<?php
/**
 * BizCity CRM — Campaign ↔ Scenario Conversion Bridge (PHASE 0.35 M6.W9).
 *
 * Listens on `crm_campaign_conversion_recorded` (priority 30 — runs AFTER
 * Loyalty Bridge @25) and applies the campaign's bound scenario to the
 * conversation:
 *
 *   1. `bound_character_id`   → switch the conversation to that character
 *                               (sets `conversations.character_id`).
 *   2. `bound_notebook_id`    → attach notebook for AI grounding
 *                               (sets `conversations.notebook_id`).
 *   3. `welcome_template_id`  → render macro template + insert outgoing
 *                               message + dispatch via inbox adapter.
 *
 * All three are optional; bridge is a no-op for campaigns with none set.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M6.W9
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Campaign_Conversion_Bridge {

	public static function register(): void {
		// Event_Emitter::emit() fans out via `bizcity_crm_event_<type>` — raw
		// `crm_campaign_conversion_recorded` is never fired (BUG fixed M6.W12).
		add_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', array( __CLASS__, 'on_conversion' ), 30, 1 );
	}

	public static function on_conversion( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$campaign_id = (int) ( $payload['campaign_id'] ?? 0 );
		$contact_id  = (int) ( $payload['contact_id'] ?? 0 );
		if ( $campaign_id <= 0 || $contact_id <= 0 ) { return; }

		$camp = BizCity_CRM_Campaign_Repository::get( $campaign_id );
		if ( ! $camp ) { return; }

		$char_id     = (int) ( $camp['bound_character_id']  ?? 0 );
		$nb_id       = (int) ( $camp['bound_notebook_id']   ?? 0 );
		$tmpl_id     = (int) ( $camp['welcome_template_id'] ?? 0 );

		if ( $char_id <= 0 && $nb_id <= 0 && $tmpl_id <= 0 ) { return; }

		// Find the most recent open conversation for this contact (the inbound
		// message that triggered W4 lives there — that conversation_id is the
		// one we want).
		$conv_id = self::most_recent_conversation_for_contact( $contact_id );
		if ( $conv_id <= 0 ) { return; }

		$applied = array();

		if ( $char_id > 0 ) {
			$applied['character'] = self::switch_character( $conv_id, $char_id );
		}
		if ( $nb_id > 0 ) {
			$applied['notebook'] = self::attach_notebook( $conv_id, $nb_id );
		}
		if ( $tmpl_id > 0 ) {
			$applied['welcome'] = self::send_welcome_template( $conv_id, $tmpl_id, array(
				'campaign_id'   => $campaign_id,
				'campaign_code' => (string) ( $payload['code'] ?? '' ),
				'event_uuid'    => (string) ( $payload['event_uuid'] ?? '' ),
			) );
		}

		BizCity_CRM_Event_Emitter::emit( 'crm_campaign_scenario_applied', array(
			'campaign_id'    => $campaign_id,
			'conversation_id'=> $conv_id,
			'contact_id'     => $contact_id,
			'applied'        => $applied,
		) );
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	public static function most_recent_conversation_for_contact( int $contact_id ): int {
		global $wpdb;
		$ci    = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$cv    = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT cv.id
			   FROM {$cv} cv
			   JOIN {$ci} ci ON ci.id = cv.contact_inbox_id
			  WHERE ci.contact_id = %d
			  ORDER BY cv.id DESC LIMIT 1",
			$contact_id
		) );
	}

	private static function switch_character( int $conv_id, int $character_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ok  = $wpdb->update(
			$tbl,
			array( 'character_id' => $character_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conv_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return array( 'ok' => false !== $ok, 'character_id' => $character_id );
	}

	private static function attach_notebook( int $conv_id, int $notebook_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ok  = $wpdb->update(
			$tbl,
			array( 'notebook_id' => $notebook_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conv_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		return array( 'ok' => false !== $ok, 'notebook_id' => $notebook_id );
	}

	private static function send_welcome_template( int $conv_id, int $macro_id, array $event_meta ): array {
		$macro = BizCity_CRM_Repository::get_macro( $macro_id );
		if ( ! $macro ) { return array( 'ok' => false, 'detail' => 'macro_not_found' ); }

		$template = (string) ( $macro['template'] ?? '' );
		if ( $template === '' ) { return array( 'ok' => false, 'detail' => 'macro_empty_template' ); }

		// Render with conversation context (M3.W4 token resolver).
		$content = $template;
		if ( class_exists( 'BizCity_CRM_Template_Renderer' ) ) {
			$ctx     = BizCity_CRM_Template_Renderer::build_context_from_conversation( $conv_id );
			$content = BizCity_CRM_Template_Renderer::render( $template, $ctx, 'text' );
		}

		$conv = BizCity_CRM_Repository::get_conversation( $conv_id );
		if ( ! $conv ) { return array( 'ok' => false, 'detail' => 'conversation_not_found' ); }

		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5.7c — the welcome template goes through the canonical outbound owner so a replayed campaign event cannot double-send.
		if ( ! class_exists( 'BizCity_CRM_Outbound_Dispatcher' ) ) {
			return array( 'ok' => false, 'detail' => 'outbound_dispatcher_unavailable' );
		}
		$campaign_code = (string) ( $event_meta['campaign_code'] ?? '' );
		$correlation   = (string) ( $event_meta['event_uuid'] ?? '' );
		$envelope = BizCity_CRM_Outbound_Dispatcher::dispatch( array(
			'conversation_id'      => $conv_id,
			'content'              => $content,
			'content_type'         => 'text',
			// One welcome per (conversation, campaign, macro) even if the event replays.
			'idempotency_key'      => 'welcome_' . md5( $conv_id . '|' . $campaign_code . '|' . $macro_id . '|' . $correlation ),
			'request_hash'         => md5( 'campaign_welcome|' . $conv_id . '|' . $macro_id . '|' . $content ),
			'actor'                => 'system',
			'system_source'        => 'campaign',
			'on_behalf_of_user_id' => (int) ( $event_meta['owner_user_id'] ?? 0 ),
			'parent_event_uuid'    => $event_meta['event_uuid'] ?? null,
			'macro_id'             => $macro_id,
		) );
		$outcome = (string) ( $envelope['outcome'] ?? 'failed' );

		return array(
			'ok'           => 'failed' !== $outcome,
			'message_id'   => (int) ( $envelope['message_id'] ?? 0 ),
			'outcome'      => $outcome,
			'replayed'     => ! empty( $envelope['replayed'] ),
			'owner_source' => (string) ( $envelope['owner_source'] ?? '' ),
			'detail'       => 'failed' === $outcome ? (string) ( $envelope['code'] ?? 'send_failed' ) : '',
		);
	}
}
