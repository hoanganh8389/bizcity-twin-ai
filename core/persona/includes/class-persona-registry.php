<?php
/**
 * Bizcity Twin AI — Persona Registry (singleton).
 *
 * PHASE-0.18 Wave 0.18.0. Aggregates providers contributed via
 * `bizcity_persona_tool_providers` filter, validates them against the
 * R-PP-1..R-PP-8 contract, and exposes lookup helpers used by the admin UI
 * and the chat pipeline.
 *
 * Build is lazy: providers are collected the first time `instance()->all()`
 * (or any lookup) is called, so plugins still loading at later priorities
 * have a chance to add filters first.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.3.3
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Persona_Registry' ) ) {
    return;
}

require_once __DIR__ . '/class-persona-tool-provider.php';

class BizCity_Persona_Registry {

    /** Slugs reserved by core ingest pipeline (cannot be claimed by providers). */
    const RESERVED_KINDS = [ 'quick_faq', 'file', 'url', 'manual', 'fanpage', 'tavily', 'legacy_faq' ];

    /** Slug regex (R-PP-1 / R-PP-3 / R-PP-5). */
    const SLUG_REGEX = '/^[a-z][a-z0-9_-]{2,39}$/';
    const KIND_REGEX = '/^[a-z][a-z0-9_]{2,39}$/';

    /** @var self|null */
    private static $instance = null;

    /** @var array<string,BizCity_Persona_Tool_Provider> slug => provider */
    private $providers = [];

    /** @var array<string,string> kind => provider_slug */
    private $kind_index = [];

    /** @var array<string,array{provider:string,def:array}> tool_name => row */
    private $tool_index = [];

    /** @var bool */
    private $built = false;

    /** @var array<int,string> Validation diagnostics (logged on demand). */
    private $errors = [];

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Force a rebuild on next lookup. Useful for tests + after plugin (de)activation.
     */
    public function reset(): void {
        $this->providers  = [];
        $this->kind_index = [];
        $this->tool_index = [];
        $this->built      = false;
        $this->errors     = [];
    }

    /**
     * @return array<string,BizCity_Persona_Tool_Provider>
     */
    public function all(): array {
        $this->build();
        return $this->providers;
    }

    public function get( string $slug ): ?BizCity_Persona_Tool_Provider {
        $this->build();
        return $this->providers[ $slug ] ?? null;
    }

    /**
     * Lookup the provider that owns a given source_type kind.
     */
    public function provider_for_kind( string $kind ): ?BizCity_Persona_Tool_Provider {
        $this->build();
        $slug = $this->kind_index[ $kind ] ?? null;
        return $slug ? ( $this->providers[ $slug ] ?? null ) : null;
    }

    /**
     * Lookup tool definition + owning provider by global tool name.
     *
     * @return array{provider:BizCity_Persona_Tool_Provider,def:array}|null
     */
    public function find_tool( string $tool_name ): ?array {
        $this->build();
        $row = $this->tool_index[ $tool_name ] ?? null;
        if ( ! $row ) {
            return null;
        }
        $provider = $this->providers[ $row['provider'] ] ?? null;
        if ( ! $provider ) {
            return null;
        }
        return [ 'provider' => $provider, 'def' => $row['def'] ];
    }

    /**
     * Aggregated source kinds (reserved + provider-declared). Wired into the
     * `bizcity_kg_source_kinds` filter by bootstrap.
     *
     * @return string[]
     */
    public function all_source_kinds(): array {
        $this->build();
        return array_values( array_unique( array_merge( self::RESERVED_KINDS, array_keys( $this->kind_index ) ) ) );
    }

    /**
     * Validation diagnostics from the last build (read-only).
     *
     * @return string[]
     */
    public function get_errors(): array {
        $this->build();
        return $this->errors;
    }

    /**
     * Resolve the persona provider bound to a character via
     * `character.settings.provider_id` (Wave 0.18.2 contract).
     *
     * @param int $character_id wp_bizcity_characters.id
     * @return BizCity_Persona_Tool_Provider|null
     */
    public function character_to_provider( int $character_id ): ?BizCity_Persona_Tool_Provider {
        if ( $character_id <= 0 ) {
            return null;
        }
        global $wpdb;
        $tbl     = $wpdb->prefix . 'bizcity_characters';
        $settings_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT settings FROM {$tbl} WHERE id = %d", $character_id
        ) );
        if ( ! $settings_raw ) {
            return null;
        }
        $settings = json_decode( (string) $settings_raw, true );
        if ( ! is_array( $settings ) || empty( $settings['provider_id'] ) ) {
            return null;
        }
        return $this->get( (string) $settings['provider_id'] );
    }

    /**
     * Resolve research capability for a character.
     *
     * Pipeline:
     *   1. Lookup provider via `character_to_provider()`.
     *   2. If no provider OR provider returns null → use core default
     *      (basic search-only fast mode) so pure-prompt Gurus still work.
     *   3. Apply filter `bizcity_research_capability_for_character` to
     *      allow per-character overrides (disable, tweak limit, etc.).
     *
     * @return array|null  null = research disabled for this character.
     */
    public function get_research_capability_for_character( int $character_id ): ?array {
        $cache_key = 'bizcity_research_cap_' . $character_id;
        $cached    = wp_cache_get( $cache_key, 'bizcity_research' );
        if ( false !== $cached ) {
            return $cached === '__NULL__' ? null : $cached;
        }

        $capability = null;
        $provider   = $this->character_to_provider( $character_id );
        if ( $provider ) {
            $capability = $provider->get_research_capability();
        }

        // Default capability for characters without a provider OR provider
        // that does not declare research → enable basic search-only fast mode.
        if ( null === $capability ) {
            $capability = self::default_capability();
        }

        /**
         * Filter the research capability for a specific character.
         *
         * @param array|null $capability  Capability array or null to disable.
         * @param int        $character_id
         */
        $capability = apply_filters(
            'bizcity_research_capability_for_character',
            $capability,
            $character_id
        );

        // Validate + sanitize.
        if ( is_array( $capability ) ) {
            $capability = self::sanitize_capability( $capability );
        }

        wp_cache_set( $cache_key, $capability ?? '__NULL__', 'bizcity_research', 300 );
        return $capability;
    }

    /**
     * Default capability for Gurus without an explicit provider declaration.
     */
    public static function default_capability(): array {
        return [
            'enabled'            => true,
            'modes'              => [ 'fast' ],
            'allowed_tools'      => [ 'search' ],
            'rate_limit_per_day' => 30,
            'starter_queries'    => [],
            'topic_tags'         => [],
            'ui_label'           => __( '🔬 Nghiên cứu sâu', 'bizcity-twin-ai' ),
        ];
    }

    /**
     * Normalize + clamp capability values; drop unknown keys.
     */
    public static function sanitize_capability( array $cap ): ?array {
        if ( empty( $cap['enabled'] ) ) {
            return null;
        }
        $allowed_modes = [ 'fast', 'deep' ];
        $allowed_tools = [ 'search', 'extract', 'crawl' ];

        $modes = array_values( array_intersect(
            $allowed_modes,
            array_map( 'strval', (array) ( $cap['modes'] ?? [ 'fast' ] ) )
        ) );
        if ( empty( $modes ) ) {
            $modes = [ 'fast' ];
        }
        $tools = array_values( array_intersect(
            $allowed_tools,
            array_map( 'strval', (array) ( $cap['allowed_tools'] ?? [ 'search' ] ) )
        ) );
        if ( empty( $tools ) ) {
            $tools = [ 'search' ];
        }
        $starter = array_slice(
            array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $cap['starter_queries'] ?? [] ) ) ) ),
            0, 6
        );
        $tags = array_slice(
            array_values( array_filter( array_map( 'sanitize_title', (array) ( $cap['topic_tags'] ?? [] ) ) ) ),
            0, 10
        );
        return [
            'enabled'            => true,
            'modes'              => $modes,
            'allowed_tools'      => $tools,
            'rate_limit_per_day' => max( 1, min( 500, (int) ( $cap['rate_limit_per_day'] ?? 30 ) ) ),
            'starter_queries'    => $starter,
            'topic_tags'         => $tags,
            'ui_label'           => sanitize_text_field( $cap['ui_label'] ?? __( '🔬 Nghiên cứu sâu', 'bizcity-twin-ai' ) ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal: build + validate.
    // ─────────────────────────────────────────────────────────────────────

    private function build(): void {
        if ( $this->built ) {
            return;
        }
        $this->built = true;

        /**
         * Filter: register persona tool providers.
         *
         * @param BizCity_Persona_Tool_Provider[] $providers
         */
        $candidates = apply_filters( 'bizcity_persona_tool_providers', [] );
        if ( ! is_array( $candidates ) ) {
            $this->errors[] = 'filter_returned_non_array';
            return;
        }

        foreach ( $candidates as $candidate ) {
            $this->try_register( $candidate );
        }
    }

    private function try_register( $candidate ): void {
        if ( ! ( $candidate instanceof BizCity_Persona_Tool_Provider ) ) {
            $this->errors[] = 'not_a_provider_instance';
            return;
        }

        $slug = (string) $candidate->id();
        if ( ! preg_match( self::SLUG_REGEX, $slug ) ) {
            $this->errors[] = "invalid_slug:{$slug}";
            return;
        }
        if ( isset( $this->providers[ $slug ] ) ) {
            // R-PP-2: keep first registration, log conflict.
            $this->errors[] = "duplicate_slug:{$slug}";
            return;
        }

        // Validate source kinds.
        $kinds = $candidate->get_source_kinds();
        if ( ! is_array( $kinds ) ) {
            $this->errors[] = "{$slug}:source_kinds_not_array";
            return;
        }
        foreach ( $kinds as $kind ) {
            if ( ! is_string( $kind ) || ! preg_match( self::KIND_REGEX, $kind ) ) {
                $this->errors[] = "{$slug}:invalid_kind:" . (string) $kind;
                return;
            }
            if ( in_array( $kind, self::RESERVED_KINDS, true ) ) {
                $this->errors[] = "{$slug}:reserved_kind:{$kind}";
                return;
            }
            if ( isset( $this->kind_index[ $kind ] ) ) {
                $this->errors[] = "{$slug}:duplicate_kind:{$kind}";
                return;
            }
        }

        // Validate tool definitions.
        $tools = $candidate->get_tool_definitions();
        if ( ! is_array( $tools ) ) {
            $this->errors[] = "{$slug}:tools_not_array";
            return;
        }
        $local_tool_names = [];
        foreach ( $tools as $def ) {
            if ( ! is_array( $def ) || empty( $def['name'] ) ) {
                $this->errors[] = "{$slug}:tool_missing_name";
                return;
            }
            $name = (string) $def['name'];
            if ( ! preg_match( self::SLUG_REGEX, $name ) ) {
                $this->errors[] = "{$slug}:invalid_tool_name:{$name}";
                return;
            }
            if ( isset( $this->tool_index[ $name ] ) || isset( $local_tool_names[ $name ] ) ) {
                $this->errors[] = "{$slug}:duplicate_tool_name:{$name}";
                return;
            }
            $local_tool_names[ $name ] = true;
        }

        // All checks passed — commit.
        $this->providers[ $slug ] = $candidate;
        foreach ( $kinds as $kind ) {
            $this->kind_index[ $kind ] = $slug;
        }
        foreach ( $tools as $def ) {
            $this->tool_index[ (string) $def['name'] ] = [
                'provider' => $slug,
                'def'      => $def,
            ];
        }
    }
}
