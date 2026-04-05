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
 * BizCity Intent — Simple Provider (Array-based)
 *
 * Đơn giản hóa việc đăng ký Intent Provider cho plugins.
 * Thay vì viết 1 class 200+ dòng, plugin chỉ cần truyền 1 array config:
 *
 *   bizcity_intent_register_plugin([
 *       'id'       => 'tool-content',
 *       'name'     => 'BizCity Tool Content',
 *       'patterns' => [ '/viết bài.../ui' => [...] ],
 *       'plans'    => [ 'write_article' => [...] ],
 *       'tools'    => [ 'write_article' => ['callback' => [...]] ],
 *       'context'  => function($goal, $slots, $user_id, $conv) { return ''; },
 *       'instructions' => function($goal) { return ''; },
 *   ]);
 *
 * @package BizCity_Intent
 * @since   2.5.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Simple_Provider extends BizCity_Intent_Provider {

    /** @var array */
    private $config;

    /**
     * @param array $config {
     *     @type string   $id           Required. Unique slug.
     *     @type string   $name         Required. Human label.
     *     @type array    $patterns     Goal patterns array (Router). Default [].
     *     @type array    $plans        Plans array (Planner). Default [].
     *     @type array    $tools        Tools array (tool_name => config). Default [].
     *     @type callable $context      build_context callback. Default null.
     *     @type callable $instructions get_system_instructions callback. Default null.
     *     @type int      $knowledge_character_id  Character ID for RAG. Default 0.
     * }
     */
    public function __construct( array $config ) {
        $this->config = wp_parse_args( $config, [
            'id'                     => '',
            'name'                   => '',
            'patterns'               => [],
            'plans'                  => [],
            'tools'                  => [],
            'examples'               => [],
            'context'                => null,
            'instructions'           => null,
            'knowledge_character_id' => 0,
        ] );
    }

    public function get_id() {
        return $this->config['id'];
    }

    public function get_name() {
        return $this->config['name'];
    }

    public function get_goal_patterns() {
        return $this->config['patterns'];
    }

    public function get_plans() {
        return $this->config['plans'];
    }

    public function get_tools() {
        return $this->config['tools'];
    }

    public function get_knowledge_character_id() {
        return (int) $this->config['knowledge_character_id'];
    }

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        if ( is_callable( $this->config['context'] ) ) {
            return call_user_func( $this->config['context'], $goal, $slots, $user_id, $conversation );
        }
        return '';
    }

    public function get_system_instructions( $goal ) {
        if ( is_callable( $this->config['instructions'] ) ) {
            return call_user_func( $this->config['instructions'], $goal );
        }
        return '';
    }

    public function get_examples() {
        return $this->config['examples'] ?: [];
    }
}

/* ════════════════════════════════════════════════════════════════
 *  Helper function — shorthand for plugins
 * ════════════════════════════════════════════════════════════════ */

/**
 * Register an Intent Plugin with a simple config array.
 *
 * Call this inside `bizcity_intent_register_providers` action:
 *
 *   add_action( 'bizcity_intent_register_providers', function ( $registry ) {
 *       bizcity_intent_register_plugin( $registry, [
 *           'id'       => 'tool-content',
 *           'name'     => 'BizCity Tool Content',
 *           'patterns' => [ ... ],
 *           'plans'    => [ ... ],
 *           'tools'    => [ ... ],
 *       ]);
 *   });
 *
 * @param BizCity_Intent_Provider_Registry $registry
 * @param array $config  See BizCity_Intent_Simple_Provider::__construct()
 */
function bizcity_intent_register_plugin( $registry, array $config ) {
    $registry->register( new BizCity_Intent_Simple_Provider( $config ) );
}
