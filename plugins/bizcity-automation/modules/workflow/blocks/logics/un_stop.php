<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_un_stop extends WaicLogic {
	protected $_code = 'un_stop';
	protected $_subtype = 5;
	protected $_order = 98;
	
	public function __construct( $block = null ) {
		$this->_name = __('Stop Workflow', 'ai-copilot-content-generator');
		$this->_desc = __('End workflow execution immediately', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
		
	public function getResults( $taskId, $variables, $step = 0 ) {
		$this->_results = array(
			'result' => array(),
			'error' => '',
			'status' => 3,
			'stop' => 'flow',
		);
		return $this->_results;
	}
}
