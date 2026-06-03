<?php
/**
 * BizCity Facebook Bot — Workflow Integration (PHASE 0.31 T-S1.3)
 *
 * Registers Facebook Messenger as a first-class channel inside the
 * `bizcity-automation` Integrations Hub by extending
 * `WaicChannelIntegration` and hooking into the discovery filter
 * `bizcity_register_channel_integrations` (T-S1.2).
 *
 * One UI source of truth for: App ID, App Secret, Verify Token, default
 * page binding. Page tokens themselves continue to live in
 * `wp_bizcity_facebook_bots` (multi-page setup) — this integration is a
 * *thin* wrapper that surfaces global credentials + selected default
 * page, delegating outbound to `BizCity_Facebook_Bot_Channel_Adapter`.
 *
 * @package BizCity\FacebookBot
 * @since   1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register on the discovery filter. The class itself is loaded lazily by
 * `WaicIntegrationsModel::getIntegClass()` so it is safe to declare here
 * even if `WaicChannelIntegration` has not been imported yet at this
 * point in the boot order.
 */
add_filter( 'bizcity_register_channel_integrations', function ( $list ) {
	$list['facebook'] = array(
		'class' => 'WaicChannelIntegration_facebook',
		'file'  => __FILE__,
	);
	return $list;
} );

/**
 * Inject a tiny delegated handler so the "Đăng nhập Facebook" button inside
 * the WAIC integration settings dialog actually navigates to the OAuth start
 * URL. WAIC's `type='button'` field renders a bare <button> and only built-in
 * OAuth providers (gmail/outlook/zoom) get a JS wire-up via proxy/signature;
 * for our channel integration we delegate by btn_label sentinel text.
 */
add_action( 'admin_footer', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	$sid = (string) $screen->id;
	// BizCity PHASE 0.31 Sprint 6 — also fire on the standalone
	// "Tích hợp bên ngoài" admin page (page=bizcity-integrations).
	if ( strpos( $sid, 'bizcity-workspace' ) === false
		&& strpos( $sid, 'bizcity-integrations' ) === false ) {
		return;
	}
	$oauth_url = esc_url_raw( home_url( '/?biz_fb_oauth=user_start' ) );
	// NOTE: dialog fallback CSS now lives in bizcity-automation:
	//   modules/workflow/includes/integ-dialog-fallback-style.php
	// Only the Facebook-specific OAuth button click handler stays here.
	?>
	<script>
	(function($){
		if (!window.jQuery) return;
		var FB_OAUTH_URL = <?php echo wp_json_encode( $oauth_url ); ?>;
		var FB_OAUTH_LABEL = '\ud83d\udd17 <?php echo esc_js( __( 'Đăng nhập Facebook', 'ai-copilot-content-generator' ) ); ?>';

		// Delegated click on any button inside the integration dialog whose
		// label text matches our sentinel — robust against WAIC's dynamic form rebuild.
		$(document).on('click', '#waicIntegSettingsDialog button, .waic-dialog-form button', function(e){
			var t = ($(this).text() || '').trim();
			if (t.indexOf('Đăng nhập Facebook') !== -1 || t.indexOf('Login Facebook') !== -1) {
				e.preventDefault();
				e.stopPropagation();
				window.open(FB_OAUTH_URL, '_blank', 'noopener,noreferrer');
				return false;
			}
		});

		// BizCity PHASE 0.31 Sprint 6 — inject "🔗 Đăng nhập Facebook" link
		// into the LEFT cell of every saved Facebook account row (right after
		// the account name). Đặt ở đây để user thấy rõ cạnh tên "bizcity",
		// trước nhóm Test/Edit/Delete bên phải.
		function injectFbOauthLink() {
			$('.waic-section[data-code="facebook"] .waic-integ-account').each(function(){
				var $row = $(this);
				if ($row.find('.waic-account-coltrol-fb-oauth').length) return;
				var $nameCell = $row.find('.waic-account-name').first();
				if (!$nameCell.length) return;
				var num = $row.attr('data-num') || 0;
				var $a = $('<a href="#" class="waic-account-coltrol-fb-oauth" style="margin-left:12px;color:#1d4ed8;text-decoration:none;font-weight:600;"></a>')
					.text('🔗 ' + '<?php echo esc_js( __( 'Đăng nhập Facebook', 'ai-copilot-content-generator' ) ); ?>')
					.attr('data-num', num);
				$a.on('click', function(e){
					e.preventDefault();
					var n = $(this).attr('data-num') || 0;
					window.open(FB_OAUTH_URL + '&num=' + encodeURIComponent(n), '_blank', 'noopener,noreferrer');
				});
				$nameCell.after($a);
			});
		}

		// Run once on ready and re-run when WAIC re-renders the accounts list
		// (after Save/Delete the integ controller rebuilds rows). Also re-run
		// after every click on a section header (createHtmlAccounts() rebuilds
		// rows on expand) — slight delay to wait for DOM update.
		$(function(){ setTimeout(injectFbOauthLink, 200); });
		$(document).on('DOMNodeInserted', '.waic-accounts-list', function(){
			clearTimeout(window._bizFbOauthInjectT);
			window._bizFbOauthInjectT = setTimeout(injectFbOauthLink, 80);
		});
		$(document).on('click', '.waic-section[data-code="facebook"] .waic-section-header', function(){
			setTimeout(injectFbOauthLink, 120);
		});
	})(jQuery);
	</script>
	<?php
} );

