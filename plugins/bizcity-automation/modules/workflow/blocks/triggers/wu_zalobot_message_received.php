<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming text messages from Zalo Bot.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_zalobot_message_received extends WaicTrigger {
	protected $_code = 'wu_zalobot_message_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 1;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận tin nhắn Zalo Bot', 'ai-copilot-content-generator');
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
				'default' => 'zalo',
				'options' => array(
					'zalo' => 'Zalo',
					'' => __('Any', 'ai-copilot-content-generator'),
				),
			),
			'text_contains' => array(
				'type' => 'text',
				'label' => __('Text contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'text_regex' => array(
				'type' => 'text',
				'label' => __('Text regex pattern', 'ai-copilot-content-generator'),
				'default' => '',
				'tooltip' => __('Example: ^/img\s+ or (order|invoice)', 'ai-copilot-content-generator'),
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
				'client_id' => __('Client ID', 'ai-copilot-content-generator'),
				'chat_id' => __('Chat ID', 'ai-copilot-content-generator'),
				'user_id' => __('User ID', 'ai-copilot-content-generator'),
				'message_id' => __('Message ID', 'ai-copilot-content-generator'),
				'text' => __('Message text', 'ai-copilot-content-generator'),
				'display_name' => __('Display name', 'ai-copilot-content-generator'),
				'attachment_type' => __('Attachment type', 'ai-copilot-content-generator'),
				'attachment_url' => __('Attachment URL', 'ai-copilot-content-generator'),
				'image_url' => __('Image URL (ưu tiên context, fallback attachment)', 'ai-copilot-content-generator'),
				'context_image_url' => __('Context Image URL (từ message trước)', 'ai-copilot-content-generator'),
				'field' => __('Incoming payload field *', 'ai-copilot-content-generator'),
			)
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (empty($args) || !is_array($args)) {
			return false;
		}

		$trigger = isset($args[0]) ? $args[0] : null;
		if (!is_array($trigger)) {
			return false;
		}

		$platform = isset($trigger['platform']) ? (string)$trigger['platform'] : '';
		$attachmentType = isset($trigger['attachment_type']) ? (string)$trigger['attachment_type'] : '';
		$text = isset($trigger['text']) ? (string)$trigger['text'] : '';

		// Filter by platform
		$filterPlatform = $this->getParam('platform');
		if (!empty($filterPlatform) && $platform !== $filterPlatform) {
			return false;
		}

		// Only process text messages (not images)
		if ($attachmentType !== 'text') {
			return false;
		}

		// Filter by text contains
		$textContains = $this->getParam('text_contains');
		if (!empty($textContains) && WaicUtils::mbstrpos($text, $textContains) === false) {
			return false;
		}
		
		// Filter by regex
		$textRegex = $this->getParam('text_regex');
		if (!empty($textRegex)) {
			$ok = @preg_match('#' . $textRegex . '#u', $text);
			if (!$ok) {
				return false;
			}
		}

		$clientId = isset($trigger['client_id']) ? (string)$trigger['client_id'] : '';
		$chatId = isset($trigger['chat_id']) ? (string)$trigger['chat_id'] : '';
		$userId = isset($trigger['user_id']) ? (string)$trigger['user_id'] : '';
		$messageId = isset($trigger['message_id']) ? (string)$trigger['message_id'] : '';
		$displayName = isset($trigger['display_name']) ? (string)$trigger['display_name'] : '';
		$attachmentUrl = isset($trigger['attachment_url']) ? (string)$trigger['attachment_url'] : '';
		$contextImageUrl = isset($trigger['context_image_url']) ? (string)$trigger['context_image_url'] : '';
		$imageUrl = isset($trigger['image_url']) ? (string)$trigger['image_url'] : '';

		$raw = null;
		if (isset($trigger['raw']) && is_array($trigger['raw'])) {
			$raw = $trigger['raw'];
		} elseif (isset($args[1]) && is_array($args[1])) {
			$raw = $args[1];
		}

		$result = array(
			'date' => date('Y-m-d'),
			'time' => date('H:i:s'),
			'platform' => $platform,
			'client_id' => $clientId,
			'chat_id' => $chatId,
			'user_id' => $userId,
			'message_id' => $messageId,
			'text' => $text,
			'display_name' => $displayName,
			'attachment_type' => $attachmentType,
			'attachment_url' => $attachmentUrl,
			'image_url' => $imageUrl,
			'context_image_url' => $contextImageUrl,
			'obj_id' => !empty($chatId) ? $chatId : $clientId,
		);

		if (is_array($raw)) {
			$fields = WaicUtils::flattenJson($raw);
			$result = $this->getFieldsArray($fields, 'field', $result);
		}

		return $result;
	}
}
