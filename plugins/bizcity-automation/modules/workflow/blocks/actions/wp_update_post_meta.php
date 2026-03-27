<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_post_meta extends WaicAction {
	protected $_code = 'wp_update_post_meta';
	protected $_order = 13;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Post Meta', 'ai-copilot-content-generator');
		//$this->_desc = __('Only filled fields will be updated.', 'ai-copilot-content-generator');
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
			'id' => array(
				'type' => 'input',
				'label' => __('Post ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'meta_key' => array(
				'type' => 'input',
				'label' => __('Meta Key *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'meta_value' => array(
				'type' => 'input',
				'label' => __('Meta Value', 'ai-copilot-content-generator'),
				'default' => '',
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
		$this->_variables = $this->getPostVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$postId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		$key = $this->replaceVariables($this->getParam('meta_key'), $variables);
		$value = $this->replaceVariables($this->getParam('meta_value'), $variables);
		
		if (empty($postId)) {
			$error = 'Post ID needed';
		} else if (empty($key)) {
			$error = 'Meta Key needed';
		} else {
			$post = get_post($postId);
			if (!$post) {
				$error = 'Post not found (ID=' . $postId . ')';
			}
		}
		
		if (empty($error)) {
			if (empty($value)) {
				delete_post_meta($postId, $key);
			} else {
				update_post_meta($postId, $key, $value);
			}
		}
		
		$this->_results = array(
			'result' => array('waic_post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
