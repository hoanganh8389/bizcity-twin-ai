<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_lp_orders extends WaicLogic {
	protected $_code = 'lp_orders';
	protected $_subtype = 3;
	protected $_order = 21;
	
	public function __construct( $block = null ) {
		$this->_name = __('Search Orders', 'ai-copilot-content-generator');
		$this->_desc = __('Repeat actions for multiple orders', 'ai-copilot-content-generator');
		$this->_sublabel = array('name');
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
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'status' => array(
				'type' => 'multiple',
				'label' => __('Order Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getOrderStatuses(),
			),
			'amount_mode' => array(
				'type' => 'select',
				'label' => __('Order Amounts', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => '',
					'total' => __('Total', 'ai-copilot-content-generator'),
					'discount_total' => __('Discount Total', 'ai-copilot-content-generator'),
					'shipping_total' => __('Shipping Total', 'ai-copilot-content-generator'),
				),
			),
			'amount_from' => array(
				'type' => 'number',
				'label' => __('Amount Range', 'ai-copilot-content-generator'),
				'default' => 0,
				'step' => '0.01',
				'min' => 0,
				'show' => array('amount_mode' => array('total', 'discount_total', 'shipping_total')),
				'add' => array('amount_to'),
			),
			'amount_to' => array(
				'type' => 'number',
				'label' => '',
				'default' => 0,
				'step' => '0.01',
				'min' => 0,
				'show' => array('amount_mode' => array('total', 'discount_total', 'shipping_total')),
				'inner' => true,
			),
			'date_mode' => array(
				'type' => 'select',
				'label' => __('Order Date', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => '',
					'date_created' => __('Created', 'ai-copilot-content-generator'),
					'date_paid' => __('Paid', 'ai-copilot-content-generator'),
					'date_completed' => __('Completed', 'ai-copilot-content-generator'),
					'date_modified' => __('Modified', 'ai-copilot-content-generator'),
				),
			),
			'date_from' => array(
				'type' => 'date',
				'label' => __('Date Range', 'ai-copilot-content-generator'),
				'default' => '',
				'show' => array('date_mode' => array('date_created', 'date_paid', 'date_completed', 'date_modified')),
				'add' => array('date_to'),
			),
			'date_to' => array(
				'type' => 'date',
				'label' => '',
				'default' => '',
				'show' => array('date_mode' => array('date_created', 'date_paid', 'date_completed', 'date_modified')),
				'inner' => true,
			),
			'user' => array(
				'type' => 'input',
				'label' => __('Customer id', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'payment' => array(
				'type' => 'input',
				'label' => __('Payment Method (slug)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'currency' => array(
				'type' => 'input',
				'label' => __('Currency', 'ai-copilot-content-generator'),
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
		$this->_variables = array(
			'count_steps' => __('Total Number of Orders', 'ai-copilot-content-generator'),
			'count_errors' => __('Number of Errors', 'ai-copilot-content-generator'),
			'count_success' => __('Number of Successful Steps', 'ai-copilot-content-generator'),
			'loop_vars' => array_merge(
				array('step' => __('Step', 'ai-copilot-content-generator')), 
				$this->getOrderVariables(),
				$this->getUserVariables(),
			),
		);
		return $this->_variables;
	}
	
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!empty($this->_results)) {
			return $this->_results;
		}
		
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		
		$args = array('limit' => -1, 'return' => 'ids');

		$statuses = $this->getParam('status', array(), 2);
		if (!empty($statuses)) {
			$args['status'] = $statuses;
		}
		$dateMode = $this->getParam('date_mode');
		if (!empty($dateMode)) {
			$from = $this->getParam('date_from');
			$to = $this->getParam('date_to');
			$value = '';
			if (!empty($from) && !empty($to)) {
				$value = $from . '...' . $to;
			} else if (!empty($from)) {
				$value = '>=' . $from ;
			} else if (!empty($to)) {
				$value = '<=' . $to ;
			}
			if (!empty($value)) {
				$args[$dateMode] = $value;
			}
		}
		$user = trim($this->replaceVariables($this->getParam('user', '', 0, true), $variables));
		if ('' !== $user) {
			$args['customer_id'] = (int) $user;
		}
		$payment = trim($this->replaceVariables($this->getParam('payment'), $variables));
		if (!empty($payment)) {
			$args['payment_method'] = $payment;
		}
		$currency = trim($this->replaceVariables($this->getParam('currency'), $variables));
		if (!empty($currency)) {
			$args['currency'] = $currency;
		}
		$amountMode = $this->getParam('amount_mode');
		if (!empty($amountMode)) {
			$from = (float) $this->getParam('amount_from');
			$to = (float) $this->getParam('amount_to');
			$args['field_query'] = array(
				array(
					'field' => $amountMode,
					'compare' => 'BETWEEN',
					'type' => 'NUMERIC',
					'value' => array($from, $to),
				)
			);
		}

		$loopIds = wc_get_orders($args);
		
		wp_reset_query();
		
		if (empty($loopIds)) {
			$loopIds = array();
		}
		$cnt = count($loopIds);
		
		$this->_results = array(
			'result' => array(
				'loop' => $loopIds,
				'count_steps' => $cnt,
				'count_errors' => 0,
				'count_success' => 0,
			),
			'error' => '',
			'status' => 3,
			'cnt' => $cnt,
			'sourceHandle' => ( $cnt > 0 ? 'output-then' : 'output-else' ),
		);
		return $this->_results;
	}
	
	public function addLoopVariables( $step, $workflow ) {
		if (!isset($this->_results['result'])) {
			return array();
		}
		
		$result = $this->_results['result'];
		$variables = $result;
		$variables['step'] = $step;
		if (empty($step)) {
			return $variables;
		}
		
		$id = WaicUtils::getArrayValue(WaicUtils::getArrayValue($result, 'loop', array(), 2), ( $step - 1 ), 0, 1);
		if (!empty($id)) {
			$variables = $workflow->addOrderVariables($variables, $id);
		}
		return $variables;
	}
}
