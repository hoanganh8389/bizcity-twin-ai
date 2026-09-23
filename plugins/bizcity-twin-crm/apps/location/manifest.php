<?php
/**
 * Context App: staff/customer location card (PHASE-0.69 §3/§8/§4). Scoped to the `service` pipeline kind.
 * Backs onto `BizCity_CRM_Location_Service` + `POST /crm-staff/{id}/request-location`.
 *
 * FE component (`LocationTool.jsx`, 0.69 §8) is not implemented in this pass — see the note in
 * `apps/shift_schedule/manifest.php`, same reasoning.
 */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['location'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'location',
		'label' => 'Vị trí', 'icon' => 'map-pin', 'entity' => 'Appointment', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( '*' ), 'pipeline_kinds' => array( 'service' ), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 26,
		'render' => array( 'type' => 'component', 'id' => 'LocationTool' ), 'rest' => array( '/crm-staff', '/service/match' ),
	);
	return $apps;
} );
