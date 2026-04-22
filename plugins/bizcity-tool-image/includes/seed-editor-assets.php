<?php
/**
 * Auto-seed editor assets from canva-editor mock-api JSON + local images.
 *
 * Reads JSON files from canva-editor mock-api, resolves image URLs to local
 * files, uploads to WP Media Library, then inserts into editor asset tables.
 *
 * Image path mapping:
 *   http://localhost:4000/images/photos/christmas/002.jpg
 *   → {MOCK_API_PUBLIC}/images/photos/christmas/002.jpg
 *
 * @package BizCity_Tool_Image
 * @since   3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base directory for mock-api JSON and public assets.
 *
 * Primary:  design-editor-build/mock-api/  (copied by build:wp)
 * Fallback: canva-editor_v1.0.69/apps/mock-api/  (source monorepo)
 */
function bztimg_mock_api_dir() {
    $primary = BZTIMG_DIR . 'design-editor-build/mock-api/';
    if ( is_dir( $primary . 'src/json' ) ) {
        return $primary;
    }
    // Fallback: source monorepo on dev machines
   
    return $primary; // return primary even if missing, so error is logged downstream
}

/**
 * Resolve a mock-api image URL to a local file path.
 * http://localhost:4000/images/shapes/001.png → .../public/images/shapes/001.png
 */
function bztimg_resolve_local_image( $url ) {
    if ( empty( $url ) ) return '';
    $prefix = 'http://localhost:4000/';
    if ( strpos( $url, $prefix ) !== 0 ) return '';
    $relative = substr( $url, strlen( $prefix ) );
    $local    = bztimg_mock_api_dir() . 'public/' . $relative;
    return file_exists( $local ) ? $local : '';
}

/**
 * Upload a local file to WP Media Library using media_handle_sideload.
 * Returns attachment_id on success, 0 on failure.
 */
function bztimg_sideload_local_image( $local_path, $title = '' ) {
    if ( empty( $local_path ) || ! file_exists( $local_path ) ) return 0;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Copy to temp so WP can move it
    $tmp = wp_tempnam( basename( $local_path ) );
    if ( ! copy( $local_path, $tmp ) ) return 0;

    $file_array = array(
        'name'     => basename( $local_path ),
        'tmp_name' => $tmp,
        'size'     => filesize( $tmp ),
        'error'    => 0,
    );

    $attachment_id = media_handle_sideload( $file_array, 0, $title );
    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp );
        return 0;
    }

    // Tag as editor asset upload
    update_post_meta( $attachment_id, '_bztimg_editor_asset', '1' );

    return (int) $attachment_id;
}

/**
 * Read and decode a JSON file from mock-api/src/json/.
 */
function bztimg_read_mock_json( $filename ) {
    $path = bztimg_mock_api_dir() . 'src/json/' . $filename;
    if ( ! file_exists( $path ) ) return null;
    $raw = file_get_contents( $path );
    if ( ! $raw ) return null;
    return json_decode( $raw, true );
}

/**
 * Static cache: localhost URL → WP attachment URL.
 * Prevents re-uploading the same image when multiple templates share a photo.
 */
function bztimg_get_url_cache() {
    static $cache = null;
    if ( $cache === null ) $cache = array();
    return $cache;
}

function &bztimg_url_cache_ref() {
    static $cache = array();
    return $cache;
}

/**
 * Upload a localhost:4000 image to WP Media, with caching.
 * Returns the WP attachment URL, or empty string on failure.
 *
 * @param string $localhost_url e.g. http://localhost:4000/images/photos/christmas/002.jpg
 * @return string WP Media URL or empty string
 */
function bztimg_upload_cached( $localhost_url ) {
    if ( empty( $localhost_url ) ) return '';
    $cache = &bztimg_url_cache_ref();

    // Already uploaded this URL in this session?
    if ( isset( $cache[ $localhost_url ] ) ) {
        return $cache[ $localhost_url ];
    }

    // Check if already uploaded previously (by postmeta)
    $relative = str_replace( 'http://localhost:4000/', '', $localhost_url );
    global $wpdb;
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
         WHERE m.meta_key = '_bztimg_source_url' AND m.meta_value = %s
         LIMIT 1",
        $localhost_url
    ) );
    if ( $existing ) {
        $wp_url = wp_get_attachment_url( $existing->ID );
        if ( $wp_url ) {
            $cache[ $localhost_url ] = $wp_url;
            return $wp_url;
        }
    }

    // Resolve to local file and upload
    $local_path = bztimg_resolve_local_image( $localhost_url );
    if ( ! $local_path ) {
        $cache[ $localhost_url ] = '';
        return '';
    }

    $title = sanitize_title( pathinfo( $relative, PATHINFO_FILENAME ) );
    $att_id = bztimg_sideload_local_image( $local_path, $title );
    if ( ! $att_id ) {
        $cache[ $localhost_url ] = '';
        return '';
    }

    // Tag with source URL for future dedup
    update_post_meta( $att_id, '_bztimg_source_url', $localhost_url );

    $wp_url = wp_get_attachment_url( $att_id );
    $cache[ $localhost_url ] = $wp_url ?: '';
    return $cache[ $localhost_url ];
}