/**
 * Class is only useful once `WaicChannelIntegration` (bizcity-automation)
 * is loaded. Without that base, defining the subclass would fatal.
 */
if ( ! class_exists( 'WaicChannelIntegration' ) ) {
	return;
}

class WaicChannelIntegration_facebook extends WaicChannelIntegration {

	protected $_code     = 'facebook';
	protected $_logo     = 'FB';
	protected $_order    = 10;
	protected $_platform = 'FACEBOOK';
	protected $_prefix   = 'fb_';

	public function __construct( $integration = false ) {
		$this->_name = 'Facebook Page (DM + Post)';
		$this->_desc = __( 'Kết nối Facebook Page: gửi/nhận Messenger DM và đăng bài lên Page feed qua channel-gateway.', 'ai-copilot-content-generator' );
		// PHASE 0.31 Sprint 6 follow-up — surface plugin-owned settings page
		// (bizcity-tool-facebook /tool-facebook/) as "⚙ Cài đặt riêng →"
		// shortcut so users edit App ID/Secret + OAuth ở 1 chỗ duy nhất thay
		// vì duplicate giữa WAIC dialog và trang plugin gốc.
		$this->_config_url = home_url( '/tool-facebook/?tab=settings' );
		$this->setIntegration( $integration );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		// Page list is sourced from wp_bizcity_facebook_bots so the user
		// picks an existing connected page rather than re-entering tokens.
		$pages           = $this->collect_page_options();
		$callback_uri    = home_url( '/?biz_fb_oauth=callback' );
		$webhook_uri     = home_url( '/?fbhook=1' );
		$oauth_start_url = home_url( '/?biz_fb_oauth=user_start' );

		$guide_html  = '<div style="line-height:1.7;font-size:13px;background:#fff;border-left:4px solid #1d4ed8;padding:14px 18px;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.04)">';
		$guide_html .= '<p style="margin:0 0 8px;font-size:14px;color:#0f172a"><strong>📘 Hướng dẫn kết nối Facebook Page (Messenger DM + Đăng bài Page)</strong></p>';
		$guide_html .= '<p style="margin:0 0 10px;color:#475569"><em>Pattern giống <strong>Zalo Bot</strong> / <strong>Bizcity Facebook Bot</strong>: tạo App → cấu hình Webhook + OAuth → bấm <em>Đăng nhập Facebook</em> để pull Page tokens vào DB.</em></p>';
		$guide_html .= '<p style="margin:0 0 10px"><strong>🎥 Video hướng dẫn:</strong> <a href="https://www.youtube.com/results?search_query=facebook+messenger+webhook+setup" target="_blank" rel="noopener">Setup Facebook Messenger Webhook</a> · <a href="https://developers.facebook.com/docs/messenger-platform/getting-started/quick-start" target="_blank" rel="noopener">Quick Start (Meta Docs)</a></p>';
		$guide_html .= '<ol style="padding-left:20px;margin:6px 0">';
		$guide_html .= '<li><strong>Bước 1.</strong> Mở <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps/</a> → bấm <em>Create App</em> → chọn loại <strong>Business</strong>.</li>';
		$guide_html .= '<li><strong>Bước 2.</strong> Vào <em>App settings → Basic</em> để lấy <strong>App ID</strong> và <strong>App Secret</strong>, dán vào ô bên dưới.</li>';
		$guide_html .= '<li><strong>Bước 3.</strong> Add product <strong>Facebook Login for Business</strong> và <strong>Messenger</strong>. Trong <em>Facebook Login → Settings</em>, mục <strong>Valid OAuth Redirect URIs</strong>, dán URL ở ô <em>“OAuth Callback URL”</em> bên dưới.</li>';
		$guide_html .= '<li><strong>Bước 4.</strong> Trong <em>Messenger → Settings → Webhooks</em>: <strong>Callback URL</strong> = ô <em>“Webhook URL”</em>, <strong>Verify Token</strong> = ô <em>“Webhook Verify Token”</em> (mặc định <code>bizgpt</code>). Subscribe các fields: <code>messages, messaging_postbacks, feed</code>.</li>';
		$guide_html .= '<li><strong>Bước 5.</strong> Add các quyền sau vào App Review (live mode): <code>pages_messaging</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code>, <code>pages_manage_metadata</code>.</li>';
		$guide_html .= '<li><strong>Bước 6.</strong> Bấm <strong>Save</strong> popup này → row tài khoản xuất hiện ở dưới → bấm link <strong>“🔗 Đăng nhập Facebook”</strong> ngay cạnh tên account để OAuth → chọn Page → token được lưu vào <code>wp_bizcity_facebook_bots</code> và xuất hiện ở dropdown <em>“Default connected Page”</em>.</li>';
		$guide_html .= '</ol>';
		$guide_html .= '<p style="margin:8px 0 0;color:#666"><em>💡 Lưu ý:</em> nếu chưa thấy Page nào sau khi OAuth → reload trang. Token sống ~60 ngày, hệ thống tự refresh khi còn 7 ngày. Status <strong>Error</strong> ở row = chưa OAuth (chưa có Page nào trong <code>wp_bizcity_facebook_bots</code>).</p>';
		$guide_html .= '</div>';

		$this->_settings = array(
			'_guide_intro' => array(
				'type'    => 'html',
				'content' => $guide_html,
				'label'   => '',
			),
			'name' => array(
				'type'    => 'input',
				'label'   => __( 'Profile name', 'ai-copilot-content-generator' ),
				'plh'     => __( 'Internal label for this Facebook account', 'ai-copilot-content-generator' ),
				'default' => '',
				'desc'    => __( 'Tên gợi nhớ — chỉ hiển thị nội bộ.', 'ai-copilot-content-generator' ),
			),
			'app_id' => array(
				'type'    => 'input',
				'label'   => __( 'Facebook App ID', 'ai-copilot-content-generator' ),
				'plh'     => __( 'Leave empty to inherit site-wide bztfb_app_id', 'ai-copilot-content-generator' ),
				'default' => '',
				'desc'    => __( 'Lấy từ developers.facebook.com → App settings → Basic → App ID.', 'ai-copilot-content-generator' ),
			),
			'app_secret' => array(
				'type'    => 'input',
				'label'   => __( 'Facebook App Secret', 'ai-copilot-content-generator' ),
				'plh'     => __( 'Used to verify webhook signature (X-Hub-Signature-256)', 'ai-copilot-content-generator' ),
				'default' => '',
				'encrypt' => true,
				'desc'    => __( 'Lấy ở cùng trang App Settings → Basic, bấm “Show” để hiện. Dùng để verify chữ ký webhook và đổi user code → access token.', 'ai-copilot-content-generator' ),
			),
			'config_id' => array(
				'type'    => 'input',
				'label'   => __( 'Login Configuration ID (Facebook Login for Business)', 'ai-copilot-content-generator' ),
				'plh'     => __( 'VD: 1234567890123456 — bắt buộc với Business app', 'ai-copilot-content-generator' ),
				'default' => '',
				'desc'    => __( 'Lấy từ Meta App Dashboard → Use cases → Facebook Login for Business → Configurations → copy ID. Bắt buộc có nếu app type = Business; nếu để trống với Business app thì FB chỉ cấp public_profile (không pull được Pages).', 'ai-copilot-content-generator' ),
			),
			'verify_token' => array(
				'type'    => 'input',
				'label'   => __( 'Webhook Verify Token', 'ai-copilot-content-generator' ),
				'plh'     => 'bizgpt',
				'default' => 'bizgpt',
				'desc'    => __( 'Phải khớp với “Verify Token” đã đặt trong Messenger → Webhooks setup.', 'ai-copilot-content-generator' ),
			),
			'_webhook_uri' => array(
				'type'     => 'input',
				'label'    => __( 'Webhook URL (paste vào FB App)', 'ai-copilot-content-generator' ),
				'default'  => $webhook_uri,
				'readonly' => true,
				'desc'     => __( 'Copy URL này dán vào Facebook Developer → Messenger → Webhooks → Callback URL.', 'ai-copilot-content-generator' ),
			),
			'_callback_uri' => array(
				'type'     => 'input',
				'label'    => __( 'OAuth Callback URL (paste vào FB Login → Valid OAuth Redirect URIs)', 'ai-copilot-content-generator' ),
				'default'  => $callback_uri,
				'readonly' => true,
				'desc'     => __( 'Đảm bảo URL này có trong Valid OAuth Redirect URIs trước khi bấm Đăng nhập.', 'ai-copilot-content-generator' ),
			),
			// BizCity PHASE 0.31 Sprint 6 — OAuth login field removed from form;
			// nhút Đăng nhập Facebook được inject vào row Test/Edit/Delete của tài
			// khoản đã SAVE (xem JS ở cuối file). Lý do: trong form, nếu user bấm
			// trước khi save → App ID/Secret chưa persist → OAuth start fail với
			// lỗi "Chưa cấu hình Facebook App ID/Secret".
			'default_bot_id' => array(
				'type'    => 'select',
				'label'   => __( 'Default connected Page', 'ai-copilot-content-generator' ),
				'options' => $pages,
				'default' => '',
				'desc'    => __( 'Page mặc định cho actions Facebook khi không chỉ định page rõ ràng.', 'ai-copilot-content-generator' ),
			),
		);
	}

