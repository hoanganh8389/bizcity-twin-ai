<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_un_delay extends WaicLogic {
	protected $_code = 'un_delay';
	protected $_subtype = 1;
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Delay (Đợi)', 'ai-copilot-content-generator');
		$this->_desc = __('Lặp lại trong một khoảng thời gian hoặc đến một thời điểm cụ thể', 'ai-copilot-content-generator');
		$this->_sublabel = array('days', 'hours', 'minutes', 'date', 'time', 'week_day', 'week_time', 'next_time');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$this->_settings = array(
			'mode' => array(
				'type' => 'select',
				'label' => __('Delay Type', 'ai-copilot-content-generator'),
				'default' => 'amount',
				'options' => array(
					'amount' => __('For a set amount of time', 'ai-copilot-content-generator'),
					'date' => __('Until a calendar date', 'ai-copilot-content-generator'),
					'week' => __('Until a day of the week', 'ai-copilot-content-generator'),
					'time' => __('Until a specific time of day', 'ai-copilot-content-generator'),
				),
			),
			'days' => array(
				'type' => 'number',
				'label' => __('Days', 'ai-copilot-content-generator'),
				'ldesc_after' => __('days', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
				'show' => array('mode' => array('amount')),
			),
			'hours' => array(
				'type' => 'number',
				'label' => __('Hours', 'ai-copilot-content-generator'),
				'ldesc_after' => __('hours', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
				'show' => array('mode' => array('amount')),
			),
			'minutes' => array(
				'type' => 'number',
				'label' => __('Minutes', 'ai-copilot-content-generator'),
				'ldesc_after' => __('minutes', 'ai-copilot-content-generator'),
				'default' => 2,
				'min' => 2,
				'show' => array('mode' => array('amount')),
			),
			'date' => array(
				'type' => 'date',
				'label' => __('Select date and time', 'ai-copilot-content-generator'),
				'ldesc' => 'On',
				'default' => WaicUtils::getFormatedDateTime(WaicUtils::getTimestamp(), 'Y-m-d'),
				'show' => array('mode' => array('date')),
				'add' => array('time'),
			),
			'time' => array(
				'type' => 'time',
				'label' => '',
				'ldesc' => __('at', 'ai-copilot-content-generator'),
				'default' => '00:00',
				'show' => array('mode' => array('date')),
				'inner' => true,
			),
			'week_day' => array(
				'type' => 'select',
				'label' => __('Select day of and time', 'ai-copilot-content-generator'),
				'ldesc' => 'On',
				'default' => 'monday',
				'options' => array(
					'monday' => __('Monday', 'ai-copilot-content-generator'),
					'tuesday' => __('Tuesday', 'ai-copilot-content-generator'),
					'wednesday' => __('Wednesday', 'ai-copilot-content-generator'),
					'thursday' => __('Thursday', 'ai-copilot-content-generator'),
					'friday' => __('Friday', 'ai-copilot-content-generator'),
					'saturday' => __('Saturday', 'ai-copilot-content-generator'),
					'sunday' => __('Sunday', 'ai-copilot-content-generator'),
				),
				'show' => array('mode' => array('week')),
				'add' => array('week_time'),
			),
			'week_time' => array(
				'type' => 'time',
				'label' => '',
				'ldesc' => __('at', 'ai-copilot-content-generator'),
				'default' => '00:00',
				'show' => array('mode' => array('week')),
				'inner' => true,
			),
			'next_time' => array(
				'type' => 'time',
				'label' => '',
				'ldesc' => __('At ', 'ai-copilot-content-generator'),
				'default' => '00:00',
				'show' => array('mode' => array('time')),
			),
		);
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
			$waiting = 0;
			$mode = $this->getParam('mode');
			$now = WaicUtils::getTimestamp();
			switch ($mode) {
				case 'amount':
					$waiting = $this->getParam('days', 0, 1) * 86400 + $this->getParam('hours', 0, 1) * 3600 + $this->getParam('minutes', 0, 1) * 60;
					if ($waiting > 0) {
						$waiting += $now;
					}
					break;
				case 'date':
					$d = $this->getParam('date');
					$timeOfDay = $this->getParam('time');
					$waiting = strtotime("$d $timeOfDay");
					if ($waiting < $now) {
						$waiting = 0;
					}
					break;
				case 'week':
					$dayOfWeek = $this->getParam('week_day');
					$timeOfDay = $this->getParam('week_time');
					if (strtolower(date('l', $now)) === strtolower($dayOfWeek) && $now < strtotime("today $timeOfDay")) {
						$waiting = strtotime("this $dayOfWeek $timeOfDay");
					} else {
						$waiting = strtotime("next $dayOfWeek $timeOfDay");
					}
					break;
				case 'time':
					$timeOfDay = $this->getParam('next_time');
					$waiting = strtotime("today $timeOfDay");
					if ($waiting < $now) {
						$waiting = strtotime("tomorrow $timeOfDay");
					}
					break;
			}

			// Tạo cron event để resume workflow đúng thời điểm
			if ($waiting > $now) {
				$run_id = isset($this->_runId) ? $this->_runId : 0;
				if ($run_id) {
					$event_hook = 'waic_resume_workflow_' . $run_id;
					if (!wp_next_scheduled($event_hook, array($run_id))) {
						wp_schedule_single_event($waiting, $event_hook, array($run_id));
					}
					// Đăng ký callback nếu chưa có
					add_action($event_hook, function($run_id) {
						// Gọi lại hàm resume workflow (giả sử có hàm này)
						if (function_exists('waic_resume_workflow')) {
							waic_resume_workflow($run_id);
						}
						// Xoá event sau khi chạy xong
						wp_clear_scheduled_hook('waic_resume_workflow_' . $run_id, array($run_id));
					}, 10, 1);
				}
			}

			$this->_results = array(
				'result' => array(),
				'error' => '',
				'status' => 0,
				'waiting' => $waiting,
			);
			return $this->_results;
	}
}
