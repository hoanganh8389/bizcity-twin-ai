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
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — sync guest checkout billing identity into canonical Contacts.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 20, 3 );

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

	public static function on_checkout_order_processed( $order_id, $posted_data = array(), $order = null ): void {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — bridge checkout order identity into Contacts.
		if ( self::$in_flight ) { return; }
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( is_object( $order ) ) {
			self::sync_from_order( $order );
		}
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
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — canonicalize account email before identity lookup/write.
		$email        = strtolower( trim( $billing_email ?: $user->user_email ) );
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — normalize account phone before lookup/write.
		$phone        = class_exists( 'BizCity_Phone_Normalizer' )
			? BizCity_Phone_Normalizer::normalize_vn( $billing_phone )
			: $billing_phone;

		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — collect every identity candidate before choosing a Contact.
		$user_contact_ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE wp_user_id=%d AND deleted_at IS NULL ORDER BY id ASC", $user_id ) );
		$email_contact_ids = $email !== ''
			? (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s AND deleted_at IS NULL ORDER BY id ASC", $email ) )
			: array();
		$phone_contact_ids = $phone !== ''
			? (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s AND deleted_at IS NULL ORDER BY id ASC", $phone ) )
			: array();
		$candidate_ids = array_values( array_unique( array_map( 'intval', array_merge( $user_contact_ids, $email_contact_ids, $phone_contact_ids ) ) ) );
		if ( count( $candidate_ids ) > 1 ) {
			// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-V2 — account identity mismatch must not overwrite an existing Contact.
			do_action( 'bizcity_crm_contact_identity_conflict', array(
				'source'           => 'woo_user',
				'wp_user_id'       => $user_id,
				'contact_ids'      => $candidate_ids,
				'user_contact_ids' => array_map( 'intval', $user_contact_ids ),
				'email_contact_ids'=> array_map( 'intval', $email_contact_ids ),
				'phone_contact_ids'=> array_map( 'intval', $phone_contact_ids ),
				'reason'           => 'identity_candidate_mismatch',
			) );
			return 0;
		}
		$id = (int) ( $candidate_ids[0] ?? 0 );

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

	/**
	 * Upsert a Woo order billing identity, including guest checkout customers.
	 *
	 * @param object $order WC_Order-compatible object.
	 * @return int contact_id (0 on failure).
	 */
	public static function sync_from_order( $order ): int {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — upsert account/guest order identity through one path.
		if ( self::$in_flight || ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) ) {
			return 0;
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$user_id  = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		if ( $user_id > 0 ) {
			$id = self::sync_from_user( $user_id );
			if ( $id > 0 ) {
				do_action( 'bizcity_crm_contact_synced_from_woo_order', array( 'order_id' => $order_id, 'contact_id' => $id, 'match_method' => 'user_id' ) );
				return $id;
			}
		}

		global $wpdb;
		$tbl       = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$email     = strtolower( trim( (string) $order->get_billing_email() ) );
		$raw_phone = method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
		$phone     = class_exists( 'BizCity_Phone_Normalizer' )
			? BizCity_Phone_Normalizer::normalize_vn( $raw_phone )
			: $raw_phone;
		$first     = method_exists( $order, 'get_billing_first_name' ) ? trim( (string) $order->get_billing_first_name() ) : '';
		$last      = method_exists( $order, 'get_billing_last_name' ) ? trim( (string) $order->get_billing_last_name() ) : '';
		$name      = trim( $first . ' ' . $last );
		if ( $email === '' && $phone === '' ) { return 0; }

		$id = 0;
		$match_method = '';
		$email_id = 0;
		$phone_id = 0;
		if ( $email !== '' ) {
			$email_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE email=%s ORDER BY (wp_user_id IS NULL), id ASC LIMIT 1", $email ) );
		}
		if ( $phone !== '' ) {
			$phone_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$tbl}` WHERE phone=%s ORDER BY (wp_user_id IS NULL), id ASC LIMIT 1", $phone ) );
		}
		// [2026-08-11 Johnny Chu] R-UNIFY — refuse ambiguous guest identity instead of silently merging email and phone matches.
		if ( $email_id > 0 && $phone_id > 0 && $email_id !== $phone_id ) {
			do_action( 'bizcity_crm_contact_identity_conflict', array(
				'source'           => 'woo_order',
				'order_id'         => $order_id,
				'email_contact_id' => $email_id,
				'phone_contact_id'  => $phone_id,
				'reason'           => 'email_phone_contact_mismatch',
			) );
			return 0;
		}
		if ( $email_id > 0 ) {
			$id = $email_id;
			$match_method = 'email';
		} elseif ( $phone_id > 0 ) {
			$id = $phone_id;
			$match_method = 'phone';
		}

		$now = current_time( 'mysql' );
		$attrs = array(
			'woo' => array(
				'latest_order_id' => $order_id,
				'last_seen_at'    => $now,
			),
		);
		self::$in_flight = true;
		try {
			if ( $id > 0 ) {
				$existing = $wpdb->get_row( $wpdb->prepare( "SELECT name, additional_attributes FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
				$existing_attrs = (array) json_decode( (string) ( $existing['additional_attributes'] ?? '' ), true );
				$existing_attrs['woo'] = array_merge( (array) ( $existing_attrs['woo'] ?? array() ), $attrs['woo'] );
				$update = array(
					'additional_attributes' => wp_json_encode( $existing_attrs ),
					'updated_at'            => $now,
				);
				if ( $user_id > 0 ) { $update['wp_user_id'] = $user_id; }
				if ( $email !== '' ) { $update['email'] = $email; }
				if ( $phone !== '' ) { $update['phone'] = $phone; }
				if ( $name !== '' && empty( $existing['name'] ) ) { $update['name'] = $name; }
				$wpdb->update( $tbl, $update, array( 'id' => $id ) );
			} else {
				$wpdb->insert( $tbl, array(
					'wp_user_id'            => $user_id > 0 ? $user_id : null,
					'name'                  => $name,
					'email'                 => $email !== '' ? $email : null,
					'phone'                 => $phone !== '' ? $phone : null,
					'acquisition_source'    => 'woo_order',
					'acquisition_meta_json' => wp_json_encode( array( 'order_id' => $order_id ) ),
					'additional_attributes' => wp_json_encode( $attrs ),
					'created_at'            => $now,
					'updated_at'            => $now,
				) );
				$id = (int) $wpdb->insert_id;
				$match_method = 'insert';
			}
		} finally {
			self::$in_flight = false;
		}

		if ( $id > 0 ) {
			do_action( 'bizcity_crm_contact_synced_from_woo_order', array(
				'order_id' => $order_id,
				'contact_id' => $id,
				'match_method' => $match_method,
				'wp_user_id' => $user_id,
			) );
		}
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
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — use the same normalized order upsert for lookup and guest creation.
		return self::sync_from_order( $order );
	}
}
