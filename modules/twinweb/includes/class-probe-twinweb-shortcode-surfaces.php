<?php
/**
 * BizCity Diagnostics — modules.twin_gpt.shortcode_surfaces probe.
 *
 * R-DDV: 3-layer evidence for Twin GPT block/float shortcode surfaces.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-18 (PHASE-TWINWEB-UI-SKINS UIS-4/UIS-5)
 */

// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV probe for block/float shortcode mount contracts.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Shortcode_Surfaces', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Shortcode_Surfaces implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twin_gpt.shortcode_surfaces'; }
	public function label(): string       { return 'Twin GPT · Shortcode Surfaces'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: [bizcity_twin surface="block|float"] shortcode rendering, multi-root mount config, and compact embed shell.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 86; }
	public function icon(): string        { return 'layout'; }
	public function estimate_ms(): int    { return 20; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$page_file = __DIR__ . '/class-twinweb-page.php';
		$app_file  = defined( 'BIZCITY_TWINWEB_DIR' )
			? BIZCITY_TWINWEB_DIR . 'ui/src/App.tsx'
			: dirname( __DIR__ ) . '/ui/src/App.tsx';
		$main_file = defined( 'BIZCITY_TWINWEB_DIR' )
			? BIZCITY_TWINWEB_DIR . 'ui/src/main.tsx'
			: dirname( __DIR__ ) . '/ui/src/main.tsx';
		$css_file  = defined( 'BIZCITY_TWINWEB_DIR' )
			? BIZCITY_TWINWEB_DIR . 'ui/src/index.css'
			: dirname( __DIR__ ) . '/ui/src/index.css';

		$page_src = is_readable( $page_file ) ? (string) file_get_contents( $page_file ) : '';
		$app_src  = is_readable( $app_file ) ? (string) file_get_contents( $app_file ) : '';
		$main_src = is_readable( $main_file ) ? (string) file_get_contents( $main_file ) : '';
		$css_src  = is_readable( $css_file ) ? (string) file_get_contents( $css_file ) : '';

		$disk_ok = '' !== $page_src
			&& false !== strpos( $page_src, 'content_requests_full_surface' )
			&& false !== strpos( $page_src, 'window.twinwebMounts' )
			&& false !== strpos( $page_src, 'data-tw-state' );
		$step = array(
			'label'  => 'Disk · class-twinweb-page.php shortcode surface markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'Page class has full-surface gate, per-root mount config and float state marker.'
				: 'Missing shortcode surface markers in class-twinweb-page.php.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) { $pass = false; }

		$fe_ok = false !== strpos( $main_src, 'getMountRoots' )
			&& false !== strpos( $main_src, 'twinwebMounts' )
			&& false !== strpos( $app_src, 'tw-float-launcher' )
			&& false !== strpos( $app_src, 'data-twinweb-surface' )
			&& false !== strpos( $css_src, 'tw-surface-block' )
			&& false !== strpos( $css_src, 'tw-surface-float' );
		$step = array(
			'label'  => 'Disk · FE multi-root + block/float shell markers',
			'status' => $fe_ok ? 'pass' : 'warn',
			'detail' => $fe_ok
				? 'FE source has multi-root mounting, per-surface data attributes, float launcher and block/float CSS.'
				: 'FE source markers missing or src not deployed. Dist-only deployments should verify built bundle/browser smoke.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		// FE source can be absent on dist-only deployment; warn only.

		$class_ok = class_exists( 'BizCity_TwinWeb_Page' );
		$method_ok = $class_ok && method_exists( 'BizCity_TwinWeb_Page', 'render_shortcode' );
		$step = array(
			'label'  => 'Loader · BizCity_TwinWeb_Page::render_shortcode()',
			'status' => ( $class_ok && $method_ok ) ? 'pass' : 'fail',
			'detail' => ( $class_ok && $method_ok )
				? 'Page class loaded and shortcode render method exists.'
				: ( ! $class_ok ? 'BizCity_TwinWeb_Page class not loaded.' : 'render_shortcode() method missing.' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $class_ok || ! $method_ok ) { $pass = false; }

		$runtime_ok = false;
		$runtime_detail = 'Runtime skipped because page class is not loaded.';
		if ( $class_ok && $method_ok ) {
			try {
				$page = BizCity_TwinWeb_Page::instance();
				$block_html = $page->render_shortcode( array(
					'surface'   => 'block',
					'skin'      => 'claude',
					'height'    => '620px',
					'max_width' => '760px',
					'align'     => 'center',
				) );
				$float_html = $page->render_shortcode( array(
					'surface'  => 'float',
					'skin'     => 'grok',
					'position' => 'bottom-right',
				) );

				$runtime_ok = false !== strpos( $block_html, 'data-tw-surface="block"' )
					&& false !== strpos( $block_html, 'data-tw-skin="claude"' )
					&& false !== strpos( $block_html, 'window.twinwebMounts' )
					&& false !== strpos( $float_html, 'data-tw-surface="float"' )
					&& false !== strpos( $float_html, 'data-tw-state="closed"' )
					&& false !== strpos( $float_html, 'data-tw-skin="grok"' );
				$runtime_detail = $runtime_ok
					? 'Synthetic block/float shortcode render returns expected data attributes and mount config.'
					: 'Synthetic shortcode render missing expected block/float attributes.';
			} catch ( Throwable $e ) {
				$runtime_detail = 'Exception while rendering synthetic shortcodes: ' . $e->getMessage();
			}
		}

		$step = array(
			'label'  => 'Runtime · synthetic block/float shortcode render',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT block/float shortcode surfaces are wired for PHP render + FE mounting.'
				: 'Twin GPT shortcode surface contract is incomplete.',
			'error'    => $pass ? '' : 'twinweb_shortcode_surfaces_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-page.php and TwinWeb UI mount files for surface="block|float" markers.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no artifacts to clean up.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_Shortcode_Surfaces', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_Shortcode_Surfaces();
	}
	return $probes;
} );