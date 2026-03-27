<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_sy_schedule extends WaicTrigger {
	protected $_code = 'sy_schedule';
	protected $_subtype = 1;
	
	public function __construct( $block = false ) {
		parent::__construct();
		$this->_name = __('Schedule', 'ai-copilot-content-generator');
		$this->_desc = __('Trigger activated based on a specific time or recurring interval.', 'ai-copilot-content-generator');
		$this->_sublabel = array('mode', 'date', 'time', 'frequency', 'units', 'from_date', 'from_time');
		$this->setBlock($block); 
	}
	
	public function getSchStart() {
		if ($this->getParam('mode') == 'one') {
			$start = $this->getParam('date') . ' ' . $this->getParam('time');
		} else {
			$start = $this->getParam('from_date') . ' ' . $this->getParam('from_time');
		}
		if (strlen($start) == 16) {
			$start .= ':00';
		} else {
			$start = null;
		}
		return $start;
	}
	public function getPeriod( $settings = array() ) {
		if ($this->getParam('mode') == 'one') {
			return 0;
		}
		$k = 86400; // one day
		$frequency = $this->getParam('frequency', 0, 1);
		if ($frequency <= 0) {
			return $k;
		}
		$units = $this->getParam('units');
		if ('m' == $units) {
			$k = 60; 
		} else if ('h' == $units) {
			$k = 3600; 
		}
		return $frequency * $k ;
	}
	
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$now = WaicUtils::getFormatedDateTime(WaicUtils::getTimestamp(), 'Y-m-d');
		$this->_settings = array(
			'mode' => array(
				'type' => 'select',
				'label' => __('Mode', 'ai-copilot-content-generator'),
				'options' => array('one' => 'At specific time', 'period' => 'Every period'),
				'ndesc' => array('one' => 'Once on', 'period' => 'Every'),
				'default' => 'one',
			),
			'date' => array(
				'type' => 'date',
				'label' => __('Select time', 'ai-copilot-content-generator'),
				'ldesc' => ' \n',
				'default' => $now,
				'show' => array('mode' => array('one')),
				'add' => array('time'),
			),
			'time' => array(
				'type' => 'time',
				'label' => '',
				'ldesc' => __('at', 'ai-copilot-content-generator'),
				'default' => '00:00',
				'show' => array('mode' => array('one')),
				'inner' => true,
			),
			'frequency' => array(
				'type' => 'number',
				'label' => __('Frequency', 'ai-copilot-content-generator'),
				'default' => '12',
				'show' => array('mode' => array('period')),
				'add' => array('units'),
			),
			'units' => array(
				'type' => 'select',
				'label' => '',
				'default' => 'd',
				'options' => array('d' => 'Days', 'h' => 'Hours', 'm' => 'Minutes'),
				'ndesc' => array('d' => 'days', 'h' => 'hours', 'm' => 'minutes'),
				'inner' => true,
			),
			'from_date' => array(
				'type' => 'date',
				'label' => __('Starting from', 'ai-copilot-content-generator'),
				'ldesc' => ' \n' . __('from', 'ai-copilot-content-generator'),
				'default' => $now,
				'show' => array('mode' => array('period')),
				'add' => array('from_time'),
			),
			'from_time' => array(
				'type' => 'time',
				'label' => '',
				'default' => '00:00',
				'show' => array('mode' => array('period')),
				'inner' => true,
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
		$this->_variables = $this->getDTVariables();
		return $this->_variables;
	}
}
