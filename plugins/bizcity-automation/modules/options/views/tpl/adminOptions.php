<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;

?>
<section class="wbw-body-options">
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
	<div class="wbw-tabs-content">
		<?php foreach ($props['tabs'] as $key => $data) { ?>
			<div class="wbw-tab-content" id="content-tab-<?php echo esc_attr($key); ?>">
				<form id="waicOptionsForm<?php echo esc_attr($key); ?>" data-group="<?php echo esc_attr($key); ?>">
					<?php include_once 'adminOptionsTab' . waicStrFirstUp($key) . '.php'; ?>
					<div class="wbw-clear"></div>
					<div class="wbw-buttons-form row">
						<div class="col-12">
					<button class="wbw-button wbw-button-form wbw-button-main wbw-button-save">Lưu</button>
					<button class="wbw-button wbw-button-form wbw-button-minor wbw-button-cancel">Hủy</button>
					<button class="wbw-button wbw-button-form wbw-button-leer wbw-button-restore">Khôi phục mặc định</button>
						</div>
					</div>
				</form>
			</div>
			<?php 
		} 
		WaicHtml::hidden('', array('value' => WaicUtils::jsonEncode($props['lang']), 'attrs' => 'id="waicLangSettingsJson" class="wbw-nosave"'));
		?>
	</div>
</section>
