<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_sy_manual extends WaicTrigger {
	protected $_code = 'sy_manual';
	protected $_subtype = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Manually', 'ai-copilot-content-generator');
		$this->_desc = __('Trigger initiated by the user to manually start the workflow.', 'ai-copilot-content-generator');
		$this->setBlock($block);
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
