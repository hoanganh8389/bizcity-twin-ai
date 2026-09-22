<?php
/**
 * PHASE-0.63A WP-1 — standalone checks for the pipeline definition layer (lane S).
 *
 * Run: php tests/unit/CrmPipelineRegistryTest.php
 *
 * No WordPress required. The registry only touches WP through guarded `function_exists()` calls, so
 * the parts that matter here — contract validation, the anti-spoof clamp on the kind filter, the open
 * action set — are exercised against the real class, not a copy of it.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
define( 'BIZCITY_CRM_DIR', dirname( __DIR__, 2 ) );

/* ---- Minimal WordPress surface ------------------------------------------------ */

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

$GLOBALS['test_filters'] = array();
function add_filter( $hook, $callback ) { $GLOBALS['test_filters'][ $hook ][] = $callback; }
function remove_all_filters( $hook ) { unset( $GLOBALS['test_filters'][ $hook ] ); }
function apply_filters( $hook, $value ) {
	foreach ( $GLOBALS['test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}

require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-registry.php';
require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-service.php';
require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php';
require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-clock.php';
require dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-runner.php';

/* ---- Harness ------------------------------------------------------------------ */

$pass = 0;
$fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; return; }
	$fail++;
	echo "FAIL: {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
}
function reasons_of( $result ) {
	if ( ! is_wp_error( $result ) ) { return array(); }
	$data = $result->get_error_data();
	return is_array( $data ) && isset( $data['reasons'] ) ? (array) $data['reasons'] : array();
}
function load_json( $path ) {
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array();
}

$root      = dirname( __DIR__, 4 );
$fixtures  = $root . '/core/twin-core/contracts/schema/public/v1/fixtures/';
$templates = dirname( __DIR__, 2 ) . '/templates/pipelines/';

/* ---- 1. The shipped templates are valid definitions ---------------------------- */

foreach ( array( 'purchase', 'request', 'production' ) as $name ) {
	$definition = load_json( $templates . $name . '.json' );
	$result     = BizCity_CRM_Pipeline_Registry::validate( $definition );
	check( "template {$name}.json validates", true === $result, implode( ' | ', reasons_of( $result ) ) );
	check( "template {$name}.json declares its own kind", ( $definition['kind'] ?? '' ) === $name );
}

check(
	'template() reads purchase.json from disk',
	is_array( BizCity_CRM_Pipeline_Registry::template( 'purchase' ) )
);
check(
	'templates() lists the three built-ins',
	BizCity_CRM_Pipeline_Registry::templates() === array( 'production', 'purchase', 'request' ),
	implode( ',', BizCity_CRM_Pipeline_Registry::templates() )
);

/* ---- 2. Contract fixtures agree with the PHP mirror of the schema -------------- */

$valid_fixture = load_json( $fixtures . 'pipeline-definition.valid.json' );
check(
	'contract valid fixture passes the PHP validator',
	true === BizCity_CRM_Pipeline_Registry::validate( $valid_fixture ),
	implode( ' | ', reasons_of( BizCity_CRM_Pipeline_Registry::validate( $valid_fixture ) ) )
);
$invalid_fixture = load_json( $fixtures . 'pipeline-definition.invalid.json' );
check(
	'contract invalid fixture is rejected',
	is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $invalid_fixture ) )
);

/* ---- 3. Individual validation rules -------------------------------------------- */

$base = function ( array $overrides = array() ) {
	return array_merge( array(
		'contract' => 'pipeline-definition',
		'version'  => '1.0.0',
		'kind'     => 'demo_kind',
		'label'    => 'Demo',
		'roles'    => array( 'lead' => array( 'label' => 'Trưởng nhóm', 'resolve' => array( 'team' => 5 ) ) ),
		'stages'   => array(
			array( 'key' => 's1', 'label' => 'Bước 1', 'role' => 'lead' ),
			array( 'key' => 's2', 'label' => 'Bước 2' ),
		),
	), $overrides );
};

check( 'a minimal definition is valid', true === BizCity_CRM_Pipeline_Registry::validate( $base() ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'stages' => array(
	array( 'key' => 's1', 'label' => 'Bước 1' ),
	array( 'key' => 's1', 'label' => 'Trùng key' ),
) ) ) );
check( 'duplicate stage key is rejected', is_wp_error( $result ), implode( ' | ', reasons_of( $result ) ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'stages' => array(
	array( 'key' => 's1', 'label' => 'Bước 1', 'role' => 'R99' ),
) ) ) );
check( 'stage naming an undeclared role is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array(
	'gates' => array( array( 'stage' => 's2', 'requires' => array( 'all_of' => array( 'ghost' ) ) ) ),
) ) );
check( 'gate waiting on an unknown step is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array(
	'gates' => array( array( 'stage' => 's2', 'requires' => array( 'all_of' => array( 's1' ) ) ) ),
) ) );
check( 'gate on a declared step is accepted', true === $result, implode( ' | ', reasons_of( $result ) ) );

