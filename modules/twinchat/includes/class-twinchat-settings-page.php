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
		return (string) get_site_option( self::OPT_API_KEY, '' );
	}

	/** @return string Canonical gateway base URL (no trailing slash). */
	public static function get_gateway_url(): string {
		$base = (string) get_site_option( self::OPT_GATEWAY_URL, '' );
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

		update_site_option( self::OPT_API_KEY,     $key );
		update_site_option( self::OPT_GATEWAY_URL, $url );

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

		update_site_option( 'bizcity_llm_last_test_at', time() );
		update_site_option( 'bizcity_llm_last_test_result', [
			'success' => (bool) $result['success'],
			'status'  => (int) $result['status'],
			'latency' => $latency,
			'error'   => $result['error'],
			'tier'    => $result['tier']    ?? '',
			'balance' => $result['balance'] ?? '',
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
				update_site_option( self::OPT_API_KEY, (string) $body['key'] );
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

		// Persist last test for sidebar.
		update_site_option( 'bizcity_llm_last_test_at', time() );
		update_site_option( 'bizcity_llm_last_test_result', [
			'success' => $out['success'],
			'status'  => $out['status'],
			'latency' => $out['latency_ms'],
			'error'   => $out['error'],
			'tier'    => $res['tier']    ?? '',
			'balance' => $res['balance'] ?? '',
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

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// Delegate to the canonical BizCity_LLM_Settings render — single source of truth
		// (R-1API-9: every page configuring `bizcity_llm_api_key` MUST render the same UI
		// from `core/bizcity-llm/includes/class-llm-settings.php`).
		//
		// Assets enqueue is handled by BizCity_LLM_Settings::enqueue_assets() — its
		// admin_enqueue_scripts hook now matches `twinchat_page_bizcity-twinchat-settings`
		// too, so CSS/JS load on time before <head> closes.
		if ( class_exists( 'BizCity_LLM_Settings' ) ) {
			$canonical = BizCity_LLM_Settings::instance();

			$this->render_twinchat_banner();
			// [2026-06-03 Johnny Chu] R-1API — Live status card (ping nhẹ /account/info).
			$this->render_account_status_card();
			$this->render_install_guide();
			$canonical->render_page();
			$this->render_consumer_plugins_table();
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
		$url       = (string) get_site_option( self::OPT_GATEWAY_URL, '' );
		$base      = self::get_gateway_url();
		$last_test = (array) get_site_option( 'bizcity_llm_last_test_result', [] );
		$last_at   = (int)   get_site_option( 'bizcity_llm_last_test_at', 0 );
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
