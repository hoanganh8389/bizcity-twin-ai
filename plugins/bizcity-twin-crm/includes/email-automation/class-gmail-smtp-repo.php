<?php
/**
 * BizCity CRM — Gmail SMTP Accounts Repository (PHASE 0.37.1)
 *
 * Single-tenant store cho Gmail App-Password accounts.
 * Password được encrypt nhẹ bằng AUTH_KEY salt + base64 (đủ để chống
 * casual leak; muốn mạnh hơn thay sang Halite/sodium sau).
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Gmail_SMTP_Repo {

	/** Lightweight encryption (XOR + base64) — readable only with WP secret salt. */
	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) { return ''; }
		$key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity-fallback-key';
		$key  = hash( 'sha256', $key, true );
		$out  = '';
		for ( $i = 0, $n = strlen( $plain ); $i < $n; $i++ ) {
			$out .= chr( ord( $plain[ $i ] ) ^ ord( $key[ $i % 32 ] ) );
		}
		return base64_encode( $out );
	}

	public static function decrypt( string $enc ): string {
		if ( $enc === '' ) { return ''; }
		$raw = base64_decode( $enc, true );
		if ( $raw === false ) { return ''; }
		$key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bizcity-fallback-key';
		$key = hash( 'sha256', $key, true );
		$out = '';
		for ( $i = 0, $n = strlen( $raw ); $i < $n; $i++ ) {
			$out .= chr( ord( $raw[ $i ] ) ^ ord( $key[ $i % 32 ] ) );
		}
		return $out;
	}

	private static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_gmail_smtp_accounts();
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_accounts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT * FROM " . self::table() . " WHERE deleted_at IS NULL ORDER BY is_default DESC, id DESC",
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'mask' ), is_array( $rows ) ? $rows : array() );
	}

	public static function get( int $id, bool $with_password = false ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d AND deleted_at IS NULL", $id ),
			ARRAY_A
		);
		if ( ! $row ) { return null; }
		return $with_password ? self::with_password( $row ) : self::mask( $row );
	}

	/** Strip password from API responses; expose only '***' marker. */
	public static function mask( array $row ): array {
		$has = ! empty( $row['smtp_pass_enc'] );
		unset( $row['smtp_pass_enc'] );
		$row['has_password'] = $has;
		return $row;
	}

	public static function with_password( array $row ): array {
		$enc = (string) ( $row['smtp_pass_enc'] ?? '' );
		$row['smtp_pass'] = $enc === '' ? '' : self::decrypt( $enc );
		unset( $row['smtp_pass_enc'] );
		return $row;
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$pass = (string) ( $data['smtp_pass'] ?? '' );
		// Strip spaces from Google App Password (16 chars w/ spaces commonly pasted).
		$pass = preg_replace( '/\s+/', '', $pass );
		$row = array(
			'label'         => (string) ( $data['label'] ?? '' ),
			'from_email'    => sanitize_email( $data['from_email'] ?? ( $data['smtp_user'] ?? '' ) ),
			'from_name'     => (string) ( $data['from_name'] ?? get_bloginfo( 'name' ) ),
			'smtp_host'     => (string) ( $data['smtp_host'] ?? 'smtp.gmail.com' ),
			'smtp_port'     => (int)    ( $data['smtp_port'] ?? 587 ),
			'smtp_secure'   => (string) ( $data['smtp_secure'] ?? 'tls' ),
			'smtp_user'     => sanitize_email( $data['smtp_user'] ?? '' ),
			'smtp_pass_enc' => $pass !== '' ? self::encrypt( $pass ) : null,
			'is_default'    => ! empty( $data['is_default'] ) ? 1 : 0,
			'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;
		if ( $id && $row['is_default'] ) {
			self::set_default( $id );
		}
		return $id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$set = array( 'updated_at' => current_time( 'mysql' ) );
		foreach ( array( 'label', 'from_email', 'from_name', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'is_active' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$set[ $k ] = is_int( $data[ $k ] ) || is_bool( $data[ $k ] ) ? (int) $data[ $k ] : (string) $data[ $k ];
			}
		}
		if ( array_key_exists( 'smtp_pass', $data ) && (string) $data['smtp_pass'] !== '' ) {
			$pass = preg_replace( '/\s+/', '', (string) $data['smtp_pass'] );
			$set['smtp_pass_enc'] = self::encrypt( $pass );
		}
		$wpdb->update( self::table(), $set, array( 'id' => $id ) );
		if ( ! empty( $data['is_default'] ) ) {
			self::set_default( $id );
		}
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	/** Exactly one row may be is_default=1. */
	public static function set_default( int $id ): void {
		global $wpdb;
		$tbl = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$tbl} SET is_default=0 WHERE id<>%d", $id ) );
		$wpdb->update( $tbl, array( 'is_default' => 1 ), array( 'id' => $id ) );
	}

	public static function get_default( bool $with_password = false ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM " . self::table() . " WHERE deleted_at IS NULL AND is_active=1 AND is_default=1 LIMIT 1",
			ARRAY_A
		);
		if ( ! $row ) { return null; }
		return $with_password ? self::with_password( $row ) : self::mask( $row );
	}

	/**
	 * Promote account → site-wide `bizcity_smtp_settings` option so wp_mail()
	 * uses it for ALL outbound (Woo emails, password reset, contact forms…).
	 */
	public static function promote_to_global( int $id ): bool {
		$row = self::get( $id, true );
		if ( ! $row ) { return false; }
		$opt = array(
			'host'      => (string) $row['smtp_host'],
			'port'      => (int) $row['smtp_port'],
			'user'      => (string) $row['smtp_user'],
			'pass'      => (string) ( $row['smtp_pass'] ?? '' ),
			'from'      => (string) ( $row['from_email'] ?: $row['smtp_user'] ),
			'from_name' => (string) ( $row['from_name'] ?: get_bloginfo( 'name' ) ),
			'secure'    => (string) $row['smtp_secure'],
			'auth'      => 1,
		);
		return (bool) update_option( 'bizcity_smtp_settings', $opt, false );
	}

	/**
	 * Send via a specific account using PHPMailer directly (does NOT touch
	 * the global wp_mail config — useful for per-rule sender override).
	 */
	public static function send_via( int $account_id, array $args ): array {
		$row = self::get( $account_id, true );
		if ( ! $row ) {
			return array( 'ok' => false, 'error' => 'account_not_found' );
		}
		$to          = $args['to']          ?? array();
		$cc          = $args['cc']          ?? array();
		$bcc         = $args['bcc']         ?? array();
		$subject     = (string) ( $args['subject'] ?? '' );
		$body        = (string) ( $args['body'] ?? '' );
		$is_html     = ! empty( $args['is_html'] );
		// [2026-06-20 Johnny Chu] HOTFIX — capture raw SMTP conversation when debug_smtp=true
		$debug_smtp  = ! empty( $args['debug_smtp'] );
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — attachment file paths
		$attachments = isset( $args['attachments'] ) && is_array( $args['attachments'] ) ? $args['attachments'] : array();

		if ( is_string( $to ) )  { $to  = array_filter( array_map( 'trim', preg_split( '/[,;]/', $to ) ) ); }
		if ( is_string( $cc ) )  { $cc  = array_filter( array_map( 'trim', preg_split( '/[,;]/', $cc ) ) ); }
		if ( is_string( $bcc ) ) { $bcc = array_filter( array_map( 'trim', preg_split( '/[,;]/', $bcc ) ) ); }

		if ( empty( $to ) || $subject === '' ) {
			return array( 'ok' => false, 'error' => 'missing_to_or_subject' );
		}

		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$smtp_log = array();
		try {
			$mail = new \PHPMailer\PHPMailer\PHPMailer( true );
			$mail->isSMTP();
			// [2026-06-20 Johnny Chu] HOTFIX — enable debug capture when requested
			if ( $debug_smtp ) {
				$mail->SMTPDebug  = 4; // full SMTP + client/server conversation
				$mail->Debugoutput = function ( $str ) use ( &$smtp_log ) {
					$smtp_log[] = rtrim( $str );
				};
			}
			$mail->Host       = (string) $row['smtp_host'];
			$mail->Port       = (int) $row['smtp_port'];
			$mail->SMTPAuth   = true;
			$mail->Username   = (string) $row['smtp_user'];
			$mail->Password   = (string) ( $row['smtp_pass'] ?? '' );
			$mail->SMTPSecure = $row['smtp_secure'] === 'ssl' ? 'ssl' : 'tls';
			$mail->CharSet    = 'UTF-8';

			// [2026-06-20 Johnny Chu] HOTFIX — Gmail Sender Alignment.
			// Gmail chỉ chấp nhận MAIL FROM = tài khoản đã xác thực (smtp_user).
			// Nếu from_email khác smtp_user (vd: info@domain.com vs gmail@gmail.com),
			// Gmail nhận 250 OK nhưng sau đó block vì envelope mismatch.
			// Fix: luôn set Sender (envelope MAIL FROM) = smtp_user;
			// giữ From = from_email để hiển thị đúng tên người gửi.
			// Reply-To = from_email để reply về đúng địa chỉ domain.
			$smtp_user  = (string) $row['smtp_user'];
			$from_email = (string) ( $row['from_email'] ?: $smtp_user );
			$from_name  = (string) $row['from_name'];
			$mail->setFrom( $from_email, $from_name );
			// Envelope sender phải là smtp_user → Gmail chấp nhận không block
			$mail->Sender = $smtp_user;
			// Nếu from_email khác smtp_user → add Reply-To để reply đúng địa chỉ
			if ( strtolower( $from_email ) !== strtolower( $smtp_user ) ) {
				$mail->addReplyTo( $from_email, $from_name );
			}

			foreach ( $to as $addr )  { $mail->addAddress( $addr ); }
			foreach ( $cc as $addr )  { $mail->addCC( $addr ); }
			foreach ( $bcc as $addr ) { $mail->addBCC( $addr ); }
			$mail->Subject = $subject;
			if ( $is_html ) {
				$mail->isHTML( true );
				$mail->Body    = $body;
				$mail->AltBody = wp_strip_all_tags( $body );
			} else {
				$mail->Body = $body;
			}

			// [2026-06-20 Johnny Chu] HOTFIX — DO NOT add Precedence/List-Unsubscribe headers.
			// These headers trigger Gmail's bulk-sender policy SMTP reject (data not accepted)
			// when SPF/DMARC is not fully set up on the From domain. Only add them after
			// SPF + DMARC records are confirmed propagated on the domain.

			// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — attach PDF/files if provided
			foreach ( $attachments as $path ) {
				if ( is_string( $path ) && @file_exists( $path ) ) {
					$mail->addAttachment( $path );
				}
			}
			$mail->send();
			return array( 'ok' => true, 'smtp_log' => $smtp_log );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'error' => $e->getMessage(), 'smtp_log' => $smtp_log );
		}
	}

	public static function record_test( int $id, bool $ok, string $msg = '' ): void {
		global $wpdb;
		$wpdb->update( self::table(), array(
			'last_test_at'  => current_time( 'mysql' ),
			'last_test_ok'  => $ok ? 1 : 0,
			'last_test_msg' => $msg,
			'updated_at'    => current_time( 'mysql' ),
		), array( 'id' => $id ) );
	}
}
