<?php
/** Core Context App: team operations. */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['ops'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'ops',
		'label' => 'Điều phối', 'icon' => 'users', 'entity' => 'Work', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( 'customer', 'supplier', 'colleague', 'staff' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 20,
		'render' => array( 'type' => 'component', 'id' => 'OperationsPanel' ), 'rest' => array( '/team' ),
	);
	return $apps;
} );