/**
 * Scan a data string (JSON) for all http://localhost:4000/ URLs,
 * upload each to WP Media, and replace with WP attachment URLs.
 *
 * @param string $data_str JSON string containing localhost URLs
 * @return string JSON string with URLs replaced
 */
function bztimg_rewrite_localhost_urls_in_data( $data_str ) {
    if ( strpos( $data_str, 'localhost:4000' ) === false ) {
        return $data_str;
    }

    // Find all unique localhost URLs
    preg_match_all( '#http://localhost:4000/[^"\\\\]+#', $data_str, $matches );
    if ( empty( $matches[0] ) ) return $data_str;

    $urls = array_unique( $matches[0] );
    foreach ( $urls as $url ) {
        $wp_url = bztimg_upload_cached( $url );
        if ( $wp_url ) {
            $data_str = str_replace( $url, $wp_url, $data_str );
        }
    }

    return $data_str;
}

/* ═══════════════════════════════════════════════════════════════
   SEED FUNCTIONS — one per asset type
   ═══════════════════════════════════════════════════════════════ */

/**
 * Seed shapes from shapes.json.
 * Insert-only (skips existing rows).
 */
function bztimg_seed_editor_shapes() {
    return bztimg_reseed_editor_shapes( false );
}

/**
 * Re-seed (upsert) shapes from shapes.json.
 *
 * @param bool $force_update  true = update existing rows (re-upload thumb + update link).
 *                            false = skip existing (default insert-only behaviour).
 * @return array { inserted, updated }
 */
function bztimg_reseed_editor_shapes( bool $force_update = true ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_editor_shapes';

    $json = bztimg_read_mock_json( 'shapes.json' );
    if ( ! $json || empty( $json['data'] ) ) {
        return array( 'inserted' => 0, 'updated' => 0, 'error' => 'shapes.json not found or empty' );
    }

    $inserted = 0;
    $updated  = 0;

    foreach ( $json['data'] as $i => $item ) {
        if ( empty( $item['clipPath'] ) ) continue;

        $hash  = md5( $item['clipPath'] );
        $label = 'shape-' . str_pad( $i + 1, 3, '0', STR_PAD_LEFT );

        // Always (re-)upload the thumbnail from local file
        $att_id  = 0;
        $img_url = '';
        if ( ! empty( $item['img']['url'] ) ) {
            $local = bztimg_resolve_local_image( $item['img']['url'] );
            if ( $local ) {
                $att_id = bztimg_sideload_local_image( $local, $label );
                if ( $att_id ) {
                    $img_url = wp_get_attachment_url( $att_id );
                }
            }
        }

        $row_data = array(
            'clip_path'     => $item['clipPath'],
            'description'   => sanitize_text_field( $item['desc'] ?? '' ),
            'background'    => sanitize_text_field( $item['background'] ?? '' ),
            'width'         => (int) ( $item['width'] ?? 256 ),
            'height'        => (int) ( $item['height'] ?? 256 ),
            'img_url'       => $img_url,
            'attachment_id' => $att_id,
            'content_hash'  => $hash,
            'sort_order'    => $i,
        );
        $row_fmt = array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d' );

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
        ) );

        if ( $existing_id ) {
            if ( $force_update ) {
                $wpdb->update( $table, $row_data, array( 'id' => $existing_id ), $row_fmt, array( '%d' ) );
                $updated++;
            }
            // If !$force_update: skip (original behaviour)
        } else {
            $wpdb->insert( $table, $row_data, $row_fmt );
            if ( $wpdb->insert_id ) $inserted++;
        }
    }

    return array( 'inserted' => $inserted, 'updated' => $updated );
}

/**
 * Seed frames from frames.json.
 */
