<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — Broadcast Dispatcher (Deplao parity)
 *
 * Token-bucket cron dispatcher for mass-send campaigns with multi-variant content.
 * Supports random variant selection and controlled per-minute throttle.
 *
 * Architecture:
 *   - Cron hook `bizcity_crm_broadcast_tick` fires every minute (registered via scheduler).
 *   - Each tick pulls up to BATCH_PER_MINUTE pending queue rows from `bizcity_crm_broadcast_queue`.
 *   - Sends via BizCity_Gateway_Sender, records success/fail per R-CRON-META.
 *   - Multi-variant: picks a variant randomly (or round-robin if mode = 'all').
 *
 * @package BizCity_Twin_CRM
 * @since   1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BizCity_CRM_Broadcast_Dispatcher
 */
final class BizCity_CRM_Broadcast_Dispatcher {

	/** Default sends per cron tick. */
	const BATCH_PER_MINUTE = 30;

	/** Option key for pausing the dispatcher. */
	const OPT_PAUSED = 'bizcity_crm_broadcast_paused';

	/** Cron hook. */
	const CRON_HOOK = 'bizcity_crm_broadcast_tick';

	/**
	 * Recipients table shortcut — uses existing bizcity_crm_broadcast_recipients.
	 */
	private static function recipients_tbl() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_crm_broadcast_recipients';
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — R-SHOW-TABLES safe table existence check (static + wp_cache).
	 */
	private static function table_exists_cached( $table_name ) {
		static $memo = array();
		if ( isset( $memo[ $table_name ] ) ) {
			return $memo[ $table_name ];
		}

		$cache_key = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present   = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $cache_key, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}

