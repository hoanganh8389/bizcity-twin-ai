<?php
/**
 * BizCity CRM — the one writer for customer pipeline changes (PHASE-0.52, R-PIPE-2/3/4/5).
 *
 * `change()` covers every entry point with the same rules — Inbox toolbar "Giai đoạn" sheet (B2 + C),
 * "Ghi kết quả" / "Ghi cuộc gọi" on /gpt/ "Hôm nay", manual moves on "Khách của tôi":
 *   - scope first: B2 = actor's inbox scope (admin tenant-wide), C = the member's own scope only;
 *   - flexible (R-PIPE-2/5): any stage may be chosen by hand, incl. "won" without a Woo order; only "lost"
 *     needs a reason; steps and reminders are optional;
 *   - one pipeline opportunity per customer carries the manual stage + ticked steps (R-PIPE-4);
 *   - every change is audited on `crm_contact` (R-PIPE-3); a note becomes a private note in the conversation
 *     (shows inside the thread, never sent to the customer); a reminder is a normal contact task;
 *   - an outcome may complete the member's open handoff task on this customer (leader-task-handoff).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.52 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Stage_Service', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Stage_Service {

	const MANUAL_STAGES = array( 'target', 'contacted', 'consult', 'quote', 'won', 'repeat', 'lost' );
	/** care-outcome@1.0.0 — code => stage it moves to ('' = keep), reminder title, days. */
	const OUTCOMES = array(
		'interested' => array( 'to' => 'consult', 'next' => 'Gửi thêm thông tin', 'days' => 1 ),
		'quoted'     => array( 'to' => 'quote',   'next' => 'Gọi lại chốt báo giá', 'days' => 2 ),
		'ordered'    => array( 'to' => 'won',     'next' => 'Chăm sau mua D+3', 'days' => 3 ),
		'callback'   => array( 'to' => '',        'next' => 'Gọi lại theo hẹn', 'days' => 1 ),
		'no_answer'  => array( 'to' => '',        'next' => 'Thử liên hệ lại', 'days' => 1 ),
		'lost'       => array( 'to' => 'lost',    'next' => '', 'days' => 0 ),
	);
	const CHANNELS = array( 'zalo', 'call', 'meeting', 'other' );
	const IDEMPOTENCY_TTL = 600;

	/**
	 * @param string $surface 'b2' | 'c'
	 * @param array  $in {to?, note?, steps_done?: int[], remind?: {enabled, title?, due_date?|days?}, lost_reason?, outcome?, channel?, conversation_id?, client_request_id?}
	 * @return array|WP_Error
	 */
	public static function change( int $actor_id, int $contact_id, array $in, string $surface ) {
		if ( $actor_id <= 0 ) { return self::error( 'auth_required', 'Cần đăng nhập.', 401 ); }
		if ( $contact_id <= 0 ) { return self::error( 'contact_not_in_scope', 'Không tìm thấy khách.', 404 ); }
		$inbox_ids = 'c' === $surface ? BizCity_CRM_Customer_Pipeline::c_inbox_ids( $actor_id ) : BizCity_CRM_Customer_Pipeline::b2_inbox_ids( $actor_id );
		if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, $inbox_ids ) ) {
			return self::error( 'contact_not_in_scope', 'Không tìm thấy khách trong phạm vi của bạn.', 404 );
		}

		$request_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['client_request_id'] ?? '' ) );
		$idem = '' !== $request_key ? 'bzc_pipe_' . md5( get_current_blog_id() . '|' . $actor_id . '|' . $contact_id . '|' . $request_key ) : '';
		if ( '' !== $idem ) {
			$prev = get_transient( $idem );
			if ( is_array( $prev ) ) { return array_merge( $prev, array( 'duplicate' => true ) ); }
		}

		$settings = BizCity_CRM_Customer_Pipeline::settings();
		$rows = BizCity_CRM_Customer_Pipeline::rows( array( $contact_id ), $settings );
		if ( ! isset( $rows[ $contact_id ] ) ) { return self::error( 'contact_not_in_scope', 'Không tìm thấy khách.', 404 ); }
		$row = $rows[ $contact_id ];
		$from = (string) $row['stage'];

		$outcome = sanitize_key( (string) ( $in['outcome'] ?? '' ) );
		if ( '' !== $outcome && ! isset( self::OUTCOMES[ $outcome ] ) ) { return self::error( 'invalid_outcome', 'Kết quả không hợp lệ.', 422 ); }
		$channel = sanitize_key( (string) ( $in['channel'] ?? '' ) );
		if ( '' !== $channel && ! in_array( $channel, self::CHANNELS, true ) ) { $channel = 'other'; }

		$to = sanitize_key( (string) ( $in['to'] ?? '' ) );
		if ( '' === $to && '' !== $outcome ) { $to = self::OUTCOMES[ $outcome ]['to']; }
		// Any logged outcome on a never-touched customer counts as a touch (e.g. a phone call).
		if ( '' === $to && '' !== $outcome && 'target' === $from ) { $to = 'contacted'; }
		if ( '' !== $to && ! in_array( $to, self::MANUAL_STAGES, true ) ) { return self::error( 'invalid_stage', 'Giai đoạn không hợp lệ.', 422 ); }
		$current_base = (string) $row['base_stage'];
		$moving = '' !== $to && $to !== $from && ! ( 'dormant' === $from && $to === $current_base );

		$lost_reason = BizCity_CRM_Customer_Pipeline::clean_text( (string) ( $in['lost_reason'] ?? '' ), 80 );
		if ( 'lost' === $to && $moving && '' === $lost_reason ) {
			return self::error( 'lost_reason_required', 'Chọn lý do khách không mua.', 422, 'Chọn một lý do trong danh sách.' );
		}
		$note = trim( sanitize_textarea_field( (string) ( $in['note'] ?? '' ) ) );
		if ( mb_strlen( $note ) > 2000 ) { return self::error( 'content_too_long', 'Ghi chú tối đa 2000 ký tự.', 422 ); }

		// Steps ticked for the stage the customer is CURRENTLY in (before a move).
		$step_stage = $from;
		$step_labels = $settings['steps'][ $step_stage ] ?? array();
		$steps_in = isset( $in['steps_done'] ) && is_array( $in['steps_done'] ) ? array_map( 'intval', $in['steps_done'] ) : null;

		$pipe = $row['pipe'];
		$stored_steps = is_array( $pipe['steps'] ?? null ) ? $pipe['steps'] : array();
		$newly_done = array();
		if ( null !== $steps_in ) {
			$current = is_array( $stored_steps[ $step_stage ] ?? null ) ? $stored_steps[ $step_stage ] : array();
			$next = array();
			foreach ( $step_labels as $i => $label ) {
				$done = in_array( (int) $i, $steps_in, true );
				$next[ (int) $i ] = $done;
				if ( $done && empty( $current[ $i ] ) ) { $newly_done[] = array( 'key' => (int) $i, 'label' => $label ); }
			}
			$stored_steps[ $step_stage ] = $next;
		}

		$now_ts = (int) current_time( 'timestamp' );
		$opp_id = self::upsert_opportunity( $contact_id, $row, $moving ? $to : ( $pipe && empty( $pipe['legacy'] ) ? (string) $pipe['stage'] : '' ), $moving ? $now_ts : (int) ( $pipe['at_ts'] ?? 0 ), '' !== $outcome ? 'outcome' : 'manual', $stored_steps, $lost_reason, $actor_id, $moving || null !== $steps_in );

		$audit = class_exists( 'BizCity_CRM_Audit_Log' );
		if ( $moving && $audit ) {
			BizCity_CRM_Audit_Log::log( 'crm_contact', $contact_id, 'stage_changed', array( 'stage' => $from ), array_filter( array(
				'stage' => $to, 'note' => $note, 'source' => '' !== $outcome ? 'outcome' : 'manual', 'outcome' => $outcome, 'channel' => $channel,
				'lost_reason' => $lost_reason, 'surface' => $surface,
			), static function ( $v ) { return '' !== $v; } ), array( 'user_id' => $actor_id ) );
		}
		if ( $audit ) {
			foreach ( $newly_done as $step ) {
				BizCity_CRM_Audit_Log::log( 'crm_contact', $contact_id, 'step_done', null, array( 'stage' => $step_stage, 'step_key' => $step['key'], 'step_label' => $step['label'] ), array( 'user_id' => $actor_id ) );
			}
			if ( '' !== $outcome && ! $moving ) {
				BizCity_CRM_Audit_Log::log( 'crm_contact', $contact_id, 'outcome_logged', array( 'stage' => $from ), array_filter( array( 'stage' => $from, 'outcome' => $outcome, 'channel' => $channel, 'note' => $note ), static function ( $v ) { return '' !== $v; } ), array( 'user_id' => $actor_id ) );
			} elseif ( ! $moving && '' !== $note ) {
				BizCity_CRM_Audit_Log::log( 'crm_contact', $contact_id, 'outcome_logged', array( 'stage' => $from ), array( 'stage' => $from, 'note' => $note ), array( 'user_id' => $actor_id ) );
			}
		}

		// Note inside the conversation thread (private — never sent to the customer).
		$note_id = 0;
		$conv_id = (int) ( $in['conversation_id'] ?? 0 ) ?: (int) $row['conversation_id'];
		if ( ( $moving || '' !== $note || '' !== $outcome ) && $conv_id > 0 && self::conversation_in_scope( $conv_id, $contact_id, $inbox_ids ) && class_exists( 'BizCity_CRM_Repository' ) ) {
			$labels = BizCity_CRM_Customer_Pipeline::LABELS;
			$line = $moving ? '📌 Giai đoạn: ' . ( $labels[ $from ] ?? $from ) . ' → ' . ( $labels[ $to ] ?? $to ) : '📌 ' . ( $labels[ $from ] ?? $from );
			if ( '' !== $outcome ) { $line .= ' · kết quả: ' . self::outcome_label( $outcome ) . ( '' !== $channel ? ' (' . self::channel_label( $channel ) . ')' : '' ); }
			if ( '' !== $lost_reason && 'lost' === $to ) { $line .= ' · lý do: ' . $lost_reason; }
			if ( '' !== $note ) { $line .= "\n" . $note; }
			$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
			$note_id = (int) BizCity_CRM_Repository::insert_message( array(
				'conversation_id' => $conv_id, 'inbox_id' => (int) ( $conv['inbox_id'] ?? 0 ), 'content' => $line, 'content_type' => 'text',
				'message_type' => 'private_note', 'sender_type' => 'agent', 'sender_id' => $actor_id, 'status' => 'note',
				'responder_kind' => 'manual', 'responder_user_id' => $actor_id,
			) );
		}

		// Optional reminder = the next step (R-PIPE-5: suggestion, may be switched off).
		$reminder_id = 0;
		$remind = is_array( $in['remind'] ?? null ) ? $in['remind'] : array();
		if ( ! empty( $remind['enabled'] ) && ( 'lost' !== ( $moving ? $to : $from ) ) ) {
			$target_stage = $moving ? $to : $from;
			$title = BizCity_CRM_Customer_Pipeline::clean_text( (string) ( $remind['title'] ?? '' ), 180 );
			if ( '' === $title ) {
				$title = '' !== $outcome && '' !== self::OUTCOMES[ $outcome ]['next'] ? self::OUTCOMES[ $outcome ]['next'] : (string) ( $settings['steps'][ $target_stage ][0] ?? 'Liên hệ lại' );
			}
			$due = self::due_date( $remind, '' !== $outcome ? (int) self::OUTCOMES[ $outcome ]['days'] : 1 );
			if ( is_wp_error( $due ) ) { return $due; }
			$reminder_id = self::insert_reminder( $actor_id, $contact_id, $title, $due, $note, $conv_id );
		}

		// An outcome closes the member's own open handoff task on this customer (if any).
		$task_done = 0;
		if ( '' !== $outcome && 'no_answer' !== $outcome && class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			$open = BizCity_CRM_Task_Handoff::list_for_member( $actor_id, 'open', 20, $contact_id );
			if ( ! empty( $open ) ) {
				$task = $open[0];
				$tid = (int) ( $task['task_id'] ?? 0 );
				if ( 'sent' === ( $task['status'] ?? '' ) ) { BizCity_CRM_Task_Handoff::member_transition( $actor_id, $tid, 'accepted' ); }
				$res = BizCity_CRM_Task_Handoff::member_transition( $actor_id, $tid, 'done', '', '' !== $note ? $note : self::outcome_label( $outcome ) );
				if ( ! is_wp_error( $res ) ) { $task_done = $tid; }
			}
		}

		$after = BizCity_CRM_Customer_Pipeline::rows( array( $contact_id ), $settings );
		$result = array(
			'contact_id'     => $contact_id,
			'from'           => $from,
			'to'             => $moving ? $to : $from,
			'stage'          => (string) ( $after[ $contact_id ]['stage'] ?? ( $moving ? $to : $from ) ),
			'moved'          => $moving,
			'opportunity_id' => $opp_id,
			'note_message_id' => $note_id ?: null,
			'reminder_task_id' => $reminder_id ?: null,
			'handoff_task_done' => $task_done ?: null,
			'steps_done'     => array_map( static function ( $s ) { return $s['key']; }, $newly_done ),
		);
		if ( '' !== $idem ) { set_transient( $idem, $result, self::IDEMPOTENCY_TTL ); }
		if ( $moving ) { do_action( 'bizcity_crm_pipeline_stage_changed', $contact_id, $from, $to, $actor_id, '' !== $outcome ? 'outcome' : 'manual' ); }
		return $result;
	}

	/**
	 * [PHASE-0.54 R-INBOX-PIPE-6b, D54-2] Order ↔ stage is one loop: when creating an order (any CRM order
	 * entry point — cột phải / OrderTab / OrderSheet, `class-order-adapter.php::create_order()`) pushes the
	 * customer's resolved stage UP (facts only ever push up, R-PIPE-2), leave a 🧭 timeline line + audit entry
	 * so the change is never silent (R-PIPE-3 / R-INBOX-PIPE-5). This never rewrites the manual pipeline
	 * opportunity — the stage itself stays fact-computed (`resolve()`); this only records *why* it moved.
	 * Best-effort: never throws, never blocks order creation.
	 */
	public static function note_order_created( int $contact_id, string $from_stage, string $to_stage, int $conv_id, int $order_id, int $actor_id ): void {
		if ( $contact_id <= 0 || $order_id <= 0 || $from_stage === $to_stage ) { return; }
		try {
			if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
				BizCity_CRM_Audit_Log::log( 'crm_contact', $contact_id, 'stage_changed', array( 'stage' => $from_stage ), array(
					'stage' => $to_stage, 'source' => 'order_created', 'order_id' => $order_id,
				), array( 'user_id' => $actor_id ) );
			}
			if ( $conv_id > 0 && class_exists( 'BizCity_CRM_Repository' ) ) {
				$labels = BizCity_CRM_Customer_Pipeline::LABELS;
				$line = '🧭 Đã tạo đơn #' . $order_id . ' → ' . ( $labels[ $to_stage ] ?? $to_stage );
				$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
				BizCity_CRM_Repository::insert_message( array(
					'conversation_id' => $conv_id, 'inbox_id' => (int) ( $conv['inbox_id'] ?? 0 ), 'content' => $line, 'content_type' => 'text',
					'message_type' => 'private_note', 'sender_type' => 'agent', 'sender_id' => $actor_id, 'status' => 'note',
					'responder_kind' => 'manual', 'responder_user_id' => $actor_id,
				) );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) { error_log( '[bizcity-crm] note_order_created failed: ' . $e->getMessage() ); }
		}
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/**
	 * Keep exactly one pipeline opportunity per customer (R-PIPE-4). A legacy (Chatwoot) opportunity is adopted
	 * by flagging it instead of creating a second one.
	 */
	private static function upsert_opportunity( int $contact_id, array $row, string $stage, int $at_ts, string $source, array $steps, string $lost_reason, int $actor_id, bool $write ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$pipe = $row['pipe'];
		$opp_id = (int) ( $pipe['opportunity_id'] ?? 0 );
		if ( ! $write ) { return $opp_id; }
		if ( '' === $stage ) { $stage = (string) $row['base_stage']; }
		if ( ! in_array( $stage, self::MANUAL_STAGES, true ) ) { $stage = in_array( $row['base_stage'], self::MANUAL_STAGES, true ) ? $row['base_stage'] : 'contacted'; }
		$now = current_time( 'mysql' );
		$custom = array();
		if ( $opp_id > 0 ) {
			$existing = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT custom_json FROM `{$tbl}` WHERE id = %d", $opp_id ) ), true );
			$custom = is_array( $existing ) ? $existing : array();
		}
		$custom = array_merge( $custom, array( 'pipeline' => true, 'pipeline_stage' => $stage, 'stage_at' => $at_ts > 0 ? $at_ts : (int) current_time( 'timestamp' ), 'source' => $source, 'steps' => $steps ) );
		$fields = array(
			'stage'       => BizCity_CRM_Customer_Pipeline::OPP_STAGE_OUT[ $stage ] ?? 'prospecting',
			'status'      => in_array( $stage, array( 'won', 'repeat' ), true ) ? 'won' : ( 'lost' === $stage ? 'lost' : 'open' ),
			'lost_reason' => 'lost' === $stage ? $lost_reason : null,
			'custom_json' => wp_json_encode( $custom ),
			'updated_at'  => $now,
		);
		if ( in_array( $stage, array( 'won', 'repeat' ), true ) ) { $fields['actual_close_date'] = current_time( 'Y-m-d' ); }
		if ( $opp_id > 0 ) {
			$wpdb->update( $tbl, $fields, array( 'id' => $opp_id ) );
			return $opp_id;
		}
		$owner = (int) $row['owner_id'] ?: $actor_id;
		$wpdb->insert( $tbl, array_merge( $fields, array(
			'name'       => 'Pipeline · ' . BizCity_CRM_Customer_Pipeline::clean_text( (string) $row['name'], 200 ),
			'contact_id' => $contact_id,
			'owner_id'   => $owner,
			'currency'   => 'VND',
			'created_by' => $actor_id,
			'created_at' => $now,
		) ) );
		return (int) $wpdb->insert_id;
	}

	private static function conversation_in_scope( int $conv_id, int $contact_id, ?array $inbox_ids ): bool {
		global $wpdb;
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT c.inbox_id, ci.contact_id FROM `{$conv_t}` c INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id WHERE c.id = %d", $conv_id ), ARRAY_A );
		if ( ! $row || (int) $row['contact_id'] !== $contact_id ) { return false; }
		return null === $inbox_ids || in_array( (int) $row['inbox_id'], array_map( 'intval', (array) $inbox_ids ), true );
	}

	/** @return string|WP_Error Y-m-d */
	private static function due_date( array $remind, int $default_days ) {
		$raw = (string) ( $remind['due_date'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			if ( $raw < current_time( 'Y-m-d' ) ) { return self::error( 'due_in_past', 'Ngày nhắc đã qua.', 422 ); }
			return $raw;
		}
		$days = isset( $remind['days'] ) ? max( 0, min( 365, (int) $remind['days'] ) ) : max( 0, $default_days );
		return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . " +{$days} days" ) );
	}

	private static function insert_reminder( int $actor_id, int $contact_id, string $title, string $due, string $note, int $conv_id ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(), array(
			'title' => $title, 'status' => 'open', 'priority' => 'medium', 'due_date' => $due, 'assignee_id' => $actor_id,
			'related_entity_type' => 'contact', 'related_entity_id' => $contact_id,
			'notes' => trim( $note . ( $conv_id > 0 ? "\n\n— conversation #" . $conv_id : '' ) ),
			'completed' => 0, 'created_by' => $actor_id, 'created_at' => $now, 'updated_at' => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function outcome_label( string $code ): string {
		$labels = array( 'interested' => 'Đã tư vấn · quan tâm', 'quoted' => 'Đã gửi báo giá', 'ordered' => 'Khách chốt đơn', 'callback' => 'Hẹn gọi lại', 'no_answer' => 'Không nghe máy / chưa trả lời', 'lost' => 'Không có nhu cầu' );
		return $labels[ $code ] ?? $code;
	}

	public static function channel_label( string $code ): string {
		$labels = array( 'zalo' => 'Nhắn Zalo', 'call' => 'Gọi điện', 'meeting' => 'Gặp trực tiếp', 'other' => 'Khác' );
		return $labels[ $code ] ?? $code;
	}

	private static function error( string $code, string $message, int $status, string $hint = '' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'hint' => $hint, 'help_code' => $code ) );
	}
}
