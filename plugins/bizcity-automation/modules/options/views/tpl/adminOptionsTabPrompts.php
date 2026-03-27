<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
//var_dump($props['options']['prompts']);
$options = WaicUtils::getArrayValue($props['options'], 'prompts', array(), 2);
$defaults = WaicUtils::getArrayValue($props['defaults'], 'prompts', array(), 2);
?>
<div class="wbw-alert-block">
	<div class="wbw-alert-title"><span>!</span> Chỉ dành cho người dùng có kinh nghiệm</div>
	<div class="wbw-alert-info">Việc chỉnh sửa prompts có thể ảnh hưởng đến chất lượng nội dung được tạo ra và chức năng tổng thể của plugin. Chỉ tiến hành nếu bạn là người dùng có kinh nghiệm với mạng neural tạo sinh. Các giá trị mặc định luôn có thể được khôi phục.</div>
</div>
<section class="wbw-body-options-prompts">
	<div class="wbw-group-title">
		Bài viết
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Tiêu đề dựa trên chủ đề</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[title_topic]', array(
					'value' => WaicUtils::getArrayValue($options, 'title_topic', $defaults['title_topic']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Tiêu đề dựa trên dàn ý (chỉ dùng cho bài viết tùy chỉnh theo phần)</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[title_sections]', array(
					'value' => WaicUtils::getArrayValue($options, 'title_sections', $defaults['title_sections']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Tiêu đề (tạo dựa trên nội dung bài)</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[title_body]', array(
					'value' => WaicUtils::getArrayValue($options, 'title_body', $defaults['title_body']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Nội dung (Bài viết prompt đơn)</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[body]', array(
					'value' => WaicUtils::getArrayValue($options, 'body', $defaults['body']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Dàn ý (Tạo bài viết theo phần)</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[sections]', array(
					'value' => WaicUtils::getArrayValue($options, 'sections', $defaults['sections']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Phần (Tạo bài viết theo phần & Tùy chỉnh)</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[body_section]', array(
					'value' => WaicUtils::getArrayValue($options, 'body_section', $defaults['body_section']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Danh mục</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[categories]', array(
					'value' => WaicUtils::getArrayValue($options, 'categories', $defaults['categories']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Thẻ</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[tags]', array(
					'value' => WaicUtils::getArrayValue($options, 'tags', $defaults['tags']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Trích đoạn</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[excerpt]', array(
					'value' => WaicUtils::getArrayValue($options, 'excerpt', $defaults['excerpt']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Trường tùy chỉnh</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[custom]', array(
					'value' => WaicUtils::getArrayValue($options, 'custom', $defaults['custom']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Hình ảnh</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[image]', array(
					'value' => WaicUtils::getArrayValue($options, 'image', $defaults['image']),
				));
				?>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label wbw-settings-label-top col-2">Text thay thế cho hình ảnh</div>
		<div class="wbw-settings-fields col-10">
			<?php 
				WaicHtml::textarea('prompts[image_alt]', array(
					'value' => WaicUtils::getArrayValue($options, 'image_alt', $defaults['image_alt']),
				));
				?>
		</div>
	</div>
<?php 
	$this->includeExtTemplate('postsrss', 'adminOptionsTabPrompts');
	$this->includeExtTemplate('postslinks', 'adminOptionsTabPrompts');
	$this->includeExtTemplate('productsfields', 'adminOptionsTabPrompts');
?>
</section>