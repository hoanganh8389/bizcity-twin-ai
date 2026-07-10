<?php
/**
 * WF-AUTO BRIDGE W1 — fail-OPEN test for action.invoke_skill.
 *
 * Run via WP-CLI:   wp eval-file core/automation/tests/test-action-invoke-skill.php
 * Or via browser:    ?bizc_test_invoke_skill=1 (admin only)
 *
 * @package BizCity_Automation
 * @since   2026-06-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( isset( $_GET['bizc_test_invoke_skill'] ) && current_user_can( 'manage_options' ) ) ) ) {
	return;
}

if ( ! class_exists( 'BizCity_Automation_Action_Invoke_Skill' ) ) {
	echo "FAIL: BizCity_Automation_Action_Invoke_Skill not loaded.\n";
	return;
}

$block = new BizCity_Automation_Action_Invoke_Skill();

$pass = 0;
$fail = 0;
$log  = function ( $name, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) { $pass++; echo "  PASS  $name\n"; }
	else       { $fail++; echo "  FAIL  $name :: $detail\n"; }
};

/* ============================================================ */
/* M1 — Metadata sanity                                          */
/* ============================================================ */
$meta = $block->meta();
$log( 'M1a id = action.invoke_skill', $block->id() === 'action.invoke_skill', $block->id() );
$log( 'M1b kind = action',            $block->kind() === 'action',            $block->kind() );
$log( 'M1c category = bridge',        ( $meta['category'] ?? '' ) === 'bridge', (string) ( $meta['category'] ?? '' ) );
$log( 'M1d has skill_slug field',     ! empty( array_filter( $meta['fields'] ?? [], function( $f ) { return ( $f['name'] ?? '' ) === 'skill_slug'; } ) ), 'missing' );

/* ============================================================ */
/* F1 — Empty skill_slug → fail-OPEN with reason=invalid_param   */
/* ============================================================ */
$out = $block->execute( array( '_workflow_id' => 0 ), array( 'skill_slug' => '' ) );
$log( 'F1 empty slug fail-OPEN',
	is_array( $out ) && $out['ok'] === false && ! empty( $out['_degraded'] ) && $out['reason'] === 'invalid_param',
	wp_json_encode( $out ) );

/* ============================================================ */
/* F2 — Unknown skill → fail-OPEN reason=skill_not_found         */
/* ============================================================ */
$unknown = '__nonexistent_skill_' . wp_generate_password( 8, false, false );
$out = $block->execute( array( '_workflow_id' => 0 ), array( 'skill_slug' => $unknown ) );
$log( 'F2 unknown slug fail-OPEN',
	is_array( $out ) && $out['ok'] === false && ! empty( $out['_degraded'] )
	&& in_array( $out['reason'], array( 'skill_not_found', 'skill_db_missing' ), true ),
	wp_json_encode( $out ) );
$log( 'F2b returns skill_output=""',
	isset( $out['skill_output'] ) && $out['skill_output'] === '',
	wp_json_encode( $out ) );

/* ============================================================ */
/* F3 — Resolve template tokens from ctx + vars_json             */
/* ============================================================ */
// We can't actually dispatch without a real skill, but we can verify the
// block doesn't fatal when ctx contains nested keys + vars_json is parsed.
$ctx = array(
	'_workflow_id' => 123,
	'_user_id'     => get_current_user_id(),
	'trigger'      => array( 'text' => 'hello world' ),
);
$out = $block->execute( $ctx, array(
	'skill_slug'      => $unknown,
	'prompt_template' => 'msg: {{trigger.text}} | foo={{vars.foo}}',
	'vars_json'       => '{"foo":"bar"}',
) );
$log( 'F3 template + vars resolved without fatal',
	is_array( $out ) && $out['ok'] === false && ! empty( $out['_degraded'] ),
	wp_json_encode( $out ) );

/* ============================================================ */
/* F4 — Malformed vars_json → still fail-OPEN (no fatal)         */
/* ============================================================ */
$out = $block->execute( $ctx, array(
	'skill_slug' => $unknown,
	'vars_json'  => '{not valid json',
) );
$log( 'F4 malformed vars_json fail-OPEN', is_array( $out ) && $out['ok'] === false, wp_json_encode( $out ) );

/* ============================================================ */
/* R1 — Registered in block registry                             */
/* ============================================================ */
if ( class_exists( 'BizCity_Automation_Block_Registry' ) ) {
	$reg = BizCity_Automation_Block_Registry::instance();
	$found = method_exists( $reg, 'get' ) ? $reg->get( 'action.invoke_skill' ) : null;
	$log( 'R1 block registered in registry', $found instanceof BizCity_Automation_Block, $found ? 'ok' : 'not found' );
} else {
	$log( 'R1 block registered in registry', false, 'BizCity_Automation_Block_Registry not loaded' );
}

echo "\n--------\n";
echo "PASS: $pass   FAIL: $fail\n";
