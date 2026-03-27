<?php
/**
 * BizCity Tool WooCommerce — Custom Post Type Registration
 *
 * Replaces custom DB tables with WordPress CPT + wp_postmeta.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Woo_Post_Type {

    const POST_TYPE = 'bza-woo';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Agent Woo History',
                'singular_name' => 'Agent Woo Entry',
            ],
            'public'          => false,
            'show_ui'         => false,
            'show_in_rest'    => false,
            'supports'        => [ 'title', 'editor', 'author', 'custom-fields' ],
            'capability_type' => 'post',
        ] );
    }

    /**
     * Save a prompt history entry.
     */
    public static function save_history( int $user_id, string $goal, string $prompt, string $ai_title, string $ai_content, ?int $product_id, string $product_url, string $image_url ) {
        $post_id = wp_insert_post( [
            'post_type'    => self::POST_TYPE,
            'post_title'   => sanitize_text_field( $prompt ),
            'post_content' => wp_kses_post( $ai_content ),
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ] );

        if ( $post_id && ! is_wp_error( $post_id ) ) {
            update_post_meta( $post_id, '_bza_goal',        sanitize_text_field( $goal ) );
            update_post_meta( $post_id, '_bza_ai_title',    sanitize_text_field( $ai_title ) );
            update_post_meta( $post_id, '_bza_result_id',   (int) $product_id );
            update_post_meta( $post_id, '_bza_result_url',  esc_url_raw( $product_url ) );
            update_post_meta( $post_id, '_bza_image_url',   esc_url_raw( $image_url ) );
            update_post_meta( $post_id, '_bza_status',      'completed' );
        }

        return $post_id;
    }

    /**
     * Get history entries for a user.
     */
    public static function get_history( int $user_id, int $limit = 30 ): array {
        $args = [
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        if ( $user_id > 0 ) {
            $args['author'] = $user_id;
        }

        $query = new WP_Query( $args );

        $items = [];
        foreach ( $query->posts as $p ) {
            $items[] = (object) [
                'id'          => $p->ID,
                'user_id'     => $p->post_author,
                'goal'        => get_post_meta( $p->ID, '_bza_goal', true ),
                'prompt'      => $p->post_title,
                'ai_title'    => get_post_meta( $p->ID, '_bza_ai_title', true ),
                'ai_content'  => wp_trim_words( wp_strip_all_tags( $p->post_content ), 50 ),
                'product_id'  => get_post_meta( $p->ID, '_bza_result_id', true ),
                'product_url' => get_post_meta( $p->ID, '_bza_result_url', true ),
                'image_url'   => get_post_meta( $p->ID, '_bza_image_url', true ),
                'status'      => get_post_meta( $p->ID, '_bza_status', true ),
                'created_at'  => $p->post_date,
            ];
        }

        return $items;
    }

    /**
     * Get a single history entry.
     */
    public static function get_entry( int $entry_id, int $user_id ) {
        $post = get_post( $entry_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE || (int) $post->post_author !== $user_id ) {
            return null;
        }

        return (object) [
            'id'      => $post->ID,
            'prompt'  => $post->post_title,
            'goal'    => get_post_meta( $post->ID, '_bza_goal', true ),
        ];
    }
}
