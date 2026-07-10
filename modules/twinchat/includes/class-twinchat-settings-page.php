<?php
/**
 * Bizcity Twin AI — TwinChat Settings Page (Unified BizCity API Gateway)
 *
 * Canonical admin Settings surface for R-1API ("One API Key for All Services").
 * URL: admin.php?page=bizcity-twinchat-settings
 * Parent menu: bizcity-twinchat (TwinChat).
 *
 * Stores ONLY the canonical site options defined by R-1API-2:
 *   - bizcity_llm_api_key        (Bearer token "biz-…")
 *   - bizcity_llm_gateway_url    (default https://bizcity.vn)
 *
 * Per R-1API-9 (Single canonical settings page) every plugin in the BizCity
 * ecosystem MUST link admins here instead of building its own gateway UI.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-17
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Settings_Page {

	const PAGE_SLUG       = 'bizcity-twinchat-settings';
	const PARENT_SLUG     = 'bizcity-twinchat';
	const OPT_API_KEY     = 'bizcity_llm_api_key';
	const OPT_GATEWAY_URL = 'bizcity_llm_gateway_url';
	const DEFAULT_GATEWAY = 'https://bizcity.vn';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'admin_menu',                                  [ $this, 'add_menu' ], 11 );
		add_action( 'admin_post_bizcity_twinchat_save_gateway',    [ $this, 'handle_save' ] );
		add_action( 'admin_post_bizcity_twinchat_test_gateway',    [ $this, 'handle_test' ] );
		add_action( 'admin_post_bizcity_twinchat_register_key',    [ $this, 'handle_register' ] );
		// [2026-06-03 Johnny Chu] R-1API — Live account/balance/usage ping (JS-driven dialog).
		add_action( 'wp_ajax_bizcity_twinchat_account_status',     [ $this, 'ajax_account_status' ] );
		// [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — fetch plan config from hub.
		add_action( 'wp_ajax_bizcity_twinchat_plan_config',        [ $this, 'ajax_plan_config' ] );
		// [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — 6-dimension usage stats tab.
		add_action( 'wp_ajax_bizcity_twinchat_usage_stats',        [ $this, 'ajax_usage_stats' ] );
	}

	public function add_menu() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'BizCity API & Gateway', 'bizcity-twin-ai' ),
			__( '⚙ Settings', 'bizcity-twin-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/* ── Canonical accessors (used by other plugins via static call) ── */

	/** @return string Canonical Bearer key, '' if not configured. */
	public static function get_api_key(): string {
		// [2026-06-11 Johnny Chu] HOTFIX — per-site option (không dùng site_option)
		return (string) get_option( self::OPT_API_KEY, '' );
	}

	/** @return string Canonical gateway base URL (no trailing slash). */
	public static function get_gateway_url(): string {
		// [2026-06-11 Johnny Chu] HOTFIX — per-site option
		$base = (string) get_option( self::OPT_GATEWAY_URL, '' );
		if ( $base === '' ) {
			$base = self::DEFAULT_GATEWAY;
		}
		return untrailingslashit( $base );
	}

	public static function get_masked_key(): string {
		$k = self::get_api_key();
		if ( $k === '' ) return '';
		return substr( $k, 0, 6 ) . '…' . substr( $k, -4 );
	}

	/** Absolute admin URL for the canonical settings page (for use by other plugins). */
	public static function admin_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/* ── Handlers ── */

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
		check_admin_referer( 'bizcity_twinchat_save_gateway' );

		$key = isset( $_POST['bizcity_llm_api_key'] )
			? trim( wp_unslash( $_POST['bizcity_llm_api_key'] ) )
			: '';
		$url = isset( $_POST['bizcity_llm_gateway_url'] )
			? esc_url_raw( wp_unslash( $_POST['bizcity_llm_gateway_url'] ) )
			: '';

		// [2026-06-11 Johnny Chu] HOTFIX — per-site option
		update_option( self::OPT_API_KEY,     $key );
		update_option( self::OPT_GATEWAY_URL, $url );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'saved' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
		check_admin_referer( 'bizcity_twinchat_test_gateway' );

		$started = microtime( true );
		$result  = self::probe_account_info();
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		// [2026-06-11 Johnny Chu] HOTFIX — unified per-site option (biếtết bizcity_llm_last_test_at + bizcity_llm_last_test_result)
		update_option( 'bizcity_llm_last_test', [
			'ok'      => (bool) $result['success'],
			'ts'      => time(),
			'ms'      => $latency,
			'code'    => 'http_' . $result['status'],
			'status'  => (int) $result['status'],
			'message' => (string) ( $result['error'] ?? '' ),
			'tier'    => (string) ( $result['tier']    ?? '' ),
			'balance' => (string) ( $result['balance'] ?? '' ),
		] );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'tested' => 1 ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_register() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden', 403 ); }
		check_admin_referer( 'bizcity_twinchat_register_key' );

		$base  = self::get_gateway_url();
		$email = (string) get_option( 'admin_email', '' );
		$label = (string) get_bloginfo( 'name' );
		if ( $label === '' ) { $label = wp_parse_url( home_url(), PHP_URL_HOST ); }

		$resp = wp_remote_post( $base . '/wp-json/bizcity/v1/register-key', [
			'timeout' => 20,
			'headers' => [ 'content-type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'label' => $label,
				'email' => $email,
				'site'  => home_url(),
			] ),
		] );

		$err = '';
		if ( is_wp_error( $resp ) ) {
			$err = $resp->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( $code >= 200 && $code < 300 && ! empty( $body['key'] ) ) {
				// [2026-06-11 Johnny Chu] HOTFIX — per-site option
				update_option( self::OPT_API_KEY, (string) $body['key'] );
			} else {
				$err = ! empty( $body['message'] ) ? (string) $body['message'] : ( 'HTTP ' . $code );
			}
		}

		$args = [ 'page' => self::PAGE_SLUG ];
		$args[ $err === '' ? 'registered' : 'register_error' ] = $err === '' ? 1 : rawurlencode( $err );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Test connection by calling canonical R-1API-6 endpoint `/account/info`.
	 *
	 * @return array{success:bool,status:int,error:?string,tier?:string,balance?:string}
	 */
	private static function probe_account_info(): array {
		$key  = self::get_api_key();
		$base = self::get_gateway_url();

		if ( $key === '' ) {
			return [
				'success' => false,
				'status'  => 0,
				'error'   => 'no_api_key',
			];
		}

		$resp = wp_remote_get( $base . '/wp-json/bizcity/v1/account/info', [
			'timeout' => 15,
			'headers' => [
				'authorization' => 'Bearer ' . $key,
				'accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $resp ) ) {
			return [
				'success' => false,
				'status'  => 0,
				'error'   => $resp->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $resp );
		$body   = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$ok     = $status >= 200 && $status < 300;

		// [2026-06-03 Johnny Chu] R-1API — gateway nest tier/balance dưới `data`, không phải top-level.
		$data = ( is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : ( is_array( $body ) ? $body : [] );

		return [
			'success' => $ok,
			'status'  => $status,
			'error'   => $ok ? null : ( is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : ( is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : 'http_' . $status ) ),
			'tier'    => (string) ( $data['tier']        ?? '' ),
			'balance' => (string) ( $data['balance_usd'] ?? '' ),
			'data'    => $data,
		];
	}

	/**
	 * [2026-06-03 Johnny Chu] R-1API — AJAX endpoint cho status card.
	 *
	 * Ping nhẹ `/wp-json/bizcity/v1/account/info` qua wrapper server-side
	 * (R-GW-8: KHÔNG để FE fetch cross-origin sang bizcity.vn) và trả về
	 * payload chuẩn cho JS dialog: key_set / gateway / tier / plan / balance /
	 * requests_today / requests_limit / requests_remaining / latency_ms / error.
	 *
	 * Auth: manage_options + nonce `bizcity_twinchat_status`.
	 * Fail-OPEN: luôn trả 200 + success boolean (FE không retry-loop).
	 */
	public function ajax_account_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( 'bizcity_twinchat_status', 'nonce' );

		$key     = self::get_api_key();
		$gateway = self::get_gateway_url();

		$out = [
			'key_set'      => $key !== '',
			'key_masked'   => self::get_masked_key(),
			'gateway'      => $gateway,
			'success'      => false,
			'status'       => 0,
			'latency_ms'   => 0,
			'error'        => null,
			'tier'         => '',
			'plan'         => '',
			'balance_usd'  => null,
			'requests_today'     => null,
			'requests_limit'     => null,
			'requests_remaining' => null,
			'is_free_tier'       => null,
			'my_account_url'     => $gateway . '/my-account/',
			'checked_at'         => time(),
		];

		if ( ! $out['key_set'] ) {
			$out['error'] = 'no_api_key';
			wp_send_json_success( $out );
		}

		$started = microtime( true );
		$res     = self::probe_account_info();
		$out['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		$out['success']    = (bool) $res['success'];
		$out['status']     = (int)  $res['status'];
		$out['error']      = $res['error'];

		// [2026-06-11 Johnny Chu] HOTFIX — unified per-site option (same key as REST /api-key/test)
		update_option( 'bizcity_llm_last_test', [
			'ok'      => $out['success'],
			'ts'      => time(),
			'ms'      => $out['latency_ms'],
			'code'    => 'http_' . $out['status'],
			'status'  => $out['status'],
			'message' => (string) ( $out['error'] ?? '' ),
			'tier'    => (string) ( $res['tier']    ?? '' ),
			'balance' => (string) ( $res['balance'] ?? '' ),
		] );

		if ( $out['success'] && ! empty( $res['data'] ) ) {
			$d = $res['data'];
			$out['tier']               = (string) ( $d['tier']         ?? '' );
			$out['plan']               = (string) ( $d['plan']         ?? '' );
			$out['balance_usd']        = isset( $d['balance_usd'] )        ? (float) $d['balance_usd']        : null;
			$out['requests_today']     = isset( $d['requests_today'] )     ? (int)   $d['requests_today']     : null;
			$out['requests_limit']     = isset( $d['requests_limit'] )     ? (int)   $d['requests_limit']     : null;
			$out['requests_remaining'] = isset( $d['requests_remaining'] ) ? (int)   $d['requests_remaining'] : null;
			$out['is_free_tier']       = isset( $d['is_free_tier'] )       ? (bool)  $d['is_free_tier']       : null;
			if ( ! empty( $d['my_account_url'] ) ) {
				$out['my_account_url'] = (string) $d['my_account_url'];
			}
		}

		wp_send_json_success( $out );
	}

	/**
	 * List of plugins/modules registered as consumers of this canonical key.
	 * Other plugins can register themselves via the `bizcity_llm_consumer_plugins` filter.
	 *
	 * @return array<int,array{id:string,label:string,desc:string}>
	 */
	private function consumer_plugins(): array {
		$default = array(
			array( 'id' => 'twinchat',             'label' => '💬 TwinChat — Webchat & React UI',           'desc' => 'LLM chat, embeddings, vector search, channel routing.', 'status' => 'ok' ),
			array( 'id' => 'webchat',              'label' => '🌐 WebChat (legacy module)',                  'desc' => 'Public chat widget — LLM via BizCity_LLM_Client.',     'status' => 'ok' ),
			array( 'id' => 'knowledge-kg-hub',     'label' => '📚 Knowledge KG Hub',                         'desc' => 'OCR + A/V Transcribe + Embeddings (KG ingestion).',     'status' => 'ok' ),
			array( 'id' => 'research',             'label' => '🔬 Research module',                          'desc' => 'Web search (Tavily) + extract + crawl via gateway.',    'status' => 'ok' ),
			array( 'id' => 'bizcoach-pro',         'label' => '🎴 BizCoach Pro — Astrology',                 'desc' => 'Western / Vedic / Chinese chart qua /astrology/*.',     'status' => 'ok' ),
			array( 'id' => 'bizcity-tarot',        'label' => '🔮 BizCity Tarot',                            'desc' => 'Tarot + astrology tool.',                                'status' => 'ok' ),
			array( 'id' => 'bizcity-tool-content', 'label' => '✍ BizCity Tool — Content',                   'desc' => 'Content generation tool.',                               'status' => 'ok' ),
			array( 'id' => 'bizcity-content-creator','label' => '📝 BizCity Content Creator',                'desc' => 'Long-form content via BizCity_LLM_Client.',              'status' => 'ok' ),
			array( 'id' => 'bizgpt-custom-flows',  'label' => '🛠 BizGPT Custom Flows',                      'desc' => 'Flow runner gọi LLM qua BizCity_LLM_Client.',           'status' => 'ok' ),
			array( 'id' => 'bizcity-doc',          'label' => '📄 BizCity Doc',                              'desc' => 'Document + prompt library — LLM canonical.',             'status' => 'ok' ),
			array( 'id' => 'bizcity-openrouter-mu','label' => '🔌 BizCity OpenRouter (mu-plugin)',           'desc' => 'Thin proxy: BizCity_LLM/Search/Video Client.',           'status' => 'ok' ),
			array( 'id' => 'bizcity-tool-image',   'label' => '🎨 BizCity Tool — Image',                     'desc' => 'Image gen (FLUX/Gemini) — LEGACY: dùng `bztimg_api_key` riêng (migrating to canonical).',  'status' => 'migrating' ),
			array( 'id' => 'bizcity-video-kling',  'label' => '🎬 BizCity Video Kling',                      'desc' => 'PiAPI video — LEGACY: `bizcity_video_kling_api_key` riêng (migrating).', 'status' => 'migrating' ),
			array( 'id' => 'core-automation',      'label' => '🤖 Core Automation (canonical)',              'desc' => 'Workflow runner sống ở core/automation/ — dùng BizCity_LLM_Client.', 'status' => 'ok' ),
			array( 'id' => 'bizcity-automation',   'label' => '🗑 BizCity Automation (DEPRECATED)',          'desc' => 'Plugin deprecate 2026-06-02 — logic chuyển sang core/automation/. Chờ delete.', 'status' => 'migrating' ),
			array( 'id' => 'bizcity-zalo-bot',     'label' => '💬 BizCity Zalo Bot',                         'desc' => 'Memory extraction qua BizCity_LLM_Client::chat() (fixed 2026-06-02).', 'status' => 'ok' ),
		);
		$list = apply_filters( 'bizcity_llm_consumer_plugins', $default );
		return is_array( $list ) ? $list : $default;
	}

	/* ── Render ── */

	// [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — tab navigation constants.
	const TAB_SETTINGS = 'settings';
	const TAB_STATS    = 'stats';

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — tab switcher: Cài đặt | Thống kê.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : self::TAB_SETTINGS;
		if ( ! in_array( $active_tab, [ self::TAB_SETTINGS, self::TAB_STATS ], true ) ) {
			$active_tab = self::TAB_SETTINGS;
		}
		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<nav class="nav-tab-wrapper" style="margin:16px 20px 0;border-bottom:1px solid #c3c4c7;">
			<a href="<?php echo esc_url( $page_url ); ?>"
			   class="nav-tab <?php echo $active_tab === self::TAB_SETTINGS ? 'nav-tab-active' : ''; ?>">
				⚙ <?php esc_html_e( 'Cài đặt', 'bizcity-twin-ai' ); ?>
			</a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', self::TAB_STATS, $page_url ) ); ?>"
			   class="nav-tab <?php echo $active_tab === self::TAB_STATS ? 'nav-tab-active' : ''; ?>">
				📊 <?php esc_html_e( 'Thống kê', 'bizcity-twin-ai' ); ?>
			</a>
		</nav>
		<?php

		if ( $active_tab === self::TAB_STATS ) {
			$this->render_usage_tab();
			return;
		}

		// ── Settings tab (default) ──────────────────────────────────────────
		if ( class_exists( 'BizCity_LLM_Settings' ) ) {
			$canonical = BizCity_LLM_Settings::instance();

			$this->render_twinchat_banner();
			// [2026-06-03 Johnny Chu] R-1API — Live status card (ping nhẹ /account/info).
			$this->render_account_status_card();
			// [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — Plan config card.
			$this->render_plan_config_card();
			$this->render_install_guide();
			$canonical->render_page();
			return;
		}

		// Fallback: legacy slim UI (only used if BizCity_LLM_Settings missing).
		$this->render_install_guide();
		$this->render_legacy();
	}

	/**
	 * [2026-06-03 Johnny Chu] R-1API — Live status card.
	 *
	 * Render khung "Live Account Status" + JS poller:
	 *   - Check key configured?
	 *   - Ping nhẹ gateway `/account/info` qua AJAX `bizcity_twinchat_account_status`.
	 *   - Hiển thị: tier, plan, balance USD, requests today / limit / remaining,
	 *     latency ms, last checked timestamp.
	 *   - Cảnh báo (notice WP) khi balance < $1 hoặc requests_remaining ≤ 5.
	 *   - Auto refresh 60s + nút Refresh thủ công.
	 */
	private function render_account_status_card(): void {
		$has_key   = self::get_api_key() !== '';
		$gateway   = self::get_gateway_url();
		$nonce     = wp_create_nonce( 'bizcity_twinchat_status' );
		$ajax_url  = admin_url( 'admin-ajax.php' );
		$masked    = self::get_masked_key();
		?>
		<div id="bizcity-twinchat-status-card"
		     class="bizcity-llm-card"
		     style="margin:8px 0 18px;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;">
			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
				<h2 style="margin:0;font-size:16px;">
					🛰 <?php esc_html_e( 'Live Account Status — BizCity Hub', 'bizcity-twin-ai' ); ?>
				</h2>
				<div style="display:flex;gap:8px;align-items:center;">
					<span id="bcs-checked-at" style="color:#646970;font-size:12px;">—</span>
					<button type="button" id="bcs-refresh-btn" class="button button-small">
						🔄 <?php esc_html_e( 'Refresh', 'bizcity-twin-ai' ); ?>
					</button>
				</div>
			</div>

			<div id="bcs-alert" class="notice inline" style="display:none;margin:10px 0 0;padding:8px 12px;"></div>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-warning inline" style="margin:10px 0 0;padding:8px 12px;">
					<p style="margin:0;">
						⚠️ <strong><?php esc_html_e( 'Chưa cấu hình BizCity API key.', 'bizcity-twin-ai' ); ?></strong>
						<?php esc_html_e( 'Cuộn xuống form bên dưới để nhập key (biz-…) hoặc dùng nút "Auto-register".', 'bizcity-twin-ai' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:14px;">
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">🔑 <?php esc_html_e( 'API Key', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-key" style="font-size:14px;font-weight:600;margin-top:4px;font-family:Consolas,monospace;">
						<?php echo $has_key ? esc_html( $masked ) : '<span style="color:#d63638;">' . esc_html__( 'chưa có', 'bizcity-twin-ai' ) . '</span>'; ?>
					</div>
				</div>
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">🏷 <?php esc_html_e( 'Tier / Plan', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-tier" style="font-size:14px;font-weight:600;margin-top:4px;">—</div>
				</div>
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">💰 <?php esc_html_e( 'Balance (USD)', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-balance" style="font-size:18px;font-weight:700;margin-top:4px;color:#00a32a;">—</div>
				</div>
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">📊 <?php esc_html_e( 'Requests today', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-requests" style="font-size:14px;font-weight:600;margin-top:4px;">—</div>
				</div>
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">⏱ <?php esc_html_e( 'Latency', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-latency" style="font-size:14px;font-weight:600;margin-top:4px;">—</div>
				</div>
				<div class="bcs-stat" style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">🌐 <?php esc_html_e( 'Hub', 'bizcity-twin-ai' ); ?></div>
					<div id="bcs-hub" style="font-size:12px;font-weight:600;margin-top:4px;word-break:break-all;font-family:Consolas,monospace;">
						<?php echo esc_html( $gateway ); ?>
					</div>
				</div>
			</div>

			<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<a id="bcs-topup" class="button button-primary" target="_blank" rel="noopener"
				   href="<?php echo esc_url( $gateway . '/my-account/' ); ?>">
					⬆ <?php esc_html_e( 'Topup / Nâng cấp gói', 'bizcity-twin-ai' ); ?>
				</a>
				<a class="button" target="_blank" rel="noopener"
				   href="<?php echo esc_url( $gateway . '/my-account/api-keys/' ); ?>">
					🔑 <?php esc_html_e( 'Quản lý API keys', 'bizcity-twin-ai' ); ?>
				</a>
				<span style="color:#646970;font-size:12px;margin-left:auto;">
					<?php esc_html_e( 'Auto-refresh mỗi 60s.', 'bizcity-twin-ai' ); ?>
				</span>
			</div>
		</div>

		<script type="text/javascript">
		(function(){
			var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
			var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
			var POLL_MS  = 60000;
			var $card    = document.getElementById('bizcity-twinchat-status-card');
			if (!$card) return;
			var $alert   = document.getElementById('bcs-alert');
			var $tier    = document.getElementById('bcs-tier');
			var $balance = document.getElementById('bcs-balance');
			var $req     = document.getElementById('bcs-requests');
			var $lat     = document.getElementById('bcs-latency');
			var $checked = document.getElementById('bcs-checked-at');
			var $topup   = document.getElementById('bcs-topup');
			var $btn     = document.getElementById('bcs-refresh-btn');

			function setAlert(kind, html){
				if (!html) { $alert.style.display = 'none'; $alert.innerHTML = ''; return; }
				$alert.className = 'notice notice-' + kind + ' inline';
				$alert.style.display = 'block';
				$alert.style.margin = '10px 0 0';
				$alert.style.padding = '8px 12px';
				$alert.innerHTML = '<p style="margin:0">' + html + '</p>';
			}
			function fmtUsd(v){
				if (v === null || v === undefined || isNaN(v)) return '—';
				return '$' + Number(v).toFixed(4);
			}
			function fmtTime(){
				var d = new Date();
				return d.toLocaleTimeString();
			}
			function render(payload){
				if (!payload.key_set) {
					setAlert('warning', '⚠️ <strong>Chưa cấu hình API key.</strong> Nhập key bên dưới để kích hoạt.');
					$tier.textContent = '—'; $balance.textContent = '—'; $req.textContent = '—'; $lat.textContent = '—';
					$checked.textContent = 'never';
					return;
				}
				$lat.textContent = (payload.latency_ms || 0) + ' ms';
				$checked.textContent = '✓ ' + fmtTime();
				if (!payload.success) {
					setAlert('error', '❌ <strong>Ping thất bại</strong> (HTTP ' + payload.status + '): <code>' + (payload.error || 'unknown') + '</code>');
					$tier.textContent = '—'; $balance.textContent = '—'; $req.textContent = '—';
					return;
				}
				var tierTxt = (payload.plan || payload.tier || '—');
				if (payload.tier && payload.tier !== payload.plan) tierTxt += ' (' + payload.tier + ')';
				$tier.textContent = tierTxt;

				var bal = payload.balance_usd;
				$balance.textContent = fmtUsd(bal);
				$balance.style.color = (bal !== null && bal < 1) ? '#d63638' : '#00a32a';

				if (payload.requests_limit !== null && payload.requests_limit !== undefined) {
					$req.textContent = (payload.requests_today || 0) + ' / ' + payload.requests_limit
						+ ' (còn ' + (payload.requests_remaining ?? 0) + ')';
				} else {
					$req.textContent = (payload.requests_today !== null && payload.requests_today !== undefined)
						? String(payload.requests_today) : '∞';
				}

				if (payload.my_account_url) $topup.href = payload.my_account_url;

				// Notifications
				var alerts = [];
				if (bal !== null && bal < 1) {
					alerts.push('💰 <strong>Số dư thấp:</strong> ' + fmtUsd(bal) + '. <a href="' + (payload.my_account_url || '#') + '" target="_blank">Topup ngay</a>.');
				}
				if (payload.is_free_tier && payload.requests_remaining !== null && payload.requests_remaining <= 5) {
					alerts.push('📉 <strong>Sắp hết quota free:</strong> còn ' + payload.requests_remaining + ' request hôm nay.');
				}
				setAlert(alerts.length ? 'warning' : 'success',
					alerts.length ? alerts.join('<br>')
					              : '✅ <strong>Gateway OK</strong> — tier <code>' + payload.tier + '</code>, balance ' + fmtUsd(bal) + ', ' + (payload.latency_ms||0) + ' ms.');
			}
			function ping(){
				$btn.disabled = true; $btn.textContent = '⏳';
				var fd = new FormData();
				fd.append('action', 'bizcity_twinchat_account_status');
				fd.append('nonce', NONCE);
				fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json && json.success && json.data) render(json.data);
						else setAlert('error', '❌ Phản hồi không hợp lệ từ server.');
					})
					.catch(function(err){ setAlert('error', '❌ Lỗi mạng: ' + (err && err.message || err)); })
					.then(function(){ $btn.disabled = false; $btn.textContent = '🔄 Refresh'; });
			}
			$btn.addEventListener('click', ping);
			ping();
			setInterval(ping, POLL_MS);
		})();
		</script>
		<?php
	}

	/**
	 * Banner shown above the canonical settings form when rendered inside TwinChat menu.
	 */
	private function render_twinchat_banner(): void {
		?>
		<div class="notice notice-info inline" style="margin:8px 0 16px;border-left:4px solid #6366f1;padding:12px 16px;background:#eef2ff;">
			<p style="margin:0;font-size:14px;line-height:1.6;">
				📜 <strong><?php esc_html_e( '1 API key — 1 cửa cho toàn bộ các modules Bizcity Twin ecosystem (R-1API).', 'bizcity-twin-ai' ); ?></strong><br>
				<?php esc_html_e( 'Cấu hình tại đây sẽ áp dụng cho TwinChat, BizCoach, Tool Image, Video, Custom Flows, Astrology, Search… Mọi plugin BizCity tự động kế thừa.', 'bizcity-twin-ai' ); ?>
			</p>
			
		</div>
		<?php
	}

	/**
	 * [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — Plan config card.
	 *
	 * Render thẻ "Gói dịch vụ hiện tại" hiển thị đầy đủ:
	 *   - Tên gói + badge tier + giá / tháng
	 *   - Nhóm 1: danh sách plugins được bật (dạng badge)
	 *   - Nhóm 2: quota limits (request/ngày, daily cap, ảnh, video, KG batch, KG quota)
	 *   - Nút Refresh để fetch lại từ hub
	 *
	 * Dữ liệu đọc từ site_options (cached bởi get_plan_config() hoặc get_entitlement()).
	 * Nút Refresh gọi AJAX `bizcity_twinchat_plan_config` để fetch fresh từ hub.
	 */
	private function render_plan_config_card(): void {
		if ( self::get_api_key() === '' ) {
			return;
		}

		$plugin_labels = [
			'bizcity-admin-hook-zalo'  => '📳 Zalo Hook / ZNS',
			'bizcity-zalo-bot'         => '🤖 Zalo Bot',
			'bizcity-zalo-bizcity'     => '💬 Zalo BizCity Channel',
			'bizcity-zalo-personal'    => '👤 Zalo Personal',
			'bizcity-facebook-bot'     => '📘 Facebook Bot / Messenger',
			'bizcity-content-creator'  => '✍ Content Creator (AI)',
			'bizcity-tool-content'     => '🗒 Tool Content (Template)',
			'bizcity-doc'              => '📄 Doc Studio (Word/Excel/PPT)',
			'bizcity-tool-image'       => '🖼 Image Studio',
			'bizcity-video-kling'      => '🎬 Video Studio (Kling/Veo3)',
			'bizcity-pagebuilder'      => '🌐 Page Builder (AI website)',
			'bizcoach-pro'             => '🎴 BizCoach Pro',
			'bizcity-twin-crm'         => '👥 Twin CRM',
			'bizgpt-tool-google'       => '🔎 Google Workspace',
			'bizcity-tarot'            => '🔮 Tarot / Tử vi',
		];

		$nonce    = wp_create_nonce( 'bizcity_plan_config' );
		$ajax_url = admin_url( 'admin-ajax.php' );

		// [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — always render full skeleton DOM;
		// elements are hidden until JS populates them after fetch. Fixes blank card when
		// no site_options cached yet (getElementById returned null → nothing rendered).
		?>
		<div id="bzpc-card"
		     class="bizcity-llm-card"
		     style="margin:8px 0 18px;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #f0930a;border-radius:4px;">

			<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
				<h2 style="margin:0;font-size:16px;">
					📦 <?php esc_html_e( 'Gói dịch vụ hiện tại', 'bizcity-twin-ai' ); ?>
				</h2>
				<div style="display:flex;gap:8px;align-items:center;">
					<span id="bzpc-fetched-at" style="color:#646970;font-size:12px;">—</span>
					<button type="button" id="bzpc-refresh-btn" class="button button-small">
						🔄 <?php esc_html_e( 'Làm mới', 'bizcity-twin-ai' ); ?>
					</button>
				</div>
			</div>

			<!-- Loading placeholder — shown while fetching, hidden after render -->
			<p id="bzpc-loading" style="color:#646970;margin:0;">
				⏳ <?php esc_html_e( 'Đang tải thông tin gói từ hub…', 'bizcity-twin-ai' ); ?>
			</p>

			<!-- Plan header — always in DOM, hidden until JS populates -->
			<div id="bzpc-header" style="display:none;align-items:center;gap:16px;margin-bottom:10px;flex-wrap:wrap;">
				<span id="bzpc-badge"
				      style="background:#888;color:#fff;padding:6px 18px;border-radius:20px;font-weight:700;font-size:15px;">
					—
				</span>
				<span id="bzpc-level" style="color:#646970;font-size:13px;"></span>
				<span id="bzpc-price" style="font-size:18px;font-weight:700;color:#00a32a;">—</span>
			</div>
			<!-- [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — Key info row: hub key_id + my-account link (Bearer-authenticated portal, NOT wp-admin) -->
			<div id="bzpc-keyinfo" style="display:none;margin-bottom:14px;padding:8px 12px;background:#f6f7f7;border-radius:4px;font-size:12px;color:#646970;">
				🔑 Hub key: <strong id="bzpc-ki-label"></strong>
				&nbsp;·&nbsp; ID: <code id="bzpc-ki-id"></code>
				&nbsp;·&nbsp; Tổng requests: <span id="bzpc-ki-req"></span>
				&nbsp;·&nbsp; <a id="bzpc-ki-link" href="#" target="_blank" rel="noopener" style="color:#2271b1;">⬆ Nâng cấp gói</a>
				<span style="margin-left:8px;color:#d63638;font-weight:600;" id="bzpc-ki-warn" style="display:none;"></span>
			</div>

			<div id="bzpc-body" style="display:none;grid-template-columns:1fr 1fr;gap:16px;align-items:start;">

				<!-- Nhóm 1: Plugins bật -->
				<div id="bzpc-plugins-wrap"
				     style="background:#f0f8ff;border:1px solid #c3d9f0;border-radius:6px;padding:14px 16px;">
					<div style="font-size:13px;font-weight:600;color:#2271b1;margin-bottom:10px;">
						💡 <?php esc_html_e( 'Plugins / Services được bật', 'bizcity-twin-ai' ); ?>
					</div>
					<div id="bzpc-plugins" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
				</div>

				<!-- Nhóm 2: Quota -->
				<div id="bzpc-quota-wrap"
				     style="background:#fffbeb;border:1px solid #f5d87a;border-radius:6px;padding:14px 16px;">
					<div style="font-size:13px;font-weight:600;color:#92400e;margin-bottom:10px;">
						📊 <?php esc_html_e( 'Quota & Giới hạn sử dụng', 'bizcity-twin-ai' ); ?>
					</div>
					<table id="bzpc-quota-table" style="width:100%;border-collapse:collapse;font-size:13px;">
						<tbody></tbody>
					</table>
				</div>

			</div>

		</div>
		<script>
		(function() {
			var btn       = document.getElementById('bzpc-refresh-btn');
			var loadEl    = document.getElementById('bzpc-loading');
			var headerEl   = document.getElementById('bzpc-header');
			var keyInfoEl  = document.getElementById('bzpc-keyinfo');
			var bodyEl     = document.getElementById('bzpc-body');
			var badgeEl    = document.getElementById('bzpc-badge');
			var levelEl    = document.getElementById('bzpc-level');
			var priceEl    = document.getElementById('bzpc-price');
			var pluginsEl  = document.getElementById('bzpc-plugins');
			var quotaTbl   = document.getElementById('bzpc-quota-table');
			var fetchedAt  = document.getElementById('bzpc-fetched-at');
			var gateway    = <?php echo wp_json_encode( self::get_gateway_url() ); ?>;

			var tierStyles = {
				free:           'background:#888;color:#fff',
				master_pro:     'background:#2271b1;color:#fff',
				master_premium: 'background:#d97706;color:#fff',
			};
			var pluginLabels = <?php echo wp_json_encode( $plugin_labels ); ?>;
			var qLabels = {
				req:   '<?php echo esc_js( __( 'Tổng requests/ngày', 'bizcity-twin-ai' ) ); ?>',
				cap:   '<?php echo esc_js( __( 'Daily cap (USD)', 'bizcity-twin-ai' ) ); ?>',
				img:   '<?php echo esc_js( __( 'Tạo ảnh / ngày', 'bizcity-twin-ai' ) ); ?>',
				vid:   '<?php echo esc_js( __( 'Tạo video / ngày', 'bizcity-twin-ai' ) ); ?>',
				kg:    'KG batch_size',
				kgq:   '<?php echo esc_js( __( 'KG quota/user/ngày', 'bizcity-twin-ai' ) ); ?>',
			};

			function renderPlanCard( d ) {
				if ( ! d ) return;

				// Badge + level + price.
				var lvl = d.master_level || 'free';
				var lbl = d.master_label || lvl;
				if ( badgeEl ) {
					badgeEl.textContent = lbl;
					badgeEl.style.cssText = ( tierStyles[lvl] || 'background:#888;color:#fff' )
					    + ';padding:6px 18px;border-radius:20px;font-weight:700;font-size:15px;';
				}
				if ( levelEl ) levelEl.textContent = lvl;
				if ( priceEl && d.plan ) {
					var price = d.plan.price_usd ? parseFloat( d.plan.price_usd ) : 0;
					priceEl.innerHTML = price > 0
						? '$' + Math.round(price) + '<span style="font-size:13px;font-weight:normal;color:#646970;">/tháng</span>'
						: '<?php echo esc_js( __( 'Miễn phí', 'bizcity-twin-ai' ) ); ?>';
					priceEl.style.color = price > 0 ? '#2271b1' : '#00a32a';
				}

				// Plugins.
				if ( pluginsEl ) {
					var enabled = d.plugins_enabled || d.features || [];
					var html = '';
					for ( var slug in pluginLabels ) {
						var on = enabled.indexOf(slug) !== -1;
						html += '<span style="background:' + (on ? '#d7f0e0' : '#f0f0f0') + ';'
						      + 'color:' + (on ? '#1a7a3c' : '#999') + ';'
						      + 'border:1px solid ' + (on ? '#a3d9b5' : '#ddd') + ';'
						      + 'padding:3px 10px;border-radius:12px;font-size:12px;white-space:nowrap;">'
						      + (on ? '✓ ' : '') + pluginLabels[slug] + '</span>';
					}
					pluginsEl.innerHTML = html;
				}

				// Quota table.
				if ( quotaTbl && d.plan && d.kg_config ) {
					var p  = d.plan;
					var kg = d.kg_config;
					var rows = [
						[qLabels.req, p.max_requests_day > 0 ? p.max_requests_day.toLocaleString() : '∞'],
						[qLabels.cap, p.daily_cap_usd > 0 ? '$' + parseFloat(p.daily_cap_usd).toFixed(2) : '∞'],
						[qLabels.img, p.image_calls_day > 0 ? p.image_calls_day.toLocaleString() : '∞'],
						[qLabels.vid, p.video_calls_day > 0 ? p.video_calls_day.toLocaleString() : '∞'],
						[qLabels.kg,  kg.batch_size > 0 ? kg.batch_size : '—'],
						[qLabels.kgq, kg.quota_per_user > 0 ? kg.quota_per_user.toLocaleString() : '∞'],
					];
					var tbody = '';
					for ( var i = 0; i < rows.length; i++ ) {
						tbody += '<tr>'
						       + '<td style="padding:5px 0;color:#555;white-space:nowrap;">' + rows[i][0] + '</td>'
						       + '<td style="padding:5px 12px;font-weight:700;font-size:16px;color:#1a1a1a;text-align:right;white-space:nowrap;">' + rows[i][1] + '</td>'
						       + '</tr>';
					}
					quotaTbl.querySelector('tbody').innerHTML = tbody;
				}

				// Key info row.
				if ( keyInfoEl && d.key_info ) {
					var ki = d.key_info;
					var kiLabel = document.getElementById('bzpc-ki-label');
					var kiId    = document.getElementById('bzpc-ki-id');
					var kiReq   = document.getElementById('bzpc-ki-req');
					var kiLink  = document.getElementById('bzpc-ki-link');
					var kiWarn  = document.getElementById('bzpc-ki-warn');
					if ( kiLabel ) kiLabel.textContent = ki.label || '(no label)';
					if ( kiId )    kiId.textContent    = '#' + ki.key_id;
					if ( kiReq )   kiReq.textContent   = Number(ki.total_requests || 0).toLocaleString() + ' requests';
					// [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — manage_url is returned by hub
					// via Bearer-authenticated /master/config response (R-GW-API-CATALOG: client
					// NEVER hardcodes gateway paths; hub owns all its own portal URLs).
					if ( kiLink && d.manage_url ) kiLink.href = d.manage_url;
					// Warn if level in DB is still free but user might expect otherwise.
					if ( kiWarn ) {
						if ( (d.master_level === 'free') ) {
							kiWarn.textContent = '⚠ Đang ở gói Free — Bấm "Đổi gói trên Hub" để nâng cấp key #' + ki.key_id;
							kiWarn.style.display = '';
						} else {
							kiWarn.style.display = 'none';
						}
					}
					keyInfoEl.style.display = '';
				}

				// Show content, hide loading.
				if ( loadEl )   loadEl.style.display  = 'none';
				if ( headerEl ) headerEl.style.display = 'flex';
				if ( bodyEl )   bodyEl.style.display   = 'grid';
				if ( fetchedAt ) fetchedAt.textContent = '✓ ' + new Date().toLocaleTimeString();
			}

			function fetchPlanConfig( force ) {
				if ( btn ) btn.disabled = true;
				if ( loadEl ) loadEl.style.display = '';
				var fd = new FormData();
				fd.append( 'action', 'bizcity_twinchat_plan_config' );
				fd.append( 'nonce',  <?php echo wp_json_encode( $nonce ); ?> );
				if ( force ) fd.append( 'force_refresh', '1' );

				fetch( <?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd,
				} )
				.then( function(r) { return r.json(); } )
				.then( function(resp) {
					if ( resp && resp.success && resp.data ) {
						renderPlanCard( resp.data );
					} else {
						if ( loadEl ) loadEl.textContent = '❌ Không tải được dữ liệu gói.';
					}
				} )
				.catch( function(e) {
					if ( loadEl ) loadEl.textContent = '❌ Lỗi mạng khi tải gói.';
				} )
				.then( function() {
					if ( btn ) btn.disabled = false;
				} );
			}

			// Always auto-fetch on page load (use cache if available, force=false).
			fetchPlanConfig( false );

			if ( btn ) {
				btn.addEventListener( 'click', function() { fetchPlanConfig( true ); } );
			}
		})();
		</script>
		<?php
	}

	/**
	 * [2026-06-09 Johnny Chu] PHASE-MASTER-PLANS — AJAX: fetch plan config from hub.
	 * Calls BizCity_LLM_Client::get_plan_config() (Bearer server-to-server).
	 */
	public function ajax_plan_config(): void {
		check_ajax_referer( 'bizcity_plan_config', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			wp_send_json_error( 'BizCity_LLM_Client not loaded.' );
		}

		$force  = ! empty( $_POST['force_refresh'] );
		$result = BizCity_LLM_Client::instance()->get_plan_config( [
			'timeout'       => 12,
			'force_refresh' => $force,
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/* ================================================================
	 * [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — Usage Stats Tab
	 * 6-dimension analytics for this API key (hub-side data).
	 * ================================================================ */

	/**
	 * Render the full "📊 Thống kê" tab content.
	 * All data loaded async via ajax_usage_stats().
	 */
	private function render_usage_tab(): void {
		if ( self::get_api_key() === '' ) {
			?>
			<div class="wrap">
				<div class="notice notice-warning inline" style="margin:16px 0;">
					<p>⚠️ <?php esc_html_e( 'Chưa có API key. Vào tab Cài đặt để nhập key (biz-…).', 'bizcity-twin-ai' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		$nonce    = wp_create_nonce( 'bizcity_usage_stats' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<div class="wrap bizcity-llm-wrap" id="bzus-wrap" style="margin-top:8px;">

			<!-- ── Period selector ── -->
			<div class="bizcity-llm-card" style="padding:12px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
				<strong><?php esc_html_e( 'Khoảng thời gian:', 'bizcity-twin-ai' ); ?></strong>
				<?php
				$periods = [
					'today' => __( 'Hôm nay', 'bizcity-twin-ai' ),
					'7d'    => __( '7 ngày',  'bizcity-twin-ai' ),
					'30d'   => __( '30 ngày', 'bizcity-twin-ai' ),
					'all'   => __( 'Tất cả',  'bizcity-twin-ai' ),
				];
				foreach ( $periods as $val => $label ) :
					$active = $val === '30d' ? ' button-primary' : '';
				?>
				<button type="button"
				        class="button bzus-period<?php echo esc_attr( $active ); ?>"
				        data-period="<?php echo esc_attr( $val ); ?>">
					<?php echo esc_html( $label ); ?>
				</button>
				<?php endforeach; ?>
				<span id="bzus-loading" style="display:none;color:#646970;">
					⏳ <?php esc_html_e( 'Đang tải…', 'bizcity-twin-ai' ); ?>
				</span>
				<span id="bzus-fetched-at" style="color:#646970;font-size:12px;"></span>
				<button type="button" id="bzus-refresh" class="button button-small" style="margin-left:auto;">
					🔄 <?php esc_html_e( 'Làm mới', 'bizcity-twin-ai' ); ?>
				</button>
			</div>

			<!-- ── Summary boxes ── -->
			<div id="bzus-summary"
			     style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:12px 0;">
				<?php
				$sum_boxes = [
					[ 'id' => 'bzus-s-req',     'label' => '📋 Requests',    'color' => '#2271b1', 'val' => '—' ],
					[ 'id' => 'bzus-s-ok',      'label' => '✅ Thành công',  'color' => '#00a32a', 'val' => '—' ],
					[ 'id' => 'bzus-s-err',     'label' => '❌ Lỗi',         'color' => '#d63638', 'val' => '—' ],
					[ 'id' => 'bzus-s-tokens',  'label' => '🔢 Tokens',      'color' => '#1a1a1a', 'val' => '—' ],
					[ 'id' => 'bzus-s-cost',    'label' => '💰 Chi phí (USD)','color' => '#92400e', 'val' => '—' ],
					[ 'id' => 'bzus-s-latency', 'label' => '⏱ Avg latency',  'color' => '#1a1a1a', 'val' => '—' ],
				];
				foreach ( $sum_boxes as $box ) :
				?>
				<div style="background:#f6f7f7;border-radius:4px;padding:10px 12px;">
					<div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.04em;">
						<?php echo esc_html( $box['label'] ); ?>
					</div>
					<div id="<?php echo esc_attr( $box['id'] ); ?>"
					     style="font-size:22px;font-weight:700;margin-top:4px;color:<?php echo esc_attr( $box['color'] ); ?>;">
						<?php echo esc_html( $box['val'] ); ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- ── [2026-06-10 Johnny Chu] PHASE-LLM-ACTIVITY R8 — Request/day meter banner ── -->
			<div class="bizcity-llm-card" id="bzus-meter" style="display:none;background:#f0f8ff;border:1px solid #c3d9f0;">
				<h3 style="margin-top:0;">⚡ <?php esc_html_e( 'Hạn mức hôm nay (request / ngày)', 'bizcity-twin-ai' ); ?>
					<small style="font-weight:normal;color:#646970;"><?php esc_html_e( 'Mọi lệnh gọi (chat · ảnh · video · astro · search · learning) đều tính 1 request', 'bizcity-twin-ai' ); ?></small>
				</h3>
				<div id="bzus-meter-main" style="margin-bottom:10px;"></div>
				<div id="bzus-meter-types" style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;"></div>
				<div id="bzus-meter-reset" style="margin-top:8px;font-size:12px;color:#646970;"></div>
			</div>

			<!-- ── Section 1: Requests theo ngày ── -->
			<div class="bizcity-llm-card">
				<h3 style="margin-top:0;">📅 <?php esc_html_e( 'Requests mỗi ngày', 'bizcity-twin-ai' ); ?></h3>
				<div id="bzus-by-day"><p class="description">⏳ <?php esc_html_e( 'Đang tải…', 'bizcity-twin-ai' ); ?></p></div>
			</div>
			<!-- ── 4-section grid ── -->
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

				<!-- Section 2: By service -->
				<div class="bizcity-llm-card" style="margin-bottom:0;">
					<h3 style="margin-top:0;">🔌 <?php esc_html_e( 'Theo loại Service / API', 'bizcity-twin-ai' ); ?></h3>
					<div id="bzus-by-service"><p class="description">⏳…</p></div>
				</div>

				<!-- Section 4: By provider -->
				<div class="bizcity-llm-card" style="margin-bottom:0;">
					<h3 style="margin-top:0;">🏭 <?php esc_html_e( 'Theo Provider', 'bizcity-twin-ai' ); ?>
						<small style="font-weight:normal;color:#646970;">(OpenRouter / Tavily / PiAPI…)</small>
					</h3>
					<div id="bzus-by-provider"><p class="description">⏳…</p></div>
				</div>

				<!-- Section 5: By plugin -->
				<div class="bizcity-llm-card" style="margin-bottom:0;">
					<h3 style="margin-top:0;">📦 <?php esc_html_e( 'Theo Plugin', 'bizcity-twin-ai' ); ?></h3>
					<div id="bzus-by-plugin"><p class="description">⏳…</p></div>
				</div>

				<!-- Section 6: By call type -->
				<div class="bizcity-llm-card" style="margin-bottom:0;">
					<h3 style="margin-top:0;">💬 <?php esc_html_e( 'Theo loại gọi', 'bizcity-twin-ai' ); ?>
						<small style="font-weight:normal;color:#646970;">(Chat / Embed / Video / Ảnh…)</small>
					</h3>
					<div id="bzus-by-calltype"><p class="description">⏳…</p></div>
				</div>

			</div>

			<!-- ── Section 3: Top Models ── -->
			<div class="bizcity-llm-card">
				<h3 style="margin-top:0;">🤖 <?php esc_html_e( 'Theo Model LLM (top 20)', 'bizcity-twin-ai' ); ?></h3>
				<div id="bzus-by-model"><p class="description">⏳ <?php esc_html_e( 'Đang tải…', 'bizcity-twin-ai' ); ?></p></div>
			</div>

		</div><!-- #bzus-wrap -->

		<script type="text/javascript">
		(function() {
			var AJAX_URL  = <?php echo wp_json_encode( $ajax_url ); ?>;
			var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
			var curPeriod = '30d';

			/* ── provider friendly names ── */
			var providerLabels = {
				'openrouter':     '🌐 OpenRouter (LLM)',
				'tavily':         '🔎 Tavily (Search)',
				'piapi':          '🎬 PiAPI (Video/Image)',
				'openai_whisper': '🎙 OpenAI Whisper',
				'free_astro':     '✨ Free Astro',
				'astro':          '✨ Astro',
			};

			/* ── service friendly names ── */
			var serviceLabels = {
				'llm':    '🤖 LLM (Chat/Embed/Rerank)',
				'search': '🔎 Search (Tavily)',
				'video':  '🎬 Video (PiAPI/Kling)',
				'image':  '🖼 Image Generation',
			};

			/* ── purpose → call-type group ── */
			var purposeGroups = {
				'chat':                  '💬 Chat / General',
				'stream':                '💬 Chat / General',
				'fast':                  '💬 Chat / General',
				'vision':                '💬 Chat / General',
				'code':                  '💬 Chat / General',
				'planner':               '💬 Chat / General',
				'executor':              '💬 Chat / General',
				'router':                '💬 Chat / General',
				'twinbrain_classify':    '💬 Chat / General',
				'astro_report':          '💬 Chat / General',
				'embedding':             '📚 Learning / Embed',
				'video_generation':      '🎬 Video',
				'faceswap':              '🎬 Video',
				'virtual_tryon':         '🎬 Video',
				'image':                 '🖼 Ảnh (Image)',
				'search':                '🔎 Search (Tavily)',
				'extract':               '🔎 Search (Tavily)',
				'crawl':                 '🔎 Search (Tavily)',
				'transcribe':            '🎙 Transcribe / OCR',
				'ocr':                   '🎙 Transcribe / OCR',
				'astrology':             '✨ Astro / Tarot',
				'tarot':                 '✨ Astro / Tarot',
				'rerank':                '🔀 Rerank',
			};

			/* ── helpers ── */
			function fmtN(n) { return n !== null && n !== undefined ? Number(n).toLocaleString() : '0'; }
			function fmtUsd(v) {
				var f = parseFloat(v);
				if (!f || f <= 0) return '$0.0000';
				return f < 0.01 ? '$' + f.toFixed(6) : '$' + f.toFixed(4);
			}
			function pct(part, total) {
				if (!total || total <= 0) return '—';
				return Math.round(part * 100 / total) + '%';
			}
			function tbl(cols, rows, emptyMsg) {
				if (!rows || rows.length === 0) {
					return '<p class="description" style="margin:0;">' + (emptyMsg || 'Chưa có dữ liệu.') + '</p>';
				}
				var h = '<table class="widefat striped" style="font-size:13px;"><thead><tr>';
				for (var i = 0; i < cols.length; i++) {
					h += '<th style="' + (i > 0 ? 'text-align:right;' : '') + '">' + cols[i] + '</th>';
				}
				h += '</tr></thead><tbody>';
				for (var j = 0; j < rows.length; j++) {
					h += '<tr>' + rows[j] + '</tr>';
				}
				h += '</tbody></table>';
				return h;
			}
			function td(v, right, extra) {
				var align = right ? 'text-align:right;' : '';
				return '<td style="' + align + (extra||'') + '">' + v + '</td>';
			}

			/* ── render functions ── */
			function renderSummary(s) {
				function set(id, val) { var el = document.getElementById(id); if(el) el.textContent = val; }
				var total = s.requests || 0;
				set('bzus-s-req',     fmtN(total));
				set('bzus-s-ok',      fmtN(s.successes));
				set('bzus-s-err',     fmtN(s.errors));
				set('bzus-s-tokens',  fmtN(s.tokens_total));
				set('bzus-s-cost',    fmtUsd(s.cost_usd));
				set('bzus-s-latency', fmtN(s.avg_latency_ms) + ' ms');
				var errEl = document.getElementById('bzus-s-err');
				if (errEl) errEl.style.color = s.errors > 0 ? '#d63638' : '#00a32a';
			}

			function renderByDay(rows) {
				var el = document.getElementById('bzus-by-day');
				if (!el) return;
				if (!rows || rows.length === 0) {
					el.innerHTML = '<p class="description">Chưa có dữ liệu.</p>';
					return;
				}
				var maxReq = 0;
				for (var i = 0; i < rows.length; i++) {
					if (parseInt(rows[i].requests) > maxReq) maxReq = parseInt(rows[i].requests);
				}
				var cols = ['Ngày', 'Requests', 'Bar', 'Tokens', 'Chi phí (USD)', 'Lỗi', 'Avg ms'];
				var trows = [];
				// Reverse to show newest first
				var rev = rows.slice().reverse();
				for (var k = 0; k < rev.length; k++) {
					var r = rev[k];
					var w = maxReq > 0 ? Math.round(parseInt(r.requests) * 120 / maxReq) : 0;
					var bar = '<div style="background:#2271b1;height:10px;width:' + w + 'px;border-radius:2px;min-width:2px;"></div>';
					var errStyle = parseInt(r.errors) > 0 ? 'color:#d63638;font-weight:600;' : '';
					trows.push(
						td(r.day) +
						td('<strong>' + fmtN(r.requests) + '</strong>', true) +
						'<td>' + bar + '</td>' +
						td(fmtN(r.tokens), true) +
						td(fmtUsd(r.cost_usd), true) +
						td(fmtN(r.errors), true, errStyle) +
						td(fmtN(r.avg_latency_ms) + ' ms', true)
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderByService(rows) {
				var el = document.getElementById('bzus-by-service');
				if (!el) return;
				var total = 0;
				for (var i = 0; i < (rows||[]).length; i++) total += parseInt(rows[i].requests);
				var cols = ['Service', 'Requests', '%', 'Tokens', 'Chi phí', 'Lỗi'];
				var trows = [];
				for (var k = 0; k < (rows||[]).length; k++) {
					var r = rows[k];
					var lbl = serviceLabels[r.service] || r.service;
					trows.push(
						td(lbl) +
						td('<strong>' + fmtN(r.requests) + '</strong>', true) +
						td(pct(r.requests, total), true, 'color:#646970;') +
						td(fmtN(r.tokens), true) +
						td(fmtUsd(r.cost_usd), true) +
						td(fmtN(r.errors), true, parseInt(r.errors)>0?'color:#d63638;':'')
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderByModel(rows) {
				var el = document.getElementById('bzus-by-model');
				if (!el) return;
				var cols = ['Model', 'Requests', 'Tokens', 'Chi phí', 'Avg ms'];
				var trows = [];
				for (var k = 0; k < (rows||[]).length; k++) {
					var r = rows[k];
					trows.push(
						'<td><code style="font-size:12px;">' + r.model + '</code></td>' +
						td('<strong>' + fmtN(r.requests) + '</strong>', true) +
						td(fmtN(r.tokens), true) +
						td(fmtUsd(r.cost_usd), true) +
						td(fmtN(r.avg_latency_ms) + ' ms', true)
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderByProvider(rows) {
				var el = document.getElementById('bzus-by-provider');
				if (!el) return;
				var total = 0;
				for (var i = 0; i < (rows||[]).length; i++) total += parseInt(rows[i].requests);
				var cols = ['Provider', 'Requests', '%', 'Chi phí', 'Lỗi'];
				var trows = [];
				for (var k = 0; k < (rows||[]).length; k++) {
					var r = rows[k];
					var lbl = providerLabels[r.provider] || ('🔌 ' + r.provider);
					trows.push(
						td(lbl) +
						td('<strong>' + fmtN(r.requests) + '</strong>', true) +
						td(pct(r.requests, total), true, 'color:#646970;') +
						td(fmtUsd(r.cost_usd), true) +
						td(fmtN(r.errors), true, parseInt(r.errors)>0?'color:#d63638;':'')
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderByPlugin(rows) {
				var el = document.getElementById('bzus-by-plugin');
				if (!el) return;
				var total = 0;
				for (var i = 0; i < (rows||[]).length; i++) total += parseInt(rows[i].requests);
				var cols = ['Plugin', 'Requests', '%', 'Chi phí'];
				var trows = [];
				for (var k = 0; k < (rows||[]).length; k++) {
					var r = rows[k];
					trows.push(
						td(r.plugin_name) +
						td('<strong>' + fmtN(r.requests) + '</strong>', true) +
						td(pct(r.requests, total), true, 'color:#646970;') +
						td(fmtUsd(r.cost_usd), true)
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderByCallType(rows) {
				var el = document.getElementById('bzus-by-calltype');
				if (!el) return;
				// Aggregate rows by group
				var groups = {};
				var groupOrder = [];
				for (var i = 0; i < (rows||[]).length; i++) {
					var r  = rows[i];
					var grp = purposeGroups[r.purpose] || ('🔧 ' + r.purpose);
					if (!groups[grp]) {
						groups[grp] = { requests: 0, tokens: 0, cost_usd: 0 };
						groupOrder.push(grp);
					}
					groups[grp].requests  += parseInt(r.requests);
					groups[grp].tokens    += parseInt(r.tokens);
					groups[grp].cost_usd  += parseFloat(r.cost_usd);
				}
				var total = 0;
				for (var g in groups) total += groups[g].requests;
				var cols = ['Loại gọi', 'Requests', '%', 'Tokens', 'Chi phí'];
				var trows = [];
				for (var k = 0; k < groupOrder.length; k++) {
					var grpName = groupOrder[k];
					var gd = groups[grpName];
					trows.push(
						td(grpName) +
						td('<strong>' + fmtN(gd.requests) + '</strong>', true) +
						td(pct(gd.requests, total), true, 'color:#646970;') +
						td(fmtN(gd.tokens), true) +
						td(fmtUsd(gd.cost_usd), true)
					);
				}
				el.innerHTML = tbl(cols, trows, 'Chưa có dữ liệu.');
			}

			function renderAll(d) {
				renderSummary(d.summary || {});
				renderMeter(d.meter || null);
				renderByDay(d.by_day);
				renderByService(d.by_service);
				renderByModel(d.by_model);
				renderByProvider(d.by_provider);
				renderByPlugin(d.by_plugin);
				renderByCallType(d.by_purpose);
				var ft = document.getElementById('bzus-fetched-at');
				if (ft) ft.textContent = '✓ ' + new Date().toLocaleTimeString();
			}

			/* [2026-06-10 Johnny Chu] PHASE-LLM-ACTIVITY R8 — request/day meter bar. */
			function renderMeter(m) {
				var wrap = document.getElementById('bzus-meter');
				if (!wrap || !m || !m.requests) { if (wrap) wrap.style.display = 'none'; return; }
				wrap.style.display = '';

				var req  = m.requests;
				var used = parseInt(req.used) || 0;
				var cap  = parseInt(req.cap) || 0;
				var pctv = cap > 0 ? Math.min(100, Math.round(used * 100 / cap)) : 0;
				var barColor = pctv >= 100 ? '#d63638' : (pctv >= 80 ? '#dba617' : '#2271b1');
				var capTxt = cap > 0 ? fmtN(cap) : '∞';

				var mainEl = document.getElementById('bzus-meter-main');
				if (mainEl) {
					mainEl.innerHTML =
						'<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">'
						+ '<strong>' + fmtN(used) + ' / ' + capTxt + ' requests</strong>'
						+ '<span style="color:' + barColor + ';font-weight:700;">' + pctv + '%</span>'
						+ '</div>'
						+ '<div style="height:14px;background:#e2e8f0;border-radius:7px;overflow:hidden;">'
						+ '<div style="height:100%;width:' + pctv + '%;background:' + barColor + ';transition:width .3s;"></div>'
						+ '</div>'
						+ (m.exhausted ? '<div style="color:#d63638;font-weight:600;margin-top:6px;">⛔ Đã hết hạn mức request hôm nay.</div>' : '');
				}

				var typesEl = document.getElementById('bzus-meter-types');
				if (typesEl) {
					var html = '';
					var bt = m.by_type || {};
					var labels = { image: '🖼 Ảnh', video: '🎬 Video' };
					for (var t in labels) {
						if (!bt[t]) continue;
						var u = parseInt(bt[t].used) || 0;
						var c = parseInt(bt[t].cap) || 0;
						var ex = bt[t].exhausted;
						html += '<span style="' + (ex ? 'color:#d63638;font-weight:700;' : 'color:#1a1a1a;') + '">'
						      + labels[t] + ': ' + fmtN(u) + ' / ' + (c > 0 ? fmtN(c) : '∞')
						      + (ex ? ' ⛔' : '') + '</span>';
					}
					if (m.cost_usd) {
						html += '<span style="color:#92400e;">💰 ' + fmtUsd(m.cost_usd.used)
						      + ' / ' + fmtUsd(m.cost_usd.cap) + '/ngày</span>';
					}
					typesEl.innerHTML = html;
				}

				var resetEl = document.getElementById('bzus-meter-reset');
				if (resetEl && m.reset_at) {
					var dt = new Date(m.reset_at);
					resetEl.textContent = '🔄 Hạn mức reset lúc ' + (isNaN(dt) ? m.reset_at : dt.toLocaleString())
					    + ' · Gói: ' + (m.master_level || 'free');
				}
			}

			function loadStats(period, force) {
				var loadEl = document.getElementById('bzus-loading');
				var refBtn = document.getElementById('bzus-refresh');
				if (loadEl) loadEl.style.display = '';
				if (refBtn) refBtn.disabled = true;

				var fd = new FormData();
				fd.append('action',  'bizcity_twinchat_usage_stats');
				fd.append('nonce',   NONCE);
				fd.append('period',  period);
				if (force) fd.append('force_refresh', '1');

				fetch(AJAX_URL, { method: 'POST', credentials: 'same-origin', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(json) {
						if (json && json.success && json.data) {
							renderAll(json.data);
						} else {
							var msg = (json && json.data) ? String(json.data) : 'Lỗi không xác định.';
							var ft = document.getElementById('bzus-fetched-at');
							if (ft) ft.textContent = '❌ ' + msg;
						}
					})
					.catch(function(err) {
						var ft = document.getElementById('bzus-fetched-at');
						if (ft) ft.textContent = '❌ Lỗi mạng: ' + (err && err.message ? err.message : '');
					})
					.then(function() {
						if (loadEl) loadEl.style.display = 'none';
						if (refBtn) { refBtn.disabled = false; }
					});
			}

			/* ── period buttons ── */
			var perioBtns = document.querySelectorAll('.bzus-period');
			for (var bi = 0; bi < perioBtns.length; bi++) {
				perioBtns[bi].addEventListener('click', function() {
					for (var xi = 0; xi < perioBtns.length; xi++) {
						perioBtns[xi].classList.remove('button-primary');
					}
					this.classList.add('button-primary');
					curPeriod = this.getAttribute('data-period');
					loadStats(curPeriod, false);
				});
			}

			/* ── refresh button ── */
			var refBtn = document.getElementById('bzus-refresh');
			if (refBtn) {
				refBtn.addEventListener('click', function() { loadStats(curPeriod, true); });
			}

			/* ── auto-load on page open ── */
			loadStats('30d', false);
		})();
		</script>
		<?php
	}

	/**
	 * [2026-06-08 Johnny Chu] PHASE-MASTER-PLANS — AJAX handler for usage stats tab.
	 * Calls BizCity_LLM_Client::get_usage_stats() (server-to-server Bearer).
	 */
	public function ajax_usage_stats(): void {
		check_ajax_referer( 'bizcity_usage_stats', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			wp_send_json_error( 'BizCity_LLM_Client not loaded.' );
		}

		$period = isset( $_POST['period'] ) ? sanitize_key( $_POST['period'] ) : '30d';
		$force  = ! empty( $_POST['force_refresh'] );

		$result = BizCity_LLM_Client::instance()->get_usage_stats( [
			'period'        => $period,
			'timeout'       => 15,
			'force_refresh' => $force,
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * [2026-06-03 Johnny Chu] R-1API-9 — Hướng dẫn cài đặt A→Z (collapsible),
	 * render ngay trên trang Settings để admin tra cứu nhanh không cần rời trang.
	 * Nội dung đầy đủ trong docs/INSTALL-USER-GUIDE-VI.md.
	 */
	private function render_install_guide(): void {
		$plugin_dir = plugin_dir_path( dirname( dirname( dirname( __FILE__ ) ) ) );
		$guide_rel  = 'docs/INSTALL-USER-GUIDE-VI.md';
		$guide_url  = plugins_url( $guide_rel, $plugin_dir . 'bizcity-twin-ai.php' );
		$has_key    = self::get_api_key() !== '';
		$base       = self::get_gateway_url();
		?>
		<details class="bizcity-install-guide" <?php echo $has_key ? '' : 'open'; ?>
		         style="margin:8px 0 20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00a32a;border-radius:4px;padding:0;">
			<summary style="cursor:pointer;padding:14px 18px;font-size:14px;font-weight:600;list-style:none;">
				📘 <?php esc_html_e( 'Hướng dẫn cài đặt từ A → Z (LocalWP → Plugin → API key → Settings)', 'bizcity-twin-ai' ); ?>
				<span style="float:right;color:#646970;font-weight:normal;font-size:12px;">
					<?php echo $has_key ? esc_html__( 'Bấm để mở', 'bizcity-twin-ai' )
					                    : esc_html__( '⚠ Chưa cấu hình key — đọc hướng dẫn này', 'bizcity-twin-ai' ); ?>
				</span>
			</summary>
			<div style="padding:4px 22px 18px;font-size:13px;line-height:1.7;color:#1d2327;">

				<p style="margin:6px 0 14px;color:#646970;">
					<?php esc_html_e( 'Lần đầu cài plugin? Theo đúng 6 bước dưới đây — tổng ~30 phút. Tài liệu chi tiết đầy đủ:', 'bizcity-twin-ai' ); ?>
					<a href="<?php echo esc_url( $guide_url ); ?>" target="_blank" rel="noopener">
						📄 docs/INSTALL-USER-GUIDE-VI.md
					</a>
				</p>

				<ol style="margin:0 0 14px 22px;padding:0;">
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Cài LocalWP', 'bizcity-twin-ai' ); ?></strong> —
						<?php esc_html_e( 'tải miễn phí tại', 'bizcity-twin-ai' ); ?>
						<a href="https://localwp.com/" target="_blank" rel="noopener">localwp.com</a>
						→ <?php esc_html_e( 'tạo site mới (PHP 7.4 hoặc 8.1) → mở WP Admin.', 'bizcity-twin-ai' ); ?>
						<em style="color:#646970;">(<?php esc_html_e( 'bỏ qua bước này nếu đã có site WordPress', 'bizcity-twin-ai' ); ?>)</em>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Tải plugin Bizcity Twin Brain', 'bizcity-twin-ai' ); ?></strong> —
						<a href="https://github.com/bizcity/bizcity-twin-ai" target="_blank" rel="noopener">GitHub</a>
						→ <?php esc_html_e( '"Code → Download ZIP"', 'bizcity-twin-ai' ); ?>
						<em style="color:#646970;">(<?php esc_html_e( 'KHÔNG tải bizcity-llm-router — đó là plugin chỉ chạy trên server BizCity', 'bizcity-twin-ai' ); ?>)</em>.
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Upload & Activate', 'bizcity-twin-ai' ); ?></strong> —
						<?php esc_html_e( 'WP Admin → Plugins → Add New → Upload Plugin → chọn ZIP → Install → Activate.', 'bizcity-twin-ai' ); ?>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Đăng ký BizCity API key', 'bizcity-twin-ai' ); ?></strong> — 2 cách:
						<ul style="margin:4px 0 4px 18px;padding:0;list-style:disc;">
							<li>
								<strong><?php esc_html_e( 'Nhanh:', 'bizcity-twin-ai' ); ?></strong>
								<?php esc_html_e( 'cuộn xuống mục "Đăng ký nhanh" bên dưới → bấm "⚡ Đăng ký nhanh key BizCity" → hệ thống dùng email admin tạo key tự động.', 'bizcity-twin-ai' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Thủ công:', 'bizcity-twin-ai' ); ?></strong>
								<?php esc_html_e( 'vào', 'bizcity-twin-ai' ); ?>
								<a href="<?php echo esc_url( $base . '/my-account/api-keys/' ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $base ); ?>/my-account/api-keys/
								</a>
								→ <?php esc_html_e( 'đăng ký tài khoản → bấm "Tạo key mới" → copy key dạng', 'bizcity-twin-ai' ); ?>
								<code>biz-xxxxxxxx…</code> <em style="color:#646970;">(<?php esc_html_e( 'key chỉ hiện 1 lần', 'bizcity-twin-ai' ); ?>)</em>.
							</li>
						</ul>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Dán key vào ô "BizCity API key" bên dưới', 'bizcity-twin-ai' ); ?></strong> →
						<?php esc_html_e( 'Gateway URL để trống → bấm "💾 Lưu cấu hình".', 'bizcity-twin-ai' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Test connection', 'bizcity-twin-ai' ); ?></strong> —
						<?php esc_html_e( 'bấm "🔌 Test gateway" → nếu thấy ✅ Test OK + HTTP 200 → xong, plugin đã sẵn sàng dùng cho TwinChat, KG Hub, Automation Workflow, BizCoach…', 'bizcity-twin-ai' ); ?>
					</li>
				</ol>

				<div style="background:#f0f6fc;border-left:3px solid #2271b1;padding:10px 14px;margin:12px 0;font-size:12px;">
					<strong>💡 <?php esc_html_e( 'Mẹo:', 'bizcity-twin-ai' ); ?></strong>
					<?php esc_html_e( 'Theo nguyên tắc R-1API, bạn CHỈ cần dán key 1 lần tại trang này — mọi plugin BizCity (TwinChat, BizCoach, Tool Image, Video, Custom Flows…) sẽ tự động kế thừa, không cần cấu hình lại ở từng plugin.', 'bizcity-twin-ai' ); ?>
				</div>

				<details style="margin-top:10px;">
					<summary style="cursor:pointer;font-weight:600;color:#2271b1;">
						🆘 <?php esc_html_e( 'Xử lý sự cố thường gặp (FAQ)', 'bizcity-twin-ai' ); ?>
					</summary>
					<ul style="margin:8px 0 0 22px;padding:0;list-style:disc;">
						<li><strong>HTTP 401 Unauthorized</strong> — <?php esc_html_e( 'key sai hoặc thu hồi. Copy lại key trên bizcity.vn, không thừa khoảng trắng.', 'bizcity-twin-ai' ); ?></li>
						<li><strong>HTTP 402 / insufficient_balance</strong> — <?php esc_html_e( 'hết credit. Vào My Account → Billing để topup.', 'bizcity-twin-ai' ); ?></li>
						<li><strong>HTTP 0 / could not resolve host</strong> — <?php esc_html_e( 'mạng/firewall chặn bizcity.vn. Kiểm tra kết nối internet.', 'bizcity-twin-ai' ); ?></li>
						<li><strong><?php esc_html_e( 'Trắng trang sau activate', 'bizcity-twin-ai' ); ?></strong> — <?php esc_html_e( 'đổi PHP về 7.4 hoặc 8.1 trong LocalWP → tab Overview → Restart site.', 'bizcity-twin-ai' ); ?></li>
						<li><strong><?php esc_html_e( 'Có cần cài bizcity-llm-router không?', 'bizcity-twin-ai' ); ?></strong> — <?php esc_html_e( 'KHÔNG. Plugin đó chỉ chạy trên server bizcity.vn. Client chỉ cần bizcity-twin-ai + API key.', 'bizcity-twin-ai' ); ?></li>
					</ul>
				</details>

				<p style="margin:14px 0 0;font-size:12px;color:#646970;">
					📖 <?php esc_html_e( 'Đọc đầy đủ:', 'bizcity-twin-ai' ); ?>
					<a href="<?php echo esc_url( $guide_url ); ?>" target="_blank" rel="noopener">docs/INSTALL-USER-GUIDE-VI.md</a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( plugins_url( 'docs/getting-started.md', $plugin_dir . 'bizcity-twin-ai.php' ) ); ?>" target="_blank" rel="noopener">
						getting-started.md <?php esc_html_e( '(cho developer)', 'bizcity-twin-ai' ); ?>
					</a>
					&nbsp;·&nbsp;
					<a href="mailto:support@bizcity.vn">support@bizcity.vn</a>
				</p>
			</div>
		</details>
		<?php
	}

	/**
	 * Table liệt kê toàn bộ plugin/module dùng chung `bizcity_llm_api_key` — render ngay
	 * dưới form canonical để admin biết key sẽ ảnh hưởng tới đâu.
	 */
	private function render_consumer_plugins_table(): void {
		$consumers = $this->consumer_plugins();
		?>
		<div class="bizcity-llm-card" style="margin-top:24px;background:#fff;padding:20px;border:1px solid #c3c4c7;border-radius:4px;">
			<h2 style="margin-top:0;">🔌 <?php esc_html_e( 'Plugins / Modules đang dùng chung 1 API key này', 'bizcity-twin-ai' ); ?></h2>
			<p class="description" style="margin-bottom:12px;">
				<?php esc_html_e( 'Tự động kế thừa cấu hình ở trên. Sub-plugin có thể đăng ký thêm vào danh sách qua filter `bizcity_llm_consumer_plugins`.', 'bizcity-twin-ai' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:32%;"><?php esc_html_e( 'Plugin / Module', 'bizcity-twin-ai' ); ?></th>
						<th><?php esc_html_e( 'Mô tả', 'bizcity-twin-ai' ); ?></th>
						<th style="width:120px;"><?php esc_html_e( 'Trạng thái', 'bizcity-twin-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $consumers as $c ) :
						$status = isset( $c['status'] ) ? (string) $c['status'] : 'ok';
						$badge  = $status === 'ok'      ? '<span style="color:#00a32a;">✅ Active</span>'
						        : ( $status === 'migrating' ? '<span style="color:#dba617;">🚧 Migrating</span>'
						        : '<span style="color:#d63638;">❌ Violation</span>' );
						?>
						<tr>
							<td><strong><?php echo esc_html( (string) ( $c['label'] ?? '' ) ); ?></strong></td>
							<td><?php echo esc_html( (string) ( $c['desc']  ?? '' ) ); ?></td>
							<td><?php echo $badge; // already escaped ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Original slim UI — kept as fallback when BizCity_LLM_Settings is missing.
	 */
	private function render_legacy(): void {

		$key       = self::get_api_key();
		$url       = (string) get_option( self::OPT_GATEWAY_URL, '' ); // [2026-06-11 Johnny Chu] HOTFIX — per-site
		$base      = self::get_gateway_url();
		// [2026-06-11 Johnny Chu] HOTFIX — unified option key
		$_raw_test = (array) get_option( 'bizcity_llm_last_test', [] );
		$last_test = [
			'success' => ! empty( $_raw_test['ok'] ),
			'status'  => (int) ( $_raw_test['status'] ?? 0 ),
			'latency' => (int) ( $_raw_test['ms']     ?? 0 ),
			'error'   => (string) ( $_raw_test['message'] ?? '' ),
			'tier'    => (string) ( $_raw_test['tier']    ?? '' ),
			'balance' => (string) ( $_raw_test['balance'] ?? '' ),
		];
		$last_at   = (int) ( $_raw_test['ts'] ?? 0 );
		$consumers = $this->consumer_plugins();

		$register_error = isset( $_GET['register_error'] ) ? rawurldecode( (string) $_GET['register_error'] ) : '';
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'BizCity API & Gateway', 'bizcity-twin-ai' ); ?>
				<span style="font-size:13px;font-weight:normal;color:#646970;">
					— <?php esc_html_e( '1 API key dùng chung cho mọi dịch vụ BizCity', 'bizcity-twin-ai' ); ?>
				</span>
			</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Đã lưu cấu hình.', 'bizcity-twin-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['registered'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '✅ Đã đăng ký key mới từ BizCity Hub.', 'bizcity-twin-ai' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $register_error !== '' ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<strong><?php esc_html_e( '❌ Đăng ký nhanh thất bại:', 'bizcity-twin-ai' ); ?></strong>
					<code><?php echo esc_html( $register_error ); ?></code>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['tested'] ) ) : ?>
				<div class="notice <?php echo ! empty( $last_test['success'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible">
					<p>
						<strong><?php echo ! empty( $last_test['success'] ) ? '✅ Test OK' : '❌ Test failed'; ?></strong>
						— HTTP <?php echo (int) ( $last_test['status'] ?? 0 ); ?>,
						<?php echo (int) ( $last_test['latency'] ?? 0 ); ?> ms.
						<?php if ( ! empty( $last_test['tier'] ) ) : ?>
							· tier: <code><?php echo esc_html( (string) $last_test['tier'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $last_test['balance'] ) ) : ?>
							· balance: <code>$<?php echo esc_html( (string) $last_test['balance'] ); ?></code>
						<?php endif; ?>
						<?php if ( ! empty( $last_test['error'] ) ) : ?>
							<br><code><?php echo esc_html( (string) $last_test['error'] ); ?></code>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline" style="margin-bottom:16px;">
				<p>
					📜 <strong><?php esc_html_e( 'Tiêu chuẩn R-1API:', 'bizcity-twin-ai' ); ?></strong>
					<?php esc_html_e( 'Mỗi site CHỈ cần 1 API key BizCity duy nhất — dùng chung cho LLM, embeddings, web search, astrology, video, billing và mọi plugin BizCity khác. Cấu hình tại đây sẽ áp dụng toàn bộ network.', 'bizcity-twin-ai' ); ?>
				</p>
			</div>

			<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
				<div style="flex:1;min-width:520px;max-width:760px;">

					<h2><?php esc_html_e( '1. Cấu hình API key', 'bizcity-twin-ai' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bizcity_twinchat_save_gateway">
						<?php wp_nonce_field( 'bizcity_twinchat_save_gateway' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="bizcity_llm_api_key"><?php esc_html_e( 'BizCity API key', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<input type="password" id="bizcity_llm_api_key" name="bizcity_llm_api_key"
									       value="<?php echo esc_attr( $key ); ?>"
									       class="regular-text" autocomplete="off"
									       placeholder="biz-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
									<p class="description">
										<?php esc_html_e( 'Bearer token cấp bởi bizcity.vn. Dán key đã có hoặc bấm "Đăng ký nhanh" bên dưới để hệ thống tự tạo.', 'bizcity-twin-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="bizcity_llm_gateway_url"><?php esc_html_e( 'Gateway URL (tuỳ chọn)', 'bizcity-twin-ai' ); ?></label></th>
								<td>
									<input type="url" id="bizcity_llm_gateway_url" name="bizcity_llm_gateway_url"
									       value="<?php echo esc_attr( $url ); ?>"
									       class="regular-text"
									       placeholder="<?php echo esc_attr( self::DEFAULT_GATEWAY ); ?>">
									<p class="description">
										<?php
										printf(
											/* translators: %s = default hub URL */
											esc_html__( 'Để trống → dùng mặc định %s. Chỉ override khi chạy staging hub.', 'bizcity-twin-ai' ),
											'<code>' . esc_html( self::DEFAULT_GATEWAY ) . '</code>'
										);
										?>
									</p>
								</td>
							</tr>
						</table>
						<?php submit_button( __( '💾 Lưu cấu hình', 'bizcity-twin-ai' ) ); ?>
					</form>

					<hr>

					<h2><?php esc_html_e( '2. Đăng ký nhanh (chưa có key?)', 'bizcity-twin-ai' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bizcity_twinchat_register_key">
						<?php wp_nonce_field( 'bizcity_twinchat_register_key' ); ?>
						<p>
							<?php esc_html_e( '1-click tạo BizCity API key bằng email admin của site này. Free-tier mặc định $0 — sau khi có key, bấm "Nâng cấp gói" để topup credit.', 'bizcity-twin-ai' ); ?>
						</p>
						<?php submit_button( __( '⚡ Đăng ký nhanh key BizCity', 'bizcity-twin-ai' ), 'secondary', 'submit', false ); ?>
						<a class="button button-link" target="_blank" rel="noopener"
						   href="<?php echo esc_url( $base . '/my-account/api-keys/' ); ?>">
							🔗 <?php esc_html_e( 'Mở my-account/api-keys/ trên BizCity', 'bizcity-twin-ai' ); ?>
						</a>
					</form>

					<hr>

					<h2><?php esc_html_e( '3. Test connection', 'bizcity-twin-ai' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bizcity_twinchat_test_gateway">
						<?php wp_nonce_field( 'bizcity_twinchat_test_gateway' ); ?>
						<p><?php esc_html_e( 'Gọi GET /bizcity/v1/account/info để xác minh key hoạt động và lấy tier + balance.', 'bizcity-twin-ai' ); ?></p>
						<?php submit_button( __( '🔌 Test gateway', 'bizcity-twin-ai' ), 'secondary', 'submit', false ); ?>
						<a class="button button-primary" target="_blank" rel="noopener"
						   href="<?php echo esc_url( $base . '/my-account/' ); ?>">
							⬆ <?php esc_html_e( 'Nâng cấp gói / Topup credit', 'bizcity-twin-ai' ); ?>
						</a>
					</form>

					<hr>

					<h2><?php esc_html_e( '4. Plugins đang dùng chung key này', 'bizcity-twin-ai' ); ?></h2>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'Mọi plugin BizCity tự động kế thừa cấu hình ở trên. Không cần cấu hình lại trong từng plugin (theo R-1API-9, R-1API-10).', 'bizcity-twin-ai' ); ?>
					</p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Plugin', 'bizcity-twin-ai' ); ?></th>
								<th><?php esc_html_e( 'Mô tả', 'bizcity-twin-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $consumers as $c ) : ?>
								<tr>
									<td><strong><?php echo esc_html( (string) ( $c['label'] ?? '' ) ); ?></strong></td>
									<td><?php echo esc_html( (string) ( $c['desc']  ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<aside style="flex:0 0 320px;background:#f6f7f7;border:1px solid #c3c4c7;padding:14px 18px;">
					<h2 style="margin-top:0;"><?php esc_html_e( 'Trạng thái', 'bizcity-twin-ai' ); ?></h2>
					<p>
						<strong><?php esc_html_e( 'Hub URL', 'bizcity-twin-ai' ); ?>:</strong><br>
						<code style="word-break:break-all;"><?php echo esc_html( $base ); ?></code>
						<?php if ( $url === '' ) : ?>
							<br><small style="color:#646970;">(<?php esc_html_e( 'mặc định', 'bizcity-twin-ai' ); ?>)</small>
						<?php endif; ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'API key', 'bizcity-twin-ai' ); ?>:</strong><br>
						<?php if ( $key !== '' ) : ?>
							<code><?php echo esc_html( self::get_masked_key() ); ?></code>
						<?php else : ?>
							<em style="color:#d63638;"><?php esc_html_e( '(chưa cấu hình)', 'bizcity-twin-ai' ); ?></em>
						<?php endif; ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Last test', 'bizcity-twin-ai' ); ?>:</strong><br>
						<?php if ( $last_at ) : ?>
							<?php echo esc_html( human_time_diff( $last_at, time() ) . ' ago' ); ?><br>
							<?php
							$ok = ! empty( $last_test['success'] );
							echo $ok ? '<span style="color:#00a32a;">✅</span>' : '<span style="color:#d63638;">❌</span>';
							echo ' HTTP ' . (int) ( $last_test['status'] ?? 0 );
							echo ', ' . (int) ( $last_test['latency'] ?? 0 ) . ' ms';
							?>
						<?php else : ?>
							<em><?php esc_html_e( 'never run', 'bizcity-twin-ai' ); ?></em>
						<?php endif; ?>
					</p>
					<hr>
					<p style="font-size:12px;color:#646970;">
						📖 <?php esc_html_e( 'Rule canonical:', 'bizcity-twin-ai' ); ?>
						<a href="<?php echo esc_url( plugins_url( 'PHASE-0-RULE-1-API.md', dirname( dirname( dirname( __FILE__ ) ) ) ) ); ?>" target="_blank">
							PHASE-0-RULE-1-API.md
						</a>
					</p>
				</aside>
			</div>
		</div>
		<?php
	}
}
