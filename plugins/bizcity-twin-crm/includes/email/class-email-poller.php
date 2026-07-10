<?php
/**
 * BizCity CRM — IMAP Poller (PHASE 0.35 M-CRM.M3).
 *
 * Cron tick that fetches new messages from configured IMAP accounts and
 * hands them to BizCity_CRM_Email_Repository::ingest_message().
 *
 * Gracefully skips when ext-imap is not loaded (most managed PHP hosts ship
 * it; some don't). Logs a one-time admin notice when missing.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Email_Poller {

	const HOOK         = 'bizcity_crm_email_poll_tick';
	const LOCK_PREFIX  = 'bizcity_crm_email_poll_lock_';
	const LOCK_TTL_SEC = 600;
	const PAGE_SIZE    = 50;

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run_all' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['bizcity_crm_5min'] ) ) {
			$schedules['bizcity_crm_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (BizCity CRM)', 'bizcity-twin-crm' ),
			);
		}
		return $schedules;
	}

	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'bizcity_crm_5min', self::HOOK );
		}
	}

	public static function imap_available(): bool {
		return function_exists( 'imap_open' );
	}

	public static function run_all(): void {
		if ( ! self::imap_available() ) {
			return;
		}
		$accounts = BizCity_CRM_Email_Repository::list_accounts();
		foreach ( $accounts as $a ) {
			if ( empty( $a['is_active'] ) ) { continue; }
			// Swallow per-account errors here so a single broken account
			// (network timeout, bad creds, etc.) doesn't kill the WP cron
			// worker for the rest of the queue. The error is already logged
			// inside poll_account() and persisted via update_sync_state().
			try {
				self::poll_account( (int) $a['id'] );
			} catch ( \Throwable $e ) {
				error_log( '[bizcity-crm] email poll skipped account ' . (int) $a['id'] . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Poll a single account. Returns ['fetched' => N, 'inserted' => M] or throws.
	 */
	public static function poll_account( int $account_id ): array {
		if ( ! self::imap_available() ) {
			throw new \RuntimeException( 'imap_extension_missing' );
		}

		$lock = self::LOCK_PREFIX . $account_id;
		if ( get_transient( $lock ) ) {
			return array( 'fetched' => 0, 'inserted' => 0, 'skipped' => 'locked' );
		}
		set_transient( $lock, 1, self::LOCK_TTL_SEC );

		$result = array( 'fetched' => 0, 'inserted' => 0 );
		try {
			$acc = BizCity_CRM_Email_Repository::get_account_with_passwords( $account_id );
			if ( ! $acc ) {
				throw new \RuntimeException( 'account_not_found' );
			}

			$mbx = self::build_mailbox_string( $acc );
			$conn = @imap_open( $mbx, (string) $acc['imap_user'], (string) ( $acc['imap_pass'] ?? '' ), 0, 1, array( 'DISABLE_AUTHENTICATOR' => 'GSSAPI' ) );
			if ( ! $conn ) {
				$err = imap_last_error() ?: 'imap_open_failed';
				BizCity_CRM_Email_Repository::update_sync_state( $account_id, array(
					'last_sync_status' => 'error',
					'last_sync_error'  => (string) $err,
				) );
				throw new \RuntimeException( 'imap_open_failed: ' . $err );
			}

			$last_uid = (int) ( $acc['last_uid_seen'] ?? 0 );
			$search   = sprintf( 'UID %d:*', $last_uid + 1 );
			$uids     = imap_search( $conn, $search, SE_UID ) ?: array();
			// imap_search "UID N:*" always returns at least one (the newest); filter.
			$uids = array_values( array_filter( array_map( 'intval', (array) $uids ), static function ( $u ) use ( $last_uid ) {
				return $u > $last_uid;
			} ) );
			sort( $uids );

			$max_uid = $last_uid;
			foreach ( array_slice( $uids, 0, self::PAGE_SIZE ) as $uid ) {
				$result['fetched']++;
				$parsed = self::fetch_and_parse( $conn, $uid );
				if ( $parsed === null ) {
					continue;
				}
				$ing = BizCity_CRM_Email_Repository::ingest_message( $account_id, $parsed );
				if ( ! empty( $ing['inserted'] ) ) {
					$result['inserted']++;
				}
				if ( $uid > $max_uid ) {
					$max_uid = $uid;
				}
			}

			BizCity_CRM_Email_Repository::update_sync_state( $account_id, array(
				'last_uid_seen'    => $max_uid,
				'last_sync_status' => 'ok',
				'last_sync_error'  => '',
			) );

			imap_close( $conn );
		} catch ( \Throwable $e ) {
			error_log( '[bizcity-crm] email poll failed for account ' . $account_id . ': ' . $e->getMessage() );
			throw $e;
		} finally {
			delete_transient( $lock );
		}
		return $result;
	}

	private static function build_mailbox_string( array $acc ): string {
		$host   = (string) $acc['imap_host'];
		$port   = (int) $acc['imap_port'];
		$secure = (string) $acc['imap_secure'];
		$folder = (string) ( $acc['imap_folder'] ?: 'INBOX' );
		$flags  = '/imap';
		if ( $secure === 'ssl' )      { $flags .= '/ssl'; }
		elseif ( $secure === 'tls' )  { $flags .= '/tls'; }
		else                          { $flags .= '/notls'; }
		$flags .= '/novalidate-cert'; // accept self-signed; users can lock down via filter.
		$flags = (string) apply_filters( 'bizcity_crm_imap_flags', $flags, $acc );
		return sprintf( '{%s:%d%s}%s', $host, $port, $flags, $folder );
	}

	private static function fetch_and_parse( $conn, int $uid ): ?array {
		$msgno = imap_msgno( $conn, $uid );
		if ( ! $msgno ) { return null; }

		$header_raw = imap_fetchheader( $conn, $uid, FT_UID );
		$overview   = imap_fetch_overview( $conn, (string) $uid, FT_UID );
		$ov         = ! empty( $overview ) ? $overview[0] : null;
		$headers    = imap_rfc822_parse_headers( (string) $header_raw );

		$from_email = '';
		$from_name  = '';
		if ( ! empty( $headers->from[0] ) ) {
			$from_email = ( $headers->from[0]->mailbox ?? '' ) . '@' . ( $headers->from[0]->host ?? '' );
			$from_name  = self::mime_decode( (string) ( $headers->from[0]->personal ?? '' ) );
		}
		$to  = self::addr_list( $headers->to ?? array() );
		$cc  = self::addr_list( $headers->cc ?? array() );
		$bcc = self::addr_list( $headers->bcc ?? array() );

		$subject  = self::mime_decode( (string) ( $ov->subject ?? '' ) );
		$msgid    = (string) ( $ov->message_id ?? '' );
		$inreply  = (string) ( $headers->in_reply_to ?? '' );
		$received = ! empty( $ov->date ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $ov->date ) ?: time() ) : current_time( 'mysql', true );

		// Body parsing.
		$structure = imap_fetchstructure( $conn, $uid, FT_UID );
		$parts     = self::extract_body_parts( $conn, $uid, $structure );

		return array(
			'imap_uid'          => $uid,
			'message_id_header' => $msgid,
			'in_reply_to'       => $inreply,
			'from_email'        => $from_email,
			'from_name'         => $from_name,
			'to'                => $to,
			'cc'                => $cc,
			'bcc'               => $bcc,
			'subject'           => $subject,
			'body_html'         => $parts['html'],
			'body_text'         => $parts['text'],
			'attachments'       => $parts['attachments'],
			'received_at'       => $received,
			'raw_size'          => (int) ( $ov->size ?? 0 ),
		);
	}

	private static function addr_list( $arr ): array {
		$out = array();
		foreach ( (array) $arr as $a ) {
			if ( empty( $a->mailbox ) || empty( $a->host ) ) { continue; }
			$out[] = $a->mailbox . '@' . $a->host;
		}
		return $out;
	}

	private static function mime_decode( string $s ): string {
		if ( $s === '' ) { return ''; }
		$decoded = imap_mime_header_decode( $s );
		$out     = '';
		foreach ( (array) $decoded as $d ) {
			$txt = (string) ( $d->text ?? '' );
			$cs  = strtoupper( (string) ( $d->charset ?? 'UTF-8' ) );
			if ( $cs !== 'UTF-8' && $cs !== 'DEFAULT' && function_exists( 'mb_convert_encoding' ) ) {
				$txt = (string) @mb_convert_encoding( $txt, 'UTF-8', $cs );
			}
			$out .= $txt;
		}
		return $out;
	}

	private static function extract_body_parts( $conn, int $uid, $structure ): array {
		$result = array( 'html' => '', 'text' => '', 'attachments' => array() );
		if ( ! $structure ) { return $result; }

		if ( empty( $structure->parts ) ) {
			$body = imap_fetchbody( $conn, $uid, '1', FT_UID );
			$body = self::decode( $body, (int) ( $structure->encoding ?? 0 ) );
			if ( ( $structure->subtype ?? '' ) === 'HTML' ) {
				$result['html'] = $body;
			} else {
				$result['text'] = $body;
			}
			return $result;
		}

		self::walk_parts( $conn, $uid, $structure->parts, '', $result );
		return $result;
	}

	private static function walk_parts( $conn, int $uid, array $parts, string $prefix, array &$result ): void {
		foreach ( $parts as $i => $part ) {
			$section = $prefix === '' ? (string) ( $i + 1 ) : $prefix . '.' . ( $i + 1 );

			if ( ! empty( $part->parts ) ) {
				self::walk_parts( $conn, $uid, $part->parts, $section, $result );
				continue;
			}

			$disposition = strtoupper( (string) ( $part->disposition ?? '' ) );
			$is_attach   = ( $disposition === 'ATTACHMENT' || $disposition === 'INLINE' && ! empty( $part->ifid ) );

			if ( $is_attach ) {
				$filename = '';
				foreach ( (array) ( $part->dparameters ?? array() ) as $p ) {
					if ( strtolower( (string) $p->attribute ) === 'filename' ) { $filename = (string) $p->value; }
				}
				if ( $filename === '' ) {
					foreach ( (array) ( $part->parameters ?? array() ) as $p ) {
						if ( strtolower( (string) $p->attribute ) === 'name' ) { $filename = (string) $p->value; }
					}
				}
				$result['attachments'][] = array(
					'filename'  => self::mime_decode( $filename ),
					'mime'      => self::part_mime( $part ),
					'size'      => (int) ( $part->bytes ?? 0 ),
					'section'   => $section,
				);
				continue;
			}

			$body = imap_fetchbody( $conn, $uid, $section, FT_UID );
			$body = self::decode( $body, (int) ( $part->encoding ?? 0 ) );

			$subtype = strtoupper( (string) ( $part->subtype ?? '' ) );
			if ( $subtype === 'HTML' && $result['html'] === '' ) {
				$result['html'] = $body;
			} elseif ( $subtype === 'PLAIN' && $result['text'] === '' ) {
				$result['text'] = $body;
			}
		}
	}

	private static function part_mime( $part ): string {
		static $types = array( 0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application', 4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other' );
		$type = $types[ (int) ( $part->type ?? 0 ) ] ?? 'application';
		$sub  = strtolower( (string) ( $part->subtype ?? 'octet-stream' ) );
		return $type . '/' . $sub;
	}

	private static function decode( string $data, int $encoding ): string {
		switch ( $encoding ) {
			case 3: return (string) base64_decode( $data );
			case 4: return (string) quoted_printable_decode( $data );
			default: return $data;
		}
	}
}
