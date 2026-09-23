<?php
/**
 * BizCity CRM — Location Service (PHASE-0.69 §4, WP-L). **0 new tables** (0.69 §4.4).
 *
 * A Zalo Personal "send location" share does not arrive as a structured attachment — it is flattened
 * to a plain text message containing a Google Maps link (0.69 §4.1, verified against the sidecar's
 * `classify.ts`/`wpSink.ts` behaviour, confirmed 2026-09-21). This class recovers the coordinates from
 * that text (source A), or, when present, from the richer `ai_metadata_json.quote_src.content.params`
 * payload the inbound adapter already carries unconditionally but nothing reads today (source B) —
 * exactly the two sources the phase doc chose, in the priority it chose (`B a rồi A`, §4.4 L-02).
 *
 * Storage (0.69 §4.4 — the whole point is 0 DDL):
 *   - one `bizcity_crm_attachments` row, `file_type='location'` (needs the channel-contract allowlist
 *     widened — see `includes/inbox/class-channel-contract.php` R2 fix, same date).
 *   - customer's point also mirrors onto the contact's OPEN `service` pipeline run at
 *     `custom_json.service_address` (`Pipeline_Run_Service::set_service_address()`), because that is
 *     where the check-in distance check and the dispatch map actually read from.
 *   - staff's own point (only ever written by the magic-link flow, never by passive extraction — see
 *     `class-crm-service-rest.php`) lives in `user_meta['bizcity_crm_staff_last_location']`, overwritten
 *     each time (0.69 §4.4 L4 — this is a last-known-point index, not a location history table).
 *
 * PII discipline (0.69 §4.5/L-07, R-LM-5): coordinates never go into Context Bank, never into a
 * notification body, never into `error_log()`. Only ids and booleans are allowed to leave this class
 * through anything that isn't `extract_from_message()`/`last_for_user()`'s direct caller.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-23 (PHASE-0.69 WP-L)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Location_Service', false ) ) {
	return;
}

final class BizCity_CRM_Location_Service {

	const FILE_TYPE  = 'location';
	const STAFF_META = 'bizcity_crm_staff_last_location';
	const SERVICE_KIND = 'service';

	public static function register(): void {
		// [2026-09-23 PHASE-0.69 L-02] Fires after the message row already exists (Repository::insert_message
		// emits this post-write) — never chains into the inbound ingest path itself, so a parsing failure here
		// can never block a message from landing.
		add_action( 'bizcity_crm_event_crm_message_received', array( __CLASS__, 'on_message_received' ), 20, 1 );
	}

	public static function on_message_received( $payload ): void {
		try {
			if ( ! is_array( $payload ) || 'text' !== (string) ( $payload['content_type'] ?? '' ) ) {
				return;
			}
			$message_id = (int) ( $payload['message_id'] ?? 0 );
			if ( $message_id <= 0 ) {
				return;
			}
			self::record_from_message( $message_id );
		} catch ( Throwable $error ) {
			// Never let a parsing bug take down message ingest (same discipline as
			// `class-ai-autoreply-listener.php`'s listener).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[bizcity-crm-location] on_message_received failed: ' . $error->getMessage() );
			}
		}
	}

	/**
	 * @param array $message A row (or row-shaped array) from `bizcity_crm_messages`: needs at least
	 *                        `content` and, optionally, `ai_metadata_json`.
	 * @return array{lat:float,lng:float,label:?string,source:string}|null
	 */
	public static function extract_from_message( array $message ): ?array {
		// Source B first — richer (carries a label), when the quote_src payload is actually present.
		$meta = isset( $message['ai_metadata_json'] ) ? json_decode( (string) $message['ai_metadata_json'], true ) : null;
		if ( is_array( $meta ) ) {
			$params = $meta['quote_src']['content']['params'] ?? null;
			if ( is_array( $params ) && isset( $params['lat'], $params['lng'] ) && is_numeric( $params['lat'] ) && is_numeric( $params['lng'] ) ) {
				return array(
					'lat'    => (float) $params['lat'],
					'lng'    => (float) $params['lng'],
					'label'  => isset( $params['description'] ) ? sanitize_text_field( (string) $params['description'] ) : null,
					'source' => 'quote_src',
				);
			}
		}
		// Source A — regex on the flattened "📍 Vị trí: https://www.google.com/maps?q=<lat>,<lng>" text.
		$content = (string) ( $message['content'] ?? '' );
		if ( '' === $content ) {
			return null;
		}
		if ( preg_match( '/maps\?q=(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+)/', $content, $m ) ) {
			$lat = (float) $m[1];
			$lng = (float) $m[2];
			if ( $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 ) {
				return array( 'lat' => $lat, 'lng' => $lng, 'label' => null, 'source' => 'text_link' );
			}
		}
		return null;
	}

	/**
	 * Extract + persist for one message id. Idempotent — a message already recorded is skipped, so
	 * re-firing (retry, replay) never creates duplicate attachment rows.
	 *
	 * @return int|null Attachment id, or null when nothing was found / already recorded.
	 */
	public static function record_from_message( int $message_id ) {
		global $wpdb;
		if ( $message_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}
		$messages_table = self::messages_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$messages_table}` WHERE id = %d LIMIT 1", $message_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$attachments_table = self::attachments_table();
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$attachments_table}` WHERE message_id = %d AND file_type = %s LIMIT 1",
			$message_id,
			self::FILE_TYPE
		) );
		if ( $existing ) {
			return null;
		}
		$point = self::extract_from_message( $row );
		if ( null === $point ) {
			return null;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert( $attachments_table, array(
			'message_id' => $message_id,
			'file_type'  => self::FILE_TYPE,
			'data_url'   => null,
			'thumb_url'  => null,
			'meta_json'  => function_exists( 'wp_json_encode' ) ? wp_json_encode( $point ) : json_encode( $point ),
			'created_at' => $now,
		) );
		if ( ! $inserted ) {
			return null;
		}
		$attachment_id = (int) $wpdb->insert_id;

		// Customer location → mirror onto the contact's open service run (0.69 §4.4 L3). Staff's own
		// location never arrives this way (see class docblock) — only via the magic-link flow.
		if ( 'contact' === (string) ( $row['sender_type'] ?? '' ) && class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			$conversation_id = (int) ( $row['conversation_id'] ?? 0 );
			$contact_id = self::contact_for_conversation( $conversation_id );
			if ( $contact_id > 0 ) {
				$run_id = BizCity_CRM_Pipeline_Run_Service::open_run_id_for_contact( $contact_id, self::SERVICE_KIND );
				if ( $run_id > 0 ) {
					BizCity_CRM_Pipeline_Run_Service::set_service_address( $run_id, $point, $message_id );
				}
			}
		}

		return $attachment_id;
	}

	/** Last-known point for a staff member (written only by the magic-link consume flow). */
	public static function last_for_user( int $user_id ): ?array {
		if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return null;
		}
		$raw = get_user_meta( $user_id, self::STAFF_META, true );
		return is_array( $raw ) && isset( $raw['lat'], $raw['lng'] ) ? $raw : null;
	}

	/** Overwrite — this is a last-known-point index, not a history log (0.69 §4.4 L4). */
	public static function save_staff_location( int $user_id, array $point ): bool {
		if ( $user_id <= 0 || ! function_exists( 'update_user_meta' ) || ! isset( $point['lat'], $point['lng'] ) ) {
			return false;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		return (bool) update_user_meta( $user_id, self::STAFF_META, array(
			'lat'        => (float) $point['lat'],
			'lng'        => (float) $point['lng'],
			'at'         => $now,
			'message_id' => isset( $point['message_id'] ) ? (int) $point['message_id'] : null,
		) );
	}

	/** Great-circle distance in meters. Pure PHP — no library (0.69 §4.4 L02 note: "haversine thuần PHP"). */
	public static function distance_m( array $a, array $b ): float {
		if ( ! isset( $a['lat'], $a['lng'], $b['lat'], $b['lng'] ) ) {
			return -1.0;
		}
		$earth_radius_m = 6371000.0;
		$lat1 = deg2rad( (float) $a['lat'] );
		$lat2 = deg2rad( (float) $b['lat'] );
		$d_lat = $lat2 - $lat1;
		$d_lng = deg2rad( (float) $b['lng'] - (float) $a['lng'] );
		$h = sin( $d_lat / 2 ) ** 2 + cos( $lat1 ) * cos( $lat2 ) * sin( $d_lng / 2 ) ** 2;
		return $earth_radius_m * 2 * atan2( sqrt( $h ), sqrt( max( 0.0, 1 - $h ) ) );
	}

	/**
	 * Check-in distance verdict — advisory only (D69-2, chốt 2026-09-21: "Không chặn"). Never blocks the
	 * check-in transition; callers just attach `checkin_far` + an audit row when over the radius.
	 */
	public static function checkin_verdict( array $staff_point, array $service_address, int $radius_m = 150 ): array {
		$distance = self::distance_m( $staff_point, $service_address );
		return array( 'distance_m' => $distance, 'within_radius' => $distance >= 0 && $distance <= $radius_m, 'radius_m' => $radius_m );
	}

	private static function contact_for_conversation( int $conversation_id ): int {
		global $wpdb;
		if ( $conversation_id <= 0 || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}
		$table = class_exists( 'BizCity_CRM_DB_Installer_V2' ) && method_exists( 'BizCity_CRM_DB_Installer_V2', 'tbl_conversations' )
			? BizCity_CRM_DB_Installer_V2::tbl_conversations()
			: $wpdb->prefix . 'bizcity_crm_conversations';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM `{$table}` WHERE id = %d LIMIT 1", $conversation_id ) );
	}

	private static function messages_table(): string {
		global $wpdb;
		return class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_messages() : $wpdb->prefix . 'bizcity_crm_messages';
	}

	private static function attachments_table(): string {
		global $wpdb;
		return class_exists( 'BizCity_CRM_DB_Installer_V2' ) ? BizCity_CRM_DB_Installer_V2::tbl_attachments() : $wpdb->prefix . 'bizcity_crm_attachments';
	}
}
