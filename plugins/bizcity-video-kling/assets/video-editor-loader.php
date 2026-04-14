<?php
/**
 * Video Editor Loader + Asset Proxy
 * Serves the React Video Editor SPA and all its assets through PHP.
 *
 * Routes:
 *   ?               → serves index.html (with rewritten asset URLs)
 *   ?bvk_asset=XYZ  → serves file from video-editor/ with correct MIME type
 */

// Load WordPress if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    $dir = __DIR__;
    for ( $i = 0; $i < 8; $i++ ) {
        $dir = dirname( $dir );
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            require_once $dir . '/wp-load.php';
            break;
        }
    }
}

// ── Asset proxy mode ──────────────────────────────────────────────────────────
if ( ! empty( $_GET['bvk_asset'] ) ) {
    // Sanitize: only allow alphanumeric, hyphen, underscore, dot, slash — no ..
    $rel = preg_replace( '/[^a-zA-Z0-9\-_\.\/]/', '', $_GET['bvk_asset'] );
    if ( strpos( $rel, '..' ) !== false ) { http_response_code( 403 ); exit; }

    $asset_path = __DIR__ . '/video-editor/' . ltrim( $rel, '/' );
    if ( ! file_exists( $asset_path ) || ! is_file( $asset_path ) ) {
        http_response_code( 404 ); exit;
    }

    $ext = strtolower( pathinfo( $asset_path, PATHINFO_EXTENSION ) );
    $mime_map = [
        'js'    => 'application/javascript; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'woff2' => 'font/woff2',
        'woff'  => 'font/woff',
        'ttf'   => 'font/ttf',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'ico'   => 'image/x-icon',
        'json'  => 'application/json',
        'wasm'  => 'application/wasm',
    ];
    $mime = $mime_map[ $ext ] ?? 'application/octet-stream';

    // For CSS files, rewrite relative url() references to go through the proxy
    if ( $ext === 'css' ) {
        $self_url_asset = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
        $self_url_asset .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
        $asset_dir = dirname( $rel ); // e.g. "assets"
        $css_content = file_get_contents( $asset_path );
        $css_content = preg_replace_callback(
            '/url\(\s*["\']?(\.\/)([^"\')\s]+)["\']?\s*\)/i',
            function( $m ) use ( $self_url_asset, $asset_dir ) {
                $resolved = $asset_dir . '/' . $m[2];
                return 'url("' . $self_url_asset . '?bvk_asset=' . htmlspecialchars( $resolved, ENT_QUOTES ) . '")';
            },
            $css_content
        );
        header( 'Content-Type: ' . $mime );
        header( 'Cache-Control: public, max-age=31536000, immutable' );
        header( 'Access-Control-Allow-Origin: *' );
        echo $css_content;
        exit;
    }

    header( 'Content-Type: ' . $mime );
    header( 'Cache-Control: public, max-age=31536000, immutable' );
    header( 'Content-Length: ' . filesize( $asset_path ) );
    $crossorigin_types = [ 'js', 'css', 'woff2', 'woff', 'ttf' ];
    if ( in_array( $ext, $crossorigin_types, true ) ) {
        header( 'Access-Control-Allow-Origin: *' );
    }
    readfile( $asset_path );
    exit;
}

// ── Auth gate ─────────────────────────────────────────────────────────────────
if ( function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
    http_response_code( 403 );
    echo '<!doctype html><html><head><meta charset="UTF-8"></head><body style="background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;gap:12px;"><span style="font-size:40px">🔒</span><p style="margin:0;color:#8b949e">Vui lòng đăng nhập để sử dụng Video Editor.</p></body></html>';
    exit;
}

// ── Serve index.html ──────────────────────────────────────────────────────────
$index_path = __DIR__ . '/video-editor/index.html';

if ( ! file_exists( $index_path ) ) {
    http_response_code( 503 );
    echo '<!doctype html><html><head><meta charset="UTF-8"></head><body style="background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;gap:16px;text-align:center;padding:24px;">';
    echo '<span style="font-size:48px">🎞️</span>';
    echo '<h2 style="margin:0;font-size:18px">Video Editor chưa được cài đặt</h2>';
    echo '<p style="color:#8b949e;margin:0;max-width:360px;line-height:1.6">File build chưa có trên server.<br>Upload thư mục <code style="background:#21262d;padding:2px 6px;border-radius:4px;font-size:13px">assets/video-editor/</code> lên server để kích hoạt.</p>';
    echo '</body></html>';
    exit;
}

// Build self-referencing proxy base URL
$self_url = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
$self_url .= '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
$proxy_base = $self_url . '?bvk_asset=';

$html = file_get_contents( $index_path );

// Rewrite asset paths to go through this PHP proxy
// Handles: src="./assets/...", href="./assets/...", src='./assets/...', href='./assets/...'
$html = preg_replace_callback(
    '/(?:src|href)=["\']\.\/([^"\']+)["\']/i',
    function( $m ) use ( $proxy_base ) {
        $attr = stripos( $m[0], 'href=' ) === 0 ? 'href' : 'src';
        return $attr . '="' . $proxy_base . htmlspecialchars( $m[1], ENT_QUOTES ) . '"';
    },
    $html
);

header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Frame-Options: SAMEORIGIN' );
header( 'Cache-Control: no-cache, must-revalidate' );

// Inject WP config for the React app to call admin-ajax.php
$bvk_config = '<script>window.BVK_WP={"ajax_url":"' . esc_js( admin_url( 'admin-ajax.php' ) ) . '","nonce":"' . esc_js( wp_create_nonce( 'bvk_nonce' ) ) . '"};</script>';
$html = str_replace( '</head>', $bvk_config . '</head>', $html );

echo $html;
exit;

