<?php
/** Core Context App: related documents. */
defined( 'ABSPATH' ) || exit;
add_filter( 'bizcity_crm_register_context_apps', static function ( array $apps ): array {
	$apps['documents'] = array(
		'contract' => 'context-app', 'version' => '1.0.0', 'key' => 'documents',
		'label' => 'Tài liệu', 'icon' => 'file', 'entity' => 'Document', 'provider' => '',
		'applies_to' => array( 'subject_roles' => array( 'customer', 'supplier', 'colleague', 'staff' ), 'pipeline_kinds' => array(), 'capability' => 'crm.inbox.read', 'channels' => array( '*' ) ),
		'surfaces' => array( 'b2', 'c' ), 'position' => 40,
		'render' => array( 'type' => 'component', 'id' => 'DocumentsPanel' ), 'rest' => array( '/documents' ),
	);
	return $apps;
} );
