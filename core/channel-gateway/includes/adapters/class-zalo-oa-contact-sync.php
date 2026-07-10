<?php
/**
 * Zalo OA — Contact Sync (Sprint ZA-4)
 *
 * Listens on `bizcity_channel_normalized` (priority 7) for ZALO_BOT
 * follow / unfollow events and syncs them into `bizcity_crm_contacts`.
 *
 * Follow  → fetch user profile via /v3.0/oa/user/detail → upsert contact.
 * Unfollow → mark existing contact's zalo_follow_status = 'unfollowed'
 *            (contact record is kept for CRM history; re-follows update it).
 *
 * Fails silently when bizcity-twin-crm is not installed — no CRM table →
 * just skips. No errors surfaced to admin.
 *
 * Design notes:
 * - Priority 7 = after UCL log (5) and before CRM ingestor (9).
 * - Table key: `bizcity_crm_contacts` with column `additional_attributes` JSON.
 *   We store `{"zalo_follow_status":"followed","zalo_oa_id":"...","zalo_user_id":"..."}`.
 * - Deduplicate contacts by `additional_attributes->>zalo_user_id` match first,
 *   then by phone (if Zalo shared phone), then INSERT new.
 * - Uses `BizCity_CRM_DB_Installer::tbl_contacts()` to get prefixed table name.
 *
 * [2026-06-13 Johnny Chu] ZA-4 — Zalo OA follow/unfollow CRM contact sync.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Adapters
 * @since      PHASE 0.37 ZA-4
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_OA_Contact_Sync' ) ) {
	return;
}

final class BizCity_Zalo_OA_Contact_Sync {

	/** @var bool */
	private static $booted = false;

	/**
	 * Register subscriber. Called from channel-gateway bootstrap.php.
	 */
	public static function init(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// [2026-06-13 Johnny Chu] ZA-4 — priority 7: after UCL log (5), before CRM ingestor (9).
		add_action( 'bizcity_channel_normalized', array( __CLASS__, 'on_normalized' ), 7, 2 );
	}

	/**
	 * @param array  $envelope  Canonical envelope from UCL.
	 * @param string $trigger_key
	 */
	public static function on_normalized( $envelope, $trigger_key = '' ): void {
		if ( ! is_array( $envelope ) ) {
			return;
		}
		$platform  = (string) ( $envelope['platform']   ?? '' );

		// [2026-06-13 Johnny Chu] ZA-4 BUG FIX — UCL $map hardcodes event_type='message'
		// for all events including follow/unfollow (same trigger key wu_zalobot_message_received).
		// Actual Zalo event type is preserved in envelope['raw']['type'] from normalize_inbound().
		$_raw       = is_array( $envelope['raw'] ?? null ) ? (array) $envelope['raw'] : array();
		$event_type = (string) ( $_raw['type'] ?? $envelope['event_type'] ?? '' );

		if ( $platform !== 'ZALO_BOT' ) {
			return;
		}
		if ( $event_type !== 'follow' && $event_type !== 'unfollow' ) {
			return;
		}

		$user_id = (string) ( $envelope['sender_id'] ?? $envelope['user_id'] ?? '' );
		$oa_id   = (string) ( $envelope['instance_id'] ?? $envelope['account_id'] ?? '' );
		if ( $user_id === '' ) {
			return;
		}

		if ( $event_type === 'follow' ) {
			self::handle_follow( $user_id, $oa_id, $envelope );
		} else {
			self::handle_unfollow( $user_id, $oa_id );
		}
	}

	/* ──────────────────────────────────────────
	 * Follow — fetch profile + upsert contact
	 * ────────────────────────────────────────── */

	private static function handle_follow( string $user_id, string $oa_id, array $envelope ): void {
		// [2026-06-13 Johnny Chu] ZA-4 — follow event: fetch profile + upsert CRM contact.
		$profile = self::fetch_profile( $user_id, $oa_id );

		$display_name = '';
		$avatar       = '';
		$phone        = '';
		if ( is_array( $profile ) ) {
			$display_name = (string) ( $profile['display_name'] ?? '' );
			$avatar       = (string) ( $profile['avatar']       ?? '' );
			$shared       = is_array( $profile['shared_info'] ?? null ) ? $profile['shared_info'] : array();
			$phone        = (string) ( $shared['phone'] ?? '' );
			// Zalo returns phone with leading '0'; normalize to local format.
			if ( strpos( $phone, '+84' ) === 0 ) {
				$phone = '0' . substr( $phone, 3 );
			}
		}

		// Build additional_attributes for zalo identity.
		$zalo_meta = array(
			'zalo_follow_status' => 'followed',
			'zalo_oa_id'         => $oa_id,
			'zalo_user_id'       => $user_id,
			'zalo_avatar'        => $avatar,
			'zalo_followed_at'   => gmdate( 'Y-m-d H:i:s' ),
		);

		self::upsert_contact( $user_id, $display_name, $phone, $zalo_meta );
	}

	private static function handle_unfollow( string $user_id, string $oa_id ): void {
		// [2026-06-13 Johnny Chu] ZA-4 — unfollow: mark contact without deleting history.
		if ( ! self::crm_available() ) {
			return;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer::tbl_contacts();

		// Find by JSON field match (MySQL 5.7+ JSON_EXTRACT).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$tbl}`
			 WHERE JSON_UNQUOTE(JSON_EXTRACT(additional_attributes,'$.zalo_user_id')) = %s
			   AND deleted_at IS NULL
			 LIMIT 1",
			$user_id
		) );

		if ( ! $contact_id ) {
			return; // Contact never created — nothing to update.
		}

		// Update just the zalo follow status inside existing JSON.
		// We read → merge → write to avoid clobbering other attributes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT additional_attributes FROM `{$tbl}` WHERE id=%d LIMIT 1",
			$contact_id
		) );
		$attrs = array();
		if ( $row ) {
			$decoded = json_decode( (string) $row, true );
			if ( is_array( $decoded ) ) {
				$attrs = $decoded;
			}
		}
		$attrs['zalo_follow_status']   = 'unfollowed';
		$attrs['zalo_unfollowed_at']   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$tbl,
			array(
				'additional_attributes' => wp_json_encode( $attrs ),
				'updated_at'            => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $contact_id )
		);
	}

	/* ──────────────────────────────────────────
	 * Upsert contact into bizcity_crm_contacts
	 * ────────────────────────────────────────── */

	private static function upsert_contact( string $zalo_user_id, string $display_name, string $phone, array $zalo_meta ): void {
		if ( ! self::crm_available() ) {
			return;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer::tbl_contacts();
		$now = gmdate( 'Y-m-d H:i:s' );

		// Step 1: find by zalo_user_id inside JSON.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$tbl}`
			 WHERE JSON_UNQUOTE(JSON_EXTRACT(additional_attributes,'$.zalo_user_id')) = %s
			   AND deleted_at IS NULL
			 LIMIT 1",
			$zalo_user_id
		) );

		// Step 2: find by phone if Zalo shared it.
		if ( ! $contact_id && $phone !== '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$tbl}` WHERE phone=%s AND deleted_at IS NULL LIMIT 1",
				$phone
			) );
		}

		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — track new contact for lead creation
		$is_new = false;

		if ( $contact_id ) {
			// Update: merge zalo_meta into existing additional_attributes.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT additional_attributes FROM `{$tbl}` WHERE id=%d LIMIT 1",
				$contact_id
			) );
			$attrs = array();
			if ( $existing_json ) {
				$decoded = json_decode( (string) $existing_json, true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}
			$attrs = array_merge( $attrs, $zalo_meta );

			$update_data = array(
				'additional_attributes' => wp_json_encode( $attrs ),
				'updated_at'            => $now,
				'acquisition_source'    => 'zalo_oa',
			);
			if ( $display_name !== '' ) {
				$update_data['name'] = sanitize_text_field( $display_name );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $tbl, $update_data, array( 'id' => $contact_id ) );

		} else {
			// Insert new contact.
			$insert_data = array(
				'name'                  => sanitize_text_field( $display_name ?: ( 'Zalo ' . $zalo_user_id ) ),
				'acquisition_source'    => 'zalo_oa',
				'additional_attributes' => wp_json_encode( $zalo_meta ),
				'created_at'            => $now,
				'updated_at'            => $now,
			);
			if ( $phone !== '' ) {
				$insert_data['phone'] = sanitize_text_field( $phone );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $tbl, $insert_data );
			$contact_id = (int) $wpdb->insert_id;
			$is_new     = true;
		}

		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — auto-create pipeline lead on first Zalo OA follow
		if ( $is_new && $contact_id ) {
			self::maybe_create_lead( $contact_id, (string) ( $zalo_meta['zalo_oa_id'] ?? '' ), $display_name, $phone );
		}

		// Fire hook so other modules can react (e.g. assign to inbox).
		if ( $contact_id ) {
			do_action( 'bizcity_zalo_oa_contact_synced', $contact_id, $zalo_user_id, $zalo_meta );
		}
	}

	/**
	 * Auto-create a pipeline lead when a new Zalo OA follower is synced as a CRM contact.
	 * Idempotent: skips if a lead with source='zalo_oa' already exists for this contact.
	 *
	 * [2026-06-13 Johnny Chu] PHASE-CG-CF7 — Zalo OA pipeline lead auto-creation
	 */
	private static function maybe_create_lead( int $contact_id, string $oa_id, string $display_name, string $phone ): void {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}
		global $wpdb;
		$leads_t = BizCity_CRM_DB_Installer_V2::tbl_crm_leads();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $leads_t ) ) {
			return;
		}
		// Idempotency: one lead per (contact, source).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$leads_t}` WHERE contact_id = %d AND source = 'zalo_oa' AND deleted_at IS NULL LIMIT 1",
			$contact_id
		) );
		if ( $exists ) {
			return;
		}
		// source_ref unique per (source, contact) so UNIQUE(source, source_ref) is safe.
		$source_ref = 'contact:' . $contact_id . ( $oa_id !== '' ? ':oa:' . $oa_id : '' );
		// Split display_name into first / last.
		$name   = trim( $display_name );
		$parts  = $name !== '' ? explode( ' ', $name, 2 ) : array( '', '' );
		$first  = sanitize_text_field( $parts[0] ?? '' );
		$last   = sanitize_text_field( $parts[1] ?? '' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$leads_t,
			array(
				'first_name'  => $first,
				'last_name'   => $last,
				'phone'       => $phone !== '' ? sanitize_text_field( $phone ) : null,
				'source'      => 'zalo_oa',
				'source_ref'  => sanitize_text_field( $source_ref ),
				'status'      => 'new',
				'contact_id'  => $contact_id,
				'notes'       => 'Lead tự động từ Zalo OA: ' . sanitize_text_field( $oa_id ?: 'không rõ OA' ),
				'custom_json' => wp_json_encode( array(
					'origin' => 'zalo_oa',
					'oa_id'  => $oa_id,
				) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
	}

	/* ──────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────── */

	/**
	 * Fetch user profile from Zalo OA API using the registered integration account.
	 *
	 * @return array|null
	 */
	private static function fetch_profile( string $user_id, string $oa_id ) {
		if (
			! class_exists( 'BizCity_Integration_Registry' ) ||
			! class_exists( 'BizCity_Zalo_Bot_OA_Integration' )
		) {
			return null;
		}

		$registry = BizCity_Integration_Registry::instance();
		$channel  = null;
		$account  = array();

		foreach ( $registry->get_all() as $integ ) {
			if ( $integ instanceof BizCity_Zalo_Bot_OA_Integration ) {
				$channel = $integ;
				break;
			}
		}
		if ( ! $channel ) {
			return null;
		}

		$accounts = $registry->get_accounts( $channel->get_code() );
		foreach ( $accounts as $acc ) {
			if ( (string) ( $acc['oa_id'] ?? '' ) === $oa_id ) {
				$account = $acc;
				break;
			}
		}
		if ( empty( $account ) && count( $accounts ) === 1 ) {
			$account = $accounts[0];
		}
		if ( empty( $account ) ) {
			return null;
		}

		$clone = clone $channel;
		$clone->set_account( $account );
		return $clone->api_get_user_profile( $user_id, $clone->get_decrypted_params() );
	}

	/**
	 * Check bizcity_crm_contacts table is available.
	 */
	private static function crm_available(): bool {
		static $ok = null;
		if ( null !== $ok ) {
			return $ok;
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer' ) ) {
			return $ok = false;
		}
		if ( ! method_exists( 'BizCity_CRM_DB_Installer', 'tbl_contacts' ) ) {
			return $ok = false;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer::tbl_contacts();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $ok = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
			$tbl
		) );
	}
}
