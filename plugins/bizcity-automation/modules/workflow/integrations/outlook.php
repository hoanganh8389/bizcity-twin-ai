<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_outlook extends WaicIntegration {
	protected $_code = 'outlook';
	protected $_category = 'email';
	protected $_logo = 'OU';
	protected $_order = 3;
	protected $_privatParams = array('_refresh_token', '_access_token', '_expires_at', '_token_id');
	protected $_signalParams = array('access_code');
	
	private $_provider = 'outlook';
	private $_authUri = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private $_tokenUri = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private $_scope = 'User.Read Mail.Send Mail.Read offline_access';
    private $_testUri = 'https://graph.microsoft.com/v1.0/me';
    private $_sendUri = 'https://graph.microsoft.com/v1.0/me/sendMail';
	private $_redirectUri = '';
	
	public function __construct( $integration = false ) {
		$this->_name = 'Outlook';
		$this->_desc = __('Connect to Outlook via Microsoft Graph API', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
		//$this->_redirectUri = 'https://aiwuplugin.com/wp-json/aiwu/v1/oauth2callback';
		$this->_redirectUri = home_url() . '/wp-json/aiwu/v1/oauth2callback';
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
			'scopes'   => array('outlook'),
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
					'direct' => __('Direct (Outlook)', 'ai-copilot-content-generator'),
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
				'scope' => $this->_scope, //"https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/Mail.Read offline_access"
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
			$this->addParam('_expires_at', time() + (int)($tokens['expires_in'] ?? 3600));
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
			
			$message = array(
				'message' => array(
					'subject' => $data['subject'],
					'body' => array(
						'contentType' => 'HTML',
						'content' => $data['message'],
					),
					'toRecipients' => array(
						array('emailAddress' => array('address' => $data['to']))
					),
				),
				'saveToSentItems' => 'true',
			);

			if (!empty($data['cc'])) {
				$message['message']['ccRecipients'][] = array('emailAddress' => array('address' => $data['cc']));
			}
			if (!empty($data['bcc'])) {
				$message['message']['bccRecipients'][] = array('emailAddress' => array('address' => $data['bcc']));
			}
			if (!empty($data['reply'])) {
				$message['message']['replyTo'][] = array('emailAddress' => array('address' => $data['reply']));
			}

			$response = wp_remote_post($this->_sendUri, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $accessToken,
					'Content-Type'  => 'application/json',
				),
				'body' => json_encode($message),
			));

			if (is_wp_error($response)) {
				$error = 'http_request_failed: ' . $response->get_error_message();
			} else {
				$code = wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);

				if ($code !== 202) { // Graph API returns 202 Accepted
					$error = 'send_failed (' . $code . '): ' . $body;
				}
			}
		}

		$result['error'] = $error;
		return $result;
	}
}
