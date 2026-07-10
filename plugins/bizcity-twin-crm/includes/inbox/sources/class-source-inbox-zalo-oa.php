<?php
/**
 * BizCity CRM — Customer Source: Inbox Zalo OA.
 *
 * Yields every CRM contact that has at least one contact_inbox row tied to a
 * Zalo Official Account inbox. Used by Pipeline_Sync to seed the "Prospecting"
 * column. Phone presence on the canonical bizcity_crm_contacts row drives the
 * prospecting → qualification promotion.
 *
 * Zone: ZONE 1 — Kênh Khách Hàng (zalo_oa). KHÔNG dùng cho zalo_bot.
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.1
 */

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-4 — Zalo OA Customer Source adapter để lấp gap Sales Pipeline

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Source_Inbox_Zalo_OA implements BizCity_CRM_Customer_Source {

	public function code(): string  { return 'zalo_oa'; }
	public function label(): string { return 'Inbox · Zalo OA'; }

	public function fetch_recent( ?int $since_ts, int $limit ): array {
		global $wpdb;

		$inboxes         = $wpdb->prefix . 'bizcity_crm_inboxes';
		$contact_inboxes = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
		$contacts        = $wpdb->prefix . 'bizcity_crm_contacts';

		// Guard: tables may not exist on a brand-new install.
		foreach ( array( $inboxes, $contact_inboxes, $contacts ) as $t ) {
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $t ) ) {
				return array();
			}
		}

		$limit = max( 1, min( 500, (int) $limit ) );

		// Zone-safe: only pull from Zalo OA inboxes (Zone 1 — customer channel).
		// zalo_bot (Zone 2 — admin) is intentionally excluded.
		$where  = "i.channel_type = 'zalo_oa' AND c.deleted_at IS NULL";
		$params = array();
		if ( $since_ts ) {
			$where   .= ' AND (ci.last_seen_at >= %s OR c.updated_at >= %s)';
			$dt       = gmdate( 'Y-m-d H:i:s', (int) $since_ts );
			$params[] = $dt;
			$params[] = $dt;
		}

		$sql = "
			SELECT
				ci.source_id      AS zalo_uid,
				c.id              AS contact_id,
				c.name            AS contact_name,
				c.first_name      AS first_name,
				c.last_name       AS last_name,
				c.phone           AS phone,
				c.email           AS email,
				ci.last_seen_at   AS last_seen_at,
				c.updated_at      AS contact_updated_at
			FROM `{$contact_inboxes}` ci
			INNER JOIN `{$inboxes}`  i ON i.id = ci.inbox_id
			INNER JOIN `{$contacts}` c ON c.id = ci.contact_id
			WHERE {$where}
			ORDER BY COALESCE(ci.last_seen_at, c.updated_at) DESC
			LIMIT {$limit}
		";

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$uid = (string) $r['zalo_uid'];
			if ( '' === $uid ) {
				continue;
			}
			$phone = isset( $r['phone'] ) ? self::norm_phone( (string) $r['phone'] ) : null;
			$name  = trim( (string) ( $r['contact_name'] ?? '' ) );
			if ( '' === $name ) {
				$name = trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) );
			}
			if ( '' === $name ) {
				$name = 'Khách Zalo ' . substr( $uid, -4 );
			}

			$out[] = array(
				'external_ref'     => $uid,
				'name'             => $name,
				'phone'            => $phone ?: null,
				'email'            => $r['email'] ? (string) $r['email'] : null,
				'channel'          => 'zalo_oa',
				'has_phone'        => (bool) $phone,
				'last_activity_at' => $r['last_seen_at'] ?: $r['contact_updated_at'],
				'meta'             => array(
					'contact_id' => (int) $r['contact_id'],
					'zalo_uid'   => $uid,
				),
			);
		}
		return $out;
	}

	/**
	 * Best-effort Vietnamese phone normalizer. Returns '' if not phone-like.
	 * PHP 7.4-safe: no str_starts_with().
	 *
	 * @param string $raw Raw phone string.
	 * @return string
	 */
	private static function norm_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}
		// Strip leading 84 (VN country code) → leading 0.  PHP 7.4: no str_starts_with().
		if ( strlen( $digits ) >= 11 && substr( $digits, 0, 2 ) === '84' ) {
			$digits = '0' . substr( $digits, 2 );
		}
		// Plausible VN mobile/landline: 9–11 digits, starts with 0.
		if ( strlen( $digits ) < 9 || strlen( $digits ) > 11 ) {
			return '';
		}
		if ( '0' !== $digits[0] ) {
			return '';
		}
		return $digits;
	}
}
