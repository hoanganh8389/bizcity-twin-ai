<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegration_discord extends WaicIntegration {
	protected $_code = 'discord';
	protected $_category = 'messenger';
	protected $_logo = 'DI';
	protected $_order = 43;
	private $_maxAttempts = 3;
	
	public function __construct( $integration = false ) {
		$this->_name = 'Discord';
		$this->_desc = __('Connect to Discord', 'ai-copilot-content-generator');
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
			'webhook' => array(
				'type' => 'input',
				'label' => __('Webhook Url', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'encrypt' => true,
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
		$webhook = $this->getDecryptedParam('webhook');
		if (empty($webhook)) {
			return 'Webhook Url is required';
		}
		$response = wp_remote_get($webhook);

		if (is_wp_error($response)) {
			return 'Discord webhook check error: ' . $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($code !== 200) {
			return 'Webhook invalid, HTTP code: ' . $code . ', body: ' . $body;
		}
        
		return false;
	}
	
	public function doSendMessage( $data ) {
		$webhook = $this->getDecryptedParam('webhook');
		if (empty($webhook)) {
			$data['error'] = 'Webhook Url is required';
			return $data;
		}
		$maxAttempts = $this->_maxAttempts;
		$attempt = 0;
		$success = false;
		$payload = json_encode($data);
		$error = '';

		while ($attempt < $maxAttempts && !$success) {
			$attempt++;
			$response = wp_remote_post($webhook, array(
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

				if (200 == $сode || 204 == $сode) {
					$success = true;
				} else {
					$success = false;
					$bodyArr = json_decode($body, true);
					if (429 === $сode && is_array($bodyArr) && isset($bodyArr['retry_after'])) {
						$ms = (int) $bodyArr['retry_after'];
						$error = 'Rate limit hit. Retry after: ' . $ms . ' ms';
						if ($attempt < $maxAttempts) {
							usleep(1000 * $ms); 
						}
					} else {
						if (isset($bodyArr['message'])) {
							$error = 'Discord API error:' . $bodyArr['message'];
						} else {
							$error = 'Discord API returned error. Status: ' . $сode . ', Response: ' . $body;
						}
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
}
