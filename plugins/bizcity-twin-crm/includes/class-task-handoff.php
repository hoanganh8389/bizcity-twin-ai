<?php
/**
 * BizCity CRM — Leader → member task handoff service (PHASE-0.50, R-LEADER-MEMBER R-LM-5).
 *
 * One owner for `leader-task-handoff@1.0.0`, shared by:
 *   - B2 leader routes (`/twin/`, `/crm/`) in {@see BizCity_CRM_Leader_Member_REST};
 *   - C member routes (`/gpt/crm/`) in modules/twinweb.
 *
 * Storage is the existing `bizcity_crm_tasks` table (no new table/column):
 *   assignee_id = member, created_by = leader, notes = instructions,
 *   related_entity_type/id = contact|conversation subject, status = handoff vocabulary.
 * Transition history, "seen", return reasons and batch correlation live in the
 * existing `bizcity_crm_audit_log` (entity_type = crm_task).
 *
 * Every public method takes the server-resolved actor id; nothing here reads a
 * user id from a request.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Task_Handoff' ) ) { return; }

final class BizCity_CRM_Task_Handoff {

	const CONTRACT = 'leader-task-handoff';
	const VERSION  = '1.2.0';

	const STATUS_SENT        = 'sent';
	const STATUS_ACCEPTED    = 'accepted';
	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_DONE        = 'done';
	const STATUS_RETURNED    = 'returned';
	const STATUS_CANCELLED   = 'cancelled';

	const STATUSES       = array( 'sent', 'accepted', 'in_progress', 'done', 'returned', 'cancelled' );
	const OPEN_STATUSES  = array( 'sent', 'accepted', 'in_progress', 'returned' );
	const PRIORITIES     = array( 'low', 'medium', 'high', 'urgent' );
	const RETURN_REASONS = array( 'wrong_person', 'customer_unreachable', 'out_of_scope', 'other' );
	const MAX_SUBJECTS   = 200;
	const AUDIT_ENTITY   = 'crm_task';
	const IDEMPOTENCY_TTL = 600;
	// [2026-09-19 Johnny Chu] PHASE-0.55-A4 — one server-owned playbook catalog for leader and member surfaces.
	const PLAYBOOKS = array(
		'new_24h' => array( 'segment' => 'target_new', 'title' => 'Nhắn chào + hỏi nhu cầu', 'label' => 'Chạm khách mới', 'instructions' => 'Chào khách, hỏi nhu cầu và thời điểm cần. Ghi kết quả ngay sau khi nhắn.', 'due_days' => 0, 'priority' => 'high' ),
		'quote_followup' => array( 'segment' => 'quote_stuck', 'title' => 'Gọi lại chốt báo giá', 'label' => 'Theo sau báo giá', 'instructions' => 'Nhắc ưu đãi còn hạn, hỏi vướng mắc, đề xuất phương án thanh toán.', 'due_days' => 2, 'priority' => 'high' ),
		'nudge' => array( 'segment' => 'consult_stuck', 'title' => 'Gửi thông tin còn thiếu', 'label' => 'Gỡ khách tư vấn bị kẹt', 'instructions' => 'Đọc lại hội thoại, gửi đúng thông tin khách hỏi, đặt lịch gọi.', 'due_days' => 1, 'priority' => 'medium' ),
		'no_next' => array( 'segment' => 'no_next', 'title' => 'Đặt bước tiếp theo cho khách', 'label' => 'Khách chưa có bước tiếp', 'instructions' => 'Liên hệ lại và ghi kết quả để hệ thống đặt nhắc.', 'due_days' => 1, 'priority' => 'medium' ),
		'aftercare' => array( 'segment' => 'won_d3', 'title' => 'Hỏi trải nghiệm sau mua', 'label' => 'Chăm sau mua D+3', 'instructions' => 'Hỏi trải nghiệm, xin ảnh feedback, giới thiệu gói mua lại.', 'due_days' => 1, 'priority' => 'medium' ),
		'retouch' => array( 'segment' => 'dormant', 'title' => 'Tái chạm khách nguội', 'label' => 'Tái chạm khách nguội', 'instructions' => 'Gửi ưu đãi mua lại, hỏi thăm nhu cầu mới.', 'due_days' => 3, 'priority' => 'medium' ),
		'rebuy' => array( 'segment' => 'repeat_d30', 'title' => 'Mời mua lại', 'label' => 'Mời mua lại D+30', 'instructions' => 'Nhắc chu kỳ dùng sản phẩm, gửi mã ưu đãi.', 'due_days' => 3, 'priority' => 'low' ),
		'stuck' => array( 'segment' => 'stuck', 'title' => 'Gỡ khách đang kẹt', 'label' => 'Mọi khách đang kẹt', 'instructions' => 'Xem lại hội thoại và bước còn thiếu, liên hệ khách, ghi kết quả.', 'due_days' => 1, 'priority' => 'medium' ),
	);

	/** Member transitions: from => [to...]. */
	const MEMBER_TRANSITIONS = array(
		'sent'        => array( 'accepted', 'in_progress', 'returned' ),
		'accepted'    => array( 'in_progress', 'done', 'returned' ),
		'in_progress' => array( 'done', 'returned' ),
	);

	// ── Scope helpers ────────────────────────────────────────────────────

	/**
	 * The subject's OWN inbox scope (never widened by admin or team rank) —
	 * exact-owner Zalo Personal + business inbox membership.
	 *
	 * @return int[]
	 */
	public static function user_inbox_ids( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return array(); }
		$scope = BizCity_CRM_Inbox_Access::resolve_scope( $user_id, 'be', true );
		return array_values( array_unique( array_map( 'intval', (array) ( $scope['inbox_ids'] ?? array() ) ) ) );
	}

	public static function contact_in_inboxes( int $contact_id, array $inbox_ids ): bool {
		if ( $contact_id <= 0 || empty( $inbox_ids ) ) { return false; }
		global $wpdb;
		$ci = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ph = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM `{$ci}` WHERE contact_id = %d AND inbox_id IN ({$ph}) LIMIT 1", array_merge( array( $contact_id ), array_map( 'intval', $inbox_ids ) ) ) );
	}

	/**
	 * Most recent conversation of a contact inside the given inbox ids.
	 *
	 * @return array{id:int,inbox_id:int}|null
	 */
	public static function latest_conversation_in_inboxes( int $contact_id, array $inbox_ids ): ?array {
		if ( $contact_id <= 0 || empty( $inbox_ids ) ) { return null; }
		global $wpdb;
		$conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ph   = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
		$row  = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.inbox_id FROM `{$conv}` c WHERE c.inbox_id IN ({$ph}) AND ( c.contact_id = %d OR c.contact_inbox_id IN ( SELECT id FROM `{$ci}` WHERE contact_id = %d ) ) ORDER BY c.last_activity_at DESC, c.id DESC LIMIT 1",
			array_merge( array_map( 'intval', $inbox_ids ), array( $contact_id, $contact_id ) )
		), ARRAY_A );
		return $row ? array( 'id' => (int) $row['id'], 'inbox_id' => (int) $row['inbox_id'] ) : null;
	}

	// ── Status vocabulary ────────────────────────────────────────────────

	/** A task is a handoff when someone assigned it to a different user. */
	public static function is_handoff( array $row ): bool {
		$assignee = (int) ( $row['assignee_id'] ?? 0 );
		$creator  = (int) ( $row['created_by'] ?? 0 );
		return $assignee > 0 && $creator > 0 && $assignee !== $creator;
	}

	/** Read-side mapping for legacy task rows (contract §1.6). */
	public static function normalize_status( array $row ): string {
		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( in_array( $status, self::STATUSES, true ) ) { return $status; }
		if ( ! empty( $row['completed'] ) || in_array( $status, array( 'completed', 'closed' ), true ) ) { return self::STATUS_DONE; }
		// TasksTab kanban (PHASE-0.40 G6.2) columns that mean "work has started".
		if ( in_array( $status, array( 'review', 'blocked' ), true ) ) { return self::STATUS_IN_PROGRESS; }
		return self::STATUS_SENT; // legacy `open`/`todo`/unknown.
	}

	public static function is_overdue( array $row ): bool {
		$due = (string) ( $row['due_date'] ?? '' );
		if ( $due === '' ) { return false; }
		$status = self::normalize_status( $row );
		return ! in_array( $status, array( self::STATUS_DONE, self::STATUS_CANCELLED ), true ) && $due < current_time( 'Y-m-d' );
	}

	// ── Create (leader) ──────────────────────────────────────────────────

	/**
	 * @param array $payload {client_request_id, assignee_user_id, title, instructions, priority, due_date,
	 *                        contact_ids[], conversation_id, out_of_scope_policy: reject|strip, playbook}
	 * @return array|WP_Error
	 */
	public static function create( int $actor_id, array $payload ) {
		$assignee_id = (int) ( $payload['assignee_user_id'] ?? 0 );
		if ( $actor_id <= 0 ) { return self::error( 'auth_required', 'Cần đăng nhập.', 401 ); }
		if ( $assignee_id <= 0 || ! BizCity_CRM_Staff_Policy::is_assignable_user( $assignee_id ) ) {
			return self::error( 'assignee_invalid', 'Người nhận việc không hợp lệ.', 422 );
		}
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'task.assign', $assignee_id );
		if ( ! $decision['ok'] ) {
			return self::error( 'member_not_manageable', $decision['why'] ?: 'Bạn không quản lý nhân viên này.', 403 );
		}

		$title = trim( sanitize_text_field( (string) ( $payload['title'] ?? '' ) ) );
		if ( $title === '' ) { return self::error( 'title_required', 'Tiêu đề việc không được để trống.', 422 ); }
		$title = mb_substr( $title, 0, 180 );
		$instructions = mb_substr( trim( sanitize_textarea_field( (string) ( $payload['instructions'] ?? '' ) ) ), 0, 2000 );
		$priority = sanitize_key( (string) ( $payload['priority'] ?? 'medium' ) );
		if ( ! in_array( $priority, self::PRIORITIES, true ) ) { $priority = 'medium'; }
		$due_date = self::sanitize_date( (string) ( $payload['due_date'] ?? '' ) );
		if ( $due_date !== null && $due_date < current_time( 'Y-m-d' ) ) {
			return self::error( 'due_in_past', 'Hạn đã qua.', 422 );
		}
		$policy = 'strip' === ( $payload['out_of_scope_policy'] ?? '' ) ? 'strip' : 'reject';
		$playbook = sanitize_key( (string) ( $payload['playbook'] ?? '' ) );

		$request_key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $payload['client_request_id'] ?? '' ) );
		$idem_key = $request_key !== '' ? 'bzc_handoff_' . md5( get_current_blog_id() . '|' . $actor_id . '|' . $request_key ) : '';
		if ( $idem_key !== '' ) {
			$previous = get_transient( $idem_key );
			if ( is_array( $previous ) ) { return array_merge( $previous, array( 'duplicate' => true ) ); }
		}

		// Subjects: contacts, one conversation, or none.
		$subjects = array();
		$stripped = array();
		$assignee_inboxes = self::user_inbox_ids( $assignee_id );
		$conversation_id = (int) ( $payload['conversation_id'] ?? 0 );
		$contact_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $payload['contact_ids'] ?? array() ) ) ) ) );

		if ( $conversation_id > 0 ) {
			$conv = class_exists( 'BizCity_CRM_Repository' ) ? BizCity_CRM_Repository::get_conversation( $conversation_id ) : null;
			// The leader must see it, and the assignee must be able to open it.
			if ( ! $conv || ! BizCity_CRM_Inbox_Access::can_view_conversation( $conversation_id, $actor_id ) ) {
				return self::error( 'conversation_not_found', 'Không tìm thấy hội thoại.', 404 );
			}
			if ( ! in_array( (int) $conv['inbox_id'], $assignee_inboxes, true ) ) {
				return self::error( 'task_subject_out_of_scope', 'Hội thoại không thuộc kênh của người nhận. Chuyển phụ trách trước.', 422 );
			}
			$subjects[] = array( 'type' => 'conversation', 'id' => $conversation_id );
		} elseif ( ! empty( $contact_ids ) ) {
			if ( count( $contact_ids ) > self::MAX_SUBJECTS ) {
				return self::error( 'too_many_subjects', 'Tối đa ' . self::MAX_SUBJECTS . ' khách mỗi lần giao.', 422 );
			}
			$actor_inboxes = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $actor_id ); // null = tenant admin.
			foreach ( $contact_ids as $contact_id ) {
				$actor_sees = null === $actor_inboxes || self::contact_in_inboxes( $contact_id, (array) $actor_inboxes );
				if ( ! $actor_sees ) {
					$stripped[] = array( 'contact_id' => $contact_id, 'reason' => 'contact_not_in_scope' );
					continue;
				}
				if ( ! self::contact_in_inboxes( $contact_id, $assignee_inboxes ) ) {
					$stripped[] = array( 'contact_id' => $contact_id, 'reason' => 'task_subject_out_of_scope' );
					continue;
				}
				$subjects[] = array( 'type' => 'contact', 'id' => $contact_id );
			}
			if ( ! empty( $stripped ) && 'reject' === $policy ) {
				return self::error( 'task_subject_out_of_scope', count( $stripped ) . ' khách không thuộc kênh của người nhận.', 422, array( 'stripped' => $stripped ) );
			}
			if ( empty( $subjects ) ) {
				return self::error( 'task_subject_out_of_scope', 'Không còn khách nào thuộc kênh của người nhận.', 422, array( 'stripped' => $stripped ) );
			}
		} else {
			$subjects[] = array( 'type' => '', 'id' => 0 );
		}

		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$now = current_time( 'mysql' );
		$batch_key = count( $subjects ) > 1 ? substr( md5( $actor_id . '|' . $now . '|' . wp_rand() ), 0, 16 ) : '';
		$created = array();
		foreach ( $subjects as $subject ) {
			$ok = $wpdb->insert( $tbl, array(
				'title'               => $title,
				'status'              => self::STATUS_SENT,
				'priority'            => $priority,
				'due_date'            => $due_date,
				'assignee_id'         => $assignee_id,
				'related_entity_type' => $subject['type'] !== '' ? $subject['type'] : null,
				'related_entity_id'   => $subject['id'] > 0 ? $subject['id'] : null,
				'notes'               => $instructions !== '' ? $instructions : null,
				'completed'           => 0,
				'created_by'          => $actor_id,
				'created_at'          => $now,
				'updated_at'          => $now,
			) );
			if ( ! $ok ) { continue; }
			$task_id = (int) $wpdb->insert_id;
			$created[] = $task_id;
			// PHASE-0.52 §16.2 gap — `playbook` also lands in the audit row (not just the POST response)
			// so `list_batches()` below can read it back after the page reloads.
			self::audit( $task_id, 'handoff_sent', $actor_id, null, array( 'to' => self::STATUS_SENT, 'assignee_id' => $assignee_id, 'batch_key' => $batch_key, 'playbook' => $playbook !== '' ? $playbook : null ) );
			/** [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 C-04 — per-task lifecycle hook for metric rollups (ids and dates only). */
			do_action( 'bizcity_crm_task_handoff_transition', $task_id, '', self::STATUS_SENT, $assignee_id, array( 'due_date' => $due_date, 'at' => $now ) );
		}
		if ( empty( $created ) ) { return self::error( 'insert_failed', 'Không tạo được việc.', 500 ); }

		/**
		 * Assigned-work notification hook (Zone 2 transports may listen, e.g. Zalo Bot).
		 * Carries no customer PII — only ids and counts.
		 */
		do_action( 'bizcity_crm_task_handoff_assigned', $assignee_id, count( $created ), $actor_id, $due_date );

		$result = array(
			'contract'  => self::CONTRACT . '@' . self::VERSION,
			'batch_key' => $batch_key !== '' ? $batch_key : null,
			// PHASE-0.52 P52-C-04 — lets L2 (TaskPlanPanel) show a batch's own total/done instead of only the
			// in-session list; `done` starts at 0 because every created task starts STATUS_SENT.
			'batch'     => $batch_key !== '' ? array(
				'key'      => $batch_key,
				'playbook' => $playbook !== '' ? $playbook : null,
				'total'    => count( $created ) + count( $stripped ),
				'done'     => 0,
			) : null,
			'created'   => $created,
			'stripped'  => $stripped,
		);
		if ( $idem_key !== '' ) { set_transient( $idem_key, $result, self::IDEMPOTENCY_TTL ); }
		return $result;
	}

	// ── Leader actions ───────────────────────────────────────────────────

	/**
	 * cancel | reassign | reopen.
	 *
	 * @return array|WP_Error shaped B2 task
	 */
	public static function leader_action( int $actor_id, int $task_id, string $action, int $new_assignee_id = 0, string $reason = '' ) {
		$row = self::get_row( $task_id );
		if ( ! $row || ! self::leader_can_see( $actor_id, $row ) ) { return self::error( 'task_not_found', 'Không tìm thấy việc.', 404 ); }
		$current_assignee = (int) $row['assignee_id'];
		$decision = BizCity_CRM_Staff_Policy::can( $actor_id, 'task.assign', $current_assignee );
		if ( ! $decision['ok'] ) { return self::error( 'member_not_manageable', $decision['why'], 403 ); }
		$from = self::normalize_status( $row );
		$fields = array( 'updated_at' => current_time( 'mysql' ) );

		if ( 'cancel' === $action ) {
			if ( in_array( $from, array( self::STATUS_DONE, self::STATUS_CANCELLED ), true ) ) {
				return self::error( 'task_transition_invalid', 'Việc đã xong hoặc đã huỷ.', 409 );
			}
			$fields['status'] = self::STATUS_CANCELLED;
		} elseif ( 'reopen' === $action ) {
			if ( ! in_array( $from, array( self::STATUS_DONE, self::STATUS_RETURNED, self::STATUS_CANCELLED ), true ) ) {
				return self::error( 'task_transition_invalid', 'Chỉ mở lại việc đã xong, bị trả lại hoặc đã huỷ.', 409 );
			}
			$fields['status'] = self::STATUS_SENT;
			$fields['completed'] = 0;
			$fields['completed_at'] = null;
		} elseif ( 'reassign' === $action ) {
			if ( in_array( $from, array( self::STATUS_DONE, self::STATUS_CANCELLED ), true ) ) {
				return self::error( 'task_transition_invalid', 'Mở lại việc trước khi chuyển người.', 409 );
			}
			if ( $new_assignee_id <= 0 || $new_assignee_id === $current_assignee || ! BizCity_CRM_Staff_Policy::is_assignable_user( $new_assignee_id ) ) {
				return self::error( 'assignee_invalid', 'Người nhận mới không hợp lệ.', 422 );
			}
			$decision_new = BizCity_CRM_Staff_Policy::can( $actor_id, 'task.assign', $new_assignee_id );
			if ( ! $decision_new['ok'] ) { return self::error( 'member_not_manageable', $decision_new['why'], 403 ); }
			$subject_type = (string) ( $row['related_entity_type'] ?? '' );
			$subject_id   = (int) ( $row['related_entity_id'] ?? 0 );
			$new_inboxes  = self::user_inbox_ids( $new_assignee_id );
			if ( 'contact' === $subject_type && $subject_id > 0 && ! self::contact_in_inboxes( $subject_id, $new_inboxes ) ) {
				return self::error( 'task_subject_out_of_scope', 'Khách không thuộc kênh của người nhận mới.', 422 );
			}
			if ( 'conversation' === $subject_type && $subject_id > 0 ) {
				$conv = BizCity_CRM_Repository::get_conversation( $subject_id );
				if ( ! $conv || ! in_array( (int) $conv['inbox_id'], $new_inboxes, true ) ) {
					return self::error( 'task_subject_out_of_scope', 'Hội thoại không thuộc kênh của người nhận mới.', 422 );
				}
			}
			$fields['assignee_id'] = $new_assignee_id;
			$fields['status'] = self::STATUS_SENT;
		} elseif ( 'review' === $action ) {
			if ( self::STATUS_DONE !== $from ) {
				return self::error( 'task_transition_invalid', 'Chỉ đánh giá việc đã xong.', 409 );
			}
			$verdict_check = sanitize_key( (string) ( explode( '|', $reason )[0] ?? '' ) );
			if ( ! in_array( $verdict_check, array( 'accepted', 'needs_rework' ), true ) ) {
				return self::error( 'review_verdict_invalid', 'Chọn "Đạt" hoặc "Làm lại".', 422 );
			}
			// §5.7: needs_rework = reopen kèm lý do (nhận xét đã lưu qua audit bên dưới).
			if ( 'needs_rework' === $verdict_check ) {
				$fields['status'] = self::STATUS_SENT;
				$fields['completed'] = 0;
				$fields['completed_at'] = null;
			}
		} else {
			return self::error( 'invalid_action', 'Thao tác không hợp lệ.', 422 );
		}

		global $wpdb;
		// 'review'+accepted only writes the audit trail (no status change); 'review'+needs_rework
		// also reopens the task (fields['status'] set above), same as every other branch.
		if ( 'review' !== $action || isset( $fields['status'] ) ) {
			$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(), $fields, array( 'id' => $task_id ) );
		}
		self::audit( $task_id, 'review' === $action ? 'task_reviewed' : ( 'handoff_' . $action ), $actor_id, array( 'from' => $from, 'assignee_id' => $current_assignee ), array(
			'to'          => $fields['status'] ?? $from,
			'assignee_id' => $fields['assignee_id'] ?? $current_assignee,
			'reason'      => mb_substr( sanitize_text_field( $reason ), 0, 300 ),
			'verdict'     => 'review' === $action ? sanitize_key( (string) ( explode( '|', $reason )[0] ?? '' ) ) : null, // Format: "verdict|comment"
			'comment'     => 'review' === $action ? mb_substr( sanitize_textarea_field( (string) ( implode( '|', array_slice( explode( '|', $reason ), 1 ) ) ) ), 0, 1000 ) : null,
		) );
		if ( 'reassign' === $action ) {
			do_action( 'bizcity_crm_task_handoff_assigned', $new_assignee_id, 1, $actor_id, $row['due_date'] ?? null );
		}
		if ( 'review' === $action ) {
			// [2026-09-19] PHASE-0.55 A5 — `task_reviewed` event for automation (§5.6); counts/ids only, no customer data.
			do_action( 'bizcity_crm_task_reviewed', $task_id, $current_assignee, $actor_id, sanitize_key( (string) ( explode( '|', $reason )[0] ?? '' ) ) );
		}
		return self::shape_b2( self::get_row( $task_id ), $actor_id );
	}

	// ── Member transitions (C and B2 self) ───────────────────────────────

	/**
	 * @return array|WP_Error shaped C task
	 */
	public static function member_transition( int $member_id, int $task_id, string $to, string $reason_code = '', string $note = '' ) {
		$row = self::get_row( $task_id );
		// Not-found and not-mine are indistinguishable (contract §0.3).
		if ( ! $row || (int) $row['assignee_id'] !== $member_id || $member_id <= 0 ) {
			return self::error( 'task_not_found', 'Không tìm thấy việc.', 404 );
		}
		$from = self::normalize_status( $row );
		$to   = sanitize_key( $to );
		if ( ! in_array( $to, self::MEMBER_TRANSITIONS[ $from ] ?? array(), true ) ) {
			return self::error( 'task_transition_invalid', 'Không thể chuyển việc sang trạng thái này.', 409 );
		}
		$note = trim( sanitize_textarea_field( $note ) );
		if ( mb_strlen( $note ) > 2000 ) { return self::error( 'content_too_long', 'Ghi chú tối đa 2000 ký tự.', 422 ); }
		$reason_code = sanitize_key( $reason_code );
		if ( self::STATUS_RETURNED === $to ) {
			if ( ! in_array( $reason_code, self::RETURN_REASONS, true ) ) {
				return self::error( 'reason_required', 'Chọn lý do trả lại việc.', 422 );
			}
		} else {
			$reason_code = '';
		}

		$fields = array( 'status' => $to, 'updated_at' => current_time( 'mysql' ) );
		if ( self::STATUS_DONE === $to ) {
			$fields['completed'] = 1;
			$fields['completed_at'] = current_time( 'mysql' );
		}

		$note_ref = null;
		if ( $note !== '' ) {
			$note_ref = self::write_result_note( $member_id, $row, $note, $to );
		}

		global $wpdb;
		$wpdb->update( BizCity_CRM_DB_Installer_V2::tbl_crm_tasks(), $fields, array( 'id' => $task_id ) );
		self::audit( $task_id, 'handoff_' . $to, $member_id, array( 'from' => $from ), array(
			'to'          => $to,
			'reason_code' => $reason_code,
			'note'        => $note !== '' ? mb_substr( $note, 0, 300 ) : '',
			'note_ref'    => $note_ref,
		) );
		self::mark_seen( $member_id, $task_id );
		do_action( 'bizcity_crm_task_handoff_transition', $task_id, $from, $to, $member_id, array( 'due_date' => $row['due_date'] ?? null, 'at' => $fields['updated_at'] ) );
		return self::shape_c( self::get_row( $task_id ), $member_id );
	}

	/**
	 * Result notes go to the contact's private note owner (0.48B §8.12) when the
	 * member can open a conversation for that subject; otherwise audit only.
	 */
	private static function write_result_note( int $member_id, array $row, string $note, string $to ): ?int {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) { return null; }
		$inboxes = self::user_inbox_ids( $member_id );
		$conv = null;
		$type = (string) ( $row['related_entity_type'] ?? '' );
		$id   = (int) ( $row['related_entity_id'] ?? 0 );
		if ( 'conversation' === $type && $id > 0 ) {
			$c = BizCity_CRM_Repository::get_conversation( $id );
			if ( $c && in_array( (int) $c['inbox_id'], $inboxes, true ) ) { $conv = array( 'id' => $id, 'inbox_id' => (int) $c['inbox_id'] ); }
		} elseif ( 'contact' === $type && $id > 0 ) {
			$conv = self::latest_conversation_in_inboxes( $id, $inboxes );
		}
		if ( ! $conv ) { return null; }
		$label = self::STATUS_RETURNED === $to ? 'Trả lại việc' : 'Kết quả việc';
		$msg_id = BizCity_CRM_Repository::insert_message( array(
			'conversation_id'   => $conv['id'],
			'inbox_id'          => $conv['inbox_id'],
			'content'           => $label . ' "' . (string) $row['title'] . '": ' . $note,
			'content_type'      => 'text',
			'message_type'      => 'private_note',
			'sender_type'       => 'agent',
			'sender_id'         => $member_id,
			'status'            => 'note',
			'responder_kind'    => 'manual',
			'responder_user_id' => $member_id,
		) );
		return $msg_id ? (int) $msg_id : null;
	}

	public static function mark_seen( int $member_id, int $task_id ): void {
		if ( $member_id <= 0 || $task_id <= 0 ) { return; }
		$seen = self::seen_task_ids( $member_id, array( $task_id ) );
		if ( in_array( $task_id, $seen, true ) ) { return; }
		self::audit( $task_id, 'handoff_seen', $member_id, null, null );
	}

	/** @return int[] */
	public static function seen_task_ids( int $member_id, array $task_ids ): array {
		$task_ids = array_values( array_filter( array_map( 'intval', $task_ids ) ) );
		if ( $member_id <= 0 || empty( $task_ids ) || ! self::audit_ready() ) { return array(); }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$ph  = implode( ',', array_fill( 0, count( $task_ids ), '%d' ) );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT entity_id FROM `{$tbl}` WHERE entity_type = %s AND action = 'handoff_seen' AND user_id = %d AND entity_id IN ({$ph})",
			array_merge( array( self::AUDIT_ENTITY, $member_id ), $task_ids )
		) );
		return array_map( 'intval', (array) $ids );
	}

	// ── Reads ────────────────────────────────────────────────────────────

	public static function get_row( int $task_id ): ?array {
		if ( $task_id <= 0 ) { return null; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$tbl}` WHERE id = %d AND deleted_at IS NULL", $task_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** A leader sees a task when they created it, own it, or manage its assignee. */
	public static function leader_can_see( int $actor_id, array $row ): bool {
		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		if ( null === $visible ) { return true; }
		return (int) $row['created_by'] === $actor_id || in_array( (int) $row['assignee_id'], $visible, true );
	}

	/**
	 * Member's own handoff tasks (C "Việc được giao").
	 *
	 * @param string $filter open|today|overdue|done|all
	 */
	public static function list_for_member( int $member_id, string $filter = 'open', int $limit = 100, int $contact_id = 0 ): array {
		if ( $member_id <= 0 ) { return array(); }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$where = array( 'deleted_at IS NULL', $wpdb->prepare( 'assignee_id = %d', $member_id ), $wpdb->prepare( '(created_by IS NOT NULL AND created_by <> %d)', $member_id ) );
		$where = array_merge( $where, self::status_filter_sql( $filter ) );
		if ( $contact_id > 0 ) { $where[] = $wpdb->prepare( "related_entity_type = 'contact' AND related_entity_id = %d", $contact_id ); }
		$limit = max( 1, min( 200, $limit ) );
		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . " ORDER BY (due_date IS NULL) ASC, due_date ASC, id DESC LIMIT {$limit}", ARRAY_A );
		$seen = self::seen_task_ids( $member_id, array_column( $rows, 'id' ) );
		$out = array();
		foreach ( $rows as $row ) { $out[] = self::shape_c( $row, $member_id, $seen ); }
		return $out;
	}

	public static function count_unseen_for_member( int $member_id ): int {
		if ( $member_id <= 0 ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE deleted_at IS NULL AND assignee_id = %d AND created_by IS NOT NULL AND created_by <> %d AND status IN ('sent','open') LIMIT 200", $member_id, $member_id ) );
		$ids = array_map( 'intval', (array) $ids );
		return max( 0, count( $ids ) - count( self::seen_task_ids( $member_id, $ids ) ) );
	}

	/**
	 * Leader board: handoff tasks created by the actor or assigned to a user the actor manages.
	 *
	 * @param array $filters {member_id, status, due: today|week|overdue|all, contact_id, team_id}
	 */
	public static function board( int $actor_id, array $filters = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$where = array( 'deleted_at IS NULL', 'assignee_id IS NOT NULL', 'created_by IS NOT NULL', 'assignee_id <> created_by' );
		$visible = BizCity_CRM_Staff_Policy::visible_user_ids( $actor_id );
		if ( null !== $visible ) {
			$visible = array_values( array_filter( array_map( 'intval', $visible ) ) );
			$ph = $visible ? implode( ',', array_fill( 0, count( $visible ), '%d' ) ) : '0';
			$where[] = $visible
				? $wpdb->prepare( "(created_by = %d OR assignee_id IN ({$ph}))", array_merge( array( $actor_id ), $visible ) )
				: $wpdb->prepare( 'created_by = %d', $actor_id );
		}
		$member_id = (int) ( $filters['member_id'] ?? 0 );
		if ( $member_id > 0 ) { $where[] = $wpdb->prepare( 'assignee_id = %d', $member_id ); }
		$contact_id = (int) ( $filters['contact_id'] ?? 0 );
		if ( $contact_id > 0 ) { $where[] = $wpdb->prepare( "related_entity_type = 'contact' AND related_entity_id = %d", $contact_id ); }
		$due = sanitize_key( (string) ( $filters['due'] ?? 'all' ) );
		$today = current_time( 'Y-m-d' );
		if ( 'today' === $due ) { $where[] = $wpdb->prepare( 'due_date = %s', $today ); }
		if ( 'week' === $due ) { $where[] = $wpdb->prepare( 'due_date BETWEEN %s AND %s', $today, gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) ); }
		if ( 'overdue' === $due ) { $where[] = $wpdb->prepare( "due_date < %s AND status NOT IN ('done','cancelled')", $today ); }
		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT 300', ARRAY_A );
		$status_filter = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		// Team is a display filter on the assignee's own team, same convention as the roster (`get_roster()`);
		// it never widens `$visible` above, so a non-manageable team_id just yields an empty board, not an error.
		$team_filter = max( 0, (int) ( $filters['team_id'] ?? 0 ) );
		$out = array();
		foreach ( $rows as $row ) {
			if ( $team_filter > 0 && class_exists( 'BizCity_CRM_Staff_Policy' )
				&& BizCity_CRM_Staff_Policy::primary_team( (int) ( $row['assignee_id'] ?? 0 ) ) !== $team_filter ) {
				continue;
			}
			$shaped = self::shape_b2( $row, $actor_id, false );
			if ( $status_filter !== '' && $shaped['status'] !== $status_filter ) { continue; }
			$out[] = $shaped;
		}
		$columns = array();
		foreach ( self::STATUSES as $status ) { $columns[ $status ] = 0; }
		$overdue = 0;
		foreach ( $out as $task ) {
			$columns[ $task['status'] ] = ( $columns[ $task['status'] ] ?? 0 ) + 1;
			if ( $task['overdue'] ) { $overdue++; }
		}
		return array( 'tasks' => $out, 'counts' => $columns, 'overdue' => $overdue );
	}

	/**
	 * PHASE-0.52 §16.2 gap — L2's "Các đợt đã giao" only showed batches from the current
	 * page session (lost on reload). Reads the same `batch_key`/`playbook` already written
	 * to the `handoff_sent` audit row at {@see create()} — no new table/column — and joins
	 * back to `bizcity_crm_tasks` for a live `total`/`done` count (same shape as the `data.batch`
	 * on the POST response, P52-C-04).
	 *
	 * @return array<int,array{key:string,playbook:?string,title:?string,total:int,done:int,created_at:string}>
	 */
	public static function list_batches( int $actor_id, int $limit = 20 ): array {
		if ( $actor_id <= 0 || ! self::audit_ready() ) { return array(); }
		$limit = max( 1, min( 50, $limit ) );
		global $wpdb;
		$audit_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		// Over-fetch: one batch can hold up to MAX_SUBJECTS (200) audit rows, so scan enough
		// recent `handoff_sent` rows to cover several distinct batches, newest first.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT entity_id, after_json, created_at FROM `{$audit_tbl}` WHERE entity_type = %s AND action = 'handoff_sent' AND user_id = %d ORDER BY id DESC LIMIT 4000",
			self::AUDIT_ENTITY, $actor_id
		), ARRAY_A );

		$batches = array(); // batch_key => { playbook, created_at, task_ids[] }
		foreach ( $rows as $row ) {
			$after = json_decode( (string) $row['after_json'], true );
			$batch_key = is_array( $after ) ? sanitize_key( (string) ( $after['batch_key'] ?? '' ) ) : '';
			if ( '' === $batch_key ) { continue; } // a single-customer handoff has no batch_key — not a "batch".
			if ( ! isset( $batches[ $batch_key ] ) ) {
				if ( count( $batches ) >= $limit ) { continue; } // already holding the newest $limit distinct batches.
				$batches[ $batch_key ] = array(
					'playbook'   => ( is_array( $after ) && ! empty( $after['playbook'] ) ) ? sanitize_key( (string) $after['playbook'] ) : null,
					'created_at' => (string) $row['created_at'],
					'task_ids'   => array(),
				);
			}
			$batches[ $batch_key ]['task_ids'][] = (int) $row['entity_id'];
		}
		if ( empty( $batches ) ) { return array(); }

		$all_task_ids = array();
		foreach ( $batches as $batch ) { $all_task_ids = array_merge( $all_task_ids, $batch['task_ids'] ); }
		$all_task_ids = array_values( array_unique( $all_task_ids ) );
		$tasks_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$ph = implode( ',', array_fill( 0, count( $all_task_ids ), '%d' ) );
		$task_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status, title FROM `{$tasks_tbl}` WHERE id IN ({$ph})", $all_task_ids ), ARRAY_A );
		$status_by_id = array();
		$title_by_id  = array();
		foreach ( $task_rows as $task_row ) {
			$status_by_id[ (int) $task_row['id'] ] = self::normalize_status( $task_row );
			$title_by_id[ (int) $task_row['id'] ]  = (string) $task_row['title'];
		}

		$out = array();
		foreach ( $batches as $batch_key => $batch ) {
			$done  = 0;
			$title = null;
			foreach ( $batch['task_ids'] as $task_id ) {
				if ( self::STATUS_DONE === ( $status_by_id[ $task_id ] ?? '' ) ) { $done++; }
				if ( null === $title && isset( $title_by_id[ $task_id ] ) ) { $title = $title_by_id[ $task_id ]; }
			}
			$out[] = array(
				'key'        => $batch_key,
				'playbook'   => $batch['playbook'],
				'title'      => $title,
				'total'      => count( $batch['task_ids'] ),
				'done'       => $done,
				'created_at' => $batch['created_at'],
			);
		}
		return $out;
	}

	private static function status_filter_sql( string $filter ): array {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		switch ( sanitize_key( $filter ) ) {
			case 'done':
				return array( "status IN ('done')" );
			case 'today':
				return array( "status NOT IN ('done','cancelled')", $wpdb->prepare( 'due_date = %s', $today ) );
			case 'overdue':
				return array( "status NOT IN ('done','cancelled')", $wpdb->prepare( 'due_date < %s', $today ) );
			case 'all':
				return array( "status <> 'cancelled'" );
			default:
				return array( "status NOT IN ('done','cancelled','returned')" );
		}
	}

	// ── Shapes ───────────────────────────────────────────────────────────

	public static function shape_b2( ?array $row, int $actor_id, bool $with_timeline = true ): ?array {
		if ( ! $row ) { return null; }
		$status = self::normalize_status( $row );
		$assignee_id = (int) ( $row['assignee_id'] ?? 0 );
		$creator_id  = (int) ( $row['created_by'] ?? 0 );
		$subject = self::subject_summary( $row, null );
		$can = array();
		if ( BizCity_CRM_Staff_Policy::can( $actor_id, 'task.assign', $assignee_id )['ok'] ) {
			if ( ! in_array( $status, array( self::STATUS_DONE, self::STATUS_CANCELLED ), true ) ) { $can[] = 'cancel'; $can[] = 'reassign'; }
			if ( in_array( $status, array( self::STATUS_DONE, self::STATUS_RETURNED, self::STATUS_CANCELLED ), true ) ) { $can[] = 'reopen'; }
		}
		$out = array(
			'task_id'      => (int) $row['id'],
			'title'        => (string) $row['title'],
			'instructions' => (string) ( $row['notes'] ?? '' ),
			'priority'     => (string) $row['priority'],
			'due_at'       => $row['due_date'] ?: null,
			'status'       => $status,
			'overdue'      => self::is_overdue( $row ),
			'assigned_by'  => self::user_ref( $creator_id, false ),
			'assignee'     => self::user_ref( $assignee_id, true ),
			'subjects'     => $subject ? array( $subject ) : array(),
			'progress'     => array( 'touched' => ( $subject && ! empty( $subject['touched'] ) ) ? 1 : 0, 'total' => $subject ? 1 : 0 ),
			'created_at'   => $row['created_at'],
			'updated_at'   => $row['updated_at'],
			'can'          => $can,
		);
		$out += self::pipeline_fields( $row );
		if ( $with_timeline ) {
			$out['timeline'] = self::timeline( (int) $row['id'] );
			$out['result']   = self::last_result( $out['timeline'] );
		}
		return $out;
	}

	public static function shape_c( ?array $row, int $member_id, ?array $seen_ids = null ): ?array {
		if ( ! $row || (int) $row['assignee_id'] !== $member_id ) { return null; }
		$status = self::normalize_status( $row );
		$playbook_id = self::playbook_id_from_task( $row );
		$inboxes = self::user_inbox_ids( $member_id );
		$subject = self::subject_summary( $row, $inboxes );
		$seen_ids = null === $seen_ids ? self::seen_task_ids( $member_id, array( (int) $row['id'] ) ) : $seen_ids;
		$creator = get_userdata( (int) $row['created_by'] );
		$review = self::last_review( (int) $row['id'] );
		return self::pipeline_fields( $row ) + array(
			'task_id'      => (int) $row['id'],
			'title'        => (string) $row['title'],
			'instructions' => (string) ( $row['notes'] ?? '' ),
			'priority'     => (string) $row['priority'],
			'due_at'       => $row['due_date'] ?: null,
			'status'       => $status,
			'overdue'      => self::is_overdue( $row ),
			'assigned_by'  => array( 'display_name' => $creator ? (string) $creator->display_name : '' ),
			'subjects'     => $subject ? array( $subject ) : array(),
			'progress'     => array( 'touched' => ( $subject && ! empty( $subject['touched'] ) ) ? 1 : 0, 'total' => $subject ? 1 : 0 ),
			'seen'         => in_array( (int) $row['id'], $seen_ids, true ),
			'created_at'   => $row['created_at'],
			'can'          => array_map( static function ( $to ) {
				return array( 'accepted' => 'accept', 'in_progress' => 'start', 'done' => 'complete', 'returned' => 'return' )[ $to ];
			}, self::MEMBER_TRANSITIONS[ $status ] ?? array() ),
			'playbook'     => $playbook_id !== '' && isset( self::PLAYBOOKS[ $playbook_id ] )
				? array_merge( array( 'id' => $playbook_id ), self::PLAYBOOKS[ $playbook_id ] )
				: null,
			'review'       => $review, // §5.7 A6 — verdict + comment for member, not rating
		);
	}

	public static function playbook( string $id ): ?array {
		$id = sanitize_key( $id );
		return isset( self::PLAYBOOKS[ $id ] ) ? array_merge( array( 'id' => $id ), self::PLAYBOOKS[ $id ] ) : null;
	}

	/**
	 * Return the canonical playbook catalog for the leader and member APIs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function playbooks(): array {
		$out = array();
		foreach ( self::PLAYBOOKS as $id => $playbook ) {
			$out[ $id ] = array_merge( array( 'id' => $id ), $playbook );
		}
		return $out;
	}

	private static function playbook_id_from_task( array $row ): string {
		if ( ! self::audit_ready() ) { return ''; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$after = $wpdb->get_var( $wpdb->prepare( "SELECT after_json FROM `{$tbl}` WHERE entity_type = %s AND entity_id = %d AND action = 'handoff_sent' ORDER BY id DESC LIMIT 1", self::AUDIT_ENTITY, (int) $row['id'] ) );
		$data = json_decode( (string) $after, true );
		return is_array( $data ) ? sanitize_key( (string) ( $data['playbook'] ?? '' ) ) : '';
	}

	/** @return array{verdict:string,comment:string,reviewed_by:array{display_name:string},at:string}|null — PHASE-0.55 A6 */
	private static function last_review( int $task_id ): ?array {
		if ( $task_id <= 0 || ! self::audit_ready() ) { return null; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT actor_id, after_json, created_at FROM `{$tbl}` WHERE entity_type = %s AND entity_id = %d AND action = 'task_reviewed' ORDER BY id DESC LIMIT 1",
			self::AUDIT_ENTITY, $task_id
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$data = json_decode( (string) $row['after_json'], true );
		if ( ! is_array( $data ) ) { return null; }
		$reviewer = get_userdata( (int) $row['actor_id'] );
		return array(
			'verdict'      => (string) ( $data['verdict'] ?? '' ), // 'accepted' or 'needs_rework'
			'comment'      => (string) ( $data['comment'] ?? '' ),
			'reviewed_by'  => array( 'display_name' => $reviewer ? (string) $reviewer->display_name : '' ),
			'at'           => (string) $row['created_at'],
		);
	}

	/**
	 * `pipeline_kind`/`stage_key`/`role_code`/`form_ref` — PHASE-0.71 F71-08 / 0.63C GC-02.
	 *
	 * `leader-task-handoff@1.2.0` declares these 4 fields (all nullable) on every task, but only a task
	 * a pipeline run spawned actually has them: `Pipeline_Run_Service::ensure_sub_step_tasks()` writes
	 * `pipeline_kind`/`stage_key`/`role_code` into `data_json` at creation, and
	 * `persist_step_evidence()` carries them forward across its own overwrite of the same column when a
	 * step closes with evidence. A leader-assigned task (no `data_json`, or `related_entity_type` other
	 * than `pipeline_run`) simply has none of the four — never guessed, never backfilled.
	 *
	 * @return array{pipeline_kind:?string,stage_key:?string,role_code:?string,form_ref:?string}
	 */
	private static function pipeline_fields( array $row ): array {
		$empty = array( 'pipeline_kind' => null, 'stage_key' => null, 'role_code' => null, 'form_ref' => null );
		if ( 'pipeline_run' !== (string) ( $row['related_entity_type'] ?? '' ) ) {
			return $empty;
		}
		$data = json_decode( (string) ( $row['data_json'] ?? '' ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}
		return array(
			'pipeline_kind' => isset( $data['pipeline_kind'] ) && '' !== $data['pipeline_kind'] ? (string) $data['pipeline_kind'] : null,
			'stage_key'     => isset( $data['stage_key'] ) && '' !== $data['stage_key'] ? (string) $data['stage_key'] : null,
			'role_code'     => isset( $data['role_code'] ) && '' !== $data['role_code'] ? (string) $data['role_code'] : null,
			'form_ref'      => isset( $data['form_ref'] ) && '' !== $data['form_ref'] ? (string) $data['form_ref'] : null,
		);
	}

	/**
	 * Subject summary. `$member_inboxes === null` → B2 (leader) shape; array → C shape
	 * with `can_open_thread` computed against the member's own inboxes and a masked phone.
	 */
	private static function subject_summary( array $row, ?array $member_inboxes ): ?array {
		$type = (string) ( $row['related_entity_type'] ?? '' );
		$id   = (int) ( $row['related_entity_id'] ?? 0 );
		if ( $id <= 0 || ! in_array( $type, array( 'contact', 'conversation' ), true ) ) { return null; }
		global $wpdb;
		$contact_id = 0;
		$conversation = null;
		if ( 'conversation' === $type ) {
			$c = class_exists( 'BizCity_CRM_Repository' ) ? BizCity_CRM_Repository::get_conversation( $id ) : null;
			if ( ! $c ) { return null; }
			$contact_id = (int) ( $c['contact_id'] ?? 0 );
			$conversation = array( 'id' => $id, 'inbox_id' => (int) $c['inbox_id'] );
		} else {
			$contact_id = $id;
			$assignee_inboxes = null === $member_inboxes ? self::user_inbox_ids( (int) $row['assignee_id'] ) : $member_inboxes;
			$conversation = self::latest_conversation_in_inboxes( $contact_id, $assignee_inboxes );
		}
		$contact = null;
		if ( $contact_id > 0 ) {
			$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$contact = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, phone FROM `{$tbl}` WHERE id = %d", $contact_id ), ARRAY_A );
		}
		$touched = false;
		if ( $conversation ) {
			$msg = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$touched = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$msg}` WHERE conversation_id = %d AND message_type = 'outgoing' AND responder_user_id = %d AND created_at >= %s LIMIT 1",
				$conversation['id'], (int) $row['assignee_id'], (string) $row['created_at']
			) );
		}
		$out = array(
			'kind'            => $type,
			'contact_id'      => $contact_id > 0 ? $contact_id : null,
			'conversation_id' => $conversation['id'] ?? null,
			'inbox_id'        => $conversation['inbox_id'] ?? null,
			'display_name'    => $contact ? sanitize_text_field( (string) $contact['name'] ) : '',
			'touched'         => $touched,
		);
		if ( null !== $member_inboxes ) {
			$out['can_open_thread'] = $conversation && in_array( (int) $conversation['inbox_id'], $member_inboxes, true );
			if ( ! $out['can_open_thread'] ) { $out['conversation_id'] = null; $out['inbox_id'] = null; }
			// `/gpt/crm/` addresses an inbox by `channel` + `ref` (same values the member already has in their URL).
			$out['channel'] = null;
			$out['ref'] = null;
			if ( $out['can_open_thread'] ) {
				$inbox_tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
				$inbox = $wpdb->get_row( $wpdb->prepare( "SELECT channel_type, channel_ref_id FROM `{$inbox_tbl}` WHERE id = %d", (int) $conversation['inbox_id'] ), ARRAY_A );
				if ( $inbox ) {
					$out['channel'] = sanitize_key( (string) $inbox['channel_type'] );
					$out['ref'] = (string) $inbox['channel_ref_id'];
				}
			}
			$out['phone_masked'] = $contact ? self::mask_phone( (string) $contact['phone'] ) : null;
		}
		return $out;
	}

	private static function timeline( int $task_id ): array {
		if ( ! self::audit_ready() ) { return array(); }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT action, before_json, after_json, user_id, created_at FROM `{$tbl}` WHERE entity_type = %s AND entity_id = %d AND action LIKE 'handoff\\_%%' ORDER BY id ASC LIMIT 50",
			self::AUDIT_ENTITY, $task_id
		), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) {
			$before = json_decode( (string) $r['before_json'], true );
			$after  = json_decode( (string) $r['after_json'], true );
			$out[] = array(
				'at'          => $r['created_at'],
				'action'      => substr( (string) $r['action'], 8 ),
				'actor'       => self::user_ref( (int) $r['user_id'], false ),
				'from'        => is_array( $before ) ? ( $before['from'] ?? null ) : null,
				'to'          => is_array( $after ) ? ( $after['to'] ?? null ) : null,
				'reason_code' => is_array( $after ) ? ( $after['reason_code'] ?? ( $after['reason'] ?? null ) ) : null,
				'note'        => is_array( $after ) ? ( $after['note'] ?? null ) : null,
			);
		}
		return $out;
	}

	private static function last_result( array $timeline ): ?array {
		for ( $i = count( $timeline ) - 1; $i >= 0; $i-- ) {
			if ( in_array( $timeline[ $i ]['action'], array( 'done', 'returned' ), true ) && ! empty( $timeline[ $i ]['note'] ) ) {
				return array( 'note_excerpt' => $timeline[ $i ]['note'], 'status' => $timeline[ $i ]['action'] );
			}
		}
		return null;
	}

	private static function user_ref( int $user_id, bool $with_role ): ?array {
		if ( $user_id <= 0 ) { return null; }
		$user = get_userdata( $user_id );
		$out = array( 'user_id' => $user_id, 'display_name' => $user ? (string) $user->display_name : '#' . $user_id );
		if ( $with_role ) { $out['team_role'] = BizCity_CRM_Staff_Policy::role( $user_id ); }
		return $out;
	}

	// ── Utilities ────────────────────────────────────────────────────────

	public static function mask_phone( string $phone ): ?string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		$len = strlen( $digits );
		if ( $len === 0 ) { return null; }
		return $len > 4 ? substr( $digits, 0, 2 ) . str_repeat( '*', max( 2, $len - 4 ) ) . substr( $digits, -2 ) : str_repeat( '*', $len );
	}

	private static function sanitize_date( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) { return null; }
		if ( ctype_digit( $value ) ) { return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $value ), 'Y-m-d' ); }
		return preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ? substr( $value, 0, 10 ) : null;
	}

	private static function audit_ready(): bool {
		return class_exists( 'BizCity_CRM_Audit_Log' ) && BizCity_CRM_DB_Installer_V2::table_exists( BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log() );
	}

	private static function audit( int $task_id, string $action, int $actor_id, ?array $before, ?array $after ): void {
		if ( ! class_exists( 'BizCity_CRM_Audit_Log' ) ) { return; }
		BizCity_CRM_Audit_Log::log( self::AUDIT_ENTITY, $task_id, $action, $before, $after, array( 'user_id' => $actor_id ) );
	}

	private static function error( string $code, string $message, int $status, array $extra = array() ): WP_Error {
		return new WP_Error( $code, $message, array_merge( array( 'status' => $status, 'help_code' => $code ), $extra ) );
	}
}
