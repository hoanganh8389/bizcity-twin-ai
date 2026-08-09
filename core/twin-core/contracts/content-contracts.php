<?php
/**
 * BizCity Twin AI content-level extension contracts.
 *
 * These interfaces are opt-in. Existing array-based providers remain valid;
 * new extensions can implement these contracts for IDE and validator support.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Contracts
 * @since 1.1.0
 */

// [2026-07-29 Johnny Chu] PHASE-1.21-F — content-type extension contracts.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Skill_Interface' ) ) {
	interface BizCity_Skill_Interface {
		public function id();
		public function label();
		public function instructions();
		public function sub_tools();
		public function meta();
	}
}

if ( ! interface_exists( 'BizCity_Channel_Adapter_Interface' ) ) {
	interface BizCity_Channel_Adapter_Interface {
		public function id();
		public function platform();
		public function zone();
		public function normalize_inbound( array $payload );
		public function send( array $message, array $context = [] );
		public function meta();
	}
}

if ( ! interface_exists( 'BizCity_KG_Source_Adapter_Interface' ) ) {
	interface BizCity_KG_Source_Adapter_Interface {
		public function id();
		public function source_type();
		public function supports( array $source );
		public function fetch( array $source, array $context = [] );
		public function to_passages( array $payload, array $context = [] );
		public function meta();
	}
}

if ( ! interface_exists( 'BizCity_Workflow_Block_Interface' ) ) {
	interface BizCity_Workflow_Block_Interface {
		public function node_id();
		public function label();
		public function input_schema();
		public function output_schema();
		public function execute( array $input, array $context = [] );
		public function side_effects();
		public function meta();
	}
}

if ( ! interface_exists( 'BizCity_Persona_Provider_Interface' ) ) {
	interface BizCity_Persona_Provider_Interface {
		public function id();
		public function label();
		public function profile();
		public function system_instructions( array $context = [] );
		public function meta();
	}
}

if ( ! interface_exists( 'BizCity_Output_Renderer_Interface' ) ) {
	interface BizCity_Output_Renderer_Interface {
		public function id();
		public function artifact_type();
		public function supports( array $output );
		public function render( array $output, array $context = [] );
		public function meta();
	}
}
