<?php
/**
 * ZNS Rules Repo — CRUD cho bảng bizcity_zns_event_rules.
 *
 * Cache Contract
 * @group  zns_rules
 * @keys   rules_all, rules_active, rules_by_event_{event_key}, rule_id_{id}
 * @ttl    BizCity_Cache::TTL_MEDIUM (5 phút)
 * @flush  insert, update, delete (soft)
 *
 * R-SHOW-TABLES: Dùng information_schema — CẤM SHOW TABLES.
 * R-CACHE: Wrap mọi read bằng BizCity_Cache::get/set.
 * R-CR.2: Schema Registry được gọi sau closing } ở cuối file.
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_Rules_Repo' ) ) {
	return;
}

class BizCity_ZNS_Rules_Repo {

	const CACHE_GROUP  = 'zns_rules';
	const SCHEMA_VERSION = '1.0.0';
	const VERSION_OPTION = 'bizcity_zns_rules_schema_ver';

	/**
	 * Tên bảng đầy đủ (với prefix).
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_zns_event_rules';
	}

	/**
	 * Kiểm tra bảng có tồn tại không.
	 * Dùng information_schema (R-SHOW-TABLES — CẤM SHOW TABLES).
	 *
	 * @return bool
	 */
	public static function table_exists() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — information_schema dual cache (R-SHOW-TABLES)
		static $cache = array();
		$tbl = self::table();
		if ( isset( $cache[ $tbl ] ) ) {
			return $cache[ $tbl ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $tbl );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$tbl
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$cache[ $tbl ] = (bool) $present;
		return $cache[ $tbl ];
	}

	/**
	 * Lấy tất cả rules.
	 *
	 * @param  bool $include_deleted  Có bao gồm soft-deleted không.
	 * @return array
	 */
	public static function get_all( $include_deleted = false ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — cache wrap
		if ( ! self::table_exists() ) {
			return array();
		}
		$cache_key = $include_deleted ? 'rules_all_with_deleted' : 'rules_all';
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$where  = $include_deleted ? '' : 'WHERE deleted_at IS NULL';
		$result = $wpdb->get_results(
			"SELECT * FROM " . self::table() . " {$where} ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);
		$result = $result ? array_map( array( __CLASS__, 'decode_row' ), $result ) : array();
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		return $result;
	}

	/**
	 * Lấy các rules active cho một event_key.
	 *
	 * @param  string $event_key
	 * @return array
	 */
	public static function get_active_by_event( $event_key ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — per-event cache
		if ( ! self::table_exists() ) {
			return array();
		}
		$cache_key = 'rules_by_event_' . sanitize_key( $event_key );
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . "
				 WHERE event_key = %s AND enabled = 1 AND deleted_at IS NULL
				 ORDER BY sort_order ASC, id ASC",
				$event_key
			),
			ARRAY_A
		);
		$result = $result ? array_map( array( __CLASS__, 'decode_row' ), $result ) : array();
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		return $result;
	}

	/**
	 * Lấy 1 rule theo ID.
	 *
	 * @param  int $id
	 * @return array|null
	 */
	public static function get_by_id( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — single rule cache
		if ( ! self::table_exists() ) {
			return null;
		}
		$cache_key = 'rule_id_' . (int) $id;
		$cached    = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE id = %d AND deleted_at IS NULL",
				(int) $id
			),
			ARRAY_A
		);
		$result = $row ? self::decode_row( $row ) : array();
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result );
		return $result ?: null;
	}

	/**
	 * Tạo rule mới.
	 *
	 * @param  array $data
	 * @return int  Insert ID (0 on failure).
	 */
	public static function insert( array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — insert + flush_group
		if ( ! self::table_exists() ) {
			return 0;
		}
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$insert = array_merge( self::sanitize_data( $data ), array(
			'created_at' => $now,
			'updated_at' => $now,
			'deleted_at' => null,
		) );
		$wpdb->insert( self::table(), $insert );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return $id;
	}

	/**
	 * Cập nhật rule.
	 *
	 * @param  int   $id
	 * @param  array $data
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — update + flush_group
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$update = array_merge( self::sanitize_data( $data ), array(
			'updated_at' => current_time( 'mysql', true ),
		) );
		$result = $wpdb->update( self::table(), $update, array( 'id' => (int) $id ) );
		if ( false !== $result ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return false !== $result;
	}

	/**
	 * Soft delete rule.
	 *
	 * @param  int $id
	 * @return bool
	 */
	public static function delete( $id ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — soft delete + flush_group
		if ( ! self::table_exists() ) {
			return false;
		}
		global $wpdb;
		$result = $wpdb->update(
			self::table(),
			array( 'deleted_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id )
		);
		if ( false !== $result ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
		return false !== $result;
	}

	/**
	 * Cập nhật thống kê fire sau mỗi lần gửi.
	 *
	 * @param  int    $id
	 * @param  bool   $success
	 * @param  string $error
	 * @return void
	 */
	public static function update_fire_stats( $id, $success, $error = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — fire_count + last_fired_at
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$set = 'fire_count = fire_count + 1, last_fired_at = %s';
		$params = array( current_time( 'mysql', true ) );
		if ( ! $success && $error ) {
			$set      .= ', last_error = %s';
			$params[]  = substr( $error, 0, 255 );
		}
		$params[] = (int) $id;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET {$set} WHERE id = %d",
				$params
			)
		);
		// Don't flush full group — just invalidate single rule cache
		$cache_key = 'rule_id_' . (int) $id;
		BizCity_Cache::set( self::CACHE_GROUP, $cache_key, array() ); // force re-read
	}

	/**
	 * Cài đặt bảng (gọi từ installer).
	 * Dùng dbDelta để tạo bảng nếu chưa tồn tại.
	 *
	 * @return void
	 */
	public static function install() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — dbDelta installer
		global $wpdb;
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$t} (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name           VARCHAR(190) NOT NULL DEFAULT '',
			event_key      VARCHAR(100) NOT NULL DEFAULT '',
			oa_id          VARCHAR(64)  NOT NULL DEFAULT '',
			temp_id        VARCHAR(64)  NOT NULL DEFAULT '',
			temp_vars_json LONGTEXT     NULL,
			sandbox        TINYINT(1)   NOT NULL DEFAULT 0,
			campaign_id    VARCHAR(254) NOT NULL DEFAULT '',
			enabled        TINYINT(1)   NOT NULL DEFAULT 1,
			sort_order     INT          NOT NULL DEFAULT 0,
			last_fired_at  DATETIME     NULL,
			fire_count     INT UNSIGNED NOT NULL DEFAULT 0,
			last_error     TEXT         NULL,
			created_at     DATETIME     NOT NULL,
			updated_at     DATETIME     NOT NULL,
			deleted_at     DATETIME     NULL,
			PRIMARY KEY (id),
			KEY idx_event   (event_key),
			KEY idx_enabled (enabled),
			KEY idx_deleted (deleted_at)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Invalidate table-existence cache
		$ck = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $t );
		wp_cache_delete( $ck, 'bizcity_tbl' );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	// ── Internal helpers ───────────────────────────────────────────────────────

	/**
	 * Decode JSON columns trong row.
	 *
	 * @param  array $row
	 * @return array
	 */
	private static function decode_row( array $row ) {
		if ( ! empty( $row['temp_vars_json'] ) ) {
			$decoded             = json_decode( $row['temp_vars_json'], true );
			$row['temp_vars']    = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['temp_vars'] = array();
		}
		$row['enabled']  = (bool) $row['enabled'];
		$row['sandbox']  = (bool) $row['sandbox'];
		$row['id']       = (int) $row['id'];
		$row['fire_count'] = (int) $row['fire_count'];
		$row['sort_order'] = (int) $row['sort_order'];
		return $row;
	}

	/**
	 * Sanitize & filter input data cho insert/update.
	 *
	 * @param  array $data
	 * @return array
	 */
	private static function sanitize_data( array $data ) {
		$clean = array();
		$allowed_text   = array( 'name', 'event_key', 'oa_id', 'temp_id', 'campaign_id' );
		$allowed_int    = array( 'enabled', 'sandbox', 'sort_order' );
		foreach ( $allowed_text as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$clean[ $f ] = sanitize_text_field( (string) $data[ $f ] );
			}
		}
		foreach ( $allowed_int as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$clean[ $f ] = (int) $data[ $f ];
			}
		}
		if ( array_key_exists( 'temp_vars', $data ) && is_array( $data['temp_vars'] ) ) {
			$clean['temp_vars_json'] = wp_json_encode( $data['temp_vars'] );
		} elseif ( array_key_exists( 'temp_vars_json', $data ) ) {
			$clean['temp_vars_json'] = $data['temp_vars_json'];
		}
		return $clean;
	}
}

// ── R-CACHE Registry (file scope, ngoài hook) ──────────────────────────────────
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'zns_rules', 'modules.zns-automation', array(
		'rules_all'              => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'All ZNS event rules' ),
		'rules_all_with_deleted' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'All rules incl. deleted' ),
		'rules_active'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Active rules only' ),
		'rules_by_event_{key}'   => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Active rules for event key' ),
		'rule_id_{id}'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Single rule by ID' ),
	) );
}

// ── R-CR.2 Schema Registry (file scope, ngoài hook) ───────────────────────────
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_zns_event_rules',
		'modules.zns-automation',
		BizCity_ZNS_Rules_Repo::SCHEMA_VERSION,
		BizCity_ZNS_Rules_Repo::VERSION_OPTION,
		array( 'BizCity_ZNS_Rules_Repo', 'install' )
	);
}
