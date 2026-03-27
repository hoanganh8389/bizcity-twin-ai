<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trigger for Zalo Bot text messages with LLM intent classification
 * Fired via: do_action('waic_twf_process_flow', $trigger, $params)
 */
class WaicTrigger_wu_zalobot_text_received extends WaicTrigger {
	protected $_code = 'wu_zalobot_text_received';
	protected $_hook = 'waic_twf_process_flow';
	protected $_subtype = 2;
	protected $_order = 10;

	public function __construct( $block = null ) {
		$this->_name = __('Nhận tin nhắn Zalo Bot (có phân tích intent)', 'ai-copilot-content-generator');
		$this->_desc = __('Trigger khi nhận text từ Zalo Bot, tự động phân tích ý định bằng AI', 'ai-copilot-content-generator');
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

	/**
	 * Get predefined intent list
	 */
	private function getIntentOptions() {
		return array(
			'' => __('— Không filter theo intent —', 'ai-copilot-content-generator'),
			'question' => __('❓ question - Câu hỏi, thắc mắc', 'ai-copilot-content-generator'),
			'order' => __('🛒 order - Đặt hàng, mua sản phẩm', 'ai-copilot-content-generator'),
			'complaint' => __('😡 complaint - Phản ánh, khiếu nại', 'ai-copilot-content-generator'),
			'greeting' => __('👋 greeting - Chào hỏi, làm quen', 'ai-copilot-content-generator'),
			'support' => __('🆘 support - Yêu cầu hỗ trợ, trợ giúp', 'ai-copilot-content-generator'),
			'feedback' => __('💬 feedback - Đóng góp ý kiến, đánh giá', 'ai-copilot-content-generator'),
			'thanks' => __('🙏 thanks - Cảm ơn, biết ơn', 'ai-copilot-content-generator'),
			'price_inquiry' => __('💰 price_inquiry - Hỏi giá, tư vấn chi phí', 'ai-copilot-content-generator'),
			'booking' => __('📅 booking - Đặt lịch, hẹn gặp', 'ai-copilot-content-generator'),
			'cancel' => __('❌ cancel - Hủy đơn, hủy dịch vụ', 'ai-copilot-content-generator'),
			'other' => __('🔹 other - Khác', 'ai-copilot-content-generator'),
		);
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

			'intent_filter' => array(
				'type' => 'select',
				'label' => __('Lọc theo Intent (AI phân tích)', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getIntentOptions(),
				'desc' => __('AI sẽ tự động phân loại ý định của tin nhắn. Chọn intent để chỉ trigger khi match.', 'ai-copilot-content-generator'),
			),

			'text_contains' => array(
				'type' => 'input',
				'label' => __('Text chứa từ khóa', 'ai-copilot-content-generator'),
				'default' => '',
				'desc' => __('Chỉ trigger nếu tin nhắn chứa từ này (case-insensitive)', 'ai-copilot-content-generator'),
			),

			'text_regex' => array(
				'type' => 'input',
				'label' => __('Text khớp regex', 'ai-copilot-content-generator'),
				'default' => '',
				'tooltip' => __('Ví dụ: ^/order\s+ hoặc (mua|đặt)', 'ai-copilot-content-generator'),
			),

			'min_confidence' => array(
				'type' => 'input',
				'label' => __('Độ tin cậy tối thiểu (%)', 'ai-copilot-content-generator'),
				'default' => '70',
				'desc' => __('Chỉ trigger nếu AI classify với confidence >= giá trị này (0-100)', 'ai-copilot-content-generator'),
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
				'display_name' => __('Tên hiển thị', 'ai-copilot-content-generator'),
				'text' => __('Nội dung tin nhắn', 'ai-copilot-content-generator'),
				'message_id' => __('Message ID', 'ai-copilot-content-generator'),
				'attachment_url' => __('Attachment URL', 'ai-copilot-content-generator'),
				'attachment_type' => __('Attachment type (text/image/audio)', 'ai-copilot-content-generator'),
				'image_url' => __('Image URL (ưu tiên context, fallback attachment)', 'ai-copilot-content-generator'),
				'context_image_url' => __('Context Image URL (từ message trước)', 'ai-copilot-content-generator'),
				'file_url' => __('File URL', 'ai-copilot-content-generator'),
				'intent' => __('Intent (AI phân loại)', 'ai-copilot-content-generator'),
				'intent_confidence' => __('Độ tin cậy intent (%)', 'ai-copilot-content-generator'),
				'intent_reasoning' => __('Lý do AI phân loại', 'ai-copilot-content-generator'),
				'field' => __('Webhook payload field *', 'ai-copilot-content-generator'),
			)
		);
		return $this->_variables;
	}

	/**
	 * Classify message intent using LLM
	 */
	private function classifyIntent( $text, $display_name = '' ) {
		// Get OpenAI API key
		$api_key = get_option( 'twf_openai_api_key' );
		if ( empty( $api_key ) ) {
			error_log( '[Zalo Bot Trigger] OpenAI API key not found in twf_openai_api_key' );
			return array(
				'intent' => 'other',
				'confidence' => 0,
				'reasoning' => 'API key not configured',
			);
		}

		// Build prompt
		$intent_list = array_keys( $this->getIntentOptions() );
		array_shift( $intent_list ); // Remove empty option
		
		$intent_descriptions = array(
			'question' => 'Câu hỏi, thắc mắc về sản phẩm/dịch vụ',
			'order' => 'Đặt hàng, mua sản phẩm',
			'complaint' => 'Phản ánh, khiếu nại, không hài lòng',
			'greeting' => 'Chào hỏi, làm quen',
			'support' => 'Yêu cầu hỗ trợ, trợ giúp kỹ thuật',
			'feedback' => 'Đóng góp ý kiến, đánh giá',
			'thanks' => 'Cảm ơn, biết ơn',
			'price_inquiry' => 'Hỏi giá, tư vấn chi phí',
			'booking' => 'Đặt lịch, hẹn gặp',
			'cancel' => 'Hủy đơn, hủy dịch vụ',
			'other' => 'Các ý định khác',
		);

		$intent_list_text = '';
		foreach ( $intent_descriptions as $slug => $desc ) {
			$intent_list_text .= "- {$slug}: {$desc}\n";
		}

		$system_prompt = "Bạn là AI phân loại ý định tin nhắn khách hàng.

Danh sách intent:
{$intent_list_text}

Nhiệm vụ: Phân tích tin nhắn và trả về JSON với format:
{
  \"intent\": \"<slug>\",
  \"confidence\": <0-100>,
  \"reasoning\": \"<lý do ngắn gọn>\"
}

Lưu ý:
- confidence: độ tin cậy (0-100), càng rõ ràng càng cao
- reasoning: giải thích ngắn gọn tại sao phân loại vào intent này
- Chỉ trả về JSON, không có text thừa";

		$user_prompt = "Phân tích tin nhắn sau:\n\n";
		if ( ! empty( $display_name ) ) {
			$user_prompt .= "Người gửi: {$display_name}\n";
		}
		$user_prompt .= "Nội dung: {$text}";

		// Call OpenAI API
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type' => 'application/json',
			),
			'body' => json_encode( array(
				'model' => 'gpt-4o-mini',
				'messages' => array(
					array(
						'role' => 'system',
						'content' => $system_prompt,
					),
					array(
						'role' => 'user',
						'content' => $user_prompt,
					),
				),
				'temperature' => 0.3,
				'max_tokens' => 200,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[Zalo Bot Trigger] OpenAI API error: ' . $response->get_error_message() );
			return array(
				'intent' => 'other',
				'confidence' => 0,
				'reasoning' => 'API call failed',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			error_log( '[Zalo Bot Trigger] Invalid OpenAI response: ' . print_r( $body, true ) );
			return array(
				'intent' => 'other',
				'confidence' => 0,
				'reasoning' => 'Invalid API response',
			);
		}

		$llm_output = trim( $body['choices'][0]['message']['content'] );

		// Parse JSON from LLM output
		if ( preg_match( '/\{.*\}/s', $llm_output, $matches ) ) {
			$result = json_decode( $matches[0], true );
			
			if ( is_array( $result ) && isset( $result['intent'], $result['confidence'] ) ) {
				// Validate intent
				if ( ! in_array( $result['intent'], $intent_list ) ) {
					$result['intent'] = 'other';
				}
				
				// Ensure confidence is 0-100
				$result['confidence'] = max( 0, min( 100, (int) $result['confidence'] ) );
				
				// Add reasoning if missing
				if ( ! isset( $result['reasoning'] ) ) {
					$result['reasoning'] = '';
				}

				return $result;
			}
		}

		// Fallback
		return array(
			'intent' => 'other',
			'confidence' => 50,
			'reasoning' => 'Failed to parse LLM output',
		);
	}

	public function controlRun( $args = array() ) {
		if (empty($args) || !is_array($args)) {
			return false;
		}

		$trigger = isset($args[0]) ? $args[0] : null;
		if (!is_array($trigger)) {
			return false;
		}

		// Only process platform=zalo
		$platform = isset($trigger['platform']) ? (string)$trigger['platform'] : '';
		if ( $platform !== 'zalo' ) {
			return false;
		}

		// Only process text messages (skip image/audio)
		$attachment_type = isset($trigger['attachment_type']) ? (string)$trigger['attachment_type'] : '';
		if ( $attachment_type !== 'text' && ! empty( $attachment_type ) ) {
			return false;
		}

		$client_id = isset($trigger['client_id']) ? (string)$trigger['client_id'] : '';
		$chat_id = isset($trigger['chat_id']) ? (string)$trigger['chat_id'] : '';
		$user_id = isset($trigger['user_id']) ? (string)$trigger['user_id'] : '';
		$text = isset($trigger['text']) ? (string)$trigger['text'] : '';
		$message_id = isset($trigger['message_id']) ? (string)$trigger['message_id'] : '';
		$display_name = isset($trigger['display_name']) ? (string)$trigger['display_name'] : '';

		// Filter by bot_id if specified
		$filter_bot_id = (int) $this->getParam('bot_id');
		if ( $filter_bot_id > 0 ) {
			// Extract bot_id from webhook (need to check if available in trigger data)
			$trigger_bot_id = isset($trigger['bot_id']) ? (int)$trigger['bot_id'] : 0;
			
			// If not in trigger, try to get from database by matching client_id
			if ( $trigger_bot_id === 0 && class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
				global $wpdb;
				$table_logs = $wpdb->prefix . 'bizcity_zalo_bot_logs';
				$trigger_bot_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT bot_id FROM {$table_logs} WHERE client_id = %s ORDER BY id DESC LIMIT 1",
					$user_id
				) );
			}

			if ( $trigger_bot_id !== $filter_bot_id ) {
				return false;
			}
		}

		// Filter by text_contains
		$textContains = $this->getParam('text_contains');
		if (!empty($textContains) && WaicUtils::mbstrpos($text, $textContains) === false) {
			return false;
		}

		// Filter by text_regex
		$textRegex = $this->getParam('text_regex');
		if (!empty($textRegex)) {
			$ok = @preg_match('#' . $textRegex . '#u', $text);
			if (!$ok) {
				return false;
			}
		}

		// Intent classification
		$intent_result = array(
			'intent' => 'other',
			'confidence' => 0,
			'reasoning' => '',
		);

		$intent_filter = $this->getParam('intent_filter');
		
		// Only classify if there's an intent filter OR if text is not empty
		if ( ! empty( $text ) && ( ! empty( $intent_filter ) || strlen( $text ) > 5 ) ) {
			$intent_result = $this->classifyIntent( $text, $display_name );
		}

		// Check intent filter
		if ( ! empty( $intent_filter ) && $intent_result['intent'] !== $intent_filter ) {
			return false;
		}

		// Check minimum confidence
		$min_confidence = (int) $this->getParam('min_confidence');
		if ( $min_confidence > 0 && $intent_result['confidence'] < $min_confidence ) {
			return false;
		}

		// Get raw payload
		$raw = null;
		if (isset($trigger['raw']) && is_array($trigger['raw'])) {
			$raw = $trigger['raw'];
		} elseif (isset($args[1]) && is_array($args[1])) {
			$raw = $args[1];
		}

		// Extract attachment info (like wu_twf_message_received)
		$attachment_url = isset($trigger['attachment_url']) ? (string)$trigger['attachment_url'] : '';
		$attachment_type = isset($trigger['attachment_type']) ? (string)$trigger['attachment_type'] : 'text';
		$context_image_url = isset($trigger['context_image_url']) ? (string)$trigger['context_image_url'] : '';
		
		// Classify as image if URL contains image extension
		$image_url = '';
		$file_url = '';
		if (!empty($attachment_url)) {
			$path = parse_url($attachment_url, PHP_URL_PATH);
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			$image_exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
			
			if (in_array($ext, $image_exts)) {
				$image_url = $attachment_url;
			} else {
				$file_url = $attachment_url;
			}
		}
		
		// Ưu tiên context image (từ message trước), fallback về attachment hiện tại
		if (!empty($context_image_url)) {
			$image_url = $context_image_url;
		}

		// Build result variables
		$result = array(
			'date' => date('Y-m-d'),
			'time' => date('H:i:s'),
			'bot_id' => $trigger['bot_id'] ?? 0,
			'bot_name' => $trigger['bot_name'] ?? '',
			'platform' => $platform,
			'client_id' => $client_id,
			'chat_id' => $chat_id,
			'user_id' => $user_id,
			'display_name' => $display_name,
			'text' => $text,
			'message_id' => $message_id,
			'attachment_url' => $attachment_url,
			'attachment_type' => $attachment_type,
			'image_url' => $image_url,
			'context_image_url' => $context_image_url,
			'file_url' => $file_url,
			'intent' => $intent_result['intent'],
			'intent_confidence' => $intent_result['confidence'],
			'intent_reasoning' => $intent_result['reasoning'],
			'obj_id' => !empty($chat_id) ? $chat_id : $client_id,
		);

		// Add flattened fields from raw payload
		if (is_array($raw)) {
			$fields = WaicUtils::flattenJson($raw);
			$result = $this->getFieldsArray($fields, 'field', $result);
		}

		return $result;
	}
}
