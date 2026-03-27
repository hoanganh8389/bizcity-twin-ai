<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_openrouter_generate_image extends WaicAction {
	protected $_code = 'ai_openrouter_generate_image';
	protected $_order = 9;
	
	public function __construct( $block = null ) {
		$this->_name = __('OpenRouter Generate Image', 'ai-copilot-content-generator');
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
				'default' => $modelOptions->getDefaults('api', 'openrouter_img_model'),
				'options' => $modelOptions->getVariations('api', 'openrouter_img_model'),
			),
			'orientation' => array(
				'type' => 'select',
				'label' => __('Aspect Ratio', 'ai-copilot-content-generator'),
				'default' => 'horizontal',
				'options' => array('1:1' => __('1:1', 'ai-copilot-content-generator'),
					'3:4' => __('3:4', 'ai-copilot-content-generator'),
					'4:3' => __('4:3', 'ai-copilot-content-generator'),
					'9:16' => __('9:16', 'ai-copilot-content-generator'),
					'16:9' => __('16:9', 'ai-copilot-content-generator'),
				),
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
			'image_id' => __('Generated Image Id', 'ai-copilot-content-generator'),
			'image_url' => __('Generated Image Url', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$apiOptions = array(
			'engine' => 'openrouter',
			'image_engine' => 'openrouter',
			'openrouter_img_model' => $this->getParam('model'),
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
		$opts = array(
			'prompt' => $prompt,
			'gemini_size' => $this->getParam('orientation'),
		);

		$result = $aiProvider->getImage($opts);
		$error = $result['error'];
		$attId = 0;
		if (!$error) {
			$path = $this->controlText($result['data']);
			if ($path) {
				$attId = $this->saveImage(htmlspecialchars_decode($path, ENT_QUOTES), 'WAIC AI generated Image');
			}
		}

		$error = $result['error'];
		$this->_results = array(
			'result' => $error ? array() : array('image_id' => $attId, 'image_url' => wp_get_attachment_url($attId)),
			'error' => $error ? $this->controlText($result['msg']) : '',
			'tokens' => empty($result['tokens']) ? array() : ( (int) $result['tokens'] ),
			'status' => $error ? 7 : 3,
		);
		return $this->_results;
	}
	
	
}
