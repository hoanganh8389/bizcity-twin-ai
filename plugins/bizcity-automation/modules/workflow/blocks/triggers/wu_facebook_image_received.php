<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming image messages from Facebook Messenger.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_facebook_image_received extends WaicTrigger {
	protected $_code = 'wu_facebook_image_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 2;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận hình ảnh Facebook Bot', 'ai-copilot-content-generator');
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
			'platform' => array(
				'type' => 'select',
				'label' => __('Platform', 'ai-copilot-content-generator'),
				'default' => 'facebook',
				'options' => array(
					'facebook' => 'Facebook Messenger',
					'' => __('Any', 'ai-copilot-content-generator'),
				),
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
				'user_id' => __('User ID (PSID)', 'ai-copilot-content-generator'),
				'chat_id' => __('Chat ID', 'ai-copilot-content-generator'),
				'image_url' => __('Image URL', 'ai-copilot-content-generator'),
				'text' => __('Caption text', 'ai-copilot-content-generator'),
				'timestamp' => __('Timestamp', 'ai-copilot-content-generator'),
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
		if ($trigger_key !== 'bizcity_facebook_image_received') {
			return false;
		}

		$data = isset($args[1]) ? $args[1] : array();
		if (empty($data) || !is_array($data)) {
			return false;
		}

		return true;
	}

	public function getRunValues( $args = array() ) {
		$data = isset($args[1]) ? $args[1] : array();
		
		return array(
			'platform' => 'facebook',
			'bot_id' => isset($data['bot_id']) ? $data['bot_id'] : '',
			'user_id' => isset($data['user_id']) ? $data['user_id'] : '',
			'chat_id' => isset($data['user_id']) ? $data['user_id'] : '',
			'twf_chat_id' => isset($data['user_id']) ? $data['user_id'] : '',
			'image_url' => isset($data['image_url']) ? $data['image_url'] : '',
			'text' => isset($data['message']) ? $data['message'] : '',
			'timestamp' => isset($data['timestamp']) ? $data['timestamp'] : time() * 1000,
			'event' => $data,
		);
	}
}
