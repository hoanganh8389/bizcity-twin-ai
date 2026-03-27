<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_zoom extends WaicIntegration {
	protected $_code = 'zoom';
	protected $_category = 'calendar';
	protected $_logo = 'ZO';
	protected $_order = 22;
	protected $_privatParams = array('_refresh_token', '_access_token', '_expires_at', '_token_id');
	protected $_signalParams = array('access_code');
	
	private $_provider = 'zoom';
	private $_authUri = 'https://zoom.us/oauth/authorize';
	private $_tokenUri = 'https://zoom.us/oauth/token';
	private $_scope = 'meeting:read:meeting meeting:write:meeting meeting:update:meeting meeting:delete:meeting user:read:user';
	private $_testUri = 'https://api.zoom.us/v2/users/me';
	private $_createMeetingUri = 'https://api.zoom.us/v2/users/me/meetings';
	private $_meetingUri = 'https://api.zoom.us/v2/meetings/';
	private $_redirectUri = '';
	
	public function __construct( $integration = false ) {
		$this->_name = 'Zoom Meetings';
		$this->_desc = __('Connect to Zoom API (Meetings, Users)', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
		//$this->_redirectUri = 'https://aiwuplugin.com/wp-json/aiwu/v1/oauth2callback?cur=' . $this->_code;
		$this->_redirectUri = home_url() . '/wp-json/aiwu/v1/oauth2callback?cur=' . $this->_code;
	}
	public function getEndpointUri() {
		return $this->_createMeetingUri;
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
			'scopes'   => array('zoom'),
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
					'direct' => __('Direct (Zoom)', 'ai-copilot-content-generator'),
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
	public function doCreateMeeting( $data ) {
		$error = $this->doConnect(false);
		if (!empty($error)) {
			return array('error' => $error);
		}
		$accessToken = $this->getDecryptedParam('_access_token');
		if (empty($accessToken)) {
			return array('error' => 'Access Token not found');
		}
		$result = array();
		$message = '';
		
		if (empty($data['start'])) {
			return array('error' => 'Start is required');
		}
		$tz = !empty($data['tz']) ? $data['tz'] : 'UTC';
		$start = WaicUtils::convertDateTimeToISO8601($data['start'], $tz);
		$duration = !empty($data['duration']) ? intval($data['duration']) : 30;
		
		$meeting = array(
			'topic' => !empty($data['title']) ? $data['title'] : 'New Meeting',
			'agenda' => !empty($data['description']) ? $data['description'] : '',
			'type' => 2, // Scheduled meeting
			'start_time' => $start,
			'duration' => $duration,
			'timezone' => $tz,
			'settings' => array(
				'join_before_host' => true,
				'approval_type' => 0,
				'registration_type' => 1,
				'audio' => 'both',
				'auto_recording' => 'none',
			),
		);
		$response = wp_remote_post($this->getEndpointUri(), array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type'  => 'application/json',
			),
			'body' => json_encode($meeting),
		));

		if (is_wp_error($response)) {
			return array('error' => 'http_request_failed: ' . $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$results = json_decode($body, true);
		if ($code !== 201) {
			$error = !empty($results['message']) ? $results['message'] : $body;
			return array('error' => 'create_failed (' . $code . '): ' . $error);
		}
		
		$startTime = $results['start_time'] ?? '';
		$endTime = WaicUtils::convertDateTimeToISO8601($data['end'], $tz);
		$createdAt = $results['created_at'] ?? '';

		return array(
			'event_id' => $results['id'] ?? '',
			'event_status' => $results['status'] ?? '',
			'event_created' => $createdAt,
			'event_start' => $startTime,
			'event_end' => $endTime,
			'duration'=> $results['duration'] ?? '',
			'start' => str_replace('T', ' ', substr($startTime, 0, 16)),
			'end' => str_replace('T', ' ', substr($endTime, 0, 16)),
			'meet_link' => $results['join_url'] ?? '',
			'meet_id' => $results['id'] ?? '',
		);
		
		return $result;
	}
	public function doDeleteMeeting( $data ) {
		$error = $this->doConnect(false);
		if (!empty($error)) {
			return array('error' => $error);
		}
		$accessToken = $this->getDecryptedParam('_access_token');
		if (empty($accessToken)) {
			return array('error' => 'Access Token not found');
		}

		if (empty($data['meeting_id'])) {
			return array('error' => 'Meeting ID is required.');
		}

		$response = wp_remote_request($this->_meetingUri . $data['meeting_id'], array(
			'method'  => 'DELETE',
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
			),
		));

		if (is_wp_error($response)) {
			return array('error' => 'http_request_failed: ' . $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 204) {
			$body = wp_remote_retrieve_body($response);
			$results = json_decode($body, true);
			if (!empty($results['message'])) {
				$error = $results['message'];
			} else if (!empty($results['error']) && !empty($results['error']['message'])) {
				$error = $results['error']['message'];
			} else {
				$error = $body;
			}
			return array('error' => 'delete_failed (' . $code . '): ' . $error);
		}

		return array('success' => true);
	}
	public function doUpdateMeeting( $data ) {
		$error = $this->doConnect(false);
		if (!empty($error)) {
			return array('error' => $error);
		}
		$accessToken = $this->getDecryptedParam('_access_token');
		if (empty($accessToken)) {
			return array('error' => 'Access Token not found');
		}

		if (empty($data['meeting_id'])) {
			return array('error' => 'Meeting ID is required.');
		}

		$update = array();
		if (!empty($data['title'])) {
			$update['topic'] = $data['title'];
		}
		if (!empty($data['description'])) {
			$update['agenda'] = $data['description'];
		}
		if (!empty($data['start'])) {
			$update['start_time'] = WaicUtils::convertDateTimeToISO8601($data['start'], $data['tz'] ?? 'UTC');
			if (!empty($data['tz'])) {
				$update['timezone'] = $data['tz'];
			}
		}
		if (!empty($data['duration'])) {
			$update['duration'] = intval($data['duration']);
		}

		$response = wp_remote_request($this->_meetingUri . $data['meeting_id'], array(
			'method'  => 'PATCH',
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type'  => 'application/json',
			),
			'body' => json_encode($update),
		));

		if (is_wp_error($response)) {
			return array('error' => 'http_request_failed: ' . $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 204) {
			$body = wp_remote_retrieve_body($response);
			$results = json_decode($body, true);
			if (!empty($results['message'])) {
				$error = $results['message'];
			} else if (!empty($results['error']) && !empty($results['error']['message'])) {
				$error = $results['error']['message'];
			} else {
				$error = $body;
			}
			return array('error' => 'update_failed (' . $code . '): ' . $error);
		}
		$response = wp_remote_request($this->_meetingUri . $data['meeting_id'], array(
			'method'  => 'GET',
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type'  => 'application/json',
			),
		));
		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$results = json_decode($body, true);
		if ($code !== 200) {
			if (!empty($results['message'])) {
				$error = $results['message'];
			} else if (!empty($results['error']) && !empty($results['error']['message'])) {
				$error = $results['error']['message'];
			} else {
				$error = $body;
			}
			return array('error' => 'get_failed (' . $code . '): ' . $error);
		}
		
		$startTime = $results['start_time'] ?? '';
		$start = str_replace('T', ' ', substr($startTime, 0, 16));
		$duration = $results['duration'] ?? '';
		$endTime = WaicUtils::convertDateTimeToISO8601($start, $data['tz']);
		$createdAt = $results['created_at'] ?? '';

		return array(
			'event_id' => $results['id'] ?? '',
			'event_status' => $results['status'] ?? '',
			'event_created' => $createdAt,
			'event_start' => $startTime,
			'event_end' => $endTime,
			'duration'=> $duration,
			'start' => $start,
			'end' => empty($start) ? '' : WaicUtils::addInterval($start, (int) $duration, 'minutes'),
			'meet_link' => $results['join_url'] ?? '',
			'meet_id' => $results['id'] ?? '',
		);
	}
}
