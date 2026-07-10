<?php
/**
 * BizCity Diagnostics — twinbrain.astro_mode probe (PHASE-A C.3b · 2026-06-04).
 *
 * Validates the TwinBrain Astro Mode pipeline introduced in Sprint C.3b:
 *   - stream_astro_mode() method present in BizCity_TwinBrain_Runtime.
 *   - web_mode='astro' dispatch branch wired (confirmed via method_exists).
 *   - CAP filter bizcity_twin_context_artifacts has a subscriber (bizcoach-pro
 *     side, wired in Sprint C.0b) — without this, astro mode degrades silently.
 *   - BizCity_TwinBrain_Final_Composer available (called inside stream_astro_mode).
 *
 * 3-layer evidence (R-DDV):
 *   - Disk    : class-twinbrain-runtime.php exists at canonical path.
 *   - Loader  : BizCity_TwinBrain_Runtime class declared +
 *               method stream_astro_mode present (private ok for method_exists).
 *   - Runtime : CAP filter has subscriber + Final_Composer available.
 *
 * No LLM / gateway call — severity=info, estimate_ms=300. Safe to run
 * unattended. Fail-OPEN: exceptions → status=warn (not fatal).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-04 (PHASE-A C.3b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_TwinBrain_Astro_Mode', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Astro_Mode implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'twinbrain.astro_mode'; }
	public function label(): string       { return 'TwinBrain Astro Mode Pipeline (Sprint C.3b)'; }
	public function description(): string {
		return 'Kiểm tra stream_astro_mode() đã wire trong Runtime, CAP filter đã có subscriber, và Final_Composer available.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 43; }
	public function icon(): string        { return 'sparkles'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
			return 'BIZCITY_TWINBRAIN_DIR chưa định nghĩa — bizcity-twin-ai chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — DDV probe twinbrain.astro_mode
		$steps    = array();
		$failures = array();

		// ── 1. DISK ──────────────────────────────────────────────────
		$runtime_file = defined( 'BIZCITY_TWINBRAIN_DIR' )
			? BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php'
			: '';
		$disk_ok = ( $runtime_file !== '' && file_exists( $runtime_file ) );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'File exists: core/twinbrain/includes/class-twinbrain-runtime.php'
				: 'File MISSING: ' . $runtime_file,
		);
		if ( ! $disk_ok ) {
			$failures[] = 'runtime_file_missing';
		}

		// ── 2. LOADER — class + private method ───────────────────────
		$class_ok = class_exists( 'BizCity_TwinBrain_Runtime' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $class_ok,
			'msg'   => $class_ok
				? 'Class BizCity_TwinBrain_Runtime declared.'
				: 'Class NOT loaded — twinbrain bootstrap chưa require_once runtime.',
		);
		if ( ! $class_ok ) {
			$failures[] = 'class_missing';
		}

		$method_ok = $class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'stream_astro_mode' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $method_ok,
			'msg'   => $method_ok
				? 'Method stream_astro_mode() present (PHASE-A C.3b wired).'
				: 'Method stream_astro_mode() MISSING — C.3b patch chưa apply.',
		);
		if ( ! $method_ok ) {
			$failures[] = 'method_missing';
		}

		// [2026-06-04 Johnny Chu] PHASE-A C.3b — Web_Astro engine (dedicated
		// multi-step class parallel với web-deep/web-law). Runtime delegates
		// classify+CAP to it. Missing = runtime falls back to legacy inline path.
		$engine_ok = class_exists( 'BizCity_TwinBrain_Web_Astro' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $engine_ok,
			'msg'   => $engine_ok
				? 'Engine BizCity_TwinBrain_Web_Astro loaded (LLM-classify period + CAP dispatch).'
				: 'Engine BizCity_TwinBrain_Web_Astro NOT loaded — runtime sẽ fallback inline (mất astro_intent_detected event).',
		);
		if ( ! $engine_ok ) {
			$failures[] = 'web_astro_engine_missing';
		}

		// ── 3. RUNTIME — CAP filter subscriber + Final_Composer ──────
		$has_cap = false;
		if ( function_exists( 'has_filter' ) ) {
			$has_cap = (bool) has_filter( 'bizcity_twin_context_artifacts' );
		}
		$steps[] = array(
			'layer' => 'runtime',
			'ok'    => $has_cap,
			'msg'   => $has_cap
				? 'CAP filter bizcity_twin_context_artifacts có subscriber (bizcoach-pro C.0b).'
				: 'CAP filter chưa có subscriber — astro mode sẽ degrade (passages rỗng).',
		);
		if ( ! $has_cap ) {
			// Degraded but not fatal — runtime fails gracefully with _degraded key.
			$failures[] = 'cap_filter_missing';
		}

		$composer_ok = class_exists( 'BizCity_TwinBrain_Final_Composer' );
		$steps[] = array(
			'layer' => 'runtime',
			'ok'    => $composer_ok,
			'msg'   => $composer_ok
				? 'BizCity_TwinBrain_Final_Composer available (compose_stream sẵn sàng).'
				: 'BizCity_TwinBrain_Final_Composer NOT loaded — LLM compose step sẽ fatal.',
		);
		if ( ! $composer_ok ) {
			$failures[] = 'final_composer_missing';
		}

		// ── Summary ──────────────────────────────────────────────────
		if ( ! empty( $failures ) ) {
			// Distinguish fatal vs degraded-only failures.
			$fatal_keys = array( 'runtime_file_missing', 'class_missing', 'method_missing', 'final_composer_missing' );
			$has_fatal  = (bool) array_intersect( $fatal_keys, $failures );
			$status     = $has_fatal ? 'fail' : 'warn';

			if ( in_array( 'method_missing', $failures, true ) ) {
				$fix = 'stream_astro_mode() chưa có — xem class-twinbrain-runtime.php PHASE-A C.3b block.';
			} elseif ( in_array( 'cap_filter_missing', $failures, true ) ) {
				$fix = 'Wire add_filter("bizcity_twin_context_artifacts",...) trong bizcoach-pro.php (C.0b block).';
			} elseif ( in_array( 'final_composer_missing', $failures, true ) ) {
				$fix = 'Đảm bảo BizCity_TwinBrain_Final_Composer được load trước Runtime trong twinbrain/bootstrap.php.';
			} else {
				$fix = 'Inspect step messages for details.';
			}

			return array(
				'status'   => $status,
				'steps'    => $steps,
				'summary'  => 'TwinBrain Astro Mode: ' . count( $failures ) . ' issue(s) — ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => $fix,
			);
		}

		return array(
			'status'  => 'pass',
			'steps'   => $steps,
			'summary' => 'TwinBrain Astro Mode pipeline OK — dispatch wired, CAP filter active, Final_Composer ready.',
		);
	}

	public function cleanup(): void {
		// No test artifacts created — nothing to clean up.
	}
}
