<?php
/**
 * BizCity KG-Hub — Notebook Workflow Integration (PHASE 0.31 T-S2.4)
 *
 * Surfaces the Knowledge Graph notebook layer ("Twin's second brain") as a
 * first-class WaicChannelIntegration so workflow blocks can read/write notes
 * + artifacts through one settings facade instead of poking
 * `BizCity_KG_Source_Service` / `BizCity_KG_Database` directly from each
 * action.
 *
 * Companion blocks (created in T-S2.4):
 *   - nb_query_kg          (Sprint 1 T-S1.6)  — read passages/answer
 *   - nb_create_note       (Sprint 2 T-S2.4)  — write a note (passage)
 *   - nb_attach_artifact   (Sprint 2 T-S2.4)  — bind an artifact to a notebook
 *
 * @package BizCity\KGHub
 * @since   PHASE 0.31 Sprint 2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHASE 0.31 T-S3.1 — Bridge Brain event → Workflow trigger bus.
 *
 * MUST be registered BEFORE the `WaicChannelIntegration` guard below, because
 * we want the bridge to fire even when this file is loaded in a context where
 * the workflow framework hasn't booted yet (e.g. plain REST requests that
 * call `add_passage()` via cron). Trigger blocks themselves still need the
 * automation runtime to be running before they can match.
 *
 * `bizcity_twin_notebook_event($event_subtype, $payload)` is fired from KG
 * service mutators (currently `BizCity_KG_Source_Service::add_passage()` for
 * `note_created`; `note_updated` + `note_tagged` are forward-compat awaiting
 * Sprint 4 service work).
 */
add_action( 'bizcity_twin_notebook_event', function ( $event_subtype, $payload = array() ) {
	if ( function_exists( 'bizcity_twin_notebook_event_bridge' ) ) {
		// Canonical bridge already provided by channel-gateway bootstrap.
		return bizcity_twin_notebook_event_bridge( $event_subtype, $payload );
	}
	$map = array(
		'note_created' => 'bizcity_twin_note_created',
		'note_updated' => 'bizcity_twin_note_updated',
		'note_tagged'  => 'bizcity_twin_note_tagged',
	);
	$key = isset( $map[ $event_subtype ] ) ? $map[ $event_subtype ] : null;
	if ( ! $key ) { return; }
	do_action( 'waic_twf_process_flow', $key, $payload );
}, 10, 2 );

add_filter( 'bizcity_register_channel_integrations', function ( $list ) {
	$list['notebook'] = array(
		'class' => 'WaicChannelIntegration_notebook',
		'file'  => __FILE__,
	);
	return $list;
} );

if ( ! class_exists( 'WaicChannelIntegration' ) ) {
	return;
}

class WaicChannelIntegration_notebook extends WaicChannelIntegration {

	protected $_code     = 'notebook';
	protected $_logo     = 'NB';
	protected $_order    = 5;
	protected $_platform = 'NOTEBOOK';
	protected $_prefix   = 'nb_';

	public function __construct( $integration = false ) {
		$this->_name = 'Twin Notebook (KG-Hub)';
		$this->_desc = __( 'Read & write notebook passages, attach artifacts — Twin Second Brain bus.', 'ai-copilot-content-generator' );
		$this->setIntegration( $integration );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$notebooks = $this->collect_notebook_options();

		$this->_settings = array(
			'name' => array(
				'type'    => 'input',
				'label'   => __( 'Profile name', 'ai-copilot-content-generator' ),
				'plh'     => __( 'Internal label for this notebook binding', 'ai-copilot-content-generator' ),
				'default' => '',
			),
			'default_notebook_id' => array(
				'type'    => 'select',
				'label'   => __( 'Default notebook', 'ai-copilot-content-generator' ),
				'options' => $notebooks,
				'default' => '',
				'desc'    => __( 'Notebook mặc định cho action nb_*. Có thể override ở từng block.', 'ai-copilot-content-generator' ),
			),
		);
	}

	private function collect_notebook_options() {
		$out = array( '' => __( '— Pick at block level —', 'ai-copilot-content-generator' ) );
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return $out;
		}
		global $wpdb;
		$tbl  = BizCity_KG_Database::instance()->tbl_notebooks();
		$rows = $wpdb->get_results( "SELECT id, title FROM {$tbl} ORDER BY id DESC LIMIT 100" );
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$out[ (string) $r->id ] = sprintf( '#%d — %s', (int) $r->id, $r->title ? $r->title : '(untitled)' );
		}
		return $out;
	}

	public function doTest( $need = false ) {
		$params = $this->getParams();
		if ( ! $need && ! empty( $params['_status'] ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			$this->addParam( '_status', 7 );
			$this->addParam( '_status_error', 'KG-Hub not loaded (BizCity_KG_Database missing)' );
			return false;
		}
		$this->addParam( '_status', 1 );
		$this->addParam( '_status_error', '' );
		return true;
	}

	public function getTriggerBlocks() {
		// Triggers (nb_note_created, nb_note_updated, nb_note_tagged) ship in Sprint 3 T-S3.1.
		return array(
			array( 'code' => 'nb_note_created' ),
			array( 'code' => 'nb_note_updated' ),
			array( 'code' => 'nb_note_tagged' ),
		);
	}

	public function getActionBlocks() {
		return array(
			array( 'code' => 'nb_query_kg' ),
			array( 'code' => 'nb_create_note' ),
			array( 'code' => 'nb_attach_artifact' ),
		);
	}
}
