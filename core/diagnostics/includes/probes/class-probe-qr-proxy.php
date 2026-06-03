<?php
/**
 * BizCity Diagnostics — qr.proxy probe (Server-side, bizcity.vn).
 *
 * R-DDV verify cho **Pretty URL** `/create-qr-code/` (bizcity-llm-router):
 *
 *   Layer 1 · DISK: includes/class-qr-proxy.php exists trên bizcity-llm-router.
 *   Layer 2 · LOADER: class `BizCity_QR_Proxy` loaded + hook `init` priority 0.
 *   Layer 3 · RUNTIME: `wp_remote_get( home_url('/create-qr-code/?data=ping&size=64') )`
 *      assert HTTP 200 + `content-type: image/*` + header `X-BizCity-QR: proxy`.
 *
 * **CHỈ chạy được trên server BizCity** (bizcity.vn / bizcity.ai). Trên client
 * site (R-GW-8: router KHÔNG được cài), `BizCity_QR_Proxy` không tồn tại →
 * `precondition()` trả WP_Error → BizCity Diagnostics smoke runner skip
 * (precheck-fail) thay vì báo FAIL — phù hợp R-DDV §3.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Scenario Builder MVP (2026-06-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_QR_Proxy implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'qr.proxy'; }
	public function label(): string       { return 'QR Proxy · /create-qr-code/ (server-only)'; }
	public function description(): string {
		return 'Verify Pretty URL /create-qr-code/ trên bizcity-llm-router server: hook init + live GET trả PNG/JPEG/SVG. Client site sẽ skip (router not installed per R-GW-8).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 80; }
	public function icon(): string        { return 'qrcode'; }
	public function estimate_ms(): int    { return 1500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_QR_Proxy' ) ) {
			return new WP_Error( 'router_not_installed',
				'bizcity-llm-router không cài trên site này (R-GW-8 client topology). Probe skip.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		// ── Layer 1 · DISK ─────────────────────────────────────────────
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$path       = $plugin_dir . '/bizcity-llm-router/includes/class-qr-proxy.php';
		$disk_ok    = is_readable( $path );
		$steps[] = $s = array(
			'label'  => 'Disk · class-qr-proxy.php',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? number_format( (int) filesize( $path ) ) . ' bytes' : 'MISSING',
		);
		$ctx->emit_step( $s );
		if ( ! $disk_ok ) {
			return self::fail( $steps, 'class-qr-proxy.php không tồn tại', 'file_missing',
				'Deploy bizcity-llm-router/includes/class-qr-proxy.php.' );
		}

		// ── Layer 2 · LOADER ───────────────────────────────────────────
		$has_init_hook = has_action( 'init', array( 'BizCity_QR_Proxy', 'maybe_handle' ) );
		$ok2 = $has_init_hook !== false;
		$steps[] = $s = array(
			'label'  => 'Loader · init hook BizCity_QR_Proxy::maybe_handle',
			'status' => $ok2 ? 'pass' : 'fail',
			'detail' => $ok2 ? ( 'priority=' . (int) $has_init_hook ) : 'NOT attached',
		);
		$ctx->emit_step( $s );
		if ( ! $ok2 ) {
			return self::fail( $steps, 'init hook chưa register', 'hook_missing',
				'Verify BizCity_QR_Proxy::boot() được gọi trên plugins_loaded trong bizcity-llm-router.php.' );
		}

		// ── Layer 3 · RUNTIME ──────────────────────────────────────────
		$url = add_query_arg( array(
			'data' => 'ping-' . wp_generate_password( 6, false, false ),
			'size' => 64,
			'ecc'  => 'L',
		), home_url( '/create-qr-code/' ) );

		$resp = wp_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'bizcity_diag_qr_proxy_sslverify', true ),
		) );
		if ( is_wp_error( $resp ) ) {
			$steps[] = $s = array(
				'label'  => 'Runtime · GET /create-qr-code/',
				'status' => 'fail',
				'detail' => 'wp_remote_get error: ' . $resp->get_error_message(),
			);
			$ctx->emit_step( $s );
			return self::fail( $steps, 'GET /create-qr-code/ failed', 'remote_error',
				'Kiểm hosting outbound + permalink rewrite (init priority 0).' );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$ctype = strtolower( (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
		$xqr   = (string) wp_remote_retrieve_header( $resp, 'x-bizcity-qr' );
		$body_len = strlen( (string) wp_remote_retrieve_body( $resp ) );

		$is_image = strpos( $ctype, 'image/' ) === 0;
		$pass3    = $code === 200 && $is_image && $xqr === 'proxy' && $body_len > 0;

		$steps[] = $s = array(
			'label'  => 'Runtime · HTTP 200 + image/* + X-BizCity-QR',
			'status' => $pass3 ? 'pass' : 'fail',
			'detail' => sprintf( 'http=%d ctype=%s x-bizcity-qr=%s bytes=%d',
				$code, $ctype ?: 'n/a', $xqr ?: 'n/a', $body_len ),
		);
		$ctx->emit_step( $s );
		if ( ! $pass3 ) {
			return self::fail( $steps, 'QR proxy không trả ảnh đúng shape',
				'qr_response_invalid',
				'Kiểm class-qr-proxy.php::handle() + upstream api.qrserver.com reachable.' );
		}

		// Optional: verify cache header presence (immutable Cache-Control).
		$cache_ctl = (string) wp_remote_retrieve_header( $resp, 'cache-control' );
		$has_cache = strpos( strtolower( $cache_ctl ), 'immutable' ) !== false
			|| strpos( strtolower( $cache_ctl ), 'max-age' ) !== false;
		$steps[] = array(
			'label'  => 'Runtime · Cache-Control header',
			'status' => $has_cache ? 'pass' : 'fail',
			'detail' => $cache_ctl ?: 'header missing',
		);
		$ctx->emit_step( end( $steps ) );

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'QR proxy live · %s · %d bytes', $ctype, $body_len ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_QR_Proxy';
	return $list;
} );
