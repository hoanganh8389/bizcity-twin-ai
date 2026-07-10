<?php
/**
 * BizCity CRM — Customer Source: bizgpt-dino-tichdiem.
 *
 * Reads `{prefix}dino_users` (created by plugins/bizgpt-dino-tichdiem).
 * Phone-keyed records → all qualify (has_phone=true) so they land directly in
 * "Qualification" when synced into the Sales Pipeline.
 *
 * Self-disables silently when the dino plugin is not installed (no table).
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Source_Dino_Tichdiem implements BizCity_CRM_Customer_Source {

	public function code(): string  { return 'dino_tichdiem'; }
	public function label(): string { return 'Dino · Tích điểm'; }

	public function fetch_recent( ?int $since_ts, int $limit ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'dino_users';
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array();
		}

		$limit = max( 1, min( 500, (int) $limit ) );

		$sql    = "SELECT phone_number, full_name, email, address, total_points, last_activity_date
				FROM `{$tbl}` WHERE phone_number <> ''";
		$params = array();
		if ( $since_ts ) {
			$sql     .= ' AND last_activity_date >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $since_ts );
		}
		$sql .= " ORDER BY last_activity_date DESC LIMIT {$limit}";

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$phone = preg_replace( '/\D+/', '', (string) $r['phone_number'] );
			if ( '' === $phone ) {
				continue;
			}
			$name = trim( (string) ( $r['full_name'] ?? '' ) );
			if ( '' === $name ) {
				$name = 'Khách Dino ' . substr( $phone, -4 );
			}
			$out[] = array(
				'external_ref'     => $phone,
				'name'             => $name,
				'phone'            => $phone,
				'email'            => ! empty( $r['email'] ) ? (string) $r['email'] : null,
				'channel'          => 'loyalty',
				'has_phone'        => true,
				'last_activity_at' => $r['last_activity_date'] ?: null,
				'meta'             => array(
					'address'      => (string) ( $r['address'] ?? '' ),
					'total_points' => (int) ( $r['total_points'] ?? 0 ),
				),
			);
		}
		return $out;
	}
}
