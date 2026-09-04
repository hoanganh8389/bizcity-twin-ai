<?php
/**
 * Context Bank rollup definition registry.
 *
 * Definitions are declarative and side-effect-free. Workers, checkpoints and
 * durable rollup writes belong to CB5 and are not started by this registry.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Rollup_Registry', false ) ) {
	return;
}

final class BizCity_Context_Bank_Rollup_Registry {

	/**
	 * @var array<string,array<string,mixed>>
	 */
	private static $rollups = array();

	/**
	 * Register one deterministic rollup definition.
	 *
	 * @param string              $rollup_id Rollup identifier.
	 * @param array<string,mixed> $definition Rollup metadata.
	 * @return bool
	 */
	public static function register( $rollup_id, array $definition ) {
		// [2026-09-01 Johnny Chu] CB2.2.4 — register explainable rollup definitions without starting workers or storage writes.
		$rollup_id = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $rollup_id );
		$required = array( 'owner_module', 'input_contracts', 'dimensions', 'window', 'version', 'measures', 'evidence_policy', 'correction_policy', 'rebuild_policy', 'retention_days', 'kg_candidate_policy' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $definition ) ) {
				return false;
			}
		}
		if ( $rollup_id === ''
			|| ! is_string( $definition['owner_module'] )
			|| ! is_array( $definition['input_contracts'] ) || count( $definition['input_contracts'] ) < 1
			|| ! is_array( $definition['dimensions'] ) || count( $definition['dimensions'] ) < 1
			|| ! is_array( $definition['measures'] ) || count( $definition['measures'] ) < 1
			|| ! is_string( $definition['window'] ) || $definition['window'] === ''
			|| ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', (string) $definition['version'] )
			|| ! is_string( $definition['evidence_policy'] ) || $definition['evidence_policy'] === ''
			|| ! is_string( $definition['correction_policy'] ) || $definition['correction_policy'] === ''
			|| ! is_string( $definition['rebuild_policy'] ) || $definition['rebuild_policy'] === ''
			|| (int) $definition['retention_days'] < 1
			|| ! is_string( $definition['kg_candidate_policy'] ) || $definition['kg_candidate_policy'] === '' ) {
			return false;
		}
		$normalized = array(
			'owner_module' => sanitize_key( $definition['owner_module'] ),
			'input_contracts' => array_values( array_map( 'sanitize_key', $definition['input_contracts'] ) ),
			'dimensions' => array_values( array_map( 'sanitize_key', $definition['dimensions'] ) ),
			'window' => sanitize_key( $definition['window'] ),
			'version' => (string) $definition['version'],
			'measures' => array_values( array_map( 'sanitize_key', $definition['measures'] ) ),
			'evidence_policy' => sanitize_key( $definition['evidence_policy'] ),
			'correction_policy' => sanitize_key( $definition['correction_policy'] ),
			'rebuild_policy' => sanitize_key( $definition['rebuild_policy'] ),
			'retention_days' => (int) $definition['retention_days'],
			'kg_candidate_policy' => sanitize_key( $definition['kg_candidate_policy'] ),
			'status' => isset( $definition['status'] ) ? sanitize_key( (string) $definition['status'] ) : 'active',
		);
		if ( ! in_array( $normalized['status'], array( 'active', 'candidate', 'retire_only' ), true ) ) {
			return false;
		}
		if ( isset( self::$rollups[ $rollup_id ] ) ) {
			return self::$rollups[ $rollup_id ] === $normalized;
		}
		self::$rollups[ $rollup_id ] = $normalized;
		return true;
	}

	/** Register the initial rollup families defined by CB5. */
	public static function register_builtins() {
		// [2026-09-01 Johnny Chu] CB2.2.4 — keep initial rollup vocabulary aligned with the roadmap and evidence policies.
		$definitions = array(
			'conversation_state' => array( 'owner_module' => 'core/context-bank', 'input_contracts' => array( 'context-bank-record' ), 'dimensions' => array( 'contact', 'conversation' ), 'window' => '30d', 'version' => '1.0.0', 'measures' => array( 'message_count', 'inbound_count', 'outbound_count', 'last_message_at', 'status' ), 'evidence_policy' => 'bounded_source_refs', 'correction_policy' => 'reopen_window', 'rebuild_policy' => 'from_canonical_events', 'retention_days' => 365, 'kg_candidate_policy' => 'summary_only' ),
			'customer_product_affinity' => array( 'owner_module' => 'core/context-bank', 'input_contracts' => array( 'context-bank-record' ), 'dimensions' => array( 'identity', 'product', 'sku' ), 'window' => '30d', 'version' => '1.0.0', 'measures' => array( 'views_30d', 'clicks_30d', 'messages_30d', 'orders_30d', 'score' ), 'evidence_policy' => 'bounded_source_refs', 'correction_policy' => 'rebuild_affected_window', 'rebuild_policy' => 'from_canonical_events', 'retention_days' => 365, 'kg_candidate_policy' => 'summary_only' ),
			'sku_inventory' => array( 'owner_module' => 'core/context-bank', 'input_contracts' => array( 'context-bank-record' ), 'dimensions' => array( 'sku', 'warehouse' ), 'window' => 'current', 'version' => '1.0.0', 'measures' => array( 'on_hand', 'reserved', 'available', 'inbound', 'stock_status' ), 'evidence_policy' => 'canonical_inventory_refs', 'correction_policy' => 'supersede_state', 'rebuild_policy' => 'from_canonical_inventory_events', 'retention_days' => 365, 'kg_candidate_policy' => 'derived_state_only' ),
			'order_lifecycle' => array( 'owner_module' => 'core/context-bank', 'input_contracts' => array( 'context-bank-record' ), 'dimensions' => array( 'order', 'identity', 'conversation' ), 'window' => 'lifecycle', 'version' => '1.0.0', 'measures' => array( 'payment_state', 'fulfillment_state', 'shipment_state', 'refund_state', 'last_status' ), 'evidence_policy' => 'canonical_order_refs', 'correction_policy' => 'supersede_state', 'rebuild_policy' => 'from_canonical_order_events', 'retention_days' => 365, 'kg_candidate_policy' => 'approved_summary_only' ),
		);
		foreach ( $definitions as $rollup_id => $definition ) {
			if ( ! self::register( $rollup_id, $definition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $rollup_id Rollup identifier.
	 * @return array<string,mixed>|null
	 */
	public static function get( $rollup_id ) {
		return isset( self::$rollups[ $rollup_id ] ) ? self::$rollups[ $rollup_id ] : null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return self::$rollups;
	}
}
