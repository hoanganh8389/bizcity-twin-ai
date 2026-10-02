<?php
/**
 * Remote Zalo Hub — last connection check result (E3).
 *
 * Records the outcome of the most recent "Lưu và kiểm tra" / "Kiểm tra lại" run so the settings UI
 * can show a stable ok/warn/fail state and timestamp without re-probing on every page load. Never
 * stores the base URL, key, `zaloUid`, or message content — only counts and machine codes.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Last_Check', false ) ) {
	return;
}

// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — one place to read/write the last-check outcome; the option
// this writes to is separate from the credential option (`bizcity_zalo_remote_conn`) so a probe result
// never shares a write path with the encrypted key.
final class BizCity_Remote_Zalo_Last_Check {

	const OPTION = 'bizcity_zalo_remote_last_check';

	/**
	 * @param array{status?:string,code?:string,checks?:array,accounts_total?:int,accounts_running?:int,request_id?:string,by_user_id?:int,key_fingerprint?:string} $fields
	 */
	public static function record( array $fields ): array {
		$status = (string) ( $fields['status'] ?? 'fail' );
		$checks = array();
		foreach ( (array) ( $fields['checks'] ?? array() ) as $check ) {
			if ( is_array( $check ) && isset( $check['id'], $check['status'] ) ) {
				$checks[] = array(
					'id'     => sanitize_key( (string) $check['id'] ),
					'status' => in_array( $check['status'], array( 'ok', 'warn', 'fail' ), true ) ? $check['status'] : 'fail',
				);
			}
		}
		$row = array(
			'at'               => gmdate( 'c' ),
			'status'           => in_array( $status, array( 'ok', 'warn', 'fail' ), true ) ? $status : 'fail',
			'code'             => sanitize_key( (string) ( $fields['code'] ?? '' ) ),
			'checks'           => $checks,
			'accounts_total'   => max( 0, (int) ( $fields['accounts_total'] ?? 0 ) ),
			'accounts_running' => max( 0, (int) ( $fields['accounts_running'] ?? 0 ) ),
			'request_id'       => substr( (string) ( $fields['request_id'] ?? '' ), 0, 100 ),
			'by_user_id'       => max( 0, (int) ( $fields['by_user_id'] ?? 0 ) ),
			'key_fingerprint'  => substr( (string) ( $fields['key_fingerprint'] ?? '' ), 0, 16 ),
		);
		update_option( self::OPTION, $row, false );
		return $row;
	}

	public static function get(): array {
		$row = get_option( self::OPTION, array() );
		return is_array( $row ) ? $row : array();
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Turn a `list_accounts()`-shaped probe result into a `record()` payload. Base connectivity/auth/scope
	 * classification only — cross-referencing which accounts are LINKED and running is C4/E5b's job
	 * (it needs the local mapping repo, which this class does not touch), so a probe with zero or
	 * all-stopped accounts is reported `warn`, never silently `ok`.
	 */
	public static function from_probe( array $probe, int $by_user_id, string $key_fingerprint ): array {
		if ( empty( $probe['ok'] ) ) {
			return array(
				'status'          => 'fail',
				'code'            => (string) ( $probe['error']['code'] ?? 'remote_unreachable' ),
				'checks'          => array( array( 'id' => 'auth', 'status' => 'fail' ) ),
				'request_id'      => (string) ( $probe['request_id'] ?? '' ),
				'by_user_id'      => $by_user_id,
				'key_fingerprint' => $key_fingerprint,
			);
		}
		$items = is_array( $probe['data']['items'] ?? null ) ? $probe['data']['items'] : array();
		$total = count( $items );
		$running = 0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && 'running' === (string) ( $item['status'] ?? '' ) ) {
				$running++;
			}
		}
		$status = ( $total > 0 && $running > 0 ) ? 'ok' : 'warn';
		$checks = array( array( 'id' => 'auth', 'status' => 'ok' ), array( 'id' => 'scope', 'status' => 'ok' ) );
		$checks[] = array( 'id' => 'accounts', 'status' => $total > 0 ? 'ok' : 'warn' );
		return array(
			'status'           => $status,
			'code'             => 'ok' === $status ? 'remote_ok' : 'remote_no_running_account',
			'checks'           => $checks,
			'accounts_total'   => $total,
			'accounts_running' => $running,
			'request_id'       => (string) ( $probe['request_id'] ?? '' ),
			'by_user_id'       => $by_user_id,
			'key_fingerprint'  => $key_fingerprint,
		);
	}
}
