<?php
/**
 * BizCity Channel Adapter Base — backward-compat base for BizCity_Channel_Adapter (PHASE 0.37 M3.W1)
 *
 * Existing channel adapters (BizCity_Zalo_Bot_Channel_Adapter, BizCity_Facebook_Bot_Channel_Adapter)
 * implement BizCity_Channel_Adapter directly. This abstract class adds:
 *   - health()           — R-CH-8 health probe with latency
 *   - describe_capabilities() — ['inbound','outbound']
 *   - make_wp_error()    — protected helper
 *   - test_connection()  — override for lightweight health ping
 *
 * Migration path (M3.W2): change existing adapters from
 *   class My_Adapter implements BizCity_Channel_Adapter
 * to:
 *   class My_Adapter extends BizCity_Channel_Adapter_Base
 * and remove the methods already provided here.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 * @see        PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md M3.W1
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-13 Johnny Chu] HOTFIX — interface_exists guard: BizCity_Channel_Magic_Link_Capable
// may not be present on server if deploy is partial. Fall back to base interface only.
if ( ! interface_exists( 'BizCity_Channel_Magic_Link_Capable' ) ) {
	$_bzc_magic_iface = __DIR__ . '/interface-channel-magic-link-capable.php';
	if ( file_exists( $_bzc_magic_iface ) ) {
		require_once $_bzc_magic_iface;
	}
	unset( $_bzc_magic_iface );
}

if ( interface_exists( 'BizCity_Channel_Magic_Link_Capable' ) ) {
	abstract class BizCity_Channel_Adapter_Base implements BizCity_Channel_Adapter, BizCity_Channel_Magic_Link_Capable {

	/* ═══════════════════════════════════════════
	 *  Required interface stubs
	 *  (keep the interface contract explicit so IDEs show them)
	 * ═══════════════════════════════════════════ */

	abstract public function get_platform(): string;
	abstract public function get_prefix(): string;
	abstract public function get_endpoints(): array;

	/**
	 * Default: accept all inbound (override for real verification).
	 */
	public function verify_webhook( array $request ): bool {
		return true;
	}

	/**
	 * Default stub — override with real normalization in M4.
	 */
	public function normalize_inbound( array $raw_data ): array {
		return array_merge( [
			'platform'    => $this->get_platform(),
			'chat_id'     => '',
			'user_id'     => '',
			'client_name' => '',
			'message'     => '',
			'attachments' => [],
			'event_type'  => 'message',
			'raw'         => $raw_data,
		], $raw_data );
	}

	/**
	 * Default stub — override with real dispatch.
	 */
	public function send_outbound( string $chat_id, string $message, array $options = [] ): bool {
		return false;
	}

	/* ═══════════════════════════════════════════
	 *  PHASE 3.5-WD — BizCity_Channel_Magic_Link_Capable
	 * ═══════════════════════════════════════════ */

	/**
	 * Default magic-link implementation: delegates to BizCity_CRM_Magic_Link::issue().
	 * Subclasses may override to generate platform-specific deep-links
	 * (e.g. tg://resolve… for Telegram, m.me/PAGE?ref=… for FB Messenger).
	 *
	 * [2026-06-13 Johnny Chu] PHASE-3.5-WD — generic fallback for all adapters
	 *
	 * @param string $chat_id
	 * @param array  $args
	 * @return array{token:string,url:string,expires_at:string,id:int}|WP_Error
	 */
	public function issue_magic_link( string $chat_id, array $args = array() ) {
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			return new WP_Error( 'bizcity_magic_link_unavailable', 'BizCity_CRM_Magic_Link class not loaded.' );
		}
		$args['platform'] = $this->get_platform();
		$args['chat_id']  = $chat_id;
		return BizCity_CRM_Magic_Link::issue( $args );
	}

	/* ═══════════════════════════════════════════
	 *  PHASE 0.37 additions
	 * ═══════════════════════════════════════════ */

	/**
	 * Health check — calls test_connection() and measures latency.
	 *
	 * R-CH-8: every adapter must implement health().
	 * Override test_connection() for a cheaper ping; override health() for full control.
	 *
	 * @return array{ok:bool|null, latency_ms:int, last_error:string, last_success_at:string, token_expires_at:string}
	 */
	public function health(): array {
		$start = microtime( true );
		$error = '';
		$ok    = null;

		try {
			$result = $this->test_connection();
			if ( is_array( $result ) ) {
				$ok    = ! empty( $result['success'] ) && ! empty( $result['ok'] ) || ( isset( $result['success'] ) && $result['success'] );
				$error = (string) ( $result['error'] ?? $result['note'] ?? '' );
			} else {
				$ok = (bool) $result;
			}
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		}

		return [
			'ok'              => $ok,
			'latency_ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'last_error'      => $error,
			'last_success_at' => '',
			'token_expires_at'=> '',
		];
	}

	/**
	 * Lightweight connection test. Override for real check.
	 *
	 * @return array{success:bool, error:string, note:string}
	 */
	protected function test_connection(): array {
		return [ 'success' => true, 'error' => '', 'note' => 'health() stub — override test_connection()' ];
	}

	/**
	 * Describe capabilities of this adapter.
	 *
	 * @return string[] E.g. ['inbound', 'outbound']
	 */
	public function describe_capabilities(): array {
		return [ 'inbound', 'outbound' ];
	}

	/**
	 * Helper: create a WP_Error with a consistent prefix.
	 */
	protected function make_wp_error( string $code, string $message ): \WP_Error {
		return new \WP_Error( 'bizcity_adapter_' . $code, $message );
	}
}
} else {
	// [2026-06-13 Johnny Chu] HOTFIX — fallback: interface file missing on server, define without MagicLink.
	abstract class BizCity_Channel_Adapter_Base implements BizCity_Channel_Adapter {
		abstract public function get_platform(): string;
		abstract public function get_prefix(): string;
		abstract public function get_endpoints(): array;
		public function verify_webhook( array $request ): bool { return true; }
		public function normalize_inbound( array $raw_data ): array {
			return array_merge( array(
				'platform' => $this->get_platform(), 'chat_id' => '',
				'user_id' => '', 'client_name' => '', 'message' => '',
				'attachments' => array(), 'event_type' => 'message', 'raw' => $raw_data,
			), $raw_data );
		}
		public function send_outbound( string $chat_id, string $message, array $options = array() ): bool { return false; }
		public function health(): array {
			return array( 'ok' => null, 'latency_ms' => 0, 'last_error' => 'MagicLink interface missing', 'last_success_at' => '', 'token_expires_at' => '' );
		}
		public function test_connection(): array { return array( 'success' => false, 'error' => 'interface_missing' ); }
		public function describe_capabilities(): array { return array( 'inbound', 'outbound' ); }
		protected function make_wp_error( string $code, string $message ): \WP_Error {
			return new \WP_Error( 'bizcity_adapter_' . $code, $message );
		}
	}
}
