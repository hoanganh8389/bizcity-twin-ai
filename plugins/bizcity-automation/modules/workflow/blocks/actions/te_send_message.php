<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_te_send_message extends WaicAction {
	protected $_code = 'te_send_message';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Message', 'ai-copilot-content-generator');
		$this->_desc = __('Send a message to Telegram', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$accounts = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('messenger', 'telegram');
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
				'label' => __('Text', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'rows' => 5,
				'html' => true,
				'variables' => true,
			),
			'parse_mode' => array(
				'type' => 'select',
				'label' => __('Parse Mode', 'ai-copilot-content-generator'),
				'options' => array(
					'' => __('None (plain text)', 'ai-copilot-content-generator'),
					'HTML' => 'HTML',
					'Markdown' => 'Markdown',
					'MarkdownV2' => 'MarkdownV2',
				),
				'default' => '',
			),
			'disable_web_page_preview' => array(
				'type' => 'select',
				'label' => __('Disable Web Page Preview', 'ai-copilot-content-generator'),
				'options' => array(
					'0' => __('No (show link previews)', 'ai-copilot-content-generator'),
					'1' => __('Yes (hide link previews)', 'ai-copilot-content-generator'),
				),
				'default' => '0',
			),
			'disable_notification' => array(
				'type' => 'select',
				'label' => __('Disable Notification', 'ai-copilot-content-generator'),
				'options' => array(
					'0' => __('No (send with sound)', 'ai-copilot-content-generator'),
					'1' => __('Yes (send silently)', 'ai-copilot-content-generator'),
				),
				'default' => '0',
			),
			'reply_to_message_id' => array(
				'type' => 'input',
				'label' => __('Reply To Message ID', 'ai-copilot-content-generator'),
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
			'success' => __('Message Sent Successfully', 'ai-copilot-content-generator'),
			'message_id' => __('Message ID', 'ai-copilot-content-generator'),
			'chat_id' => __('Chat ID', 'ai-copilot-content-generator'),
			'chat_type' => __('Chat Type (private/group/supergroup/channel)', 'ai-copilot-content-generator'),
			'date' => __('Message Date (Unix timestamp)', 'ai-copilot-content-generator'),
			'text' => __('Message Text', 'ai-copilot-content-generator'),
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
				if ('telegram' !== $integCode) {
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
			} else if (WaicUtils::mbstrlen($message) > 4096) {
				$error = 'Message text is too long (max 4096 characters)';
			}
		}
		if (empty($error) && $integration) {
			$data = array('text' => $message);
			$parseMode = $this->getParam('parse_mode');
			if (!empty($parseMode)) {
				$data['parse_mode'] = $parseMode;
			}
			$preview = $this->getParam('disable_web_page_preview');
			if (!empty($preview)) {
				$data['disable_web_page_preview'] = $preview;
			}
			$notification = $this->getParam('disable_notification');
			if (!empty($notification)) {
				$data['disable_notification'] = $notification;
			}
			$reply = $this->replaceVariables($this->getParam('reply_to_message_id'), $variables);
			if (!empty($reply)) {
				$data['reply_to_message_id'] = $reply;
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
