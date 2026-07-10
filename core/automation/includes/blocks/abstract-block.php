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
				} elseif ( is_string( $node ) && $node !== '' ) {
					// [2026-06-15 Johnny Chu] PHASE-0 — support JSON string sub-field
					// traversal. Enables: {{extract.output.title}}, {{extract.output.when}}.
					$decoded = json_decode( $node, true );
					if ( is_array( $decoded ) && array_key_exists( $p, $decoded ) ) {
						$node = $decoded[ $p ];
					} else {
						return $m[0];
					}
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

	/**
	 * [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound provenance.
	 *
	 * Mọi action block tạo CRM event qua scheduler PHẢI gọi helper này để
	 * merge `$ctx['trigger']['inbound']` (do matcher inject) vào metadata.
	 * Scheduler Completion Notifier dùng `metadata.inbound.{platform,chat_id}`
	 * để reply về đúng kênh khi event done. Thiếu inbound → user mất tin
	 * "✅ Đã đăng FB xong …".
	 *
	 * @param array $ctx        Runner context (`['trigger'=>[...], '_run_id'=>..., '_workflow_id'=>...]`).
	 * @param array $own_fields Metadata-specific fields của action (fb_page_id, web_post_id, …).
	 * @return array Metadata array với block `inbound` + `_workflow` audit attached.
	 */
	protected function build_event_metadata( array $ctx, array $own_fields ): array {
		$base = array();

		// Forward inbound provenance.
		$trigger = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		if ( isset( $trigger['inbound'] ) && is_array( $trigger['inbound'] ) && ! empty( $trigger['inbound'] ) ) {
			$base['inbound'] = $trigger['inbound'];
		} else {
			// Backfill từ raw trigger fields nếu matcher chưa attach (vd manual run).
			$platform_raw = (string) ( $trigger['_trigger'] ?? $trigger['platform'] ?? '' );
			$chat_id      = (string) ( $trigger['chat_id'] ?? '' );
			if ( $chat_id !== '' ) {
				$platform = $platform_raw !== ''
					? strtoupper( str_replace( array( 'zalo_inbound', 'fb_inbound', 'tg_inbound', 'webchat_inbound' ),
						array( 'ZALO', 'FACEBOOK', 'TELEGRAM', 'WEBCHAT' ), $platform_raw ) )
					: '';
				$base['inbound'] = array(
					'platform'   => $platform !== '' ? $platform : 'UNKNOWN',
					'chat_id'    => $chat_id,
					'user_id'    => (string) ( $trigger['user_id'] ?? '' ),
					'account_id' => (string) ( $trigger['account_id'] ?? '' ),
					'message_id' => (string) ( $trigger['mid'] ?? '' ),
					'raw_text'   => (string) ( $trigger['text'] ?? '' ),
				);
			}
		}

		// Workflow audit breadcrumb (optional, useful for forensic).
		$wf_id = (int) ( $ctx['_workflow_id'] ?? 0 );
		$rid   = (string) ( $ctx['_run_id'] ?? '' );
		if ( $wf_id > 0 || $rid !== '' ) {
			$base['_workflow'] = array_filter( array(
				'workflow_id' => $wf_id ?: null,
				'run_id'      => $rid !== '' ? $rid : null,
				'block_id'    => $this->id(),
			) );
		}

		return array_merge( $base, $own_fields );
	}
}
