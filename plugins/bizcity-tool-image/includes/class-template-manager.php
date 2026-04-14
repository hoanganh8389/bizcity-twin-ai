<?php
/**
 * Template Manager — CRUD for image generation templates.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Template_Manager {

    private static $table_suffix = 'bztimg_templates';

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    /* ═══════════════════════════════ READ ═══════════════════════════════ */

    public static function get_all( $args = array() ) {
        global $wpdb;
        $table  = self::table();
        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['category_id'] ) ) {
            $where[]  = 'category_id = %d';
            $values[] = absint( $args['category_id'] );
        }
        if ( ! empty( $args['category_slug'] ) ) {
            $cat = BizCity_Template_Category_Manager::get_by_slug( $args['category_slug'] );
            if ( $cat ) {
                $where[]  = 'category_id = %d';
                $values[] = (int) $cat['id'];
            } else {
                return array();
            }
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }
        if ( ! empty( $args['is_featured'] ) ) {
            $where[] = 'is_featured = 1';
        }
        if ( isset( $args['subcategory'] ) && $args['subcategory'] !== '' ) {
            $where[]  = 'subcategory = %s';
            $values[] = sanitize_text_field( $args['subcategory'] );
        }
        if ( ! empty( $args['slug'] ) ) {
            $where[]  = 'slug = %s';
            $values[] = sanitize_title( $args['slug'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(title LIKE %s OR tags LIKE %s OR description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $order  = 'ORDER BY sort_order ASC, id DESC';
        $limit  = '';

        $per_page = absint( $args['per_page'] ?? 20 );
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $limit    = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " {$order} {$limit}";
        if ( $values ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function count( $args = array() ) {
        global $wpdb;
        $table  = self::table();
        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['category_slug'] ) ) {
            $cat = BizCity_Template_Category_Manager::get_by_slug( $args['category_slug'] );
            if ( $cat ) {
                $where[]  = 'category_id = %d';
                $values[] = (int) $cat['id'];
            } else {
                return 0;
            }
        }
        if ( ! empty( $args['category_id'] ) ) {
            $where[]  = 'category_id = %d';
            $values[] = absint( $args['category_id'] );
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }
        if ( ! empty( $args['is_featured'] ) ) {
            $where[] = 'is_featured = 1';
        }
        if ( isset( $args['subcategory'] ) && $args['subcategory'] !== '' ) {
            $where[]  = 'subcategory = %s';
            $values[] = sanitize_text_field( $args['subcategory'] );
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[]  = '(title LIKE %s OR tags LIKE %s OR description LIKE %s)';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
        if ( $values ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }
        return (int) $wpdb->get_var( $sql );
    }

    public static function get_by_id( $id ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", absint( $id ) ),
            ARRAY_A
        );
        if ( $row ) {
            $row['form_fields'] = json_decode( $row['form_fields'] ?? '[]', true ) ?: array();
        }
        return $row;
    }

    public static function get_by_slug( $slug ) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE slug = %s", sanitize_title( $slug ) ),
            ARRAY_A
        );
        if ( $row ) {
            $row['form_fields'] = json_decode( $row['form_fields'] ?? '[]', true ) ?: array();
        }
        return $row;
    }

    public static function get_featured( $limit = 12 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE is_featured = 1 AND status = 'active' ORDER BY sort_order ASC LIMIT %d",
            absint( $limit )
        ), ARRAY_A );
    }

    /* ═══════════════════════════════ CREATE ═══════════════════════════════ */

    public static function insert( $data ) {
        global $wpdb;

        $row = self::sanitize_row( $data );
        if ( is_wp_error( $row ) ) return $row;

        if ( empty( $row['slug'] ) ) {
            $row['slug'] = sanitize_title( $row['title'] );
        }

        // Ensure unique slug
        $row['slug'] = self::unique_slug( $row['slug'] );

        $row['author_id'] = get_current_user_id();

        $result = $wpdb->insert( self::table(), $row );
        return $result ? $wpdb->insert_id : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    /* ═══════════════════════════════ UPDATE ═══════════════════════════════ */

    public static function update( $id, $data ) {
        global $wpdb;

        $id  = absint( $id );
        $row = self::sanitize_row( $data, true );
        if ( is_wp_error( $row ) ) return $row;

        if ( empty( $row ) ) return true;

        $row['updated_at'] = current_time( 'mysql' );

        $result = $wpdb->update( self::table(), $row, array( 'id' => $id ) );
        return false !== $result ? true : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    /**
     * Upsert by slug — update existing or insert new.
     * Skips update if version matches (idempotent), unless force_update is true.
     *
     * @param array  $data         Template data (must include 'slug').
     * @param string $version      Version string from _meta.version.
     * @param bool   $force_update If true, always update even when version matches.
     * @return int|WP_Error        Row ID on success.
     */
    public static function upsert_by_slug( $data, $version = '', $force_update = false ) {
        $slug = sanitize_title( $data['slug'] ?? '' );
        if ( ! $slug ) {
            return new \WP_Error( 'missing_slug', 'Slug is required for upsert.' );
        }

        $existing = self::get_by_slug( $slug );

        if ( $existing ) {
            // Same version → skip (idempotent), unless force_update
            if ( ! $force_update && $version && ( $existing['version'] ?? '' ) === $version ) {
                return (int) $existing['id'];
            }
            // Update existing row
            if ( $version ) {
                $data['version'] = $version;
            }
            unset( $data['slug'] ); // Don't change slug on update
            $result = self::update( (int) $existing['id'], $data );
            return is_wp_error( $result ) ? $result : (int) $existing['id'];
        }

        // Insert new
        if ( $version ) {
            $data['version'] = $version;
        }
        return self::insert( $data );
    }

    /* ═══════════════════════════════ DELETE ═══════════════════════════════ */

    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /* ═══════════════════════════════ DUPLICATE ═══════════════════════════════ */

    public static function duplicate( $id ) {
        $tpl = self::get_by_id( $id );
        if ( ! $tpl ) return new \WP_Error( 'not_found', 'Template not found.' );

        unset( $tpl['id'], $tpl['created_at'], $tpl['updated_at'] );
        $tpl['title']     = $tpl['title'] . ' (Copy)';
        $tpl['slug']      = self::unique_slug( $tpl['slug'] . '-copy' );
        $tpl['use_count'] = 0;
        $tpl['status']    = 'draft';
        $tpl['form_fields'] = wp_json_encode( $tpl['form_fields'] );

        return self::insert( $tpl );
    }

    /* ═══════════════════════════════ USE COUNT ═══════════════════════════════ */

    public static function increment_use_count( $id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET use_count = use_count + 1 WHERE id = %d",
            absint( $id )
        ) );
    }

    /* ═══════════════════════════════ IMPORT / EXPORT ═══════════════════════════════ */

    public static function export_all() {
        $templates = self::get_all( array( 'per_page' => 9999 ) );
        foreach ( $templates as &$tpl ) {
            $tpl['form_fields'] = json_decode( $tpl['form_fields'] ?? '[]', true ) ?: array();
        }
        return array(
            'version'   => '1.0',
            'plugin'    => 'bizcity-tool-image',
            'exported'  => gmdate( 'c' ),
            'templates' => $templates,
        );
    }

    /**
     * Import templates from JSON data.
     *
     * @param array $json_data Parsed JSON.
     * @param bool  $force     If true, upsert by slug (update existing). Default false = insert only.
     * @return int|WP_Error    Number of imported/updated templates.
     */
    public static function import( $json_data, $force = false ) {
        $version = $json_data['_meta']['version'] ?? '';

        // New bztimg_template single-schema format: { "_meta": { "schema": "bztimg_template" }, "template": {...}, ... }
        if ( isset( $json_data['_meta']['schema'] ) && $json_data['_meta']['schema'] === 'bztimg_template' ) {
            $templates  = self::normalize_bztimg_schema( $json_data );
            $extra_data = self::extract_extra_data( $json_data );
        } elseif ( ! empty( $json_data['templates'] ) && is_array( $json_data['templates'] ) ) {
            $templates  = $json_data['templates'];
            $extra_data = null;
        } elseif ( isset( $json_data['_meta']['schema'] ) && in_array( $json_data['_meta']['schema'], array( 'bztimg_sample_clothing', 'bztimg_sample_accessories' ), true ) ) {
            $templates  = self::normalize_sample_items( $json_data );
            $extra_data = null;
            $force      = true; // always upsert sample items
        } else {
            return new \WP_Error( 'invalid_data', 'Invalid import data. Expected "templates" array or bztimg_template schema.' );
        }

        $imported = 0;
        foreach ( $templates as $tpl ) {
            unset( $tpl['id'] );
            if ( is_array( $tpl['form_fields'] ?? null ) ) {
                $tpl['form_fields'] = wp_json_encode( $tpl['form_fields'] );
            }
            if ( $extra_data ) {
                $tpl['extra_data'] = wp_json_encode( $extra_data );
            }
            if ( $version ) {
                $tpl['version'] = $version;
            }

            if ( $force ) {
                $result = self::upsert_by_slug( $tpl, $version, true );
            } else {
                $result = self::insert( $tpl );
            }
            if ( ! is_wp_error( $result ) ) $imported++;
        }
        return $imported;
    }

    /**
     * Extract non-DB fields from bztimg_template JSON into extra_data blob.
     */
    private static function extract_extra_data( $json_data ) {
        $extra = array();
        if ( ! empty( $json_data['template']['prompt_templates'] ) ) {
            $extra['prompt_templates'] = $json_data['template']['prompt_templates'];
        }
        if ( ! empty( $json_data['generation_logic'] ) ) {
            $extra['generation_logic'] = $json_data['generation_logic'];
        }
        if ( ! empty( $json_data['model_library'] ) ) {
            $extra['model_library_meta'] = array(
                'max_selection' => $json_data['model_library']['max_selection'] ?? 5,
                'categories'    => $json_data['model_library']['categories'] ?? array(),
            );
        }
        if ( ! empty( $json_data['clothing_library'] ) ) {
            $extra['clothing_library_meta'] = array(
                'categories' => $json_data['clothing_library']['categories'] ?? array(),
                'modes'      => $json_data['clothing_library']['modes'] ?? array(),
            );
        }
        if ( ! empty( $json_data['accessory_library'] ) ) {
            $extra['accessory_library_meta'] = array(
                'accessory_types' => $json_data['accessory_library']['accessory_types'] ?? array(),
            );
        }
        // Mode-specific model recommendations
        $tpl = $json_data['template'] ?? array();
        foreach ( array( 'recommended_model_generate', 'recommended_model_composite', 'fallback_model_composite' ) as $key ) {
            if ( ! empty( $tpl[ $key ] ) ) {
                $extra[ $key ] = $tpl[ $key ];
            }
        }
        return $extra ?: null;
    }

    /**
     * Convert bztimg_template single-schema JSON to templates array.
     * Handles category_slug → category_id resolution.
     */
    private static function normalize_bztimg_schema( $json_data ) {
        $tpl = $json_data['template'] ?? array();
        if ( empty( $tpl ) ) return array();

        // Resolve category_id from category_slug if not set
        if ( ! empty( $tpl['category_slug'] ) && empty( $tpl['category_id'] ) ) {
            $cat = BizCity_Template_Category_Manager::get_by_slug( $tpl['category_slug'] );
            if ( $cat ) {
                $tpl['category_id'] = (int) $cat['id'];
            }
        }

        // Remove non-DB fields
        unset( $tpl['category_slug'] );

        return array( $tpl );
    }

    /**
     * Convert sample_clothing / sample_accessories JSON into template rows.
     */
    private static function normalize_sample_items( $json_data ) {
        $schema = $json_data['_meta']['schema'] ?? '';
        $items  = $json_data['items'] ?? array();
        if ( empty( $items ) ) return array();

        $is_clothing = ( $schema === 'bztimg_sample_clothing' );

        // Resolve category — apparel-tryon for clothing, on-hand for accessories
        $cat_slug = $is_clothing ? 'apparel-tryon' : 'on-hand';
        $cat      = BizCity_Template_Category_Manager::get_by_slug( $cat_slug );
        $cat_id   = $cat ? (int) $cat['id'] : 0;

        // Store library metadata (categories, modes, accessory_types) as extra_data
        $extra = array();
        if ( $is_clothing ) {
            $extra['clothing_categories'] = $json_data['categories'] ?? array();
            $extra['clothing_modes']      = $json_data['modes'] ?? array();
        } else {
            $extra['accessory_types'] = $json_data['accessory_types'] ?? array();
        }

        $templates = array();
        foreach ( $items as $item ) {
            $sub = $is_clothing ? ( $item['category'] ?? 'clothing' ) : ( $item['type'] ?? 'accessory' );

            $form_fields = array(
                'gender'         => $item['gender'] ?? 'unisex',
                'color'          => $item['color'] ?? '',
            );
            if ( $is_clothing ) {
                $form_fields['suitable_modes'] = $item['suitable_modes'] ?? array();
                $form_fields['clothing_category'] = $item['category'] ?? '';
            } else {
                $form_fields['accessory_type'] = $item['type'] ?? '';
            }

            $templates[] = array(
                'slug'              => sanitize_title( $item['id'] ?? '' ),
                'category_id'       => $cat_id,
                'subcategory'       => $sub,
                'title'             => $item['name'] ?? $item['id'],
                'description'       => $item['name'] ?? '',
                'thumbnail_url'     => $item['thumbnail'] ?? '',
                'tags'              => implode( ',', array_filter( array( $sub, $item['gender'] ?? '', $item['color'] ?? '' ) ) ),
                'form_fields'       => $form_fields,
                'prompt_template'   => $item['name'] ?? '',
                'recommended_model' => $is_clothing ? 'flux-kontext' : 'flux-kontext',
                'recommended_size'  => '1024x1536',
                'style'             => 'photorealistic',
                'num_outputs'       => 1,
                'is_featured'       => ( ( $item['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                'sort_order'        => (int) ( $item['sort_order'] ?? 99 ),
                'status'            => 'active',
                'extra_data'        => wp_json_encode( $extra ),
            );
        }
        return $templates;
    }

    /* ═══════════════════════════════ GENERATE FROM TEMPLATE ═══════════════════════════════ */

    /**
     * Resolve prompt from template + user form data.
     *
     * Mode-aware: selects prompt_template (generate vs composite), resolves
     * card_radio prompt_hints, model_picker → model_description, and assembles
     * ref_images array for composite mode.
     *
     * @param int   $template_id Template ID.
     * @param array $form_data   Key-value pairs from user form submission.
     * @param array $overrides   Optional model/size/style overrides.
     * @return array|WP_Error Slots array ready for BizCity_Tool_Image::generate_image().
     */
    public static function resolve_slots( $template_id, $form_data, $overrides = array() ) {
        $tpl = self::get_by_id( $template_id );
        if ( ! $tpl ) return new \WP_Error( 'not_found', 'Template not found.' );

        // ── 1. Determine creation_mode ──
        $creation_mode = sanitize_text_field( $form_data['creation_mode'] ?? '' );

        // ── 2. Load extra_data for prompt_templates & model recommendations ──
        $extra           = json_decode( $tpl['extra_data'] ?? '{}', true ) ?: array();
        $prompt_templates = $extra['prompt_templates'] ?? array();

        // ── 3. Select prompt template based on mode ──
        if ( ! empty( $creation_mode ) && ! empty( $prompt_templates[ $creation_mode ] ) ) {
            $prompt = $prompt_templates[ $creation_mode ];
        } else {
            $prompt = $tpl['prompt_template'];
        }

        // ── 4. Iterate form_fields, resolve variables ──
        $form_fields = $tpl['form_fields'];
        $ref_images  = array();

        foreach ( $form_fields as $field ) {
            $slug  = $field['slug'] ?? '';
            $value = $form_data[ $slug ] ?? '';

            switch ( $field['type'] ?? '' ) {

                case 'image_upload':
                    if ( ! empty( $value ) ) {
                        $ref_images[ $slug ] = esc_url_raw( $value );
                    }
                    $prompt = str_replace( '{{' . $slug . '}}', '', $prompt );
                    break;

                case 'model_picker':
                    if ( ! empty( $value ) ) {
                        if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
                            $ref_images[ $slug ] = esc_url_raw( $value );
                            $model_desc = 'a model similar to the reference image';
                        } else {
                            // Slug → look up model row for both description AND image
                            $model_desc = self::lookup_model_description( $tpl, $extra, $value );
                            $model_row  = self::get_by_slug( $value );
                            if ( $model_row && ! empty( $model_row['thumbnail_url'] ) ) {
                                $ref_images[ $slug ] = esc_url_raw( $model_row['thumbnail_url'] );
                            }
                        }
                        $prompt = str_replace( '{{model_description}}', sanitize_text_field( $model_desc ), $prompt );
                    }
                    $prompt = str_replace( '{{' . $slug . '}}', '', $prompt );
                    break;

                case 'card_radio':
                    if ( ! empty( $value ) && is_array( $field['options'] ?? null ) ) {
                        $hint = '';
                        foreach ( $field['options'] as $opt ) {
                            if ( ( $opt['value'] ?? '' ) === $value ) {
                                if ( $creation_mode === 'composite' && ! empty( $opt['prompt_hint_composite'] ) ) {
                                    $hint = $opt['prompt_hint_composite'];
                                } else {
                                    $hint = $opt['prompt_hint'] ?? '';
                                }
                                break;
                            }
                        }
                        $prompt = str_replace( '{{' . $slug . '_description}}', sanitize_text_field( $hint ), $prompt );
                        $prompt = str_replace( '{{' . $slug . '}}', sanitize_text_field( $value ), $prompt );
                    } else {
                        $prompt = str_replace( '{{' . $slug . '_description}}', '', $prompt );
                        $prompt = str_replace( '{{' . $slug . '}}', sanitize_text_field( $value ), $prompt );
                    }
                    break;

                case 'size_picker':
                    $prompt = str_replace( '{{' . $slug . '}}', '', $prompt );
                    break;

                case 'multi_reference_images':
                    if ( is_array( $field['image_roles'] ?? null ) ) {
                        foreach ( $field['image_roles'] as $role ) {
                            $role_slug = $role['slug'] ?? '';
                            $role_val  = $form_data[ $role_slug ] ?? '';
                            if ( ! empty( $role_val ) ) {
                                $ref_images[ $role_slug ] = esc_url_raw( $role_val );
                            }
                        }
                    }
                    $prompt = str_replace( '{{' . $slug . '}}', '', $prompt );
                    break;

                default:
                    $prompt = str_replace( '{{' . $slug . '}}', sanitize_text_field( $value ), $prompt );
                    break;
            }
        }

        // ── 5. Clean up unreplaced variables ──
        $prompt = preg_replace( '/\{\{[a-z_]+\}\}/', '', $prompt );
        $prompt = preg_replace( '/,\s*,+/', ',', $prompt );
        $prompt = preg_replace( '/\s+/', ' ', trim( $prompt ) );
        $prompt = trim( $prompt, ', ' );

        if ( ! empty( $tpl['negative_prompt'] ) ) {
            $prompt .= "\n\nNegative: " . $tpl['negative_prompt'];
        }

        // ── 6. Select model based on mode ──
        if ( ! empty( $overrides['model'] ) ) {
            $model = $overrides['model'];
        } elseif ( ! empty( $creation_mode ) && ! empty( $extra[ 'recommended_model_' . $creation_mode ] ) ) {
            $model = $extra[ 'recommended_model_' . $creation_mode ];
        } else {
            $model = $tpl['recommended_model'];
        }

        // ── 7. Determine size ──
        $size = $overrides['size'] ?? $form_data['aspect_ratio'] ?? $tpl['recommended_size'];

        // ── 8. Build slots ──
        $slots = array(
            'prompt' => $prompt,
            'model'  => $model,
            'size'   => $size,
            'style'  => $overrides['style'] ?? $tpl['style'],
        );

        // ── 9. Assemble reference images by mode ──
        if ( $creation_mode === 'composite' ) {
            $ordered = array();
            // model_picker images first (the person/base)
            foreach ( $form_fields as $field ) {
                $s = $field['slug'] ?? '';
                if ( ( $field['type'] ?? '' ) === 'model_picker' && ! empty( $ref_images[ $s ] ) ) {
                    $ordered[] = $ref_images[ $s ];
                }
            }
            // then image_upload images (product/clothing/accessory)
            foreach ( $form_fields as $field ) {
                $s = $field['slug'] ?? '';
                if ( ( $field['type'] ?? '' ) === 'image_upload' && ! empty( $ref_images[ $s ] ) ) {
                    $ordered[] = $ref_images[ $s ];
                }
            }
            // then multi_reference_images
            foreach ( $ref_images as $key => $url ) {
                if ( strpos( $key, 'ref_' ) === 0 && ! in_array( $url, $ordered, true ) ) {
                    $ordered[] = $url;
                }
            }
            $slots['creation_mode'] = ! empty( $ordered ) ? 'reference' : 'text';
            $slots['image_url']     = $ordered[0] ?? '';
            if ( count( $ordered ) > 1 ) {
                $slots['ref_images'] = $ordered;
            }
        } else {
            $all_refs = array_values( array_filter( $ref_images ) );
            $slots['creation_mode'] = ! empty( $all_refs ) ? 'reference' : 'text';
            $slots['image_url']     = $all_refs[0] ?? '';
            if ( count( $all_refs ) > 1 ) {
                $slots['ref_images'] = $all_refs;
            }
        }

        self::increment_use_count( $template_id );

        return $slots;
    }

    /**
     * Look up model_description from model_library in extra_data, or from DB model row.
     */
    private static function lookup_model_description( $tpl, $extra, $model_id ) {
        // Check model_library in extra_data
        $models = $extra['model_library_meta']['models'] ?? array();
        foreach ( $models as $m ) {
            if ( ( $m['id'] ?? '' ) === $model_id ) {
                return $m['model_description'] ?? $m['name'] ?? 'a person';
            }
        }
        // Fallback: DB model row by slug
        $model_row = self::get_by_slug( $model_id );
        if ( $model_row && ! empty( $model_row['form_fields']['model_description'] ) ) {
            return $model_row['form_fields']['model_description'];
        }
        return 'a person';
    }

    /* ═══════════════════════════════ PRIVATE ═══════════════════════════════ */

    private static function sanitize_row( $data, $partial = false ) {
        $fields = array(
            'slug'              => 'sanitize_title',
            'category_id'       => 'absint',
            'subcategory'       => 'sanitize_text_field',
            'title'             => 'sanitize_text_field',
            'description'       => 'wp_kses_post',
            'thumbnail_url'     => 'esc_url_raw',
            'badge_text'        => 'sanitize_text_field',
            'badge_color'       => null,  // handled explicitly
            'tags'              => 'sanitize_text_field',
            'prompt_template'   => 'wp_kses_post',
            'negative_prompt'   => 'wp_kses_post',
            'form_fields'       => null,  // JSON
            'recommended_model' => 'sanitize_text_field',
            'recommended_size'  => 'sanitize_text_field',
            'style'             => 'sanitize_text_field',
            'num_outputs'       => 'absint',
            'is_featured'       => null,
            'sort_order'        => 'intval',
            'status'            => null,
            'version'           => 'sanitize_text_field',
            'extra_data'        => null,
        );

        $row = array();
        foreach ( $fields as $key => $sanitizer ) {
            if ( ! array_key_exists( $key, $data ) ) {
                if ( ! $partial && in_array( $key, array( 'title', 'prompt_template' ), true ) ) {
                    return new \WP_Error( 'missing_fields', "Field '{$key}' is required." );
                }
                continue;
            }

            $val = $data[ $key ];

            if ( $key === 'form_fields' ) {
                if ( is_string( $val ) ) {
                    $decoded = json_decode( $val, true );
                    $row[ $key ] = ( $decoded !== null || $val === 'null' ) ? $val : '[]';
                } else {
                    $row[ $key ] = wp_json_encode( $val );
                }
            } elseif ( $key === 'badge_color' ) {
                $row[ $key ] = sanitize_hex_color( $val ) ?: '';
            } elseif ( $key === 'is_featured' ) {
                $row[ $key ] = $val ? 1 : 0;
            } elseif ( $key === 'extra_data' ) {
                if ( is_string( $val ) ) {
                    $row[ $key ] = $val;
                } else {
                    $row[ $key ] = wp_json_encode( $val );
                }
            } elseif ( $key === 'status' ) {
                $row[ $key ] = in_array( $val, array( 'active', 'draft' ), true ) ? $val : 'active';
            } elseif ( $sanitizer ) {
                $row[ $key ] = call_user_func( $sanitizer, $val );
            }
        }

        return $row;
    }

    private static function unique_slug( $slug ) {
        global $wpdb;
        $table    = self::table();
        $original = sanitize_title( $slug );
        $slug     = $original;
        $i        = 1;

        while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
            $slug = $original . '-' . $i;
            $i++;
        }
        return $slug;
    }
}
