<?php
/**
 * CRM bridge for the legacy user-points ledger.
 *
 * @package BizCity_Twin_CRM
 * @subpackage Campaigns
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_UserPoints_Contact_Bridge' ) ) {
	return;
}

final class BizCity_CRM_UserPoints_Contact_Bridge {

	public static function register(): void {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — register ledger-to-contact listener.
		add_action( 'bizcity_user_points_ledger_written', array( __CLASS__, 'on_ledger_written' ), 20, 5 );
	}

	/**
	 * Link a newly written legacy ledger row to the canonical CRM contact.
	 *
	 * The ledger remains the source of truth for point arithmetic. This bridge
	 * only adds identity linkage and refreshes the denormalized contact balance.
	 */
	public static function on_ledger_written( $table_name, $ledger_id, $phone, $client_id = '', $direction = 'credit' ): void {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — link one ledger row without changing points arithmetic.
		$ledger_id = (int) $ledger_id;
		$phone     = class_exists( 'BizCity_Phone_Normalizer' )
			? BizCity_Phone_Normalizer::normalize_vn( (string) $phone )
			: trim( (string) $phone );
		if ( $ledger_id <= 0 || $phone === '' ) {
			return;
		}

		global $wpdb;
		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$contacts}` WHERE phone=%s ORDER BY id ASC LIMIT 1",
			$phone
		) );

		$now = current_time( 'mysql' );
		if ( $contact_id <= 0 ) {
			$wpdb->insert( $contacts, array(
				'name'               => '',
				'phone'              => $phone,
				'acquisition_source' => 'user_points_card',
				'acquisition_meta_json' => wp_json_encode( array(
					'client_id' => (string) $client_id,
					'direction' => sanitize_key( (string) $direction ),
				) ),
				'created_at'         => $now,
				'updated_at'         => $now,
			) );
			$contact_id = (int) $wpdb->insert_id;
		}
		if ( $contact_id <= 0 ) {
			return;
		}

		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — write ledger contact_id only after its versioned schema exists.
		$ledger_table = self::resolve_ledger_table( (string) $table_name );
		if ( $ledger_table !== '' && function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $ledger_table, 'contact_id' ) ) {
			$wpdb->update(
				$ledger_table,
				array( 'contact_id' => $contact_id ),
				array( 'id' => $ledger_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		if ( class_exists( 'BizCity_CRM_Loyalty_Bridge' ) && method_exists( 'BizCity_CRM_Loyalty_Bridge', 'balance' ) ) {
			$balance = BizCity_CRM_Loyalty_Bridge::balance( array( 'contact_id' => $contact_id ) );
			if ( function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $contacts, 'points_balance_cache' ) ) {
				$wpdb->update(
					$contacts,
					array( 'points_balance_cache' => (int) $balance, 'updated_at' => $now ),
					array( 'id' => $contact_id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		do_action( 'bizcity_crm_user_points_contact_linked', array(
			'contact_id' => $contact_id,
			'ledger_id'  => $ledger_id,
			'phone'      => $phone,
			'direction'  => sanitize_key( (string) $direction ),
		) );
	}

	private static function resolve_ledger_table( string $table_name ): string {
		global $wpdb;
		$allowed = array(
			'user_points'          => $wpdb->prefix . 'user_points',
			'user_points_exchange' => $wpdb->prefix . 'user_points_exchange',
		);
		if ( isset( $allowed[ $table_name ] ) ) {
			return $allowed[ $table_name ];
		}
		return in_array( $table_name, $allowed, true ) ? $table_name : '';
	}
}
