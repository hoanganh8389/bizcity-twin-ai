<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicFlowlogsModel extends WaicModel {
	private $_statuses = null;
	public function __construct() {
		$this->_setTbl('flowlogs');
	}
	
	public function getStatuses( $st = null ) {
		if (is_null($this->_statuses)) {
			$this->_statuses = array(
				0 => __('Running', 'ai-copilot-content-generator'), 
				3 => __('Success', 'ai-copilot-content-generator'),
				7 => __('Error', 'ai-copilot-content-generator'),
				9 => __('Canceled', 'ai-copilot-content-generator'),
			);
		}
		return is_null($st) ? $this->_statuses : ( isset($this->_statuses[$st]) ? $this->_statuses[$st] : '' );
	}
	
	public function clearLogs( $runId, $logId ) {
		$this->delete(array('run_id' => $runId, 'additionalCondition' => 'id>' . $logId));
	}
	
	public function createLog( $runId, $blId, $blCode, $blType, $parent = 0, $step = 0 ) {
		$log = array(
			'run_id' => ( (int) $runId ),
			'bl_id' => ( (int) $blId ),
			'bl_code' => substr($blCode, 0, 30),
			'bl_type' => ( (int) $blType ),
			'parent' => ( (int) $parent ),
			'step' => ( (int) $step ),
			'started' => WaicUtils::getTimestampDB(),
		);
		$id = $this->insert($log);
		return empty($id) ? 0 : $id;
	}
	
	public function updateLog( $logId, $data ) {
		if (!empty($data['status']) && ( 3 == $data['status'] )) {
			$data['ended'] = WaicUtils::getTimestampDB();
		}
		if (!empty($data['error'])) {
			$data['error'] = addslashes(substr($data['error'], 0, 500)); 
		}
		if (isset($data['result'])) {
			$data['result'] = empty($data['result']) ? '' : str_replace("'", '`', WaicUtils::jsonEncode($data['result'], true));
		}
		$this->updateById($data, $logId);
		return true;
	}
	
	public function getLog( $logId ) {
		$log = $this->getById($logId);
		if (!empty($log['result'])) {
			$log['result'] = WaicUtils::jsonDecode($log['result']);
		}
		return $log;
	}
	
	public function getResults( $runId, $parent = 0, $step = 0, $logId = false) {
		$where = array('status' => 3, 'parent' => $parent, 'step' => $step);
		if (!empty($logId)) {
			$where['id'] = $logId;
		}
		if (!empty($runId)) {
			$where['run_id'] = $runId;
		}
		$results = array();
		$logs = $this->setSelectFields('bl_id, result')->setWhere($where)->getFromTbl();
		if ($logs) {
			$workflow = $this->getModule()->getModel('workflow');
			foreach ($logs as $log) {
				$results['node#' . $log['bl_id']] = ( empty($log['result']) ? array() : $workflow->addObjectsVariables(WaicUtils::jsonDecode($log['result'])) );
			}
		}
		return $results;
	}
	
}
