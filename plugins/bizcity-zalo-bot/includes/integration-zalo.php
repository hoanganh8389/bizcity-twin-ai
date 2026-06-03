<?php
/**
 * BizCity Zalo Bot — Workflow Integration (PHASE 0.31 T-S2.1)
 *
 * ⚠️ RUNTIME LOCATION (chốt 2026-05-07):
 *   Plugin Zalo Bot SỐNG ở `plugins/bizcity-twin-ai/plugins/bizcity-zalo-bot/`.
 *   KHÔNG còn chạy ở `mu-plugins/bizcity-zalo-bot/` (folder cũ chỉ giữ để rollback,
 *   không được autoload — mu-plugins WP chỉ autoload .php top-level).
 *
 * Mirrors `mu-plugins/bizcity-facebook-bot/includes/integration-facebook.php`
 * pattern (Sprint 1 T-S1.3): registers Zalo Bot as a first-class channel via
 * the discovery filter `bizcity_register_channel_integrations` so workflow
 * actions can resolve `default_bot_id` through the Integrations Hub instead
 * of reading `wp_bizcity_zalo_bots` directly.
 *
 * Single source of truth for: bot binding (selected default bot), prefix
 * `zalobot_` and platform code `ZALO_BOT`. Tokens themselves stay in
 * `wp_bizcity_zalo_bots` (multi-bot setup).
 *
 * @package BizCity\ZaloBot
 * @since   1.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'bizcity_register_channel_integrations', function ( $list ) {
	$list['zalo_bot'] = array(
		'class' => 'WaicChannelIntegration_zalobot',
		'file'  => __FILE__,
	);
	return $list;
} );

if ( ! class_exists( 'WaicChannelIntegration' ) ) {
	return;
}

class WaicChannelIntegration_zalobot extends WaicChannelIntegration {

	protected $_code     = 'zalo_bot';
	protected $_logo     = 'ZB';
	protected $_order    = 20;
	protected $_platform = 'ZALO_BOT';
	protected $_prefix   = 'zb_';

	public function __construct( $integration = false ) {
		$this->_name = 'Zalo Bot';
		$this->_desc = __( 'Send/receive Zalo Bot messages via the channel-gateway.', 'ai-copilot-content-generator' );
		// PHASE 0.31 Sprint 6 follow-up — surface plugin-owned settings page
		// (/tool-zalo-bizcity/?tab=bot) as "⚙ Cài đặt riêng →" shortcut so
		// users edit OA ID/access_token tại 1 chỗ duy nhất thay vì duplicate
		// giữa WAIC dialog và trang plugin gốc (mirror Facebook pattern).
		$this->_config_url = home_url( '/tool-zalo-bizcity/?tab=bot' );
		$this->setIntegration( $integration );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$bots        = $this->collect_bot_options();
		$webhook_uri = home_url( '/wp-json/bizcity-zalo-bot/v1/webhook' );

		$guide_html  = '<div style="line-height:1.7;font-size:13px">';
		$guide_html .= '<p><strong>📘 Hướng dẫn kết nối Zalo Official Account (OA)</strong></p>';
		$guide_html .= '<ol style="padding-left:20px;margin:6px 0">';
		$guide_html .= '<li><strong>Bước 1.</strong> Truy cập <code>https://oa.zalo.me/manage/oa</code> → đăng nhập bằng tài khoản Zalo cá nhân → Tạo OA mới (Doanh nghiệp / Cá nhân) hoặc chọn OA có sẵn. Xác minh OA để được tăng giới hạn gửi tin.</li>';
		$guide_html .= '<li><strong>Bước 2.</strong> Vào <code>https://developers.zalo.me/createapp</code> → <em>Create Application</em> → liên kết với OA vừa tạo.</li>';
		$guide_html .= '<li><strong>Bước 3.</strong> Trong app vừa tạo, tab <em>Settings → Login</em>: lấy <strong>App ID</strong> và <strong>Secret Key</strong>. Thêm <strong>Callback URL</strong> = URL ở ô “Webhook URL” bên dưới vào danh sách OAuth redirect.</li>';
		$guide_html .= '<li><strong>Bước 4.</strong> Tab <em>Official Account → Permission</em>: cấp các quyền tối thiểu — <code>send_message</code>, <code>get_profile</code>, <code>upload_file</code>, <code>manage_oa</code>. Bấm <em>Authorize</em> để OA admin xác nhận.</li>';
		$guide_html .= '<li><strong>Bước 5.</strong> Lấy <strong>OA Access Token</strong>: vào <code>https://developers.zalo.me/tools/explorer/</code> → chọn app → bấm <em>Get Access Token</em>. Token sống ~90 phút, refresh token sống ~1 năm — hệ thống tự refresh nếu bạn lưu cả refresh token.</li>';
		$guide_html .= '<li><strong>Bước 6.</strong> Vào WP Admin → <em>Zalo Bot → Bots</em> → <em>Add new bot</em> → dán <strong>OA ID</strong>, <strong>Access Token</strong>, <strong>Refresh Token</strong>, <strong>App Secret</strong>. Sau khi lưu, bot sẽ xuất hiện ở dropdown “Default Zalo Bot” bên dưới.</li>';
		$guide_html .= '<li><strong>Bước 7.</strong> Vào tab <em>Webhook</em> trong Zalo Developer Console: paste “Webhook URL” bên dưới + chọn các events (<code>user_send_text</code>, <code>user_send_image</code>, <code>follow</code>, <code>unfollow</code>) → Save.</li>';
		$guide_html .= '</ol>';
		$guide_html .= '<p style="margin-top:8px;color:#666"><em>Lưu ý:</em> tin nhắn chủ động ngoài khung 24h cần dùng <strong>Consultation Tag</strong> hoặc <strong>Transaction Tag</strong> (Zalo OA policy 2024+). Hệ thống đã hỗ trợ tag trong action <code>send_zalo_text</code>.</p>';
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
				'plh'     => __( 'Internal label for this Zalo account', 'ai-copilot-content-generator' ),
				'default' => '',
				'desc'    => __( 'Tên gợi nhớ — chỉ hiển thị nội bộ.', 'ai-copilot-content-generator' ),
			),
			'_webhook_uri' => array(
				'type'     => 'input',
				'label'    => __( 'Webhook URL (paste vào Zalo Developer Console)', 'ai-copilot-content-generator' ),
				'default'  => $webhook_uri,
				'readonly' => true,
				'desc'     => __( 'Copy URL này dán vào Zalo Developer → app → Webhook → Callback URL.', 'ai-copilot-content-generator' ),
			),
			'default_bot_id' => array(
				'type'    => 'select',
				'label'   => __( 'Default Zalo Bot', 'ai-copilot-content-generator' ),
				'options' => $bots,
				'default' => '',
				'desc'    => __( 'Bot mặc định cho actions Zalo khi không chỉ định bot_id rõ ràng. Thêm bot mới ở menu Zalo Bot → Bots.', 'ai-copilot-content-generator' ),
			),
		);
	}

	private function collect_bot_options() {
		$out = array( '' => __( '— None / use first available —', 'ai-copilot-content-generator' ) );
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			return $out;
		}
		$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
		if ( empty( $bots ) ) {
			return $out;
		}
		foreach ( $bots as $bot ) {
			$out[ (string) $bot->id ] = sprintf(
				'%s (ID: %d)',
				$bot->bot_name ? $bot->bot_name : 'Bot #' . $bot->id,
				$bot->id
			);
		}
		return $out;
	}

	public function doTest( $need = false ) {
		$params = $this->getParams();
		if ( ! $need && ! empty( $params['_status'] ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$this->addParam( '_status', 7 );
			$this->addParam( '_status_error', 'plugin bizcity-zalo-bot (twin-ai child) is not loaded' );
			return false;
		}
		$bots = BizCity_Zalo_Bot_Database::instance()->get_active_bots();
		if ( empty( $bots ) ) {
			$this->addParam( '_status', 7 );
			$this->addParam( '_status_error', 'No active Zalo Bots found in wp_bizcity_zalo_bots' );
			return false;
		}
		$this->addParam( '_status', 1 );
		$this->addParam( '_status_error', '' );
		return true;
	}

	public function getTriggerBlocks() {
		return array(
			array( 'code' => 'wu_zalobot_message_received' ),
			array( 'code' => 'wu_zalobot_text_received' ),
			array( 'code' => 'wu_zalobot_image_received' ),
		);
	}

	public function getActionBlocks() {
		return array(
			array( 'code' => 'wp_send_zalo_bot_text' ),
			array( 'code' => 'wp_send_zalo_bot_photo' ),
		);
	}
}
