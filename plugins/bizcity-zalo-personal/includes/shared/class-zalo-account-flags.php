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
	/** Hub → site relay header, present only when the account's AI is off. */
	const AI_HEADER               = 'X-BizCity-Account-AI';
	const GATE_ZALO_HUB           = 'replier_is_zalo_hub';
	const GATE_AI_DISABLED        = 'ai_disabled';
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
		if ( self::PROVIDER_ZALO_HUB === $flags['provider'] ) {
			return self::GATE_ZALO_HUB;
		}
		return $flags['ai_enabled'] ? '' : self::GATE_AI_DISABLED;
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
			uasort( $all, static function ( $a, $b ) { return (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ); } );
			$all = array_slice( $all, 0, self::MAX_ACCOUNTS, true );
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
		$provider = self::PROVIDER_ZALO_HUB === (string) ( $body['provider'] ?? '' ) ? self::PROVIDER_ZALO_HUB : self::PROVIDER_ZCA;
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
				$fields['provider'] = $account['provider'];
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
			$fields = array( 'ai_enabled' => false );
			if ( isset( $item['provider'] ) ) {
				$fields['provider'] = $item['provider'];
			}
			self::record( (string) $id, $fields, 'capability' );
		}
		foreach ( array_keys( self::all() ) as $id ) {
			if ( ! isset( $off[ (string) $id ] ) ) {
				self::record( (string) $id, array( 'ai_enabled' => true ), 'capability' );
			}
		}
	}

	/* ---------------- provider choice for new numbers (4a-2, T-13) ---------------- */

	/**
	 * [2026-09-26 Claude Opus 5.5] PHASE-0.80 owner decision — TWO connections only: zalo-hub is the DEFAULT for new numbers, zca-bridge is the legacy
	 * alternative. An unset option means zalo_hub; a Hub that refuses zalo-hub for this key still falls back to zca once (create_account), so the
	 * default never blocks creating a number. Existing numbers keep the provider recorded at creation.
	 */
	public static function default_provider(): string {
		return self::normalize_provider( get_option( self::DEFAULT_PROVIDER_OPTION, self::PROVIDER_ZALO_HUB ) );
	}

	/** Explicit request value wins; empty → the site default. Never anything but zca|zalo_hub. */
	public static function requested_provider( $raw ): string {
		$raw = is_string( $raw ) ? sanitize_key( $raw ) : '';
		return '' === $raw ? self::default_provider() : self::normalize_provider( $raw );
	}

	public static function normalize_provider( $value ): string {
		return self::PROVIDER_ZALO_HUB === ( is_string( $value ) ? strtolower( trim( $value ) ) : '' ) ? self::PROVIDER_ZALO_HUB : self::PROVIDER_ZCA;
	}
}
