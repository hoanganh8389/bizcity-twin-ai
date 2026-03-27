<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_delete_user extends WaicAction {
	protected $_code = 'wp_delete_user';
	protected $_order = 9;
	
	public function __construct( $block = null ) {
		$this->_name = __('Delete User', 'ai-copilot-content-generator');
		//$this->_desc = __('Only filled fields will be updated', 'ai-copilot-content-generator');
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
				'label' => __('User ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'reassign' => array(
				'type' => 'input',
				'label' => __('Reassign posts to User ID', 'ai-copilot-content-generator'),
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
			'success' => __('Is Successfully?', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$userId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($userId)) {
			$error = 'User ID needed';
		} else {
			if (!function_exists('wp_delete_user')) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$reassign = (int) $this->replaceVariables($this->getParam('reassign'), $variables);
			$result = wp_delete_user($userId, empty($reassign) ? null : $reassign);
			if (!$result) {
				$error = 'Failed to delete user';
			}
		}

		$this->_results = array(
			'result' => array('success' => empty($error) ? 1 : 0),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
