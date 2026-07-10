<?php
/**
 * BizCity CRM — Scheduler Adapter (PHASE-0.35-GURU-SERVICES §G.6).
 *
 * Bridges CRM Inbox ↔ existing core/scheduler/. NO fork, NO new table, NO
 * new REST namespace. CRM consumes scheduler's hooks + REST as-is. The only
 * extra state lives inside `bizcity_scheduler_events.metadata` JSON column.
 *
 * Wiring:
 *   • Helper `create_from_conversation()` for FE convenience (calls
 *     BizCity_Scheduler_Manager::create_event with source='crm_inbox').
 *   • Listen `bizcity_scheduler_event_created` → emit CRM event +
 *     append system note to conversation.
 *   • Listen `bizcity_scheduler_event_updated` / `_deleted` → mirror notes.
 *   • Listen `bizcity_scheduler_reminder_fire` → add reminder note so
 *     operator sees badge.
 *   • Filter `bizcity_scheduler_parse_quick` (priority 20) → enrich AI
 *     parse with contact context when CRM is the caller.
 *
 * Anti-patterns (banned by §11):
 *   ✗ Fork core/scheduler/ or create bizcity_crm_appointments table.
 *   ✗ Wrap scheduler REST in a CRM-namespaced route.
 *   ✗ Mutate scheduler core to know about CRM (one-way dependency only).
 *
 * @package BizCity_Twin_CRM
 * @since   2026-05-10
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Scheduler_Adapter {

	const SOURCE_TAG = 'crm_inbox';

	/**
	 * Boot — wire WordPress hooks. Idempotent.
	 */
	public static function register(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'bizcity_scheduler_event_created',  array( __CLASS__, 'on_event_created' ), 10, 2 );
		add_action( 'bizcity_scheduler_event_updated',  array( __CLASS__, 'on_event_updated' ), 10, 3 );
		add_action( 'bizcity_scheduler_event_deleted',  array( __CLASS__, 'on_event_deleted' ), 10, 1 );
		add_action( 'bizcity_scheduler_reminder_fire',  array( __CLASS__, 'on_reminder_fire' ), 10, 1 );
		add_filter( 'bizcity_scheduler_parse_quick',    array( __CLASS__, 'enrich_quick_parse' ), 20, 2 );
	}

	/* =================================================================
	 * Helper — create event from a CRM conversation context.
	 * =================================================================
	 *
	 * Usage from FE / message handler:
	 *   $event_id = BizCity_CRM_Scheduler_Adapter::create_from_conversation( $conv_id, [
	 *     'title'        => 'CSKH gọi lại',
	 *     'start_at'     => '2026-05-12 15:00:00',
	 *     'end_at'       => '2026-05-12 15:30:00',
	 *     'reminder_min' => 15,
	 *     'description'  => 'Khách hỏi báo giá tour',
	 *   ] );
	 *
	 * @param int   $conversation_id
	 * @param array $event_payload  Same shape as BizCity_Scheduler_Manager::create_event.
	 * @return int|WP_Error event_id or error.
	 */
	public static function create_from_conversation( int $conversation_id, array $event_payload ) {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'scheduler_not_loaded', 'core/scheduler/ not available.' );
		}
		if ( $conversation_id <= 0 ) {
			return new WP_Error( 'bad_conversation', 'conversation_id required.' );
		}

		$ctx = self::resolve_conversation_context( $conversation_id );
		if ( ! $ctx ) {
			return new WP_Error( 'conversation_missing', 'Conversation not found.' );
		}

		$existing_meta = isset( $event_payload['metadata'] ) && is_array( $event_payload['metadata'] )
			? $event_payload['metadata'] : array();

		$event_payload['source']   = self::SOURCE_TAG;
		$event_payload['metadata'] = array_merge( $existing_meta, array(
			'conversation_id' => $conversation_id,
			'contact_id'      => $ctx['contact_id'],
			'channel'         => $ctx['channel'],
			'created_by'      => get_current_user_id() ?: null,
		) );

		// Default the actor to the conversation's assigned operator (if any) so
		// the scheduler row is owned consistently.
		if ( empty( $event_payload['user_id'] ) && ! empty( $ctx['assignee_id'] ) ) {
			$event_payload['user_id'] = (int) $ctx['assignee_id'];
		}

		return BizCity_Scheduler_Manager::instance()->create_event( $event_payload );
	}

	/* =================================================================
	 * Listeners
	 * ================================================================= */

	public static function on_event_created( $event, $data ): void {
		if ( ! self::is_crm_event( $event ) ) {
			return;
		}
		$meta    = self::extract_meta( $event );
		$conv_id = (int) ( $meta['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) {
			return;
		}

		$start  = (string) ( $event->start_at ?? '' );
		$title  = (string) ( $event->title    ?? '(không tiêu đề)' );
		$note   = sprintf( '📅 Đã tạo lịch hẹn: %s — %s', $title, $start );

		self::append_system_note( $conv_id, $note, array(
			'kind'         => 'appointment_created',
			'event_id'     => (int) $event->id,
			'scheduler_id' => (int) $event->id,
		) );

		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_appointment_created', array(
				'conversation_id' => $conv_id,
				'contact_id'      => (int) ( $meta['contact_id'] ?? 0 ),
				'event_id'        => (int) $event->id,
				'title'           => $title,
				'start_at'        => $start,
				'reminder_min'    => isset( $event->reminder_min ) ? (int) $event->reminder_min : null,
			) );
		}
	}

	public static function on_event_updated( $event, $old, array $changed_fields ): void {
		if ( ! self::is_crm_event( $event ) ) {
			return;
		}
		$meta    = self::extract_meta( $event );
		$conv_id = (int) ( $meta['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) {
			return;
		}

		$is_reschedule = (bool) array_intersect( array( 'start_at', 'end_at' ), (array) $changed_fields );
		$note          = $is_reschedule
			? sprintf( '🔁 Đã dời lịch: %s — %s', (string) $event->title, (string) $event->start_at )
			: sprintf( '✏️ Cập nhật lịch: %s', (string) $event->title );

		self::append_system_note( $conv_id, $note, array(
			'kind'         => $is_reschedule ? 'appointment_rescheduled' : 'appointment_updated',
			'event_id'     => (int) $event->id,
			'changed'      => array_values( $changed_fields ),
		) );
	}

	public static function on_event_deleted( $event ): void {
		if ( ! self::is_crm_event( $event ) ) {
			return;
		}
		$meta    = self::extract_meta( $event );
		$conv_id = (int) ( $meta['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) {
			return;
		}

		self::append_system_note(
			$conv_id,
			sprintf( '❌ Đã hủy lịch: %s', (string) ( $event->title ?? '' ) ),
			array( 'kind' => 'appointment_cancelled', 'event_id' => (int) $event->id )
		);
	}

	public static function on_reminder_fire( $event ): void {
		if ( ! self::is_crm_event( $event ) ) {
			return;
		}
		$meta    = self::extract_meta( $event );
		$conv_id = (int) ( $meta['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) {
			return;
		}

		self::append_system_note(
			$conv_id,
			sprintf( '⏰ Nhắc lịch: %s — bắt đầu lúc %s', (string) $event->title, (string) $event->start_at ),
			array(
				'kind'         => 'appointment_reminder',
				'event_id'     => (int) $event->id,
				'reminder_min' => isset( $event->reminder_min ) ? (int) $event->reminder_min : null,
			)
		);

		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_appointment_reminder', array(
				'conversation_id' => $conv_id,
				'event_id'        => (int) $event->id,
			) );
		}
	}

	/**
	 * Filter `bizcity_scheduler_parse_quick` — enrich the AI parse with
	 * conversation/contact context when the request originated from CRM.
	 *
	 * Trigger: REST request includes `?conversation_id=N` (FE adapter passes
	 * it through SchedulerApp's `presetContext` prop). When present, we
	 * prepend the contact name to the parsed title so the user sees it in
	 * the suggestion preview.
	 *
	 * @param array  $parsed  {title, start_at, end_at, reminder_min, all_day, description}
	 * @param string $text    Original raw quick-add text.
	 * @return array
	 */
	public static function enrich_quick_parse( $parsed, $text ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		// Pull conversation_id from the active REST request body.
		$conv_id = 0;
		if ( function_exists( 'rest_get_server' ) ) {
			$req = rest_get_server()->get_raw_data();
			if ( is_string( $req ) && $req !== '' ) {
				$body = json_decode( $req, true );
				if ( is_array( $body ) && ! empty( $body['conversation_id'] ) ) {
					$conv_id = (int) $body['conversation_id'];
				}
			}
		}
		if ( $conv_id <= 0 && isset( $_REQUEST['conversation_id'] ) ) {
			$conv_id = (int) $_REQUEST['conversation_id'];
		}
		if ( $conv_id <= 0 ) {
			return $parsed;
		}

		$ctx = self::resolve_conversation_context( $conv_id );
		if ( ! $ctx || empty( $ctx['contact_name'] ) ) {
			return $parsed;
		}

		$title = (string) ( $parsed['title'] ?? '' );
		// Avoid double-prefix.
		if ( stripos( $title, (string) $ctx['contact_name'] ) === false ) {
			$parsed['title'] = trim( sprintf( '[%s] %s', $ctx['contact_name'], $title ) );
		}

		return $parsed;
	}

	/* =================================================================
	 * Internals
	 * ================================================================= */

	private static function is_crm_event( $event ): bool {
		if ( ! is_object( $event ) ) {
			return false;
		}
		return isset( $event->source ) && (string) $event->source === self::SOURCE_TAG;
	}

	/**
	 * Decode metadata column → assoc array (idempotent if already array).
	 */
	private static function extract_meta( $event ): array {
		if ( ! is_object( $event ) || ! isset( $event->metadata ) ) {
			return array();
		}
		$raw = $event->metadata;
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return array();
	}

	/**
	 * Resolve {contact_id, channel, contact_name, assignee_id} from a
	 * conversation row. Returns null if conversation missing.
	 */
	private static function resolve_conversation_context( int $conv_id ): ?array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}
		$conv_tbl    = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$contact_tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci_tbl      = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$inbox_tbl   = BizCity_CRM_DB_Installer_V2::tbl_inboxes();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.assignee_id, c.inbox_id,
			        ci.contact_id AS contact_id,
			        ct.name       AS contact_name,
			        i.channel_type AS channel
			   FROM {$conv_tbl} c
			   LEFT JOIN {$ci_tbl}      ci ON ci.id = c.contact_inbox_id
			   LEFT JOIN {$contact_tbl} ct ON ct.id = ci.contact_id
			   LEFT JOIN {$inbox_tbl}   i  ON i.id  = c.inbox_id
			  WHERE c.id = %d
			  LIMIT 1",
			$conv_id
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}
		return array(
			'contact_id'   => (int) ( $row['contact_id']  ?? 0 ),
			'contact_name' => (string) ( $row['contact_name'] ?? '' ),
			'channel'      => (string) ( $row['channel']  ?? '' ),
			'assignee_id'  => (int) ( $row['assignee_id'] ?? 0 ),
		);
	}

	/**
	 * Append a system-authored message into the conversation. Uses
	 * Repository::insert_message with sender_type='system'. Idempotency
	 * provided by external_source_id = "scheduler:event_id:kind".
	 */
	private static function append_system_note( int $conv_id, string $body, array $meta = array() ): void {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return;
		}
		$ext = sprintf( 'scheduler:%d:%s', (int) ( $meta['event_id'] ?? 0 ), (string) ( $meta['kind'] ?? 'note' ) );

		// Resolve inbox_id from conversation.
		global $wpdb;
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$inbox_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT inbox_id FROM {$conv_tbl} WHERE id = %d LIMIT 1", $conv_id
		) );
		if ( $inbox_id <= 0 ) {
			return;
		}

		BizCity_CRM_Repository::insert_message( array(
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'external_source_id' => $ext,
			'content'            => $body,
			'content_type'       => 'text',
			'message_type'       => 'system',
			'sender_type'        => 'system',
			'status'             => 'sent',
			'ai_metadata'        => array_merge( array( 'origin' => 'scheduler_adapter' ), $meta ),
		) );
	}
}
