<?php
/** Core Context App: activity history. */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['activity'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'activity',
		'label' => 'Hoạt động', 'icon' => 'activity', 'entity' => 'Context', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( '*' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 50,
		'render' => array( 'type' => 'component', 'id' => 'ActivityPanel' ), 'rest' => array( '/activity' ),
	);
	return $apps;
} );
