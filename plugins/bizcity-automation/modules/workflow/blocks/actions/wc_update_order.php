<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_update_order extends WaicAction {
	protected $_code = 'wc_update_order';
	protected $_order = 7;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Order', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': wp_login';
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$wordspace = WaicFrame::_()->getModule('workspace');
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Order ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Order Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), $this->getOrderStatuses()),
			),
			'note' => array(
				'type' => 'textarea',
				'label' => __('Add Note', 'ai-copilot-content-generator'),
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
		$this->_variables = $this->_variables = array_merge(
			$this->getOrderVariables(),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		$error = '';
		$orderId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($orderId)) {
			$error = 'Order ID needed';
		} else {
			$order = wc_get_order($orderId);
			if (!$order) {
				$error = 'Order not found';
			}
		}
		
		if (empty($error)) {
			$status = $this->getParam('status');
			$note = $this->replaceVariables($this->getParam('note'), $variables);
			if (empty($status)) {
				if (!empty($note)) {
					$order->add_order_note($note, true);
				}
			} else {
				$order->update_status($status, $note);
			}
		}

		$this->_results = array(
			'result' => array('waic_order_id' => (int) $orderId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
