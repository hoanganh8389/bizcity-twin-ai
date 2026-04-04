<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notebook Tool Registry — Hook-based tool registration for Studio sidebar.
 *
 * External plugins (e.g. bizcity-tool-mindmap) register via:
 *   add_action( 'bcn_register_notebook_tools', function( $registry ) {
 *       $registry->add([...]);
 *   });
 *
 * Plugin header "Notebook: true" marks a tool plugin as notebook-compatible.
 */
class BCN_Notebook_Tool_Registry {

    /** @var array<string, array> type => tool definition */
    private static $tools = [];

    /** @var bool */
    private static $booted = false;

    /**
     * Register a tool.
     *
     * @param array $tool {
     *   @type string   $type        Tool type slug (e.g. 'mindmap')
     *   @type string   $label       Display label
     *   @type string   $description Short description
     *   @type string   $icon        Emoji icon
     *   @type string   $icon_url    URL to icon image (optional)
     *   @type string   $category    Category for grouping
     *   @type string   $mode        'delegate' | 'built-in'
     *   @type callable $callback    fn(array $skeleton) => array Tool Output
     * }
     */
    public function add( array $tool ) {
        $type = sanitize_key( $tool['type'] ?? '' );
        if ( empty( $type ) ) return;

        self::$tools[ $type ] = wp_parse_args( $tool, [
            'type'        => $type,
            'label'       => $type,
            'description' => '',
            'icon'        => '🔧',
            'icon_url'    => '',
            'color'       => 'blue',
            'category'    => 'general',
            'mode'        => 'delegate',
            'available'   => true,
            'callback'    => null,
        ] );
    }

    /**
     * Get all registered tools (for JS config).
     */
    public static function get_all() {
        self::ensure_booted();

        return array_values( array_map( function ( $t ) {
            return [
                'type'        => $t['type'],
                'label'       => $t['label'],
                'description' => $t['description'],
                'icon'        => $t['icon'],
                'icon_url'    => $t['icon_url'],
                'color'       => $t['color'] ?? 'blue',
                'category'    => $t['category'],
                'mode'        => $t['mode'],
                'available'   => $t['available'],
            ];
        }, self::$tools ) );
    }

    /**
     * Execute a tool's callback with Skeleton JSON.
     *
     * @param string $type     Tool type slug.
     * @param array  $skeleton Skeleton JSON from BCN_Studio_Input_Builder.
     * @return array|WP_Error  Tool output.
     */
    public static function execute( $type, array $skeleton ) {
        self::ensure_booted();

        if ( ! isset( self::$tools[ $type ] ) ) {
            return new WP_Error( 'unknown_tool', "Tool '{$type}' chưa được đăng ký" );
        }

        $tool = self::$tools[ $type ];

        if ( ! is_callable( $tool['callback'] ) ) {
            return new WP_Error( 'no_callback', "Tool '{$type}' không có callback" );
        }

        $result = call_user_func( $tool['callback'], $skeleton );

        // Normalize: Intent Envelope {success,message,data} → Notebook format {content,content_format,title}
        if ( is_array( $result ) && isset( $result['success'] ) && ! isset( $result['content'] ) ) {
            if ( empty( $result['success'] ) ) {
                return new WP_Error( 'tool_failed', $result['message'] ?? "Tool '{$type}' thất bại" );
            }
            $result = [
                'content'        => $result['message'] ?? '',
                'content_format' => 'markdown',
                'title'          => $result['data']['title'] ?? ( ucfirst( $type ) . ' — ' . current_time( 'd/m/Y H:i' ) ),
                'data'           => $result['data'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Check if a tool is registered.
     */
    public static function has( $type ) {
        self::ensure_booted();
        return isset( self::$tools[ $type ] );
    }

    /**
     * Boot: fire hook for external plugins to register tools.
     */
    public static function boot() {
        if ( self::$booted ) return;
        self::$booted = true;

        // External plugins register via this hook.
        do_action( 'bcn_register_notebook_tools', new self() );
    }

    private static function ensure_booted() {
        if ( ! self::$booted ) {
            self::boot();
        }
    }
}
