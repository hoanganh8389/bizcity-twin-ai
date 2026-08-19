<?php
/**
 * Shared Guru Workspace focused notebook validator.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Guru_Focus_Validator', false ) ) {
	return;
}

final class BizCity_TwinBrain_Guru_Focus_Validator {

	public static function validate( $prompt, $user_id, $notebook_id ) {
		// [2026-08-16 Johnny Chu] P2 — shared tenant/attachment/owner scope boundary for @ notebook focus.
		if ( ! class_exists( 'BizCity_Guru_Token_Parser' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'guru_notebook_focus_unavailable', 'Không thể xác thực phạm vi notebook của Guru.', array( 'status' => 503 ) );
		}
		$parsed = BizCity_Guru_Token_Parser::parse( (string) $prompt );
		$guru_id = (int) ( $parsed['guru_id'] ?? 0 );
		if ( $guru_id <= 0 ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus cần Guru Workspace hợp lệ.', array( 'status' => 400 ) );
		}
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$guru_uuid = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT guru_uuid FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ) );
		if ( $guru_uuid === '' ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Guru Workspace không thuộc site hiện tại.', array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT n.owner_id, n.notebook_scope FROM ' . $db->tbl_notebooks() . ' n INNER JOIN ' . $db->tbl_notebook_character_attachments() . ' a ON a.notebook_id = n.id WHERE a.guru_uuid = %s AND n.id = %d LIMIT 1', $guru_uuid, (int) $notebook_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook này chưa được gắn vào Guru Workspace.', array( 'status' => 400 ) );
		}
		$owner_id = (int) ( $row['owner_id'] ?? 0 );
		$scope = sanitize_key( (string) ( $row['notebook_scope'] ?? 'personal' ) );
		$visible = ( $owner_id > 0 && $owner_id === (int) $user_id ) || ( $owner_id === 0 && in_array( $scope, array( 'business_kb', 'guru_kb' ), true ) );
		if ( ! $visible ) {
			return new WP_Error( 'guru_notebook_focus_invalid', 'Notebook focus không thuộc phạm vi được phép.', array( 'status' => 403 ) );
		}
		$guru = $wpdb->get_row( $wpdb->prepare( 'SELECT notebook_policy FROM ' . $wpdb->prefix . 'bizcity_characters WHERE id = %d LIMIT 1', $guru_id ), ARRAY_A );
		return array(
			'valid' => true,
			'guru_id' => $guru_id,
			'policy' => in_array( (string) ( $guru['notebook_policy'] ?? 'augment' ), array( 'augment', 'restrict' ), true ) ? (string) $guru['notebook_policy'] : 'augment',
		);
	}
}
