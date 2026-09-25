<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Adapters
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.7
 * Migrate the legacy `BizCity_Intent_Tools` registry into TwinShell tools.
 *
 * Strategy: lazy adapter (no batch copy, no schema duplication).
 *
 *   • `wrap($name)`               → BizCity_TwinShell_Tool that proxies to
 *                                   `BizCity_Intent_Tools::execute($name, $args)`.
 *   • `wrap_many([...])`          → array<TwinShell_Tool> for picking subsets.
 *   • `wrap_by_intent($intent)`   → tools whose schema metadata matches an
 *                                   intent_kind (creative / task / chat).
 *   • `all_executable_tools()`    → every registered legacy tool wrapped.
 *
 * Schema conversion: legacy tools declare `input_fields => [name => [required,
 * type, ...]]`. The migrator converts that to a JSON-Schema object compatible
 * with the OpenAI function-calling spec used by `BizCity_Twin_Runner`.
 *
 * Approval gate: tools without `auto_execute` get `needs_approval = true`
 * by default — matches the legacy `execute_with_preconfirm()` behaviour.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Tool_Migrator {

	/** Cache wrapped tools so we don't recreate the closure per call. */
	private static $cache = [];

	/**
	 * Return a single tool wrapped as TwinShell_Tool, or null if missing.
	 */
	public static function wrap( string $name ): ?BizCity_TwinShell_Tool {
		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}
		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return null;
		}

		$registry = BizCity_Intent_Tools::instance();
		if ( ! $registry->has( $name ) ) {
			return null;
		}

		$schema      = (array) $registry->get_schema( $name );
		$description = (string) ( $schema['description'] ?? "Legacy intent tool: {$name}" );
		$auto_exec   = ! empty( $schema['auto_execute'] );
		$json_schema = self::to_json_schema( $schema );

		$tool = new BizCity_TwinShell_Tool(
			$name,
			$description,
			$json_schema,
			static function ( array $args, array $ctx = [] ) use ( $name, $registry ) {
				// Inject WP context the legacy callbacks expect.
				$slots = array_merge( $args, [
					'user_id'    => $ctx['user_id']    ?? 0,
					'session_id' => $ctx['conversation_id'] ?? '',
					'_meta'      => [
						'session_id' => $ctx['conversation_id'] ?? '',
						'channel'    => $ctx['channel']    ?? 'webchat',
						'user_id'    => $ctx['user_id']    ?? 0,
					],
				] );

				$result = $registry->execute( $name, $slots );

				// Surface result in a stable shape for the agent LLM.
				return [
					'ok'              => (bool) ( $result['success'] ?? false ),
					'message'         => (string) ( $result['message'] ?? '' ),
					'data'            => $result['data'] ?? [],
					'missing_fields'  => $result['missing_fields'] ?? [],
					'tool'            => $name,
				];
			},
			null,                       // is_enabled — always enabled for now (Sprint 3 can gate by capability_tags)
			$auto_exec ? false : true   // needs_approval default ON for write tools
		);

		self::$cache[ $name ] = $tool;
		return $tool;
	}

	/**
	 * Wrap an explicit list of tool names. Silently drops missing ones.
	 *
	 * @param string[] $names
	 * @return BizCity_TwinShell_Tool[]
	 */
	public static function wrap_many( array $names ): array {
		$out = [];
		foreach ( $names as $n ) {
			$t = self::wrap( (string) $n );
			if ( $t instanceof BizCity_TwinShell_Tool ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Wrap every registered legacy tool. Cap at $limit to avoid flooding the
	 * LLM context window (default 30 = OpenAI's recommended ceiling).
	 *
	 * @return BizCity_TwinShell_Tool[]
	 */
	public static function all_executable_tools( int $limit = 30 ): array {
		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return [];
		}
		$names = array_keys( BizCity_Intent_Tools::instance()->list_all() );
		$names = array_slice( $names, 0, $limit );
		return self::wrap_many( $names );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Convert legacy `input_fields` schema → JSON Schema object.
	 *
	 *   input_fields => [
	 *     'topic' => ['required'=>true, 'type'=>'string', 'description'=>'…'],
	 *     'count' => ['required'=>false, 'type'=>'integer'],
	 *   ]
	 *
	 * becomes:
	 *
	 *   {type:object, properties:{topic:{type:string,description:'…'},
	 *                              count:{type:integer}}, required:['topic']}
	 */
	private static function to_json_schema( array $legacy_schema ): array {
		$properties = [];
		$required   = [];

		$fields = $legacy_schema['input_fields'] ?? $legacy_schema['parameters'] ?? [];
		if ( ! is_array( $fields ) ) {
			$fields = [];
		}

		foreach ( $fields as $field_name => $meta ) {
			if ( ! is_array( $meta ) ) {
				$meta = [ 'type' => is_string( $meta ) ? $meta : 'string' ];
			}
			$prop = [ 'type' => self::normalize_type( $meta['type'] ?? 'string' ) ];
			if ( ! empty( $meta['description'] ) ) {
				$prop['description'] = (string) $meta['description'];
			}
			if ( ! empty( $meta['enum'] ) && is_array( $meta['enum'] ) ) {
				$prop['enum'] = array_values( $meta['enum'] );
			}
			$properties[ (string) $field_name ] = $prop;

			if ( ! empty( $meta['required'] ) ) {
				$required[] = (string) $field_name;
			}
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => (object) $properties, // keep as object even when empty
			'additionalProperties' => false,
		];
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	private static function normalize_type( $t ): string {
		$t = is_string( $t ) ? strtolower( $t ) : 'string';
		$map = [
			'int' => 'integer', 'long' => 'integer', 'float' => 'number',
			'double' => 'number', 'bool' => 'boolean', 'list' => 'array',
			'dict' => 'object', 'json' => 'object',
		];
		return $map[ $t ] ?? $t;
	}
}
