<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicOptions extends WaicModule {
	private $_options = array();
	private $_optionsToCategoires = array(); // For faster search

	public function init() {
		WaicDispatcher::addFilter('mainAdminTabs', array($this, 'addAdminTab'));
	}
	public function initAllOptValues() {
		// Just to make sure - that we loaded all default options values
		$this->getAll();
	}
	/**
	 * This method provides fast access to options model method get
	 *
	 * @see optionsModel::get($d)
	 */
	public function get( $gr = '', $key = '' ) {
		return $this->getModel()->get($gr, $key);
	}
	public function getWithDefaults( $gr, $key, $leer = true ) {
		$value = $this->getModel()->get($gr, $key);
		if ($leer) {
			return empty($value) ? $this->getModel()->getDefaults($gr, $key) : $value;
		} else {
			return false === $value ? $this->getModel()->getDefaults($gr, $key) : $value;
		}
	}
	/**
	 * This method provides fast access to options model method get
	 *
	 * @see optionsModel::get($d)
	 */
	public function isEmpty( $key = '' ) {
		return $this->getModel()->isEmpty($key);
	}

	public function addAdminTab( $tabs ) {
		$tabs['settings'] = array('label' => esc_html__('Cài đặt', 'ai-copilot-content-generator'), 'callback' => array($this, 'getSettingsTabContent'), 'fa_icon' => 'fa-cog', 'sort_order' => 40);
		return $tabs;
	}
	public function getSettingsTabContent() {
		return $this->getView()->getSettingsTabContent();
	}
	public function getOptionsTabsList( $current = '' ) {
		$tabs = array(
			'api' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Cài đặt các API ', 'ai-copilot-content-generator'),
			),
			'router' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Router (Phân loại yêu cầu)', 'ai-copilot-content-generator'),
			),
			'mcp' => array(
				'class' => '',
				'pro' => false,
				'label' => __('MCP', 'ai-copilot-content-generator'),
			),
			'plugin' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Cài đặt Plugin', 'ai-copilot-content-generator'),
			),
			'prompts' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Chỉnh sửa Prompts', 'ai-copilot-content-generator'),
			),
		);

		if (empty($current) || !isset($tabs[$current])) {
			reset($tabs);
			$current = key($tabs);
		}
		$tabs[$current]['class'] .= ' current';
		
		return WaicDispatcher::applyFilters('getOptionsTabsList', $tabs);
	}
}
