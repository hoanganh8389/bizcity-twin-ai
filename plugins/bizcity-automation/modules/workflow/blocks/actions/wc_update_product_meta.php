<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_update_product_meta extends WaicAction {
	protected $_code = 'wc_update_product_meta';
	protected $_order = 5;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Product Meta', 'ai-copilot-content-generator');
		//$this->_desc = __('Only filled fields will be updated.', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Product ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'meta_key' => array(
				'type' => 'input',
				'label' => __('Meta Key *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'meta_value' => array(
				'type' => 'input',
				'label' => __('Meta Value', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
		);
	}
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = $this->getProductVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		
		$error = '';
		$productId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		$key = $this->replaceVariables($this->getParam('meta_key'), $variables);
		$value = $this->replaceVariables($this->getParam('meta_value'), $variables);
		
		if (empty($productId)) {
			$error = 'Product ID needed';
		} else if (empty($key)) {
			$error = 'Meta Key needed';
		} else {
			$product = wc_get_product($productId);
			if (!$product) {
				$error = 'Product not found (ID=' . $productId . ')';
			}
		}
		
		if (empty($error)) {
			if (empty($value)) {
				delete_post_meta($productId, $key);
			} else {
				update_post_meta($productId, $key, $value);
			}
		}
		
		$this->_results = array(
			'result' => array('waic_product_id' => $productId, 'meta_key' => $key, 'meta_value' => $value),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
