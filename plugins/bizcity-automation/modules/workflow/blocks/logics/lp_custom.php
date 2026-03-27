<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_lp_custom extends WaicLogic {
	protected $_code = 'lp_custom';
	protected $_subtype = 3;
	protected $_order = 50;
	
	public function __construct( $block = null ) {
		$this->_name = __('Custom Array', 'ai-copilot-content-generator');
		$this->_desc = __('Loop through a custom array', 'ai-copilot-content-generator');
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
		global $wp_roles;
		$roles = array();

		foreach ($wp_roles->roles as $roleName => $roleData) {
			$roles[$roleName] = $roleName;
		}
		$this->_settings = array(
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'json' => array(
				'type' => 'textarea',
				'label' => __('Json', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'rows' => 15,
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
			'count_steps' => __('Total Number of Steps', 'ai-copilot-content-generator'),
			'count_errors' => __('Number of Errors', 'ai-copilot-content-generator'),
			'count_success' => __('Number of Successful Steps', 'ai-copilot-content-generator'),
			'loop_vars' => 
				array(
					'step' => __('Step', 'ai-copilot-content-generator'),
					'json_field' => __('Query Field *', 'ai-copilot-content-generator'),
				),
		);
		return $this->_variables;
	}
	
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!empty($this->_results)) {
			return $this->_results;
		}
		$json = $this->replaceVariables($this->getParam('json'), $variables);
		$error = '';
		if (empty($json)) {
			$error = 'JSON is empty';
		} else {
			$data = json_decode($json, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$data = is_array($data) ? array_values($data) : array();
				$cnt = count($data);
			} else {
				$error = 'JSON error: ' . json_last_error_msg();
			} 
		}
		if (!empty($error)) {
			$cnt = 0;
			$data = array();
		}
		
		$this->_results = array(
			'result' => array(
				'loop' => $data,
				'count_steps' => $cnt,
				'count_errors' => 0,
				'count_success' => 0,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
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
		$row = WaicUtils::getArrayValue(WaicUtils::getArrayValue($result, 'loop', array(), 2), ( $step - 1 ), array(), 2);
		if (!empty($row) && is_array($row)) {
			foreach ($row as $key => $value) {
				$variables['json_field[' . $key . ']'] = is_array($value) ? implode(',', $value) : $value;
			}
		}
		return $variables;
	}

}
