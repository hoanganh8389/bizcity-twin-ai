<?php
/**
 * PHASE-0.57A W5 — signed public KG link service.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_KG_Public_Link_Service', false ) ) {
	return;
}

final class BizCity_KG_Public_Link_Service {
	const DEFAULT_TTL = 2592000;
	const MAX_TTL = 15552000;

	public static function create( $object_type, $object_id, array $doors, $actor_id, $ttl = self::DEFAULT_TTL ) {
		$object_type = sanitize_key( $object_type );
		$object_id = (int) $object_id;
		$actor_id = (int) $actor_id;
		if ( ! in_array( $object_type, array( 'workspace', 'notebook' ), true ) || $object_id <= 0 || ! BizCity_KG_Access::can_manage( $object_type, $object_id, $actor_id ) ) {
			return new WP_Error( 'kg_public_link_forbidden', 'Bạn không có quyền tạo link công khai.', array( 'status' => 403 ) );
		}
		if ( self::contains_channel_source( $object_type, $object_id ) ) {
			return new WP_Error( 'kg_public_link_channel_blocked', 'Nội dung kênh khách không thể phát hành bằng link công khai.', array( 'status' => 403 ) );
		}
		$ttl = max( 3600, min( self::MAX_TTL, (int) $ttl ) );
		$nonce = wp_generate_password( 32, false, false );
		$payload = array( 'typ' => $object_type, 'id' => $object_id, 'doors' => self::normalize_doors( $doors ), 'exp' => time() + $ttl, 'nonce' => $nonce );
		self::store_nonce( $object_type, $object_id, $nonce, $payload['doors'], $payload['exp'] );
		BizCity_KG_Access::bump_generation();
		return array( 'token' => self::encode( $payload ), 'expires_ts' => $payload['exp'], 'doors' => $payload['doors'], 'object_type' => $object_type, 'object_id' => $object_id );
	}

	public static function resolve( $token, $door = '' ) {
		$parts = explode( '.', trim( (string) $token ), 2 );
		if ( count( $parts ) !== 2 || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return new WP_Error( 'kg_public_link_invalid', 'Link công khai không hợp lệ.' );
		}
		$json = function_exists( 'base64_decode' ) ? base64_decode( strtr( $parts[0], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[0] ) % 4 ) % 4 ) ) : false;
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['typ'] ) || empty( $data['id'] ) || (int) ( $data['exp'] ?? 0 ) < time() || ! in_array( $door, self::normalize_doors( $data['doors'] ?? array() ), true ) ) {
			return new WP_Error( 'kg_public_link_expired', 'Link công khai đã hết hạn, bị thu hồi hoặc không mở cửa này.' );
		}
		$stored = get_option( self::nonce_option( $data['typ'], (int) $data['id'] ), array() );
		if ( ! is_array( $stored ) || empty( $stored['nonce'] ) || ! hash_equals( (string) $stored['nonce'], (string) ( $data['nonce'] ?? '' ) ) ) {
			return new WP_Error( 'kg_public_link_revoked', 'Link công khai đã bị thu hồi.' );
		}
		return $data;
	}

	public static function revoke( $object_type, $object_id, $actor_id ) {
		if ( ! BizCity_KG_Access::can_manage( $object_type, $object_id, $actor_id ) ) return new WP_Error( 'kg_public_link_forbidden', 'Bạn không có quyền thu hồi link.' );
		delete_option( self::nonce_option( $object_type, $object_id ) );
		BizCity_KG_Access::bump_generation();
		return true;
	}

	private static function contains_channel_source( $type, $id ) {
		if ( 'notebook' === $type ) return BizCity_KG_Access::notebook_has_channel_source( $id );
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . BizCity_KG_Database::instance()->tbl_notebooks() . " WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.workspace_id')) = %s", (string) $id ) );
		foreach ( $ids as $notebook_id ) if ( BizCity_KG_Access::notebook_has_channel_source( $notebook_id ) ) return true;
		return false;
	}

	private static function normalize_doors( $doors ) {
		return array_values( array_intersect( array( 'graph', 'ask', 'mcp' ), array_map( 'sanitize_key', (array) $doors ) ) );
	}
	private static function nonce_option( $type, $id ) { return 'bizcity_kg_public_link_' . sanitize_key( $type ) . '_' . (int) $id; }
	private static function store_nonce( $type, $id, $nonce, $doors, $exp ) { update_option( self::nonce_option( $type, $id ), array( 'nonce' => $nonce, 'doors' => $doors, 'exp' => $exp ), false ); }
	private static function encode( array $data ) { $body = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' ); return $body . '.' . self::sign( $body ); }
	private static function sign( $body ) { return substr( hash_hmac( 'sha256', (string) $body, wp_salt( 'auth' ) ), 0, 40 ); }
}