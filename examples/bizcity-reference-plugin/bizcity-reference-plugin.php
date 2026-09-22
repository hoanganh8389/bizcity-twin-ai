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

/**
 * Register the reference Control Panel entry.
 *
 * [2026-09-14 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — golden fixture for extension
 * authors: metadata-only registration through the SDK, retried across load order, never inside an
 * is_admin() guard so CLI/cron/diagnostics see the same registry.
 *
 * @return bool
 */
function bizcity_reference_register_setting_panel() {
	if ( ! class_exists( 'BizCity_Twin_Plugin_SDK' ) || ! class_exists( 'BizCity_Setting_Panel_Registry' ) ) {
		return false;
	}
	return BizCity_Twin_Plugin_SDK::register_ui(
		array(
			'setting_panel' => array(
				array(
					'contract'        => 'setting-panel-registration',
					'version'         => '1.0.0',
					'id'              => 'extension.reference.settings',
					'owner'           => 'examples/bizcity-reference-plugin',
					'origin'          => 'extension',
					'destination'     => 'control-panel',
					'group'           => 'extensions',
					'label_key'       => 'reference.settings.label',
					'description_key' => 'reference.settings.description',
					'icon'            => 'cil-puzzle',
					'capability'      => 'manage_options',
					'scope'           => 'site',
					'surface'         => 'admin_page',
					'renderer'        => array(
						'type'           => 'deep_link',
						'id'             => 'extension.reference.settings',
						'canonical_slug' => 'bizcity-reference',
					),
					'availability'    => array(
						'policy'         => 'registered-owner',
						'dependency_ids' => array( 'bizcity.reference' ),
					),
					'position'        => 900,
				),
			),
		)
	);
}

if ( ! bizcity_reference_register_setting_panel() && function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'bizcity_reference_register_setting_panel', 1 );
	add_action( 'init', 'bizcity_reference_register_setting_panel', 1 );
}

if ( ! interface_exists( 'BizCity_Tool_Interface' ) ) {
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

	public function ingest_to_kg( $notebook_id, $user_id = 0, $trace_id = '' ) {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-KG — route reference source data through the central KG facade.
		if ( ! class_exists( 'BizCity_KG' ) ) {
			return new WP_Error( 'reference_kg_unavailable', 'Central KG facade is unavailable.' );
		}
		$source = array(
			'text' => 'Reference extension fixture: the customer insight source is indexed in the Twin KG Hub for citation and analysis.',
			'type' => $this->source_type(),
		);
		$fetched = $this->fetch( $source, array( 'trace_id' => $trace_id, 'user_id' => (int) $user_id ) );
		$passages = $this->to_passages( $fetched, array( 'trace_id' => $trace_id ) );
		$content = isset( $passages[0]['text'] ) ? trim( (string) $passages[0]['text'] ) : '';
		return BizCity_KG::ingest_extension_source(
			array(
				'plugin'      => 'bizcity.reference',
				'notebook_id' => (int) $notebook_id,
				'user_id'     => (int) $user_id,
			),
			array(
				'type'     => 'reference_source',
				'title'    => 'Reference customer insight source',
				'content'  => $content,
				'metadata' => array(
					'adapter_id' => $this->id(),
					'source_type'=> $this->source_type(),
					'trace_id'   => (string) $trace_id,
				),
			)
		);
	}
}

final class BizCity_Reference_Wave5_Evidence {
	const LOG_CONTRACT = 'plugins.bizcity_reference.wave5';

	public static function register_contract() {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-LOG — keep the fixture contract available regardless of extension load order.
		return class_exists( 'BizCity_Log_Contract_Registry' ) && BizCity_Log_Contract_Registry::register( self::LOG_CONTRACT, array(
			'owner_module'       => 'examples/bizcity-reference-plugin',
			'label'              => 'Reference extension Wave 5 evidence',
			'jsonl_folder'       => 'bizcity-reference-logs',
			'jsonl_module'       => 'wave5',
			'retention_days'     => 7,
			'indexed'            => true,
			'related_sql_tables' => array(),
		) );
	}

	public static function write_log( $trace_id, array $context = array() ) {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-LOG — write Wave 5 evidence through the canonical JSONL contract.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return false;
		}
		$context['trace_id'] = (string) $trace_id;
		$context['ref_id'] = (string) $trace_id;
		return BizCity_JSONL_File_Logger::write_contract( self::LOG_CONTRACT, 'info', 'wave5_ingest_attempt', 'Reference Wave 5 ingest evidence.', $context );
	}

	public static function indexed_rows( $trace_id ) {
		// [2026-08-29 Johnny Chu] PHASE-VIBE-LOG — read the indexed pointer ledger by immutable contract and trace reference.
		if ( ! class_exists( 'BizCity_Log_Index' ) ) {
			return array();
		}
		return BizCity_Log_Index::search( array( 'contract_id' => self::LOG_CONTRACT, 'ref_id' => (string) $trace_id, 'limit' => 10 ) );
	}
}

if ( class_exists( 'BizCity_Reference_Wave5_Evidence' ) ) {
	BizCity_Reference_Wave5_Evidence::register_contract();
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

// [2026-08-29 Johnny Chu] PHASE-VIBE-SDK — exercise typed Tool registration through the runtime registry bridge.
add_filter( 'bizcity_twin_register_tool', function ( $registry ) {
	if ( ! is_array( $registry ) ) {
		$registry = array();
	}
	$registry['reference.echo'] = new BizCity_Reference_Echo_Tool();
	return $registry;
}, 20 );
