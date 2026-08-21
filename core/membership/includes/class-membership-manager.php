<?php
/**
 * Bizcity Twin AI — Membership_Manager
 *
 * PHASE-MEMBERSHIP M1.
 *
 * Facade for client-side membership plans:
 *  - Owns table bizcity_member_subscriptions (assignment + lifecycle).
 *  - Resolves the effective plan slug for a user
 *    (active subscription → user_meta override → role map → default).
 *  - Manual assign / expire from admin.
 *
 * Money note: membership revenue is collected by the CLIENT's own PayPal and
 * is a DIFFERENT money type from the hub LLM credit (R-GW-8). This core never
 * talks to bizcity-llm-router for billing.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Manager {

	const DB_VERSION        = '1.2.0';
	const OPT_DB_VERSION    = 'bizcity_membership_db_version';

	const META_PLAN         = 'bizcity_member_plan';        // user_meta override slug
	const META_VALID_UNTIL  = 'bizcity_member_valid_until'; // user_meta Y-m-d H:i:s | ''
	const META_SOURCE       = 'bizcity_member_source';      // user_meta admin|paypal|...

	const STATUS_ACTIVE     = 'active';
	const STATUS_PENDING    = 'pending';
	const STATUS_EXPIRED    = 'expired';
	const STATUS_CANCELLED  = 'cancelled';

	const SOURCE_ADMIN      = 'admin';
	const SOURCE_PAYPAL     = 'paypal';
	const SOURCE_DEFAULT    = 'default';

	/** @var BizCity_Membership_Manager|null */
	private static $instance = null;

	/** @var int|null */
	private $seat_used_cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ── Schema ─────────────────────────────────────────────────────────── */

	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_member_subscriptions';
	}

	/**
	 * Create / migrate the subscriptions table. Idempotent (ADD-only via dbDelta).
	 *
	 * @return void
	 */
	public function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();
		$t  = $this->table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			plan_slug VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			start_date DATETIME NULL DEFAULT NULL,
			expiration_date DATETIME NULL DEFAULT NULL,
			paypal_subscription_id VARCHAR(64) NOT NULL DEFAULT '',
			source VARCHAR(32) NOT NULL DEFAULT 'admin',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (user_id),
			KEY idx_status (status),
			KEY idx_plan (plan_slug)
		) {$cs};" );
		// [2026-08-21 Johnny Chu] R-METADATA-CACHE — make the new subscription table visible to same-request probes.
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $t );
		}
	}

	/**
	 * Version-gated migration. Cheap to call on admin_init / plugins_loaded.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$current = get_option( self::OPT_DB_VERSION, '' );
		$tables_ready = function_exists( 'bizcity_tbl_exists' )
			&& bizcity_tbl_exists( self::table() )
			&& ( ! class_exists( 'BizCity_Membership_Usage' ) || bizcity_tbl_exists( BizCity_Membership_Usage::instance()->table() ) )
			&& ( ! class_exists( 'BizCity_Membership_Payments' ) || bizcity_tbl_exists( BizCity_Membership_Payments::instance()->table() ) );
		// [2026-08-21 Johnny Chu] DIAGNOSTICS-SCHEMA-SELF-HEAL — a current version option cannot mask missing tenant tables.
		if ( version_compare( (string) $current, self::DB_VERSION, '>=' ) && $tables_ready ) {
			return;
		}
		$this->ensure_table();
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M3 — usage counter table (v1.1.0).
		if ( class_exists( 'BizCity_Membership_Usage' ) ) {
			BizCity_Membership_Usage::instance()->ensure_table();
		}
		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M4 — payments ledger table (v1.2.0).
		if ( class_exists( 'BizCity_Membership_Payments' ) ) {
			BizCity_Membership_Payments::instance()->ensure_table();
		}
		update_option( self::OPT_DB_VERSION, self::DB_VERSION );
	}

	/* ── Resolution ─────────────────────────────────────────────────────── */

	/**
	 * Resolve the effective plan slug for a user.
	 *
	 * Order:
	 *   1. Active, non-expired row in bizcity_member_subscriptions (latest start).
	 *   2. user_meta override (bizcity_member_plan) if not expired.
	 *   3. Role map (first matching role).
	 *   4. Registry default plan (usually 'free').
	 *
	 * @param int $user_id
	 * @return string plan slug
	 */
	public function plan_for_user( $user_id ) {
		$user_id  = (int) $user_id;
		$registry = BizCity_Membership_Plan_Registry::instance();

		if ( $user_id > 0 ) {
			$slug = $this->active_subscription_plan( $user_id );
			if ( $slug !== '' && $registry->exists( $slug ) ) {
				return $this->filter_plan( $slug, $user_id );
			}

			// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
			$meta_slug = class_exists( 'BizCity_User_Meta_Cache' )
				? (string) BizCity_User_Meta_Cache::get( $user_id, self::META_PLAN, '' )
				: (string) get_user_meta( $user_id, self::META_PLAN, true );
			if ( $meta_slug !== '' && $registry->exists( $meta_slug ) && ! $this->meta_expired( $user_id ) ) {
				return $this->filter_plan( $meta_slug, $user_id );
			}

			$role_slug = $this->plan_from_roles( $user_id );
			if ( $role_slug !== '' && $registry->exists( $role_slug ) ) {
				return $this->filter_plan( $role_slug, $user_id );
			}
		}

		return $this->filter_plan( $registry->default_slug(), $user_id );
	}

	/**
	 * Allow integrations (e.g. PMS bridge) to override the resolved slug.
	 *
	 * @param string $slug
	 * @param int    $user_id
	 * @return string
	 */
	private function filter_plan( $slug, $user_id ) {
		/**
		 * Filter the resolved plan slug for a user.
		 *
		 * @param string $slug
		 * @param int    $user_id
		 */
		$out = apply_filters( 'bizcity_membership_plan_for_user', $slug, (int) $user_id );
		$out = sanitize_key( (string) $out );
		return $out !== '' ? $out : 'free';
	}

	/**
	 * Latest active, non-expired subscription plan slug, or '' if none.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private function active_subscription_plan( $user_id ) {
		// [2026-07-02 Johnny Chu] HOTFIX R-SHOW-TABLES — guard: check table exists before querying.
		// maybe_upgrade() is option-gated and may have been set before dbDelta completed
		// (e.g. on a new multisite blog). Query information_schema to verify actual existence.
		global $wpdb;
		$t = $this->table();

		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			$tbl_ok = bizcity_tbl_exists( $t );
		} else {
			// Fallback if helper not loaded — dual cache inline.
			static $s_tbl = array();
			$ck = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $t );
			if ( ! isset( $s_tbl[ $t ] ) ) {
				$p = wp_cache_get( $ck, 'bizcity_tbl' );
				if ( false === $p ) {
					$p = (int) (bool) $wpdb->get_var( $wpdb->prepare(
						'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
						$t
					) );
					wp_cache_set( $ck, $p, 'bizcity_tbl', HOUR_IN_SECONDS );
				}
				$s_tbl[ $t ] = (bool) $p;
			}
			$tbl_ok = $s_tbl[ $t ];
		}

		if ( ! $tbl_ok ) {
			// Self-heal: create table, invalidate cache, then return '' (no subscription).
			$this->ensure_table();
			wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $t ), 'bizcity_tbl' );
			return '';
		}

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT plan_slug FROM {$t}
			 WHERE user_id = %d
			   AND status = %s
			   AND ( expiration_date IS NULL OR expiration_date >= %s )
			 ORDER BY start_date DESC, id DESC
			 LIMIT 1",
			$user_id,
			self::STATUS_ACTIVE,
			$now
		);
		$slug = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $slug ? sanitize_key( (string) $slug ) : '';
	}

	/**
	 * Whether the user_meta override has expired.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private function meta_expired( $user_id ) {
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$until = class_exists( 'BizCity_User_Meta_Cache' )
			? (string) BizCity_User_Meta_Cache::get( $user_id, self::META_VALID_UNTIL, '' )
			: (string) get_user_meta( $user_id, self::META_VALID_UNTIL, true );
		if ( $until === '' ) {
			return false; // no expiry = lifetime override.
		}
		$ts = strtotime( $until );
		if ( ! $ts ) {
			return false;
		}
		return $ts < current_time( 'timestamp' );
	}

	/**
	 * Map a user's roles to a plan via the registry role_map.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private function plan_from_roles( $user_id ) {
		$map = BizCity_Membership_Plan_Registry::instance()->role_map();
		if ( empty( $map ) ) {
			return '';
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return '';
		}
		foreach ( (array) $user->roles as $role ) {
			if ( isset( $map[ $role ] ) && $map[ $role ] !== '' ) {
				return sanitize_key( (string) $map[ $role ] );
			}
		}
		return '';
	}

	/* ── Assignment ─────────────────────────────────────────────────────── */

	/**
	 * Assign a plan to a user. Writes a subscription row AND a user_meta
	 * override (fast-path for resolution + display).
	 *
	 * @param int    $user_id
	 * @param string $plan_slug
	 * @param string $valid_until  Y-m-d H:i:s, or '' for lifetime.
	 * @param string $source       admin|paypal|...
	 * @param string $paypal_sub_id PayPal subscription id (recurring), or ''.
	 * @return int|false subscription row id, or false on failure.
	 */
	public function set_plan( $user_id, $plan_slug, $valid_until = '', $source = self::SOURCE_ADMIN, $paypal_sub_id = '' ) {
		$user_id = (int) $user_id;
		$plan_slug = sanitize_key( (string) $plan_slug );
		if ( $user_id <= 0 || $plan_slug === '' ) {
			return false;
		}
		if ( ! BizCity_Membership_Plan_Registry::instance()->exists( $plan_slug ) ) {
			return false;
		}

		$valid_until   = $this->sanitize_datetime( $valid_until );
		$source        = sanitize_key( (string) $source );
		$source        = $source !== '' ? $source : self::SOURCE_ADMIN;
		$paypal_sub_id = sanitize_text_field( (string) $paypal_sub_id );
		$now           = current_time( 'mysql' );

		// Expire any other active rows for this user (one active plan at a time).
		$this->expire_active_rows( $user_id );

		global $wpdb;
		$ok = $wpdb->insert(
			$this->table(),
			array(
				'user_id'                => $user_id,
				'plan_slug'              => $plan_slug,
				'status'                 => self::STATUS_ACTIVE,
				'start_date'             => $now,
				'expiration_date'        => $valid_until !== '' ? $valid_until : null,
				'paypal_subscription_id' => $paypal_sub_id,
				'source'                 => $source,
				'created_at'             => $now,
				'updated_at'             => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return false;
		}
		$row_id = (int) $wpdb->insert_id;

		// [2026-07-27 Johnny Chu] R-PERF — keep membership metadata reads/writes on the unified cache contract.
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::set( $user_id, self::META_PLAN, $plan_slug );
			BizCity_User_Meta_Cache::set( $user_id, self::META_VALID_UNTIL, $valid_until );
			BizCity_User_Meta_Cache::set( $user_id, self::META_SOURCE, $source );
		} else {
			update_user_meta( $user_id, self::META_PLAN, $plan_slug );
			update_user_meta( $user_id, self::META_VALID_UNTIL, $valid_until );
			update_user_meta( $user_id, self::META_SOURCE, $source );
		}

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — keep entitlement snapshot
		// consistent after plan mutation within same request.
		if ( class_exists( 'BizCity_Membership_Entitlement' ) ) {
			BizCity_Membership_Entitlement::instance()->flush_cache( $user_id );
		}

		// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — invalidate in-request seat usage cache after plan assignment.
		$this->seat_used_cache = null;

		/**
		 * Fires after a membership plan is assigned to a user.
		 *
		 * @param int    $user_id
		 * @param string $plan_slug
		 * @param string $source
		 * @param int    $row_id
		 */
		do_action( 'bizcity_membership_plan_assigned', $user_id, $plan_slug, $source, $row_id );

		return $row_id;
	}

	/**
	 * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7-recurring — extend the active
	 * subscription's expiration_date (used on a recurring PayPal renewal). If no
	 * active row matches the PayPal subscription id, falls back to the user's
	 * latest active row. Returns the affected user_id, or 0.
	 *
	 * @param string $paypal_sub_id
	 * @param string $new_expiry  Y-m-d H:i:s
	 * @return int user_id
	 */
	public function extend_by_paypal_subscription( $paypal_sub_id, $new_expiry ) {
		global $wpdb;
		$sub_id = sanitize_text_field( (string) $paypal_sub_id );
		$expiry = $this->sanitize_datetime( $new_expiry );
		if ( $sub_id === '' || $expiry === '' ) {
			return 0;
		}
		$t = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id FROM {$t}
				 WHERE paypal_subscription_id = %s AND status = %s
				 ORDER BY id DESC LIMIT 1",
				$sub_id,
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return 0;
		}
		$wpdb->update(
			$t,
			array( 'expiration_date' => $expiry, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$user_id = (int) $row['user_id'];
		if ( $user_id > 0 ) {
			if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
				BizCity_User_Meta_Cache::set( $user_id, self::META_VALID_UNTIL, $expiry );
			} else {
				update_user_meta( $user_id, self::META_VALID_UNTIL, $expiry );
			}
		}
		return $user_id;
	}

	/**
	 * Mark all active rows for a user as expired/cancelled (no new plan).
	 *
	 * @param int    $user_id
	 * @param string $status  STATUS_EXPIRED | STATUS_CANCELLED
	 * @return void
	 */
	public function clear_plan( $user_id, $status = self::STATUS_CANCELLED ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$this->expire_active_rows( $user_id, $status );
		delete_user_meta( $user_id, self::META_PLAN );
		delete_user_meta( $user_id, self::META_VALID_UNTIL );
		delete_user_meta( $user_id, self::META_SOURCE );
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::invalidate( $user_id, self::META_PLAN );
			BizCity_User_Meta_Cache::invalidate( $user_id, self::META_VALID_UNTIL );
			BizCity_User_Meta_Cache::invalidate( $user_id, self::META_SOURCE );
		}

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — invalidate entitlement cache
		// so `/me` immediately reflects downgrade/cancel in same request.
		if ( class_exists( 'BizCity_Membership_Entitlement' ) ) {
			BizCity_Membership_Entitlement::instance()->flush_cache( $user_id );
		}

		// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — invalidate in-request seat usage cache after plan clear.
		$this->seat_used_cache = null;

		/**
		 * Fires after a user's membership plan is cleared.
		 *
		 * @param int    $user_id
		 * @param string $status
		 */
		do_action( 'bizcity_membership_plan_cleared', $user_id, $status );
	}

	/**
	 * Flip all active rows for a user to a terminal status.
	 *
	 * @param int    $user_id
	 * @param string $status
	 * @return void
	 */
	private function expire_active_rows( $user_id, $status = self::STATUS_EXPIRED ) {
		global $wpdb;
		$status = sanitize_key( (string) $status );
		$wpdb->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array(
				'user_id' => (int) $user_id,
				'status'  => self::STATUS_ACTIVE,
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — expire/cancel mutations affect seat usage.
		$this->seat_used_cache = null;
	}

	/**
	 * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — expire active subscriptions
	 * whose expiration_date has passed. Each affected user is downgraded to the
	 * default (free) plan via clear_plan() so entitlement resolution falls back
	 * cleanly. Idempotent: already-expired/lifetime rows are untouched.
	 *
	 * @param int $limit Max rows to process per sweep (cron safety cap).
	 * @return array { scanned:int, expired:int, user_ids:int[], errors:int }
	 */
	public function expire_due( $limit = 200 ) {
		global $wpdb;
		$t     = $this->table();
		$now   = current_time( 'mysql' );
		$limit = max( 1, (int) $limit );

		// [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — guard: bail if table absent
		// (fresh subsite / pre-provision). Avoids "table doesn't exist" cron error.
		$exists = bizcity_tbl_exists( $t ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			return array( 'scanned' => 0, 'expired' => 0, 'user_ids' => array(), 'errors' => 0, 'skipped' => 'no_table' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, plan_slug, expiration_date FROM {$t}
				 WHERE status = %s
				   AND expiration_date IS NOT NULL
				   AND expiration_date <> ''
				   AND expiration_date < %s
				 ORDER BY expiration_date ASC
				 LIMIT %d",
				self::STATUS_ACTIVE,
				$now,
				$limit
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$summary = array(
			'scanned'  => count( $rows ),
			'expired'  => 0,
			'user_ids' => array(),
			'errors'   => 0,
		);

		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id <= 0 ) {
				$summary['errors']++;
				continue;
			}
			// Downgrade: mark active rows expired + drop the user_meta override
			// so plan_for_user() resolves back to the default (free) plan.
			$this->clear_plan( $user_id, self::STATUS_EXPIRED );
			$summary['expired']++;
			$summary['user_ids'][] = $user_id;

			/**
			 * Fires after a user's plan expires via the sweep.
			 *
			 * @param int    $user_id
			 * @param string $plan_slug  the plan that just expired
			 */
			do_action( 'bizcity_membership_plan_expired', $user_id, isset( $row['plan_slug'] ) ? (string) $row['plan_slug'] : '' );
		}

		return $summary;
	}

	/**
	 * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP M7 — cancel the membership tied to
	 * a PayPal subscription id (used by the webhook on BILLING.SUBSCRIPTION.*).
	 *
	 * @param string $paypal_subscription_id
	 * @param string $status  STATUS_CANCELLED | STATUS_EXPIRED
	 * @return int affected user_id, or 0 if none matched.
	 */
	public function cancel_by_paypal_subscription( $paypal_subscription_id, $status = self::STATUS_CANCELLED ) {
		global $wpdb;
		$sub_id = sanitize_text_field( (string) $paypal_subscription_id );
		if ( $sub_id === '' ) {
			return 0;
		}
		$t = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$t}
				 WHERE paypal_subscription_id = %s AND status = %s
				 ORDER BY id DESC LIMIT 1",
				$sub_id,
				self::STATUS_ACTIVE
			)
		);
		if ( $user_id <= 0 ) {
			return 0;
		}
		$this->clear_plan( $user_id, sanitize_key( (string) $status ) );

		/**
		 * Fires after a PayPal subscription cancellation downgrades a user.
		 *
		 * @param int    $user_id
		 * @param string $paypal_subscription_id
		 * @param string $status
		 */
		do_action( 'bizcity_membership_subscription_cancelled', $user_id, $sub_id, $status );

		return $user_id;
	}

	/**
	 * Latest subscription row (any status) for a user, or null.
	 *
	 * @param int $user_id
	 * @return array|null
	 */
	public function latest_subscription( $user_id ) {
		global $wpdb;
		$t = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
				(int) $user_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Return user_ids whose active subscription expires on exactly the given date (Y-m-d).
	 * Used by the cron to send expiry warning emails at 7/3/1 day thresholds.
	 *
	 * [2026-07-17 Johnny Chu] PHASE-D G-6 — expiry warning helper for cron.
	 *
	 * @param string $date  Y-m-d
	 * @return int[]
	 */
	public function users_expiring_on( $date ) {
		global $wpdb;
		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array();
		}
		$t = $this->table();

		// [2026-06-08 Johnny Chu] PHASE-MEMBERSHIP M7 — guard: bail if table absent
		// (fresh subsite / pre-provision). Mirrors expire_due() guard to prevent
		// "Table doesn't exist" DB errors on blogs without membership provisioned.
		$exists = bizcity_tbl_exists( $t ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			return array();
		}

		$like = $date . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$t}
				 WHERE status = %s AND expiration_date LIKE %s",
				self::STATUS_ACTIVE,
				$like
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Count licensed seats currently consumed by active non-admin members.
	 *
	 * Seat semantics:
	 * - only latest active row per user is considered
	 * - plan must have consumes_seat=true
	 * - administrators are excluded
	 * - deleted users are excluded (avoid ghost seat count)
	 *
	 * @param bool $force_refresh True to bypass in-request cache.
	 * @return int
	 */
	public function count_seat_used( $force_refresh = false ) {
		$force_refresh = (bool) $force_refresh;

		if ( ! $force_refresh && null !== $this->seat_used_cache ) {
			return (int) $this->seat_used_cache;
		}

		global $wpdb;
		$table = $this->table();
		if ( ! $this->seat_counter_table_exists( $table ) ) {
			$this->seat_used_cache = 0;
			return 0;
		}

		$now = current_time( 'mysql' );
		// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — canonical distinct-seat counter for active subscriptions.
		$sql = $wpdb->prepare(
			"SELECT user_id, plan_slug
			 FROM {$table}
			 WHERE status = %s
			   AND ( expiration_date IS NULL OR expiration_date = '' OR expiration_date >= %s )
			 ORDER BY id DESC",
			self::STATUS_ACTIVE,
			$now
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = is_array( $rows ) ? $rows : array();

		$seen = array();
		$used = 0;
		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id <= 0 || isset( $seen[ $user_id ] ) ) {
				continue;
			}
			$seen[ $user_id ] = 1;

			$user = get_userdata( $user_id );
			if ( ! $user || ! is_object( $user ) ) {
				continue;
			}

			if ( $this->seat_counter_is_admin_user( $user_id ) ) {
				continue;
			}

			$plan_slug = sanitize_key( (string) ( $row['plan_slug'] ?? '' ) );
			if ( $this->seat_counter_plan_consumes_seat( $plan_slug ) ) {
				$used++;
			}
		}

		$this->seat_used_cache = (int) $used;
		return (int) $this->seat_used_cache;
	}

	/**
	 * @param string $table_name
	 * @return bool
	 */
	private function seat_counter_table_exists( $table_name ) {
		$table_name = (string) $table_name;
		if ( $table_name === '' ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}

		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		return $present === 1;
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	private function seat_counter_is_admin_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * @param string $plan_slug
	 * @return bool
	 */
	private function seat_counter_plan_consumes_seat( $plan_slug ) {
		$plan_slug = sanitize_key( (string) $plan_slug );
		if ( $plan_slug === '' || ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return false;
		}
		$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		return ! empty( $plan['consumes_seat'] );
	}

	/* ── Helpers ────────────────────────────────────────────────────────── */

	/**
	 * Validate / normalize a Y-m-d H:i:s datetime string. '' stays ''.
	 *
	 * @param string $value
	 * @return string
	 */
	private function sanitize_datetime( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return '';
		}
		$ts = strtotime( $value );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
