<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wu_generate_post extends WaicAction {
	protected $_code = 'wu_generate_post';
	
	public function __construct( $block = null ) {
		//$this->setSettings();
		$this->_name = __('Generate Post', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': wp_login';
	}

}
