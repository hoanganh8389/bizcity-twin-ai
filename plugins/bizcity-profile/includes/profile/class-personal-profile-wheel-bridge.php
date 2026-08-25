<?php
/**
 * Profile Gift Wheel compatibility bridge.
 *
 * @package Bizcity_Twin_AI
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Profile_Wheel_Bridge' ) ) { return; }

final class BizCity_Personal_Profile_Wheel_Bridge {

	public static function init() {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: providers own vendor hook adapters; bridge owns canonical attribution.
		if ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ) { BizCity_Profile_Wheel_Provider_Registry::register_hooks( array( __CLASS__, 'on_play' ) ); }
	}

	public static function on_play( $payload ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — attribute only validated published Profile wheels; never trust browser owner/card IDs.
		if ( ! is_array( $payload ) || ! class_exists( 'BizCity_Personal_Profile_Analytics' ) || ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ) { return; }
		$wheel_id = absint( $payload['wheel'] ?? 0 );
		if ( $wheel_id <= 0 ) { return; }
		$card = self::find_published_card_for_wheel( $wheel_id );
		if ( ! is_array( $card ) ) { return; }
		$provider = (string) ( $card['provider'] ?? BizCity_Profile_Wheel_Provider_Registry::default_key() );
		$provider_instance = BizCity_Profile_Wheel_Provider_Registry::get( $provider );
		if ( ! $provider_instance || ! $provider_instance->is_available() ) { return; }
		$fields = self::normalize_fields( $payload['fields'] ?? array() );
		$email = sanitize_email( (string) ( $payload['email'] ?? $payload['mail'] ?? '' ) );
		$phone = self::find_phone( $fields );
		$name  = self::find_name( $fields );
		$source_id = $email ?: $phone;
		$consent = self::has_explicit_consent( $payload, $fields );
		$crm = self::should_upsert_crm( $source_id, $consent ) ? self::upsert_crm_contact( $source_id, $email, $phone, $name, $card, $payload ) : array();
		$meta = array(
			'profile_card_id' => (int) $card['card_id'],
			'wheel_id'        => $wheel_id,
			'campaign'        => 'profile_wheel',
			'crm_contact_id'  => (int) ( $crm['contact_id'] ?? 0 ),
			'winning'         => ! empty( $payload['winning'] ) ? '1' : '0',
			'consent'         => $consent ? 'yes' : 'no',
		);
		$event = ! empty( $payload['winning'] ) ? 'gift_wheel_win' : 'gift_wheel_play';
		BizCity_Personal_Profile_Analytics::record( (int) $card['card_id'], $event, $meta );
	}

	public static function get_campaigns_for_owner( $user_id ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: project Mabel campaign counters into the owner dashboard without copying its tables.
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ) { return array(); }
		global $wpdb;
		$cards_table = $wpdb->prefix . 'bizcity_personal_profile_cards';
		$projects_table = $wpdb->prefix . 'bzpb_projects';
		if ( function_exists( 'bizcity_tbl_exists' ) && ( ! bizcity_tbl_exists( $cards_table ) || ! bizcity_tbl_exists( $projects_table ) ) ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.id AS card_id, p.site_config FROM `' . $cards_table . '` c INNER JOIN `' . $projects_table . '` p ON p.id = c.bzpb_project_id AND p.user_id = c.owner_user_id WHERE c.owner_user_id = %d AND c.status = %s', $user_id, 'published' ), ARRAY_A );
		$campaigns = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$config = json_decode( (string) ( $row['site_config'] ?? '' ), true );
			foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
				$wheel_id = 'profile-card' === (string) ( $block['type'] ?? '' ) ? absint( $block['props']['giftWheelId'] ?? 0 ) : 0;
				$provider = 'profile-card' === (string) ( $block['type'] ?? '' ) ? sanitize_key( (string) ( $block['props']['giftWheelProvider'] ?? BizCity_Profile_Wheel_Provider_Registry::default_key() ) ) : BizCity_Profile_Wheel_Provider_Registry::default_key();
				if ( $wheel_id <= 0 || 'publish' !== get_post_status( $wheel_id ) ) { continue; }
				$stats = BizCity_Profile_Wheel_Provider_Registry::stats( $provider, $wheel_id );
				$wheel_post = get_post( $wheel_id );
				$campaigns[] = array(
					'profile_card_id' => (int) $row['card_id'],
					'provider' => $provider,
					'wheel_id' => $wheel_id,
					'label' => $wheel_post ? (string) $wheel_post->post_title : 'Wheel #' . $wheel_id,
					'plays' => (int) ( $stats['plays'] ?? 0 ),
					'wins' => (int) ( $stats['wins'] ?? 0 ),
					'optins' => (int) ( $stats['optins'] ?? 0 ),
				);
			}
		}
		return $campaigns;
	}

	private static function find_published_card_for_wheel( $wheel_id ) {
		global $wpdb;
		$cards_table = $wpdb->prefix . 'bizcity_personal_profile_cards';
		$projects_table = $wpdb->prefix . 'bzpb_projects';
		if ( function_exists( 'bizcity_tbl_exists' ) && ( ! bizcity_tbl_exists( $cards_table ) || ! bizcity_tbl_exists( $projects_table ) ) ) { return null; }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.id AS card_id, c.owner_user_id, c.status, p.site_config FROM `' . $cards_table . '` c INNER JOIN `' . $projects_table . '` p ON p.id = c.bzpb_project_id AND p.user_id = c.owner_user_id WHERE c.status = %s', 'published' ), ARRAY_A );
		$matches = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$config = json_decode( (string) ( $row['site_config'] ?? '' ), true );
			foreach ( is_array( $config['blocks'] ?? null ) ? $config['blocks'] : array() as $block ) {
				if ( 'profile-card' === (string) ( $block['type'] ?? '' ) && $wheel_id === absint( $block['props']['giftWheelId'] ?? 0 ) ) {
					$matches[] = array( 'card_id' => (int) $row['card_id'], 'owner_user_id' => (int) $row['owner_user_id'], 'provider' => sanitize_key( (string) ( $block['props']['giftWheelProvider'] ?? BizCity_Profile_Wheel_Provider_Registry::default_key() ) ) );
				}
			}
		}
		return count( $matches ) === 1 ? $matches[0] : null;
	}

	private static function normalize_fields( $fields ) {
		$out = array();
	foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( is_object( $field ) ) { $field = get_object_vars( $field ); }
			if ( ! is_array( $field ) ) { continue; }
			$key = sanitize_key( (string) ( $field['id'] ?? $field['name'] ?? '' ) );
			$value = sanitize_text_field( substr( trim( (string) ( $field['value'] ?? '' ) ), 0, 160 ) );
			if ( '' !== $key && '' !== $value ) { $out[ $key ] = $value; }
		}
		return $out;
	}

	private static function find_phone( array $fields ) {
		foreach ( $fields as $value ) {
			if ( preg_match( '/(?:\+?\d[\d .()\-]{7,})/', $value, $match ) ) { return preg_replace( '/[^0-9+]/', '', $match[0] ); }
		}
		return '';
	}

	private static function find_name( array $fields ) {
		foreach ( array( 'name', 'your-name', 'fullname', 'ho-ten' ) as $key ) {
			if ( ! empty( $fields[ $key ] ) ) { return $fields[ $key ]; }
		}
		return '';
	}

	private static function has_explicit_consent( array $payload, array $fields ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — require an affirmative consent field before CRM attribution.
		foreach ( array( 'consent', 'consent_given', 'marketing_consent' ) as $key ) {
			$value = $payload[ $key ] ?? null;
			if ( true === $value || in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true ) ) { return true; }
		}
		foreach ( $fields as $key => $value ) {
			if ( false !== strpos( $key, 'consent' ) || false !== strpos( $key, 'privacy' ) || false !== strpos( $key, 'agree' ) ) {
				if ( in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true ) ) { return true; }
			}
		}
		return false;
	}

	private static function should_upsert_crm( $source_id, $consent ) {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — keep CRM side-effect eligibility separate for deterministic DDV fixtures.
		return '' !== (string) $source_id && true === (bool) $consent;
	}

	private static function upsert_crm_contact( $source_id, $email, $phone, $name, array $card, array $payload ) {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return array(); }
		$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'profile_wheel', 'profile_' . (int) $card['card_id'], array( 'name' => 'Profile Wheel #' . (int) $card['card_id'] ) );
		if ( $inbox_id <= 0 ) { return array(); }
		$contact = BizCity_CRM_Repository::upsert_contact( $inbox_id, (string) $source_id, array(
			'name'  => $name,
			'email' => $email ?: null,
			'phone' => $phone ?: null,
			'additional_attributes' => array(
				'acquisition_source' => 'profile_wheel',
				'profile_card_id'    => (int) $card['card_id'],
				'wheel_id'           => absint( $payload['wheel'] ?? 0 ),
			),
		) );
		return is_array( $contact ) ? $contact : array();
	}
}
