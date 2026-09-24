<?php
/**
 * BizCity CRM — Repository (write gate).
 *
 * SOLE entry point for INSERT/UPDATE on CRM tables (R-CRM-1).
 * Every state-change emits a Twin Event Stream event.
 *
 * Cache Contract (R-CACHE): group `crm_repository`; keys include the current
 * blog, routed database and hashed `(channel_type, channel_ref_id)` tuple;
 * inbox writes flush the group; TTL is short for account selection reads.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Repository {

	/**
	 * Build the bounded SQL preview used by Inbox list/read models.
	 *
	 * The preview is an index hint, never the cold-storage body. Existing rows
	 * with a NULL preview remain readable through the legacy content path until
	 * the H-02 backfill runs; new rows always receive a bounded value here.
	 *
	 * @param string $content
	 * @param string $content_type
	 * @param array  $attachments
	 * @return string
	 */
	public static function make_content_preview( string $content, string $content_type = 'text', array $attachments = array() ): string {
		// [2026-09-19 12:00 PM Johnny Chu] PHASE-0.56-H-02 — persist a bounded, body-independent preview at the canonical message write gate.
		$plain = wp_strip_all_tags( $content );
		$normalized = preg_replace( '/\s+/u', ' ', $plain );
		$preview = trim( is_string( $normalized ) ? $normalized : $plain );
		if ( '' !== $preview ) {
			// [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 (mục 3) — explicit UTF-8. Without the encoding argument
			// mb_substr() follows mb_internal_encoding(); on a byte-based setting a long Vietnamese message is cut
			// mid-character, $wpdb->insert() rejects the invalid UTF-8, the whole CRM row is lost and the Zalo
			// bridge retries the same message 25 times (blog 1258, 2026-09-24: 38 of 39 retried texts were long).
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $preview, 0, 255, 'UTF-8' );
			}
			return function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( substr( $preview, 0, 255 ), true ) : substr( $preview, 0, 255 );
		}

		$type = sanitize_key( $content_type );
		if ( 'sticker' === $type ) { return '[Sticker]'; }
		if ( 'image' === $type ) { return '[Ảnh]'; }
		if ( in_array( $type, array( 'audio', 'voice' ), true ) ) { return '[Audio]'; }
		if ( 'video' === $type ) { return '[Video]'; }
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) { continue; }
			$attachment_type = sanitize_key( (string) ( $attachment['file_type'] ?? $attachment['type'] ?? 'file' ) );
			if ( 'image' === $attachment_type ) { return '[Ảnh]'; }
			if ( 'sticker' === $attachment_type ) { return '[Sticker]'; }
			if ( in_array( $attachment_type, array( 'audio', 'voice' ), true ) ) { return '[Audio]'; }
			if ( 'video' === $attachment_type ) { return '[Video]'; }
			if ( '' !== $attachment_type ) { return '[Tệp]'; }
		}
		return '';
	}

	/**
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 (mục 3) — a refused message INSERT used to return 0 with no
	 * trace anywhere, so the Zalo bridge's 25 retries were the only symptom. Record sizes and the DB error only —
	 * never the message body (R-CH-FILE-LOG: no PII in logs).
	 */
	private static function log_insert_failure( array $row, string $db_error ): void {
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		$content = (string) ( $row['content'] ?? '' );
		$preview = (string) ( $row['content_preview'] ?? '' );
		BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_ERROR, 'crm_message_insert_failed', 'CRM message row was refused by the database.', array(
			'inbox_id'           => (int) ( $row['inbox_id'] ?? 0 ),
			'conversation_id'    => (int) ( $row['conversation_id'] ?? 0 ),
			'message_type'       => (string) ( $row['message_type'] ?? '' ),
			'content_type'       => (string) ( $row['content_type'] ?? '' ),
			'content_bytes'      => strlen( $content ),
			'preview_bytes'      => strlen( $preview ),
			'preview_valid_utf8' => '' === $preview || 1 === preg_match( '//u', $preview ),
			'ai_metadata_bytes'  => strlen( (string) ( $row['ai_metadata_json'] ?? '' ) ),
			'db_error'           => mb_substr( preg_replace( "/'[^']*'/", "'…'", $db_error ), 0, 200, 'UTF-8' ),
			'mb_internal_encoding' => function_exists( 'mb_internal_encoding' ) ? (string) mb_internal_encoding() : '',
		) );
	}

	/* ============================================================
	 * INBOX
	 * ============================================================ */

	/**
	 * Upsert inbox by (channel_type, channel_ref_id).
	 *
	 * @return int inbox_id (0 on failure)
	 */
	public static function upsert_inbox( string $channel_type, string $channel_ref_id, array $defaults = array() ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$cols = self::table_columns( $tbl );

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE channel_type = %s AND channel_ref_id = %s LIMIT 1",
			$channel_type, $channel_ref_id
		), ARRAY_A );

		if ( $existing ) {
			return (int) $existing['id'];
		}
		if ( ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
			return 0;
		}
		$descriptor = BizCity_CRM_Channel_Contract::require_crm_enabled( $channel_type );
		if ( is_wp_error( $descriptor ) ) {
			// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - do not create an inbox without a CRM-enabled registered channel owner.
			return 0;
		}

		$now = current_time( 'mysql' );
		$row = array(
			'name'                => $defaults['name'] ?? sprintf( '%s %s', strtoupper( $channel_type ), $channel_ref_id ),
			'channel_type'        => $channel_type,
			'channel_ref_id'      => $channel_ref_id,
			'default_notebook_id' => $defaults['default_notebook_id'] ?? null,
			'default_assignee_id' => $defaults['default_assignee_id'] ?? null,
			'settings_json'       => isset( $defaults['settings'] ) ? wp_json_encode( $defaults['settings'] ) : null,
			'is_active'           => 1,
			'created_at'          => $now,
			'updated_at'          => $now,
		);

		// [2026-07-08 Johnny Chu] HOTFIX — compatibility for drifted inbox schema on
		// some sites where column `channel_id` exists and is NOT NULL without default.
		// Canonical v2 schema uses (channel_type, channel_ref_id), but this fallback
		// prevents inbound ingest from failing with "Field 'channel_id' doesn't have a default value".
		if ( in_array( 'channel_id', $cols, true ) ) {
			$row['channel_id'] = $defaults['channel_id'] ?? $channel_ref_id;
		}

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'crm_repository' );
		}

		BizCity_CRM_Event_Emitter::emit( 'crm_inbox_created', array(
			'inbox_id'       => $id,
			'channel_type'   => $channel_type,
			'channel_ref_id' => $channel_ref_id,
		) );

		return $id;
	}

	/**
	 * Lightweight column cache for compatibility guards.
	 *
	 * @return string[]
	 */
	private static function table_columns( string $table ): array {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		global $wpdb;
		$rows = $wpdb->get_col( $wpdb->prepare(
			'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$table
		) );

		$cache[ $table ] = is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : array();
		return $cache[ $table ];
	}

	public static function invalidate_read_models(): void {
		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — invalidate scoped contact membership projections after CRM writes.
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'crm_repository' );
		}
		self::queue_change_signal();
	}

	/** @var bool One signal write per request, registered on the first CRM write. */
	private static $change_signal_pending = false;

	/**
	 * Mark that CRM data of this site changed; the marker file is rewritten once at shutdown (after every write of the request).
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — browsers poll this static file (no PHP) and only call
	 * the Inbox REST delta when it changes. Each REST poll costs a full WordPress bootstrap (~1.5–4s on bizcity.vn),
	 * which saturated PHP workers (Cloudflare 520/522/525). The file holds only an opaque version, never CRM data.
	 */
	public static function queue_change_signal(): void {
		if ( self::$change_signal_pending ) { return; }
		self::$change_signal_pending = true;
		register_shutdown_function( array( __CLASS__, 'write_change_signal' ) );
	}

	/** Atomically rewrite the per-site change marker. */
	public static function write_change_signal(): void {
		self::$change_signal_pending = false;
		$path = self::change_signal_path();
		if ( '' === $path ) { return; }
		$tmp = $path . '.' . getmypid() . '-' . mt_rand( 1000, 9999 ) . '.tmp';
		$payload = (string) wp_json_encode( array( 'v' => uniqid( '', true ), 't' => time() ) );
		if ( false === @file_put_contents( $tmp, $payload, LOCK_EX ) ) { return; }
		if ( ! @rename( $tmp, $path ) ) { @unlink( $tmp ); }
	}

	/**
	 * Public URL of this site's change marker, created on first use; '' when uploads are not writable.
	 */
	public static function change_signal_url(): string {
		$path = self::change_signal_path();
		if ( '' === $path ) { return ''; }
		if ( ! file_exists( $path ) ) {
			self::write_change_signal();
			if ( ! file_exists( $path ) ) { return ''; }
		}
		$uploads = wp_upload_dir( null, false );
		return set_url_scheme( trailingslashit( (string) $uploads['baseurl'] ) . 'bizcity-crm-signal/' . self::change_signal_name() . '.json' );
	}

	private static function change_signal_name(): string {
		// Unguessable per site; knowing it only reveals that some CRM record of the site changed.
		return substr( hash_hmac( 'sha256', 'crm-change-signal|' . get_current_blog_id(), wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function change_signal_path(): string {
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'wp_salt' ) ) { return ''; }
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) { return ''; }
		$dir = trailingslashit( (string) $uploads['basedir'] ) . 'bizcity-crm-signal';
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) { return ''; }
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir . '/' . self::change_signal_name() . '.json';
	}

	public static function get_inbox( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** The enum PHASE-0.71 F71-10 / 0.63C GC-6 (§4.4) declares for `inboxes.settings_json.purpose`. */
	const INBOX_PURPOSES = array( 'sales', 'purchasing', 'backoffice', 'production', 'mixed' );

	/**
	 * Set (or clear) `settings_json.purpose` on an existing inbox — 0.63C GC-6: "định vị số điện
	 * thoại đó dùng để làm gì". `upsert_inbox()` only ever writes `settings_json` once, at
	 * creation; this is the update path that never existed. Merges into whatever `settings_json`
	 * already holds — every other settings key on the inbox is preserved untouched.
	 *
	 * @return bool
	 */
	public static function set_inbox_purpose( int $inbox_id, string $purpose ): bool {
		if ( ! in_array( $purpose, self::INBOX_PURPOSES, true ) ) {
			return false;
		}
		$inbox = self::get_inbox( $inbox_id );
		if ( null === $inbox ) {
			return false;
		}
		$settings = json_decode( (string) ( $inbox['settings_json'] ?? '' ), true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['purpose'] = $purpose;

		global $wpdb;
		$updated = $wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_inboxes(),
			array(
				'settings_json' => wp_json_encode( $settings ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $inbox_id )
		);
		if ( false === $updated ) {
			return false;
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( 'crm_repository' );
		}
		self::invalidate_read_models();
		return true;
	}

	/**
	 * Read one active inbox by its exact channel and account reference.
	 *
	 * @param string $channel_type Canonical CRM channel code.
	 * @param string $channel_ref_id Exact provider/account reference.
	 * @return array|null
	 */
	public static function get_inbox_by_ref( string $channel_type, string $channel_ref_id ): ?array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7 — provide the canonical exact-account inbox reader for the future /gpt/crm/ scope without broad inbox scans.
		$channel_type   = sanitize_key( $channel_type );
		$channel_ref_id = trim( $channel_ref_id );
		if ( $channel_type === '' || $channel_ref_id === '' ) {
			return null;
		}

		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$tuple_hash = md5( $channel_type . '\0' . $channel_ref_id );
		$cache_key = 'inbox_by_ref_' . $blog_id . '_' . md5( $database ) . '_' . $tuple_hash;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_repository', $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['_not_found'] ) ) {
				return null;
			}
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE channel_type = %s AND channel_ref_id = %s AND is_active = 1 LIMIT 1",
			$channel_type,
			$channel_ref_id
		), ARRAY_A );
		$row = is_array( $row ) ? $row : null;
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( 'crm_repository', $cache_key, $row ?: array( '_not_found' => true ), BizCity_Cache::TTL_SHORT );
		}
		return $row;
	}

	/**
	 * Read-only: the CRM contact already attached to `(inbox_id, source_id)`, or 0 when there is none yet.
	 *
	 * Unlike upsert_contact()/upsert_contact_by_identity() this NEVER writes — callers that only need to know
	 * "who is this, if we know them" (e.g. Bot Studio deciding whether to take a turn, before the CRM ingestor
	 * has persisted the message) must not create a contact as a side effect.
	 *
	 * [2026-09-24 Claude Sonnet 5] PHASE-0.60H — added for BizCity_Bot_Turn_Claim, which used to read
	 * `contact_id` off the normalized channel envelope; that field is an Identity Hub row id, not a CRM id.
	 */
	public static function find_contact_id_by_source( int $inbox_id, string $source_id ): int {
		$source_id = trim( $source_id );
		if ( $inbox_id <= 0 || '' === $source_id ) {
			return 0;
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT contact_id FROM {$table} WHERE inbox_id = %d AND source_id = %s ORDER BY id ASC LIMIT 1",
			$inbox_id,
			$source_id
		) );
	}

	public static function list_inboxes(): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE is_active = 1 ORDER BY created_at DESC", ARRAY_A );
		return $rows ?: array();
	}

	/** Determine whether an inbox is explicitly marked as diagnostic/test data. */
	public static function is_test_inbox( array $inbox ): bool {
		// [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — keep destructive cleanup limited to named test fixtures.
		$label = strtolower( (string) ( $inbox['name'] ?? '' ) . ' ' . ( $inbox['channel_ref_id'] ?? '' ) );
		return false !== strpos( $label, '__diag' )
			|| (bool) preg_match( '/(^|[^a-z])(diag(?:_page)?|healthtest)([^a-z]|$)/i', $label );
	}

	/** Delete a marked test inbox and only data owned by that inbox. */
	public static function delete_inbox( int $inbox_id ): bool {
		// [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-CLEANUP — transactional purge for diagnostic inbox fixtures.
		if ( $inbox_id <= 0 ) { return false; }
		$inbox = self::get_inbox( $inbox_id );
		if ( ! $inbox || ! self::is_test_inbox( $inbox ) ) { return false; }
		return self::purge_inbox( $inbox_id );
	}

	/** Delete a legacy Zalo Personal inbox only after its managed mapping is gone. */
	public static function delete_legacy_zalo_personal_inbox( int $inbox_id ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39C — legacy direct Zalo channels may be purged from CRM, but active managed mappings are protected.
		if ( $inbox_id <= 0 ) { return 'inbox_not_found'; }
		$inbox = self::get_inbox( $inbox_id );
		if ( ! $inbox ) { return 'inbox_not_found'; }
		if ( 'zalo_personal' !== strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) ) { return 'zalo_personal_only'; }
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_crm_inbox_id' ) ) {
			$mapped = BizCity_Zalo_Mapping_Repo::find_account_by_crm_inbox_id( $inbox_id );
			if ( $mapped && class_exists( 'BizCity_Zalo_Bridge_Client' ) ) {
				// [2026-08-22 Johnny Chu] PHASE-0.39C — distinguish an active exact-key managed account from a stale legacy mapping before allowing cleanup.
				$bridge = BizCity_Zalo_Bridge_Client::instance();
				$remote = method_exists( $bridge, 'list_accounts' ) ? $bridge->list_accounts() : array( 'success' => false );
				if ( ! empty( $remote['_degraded'] ) || empty( $remote['success'] ) ) { return 'managed_scope_unavailable'; }
				foreach ( (array) ( $remote['accounts'] ?? array() ) as $remote_account ) {
					if ( (string) ( $remote_account['id'] ?? '' ) === (string) ( $mapped['bridge_account_id'] ?? '' ) ) {
						return 'managed_mapping_exists';
					}
				}
			}
		}
		return self::purge_inbox( $inbox_id ) ? 'deleted' : 'inbox_delete_failed';
	}

	/**
	 * [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.53 N4 (S5 `mode=purge`) — hard-delete a REAL,
	 * actively-managed Zalo Personal inbox and everything under it. `delete_inbox()` above only
	 * accepts test/diagnostic fixtures and `delete_legacy_zalo_personal_inbox()` only unmapped legacy
	 * channels — this is for exactly the case those two refuse: a live managed inbox someone actually
	 * wants gone. The caller (`class-staff-rest.php::remove_phone()`) MUST have already gated this
	 * behind admin-only + a typed confirmation and torn down the Hub-side session first — this method
	 * re-checks none of that, it only confirms the inbox really is a `zalo_personal` one so a bad
	 * `inbox_id` can never wipe an unrelated channel.
	 */
	public static function purge_managed_personal_inbox( int $inbox_id ): bool {
		if ( $inbox_id <= 0 ) { return false; }
		$inbox = self::get_inbox( $inbox_id );
		if ( ! $inbox || 'zalo_personal' !== strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) ) { return false; }
		return self::purge_inbox( $inbox_id );
	}

	/** Purge data owned by one inbox inside a transaction. */
	private static function purge_inbox( int $inbox_id ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39C — share the existing transactional purge with the explicitly gated legacy-channel action.

		global $wpdb;
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$tbl_att  = BizCity_CRM_DB_Installer_V2::tbl_attachments();
		$tbl_cl   = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$tbl_sla  = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$tbl_wh   = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$tbl_rule = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();

		$conv_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tbl_conv} WHERE inbox_id = %d", $inbox_id ) );
		$conv_ids = array_values( array_filter( array_map( 'intval', (array) $conv_ids ) ) );
		$conv_sql = 'conversation_id IN (0)';
		if ( $conv_ids ) {
			$conv_placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
			$conv_sql = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( 'conversation_id IN (' . $conv_placeholders . ')' ), $conv_ids ) );
		}
		$inbox_sql = $wpdb->prepare( 'inbox_id = %d', $inbox_id );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$queries = array(
				"DELETE FROM {$tbl_att} WHERE message_id IN (SELECT id FROM {$tbl_msg} WHERE {$conv_sql})",
				"DELETE FROM {$tbl_msg} WHERE {$conv_sql}",
				"DELETE FROM {$tbl_sla} WHERE {$conv_sql}",
				"DELETE FROM {$tbl_cl} WHERE {$conv_sql}",
				"DELETE FROM {$tbl_conv} WHERE {$inbox_sql}",
				"DELETE FROM {$tbl_ci} WHERE {$inbox_sql}",
				"DELETE FROM {$tbl_wh} WHERE {$inbox_sql}",
				"UPDATE {$tbl_rule} SET inbox_id = NULL WHERE {$inbox_sql}",
				"DELETE FROM {$tbl_ibx} WHERE id = " . (int) $inbox_id,
			);
			foreach ( $queries as $query ) {
				if ( false === $wpdb->query( $query ) ) {
					throw new \RuntimeException( 'inbox_delete_query_failed' );
				}
			}
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		BizCity_CRM_Event_Emitter::emit( 'crm_inbox_deleted', array( 'inbox_id' => $inbox_id ) );
		return true;
	}

	/* ============================================================
	 * CONTACT + CONTACT_INBOX
	 * ============================================================ */

	/**
	 * Upsert one contact across the tenant by canonical email/phone identity,
	 * then attach the source to the supplied inbox.
	 *
	 * @param int    $inbox_id
	 * @param string $source_id Stable channel identity hash.
	 * @param array  $contact_data
	 * @return array{contact_id:int,contact_inbox_id:int,action:string}
	 */
	public static function upsert_contact_by_identity( int $inbox_id, string $source_id, array $contact_data = array() ): array {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - unify Wheel captures with existing tenant contacts before inbox attachment.
		if ( $inbox_id <= 0 || $source_id === '' ) {
			return array( 'contact_id' => 0, 'contact_inbox_id' => 0, 'action' => 'skipped' );
		}
		global $wpdb;
		$contacts_table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$inboxes_table  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$email = sanitize_email( (string) ( $contact_data['email'] ?? '' ) );
		$phone = (string) ( $contact_data['phone'] ?? '' );
		if ( $phone !== '' && class_exists( 'BizCity_Phone_Normalizer' ) ) {
			$phone = BizCity_Phone_Normalizer::normalize_vn( $phone );
		}
		$contact_id = 0;
		if ( $email !== '' ) {
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$contacts_table} WHERE email = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $email ) );
		}
		if ( $contact_id <= 0 && $phone !== '' ) {
			$contact_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$contacts_table} WHERE phone = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1", $phone ) );
		}
		$name = sanitize_text_field( (string) ( $contact_data['name'] ?? '' ) );
		$attrs = isset( $contact_data['additional_attributes'] ) && is_array( $contact_data['additional_attributes'] ) ? $contact_data['additional_attributes'] : array();
		if ( $contact_id > 0 ) {
			$existing = self::get_contact( $contact_id );
			$old_attrs = is_array( json_decode( (string) ( $existing['additional_attributes'] ?? '' ), true ) ) ? json_decode( (string) $existing['additional_attributes'], true ) : array();
			$update = array( 'updated_at' => current_time( 'mysql' ) );
			if ( $name !== '' && empty( $existing['name'] ) ) { $update['name'] = $name; }
			if ( $email !== '' && empty( $existing['email'] ) ) { $update['email'] = $email; }
			if ( $phone !== '' && empty( $existing['phone'] ) ) { $update['phone'] = $phone; }
			if ( $attrs ) { $update['additional_attributes'] = wp_json_encode( array_merge( $old_attrs, $attrs ) ); }
			if ( ! empty( $contact_data['acquisition_source'] ) && empty( $existing['acquisition_source'] ) ) { $update['acquisition_source'] = sanitize_key( (string) $contact_data['acquisition_source'] ); }
			$wpdb->update( $contacts_table, $update, array( 'id' => $contact_id ) );
			$action = 'updated';
		} else {
			$wpdb->insert( $contacts_table, array(
				'name'                  => $name,
				'email'                 => $email !== '' ? $email : null,
				'phone'                 => $phone !== '' ? $phone : null,
				'acquisition_source'    => sanitize_key( (string) ( $contact_data['acquisition_source'] ?? 'mabel_wheel' ) ),
				'acquisition_meta_json' => ! empty( $contact_data['acquisition_meta'] ) ? wp_json_encode( $contact_data['acquisition_meta'] ) : null,
				'additional_attributes' => $attrs ? wp_json_encode( $attrs ) : null,
				'created_at'            => current_time( 'mysql' ),
				'updated_at'            => current_time( 'mysql' ),
			) );
			$contact_id = (int) $wpdb->insert_id;
			$action = $contact_id > 0 ? 'created' : 'error';
		}
		if ( $contact_id <= 0 ) {
			return array( 'contact_id' => 0, 'contact_inbox_id' => 0, 'action' => 'error' );
		}
		$contact_inbox_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$inboxes_table} WHERE inbox_id = %d AND contact_id = %d AND source_id = %s LIMIT 1", $inbox_id, $contact_id, $source_id ) );
		if ( $contact_inbox_id <= 0 ) {
			$wpdb->insert( $inboxes_table, array( 'contact_id' => $contact_id, 'inbox_id' => $inbox_id, 'source_id' => $source_id, 'last_seen_at' => current_time( 'mysql' ), 'created_at' => current_time( 'mysql' ) ) );
			$contact_inbox_id = (int) $wpdb->insert_id;
		} else {
			$wpdb->update( $inboxes_table, array( 'last_seen_at' => current_time( 'mysql' ) ), array( 'id' => $contact_inbox_id ) );
		}
		self::invalidate_read_models();
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.22A-WP5 — emit one canonical contact upsert event after identity/contact-inbox mutation succeeds.
		if ( $contact_inbox_id > 0 && class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array(
				'contact_id'       => $contact_id,
				'inbox_id'         => $inbox_id,
				'contact_inbox_id' => $contact_inbox_id,
				'source_id'        => $source_id,
				'action'           => $action,
			) );
		}
		return array( 'contact_id' => $contact_id, 'contact_inbox_id' => $contact_inbox_id, 'action' => $action );
	}

	/**
	 * Insert one resolved passive intake message idempotently.
	 *
	 * @param int   $inbox_id
	 * @param int   $contact_inbox_id
	 * @param array $data
	 * @return array{duplicate:bool,conversation_id:int,message_id:int}
	 */
	public static function ingest_resolved_intake( int $inbox_id, int $contact_inbox_id, array $data = array() ): array {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - create one resolved intake through Repository ownership.
		global $wpdb;
		$external_id = (string) ( $data['external_source_id'] ?? '' );
		$message_table = BizCity_CRM_DB_Installer_V2::tbl_messages();
		if ( $external_id !== '' ) {
			$existing_message = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$message_table} WHERE inbox_id = %d AND external_source_id = %s LIMIT 1", $inbox_id, $external_id ) );
			if ( $existing_message > 0 ) {
				return array( 'duplicate' => true, 'conversation_id' => 0, 'message_id' => $existing_message );
			}
		}
		$conversation_id = self::open_or_get_conversation( $inbox_id, $contact_inbox_id );
		if ( $conversation_id <= 0 ) {
			return array( 'duplicate' => false, 'conversation_id' => 0, 'message_id' => 0 );
		}
		$message_id = self::insert_message( array_merge( $data, array( 'conversation_id' => $conversation_id, 'inbox_id' => $inbox_id ) ) );
		if ( $message_id <= 0 ) {
			return array( 'duplicate' => false, 'conversation_id' => $conversation_id, 'message_id' => 0 );
		}
		self::set_conversation_status( $conversation_id, 'resolved' );
		return array( 'duplicate' => false, 'conversation_id' => $conversation_id, 'message_id' => $message_id );
	}

	/**
	 * Upsert contact identified by (inbox_id, source_id) tuple.
	 * Returns assoc array {contact_id, contact_inbox_id}.
	 */
	public static function upsert_contact( int $inbox_id, string $source_id, array $contact_data = array() ): array {
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — normalize phone at the canonical Contacts write boundary.
		if ( array_key_exists( 'phone', $contact_data ) && class_exists( 'BizCity_Phone_Normalizer' ) ) {
			$contact_data['phone'] = BizCity_Phone_Normalizer::normalize_vn( (string) $contact_data['phone'] );
		}
		global $wpdb;
		$ci_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ct_tbl  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$now     = current_time( 'mysql' );

		// Existing contact_inbox?
		$ci = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$ci_tbl} WHERE inbox_id = %d AND source_id = %s LIMIT 1",
			$inbox_id, $source_id
		), ARRAY_A );

		if ( $ci ) {
			$wpdb->update( $ci_tbl, array( 'last_seen_at' => $now ), array( 'id' => $ci['id'] ) );
			// Refresh contact name / avatar if we have new data and old is empty.
			if ( ! empty( $contact_data['name'] ) || ! empty( $contact_data['avatar_url'] ) || ! empty( $contact_data['acquisition_source'] ) || ! empty( $contact_data['name_source'] ) || ! empty( $contact_data['additional_attributes'] ) ) {
				$existing_contact = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$ct_tbl} WHERE id = %d", (int) $ci['contact_id']
				), ARRAY_A );
				$update = array( 'updated_at' => $now );
				$existing_attrs = json_decode( (string) ( $existing_contact['additional_attributes'] ?? '' ), true );
				$existing_attrs = is_array( $existing_attrs ) ? $existing_attrs : array();
				if ( ! empty( $contact_data['additional_attributes'] ) && is_array( $contact_data['additional_attributes'] ) ) {
					// [2026-09-08 04:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48-CX2 — merge bounded group label metadata without overwriting canonical contact identity fields.
					$existing_attrs = array_merge( $existing_attrs, array_intersect_key( $contact_data['additional_attributes'], array_flip( array( 'group_name' ) ) ) );
					$update['additional_attributes'] = wp_json_encode( $existing_attrs );
				}
				$incoming_name_source = sanitize_key( (string) ( $contact_data['name_source'] ?? '' ) );
				$existing_name_source = sanitize_key( (string) ( $existing_attrs['contact_name_source'] ?? '' ) );
				$old_name        = (string) ( $existing_contact['name'] ?? '' );
				$old_is_stub     = ( $old_name === '' )
					|| (bool) preg_match( '/^(FB|Zalo|Web|TG|Hotline)\s+[A-Za-z0-9_]{1,8}$/u', $old_name );
				$old_is_unreliable = 'self_echo_unreliable' === $existing_name_source;
				if ( ! empty( $contact_data['name'] ) && ( $old_is_stub || ( $old_is_unreliable && 'customer_provided' === $incoming_name_source ) ) && $contact_data['name'] !== $old_name ) {
					$update['name'] = $contact_data['name'];
				}
				// [2026-08-23 Johnny Chu] PHASE-0.39D — never downgrade a verified contact name to self-echo provenance.
				if ( $incoming_name_source !== '' && ( 'customer_provided' === $incoming_name_source || $old_is_stub ) && ( $existing_name_source === '' || ( 'customer_provided' === $incoming_name_source && 'self_echo_unreliable' === $existing_name_source ) ) ) {
					$existing_attrs['contact_name_source'] = $incoming_name_source;
					$update['additional_attributes'] = wp_json_encode( $existing_attrs );
				}
				// [2026-08-04 Johnny Chu] HOTFIX — persist a refreshed Facebook CDN avatar when the signed URL rotates.
				if ( ! empty( $contact_data['avatar_url'] )
					&& ( empty( $existing_contact['avatar_url'] ) || (string) $existing_contact['avatar_url'] !== (string) $contact_data['avatar_url'] ) ) {
					$update['avatar_url'] = $contact_data['avatar_url'];
				}
				// [2026-08-23 Johnny Chu] PHASE-0.39D — fill missing acquisition provenance without overwriting multi-channel history.
				if ( empty( $existing_contact['acquisition_source'] ) && ! empty( $contact_data['acquisition_source'] ) ) {
					$update['acquisition_source'] = sanitize_key( (string) $contact_data['acquisition_source'] );
				}
				// PHASE 0.35 M-CRM.M8.W3 — opportunistic Woo user link.
				if ( empty( $existing_contact['wp_user_id'] ) ) {
					$resolved_uid = self::resolve_wp_user_id(
						$contact_data['email'] ?? ( $existing_contact['email'] ?? '' ),
						$contact_data['phone'] ?? ( $existing_contact['phone'] ?? '' )
					);
					if ( $resolved_uid > 0 ) {
						$update['wp_user_id'] = $resolved_uid;
						do_action( 'bizcity_crm_contact_woo_link_resolved', array(
							'contact_id'   => (int) $ci['contact_id'],
							'wp_user_id'   => $resolved_uid,
							'match_method' => $resolved_uid && ! empty( $contact_data['email'] ) ? 'email' : 'phone',
						) );
					}
				}
				if ( count( $update ) > 1 ) {
					$wpdb->update( $ct_tbl, $update, array( 'id' => $ci['contact_id'] ) );
				}
			}
			self::invalidate_read_models();
			return array(
				'contact_id'       => (int) $ci['contact_id'],
				'contact_inbox_id' => (int) $ci['id'],
			);
		}

		// New contact.
		// PHASE 0.35 M-CRM.M8.W3 — try to attach a wp_user_id when the channel
		// supplied an email/phone that already matches a registered user.
		$initial_wp_user_id = $contact_data['wp_user_id'] ?? null;
		if ( ! $initial_wp_user_id ) {
			$resolved_uid = self::resolve_wp_user_id(
				(string) ( $contact_data['email'] ?? '' ),
				(string) ( $contact_data['phone'] ?? '' )
			);
			if ( $resolved_uid > 0 ) { $initial_wp_user_id = $resolved_uid; }
		}

		$wpdb->insert( $ct_tbl, array(
			'name'                  => $contact_data['name']       ?? '',
			'email'                 => $contact_data['email']      ?? null,
			'phone'                 => $contact_data['phone']      ?? null,
			'avatar_url'            => $contact_data['avatar_url'] ?? null,
			'additional_attributes' => self::contact_attributes_with_name_source( $contact_data ),
			'wp_user_id'            => $initial_wp_user_id,
			'acquisition_source'    => ! empty( $contact_data['acquisition_source'] ) ? sanitize_key( (string) $contact_data['acquisition_source'] ) : null,
			'acquisition_meta_json' => ! empty( $contact_data['acquisition_meta'] ) ? wp_json_encode( $contact_data['acquisition_meta'] ) : null,
			'created_at'            => $now,
			'updated_at'            => $now,
		) );
		$contact_id = (int) $wpdb->insert_id;

		if ( $initial_wp_user_id ) {
			do_action( 'bizcity_crm_contact_woo_link_resolved', array(
				'contact_id'   => $contact_id,
				'wp_user_id'   => (int) $initial_wp_user_id,
				'match_method' => ! empty( $contact_data['email'] ) ? 'email' : 'phone',
			) );
		}

		$wpdb->insert( $ci_tbl, array(
			'contact_id'   => $contact_id,
			'inbox_id'     => $inbox_id,
			'source_id'    => $source_id,
			'last_seen_at' => $now,
			'created_at'   => $now,
		) );
		$ci_id = (int) $wpdb->insert_id;
		self::invalidate_read_models();

		BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array(
			'contact_id' => $contact_id,
			'inbox_id'   => $inbox_id,
			'source_id'  => $source_id,
		) );

		return array(
			'contact_id'       => $contact_id,
			'contact_inbox_id' => $ci_id,
		);
	}

	private static function contact_attributes_with_name_source( array $contact_data ) {
		$attrs = isset( $contact_data['additional_attributes'] ) && is_array( $contact_data['additional_attributes'] )
			? $contact_data['additional_attributes']
			: array();
		if ( ! empty( $contact_data['name_source'] ) ) {
			$attrs['contact_name_source'] = sanitize_key( (string) $contact_data['name_source'] );
		}
		return $attrs ? wp_json_encode( $attrs ) : null;
	}

	/**
	 * PHASE 0.35 M-CRM.M8.W3 — Try to find a wp_users.ID that matches the
	 * given email or billing_phone. Returns 0 when nothing matches.
	 *
	 * Lookup precedence:
	 *   1. wp_users.user_email = $email
	 *   2. wp_usermeta.billing_email = $email
	 *   3. wp_usermeta.billing_phone = $phone
	 */
	public static function resolve_wp_user_id( string $email, string $phone ): int {
		// [2026-09-08 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX0 — fail closed on phone/email identity collisions; never auto-link the first matching user.
		global $wpdb;
		$email = trim( $email );
		// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — normalize phone before WP billing lookup.
		$phone = class_exists( 'BizCity_Phone_Normalizer' )
			? BizCity_Phone_Normalizer::normalize_vn( $phone )
			: trim( $phone );

		$email_ids = array();
		$phone_ids = array();
		if ( $email !== '' ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) { $email_ids[] = (int) $user->ID; }
			$email_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='billing_email' AND meta_value=%s",
				$email
			) );
			$email_ids = array_values( array_unique( array_merge( $email_ids, array_map( 'intval', is_array( $email_meta_ids ) ? $email_meta_ids : array() ) ) ) );
		}
		if ( $phone !== '' ) {
			$phone_values = array_values( array_unique( array_filter( array( $phone, trim( $phone ) ) ) ) );
			$phone_placeholders = implode( ',', array_fill( 0, count( $phone_values ), '%s' ) );
			$phone_params = array_merge( array( 'billing_phone' ), $phone_values );
			$phone_meta_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value IN ({$phone_placeholders})",
				$phone_params
			) );
			$phone_ids = array_values( array_unique( array_map( 'intval', is_array( $phone_meta_ids ) ? $phone_meta_ids : array() ) ) );
		}
		if ( count( $email_ids ) > 1 || count( $phone_ids ) > 1 ) { return 0; }
		if ( ! empty( $email_ids ) && ! empty( $phone_ids ) && (int) $email_ids[0] !== (int) $phone_ids[0] ) { return 0; }
		return ! empty( $email_ids ) ? (int) $email_ids[0] : ( ! empty( $phone_ids ) ? (int) $phone_ids[0] : 0 );
	}

	public static function get_contact( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Enrich an EXISTING contact (PHASE-0.60B §4.1): fill only empty scalar slots,
	 * merge additional_attributes, and write birthday only when it is still NULL.
	 * Staff-entered values always win; this never overwrites.
	 *
	 * @param array $data { name?, email?, phone?, avatar_url?, additional_attributes?: array, birthday?: Y-m-d, birthday_md?: MM-DD, birthday_meta?: array }
	 * @return array{updated:array,birthday_set:bool}
	 */
	public static function enrich_contact( int $contact_id, array $data ): array {
		// [2026-09-23 04:20 PM Claude Fable 5.1] PHASE-0.60B C3.3 — same fill-only-empty law as upsert_contact_by_identity(), keyed by contact_id.
		global $wpdb;
		$out = array( 'updated' => array(), 'birthday_set' => false );
		$existing = self::get_contact( $contact_id );
		if ( ! is_array( $existing ) || ! empty( $existing['deleted_at'] ) ) {
			return $out;
		}
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$update = array();
		foreach ( array( 'name', 'email', 'phone', 'avatar_url' ) as $field ) {
			$val = isset( $data[ $field ] ) ? trim( (string) $data[ $field ] ) : '';
			if ( $val !== '' && trim( (string) ( $existing[ $field ] ?? '' ) ) === '' ) {
				$update[ $field ] = 'email' === $field ? sanitize_email( $val ) : ( 'avatar_url' === $field ? esc_url_raw( $val ) : sanitize_text_field( $val ) );
			}
		}
		$old_attrs = is_array( json_decode( (string) ( $existing['additional_attributes'] ?? '' ), true ) ) ? json_decode( (string) $existing['additional_attributes'], true ) : array();
		$new_attrs = isset( $data['additional_attributes'] ) && is_array( $data['additional_attributes'] ) ? $data['additional_attributes'] : array();
		$has_birthday_cols = function_exists( 'bizcity_column_exists' ) ? bizcity_column_exists( $tbl, 'birthday' ) : true;
		if ( $has_birthday_cols ) {
			$cur_birthday = (string) ( $existing['birthday'] ?? '' );
			$cur_md       = (string) ( $existing['birthday_md'] ?? '' );
			$new_birthday = isset( $data['birthday'] ) ? (string) $data['birthday'] : '';
			$new_md       = isset( $data['birthday_md'] ) ? (string) $data['birthday_md'] : '';
			if ( ( $cur_birthday === '' || $cur_birthday === '0000-00-00' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_birthday ) ) {
				$update['birthday']    = $new_birthday;
				$update['birthday_md'] = substr( $new_birthday, 5 );
				$out['birthday_set']   = true;
			} elseif ( $cur_birthday === '' && $cur_md === '' && preg_match( '/^\d{2}-\d{2}$/', $new_md ) ) {
				$update['birthday_md'] = $new_md;
				$out['birthday_set']   = true;
			}
			if ( $out['birthday_set'] && isset( $data['birthday_meta'] ) && is_array( $data['birthday_meta'] ) ) {
				$new_attrs['birthday_meta'] = $data['birthday_meta'];
			}
		}
		if ( $new_attrs ) {
			$update['additional_attributes'] = wp_json_encode( array_merge( $old_attrs, $new_attrs ), JSON_UNESCAPED_UNICODE );
		}
		if ( empty( $update ) ) {
			return $out;
		}
		$update['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( $tbl, $update, array( 'id' => $contact_id ) );
		if ( false === $ok ) {
			return array( 'updated' => array(), 'birthday_set' => false );
		}
		unset( $update['updated_at'] );
		$out['updated'] = array_keys( $update );
		self::invalidate_read_models();
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_contact_upserted', array( 'contact_id' => $contact_id, 'action' => 'enriched', 'fields' => $out['updated'] ) );
		}
		return $out;
	}

	/**
	 * Write birthday (PHASE-0.60B §3). `$date` Y-m-d or '' (year unknown) with `$md` MM-DD.
	 * Non-forced writers fill only an empty slot; `$force` (staff) may overwrite or clear (both '').
	 */
	public static function set_contact_birthday( int $contact_id, string $date, string $md, bool $force = false, array $attrs = array() ): bool {
		// [2026-09-23 04:20 PM Claude Fable 5.1] PHASE-0.60B C3.3/C4.3 — one writer for the real column pair.
		global $wpdb;
		$existing = self::get_contact( $contact_id );
		if ( ! is_array( $existing ) || ! empty( $existing['deleted_at'] ) ) {
			return false;
		}
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( function_exists( 'bizcity_column_exists' ) && ! bizcity_column_exists( $tbl, 'birthday' ) ) {
			return false;
		}
		if ( $date !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		if ( $md !== '' && ! preg_match( '/^\d{2}-\d{2}$/', $md ) ) {
			return false;
		}
		$cur_birthday = (string) ( $existing['birthday'] ?? '' );
		$cur_md       = (string) ( $existing['birthday_md'] ?? '' );
		$has_current  = ( $cur_birthday !== '' && $cur_birthday !== '0000-00-00' ) || $cur_md !== '';
		if ( $has_current && ! $force ) {
			return false; // rule 1: never overwrite without explicit staff intent.
		}
		$old_attrs = is_array( json_decode( (string) ( $existing['additional_attributes'] ?? '' ), true ) ) ? json_decode( (string) $existing['additional_attributes'], true ) : array();
		$update = array(
			'birthday'    => $date !== '' ? $date : null,
			'birthday_md' => $md !== '' ? $md : ( $date !== '' ? substr( $date, 5 ) : null ),
			'updated_at'  => current_time( 'mysql' ),
		);
		if ( $attrs ) {
			$update['additional_attributes'] = wp_json_encode( array_merge( $old_attrs, $attrs ), JSON_UNESCAPED_UNICODE );
		}
		$ok = $wpdb->update( $tbl, $update, array( 'id' => $contact_id ) );
		if ( false === $ok ) {
			return false;
		}
		self::invalidate_read_models();
		return true;
	}

	/** Customer withdrawal (PHASE-0.60B rule 6): drop enriched attributes + birthday, remember the opt-out window. */
	public static function clear_contact_enrichment( int $contact_id, string $opt_out_until ): bool {
		// [2026-09-23 04:20 PM Claude Fable 5.1] PHASE-0.60B C3.8.
		global $wpdb;
		$existing = self::get_contact( $contact_id );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$attrs = is_array( json_decode( (string) ( $existing['additional_attributes'] ?? '' ), true ) ) ? json_decode( (string) $existing['additional_attributes'], true ) : array();
		unset( $attrs['zalo_profile'], $attrs['birthday_meta'], $attrs['birth_time'], $attrs['birth_place'] );
		$attrs['enrichment_opt_out_until'] = $opt_out_until;
		$update = array( 'additional_attributes' => wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) );
		if ( ! function_exists( 'bizcity_column_exists' ) || bizcity_column_exists( $tbl, 'birthday' ) ) {
			$update['birthday']    = null;
			$update['birthday_md'] = null;
		}
		$ok = $wpdb->update( $tbl, $update, array( 'id' => $contact_id ) );
		if ( false === $ok ) {
			return false;
		}
		self::invalidate_read_models();
		return true;
	}

	/** Contact ids whose birthday is on MM-DD (indexed read; used by diagnostics/reports). */
	public static function contact_ids_with_birthday_md( string $md, int $limit = 500 ): array {
		global $wpdb;
		if ( ! preg_match( '/^\d{2}-\d{2}$/', $md ) ) {
			return array();
		}
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( function_exists( 'bizcity_column_exists' ) && ! bizcity_column_exists( $tbl, 'birthday_md' ) ) {
			return array();
		}
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE birthday_md = %s AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $md, max( 1, min( 5000, $limit ) ) ) );
		return array_map( 'intval', (array) $rows );
	}

	/* ============================================================
	 * CONVERSATION
	 * ============================================================ */

	/**
	 * Get or open the active (status=open|pending) conversation for a contact-inbox.
	 *
	 * @return int conversation_id
	 */
	public static function open_or_get_conversation( int $inbox_id, int $contact_inbox_id, array $defaults = array() ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();

		$conv = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE inbox_id = %d AND contact_inbox_id = %d AND status IN ('open','pending')
			 ORDER BY id DESC LIMIT 1",
			$inbox_id, $contact_inbox_id
		), ARRAY_A );

		if ( $conv ) {
			return (int) $conv['id'];
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( $tbl, array(
			'inbox_id'         => $inbox_id,
			'contact_inbox_id' => $contact_inbox_id,
			'status'           => 'open',
			'assignee_id'      => $defaults['assignee_id'] ?? null,
			'notebook_id'      => $defaults['notebook_id'] ?? null,
			'priority'         => 0,
			'last_activity_at' => $now,
			'unread_count'     => 0,
			'created_at'       => $now,
			'updated_at'       => $now,
		) );
		$id = (int) $wpdb->insert_id;
		self::invalidate_read_models();

		BizCity_CRM_Event_Emitter::emit( 'crm_conversation_opened', array(
			'conversation_id'  => $id,
			'inbox_id'         => $inbox_id,
			'contact_inbox_id' => $contact_inbox_id,
		) );

		return $id;
	}

	public static function get_conversation( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Resolve the canonical contact_id for a conversation.
	 *
	 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CRM-CONTEXT — the
	 * conversations table stores `contact_inbox_id`, not `contact_id`, so any
	 * caller that needs the customer reference must join `contact_inboxes`.
	 * Returning 0 means the conversation has no linked contact profile yet; the
	 * caller must fail closed instead of inventing an identity.
	 */
	public static function get_conversation_contact_id( int $conversation_id ): int {
		global $wpdb;
		if ( $conversation_id <= 0 ) { return 0; }
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$contact_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT ci.contact_id FROM {$tbl_conv} c JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id WHERE c.id = %d LIMIT 1",
			$conversation_id
		) );
		return (int) $contact_id;
	}

	/**
	 * Read-only: the CRM thread a conversation belongs to — its contact and the channel thread key
	 * (`<uid>` for a private Zalo chat, `group:<group_id>` for a group).
	 *
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — Bot Studio needs this to take a turn that did not
	 * start from a webhook (the composer "AI reply" button). Conversations store `contact_inbox_id`, not
	 * the contact or the source key, so both come from `contact_inboxes`. Empty values = fail closed.
	 *
	 * @return array{contact_id:int,source_id:string}
	 */
	public static function get_conversation_thread_ref( int $conversation_id ): array {
		$out = array( 'contact_id' => 0, 'source_id' => '' );
		if ( $conversation_id <= 0 ) { return $out; }
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT ci.contact_id, ci.source_id FROM {$tbl_conv} c JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id WHERE c.id = %d LIMIT 1",
			$conversation_id
		), ARRAY_A );
		if ( is_array( $row ) ) {
			$out['contact_id'] = (int) ( $row['contact_id'] ?? 0 );
			$out['source_id']  = (string) ( $row['source_id'] ?? '' );
		}
		return $out;
	}

	/**
	 * List conversations with optional inbox filter, status filter, and pagination.
	 *
	 * @param array $args { id?, inbox_id?, status?, priority?, snoozed?, assignee_id?, unassigned?, participating_user_id?, unattended?, q?, limit?, before_id? }
	 *                    priority: int 0..3 OR string low|med|high|urgent
	 *                    snoozed:  bool — true => snoozed_until > now; false => null OR <= now
	 */
	public static function list_conversations( array $args = array() ): array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		list( $where, $params ) = self::build_conversation_where( $args );

		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );

		$sql = "SELECT
					c.id, c.inbox_id, c.contact_inbox_id, c.status, c.assignee_id,
					i.channel_type, i.channel_ref_id AS inbox_ref_id,
					c.platform, c.account_id, c.character_id, c.chat_id,
					c.notebook_id, c.priority,
					c.snoozed_until, c.waiting_since, c.first_reply_at, c.cached_label_list,
					c.sla_policy_id, c.team_id,
					c.last_message_id, c.last_activity_at, c.unread_count,
					c.created_at, c.updated_at,
					ci.source_id, ci.contact_id,
					ct.name AS contact_name, ct.avatar_url AS contact_avatar,
					ct.additional_attributes AS contact_attributes,
					m.content_preview AS last_message_content,
					m.message_type AS last_message_type,
					m.sender_type AS last_sender_type,
					m.created_at AS last_message_at
				FROM {$tbl_conv} c
				LEFT JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ibx} i ON i.id = c.inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m  ON m.id  = c.last_message_id
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY c.priority DESC, c.last_activity_at DESC, c.id DESC
				LIMIT %d";
		$params[] = $limit;

		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Count conversations with the exact same predicates used by list_conversations().
	 */
	public static function count_conversations( array $args = array() ): int {
		// [2026-09-08 10:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7 — add server-owned Inbox counts using the same predicates as the list query.
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		list( $where, $params ) = self::build_conversation_where( $args );
		$sql = "SELECT COUNT(*)
				FROM {$tbl_conv} c
				LEFT JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ibx} i ON i.id = c.inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m ON m.id = c.last_message_id
				WHERE " . implode( ' AND ', $where );
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		return (int) $wpdb->get_var( $prepared );
	}

	private static function build_conversation_where( array $args ): array {
		// [2026-09-08 10:33 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W7 — centralize Inbox filter semantics so list and count cannot drift.
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['id'] ) ) { $where[] = 'c.id = %d'; $params[] = (int) $args['id']; }
		if ( ! empty( $args['inbox_id'] ) ) { $where[] = 'c.inbox_id = %d'; $params[] = (int) $args['inbox_id']; }
		if ( isset( $args['inbox_ids'] ) && is_array( $args['inbox_ids'] ) ) {
			$inbox_ids = array_values( array_filter( array_map( 'absint', $args['inbox_ids'] ) ) );
			if ( empty( $inbox_ids ) ) { $where[] = '1=0'; return array( $where, $params ); }
			$where[] = 'c.inbox_id IN (' . implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $inbox_ids );
		}
		if ( ! empty( $args['status'] ) ) { $where[] = 'c.status = %s'; $params[] = (string) $args['status']; }
		if ( isset( $args['priority'] ) && $args['priority'] !== '' && $args['priority'] !== null ) {
			$pri_map = array( 'low' => 0, 'med' => 1, 'medium' => 1, 'high' => 2, 'urgent' => 3 );
			$pri_raw = $args['priority'];
			$pri_int = is_numeric( $pri_raw ) ? (int) $pri_raw : ( $pri_map[ strtolower( (string) $pri_raw ) ] ?? null );
			if ( $pri_int !== null && $pri_int >= 0 && $pri_int <= 3 ) { $where[] = 'c.priority = %d'; $params[] = $pri_int; }
		}
		if ( isset( $args['snoozed'] ) ) {
			$now_ts = time();
			if ( filter_var( $args['snoozed'], FILTER_VALIDATE_BOOLEAN ) ) { $where[] = 'c.snoozed_until IS NOT NULL AND c.snoozed_until > %d'; $params[] = $now_ts; }
			else { $where[] = '(c.snoozed_until IS NULL OR c.snoozed_until <= %d)'; $params[] = $now_ts; }
		}
		if ( ! empty( $args['assignee_id'] ) ) { $where[] = 'c.assignee_id = %d'; $params[] = (int) $args['assignee_id']; }
		if ( ! empty( $args['unassigned'] ) ) { $where[] = '(c.assignee_id IS NULL OR c.assignee_id = 0)'; }
		if ( ! empty( $args['participating_user_id'] ) ) {
			$where[] = "EXISTS (SELECT 1 FROM " . BizCity_CRM_DB_Installer_V2::tbl_messages() . " pm WHERE pm.conversation_id = c.id AND pm.responder_user_id = %d AND pm.sender_type = 'agent' AND pm.message_type = 'outgoing')";
			$params[] = (int) $args['participating_user_id'];
		}
		if ( ! empty( $args['unattended'] ) ) { $where[] = "c.status = 'open' AND m.sender_type = 'contact' AND m.message_type = 'incoming'"; }
		if ( ! empty( $args['q'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$where[] = '(ct.name LIKE %s OR ct.email LIKE %s OR ct.phone LIKE %s)';
			$params[] = $like; $params[] = $like; $params[] = $like;
		}
		if ( ! empty( $args['before_id'] ) ) { $where[] = 'c.id < %d'; $params[] = (int) $args['before_id']; }
		if ( ! empty( $args['label_id'] ) ) {
			$where[] = 'c.id IN ( SELECT conversation_id FROM ' . BizCity_CRM_DB_Installer_V2::tbl_conversation_labels() . ' WHERE label_id = %d )';
			$params[] = (int) $args['label_id'];
		}
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-LABEL-FILTER — "Chưa gắn nhãn" Inbox filter.
		if ( ! empty( $args['unlabeled'] ) ) {
			$where[] = 'c.id NOT IN ( SELECT conversation_id FROM ' . BizCity_CRM_DB_Installer_V2::tbl_conversation_labels() . ' )';
		}
		if ( ! empty( $args['contact_wp_user_id'] ) ) { $where[] = 'ct.wp_user_id = %d'; $params[] = (int) $args['contact_wp_user_id']; }
		if ( isset( $args['thread_kind'] ) && in_array( (string) $args['thread_kind'], array( 'group', 'personal' ), true ) ) {
			$where[] = 'group' === (string) $args['thread_kind'] ? "ci.source_id LIKE 'group:%'" : "ci.source_id NOT LIKE 'group:%'";
		}
		// [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-2 §4.2 — Bot Studio Sessions projection filters.
		// `conversations.account_id`/`character_id` already exist on the row (set at ingest time);
		// this just exposes them as filter predicates alongside the ones already here.
		// [2026-09-24 Claude Sonnet 5] PHASE-0.60I — the "set at ingest time" note above was WRONG: on the live
		// site every conversations.account_id is empty (bot-studio-selfcheck: account filter → 0 rows, unfiltered
		// → 50 rows with account_id ''). A thread's account is its INBOX's channel_ref_id, so fall back to it.
		if ( ! empty( $args['account_id'] ) ) { $where[] = "COALESCE( NULLIF( c.account_id, '' ), i.channel_ref_id ) = %s"; $params[] = (string) $args['account_id']; }
		if ( ! empty( $args['character_id'] ) ) { $where[] = 'c.character_id = %d'; $params[] = (int) $args['character_id']; }
		if ( ! empty( $args['external_uid'] ) ) { $where[] = 'ci.source_id = %s'; $params[] = (string) $args['external_uid']; }
		return array( $where, $params );
	}

	/**
	 * List conversations belonging to a member's canonical CRM contact.
	 *
	 * The contact user binding is an additional boundary to inbox scope; an
	 * inbox member must not gain access to every customer conversation in /gpt/.
	 */
	public static function list_conversations_for_member( int $wp_user_id, $allowed_inbox_ids = null, int $limit = 50, int $before_id = 0 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — bind member conversation reads to contact.wp_user_id plus resolver-derived inbox scope.
		if ( $wp_user_id <= 0 || ( is_array( $allowed_inbox_ids ) && empty( $allowed_inbox_ids ) ) ) { return array(); }
		return self::list_conversations( array(
			'contact_wp_user_id' => $wp_user_id,
			'inbox_ids'         => is_array( $allowed_inbox_ids ) ? $allowed_inbox_ids : null,
			'limit'             => max( 1, min( 100, $limit ) ),
			'before_id'         => max( 0, $before_id ),
		) );
	}

	/** List member-visible care tasks without exposing internal notes or tenant-wide rows. */
	public static function list_tasks_for_member( int $wp_user_id, int $limit = 50 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — expose only assigned or own-contact tasks to the C surface.
		if ( $wp_user_id <= 0 ) { return array(); }
		global $wpdb;
		// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — resolve table names through the canonical installer owner.
		$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$limit = max( 1, min( 100, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.related_entity_type, t.related_entity_id, t.updated_at FROM `{$tasks}` t WHERE t.deleted_at IS NULL AND (t.assignee_id = %d OR (t.related_entity_type = 'contact' AND EXISTS (SELECT 1 FROM `{$contacts}` c WHERE c.id = t.related_entity_id AND c.wp_user_id = %d AND c.deleted_at IS NULL))) ORDER BY t.due_date IS NULL ASC, t.due_date ASC, t.id DESC LIMIT %d", $wp_user_id, $wp_user_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private static function contact_care_cache_key( int $contact_id, array $allowed_inbox_ids, int $limit ): string {
		global $wpdb;
		return 'contact_care_' . get_current_blog_id() . '_' . md5( (string) ( $wpdb->dbname ?? '' ) ) . '_' . md5( $contact_id . ':' . implode( ',', $allowed_inbox_ids ) . ':' . $limit );
	}

	/**
	 * Drop one cached care projection after a note/task/event/label write.
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-SHEET-UNIFY — group flush is unsupported on some object caches, which left the C rail showing stale labels.
	 */
	public static function forget_contact_care_projection( int $contact_id, array $allowed_inbox_ids, int $limit = 30 ): void {
		if ( $contact_id <= 0 || ! class_exists( 'BizCity_Cache' ) ) { return; }
		$allowed_inbox_ids = array_values( array_unique( array_filter( array_map( 'absint', $allowed_inbox_ids ) ) ) );
		if ( empty( $allowed_inbox_ids ) ) { return; }
		BizCity_Cache::delete( 'crm_repository', self::contact_care_cache_key( $contact_id, $allowed_inbox_ids, max( 1, min( 100, $limit ) ) ) );
	}

	/** Return contact-scoped care projections after Inbox scope is resolved. */
	public static function get_contact_care_projection( int $contact_id, array $allowed_inbox_ids, int $limit = 30 ): array {
		// [2026-09-10 03:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CARE — keep notes/tasks/labels contact-scoped in the canonical CRM repository.
		if ( $contact_id <= 0 || empty( $allowed_inbox_ids ) ) { return array( 'notes' => array(), 'tasks' => array(), 'labels' => array() ); }
		global $wpdb;
		$allowed_inbox_ids = array_values( array_unique( array_filter( array_map( 'absint', $allowed_inbox_ids ) ) ) );
		if ( empty( $allowed_inbox_ids ) ) { return array( 'notes' => array(), 'tasks' => array(), 'labels' => array() ); }
		$limit = max( 1, min( 100, $limit ) );
		$cache_key = self::contact_care_cache_key( $contact_id, $allowed_inbox_ids, $limit );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_repository', $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		$placeholders = implode( ',', array_fill( 0, count( $allowed_inbox_ids ), '%d' ) );
		$params = array_merge( array( $contact_id ), $allowed_inbox_ids, array( $limit ) );
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$contact_inboxes = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$notes_sql = "SELECT m.id, m.conversation_id, m.content, m.created_at, m.responder_user_id
			FROM `{$messages}` m
			JOIN `{$conversations}` c ON c.id = m.conversation_id
			JOIN `{$contact_inboxes}` ci ON ci.id = c.contact_inbox_id
			WHERE ci.contact_id = %d AND ci.inbox_id IN ({$placeholders})
				AND m.message_type = 'private_note'
			ORDER BY m.id DESC LIMIT %d";
		$notes = $wpdb->get_results( $wpdb->prepare( $notes_sql, $params ), ARRAY_A );
		$notes = self::hydrate_messages( is_array( $notes ) ? $notes : array() );

		$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$task_sql = "SELECT id, title, status, priority, due_date, assignee_id, related_entity_type, related_entity_id, notes, completed, completed_at, created_at, updated_at
			FROM `{$tasks}` WHERE deleted_at IS NULL AND related_entity_type = 'contact' AND related_entity_id = %d
				AND EXISTS ( SELECT 1 FROM `{$contact_inboxes}` scoped_ci WHERE scoped_ci.contact_id = related_entity_id AND scoped_ci.inbox_id IN ({$placeholders}) )
			ORDER BY due_date IS NULL ASC, due_date ASC, id DESC LIMIT %d";
		$task_rows = $wpdb->get_results( $wpdb->prepare( $task_sql, array_merge( array( $contact_id ), $allowed_inbox_ids, array( $limit ) ) ), ARRAY_A );

		$labels = array();
		$label_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT l.id, l.title, l.description, l.color, l.show_on_sidebar
			 FROM " . BizCity_CRM_DB_Installer_V2::tbl_conversation_labels() . " cl
			 JOIN " . BizCity_CRM_DB_Installer_V2::tbl_labels() . " l ON l.id = cl.label_id
			 JOIN {$conversations} c ON c.id = cl.conversation_id
			 JOIN {$contact_inboxes} ci ON ci.id = c.contact_inbox_id
			 WHERE ci.contact_id = %d AND ci.inbox_id IN ({$placeholders})
			 ORDER BY l.title ASC LIMIT %d",
			array_merge( array( $contact_id ), $allowed_inbox_ids, array( $limit ) )
		), ARRAY_A );
		$labels = is_array( $label_rows ) ? $label_rows : array();
		$result = array( 'notes' => is_array( $notes ) ? $notes : array(), 'tasks' => is_array( $task_rows ) ? $task_rows : array(), 'labels' => $labels );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( 'crm_repository', $cache_key, $result, BizCity_Cache::TTL_SHORT ); }
		return $result;
	}

	/** Return canonical contacts linked to a WordPress user for order projection. */
	public static function list_contacts_for_wp_user( int $wp_user_id, int $limit = 20 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — resolve order subjects from tenant CRM contacts, never posted contact IDs.
		if ( $wp_user_id <= 0 ) { return array(); }
		global $wpdb;
		// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — resolve contact table through the canonical installer owner.
		$table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$limit = max( 1, min( 20, $limit ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE wp_user_id = %d AND deleted_at IS NULL ORDER BY id DESC LIMIT %d", $wp_user_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return CRM contacts and their raw server-side membership rows for an
	 * already-authorized Inbox scope. Public callers must use the CRM access
	 * projection, which replaces provider identifiers with opaque keys.
	 *
	 * @param int[]|null $allowed_inbox_ids Null means tenant-wide B2 scope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_contacts_for_inbox_scope( $allowed_inbox_ids, int $limit = 100 ): array {
		// [2026-09-08 02:09 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX1 — derive unified Contacts from canonical contact_inboxes without creating a second store.
		if ( is_array( $allowed_inbox_ids ) ) {
			$allowed_inbox_ids = array_values( array_unique( array_filter( array_map( 'absint', $allowed_inbox_ids ) ) ) );
			if ( empty( $allowed_inbox_ids ) ) { return array(); }
		} elseif ( null !== $allowed_inbox_ids ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$database = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$scope_key = null === $allowed_inbox_ids ? 'all' : implode( ',', $allowed_inbox_ids );
		$cache_key = 'contacts_scope_' . $blog_id . '_' . md5( $database ) . '_' . md5( $scope_key ) . '_' . $limit;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_repository', $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}

		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$contact_inboxes = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$scope_sql = '';
		$scope_params = array();
		if ( is_array( $allowed_inbox_ids ) ) {
			$scope_sql = ' AND ci.inbox_id IN (' . implode( ',', array_fill( 0, count( $allowed_inbox_ids ), '%d' ) ) . ')';
			$scope_params = $allowed_inbox_ids;
		}
		$contact_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT c.id, c.name, c.first_name, c.last_name, c.updated_at
			 FROM `{$contacts}` c
			 JOIN `{$contact_inboxes}` ci ON ci.contact_id = c.id
			 JOIN `{$inboxes}` i ON i.id = ci.inbox_id AND i.is_active = 1
			 WHERE c.deleted_at IS NULL{$scope_sql}
			 ORDER BY c.updated_at DESC, c.id DESC LIMIT %d",
			array_merge( $scope_params, array( $limit ) )
		), ARRAY_A );
		if ( empty( $contact_rows ) ) {
			if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( 'crm_repository', $cache_key, array(), BizCity_Cache::TTL_SHORT ); }
			return array();
		}

		$contact_ids = array_values( array_map( 'intval', wp_list_pluck( $contact_rows, 'id' ) ) );
		$contact_placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$membership_where = "ci.contact_id IN ({$contact_placeholders})";
		$membership_params = $contact_ids;
		if ( is_array( $allowed_inbox_ids ) ) {
			$membership_where .= ' AND ci.inbox_id IN (' . implode( ',', array_fill( 0, count( $allowed_inbox_ids ), '%d' ) ) . ')';
			$membership_params = array_merge( $membership_params, $allowed_inbox_ids );
		}
		$membership_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ci.contact_id, ci.id AS contact_inbox_id, ci.inbox_id, ci.source_id,
				i.channel_type, i.channel_ref_id,
				cv.id AS conversation_id, cv.status AS conversation_status, cv.last_activity_at
			 FROM `{$contact_inboxes}` ci
			 JOIN `{$inboxes}` i ON i.id = ci.inbox_id AND i.is_active = 1
			 LEFT JOIN `{$conversations}` cv ON cv.contact_inbox_id = ci.id
			 WHERE {$membership_where}
			 ORDER BY ci.contact_id ASC, ci.id ASC, cv.last_activity_at DESC, cv.id DESC",
			$membership_params
		), ARRAY_A );

		$memberships = array();
		$seen = array();
		foreach ( is_array( $membership_rows ) ? $membership_rows : array() as $membership ) {
			$contact_inbox_id = (int) ( $membership['contact_inbox_id'] ?? 0 );
			if ( $contact_inbox_id <= 0 || isset( $seen[ $contact_inbox_id ] ) ) { continue; }
			$seen[ $contact_inbox_id ] = true;
			$contact_id = (int) ( $membership['contact_id'] ?? 0 );
			$memberships[ $contact_id ][] = $membership;
		}
		$result = array();
		foreach ( $contact_rows as $contact ) {
			$contact_id = (int) ( $contact['id'] ?? 0 );
			$name = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
			if ( '' === $name ) { $name = trim( (string) ( $contact['name'] ?? '' ) ); }
			$result[] = array(
				'contact_id' => $contact_id,
				'display_name' => $name,
				'updated_at' => $contact['updated_at'] ?? null,
				'memberships' => array_values( $memberships[ $contact_id ] ?? array() ),
			);
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( 'crm_repository', $cache_key, $result, BizCity_Cache::TTL_SHORT );
		}
		return $result;
	}

	public static function can_user_move_order_care( string $object_type, int $object_id, int $user_id = 0 ): bool {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S3 — enforce task/opportunity ownership at the repository boundary.
		$user_id = $user_id > 0 ? $user_id : (int) get_current_user_id();
		if ( $object_id <= 0 || $user_id <= 0 ) { return false; }
		if ( user_can( $user_id, 'manage_options' ) ) { return true; }
		global $wpdb;
		if ( 'task' === $object_type ) {
			// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — keep order-care authorization on the canonical table-name owner.
			$tasks = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
			$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT assignee_id, related_entity_type, related_entity_id FROM `{$tasks}` WHERE id = %d AND deleted_at IS NULL LIMIT 1", $object_id ), ARRAY_A );
			if ( ! is_array( $row ) ) { return false; }
			if ( (int) ( $row['assignee_id'] ?? 0 ) === $user_id ) { return true; }
			return 'contact' === (string) ( $row['related_entity_type'] ?? '' ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$contacts}` WHERE id = %d AND wp_user_id = %d AND deleted_at IS NULL LIMIT 1", (int) $row['related_entity_id'], $user_id ) );
		}
		if ( 'opportunity' === $object_type ) {
			// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — resolve opportunity table through the canonical installer owner.
			$opportunities = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$opportunities}` WHERE id = %d AND owner_id = %d AND deleted_at IS NULL LIMIT 1", $object_id, $user_id ) );
		}
		return false;
	}

	public static function move_order_care_object( string $object_type, int $object_id, string $target_state, array $changes = array(), int $actor_user_id = 0 ): array {
		// [2026-08-25 Johnny Chu] PHASE-0.39F-S3 — own task/opportunity board state mutation and return repeat-safe current-state outcomes.
		$object_type = sanitize_key( $object_type );
		$target_state = sanitize_key( $target_state );
		$actor_user_id = $actor_user_id > 0 ? $actor_user_id : (int) get_current_user_id();
		if ( ! in_array( $object_type, array( 'task', 'opportunity' ), true ) || $object_id <= 0 ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'invalid_order_care_object', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		if ( ! self::can_user_move_order_care( $object_type, $object_id, $actor_user_id ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'order_care_scope_denied', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		global $wpdb;
		// [2026-09-01 Johnny Chu] PHASE-0.39F-F8-DDV — use canonical table helpers for order-care mutation after authorization.
		$table = 'task' === $object_type ? BizCity_CRM_DB_Installer_V2::tbl_crm_tasks() : BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND deleted_at IS NULL LIMIT 1", $object_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'order_care_object_not_found', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		$allowed = 'task' === $object_type
			? array( 'open', 'pending', 'in_progress', 'done', 'cancelled' )
			: array( 'prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost' );
		if ( ! in_array( $target_state, $allowed, true ) ) {
			return array( 'success' => false, 'outcome' => 'permanent_failed', 'code' => 'invalid_order_care_state', 'retryable' => false, 'contract_version' => '1.0.0' );
		}
		$current_state = 'task' === $object_type ? sanitize_key( (string) ( $row['status'] ?? '' ) ) : sanitize_key( (string) ( $row['stage'] ?? '' ) );
		if ( $current_state === $target_state ) {
			return array( 'success' => true, 'outcome' => 'already_applied', 'code' => 'already_applied', 'retryable' => false, 'contract_version' => '1.0.0', 'object_type' => $object_type, 'object_id' => $object_id, 'target_state' => $target_state );
		}
		$fields = array( 'updated_at' => current_time( 'mysql' ) );
		if ( 'task' === $object_type ) {
			$fields['status'] = $target_state;
			$fields['completed'] = 'done' === $target_state ? 1 : 0;
			$fields['completed_at'] = 'done' === $target_state ? current_time( 'mysql' ) : null;
			if ( isset( $changes['priority'] ) && in_array( (string) $changes['priority'], array( 'low', 'medium', 'high', 'urgent' ), true ) ) { $fields['priority'] = sanitize_key( (string) $changes['priority'] ); }
			if ( array_key_exists( 'due_date', $changes ) ) { $fields['due_date'] = $changes['due_date'] ? sanitize_text_field( (string) $changes['due_date'] ) : null; }
		} else {
			$fields['stage'] = $target_state;
		}
		if ( false === $wpdb->update( $table, $fields, array( 'id' => $object_id ) ) ) {
			return array( 'success' => false, 'outcome' => 'retryable', 'code' => 'order_care_move_failed', 'retryable' => true, 'contract_version' => '1.0.0' );
		}
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_order_care_moved', array( 'object_type' => $object_type, 'object_id' => $object_id, 'from_state' => $current_state, 'to_state' => $target_state, 'by_user_id' => $actor_user_id ) );
		}
		if ( class_exists( 'BizCity_Cache_Registry' ) ) { BizCity_Cache_Registry::flush_module( 'modules.twin-crm' ); }
		return array( 'success' => true, 'outcome' => 'updated', 'code' => 'moved', 'retryable' => false, 'contract_version' => '1.0.0', 'object_type' => $object_type, 'object_id' => $object_id, 'from_state' => $current_state, 'target_state' => $target_state );
	}

	/**
	 * Set / clear snoozed_until on a conversation.
	 * Pass $until_ts = 0 to unsnooze. Emits crm_conversation_snoozed / unsnoozed.
	 */
	public static function set_snooze( int $conv_id, int $until_ts, int $by_user_id = 0 ): bool {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) { return false; }
		$value = $until_ts > 0 ? $until_ts : null;
		$ok = (bool) $wpdb->update(
			$tbl,
			array(
				'snoozed_until' => $value,
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $conv_id ),
			array( $value === null ? '%s' : '%d', '%s' ),
			array( '%d' )
		);
		if ( $ok ) {
			$event = $until_ts > 0 ? 'crm_conversation_snoozed' : 'crm_conversation_unsnoozed';
			BizCity_CRM_Event_Emitter::emit( $event, array(
				'conversation_id' => $conv_id,
				'snoozed_until'   => $until_ts > 0 ? $until_ts : null,
				'by_user_id'      => $by_user_id ?: get_current_user_id(),
			) );
		}
		return $ok;
	}

	public static function set_conversation_status( int $conv_id, string $status, int $by_user_id = 0 ): bool {
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) {
			return false;
		}
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — retain the previous status for causal events.
		$previous_status = (string) ( $prev['status'] ?? '' );
		$ok = (bool) $wpdb->update( $tbl, array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $conv_id ) );

		if ( $ok && $previous_status !== $status ) {
			$actor_id = $by_user_id ?: get_current_user_id();
			BizCity_CRM_Event_Emitter::emit( 'crm_status_changed', array(
				'conversation_id' => $conv_id,
				'from_status'     => $previous_status,
				'to_status'       => $status,
				'by_user_id'      => $actor_id,
			) );
		}
		if ( $ok ) { self::invalidate_read_models(); }
		if ( $ok && $status === 'resolved' && $previous_status !== 'resolved' ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_resolved', array(
				'conversation_id' => $conv_id,
				'by_user_id'      => $by_user_id ?: get_current_user_id(),
			) );
		}
		if ( $ok && $status === 'open' && $previous_status === 'resolved' ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_reopened', array(
				'conversation_id' => $conv_id,
				'by_user_id'      => $by_user_id ?: get_current_user_id(),
			) );
		}
		return $ok;
	}

	// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — persist assignment through the CRM write gate.
	public static function set_conversation_assignee( int $conv_id, ?int $assignee_id, int $by_user_id = 0, array $event_context = array(), bool $emit_event = true ): bool {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — emit assignment only after a real state change.
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) { return false; }
		$previous_id = ! empty( $prev['assignee_id'] ) ? (int) $prev['assignee_id'] : null;
		$next_id     = $assignee_id && $assignee_id > 0 ? (int) $assignee_id : null;
		if ( $previous_id === $next_id ) { return true; }
		$ok = (bool) $wpdb->update(
			$tbl,
			array( 'assignee_id' => $next_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conv_id ),
			array( $next_id === null ? '%s' : '%d', '%s' ),
			array( '%d' )
		);
		if ( $ok && $emit_event ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_assigned', array_merge( array(
				'conversation_id'      => $conv_id,
				'previous_assignee_id' => $previous_id,
				'assignee_id'          => $next_id,
				'by_user_id'           => $by_user_id ?: get_current_user_id(),
			), array(
				'team_id'   => ! empty( $event_context['team_id'] ) ? (int) $event_context['team_id'] : null,
				'policy_id' => ! empty( $event_context['policy_id'] ) ? (int) $event_context['policy_id'] : null,
				'reason'    => sanitize_key( (string) ( $event_context['reason'] ?? 'manual' ) ),
			) ) );
		}
		return $ok;
	}

	public static function set_conversation_team( int $conv_id, ?int $team_id, int $by_user_id = 0, bool $emit_event = true ): bool {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F4 — persist team ownership through the CRM repository and event contract.
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev ) { return false; }
		$previous_id = ! empty( $prev['team_id'] ) ? (int) $prev['team_id'] : null;
		$next_id = $team_id && $team_id > 0 ? (int) $team_id : null;
		if ( $previous_id === $next_id ) { return true; }
		$ok = false !== $wpdb->update( $tbl, array( 'team_id' => $next_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $conv_id ), array( $next_id === null ? '%s' : '%d', '%s' ), array( '%d' ) );
		if ( $ok && $emit_event && class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_team_changed', array( 'conversation_id' => $conv_id, 'previous_team_id' => $previous_id, 'team_id' => $next_id, 'by_user_id' => $by_user_id ?: get_current_user_id() ) );
		}
		return $ok;
	}

	// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — persist priority through the CRM write gate.
	public static function set_conversation_priority( int $conv_id, int $priority, int $by_user_id = 0 ): bool {
		// [2026-08-04 Johnny Chu] PHASE-0.48-H2 — emit priority only after a real state change.
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$prev = self::get_conversation( $conv_id );
		if ( ! $prev || $priority < 0 || $priority > 3 ) { return false; }
		$previous_priority = (int) ( $prev['priority'] ?? 0 );
		if ( $previous_priority === $priority ) { return true; }
		$ok = (bool) $wpdb->update(
			$tbl,
			array( 'priority' => $priority, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $conv_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		if ( $ok ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_conversation_priority_changed', array(
				'conversation_id'   => $conv_id,
				'previous_priority' => $previous_priority,
				'priority'          => $priority,
				'by_user_id'        => $by_user_id ?: get_current_user_id(),
			) );
		}
		return $ok;
	}

	/* ============================================================
	 * MESSAGE
	 * ============================================================ */

	/**
	 * Insert message (idempotent on inbox_id + external_source_id).
	 *
	 * @return int message_id (0 on dedup-skip or failure)
	 */
	public static function insert_message( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();

		// Required.
		$conv_id  = (int) ( $data['conversation_id'] ?? 0 );
		$inbox_id = (int) ( $data['inbox_id']        ?? 0 );
		if ( ! $conv_id || ! $inbox_id ) {
			return 0;
		}
		$msg_type = (string) ( $data['message_type'] ?? 'incoming' );
		if ( in_array( $msg_type, array( 'incoming', 'outgoing' ), true ) ) {
			if ( ! class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
				return 0;
			}
			$inbox = self::get_inbox( $inbox_id );
			if ( ! is_array( $inbox ) ) {
				return 0;
			}
			$descriptor = BizCity_CRM_Channel_Contract::require_crm_enabled( (string) ( $inbox['channel_type'] ?? '' ) );
			if ( is_wp_error( $descriptor ) ) {
				// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - block every direct incoming/outgoing writer, including automation and campaign projections, before SQL.
				return 0;
			}
		}

		// Idempotency check.
		$ext = (string) ( $data['external_source_id'] ?? '' );
		if ( $ext !== '' ) {
			$dup = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE inbox_id = %d AND external_source_id = %s LIMIT 1",
				$inbox_id, $ext
			) );
			if ( $dup ) {
				return 0; // already ingested
			}
		}

		$now      = current_time( 'mysql' );
		$sender   = (string) ( $data['sender_type']  ?? 'contact' );
		$ai_meta  = isset( $data['ai_metadata'] ) && is_array( $data['ai_metadata'] )
			? wp_json_encode( $data['ai_metadata'] ) : null;
		$attachments = isset( $data['attachments'] ) && is_array( $data['attachments'] ) ? $data['attachments'] : array();
		$preview = self::make_content_preview(
			(string) ( $data['content'] ?? '' ),
			(string) ( $data['content_type'] ?? 'text' ),
			$attachments
		);

		$row = array(
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'external_source_id' => $ext !== '' ? $ext : null,
			'content'            => (string) ( $data['content'] ?? '' ),
			'content_preview'    => $preview !== '' ? $preview : null,
			'content_type'       => (string) ( $data['content_type'] ?? 'text' ),
			'message_type'       => $msg_type,
			'sender_type'        => $sender,
			'sender_id'          => isset( $data['sender_id'] ) ? (int) $data['sender_id'] : null,
			'status'             => (string) ( $data['status'] ?? 'sent' ),
			'ai_metadata_json'   => $ai_meta,
			'event_uuid'         => $data['event_uuid'] ?? null,
			'responder_kind'     => isset( $data['responder_kind'] ) ? (string) $data['responder_kind'] : null,
			'responder_user_id'  => isset( $data['responder_user_id'] ) ? (int) $data['responder_user_id'] : null,
			'character_id'       => isset( $data['character_id'] ) ? (int) $data['character_id'] : null,
			'created_at'         => $data['created_at'] ?? $now,
		);

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			self::log_insert_failure( $row, (string) $wpdb->last_error );
			return 0;
		}
		$msg_id = (int) $wpdb->insert_id;

		// [2026-09-23 04:00 PM Claude Fable 5.1] PHASE-0.60A/0.60D Q-D2 — the ONE insertion point every message passes
		// through (see the PHASE-0.56 D-1 note below). Outgoing rows written by the outbound dispatcher never reach
		// `bizcity_crm_message_persisted` (ingestor-only), so this generic mark is what lets Bot Studio arm the
		// "staff replied by hand → pause" window and lets automation run "after the bot replied" without polling.
		do_action( 'bizcity_crm_message_inserted', $msg_id, $row );

		// Insert attachments if any.
		if ( ! empty( $data['attachments'] ) && is_array( $data['attachments'] ) ) {
			$att_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
			foreach ( $data['attachments'] as $att ) {
				$wpdb->insert( $att_tbl, array(
					'message_id' => $msg_id,
					'file_type'  => (string) ( $att['file_type'] ?? 'file' ),
					'data_url'   => (string) ( $att['data_url']  ?? '' ),
					'thumb_url'  => $att['thumb_url'] ?? null,
					'meta_json'  => isset( $att['meta'] ) ? wp_json_encode( $att['meta'] ) : null,
					'created_at' => $now,
				) );
			}
		}

		// Denormalize on conversation.
		// [2026-09-19 Johnny Chu] PHASE-0.56 D-1 — `waiting_since`/`first_reply_at`/`unread_count`
		// verified (0.48F class-staff-rest.php comment, re-verified here) to have NO writer anywhere
		// in this plugin before this change; every reader of them silently saw NULL/0 forever. This is
		// the one insertion point every message (customer or staff) already passes through, so it is
		// the only place these three columns need a writer. Rules, matching the project's existing
		// "AI doesn't count as a human answering" convention (0.48F/0.52 reply-rate/FRT already exclude
		// AI): an incoming (customer) message starts the wait clock only if it isn't already running —
		// a burst of customer messages keeps the ORIGINAL wait start, not the latest one; only a
		// `responder_kind==='manual'` outgoing message (a human, not the AI replier) stops the wait
		// clock, records the first-reply timestamp (once) and clears the unread badge. An AI-only
		// reply leaves `waiting_since` running on purpose — a human still hasn't answered.
		$conv_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		if ( 'incoming' === $msg_type ) {
			// [2026-09-22 11:30 AM OpenAI GPT-5.6 Luna] HOTFIX — waiting_since is a BIGINT epoch on the CRM schema, not a DATETIME string.
			$wpdb->query( $wpdb->prepare(
				"UPDATE `{$conv_tbl}` SET last_message_id = %d, last_activity_at = %s, updated_at = %s,
					waiting_since = COALESCE(waiting_since, %s), unread_count = unread_count + 1
				 WHERE id = %d",
				$msg_id, $row['created_at'], $now, (int) strtotime( (string) $row['created_at'] ), $conv_id
			) );
		} elseif ( 'outgoing' === $msg_type && 'manual' === (string) ( $data['responder_kind'] ?? '' ) ) {
			// PHASE-0.56 D-5 — read the pre-update row first: need to know whether `first_reply_at`
			// was still NULL (this is the actual first reply, not a later one) before this same
			// query sets it.
			$prior = $wpdb->get_row( $wpdb->prepare( "SELECT created_at, first_reply_at, assignee_id FROM `{$conv_tbl}` WHERE id = %d", $conv_id ), ARRAY_A );
			$wpdb->query( $wpdb->prepare(
				"UPDATE `{$conv_tbl}` SET last_message_id = %d, last_activity_at = %s, updated_at = %s,
					waiting_since = NULL, first_reply_at = COALESCE(first_reply_at, %s), unread_count = 0
				 WHERE id = %d",
				$msg_id, $row['created_at'], $now, $row['created_at'], $conv_id
			) );
			// PHASE-0.56 D-5 — `conv_handled`/`conv_first_reply` rollup facts, gated the same way
			// `fetch_reply_aggregates()` already computes "replied by X" live (documented known
			// simplification there: `responder_user_id = c.assignee_id` at read time) — so switching
			// the dashboard from that live query to these rollups later (D-7) does not shift the
			// numbers. `record_fact()` dedupes on its seed, so replays/retries never double-count.
			$responder_user_id = isset( $data['responder_user_id'] ) ? (int) $data['responder_user_id'] : 0;
			if ( $responder_user_id > 0 && is_array( $prior ) && (int) ( $prior['assignee_id'] ?? 0 ) === $responder_user_id && class_exists( 'BizCity_CRM_Reporting_Rollup' ) ) {
				$day = substr( $row['created_at'], 0, 10 );
				BizCity_CRM_Reporting_Rollup::record_fact( 'conv_handled', $responder_user_id, $row['created_at'], 1, 'conv_handled|' . $conv_id . '|' . $responder_user_id . '|' . $day, $conv_id );
				if ( null === ( $prior['first_reply_at'] ?? null ) && ! empty( $prior['created_at'] ) ) {
					$frt_seconds = max( 0, strtotime( $row['created_at'] ) - strtotime( (string) $prior['created_at'] ) );
					BizCity_CRM_Reporting_Rollup::record_fact( 'conv_first_reply', $responder_user_id, $row['created_at'], (float) $frt_seconds, 'conv_first_reply|' . $conv_id, $conv_id );
				}
			}
		} else {
			$wpdb->update(
				$conv_tbl,
				array(
					'last_message_id'  => $msg_id,
					'last_activity_at' => $row['created_at'],
					'updated_at'       => $now,
				),
				array( 'id' => $conv_id )
			);
		}
		self::invalidate_read_models();

		// Emit appropriate event.
		$event_type = $msg_type === 'outgoing' ? 'crm_message_sent' : 'crm_message_received';
		// [2026-08-24 Johnny Chu] PHASE-0.39E-D1 — expose the bounded upstream trace on the canonical CRM event.
		$event_uuid = BizCity_CRM_Event_Emitter::emit( $event_type, array(
			'message_id'         => $msg_id,
			'conversation_id'    => $conv_id,
			'inbox_id'           => $inbox_id,
			'sender_type'        => $sender,
			'content_type'       => $row['content_type'],
			'external_source_id' => $row['external_source_id'],
			'has_ai_metadata'    => $ai_meta ? true : false,
			'trace_id'           => substr( sanitize_text_field( (string) ( $data['trace_id'] ?? '' ) ), 0, 128 ),
		), $data['parent_event_uuid'] ?? null );

		// Backfill event_uuid on the message row (we don't know it until emit).
		if ( ! $row['event_uuid'] ) {
			$wpdb->update( $tbl, array( 'event_uuid' => $event_uuid ), array( 'id' => $msg_id ) );
		}

		return $msg_id;
	}

	public static function get_message( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) { return null; }
		$hydrated = self::hydrate_messages( array( $row ) );
		return $hydrated[0] ?? $row;
	}

	public static function mark_message_archived( int $message_id, string $channel, array $entry, string $key ): bool {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F2 — mark SQL content archive-ready only after the encrypted file and receipt exist.
		if ( $message_id <= 0 ) { return false; }
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$account_key = (string) ( $entry['account_key'] ?? '' );
		$peer_key = (string) ( $entry['peer_key'] ?? '' );
		$month = gmdate( 'Y-m' );
		$receipt_table = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		$receipt_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT line_hash FROM `{$receipt_table}` WHERE crm_message_id = %d AND archive_status = %s LIMIT 1", $message_id, 'written' ) );
		if ( $receipt_hash === '' ) {
			return false;
		}
		return false !== $wpdb->update( $table, array(
			'content_storage_state' => 'archived',
			'archive_channel'       => sanitize_key( $channel ),
			'archive_account_key'   => $account_key,
			'archive_peer_key'      => $peer_key,
			'archive_month'         => $month,
			'archive_receipt_hash'  => $receipt_hash,
			'archived_at'           => current_time( 'mysql' ),
			'storage_error_code'    => null,
		), array( 'id' => $message_id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
	}

	/** Save a bounded, classified outbound delivery result in payload_json. */
	public static function update_message_delivery( int $message_id, array $result ): bool {
		// [2026-08-04 Johnny Chu] PHASE-0.48-INBOX-ERROR-UX — preserve provider reason for failed-send tooltip without new columns.
		if ( $message_id <= 0 ) { return false; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = self::get_message( $message_id );
		if ( ! $row ) { return false; }
		$payload = ! empty( $row['payload_json'] ) ? json_decode( (string) $row['payload_json'], true ) : array();
		if ( ! is_array( $payload ) ) { $payload = array(); }
		$error = (string) ( $result['error'] ?? '' );
		$error = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $error ) : strip_tags( $error );
		if ( function_exists( 'mb_substr' ) ) {
			$error = mb_substr( $error, 0, 500 );
		} else {
			$error = substr( $error, 0, 500 );
		}
		$lower = strtolower( $error );
		$reason_code = 'provider_error';
		if ( preg_match( '/24\s*[- ]?hour|outside.{0,20}window|messaging.{0,20}window|customer.{0,20}initiated/i', $lower ) ) {
			$reason_code = 'outside_24h_window';
		} elseif ( strpos( $lower, 'permission' ) !== false || strpos( $lower, 'not authorized' ) !== false || strpos( $lower, '(#10)' ) !== false ) {
			$reason_code = 'permission_denied';
		} elseif ( strpos( $lower, 'token' ) !== false || strpos( $lower, 'oauth' ) !== false || strpos( $lower, '(#190)' ) !== false ) {
			$reason_code = 'token_invalid';
		} elseif ( strpos( $lower, 'rate' ) !== false || strpos( $lower, 'throttl' ) !== false ) {
			$reason_code = 'rate_limited';
		} elseif ( $error === '' ) {
			$reason_code = 'unknown';
		}
		$delivery_outcome = sanitize_key( (string) ( $result['outcome'] ?? ( ! empty( $result['sent'] ) ? 'sent' : 'failed' ) ) );
		if ( ! in_array( $delivery_outcome, array( 'queued', 'accepted', 'sent', 'delivered', 'failed' ), true ) ) {
			$delivery_outcome = ! empty( $result['sent'] ) ? 'sent' : 'failed';
		}
		$payload['delivery'] = array(
			'sent'        => in_array( $delivery_outcome, array( 'sent', 'delivered' ), true ),
			'outcome'     => $delivery_outcome,
			'platform'    => (string) ( $result['platform'] ?? '' ),
			'error'       => $error,
			'reason_code' => $reason_code,
			'updated_at'  => current_time( 'mysql' ),
		);
		// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — keep delivery status mutation behind the CRM repository write gate.
		$updated = false !== $wpdb->update(
			$tbl,
			array(
				'payload_json' => wp_json_encode( $payload ),
				'status'       => in_array( $delivery_outcome, array( 'sent', 'delivered' ), true )
					? 'sent'
					: ( in_array( $delivery_outcome, array( 'queued', 'accepted' ), true ) ? 'queued' : 'failed' ),
			),
			array( 'id' => $message_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( $updated ) {
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — delivery state changes must wake Inbox browsers (this path does not invalidate read models).
			self::queue_change_signal();
			// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — emit delivery lifecycle after SQL persistence for archive and analytics.
			$event_uuid = class_exists( 'BizCity_CRM_Event_Emitter' )
				? BizCity_CRM_Event_Emitter::emit( 'crm_message_delivery_updated', array(
					'message_id'  => $message_id,
					'sent'        => in_array( $delivery_outcome, array( 'sent', 'delivered' ), true ),
					'outcome'     => $delivery_outcome,
					'platform'    => (string) ( $result['platform'] ?? '' ),
					'reason_code' => $reason_code,
				) )
				: wp_generate_uuid4();
			do_action( 'bizcity_crm_message_delivery_updated', array(
				'message_id' => $message_id,
				'event_uuid' => $event_uuid,
				'delivery'   => $payload['delivery'],
			) );
		}
		return $updated;
	}

	/**
	 * Bounded CRM-local freshness counters for one inbox.
	 *
	 * PHASE-0.41D §6.2 (D6). The C console must be able to say how old its CRM
	 * snapshot is without pretending that a second SQL read proves provider or
	 * bridge synchronisation. This reader therefore returns CRM facts only;
	 * provider/bridge/session state stays with its own readiness owner.
	 *
	 * @param int $inbox_id CRM inbox id.
	 * @return array{last_inbound_at:string,last_outbound_at:string,queued_outbound:int,message_count:int}
	 */
	public static function get_inbox_message_freshness( int $inbox_id ): array {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D6 — CRM-local freshness only; never presented as provider/bridge health.
		$empty = array( 'last_inbound_at' => '', 'last_outbound_at' => '', 'queued_outbound' => 0, 'message_count' => 0 );
		if ( $inbox_id <= 0 ) {
			return $empty;
		}
		$cache_key = 'inbox_freshness_' . $inbox_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( 'crm_repository', $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				MAX( CASE WHEN message_type = 'incoming' THEN created_at END ) AS last_inbound_at,
				MAX( CASE WHEN message_type = 'outgoing' THEN created_at END ) AS last_outbound_at,
				SUM( CASE WHEN message_type = 'outgoing' AND status = 'queued' THEN 1 ELSE 0 END ) AS queued_outbound,
				COUNT(*) AS message_count
			FROM {$tbl} WHERE inbox_id = %d",
			$inbox_id
		), ARRAY_A );
		$result = array(
			'last_inbound_at'  => is_array( $row ) ? (string) ( $row['last_inbound_at'] ?? '' ) : '',
			'last_outbound_at' => is_array( $row ) ? (string) ( $row['last_outbound_at'] ?? '' ) : '',
			'queued_outbound'  => is_array( $row ) ? (int) ( $row['queued_outbound'] ?? 0 ) : 0,
			'message_count'    => is_array( $row ) ? (int) ( $row['message_count'] ?? 0 ) : 0,
		);
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( 'crm_repository', $cache_key, $result, BizCity_Cache::TTL_SHORT );
		}
		return $result;
	}

	/**
	 * Persist a provider identifier on an outbound message exactly once.
	 *
	 * PHASE-0.41D §5 (D5). A delivery callback may supply the provider message
	 * id after the CRM row already exists. The column is write-once: an
	 * existing identifier is never overwritten, so a late or duplicated
	 * callback cannot rewrite delivery provenance.
	 *
	 * @param int    $message_id         CRM message id.
	 * @param string $external_source_id Provider message identifier.
	 * @return bool True when the identifier was stored by this call.
	 */
	/**
	 * Attach the AI trace (`ai_metadata_json`) to an outgoing message another owner already inserted.
	 *
	 * [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H7 — Bot Studio sends through the Outbound Dispatcher, which
	 * does not carry `ai_metadata`, so its replies showed no "Thinking" timeline in the Inbox. Only outgoing
	 * rows may be annotated; an incoming customer message is never rewritten.
	 */
	public static function set_message_ai_metadata( int $message_id, array $ai_metadata ): bool {
		if ( $message_id <= 0 || empty( $ai_metadata ) ) {
			return false;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET ai_metadata_json = %s WHERE id = %d AND message_type = 'outgoing'",
			wp_json_encode( $ai_metadata ),
			$message_id
		) );
		if ( $updated ) {
			self::invalidate_read_models();
		}
		return (bool) $updated;
	}

	public static function set_message_external_source_id( int $message_id, string $external_source_id ): bool {
		// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D5 — write-once provider id so callback replay cannot rewrite provenance.
		$external_source_id = trim( $external_source_id );
		if ( $message_id <= 0 || '' === $external_source_id ) {
			return false;
		}
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = self::get_message( $message_id );
		if ( ! $row || '' !== (string) ( $row['external_source_id'] ?? '' ) ) {
			return false;
		}
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET external_source_id = %s WHERE id = %d AND ( external_source_id IS NULL OR external_source_id = '' )",
			$external_source_id,
			$message_id
		) );
		if ( $updated ) {
			self::invalidate_read_models();
		}
		return (bool) $updated;
	}

	/**
	 * Resolve an outbound chat_id (gateway-compatible) for a conversation.
	 * Mirrors `BizCity_Universal_Channel_Listener::compose_chat_id()`.
	 *
	 * @return array{chat_id:string,platform:string}|null
	 */
	public static function resolve_chat_id( int $conv_id ): ?array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT i.channel_type AS platform, i.channel_ref_id AS account_id, ci.source_id AS user_id
			   FROM {$tbl_conv} c
			   JOIN {$tbl_ci}  ci ON ci.id = c.contact_inbox_id
			   JOIN {$tbl_ibx} i  ON i.id  = c.inbox_id
			  WHERE c.id = %d LIMIT 1",
			$conv_id
		), ARRAY_A );
		if ( ! $row ) { return null; }
		$platform = strtoupper( (string) $row['platform'] );
		$account  = (string) $row['account_id'];
		$user     = (string) $row['user_id'];

		// Map CRM adapter codes (lowercase, e.g. 'facebook', 'zalo') to canonical Channel Gateway
		// platform tokens. Without this, the gateway sees 'FACEBOOK'/'ZALO' and falls back to
		// chat_id "facebook_..." which the gateway's detect_platform_legacy() does not recognise
		// (it expects "fb_" / "zalobot_" prefixes) → routed as UNKNOWN/FALLBACK and the send fails.
		switch ( $platform ) {
			case 'FACEBOOK':
				// Comment inbox uses channel_ref_id "fb_feed_{page_id}" — peel off the prefix.
				if ( strpos( $account, 'fb_feed_' ) === 0 ) {
					$account  = substr( $account, 8 );
					$platform = 'FB_FEED';
				} else {
					$platform = 'FB_MESS';
				}
				break;
			case 'ZALO':
				$platform = 'ZALO_BOT';
				break;
			case 'ZALO_BOT':
				// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - preserve the Bot channel discriminator.
				$platform = 'ZALO_BOT';
				break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — preserve ZALO_OA discriminator for canonical session key.
			case 'ZALO_OA':
				$platform = 'ZALO_OA';
				break;
		}

		switch ( $platform ) {
			case 'FB_MESS':
			case 'FB_FEED':
				$chat_id = 'fb_' . $account . '_' . $user; break;
			case 'ZALO_BOT':
				// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - compose the same private/group chat ID used by the adapter.
				$chat_id = strpos( $user, 'group:' ) === 0
					? 'zalobot_' . $account . '_group_' . substr( $user, 6 )
					: 'zalobot_' . $account . '_private_' . $user;
				break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — canonical session key for Zalo OA customer lane.
			case 'ZALO_OA':
				$chat_id = 'zalooa_' . $account . '_' . $user; break;
			case 'ZALO_HOTLINE':
				$chat_id = 'hotline_' . $account . '_' . $user; break;
			// [2026-07-06 Johnny Chu] PHASE-0.48 ID-MEM — align with Channel Gateway webchat prefix.
			case 'WEBCHAT':
				$chat_id = 'webchat_' . $user; break;
			case 'TELEGRAM':
				$chat_id = 'tg_' . $account . '_' . $user; break;
			default:
				$chat_id = strtolower( $platform ) . '_' . $account . '_' . $user;
		}
		return array( 'chat_id' => $chat_id, 'platform' => $platform );
	}

	/**
	 * List messages for a conversation (chronological asc).
	 */
	public static function list_messages( int $conversation_id, int $limit = 100, int $after_id = 0 ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$limit  = max( 1, min( 500, $limit ) );

		// [2026-09-06 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.39C-C3 — hydrate the detail pane from the newest bounded message window; the conversation list already points at last_message_id, while ASC-from-zero returned only stale history once a thread exceeded the page limit.
		if ( $after_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$tbl}
				 WHERE conversation_id = %d AND id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$conversation_id, $after_id, $limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$tbl}
				 WHERE conversation_id = %d
				 ORDER BY id DESC
				 LIMIT %d",
				$conversation_id, $limit
			), ARRAY_A );
			$rows = is_array( $rows ) ? array_reverse( $rows ) : array();
		}

		return self::hydrate_messages( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * List the newest bounded window of messages older than $before_id (chronological asc).
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — scroll-up paging for the browser cache; same hydration as list_messages().
	 */
	public static function list_messages_before( int $conversation_id, int $before_id, int $limit = 50 ): array {
		global $wpdb;
		if ( $conversation_id <= 0 || $before_id <= 0 ) { return array(); }
		$tbl   = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE conversation_id = %d AND id < %d
			 ORDER BY id DESC
			 LIMIT %d",
			$conversation_id, $before_id, $limit
		), ARRAY_A );
		return self::hydrate_messages( is_array( $rows ) ? array_reverse( $rows ) : array() );
	}

	/**
	 * Re-read specific messages of one conversation; ids outside the conversation are ignored.
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — the messages table has no updated_at, so the browser rechecks non-terminal ids to pick up delivery changes.
	 */
	public static function get_messages_by_ids( int $conversation_id, array $ids ): array {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 50 );
		if ( $conversation_id <= 0 || empty( $ids ) ) { return array(); }
		$tbl          = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d AND id IN ({$placeholders}) ORDER BY id ASC",
			array_merge( array( $conversation_id ), $ids )
		), ARRAY_A );
		return self::hydrate_messages( is_array( $rows ) ? $rows : array() );
	}

	/** Newest message id of one conversation (0 when empty). */
	public static function get_conversation_newest_message_id( int $conversation_id ): int {
		global $wpdb;
		if ( $conversation_id <= 0 ) { return 0; }
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$tbl} WHERE conversation_id = %d", $conversation_id ) );
	}

	/**
	 * Cheap fingerprint of a conversation list using the exact list_conversations() predicates.
	 *
	 * [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-CACHE — lets the Inbox poll answer not_modified without running the list + count queries.
	 */
	public static function get_inbox_sync_token( array $args ): string {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$tbl_ibx  = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		list( $where, $params ) = self::build_conversation_where( $args );
		$sql = "SELECT COUNT(*) AS n, MAX(c.updated_at) AS cu, MAX(c.last_message_id) AS lm, SUM(c.unread_count) AS ur,
					MAX(c.last_activity_at) AS la, MAX(ct.updated_at) AS ctu, SUM(CRC32(COALESCE(c.cached_label_list, ''))) AS lb,
					SUM(c.priority) AS pr, SUM(CRC32(COALESCE(c.status, ''))) AS st
				FROM {$tbl_conv} c
				LEFT JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ibx} i ON i.id = c.inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m ON m.id = c.last_message_id
				WHERE " . implode( ' AND ', $where );
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$row      = $wpdb->get_row( $prepared, ARRAY_A );
		return md5( (string) wp_json_encode( array( is_array( $row ) ? array_values( $row ) : array(), $args ) ) );
	}

	/**
	 * Attachments and cold-content read gate for every CRM message reader.
	 *
	 * Hot/archived rows remain SQL-backed. Only offloaded rows follow the
	 * immutable archive pointer path. The result carries `cold`, `partial` and
	 * `cold_error` so callers can render a bounded degraded state without
	 * silently treating missing archive content as an empty message.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param int                            $max_ms
	 * @return array<int,array<string,mixed>>
	 */
	public static function hydrate_messages( array $rows, int $max_ms = 800 ): array {
		global $wpdb;
		$att_tbl = BizCity_CRM_DB_Installer_V2::tbl_attachments();
		if ( ! $rows ) {
			return array();
		}

		// Hydrate attachments in 1 query.
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// [2026-09-22 02:35 AM OpenAI GPT-5.6 Luna] HOTFIX — attachments are
		// optional for the message read projection; a tenant without that table
		// must still receive text messages instead of a database exception.
		$atts = BizCity_CRM_DB_Installer_V2::table_exists( $att_tbl )
			? $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$att_tbl} WHERE message_id IN ({$placeholders})", $ids
			), ARRAY_A )
			: array();
		$by_msg = array();
		foreach ( $atts as $a ) {
			$by_msg[ (int) $a['message_id'] ][] = $a;
		}
		foreach ( $rows as &$r ) {
			$r['attachments'] = $by_msg[ (int) $r['id'] ] ?? array();
			$r['cold'] = false;
			$r['partial'] = false;
		}
		unset( $r );
		$offloaded = array();
		foreach ( $rows as $index => $row ) {
			if ( 'offloaded' === (string) ( $row['content_storage_state'] ?? '' ) ) {
				$offloaded[ (int) $row['id'] ] = $index;
			} elseif ( 'expired' === (string) ( $row['content_storage_state'] ?? '' ) ) {
				$rows[ $index ]['cold'] = true;
				$rows[ $index ]['cold_error'] = 'expired';
			}
		}
		if ( $offloaded && class_exists( 'BizCity_Channel_Conversation_Archive' ) ) {
			$receipt_tbl = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
			$ids = array_keys( $offloaded );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$receipt_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT crm_message_id, line_hash, byte_offset, line_bytes FROM {$receipt_tbl} WHERE archive_status = 'written' AND crm_message_id IN ({$placeholders})",
				$ids
			), ARRAY_A );
			$receipts = array();
			foreach ( is_array( $receipt_rows ) ? $receipt_rows : array() as $receipt ) {
				$receipts[ (int) $receipt['crm_message_id'] ] = $receipt;
			}
			$pointers = array();
			$pointer_rows = array();
			foreach ( $offloaded as $message_id => $index ) {
				$row = $rows[ $index ];
				$receipt = $receipts[ $message_id ] ?? array();
				$pointer_rows[] = array(
					'crm_message_id' => $message_id,
					'channel'        => (string) ( $row['archive_channel'] ?? '' ),
					'account_key'    => (string) ( $row['archive_account_key'] ?? '' ),
					'peer_key'       => (string) ( $row['archive_peer_key'] ?? '' ),
					'archive_month'  => (string) ( $row['archive_month'] ?? '' ),
					'byte_offset'    => $receipt['byte_offset'] ?? null,
					'line_bytes'     => $receipt['line_bytes'] ?? null,
					'row_hash'       => $receipt['line_hash'] ?? '',
				);
				$pointers[] = $pointer_rows[ count( $pointer_rows ) - 1 ];
			}
			$batch = BizCity_Channel_Conversation_Archive::read_batch( $pointers, $max_ms );
			foreach ( $pointer_rows as $pointer_index => $pointer ) {
				$message_id = (int) $pointer['crm_message_id'];
				$index = $offloaded[ $message_id ];
				$result = $batch['items'][ $pointer_index ] ?? array( 'ok' => false, 'cold_error' => 'archive_record_missing' );
				$rows[ $index ]['cold'] = true;
				$rows[ $index ]['partial'] = ! empty( $batch['partial'] );
				if ( ! empty( $result['ok'] ) ) {
					$rows[ $index ]['content'] = (string) ( $result['content'] ?? '' );
					$rows[ $index ]['body'] = (string) ( $result['body'] ?? '' );
					$rows[ $index ]['content_type'] = (string) ( $result['content_type'] ?? $rows[ $index ]['content_type'] );
				} else {
					$rows[ $index ]['cold_error'] = (string) ( $result['cold_error'] ?? 'archive_record_missing' );
				}
			}
		}

		return $rows;
	}

	/** Plan or offload verified archived message content in a bounded maintenance batch. */
	public static function offload_archived_messages( string $before, int $limit = 100, int $keep_recent = 20, bool $dry_run = false ): int {
		// [2026-09-19 02:30 PM Johnny Chu] PHASE-0.56-H-11 — safe dry-run/guarded offload selection; production deletion remains feature-flagged.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before ) ) { return 0; }
		if ( ! $dry_run && function_exists( 'get_option' ) && ! get_option( 'bizcity_crm_message_offload_enabled', false ) ) { return 0; }
		global $wpdb;
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$receipts = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		$limit = max( 1, min( 500, $limit ) );
		$keep_recent = max( 1, min( 100, $keep_recent ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.id, m.conversation_id, m.content, m.body, m.payload_json, m.message_type,
				m.archive_channel, m.archive_account_key, m.archive_peer_key, m.archive_month,
				r.archive_schema_version, r.line_hash, r.byte_offset, r.line_bytes
			 FROM `{$messages}` m JOIN `{$receipts}` r ON r.crm_message_id = m.id AND r.archive_status = 'written'
			 LEFT JOIN `{$messages}` newer ON newer.conversation_id = m.conversation_id AND newer.id > m.id
			 WHERE m.content_storage_state = 'archived' AND m.created_at < %s
			   AND m.message_type NOT IN ('private_note', 'activity')
			   AND r.archive_schema_version >= 2
			 GROUP BY m.id
			 HAVING COUNT(newer.id) >= %d
			 ORDER BY m.id ASC LIMIT %d",
			$before, $keep_recent, $limit
		), ARRAY_A );
		$offloaded = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $dry_run ) { $offloaded++; continue; }
			$pointer = array(
				'crm_message_id' => (int) $row['id'],
				'channel' => (string) ( $row['archive_channel'] ?? '' ),
				'account_key' => (string) ( $row['archive_account_key'] ?? '' ),
				'peer_key' => (string) ( $row['archive_peer_key'] ?? '' ),
				'archive_month' => (string) ( $row['archive_month'] ?? '' ),
				'byte_offset' => $row['byte_offset'] ?? null,
				'line_bytes' => $row['line_bytes'] ?? null,
				'row_hash' => $row['line_hash'] ?? '',
			);
			$verified = class_exists( 'BizCity_Channel_Conversation_Archive' ) ? BizCity_Channel_Conversation_Archive::read_batch( array( $pointer ), 200 ) : array();
			if ( empty( $verified['items'][0]['ok'] ) ) { continue; }
			$updated = $wpdb->update( $messages, array(
				'content'              => null,
				'body'                 => null,
				'payload_json'         => null,
				'content_storage_state' => 'offloaded',
				'offloaded_at'         => current_time( 'mysql' ),
			), array( 'id' => (int) $row['id'], 'content_storage_state' => 'archived' ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d', '%s' ) );
			if ( false !== $updated && $updated > 0 ) {
				$offloaded++;
				if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
					BizCity_CRM_Event_Emitter::emit( 'crm_message_offloaded', array( 'message_id' => (int) $row['id'], 'conversation_id' => (int) $row['conversation_id'] ) );
				}
			}
		}
		return $offloaded;
	}

	/** Run one guarded staged offload tick; the feature flag remains opt-in. */
	public static function offload_staged_tick( int $limit = 25, bool $dry_run = true ): array {
		// [2026-09-19 02:45 PM Johnny Chu] PHASE-0.56-H-12 — small staged rollout surface; no default scheduling or automatic enablement.
		$before = current_time( 'mysql' );
		$limit = max( 1, min( 25, $limit ) );
		$count = self::offload_archived_messages( $before, $limit, 20, $dry_run );
		return array(
			'dry_run' => $dry_run,
			'limit' => $limit,
			'eligible_or_offloaded' => $count,
			'offload_enabled' => function_exists( 'get_option' ) ? (bool) get_option( 'bizcity_crm_message_offload_enabled', false ) : false,
			'before' => $before,
		);
	}

	/* ============================================================
	 * CONTACT DRAWER (PHASE 0.34 FE-M6)
	 * ============================================================ */

	/**
	 * Inboxes this contact has touched (joined via contact_inboxes).
	 */
	public static function list_inboxes_for_contact( int $contact_id ): array {
		global $wpdb;
		$tbl_ibx = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$tbl_ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT i.*, ci.source_id, ci.last_seen_at
			   FROM {$tbl_ibx} i
			   JOIN {$tbl_ci}  ci ON ci.inbox_id = i.id
			  WHERE ci.contact_id = %d
			  GROUP BY i.id
			  ORDER BY ci.last_seen_at DESC, i.id DESC",
			$contact_id
		), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Recent conversations across every inbox this contact has used.
	 */
	public static function list_conversations_for_contact( int $contact_id, int $limit = 10 ): array {
		global $wpdb;
		$tbl_conv = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$tbl_ci   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$tbl_ct   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_msg  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$limit    = max( 1, min( 50, $limit ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
					c.id, c.inbox_id, c.contact_inbox_id, c.status, c.priority,
					c.last_message_id, c.last_activity_at, c.unread_count,
					c.created_at, c.updated_at,
					ci.source_id, ci.contact_id,
					ct.name AS contact_name, ct.avatar_url AS contact_avatar,
					m.content_preview AS last_message_content,
					m.message_type AS last_message_type,
					m.sender_type AS last_sender_type,
					m.created_at AS last_message_at
				FROM {$tbl_conv} c
				JOIN {$tbl_ci} ci ON ci.id = c.contact_inbox_id
				LEFT JOIN {$tbl_ct} ct ON ct.id = ci.contact_id
				LEFT JOIN {$tbl_msg} m  ON m.id  = c.last_message_id
				WHERE ci.contact_id = %d
				ORDER BY c.last_activity_at DESC, c.id DESC
				LIMIT %d",
			$contact_id, $limit
		), ARRAY_A );
		return $rows ?: array();
	}

	/**
	 * Twin Gurus bound to any inbox this contact has used.
	 *
	 * Joins CRM inboxes (channel_type, channel_ref_id) to the gateway
	 * `_bizcity_channel_bindings` table (platform, account_id), then resolves
	 * character roster details via BizCity_Knowledge_Database when available.
	 *
	 * Returns: [ {character_id,name,slug,avatar,platform,account_id} ]
	 */
	public static function list_gurus_for_contact( int $contact_id ): array {
		if ( ! class_exists( 'BizCity_Channel_Binding' ) ) { return array(); }
		global $wpdb;
		$tbl_ibx = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$tbl_ci  = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$bind_tbl = BizCity_Channel_Binding::table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT b.character_id, b.platform, b.account_id, b.mode
			   FROM {$tbl_ci} ci
			   JOIN {$tbl_ibx} i ON i.id = ci.inbox_id
			   JOIN {$bind_tbl} b
				 ON UPPER(b.platform) = UPPER(i.channel_type)
				AND ( b.account_id = i.channel_ref_id OR b.account_id = '*' )
			  WHERE ci.contact_id = %d AND b.status = 1 AND b.character_id > 0",
			$contact_id
		), ARRAY_A );
		if ( ! $rows ) { return array(); }

		// Hydrate character roster (name/avatar/slug) when knowledge DB is loaded.
		$roster = array();
		if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
			$db   = BizCity_Knowledge_Database::instance();
			$chrs = (array) $db->get_characters( array( 'limit' => 500 ) );
			foreach ( $chrs as $c ) {
				$roster[ (int) $c->id ] = array(
					'name'   => isset( $c->name )   ? (string) $c->name   : '',
					'slug'   => isset( $c->slug )   ? (string) $c->slug   : '',
					'avatar' => isset( $c->avatar ) ? (string) $c->avatar : '',
					'status' => isset( $c->status ) ? (string) $c->status : '',
				);
			}
		}

		$out = array();
		foreach ( $rows as $r ) {
			$cid = (int) $r['character_id'];
			$ext = $roster[ $cid ] ?? array( 'name' => 'Guru #' . $cid, 'slug' => '', 'avatar' => '', 'status' => '' );
			$out[] = array(
				'character_id' => $cid,
				'name'         => $ext['name'],
				'slug'         => $ext['slug'],
				'avatar'       => $ext['avatar'],
				'status'       => $ext['status'],
				'platform'     => (string) $r['platform'],
				'account_id'   => (string) $r['account_id'],
				'mode'         => (string) ( $r['mode'] ?? 'auto' ),
			);
		}
		return $out;
	}

	/* ============================================================
	 * AUTOMATION RULES — PHASE 0.35 M2.W1
	 * ============================================================ */

	/**
	 * @param array $args { event_name?, active?, inbox_id?, q?, limit?, offset? }
	 */
	public static function list_automation_rules( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['event_name'] ) ) {
			$where[]  = 'event_name = %s';
			$params[] = (string) $args['event_name'];
		}
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( isset( $args['inbox_id'] ) ) {
			$where[]  = '(inbox_id IS NULL OR inbox_id = %d)';
			$params[] = (int) $args['inbox_id'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$sql    = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where )
				. " ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_automation_rule( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Insert (when id missing/0) or update (when id > 0).
	 *
	 * @return int Rule id (0 on failure).
	 */
	public static function upsert_automation_rule( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );

		$cond_json = isset( $data['conditions'] ) && ! is_string( $data['conditions'] )
			? wp_json_encode( $data['conditions'] )
			: ( $data['conditions_json'] ?? null );
		$act_json  = isset( $data['actions'] ) && ! is_string( $data['actions'] )
			? wp_json_encode( $data['actions'] )
			: ( $data['actions_json'] ?? null );

		$row = array(
			'name'            => (string) ( $data['name'] ?? '' ),
			'description'     => isset( $data['description'] ) ? (string) $data['description'] : null,
			'event_name'      => (string) ( $data['event_name'] ?? '' ),
			'inbox_id'        => isset( $data['inbox_id'] ) && (int) $data['inbox_id'] > 0 ? (int) $data['inbox_id'] : null,
			'conditions_json' => $cond_json,
			'actions_json'    => $act_json,
			'active'          => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'      => $now,
		);

		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['run_count']     = 0;
		$row['last_run_at']   = null;
		$row['created_by_id'] = isset( $data['created_by_id'] ) ? (int) $data['created_by_id'] : ( get_current_user_id() ?: null );
		$row['created_at']    = $now;
		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) { return 0; }
		return (int) $wpdb->insert_id;
	}

	public static function delete_automation_rule( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_rule_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_automation_rules();
		$now = current_time( 'mysql' );
		$ok  = $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_run_at = %s WHERE id = %d",
			$now, $id
		) );
		return (bool) $ok;
	}

	/* ============================================================
	 * LABELS — PHASE 0.35 M3.W1
	 * ============================================================ */

	public static function list_labels( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['show_on_sidebar'] ) ) {
			$where[]  = 'show_on_sidebar = %d';
			$params[] = (int) (bool) $args['show_on_sidebar'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY title ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_label( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_label_by_title( string $title ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE title = %s", $title ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_label( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'title'           => (string) ( $data['title'] ?? '' ),
			'description'     => isset( $data['description'] ) ? (string) $data['description'] : null,
			'color'           => (string) ( $data['color'] ?? '#1f93ff' ),
			'show_on_sidebar' => isset( $data['show_on_sidebar'] ) ? (int) (bool) $data['show_on_sidebar'] : 1,
			'updated_at'      => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_label( int $id ): bool {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$cl_tbl = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		// Find conversations losing this label so we can rebuild cached_label_list.
		$convs = $wpdb->get_col( $wpdb->prepare( "SELECT conversation_id FROM {$cl_tbl} WHERE label_id = %d", $id ) );
		$wpdb->delete( $cl_tbl, array( 'label_id' => $id ), array( '%d' ) );
		$ok = (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
		foreach ( (array) $convs as $cid ) {
			self::resync_conversation_label_cache( (int) $cid );
		}
		return $ok;
	}

	/**
	 * Replace the label set on a conversation.
	 *
	 * @param int    $conv_id
	 * @param int[]  $label_ids
	 * @param int    $by_user_id
	 * @return array { added:int[], removed:int[], titles:string[] }
	 */
	public static function set_conversation_labels( int $conv_id, array $label_ids, int $by_user_id = 0 ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$conv    = self::get_conversation( $conv_id );
		if ( ! $conv ) { return array( 'added' => array(), 'removed' => array(), 'titles' => array() ); }

		$desired = array_values( array_unique( array_map( 'intval', $label_ids ) ) );
		$desired = array_values( array_filter( $desired, static fn( $i ) => $i > 0 ) );

		$current = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT label_id FROM {$cl_tbl} WHERE conversation_id = %d", $conv_id
		) ) );

		$to_add    = array_values( array_diff( $desired, $current ) );
		$to_remove = array_values( array_diff( $current, $desired ) );
		$now       = current_time( 'mysql' );

		// PHASE-0.48F U5 / P-U-3 — a failed write must surface, not return "ok" with an empty label set.
		$write_errors = array();
		foreach ( $to_add as $lid ) {
			$inserted = $wpdb->insert( $cl_tbl, array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'assigned_by'     => $by_user_id ?: null,
				'assigned_at'     => $now,
			) );
			if ( false === $inserted ) { $write_errors[] = 'insert:' . $lid . ':' . $wpdb->last_error; }
		}
		if ( $to_remove ) {
			$placeholders = implode( ',', array_fill( 0, count( $to_remove ), '%d' ) );
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$cl_tbl} WHERE conversation_id = %d AND label_id IN ({$placeholders})",
				array_merge( array( $conv_id ), $to_remove )
			) );
			if ( false === $deleted ) { $write_errors[] = 'delete:' . $wpdb->last_error; }
		}
		if ( $write_errors ) {
			error_log( '[bizcity-crm] set_conversation_labels write failed conv=' . $conv_id . ' table=' . $cl_tbl . ' ' . implode( ' | ', $write_errors ) );
			return array( 'added' => array(), 'removed' => array(), 'titles' => array(), 'failed' => true );
		}

		$titles = self::resync_conversation_label_cache( $conv_id );

		// Emit events — one per add/remove, threaded for downstream rules.
		foreach ( $to_add as $lid ) {
			$lbl = self::get_label( $lid );
			BizCity_CRM_Event_Emitter::emit( 'crm_label_assigned', array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'label'           => $lbl['title'] ?? null,
				'by_user_id'      => $by_user_id,
			) );
		}
		foreach ( $to_remove as $lid ) {
			$lbl = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lbl_tbl} WHERE id=%d", $lid ), ARRAY_A );
			BizCity_CRM_Event_Emitter::emit( 'crm_label_removed', array(
				'conversation_id' => $conv_id,
				'label_id'        => $lid,
				'label'           => $lbl['title'] ?? null,
				'by_user_id'      => $by_user_id,
			) );
		}

		return array( 'added' => $to_add, 'removed' => $to_remove, 'titles' => $titles );
	}

	public static function get_conversation_labels( int $conv_id ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.*, cl.assigned_at, cl.assigned_by
				FROM {$cl_tbl} cl JOIN {$lbl_tbl} l ON l.id = cl.label_id
				WHERE cl.conversation_id = %d ORDER BY l.title ASC",
			$conv_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recompute conversations.cached_label_list from join table.
	 * Returns the resulting label titles array.
	 */
	public static function resync_conversation_label_cache( int $conv_id ): array {
		global $wpdb;
		$cl_tbl  = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$lbl_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$titles  = $wpdb->get_col( $wpdb->prepare(
			"SELECT l.title FROM {$cl_tbl} cl JOIN {$lbl_tbl} l ON l.id = cl.label_id
				WHERE cl.conversation_id = %d ORDER BY l.title ASC",
			$conv_id
		) );
		$titles  = is_array( $titles ) ? array_map( 'strval', $titles ) : array();
		$wpdb->update(
			BizCity_CRM_DB_Installer_V2::tbl_conversations(),
			array(
				'cached_label_list' => implode( ',', $titles ),
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $conv_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return $titles;
	}

	/* ============================================================
	 * CUSTOM ATTRIBUTE DEFINITIONS — PHASE 0.35 M3.W3
	 * ============================================================ */

	public static function list_custom_attribute_defs( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['target'] ) ) {
			$where[]  = 'target = %s';
			$params[] = (string) $args['target'];
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY display_name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_custom_attribute_def( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_custom_attribute_def( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'attribute_key' => sanitize_key( (string) ( $data['attribute_key'] ?? '' ) ),
			'display_name'  => (string) ( $data['display_name'] ?? '' ),
			'description'   => isset( $data['description'] ) ? (string) $data['description'] : null,
			'display_type'  => (string) ( $data['display_type'] ?? 'text' ),
			'target'        => (string) ( $data['target'] ?? 'contact' ),
			'regex_pattern' => isset( $data['regex_pattern'] ) ? (string) $data['regex_pattern'] : null,
			'options_json'  => isset( $data['options'] ) && ! is_string( $data['options'] )
				? wp_json_encode( $data['options'] )
				: ( $data['options_json'] ?? null ),
			'default_value' => isset( $data['default_value'] ) ? (string) $data['default_value'] : null,
			'updated_at'    => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_custom_attribute_def( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_custom_attribute_definitions();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	/* ============================================================
	 * MACROS — PHASE 0.35 M3.W5
	 * ============================================================ */

	public static function list_macros( array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$where  = array( '1=1' );
		$params = array();
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['visibility'] ) ) {
			$where[]  = 'visibility = %s';
			$params[] = (string) $args['visibility'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(name LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$sql      = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC';
		$prepared = $params ? $wpdb->prepare( $sql, $params ) : $sql;
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_macro( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function upsert_macro( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		$id  = (int) ( $data['id'] ?? 0 );
		$row = array(
			'name'         => (string) ( $data['name'] ?? '' ),
			'description'  => isset( $data['description'] ) ? (string) $data['description'] : null,
			'visibility'   => (string) ( $data['visibility'] ?? 'global' ),
			'owner_user_id'=> isset( $data['owner_user_id'] ) ? (int) $data['owner_user_id'] : ( get_current_user_id() ?: null ),
			'template'     => isset( $data['template'] ) ? (string) $data['template'] : null,
			'actions_json' => isset( $data['actions'] ) && ! is_string( $data['actions'] )
				? wp_json_encode( $data['actions'] )
				: ( $data['actions_json'] ?? null ),
			'active'       => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'   => $now,
		);
		if ( $id > 0 ) {
			$wpdb->update( $tbl, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = $now;
		$row['run_count']  = 0;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_macro( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	public static function bump_macro_run_count( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_macros();
		$now = current_time( 'mysql' );
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET run_count = run_count + 1, last_used_at = %s WHERE id = %d",
			$now, $id
		) );
	}

	/* ================================================================
	 * PHASE 0.35 M4.W1 — Working Hours
	 * ================================================================ */

	/**
	 * Default schedule (used by seeder and fallback when no row exists):
	 *   Mon-Fri 09:00–18:00 open · Sat-Sun closed.
	 *   day_of_week: 0=Sun, 1=Mon, ..., 6=Sat (matches PHP `date('w')`).
	 */
	public static function default_working_hours_grid(): array {
		$grid = array();
		for ( $d = 0; $d <= 6; $d++ ) {
			$grid[] = array(
				'day_of_week' => $d,
				'is_open'     => ( $d >= 1 && $d <= 5 ) ? 1 : 0,
				'open_time'   => '09:00:00',
				'close_time'  => '18:00:00',
			);
		}
		return $grid;
	}

	public static function list_working_hours( int $inbox_id ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE inbox_id = %d ORDER BY day_of_week ASC",
			$inbox_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function ensure_working_hours_seeded( int $inbox_id ): int {
		$existing = self::list_working_hours( $inbox_id );
		if ( ! empty( $existing ) ) { return 0; }
		$inserted = 0;
		foreach ( self::default_working_hours_grid() as $row ) {
			$row['inbox_id'] = $inbox_id;
			if ( self::upsert_working_hour_row( $row ) ) { $inserted++; }
		}
		return $inserted;
	}

	public static function upsert_working_hour_row( array $row ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_working_hours();
		$now = current_time( 'mysql' );
		$inbox_id = (int) ( $row['inbox_id']    ?? 0 );
		$dow      = (int) ( $row['day_of_week'] ?? -1 );
		if ( $inbox_id <= 0 || $dow < 0 || $dow > 6 ) { return false; }
		$data = array(
			'inbox_id'    => $inbox_id,
			'day_of_week' => $dow,
			'is_open'     => isset( $row['is_open'] ) ? (int) (bool) $row['is_open'] : 1,
			'open_time'   => self::sanitize_time( $row['open_time']  ?? '09:00:00' ),
			'close_time'  => self::sanitize_time( $row['close_time'] ?? '18:00:00' ),
			'updated_at'  => $now,
		);
		// Try insert; if PK collision, update.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE inbox_id = %d AND day_of_week = %d",
			$inbox_id, $dow
		) );
		if ( $existing ) {
			return false !== $wpdb->update(
				$tbl,
				$data,
				array( 'inbox_id' => $inbox_id, 'day_of_week' => $dow ),
				array( '%d', '%d', '%d', '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		}
		$data['created_at'] = $now;
		return false !== $wpdb->insert( $tbl, $data );
	}

	private static function sanitize_time( string $t ): string {
		$ts = strtotime( '1970-01-01 ' . $t );
		return $ts ? gmdate( 'H:i:s', $ts ) : '09:00:00';
	}

	/* ================================================================
	 * PHASE 0.35 M4.W2 — SLA Policies CRUD
	 * ================================================================ */

	public static function list_sla_policies( array $args = array() ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$where = array( '1=1' );
		$prep  = array();
		if ( isset( $args['active'] ) ) {
			$where[] = 'active = %d';
			$prep[]  = (int) (bool) $args['active'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[] = 'name LIKE %s';
			$prep[]  = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
		}
		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT 200';
		$sql = $prep ? $wpdb->prepare( $sql, ...$prep ) : $sql;
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_sla_policy( int $id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		// [2026-09-21 10:50 PM OpenAI GPT-5.6 Luna] HOTFIX — policy lookup must also degrade when the optional legacy SLA schema is absent.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_sla_policy( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		$now = current_time( 'mysql' );
		$row = array(
			'name'                       => trim( (string) ( $data['name'] ?? '' ) ),
			'description'                => isset( $data['description'] ) ? (string) $data['description'] : null,
			'frt_threshold_minutes'      => isset( $data['frt_threshold_minutes'] ) ? (int) $data['frt_threshold_minutes'] : null,
			'nrt_threshold_minutes'      => isset( $data['nrt_threshold_minutes'] ) ? (int) $data['nrt_threshold_minutes'] : null,
			'rt_threshold_minutes'       => isset( $data['rt_threshold_minutes'] )  ? (int) $data['rt_threshold_minutes']  : null,
			'only_during_business_hours' => isset( $data['only_during_business_hours'] ) ? (int) (bool) $data['only_during_business_hours'] : 0,
			'active'                     => isset( $data['active'] ) ? (int) (bool) $data['active'] : 1,
			'updated_at'                 => $now,
		);
		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			$wpdb->update( $tbl, $row, array( 'id' => $id ), null, array( '%d' ) );
			return $id;
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function delete_sla_policy( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_sla_policies();
		return (bool) $wpdb->delete( $tbl, array( 'id' => $id ), array( '%d' ) );
	}

	/* ================================================================
	 * PHASE 0.35 M4.W2 — Applied SLAs (writer + reader)
	 * ================================================================ */

	public static function get_applied_sla_for_conversation( int $conv_id ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		// [2026-09-21 10:45 PM OpenAI GPT-5.6 Luna] HOTFIX — legacy SLA inspection must fail open when a tenant has not installed the optional SLA schema.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d",
			$conv_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function upsert_applied_sla( array $data ): int {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		$now    = current_time( 'mysql' );
		$convid = (int) ( $data['conversation_id'] ?? 0 );
		if ( $convid <= 0 ) { return 0; }
		$existing = self::get_applied_sla_for_conversation( $convid );
		$row = array(
			'conversation_id'   => $convid,
			'sla_policy_id'     => (int) ( $data['sla_policy_id'] ?? 0 ),
			'applied_at'        => isset( $data['applied_at'] ) ? (int) $data['applied_at'] : time(),
			'frt_due_at'        => isset( $data['frt_due_at'] ) ? (int) $data['frt_due_at'] : null,
			'nrt_due_at'        => isset( $data['nrt_due_at'] ) ? (int) $data['nrt_due_at'] : null,
			'rt_due_at'         => isset( $data['rt_due_at'] )  ? (int) $data['rt_due_at']  : null,
			'frt_breached_at'   => isset( $data['frt_breached_at'] ) ? (int) $data['frt_breached_at'] : null,
			'nrt_breached_at'   => isset( $data['nrt_breached_at'] ) ? (int) $data['nrt_breached_at'] : null,
			'rt_breached_at'    => isset( $data['rt_breached_at'] )  ? (int) $data['rt_breached_at']  : null,
			'met_at'            => isset( $data['met_at'] )            ? (int) $data['met_at']            : null,
			'last_evaluated_at' => isset( $data['last_evaluated_at'] ) ? (int) $data['last_evaluated_at'] : null,
			'state'             => (string) ( $data['state'] ?? 'active' ),
			'updated_at'        => $now,
		);
		if ( $existing ) {
			$wpdb->update( $tbl, $row, array( 'id' => (int) $existing['id'] ), null, array( '%d' ) );
			return (int) $existing['id'];
		}
		$row['created_at'] = $now;
		$ok = $wpdb->insert( $tbl, $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_applied_sla_fields( int $id, array $fields ): bool {
		global $wpdb;
		if ( empty( $fields ) ) { return false; }
		$fields['updated_at'] = current_time( 'mysql' );
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		return false !== $wpdb->update( $tbl, $fields, array( 'id' => $id ), null, array( '%d' ) );
	}

	public static function list_active_applied_slas( int $limit = 500 ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_applied_slas();
		// PHASE 0.35 fix — silently skip on subsites where CRM schema isn't installed.
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $tbl ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE state IN ('active','breached') AND (met_at IS NULL OR state = 'breached') ORDER BY frt_due_at ASC LIMIT %d",
			$limit
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'crm_repository', 'modules.twin-crm', array(
		'inbox_by_ref_{blog_id}_{database_hash}_{tuple_hash}' => array( 'ttl' => 60, 'desc' => 'Active CRM inbox by exact channel and account reference' ),
		'contacts_scope_{blog_id}_{database_hash}_{scope_hash}_{limit}' => array( 'ttl' => 60, 'desc' => 'Contacts and source memberships for one authorized Inbox scope' ),
		'contact_care_{blog_id}_{database_hash}_{contact_scope_hash}_{limit}' => array( 'ttl' => 30, 'desc' => 'Contact-scoped CRM notes, tasks and assigned labels' ),
	) );
}
