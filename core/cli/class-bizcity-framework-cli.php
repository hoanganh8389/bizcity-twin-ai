<?php
/**
 * WP-CLI command family for the BizCity Twin framework.
 *
 * @package BizCity_Twin_AI
 * @since 1.3.7 PHASE-1.31
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Framework_CLI' ) ) {
	final class BizCity_Framework_CLI {

		const VERDICT_CONTRACT = 'diagnostics-verdict';
		const VERDICT_VERSION   = '1';

		public static function register(): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — register one framework command family.
			if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
				return;
			}

			\WP_CLI::add_command( 'bizcity status', [ __CLASS__, 'status' ] );
			\WP_CLI::add_command( 'bizcity diagnostics', [ __CLASS__, 'diagnostics' ] );
			\WP_CLI::add_command( 'bizcity health', [ __CLASS__, 'health' ] );
			\WP_CLI::add_command( 'bizcity probe', [ __CLASS__, 'probe' ] );
			\WP_CLI::add_command( 'bizcity sdk-check', [ __CLASS__, 'sdk_check' ] );
			\WP_CLI::add_command( 'bizcity tools', [ 'BizCity_Framework_CLI_Tools', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity cron', [ 'BizCity_Framework_CLI_Cron', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity tool-index', [ 'BizCity_Framework_CLI_Tool_Index', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity knowledge', [ 'BizCity_Framework_CLI_Knowledge', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity memory', [ 'BizCity_Framework_CLI_Memory', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity contracts', [ 'BizCity_Framework_CLI_Contracts', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity brain', [ 'BizCity_Framework_CLI_Brain', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity plugin', [ 'BizCity_Framework_CLI_Plugin', 'dispatch' ] );
			\WP_CLI::add_command( 'bizcity make:plugin', [ 'BizCity_Framework_CLI_Make', 'plugin' ] );
			\WP_CLI::add_command( 'bizcity make:tool', [ 'BizCity_Framework_CLI_Make', 'tool' ] );
			\WP_CLI::add_command( 'bizcity make:source', [ 'BizCity_Framework_CLI_Make', 'source' ] );
			\WP_CLI::add_command( 'bizcity make:event', [ 'BizCity_Framework_CLI_Make', 'event' ] );
			\WP_CLI::add_command( 'bizcity make:diagnostic', [ 'BizCity_Framework_CLI_Make', 'diagnostic' ] );
		}

		public static function status( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — expose read-only framework status.
			$origin = self::switch_blog( $assoc_args );
			try {
				$last = class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ? BizCity_Diagnostics_Smoke_Runner::get_last_results() : [];
				$last_smoke = get_option( 'bizcity_diag_last_smoke', array() );
				if ( is_array( $last_smoke ) && isset( $last_smoke['pass'], $last_smoke['fail'], $last_smoke['skipped'] ) ) {
					// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — report the latest run aggregate instead of summing capped/stale per-probe history.
					$counts = array(
						'pass' => (int) $last_smoke['pass'],
						'warn' => (int) ( $last_smoke['warn'] ?? 0 ),
						'fail' => (int) $last_smoke['fail'],
						'skip'  => (int) $last_smoke['skipped'],
					);
				} else {
					$counts = self::count_results( $last );
				}
				$modules = class_exists( 'BizCity_Module_Registry' ) ? BizCity_Module_Registry::instance()->inventory() : [];
				$payload = [
					'contract' => self::VERDICT_CONTRACT,
					'version' => self::VERDICT_VERSION,
					'blog_id' => (int) get_current_blog_id(),
					'env' => [ 'php' => PHP_VERSION, 'wordpress' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '', 'multisite' => is_multisite() ],
					'modules' => self::module_summary( $modules ),
					'last_diagnostics' => [ 'counts' => $counts, 'verdict' => self::verdict_from_counts( $counts ) ],
				];
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, 0 );
		}

		public static function diagnostics( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — run the canonical Smoke Runner probe set.
			self::require_class( 'BizCity_Diagnostics_Smoke_Runner', 'Diagnostics engine' );
			self::apply_diagnostics_options( $assoc_args );
			$origin = self::switch_blog( $assoc_args );
			try {
				$filter = isset( $assoc_args['filter'] ) ? (string) $assoc_args['filter'] : '';
				// [2026-08-29 Johnny Chu] PHASE-1.31-S2.3 — resume is defined for a complete batch cursor, not an ad-hoc filtered subset.
				if ( $filter !== '' && isset( $assoc_args['resume'] ) && (string) $assoc_args['resume'] !== '' ) {
					self::usage_error( 'Resume requires a batch run without --filter.' );
				}
				$options = ! empty( $assoc_args['skip-network'] ) ? [ 'skip_network' => true ] : [];
				$requested_batch = isset( $assoc_args['batch'] ) ? sanitize_key( (string) $assoc_args['batch'] ) : '';
				if ( $requested_batch !== '' && empty( BizCity_Diagnostics_Smoke_Runner::batch_ids( $requested_batch ) ) ) {
					self::usage_error( 'Unknown or empty diagnostics batch.' );
				}
				if ( isset( $assoc_args['batch'] ) && (string) $assoc_args['batch'] !== '' ) {
					$options['batch'] = (string) $assoc_args['batch'];
				}
				if ( isset( $assoc_args['resume'] ) && (string) $assoc_args['resume'] !== '' ) {
					$options['resume'] = (string) $assoc_args['resume'];
				}
				if ( isset( $assoc_args['run-id'] ) && (string) $assoc_args['run-id'] !== '' ) {
					$options['run_id'] = (string) $assoc_args['run-id'];
				}
				$aggregate_id = isset( $assoc_args['aggregate'] ) ? sanitize_key( (string) $assoc_args['aggregate'] ) : '';
				$run_meta = array();
				if ( $aggregate_id !== '' ) {
					// [2026-08-29 Johnny Chu] PHASE-1.31-S2.5 — aggregate persisted batch evidence without executing probes or installers.
					$payload = BizCity_Diagnostics_Smoke_Runner::aggregate_checkpoints( $aggregate_id );
				} else {
				if ( $filter === '' ) {
					$run = BizCity_Diagnostics_Smoke_Runner::run_all( $options );
					$results = isset( $run['results'] ) && is_array( $run['results'] ) ? $run['results'] : [];
					$run_meta = $run;
				} else {
				$results = [];
				foreach ( BizCity_Diagnostics_Smoke_Runner::catalog() as $id => $_probe ) {
					if ( $requested_batch !== '' && ! in_array( $id, BizCity_Diagnostics_Smoke_Runner::batch_ids( $requested_batch ), true ) ) {
						continue;
					}
					if ( ! self::glob_match( $filter, $id ) ) {
						continue;
					}
					$results[] = BizCity_Diagnostics_Smoke_Runner::run_probe( $id, $options );
				}
				}
				$payload = self::aggregate( $results, array(
					'command'  => 'diagnostics',
					'batch'    => isset( $run_meta['batch'] ) ? (string) $run_meta['batch'] : 'filter',
					'catalog_hash' => isset( $run_meta['catalog_hash'] ) ? (string) $run_meta['catalog_hash'] : BizCity_Diagnostics_Smoke_Runner::catalog_hash(),
					'batch_hash'   => isset( $run_meta['batch_hash'] ) ? (string) $run_meta['batch_hash'] : ( $requested_batch !== '' ? BizCity_Diagnostics_Smoke_Runner::batch_hash( $requested_batch ) : '' ),
					// [2026-08-29 Johnny Chu] PHASE-1.32-S1 — expose fix_hint coverage for actionable failures and warnings.
					'evidence_audit' => isset( $run_meta['evidence_audit'] ) ? $run_meta['evidence_audit'] : BizCity_Diagnostics_Smoke_Runner::audit_actionable_evidence( $results ),
					'coverage' => isset( $run_meta['coverage'] ) ? $run_meta['coverage'] : array(
						'catalog_total'   => count( BizCity_Diagnostics_Smoke_Runner::catalog() ),
						'selected_total'  => count( $results ),
						'executed'        => count( $results ),
						'deferred'        => 0,
						'complete'         => false,
					),
				) );
				if ( ! empty( $run_meta['error'] ) ) {
					$payload['verdict'] = 'fail';
					$payload['error'] = (string) $run_meta['error'];
				}
				}
				// [2026-08-29 Johnny Chu] PHASE-1.31-S2.5 — apply the release gate to aggregate and execution paths alike.
				if ( ! empty( $assoc_args['require-complete'] ) && empty( $payload['coverage']['complete'] ) ) {
					$payload['verdict'] = 'fail';
					$payload['error'] = 'coverage_incomplete';
				}
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
		}

		public static function health( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — run the bounded local health allowlist.
			self::require_class( 'BizCity_Diagnostics_Smoke_Runner', 'Diagnostics engine' );
			$origin = self::switch_blog( $assoc_args );
			try {
			$ids = [
				'core.module-registry',
				'core.loader.registration_integrity',
				'core.loader.ownership',
				'core.helper.error_ux',
				'core.wp_hook.callback_integrity',
			];
			$catalog = BizCity_Diagnostics_Smoke_Runner::catalog();
			$results = [];
			foreach ( $ids as $id ) {
				if ( ! isset( $catalog[ $id ] ) ) {
					$results[] = [ 'id' => $id, 'status' => 'skip', 'error' => 'Health probe is not registered.', 'duration_ms' => 0 ];
					continue;
				}
				$results[] = BizCity_Diagnostics_Smoke_Runner::run_probe( $id, [ 'skip_network' => true ] );
			}
			$payload = self::aggregate( $results, [ 'command' => 'health' ] );
			// [2026-08-29 Johnny Chu] PHASE-1.32-CI-HEALTH — intentional health precondition skips degrade to WARN with explicit evidence; only an executed FAIL blocks the health smoke.
			if ( (string) ( $payload['verdict'] ?? '' ) === 'skip' ) {
				$skipped = array();
				foreach ( (array) ( $payload['results'] ?? array() ) as $health_result ) {
					if ( is_array( $health_result ) && (string) ( $health_result['status'] ?? '' ) === 'skip' ) {
						$skipped[] = (string) ( $health_result['id'] ?? 'unknown' ) . ':' . (string) ( $health_result['error'] ?? $health_result['skip_reason'] ?? 'precondition_skip' );
					}
				}
				$payload['verdict'] = 'warn';
				$payload['health_degraded'] = true;
				$payload['health_skip_reasons'] = $skipped;
			}
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
		}

		public static function probe( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — list or execute one canonical probe.
			self::require_class( 'BizCity_Diagnostics_Smoke_Runner', 'Diagnostics engine' );
			self::apply_diagnostics_options( $assoc_args );
			$origin = self::switch_blog( $assoc_args );
			try {
				$catalog = BizCity_Diagnostics_Smoke_Runner::describe_catalog();
				$id = isset( $assoc_args['id'] ) ? (string) $assoc_args['id'] : '';
				if ( $id === '' || ! empty( $assoc_args['list'] ) || ! empty( $assoc_args['list-batches'] ) ) {
					// [2026-08-29 Johnny Chu] PHASE-1.31-S2.2 — expose deterministic batch catalog and hashes for orchestration.
					if ( self::wants_json( $assoc_args ) ) {
						self::emit( [
							'command'      => 'probe',
							'probes'       => $catalog,
							'count'        => count( $catalog ),
							'catalog_hash' => BizCity_Diagnostics_Smoke_Runner::catalog_hash(),
							'batches'      => BizCity_Diagnostics_Smoke_Runner::batches(),
						], $assoc_args, 0 );
					} else {
						if ( ! empty( $assoc_args['list-batches'] ) ) {
							$batch_rows = array();
							foreach ( BizCity_Diagnostics_Smoke_Runner::batches() as $batch_name => $batch_data ) {
								$batch_rows[] = array( 'batch' => $batch_name, 'count' => $batch_data['count'], 'batch_hash' => $batch_data['batch_hash'] );
							}
							\WP_CLI\Utils\format_items( 'table', $batch_rows, [ 'batch', 'count', 'batch_hash' ] );
						} else {
							\WP_CLI\Utils\format_items( 'table', $catalog, [ 'id', 'label', 'severity', 'order', 'estimate_ms' ] );
						}
					}
					return;
				}
				$result = BizCity_Diagnostics_Smoke_Runner::run_probe( $id, ! empty( $assoc_args['skip-network'] ) ? [ 'skip_network' => true ] : [] );
				$payload = self::aggregate( [ $result ], [ 'command' => 'probe', 'probe_id' => $id ] );
			} finally {
				self::restore_blog( $origin );
			}
			self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
		}

		public static function sdk_check( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — delegate SDK validation to existing validators.
			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
			$steps = [
				[ 'label' => 'Plugin contract registry', 'command' => [ 'node', 'bin/validate-plugin-contract-registry.mjs' ] ],
				[ 'label' => 'Framework contract audit', 'command' => [ 'node', 'bin/framework-contract-audit.mjs' ] ],
				[ 'label' => 'SDK release metadata', 'command' => [ 'node', 'bin/validate-sdk-release.mjs', '--check-build' ] ],
				[ 'label' => 'Reference plugin manifest', 'command' => [ PHP_BINARY, 'bin/bizcity-manifest-validate.php', '--plugin=examples/bizcity-reference-plugin' ] ],
			];
			$results = [];
			foreach ( $steps as $step ) {
				$run = self::run_external( $step['command'], $root );
				$results[] = [
					'id'       => sanitize_key( str_replace( ' ', '_', strtolower( $step['label'] ) ) ),
					'label'    => $step['label'],
					'status'   => $run['code'] === 0 ? 'pass' : 'fail',
					'detail'   => $run['output'],
					'duration_ms' => $run['duration_ms'],
				];
			}
			$payload = self::aggregate( $results, [ 'command' => 'sdk-check' ] );
			self::emit( $payload, $assoc_args, self::exit_code( $payload, $assoc_args ) );
		}

		public static function usage_error( string $message ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — preserve WP-CLI usage exit code 2.
			\WP_CLI::error( $message, false );
			\WP_CLI::halt( 2 );
		}

		private static function apply_diagnostics_options( array $assoc_args ): void {
			// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — make WP-CLI skip-network match bin/diagnostics-run.php so live gateway probes precheck-skip instead of calling the provider.
			if ( ! empty( $assoc_args['skip-network'] ) && ! defined( 'BIZCITY_DIAGNOSTICS_MOCK' ) ) {
				define( 'BIZCITY_DIAGNOSTICS_MOCK', true );
			}
		}

		public static function aggregate( array $results, array $extra = [] ): array {
			$normalized = [];
			foreach ( $results as $result ) {
				$status = strtolower( (string) ( $result['status'] ?? 'fail' ) );
				if ( $status === 'precheck-fail' || $status === 'skipped' ) {
					$status = 'skip';
				}
				if ( ! in_array( $status, [ 'pass', 'warn', 'fail', 'skip' ], true ) ) {
					$status = 'fail';
				}
				$result['status'] = $status;
				$normalized[] = $result;
			}
			$counts = self::count_results( $normalized );
			return array_merge(
				[ 'contract' => self::VERDICT_CONTRACT, 'version' => self::VERDICT_VERSION, 'results' => $normalized, 'counts' => $counts, 'verdict' => self::verdict_from_counts( $counts ) ],
				$extra
			);
		}

		private static function count_results( array $results ): array {
			$counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 ];
			foreach ( $results as $result ) {
				$status = strtolower( (string) ( $result['status'] ?? 'fail' ) );
				if ( $status === 'precheck-fail' || $status === 'skipped' ) {
					$status = 'skip';
				}
				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ]++;
				} else {
					$counts['fail']++;
				}
			}
			return $counts;
		}

		private static function verdict_from_counts( array $counts ): string {
			if ( ! empty( $counts['fail'] ) ) {
				return 'fail';
			}
			if ( ! empty( $counts['warn'] ) ) {
				return 'warn';
			}
			// [2026-08-28 Johnny Chu] PHASE-1.31 — never collapse an unavailable probe set into PASS.
			return ! empty( $counts['skip'] ) ? 'skip' : 'pass';
		}

		public static function exit_code( array $payload, array $assoc_args ): int {
			$verdict = (string) ( $payload['verdict'] ?? 'fail' );
			if ( $verdict === 'fail' || ( $verdict === 'warn' && ! empty( $assoc_args['strict'] ) ) ) {
				return 1;
			}
			return 0;
		}

		public static function emit( array $payload, array $assoc_args, int $exit_code ): void {
			if ( self::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				if ( isset( $payload['results'] ) ) {
					foreach ( $payload['results'] as $result ) {
						\WP_CLI::log( sprintf( '[%s] %s', strtoupper( (string) $result['status'] ), (string) ( $result['label'] ?? $result['id'] ?? '' ) ) );
					}
				}
				\WP_CLI::log( sprintf( 'Verdict: %s', strtoupper( (string) ( $payload['verdict'] ?? 'pass' ) ) ) );
			}
			if ( $exit_code !== 0 ) {
				\WP_CLI::halt( $exit_code );
			}
		}

		public static function switch_blog( array $assoc_args ): int {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — honor explicit multisite blog scope.
			$target = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
			$origin = (int) get_current_blog_id();
			if ( $target <= 0 || ! is_multisite() || $target === $origin ) {
				return 0;
			}
			if ( ! function_exists( 'get_site' ) || ! get_site( $target ) ) {
				self::usage_error( 'Requested blog does not exist.' );
			}
			switch_to_blog( $target );
			return $origin;
		}

		public static function restore_blog( int $origin ): void {
			if ( $origin > 0 && is_multisite() && get_current_blog_id() !== $origin ) {
				restore_current_blog();
			}
		}

		public static function wants_json( array $assoc_args ): bool {
			return ! empty( $assoc_args['json'] ) || ( isset( $assoc_args['format'] ) && strtolower( (string) $assoc_args['format'] ) === 'json' );
		}

		private static function require_class( string $class, string $label ): void {
			if ( ! class_exists( $class ) ) {
				self::usage_error( $label . ' is unavailable in this WordPress runtime.' );
			}
		}

		private static function module_summary( array $modules ): array {
			$booted = 0;
			foreach ( $modules as $module ) {
				if ( ! empty( $module['booted'] ) ) {
					$booted++;
				}
			}
			return [ 'total' => count( $modules ), 'booted' => $booted, 'failed' => count( $modules ) - $booted ];
		}

		private static function glob_match( string $pattern, string $value ): bool {
			$regex = '#^' . str_replace( [ '\\*', '\\?' ], [ '.*', '.' ], preg_quote( $pattern, '#' ) ) . '$#';
			return (bool) preg_match( $regex, $value );
		}

		private static function run_external( array $parts, string $cwd ): array {
			// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — use array command + bypass_shell on Windows; proc_open() string commands are still misparsed by cmd.exe even after quoting.
			$windows = DIRECTORY_SEPARATOR === '\\';
			$command = $windows
				? array_values( array_map( 'strval', $parts ) )
				: implode( ' ', array_map( array( __CLASS__, 'shell_arg' ), $parts ) );
			$spec = [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
			$started = microtime( true );
			$process_options = $windows ? array( 'bypass_shell' => true ) : array();
			$process = proc_open( $command, $spec, $pipes, $cwd, null, $process_options );
			if ( ! is_resource( $process ) ) {
				return [ 'code' => 2, 'output' => 'Unable to start validator.', 'duration_ms' => 0 ];
			}
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$code = proc_close( $process );
			return [ 'code' => (int) $code, 'output' => trim( (string) $output ), 'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ) ];
		}

		private static function shell_arg( $value ): string {
			// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — preserve paths with spaces across Windows cmd and POSIX shells.
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				return '"' . str_replace( '"', '\\"', (string) $value ) . '"';
			}
			return escapeshellarg( (string) $value );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Plugin' ) ) {
	final class BizCity_Framework_CLI_Plugin {

		public static function dispatch( array $args, array $assoc_args ): void {
			$verb = isset( $args[0] ) ? (string) $args[0] : '';
			$plugin = isset( $args[1] ) ? (string) $args[1] : '';
			if ( 'lint' !== $verb || '' === $plugin ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity plugin lint <slug|path> [--json] [--strict].' );
			}

			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
			$script = rtrim( $root, '\\/' ) . '/bin/bizcity-plugin-diagnostics.php';
			if ( ! is_file( $script ) || ! is_readable( $script ) ) {
				BizCity_Framework_CLI::usage_error( 'Plugin diagnostics engine is unavailable.' );
			}

			$parts = array( PHP_BINARY, $script, '--plugin=' . $plugin, '--json' );
			if ( ! empty( $assoc_args['strict'] ) ) {
				$parts[] = '--strict';
			}
			$windows = DIRECTORY_SEPARATOR === '\\';
			$command = $windows ? array_values( array_map( 'strval', $parts ) ) : implode( ' ', array_map( array( __CLASS__, 'shell_arg' ), $parts ) );
			$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $root, null, $windows ? array( 'bypass_shell' => true ) : array() );
			if ( ! is_resource( $process ) ) {
				BizCity_Framework_CLI::usage_error( 'Unable to start plugin diagnostics engine.' );
			}
			$output = trim( (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit_code = (int) proc_close( $process );
			$payload = json_decode( $output, true );
			if ( ! is_array( $payload ) ) {
				BizCity_Framework_CLI::usage_error( 'Plugin diagnostics returned invalid JSON.' );
			}
			$payload['command'] = 'plugin lint';
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				foreach ( (array) ( $payload['checks'] ?? array() ) as $check ) {
					\WP_CLI::line( sprintf( '[%s] %s', strtoupper( (string) ( $check['status'] ?? 'fail' ) ), (string) ( $check['label'] ?? $check['id'] ?? '' ) ) );
					if ( ! empty( $check['hint'] ) && 'pass' !== (string) ( $check['status'] ?? '' ) ) {
						\WP_CLI::line( '  hint: ' . (string) $check['hint'] );
					}
				}
				\WP_CLI::line( 'Verdict: ' . strtoupper( (string) ( $payload['verdict'] ?? 'fail' ) ) );
			}
			if ( 0 !== $exit_code ) {
				\WP_CLI::halt( $exit_code );
			}
		}

		private static function shell_arg( $value ): string {
			return DIRECTORY_SEPARATOR === '\\' ? '"' . str_replace( '"', '\\"', (string) $value ) . '"' : escapeshellarg( (string) $value );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Make' ) ) {
	final class BizCity_Framework_CLI_Make {

		public static function plugin( array $args, array $assoc_args ): void {
			$slug = isset( $args[0] ) ? (string) $args[0] : '';
			$name = isset( $assoc_args['name'] ) ? (string) $assoc_args['name'] : '';
			$out  = isset( $assoc_args['out'] ) ? (string) $assoc_args['out'] : ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' );
			if ( '' === $slug || '' === $name || '' === $out ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity make:plugin <slug> --name=<name> [--out=<directory>].' );
			}
			self::run( 'plugin', $slug, array( 'name' => $name, 'out' => $out ) );
		}

		public static function tool( array $args, array $assoc_args ): void {
			self::component( 'tool', $args, $assoc_args );
		}

		public static function source( array $args, array $assoc_args ): void {
			self::component( 'source', $args, $assoc_args );
		}

		public static function event( array $args, array $assoc_args ): void {
			self::component( 'event', $args, $assoc_args );
		}

		public static function diagnostic( array $args, array $assoc_args ): void {
			self::component( 'diagnostic', $args, $assoc_args );
		}

		private static function component( $type, array $args, array $assoc_args ): void {
			$slug   = isset( $args[0] ) ? (string) $args[0] : '';
			$plugin = isset( $assoc_args['plugin'] ) ? (string) $assoc_args['plugin'] : '';
			if ( '' === $slug || '' === $plugin ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity make:' . $type . ' <slug> --plugin=<plugin-directory>.' );
			}
			self::run( $type, $slug, array( 'plugin' => $plugin ) );
		}

		private static function run( $type, $slug, array $options ): void {
			$script = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'bin/bizcity-sdk-scaffold.php' : '';
			if ( '' === $script || ! is_file( $script ) || ! is_readable( $script ) ) {
				BizCity_Framework_CLI::usage_error( 'Scaffold engine is unavailable.' );
			}
			$parts = array( PHP_BINARY, $script, '--type=' . $type, '--slug=' . $slug );
			foreach ( $options as $key => $value ) {
				$parts[] = '--' . $key . '=' . (string) $value;
			}
			$windows = DIRECTORY_SEPARATOR === '\\';
			$command = $windows ? array_values( array_map( 'strval', $parts ) ) : implode( ' ', array_map( array( __CLASS__, 'shell_arg' ), $parts ) );
			$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : null, null, $windows ? array( 'bypass_shell' => true ) : array() );
			if ( ! is_resource( $process ) ) {
				BizCity_Framework_CLI::usage_error( 'Unable to start scaffold engine.' );
			}
			$output = trim( (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );
			if ( '' !== $output ) {
				\WP_CLI::line( $output );
			}
			if ( 0 !== (int) $exit_code ) {
				\WP_CLI::error( 'Scaffold command failed.' );
			}
		}

		private static function shell_arg( $value ): string {
			return DIRECTORY_SEPARATOR === '\\' ? '"' . str_replace( '"', '\\"', (string) $value ) . '"' : escapeshellarg( (string) $value );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Tools' ) ) {
	final class BizCity_Framework_CLI_Tools {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — expose the persisted tool index read path.
			$verb = isset( $args[0] ) ? (string) $args[0] : 'list';
			if ( $verb !== 'list' ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity tools list [--json] [--plugin=<slug>].' );
			}
			if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
				BizCity_Framework_CLI::usage_error( 'Tool index is unavailable in this WordPress runtime.' );
			}
			$origin = BizCity_Framework_CLI::switch_blog( $assoc_args );
			try {
				$tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
				$filter = isset( $assoc_args['plugin'] ) ? (string) $assoc_args['plugin'] : '';
				if ( $filter !== '' ) {
					$tools = array_values( array_filter( $tools, static function ( $tool ) use ( $filter ) {
						return (string) ( $tool['plugin_slug'] ?? $tool['provider'] ?? $tool['plugin'] ?? '' ) === $filter;
					} ) );
				}
				$payload = [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'tools list', 'blog_id' => (int) get_current_blog_id(), 'count' => count( $tools ), 'tools' => $tools ];
			} finally {
				BizCity_Framework_CLI::restore_blog( $origin );
			}
			if ( ! empty( $assoc_args['json'] ) || ( isset( $assoc_args['format'] ) && strtolower( (string) $assoc_args['format'] ) === 'json' ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $tools, [ 'tool_key', 'name', 'provider', 'active' ] );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Cron' ) ) {
	final class BizCity_Framework_CLI_Cron {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — expose the canonical cron registry.
			if ( ( $args[0] ?? 'check' ) !== 'check' || ! class_exists( 'BizCity_Cron_Manager' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity cron check [--json].' );
			}
			$origin = BizCity_Framework_CLI::switch_blog( $assoc_args );
			try {
				$jobs = BizCity_Cron_Manager::instance()->all();
				$payload = [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'cron check', 'blog_id' => (int) get_current_blog_id(), 'count' => count( $jobs ), 'jobs' => $jobs, 'verdict' => 'pass' ];
			foreach ( $jobs as $job ) {
				if ( ! empty( $job['enabled'] ) && empty( $job['next_run_at'] ) ) {
					$payload['verdict'] = 'warn';
				}
				if ( in_array( strtolower( (string) ( $job['last_status'] ?? '' ) ), [ 'fail', 'failed', 'error' ], true ) ) {
					$payload['verdict'] = 'fail';
				}
			}
			} finally {
				BizCity_Framework_CLI::restore_blog( $origin );
			}
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				\WP_CLI\Utils\format_items( 'table', $jobs, [ 'job_id', 'hook', 'enabled', 'next_run_at', 'last_run_at', 'last_status' ] );
				\WP_CLI::log( 'Verdict: ' . strtoupper( $payload['verdict'] ) );
			}
			if ( $payload['verdict'] === 'fail' || ( $payload['verdict'] === 'warn' && ! empty( $assoc_args['strict'] ) ) ) {
				\WP_CLI::halt( 1 );
			}
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Tool_Index' ) ) {
	final class BizCity_Framework_CLI_Tool_Index {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — keep tool index writes explicit and reversible.
			if ( ( $args[0] ?? 'sync' ) !== 'sync' || ! class_exists( 'BizCity_Intent_Tool_Index' ) || ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity tool-index sync [--yes] [--json].' );
			}
			$origin = BizCity_Framework_CLI::switch_blog( $assoc_args );
			try {
				$registry = BizCity_Intent_Provider_Registry::instance();
				$providers = $registry->get_all();
				if ( empty( $assoc_args['yes'] ) && empty( $assoc_args['force'] ) ) {
					\WP_CLI::warning( 'Dry-run only. Add --yes to persist the tool index.' );
					\WP_CLI::line( wp_json_encode( [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'tool-index sync', 'status' => 'skip', 'verdict' => 'skip', 'provider_count' => count( $providers ), 'reason' => 'confirmation_required' ], JSON_UNESCAPED_SLASHES ) );
					return;
				}
				BizCity_Intent_Tool_Index::instance()->sync_all( $providers );
			} finally {
				BizCity_Framework_CLI::restore_blog( $origin );
			}
			$payload = [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'tool-index sync', 'status' => 'pass', 'verdict' => 'pass', 'provider_count' => count( $providers ) ];
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			\WP_CLI::success( 'Tool index sync completed.' );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Knowledge' ) ) {
	final class BizCity_Framework_CLI_Knowledge {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — route knowledge repair to the KG skeleton owner.
			if ( ( $args[0] ?? 'repair' ) !== 'repair' || ! class_exists( 'BizCity_KG_Skeleton_Diagnostic' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity knowledge repair [--notebook=<id>] [--stuck] [--yes] [--json].' );
			}
			$blog_id = isset( $assoc_args['blog'] ) ? (int) $assoc_args['blog'] : 0;
			$notebook = isset( $assoc_args['notebook'] ) ? (int) $assoc_args['notebook'] : 0;
			$stuck = ! empty( $assoc_args['stuck'] );
			$diagnostic = BizCity_KG_Skeleton_Diagnostic::instance();
			if ( empty( $assoc_args['yes'] ) && empty( $assoc_args['force'] ) ) {
				$result = $diagnostic->audit_blog( $blog_id );
				$result['contract'] = BizCity_Framework_CLI::VERDICT_CONTRACT;
				$result['version'] = BizCity_Framework_CLI::VERDICT_VERSION;
				$result['command'] = 'knowledge repair';
				$result['status'] = 'skip';
				$result['verdict'] = 'skip';
				$result['reason'] = 'confirmation_required';
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			$result = $diagnostic->rebuild( $notebook, $stuck, $blog_id );
			$result['contract'] = BizCity_Framework_CLI::VERDICT_CONTRACT;
			$result['version'] = BizCity_Framework_CLI::VERDICT_VERSION;
			$result['command'] = 'knowledge repair';
			$result['status'] = ! empty( $result['ok'] ) ? 'pass' : 'fail';
			$result['verdict'] = $result['status'];
			if ( ! empty( $assoc_args['json'] ) ) {
				\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				if ( $result['status'] === 'fail' ) {
					\WP_CLI::halt( 1 );
				}
			} else {
				if ( $result['status'] === 'fail' ) {
					\WP_CLI::error( (string) ( $result['reason'] ?? 'Knowledge repair failed.' ), false );
					\WP_CLI::halt( 1 );
				}
				\WP_CLI::success( 'Knowledge repair completed.' );
			}
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Memory' ) ) {
	final class BizCity_Framework_CLI_Memory {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-27 Johnny Chu] PHASE-1.31 — reuse the two canonical memory parity probes.
			if ( ( $args[0] ?? 'audit' ) !== 'audit' || ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity memory audit [--json] [--strict].' );
			}
			$ids = [ 'core.memory.unified.dual-write-parity', 'core.memory.unified.recall-parity' ];
			// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — use the current encrypted filestore parity contract after SQL memory retirement; retain legacy recall parity only for installations without that contract.
			if ( class_exists( 'BizCity_File_Contract_Registry' )
				&& BizCity_File_Contract_Registry::has( 'core.knowledge.user_memory' ) ) {
				$ids[1] = 'core.memory.filestore_parity';
			}
			$results = [];
			$origin = BizCity_Framework_CLI::switch_blog( $assoc_args );
			try {
				foreach ( $ids as $id ) {
					$results[] = BizCity_Diagnostics_Smoke_Runner::run_probe( $id, [ 'skip_network' => true ] );
				}
			} finally {
				BizCity_Framework_CLI::restore_blog( $origin );
			}
			$payload = BizCity_Framework_CLI::aggregate( $results, [ 'command' => 'memory audit' ] );
			BizCity_Framework_CLI::emit( $payload, $assoc_args, BizCity_Framework_CLI::exit_code( $payload, $assoc_args ) );
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Contracts' ) ) {
	final class BizCity_Framework_CLI_Contracts {

		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-28 Johnny Chu] PHASE-1.32 — expose contract discovery without provider or DB side effects.
			$verb = isset( $args[0] ) ? (string) $args[0] : 'list';
			switch ( $verb ) {
				case 'list':
					self::list_contracts( $assoc_args );
					return;
				case 'show':
					self::show_contract( isset( $args[1] ) ? (string) $args[1] : '', $assoc_args );
					return;
				case 'check':
					self::check_contracts( $assoc_args );
					return;
				case 'audit':
					self::audit_contracts( $args, $assoc_args );
					return;
				case 'graph':
					self::graph_contracts( $assoc_args );
					return;
				default:
					BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity contracts list|show|check|audit|graph.' );
			}
		}

		private static function list_contracts( array $assoc_args ): void {
			$rows = self::collect();
			$scope = isset( $assoc_args['scope'] ) ? strtolower( (string) $assoc_args['scope'] ) : '';
			if ( $scope !== '' ) {
				$rows = array_values( array_filter( $rows, static function ( $row ) use ( $scope ) {
					return (string) ( $row['location_scope'] ?? '' ) === $scope;
				} ) );
			}
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( [
					'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT,
					'version'  => BizCity_Framework_CLI::VERDICT_VERSION,
					'command'  => 'contracts list',
					'count'    => count( $rows ),
					'contracts' => $rows,
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $rows, [ 'contract_id', 'scope', 'location_scope', 'owner', 'status', 'source' ] );
		}

		private static function show_contract( string $id, array $assoc_args ): void {
			if ( $id === '' ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity contracts show <contract-id> [--json].' );
			}
			$found = null;
			foreach ( self::collect() as $row ) {
				if ( (string) $row['contract_id'] === $id ) {
					$found = $row;
					break;
				}
			}
			if ( ! is_array( $found ) ) {
				BizCity_Framework_CLI::usage_error( 'Contract was not found in the active inventory.' );
			}
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			foreach ( $found as $key => $value ) {
				\WP_CLI::log( sprintf( '%s: %s', $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_SLASHES ) ) );
			}
		}

		private static function check_contracts( array $assoc_args ): void {
			$rows = self::collect();
			$id = isset( $assoc_args['id'] ) ? (string) $assoc_args['id'] : '';
			$scope = isset( $assoc_args['scope'] ) ? strtolower( (string) $assoc_args['scope'] ) : '';
			$checks = [];
			foreach ( $rows as $row ) {
				if ( $id !== '' && (string) $row['contract_id'] !== $id ) {
					continue;
				}
				if ( $scope !== '' && (string) ( $row['location_scope'] ?? '' ) !== $scope ) {
					continue;
				}
				$checks[] = self::check_row( $row );
			}
			if ( empty( $checks ) ) {
				BizCity_Framework_CLI::usage_error( 'No active contract rows matched the requested scope or id.' );
			}
			$payload = BizCity_Framework_CLI::aggregate( $checks, [ 'command' => 'contracts check' ] );
			BizCity_Framework_CLI::emit( $payload, $assoc_args, BizCity_Framework_CLI::exit_code( $payload, $assoc_args ) );
		}

		private static function audit_contracts( array $args, array $assoc_args ): void {
			// [2026-08-28 Johnny Chu] PHASE-1.32 — report untracked active packages as actionable contract debt.
			$rows = self::collect();
			$packages = [];
			foreach ( $rows as $row ) {
				if ( ( $row['scope'] ?? '' ) === 'package_adoption' ) {
					$packages[ (string) $row['owner'] ] = true;
				}
			}
			$missing = [];
			foreach ( self::active_package_dirs() as $path ) {
				$owner = 'plugins/' . basename( $path );
				if ( ! isset( $packages[ $owner ] ) ) {
					$missing[] = [ 'id' => 'untracked.' . sanitize_key( basename( $path ) ), 'status' => 'warn', 'label' => 'Untracked active package', 'detail' => $owner ];
				}
			}
			$payload = BizCity_Framework_CLI::aggregate( $missing ?: [ [ 'id' => 'contract_inventory', 'status' => 'pass', 'label' => 'Active packages tracked', 'detail' => 'No untracked package rows.' ] ], [ 'command' => 'contracts audit', 'inventory_source' => 'derived' ] );
			BizCity_Framework_CLI::emit( $payload, $assoc_args, BizCity_Framework_CLI::exit_code( $payload, $assoc_args ) );
		}

		private static function graph_contracts( array $assoc_args ): void {
			$id = isset( $assoc_args['id'] ) ? (string) $assoc_args['id'] : '';
			$edges = [
				[ 'from' => 'BizCity_Tool_Interface', 'to' => 'tool-io-envelope', 'kind' => 'implements' ],
				[ 'from' => 'BizCity_Tool_Interface', 'to' => 'permission-scopes', 'kind' => 'requires' ],
				[ 'from' => 'BizCity_Tool_Interface', 'to' => 'runtime-execution-policy', 'kind' => 'requires' ],
				[ 'from' => 'BizCity_Channel_Adapter', 'to' => 'channel-payload', 'kind' => 'produces' ],
				[ 'from' => 'BizCity_Channel_Adapter', 'to' => 'event-envelope', 'kind' => 'produces' ],
				[ 'from' => 'BizCity_Channel_Adapter', 'to' => 'error-envelope', 'kind' => 'failure' ],
				[ 'from' => 'BizCity_Automation_Block', 'to' => 'workflow-json', 'kind' => 'implements' ],
				[ 'from' => 'BizCity_Automation_Block', 'to' => 'mutation-contract', 'kind' => 'side_effect' ],
				[ 'from' => 'BizCity_TwinBrain_Vertical_Bridge_Registry', 'to' => 'MPR Layer 2/5', 'kind' => 'dispatches' ],
				[ 'from' => 'core/channel-gateway', 'to' => 'core/twinbrain', 'kind' => 'normalized_intake' ],
				[ 'from' => 'core/twinbrain', 'to' => 'plugins/bizcity-twin-crm', 'kind' => 'admin_surface' ],
			];
			if ( $id !== '' ) {
				$edges = array_values( array_filter( $edges, static function ( $edge ) use ( $id ) {
					return $edge['from'] === $id || $edge['to'] === $id;
				} ) );
			}
			$payload = [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'contracts graph', 'source' => 'derived', 'edges' => $edges ];
			if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) {
				\WP_CLI::line( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $edges, [ 'from', 'to', 'kind' ] );
		}

		private static function collect(): array {
			$rows = [];
			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
			$catalog_path = $root . 'core/twin-core/contracts/schema/public/v1/contract-catalog.json';
			$catalog = is_readable( $catalog_path ) ? json_decode( (string) file_get_contents( $catalog_path ), true ) : [];
			foreach ( (array) ( $catalog['contracts'] ?? [] ) as $contract ) {
				$rows[] = [
					'contract_id' => (string) ( $contract['id'] ?? '' ),
					'version' => (string) ( $contract['version'] ?? '' ),
					'owner' => 'core/twin-core',
					'scope' => 'public_schema',
					'location_scope' => 'core',
					'canonical' => true,
					'source' => 'canonical_catalog',
					'artifact' => 'core/twin-core/contracts/schema/public/v1/' . (string) ( $contract['schema'] ?? '' ),
					'fixtures' => (array) ( $contract['fixtures'] ?? [] ),
					'validators' => [ 'contract-fixture', 'node core/twin-core/contracts/tests/run-contract-tests.mjs' ],
					'evidence' => [ 'disk' => 'pending', 'loader' => 'pending', 'runtime' => 'pending' ],
					'status' => 'partial',
				];
			}
			$interface_files = [
				'core/twin-core/contracts/framework-contracts.php',
				'core/twin-core/contracts/content-contracts.php',
				'core/twin-core/includes/interface-twin-tool.php',
				'core/channel-gateway/includes/interface-channel-adapter.php',
				'core/channel-gateway/includes/interface-channel-magic-link-capable.php',
				'core/automation/includes/blocks/interface-block.php',
				'core/diagnostics/includes/interface-diagnostics-probe.php',
				'core/knowledge/kg-hub/includes/adapters/interface-source-adapter.php',
				'core/runtime/interface-twin-session.php',
				'core/scheduler/includes/interface-scheduler-event-adapter.php',
			];
			foreach ( $interface_files as $relative ) {
				$path = $root . $relative;
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$source = (string) file_get_contents( $path );
				if ( preg_match_all( '/interface\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches ) ) {
					foreach ( $matches[1] as $name ) {
						$rows[] = [ 'contract_id' => 'php.' . strtolower( $name ), 'version' => 'unversioned', 'owner' => dirname( $relative ), 'scope' => strpos( $relative, 'twin-core/contracts/' ) !== false ? 'public_typed' : 'domain_runtime', 'location_scope' => 'core', 'canonical' => strpos( $relative, 'twin-core/contracts/' ) !== false, 'source' => 'derived_interface_inventory', 'artifact' => $relative, 'interfaces' => [ $name ], 'validators' => [ 'php-token/interface-shape', 'loader/runtime probe' ], 'evidence' => [ 'disk' => 'pending', 'loader' => 'pending', 'runtime' => 'pending' ], 'status' => 'partial' ];
					}
				}
			}
			foreach ( self::active_package_dirs() as $path ) {
				$slug = basename( $path );
				$manifest = $path . '/manifest.json';
				$data = is_readable( $manifest ) ? json_decode( (string) file_get_contents( $manifest ), true ) : [];
				$rows[] = [ 'contract_id' => 'package.' . sanitize_key( (string) ( $data['id'] ?? $slug ) ), 'version' => (string) ( $data['version'] ?? 'unknown' ), 'owner' => 'plugins/' . $slug, 'scope' => 'package_adoption', 'location_scope' => 'plugins', 'canonical' => false, 'source' => 'derived_package_inventory', 'artifact' => 'plugins/' . $slug . '/manifest.json', 'bootstrap' => self::find_bootstrap( $path, $slug ), 'readme' => is_readable( $path . '/README.md' ), 'capabilities' => (array) ( $data['capabilities'] ?? [] ), 'validators' => [ 'manifest schema', 'plugin diagnostics', 'runtime probe' ], 'evidence' => [ 'disk' => 'pending', 'loader' => 'pending', 'runtime' => 'pending' ], 'status' => 'partial' ];
			}
			return $rows;
		}

		private static function check_row( array $row ): array {
			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
			$issues = [];
			$artifact = $root . (string) ( $row['artifact'] ?? '' );
			if ( ! is_readable( $artifact ) ) {
				$issues[] = 'artifact_missing';
			}
			if ( ( $row['scope'] ?? '' ) === 'public_schema' && empty( $row['fixtures']['valid'] ) ) {
				$issues[] = 'valid_fixture_missing';
			}
			if ( ( $row['scope'] ?? '' ) === 'package_adoption' ) {
				if ( ! is_readable( $artifact ) ) $issues[] = 'manifest_missing';
				if ( empty( $row['bootstrap'] ) ) $issues[] = 'bootstrap_missing';
				if ( empty( $row['readme'] ) ) $issues[] = 'readme_missing';
			}
			$status = empty( $issues ) ? 'pass' : 'fail';
			return [ 'id' => (string) $row['contract_id'], 'label' => (string) $row['owner'], 'status' => $status, 'evidence' => empty( $issues ) ? 'Static artifact and declared fields are present; Loader/Runtime evidence remains separate.' : implode( ', ', $issues ), 'fix_hint' => empty( $issues ) ? 'Run the owning Runtime probe before promoting package status.' : 'Add or repair the missing canonical contract artifact.', 'file' => (string) ( $row['artifact'] ?? '' ), 'severity' => $status === 'fail' ? 'critical' : 'info' ];
		}

		private static function active_package_dirs(): array {
			$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
			$base = $root . 'plugins';
			$dirs = [];
			if ( ! is_dir( $base ) ) return $dirs;
			foreach ( scandir( $base ) as $entry ) {
				if ( $entry === '.' || $entry === '..' || $entry === '_archived' || ! is_dir( $base . '/' . $entry ) ) continue;
				$dirs[] = $base . '/' . $entry;
			}
			return $dirs;
		}

		private static function find_bootstrap( string $path, string $slug ): string {
			$candidates = [ $path . '/' . $slug . '.php', $path . '/bootstrap.php', $path . '/bizcity-' . $slug . '.php' ];
			foreach ( $candidates as $candidate ) {
				if ( is_readable( $candidate ) ) return str_replace( '\\', '/', substr( $candidate, strlen( defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/' ) ) );
			}
			return '';
		}
	}
}

if ( ! class_exists( 'BizCity_Framework_CLI_Brain' ) ) {
	final class BizCity_Framework_CLI_Brain {
		public static function dispatch( array $args, array $assoc_args ): void {
			// [2026-08-28 Johnny Chu] PHASE-1.32 — expose the canonical Vertical Brain/MPR bridge contract.
			$verb = isset( $args[0] ) ? (string) $args[0] : 'verticals';
			if ( ! in_array( $verb, [ 'verticals', 'check' ], true ) || ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
				BizCity_Framework_CLI::usage_error( 'Usage: wp bizcity brain verticals|check [--id=<vertical>] [--json] [--strict].' );
			}
			$verticals = BizCity_TwinBrain_Vertical_Bridge_Registry::all();
			$id = isset( $assoc_args['id'] ) ? sanitize_key( (string) $assoc_args['id'] ) : '';
			if ( $id !== '' ) {
				$verticals = array_values( array_filter( $verticals, static function ( $row ) use ( $id ) { return sanitize_key( (string) ( $row['id'] ?? '' ) ) === $id; } ) );
			}
			if ( $verb === 'verticals' ) {
				if ( BizCity_Framework_CLI::wants_json( $assoc_args ) ) { \WP_CLI::line( wp_json_encode( [ 'contract' => BizCity_Framework_CLI::VERDICT_CONTRACT, 'version' => BizCity_Framework_CLI::VERDICT_VERSION, 'command' => 'brain verticals', 'source' => 'BizCity_TwinBrain_Vertical_Bridge_Registry', 'count' => count( $verticals ), 'verticals' => $verticals ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); return; }
				\WP_CLI\Utils\format_items( 'table', $verticals, [ 'id', 'label', 'owner_plugin', 'output_shape', 'min_plan', 'guest_allowed', 'contract_id' ] );
				return;
			}
			$checks = [];
			foreach ( $verticals as $vertical ) {
				$missing = [];
				foreach ( [ 'id', 'label', 'role', 'owner_plugin', 'output_shape', 'guest_allowed', 'min_plan', 'contract_id', 'mpr_layers', 'automation_mode', 'channel_entry', 'admin_surface' ] as $field ) { if ( ! array_key_exists( $field, $vertical ) || $vertical[ $field ] === '' ) $missing[] = $field; }
				$checks[] = [ 'id' => 'vertical.' . sanitize_key( (string) ( $vertical['id'] ?? 'unknown' ) ), 'label' => (string) ( $vertical['label'] ?? 'Vertical'), 'status' => empty( $missing ) ? 'pass' : 'fail', 'evidence' => empty( $missing ) ? 'Bridge metadata, MPR layers, automation/channel/admin spine are declared.' : 'Missing: ' . implode( ', ', $missing ), 'fix_hint' => empty( $missing ) ? 'Run the vertical runtime probe before promotion.' : 'Register the vertical with the complete bridge contract fields.', 'file' => 'core/twinbrain/includes/class-twinbrain-vertical-bridge-registry.php', 'severity' => empty( $missing ) ? 'info' : 'critical' ];
			}
			$payload = BizCity_Framework_CLI::aggregate( $checks, [ 'command' => 'brain check', 'source' => 'BizCity_TwinBrain_Vertical_Bridge_Registry' ] );
			BizCity_Framework_CLI::emit( $payload, $assoc_args, BizCity_Framework_CLI::exit_code( $payload, $assoc_args ) );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	BizCity_Framework_CLI::register();
}
