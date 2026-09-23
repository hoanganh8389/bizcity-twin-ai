<?php
/**
 * BizCity CRM — Pipeline Document Link (PHASE-0.71 F71-13 / PHASE-0.63C GC-9).
 *
 * Auto-links an inbound message's attachments (Zalo and every other channel — the
 * ingestor fires the same hook for all of them, see `class-fb-ingestor.php`) to the
 * contact's pipeline run, so a photo sent mid-conversation lands under "Tài liệu" of
 * the run that is actually in progress, not the generic contact-wide document list.
 *
 * 0.63C's original GC-9 text assumed a "selected run for this conversation" concept
 * that only ever lived in the browser (`PipelineTargetSelector.jsx`'s `localStorage`,
 * never persisted server-side — PHASE-0.71 §2.3 F71-7). D71-3 (PHASE-0.71 §6) chose the
 * cheaper of the two designs on the table: a heuristic, not a new persisted "selected
 * run" field. **We only auto-link when the contact has EXACTLY ONE open run** — with
 * zero or with two-or-more, we skip silently rather than guess wrong. A contact who
 * genuinely has two concurrent open runs (e.g. both a `purchase` and a `production` in
 * flight) keeps getting the pre-existing behaviour: attachments simply aren't linked to
 * a pipeline_run, exactly as before this file existed.
 *
 * Storage: `bizcity_crm_documents.message_id` (added by `class-db-installer.php`'s
 * `migrate_phase_071()`) doubles as this class's idempotency key — a message whose
 * attachments are already linked is never reprocessed, so a duplicate
 * `bizcity_crm_message_persisted` fire (or a future admin retry) cannot create
 * duplicate document rows.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Document_Link', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Document_Link {

	/**
	 * Handler for `bizcity_crm_message_persisted` (fired only for inbound messages, after
	 * their attachments are already committed to `bizcity_crm_attachments` — see
	 * `class-fb-ingestor.php::ingest()`).
	 *
	 * @param array $ctx { message_id:int, conversation_id:int, inbox_id:int, contact_id:int, adapter_code:string, direction:string }
	 */
	public static function on_message_persisted( $ctx ): void {
		if ( ! is_array( $ctx ) ) {
			return;
		}
		$message_id = (int) ( $ctx['message_id'] ?? 0 );
		$contact_id = (int) ( $ctx['contact_id'] ?? 0 );
		if ( $message_id <= 0 || $contact_id <= 0 || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}

		global $wpdb;
		$documents_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_documents();

		// Idempotency: a message already linked (this fire, a retry, a double-dispatch) is
		// never reprocessed — checked before anything else so a contact with 0 or 2+ open
		// runs doesn't get re-evaluated on every retry either.
		$already = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$documents_tbl}` WHERE message_id = %d LIMIT 1", $message_id
		) );
		if ( $already ) {
			return;
		}

		$attachments_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
		$attachments = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$attachments_tbl}` WHERE message_id = %d", $message_id
		), ARRAY_A );
		if ( empty( $attachments ) ) {
			return;
		}

		$run_id = self::single_open_run_id( $contact_id );
		if ( $run_id <= 0 ) {
			return;
		}

		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		foreach ( $attachments as $attachment ) {
			$path = (string) ( $attachment['data_url'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$wpdb->insert( $documents_tbl, array(
				'name'                => self::name_from_path( $path ),
				'type'                => self::text( $attachment['file_type'] ?? 'file', 64 ),
				// Not known from the attachment record — nothing in `bizcity_crm_attachments`
				// carries a byte size today.
				'size_bytes'          => 0,
				'path'                => self::text( $path, 512 ),
				// Inbound from the contact, not a staff upload.
				'uploaded_by'         => null,
				'related_entity_type' => 'pipeline_run',
				'related_entity_id'   => $run_id,
				'message_id'          => $message_id,
				'uploaded_at'         => $now,
			) );
		}
	}

	/**
	 * @return int The contact's one OPEN pipeline run id, or 0 when there are zero or 2+
	 *             (never guesses which one — D71-3).
	 */
	private static function single_open_run_id( int $contact_id ): int {
		if ( ! class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			return 0;
		}
		$runs = BizCity_CRM_Pipeline_Run_Service::runs_for_contact( $contact_id );
		if ( ! is_array( $runs ) ) {
			return 0;
		}
		$open = array_values( array_filter( $runs, static function ( $run ) {
			return is_array( $run ) && 'open' === (string) ( $run['status'] ?? '' );
		} ) );
		return 1 === count( $open ) ? (int) ( $open[0]['id'] ?? 0 ) : 0;
	}

	private static function name_from_path( string $path ): string {
		$name = (string) ( parse_url( $path, PHP_URL_PATH ) ?: $path );
		$name = basename( $name );
		return '' !== $name ? self::text( $name, 255 ) : 'attachment';
	}

	private static function text( $value, int $limit ): string {
		$value = trim( (string) $value );
		return function_exists( 'sanitize_text_field' ) ? substr( sanitize_text_field( $value ), 0, $limit ) : substr( $value, 0, $limit );
	}
}
