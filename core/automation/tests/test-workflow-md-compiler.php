<?php
/**
 * WF-AUTO W3 — Round-trip test for BizCity_Workflow_MD_Compiler.
 *
 * Run via WP-CLI:   wp eval-file core/automation/tests/test-workflow-md-compiler.php
 * Or via browser:    ?bizc_test_wf_md=1 (admin only, logged in)
 *
 * @package BizCity_Automation
 * @since   2026-06-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI )
	|| ( isset( $_GET['bizc_test_wf_md'] ) && current_user_can( 'manage_options' ) ) ) ) {
	return;
}

if ( ! class_exists( 'BizCity_Workflow_MD_Compiler' ) ) {
	echo "FAIL: BizCity_Workflow_MD_Compiler not loaded.\n";
	return;
}

$compiler = BizCity_Workflow_MD_Compiler::instance();

$pass = 0;
$fail = 0;
$log  = function ( $name, $ok, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $ok ) { $pass++; echo "  PASS  $name\n"; }
	else       { $fail++; echo "  FAIL  $name :: $detail\n"; }
};

/* ============================================================ */
/* T1 — G1 archetype guard rejects missing archetype             */
/* ============================================================ */
$md_bad_archetype = "---\nname: X\nslug: x\n---\n\n## Steps\n\n### 1. `trigger.manual` — Start\n";
$r = $compiler->md_to_workflow( $md_bad_archetype );
$log( 'T1 G1 reject when archetype missing', is_wp_error( $r ) && $r->get_error_code() === 'archetype_missing',
	is_wp_error( $r ) ? $r->get_error_code() : 'expected WP_Error' );

/* ============================================================ */
/* T2 — G1 archetype guard rejects wrong archetype               */
/* ============================================================ */
$md_wrong = "---\narchetype: skill\nname: X\nslug: x\n---\n\n## Steps\n\n### 1. `trigger.manual` — Start\n";
$r = $compiler->md_to_workflow( $md_wrong );
$log( 'T2 G1 reject when archetype != workflow', is_wp_error( $r ) && $r->get_error_code() === 'archetype_mismatch',
	is_wp_error( $r ) ? $r->get_error_code() : 'expected WP_Error' );

/* ============================================================ */
/* T3 — Linear 3-step round-trip                                 */
/* ============================================================ */
$md_linear = <<<MD
---
archetype: workflow
name: Demo Linear
slug: tpl_demo_linear_v1
description: 3-step linear chain
trigger_type: manual
icon: Play
tags: demo,linear
enabled: false
---

# Demo Linear

## Steps

### 1. `trigger.manual` — Bắt đầu

```yaml
instance_id: ""
```

### 2. `llm.compose_reply` — Soạn

```yaml
model: gpt-4o-mini
prompt: |
  Trả lời ngắn gọn.
  Dòng hai.
```

### 3. `action.create_crm_event` — Log

```yaml
event_type: task
title: "Done"
```
MD;

$wf = $compiler->md_to_workflow( $md_linear );
$log( 'T3a compile linear ok', ! is_wp_error( $wf ) && isset( $wf['graph']['nodes'] ),
	is_wp_error( $wf ) ? $wf->get_error_message() : 'no graph.nodes' );

if ( ! is_wp_error( $wf ) ) {
	$nodes = $wf['graph']['nodes'];
	$edges = $wf['graph']['edges'];
	$log( 'T3b 3 nodes', count( $nodes ) === 3, 'got ' . count( $nodes ) );
	$log( 'T3c 2 linear edges', count( $edges ) === 2, 'got ' . count( $edges ) );
	$log( 'T3d blockId trigger', $nodes[0]['data']['blockId'] === 'trigger.manual', $nodes[0]['data']['blockId'] );
	$log( 'T3e prompt block-scalar preserved',
		isset( $nodes[1]['data']['prompt'] ) && strpos( $nodes[1]['data']['prompt'], 'Dòng hai' ) !== false,
		isset( $nodes[1]['data']['prompt'] ) ? var_export( $nodes[1]['data']['prompt'], true ) : 'missing' );
	$log( 'T3f node type inferred', $nodes[0]['type'] === 'trigger' && $nodes[1]['type'] === 'llm' && $nodes[2]['type'] === 'action',
		$nodes[0]['type'] . '/' . $nodes[1]['type'] . '/' . $nodes[2]['type'] );

	// Round-trip: serialise back, re-parse, compare graph.
	$md2 = $compiler->workflow_to_md( $wf );
	$wf2 = $compiler->md_to_workflow( $md2 );
	$log( 'T3g round-trip re-compile ok', ! is_wp_error( $wf2 ),
		is_wp_error( $wf2 ) ? $wf2->get_error_message() : '' );
	if ( ! is_wp_error( $wf2 ) ) {
		$log( 'T3h nodes count stable', count( $wf2['graph']['nodes'] ) === count( $nodes ),
			count( $wf2['graph']['nodes'] ) . ' vs ' . count( $nodes ) );
		$log( 'T3i node[1].data.prompt preserved on re-parse',
			isset( $wf2['graph']['nodes'][1]['data']['prompt'] )
				&& $wf2['graph']['nodes'][1]['data']['prompt'] === $nodes[1]['data']['prompt'],
			isset( $wf2['graph']['nodes'][1]['data']['prompt'] ) ? var_export( $wf2['graph']['nodes'][1]['data']['prompt'], true ) : 'missing' );
		$log( 'T3j slug/name preserved',
			$wf2['slug'] === $wf['slug'] && $wf2['name'] === $wf['name'],
			$wf2['slug'] . '/' . $wf2['name'] );
	}
}

