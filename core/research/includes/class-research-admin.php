<?php
/**
 * Research Admin — registers:
 *   1. Standalone admin page "Twin Research" (per-user projects)
 *   2. Character-edit tab injector (admin_footer JS that grafts a 4th tab)
 *
 * Both mount the same studio.js bundle with different bootData.
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Admin {

    const PAGE_SLUG = 'bizcity-twin-research';

    private static ?self $instance = null;
    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_menu(): void {
        // Phase G (2026-05-19) — promoted as submenu of Twin Chat
        // (admin.php?page=bizcity-twinchat). Slug unchanged → deep-links OK.
        add_submenu_page(
            'bizcity-twinchat',
            __( 'Twin Research', 'bizcity-twin-ai' ),
            __( 'Twin Research', 'bizcity-twin-ai' ),
            'read',
            self::PAGE_SLUG,
            [ $this, 'render_user_page' ]
        );
    }

    public function maybe_enqueue( string $hook ): void {
        $is_research_page = strpos( $hook, self::PAGE_SLUG ) !== false;
        $is_character_edit = (
            strpos( $hook, 'bizcity-knowledge-character-edit' ) !== false
            || strpos( $hook, 'knowledge-character-edit' ) !== false
        );

        if ( ! $is_research_page && ! $is_character_edit ) {
            return;
        }

        $base = plugins_url( '', BIZCITY_RESEARCH_DIR . 'bootstrap.php' ) . '/assets/';

        wp_enqueue_style(
            'bizcity-research-studio',
            $base . 'studio.css',
            [],
            BIZCITY_RESEARCH_VERSION
        );
        wp_enqueue_script(
            'bizcity-research-studio',
            $base . 'studio.js',
            [],
            BIZCITY_RESEARCH_VERSION,
            true
        );

        $boot = [
            'restBase'  => esc_url_raw( rest_url( 'bizcity/research/v1' ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'userId'    => get_current_user_id(),
            'isAdmin'   => current_user_can( 'manage_options' ),
            'i18n'      => [
                'planning'        => __( 'Planning', 'bizcity-twin-ai' ),
                'searching'       => __( 'Searching', 'bizcity-twin-ai' ),
                'generating'      => __( 'Generating Report', 'bizcity-twin-ai' ),
                'newSession'      => __( 'Dự án mới', 'bizcity-twin-ai' ),
                'noSessions'      => __( 'Chưa có dự án nào. Tạo mới ở góc trên.', 'bizcity-twin-ai' ),
                'send'            => __( 'Nghiên cứu', 'bizcity-twin-ai' ),
                'placeholder'     => __( 'Hỏi gì cũng được — agent sẽ tự tìm + extract + crawl…', 'bizcity-twin-ai' ),
                'sources'         => __( 'Nguồn', 'bizcity-twin-ai' ),
                'addToKnowledge'  => __( 'Thêm vào kiến thức', 'bizcity-twin-ai' ),
                'addedSuccess'    => __( 'Đã thêm thành công', 'bizcity-twin-ai' ),
                'fast'            => __( 'Nhanh', 'bizcity-twin-ai' ),
                'deep'            => __( 'Chuyên sâu', 'bizcity-twin-ai' ),
                'mode'            => __( 'Mode', 'bizcity-twin-ai' ),
                'thinking'        => __( 'Suy luận', 'bizcity-twin-ai' ),
                'report'          => __( 'Báo cáo', 'bizcity-twin-ai' ),
            ],
        ];

        wp_localize_script( 'bizcity-research-studio', 'BIZCITY_RESEARCH', $boot );
    }

    /**
     * Render the standalone "Twin Research" page (scope = current user).
     */
    public function render_user_page(): void {
        $user_id = get_current_user_id();
        ?>
        <div class="wrap bizcity-research-wrap">
            <h1 class="wp-heading-inline">
                🔬 <?php esc_html_e( 'Twin Research', 'bizcity-twin-ai' ); ?>
            </h1>
            <p class="description">
                <?php esc_html_e( 'Studio nghiên cứu chuyên sâu — tạo dự án, hỏi đa lượt, agent gọi Search/Extract/Crawl, xuất báo cáo markdown, thêm sources vào kho kiến thức cá nhân hoặc Twin Guru.', 'bizcity-twin-ai' ); ?>
            </p>

            <div
                id="bizcity-research-studio-root"
                data-scope-type="user"
                data-scope-id="<?php echo esc_attr( $user_id ); ?>"
            ></div>
        </div>
        <?php
    }

    /**
     * Inject Research tab into the character-edit page using JS:
     *  - adds a 4th button to .bk-tabs-nav
     *  - appends a #tab-research panel hosting the studio root
     *  - reuses existing tab-switching JS
     */
    public function maybe_inject_character_tab(): void {
        // Use $_GET['page'] — more reliable than screen->id for hidden submenu pages.
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( $page !== 'bizcity-knowledge-character-edit' ) return;

        $character_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
        if ( $character_id <= 0 ) return;
        ?>
        <script>
        (function () {
            function inject() {
                var nav = document.querySelector('.bk-tabs-nav');
                if (!nav || nav.querySelector('[data-tab="research"]')) return;

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'bk-tab-btn';
                btn.setAttribute('data-tab', 'research');
                btn.innerHTML = '<span class="dashicons dashicons-search"></span> 🔬 <?php echo esc_js( __( 'Nghiên cứu', 'bizcity-twin-ai' ) ); ?>';
                nav.appendChild(btn);

                var form = document.getElementById('character-form') || nav.parentNode;
                var panel = document.createElement('div');
                panel.className = 'bk-tab-content';
                panel.id = 'tab-research';
                panel.innerHTML = '<div class="bk-tab-inner"><div id="bizcity-research-studio-root"' +
                    ' data-scope-type="character"' +
                    ' data-scope-id="<?php echo esc_js( $character_id ); ?>"></div></div>';
                form.appendChild(panel);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', inject);
            } else {
                inject();
            }
        })();
        </script>
        <?php
    }
}
