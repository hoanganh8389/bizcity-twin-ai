<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_create_user extends WaicAction {
	protected $_code = 'wp_create_user';
	protected $_order = 7;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create User', 'ai-copilot-content-generator');
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
			'user_login' => array(
				'type' => 'input',
				'label' => __('Username', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'user_email' => array(
				'type' => 'input',
				'label' => __('Email', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'user_pass' => array(
				'type' => 'input',
				'label' => __('Password', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'role' => array(
				'type' => 'select',
				'label' => __('Role', 'ai-copilot-content-generator'),
				'default' => 'subscriber',
				'options' => $this->getRolesList(),
			),
			'display_name' => array(
				'type' => 'input',
				'label' => __('Display Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'first_name' => array(
				'type' => 'input',
				'label' => __('First Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'last_name' => array(
				'type' => 'input',
				'label' => __('Last Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'nickname' => array(
				'type' => 'input',
				'label' => __('Nickname', 'ai-copilot-content-generator'),
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
		$this->_variables = $this->getUserVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$user = array(
			'user_login' => $this->replaceVariables($this->getParam('user_login'), $variables),
			'user_email' => $this->replaceVariables($this->getParam('user_email'), $variables),
			'user_pass' => $this->replaceVariables($this->getParam('user_pass'), $variables),
			'display_name' => $this->replaceVariables($this->getParam('display_name'), $variables),
			'first_name' => $this->replaceVariables($this->getParam('first_name'), $variables),
			'last_name' => $this->replaceVariables($this->getParam('last_name'), $variables),
			'nickname' => $this->replaceVariables($this->getParam('nickname'), $variables),
			'role' => $this->getParam('role', 'subscriber'),
		);
		$error = '';
		if (empty($user['user_login'])) {
			$error = 'Username needed';
		} else if (empty($user['user_email'])) {
			$error = 'Email needed';
		} else if (empty($user['user_pass'])) {
			$error = 'Password needed';
		}

		$userId = 0; 
		if (empty($error)) {
			$userId = wp_insert_user($user);
			if (is_wp_error($userId)) {
				$error = $userId->get_error_message();
			}
		}

		$this->_results = array(
			'result' => array('waic_user_id' => (int) $userId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
