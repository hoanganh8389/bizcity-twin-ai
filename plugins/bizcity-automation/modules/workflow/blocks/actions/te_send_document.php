<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_te_send_document extends WaicAction {
	protected $_code = 'te_send_document';
	protected $_order = 4;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Document', 'ai-copilot-content-generator');
		$this->_desc = __('Send Document to Telegram', 'ai-copilot-content-generator');
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
			'mode' => array(
				'type' => 'select',
				'label' => __('Mode', 'ai-copilot-content-generator') . ' *',
				'options' => array(
					'url' => __('Url', 'ai-copilot-content-generator'),
					'file_id' => __('Telegram File Id', 'ai-copilot-content-generator'),
				),
				'default' => 'url',
			),
			'file_id' => array(
				'type' => 'input',
				'label' => __('Document File Id', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
				'show' => array('mode' => array('file_id')),
			),
			'url' => array(
				'type' => 'input',
				'label' => __('Document Url', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
				'show' => array('mode' => array('url')),
			),
			'filename' => array(
				'type' => 'input',
				'label' => __('Document Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
				'show' => array('mode' => array('url')),
			),
			'caption' => array(
				'type' => 'textarea',
				'label' => __('Caption', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
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
			'success' => __('Photo Sent Successfully', 'ai-copilot-content-generator'),
			'message_id' => __('Message ID', 'ai-copilot-content-generator'),
			'chat_id' => __('Chat ID', 'ai-copilot-content-generator'),
			'chat_type' => __('Chat Type (private/group/supergroup/channel)', 'ai-copilot-content-generator'),
			'date' => __('Message Date (Unix timestamp)', 'ai-copilot-content-generator'),
			'caption' => __('Caption Text', 'ai-copilot-content-generator'),
			'file_id' => __('File ID', 'ai-copilot-content-generator'),
			'file_name' => __('File Name', 'ai-copilot-content-generator'),
			'mime_type' => __('File mime-type', 'ai-copilot-content-generator'),
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
		$data = array();
		if (empty($error)) {
			$mode = $this->getParam('mode');
			$data['mode'] = $mode;
			if ('file_id' == $mode) {
				$fileId = $this->replaceVariables($this->getParam('file_id'), $variables);
				if (empty($fileId)) {
					$error = 'Document file_id is required';
				}
				$data['document'] = $fileId;
			} else {
				$url = $this->replaceVariables($this->getParam('url'), $variables);
				if (empty($url)) {
					$error = 'Document Url is required';
				}
				$data['document'] = $url;
				$data['filename'] = $this->replaceVariables($this->getParam('filename'), $variables);
			}
		}
		if (empty($error)) {
			$caption = $this->replaceVariables($this->getParam('caption'), $variables);
			if (!empty($caption) && WaicUtils::mbstrlen($caption) > 1024) {
				$error = 'Caption text is too long (max 1024 characters)';
			}
		}
		if (empty($error) && $integration) {
			if (!empty($caption)) {
				$data['caption'] = $caption;
			}
			$notification = $this->getParam('disable_notification');
			if (!empty($notification)) {
				$data['disable_notification'] = $notification;
			}
			$reply = $this->replaceVariables($this->getParam('reply_to_message_id'), $variables);
			if (!empty($reply)) {
				$data['reply_to_message_id'] = $reply;
			}
			$result = $integration->doSendDocument($data);
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
