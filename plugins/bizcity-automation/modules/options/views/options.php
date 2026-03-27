<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicOptionsView extends WaicView {
	
	public function getSettingsTabContent() {
		$accets = WaicAssets::_();
		$frame = WaicFrame::_();
		
		$accets->loadSlider();
		
		$path = $this->getModule()->getModPath() . 'assets/';
		
		$frame->addScript('waic-admin-settings', $path . 'js/admin.settings.js');
		
		$module = $frame->getModule('options');
		$model = $module->getModel();
		
		$lang = array(
			'confirm-restore' => esc_html__('Do you really want to restore the settings to their default values?', 'ai-copilot-content-generator'),
			'btn-view' => esc_html__('View', 'ai-copilot-content-generator'),
			'btn-hide' => esc_html__('Hide', 'ai-copilot-content-generator'),
		);
		$curTab = WaicReq::getVar('cur');
		
		$this->assign('lang', WaicDispatcher::applyFilters('addLangSettings', $lang));
		$this->assign('tabs', $module->getOptionsTabsList(is_null($curTab) || empty($curTab) ? '' : $curTab));
		$this->assign('is_pro', $frame->isPro());
		$this->assign('options', $model->getAll());
		$this->assign('variations', $model->getVariations());
		$this->assign('defaults', $model->getDefaults());

		return parent::getContent('adminOptions');
	}
}
