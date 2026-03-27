<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wu_workflow_ended extends WaicTrigger {
	protected $_code = 'wu_workflow_ended';
	protected $_hook = 'waic_afterWorkflowEnded';
	protected $_subtype = 2;
	protected $_order = 3;
	
	public function __construct( $block = null ) {
		$this->_name = __('Workflow completed', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$task = WaicFrame::_()->getModule('workspace')->getModel('tasks');
		$workflow = WaicFrame::_()->getModule('workflow')->getModel();
		$this->_settings = array(
			'workflow' => array(
				'type' => 'select',
				'label' => __('Select Workflow', 'ai-copilot-content-generator'),
				'options' => $task->getTasksList(array('feature' => 'workflow', 'additionalCondition' => 'id!=' . $this->getId())),
				'default' => '',
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Status', 'ai-copilot-content-generator'),
				'options' => array_merge(array(0 => ''), $workflow->getStatuses()),
				'default' => 0,
			),
			'count' => array(
				'type' => 'number',
				'label' => __('Run count (0 - no limit)', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
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
		$this->_variables = array_merge(
			$this->getDTVariables(),
			array('run_number' => __('Run number', 'ai-copilot-content-generator')),
			$this->getWorkflowVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 1) {
			return false;
		}
		$runId = $args[0];

		$runModel = WaicFrame::_()->getModule('workflow')->getModel('flowruns');
		$run = $runModel->getById($runId);
		if (empty($run)) {
			return false;
		}
		$taskId = $run['task_id'];
		
		$flow = $this->getParam('workflow', 0, 1);
		if (!empty($flow)) {
			if ($taskId != $flow) {
				return false;
			}
		}

		$status = $this->getParam('status', 0, 1);
		if (!empty($status)) {
			if ($run['status'] != $status) {
				return false;
			}
		}
		$count = $this->getParam('count', 0, 1);
		$countRuns = $runModel->getCountRunForObj($this->_flowId, $taskId);
		if (!empty($count)) {
			if ($countRuns >= $count) {
				return false;
			}
		}
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'run_number' => $countRuns + 1, 'waic_run_id' => $runId, 'obj_id' => $taskId);
	}
	
}
