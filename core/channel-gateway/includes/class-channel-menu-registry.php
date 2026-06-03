<?php
/**
 * BizCity Channel Menu Registry — bizchat-gateway Hub (PHASE 0.37 Shared API)
 *
 * Manages the `bizchat-gateway` admin page as the SINGLE admin surface for all
 * channel/integration configuration (R-CH-1).
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  wp-admin/admin.php?page=bizchat-gateway                    │
 * │                                                              │
 * │  Tabs: Channels · Adapters · Roles · Integrations · Logs · Diag│
 * │                                                              │
 * │  Each sub-page is a slot registered by a plugin via hook:    │
 * │    add_action('bizchat_gateway_register_subpages', function($reg){│
 * │        $reg->add_subpage([...]);                             │
 * │    });                                                       │
 * └─────────────────────────────────────────────────────────────┘
 *
 * Usage for plugin authors (register a sub-page):
 *
 *   add_action( 'bizchat_gateway_register_subpages', function( $reg ) {
 *       $reg->add_subpage([
 *           'group'      => 'channels',         // channels|adapters|roles|integrations|logs|diag
 *           'slug'       => 'zalo-bot',
 *           'title'      => 'Zalo Bot',
 *           'icon'       => '🤖',               // optional
 *           'capability' => 'manage_options',
 *           'callback'   => [ BizCity_Zalo_Bot_UI::class, 'render' ],
 *           'order'      => 10,
 *       ]);
 *   });
 *
 * URL pattern: admin.php?page=bizchat-gateway&group={group}&sub={slug}
 * Default render: overview when no group/sub given.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 * @see        PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md M1.W1
 * @see        PHASE-0-RULE-CHANNEL-ONLY.md R-CH-1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Channel_Menu_Registry {

	private static ?self $instance = null;

	/**
	 * Registered sub-pages, keyed by "{group}/{slug}".
	 * @var array<string, array>
	 */
	private array $subpages = [];

	/**
	 * Groups, ordered.
	 * @var array<string, array{label:string, icon:string, order:int}>
	 */
	private array $groups = [];

	private function __construct() {
		$this->init_default_groups();
		add_action( 'admin_menu',           [ $this, 'register_wp_menus' ], 15 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ═══════════════════════════════════════════
	 *  Default groups
	 * ═══════════════════════════════════════════ */

	private function init_default_groups(): void {
		$this->groups = [
			'channels'     => [ 'label' => 'Channels',      'icon' => '🔌', 'order' => 10 ],
			'adapters'     => [ 'label' => 'Adapters',      'icon' => '🧩', 'order' => 20 ],
			'roles'        => [ 'label' => 'Channel Roles', 'icon' => '🎭', 'order' => 30 ],
			'integrations' => [ 'label' => 'Integrations',  'icon' => '🔗', 'order' => 40 ],
			'logs'         => [ 'label' => 'Logs',          'icon' => '📜', 'order' => 50 ],
			'diag'         => [ 'label' => 'Diagnostics',   'icon' => '🩺', 'order' => 60 ],
		];
	}

	/* ═══════════════════════════════════════════
	 *  Public API
	 * ═══════════════════════════════════════════ */

	/**
	 * Register a sub-page slot.
	 *
	 * @param array $args {
	 *   @type string   $group      Group slug (channels|adapters|roles|integrations|logs|diag)
	 *   @type string   $slug       Unique sub-page slug within the group
	 *   @type string   $title      Display title in tab list
	 *   @type string   $icon       Optional emoji/icon prefix
	 *   @type string   $capability WP capability. Default 'manage_options'
	 *   @type callable $callback   Render function, receives (array $args): void
	 *   @type int      $order      Sort order within group. Default 50
	 * }
	 */
	public function add_subpage( array $args ): void {
		$group = sanitize_key( $args['group'] ?? 'channels' );
		$slug  = sanitize_key( $args['slug']  ?? '' );
		if ( ! $slug || ! $group ) {
			return;
		}
		$key = $group . '/' . $slug;
		$this->subpages[ $key ] = [
			'group'      => $group,
			'slug'       => $slug,
			'title'      => (string) ( $args['title'] ?? $slug ),
			'icon'       => (string) ( $args['icon'] ?? '' ),
			'capability' => (string) ( $args['capability'] ?? 'manage_options' ),
			'callback'   => $args['callback'] ?? null,
			'order'      => (int) ( $args['order'] ?? 50 ),
		];
	}

	/**
	 * Register a custom group (for advanced use).
	 */
	public function add_group( string $slug, string $label, string $icon = '', int $order = 99 ): void {
		$this->groups[ sanitize_key( $slug ) ] = compact( 'label', 'icon', 'order' );
	}

	/**
	 * Get subpages for a group, sorted by order.
	 *
	 * @return array<string, array>
	 */
	public function get_subpages( string $group ): array {
		$items = [];
		foreach ( $this->subpages as $key => $sp ) {
			if ( $sp['group'] === $group ) {
				$items[ $key ] = $sp;
			}
		}
		uasort( $items, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		return $items;
	}

	/* ═══════════════════════════════════════════
	 *  WP Menu registration
	 * ═══════════════════════════════════════════ */

	public function register_wp_menus(): void {
		// Fire hook so plugins can register their sub-pages before we render.
		// We fire this at admin_menu priority 15, plugins should hook at >= 10.
		do_action( 'bizchat_gateway_register_subpages', $this );

		// Register each subpage as a hidden WP submenu so screen_id resolves
		// correctly for capability checks. The actual routing is URL-param based.
		foreach ( $this->subpages as $sp ) {
			if ( ! current_user_can( $sp['capability'] ) ) {
				continue;
			}
			add_submenu_page(
				'',   // hidden parent = no sidebar entry
				$sp['title'],
				$sp['title'],
				$sp['capability'],
				'bizchat-gateway&group=' . rawurlencode( $sp['group'] ) . '&sub=' . rawurlencode( $sp['slug'] ),
				'__return_null'
			);
		}
	}

	/* ═══════════════════════════════════════════
	 *  Renderer — called from BizCity_Gateway_Admin::render_overview()
	 * ═══════════════════════════════════════════ */

	/**
	 * Render the full hub UI.
	 * Call this from BizCity_Gateway_Admin::render_overview() (or replace it).
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}

		$active_group = sanitize_key( $_GET['group'] ?? 'channels' );
		$active_sub   = sanitize_key( $_GET['sub']   ?? '' );

		echo '<div class="wrap bzc-gateway-hub">';

		// Sub-page routing — render the sub directly without inner tabs/header
		// to keep the UI clean (per user feedback: just show what was clicked).
		if ( $active_sub ) {
			$key = $active_group . '/' . $active_sub;
			if ( isset( $this->subpages[ $key ] ) ) {
				$sp = $this->subpages[ $key ];
				if ( ! current_user_can( $sp['capability'] ) ) {
					echo '<div class="notice notice-error"><p>Bạn không có quyền truy cập.</p></div>';
				} elseif ( is_callable( $sp['callback'] ) ) {
					call_user_func( $sp['callback'], $sp );
				} else {
					echo '<div class="notice notice-warning"><p>Trang này chưa có nội dung (callback chưa triển khai).</p></div>';
				}
			} else {
				echo '<div class="notice notice-warning"><p>Sub-page không tìm thấy.</p></div>';
			}
		} else {
			echo '<h1>🔌 BizChat Gateway</h1>';
			$this->render_group_index( $active_group );
		}

		echo '</div>'; // .bzc-gateway-hub
	}

	/* ═══════════════════════════════════════════
	 *  Internal render helpers
	 * ═══════════════════════════════════════════ */

	private function render_group_tabs( string $active_group ): void {
		$sorted = $this->groups;
		uasort( $sorted, fn( $a, $b ) => $a['order'] <=> $b['order'] );

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:0;">';
		$iframe_qs = $this->is_iframe_context() ? '&bizcity_iframe=1' : '';
		$base = admin_url( 'admin.php?page=bizchat-gateway' ) . $iframe_qs;
		foreach ( $sorted as $slug => $info ) {
			$is_active = ( $slug === $active_group );
			$url       = esc_url( $base . '&group=' . rawurlencode( $slug ) );
			$class     = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
			echo '<a href="' . $url . '" class="' . esc_attr( $class ) . '">'
				. esc_html( $info['icon'] . ' ' . $info['label'] )
				. '</a>';
		}
		echo '</nav>';
		echo '<div style="border:1px solid #ccd0d4;border-top:none;padding:16px;background:#fff;">';
	}

	private function render_group_index( string $group ): void {
		if ( $group === 'channels' && ! $this->has_subpages( 'channels' ) ) {
			$this->render_overview_dashboard();
		} else {
			$items = $this->get_subpages( $group );
			if ( empty( $items ) ) {
				echo '<p style="color:#888;">Nhóm này chưa có trang nào được đăng ký.<br>'
					. 'Plugins dùng hook <code>bizchat_gateway_register_subpages</code> để thêm vào.</p>';
			} else {
				echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">';
				$iframe_qs = $this->is_iframe_context() ? '&bizcity_iframe=1' : '';
				$base = admin_url( 'admin.php?page=bizchat-gateway' ) . $iframe_qs;
				foreach ( $items as $sp ) {
					$url = esc_url( $base . '&group=' . rawurlencode( $group ) . '&sub=' . rawurlencode( $sp['slug'] ) );
					echo '<a href="' . $url . '" style="display:block;padding:12px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;text-decoration:none;color:#23282d;">'
						. esc_html( $sp['icon'] . ' ' . $sp['title'] )
						. '</a>';
				}
				echo '</div>';
			}
		}
	}

	private function has_subpages( string $group ): bool {
		foreach ( $this->subpages as $sp ) {
			if ( $sp['group'] === $group ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect iframe context even when bizcity_iframe=1 is missing from the
	 * current URL due to deep-link wrappers or refresh flows.
	 */
	private function is_iframe_context(): bool {
		if ( ! empty( $_GET['bizcity_iframe'] ) ) {
			return true;
		}

		$fetch_dest = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' ) );
		if ( $fetch_dest === 'iframe' ) {
			return true;
		}

		$probes = [];
		foreach ( [ '_iurl', '_url', 'redirect_to' ] as $key ) {
			if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
				$probes[] = (string) wp_unslash( $_GET[ $key ] );
			}
		}

		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$probes[] = (string) $_SERVER['REQUEST_URI'];
		}

		foreach ( $probes as $raw ) {
			$sample = (string) $raw;
			for ( $i = 0; $i < 2; $i++ ) {
				$decoded = rawurldecode( $sample );
				if ( $decoded === $sample ) {
					break;
				}
				$sample = $decoded;
			}

			if (
				stripos( $sample, 'bizcity_iframe=1' ) !== false ||
				stripos( $sample, 'bizcity_iframe=true' ) !== false
			) {
				return true;
			}
		}

		return false;
	}

	private function render_overview_dashboard(): void {
		echo '<h2>🔌 Channel Gateway Overview</h2>';

		// Adapter health grid.
		$bridge   = class_exists( 'BizCity_Gateway_Bridge' ) ? BizCity_Gateway_Bridge::instance() : null;
		$adapters = $bridge ? $bridge->get_all_adapters() : [];
		if ( $adapters ) {
			echo '<h3>Active Adapters</h3>';
			echo '<table class="widefat striped" style="max-width:600px;"><thead><tr>'
				. '<th>Platform</th><th>Class</th><th>Health</th>'
				. '</tr></thead><tbody>';
			foreach ( $adapters as $adapter ) {
				$platform = is_object( $adapter ) && method_exists( $adapter, 'get_platform' )
					? $adapter->get_platform()
					: ( is_object( $adapter ) && method_exists( $adapter, 'inbound_platform' ) ? $adapter->inbound_platform() : '?' );
				$health   = is_object( $adapter ) && method_exists( $adapter, 'health' )
					? $adapter->health()
					: [ 'ok' => null ];
				$dot      = $health['ok'] === true ? '🟢' : ( $health['ok'] === false ? '🔴' : '⚪' );
				echo '<tr>'
					. '<td><strong>' . esc_html( $platform ) . '</strong></td>'
					. '<td><code>' . esc_html( get_class( $adapter ) ) . '</code></td>'
					. '<td>' . esc_html( $dot ) . ' ' . esc_html( $health['last_error'] ?? '' ) . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Chưa có adapter nào đăng ký. Kích hoạt plugin kênh (Zalo Bot, Facebook Bot, ...) để thấy adapter.</p>';
		}

		// Channel Role summary.
		echo '<h3>Channel Role Assignments</h3>';
		$assignments = get_option( 'bizcity_channel_roles', [] );
		if ( $assignments ) {
			echo '<table class="widefat striped" style="max-width:400px;"><thead><tr><th>Channel</th><th>Role</th></tr></thead><tbody>';
			foreach ( (array) $assignments as $ch => $role ) {
				echo '<tr><td><code>' . esc_html( $ch ) . '</code></td><td>' . esc_html( $role ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Chưa có gán role. Mặc định theo platform.</p>';
		}

		echo '<p style="margin-top:16px;"><a href="' . esc_url( admin_url( 'tools.php?page=bizcity-channel-phase-037-diag' ) ) . '" class="button">🩺 PHASE 0.37 Diagnostic</a></p>';
		echo '</div>'; // tab content
	}

	/* ═══════════════════════════════════════════
	 *  Assets
	 * ═══════════════════════════════════════════ */

	public function enqueue_assets( string $hook ): void {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'bizchat-gateway' ) {
			return;
		}

		$iframe_mode = $this->is_iframe_context();
		// Inline minimal CSS — no separate asset file needed.
		$css = '
			.bzc-gateway-hub .nav-tab-active { background:#fff; border-bottom-color:#fff; }
			.bzc-gateway-hub h2 { margin-top: 8px; }
		';

		if ( $iframe_mode ) {
			$css .= '
				#adminmenumain, #adminmenuback, #adminmenuwrap,
				#adminmenu, #collapse-menu {
					display: none !important;
				}
				#wpcontent, #wpfooter {
					margin-left: 0 !important;
				}
				#wpadminbar {
					display: none !important;
				}
				html.wp-toolbar {
					padding-top: 0 !important;
				}
				#wpfooter {
					display: none !important;
				}
			';
		}

		wp_add_inline_style( 'common', $css );

		// When loaded inside a TwinChat iframe, intercept ALL link clicks and
		// inject bizcity_iframe=1 so deep navigation never loses the iframe param.
		if ( $iframe_mode ) {
			$js = <<<'JS'
(function(){
  // Self-heal deep links: keep iframe marker in current URL after refresh.
  try{
    var selfUrl=new URL(window.location.href);
    if(!selfUrl.searchParams.has('bizcity_iframe')){
      selfUrl.searchParams.set('bizcity_iframe','1');
      window.history.replaceState(null,'',selfUrl.toString());
    }
  }catch(err){}

  document.addEventListener('click',function(e){
    var a=e.target.closest('a[href]');
    if(!a)return;
    var href=a.getAttribute('href');
    if(!href||href.indexOf('bizcity_iframe')!==-1)return;
    // Only same-origin wp-admin links (not #anchors, not external).
    if(href.charAt(0)==='#')return;
    try{
      var u=new URL(href,window.location.origin);
      if(u.hostname!==window.location.hostname)return;
      u.searchParams.set('bizcity_iframe','1');
      e.preventDefault();
      window.location.href=u.toString();
    }catch(err){}
  },true);
})();
JS;
			wp_add_inline_script( 'common', $js );
		}
	}
}

// Bootstrap as singleton so the hooks are registered.
BizCity_Channel_Menu_Registry::instance();
