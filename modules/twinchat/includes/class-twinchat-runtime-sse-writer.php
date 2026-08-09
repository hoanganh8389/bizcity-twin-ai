<?php
/**
 * TwinChat SSE bridge for the canonical TwinBrain Runtime stream.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinChat_Runtime_SSE_Writer', false ) ) {
	return;
}

if ( ! class_exists( 'BizCity_Twin_SSE_Writer' ) ) {
	return;
}

final class BizCity_TwinChat_Runtime_SSE_Writer extends BizCity_Twin_SSE_Writer {

	/** @var callable */
	private $forward;

	/** @var string */
	private $final_token_buffer = '';

	/** @var array */
	private $final_token_last_payload = array();

	/** @var float */
	private $final_token_last_flush = 0.0;

	const FINAL_TOKEN_BATCH_CHARS = 240;
	const FINAL_TOKEN_BATCH_MS    = 40;

	public function __construct( $forward ) {
		// [2026-08-04 Johnny Chu] V3.1 — reuse TwinChat's already-open SSE stream; Runtime owns event sequencing.
		parent::__construct( false );
		$this->forward = is_callable( $forward ) ? $forward : static function () {};
	}

	public function emit( string $type, array $data = [] ): void {
		if ( $type === 'final_token' ) {
			// [2026-08-05 Johnny Chu] V3.1-MVP — coalesce tiny final deltas to protect the SSE socket and browser reducer without changing the final_token contract.
			$this->final_token_buffer .= (string) ( $data['delta'] ?? '' );
			$this->final_token_last_payload = $data;
			$elapsed_ms = $this->final_token_last_flush > 0
				? ( microtime( true ) - $this->final_token_last_flush ) * 1000
				: self::FINAL_TOKEN_BATCH_MS;
			if ( strlen( $this->final_token_buffer ) >= self::FINAL_TOKEN_BATCH_CHARS || $elapsed_ms >= self::FINAL_TOKEN_BATCH_MS ) {
				$this->flush_final_tokens();
			}
			return;
		}
		$this->flush_final_tokens();
		call_user_func( $this->forward, $type, $data );
	}

	public function maybe_heartbeat(): void {
		$this->flush_final_tokens();
		call_user_func( $this->forward, '__heartbeat', array() );
	}

	private function flush_final_tokens(): void {
		if ( $this->final_token_buffer === '' ) {
			return;
		}
		$payload = $this->final_token_last_payload;
		$payload['delta'] = $this->final_token_buffer;
		$payload['len'] = isset( $payload['len'] ) ? (int) $payload['len'] : strlen( $this->final_token_buffer );
		call_user_func( $this->forward, 'final_token', $payload );
		$this->final_token_buffer = '';
		$this->final_token_last_payload = array();
		$this->final_token_last_flush = microtime( true );
	}
}
