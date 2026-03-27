<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_user_register extends WaicTrigger {
	protected $_code = 'wp_user_register';
	protected $_hook = 'user_register';
	protected $_subtype = 2;
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('New user registered', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
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
		$capabilities = array('' => '');
		$roles = array('' => '');

		foreach ($wp_roles->roles as $roleName => $roleData) {
			$roles[$roleName] = $roleName;
			foreach ($roleData['capabilities'] as $cap => $value) {
				$capabilities[$cap] = $cap;
			}
		}

		$this->_settings = array(
			'login' => array(
				'type' => 'input',
				'label' => __('User Login', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'name' => array(
				'type' => 'input',
				'label' => __('User Name contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'email' => array(
				'type' => 'input',
				'label' => __('Email contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'role' => array(
				'type' => 'select',
				'label' => __('User role', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $roles,
			),
			'capability' => array(
				'type' => 'select',
				'label' => __('User capability', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $capabilities,
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
		$this->_variables = $this->_variables = array_merge($this->getDTVariables(), $this->getUserVariables());
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 2) {
			return false;
		}
		$userId = $args[0];
		$user = $args[1];
		
		$login = $this->getParam('login', 0, 1);
		if (!empty($login)) {
			if ($uLogin !== $login) {
				return false;
			}
		}
		$email = $this->getParam('email');
		if (!empty($email)) {
			if (WaicUtils::mbstrpos($user->user_email, $email) === false) {
				return false;
			}
		} 
		$name = $this->getParam('name');
		if (!empty($name)) {
			if (WaicUtils::mbstrpos($user->user_nicename, $name) === false && WaicUtils::mbstrpos($user->display_name, $name)) {
				return false;
			}
		}
		$role = $this->getParam('role');
		if (!empty($email)) {
			if (!in_array($role, $user->roles)) {
				return false;
			}
		} 
		$capability = $this->getParam('capability');
		if (!empty($capability)) {
			if (!$user->has_cap($capability)) {
				return false;
			}
		}

		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_user_id' => $userId, 'obj_id' => $userId);
	}
}
