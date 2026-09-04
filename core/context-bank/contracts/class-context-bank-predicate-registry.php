<?php
/**
 * Context Bank correlation predicate registry.
 *
 * These predicates describe bounded Context Bank correlations. They are not
 * KG semantic predicates; KG-Hub owns semantic extraction and relation build.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Predicate_Registry', false ) ) {
	return;
}

final class BizCity_Context_Bank_Predicate_Registry {

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private static $predicates = array();

	/**
	 * Register one bounded correlation predicate.
	 *
	 * @param string              $predicate_id Predicate identifier.
	 * @param array<string,mixed> $definition Predicate metadata.
	 * @return bool
	 */
	public static function register( $predicate_id, array $definition ) {
		// [2026-09-01 Johnny Chu] CB2.2.3 — register typed Context correlations separately from KG semantic relations.
		$predicate_id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $predicate_id );
		$required = array( 'owner_module', 'subject_type', 'object_type', 'direction', 'status' );
		foreach ( $required as $field ) {
			if ( ! isset( $definition[ $field ] ) || ! is_scalar( $definition[ $field ] ) || (string) $definition[ $field ] === '' ) {
				return false;
			}
		}
		$allowed_types = array( 'identity', 'contact', 'conversation', 'account', 'user', 'company', 'order', 'product', 'sku', 'warehouse', 'event', 'rollup', 'memory', 'rule', 'source' );
		$allowed_directions = array( 'forward', 'reverse', 'symmetric' );
		$allowed_statuses = array( 'active', 'candidate', 'retire_only' );
		if ( $predicate_id === ''
			|| ! in_array( (string) $definition['subject_type'], $allowed_types, true )
			|| ! in_array( (string) $definition['object_type'], $allowed_types, true )
			|| ! in_array( (string) $definition['direction'], $allowed_directions, true )
			|| ! in_array( (string) $definition['status'], $allowed_statuses, true ) ) {
			return false;
		}
		$normalized = array(
			'owner_module' => sanitize_key( (string) $definition['owner_module'] ),
			'subject_type' => (string) $definition['subject_type'],
			'object_type' => (string) $definition['object_type'],
			'direction' => (string) $definition['direction'],
			'status' => (string) $definition['status'],
		);
		if ( isset( self::$predicates[ $predicate_id ] ) ) {
			return self::$predicates[ $predicate_id ] === $normalized;
		}
		self::$predicates[ $predicate_id ] = $normalized;
		return true;
	}

	/** Register the initial non-semantic correlation predicates. */
	public static function register_builtins() {
		// [2026-09-01 Johnny Chu] CB2.2.3 — keep Context Bank correlation vocabulary deterministic and side-effect-free.
		$definitions = array(
			'belongs_to' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'event', 'object_type' => 'identity', 'direction' => 'forward', 'status' => 'active' ),
			'has_conversation' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'identity', 'object_type' => 'conversation', 'direction' => 'forward', 'status' => 'active' ),
			'concerns_account' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'event', 'object_type' => 'account', 'direction' => 'forward', 'status' => 'active' ),
			'relates_product' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'event', 'object_type' => 'product', 'direction' => 'forward', 'status' => 'active' ),
			'uses_sku' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'product', 'object_type' => 'sku', 'direction' => 'forward', 'status' => 'active' ),
			'located_at' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'sku', 'object_type' => 'warehouse', 'direction' => 'forward', 'status' => 'active' ),
			'derived_from' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'rollup', 'object_type' => 'source', 'direction' => 'forward', 'status' => 'active' ),
			'supersedes' => array( 'owner_module' => 'core/context-bank', 'subject_type' => 'event', 'object_type' => 'event', 'direction' => 'forward', 'status' => 'active' ),
		);
		foreach ( $definitions as $predicate_id => $definition ) {
			if ( ! self::register( $predicate_id, $definition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $predicate_id Predicate identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get( $predicate_id ) {
		return isset( self::$predicates[ $predicate_id ] ) ? self::$predicates[ $predicate_id ] : null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return self::$predicates;
	}
}
