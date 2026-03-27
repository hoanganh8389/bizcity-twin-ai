<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_telegram extends WaicIntegration {
	protected $_code = 'telegram';
	protected $_category = 'messenger';
	protected $_logo = 'TE';
	protected $_order = 42;
	private $_apiBaseUri = 'https://api.telegram.org/bot';
	private $_maxAttempts = 3;
	
	public function __construct( $integration = false ) {
		$this->_name = 'Telegram';
		$this->_desc = __('Connect to Telegram API', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
	}
	
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		
		$this->_settings = array(
			'name' => array(
				'type' => 'input',
				'label' => __('Profile name', 'ai-copilot-content-generator'),
				'plh' => __('Internal name to identify this configuration', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'bot_token' => array(
				'type' => 'input',
				'label' => __('Bot Token', 'ai-copilot-content-generator') . ' *',
				'plh' => 'Example: 123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11',
				'default' => '',
				'encrypt' => true,
			),
			'chat_id' => array(
				'type' => 'input',
				'label' => __('Chat ID', 'ai-copilot-content-generator') . ' *',
				'plh' => 'Chat ID (numeric) or channel username (@channelname). Use @userinfobot to get your chat ID.',
				'default' => '',
				'show' => array('mode' => array('api')),
			),
		);
	}
	
	public function doTest( $need = false ) {
		$params = $this->getParams();
		if (!$need && !empty($params['_status'])) {
			return true;
		}
		$error = $this->doConnect();
		if (empty($error)) {
			$this->addParam('_status', 1);
			$this->addParam('_status_error', '');
		} else {
			$this->addParam('_status', 7);
			$this->addParam('_status_error', $error);
		}
	}
	
	public function doConnect( $close = true ) {
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			return 'Bot Token is required';
		}

		$response = wp_remote_get($this->_apiBaseUri . $botToken . '/getMe');
		$result = $this->parseApiResponse($response, 'getMe');
		if (empty($result['success'])) {
			return empty($result['error']) ? 'Request error' : $result['error'];
		}
		
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$response = wp_remote_get($this->_apiBaseUri . $botToken . '/getChat?chat_id=' . $chatId);
		$result = $this->parseApiResponse($response, 'getChat');
		if (empty($result['success'])) {
			return empty($result['error']) ? 'Request error' : $result['error'];
		}
		return false;
	}
	
	private function callTelegramAPI($method, $botToken, $params) {
		$url = $this->_apiBaseUri . $botToken . '/' . $method;

		$maxAttempts = $this->_maxAttempts;
		$attempt = 0;
		$success = false;
		$payload = json_encode($params);
		while ($attempt < $maxAttempts && !$success) {
			$attempt++;
			$response = wp_remote_post($url, array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => $payload,
				'timeout' => 30,
				'sslverify' => true,
			));
			$result = $this->parseApiResponse($response, $method);
			if (!empty($result['success'])) {
				$success = true;
				if (!empty($result['data'])) {
					$result = array_merge($result, $result['data']);
					unset($result['data']);
				}
			} else {
				$error = $result['error'];
				$shouldRetry = isset($result['retry']) ? $result['retry'] : false;
			}
			if (!$success && $shouldRetry && $attempt < $maxAttempts) {
				$waitTime = pow(2, $attempt);
				sleep($waitTim);
			}
		}
		
		return $result;
	}
	
	private function parseApiResponse($response, $method) {
		if (is_wp_error($response)) {
			return array(
				'success' => false,
				'error' => 'HTTP Error (/' . $method . '): ' . $response->get_error_message(),
				'retry' => true,
			);
		}

		$statusCode = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return array(
				'success' => false,
				'error' => 'Invalid JSON response from Telegram API /' . $method,
				'retry' => true,
			);
		}

		if (!isset($data['ok']) || $data['ok'] !== true) {
			$errorMsg = isset($data['description']) ? $data['description'] : 'Unknown API error';

			$errorCode = isset($data['error_code']) ? $data['error_code'] : $statusCode;
			$retry = $this->isRetryableApiError($errorCode);

			return array(
				'success' => false,
				'error' => 'Telegram API /' . $method . ' Error (' . $errorCode . '): ' . $errorMsg,
				'retry' => $retry,
			);
		}

		// Extract result data
		$resultData = $this->extractResultData($data, $method);

		return array(
			'success' => true,
			'data' => $resultData,
		);
	}
	private function isRetryableApiError( $code ) {
		// Retryable errors:
		// - 429: Too Many Requests (rate limit)
		// - 500-599: Server errors
		// - Network/timeout errors (handled in parseApiResponse)

		// Non-retryable errors:
		// - 400: Bad Request (validation errors)
		// - 401: Unauthorized (invalid token)
		// - 403: Forbidden (bot blocked, insufficient permissions)
		// - 404: Not Found (chat/message not found)

		if ($code == 429) {
			return true;  // Rate limit - wait and retry
		}

		if ($code >= 500 && $code < 600) {
			return true;  // Server error - might be temporary
		}

		// Client errors (400, 401, 403, 404) - don't retry
		return false;
	}
	private function extractResultData($data, $method) {
		$result = array(
			'success' => true,
			//'operation' => $method,
		);
		// For deleteMessage, result is just boolean
		if ($method === 'deleteMessage') {
			$result['deleted'] = isset($data['result']) ? $data['result'] : true;
			return $result;
		}

		// Extract message data
		if (isset($data['result'])) {
			$message = $data['result'];

			// Message ID
			if (isset($message['message_id'])) {
				$result['message_id'] = $message['message_id'];
			}

			// From (bot info)
			/*if (isset($message['from'])) {
				$result['from_id'] = isset($message['from']['id'])
					? $message['from']['id']
					: '';
				$result['from_first_name'] = isset($message['from']['first_name'])
					? $message['from']['first_name']
					: '';
				$result['from_username'] = isset($message['from']['username'])
					? $message['from']['username']
					: '';
			}*/

			// Chat info
			if (isset($message['chat'])) {
				$result['chat_id'] = isset($message['chat']['id'])
					? $message['chat']['id']
					: '';
				$result['chat_type'] = isset($message['chat']['type'])
					? $message['chat']['type']
					: '';
			}

			// Date
			if (isset($message['date'])) {
				$result['date'] = $message['date'];
			}

			// Text
			if (isset($message['text'])) {
				$result['text'] = $message['text'];
			}

			// Caption (for photos/documents)
			if (isset($message['caption'])) {
				$result['caption'] = $message['caption'];
			}
			if (isset($message['photo'])) {
				$lastPhoto = is_array($message['photo']) ? end($message['photo']) : false;
				$result['file_id'] = $lastPhoto && isset($lastPhoto['file_id']) ? $lastPhoto['file_id'] : '';
			}
			if (isset($message['document'])) {
				$result['file_id'] = isset($message['document']['file_id']) ? $message['document']['file_id'] : '';
				$result['file_name'] = isset($message['document']['file_name']) ? $message['document']['file_name'] : '';
				$result['mime_type'] = isset($message['document']['mime_type']) ? $message['document']['mime_type'] : '';
			}
		}

		return $result;
	}
	
	public function doSendMessage( $data ) {
		$error = '';
		$result = array();
		
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$data['chat_id'] = $chatId;
		return $this->callTelegramAPI('sendMessage', $botToken, $data);
	}
	public function doEditMessage( $data ) {
		$error = '';
		$result = array();
		
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$data['chat_id'] = $chatId;
		return $this->callTelegramAPI('editMessageText', $botToken, $data);
	}
	public function doDeleteMessage( $data ) {
		$error = '';
		$result = array();
		
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$data['chat_id'] = $chatId;
		return $this->callTelegramAPI('deleteMessage', $botToken, $data);
	}
	public function doSendPhoto( $data ) {
		$error = '';
		$result = array();
		
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$data['chat_id'] = $chatId;
		return $this->callTelegramAPI('sendPhoto', $botToken, $data);
	}
	public function doSendDocument( $data ) {
		$error = '';
		$result = array();
		$method = 'sendDocument';
		
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		$chatId = $this->getParam('chat_id');
		if (empty($chatId)) {
			return 'Chat ID is required';
		}
		$data['chat_id'] = $chatId;
		
		$url = $this->_apiBaseUri . $botToken . '/' . $method;

		$maxAttempts = 2;
		$attempt = 0;
		$success = false;
		$tmpPath = false;
		$error = '';
		
		$isUrl = 'url' == $data['mode'];
		$filename = empty($data['filename']) ? '' : $data['filename'];
		unset($data['mode'], $data['filename']);
		
		$payload = json_encode($data);
		while ($attempt < $maxAttempts && !$success) {
			$attempt++;
			$response = wp_remote_post($url, array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => $payload,
				'timeout' => 30,
				'sslverify' => true,
			));
			$result = $this->parseApiResponse($response, $method);
			if (empty($result['success'])) {
				$statusCode = wp_remote_retrieve_response_code($response);
				if (400 === $statusCode && $isUrl) {
					if (false === $tmpPath) {
						$fileUrl = empty($data['document']) ? '' : $data['document'];
						if (empty($filename)) {
							$filename = basename(parse_url($fileUrl, PHP_URL_PATH));
						}
						$tmpPath = get_temp_dir() . $filename;
						$responseFile = wp_remote_get($fileUrl);
						if (is_wp_error($responseFile)) {
							$result['error'] = 'Error upload: ' . $responseFile->get_error_message();
							$tmpPath = false;
						} else {
							$fileData = wp_remote_retrieve_body($responseFile);
							file_put_contents($tmpPath, $fileData);

							if (!file_exists($tmpPath)) {
								$result['error'] = 'Error file upload: ' . $fileUrl;
								$tmpPath = false;
							}
						}
					}
					if ($tmpPath) {
						$mime = mime_content_type($tmpPath);
						$data['document'] = new \CURLFile($tmpPath);
						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $url);
						curl_setopt($ch, CURLOPT_POST, true);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

						$response = curl_exec($ch);
						$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						curl_close($ch);
						if (200 !== $httpCode) {
							$result['error'] = 'Telegram API error: HTTP ' . $httpCode;
						} else {
							$body = json_decode($response, true);
							if (json_last_error() !== JSON_ERROR_NONE) {
								$result['error'] = 'Invalid JSON response from Telegram API';
							} else if (!isset($body['ok']) || $body['ok'] !== true) {
								$result['error'] = isset($body['description']) ? $body['description'] : 'Unknown API error';
								$errorCode = isset($body['error_code']) ? $body['error_code'] : $statusCode;
								$result['retry'] = $this->isRetryableApiError($errorCode);
							} else {
								$result = array(
									'success' => true,
									'data' => $this->extractResultData($body, $method),
								);
							}
						}
					}
				}
			}
			if (!empty($result['success'])) {
				$success = true;
				if (!empty($result['data'])) {
					$result = array_merge($result, $result['data']);
					unset($result['data']);
				}
			} else {
				$error = $result['error'];
				$shouldRetry = isset($result['retry']) ? $result['retry'] : false;
			}
			if (!$success && $shouldRetry && $attempt < $maxAttempts) {
				$waitTime = pow(2, $attempt);
				sleep($waitTime);
			}
		}
		if ($tmpPath) {
			unlink($tmpPath);
		}
		return $result;
	}
}
