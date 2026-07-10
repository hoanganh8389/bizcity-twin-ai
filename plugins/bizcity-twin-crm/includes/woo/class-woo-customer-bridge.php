<?php
/**
 * BizCity CRM — Woo Customer Bridge (PHASE 0.35 M-CRM.M8.W3).
 *
 * Two-way sync between WordPress users (`wp_users` + `wp_usermeta`
 * Woo billing/shipping fields) and CRM contacts
 * (`wp_*_bizcity_crm_contacts` — the canonical contact table).
 *
 * Hooks registered (only when WooCommerce active, gated by orchestrator):
 *   - user_register / profile_update            → pull user → contact upsert
 *   - woocommerce_update_customer               → pull billing meta diff → contact
 *   - bizcity_crm_contact_saved (custom event)  → push contact → usermeta
 *   - bizcity_crm_resolve_contact_for_order     → match WC_Order → contact
 *
 * Loop guard: every push/pull is wrapped in {@see in_flight()} so the
 * mirror hook on the other side short-circuits and we don't ping-pong.
 *
 * @package BizCity_Twin_CRM\Woo
 * @since   1.11.0 (2026-05-13)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Customer_Bridge' ) ) { return; }

final class BizCity_CRM_Woo_Customer_Bridge {

	/** Loop guard set during pull/push. */
	private static bool $in_flight = false;

	/** Billing/shipping usermeta keys we mirror in/out. */
	const MIRROR_META = array(
		'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
		'billing_company', 'billing_address_1', 'billing_address_2',
		'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
		'shipping_first_name', 'shipping_last_name', 'shipping_company',
		'shipping_address_1', 'shipping_address_2', 'shipping_city',
		'shipping_state', 'shipping_postcode', 'shipping_country',
	);

	public static function register(): void {
		// PULL: WP/Woo user → CRM contact.
		add_action( 'user_register',                array( __CLASS__, 'on_user_register' ),  20, 1 );
		add_action( 'profile_update',               array( __CLASS__, 'on_profile_update' ), 20, 2 );
		add_action( 'woocommerce_update_customer',  array( __CLASS__, 'on_woo_customer_updated' ), 20, 1 );

		// PUSH: CRM contact save → mirror to wp_usermeta. Custom event the
		// repository will fire (added in W3).
		add_action( 'bizcity_crm_contact_saved',    array( __CLASS__, 'on_contact_saved' ), 20, 2 );
	}

	public static function in_flight(): bool { return self::$in_flight; }

	/* ----------------------------------------------------------------
	 * PULL — user → contact upsert
	 * ---------------------------------------------------------------- */

	public static function on_user_register( int $user_id ): void {
		if ( self::$in_flight ) { return; }
		self::sync_from_user( $user_id );
	}

	public static function on_profile_update( int $user_id, $old_user_data ): void {
		if ( self::$in_flight ) { return; }
		self::sync_from_user( $user_id );
	}

	public static function on_woo_customer_updated( int $user_id ): void {
		if ( self::$in_flight ) { return; }
		self::sync_from_user( $user_id );
	}

	/**
	 * Upsert a CRM contact row from a WP user. Match precedence:
	 *   1. existing contact with same `wp_user_id`
	 *   2. existing contact with same email (link wp_user_id)
	 *   3. existing contact with same phone (link wp_user_id)
	 *   4. insert a brand-new contact with wp_user_id set.
	 *
	 * @return int contact_id (0 on failure).
	 */
	public static function sync_from_user( int $user_id ): int {
		if ( $user_id <= 0 ) { return 0; }
		$user = get_userdata( $user_id );
		if ( ! $user ) { return 0; }

		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();

		$billing_first = (string) get_user_meta( $user_id, 'billing_first_name', true );
		$billing_last  = (string) get_user_meta( $user_id, 'billing_last_name', true );
		$billing_email = (string) get_user_meta( $user_id, 'billing_email', true );
		$billing_phone = (string) get_user_meta( $user_id, 'billing_phone', true );

		$display_name = trim( ( $billing_first . ' ' . $billing_last ) ) ?: ( $user->display_name ?: $user->user_login );
		$email        = $billing_email ?: $user->user_email;
		$phone        = $billing_phone;

		// Lookup
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE wp_user_id=%d LIMIT 1", $user_id ) );
		if ( ! $id && $email !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s AND (wp_user_id IS NULL OR wp_user_id=0) LIMIT 1", $email ) );
		}
		if ( ! $id && $phone !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s AND (wp_user_id IS NULL OR wp_user_id=0) LIMIT 1", $phone ) );
		}

		// Build a billing snapshot we stash inside additional_attributes.billing
		$billing = self::collect_meta( $user_id, 'billing_' );
		$shipping = self::collect_meta( $user_id, 'shipping_' );

		$now = current_time( 'mysql' );
		self::$in_flight = true;
		try {
			if ( $id > 0 ) {
				$existing_attrs = (array) json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT additional_attributes FROM `{$tbl}` WHERE id=%d", $id ) ), true );
				$existing_attrs['billing']  = $billing;
				$existing_attrs['shipping'] = $shipping;
				$wpdb->update( $tbl, array(
					'wp_user_id'            => $user_id,
					'name'                  => $display_name,
					'email'                 => $email ?: null,
					'phone'                 => $phone ?: null,
					'additional_attributes' => wp_json_encode( $existing_attrs ),
					'updated_at'            => $now,
				), array( 'id' => $id ) );
			} else {
				$wpdb->insert( $tbl, array(
					'wp_user_id'            => $user_id,
					'name'                  => $display_name,
					'email'                 => $email ?: null,
					'phone'                 => $phone ?: null,
					'acquisition_source'    => 'woo_user',
					'additional_attributes' => wp_json_encode( array( 'billing' => $billing, 'shipping' => $shipping ) ),
					'created_at'            => $now,
					'updated_at'            => $now,
				) );
				$id = (int) $wpdb->insert_id;
			}
		} finally {
			self::$in_flight = false;
		}

		do_action( 'bizcity_crm_contact_synced_from_woo', array(
			'direction'   => 'pull',
			'contact_id'  => $id,
			'wp_user_id'  => $user_id,
		) );

		return $id;
	}

	/* ----------------------------------------------------------------
	 * PUSH — contact save → usermeta
	 * ---------------------------------------------------------------- */

	/**
	 * Mirror a contact row's billing snapshot into `wp_usermeta` if the
	 * contact has a `wp_user_id` set.
	 *
	 * @param int   $contact_id
	 * @param array $contact   Latest row data (already saved).
	 */
	public static function on_contact_saved( int $contact_id, array $contact ): void {
		if ( self::$in_flight ) { return; }
		$user_id = (int) ( $contact['wp_user_id'] ?? 0 );
		if ( $user_id <= 0 ) { return; }

		$attrs = $contact['additional_attributes'] ?? array();
		if ( is_string( $attrs ) ) { $attrs = (array) json_decode( $attrs, true ); }
		$billing = (array) ( $attrs['billing'] ?? array() );
		if ( ! $billing ) { return; }

		self::$in_flight = true;
		try {
			foreach ( self::MIRROR_META as $k ) {
				if ( strpos( $k, 'billing_' ) !== 0 ) { continue; }
				$short = substr( $k, strlen( 'billing_' ) ); // e.g. 'first_name'
				if ( array_key_exists( $short, $billing ) ) {
					update_user_meta( $user_id, $k, (string) $billing[ $short ] );
				}
			}
		} finally {
			self::$in_flight = false;
		}

		do_action( 'bizcity_crm_contact_synced_from_woo', array(
			'direction'  => 'push',
			'contact_id' => $contact_id,
			'wp_user_id' => $user_id,
		) );
	}

	/* ----------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/** @return array<string,string> e.g. ['first_name'=>'..','phone'=>'..'] */
	public static function collect_meta( int $user_id, string $prefix ): array {
		$out = array();
		foreach ( self::MIRROR_META as $k ) {
			if ( strpos( $k, $prefix ) !== 0 ) { continue; }
			$short = substr( $k, strlen( $prefix ) );
			$v = (string) get_user_meta( $user_id, $k, true );
			if ( $v !== '' ) { $out[ $short ] = $v; }
		}
		return $out;
	}

	/**
	 * Resolve which CRM contact owns a given WC_Order.
	 * Match precedence: customer_id (wp_user_id) → billing_email → billing_phone.
	 *
	 * @return int contact_id (0 if no match — caller may insert a guest contact).
	 */
	public static function resolve_contact_for_order( $order ): int {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();

		$user_id = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE wp_user_id=%d LIMIT 1", $user_id ) );
			if ( $id ) {
				do_action( 'bizcity_crm_contact_woo_link_resolved', array( 'order_id' => $order->get_id(), 'contact_id' => $id, 'match_method' => 'user_id' ) );
				return $id;
			}
			// Auto-create from user.
			$id = self::sync_from_user( $user_id );
			if ( $id ) {
				do_action( 'bizcity_crm_contact_woo_link_resolved', array( 'order_id' => $order->get_id(), 'contact_id' => $id, 'match_method' => 'user_id_synced' ) );
				return $id;
			}
		}

		$email = (string) $order->get_billing_email();
		if ( $email !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s LIMIT 1", $email ) );
			if ( $id ) {
				do_action( 'bizcity_crm_contact_woo_link_resolved', array( 'order_id' => $order->get_id(), 'contact_id' => $id, 'match_method' => 'email' ) );
				return $id;
			}
		}
		$phone = (string) $order->get_billing_phone();
		if ( $phone !== '' ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s LIMIT 1", $phone ) );
			if ( $id ) {
				do_action( 'bizcity_crm_contact_woo_link_resolved', array( 'order_id' => $order->get_id(), 'contact_id' => $id, 'match_method' => 'phone' ) );
				return $id;
			}
		}

		return 0;
	}
}
