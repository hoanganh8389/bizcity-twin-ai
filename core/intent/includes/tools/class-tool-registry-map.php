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
 * BizCity Tool Registry Map — Phase 1.11 S0
 *
 * Serializes all registered tools (in-memory + DB index) into a standard
 * JSON format that is LLM-readable and suitable for the Data Contract v1
 * payload sent to the server Smart Classifier.
 *
 * Sources:
 *   1. BizCity_Intent_Tools::list_all()  — live in-memory callbacks
 *   2. BizCity_Intent_Tool_Index (DB)    — persisted metadata (rich schema)
 *
 * Output format per tool:
 *   { tool_id, type, version, capability, input_fields, output_fields, execution, provider }
 *
 * @since Phase 1.11 S0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Registry_Map {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[ToolRegistryMap]';

	/** @var string Transient key for cached JSON */
	private const CACHE_KEY = 'bizcity_tool_registry_map';

	/** @var int Cache TTL — 1 hour (rebuilt on boot anyway via sync_all) */
	private const CACHE_TTL = 3600;

	/** @var array In-memory cache per request */
	private $tools_cache = null;

	/** @var array Composite tool definitions */
	private $composites = [];

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Build — merge in-memory tools + DB index
	 * ================================================================ */

	/**
	 * Build the full tools array from both sources.
	 *
	 * @return array [ tool_id => { tool_id, type, capability, input_fields, ... } ]
	 */
	public function build(): array {
		if ( $this->tools_cache !== null ) {
			return $this->tools_cache;
		}

		$tools = [];

		// Source 1: In-memory tools (BizCity_Intent_Tools)
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$live = BizCity_Intent_Tools::instance()->list_all();
			foreach ( $live as $name => $schema ) {
				$tools[ $name ] = $this->normalize_from_memory( $name, $schema );
			}
		}

		// Source 2: DB tool index (richer metadata — overrides/merges)
		if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			$db_tools = $this->load_from_db();
			foreach ( $db_tools as $row ) {
				$tool_id = $row['tool_name'] ?? $row['tool_key'] ?? '';
				if ( empty( $tool_id ) ) {
					continue;
				}
				if ( isset( $tools[ $tool_id ] ) ) {
					// Merge DB data into existing entry (DB has richer metadata)
					$tools[ $tool_id ] = $this->merge_db_into_tool( $tools[ $tool_id ], $row );
				} else {
					$tools[ $tool_id ] = $this->normalize_from_db( $row );
				}
			}
		}

		// Source 3: Composite tools
		foreach ( $this->composites as $cid => $def ) {
			$tools[ $cid ] = $def;
		}

		// S8 v3: Log provider distribution for observability
		$counts = array( 'core' => 0, 'builtin' => 0, 'provider' => 0, 'plugin' => 0, 'composite' => 0, 'other' => 0 );
		foreach ( $tools as $t ) {
			$p = $t['provider'] ?? 'core';
			if ( ( $t['type'] ?? '' ) === 'composite' ) {
				$counts['composite']++;
			} elseif ( isset( $counts[ $p ] ) ) {
				$counts[ $p ]++;
			} else {
				$counts['other']++;
			}
		}
		error_log( self::LOG . ' [Build] total=' . count( $tools )
			. ' | core=' . $counts['core']
			. ' | provider=' . $counts['provider']
			. ' | plugin=' . $counts['plugin']
			. ' | builtin=' . $counts['builtin']
			. ' | other=' . $counts['other']
			. ' | composite=' . $counts['composite'] );

		$this->tools_cache = $tools;
		return $tools;
	}

	/* ================================================================
	 *  JSON serialization
	 * ================================================================ */

	/**
	 * Full JSON of all tools.
	 *
	 * @return string JSON string.
	 */
	public function to_json(): string {
		return wp_json_encode( array_values( $this->build() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	/**
	 * Focused JSON — top N tools sorted by relevance/priority.
	 * Used in Data Contract v1 payload to keep size < 30KB.
	 *
	 * @param int      $top_n   Max tools to include.
	 * @param string[] $prefer  Tool IDs to prioritize (from skill tool_refs).
	 * @return string JSON string.
	 */
	public function to_focused_json( int $top_n = 15, array $prefer = [] ): string {
		$all = $this->build();

		// S8 v4: 4-tier priority: preferred → built-in action tools → core content → plugin/provider
		// Built-in tools (post_facebook, write_article, etc.) are essential for pipeline building.
		// Content tools (bizcity_atomic_*) provide variety but should not push out built-in tools.
		$preferred    = [];
		$builtin_tools = [];
		$content_tools = [];
		$plugin_tools  = [];

		foreach ( $all as $tid => $tool ) {
			if ( in_array( $tid, $prefer, true ) ) {
				$preferred[ $tid ] = $tool;
			} elseif ( ( $tool['provider'] ?? '' ) === 'core' || ( $tool['provider'] ?? '' ) === 'builtin' ) {
				// Separate built-in action tools from content tools
				$ct = (int) ( $tool['content_tier'] ?? 0 );
				if ( $ct > 0 ) {
					$content_tools[ $tid ] = $tool;
				} else {
					$builtin_tools[ $tid ] = $tool;
				}
			} else {
				$plugin_tools[ $tid ] = $tool;
			}
		}

		// Sort content tools by content_tier DESC
		uasort( $content_tools, function( $a, $b ) {
			return (int) ( $b['content_tier'] ?? 0 ) - (int) ( $a['content_tier'] ?? 0 );
		} );

		// Build result: preferred first, then BALANCED builtin + content, then plugin.
		// Split remaining slots ~50/50 between builtin and content.
		// If one category doesn't fill its half, overflow goes to the other.
		$result    = $preferred;
		$remaining = $top_n - count( $result );

		if ( $remaining > 0 ) {
			$half          = (int) ceil( $remaining / 2 );
			$builtin_avail = count( $builtin_tools );
			$content_avail = count( $content_tools );

			$builtin_take  = min( $builtin_avail, $half );
			$content_take  = min( $content_avail, $remaining - $builtin_take );
			// Overflow: if content didn't fill its half, give to builtin
			$leftover      = $remaining - $builtin_take - $content_take;
			if ( $leftover > 0 && $builtin_avail > $builtin_take ) {
				$builtin_take = min( $builtin_avail, $builtin_take + $leftover );
			}

			$result   += array_slice( $builtin_tools, 0, $builtin_take, true );
			$result   += array_slice( $content_tools, 0, $content_take, true );
			$remaining = $top_n - count( $result );
		}
		if ( $remaining > 0 ) {
			$result += array_slice( $plugin_tools, 0, $remaining, true );
		}

		// S8 v3: Log selected tools for debugging
		error_log( self::LOG . ' [Focused] selected=' . implode( ',', array_keys( $result ) )
			. ' | builtin_pool=' . count( $builtin_tools )
			. ' | content_pool=' . count( $content_tools )
			. ' | plugin_pool=' . count( $plugin_tools ) );

		return wp_json_encode( array_values( $result ), JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Stable fingerprint of the current registry state.
	 * Used for cache invalidation in Data Contract v1.
	 *
	 * @return string 8-char hex fingerprint.
	 */
	public function get_fingerprint(): string {
		$ids = array_keys( $this->build() );
		sort( $ids );
		return substr( md5( implode( '|', $ids ) ), 0, 8 );
	}

	/* ================================================================
	 *  Composite tools (S3 — placeholder API)
	 * ================================================================ */

	/**
	 * Register a composite tool definition.
	 *
	 * @param string $tool_id    Composite tool ID.
	 * @param array  $definition Full composite definition.
	 */
	public function register_composite( string $tool_id, array $definition ): void {
		$definition['tool_id'] = $tool_id;
		$definition['type']    = 'composite';
		$this->composites[ $tool_id ] = $definition;
		$this->tools_cache = null; // bust cache
	}

	/**
	 * Get all registered composites.
	 *
	 * @return array
	 */
	public function get_composites(): array {
		return $this->composites;
	}

	/**
	 * Match a tool_id to a composite definition.
	 *
	 * @param string $tool_id
	 * @return array|null Composite definition or null.
	 */
	public function match_composite( string $tool_id ): ?array {
		return $this->composites[ $tool_id ] ?? null;
	}

	/* ================================================================
	 *  PRIVATE — normalization helpers
	 * ================================================================ */

	/**
	 * Normalize a tool from in-memory BizCity_Intent_Tools format.
	 *
	 * S8: provider is resolved via get_tool_source() — plugin/provider tools
	 * are NOT marked 'core', preventing hallucination in Smart Classifier.
	 */
	private function normalize_from_memory( string $name, array $schema ): array {
		$input_fields = [];
		foreach ( (array) ( $schema['input_fields'] ?? [] ) as $field => $meta ) {
			if ( is_array( $meta ) ) {
				$input_fields[ $field ] = [
					'type'     => $meta['type'] ?? 'string',
					'required' => ! empty( $meta['required'] ),
				];
			} else {
				$input_fields[ $field ] = [
					'type'     => 'string',
					'required' => ( $meta === 'required' ),
				];
			}
		}

		// S8: Determine real provider — 'core' only for built_in and core-registered tools
		$provider = 'core';
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$source = BizCity_Intent_Tools::instance()->get_tool_source( $name );
			if ( $source === 'provider' || $source === 'plugin' ) {
				$provider = $source; // Will be overridden by DB merge if available
			}
		}

		return [
			'tool_id'      => $name,
			'type'         => $schema['tool_type'] ?? 'atomic',
			'version'      => '1.0',
			'capability'   => [
				'summary'  => $schema['description'] ?? '',
				'actions'  => [],
				'domains'  => [],
				'triggers' => [],
			],
			'input_fields'  => $input_fields,
			'output_fields' => [],
			'execution'     => [
				'auto_execute'              => ! empty( $schema['auto_execute'] ),
				'confirm_required'          => empty( $schema['auto_execute'] ),
				'side_effects'              => [],
				'idempotent'                => false,
				'content_generation'        => ( (int) ( $schema['content_tier'] ?? 0 ) ) > 0,
				'response_needs_synthesis'  => ! empty( $schema['response_needs_synthesis'] ),
			],
			'provider'     => $provider,
			'content_tier' => $schema['content_tier'] ?? null,
			'accepts_skill' => ! empty( $schema['accepts_skill'] ),
		];
	}

	/**
	 * Normalize a tool from DB row (BizCity_Intent_Tool_Index).
	 */
	private function normalize_from_db( array $row ): array {
		$tool_id = $row['tool_name'] ?? $row['tool_key'] ?? '';

		$input_fields = [];
		$req_slots = json_decode( $row['required_slots'] ?? '[]', true ) ?: [];
		$opt_slots = json_decode( $row['optional_slots'] ?? '[]', true ) ?: [];
		foreach ( $req_slots as $slot ) {
			$name = is_array( $slot ) ? ( $slot['name'] ?? '' ) : (string) $slot;
			if ( $name ) {
				$input_fields[ $name ] = [ 'type' => 'string', 'required' => true ];
			}
		}
		foreach ( $opt_slots as $slot ) {
			$name = is_array( $slot ) ? ( $slot['name'] ?? '' ) : (string) $slot;
			if ( $name ) {
				$input_fields[ $name ] = [ 'type' => 'string', 'required' => false ];
			}
		}

		$side_effects = json_decode( $row['side_effects'] ?? '[]', true ) ?: [];

		$cap_tags    = json_decode( $row['capability_tags'] ?? '[]', true ) ?: [];
		$intent_tags = json_decode( $row['intent_tags'] ?? '[]', true ) ?: [];

		return [
			'tool_id'      => $tool_id,
			'type'         => 'atomic',
			'version'      => $row['version'] ?? '1.0',
			'capability'   => [
				'summary'  => $row['description'] ?? ( $row['goal_description'] ?? '' ),
				'actions'  => $cap_tags,
				'domains'  => json_decode( $row['domain_tags'] ?? '[]', true ) ?: [],
				'triggers' => $intent_tags,
			],
			'input_fields'  => $input_fields,
			'output_fields' => json_decode( $row['output_schema'] ?? '{}', true ) ?: [],
			'execution'     => [
				'auto_execute'              => ! empty( $row['auto_execute'] ),
				'confirm_required'          => empty( $row['auto_execute'] ),
				'side_effects'              => $side_effects,
				'idempotent'                => ! empty( $row['idempotency'] ),
				'content_generation'        => ! empty( $row['content_generation'] ),
				'response_needs_synthesis'  => ! empty( $row['response_needs_synthesis'] ),
			],
			'provider' => $row['plugin'] ?? 'core',
		];
	}

	/**
	 * Merge richer DB data into an existing in-memory tool.
	 */
	private function merge_db_into_tool( array $tool, array $row ): array {
		// DB description overrides if present
		if ( ! empty( $row['description'] ) ) {
			$tool['capability']['summary'] = $row['description'];
		}
		if ( ! empty( $row['goal_description'] ) && empty( $tool['capability']['summary'] ) ) {
			$tool['capability']['summary'] = $row['goal_description'];
		}

		// Merge capability tags
		$cap_tags = json_decode( $row['capability_tags'] ?? '[]', true ) ?: [];
		if ( $cap_tags ) {
			$tool['capability']['actions'] = array_values( array_unique(
				array_merge( $tool['capability']['actions'], $cap_tags )
			) );
		}

		// Merge domains
		$domains = json_decode( $row['domain_tags'] ?? '[]', true ) ?: [];
		if ( $domains ) {
			$tool['capability']['domains'] = array_values( array_unique(
				array_merge( $tool['capability']['domains'], $domains )
			) );
		}

		// Merge triggers
		$intent_tags = json_decode( $row['intent_tags'] ?? '[]', true ) ?: [];
		if ( $intent_tags ) {
			$tool['capability']['triggers'] = array_values( array_unique(
				array_merge( $tool['capability']['triggers'], $intent_tags )
			) );
		}

		// Side effects from DB
		$se = json_decode( $row['side_effects'] ?? '[]', true ) ?: [];
		if ( $se ) {
			$tool['execution']['side_effects'] = $se;
		}

		// Provider from DB
		// S8 fix v3: sync_memory_tools() → sync_builtin_tools() marks ALL in-memory
		// tools as plugin='builtin' (including provider-registered ones like bizcoach).
		// If memory-side detected 'provider' or 'plugin', that's more accurate than DB.
		if ( ! empty( $row['plugin'] ) ) {
			$db_provider = $row['plugin'];
			if ( $db_provider === 'builtin' ) {
				$mem_provider = $tool['provider'] ?? 'core';
				// Memory detected non-core source → trust it over DB sync artifact
				$tool['provider'] = ( $mem_provider === 'provider' || $mem_provider === 'plugin' )
					? $mem_provider
					: 'core';
			} else {
				$tool['provider'] = $db_provider;
			}
		}

		// Version from DB
		if ( ! empty( $row['version'] ) ) {
			$tool['version'] = $row['version'];
		}

		return $tool;
	}

	/**
	 * Load all active tools from DB index.
	 *
	 * @return array DB rows.
	 */
	private function load_from_db(): array {
		global $wpdb;

		$index = BizCity_Intent_Tool_Index::instance();
		$table = $wpdb->prefix . 'bizcity_tool_registry';

		// Check schema version — table might not exist
		$version = (int) get_option( BizCity_Intent_Tool_Index::SCHEMA_VERSION_KEY, 0 );
		if ( $version < 1 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE active = 1 ORDER BY priority ASC, tool_name ASC",
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}
}
