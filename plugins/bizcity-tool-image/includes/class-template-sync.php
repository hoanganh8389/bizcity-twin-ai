<?php
/**
 * BizCity Tool Image — Hub Template Sync Engine (Phase IT-4)
 *
 * 1-button "Update Templates" sync between client and `bizcity-llm-router` hub.
 *
 * Architecture (R-GW-8):
 *   Client (this class) ──▶ BizCity_LLM_Client (Bearer biz-xxx)
 *                       ──▶ https://bizcity.vn/wp-json/bizcity/v1/image-library/{manifest,bundle}
 *
 * 2 source groups:
 *   - source='hub'   → auto-sync (skipped if protected_from_sync=1)
 *   - source='local' → never touched
 *
 * @package BizCity_Tool_Image
 * @since   3.7.2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Image_Template_Sync {

    const MANIFEST_OPT     = 'bztimg_hub_manifest_etag';
    const LAST_REPORT_OPT  = 'bztimg_hub_last_sync_report';
    const LAST_SYNC_AT_OPT = 'bztimg_hub_last_sync_at';
    const SAMPLES_OPT_PREFIX = 'bztimg_hub_samples_';

    /**
     * Types of editor assets handled.
     * Map type-key (used in bundle) → [client_table, unique_col, json_cols]
     */
    private static function editor_type_map() {
        global $wpdb;
        $bp = $wpdb->prefix;
        return array(
            'shapes' => array(
                'table'      => $bp . 'bztimg_editor_shapes',
                'unique_col' => 'content_hash',
                'cols'       => array( 'clip_path', 'description', 'background', 'width', 'height', 'img_url', 'sort_order' ),
                'json_cols'  => array(),
            ),
            'frames' => array(
                'table'      => $bp . 'bztimg_editor_frames',
                'unique_col' => 'content_hash',
                'cols'       => array( 'clip_path', 'description', 'width', 'height', 'img_url', 'sort_order' ),
                'json_cols'  => array(),
            ),
            'fonts' => array(
                'table'      => $bp . 'bztimg_editor_fonts',
                'unique_col' => 'family',
                'cols'       => array( 'family', 'styles_json', 'sort_order' ),
                'json_cols'  => array( 'styles_json' ),
            ),
            'text_presets' => array(
                'table'      => $bp . 'bztimg_editor_text_presets',
                'unique_col' => 'content_hash',
                'cols'       => array( 'description', 'data_json', 'width', 'height', 'img_url', 'sort_order' ),
                'json_cols'  => array( 'data_json' ),
            ),
            'templates' => array(
                'table'      => $bp . 'bztimg_editor_templates',
                'unique_col' => 'content_hash',
                'cols'       => array( 'description', 'data_json', 'pages', 'width', 'height', 'img_url', 'sort_order' ),
                'json_cols'  => array( 'data_json' ),
            ),
        );
    }

    /* ============================================================
     *  PUBLIC API
     * ============================================================ */

    /**
     * Run a full sync.
     *
     * @param bool $force  Re-fetch + override protected rows.
     * @return array|WP_Error
     */
    public static function apply_sync( $force = false ) {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return new WP_Error( 'no_gateway_client', 'BizCity_LLM_Client chưa load — kích hoạt bizcity-twin-ai.' );
        }
        $llm = BizCity_LLM_Client::instance();
        if ( ! $llm->is_ready() ) {
            return new WP_Error( 'no_api_key', 'BizCity API key chưa cấu hình (bizcity_llm_api_key).' );
        }

        $manifest = self::fetch_manifest( $force );
        if ( is_wp_error( $manifest ) ) return $manifest;

        if ( ! empty( $manifest['unchanged'] ) && ! $force ) {
            $report = array( 'status' => 'unchanged', 'message' => 'Hub không có thay đổi.', 'manifest_etag' => get_option( self::MANIFEST_OPT, '' ) );
            self::save_report( $report );
            return $report;
        }

        // Fetch full bundle (all types).
        $bundle = self::fetch_bundle( 'all' );
        if ( is_wp_error( $bundle ) ) return $bundle;

        $data = isset( $bundle['data'] ) && is_array( $bundle['data'] ) ? $bundle['data'] : array();

        $report = array(
            'status'        => 'synced',
            'manifest_etag' => isset( $manifest['etag'] ) ? $manifest['etag'] : '',
            'router_version'=> isset( $manifest['router_version'] ) ? $manifest['router_version'] : '',
            'counts'        => array(),
            'errors'        => array(),
        );

        // 1) Categories.
        $report['counts']['categories'] = self::sync_categories(
            isset( $data['categories'] ) ? $data['categories'] : array()
        );

        // 2) Templates (AI prompt templates).
        $report['counts']['templates'] = self::sync_templates(
            isset( $data['templates'] ) ? $data['templates'] : array(),
            $force
        );

        // 3) Samples (clothing/accessories) — stored as options.
        $report['counts']['samples'] = self::sync_samples(
            isset( $data['samples'] ) ? $data['samples'] : array()
        );

        // 4) Editor assets (5 types).
        foreach ( self::editor_type_map() as $type => $cfg ) {
            $bundle_key = 'editor_' . $type;
            $rows       = isset( $data[ $bundle_key ] ) ? $data[ $bundle_key ] : array();
            $report['counts'][ $bundle_key ] = self::sync_editor_assets( $type, $cfg, $rows, $force );
        }

        // 5) Soft-delete rows missing from manifest.
        $report['counts']['soft_deleted'] = self::soft_delete_missing( $manifest );

        // Persist state.
        if ( ! empty( $manifest['etag'] ) ) {
            update_option( self::MANIFEST_OPT, $manifest['etag'], false );
        }
        update_option( self::LAST_SYNC_AT_OPT, current_time( 'mysql' ), false );
        self::save_report( $report );

        return $report;
    }

    /**
     * GET manifest with If-None-Match support.
     *
     * @param bool $force  Bypass cached ETag.
     * @return array|WP_Error  ['unchanged'=>true] OR full manifest array OR WP_Error
     */
    public static function fetch_manifest( $force = false ) {
        $llm    = BizCity_LLM_Client::instance();
        $url    = $llm->get_gateway_url() . '/wp-json/bizcity/v1/image-library/manifest';
        $cached = $force ? '' : (string) get_option( self::MANIFEST_OPT, '' );

        $headers = array( 'Accept' => 'application/json' );
        if ( $cached !== '' ) {
            $headers['If-None-Match'] = '"' . $cached . '"';
        }
        $api_key = $llm->get_api_key();
        if ( $api_key !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $resp = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code === 304 ) {
            return array( 'unchanged' => true );
        }
        if ( $code !== 200 ) {
            return new WP_Error( 'manifest_http_' . $code, 'Manifest HTTP ' . $code );
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return new WP_Error( 'manifest_invalid', 'Manifest payload invalid.' );
        }
        return isset( $body['data'] ) ? $body['data'] : array();
    }

    /**
     * GET bundle (full payload).
     *
     * @param string $include  'all' OR CSV (templates,editor_fonts,...)
     * @return array|WP_Error
     */
    public static function fetch_bundle( $include = 'all' ) {
        $llm = BizCity_LLM_Client::instance();
        $url = $llm->get_gateway_url() . '/wp-json/bizcity/v1/image-library/bundle';
        if ( $include && $include !== 'all' ) {
            $url = add_query_arg( array( 'include' => $include ), $url );
        }
        $headers = array( 'Accept' => 'application/json' );
        $api_key = $llm->get_api_key();
        if ( $api_key !== '' ) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }
        $resp = wp_remote_get( $url, array( 'timeout' => 60, 'headers' => $headers ) );
        if ( is_wp_error( $resp ) ) return $resp;

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code !== 200 ) {
            return new WP_Error( 'bundle_http_' . $code, 'Bundle HTTP ' . $code );
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            return new WP_Error( 'bundle_invalid', 'Bundle payload invalid.' );
        }
        return isset( $body['data'] ) ? $body['data'] : array();
    }

    /* ============================================================
     *  SYNC METHODS (per resource type)
     * ============================================================ */

    private static function sync_categories( $rows ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_template_categories';
        $stats = self::empty_stats();
        if ( ! is_array( $rows ) ) return $stats;

        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) || empty( $r['slug'] ) ) continue;
            $hub_row = array(
                'slug'       => (string) $r['slug'],
                'name'       => isset( $r['name'] ) ? (string) $r['name'] : '',
                'icon_emoji' => isset( $r['icon_emoji'] ) ? (string) $r['icon_emoji'] : '',
                'icon_url'   => isset( $r['icon_url'] )   ? (string) $r['icon_url']   : '',
                'sort_order' => isset( $r['sort_order'] ) ? (int) $r['sort_order']    : 0,
                'status'     => isset( $r['status'] )     ? (string) $r['status']     : 'active',
            );
            $hub_version = isset( $r['version'] ) ? (string) $r['version'] : '1.0.0';
            $action = self::upsert_hub_row( $table, 'slug', $hub_row['slug'], $hub_row, $hub_version, false );
            $stats[ $action ] = ( isset( $stats[ $action ] ) ? $stats[ $action ] : 0 ) + 1;
        }
        return $stats;
    }

    private static function sync_templates( $rows, $force ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_templates';
        $stats = self::empty_stats();
        if ( ! is_array( $rows ) ) return $stats;

        // Build slug → category_id lookup once.
        $cat_map = $wpdb->get_results(
            "SELECT slug, id FROM {$wpdb->prefix}bztimg_template_categories",
            ARRAY_A
        );
        $cat_lookup = array();
        foreach ( (array) $cat_map as $c ) {
            $cat_lookup[ $c['slug'] ] = (int) $c['id'];
        }

        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) || empty( $r['slug'] ) ) continue;

            // Resolve category_id from hub's category slug (in extra_data.category_slug or via category_id reference).
            $category_id = 0;
            if ( ! empty( $r['extra_data']['category_slug'] ) && isset( $cat_lookup[ $r['extra_data']['category_slug'] ] ) ) {
                $category_id = $cat_lookup[ $r['extra_data']['category_slug'] ];
            }

            $form_fields      = isset( $r['form_fields'] )      ? $r['form_fields']      : array();
            $prompt_templates = isset( $r['prompt_templates'] ) ? $r['prompt_templates'] : null;
            $extra_data       = isset( $r['extra_data'] )       ? $r['extra_data']       : array();

            // Merge prompt_templates into extra_data so client doesn't need a new column.
            if ( is_array( $prompt_templates ) && ! empty( $prompt_templates ) ) {
                if ( ! is_array( $extra_data ) ) $extra_data = array();
                $extra_data['prompt_templates'] = $prompt_templates;
            }

            $hub_row = array(
                'slug'              => (string) $r['slug'],
                'category_id'       => $category_id,
                'subcategory'       => isset( $r['subcategory'] )       ? (string) $r['subcategory']       : '',
                'title'             => isset( $r['title'] )             ? (string) $r['title']             : '',
                'description'       => isset( $r['description'] )       ? (string) $r['description']       : '',
                'thumbnail_url'     => isset( $r['thumbnail_url'] )     ? (string) $r['thumbnail_url']     : '',
                'badge_text'        => isset( $r['badge_text'] )        ? (string) $r['badge_text']        : '',
                'badge_color'       => isset( $r['badge_color'] )       ? (string) $r['badge_color']        : '',
                'tags'              => isset( $r['tags'] )              ? (string) $r['tags']              : '',
                'prompt_template'   => isset( $r['prompt_template'] )   ? (string) $r['prompt_template']   : '',
                'negative_prompt'   => isset( $r['negative_prompt'] )   ? (string) $r['negative_prompt']   : '',
                'form_fields'       => is_array( $form_fields ) ? wp_json_encode( $form_fields ) : (string) $form_fields,
                'recommended_model' => isset( $r['recommended_model'] ) ? (string) $r['recommended_model'] : 'flux-pro',
                'recommended_size'  => isset( $r['recommended_size'] )  ? (string) $r['recommended_size']  : '1024x1024',
                'style'             => isset( $r['style'] )             ? (string) $r['style']             : 'auto',
                'num_outputs'       => isset( $r['num_outputs'] )       ? (int) $r['num_outputs']          : 1,
                'extra_data'        => is_array( $extra_data ) ? wp_json_encode( $extra_data ) : (string) $extra_data,
                'is_featured'       => isset( $r['is_featured'] )       ? (int) $r['is_featured']          : 0,
                'sort_order'        => isset( $r['sort_order'] )        ? (int) $r['sort_order']           : 0,
                'status'            => isset( $r['status'] )            ? (string) $r['status']            : 'active',
            );
            $hub_version = isset( $r['version'] ) ? (string) $r['version'] : '1.0.0';

            $action = self::upsert_hub_row( $table, 'slug', $hub_row['slug'], $hub_row, $hub_version, $force );
            $stats[ $action ] = ( isset( $stats[ $action ] ) ? $stats[ $action ] : 0 ) + 1;
        }
        return $stats;
    }

    /**
     * Samples are catalog data (clothing/accessories) — store under options so existing FE can read.
     */
    private static function sync_samples( $rows ) {
        $stats = array( 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_local_conflict' => 0, 'skipped_protected' => 0, 'error' => 0 );
        if ( ! is_array( $rows ) ) return $stats;
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) || empty( $r['sample_type'] ) ) continue;
            $key = self::SAMPLES_OPT_PREFIX . sanitize_key( $r['sample_type'] );
            $existing = get_option( $key, null );
            $new = array(
                'sample_type' => (string) $r['sample_type'],
                'version'     => isset( $r['version'] ) ? (string) $r['version'] : '1.0.0',
                'data'        => isset( $r['data'] ) ? $r['data'] : array(),
                'synced_at'   => current_time( 'mysql' ),
            );
            if ( ! is_array( $existing ) ) {
                update_option( $key, $new, false );
                $stats['inserted']++;
            } elseif ( empty( $existing['version'] ) || $existing['version'] !== $new['version'] ) {
                update_option( $key, $new, false );
                $stats['updated']++;
            } else {
                $stats['unchanged']++;
            }
        }
        return $stats;
    }

    private static function sync_editor_assets( $type, $cfg, $rows, $force ) {
        $stats = self::empty_stats();
        if ( ! is_array( $rows ) ) return $stats;

        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) continue;
            $unique_val = isset( $r[ $cfg['unique_col'] ] ) ? (string) $r[ $cfg['unique_col'] ] : '';
            if ( $unique_val === '' ) continue;

            $hub_row = array();
            foreach ( $cfg['cols'] as $col ) {
                if ( ! array_key_exists( $col, $r ) ) continue;
                $val = $r[ $col ];
                if ( in_array( $col, $cfg['json_cols'], true ) && ( is_array( $val ) || is_object( $val ) ) ) {
                    $val = wp_json_encode( $val );
                }
                $hub_row[ $col ] = $val;
            }
            $hub_row[ $cfg['unique_col'] ] = $unique_val;
            $hub_row['status'] = isset( $r['status'] ) ? (string) $r['status'] : 'active';

            $hub_version = isset( $r['version'] ) ? (string) $r['version'] : '1.0.0';
            $action = self::upsert_hub_row( $cfg['table'], $cfg['unique_col'], $unique_val, $hub_row, $hub_version, $force );
            $stats[ $action ] = ( isset( $stats[ $action ] ) ? $stats[ $action ] : 0 ) + 1;
        }
        return $stats;
    }

    /* ============================================================
     *  CORE UPSERT (4-branch logic)
     * ============================================================ */

    /**
     * Insert/update one hub row honoring source + protected_from_sync.
     *
     * @return string  inserted | updated | unchanged | skipped_local_conflict | skipped_protected | error
     */
    private static function upsert_hub_row( $table, $unique_col, $unique_val, $hub_row, $hub_version, $force ) {
        global $wpdb;

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, source, protected_from_sync, hub_version FROM {$table} WHERE {$unique_col} = %s",
                $unique_val
            ),
            ARRAY_A
        );

        if ( ! $existing ) {
            $row = $hub_row;
            $row['source']        = 'hub';
            $row['hub_slug']      = (string) $unique_val;
            $row['hub_version']   = (string) $hub_version;
            $row['hub_synced_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( $table, $row );
            return $ok ? 'inserted' : 'error';
        }

        if ( $existing['source'] === 'local' ) {
            return 'skipped_local_conflict';
        }
        if ( ! $force && (int) $existing['protected_from_sync'] === 1 ) {
            return 'skipped_protected';
        }
        if ( ! $force && (string) $existing['hub_version'] === (string) $hub_version ) {
            return 'unchanged';
        }

        $row = $hub_row;
        $row['source']        = 'hub';
        $row['hub_slug']      = (string) $unique_val;
        $row['hub_version']   = (string) $hub_version;
        $row['hub_synced_at'] = current_time( 'mysql' );

        $ok = $wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
        return ( $ok !== false ) ? 'updated' : 'error';
    }

    /* ============================================================
     *  SOFT DELETE — any hub row missing from manifest → 'deprecated'
     * ============================================================ */

    private static function soft_delete_missing( $manifest ) {
        global $wpdb;
        $deleted = 0;
        if ( empty( $manifest['types'] ) || ! is_array( $manifest['types'] ) ) return 0;

        // Build present-key sets per type from manifest items.
        $present = array();
        foreach ( $manifest['types'] as $type => $bucket ) {
            $items = isset( $bucket['items'] ) && is_array( $bucket['items'] ) ? $bucket['items'] : array();
            $keys  = array();
            foreach ( $items as $it ) {
                if ( ! is_array( $it ) ) continue;
                // manifest items use 'slug' (categories/templates/samples) or 'k' (editor) — accept both.
                if ( isset( $it['slug'] ) )      $keys[] = (string) $it['slug'];
                elseif ( isset( $it['k'] ) )     $keys[] = (string) $it['k'];
                elseif ( isset( $it['sample_type'] ) ) $keys[] = (string) $it['sample_type'];
            }
            $present[ $type ] = $keys;
        }

        $map = array(
            'categories' => array( 'table' => $wpdb->prefix . 'bztimg_template_categories', 'col' => 'slug' ),
            'templates'  => array( 'table' => $wpdb->prefix . 'bztimg_templates',           'col' => 'slug' ),
        );
        foreach ( self::editor_type_map() as $type => $cfg ) {
            $map[ 'editor_' . $type ] = array( 'table' => $cfg['table'], 'col' => $cfg['unique_col'] );
        }

        foreach ( $map as $type => $info ) {
            if ( ! isset( $present[ $type ] ) ) continue;
            $keys = $present[ $type ];
            if ( empty( $keys ) ) continue;

            $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
            $sql = "UPDATE {$info['table']} SET status = 'deprecated'
                    WHERE source = 'hub'
                      AND status <> 'deprecated'
                      AND protected_from_sync = 0
                      AND {$info['col']} NOT IN ({$placeholders})";
            $deleted += (int) $wpdb->query( $wpdb->prepare( $sql, $keys ) );
        }
        return $deleted;
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private static function empty_stats() {
        return array(
            'inserted'               => 0,
            'updated'                => 0,
            'unchanged'              => 0,
            'skipped_local_conflict' => 0,
            'skipped_protected'      => 0,
            'error'                  => 0,
        );
    }

    private static function save_report( $report ) {
        $report['saved_at'] = current_time( 'mysql' );
        update_option( self::LAST_REPORT_OPT, $report, false );
    }

    public static function get_last_report() {
        $r = get_option( self::LAST_REPORT_OPT, array() );
        return is_array( $r ) ? $r : array();
    }

    public static function get_last_sync_at() {
        return (string) get_option( self::LAST_SYNC_AT_OPT, '' );
    }

    public static function get_etag() {
        return (string) get_option( self::MANIFEST_OPT, '' );
    }

    /**
     * Toggle protected_from_sync flag for a single row.
     */
    public static function set_protected( $table_short, $id, $protected ) {
        global $wpdb;
        $allowed = array(
            'templates'           => $wpdb->prefix . 'bztimg_templates',
            'categories'          => $wpdb->prefix . 'bztimg_template_categories',
            'editor_shapes'       => $wpdb->prefix . 'bztimg_editor_shapes',
            'editor_frames'       => $wpdb->prefix . 'bztimg_editor_frames',
            'editor_fonts'        => $wpdb->prefix . 'bztimg_editor_fonts',
            'editor_text_presets' => $wpdb->prefix . 'bztimg_editor_text_presets',
            'editor_templates'    => $wpdb->prefix . 'bztimg_editor_templates',
        );
        if ( ! isset( $allowed[ $table_short ] ) ) return new WP_Error( 'bad_table', 'Unknown table' );
        $ok = $wpdb->update(
            $allowed[ $table_short ],
            array( 'protected_from_sync' => $protected ? 1 : 0 ),
            array( 'id' => (int) $id ),
            array( '%d' ),
            array( '%d' )
        );
        return ( $ok !== false );
    }

    /**
     * Quick stats per table for the admin dashboard.
     */
    public static function get_table_stats() {
        global $wpdb;
        $tables = array(
            'categories'          => $wpdb->prefix . 'bztimg_template_categories',
            'templates'           => $wpdb->prefix . 'bztimg_templates',
            'editor_shapes'       => $wpdb->prefix . 'bztimg_editor_shapes',
            'editor_frames'       => $wpdb->prefix . 'bztimg_editor_frames',
            'editor_fonts'        => $wpdb->prefix . 'bztimg_editor_fonts',
            'editor_text_presets' => $wpdb->prefix . 'bztimg_editor_text_presets',
            'editor_templates'    => $wpdb->prefix . 'bztimg_editor_templates',
        );
        $out = array();
        foreach ( $tables as $key => $t ) {
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $t ) ) !== $t ) {
                $out[ $key ] = array( 'hub' => 0, 'local' => 0, 'protected' => 0, 'deprecated' => 0, 'total' => 0 );
                continue;
            }
            $row = $wpdb->get_row(
                "SELECT
                    SUM(CASE WHEN source='hub'   THEN 1 ELSE 0 END) AS hub,
                    SUM(CASE WHEN source='local' THEN 1 ELSE 0 END) AS local,
                    SUM(CASE WHEN protected_from_sync=1 THEN 1 ELSE 0 END) AS protected,
                    SUM(CASE WHEN status='deprecated'   THEN 1 ELSE 0 END) AS deprecated,
                    COUNT(*) AS total
                 FROM {$t}",
                ARRAY_A
            );
            $out[ $key ] = array(
                'hub'        => (int) $row['hub'],
                'local'      => (int) $row['local'],
                'protected'  => (int) $row['protected'],
                'deprecated' => (int) $row['deprecated'],
                'total'      => (int) $row['total'],
            );
        }
        return $out;
    }
}
