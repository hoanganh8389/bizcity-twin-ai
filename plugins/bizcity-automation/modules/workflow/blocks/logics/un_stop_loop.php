<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_un_stop_loop extends WaicLogic {
	protected $_code = 'un_stop_loop';
	protected $_subtype = 4;
	protected $_order = 99;
	
	public function __construct( $block = null ) {
		$this->_name = __('Stop Loop', 'ai-copilot-content-generator');
		$this->_desc = __('End Loop execution immediately', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
		
	public function getResults( $taskId, $variables, $step = 0 ) {
		$this->_results = array(
			'result' => array(),
			'error' => '',
			'status' => 3,
			'stop' => 'loop',
			'sourceHandle' => 'output-else',
		);
		return $this->_results;
	}
}