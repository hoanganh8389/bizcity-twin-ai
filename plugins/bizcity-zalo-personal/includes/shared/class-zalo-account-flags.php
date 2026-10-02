<?php
/**
 * BizCity Zalo Personal — per-account provider + AI flags (PHASE-0.80 Lane C 4a-2/4a-3/4a-6, D-L43).
 *
 * Two facts decide whether the PHP Bot Studio may answer a Zalo Cá nhân number:
 *
 *  - provider (`zca` | `zalo_hub`): a `zalo_hub` number is answered by the Hub-side assistant,
 *    so Bot Studio stays silent for it (D-H7 "one replier", 01 §5.6 `replier_is_zalo_hub`);
 *  - ai_enabled: the Hub turns AI off for accounts beyond the plan's account pool
 *    (R-B2B2C-ZH §7, D-L36). D-L43 (2026-09-26): the WP client MUST honour it for EVERY
 *    provider, so a `zca` number over the limit also gets no PHP auto-reply. Customer
 *    messages still reach the CRM and staff can still answer by hand.
 *
 * The Hub is canonical; this class keeps a per-blog projection (option, autoload off) keyed by
 * bridge account id, refreshed without extra network calls from:
 *   - every relayed inbound event (Hub adds `provider:"zalo_hub"` for cell events and the header
 *     `X-BizCity-Account-AI: off` when the account's AI is off) — fresh at the moment of the turn;
 *   - account create / list responses and `capability().over_limit_accounts[]`;
 *   - the owner's own `ai-enabled` switch.
 * An option map (not a new column on `bizcity_zalo_accounts`) keeps this off the sharded tenant
 * DDL path; see PHASE-0.80 50 T-21.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Account_Flags', false ) ) {
	return;
}

final class BizCity_Zalo_Account_Flags {

	const OPTION                  = 'bizcity_zalo_account_flags';
	const DEFAULT_PROVIDER_OPTION = 'bizcity_zalo_default_provider';
	const PROVIDER_ZCA            = 'zca';
	const PROVIDER_ZALO_HUB       = 'zalo_hub';
	/**
	 * PHASE-0.82 branch 3 — third-party-operated "Remote Zalo Hub API" (04-IMPLEMENTATION-FRAMEWORK-ROADMAP.md
	 * §0.1/§0.3: separate provider value, not folded under `zalo_hub`, so the commercial boundary between
	 * BizCity-exclusive B2B2C and a customer-brought third-party transport stays visible in the data).
	 * Tier 1 prep only (05-TIER-1-ENUM-SIZING.md): no branch-3 row is ever written by this class yet —
	 * `observe_*()` below never produce this value, so it can only appear once the branch-3 adapter starts
	 * calling `record()` directly in a later tier.
	 */
	const PROVIDER_REMOTE_ZALO_HUB = 'remote_zalo_hub';
	/** 05-TIER-1-ENUM-SIZING.md F1: any value outside the three above. Never silently treated as `zca`. */
	const PROVIDER_UNKNOWN         = 'unknown';
	/** Hub → site relay header, present only when the account's AI is off. */
	const AI_HEADER               = 'X-BizCity-Account-AI';
	const GATE_ZALO_HUB           = 'replier_is_zalo_hub';
	const GATE_AI_DISABLED        = 'ai_disabled';
	/** 05-TIER-1-ENUM-SIZING.md F5/F6: branch-3's own bot until the §3.4 handshake confirms it is off. */
	const GATE_REMOTE_BOT_UNCONFIRMED = 'remote_bot_unconfirmed';
	/** 05-TIER-1-ENUM-SIZING.md F1: fail-closed gate for a provider value this class does not recognize. */
	const GATE_UNKNOWN_PROVIDER   = 'unknown_provider';
	const MAX_ACCOUNTS            = 500;

	/** @var array|null request memo */
	private static $memo = null;

	/* ---------------- read ---------------- */

	public static function all(): array {
		if ( null === self::$memo ) {
			$raw = get_option( self::OPTION, array() );
			self::$memo = is_array( $raw ) ? $raw : array();
		}
		return self::$memo;
	}

	/** @return array{provider:string,ai_enabled:bool,updated_at:int,source:string} */
	public static function get( string $bridge_id ): array {
		$all = self::all();
		$row = isset( $all[ $bridge_id ] ) && is_array( $all[ $bridge_id ] ) ? $all[ $bridge_id ] : array();
		return array(
			'provider'   => self::normalize_provider( $row['provider'] ?? '' ),
			'ai_enabled' => ! array_key_exists( 'ai_enabled', $row ) || (bool) $row['ai_enabled'],
			'updated_at' => (int) ( $row['updated_at'] ?? 0 ),
			'source'     => (string) ( $row['source'] ?? '' ),
		);
	}

	public static function provider( string $bridge_id ): string { return self::get( $bridge_id )['provider']; }
	public static function ai_enabled( string $bridge_id ): bool { return self::get( $bridge_id )['ai_enabled']; }

	/**
	 * Why Bot Studio must NOT answer this account ('' = it may). Filter target of
	 * `bizcity_bot_studio_account_gate` (core asks, this plugin answers).
	 */
	public static function bot_gate( string $bridge_id ): string {
		if ( '' === $bridge_id ) {
			return '';
		}
		$flags = self::get( $bridge_id );
		switch ( $flags['provider'] ) {
			case self::PROVIDER_ZALO_HUB:
				return self::GATE_ZALO_HUB;
			case self::PROVIDER_REMOTE_ZALO_HUB:
				// 05-TIER-1-ENUM-SIZING.md F5/F6, Tier 1: no §3.4 handshake exists yet to confirm the
				// remote nick's own bot is off, so fail closed unconditionally. Tier 3 replaces this
				// with a verified `botEnabled` check before Bot Studio may answer a branch-3 account.
				return self::GATE_REMOTE_BOT_UNCONFIRMED;
			case self::PROVIDER_ZCA:
				return $flags['ai_enabled'] ? '' : self::GATE_AI_DISABLED;
			default:
				// F1: an unrecognized provider value must never silently permit an auto-reply.
				return self::GATE_UNKNOWN_PROVIDER;
		}
	}

	/** Filter callback: keep an earlier gate, otherwise answer for Zalo Cá nhân accounts. */
	public static function filter_bot_gate( $gate, $platform = '', $account_id = '' ): string {
		if ( is_string( $gate ) && '' !== $gate ) {
			return $gate;
		}
		if ( 'ZALO_PERSONAL' !== strtoupper( (string) $platform ) ) {
			return '';
		}
		return self::bot_gate( (string) $account_id );
	}

	/* ---------------- write ---------------- */

	/** Merge flags for one account; writes the option only when provider/ai_enabled really change. */
	public static function record( string $bridge_id, array $fields, string $source ): bool {
		$bridge_id = trim( $bridge_id );
		if ( '' === $bridge_id ) {
			return false;
		}
		$all = self::all();
		$old = isset( $all[ $bridge_id ] ) && is_array( $all[ $bridge_id ] ) ? $all[ $bridge_id ] : array();
		$new = $old;
		if ( array_key_exists( 'provider', $fields ) ) {
			$new['provider'] = self::normalize_provider( $fields['provider'] );
		}
		if ( array_key_exists( 'ai_enabled', $fields ) ) {
			$new['ai_enabled'] = (bool) $fields['ai_enabled'];
		}
		$new['provider']   = self::normalize_provider( $new['provider'] ?? '' );
		$new['ai_enabled'] = ! array_key_exists( 'ai_enabled', $new ) || (bool) $new['ai_enabled'];
		$changed = empty( $old ) || self::normalize_provider( $old['provider'] ?? '' ) !== $new['provider'] || ( ! array_key_exists( 'ai_enabled', $old ) || (bool) $old['ai_enabled'] ) !== $new['ai_enabled'];
		if ( ! $changed ) {
			return false;
		}
		$new['updated_at'] = time();
		$new['source']     = sanitize_key( $source );
		$all[ $bridge_id ] = $new;
		if ( count( $all ) > self::MAX_ACCOUNTS ) {
			// 05-TIER-1-ENUM-SIZING.md F8: a branch-3 row has no Hub re-seed path, so evicting one
			// here would silently revert it to remote_bot_unconfirmed with no recovery but a fresh
			// §3.4 handshake. Only zca/zalo_hub rows (which the Hub can always re-seed) are evictable.
			$branch3 = array();
			$rest    = array();
			foreach ( $all as $key => $row ) {
				$provider = is_array( $row ) ? ( $row['provider'] ?? '' ) : '';
				if ( self::PROVIDER_REMOTE_ZALO_HUB === $provider ) {
					$branch3[ $key ] = $row;
				} else {
					$rest[ $key ] = $row;
				}
			}
			$rest_cap = max( 0, self::MAX_ACCOUNTS - count( $branch3 ) );
			if ( count( $rest ) > $rest_cap ) {
				uasort( $rest, static function ( $a, $b ) { return (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ); } );
				$rest = array_slice( $rest, 0, $rest_cap, true );
			}
			$all = $rest + $branch3;
		}
		self::$memo = $all;
		update_option( self::OPTION, $all, false );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_zalo_account_flags_changed', $bridge_id, $new, $old );
		}
		return true;
	}

	public static function forget( string $bridge_id ): void {
		$all = self::all();
		if ( isset( $all[ $bridge_id ] ) ) {
			unset( $all[ $bridge_id ] );
			self::$memo = $all;
			update_option( self::OPTION, $all, false );
		}
	}

	/** Test seam: drop the request memo. */
	public static function reset_memo(): void { self::$memo = null; }

	/* ---------------- observers (no network) ---------------- */

	/**
	 * Every authenticated Hub relay tells the current truth for this account:
	 * cell events carry `provider:"zalo_hub"`, zca payloads carry none; the AI header is present only when AI is off.
	 */
	public static function observe_inbound( string $bridge_id, array $body, string $ai_header = '' ): void {
		$raw_provider = (string) ( $body['provider'] ?? '' );
		if ( self::PROVIDER_REMOTE_ZALO_HUB === self::normalize_provider( $raw_provider ) ) {
			// 05-TIER-1-ENUM-SIZING.md F3: branch-3 traffic arrives from our own poller, never the Hub
			// relay. A stray `remote_zalo_hub` value here is unexpected — refuse to record rather than
			// misclassify it as `zca` (the old binary ternary below would have done exactly that).
			return;
		}
		$provider = self::PROVIDER_ZALO_HUB === $raw_provider ? self::PROVIDER_ZALO_HUB : self::PROVIDER_ZCA;
		self::record( $bridge_id, array( 'provider' => $provider, 'ai_enabled' => 'off' !== strtolower( trim( $ai_header ) ) ), 'inbound' );
	}

	/** Hub account list / create response items (`id`, optional `provider`, optional `ai_enabled`). */
	public static function observe_accounts( array $accounts, string $source = 'hub_list' ): void {
		foreach ( $accounts as $account ) {
			if ( ! is_array( $account ) || ! isset( $account['id'] ) ) {
				continue;
			}
			$fields = array();
			if ( isset( $account['provider'] ) ) {
				$incoming = self::normalize_provider( $account['provider'] );
				// 05 F2/F3: the Hub has no visibility into branch-3 accounts, so it is never
				// authoritative for that value — drop the field rather than let it overwrite one.
				if ( self::PROVIDER_REMOTE_ZALO_HUB !== $incoming ) {
					$fields['provider'] = $incoming;
				}
			}
			if ( array_key_exists( 'ai_enabled', $account ) ) {
				$fields['ai_enabled'] = (bool) $account['ai_enabled'];
			}
			if ( $fields ) {
				self::record( (string) $account['id'], $fields, $source );
			}
		}
	}

	/** `capability().over_limit_accounts[]` is the complete list of AI-off accounts of the key. */
	public static function observe_capability( array $capability ): void {
		if ( ! isset( $capability['over_limit_accounts'] ) || ! is_array( $capability['over_limit_accounts'] ) ) {
			return;
		}
		$off = array();
		foreach ( $capability['over_limit_accounts'] as $item ) {
			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$off[ (string) $item['id'] ] = $item;
			}
		}
		foreach ( $off as $id => $item ) {
			// 05 F2: a routine capability refresh must never touch a branch-3 account — the Hub does
			// not track it, so anything the Hub says about this id cannot be about that account.
			if ( self::PROVIDER_REMOTE_ZALO_HUB === self::provider( (string) $id ) ) {
				continue;
			}
			$fields = array( 'ai_enabled' => false );
			if ( isset( $item['provider'] ) ) {
				$incoming = self::normalize_provider( $item['provider'] );
				if ( self::PROVIDER_REMOTE_ZALO_HUB !== $incoming ) {
					$fields['provider'] = $incoming;
				}
			}
			self::record( (string) $id, $fields, 'capability' );
		}
		foreach ( array_keys( self::all() ) as $id ) {
			if ( isset( $off[ (string) $id ] ) || self::PROVIDER_REMOTE_ZALO_HUB === self::provider( (string) $id ) ) {
				continue;
			}
			self::record( (string) $id, array( 'ai_enabled' => true ), 'capability' );
		}
	}

	/* ---------------- provider choice for new numbers (4a-2, T-13; PHASE-0.82 D82-40) ---------------- */

	/**
	 * [2026-09-29 Claude Sonnet 5] PHASE-0.82 D82-40 — owner direction 2026-09-29: `zca` is retired as a
	 * choice for NEW numbers. Existing `zca` numbers are untouched and keep running exactly as before —
	 * this list only bounds what a NEW number may be created/stored as a default with. `zalo_hub` is
	 * always offered. `remote_zalo_hub` is offered only once the branch-3 feature flag is on
	 * ([`50`](../../../../core/channel-gateway/docs/PHASE-0.82-CRM-REMOTE-ZALO-HUB/50-EXPANSION-REQUIREMENTS-2026-09-29.md)
	 * D82-41 — the account itself still needs a configured+checked remote connection, enforced by the
	 * REST layer, not here). `zca` re-enters the list only behind the documented escape hatch constant
	 * `BIZCITY_ZALO_ALLOW_ZCA_NEW` (site-level, not a UI toggle) for a site that still needs it.
	 *
	 * @return list<string>
	 */
	public static function new_number_providers(): array {
		$out = array( self::PROVIDER_ZALO_HUB );
		if ( class_exists( 'BizCity_Remote_Zalo_Feature' ) && BizCity_Remote_Zalo_Feature::enabled() ) {
			$out[] = self::PROVIDER_REMOTE_ZALO_HUB;
		}
		if ( defined( 'BIZCITY_ZALO_ALLOW_ZCA_NEW' ) && true === BIZCITY_ZALO_ALLOW_ZCA_NEW ) {
			$out[] = self::PROVIDER_ZCA;
		}
		return $out;
	}

	/**
	 * [2026-09-26 Claude Opus 5.5 / 2026-09-29 Claude Sonnet 5] PHASE-0.80 → PHASE-0.82 D82-40 — the stored
	 * site default for NEW numbers. A stored value that has fallen outside `new_number_providers()` (e.g.
	 * a site that had `zca` as its default before the 2026-09-29 retirement, and has no escape hatch set)
	 * reads back as `zalo_hub` — a READ-time fallback, never written back here, so the stored option is
	 * left untouched for the owner/UI to see and correct explicitly (D82-00R reconciliation, F7).
	 */
	public static function default_provider(): string {
		$stored = self::normalize_provider( get_option( self::DEFAULT_PROVIDER_OPTION, self::PROVIDER_ZALO_HUB ) );
		return in_array( $stored, self::new_number_providers(), true ) ? $stored : self::PROVIDER_ZALO_HUB;
	}

	/**
	 * Explicit request value wins; empty → the site default. [2026-09-29 Claude Sonnet 5] PHASE-0.82
	 * D82-40 supersedes the PHASE-0.82-X0.3 legacy-zca-fallback behaviour on THIS path only: an explicit
	 * value outside `new_number_providers()` (including a bare `zca` with no escape hatch) now returns
	 * `PROVIDER_UNKNOWN` instead of silently becoming `zca`. Callers on the number-creation path
	 * (`BizCity_Zalo_Personal_Hub_Client::create_account()`) must treat `PROVIDER_UNKNOWN` as a hard
	 * `provider_retired` error and must NOT create a number with it.
	 */
	public static function requested_provider( $raw ): string {
		$raw = is_string( $raw ) ? sanitize_key( $raw ) : '';
		if ( '' === $raw ) {
			return self::default_provider();
		}
		$normalized = self::normalize_provider( $raw );
		return in_array( $normalized, self::new_number_providers(), true ) ? $normalized : self::PROVIDER_UNKNOWN;
	}

	/**
	 * 05-TIER-1-ENUM-SIZING.md F1: explicit three-value allowlist with a fail-closed unknown case.
	 * An empty value (never recorded) keeps the pre-existing legacy default of `zca` — that case is
	 * "not yet set", not "corrupt". Anything else that isn't one of the three recognized providers
	 * returns PROVIDER_UNKNOWN and must never silently become `zca` the way the old binary
	 * normalizer did for every non-`zalo_hub` string.
	 */
	public static function normalize_provider( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( '' === $value ) {
			return self::PROVIDER_ZCA;
		}
		$known = array( self::PROVIDER_ZCA, self::PROVIDER_ZALO_HUB, self::PROVIDER_REMOTE_ZALO_HUB );
		return in_array( $value, $known, true ) ? $value : self::PROVIDER_UNKNOWN;
	}
}
