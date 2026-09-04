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
		return is_user_logged_in() && current_user_can( 'manage_options' );
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
		$deleted = self::purge_expired();
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'channel_conversation_archive_deleted' => $deleted ) ) );
			$cron->note_event( 'channel_conversation_archive_retention', array(
				'deleted_files'  => $deleted,
				'retention_days' => self::retention_days(),
				'channels'       => self::CHANNELS,
			) );
		}
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
				if ( $dry_run || @unlink( $file ) ) { $deleted++; }
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
				$plain = BizCity_Codec::decrypt_json_payload( (string) $entry['content_ciphertext'], $key, self::PREFIX, 'bizcity-channel-conversation' );
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
				array( 'files_scanned' => $files_scanned, 'sql_messages' => count( $sql_ids ), 'archive_messages' => count( $archive_message_ids ), 'missing' => count( $missing ), 'orphan' => count( $orphan ), 'duplicate_events' => $duplicate_events, 'malformed_lines' => $malformed_lines )
			);
		}
		return array( 'ok' => true, 'channel' => $normalized_channel, 'files_scanned' => $files_scanned, 'sql_message_count' => count( $sql_ids ), 'archive_message_count' => count( $archive_message_ids ), 'missing_crm_message_ids' => $missing, 'orphan_archive_message_ids' => $orphan, 'duplicate_events' => $duplicate_events, 'malformed_lines' => $malformed_lines, 'truncated' => $truncated );
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
			$plain = array(
				'content'      => (string) ( $row['content'] ?? '' ),
				'body'         => (string) ( $row['body'] ?? '' ),
				'content_type' => (string) ( $row['content_type'] ?? 'text' ),
			);
			$ciphertext = BizCity_Codec::encrypt_json_payload( $plain, $key, self::PREFIX, 'bizcity-channel-conversation' );
			if ( $ciphertext === '' ) {
				return false;
			}

			$payload = ! empty( $row['payload_json'] ) ? json_decode( (string) $row['payload_json'], true ) : array();
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
				'schema_version'          => 1,
				'event_type'              => $event_type,
				'event_uuid'              => (string) ( $event['event_uuid'] ?? $row['event_uuid'] ?? '' ),
				'trace_id'                => (string) ( $event['trace_id'] ?? '' ),
				'blog_id'                 => (int) get_current_blog_id(),
				'channel'                => $archive_channel,
				'platform'               => strtoupper( $archive_channel ),
				'account_key'             => 'a_' . self::hash_identifier( $account_id, $key ),
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
			$receipt_ok = self::write_receipt( $entry, $key, $archive_channel, $account_id, $peer_uid );
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

	/** Read one encrypted cold message without writing it back to SQL. */
	public static function rehydrate_message( int $message_id, string $channel, string $account_id, string $peer_uid, string $month ): ?array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F2 — rehydrate only the authorized message locator; never bulk restore cold content.
		if ( $message_id <= 0 || $channel === '' || $account_id === '' || $peer_uid === '' || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return null;
		}
		$key = self::archive_key();
		$file = self::archive_file( self::archive_channel_for( $channel ), $account_id, $peer_uid, $month );
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
				$plain = BizCity_Codec::decrypt_json_payload( (string) ( $entry['content_ciphertext'] ?? '' ), $key, self::PREFIX, 'bizcity-channel-conversation' );
				return is_array( $plain ) ? array_merge( $entry, array( 'content' => $plain['content'] ?? '', 'body' => $plain['body'] ?? '', 'content_type' => $plain['content_type'] ?? 'text' ) ) : null;
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
			'row_hash'      => hash( 'sha256', $durable_line ),
			'content_hash'  => hash( 'sha256', $line ),
			'occurred_at'   => (string) ( $entry['occurred_at'] ?? gmdate( 'c' ) ),
			'operation'     => (string) ( $entry['operation'] ?? 'upsert' ),
			'blog_id'       => (int) get_current_blog_id(),
			'channel'       => $channel,
			'account_key'   => (string) ( $entry['account_key'] ?? '' ),
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
		return array( 'ok' => true, 'operation' => (string) ( $entry['operation'] ?? 'upsert' ), 'entry' => array( 'record_id' => (string) $entry['record_id'], 'event_uuid' => (string) $entry['event_uuid'], 'blog_id' => $blog_id ) );
	}

	private static function write_receipt( array $entry, string $key, string $channel, string $account_id, string $peer_uid ): bool {
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
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (crm_message_id, conversation_id, inbox_id, channel_type, account_key, peer_key, archive_month, archive_schema_version, archive_key_version, line_hash, archive_status, written_at, verified_at, created_at, updated_at) VALUES (%d, %d, %d, %s, %s, %s, %s, 1, %s, %s, 'written', %s, %s, %s, %s) ON DUPLICATE KEY UPDATE archive_status = 'written', line_hash = VALUES(line_hash), archive_month = VALUES(archive_month), verified_at = VALUES(verified_at), updated_at = VALUES(updated_at)",
			(int) $entry['crm_message_id'],
			(int) $entry['conversation_id'],
			(int) $entry['inbox_id'],
			$channel,
			'a_' . self::hash_identifier( $account_id, $key ),
			'p_' . self::hash_identifier( $peer_uid, $key ),
			$month,
			'v1',
			hash( 'sha256', is_string( $line ) ? $line : '' ),
			$now,
			$now,
			$now,
			$now
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

	/** Extract a whitelisted channel from an archive path relative to the archive root. */
	private static function channel_from_path( string $file, string $root ): string {
		$relative = ltrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, substr( $file, strlen( $root ) ) ), DIRECTORY_SEPARATOR );
		$channel = sanitize_key( (string) strtok( $relative, DIRECTORY_SEPARATOR ) );
		return in_array( $channel, self::CHANNELS, true ) ? $channel : '';
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
