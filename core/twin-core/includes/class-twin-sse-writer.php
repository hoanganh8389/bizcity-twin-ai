<?php
/**
 * Bizcity Twin AI — Twin SSE Writer
 *
 * Sprint 4.7d — Typed Server-Sent Events writer chuẩn cho mọi consumer của
 * `BizCity_Twin_Agent`. Handle:
 *   - SSE headers + buffering disable
 *   - Heartbeat 15s (chống Cloudflare/Nginx idle timeout)
 *   - 9 typed events: status, thinking, tool_call, tool_result, sources,
 *     token, token_rollback, complete, error
 *   - Auto rollback buffered tokens khi LLM phát tool_call sau khi streaming
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_SSE_Writer {

	const HEARTBEAT_SEC = 15;

	/** @var float */
	private $last_emit = 0.0;

	/** @var bool */
	private $headers_sent = false;

	/** @var string */
	private $token_buffer = '';

	/** @var int */
	private $tokens_flushed = 0;

	public function __construct( bool $send_headers = true ) {
		if ( $send_headers ) {
			$this->begin();
		}
		$this->maybe_attach_debug_bridge();
	}

	/**
	 * Forward `bizcity_intent_pipeline_log` entries (and especially the
	 * `twin_debug:*` ones produced by `BizCity_Twin_Debug::trace`) into the
	 * SSE stream as `event: debug` chunks. No-op when debug gate is off,
	 * so production keeps the stream lean.
	 */
	private function maybe_attach_debug_bridge(): void {
		if ( ! class_exists( 'BizCity_Twin_Debug' ) || ! BizCity_Twin_Debug::is_enabled() ) {
			return;
		}
		$self = $this;
		add_action(
			'bizcity_intent_pipeline_log',
			static function ( $step, $data, $level, $elapsed_ms ) use ( $self ) {
				// Drop noisy bookkeeping events.
				if ( in_array( $step, [ 'trace_begin', 'trace_end' ], true ) ) {
					return;
				}
				$self->emit( 'debug', [
					'step'  => (string) $step,
					'level' => (string) $level,
					'ms'    => (float) $elapsed_ms,
					'data'  => is_array( $data ) ? $data : [ 'value' => $data ],
				] );
			},
			10,
			4
		);
	}

	public function begin(): void {
		if ( $this->headers_sent ) return;
		$this->headers_sent = true;

		// Disable runtime compression *before* sending anything.
		@ini_set( 'zlib.output_compression', '0' );
		@ini_set( 'output_buffering', 'off' );
		@ini_set( 'implicit_flush', '1' );
		ignore_user_abort( true );
		set_time_limit( 0 );

		// Override headers WP REST already queued (Content-Type: application/json).
		// Must happen BEFORE ob_end_clean — otherwise the buffered body locks headers.
		if ( ! headers_sent() ) {
			header_remove( 'Content-Type' );
			header_remove( 'Content-Encoding' );
			header_remove( 'Content-Length' );
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'Cache-Control: no-cache, no-store, no-transform, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );      // nginx
			header( 'Content-Encoding: none' );     // LiteSpeed/Apache: forbid gzip
			header( 'X-Content-Type-Options: nosniff' );
			if ( function_exists( 'apache_setenv' ) ) {
				@apache_setenv( 'no-gzip', '1' );
			}
		}

		// Discard (NOT flush) any output buffers — flushing would emit WP REST's
		// JSON body and lock Content-Type back to application/json.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
		@ob_implicit_flush( 1 );

		$this->last_emit = microtime( true );
		// 4 KB padding to defeat Cloudflare/LiteSpeed buffering (matches stream-handler).
		echo ": sse-open\n" . str_repeat( ' ', 4096 ) . "\n\n";
		@flush();
	}

	/**
	 * Emit typed event.
	 *
	 * @param string $type   one of: status,thinking,tool_call,tool_result,sources,token,token_rollback,complete,error
	 * @param array  $data
	 */
	public function emit( string $type, array $data = [] ): void {
		$payload = wp_json_encode(
			[ 'type' => $type ] + $data,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		echo "event: {$type}\n";
		echo "data: {$payload}\n\n";
		// Defensive double-flush — some hosts (LiteSpeed shared, plugins
		// that re-open OB mid-request) buffer output between echo and the
		// kernel socket. ob_flush() drains any reopened buffer; flush()
		// pushes to the wire. Errors suppressed when no buffer is active.
		if ( ob_get_level() > 0 ) {
			@ob_flush();
		}
		@flush();
		$this->last_emit = microtime( true );
	}

	/** Stream a token chunk. Buffered so we can rollback if LLM emits a tool call mid-stream. */
	public function token( string $delta ): void {
		if ( '' === $delta ) return;
		$this->token_buffer .= $delta;
		$this->tokens_flushed++;
		$this->emit( 'token', [ 'text' => $delta ] );
	}

	/**
	 * Rollback buffered tokens — tells the FE to discard them because we
	 * detected a tool call and the answer must be regenerated after tool exec.
	 */
	public function rollback_tokens(): void {
		if ( '' === $this->token_buffer ) return;
		$this->emit( 'token_rollback', [
			'discarded_chars' => mb_strlen( $this->token_buffer ),
		] );
		$this->token_buffer  = '';
		$this->tokens_flushed = 0;
	}

	public function clear_token_buffer(): void {
		$this->token_buffer  = '';
		$this->tokens_flushed = 0;
	}

	public function get_token_buffer(): string {
		return $this->token_buffer;
	}

	/** Send heartbeat comment if idle > 15s. */
	public function maybe_heartbeat(): void {
		if ( microtime( true ) - $this->last_emit >= self::HEARTBEAT_SEC ) {
			echo ": heartbeat\n\n";
			@flush();
			$this->last_emit = microtime( true );
		}
	}

	public function close( array $final = [] ): void {
		$this->emit( 'complete', $final );
	}

	public function error( string $message, string $code = 'twin_agent_error' ): void {
		$this->emit( 'error', [ 'message' => $message, 'code' => $code ] );
	}
}
