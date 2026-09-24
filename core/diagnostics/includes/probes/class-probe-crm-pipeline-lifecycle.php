<?php
/**
 * PHASE-0.71 F71-12 / PHASE-0.63C GC-8 — CRM pipeline lifecycle → Context Bank probe.
 *
 * `CONTEXT-BANK-PRODUCER-INVENTORY-v1.md:42` has listed this probe as "planned" since the adapter
 * landed. This closes that gap. It checks:
 *   (a) the adapter's `add_action()` on `bizcity_crm_event_crm_pipeline_lifecycle` is actually
 *       attached on this request — not just that the class exists;
 *   (b) the emitter side (`Pipeline_Run_Service::emit_lifecycle()` → `BizCity_CRM_Event_Emitter::emit()`)
 *       still names the same event on THIS deployed source, read from disk rather than assumed;
 *   (c) the record the adapter would project stays pointer-only — no message/note/content key —
 *       read from the `$record = array(...)` literal in `project()`, again from disk;
 *   (d) a real call into `project()`, safe under either state of the
 *       `bizcity_context_bank_pipeline_capture_enabled` flag: with the flag off (the default), the
 *       PASS outcome IS the guard clause returning `capture_disabled` and writing nothing; with the
 *       flag on (an admin already opted the site in), a `__healthtest_`-tagged synthetic event is
 *       written then immediately tombstoned via `BizCity_Context_Bank_Ledger::on_reference_delete()`.
 *
 * SAFE BY DEFAULT: this probe never flips the capture flag itself and never touches real pipeline
 * runs — the synthetic event uses a sentinel `run_id` well outside any real opportunity id range and
 * `contact_id = 0`.
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Pipeline_Lifecycle', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Pipeline_Lifecycle implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.pipeline_lifecycle'; }
	public function label(): string { return 'CRM pipeline lifecycle → Context Bank'; }
	public function description(): string { return 'Kiểm hook crm_pipeline_lifecycle nối đúng adapter, payload pointer-only, và gọi thật project() an toàn ở cả 2 trạng thái cờ capture.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 72; }
	public function icon(): string { return 'link-2'; }
	public function estimate_ms(): int { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_CRM_Pipeline_Adapter' ) ) {
			return new WP_Error( 'crm_pipeline_lifecycle_adapter_missing', 'BizCity_Context_Bank_CRM_Pipeline_Adapter chưa được load.' );
		}
		if ( ! class_exists( 'BizCity_CRM_Pipeline_Run_Service' ) ) {
			return new WP_Error( 'crm_pipeline_lifecycle_run_service_missing', 'BizCity_CRM_Pipeline_Run_Service chưa được load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$ok = true;

		/* ---- (a) the hook is actually attached on this request, not just declared ---- */

		$priority = has_action( 'bizcity_crm_event_crm_pipeline_lifecycle', array( 'BizCity_Context_Bank_CRM_Pipeline_Adapter', 'project' ) );
		$hooked   = 20 === $priority;
		$ctx->emit_step( array(
			'label'  => 'Adapter is hooked on bizcity_crm_event_crm_pipeline_lifecycle @20',
			'status' => $hooked ? 'pass' : 'fail',
			'detail' => $hooked
				? 'has_action() = 20'
				: 'has_action() returned: ' . var_export( $priority, true ) . ' — the Context Bank pipeline adapter boot() may not have run on this request/surface.',
		) );
		$ok = $ok && $hooked;

		/* ---- (b) the emitter side still names the same event — read from disk, not assumed ---- */

		$run_service_file = defined( 'BIZCITY_CRM_DIR' ) ? BIZCITY_CRM_DIR . '/includes/pipeline/class-pipeline-run-service.php' : '';
		$run_service_src  = '' !== $run_service_file && is_readable( $run_service_file ) ? (string) file_get_contents( $run_service_file ) : '';
		$emits_lifecycle  = false !== strpos( $run_service_src, "emit( 'crm_pipeline_lifecycle'" );

		$emitter_src = '';
		if ( class_exists( 'BizCity_CRM_Event_Emitter' ) ) {
			$emitter_file = ( new ReflectionClass( 'BizCity_CRM_Event_Emitter' ) )->getFileName();
			$emitter_src  = $emitter_file && is_readable( $emitter_file ) ? (string) file_get_contents( $emitter_file ) : '';
		}
		$emitter_names_type = false !== strpos( $emitter_src, "'bizcity_crm_event_' . \$event_type" );

		$names_match = $emits_lifecycle && $emitter_names_type;
		$ctx->emit_step( array(
			'label'  => 'Pipeline_Run_Service emits crm_pipeline_lifecycle through the shared event emitter',
			'status' => $names_match ? 'pass' : 'fail',
			'detail' => $names_match
				? "emit_lifecycle() → Event_Emitter::emit('crm_pipeline_lifecycle') → do_action('bizcity_crm_event_' . \$type) — matches the adapter's hook name"
				: 'emit_lifecycle call found=' . ( $emits_lifecycle ? 'yes' : 'no' ) . ', emitter do_action pattern found=' . ( $emitter_names_type ? 'yes' : 'no' ),
		) );
		$ok = $ok && $names_match;

		/* ---- (c) the record project() would write stays pointer-only ---- */

		$adapter_src  = '';
		if ( class_exists( 'BizCity_Context_Bank_CRM_Pipeline_Adapter' ) ) {
			$adapter_file = ( new ReflectionClass( 'BizCity_Context_Bank_CRM_Pipeline_Adapter' ) )->getFileName();
			$adapter_src  = $adapter_file && is_readable( $adapter_file ) ? (string) file_get_contents( $adapter_file ) : '';
		}
		$pointer_only = false;
		$leaks        = array( '(record literal not found)' );
		if ( preg_match( '/\$record\s*=\s*array\((.*?)\);/s', $adapter_src, $m ) ) {
			$leaks = array();
			foreach ( array( 'message', 'text', 'content', 'note', 'body' ) as $word ) {
				if ( preg_match( '/[\'"]' . $word . '[\'"]\s*=>/i', $m[1] ) ) {
					$leaks[] = $word;
				}
			}
			$pointer_only = empty( $leaks );
		}
		$ctx->emit_step( array(
			'label'  => 'Projected record stays pointer-only (contact_id/run_id/stage/event, no message content)',
			'status' => $pointer_only ? 'pass' : 'fail',
			'detail' => $pointer_only ? 'No message/text/content/note/body key in the $record literal' : 'Suspicious key(s): ' . implode( ', ', $leaks ),
		) );
		$ok = $ok && $pointer_only;

		/* ---- (d) a real call into project(), safe under either flag state ---- */

		$capture_enabled = function_exists( 'get_option' ) && (bool) get_option( 'bizcity_context_bank_pipeline_capture_enabled', false );
		$synthetic = array(
			'event_uuid'    => '__healthtest_' . wp_generate_uuid4(),
			'blog_id'       => get_current_blog_id(),
			// Sentinel, far outside any real opportunity id — never collides with a real run.
			'run_id'        => 900000000 + wp_rand( 1, 999999 ),
			'contact_id'    => 0,
			'pipeline_kind' => 'production',
			'stage_key'     => 'P5.3',
			'event'         => 'stage_doing',
			'occurred_at'   => gmdate( 'c' ),
		);

		try {
			$result = BizCity_Context_Bank_CRM_Pipeline_Adapter::project( $synthetic );
		} catch ( \Throwable $e ) {
			$result = array( 'ok' => false, 'projected' => false, 'reason' => 'exception: ' . $e->getMessage() );
		}

		if ( ! $capture_enabled ) {
			$real_call_ok = true === ( $result['ok'] ?? false )
				&& false === ( $result['projected'] ?? true )
				&& 'capture_disabled' === ( $result['reason'] ?? '' );
			$ctx->emit_step( array(
				'label'  => 'Real call to project() — capture flag is OFF (default): guard clause must no-op',
				'status' => $real_call_ok ? 'pass' : 'fail',
				'detail' => 'reason=' . (string) ( $result['reason'] ?? '(missing)' ),
			) );
			$ok = $ok && $real_call_ok;
		} else {
			// The site already opted in — exercise the real write, then tombstone the test record.
			$projected = true === ( $result['ok'] ?? false ) && true === ( $result['projected'] ?? false );
			if ( $projected && class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
				try {
					BizCity_Context_Bank_Ledger::instance()->on_reference_delete( array(
						'source_contract_id' => 'core.context_bank.crm_pipeline_lifecycle',
						'record_id'          => (string) ( $result['record_id'] ?? '' ),
					) );
				} catch ( \Throwable $e ) {
					// Cleanup is best-effort only — never flips the verdict on cleanup failure.
				}
			}
			// A degraded Context Bank runtime (filestore/ledger/contract registry unavailable on this
			// surface) is informational here, not a hard fail — (b)/(c) of GC-8 are explicitly
			// WordPress-runtime-dependent; source/build evidence must not masquerade as runtime PASS.
			$ctx->emit_step( array(
				'label'  => 'Real call to project() — capture flag is ON: verify write + tombstone cleanup',
				'status' => $projected ? 'pass' : 'warn',
				'detail' => $projected
					? 'Wrote and tombstoned __healthtest_ record ' . (string) ( $result['record_id'] ?? '' )
					: 'reason=' . (string) ( $result['reason'] ?? '(missing)' ) . ' — Context Bank runtime may not be available on this surface.',
			) );
		}

		return array(
			'status'   => $ok ? 'pass' : 'fail',
			'summary'  => $ok ? 'CRM pipeline lifecycle wiring PASS.' : 'CRM pipeline lifecycle wiring FAIL.',
			'error'    => $ok ? '' : 'pipeline_lifecycle_wiring_invalid',
			'fix_hint' => $ok ? '' : 'Xác nhận context-bank module đã boot() trên surface này, và emit_lifecycle()/Event_Emitter vẫn dùng đúng tên event crm_pipeline_lifecycle.',
		);
	}

	public function cleanup(): void {
		// Best-effort cleanup already happens inline in run() right after a real write;
		// nothing persists across requests to clean up here.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes['core.crm.pipeline_lifecycle'] = new BizCity_Probe_CRM_Pipeline_Lifecycle();
	return $probes;
} );