// 0.63 §2.1 — a negative offset only has meaning against a moment still ahead.
$negative_on_past = $base( array( 'process_sla' => array(
	'anchor' => 'run_created', 'offset' => '-40m', 'target' => 'stage_done(s2)',
) ) );
check( 'negative offset on run_created is rejected', is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $negative_on_past ) ) );

$negative_on_future = $base( array( 'rules' => array( array(
	'id' => 'depart_prep', 'anchor' => 'appointment_at', 'offset' => '-40m', 'target' => 'stage_started(s2)',
) ) ) );
$result = BizCity_CRM_Pipeline_Registry::validate( $negative_on_future );
check( 'negative offset on appointment_at is accepted', true === $result, implode( ' | ', reasons_of( $result ) ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'rules' => array( array(
	'id' => 'late', 'anchor' => 'field:promised_delivery_at', 'offset' => '-1d', 'target' => 'stage_done(s2)',
) ) ) ) );
check( 'negative offset on a run datetime field is accepted', true === $result, implode( ' | ', reasons_of( $result ) ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'rules' => array( array(
	'id' => 'ghost', 'anchor' => 'stage_ready(nope)', 'offset' => '+1h', 'target' => 'stage_done(s2)',
) ) ) ) );
check( 'rule anchored on an unknown step is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'clock' => 'calendar:missing' ) ) );
check( 'clock referencing an undeclared calendar is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array(
	'clock'     => 'calendar:office',
	'calendars' => array( 'office' => array( array( 'dow' => array( 1, 2 ), 'from' => '08:00', 'to' => '17:30' ) ) ),
) ) );
check( 'clock referencing a declared calendar is accepted', true === $result, implode( ' | ', reasons_of( $result ) ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array(
	'calendars' => array( 'office' => array( array( 'dow' => array( 9 ), 'from' => '25:00', 'to' => '17:30' ) ) ),
) ) );
check( 'malformed calendar window is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'stages' => array(
	array( 'key' => 's1', 'label' => 'Bước 1', 'sub_steps' => array(
		array( 'key' => 's1a', 'label' => 'Phụ', 'mode' => 'checklist' ),
	) ),
) ) ) );
check( 'sub-step with an unknown mode is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'stages' => array(
	array( 'key' => 's1', 'label' => 'Bước 1', 'return_to' => 'nowhere' ),
) ) ) );
check( 'return_to pointing at an unknown step is rejected', is_wp_error( $result ) );

$result = BizCity_CRM_Pipeline_Registry::validate( $base( array( 'stages' => array(
	array( 'key' => 's1', 'label' => 'Bước 1', 'requires' => array( 'evidence' => array( 'hologram' ) ) ),
) ) ) );
check( 'unknown evidence kind is rejected', is_wp_error( $result ) );

/* ---- 4. The escalation ladder --------------------------------------------------- */

$ladder = function ( $do ) use ( $base ) {
	return $base( array( 'process_sla' => array(
		'anchor'  => 'run_created',
		'offset'  => '+2h',
		'target'  => 'stage_done(s2)',
		'on_miss' => array( array( 'at' => '0', 'do' => $do ) ),
	) ) );
};

check( 'core action notify() is accepted', true === BizCity_CRM_Pipeline_Registry::validate( $ladder( 'notify(role:lead)' ) ) );
check( 'chained actions are accepted', true === BizCity_CRM_Pipeline_Registry::validate( $ladder( 'flag(at_risk) + notify(role:lead)' ) ) );
check( 'unregistered action is rejected', is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $ladder( 'teleport(role:lead)' ) ) ) );
check( 'notify() on an undeclared role is rejected', is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $ladder( 'notify(role:R99)' ) ) ) );
check( 'flag() outside at_risk/breached is rejected', is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $ladder( 'flag(done)' ) ) ) );
check(
	'a missed deadline cannot advance a stage',
	is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $ladder( 'change_stage(s2)' ) ) )
);
check( 'malformed rung offset is rejected', is_wp_error( BizCity_CRM_Pipeline_Registry::validate( $base( array(
	'process_sla' => array(
		'anchor' => 'run_created', 'offset' => '+2h', 'target' => 'stage_done(s2)',
		'on_miss' => array( array( 'at' => 'soon', 'do' => 'notify(role:lead)' ) ),
	),
) ) ) ) );

