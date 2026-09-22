<?php
/**
 * PHASE-0.55 A5 — standalone regression test for
 * `BizCity_CRM_Task_Overdue_Notify::scan()`: fires `task_overdue` exactly once
 * per task that just crossed its due date, skips tasks already marked in the
 * audit log (idempotency) and skips tasks whose status is no longer open.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['crm_fired'] = array();
$GLOBALS['crm_audited'] = array();

function current_time( $type ) { return 'Y-m-d' === $type ? '2026-09-19' : gmdate( 'c' ); }
function do_action( $tag, ...$args ) { if ( 'bizcity_crm_task_overdue' === $tag ) { $GLOBALS['crm_fired'][] = $args[0]; } }
function add_action( $tag, $cb, $prio = 10, $args = 1 ) {}
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( $ts, $recur, $hook ) {}

class BizCity_CRM_DB_Installer_V2 {
	public static function tbl_crm_tasks() { return 'wp_bizcity_crm_tasks'; }
	public static function tbl_crm_audit_log() { return 'wp_bizcity_crm_audit_log'; }
	public static function table_exists( $t ) { return true; }
}

class BizCity_CRM_Audit_Log {
	public static function log( $entity_type, $entity_id, $action, $before = null, $after = null, $opts = array() ) {
		$GLOBALS['crm_audited'][] = array( $entity_type, $entity_id, $action );
	}
}

/** Mirrors the real class's status vocabulary just enough for the scanner's own filtering. */
class BizCity_CRM_Task_Handoff {
	const AUDIT_ENTITY  = 'crm_task';
	const OPEN_STATUSES = array( 'sent', 'accepted', 'in_progress', 'returned' );
	public static function normalize_status( array $row ) { return (string) ( $row['status'] ?? 'sent' ); }
	public static function is_overdue( array $row ) { return ( $row['due_date'] ?? '' ) < '2026-09-19'; }
}

/** Fake $wpdb: `candidates` seeds get_results(); `existing_audit` seeds get_col(). */
class FakeWpdb {
	public $candidates = array();
	public $existing_audit = array();
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_results( $sql, $output = ARRAY_A ) {
		return false !== strpos( $sql, 'FROM `wp_bizcity_crm_tasks`' ) ? $this->candidates : array();
	}
	public function get_col( $sql ) {
		return false !== strpos( $sql, 'audit_log' ) ? $this->existing_audit : array();
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname( __DIR__, 2 ) . '/includes/class-task-overdue-notify.php';

$pass = 0; $fail = 0;
function crm_check( $label, $ok ) { global $pass, $fail; $ok ? $pass++ : ( $fail++ . print "FAIL: {$label}\n" ); }

// Case 1: three overdue candidates, one already fired (in audit), one done (skip), one truly new.
$GLOBALS['wpdb']->candidates = array(
	array( 'id' => 1, 'assignee_id' => 10, 'due_date' => '2026-09-10', 'status' => 'sent', 'completed' => 0 ),      // new -> should fire
	array( 'id' => 2, 'assignee_id' => 10, 'due_date' => '2026-09-12', 'status' => 'sent', 'completed' => 0 ),      // already audited -> skip
	array( 'id' => 3, 'assignee_id' => 11, 'due_date' => '2026-09-05', 'status' => 'done', 'completed' => 1 ),      // done -> skip
);
$GLOBALS['wpdb']->existing_audit = array( 2 );
$GLOBALS['crm_fired'] = array();
$GLOBALS['crm_audited'] = array();

BizCity_CRM_Task_Overdue_Notify::scan();

crm_check( 'fires exactly once', 1 === count( $GLOBALS['crm_fired'] ) );
crm_check( 'fires for the new task (id 1), not the already-audited or done ones', array( 1 ) === $GLOBALS['crm_fired'] );
crm_check( 'writes an audit marker before firing', 1 === count( $GLOBALS['crm_audited'] ) && 'task_overdue' === $GLOBALS['crm_audited'][0][2] );

// Case 2: no candidates at all -> no-op, no crash.
$GLOBALS['wpdb']->candidates = array();
$GLOBALS['crm_fired'] = array();
BizCity_CRM_Task_Overdue_Notify::scan();
crm_check( 'empty candidate set is a no-op', empty( $GLOBALS['crm_fired'] ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail ? 1 : 0 );
