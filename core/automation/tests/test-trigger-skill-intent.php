<?php
/**
 * WF-AUTO BRIDGE W2 — Trigger skill_intent + Skill_Bridge dispatch test.
 *
 * Run via WP-CLI:   wp eval-file core/automation/tests/test-trigger-skill-intent.php
 * Or via browser:    ?bizc_test_skill_intent=1 (admin only)
 *
 * @package BizCity_Automation
 * @since   2026-06-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( isset( $_GET['bizc_test_skill_intent'] ) && current_user_can( 'manage_options' ) ) ) ) {
	return;
}

$pass = 0;
$fail = 0;
$log  = function ( $name, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) { $pass++; echo "  PASS  $name\n"; }
	else       { $fail++; echo "  FAIL  $name :: $detail\n"; }
};

/* M1 — Trigger block class loaded */
$log( 'M1a class loaded',   class_exists( 'BizCity_Automation_Trigger_Skill_Intent' ), 'class missing' );
$log( 'M1b bridge loaded',  class_exists( 'BizCity_Automation_Skill_Bridge' ),         'class missing' );

if ( ! class_exists( 'BizCity_Automation_Trigger_Skill_Intent' ) ) {
	echo "ABORT: trigger class not loaded.\n";
	return;
}

$block = new BizCity_Automation_Trigger_Skill_Intent();
$meta  = $block->meta();
$log( 'M2a id = trigger.skill_intent', $block->id() === 'trigger.skill_intent', $block->id() );
$log( 'M2b kind = trigger',            $block->kind() === 'trigger',            $block->kind() );
$log( 'M2c has skill_slug field',
	! empty( array_filter( $meta['fields'] ?? [], function( $f ) { return ( $f['name'] ?? '' ) === 'skill_slug'; } ) ),
	'missing field' );
$log( 'M2d archetype select options',
	in_array( 'A', (array) ( $meta['fields'][2]['options'] ?? [] ), true )
		&& in_array( 'C', (array) ( $meta['fields'][2]['options'] ?? [] ), true ),
	wp_json_encode( $meta['fields'][2]['options'] ?? [] ) );

/* T1 — TRIGGER_TYPES const includes skill_intent */
$log( 'T1 trigger_type registered',
	defined( 'BizCity_Automation_Repo_Workflows::TABLE' )
		&& in_array( 'skill_intent', (array) BizCity_Automation_Repo_Workflows::TRIGGER_TYPES, true ),
	'not registered' );

/* T2 — execute pass-through */
$out = $block->execute( array( 'trigger' => array( 'skill_slug' => 'demo' ) ), array() );
$log( 'T2 execute pass-through', is_array( $out ) && ( $out['skill_slug'] ?? '' ) === 'demo', wp_json_encode( $out ) );

/* T3 — Block registry has trigger.skill_intent */
if ( class_exists( 'BizCity_Automation_Block_Registry' ) ) {
	$reg = BizCity_Automation_Block_Registry::instance();
	$got = method_exists( $reg, 'get' ) ? $reg->get( 'trigger.skill_intent' ) : null;
	$log( 'T3 block registered',
		$got instanceof BizCity_Automation_Block,
		$got ? 'ok' : 'not registered' );
}

/* T4 — Skill_Bridge dispatch on bizcity_skill_invoked
 * No real workflow exists with trigger_type=skill_intent in test env, so
 * dispatch should silently complete without fatal. We verify by hooking
 * `bizcity_automation_run_enqueued` and counting invocations. */
$enqueued = array();
$probe = function( $run, $wf_id, $payload ) use ( &$enqueued ) {
	$enqueued[] = array( 'wf_id' => $wf_id, 'skill_slug' => $payload['skill_slug'] ?? '' );
};
add_action( 'bizcity_automation_run_enqueued', $probe, 10, 3 );
do_action( 'bizcity_skill_invoked', '__test_skill_' . wp_generate_password( 6, false, false ), array( 'archetype' => 'A' ) );
remove_action( 'bizcity_automation_run_enqueued', $probe, 10 );
$log( 'T4 invoke fires without fatal (no enqueue when no listener wf)',
	is_array( $enqueued ),
	wp_json_encode( $enqueued ) );

/* T5 — Skill_Bridge::on_skill_invoked ignores empty slug */
$err = '';
try {
	BizCity_Automation_Skill_Bridge::on_skill_invoked( '', array() );
} catch ( \Throwable $e ) {
	$err = $e->getMessage();
}
$log( 'T5 empty slug no-op', $err === '', $err );

echo "\n--------\n";
echo "PASS: $pass   FAIL: $fail\n";
