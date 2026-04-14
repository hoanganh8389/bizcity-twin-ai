<?php
/**
 * Bizcity Twin AI — WebChat Admin Menu & Settings
 * Menu quản trị & Trang cấu hình WebChat Bot
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_WebChat_Admin_Menu {
	
	private static $instance = null;
	
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function __construct() {
		// Menu registration moved to BizCity_Admin_Menu (centralized).
		
		// AJAX handlers
		add_action( 'wp_ajax_bizcity_webchat_save_settings', array( $this, 'ajax_save_settings' ) );
	}
	
	/**
	 * Add admin menu
	 */
	public function add_menu() {
		$td = 'bizcity-webchat';

		add_menu_page(
			__( 'Bots - Web Chat', $td ),
			__( 'Bots - Web Chat', $td ),
			'manage_options',
			'bizcity-webchat',
			array( $this, 'render_settings_page' ),
			'dashicons-format-chat',
			32
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'WebChat Settings', $td ),
			__( 'Settings', $td ),
			'manage_options',
			'bizcity-webchat',
			array( $this, 'render_settings_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Trigger Guide', $td ),
			__( 'Trigger Guide', $td ),
			'manage_options',
			'bizcity-webchat-trigger-guide',
			array( $this, 'render_trigger_guide_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Shortcode Guide', $td ),
			__( 'Shortcode Guide', $td ),
			'manage_options',
			'bizcity-webchat-shortcode-guide',
			array( $this, 'render_shortcode_guide_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Chat Logs', $td ),
			__( 'Logs', $td ),
			'manage_options',
			'bizcity-webchat-logs',
			array( $this, 'render_logs_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Chat Timeline', $td ),
			__( 'Timeline', $td ),
			'manage_options',
			'bizcity-webchat-timeline',
			array( $this, 'render_timeline_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Session Memory', $td ),
			__( 'Memory', $td ),
			'manage_options',
			'bizcity-webchat-memory',
			array( $this, 'render_memory_page' )
		);
		
		add_submenu_page(
			'bizcity-webchat',
			__( 'Widget Appearance', $td ),
			__( 'Appearance', $td ),
			'manage_options',
			'bizcity-webchat-appearance',
			array( $this, 'render_appearance_page' )
		);
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		// Get current settings
		$widget_enabled = get_option( 'bizcity_webchat_widget_enabled', true );
		$bot_name = get_option( 'bizcity_webchat_bot_name', 'BizChat AI' );
		$bot_avatar = get_option( 'bizcity_webchat_bot_avatar', '' );
		$welcome_message = get_option( 'bizcity_webchat_welcome', 'Xin chào! Tôi là trợ lý ảo của BizCity. Tôi có thể giúp gì cho bạn?' );
		$primary_color = get_option( 'bizcity_webchat_primary_color', '#3182f6' );
		$widget_position = get_option( 'bizcity_webchat_widget_position', 'bottom-right' );
		$ai_model = get_option( 'bizcity_webchat_ai_model', 'gpt-4o-mini' );
		?>
		<style>
			.bizcity-webchat-admin-wrap {
				max-width: 1200px;
				margin-top: 20px;
			}
			.bizcity-webchat-card {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.05);
			}
			.bizcity-webchat-card h2 {
				margin-top: 0;
				padding-bottom: 12px;
				border-bottom: 1px solid #eee;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.bizcity-webchat-card h2 .dashicons {
				color: #3182f6;
			}
			.bizcity-form-row {
				margin-bottom: 16px;
			}
			.bizcity-form-row label {
				display: block;
				font-weight: 600;
				margin-bottom: 6px;
			}
			.bizcity-form-row input[type="text"],
			.bizcity-form-row input[type="url"],
			.bizcity-form-row textarea,
			.bizcity-form-row select {
				width: 100%;
				max-width: 500px;
				padding: 8px 12px;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			.bizcity-form-row textarea {
				min-height: 100px;
			}
			.bizcity-form-row .description {
				color: #666;
				font-size: 13px;
				margin-top: 4px;
			}
			.bizcity-form-row input[type="color"] {
				width: 50px;
				height: 34px;
				padding: 2px;
				border: 1px solid #ddd;
				border-radius: 4px;
				cursor: pointer;
			}
			.bizcity-switch {
				position: relative;
				display: inline-block;
				width: 50px;
				height: 26px;
			}
			.bizcity-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}
			.bizcity-switch .slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .3s;
				border-radius: 26px;
			}
			.bizcity-switch .slider:before {
				position: absolute;
				content: "";
				height: 20px;
				width: 20px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .3s;
				border-radius: 50%;
			}
			.bizcity-switch input:checked + .slider {
				background-color: #3182f6;
			}
			.bizcity-switch input:checked + .slider:before {
				transform: translateX(24px);
			}
			.bizcity-btn-primary {
				background: linear-gradient(135deg, #3182f6 0%, #1d6ae5 100%);
				color: #fff;
				border: none;
				padding: 10px 24px;
				border-radius: 6px;
				font-size: 14px;
				font-weight: 600;
				cursor: pointer;
				transition: all 0.2s;
			}
			.bizcity-btn-primary:hover {
				transform: translateY(-1px);
				box-shadow: 0 4px 12px rgba(49, 130, 246, 0.3);
			}
			.bizcity-info-box {
				background: #e7f5ff;
				border-left: 4px solid #3182f6;
				padding: 15px 20px;
				margin: 20px 0;
				border-radius: 0 8px 8px 0;
			}
			.bizcity-info-box p {
				margin: 0;
			}
			.bizcity-settings-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
				gap: 20px;
			}
			.bizcity-webhook-url {
				background: #f5f5f5;
				padding: 12px 16px;
				border-radius: 6px;
				font-family: monospace;
				font-size: 14px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 10px;
			}
			.bizcity-webhook-url code {
				flex: 1;
				word-break: break-all;
			}
			.bizcity-copy-btn {
				background: #3182f6;
				color: #fff;
				border: none;
				padding: 6px 12px;
				border-radius: 4px;
				cursor: pointer;
				font-size: 12px;
			}
			.bizcity-copy-btn:hover {
				background: #1d6ae5;
			}
		</style>
		
		<div class="wrap bizcity-webchat-admin-wrap">
			<h1>
				<span class="dashicons dashicons-format-chat" style="margin-right: 8px; color: #3182f6;"></span>
				Bots - Web Chat — Cấu hình / Configuration
			</h1>
			
			<div class="bizcity-info-box">
				<p><strong>💡 Mẹo / Tip:</strong> Web Chat Bot tích hợp với <strong>bizcity-workflow (WAIC)</strong> để trigger workflows từ tin nhắn web chat. Xem thêm tại <a href="<?php echo admin_url( 'admin.php?page=bizcity-webchat-trigger-guide' ); ?>">Hướng dẫn Trigger / Trigger Guide</a>.</p>
			</div>
			
			<form id="bizcity-webchat-settings-form">
				<?php wp_nonce_field( 'bizcity_webchat_settings', 'bizcity_webchat_nonce' ); ?>
				
				<div class="bizcity-settings-grid">
					<!-- Widget Settings -->
					<div class="bizcity-webchat-card">
						<h2><span class="dashicons dashicons-admin-appearance"></span> Cấu hình Widget / Widget Settings</h2>
						
						<div class="bizcity-form-row">
							<label>Bật/Tắt Widget / Enable/Disable Widget</label>
							<label class="bizcity-switch">
								<input type="checkbox" name="widget_enabled" value="1" <?php checked( $widget_enabled ); ?>>
								<span class="slider"></span>
							</label>
							<p class="description">Hiển thị chat widget trên frontend / Show chat widget on frontend</p>
						</div>
						
						<div class="bizcity-form-row">
							<label>Tên Bot / Bot Name</label>
							<input type="text" name="bot_name" value="<?php echo esc_attr( $bot_name ); ?>">
						</div>
						
						<div class="bizcity-form-row">
							<label>Avatar Bot (URL)</label>
							<input type="url" name="bot_avatar" value="<?php echo esc_url( $bot_avatar ); ?>" placeholder="https://example.com/avatar.png">
							<p class="description">Để trống sẽ dùng avatar mặc định / Leave empty for default avatar</p>
						</div>
						
						<div class="bizcity-form-row">
							<label>Tin nhắn chào mừng / Welcome Message</label>
							<textarea name="welcome_message"><?php echo esc_textarea( $welcome_message ); ?></textarea>
						</div>
						
						<div class="bizcity-form-row">
							<label>Màu chính / Primary Color</label>
							<input type="color" name="primary_color" value="<?php echo esc_attr( $primary_color ); ?>">
						</div>
						
						<div class="bizcity-form-row">
							<label>Vị trí Widget / Widget Position</label>
							<select name="widget_position">
								<option value="bottom-right" <?php selected( $widget_position, 'bottom-right' ); ?>>Góc dưới phải / Bottom Right</option>
								<option value="bottom-left" <?php selected( $widget_position, 'bottom-left' ); ?>>Góc dưới trái / Bottom Left</option>
							</select>
						</div>
					</div>
					
					<!-- AI Settings -->
					<div class="bizcity-webchat-card">
						<h2><span class="dashicons dashicons-lightbulb"></span> Cấu hình AI / AI Settings</h2>
						
						<?php
						// Get characters from bizcity-knowledge
						global $wpdb;
					$characters_table = $wpdb->prefix . 'bizcity_characters';
					$characters = [];
					
					if ($wpdb->get_var("SHOW TABLES LIKE '$characters_table'") === $characters_table) {
						$characters = $wpdb->get_results("SELECT id, name FROM $characters_table WHERE status = 'active' ORDER BY name ASC");
					}
					
					$default_character_id = get_option('bizcity_webchat_default_character_id', 0);
					?>
					
					<div class="bizcity-form-row">
						<label>Character mặc định / Default Character</label>
						<select name="default_character_id">
							<option value="0" <?php selected($default_character_id, 0); ?>>Không dùng character / No character (Legacy FAQ)</option>
							<?php foreach ($characters as $char): ?>
								<option value="<?php echo esc_attr($char->id); ?>" <?php selected($default_character_id, $char->id); ?>>
									<?php echo esc_html($char->name); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							Chọn character để chatbot sử dụng knowledge của character đó.<br>
							Select a character for the chatbot to use its knowledge base.<br>
							Nếu không chọn, hệ thống sẽ tìm trong quick_faq posts (legacy mode).
						</p>
					</div>
						
						<div class="bizcity-form-row">
							<label>Model AI</label>
							<select name="ai_model">
								<option value="gpt-4o-mini" <?php selected( $ai_model, 'gpt-4o-mini' ); ?>>GPT-4o Mini (Nhanh, tiết kiệm / Fast, cost-effective)</option>
								<option value="gpt-4o" <?php selected( $ai_model, 'gpt-4o' ); ?>>GPT-4o (Mạnh mẽ / Powerful)</option>
								<option value="gpt-4-turbo" <?php selected( $ai_model, 'gpt-4-turbo' ); ?>>GPT-4 Turbo</option>
								<option value="gpt-3.5-turbo" <?php selected( $ai_model, 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo</option>
							</select>
							<p class="description">Model AI sử dụng cho chat / AI model for chat responses</p>
						</div>
						
						<div class="bizcity-form-row">
							<label>OpenAI API Key (tùy chọn / optional)</label>
							<input type="password" name="openai_api_key" value="<?php echo esc_attr( get_option( 'bizcity_webchat_openai_api_key', '' ) ); ?>" placeholder="sk-...">
							<p class="description">
								Chỉ điền nếu muốn dùng API key riêng cho WebChat.<br>
								Only fill in if you want to use a separate API key for WebChat.<br>
								Còn không hệ thống sẽ dùng AI mặc định của BizCity.
							</p>
						</div>
					</div>
				</div>
				
				<!-- Webhook Info -->
				<div class="bizcity-webchat-card">
					<h2><span class="dashicons dashicons-rest-api"></span> Webhook & API</h2>
					
					<div class="bizcity-form-row">
						<label>Webhook URL</label>
						<div class="bizcity-webhook-url">
							<code id="webhook-url"><?php echo esc_url( home_url( '/webchat-hook/' ) ); ?></code>
							<button type="button" class="bizcity-copy-btn" onclick="copyWebhookUrl()">📋 Copy</button>
						</div>
						<p class="description">Sử dụng URL này để gửi webhook từ dịch vụ bên ngoài / Use this URL to send webhooks from external services</p>
					</div>
					
					<div class="bizcity-form-row">
						<label>AJAX Endpoint</label>
						<div class="bizcity-webhook-url">
							<code><?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=bizcity_webchat_send</code>
						</div>
					</div>
					
					<div class="bizcity-form-row">
						<label>Shortcodes</label>
						<div style="display: flex; flex-direction: column; gap: 8px;">
							<div class="bizcity-webhook-url">
								<code>[chatbot character_id="5" height="600px"]</code>
							</div>
							<div class="bizcity-webhook-url">
								<code>[bizcity_chat style="embedded" width="100%"]</code>
							</div>
							<div class="bizcity-webhook-url">
								<code>[webchat style="floating" position="bottom-right"]</code>
							</div>
							<div class="bizcity-webhook-url">
								<code>[bizcity_webchat style="embed" height="500px"]</code>
							</div>
							<div class="bizcity-webhook-url">
								<code>[bizcity_webchat_timeline session_id="xxx"]</code>
							</div>
						</div>
						<p class="description">
							💡 <strong>Tính năng mới / New Feature:</strong> [chatbot] hỗ trợ upload ảnh để tìm kiếm trong knowledge base (image embeddings)
							/ Supports image upload for knowledge base search
						</p>
					</div>
				</div>
				
				<p>
					<button type="submit" class="bizcity-btn-primary">
						<span class="dashicons dashicons-saved" style="margin-right: 4px;"></span>
						Lưu cấu hình / Save Settings
					</button>
				</p>
			</form>
		</div>
		
		<script>
		function copyWebhookUrl() {
			var url = document.getElementById('webhook-url').textContent;
			navigator.clipboard.writeText(url).then(function() {
				alert('Đã copy URL! / URL copied!');
			});
		}
		
		jQuery(document).ready(function($) {
			$('#bizcity-webchat-settings-form').on('submit', function(e) {
				e.preventDefault();
				
				var formData = $(this).serialize();
				formData += '&action=bizcity_webchat_save_settings';
				
				$.post(ajaxurl, formData, function(response) {
					if (response.success) {
						alert('Đã lưu cấu hình thành công! / Settings saved successfully!');
					} else {
						alert('Có lỗi xảy ra / Error: ' + (response.data.message || 'Unknown error'));
					}
				});
			});
		});
		</script>
		<?php
	}
	
	/**
	 * Render trigger guide page
	 */
	public function render_trigger_guide_page() {
		?>
		<style>
			.bizcity-guide-wrap {
				max-width: 1000px;
				margin-top: 20px;
			}
			.bizcity-guide-card {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 2px 8px rgba(0,0,0,0.05);
			}
			.bizcity-guide-card h2 {
				margin-top: 0;
				color: #333;
				border-bottom: 2px solid #3182f6;
				padding-bottom: 10px;
			}
			.bizcity-guide-card h3 {
				color: #3182f6;
				margin-top: 20px;
			}
			.bizcity-code-block {
				background: #1e1e1e;
				color: #d4d4d4;
				padding: 16px 20px;
				border-radius: 8px;
				font-family: 'Consolas', 'Monaco', monospace;
				font-size: 13px;
				overflow-x: auto;
				margin: 12px 0;
			}
			.bizcity-code-block .comment { color: #6a9955; }
			.bizcity-code-block .keyword { color: #569cd6; }
			.bizcity-code-block .string { color: #ce9178; }
			.bizcity-code-block .function { color: #dcdcaa; }
			.bizcity-code-block .variable { color: #9cdcfe; }
			.bizcity-table {
				width: 100%;
				border-collapse: collapse;
				margin: 16px 0;
			}
			.bizcity-table th,
			.bizcity-table td {
				border: 1px solid #ddd;
				padding: 10px 12px;
				text-align: left;
			}
			.bizcity-table th {
				background: #f5f7fa;
				font-weight: 600;
			}
			.bizcity-table code {
				background: #f0f0f0;
				padding: 2px 6px;
				border-radius: 4px;
				font-size: 12px;
			}
			.bizcity-info-banner {
				background: linear-gradient(135deg, #3182f6 0%, #1d6ae5 100%);
				color: #fff;
				padding: 20px 24px;
				border-radius: 8px;
				margin-bottom: 20px;
			}
			.bizcity-info-banner h1 {
				margin: 0 0 8px 0;
				font-size: 24px;
			}
			.bizcity-info-banner p {
				margin: 0;
				opacity: 0.9;
			}
			.bizcity-step-list {
				counter-reset: step;
				list-style: none;
				padding: 0;
			}
			.bizcity-step-list li {
				position: relative;
				padding-left: 50px;
				margin-bottom: 16px;
			}
			.bizcity-step-list li::before {
				counter-increment: step;
				content: counter(step);
				position: absolute;
				left: 0;
				top: 0;
				width: 32px;
				height: 32px;
				background: #3182f6;
				color: #fff;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				font-weight: bold;
			}
			.bizcity-alert {
				background: #fff3cd;
				border: 1px solid #ffeaa7;
				border-radius: 6px;
				padding: 12px 16px;
				margin: 16px 0;
			}
			.bizcity-alert-info {
				background: #e7f5ff;
				border-color: #3182f6;
			}
			.bizcity-alert-success {
				background: #d4edda;
				border-color: #28a745;
			}
		</style>
		
		<div class="wrap bizcity-guide-wrap">
			<div class="bizcity-info-banner">
				<h1>📚 Hướng dẫn WebChat Trigger / WebChat Trigger Guide</h1>
				<p>Tích hợp Web Chat với bizcity-workflow (WAIC) để tự động hóa quy trình làm việc / Integrate Web Chat with bizcity-workflow (WAIC) for workflow automation</p>
			</div>
			
			<!-- Overview -->
			<div class="bizcity-guide-card">
				<h2>🎯 Tổng quan / Overview</h2>
				<p>WebChat Bot cho phép trigger workflows từ tin nhắn chat trên website. Khi khách hàng gửi tin nhắn, hệ thống sẽ:<br>
				WebChat Bot lets you trigger workflows from chat messages on your website. When a customer sends a message, the system will:</p>
				<ol class="bizcity-step-list">
					<li>Nhận và xử lý tin nhắn / Receive and process message</li>
					<li>Log vào database để tracking / Log to database for tracking</li>
					<li>Fire WAIC workflow triggers</li>
					<li>Trả về response từ AI hoặc custom flows / Return AI or custom flow response</li>
					<li>Gửi thông báo đến admin / Notify admin (Zalo BizCity or Zalo Bots)</li>
				</ol>
			</div>
			
			<!-- Webhook Format -->
			<div class="bizcity-guide-card">
				<h2>📨 Webhook Format</h2>
				<p>Gửi POST request đến / Send POST request to <code><?php echo esc_url( home_url( '/webchat-hook/' ) ); ?></code></p>
				
				<h3>Request Body (JSON)</h3>
				<div class="bizcity-code-block">
<pre>{
    <span class="string">"platform_type"</span>: <span class="string">"WEBCHAT"</span>,
    <span class="string">"event"</span>: <span class="string">"message.create"</span>,
    <span class="string">"session_id"</span>: <span class="string">"unique-session-id"</span>,
    <span class="string">"user_id"</span>: <span class="variable">0</span>,
    <span class="string">"client_name"</span>: <span class="string">"Guest"</span>,
    <span class="string">"message"</span>: {
        <span class="string">"message_id"</span>: <span class="string">"wcm_123456"</span>,
        <span class="string">"text"</span>: <span class="string">"Nội dung tin nhắn"</span>,
        <span class="string">"attachments"</span>: [
            {
                <span class="string">"type"</span>: <span class="string">"image"</span>,
                <span class="string">"url"</span>: <span class="string">"https://example.com/image.jpg"</span>
            }
        ]
    }
}</pre>
				</div>
				
				<h3>Response</h3>
				<div class="bizcity-code-block">
<pre>{
    <span class="string">"ok"</span>: <span class="keyword">true</span>,
    <span class="string">"result"</span>: {
        <span class="string">"status"</span>: <span class="string">"success"</span>,
        <span class="string">"replies"</span>: [<span class="string">"Bot response message"</span>],
        <span class="string">"conversation_id"</span>: <span class="variable">123</span>,
        <span class="string">"task_id"</span>: <span class="string">"task_abc123"</span>
    }
}</pre>
				</div>
			</div>
			
			<!-- Trigger Hooks -->
			<div class="bizcity-guide-card">
				<h2>🔗 Trigger Hooks (WordPress Actions)</h2>
				<p>Các hooks được fire khi nhận tin nhắn từ webchat / Hooks fired when receiving webchat messages:</p>
				
				<table class="bizcity-table">
					<thead>
						<tr>
							<th>Action Hook</th>
							<th>Mô tả / Description</th>
							<th>Parameters</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>waic_twf_process_flow</code></td>
							<td>Hook chung cho WAIC workflow / Main WAIC workflow hook</td>
							<td><code>$twf_trigger, $raw_data</code></td>
						</tr>
						<tr>
							<td><code>waic_twf_process_flow_webchat</code></td>
							<td>Hook riêng cho webchat / Webchat-specific hook</td>
							<td><code>$twf_trigger, $raw_data</code></td>
						</tr>
						<tr>
							<td><code>bizcity_webchat_message_received</code></td>
							<td>Khi nhận tin nhắn mới / When new message received</td>
							<td><code>$twf_trigger, $raw_data</code></td>
						</tr>
						<tr>
							<td><code>bizcity_webchat_image_received</code></td>
							<td>Khi nhận attachment image / When image attachment received</td>
							<td><code>$twf_trigger, $raw_data</code></td>
						</tr>
						<tr>
							<td><code>bizcity_webchat_push_message</code></td>
							<td>Khi bot gửi tin nhắn / When bot sends message</td>
							<td><code>$session_id, $message, $options</code></td>
						</tr>
					</tbody>
				</table>
				
				<h3>Ví dụ: Bắt hook trong plugin khác / Example: Catch hook in another plugin</h3>
				<div class="bizcity-code-block">
<pre><span class="comment">// Bắt tin nhắn từ webchat</span>
<span class="function">add_action</span>(<span class="string">'bizcity_webchat_message_received'</span>, <span class="keyword">function</span>(<span class="variable">$trigger</span>, <span class="variable">$raw</span>) {
    <span class="variable">$message</span> = <span class="variable">$trigger</span>[<span class="string">'text'</span>];
    <span class="variable">$session_id</span> = <span class="variable">$trigger</span>[<span class="string">'session_id'</span>];
    
    <span class="comment">// Xử lý logic của bạn</span>
    <span class="keyword">if</span> (<span class="function">str_contains</span>(<span class="variable">$message</span>, <span class="string">'đặt hàng'</span>)) {
        <span class="comment">// Trigger đặt hàng workflow</span>
        <span class="function">do_action</span>(<span class="string">'my_order_workflow'</span>, <span class="variable">$trigger</span>);
    }
}, <span class="variable">10</span>, <span class="variable">2</span>);</pre>
				</div>
			</div>
			
			<!-- Trigger Payload -->
			<div class="bizcity-guide-card">
				<h2>📦 Trigger Payload ($twf_trigger)</h2>
				<p>Cấu trúc dữ liệu trigger / Trigger data structure:</p>
				
				<table class="bizcity-table">
					<thead>
						<tr>
							<th>Key</th>
							<th>Type</th>
							<th>Mô tả / Description</th>
						</tr>
					</thead>
					<tbody>
						<tr><td><code>platform</code></td><td>string</td><td>Luôn là / Always "webchat"</td></tr>
						<tr><td><code>client_id</code></td><td>string</td><td>Session ID của client / Client session ID</td></tr>
						<tr><td><code>chat_id</code></td><td>string</td><td>ID cuộc hội thoại / Conversation ID (= session_id)</td></tr>
						<tr><td><code>user_id</code></td><td>int</td><td>WordPress user ID (0 nếu guest / if guest)</td></tr>
						<tr><td><code>text</code></td><td>string</td><td>Nội dung tin nhắn / Message content</td></tr>
						<tr><td><code>raw</code></td><td>array</td><td>Dữ liệu gốc / Raw webhook data</td></tr>
						<tr><td><code>attachment_url</code></td><td>string</td><td>URL file đính kèm / Attachment URL</td></tr>
						<tr><td><code>attachment_type</code></td><td>string</td><td>image|audio|video|document</td></tr>
						<tr><td><code>image_url</code></td><td>string</td><td>URL nếu là hình ảnh / Image URL</td></tr>
						<tr><td><code>audio_url</code></td><td>string</td><td>URL nếu là audio / Audio URL</td></tr>
						<tr><td><code>task_id</code></td><td>string</td><td>ID của task / Task ID (for timeline)</td></tr>
						<tr><td><code>message_id</code></td><td>string</td><td>ID tin nhắn duy nhất / Unique message ID</td></tr>
					</tbody>
				</table>
			</div>
			
			<!-- PHP Functions -->
			<div class="bizcity-guide-card">
				<h2>🛠️ PHP Functions</h2>
				
				<h3>Gửi tin nhắn từ bot / Send message from bot</h3>
				<div class="bizcity-code-block">
<pre><span class="comment">// Gửi tin nhắn đến session</span>
<span class="function">bizcity_webchat_send_bot_message</span>(<span class="variable">$session_id</span>, <span class="string">'Xin chào!'</span>);

<span class="comment">// Hoặc sử dụng trigger instance</span>
<span class="variable">$trigger</span> = <span class="function">BizCity_WebChat_Trigger::instance</span>();
<span class="variable">$trigger</span>-><span class="function">send_message</span>(<span class="variable">$session_id</span>, <span class="string">'Hello!'</span>, [
    <span class="string">'attachments'</span> => []
]);</pre>
				</div>
				
				<h3>Timeline (Relevance AI style)</h3>
				<div class="bizcity-code-block">
<pre><span class="comment">// Bắt đầu task mới</span>
<span class="variable">$task_id</span> = <span class="function">bizcity_webchat_start_task</span>([
    <span class="string">'session_id'</span> => <span class="variable">$session_id</span>,
    <span class="string">'task_name'</span> => <span class="string">'Xử lý đơn hàng'</span>,
    <span class="string">'triggered_by'</span> => <span class="string">'User message'</span>,
]);

<span class="comment">// Thêm bước vào timeline</span>
<span class="function">bizcity_webchat_add_task_step</span>(<span class="variable">$task_id</span>, [
    <span class="string">'type'</span> => <span class="string">'action'</span>,
    <span class="string">'name'</span> => <span class="string">'Đang tìm kiếm sản phẩm...'</span>,
    <span class="string">'status'</span> => <span class="string">'running'</span>,
]);

<span class="comment">// Hoàn thành task</span>
<span class="function">bizcity_webchat_complete_task</span>(<span class="variable">$task_id</span>, [
    <span class="string">'status'</span> => <span class="string">'completed'</span>,
    <span class="string">'response'</span> => <span class="string">'Đã tìm thấy 5 sản phẩm!'</span>,
    <span class="string">'actions'</span> => <span class="variable">3</span>,
    <span class="string">'credits'</span> => <span class="variable">1.5</span>,
]);</pre>
				</div>
			</div>
			
			<!-- Human-in-the-Loop -->
			<div class="bizcity-guide-card">
				<h2>👤 Human-in-the-Loop (HIL)</h2>
				<p>WebChat Bot hỗ trợ Human-in-the-Loop khi cần xác nhận từ người dùng / Supports HIL when user confirmation is needed:</p>
				
				<div class="bizcity-alert bizcity-alert-info">
					<strong>ℹ️ Lưu ý / Note:</strong> HIL được xử lý thông qua WAIC workflow. Khi workflow yêu cầu xác nhận, bot sẽ gửi tin nhắn và chờ phản hồi từ user.
					HIL is handled via WAIC workflow. When confirmation is required, bot sends a message and waits for user response.
				</div>
				
				<div class="bizcity-code-block">
<pre><span class="comment">// HIL được tự động check qua hàm waic_hil_maybe_handle_incoming()</span>
<span class="comment">// Nếu có HIL pending, tin nhắn sẽ được chuyển để xử lý HIL</span>
<span class="comment">// thay vì trigger workflow mới</span></pre>
				</div>
			</div>
			
			<!-- Database Tables 
			<div class="bizcity-guide-card">
				<h2>🗃️ Database Tables</h2>
				
				<table class="bizcity-table">
					<thead>
						<tr>
							<th>Table</th>
							<th>Mô tả</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code><?php echo esc_html( $GLOBALS['wpdb']->prefix ); ?>bizcity_webchat_conversations</code></td>
							<td>Lưu thông tin conversations</td>
						</tr>
						<tr>
							<td><code><?php echo esc_html( $GLOBALS['wpdb']->prefix ); ?>bizcity_webchat_messages</code></td>
							<td>Lưu tất cả messages</td>
						</tr>
						<tr>
							<td><code><?php echo esc_html( $GLOBALS['wpdb']->prefix ); ?>bizcity_webchat_tasks</code></td>
							<td>Lưu tasks (cho timeline)</td>
						</tr>
						<tr>
							<td><code><?php echo esc_html( $GLOBALS['wpdb']->prefix ); ?>bizcity_webchat_task_steps</code></td>
							<td>Lưu các bước của task</td>
						</tr>
					</tbody>
				</table>
			</div>-->
			
			<!-- Tích hợp với các plugin khác -->
			<div class="bizcity-guide-card">
				<h2>🔌 Tích hợp với các plugin khác / Integration with Other Plugins</h2>
				
				<h3>bizgpt-agent</h3>
				<p>WebChat Bot tự động sử dụng các hàm từ bizgpt-agent nếu có / Auto-uses functions from bizgpt-agent if available:</p>
				<ul>
					<li><code>bizgpt_chatbot_run_admin_flows()</code> - Chạy flows cho admin / Run admin flows</li>
					<li><code>bizgpt_chatbot_run_guest_flows()</code> - Chạy flows cho guest / Run guest flows</li>
					<li><code>bizgpt_match_custom_flow()</code> - Match custom flows</li>
					<li><code>bizgpt_flow_smart_find_post()</code> - Tìm bài viết thông minh / Smart post search</li>
				</ul>
				
				<h3>bizcity-workflow (WAIC)</h3>
				<p>Tích hợp với WAIC để trigger workflows / Integrate with WAIC to trigger workflows:</p>
				<ul>
					<li>Fire <code>waic_twf_process_flow</code> hook</li>
					<li>Hỗ trợ / Supports <code>waic_hil_maybe_handle_incoming()</code> cho HIL</li>
					<li>Sử dụng / Uses <code>bizcity_aiwu_fire_twf_process_flow()</code></li>
				</ul>
				
				<h3>Zalo BizCity / Zalo Bots Notification</h3>
				<p>Tự động gửi thông báo đến admin qua Zalo khi có tin nhắn mới / Auto-notify admin via Zalo when new messages arrive (if configured).</p>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render logs page
	 */
	public function render_logs_page() {
		$log_file = BIZCITY_WEBCHAT_LOGS . 'webhook-raw.log';
		$log_content = '';
		
		if ( file_exists( $log_file ) ) {
			$log_content = file_get_contents( $log_file );
			// Lấy 50 entries cuối
			$lines = explode( "\n\n", $log_content );
			$lines = array_slice( $lines, -50 );
			$log_content = implode( "\n\n", $lines );
		}
		
		?>
		<style>
			.bizcity-logs-wrap {
				max-width: 1200px;
				margin-top: 20px;
			}
			.bizcity-logs-card {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
			}
			.bizcity-logs-content {
				background: #1e1e1e;
				color: #d4d4d4;
				padding: 20px;
				border-radius: 8px;
				font-family: 'Consolas', 'Monaco', monospace;
				font-size: 12px;
				max-height: 600px;
				overflow: auto;
				white-space: pre-wrap;
				word-break: break-all;
			}
			.bizcity-logs-actions {
				margin-bottom: 20px;
				display: flex;
				gap: 10px;
			}
			.bizcity-logs-actions button {
				padding: 8px 16px;
				border-radius: 4px;
				cursor: pointer;
				font-size: 13px;
			}
			.bizcity-btn-danger {
				background: #dc3545;
				color: #fff;
				border: none;
			}
			.bizcity-btn-secondary {
				background: #6c757d;
				color: #fff;
				border: none;
			}
		</style>
		
		<div class="wrap bizcity-logs-wrap">
			<h1>📋 Nhật ký WebChat / WebChat Logs</h1>
			
			<div class="bizcity-logs-card">
				<h2>Webhook Logs</h2>
				<div class="bizcity-logs-actions">
					<button type="button" class="bizcity-btn-secondary" onclick="location.reload()">🔄 Refresh</button>
					<button type="button" class="bizcity-btn-danger" onclick="clearLogs()">🗑️ Xóa logs / Clear logs</button>
				</div>
				
				<?php if ( empty( trim( $log_content ) ) ) : ?>
					<p>Chưa có log nào. / No logs yet.</p>
				<?php else : ?>
					<div class="bizcity-logs-content"><?php echo esc_html( $log_content ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		
		<script>
		function clearLogs() {
			if (!confirm('Bạn có chắc muốn xóa tất cả logs? / Are you sure you want to clear all logs?')) return;
			// TODO: Implement clear logs AJAX
			alert('Tính năng đang phát triển / Feature in development');
		}
		</script>
		<?php
	}
	
	/**
	 * Render timeline page
	 */
	public function render_timeline_page() {
		$db = BizCity_WebChat_Database::instance();
		$recent_tasks = $db->get_recent_tasks( 20 );
		?>
		<style>
			.bizcity-timeline-wrap {
				max-width: 1200px;
				margin-top: 20px;
			}
			.bizcity-timeline-card {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
			}
			.bizcity-task-list {
				list-style: none;
				padding: 0;
			}
			.bizcity-task-item {
				border-bottom: 1px solid #eee;
				padding: 16px 0;
			}
			.bizcity-task-item:last-child {
				border-bottom: none;
			}
			.bizcity-task-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 8px;
			}
			.bizcity-task-name {
				font-weight: 600;
				font-size: 15px;
			}
			.bizcity-task-status {
				padding: 4px 10px;
				border-radius: 12px;
				font-size: 11px;
				font-weight: bold;
			}
			.bizcity-task-status-completed {
				background: #d4edda;
				color: #155724;
			}
			.bizcity-task-status-running {
				background: #fff3cd;
				color: #856404;
			}
			.bizcity-task-status-failed {
				background: #f8d7da;
				color: #721c24;
			}
			.bizcity-task-meta {
				font-size: 13px;
				color: #666;
			}
		</style>
		
		<div class="wrap bizcity-timeline-wrap">
			<h1>⏱️ Timeline Tasks</h1>
			
			<div class="bizcity-timeline-card">
				<h2>Recent Tasks</h2>
				
				<?php if ( empty( $recent_tasks ) ) : ?>
					<p>Chưa có task nào. / No tasks yet.</p>
				<?php else : ?>
					<ul class="bizcity-task-list">
						<?php foreach ( $recent_tasks as $task ) : 
							$status_class = 'bizcity-task-status-' . ( $task->status ?? 'running' );
						?>
							<li class="bizcity-task-item">
								<div class="bizcity-task-header">
									<span class="bizcity-task-name"><?php echo esc_html( $task->task_name ?? 'Unnamed Task' ); ?></span>
									<span class="bizcity-task-status <?php echo esc_attr( $status_class ); ?>">
										<?php echo esc_html( ucfirst( $task->status ?? 'running' ) ); ?>
									</span>
								</div>
								<div class="bizcity-task-meta">
									<strong>Task ID:</strong> <?php echo esc_html( $task->task_id ?? '' ); ?> |
									<strong>Session:</strong> <?php echo esc_html( $task->session_id ?? '' ); ?> |
									<strong>Time:</strong> <?php echo esc_html( $task->created_at ?? '' ); ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render memory page
	 */
	public function render_memory_page() {
		// Handle build memory action
		if (isset($_POST['build_memory']) && check_admin_referer('bizcity_webchat_memory')) {
			$result = BizCity_WebChat_Memory::build_from_messages([
				'limit' => 500,
			]);
			
			echo '<div class="notice notice-success"><p>';
			echo 'Đã xử lý / Processed: ' . $result['count'] . ' tin nhắn / messages. ';
			echo 'Tạo mới / Created: ' . $result['inserted'] . ' | Cập nhật / Updated: ' . $result['updated'];
			echo '</p></div>';
		}
		
		// Get statistics
		$stats = BizCity_WebChat_Memory::get_stats();
		$memories = BizCity_WebChat_Memory::get_memories(['limit' => 100]);
		
		$type_labels = [
			'identity' => '🆔 Thông tin cá nhân / Identity',
			'preference' => '❤️ Sở thích / Preference',
			'goal' => '🎯 Mục tiêu / Goal',
			'pain' => '😰 Vấn đề/Nỗi đau / Pain',
			'constraint' => '⚠️ Giới hạn / Constraint',
			'habit' => '⏰ Thói quen / Habit',
			'relationship' => '👥 Quan hệ / Relationship',
			'fact' => '📌 Sự kiện / Fact',
		];
		?>
		<style>
			.bizcity-memory-wrap {
				max-width: 1400px;
				margin: 20px 0;
			}
			.bizcity-memory-header {
				background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
				color: white;
				padding: 30px;
				border-radius: 10px;
				margin-bottom: 30px;
			}
			.bizcity-memory-header h1 {
				color: white;
				margin: 0 0 10px 0;
				font-size: 28px;
			}
			.bizcity-memory-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
				gap: 20px;
				margin-bottom: 30px;
			}
			.bizcity-stat-card {
				background: white;
				padding: 20px;
				border-radius: 8px;
				box-shadow: 0 2px 4px rgba(0,0,0,0.1);
			}
			.bizcity-stat-card h3 {
				margin: 0 0 10px 0;
				font-size: 14px;
				color: #666;
				font-weight: normal;
			}
			.bizcity-stat-card .stat-value {
				font-size: 32px;
				font-weight: bold;
				color: #667eea;
			}
			.bizcity-memories-table {
				background: white;
				border-radius: 8px;
				box-shadow: 0 2px 4px rgba(0,0,0,0.1);
				overflow: hidden;
			}
			.bizcity-memories-table table {
				width: 100%;
				border-collapse: collapse;
			}
			.bizcity-memories-table th,
			.bizcity-memories-table td {
				padding: 12px 15px;
				text-align: left;
				border-bottom: 1px solid #eee;
			}
			.bizcity-memories-table th {
				background: #f8f9fa;
				font-weight: 600;
				color: #333;
			}
			.bizcity-memory-type {
				display: inline-block;
				padding: 4px 12px;
				border-radius: 12px;
				font-size: 12px;
				font-weight: 500;
			}
			.type-identity { background: #e3f2fd; color: #1976d2; }
			.type-preference { background: #fce4ec; color: #c2185b; }
			.type-goal { background: #e8f5e9; color: #388e3c; }
			.type-pain { background: #fff3e0; color: #f57c00; }
			.type-constraint { background: #ffebee; color: #d32f2f; }
			.type-habit { background: #f3e5f5; color: #7b1fa2; }
			.type-relationship { background: #e0f2f1; color: #00796b; }
			.type-fact { background: #e8eaf6; color: #3f51b5; }
			.bizcity-score-bar {
				width: 60px;
				height: 8px;
				background: #eee;
				border-radius: 4px;
				overflow: hidden;
			}
			.bizcity-score-fill {
				height: 100%;
				background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
				border-radius: 4px;
			}
			.bizcity-build-btn {
				background: #667eea;
				color: white;
				border: none;
				padding: 12px 24px;
				border-radius: 6px;
				cursor: pointer;
				font-size: 14px;
				font-weight: 500;
			}
			.bizcity-build-btn:hover {
				background: #5568d3;
			}
		</style>
		
		<div class="wrap bizcity-memory-wrap">
			<div class="bizcity-memory-header">
				<h1>🧠 Memory - Ký ức về người dùng / User Memories</h1>
				<p>Hệ thống tự động phân tích và ghi nhớ thói quen, sở thích, nỗi đau của người dùng qua AI / Auto-analyze and remember user habits, preferences, and pain points via AI</p>
			</div>
			
			<!-- Statistics -->
			<div class="bizcity-memory-stats">
				<div class="bizcity-stat-card">
					<h3>Tổng số Memories / Total Memories</h3>
					<div class="stat-value"><?php echo number_format($stats['totals']['total'] ?? 0); ?></div>
				</div>
				<div class="bizcity-stat-card">
					<h3>Nỗi đau / Pain</h3>
					<div class="stat-value"><?php echo number_format($stats['totals']['pain_count'] ?? 0); ?></div>
				</div>
				<div class="bizcity-stat-card">
					<h3>Giới hạn / Constraint</h3>
					<div class="stat-value"><?php echo number_format($stats['totals']['constraint_count'] ?? 0); ?></div>
				</div>
				<div class="bizcity-stat-card">
					<h3>Mục tiêu / Goal</h3>
					<div class="stat-value"><?php echo number_format($stats['totals']['goal_count'] ?? 0); ?></div>
				</div>
			</div>
			
			<!-- Build Memory Button -->
			<div style="margin-bottom: 20px;">
				<form method="post">
					<?php wp_nonce_field('bizcity_webchat_memory'); ?>
					<button type="submit" name="build_memory" class="bizcity-build-btn">
						🔄 Xây dựng Memory từ tin nhắn / Build Memory from Messages
					</button>
					<p style="color: #666; font-size: 13px; margin-top: 8px;">
						Hệ thống sẽ phân tích 500 tin nhắn gần nhất và trích xuất memories bằng AI / System will analyze 500 recent messages and extract memories via AI
					</p>
				</form>
			</div>
			
			<!-- Memory Types Distribution -->
			<?php if (!empty($stats['by_type'])): ?>
			<div class="bizcity-memories-table" style="margin-bottom: 30px;">
				<table>
					<thead>
						<tr>
							<th>Loại Memory / Memory Type</th>
							<th>Số lượng / Count</th>
							<th>Điểm TB / Avg Score</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($stats['by_type'] as $type_stat): ?>
						<tr>
							<td>
								<span class="bizcity-memory-type type-<?php echo esc_attr($type_stat['memory_type']); ?>">
									<?php echo $type_labels[$type_stat['memory_type']] ?? $type_stat['memory_type']; ?>
								</span>
							</td>
							<td><?php echo number_format($type_stat['count']); ?></td>
							<td><?php echo number_format($type_stat['avg_score'], 1); ?>/100</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			
			<!-- Memory List -->
			<div class="bizcity-memories-table">
				<h2 style="padding: 20px 20px 10px; margin: 0;">Chi tiết Memories / Memory Details</h2>
				<?php if (empty($memories)): ?>
					<p style="padding: 20px; color: #666;">
						Chưa có memory nào. Nhấn nút "Xây dựng Memory từ tin nhắn" để bắt đầu. / No memories yet. Click "Build Memory from Messages" to start.
					</p>
				<?php else: ?>
					<table>
						<thead>
							<tr>
								<th>Loại / Type</th>
								<th>Key</th>
								<th width="40%">Nội dung / Content</th>
								<th>Score</th>
								<th>Lần thấy / Seen</th>
								<th>Session ID</th>
								<th>Cập nhật / Updated</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($memories as $mem): ?>
							<tr>
								<td>
									<span class="bizcity-memory-type type-<?php echo esc_attr($mem->memory_type); ?>">
										<?php echo $type_labels[$mem->memory_type] ?? $mem->memory_type; ?>
									</span>
								</td>
								<td><code><?php echo esc_html($mem->memory_key); ?></code></td>
								<td><?php echo esc_html($mem->memory_text); ?></td>
								<td>
									<div class="bizcity-score-bar">
										<div class="bizcity-score-fill" style="width: <?php echo $mem->score; ?>%"></div>
									</div>
									<small><?php echo $mem->score; ?>/100</small>
								</td>
								<td><?php echo $mem->times_seen; ?>x</td>
								<td><small><?php echo esc_html(substr($mem->session_id, 0, 12)); ?>...</small></td>
								<td><small><?php echo esc_html($mem->updated_at); ?></small></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
	
	/**
	 * AJAX: Save settings
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'bizcity_webchat_settings', 'bizcity_webchat_nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
			return;
		}
		
		// Save settings
		$widget_enabled = isset( $_POST['widget_enabled'] ) ? true : false;
		$bot_name = sanitize_text_field( $_POST['bot_name'] ?? 'BizChat AI' );
		$bot_avatar = esc_url_raw( $_POST['bot_avatar'] ?? '' );
		$welcome_message = sanitize_textarea_field( $_POST['welcome_message'] ?? '' );
		$primary_color = sanitize_hex_color( $_POST['primary_color'] ?? '#3182f6' );
		$widget_position = sanitize_text_field( $_POST['widget_position'] ?? 'bottom-right' );
		$ai_model = sanitize_text_field( $_POST['ai_model'] ?? 'gpt-4o-mini' );
		$openai_api_key = sanitize_text_field( $_POST['openai_api_key'] ?? '' );
		$default_character_id = intval( $_POST['default_character_id'] ?? 0 );
		
		update_option( 'bizcity_webchat_widget_enabled', $widget_enabled );
		update_option( 'bizcity_webchat_bot_name', $bot_name );
		update_option( 'bizcity_webchat_bot_avatar', $bot_avatar );
		update_option( 'bizcity_webchat_welcome', $welcome_message );
		update_option( 'bizcity_webchat_primary_color', $primary_color );
		update_option( 'bizcity_webchat_widget_position', $widget_position );
		update_option( 'bizcity_webchat_ai_model', $ai_model );
		update_option( 'bizcity_webchat_openai_api_key', $openai_api_key );
		update_option( 'bizcity_webchat_default_character_id', $default_character_id );
		
		wp_send_json_success( [ 'message' => 'Settings saved!' ] );
	}
	
	/**
	 * Render Shortcode Guide Page
	 */
	public function render_shortcode_guide_page() {
		?>
		<style>
			.bw-guide-wrap {
				max-width: 1400px;
				margin-top: 20px;
			}
			.bw-guide-header {
				background: linear-gradient(135deg, #3182f6 0%, #1d6ae5 100%);
				color: #fff;
				padding: 30px 40px;
				border-radius: 12px;
				margin-bottom: 30px;
				box-shadow: 0 4px 20px rgba(49, 130, 246, 0.3);
			}
			.bw-guide-header h1 {
				margin: 0 0 10px 0;
				font-size: 32px;
				color: #fff;
			}
			.bw-guide-header p {
				margin: 0;
				opacity: 0.95;
				font-size: 16px;
			}
			.bw-guide-section {
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 12px;
				padding: 30px;
				margin-bottom: 24px;
				box-shadow: 0 2px 10px rgba(0,0,0,0.05);
			}
			.bw-guide-section h2 {
				margin-top: 0;
				padding-bottom: 12px;
				border-bottom: 2px solid #3182f6;
				color: #1d2327;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.bw-guide-section h2 .dashicons {
				color: #3182f6;
				font-size: 28px;
				width: 28px;
				height: 28px;
			}
			.bw-shortcode-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 20px;
			}
			.bw-shortcode-table th,
			.bw-shortcode-table td {
				padding: 14px 16px;
				text-align: left;
				border-bottom: 1px solid #e0e0e0;
			}
			.bw-shortcode-table th {
				background: #f8f9fa;
				font-weight: 600;
				color: #1d2327;
				border-top: 2px solid #3182f6;
			}
			.bw-shortcode-table tr:hover {
				background: #f8f9fa;
			}
			.bw-code {
				background: #f1f3f5;
				padding: 3px 8px;
				border-radius: 4px;
				font-family: 'Courier New', monospace;
				font-size: 13px;
				color: #000;
				font-weight: 800;
				white-space: nowrap;
			}
			.bw-code-block {
				background: #1e1e1e;
				color: #d4d4d4;
				padding: 16px 20px;
				border-radius: 8px;
				font-family: 'Courier New', monospace;
				font-size: 14px;
				margin: 12px 0;
				position: relative;
				overflow-x: auto;
			}
			.bw-copy-btn {
				position: absolute;
				top: 8px;
				right: 8px;
				background: #3182f6;
				color: #fff;
				border: none;
				padding: 6px 12px;
				border-radius: 4px;
				cursor: pointer;
				font-size: 12px;
				transition: all 0.2s;
			}
			.bw-copy-btn:hover {
				background: #1d6ae5;
				transform: translateY(-1px);
			}
			.bw-feature-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
				gap: 16px;
				margin-top: 20px;
			}
			.bw-feature-card {
				background: #f8f9fa;
				padding: 16px;
				border-radius: 8px;
				border-left: 4px solid #3182f6;
			}
			.bw-feature-card .icon {
				font-size: 24px;
				margin-bottom: 8px;
			}
			.bw-feature-card h4 {
				margin: 0 0 6px 0;
				color: #1d2327;
			}
			.bw-feature-card p {
				margin: 0;
				color: #666;
				font-size: 14px;
				line-height: 1.5;
			}
			.bw-note {
				background: #e7f3ff;
				border-left: 4px solid #3182f6;
				padding: 16px 20px;
				margin: 16px 0;
				border-radius: 4px;
			}
			.bw-note strong {
				color: #1d6ae5;
			}
		</style>
		
		<div class="wrap bw-guide-wrap">
			<div class="bw-guide-header">
				<h1>📚 Hướng Dẫn Sử Dụng Shortcode [chatbot] / Shortcode Guide</h1>
				<p>Tài liệu đầy đủ về cách sử dụng shortcode chatbot trong WordPress pages/posts / Complete guide for using chatbot shortcodes</p>
			</div>
			
			<!-- Quick Start -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-rocket"></span> Quick Start</h2>
				<p>Cách nhanh nhất để thêm chatbot vào trang của bạn / Quickest way to add chatbot to your page:</p>
				<div class="bw-code-block">
					<button class="bw-copy-btn" onclick="copyToClipboard('[chatbot]')">Copy</button>
					[chatbot]
				</div>
				<p>➜ Auto-select character đầu tiên, hiển thị sidebar để switch characters</p>
			</div>
			
			<!-- Basic Examples -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-editor-code"></span> Shortcode Cơ Bản / Basic Shortcodes</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 35%;">Shortcode</th>
							<th>Mô Tả</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">[chatbot]</code></td>
							<td>Auto-select character đầu tiên & hiển thị sidebar</td>
						</tr>
						<tr>
							<td><code class="bw-code">[bizcity_chat]</code></td>
							<td>Tương tự [chatbot] - alias</td>
						</tr>
						<tr>
							<td><code class="bw-code">[webchat]</code></td>
							<td>Tương tự [chatbot] - alias</td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<!-- Filter Characters -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-filter"></span> Lọc Hiển Thị Characters / Filter Characters Display</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 35%;">Shortcode</th>
							<th>Hiển Thị / Display</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">[chatbot show="active"]</code></td>
							<td><strong>Mặc định</strong> - Chỉ characters có status "active"</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot show="published"]</code></td>
							<td>Chỉ characters có status "published"</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot show="all"]</code></td>
							<td><strong>Tất cả</strong> characters (active + draft + published)</td>
						</tr>
					</tbody>
				</table>
				
				<div class="bw-note">
					<strong>💡 Gợi ý:</strong> Dùng <code>show="published"</code> cho trang public để chỉ hiển thị bot đã hoàn thiện, <code>show="all"</code> cho trang testing.
				</div>
				
				<div style="margin-top: 20px; padding: 16px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
					<strong style="color: #0369a1; font-size: 15px;">⚙️ Quản Lý Trạng Thái Characters / Manage Character Status</strong>
					<p style="margin: 10px 0 8px 0; color: #334155;">Để thay đổi trạng thái hiển thị của characters trên website:</p>
					<ol style="margin: 0; padding-left: 20px; color: #334155;">
						<li>Vào <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" style="color: #0ea5e9; font-weight: 600;">Knowledge Base → AI Characters</a></li>
						<li>Click <strong>"Sửa"</strong> character muốn cấu hình</li>
						<li>Chọn <strong>Status</strong>:
							<ul style="margin-top: 5px;">
								<li><code>draft</code> - Đang phát triển, không hiện trên web</li>
								<li><code>active</code> - Sẵn sàng cho internal testing</li>
								<li><code>published</code> - Công khai cho người dùng cuối</li>
								<li><code>archived</code> - Ngừng sử dụng</li>
							</ul>
						</li>
						<li>Click <strong>"Lưu thay đổi"</strong></li>
					</ol>
					<p style="margin: 12px 0 0 0;"><a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" class="button button-primary" style="text-decoration: none;">📝 Quản lý Characters ngay</a></p>
				</div>
			</div>
			
			<!-- Quick Character ID Finder -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-search"></span> Tìm Character ID / Find Character ID</h2>
				<p>Để lấy ID của character cho shortcode:</p>
				<ol style="line-height: 1.8;">
					<li>Vào <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" target="_blank" style="color: #3182f6; font-weight: 600;">Knowledge Base → AI Characters</a></li>
					<li>Nhìn vào cột <strong>"ID"</strong> hoặc hover vào link "Sửa" để thấy <code>?id=X</code> trong URL</li>
					<li>Sử dụng ID đó trong shortcode: <code class="bw-code">[chatbot character_id="X"]</code></li>
				</ol>
				<div style="background: #fff7ed; padding: 12px 16px; border-left: 4px solid #f97316; border-radius: 4px; margin-top: 16px;">
					<strong style="color: #c2410c;">📌 Tip:</strong> <span style="color: #7c2d12;">Nếu không set character_id, shortcode sẽ tự động chọn character đầu tiên theo thứ tự tạo.</span>
				</div>
			</div>
			
			<!-- Character Selection -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-admin-users"></span> Chọn Character Cụ Thể / Select Specific Character</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 45%;">Shortcode</th>
							<th>Kết Quả / Result</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">[chatbot character_id="5"]</code></td>
							<td>Mặc định chat với character ID=5, sidebar hiển thị active characters</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot character_id="5" show="all"]</code></td>
							<td>Chat với character ID=5, sidebar hiển thị TẤT CẢ characters</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot character_id="5" show="published"]</code></td>
							<td>Chat với character ID=5, sidebar chỉ published</td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<!-- Size Customization -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-admin-appearance"></span> Tùy Chỉnh Kích Thước / Customize Size</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 45%;">Shortcode</th>
							<th>Kích Thước / Size</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">[chatbot]</code></td>
							<td><strong>Mặc định:</strong> height="600px", width="100%"</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot height="500px"]</code></td>
							<td>Chiều cao 500px, rộng full width</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot height="800px" width="800px"]</code></td>
							<td>Chat box 800x800px</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot height="700px" width="90%"]</code></td>
							<td>Cao 700px, rộng 90% container</td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<!-- Advanced Examples -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-admin-settings"></span> Ví Dụ Tổng Hợp / Combined Examples</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 50%;">Shortcode</th>
							<th>Use Case</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">[chatbot show="all" height="700px"]</code></td>
							<td><strong>Testing Page:</strong> Hiển thị tất cả bots, cao 700px</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot show="published"]</code></td>
							<td><strong>Public Page:</strong> Chỉ bots đã publish cho users</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot character_id="3" height="600px"]</code></td>
							<td><strong>Landing Page:</strong> Chat cụ thể với bot ID=3</td>
						</tr>
						<tr>
							<td><code class="bw-code">[chatbot height="500px" width="800px"]</code></td>
							<td><strong>Sidebar Widget:</strong> Chat box nhỏ gọn</td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<!-- Features -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-yes-alt"></span> Tính Năng Hỗ Trợ / Supported Features</h2>
				<div class="bw-feature-grid">
					<div class="bw-feature-card">
						<div class="icon">📷</div>
						<h4>Vision Models</h4>
						<p>Upload & gửi hình ảnh cho AI phân tích (GPT-4o, Claude-3, Gemini)</p>
					</div>
					<div class="bw-feature-card">
						<div class="icon">💾</div>
						<h4>Lịch Sử Chat / Chat History</h4>
						<p>Tự động lưu lịch sử chat vào localStorage theo từng character / Auto-save chat history to localStorage per character</p>
					</div>
					<div class="bw-feature-card">
						<div class="icon">🔄</div>
						<h4>Switch Characters</h4>
						<p>Dễ dàng chuyển đổi giữa các characters từ sidebar</p>
					</div>
					<div class="bw-feature-card">
						<div class="icon">✍️</div>
						<h4>Markdown Support</h4>
						<p>Tự động format **bold**, *italic* trong messages</p>
					</div>
					<div class="bw-feature-card">
						<div class="icon">⌨️</div>
						<h4>Smart Input</h4>
						<p>Auto-resize textarea, Enter để gửi, Shift+Enter xuống dòng</p>
					</div>
					<div class="bw-feature-card">
						<div class="icon">🎨</div>
						<h4>Beautiful UI</h4>
						<p>Giao diện hiện đại với animations, gradients, responsive</p>
					</div>
				</div>
			</div>
			
			<!-- Parameters Reference -->
			<div class="bw-guide-section">
				<h2><span class="dashicons dashicons-book"></span> Tham Số Chi Tiết / Parameter Reference</h2>
				<table class="bw-shortcode-table">
					<thead>
						<tr>
							<th style="width: 25%;">Parameter</th>
							<th style="width: 20%;">Giá Trị / Value</th>
							<th style="width: 20%;">Mặc Định / Default</th>
							<th>Mô Tả / Description</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code class="bw-code">character_id</code></td>
							<td>Number</td>
							<td><em>auto</em></td>
							<td>ID của character. Nếu không set, auto-select character đầu tiên</td>
						</tr>
						<tr>
							<td><code class="bw-code">show</code></td>
							<td>all | active | published</td>
							<td>active</td>
							<td>Lọc characters hiển thị trong sidebar</td>
						</tr>
						<tr>
							<td><code class="bw-code">height</code></td>
							<td>CSS unit</td>
							<td>600px</td>
							<td>Chiều cao của chat box (px, %, vh)</td>
						</tr>
						<tr>
							<td><code class="bw-code">width</code></td>
							<td>CSS unit</td>
							<td>100%</td>
							<td>Chiều rộng của chat box (px, %, vw)</td>
						</tr>
						<tr>
							<td><code class="bw-code">style</code></td>
							<td>embedded | floating | popup</td>
							<td>embedded</td>
							<td>Kiểu hiển thị (floating & popup: coming soon)</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		
		<script>
		function copyToClipboard(text) {
			navigator.clipboard.writeText(text).then(function() {
				alert('Đã copy: ' + text);
			}, function(err) {
				console.error('Copy failed:', err);
			});
		}
		</script>
		<?php
	}
	
	/**
	 * Render Appearance Settings Page
	 */
	public function render_appearance_page() {
		$template = get_option('bizcity_webchat_template', 'minimal');
		
		// Handle form submission
		if (isset($_POST['bizcity_webchat_appearance_nonce']) && 
			wp_verify_nonce($_POST['bizcity_webchat_appearance_nonce'], 'bizcity_webchat_appearance')) {
			$new_template = sanitize_key($_POST['bizcity_webchat_template'] ?? 'minimal');
			if (in_array($new_template, ['minimal', 'legacy'], true)) {
				update_option('bizcity_webchat_template', $new_template);
				$template = $new_template;
				echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cài đặt giao diện! / Appearance settings saved!</p></div>';
			}
		}
		?>
		<div class="wrap">
			<h1><span class="dashicons dashicons-admin-appearance" style="margin-right:8px;"></span>Giao diện Chat / Chat Appearance</h1>
			
			<form method="post" action="">
				<?php wp_nonce_field('bizcity_webchat_appearance', 'bizcity_webchat_appearance_nonce'); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="bizcity_webchat_template">Chọn giao diện / Select Template</label>
						</th>
						<td>
							<fieldset>
								<label style="display:block; margin-bottom:12px; padding:15px; border:2px solid <?php echo $template === 'minimal' ? '#3182f6' : '#ddd'; ?>; border-radius:8px; cursor:pointer; background:<?php echo $template === 'minimal' ? '#f0f8ff' : '#fff'; ?>;">
									<input type="radio" name="bizcity_webchat_template" value="minimal" <?php checked($template, 'minimal'); ?> style="margin-right:10px;">
									<strong>Minimal</strong> <span style="color:#666;">(Mặc định / Default)</span>
									<p style="margin:8px 0 0 24px; color:#666; font-size:13px;">
										Giao diện tối giản, ẩn avatar, tối đa hóa không gian chat. Phù hợp cho trải nghiệm nhanh gọn. / Minimal interface, hidden avatars, maximized chat space.
									</p>
								</label>
								
								<label style="display:block; margin-bottom:12px; padding:15px; border:2px solid <?php echo $template === 'legacy' ? '#3182f6' : '#ddd'; ?>; border-radius:8px; cursor:pointer; background:<?php echo $template === 'legacy' ? '#f0f8ff' : '#fff'; ?>;">
									<input type="radio" name="bizcity_webchat_template" value="legacy" <?php checked($template, 'legacy'); ?> style="margin-right:10px;">
									<strong>Legacy</strong> <span style="color:#666;">(Cổ điển / Classic)</span>
									<p style="margin:8px 0 0 24px; color:#666; font-size:13px;">
										Giao diện đầy đủ với avatar, màu sắc gradient, và hiệu ứng hover. Phù hợp khi cần thể hiện thương hiệu. / Full interface with avatars, gradient colors, and hover effects. Suitable for branding.
									</p>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				
				<?php submit_button('Lưu cài đặt / Save Settings'); ?>
			</form>
			
			<hr style="margin:30px 0;">
			
			<h2>Xem trước nhanh / Quick Preview</h2>
			<p>Bạn có thể xem trước giao diện mà không cần lưu bằng cách thêm tham số URL / Preview templates without saving by adding URL parameters:</p>
			<ul style="list-style:disc; margin-left:20px;">
				<li><code>/chat/?template=minimal</code> — Xem giao diện Minimal</li>
				<li><code>/chat/?template=legacy</code> — Xem giao diện Legacy</li>
			</ul>
		</div>
		<?php
	}
}