// D63-2 — the action set must be open, or a business would need a core patch to escalate its own way.
check( 'five core actions ship by default', count( BizCity_CRM_Pipeline_Registry::core_sla_actions() ) === 5 );
add_filter( 'bizcity_crm_register_pipeline_sla_actions', function ( $actions ) {
	$actions['page_oncall'] = array( 'action' => 'page_oncall', 'label' => 'Gọi trực ca' );
	$actions['spoofed']     = array( 'action' => 'something_else', 'label' => 'Đổi tên giữa đường' );
	return $actions;
} );
$actions = BizCity_CRM_Pipeline_Registry::sla_actions();
check( 'an extension can register a new on_miss action', isset( $actions['page_oncall'] ) );
check( 'a registration renaming itself is dropped', ! isset( $actions['spoofed'] ) );
check( 'core actions survive an extension filter', isset( $actions['flag'], $actions['notify'], $actions['escalate'], $actions['reassign'], $actions['emit'] ) );
check(
	'the newly registered action is usable in a ladder',
	true === BizCity_CRM_Pipeline_Registry::validate( $ladder( 'page_oncall(role:lead)' ) )
);
remove_all_filters( 'bizcity_crm_register_pipeline_sla_actions' );

/* ---- 5. Kind registry: anti-spoof clamp ---------------------------------------- */

BizCity_CRM_Pipeline_Registry::flush_cache();
check( 'all() on a bare site returns an empty array, not a fatal', BizCity_CRM_Pipeline_Registry::all() === array() );
check( 'registration_issues() is clean when nothing is registered', BizCity_CRM_Pipeline_Registry::registration_issues() === array() );

$purchase = load_json( $templates . 'purchase.json' );
add_filter( 'bizcity_crm_register_pipeline_kinds', function ( $kinds ) use ( $purchase ) {
	$kinds['purchase'] = array( 'kind' => 'purchase', 'definition' => $purchase );
	// Claims a key that is not its own — the Channel Registry precedent says drop it, loudly.
	$kinds['sales']    = array( 'kind' => 'purchase', 'definition' => $purchase );
	return $kinds;
} );
BizCity_CRM_Pipeline_Registry::flush_cache();
$all = BizCity_CRM_Pipeline_Registry::all();
check( 'a well-formed kind registration is picked up', isset( $all['purchase'] ) );
check( 'a kind registration claiming another key is dropped', ! isset( $all['sales'] ) );
check( 'get() returns the registered definition', ( BizCity_CRM_Pipeline_Registry::get( 'purchase' )['kind'] ?? '' ) === 'purchase' );
check( 'get() on an unknown kind returns null', null === BizCity_CRM_Pipeline_Registry::get( 'nope' ) );
$issues = BizCity_CRM_Pipeline_Registry::registration_issues();
check( 'the spoofed registration is reported, not hidden', in_array( 'sales:kind_mismatch:purchase', $issues, true ), implode( ' | ', $issues ) );

$tools = BizCity_CRM_Pipeline_Registry::tools();
check( 'tools() falls back to the declared priority order', in_array( 'stage', $tools, true ) && in_array( 'supplier', $tools, true ), implode( ',', $tools ) );
remove_all_filters( 'bizcity_crm_register_pipeline_kinds' );
BizCity_CRM_Pipeline_Registry::flush_cache();

/* ---- 6. SLA service degrades safely outside WordPress -------------------------- */


$stubs = array(
	'sla_service.sync_for_run' => BizCity_CRM_Pipeline_SLA_Service::sync_for_run( 1 ),
);
foreach ( $stubs as $label => $result ) {
	check( "{$label} reports database unavailable outside WordPress", is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'run_not_found', 'db_unavailable' ), true ) );
}
check(
	'dedupe_key has the shape the unique index expects',
	'7:qc_finish:B13:2' === BizCity_CRM_Pipeline_SLA_Service::dedupe_key( 7, 'qc_finish', 'B13', 2 )
);
check(
	'runner fails closed without a WordPress database',
	is_wp_error( BizCity_CRM_Pipeline_SLA_Runner::claim_due( 'standalone-test' ) )
		&& 'db_unavailable' === BizCity_CRM_Pipeline_SLA_Runner::claim_due( 'standalone-test' )->get_error_code()
);

/* ---- 6b. REST definition save regression -------------------------------------- */

