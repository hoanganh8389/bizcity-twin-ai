<?php
/**
 * BizCity Diagnostics — modules.twin_gpt.skin_renderer probe.
 *
 * R-DDV: 3-layer evidence for Twin GPT public skin token renderer.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-18 (PHASE-TWINWEB-UI-SKINS UIS-3)
 */

// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV probe for public skin renderer tokens and FE markers.
defined( 'ABSPATH' ) || exit;

$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Skin_Renderer', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Skin_Renderer implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twin_gpt.skin_renderer'; }
	public function label(): string       { return 'Twin GPT · Public Skin Renderer'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: public skin CSS tokens, FE data-twinweb-skin marker, and effective appearance payload.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 85; }
	public function icon(): string        { return 'paintbrush'; }
	public function estimate_ms(): int    { return 20; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;
		$skins = array( 'chatgpt', 'claude', 'perplexity', 'gemini', 'grok' );

		$module_root = defined( 'BIZCITY_TWINWEB_DIR' ) ? BIZCITY_TWINWEB_DIR : dirname( __DIR__ ) . '/';
		$css_file = $module_root . 'ui/src/styles/tokens.css';
		$app_file = $module_root . 'ui/src/App.tsx';
		$config_file = $module_root . 'ui/src/api/effectiveConfig.ts';
		$page_file = __DIR__ . '/class-twinweb-page.php';
		$dist_manifest = $module_root . 'ui/dist/.vite/manifest.json';
		$dist_assets_dir = $module_root . 'ui/dist/assets';

		$css_src = is_readable( $css_file ) ? (string) file_get_contents( $css_file ) : '';
		$app_src = is_readable( $app_file ) ? (string) file_get_contents( $app_file ) : '';
		$config_src = is_readable( $config_file ) ? (string) file_get_contents( $config_file ) : '';
		$page_src = is_readable( $page_file ) ? (string) file_get_contents( $page_file ) : '';
		$dist_ok = is_readable( $dist_manifest ) || is_dir( $dist_assets_dir );

		$missing_css = array();
		foreach ( $skins as $skin ) {
			if ( false === strpos( $css_src, '[data-twinweb-skin="' . $skin . '"]' ) ) {
				$missing_css[] = $skin;
			}
		}
		$css_source_ok = '' !== $css_src && empty( $missing_css )
			&& false !== strpos( $css_src, '--background' )
			&& false !== strpos( $css_src, '--primary' )
			&& false !== strpos( $css_src, '--border' );
		// [2026-07-18 Johnny Chu] SPRINT-30 DDV-FE-DIST — production may ship only built UI artifacts, not React/CSS src.
		$css_ok = $css_source_ok || ( '' === $css_src && $dist_ok );
		$step = array(
			'label'  => 'Disk · skin CSS token selectors for 5 skins',
			'status' => $css_ok ? 'pass' : 'fail',
			'detail' => $css_source_ok
				? 'tokens.css defines data-twinweb-skin selectors and core CSS variables for all 5 skins.'
				: ( $dist_ok ? 'React/CSS source is absent on this deployment; built TwinWeb UI artifact exists, runtime payload validates renderer contract.' : 'Missing skin CSS selectors/variables: ' . implode( ', ', $missing_css ) ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $css_ok ) { $pass = false; }

		$fe_source_ok = false !== strpos( $app_src, 'data-twinweb-skin' )
			&& false !== strpos( $app_src, 'configuredSkin' )
			&& false !== strpos( $config_src, 'EffectiveAppearance' )
			&& false !== strpos( $config_src, 'EffectiveSkin' );
		$step = array(
			'label'  => 'Disk · FE source marker or built manifest available',
			'status' => ( $fe_source_ok || $dist_ok ) ? 'pass' : 'warn',
			'detail' => $fe_source_ok
				? 'FE source reads appearance and renders data-twinweb-skin.'
				: ( $dist_ok ? 'Source markers not readable, but built Vite manifest exists for dist-only deployment.' : 'FE source markers and dist manifest missing.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $fe_source_ok && ! $dist_ok ) { $pass = false; }

		$url_override_ok = false !== strpos( $page_src, '$_GET[\'skin\']' )
			&& false !== strpos( $page_src, '$query_skin' )
			&& false !== strpos( $app_src, 'getUrlSkinOverride' )
			&& false !== strpos( $app_src, 'resolveSkin' );
		$url_override_dist_ok = false !== strpos( $page_src, '$_GET[\'skin\']' )
			&& false !== strpos( $page_src, '$query_skin' )
			&& '' === $app_src
			&& $dist_ok;
		$step = array(
			'label'  => 'Disk · /gpt/?skin=... override markers',
			'status' => ( $url_override_ok || $url_override_dist_ok ) ? 'pass' : 'warn',
			'detail' => $url_override_ok
				? 'PHP injects query skin and FE resolves URL/shortcode skin against effective allowed skins.'
				: ( $url_override_dist_ok ? 'PHP injects query skin; React source is absent on dist-only deployment, built artifact is present.' : 'URL skin override markers missing. /gpt/?skin=claude may not override renderer skin.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$rest_ok = class_exists( 'BizCity_TwinWeb_REST' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_effective_config' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_effective_skins' );
		$step = array(
			'label'  => 'Loader · effective config/skins methods loaded',
			'status' => $rest_ok ? 'pass' : 'fail',
			'detail' => $rest_ok
				? 'BizCity_TwinWeb_REST exposes get_effective_config() and get_effective_skins().'
				: 'BizCity_TwinWeb_REST missing effective config/skins methods.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $rest_ok ) { $pass = false; }

		$runtime_ok = false;
		$runtime_detail = 'Runtime skipped because REST class is not ready.';
		if ( $rest_ok && class_exists( 'WP_REST_Request' ) ) {
			try {
				$rest = BizCity_TwinWeb_REST::instance();
				// [2026-07-18 Johnny Chu] SPRINT-28 DDV-FIX — avoid stale effective-config object cache hiding newly added appearance fields.
				if ( function_exists( 'wp_cache_flush_group' ) ) {
					wp_cache_flush_group( 'bizcity_twinweb' );
				}
				$response = $rest->get_effective_config( new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/config/effective' ) );
				$data = $this->response_data( $response );
				$appearance = isset( $data['appearance'] ) && is_array( $data['appearance'] ) ? $data['appearance'] : array();
				$runtime_markers = array(
					'default_skin'  => isset( $appearance['default_skin'] ) && (string) $appearance['default_skin'] !== '',
					'skins_array'   => isset( $appearance['skins'] ) && is_array( $appearance['skins'] ),
					'surfaces_array'=> isset( $appearance['surfaces'] ) && is_array( $appearance['surfaces'] ),
					'surface_page'  => isset( $appearance['surfaces'] ) && is_array( $appearance['surfaces'] ) && isset( $appearance['surfaces']['page'] ),
					'cp_ver'        => isset( $appearance['cp_ver'] ) && (string) $appearance['cp_ver'] !== '',
				);
				$runtime_ok = ! in_array( false, $runtime_markers, true );
				$runtime_detail = $runtime_ok
					? 'Effective config includes appearance.default_skin, skins[], surfaces.page and cp_ver.'
					: 'Effective config appearance missing markers: ' . implode( ', ', array_keys( array_filter( $runtime_markers, static function ( $ok ) { return ! $ok; } ) ) );
			} catch ( Throwable $e ) {
				$runtime_detail = 'Exception while building effective config: ' . $e->getMessage();
			}
		}

		$step = array(
			'label'  => 'Runtime · /config/effective appearance renderer payload',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Twin GPT public skin renderer contract is ready.' : 'Twin GPT skin renderer contract is incomplete.',
			'error'    => $pass ? '' : 'twinweb_skin_renderer_failed',
			'fix_hint' => $pass ? '' : 'Check tokens.css, App.tsx and /config/effective appearance payload.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts to clean up.
	}

	private function response_data( $response ) {
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : array();
		}
		if ( is_array( $response ) ) {
			return $response;
		}
		return array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Skin_Renderer', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Skin_Renderer();
	}
	return $probes;
} );