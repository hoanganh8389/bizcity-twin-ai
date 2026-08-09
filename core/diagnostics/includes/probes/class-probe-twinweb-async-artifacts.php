<?php
/**
 * BizCity Diagnostics - Twin GPT durable async artifact jobs probe.
 *
 * R-DDV evidence:
 * - Disk: AT-7 job store, installer/changelog, REST status route and reason buckets.
 * - Loader: job store methods and owner-scoped status route are loaded.
 * - Runtime: synthetic failed and ready transitions without provider calls.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-08-01
 */

// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — DDV probe for durable artifact job state transitions.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_Async_Artifacts', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Async_Artifacts implements BizCity_Diagnostics_Probe {

	/** @var string[] */
	private $synthetic_jobs = array();

	public function id(): string { return 'modules.twin_gpt.async_artifacts'; }
	public function label(): string { return 'Twin GPT Durable Async Artifacts'; }
	public function description(): string {
		return 'Verifies AT-7 durable artifact job storage, owner-scoped status route and deterministic ready/failed transitions without provider calls.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 92; }
	public function icon(): string { return 'Timer'; }
	public function estimate_ms(): int { return 220; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) ) {
			return new WP_Error( 'no_artifact_jobs', 'BizCity_TwinWeb_Artifact_Jobs is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — read-only provider boundary; only synthetic local state is written and cleaned.
		$steps = array();
		$pass  = true;
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$jobs_file = $this->class_file_or_fallback( 'BizCity_TwinWeb_Artifact_Jobs', $root . 'modules/twinweb/includes/class-twinweb-artifact-jobs.php' );
		$installer_file = $root . 'modules/twinweb/includes/class-twinweb-installer.php';
		$rest_file = $this->class_file_or_fallback( 'BizCity_TwinWeb_REST', $root . 'modules/twinweb/includes/class-twinweb-rest.php' );
		$changelog_file = $root . 'core/diagnostics/changelog/modules.twinweb.json';

		$jobs_src = is_readable( $jobs_file ) ? (string) file_get_contents( $jobs_file ) : '';
		$installer_src = is_readable( $installer_file ) ? (string) file_get_contents( $installer_file ) : '';
		$rest_src = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$changelog_src = is_readable( $changelog_file ) ? (string) file_get_contents( $changelog_file ) : '';

		$disk_markers = array(
			'job_store_class'       => strpos( $jobs_src, 'class BizCity_TwinWeb_Artifact_Jobs' ) !== false,
			'poller'                => strpos( $jobs_src, 'poll_due_jobs' ) !== false && strpos( $jobs_src, 'run_cron' ) !== false,
			'retry_state'           => strpos( $jobs_src, 'public static function retry' ) !== false && strpos( $jobs_src, 'attempt_count' ) !== false,
			'owner_identity'        => strpos( $jobs_src, 'row_belongs_to_identity' ) !== false && strpos( $jobs_src, 'owner_user_id' ) !== false,
			'reason_buckets'        => strpos( $jobs_src, 'owner_missing' ) !== false && strpos( $jobs_src, 'error_payload' ) !== false,
			'installer'             => strpos( $installer_src, 'ensure_artifact_jobs_table' ) !== false && strpos( $installer_src, 'information_schema.TABLES' ) !== false,
			'changelog'             => strpos( $changelog_src, 'bizcity_twinweb_artifact_jobs' ) !== false && strpos( $changelog_src, '"1.1.0"' ) !== false,
			'rest_handler'          => strpos( $rest_src, 'get_artifact_job_status' ) !== false && strpos( $rest_src, 'permission_denied' ) !== false,
			'retry_route'           => strpos( $rest_src, '/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)/retry' ) !== false && strpos( $rest_src, 'retry_artifact_job' ) !== false,
		);
		$this->emit( $ctx, $steps, $pass, 'Disk - AT-7 durable job contract markers', $this->all_true( $disk_markers ), $this->marker_detail( $disk_markers, 'Durable job store, poller, ownership, reason buckets, installer, changelog and REST handler markers found.' ) );

		$method_markers = array(
			'table_ready'    => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'table_ready' ),
			'create'         => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'create' ),
			'get_by_job_id'  => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'get_by_job_id' ),
			'update'         => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'update' ),
			'poll_due_jobs'  => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'poll_due_jobs' ),
			'normalize_row'  => method_exists( 'BizCity_TwinWeb_Artifact_Jobs', 'normalize_row' ),
			'rest_handler'   => method_exists( 'BizCity_TwinWeb_REST', 'get_artifact_job_status' ),
			'retry_handler'  => method_exists( 'BizCity_TwinWeb_REST', 'retry_artifact_job' ),
		);
		$this->emit( $ctx, $steps, $pass, 'Loader - durable job store and REST handler loaded', $this->all_true( $method_markers ), $this->marker_detail( $method_markers, 'AT-7 job methods and REST handler are loaded.' ) );

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)', 'GET' );
		$this->emit( $ctx, $steps, $pass, 'Loader - durable artifact status route registered', $route_ok, $route_ok ? 'GET /artifacts/jobs/{job_id} is registered.' : 'Missing GET /artifacts/jobs/{job_id} route.' );
		$retry_route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/artifacts/jobs/(?P<job_id>[A-Za-z0-9_-]+)/retry', 'POST' );
		$this->emit( $ctx, $steps, $pass, 'Loader - durable artifact retry route registered', $retry_route_ok, $retry_route_ok ? 'POST /artifacts/jobs/{job_id}/retry is registered.' : 'Missing POST /artifacts/jobs/{job_id}/retry route.' );

		$table_ok = BizCity_TwinWeb_Artifact_Jobs::table_ready();
		$this->emit( $ctx, $steps, $pass, 'Runtime - durable artifact jobs table available', $table_ok, $table_ok ? 'AT-7 job table is available for synthetic state checks.' : 'Artifact jobs table is not available; run the registered TwinWeb installer/provisioner first.' );
		if ( ! $table_ok ) {
			return array(
				'status'   => $pass ? 'pass' : 'fail',
				'summary'  => $pass ? 'Twin GPT durable artifact contract is present; runtime table check skipped.' : 'Twin GPT durable artifact contract failed one or more checks.',
				'error'    => $pass ? '' : 'twinweb_async_artifacts_contract_failed',
				'fix_hint' => $pass ? 'Provision the registered AT-7 table, then rerun this probe for runtime transition evidence.' : 'Check AT-7 job store, installer, changelog and REST route.',
				'steps'    => $steps,
			);
		}

		$failed_job_id = 'twaj_diag_failed_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$failed = BizCity_TwinWeb_Artifact_Jobs::create( array(
			'job_id'        => $failed_job_id,
			'owner_user_id' => 0,
			'tool_slug'    => 'create_xlsx',
			'artifact_type'=> 'xlsx',
			'status'       => 'queued',
			'status_url'   => rest_url( 'bizcity-twinweb/v1/artifacts/status' ),
			'next_poll_at' => $this->past_datetime(),
			'input'        => array( 'probe' => 'async_artifacts', 'mode' => 'failed_owner_missing' ),
		) );
		$this->synthetic_jobs[] = $failed_job_id;
		$failed_created_ok = is_array( $failed ) && (string) ( $failed['job_id'] ?? '' ) === $failed_job_id;
		$this->emit( $ctx, $steps, $pass, 'Runtime - synthetic job is owner-scoped and persisted', $failed_created_ok, $failed_created_ok ? 'Synthetic queued job persisted with a unique owner-scoped job id.' : 'Could not persist synthetic artifact job.' );

		$failed_poll_ok = false;
		$failed_detail = 'Synthetic failed job was not created.';
		if ( $failed_created_ok ) {
			$summary = BizCity_TwinWeb_Artifact_Jobs::poll_due_jobs( 50 );
			$failed_row = BizCity_TwinWeb_Artifact_Jobs::get_by_job_id( $failed_job_id );
			$failed_poll_ok = is_array( $failed_row )
				&& (string) ( $failed_row['status'] ?? '' ) === BizCity_TwinWeb_Artifact_Jobs::STATUS_FAILED
				&& (string) ( $failed_row['reason_bucket'] ?? '' ) === 'owner_missing'
				&& ! empty( $summary['failed'] );
			$failed_detail = sprintf( 'status=%s; reason=%s; failed=%d', (string) ( $failed_row['status'] ?? 'missing' ), (string) ( $failed_row['reason_bucket'] ?? 'missing' ), (int) ( $summary['failed'] ?? 0 ) );
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - ownerless synthetic job reaches deterministic failed bucket', $failed_poll_ok, $failed_detail );

		$ready_job_id = 'twaj_diag_ready_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$runtime_uid = $this->resolve_runtime_user_id();
		$ready = BizCity_TwinWeb_Artifact_Jobs::create( array(
			'job_id'        => $ready_job_id,
			'owner_user_id' => $runtime_uid,
			'tool_slug'    => 'create_xlsx',
			'artifact_type'=> 'xlsx',
			'status'       => 'queued',
			'status_url'   => rest_url( 'bizcity-twinweb/v1/artifacts/status' ),
			'next_poll_at' => $this->past_datetime(),
			'input'        => array( 'probe' => 'async_artifacts', 'mode' => 'ready_reducer' ),
		) );
		$this->synthetic_jobs[] = $ready_job_id;
		$ready_ok = false;
		$ready_detail = 'Synthetic ready job could not be created; no provider call was attempted.';
		if ( is_array( $ready ) && $runtime_uid > 0 ) {
			try {
				$method = new ReflectionMethod( 'BizCity_TwinWeb_Artifact_Jobs', 'apply_owner_status_payload' );
				$method->setAccessible( true );
				$method->invoke( null, (object) array( 'job_id' => $ready_job_id, 'artifact_type' => 'xlsx', 'progress' => 35 ), array(
					'success'       => true,
					'status'        => 'done',
					'preview_url'   => 'https://example.test/diag.xlsx',
					'download_url'  => 'https://example.test/diag.xlsx',
					'result'        => array( 'probe' => 'async_artifacts' ),
				) );
				$ready_row = BizCity_TwinWeb_Artifact_Jobs::get_by_job_id( $ready_job_id );
				$ready_ok = is_array( $ready_row )
					&& (string) ( $ready_row['status'] ?? '' ) === BizCity_TwinWeb_Artifact_Jobs::STATUS_READY
					&& (int) ( $ready_row['progress'] ?? 0 ) === 100
					&& (string) ( $ready_row['download_url'] ?? '' ) === 'https://example.test/diag.xlsx';
				$ready_detail = sprintf( 'status=%s; progress=%d; download=%s', (string) ( $ready_row['status'] ?? 'missing' ), (int) ( $ready_row['progress'] ?? 0 ), ! empty( $ready_row['download_url'] ) ? 'yes' : 'no' );
			} catch ( \Throwable $e ) {
				$ready_detail = 'Exception: ' . $e->getMessage();
			}
		} elseif ( $runtime_uid <= 0 ) {
			$ready_detail = 'No runtime user available for owner-scoped ready transition.';
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - synthetic owner payload reaches ready bucket', $ready_ok, $ready_detail );

		$retry_job_id = 'twaj_diag_retry_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$retry_ok = false;
		$retry_detail = 'No runtime user available for owner-scoped retry transition.';
		if ( $runtime_uid > 0 ) {
			$retry = BizCity_TwinWeb_Artifact_Jobs::create( array(
				'job_id'        => $retry_job_id,
				'owner_user_id' => $runtime_uid,
				'tool_slug'    => 'create_pptx',
				'artifact_type'=> 'pptx',
				'status'       => 'failed',
				'reason_bucket' => 'generation_failed',
				'owner_job_id'  => 'owner_diag_retry',
				'status_url'   => rest_url( 'bzdoc/v1/get/999999' ),
				'error_payload' => array(
					'code' => 'generation_failed',
					'message' => 'Synthetic failure.',
					'hint' => 'Retry synthetic job.',
					'help_code' => 'automation_run_failed',
				),
			) );
			$this->synthetic_jobs[] = $retry_job_id;
			if ( is_array( $retry ) ) {
				$requeued = BizCity_TwinWeb_Artifact_Jobs::retry( $retry_job_id, array( 'user_id' => $runtime_uid ) );
				$retry_ok = is_array( $requeued )
					&& (string) ( $requeued['status'] ?? '' ) === BizCity_TwinWeb_Artifact_Jobs::STATUS_QUEUED
					&& (int) ( $requeued['attempt_count'] ?? 0 ) === 1
					&& (string) ( $requeued['reason_bucket'] ?? '' ) === '';
				$retry_detail = sprintf( 'status=%s; attempts=%d; reason=%s', (string) ( $requeued['status'] ?? 'missing' ), (int) ( $requeued['attempt_count'] ?? 0 ), (string) ( $requeued['reason_bucket'] ?? 'missing' ) );
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Runtime - owner-scoped retry requeues failed job', $retry_ok, $retry_detail );

		$missing_job_id = 'twaj_diag_missing';
		$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/artifacts/jobs/' . $missing_job_id );
		$res = rest_do_request( $req );
		$data = is_wp_error( $res ) ? array() : (array) rest_ensure_response( $res )->get_data();
		$missing_ok = ! is_wp_error( $res )
			&& (string) ( $data['job_id'] ?? '' ) === $missing_job_id
			&& (string) ( $data['code'] ?? '' ) === 'not_found'
			&& (string) ( $data['job_status'] ?? '' ) === 'missing'
			&& ! empty( $data['help_code'] );
		$this->emit( $ctx, $steps, $pass, 'Runtime - missing job returns structured error payload', $missing_ok, sprintf( 'code=%s; job_status=%s; help_code=%s', (string) ( $data['code'] ?? 'missing' ), (string) ( $data['job_status'] ?? 'missing' ), (string) ( $data['help_code'] ?? 'missing' ) ) );

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Twin GPT durable artifact jobs PASS: synthetic failed/ready transitions, owner scope and missing-job error contract verified without provider calls.' : 'Twin GPT durable artifact jobs contract failed one or more checks.',
			'error'    => $pass ? '' : 'twinweb_async_artifacts_contract_failed',
			'fix_hint' => $pass ? '' : 'Check AT-7 job table provisioning, owner identity, poller transitions and /artifacts/jobs/{job_id} payload.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — delete only this probe run's synthetic rows.
		if ( empty( $this->synthetic_jobs ) || ! class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) || ! BizCity_TwinWeb_Artifact_Jobs::table_ready() ) {
			return;
		}
		global $wpdb;
		foreach ( $this->synthetic_jobs as $job_id ) {
			$wpdb->delete( BizCity_TwinWeb_Artifact_Jobs::table(), array( 'job_id' => sanitize_key( (string) $job_id ) ), array( '%s' ) );
		}
		$this->synthetic_jobs = array();
	}

	private function emit( $ctx, array &$steps, &$pass, $label, $ok, $detail ) {
		$step = array(
			'label'  => (string) $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => (string) $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $ok ) {
			$pass = false;
		}
	}

	private function all_true( array $checks ): bool {
		foreach ( $checks as $ok ) {
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	private function marker_detail( array $checks, $success ): string {
		$missing = array();
		foreach ( $checks as $key => $ok ) {
			if ( ! $ok ) {
				$missing[] = (string) $key;
			}
		}
		return empty( $missing ) ? (string) $success : 'Missing markers: ' . implode( ', ', $missing );
	}

	private function resolve_runtime_user_id() {
		$current = get_current_user_id();
		if ( $current > 0 ) {
			return (int) $current;
		}
		$admins = get_users( array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => array( 'ID' ),
		) );
		return ! empty( $admins ) && isset( $admins[0]->ID ) ? (int) $admins[0]->ID : 0;
	}

	private function past_datetime(): string {
		return date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 60 );
	}

	private function class_file_or_fallback( $class_name, $fallback ) {
		if ( class_exists( 'ReflectionClass' ) && class_exists( (string) $class_name ) ) {
			try {
				$ref = new ReflectionClass( (string) $class_name );
				$file = (string) $ref->getFileName();
				if ( $file !== '' && is_readable( $file ) ) {
					return $file;
				}
			} catch ( \Throwable $e ) {
				// Use fallback below.
			}
		}
		return $fallback;
	}

	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
				return true;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}
}

// [2026-08-01 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS AT-7 — register durable async artifact probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Async_Artifacts';
	return $list;
} );
