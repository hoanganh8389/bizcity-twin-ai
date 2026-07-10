<?php
/**
 * BizCity CRM — Unified Submissions Repository.
 *
 * Cache Contract (R-CACHE):
 *   group: bzcsub
 *   keys:
 *     list_{args_hash}   — paginated list result (rows + total)  TTL: MEDIUM
 *     single_{id}        — single submission row                  TTL: MEDIUM
 *   invalidations:
 *     create / assign / update_status → flush_group( 'bzcsub' )
 *
 * @package BizCity_Twin_CRM
 * @since   1.31.0
 */

// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — new unified submissions repo class

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Submissions_Repo' ) ) :

class BizCity_CRM_Submissions_Repo {

	const CACHE_GROUP     = 'bzcsub';

	const FOLLOW_STATUSES = array(
		'new',
		'contacted',
		'qualified',
		'proposal_sent',
		'negotiating',
		'closed_won',
		'closed_lost',
		'invalid',
	);

	const SOURCE_TYPES = array(
		'cf7',
		'campaign_qr',
		'campaign_ref',
		'loyalty',
		'lucky_wheel',
		'broadcast_reply',
		'webchat_optin',
		'zns_reply',
		'zalo_ref',
		'manual',
		'import',
	);

	/* ─────────────────────────────────────────────────────────────────────────
	   READ
	   ───────────────────────────────────────────────────────────────────────── */

