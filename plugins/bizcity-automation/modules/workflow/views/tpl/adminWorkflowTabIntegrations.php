<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$integModel = $this->getModule()->getModel('integrations');
//$o = array_merge(array('' => __('All categories', 'ai-copilot-content-generator')), $integModel->getIntegCategories());
//error_log(json_encode($o));
$integrations = $integModel->getIntegCodes();
$integSaved = $integModel->getAllSavedIntegrations(true, false);
$integStatuses = $integModel->getStatuses();
?>
<section class="wbw-body-intagrations">
	<div class="waic-tab-header waic-row-coltrols">
		<div class="waic-row-coltrol">
			<a href="<?php echo esc_url($props['ai_url']); ?>" target="_blank" class="waic-maim-link"><?php esc_html_e('AI Providers', 'ai-copilot-content-generator'); ?> →</a>
		</div>
		<div class="waic-row-coltrol">
			<?php 
				WaicHtml::selectbox('', array(
					'options' => array_merge(array('' => __('All categories', 'ai-copilot-content-generator')), $integModel->getIntegCategories()),
					'attrs' => 'id="waicCategoriesList" class="wbw-nosave"',
				));
				WaicHtml::hidden('', array(
					'value' => WaicUtils::jsonEncode($integStatuses), 
					'attrs' => 'id="waicIntegStatuses"'));
				?>
		</div>
	</div>
	<div class="waic-section-integrations">
		<?php 
		foreach ($integrations as $key => $integ) { 
			$integCode = $integ['code'];
			$iSaved = WaicUtils::getArrayValue($integSaved, $integCode, array(), 2);
			$connected = false;
			foreach ($iSaved as $s) {
				if (WaicUtils::getArrayValue($s, '_status', 0, 1) == 1) {
					$connected = true;
				}
			}
			?>
		<div class="waic-section" data-code="<?php echo esc_attr($integCode); ?>" data-category="<?php echo esc_attr($integ['category']); ?>">
			<div class="waic-section-header waic-row-coltrols">
				<div class="waic-row-coltrol">
					<div class="waic-integ-logo"><?php echo esc_html($integ['logo']); ?></div>
					<div class="waic-integ-text">
						<div class="waic-integ-name"><?php echo esc_html($integ['name']); ?></div>
						<div class="waic-integ-desc"><?php echo esc_html($integ['desc']); ?></div>
					</div>
				</div>
				<div class="waic-row-coltrol">
					<div class="waic-integ-connected<?php echo $connected ? '' : esc_attr(' wbw-hidden'); ?>"><?php esc_html_e('Connected', 'ai-copilot-content-generator'); ?></div>
					<button class="wbw-button wbw-button-small waic-add-integration"><?php esc_html_e('Connect New', 'ai-copilot-content-generator'); ?></button>
					<a href="#" class="waic-section-toggle"><i class="fa fa-chevron-down"></i></a>
				</div>
			</div>
			<div class="waic-section-options wbw-hidden">
				<?php
					WaicHtml::hidden('', array(
						'value' => WaicUtils::jsonEncode($integ['settings']), 
						'attrs' => 'class="waic-integ-settings"'));
					WaicHtml::hidden('', array(
						'value' => WaicUtils::jsonEncode($integSaved[$integCode]), 
						'attrs' => 'class="waic-integ-accounts"'));
				?>
				<div class="waic-accounts-list">
				</div>
			</div>
		</div>
		<?php }?>
	</div>
	
	<div class="wbw-clear"></div>
	<div class="wbw-template">
		<div class="waic-no-accounts">
			<?php esc_html_e('No connections', 'ai-copilot-content-generator'); ?>
		</div>
		<div class="waic-saving-accounts">
			<div class="waic-loader">
				<div class="waic-loader-bar bar1"></div><div class="waic-loader-bar bar2"></div>
			</div>
		</div>
		<div class="waic-integ-account waic-row-coltrols">
			<div class="waic-row-coltrol">
				<div class="waic-account-name"></div>
			</div>
			<div class="waic-row-coltrol">
				<div class="waic-account-status"></div>
				<a href="#" class="waic-account-coltrol" data-action="test"><?php esc_html_e('Test', 'ai-copilot-content-generator'); ?></a>
				<a href="#" class="waic-account-coltrol" data-action="edit"><?php esc_html_e('Edit', 'ai-copilot-content-generator'); ?></a>
				<a href="#" class="waic-account-coltrol" data-action="delete"><?php esc_html_e('Delete', 'ai-copilot-content-generator'); ?></a>
			</div>
		</div>
	</div>
	<div id="waicIntegSettingsDialog" class="wbw-hidden" title="<?php esc_attr_e('Integration', 'ai-copilot-content-generator'); ?>">
		<div class="waic-dialog-form">
		</div>
	</div>
</section>

