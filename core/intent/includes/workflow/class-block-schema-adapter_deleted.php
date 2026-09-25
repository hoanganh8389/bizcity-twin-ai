<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Block Schema Adapter
 *
 * Auto-derives pipeline schema from native workflow block setSettings().
 * Allows blocks to opt-in with explicit getPipelineSchema() override.
 *
 * Phase 1.1 v1.3 — Executor Middleware architecture.
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Block_Schema_Adapter {

	/**
	 * Cache resolved schemas to avoid repeated instantiation.
	 *
	 * @var array<string, array>
	 */
	private static $cache = [];

	/**
	 * Resolve pipeline schema for a block.
	 * Priority: getPipelineSchema() > heuristic from setSettings().
	 *
	 * Returns array of injectable fields:
	 *   [ 'field_name' => [ 'name' => '', 'label' => '', 'type' => '', 'required' => bool ] ]
	 *
	 * @param string               $block_code     Block code (e.g. 'ai_generate_content').
	 * @param WaicBuilderBlock|null $block_instance Optional pre-existing block instance.
	 * @return array Pipeline schema fields.
	 */
	public static function resolve( $block_code, $block_instance = null ) {
		if ( isset( self::$cache[ $block_code ] ) ) {
			return self::$cache[ $block_code ];
		}

		// Priority 1: Block explicitly declares pipeline schema
		if ( $block_instance && method_exists( $block_instance, 'getPipelineSchema' ) ) {
			$schema = $block_instance->getPipelineSchema();
			self::$cache[ $block_code ] = $schema;
			return $schema;
		}

		// Priority 2: Auto-derive from setSettings()
		if ( ! $block_instance ) {
			$className = 'WaicAction_' . $block_code;
			if ( ! class_exists( $className ) ) {
				self::$cache[ $block_code ] = [];
				return [];
			}
			try {
				$block_instance = new $className();
			} catch ( \Throwable $e ) {
				self::$cache[ $block_code ] = [];
				return [];
			}
		}

		if ( ! method_exists( $block_instance, 'getSettings' ) ) {
			self::$cache[ $block_code ] = [];
			return [];
		}

		$settings = $block_instance->getSettings();
		if ( ! is_array( $settings ) ) {
			self::$cache[ $block_code ] = [];
			return [];
		}

		$schema = [];
		foreach ( $settings as $key => $cfg ) {
			// Only fields with `variables: true` are pipeline-injectable
			if ( empty( $cfg['variables'] ) ) {
				continue;
			}

			$schema[ $key ] = [
				'name'     => $key,
				'label'    => $cfg['label'] ?? $key,
				'type'     => $cfg['type']  ?? 'input',
				'required' => self::guess_required( $key, $cfg ),
			];
		}

		self::$cache[ $block_code ] = $schema;
		return $schema;
	}

	/**
	 * Heuristic: is this field required for pipeline execution?
	 *
	 * Conservative: defaults to false. Scenario Generator plan or
	 * block's getPipelineSchema() can override.
	 *
	 * @param string $key Field key.
	 * @param array  $cfg Field config from setSettings().
	 * @return bool
	 */
	private static function guess_required( $key, array $cfg ) {
		// Explicit flag from block (future-compatible)
		if ( isset( $cfg['required'] ) ) {
			return (bool) $cfg['required'];
		}

		// System fields always required
		if ( in_array( $key, [ 'chat_id' ], true ) ) {
			return true;
		}

		// Has non-empty default → optional
		if ( isset( $cfg['default'] ) && $cfg['default'] !== '' ) {
			return false;
		}

		// Conservative: return false and let Scenario Generator plan specify
		return false;
	}

	/**
	 * Check which required fields are still empty given resolved variables.
	 *
	 * @param array $schema   Pipeline schema from resolve().
	 * @param array $resolved Key-value map of resolved field values.
	 * @return array List of missing required field schemas.
	 */
	public static function check_missing( array $schema, array $resolved ) {
		$missing = [];
		foreach ( $schema as $key => $field ) {
			if ( ! empty( $field['required'] ) ) {
				$val = $resolved[ $key ] ?? '';
				if ( $val === '' || $val === null ) {
					$missing[] = $field;
				}
			}
		}
		return $missing;
	}

	/**
	 * Clear cache (for testing or dynamic reload).
	 */
	public static function clear_cache() {
		self::$cache = [];
	}
}
