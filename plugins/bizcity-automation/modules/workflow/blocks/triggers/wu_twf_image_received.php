<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming image messages from BizCity TWF layer.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_twf_image_received extends WaicTrigger {
	protected $_code = 'wu_twf_image_received';
	protected $_hook = 'waic_twf_process_flow_image_received';
	protected $_subtype = 2;
	protected $_order = 2;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận ảnh Zalo BizCity', 'ai-copilot-content-generator');
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
					'zalo' => 'zalo',
					'telegram' => 'telegram',
					'' => '',
				),
			),
			// Không có text_contains, text_regex, file_type
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
				'message_id' => __('Message ID', 'ai-copilot-content-generator'),
				'attachment_url' => __('Attachment URL', 'ai-copilot-content-generator'),
				'attachment_type' => __('Attachment type', 'ai-copilot-content-generator'),
				'image_url' => __('Image URL', 'ai-copilot-content-generator'),
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
		$attachmentUrl = isset($trigger['attachment_url']) ? (string)$trigger['attachment_url'] : '';
		if ($attachmentType !== 'image' || empty($attachmentUrl)) {
			return false;
		}
		$filterPlatform = $this->getParam('platform');
		if (!empty($filterPlatform) && $platform !== $filterPlatform) {
			return false;
		}

		$messageId = isset($trigger['message_id']) ? (string)$trigger['message_id'] : '';
		$clientId = isset($trigger['client_id']) ? (string)$trigger['client_id'] : '';
		$chatId = isset($trigger['chat_id']) ? (string)$trigger['chat_id'] : '';
		$imageUrl = $attachmentUrl;

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
			'message_id' => $messageId,
			'attachment_url' => $attachmentUrl,
			'attachment_type' => $attachmentType,
			'image_url' => $imageUrl,
			'obj_id' => !empty($chatId) ? $chatId : $clientId,
		);

		if (is_array($raw)) {
			$fields = WaicUtils::flattenJson($raw);
			$result = $this->getFieldsArray($fields, 'field', $result);
		}

		return $result;
	}
}
