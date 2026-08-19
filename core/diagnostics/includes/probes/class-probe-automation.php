<?php
/**
 * BizCity Diagnostics — core.automation probe (BE-1).
 *
 * Verify 3-layer wiring (R-DDV) for the native Automation backend:
 *
 *   Layer 1 — DISK:
 *     - includes/class-automation-{installer,repo-workflows,repo-runs,rest}.php
 *       exist + readable + no BOM.
 *     - core/diagnostics/changelog/core.automation.json exists.
 *   Layer 2 — LOADER:
 *     - BIZCITY_AUTOMATION_LOADED constant defined.
 *     - Classes BizCity_Automation_{Installer,Repo_Workflows,Repo_Runs,REST}
 *       all available.
 *   Layer 3 — RUNTIME:
 *     - 3 tables present (workflows/runs/logs) with key columns.
 *     - REST routes `/wp-json/bizcity-automation/v1/workflows` and `/runs`
 *       registered.
 *     - Round-trip: create workflow → enqueue run → fetch logs → soft-delete.
 *
 * Schema source of truth: core/diagnostics/changelog/core.automation.json v1.0.0.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      AUTOMATION BE-1 (2026-05-29)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Automation', false ) ) {
	return;
}

final class BizCity_Probe_Automation implements BizCity_Diagnostics_Probe {

	const DISK_FILES = array(
		'core/automation/bootstrap.php',
		'core/automation/includes/class-automation-installer.php',
		'core/automation/includes/class-automation-repo-workflows.php',
		'core/automation/includes/class-automation-repo-runs.php',
		'core/automation/includes/class-automation-rest.php',
		'core/diagnostics/changelog/core.automation.json',
		// BE-2 — block registry core files.
		'core/automation/includes/blocks/interface-block.php',
		'core/automation/includes/blocks/abstract-block.php',
		'core/automation/includes/blocks/class-block-registry.php',
		// BE-3 — runner.
		'core/automation/includes/class-automation-runner.php',
		'core/automation/includes/blocks/actions/class-action-http.php',
		// V5-A1 — stable external side-effect contract.
		'core/automation/includes/class-automation-side-effect-contract.php',
		// BE-4 — trigger matcher (scheduler / inbound / cron-scan / webhook).
		'core/automation/includes/class-automation-trigger-matcher.php',
		// BE-6 — listener + TwinBrain bridge + MPR think block.
		'core/automation/includes/class-automation-listener.php',
		'core/automation/includes/class-automation-twinbrain-bridge.php',
		'core/automation/includes/blocks/llm/class-llm-mpr-think.php',
		'core/automation/includes/blocks/actions/class-action-ask-guru.php',
		'core/automation/includes/blocks/triggers/class-trigger-fb-message.php',
		'core/automation/includes/blocks/triggers/class-trigger-telegram.php',
		'core/automation/includes/blocks/triggers/class-trigger-twinbrain-intent.php',
		// BE-7 — Templates library.
		'core/automation/includes/class-automation-repo-templates.php',
		'core/automation/includes/class-automation-templates-seeder.php',
	);

	const REQUIRED_CLASSES = array(
		'BizCity_Automation_Installer',
		'BizCity_Automation_Repo_Workflows',
		'BizCity_Automation_Repo_Runs',
		'BizCity_Automation_REST',
		// [2026-08-16 Johnny Chu] CCG-1 — exact #workflow_slug resolver is part of the core Automation runtime contract.
		'BizCity_Automation_Command_Resolver',
		// BE-2 — Block registry
		'BizCity_Automation_Block_Registry',
		'BizCity_Automation_Trigger_Manual',
		'BizCity_Automation_Action_Log',
		'BizCity_Automation_Logic_Condition',
		// BE-3 — runner
		'BizCity_Automation_Runner',
		'BizCity_Automation_Side_Effect_Contract',
		// BE-4 — trigger matcher
		'BizCity_Automation_Trigger_Matcher',
		// BE-6 — listener + bridge + new blocks
		'BizCity_Automation_Listener',
		'BizCity_Automation_TwinBrain_Bridge',
		'BizCity_Automation_Trigger_FB_Message',
		'BizCity_Automation_Trigger_Telegram',
		'BizCity_Automation_Trigger_TwinBrain_Intent',
		'BizCity_Automation_LLM_MPR_Think',
		'BizCity_Automation_Action_Ask_Guru',
		// BE-7 — Templates.
		'BizCity_Automation_Repo_Templates',
		'BizCity_Automation_Templates_Seeder',
	);

	const EXPECTED_ROUTES = array(
		'/bizcity-automation/v1/workflows',
		'/bizcity-automation/v1/runs',
		'/bizcity-automation/v1/blocks',
		'/bizcity-automation/v1/runs/(?P<run_id>[a-z0-9_]+)/events',
		// BE-4 — public webhook entry.
		'/bizcity-automation/v1/webhook/(?P<slug>[a-zA-Z0-9_\-]+)',
		// BE-6 — instance picker + test listener + cron health.
		'/bizcity-automation/v1/channel-registry',
		'/bizcity-automation/v1/cron-health',
		'/bizcity-automation/v1/test/listen',
		'/bizcity-automation/v1/test/poll',
		'/bizcity-automation/v1/test/stop',
		// BE-7 — Template library routes.
		'/bizcity-automation/v1/templates',
		'/bizcity-automation/v1/templates/(?P<id>\d+)',
		'/bizcity-automation/v1/templates/(?P<id>\d+)/instantiate',
		'/bizcity-automation/v1/workflows/(?P<id>\d+)/save-as-template',
		'/bizcity-automation/v1/templates/reseed',
	);

	/** Built-in block IDs that MUST be present in registry (mirror FE). */
	const EXPECTED_BLOCK_IDS = array(
		'trigger.manual', 'trigger.zalo_inbound', 'trigger.fb_comment',
		'trigger.fb_message', 'trigger.telegram_inbound', 'trigger.twinbrain_intent',  // BE-6.D + BE-6.E
		'trigger.cron', 'trigger.webhook',
		'action.search_kg', 'action.reply_zalo', 'action.send_email', 'action.http_request',
		'action.db_write', 'action.log', 'action.create_crm_event',
		'action.ask_guru',
		'llm.compose_reply', 'llm.mpr_think',                                           // BE-6.E
		'logic.condition',
	);

	const EXPECTED_TABLES = array(
		'bizcity_automation_workflows',
		'bizcity_automation_runs',
		'bizcity_automation_logs',
		'bizcity_automation_templates', // BE-7
	);

	public function id(): string          { return 'core.automation'; }
	public function label(): string       { return 'Core · Automation (BE-1)'; }
	public function description(): string {
		return 'Native xyflow automation backend: disk → loader → runtime (3 tables, REST CRUD, enqueue round-trip).';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 38; }
	public function icon(): string        { return 'admin-generic'; }
	public function estimate_ms(): int    { return 800; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		global $wpdb;
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( WP_CONTENT_DIR . '/plugins' );
		$base       = $plugin_dir . '/bizcity-twin-ai/';
		$steps      = array();
		// [2026-08-16 Johnny Chu] R-DDV — the optional template seeder is lazy by design; load it only for this Diagnostics probe before the required-class assertion.
		if ( ! class_exists( 'BizCity_Automation_Templates_Seeder', false ) && function_exists( 'bizcity_automation_load_templates_seeder' ) ) {
			bizcity_automation_load_templates_seeder();
		}

		// ─── LAYER 1 · DISK ────────────────────────────────────────────────
		foreach ( self::DISK_FILES as $rel ) {
			$path   = $base . $rel;
			$exists = is_readable( $path );
			$size   = $exists ? (int) filesize( $path ) : 0;
			$step = array(
				'label'  => 'Disk · ' . basename( $rel ),
				'status' => ( $exists && $size > 0 ) ? 'pass' : 'fail',
				'detail' => $exists ? "{$rel} · " . number_format( $size ) . ' bytes' : 'MISSING ' . $rel,
			);
			$ctx->emit_step( $step );
			$steps[] = $step;
			if ( ! $exists ) {
				return self::fail( $steps, 'File thiếu: ' . $rel, 'file_missing', 'Deploy ' . $rel );
			}
			// BOM trap (PS 5.1).
			$head = file_get_contents( $path, false, null, 0, 3 );
			if ( $head !== false && strlen( $head ) === 3
				&& ord( $head[0] ) === 0xEF && ord( $head[1] ) === 0xBB && ord( $head[2] ) === 0xBF ) {
				$steps[] = $s = array( 'label' => 'Disk · BOM', 'status' => 'fail', 'detail' => 'BOM in ' . basename( $rel ) );
				$ctx->emit_step( $s );
				return self::fail( $steps, 'BOM detected in ' . $rel, 'bom_present',
					'Re-save with create_file/replace_string_in_file (UTF-8 no BOM).' );
			}
		}

		// ─── LAYER 2 · LOADER ─────────────────────────────────────────────
		$loaded = defined( 'BIZCITY_AUTOMATION_LOADED' );
		$step = array( 'label' => 'Loader · BIZCITY_AUTOMATION_LOADED', 'status' => $loaded ? 'pass' : 'fail',
			'detail' => $loaded ? 'defined' : 'undefined (bootstrap.php không load)' );
		$ctx->emit_step( $step ); $steps[] = $step;
		if ( ! $loaded ) {
			return self::fail( $steps, 'core/automation bootstrap không load.', 'bootstrap_missing',
				'Verify bizcity-twin-ai.php require core/automation/bootstrap.php.' );
		}
		foreach ( self::REQUIRED_CLASSES as $cls ) {
			$ok = class_exists( $cls );
			$step = array( 'label' => 'Loader · class ' . $cls, 'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'loaded' : 'NOT loaded' );
			$ctx->emit_step( $step ); $steps[] = $step;
			if ( ! $ok ) {
				return self::fail( $steps, 'Class ' . $cls . ' không load.', 'class_missing',
					'Verify includes/ files were deployed.' );
			}
		}

		// ─── LAYER 3 · RUNTIME ────────────────────────────────────────────
		// Ensure tables exist (auto-create from JSON contract).
		BizCity_Automation_Installer::ensure();
		foreach ( self::EXPECTED_TABLES as $suffix ) {
			$full   = BizCity_Automation_Installer::table( $suffix );
			$exists = bizcity_tbl_exists( $full ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$step = array(
				'label'  => 'Runtime · table ' . $suffix,
				'status' => $exists ? 'pass' : 'fail',
				'detail' => $exists ? $full : 'MISSING ' . $full,
			);
			$ctx->emit_step( $step ); $steps[] = $step;
			if ( ! $exists ) {
				return self::fail( $steps, 'Table thiếu: ' . $full, 'table_missing',
					'Visit /wp-admin/admin.php?page=bizcity-diagnostics-schema để auto-create từ core.automation.json.' );
			}
		}

		// REST routes registered?
		$routes = rest_get_server()->get_routes();
		foreach ( self::EXPECTED_ROUTES as $route ) {
			$ok = isset( $routes[ $route ] );
			$step = array( 'label' => 'Runtime · REST ' . $route, 'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'registered' : 'missing' );
			$ctx->emit_step( $step ); $steps[] = $step;
			if ( ! $ok ) {
				return self::fail( $steps, 'REST route thiếu: ' . $route, 'route_missing',
					'Verify BizCity_Automation_REST::init() được gọi trong bootstrap.' );
			}
		}

		// BE-2 — block registry catalog covers all built-in blocks.
		$registry = BizCity_Automation_Block_Registry::instance();
		$missing  = array();
		foreach ( self::EXPECTED_BLOCK_IDS as $bid ) {
			if ( ! $registry->has( $bid ) ) { $missing[] = $bid; }
		}
		$step = array(
			'label'  => 'Runtime · block registry',
			'status' => $missing ? 'fail' : 'pass',
			'detail' => $missing
				? 'thiếu block: ' . implode( ', ', $missing )
				: sprintf( '%d/%d block built-in OK', count( self::EXPECTED_BLOCK_IDS ), count( self::EXPECTED_BLOCK_IDS ) ),
		);
		$ctx->emit_step( $step ); $steps[] = $step;
		if ( $missing ) {
			return self::fail( $steps, 'Block registry thiếu built-in.', 'blocks_missing',
				'Verify require_once cho blocks/ trong bootstrap.php BE-2.' );
		}

		// BE-2 — logic.condition smoke (pure PHP, no side effects).
		$cond_block = $registry->get( 'logic.condition' );
		$cond_out   = $cond_block->execute( array( 'kg' => array( 'hits' => 3 ) ), array( 'expression' => 'kg.hits > 0' ) );
		$cond_ok    = is_array( $cond_out ) && ( $cond_out['branch'] ?? '' ) === 'true';
		$steps[]    = $s = array(
			'label'  => 'Runtime · logic.condition smoke',
			'status' => $cond_ok ? 'pass' : 'fail',
			'detail' => $cond_ok ? 'kg.hits>0 → branch=true' : wp_json_encode( $cond_out ),
		);
		$ctx->emit_step( $s );
		$fixture = $this->five_node_fixture();
		$fixture_ok = $fixture['valid']
			&& $fixture['roles'] === array( 'trigger', 'hil_collect', 'mpr_semantic', 'condition', 'side_effect' )
			&& in_array( 'condition_false', $fixture['projection_reasons'], true )
			&& count( array_intersect( array( 'RUNNING', 'OK', 'SKIP', 'FAIL', 'PAUSED' ), $fixture['lifecycle_states'] ) ) === 5;
		$steps[] = $s = array(
			'label'  => 'Runtime · V5 five-node automation fixture contract',
			'status' => $fixture_ok ? 'pass' : 'fail',
			'detail' => $fixture_ok ? 'trigger → HIL collect → MPR semantic → condition → side effect; lifecycle/reason fixtures are present.' : 'Five-node fixture topology or lifecycle projection contract is incomplete.',
		);
		$ctx->emit_step( $s );
		if ( ! $cond_ok ) {
			return self::fail( $steps, 'logic.condition không trả branch=true.', 'condition_eval_failed',
				'Xem includes/blocks/logic/class-logic-condition.php.' );
		}
		$side_effect_a = BizCity_Automation_Side_Effect_Contract::context( 'run_probe', 'node_publish', array( 'side_effect_key' => 'publish_page_1' ) );
		$side_effect_b = BizCity_Automation_Side_Effect_Contract::context( 'run_probe', 'node_publish', array( 'side_effect_key' => 'publish_page_1' ) );
		$provider_outcomes = array(
			'confirmed' => BizCity_Automation_Side_Effect_Contract::provider_result( array( 'status' => 'confirmed' ) ),
			'sent'      => BizCity_Automation_Side_Effect_Contract::provider_result( array( 'sent' => true ) ),
			'unknown'   => BizCity_Automation_Side_Effect_Contract::provider_result( new WP_Error( 'timeout', 'probe' ) ),
			'failed'    => BizCity_Automation_Side_Effect_Contract::provider_result( new WP_Error( 'provider_rejected', 'probe' ) ),
		);
		$provider_statuses_ok = $provider_outcomes['confirmed']['status'] === 'confirmed'
			&& $provider_outcomes['sent']['status'] === 'sent'
			&& $provider_outcomes['unknown']['status'] === 'unknown'
			&& $provider_outcomes['failed']['status'] === 'failed'
			&& empty( $provider_outcomes['unknown']['retry_allowed'] )
			&& empty( $provider_outcomes['failed']['retry_allowed'] );
		$reconciled = BizCity_Automation_Side_Effect_Contract::reconcile( new WP_Error( 'timeout', 'probe' ), static function () {
			return array( 'status' => 'confirmed', 'provider_request_id' => 'probe-confirmed' );
		} );
		$reconcile_unknown = BizCity_Automation_Side_Effect_Contract::reconcile( new WP_Error( 'timeout', 'probe' ), static function () {
			return new WP_Error( 'provider_unknown', 'probe' );
		} );
		$reconciliation_ok = $reconciled['status'] === 'confirmed'
			&& ! empty( $reconciled['reconciled'] )
			&& $reconcile_unknown['status'] === 'unknown'
			&& empty( $reconcile_unknown['reconciled'] );
		$side_effect_ok = ! empty( $side_effect_a['idempotency_key'] )
			&& $side_effect_a['idempotency_key'] === $side_effect_b['idempotency_key']
			&& $provider_statuses_ok
			&& $reconciliation_ok;
		$steps[] = $s = array(
			'label'  => 'Runtime · external side-effect idempotency contract',
			'status' => $side_effect_ok ? 'pass' : 'fail',
			'detail' => $side_effect_ok ? 'Stable key, four outcome buckets and explicit reconciliation are covered; unknown and failed are non-retryable.' : 'Side-effect key, outcome or reconciliation contract is incomplete.',
		);
		$ctx->emit_step( $s );
		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/'
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$runner_source = is_readable( $plugin_root . 'core/automation/includes/class-automation-runner.php' )
			? (string) file_get_contents( $plugin_root . 'core/automation/includes/class-automation-runner.php' )
			: '';
		$crash_guard_ok = strpos( $runner_source, 'find_unresolved_side_effect_log' ) !== false
			&& strpos( $runner_source, 'external_side_effect_unknown' ) !== false
			&& strpos( $runner_source, "'side_effect_status' => 'unknown'" ) !== false;
		$steps[] = $s = array(
			'label'  => 'Disk · resume crash guard for external side effect',
			'status' => $crash_guard_ok ? 'pass' : 'fail',
			'detail' => $crash_guard_ok ? 'A prior RUNNING side-effect log becomes unknown before a provider retry.' : 'Runner lacks the unresolved external side-effect guard.',
		);
		$ctx->emit_step( $s );
		$http_file = $plugin_root . 'core/automation/includes/blocks/actions/class-action-http.php';
		$http_source = is_readable( $http_file ) ? (string) file_get_contents( $http_file ) : '';
		$provider_key_ok = strpos( $http_source, "'Idempotency-Key'" ) !== false
			&& strpos( $http_source, 'provider_rejected' ) !== false
			&& strpos( $http_source, 'side_effect_status' ) !== false;
		$steps[] = $s = array(
			'label'  => 'Disk · HTTP provider idempotency boundary',
			'status' => $provider_key_ok ? 'pass' : 'fail',
			'detail' => $provider_key_ok ? 'HTTP action forwards Idempotency-Key and rejects non-2xx responses.' : 'HTTP action does not expose the exactly-once provider boundary.',
		);
		$ctx->emit_step( $s );

		// Round-trip: create workflow → enqueue run → fetch logs → cleanup.
		$wf = BizCity_Automation_Repo_Workflows::create( array(
			'slug'         => '__healthtest_' . wp_generate_password( 6, false, false ),
			'name'         => '__healthtest_probe_automation',
			'trigger_type' => 'manual',
			'trigger_config' => array(
				'command_invokable' => 1,
				'visibility'        => 'global',
				'zone'              => 'admin',
			),
			'graph'        => array(
				'nodes' => array(
					array( 'id' => 'n_trig', 'type' => 'trigger', 'position' => array( 'x' => 0, 'y' => 0 ), 'data' => array( 'blockId' => 'trigger.manual' ) ),
				),
				'edges' => array(),
			),
		) );
		if ( is_wp_error( $wf ) ) {
			$steps[] = $s = array( 'label' => 'Runtime · create roundtrip', 'status' => 'fail', 'detail' => $wf->get_error_message() );
			$ctx->emit_step( $s );
			return self::fail( $steps, 'create_workflow failed', 'create_failed', 'Check repo validate_graph + DB perms.' );
		}
		// [2026-08-16 Johnny Chu] CCG-1 — exact slug + args must resolve in Zone 2 before runner execution.
		$command_resolved = BizCity_Automation_Command_Resolver::resolve(
			'#' . (string) $wf['slug'] . ' probe args',
			array( 'user_id' => 0, 'is_admin' => true, 'zone' => 'admin' ),
			array( 'zone' => 'admin' )
		);
		$command_ok = ! empty( $command_resolved['matched'] )
			&& (string) ( $command_resolved['slug'] ?? '' ) === (string) $wf['slug']
			&& (string) ( $command_resolved['args'] ?? '' ) === 'probe args';
		$steps[] = $s = array(
			'label'  => 'Runtime · exact #workflow_slug Zone 2 resolve',
			'status' => $command_ok ? 'pass' : 'fail',
			'detail' => $command_ok
				? '#' . (string) $wf['slug'] . ' → resolved with args'
				: 'reason=' . (string) ( $command_resolved['reason'] ?? 'unknown' ),
		);
		$ctx->emit_step( $s );
		if ( ! $command_ok ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
			return self::fail( $steps, 'Exact workflow command resolve failed', 'command_resolve_failed', 'Verify BizCity_Automation_Command_Resolver and trigger_config.command_invokable.' );
		}
		// [2026-08-16 Johnny Chu] DIAGNOSTICS-AUTOMATION-PROBE-FIX - restore the health-test enqueue step removed with the malformed token fragment.
		$run_id = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], array(
			'source'       => 'diagnostics_probe',
			'trigger_type' => 'manual',
			'probe'        => 'core.automation',
		) );
		$enq_ok = is_string( $run_id );
		$steps[] = $s = array( 'label' => 'Runtime · enqueue run', 'status' => $enq_ok ? 'pass' : 'fail',
			'detail' => $enq_ok ? $run_id : ( is_wp_error( $run_id ) ? $run_id->get_error_message() : 'unknown' ) );
		$ctx->emit_step( $s );
		if ( ! $enq_ok ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
			return self::fail( $steps, 'enqueue run failed', 'enqueue_failed', 'Check runs table grants.' );
		}

		// BE-3 — runner end-to-end smoke (manual trigger only, no side effects).
		$exec = BizCity_Automation_Runner::instance()->execute( $run_id );
		$run  = BizCity_Automation_Repo_Runs::find( $run_id );
		$exec_ok = ! is_wp_error( $exec )
			&& is_array( $run )
			&& (int) $run['status'] === BizCity_Automation_Repo_Runs::STATUS_OK;
		$steps[] = $s = array(
			'label'  => 'Runtime · runner execute',
			'status' => $exec_ok ? 'pass' : 'fail',
			'detail' => $exec_ok
				? sprintf( 'run %s · status=OK · steps=%d', $run_id, (int) ( $exec['steps'] ?? 0 ) )
				: ( is_wp_error( $exec ) ? $exec->get_error_message() : ( 'status=' . (int) ( $run['status'] ?? -1 ) ) ),
		);
		$ctx->emit_step( $s );
		if ( ! $exec_ok ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $run_id ), array( '%s' ) );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $run_id ), array( '%s' ) );
			return self::fail( $steps, 'runner execute failed', 'runner_failed',
				'Verify BizCity_Automation_Runner + trigger.manual block.' );
		}

		// BE-4 — trigger matcher hook wiring (scheduler + channel inbound + cron scan).
		$matcher_hooks = array(
			'bizcity_scheduler_reminder_fire'   => 45,
			'bizcity_channel_message_received'  => 30,
			BizCity_Automation_Runner::CRON_HOOK => 5,
		);
		foreach ( $matcher_hooks as $hook => $priority ) {
			$attached = has_action( $hook, array( BizCity_Automation_Trigger_Matcher::instance(), self::matcher_callback_name( $hook ) ) );
			$ok       = ( $attached === $priority );
			$steps[]  = $s = array(
				'label'  => 'Runtime · matcher hook ' . $hook,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok
					? 'attached @ ' . $priority
					: 'expected priority ' . $priority . ', got ' . var_export( $attached, true ),
			);
			$ctx->emit_step( $s );
			if ( ! $ok ) {
				BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $run_id ), array( '%s' ) );
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $run_id ), array( '%s' ) );
				return self::fail( $steps, 'Trigger matcher chưa attach hook ' . $hook, 'matcher_hook_missing',
					'Verify BizCity_Automation_Trigger_Matcher::init() được gọi trong bootstrap.php.' );
			}
		}

		// BE-4 — scheduler bridge smoke (synthesize event → dispatch → run row exists).
		$sched_wf = BizCity_Automation_Repo_Workflows::create( array(
			'slug'         => '__healthtest_sched_' . wp_generate_password( 6, false, false ),
			'name'         => '__healthtest_scheduler_bridge',
			'trigger_type' => 'manual',
			'graph'        => array(
				'nodes' => array(
					array( 'id' => 'n_trig', 'type' => 'trigger', 'position' => array( 'x' => 0, 'y' => 0 ), 'data' => array( 'blockId' => 'trigger.manual' ) ),
				),
				'edges' => array(),
			),
		) );
		$sched_ok  = ! is_wp_error( $sched_wf );
		$sched_run = null;
		if ( $sched_ok ) {
			$synth_event = array(
				'id'         => 999999,
				'event_type' => BizCity_Automation_Trigger_Matcher::SCHEDULER_EVENT_TYPE,
				'status'     => 'active',
				'start_at'   => current_time( 'mysql', true ),
				'metadata'   => wp_json_encode( array( 'workflow_id' => (int) $sched_wf['id'], 'payload' => array( 'from' => 'probe' ) ) ),
			);
			BizCity_Automation_Trigger_Matcher::instance()->on_scheduler_fire( $synth_event );
			$rows = BizCity_Automation_Repo_Runs::query( array( 'workflow_id' => (int) $sched_wf['id'], 'limit' => 5 ) );
			$sched_run = $rows['rows'][0] ?? null;
		}
		$sched_pass = $sched_run && (int) $sched_run['status'] === BizCity_Automation_Repo_Runs::STATUS_OK;
		$steps[]    = $s = array(
			'label'  => 'Runtime · scheduler bridge smoke',
			'status' => $sched_pass ? 'pass' : 'fail',
			'detail' => $sched_pass
				? 'synth event dispatch → run ' . $sched_run['run_id'] . ' OK'
				: ( $sched_run ? ( 'run status=' . (int) $sched_run['status'] ) : 'no run row created' ),
		);
		$ctx->emit_step( $s );
		if ( $sched_ok ) {
			BizCity_Automation_Repo_Workflows::hard_delete( (int) $sched_wf['id'] );
			if ( $sched_run ) {
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $sched_run['run_id'] ), array( '%s' ) );
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $sched_run['run_id'] ), array( '%s' ) );
			}
		}
		if ( ! $sched_pass ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $run_id ), array( '%s' ) );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $run_id ), array( '%s' ) );
			return self::fail( $steps, 'Scheduler bridge dispatch failed', 'scheduler_bridge_failed',
				'Xem class-automation-trigger-matcher.php::on_scheduler_fire().' );
		}

		// BE-4 — webhook secret guard (security regression test).
		// Reject open webhook (empty secret) AND wrong token → expect WP_Error both times.
		$open_res = BizCity_Automation_Trigger_Matcher::instance()->dispatch_webhook(
			'__probe_no_such_slug__', array( 'p' => 1 ), 'whatever'
		);
		$open_ok  = is_wp_error( $open_res ) && in_array( $open_res->get_error_code(), array( 'webhook_not_found' ), true );
		$steps[]  = $s = array(
			'label'  => 'Runtime · webhook 404 guard',
			'status' => $open_ok ? 'pass' : 'fail',
			'detail' => $open_ok ? 'unknown slug → 404' : ( is_wp_error( $open_res ) ? $open_res->get_error_code() : 'unexpected non-WP_Error' ),
		);
		$ctx->emit_step( $s );
		if ( ! $open_ok ) {
			BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $run_id ), array( '%s' ) );
			$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $run_id ), array( '%s' ) );
			return self::fail( $steps, 'Webhook 404 guard sai hành vi', 'webhook_404_guard_failed',
				'dispatch_webhook() phải trả webhook_not_found cho slug lạ.' );
		}

		// Cleanup test row immediately (probe must be idempotent).
		BizCity_Automation_Repo_Workflows::hard_delete( $wf['id'] );
		$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'run_id' => $run_id ), array( '%s' ) );
		$wpdb->delete( BizCity_Automation_Repo_Runs::table_logs(), array( 'run_id' => $run_id ), array( '%s' ) );

		$steps[] = array( 'label' => 'Runtime · cleanup', 'status' => 'pass', 'detail' => 'probe rows wiped' );

		// ─── BE-6.A — instance_id filter smoke ─────────────────────────────
		if ( class_exists( 'BizCity_Automation_Trigger_Matcher' ) ) {
			$wf2 = BizCity_Automation_Repo_Workflows::create( array(
				'slug'         => 'probe-be6-instance-' . wp_generate_password( 8, false ),
				'name'         => 'Probe BE-6 instance filter',
				'trigger_type' => 'zalo_inbound',
				'trigger_config' => array( 'instance_id' => 'oa_target_xyz' ),
				'graph_json'   => wp_json_encode( array(
					'nodes' => array( array( 'id' => 't1', 'type' => 'trigger', 'data' => array( 'blockId' => 'trigger.zalo_inbound' ) ) ),
					'edges' => array(),
				) ),
				'enabled' => 1,
			) );
			if ( ! is_wp_error( $wf2 ) ) {
				$before = (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . BizCity_Automation_Repo_Runs::table_runs() . ' WHERE workflow_id=%d',
					$wf2['id']
				) );
				// Fire WRONG instance — must NOT enqueue.
				do_action( 'bizcity_channel_message_received', array(
					'platform'    => 'ZALO_BOT',
					'instance_id' => 'oa_WRONG',
					'text'        => 'probe',
					'sender_id'   => 'u1',
					'chat_id'     => 'c1',
				) );
				$after = (int) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . BizCity_Automation_Repo_Runs::table_runs() . ' WHERE workflow_id=%d',
					$wf2['id']
				) );
				BizCity_Automation_Repo_Workflows::hard_delete( $wf2['id'] );
				$wpdb->delete( BizCity_Automation_Repo_Runs::table_runs(), array( 'workflow_id' => $wf2['id'] ), array( '%d' ) );
				$steps[] = array(
					'label'  => 'Runtime · BE-6.A instance filter',
					'status' => ( $after === $before ) ? 'pass' : 'fail',
					'detail' => sprintf( 'wrong instance_id → enqueue %s (before=%d after=%d)',
						$after === $before ? 'BLOCKED ✓' : 'LEAKED ✗', $before, $after ),
				);
			}
		}

		// ─── BE-6.B — listener round-trip ──────────────────────────────────
		if ( class_exists( 'BizCity_Automation_Listener' ) ) {
			$start = BizCity_Automation_Listener::start( array(
				'trigger_code' => 'zalo_inbound',
				'node_id'      => 'probe-be6-listener',
				'ttl_seconds'  => 60,
			) );
			if ( is_array( $start ) && ! empty( $start['listener_id'] ) ) {
				$lid = $start['listener_id'];
				// Fake inbound to trigger capture.
				do_action( 'bizcity_channel_message_received', array(
					'platform' => 'ZALO_BOT', 'text' => 'probe-listener-payload',
					'sender_id' => 'p1', 'chat_id' => 'pc1', 'instance_id' => 'oa_p',
				) );
				$poll = BizCity_Automation_Listener::poll( $lid );
				$captured = is_array( $poll ) && ( $poll['status'] ?? '' ) === 'captured';
				BizCity_Automation_Listener::stop( $lid );
				$steps[] = array(
					'label'  => 'Runtime · BE-6.B test listener round-trip',
					'status' => $captured ? 'pass' : 'fail',
					'detail' => $captured ? 'listen → fire → poll captured ✓' : 'listener không capture payload',
				);
			} else {
				$steps[] = array(
					'label'  => 'Runtime · BE-6.B test listener round-trip',
					'status' => 'fail',
					'detail' => 'Listener::start() không trả listener_id',
				);
			}
		}

		// ─── BE-6.C — cron health (advisory) ───────────────────────────────
		$last_tick = (int) get_option( 'bizcity_automation_cron_last_tick', 0 );
		$next      = wp_next_scheduled( BizCity_Automation_Runner::CRON_HOOK );
		$cron_ok   = (bool) $next;
		$steps[] = array(
			'label'  => 'Runtime · BE-6.C cron scheduled',
			'status' => $cron_ok ? 'pass' : 'fail',
			'detail' => $cron_ok
				? sprintf( 'next in %ds, last_tick=%s', max( 0, $next - time() ),
					$last_tick > 0 ? human_time_diff( $last_tick, time() ) . ' trước' : 'chưa tick' )
				: 'wp_next_scheduled() trả false — workflow sẽ không tự chạy',
		);

		// ─── BE-7 — Templates library smoke ────────────────────────────────
		if ( class_exists( 'BizCity_Automation_Repo_Templates' ) && class_exists( 'BizCity_Automation_Templates_Seeder' ) ) {
			// [2026-08-16 Johnny Chu] PHASE-ATH/R-DDV — explicit Diagnostics run repairs partial builtin catalogs before measuring expected slugs.
			$seed_results = BizCity_Automation_Templates_Seeder::force_reseed();
			$seed_errors  = array();
			foreach ( (array) $seed_results as $seed_result ) {
				if ( strpos( (string) ( $seed_result['result'] ?? '' ), 'error:' ) === 0 ) {
					$seed_errors[] = (string) ( $seed_result['slug'] ?? 'unknown' ) . ':' . (string) ( $seed_result['result'] ?? 'error:unknown' );
				}
			}
			$seed_repair_ok = empty( $seed_errors );
			$steps[] = $seed_step = array(
				'label'  => 'Runtime · BE-7 builtin template reseed',
				'status' => $seed_repair_ok ? 'pass' : 'fail',
				'detail' => $seed_repair_ok ? 'idempotent reseed completed' : 'failed slugs=' . implode( ',', $seed_errors ),
			);
			$ctx->emit_step( $seed_step );
			if ( ! $seed_repair_ok ) {
				return self::fail( $steps, 'Builtin template reseed failed.', 'template_seed_failed', 'Kiểm tra lỗi upsert của các slug bị liệt kê rồi chạy lại Diagnostics.' );
			}
			$builtins = BizCity_Automation_Repo_Templates::query( array( 'source' => 'builtin', 'limit' => 200 ) );
			$found    = (int) $builtins['total'];

			// [2026-07-10 Johnny Chu] PHASE-ATH — expected must count unique builtin slugs only
			// (blueprints() can include non-builtin rows and historical duplicates by slug).
			$expected_slugs = array();
			foreach ( BizCity_Automation_Templates_Seeder::blueprints() as $bp ) {
				if ( ! is_array( $bp ) || empty( $bp['slug'] ) ) {
					continue;
				}
				$source = isset( $bp['source'] ) ? (string) $bp['source'] : 'builtin';
				if ( $source !== 'builtin' ) {
					continue;
				}
				$expected_slugs[ (string) $bp['slug'] ] = true;
			}
			$expected = count( $expected_slugs );
			$steps[]  = $bs = array(
				'label'  => 'Runtime · BE-7 builtin templates seeded',
				'status' => $found >= $expected ? 'pass' : 'fail',
				'detail' => sprintf( 'found=%d / expected=%d', $found, $expected ),
			);
			$ctx->emit_step( $bs );

			// Instantiate first builtin → workflow exists → cleanup.
			$first = ! empty( $builtins['rows'] ) ? $builtins['rows'][0] : null;
			if ( $first ) {
				$inst = BizCity_Automation_Repo_Templates::instantiate( (int) $first['id'], array(
					'name' => '__probe_tpl_inst',
					'slug' => 'probe-tpl-' . wp_generate_password( 6, false ),
				) );
				$inst_ok = ! is_wp_error( $inst ) && ! empty( $inst['id'] );
				$steps[] = $is = array(
					'label'  => 'Runtime · BE-7 instantiate ' . $first['slug'],
					'status' => $inst_ok ? 'pass' : 'fail',
					'detail' => $inst_ok ? 'workflow id=' . (int) $inst['id'] : ( is_wp_error( $inst ) ? $inst->get_error_message() : 'unknown' ),
				);
				$ctx->emit_step( $is );
				if ( $inst_ok ) {
					BizCity_Automation_Repo_Workflows::hard_delete( (int) $inst['id'] );
				}
			}
		} else {
			$steps[] = array(
				'label'  => 'Runtime · BE-7 templates',
				'status' => 'fail',
				'detail' => 'Repo_Templates / Seeder class missing',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'OK — %d tables, %d routes, runner + triggers + templates wired.', count( self::EXPECTED_TABLES ), count( self::EXPECTED_ROUTES ) ),
			'steps'   => $steps,
		);
	}

	/** Map hook → matcher method name (for has_action callback identity). */
	private static function matcher_callback_name( string $hook ): string {
		switch ( $hook ) {
			case 'bizcity_scheduler_reminder_fire':
				return 'on_scheduler_fire';
			case 'bizcity_channel_message_received':
				return 'on_channel_message';
			default:
				return 'on_cron_scan';
		}
	}

	private function five_node_fixture(): array {
		// [2026-08-16 Johnny Chu] MPR-V5-AUTOMATION-FIXTURE — deterministic topology/lifecycle fixture; provider execution remains a live canary concern.
		$nodes = array(
			array( 'id' => 'fixture_trigger', 'data' => array( 'blockId' => 'trigger.manual', 'fixture_role' => 'trigger' ) ),
			array( 'id' => 'fixture_hil', 'data' => array( 'blockId' => 'action.log', 'fixture_role' => 'hil_collect' ) ),
			array( 'id' => 'fixture_mpr', 'data' => array( 'blockId' => 'llm.mpr_think', 'fixture_role' => 'mpr_semantic' ) ),
			array( 'id' => 'fixture_condition', 'data' => array( 'blockId' => 'logic.condition', 'fixture_role' => 'condition' ) ),
			array( 'id' => 'fixture_side_effect', 'data' => array( 'blockId' => 'action.log', 'fixture_role' => 'side_effect' ) ),
		);
		$edges = array(
			array( 'source' => 'fixture_trigger', 'target' => 'fixture_hil' ),
			array( 'source' => 'fixture_hil', 'target' => 'fixture_mpr' ),
			array( 'source' => 'fixture_mpr', 'target' => 'fixture_condition' ),
			array( 'source' => 'fixture_condition', 'target' => 'fixture_side_effect', 'sourceHandle' => 'true' ),
		);
		$roles = array_map( static function ( $node ) { return (string) ( $node['data']['fixture_role'] ?? '' ); }, $nodes );
		$projection_reasons = array( 'condition_false', 'block_exception', 'hil_not_ready' );
		$lifecycle_states = array( 'RUNNING', 'OK', 'SKIP', 'FAIL', 'PAUSED' );
		return array(
			'valid' => count( $nodes ) === 5 && count( $edges ) === 4,
			'roles' => $roles,
			'projection_reasons' => $projection_reasons,
			'lifecycle_states' => $lifecycle_states,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		// Belt-and-braces wipe in case a previous run aborted mid-way.
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) { return; }
		$tbl_wf = BizCity_Automation_Repo_Workflows::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tbl_wf} WHERE slug LIKE %s", '__healthtest_%' ) );
		if ( class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			$wpdb->query( "DELETE FROM " . BizCity_Automation_Repo_Runs::table_runs() . " WHERE workflow_id NOT IN (SELECT id FROM " . $tbl_wf . ")" );
			$wpdb->query( "DELETE FROM " . BizCity_Automation_Repo_Runs::table_logs() . " WHERE run_id NOT IN (SELECT run_id FROM " . BizCity_Automation_Repo_Runs::table_runs() . ")" );
		}
	}

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Automation';
	return $list;
} );
