<?php
/**
 * Full-page Video Editor at /video-editor/ (same-origin, no iframe).
 *
 * URLs:
 *   /video-editor/              — New blank project
 *   /video-editor/?id=Xk9Ab3   — Load existing project (encoded ID)
 *
 * Serves the React Video Editor SPA (Remotion + DesignCombo)
 * with project persistence — auto-save JSON template to DB.
 *
 * @package BizCity_Video_Kling
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Video_Editor_Page {

    const SLUG    = 'video-editor';
    const XOR_KEY = 0x7B2E4F9A;
    const B62     = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function init() {
        add_action( 'init',              [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'render' ] );
    }

    /* ═══════════ ID Encode / Decode (XOR + base62) ═══════════ */

    public static function encode_id( int $id ): string {
        $n = ( $id ^ self::XOR_KEY ) & 0xFFFFFFFF;
        if ( $n === 0 ) return '0';
        $s = '';
        while ( $n > 0 ) {
            $s = self::B62[ $n % 62 ] . $s;
            $n = intdiv( $n, 62 );
        }
        return $s;
    }

    public static function decode_id( string $hash ): int {
        if ( empty( $hash ) ) return 0;
        $n = 0;
        for ( $i = 0, $len = strlen( $hash ); $i < $len; $i++ ) {
            $pos = strpos( self::B62, $hash[ $i ] );
            if ( $pos === false ) return 0;
            $n = $n * 62 + $pos;
        }
        return ( $n ^ self::XOR_KEY ) & 0xFFFFFFFF;
    }

    /* ═══════════ Rewrite ═══════════ */

    public static function register_rewrite() {
        add_rewrite_rule(
            '^' . self::SLUG . '/?$',
            'index.php?bvk_video_editor=1',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'bvk_video_editor';
        return $vars;
    }

    /* ═══════════ Render full-page editor ═══════════ */

    public static function render() {
        if ( ! get_query_var( 'bvk_video_editor' ) ) {
            return;
        }

        /* ── Auth gate ── */
        if ( ! is_user_logged_in() ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            echo '<!doctype html><html><head><meta charset="UTF-8"></head>';
            echo '<body style="background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;gap:12px;">';
            echo '<span style="font-size:40px">🔒</span>';
            echo '<p style="margin:0;color:#8b949e">Vui lòng đăng nhập để sử dụng Video Editor.</p>';
            echo '</body></html>';
            exit;
        }

        $user_id = get_current_user_id();
        $nonce   = wp_create_nonce( 'bvk_nonce' );

        /* ── Decode project ID ── */
        $raw_id     = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
        $project_id = $raw_id ? self::decode_id( $raw_id ) : 0;

        /* ── Load project data from DB ── */
        $project_data  = null;
        $project_title = '';
        if ( $project_id ) {
            $project = BizCity_Video_Kling_Database::get_project( $project_id );
            if ( $project && (int) $project->user_id === $user_id ) {
                $project_data  = $project->data;
                $project_title = $project->title;
            } else {
                $project_id = 0; // Not found or not owner → will create new below
            }
        }

        /* ── Auto-create project if none ── */
        if ( ! $project_id ) {
            $project_id = BizCity_Video_Kling_Database::save_project( [
                'user_id' => $user_id,
                'title'   => 'Untitled video',
                'status'  => 'draft',
                'data'    => wp_json_encode( [] ),
            ] );
            if ( $project_id ) {
                $encoded = self::encode_id( $project_id );
                wp_safe_redirect( home_url( '/' . self::SLUG . '/?id=' . $encoded ) );
                exit;
            } else {
                header( 'Content-Type: text/html; charset=UTF-8' );
                echo '<!doctype html><html><head><meta charset="UTF-8"></head>';
                echo '<body style="background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px;">';
                echo '<div><span style="font-size:48px">⚠️</span>';
                echo '<h2 style="margin:16px 0 8px;font-size:18px">Không thể tạo project</h2>';
                echo '<p style="color:#8b949e;margin:0">Vui lòng thử lại hoặc liên hệ admin.</p>';
                echo '<a href="' . esc_url( home_url( '/' . self::SLUG . '/' ) ) . '" style="display:inline-block;margin-top:16px;padding:8px 20px;background:#238636;color:#fff;border-radius:6px;text-decoration:none">Thử lại</a>';
                echo '</div></body></html>';
                exit;
            }
        }

        /* ── Read index.html from build ── */
        $index_path = BIZCITY_VIDEO_KLING_DIR . 'assets/video-editor/index.html';
        if ( ! file_exists( $index_path ) ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            echo '<!doctype html><html><head><meta charset="UTF-8"></head>';
            echo '<body style="background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;gap:16px;text-align:center;padding:24px;">';
            echo '<span style="font-size:48px">🎞️</span>';
            echo '<h2 style="margin:0;font-size:18px">Video Editor chưa được cài đặt</h2>';
            echo '<p style="color:#8b949e;margin:0;max-width:360px;line-height:1.6">Upload thư mục <code style="background:#21262d;padding:2px 6px;border-radius:4px;font-size:13px">assets/video-editor/</code> lên server.</p>';
            echo '</body></html>';
            exit;
        }

        $html = file_get_contents( $index_path );

        /* ── Rewrite relative asset paths to absolute URLs ── */
        $base_url = BIZCITY_VIDEO_KLING_URL . 'assets/video-editor/';
        $html = preg_replace_callback(
            '/(?:src|href)=["\']\.\/([^"\']+)["\']/i',
            function( $m ) use ( $base_url ) {
                $attr = stripos( $m[0], 'href=' ) === 0 ? 'href' : 'src';
                return $attr . '="' . esc_url( $base_url . $m[1] ) . '"';
            },
            $html
        );

        /* ── Inject editor config before </head> ── */
        $title = $project_title ?: 'Video Editor — BizCity';

        $config_js  = '<title>' . esc_html( $title ) . '</title>' . "\n";
        $config_js .= '<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">' . "\n";
        $config_js .= '<style>html,body,#root{margin:0;padding:0;width:100%;height:100%;overflow:hidden;}</style>' . "\n";
        $config_js .= '<script>' . "\n";
        $config_js .= 'window.BVK_WP=' . wp_json_encode( [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => $nonce,
        ] ) . ";\n";
        $config_js .= 'window.__BVK_EDITOR_CONFIG__=' . wp_json_encode( [
            'projectId'   => $project_id ?: null,
            'projectData' => $project_data ? json_decode( $project_data, true ) : null,
            'title'       => $project_title,
            'userId'      => $user_id,
            'xorKey'      => self::XOR_KEY,
        ] ) . ";\n";
        $config_js .= '</script>' . "\n";

        // XOR + base62 encode helper for URL updates
        $config_js .= '<script>' . "\n";
        $config_js .= 'var _VEK=0x' . dechex( self::XOR_KEY ) . ',_VEB="' . self::B62 . '";' . "\n";
        $config_js .= 'function _veEncId(id){var n=(id^_VEK)>>>0;if(!n)return"0";var s="";while(n>0){s=_VEB[n%62]+s;n=Math.floor(n/62);}return s;}' . "\n";
        $config_js .= '</script>' . "\n";

        $html = str_replace( '</head>', $config_js . '</head>', $html );

        /* ── Project save URL updater (after React loads) ── */
        $post_js  = '<script>' . "\n";
        $post_js .= 'window.addEventListener("message",function(ev){' . "\n";
        $post_js .= '  if(ev.data&&ev.data.type==="bvk:project-saved"&&ev.data.projectId){' . "\n";
        $post_js .= '    var encoded=_veEncId(ev.data.projectId);' . "\n";
        $post_js .= '    var u=new URL(window.location.href);u.search="";u.searchParams.set("id",encoded);' . "\n";
        $post_js .= '    history.replaceState(null,"",u.toString());' . "\n";
        $post_js .= '  }' . "\n";
        $post_js .= '});' . "\n";
        $post_js .= '</script>' . "\n";

        $html = str_replace( '</body>', $post_js . '</body>', $html );

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        echo $html;
        exit;
    }
}
