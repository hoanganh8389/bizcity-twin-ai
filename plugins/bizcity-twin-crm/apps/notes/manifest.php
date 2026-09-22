<?php
/** Core Context App: CRM notes. */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['notes'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'notes',
		'label' => 'Ghi chú', 'icon' => 'note', 'entity' => 'Conversation', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( 'customer', 'supplier', 'colleague', 'staff' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 30,
		'render' => array( 'type' => 'component', 'id' => 'NotesPanel' ), 'rest' => array( '/notes' ),
	);
	return $apps;
} );
