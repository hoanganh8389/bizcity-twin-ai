<?php
/**
 * Context Bank identity dimension registry.
 *
 * The registry stores resolver identifiers, not request-provided callables.
 * Actual identity resolution remains owned by Channel, CRM, TwinWeb and
 * canonical source adapters.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Identity_Registry', false ) ) {
	return;
}

final class BizCity_Context_Bank_Identity_Registry {

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private static $dimensions = array();

	/**
	 * Register one identity dimension using an allowlisted resolver identifier.
	 *
	 * @param string              $dimension_id Dimension identifier.
	 * @param array<string,mixed> $definition Dimension metadata.
	 * @return bool
	 */
	public static function register( $dimension_id, array $definition ) {
		// [2026-09-01 Johnny Chu] CB2.2.2 — register identity dimensions without accepting arbitrary resolver callables.
		$dimension_id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $dimension_id );
		$required = array( 'owner_module', 'key_format', 'sensitivity', 'resolver_id', 'scope' );
		foreach ( $required as $field ) {
			if ( ! isset( $definition[ $field ] ) || ! is_scalar( $definition[ $field ] ) || (string) $definition[ $field ] === '' ) {
				return false;
			}
		}
		$allowed_resolvers = array( 'channel_account', 'crm_contact', 'crm_conversation', 'twin_user', 'canonical_entity' );
		$allowed_scopes = array( 'tenant', 'account', 'identity', 'entity' );
		if ( $dimension_id === ''
			|| ! preg_match( '/^[A-Za-z0-9_.-]+$/', (string) $definition['key_format'] )
			|| ! in_array( (string) $definition['sensitivity'], array( 'low', 'medium', 'high', 'critical' ), true )
			|| ! in_array( (string) $definition['resolver_id'], $allowed_resolvers, true )
			|| ! in_array( (string) $definition['scope'], $allowed_scopes, true ) ) {
			return false;
		}
		$normalized = array(
			'owner_module' => sanitize_key( (string) $definition['owner_module'] ),
			'key_format' => (string) $definition['key_format'],
			'sensitivity' => (string) $definition['sensitivity'],
			'resolver_id' => (string) $definition['resolver_id'],
			'scope' => (string) $definition['scope'],
			'status' => isset( $definition['status'] ) ? sanitize_key( (string) $definition['status'] ) : 'active',
		);
		if ( ! in_array( $normalized['status'], array( 'active', 'candidate', 'retire_only' ), true ) ) {
			return false;
		}
		if ( isset( self::$dimensions[ $dimension_id ] ) ) {
			return self::$dimensions[ $dimension_id ] === $normalized;
		}
		self::$dimensions[ $dimension_id ] = $normalized;
		return true;
	}

	/** Register the initial canonical identity dimensions. */
	public static function register_builtins() {
		// [2026-09-01 Johnny Chu] CB2.2.2 — bind identity metadata to existing canonical owners; no identity data is read here.
		$definitions = array(
			'channel_account' => array( 'owner_module' => 'core/channel-gateway', 'key_format' => 'channel.account_id', 'sensitivity' => 'high', 'resolver_id' => 'channel_account', 'scope' => 'account' ),
			'crm_contact' => array( 'owner_module' => 'plugins/bizcity-twin-crm', 'key_format' => 'blog.contact_id', 'sensitivity' => 'critical', 'resolver_id' => 'crm_contact', 'scope' => 'identity' ),
			'crm_conversation' => array( 'owner_module' => 'plugins/bizcity-twin-crm', 'key_format' => 'blog.conversation_id', 'sensitivity' => 'high', 'resolver_id' => 'crm_conversation', 'scope' => 'identity' ),
			'twin_user' => array( 'owner_module' => 'core/twinweb', 'key_format' => 'blog.wp_user_id', 'sensitivity' => 'critical', 'resolver_id' => 'twin_user', 'scope' => 'identity' ),
			'canonical_entity' => array( 'owner_module' => 'core/context-bank', 'key_format' => 'entity_type.entity_key', 'sensitivity' => 'high', 'resolver_id' => 'canonical_entity', 'scope' => 'entity' ),
		);
		foreach ( $definitions as $dimension_id => $definition ) {
			if ( ! self::register( $dimension_id, $definition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $dimension_id Dimension identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get( $dimension_id ) {
		return isset( self::$dimensions[ $dimension_id ] ) ? self::$dimensions[ $dimension_id ] : null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return self::$dimensions;
	}
}
