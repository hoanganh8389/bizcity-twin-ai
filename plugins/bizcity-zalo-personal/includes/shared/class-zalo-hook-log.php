<?php
/**
 * BizCity Zalo Hook Log — rolling buffer of inbound/outbound hook activity (PHASE-0.39)
 *
 * Mirrors the spirit of BizCity_Webhook_Inspector but lightweight: stores the
 * last N records in a single autoloaded-off option so admins can "read hooks"
 * from the Logs tab without standing up a new table.
 *
 * Security (OWASP A05): only a ≤120-char text_preview is stored. No tokens, no
 * full PII, no raw provider payloads.
 *
 * @package BizCity_Zalo_Personal
 * @since   1.0.0
 */

// [2026-06-07 Johnny Chu] PHASE-0.39 — rolling hook log for test/read-hook tooling
defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Hook_Log {

	const OPTION  = 'bizcity_zalo_hook_log';
	const MAX     = 100;
	const PREVIEW = 120;

	/**
	 * Append an inbound record from a sidecar payload.
	 *
	 * @param array $body Inbound body as received by BizCity_Zalo_Bridge_REST::handle_inbound().
	 * @param int   $crm_message_id Result of the emitter (0 = retryable failure, -1 = intentional drop).
	 * @return void
	 */
	public static function record_inbound( array $body, int $crm_message_id ): void {
		$kind     = isset( $body['kind'] ) && 'oa' === $body['kind'] ? 'oa' : 'personal';
		$platform = 'oa' === $kind ? 'ZALO_OA' : 'ZALO_PERSONAL';
		self::push( array(
			'dir'            => 'inbound',
			'platform'       => $platform,
			'account_id'     => self::scalar( $body['account_id'] ?? '' ),
			'peer'           => self::scalar( $body['from_user_id'] ?? '' ),
			'peer_name'      => self::scalar( $body['from_user_name'] ?? '' ),
			'msg_id'         => self::scalar( $body['message_id'] ?? '' ),
			'msg_type'       => self::scalar( $body['message_type'] ?? 'text' ),
			'text_preview'   => self::preview( $body['message_text'] ?? '' ),
			'crm_message_id' => $crm_message_id,
			'status'         => $crm_message_id > 0 ? 'ok' : 'skipped',
		) );
	}

	/**
	 * Append an outbound record.
	 *
	 * @param string $platform   ZALO_PERSONAL | ZALO_OA.
	 * @param string $account_id Bridge account id.
	 * @param string $recipient  Thread/user id on the Zalo side.
	 * @param string $text       Outgoing text.
	 * @param string $status     ok | degraded | error.
	 * @param string $detail     Optional short detail/reason.
	 * @return void
	 */
	public static function record_outbound( string $platform, string $account_id, string $recipient, string $text, string $status, string $detail = '' ): void {
		self::push( array(
			'dir'          => 'outbound',
			'platform'     => $platform,
			'account_id'   => $account_id,
			'peer'         => $recipient,
			'peer_name'    => '',
			'msg_id'       => '',
			'msg_type'     => 'text',
			'text_preview' => self::preview( $text ),
			'status'       => $status,
			'detail'       => self::preview( $detail ),
		) );
	}

	/**
	 * Read recent records, newest first.
	 *
	 * @param int $limit
	 * @return array[]
	 */
	public static function read( int $limit = 100 ): array {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$rows = array_reverse( $rows );
		if ( $limit > 0 && count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		return $rows;
	}

	/** Empty the buffer. */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	// ── Internal ──────────────────────────────────────────────────────────

	private static function push( array $row ): void {
		$row['ts'] = time();
		$rows      = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		$rows[] = $row;
		if ( count( $rows ) > self::MAX ) {
			$rows = array_slice( $rows, count( $rows ) - self::MAX );
		}
		update_option( self::OPTION, $rows, false );
	}

	private static function preview( $text ): string {
		$text = is_string( $text ) ? $text : '';
		$text = trim( wp_strip_all_tags( $text ) );
		if ( strlen( $text ) > self::PREVIEW ) {
			$text = substr( $text, 0, self::PREVIEW ) . '…';
		}
		return $text;
	}

	private static function scalar( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '';
	}
}
