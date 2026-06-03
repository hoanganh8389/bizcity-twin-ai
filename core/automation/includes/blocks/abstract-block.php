<?php
/**
 * BizCity_Automation_Block_Base — abstract block với helpers chung.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

abstract class BizCity_Automation_Block_Base implements BizCity_Automation_Block {

	/**
	 * Resolve `{{token}}` references trong scalar string từ $ctx (đệ quy 1 level).
	 *
	 * Hỗ trợ: `{{trigger.text}}`, `{{n_xxx.output}}`, `{{kg.snippet}}`
	 * (sau khi runner đã alias upstream output → key ngắn).
	 *
	 * @param mixed $value
	 * @param array $ctx
	 * @return mixed
	 */
	protected function resolve( $value, array $ctx ) {
		if ( ! is_string( $value ) || strpos( $value, '{{' ) === false ) {
			return $value;
		}
		return preg_replace_callback( '/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ( $m ) use ( $ctx ) {
			$parts = explode( '.', $m[1] );
			$node  = $ctx;
			foreach ( $parts as $p ) {
				if ( is_array( $node ) && array_key_exists( $p, $node ) ) {
					$node = $node[ $p ];
				} else {
					return $m[0];
				}
			}
			if ( is_scalar( $node ) ) { return (string) $node; }
			return wp_json_encode( $node );
		}, $value );
	}

	/** Convenience: log step output dùng error_log khi WP_DEBUG. */
	protected function debug( string $msg ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[bizcity-automation][' . $this->id() . '] ' . $msg );
		}
	}

	/**
	 * [2026-06-02 Johnny Chu] R-CRON-META — block-level event bus.
	 * Block chạy trong cron context (runner dispatch_async) → ghi evidence vào
	 * bizcity_cron_runs.meta để diagnostic có thể tail. No-op nếu chưa load.
	 */
	protected function note_event( string $name, array $data ): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) { return; }
		try {
			BizCity_Cron_Manager::instance()->note_event( $name, array_merge(
				array( 'block_id' => $this->id() ),
				$data
			) );
		} catch ( \Throwable $e ) {
			// Never let evidence write break the runner.
		}
	}
}
