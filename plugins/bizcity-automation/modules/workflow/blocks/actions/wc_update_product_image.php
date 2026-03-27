<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_update_product_image extends WaicAction {
	protected $_code = 'wc_update_product_image';
	protected $_order = 2;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Product Image', 'ai-copilot-content-generator');
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
			'image' => array(
				'type' => 'input',
				'label' => __('Image ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'alt' => array(
				'type' => 'input',
				'label' => __('Image Alt', 'ai-copilot-content-generator'),
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
		$image = (int) $this->replaceVariables($this->getParam('image'), $variables);
		
		if (empty($productId)) {
			$error = 'Product ID needed';
		} else if (empty($image)) {
			$error = 'Image ID needed';
		} else {
			$product = wc_get_product($productId);
			if (!$product) {
				$error = 'Product not found (ID=' . $productId . ')';
			}
		}
		if (empty($error)) {
			$alt = $this->replaceVariables($this->getParam('alt'), $variables);
			if (empty($alt)) {
				$alt = $product->get_title();
			}
			$error =  $this->addPostImage($productId, $image, $alt);
		}
		
		$this->_results = array(
			'result' => array('waic_product_id' => $productId, 'image_id' => $image, 'alt' => $alt),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