	/**
	 * List submissions with filters.
	 *
	 * @param array $args {
	 *   source_type?        string
	 *   follow_status?      string
	 *   assigned_to?        int    WP user_id (0 = unassigned, -1 = any)
	 *   contact_id?         int
	 *   from?               string YYYY-MM-DD
	 *   to?                 string YYYY-MM-DD
	 *   page?               int    (1-based)
	 *   per_page?           int    default 20
	 *   order_by?           string submitted_at|updated_at
	 *   order?              string DESC|ASC
	 * }
	 * @return array { rows: array, total: int, pages: int }
	 */
	public static function list( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'source_type'   => '',
			'follow_status' => '',
			'assigned_to'   => -1,
			'contact_id'    => 0,
			'from'          => '',
			'to'            => '',
			'page'          => 1,
			'per_page'      => 20,
			'order_by'      => 'submitted_at',
			'order'         => 'DESC',
		) );

		$cache_key = 'list_' . md5( serialize( $args ) );

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$where  = array( 's.deleted_at IS NULL' );
		$params = array();

		if ( $args['source_type'] !== '' ) {
			$where[]  = 's.source_type = %s';
			$params[] = sanitize_key( $args['source_type'] );
		}

		if ( $args['follow_status'] !== '' ) {
			$where[]  = 's.follow_status = %s';
			$params[] = sanitize_key( $args['follow_status'] );
		}

		if ( (int) $args['assigned_to'] === 0 ) {
			$where[] = 's.assigned_to_wp_user_id IS NULL';
		} elseif ( (int) $args['assigned_to'] > 0 ) {
			$where[]  = 's.assigned_to_wp_user_id = %d';
			$params[] = (int) $args['assigned_to'];
		}

		if ( (int) $args['contact_id'] > 0 ) {
			$where[]  = 's.contact_id = %d';
			$params[] = (int) $args['contact_id'];
		}

		if ( $args['from'] !== '' ) {
			$where[]  = 's.submitted_at >= %s';
			$params[] = sanitize_text_field( $args['from'] ) . ' 00:00:00';
		}

		if ( $args['to'] !== '' ) {
			$where[]  = 's.submitted_at <= %s';
			$params[] = sanitize_text_field( $args['to'] ) . ' 23:59:59';
		}

		$allowed_order_by = array( 'submitted_at', 'updated_at', 'follow_status', 'assigned_to_wp_user_id' );
		$order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'submitted_at';
		$order    = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM `{$tbl}` s WHERE {$where_sql}";
		$data_sql  = "SELECT s.*, u.display_name AS assignee_name
		              FROM `{$tbl}` s
		              LEFT JOIN {$wpdb->users} u ON u.ID = s.assigned_to_wp_user_id
		              WHERE {$where_sql}
		              ORDER BY s.{$order_by} {$order}
		              LIMIT %d OFFSET %d";

		if ( ! empty( $params ) ) {
			$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$rows      = $wpdb->get_results( $wpdb->prepare( $data_sql, array_merge( $params, $params, array( $per_page, $offset ) ) ), ARRAY_A );
		} else {
			$total     = (int) $wpdb->get_var( $count_sql );
			$rows      = $wpdb->get_results( $wpdb->prepare( $data_sql, $per_page, $offset ), ARRAY_A );
		}

		$result = array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
		);

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Get a single submission by ID.
	 *
	 * @param int $id
	 * @return array|null Row as assoc array, or null if not found.
	 */
	public static function get_by_id( int $id ) {
		global $wpdb;

		$cache_key = 'single_' . $id;

		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, u.display_name AS assignee_name
				 FROM `{$tbl}` s
				 LEFT JOIN {$wpdb->users} u ON u.ID = s.assigned_to_wp_user_id
				 WHERE s.id = %d AND s.deleted_at IS NULL
				 LIMIT 1",
				$id
			),
			ARRAY_A
		);

		$result = $row ?: null;

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		}

		return $result;
	}

	/* ─────────────────────────────────────────────────────────────────────────
	   WRITE
	   ───────────────────────────────────────────────────────────────────────── */

	/**
	 * Create a new unified submission row.
	 *
	 * @param array $data {
	 *   source_type       string   required
	 *   source_ref_id?    int
	 *   source_meta_json? string   JSON
	 *   contact_email?    string
	 *   contact_phone?    string
	 *   contact_name?     string
	 *   contact_id?       int
	 *   lead_id?          int
	 *   submitted_at?     string   YYYY-MM-DD HH:MM:SS (defaults to now)
	 * }
	 * @return int  Inserted ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$source_type = sanitize_key( $data['source_type'] ?? 'manual' );
		if ( ! in_array( $source_type, self::SOURCE_TYPES, true ) ) {
			$source_type = 'manual';
		}

		$now = current_time( 'mysql' );
		$row = array(
			'source_type'   => $source_type,
			'source_ref_id' => isset( $data['source_ref_id'] ) ? (int) $data['source_ref_id'] : null,
			'source_meta_json' => isset( $data['source_meta_json'] ) ? $data['source_meta_json'] : null,
			'contact_email' => isset( $data['contact_email'] ) ? sanitize_email( $data['contact_email'] ) : null,
			'contact_phone' => isset( $data['contact_phone'] ) ? sanitize_text_field( $data['contact_phone'] ) : null,
			'contact_name'  => isset( $data['contact_name'] )  ? sanitize_text_field( $data['contact_name'] )  : null,
			'contact_id'    => isset( $data['contact_id'] )  && (int) $data['contact_id']  ? (int) $data['contact_id']  : null,
			'lead_id'       => isset( $data['lead_id'] )     && (int) $data['lead_id']     ? (int) $data['lead_id']     : null,
			'follow_status' => 'new',
			'submitted_at'  => isset( $data['submitted_at'] ) ? $data['submitted_at'] : $now,
			'updated_at'    => $now,
		);

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$wpdb->insert( $tbl, $row );
		$id = (int) $wpdb->insert_id;

		if ( $id ) {
			self::flush();
			do_action( 'bizcity_crm_submission_created', $id, $row );
		}

		return $id;
	}

	/**
	 * Assign a submission to a WP user.
	 *
	 * @param int $id             Submission ID.
	 * @param int $wp_user_id     Assignee WP user ID.
	 * @param int $assigned_by    WP user_id of manager doing the assign.
	 * @return bool
	 */
	public static function assign( int $id, int $wp_user_id, int $assigned_by = 0 ): bool {
		global $wpdb;

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$ok  = $wpdb->update(
			$tbl,
			array(
				'assigned_to_wp_user_id'  => $wp_user_id,
				'assigned_at'             => current_time( 'mysql' ),
				'assigned_by_wp_user_id'  => $assigned_by > 0 ? $assigned_by : get_current_user_id(),
				'updated_at'              => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $ok ) {
			self::flush();
			do_action( 'bizcity_crm_submission_assigned', $id, $wp_user_id );
		}

		return false !== $ok;
	}

	/**
	 * Bulk-assign submissions to a WP user.
	 *
	 * @param int[] $ids          Submission IDs.
	 * @param int   $wp_user_id   Assignee.
	 * @param int   $assigned_by  Manager doing the assign.
	 * @return int  Number of rows updated.
	 */
	public static function bulk_assign( array $ids, int $wp_user_id, int $assigned_by = 0 ): int {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$tbl         = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now          = current_time( 'mysql' );
		$assigner     = $assigned_by > 0 ? $assigned_by : get_current_user_id();

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$tbl}` SET
				 assigned_to_wp_user_id = %d,
				 assigned_at            = %s,
				 assigned_by_wp_user_id = %d,
				 updated_at             = %s
				 WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
				array_merge( array( $wp_user_id, $now, $assigner, $now ), $ids )
			)
		);

		if ( $updated ) {
			self::flush();
			do_action( 'bizcity_crm_submissions_bulk_assigned', $ids, $wp_user_id );
		}

		return (int) $updated;
	}

	/**
	 * Update follow_status.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $status New follow_status value.
	 * @return bool
	 */
	public static function update_status( int $id, string $status ): bool {
		global $wpdb;

		$status = sanitize_key( $status );
		if ( ! in_array( $status, self::FOLLOW_STATUSES, true ) ) {
			return false;
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$row = self::get_by_id( $id );
		$old = $row ? $row['follow_status'] : '';

		$ok = $wpdb->update(
			$tbl,
			array( 'follow_status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $ok ) {
			self::flush();
			do_action( 'bizcity_crm_submission_status_changed', $id, $old, $status );
		}

		return false !== $ok;
	}

	/**
	 * Link a submission to a pipeline opportunity.
	 *
	 * @param int $id     Submission ID.
	 * @param int $opp_id Opportunity ID.
	 * @return bool
	 */
	public static function set_pipeline_opp( int $id, int $opp_id ): bool {
		global $wpdb;

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$ok  = $wpdb->update(
			$tbl,
			array( 'pipeline_opp_id' => $opp_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false !== $ok ) {
			self::flush();
		}

		return false !== $ok;
	}

	/**
	 * Create or update a unified submission row from a CF7 submission.
	 * Called by BizCity_CRM_Lead_Source_CF7 after inserting into bizcity_cf7_submissions.
	 *
	 * @param int   $cf7_submission_id  ID of the bizcity_cf7_submissions row.
	 * @param array $cf7_data           Raw CF7 data (email, phone, name, form_id, ...).
	 * @return int  Unified submission ID (created or existing).
	 */
	public static function sync_from_cf7( int $cf7_submission_id, array $cf7_data = array() ): int {
		global $wpdb;

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();

		// Check for existing unified row linked to this cf7 submission
		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `{$tbl}` WHERE source_type = 'cf7' AND source_ref_id = %d AND deleted_at IS NULL LIMIT 1",
				$cf7_submission_id
			)
		);

		if ( $existing_id ) {
			return $existing_id;
		}

		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — merge source_meta (UTM/device) if provided
		$meta = array(
			'form_id'    => isset( $cf7_data['form_id'] ) ? (int) $cf7_data['form_id'] : 0,
			'form_title' => isset( $cf7_data['form_title'] ) ? sanitize_text_field( $cf7_data['form_title'] ) : '',
		);
		if ( ! empty( $cf7_data['source_meta'] ) && is_array( $cf7_data['source_meta'] ) ) {
			$meta = array_merge( $meta, $cf7_data['source_meta'] );
		}

		$unified_id = self::create( array(
			'source_type'      => 'cf7',
			'source_ref_id'    => $cf7_submission_id,
			'source_meta_json' => wp_json_encode( $meta ),
			'contact_email'    => $cf7_data['email']  ?? '',
			'contact_phone'    => $cf7_data['phone']  ?? '',
			'contact_name'     => $cf7_data['name']   ?? '',
			'contact_id'       => isset( $cf7_data['contact_id'] ) ? (int) $cf7_data['contact_id'] : 0,
			'submitted_at'     => $cf7_data['submitted_at'] ?? current_time( 'mysql' ),
		) );

		// Write back-link into bizcity_cf7_submissions (ADD-only col from migration)
		if ( $unified_id ) {
			$cf7_tbl = $wpdb->prefix . 'bizcity_cf7_submissions';
			$wpdb->update(
				$cf7_tbl,
				array( 'unified_submission_id' => $unified_id ),
				array( 'id' => $cf7_submission_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		return $unified_id;
	}

	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — hook-based sync from bizcity_crm_lead_created

	/**
	 * Derive source_type from a source string like 'cf7:123', 'campaign_qr', 'loyalty', etc.
	 *
	 * @param string $source
	 * @return string
	 */
	private static function source_type_from_string( string $source ): string {
		$s = strtolower( explode( ':', $source )[0] );
		$map = array(
			'cf7'              => 'cf7',
			'campaign'         => 'campaign_qr',
			'campaign_qr'      => 'campaign_qr',
			'campaign_ref'     => 'campaign_ref',
			'loyalty'          => 'loyalty',
			'lucky_wheel'      => 'lucky_wheel',
			'broadcast_reply'  => 'broadcast_reply',
			'webchat'          => 'webchat_optin',
			'webchat_optin'    => 'webchat_optin',
			'zns'              => 'zns_reply',
			'zns_reply'        => 'zns_reply',
			'zalo_ref'         => 'zalo_ref',
			'comment'          => 'manual',
			'manual'           => 'manual',
			'import'           => 'import',
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : 'manual';
	}

	/**
	 * Create unified submission row from lead capture data.
	 * Hooks on bizcity_crm_lead_created + bizcity_crm_lead_recaptured.
	 *
	 * @param int    $lead_id
	 * @param array  $lead_row  Normalized row (id, name, email, phone, source).
	 */
	public static function sync_from_lead_capture( int $lead_id, array $lead_row ): void {
		global $wpdb;

		if ( ! $lead_id ) {
			return;
		}

		$tbl        = BizCity_CRM_DB_Installer_V2::tbl_crm_submissions();
		$email      = sanitize_email( (string) ( $lead_row['email'] ?? '' ) );
		$phone      = (string) ( $lead_row['phone'] ?? '' );
		$source_str = (string) ( $lead_row['source'] ?? 'manual' );
		$src_type   = self::source_type_from_string( $source_str );
		$now        = current_time( 'mysql' );
		$window     = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );

		// Dedup: same email+phone within 5 minutes — avoid duplicate on recapture
		$where = array( "source_type = %s", "deleted_at IS NULL", "submitted_at >= %s" );
		$args  = array( $src_type, $window );
		if ( $email ) {
			$where[] = 'contact_email = %s';
			$args[]  = $email;
		} elseif ( $phone ) {
			$where[] = 'contact_phone = %s';
			$args[]  = $phone;
		} else {
			return; // no identity, skip
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT id FROM `{$tbl}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 1',
				...$args
			)
		);

		if ( $existing_id ) {
			// Update lead_id back-link if not already set
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$tbl}` SET lead_id = %d WHERE id = %d AND ( lead_id IS NULL OR lead_id = 0 )",
					$lead_id,
					$existing_id
				)
			);
			return;
		}

		$name_parts = explode( ' ', (string) ( $lead_row['name'] ?? '' ), 2 );

		// [2026-07-02 Johnny Chu] PHASE-0.47 W2 — pull referral block from lead capture_events[last].meta.referral
		$referral  = array();
		$custom_raw = (string) ( $lead_row['custom_json'] ?? '' );
		if ( $custom_raw !== '' ) {
			$custom = json_decode( $custom_raw, true );
			if ( is_array( $custom ) && ! empty( $custom['capture_events'] ) && is_array( $custom['capture_events'] ) ) {
				$last_event = end( $custom['capture_events'] );
				if ( is_array( $last_event ) && isset( $last_event['meta']['referral'] ) && is_array( $last_event['meta']['referral'] ) ) {
					$referral = $last_event['meta']['referral'];
				}
			}
		}

		$source_meta = array( 'source_raw' => $source_str );
		if ( ! empty( $referral ) ) {
			$source_meta['referral'] = $referral;
		}

		self::create( array(
			'source_type'      => $src_type,
			'source_meta_json' => wp_json_encode( $source_meta ),
			'contact_email'    => $email,
			'contact_phone'    => $phone,
			'contact_name'     => $lead_row['name'] ?? '',
			'lead_id'          => $lead_id,
			'submitted_at'     => $now,
		) );
	}

	/* ─────────────────────────────────────────────────────────────────────────
	   CACHE HELPERS
	   ───────────────────────────────────────────────────────────────────────── */

	private static function flush(): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}
}

endif; // class_exists BizCity_CRM_Submissions_Repo

// ── Cache Registry (R-CACHE) ─────────────────────────────────────────────────
// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — register cache group
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzcsub', 'modules.twin-crm', array(
		'list_{args_hash}'  => array( 'ttl' => 300, 'desc' => 'Paginated list of unified submissions (keyed by filter hash)' ),
		'single_{id}'       => array( 'ttl' => 300, 'desc' => 'Single submission row by ID' ),
	) );
}
