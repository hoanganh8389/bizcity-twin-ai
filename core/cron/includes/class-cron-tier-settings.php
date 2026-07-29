<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Tier_Settings — tier-based cron interval resolver + file-first flags.
 *
 * [2026-07-15 Johnny Chu] R-PERF / R-CRON-TIER — chia nhỏ tần suất cron theo
 * license tier để tránh over-load trên multisite (~1.400 blog):
 *   - free    → 10 phút / lần
 *   - pro     → 5 phút / lần
 *   - premium → 1 phút / lần
 *
 * Đọc tier bằng get_option('bizcity_hub_master_level') (KHÔNG gọi HTTP trong bind()).
 * Interval (phút) cấu hình được qua Network Admin UI (site option / network option),
 * lưu tại option `bizcity_cron_tier_minutes` = [ 'free'=>10, 'pro'=>5, 'premium'=>1 ].
 *
 * File-first flags (hard-cut defaults, 2026-07-23):
 *   - bizcity_kg_v06_dual_write_enabled  (legacy central dual-write default OFF)
 *   - bizcity_kg_v06_read_switch_enabled (default ON)
 *   - bizcity_kg_v07_file_primary_write_enabled (default ON)
 *   - bizcity_kg_v08_graph_embedding_migration_enabled (default ON)
 *   - bizcity_kg_v09_triplet_raw_migration_enabled (default ON)
 *
 * PHP 7.4 compat: không match(), union type, nullsafe.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Tier_Settings {

	/** Option lưu map tier => số phút. Network-scoped trên multisite. */
	const OPT_TIER_MINUTES = 'bizcity_cron_tier_minutes';

	/** File-first flags. */
	const OPT_FILE_FIRST_DUAL  = 'bizcity_kg_v06_dual_write_enabled';
	const OPT_FILE_FIRST_READ  = 'bizcity_kg_v06_read_switch_enabled';
	// [2026-07-15 Johnny Chu] PHASE-FILE-PRIMARY — rollout option for file-primary write mode.
	const OPT_FILE_PRIMARY     = 'bizcity_kg_v07_file_primary_write_enabled';
	// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — migrate graph embedding LONGTEXT to .embed.bin sidecars.
	const OPT_GRAPH_EMBEDDING_MIGRATION = 'bizcity_kg_v08_graph_embedding_migration_enabled';
	// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — migrate triplet raw payload TEXT to notebook JSONL and scrub SQL.
	const OPT_TRIPLET_RAW_MIGRATION = 'bizcity_kg_v09_triplet_raw_migration_enabled';

	/** Default minutes per tier (yêu cầu 2026-07-15). */
	const DEFAULTS = array(
		'free'       => 10,
		'pro'        => 5,
		'premium'    => 1,
		'enterprise' => 1,
	);

	/**
	 * Đọc map tier => phút (đã merge với default).
	 *
	 * @return array<string,int>
	 */
	public static function get_tier_minutes(): array {
		$stored = self::get_network_option( self::OPT_TIER_MINUTES, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = self::DEFAULTS;
		foreach ( self::DEFAULTS as $tier => $def ) {
			if ( isset( $stored[ $tier ] ) ) {
				$m = (int) $stored[ $tier ];
				// Clamp 1..1440 phút để tránh giá trị vô lý.
				if ( $m < 1 ) { $m = 1; }
				if ( $m > 1440 ) { $m = 1440; }
				$out[ $tier ] = $m;
			}
		}
		return $out;
	}

	/**
	 * Cập nhật map tier => phút.
	 *
	 * @param array<string,int> $map
	 */
	public static function update_tier_minutes( array $map ): void {
		$clean = array();
		foreach ( self::DEFAULTS as $tier => $def ) {
			$m = isset( $map[ $tier ] ) ? (int) $map[ $tier ] : $def;
			if ( $m < 1 ) { $m = 1; }
			if ( $m > 1440 ) { $m = 1440; }
			$clean[ $tier ] = $m;
		}
		self::update_network_option( self::OPT_TIER_MINUTES, $clean );
	}

	/**
	 * Tier hiện tại của site (từ entitlement cache — KHÔNG gọi HTTP).
	 */
	public static function current_tier(): string {
		$tier = (string) get_option( 'bizcity_hub_master_tier', '' );
		if ( $tier === '' ) {
			$tier = (string) get_option( 'bizcity_hub_master_level', 'free' );
		}
		$tier = strtolower( trim( $tier ) );

		// [2026-07-15 Johnny Chu] PHASE-MASTER-PLANS — normalize hub slugs
		// (master_pro/master_premium) to local tier buckets used by cron settings.
		if ( in_array( $tier, array( 'master_enterprise' ), true ) ) {
			$tier = 'enterprise';
		} elseif ( in_array( $tier, array( 'master_premium', 'business', 'plus' ), true ) ) {
			$tier = 'premium';
		} elseif ( in_array( $tier, array( 'master_pro', 'starter' ), true ) ) {
			$tier = 'pro';
		}

		if ( ! isset( self::DEFAULTS[ $tier ] ) ) {
			$tier = 'free';
		}
		return $tier;
	}

	/**
	 * Số phút interval cho tier hiện tại.
	 */
	public static function current_minutes(): int {
		$map  = self::get_tier_minutes();
		$tier = self::current_tier();
		return isset( $map[ $tier ] ) ? (int) $map[ $tier ] : (int) self::DEFAULTS['free'];
	}

	/**
	 * Tên cron schedule cho tier hiện tại (đăng ký động qua register_schedules()).
	 * Format: `bizcity_tier_{N}min`.
	 */
	public static function current_schedule_name(): string {
		return 'bizcity_tier_' . self::current_minutes() . 'min';
	}

	/**
	 * Đăng ký TẤT CẢ schedule names cần thiết (mọi giá trị phút trong map) vào
	 * cron_schedules — phải chạy vô điều kiện mỗi request (R-PERF / PERF-CRON-FIX)
	 * để wp_reschedule_event() không bao giờ gặp invalid_schedule.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$minutes = array_unique( array_values( self::get_tier_minutes() ) );
		foreach ( $minutes as $m ) {
			$m    = (int) $m;
			$name = 'bizcity_tier_' . $m . 'min';
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = array(
					'interval' => $m * MINUTE_IN_SECONDS,
					'display'  => sprintf( 'BizCity Tier — mỗi %d phút', $m ),
				);
			}
		}
		return $schedules;
	}

	/**
	 * Reschedule 1 hook về đúng schedule tier hiện tại nếu khác.
	 * Chỉ reschedule khi interval đổi (tránh churn mỗi request).
	 *
	 * @param string $hook
	 */
	public static function ensure_hook_interval( string $hook ): void {
		$want = self::current_schedule_name();
		$ts   = wp_next_scheduled( $hook );
		if ( $ts ) {
			$cur = wp_get_schedule( $hook );
			if ( $cur === $want ) {
				return; // đúng rồi, không làm gì.
			}
			wp_unschedule_event( $ts, $hook );
		}
		wp_schedule_event( time() + 60, $want, $hook );
	}

	// ─── File-first flags ────────────────────────────────────────────────────

	public static function is_file_first_dual_write(): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — legacy central dual-write is no longer a hard-cut default.
		return (bool) self::get_network_option( self::OPT_FILE_FIRST_DUAL, false );
	}

	public static function is_file_first_read_switch(): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — read filestore before SQL by default.
		return (bool) self::get_network_option( self::OPT_FILE_FIRST_READ, true );
	}

	// [2026-07-15 Johnny Chu] PHASE-FILE-PRIMARY — gate for SQL-inline scrub on successful file writes.
	public static function is_file_primary_write(): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — file body is primary by default after successful write.
		return (bool) self::get_network_option( self::OPT_FILE_PRIMARY, true );
	}

	public static function is_graph_embedding_migration_enabled(): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — drain old graph embedding LONGTEXT by default.
		return (bool) self::get_network_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, true );
	}

	public static function is_triplet_raw_migration_enabled(): bool {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — move raw_llm_output out of SQL by default.
		return (bool) self::get_network_option( self::OPT_TRIPLET_RAW_MIGRATION, true );
	}

	public static function set_file_first( bool $dual_write, bool $read_switch ): void {
		self::update_network_option( self::OPT_FILE_FIRST_DUAL, $dual_write ? 1 : 0 );
		self::update_network_option( self::OPT_FILE_FIRST_READ, $read_switch ? 1 : 0 );
		// Mirror sang site option để filter v06 hiện có (đọc get_option) vẫn nhận ra.
		update_option( self::OPT_FILE_FIRST_DUAL, $dual_write ? 1 : 0 );
		update_option( self::OPT_FILE_FIRST_READ, $read_switch ? 1 : 0 );
	}

	public static function set_file_primary_write( bool $enabled ): void {
		// [2026-07-15 Johnny Chu] PHASE-FILE-PRIMARY — rollout flag for file-primary write path.
		self::update_network_option( self::OPT_FILE_PRIMARY, $enabled ? 1 : 0 );
		update_option( self::OPT_FILE_PRIMARY, $enabled ? 1 : 0 );
	}

	public static function set_graph_embedding_migration( bool $enabled ): void {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — persist graph embedding migration cron flag.
		self::update_network_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, $enabled ? 1 : 0 );
		update_option( self::OPT_GRAPH_EMBEDDING_MIGRATION, $enabled ? 1 : 0 );
	}

	public static function set_triplet_raw_migration( bool $enabled ): void {
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — persist triplet raw payload migration + file-primary write flag.
		self::update_network_option( self::OPT_TRIPLET_RAW_MIGRATION, $enabled ? 1 : 0 );
		update_option( self::OPT_TRIPLET_RAW_MIGRATION, $enabled ? 1 : 0 );
	}

	// ─── Network-aware option helpers ────────────────────────────────────────

	private static function get_network_option( string $key, $default ) {
		if ( is_multisite() ) {
			$val = get_site_option( $key, null );
			if ( null !== $val ) {
				return $val;
			}
		}
		return get_option( $key, $default );
	}

	private static function update_network_option( string $key, $value ): void {
		if ( is_multisite() ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value );
		}
	}
}
