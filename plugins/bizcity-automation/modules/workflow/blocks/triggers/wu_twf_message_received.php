<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming chat messages from BizCity TWF layer.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_twf_message_received extends WaicTrigger {
	protected $_code = 'wu_twf_message_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 11;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận tin Zalo BizCity', 'ai-copilot-content-generator');
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
					'zalo' => 'zalo','telegram' => 'telegram',
					'' => '',
					
				),
			),
			'text_contains' => array(
				'type' => 'input',
				'label' => __('Text contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'text_regex' => array(
				'type' => 'input',
				'label' => __('Text regex', 'ai-copilot-content-generator'),
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
				'text' => __('Text', 'ai-copilot-content-generator'),

				// BizCity: attachments (image/audio)
				'message_id' => __('Message ID', 'ai-copilot-content-generator'),
				'attachment_url' => __('Attachment URL', 'ai-copilot-content-generator'),
				'attachment_type' => __('Attachment type (image/audio/unknown)', 'ai-copilot-content-generator'),
			'image_url' => __('Image URL (từ text hoặc attachment)', 'ai-copilot-content-generator'),
			'audio_url' => __('Audio URL', 'ai-copilot-content-generator'),

			'field' => __('Incoming payload field *', 'ai-copilot-content-generator'),
		)
	);
	return $this->_variables;
	}

	private function bizcityClassifyAttachmentUrl($url) {
		$url = (string) $url;
		if ($url === '') return 'unknown';

		$path = (string) parse_url($url, PHP_URL_PATH);
		$ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

		$audio = array('aac','m4a','mp3','wav','ogg','oga','opus');
		$image = array('jpg','jpeg','png','gif','webp','bmp','tif','tiff');

		if ($ext && in_array($ext, $audio, true)) return 'audio';
		if ($ext && in_array($ext, $image, true)) return 'image';

		return 'unknown';
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
		$clientId = isset($trigger['client_id']) ? (string)$trigger['client_id'] : '';
		$chatId = isset($trigger['chat_id']) ? (string)$trigger['chat_id'] : '';
		$text = isset($trigger['text']) ? (string)$trigger['text'] : ''; // ⭐ Already cleaned by bootstrap

		$filterPlatform = $this->getParam('platform');
		if (!empty($filterPlatform) && $platform !== $filterPlatform) {
			return false;
		}

		$textContains = $this->getParam('text_contains');
		if (!empty($textContains) && WaicUtils::mbstrpos($text, $textContains) === false) {
			return false;
		}
		
		$textRegex = $this->getParam('text_regex');
		if (!empty($textRegex)) {
			$ok = @preg_match('#' . $textRegex . '#u', $text);
			if (!$ok) {
				return false;
			}
		}
		// --- BizCity: get attachment data from trigger input (already processed by bootstrap) ---
		$messageId      = isset($trigger['message_id']) ? (string)$trigger['message_id'] : '';
		$attachmentUrl  = isset($trigger['attachment_url']) ? (string)$trigger['attachment_url'] : '';
		$attachmentType = isset($trigger['attachment_type']) ? (string)$trigger['attachment_type'] : '';
		$imageUrl       = isset($trigger['image_url']) ? (string)$trigger['image_url'] : ''; // ⭐ Already parsed by bootstrap
		$audioUrl       = isset($trigger['audio_url']) ? (string)$trigger['audio_url'] : '';

		$raw = null;
		if (isset($trigger['raw']) && is_array($trigger['raw'])) {
			$raw = $trigger['raw'];
		} elseif (isset($args[1]) && is_array($args[1])) {
			$raw = $args[1];
		}

		// Fallback: parse from raw payload if needed (structure based on MU webhook)
		if (is_array($raw)) {
			if ($messageId === '' && isset($raw['message']['message_id'])) {
				$messageId = (string) $raw['message']['message_id'];
			}
			if ($attachmentUrl === '' && !empty($raw['message']['message_attachments'][0]['payload']['url'])) {
				$attachmentUrl = (string) $raw['message']['message_attachments'][0]['payload']['url'];
			}
			if ($attachmentType === '' && !empty($raw['conversation']['last_message_type'])) {
				$maybe = (string) $raw['conversation']['last_message_type'];
				if (in_array($maybe, array('text','image','audio','file'), true)) {
					$attachmentType = $maybe;
				}
			}
		}

		if ($attachmentType === '' && $attachmentUrl !== '') {
			$attachmentType = $this->bizcityClassifyAttachmentUrl($attachmentUrl);
		}

		$result = array(
			'date' => date('Y-m-d'),
			'time' => date('H:i:s'),
			'platform' => $platform,
			'client_id' => $clientId,
			'chat_id' => $chatId,
			'text' => $text, // ⭐ Cleaned by bootstrap

			// BizCity: attachments
			'message_id' => $messageId,
			'attachment_url' => $attachmentUrl,
			'attachment_type' => $attachmentType,
			'image_url' => $imageUrl, // ⭐ Parsed by bootstrap
			'audio_url' => $audioUrl,

			'obj_id' => !empty($chatId) ? $chatId : $clientId,
		);
		
		// ⭐ Debug log
		error_log('[wu_twf_message_received] OUTPUT: text=' . mb_substr($text, 0, 50) . ' | image_url=' . ($imageUrl ?: 'EMPTY'));
		
		if (is_array($raw)) {
			$fields = WaicUtils::flattenJson($raw);
			$result = $this->getFieldsArray($fields, 'field', $result);
		}

		return $result;
	}
}
