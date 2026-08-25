<?php
/**
 * BizCity Profile card registry manager.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Card_Manager' ) ) { return; }

/**
 * Cache Contract:
 * - Group: bzp_profile_cards
 * - Keys: owner_{user_id}, id_{card_id}
 * - TTL: medium
 * - Invalidate: every successful create/update/delete
 */
final class BizCity_Personal_Profile_Card_Manager {

	const CACHE_GROUP = 'bzp_profile_cards';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_profile_cards';
	}

	public static function get_by_owner( $user_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — owner-scoped card list with cache-first reads.
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return array(); }
		if ( ! self::table_ready() ) { return array(); }
		$key    = 'owner_' . $user_id;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE owner_user_id = %d ORDER BY updated_at DESC, id DESC', $user_id ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $rows, BizCity_Cache::TTL_MEDIUM );
		}
		return $rows;
	}

	public static function get( $card_id, $user_id = 0 ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — resolve a card only inside its owner boundary.
		$card_id = (int) $card_id;
		$user_id = (int) $user_id;
		if ( $card_id <= 0 ) { return null; }
		if ( ! self::table_ready() ) { return null; }
		$key    = 'id_' . $card_id . '_' . $user_id;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : null; }

		global $wpdb;
		$sql    = 'SELECT * FROM `' . self::table() . '` WHERE id = %d';
		$args   = array( $card_id );
		if ( $user_id > 0 ) {
			$sql   .= ' AND owner_user_id = %d';
			$args[] = $user_id;
		}
		$sql   .= ' LIMIT 1';
		$row    = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$row    = is_array( $row ) ? $row : null;
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $row, BizCity_Cache::TTL_MEDIUM );
		}
		return $row;
	}

	public static function get_published( $card_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — public context may resolve only a published card.
		$card_id = (int) $card_id;
		if ( $card_id <= 0 ) { return null; }
		if ( ! self::table_ready() ) { return null; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . self::table() . '` WHERE id = %d AND status = %s LIMIT 1', $card_id, 'published' ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $data ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — create an owner-scoped registry row only.
		$owner_user_id  = isset( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : 0;
		$project_id     = isset( $data['bzpb_project_id'] ) ? (int) $data['bzpb_project_id'] : 0;
		$label          = isset( $data['label'] ) ? sanitize_text_field( $data['label'] ) : '';
		$template_key   = isset( $data['template_key'] ) ? sanitize_key( $data['template_key'] ) : '';
		if ( $owner_user_id <= 0 || $project_id <= 0 ) { return 0; }
		if ( ! self::table_ready() ) { return 0; }

		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'owner_user_id'   => $owner_user_id,
				'bzpb_project_id' => $project_id,
				'label'           => $label,
				'template_key'    => $template_key,
				'status'          => 'draft',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $ok ) { return 0; }
		self::flush( $owner_user_id, (int) $wpdb->insert_id );
		return (int) $wpdb->insert_id;
	}

	public static function update( $card_id, $user_id, array $data ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — update mutable registry fields after ownership resolution.
		$card = self::get( $card_id, $user_id );
		if ( ! is_array( $card ) ) { return false; }
		$update = array();
		$formats = array();
		if ( array_key_exists( 'label', $data ) ) {
			$update['label'] = sanitize_text_field( $data['label'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'template_key', $data ) ) {
			$update['template_key'] = sanitize_key( (string) $data['template_key'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], array( 'draft', 'published', 'archived' ), true ) ) {
			$update['status'] = $data['status'];
			$formats[] = '%s';
		}
		if ( ! $update ) { return true; }

		global $wpdb;
		$ok = $wpdb->update( self::table(), $update, array( 'id' => (int) $card_id, 'owner_user_id' => (int) $user_id ), $formats, array( '%d', '%d' ) );
		if ( false !== $ok ) { self::flush( $user_id, $card_id ); }
		return false !== $ok;
	}

	public static function delete( $card_id, $user_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — delete registry row without deleting the Page Builder project.
		$card = self::get( $card_id, $user_id );
		if ( ! is_array( $card ) ) { return false; }
		global $wpdb;
		$ok = $wpdb->delete( self::table(), array( 'id' => (int) $card_id, 'owner_user_id' => (int) $user_id ), array( '%d', '%d' ) );
		if ( false !== $ok ) { self::flush( $user_id, $card_id ); }
		return false !== $ok;
	}

	private static function flush( $user_id, $card_id ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	private static function table_ready() {
		return function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( self::table() );
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_cards',
		'modules.personal.profile',
		array(
			'owner_{user_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Owner-scoped Profile card list' ),
			'id_{card_id}_{user_id}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Owner-scoped Profile card row' ),
		)
	);
}
