<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_image extends WaicAction {
	protected $_code = 'ai_generate_image';
	protected $_order = 1;
	
	public function __construct( $block = null ) {
		$this->_name = __('Open AI Generate Image', 'ai-copilot-content-generator');
		$this->_desc = __('GPT image generation', 'ai-copilot-content-generator');
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
				'default' => $modelOptions->getDefaults('api', 'img_model'),
				'options' => $modelOptions->getVariations('api', 'img_model'),
			),
			'orientation' => array(
				'type' => 'select',
				'label' => __('Orientation', 'ai-copilot-content-generator'),
				'default' => 'horizontal',
				'options' => array('horizontal' => 'Horizontal', 'vertical' => 'Vertical', 'square' => 'Square'),
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
		// Get prompt from settings and replace variables
		$prompt = $this->replaceVariables($this->getParam('prompt'), $variables);
		
		if (empty($prompt)) {
			$this->_results = array(
				'result' => array(),
				'error' => 'Prompt is required',
				'tokens' => 0,
				'status' => 7,
			);
			return $this->_results;
		}
		
		// Call biz_generate_image() to generate image URL
		$image_url = false;
		if (function_exists('biz_generate_image')) {
			$image_url = biz_generate_image($prompt);
		} elseif (function_exists('twf_generate_image_url')) {
			$image_url = twf_generate_image_url($prompt);
		}
		
		$attId = 0;
		$error = '';
		
		if ($image_url && !is_wp_error($image_url)) {
			// Save image to media library
			$attId = $this->saveImage(htmlspecialchars_decode($image_url, ENT_QUOTES), 'BizCity AI generated Image');
			
			if (!$attId || is_wp_error($attId)) {
				$error = 'Failed to save image to media library';
				$attId = 0;
			}
		} else {
			$error = is_wp_error($image_url) ? $image_url->get_error_message() : 'Failed to generate image';
		}

		$this->_results = array(
			'result' => $error ? array() : array('image_id' => $attId, 'image_url' => wp_get_attachment_url($attId)),
			'error' => $error,
			'tokens' => 0,
			'status' => $error ? 7 : 3,
		);
		return $this->_results;
	}
	
	
}
