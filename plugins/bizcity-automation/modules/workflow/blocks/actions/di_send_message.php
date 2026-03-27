<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_di_send_message extends WaicAction {
	protected $_code = 'di_send_message';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Message', 'ai-copilot-content-generator');
		$this->_desc = __('Send message to Discord channel', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$accounts = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('messenger', 'discord');
		if (empty($accounts)) {
			$accounts = array('' => __('No connected accounts found', 'ai-copilot-content-generator'));
		}
		$keys = array_keys($accounts);
		
		$this->_settings = array(
			'account' => array(
				'type' => 'select',
				'label' => __('Account', 'ai-copilot-content-generator') . ' *',
				'options' => $accounts,
				'default' => $keys[0],
			),
			'message' => array(
				'type' => 'textarea',
				'label' => __('Message (max 2000)', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'rows' => 5,
				'variables' => true,
			),
			'username' => array(
				'type' => 'input',
				'label' => __('Custom Username', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'avatar_url' => array(
				'type' => 'input',
				'label' => __('Custom Avatar URL', 'ai-copilot-content-generator'),
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
			'success' => __('Operation Success', 'ai-copilot-content-generator'),
			'content' => __('Message content', 'ai-copilot-content-generator'),
			'username' => __('Username', 'ai-copilot-content-generator'),
			'avatar_url' => __('Avatar Url', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$account = $this->getParam('account');
		
		$integration = false;
		if (empty($account)) {
			$error = 'Account is empty';
		} else {
			$parts = explode('-', $account);
			if (count($parts) != 2) {
				$error = 'Account settings error';
			} else {
				$integCode = $parts[0];
				if ('discord' !== $integCode) {
					$error = 'Account code unacceptable';
				} else {
					$accountNum = (int) $parts[1];
					$integration = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegration($integCode, $accountNum);
					if (!$integration) {
						$error = 'Intergation account not found';
					}
				}
			}
		}
		$result = array();
		if (empty($error)) {
			$message = $this->replaceVariables($this->getParam('message'), $variables);
			if (empty($message)) {
				$error = 'The Message is empty';
			} else if (WaicUtils::mbstrlen($message) > 2000) {
				$error = 'Message text is too long (max 2000 characters)';
			}
		}
		if (empty($error) && $integration) {
			$data = array('content' => $message);
			$username = $this->replaceVariables($this->getParam('username'), $variables);
			if (!empty($username)) {
				$data['username'] = $username;
			}
			$avatar = $this->replaceVariables($this->getParam('avatar_url'), $variables);
			if (!empty($avatar)) {
				$data['avatar_url'] = $avatar;
			}
			$result = $integration->doSendMessage($data);
			$error = empty($result['error']) ? '' : $result['error'];
			unset($result['error']);
		}
		if (empty($error)) {
			$result['success'] = 1;
		}
		
		$this->_results = array(
			'result' => $result,
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
