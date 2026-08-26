<?php
/**
 * Bizcity Twin AI — Trigger Block: Admin Chat Message Received
 * Block trigger: Nhận tin nhắn Admin Chat
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BizCity Chat Agent Trigger — Nhận tin nhắn từ Admin Chat
 * Receive message from Admin Chat / Nhận tin nhắn từ Admin Chat dashboard
 *
 * Dedicated trigger for admin chat sessions (adminchat_ sessions).
 * Listens to `waic_twf_process_flow` event fired by Gateway Bridge.
 *
 * Variables output tương thích với `wp_send_gateway` action block
 * để pipeline có thể phản hồi ngược về session.
 *
 * @package BizCity_WebChat
 * @since   3.3.0
 */
class WaicTrigger_bc_adminchat_message extends WaicTrigger {
	protected $_code    = 'bc_adminchat_message';
	protected $_hook    = 'waic_twf_process_flow';
	protected $_subtype = 2; // wphook
	protected $_order   = 1;

	public function __construct( $block = null ) {
		$this->_name = __( '💬 Admin Chat — Receive Message', 'bizcity-twin-ai' );
		$this->_desc = __( 'Triggered when admin/user sends a message in Admin Chat dashboard', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = [
			'text_contains' => [
				'type'    => 'input',
				'label'   => __( 'Contains keyword (optional)', 'bizcity-twin-ai' ),
				'default' => '',
				'desc'    => __( 'Only trigger if message contains this word. Leave empty for all messages.', 'bizcity-twin-ai' ),
			],
			'text_regex' => [
				'type'    => 'input',
				'label'   => __( 'Regex filter (optional)', 'bizcity-twin-ai' ),
				'default' => '',
				'tooltip' => __( 'Example: ^/pipeline or (create post|add product)', 'bizcity-twin-ai' ),
			],
		];
	}

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = array_merge(
			$this->getDTVariables(),
			[
				'session_id'   => __( 'Session ID (adminchat_xxx)', 'bizcity-twin-ai' ),
				'user_id'      => __( 'WordPress User ID', 'bizcity-twin-ai' ),
				'display_name' => __( 'Sender display name', 'bizcity-twin-ai' ),
				'text'         => __( 'Message content', 'bizcity-twin-ai' ),
				'message_id'   => __( 'Message ID', 'bizcity-twin-ai' ),
				'image_url'    => __( 'Image URL (if any)', 'bizcity-twin-ai' ),
				'platform'     => __( 'Platform (adminchat)', 'bizcity-twin-ai' ),
				'reply_to'     => __( 'Reply To — Session ID to send reply', 'bizcity-twin-ai' ),
			]
		);
	}

	public function controlRun( $args = array() ) {
		$data = isset( $args[0] ) ? $args[0] : [];

		// Only process adminchat messages — check platform first (gateway-normalized)
		$platform = isset( $data['platform'] ) ? strtolower( (string) $data['platform'] ) : '';
		$session_id = isset( $data['session_id'] ) ? (string) $data['session_id'] : '';

		// Fallback: detect platform from session_id prefix
		if ( empty( $platform ) ) {
			if ( strpos( $session_id, 'adminchat_' ) === 0 ) {
				$platform = 'adminchat';
			}
		}

		if ( $platform !== 'adminchat' ) {
			return;
		}

		// Read text from gateway-normalized field, fallback to message_text
		$text = isset( $data['text'] ) ? (string) $data['text'] : '';
		if ( $text === '' && isset( $data['message_text'] ) ) {
			$text = (string) $data['message_text'];
		}

		// Text filter
		$contains = $this->getParam( 'text_contains' );
		if ( ! empty( $contains ) && stripos( $text, $contains ) === false ) {
			return;
		}

		// Regex filter
		$regex = $this->getParam( 'text_regex' );
		if ( ! empty( $regex ) && ! @preg_match( '/' . $regex . '/ui', $text ) ) {
			return;
		}

		// Resolve user info
		$user_id   = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$user_data = $user_id ? get_userdata( $user_id ) : null;
		$display   = $user_data ? $user_data->display_name : ( isset( $data['display_name'] ) ? $data['display_name'] : ( isset( $data['client_name'] ) ? $data['client_name'] : 'User' ) );

		// Image extraction — gateway-normalized or raw attachments
		$image_url = isset( $data['image_url'] ) ? (string) $data['image_url'] : '';
		if ( empty( $image_url ) && ! empty( $data['attachments'] ) ) {
			$atts = is_string( $data['attachments'] ) ? json_decode( $data['attachments'], true ) : $data['attachments'];
			if ( is_array( $atts ) ) {
				foreach ( $atts as $att ) {
					$url  = isset( $att['url'] ) ? $att['url'] : '';
					$type = isset( $att['type'] ) ? $att['type'] : '';
					if ( $type === 'image' || preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $url ) ) {
						$image_url = $url;
						break;
					}
				}
			}
		}

		$this->_results = [
			'date'         => date( 'Y-m-d' ),
			'time'         => date( 'H:i:s' ),
			'session_id'   => $session_id,
			'user_id'      => $user_id,
			'display_name' => $display,
			'text'         => $text,
			'message_id'   => isset( $data['message_id'] ) ? $data['message_id'] : '',
			'image_url'    => $image_url,
			'platform'     => 'adminchat',
			'reply_to'     => $session_id,
		];

		return $this->_results;
	}
}
