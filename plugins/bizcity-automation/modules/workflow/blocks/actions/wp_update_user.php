<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_user extends WaicAction {
	protected $_code = 'wp_update_user';
	protected $_order = 8;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update User', 'ai-copilot-content-generator');
		$this->_desc = __('Only filled fields will be updated', 'ai-copilot-content-generator');
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
				'label' => __('User ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'user_email' => array(
				'type' => 'input',
				'label' => __('Email', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'user_pass' => array(
				'type' => 'input',
				'label' => __('Password', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'role' => array(
				'type' => 'select',
				'label' => __('Role', 'ai-copilot-content-generator'),
				'default' => 'subscriber',
				'options' => array_merge(array('' => ''), $this->getRolesList()),
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
		$error = '';
		$userId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($userId)) {
			$error = 'User ID needed';
		} else {
			$user = array('ID' => $userId);
			$email = $this->replaceVariables($this->getParam('user_email'), $variables);
			if (!empty($email)) {
				$user['user_email'] = $email;
			}
			$pass = $this->replaceVariables($this->getParam('user_pass'), $variables);
			if (!empty($pass)) {
				$user['user_pass'] = $pass;
			}
			$display = $this->replaceVariables($this->getParam('display_name'), $variables);
			if (!empty($display)) {
				$user['display_name'] = $display;
			}
			$first = $this->replaceVariables($this->getParam('first_name'), $variables);
			if (!empty($first)) {
				$user['first_name'] = $first;
			}
			$last = $this->replaceVariables($this->getParam('last_name'), $variables);
			if (!empty($last)) {
				$user['last_name'] = $last;
			}
			$nickname = $this->replaceVariables($this->getParam('nickname'), $variables);
			if (!empty($nickname)) {
				$user['nickname'] = $nickname;
			}
			$role = $this->getParam('role');
			if (!empty($role)) {
				$user['role'] = $role;
			}
			$userId = wp_update_user($user);
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
