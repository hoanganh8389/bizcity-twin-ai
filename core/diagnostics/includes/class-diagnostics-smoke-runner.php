<?php
/**
 * BizCity Diagnostics — Smoke Runner orchestrator (Phase 0.41 L9.a T2).
 *
 * Discovers probes via the `bizcity_diagnostics_register_probes` filter,
 * provides the catalog to the FE wizard, runs individual probes inside a
 * lightweight context that captures sub-steps, and routes cleanup.
 *
 * Probes register through the filter:
 *   add_filter( 'bizcity_diagnostics_register_probes', function( array $list ) {
 *       $list[] = 'BizCity_Probe_KG_Seeding';      // FQCN
 *       $list[] = new My_Custom_Probe();           // or instance
 *       return $list;
 *   } );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.a)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/interface-diagnostics-probe.php';

final class BizCity_Diagnostics_Smoke_Runner {

	/** @var array<string,BizCity_Diagnostics_Probe>|null memoized catalog */
	private static $catalog = null;

	/**
	 * Per-blog hard cap for one full run() — guards against runaway probes.
	 * Individual probes still have their own estimate_ms() budget.
	 */
	private const RUN_BUDGET_SECONDS = 30;

	/**
	 * Build (or return memoized) catalog of probes registered via filter.
	 *
	 * @return array<string,BizCity_Diagnostics_Probe> keyed by id().
	 */
	public static function catalog(): array {
		if ( self::$catalog !== null ) {
			return self::$catalog;
		}

		$raw = apply_filters( 'bizcity_diagnostics_register_probes', [] );
		$out = [];

		if ( is_array( $raw ) ) {
			foreach ( $raw as $entry ) {
				$probe = null;
				if ( is_object( $entry ) && $entry instanceof BizCity_Diagnostics_Probe ) {
					$probe = $entry;
				} elseif ( is_string( $entry ) && class_exists( $entry ) ) {
					$obj = new $entry();
					if ( $obj instanceof BizCity_Diagnostics_Probe ) {
						$probe = $obj;
					}
				}
				if ( $probe ) {
					$out[ $probe->id() ] = $probe;
				}
			}
		}

		// Sort by order() ascending, then id() for stability.
		uasort( $out, function ( $a, $b ) {
			$cmp = $a->order() <=> $b->order();
			return $cmp !== 0 ? $cmp : strcmp( $a->id(), $b->id() );
		} );

		return self::$catalog = $out;
	}

	/** Force re-discovery (after dynamic registration in tests). */
	public static function flush(): void {
		self::$catalog = null;
	}

	/**
	 * Phase 0.41 L9.f (2026-05-22) — Per-probe last-result history.
	 *
	 * Option key (per-blog via blog-specific option storage). Map shape:
	 *   [ '<probe_id>' => {status, summary, error, fix_hint, duration_ms, ts} ]
	 * Cap: max 64 entries (LRU by ts). Persisted by `run_probe()` after every
	 * single invocation (UI Run button, Run-all, REST, FE Wizard) so the
	 * admin page + FE modal can show "last passed 5m ago" without re-running.
	 */
	const LAST_RESULTS_OPT = 'bizcity_diag_probe_last_results';
	const LAST_RESULTS_CAP = 64;

	/**
	 * Return the persisted last-result map (keyed by probe id).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_last_results(): array {
		$raw = get_option( self::LAST_RESULTS_OPT, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * Persist one probe envelope into the last-result map. Strips heavy
	 * `steps`/`artifacts` to keep the option row tiny.
	 *
	 * @param string               $id
	 * @param array<string,mixed>  $res run_probe() return
	 */
	private static function record_last_result( string $id, array $res ): void {
		$map = self::get_last_results();
		$map[ $id ] = [
			'status'      => (string) ( $res['status'] ?? 'fail' ),
			'summary'     => isset( $res['summary'] )  ? (string) $res['summary']  : '',
			'error'       => isset( $res['error'] )    ? (string) $res['error']    : '',
			'fix_hint'    => isset( $res['fix_hint'] ) ? (string) $res['fix_hint'] : '',
			'duration_ms' => (int) ( $res['duration_ms'] ?? 0 ),
			'steps_count' => isset( $res['steps'] ) && is_array( $res['steps'] ) ? count( $res['steps'] ) : 0,
			'ts'          => time(),
		];
		// LRU cap by ts.
		if ( count( $map ) > self::LAST_RESULTS_CAP ) {
			uasort( $map, function ( $a, $b ) { return (int) ( $b['ts'] ?? 0 ) <=> (int) ( $a['ts'] ?? 0 ); } );
			$map = array_slice( $map, 0, self::LAST_RESULTS_CAP, true );
		}
		update_option( self::LAST_RESULTS_OPT, $map, false );
	}

	/** Clear all persisted probe results (admin-tool / dev reset). */
	public static function clear_last_results(): void {
		delete_option( self::LAST_RESULTS_OPT );
	}

	/**
	 * Public-shape catalog for REST — no closures, no objects.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function describe_catalog(): array {
		$list = [];
		foreach ( self::catalog() as $p ) {
			$list[] = [
				'id'          => $p->id(),
				'label'       => $p->label(),
				'description' => $p->description(),
				'severity'    => $p->severity(),
				'order'       => $p->order(),
				'icon'        => $p->icon(),
				'estimate_ms' => $p->estimate_ms(),
			];
		}
		return $list;
	}

	/**
	 * Execute one probe by id and return its result envelope.
	 *
	 * @param string $id
	 * @return array{status:string,id:string,duration_ms:int,summary?:string,error?:string,fix_hint?:string,steps?:array,artifacts?:array}
	 */
	public static function run_probe( string $id ): array {
		$catalog = self::catalog();
		if ( ! isset( $catalog[ $id ] ) ) {
			return [
				'id'          => $id,
				'status'      => 'fail',
				'error'       => 'Unknown probe id.',
				'duration_ms' => 0,
			];
		}
		$probe = $catalog[ $id ];

		// Precondition gate.
		$pc = $probe->precondition();
		if ( is_wp_error( $pc ) ) {
			return [
				'id'          => $id,
				'status'      => 'precheck-fail',
				'error'       => $pc->get_error_message(),
				'duration_ms' => 0,
			];
		}

		$ctx   = new BizCity_Diagnostics_Probe_Context();
		$start = microtime( true );
		$res   = [];
		try {
			$res = $probe->run( $ctx );
			if ( ! is_array( $res ) ) {
				$res = [ 'status' => 'fail', 'error' => 'Probe returned non-array.' ];
			} elseif ( ! isset( $res['status'] ) && ! empty( $res ) && isset( $res[0] ) && is_array( $res[0] ) && array_key_exists( 'status', $res[0] ) ) {
				// Bare-steps return shape: probe returned a numeric-indexed list of step entries
				// (each having a 'status' key). Auto-wrap to proper envelope: overall 'pass' iff
				// no step status is FAIL (case-insensitive). Preserves legacy probes that never
				// learned the envelope contract.
				$failed_steps = [];
				foreach ( $res as $step ) {
					if ( ! is_array( $step ) ) { continue; }
					if ( strtolower( (string) ( $step['status'] ?? '' ) ) === 'fail' ) {
						$failed_steps[] = (string) ( $step['label'] ?? '(unlabeled step)' )
							. ( isset( $step['detail'] ) ? ' — ' . (string) $step['detail'] : '' );
					}
				}
				$res = [
					'status'  => empty( $failed_steps ) ? 'pass' : 'fail',
					'steps'   => $res,
					'summary' => empty( $failed_steps )
						? 'All ' . count( $res ) . ' steps passed.'
						: count( $failed_steps ) . ' step(s) failed: ' . implode( ' | ', $failed_steps ),
				];
			}
		} catch ( \Throwable $e ) {
			$res = [
				'status' => 'fail',
				'error'  => $e->getMessage(),
			];
			if ( class_exists( 'BizCity_Error_Reporter' ) ) {
				BizCity_Error_Reporter::record( [
					'code'    => 'probe_exception',
					'module'  => 'diagnostics/smoke',
					'title'   => sprintf( 'Probe %s threw exception', $id ),
					'detail'  => $e->getMessage(),
					'context' => [ 'probe_id' => $id, 'file' => $e->getFile(), 'line' => $e->getLine() ],
					'source'  => 'be',
				] );
			}
		}

		// Always try to cleanup, even on fail.
		try {
			$probe->cleanup();
		} catch ( \Throwable $e ) {
			// Cleanup failure is logged but does not flip status.
			if ( class_exists( 'BizCity_Error_Reporter' ) ) {
				BizCity_Error_Reporter::record( [
					'code'    => 'probe_cleanup_failed',
					'module'  => 'diagnostics/smoke',
					'title'   => sprintf( 'Probe %s cleanup failed', $id ),
					'detail'  => $e->getMessage(),
					'context' => [ 'probe_id' => $id ],
					'source'  => 'be',
				] );
			}
		}

		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		// Merge runner-emitted steps if probe didn't provide its own.
		if ( empty( $res['steps'] ) && $ctx->steps ) {
			$res['steps'] = $ctx->steps;
		}

		$res['id']          = $id;
		$res['duration_ms'] = $duration;
		if ( ! isset( $res['status'] ) ) {
			$res['status'] = 'fail';
		}

		// Phase 0.41 L9.f — persist per-probe last result (lightweight).
		self::record_last_result( $id, $res );

		return $res;
	}

	/**
	 * Run every probe sequentially. Stops if cumulative time would exceed
	 * RUN_BUDGET_SECONDS. Returns an aggregate envelope used by the
	 * "Run all" button in the wizard.
	 *
	 * @return array{started_at:string,duration_ms:int,results:array}
	 */
	public static function run_all(): array {
		$started = microtime( true );
		$results = [];
		foreach ( self::catalog() as $id => $_p ) {
			if ( ( microtime( true ) - $started ) > self::RUN_BUDGET_SECONDS ) {
				$results[] = [
					'id'          => $id,
					'status'      => 'skipped',
					'error'       => 'Run-all budget exceeded.',
					'duration_ms' => 0,
				];
				continue;
			}
			$results[] = self::run_probe( $id );
		}

		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		// Phase 0.41 L9.e — persist last-smoke aggregate for dashboard widget
		// + external monitoring consumers.
		$pass = 0; $fail = 0; $skip = 0;
		foreach ( $results as $r ) {
			$s = $r['status'] ?? '';
			if ( $s === 'pass' ) { $pass++; }
			elseif ( $s === 'skipped' ) { $skip++; }
			else { $fail++; }
		}
		update_option( 'bizcity_diag_last_smoke', [
			'ts'          => time(),
			'pass'        => $pass,
			'fail'        => $fail,
			'skipped'     => $skip,
			'duration_ms' => $duration_ms,
		], false );

		return [
			'started_at'  => gmdate( 'c', (int) $started ),
			'duration_ms' => $duration_ms,
			'results'     => $results,
		];
	}

	/**
	 * Phase 0.41 L9.b+ — Auto-Fix-All orchestrator.
	 *
	 * Idempotent + additive-only remediation sweep:
	 *   1. Run every registered installer (Site_Provisioner::run_all(force=true)).
	 *   2. Flush caches.
	 *   3. JSON-declared Auto-Create cho mọi row missing/drift.
	 *   4. **Per-row class fallback** — với mỗi row còn missing mà registry có
	 *      `class` field, thử gọi installer method chuẩn (install / maybe_install /
	 *      maybe_create_tables / ensure_table / ensure_tables_exist / install_tables /
	 *      create_tables / maybe_install_inbox). Hữu ích khi class tồn tại lúc
	 *      inspection nhưng chưa đăng ký qua `bizcity_register_installers` filter
	 *      (timing / module load order).
	 *   5. Phân loại "unfixable" cho rows còn lại (orphan: không JSON, không class).
	 *
	 * @return array{
	 *   installer_results:array, auto_create_results:array, class_fallback_results:array,
	 *   unfixable:array, before:int, after:int, took_ms:int
	 * }
	 */
	public static function auto_fix_all(): array {
		$started = microtime( true );

		// Count missing before.
		$before_missing = 0;
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( empty( $r['exists'] ) ) { $before_missing++; }
			}
		}

		// 1. Installers.
		$installer_results = class_exists( 'BizCity_Site_Provisioner' )
			? BizCity_Site_Provisioner::run_all( true )
			: [];

		// 2. Flush caches.
		if ( class_exists( 'BizCity_Diagnostics_Installer_Resolver' ) ) {
			BizCity_Diagnostics_Installer_Resolver::flush();
		}

		// 3. JSON-declared auto-create for still-missing / drift rows.
		$auto_create_results = [];
		$json_tables = class_exists( 'BizCity_Diagnostics_Changelog_Loader' )
			? BizCity_Diagnostics_Changelog_Loader::tables()
			: [];
		if ( $json_tables && class_exists( 'BizCity_Diagnostics_Auto_Create' ) && class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				$suffix = $r['name'] ?? '';
				if ( ! isset( $json_tables[ $suffix ] ) ) { continue; }

				$needs = empty( $r['exists'] );
				if ( ! $needs && class_exists( 'BizCity_Diagnostics_Column_Inspector' ) ) {
					$diff = BizCity_Diagnostics_Column_Inspector::diff( $r );
					$needs = ( $diff['status'] ?? '' ) === 'drift';
				}
				if ( ! $needs ) { continue; }

				$auto_create_results[ $suffix ] = BizCity_Diagnostics_Auto_Create::run( $suffix );
			}
		}

		// 4. Per-row class fallback for rows still missing.
		$class_fallback_results = [];
		$unfixable              = [];
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			$candidate_methods = [
				'install', 'maybe_install', 'maybe_create_tables', 'create_tables',
				'ensure_table', 'ensure_tables_exist', 'install_tables', 'maybe_install_inbox',
			];
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( ! empty( $r['exists'] ) ) { continue; }

				$suffix    = (string) ( $r['name'] ?? '' );
				$class     = (string) ( $r['class'] ?? '' );
				$has_json  = $suffix !== '' && isset( $json_tables[ $suffix ] );
				$ran       = false;

				if ( $class !== '' && class_exists( $class ) ) {
					// Clear the version option so `maybe_install()` doesn't bail early
					// when the option value already matches SCHEMA_VERSION but the table
					// was dropped / never actually created (option set, table missing).
					if ( defined( "{$class}::OPTION_VERSION" ) ) {
						delete_option( constant( "{$class}::OPTION_VERSION" ) );
					}

					foreach ( $candidate_methods as $m ) {
						if ( ! method_exists( $class, $m ) ) { continue; }
						try {
							call_user_func( [ $class, $m ] );
							$class_fallback_results[ $suffix ] = [
								'class'  => $class,
								'method' => $m,
								'status' => 'invoked',
							];
							$ran = true;
							break; // first matching method wins; idempotent
						} catch ( \Throwable $e ) {
							$class_fallback_results[ $suffix ] = [
								'class'  => $class,
								'method' => $m,
								'status' => 'error',
								'error'  => $e->getMessage(),
							];
							$ran = true;
							break;
						}
					}
				}

				if ( ! $ran && ! $has_json ) {
					$unfixable[] = [
						'physical' => (string) ( $r['physical'] ?? $suffix ),
						'owner'    => (string) ( $r['owner'] ?? '' ),
						'class'    => $class,
						'hint'     => $class !== ''
							? 'class exists but no recognised installer method'
							: 'orphan registry row: add CREATE TABLE / JSON changelog OR remove from registry',
					];
				}
			}
		}

		// Count after (re-flush schema cache by re-inspect).
		$after_missing = 0;
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			// force fresh schema snapshot
			if ( property_exists( 'BizCity_Diagnostics_Table_Inspector', 'schema_cache' ) ) {
				$ref = new \ReflectionClass( 'BizCity_Diagnostics_Table_Inspector' );
				if ( $ref->hasProperty( 'schema_cache' ) ) {
					$p = $ref->getProperty( 'schema_cache' );
					$p->setAccessible( true );
					$p->setValue( null, null );
				}
			}
			foreach ( BizCity_Diagnostics_Table_Inspector::inspect_all() as $r ) {
				if ( empty( $r['exists'] ) ) { $after_missing++; }
			}
		}

		$took_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		// Audit.
		if ( class_exists( 'BizCity_Error_Reporter' ) ) {
			BizCity_Error_Reporter::record( [
				'code'    => 'auto_fix_all_run',
				'module'  => 'core/diagnostics',
				'title'   => 'Auto-Fix-All sweep',
				'detail'  => sprintf( 'Missing tables: %d → %d in %dms', $before_missing, $after_missing, $took_ms ),
				'context' => [
					'before_missing'         => $before_missing,
					'after_missing'          => $after_missing,
					'auto_create_keys'       => array_keys( $auto_create_results ),
					'class_fallback_keys'    => array_keys( $class_fallback_results ),
					'unfixable_count'        => count( $unfixable ),
					'installer_count'        => is_array( $installer_results ) ? count( $installer_results ) : 0,
				],
			] );
		}

		return [
			'installer_results'      => $installer_results,
			'auto_create_results'    => $auto_create_results,
			'class_fallback_results' => $class_fallback_results,
			'unfixable'              => $unfixable,
			'before'                 => $before_missing,
			'after'                  => $after_missing,
			'took_ms'                => $took_ms,
		];
	}
}

/**
 * Lightweight context passed to every probe's run(). Probes call
 * $ctx->emit_step() to push live progress that the REST layer can later
 * stream (Phase 0.41 L9.b will upgrade to SSE; today we just return the
 * accumulated array at the end).
 */
final class BizCity_Diagnostics_Probe_Context {

	/** @var array<int,array{label:string,status:string,detail?:string}> */
	public $steps = [];

	/** @var bool */
	private $abort = false;

	public function emit_step( array $step ): void {
		$this->steps[] = [
			'label'  => isset( $step['label'] ) ? (string) $step['label'] : '',
			'status' => isset( $step['status'] ) ? (string) $step['status'] : 'pass',
			'detail' => isset( $step['detail'] ) ? (string) $step['detail'] : null,
		];
	}

	public function should_abort(): bool {
		return $this->abort;
	}

	public function abort(): void {
		$this->abort = true;
	}
}
