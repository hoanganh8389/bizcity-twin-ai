<?php
/**
 * Context App: shift/ca schedule card (PHASE-0.69 §3/§8). Scoped to the `service` pipeline kind — a
 * sales/purchase/request/production run never has a ca to show here.
 *
 * FE component (`ShiftScheduleCard.jsx`, `inline`/`rail` variants, 0.69 §8) is not implemented in this
 * pass — this manifest registers the backend-truth app so `Context_Resolver` includes it for a `service`
 * run today; a surface without the matching component will simply not render it (registering the app is
 * what makes it appear in the REST payload's `context_apps[]` at all — deferring the FE component does
 * not need a second backend change later).
 */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['shift_schedule'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'shift_schedule',
		'label' => 'Lịch làm việc', 'icon' => 'calendar', 'entity' => 'Appointment', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( '*' ), 'pipeline_kinds' => array( 'service' ), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 25,
		'render' => array( 'type' => 'component', 'id' => 'ShiftScheduleCard' ), 'rest' => array( '/pipeline-runs' ),
	);
	return $apps;
} );
