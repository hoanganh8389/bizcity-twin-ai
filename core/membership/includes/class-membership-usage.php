<?php
/**
 * Bizcity Twin AI — Membership_Usage
 *
 * PHASE-MEMBERSHIP M3.
 *
 * Per-user, per-day, per-feature request counter + gate. Lets the plan limits
 * (chat / image / kg) actually be enforced per WP user, resetting daily by UTC
 * date (mirrors bizcity_kg_usage_log).
 *
 * Effective limit comes from BizCity_Membership_Entitlement (already clamps the
 * user plan by the hub site-tier ceiling). 0 = blocked, negative = unlimited.
 *
 * Owns table bizcity_member_usage (declared in core.membership.json @1.1.0).
 *
 * PHP 7.4-safe.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Usage {

	/** Feature key → entitlement limit key. */
	const LIMIT_MAP = array(
		'chat'  => 'chat_msgs_per_day',
		'image' => 'image_per_day',
		'kg'    => 'kg_passages_per_day',
	);

	/** @var BizCity_Membership_Usage|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ── Schema ─────────────────────────────────────────────────────────── */

	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_member_usage';
	}

	/**
	 * Create the usage table. Idempotent (ADD-only via dbDelta).
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
			day DATE NOT NULL,
			feature VARCHAR(32) NOT NULL DEFAULT '',
			count INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_day_feature (user_id, day, feature)
		) {$cs};" );
	}

	/* ── Gate API ───────────────────────────────────────────────────────── */

	/**
	 * Today's UTC date (sync with bizcity_kg_usage_log day column).
	 *
	 * @return string Y-m-d
	 */
	private function today() {
		return gmdate( 'Y-m-d' );
	}

	/**
	 * Effective daily limit for a feature (0 = blocked, < 0 = unlimited).
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @return int
	 */
	public function limit_for( $user_id, $feature ) {
		$feature = sanitize_key( (string) $feature );
		if ( ! isset( self::LIMIT_MAP[ $feature ] ) ) {
			return -1; // unknown feature = not gated.
		}
		if ( ! class_exists( 'BizCity_Membership_Entitlement' ) ) {
			return -1;
		}
		$limit_key = self::LIMIT_MAP[ $feature ];
		return (int) BizCity_Membership_Entitlement::instance()->limit( (int) $user_id, $limit_key );
	}

	/**
	 * Count used today for a feature.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @return int
	 */
	public function used( $user_id, $feature ) {
		global $wpdb;
		$t = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$t} WHERE user_id = %d AND day = %s AND feature = %s LIMIT 1",
				(int) $user_id,
				$this->today(),
				sanitize_key( (string) $feature )
			)
		);
		return $val ? (int) $val : 0;
	}

	/**
	 * Remaining quota today (PHP_INT_MAX when unlimited).
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @return int
	 */
	public function remaining( $user_id, $feature ) {
		$limit = $this->limit_for( $user_id, $feature );
		if ( $limit < 0 ) {
			return PHP_INT_MAX;
		}
		$left = $limit - $this->used( $user_id, $feature );
		return $left > 0 ? $left : 0;
	}

	/**
	 * Whether the user may perform N more units of a feature today.
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @param int    $units
	 * @return bool
	 */
	public function can( $user_id, $feature, $units = 1 ) {
		$limit = $this->limit_for( $user_id, $feature );
		if ( $limit < 0 ) {
			return true; // unlimited / not gated.
		}
		if ( $limit === 0 ) {
			return false; // feature not allowed on this plan.
		}
		$units = max( 1, (int) $units );
		return ( $this->used( $user_id, $feature ) + $units ) <= $limit;
	}

	/**
	 * Increment today's counter for a feature (atomic upsert).
	 *
	 * @param int    $user_id
	 * @param string $feature
	 * @param int    $units
	 * @return void
	 */
	public function incr( $user_id, $feature, $units = 1 ) {
		$user_id = (int) $user_id;
		$feature = sanitize_key( (string) $feature );
		$units   = max( 1, (int) $units );
		if ( $user_id <= 0 || $feature === '' ) {
			return;
		}
		global $wpdb;
		$t   = $this->table();
		$day = $this->today();
		// Atomic upsert — unique key (user_id, day, feature) handles concurrency.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$t} (user_id, day, feature, count)
				 VALUES (%d, %s, %s, %d)
				 ON DUPLICATE KEY UPDATE count = count + %d",
				$user_id,
				$day,
				$feature,
				$units,
				$units
			)
		);
	}

	/**
	 * Snapshot of all feature usage today for a user (for FE /membership/me).
	 *
	 * @param int $user_id
	 * @return array feature => { used, limit, remaining }
	 */
	public function snapshot( $user_id ) {
		$out = array();
		foreach ( array_keys( self::LIMIT_MAP ) as $feature ) {
			$limit = $this->limit_for( $user_id, $feature );
			$used  = $this->used( $user_id, $feature );
			$out[ $feature ] = array(
				'used'      => $used,
				'limit'     => $limit,
				'remaining' => $limit < 0 ? -1 : max( 0, $limit - $used ),
			);
		}
		return $out;
	}
}
