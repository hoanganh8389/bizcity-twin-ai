<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_sy_webhook extends WaicTrigger {
	protected $_code = 'sy_webhook';
	protected $_subtype = 3;
	protected $_order = 3;
	protected $_restNamespace = '/wp-json/aiwu/v1';
	protected $_restRoute = '/webhook/';
	
	public function __construct( $block = false ) {
		parent::__construct();
		$this->_name = __('Webhook', 'ai-copilot-content-generator');
		$this->_desc = __('Trigger activated via webhook.', 'ai-copilot-content-generator');
		//$this->_sublabel = array('mode', 'date', 'time', 'frequency', 'units', 'from_date', 'from_time');
		$this->setBlock($block); 
	}
	
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		
		$this->_settings = array(
			'url' => array(
				'type' => 'readonly',
				'label' => __('Webhook URL', 'ai-copilot-content-generator'),
				'text' => home_url() . $this->_restNamespace . $this->_restRoute . '{task_id}_{node_id}/',
				'default' => __('Save the wolkflow to get the Webhook URL', 'ai-copilot-content-generator'),
				'copy' => true,
			),
			'method' => array(
				'type' => 'select',
				'label' => __('Method', 'ai-copilot-content-generator'),
				'options' => array(
					'POST' => 'POST',
					'GET' => 'GET', 
					'PUT' => 'PUT', 
				),
				'default' => 'POST',
			),
			'headers' => array(
				'type' => 'textarea',
				'label' => __('Headers', 'ai-copilot-content-generator'),
				'tooltip' => __('Enter Key & Value of each header on a new line. Separate the Key and Value with a colon.', 'ai-copilot-content-generator') . '</br></br>' . __('Example:', 'ai-copilot-content-generator') . ':</br>x-api-key: xxxxxxxxxxx-xxxxxxxxx</br>Authorization: Bearer xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxx-xxxx',
				'default' => '',
			),
			'format' => array(
				'type' => 'select',
				'label' => __('Data format', 'ai-copilot-content-generator'),
				'options' => array(
					'auto' => 'AUTO',
					'json' => 'JSON',
					'xml' => 'XML', 
					'x-www-form-urlencoded' => 'x-www-form-urlencoded', 
					'form-data' => 'form-data',
				),
				'default' => 'json',
				//'show' => array('method' => array('POST', 'PUT')),
			),
			/*'fields' => array(
				'type' => 'textarea',
				'label' => __('Fields', 'ai-copilot-content-generator'),
				'tooltip' => __('Enter paars Field Key & Field Name of each field on a new line. Separate the Key and Name with a colon. Your nested data keys will be joined together with a forward-slash / to identify parent/child pair.', 'ai-copilot-content-generator') . '</br></br>' . __('Example:', 'ai-copilot-content-generator') . ':</br>user/id: User Indentificator</br>address/line1: Street</br>address/line2: City</br>desc: Description',
				'default' => '',
			),*/
		);
	}
	public function getHook() {
		$hook = array();
		$url = $this->getParam('url');
		$path = $this->_restNamespace . $this->_restRoute;
		$pos = strpos($url, $path, 0);
		if ($pos) {
			$hook['url'] = substr($url, $pos + strlen($this->_restNamespace));
		}
		$hook['method'] = $this->getParam('method', 'POST');
		
		return empty($hook) ? '' : json_encode($hook);
	}
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = array_merge(
			$this->getDTVariables(),
			array('webhook_field' => __('Webhook Field *', 'ai-copilot-content-generator')),
		);
		return $this->_variables;
	}
	public function controlRun( $args = array() ) {
		$request = $args;
		$headers = $this->getParam('headers');
		if (!empty($headers)) {
			$list = preg_split('/\r\n|\r|\n/', $headers);
			foreach ($list as $l) {
				$parts = explode(':', $l);
				if (count($parts) == 2) {
					$key = trim($parts[0]);
					$value = trim($parts[1]);
					$header = $request->get_header($key);
					if (!$header || trim($header) != $value) {
						return false;
					}
				}
			}
		}
		$method = $this->getParam('method');
		$format = $this->getParam('format');
		$params = $request->get_params();
		if ('GET' == $method) {
			if ('json' == $format) {
				foreach ($params as $key => $value) {
					if (is_string($value)) {
						$clean = stripslashes(html_entity_decode(urldecode($value)));
						$decoded = json_decode($clean, true);
						if (json_last_error() === JSON_ERROR_NONE) {
							$params[$key] = $decoded;
						} else {
							$params[$key] = $clean;
						}
					}
				}
			}
		} else {
			if ('xml' == $format) {
				$raw = file_get_contents('php://input');
				libxml_use_internal_errors(true);
				$xml = simplexml_load_string($raw);

				if ($xml === false) {
					wp_send_json_error(array(
						'message' => 'Invalid XML',
						'code' => 'aiwu_invalid_xml'
					), 400);
					return false;
				}

				$params = json_decode(json_encode($xml), true);
			}
		}
		$result = array('date' => date('Y-m-d'), 'time' => date('H:i:s'));
		$fields = WaicUtils::flattenJson($params);
		
		$result = $this->getFieldsArray($fields, 'webhook_field', $result);
		/*foreach ($fields as $key => $value) {
			$result['webhook_field[' . $key . ']'] = $value;
		}*/

		return $result;
	}
}