		$memo[ $table_name ] = (bool) $present;
		return $memo[ $table_name ];
	}

	/* ------------------------------------------------------------------
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	/**
	 * Register hooks + cron schedule.
	 */
	public static function init() {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — register cron + REST actions
		// [2026-06-08 Johnny Chu] PHASE-0.43 BUG-1 — own every_minute registration (not rely on content-ops)
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Register every_minute interval if not already present.
	 * Idempotent: skips if another module already registered it.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function add_cron_schedule( array $schedules ): array {
		// [2026-06-08 Johnny Chu] PHASE-0.43 BUG-1 — self-contained interval, no content-ops dep
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => 60,
				'display'  => 'Every Minute (Broadcast Dispatcher)',
			);
		}
		return $schedules;
	}

	/**
	 * Ensure cron is scheduled.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'every_minute', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------
	 * Cron tick — token-bucket dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Dispatch up to BATCH_PER_MINUTE queued items.
	 * Follows R-CRON-META: note() counters + note_event() on failure.
	 */
	public static function tick() {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — tick entry
		if ( get_option( self::OPT_PAUSED ) ) {
			return;
		}
		global $wpdb;
		$queue_tbl = self::recipients_tbl();
		if ( ! self::table_exists_cached( $queue_tbl ) ) {
			return; // Table not yet provisioned — fail-open.
		}

		$batch   = (int) apply_filters( 'bizcity_crm_broadcast_batch', self::BATCH_PER_MINUTE );
		$batch   = max( 1, min( 200, $batch ) );
		// [2026-06-07 Johnny Chu] PHASE-0.43 M1.2 — filter by scheduled_send_at to honour per-recipient delay
		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$queue_tbl}` WHERE status = 'queued' AND (scheduled_send_at IS NULL OR scheduled_send_at <= %s) ORDER BY scheduled_send_at ASC, id ASC LIMIT %d",
			current_time( 'mysql', true ),
			$batch
		), ARRAY_A );

		if ( empty( $pending ) ) {
			// [2026-07-10 Johnny Chu] PHASE-0.47 — reconcile stale sending campaigns even when no pending rows.
			self::reconcile_sending_broadcasts();
			return;
		}

		$counters = array( 'queued' => count( $pending ), 'sent' => 0, 'failed' => 0 );
		$cron_mgr = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		$touched  = array();

		foreach ( $pending as $item ) {
			$broadcast_id = isset( $item['broadcast_id'] ) ? (int) $item['broadcast_id'] : 0;
			if ( $broadcast_id > 0 ) {
				$touched[ $broadcast_id ] = true;
			}

			$sent = self::send_one( $item );
			if ( $sent === true ) {
				$counters['sent']++;
				$wpdb->update( $queue_tbl, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ), array( 'id' => (int) $item['id'] ) );
			} else {
				$counters['failed']++;
				$reason  = is_string( $sent ) ? $sent : 'unknown_error';
				$wpdb->update( $queue_tbl, array( 'status' => 'failed', 'error' => $reason ), array( 'id' => (int) $item['id'] ) );
				// R-CRON-META: note_event on fail
				if ( $cron_mgr ) {
					$cron_mgr->note_event( 'broadcast_send_failed', array(
						'queue_id'    => (int) $item['id'],
						'broadcast_id'=> (int) $broadcast_id,
						'contact_id'  => (int) $item['contact_id'],
						'reason'      => $reason,
					) );
				}
			}
		}

		// R-CRON-META: note counters
		if ( $cron_mgr ) {
			$cron_mgr->note( array( 'counters' => $counters ) );
		}

		// [2026-07-10 Johnny Chu] PHASE-0.47 — keep broadcast header counters/status in sync after each tick.
		if ( ! empty( $touched ) ) {
			foreach ( array_keys( $touched ) as $bid ) {
				self::reconcile_broadcast( (int) $bid );
			}
		}
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — reconcile one broadcast header from recipient ledger.
	 */
	private static function reconcile_broadcast( $broadcast_id ) {
		global $wpdb;
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 ) {
			return;
		}

		$queue_tbl = self::recipients_tbl();
		$bc_tbl    = $wpdb->prefix . 'bizcity_crm_broadcasts';

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM `{$queue_tbl}` WHERE broadcast_id=%d GROUP BY status", $broadcast_id ),
			ARRAY_A
		) ?: array();

		$sent    = 0;
		$failed  = 0;
		$queued  = 0;
		$skipped = 0;
		$total   = 0;
		foreach ( $rows as $r ) {
			$st = (string) ( isset( $r['status'] ) ? $r['status'] : '' );
			$ct = (int) ( isset( $r['cnt'] ) ? $r['cnt'] : 0 );
			$total += $ct;
			if ( $st === 'sent' ) {
				$sent = $ct;
			} elseif ( $st === 'failed' ) {
				$failed = $ct;
			} elseif ( $st === 'queued' ) {
				$queued = $ct;
			} elseif ( $st === 'skipped' ) {
				$skipped = $ct;
			}
		}

		$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM `{$bc_tbl}` WHERE id=%d", $broadcast_id ), ARRAY_A );
		if ( ! $bc_row ) {
			return;
		}

		$status = (string) $bc_row['status'];
		$done   = ( $total > 0 && $queued <= 0 );
		if ( $done && in_array( $status, array( 'sending', 'queued', 'paused' ), true ) ) {
			$status = 'sent';
		} elseif ( ! $done && in_array( $status, array( 'queued', 'paused', 'sent' ), true ) ) {
			$status = 'sending';
		}

		$wpdb->update(
			$bc_tbl,
			array(
				'status'       => $status,
				'total_count'  => $total,
				'sent_count'   => $sent,
				'failed_count' => $failed,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( 'id' => $broadcast_id ),
			array( '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-0.47 — sweep active broadcasts to fix stale sending badge.
	 */
	private static function reconcile_sending_broadcasts() {
		global $wpdb;
		$bc_tbl = $wpdb->prefix . 'bizcity_crm_broadcasts';
		$rows   = $wpdb->get_col( "SELECT id FROM `{$bc_tbl}` WHERE status IN ('sending','queued','paused') ORDER BY id DESC LIMIT 100" );
		if ( empty( $rows ) ) {
			return;
		}
		foreach ( $rows as $bid ) {
			self::reconcile_broadcast( (int) $bid );
		}
	}

	/* ------------------------------------------------------------------
	 * Single-item send
	 * ------------------------------------------------------------------ */

	/**
	 * Attempt to send one queue item.
	 *
	 * @param array $item Row from bizcity_crm_broadcast_queue.
	 * @return true|string True on success, error reason string on failure.
	 */
	private static function send_one( array $item ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — pick variant + send
		// [2026-06-07 Johnny Chu] PHASE-0.43 M1.3 — action_flags dispatch (send_message/send_friend_request/invite_group)
		$contact_id   = (int) $item['contact_id'];
		$broadcast_id = (int) $item['broadcast_id'];

		if ( $contact_id <= 0 || $broadcast_id <= 0 ) {
			return 'invalid_param'; // R-CRON-META bucket
		}

		// Resolve contact's chat_id + platform.
		$platform = '';
		$chat_id  = '';
		if ( class_exists( 'BizCity_CRM_Contact_Repository' ) ) {
			$contact = BizCity_CRM_Contact_Repository::get( $contact_id );
			if ( is_array( $contact ) ) {
				$chat_id  = (string) ( isset( $contact['zalo_uid'] ) ? $contact['zalo_uid'] : ( isset( $contact['fb_uid'] ) ? $contact['fb_uid'] : ( isset( $contact['chat_id'] ) ? $contact['chat_id'] : '' ) ) );
				$platform = (string) ( isset( $contact['primary_platform'] ) ? $contact['primary_platform'] : '' );
			}
		}
		if ( $chat_id === '' ) {
			return 'invalid_param';
		}

		// Get campaign + action_flags from broadcast header.
		global $wpdb;
		$bc_tbl = $wpdb->prefix . 'bizcity_crm_broadcasts';
		$bc_row = $wpdb->get_row( $wpdb->prepare( "SELECT campaign_id, action_flags_json, message_template FROM `{$bc_tbl}` WHERE id=%d", $broadcast_id ), ARRAY_A );
		if ( ! $bc_row ) {
			return 'not_found';
		}

		// Parse action_flags (NULL-safe fallback: send_message only).
		$flags_raw = isset( $bc_row['action_flags_json'] ) ? $bc_row['action_flags_json'] : null;
		$flags     = ( $flags_raw ) ? json_decode( $flags_raw, true ) : array();
		if ( ! is_array( $flags ) ) {
			$flags = array();
		}
		$do_message       = ! empty( $flags['send_message'] );
		$do_friend        = ! empty( $flags['send_friend_request'] );
		$do_group         = ! empty( $flags['invite_group'] );
		$group_id         = isset( $flags['group_id'] ) ? (string) $flags['group_id'] : '';
		// NULL-safe fallback
		if ( ! $do_message && ! $do_friend && ! $do_group ) {
			$do_message = true;
		}

		// Pick variant (full object: text, images[], friend_invite_text, weight).
		$campaign_id = (int) $bc_row['campaign_id'];
		$campaign    = ( $campaign_id > 0 && class_exists( 'BizCity_CRM_Campaign_Repository' ) )
			? BizCity_CRM_Campaign_Repository::get( $campaign_id )
			: null;
		// [2026-06-07 Johnny Chu] PHASE-0.43 — synthetic campaign fallback từ broadcast.message_template
		// khi broadcast không link campaign (campaign_id=0, tạo qua BroadcastCreateDialog).
		if ( ! is_array( $campaign ) || empty( $campaign ) ) {
			$tpl = isset( $bc_row['message_template'] ) ? (string) $bc_row['message_template'] : '';
			if ( $tpl === '' ) {
				return 'not_found';
			}
			$campaign = array( 'scenario_template' => $tpl, 'variants' => array() );
		}

		$variant = self::pick_variant_full( $campaign, $contact_id );
		$message_text = self::substitute_vars( (string) ( isset( $variant['text'] ) ? $variant['text'] : '' ), $contact_id );
		$images       = isset( $variant['images'] ) && is_array( $variant['images'] ) ? $variant['images'] : array();
		$friend_text  = isset( $variant['friend_invite_text'] ) ? (string) $variant['friend_invite_text'] : $message_text;

		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return 'gateway_degraded';
		}

		$cron_mgr   = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		$any_failed = false;

		// Action 1 — send_friend_request (Zalo Personal only, fail-open)
		if ( $do_friend ) {
			if ( class_exists( 'BizCity_Zalo_Personal_Adapter' ) ) {
				$fr_result = BizCity_Zalo_Personal_Adapter::send_friend_request( $chat_id, $friend_text );
				if ( is_wp_error( $fr_result ) ) {
					if ( $cron_mgr ) {
						$cron_mgr->note_event( 'broadcast_send_failed', array(
							'queue_id' => (int) $item['id'], 'action' => 'friend_request',
							'reason'   => 'friend_request_error', 'error' => $fr_result->get_error_message(),
						) );
					}
					$any_failed = true;
				}
			} else {
				if ( $cron_mgr ) {
					$cron_mgr->note_event( 'broadcast_send_failed', array(
						'queue_id' => (int) $item['id'], 'action' => 'friend_request', 'reason' => 'permission_denied',
					) );
				}
				$any_failed = true;
			}
		}

		// Action 2 — send_message
		if ( $do_message && $message_text !== '' ) {
			$result = BizCity_Gateway_Sender::send( $platform, $chat_id, $message_text, $images );
			if ( is_wp_error( $result ) ) {
				$code   = $result->get_error_code();
				$reason = ( strpos( $code, 'token' ) !== false ) ? 'token_invalid'
					: ( ( strpos( $code, 'rate' ) !== false || strpos( $code, 'quota' ) !== false ) ? 'rate_limited'
					: ( ( strpos( $code, 'timeout' ) !== false ) ? 'timeout' : 'http_error' ) );
				if ( $cron_mgr ) {
					$cron_mgr->note_event( 'broadcast_send_failed', array(
						'queue_id' => (int) $item['id'], 'action' => 'send_message', 'reason' => $reason,
					) );
				}
				$any_failed = true;
			}
		}

		// Action 3 — invite_group
		if ( $do_group && $group_id !== '' ) {
			if ( class_exists( 'BizCity_Zalo_Personal_Adapter' ) ) {
				$gr_result = BizCity_Zalo_Personal_Adapter::invite_to_group( $group_id, $chat_id );
				if ( is_wp_error( $gr_result ) ) {
					if ( $cron_mgr ) {
						$cron_mgr->note_event( 'broadcast_send_failed', array(
							'queue_id' => (int) $item['id'], 'action' => 'invite_group',
							'reason'   => 'invite_group_error', 'error' => $gr_result->get_error_message(),
						) );
					}
					$any_failed = true;
				}
			} else {
				if ( $cron_mgr ) {
					$cron_mgr->note_event( 'broadcast_send_failed', array(
						'queue_id' => (int) $item['id'], 'action' => 'invite_group', 'reason' => 'permission_denied',
					) );
				}
				$any_failed = true;
			}
		}

		// best-effort: return true if at least one action attempted, partial fail still logged
		return $any_failed ? 'http_error' : true;
	}

	/* ------------------------------------------------------------------
	 * Variant picker
	 * ------------------------------------------------------------------ */

	/**
	 * Pick message text from campaign variants array.
	 * If variants is empty, fall back to scenario_template.
	 *
	 * @param array $campaign Hydrated campaign row (with 'variants' key).
	 * @param int   $contact_id Used for round-robin (mode=all) if needed.
	 * @return string Message text, empty string if none available.
	 */
	public static function pick_variant( array $campaign, $contact_id = 0 ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — variant selection logic
		$variants = isset( $campaign['variants'] ) && is_array( $campaign['variants'] ) ? $campaign['variants'] : array();
		if ( empty( $variants ) ) {
			return (string) ( isset( $campaign['scenario_template'] ) ? $campaign['scenario_template'] : ( isset( $campaign['reminder_text'] ) ? $campaign['reminder_text'] : '' ) );
		}
		$mode = isset( $campaign['variant_mode'] ) ? (string) $campaign['variant_mode'] : 'random';
		if ( $mode === 'all' ) {
			// Round-robin: distribute by contact_id mod count.
			$idx = ( $contact_id > 0 ) ? ( $contact_id % count( $variants ) ) : 0;
		} else {
			// Random (default Deplao behaviour).
			$idx = array_rand( $variants );
		}
		$variant = $variants[ $idx ];
		return isset( $variant['text'] ) ? (string) $variant['text'] : (string) $variant;
	}

	/**
	 * [2026-06-07 Johnny Chu] PHASE-0.43 M1.4 — Pick full variant object (weighted random).
	 * Returns full variant array {text, images[], friend_invite_text, weight}.
	 * Falls back to {text: scenario_template} if no variants.
	 *
	 * @param array $campaign    Hydrated campaign row.
	 * @param int   $contact_id  Unused placeholder for future per-contact logic.
	 * @return array Variant object.
	 */
	public static function pick_variant_full( array $campaign, $contact_id = 0 ) {
		$variants = isset( $campaign['variants'] ) && is_array( $campaign['variants'] ) ? $campaign['variants'] : array();
		if ( empty( $variants ) ) {
			$fallback = (string) ( isset( $campaign['scenario_template'] ) ? $campaign['scenario_template'] : '' );
			return array( 'text' => $fallback );
		}
		// Weighted random
		$total = 0;
		foreach ( $variants as $v ) {
			$total += max( 1, (int) ( isset( $v['weight'] ) ? $v['weight'] : 1 ) );
		}
		$rand = wp_rand( 1, $total );
		$acc  = 0;
		foreach ( $variants as $v ) {
			$acc += max( 1, (int) ( isset( $v['weight'] ) ? $v['weight'] : 1 ) );
			if ( $rand <= $acc ) {
				return is_array( $v ) ? $v : array( 'text' => (string) $v );
			}
		}
		$last = end( $variants );
		return is_array( $last ) ? $last : array( 'text' => (string) $last );
	}

	/* ------------------------------------------------------------------
	 * Template variable substitution
	 * ------------------------------------------------------------------ */

	/**
	 * Replace {name}, {userId}, {phone} in message text.
	 *
	 * @param string $text       Template text.
	 * @param int    $contact_id CRM contact id.
	 * @return string Substituted text.
	 */
	public static function substitute_vars( $text, $contact_id ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.4 — variable substitution
		if ( strpos( $text, '{' ) === false ) {
			return $text;
		}
		$name  = '';
		$phone = '';
		$uid   = '';
		if ( $contact_id > 0 && class_exists( 'BizCity_CRM_Contact_Repository' ) ) {
			$contact = BizCity_CRM_Contact_Repository::get( $contact_id );
			if ( is_array( $contact ) ) {
				$name  = (string) ( $contact['display_name'] ?? $contact['name'] ?? '' );
				$phone = (string) ( $contact['phone'] ?? '' );
				$uid   = (string) $contact_id;
			}
		}
		$text = str_replace( '{name}',   $name,  $text );
		$text = str_replace( '{userId}', $uid,   $text );
		$text = str_replace( '{phone}',  $phone, $text );
		return $text;
	}

	/* ------------------------------------------------------------------
	 * Queue builder — called by REST or admin action
	 * ------------------------------------------------------------------ */

	/**
	 * Enqueue contacts into existing broadcast_recipients for a broadcast id.
	 *
	 * @param int   $broadcast_id  Broadcast row ID (bizcity_crm_broadcasts.id).
	 * @param array $contact_ids   Array of CRM contact IDs.
	 * @param array $opts          Unused (reserved for future throttle options).
	 * @return array {enqueued: int, skipped: int}.
	 */
	public static function enqueue( $broadcast_id, array $contact_ids, array $opts = array() ) {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G4.2 — bulk enqueue into existing recipients table
		// [2026-06-07 Johnny Chu] PHASE-0.43 M1.1 — per-recipient delay scheduling via scheduled_send_at
		global $wpdb;
		$queue_tbl    = self::recipients_tbl();
		$enqueued     = 0;
		$skipped      = 0;
		$broadcast_id = (int) $broadcast_id;
		if ( $broadcast_id <= 0 ) {
			return array( 'enqueued' => 0, 'skipped' => count( $contact_ids ) );
		}

		// Fetch delay_sec from broadcast row (default 5s)
		$bc_tbl    = $wpdb->prefix . 'bizcity_crm_broadcasts';
		$delay_sec = (int) $wpdb->get_var( $wpdb->prepare( "SELECT delay_sec FROM `{$bc_tbl}` WHERE id=%d", $broadcast_id ) );
		$delay_sec = max( 0, $delay_sec );
		$now_ts    = time();
		$idx       = 0;

		foreach ( $contact_ids as $cid ) {
			$cid = (int) $cid;
			if ( $cid <= 0 ) {
				$skipped++;
				continue;
			}
			$send_at = ( $delay_sec > 0 )
				? gmdate( 'Y-m-d H:i:s', $now_ts + ( $idx * $delay_sec ) )
				: null;
			$wpdb->insert( $queue_tbl, array(
				'broadcast_id'      => $broadcast_id,
				'contact_id'        => $cid,
				'conversation_id'   => null,
				'status'            => 'queued',
				'sent_at'           => null,
				'error'             => null,
				'scheduled_send_at' => $send_at,
			) );
			$enqueued++;
			$idx++;
		}
		return array( 'enqueued' => $enqueued, 'skipped' => $skipped );
	}
}
