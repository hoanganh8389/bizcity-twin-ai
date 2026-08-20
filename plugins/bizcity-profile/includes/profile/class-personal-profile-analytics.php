<?php
/**
 * Profile privacy-minimal analytics manager.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Analytics' ) ) { return; }

final class BizCity_Personal_Profile_Analytics {

	const CACHE_GROUP = 'bzp_profile_analytics';
	const EVENT_TYPES = array( 'view', 'qr_scan', 'qr_download', 'click_phone', 'click_email', 'click_map', 'click_link', 'click_social', 'click_message_app', 'save_contact', 'share' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_profile_analytics_events';
	}

	public static function record( $card_id, $event_type, array $meta = array() ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — write privacy-minimal analytics only for published cards.
		$card_id = (int) $card_id;
		$event_type = sanitize_key( (string) $event_type );
		if ( $card_id <= 0 || ! in_array( $event_type, self::EVENT_TYPES, true ) ) { return false; }
		$card = BizCity_Personal_Profile_Card_Manager::get_published( $card_id );
		if ( ! is_array( $card ) ) { return false; }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return false; }
		$visitor_hash = self::visitor_hash();
		if ( 'view' === $event_type && ! empty( $_GET['qr'] ) ) {
			$event_type = 'qr_scan';
		}
		if ( 'view' === $event_type ) {
			$rate_key = 'bzp_profile_view_' . $card_id . '_' . substr( $visitor_hash, 0, 24 );
			if ( false !== get_transient( $rate_key ) ) { return true; }
			set_transient( $rate_key, 1, DAY_IN_SECONDS );
		}
		global $wpdb;
		$ok = $wpdb->insert( self::table(), array(
			'card_id'      => $card_id,
			'event_type'   => $event_type,
			'visitor_hash' => $visitor_hash,
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( substr( (string) $_SERVER['HTTP_REFERER'], 0, 255 ) ) : '',
			'meta_json'    => $meta ? wp_json_encode( self::safe_meta( $meta ) ) : null,
		), array( '%d', '%s', '%s', '%s', '%s' ) );
		if ( false !== $ok && class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		return false !== $ok;
	}

	public static function counts_for_card( $card_id, $range = 30 ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — aggregate counts for dashboard/list views with bounded range.
		$card_id = (int) $card_id;
		$range = max( 1, min( 365, (int) $range ) );
		$key = 'counts_' . $card_id . '_' . $range;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return array(); }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, COUNT(*) AS total FROM `' . self::table() . '` WHERE card_id = %d AND occurred_at >= %s GROUP BY event_type', $card_id, gmdate( 'Y-m-d H:i:s', time() - ( $range * DAY_IN_SECONDS ) ) ), ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) { $counts[ (string) $row['event_type'] ] = (int) $row['total']; }
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $key, $counts, BizCity_Cache::TTL_SHORT ); }
		return $counts;
	}

	public static function report_for_card( $card_id, $range = 30 ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — aggregate trend and entrypoint attribution without a new analytics table.
		$card_id = (int) $card_id;
		$range = max( 1, min( 365, (int) $range ) );
		$key = 'report_' . $card_id . '_' . $range;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return array( 'counts' => array(), 'trend' => array(), 'channels' => array() ); }
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $range * DAY_IN_SECONDS ) );
		$trend_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(occurred_at) AS day, event_type, COUNT(*) AS total FROM `' . self::table() . '` WHERE card_id = %d AND occurred_at >= %s GROUP BY DATE(occurred_at), event_type ORDER BY day ASC', $card_id, $since ), ARRAY_A );
		$trend = array();
		foreach ( is_array( $trend_rows ) ? $trend_rows : array() as $row ) {
			$day = (string) $row['day'];
			$type = (string) $row['event_type'];
			if ( ! isset( $trend[ $day ] ) ) { $trend[ $day ] = array( 'day' => $day ); }
			$trend[ $day ][ $type ] = (int) $row['total'];
		}
		$meta_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_type, meta_json FROM `' . self::table() . '` WHERE card_id = %d AND occurred_at >= %s AND meta_json IS NOT NULL AND meta_json != %s LIMIT 5000', $card_id, $since, '' ), ARRAY_A );
		$channels = array();
		foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $row ) {
			$meta = json_decode( (string) $row['meta_json'], true );
			if ( ! is_array( $meta ) ) { continue; }
			$channel = sanitize_key( (string) ( $meta['channel_code'] ?? 'direct' ) );
			$tag = sanitize_key( (string) ( $meta['tracking_tag'] ?? $meta['trackingTag'] ?? '' ) );
			$key_part = $channel . '|' . $tag;
			if ( ! isset( $channels[ $key_part ] ) ) { $channels[ $key_part ] = array( 'channel_code' => $channel, 'tracking_tag' => $tag, 'events' => 0, 'interactions' => 0 ); }
			$channels[ $key_part ]['events']++;
			if ( 'view' !== (string) $row['event_type'] && 'qr_scan' !== (string) $row['event_type'] ) { $channels[ $key_part ]['interactions']++; }
		}
		$report = array( 'counts' => self::counts_for_card( $card_id, $range ), 'trend' => array_values( $trend ), 'channels' => array_values( $channels ) );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $key, $report, BizCity_Cache::TTL_SHORT ); }
		return $report;
	}

	private static function visitor_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : AUTH_KEY;
		return hash( 'sha256', $ip . '|' . $ua . '|' . gmdate( 'Y-m-d' ) . '|' . $salt );
	}

	private static function safe_meta( array $meta ) {
		$safe = array();
		foreach ( $meta as $key => $value ) {
			if ( ! is_scalar( $value ) ) { continue; }
			$safe[ sanitize_key( $key ) ] = sanitize_text_field( substr( (string) $value, 0, 80 ) );
		}
		return $safe;
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_analytics',
		'modules.personal.profile',
		array( 'counts_{card_id}_{range}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Profile event counts by card and range' ) )
	);
}
