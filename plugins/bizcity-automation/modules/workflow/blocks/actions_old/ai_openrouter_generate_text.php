<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_openrouter_generate_text extends WaicAction {
	protected $_code = 'ai_openrouter_generate_text';
	protected $_order = 7;
	
	public function __construct( $block = null ) {
		$this->_name = __('OpenRouter Generate Text', 'ai-copilot-content-generator');
		$this->_desc = __('To fetch the latest available OpenRouter models, go to Settings → API → Text Generation and click Check Models.', 'ai-copilot-content-generator');
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
		$modelOptions = WaicFrame::_()->getModule('options')->getModel();
		$this->_settings = array(
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'model' => array(
				'type' => 'select',
				'label' => __('Model', 'ai-copilot-content-generator'),
				'default' => $modelOptions->getDefaults('api', 'openrouter_model'),
				'options' => $modelOptions->getVariations('api', 'model', 'openrouter'),
			),
			'tokens' => array(
				'type' => 'number',
				'label' => __('Max Tokens', 'ai-copilot-content-generator'),
				'default' => 4096,
			),
			'temperature' => array(
				'type' => 'number',
				'label' => __('Temperature', 'ai-copilot-content-generator'),
				'default' => 0.7,
				'step' => '0.01',
				'min' => 0,
				'max' => 2,
			),
			'prompt' => array (
				'type' => 'textarea',
				'label' => __('Prompt *', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 8,
				'variables' => true,
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
		$this->_variables = array(
			'content' => __('Generated Text', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$apiOptions = array(
			'engine' => 'openrouter',
			'openrouter_model' => $this->getParam('model'),
			'tokens' => $this->getParam('tokens'),
			'temperature' => $this->getParam('temperature'),
		);
		$aiProvider = WaicFrame::_()->getModule('workspace')->getModel('aiprovider')->getInstance($apiOptions);
		if (!$aiProvider) {
			return false;
		}
		$aiProvider->init($taskId);
		if (!$aiProvider->setApiOptions($apiOptions)) {
			return false;
		}
		$prompt = $this->replaceVariables($this->getParam('prompt'), $variables);
		$result = $aiProvider->getText(array('prompt' => $prompt));
		$error = $result['error'];
		$this->_results = array(
			'result' => $error ? array() : array('content' => $this->controlText($result['data'])),
			'error' => $error ? $this->controlText($result['msg']) : '',
			'tokens' => empty($result['tokens']) ? array() : ( (int) $result['tokens'] ),
			'status' => $error ? 7 : 3,
		);
		return $this->_results;
	}
	
}
