<?php
/**
 * BizCity Diagnostics — astro.faa2_chart_svg probe (PHASE-FAA2-DDV · 2026-07-04).
 *
 * Validates the FAA2 natal-wheel-chart (url-only) pipeline end-to-end:
 *   - Hub chart-svg route → faa2_western:natal_wheel_chart
 *   - Provider returns { success:true, url:'https://...svg', _source:'faa2_natal_wheel_chart' }
 *   - Client parsers handle 'url' key (not legacy 'image_url'/'svg')
 *
 * 3-layer evidence (R-DDV):
 *   - Disk    : provider file exists at canonical bizcity-llm-router path.
 *   - Loader  : Astro_Provider_FAA2_Western class loaded + supports('natal_wheel_chart')
 *               + BizCity_Astro_Router::get_provider('faa2_western') returns provider.
 *   - Runtime : If provider is_ready(), call natal_wheel_chart() with Hanoi test birth
 *               data and assert success=true + url non-empty + _source correct.
 *               If API key absent → status=skip with 'api_key_missing' reason.
 *
 * Cost: 1 FAA2 API call when key is configured. Estimate: ~4 000 ms.
 * Severity: warning (gateway call; degraded = no wheel chart for users).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-04 (PHASE-FAA2-DDV)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-04 Johnny Chu] PHASE-FAA2-DDV — double-load guard.
if ( class_exists( 'BizCity_Probe_Astro_FAA2_Chart_SVG', false ) ) {
	return;
}

final class BizCity_Probe_Astro_FAA2_Chart_SVG implements BizCity_Diagnostics_Probe {

	// Birth data for runtime smoke test: Hanoi, 1990-01-15, 10:30 AM UTC+7
	const TEST_DAY      = 15;
	const TEST_MONTH    = 1;
	const TEST_YEAR     = 1990;
	const TEST_HOUR     = 10;
	const TEST_MINUTE   = 30;
	const TEST_LAT      = 21.0285;
	const TEST_LON      = 105.8542;
	const TEST_TZ       = 7.0;
	const TEST_CITY     = 'Hanoi';

	public function id(): string          { return 'astro.faa2_chart_svg'; }
	public function label(): string       { return 'FAA2 Natal Wheel Chart (url-only, PHASE-FAA2-DDV)'; }
	public function description(): string {
		return 'Verify Astro_Provider_FAA2_Western::natal_wheel_chart() trả { success:true, url:..., _source:"faa2_natal_wheel_chart" } — đường dẫn chart-svg FAA2 sau PHASE-FAA2-NEXT migration.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 45; }
	public function icon(): string        { return 'sparkles'; }
	public function estimate_ms(): int    { return 4000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Astro_Router' ) ) {
			return 'BizCity_Astro_Router chưa load. Đảm bảo bizcity-llm-router active + astro router bootstrapped.';
		}
		return true;
	}

	/**
	 * @param object $ctx  Probe context (emit_step available).
	 */
	public function run( $ctx ): array {
		$failures = array();

		// ---------------------------------------------------------------
		// LAYER 1 — DISK: provider file exists
		// ---------------------------------------------------------------
		$provider_file = WP_PLUGIN_DIR . '/bizcity-llm-router/includes/astro/class-astro-provider-faa2-western.php';
		$disk_ok = file_exists( $provider_file );
		$ctx->emit_step( array(
			'layer'  => 'disk',
			'label'  => 'Provider file',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'bizcity-llm-router/includes/astro/class-astro-provider-faa2-western.php ✓'
				: 'File MISSING: ' . $provider_file,
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_missing';
		}

		// ---------------------------------------------------------------
		// LAYER 2 — LOADER: class + supports() + router registration
		// ---------------------------------------------------------------
		$class_ok = class_exists( 'Astro_Provider_FAA2_Western' );
		$ctx->emit_step( array(
			'layer'  => 'loader',
			'label'  => 'Class Astro_Provider_FAA2_Western',
			'status' => $class_ok ? 'pass' : 'fail',
			'detail' => $class_ok
				? 'Class loaded.'
				: 'Class NOT found — check bizcity-llm-router/bootstrap or astro-rest includes.',
		) );
		if ( ! $class_ok ) {
			$failures[] = 'class_missing';
		}

		if ( $class_ok ) {
			// supports() check (no instance needed — static-like helper via temporary instance)
			$provider = null;
			if ( class_exists( 'BizCity_Astro_Router' ) ) {
				try {
					BizCity_Astro_Router::boot();
					$provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
				} catch ( \Exception $e ) {
					$provider = null;
				}
			}

			$supports_ok = $provider && method_exists( $provider, 'supports' ) && $provider->supports( 'natal_wheel_chart' );
			$ctx->emit_step( array(
				'layer'  => 'loader',
				'label'  => 'supports("natal_wheel_chart")',
				'status' => $supports_ok ? 'pass' : 'fail',
				'detail' => $supports_ok
					? 'faa2_western supports natal_wheel_chart ✓'
					: 'Provider missing or supports() does not include natal_wheel_chart. Run BizCity_Astro_Router::boot() first.',
			) );
			if ( ! $supports_ok ) {
				$failures[] = 'supports_missing';
			}
		}

		// ---------------------------------------------------------------
		// LAYER 3 — RUNTIME: live call (skip if provider not ready)
		// ---------------------------------------------------------------
		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Disk/Loader checks failed: ' . implode( ', ', $failures ),
				'failures' => $failures,
				'fix_hint' => 'Activate bizcity-llm-router + ensure FAA2 Western provider bootstrapped.',
			);
		}

		// Re-fetch provider for runtime call
		$provider = null;
		try {
			BizCity_Astro_Router::boot();
			$provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
		} catch ( \Exception $e ) {
			$provider = null;
		}

		if ( ! $provider || ! method_exists( $provider, 'is_ready' ) || ! $provider->is_ready() ) {
			$ctx->emit_step( array(
				'layer'  => 'runtime',
				'label'  => 'FAA2 API key check',
				'status' => 'skip',
				'detail' => 'FAA2 API key chưa cấu hình (is_ready()=false). Live call skipped.',
			) );
			return array(
				'status'  => 'skip',
				'summary' => 'FAA2 API key absent — skip live call. Configure key để chạy full runtime check.',
				'reason'  => 'api_key_missing',
			);
		}

		// Perform live natal_wheel_chart call
		$input = array(
			'day'      => self::TEST_DAY,
			'month'    => self::TEST_MONTH,
			'year'     => self::TEST_YEAR,
			'hour'     => self::TEST_HOUR,
			'minute'   => self::TEST_MINUTE,
			'latitude' => self::TEST_LAT,
			'longitude'=> self::TEST_LON,
			'timezone' => self::TEST_TZ,
			'city'     => self::TEST_CITY,
		);

		$t0  = microtime( true );
		$res = null;
		try {
			$res = $provider->natal_wheel_chart( $input );
		} catch ( \Exception $e ) {
			$res = array( 'success' => false, 'message' => $e->getMessage() );
		}
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$success = ! empty( $res['success'] );
		$url     = (string) ( $res['url'] ?? '' );
		$source  = (string) ( $res['_source'] ?? '' );

		$ctx->emit_step( array(
			'layer'  => 'runtime',
			'label'  => 'natal_wheel_chart() live call',
			'status' => ( $success && $url !== '' ) ? 'pass' : 'fail',
			'detail' => $success
				? sprintf( 'url=%s _source=%s (%d ms)', substr( $url, 0, 80 ) . ( strlen( $url ) > 80 ? '…' : '' ), $source, $ms )
				: 'FAILED: ' . ( $res['message'] ?? 'unknown error' ),
		) );

		if ( ! $success || $url === '' ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'natal_wheel_chart() failed: ' . ( $res['message'] ?? 'empty url' ),
				'response' => $res,
				'fix_hint' => 'Kiểm tra FAA2 API key + bizcity-llm-router log. url field phải là S3 https://… link. Xem class-astro-provider-faa2-western.php::natal_wheel_chart().',
			);
		}

		// Verify _source contract
		$source_ok = ( $source === 'faa2_natal_wheel_chart' );
		$ctx->emit_step( array(
			'layer'  => 'runtime',
			'label'  => '_source contract',
			'status' => $source_ok ? 'pass' : 'warn',
			'detail' => $source_ok
				? '_source === "faa2_natal_wheel_chart" ✓'
				: '_source is "' . $source . '" — expected "faa2_natal_wheel_chart". Client parsers may misidentify provider.',
		) );

		return array(
			'status'  => $source_ok ? 'pass' : 'warn',
			'summary' => sprintf(
				'FAA2 natal wheel chart OK — url length=%d, _source=%s (%d ms)',
				strlen( $url ),
				$source,
				$ms
			),
			'url'     => $url,
			'_source' => $source,
		);
	}

	public function cleanup(): void {
		// [2026-07-04 Johnny Chu] PHASE-FAA2-DDV — read-only probe; no state to clean.
	}
}
