<?php
/**
 * Full-page Design Editor at /canva/ (no iframe).
 *
 * URLs:
 *   /canva/              — New blank design
 *   /canva/?id=Ab3kX9    — Load project (encoded ID)
 *   /canva/?tpl=15       — Start from template #15
 *
 * Because this is a real WordPress page (same-origin cookie auth),
 * nonce validation works normally — no iframe hacks needed.
 *
 * @package BizCity_Tool_Image
 * @since   3.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Canva_Page {

    const SLUG    = 'canva';
    const XOR_KEY = 0x5A3C7E1D;
    const B62     = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function init() {
        add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'register_query_var' ) );
        add_action( 'template_redirect', array( __CLASS__, 'render' ) );
    }

    /* ═══════════ ID Encode / Decode (XOR + base62) ═══════════ */

    public static function encode_id( int $id ): string {
        $n = ( $id ^ self::XOR_KEY ) & 0xFFFFFFFF; // unsigned 32-bit
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
            'index.php?bztimg_canva=1',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'bztimg_canva';
        return $vars;
    }

    /* ═══════════ Render full-page editor ═══════════ */
    public static function render() {
        if ( ! get_query_var( 'bztimg_canva' ) ) {
            return;
        }

        $user_id = get_current_user_id();
        $nonce   = wp_create_nonce( 'wp_rest' );

        // Decode encoded project ID from URL
        $raw_id      = isset( $_GET['id'] ) ? sanitize_text_field( $_GET['id'] ) : '';
        $project_id  = $raw_id ? self::decode_id( $raw_id ) : 0;
        $template_id = isset( $_GET['tpl'] ) ? absint( $_GET['tpl'] ) : 0;

        // Build asset URLs
        $build_url = BZTIMG_URL . 'design-editor-build/';
        $build_dir = BZTIMG_DIR . 'design-editor-build/assets/';
        $ts        = BZTIMG_VERSION;

        // Auto-detect hashed bundle filenames from build output
        $js_bundle  = 'index.js';
        $css_bundle = 'index.css';
        $js_files   = glob( $build_dir . 'index-*.js' );
        $css_files  = glob( $build_dir . 'index-*.css' );
        if ( $js_files )  $js_bundle  = basename( $js_files[0] );
        if ( $css_files ) $css_bundle = basename( $css_files[0] );

        $title = 'Design Editor — BizCity';
        if ( $project_id ) {
            $title = 'Dự án — Design Editor';
        }

        header( 'Content-Type: text/html; charset=UTF-8' );
        ?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $title ); ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<link rel="stylesheet" href="<?php echo esc_url( $build_url . 'fonts/fonts.css' ); ?>">
<link rel="stylesheet" crossorigin="anonymous" href="<?php echo esc_url( $build_url . 'assets/' . $css_bundle . '?v=' . $ts ); ?>">
<style>
html,body,#root{margin:0;padding:0;width:100%;height:100%;overflow:hidden;}

/* ── Template panel: 2-column grid ── */
.css-ake55t {
  display: grid !important;
  grid-template-columns: repeat(2, 1fr) !important;
  gap: 6px !important;
  padding: 6px !important;
  align-content: start !important;
}
.css-ake55t .css-11408bl {
  width: 100% !important;
  overflow: hidden !important;
  border-radius: 4px !important;
}
.css-ake55t .css-11408bl img {
  width: 100% !important;
  height: auto !important;
  display: block !important;
}

/* ── Shapes panel: 3-column grid + fix overlay ── */
.css-ytr5uu {
  display: grid !important;
  grid-template-columns: repeat(3, 1fr) !important;
  gap: 6px !important;
  padding: 6px !important;
  align-content: start !important;
}
.css-gx0lhm {
  width: 100% !important;
}
.css-gx0lhm .css-11408bl {
  position: relative !important;
  width: 100% !important;
  aspect-ratio: 1 / 1 !important;
  overflow: hidden !important;
}
/* Loading placeholder — keep behind the image */
.css-1rea78r {
  position: absolute !important;
  inset: 0 !important;
  z-index: 0 !important;
  pointer-events: none !important;
}
/* Image wrapper — must sit above placeholder */
.css-1jkxlo6 {
  position: relative !important;
  z-index: 1 !important;
  width: 100% !important;
  height: 100% !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
}
.css-vnkovg {
  width: 100% !important;
  height: 100% !important;
  object-fit: contain !important;
  display: block !important;
}

/* ── Hide GitHub / Gumroad branding buttons ── */
[data-tooltip-id="btn_ca_aUxJ8av"],
button[class*="css-"] > span > svg[viewBox="0 0 16 16"] {
  display: none !important;
}
/* Target the GitHub button parent more broadly */
button.css-lykhks {
  display: none !important;
}
</style>
</head>
<body>
<div id="root"></div>
<script>
// ── ID encoder (same XOR + base62 as PHP) ──
var _XK = 0x5A3C7E1D, _B62 = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
function _encId(id) {
    var n = (id ^ _XK) >>> 0;
    if (!n) return '0';
    var s = '';
    while (n > 0) { s = _B62[n % 62] + s; n = Math.floor(n / 62); }
    return s;
}

// ── Inject config BEFORE React loads ──
window.__BZTIMG_CONFIG__ = {
    restUrl:    <?php echo wp_json_encode( rest_url( 'bztool-image/v1/' ) ); ?>,
    nonce:      <?php echo wp_json_encode( $nonce ); ?>,
    userId:     <?php echo (int) $user_id; ?>,
    siteUrl:    <?php echo wp_json_encode( home_url() ); ?>,
    pluginUrl:  <?php echo wp_json_encode( $build_url ); ?>,
    projectId:  <?php echo $project_id ? $project_id : 'null'; ?>,
    templateId: <?php echo $template_id ? $template_id : 'null'; ?>
};

