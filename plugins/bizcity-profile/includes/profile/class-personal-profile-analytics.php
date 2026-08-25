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
	const EVENT_TYPES = array( 'view', 'qr_scan', 'qr_download', 'click_phone', 'click_email', 'click_map', 'click_link', 'click_social', 'click_message_app', 'save_contact', 'share', 'chat_open', 'chat_message', 'contact_submitted', 'gift_wheel_play', 'gift_wheel_win' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_profile_analytics_events';
	}

	public static function record( $card_id, $event_type, array $meta = array() ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — write privacy-minimal analytics only for published cards.
		$card_id = (int) $card_id;
		$event_type = sanitize_key( (string) $event_type );
		if ( $card_id <= 0 || ! in_array( $event_type, self::EVENT_TYPES, true ) ) { return false; }
		if ( 'view' === $event_type && ! empty( $_GET['qr'] ) ) {
			$event_type = 'qr_scan';
		}
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — append redacted traffic evidence before any Profile DB lookup.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( 'profile', BizCity_Channel_File_Logger::LEVEL_INFO, 'traffic_' . $event_type, 'Profile traffic event received.', array(
				'card_id'      => $card_id,
				'event_type'   => $event_type,
				'channel_code' => $meta['channel_code'] ?? '',
				'tracking_tag' => $meta['tracking_tag'] ?? $meta['trackingTag'] ?? '',
				'funnel'       => $meta['funnel'] ?? '',
			) );
		}
		$card = BizCity_Personal_Profile_Card_Manager::get_published( $card_id );
		if ( ! is_array( $card ) ) { return false; }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return false; }
		$visitor_hash = self::visitor_hash();
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
		// [2026-08-21 Johnny Chu] R-CACHE/R-MSDB — dimension analytics cache by tenant blog as well as card and range.
		$key = self::cache_scope() . '_counts_' . $card_id . '_' . $range;
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
		// [2026-08-21 Johnny Chu] R-CACHE/R-MSDB — prevent report projection collisions between multisite tenants.
		$key = self::cache_scope() . '_report_v2_' . $card_id . '_' . $range;
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
		$click_facebook = 0;
		foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $row ) {
			$meta = json_decode( (string) $row['meta_json'], true );
			if ( ! is_array( $meta ) ) { continue; }
			if ( 'click_social' === (string) $row['event_type'] && 'facebook' === sanitize_key( (string) ( $meta['social'] ?? '' ) ) ) {
				$click_facebook++;
			}
			$channel = sanitize_key( (string) ( $meta['channel_code'] ?? 'direct' ) );
			$tag = sanitize_key( (string) ( $meta['tracking_tag'] ?? $meta['trackingTag'] ?? '' ) );
			$key_part = $channel . '|' . $tag;
			if ( ! isset( $channels[ $key_part ] ) ) { $channels[ $key_part ] = array( 'channel_code' => $channel, 'tracking_tag' => $tag, 'events' => 0, 'interactions' => 0 ); }
			$channels[ $key_part ]['events']++;
			if ( 'view' !== (string) $row['event_type'] && 'qr_scan' !== (string) $row['event_type'] ) { $channels[ $key_part ]['interactions']++; }
		}
		$counts = self::counts_for_card( $card_id, $range );
		$report = array(
			'counts'  => $counts,
			'metrics' => array(
				'views'          => (int) ( $counts['view'] ?? 0 ),
				'click_phone'    => (int) ( $counts['click_phone'] ?? 0 ),
				'click_email'    => (int) ( $counts['click_email'] ?? 0 ),
				'click_facebook' => $click_facebook,
				'click_social'   => (int) ( $counts['click_social'] ?? 0 ),
				'chat_open'      => (int) ( $counts['chat_open'] ?? 0 ),
				'chat_message'   => (int) ( $counts['chat_message'] ?? 0 ),
				'contacts'       => (int) ( $counts['contact_submitted'] ?? 0 ),
			),
			'trend'   => array_values( $trend ),
			'channels' => array_values( $channels ),
			'funnel'  => self::funnel_for_card( $card_id, $range ),
		);
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $key, $report, BizCity_Cache::TTL_SHORT ); }
		return $report;
	}

	public static function funnel_for_card( $card_id, $range = 30 ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — measure share-to-chat/contact cohorts using anonymous visitor hashes only.
		$card_id = (int) $card_id;
		$range = max( 1, min( 365, (int) $range ) );
		$key = self::cache_scope() . '_funnel_' . $card_id . '_' . $range;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }
		$empty = array( 'shares' => 0, 'chat_open' => 0, 'chat_message' => 0, 'contacts' => 0, 'chat_rate' => 0, 'contact_rate' => 0 );
		if ( $card_id <= 0 || ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return $empty; }
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $range * DAY_IN_SECONDS ) );
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(DISTINCT s.visitor_hash) AS shares, COUNT(DISTINCT CASE WHEN f.event_type = %s THEN s.visitor_hash END) AS chat_open, COUNT(DISTINCT CASE WHEN f.event_type = %s THEN s.visitor_hash END) AS chat_message, COUNT(DISTINCT CASE WHEN f.event_type = %s THEN s.visitor_hash END) AS contacts FROM `' . $table . '` s LEFT JOIN `' . $table . '` f ON f.card_id = s.card_id AND f.visitor_hash = s.visitor_hash AND f.occurred_at >= s.occurred_at AND f.occurred_at >= %s AND f.event_type IN (%s,%s,%s) WHERE s.card_id = %d AND s.event_type = %s AND s.occurred_at >= %s', 'chat_open', 'chat_message', 'contact_submitted', $since, 'chat_open', 'chat_message', 'contact_submitted', $card_id, 'share', $since ), ARRAY_A );
		$shares = (int) ( $row['shares'] ?? 0 );
		$result = array( 'shares' => $shares, 'chat_open' => (int) ( $row['chat_open'] ?? 0 ), 'chat_message' => (int) ( $row['chat_message'] ?? 0 ), 'contacts' => (int) ( $row['contacts'] ?? 0 ), 'chat_rate' => $shares > 0 ? round( ( (int) ( $row['chat_open'] ?? 0 ) / $shares ) * 100, 1 ) : 0, 'contact_rate' => $shares > 0 ? round( ( (int) ( $row['contacts'] ?? 0 ) / $shares ) * 100, 1 ) : 0 );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $key, $result, BizCity_Cache::TTL_SHORT ); }
		return $result;
	}

	private static function cache_scope() {
		return 'blog_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 );
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
