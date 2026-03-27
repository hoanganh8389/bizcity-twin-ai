<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicLogic extends WaicBuilderBlock {

	public function __construct( $code ) {
		$this->_type = 'logic';
	}
	public function setResults( $resuts ) {
		$this->_results = array('result' => $resuts);
	}
	public function addLoopError() {
		if (!empty($this->_results) && !empty($this->_results['result']) && is_array($this->_results['result'])) {
			$count = WaicUtils::getArrayValue($this->_results['result'], 'count_errors', 0, 1);
			$this->_results['result']['count_errors'] = $count + 1;
		}
	}
	public function addLoopSuccess() {
		if (!empty($this->_results) && !empty($this->_results['result']) && is_array($this->_results['result'])) {
			$count = WaicUtils::getArrayValue($this->_results['result'], 'count_success', 0, 1);
			$this->_results['result']['count_success'] = $count + 1;
		}
	}
}