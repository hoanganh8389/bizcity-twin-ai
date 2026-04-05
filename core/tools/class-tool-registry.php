<?php
/**
 * BizCity Unified Tool Registry — Phase 1.9 Sprint 2
 *
 * Aggregates all registered tools from every source (BizCity_Intent_Tools,
 * BCN_Notebook_Tool_Registry) into a single flat registry.  This is an
 * ADDITIVE layer — existing registries are left untouched.
 *
 * Registration happens via auto-sync adapters in core/tools/bootstrap.php
 * (priority 30 + 31, after all tool groups have registered at ≤27).
 *
 * Schema per entry:
 *   slug             string   Machine identifier (unique key)
 *   label            string   Human-readable name
 *   description      string   One-line summary
 *   icon             string   Emoji or icon slug
 *   icon_url         string   (optional) URL to icon image
 *   color            string   Brand colour token (e.g. 'blue', 'green', '#abc')
 *   category         string   (optional) Group / category label
 *   tool_type        string   'atomic' | 'distribution' | 'notebook' | 'planning' etc.
 *   available        bool     Whether the tool is currently executable
 *   studio_enabled   bool     Show in Studio output chooser
 *   at_enabled       bool     Usable in @ selector (ToolDialog)
 *   accepts_skill    bool     Whether skill parameters are accepted
 *   content_tier     int      0 = utility, 1+ = content generating
 *   input_fields     array    Declared input schema (may be empty)
 *   source           string   'intent_tools' | 'notebook_registry' | 'custom'
 *
 * @package  BizCity_Tools
 * @since    2026-05-01 (Phase 1.9 Sprint 2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Registry {

	/** @var array<string, array> Flat tool list keyed by slug */
	private static $tools = [];

	/** @var bool Whether the registry has been sealed (no more registrations) */
	private static $sealed = false;

	// ── Registration ────────────────────────────────────────────────

	/**
	 * Register (or overwrite) a tool in the unified registry.
	 *
	 * Late registrations after seal are silently dropped so that runtime
	 * code cannot mutate the registry after it has been read by adapters.
	 *
	 * @param string $slug   Unique machine key.
	 * @param array  $schema Tool schema — see class docblock for keys.
	 */
	public static function register( string $slug, array $schema ): void {
		if ( self::$sealed ) {
			return;
		}

		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return;
		}

		self::$tools[ $slug ] = array_merge(
			[
				'slug'          => $slug,
				'label'         => ucwords( str_replace( [ '_', '-' ], ' ', $slug ) ),
				'description'   => '',
				'icon'          => '🔧',
				'icon_url'      => '',
				'color'         => 'gray',
				'category'      => '',
				'tool_type'     => 'atomic',
				'available'     => true,
				'studio_enabled'=> false,
				'at_enabled'    => false,
				'accepts_skill' => false,
				'content_tier'  => 0,
				'input_fields'  => [],
				'source'        => 'custom',
			],
			$schema,
			[ 'slug' => $slug ] // ensure slug key is always normalized
		);
	}

	/**
	 * Seal the registry.  Called by the adapter bootstrap after all
	 * auto-sync passes to prevent further mutation.
	 */
	public static function seal(): void {
		self::$sealed = true;
	}

	// ── Queries ──────────────────────────────────────────────────────

	/**
	 * Return all registered tools.
	 *
	 * @return array<string, array>
	 */
	public static function get_all(): array {
		return self::$tools;
	}

	/**
	 * Return tools that are allowed to appear in the Studio output panel.
	 * Formatted identically to BCN_Notebook_Tool_Registry::get_all() so
	 * the React studioTools config key doesn't need any changes.
	 *
	 * @return array<string, array>
	 */
	public static function get_studio_tools(): array {
		$result = [];
		foreach ( self::$tools as $slug => $tool ) {
			if ( ! empty( $tool['studio_enabled'] ) ) {
				$result[ $slug ] = self::to_js_safe( $tool );
			}
		}
		return $result;
	}

	/**
	 * Return tools eligible for the @ (at-mention) dialog.
	 *
	 * @return array<string, array>
	 */
	public static function get_at_tools(): array {
		$result = [];
		foreach ( self::$tools as $slug => $tool ) {
			if ( ! empty( $tool['at_enabled'] ) ) {
				$result[ $slug ] = self::to_js_safe( $tool );
			}
		}
		return $result;
	}

	/**
	 * Return distribution tools only.
	 *
	 * @return array<string, array>
	 */
	public static function get_distribution_tools(): array {
		$result = [];
		foreach ( self::$tools as $slug => $tool ) {
			if ( ( $tool['tool_type'] ?? '' ) === 'distribution' ) {
				$result[ $slug ] = self::to_js_safe( $tool );
			}
		}
		return $result;
	}

	/**
	 * Return the full registry stripped of any callable values so it is
	 * safe to pass via wp_localize_script() or REST/AJAX responses.
	 *
	 * @return array<string, array>
	 */
	public static function get_for_js(): array {
		$result = [];
		foreach ( self::$tools as $slug => $tool ) {
			$result[ $slug ] = self::to_js_safe( $tool );
		}
		return $result;
	}

	/**
	 * Check whether a tool slug is registered.
	 *
	 * @param string $slug
	 * @return bool
	 */
	public static function has( string $slug ): bool {
		return isset( self::$tools[ sanitize_key( $slug ) ] );
	}

	/**
	 * Retrieve a single tool schema, or null if not found.
	 *
	 * @param string $slug
	 * @return array|null
	 */
	public static function get( string $slug ): ?array {
		return self::$tools[ sanitize_key( $slug ) ] ?? null;
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Strip any non-serialisable values (Closures, objects) from a tool
	 * array before handing it to the JS layer.
	 *
	 * @param array $tool
	 * @return array
	 */
	private static function to_js_safe( array $tool ): array {
		return array_filter(
			$tool,
			static fn( $v ) => ! ( $v instanceof \Closure ) && ! is_object( $v )
		);
	}
}
