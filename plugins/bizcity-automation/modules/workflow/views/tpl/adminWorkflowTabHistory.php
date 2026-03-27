<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
?>
<section class="wbw-body-history">
	<div class="wbw-table-list mt-3">
		<table id="waicHistoryList">
			<thead>
				<tr>
					<th><input type="checkbox" class="waicCheckAll"></th>
					<th><?php esc_html_e('ID', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Name', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Tokens', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Status', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Date', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Author', 'ai-copilot-content-generator'); ?></th>
					<th><?php esc_html_e('Actions', 'ai-copilot-content-generator'); ?></th>
				</tr>
			</thead>
		</table>
	</div>
	<div class="wbw-clear"></div>
</section>

