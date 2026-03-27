<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ca_create_meeting extends WaicAction {
	protected $_code = 'ca_create_meeting';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create Meeting', 'ai-copilot-content-generator');
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
		$providers = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('calendar');
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
			'title' => array(
				'type' => 'input',
				'label' => __('Title', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'description' => array(
				'type' => 'input',
				'label' => __('Description', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'start_date' => array(
				'type' => 'date',
				'label' => __('Select Start date and time', 'ai-copilot-content-generator'),
				'default' => '',
				'add' => array('start_time'),
			),
			'start_time' => array(
				'type' => 'time',
				'label' => '',
				'default' => '',
				'inner' => true,
			),
			'start_dt' => array(
				'type' => 'input',
				'label' => __('Or Enter Start DateTime', 'ai-copilot-content-generator'),
				'default' => '',
				'plh' => 'YYYY-MM-DD hh:mm',
				'variables' => true,
			),
			'duration' => array(
				'type' => 'number',
				'label' => __('Duration', 'ai-copilot-content-generator'),
				'default' => '30',
				'add' => array('units'),
			),
			'units' => array(
				'type' => 'select',
				'label' => '',
				'default' => 'm',
				'options' => array('m' => 'Minutes', 'h' => 'Hours', 'd' => 'Days'),
				'inner' => true,
			),
			'zone' => array(
				'type' => 'select',
				'label' => __('Time Zone', 'ai-copilot-content-generator'),
				'default' => wp_timezone_string(),
				'options' => $this->getTimeZones(),
				'variables' => true,
			),
			'attendees' => array(
				'type' => 'textarea',
				'label' => __('Attendees (emails separated with commas)', 'ai-copilot-content-generator'),
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
			'meet_id' => __('Meeting ID', 'ai-copilot-content-generator'), 
			'meet_link' => __('Meeting Link', 'ai-copilot-content-generator'), 
			'event_id' => __('Event ID', 'ai-copilot-content-generator'),
			'event_status' => __('Event Status', 'ai-copilot-content-generator'),
			'event_link' => __('Event Link', 'ai-copilot-content-generator'),
			'event_created' => __('Event Created', 'ai-copilot-content-generator'),
			'event_start' => __('Event Start (ISO 8601)', 'ai-copilot-content-generator'),
			'event_end' => __('Event End (ISO 8601)', 'ai-copilot-content-generator'),
			'start' => __('Event Start (YYYY-MM-DD hh:mm)', 'ai-copilot-content-generator'),
			'end' => __('Event End (YYYY-MM-DD hh:mm)', 'ai-copilot-content-generator'),
			'duration' => __('Duration (minutes)', 'ai-copilot-content-generator'),
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
			$attendees = $this->replaceVariables($this->getParam('attendees'), $variables);
			$start = trim($this->replaceVariables($this->getParam('start_dt'), $variables));
			if (empty($start)) {
				$start = trim($this->getParam('start_date', WaicUtils::getFormatedDateTime(WaicUtils::getTimestamp(), 'Y-m-d')) . ' ' . $this->getParam('start_time', '00:00'));
			}
			
			if (!WaicUtils::checkDateTimeFormat($start, 'Y-m-d H:i')) {
				$error = 'Error Start DateTime format';
			} else {
				$duration = $this->getDurationInMinutes($this->getParam('duration'), $this->getParam('units'));
				
				$data = array(
					'title' => $this->replaceVariables($this->getParam('title'), $variables),
					'description' => $this->replaceVariables($this->getParam('description'), $variables),
					'start' => $start,
					'duration' => $duration,
					'end' => WaicUtils::addInterval($start, (int) $duration, 'minutes'),
					'attendees' => empty($attendees) ? array() : explode(',', $attendees),
					'tz' => $this->getParam('zone'),
				);
				if (empty($data['start']) && strlen($data['start']) < 10) {
					$error = 'Start is required';
				} else {
					$result = $integration->doCreateMeeting($data);
					$error = empty($result['error']) ? '' : $result['error'];
					unset($result['error']);
				}
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
