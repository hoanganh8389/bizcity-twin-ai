<?php
/**
 * Plugin Name: BizCity Reference Extension
 * Description: Minimal reference implementation for the Twin content contracts.
 * Version: 1.0.0
 * Requires PHP: 7.4
 *
 * @package Bizcity_Reference_Extension
 */

// [2026-07-29 Johnny Chu] PHASE-1.21-I — reference implementations for all content contracts.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Tool_Interface' ) ) {
	return;
}

if ( class_exists( 'BizCity_Twin_Capability_Consent' ) && is_readable( __DIR__ . '/manifest.json' ) ) {
	$reference_manifest = json_decode( file_get_contents( __DIR__ . '/manifest.json' ), true );
	if ( is_array( $reference_manifest ) ) {
		BizCity_Twin_Capability_Consent::register_manifest( $reference_manifest );
	}
}

final class BizCity_Reference_Echo_Tool implements BizCity_Tool_Interface {
	public function id() { return 'reference.echo'; }
	public function label() { return 'Echo input'; }
	public function schema() {
		return array(
			'name' => $this->id(),
			'description' => 'Echo a sanitized text value.',
			'parameters' => array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string' ) ) ),
		);
	}
	public function run( array $args, array $context = [] ) {
		return array( 'success' => true, 'result' => array( 'text' => sanitize_text_field( $args['text'] ?? '' ) ) );
	}
}

final class BizCity_Reference_Skill implements BizCity_Skill_Interface {
	public function id() { return 'reference.skill'; }
	public function label() { return 'Reference skill'; }
	public function instructions() { return 'Return a concise response grounded in the provided input.'; }
	public function sub_tools() { return array( 'reference.echo' ); }
	public function meta() { return array( 'version' => '1.0.0' ); }
}

final class BizCity_Reference_Agent implements BizCity_Agent_Interface {
	public function id() { return 'reference.agent'; }
	public function name() { return 'Reference agent'; }
	public function meta() { return array( 'skills' => array( 'reference.skill' ) ); }
	public function run( $input, array $context = [] ) {
		return array( 'success' => true, 'reply' => (string) $input );
	}
}

final class BizCity_Reference_Channel implements BizCity_Channel_Adapter_Interface {
	public function id() { return 'reference.channel'; }
	public function platform() { return 'reference'; }
	public function zone() { return 'admin'; }
	public function normalize_inbound( array $payload ) {
		return array( 'platform' => $this->platform(), 'text' => sanitize_text_field( $payload['text'] ?? '' ), 'raw' => $payload );
	}
	public function send( array $message, array $context = [] ) { return array( 'success' => true, 'message' => $message ); }
	public function meta() { return array( 'zone' => $this->zone() ); }
}

final class BizCity_Reference_Source_Adapter implements BizCity_KG_Source_Adapter_Interface {
	public function id() { return 'reference.source'; }
	public function source_type() { return 'reference_text'; }
	public function supports( array $source ) { return isset( $source['text'] ); }
	public function fetch( array $source, array $context = [] ) { return array( 'text' => (string) ( $source['text'] ?? '' ) ); }
	public function to_passages( array $payload, array $context = [] ) { return array( array( 'text' => (string) ( $payload['text'] ?? '' ), 'source_type' => $this->source_type() ) ); }
	public function meta() { return array( 'scope' => 'tenant' ); }
}

final class BizCity_Reference_Workflow_Block implements BizCity_Workflow_Block_Interface {
	public function node_id() { return 'reference.echo'; }
	public function label() { return 'Reference workflow block'; }
	public function input_schema() { return array( 'type' => 'object', 'required' => array( 'text' ) ); }
	public function output_schema() { return array( 'type' => 'object', 'properties' => array( 'text' => array( 'type' => 'string' ) ) ); }
	public function execute( array $input, array $context = [] ) { return array( 'success' => true, 'text' => (string) ( $input['text'] ?? '' ) ); }
	public function side_effects() { return array(); }
	public function meta() { return array( 'supports_retry' => true, 'supports_idempotency' => true ); }
}

final class BizCity_Reference_Persona implements BizCity_Persona_Provider_Interface {
	public function id() { return 'reference.persona'; }
	public function label() { return 'Reference persona'; }
	public function profile() { return array( 'tone' => 'clear', 'language' => 'vi' ); }
	public function system_instructions( array $context = [] ) { return 'Be concise, factual, and transparent about uncertainty.'; }
	public function meta() { return array( 'scope' => 'extension' ); }
}

final class BizCity_Reference_Text_Renderer implements BizCity_Output_Renderer_Interface {
	public function id() { return 'reference.text'; }
	public function artifact_type() { return 'text'; }
	public function supports( array $output ) { return isset( $output['text'] ); }
	public function render( array $output, array $context = [] ) { return esc_html( (string) $output['text'] ); }
	public function meta() { return array( 'editable' => false ); }
}

// [2026-07-30 Johnny Chu] PHASE-1.22-SDK — register typed sample providers at runtime.
add_filter( 'bizcity_twin_register_extension_capabilities', function ( $groups ) {
	if ( ! is_array( $groups ) ) {
		$groups = array();
	}
	$groups['tools'][]              = new BizCity_Reference_Echo_Tool();
	$groups['skills'][]             = new BizCity_Reference_Skill();
	$groups['agents'][]             = new BizCity_Reference_Agent();
	$groups['channels'][]           = new BizCity_Reference_Channel();
	$groups['kg_source_adapters'][] = new BizCity_Reference_Source_Adapter();
	$groups['workflow_blocks'][]    = new BizCity_Reference_Workflow_Block();
	$groups['personas'][]           = new BizCity_Reference_Persona();
	$groups['output_renderers'][]   = new BizCity_Reference_Text_Renderer();
	return $groups;
} );
