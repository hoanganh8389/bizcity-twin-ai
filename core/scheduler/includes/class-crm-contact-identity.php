<?php
/**
 * BizCity CRM Contact Identity — multi-platform identity resolution.
 *
 * R-UNIFY Wave 1 (2026-06-15) — decouples "which contact" from "which
 * platform account". One CRM contact can have N identities across Zalo OA,
 * Facebook, WebChat, Telegram, etc.
 *
 * Table: bizcity_crm_contact_identities (per-blog, $wpdb->prefix)
 *
 * Canonical entry-point for CRM_Inbox_Bridge:
 *   $contact_id = BizCity_CRM_Contact_Identity::resolve_or_create(
 *       'ZALO', $platform_uid, $oa_id
 *   );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-15 (R-UNIFY Wave 1)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — double-load guard.
if ( class_exists( 'BizCity_CRM_Contact_Identity', false ) ) {
	return;
}

final class BizCity_CRM_Contact_Identity {

	const TABLE_NAME     = 'bizcity_crm_contact_identities';
	const SCHEMA_VERSION = 1;
	const SCHEMA_OPT     = 'bizcity_crm_contact_identities_schema';

	/** @var string */
	private static $table = '';

	/** @var bool[] Per-request install cache keyed by blog_id. */
	private static $ensured = array();

	/* ================================================================
	 *  Table helpers
	 * ================================================================ */

	public static function table(): string {
		if ( '' === self::$table ) {
			global $wpdb;
			self::$table = $wpdb->prefix . self::TABLE_NAME;
		}
		return self::$table;
	}

	/**
	 * Ensure the table exists (ADD-only, idempotent).
	 *
	 * [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — self-installing installer.
	 */
	public static function ensure(): void {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( ! empty( self::$ensured[ $blog_id ] ) ) {
			return;
		}
		$stored = (int) get_option( self::SCHEMA_OPT, 0 );
		if ( $stored >= self::SCHEMA_VERSION ) {
			self::$ensured[ $blog_id ] = true;
			return;
		}
		self::install();
		update_option( self::SCHEMA_OPT, self::SCHEMA_VERSION, false );
		self::$ensured[ $blog_id ] = true;
	}

	private static function install(): void {
		global $wpdb;
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$t} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id   BIGINT UNSIGNED NOT NULL,
			platform     VARCHAR(32) NOT NULL DEFAULT 'unknown',
			platform_uid VARCHAR(190) NOT NULL DEFAULT '',
			account_id   VARCHAR(190) NOT NULL DEFAULT '',
			is_primary   TINYINT(1) NOT NULL DEFAULT 0,
			meta_json    LONGTEXT NULL,
			created_at   DATETIME NOT NULL,
			updated_at   DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_platform_uid_acct (platform, platform_uid, account_id),
			KEY idx_contact (contact_id),
			KEY idx_platform (platform)
		) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ================================================================
	 *  Public API
	 * ================================================================ */

	/**
	 * Resolve an existing identity row or return 0 (no contact yet).
	 *
	 * @param string $platform     FACEBOOK | ZALO | ZALO_OA | ...
	 * @param string $platform_uid Platform-side user ID.
	 * @param string $account_id   Page / OA / bot account ID. '' = N/A.
	 * @return int  contact_id or 0 if not found.
	 */
	public static function find_contact_id( string $platform, string $platform_uid, string $account_id = '' ): int {
		global $wpdb;
		self::ensure();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT contact_id FROM ' . self::table() . ' WHERE platform = %s AND platform_uid = %s AND account_id = %s LIMIT 1',
				$platform,
				$platform_uid,
				$account_id
			),
			ARRAY_A
		);
		return $row ? (int) $row['contact_id'] : 0;
	}

	/**
	 * Resolve an existing identity or create a new contact + identity row.
	 *
	 * This is the CANONICAL entry-point for CRM_Inbox_Bridge. It resolves
	 * the contact_id for a platform user without creating duplicate contacts.
	 *
	 * When no identity exists AND $create_contact_data is provided, a new
	 * bizcity_crm_contacts row is inserted first, then the identity is linked.
	 *
	 * @param string $platform           FACEBOOK | ZALO | ZALO_OA | WEBCHAT | TELEGRAM
	 * @param string $platform_uid       Platform-side sender/user ID.
	 * @param string $account_id         Page/OA/bot account ID. '' = N/A.
	 * @param array  $create_contact_data {name, avatar_url, source, phone, email} used when creating a new contact. Empty = skip create.
	 * @return int  contact_id (>0) on success, 0 on failure.
	 */
	public static function resolve_or_create(
		string $platform,
		string $platform_uid,
		string $account_id = '',
		array $create_contact_data = array()
	): int {
		// [2026-06-15 Johnny Chu] R-UNIFY Wave 1 — resolve_or_create canonical.
		self::ensure();

		// 1. Check existing identity.
		$contact_id = self::find_contact_id( $platform, $platform_uid, $account_id );
		if ( $contact_id > 0 ) {
			return $contact_id;
		}

		// 2. No identity found; need a contact row.
		if ( empty( $create_contact_data ) ) {
			return 0;
		}

		// 3. Check if contacts table exists (guard: CRM may not be active).
		global $wpdb;
		$contacts_table = $wpdb->prefix . 'bizcity_crm_contacts';
		$has_contacts   = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$contacts_table
		) );
		if ( ! $has_contacts ) {
			return 0;
		}

		// 4. Upsert contact by (platform, platform_uid) — respect the existing
		//    unique key on bizcity_crm_contacts if still present.
		$contact_name   = sanitize_text_field( $create_contact_data['name'] ?? $platform_uid );
		$contact_source = sanitize_text_field( $create_contact_data['source'] ?? 'crm_inbox' );
		$now            = current_time( 'mysql' );

		// Try to find by existing contacts.platform_uid first (legacy dedup).
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$contacts_table} WHERE platform = %s AND platform_uid = %s LIMIT 1",
			$platform,
			$platform_uid
		) );
		if ( $existing ) {
			$contact_id = (int) $existing;
		} else {
			// Insert new contact row.
			$new_contact = array(
				'name'       => $contact_name,
				'platform'   => strtoupper( $platform ),
				'platform_uid' => $platform_uid,
				'source'     => $contact_source,
				'created_at' => $now,
				'updated_at' => $now,
			);
			if ( ! empty( $create_contact_data['avatar_url'] ) ) {
				$new_contact['avatar_url'] = esc_url_raw( $create_contact_data['avatar_url'] );
			}
			if ( ! empty( $create_contact_data['phone'] ) ) {
				$new_contact['phone'] = sanitize_text_field( $create_contact_data['phone'] );
			}
			if ( ! empty( $create_contact_data['email'] ) ) {
				$new_contact['email'] = sanitize_email( $create_contact_data['email'] );
			}
			$wpdb->insert( $contacts_table, $new_contact );
			$contact_id = (int) $wpdb->insert_id;
		}

		if ( $contact_id <= 0 ) {
			return 0;
		}

		// 5. Register identity row (INSERT IGNORE for race-safe idempotency).
		$wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . '
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			VALUES (%d, %s, %s, %s, 1, %s, %s)',
			$contact_id,
			strtoupper( $platform ),
			$platform_uid,
			$account_id,
			$now,
			$now
		) );

		return $contact_id;
	}

	/**
	 * Return all identity rows for a contact.
	 *
	 * @param int $contact_id
	 * @return array[]
	 */
	public static function get_by_contact( int $contact_id ): array {
		global $wpdb;
		self::ensure();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE contact_id = %d ORDER BY is_primary DESC, id ASC',
				$contact_id
			),
			ARRAY_A
		) ?: array();
		return array_map( array( __CLASS__, 'hydrate' ), $rows );
	}

	/**
	 * Link an existing contact to a new platform identity (INSERT IGNORE).
	 *
	 * @param int    $contact_id
	 * @param string $platform
	 * @param string $platform_uid
	 * @param string $account_id
	 * @return bool
	 */
	public static function link( int $contact_id, string $platform, string $platform_uid, string $account_id = '' ): bool {
		global $wpdb;
		self::ensure();
		$now = current_time( 'mysql' );
		return false !== $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . '
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			VALUES (%d, %s, %s, %s, 0, %s, %s)',
			$contact_id,
			strtoupper( $platform ),
			$platform_uid,
			$account_id,
			$now,
			$now
		) );
	}

	/**
	 * Backfill identities from bizcity_crm_contacts (Wave 1 migration).
	 * Runs once — idempotent via INSERT IGNORE.
	 *
	 * @return int  Number of rows inserted.
	 */
	public static function backfill_from_contacts(): int {
		global $wpdb;
		self::ensure();
		$contacts_table = $wpdb->prefix . 'bizcity_crm_contacts';
		$now            = current_time( 'mysql' );
		$result         = $wpdb->query(
			"INSERT IGNORE INTO " . self::table() . "
			(contact_id, platform, platform_uid, account_id, is_primary, created_at, updated_at)
			SELECT id, UPPER(platform), platform_uid, '', 1, '{$now}', '{$now}'
			FROM {$contacts_table}
			WHERE platform_uid IS NOT NULL AND platform_uid <> ''"
		);
		return (int) $result;
	}

	/* ================================================================
	 *  Internal
	 * ================================================================ */

	private static function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['contact_id'] = (int) $row['contact_id'];
		$row['is_primary'] = (int) $row['is_primary'];
		$row['meta']       = isset( $row['meta_json'] ) && '' !== $row['meta_json']
			? json_decode( $row['meta_json'], true ) : null;
		return $row;
	}
}
