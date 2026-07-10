<?php
/**
 * BizCity Diagnostics — astro.transit_resolver probe (PHASE-A C.0b · 2026-06-04).
 *
 * Validates the BizCoach Pro DB-first transit resolver per RFC §7.3.
 *
 * 3-layer evidence (R-DDV):
 *   - Disk    : resolver file exists at canonical path.
 *   - Loader  : `BizCoach_Pro_Astro_Transit_Resolver` class declared.
 *   - Runtime : ::instance() + resolve(<bogus_coachee>) returns structured
 *               array with expected keys; CAP filter `bizcity_twin_context_artifacts`
 *               has subscriber from bizcoach-pro.
 *
 * No gateway / API call → severity=info, estimate_ms=600. Safe to run
 * unattended. Fail-OPEN: any thrown exception → status=warn (not fatal).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-04 (PHASE-A C.0b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Astro_Transit_Resolver', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Transit_Resolver implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'astro.transit_resolver'; }
	public function label(): string       { return 'Astro Transit Resolver (DB-first, Sprint C.0b)'; }
	public function description(): string {
		return 'Kiểm tra resolver thiên-văn DB-first đã load + CAP filter cho mode=astro đã đăng ký.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 42; }
	public function icon(): string        { return 'sparkles'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		if ( ! defined( 'BCPRO_DIR' ) ) {
			return 'BCPRO_DIR chưa định nghĩa — bizcoach-pro chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps    = array();
		$failures = array();

		// 1. DISK
		$file = defined( 'BCPRO_DIR' ) ? BCPRO_DIR . 'includes/astro/class-astro-transit-resolver.php' : '';
		$disk_ok = ( $file !== '' && file_exists( $file ) );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'File exists: includes/astro/class-astro-transit-resolver.php'
				: 'File MISSING: ' . $file,
		);
		if ( ! $disk_ok ) { $failures[] = 'disk_missing'; }

		// 2. LOADER
		$loader_ok = class_exists( 'BizCoach_Pro_Astro_Transit_Resolver' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $loader_ok,
			'msg'   => $loader_ok
				? 'Class BizCoach_Pro_Astro_Transit_Resolver declared.'
				: 'Class NOT loaded — bootstrap chưa require_once class-astro-transit-resolver.php.',
		);
		if ( ! $loader_ok ) { $failures[] = 'class_missing'; }

		// 3. RUNTIME — instance + structured return shape
		if ( $loader_ok ) {
			try {
				$resolver = BizCoach_Pro_Astro_Transit_Resolver::instance();
				$bogus    = 999999999; // unlikely to exist in production DB
				$out      = $resolver->resolve( $bogus, 'day', array( 'user_id' => 0 ) );
				$shape_ok = is_array( $out )
					&& array_key_exists( 'coachee_id', $out )
					&& array_key_exists( 'period',     $out )
					&& array_key_exists( 'source',     $out )
					&& array_key_exists( 'markdown',   $out )
					&& array_key_exists( '_degraded',  $out );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $shape_ok,
					'msg'   => $shape_ok
						? sprintf( 'resolve() OK — source=%s, _degraded=%s', (string) $out['source'], (string) $out['_degraded'] )
						: 'resolve() returned invalid shape — missing required keys.',
				);
				if ( ! $shape_ok ) { $failures[] = 'runtime_shape'; }

				// to_passages() shape check
				$passages = $resolver->to_passages( $out );
				$pass_ok  = is_array( $passages );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $pass_ok,
					'msg'   => $pass_ok
						? sprintf( 'to_passages() OK — %d passage(s) (empty when natal missing).', count( $passages ) )
						: 'to_passages() did not return array.',
				);
				if ( ! $pass_ok ) { $failures[] = 'to_passages_shape'; }
			} catch ( \Throwable $e ) {
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => false,
					'msg'   => 'Exception: ' . $e->getMessage(),
				);
				$failures[] = 'runtime_exception';
			}
		}

		// 4. CAP filter subscriber registered (bizcoach-pro side)
		$has_cap = false;
		if ( function_exists( 'has_filter' ) ) {
			$has_cap = (bool) has_filter( 'bizcity_twin_context_artifacts' );
		}
		$steps[] = array(
			'layer' => 'runtime',
			'ok'    => $has_cap,
			'msg'   => $has_cap
				? 'CAP filter bizcity_twin_context_artifacts has at least one subscriber.'
				: 'CAP filter chưa có subscriber — astro mode sẽ không inject transit context.',
		);
		if ( ! $has_cap ) { $failures[] = 'cap_filter_missing'; }

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'warn',
				'steps'    => $steps,
				'summary'  => 'Astro Transit Resolver: ' . count( $failures ) . ' issue(s) — ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => in_array( 'class_missing', $failures, true )
					? 'Add `require_once BCPRO_DIR . "includes/astro/class-astro-transit-resolver.php";` in bizcoach-pro.php after class-astro-client.php.'
					: ( in_array( 'cap_filter_missing', $failures, true )
						? 'Wire `add_filter("bizcity_twin_context_artifacts", ...)` in bizcoach-pro.php (see PHASE-A C.0b block).'
						: 'Inspect step messages for details.' ),
			);
		}

		return array(
			'status'  => 'pass',
			'steps'   => $steps,
			'summary' => 'Astro Transit Resolver OK — class loaded, resolve() shape valid, CAP filter wired.',
		);
	}

	public function cleanup(): void {
		// Read-only probe; no state to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Transit_Resolver';
	return $list;
} );
