<?php
/**
 * BizCity Zalo Mapping Repository (PHASE-0.39)
 *
 * Manages three tables from modules.zalo-personal.json (v1.0.0):
 *   bizcity_zalo_accounts    — bridge account registry
 *   bizcity_zalo_message_map — Zalo msg_id ↔ CRM message_id mapping (dedup + outbound lookup)
 *   bizcity_zalo_oa_window   — OA CSKH 7-day window tracker (R-ZP-5)
 *
 * Cache Contract (R-CACHE):
 *   group: bz_zalo_map
 *   keys: account_bridge_{hash}, accounts_owner_{hash}, map_zalo_{hash}, map_crm_{hash}
 *   invalidations: account/map/window insert, update, delete and schema repair
 *
 * R-DCL: all schema changes MUST be reflected in modules.zalo-personal.json first.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 R-DCL — schema repo (tables declared in modules.zalo-personal.json v1.0.0)
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Mapping_Repo {

	const VERSION_OPTION = 'bizcity_zalo_personal_db_version';
	const DB_VERSION     = '1.1.4'; // [2026-08-22 Johnny Chu] R-DCL — backfill legacy owner and display mirrors for existing tenant rows.
	const CACHE_GROUP    = 'bz_zalo_map';
	const CACHE_TTL      = 300;

	private static $owner_cache = array();

	// ── Schema install ────────────────────────────────────────────────────

	/**
	 * Create tables if they do not exist. Idempotent.
	 * Called on admin_init and plugin activation.
	 */
	public static function maybe_install(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		// [2026-08-22 Johnny Chu] R-METADATA-CACHE — do not trust the version stamp when a tenant table is stale.
		if ( $installed === self::DB_VERSION && self::schema_is_ready() ) {
			return;
		}
		self::run_dbdelta();
		if ( self::schema_is_ready() ) {
			self::backfill_legacy_owner_mirrors();
		}
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			global $wpdb;
			foreach ( array( 'bizcity_zalo_accounts', 'bizcity_zalo_message_map', 'bizcity_zalo_oa_window' ) as $_table_base ) {
				bizcity_tbl_invalidate( $wpdb->prefix . $_table_base );
			}
			if ( function_exists( 'bizcity_column_invalidate' ) ) {
				foreach ( array( 'kind', 'user_id', 'owner_user_id', 'account_name', 'label', 'bridge_account_id', 'crm_inbox_id', 'status' ) as $_column ) {
					bizcity_column_invalidate( $wpdb->prefix . 'bizcity_zalo_accounts', $_column );
				}
			}
		}
		// [2026-08-22 Johnny Chu] R-DCL — only advance the version after the routed table passes the cached schema check.
		if ( self::schema_is_ready() ) {
			update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/** Backfill compatibility columns once per tenant migration without changing canonical ownership. */
	private static function backfill_legacy_owner_mirrors(): void {
		// [2026-08-22 Johnny Chu] R-DCL — preserve existing account visibility when owner_user_id/label were added after legacy rows existed.
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$wpdb->query( "UPDATE `{$table}` SET owner_user_id = user_id WHERE (owner_user_id IS NULL OR owner_user_id = 0) AND user_id > 0" );
		$wpdb->query( "UPDATE `{$table}` SET account_name = label WHERE (account_name IS NULL OR account_name = '') AND label <> ''" );
	}

	/**
	 * Verify the columns required by active and legacy tenant rows.
	 *
	 * Metadata helpers cache both true and false values by blog/database. When
	 * the shared helper is unavailable, keep the compatibility path conservative
	 * and let the installer remain the explicit repair owner.
	 */
	private static function schema_is_ready(): bool {
		// [2026-08-22 Johnny Chu] R-METADATA-CACHE — verify all mapping tables before trusting the version stamp.
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! function_exists( 'bizcity_column_exists' ) ) {
			return true;
		}
		global $wpdb;
		foreach ( array( 'bizcity_zalo_accounts', 'bizcity_zalo_message_map', 'bizcity_zalo_oa_window' ) as $_table_base ) {
			if ( ! bizcity_tbl_exists( $wpdb->prefix . $_table_base ) ) {
				return false;
			}
		}
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		foreach ( array( 'kind', 'user_id', 'owner_user_id', 'account_name', 'label', 'bridge_account_id', 'crm_inbox_id', 'status' ) as $column ) {
			if ( ! bizcity_column_exists( $table, $column ) ) {
				return false;
			}
		}
		return true;
	}

	private static function run_dbdelta(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cs = $wpdb->get_charset_collate();

		// [2026-06-07 Johnny Chu] PHASE-0.39 R-DCL — bizcity_zalo_accounts (since 1.0.0)
		$sql_accounts = "CREATE TABLE {$wpdb->prefix}bizcity_zalo_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(16) NOT NULL DEFAULT 'personal',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			account_name VARCHAR(255) NOT NULL DEFAULT '',
			label VARCHAR(255) NOT NULL DEFAULT '',
			bridge_account_id VARCHAR(255) NOT NULL DEFAULT '',
			zalo_uid VARCHAR(128) NOT NULL DEFAULT '',
			zalo_oa_id VARCHAR(128) NOT NULL DEFAULT '',
			crm_inbox_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'pending_qr',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY kind_bridge (kind(16), bridge_account_id(191)),
			KEY idx_owner (owner_user_id, kind, status)
		) $cs;";

		// [2026-06-07 Johnny Chu] PHASE-0.39 R-DCL — bizcity_zalo_message_map (since 1.0.0)
		$sql_map = "CREATE TABLE {$wpdb->prefix}bizcity_zalo_message_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			zalo_msg_id VARCHAR(255) NOT NULL DEFAULT '',
			zalo_thread_id VARCHAR(255) NOT NULL DEFAULT '',
			thread_kind VARCHAR(16) NOT NULL DEFAULT 'personal',
			crm_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			direction ENUM('in','out') NOT NULL DEFAULT 'in',
			quote_src_json TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY acct_msg (account_id, zalo_msg_id(191)),
			KEY crm_msg (crm_message_id)
		) $cs;";

		// [2026-06-07 Johnny Chu] PHASE-0.39 R-DCL — bizcity_zalo_oa_window (since 1.0.0)
		$sql_window = "CREATE TABLE {$wpdb->prefix}bizcity_zalo_oa_window (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			zalo_uid VARCHAR(255) NOT NULL DEFAULT '',
			last_inbound_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			cs_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY acct_uid (account_id, zalo_uid(191))
		) $cs;";

		dbDelta( $sql_accounts );
		dbDelta( $sql_map );
		dbDelta( $sql_window );
	}

	// ── bizcity_zalo_accounts ─────────────────────────────────────────────

	/**
	 * Insert or update an account record.
	 *
	 * @param array $data { kind, user_id, owner_user_id, account_name, label, bridge_account_id, zalo_uid, zalo_oa_id, crm_inbox_id, status }
	 * @return int Inserted/updated row ID.
	 */
	public static function save_account( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$row   = array(
			'kind'              => sanitize_text_field( $data['kind'] ?? 'personal' ),
			'owner_user_id'     => array_key_exists( 'owner_user_id', $data ) ? max( 0, (int) $data['owner_user_id'] ) : null,
			'label'             => sanitize_text_field( $data['label'] ?? '' ),
			'bridge_account_id' => sanitize_text_field( $data['bridge_account_id'] ?? '' ),
			'zalo_uid'          => sanitize_text_field( $data['zalo_uid'] ?? '' ),
			'zalo_oa_id'        => sanitize_text_field( $data['zalo_oa_id'] ?? '' ),
			'crm_inbox_id'      => (int) ( $data['crm_inbox_id'] ?? 0 ),
			'status'            => sanitize_text_field( $data['status'] ?? 'pending_qr' ),
		);
		// [2026-08-21 Johnny Chu] PHASE-0.39B — existing tenant tables may require legacy user_id; keep it aligned with the canonical owner.
		$row['user_id'] = null === $row['owner_user_id'] ? 0 : (int) $row['owner_user_id'];
		// [2026-08-21 Johnny Chu] PHASE-0.39B — existing tenant tables may require legacy account_name; keep it aligned with the CRM label.
		$row['account_name'] = $row['label'];
		// Upsert via INSERT … ON DUPLICATE KEY UPDATE.
		$owner_sql   = null === $row['owner_user_id'] ? 'NULL' : '%d';
		$query_args  = array( $row['kind'], $row['user_id'] );
		if ( null !== $row['owner_user_id'] ) {
			$query_args[] = $row['owner_user_id'];
		}
		$query_args = array_merge( $query_args, array(
			$row['account_name'],
			$row['label'],
			$row['bridge_account_id'],
			$row['zalo_uid'],
			$row['zalo_oa_id'],
			$row['crm_inbox_id'],
			$row['status'],
		) );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (kind, user_id, owner_user_id, account_name, label, bridge_account_id, zalo_uid, zalo_oa_id, crm_inbox_id, status)
			 VALUES (%s, %d, {$owner_sql}, %s, %s, %s, %s, %s, %d, %s)
			 ON DUPLICATE KEY UPDATE
			   user_id = IF(VALUES(user_id) = 0, user_id, VALUES(user_id)),
			   owner_user_id = IF(VALUES(owner_user_id) IS NULL, owner_user_id, VALUES(owner_user_id)),
			   account_name = VALUES(account_name),
			   label = VALUES(label),
			   zalo_uid = VALUES(zalo_uid),
			   zalo_oa_id = VALUES(zalo_oa_id),
			   crm_inbox_id = VALUES(crm_inbox_id),
			   status = VALUES(status),
			   updated_at = NOW()",
			$query_args
		) );
		$id = (int) $wpdb->insert_id;
		if ( $id === 0 ) {
			// Was an update; retrieve existing id.
			$found = self::find_account_by_bridge_id( $row['kind'], $row['bridge_account_id'] );
			$id    = $found ? (int) $found['id'] : 0;
		}
		self::flush_owner_cache();
		self::flush_cache();
		return $id;
	}

	/**
	 * Find account by bridge ID + kind.
	 *
	 * @param string $kind               'personal' or 'oa'.
	 * @param string $bridge_account_id
	 * @return array|null
	 */
	public static function find_account_by_bridge_id( string $kind, string $bridge_account_id ) {
		$cache_key = self::cache_key( 'account_bridge', $kind . '|' . $bridge_account_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE kind = %s AND bridge_account_id = %s LIMIT 1",
			$kind,
			$bridge_account_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	/**
	 * List Personal accounts owned by one WordPress user in the current tenant.
	 *
	 * @param int $owner_user_id
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_personal_accounts_for_owner( int $owner_user_id ): array {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — owner-scoped Personal account read.
		if ( $owner_user_id <= 0 ) {
			return array();
		}
		if ( isset( self::$owner_cache[ $owner_user_id ] ) ) {
			return self::$owner_cache[ $owner_user_id ];
		}
		$cache_key = self::cache_key( 'accounts_owner', (string) $owner_user_id );
		$cached    = self::cache_get( $cache_key );
		if ( is_array( $cached ) ) {
			self::$owner_cache[ $owner_user_id ] = $cached;
			return $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE kind = 'personal' AND owner_user_id = %d ORDER BY id DESC",
			$owner_user_id
		), ARRAY_A );
		self::$owner_cache[ $owner_user_id ] = is_array( $rows ) ? $rows : array();
		self::cache_set( $cache_key, self::$owner_cache[ $owner_user_id ] );
		return self::$owner_cache[ $owner_user_id ];
	}

	/** Find the local Zalo account attached to one CRM inbox. */
	public static function find_account_by_crm_inbox_id( int $crm_inbox_id ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39C — protect active managed CRM inboxes from legacy cleanup.
		if ( $crm_inbox_id <= 0 ) { return null; }
		$cache_key = self::cache_key( 'account_inbox', (string) $crm_inbox_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE crm_inbox_id = %d LIMIT 1",
			$crm_inbox_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	public static function flush_owner_cache(): void {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — invalidate owner account read cache after writes.
		self::$owner_cache = array();
	}

	private static function cache_key( string $scope, string $value ): string {
		// [2026-08-22 Johnny Chu] R-CACHE — dimension mapping keys by tenant and routed database.
		global $wpdb;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		return sanitize_key( $scope ) . '_' . md5( (int) get_current_blog_id() . '|' . $database . '|' . $value );
	}

	private static function cache_get( string $key ) {
		// [2026-08-22 Johnny Chu] R-CACHE — read mapping cache through the shared helper.
		return class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, $key ) : false;
	}

	private static function cache_set( string $key, $value ): void {
		// [2026-08-22 Johnny Chu] R-CACHE — write mapping cache with bounded TTL.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $value, self::CACHE_TTL );
		}
	}

	private static function flush_cache(): void {
		// [2026-08-22 Johnny Chu] R-CACHE — invalidate all mapping projections after writes.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Resolve one Personal account only when it belongs to the current owner.
	 *
	 * @param int    $owner_user_id
	 * @param string $bridge_account_id
	 * @return array|null
	 */
	public static function find_personal_account_for_owner( int $owner_user_id, string $bridge_account_id ) {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — fail-closed account ownership lookup.
		if ( $owner_user_id <= 0 || $bridge_account_id === '' ) {
			return null;
		}
		$cache_key = self::cache_key( 'account_owner', $owner_user_id . '|' . $bridge_account_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE kind = 'personal' AND owner_user_id = %d AND bridge_account_id = %s LIMIT 1",
			$owner_user_id,
			$bridge_account_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	/**
	 * Find OA account by Zalo OA ID (used by legacy send() outbound path).
	 *
	 * @param string $oa_id  Zalo OA ID (zalo_oa_id column).
	 * @return array|null
	 */
	// [2026-06-07 Johnny Chu] PHASE-0.39 — lookup for send_outbound legacy shim in OA integration
	public static function find_account_by_zalo_oa_id( string $oa_id ) {
		$cache_key = self::cache_key( 'account_oa', $oa_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE kind = 'oa' AND zalo_oa_id = %s LIMIT 1",
			$oa_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	/**
	 * Update account status (and optional extra fields).
	 *
	 * @param int    $id
	 * @param string $status
	 * @param array  $extra  { zalo_uid?:string, zalo_oa_id?:string, crm_inbox_id?:int }
	 */
	public static function update_account_status( int $id, string $status, array $extra = array() ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_accounts';
		$set   = array( 'status' => $status );
		if ( isset( $extra['zalo_uid'] ) ) {
			$set['zalo_uid'] = sanitize_text_field( $extra['zalo_uid'] );
		}
		if ( isset( $extra['zalo_oa_id'] ) ) {
			$set['zalo_oa_id'] = sanitize_text_field( $extra['zalo_oa_id'] );
		}
		if ( isset( $extra['crm_inbox_id'] ) ) {
			$set['crm_inbox_id'] = (int) $extra['crm_inbox_id'];
		}
		$wpdb->update( $table, $set, array( 'id' => $id ) );
		self::flush_owner_cache();
		self::flush_cache();
	}

	// ── bizcity_zalo_message_map ──────────────────────────────────────────

	/**
	 * Insert a message map row. Ignores duplicate (idempotent).
	 *
	 * @param array $data { account_id, zalo_msg_id, zalo_thread_id, thread_kind, crm_message_id, direction, quote_src_json }
	 * @return int Inserted row ID (0 on duplicate).
	 */
	public static function save_map( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_message_map';
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO `{$table}` (account_id, zalo_msg_id, zalo_thread_id, thread_kind, crm_message_id, direction, quote_src_json)
			 VALUES (%d, %s, %s, %s, %d, %s, %s)",
			(int) $data['account_id'],
			(string) ( $data['zalo_msg_id'] ?? '' ),
			(string) ( $data['zalo_thread_id'] ?? '' ),
			(string) ( $data['thread_kind'] ?? 'personal' ),
			(int) ( $data['crm_message_id'] ?? 0 ),
			(string) ( $data['direction'] ?? 'in' ),
			(string) ( $data['quote_src_json'] ?? '' )
		) );
		$id = (int) $wpdb->insert_id;
		self::flush_cache();
		return $id;
	}

	/**
	 * Update CRM message ID on an existing map row (called by CRM Adapter callback).
	 *
	 * @param int $account_id  Local account ID.
	 * @param string $zalo_msg_id
	 * @param int $crm_message_id
	 */
	public static function link_crm_message( int $account_id, string $zalo_msg_id, int $crm_message_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_message_map';
		$wpdb->update( $table, array( 'crm_message_id' => $crm_message_id ), array(
			'account_id'  => $account_id,
			'zalo_msg_id' => $zalo_msg_id,
		) );
		self::flush_cache();
	}

	/**
	 * @param int    $account_id
	 * @param string $zalo_msg_id
	 * @return array|null
	 */
	public static function find_by_zalo_msg_id( int $account_id, string $zalo_msg_id ) {
		$cache_key = self::cache_key( 'map_zalo', $account_id . '|' . $zalo_msg_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_message_map';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE account_id = %d AND zalo_msg_id = %s LIMIT 1",
			$account_id,
			$zalo_msg_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	/**
	 * @param int $crm_message_id
	 * @return array|null
	 */
	public static function find_by_crm_message_id( int $crm_message_id ) {
		$cache_key = self::cache_key( 'map_crm', (string) $crm_message_id );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_message_map';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE crm_message_id = %d LIMIT 1",
			$crm_message_id
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	// ── bizcity_zalo_oa_window ────────────────────────────────────────────

	/**
	 * Upsert OA window last_inbound_at on user message received.
	 *
	 * @param int|string $account_ref  Local account id (int) or oa_id string fallback.
	 * @param string     $zalo_uid     Sender Zalo UID.
	 */
	public static function update_oa_window( $account_ref, string $zalo_uid ): void {
		global $wpdb;
		$table      = $wpdb->prefix . 'bizcity_zalo_oa_window';
		$account_id = is_int( $account_ref ) ? $account_ref : 0;
		// If string passed (oa_id), look up account.
		if ( 0 === $account_id && is_string( $account_ref ) && $account_ref !== '' ) {
			$found      = self::find_account_by_bridge_id( 'oa', $account_ref );
			$account_id = $found ? (int) $found['id'] : 0;
		}
		if ( 0 === $account_id || $zalo_uid === '' ) {
			return;
		}
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (account_id, zalo_uid, last_inbound_at)
			 VALUES (%d, %s, NOW())
			 ON DUPLICATE KEY UPDATE last_inbound_at = NOW()",
			$account_id,
			$zalo_uid
		) );
		self::flush_cache();
	}

	/**
	 * Increment cs_sent_count after OA outbound send.
	 *
	 * @param string $oa_id     Zalo OA ID.
	 * @param string $zalo_uid  Recipient UID.
	 */
	public static function increment_oa_cs_count( string $oa_id, string $zalo_uid ): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'bizcity_zalo_oa_window';
		$account = self::find_account_by_bridge_id( 'oa', $oa_id );
		if ( ! $account ) {
			return;
		}
		$account_id = (int) $account['id'];
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$table}` SET cs_sent_count = cs_sent_count + 1
			 WHERE account_id = %d AND zalo_uid = %s",
			$account_id,
			$zalo_uid
		) );
		self::flush_cache();
	}

	/**
	 * Get OA window row for a user.
	 *
	 * @param int    $account_id
	 * @param string $zalo_uid
	 * @return array|null
	 */
	public static function get_oa_window( int $account_id, string $zalo_uid ) {
		$cache_key = self::cache_key( 'oa_window', $account_id . '|' . $zalo_uid );
		$cached    = self::cache_get( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['_missing'] ) ) ? null : $cached;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_zalo_oa_window';
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE account_id = %d AND zalo_uid = %s LIMIT 1",
			$account_id,
			$zalo_uid
		), ARRAY_A );
		$result = $row ?: array( '_missing' => 1 );
		self::cache_set( $cache_key, $result );
		return $row ?: null;
	}

	/**
	 * Check if OA CSKH 7-day window is still open for a user (R-ZP-5).
	 * Window: last_inbound_at within 7 days.
	 *
	 * @param int    $account_id
	 * @param string $zalo_uid
	 * @return bool
	 */
	public static function is_oa_window_open( int $account_id, string $zalo_uid ): bool {
		$row = self::get_oa_window( $account_id, $zalo_uid );
		if ( $row === null ) {
			return false;
		}
		$last_inbound_ts = strtotime( $row['last_inbound_at'] );
		if ( $last_inbound_ts === false ) {
			return false;
		}
		// 7 days = 604800 seconds.
		return ( time() - $last_inbound_ts ) <= 604800;
	}
}

// [2026-08-22 Johnny Chu] R-CR/R-CACHE — register the three tenant tables and mapping cache at file scope.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	foreach ( array( 'bizcity_zalo_accounts', 'bizcity_zalo_message_map', 'bizcity_zalo_oa_window' ) as $_zalo_table ) {
		BizCity_Schema_Registry::register( $_zalo_table, 'modules.zalo-personal', BizCity_Zalo_Mapping_Repo::DB_VERSION, BizCity_Zalo_Mapping_Repo::VERSION_OPTION, array( 'BizCity_Zalo_Mapping_Repo', 'maybe_install' ) );
	}
}
if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	BizCity_Cache_Registry::register( BizCity_Zalo_Mapping_Repo::CACHE_GROUP, 'modules.zalo-personal', array(
		'account_bridge_{hash}' => array( 'ttl' => BizCity_Zalo_Mapping_Repo::CACHE_TTL, 'desc' => 'Zalo account by kind and bridge ID' ),
		'accounts_owner_{hash}' => array( 'ttl' => BizCity_Zalo_Mapping_Repo::CACHE_TTL, 'desc' => 'Personal accounts for current tenant owner' ),
		'map_zalo_{hash}'       => array( 'ttl' => BizCity_Zalo_Mapping_Repo::CACHE_TTL, 'desc' => 'Zalo provider message idempotency map' ),
		'map_crm_{hash}'        => array( 'ttl' => BizCity_Zalo_Mapping_Repo::CACHE_TTL, 'desc' => 'CRM message to Zalo map' ),
	) );
}
