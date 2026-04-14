<?php
/**
 * Template Category Manager — CRUD for image template categories.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Template_Category_Manager {

    private static $table_suffix = 'bztimg_template_categories';

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::$table_suffix;
    }

    /* ── READ ── */

    public static function get_all( $args = array() ) {
        global $wpdb;
        $table  = self::table();
        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = sanitize_text_field( $args['status'] );
        }

        $order = 'ORDER BY sort_order ASC, id ASC';
        $sql   = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " {$order}";

        if ( $values ) {
            $sql = $wpdb->prepare( $sql, ...$values );
        }
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function get_by_id( $id ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", absint( $id ) ),
            ARRAY_A
        );
    }

    public static function get_by_slug( $slug ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE slug = %s", sanitize_title( $slug ) ),
            ARRAY_A
        );
    }

    /* ── CREATE ── */

    public static function insert( $data ) {
        global $wpdb;

        $row = array(
            'slug'        => sanitize_title( $data['slug'] ?? '' ),
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'description' => wp_kses_post( $data['description'] ?? '' ),
            'icon_emoji'  => mb_substr( sanitize_text_field( $data['icon_emoji'] ?? '' ), 0, 10 ),
            'icon_url'    => esc_url_raw( $data['icon_url'] ?? '' ),
            'parent_id'   => absint( $data['parent_id'] ?? 0 ),
            'sort_order'  => intval( $data['sort_order'] ?? 0 ),
            'status'      => in_array( ( $data['status'] ?? 'active' ), array( 'active', 'draft' ), true ) ? $data['status'] : 'active',
        );

        if ( empty( $row['slug'] ) || empty( $row['name'] ) ) {
            return new \WP_Error( 'missing_fields', 'Slug and name are required.' );
        }

        $existing = self::get_by_slug( $row['slug'] );
        if ( $existing ) {
            return new \WP_Error( 'duplicate_slug', 'Category slug already exists.' );
        }

        $result = $wpdb->insert( self::table(), $row );
        return $result ? $wpdb->insert_id : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    /* ── UPDATE ── */

    public static function update( $id, $data ) {
        global $wpdb;

        $id  = absint( $id );
        $row = array();

        $allowed = array( 'name', 'description', 'icon_emoji', 'icon_url', 'parent_id', 'sort_order', 'status' );
        foreach ( $allowed as $key ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            switch ( $key ) {
                case 'name':        $row[ $key ] = sanitize_text_field( $data[ $key ] ); break;
                case 'description': $row[ $key ] = wp_kses_post( $data[ $key ] ); break;
                case 'icon_emoji':  $row[ $key ] = mb_substr( sanitize_text_field( $data[ $key ] ), 0, 10 ); break;
                case 'icon_url':    $row[ $key ] = esc_url_raw( $data[ $key ] ); break;
                case 'parent_id':   $row[ $key ] = absint( $data[ $key ] ); break;
                case 'sort_order':  $row[ $key ] = intval( $data[ $key ] ); break;
                case 'status':      $row[ $key ] = in_array( $data[ $key ], array( 'active', 'draft' ), true ) ? $data[ $key ] : 'active'; break;
            }
        }

        if ( empty( $row ) ) return true;

        $result = $wpdb->update( self::table(), $row, array( 'id' => $id ) );
        return false !== $result ? true : new \WP_Error( 'db_error', $wpdb->last_error );
    }

    /* ── DELETE ── */

    public static function delete( $id ) {
        global $wpdb;
        return $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /* ── HELPERS ── */

    public static function count_templates( $category_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_templates WHERE category_id = %d",
            absint( $category_id )
        ) );
    }

    public static function reorder( $ordered_ids ) {
        global $wpdb;
        $table = self::table();
        foreach ( $ordered_ids as $sort => $id ) {
            $wpdb->update( $table, array( 'sort_order' => (int) $sort ), array( 'id' => absint( $id ) ) );
        }
        return true;
    }
}
