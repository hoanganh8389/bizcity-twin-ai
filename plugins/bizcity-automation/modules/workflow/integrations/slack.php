<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_slack extends WaicIntegration {
	protected $_code = 'slack';
	protected $_category = 'messenger';
	protected $_logo = 'SL';
	protected $_order = 41;
	private $_sendUri = 'https://slack.com/api/chat.postMessage';
	private $_linkUri ='https://slack.com/api/chat.getPermalink';
	private $_testApiUri = 'https://slack.com/api/auth.test';
	private $_channelListUri = 'https://slack.com/api/conversations.list';
	private $_permalinkUri = 'https://slack.com/api/chat.getPermalink';
	private $_maxAttempts = 3;
	
	/*private $_authUri = 'https://accounts.google.com/o/oauth2/auth';
	private $_tokenUri = 'https://oauth2.googleapis.com/token';
	private $_scope = 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly';
	private $_testUri = 'https://www.googleapis.com/gmail/v1/users/me/profile';
	
	private $_redirectUri = '';*/
	
	public function __construct( $integration = false ) {
		$this->_name = 'Slack';
		$this->_desc = __('Connect to Slack API / Webhook', 'ai-copilot-content-generator');
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
			'mode' => array(
				'type' => 'select',
				'label' => __('Type', 'ai-copilot-content-generator'),
				'options' => array('webhook' => 'Webhook', 'api' => 'API access'),
				'default' => 'webhook',
			),
			'webhook_uri' => array(
				'type' => 'input',
				'label' => __('Webhook URL', 'ai-copilot-content-generator') . ' *',
				'plh' => '',
				'default' => '',
				'encrypt' => true,
				'show' => array('mode' => array('webhook')),
			),
			'bot_token' => array(
				'type' => 'input',
				'label' => __('Bot Token', 'ai-copilot-content-generator') . ' *',
				'plh' => 'xoxb-...',
				'default' => '',
				'encrypt' => true,
				'show' => array('mode' => array('api')),
			),
			'channel' => array(
				'type' => 'input',
				'label' => __('Channel', 'ai-copilot-content-generator') . ' *',
				'plh' => 'e.g., #general, C1234567890',
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
		$mode = $this->getParam('mode');
		if ('webhook' == $mode) {
			$data = $this->sendViaWebhook(array('message' => ''), true);
			return empty($data['error']) ? false : $data['error'];
		} else {
			return $this->checkToken();
		}
	}
	
	public function checkToken() {
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			return 'Bot Token is required';
		}
		$response = wp_remote_post($this->_testApiUri, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $botToken,
				'Content-Type'  => 'application/x-www-form-urlencoded'
			)
		));
		if (is_wp_error($response)) {
			return 'Slack API error: ' . $response->get_error_message();
		} else {
			$сode = wp_remote_retrieve_response_code($response);
			if (200 !== $сode) {
				return 'Slack API auth.test result error: ' . $сode;
			}
			$body = wp_remote_retrieve_body($response);
			$bodyArr = json_decode($body, true);
			if (!is_array($bodyArr)) {
				return 'Slack API returned error (' . $сode . '): ' . $body;
			}
			if (!empty($bodyArr['error'])) {
				$error = 'Slack API auth.test error: ' . $bodyArr['error'];
				if (!empty($bodyArr['warning'])) {
					$error .= ' (Warning: ' . $bodyArr['warning'] . ')';
				}
				return $error;
			} else if (empty($bodyArr['ok']) || ( true !== $bodyArr['ok'] )) {
				return 'Slack API not return OK (' . $сode . '): ' . $body;
			}
		}
		
		$channel = str_replace('#', '', $this->getParam('channel'));
		if (!empty($channel)) {
			$response = wp_remote_get($this->_channelListUri, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $botToken,
				)
			));

			if (is_wp_error($response)) {
				return 'Slack API error: ' . $response->get_error_message();
			} else {
				$сode = wp_remote_retrieve_response_code($response);
				if (200 !== $сode) {
					return 'Slack API conversations.list result error: ' . $сode;
				}
				$body = wp_remote_retrieve_body($response);
				$bodyArr = json_decode($body, true);
				if (!is_array($bodyArr)) {
					return 'Slack API returned error (' . $сode . '): ' . $body;
				}
				$found = false;
				if (!empty($bodyArr['error'])) {
					$error = 'Slack API conversations.list error: ' . $bodyArr['error'];
					if (!empty($bodyArr['warning'])) {
						$error .= ' (Warning: ' . $bodyArr['warning'] . ')';
					}
					return $error;
				} else if (empty($bodyArr['ok']) || ( true !== $bodyArr['ok'] )) {
					return 'Slack API not return OK (' . $сode . '): ' . $body;
				} else if (!empty($bodyArr['channels']) && is_array($bodyArr['channels'])) {
					foreach ($bodyArr['channels'] as $ch) {
						if ($ch['name'] === $channel || $ch['id'] === $channel) {
							$found = true;
							break;
						}
					}
				}
				if (!$found) {
					return 'Channel ' . $channel . ' not found';
				}
			}
		}
		return false;
	}
	
	public function doSendMessage( $data ) {
		$error = '';
		$result = array();
		
		$mode = $this->getParam('mode');
		$data['method'] = $mode;
		if ('webhook' == $mode) {
			unset($data['channel'], $data['thread_ts']);
			$data = $this->sendViaWebhook($data);
		} else {
			$data = $this->sendViaApi($data);
		}

		return $data;
	}
	
	public function sendViaWebhook( $data, $test = false ) {
		$webhookUri = $this->getDecryptedParam('webhook_uri');
		if (empty($webhookUri)) {
			$data['error'] = 'Slack Webhook URL is required';
			return $data;
		}
		$message = empty($data['message']) ? '' : $data['message'];
		$payload = array('text' => $message);
		
		if (!empty($data['username'])) {
			$payload['username'] = $data['username'];
		}
		if (!empty($data['icon_emoji'])) {
			$payload['icon_emoji'] = $data['icon_emoji'];
		}
		$maxAttempts = $this->_maxAttempts;
		$attempt = 0;
		$success = false;
		$payload = json_encode($payload);

		while ($attempt < $maxAttempts && !$success) {
			$attempt++;
			$response = wp_remote_post($webhookUri, array(
				'headers' => array('Content-Type' => 'application/json'),
				'body' => $payload,
				'timeout' => 30,
				'sslverify' => true,
			));
			if (is_wp_error($response)) {
				$error = $response->get_error_message();
				if ($attempt < $maxAttempts) {
					sleep(2);
				}
			} else {
				$сode = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);

				if (200 === $сode && 'ok' === $body) {
					$success = true;
				} else {
					if ($test && 400 === $сode && 'no_text' === $body) {
						$success = true;
					} else {
						$error = 'Slack returned error (' . $сode . '): ' . $body;
						if ($attempt < $maxAttempts) {
							sleep(2);
						}
					}
				}
			}
		}
		if (!$success) {
			$data['error'] = ( empty($error) ? 'Unknown error' : $error );
		}
		return $data;
	}
	
	public function sendViaApi( $data ) {
		$botToken = $this->getDecryptedParam('bot_token');
		if (empty($botToken)) {
			$data['error'] = 'Bot Token is required';
			return $data;
		}
		if (empty($data['channel'])) {
			$data['channel'] = $this->getParam('channel');
		}
		if (empty($data['channel'])) {
			$data['error'] = 'Channel is required';
			return $data;
		}
		$message = empty($data['message']) ? '' : $data['message'];
		$payload = array('text' => $message, 'channel' => $data['channel']);
		
		if (!empty($data['username'])) {
			$payload['username'] = $data['username'];
		}
		if (!empty($data['icon_emoji'])) {
			$payload['icon_emoji'] = $data['icon_emoji'];
		}
				
		if (!empty($data['thread_ts'])) {
			$payload['thread_ts'] = $data['thread_ts'];
		}
		$maxAttempts = $this->_maxAttempts;
		$attempt = 0;
		$success = false;
		$payload = json_encode($payload);
		while ($attempt < $maxAttempts && !$success) {
			$attempt++;
			$response = wp_remote_post($this->_sendUri, array(
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $botToken,
				),
				'body' => $payload,
				'timeout' => 30,
				'sslverify' => true,
			));
		
			if (is_wp_error($response)) {
				$error = 'Slack API error: ' . $response->get_error_message();
			} else {
				$сode = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);
				$bodyArr = json_decode($body, true);
				
				if (200 === $сode && !empty($bodyArr['ok']) && ( true === $bodyArr['ok'] )) {
					$success = true;
					$error = '';
					$messageTs = !empty($bodyArr['ts']) ? $bodyArr['ts'] : '';
					$channelId = !empty($bodyArr['channel']) ? $bodyArr['channel'] : '';
					$data['message_ts'] = $messageTs;
					$data['channel_id'] = $channelId;
						
					if (!empty($messageTs) && !empty($channelId)) {
						$permalink = $this->getMessagePermalink($botToken, $channelId, $messageTs);
						if ($permalink) {
							$data['permalink'] = $permalink;
						}
					}
				} else {
					if (!is_array($bodyArr)) {
						$error = 'Slack API returned error (' . $сode . '): ' . $body;
					} else if (!empty($bodyArr['error'])) {
						$error = 'Slack API chat.postMessage error: ' . $bodyArr['error'];
						if (!empty($bodyArr['warning'])) {
							$error .= ' (Warning: ' . $bodyArr['warning'] . ')';
						}
					}
				}
				if (!$success) {
					if (empty($error)) {
						$error = 'Slack API chat.postMessage result error (' . $сode . '): ' . $body;
					}
					if ($attempt < $maxAttempts) {
						sleep(2);
					}
				}
			}
		}
		
		if (!$success) {
			$data['error'] = ( empty($error) ? 'Unknown error' : $error );
		}
		return $data;
	}
	
	public function getMessagePermalink($botToken, $channel, $messageTs) {
		$response = wp_remote_get(
			add_query_arg(
				array(
					'channel' => $channel,
					'message_ts' => $messageTs,
				),
				$this->_permalinkUri,
			),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $botToken,
				),
				'timeout' => 10,
				'sslverify' => true,
			)
		);

		if (!is_wp_error($response)) {
			$body = wp_remote_retrieve_body($response);
			$bodyArr = json_decode($body, true);

			if (!empty($bodyArr['ok']) && !empty($bodyArr['permalink'])) {
				return $bodyArr['permalink'];
			}
		}
		return '';
	}
}