function bztimg_seed_editor_frames() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_editor_frames';

    $json = bztimg_read_mock_json( 'frames.json' );
    if ( ! $json || empty( $json['data'] ) ) return 0;

    $count = 0;
    foreach ( $json['data'] as $i => $item ) {
        if ( empty( $item['clipPath'] ) ) continue;

        $hash = md5( $item['clipPath'] );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
        ) );
        if ( $exists ) continue;

        $att_id  = 0;
        $img_url = '';
        if ( ! empty( $item['img']['url'] ) ) {
            $local = bztimg_resolve_local_image( $item['img']['url'] );
            if ( $local ) {
                $att_id = bztimg_sideload_local_image( $local, 'frame-' . str_pad( $i + 1, 3, '0', STR_PAD_LEFT ) );
                if ( $att_id ) {
                    $img_url = wp_get_attachment_url( $att_id );
                }
            }
        }

        $wpdb->insert( $table, array(
            'clip_path'    => $item['clipPath'],
            'description'  => sanitize_text_field( $item['desc'] ?? '' ),
            'width'        => (int) ( $item['width'] ?? 256 ),
            'height'       => (int) ( $item['height'] ?? 256 ),
            'img_url'      => $img_url,
            'attachment_id' => $att_id,
            'content_hash' => $hash,
            'sort_order'   => $i,
        ), array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d' ) );

        if ( $wpdb->insert_id ) $count++;
    }

    return $count;
}

/**
 * Seed fonts from draft-fonts.json (simpler format: family + styles).
 */
function bztimg_seed_editor_fonts() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_editor_fonts';

    $json = bztimg_read_mock_json( 'draft-fonts.json' );
    if ( ! $json || empty( $json['items'] ) ) {
        // Fallback to fonts.json (Google Fonts format)
        $json = bztimg_read_mock_json( 'fonts.json' );
    }
    if ( ! $json ) return 0;

    // Determine format
    $items = $json['items'] ?? $json['data'] ?? array();
    if ( empty( $items ) ) return 0;

    $count = 0;
    foreach ( $items as $i => $item ) {
        $family = $item['family'] ?? '';
        if ( ! $family ) continue;

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE family = %s LIMIT 1", $family
        ) );
        if ( $exists ) continue;

        // Build styles array depending on format
        $styles = array();
        if ( isset( $item['styles'] ) ) {
            // draft-fonts.json format: { family, styles: [{name, style, url}] }
            $styles = $item['styles'];
        } elseif ( isset( $item['files'] ) ) {
            // Google Fonts format: { family, variants, files: {variant: url} }
            foreach ( $item['files'] as $variant => $url ) {
                $styles[] = array(
                    'name'  => $family . ' ' . ucfirst( $variant ),
                    'style' => $variant,
                    'url'   => $url,
                );
            }
        }

        $wpdb->insert( $table, array(
            'family'     => sanitize_text_field( $family ),
            'styles_json' => wp_json_encode( $styles ),
            'sort_order' => $i,
        ), array( '%s', '%s', '%d' ) );

        if ( $wpdb->insert_id ) $count++;
    }

    return $count;
}

/**
 * Seed text presets from texts.json.
 */
function bztimg_seed_editor_texts() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_editor_text_presets';

    $json = bztimg_read_mock_json( 'texts.json' );
    if ( ! $json || empty( $json['data'] ) ) return 0;

    $count = 0;
    foreach ( $json['data'] as $i => $item ) {
        $data = $item['data'] ?? null;
        if ( ! $data ) continue;

        $data_str = is_string( $data ) ? $data : wp_json_encode( $data );

        // Upload all localhost:4000 images inside text data and replace URLs
        $data_str = bztimg_rewrite_localhost_urls_in_data( $data_str );

        $hash     = md5( $data_str );
        $exists   = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
        ) );
        if ( $exists ) continue;

        $att_id  = 0;
        $img_url = '';
        if ( ! empty( $item['img']['url'] ) ) {
            $local = bztimg_resolve_local_image( $item['img']['url'] );
            if ( $local ) {
                $att_id = bztimg_sideload_local_image( $local, 'text-' . sanitize_title( $item['desc'] ?? $i ) );
                if ( $att_id ) {
                    $img_url = wp_get_attachment_url( $att_id );
                }
            }
        }

        $wpdb->insert( $table, array(
            'description'  => sanitize_text_field( $item['desc'] ?? '' ),
            'data_json'    => $data_str,
            'width'        => (int) ( $item['width'] ?? 256 ),
            'height'       => (int) ( $item['height'] ?? 256 ),
            'img_url'      => $img_url,
            'attachment_id' => $att_id,
            'content_hash' => $hash,
            'sort_order'   => $i,
        ), array( '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d' ) );

        if ( $wpdb->insert_id ) $count++;
    }

    return $count;
}

