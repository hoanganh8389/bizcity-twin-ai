<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_send_webhook extends WaicAction {
	protected $_code = 'wp_send_webhook';
	protected $_order = 1;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Webhook', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': wp_login';
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
				'type' => 'input',
				'label' => __('URL *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'method' => array(
				'type' => 'select',
				'label' => __('Method', 'ai-copilot-content-generator'),
				'options' => array(
					'POST' => 'POST',
					'GET' => 'GET', 
					'PUT' => 'PUT',
					'PATCH' => 'PATCH',
					'DELETE' => 'DELETE', 
				),
				'default' => 'POST',
			),
			'headers' => array(
				'type' => 'textarea',
				'label' => __('Headers', 'ai-copilot-content-generator'),
				'tooltip' => __('Enter Key & Value of each header on a new line. Separate the Key and Value with a colon.', 'ai-copilot-content-generator') . '</br></br>' . __('Example:', 'ai-copilot-content-generator') . ':</br>x-api-key: xxxxxxxxxxx-xxxxxxxxx</br>Authorization: Bearer xxxxx-xxxxx-xxxxxxxxxxxxxxxxxxx-xxxx',
				'default' => '',
				'variables' => true,
			),
			'format' => array(
				'type' => 'select',
				'label' => __('Data format', 'ai-copilot-content-generator'),
				'options' => array(
					'json' => 'JSON',
					'xml' => 'XML', 
					'x-www-form-urlencoded' => 'x-www-form-urlencoded', 
					'form-data' => 'form-data',
					'text' => 'TEXT',
					'html' => 'HTML',
				),
				'default' => 'json',
				'show' => array('method' => array('POST', 'PUT', 'PATCH', 'DELETE')),
			),
			'fields' => array(
				'type' => 'textarea',
				'label' => __('Fields', 'ai-copilot-content-generator'),
				'tooltip' => __('Enter paars Key & Value of each field on a new line. Separate the Key and Name with a colon. Separate keys with / to build nested data.', 'ai-copilot-content-generator') . '</br></br>' . __('Example:', 'ai-copilot-content-generator') . ':</br>user/id: 1</br>address/streen: 128 Main St.</br>address/city: Mexico</br>desc: New User',
				'default' => '',
				'variables' => true,
			),
		);
	}
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = array(
			'webhook_code' => __('Response Code', 'ai-copilot-content-generator'),
			'webhook_error' => __('Webhook Error', 'ai-copilot-content-generator'),
			'webhook_field' => __('Webhook Field *', 'ai-copilot-content-generator'),
			'webhook_result' => __('Webhook Result *', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$url = $this->replaceVariables($this->getParam('url'), $variables);
		$method = $this->getParam('method', 'POST');
		$headers = $this->replaceVariables($this->getParam('headers'), $variables);
		$format = $this->getParam('format');
		$fields = $this->replaceVariables($this->getParam('fields'), $variables);
		
		$error = '';
		if (empty($url)) {
			$error = 'Url is empty';
		} else if (empty($method)) {
			$error = 'Method is empty';
		} else if (empty($format) && 'GET' != $method) {
			$error = 'Data format is empty';
		}
		
		$sendHeaders = array();
		$body = null;
		$data = array();
		$args = array();
		$webhookError = '';
		$webhookFields = array();
		$webhookResults = array();
		$webhookCode = '';
		if (empty($error)) {
			if (!empty($headers)) {
				$list = preg_split('/\r\n|\r|\n/', $headers);
				foreach ($list as $l) {
					$parts = explode(':', $l);
					if (count($parts) == 2) {
						$sendHeaders[trim($parts[0])] = trim($parts[1]);
					}
				}
			}
			if (!empty($fields)) {
				$list = preg_split('/\r\n|\r|\n/', $fields);
				foreach ($list as $l) {
					if (empty($l)) {
						continue;
					}
					$parts = explode(':', $l);
					if (count($parts) > 0) {
						$key = trim($parts[0]);
						if (empty($key)) {
							continue;
						}
						$value = empty($parts[1]) ? '' : trim($parts[1]);
						$webhookFields['webhook_field[' . $key . ']'] = $value;
						$keys = explode('/', $key);
						$cnt = count($keys);
						$ref = &$data;
						
						foreach ($keys as $i => $k) {
							if ($i === $cnt - 1) {
								$ref[$k] = $value;
							} else {
								if (!isset($ref[$k]) || !is_array($ref[$k])) {
									$ref[$k] = array();
								}
								$ref = &$ref[$k];
							}
						}
					}
				}
			}
			
			switch ($format) {
				case 'json':
					$sendHeaders['Content-Type'] = 'application/json';
					$body = json_encode($data);
				break;
				case 'xml':
					$sendHeaders['Content-Type'] = 'application/xml';
					$body = WaicUtils::arrayToXml($data);
					break;
				case 'x-www-form-urlencoded':
					$sendHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
					$body = http_build_query($data);
					break;
				case 'form-data':
					$body = $data;
					break;
				case 'text':
					$sendHeaders['Content-Type'] = 'text/plain';
					$body = is_array($data) ? implode("\n", $data) : (string) $data;
					break;
				case 'html':
					$sendHeaders['Content-Type'] = 'text/html';
					$body = is_array($data) ? implode('', $data) : (string) $data;
					break;
			}
			$args = array(
				'method'  => $method,
				'headers' => $sendHeaders,
				'timeout' => 15,
			);
			if ('GET' === $method) {
				$url = add_query_arg($data, $url);
			} else {
				$args['body'] = $body;
			}
			
			$response = wp_remote_request($url, $args);
			if (is_wp_error($response)) {
				$webhookError = $response->get_error_message();
			} else {
				$data = wp_remote_retrieve_body($response);
				$results = WaicUtils::responseToArray($response);
				if (!empty($results)) {
					$webhookResults = $this->getFieldsArray(WaicUtils::flattenJson($results), 'webhook_result', array());
				}
			}
			$webhookCode = wp_remote_retrieve_response_code($response);
		}
				
		$this->_results = array(
			'result' => array_merge(array(
					'webhook_error' => $webhookError,
					'webhook_code' => $webhookCode,
				),
				$webhookFields,
				$webhookResults,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