/* ============================================================ */
/* T4 — Branching with ## Edges + Layout                         */
/* ============================================================ */
$md_branch = <<<MD
---
archetype: workflow
name: Demo Branch
slug: tpl_demo_branch_v1
trigger_type: manual
icon: GitBranch
tags: demo,branch
enabled: false
---

## Steps

### 1. `trigger.manual` — Start

```yaml
```

### 2. `logic.condition` — Check

```yaml
field: trigger.text
op: contains
value: "yes"
```

### 3. `action.create_crm_event` — Yes branch

```yaml
event_type: task
title: "Said yes"
```

### 4. `action.create_crm_event` — No branch

```yaml
event_type: task
title: "Said no"
```

## Edges

- n1 -> n2
- n2 -> n3 [via: yes]
- n2 -> n4 [via: no]

## Layout

- n1: x=0 y=80
- n2: x=320 y=80
- n3: x=640 y=0
- n4: x=640 y=160
MD;

$wfb = $compiler->md_to_workflow( $md_branch );
$log( 'T4a compile branch ok', ! is_wp_error( $wfb ), is_wp_error( $wfb ) ? $wfb->get_error_message() : '' );
if ( ! is_wp_error( $wfb ) ) {
	$log( 'T4b 3 edges (branching)', count( $wfb['graph']['edges'] ) === 3,
		'got ' . count( $wfb['graph']['edges'] ) );
	$log( 'T4c sourceHandle preserved on branch edges',
		isset( $wfb['graph']['edges'][1]['sourceHandle'] ) && $wfb['graph']['edges'][1]['sourceHandle'] === 'yes',
		isset( $wfb['graph']['edges'][1]['sourceHandle'] ) ? $wfb['graph']['edges'][1]['sourceHandle'] : 'missing' );
	$log( 'T4d layout x=320 for n2', isset( $wfb['graph']['nodes'][1]['position']['x'] )
		&& (int) $wfb['graph']['nodes'][1]['position']['x'] === 320,
		isset( $wfb['graph']['nodes'][1]['position']['x'] ) ? $wfb['graph']['nodes'][1]['position']['x'] : 'missing' );

	$md2 = $compiler->workflow_to_md( $wfb );
	$wfb2 = $compiler->md_to_workflow( $md2 );
	$log( 'T4e round-trip branch ok', ! is_wp_error( $wfb2 ),
		is_wp_error( $wfb2 ) ? $wfb2->get_error_message() : '' );
	if ( ! is_wp_error( $wfb2 ) ) {
		$log( 'T4f edge handle round-trip',
			isset( $wfb2['graph']['edges'][1]['sourceHandle'] ) && $wfb2['graph']['edges'][1]['sourceHandle'] === 'yes',
			isset( $wfb2['graph']['edges'][1]['sourceHandle'] ) ? $wfb2['graph']['edges'][1]['sourceHandle'] : 'missing' );
		$log( 'T4g layout round-trip x=320',
			(int) $wfb2['graph']['nodes'][1]['position']['x'] === 320,
			$wfb2['graph']['nodes'][1]['position']['x'] );
	}
}

/* ============================================================ */
/* T5 — validate_md sanity                                       */
/* ============================================================ */
$v = $compiler->validate_md( $md_linear );
$log( 'T5a validate_md linear → true', $v === true, is_wp_error( $v ) ? $v->get_error_message() : 'not true' );

$md_no_steps = "---\narchetype: workflow\nname: X\nslug: x\n---\n";
$v2 = $compiler->validate_md( $md_no_steps );
$log( 'T5b validate_md rejects empty steps', is_wp_error( $v2 ) && $v2->get_error_code() === 'no_steps',
	is_wp_error( $v2 ) ? $v2->get_error_code() : 'expected WP_Error' );

echo "\n=== Workflow MD Compiler test : PASS=$pass FAIL=$fail ===\n";
