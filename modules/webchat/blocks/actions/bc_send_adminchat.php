<?php
/**
 * Bizcity Twin AI — Action Block: Send Admin Chat Message
 * Block hành động: Gửi tin nhắn Admin Chat
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
 * BizCity Chat Agent Action — Gửi tin nhắn về Admin Chat
 * Send message to Admin Chat / Gửi tin nhắn vào Admin Chat session
 *
 * Insert message into webchat_messages table with platform_type = ADMINCHAT.
 * Frontend poll auto-receives via bizcity_webchat_session_poll.
 *
 * @package BizCity_WebChat
 * @since   3.3.0
 */
class WaicAction_bc_send_adminchat extends WaicAction {
	protected $_code  = 'bc_send_adminchat';
	protected $_order = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '💬 Admin Chat — Send Message', 'bizcity-twin-ai' );
		$this->_desc = __( 'Send a reply message to Admin Chat session. Frontend receives it automatically via poll.', 'bizcity-twin-ai' );
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
			'message' => [
				'type'      => 'textarea',
				'label'     => __( 'Message content *', 'bizcity-twin-ai' ),
				'default'   => '',
				'rows'      => 6,
				'html'      => true,
				'variables' => true,
			],
			'sender_name' => [
				'type'      => 'input',
				'label'     => __( 'Sender name', 'bizcity-twin-ai' ),
				'default'   => 'Pipeline Bot',
				'variables' => true,
			],
			'message_type' => [
				'type'    => 'select',
				'label'   => __( 'Message type', 'bizcity-twin-ai' ),
				'default' => 'text',
				'options' => [
					'text'   => __( 'Text', 'bizcity-twin-ai' ),
					'status' => __( 'Status update (dimmed)', 'bizcity-twin-ai' ),
				],
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
		$this->_variables = [
			'message_id' => __( 'Message ID (just created)', 'bizcity-twin-ai' ),
			'session_id' => __( 'Session ID', 'bizcity-twin-ai' ),
			'sent'       => __( 'Successfully sent (1/0)', 'bizcity-twin-ai' ),
		];
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		$message     = (string) $this->replaceVariables( $this->getParam( 'message' ), $variables );
		$sender_name = (string) $this->replaceVariables( $this->getParam( 'sender_name' ), $variables );
		$msg_type    = (string) $this->getParam( 'message_type' );

		// Auto-resolve: prefer user_id, fallback to session_id
		$user_id    = $this->resolve_var( $variables, 'user_id' );
		$session_id = '';

		if ( ! empty( $user_id ) ) {
			// Lookup active session for this user
			$session_id = $this->get_session_by_user( $user_id );
		}

		if ( empty( $session_id ) ) {
			$session_id = $this->resolve_var( $variables, 'session_id' );
		}

		$error = '';

		if ( empty( $session_id ) ) {
			$error = __( 'Session not found (both user_id and session_id are empty).', 'bizcity-twin-ai' );
		}

		if ( empty( $message ) ) {
			$error = __( 'Message content is empty.', 'bizcity-twin-ai' );
		}

		$message_id = '';

		if ( empty( $error ) ) {
			$message_id = uniqid( 'pipe_' );

			// Insert directly into webchat_messages — poll picks it up via since_id
			if ( class_exists( 'BizCity_WebChat_Database' ) ) {
				BizCity_WebChat_Database::instance()->log_message( [
					'session_id'    => $session_id,
					'user_id'       => 0,
					'client_name'   => $sender_name ?: 'Pipeline Bot',
					'message_id'    => $message_id,
					'message_text'  => $message,
					'message_from'  => 'bot',
					'message_type'  => $msg_type ?: 'text',
					'platform_type' => 'ADMINCHAT',
					'tool_name'     => 'pipeline',
				] );
			} else {
				// Fallback: direct DB insert
				global $wpdb;
				$table = $wpdb->prefix . 'bizcity_webchat_messages';
				$wpdb->insert( $table, [
					'session_id'    => $session_id,
					'user_id'       => 0,
					'client_name'   => $sender_name ?: 'Pipeline Bot',
					'message_id'    => $message_id,
					'message_text'  => $message,
					'message_from'  => 'bot',
					'message_type'  => $msg_type ?: 'text',
					'platform_type' => 'ADMINCHAT',
					'tool_name'     => 'pipeline',
					'created_at'    => current_time( 'mysql' ),
				] );
			}
		}

		$this->_results = [
			'result' => [
				'message_id' => $message_id,
				'session_id' => $session_id,
				'sent'       => empty( $error ) ? 1 : 0,
			],
			'error'  => $error,
			'status' => empty( $error ) ? 3 : 7,
		];

		return $this->_results;
	}

	/**
	 * Resolve a variable from flat scope or node#1.
	 */
	private function resolve_var( $variables, $key ) {
		if ( ! empty( $variables[ $key ] ) ) {
			return $variables[ $key ];
		}
		if ( isset( $variables['node#1'] ) && ! empty( $variables['node#1'][ $key ] ) ) {
			return $variables['node#1'][ $key ];
		}
		return '';
	}

	/**
	 * Find the most recent active session for a user_id.
	 */
	private function get_session_by_user( $user_id ) {
		if ( empty( $user_id ) ) {
			return '';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_sessions';
		$session_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT session_id FROM {$table} WHERE user_id = %d AND platform_type = 'ADMINCHAT' ORDER BY last_message_at DESC LIMIT 1",
			(int) $user_id
		) );
		return $session_id ?: '';
	}
}