	/**
	 * Build a select-friendly list of currently connected Pages.
	 *
	 * @return array bot_id => "Bot Name (page_id)"
	 */
	private function collect_page_options() {
		$out = array( '' => __( '— None / use first available —', 'ai-copilot-content-generator' ) );
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			return $out;
		}
		$bots = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
		if ( empty( $bots ) ) {
			return $out;
		}
		foreach ( $bots as $bot ) {
			$out[ (string) $bot->id ] = sprintf(
				'%s (%s)',
				$bot->bot_name ? $bot->bot_name : 'Bot #' . $bot->id,
				$bot->page_id
			);
		}
		return $out;
	}

	/**
	 * Trust the gateway adapter for connectivity (no separate ping). The
	 * adapter is registered at boot when at least one page row exists.
	 */
	public function doTest( $need = false ) {
		$params = $this->getParams();
		// BizCity PHASE 0.31 Sprint 6 — only short-circuit when previously
		// healthy (status=1). If status=7 (error) was cached we MUST re-probe
		// because the user just completed OAuth and pages may now exist.
		$cached = isset( $params['_status'] ) ? (int) $params['_status'] : 0;
		if ( ! $need && $cached === 1 ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$this->addParam( '_status', 7 );
			$this->addParam( '_status_error', 'mu-plugin bizcity-facebook-bot is not loaded' );
			return false;
		}
		$bots = BizCity_Facebook_Bot_Database::instance()->get_active_bots();
		if ( empty( $bots ) ) {
			$this->addParam( '_status', 7 );
			$this->addParam( '_status_error', 'No connected Facebook Pages found in wp_bizcity_facebook_bots' );
			return false;
		}
		$this->addParam( '_status', 1 );
		$this->addParam( '_status_error', '' );
		return true;
	}

	/**
	 * Block contributions populated incrementally in Sprint 2; placeholders
	 * keep the contract explicit.
	 */
	public function getTriggerBlocks() {
		return array(
			// Existing in `bizcity-automation/modules/workflow/blocks/triggers/`
			array( 'code' => 'wu_facebook_message_received' ),
			array( 'code' => 'wu_facebook_comment_received' ),
		);
	}

	public function getActionBlocks() {
		return array(
			array( 'code' => 'wp_send_facebook_bot_text' ),
			array( 'code' => 'wp_send_facebook_bot_photo' ),
			array( 'code' => 'wp_create_facebook_page_post' ),
		);
	}
}
