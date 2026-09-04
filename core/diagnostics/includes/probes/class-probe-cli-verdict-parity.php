<?php
/**
 * BizCity Diagnostics - CLI verdict parity probe.
 *
 * Compares the same lightweight runtime probe through the current WP-CLI
 * command, bin/twin, and bin/diagnostics-run.php.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_CLI_Verdict_Parity', false ) ) {
	return;
}

final class BizCity_Probe_CLI_Verdict_Parity implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - add runtime CLI verdict parity probe.
		return 'core.framework.cli_verdict_parity';
	}

	public function label(): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - expose the parity probe in diagnostics catalog.
		return 'Framework CLI verdict parity';
	}

	public function description(): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - document the three CLI boundary check.
		return 'Chay cung mot probe nhe qua wp bizcity, bin/twin va bin/diagnostics-run.php, sau do so sanh verdict va exit code.';
	}

	public function severity(): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - parity is a framework contract check.
		return 'critical';
	}

	public function order(): int {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - keep parity near framework probes.
		return 8;
	}

	public function icon(): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - use the existing CLI-oriented icon contract.
		return 'terminal';
	}

	public function estimate_ms(): int {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - bound the expected child CLI smoke.
		return 5000;
	}

	public function precondition() {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - require WP-CLI context for the current boundary.
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
			return 'Probe parity can only run inside WP-CLI.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - compare three CLI verdict boundaries without side effects.
		if ( getenv( 'BIZCITY_DIAGNOSTICS_INJECT_FAILURE' ) === '1' ) {
			$ctx->emit_step( $step = array(
				'label'  => 'Runtime - controlled failure injection',
				'status' => 'fail',
				'detail' => 'Intentional N4 failure injection is enabled for this process.',
			) );
			return array(
				'status'  => 'fail',
				'summary' => 'Controlled N4 failure injection triggered.',
				'error'   => 'controlled_failure_injected',
				'fix_hint'=> 'Unset BIZCITY_DIAGNOSTICS_INJECT_FAILURE and rerun the probe.',
				'steps'   => array( $step ),
			);
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' )
			: dirname( __DIR__, 4 );
		$runner = $root . '/bin/diagnostics-run.php';
		$twin   = $root . '/bin/twin';
		$cli    = $root . '/core/cli/class-bizcity-framework-cli.php';
		$disk_ok = is_readable( $runner ) && is_readable( $twin ) && is_readable( $cli );
		$ctx->emit_step( $step = array(
			'label'  => 'Disk - CLI dispatch artifacts',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'wp CLI dispatcher and both PHP runners are readable.' : 'One or more CLI artifacts are missing.',
		) );
		$steps = array( $step );
		if ( ! $disk_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'CLI parity artifacts are incomplete.',
				'error'   => 'cli_parity_artifact_missing',
				'fix_hint'=> 'Deploy the framework CLI dispatcher and both canonical diagnostics runners.',
				'steps'   => $steps,
			);
		}

		$loader_ok = class_exists( 'BizCity_Framework_CLI' ) && defined( 'WP_CLI' ) && WP_CLI;
		$ctx->emit_step( $step = array(
			'label'  => 'Loader - current wp bizcity boundary',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'BizCity_Framework_CLI is loaded in WP-CLI.' : 'Framework CLI class is not loaded in WP-CLI.',
		) );
		$steps[] = $step;
		if ( ! $loader_ok ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Current wp bizcity CLI boundary is unavailable.',
				'error'   => 'cli_dispatcher_not_loaded',
				'fix_hint'=> 'Load core/cli/class-bizcity-framework-cli.php through the guarded plugin loader.',
				'steps'   => $steps,
			);
		}

		$children = array(
			'bin/twin' => array( $twin, 'diagnostics', '--filter=core.module-registry', '--skip-network', '--skip-provision' ),
			'bin/diagnostics-run.php' => array( $runner, '--filter=core.module-registry', '--skip-network', '--skip-provision' ),
		);
		$child_results = array();
		foreach ( $children as $label => $parts ) {
			$child_results[ $label ] = self::run_child( $parts, $root );
		}

		$child_ok = true;
		$child_summary = array();
		foreach ( $child_results as $label => $result ) {
			$ok = $result['code'] === 0 && $result['verdict'] === 'pass';
			$child_ok = $child_ok && $ok;
			$child_summary[] = $label . '=' . ( $result['verdict'] !== '' ? $result['verdict'] : 'unparsed' ) . '/' . $result['code'];
			$ctx->emit_step( $step = array(
				'label'  => 'Runtime - ' . $label,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $result['detail'],
			) );
			$steps[] = $step;
		}

		$parity_ok = $child_ok;
		foreach ( $child_results as $result ) {
			$parity_ok = $parity_ok && $result['verdict'] === 'pass' && $result['code'] === 0;
		}
		$ctx->emit_step( $step = array(
			'label'  => 'Runtime - verdict and exit-code parity',
			'status' => $parity_ok ? 'pass' : 'fail',
			'detail' => 'wp bizcity=pass/0 | ' . implode( ' | ', $child_summary ),
		) );
		$steps[] = $step;

		return array(
			'status'  => $parity_ok ? 'pass' : 'fail',
			'summary' => $parity_ok ? 'All three CLI boundaries returned pass/0 for core.module-registry.' : 'CLI boundaries returned different verdicts or exit codes.',
			'error'   => $parity_ok ? '' : 'cli_verdict_parity_failed',
			'fix_hint'=> $parity_ok ? '' : 'Run the failing child command directly and align it with the canonical diagnostics runner.',
			'steps'   => $steps,
		);
	}

	private static function run_child( array $parts, string $cwd ): array {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - execute only read-only filtered diagnostics in child CLI processes.
		$command = implode( ' ', array_map( array( __CLASS__, 'quote_process_arg' ), array_merge( array( PHP_BINARY ), $parts ) ) );
		$spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process = proc_open( $command, $spec, $pipes, $cwd );
		if ( ! is_resource( $process ) ) {
			return array( 'code' => 2, 'verdict' => '', 'detail' => 'Unable to start child CLI.' );
		}
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = (int) proc_close( $process );
		$verdict = '';
		if ( preg_match( '/Result:\s*\d+\s+pass\s+[^\d]+\d+\s+fail\s+[^\d]+\d+\s+skip/i', $output ) ) {
			if ( preg_match( '/Result:\s*\d+\s+pass\s+[^\d]+(\d+)\s+fail\s+[^\d]+\d+\s+skip/i', $output, $matches ) && (int) $matches[1] > 0 ) {
				$verdict = 'fail';
			} else {
				$verdict = 'pass';
			}
		}
		$detail = preg_replace( '/\s+/', ' ', trim( $output ) );
		return array(
			'code'    => $code,
			'verdict' => $verdict,
			'detail' => substr( (string) $detail, -500 ),
		);
	}

	private static function quote_process_arg( string $value ): string {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - keep child CLI invocation portable on Windows and POSIX shells.
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			return preg_match( '/[\s"]/', $value ) ? '"' . str_replace( '"', '\\"', $value ) . '"' : $value;
		}
		return escapeshellarg( $value );
	}

	public function cleanup(): void {
		// [2026-08-28 Johnny Chu] PHASE-1.31 - parity probe creates no persistent artifacts.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list   = is_array( $list ) ? $list : array();
	$list[] = 'BizCity_Probe_CLI_Verdict_Parity';
	return $list;
} );
