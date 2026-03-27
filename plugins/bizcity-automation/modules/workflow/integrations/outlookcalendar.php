<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_outlookcalendar extends WaicIntegration {
	protected $_code = 'outlookcalendar';
	protected $_category = 'calendar';
	protected $_logo = 'OC';
	protected $_order = 22;
	protected $_privatParams = array('_refresh_token', '_access_token', '_expires_at', '_token_id');
	protected $_signalParams = array('access_code');
	
	private $_provider = 'outlook';
	private $_authUri = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
	private $_tokenUri = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
	private $_scope = 'OnlineMeetings.ReadWrite Calendars.ReadWrite User.Read offline_access';
	private $_testUri = 'https://graph.microsoft.com/v1.0/me';
	private $_eventUri = 'https://graph.microsoft.com/v1.0/me/events';
	private $_redirectUri = '';
	
	public function __construct( $integration = false ) {
		$this->_name = 'Outlook Calendar';
		$this->_desc = __('Connect to Outlook Calendar API (+ Teams Meetings)', 'ai-copilot-content-generator');
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
		$body = json_encode(array(
			'provider' => $this->_provider,
			'url'      => home_url(),
			'scopes'   => array('googlemeet'),
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
			'oauth2' => array(
				'type' => 'button',
				'label' => '',
				'btn_label' => __('Connect account →', 'ai-copilot-content-generator'),
				'link' => $this->_authUri . '?client_id={client_id}&redirect_uri={redirect_uri}&response_type=code&scope=' . urlencode($this->_scope) . '&response_mode=query',
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
				'scope' => $this->_scope,
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

	public function doCreateMeeting( $data ) {
		return $this->doCreateEvent($data, true);
	}
	public function doCreateEvent( $data, $withMeeting = false ) {
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
		$tz = $data['tz'] ?? 'UTC';
		
		$event = array(
			'subject' => !empty($data['title']) ? $data['title'] : '',
			'body' => array(
				'contentType' => 'HTML',
				'content' => !empty($data['description']) ? $data['description'] : ''
			),
			'start' => array(
				'dateTime' => WaicUtils::convertDateTimeToISO8601($data['start'], $tz),
				'timeZone' => $tz,
			),
			'end' => array(
				'dateTime' => WaicUtils::convertDateTimeToISO8601($data['end'] ?? $data['start'], $tz),
				'timeZone' => $tz,
			),
			'attendees' => array()
		);

		if (!empty($data['attendees']) && is_array($data['attendees'])) {
			foreach ($data['attendees'] as $email) {
				$event['attendees'][] = array(
					'emailAddress' => array('address' => $email),
					'type' => 'required'
				);
			}
		}

		if ($withMeeting) {
			$event['isOnlineMeeting'] = true;
			$event['onlineMeetingProvider'] = 'teamsForBusiness';
		}
		$response = wp_remote_post($this->_eventUri, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type' => 'application/json',
			),
			'body' => json_encode($event),
		));
		
		if (is_wp_error($response)) {
			return array('error' => 'http_request_failed: ' . $response->get_error_message());
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		$results = json_decode($body, true);

		if ($code !== 201) { // Graph returns 201 Created
			return array('error' => 'create_failed (' . $code . '): ' . $body);
		}
		
		$start = empty($results['start']['dateTime']) ? '' : $results['start']['dateTime'];
		$end = empty($results['end']['dateTime']) ? '' : $results['end']['dateTime'];

		$result = array(
			'event_id' => $results['id'] ?? '',
			'event_link' => $results['webLink'] ?? '',
			'event_start' => $start,
			'event_end' => $end,
			'start' => str_replace('T', ' ', substr($start, 0, 16)),
			'end' => str_replace('T', ' ', substr($end, 0, 16)),
		);

		if ($withMeeting && !empty($results['onlineMeeting'])) {
			$result['meet_link'] = $results['onlineMeeting']['joinUrl'] ?? '';
			$result['meet_id'] = $results['onlineMeeting']['conferenceId'] ?? '';
		}

		return $result;
	}
	public function doDeleteEvent( $data ) {
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
		
		if (empty($data['event_id'])) {
			return array('error' => 'Event ID is required.');
		}
		$response = wp_remote_request($this->_eventUri . '/' . $data['event_id'], array(
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
			 if (!empty($results['error']['message'])) {
				$error = $results['error']['message'];
			} else {
				$error = $body;
			}
			return array('error' => 'delete_failed (' . $code . '): ' . $error);
		}

		return array('success' => true);
	}
	public function doUpdateEvent( $data ) {
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
		
		if (empty($data['event_id'])) {
			return array('error' => 'Event ID is required.');
		}
		$event = array();
		if (!empty($data['title'])) {
			$event['subject'] = $data['title'];
		}

		if (!empty($data['description'])) {
			$event['body'] = array(
				'contentType' => 'HTML',
				'content' => $data['description']
			);
		}
		
		$tz = empty($data['tz']) ? false : $data['tz'];
		if (!empty($data['start'])) {
			$event['start'] = array(
				'dateTime' => WaicUtils::convertDateTimeToISO8601($data['start'], $tz),
				'timeZone' => $tz
			);
		}

		if (!empty($data['end'])) {
			$event['end'] = array(
				'dateTime' => WaicUtils::convertDateTimeToISO8601($data['end'], $tz),
				'timeZone' => $tz
			);
		}
		

		$attendees = empty($data['attendees']) ? array() : $data['attendees'];
		if (!empty($attendees) && is_array($attendees)) {
			$list = array();
			foreach ($attendees as $email) {
				if (!empty($email)) {
					$list[] = array(
						'emailAddress' => array('address' => $email),
						'type' => 'required'
					);
				}
			}
			if (!empty($list)) {
				$event['attendees'] = $list;
			}
		}
		$response = wp_remote_request($this->_eventUri . '/' . $data['event_id'], array(
			'method'  => 'PATCH',
			'headers' => array(
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type'  => 'application/json',
			),
			'body' => json_encode($event),
		));

		if (is_wp_error($response)) {
			return array('error' => 'http_request_failed: ' . $response->get_error_message());
		}

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
			return array('error' => 'update_failed (' . $code . '): ' . $error);
		}

		$start = !empty($results['start']['dateTime']) ? $results['start']['dateTime'] : '';
		$end   = !empty($results['end']['dateTime']) ? $results['end']['dateTime'] : '';

		$result = array(
			'event_id'      => $results['id'] ?? '',
			'event_status'  => $results['status'] ?? '',
			'event_link'    => $results['webLink'] ?? '',
			'event_created' => $results['createdDateTime'] ?? '',
			'event_start'   => $start,
			'event_end'     => $end,
			'start'         => str_replace('T', ' ', substr($start, 0, 16)),
			'end'           => str_replace('T', ' ', substr($end, 0, 16)),
		);

		return $result;
	}
}
