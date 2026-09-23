<?php
/**
 * BizCity Channel Conversation Archive.
 *
 * Encrypted, append-only JSONL archive for CRM conversations that need a
 * recovery/audit copy. SQL remains the canonical query and analytics store.
 * This helper is deliberately separate from BizCity_Channel_File_Logger because
 * the operational logger redacts message content by contract.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Channel_Conversation_Archive', false ) ) {
	return;
}

final class BizCity_Channel_Conversation_Archive {

	const BASE_FOLDER = 'bizcity-channel-conversations';
	const PREFIX      = 'bzca1_';
	// [2026-08-24 Johnny Chu] PHASE-0.39F-F1 — cover every Zone 1 CRM channel; Zone 2 remains outside the CRM archive.
	const CHANNELS    = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' );
	const MAX_LINE_BYTES = 262144;
	const DEFAULT_RETENTION_DAYS = 365;
	const MAX_RECONCILE_IDS = 1000;
	const KEYRING_OPTION = 'bizcity_channel_archive_keyring';

	private static $registered = false;

	/** Register CRM message event listeners after CRM has loaded. */
	public static function register(): void {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — register CRM archive event listeners.
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'bizcity_crm_event_crm_message_received', array( __CLASS__, 'archive_inbound' ), 20, 1 );
		add_action( 'bizcity_crm_event_crm_message_sent', array( __CLASS__, 'archive_outbound' ), 20, 1 );
		add_action( 'bizcity_crm_event_crm_message_delivery_updated', array( __CLASS__, 'archive_delivery' ), 20, 1 );
		add_action( 'bizcity_channel_jsonl_retention', array( __CLASS__, 'retention_tick' ), 15 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ), 20 );
	}

	/** Register admin-only archive maintenance endpoints; Inbox never uses these routes. */
	public static function register_rest_routes(): void {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — expose controlled maintenance operations without creating a second archive data path.
		$permission = array( __CLASS__, 'rest_permission' );
		register_rest_route( 'bizcity-channel/v1', '/conversation-archive/reconcile', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_reconcile' ),
			'permission_callback' => $permission,
		) );
		register_rest_route( 'bizcity-channel/v1', '/conversation-archive/export', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_export' ),
			'permission_callback' => $permission,
		) );
		register_rest_route( 'bizcity-channel/v1', '/conversation-archive/erase', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'rest_erase' ),
			'permission_callback' => $permission,
		) );
	}

	/** Require an authenticated tenant administrator for archive maintenance. */
	public static function rest_permission(): bool {
		// [2026-08-22 Johnny Chu] R-ERROR-UX/R-TWEB-4 — archive maintenance is destructive/sensitive and remains admin-only until a CRM export policy exists.
		// [2026-09-23 Claude Sonnet 5] Core-wide super-admin capability audit — bare manage_options wrongly rejected Network Super Admins with no local blog role.
		return is_user_logged_in() && ( class_exists( 'BizCity_Network_Admin_Capability' )
			? BizCity_Network_Admin_Capability::can_manage()
			: current_user_can( 'manage_options' ) );
	}

	/** Read common archive maintenance parameters without accepting a filesystem path. */
	private static function rest_partition( WP_REST_Request $request ): array {
		return array(
			'channel'    => sanitize_key( (string) $request->get_param( 'channel' ) ),
			'account_id' => sanitize_text_field( (string) $request->get_param( 'account_id' ) ),
			'peer_uid'   => sanitize_text_field( (string) $request->get_param( 'peer_uid' ) ),
			'month'      => sanitize_text_field( (string) $request->get_param( 'month' ) ),
		);
	}

	/** Return a standard maintenance error payload without leaking paths or content. */
	private static function rest_error( string $code, string $message, string $hint, string $help_code, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => false, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code ), $status );
	}

	public static function rest_reconcile( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — admin maintenance reconcile is bounded and read-only.
		$partition = self::rest_partition( $request );
		$result = self::reconcile_partition( $partition['channel'], $partition['account_id'], $partition['peer_uid'], array( __CLASS__, 'rest_authorize_tenant_admin' ) );
		return new WP_REST_Response( ! empty( $result['ok'] ) ? array_merge( array( 'success' => true ), $result ) : self::rest_error( (string) ( $result['code'] ?? 'archive_reconcile_failed' ), 'Không đối soát được archive hội thoại.', 'Kiểm tra channel, account và quyền quản trị rồi thử lại.', 'gateway_degraded', 200 ), 200 );
	}

	public static function rest_export( WP_REST_Request $request ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — admin export decrypts only an explicitly selected monthly partition.
		$partition = self::rest_partition( $request );
		if ( $partition['month'] === '' ) { return self::rest_error( 'invalid_param', 'Thiếu tháng archive cần xuất.', 'Gửi month theo định dạng YYYY-MM rồi thử lại.', 'invalid_param_generic' ); }
		$result = self::export_conversation( $partition['channel'], $partition['account_id'], $partition['peer_uid'], $partition['month'], array( __CLASS__, 'rest_authorize_tenant_admin' ) );
		if ( is_wp_error( $result ) ) { return new WP_REST_Response( array( 'success' => false, 'code' => $result->get_error_code(), 'message' => 'Không xuất được archive hội thoại.', 'hint' => 'Kiểm tra quyền quản trị và khóa archive rồi thử lại.', 'help_code' => 'gateway_degraded' ), 200 ); }
		return new WP_REST_Response( array( 'success' => true, 'items' => is_array( $result ) ? $result : array(), 'channel' => $partition['channel'], 'month' => $partition['month'] ), 200 );
	}

	public static function rest_erase( WP_REST_Request $request ): WP_REST_Response {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — admin legal erase uses the same atomic archive rewrite and legal-hold policy.
		$partition = self::rest_partition( $request );
		$conversation_id = (int) $request->get_param( 'conversation_id' );
		$result = self::erase_conversation( $partition['channel'], $partition['account_id'], $partition['peer_uid'], $conversation_id, array( __CLASS__, 'rest_authorize_tenant_admin' ) );
		return new WP_REST_Response( ! empty( $result['ok'] ) ? array_merge( array( 'success' => true ), $result ) : self::rest_error( (string) ( $result['code'] ?? 'archive_erase_failed' ), 'Không xóa được archive hội thoại.', 'Kiểm tra conversation ID, legal hold và quyền quản trị rồi thử lại.', 'gateway_degraded', 200 ), 200 );
	}

	/** Authorize only the current physical tenant; no cross-blog admin override. */
	public static function rest_authorize_tenant_admin( array $context ): bool {
		return self::rest_permission() && (int) ( $context['blog_id'] ?? 0 ) === (int) get_current_blog_id();
	}

	/** Run the archive retention sweep from the existing Channel Gateway job. */
	public static function retention_tick(): void {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — archive retention shares the existing guarded JSONL retention owner.
		$retry = self::retry_hot_messages();
		$deleted = self::purge_expired();
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array(
				'channel_conversation_archive_deleted' => $deleted,
				'channel_conversation_archive_retry_attempted' => (int) ( $retry['attempted'] ?? 0 ),
				'channel_conversation_archive_retry_archived' => (int) ( $retry['archived'] ?? 0 ),
				'channel_conversation_archive_retry_failed' => (int) ( $retry['failed'] ?? 0 ),
			) ) );
			$cron->note_event( 'channel_conversation_archive_retention', array(
				'deleted_files'  => $deleted,
				'retention_days' => self::retention_days(),
				'channels'       => self::CHANNELS,
				'retry'          => $retry,
			) );
		}
	}

	/** Retry bounded archive writes for old HOT messages; offload is never involved. */
	public static function retry_hot_messages( int $limit = 100 ): array {
		// [2026-09-19 02:00 PM Johnny Chu] PHASE-0.56-H-10 — retry HOT archive failures through the existing retention cron, max five attempts per message.
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return array( 'attempted' => 0, 'archived' => 0, 'failed' => 0 ); }
		global $wpdb;
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$limit = max( 1, min( 500, $limit ) );
		$now = current_time( 'mysql' );
		$cutoff = date( 'Y-m-d H:i:s', strtotime( $now ) - HOUR_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, message_type FROM `{$messages}` WHERE content_storage_state = 'hot' AND created_at < %s AND storage_attempts < 5 ORDER BY id ASC LIMIT %d",
			$cutoff, $limit
		), ARRAY_A );
		$result = array( 'attempted' => 0, 'archived' => 0, 'failed' => 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$message_id = (int) ( $row['id'] ?? 0 );
			if ( $message_id <= 0 ) { continue; }
			$attempted = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE `{$messages}` SET storage_attempts = storage_attempts + 1, storage_error_code = 'archive_retry' WHERE id = %d AND content_storage_state = 'hot' AND storage_attempts < 5",
				$message_id
			) );
			if ( 1 !== $attempted ) { continue; }
			$result['attempted']++;
			$direction = 'outgoing' === (string) ( $row['message_type'] ?? '' ) ? 'outbound' : 'inbound';
			$archived = self::archive_message( array( 'message_id' => $message_id, 'event_uuid' => 'archive_retry_' . $message_id ), $direction );
			if ( $archived ) {
				$result['archived']++;
			} else {
				$result['failed']++;
				$wpdb->update( $messages, array( 'storage_error_code' => 'archive_retry_failed' ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );
			}
		}
		return $result;
	}

	/** Return the bounded archive retention policy in days. */
	public static function retention_days(): int {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — keep retention explicit and filterable until a tenant policy UI exists.
		$days = (int) apply_filters( 'bizcity_channel_conversation_archive_retention_days', self::DEFAULT_RETENTION_DAYS );
		return max( 1, min( 3650, $days ) );
	}

	/** Delete complete monthly archive files past retention, honoring legal-hold filters. */
	public static function purge_expired( int $days = 0, bool $dry_run = false, int $limit = 100 ): int {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — bounded retention deletes whole old month files only; current SQL history is untouched.
		$root = self::root_directory();
		if ( $root === '' ) { return 0; }
		$days = $days > 0 ? max( 1, min( 3650, $days ) ) : self::retention_days();
		$limit = max( 1, min( 1000, $limit ) );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$deleted = 0;
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file_info ) {
				if ( $deleted >= $limit || ! $file_info->isFile() || $file_info->isLink() ) { continue; }
				$file = $file_info->getPathname();
				$month = basename( $file, '.jsonl' );
				if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) || substr( $file, -6 ) !== '.jsonl' ) { continue; }
				$file_ts = strtotime( $month . '-01 00:00:00 UTC' );
				if ( false === $file_ts || $file_ts >= $cutoff ) { continue; }
				$channel = self::channel_from_path( $file, $root );
				if ( $channel === '' || self::under_legal_hold( $channel, $file, $month ) ) { continue; }
				if ( $dry_run || @unlink( $file ) ) {
					if ( ! $dry_run ) {
						$partition = self::partition_from_path( $file, $root, $month );
						if ( is_array( $partition ) ) { self::expire_partition_rows( $partition ); }
					}
					$deleted++;
				}
			}
		} catch ( \Throwable $e ) {
			self::operational_failure( 'conversation_archive_retention_failed', $e );
		}
		return $deleted;
	}

	/** Export decrypted entries for one authorized account/contact/month selection. */
	public static function export_conversation( string $channel, string $account_id, string $peer_uid, string $month, callable $authorize ) {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — export requires an explicit caller authorization decision and never exposes raw archive paths.
		$key = self::archive_key();
		if ( $key === '' ) {
			return new WP_Error( 'archive_key_missing', 'Kho lưu trữ chưa sẵn sàng để xuất dữ liệu.', array( 'status' => 503, 'hint' => 'Cấu hình khóa archive rồi thử lại.', 'help_code' => 'gateway_degraded' ) );
		}
		$expected_account_key = 'a_' . self::hash_identifier( $account_id, $key );
		$expected_peer_key = 'p_' . self::hash_identifier( $peer_uid, $key );
		$context = array(
			'blog_id'     => (int) get_current_blog_id(),
			'channel'     => sanitize_key( $channel ),
			'account_key' => $key !== '' ? 'a_' . self::hash_identifier( $account_id, $key ) : '',
			'peer_key'    => $key !== '' ? 'p_' . self::hash_identifier( $peer_uid, $key ) : '',
			'month'       => $month,
		);
		if ( ! is_callable( $authorize ) || ! call_user_func( $authorize, $context ) ) {
			return new WP_Error( 'permission_denied', 'Bạn không có quyền xuất hội thoại lưu trữ.', array( 'status' => 403, 'hint' => 'Yêu cầu quyền xuất dữ liệu hội thoại trong CRM.', 'help_code' => 'permission_denied' ) );
		}
		$file = self::archive_file( $channel, $account_id, $peer_uid, $month );
		if ( $file === '' || ! is_readable( $file ) ) { return array(); }
		$entries = array();
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) { return array(); }
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				if ( strlen( $line ) > self::MAX_LINE_BYTES + 1 ) { continue; }
				$entry = json_decode( trim( $line ), true );
				if ( ! is_array( $entry ) || empty( $entry['content_ciphertext'] ) ) { continue; }
				if ( (string) ( $entry['channel'] ?? '' ) !== sanitize_key( $channel ) || (int) ( $entry['blog_id'] ?? 0 ) !== (int) get_current_blog_id() || (string) ( $entry['account_key'] ?? '' ) !== $expected_account_key || (string) ( $entry['peer_key'] ?? '' ) !== $expected_peer_key ) { continue; }
				$entry_key = self::archive_key_for_version( (string) ( $entry['archive_key_version'] ?? 'v1' ), $key );
				$plain = BizCity_Codec::decrypt_json_payload( (string) $entry['content_ciphertext'], $entry_key, self::PREFIX, 'bizcity-channel-conversation' );
				if ( is_array( $plain ) ) { $entry['content'] = $plain; unset( $entry['content_ciphertext'] ); $entries[] = $entry; }
			}
		} finally {
			fclose( $handle );
		}
		return $entries;
	}

	/** Reconcile one account/contact partition against canonical CRM message IDs. */
	public static function reconcile_partition( string $channel, string $account_id, string $peer_uid, callable $authorize ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — bounded maintenance reconciliation compares archive metadata with SQL without decrypting content.
		$key = self::archive_key();
		$normalized_channel = sanitize_key( $channel );
		if ( $key === '' ) { return array( 'ok' => false, 'code' => 'archive_key_missing' ); }
		$expected_account_key = 'a_' . self::hash_identifier( $account_id, $key );
		$expected_peer_key = 'p_' . self::hash_identifier( $peer_uid, $key );
		$context = array(
			'blog_id'     => (int) get_current_blog_id(),
			'channel'     => $normalized_channel,
			'account_key' => $key !== '' ? 'a_' . self::hash_identifier( $account_id, $key ) : '',
			'peer_key'    => $key !== '' ? 'p_' . self::hash_identifier( $peer_uid, $key ) : '',
		);
		if ( $account_id === '' || $peer_uid === '' || ! in_array( $normalized_channel, self::CHANNELS, true ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		if ( ! is_callable( $authorize ) || ! call_user_func( $authorize, $context ) ) {
			return array( 'ok' => false, 'code' => 'permission_denied' );
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array( 'ok' => false, 'code' => 'module_not_loaded' );
		}
		$dir = self::archive_directory_path( $normalized_channel, $account_id, $peer_uid );
		$archive_ids = array();
		$duplicate_events = 0;
		$malformed_lines = 0;
		$grant_key_missing = 0;
		$files_scanned = 0;
		if ( $dir !== '' && is_dir( $dir ) ) {
			$files = glob( $dir . DIRECTORY_SEPARATOR . '*.jsonl' );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$files_scanned++;
				$handle = @fopen( $file, 'rb' );
				if ( false === $handle ) { continue; }
				if ( ! @flock( $handle, LOCK_SH ) ) { fclose( $handle ); continue; }
				while ( false !== ( $line = fgets( $handle ) ) ) {
					if ( strlen( $line ) > self::MAX_LINE_BYTES + 1 ) { $malformed_lines++; continue; }
					$entry = json_decode( trim( $line ), true );
					$message_id = is_array( $entry ) ? (int) ( $entry['crm_message_id'] ?? 0 ) : 0;
					if ( ! is_array( $entry ) || $message_id <= 0 ) { $malformed_lines++; continue; }
					if ( (string) ( $entry['channel'] ?? '' ) !== $normalized_channel || (int) ( $entry['blog_id'] ?? 0 ) !== (int) get_current_blog_id() || (string) ( $entry['account_key'] ?? '' ) !== $expected_account_key || (string) ( $entry['peer_key'] ?? '' ) !== $expected_peer_key ) { $malformed_lines++; continue; }
					if ( ! preg_match( '/^a_[a-f0-9]{64}$/i', (string) ( $entry['grant_account_key'] ?? '' ) ) ) { $grant_key_missing++; }
					$event_key = $message_id . '|' . (string) ( $entry['event_type'] ?? 'message' ) . '|' . (string) ( $entry['event_uuid'] ?? '' );
					if ( isset( $archive_ids[ $message_id ] ) && isset( $archive_ids[ $message_id ][ $event_key ] ) ) { $duplicate_events++; }
					$archive_ids[ $message_id ][ $event_key ] = true;
				}
				@flock( $handle, LOCK_UN );
				fclose( $handle );
			}
		}
		global $wpdb;
		$message_table = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$conversation_table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$inbox_table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$contact_inbox_table = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$sql_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT m.id
			   FROM `{$message_table}` m
			   JOIN `{$conversation_table}` c ON c.id = m.conversation_id
			   JOIN `{$inbox_table}` i ON i.id = c.inbox_id
			   LEFT JOIN `{$contact_inbox_table}` ci ON ci.id = c.contact_inbox_id
			  WHERE i.channel_type = %s AND i.channel_ref_id = %s AND ci.source_id = %s
			  ORDER BY m.id ASC
			  LIMIT %d",
			$normalized_channel,
			$account_id,
			$peer_uid,
			self::MAX_RECONCILE_IDS + 1
		) );
		$sql_ids = array_values( array_filter( array_map( 'intval', is_array( $sql_ids ) ? $sql_ids : array() ) ) );
		$archive_message_ids = array_map( 'intval', array_keys( $archive_ids ) );
		$missing = array_values( array_diff( $sql_ids, $archive_message_ids ) );
		$orphan = array_values( array_diff( $archive_message_ids, $sql_ids ) );
		$truncated = count( $sql_ids ) > self::MAX_RECONCILE_IDS;
		if ( $truncated ) { $sql_ids = array_slice( $sql_ids, 0, self::MAX_RECONCILE_IDS ); }
		$missing = array_slice( $missing, 0, self::MAX_RECONCILE_IDS );
		$orphan = array_slice( $orphan, 0, self::MAX_RECONCILE_IDS );
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				'messenger' === $normalized_channel ? BizCity_Channel_File_Logger::CH_MESSENGER : BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'conversation_archive_reconciled',
				'Archive metadata reconciliation completed.',
				array( 'files_scanned' => $files_scanned, 'sql_messages' => count( $sql_ids ), 'archive_messages' => count( $archive_message_ids ), 'missing' => count( $missing ), 'orphan' => count( $orphan ), 'duplicate_events' => $duplicate_events, 'malformed_lines' => $malformed_lines, 'grant_key_missing' => $grant_key_missing )
			);
		}
		return array( 'ok' => true, 'channel' => $normalized_channel, 'files_scanned' => $files_scanned, 'sql_message_count' => count( $sql_ids ), 'archive_message_count' => count( $archive_message_ids ), 'missing_crm_message_ids' => $missing, 'orphan_archive_message_ids' => $orphan, 'duplicate_events' => $duplicate_events, 'malformed_lines' => $malformed_lines, 'grant_key_missing' => $grant_key_missing, 'truncated' => $truncated );
	}

	/**
	 * Build a bounded, read-only plan for a future grant-key archive rewrite.
	 *
	 * This method never writes JSONL, receipts or ledger rows. It deliberately
	 * exposes counts only; raw account IDs, paths, offsets and hashes stay inside
	 * the maintenance owner.
	 */
	public static function plan_grant_key_rewrite( string $channel, string $account_id, string $peer_uid, callable $authorize, int $limit = self::MAX_RECONCILE_IDS ): array {
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-R0 — inventory a partition before any receipt-safe staged rewrite is allowed.
		$key = self::archive_key();
		$normalized_channel = sanitize_key( $channel );
		$policy_channel = 'messenger' === $normalized_channel ? 'facebook' : $normalized_channel;
		$limit = max( 1, min( self::MAX_RECONCILE_IDS, $limit ) );
		$base = array(
			'ok' => false,
			'status' => 'blocked',
			'channel' => $normalized_channel,
			'rows_scanned' => 0,
			'rows_rewritten' => 0,
			'grant_key_missing' => 0,
			'grant_key_mismatch' => 0,
			'malformed_rows' => 0,
			'duplicate_events' => 0,
			'legal_hold_files' => 0,
			'files_scanned' => 0,
			'source_bytes' => 0,
			'staged_bytes' => 0,
			'truncated' => false,
			'coverage_complete' => false,
			'rollback_ready' => false,
		);
		if ( $key === '' ) { $base['reason'] = 'archive_key_missing'; return $base; }
		if ( $account_id === '' || $peer_uid === '' || ! in_array( $normalized_channel, self::CHANNELS, true ) ) { $base['reason'] = 'invalid_param'; return $base; }
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) { $base['reason'] = 'channel_grant_owner_unavailable'; return $base; }
		$legacy_account_key = 'a_' . self::hash_identifier( $account_id, $key );
		$peer_key = 'p_' . self::hash_identifier( $peer_uid, $key );
		$grant_account_key = BizCity_Channel_User_Grant::account_key( $policy_channel, $account_id, (int) get_current_blog_id() );
		if ( $grant_account_key === '' ) { $base['reason'] = 'grant_account_key_unavailable'; return $base; }
		$context = array( 'blog_id' => (int) get_current_blog_id(), 'channel' => $normalized_channel, 'account_key' => $legacy_account_key, 'peer_key' => $peer_key );
		if ( ! is_callable( $authorize ) || ! call_user_func( $authorize, $context ) ) { $base['reason'] = 'permission_denied'; return $base; }
		$dir = self::archive_directory_path( $normalized_channel, $account_id, $peer_uid );
		if ( $dir === '' || ! is_dir( $dir ) ) { $base['ok'] = true; $base['status'] = 'ready'; $base['coverage_complete'] = true; $base['rollback_ready'] = true; $base['reason'] = 'partition_empty'; return $base; }
		$files = glob( $dir . DIRECTORY_SEPARATOR . '*.jsonl' );
		$seen = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( $base['files_scanned'] >= $limit ) { $base['truncated'] = true; break; }
			$base['files_scanned']++;
			$month = basename( $file, '.jsonl' );
			if ( self::under_legal_hold( $normalized_channel, $file, $month ) ) { $base['legal_hold_files']++; continue; }
			$handle = @fopen( $file, 'rb' );
			if ( false === $handle ) { $base['malformed_rows']++; continue; }
			while ( false !== ( $line = fgets( $handle ) ) ) {
				if ( $base['rows_scanned'] >= $limit ) { $base['truncated'] = true; break; }
				$base['rows_scanned']++;
				$base['source_bytes'] += strlen( $line );
				if ( strlen( $line ) > self::MAX_LINE_BYTES + 1 ) { $base['malformed_rows']++; continue; }
				$entry = json_decode( trim( $line ), true );
				$record_id = is_array( $entry ) ? (string) ( $entry['record_id'] ?? '' ) : '';
				$event_uuid = is_array( $entry ) ? (string) ( $entry['event_uuid'] ?? '' ) : '';
				if ( ! is_array( $entry ) || $record_id === '' || $event_uuid === '' || (int) ( $entry['blog_id'] ?? 0 ) !== (int) get_current_blog_id() || (string) ( $entry['channel'] ?? '' ) !== $normalized_channel || (string) ( $entry['account_key'] ?? '' ) !== $legacy_account_key || (string) ( $entry['peer_key'] ?? '' ) !== $peer_key ) { $base['malformed_rows']++; continue; }
				$identity = $record_id . '|' . $event_uuid;
				if ( isset( $seen[ $identity ] ) ) { $base['duplicate_events']++; }
				$seen[ $identity ] = true;
				$current_grant_key = (string) ( $entry['grant_account_key'] ?? '' );
				if ( $current_grant_key === '' ) {
					$entry['grant_account_key'] = $grant_account_key;
					$base['grant_key_missing']++;
					$base['rows_rewritten']++;
				} elseif ( ! hash_equals( strtolower( $current_grant_key ), strtolower( $grant_account_key ) ) ) {
					$base['grant_key_mismatch']++;
				}
				$staged_line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $staged_line ) || $staged_line === '' ) { $base['malformed_rows']++; continue; }
				$base['staged_bytes'] += strlen( $staged_line ) + 1;
			}
			fclose( $handle );
			if ( $base['truncated'] ) { break; }
		}
		$base['coverage_complete'] = ! $base['truncated'];
		$base['rollback_ready'] = $base['coverage_complete'] && 0 === $base['legal_hold_files'];
		$base['ok'] = true;
		if ( ! $base['coverage_complete'] ) { $base['status'] = 'blocked'; $base['reason'] = 'plan_truncated'; }
		elseif ( $base['legal_hold_files'] > 0 ) { $base['status'] = 'blocked'; $base['reason'] = 'legal_hold_active'; }
		elseif ( $base['malformed_rows'] > 0 ) { $base['status'] = 'blocked'; $base['reason'] = 'malformed_rows_present'; }
		elseif ( $base['duplicate_events'] > 0 ) { $base['status'] = 'blocked'; $base['reason'] = 'duplicate_events_present'; }
		elseif ( $base['grant_key_mismatch'] > 0 ) { $base['status'] = 'blocked'; $base['reason'] = 'grant_key_mismatch_present'; }
		else { $base['status'] = 'ready'; $base['reason'] = 0 === $base['grant_key_missing'] ? 'grant_keys_complete' : 'staged_backfill_planned'; }
		return $base;
	}

	/** Erase one conversation from all monthly files in an authorized account/contact partition. */
	public static function erase_conversation( string $channel, string $account_id, string $peer_uid, int $conversation_id, callable $authorize ): array {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — legal erasure is the explicit append-only exception and rewrites files atomically under an authorization callback.
		$key = self::archive_key();
		$normalized_channel = sanitize_key( $channel );
		if ( $key === '' ) { return array( 'ok' => false, 'code' => 'archive_key_missing', 'removed' => 0, 'skipped_legal_hold' => 0 ); }
		$context = array(
			'blog_id'         => (int) get_current_blog_id(),
			'channel'         => $normalized_channel,
			'account_key'     => $key !== '' ? 'a_' . self::hash_identifier( $account_id, $key ) : '',
			'peer_key'        => $key !== '' ? 'p_' . self::hash_identifier( $peer_uid, $key ) : '',
			'conversation_id' => $conversation_id,
		);
		if ( $conversation_id <= 0 || $account_id === '' || $peer_uid === '' || ! in_array( $normalized_channel, self::CHANNELS, true ) ) {
			return array( 'ok' => false, 'code' => 'invalid_param', 'removed' => 0, 'skipped_legal_hold' => 0 );
		}
		if ( ! is_callable( $authorize ) || ! call_user_func( $authorize, $context ) ) {
			return array( 'ok' => false, 'code' => 'permission_denied', 'removed' => 0, 'skipped_legal_hold' => 0 );
		}
		$dir = self::archive_directory_path( $channel, $account_id, $peer_uid );
		if ( $dir === '' || ! is_dir( $dir ) ) { return array( 'ok' => true, 'removed' => 0, 'skipped_legal_hold' => 0 ); }
		$removed = 0;
		$skipped = 0;
		$files = glob( $dir . DIRECTORY_SEPARATOR . '*.jsonl' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$month = basename( $file, '.jsonl' );
			if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) { continue; }
			if ( self::under_legal_hold( sanitize_key( $channel ), $file, $month ) ) { $skipped++; continue; }
			$result = self::erase_from_file( $file, $conversation_id );
			if ( $result < 0 ) { return array( 'ok' => false, 'code' => 'archive_erase_failed', 'removed' => $removed, 'skipped_legal_hold' => $skipped ); }
			$removed += $result;
		}
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				'messenger' === $normalized_channel ? BizCity_Channel_File_Logger::CH_MESSENGER : BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'conversation_archive_erased',
				'Authorized conversation archive erasure completed.',
				array( 'conversation_id' => $conversation_id, 'removed_rows' => $removed, 'skipped_legal_hold_files' => $skipped )
			);
		}
		return array( 'ok' => true, 'removed' => $removed, 'skipped_legal_hold' => $skipped );
	}

	/** Archive a received CRM message after its canonical SQL insert. */
	public static function archive_inbound( $event ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — archive inbound CRM message after canonical insert.
		return self::archive_message( $event, 'inbound' );
	}

	/** Archive a sent CRM message after its canonical SQL insert. */
	public static function archive_outbound( $event ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — archive outbound CRM message after canonical insert.
		return self::archive_message( $event, 'outbound' );
	}

	/** Archive a delivery transition as a new append-only lifecycle row. */
	public static function archive_delivery( $event ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — preserve sent/failed delivery transitions without rewriting message history.
		return self::archive_message( $event, 'outbound', 'delivery' );
	}

	/**
	 * Resolve a Personal CRM message and append its encrypted content.
	 *
	 * @param mixed  $event CRM event payload.
	 * @param string $direction inbound or outbound.
	 * @return bool
	 */
	private static function archive_message( $event, string $direction, string $event_type = 'message' ): bool {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — resolve Personal message and append encrypted archive row.
		$archive_channel = 'zalo_personal';
		try {
			if ( ! is_array( $event ) || empty( $event['message_id'] ) ) {
				return false;
			}
			if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_Codec' ) ) {
				return false;
			}

			global $wpdb;
			$message_table = BizCity_CRM_DB_Installer_V2::tbl_messages();
			$conversation_table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
			$inbox_table = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
			$contact_inbox_table = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT m.id AS crm_message_id, m.content, m.body, m.content_type, m.message_type,
						m.sender_type, m.sender_id, m.responder_user_id, m.status, m.event_uuid,
						m.platform_msg_id, m.external_source_id, m.payload_json, m.created_at,
						c.id AS conversation_id, c.inbox_id, i.channel_type, i.channel_ref_id,
						ci.source_id
				   FROM `{$message_table}` m
				   JOIN `{$conversation_table}` c ON c.id = m.conversation_id
				   JOIN `{$inbox_table}` i ON i.id = c.inbox_id
				   LEFT JOIN `{$contact_inbox_table}` ci ON ci.id = c.contact_inbox_id
				  WHERE m.id = %d
				  LIMIT 1",
				(int) $event['message_id']
			), ARRAY_A );

			$archive_channel = self::archive_channel_for( (string) ( is_array( $row ) ? ( $row['channel_type'] ?? '' ) : '' ) );
			if ( ! is_array( $row ) || ! in_array( $archive_channel, self::CHANNELS, true ) ) {
				return false;
			}

			$account_id = (string) ( $row['channel_ref_id'] ?? '' );
			$peer_uid   = (string) ( $row['source_id'] ?? '' );
			if ( $account_id === '' || $peer_uid === '' ) {
				return false;
			}

			$key = self::archive_key();
			if ( $key === '' ) {
				return false;
			}
			$payload = ! empty( $row['payload_json'] ) ? json_decode( (string) $row['payload_json'], true ) : array();
			$plain = array(
				'content'      => (string) ( $row['content'] ?? '' ),
				'body'         => (string) ( $row['body'] ?? '' ),
				'content_type' => (string) ( $row['content_type'] ?? 'text' ),
				'payload_json' => self::archive_payload_json( is_array( $payload ) ? $payload : array() ),
			);
			$ciphertext = BizCity_Codec::encrypt_json_payload( $plain, $key, self::PREFIX, 'bizcity-channel-conversation' );
			if ( $ciphertext === '' ) {
				return false;
			}

			$delivery_status = (string) ( $row['status'] ?? 'received' );
			if ( is_array( $payload ) && isset( $payload['delivery']['sent'] ) ) {
				$delivery_status = ! empty( $payload['delivery']['sent'] ) ? 'sent' : 'failed';
			}
			$attachment_refs = array();
			$attachment_table = BizCity_CRM_DB_Installer_V2::tbl_attachments();
			$attachments = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, file_type, data_url, thumb_url, meta_json FROM `{$attachment_table}` WHERE message_id = %d ORDER BY id ASC",
				(int) $row['crm_message_id']
			), ARRAY_A );
			foreach ( is_array( $attachments ) ? $attachments : array() as $attachment ) {
				// [2026-08-24 Johnny Chu] PHASE-0.39F-F1 — keep attachment identity/type/size evidence without persisting provider URLs.
				$meta = ! empty( $attachment['meta_json'] ) ? json_decode( (string) $attachment['meta_json'], true ) : array();
				$meta = is_array( $meta ) ? $meta : array();
				$attachment_refs[] = array(
					'attachment_id' => (int) ( $attachment['id'] ?? 0 ),
					'file_type'     => sanitize_key( (string) ( $attachment['file_type'] ?? 'file' ) ),
					'data_hash'     => self::hash_identifier( (string) ( $attachment['data_url'] ?? '' ), $key ),
					'thumb_hash'    => self::hash_identifier( (string) ( $attachment['thumb_url'] ?? '' ), $key ),
					'size_bytes'    => isset( $meta['size'] ) ? max( 0, (int) $meta['size'] ) : 0,
					'mime'          => sanitize_text_field( (string) ( $meta['mime'] ?? '' ) ),
				);
			}

			$entry = array(
				'schema_version'          => 2,
				'archive_key_version'     => self::archive_key_version(),
				'event_type'              => $event_type,
				'event_uuid'              => (string) ( $event['event_uuid'] ?? $row['event_uuid'] ?? '' ),
				'trace_id'                => (string) ( $event['trace_id'] ?? '' ),
				'blog_id'                 => (int) get_current_blog_id(),
				'channel'                => $archive_channel,
				'platform'               => strtoupper( $archive_channel ),
				'account_key'             => 'a_' . self::hash_identifier( $account_id, $key ),
				// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A — retain the archive partition key and add the tenant/channel grant key for Context Bank ACL matching.
				'grant_account_key'       => class_exists( 'BizCity_Channel_User_Grant' ) ? BizCity_Channel_User_Grant::account_key( 'messenger' === $archive_channel ? 'facebook' : $archive_channel, $account_id, (int) get_current_blog_id() ) : '',
				'peer_key'                => 'p_' . self::hash_identifier( $peer_uid, $key ),
				'conversation_id'         => (int) $row['conversation_id'],
				// [2026-08-29 Johnny Chu] PHASE-0.39F-CONTEXT — keep inbox correlation aligned with archive receipt writes.
				'inbox_id'                => (int) $row['inbox_id'],
				'crm_message_id'          => (int) $row['crm_message_id'],
				'provider_message_id_hash'=> self::hash_identifier( (string) ( $row['platform_msg_id'] ?? $row['external_source_id'] ?? '' ), $key ),
				'direction'               => $direction,
				'actor_type'              => self::actor_type( $row, $direction ),
				'actor_user_id'           => (int) ( $row['responder_user_id'] ?? $row['sender_id'] ?? 0 ),
				'content_ciphertext'      => $ciphertext,
				'attachment_refs'         => $attachment_refs,
				'delivery_status'         => $delivery_status,
				'occurred_at'             => (string) ( $row['created_at'] ?? current_time( 'mysql' ) ),
			);
			$entry['record_id'] = 'crm_' . (int) $entry['crm_message_id'] . '_' . substr( hash( 'sha256', (string) $entry['event_uuid'] ), 0, 24 );
			$archive_receipt = self::append_with_receipt( $entry, $archive_channel, $account_id, $peer_uid );
			if ( ! is_array( $archive_receipt ) ) {
				return false;
			}
			if ( 'message' !== $event_type ) {
				do_action( 'bizcity_channel_archive_written', array( 'entry' => $entry, 'receipt' => $archive_receipt ) );
				return true;
			}
			$receipt_ok = self::write_receipt( $entry, $key, $archive_channel, $account_id, $peer_uid, $archive_receipt );
			if ( ! $receipt_ok ) {
				self::operational_failure( 'conversation_archive_receipt_failed', new Exception( 'archive_receipt_failed' ), $archive_channel );
				return false;
			}
			if ( class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'mark_message_archived' ) ) {
				BizCity_CRM_Repository::mark_message_archived( (int) $row['crm_message_id'], $archive_channel, $entry, $key );
			}
			do_action( 'bizcity_channel_archive_written', array( 'entry' => $entry, 'receipt' => $archive_receipt ) );
			return true;
		} catch ( \Throwable $e ) {
			self::operational_failure( 'conversation_archive_failed', $e, $archive_channel );
			return false;
		}
	}

	private static function actor_type( array $row, string $direction ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — normalize archive actor classification.
		if ( $direction === 'inbound' ) {
			return 'customer';
		}
		$sender = (string) ( $row['sender_type'] ?? '' );
		if ( $sender === 'bot' ) {
			return 'ai';
		}
		if ( $sender === 'system' ) {
			return 'system';
		}
		return 'agent';
	}

	/** Return a valid, bounded payload JSON envelope for archive schema v2. */
	private static function archive_payload_json( array $payload ): string {
		if ( empty( $payload ) ) { return ''; }
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) { return ''; }
		if ( strlen( $json ) <= 8192 ) { return $json; }
		$bounded = array( 'truncated' => true );
		if ( isset( $payload['delivery'] ) && is_array( $payload['delivery'] ) ) {
			$bounded['delivery'] = $payload['delivery'];
		}
		$json = wp_json_encode( $bounded, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) && strlen( $json ) <= 8192 ? $json : '{"truncated":true}';
	}

	/** Read one encrypted cold message without writing it back to SQL. */
	public static function rehydrate_message( int $message_id, string $channel, string $account_id, string $peer_uid, string $month ): ?array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F2 — rehydrate only the authorized message locator; never bulk restore cold content.
		if ( $message_id <= 0 || $channel === '' || $account_id === '' || $peer_uid === '' || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return null;
		}
		$key = self::archive_key();
		$file = self::archive_file( self::archive_channel_for( $channel ), $account_id, $peer_uid, $month );
		return self::rehydrate_message_from_file( $message_id, $file, $key );
	}

	/**
	 * Read one cold message from the immutable hashed partition stored in SQL.
	 *
	 * @param int    $message_id CRM message ID.
	 * @param string $channel Archive channel slug.
	 * @param string $account_key Stored `a_<hmac>` partition key.
	 * @param string $peer_key Stored `p_<hmac>` partition key.
	 * @param string $month Stored archive month.
	 * @return array|null
	 */
	public static function rehydrate_message_from_keys( int $message_id, string $channel, string $account_key, string $peer_key, string $month ): ?array {
		// [2026-09-19 01:00 PM Johnny Chu] PHASE-0.56-H-04 — read from immutable SQL archive keys so rebinding an inbox cannot move the cold partition.
		if ( $message_id <= 0 ) { return null; }
		$key = self::archive_key();
		$file = self::archive_file_from_keys( $channel, $account_key, $peer_key, $month );
		return self::rehydrate_message_from_file( $message_id, $file, $key );
	}

	/** Read and decrypt one message from a validated archive file. */
	private static function rehydrate_message_from_file( int $message_id, string $file, string $key ): ?array {
		if ( $key === '' || $file === '' || ! is_readable( $file ) ) {
			return null;
		}
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) { return null; }
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				if ( strlen( $line ) > self::MAX_LINE_BYTES + 1 ) { continue; }
				$entry = json_decode( trim( $line ), true );
				if ( ! is_array( $entry ) || (int) ( $entry['crm_message_id'] ?? 0 ) !== $message_id || (string) ( $entry['event_type'] ?? '' ) !== 'message' ) { continue; }
				$entry_key = self::archive_key_for_version( (string) ( $entry['archive_key_version'] ?? 'v1' ), $key );
				$plain = BizCity_Codec::decrypt_json_payload( (string) ( $entry['content_ciphertext'] ?? '' ), $entry_key, self::PREFIX, 'bizcity-channel-conversation' );
				return is_array( $plain ) ? array_merge( $entry, array( 'content' => $plain['content'] ?? '', 'body' => $plain['body'] ?? '', 'content_type' => $plain['content_type'] ?? 'text', 'payload_json' => $plain['payload_json'] ?? '' ) ) : null;
			}
		} finally {
			fclose( $handle );
		}
		return null;
	}

	private static function archive_channel_for( string $channel ): string {
		$channel = sanitize_key( $channel );
		$aliases = array(
			'email_imap'     => 'email',
			'web_widget'     => 'webchat',
			'fb_mess'        => 'messenger',
			'facebook_messenger' => 'messenger',
			'whatsapp_cloud' => 'whatsapp',
		);
		return isset( $aliases[ $channel ] ) ? $aliases[ $channel ] : $channel;
	}

	private static function archive_key(): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — resolve canonical archive encryption key.
		$key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$key = (string) apply_filters( 'bizcity_channel_archive_key', $key );
		return $key;
	}

	/** Return a non-secret archive key version derived from the active key. */
	public static function archive_key_version(): string {
		$key = self::archive_key();
		return '' !== $key ? 'k_' . substr( hash( 'sha256', $key ), 0, 16 ) : 'v1';
	}

	/**
	 * Resolve an archive key by receipt/entry version.
	 *
	 * Legacy `v1` rows fall back to the active key when no keyring exists. A
	 * planned salt rotation must call rotate_archive_key() before the salt is
	 * changed so the old key is wrapped by the new active key.
	 */
	private static function archive_key_for_version( string $version, string $fallback = '' ): string {
		$current = self::archive_key();
		if ( '' === $version || $version === self::archive_key_version() ) { return $current; }
		$keyring = function_exists( 'get_option' ) ? get_option( self::KEYRING_OPTION, array() ) : array();
		if ( ! is_array( $keyring ) || empty( $keyring[ $version ]['wrapped'] ) || '' === $current || ! class_exists( 'BizCity_Codec' ) ) {
			return $fallback !== '' ? $fallback : ( 'v1' === $version ? $current : '' );
		}
		$decoded = BizCity_Codec::decrypt_json_payload( (string) $keyring[ $version ]['wrapped'], $current, self::PREFIX . 'keyring_', 'bizcity-channel-archive-keyring' );
		return is_array( $decoded ) ? (string) ( $decoded['key'] ?? '' ) : '';
	}

	/**
	 * Register the active key and optionally wrap a previous key for rotation.
	 * The previous key is operator-supplied and is never logged or returned.
	 */
	public static function rotate_archive_key( string $previous_key = '' ): bool {
		$current = self::archive_key();
		if ( '' === $current || ! function_exists( 'update_option' ) || ! class_exists( 'BizCity_Codec' ) ) { return false; }
		$ring = function_exists( 'get_option' ) ? get_option( self::KEYRING_OPTION, array() ) : array();
		$ring = is_array( $ring ) ? $ring : array();
		if ( '' !== $previous_key && $previous_key !== $current ) {
			$previous_version = 'k_' . substr( hash( 'sha256', $previous_key ), 0, 16 );
			$ring[ $previous_version ] = array(
				'fingerprint' => $previous_version,
				'wrapped'     => BizCity_Codec::encrypt_json_payload( array( 'key' => $previous_key ), $current, self::PREFIX . 'keyring_', 'bizcity-channel-archive-keyring' ),
				'created_at'  => gmdate( 'c' ),
			);
			$ring['v1'] = $ring[ $previous_version ];
		}
		$version = self::archive_key_version();
		$ring[ $version ] = array(
			'fingerprint' => $version,
			'wrapped'     => BizCity_Codec::encrypt_json_payload( array( 'key' => $current ), $current, self::PREFIX . 'keyring_', 'bizcity-channel-archive-keyring' ),
			'created_at'  => gmdate( 'c' ),
		);
		return (bool) update_option( self::KEYRING_OPTION, $ring, false );
	}

	private static function hash_identifier( string $value, string $key ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — derive non-PII HMAC path identifiers.
		return class_exists( 'BizCity_Codec' )
			? (string) BizCity_Codec::hmac_sha256( $value, $key, false )
			: hash_hmac( 'sha256', $value, $key );
	}

	private static function append( array $entry, string $channel, string $account_id, string $peer_uid ): bool {
		return is_array( self::append_with_receipt( $entry, $channel, $account_id, $peer_uid ) );
	}

	/**
	 * Append one archive row and return its lock-captured pointer receipt.
	 *
	 * @return array<string,mixed>|false
	 */
	private static function append_with_receipt( array $entry, string $channel, string $account_id, string $peer_uid ) {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — capture archive offset/hash while LOCK_EX is held for Context Bank pointer admission.
		$dir = self::directory( $channel, $account_id, $peer_uid );
		if ( $dir === '' ) {
			return false;
		}
		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $line ) || $line === '' ) {
			return false;
		}
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — bound one archive row before touching the filesystem.
		if ( strlen( $line ) > self::MAX_LINE_BYTES ) {
			self::operational_failure( 'conversation_archive_row_too_large', new Exception( 'archive_row_too_large' ), $channel );
			return false;
		}
		$file = $dir . DIRECTORY_SEPARATOR . gmdate( 'Y-m' ) . '.jsonl';
		$durable_line = $line . "\n";
		$handle = @fopen( $file, 'ab' );
		if ( ! $handle || ! @flock( $handle, LOCK_EX ) ) {
			if ( $handle ) {
				@fclose( $handle );
			}
			return false;
		}
		$file_stat = @fstat( $handle );
		$offset = is_array( $file_stat ) && isset( $file_stat['size'] ) ? (int) $file_stat['size'] : 0;
		$written = @fwrite( $handle, $durable_line );
		@fflush( $handle );
		$write_ok = false !== $written && (int) $written === strlen( $durable_line );
		$receipt = $write_ok ? array(
			'contract_id'   => 'core.channel_gateway.context_corpus',
			'record_id'     => (string) ( $entry['record_id'] ?? '' ),
			'event_uuid'    => (string) ( $entry['event_uuid'] ?? '' ),
			'relative_file' => $channel . '/a_' . self::hash_identifier( $account_id, self::archive_key() ) . '/p_' . self::hash_identifier( $peer_uid, self::archive_key() ) . '/' . gmdate( 'Y-m' ) . '.jsonl',
			'byte_offset'   => $offset,
			'line_bytes'    => strlen( $durable_line ),
			'row_hash'      => hash( 'sha256', $durable_line ),
			'content_hash'  => hash( 'sha256', $line ),
			'occurred_at'   => (string) ( $entry['occurred_at'] ?? gmdate( 'c' ) ),
			'operation'     => (string) ( $entry['operation'] ?? 'upsert' ),
			'blog_id'       => (int) get_current_blog_id(),
			'channel'       => $channel,
			'account_key'   => (string) ( $entry['account_key'] ?? '' ),
			'grant_account_key' => (string) ( $entry['grant_account_key'] ?? '' ),
			'peer_key'      => (string) ( $entry['peer_key'] ?? '' ),
		) : false;
		@flock( $handle, LOCK_UN );
		@fclose( $handle );
		return is_array( $receipt ) && $receipt['record_id'] !== '' && $receipt['event_uuid'] !== '' ? $receipt : false;
	}

	/**
	 * Verify one archive receipt against the exact stored JSONL line.
	 *
	 * @param array<string,mixed> $receipt Lock-captured archive receipt.
	 * @param int                 $max_ms Read budget in milliseconds.
	 * @return array<string,mixed>
	 */
	public static function read_receipt( array $receipt, int $max_ms = 100 ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB4.2 — verify archive pointer scope/hash before any Context Bank ledger follow succeeds.
		$fail = static function ( $reason ) { return array( 'ok' => false, 'reason' => (string) $reason ); };
		$started = microtime( true );
		$relative = (string) ( $receipt['relative_file'] ?? '' );
		$blog_id = (int) ( $receipt['blog_id'] ?? 0 );
		$current_blog_id = (int) get_current_blog_id();
		if ( (string) ( $receipt['contract_id'] ?? '' ) !== 'core.channel_gateway.context_corpus' || $blog_id <= 0 || $blog_id !== $current_blog_id || (int) ( $receipt['byte_offset'] ?? -1 ) < 0 || ! preg_match( '#^(facebook|messenger|zalo_oa|zalo_personal|webchat|email|instagram|whatsapp)/a_[a-f0-9]{64}/p_[a-f0-9]{64}/\d{4}-\d{2}\.jsonl$#i', $relative ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) ( $receipt['row_hash'] ?? '' ) ) ) {
			return $fail( 'archive_receipt_shape_invalid' );
		}
		$root = self::root_directory();
		$path = $root . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative );
		if ( $root === '' || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $fail( 'archive_pointer_missing' );
		}
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle || false === @fseek( $handle, (int) $receipt['byte_offset'] ) ) {
			if ( $handle ) { @fclose( $handle ); }
			return $fail( 'archive_pointer_seek_failed' );
		}
		$line = @fgets( $handle, self::MAX_LINE_BYTES + 2 );
		@fclose( $handle );
		if ( ( microtime( true ) - $started ) * 1000 > max( 1, min( 1000, $max_ms ) ) ) {
			return $fail( 'archive_pointer_budget_exhausted' );
		}
		if ( ! is_string( $line ) || ! hash_equals( strtolower( (string) $receipt['row_hash'] ), strtolower( hash( 'sha256', $line ) ) ) || ( ! empty( $receipt['content_hash'] ) && ! hash_equals( strtolower( (string) $receipt['content_hash'] ), strtolower( hash( 'sha256', rtrim( $line, "\r\n" ) ) ) ) ) ) {
			return $fail( 'archive_pointer_hash_mismatch' );
		}
		$entry = json_decode( trim( $line ), true );
		if ( ! is_array( $entry ) || (int) ( $entry['blog_id'] ?? -1 ) !== $blog_id || (string) ( $entry['record_id'] ?? '' ) !== (string) ( $receipt['record_id'] ?? '' ) || (string) ( $entry['event_uuid'] ?? '' ) !== (string) ( $receipt['event_uuid'] ?? '' ) ) {
			return $fail( 'archive_pointer_envelope_mismatch' );
		}
		return array( 'ok' => true, 'operation' => (string) ( $entry['operation'] ?? 'upsert' ), 'entry' => array( 'record_id' => (string) $entry['record_id'], 'event_uuid' => (string) $entry['event_uuid'], 'blog_id' => $blog_id, 'grant_account_key' => (string) ( $entry['grant_account_key'] ?? '' ) ) );
	}

	/**
	 * Read a bounded batch of archive pointers, grouped by monthly file.
	 *
	 * Pointers with a byte offset use one open handle per file and seek directly
	 * to the durable line. Legacy pointers without an offset trigger one linear
	 * scan per file, never one scan per message. The method is read-only and
	 * returns partial results when the time budget is exhausted.
	 *
	 * @param array<int,array<string,mixed>> $pointers
	 * @param int                            $max_ms
	 * @return array{items:array<int,array<string,mixed>>,partial:bool,files_scanned:int}
	 */
	public static function read_batch( array $pointers, int $max_ms = 800 ): array {
		// [2026-09-19 01:30 PM Johnny Chu] PHASE-0.56-H-05 — batch cold reads by immutable archive file and bounded time budget.
		$started = microtime( true );
		$budget = max( 1, min( 5000, $max_ms ) );
		$items = array();
		$groups = array();
		foreach ( array_values( $pointers ) as $index => $pointer ) {
			if ( ! is_array( $pointer ) ) {
				$items[ $index ] = array( 'ok' => false, 'cold_error' => 'invalid_pointer' );
				continue;
			}
			$file = self::pointer_file( $pointer );
			if ( '' === $file ) {
				$items[ $index ] = array( 'ok' => false, 'cold_error' => 'invalid_pointer' );
				continue;
			}
			$groups[ $file ][] = array( 'index' => $index, 'pointer' => $pointer );
		}

		$partial = false;
		$files_scanned = 0;
		$key = self::archive_key();
		foreach ( $groups as $file => $group ) {
			if ( ( microtime( true ) - $started ) * 1000 >= $budget ) {
				$partial = true;
				break;
			}
			if ( ! is_readable( $file ) || false === ( $handle = @fopen( $file, 'rb' ) ) ) {
				foreach ( $group as $request ) { $items[ $request['index'] ] = array( 'ok' => false, 'cold_error' => 'archive_pointer_missing' ); }
				continue;
			}
			$files_scanned++;
			$offset_requests = array();
			$scan_requests = array();
			foreach ( $group as $request ) {
				$offset = isset( $request['pointer']['byte_offset'] ) ? (int) $request['pointer']['byte_offset'] : -1;
				if ( $offset >= 0 ) { $offset_requests[] = $request; } else { $scan_requests[] = $request; }
			}

			foreach ( $offset_requests as $request ) {
				if ( ( microtime( true ) - $started ) * 1000 >= $budget ) { $partial = true; break; }
				$pointer = $request['pointer'];
				if ( false === @fseek( $handle, (int) $pointer['byte_offset'] ) ) {
					$items[ $request['index'] ] = array( 'ok' => false, 'cold_error' => 'archive_pointer_seek_failed' );
					continue;
				}
				$line = @fgets( $handle, self::MAX_LINE_BYTES + 2 );
				$items[ $request['index'] ] = self::decode_batch_line( $line, $pointer, $key );
			}

			if ( ! $partial && $scan_requests ) {
				$wanted = array();
				$scan_lookup = array();
				foreach ( $scan_requests as $request ) {
					$wanted[ $request['index'] ] = true;
					$pointer = $request['pointer'];
					$identity = isset( $pointer['crm_message_id'] ) ? 'id:' . (string) $pointer['crm_message_id'] : ( isset( $pointer['record_id'] ) ? 'record:' . (string) $pointer['record_id'] : ( isset( $pointer['event_uuid'] ) ? 'event:' . (string) $pointer['event_uuid'] : '' ) );
					if ( '' !== $identity ) { $scan_lookup[ $identity ][] = $request; }
				}
				@rewind( $handle );
				while ( $wanted && false !== ( $line = @fgets( $handle, self::MAX_LINE_BYTES + 2 ) ) ) {
					if ( ( microtime( true ) - $started ) * 1000 >= $budget ) { $partial = true; break; }
					$envelope = json_decode( trim( $line ), true );
					if ( ! is_array( $envelope ) ) { continue; }
					$identity_keys = array( 'id:' . (string) ( $envelope['crm_message_id'] ?? '' ), 'record:' . (string) ( $envelope['record_id'] ?? '' ), 'event:' . (string) ( $envelope['event_uuid'] ?? '' ) );
					$candidates = array();
					foreach ( $identity_keys as $identity_key ) {
						if ( isset( $scan_lookup[ $identity_key ] ) ) { $candidates = array_merge( $candidates, $scan_lookup[ $identity_key ] ); }
					}
					if ( ! $candidates && count( $scan_lookup ) < count( $scan_requests ) ) { $candidates = $scan_requests; }
					foreach ( $candidates as $request ) {
						$index = $request['index'];
						if ( ! isset( $wanted[ $index ] ) ) { continue; }
						$decoded = self::decode_batch_line( $line, $request['pointer'], $key );
						if ( ! empty( $decoded['ok'] ) ) { $items[ $index ] = $decoded; unset( $wanted[ $index ] ); }
					}
				}
				foreach ( array_keys( $wanted ) as $index ) { $items[ $index ] = array( 'ok' => false, 'cold_error' => $partial ? 'read_budget_exhausted' : 'archive_record_missing' ); }
			}
			fclose( $handle );
		}

		if ( $partial ) {
			foreach ( array_keys( $groups ) as $file ) {
				foreach ( $groups[ $file ] as $request ) {
					if ( ! isset( $items[ $request['index'] ] ) ) { $items[ $request['index'] ] = array( 'ok' => false, 'cold_error' => 'read_budget_exhausted' ); }
				}
			}
		}
		ksort( $items );
		return array( 'items' => $items, 'partial' => $partial, 'files_scanned' => $files_scanned );
	}

	/**
	 * Plan or repair missing byte pointers on legacy CRM archive receipts.
	 *
	 * The archive line is the source of truth: no pointer is written unless the
	 * immutable hashed partition, CRM message id and stored line hash all match.
	 *
	 * @param int  $limit   Maximum receipts inspected in this bounded call.
	 * @param bool $dry_run Do not update receipts when true.
	 * @return array<string,mixed>
	 */
	public static function reconcile_legacy_receipt_pointers( int $limit = 100, bool $dry_run = true ): array {
		// [2026-09-21 05:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.56-H-11 — receipt-safe legacy pointer repair; bounded, hash-verified and dry-run by default.
		$limit = max( 1, min( 500, $limit ) );
		if ( ! $dry_run ) {
			// [2026-09-21 06:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.56-H-11 — preflight the complete batch before any receipt write; missing files, unmatched rows or integrity errors block the whole apply atomically.
			$preflight = self::reconcile_legacy_receipt_pointers( $limit, true );
			$preflight_clean = ! empty( $preflight['ok'] )
				&& (int) ( $preflight['scanned'] ?? 0 ) > 0
				&& (int) ( $preflight['matched'] ?? 0 ) === (int) ( $preflight['scanned'] ?? 0 )
				&& 0 === (int) ( $preflight['skipped'] ?? 0 )
				&& 0 === (int) ( $preflight['errors'] ?? 0 )
				&& 0 === (int) ( $preflight['files_missing'] ?? 0 )
				&& 0 === (int) ( $preflight['malformed_lines'] ?? 0 )
				&& 0 === (int) ( $preflight['hash_mismatches'] ?? 0 )
				&& 0 === (int) ( $preflight['unmatched_receipts'] ?? 0 );
			if ( ! $preflight_clean ) {
				$preflight['dry_run'] = false;
				$preflight['reason'] = 'apply_blocked_preflight';
				$preflight['repaired'] = 0;
				return $preflight;
			}
		}
		$result = array(
			'ok'              => false,
			'dry_run'         => $dry_run,
			'limit'           => $limit,
			'scanned'         => 0,
			'matched'         => 0,
			'repaired'        => 0,
			'skipped'         => 0,
			'errors'          => 0,
			'files_scanned'   => 0,
			'files_missing'   => 0,
			'archive_lines_scanned' => 0,
			'malformed_lines' => 0,
			'hash_mismatches' => 0,
			'hash_matches_exact' => 0,
			'hash_matches_without_newline' => 0,
			'unmatched_receipts' => 0,
			'partitions'       => array(),
			'coverage_complete' => false,
		);
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$result['reason'] = 'crm_schema_owner_unavailable';
			return $result;
		}
		global $wpdb;
		$receipts = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $receipts ) ) {
			$result['reason'] = 'archive_receipts_table_missing';
			return $result;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, crm_message_id, channel_type, account_key, peer_key, archive_month, line_hash
			 FROM `{$receipts}`
			 WHERE archive_status = 'written'
			   AND archive_schema_version < 2
			   AND (byte_offset IS NULL OR line_bytes IS NULL)
			 ORDER BY id ASC LIMIT %d",
			$limit
		), ARRAY_A );
		$groups = array();
		$partition_index = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result['scanned']++;
			$channel = self::archive_channel_for( (string) ( $row['channel_type'] ?? '' ) );
			$account_key = (string) ( $row['account_key'] ?? '' );
			$peer_key = (string) ( $row['peer_key'] ?? '' );
			$month = (string) ( $row['archive_month'] ?? '' );
			$file = self::archive_file_from_keys( $channel, $account_key, $peer_key, $month );
			$partition_key = $channel . '/' . $account_key . '/' . $peer_key . '/' . $month . '.jsonl';
			if ( ! isset( $partition_index[ $file ] ) ) {
				$partition_index[ $file ] = count( $result['partitions'] );
				$result['partitions'][] = array(
					'partition' => $partition_key,
					'receipts'  => 0,
					'matched'   => 0,
					'unmatched' => 0,
					'errors'    => 0,
					'file_missing' => false,
				);
			}
			$result['partitions'][ $partition_index[ $file ] ]['receipts']++;
			if ( '' === $file ) {
				$result['skipped']++;
				$result['partitions'][ $partition_index[ $file ] ]['errors']++;
				continue;
			}
			$groups[ $file ][] = $row;
		}
		foreach ( $groups as $file => $file_rows ) {
			if ( ! is_readable( $file ) || false === ( $handle = @fopen( $file, 'rb' ) ) ) {
				$result['errors'] += count( $file_rows );
				$result['files_missing']++;
				$partition_id = $partition_index[ $file ];
				$result['partitions'][ $partition_id ]['file_missing'] = true;
				$result['partitions'][ $partition_id ]['errors'] += count( $file_rows );
				continue;
			}
			$result['files_scanned']++;
			$wanted = array();
			foreach ( $file_rows as $row ) {
				$wanted[ (int) $row['crm_message_id'] ][] = $row;
			}
			$offset = 0;
			while ( false !== ( $line = @fgets( $handle ) ) ) {
				$line_bytes = strlen( $line );
				$result['archive_lines_scanned']++;
				$entry = $line_bytes <= self::MAX_LINE_BYTES + 1 ? json_decode( trim( $line ), true ) : null;
				$message_id = is_array( $entry ) ? (int) ( $entry['crm_message_id'] ?? 0 ) : 0;
				if ( ! is_array( $entry ) || $message_id <= 0 ) {
					$result['malformed_lines']++;
				}
				if ( $message_id > 0 && isset( $wanted[ $message_id ] ) ) {
					$line_hash = hash( 'sha256', $line );
					$line_hash_without_newline = hash( 'sha256', rtrim( $line, "\r\n" ) );
					foreach ( $wanted[ $message_id ] as $row ) {
						$stored_hash = strtolower( (string) $row['line_hash'] );
						$exact_hash_match = hash_equals( $stored_hash, strtolower( $line_hash ) );
						$trimmed_hash_match = hash_equals( $stored_hash, strtolower( $line_hash_without_newline ) );
						if ( ! $exact_hash_match && ! $trimmed_hash_match ) {
							$result['errors']++;
							$result['hash_mismatches']++;
							$result['partitions'][ $partition_index[ $file ] ]['errors']++;
							continue;
						}
						if ( $exact_hash_match ) {
							$result['hash_matches_exact']++;
						} else {
							$result['hash_matches_without_newline']++;
						}
						$result['matched']++;
						$result['partitions'][ $partition_index[ $file ] ]['matched']++;
						if ( ! $dry_run ) {
							$updated = $wpdb->query( $wpdb->prepare(
								"UPDATE `{$receipts}` SET line_hash = %s, byte_offset = %d, line_bytes = %d, updated_at = %s WHERE id = %d AND archive_status = 'written' AND archive_schema_version < 2 AND (byte_offset IS NULL OR line_bytes IS NULL)",
								$line_hash,
								$offset,
								$line_bytes,
								current_time( 'mysql' ),
								(int) $row['id']
							) );
							if ( false !== $updated && $updated > 0 ) {
								$result['repaired']++;
							} else {
								$result['errors']++;
							}
						}
					}
					unset( $wanted[ $message_id ] );
				}
				$offset += $line_bytes;
			}
			fclose( $handle );
			foreach ( $wanted as $unmatched ) {
				$result['skipped'] += count( $unmatched );
				$result['unmatched_receipts'] += count( $unmatched );
				$result['partitions'][ $partition_index[ $file ] ]['unmatched'] += count( $unmatched );
			}
		}
		$result['coverage_complete'] = count( $rows ) < $limit;
		$result['ok'] = true;
		$result['reason'] = $dry_run ? 'dry_run_only' : 'pointer_repair_applied';
		return $result;
	}

	/** Resolve a pointer to an existing archive file without accepting raw IDs. */
	private static function pointer_file( array $pointer ): string {
		$relative = (string) ( $pointer['relative_file'] ?? '' );
		$root = self::root_directory();
		if ( '' !== $root && preg_match( '#^(facebook|messenger|zalo_oa|zalo_personal|webchat|email|instagram|whatsapp)/a_[a-f0-9]{64}/p_[a-f0-9]{64}/\d{4}-\d{2}\.jsonl$#i', $relative ) ) {
			return $root . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $relative );
		}
		return self::archive_file_from_keys(
			(string) ( $pointer['channel'] ?? $pointer['archive_channel'] ?? '' ),
			(string) ( $pointer['account_key'] ?? $pointer['archive_account_key'] ?? '' ),
			(string) ( $pointer['peer_key'] ?? $pointer['archive_peer_key'] ?? '' ),
			(string) ( $pointer['archive_month'] ?? $pointer['month'] ?? '' )
		);
	}

	/** Decode, verify and decrypt one JSONL line for read_batch(). */
	private static function decode_batch_line( $line, array $pointer, string $key ): array {
		if ( ! is_string( $line ) || strlen( $line ) > self::MAX_LINE_BYTES + 1 ) { return array( 'ok' => false, 'cold_error' => 'line_too_large' ); }
		if ( isset( $pointer['line_bytes'] ) && (int) $pointer['line_bytes'] > 0 && (int) $pointer['line_bytes'] !== strlen( $line ) ) { return array( 'ok' => false, 'cold_error' => 'line_length_mismatch' ); }
		if ( ! empty( $pointer['row_hash'] ) && ! hash_equals( strtolower( (string) $pointer['row_hash'] ), strtolower( hash( 'sha256', $line ) ) ) ) { return array( 'ok' => false, 'cold_error' => 'line_hash_mismatch' ); }
		if ( ! empty( $pointer['content_hash'] ) && ! hash_equals( strtolower( (string) $pointer['content_hash'] ), strtolower( hash( 'sha256', rtrim( $line, "\r\n" ) ) ) ) ) { return array( 'ok' => false, 'cold_error' => 'content_hash_mismatch' ); }
		$entry = json_decode( trim( $line ), true );
		if ( ! is_array( $entry ) ) { return array( 'ok' => false, 'cold_error' => 'invalid_archive_line' ); }
		foreach ( array( 'record_id', 'event_uuid', 'crm_message_id' ) as $identity ) {
			if ( isset( $pointer[ $identity ] ) && (string) $pointer[ $identity ] !== (string) ( $entry[ $identity ] ?? '' ) ) { return array( 'ok' => false, 'cold_error' => 'archive_identity_mismatch' ); }
		}
		$entry_key = self::archive_key_for_version( (string) ( $entry['archive_key_version'] ?? 'v1' ), $key );
		$plain = ! empty( $entry['content_ciphertext'] ) && $entry_key !== '' ? BizCity_Codec::decrypt_json_payload( (string) $entry['content_ciphertext'], $entry_key, self::PREFIX, 'bizcity-channel-conversation' ) : array();
		if ( ! is_array( $plain ) ) { return array( 'ok' => false, 'cold_error' => 'archive_decrypt_failed' ); }
		return array( 'ok' => true, 'entry' => $entry, 'content' => (string) ( $plain['content'] ?? '' ), 'body' => (string) ( $plain['body'] ?? '' ), 'content_type' => (string) ( $plain['content_type'] ?? 'text' ), 'payload_json' => (string) ( $plain['payload_json'] ?? '' ) );
	}

	private static function write_receipt( array $entry, string $key, string $channel, string $account_id, string $peer_uid, array $pointer = array() ): bool {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return false;
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $table ) ) {
			return false;
		}
		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
		$month = gmdate( 'Y-m' );
		$now = current_time( 'mysql' );
		$byte_offset = isset( $pointer['byte_offset'] ) ? max( 0, (int) $pointer['byte_offset'] ) : null;
		$line_bytes = isset( $pointer['line_bytes'] ) ? max( 0, (int) $pointer['line_bytes'] ) : null;
		$line_hash = isset( $pointer['row_hash'] ) && preg_match( '/^[a-f0-9]{64}$/i', (string) $pointer['row_hash'] )
			? (string) $pointer['row_hash']
			: hash( 'sha256', is_string( $line ) ? $line . "\n" : '' );
		$offset_sql = null === $byte_offset ? 'NULL' : '%d';
		$line_bytes_sql = null === $line_bytes ? 'NULL' : '%d';
		$params = array(
			(int) $entry['crm_message_id'],
			(int) $entry['conversation_id'],
			(int) $entry['inbox_id'],
			$channel,
			'a_' . self::hash_identifier( $account_id, $key ),
			'p_' . self::hash_identifier( $peer_uid, $key ),
			$month,
			(string) ( $entry['archive_key_version'] ?? self::archive_key_version() ),
			$line_hash,
		);
		if ( null !== $byte_offset ) { $params[] = $byte_offset; }
		if ( null !== $line_bytes ) { $params[] = $line_bytes; }
		$params = array_merge( $params, array( $now, $now, $now, $now ) );
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (crm_message_id, conversation_id, inbox_id, channel_type, account_key, peer_key, archive_month, archive_schema_version, archive_key_version, line_hash, byte_offset, line_bytes, archive_status, written_at, verified_at, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %s, %s, 2, %s, %s, {$offset_sql}, {$line_bytes_sql}, 'written', %s, %s, %s, %s) ON DUPLICATE KEY UPDATE archive_status = 'written', archive_schema_version = 2, archive_key_version = VALUES(archive_key_version), line_hash = VALUES(line_hash), byte_offset = VALUES(byte_offset), line_bytes = VALUES(line_bytes), archive_month = VALUES(archive_month), verified_at = VALUES(verified_at), updated_at = VALUES(updated_at)",
			$params
		) );
		return false !== $ok;
	}

	private static function directory( string $channel, string $account_id, string $peer_uid ): string {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — resolve private HMAC-partitioned archive directory.
		$upload = wp_upload_dir();
		$base   = (string) ( $upload['basedir'] ?? '' );
		$key    = self::archive_key();
		if ( $base === '' || $key === '' ) {
			return '';
		}
		if ( ! in_array( $channel, self::CHANNELS, true ) ) {
			return '';
		}
		$root = $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER;
		$dir  = $root . DIRECTORY_SEPARATOR . $channel
			. DIRECTORY_SEPARATOR . 'a_' . self::hash_identifier( $account_id, $key )
			. DIRECTORY_SEPARATOR . 'p_' . self::hash_identifier( $peer_uid, $key );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			@mkdir( $dir, 0755, true );
		}
		$htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
		if ( is_dir( $root ) && ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Deny from all\nOptions -Indexes\n" );
		}
		return is_dir( $dir ) && is_writable( $dir ) ? $dir : '';
	}

	/** Resolve the archive root without creating it during maintenance reads. */
	private static function root_directory(): string {
		$upload = wp_upload_dir();
		$base = (string) ( $upload['basedir'] ?? '' );
		$root = $base !== '' ? $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER : '';
		return $root !== '' && is_dir( $root ) ? $root : '';
	}

	/** Resolve an existing account/contact archive partition without exposing its path. */
	private static function archive_directory_path( string $channel, string $account_id, string $peer_uid ): string {
		$channel = sanitize_key( $channel );
		$key = self::archive_key();
		$root = self::root_directory();
		if ( $root === '' || $key === '' || ! in_array( $channel, self::CHANNELS, true ) || $account_id === '' || $peer_uid === '' ) {
			return '';
		}
		return $root . DIRECTORY_SEPARATOR . $channel
			. DIRECTORY_SEPARATOR . 'a_' . self::hash_identifier( $account_id, $key )
			. DIRECTORY_SEPARATOR . 'p_' . self::hash_identifier( $peer_uid, $key );
	}

	/** Resolve one validated monthly archive file without creating a partition. */
	private static function archive_file( string $channel, string $account_id, string $peer_uid, string $month ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) { return ''; }
		$dir = self::archive_directory_path( $channel, $account_id, $peer_uid );
		return $dir !== '' ? $dir . DIRECTORY_SEPARATOR . $month . '.jsonl' : '';
	}

	/**
	 * Resolve one archive file from already-hashed, SQL-persisted partition keys.
	 *
	 * This method deliberately never calls `archive_key()` and never accepts raw
	 * account/contact identifiers. Legacy receipts can continue using the raw
	 * identity wrapper above; new cold reads must use this stable path contract.
	 */
	public static function archive_file_from_keys( string $channel, string $account_key, string $peer_key, string $month ): string {
		$channel = self::archive_channel_for( $channel );
		if ( ! in_array( $channel, self::CHANNELS, true ) || ! preg_match( '/^a_[a-f0-9]{64}$/i', $account_key ) || ! preg_match( '/^p_[a-f0-9]{64}$/i', $peer_key ) || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return '';
		}
		$root = self::root_directory();
		if ( '' === $root ) { return ''; }
		return $root . DIRECTORY_SEPARATOR . $channel
			. DIRECTORY_SEPARATOR . $account_key
			. DIRECTORY_SEPARATOR . $peer_key
			. DIRECTORY_SEPARATOR . $month . '.jsonl';
	}

	/** Extract a whitelisted channel from an archive path relative to the archive root. */
	private static function channel_from_path( string $file, string $root ): string {
		$relative = ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, substr( $file, strlen( $root ) ) ), DIRECTORY_SEPARATOR );
		$channel = sanitize_key( (string) strtok( $relative, DIRECTORY_SEPARATOR ) );
		return in_array( $channel, self::CHANNELS, true ) ? $channel : '';
	}

	/** Extract the stable hashed partition identity from a monthly archive path. */
	private static function partition_from_path( string $file, string $root, string $month ): ?array {
		$relative = ltrim( str_replace( array( '/', '\\' ), '/', substr( $file, strlen( $root ) ) ), '/' );
		if ( ! preg_match( '#^(facebook|messenger|zalo_oa|zalo_personal|webchat|email|instagram|whatsapp)/(a_[a-f0-9]{64})/(p_[a-f0-9]{64})/' . preg_quote( $month, '#' ) . '\.jsonl$#i', $relative, $matches ) ) {
			return null;
		}
		return array( 'channel' => sanitize_key( $matches[1] ), 'account_key' => $matches[2], 'peer_key' => $matches[3], 'month' => $month );
	}

	/** Mark SQL indexes expired and remove receipts only after the archive file is gone. */
	private static function expire_partition_rows( array $partition ): void {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return; }
		global $wpdb;
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$receipts = BizCity_CRM_DB_Installer_V2::tbl_archive_receipts();
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$messages}` SET content_storage_state = 'expired', storage_error_code = 'archive_expired' WHERE archive_channel = %s AND archive_account_key = %s AND archive_peer_key = %s AND archive_month = %s AND content_storage_state IN ('archived','offloaded')",
			$partition['channel'], $partition['account_key'], $partition['peer_key'], $partition['month']
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$receipts}` WHERE channel_type = %s AND account_key = %s AND peer_key = %s AND archive_month = %s",
			$partition['channel'], $partition['account_key'], $partition['peer_key'], $partition['month']
		) );
	}

	/** Let the owning policy protect files from retention or legal erasure. */
	private static function under_legal_hold( string $channel, string $file, string $month ): bool {
		return (bool) apply_filters( 'bizcity_channel_conversation_archive_legal_hold', false, array(
			'blog_id' => (int) get_current_blog_id(),
			'channel' => $channel,
			'file'    => $file,
			'month'   => $month,
		) );
	}

	/** Remove matching conversation rows while preserving malformed/unrelated lines. */
	private static function erase_from_file( string $file, int $conversation_id ): int {
		$source = @fopen( $file, 'c+b' );
		if ( false === $source || ! @flock( $source, LOCK_EX ) ) {
			if ( is_resource( $source ) ) { fclose( $source ); }
			return -1;
		}
		$dir = dirname( $file );
		$tmp = @tempnam( $dir, '.bzca-erase-' );
		$target = false !== $tmp ? @fopen( $tmp, 'wb' ) : false;
		if ( false === $target ) {
			if ( false !== $tmp ) { @unlink( $tmp ); }
			@flock( $source, LOCK_UN );
			fclose( $source );
			return -1;
		}
		$removed = 0;
		try {
			rewind( $source );
			while ( false !== ( $line = fgets( $source ) ) ) {
				if ( strlen( $line ) <= self::MAX_LINE_BYTES + 1 ) {
					$entry = json_decode( trim( $line ), true );
					if ( is_array( $entry ) && (int) ( $entry['conversation_id'] ?? 0 ) === $conversation_id ) {
						$removed++;
						continue;
					}
				}
				if ( false === @fwrite( $target, $line ) ) { throw new Exception( 'archive_erase_write_failed' ); }
			}
			if ( false === @fflush( $target ) ) { throw new Exception( 'archive_erase_flush_failed' ); }
		} catch ( \Throwable $e ) {
			fclose( $target );
			@unlink( $tmp );
			@flock( $source, LOCK_UN );
			fclose( $source );
			self::operational_failure( 'conversation_archive_erase_failed', $e );
			return -1;
		}
		fclose( $target );
		if ( 0 === $removed ) {
			@unlink( $tmp );
			@flock( $source, LOCK_UN );
			fclose( $source );
			return 0;
		}
		$renamed = @rename( $tmp, $file );
		@flock( $source, LOCK_UN );
		fclose( $source );
		if ( ! $renamed ) {
			@unlink( $tmp );
			self::operational_failure( 'conversation_archive_erase_failed', new Exception( 'archive_erase_rename_failed' ) );
			return -1;
		}
		return $removed;
	}

	private static function operational_failure( string $event, \Throwable $exception, string $channel = 'zalo_personal' ): void {
		// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — emit redacted archive failure evidence without throwing.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::error(
				in_array( $channel, self::CHANNELS, true ) && $channel === 'messenger'
					? BizCity_Channel_File_Logger::CH_MESSENGER
					: BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
				$event,
				'Conversation archive write failed.',
				array( 'exception_class' => get_class( $exception ) )
			);
		}
	}
}

// [2026-08-22 Johnny Chu] PHASE-0.39B-W8 — register archive listeners at file scope.
BizCity_Channel_Conversation_Archive::register();
