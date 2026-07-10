<?php
/**
 * BizCity CRM — Pipeline Sync.
 *
 * Single entry point that fans out across every registered
 * BizCity_CRM_Customer_Source and upserts opportunities idempotently by
 * (source, source_ref). Used by:
 *
 *   - REST  POST /wp-json/bizcity-crm/v1/sales-sync     (manual trigger)
 *   - Hook  bizcity_crm_message_persisted               (cheap per-message refresh)
 *   - Hook  bizgpt_dino_user_points_updated (optional)  (loyalty plugins re-trigger)
 *
 * Stage rules (the only place these are decided):
 *
 *   has_phone == false   →  stage = 'prospecting',   probability = 10
 *   has_phone == true    →  stage = 'qualification', probability = 25
 *
 * If an opp already past qualification (proposal/negotiation/closed_*),
 * we never demote it — adapter sync only refreshes name/contact/last_activity.
 *
 * @package BizCity_Twin_CRM
 * @since   1.16.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Pipeline_Sync {

	const OPT_LAST_RUN = 'bizcity_crm_pipeline_sync_last_run';

	/**
	 * Run every registered source. Returns per-source counters.
	 *
	 * @param array $args { 'since_ts'?: int|null, 'limit'?: int, 'sources'?: string[] }
	 */
	public static function run( array $args = array() ): array {
		$since_ts = isset( $args['since_ts'] ) ? (int) $args['since_ts'] : null;
		$limit    = isset( $args['limit'] )    ? (int) $args['limit']    : 200;
		$only     = isset( $args['sources'] ) && is_array( $args['sources'] ) ? array_filter( $args['sources'] ) : null;

		$registry = BizCity_CRM_Customer_Source_Registry::all();
		$report   = array( 'sources' => array(), 'started_at' => gmdate( 'c' ) );

		foreach ( $registry as $code => $src ) {
			if ( $only && ! in_array( $code, $only, true ) ) {
				continue;
			}
			$counts = array( 'fetched' => 0, 'inserted' => 0, 'updated' => 0, 'promoted' => 0, 'skipped' => 0, 'errors' => 0 );
			try {
				$rows = $src->fetch_recent( $since_ts, $limit );
				$counts['fetched'] = count( $rows );
				foreach ( $rows as $row ) {
					$res = self::upsert_one( $code, $row );
					if ( isset( $counts[ $res ] ) ) { $counts[ $res ]++; }
				}
			} catch ( \Throwable $e ) {
				$counts['errors']++;
				$counts['error_msg'] = $e->getMessage();
			}
			$report['sources'][ $code ] = $counts;
		}

		update_option( self::OPT_LAST_RUN, time(), false );
		$report['finished_at'] = gmdate( 'c' );
		return $report;
	}

	/**
	 * Upsert one row from a source. Returns: 'inserted' | 'updated' | 'promoted' | 'skipped'.
	 */
	private static function upsert_one( string $source_code, array $row ): string {
		global $wpdb;

		$external_ref = isset( $row['external_ref'] ) ? (string) $row['external_ref'] : '';
		if ( '' === $external_ref ) {
			return 'skipped';
		}

		$opps_tbl = $wpdb->prefix . 'bizcity_crm_opportunities';
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $opps_tbl ) ) {
			return 'skipped';
		}

		$has_phone     = ! empty( $row['has_phone'] );
		$desired_stage = $has_phone ? 'qualification' : 'prospecting';
		$desired_prob  = $has_phone ? 25 : 10;
		$name          = isset( $row['name'] ) && '' !== $row['name'] ? (string) $row['name'] : ( 'Khách ' . substr( $external_ref, -4 ) );
		$last_activity = isset( $row['last_activity_at'] ) && $row['last_activity_at'] ? (string) $row['last_activity_at'] : current_time( 'mysql', true );
		$now           = current_time( 'mysql', true );

		// Try to attach contact (canonical), creating one if a phone is provided.
		$contact_id = self::ensure_contact( $source_code, $row );

		// Look up existing opp by (source, source_ref).
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, stage FROM `{$opps_tbl}` WHERE source = %s AND source_ref = %s AND deleted_at IS NULL LIMIT 1",
			$source_code,
			$external_ref
		), ARRAY_A );

		$custom_json = wp_json_encode( array(
			'source_meta' => isset( $row['meta'] ) ? $row['meta'] : array(),
			'channel'     => $row['channel'] ?? null,
		) );

		if ( $existing ) {
			$current_stage = (string) $existing['stage'];
			$promoted      = false;
			$update        = array(
				'name'             => $name,
				'updated_at'       => $now,
				'source_synced_at' => $now,
				'custom_json'      => $custom_json,
			);
			if ( $contact_id ) {
				$update['contact_id'] = $contact_id;
			}
			// Only promote from prospecting → qualification. Never demote.
			if ( $has_phone && 'prospecting' === $current_stage ) {
				$update['stage']       = 'qualification';
				$update['probability'] = 25;
				$promoted              = true;
			}
			$wpdb->update( $opps_tbl, $update, array( 'id' => (int) $existing['id'] ) );
			do_action( 'bizcity_crm_pipeline_sync_upserted', (int) $existing['id'], $source_code, $external_ref, $promoted );
			return $promoted ? 'promoted' : 'updated';
		}

		$insert = array(
			'name'             => $name,
			'contact_id'       => $contact_id ?: null,
			'stage'            => $desired_stage,
			'status'           => 'open',
			'amount'           => 0,
			'currency'         => 'VND',
			'probability'      => $desired_prob,
			'expected_revenue' => 0,
			'description'      => sprintf( '[%s] %s', $source_code, $name ),
			'custom_json'      => $custom_json,
			'source'           => $source_code,
			'source_ref'       => $external_ref,
			'source_synced_at' => $now,
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		$ok = $wpdb->insert( $opps_tbl, $insert );
		if ( false === $ok ) {
			// Likely UNIQUE collision under race — retry as update.
			$existing2 = $wpdb->get_row( $wpdb->prepare(
				"SELECT id FROM `{$opps_tbl}` WHERE source = %s AND source_ref = %s LIMIT 1",
				$source_code,
				$external_ref
			), ARRAY_A );
			if ( $existing2 ) {
				$wpdb->update( $opps_tbl, array(
					'name'             => $name,
					'source_synced_at' => $now,
					'updated_at'       => $now,
				), array( 'id' => (int) $existing2['id'] ) );
				return 'updated';
			}
			return 'skipped';
		}
		$opp_id = (int) $wpdb->insert_id;
		do_action( 'bizcity_crm_pipeline_sync_upserted', $opp_id, $source_code, $external_ref, false );
		return 'inserted';
	}

	/**
	 * Resolve / create the canonical contact for this source row.
	 * Match priority: meta.contact_id → phone (existing) → insert new (only if phone present).
	 */
	private static function ensure_contact( string $source_code, array $row ): int {
		global $wpdb;
		$contacts_tbl = $wpdb->prefix . 'bizcity_crm_contacts';
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $contacts_tbl ) ) {
			return 0;
		}

		// Provided directly by source (e.g. messenger already has CRM contact id).
		if ( ! empty( $row['meta']['contact_id'] ) ) {
			return (int) $row['meta']['contact_id'];
		}

		$phone = isset( $row['phone'] ) ? (string) $row['phone'] : '';
		if ( '' !== $phone ) {
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$contacts_tbl}` WHERE phone = %s AND deleted_at IS NULL LIMIT 1",
				$phone
			) );
			if ( $existing ) {
				return (int) $existing;
			}
			$now = current_time( 'mysql', true );
			$ok  = $wpdb->insert( $contacts_tbl, array(
				'name'                  => (string) ( $row['name'] ?? '' ),
				'phone'                 => $phone,
				'email'                 => ! empty( $row['email'] ) ? (string) $row['email'] : null,
				'acquisition_source'    => $source_code,
				'acquisition_meta_json' => wp_json_encode( $row['meta'] ?? array() ),
				'created_at'            => $now,
				'updated_at'            => $now,
			) );
			if ( $ok ) {
				return (int) $wpdb->insert_id;
			}
		}
		return 0;
	}

	/**
	 * Cheap targeted refresh — call after FB inbound to immediately surface
	 * the new contact in Prospecting (or promote to Qualification if a phone
	 * was extracted into the contact row).
	 */
	public static function sync_for_contact( int $contact_id ): void {
		if ( $contact_id <= 0 ) {
			return;
		}
		global $wpdb;
		$contacts_tbl = $wpdb->prefix . 'bizcity_crm_contacts';
		$ci_tbl       = $wpdb->prefix . 'bizcity_crm_contact_inboxes';
		$inboxes      = $wpdb->prefix . 'bizcity_crm_inboxes';

		$contact = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, first_name, last_name, phone, email, updated_at FROM `{$contacts_tbl}` WHERE id = %d",
			$contact_id
		), ARRAY_A );
		if ( ! $contact ) {
			return;
		}
		$psid = $wpdb->get_var( $wpdb->prepare(
			"SELECT ci.source_id
			 FROM `{$ci_tbl}` ci
			 INNER JOIN `{$inboxes}` i ON i.id = ci.inbox_id
			 WHERE ci.contact_id = %d AND i.channel_type = 'facebook'
			 ORDER BY ci.last_seen_at DESC LIMIT 1",
			$contact_id
		) );
		if ( ! $psid ) {
			return; // not a messenger contact — nothing to do here.
		}
		$phone = preg_replace( '/\D+/', '', (string) ( $contact['phone'] ?? '' ) );
		$name  = trim( (string) $contact['name'] );
		if ( '' === $name ) {
			$name = trim( $contact['first_name'] . ' ' . $contact['last_name'] );
		}
		if ( '' === $name ) {
			$name = 'Khách FB ' . substr( (string) $psid, -4 );
		}
		self::upsert_one( 'messenger', array(
			'external_ref'     => (string) $psid,
			'name'             => $name,
			'phone'            => $phone ?: null,
			'email'            => $contact['email'] ?: null,
			'has_phone'        => (bool) $phone,
			'last_activity_at' => $contact['updated_at'],
			'meta'             => array( 'contact_id' => $contact_id, 'psid' => (string) $psid ),
		) );
	}
}