sessionStorage.setItem('userToken', <?php echo wp_json_encode( $nonce ); ?>);

// Inject URL params for WPEditor.parseUrlConfig()
(function(){
    var u = new URL(window.location.href);
    var c = window.__BZTIMG_CONFIG__;
    if (!u.searchParams.get('restUrl')) {
        u.searchParams.set('restUrl', c.restUrl);
        u.searchParams.set('nonce', c.nonce);
        u.searchParams.set('userId', c.userId);
        u.searchParams.set('siteUrl', c.siteUrl);
        u.searchParams.set('pluginUrl', c.pluginUrl);
        if (c.projectId) u.searchParams.set('projectId', c.projectId);
        history.replaceState(null, '', u.toString());
    }
})();
</script>
<script type="module" crossorigin="anonymous" src="<?php echo esc_url( $build_url . 'assets/' . $js_bundle . '?v=' . $ts ); ?>"></script>
<script src="https://unpkg.com/html-to-image@1.11.11/dist/html-to-image.js"></script>
<script>
/* ── Monkey-patch htmlToImage.toPng to cache the page element ── */
(function(){
    var _waitLib = setInterval(function(){
        if (!window.htmlToImage || !window.htmlToImage.toPng) return;
        clearInterval(_waitLib);
        var _orig = window.htmlToImage.toPng;
        window.__bzt_lastPageEl = null;
        window.htmlToImage.toPng = function(el, opts) {
            window.__bzt_lastPageEl = el;
            return _orig.apply(this, arguments);
        };
    }, 50);
})();
</script>
<script>
/* ── Save-to-Media button injection ── */
(function(){
    var REST_URL = <?php echo wp_json_encode( rest_url( 'image-editor/v1/save-to-media' ) ); ?>;
    var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
    var INJECTED = false;

    function createSaveBtn() {
        if (INJECTED) return;
        var exportBtn = document.querySelector('button.css-1egzvsu');
        if (!exportBtn) return;
        INJECTED = true;

        var btn = document.createElement('button');
        btn.className = exportBtn.className;
        btn.innerHTML =
            '<div class="css-14is9qy">' +
                '<svg width="25" height="25" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                    '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" ' +
                          'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
                '</svg>' +
            '</div>' +
            '<span class="css-4cf4s9">Lưu Media</span>';
        btn.title = 'Lưu ảnh vào Media Library';
        btn.style.marginLeft = '6px';

        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            btn.disabled = true;
            var origText = btn.querySelector('.css-4cf4s9');
            origText.textContent = 'Đang lưu…';

            try {
                /* Reuse the exact same element that built-in Export uses.
                   The monkey-patched toPng caches it in __bzt_lastPageEl.
                   If user hasn't exported yet, find it the same way the editor does:
                   .page-content → parent(.css-14kkpju) → parent(.css-1jpzg9z) */
                var pageEl = window.__bzt_lastPageEl;
                if (!pageEl || !document.contains(pageEl)) {
                    var pc = document.querySelector('.page-content');
                    pageEl = pc && pc.parentElement && pc.parentElement.parentElement;
                }
                if (!pageEl) throw new Error('Chưa có trang để lưu. Hãy nhấn Xuất trước 1 lần.');

                var dataUrl = await window.htmlToImage.toPng(pageEl, {
                    quality: 1,
                    cacheBust: true,
                    filter: function(node) {
                        return !(node.classList && node.classList.contains('selection-box'));
                    }
                });

                var resp = await fetch(REST_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': NONCE
                    },
                    body: JSON.stringify({
                        dataUrl: dataUrl,
                        filename: 'design-' + Date.now() + '.png'
                    })
                });

                var json = await resp.json();
                if (!resp.ok) throw new Error(json.message || json.error || 'Lưu thất bại');

                origText.textContent = '✓ Đã lưu';
                setTimeout(function(){ origText.textContent = 'Lưu Media'; }, 2500);
            } catch(err) {
                origText.textContent = '✗ Lỗi';
                console.error('[SaveToMedia]', err);
                alert('Lưu Media thất bại: ' + err.message);
                setTimeout(function(){ origText.textContent = 'Lưu Media'; }, 2500);
            } finally {
                btn.disabled = false;
            }
        });

        exportBtn.parentNode.insertBefore(btn, exportBtn.nextSibling);
    }

    var iv = setInterval(function() {
        createSaveBtn();
        if (INJECTED) clearInterval(iv);
    }, 500);
    setTimeout(function() { clearInterval(iv); }, 30000);

    var obs = new MutationObserver(function() { createSaveBtn(); if (INJECTED) obs.disconnect(); });
    obs.observe(document.body, { childList: true, subtree: true });
})();
</script>
<script>
// Auto-update URL with encoded ID when project is saved
window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.type === 'bztimg:saved' && ev.data.payload && ev.data.payload.id) {
        var encoded = _encId(ev.data.payload.id);
        var u = new URL(window.location.href);
        // Clean up internal params — keep only id/tpl for clean share URL
        u.search = '';
        u.searchParams.set('id', encoded);
        history.replaceState(null, '', u.toString());
    }
    if (ev.data && ev.data.type === 'bztimg:removed') {
        var u = new URL(window.location.href);
        u.search = '';
        history.replaceState(null, '', u.toString());
    }
});
</script>
</body>
</html>
<?php
        exit;
    }
}
