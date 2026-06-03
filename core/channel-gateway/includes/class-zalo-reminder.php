<?php
/**
 * Channel Gateway — Zalo Reminder Handler
 *
 * Bridge between core/scheduler and Zalo OA send API.
 *
 * Listens to `bizcity_scheduler_reminder_fire` (fired by scheduler cron when
 * `start_at <= now + reminder_min*60`). When the event's `event_type` is
 * `reminder_zalo`, it resolves the target OA + user, sends the message via
 * `bizcity_channel_send()`, then writes the result back to event metadata.
 *
 * Contract (event_type='reminder_zalo' metadata fields — see core/diagnostics/changelog/core.scheduler.json v3.2.1):
 *   zalo_bot_id          Required. Row ID in bizcity_zalo_bots table (BIGINT).
 *   zalo_user_id         Required. Zalo OA follower user ID (VARCHAR 64).
 *   zalo_text            Required. Message text to send (TEXT).
 *   zalo_reminder_status pending|sending|sent|failed (default: pending).
 *   zalo_error           Filled on failure.
 *   zalo_message_id      Filled after successful send.
 *
 * Idempotency: skip if zalo_reminder_status not in [pending, failed],
 *              or if event status !== 'active'.
 *
 * Send path: bizcity_channel_send("zalobot_{oa_id}_{zalo_user_id}", text)
 *   → BizCity_Gateway_Sender → BizCity_Zalo_Bot_OA_Integration::send()
 *
 * R-DCL compliant: no schema change. Contract bump in core.scheduler.json v3.2.1.
 * R-CH compliant: bridges via filter/action, no fork of core/scheduler.
 * R-CRON-META compliant: emits note_event() on attempt/ok/failed.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Reminder {

	const HOOK_PRIORITY = 30;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function init(): void {
		add_action(
			'bizcity_scheduler_reminder_fire',
			array( self::instance(), 'on_reminder_fire' ),
			self::HOOK_PRIORITY,
			1
		);
	}

	/**
	 * Reminder callback. Returns silently for non-reminder_zalo events.
	 *
	 * @param array|object $event Event row from cron::claim_due_reminders.
	 */
	public function on_reminder_fire( $event ): void {
		$event = is_array( $event ) ? $event : (array) $event;
		if ( empty( $event['id'] ) ) {
			return;
		}
		if ( ( $event['event_type'] ?? '' ) !== 'reminder_zalo' ) {
			return;
		}
		if ( ( $event['status'] ?? '' ) !== 'active' ) {
			return;
		}

		$meta = $this->decode_metadata( $event['metadata'] ?? '' );

		// Idempotency guard.
		$reminder_status = (string) ( $meta['zalo_reminder_status'] ?? 'pending' );
		if ( ! in_array( $reminder_status, array( 'pending', 'failed' ), true ) ) {
			return;
		}

		$event_id    = (int) $event['id'];
		$zalo_bot_id = (int) ( $meta['zalo_bot_id'] ?? 0 );
		$zalo_user_id = (string) ( $meta['zalo_user_id'] ?? '' );
		$text        = (string) ( $meta['zalo_text'] ?? '' );

		// R-CRON-META: attempt evidence.
		$this->cron_note_event( 'zalo_reminder_send_attempt', array(
			'event_id'       => $event_id,
			'zalo_bot_id'    => $zalo_bot_id,
			'zalo_user_id'   => $zalo_user_id,
			'text_len'       => strlen( $text ),
			'prev_status'    => $reminder_status,
			'is_retry'       => $reminder_status === 'failed',
		) );

		// Validate required fields.
		if ( $zalo_bot_id <= 0 || $zalo_user_id === '' || $text === '' ) {
			$this->mark_failed( $event_id, $meta, 'Missing zalo_bot_id, zalo_user_id, or zalo_text in metadata.' );
			$this->cron_note_event( 'zalo_reminder_send_failed', array(
				'event_id' => $event_id,
				'reason'   => 'invalid_metadata',
				'error'    => 'Missing required metadata fields.',
			) );
			$this->cron_bump_counter( 'zalo_failed' );
			return;
		}

		// Resolve OA ID from bizcity_zalo_bots table.
		$oa_id = $this->resolve_oa_id( $zalo_bot_id );
		if ( $oa_id === '' ) {
			$err = "Cannot resolve oa_id for zalo_bot_id={$zalo_bot_id}. Bot not found or missing oa_id.";
			$this->mark_failed( $event_id, $meta, $err );
			$this->cron_note_event( 'zalo_reminder_send_failed', array(
				'event_id'    => $event_id,
				'zalo_bot_id' => $zalo_bot_id,
				'reason'      => 'invalid_param',
				'error'       => $err,
			) );
			$this->cron_bump_counter( 'zalo_failed' );
			return;
		}

		// Mark sending (claim — prevents duplicate dispatch).
		$meta['zalo_reminder_status'] = 'sending';
		unset( $meta['zalo_error'] );
		$this->write_metadata( $event_id, $meta );

		// Build chat_id in canonical format and dispatch.
		$chat_id = 'zalobot_' . $oa_id . '_' . $zalo_user_id;
		$result  = bizcity_channel_send( $chat_id, $text );

		// bizcity_channel_send returns array{sent, error, platform, mid} or WP_Error.
		if ( is_wp_error( $result ) ) {
			$err_code = $result->get_error_code();
			$err_msg  = $result->get_error_message();
			$this->mark_failed( $event_id, $meta, $err_msg );
			$this->cron_note_event( 'zalo_reminder_send_failed', array(
				'event_id'    => $event_id,
				'zalo_bot_id' => $zalo_bot_id,
				'zalo_user_id'=> $zalo_user_id,
				'oa_id'       => $oa_id,
				'reason'      => $this->classify_send_error( $err_code ),
				'error'       => $err_msg,
			) );
			$this->cron_bump_counter( 'zalo_failed' );
			return;
		}

		$sent = (bool) ( $result['sent'] ?? false );
		if ( ! $sent ) {
			$err_msg = (string) ( $result['error'] ?? 'Unknown send error' );
			$this->mark_failed( $event_id, $meta, $err_msg );
			$this->cron_note_event( 'zalo_reminder_send_failed', array(
				'event_id'    => $event_id,
				'zalo_bot_id' => $zalo_bot_id,
				'zalo_user_id'=> $zalo_user_id,
				'oa_id'       => $oa_id,
				'reason'      => 'http_error',
				'error'       => $err_msg,
			) );
			$this->cron_bump_counter( 'zalo_failed' );
			return;
		}

		// Success.
		$meta['zalo_reminder_status'] = 'sent';
		$meta['zalo_message_id']      = (string) ( $result['mid'] ?? '' );
		unset( $meta['zalo_error'] );
		$this->write_metadata( $event_id, $meta, 'done' );

		$this->cron_note_event( 'zalo_reminder_send_ok', array(
			'event_id'    => $event_id,
			'zalo_bot_id' => $zalo_bot_id,
			'zalo_user_id'=> $zalo_user_id,
			'oa_id'       => $oa_id,
			'mid'         => $meta['zalo_message_id'],
		) );
		$this->cron_bump_counter( 'zalo_sent' );

		do_action( 'bizcity_zalo_reminder_sent', $event_id, $zalo_user_id, $meta['zalo_message_id'] );
	}

	/* ═══════════════════════════════════════════════════════════
	 *  Helpers
	 * ═══════════════════════════════════════════════════════════ */

	/**
	 * Look up oa_id from bizcity_zalo_bots row.
	 *
	 * The legacy bizcity-zalo-bot plugin owns this table. We read it
	 * read-only — no write or schema dependency.
	 */
	protected function resolve_oa_id( int $bot_id ): string {
		if ( $bot_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$oa_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT oa_id FROM {$table} WHERE id = %d LIMIT 1",
			$bot_id
		) );
		return $oa_id !== null ? (string) $oa_id : '';
	}

	/**
	 * Write metadata (+ optional status) back to scheduler event.
	 */
	protected function write_metadata( int $event_id, array $meta, string $new_status = '' ): void {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$mgr     = BizCity_Scheduler_Manager::instance();
		$payload = array( 'metadata' => wp_json_encode( $meta ) );
		if ( $new_status !== '' ) {
			$payload['status'] = $new_status;
		}
		// null user_id = admin bypass (cron context, no current user).
		$mgr->update_event( $event_id, $payload, null );
	}

	/**
	 * Mark event failed: keep event.status='active' so admin can retry by
	 * resetting metadata.zalo_reminder_status back to 'pending'.
	 */
	protected function mark_failed( int $event_id, array $meta, string $error ): void {
		$meta['zalo_reminder_status'] = 'failed';
		$meta['zalo_error']           = $error;
		$this->write_metadata( $event_id, $meta );
	}

	/**
	 * Decode metadata column (JSON string or already-decoded array).
	 *
	 * @param mixed $raw
	 */
	protected function decode_metadata( $raw ): array {
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

	/**
	 * Classify bizcity_channel_send() error code into stable reason buckets.
	 */
	protected function classify_send_error( string $err_code ): string {
		$map = array(
			'zalobot_no_token'     => 'token_invalid',
			'zalobot_no_recipient' => 'invalid_param',
			'http_request_failed'  => 'timeout',
		);
		return $map[ $err_code ] ?? 'http_error';
	}

	/**
	 * R-CRON-META helpers (silent no-op outside cron context or if class unavailable).
	 */
	protected function cron_note_event( string $name, array $data ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $name, $data );
		}
	}

	protected function cron_bump_counter( string $key, int $by = 1 ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		$mgr    = BizCity_Cron_Manager::instance();
		$run_id = $mgr->current_run_id();
		if ( ! $run_id ) {
			return;
		}
		global $wpdb;
		$t   = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RUNS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$t} WHERE id=%d", $run_id ) );
		$cur = $raw !== '' ? json_decode( $raw, true ) : array();
		if ( ! is_array( $cur ) ) {
			$cur = array();
		}
		$prev = (int) ( $cur['counters'][ $key ] ?? 0 );
		$mgr->note( array( 'counters' => array( $key => $prev + $by ) ) );
	}
}
