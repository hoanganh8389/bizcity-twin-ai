<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicLogic_un_join extends WaicLogic {
    protected $_code = 'un_join';
    protected $_subtype = 2;
    protected $_order = 4;

    public function __construct( $block = null ) {
        $this->_name = __('Ghép luồng', 'ai-copilot-content-generator');
        $this->_desc = __('Ghép nhiều luồng đầu vào thành một luồng tiếp tục..', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'name' => array(
                'type' => 'input',
                'label' => __('Node Name', 'ai-copilot-content-generator'),
                'default' => 'Join',
            ),
            'mode' => array(
                'type' => 'select',
                'label' => __('Mode', 'ai-copilot-content-generator'),
                'default' => 'cosmetic',
                'options' => array(
                    'cosmetic' => __('Cosmetic (pass-through)', 'ai-copilot-content-generator'),
                    'barrier' => __('Barrier (not implemented)', 'ai-copilot-content-generator'),
                ),
            ),
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // Current implementation: cosmetic join that simply continues the flow.
        // Barrier behavior would require run-state tracking and is not implemented here.

        $this->_results = array(
            'result' => array('joined' => true),
            'error' => '',
            'status' => 3,
            'sourceHandle' => 'output-right',
        );
        return $this->_results;
    }
}
