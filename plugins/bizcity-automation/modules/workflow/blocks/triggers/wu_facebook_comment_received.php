<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming comments on Facebook Page posts.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_facebook_comment_received extends WaicTrigger {
	protected $_code = 'wu_facebook_comment_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 3;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận comment Facebook Page', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
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
			'text_contains' => array(
				'type' => 'text',
				'label' => __('Comment contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'text_regex' => array(
				'type' => 'text',
				'label' => __('Comment regex pattern', 'ai-copilot-content-generator'),
				'default' => '',
				'tooltip' => __('Example: ^(order|mua|đặt)', 'ai-copilot-content-generator'),
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
		$this->_variables = array_merge(
			$this->getDTVariables(),
			array(
				'platform' => __('Platform', 'ai-copilot-content-generator'),
				'bot_id' => __('Bot ID', 'ai-copilot-content-generator'),
				'comment_id' => __('Comment ID', 'ai-copilot-content-generator'),
				'post_id' => __('Post ID', 'ai-copilot-content-generator'),
				'user_id' => __('User ID', 'ai-copilot-content-generator'),
				'user_name' => __('User Name', 'ai-copilot-content-generator'),
				'message' => __('Comment text', 'ai-copilot-content-generator'),
				'field' => __('Incoming payload field *', 'ai-copilot-content-generator'),
			)
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (empty($args) || !is_array($args)) {
			return false;
		}

		$trigger_key = isset($args[0]) ? $args[0] : null;
		
		// Check if this is our trigger
		if ($trigger_key !== 'bizcity_facebook_comment_received') {
			return false;
		}

		$data = isset($args[1]) ? $args[1] : array();
		if (empty($data) || !is_array($data)) {
			return false;
		}

		$text = isset($data['message']) ? (string)$data['message'] : '';

		// Filter by text contains
		$filterTextContains = $this->getParam('text_contains');
		if (!empty($filterTextContains)) {
			if (stripos($text, $filterTextContains) === false) {
				return false;
			}
		}

		// Filter by regex
		$filterRegex = $this->getParam('text_regex');
		if (!empty($filterRegex)) {
			if (!@preg_match('/' . $filterRegex . '/iu', $text)) {
				return false;
			}
		}

		return true;
	}

	public function getRunValues( $args = array() ) {
		$data = isset($args[1]) ? $args[1] : array();
		
		return array(
			'platform' => 'facebook',
			'bot_id' => isset($data['bot_id']) ? $data['bot_id'] : '',
			'comment_id' => isset($data['comment_id']) ? $data['comment_id'] : '',
			'post_id' => isset($data['post_id']) ? $data['post_id'] : '',
			'user_id' => isset($data['user_id']) ? $data['user_id'] : '',
			'user_name' => isset($data['user_name']) ? $data['user_name'] : '',
			'message' => isset($data['message']) ? $data['message'] : '',
			'data' => $data,
		);
	}
}
