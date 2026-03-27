<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_gmail extends WaicIntegration {
	protected $_code = 'gmail';
	protected $_category = 'email';
	protected $_logo = 'GM';
	protected $_order = 2;
	protected $_privatParams = array('_refresh_token', '_access_token', '_expires_at', '_token_id');
	protected $_signalParams = array('access_code');
	
	private $_provider = 'google';
	private $_authUri = 'https://accounts.google.com/o/oauth2/auth';
	private $_tokenUri = 'https://oauth2.googleapis.com/token';
	private $_scope = 'https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly';
	private $_testUri = 'https://www.googleapis.com/gmail/v1/users/me/profile';
	private $_sendUri = "https://gmail.googleapis.com/gmail/v1/users/me/messages/send";
	private $_redirectUri = '';
	
	public function __construct( $integration = false ) {
		$this->_name = 'Gmail';
		$this->_desc = __('Connect to Gmail API', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
		//$this->_redirectUri = 'https://aiwuplugin.com/wp-json/aiwu/v1/oauth2callback?cur=gmail';
		$this->_redirectUri = home_url() . '/wp-json/aiwu/v1/oauth2callback?cur=' . $this->_code;
	}
	
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		//$integrations = WaicFrame::_()->getModule('workflow')->getModel('integrations');
		$body = json_encode(array(
			'provider' => $this->_provider,
			'url'      => home_url(),
			'scopes'   => array('gmail'),
		));
		$this->_settings = array(
			'uniq_id' => array(
				'type' => 'hidden',
				'label' => '',
				'default' => '',
			),
			'name' => array(
				'type' => 'input',
				'label' => __('Profile name', 'ai-copilot-content-generator'),
				'plh' => __('Internal name to identify this configuration', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'auth_mode' => array(
				'type' => 'select',
				'label' => __('Authorization Mode', 'ai-copilot-content-generator'),
				'options' => array(
					//'proxy'  => __('Proxy (AOPS Server)', 'ai-copilot-content-generator'),
					'direct' => __('Direct (Google)', 'ai-copilot-content-generator'),
				),
				'default' => 'direct',
			),
			'client_id' => array(
				'type' => 'input',
				'label' => __('Client ID', 'ai-copilot-content-generator'),
				'default' => '',
				'show' => array('auth_mode' => array('direct')),
			),
			'client_secret' => array(
				'type' => 'input',
				'label' => __('Client Secret', 'ai-copilot-content-generator'),
				'encrypt' => true,
				'default' => '',
				'show' => array('auth_mode' => array('direct')),
			),
			'redirect_uri' => array(
				'type' => 'input',
				'label' => __('Redirect Uri', 'ai-copilot-content-generator'),
				'readonly' => true,
				'default' => $this->_redirectUri,
				'show' => array('auth_mode' => array('direct')),
			),
			'from_email' => array(
				'type' => 'input',
				'label' => __('From Email', 'ai-copilot-content-generator'),
				'plh' => 'your-email@domain.com',
				'default' => '',
			),
			'from_name' => array(
				'type' => 'input',
				'label' => __('From Name', 'ai-copilot-content-generator'),
				'plh' => '',
				'default' => '',
			),
			'oauth2' => array(
				'type' => 'button',
				'label' => '',
				'btn_label' => __('Connect account →', 'ai-copilot-content-generator'),
				'link' => $this->_authUri . '?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code&scope=' . urlencode($this->_scope) . '&access_type=offline&prompt=consent',
				'proxy' => $this->getAuthProxyUrl($this->_provider),
				'signature' => $this->getAuthProxySecret($body),
				'body' => $body,
			),
			'access_code' => array(
				'type' => 'hidden',
				'label' => '',
				'default' => '',
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
	
	public function getAccessToken() {
		$refreshToken = $this->getDecryptedParam('_refresh_token');
		
		if (empty($refreshToken)) {
			$accessCode = $this->getParam('access_code');
			if (empty($accessCode)) {
				return 'Not found Access Code';
			}
			$body = array(
				'code' => $accessCode,
				'client_id' => $this->getParam('client_id'),
				'client_secret' => $this->getDecryptedParam('client_secret'),
				'redirect_uri' => $this->_redirectUri,
				'grant_type' => 'authorization_code',
			);
		} else {
			$body = array(
				'client_id'	=> $this->getParam('client_id'),
				'client_secret' => $this->getDecryptedParam('client_secret'),
				'refresh_token' => $refreshToken,
				'grant_type' => 'refresh_token',
			);
		}
		$response = wp_remote_post($this->_tokenUri, array(
			'body' => $body,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
		));
		

		if (is_wp_error($response)) {
			return 'http_request_failed: ' . $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($code !== 200) {
			return 'Invalid_response (' . $code . '): ' . $body;
		}
		$tokens = json_decode($body, true);
		if (!empty($tokens['refresh_token'])) {
			$this->addParam('_refresh_token', $tokens['refresh_token']);
		}
		if (!empty($tokens['access_token'])) {
			$this->addParam('_access_token', $tokens['access_token']);
			return false;
		}
		return 'Not recieve Access Token';
	}
	
	public function getProxyAccessToken() {
		$tokenId = $this->getDecryptedParam('_token_id');
		if (!empty($tokenId)) {
			$body = json_encode(array(
				'provider' => $this->_provider,
				'token_id' => $tokenId,
			));
			$response = wp_remote_post($this->getAuthProxyRefreshUrl(), array(
				'body' => $body,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-AOPS-Signature' => $this->getAuthProxySecret($body),
				),
			));

			if (is_wp_error($response)) {
				return 'http_request_failed: ' . $response->get_error_message();
			}
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($code !== 200) {
				return 'Invalid_response (' . $code . '): ' . $body;
			}
			$result = json_decode($body, true);
			if (!empty($result['token_package'])) {
				$this->addParam('access_code', $result['token_package']);
			}
		}

		$tokenPackage = $this->getParam('access_code');
		if (!empty($tokenPackage)) {
			$token = $this->unpackTokenPackage($tokenPackage);
			if (!is_array($token)) {
				return $token;
			}
			if (empty($token['token_id']) || empty($token['access_token']) || empty($token['expires_in'])) {
				return 'Incorrect body JWT';
			}

			$this->addParam('_access_token', $token['access_token']);
			$this->addParam('_token_id', $token['token_id']);
			$this->addParam('_expires_at', time() + (int)($token['expires_in'] ?? 3600));
			return false;
		}
		return 'Not recieve Access Token';
	}
	
	public function doConnect( $close = true ) {
		$error = $this->checkToken();
		if (!empty($error)) {
			$this->addParam('_access_token', '');
			$error = $this->checkToken();
		}
		return $error;
	}
	public function checkToken() {
		$accessToken = $this->getDecryptedParam('_access_token');
		if (!empty($accessToken)) {
			$expiresAt = $this->getDecryptedParam('_expires_at');
			if (!empty($expiresAt) && $expiresAt < time()) {
				$this->addParam('_access_token', ''); 
				$accessToken = '';
			}
		}
		
		if (empty($accessToken)) {
			$authMode = $this->getParam('auth_mode');
			$error = 'proxy' == $authMode ? $this->getProxyAccessToken() : $this->getAccessToken();
			if (!empty($error)) {
				return $error;
			}
			$accessToken = $this->getDecryptedParam('_access_token');
		}
		if (empty($accessToken)) {
			return 'Error';
		}
		$error = false;
		$response = wp_remote_get($this->_testUri, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
			),
		));
		if (is_wp_error($response)) {
			return 'http_request_failed: ' . $response->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($code !== 200) {
				return 'Invalid_token: ' . $body;
			}
		}
	}
	public function doSendEmail( $data ) {
		$error = $this->doConnect(false);
		$result = array();
		$message = '';
		if (empty($error)) {
			$accessToken = $this->getDecryptedParam('_access_token');
			if (empty($accessToken)) {
				$error = 'Access Token not found';
			}
		}
		if (empty($error)) {
			$from = $this->getParam('from_email');
			$fromName = $this->getParam('from_name');
			$result['from'] = $from;
			
			$fromName = empty($fromName) ? '' : '=?UTF-8?B?' . base64_encode($fromName) . '?= ';
			
			$message  = 'From: ' . $fromName . "<$from>\r\n";
			$message .= 'To: ' . $data['to'] . "\r\n";

			if (!empty($data['cc'])) {
				$message .= 'Cc: ' . $data['cc'] . "\r\n";
			}
			if (!empty($data['bcc'])) {
				$message .= 'Bcc: ' . $data['bcc'] . "\r\n";
			}
			if (!empty($data['reply'])) {
				$message .= 'Reply-To: ' . $data['reply'] . "\r\n";
			}
			$subject = $data['subject'];
			$subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
			
			$message .= 'Subject: ' . $subject . "\r\n";
			$message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
			$message .= $data['message'];

			$message = rtrim(strtr(base64_encode($message), '+/', '-_'), '=');
			$response = wp_remote_post($this->_sendUri, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $accessToken,
					'Content-Type'  => 'application/json',
				),
				'body' => json_encode(array('raw' => $message)),
			));

			if (is_wp_error($response)) {
				$error = 'http_request_failed: ' . $response->get_error_message();
			} else {
				$code = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);

				if ($code !== 200) {
					$error = 'send_failed (' . $code . '): ' . $body;
				}
			}
		}
		$result['error'] = $error;
		return $result;
	}
}
