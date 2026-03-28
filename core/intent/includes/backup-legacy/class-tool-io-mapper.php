<?php
/**
 * BizCity Tool I/O Mapper — Deterministic + LLM Fallback Field Mapping
 *
 * Maps output fields from tool A → input fields of tool B using 5 rules:
 *   Rule 1: Exact name match
 *   Rule 2: Semantic type compatibility
 *   Rule 3: Alias match (hard-coded canonical aliases)
 *   Rule 4: LLM fallback (GPT-4o-mini) + cache
 *   Rule 5: Unmappable → HIL ask user
 *
 * Phase 1 — Section 2.3: I/O Convention for LLM Mapping
 *
 * @package BizCity_Intent
 * @since   4.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_IO_Mapper {

    /**
     * Canonical alias groups. Fields within same group can be mapped 1:1.
     */
    const ALIAS_GROUPS = [
        'content'        => [ 'content', 'message', 'body', 'text' ],
        'title'          => [ 'title', 'name', 'subject' ],
        'image_url'      => [ 'image_url', 'thumbnail', 'featured_image', 'image' ],
        'url'            => [ 'url', 'link', 'permalink', 'href' ],
        'id'             => [ 'id', 'post_id', 'resource_id', 'object_id' ],
        'excerpt'        => [ 'excerpt', 'summary', 'description' ],
        'topic'          => [ 'topic', 'keyword', 'theme' ],
        'category'       => [ 'category', 'category_name', 'cat' ],
        'customer_name'  => [ 'customer_name', 'full_name' ],
        'customer_phone' => [ 'customer_phone', 'phone', 'phone_number' ],
        'video_url'      => [ 'video_url', 'video' ],
        'tags'           => [ 'tags', 'labels' ],
    ];

    /**
     * Semantic type compatibility matrix.
     * Key = source type, value = array of compatible target types.
     */
    const TYPE_COMPAT = [
        'text_short' => [ 'text_short', 'text_long' ],
        'text_long'  => [ 'text_long' ],
        'integer'    => [ 'integer', 'number', 'string' ],
        'number'     => [ 'number' ],
        'url'        => [ 'url', 'url_image', 'url_video', 'url_admin' ],
        'url_image'  => [ 'url_image', 'url' ],
        'url_video'  => [ 'url_video', 'url' ],
        'url_admin'  => [ 'url_admin', 'url' ],
        'enum'       => [ 'enum', 'text_short' ],
        'date'       => [ 'date', 'text_short' ],
        'datetime'   => [ 'datetime', 'text_short' ],
        'boolean'    => [ 'boolean' ],
        'string'     => [ 'string', 'text_short', 'text_long' ],
    ];

    /** @var string Transient prefix for LLM mapping cache. */
    const CACHE_PREFIX = 'bizcity_io_map_';
    const CACHE_TTL    = 86400 * 7; // 7 days

    /**
     * Map output fields from source tool to input fields of target tool.
     *
     * @param array $source_output  Output data from source tool. { field => value }
     * @param array $source_schema  Output field schema. { field => { type, description } }
     * @param array $target_schema  Input field schema of target tool. { field => { type, required, description } }
     * @return array {
     *   @type array $mapped          Successfully mapped { target_field => value }
     *   @type array $unmapped        Fields that could not be mapped. [ field_name, ... ]
     *   @type array $mapping_log     Explanation of each mapping { target_field => { source, rule } }
     * }
     */
    public static function map( array $source_output, array $source_schema, array $target_schema ) {
        $mapped      = [];
        $unmapped    = [];
        $mapping_log = [];
        $used_sources = [];

        foreach ( $target_schema as $target_field => $target_config ) {
            $result = self::resolve_field(
                $target_field,
                $target_config,
                $source_output,
                $source_schema,
                $used_sources
            );

            if ( $result !== null ) {
                $mapped[ $target_field ]      = $result['value'];
                $mapping_log[ $target_field ] = $result['log'];
                $used_sources[]               = $result['source_field'];
            } elseif ( ! empty( $target_config['required'] ) ) {
                $unmapped[] = $target_field;
            }
        }

        return [
            'mapped'      => $mapped,
            'unmapped'    => $unmapped,
            'mapping_log' => $mapping_log,
        ];
    }

    /**
     * Resolve a single target field from source output.
     *
     * @param string $target_field
     * @param array  $target_config
     * @param array  $source_output
     * @param array  $source_schema
     * @param array  $used_sources  Already-used source fields (avoid double-mapping).
     * @return array|null { value, source_field, log: { source, rule } }
     */
    private static function resolve_field( $target_field, $target_config, $source_output, $source_schema, $used_sources ) {

        // Rule 1: Exact name match
        if ( array_key_exists( $target_field, $source_output ) && ! in_array( $target_field, $used_sources, true ) ) {
            return [
                'value'        => $source_output[ $target_field ],
                'source_field' => $target_field,
                'log'          => [ 'source' => $target_field, 'rule' => 'exact_name' ],
            ];
        }

        // Rule 3: Alias match (checked before type match because it's more specific)
        $alias_result = self::find_by_alias( $target_field, $source_output, $used_sources );
        if ( $alias_result ) {
            return $alias_result;
        }

        // Rule 2: Semantic type match (single candidate only)
        $target_type = $target_config['type'] ?? 'string';
        $type_result = self::find_by_type( $target_type, $source_output, $source_schema, $used_sources );
        if ( $type_result ) {
            return $type_result;
        }

        return null;
    }

    /**
     * Rule 3: Find source field via canonical alias groups.
     */
    private static function find_by_alias( $target_field, $source_output, $used_sources ) {
        // Find which alias group the target field belongs to
        $target_group = null;
        foreach ( self::ALIAS_GROUPS as $canonical => $aliases ) {
            if ( in_array( $target_field, $aliases, true ) ) {
                $target_group = $aliases;
                break;
            }
        }

        if ( ! $target_group ) {
            return null;
        }

        // Check if any source field is in the same alias group
        foreach ( $target_group as $alias ) {
            if ( $alias === $target_field ) {
                continue; // Already checked in Rule 1
            }
            if ( array_key_exists( $alias, $source_output ) && ! in_array( $alias, $used_sources, true ) ) {
                return [
                    'value'        => $source_output[ $alias ],
                    'source_field' => $alias,
                    'log'          => [ 'source' => $alias, 'rule' => 'alias_match' ],
                ];
            }
        }

        return null;
    }

    /**
     * Rule 2: Find source field by semantic type compatibility.
     * Only returns a match if there's exactly 1 compatible candidate (unambiguous).
     */
    private static function find_by_type( $target_type, $source_output, $source_schema, $used_sources ) {
        $compatible_types = self::TYPE_COMPAT[ $target_type ] ?? [ $target_type ];
        $candidates = [];

        foreach ( $source_schema as $field => $config ) {
            if ( in_array( $field, $used_sources, true ) ) {
                continue;
            }
            $source_type = $config['type'] ?? 'string';
            if ( in_array( $source_type, $compatible_types, true ) && array_key_exists( $field, $source_output ) ) {
                $candidates[] = $field;
            }
        }

        // Only use if unambiguous (exactly 1 candidate)
        if ( count( $candidates ) === 1 ) {
            $field = $candidates[0];
            return [
                'value'        => $source_output[ $field ],
                'source_field' => $field,
                'log'          => [ 'source' => $field, 'rule' => 'type_match' ],
            ];
        }

        return null;
    }

    /**
     * Rule 4: LLM fallback mapping via GPT-4o-mini.
     * Called by Core Planner when deterministic rules fail.
     *
     * @param string $source_tool   Source tool name (for cache key).
     * @param string $target_tool   Target tool name (for cache key).
     * @param array  $source_schema Output fields of source tool.
     * @param array  $target_fields Unmapped required fields of target tool with their schemas.
     * @return array { target_field => source_field_reference }
     */
    public static function llm_fallback( $source_tool, $target_tool, $source_schema, $target_fields ) {
        // Check cache first
        $cache_key = self::CACHE_PREFIX . md5( $source_tool . ':' . $target_tool . ':' . implode( ',', array_keys( $target_fields ) ) );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // Build compact schema descriptions
        $source_desc = [];
        foreach ( $source_schema as $field => $config ) {
            $source_desc[ $field ] = ( $config['type'] ?? 'string' ) . ' — ' . ( $config['description'] ?? '' );
        }
        $target_desc = [];
        foreach ( $target_fields as $field => $config ) {
            $target_desc[ $field ] = ( $config['type'] ?? 'string' ) . ' — ' . ( $config['description'] ?? '' );
        }

        $prompt = sprintf(
            "Map output fields to input fields.\n\nAvailable output: %s\n\nRequired input: %s\n\nReturn JSON only: {\"input_field\": \"output_field\"}\nIf no match, use null as value.",
            wp_json_encode( $source_desc, JSON_UNESCAPED_UNICODE ),
            wp_json_encode( $target_desc, JSON_UNESCAPED_UNICODE )
        );

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [];
        }

        $response = bizcity_openrouter_chat( [
            [
                'role'    => 'system',
                'content' => 'You are a data field mapper. Return only valid JSON. No explanation.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ], [
            'model'       => 'openai/gpt-4o-mini',
            'max_tokens'  => 200,
            'temperature' => 0,
        ] );

        $mapping = [];
        if ( ! empty( $response['content'] ) ) {
            $decoded = json_decode( $response['content'], true );
            if ( is_array( $decoded ) ) {
                // Filter out null values (unmappable)
                foreach ( $decoded as $target => $source ) {
                    if ( $source !== null && isset( $source_schema[ $source ] ) ) {
                        $mapping[ $target ] = $source;
                    }
                }
            }
        }

        // Cache for future calls
        set_transient( $cache_key, $mapping, self::CACHE_TTL );

        return $mapping;
    }

    /**
     * Resolve input_map references from pipeline step output.
     *
     * Supports:
     *   $step[0].data.title   — output from step index 0, data.title
     *   $slots.topic          — from current conversation slots
     *   $prev.data.image_url  — from immediately previous step
     *   literal string        — passed through as-is
     *
     * @param array $input_map   { target_field => reference_string }
     * @param array $step_results  Array of step results indexed by step_index.
     * @param array $slots        Current conversation slots.
     * @return array { target_field => resolved_value }
     */
    public static function resolve_input_map( array $input_map, array $step_results, array $slots = [] ) {
        $resolved = [];

        foreach ( $input_map as $target_field => $ref ) {
            $resolved[ $target_field ] = self::resolve_reference( $ref, $step_results, $slots );
        }

        return $resolved;
    }

    /**
     * Resolve a single reference string.
     *
     * @param string $ref
     * @param array  $step_results
     * @param array  $slots
     * @return mixed
     */
    private static function resolve_reference( $ref, array $step_results, array $slots ) {
        if ( ! is_string( $ref ) ) {
            return $ref;
        }

        // $step[N].data.field
        if ( preg_match( '/^\$step\[(\d+)\]\.(.+)$/', $ref, $m ) ) {
            $step_idx = (int) $m[1];
            $path     = $m[2];
            if ( isset( $step_results[ $step_idx ] ) ) {
                return self::dot_get( $step_results[ $step_idx ], $path );
            }
            return null;
        }

        // $prev.data.field
        if ( preg_match( '/^\$prev\.(.+)$/', $ref, $m ) ) {
            $path = $m[1];
            if ( ! empty( $step_results ) ) {
                $last = end( $step_results );
                return self::dot_get( $last, $path );
            }
            return null;
        }

        // $slots.field
        if ( preg_match( '/^\$slots\.(.+)$/', $ref, $m ) ) {
            return $slots[ $m[1] ] ?? null;
        }

        // Literal value
        return $ref;
    }

    /**
     * Dot-notation access to nested array.
     *
     * @param array  $data
     * @param string $path  e.g. "data.title"
     * @return mixed
     */
    private static function dot_get( $data, $path ) {
        $keys = explode( '.', $path );
        $current = $data;
        foreach ( $keys as $key ) {
            if ( is_array( $current ) && array_key_exists( $key, $current ) ) {
                $current = $current[ $key ];
            } else {
                return null;
            }
        }
        return $current;
    }

    /**
     * Validate that a tool's declared I/O fields use canonical names.
     * Returns warnings for non-canonical field names.
     *
     * @param array $fields  { field_name => { type, ... } }
     * @return array Warnings [ 'field_name: suggestion' ]
     */
    public static function validate_canonical_names( array $fields ) {
        $all_canonical = [];
        foreach ( self::ALIAS_GROUPS as $aliases ) {
            foreach ( $aliases as $alias ) {
                $all_canonical[] = $alias;
            }
        }
        // Common standalone canonical names not in alias groups
        $all_canonical = array_merge( $all_canonical, [
            'type', 'status', 'author_id', 'quantity', 'price', 'currency',
            'date_from', 'date_to', 'evidence_id', 'pipeline_id', 'session_id',
            'meta', 'platform', 'platform_id', 'edit_url', 'prompt',
        ] );

        $warnings = [];
        foreach ( array_keys( $fields ) as $field ) {
            if ( ! in_array( $field, $all_canonical, true ) ) {
                // Check if it's close to a canonical name (alias match)
                $suggestion = self::suggest_canonical( $field );
                if ( $suggestion ) {
                    $warnings[] = "{$field}: consider using canonical name '{$suggestion}'";
                }
            }
        }

        return $warnings;
    }

    /**
     * Suggest a canonical name for a non-canonical field.
     *
     * @param string $field
     * @return string|null
     */
    private static function suggest_canonical( $field ) {
        $lower = strtolower( $field );
        foreach ( self::ALIAS_GROUPS as $canonical => $aliases ) {
            foreach ( $aliases as $alias ) {
                if ( strpos( $lower, $alias ) !== false || strpos( $alias, $lower ) !== false ) {
                    return $canonical;
                }
            }
        }
        return null;
    }
}
