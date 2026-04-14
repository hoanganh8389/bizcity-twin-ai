<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity LLM — Admin Settings Page
 *
 * Two-mode configuration:
 *   • Gateway — enter BizCity API key + gateway URL
 *   • Direct  — enter OpenRouter API key (self-hosted)
 *
 * Works in both Multisite (Network Admin) and single-site (Settings menu).
 *
 * @package BizCity_LLM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_LLM_Settings {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( is_multisite() ) {
            add_action( 'network_admin_menu',                         [ $this, 'add_menu' ] );
            add_action( 'network_admin_edit_bizcity_llm_save',       [ $this, 'save_settings' ] );
            add_action( 'network_admin_notices',                      [ $this, 'admin_notices' ] );
        }

        // Site-level menu — always registered (single-site or each site in multisite)
        // Single-site menu moved to BizCity_Admin_Menu (centralized).
        // add_action( 'admin_menu', [ $this, 'add_menu_single' ], 30 );
        add_action( 'admin_init',                                 [ $this, 'handle_save_single' ] );
        add_action( 'admin_notices',                              [ $this, 'admin_notices' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX
        add_action( 'wp_ajax_bizcity_llm_test_key',       [ $this, 'ajax_test_key' ] );
        add_action( 'wp_ajax_bizcity_llm_fetch_models',    [ $this, 'ajax_fetch_models' ] );
        add_action( 'wp_ajax_bizcity_tavily_test_api_key', [ $this, 'ajax_test_tavily_key' ] );
        add_action( 'wp_ajax_bizcity_llm_register_key',    [ $this, 'ajax_register_key' ] );
        add_action( 'wp_ajax_bizcity_llm_usage_log',       [ $this, 'ajax_usage_log' ] );
        add_action( 'wp_ajax_bizcity_llm_purge_log',       [ $this, 'ajax_purge_log' ] );
    }

    /* ── Menus ── */
    public function add_menu(): void {
        add_submenu_page(
            'settings.php', 'BizCity LLM', 'BizCity LLM',
            'manage_network_options', 'bizcity-llm', [ $this, 'render_page' ]
        );
    }

    public function add_menu_single(): void {
        // Submenu under "Bots - Web Chat" for site-level admins
        add_submenu_page(
            'bizcity-webchat',
            'BizCity LLM — ' . __( 'AI Gateway Configuration', 'bizcity-twin-ai' ),
            '⚡ LLM Settings',
            'manage_options',
            'bizcity-llm',
            [ $this, 'render_page' ]
        );
    }

    /* ── Enqueue ── */
    public function enqueue_assets( string $hook ): void {
        // Match: settings_page_bizcity-llm, bots-web-chat_page_bizcity-llm, toplevel_page_bizcity-llm
        if ( strpos( $hook, 'bizcity-llm' ) === false && strpos( $hook, 'bizcity-openrouter' ) === false ) {
            return;
        }
        $css_ver = (string) filemtime( BIZCITY_LLM_DIR . '/assets/admin.css' );
        $js_ver  = (string) filemtime( BIZCITY_LLM_DIR . '/assets/admin.js' );
        wp_enqueue_style(
            'bizcity-llm-admin',
            BIZCITY_LLM_URL . '/assets/admin.css',
            [], $css_ver
        );
        wp_enqueue_script(
            'bizcity-llm-admin',
            BIZCITY_LLM_URL . '/assets/admin.js',
            [ 'jquery' ], $js_ver, true
        );
        wp_localize_script( 'bizcity-llm-admin', 'bizcityLLM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bizcity_llm_admin' ),
            'i18n'     => [
                'testing'          => __( 'Testing…', 'bizcity-twin-ai' ),
                'fetching'         => __( 'Loading model list…', 'bizcity-twin-ai' ),
                'test_ok'          => __( '✅ Connection successful!', 'bizcity-twin-ai' ),
                'test_fail'        => __( '❌ Connection failed: ', 'bizcity-twin-ai' ),
                'models_loaded'    => __( '✅ Loaded {n} models.', 'bizcity-twin-ai' ),
                'copy_ok'          => __( 'Copied!', 'bizcity-twin-ai' ),
                'error_prefix'     => __( 'Error: ', 'bizcity-twin-ai' ),
                'models_load_fail' => __( 'Failed to load model list.', 'bizcity-twin-ai' ),
                'name'             => __( 'Name', 'bizcity-twin-ai' ),
                'custom'           => __( 'Custom', 'bizcity-twin-ai' ),
                'select_from_list' => __( 'Select from list', 'bizcity-twin-ai' ),
                'confirm_register' => __( 'Auto-register API key from bizcity.vn?\nThe key will be created and saved to settings.', 'bizcity-twin-ai' ),
                'registering'      => __( 'Registering…', 'bizcity-twin-ai' ),
                'loading'          => __( 'Loading…', 'bizcity-twin-ai' ),
                'no_more_data'     => __( 'No more data', 'bizcity-twin-ai' ),
                'load_more'        => __( 'Load more…', 'bizcity-twin-ai' ),
                'load_error'       => __( 'Error loading data', 'bizcity-twin-ai' ),
                'confirm_purge'    => __( 'Delete all logs older than 90 days?', 'bizcity-twin-ai' ),
            ],
        ] );
    }

    /* ── Render settings page ── */
    public function render_page(): void {
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'bizcity-twin-ai' ) );
        }

        $mode        = 'gateway';
        $api_key     = get_site_option( 'bizcity_llm_api_key', '' );
        $gateway_url = get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' );
        $settings    = get_site_option( 'bizcity_llm_settings', [] );
        $timeout     = $settings['timeout'] ?? 60;
        $tavily_key  = get_site_option( 'bizcity_tavily_api_key', '' );
        $purposes    = BizCity_LLM_Models::purposes();

        if ( is_multisite() ) {
            $form_action = esc_url( network_admin_url( 'edit.php?action=bizcity_llm_save' ) );
        } else {
            $form_action = '';
        }
        ?>
        <div class="wrap bizcity-llm-wrap">
            <h1>⚡ BizCity LLM — <?php echo esc_html__( 'AI Gateway Configuration', 'bizcity-twin-ai' ); ?></h1>
            <p class="description">
                <?php echo esc_html__( 'Configure LLM connection for the BizCity Twin AI platform.', 'bizcity-twin-ai' ); ?>
            </p>

            <div class="bizcity-llm-status bizcity-llm-status--info" style="background:#eef6ff;border-left:4px solid #2271b1;padding:10px 14px;margin:12px 0">
                💎 <?php esc_html_e( 'Want to use your own OpenRouter, OpenAI, or other API keys? Upgrade to Pro for direct API integration.', 'bizcity-twin-ai' ); ?>
                <br/><small style="color:#666">Bạn muốn dùng API key riêng (OpenRouter, OpenAI…)? Nâng cấp bản Pro để tích hợp trực tiếp.</small>
            </div>

            <?php if ( ! empty( $api_key ) ) : ?>
                <div class="bizcity-llm-status bizcity-llm-status--ok">
                    <?php esc_html_e( '✅ API key configured (BizCity Gateway).', 'bizcity-twin-ai' ); ?>
                    <span id="bizcity-llm-test-result"></span>
                    <button type="button" id="bizcity-llm-test-btn" class="button button-small"><?php esc_html_e( 'Test Connection', 'bizcity-twin-ai' ); ?></button>
                </div>
            <?php else : ?>
                <div class="bizcity-llm-status bizcity-llm-status--warn">
                    <?php esc_html_e( '⚠️ No API key configured. Enter one below to activate AI.', 'bizcity-twin-ai' ); ?>
                </div>
            <?php endif; ?>

            <!-- ── Usage Stats Dashboard ── -->
            <?php $this->render_usage_dashboard(); ?>

            <form method="post" action="<?php echo $form_action; ?>">
                <?php wp_nonce_field( 'bizcity_llm_settings', '_wpnonce_llm' ); ?>
                <?php if ( ! is_multisite() ) : ?>
                    <input type="hidden" name="bizcity_llm_save" value="1" />
                <?php endif; ?>

                <!-- ── Gateway Settings ── -->
                <div class="bizcity-llm-card">
                    <h2>🌐 <?php esc_html_e( 'BizCity Gateway Settings', 'bizcity-twin-ai' ); ?></h2>
                    <input type="hidden" name="bizcity_llm_mode" value="gateway" />
                    <table class="form-table">
                        <tr>
                            <th><label for="llm_gateway_url">Gateway URL</label></th>
                            <td>
                                <input type="url" id="llm_gateway_url" name="bizcity_llm_gateway_url"
                                    value="<?php echo esc_attr( $gateway_url ); ?>" class="regular-text"
                                    placeholder="https://bizcity.vn" />
                                <p class="description"><?php esc_html_e( 'Gateway URL của BizCity (mặc định: bizcity.vn)', 'bizcity-twin-ai' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="llm_api_key_gw">BizCity API Key</label></th>
                            <td>
                                <div class="bizcity-llm-key-wrap">
                                    <input type="password" id="llm_api_key_gw" name="bizcity_llm_api_key"
                                        value="<?php echo esc_attr( $api_key ); ?>"
                                        class="regular-text" autocomplete="new-password" placeholder="biz-…" />
                                    <button type="button" class="button bizcity-llm-toggle-key">👁</button>
                                </div>
                                <p class="description"><?php esc_html_e( 'Tạo API key tại Tài khoản của tôi trên bizcity.vn', 'bizcity-twin-ai' ); ?></p>
                                <div id="bizcity-llm-register-wrap" style="margin-top:8px">
                                    <button type="button" id="bizcity-llm-register-btn" class="button button-secondary">
                                        🔑 <?php esc_html_e( 'Auto-register API Key', 'bizcity-twin-ai' ); ?>
                                    </button>
                                    <span id="bizcity-llm-register-result"></span>
                                    <p class="description" style="margin-top:4px">
                                        <?php printf( esc_html__( 'Or register manually at %s', 'bizcity-twin-ai' ), '<a href="https://bizcity.vn/my-account/api-keys/" target="_blank">bizcity.vn/my-account/api-keys/</a>' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Common: Timeout ── -->
                <div class="bizcity-llm-card">
                    <h2>⚙️ <?php esc_html_e( 'General Settings', 'bizcity-twin-ai' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="llm_timeout"><?php esc_html_e( 'Timeout (seconds)', 'bizcity-twin-ai' ); ?></label></th>
                            <td>
                                <input type="number" id="llm_timeout"
                                    name="bizcity_llm_settings[timeout]"
                                    value="<?php echo esc_attr( $timeout ); ?>"
                                    class="small-text" min="10" max="300" />
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Tavily ── -->
                <div class="bizcity-llm-card">
                    <h2>🔍 Tavily — Web Search API</h2>
                    <p class="description"><?php esc_html_e( 'For Deep Research — real-time web search.', 'bizcity-twin-ai' ); ?></p>
                    <?php if ( ! empty( $tavily_key ) ) : ?>
                        <div class="bizcity-llm-status bizcity-llm-status--ok">
                            <?php esc_html_e( '✅ Tavily configured.', 'bizcity-twin-ai' ); ?>
                            <span id="bizcity-tavily-test-result"></span>
                            <button type="button" id="bizcity-tavily-test-btn" class="button button-small"><?php esc_html_e( 'Test', 'bizcity-twin-ai' ); ?></button>
                        </div>
                    <?php else : ?>
                        <div class="bizcity-llm-status bizcity-llm-status--warn"><?php esc_html_e( '⚠️ No Tavily key configured.', 'bizcity-twin-ai' ); ?></div>
                    <?php endif; ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="bizcity_tavily_api_key">Tavily API Key</label></th>
                            <td>
                                <div class="bizcity-llm-key-wrap">
                                    <input type="password" id="bizcity_tavily_api_key" name="bizcity_tavily_api_key"
                                        value="<?php echo esc_attr( $tavily_key ); ?>"
                                        class="regular-text" autocomplete="new-password" placeholder="tvly-…" />
                                    <button type="button" class="button bizcity-llm-toggle-key">👁</button>
                                </div>
                                <p class="description"><a href="https://app.tavily.com/home" target="_blank">app.tavily.com</a> — <?php esc_html_e( '1,000 requests/month free.', 'bizcity-twin-ai' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Model Defaults ── -->
                <div class="bizcity-llm-card">
                    <h2>🤖 <?php esc_html_e( 'Models by Purpose — Primary & Fallback', 'bizcity-twin-ai' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Each purpose has a Primary (used first) and Fallback (auto-triggered on error).', 'bizcity-twin-ai' ); ?>
                        <button type="button" id="bizcity-llm-refresh-models" class="button button-small">🔄 <?php esc_html_e( 'Load Models', 'bizcity-twin-ai' ); ?></button>
                    </p>

                    <?php
                    $purpose_labels = [
                        'chat'     => '💬 Chat / General',
                        'vision'   => '👁 Vision',
                        'code'     => '💻 Code',
                        'fast'     => '⚡ Fast',
                        'router'   => '🔀 Router / Classify',
                        'planner'  => '📋 Planner / Slot',
                        'executor' => '⚙️ Executor / Compose',
                    ];
                    ?>

                    <table class="form-table bizcity-llm-model-table">
                        <thead>
                            <tr>
                                <th style="width:180px"><?php esc_html_e( 'Purpose', 'bizcity-twin-ai' ); ?></th>
                                <th>🟢 Primary</th>
                                <th>🔶 Fallback</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $purpose_labels as $purpose => $label ) :
                            $p_val   = $settings[ 'model_' . $purpose ] ?? BizCity_LLM_Models::DEFAULTS[ $purpose ] ?? '';
                            $f_val   = $settings[ 'model_fallback_' . $purpose ] ?? BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] ?? '';
                            $catalog = BizCity_LLM_Models::get( $purpose );
                        ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php $this->render_model_select( "bizcity_llm_settings[model_{$purpose}]", $p_val, $catalog, $purpose, 'primary' ); ?></td>
                            <td>
                                <?php $this->render_model_select( "bizcity_llm_settings[model_fallback_{$purpose}]", $f_val, $catalog, $purpose, 'fallback' ); ?>
                                <label class="bizcity-llm-nofb">
                                    <input type="checkbox" name="bizcity_llm_settings[no_fallback_<?php echo $purpose; ?>]"
                                        value="1" <?php checked( ! empty( $settings[ 'no_fallback_' . $purpose ] ) ); ?> />
                                    <?php esc_html_e( 'Disable fallback', 'bizcity-twin-ai' ); ?>
                                </label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ── Live Model Browser ── -->
                <div class="bizcity-llm-card" id="bizcity-llm-model-browser" style="display:none">
                    <h2>📋 <?php esc_html_e( 'Model List', 'bizcity-twin-ai' ); ?></h2>
                    <input type="search" id="bizcity-llm-filter" placeholder="<?php esc_attr_e( 'Filter by name…', 'bizcity-twin-ai' ); ?>" class="regular-text" />
                    <div id="bizcity-llm-model-list"></div>
                </div>

                <!-- ── API Reference ── -->
                <div class="bizcity-llm-card bizcity-llm-info">
                    <h2>📖 API Reference</h2>
                    <pre><code>// Chat (auto-fallback, purpose-based routing)
$result = bizcity_llm_chat( $messages );
$result = bizcity_llm_chat( $messages, ['purpose' => 'vision'] );

// Streaming
bizcity_llm_chat_stream( $messages, $opts, function($delta, $full) {
    echo $delta;
});

// Embeddings
$result = bizcity_llm_embeddings( 'Hello world' );

// Get model
$model = bizcity_llm_get_model( 'chat' );

// Check mode
bizcity_llm_mode();    // 'gateway' | 'direct'
bizcity_llm_is_ready(); // true | false

// Backward compat — still works:
bizcity_openrouter_chat( $messages );</code></pre>
                </div>

                <?php submit_button( __( 'Save Settings', 'bizcity-twin-ai' ), 'primary', 'bizcity_llm_submit' ); ?>
            </form>
        </div>
        <?php
    }

    /** Render model select dropdown with custom text input toggle. */
    private function render_model_select( string $name, string $current, array $catalog, string $purpose, string $slot ): void {
        $id = 'bizcity-llm-sel-' . $purpose . '-' . $slot;
        ?>
        <select name="<?php echo esc_attr( $name ); ?>" id="<?php echo $id; ?>"
                class="bizcity-llm-model-select regular-text"
                data-purpose="<?php echo esc_attr( $purpose ); ?>"
                data-slot="<?php echo esc_attr( $slot ); ?>">
            <optgroup label="<?php esc_attr_e( 'Curated Models', 'bizcity-twin-ai' ); ?>">
                <?php foreach ( $catalog as $m ) : ?>
                    <option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $current, $m['id'] ); ?>>
                        <?php echo esc_html( $m['name'] . '  (' . $m['id'] . ')' ); ?>
                    </option>
                <?php endforeach; ?>
            </optgroup>
        </select>
        <br/>
        <input type="text" name="<?php echo esc_attr( $name ); ?>" class="bizcity-llm-model-custom"
               value="<?php echo esc_attr( $current ); ?>" placeholder="<?php esc_attr_e( 'Enter custom model ID…', 'bizcity-twin-ai' ); ?>"
               style="display:none;margin-top:4px;width:100%" disabled />
        <a href="#" class="bizcity-llm-custom-toggle">✏️ <?php esc_html_e( 'Custom', 'bizcity-twin-ai' ); ?></a>
        <?php
    }

    /* ── Save (multisite) ── */
    public function save_settings(): void {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'You do not have permission.', 'bizcity-twin-ai' ) );
        }
        check_admin_referer( 'bizcity_llm_settings', '_wpnonce_llm' );
        $this->do_save();
        wp_redirect( add_query_arg(
            [ 'page' => 'bizcity-llm', 'updated' => '1' ],
            network_admin_url( 'settings.php' )
        ) );
        exit;
    }

    /* ── Save (single-site) ── */
    public function handle_save_single(): void {
        if ( empty( $_POST['bizcity_llm_save'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'bizcity_llm_settings', '_wpnonce_llm' );
        $this->do_save();
        wp_redirect( add_query_arg(
            [ 'page' => 'bizcity-llm', 'updated' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private function do_save(): void {
        // Always gateway mode in free version
        update_site_option( 'bizcity_llm_mode', 'gateway' );

        $gateway_url = esc_url_raw( $_POST['bizcity_llm_gateway_url'] ?? 'https://bizcity.vn' );
        update_site_option( 'bizcity_llm_gateway_url', $gateway_url );

        // API key — always from gateway field
        $api_key = sanitize_text_field( $_POST['bizcity_llm_api_key'] ?? '' );
        update_site_option( 'bizcity_llm_api_key', $api_key );

        // Tavily
        $tavily_key = sanitize_text_field( $_POST['bizcity_tavily_api_key'] ?? '' );
        update_site_option( 'bizcity_tavily_api_key', $tavily_key );

        // Settings array
        $raw      = $_POST['bizcity_llm_settings'] ?? [];
        $settings = [];
        $settings['site_name'] = sanitize_text_field( $raw['site_name'] ?? '' );
        $settings['timeout']   = intval( $raw['timeout'] ?? 60 );

        foreach ( BizCity_LLM_Models::purposes() as $purpose ) {
            $settings[ 'model_' . $purpose ] = sanitize_text_field(
                $raw[ 'model_' . $purpose ] ?? BizCity_LLM_Models::DEFAULTS[ $purpose ] ?? ''
            );
            $settings[ 'model_fallback_' . $purpose ] = sanitize_text_field(
                $raw[ 'model_fallback_' . $purpose ] ?? BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] ?? ''
            );
            $settings[ 'no_fallback_' . $purpose ] = ! empty( $raw[ 'no_fallback_' . $purpose ] ) ? 1 : 0;
        }

        update_site_option( 'bizcity_llm_settings', $settings );
        BizCity_LLM_Client::instance()->bust_models_cache();
    }

    /* ── Admin notice ── */
    public function admin_notices(): void {
        if ( ! isset( $_GET['updated'], $_GET['page'] ) || $_GET['page'] !== 'bizcity-llm' ) return;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '✅ BizCity LLM settings saved.', 'bizcity-twin-ai' ) . '</p></div>';
    }

    /* ── AJAX: test key ── */
    public function ajax_test_key(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $cap ) ) wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );

        $key  = get_site_option( 'bizcity_llm_api_key', '' );

        if ( empty( $key ) ) {
            wp_send_json_error( __( 'No API key entered.', 'bizcity-twin-ai' ) );
        }

        // [2026-03-25] Unified API namespace: migrate llm/router/v1/models → bizcity/v1/llm/models
        // $url      = BizCity_LLM_Client::instance()->get_gateway_url() . '/wp-json/llm/router/v1/models';
        $url      = BizCity_LLM_Client::instance()->get_gateway_url() . '/wp-json/bizcity/v1/llm/models';
        $response = wp_remote_get( $url, [
            'timeout'     => 10,
            'redirection' => 0,
            'headers'     => [ 'Authorization' => 'Bearer ' . $key ],
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $raw_body     = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        // Detect redirect
        if ( $code >= 300 && $code < 400 ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            wp_send_json_error( "HTTP {$code} redirect → {$location}" );
            return;
        }

        // Strip BOM if present & trim whitespace
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $raw_body = trim( $raw_body );

        $body = json_decode( $raw_body, true );

        // Non-JSON response
        if ( $body === null && json_last_error() !== JSON_ERROR_NONE ) {
            $preview  = mb_substr( strip_tags( $raw_body ), 0, 150 );
            $json_err = json_last_error_msg();
            wp_send_json_error( "HTTP {$code} — JSON decode failed: {$json_err} ({$content_type}): {$preview}" );
            return;
        }

        if ( $code === 200 ) {
            if ( isset( $body['success'] ) && ! $body['success'] ) {
                wp_send_json_error( $body['error'] ?? $body['message'] ?? 'Server returned success=false.' );
                return;
            }

            $count = count( $body['data'] ?? $body['models'] ?? [] );
            /* translators: %d: number of available models */
            wp_send_json_success( sprintf( __( 'Connection OK — %d models available.', 'bizcity-twin-ai' ), $count ) );
        } else {
            wp_send_json_error( $body['error']['message'] ?? $body['message'] ?? $body['error'] ?? "HTTP {$code}" );
        }
    }

    /* ── AJAX: fetch models ── */
    public function ajax_fetch_models(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $cap ) ) wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );

        BizCity_LLM_Client::instance()->bust_models_cache();
        $models = BizCity_LLM_Client::instance()->get_available_models();

        $simplified = array_map( fn( $m ) => [
            'id'      => $m['id'] ?? '',
            'name'    => $m['name'] ?? $m['id'] ?? '',
            'context' => $m['context_length'] ?? $m['ctx'] ?? 0,
        ], $models );

        usort( $simplified, fn( $a, $b ) => strcmp( $a['id'], $b['id'] ) );
        wp_send_json_success( $simplified );
    }

    /* ── AJAX: test Tavily ── */
    public function ajax_test_tavily_key(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $cap ) ) wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            $api_key = get_site_option( 'bizcity_tavily_api_key', '' );
        }
        if ( empty( $api_key ) ) {
            wp_send_json_error( __( 'No Tavily API key entered.', 'bizcity-twin-ai' ) );
        }

        $response = wp_remote_post( 'https://api.tavily.com/search', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'api_key'     => $api_key,
                'query'       => 'test connection bizcity',
                'max_results' => 1,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && isset( $body['results'] ) ) {
            wp_send_json_success( __( 'Tavily connection OK.', 'bizcity-twin-ai' ) );
        } else {
            wp_send_json_error( $body['message'] ?? $body['detail'] ?? "HTTP {$code}" );
        }
    }

    /* ── AJAX: register API key from gateway ── */
    public function ajax_register_key(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );
        }

        $gateway = BizCity_LLM_Client::instance()->get_gateway_url();
        $site    = home_url();
        $label   = sanitize_text_field( $_POST['label'] ?? wp_parse_url( $site, PHP_URL_HOST ) );
        $email   = get_option( 'admin_email', '' );
        if ( empty( $email ) ) {
            $email = get_site_option( 'admin_email', '' );
        }

        // [2026-03-25] Unified API namespace: migrate bizcity/llmhub/v1/register-key → bizcity/v1/register-key
        // $response = wp_remote_post( $gateway . '/wp-json/bizcity/llmhub/v1/register-key', [
        $response = wp_remote_post( $gateway . '/wp-json/bizcity/v1/register-key', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'site_url' => $site,
                'label'    => $label,
                'email'    => $email,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $body['api_key'] ) ) {
            update_site_option( 'bizcity_llm_api_key', sanitize_text_field( $body['api_key'] ) );
            update_site_option( 'bizcity_llm_mode', 'gateway' );
            wp_send_json_success( [
                'message' => __( 'API key created and saved automatically!', 'bizcity-twin-ai' ),
                'key_preview' => substr( $body['api_key'], 0, 12 ) . '…',
            ] );
        } else {
            wp_send_json_error( $body['message'] ?? $body['error'] ?? sprintf( __( 'HTTP %d — Registration failed.', 'bizcity-twin-ai' ), $code ) );
        }
    }

    /* ── AJAX: get usage log ── */
    public function ajax_usage_log(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );
        }

        if ( ! class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            wp_send_json_error( __( 'Usage log not installed.', 'bizcity-twin-ai' ) );
        }

        $page  = max( 1, intval( $_POST['page'] ?? 1 ) );
        $limit = 50;
        $rows  = BizCity_LLM_Usage_Log::get_recent( $limit, ( $page - 1 ) * $limit );

        wp_send_json_success( [
            'rows' => $rows,
            'page' => $page,
        ] );
    }

    /* ── AJAX: purge old log entries ── */
    public function ajax_purge_log(): void {
        check_ajax_referer( 'bizcity_llm_admin', 'nonce' );
        $cap = is_multisite() ? 'manage_network_options' : 'manage_options';
        if ( ! current_user_can( $cap ) ) {
            wp_send_json_error( __( 'Permission denied.', 'bizcity-twin-ai' ) );
        }

        if ( ! class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            wp_send_json_error( __( 'Usage log not installed.', 'bizcity-twin-ai' ) );
        }

        $days    = max( 7, intval( $_POST['days'] ?? 90 ) );
        $deleted = BizCity_LLM_Usage_Log::purge( $days );
        /* translators: 1: number of deleted records, 2: number of days */
        wp_send_json_success( sprintf( __( 'Deleted %1$d records older than %2$d days.', 'bizcity-twin-ai' ), $deleted, $days ) );
    }

    /* ================================================================
     *  Usage Dashboard (rendered on settings page)
     * ================================================================ */

    private function render_usage_dashboard(): void {
        if ( ! class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            return;
        }

        $stats_24h = BizCity_LLM_Usage_Log::get_stats( '24h' );
        $stats_7d  = BizCity_LLM_Usage_Log::get_stats( '7d' );
        $top       = BizCity_LLM_Usage_Log::get_top_models( 5, '7d' );
        $recent    = BizCity_LLM_Usage_Log::get_recent( 20 );
        ?>
        <div class="bizcity-llm-card">
            <h2>📊 Usage Dashboard</h2>

            <div class="bizcity-llm-stats-grid">
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Total Calls', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value"><?php echo intval( $stats_24h['total_calls'] ); ?></span>
                </div>
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Success', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value bizcity-llm-stat--ok"><?php echo intval( $stats_24h['success_count'] ); ?></span>
                </div>
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Errors', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value bizcity-llm-stat--err"><?php echo intval( $stats_24h['error_count'] ); ?></span>
                </div>
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Token (in/out)', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value">
                        <?php echo number_format( $stats_24h['total_prompt_tokens'] ); ?> /
                        <?php echo number_format( $stats_24h['total_completion_tokens'] ); ?>
                    </span>
                </div>
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Avg latency', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value"><?php echo intval( $stats_24h['avg_latency_ms'] ); ?> ms</span>
                </div>
                <div class="bizcity-llm-stat-box">
                    <span class="bizcity-llm-stat-label">24h — <?php esc_html_e( 'Fallback', 'bizcity-twin-ai' ); ?></span>
                    <span class="bizcity-llm-stat-value"><?php echo intval( $stats_24h['fallback_count'] ); ?></span>
                </div>
            </div>

            <details style="margin-top:12px">
                <summary style="cursor:pointer;font-weight:600">📈 <?php printf( esc_html__( '7-day: %1$d calls — Token %2$s', 'bizcity-twin-ai' ), intval( $stats_7d['total_calls'] ), number_format( $stats_7d['total_prompt_tokens'] + $stats_7d['total_completion_tokens'] ) ); ?></summary>
                <div class="bizcity-llm-stats-grid" style="margin-top:8px">
                    <div class="bizcity-llm-stat-box">
                        <span class="bizcity-llm-stat-label">7d — <?php esc_html_e( 'Success / Errors', 'bizcity-twin-ai' ); ?></span>
                        <span class="bizcity-llm-stat-value"><?php echo intval( $stats_7d['success_count'] ); ?> / <?php echo intval( $stats_7d['error_count'] ); ?></span>
                    </div>
                    <div class="bizcity-llm-stat-box">
                        <span class="bizcity-llm-stat-label">7d — <?php esc_html_e( 'Max latency', 'bizcity-twin-ai' ); ?></span>
                        <span class="bizcity-llm-stat-value"><?php echo intval( $stats_7d['max_latency_ms'] ); ?> ms</span>
                    </div>
                </div>
            </details>

            <?php if ( ! empty( $top ) ) : ?>
            <h3 style="margin-top:16px;font-size:0.95em">🏆 Top Models (7d)</h3>
            <table class="widefat striped" style="max-width:600px">
                <thead><tr><th>Model</th><th style="text-align:right">Calls</th><th style="text-align:right">Tokens</th><th style="text-align:right">Avg ms</th></tr></thead>
                <tbody>
                <?php foreach ( $top as $m ) : ?>
                    <tr>
                        <td><code style="font-size:12px"><?php echo esc_html( $m['model_used'] ?: '(empty)' ); ?></code></td>
                        <td style="text-align:right"><?php echo intval( $m['calls'] ); ?></td>
                        <td style="text-align:right"><?php echo number_format( $m['total_tokens'] ); ?></td>
                        <td style="text-align:right"><?php echo intval( $m['avg_latency'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h3 style="margin-top:16px;font-size:0.95em">📋 Recent Calls</h3>
            <?php if ( empty( $recent ) ) : ?>
                <p class="description"><?php esc_html_e( 'No usage data yet.', 'bizcity-twin-ai' ); ?></p>
            <?php else : ?>
                <div style="max-height:400px;overflow:auto">
                <table class="widefat striped" style="font-size:12px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Mode', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Purpose', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Model', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Tokens', 'bizcity-twin-ai' ); ?></th>
                            <th>ms</th>
                            <th><?php esc_html_e( 'Status', 'bizcity-twin-ai' ); ?></th>
                            <th><?php esc_html_e( 'Error', 'bizcity-twin-ai' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                            <td><?php echo esc_html( $row['mode'] ); ?></td>
                            <td><?php echo esc_html( $row['purpose'] ); ?></td>
                            <td>
                                <code style="font-size:11px"><?php echo esc_html( $row['model_used'] ?: $row['model_requested'] ); ?></code>
                                <?php if ( $row['fallback_used'] ) : ?><span title="Fallback used">🔶</span><?php endif; ?>
                            </td>
                            <td style="text-align:right"><?php echo intval( $row['tokens_prompt'] ); ?>/<?php echo intval( $row['tokens_completion'] ); ?></td>
                            <td style="text-align:right"><?php echo intval( $row['latency_ms'] ); ?></td>
                            <td><?php echo $row['success'] ? '✅' : '❌'; ?></td>
                            <td style="max-width:200px;word-break:break-all;color:#dc2626"><?php echo esc_html( mb_substr( $row['error'], 0, 120 ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                    <button type="button" id="bizcity-llm-load-more-log" class="button button-small" data-page="2"><?php esc_html_e( 'Load more…', 'bizcity-twin-ai' ); ?></button>
                    <button type="button" id="bizcity-llm-purge-log" class="button button-small" style="color:#dc2626">🗑️ <?php esc_html_e( 'Purge old logs (90 days)', 'bizcity-twin-ai' ); ?></button>
                    <span id="bizcity-llm-log-result"></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
