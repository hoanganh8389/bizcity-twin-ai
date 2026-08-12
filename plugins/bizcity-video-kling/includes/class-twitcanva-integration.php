<?php
/**
 * TwitCanva Video Workflow — WordPress Integration
 *
 * Adds admin page with iframe + settings panel.
 * AJAX handlers to save/load API keys and push them to Docker runtime.
 * REST API proxy for cross-origin requests.
 *
 * @package BizCity_Video_Kling
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_TwitCanva_Integration {

    /** Docker backend URL (internal network or localhost) */
    const BACKEND_URL = 'http://localhost:3001';

    /** WP option prefix for TwitCanva keys */
    const OPT_PREFIX = 'bzvideo_twitcanva_';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

        // AJAX handlers
        add_action( 'wp_ajax_bvk_save_twitcanva_config', [ __CLASS__, 'ajax_save_config' ] );
        add_action( 'wp_ajax_bvk_load_twitcanva_config', [ __CLASS__, 'ajax_load_config' ] );
        add_action( 'wp_ajax_bvk_push_twitcanva_config', [ __CLASS__, 'ajax_push_config' ] );
    }

    private static function send_error( $code, $message, $hint, $help_code, $status = 400, $context = array() ) {
        // [2026-08-10 Johnny Chu] PHASE-1.24-VIDEO-KLING — centralize TwitCanva integration AJAX errors.
        $payload = class_exists( 'BizCity_Error_Payload' )
            ? BizCity_Error_Payload::make( $code, $message, $hint, $help_code, $context )
            : array(
                'success'   => false,
                '_degraded' => true,
                'code'      => (string) $code,
                'message'   => (string) $message,
                'hint'      => (string) $hint,
                'help_code' => (string) $help_code,
                'context'   => (array) $context,
            );
        wp_send_json_error( $payload, (int) $status );
    }

    private static function rest_error_response( $code, $message, $hint, $help_code, $status = 502 ) {
        // [2026-08-10 Johnny Chu] R-ERROR-UX — normalize TwitCanva REST sidecar failures.
        $payload = class_exists( 'BizCity_Error_Payload' )
            ? BizCity_Error_Payload::make( $code, $message, $hint, $help_code )
            : array( 'success' => false, 'code' => (string) $code, 'message' => (string) $message, 'hint' => (string) $hint, 'help_code' => (string) $help_code );
        return new WP_REST_Response( $payload, (int) $status );
    }

    /* ═══════════════════════════════════════════════════════════
     *  ADMIN MENU
     * ═══════════════════════════════════════════════════════════ */

    public static function register_menu() {
        add_submenu_page(
            'bizcity-kling-settings',
            'AI Video Workflow',
            '🎬 Video Workflow',
            'edit_posts',
            'bzvideo-workflow',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ═══════════════════════════════════════════════════════════
     *  RENDER PAGE — Settings panel + iframe
     * ═══════════════════════════════════════════════════════════ */

    public static function render_page() {
        $backend_url = self::get_backend_url();
        $is_admin    = current_user_can( 'manage_options' );

        // [2026-08-10 Johnny Chu] R-1API-AUTH — never read local provider keys for display.
        $twitcanva_url = get_option( self::OPT_PREFIX . 'url', '' );
        ?>
        <style>
            #bzvideo-workflow-wrap { margin: 0; padding: 0; }
            #bzvideo-workflow-wrap iframe {
                width: 100%;
                height: calc(100vh - 32px);
                border: none;
                display: block;
            }
            #wpcontent { padding-left: 0 !important; }
            #wpbody-content { padding-bottom: 0 !important; }

            /* Settings panel */
            #twitcanva-settings-toggle {
                position: fixed; top: 36px; right: 12px; z-index: 9999;
                background: #1d2327; color: #fff; border: none; padding: 6px 14px;
                border-radius: 0 0 6px 6px; cursor: pointer; font-size: 13px;
            }
            #twitcanva-settings-toggle:hover { background: #2c3338; }
            #twitcanva-settings-panel {
                display: none; position: fixed; top: 62px; right: 12px; z-index: 9998;
                background: #fff; border: 1px solid #c3c4c7; border-radius: 8px;
                padding: 16px 20px; width: 420px; max-height: 80vh; overflow-y: auto;
                box-shadow: 0 4px 20px rgba(0,0,0,.15);
            }
            #twitcanva-settings-panel.open { display: block; }
            #twitcanva-settings-panel h3 { margin: 0 0 12px; font-size: 14px; }
            #twitcanva-settings-panel label { display: block; margin: 8px 0 2px; font-weight: 600; font-size: 12px; color: #50575e; }
            #twitcanva-settings-panel input[type=text],
            #twitcanva-settings-panel input[type=password] {
                width: 100%; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; font-family: monospace; font-size: 12px;
            }
            #twitcanva-settings-panel .tc-btn { padding: 6px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; border: 1px solid #2271b1; margin-right: 6px; }
            #twitcanva-settings-panel .tc-btn-primary { background: #2271b1; color: #fff; }
            #twitcanva-settings-panel .tc-btn-primary:hover { background: #135e96; }
            #twitcanva-settings-panel .tc-btn-secondary { background: #f0f0f1; color: #2271b1; }
            #twitcanva-settings-panel .tc-btn-secondary:hover { background: #dcdcde; }
            .tc-status { margin-top: 10px; padding: 6px 10px; border-radius: 4px; font-size: 12px; display: none; }
            .tc-status.ok { background: #d1e7dd; color: #0a3622; display: block; }
            .tc-status.err { background: #f8d7da; color: #58151c; display: block; }
            .tc-key-row { display: flex; align-items: center; gap: 4px; }
            .tc-key-row input { flex: 1; }
            .tc-key-row .tc-eye { cursor: pointer; font-size: 16px; padding: 4px; user-select: none; }
            .tc-divider { border-top: 1px solid #dcdcde; margin: 12px 0; }
            .tc-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 6px; }
            .tc-badge.on { background: #d1e7dd; color: #0a3622; }
            .tc-badge.off { background: #f8d7da; color: #58151c; }
        </style>

        <?php if ( $is_admin ) : ?>
        <button id="twitcanva-settings-toggle" type="button">⚙ API Config</button>
        <div id="twitcanva-settings-panel">
            <h3>Video Workflow Gateway</h3>
            <p style="color:#646970;font-size:12px;margin:0 0 10px;">Provider credential được quản lý ở BizCity Gateway. Plugin này không lưu hoặc đẩy key cục bộ.</p>

            <label>Docker Backend URL</label>
            <input type="text" id="tc-url" value="<?php echo esc_attr( $twitcanva_url ); ?>" placeholder="http://localhost:3001">

            <div class="tc-divider"></div>

            <div style="display:flex;align-items:center;gap:6px;">
                <button type="button" class="tc-btn tc-btn-primary" id="tc-save-btn">💾 Lưu & Push</button>
                <button type="button" class="tc-btn tc-btn-secondary" id="tc-status-btn">🔍 Check Status</button>
            </div>

            <div class="tc-status" id="tc-msg"></div>

            <div id="tc-key-status" style="margin-top:10px;"></div>
        </div>

        <script>
        (function(){
            const panel = document.getElementById('twitcanva-settings-panel');
            const toggle = document.getElementById('twitcanva-settings-toggle');
            const msg = document.getElementById('tc-msg');

            toggle.addEventListener('click', () => panel.classList.toggle('open'));

            function showMsg(text, ok) {
                msg.textContent = text;
                msg.className = 'tc-status ' + (ok ? 'ok' : 'err');
                setTimeout(() => { msg.style.display = 'none'; msg.className = 'tc-status'; }, 5000);
            }

            // Save backend URL only; provider credentials stay in the managed Hub.
            document.getElementById('tc-save-btn').addEventListener('click', () => {
                const data = new FormData();
                data.append('action', 'bvk_save_twitcanva_config');
                data.append('nonce', '<?php echo wp_create_nonce( 'bvk_nonce' ); ?>');
                data.append('url', document.getElementById('tc-url').value);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(r => {
                        if (r.success) {
                            showMsg(r.data.message, true);
                        } else {
                            showMsg(r.data.message || 'Lỗi', false);
                        }
                    })
                    .catch(e => showMsg(e.message, false));
            });

            // Check Status
            document.getElementById('tc-status-btn').addEventListener('click', () => {
                const data = new FormData();
                data.append('action', 'bvk_push_twitcanva_config');
                data.append('nonce', '<?php echo wp_create_nonce( 'bvk_nonce' ); ?>');
                data.append('status_only', '1');

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(r => {
                        const box = document.getElementById('tc-key-status');
                        if (r.success && r.data.status) {
                            const s = r.data.status;
                            const labels = {gemini:'Gemini',kling:'Kling',hailuo:'Hailuo',openai:'OpenAI',fal:'FAL.ai'};
                            let html = '';
                            for (const [k,v] of Object.entries(s)) {
                                const cls = v ? 'on' : 'off';
                                html += '<span class="tc-badge ' + cls + '">' + (labels[k]||k) + ': ' + (v?'✓':'✗') + '</span> ';
                            }
                            box.innerHTML = html;
                            showMsg('Docker đang chạy', true);
                        } else {
                            box.innerHTML = '<span class="tc-badge off">Docker offline</span>';
                            showMsg(r.data?.error || 'Không kết nối được Docker', false);
                        }
                    })
                    .catch(e => showMsg(e.message, false));
            });
        })();
        </script>
        <?php endif; ?>

        <div id="bzvideo-workflow-wrap">
            <?php
            $tc_cfg  = array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bvk_nonce' ),
            );
            $tc_hash = '#wp=' . strtr( base64_encode( wp_json_encode( $tc_cfg ) ), '+/', '-_' );
            $tc_src  = $backend_url . ( strpos( $backend_url, '#' ) === false ? $tc_hash : '' );
            ?>
            <iframe
                id="twitcanva-iframe"
                src="<?php echo esc_url( $tc_src ); ?>"
                allow="clipboard-write; clipboard-read"
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads"
            ></iframe>
        </div>
        <script>
        /* Inject WP AJAX config into iframe once loaded */
        (function(){
            var iframe = document.getElementById('twitcanva-iframe');
            var cfg = {
                ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
                nonce:   <?php echo wp_json_encode( wp_create_nonce( 'bvk_nonce' ) ); ?>
            };
            iframe.addEventListener('load', function() {
                try {
                    iframe.contentWindow.__tcWp = cfg;
                } catch(e) {
                    // Cross-origin fallback: use postMessage
                    iframe.contentWindow.postMessage({ type: '__tcWp', payload: cfg }, '*');
                }
            });
        })();
        </script>
        <?php
    }

    /* ═══════════════════════════════════════════════════════════
     *  GET BACKEND URL
     * ═══════════════════════════════════════════════════════════ */

    private static function get_backend_url() {
        if ( defined( 'TWITCANVA_URL' ) ) {
            return TWITCANVA_URL;
        }
        $url = get_option( self::OPT_PREFIX . 'url', '' );
        if ( $url ) return $url;
        // ARCHIVED 2026-06-01 — twitcanva-dist/ moved to plugins/_archived/bizcity-video-kling-twitcanva-dist/.
        // Build artifact (~3 MB) bị tách khỏi bundle chính (Phase 0.99 slim-down).
        // Guard giữ nguyên: nếu admin copy lại build vào plugin folder, sẽ tự dùng local; ngược lại fallback dev backend.
        if ( file_exists( BIZCITY_VIDEO_KLING_DIR . 'twitcanva-dist/index.html' ) ) {
            return BIZCITY_VIDEO_KLING_URL . 'twitcanva-dist/index.html';
        }
        return self::BACKEND_URL;
    }

    /**
     * Shared secret for config push (set in wp-config.php + docker-compose)
     */
    private static function get_config_secret() {
        return defined( 'TWITCANVA_CONFIG_SECRET' ) ? TWITCANVA_CONFIG_SECRET : '';
    }

    /* ═══════════════════════════════════════════════════════════
     *  AJAX: Save API keys to WP options + push to Docker
     * ═══════════════════════════════════════════════════════════ */

    public static function ajax_save_config() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            self::send_error( 'permission_denied', 'Bạn không có quyền cấu hình Video Workflow.', 'Đăng nhập bằng tài khoản quản trị rồi thử lại.', 'permission_required', 403 );
        }

        // [2026-08-10 Johnny Chu] R-1API-AUTH — backend URL is non-secret; provider fields are intentionally ignored.
        if ( isset( $_POST['url'] ) ) {
            $url = esc_url_raw( wp_unslash( $_POST['url'] ) );
            update_option( self::OPT_PREFIX . 'url', $url );
        }

        wp_send_json_success( [
            'message' => 'Đã lưu địa chỉ backend. Provider credential vẫn do BizCity Gateway quản lý.',
        ] );
    }

    /* ═══════════════════════════════════════════════════════════
     *  AJAX: Load saved config (for JS pre-fill)
     * ═══════════════════════════════════════════════════════════ */

    public static function ajax_load_config() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            self::send_error( 'permission_denied', 'Bạn không có quyền xem cấu hình Video Workflow.', 'Đăng nhập bằng tài khoản quản trị rồi thử lại.', 'permission_required', 403 );
        }

        wp_send_json_success( array(
            'url'     => get_option( self::OPT_PREFIX . 'url', '' ),
            'managed' => true,
        ) );
    }

    /* ═══════════════════════════════════════════════════════════
     *  AJAX: Push config / check status
     * ═══════════════════════════════════════════════════════════ */

    public static function ajax_push_config() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            self::send_error( 'permission_denied', 'Bạn không có quyền đồng bộ Video Workflow.', 'Đăng nhập bằng tài khoản quản trị rồi thử lại.', 'permission_required', 403 );
        }

        $status_only = ! empty( $_POST['status_only'] );

        if ( $status_only ) {
            $result = self::get_docker_status();
            if ( $result && ! isset( $result['error'] ) ) {
                wp_send_json_success( [ 'status' => $result ] );
            } else {
                self::send_error( 'gateway_degraded', 'Không kết nối được Video Workflow backend.', 'Kiểm tra backend rồi thử lại.', 'gateway_degraded', 502 );
            }
            return;
        }

        // [2026-08-10 Johnny Chu] R-1API-AUTH — local provider-key push is permanently disabled.
        self::send_error( 'unsupported_operation', 'Đồng bộ provider credential cục bộ đã bị tắt.', 'Dùng BizCity Gateway để cấu hình provider rồi thử lại.', 'gateway_degraded', 422 );
    }

    /**
     * Get Docker key status
     */
    private static function get_docker_status() {
        $url    = self::get_backend_url() . '/api/wp-config/status';
        $secret = self::get_config_secret();

        $response = wp_remote_get( $url, [
            'timeout' => 5,
            'headers' => [
                'X-WP-Config-Token' => $secret,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* ═══════════════════════════════════════════════════════════
     *  REST API PROXY
     * ═══════════════════════════════════════════════════════════ */

    public static function register_rest_routes() {
        // Proxy all /api/* calls
        register_rest_route( 'bzvideo/v1', '/twitcanva/(?P<path>.+)', [
            'methods'             => [ 'GET', 'POST', 'PUT', 'DELETE' ],
            'callback'            => [ __CLASS__, 'proxy_request' ],
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ] );

        // Health check
        register_rest_route( 'bzvideo/v1', '/twitcanva-status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'health_check' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ] );
    }

    public static function proxy_request( WP_REST_Request $request ) {
        $path   = $request->get_param( 'path' );
        $method = $request->get_method();
        $body   = $request->get_body();

        $target_url = self::get_backend_url() . '/api/' . ltrim( $path, '/' );

        $args = [
            'method'  => $method,
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ( in_array( $method, [ 'POST', 'PUT' ] ) && $body ) {
            $args['body'] = $body;
        }

        $response = wp_remote_request( $target_url, $args );

        if ( is_wp_error( $response ) ) {
            return self::rest_error_response( 'gateway_degraded', 'Video Workflow backend tạm thời không khả dụng.', 'Kiểm tra backend rồi thử lại sau ít phút.', 'gateway_degraded' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 400 ) {
            return self::rest_error_response( 'gateway_degraded', 'Video Workflow backend trả về lỗi.', 'Kiểm tra backend rồi thử lại sau ít phút.', 'gateway_degraded', 502 );
        }

        return new WP_REST_Response( $data ?: $body, $code );
    }

    public static function health_check() {
        $url      = self::get_backend_url() . '/api/workflows';
        $response = wp_remote_get( $url, [ 'timeout' => 5 ] );

        if ( is_wp_error( $response ) ) {
            return self::rest_error_response( 'gateway_degraded', 'Video Workflow backend đang ngoại tuyến.', 'Khởi động backend rồi thử lại.', 'gateway_degraded', 503 );
        }

        return new WP_REST_Response( [
            'status' => 'online',
            'url'    => self::get_backend_url(),
            'code'   => wp_remote_retrieve_response_code( $response ),
        ], 200 );
    }
}
