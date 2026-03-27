<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicHistoryModel extends WaicModel {

	public function __construct() {
		$this->_setTbl('history');
	}
	public function getStatuses( $st = null ) {
		$statuses = array(
			0 => __('OK', 'ai-copilot-content-generator'),
			1 => __('AI Error', 'ai-copilot-content-generator'),
			2 => __('Plugin Error', 'ai-copilot-content-generator'),
			3 => __('Plugin Data', 'ai-copilot-content-generator'),
		);
		return is_null($st) ? $statuses : ( isset($statuses[$st]) ? $statuses[$st] : '' );
	}
	public function getModes( $m = null ) {
		$modes = array(
			0 => __('Real', 'ai-copilot-content-generator'),
			1 => __('Preview', 'ai-copilot-content-generator'),
		);
		return is_null($m) ? $modes : ( isset($modes[$m]) ? $modes[$m] : '' );
	}
	public function saveHistory( $data = array() ) {
		$data['created'] = WaicUtils::getTimestampDB();
		if (empty($data['feature']) && !empty($data['task_id'])) {
			$data['feature'] = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getTaskFeature($data['task_id']);
		}
		$id = $this->insert($data);
		
		return $id;
	}
	public function getCountTokens( $where ) {
		$cnt = $this->setWhere($where)->setSelectFields('sum(tokens)')->getFromTbl(array('return' => 'one'));
		return $cnt ? $cnt : 0;
	}
	public function getCountTokensPerFeature( $where = array() ) {
		$data = $this->setWhere($where)->setSelectFields('feature, sum(tokens) as total')->groupBy('feature')->getFromTbl();
		return $data ? $data : array();
	}
	public function getCountRequests( $where ) {
		$cnt = $this->setWhere($where)->setSelectFields('count(id)')->getFromTbl(array('return' => 'one'));
		return $cnt ? $cnt : 0;
	}
	
	public function getLastDate( $year, $month ) {
		$cnt = cal_days_in_month(CAL_GREGORIAN, $month, $year);
		return $year . '-' . ( $month >= 10 ? $month : '0' . $month ) . '-' . ( $cnt >= 10 ? $cnt : '0' . $cnt );
	}
	public function getDBDate( $year, $month, $day ) {
		return $year . '-' . ( $month >= 10 ? $month : '0' . $month ) . '-' . ( $day >= 10 ? $day : '0' . $day );
	}
	public function calcTokens() {
		// SUM() returns NULL when there are no matching rows -> force 0 to satisfy NOT NULL column
		$query = 'UPDATE @__tasks t SET t.tokens=(SELECT COALESCE(sum(h.tokens), 0) FROM @__history h WHERE h.task_id=t.id)';
		WaicDb::query($query);
		return true;
	}
	public function deleteHistory( $taskId ) {
		$this->delete(array('task_id' => $taskId));
		return true;
	}
}
