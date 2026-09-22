<?php
/** Core Context App: pipeline stage rail. */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['stage'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'stage',
		'label' => 'Dải bước', 'icon' => 'kanban', 'entity' => 'Flow', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( '*' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 10,
		'render' => array( 'type' => 'component', 'id' => 'StagePanel' ), 'rest' => array( '/pipeline-runs' ),
	);
	return $apps;
} );
