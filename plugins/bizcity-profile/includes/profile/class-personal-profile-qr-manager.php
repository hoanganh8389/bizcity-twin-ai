<?php
/**
 * Profile QR style manager.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_QR_Manager' ) ) { return; }

final class BizCity_Personal_Profile_QR_Manager {

	const CACHE_GROUP = 'bzp_profile_qr';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_profile_qrcodes';
	}

	public static function defaults() {
		return array(
			'fgColor' => '#172033',
			'bgColor' => '#ffffff',
			'size' => 360,
			'logoUrl' => '',
			'dotsStyle' => 'rounded',
			'cornerSquareStyle' => 'extra-rounded',
			'cornerDotStyle' => 'dot',
			'frameText' => '',
		);
	}

	public static function get_for_owner( $card_id, $user_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped QR style read.
		$card = BizCity_Personal_Profile_Card_Manager::get( $card_id, $user_id );
		if ( ! is_array( $card ) ) { return null; }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return null; }
		$key = 'card_' . (int) $card_id . '_' . (int) $user_id;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : null; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE card_id = %d LIMIT 1', (int) $card_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			$row = array( 'id' => 0, 'card_id' => (int) $card_id, 'target_type' => 'profile_page', 'custom_url' => '', 'style_json' => wp_json_encode( self::defaults() ), 'download_count' => 0 );
		}
		$row['style'] = self::decode_style( $row['style_json'] );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $key, $row, BizCity_Cache::TTL_MEDIUM ); }
		return $row;
	}

	public static function save( $card_id, $user_id, array $payload ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — validate and persist only QR style/target fields.
		$card = BizCity_Personal_Profile_Card_Manager::get( $card_id, $user_id );
		if ( ! is_array( $card ) ) { return new WP_Error( 'not_found', 'Không tìm thấy danh thiếp Profile.', array( 'status' => 404 ) ); }
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( self::table() ) ) { return new WP_Error( 'module_not_loaded', 'Kho QR Profile chưa được provision.', array( 'status' => 503 ) ); }
		$style = isset( $payload['style'] ) && is_array( $payload['style'] ) ? $payload['style'] : $payload;
		$style = self::sanitize_style( $style );
		$target_type = sanitize_key( (string) ( $payload['target_type'] ?? 'profile_page' ) );
		if ( ! in_array( $target_type, array( 'profile_page', 'vcard_file', 'custom_url' ), true ) ) { return new WP_Error( 'invalid_param', 'Kiểu đích QR không hợp lệ.', array( 'status' => 400 ) ); }
		$custom_url = isset( $payload['custom_url'] ) ? esc_url_raw( (string) $payload['custom_url'] ) : '';
		if ( 'custom_url' === $target_type && ( '' === $custom_url || ! wp_http_validate_url( $custom_url ) ) ) { return new WP_Error( 'invalid_param', 'Custom URL của QR không hợp lệ.', array( 'status' => 400 ) ); }
		global $wpdb;
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . self::table() . '` WHERE card_id = %d LIMIT 1', (int) $card_id ) );
		$data = array( 'card_id' => (int) $card_id, 'target_type' => $target_type, 'custom_url' => 'custom_url' === $target_type ? $custom_url : '', 'style_json' => wp_json_encode( $style ) );
		if ( $existing ) { $ok = $wpdb->update( self::table(), $data, array( 'id' => (int) $existing ), array( '%d', '%s', '%s', '%s' ), array( '%d' ) ); } else { $ok = $wpdb->insert( self::table(), $data, array( '%d', '%s', '%s', '%s' ) ); }
		if ( false === $ok ) { return new WP_Error( 'db_error', 'Không thể lưu cấu hình QR.', array( 'status' => 500 ) ); }
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		return self::get_for_owner( $card_id, $user_id );
	}

	private static function sanitize_style( array $style ) {
		$defaults = self::defaults();
		$out = $defaults;
		foreach ( array( 'fgColor', 'bgColor' ) as $key ) {
			$value = isset( $style[ $key ] ) ? sanitize_hex_color( (string) $style[ $key ] ) : false;
			if ( $value ) { $out[ $key ] = $value; }
		}
		$out['size'] = max( 100, min( 1000, (int) ( $style['size'] ?? $defaults['size'] ) ) );
		$out['logoUrl'] = isset( $style['logoUrl'] ) ? esc_url_raw( (string) $style['logoUrl'] ) : '';
		foreach ( array( 'dotsStyle', 'cornerSquareStyle', 'cornerDotStyle' ) as $key ) { $out[ $key ] = sanitize_key( (string) ( $style[ $key ] ?? $defaults[ $key ] ) ); }
		$out['frameText'] = sanitize_text_field( substr( (string) ( $style['frameText'] ?? '' ), 0, 80 ) );
		return $out;
	}

	private static function decode_style( $json ) {
		$value = json_decode( (string) $json, true );
		return self::sanitize_style( is_array( $value ) ? $value : array() );
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register( 'bzp_profile_qr', 'modules.personal.profile', array( 'card_{card_id}_{user_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Owner-scoped QR style configuration' ) ) );
}
