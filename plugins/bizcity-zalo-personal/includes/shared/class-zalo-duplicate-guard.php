<?php
/**
 * BizCity Zalo Personal — duplicate account guard (R-ZP-DUP).
 *
 * [2026-09-18] PHASE-0.48F U10 — one Zalo login (one phone / one zaloUid) must map to exactly ONE
 * Personal account per site. Two failure modes were observed on the live site:
 *
 *   1. Create: a member added a second account for a phone that already had one. The bridge
 *      happily provisioned it, so the site had two CRM inboxes for the same number.
 *   2. Login: when the second account completed its QR login, the sidecar superseded the first
 *      one (`superseded_by_account_N`, see zca-bridge `qrLoginService.supersedeSameLogin`), which
 *      then showed "Đã đăng xuất". Re-logging the first account would in turn kick the second —
 *      a ping-pong — and the UI only said "Chưa tạo được mã QR".
 *
 * This class holds the pure matching rules (unit-tested) plus thin lookups. Callers turn a match
 * into an R-ERROR-UX envelope that names the other account and the action to take.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Duplicate_Guard {

	/** Local statuses that no longer hold a phone (the row is dead, not merely logged out). */
	const DEAD_STATUSES = array( 'orphaned', 'deleted', 'revoked' );

	/** DUP-11 — local status written after the bridge account is deleted (history kept, never offered QR, never a twin). */
	const STATUS_REVOKED = 'revoked';

	/**
	 * Extract a canonical Vietnamese phone key from a free-text label.
	 * "Sale — +84 931 576 886" → "0931576886". Returns '' when no phone-like sequence is present.
	 */
	public static function phone_key( string $label ): string {
		if ( ! preg_match( '/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', $label, $match ) ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $match[0] );
		if ( strpos( $digits, '84' ) === 0 && strlen( $digits ) >= 11 ) {
			$digits = '0' . substr( $digits, 2 );
		}
		$len = strlen( $digits );
		return ( $digits[0] === '0' && $len >= 10 && $len <= 11 ) ? $digits : '';
	}

	/**
	 * Find a live local account whose label carries the same phone as $label.
	 *
	 * @param string $label New account label.
	 * @param array  $rows  Rows shaped like BizCity_Zalo_Mapping_Repo::list_personal_accounts().
	 * @return array|null The matching row, or null.
	 */
	public static function find_phone_duplicate( string $label, array $rows ): ?array {
		$key = self::phone_key( $label );
		if ( $key === '' ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || in_array( (string) ( $row['status'] ?? '' ), self::DEAD_STATUSES, true ) ) {
				continue;
			}
			$other = self::phone_key( (string) ( $row['label'] ?? '' ) );
			if ( $other === '' ) {
				$other = self::phone_key( (string) ( $row['account_name'] ?? '' ) );
			}
			if ( $other === $key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Find another bridge account that is CONNECTED with the same Zalo login as $account_id.
	 * Starting a QR for $account_id would supersede (log out) that account.
	 *
	 * @param string $account_id      Bridge account id about to start a QR login.
	 * @param array  $bridge_accounts Items from BizCity_Zalo_Bridge_Client::list_accounts()['accounts'].
	 * @return array|null The connected sibling, or null.
	 */
	public static function find_connected_sibling( string $account_id, array $bridge_accounts ): ?array {
		$uid = '';
		foreach ( $bridge_accounts as $acc ) {
			if ( is_array( $acc ) && (string) ( $acc['id'] ?? '' ) === $account_id ) {
				$uid = (string) ( $acc['zaloUid'] ?? $acc['zalo_uid'] ?? '' );
				break;
			}
		}
		if ( $uid === '' ) {
			return null;
		}
		foreach ( $bridge_accounts as $acc ) {
			if ( ! is_array( $acc ) || (string) ( $acc['id'] ?? '' ) === $account_id ) {
				continue;
			}
			$same = (string) ( $acc['zaloUid'] ?? $acc['zalo_uid'] ?? '' ) === $uid;
			if ( $same && (string) ( $acc['status'] ?? '' ) === 'connected' ) {
				return $acc;
			}
		}
		return null;
	}

	/**
	 * DUP-10 — for every logged-out/expired local row, find a CONNECTED row holding the same Zalo login
	 * (same zalo_uid, or — when either uid is still unknown — the same phone in the label).
	 *
	 * @param array $rows Rows shaped like BizCity_Zalo_Mapping_Repo::list_personal_accounts().
	 * @return array<int,array{crm_inbox_id:int,label:string,match:string}> keyed by the dead row's crm_inbox_id.
	 */
	public static function find_twins( array $rows ): array {
		$connected = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'connected' ) {
				$connected[] = $row;
			}
		}
		$out = array();
		if ( empty( $connected ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! in_array( (string) ( $row['status'] ?? '' ), array( 'logged_out', 'expired' ), true ) ) {
				continue;
			}
			$inbox_id = (int) ( $row['crm_inbox_id'] ?? 0 );
			if ( $inbox_id <= 0 ) {
				continue;
			}
			$uid   = (string) ( $row['zalo_uid'] ?? '' );
			$phone = self::phone_key( (string) ( $row['label'] ?? '' ) );
			foreach ( $connected as $live ) {
				if ( (string) ( $live['bridge_account_id'] ?? '' ) === (string) ( $row['bridge_account_id'] ?? '' ) ) {
					continue;
				}
				$live_uid = (string) ( $live['zalo_uid'] ?? '' );
				$match = '';
				if ( $uid !== '' && $live_uid !== '' ) {
					$match = $uid === $live_uid ? 'zalo_uid' : '';
				} elseif ( $phone !== '' && $phone === self::phone_key( (string) ( $live['label'] ?? '' ) ) ) {
					$match = 'phone';
				}
				if ( $match !== '' ) {
					$out[ $inbox_id ] = array(
						'crm_inbox_id' => (int) ( $live['crm_inbox_id'] ?? 0 ),
						'label'        => (string) ( $live['label'] ?? '' ),
						'match'        => $match,
					);
					break;
				}
			}
		}
		return $out;
	}

	/** Human label for an account row in messages: "Sale 0931… (#12)". */
	public static function describe( array $row ): string {
		$label = trim( (string) ( $row['label'] ?? $row['account_name'] ?? '' ) );
		$id    = (string) ( $row['bridge_account_id'] ?? $row['id'] ?? '' );
		return $label !== '' ? sprintf( '«%s» (#%s)', $label, $id ) : sprintf( '#%s', $id );
	}

	/** R-ERROR-UX envelope for a blocked create. */
	public static function create_blocked_payload( array $row ): array {
		$status = (string) ( $row['status'] ?? '' );
		$hint   = $status === 'connected'
			? 'Tài khoản đó đang kết nối — dùng luôn, không cần tạo thêm.'
			: 'Mở tài khoản đó và bấm "Đăng nhập lại" để quét QR, thay vì tạo tài khoản mới.';
		return array(
			'ok'            => false,
			'success'       => false,
			'code'          => 'duplicate_phone',
			'reason_bucket' => 'duplicate_phone',
			'message'       => 'SĐT này đã có tài khoản Zalo Cá nhân trên website: ' . self::describe( $row ) . '.',
			'hint'          => $hint,
			'help_code'     => 'zalo_duplicate_account',
			'duplicate_of'  => array(
				'bridge_account_id' => (string) ( $row['bridge_account_id'] ?? '' ),
				'crm_inbox_id'      => (int) ( $row['crm_inbox_id'] ?? 0 ),
				'status'            => $status,
			),
		);
	}

	/** R-ERROR-UX envelope for a blocked QR start/reset. */
	public static function qr_blocked_payload( array $sibling, string $operation_id, string $request_id ): array {
		return array(
			'ok'               => false,
			'success'          => false,
			'operation_status' => 'blocked',
			'code'             => 'duplicate_zalo_login',
			'reason_bucket'    => 'duplicate_zalo_login',
			'stage'            => 'account_scope',
			'message'          => 'Số Zalo này đang kết nối ở tài khoản khác: ' . self::describe( $sibling ) . '. Đăng nhập QR ở đây sẽ đẩy tài khoản kia ra.',
			'hint'             => 'Hai tài khoản đang trùng một SĐT. Dùng tài khoản đang kết nối và xoá tài khoản trùng này (lịch sử CRM vẫn được giữ).',
			'help_code'        => 'zalo_duplicate_account',
			'duplicate_of'     => array(
				'bridge_account_id' => (string) ( $sibling['id'] ?? '' ),
				'status'            => (string) ( $sibling['status'] ?? '' ),
			),
			'operation_id'     => sanitize_text_field( $operation_id ),
			'request_id'       => sanitize_text_field( $request_id ),
		);
	}
}
