<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_sl_send_message extends WaicAction {
	protected $_code = 'sl_send_message';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Message', 'ai-copilot-content-generator');
		$this->_desc = __('Send a message to Slack via webhook or using API access', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$accounts = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('messenger', 'slack');
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
			'channel' => array(
				'type' => 'input',
				'label' => __('Channel (only for API)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'thread_ts' => array(
				'type' => 'input',
				'label' => __('Thread TS (only for API)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			/*'username' => array(
				'type' => 'input',
				'label' => __('Bot Username', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'icon_emoji' => array(
				'type' => 'input',
				'label' => __('Icon Emoji (e.g., :robot_face:)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => false,
			),*/
			'message' => array(
				'type' => 'textarea',
				'label' => __('Message *', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 6,
				'html' => true,
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
			'success' => __('Message Sent Successfully', 'ai-copilot-content-generator'),
			'method' => __('Sent Method (webhook, api)', 'ai-copilot-content-generator'),
			'message_ts' => __('Slack Message Timestamp', 'ai-copilot-content-generator'),
			'channel_id' => __('Channel ID', 'ai-copilot-content-generator'),
			'message' => __('Message Content', 'ai-copilot-content-generator'),
			'permalink' => __('Message Permalink', 'ai-copilot-content-generator'),
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
				if ('slack' !== $integCode) {
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
		if ($integration) {
			$data = array(
				'channel' => $this->replaceVariables($this->getParam('channel'), $variables),
				'thread_ts' => $this->replaceVariables($this->getParam('thread_ts'), $variables),
				'icon_emoji' => $this->replaceVariables($this->getParam('icon_emoji'), $variables),
				'username' => $this->replaceVariables($this->getParam('username'), $variables),
				'message' => $this->replaceVariables($this->getParam('message'), $variables),
			);
			if (empty($data['message'])) {
				$error = 'The Message is empty';
			} else {
				$result = $integration->doSendMessage($data);
				$error = empty($result['error']) ? '' : $result['error'];
				unset($result['error']);
			}
		}
		if (empty($error)) {
			$result['success'] = 1;
		}
		//$result = empty($error) ? array_merge($data, $result) : array();
		
		$this->_results = array(
			'result' => $result,
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
