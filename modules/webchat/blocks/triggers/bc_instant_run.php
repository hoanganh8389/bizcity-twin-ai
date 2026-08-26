<?php
/**
 * Bizcity Twin AI — Trigger Block: Instant Run
 * Block trigger: Chạy ngay khi nhấn Execute (không cần chờ tin nhắn)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.4.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BizCity Chat Agent Trigger — Instant Run
 *
 * Subtype 0 = manual trigger: fires immediately when user clicks Execute.
 * No hook needed — workflow starts right away with configured input.
 *
 * Output variables are compatible with bc_adminchat_message so the same
 * action pipeline (it_call_tool, bc_send_adminchat) works seamlessly.
 *
 * @package BizCity_WebChat
 * @since   3.4.0
 */
class WaicTrigger_bc_instant_run extends WaicTrigger {
	protected $_code    = 'bc_instant_run';
	protected $_subtype = 0; // manual — fires immediately on Execute
	protected $_order   = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '⚡ Instant Run — Chạy ngay', 'bizcity-twin-ai' );
		$this->_desc = __( 'Kích hoạt ngay khi nhấn Execute. Dùng text mặc định hoặc test data, không cần chờ tin nhắn.', 'bizcity-twin-ai' );
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
			'default_text' => [
				'type'    => 'textarea',
				'label'   => __( 'Nội dung mặc định (text)', 'bizcity-twin-ai' ),
				'default' => '',
				'desc'    => __( 'Text sẽ truyền vào {{node#1.text}} khi chạy. Nếu trống, dùng test_data từ Execute panel.', 'bizcity-twin-ai' ),
			],
			'default_image_url' => [
				'type'    => 'input',
				'label'   => __( 'Image URL mặc định (optional)', 'bizcity-twin-ai' ),
				'default' => '',
				'desc'    => __( 'URL ảnh truyền vào {{node#1.image_url}}.', 'bizcity-twin-ai' ),
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
				'session_id'   => __( 'Session ID (auto-generated)', 'bizcity-twin-ai' ),
				'user_id'      => __( 'WordPress User ID (current admin)', 'bizcity-twin-ai' ),
				'display_name' => __( 'Display name (current admin)', 'bizcity-twin-ai' ),
				'text'         => __( 'Input text content', 'bizcity-twin-ai' ),
				'message_id'   => __( 'Message ID (auto-generated)', 'bizcity-twin-ai' ),
				'image_url'    => __( 'Image URL (if any)', 'bizcity-twin-ai' ),
				'platform'     => __( 'Platform (instant_run)', 'bizcity-twin-ai' ),
				'reply_to'     => __( 'Reply To — Session ID to send reply', 'bizcity-twin-ai' ),
			]
		);
	}

	/**
	 * controlRun — called by Execute API.
	 *
	 * For subtype 0, $args comes from test_data or is empty.
	 * We merge settings defaults + current user context so downstream
	 * action nodes always have the variables they expect.
	 */
	public function controlRun( $args = array() ) {
		$data = isset( $args[0] ) ? $args[0] : ( is_array( $args ) ? $args : [] );

		// Text: test_data > settings default
		$text = '';
		if ( ! empty( $data['text'] ) ) {
			$text = (string) $data['text'];
		} elseif ( ! empty( $data['message_text'] ) ) {
			$text = (string) $data['message_text'];
		}
		if ( $text === '' ) {
			$text = (string) $this->getParam( 'default_text' );
		}

		// Image: test_data > settings default
		$image_url = '';
		if ( ! empty( $data['image_url'] ) ) {
			$image_url = (string) $data['image_url'];
		}
		if ( $image_url === '' ) {
			$image_url = (string) $this->getParam( 'default_image_url' );
		}

		// Current user context
		$user_id   = get_current_user_id();
		$user_data = $user_id ? get_userdata( $user_id ) : null;
		$display   = $user_data ? $user_data->display_name : 'System';

		// Session: use provided or generate
		$session_id = ! empty( $data['session_id'] )
			? (string) $data['session_id']
			: 'instant_' . get_current_blog_id() . '_' . $user_id . '_' . time();

		$this->_results = [
			'date'         => date( 'Y-m-d' ),
			'time'         => date( 'H:i:s' ),
			'session_id'   => $session_id,
			'user_id'      => $user_id,
			'display_name' => $display,
			'text'         => $text,
			'message_id'   => 'instant_' . uniqid(),
			'image_url'    => $image_url,
			'platform'     => 'instant_run',
			'reply_to'     => $session_id,
		];

		return $this->_results;
	}
}
