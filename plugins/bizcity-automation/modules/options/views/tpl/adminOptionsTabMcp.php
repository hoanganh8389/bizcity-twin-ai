<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$options = WaicUtils::getArrayValue($props['options'], 'mcp', array(), 2);
$variations = WaicUtils::getArrayValue($props['variations'], 'mcp', array(), 2);
$defaults = WaicUtils::getArrayValue($props['defaults'], 'mcp', array(), 2);
?>
<section class="wbw-body-options-api">
	<div class="wbw-group-title">
		Tích hợp AI MCP
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Bật MCP</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Bật tùy chọn này để tạo máy chủ Model Context Protocol (MCP) cung cấp các công cụ khác nhau.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::checkbox('mcp[e_mcp]', array(
					'checked' => WaicUtils::getArrayValue($options, 'e_mcp'),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Bật ghi log MCP</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Bật ghi log để ghi lại chi tiết hoạt động của máy chủ MCP, hỗ trợ khắc phục sự cố và phân tích. Lưu ý rằng ghi log chung trong Cài đặt tạo nội dung cũng cần được bật.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::checkbox('mcp[mcp_logging]', array(
					'checked' => WaicUtils::getArrayValue($options, 'mcp_logging'),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Mã truy cập</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="MCP sẽ sử dụng Mã truy cập này. Nếu không thiết lập, bạn cần xây dựng xác thực riêng bằng filter aiwu_allow_mcp.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::text('mcp[mcp_token]', array(
					'value' => WaicUtils::getArrayValue($options, 'mcp_token', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" id="waicMCPToken" placeholder="Mã bảo mật 32 ký tự"',
				));
				?>
			</div>
			<button id="waicGenarateMCPToken" class="wbw-button wbw-button-small">Tạo mã</button>
			<button id="waicViewMCPToken" class="wbw-button wbw-button-small m-0">Xem</button>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">URL kết nối</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="URL cho các trình kết nối">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::text('', array(
					'value' => home_url() . '/wp-json/mcp/v1/sse',
					'attrs' => 'readonly id="waicMCPUrl" class="wbw-fullwidth-max"',
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-group-title">
		Hướng dẫn kết nối
	</div>
	<div class="wbw-settings-form" id="waicMCPInstructions">
		<div class="wbw-submenu-tabs">
			<div class="wbw-grbtn">
				<button type="button" data-content="#content-subtab-claude" class="wbw-button current">Claude</button>
				<button type="button" data-content="#content-subtab-chatgpt" class="wbw-button">ChatGPT</button>
				<button type="button" data-content="#content-subtab-trouble" class="wbw-button">Khắc phục sự cố</button>
				<button type="button" class="wbw-leer"></button>
			</div>
		</div>
		<div class="wbw-subtabs-content">
			<div class="wbw-subtab-content" id="content-subtab-claude">
				<div class="wbw-instrs-block wbw-info-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon square">i</div>Tích hợp Claude MCP</div>
					<div class="wbw-instrs-info">Kết nối Claude thông qua hỗ trợ MCP chính thức trong giao diện web Claude.ai</div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">1</div>Mở cài đặt Claude</div>
					<div class="wbw-instrs-info">Vào <a href="https://claude.ai/" target="_blank">claude.ai</a> → Cài đặt → Connectors</div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">2</div>Thêm Connector tùy chỉnh</div>
					<div class="wbw-instrs-info">Nhấn "Add custom connector" và nhập URL endpoint MCP của bạn:
					<?php 
						WaicHtml::text('', array(
						'value' => home_url() . '/wp-json/mcp/v1/sse?token=your-token',
						'attrs' => 'readonly',
						));
						?>
					</div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">3</div>Cấu hình quyền</div>
					<div class="wbw-instrs-info">Trong Claude.ai, tìm máy chủ MCP đã kết nối → Tools and settings → chọn các công cụ bạn cần</div>
				</div>
				<div class="wbw-alert-block">
					<div class="wbw-alert-title"><span>!</span> Yêu cầu</div>
					<div class="wbw-alert-info">Chứng chỉ HTTPS, IP công khai, tắt cache cho /wp-json/mcp/v1/sse</div>
				</div>
			</div>
			<div class="wbw-subtab-content" id="content-subtab-chatgpt">
				<div class="wbw-instrs-block wbw-info-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon square">i</div>Tích hợp ChatGPT MCP</div>
					<div class="wbw-instrs-info">Kết nối thông qua chế độ developer trong cài đặt ChatGPT với xác thực OAuth</div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">1</div><?php esc_html_e('Enable Developer Mode', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info"><?php esc_html_e('Go to ChatGPT → Settings → Connectors → Advanced Settings → Enable Developer Mode', 'ai-copilot-content-generator'); ?></div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">2</div><?php esc_html_e('Generate Token in Plugin', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info"><?php esc_html_e('Your plugin: Settings → MCP → Click "Enable MCP" → Generate token', 'ai-copilot-content-generator'); ?></div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">3</div><?php esc_html_e('Create Custom Connector', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info"><?php esc_html_e('ChatGPT → Settings → Connectors → Create New Connector', 'ai-copilot-content-generator'); ?>
					<ul>
						<li>URL:
						<?php 
							WaicHtml::text('', array(
							'value' => home_url() . '/wp-json/mcp/v1/sse?token=XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
							'attrs' => 'readonly',
							));
							?>
						</li>
						<li>Authentication: "No Authentication"</li>
						<li>Click "Create"</li>
					</ul>
					</div>
				</div>
				<div class="wbw-instrs-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon">4</div><?php esc_html_e('Use Connector', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info"><?php esc_html_e('In ChatGPT chat → Click "+" → Select "Developer Mode" → Choose your connector → Tell ChatGPT to use the connector features', 'ai-copilot-content-generator'); ?></div>
				</div>
				<div class="wbw-alert-block">
					<div class="wbw-alert-title"><span>!</span> <?php esc_html_e('Important', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-alert-info"><?php esc_html_e('ChatGPT Pro version required. Developer mode is in beta. Token passed via URL parameters, not headers.', 'ai-copilot-content-generator'); ?></div>
				</div>
			</div>
			<div class="wbw-subtab-content" id="content-subtab-trouble">
				<div class="wbw-instrs-block wbw-trouble-block">
					<div class="wbw-instrs-title"><?php esc_html_e('Connection Issues', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info">
						<ul>
							<li><?php esc_html_e('Verify that SSL certificate is valid', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Ensure site is accessible from the internet (not localhost)', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Disable caching for /wp-json/mcp/v1/sse in Cloudflare/NGINX', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Check if MCP is enabled in AIWU settings', 'ai-copilot-content-generator'); ?></li>
						</ul>
					</div>
				</div>
				<div class="wbw-instrs-block wbw-trouble-block">
					<div class="wbw-instrs-title"><?php esc_html_e('Permission Errors', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info">
						<ul>
							<li><?php esc_html_e('Check WordPress user permissions', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Ensure MCP tools are enabled in AI', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Verify Access Token is correct', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Check logs in /wp-content/plugins/aiwu/logs/', 'ai-copilot-content-generator'); ?></li>
						</ul>
					</div>
				</div>
				<div class="wbw-instrs-block wbw-trouble-block">
					<div class="wbw-instrs-title"><?php esc_html_e('Performance Issues', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info">
						<ul>
							<li><?php esc_html_e('Monitor server resources during MCP operations', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Check MCP logs for errors', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Consider rate limiting for bulk operations', 'ai-copilot-content-generator'); ?></li>
							<li><?php esc_html_e('Use staging environment for testing', 'ai-copilot-content-generator'); ?></li>
						</ul>
					</div>
				</div>
				<div class="wbw-instrs-block wbw-info-block">
					<div class="wbw-instrs-title"><div class="wbw-instrs-icon square">i</div><?php esc_html_e('Connection Test', 'ai-copilot-content-generator'); ?></div>
					<div class="wbw-instrs-info"><?php esc_html_e('Use the mcp_ping function to test your connection', 'ai-copilot-content-generator'); ?></div>
				</div>
			</div>
		</div>
	</div>
</section>