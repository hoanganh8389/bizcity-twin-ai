<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_googlecalendar extends WaicIntegration {
	protected $_code = 'googlecalendar';
	protected $_category = 'calendar';
	protected $_logo = 'GC';
	protected $_order = 20;
	protected $_privatParams = array('_refresh_token', '_access_token', '_expires_at', '_token_id');
	protected $_signalParams = array('access_code');
	
	private $_provider = 'google';
	private $_authUri = 'https://accounts.google.com/o/oauth2/auth';
	private $_tokenUri = 'https://oauth2.googleapis.com/token';
	private $_scope = 'https://www.googleapis.com/auth/calendar';
	private $_testUri = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';
	private $_createEventUri = 'https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events';
	private $_redirectUri = '';
	
	public function __construct( $integration = false ) {
		$this->_name = 'Google Calendar';
		$this->_desc = __('Connect to Google Calendar API (+ Google Meet)', 'ai-copilot-content-generator');
		$this->setIntegration($integration);
		//$this->_redirectUri = 'https://aiwuplugin.com/wp-json/aiwu/v1/oauth2callback?cur=googlecalendar';
		$this->_redirectUri = home_url() . '/wp-json/aiwu/v1/oauth2callback?cur=' . $this->_code;
	}
	public function getEndpointUri( $calendarId ) {
		return str_replace('{calendarId}', empty($calendarId) ? 'primary' : $calendarId, $this->_createEventUri);
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
			'calendar_id' => array(
				'type' => 'input',
				'label' => __('Calendar ID', 'ai-copilot-content-generator'),
				'plh' => 'primary',
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
		$start = $data['start'];
		$end = empty($data['end']) ? $start : $data['end'];
		$tz = empty($data['tz']) ? false : $data['tz'];
		
		$event = array(
			'start' => strlen($start) > 10 ? array('dateTime' => WaicUtils::convertDateTimeToISO8601($start, $tz)) : array('date' => $start),
			'end' => strlen($end) > 10 ? array('dateTime' => WaicUtils::convertDateTimeToISO8601($end, $tz)) : array('date' => $end),
		);
		if ($withMeeting) {
			$event['conferenceData'] = array(
				'createRequest' => array(
					'requestId' => uniqid(),
					'conferenceSolutionKey' => array('type' => 'hangoutsMeet')
				)
			);
		}
		if (!empty($data['title'])) {
			$event['summary'] = $data['title'];
		}
		if (!empty($data['description'])) {
			$event['description'] = $data['description'];
		}
		$attendees = empty($data['attendees']) ? array() : $data['attendees'];
		if (!empty($attendees) && is_array($attendees)) {
			$list = array();
			foreach ($attendees as $email) {
				if (!empty($email)) {
					$list[] = array('email' => $email);
				}
			}
			if (!empty($list)) {
				$event['attendees'] = $list;
			}
		}
		$calendarId = $this->getParam('calendar_id');
		$response = wp_remote_post($this->getEndpointUri($calendarId) . '?conferenceDataVersion=1', array(
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
			return array('error' => 'create_failed (' . $code . '): ' . $error);
		}

		if ($withMeeting && empty($results['conferenceData'])) {
			return array('error' => 'API not return conferenceData: ' . $body);
		}
		$start = empty($results['start']) ? '' : $results['start'];
		if (!empty($start['dateTime'])) {
			$start = $start['dateTime'];
		} else if (!empty($start['date'])) {
			$start = $start['date'];
		}
		$end = empty($results['end']) ? '' : $results['end'];
		if (!empty($end['dateTime'])) {
			$end = $end['dateTime'];
		} else if (!empty($end['date'])) {
			$end = $end['date'];
		}
		$result = array(
			'event_id' => empty($results['id']) ? '' : $results['id'],
			'event_status' => empty($results['status']) ? '' : $results['status'],
			'event_link' => empty($results['htmlLink']) ? '' : $results['htmlLink'],
			'event_created' => empty($results['created']) ? '' : $results['created'],
			'event_start' => $start,
			'event_end' => $end,
			'start' => str_replace('T', ' ', substr($start, 0, 16)),
			'end' => str_replace('T', ' ', substr($end, 0, 16)),
		);
		if ($withMeeting) {
			$result['meet_link'] = $results['conferenceData']['entryPoints'][0]['uri'] ?? '';
			$result['meet_id'] = empty($results['conferenceData']['conferenceId']) ? '' : $results['conferenceData']['conferenceId'];
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
		$calendarId = $this->getParam('calendar_id');
		$response = wp_remote_request($this->getEndpointUri($calendarId) . '/' . $data['event_id'], array(
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
			$event['summary'] = $data['title'];
		}
		if (!empty($data['description'])) {
			$event['description'] = $data['description'];
		}
		$tz = empty($data['tz']) ? false : $data['tz'];
		if (!empty($data['start'])) {
			$start = $data['start'];
			$event['start'] = strlen($start) > 10 ? array('dateTime' => WaicUtils::convertDateTimeToISO8601($start, $tz)) : array('date' => $start);
			$event['start']['timeZone'] = $tz;
		}
		if (!empty($data['end'])) {
			$end = $data['end'];
			$event['end'] = strlen($end) > 10 ? array('dateTime' => WaicUtils::convertDateTimeToISO8601($end, $tz)) : array('date' => $end);
			$event['end']['timeZone'] = $tz;
		}
		if (!empty($data['cancel_meet']) && 'yes' == $data['cancel_meet']) {
			$event['conferenceData'] = null;
		}
		$attendees = empty($data['attendees']) ? array() : $data['attendees'];
		if (!empty($attendees) && is_array($attendees)) {
			$list = array();
			foreach ($attendees as $email) {
				if (!empty($email)) {
					$list[] = array('email' => $email);
				}
			}
			if (!empty($list)) {
				$event['attendees'] = $list;
			}
		}
		
		$calendarId = $this->getParam('calendar_id');
		$response = wp_remote_request($this->getEndpointUri($calendarId) . '/' . $data['event_id'] . '?conferenceDataVersion=1', array(
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

		$start = empty($results['start']) ? '' : $results['start'];
		if (!empty($start['dateTime'])) {
			$start = $start['dateTime'];
		} else if (!empty($start['date'])) {
			$start = $start['date'];
		}
		$end = empty($results['end']) ? '' : $results['end'];
		if (!empty($end['dateTime'])) {
			$end = $end['dateTime'];
		} else if (!empty($end['date'])) {
			$end = $end['date'];
		}
		$result = array(
			'event_id' => empty($results['id']) ? '' : $results['id'],
			'event_status' => empty($results['status']) ? '' : $results['status'],
			'event_link' => empty($results['htmlLink']) ? '' : $results['htmlLink'],
			'event_created' => empty($results['created']) ? '' : $results['created'],
			'event_start' => $start,
			'event_end' => $end,
			'start' => str_replace('T', ' ', substr($start, 0, 16)),
			'end' => str_replace('T', ' ', substr($end, 0, 16)),
		);
		if (!empty($results['conferenceData'])) {
			$result['meet_link'] = $results['conferenceData']['entryPoints'][0]['uri'] ?? '';
			$result['meet_id'] = empty($results['conferenceData']['conferenceId']) ? '' : $results['conferenceData']['conferenceId'];
		}

		return $result;
	}
}
