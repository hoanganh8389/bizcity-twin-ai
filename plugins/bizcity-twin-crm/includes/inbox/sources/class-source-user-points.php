<?php
/**
 * BizCity CRM — Customer Source: user-points plugin.
 *
 * Reads `{prefix}user_points` (created by plugins/user-points). Each row is
 * a single point-grant event; we collapse by phone to one prospect per number.
 * Self-disables when the user-points plugin is absent.
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Source_User_Points implements BizCity_CRM_Customer_Source {

	public function code(): string  { return 'user_points'; }
	public function label(): string { return 'User Points (legacy)'; }

	public function fetch_recent( ?int $since_ts, int $limit ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'user_points';
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array();
		}

		$limit = max( 1, min( 500, (int) $limit ) );

		// Collapse: one row per phone, taking latest `time`.
		$sql    = "SELECT phone,
				MAX(user_name) AS user_name,
				MAX(address)   AS address,
				SUM(CAST(user_points AS UNSIGNED)) AS points,
				MAX(time)      AS last_time
			FROM `{$tbl}`
			WHERE phone <> ''";
		$params = array();
		if ( $since_ts ) {
			$sql     .= ' AND time >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', (int) $since_ts );
		}
		$sql .= " GROUP BY phone ORDER BY last_time DESC LIMIT {$limit}";

		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$phone = preg_replace( '/\D+/', '', (string) $r['phone'] );
			if ( '' === $phone ) {
				continue;
			}
			$name = trim( (string) ( $r['user_name'] ?? '' ) );
			if ( '' === $name ) {
				$name = 'Khách UP ' . substr( $phone, -4 );
			}
			$out[] = array(
				'external_ref'     => $phone,
				'name'             => $name,
				'phone'            => $phone,
				'email'            => null,
				'channel'          => 'loyalty',
				'has_phone'        => true,
				'last_activity_at' => $r['last_time'] ?: null,
				'meta'             => array(
					'address' => (string) ( $r['address'] ?? '' ),
					'points'  => (int) ( $r['points'] ?? 0 ),
				),
			);
		}
		return $out;
	}
}
