<?php
/**
 * BizCity CRM — CSAT Survey + Audit (PHASE 0.35 M5.W5).
 *
 * Hooks `crm_conversation_resolved` → schedules a one-shot delayed send (5 min)
 * which inserts a `csat_survey` outgoing message bubble + emits `crm_csat_sent`.
 *
 * Inbound listener: when an inbound message arrives on a conversation with a
 * pending csat (i.e. last `crm_csat_sent` has no later `crm_csat_response`),
 * if the message body matches a 1-5 score, record it as `crm_csat_response`.
 *
 * Audit tab: registers itself via the `bizcity_intent_monitor_tabs` filter
 * (R-IMN-1, R-IMN-4) — no new admin menu page.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_CSAT_Survey {

	const SEND_HOOK     = 'bizcity_crm_csat_send';
	const SEND_DELAY    = 300; // 5 minutes
	const PROMPT_TEXT   = "How was your experience? Reply with 1 (worst) – 5 (best).";

	public static function register(): void {
		add_action( 'bizcity_crm_event_crm_conversation_resolved', array( __CLASS__, 'on_resolved' ), 30, 1 );
		add_action( self::SEND_HOOK, array( __CLASS__, 'do_send' ), 10, 1 );
		add_action( 'bizcity_crm_event_crm_message_received',     array( __CLASS__, 'on_inbound' ),  30, 1 );
	}

	public static function on_resolved( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$cid = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $cid <= 0 ) { return; }
		// Avoid double-scheduling.
		if ( wp_next_scheduled( self::SEND_HOOK, array( $cid ) ) ) { return; }
		wp_schedule_single_event( time() + self::SEND_DELAY, self::SEND_HOOK, array( $cid ) );
	}

	public static function do_send( $cid ): void {
		$cid = (int) $cid;
		if ( $cid <= 0 ) { return; }
		global $wpdb;
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$conv     = $wpdb->get_row( $wpdb->prepare( "SELECT id, inbox_id FROM {$conv_tbl} WHERE id=%d", $cid ), ARRAY_A );
		if ( ! $conv ) { return; }
		$inbox_id = (int) $conv['inbox_id'];

		// Insert the survey bubble directly via Repository::insert_outgoing_message
		// when available, otherwise raw insert (and then mirror to event stream).
		$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$now     = current_time( 'mysql' );
		$wpdb->insert( $msg_tbl, array(
			'conversation_id' => $cid,
			'inbox_id'        => $inbox_id,
			'content'         => self::PROMPT_TEXT,
			'content_type'    => 'text',
			'message_type'    => 'outgoing',
			'sender_type'     => 'system',
			'status'          => 'sent',
			'responder_kind'  => 'system',
			'created_at'      => $now,
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$msg_id = (int) $wpdb->insert_id;

		BizCity_CRM_Event_Emitter::emit( 'crm_csat_sent', array(
			'conversation_id' => $cid,
			'inbox_id'        => $inbox_id,
			'message_id'      => $msg_id,
			'sent_at'         => time(),
		) );
	}

	/**
	 * Inbound listener — match 1-5 score when CSAT is pending.
	 *
	 * Payload contract (from Adapter_Facebook etc): {conversation_id, inbox_id, content, ...}
	 */
	public static function on_inbound( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$cid = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $cid <= 0 ) { return; }
		$content = trim( (string) ( $payload['content'] ?? '' ) );
		if ( $content === '' || ! preg_match( '/^[1-5]$/', $content ) ) { return; }
		if ( ! self::has_pending_survey( $cid ) ) { return; }
		self::record_response( $cid, (int) $content, (int) ( $payload['inbox_id'] ?? 0 ) );
	}

	public static function has_pending_survey( int $cid ): bool {
		global $wpdb;
		$evt = $wpdb->prefix . 'bizcity_twin_event_stream';
		// Latest crm_csat_sent for this conversation.
		$sent = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(created_epoch_ms) FROM {$evt} WHERE event_type=%s AND payload_json LIKE %s",
			'crm_csat_sent',
			'%"conversation_id":' . $cid . '%'
		) );
		if ( ! $sent ) { return false; }
		$resp = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(created_epoch_ms) FROM {$evt} WHERE event_type=%s AND payload_json LIKE %s",
			'crm_csat_response',
			'%"conversation_id":' . $cid . '%'
		) );
		return ( ! $resp ) || ( (int) $resp < (int) $sent );
	}

	public static function record_response( int $cid, int $score, int $inbox_id = 0 ): string {
		$score = max( 1, min( 5, $score ) );
		return BizCity_CRM_Event_Emitter::emit( 'crm_csat_response', array(
			'conversation_id' => $cid,
			'inbox_id'        => $inbox_id,
			'score'           => $score,
			'received_at'     => time(),
		) );
	}

	/* ---------- Audit tab integration ---------- */

	public static function register_intent_monitor_tab( array $tabs ): array {
		$tabs['crm-audit'] = array(
			'label'    => 'CRM Audit',
			'callback' => array( __CLASS__, 'render_audit_tab' ),
		);
		return $tabs;
	}

	public static function render_audit_tab(): void {
		global $wpdb;
		$evt   = $wpdb->prefix . 'bizcity_twin_event_stream';
		$type  = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['event_type'] ) ) : '';
		$where = "event_type LIKE 'crm_%'";
		if ( $type !== '' && preg_match( '/^crm_[a-z_]+$/', $type ) ) {
			$where = $wpdb->prepare( 'event_type=%s', $type );
		}
		$rows = $wpdb->get_results( "SELECT id, event_type, event_uuid, parent_event_id, payload_json, created_at
			FROM {$evt} WHERE {$where} ORDER BY id DESC LIMIT 50", ARRAY_A );
		echo '<h2>CRM Audit (latest 50)</h2>';
		echo '<form method="get" style="margin:8px 0">';
		foreach ( $_GET as $k => $v ) {
			if ( $k === 'event_type' ) { continue; }
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( (string) $v ) );
		}
		echo 'Filter event_type: <input name="event_type" value="' . esc_attr( $type ) . '" placeholder="crm_message_received"> ';
		echo '<button class="button">Apply</button></form>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Event</th><th>UUID</th><th>Created</th><th>Payload</th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			echo '<tr>';
			echo '<td>' . (int) $r['id'] . '</td>';
			echo '<td><code>' . esc_html( (string) $r['event_type'] ) . '</code></td>';
			echo '<td><small>' . esc_html( (string) $r['event_uuid'] ) . '</small></td>';
			echo '<td><small>' . esc_html( (string) $r['created_at'] ) . '</small></td>';
			echo '<td><pre style="white-space:pre-wrap;font-size:11px;margin:0">' . esc_html( (string) $r['payload_json'] ) . '</pre></td>';
			echo '</tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5"><em>No CRM events yet.</em></td></tr>';
		}
		echo '</tbody></table>';
	}
}
