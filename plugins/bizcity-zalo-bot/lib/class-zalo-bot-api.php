<?php
/**
 * Zalo Bot API Client
 * Official Zalo Bot API integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Zalo_Bot_API {
	
	private $access_token;
	private $api_base = 'https://openapi.zalo.me/v3.0/oa/';
	
	public function __construct( $access_token ) {
		$this->access_token = $access_token;
	}
	
	/**
	 * Send text message
	 */
	public function send_text_message( $user_id, $text ) {
		$endpoint = 'message/cs';
		
		$data = array(
			'recipient' => array(
				'user_id' => $user_id,
			),
			'message' => array(
				'text' => $text,
			),
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send image message
	 */
	public function send_image_message( $user_id, $image_url ) {
		$endpoint = 'message/cs';
		
		$data = array(
			'recipient' => array(
				'user_id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'template',
					'payload' => array(
						'template_type' => 'media',
						'elements' => array(
							array(
								'media_type' => 'image',
								'url' => $image_url,
							),
						),
					),
				),
			),
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Send file message
	 */
	public function send_file_message( $user_id, $file_url ) {
		$endpoint = 'message/cs';
		
		$data = array(
			'recipient' => array(
				'user_id' => $user_id,
			),
			'message' => array(
				'attachment' => array(
					'type' => 'file',
					'payload' => array(
						'url' => $file_url,
					),
				),
			),
		);
		
		return $this->request( $endpoint, $data );
	}
	
	/**
	 * Get user profile
	 */
	public function get_user_profile( $user_id ) {
		$endpoint = 'user/detail';
		
		$params = array(
			'data' => json_encode( array(
				'user_id' => $user_id,
			) ),
		);
		
		return $this->request( $endpoint, null, 'GET', $params );
	}
	
	/**
	 * Get OA info
	 */
	public function get_oa_info() {
		$endpoint = 'getoa';
		return $this->request( $endpoint, null, 'GET' );
	}
	
	/**
	 * Upload image
	 */
	public function upload_image( $file_path ) {
		$endpoint = 'upload/image';
		
		$file = new CURLFile( $file_path );
		$data = array( 'file' => $file );
		
		return $this->request( $endpoint, $data, 'POST', array(), true );
	}
	
	/**
	 * Upload file
	 */
	public function upload_file( $file_path ) {
		$endpoint = 'upload/file';
		
		$file = new CURLFile( $file_path );
		$data = array( 'file' => $file );
		
		return $this->request( $endpoint, $data, 'POST', array(), true );
	}
	
	/**
	 * Set webhook URL for Zalo Bot Platform
	 * Uses Zalo Bot Platform API to actually set webhook
	 * 
	 * @param string $webhook_url The webhook URL to set
	 * @param string $secret_token Optional secret token for webhook verification
	 * @return array|WP_Error Response 
	 */
	public function set_webhook( $webhook_url, $secret_token = '' ) {
		// Sử dụng Zalo Bot Platform API để set webhook
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/setWebhook';
		
		$payload = array(
			'url' => $webhook_url,
		);
		
		// Thêm secret token nếu có
		if ( ! empty( $secret_token ) ) {
			$payload['secret_token'] = $secret_token;
		}
		
		$data = json_encode( $payload );
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
					'payload' => $payload,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Get updates using long polling
	 * Alternative to webhook - these are mutually exclusive
	 * 
	 * @param int $offset Update ID to start from
	 * @param int $limit Number of updates to fetch (1-100)
	 * @param int $timeout Long polling timeout in seconds
	 * @return array|WP_Error Response
	 */
	public function get_updates( $offset = null, $limit = 100, $timeout = 30 ) {
		// Sử dụng Zalo Bot Platform API để lấy updates
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/getUpdates';
		
		$payload = array(
			'timeout' => $timeout,
			'limit' => min( 100, max( 1, $limit ) ) // Giới hạn 1-100
		);
		
		// Thêm offset nếu có
		if ( ! is_null( $offset ) ) {
			$payload['offset'] = intval( $offset );
		}
		
		$data = json_encode( $payload );
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout + 10 ); // Thêm 10s buffer
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
					'payload' => $payload,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Delete webhook to enable getUpdates mode
	 * Webhook and getUpdates are mutually exclusive
	 * 
	 * @return array|WP_Error Response
	 */
	public function delete_webhook() {
		// Sử dụng Zalo Bot Platform API để xóa webhook
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/deleteWebhook';
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Get webhook info from Zalo Bot Platform
	 * Gets current webhook configuration
	 * 
	 * @return array|WP_Error Response
	 */
	public function get_webhook_info() {
		// Sử dụng Zalo Bot Platform API để lấy webhook info
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/getWebhookInfo';
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Make API request
	 */
	private function request( $endpoint, $data = null, $method = 'POST', $query_params = array(), $is_upload = false ) {
		$url = $this->api_base . $endpoint;
		
		// Add query params
		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}
		
		$args = array(
			'method' => $method,
			'headers' => array(
				'access_token' => $this->access_token,
			),
			'timeout' => 30,
		);
		
		if ( $method === 'POST' && $data ) {
			if ( $is_upload ) {
				// For file uploads, use cURL
				return $this->curl_request( $url, $data );
			} else {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body'] = json_encode( $data );
			}
		}
		
		$response = wp_remote_request( $url, $args );
		
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$body = wp_remote_retrieve_body( $response );
		$result = json_decode( $body, true );
		
		// Check for API errors
		if ( isset( $result['error'] ) && $result['error'] !== 0 ) {
			return new WP_Error(
				'zalo_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Unknown error',
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * cURL request for file uploads
	 */
	private function curl_request( $url, $data ) {
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'access_token: ' . $this->access_token,
		) );
		
		$response = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		if ( $http_code !== 200 ) {
			return new WP_Error( 'curl_error', 'HTTP Error: ' . $http_code );
		}
		
		$result = json_decode( $response, true );
		
		if ( isset( $result['error'] ) && $result['error'] !== 0 ) {
			return new WP_Error(
				'zalo_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Unknown error',
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Send message (Zalo Bot Platform API format)
	 * Tự động chia nhỏ nếu text vượt quá 2000 ký tự
	 */
	public function send_message( $chat_id, $text ) {
		// Nếu text vượt quá 2000 ký tự, chia nhỏ và gửi từng phần
		if ( mb_strlen( $text, 'UTF-8' ) > 2000 ) {
			$chunks = $this->split_text( $text, 2000 );
			$last_result = null;
			foreach ( $chunks as $chunk ) {
				$last_result = $this->send_message( $chat_id, $chunk );
				if ( is_wp_error( $last_result ) ) {
					return $last_result;
				}
			}
			return $last_result;
		}

		// Sử dụng bot token trong URL path
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/sendMessage';
		
		$payload = array(
			'chat_id' => $chat_id,
			'text' => $text,
		);
		back_trace('NOTICE', 'Zalo Bot API send_message payload: '.print_r($this->access_token, true));
		$data = json_encode( $payload );
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
					'payload' => $payload,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Send photo (Zalo Bot Platform API format)
	 */
	public function send_photo( $chat_id, $photo, $caption = '' ) {
		// Sử dụng bot token trong URL path
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/sendPhoto';
		
		$payload = array(
			'chat_id' => $chat_id,
			'photo' => $photo,
		);
		
		if ( ! empty( $caption ) ) {
			$payload['caption'] = $caption;
		}
		
		$data = json_encode( $payload );
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
					'payload' => $payload,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}
	
	/**
	 * Get bot information using getMe API
	 * Verifies bot token and returns bot details
	 * 
	 * @return array|WP_Error Response
	 */
	public function get_me() {
		// Sử dụng Zalo Bot Platform API để lấy thông tin bot
		$url = 'https://bot-api.zaloplatforms.com/bot' . $this->access_token . '/getMe';
		
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
		) );
		
		$response = curl_exec( $ch );
		
		// Check for cURL errors
		if ( curl_errno( $ch ) ) {
			$error = curl_error( $ch );
			curl_close( $ch );
			return new WP_Error( 'curl_error', 'cURL Error: ' . $error );
		}
		
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		
		// Decode response
		$result = json_decode( $response, true );
		
		// Check HTTP status code
		if ( $http_code !== 200 ) {
			return new WP_Error( 
				'http_error', 
				'HTTP Error: ' . $http_code, 
				array( 
					'http_code' => $http_code, 
					'response' => $result ? $result : $response,
					'url' => $url,
				) 
			);
		}
		
		// Check API response status
		if ( isset( $result['ok'] ) && $result['ok'] === false ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['description'] ) ? $result['description'] : 'API returned error',
				$result
			);
		}
		
		// Check for error_code (Zalo specific)
		if ( isset( $result['error_code'] ) && $result['error_code'] !== 0 ) {
			return new WP_Error(
				'zalo_bot_api_error',
				isset( $result['message'] ) ? $result['message'] : 'Error code: ' . $result['error_code'],
				$result
			);
		}
		
		return $result;
	}

	/**
	 * Chia nhỏ text thành các đoạn không vượt quá $max_length ký tự
	 * Ưu tiên cắt tại dấu xuống dòng hoặc khoảng trắng để tránh cắt giữa từ
	 *
	 * @param string $text       Nội dung cần chia
	 * @param int    $max_length Độ dài tối đa mỗi đoạn (mặc định 2000)
	 * @return array Mảng các đoạn text
	 */
	private function split_text( $text, $max_length = 2000 ) {
		$chunks = array();
		$text   = trim( $text );

		while ( mb_strlen( $text, 'UTF-8' ) > $max_length ) {
			$segment = mb_substr( $text, 0, $max_length, 'UTF-8' );

			// Tìm vị trí xuống dòng gần nhất để cắt
			$break_pos = mb_strrpos( $segment, "\n", 0, 'UTF-8' );

			// Nếu không có xuống dòng, tìm khoảng trắng gần nhất
			if ( $break_pos === false || $break_pos < $max_length / 2 ) {
				$break_pos = mb_strrpos( $segment, ' ', 0, 'UTF-8' );
			}

			// Nếu vẫn không tìm được, cắt cứng
			if ( $break_pos === false || $break_pos < $max_length / 2 ) {
				$break_pos = $max_length;
			}

			$chunks[] = trim( mb_substr( $text, 0, $break_pos, 'UTF-8' ) );
			$text     = trim( mb_substr( $text, $break_pos, null, 'UTF-8' ) );
		}

		if ( $text !== '' ) {
			$chunks[] = $text;
		}

		return $chunks;
	}
}
