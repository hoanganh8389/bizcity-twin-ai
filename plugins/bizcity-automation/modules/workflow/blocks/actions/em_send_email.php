<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_em_send_email extends WaicAction {
	protected $_code = 'em_send_email';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Email', 'ai-copilot-content-generator');
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
		$providers = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('email');
		if (empty($providers)) {
			$providers = array('' => __('No connected providers found', 'ai-copilot-content-generator'));
		}
		$keys = array_keys($providers);
		
		$this->_settings = array(
			'provider' => array(
				'type' => 'select',
				'label' => __('Provider *', 'ai-copilot-content-generator'),
				'options' => $providers,
				'default' => $keys[0],
			),
			'to' => array(
				'type' => 'input',
				'label' => __('To Email *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'to_name' => array(
				'type' => 'input',
				'label' => __('To Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'cc' => array(
				'type' => 'input',
				'label' => __('CC', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'bcc' => array(
				'type' => 'input',
				'label' => __('BCC', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'reply' => array(
				'type' => 'input',
				'label' => __('Reply-To', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'subject' => array(
				'type' => 'input',
				'label' => __('Subject', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'body' => array(
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
			'provider' => __('Provider', 'ai-copilot-content-generator'),
			'from' => __('From Email', 'ai-copilot-content-generator'),
			'to' => __('To Email', 'ai-copilot-content-generator'),
			'to_name' => __('From Name', 'ai-copilot-content-generator'),
			'cc' => __('CC', 'ai-copilot-content-generator'),
			'bcc' => __('BCC', 'ai-copilot-content-generator'),
			'reply' => __('Reply-To', 'ai-copilot-content-generator'),
			'subject' => __('Subject', 'ai-copilot-content-generator'),
			'message' => __('Message', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$provider = $this->getParam('provider');
		
		$integration = false;
		if (empty($provider)) {
			$error = 'Provider is empty';
		} else {
			$parts = explode('-', $provider);
			if (count($parts) != 2) {
				$error = 'Provider settings error';
			} else {
				$integCode = $parts[0];
				$accountNum = (int) $parts[1];
				$integration = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegration($integCode, $accountNum);
				if (!$integration) {
					$error = 'Provider account not found';
				}
			}
		}
		$result = array();
		if ($integration) {
			$data = array(
				'to' => $this->replaceVariables($this->getParam('to'), $variables),
				'to_name' => $this->replaceVariables($this->getParam('to_name'), $variables),
				'cc' => $this->replaceVariables($this->getParam('cc'), $variables),
				'bcc' => $this->replaceVariables($this->getParam('bcc'), $variables),
				'reply' => $this->replaceVariables($this->getParam('reply'), $variables),
				'subject' => $this->replaceVariables($this->getParam('subject'), $variables),
				'message' => $this->replaceVariables($this->getParam('body'), $variables),
			);
			if (empty($data['to'])) {
				$error = 'To Email is empty';
			} else if (empty($data['message'])) {
				$error = 'The Message is empty';
			} else {
				if (empty($data['subject'])) {
					$data['subject'] = 'From ' . get_bloginfo('name');
				}			
				$result = $integration->doSendEmail($data);
				$error = empty($result['error']) ? '' : $result['error'];
				unset($result['error']);
			}
		}
		$result = empty($error) ? array_merge($data, $result) : array();
		
		$this->_results = array(
			'result' => $result,
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
