<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for incoming image messages from Zalo Bot.
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_zalobot_image_received extends WaicTrigger {
	protected $_code = 'wu_zalobot_image_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 2;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận ảnh Zalo Bot', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
		$this->setBlock($block);
	}

	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	/**
	 * Get list of active Zalo bots for dropdown
	 */
	private function getBotOptions() {
		$opts = array(
			'' => __('— Tất cả Bot —', 'ai-copilot-content-generator'),
		);
		
		if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			$db = BizCity_Zalo_Bot_Database::instance();
			$bots = $db->get_active_bots();
			
			foreach ( $bots as $bot ) {
				$opts[ $bot->id ] = sprintf( '%s (ID: %d)', $bot->bot_name, $bot->id );
			}
		}
		
		return $opts;
	}

	public function setSettings() {
		$this->_settings = array(
			'bot_id' => array(
				'type' => 'select',
				'label' => __('Chọn Bot (tùy chọn)', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getBotOptions(),
				'desc' => __('Nếu không chọn, trigger sẽ lắng nghe tất cả bot', 'ai-copilot-content-generator'),
			),

			'platform' => array(
				'type' => 'select',
				'label' => __('Platform', 'ai-copilot-content-generator'),
				'default' => 'zalo',
				'options' => array(
					'zalo' => 'Zalo',
					'' => __('Any', 'ai-copilot-content-generator'),
				),
			),

			'caption_contains' => array(
				'type' => 'input',
				'label' => __('Caption chứa từ khóa', 'ai-copilot-content-generator'),
				'default' => '',
				'desc' => __('Chỉ trigger nếu caption kèm ảnh chứa từ này', 'ai-copilot-content-generator'),
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
				'bot_id' => __('Bot ID', 'ai-copilot-content-generator'),
				'bot_name' => __('Tên Bot', 'ai-copilot-content-generator'),
				'platform' => __('Platform (zalo)', 'ai-copilot-content-generator'),
				'client_id' => __('Client ID', 'ai-copilot-content-generator'),
				'chat_id' => __('Chat ID', 'ai-copilot-content-generator'),
				'user_id' => __('User ID (Zalo user_id)', 'ai-copilot-content-generator'),
				'message_id' => __('Message ID', 'ai-copilot-content-generator'),
				'display_name' => __('Tên hiển thị', 'ai-copilot-content-generator'),
				'attachment_url' => __('URL hình ảnh', 'ai-copilot-content-generator'),
				'image_url' => __('Image URL', 'ai-copilot-content-generator'),
				'caption' => __('Caption kèm ảnh', 'ai-copilot-content-generator'),
				'field' => __('Webhook payload field *', 'ai-copilot-content-generator'),
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
		
		// Only process image attachments
		if ($attachmentType !== 'image' || empty($attachmentUrl)) {
			return false;
		}
		
		// Filter by platform
		$filterPlatform = $this->getParam('platform');
		if (!empty($filterPlatform) && $platform !== $filterPlatform) {
			return false;
		}

		$clientId = isset($trigger['client_id']) ? (string)$trigger['client_id'] : '';
		$chatId = isset($trigger['chat_id']) ? (string)$trigger['chat_id'] : '';
		$userId = isset($trigger['user_id']) ? (string)$trigger['user_id'] : '';
		$messageId = isset($trigger['message_id']) ? (string)$trigger['message_id'] : '';
		$displayName = isset($trigger['display_name']) ? (string)$trigger['display_name'] : '';
		$text = isset($trigger['text']) ? (string)$trigger['text'] : ''; // Caption

		// Filter by bot_id if specified
		$filter_bot_id = (int) $this->getParam('bot_id');
		if ( $filter_bot_id > 0 ) {
			$trigger_bot_id = isset($trigger['bot_id']) ? (int)$trigger['bot_id'] : 0;
			
			// Fallback to database lookup
			if ( $trigger_bot_id === 0 && class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				global $wpdb;
				$table_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
				$trigger_bot_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT bot_id FROM {$table_logs} WHERE client_id = %s ORDER BY id DESC LIMIT 1",
					$userId
				) );
			}

			if ( $trigger_bot_id !== $filter_bot_id ) {
				return false;
			}
		}

		// Filter by caption_contains
		$captionContains = $this->getParam('caption_contains');
		if (!empty($captionContains) && WaicUtils::mbstrpos($text, $captionContains) === false) {
			return false;
		}

		$raw = null;
		if (isset($trigger['raw']) && is_array($trigger['raw'])) {
			$raw = $trigger['raw'];
		} elseif (isset($args[1]) && is_array($args[1])) {
			$raw = $args[1];
		}

		$result = array(
			'date' => date('Y-m-d'),
			'time' => date('H:i:s'),
			'bot_id' => $trigger['bot_id'] ?? 0,
			'bot_name' => $trigger['bot_name'] ?? '',
			'platform' => $platform,
			'client_id' => $clientId,
			'chat_id' => $chatId,
			'user_id' => $userId,
			'message_id' => $messageId,
			'display_name' => $displayName,
			'attachment_url' => $attachmentUrl,
			'image_url' => $attachmentUrl,
			'caption' => $text,
			'obj_id' => !empty($chatId) ? $chatId : $clientId,
		);

		if (is_array($raw)) {
			$fields = WaicUtils::flattenJson($raw);
			$result = $this->getFieldsArray($fields, 'field', $result);
		}

		return $result;
	}
}
