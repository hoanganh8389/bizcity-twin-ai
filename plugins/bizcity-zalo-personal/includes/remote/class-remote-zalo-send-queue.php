<?php
/**
 * Remote Zalo Hub — retry queue for throttled/retryable sends (B9, doc 04 §6.5 row 2).
 *
 * Guide §6: 30 distinct threads/nick/hour and 20 messages/minute/key are hard limits, not bugs. The 31st
 * new customer in an hour — or any burst past the per-minute cap — gets a 429 from the provider. This
 * queue is what stops that from being a lost reply: it holds the message, honours `Retry-After`, and
 * retries with the EXACT SAME `Idempotency-Key` every time. That is what makes the retry safe even though
 * this queue is intentionally independent of `BizCity_CRM_Outbound_Dispatcher`'s own claim/mutation store
 * (touching that shared, all-channels file was judged out of proportion to one transport's rate limit) —
 * the third-party API's own idempotency store is the single source of truth against a duplicate send to
 * the customer (guide §4.3), not this queue and not the CRM mutation store.
 *
 * On a successful (or terminally failed) retry this writes the outcome back to the SAME CRM message row
 * via `BizCity_CRM_Repository::update_message_delivery()` — the exact method the dispatcher itself calls —
 * so the CRM Inbox is never left showing "đang gửi" for a message that actually went out (or actually died).
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Send_Queue', false ) ) {
	return;
}

// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B9 — option-backed, no new table (mirrors the cursor store's shape).
final class BizCity_Remote_Zalo_Send_Queue {

	const OPTION = 'bizcity_zalo_remote_send_queue';
	const DEAD_OPTION = 'bizcity_zalo_remote_send_queue_dead';
	const MAX_ATTEMPTS = 8;
	const MAX_AGE_SECONDS = 86400; // 24h — matches the guide's own event/message retention horizon (R-3).
	const MAX_DEAD_KEEP = 100;

	/** @var callable|null test seam: () => int */
	public static $clock = null;
	/** @var object|null test seam: an object with send_message()/() and account_id() helpers; default = real client */
	public static $client = null;
	/** @var callable|null test seam: (int $message_id, array $result) => bool */
	public static $write_back = null;

	public static function reset_seams(): void { self::$clock = self::$client = self::$write_back = null; }

	/**
	 * @param array $item { bridge_account_id, thread, body (already-built provider payload), idempotency_key,
	 *                      message_id, retry_after (seconds), code (why it was queued) }
	 */
	public static function enqueue( array $item ): void {
		$key = (string) ( $item['idempotency_key'] ?? '' );
		if ( '' === $key ) { return; } // never queue something we could not safely retry under the same key.
		$all = self::all();
		$existing = $all[ $key ] ?? array();
		$attempts = (int) ( $existing['attempts'] ?? 0 ) + 1;
		$queued_at = (int) ( $existing['queued_at'] ?? self::now() );
		if ( $attempts > self::MAX_ATTEMPTS || ( self::now() - $queued_at ) > self::MAX_AGE_SECONDS ) {
			self::give_up( $key, array_merge( $existing, $item, array( 'attempts' => $attempts ) ), 'remote_send_queue_exhausted' );
			return;
		}
		$retry_after = max( 1, min( 3600, (int) ( $item['retry_after'] ?? 60 ) ) );
		$all[ $key ] = array(
			'bridge_account_id' => (string) ( $item['bridge_account_id'] ?? '' ),
			'thread'            => (string) ( $item['thread'] ?? '' ),
			'body'              => is_array( $item['body'] ?? null ) ? $item['body'] : array(),
			'idempotency_key'   => $key,
			'message_id'        => (int) ( $item['message_id'] ?? 0 ),
			'code'              => sanitize_key( (string) ( $item['code'] ?? '' ) ),
			'attempts'          => $attempts,
			'queued_at'         => $queued_at,
			'not_before'        => self::now() + $retry_after,
		);
		update_option( self::OPTION, $all, false );
	}

	/** Remove without retrying (e.g. the send actually succeeded through another path before the queue ran). */
	public static function forget( string $idempotency_key ): void {
		$all = self::all();
		if ( isset( $all[ $idempotency_key ] ) ) { unset( $all[ $idempotency_key ] ); update_option( self::OPTION, $all, false ); }
	}

	/** Retry every item whose backoff has elapsed. Safe to call from cron every tick — a lock is not needed:
	 *  each item is retried at most once per call because `not_before` is bumped before the next tick runs. */
	public static function tick( int $limit = 20 ): array {
		$summary = array( 'retried' => 0, 'sent' => 0, 'requeued' => 0, 'dropped' => 0 );
		if ( ! is_object( self::$client ) && ! class_exists( 'BizCity_Remote_Zalo_Hub_Client' ) ) { return $summary; }
		$all = self::all();
		$now = self::now();
		$due = array_filter( $all, static function ( $row ) use ( $now ) { return (int) ( $row['not_before'] ?? 0 ) <= $now; } );
		$client = self::client();
		foreach ( array_slice( $due, 0, max( 1, $limit ), true ) as $key => $item ) {
			$summary['retried']++;
			$result = $client->send_message( (string) $item['bridge_account_id'], (string) $item['thread'], (array) $item['body'], $key );
			if ( ! empty( $result['ok'] ) ) {
				self::write_back( (int) $item['message_id'], array( 'outcome' => 'sent', 'platform' => 'zalo_personal', 'error' => '' ) );
				self::forget( $key );
				$summary['sent']++;
				continue;
			}
			$code = (string) ( $result['error']['code'] ?? 'remote_unreachable' );
			$retryable = ! empty( $result['error']['retryable'] ) && 'remote_outcome_unknown' !== $code;
			if ( ! $retryable ) {
				self::give_up( $key, $item, $code );
				$summary['dropped']++;
				continue;
			}
			self::enqueue( array_merge( $item, array( 'code' => $code, 'retry_after' => $result['error']['retry_after'] ?? 60 ) ) );
			$summary['requeued']++;
		}
		return $summary;
	}

	/** For C4/UI (doc 32/52 LX-1's `settings.queue`): counts + the oldest pending item's age, never message content. */
	public static function stats(): array {
		$all = self::all();
		$dead = self::dead();
		$oldest = null;
		foreach ( $all as $row ) {
			$at = (int) ( $row['queued_at'] ?? 0 );
			if ( null === $oldest || ( $at > 0 && $at < $oldest ) ) { $oldest = $at; }
		}
		return array(
			'pending'         => count( $all ),
			'dead'            => count( $dead ),
			'oldest_queued_at'=> $oldest ? gmdate( 'c', $oldest ) : null,
		);
	}

	/* ---------------- internals ---------------- */

	private static function give_up( string $key, array $item, string $code ): void {
		self::write_back( (int) ( $item['message_id'] ?? 0 ), array( 'outcome' => 'failed', 'platform' => 'zalo_personal', 'error' => $code ) );
		self::forget( $key );
		$dead = self::dead();
		$dead[ $key ] = array( 'message_id' => (int) ( $item['message_id'] ?? 0 ), 'code' => sanitize_key( $code ), 'attempts' => (int) ( $item['attempts'] ?? 0 ), 'gave_up_at' => self::now() );
		if ( count( $dead ) > self::MAX_DEAD_KEEP ) {
			uasort( $dead, static function ( $a, $b ) { return ( $b['gave_up_at'] ?? 0 ) <=> ( $a['gave_up_at'] ?? 0 ); } );
			$dead = array_slice( $dead, 0, self::MAX_DEAD_KEEP, true );
		}
		update_option( self::DEAD_OPTION, $dead, false );
	}

	private static function write_back( int $message_id, array $result ): void {
		if ( $message_id <= 0 ) { return; } // nothing to update — the CRM row id was not available to the caller (no adapter dispatch, e.g. a test/manual send).
		if ( is_callable( self::$write_back ) ) { call_user_func( self::$write_back, $message_id, $result ); return; }
		if ( class_exists( 'BizCity_CRM_Repository' ) ) { BizCity_CRM_Repository::update_message_delivery( $message_id, $result ); }
	}

	private static function client() { return is_object( self::$client ) ? self::$client : new BizCity_Remote_Zalo_Hub_Client(); }
	private static function all(): array { $raw = get_option( self::OPTION, array() ); return is_array( $raw ) ? $raw : array(); }
	private static function dead(): array { $raw = get_option( self::DEAD_OPTION, array() ); return is_array( $raw ) ? $raw : array(); }
	private static function now(): int { return is_callable( self::$clock ) ? (int) call_user_func( self::$clock ) : time(); }
}