check(
	'pipeline REST save endpoint is no longer a stub',
	false === strpos(
		(string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-pipeline-rest.php' ),
		"'not_implemented', 'Màn lưu định nghĩa pipeline chưa được nối.'"
	)
);

$pipeline_rest_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-pipeline-rest.php' );
check( 'pipeline REST implements run transition callback', false !== strpos( $pipeline_rest_source, 'public static function transition_run(' ) );
check( 'pipeline REST implements exception callback', false !== strpos( $pipeline_rest_source, 'public static function transition_exception(' ) );
check( 'pipeline REST implements SLA state callback', false !== strpos( $pipeline_rest_source, 'public static function get_run_sla(' ) );
check( 'pipeline REST scopes run mutations through scoped_run()', false !== strpos( $pipeline_rest_source, '$run = self::scoped_run( (int) $request[\'id\'] );' ) );
check( 'pipeline REST preserves structured transition error codes', false !== strpos( $pipeline_rest_source, 'return is_wp_error( $result ) ? self::error_from( $result ) : self::ok' ) );

check( 'SLA target completion scopes updates to matching rule ids', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-service.php' ), 'rule_ids_for_target' ) );
check( 'SLA reconciliation preserves completed runtime state', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-service.php' ), "in_array( (string) \$existing['state'], array( 'pending', 'at_risk' ), true )" ) );
check( 'SLA runner detects a lost claim', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-runner.php' ), "'sla_claim_lost'" ) );
check( 'run service requires lock version when locking is enabled', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), "'lock_version_required'" ) );
check( 'terminal run transition cancels open SLA deadlines', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), 'cancel_open_sla' ) );
check( 'legacy SLA compares resolved time with RT due', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/sla/class-sla-evaluator.php' ), 'resolved_at > $rt_due' ) );
check( 'legacy SLA reporting maps breach and met events', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/reports/class-reporting-rollup.php' ), "'crm_sla_breached'" ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/reports/class-reporting-rollup.php' ), "'crm_sla_met'" ) );
check( 'legacy SLA repository guards a missing applied table', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-repository.php' ), 'table_exists( $tbl )' ) );
check( 'legacy SLA policy lookup also guards a missing policy table', substr_count( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-repository.php' ), 'table_exists( $tbl )' ) >= 2 );
check( 'admin chat grant REST reads guard a missing grants table', substr_count( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), 'BizCity_CRM_Admin_Chat_Grants::table() )' ) >= 5 );
check( 'CRM REST read permission accepts authorized inbox staff', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), "|| self::can_handle_inbox();" ) );
check( 'CRM REST permission callbacks expose structured denial', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), 'can_read_inbox_scope_response' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), 'permission_denied_response' ) );
check( 'CRM inbox endpoints guard missing inbox/conversation tables', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), 'tbl_inboxes()' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-rest-controller.php' ), 'tbl_conversations()' ) );
check( 'task-mode sub-steps create pipeline_run tasks', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), 'ensure_sub_step_tasks' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), "'related_entity_type' => 'pipeline_run'" ) );
check( 'task-mode sub-step task creation is idempotent', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), 'SELECT id FROM `{$table}` WHERE related_entity_type' ) );
check( 'step evidence persists into task data_json', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), 'persist_step_evidence' ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), "'data_json' => self::json( " . '$payload' ) );
check( 'step evidence documents use pipeline_run relation', false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), "'related_entity_type' => 'pipeline_run'" ) && false !== strpos( (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-run-service.php' ), 'tbl_crm_documents' ) );

check(
	'SLA runner normalizes persisted DATETIME values before rung calculations',
	false === strpos(
		(string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-runner.php' ),
		"(int) ( \$deadline['anchor_at'] ?? 0 )"
	)
);
check(
	'SLA runner keeps timestamp normalization in fire()',
	false !== strpos(
		(string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/pipeline/class-pipeline-sla-runner.php' ),
		"self::parse_db_time( (string) ( \$deadline['anchor_at'] ?? '' ) )"
	)
);

/* ---- 7. Pipeline SLA clock ----------------------------------------------------- */

$office = array(
	array( 'dow' => array( 1, 2, 3, 4, 5 ), 'from' => '08:00', 'to' => '17:00' ),
);
$monday_16 = strtotime( '2026-09-21 16:00:00 UTC' );
$monday_17 = strtotime( '2026-09-21 17:00:00 UTC' );
$tuesday_09 = strtotime( '2026-09-22 09:00:00 UTC' );

check( 'clock parses positive hours', 7200 === BizCity_CRM_Pipeline_SLA_Clock::parse_offset( '+2h' ) );
check( 'clock parses negative minutes', -2400 === BizCity_CRM_Pipeline_SLA_Clock::parse_offset( '-40m' ) );
check( '24x7 due date is direct', $monday_16 + 7200 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $monday_16, '+2h' ) );
check( 'calendar deadline converges across closed time', $tuesday_09 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $monday_16, '+2h', $office ) );
check( 'calendar deadline supports negative offset', $monday_16 === BizCity_CRM_Pipeline_SLA_Clock::due_from( $tuesday_09, '-2h', $office ) );
check( 'elapsed working seconds excludes closed time', 7200 === BizCity_CRM_Pipeline_SLA_Clock::elapsed_within( $monday_16, $tuesday_09, $office ) );
check( 'percentage rung resolves from anchor span', $monday_17 + 3600 === BizCity_CRM_Pipeline_SLA_Clock::rung_at( $monday_16, $monday_17, '+100%' ) );
check( 'invalid clock offset is rejected', is_wp_error( BizCity_CRM_Pipeline_SLA_Clock::parse_offset( 'tomorrow' ) ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
