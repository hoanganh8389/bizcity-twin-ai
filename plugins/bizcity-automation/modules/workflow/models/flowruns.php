<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicFlowrunsModel extends WaicModel {
	private $_statuses = null;
	public function __construct() {
		$this->_setTbl('flowruns');
	}

	public function getStatuses( $st = null ) {
		if (is_null($this->_statuses)) {
			$this->_statuses = array(
				1 => __('Waiting', 'ai-copilot-content-generator'), /*wait in queue*/
				2 => __('Processing', 'ai-copilot-content-generator'),
				3 => __('Сompleted', 'ai-copilot-content-generator'),
				6 => __('Stopped', 'ai-copilot-content-generator'), /*stopped by timeout or tokens limit*/
				7 => __('Error', 'ai-copilot-content-generator'),
				9 => __('Canceled', 'ai-copilot-content-generator'),
			);
		}
		return is_null($st) ? $this->_statuses : ( isset($this->_statuses[$st]) ? $this->_statuses[$st] : '' );
	}
	
	public function createRun( $taskId, $flId, $params = array(), $objId = 0) {
		$run = array(
			'task_id' => ( (int) $taskId ),
			'fl_id' => ( (int) $flId ),
			'status' => 1,
			'params' => WaicUtils::jsonEncode(( empty($params) || !is_array($params) ? array() : $params ), true),
			'obj_id' => ( (int) $objId ),
			'added' => WaicUtils::getTimestampDB(),
		);
		$id = $this->insert($run);
		return empty($id) ? 0 : $id;
	}
	public function startRun( $id ) {
		$this->updateById(array('status' => 2, 'waiting' => 0, 'started' => WaicUtils::getTimestampDB()), $id);
	}
	public function stopRun( $id, $status, $error = false ) {
		$data = array('status' => $status, 'ended' => WaicUtils::getTimestampDB());
		if (!empty($error)) {
			$data['error'] = addslashes(substr($error, 0, 500)); 
		}
		$this->updateById($data, $id);
	}
	public function cancelRuns( $taskId ) {
		$this->update(array('status' => 9), array('status' => 1, 'task_id' => $taskId));
	}
	public function waitingRun( $id, $waiting ) {
		$this->updateById(array('status' => 1, 'waiting'  => $waiting), $id);
	}
	public function addTokens( $id, $tokens ) {
		$this->updateById(array('tokens' => $tokens), $id);
	}
	
	public function getTokens( $flId ) {
		$tokens = WaicDb::get('SELECT sum(tokens) FROM `@__flowruns` WHERE fl_id=' . $flId, 'one');
		return $tokens ? (int) $tokens : 0;
	}
	
	public function existRunningRuns( $flId ) {
		$run = $this->setSelectFields('id')->setWhere(array('fl_id' => $flId, 'additionalCondition' => 'status<3'))->setLimit(1)->getFromTbl(array('return' => 'one'));
		return $run ? true : false;
	}
	public function getLastRunForObj( $flId, $objId ) {
		$query = "SELECT ended, TIMESTAMPDIFF(SECOND, ended, '" . WaicUtils::getTimestampDB() . "') as period" .
			' FROM `@__flowruns`' .
			' WHERE fl_id=' . $flId . ' AND obj_id=' . $objId . ' AND status<4' .
			' ORDER BY ended IS NULL DESC, ended DESC LIMIT 1';
		return WaicDb::get($query, 'row');
	}
	public function getCountRunForObj( $flId, $objId ) {
		$query = 'SELECT count(*)' .
			' FROM `@__flowruns`' .
			' WHERE fl_id=' . $flId . ' AND obj_id=' . $objId . ' AND status<4';
		return WaicDb::get($query, 'one');
	}
	
}