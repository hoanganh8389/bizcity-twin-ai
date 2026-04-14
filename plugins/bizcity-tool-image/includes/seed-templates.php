<?php
/**
 * Seed default templates from the existing prompt library.
 *
 * Called once during install if templates table is empty.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function bztimg_seed_templates() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_templates';

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count > 0 ) return;

    // Map prompt library categories to template categories
    $cat_map = array(
        'portrait'               => 'portrait',
        'product'                => 'on-hand',
        'landscape'              => 'background',
        'social_media'           => 'social-media',
        'artistic'               => 'concepts',
        'interior_architecture'  => 'concepts',
        'branding'               => 'branding',
        'concept_art'            => 'concepts',
    );

    // Resolve category IDs
    $cat_ids = array();
    foreach ( $cat_map as $src => $slug ) {
        $cat = BizCity_Template_Category_Manager::get_by_slug( $slug );
        $cat_ids[ $src ] = $cat ? (int) $cat['id'] : 0;
    }

    if ( ! class_exists( 'BizCity_Tool_Image' ) ) return;
    $library = BizCity_Tool_Image::get_prompt_library();

    $sort = 0;
    foreach ( $library as $cat_key => $category ) {
        $category_id = $cat_ids[ $cat_key ] ?? 0;

        foreach ( $category['prompts'] as $p ) {
            $sort++;

            $form_fields = array(
                array(
                    'slug'        => 'custom_detail',
                    'label'       => 'Chi tiết thêm (tuỳ chọn)',
                    'type'        => 'textarea',
                    'placeholder' => 'VD: thêm hoa hồng, màu xanh dương...',
                    'required'    => false,
                    'grid'        => 'full',
                    'sort_order'  => 1,
                    'options'     => array(),
                ),
            );

            // For product and portrait categories, add image upload field
            if ( in_array( $cat_key, array( 'product', 'portrait' ), true ) ) {
                array_unshift( $form_fields, array(
                    'slug'        => 'reference_image',
                    'label'       => 'Ảnh tham khảo',
                    'type'        => 'image_upload',
                    'placeholder' => '',
                    'required'    => false,
                    'grid'        => 'full',
                    'sort_order'  => 0,
                    'options'     => array(),
                ) );
            }

            $prompt = $p['prompt'];
            if ( strpos( $prompt, '{{custom_detail}}' ) === false ) {
                $prompt .= ', {{custom_detail}}';
            }

            $wpdb->insert( $table, array(
                'slug'              => sanitize_title( $p['title'] ),
                'category_id'       => $category_id,
                'subcategory'       => $cat_key,
                'title'             => $p['title'],
                'description'       => ( $category['icon'] ?? '' ) . ' ' . ( $category['label'] ?? '' ),
                'prompt_template'   => $prompt,
                'negative_prompt'   => '',
                'form_fields'       => wp_json_encode( $form_fields ),
                'recommended_model' => 'flux-pro',
                'recommended_size'  => in_array( $cat_key, array( 'social_media' ), true ) ? '1024x1792' : '1024x1024',
                'style'             => 'auto',
                'num_outputs'       => 1,
                'is_featured'       => $sort <= 12 ? 1 : 0,
                'sort_order'        => $sort,
                'status'            => 'active',
                'tags'              => $cat_key . ',' . sanitize_title( $p['title'] ),
                'author_id'         => 0,
            ) );
        }
    }
}

/**
 * Seed templates from JSON files in the data/ directory.
 *
 * Import library items (model, clothing, accessory, background) from a bztimg_template JSON.
 * Called from AJAX import handler to process uploaded data directly.
 *
 * @param array $json Decoded bztimg_template JSON.
 * @return int Number of library items imported/updated.
 */
