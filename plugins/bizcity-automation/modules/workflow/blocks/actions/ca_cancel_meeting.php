<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ca_cancel_meeting extends WaicAction {
	protected $_code = 'ca_cancel_meeting';
	protected $_order = 2;
	
	public function __construct( $block = null ) {
		$this->_name = __('Cancel Meeting', 'ai-copilot-content-generator');
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
		$providers = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('calendar', 'zoom');
		if (empty($providers)) {
			$providers = array('' => __('No connected providers found', 'ai-copilot-content-generator'));
		}
		$keys = array_keys($providers);
		
		$this->_settings = array(
			'provider' => array(
				'type' => 'select',
				'label' => __('Provider', 'ai-copilot-content-generator') . ' *',
				'options' => $providers,
				'default' => $keys[0],
			),
			'meeting_id' => array(
				'type' => 'input',
				'label' => __('Meeting ID', 'ai-copilot-content-generator') . ' *',
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
			'provider' => __('Provider', 'ai-copilot-content-generator'),
			'meeting_id' => __('Event ID', 'ai-copilot-content-generator'),
			'success' => __('Success', 'ai-copilot-content-generator'),
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
			$meetingId = trim($this->replaceVariables($this->getParam('meeting_id'), $variables));
			if (empty($meetingId)) {
				$error = 'Meeting ID is required';
			} else {
				$data = array('meeting_id' => $meetingId);
				$result = $integration->doDeleteMeeting($data);
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
