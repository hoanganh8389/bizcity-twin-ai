<?php
/**
 * Public vCard export for published Profile cards.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_VCard_Export' ) ) { return; }

final class BizCity_Personal_Profile_VCard_Export {

	public static function response( $card_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — export only published Profile data from the Page Builder source of truth.
		$card = BizCity_Personal_Profile_Card_Manager::get_published( $card_id );
		if ( ! is_array( $card ) ) { return new WP_Error( 'not_found', 'Không tìm thấy danh thiếp Profile công khai.', array( 'status' => 404 ) ); }
		global $wpdb;
		$project_table = $wpdb->prefix . 'bzpb_projects';
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $project_table ) ) { return new WP_Error( 'module_not_loaded', 'Page Builder project store chưa sẵn sàng.', array( 'status' => 503 ) ); }
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT site_config FROM `' . $project_table . '` WHERE id = %d LIMIT 1', (int) $card['bzpb_project_id'] ) );
		$config = $row ? json_decode( (string) $row->site_config, true ) : null;
		$props = self::profile_props( is_array( $config ) ? $config : array() );
		if ( ! $props ) { return new WP_Error( 'not_found', 'Profile card chưa có dữ liệu vCard.', array( 'status' => 404 ) ); }
		$lines = array( 'BEGIN:VCARD', 'VERSION:3.0' );
		$name = self::value( $props, 'name' );
		$lines[] = 'FN:' . self::escape( $name );
		$lines[] = 'N:' . self::escape( $name ) . ';;;;';
		if ( self::value( $props, 'jobTitle' ) ) { $lines[] = 'TITLE:' . self::escape( self::value( $props, 'jobTitle' ) ); }
		if ( self::value( $props, 'company' ) ) { $lines[] = 'ORG:' . self::escape( self::value( $props, 'company' ) ); }
		foreach ( is_array( $props['contactFields'] ?? null ) ? $props['contactFields'] : array() as $field ) {
			$type = sanitize_key( (string) ( $field['type'] ?? '' ) );
			$value = self::escape( (string) ( $field['value'] ?? '' ) );
			if ( 'email' === $type && $value ) { $lines[] = 'EMAIL;TYPE=INTERNET:' . $value; }
			if ( 'phone' === $type && $value ) { $lines[] = 'TEL;TYPE=CELL:' . $value; }
			if ( 'website' === $type && $value ) { $lines[] = 'URL:' . $value; }
			if ( 'address' === $type && $value ) { $lines[] = 'ADR:;;' . $value . ';;;;'; }
		}
		$lines[] = 'REV:' . gmdate( 'c' );
		$lines[] = 'END:VCARD';
		$response = new WP_REST_Response( implode( "\r\n", $lines ) . "\r\n", 200 );
		$response->header( 'Content-Type', 'text/vcard; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="profile-' . (int) $card_id . '.vcf"' );
		return $response;
	}

	private static function profile_props( array $config ) {
		foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) { if ( 'profile-card' === (string) ( $block['type'] ?? '' ) && is_array( $block['props'] ?? null ) ) { return $block['props']; } }
		return array();
	}

	private static function value( array $props, $key ) { return sanitize_text_field( (string) ( $props[ $key ] ?? '' ) ); }
	private static function escape( $value ) { return str_replace( array( '\\', ';', ',', "\r", "\n" ), array( '\\\\', '\\;', '\\,', '', '\\n' ), (string) $value ); }
}