function bztimg_import_library_items( $json ) {
    if ( ( $json['_meta']['schema'] ?? '' ) !== 'bztimg_template' ) return 0;

    $category_slug = $json['template']['category_slug'] ?? '';
    if ( ! $category_slug ) return 0;

    $cat    = BizCity_Template_Category_Manager::get_by_slug( $category_slug );
    $cat_id = $cat ? (int) $cat['id'] : 0;

    global $wpdb;
    $table      = $wpdb->prefix . 'bztimg_templates';
    $parent_slug = $json['template']['slug'] ?? '';
    $count      = 0;

    // Library type → [ json_key, items_key, subcategory, description_key, extra_fields_fn ]
    $libraries = array(
        array( 'model_library',      'models',      'model',      function( $item, $parent ) {
            return array(
                'form_fields'      => wp_json_encode( array(
                    'parent_slug'       => $parent,
                    'model_description' => $item['model_description'] ?? '',
                    'gender'            => $item['gender'] ?? '',
                    'age_group'         => $item['age_group'] ?? '',
                    'ethnicity'         => $item['ethnicity'] ?? '',
                    'style'             => $item['style'] ?? '',
                ) ),
                'description'      => $item['model_description'] ?? '',
                'thumbnail_url'    => $item['preview_url'] ?? '',
                'prompt_template'  => $item['model_description'] ?? '',
                'recommended_model'=> 'flux-kontext',
                'recommended_size' => '1024x1536',
            );
        }),
        array( 'background_library', 'backgrounds', 'background', function( $item, $parent ) {
            return array(
                'form_fields'      => wp_json_encode( array(
                    'parent_slug'    => $parent,
                    'bg_description' => $item['bg_description'] ?? '',
                    'bg_style'       => $item['style'] ?? '',
                ) ),
                'description'      => $item['bg_description'] ?? '',
                'thumbnail_url'    => $item['preview_url'] ?? '',
                'prompt_template'  => $item['bg_description'] ?? '',
                'recommended_model'=> 'flux-kontext',
                'recommended_size' => '1024x1024',
            );
        }),
        array( 'clothing_library',   'items',       'clothing',   function( $item, $parent ) {
            return array(
                'form_fields'      => wp_json_encode( array(
                    'parent_slug'       => $parent,
                    'clothing_name'     => $item['name'] ?? '',
                    'clothing_category' => $item['category'] ?? '',
                    'suitable_modes'    => $item['suitable_modes'] ?? array(),
                    'gender'            => $item['gender'] ?? 'unisex',
                    'color'             => $item['color'] ?? '',
                ) ),
                'description'      => $item['name'] ?? '',
                'thumbnail_url'    => $item['thumbnail'] ?? '',
                'prompt_template'  => $item['name'] ?? '',
                'recommended_model'=> 'flux-kontext',
                'recommended_size' => '1024x1536',
            );
        }),
        array( 'accessory_library',  'items',       'accessory',  function( $item, $parent ) {
            return array(
                'form_fields'      => wp_json_encode( array(
                    'parent_slug'    => $parent,
                    'accessory_name' => $item['name'] ?? '',
                    'accessory_type' => $item['type'] ?? '',
                    'gender'         => $item['gender'] ?? 'unisex',
                    'color'          => $item['color'] ?? '',
                ) ),
                'description'      => $item['name'] ?? '',
                'thumbnail_url'    => $item['thumbnail'] ?? '',
                'prompt_template'  => $item['name'] ?? '',
                'recommended_model'=> 'gpt-image',
                'recommended_size' => '1024x1024',
            );
        }),
    );

    foreach ( $libraries as $lib ) {
        list( $lib_key, $items_key, $subcategory, $build_fn ) = $lib;
        $items = $json[ $lib_key ][ $items_key ] ?? array();
        if ( empty( $items ) ) continue;

        foreach ( $items as $item ) {
            $item_id = $item['id'] ?? '';
            if ( ! $item_id ) continue;

            $extra = $build_fn( $item, $parent_slug );
            $row   = array_merge( array(
                'slug'        => sanitize_title( $item_id ),
                'category_id' => $cat_id,
                'subcategory' => $subcategory,
                'title'       => $item['name'] ?? $item_id,
                'tags'        => implode( ',', $item['tags'] ?? array() ),
                'style'       => 'photorealistic',
                'num_outputs' => 1,
                'is_featured' => ( ( $item['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                'sort_order'  => (int) ( $item['sort_order'] ?? 99 ),
                'status'      => $item['status'] ?? 'active',
                'author_id'   => 0,
            ), $extra );

            $exists = BizCity_Template_Manager::get_by_slug( $item_id );
            if ( $exists ) {
                unset( $row['slug'], $row['category_id'], $row['author_id'] );
                $wpdb->update( $table, $row, array( 'id' => $exists['id'] ) );
            } else {
                $result = $wpdb->insert( $table, $row );
                if ( false === $result ) {
                    error_log( '[bztimg] Failed to insert library item: ' . $item_id . ' — ' . $wpdb->last_error );
                }
            }
            $count++;
        }
    }
    return $count;
}

/**
 * Scans BZTIMG_DIR . 'data/*.json', imports each file that uses
 * the bztimg_template schema. Uses upsert: if slug exists and version
 * is different → update; if same version → skip; if new → insert.
 * Also seeds model_library items as individual template records (subcategory='model').
 * Safe to call multiple times — idempotent by slug + version.
 */
function bztimg_seed_json_templates() {
    $data_dir = BZTIMG_DIR . 'data/';
    if ( ! is_dir( $data_dir ) ) return;

    $files = glob( $data_dir . '*.json' );
    if ( empty( $files ) ) return;

    foreach ( $files as $file ) {
        $raw = file_get_contents( $file );
        if ( ! $raw ) continue;

        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) continue;

        // Only handle bztimg_template schema
        if ( ( $json['_meta']['schema'] ?? '' ) !== 'bztimg_template' ) continue;

        $slug    = $json['template']['slug'] ?? '';
        $version = $json['_meta']['version'] ?? '';
        if ( ! $slug ) continue;

        // Upsert main template record (force=true → always update)
        $result = BizCity_Template_Manager::import( $json, true );
        error_log( "[bztimg_seed] {$slug} v{$version} → imported={$result}" );

        // Resolve category for child library items
        $category_slug = $json['template']['category_slug'] ?? '';
        if ( ! $category_slug ) continue;

        $cat    = BizCity_Template_Category_Manager::get_by_slug( $category_slug );
        $cat_id = $cat ? (int) $cat['id'] : 0;

        global $wpdb;
        $table  = $wpdb->prefix . 'bztimg_templates';
        $tpl_slug = $slug; // parent slug for child rows

        // --- Seed model_library items (subcategory='model') ---
        if ( ! empty( $json['model_library']['models'] ) ) {
            foreach ( $json['model_library']['models'] as $model ) {
                $model_slug = $model['id'] ?? '';
                if ( ! $model_slug ) continue;

                $model_data = array(
                    'slug'          => sanitize_title( $model_slug ),
                    'category_id'   => $cat_id,
                    'subcategory'   => 'model',
                    'title'         => $model['name'] ?? $model_slug,
                    'description'   => $model['model_description'] ?? '',
                    'thumbnail_url' => $model['preview_url'] ?? '',
                    'tags'          => implode( ',', $model['tags'] ?? array() ),
                    'form_fields'   => wp_json_encode( array(
                        'parent_slug'       => $tpl_slug,
                        'model_description' => $model['model_description'] ?? '',
                        'gender'            => $model['gender'] ?? '',
                        'age_group'         => $model['age_group'] ?? '',
                        'ethnicity'         => $model['ethnicity'] ?? '',
                        'style'             => $model['style'] ?? '',
                    ) ),
                    'prompt_template'  => $model['model_description'] ?? '',
                    'recommended_model'=> 'flux-kontext',
                    'recommended_size' => '1024x1536',
                    'style'            => 'photorealistic',
                    'num_outputs'      => 1,
                    'is_featured'      => ( ( $model['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                    'sort_order'       => (int) ( $model['sort_order'] ?? 99 ),
                    'status'           => $model['status'] ?? 'active',
                    'author_id'        => 0,
                );
                $format = array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%d' );

                $exists = BizCity_Template_Manager::get_by_slug( $model_slug );
                if ( $exists ) {
                    $wpdb->update( $table, array(
                        'subcategory'      => 'model',
                        'title'            => $model_data['title'],
                        'description'      => $model_data['description'],
                        'thumbnail_url'    => $model_data['thumbnail_url'],
                        'tags'             => $model_data['tags'],
                        'form_fields'      => $model_data['form_fields'],
                        'prompt_template'  => $model_data['prompt_template'],
                        'recommended_model'=> $model_data['recommended_model'],
                        'recommended_size' => $model_data['recommended_size'],
                        'style'            => $model_data['style'],
                        'status'           => $model_data['status'],
                    ), array( 'id' => $exists['id'] ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' ) );
                } else {
                    $wpdb->insert( $table, $model_data, $format );
                }
            }
        }

        // --- Seed clothing_library items (subcategory='clothing') ---
        if ( ! empty( $json['clothing_library']['items'] ) ) {
            foreach ( $json['clothing_library']['items'] as $item ) {
                $item_slug = $item['id'] ?? '';
                if ( ! $item_slug ) continue;

                $item_data = array(
                    'slug'          => sanitize_title( $item_slug ),
                    'category_id'   => $cat_id,
                    'subcategory'   => 'clothing',
                    'title'         => $item['name'] ?? $item_slug,
                    'description'   => $item['name'] ?? '',
                    'thumbnail_url' => $item['thumbnail'] ?? '',
                    'tags'          => implode( ',', array_filter( array( $item['category'] ?? '', $item['gender'] ?? '', $item['color'] ?? '' ) ) ),
                    'form_fields'   => wp_json_encode( array(
                        'parent_slug'       => $tpl_slug,
                        'clothing_name'     => $item['name'] ?? '',
                        'clothing_category' => $item['category'] ?? '',
                        'suitable_modes'    => $item['suitable_modes'] ?? array(),
                        'gender'            => $item['gender'] ?? 'unisex',
                        'color'             => $item['color'] ?? '',
                    ) ),
                    'prompt_template'  => $item['name'] ?? '',
                    'recommended_model'=> 'flux-kontext',
                    'recommended_size' => '1024x1536',
                    'style'            => 'photorealistic',
                    'num_outputs'      => 1,
                    'is_featured'      => ( ( $item['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                    'sort_order'       => (int) ( $item['sort_order'] ?? 99 ),
                    'status'           => 'active',
                    'author_id'        => 0,
                );
                $format = array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%d' );

                $exists = BizCity_Template_Manager::get_by_slug( $item_slug );
                if ( $exists ) {
                    $wpdb->update( $table, array(
                        'subcategory'      => 'clothing',
                        'title'            => $item_data['title'],
                        'description'      => $item_data['description'],
                        'thumbnail_url'    => $item_data['thumbnail_url'],
                        'tags'             => $item_data['tags'],
                        'form_fields'      => $item_data['form_fields'],
                        'prompt_template'  => $item_data['prompt_template'],
                        'recommended_model'=> $item_data['recommended_model'],
                        'recommended_size' => $item_data['recommended_size'],
                        'style'            => $item_data['style'],
                        'status'           => $item_data['status'],
                    ), array( 'id' => $exists['id'] ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' ) );
                } else {
                    $wpdb->insert( $table, $item_data, $format );
                }
            }
        }

        // --- Seed accessory_library items (subcategory='accessory') ---
        if ( ! empty( $json['accessory_library']['items'] ) ) {
            foreach ( $json['accessory_library']['items'] as $item ) {
                $item_slug = $item['id'] ?? '';
                if ( ! $item_slug ) continue;

                $item_data = array(
                    'slug'          => sanitize_title( $item_slug ),
                    'category_id'   => $cat_id,
                    'subcategory'   => 'accessory',
                    'title'         => $item['name'] ?? $item_slug,
                    'description'   => $item['name'] ?? '',
                    'thumbnail_url' => $item['thumbnail'] ?? '',
                    'tags'          => implode( ',', array_filter( array( $item['type'] ?? '', $item['gender'] ?? '', $item['color'] ?? '' ) ) ),
                    'form_fields'   => wp_json_encode( array(
                        'parent_slug'    => $tpl_slug,
                        'accessory_name' => $item['name'] ?? '',
                        'accessory_type' => $item['type'] ?? '',
                        'gender'         => $item['gender'] ?? 'unisex',
                        'color'          => $item['color'] ?? '',
                    ) ),
                    'prompt_template'  => $item['name'] ?? '',
                    'recommended_model'=> 'gpt-image',
                    'recommended_size' => '1024x1024',
                    'style'            => 'photorealistic',
                    'num_outputs'      => 1,
                    'is_featured'      => ( ( $item['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                    'sort_order'       => (int) ( $item['sort_order'] ?? 99 ),
                    'status'           => 'active',
                    'author_id'        => 0,
                );
                $format = array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%d' );

                $exists = BizCity_Template_Manager::get_by_slug( $item_slug );
                if ( $exists ) {
                    $wpdb->update( $table, array(
                        'subcategory'      => 'accessory',
                        'title'            => $item_data['title'],
                        'description'      => $item_data['description'],
                        'thumbnail_url'    => $item_data['thumbnail_url'],
                        'tags'             => $item_data['tags'],
                        'form_fields'      => $item_data['form_fields'],
                        'prompt_template'  => $item_data['prompt_template'],
                        'recommended_model'=> $item_data['recommended_model'],
                        'recommended_size' => $item_data['recommended_size'],
                        'style'            => $item_data['style'],
                        'status'           => $item_data['status'],
                    ), array( 'id' => $exists['id'] ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' ) );
                } else {
                    $wpdb->insert( $table, $item_data, $format );
                }
            }
        }

        // --- Seed background_library items (subcategory='background') ---
        if ( ! empty( $json['background_library']['backgrounds'] ) ) {
            foreach ( $json['background_library']['backgrounds'] as $bg ) {
                $bg_slug = $bg['id'] ?? '';
                if ( ! $bg_slug ) continue;

                $bg_data = array(
                    'slug'          => sanitize_title( $bg_slug ),
                    'category_id'   => $cat_id,
                    'subcategory'   => 'background',
                    'title'         => $bg['name'] ?? $bg_slug,
                    'description'   => $bg['bg_description'] ?? '',
                    'thumbnail_url' => $bg['preview_url'] ?? '',
                    'tags'          => implode( ',', $bg['tags'] ?? array() ),
                    'form_fields'   => wp_json_encode( array(
                        'parent_slug'    => $tpl_slug,
                        'bg_description' => $bg['bg_description'] ?? '',
                        'bg_style'       => $bg['style'] ?? '',
                    ) ),
                    'prompt_template'  => $bg['bg_description'] ?? '',
                    'recommended_model'=> 'flux-kontext',
                    'recommended_size' => '1024x1024',
                    'style'            => 'photorealistic',
                    'num_outputs'      => 1,
                    'is_featured'      => ( ( $bg['sort_order'] ?? 99 ) <= 6 ) ? 1 : 0,
                    'sort_order'       => (int) ( $bg['sort_order'] ?? 99 ),
                    'status'           => $bg['status'] ?? 'active',
                    'author_id'        => 0,
                );
                $format = array( '%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s','%d' );

                $exists = BizCity_Template_Manager::get_by_slug( $bg_slug );
                if ( $exists ) {
                    $wpdb->update( $table, array(
                        'subcategory'      => 'background',
                        'title'            => $bg_data['title'],
                        'description'      => $bg_data['description'],
                        'thumbnail_url'    => $bg_data['thumbnail_url'],
                        'tags'             => $bg_data['tags'],
                        'form_fields'      => $bg_data['form_fields'],
                        'prompt_template'  => $bg_data['prompt_template'],
                        'recommended_model'=> $bg_data['recommended_model'],
                        'recommended_size' => $bg_data['recommended_size'],
                        'style'            => $bg_data['style'],
                        'status'           => $bg_data['status'],
                    ), array( 'id' => $exists['id'] ),
                    array( '%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
                    array( '%d' ) );
                } else {
                    $wpdb->insert( $table, $bg_data, $format );
                }
            }
        }
    }
}
