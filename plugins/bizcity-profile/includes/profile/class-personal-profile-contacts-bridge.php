<?php
/**
 * Profile to CRM contacts bridge.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityProfile
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Contacts_Bridge' ) ) { return; }

final class BizCity_Personal_Profile_Contacts_Bridge {

	const CACHE_GROUP = 'bzp_profile_contacts';

	public static function get_for_owner( $user_id ) {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — map owned published pages through CF7 submissions into canonical CRM contacts.
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return array(); }
		$key    = 'owner_' . $user_id;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'table_exists' ) ) { return array(); }

		global $wpdb;
		$cards = BizCity_Personal_Profile_Card_Manager::get_by_owner( $user_id );
		$pages = array();
		foreach ( $cards as $card ) {
			$project = $wpdb->get_row( $wpdb->prepare( 'SELECT published_page_id FROM `' . $wpdb->prefix . 'bzpb_projects` WHERE id = %d AND user_id = %d LIMIT 1', (int) $card['bzpb_project_id'], $user_id ) );
			$page_id = $project ? (int) $project->published_page_id : 0;
			if ( $page_id > 0 && 'published' === (string) $card['status'] ) {
				$pages[ $page_id ] = array( 'card_id' => (int) $card['id'], 'url' => (string) get_permalink( $page_id ) );
			}
		}
		if ( ! $pages ) { return array(); }

		$submission_table = $wpdb->prefix . 'bizcity_cf7_submissions';
		$contact_table    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$has_contact_table = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $contact_table ) : BizCity_CRM_DB_Installer_V2::table_exists( $contact_table );
		$has_submission_table = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $submission_table ) : BizCity_CRM_DB_Installer_V2::table_exists( $submission_table );
		if ( ! $has_contact_table || ! $has_submission_table ) {
			return array();
		}
		$contact_ids      = array();
		$contact_cards    = array();
		foreach ( $pages as $page ) {
			if ( '' === $page['url'] ) { continue; }
			$canonical_url = esc_url_raw( $page['url'] );
			$url_variants = array_values( array_unique( array_filter( array( $canonical_url, trailingslashit( $canonical_url ), untrailingslashit( $canonical_url ) ) ) ) );
			$url_slots = implode( ',', array_fill( 0, count( $url_variants ), '%s' ) );
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT crm_contact_id FROM `' . $submission_table . '` WHERE crm_contact_id > 0 AND source_url IN (' . $url_slots . ') ORDER BY submitted_at DESC LIMIT 200', $url_variants ) );
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$contact_ids[ $id ] = $id;
					$contact_cards[ $id ] = $page['card_id'];
				}
			}
		}
		if ( ! $contact_ids ) { return array(); }

		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $contact_table . '` WHERE id IN (' . $placeholders . ') AND deleted_at IS NULL ORDER BY updated_at DESC', array_values( $contact_ids ) ), ARRAY_A );
		$result = array();
		foreach ( is_array( $contacts ) ? $contacts : array() as $contact ) {
			$contact['profile_card_id'] = (int) ( $contact_cards[ (int) $contact['id'] ] ?? 0 );
			$result[] = $contact;
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $result, BizCity_Cache::TTL_SHORT );
		}
		return $result;
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_contacts',
		'modules.personal.profile',
		array( 'owner_{user_id}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Owner-scoped CRM contacts captured from Profile pages' ) )
	);
}