/**
 * Seed editor templates from individual template JSON files (templates/00.json - 12.json).
 * Uses Format B (native canva-editor: { name, notes, layers: { ROOT: ... } }).
 * Also uploads thumbnail from templates/ images.
 */
function bztimg_seed_editor_templates() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_editor_templates';

    $tpl_dir = bztimg_mock_api_dir() . 'src/json/templates/';
    if ( ! is_dir( $tpl_dir ) ) return 0;

    $files = glob( $tpl_dir . '*.json' );
    if ( empty( $files ) ) return 0;

    $count = 0;
    foreach ( $files as $sort => $file ) {
        $raw = file_get_contents( $file );
        if ( ! $raw ) continue;

        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) continue;

        // Each template JSON is an array with one item
        $items = isset( $json[0]['layers'] ) ? $json : array( $json );

        foreach ( $items as $item ) {
            if ( empty( $item['layers'] ) ) continue;

            $data_str = wp_json_encode( $item );

            // Upload all localhost:4000 images inside template data and replace URLs
            $data_str = bztimg_rewrite_localhost_urls_in_data( $data_str );

            $hash     = md5( $data_str );
            $exists   = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
            ) );
            if ( $exists ) continue;

            // Extract dimensions from ROOT layer
            $width  = 256;
            $height = 256;
            if ( isset( $item['layers']['ROOT']['props']['boxSize'] ) ) {
                $box    = $item['layers']['ROOT']['props']['boxSize'];
                $width  = (int) ( $box['width'] ?? 256 );
                $height = (int) ( $box['height'] ?? 256 );
            }

            // Upload thumbnail: templates/01.png matches templates/01.json
            $att_id  = 0;
            $img_url = '';
            $basename = pathinfo( $file, PATHINFO_FILENAME ); // "00", "01", etc.
            $thumb_path = bztimg_mock_api_dir() . 'public/images/templates/' . $basename . '.png';
            if ( file_exists( $thumb_path ) ) {
                $att_id = bztimg_sideload_local_image( $thumb_path, 'editor-template-' . $basename );
                if ( $att_id ) {
                    $img_url = wp_get_attachment_url( $att_id );
                }
            }

            $desc = sanitize_text_field( $item['name'] ?? $item['notes'] ?? '' );

            $wpdb->insert( $table, array(
                'description'  => $desc,
                'data_json'    => $data_str,
                'pages'        => 1,
                'width'        => $width,
                'height'       => $height,
                'img_url'      => $img_url,
                'attachment_id' => $att_id,
                'content_hash' => $hash,
                'sort_order'   => $sort,
            ), array( '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%d' ) );

            if ( $wpdb->insert_id ) $count++;
        }
    }

    return $count;
}

/* ═══════════════════════════════════════════════════════════════
   MASTER SEED — runs all asset types, returns summary
   ═══════════════════════════════════════════════════════════════ */

/**
 * Seed all editor assets from mock-api data.
 * Safe to call multiple times — dedup by content_hash / family.
 *
 * @return array Summary of seeded counts per type.
 */
function bztimg_seed_all_editor_assets() {
    $mock_dir = bztimg_mock_api_dir();
    if ( ! is_dir( $mock_dir ) ) {
        error_log( '[bztimg-seed] Mock-API dir not found: ' . $mock_dir );
        return array(
            'shapes' => 0, 'frames' => 0, 'fonts' => 0,
            'texts' => 0, 'templates' => 0,
            'error' => 'Mock-API dir not found: ' . $mock_dir,
        );
    }

    error_log( '[bztimg-seed] Starting seed from: ' . $mock_dir );

    $result = array(
        'shapes'    => ( function() { $r = bztimg_seed_editor_shapes(); return $r['inserted'] ?? (int)$r; } )(),
        'frames'    => bztimg_seed_editor_frames(),
        'fonts'     => bztimg_seed_editor_fonts(),
        'texts'     => bztimg_seed_editor_texts(),
        'templates' => bztimg_seed_editor_templates(),
    );

    error_log( '[bztimg-seed] Done: ' . wp_json_encode( $result ) );
    return $result;
}
