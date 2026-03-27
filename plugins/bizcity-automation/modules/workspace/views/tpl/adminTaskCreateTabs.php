<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
?>
<div class="wbw-menu-tabs">
	<div class="wbw-grbtn">
		<?php foreach ($props['tabs'] as $key => $data) { ?>
			<button type="button" data-content="#content-tab-<?php echo esc_attr($key); ?>" class="wbw-button <?php echo ( !$data['pro'] || $props['is_pro'] ? '' : 'wbw-show-pro ' ) . ( empty($data['class']) ? '' : esc_attr($data['class']) ); ?>">
				<?php echo esc_html($data['label']); ?>
			</button>
		<?php } ?>
		<button type="button" class="wbw-leer"></button>
	</div>
</div>
<div id="waicEditNameDialog" class="wbw-hidden" title="<?php esc_attr_e('Enter a title for this scenario', 'ai-copilot-content-generator'); ?>">
	<div class="wbw-settings-fields">
		<?php 
			WaicHtml::text('', array(
				'value' => $props['task_title'],
				'attrs' => 'id="waicNewTaskName"',
			));
			?>
	</div>
</div>
