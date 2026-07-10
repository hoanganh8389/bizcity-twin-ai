<?php
/**
 * BizCity CRM — Inbox → CRM Bridge (PHASE 0.35 M-Bridge.W1).
 *
 * On `crm_conversation_resolved`, materialise a "chat session" activity
 * record into `bizcity_crm_tasks` so the chat session shows up in the
 * contact / lead activity timeline.
 *
 * Why tasks (not a new `crm_activities` table)?
 *   - Tasks already model "things done", carry `related_entity_type/id`,
 *     `notes`, `completed_at`, `created_by`.
 *   - Avoids an R-DCL schema migration for one row-shape.
 *   - Contact / Lead detail pages can filter
 *     `crm_tasks WHERE related_entity_type IN ('conversation','contact')`
 *     to show a unified activity feed.
 *
 * Idempotent: skips if a chat-session task for the same conversation_id
 * already exists (e.g. when the user toggles status open→resolved twice).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M-Bridge.W1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Inbox_To_CRM_Bridge {

	const ACTIVITY_PRIORITY = 'log';      // sentinel — distinguishes auto-logged activities from real tasks
	const ACTIVITY_TITLE_FMT = 'Chat session #%d';

	public static function register(): void {
		add_action( 'bizcity_crm_event_crm_conversation_resolved', array( __CLASS__, 'on_resolved' ), 50, 1 );
	}

	/**
	 * Hook handler — runs after CSAT (priority 30) so the resolve flow stays
	 * stable when bridge is removed.
	 */
	public static function on_resolved( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$cid = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $cid <= 0 ) { return; }

		global $wpdb;
		$tasks_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();

		// Idempotency guard — skip if we already logged this session.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$tasks_tbl}` WHERE related_entity_type = %s AND related_entity_id = %d AND priority = %s LIMIT 1",
			'conversation',
			$cid,
			self::ACTIVITY_PRIORITY
		) );
		if ( $existing > 0 ) { return; }

		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci_tbl   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv     = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.inbox_id, c.contact_inbox_id, c.status, c.created_at, ci.contact_id
			 FROM `{$conv_tbl}` c
			 LEFT JOIN `{$ci_tbl}` ci ON ci.id = c.contact_inbox_id
			 WHERE c.id = %d",
			$cid
		), ARRAY_A );
		if ( ! $conv ) { return; }

		$contact_id = (int) ( $conv['contact_id'] ?? 0 );
		$inbox_id   = (int) ( $conv['inbox_id']   ?? 0 );

		// Build a compact summary from the message stream.
		$summary = self::build_summary( $cid );
		$by_user = (int) ( $payload['by_user_id'] ?? get_current_user_id() );

		// Notes JSON — structured so Activity views can render rich timeline rows.
		$notes_json = wp_json_encode( array(
			'kind'             => 'chat_session',
			'conversation_id'  => $cid,
			'inbox_id'         => $inbox_id,
			'contact_id'       => $contact_id,
			'resolved_by'      => $by_user,
			'message_count'    => (int) ( $summary['count'] ?? 0 ),
			'inbound_count'    => (int) ( $summary['in'] ?? 0 ),
			'outbound_count'   => (int) ( $summary['out'] ?? 0 ),
			'first_inbound'    => (string) ( $summary['first_in'] ?? '' ),
			'last_outbound'    => (string) ( $summary['last_out'] ?? '' ),
			'started_at'       => (string) ( $conv['created_at'] ?? '' ),
		) );

		$now = current_time( 'mysql' );
		$wpdb->insert( $tasks_tbl, array(
			'title'               => sprintf( self::ACTIVITY_TITLE_FMT, $cid ),
			'status'              => 'completed',
			'priority'            => self::ACTIVITY_PRIORITY,
			'due_date'            => null,
			'assignee_id'         => $by_user ?: null,
			'related_entity_type' => 'conversation',
			'related_entity_id'   => $cid,
			'notes'               => $notes_json,
			'completed'           => 1,
			'completed_at'        => $now,
			'created_by'          => $by_user ?: null,
			'created_at'          => $now,
			'updated_at'          => $now,
		) );
		$task_id = (int) $wpdb->insert_id;

		if ( $task_id > 0 && class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_chat_session_logged', array(
				'task_id'         => $task_id,
				'conversation_id' => $cid,
				'contact_id'      => $contact_id,
				'inbox_id'        => $inbox_id,
				'message_count'   => (int) ( $summary['count'] ?? 0 ),
			) );
		}
	}

	/**
	 * Build a compact summary from messages of this conversation.
	 *
	 * @return array{ count:int, in:int, out:int, first_in:string, last_out:string }
	 */
	private static function build_summary( int $cid ): array {
		global $wpdb;
		$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT message_type, content FROM `{$msg_tbl}` WHERE conversation_id = %d ORDER BY id ASC LIMIT 200",
			$cid
		), ARRAY_A );
		$in = 0; $out = 0; $first_in = ''; $last_out = '';
		foreach ( (array) $rows as $r ) {
			$t = (string) ( $r['message_type'] ?? '' );
			$c = trim( (string) ( $r['content'] ?? '' ) );
			if ( $t === 'incoming' ) {
				$in++;
				if ( $first_in === '' && $c !== '' ) { $first_in = self::truncate( $c, 240 ); }
			} elseif ( $t === 'outgoing' ) {
				$out++;
				if ( $c !== '' ) { $last_out = self::truncate( $c, 240 ); }
			}
		}
		return array(
			'count'    => $in + $out,
			'in'       => $in,
			'out'      => $out,
			'first_in' => $first_in,
			'last_out' => $last_out,
		);
	}

	private static function truncate( string $s, int $n ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $n ) {
			return mb_substr( $s, 0, $n - 1 ) . '…';
		}
		if ( strlen( $s ) > $n ) {
			return substr( $s, 0, $n - 1 ) . '…';
		}
		return $s;
	}
}
