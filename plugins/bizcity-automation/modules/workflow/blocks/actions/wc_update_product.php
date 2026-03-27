<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_update_product extends WaicAction {
	protected $_code = 'wc_update_product';
	protected $_order = 1;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Product', 'ai-copilot-content-generator');
		$this->_desc = __('Only filled fields will be updated.', 'ai-copilot-content-generator');
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
			'title' => array(
				'type' => 'input',
				'label' => __('Product Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'description' => array(
				'type' => 'textarea',
				'label' => __('Description', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'short_desc' => array(
				'type' => 'textarea',
				'label' => __('Short Description', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'regular_price' => array(
				'type' => 'input',
				'label' => __('Regular price (use +,-,*)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'sale_price' => array(
				'type' => 'input',
				'label' => __('Sale price (use +,-,*)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'stock_quantity' => array(
				'type' => 'input',
				'label' => __('Stock quantity (use +,-,*)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'stock_status' => array(
				'type' => 'select',
				'label' => __('Stock Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), $this->getStockStatusesList()),
			),
			'sku' => array(
				'type' => 'input',
				'label' => __('SKU', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Publishing Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), get_post_statuses()),
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
		if (empty($productId)) {
			$error = 'Product ID needed';
		} else {
			$productObj = wc_get_product($productId);
			if (!$productObj) {
				$error = 'Product not found';
			}
		}
		if (empty($error)) {
			$productType = $productObj->get_type();
			$isSimple = ( 'simple' == $productType || 'variation' == $productType );
		}
		
		$needUpdate = false;
		if (empty($error)) {
			$title = $this->getParam('title');
			if (!empty($title)) {
				$productObj->set_name(htmlspecialchars_decode($this->replaceVariables($title, $variables), ENT_QUOTES));
				$needUpdate = true;
			}
			$description = $this->getParam('description');
			if (!empty($description)) {
				$productObj->set_description(htmlspecialchars_decode($this->replaceVariables($description, $variables), ENT_QUOTES));
				$needUpdate = true;
			}
			$short = $this->getParam('short_desc');
			if (!empty($short)) {
				$productObj->set_short_description(htmlspecialchars_decode($this->replaceVariables($short, $variables), ENT_QUOTES));
				$needUpdate = true;
			}
			$sku = $this->getParam('sku');
			if (!empty($sku)) {
				$productObj->set_sku($this->replaceVariables($sku, $variables));
				$needUpdate = true;
			}
			if ($isSimple) {
				$stockStatus = $this->getParam('stock_status');
				if (!empty($stockStatus)) {
					$productObj->set_stock_status($this->replaceVariables($stockStatus, $variables));
					$needUpdate = true;
				}
				$status = $this->getParam('status');
				if (!empty($status)) {
					$productObj->set_status($this->replaceVariables($status, $variables));
					$needUpdate = true;
				}
			}
		}
		if (empty($error) && $isSimple) {
			$regularPrice = $this->getParam('regular_price');
			if (!empty($regularPrice)) {
				$regularPrice = $this->replaceVariables($regularPrice, $variables);
				$price = $this->calcExpression($regularPrice);
				if (false === $price) {
					$error = 'Error Regular Price Expression: ' . $regularPrice;
				} else {
					$productObj->set_regular_price((float) $price);
					$needUpdate = true;
				}
			}
		}
		if (empty($error) && $isSimple) {
			$salePrice = $this->getParam('sale_price');
			if (!empty($salePrice)) {
				$salePrice = $this->replaceVariables($salePrice, $variables);
				$price = $this->calcExpression($salePrice);
				if (false === $price) {
					$error = 'Error Sale Price Expression: ' . $salePrice;
				} else {
					$productObj->set_sale_price((float) $price);
					$needUpdate = true;
				}
			}
		}
		if (empty($error) && $isSimple) {
			$stockQuantity = $this->getParam('stock_quantity');
			if (!empty($stockQuantity)) {
				$stockQuantity = $this->replaceVariables($stockQuantity, $variables);
				$quantity = $this->calcExpression($stockQuantity);
				if (false === $quantity) {
					$error = 'Error Stock Quantity Expression: ' . $stockQuantity;
				} else {
					$productObj->set_stock_quantity((int) $quantity);
					$needUpdate = true;
				}
			}
		}
		if (empty($error)) {
			if ($needUpdate) {
				$productObj->save();
			}
		}
		
		$this->_results = array(
			'result' => array('waic_product_id' => (int) $productId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
