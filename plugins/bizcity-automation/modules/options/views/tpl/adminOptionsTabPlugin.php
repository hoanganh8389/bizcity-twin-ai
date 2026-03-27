<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$options = WaicUtils::getArrayValue($props['options'], 'plugin', array(), 2);
$variations = WaicUtils::getArrayValue($props['variations'], 'plugin', array(), 2);
$defaults = WaicUtils::getArrayValue($props['defaults'], 'plugin', array(), 2);
?>
<section class="wbw-body-options-api">
	<div class="wbw-group-title">
		Cài đặt tạo nội dung
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Khởi động tạo nội dung</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Khởi động thủ công trình tạo nội dung nếu nó không tự động chạy. Tính năng này giúp bạn khắc phục sự cố và đảm bảo quá trình tạo được khởi động đúng cách.">
			<div class="wbw-settings-field">
				<button class="wbw-button wbw-button-small" id="waicStartGeneration">Chạy</button>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Cho phép gửi thống kê sử dụng</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Gửi thông tin về các tùy chọn plugin bạn thường dùng, điều này sẽ giúp chúng tôi cải thiện sản phẩm tốt hơn cho bạn.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::checkbox('plugin[user_statistics]', array(
					'checked' => WaicUtils::getArrayValue($options, 'user_statistics', $defaults['user_statistics'], 1, false, true),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Bật ghi log</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Bật ghi log để ghi lại chi tiết hoạt động, hỗ trợ khắc phục sự cố và phân tích.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::checkbox('plugin[logging]', array(
					'checked' => WaicUtils::getArrayValue($options, 'logging'),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Thông báo bài viết mới</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Nếu bật, bạn sẽ nhận thông báo khi có bài viết mới được tạo và sẵn sàng kiểm duyệt. Chỉ áp dụng cho kịch bản với tự động xuất bản bị tắt.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::checkbox('plugin[notifications]', array(
					'checked' => WaicUtils::getArrayValue($options, 'notifications', $defaults['notifications'], 1, false, true),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Định dạng ngày tháng</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Chọn định dạng ngày tháng ưa thích để hiển thị trong plugin.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::selectBox('plugin[date_format]', array(
					'options' => $variations['date_format'],
					'value' => WaicUtils::getArrayValue($options, 'date_format', $defaults['date_format']),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2">Đường dẫn blocks workflow</div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="Nhập đường dẫn đến thư mục chứa các blocks tùy chỉnh cho workflow. Chỉ định đường dẫn tương đối từ thư mục gốc site.">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::text('plugin[blocks_path]', array(
					'value' => WaicUtils::getArrayValue($options, 'blocks_path', ''),
					'attrs' => 'class="wbw-medium-field"',
				));
				?>
			</div>
		</div>
	</div>
</section>