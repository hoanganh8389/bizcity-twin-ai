<?php
/**
 * BizCity CRM — Email Repository (PHASE 0.35 M-CRM.M3).
 *
 * Write gate for `bizcity_crm_email_accounts`, `_email_threads`, `_email_messages`.
 *
 * Threading rule:
 *   1. If `In-Reply-To` matches an existing message_id_header → attach to same thread.
 *   2. Else if `subject` (normalized) matches existing thread within 30 days → attach.
 *   3. Else create new thread.
 *
 * Passwords stored encrypted-at-rest using a SECRET_AUTH_KEY-derived key
 * (see encrypt/decrypt helpers below). NEVER returned in plain via REST.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Email_Repository {

	const THREAD_WINDOW_DAYS = 30;

	/* ============================================================
	 * ACCOUNTS
	 * ============================================================ */

	public static function create_account( array $data ): int {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		$now = current_time( 'mysql' );

		$row = array(
			'user_id'         => isset( $data['user_id'] ) ? (int) $data['user_id'] : (int) get_current_user_id(),
			'label'           => (string) ( $data['label'] ?? '' ),
			'email'           => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'imap_host'       => (string) ( $data['imap_host']   ?? 'imap.gmail.com' ),
			'imap_port'       => (int)    ( $data['imap_port']   ?? 993 ),
			'imap_secure'     => (string) ( $data['imap_secure'] ?? 'ssl' ),
			'imap_user'       => (string) ( $data['imap_user']   ?? ( $data['email'] ?? '' ) ),
			'imap_pass_enc'   => isset( $data['imap_pass'] ) && $data['imap_pass'] !== '' ? self::encrypt( (string) $data['imap_pass'] ) : null,
			'imap_folder'     => (string) ( $data['imap_folder'] ?? 'INBOX' ),
			'smtp_use_global' => isset( $data['smtp_use_global'] ) ? (int) (bool) $data['smtp_use_global'] : 1,
			'smtp_host'       => isset( $data['smtp_host'] )   ? (string) $data['smtp_host']   : null,
			'smtp_port'       => isset( $data['smtp_port'] )   ? (int)    $data['smtp_port']   : null,
			'smtp_secure'     => isset( $data['smtp_secure'] ) ? (string) $data['smtp_secure'] : null,
			'smtp_user'       => isset( $data['smtp_user'] )   ? (string) $data['smtp_user']   : null,
			'smtp_pass_enc'   => isset( $data['smtp_pass'] ) && $data['smtp_pass'] !== '' ? self::encrypt( (string) $data['smtp_pass'] ) : null,
			'is_active'       => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$wpdb->insert( $tbl, $row );
		return (int) $wpdb->insert_id;
	}

	public static function update_account( int $id, array $data ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$mutable = array( 'label', 'email', 'imap_host', 'imap_port', 'imap_secure', 'imap_user', 'imap_folder', 'smtp_use_global', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'is_active' );
		foreach ( $mutable as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$update[ $f ] = $data[ $f ];
			}
		}
		if ( ! empty( $data['imap_pass'] ) ) {
			$update['imap_pass_enc'] = self::encrypt( (string) $data['imap_pass'] );
		}
		if ( ! empty( $data['smtp_pass'] ) ) {
			$update['smtp_pass_enc'] = self::encrypt( (string) $data['smtp_pass'] );
		}
		return false !== $wpdb->update( $tbl, $update, array( 'id' => $id ) );
	}

	public static function get_account( int $id, bool $with_secrets = false ): ?array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE id = %d AND deleted_at IS NULL", $id
		), ARRAY_A );
		if ( ! $row ) { return null; }
		if ( ! $with_secrets ) {
			$row['imap_pass_enc'] = $row['imap_pass_enc'] ? '***' : null;
			$row['smtp_pass_enc'] = $row['smtp_pass_enc'] ? '***' : null;
		}
		return $row;
	}

	public static function get_account_with_passwords( int $id ): ?array {
		$row = self::get_account( $id, true );
		if ( ! $row ) { return null; }
		$row['imap_pass'] = $row['imap_pass_enc'] ? self::decrypt( (string) $row['imap_pass_enc'] ) : '';
		$row['smtp_pass'] = $row['smtp_pass_enc'] ? self::decrypt( (string) $row['smtp_pass_enc'] ) : '';
		return $row;
	}

	public static function list_accounts( ?int $user_id = null ): array {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		if ( $user_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$tbl} WHERE deleted_at IS NULL AND (user_id = %d OR user_id IS NULL) ORDER BY id DESC", $user_id
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE deleted_at IS NULL ORDER BY id DESC", ARRAY_A );
		}
		foreach ( (array) $rows as &$r ) {
			$r['imap_pass_enc'] = $r['imap_pass_enc'] ? '***' : null;
			$r['smtp_pass_enc'] = $r['smtp_pass_enc'] ? '***' : null;
		}
		return $rows ?: array();
	}

	public static function delete_account( int $id ): bool {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		return false !== $wpdb->update( $tbl, array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	public static function update_sync_state( int $id, array $state ): void {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_email_accounts();
		$update = array( 'last_sync_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) );
		if ( array_key_exists( 'last_uid_seen', $state ) )    { $update['last_uid_seen']    = (int) $state['last_uid_seen']; }
		if ( array_key_exists( 'last_sync_status', $state ) ) { $update['last_sync_status'] = (string) $state['last_sync_status']; }
		if ( array_key_exists( 'last_sync_error', $state ) )  { $update['last_sync_error']  = (string) $state['last_sync_error']; }
		$wpdb->update( $tbl, $update, array( 'id' => $id ) );
	}

	/* ============================================================
	 * THREADS + MESSAGES (ingest-from-IMAP API)
	 * ============================================================ */

	/**
	 * Ingest one parsed message into the DB. De-duplicates by (account_id, imap_uid)
	 * and (message_id_header). Creates or attaches to a thread per Threading rule.
	 *
	 * @param int   $account_id
	 * @param array $parsed     keys: imap_uid, message_id_header, in_reply_to, subject,
	 *                          from_email, from_name, to (array), cc (array),
	 *                          body_html, body_text, attachments (array), received_at (Y-m-d H:i:s)
	 * @return array{message_id:int,thread_id:int,inserted:bool}
	 */
	public static function ingest_message( int $account_id, array $parsed ): array {
		global $wpdb;
		$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_messages();
		$now     = current_time( 'mysql' );

		$uid       = isset( $parsed['imap_uid'] ) ? (int) $parsed['imap_uid'] : null;
		$msg_id_h  = (string) ( $parsed['message_id_header'] ?? '' );
		$in_reply  = (string) ( $parsed['in_reply_to'] ?? '' );

		// Dedup by (account, uid).
		if ( $uid !== null ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$msg_tbl} WHERE account_id = %d AND imap_uid = %d LIMIT 1",
				$account_id, $uid
			) );
			if ( $exists ) {
				return array( 'message_id' => (int) $exists, 'thread_id' => 0, 'inserted' => false );
			}
		}
		// Dedup by message_id_header (covers re-fetched mail if UID rotated).
		if ( $msg_id_h !== '' ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$msg_tbl} WHERE message_id_header = %s LIMIT 1", $msg_id_h
			) );
			if ( $exists ) {
				return array( 'message_id' => (int) $exists, 'thread_id' => 0, 'inserted' => false );
			}
		}

		$thread_id = self::resolve_or_create_thread( $account_id, (string) ( $parsed['subject'] ?? '' ), $in_reply );

		$wpdb->insert( $msg_tbl, array(
			'account_id'        => $account_id,
			'thread_id'         => $thread_id,
			'direction'         => 'in',
			'imap_uid'          => $uid,
			'message_id_header' => $msg_id_h ?: null,
			'in_reply_to'       => $in_reply ?: null,
			'from_email'        => isset( $parsed['from_email'] ) ? sanitize_email( (string) $parsed['from_email'] ) : null,
			'from_name'         => isset( $parsed['from_name'] )  ? (string) $parsed['from_name']  : null,
			'to_json'           => isset( $parsed['to'] )  ? wp_json_encode( $parsed['to'] )  : null,
			'cc_json'           => isset( $parsed['cc'] )  ? wp_json_encode( $parsed['cc'] )  : null,
			'bcc_json'          => isset( $parsed['bcc'] ) ? wp_json_encode( $parsed['bcc'] ) : null,
			'subject'           => (string) ( $parsed['subject'] ?? '' ),
			'body_html'         => $parsed['body_html'] ?? null,
			'body_text'         => $parsed['body_text'] ?? null,
			'attachments_json'  => isset( $parsed['attachments'] ) ? wp_json_encode( $parsed['attachments'] ) : null,
			'is_seen'           => 0,
			'received_at'       => (string) ( $parsed['received_at'] ?? $now ),
			'raw_size'          => (int) ( $parsed['raw_size'] ?? 0 ),
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$msg_id = (int) $wpdb->insert_id;

		self::touch_thread( $thread_id, true /* unread */, ! empty( $parsed['attachments'] ) );

		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_email_received', array(
				'account_id' => $account_id,
				'thread_id'  => $thread_id,
				'message_id' => $msg_id,
				'subject'    => (string) ( $parsed['subject'] ?? '' ),
				'from'       => $parsed['from_email'] ?? null,
			) );
		}
		return array( 'message_id' => $msg_id, 'thread_id' => $thread_id, 'inserted' => true );
	}

	private static function resolve_or_create_thread( int $account_id, string $subject, string $in_reply_to ): int {
		global $wpdb;
		$thread_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_threads();
		$msg_tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_email_messages();

		// Rule 1: in_reply_to → same thread.
		if ( $in_reply_to ) {
			$tid = $wpdb->get_var( $wpdb->prepare(
				"SELECT thread_id FROM {$msg_tbl} WHERE message_id_header = %s LIMIT 1", $in_reply_to
			) );
			if ( $tid ) {
				return (int) $tid;
			}
		}

		// Rule 2: normalized subject within window.
		$norm = self::normalize_subject( $subject );
		if ( $norm !== '' ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::THREAD_WINDOW_DAYS * DAY_IN_SECONDS );
			$tid = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$thread_tbl} WHERE account_id = %d AND normalized_subject = %s AND last_message_at >= %s ORDER BY id DESC LIMIT 1",
				$account_id, $norm, $cutoff
			) );
			if ( $tid ) {
				return (int) $tid;
			}
		}

		// Rule 3: new thread.
		$now = current_time( 'mysql' );
		$wpdb->insert( $thread_tbl, array(
			'account_id'         => $account_id,
			'subject'            => $subject,
			'normalized_subject' => $norm,
			'message_count'      => 0,
			'unread_count'       => 0,
			'has_attachment'     => 0,
			'last_message_at'    => $now,
			'created_at'         => $now,
			'updated_at'         => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	private static function touch_thread( int $thread_id, bool $unread, bool $has_attachment ): void {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_threads();
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET message_count = message_count + 1,
			 unread_count = unread_count + %d,
			 has_attachment = GREATEST( has_attachment, %d ),
			 last_message_at = %s, updated_at = %s
			 WHERE id = %d",
			$unread ? 1 : 0, $has_attachment ? 1 : 0, $now, $now, $thread_id
		) );
	}

	public static function normalize_subject( string $s ): string {
		$s = trim( $s );
		// Strip leading Re:/Fwd: chains (case-insensitive, multilingual).
		$s = preg_replace( '/^(?:\s*(?:re|fw|fwd|tr|sv)\s*:\s*)+/iu', '', $s ) ?? '';
		return mb_substr( trim( $s ), 0, 250 );
	}

	/* ============================================================
	 * READ APIs
	 * ============================================================ */

	public static function list_threads( int $account_id, array $args = array() ): array {
		global $wpdb;
		$tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_email_threads();
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$where  = array( 'account_id = %d', 'deleted_at IS NULL' );
		$params = array( $account_id );
		if ( ! empty( $args['unread_only'] ) ) {
			$where[] = 'unread_count > 0';
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(subject LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$sql = "SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . " ORDER BY last_message_at DESC LIMIT {$limit} OFFSET {$offset}";
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	public static function get_thread_with_messages( int $thread_id ): ?array {
		global $wpdb;
		$thread_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_threads();
		$msg_tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_email_messages();
		$thread     = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$thread_tbl} WHERE id = %d AND deleted_at IS NULL", $thread_id
		), ARRAY_A );
		if ( ! $thread ) { return null; }
		$thread['messages'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$msg_tbl} WHERE thread_id = %d ORDER BY received_at ASC, id ASC", $thread_id
		), ARRAY_A ) ?: array();
		return $thread;
	}

	public static function mark_thread_read( int $thread_id ): bool {
		global $wpdb;
		$thread_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_threads();
		$msg_tbl    = BizCity_CRM_DB_Installer_V2::tbl_crm_email_messages();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$msg_tbl} SET is_seen = 1 WHERE thread_id = %d AND is_seen = 0", $thread_id
		) );
		$wpdb->update( $thread_tbl, array( 'unread_count' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $thread_id ) );
		return true;
	}

	/* ============================================================
	 * OUTBOUND (compose + send via SMTP module)
	 * ============================================================ */

	public static function compose_and_send( int $account_id, array $data ): array {
		$account = self::get_account( $account_id );
		if ( ! $account ) {
			throw new \RuntimeException( 'account_not_found' );
		}
		$to      = (array) ( $data['to'] ?? array() );
		$cc      = (array) ( $data['cc'] ?? array() );
		$bcc     = (array) ( $data['bcc'] ?? array() );
		$subject = (string) ( $data['subject'] ?? '(no subject)' );
		$html    = (string) ( $data['body_html'] ?? '' );
		$text    = (string) ( $data['body_text'] ?? wp_strip_all_tags( $html ) );
		$thread  = isset( $data['thread_id'] ) ? (int) $data['thread_id'] : 0;
		$reply_to = (string) ( $data['in_reply_to'] ?? '' );

		if ( ! $to ) {
			throw new \RuntimeException( 'recipient_required' );
		}

		// Resolve thread if reply.
		if ( ! $thread && $reply_to ) {
			$thread = self::resolve_or_create_thread( $account_id, $subject, $reply_to );
		}
		if ( ! $thread ) {
			$thread = self::resolve_or_create_thread( $account_id, $subject, '' );
		}

		// Build headers (prefer the account's email address as From).
		$from_email = (string) $account['email'];
		$from_name  = (string) ( $account['label'] ?: get_bloginfo( 'name' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);
		foreach ( $cc as $c )  { $headers[] = 'Cc: '  . $c; }
		foreach ( $bcc as $b ) { $headers[] = 'Bcc: ' . $b; }
		if ( $reply_to ) {
			$headers[] = 'In-Reply-To: ' . $reply_to;
			$headers[] = 'References: '  . $reply_to;
		}

		// Send via wp_mail (will go through core/smtp bridge).
		$ok = (bool) wp_mail( $to, $subject, $html, $headers );

		// Persist outbound record.
		global $wpdb;
		$msg_tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_email_messages();
		$now     = current_time( 'mysql' );
		$wpdb->insert( $msg_tbl, array(
			'account_id'        => $account_id,
			'thread_id'         => $thread,
			'direction'         => 'out',
			'imap_uid'          => null,
			'message_id_header' => null,
			'in_reply_to'       => $reply_to ?: null,
			'from_email'        => $from_email,
			'from_name'         => $from_name,
			'to_json'           => wp_json_encode( $to ),
			'cc_json'           => wp_json_encode( $cc ),
			'bcc_json'          => wp_json_encode( $bcc ),
			'subject'           => $subject,
			'body_html'         => $html,
			'body_text'         => $text,
			'attachments_json'  => null,
			'is_seen'           => 1,
			'sent_at'           => $now,
			'send_status'       => $ok ? 'sent' : 'failed',
			'send_error'        => $ok ? null : 'wp_mail returned false',
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$out_id = (int) $wpdb->insert_id;

		// Touch thread (no unread bump for outbound).
		self::touch_thread( $thread, false, false );

		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			BizCity_CRM_Event_Emitter::emit( 'crm_email_sent', array(
				'account_id' => $account_id,
				'thread_id'  => $thread,
				'message_id' => $out_id,
				'ok'         => $ok,
			) );
		}
		return array( 'message_id' => $out_id, 'thread_id' => $thread, 'sent' => $ok );
	}

	/* ============================================================
	 * SECRET ENCRYPTION (at-rest)
	 * ============================================================ */

	private static function key(): string {
		// Derive key from WP secret. AUTH_KEY is required by core; fallback to siteurl hash.
		$base = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : ( get_option( 'siteurl' ) . get_option( 'admin_email' ) );
		return hash( 'sha256', 'bizcity_crm_email_v1|' . $base, true ); // 32 bytes
	}

	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) { return ''; }
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Fallback: base64 with marker (NOT secure, but better than nothing).
			return 'b64:' . base64_encode( $plain );
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return 'aes:' . base64_encode( $iv . $enc );
	}

	public static function decrypt( string $blob ): string {
		if ( $blob === '' ) { return ''; }
		if ( strpos( $blob, 'b64:' ) === 0 ) {
			return (string) base64_decode( substr( $blob, 4 ) );
		}
		if ( strpos( $blob, 'aes:' ) === 0 && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $blob, 4 ) );
			if ( strlen( $raw ) < 17 ) { return ''; }
			$iv  = substr( $raw, 0, 16 );
			$ct  = substr( $raw, 16 );
			$out = openssl_decrypt( $ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
			return $out === false ? '' : $out;
		}
		return $blob; // legacy plaintext
	}
}
