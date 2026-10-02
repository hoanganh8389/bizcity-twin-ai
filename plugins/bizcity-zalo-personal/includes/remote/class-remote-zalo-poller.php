<?php
/** Remote Zalo Hub poller — B7. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Poller', false ) ) { return; }

// [2026-09-29 12:45 PM GitHub Copilot] PHASE-0.82-B7 — poll at-least-once events with lock, profile gate, normalizer and cursor advancement.
final class BizCity_Remote_Zalo_Poller {
	public static $client = null;
	public static $clock = null;
	public static $enabled = null;
	public static $normalizer = null;
	public static $profile = null;

	public static function tick( array $opts = array() ): array {
		$summary = array( 'pages' => 0, 'events' => 0, 'emitted' => 0, 'duplicates' => 0, 'dropped_unlinked' => 0, 'ignored' => 0, 'state' => 'ok' );
		if ( ! self::is_enabled() ) { $summary['state'] = 'disabled'; return $summary; }
		if ( ! class_exists( 'BizCity_Remote_Zalo_Cursor_Store' ) || ( ! is_object( self::$client ) && ! class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) ) { $summary['state'] = 'unavailable'; return $summary; }
		if ( ! BizCity_Remote_Zalo_Cursor_Store::acquire_lock( 90 ) ) { $summary['state'] = 'locked'; return $summary; }
		$max_pages = max( 1, min( 5, (int) ( $opts['max_pages'] ?? 5 ) ) );
		$deadline = self::now() + max( 1, (int) ( $opts['budget_seconds'] ?? 20 ) );
		$after = (string) BizCity_Remote_Zalo_Cursor_Store::get()['after'];
		$client = is_object( self::$client ) ? self::$client : new BizCity_Remote_Zalo_Hub_Client();
		try {
			for ( $page = 0; $page < $max_pages && self::now() < $deadline; $page++ ) {
				$response = $client->poll_events( $after, 100, (array) ( $opts['filters'] ?? array() ) );
				if ( empty( $response['ok'] ) ) { $summary['state'] = self::error_state( $response['error']['code'] ?? '' ); break; }
				$summary['pages']++;
				$data = is_array( $response['data'] ?? null ) ? $response['data'] : array();
				$events = is_array( $data['events'] ?? null ) ? $data['events'] : array();
				foreach ( $events as $event ) {
					if ( self::now() >= $deadline ) { break 2; }
					$summary['events']++;
					$event_id = (string) ( $event['event_id'] ?? '' );
					if ( '' !== $event_id && BizCity_Remote_Zalo_Cursor_Store::seen( $event_id ) ) { $summary['duplicates']++; continue; }
					$profile = is_callable( self::$profile ) ? call_user_func( self::$profile, 'event', $event ) : ( class_exists( 'BizCity_Remote_Zalo_Profile' ) ? BizCity_Remote_Zalo_Profile::check( 'event', $event ) : array( 'ok' => true ) );
					if ( empty( $profile['ok'] ) ) { BizCity_Remote_Zalo_Cursor_Store::set_state( 'drift', 'remote_profile_drift' ); $summary['state'] = 'drift'; break 2; }
					$normal = is_callable( self::$normalizer ) ? call_user_func( self::$normalizer, $event ) : ( class_exists( 'BizCity_Remote_Zalo_Normalizer' ) ? BizCity_Remote_Zalo_Normalizer::handle( $event ) : array( 'result' => 'ignored_unknown_type', 'crm_message_id' => 0 ) );
					$result = (string) ( $normal['result'] ?? 'ignored_unknown_type' );
					if ( 'retryable_failure' === $result ) { $summary['state'] = 'retryable_failure'; break 2; }
					if ( 'emitted' === $result || 'outbound_recorded' === $result ) { $summary['emitted']++; }
					elseif ( 'dropped_unlinked' === $result ) { $summary['dropped_unlinked']++; }
					elseif ( in_array( $result, array( 'ignored_unknown_type', 'linked_own_echo', 'status_updated', 'bot_state_cached', 'ignored_own_echo', 'agent_drift_recorded' ), true ) ) { $summary['ignored']++; }
					BizCity_Remote_Zalo_Cursor_Store::mark_handled( $event_id, $event['cursor'] ?? $after );
				}
				$next = array_key_exists( 'nextAfter', $data ) ? $data['nextAfter'] : null;
				if ( null === $next || $next === $after || ! $events ) { break; }
				$after = (string) $next;
			}
			if ( in_array( $summary['state'], array( 'ok', 'retryable_failure' ), true ) && 'retryable_failure' !== $summary['state'] ) { BizCity_Remote_Zalo_Cursor_Store::mark_ok(); }
		} finally { BizCity_Remote_Zalo_Cursor_Store::release_lock(); }
		return $summary;
	}

	/* ---------------- XS2 (E12) — WP-Cron schedule ---------------- */
	// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS2 — without this the CRM Inbox only received remote messages when
	// someone ran `wp bizcity zalo-remote tick`. WP-Cron is the DEGRADED path (traffic-driven, D82-16); the
	// recommended one stays a real system cron calling the WP-CLI tick. Both share the cursor lock, so they never overlap.
	const CRON_HOOK = 'bizcity_rzh_poll_tick';
	const SCHEDULE  = 'bizcity_rzh_minute';

	/** @var callable|null test seam: () => bool (is the remote connection configured?) */
	public static $configured = null;

	/** Wire the hook + interval; schedule only when a connection is configured. Called by the remote loader. */
	public static function boot(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		}
		if ( function_exists( 'add_action' ) ) {
			add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ) );
		}
		self::ensure_scheduled();
	}

	public static function add_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array( 'interval' => 60, 'display' => 'BizCity Remote Zalo poll (60s)' );
		return $schedules;
	}

	public static function cron_tick(): array { return self::tick( array( 'max_pages' => 2 ) ); }

	/** Idempotent: one event at most, and none while unconfigured. */
	public static function ensure_scheduled(): bool {
		if ( ! self::is_configured() ) { self::unschedule(); return false; }
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) { return false; }
		if ( wp_next_scheduled( self::CRON_HOOK ) ) { return true; }
		return false !== wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
	}

	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) { wp_clear_scheduled_hook( self::CRON_HOOK ); }
	}

	private static function is_configured(): bool {
		if ( is_callable( self::$configured ) ) { return (bool) call_user_func( self::$configured ); }
		if ( ! class_exists( 'BizCity_Remote_Zalo_Credentials' ) ) { return false; }
		$conn = BizCity_Remote_Zalo_Credentials::public_view();
		return ! empty( $conn['key_set'] ) && '' !== (string) ( $conn['base_url_host'] ?? '' );
	}

	public static function reset_seams(): void { self::$client = self::$clock = self::$normalizer = self::$profile = self::$configured = null; self::$enabled = null; }
	private static function is_enabled(): bool { return null !== self::$enabled ? (bool) self::$enabled : ( defined( 'BIZCITY_ZALO_REMOTE_HUB_ENABLED' ) && BIZCITY_ZALO_REMOTE_HUB_ENABLED ); }
	private static function now(): int { return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time(); }
	private static function error_state( string $code ): string { if ( 'remote_auth_failed' === $code ) { return 'auth_failed'; } if ( 'remote_cursor_expired' === $code ) { return 'expired_gap'; } if ( 'remote_scope_missing' === $code ) { return 'paused'; } return 'remote_error'; }
}
