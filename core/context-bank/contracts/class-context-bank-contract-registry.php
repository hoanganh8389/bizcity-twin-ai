<?php
/**
 * Context Bank contract registry.
 *
 * CB2.2.1 keeps the catalog in memory and has no storage side effect. Runtime
 * adapters and persistence are registered by later Context Bank slices.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Contract_Registry', false ) ) {
	return;
}

final class BizCity_Context_Bank_Contract_Registry {

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private static $contracts = array();

	/**
	 * Register one deterministic Context Bank contract definition.
	 *
	 * @param string              $contract_id Contract identifier.
	 * @param array<string,mixed> $definition Contract metadata.
	 * @return bool
	 */
	public static function register( $contract_id, array $definition ) {
		// [2026-09-01 Johnny Chu] CB2.2.1 — register immutable contract ownership without storage side effects.
		$contract_id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $contract_id );
		$required = array( 'owner_module', 'schema_ref', 'schema_version', 'sensitivity', 'retention_days', 'adapter', 'candidate_policy' );
		foreach ( $required as $field ) {
			if ( ! isset( $definition[ $field ] ) || ! is_scalar( $definition[ $field ] ) || (string) $definition[ $field ] === '' ) {
				return false;
			}
		}
		if ( $contract_id === ''
			|| ! preg_match( '/^[A-Za-z0-9_.-]+\.schema\.json$/', (string) $definition['schema_ref'] )
			|| ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', (string) $definition['schema_version'] )
			|| ! in_array( (string) $definition['sensitivity'], array( 'low', 'medium', 'high', 'critical' ), true )
			|| (int) $definition['retention_days'] < 1 ) {
			return false;
		}
		$normalized = array(
			'owner_module'    => sanitize_key( (string) $definition['owner_module'] ),
			'schema_ref'      => (string) $definition['schema_ref'],
			'schema_version'  => (string) $definition['schema_version'],
			'sensitivity'     => (string) $definition['sensitivity'],
			'retention_days'  => (int) $definition['retention_days'],
			'adapter'         => sanitize_key( (string) $definition['adapter'] ),
			'candidate_policy' => sanitize_key( (string) $definition['candidate_policy'] ),
			'status'          => isset( $definition['status'] ) ? sanitize_key( (string) $definition['status'] ) : 'active',
		);
		if ( ! in_array( $normalized['status'], array( 'active', 'candidate', 'retire_only' ), true ) ) {
			return false;
		}
		if ( isset( self::$contracts[ $contract_id ] ) ) {
			return self::$contracts[ $contract_id ] === $normalized;
		}
		foreach ( self::$contracts as $existing ) {
			if ( $existing['schema_ref'] === $normalized['schema_ref']
				&& $existing['schema_version'] === $normalized['schema_version'] ) {
				return false;
			}
		}
		self::$contracts[ $contract_id ] = $normalized;
		return true;
	}

	/** Register the four public Context Bank contract catalog entries. */
	public static function register_builtins() {
		// [2026-09-01 Johnny Chu] CB2.2.1 — bind runtime metadata to cataloged public schemas before adapters exist.
		$definitions = array(
			'context-bank-record' => array( 'owner_module' => 'core/context-bank', 'schema_ref' => 'context-bank-record.schema.json', 'schema_version' => '1.0.0', 'sensitivity' => 'critical', 'retention_days' => 365, 'adapter' => 'context_bank_writer', 'candidate_policy' => 'approved_summary_only' ),
			'context-rollup-definition' => array( 'owner_module' => 'core/context-bank', 'schema_ref' => 'context-rollup-definition.schema.json', 'schema_version' => '1.0.0', 'sensitivity' => 'high', 'retention_days' => 365, 'adapter' => 'rollup_registry', 'candidate_policy' => 'derived_state_only' ),
			'context-relation' => array( 'owner_module' => 'core/context-bank', 'schema_ref' => 'context-relation.schema.json', 'schema_version' => '1.0.0', 'sensitivity' => 'high', 'retention_days' => 365, 'adapter' => 'relation_registry', 'candidate_policy' => 'kg_policy_required' ),
			'context-retrieval-pack' => array( 'owner_module' => 'core/context-bank', 'schema_ref' => 'context-retrieval-pack.schema.json', 'schema_version' => '1.0.0', 'sensitivity' => 'high', 'retention_days' => 31, 'adapter' => 'retrieval_service', 'candidate_policy' => 'server_authorized_only' ),
		);
		foreach ( $definitions as $contract_id => $definition ) {
			if ( ! self::register( $contract_id, $definition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $contract_id Contract identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get( $contract_id ) {
		return isset( self::$contracts[ $contract_id ] ) ? self::$contracts[ $contract_id ] : null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return self::$contracts;
	}

	/**
	 * @param string $contract_id Contract identifier.
	 * @return bool
	 */
	public static function has( $contract_id ) {
		return isset( self::$contracts[ $contract_id ] );
	}
}
