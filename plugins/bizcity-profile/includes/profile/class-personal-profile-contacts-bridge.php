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
	const CONVERSATIONS_CACHE_GROUP = 'bzp_profile_conversations';

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
		if ( ! $has_contact_table ) {
			return array();
		}
		$contact_ids      = array();
		$contact_cards    = array();
		if ( $has_submission_table ) {
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
		}

		// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — include Profile WebChat contacts from canonical CRM contact_inboxes, keyed by card session prefix.
		$contact_inbox_table = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$inbox_table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$has_contact_inbox_table = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $contact_inbox_table ) : BizCity_CRM_DB_Installer_V2::table_exists( $contact_inbox_table );
		$has_inbox_table = function_exists( 'bizcity_tbl_exists' ) ? bizcity_tbl_exists( $inbox_table ) : BizCity_CRM_DB_Installer_V2::table_exists( $inbox_table );
		if ( $has_contact_inbox_table && $has_inbox_table ) {
			foreach ( $pages as $page ) {
				// [2026-08-23 Johnny Chu] PHASE-TBP-6.2 — escape LIKE wildcards so card 1 cannot match card 10 sessions.
				$session_prefix = $wpdb->esc_like( 'profile_webchat_' . (int) $page['card_id'] . '_' ) . '%';
				$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT ci.contact_id FROM `' . $contact_inbox_table . '` ci INNER JOIN `' . $inbox_table . '` i ON i.id = ci.inbox_id WHERE i.channel_type = %s AND ci.source_id LIKE %s AND ci.contact_id > 0 LIMIT 200', 'webchat', $session_prefix ) );
				foreach ( (array) $ids as $id ) {
					$id = (int) $id;
					if ( $id > 0 ) { $contact_ids[ $id ] = $id; $contact_cards[ $id ] = (int) $page['card_id']; }
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

	public static function get_conversations_for_owner( $user_id, $limit = 50 ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-2 owner-scoped CRM conversation projection; no Profile conversation table.
		$user_id = (int) $user_id;
		$limit = max( 1, min( 100, (int) $limit ) );
		if ( $user_id <= 0 ) { return array(); }
		$key = 'owner_' . $user_id . '_limit_' . $limit;
		$cached = class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CONVERSATIONS_CACHE_GROUP, $key ) : false;
		if ( false !== $cached ) { return is_array( $cached ) ? $cached : array(); }
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'table_exists' ) ) { return array(); }

		$contacts = self::get_for_owner( $user_id );
		$contact_ids = array_values( array_filter( array_map( 'intval', wp_list_pluck( $contacts, 'id' ) ) ) );
		if ( empty( $contact_ids ) ) { return array(); }
		global $wpdb;
		$conversation_table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$message_table = BizCity_CRM_DB_Installer_V2::tbl_messages();
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $conversation_table ) ) { return array(); }
		$has_message_table = bizcity_tbl_exists( $message_table );
		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$args = array_merge( $contact_ids, array( (int) get_current_blog_id(), $limit ) );
		$last_message_sql = $has_message_table ? 'LEFT(COALESCE(m.body, m.content), 240)' : "''";
		$join_sql = $has_message_table ? ' LEFT JOIN `' . $message_table . '` m ON m.id = c.last_message_id' : '';
		$sql = 'SELECT c.id, c.status, c.priority, c.platform, c.chat_id, c.account_id, c.contact_id, c.last_activity_at, c.unread_count, ' . $last_message_sql . ' AS last_message FROM `' . $conversation_table . '` c' . $join_sql . ' WHERE c.contact_id IN (' . $placeholders . ') AND c.blog_id = %d ORDER BY c.last_activity_at DESC, c.id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$contact_cards = array();
		foreach ( $contacts as $contact ) { $contact_cards[ (int) ( $contact['id'] ?? 0 ) ] = (int) ( $contact['profile_card_id'] ?? 0 ); }
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['profile_card_id'] = (int) ( $contact_cards[ (int) ( $row['contact_id'] ?? 0 ) ] ?? 0 );
			$row['id'] = (int) ( $row['id'] ?? 0 );
			$row['contact_id'] = (int) ( $row['contact_id'] ?? 0 );
			$row['priority'] = (int) ( $row['priority'] ?? 0 );
			$row['unread_count'] = (int) ( $row['unread_count'] ?? 0 );
			$result[] = $row;
		}
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CONVERSATIONS_CACHE_GROUP, $key, $result, BizCity_Cache::TTL_SHORT ); }
		return $result;
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register(
		'bzp_profile_contacts',
		'modules.personal.profile',
		array( 'owner_{user_id}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Owner-scoped CRM contacts captured from Profile pages' ) )
	);
	BizCity_Cache_Registry::register(
		'bzp_profile_conversations',
		'modules.personal.profile',
		array( 'owner_{user_id}_limit_{limit}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'Owner-scoped CRM conversation projection for Profile dashboard' ) )
	);
}
