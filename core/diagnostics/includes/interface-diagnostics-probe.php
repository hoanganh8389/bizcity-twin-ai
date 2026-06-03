<?php
/**
 * BizCity Diagnostics — Probe contract (Phase 0.41 L9.a T1).
 *
 * A "probe" is a small, idempotent end-to-end test that verifies one user-facing
 * capability of the TwinChat stack: seeding sources, running deep research,
 * searching the web, uploading a PDF, sampling vector+graph integrity, …
 *
 * Probes are discovered through the `bizcity_diagnostics_register_probes`
 * filter (each module appends an array of class names) and orchestrated by
 * `BizCity_Diagnostics_Smoke_Runner` (T2).
 *
 * Implementation rules
 * --------------------
 *   1. SAFE BY DEFAULT. A probe MUST NOT mutate any real user data. It may
 *      create test rows clearly tagged with prefix `__healthtest_` so the
 *      cleanup pass (T5) can wipe them deterministically.
 *   2. TIME BUDGET. `run()` should resolve in ≤ estimateMs(). Long probes
 *      MUST stream progress via the supplied `$ctx->emit_step()` so the FE
 *      modal renders live feedback.
 *   3. EXCEPTION = FAIL. Throwing from any method counts as a fail with the
 *      exception message used as `error`.
 *   4. NO SIDE-EFFECT ON FE. Probes are run by admin (`manage_options`)
 *      only; they must not enqueue real LLM credits beyond a token.
 *
 * Return contract
 * ---------------
 *   run()      → array{
 *       status:   'pass'|'fail'|'precheck-fail',
 *       summary?: string,
 *       error?:   string,
 *       fix_hint?:string,
 *       steps?:   array<int,array{label:string,status:string,detail?:string}>,
 *       artifacts?:array<int,array{kind:string,id:string|int,label?:string}>,
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.a)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {

	interface BizCity_Diagnostics_Probe {

		/**
		 * Stable machine id (snake.dot allowed). Used as REST param and
		 * the localStorage / user-meta key in the FE wizard.
		 * Example: 'kg.seeding'.
		 */
		public function id(): string;

		/** Display label, may contain Vietnamese. */
		public function label(): string;

		/** 1-2 sentence description shown in the wizard body. */
		public function description(): string;

		/** 'critical' | 'warning' | 'info' — drives badge color + sort. */
		public function severity(): string;

		/** Ordering for both wizard stepper and Diagnostics tab list. */
		public function order(): int;

		/** Lucide icon name for the FE (kept in sync with FE ICON_MAP). */
		public function icon(): string;

		/** Estimated runtime in milliseconds — used for "~3s" hint. */
		public function estimate_ms(): int;

		/**
		 * Precondition check — return WP_Error to short-circuit with
		 * status=precheck-fail; return true to proceed.
		 *
		 * @return true|WP_Error
		 */
		public function precondition();

		/**
		 * Execute the probe.
		 *
		 * The runner injects a context object with two helpers:
		 *   $ctx->emit_step( array $step )  — push one sub-step to the response.
		 *   $ctx->should_abort(): bool      — checked between phases.
		 *
		 * @param object $ctx
		 * @return array See class doc-block "Return contract".
		 */
		public function run( $ctx ): array;

		/**
		 * Best-effort cleanup of artifacts created during run(). The runner
		 * invokes this for every probe that completed (pass or fail). Must
		 * be idempotent — safe to call when nothing was created.
		 *
		 * @return void
		 */
		public function cleanup(): void;
	}
}
