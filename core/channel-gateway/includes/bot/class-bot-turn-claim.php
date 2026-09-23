<?php
/**
 * Bot Studio — turn claim, nhịp 1 (PHASE-0.60A W3).
 *
 * Hooks `bizcity_channel_normalized` at PRIORITY 0 — before the layer that
 * actually decides, `BizCity_Automation_Trigger_Matcher` at priority 30
 * (class-automation-trigger-matcher.php:69; the Automation_Listener at 1 only
 * logs). Cheap: no DB writes, no model call. Decides "does the bot answer this
 * turn?" and, if yes, turns off the built-in Default_Reply safety net for this
 * request only (R-CH-UNI §1.2 permits this explicitly — see doc §0.3).
 *
 * Yielding to workflows (0.60D S3.3): when the matcher enqueues a workflow run
 * in the same request (`bizcity_automation_run_enqueued`), the claim is marked
 * `workflow_matched` and the runner drops the turn — the operator's explicit
 * workflow wins, the bot only replaces the default-reply net.
 *
 * Claim context is handed to BizCity_Bot_Turn_Runner via a static property:
 * both hooks fire inside the SAME PHP request (one inbound webhook = one
 * synchronous pass through the whole chain — confirmed by tracing
 * class-universal-channel-listener.php → automation → CRM ingestor).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W3)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Turn_Claim {

	const PLATFORM = 'ZALO_PERSONAL';
	const CODE     = 'zalo_personal';
	/** Priority of the real decision layer we must run before (doc §1.1a). */
	const TRIGGER_MATCHER_PRIORITY = 30;
	const ACTIVE_TTL = 900;

	/** @var array|null Claim context for the message currently in flight this request. */
	private static $claim = null;

	public static function init(): void {
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_normalized' ), 0, 2 );
		// [2026-09-23 03:45 PM Claude Fable 5.1] PHASE-0.60D S3.3 — a matched workflow (enqueued in this request) makes the bot yield.
		add_action( 'bizcity_automation_run_enqueued', array( __CLASS__, 'on_workflow_enqueued' ), 10, 3 );
	}

	/**
	 * @param array  $envelope    Normalized envelope, class-universal-channel-listener.php:422-468.
	 * @param string $trigger_key
	 */
	public static function on_normalized( array $envelope, string $trigger_key ): void {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) || ! class_exists( 'BizCity_Bot_Config_Repo' ) ) {
			return;
		}
		if ( strtoupper( (string) ( $envelope['platform'] ?? '' ) ) !== self::PLATFORM ) {
			return;
		}
		// [2026-09-23 03:45 PM Claude Fable 5.1] PHASE-0.60A B4.1a — symmetric bail: Zone 1 Personal only (Guru_Bridge bails the other way).
		$raw_code = isset( $envelope['raw']['code'] ) ? sanitize_key( (string) $envelope['raw']['code'] ) : '';
		if ( $raw_code !== '' && $raw_code !== self::CODE ) {
			return;
		}
		$account_id = (string) ( $envelope['account_id'] ?? '' );
		$chat_id    = (string) ( $envelope['chat_id'] ?? '' );
		$contact_id = (int) ( $envelope['contact_id'] ?? 0 );
		if ( $account_id === '' || $chat_id === '' || $contact_id <= 0 ) {
			return;
		}

		$binding = BizCity_Channel_Binding::resolve( self::PLATFORM, $account_id );
		if ( ! $binding ) {
			return;
		}
		$character_id = (int) ( $binding['character_id'] ?? 0 );
		$mode         = (string) ( $binding['mode'] ?? '' );
		if ( $character_id <= 0 || ! in_array( $mode, array( 'auto', 'hybrid' ), true ) ) {
			return; // reuses existing binding fields — no separate "bot enabled" flag (doc §3.2).
		}

		$policy = self::decode_office_hours( $binding['office_hours_json'] ?? '' );
		if ( BizCity_Bot_Office_Hours::is_staff_on_duty( $policy ) ) {
			return; // staff on duty → bot silent (E10 polarity).
		}
		if ( ! empty( $policy['pause_on_manual_reply'] ) && self::is_paused( $contact_id ) ) {
			return; // a human just replied — leave room, per the doc's pause-window mitigation.
		}

		$chat_kind = (string) ( $envelope['chat_kind'] ?? 'user' );
		if ( 'group' === $chat_kind && ! empty( $policy['require_mention_in_group'] )
			&& empty( $envelope['mention_detected'] ) ) {
			return; // group chat requires @mention unless explicitly turned off.
		}

		$tuning = BizCity_Bot_Config_Repo::get_tuning();
		if ( self::today_count( $contact_id ) >= (int) $tuning['daily_message_cap'] ) {
			return; // daily cap reached — the account-ban mitigation the doc's risk table flags.
		}

		$bot_settings = BizCity_Bot_Config_Repo::get( $character_id );

		self::$claim = array(
			'character_id'     => $character_id,
			'binding_id'       => (int) ( $binding['id'] ?? 0 ),
			'account_id'       => $account_id,
			'chat_id'          => $chat_id,
			'chat_kind'        => $chat_kind,
			'contact_id'       => $contact_id,
			'mode'             => $mode, // auto = send · hybrid = draft only (doc B-04)
			'text'             => (string) ( $envelope['message_text_clean'] ?? $envelope['message'] ?? '' ),
			'external_message_id' => (string) ( $envelope['message_id'] ?? '' ),
			'history_limit'    => (int) $bot_settings['history_limit'],
			'bypass_notebook'  => ! empty( $bot_settings['bypass_notebook'] ),
			'context_source'   => (string) $bot_settings['context_source'],
			'character_off'    => (array) $bot_settings['disabled_tools'],
			'binding_off'      => BizCity_Bot_Config_Repo::sanitize_tool_list( $policy['disabled_tools'] ?? array() ),
			'workflow_matched' => false,
			'claimed_at'       => time(),
		);

		// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3 (B4.2) — the single most important line
		// in this feature: turn off the built-in default-reply net for THIS request only, since
		// the bot is taking the turn instead. R-CH-UNI §1.2 explicitly permits this.
		add_filter( 'bizcity_automation_default_reply_enabled', '__return_false' );
	}

	/** A workflow run was enqueued for the message we claimed → the workflow wins (0.60D §4.2). */
	public static function on_workflow_enqueued( $run_id, $workflow_id = 0, $payload = array() ): void {
		if ( null === self::$claim ) {
			return;
		}
		self::$claim['workflow_matched']    = true;
		self::$claim['workflow_id']         = (int) $workflow_id;
	}

	/** Consumed once by BizCity_Bot_Turn_Runner; null if no claim was recorded this request. */
	public static function consume_claim(): ?array {
		$claim       = self::$claim;
		self::$claim = null;
		return $claim;
	}

	/** Read without consuming (tests / diagnostics). */
	public static function peek_claim(): ?array {
		return self::$claim;
	}

	/* ── pause / cap / active registry — keys are blog-scoped (B10.3) ── */

	public static function is_paused( int $contact_id ): bool {
		return (bool) get_transient( self::pause_key( $contact_id ) );
	}

	public static function set_paused( int $contact_id, int $minutes ): void {
		set_transient( self::pause_key( $contact_id ), 1, max( 60, $minutes * 60 ) );
	}

	public static function today_count( int $contact_id ): int {
		$val = get_transient( self::cap_key( $contact_id ) );
		return $val ? (int) $val : 0;
	}

	public static function increment_today_count( int $contact_id ): void {
		$key = self::cap_key( $contact_id );
		$val = self::today_count( $contact_id ) + 1;
		// Expire at local midnight so the cap resets once per calendar day.
		$seconds_to_midnight = 86400 - ( (int) current_time( 'timestamp' ) % 86400 );
		set_transient( $key, $val, max( 60, $seconds_to_midnight ) );
	}

	/** Contacts with a pending/running turn — for GET /bot/queue/status (B-08). */
	public static function active_contacts(): array {
		$list = get_transient( self::active_key() );
		return is_array( $list ) ? $list : array();
	}

	public static function mark_active( int $contact_id, string $state, array $extra = array() ): void {
		$list = self::active_contacts();
		$list[ (string) $contact_id ] = array_merge( array( 'state' => $state, 'at' => time() ), $extra );
		// Drop stale entries so the list cannot grow without bound.
		foreach ( $list as $k => $row ) {
			if ( ( time() - (int) ( $row['at'] ?? 0 ) ) > self::ACTIVE_TTL ) {
				unset( $list[ $k ] );
			}
		}
		set_transient( self::active_key(), $list, self::ACTIVE_TTL );
	}

	public static function clear_active( int $contact_id ): void {
		$list = self::active_contacts();
		unset( $list[ (string) $contact_id ] );
		set_transient( self::active_key(), $list, self::ACTIVE_TTL );
	}

	private static function blog(): string {
		return (string) ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
	}

	private static function pause_key( int $contact_id ): string {
		return 'bzbot_pause_' . self::blog() . '_' . $contact_id;
	}

	private static function cap_key( int $contact_id ): string {
		return 'bzbot_cap_' . self::blog() . '_' . $contact_id . '_' . ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
	}

	private static function active_key(): string {
		return 'bzbot_active_' . self::blog();
	}

	public static function decode_office_hours( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}
}
