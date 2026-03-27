<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$options = WaicUtils::getArrayValue($props['options'], 'router', array(), 2);
$defaults = WaicUtils::getArrayValue($props['defaults'], 'router', array(), 2);

$enabled = (int) WaicUtils::getArrayValue($options, 'enabled', WaicUtils::getArrayValue($defaults, 'enabled', 1));
$promptTemplate = WaicUtils::getArrayValue($options, 'prompt_template', WaicUtils::getArrayValue($defaults, 'prompt_template', ''));
$typesList = WaicUtils::getArrayValue($options, 'types_list', WaicUtils::getArrayValue($defaults, 'types_list', ''));
$typesJson = WaicUtils::getArrayValue($options, 'types_json', WaicUtils::getArrayValue($defaults, 'types_json', ''));
$outputMap = WaicUtils::getArrayValue($options, 'output_map', WaicUtils::getArrayValue($defaults, 'output_map', ''));
?>

<div class="wbw-group-title">
	<?php esc_html_e('Router (Phân loại yêu cầu)', 'ai-copilot-content-generator'); ?>
</div>

<div class="wbw-settings-form row">
	<div class="wbw-settings-label col-3 col-xl-2">
		<?php esc_html_e('Bật Router options', 'ai-copilot-content-generator'); ?>
	</div>
	<div class="wbw-settings-fields col-8 col-sm-9 col-xl-9">
		<?php
			WaicHtml::checkboxToggle('router[enabled]', array(
				'checked' => (int) $enabled === 1,
			));
		?>
	</div>
</div>

<div class="wbw-settings-form row">
	<div class="wbw-settings-label wbw-settings-label-top col-3 col-xl-2">
		<?php esc_html_e('Prompt template', 'ai-copilot-content-generator'); ?>
		<div class="wbw-settings-label-desc">
			<?php esc_html_e('Dùng {message}, {types}, {schema}. Bạn vẫn có thể dùng biến workflow dạng {{node#1.xxx}}.', 'ai-copilot-content-generator'); ?>
		</div>
	</div>
	<div class="wbw-settings-fields col-8 col-sm-9 col-xl-9">
		<?php
			WaicHtml::textarea('router[prompt_template]', array(
				'value' => $promptTemplate,
				'rows' => 14,
			));
		?>
	</div>
</div>

<div class="wbw-settings-form row">
	<div class="wbw-settings-label wbw-settings-label-top col-3 col-xl-2">
		<?php esc_html_e('Types / Cases (mỗi dòng 1 type)', 'ai-copilot-content-generator'); ?>
		<div class="wbw-settings-label-desc">
			<?php esc_html_e('Dùng để hiển thị danh sách type (phân loại) cho router và dễ copy vào Logic/Switch (ví dụ: viet_bai, tao_san_pham...). Nếu Types JSON rỗng/không hợp lệ thì hệ thống sẽ fallback qua danh sách này.', 'ai-copilot-content-generator'); ?>
		</div>
	</div>
	<div class="wbw-settings-fields col-8 col-sm-9 col-xl-9">
		<?php
			WaicHtml::textarea('router[types_list]', array(
				'value' => $typesList,
				'rows' => 8,
			));
		?>
	</div>
</div>

<div class="wbw-settings-form row">
	<div class="wbw-settings-label wbw-settings-label-top col-3 col-xl-2">
		<?php esc_html_e('Types / Cases (JSON)', 'ai-copilot-content-generator'); ?>
		<div class="wbw-settings-label-desc">
			<?php esc_html_e('Tuỳ chọn nâng cao: mỗi phần tử gồm type, desc, instruction (tuỳ chọn), fields (tuỳ chọn). Nếu JSON hợp lệ sẽ được ưu tiên để build prompt.', 'ai-copilot-content-generator'); ?>
		</div>
	</div>
	<div class="wbw-settings-fields col-8 col-sm-9 col-xl-9">
		<?php
			WaicHtml::textarea('router[types_json]', array(
				'value' => $typesJson,
				'rows' => 14,
			));
		?>
	</div>
</div>

<div class="wbw-settings-form row">
	<div class="wbw-settings-label wbw-settings-label-top col-3 col-xl-2">
		<?php esc_html_e('Output mapping', 'ai-copilot-content-generator'); ?>
		<div class="wbw-settings-label-desc">
			<?php esc_html_e('Mỗi dòng: var=json.path[:type]. type: text|int|float|number|bool|json', 'ai-copilot-content-generator'); ?>
		</div>
	</div>
	<div class="wbw-settings-fields col-8 col-sm-9 col-xl-9">
		<?php
			WaicHtml::textarea('router[output_map]', array(
				'value' => $outputMap,
				'rows' => 16,
			));
		?>
	</div>
</div>
