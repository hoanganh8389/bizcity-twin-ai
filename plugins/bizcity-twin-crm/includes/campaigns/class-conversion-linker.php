<?php
/**
 * BizCity CRM — Campaign Conversion Linker (PHASE 0.35 M6.W4).
 *
 * Bridges M6.W3 visit rows to actual CRM contacts. The contract:
 *
 *   1. M6.W3 records a `crm_campaign_visits` row with `client_id` (NEVER
 *      `contact_id` — at scan-time we don't know who the person is yet).
 *      For FB Messenger ref, `client_id = "fb_<page>_<psid>"`. For web/pixel,
 *      it's `"web_<24-char-cookie>"`.
 *
 *   2. When the **first** inbound message from that same client_id lands
 *      (FB ingestor builds chat_id `fb_<page>_<psid>`; web widget would be
 *      `web_widget_<...>`), the FB Ingestor calls
 *      BizCity_CRM_Repository::insert_message() which emits
 *      `crm_message_received`. We subscribe and:
 *        - resolve client_id ↔ contact via the conversation row,
 *        - find the most recent visit row in the lookback window
 *          (default 30d) where `contact_id IS NULL`,
 *        - stamp `contact_id` + `converted_contact_id` + `converted_at`.
 *
 *   3. Emit `crm_campaign_conversion_recorded` so M2 Automation Engine + M6.W5
 *      Loyalty Bridge can subscribe (e.g. award points, send welcome).
 *
 * Web-side conversion linking (when an anonymous web visitor later submits a
 * web-widget conversation) is wired through the same hook: the web-widget
 * adapter normalizes its inbound to `chat_id = web_widget_<token>` while the
 * tracker stamps the visit with the same web cookie. We expose a small public
 * helper `link_web_cookie_to_contact()` so the web widget JS can map the
 * cookie → contact when an authenticated user later opens chat.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 (M6.W4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Campaign_Conversion_Linker {

	/** Lookback window — visits older than this are ignored as "expired" leads. */
	const LOOKBACK_DAYS = 30;

	public static function register(): void {
		// Subscribe AFTER Repository::insert_message() emits its event. Priority
		// 20 keeps us behind core listeners (SLA evaluator, automation engine).
		//
		// IMPORTANT: BizCity_CRM_Event_Emitter::emit() fans out via
		// `bizcity_crm_event_<type>` — NOT the raw event name. Subscribing to the
		// raw `crm_message_received` would silently never fire (BUG fixed M6.W12).
		add_action( 'bizcity_crm_event_crm_message_received', array( __CLASS__, 'on_message_received' ), 20, 1 );
	}

	/* ============================================================
	 * Inbound listener — primary conversion trigger
	 * ============================================================ */

	/**
	 * @param array $payload {
	 *   message_id, conversation_id, inbox_id, sender_type ('contact'),
	 *   content_type, external_source_id, has_ai_metadata
	 * }
	 */
	public static function on_message_received( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		if ( ( $payload['sender_type'] ?? '' ) !== 'contact' ) { return; }

		$conv_id = (int) ( $payload['conversation_id'] ?? 0 );
		if ( $conv_id <= 0 ) { return; }

		$resolved = self::resolve_client_id_for_conversation( $conv_id );
		if ( ! $resolved ) { return; }

		self::link_visit( $resolved['client_id'], (int) $resolved['contact_id'] );
	}

	/* ============================================================
	 * Core: link the most recent open visit to a contact
	 * ============================================================ */

	/**
	 * Stamp the most recent visit (within lookback) for `client_id` with
	 * `contact_id` + mark conversion. Returns the visit_id linked, or 0 when
	 * nothing eligible was found.
	 *
	 * Idempotent — calling twice with the same args is a no-op.
	 *
	 * @return int visit_id (0 if none linked)
	 */
	public static function link_visit( string $client_id, int $contact_id ): int {
		global $wpdb;
		if ( $client_id === '' || $contact_id <= 0 ) { return 0; }

		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_campaign_visits();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::LOOKBACK_DAYS * DAY_IN_SECONDS );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, campaign_id, contact_id, converted_contact_id
			   FROM {$tbl}
			  WHERE client_id = %s
			    AND created_at >= %s
			  ORDER BY id DESC
			  LIMIT 1",
			$client_id, $since
		), ARRAY_A );
		if ( ! $row ) { return 0; }

		$visit_id           = (int) $row['id'];
		$existing_contact   = (int) $row['contact_id'];
		$existing_converted = (int) $row['converted_contact_id'];

		// Already converted to this contact → nothing to do.
		if ( $existing_converted === $contact_id ) { return $visit_id; }

		$now = current_time( 'mysql' );
		$update = array( 'contact_id' => $contact_id );
		// Only fill conversion if not already set — first inbound wins.
		if ( $existing_converted <= 0 ) {
			$update['converted_contact_id'] = $contact_id;
			$update['converted_at']         = $now;
		}
		$ok = $wpdb->update( $tbl, $update, array( 'id' => $visit_id ) );
		if ( $ok === false ) { return 0; }

		// Only emit when this is the first conversion (avoid duplicate downstream events).
		if ( $existing_converted <= 0 ) {
			$campaign_id = (int) $row['campaign_id'];
			$campaign    = class_exists( 'BizCity_CRM_Campaign_Repository' )
				? BizCity_CRM_Campaign_Repository::get( $campaign_id )
				: null;
			BizCity_CRM_Event_Emitter::emit( 'crm_campaign_conversion_recorded', array(
				'visit_id'    => $visit_id,
				'campaign_id' => $campaign_id,
				'code'        => $campaign['code']                 ?? '',
				'name'        => $campaign['name']                 ?? '',
				'loyalty_points_award' => (int) ( $campaign['loyalty_points_award'] ?? 0 ),
				'client_id'   => $client_id,
				'contact_id'  => $contact_id,
				'converted_at'=> $now,
			) );
		}

		return $visit_id;
	}

	/**
	 * Public helper for the web widget — map a `bizcity_crm_visitor` cookie
	 * value (the suffix used when the tracker built `web_<cookie>`) to a
	 * known contact. M-CRM.M3 / FE web widget calls this when a logged-in
	 * user opens chat for the first time after a campaign scan.
	 *
	 * @return int visit_id (0 if no eligible visit found)
	 */
	public static function link_web_cookie_to_contact( string $visitor_cookie, int $contact_id ): int {
		$cookie = preg_replace( '/[^a-zA-Z0-9_-]/', '', $visitor_cookie );
		if ( $cookie === '' ) { return 0; }
		return self::link_visit( 'web_' . $cookie, $contact_id );
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	/**
	 * Walk conversation_id → contact_id + adapter-aware client_id key.
	 *
	 * Mirrors how M6.W3 derived `client_id`:
	 *   - facebook adapter inbox.channel_ref_id = page_id
	 *     → client_id = "fb_<page_id>_<source_id (PSID)>"
	 *   - zalo bot adapter
	 *     → client_id = "zalobot_<bot_id>_<uid>"
	 *   - web widget adapter inbox.channel_ref_id = widget token
	 *     → client_id = "web_widget_<source_id>" (matches W3 web mode AFTER
	 *       link_web_cookie_to_contact was called once).
	 *
	 * Returns NULL when we can't form a deterministic client_id (e.g. unknown
	 * adapter code) — those messages just don't trigger conversion linking.
	 *
	 * @return array|null { client_id, contact_id }
	 */
	public static function resolve_client_id_for_conversation( int $conv_id ): ?array {
		global $wpdb;

		$conv_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ci_tbl    = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$inbox_tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id          AS conv_id,
			        c.contact_inbox_id,
			        ci.contact_id,
			        ci.source_id,
			        i.channel_type,
			        i.channel_ref_id
			   FROM {$conv_tbl}  c
			   JOIN {$ci_tbl}    ci ON ci.id = c.contact_inbox_id
			   JOIN {$inbox_tbl} i  ON i.id  = c.inbox_id
			  WHERE c.id = %d
			  LIMIT 1",
			$conv_id
		), ARRAY_A );

		if ( ! $row || empty( $row['contact_id'] ) || empty( $row['source_id'] ) ) {
			return null;
		}

		$client_id = self::compose_client_id( (string) $row['channel_type'], (string) $row['channel_ref_id'], (string) $row['source_id'] );
		if ( $client_id === '' ) { return null; }

		return array(
			'client_id'  => $client_id,
			'contact_id' => (int) $row['contact_id'],
		);
	}

	/**
	 * Build the same `client_id` string that M6.W3 stamped on visit rows.
	 * Public so unit tests + diag can reuse the exact mapping rule.
	 */
	public static function compose_client_id( string $channel_type, string $channel_ref_id, string $source_id ): string {
		$ref = $channel_ref_id;
		// Comment inbox uses ref "fb_feed_{page_id}" — strip prefix to recover page_id.
		if ( strpos( $ref, 'fb_feed_' ) === 0 ) { $ref = substr( $ref, 8 ); }

		switch ( $channel_type ) {
			case 'facebook':
				return $ref !== '' && $source_id !== '' ? 'fb_' . $ref . '_' . $source_id : '';
			case 'zalo':
				// Bot path uses "zalobot_<bot_id>_<uid>"; OA path uses "zalo_<oa_id>_<uid>".
				// We don't know which from inbox row alone — try bot first since it's the
				// gateway-bridge default.
				return $ref !== '' && $source_id !== '' ? 'zalobot_' . $ref . '_' . $source_id : '';
			case 'instagram':
				return $ref !== '' && $source_id !== '' ? 'ig_' . $ref . '_' . $source_id : '';
			case 'whatsapp_cloud':
				return $ref !== '' && $source_id !== '' ? 'wa_' . $ref . '_' . $source_id : '';
			case 'telegram':
				return $ref !== '' && $source_id !== '' ? 'tg_' . $ref . '_' . $source_id : '';
			case 'web_widget':
				return $source_id !== '' ? 'web_widget_' . $source_id : '';
			default:
				return '';
		}
	}
}
