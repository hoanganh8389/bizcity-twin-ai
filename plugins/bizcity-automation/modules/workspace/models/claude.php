<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicClaudeModel extends WaicModel implements WaicAIProviderInterface {
	public $engine = 'claude';
	private $apiKey;
	private $sleep = 20;
	private $lastTime = 0;

	private $headers;
	private $timeout = 200;
	public $response;
	
	private $streamMethod = null;
	private $apiOptions = null;
	private $apiUrl = 'https://api.anthropic.com';
	private $apiVersion = 'v1';
	
	public function getEngine() {
		return $this->engine;
	}
	
	public function getApiCompletionsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/messages';
	}
	public function getApiUploadUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/files';
	}

	public function init() {
		$this->apiKey = WaicFrame::_()->getModule('options')->get('api', 'claude_api_key');
		add_action('http_api_curl', array($this, 'addSettingsForStreamClaude'));
		return $this;
	}
	
	public function setTimeout( $timeout ) {
		$this->timeout = $timeout;
	}
	
	public function addSettingsForStreamClaude( $handle ) {
		curl_setopt($handle, CURLOPT_TIMEOUT, 200);
	}
	public function setApiOptions( $options ) {
		$real = WaicFrame::_()->getModule('options')->get('api');
		foreach ($options as $key => $value) {
			if (!empty($value)) {
				if (!isset($real[$key]) || $real[$key] != $value) {
					$real[$key] = $value;
				}
			}
		}
		$this->apiOptions = $real;
		if ($this->apiKey != $real['claude_api_key']) {
			$this->apiKey = $real['claude_api_key'];
		}
		if (empty($this->apiKey)) {
			WaicFrame::_()->pushError(esc_html__('Claude API key not found', 'ai-copilot-content-generator'));
			return false;
		}
		
		$perMinute = (int) $real['pre_minute'];
		if (1 > $perMinute) {
			$perMinute = 1;
		}
		$this->sleep = round(60 / $perMinute);

		$this->headers = array(
			'x-api-key' => $this->apiKey,
			'anthropic-version' => '2023-06-01',
			'Content-Type' => ( empty($options['contentType']) ? 'application/json' : $options['contentType'] ),
		);
		return true;
	}
	public function getText( $params, $stream = null ) {
		$options = $this->apiOptions;
		if (empty($params['prompt']) && empty($params['messages'])) {
			WaicFrame::_()->pushError(esc_html__('Error: prompt is empty.', 'ai-copilot-content-generator'));
			return false;
		}
		$defaults = WaicFrame::_()->getModule('options')->getModel()->getDefaults('api');
		$tokens = WaicFrame::_()->getModule('options')->getModel()->getVariations('api', 'tokens');
		
		if (empty($params['model'])) {
			$params['model'] = WaicUtils::getArrayValue($options, 'claude_model', $defaults['claude_model']);
		}
		/*if (empty($params['temperature'])) {
			$params['temperature'] = (float) WaicUtils::getArrayValue($options, 'temperature', $defaults['temperature'], 1);
		}*/
		if (empty($params['max_tokens'])) {
			$params['max_tokens'] = (int) WaicUtils::getArrayValue($options, 'tokens', $defaults['tokens'], 1);
		}
		if (!empty($tokens[$params['model']]) && $tokens[$params['model']] < $params['max_tokens']) {
			$params['max_tokens'] = $tokens[$params['model']];
		}
		if (empty($params['messages'])) {
			$params['messages'] = array(
				array(
					'role' => 'user',
					'content' => $params['prompt'],
				),
			);
			unset($params['prompt']);
		}
		$remove = array();
		foreach ($params['messages'] as $i => $message) {
			if ('system' == $message['role']) {
				$params['system'] = $message['content'];
				$remove[] = $i;
			}
			if (is_array($message['content'])) {
				foreach ($message['content'] as $j => $content) {
					if (isset($content['type']) && 'image_url' == $content['type']) {
						//data:image/' . $extension . ";base64,
						$data = $content['image_url']['url'];
						$pos = strpos($data, ';base64,', 0);
						$params['messages'][$i]['content'][$j] = array(
							'type' => 'image',
							'source' => array(
								'type' => 'base64',
								'media_type' => $pos > 0 ? substr($data, 5, $pos - 5) : 'image/jpeg',
								'data' => $pos > 0 ? substr($data, $pos + 8) : $data,
							),
						);
					}
				}
			}
		}
		if (!empty($remove)) {
			foreach ($remove as $i) {
				unset($params['messages'][$i]);
			}
			$params['messages'] = array_values($params['messages']);
		}
		
		if (!empty($params['tools'])) {
			$fns = array();
			foreach ($params['tools'] as $tool) {
				if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function'])) {
					$fns[] = array(
						'name' => $tool['function']['name'],
						'description' => $tool['function']['description'],
						'input_schema' => $tool['function']['parameters'],
					);
				}
			}
			if (!empty($fns)) {
				$params['tools'] = $fns;
			}
		}
		
		$url = $this->getApiCompletionsUrl();
		return $this->sendRequest($url, 'POST', $params);
	}
	public function getImage( $params ) {
		return array();
	}
	public function sendFile( $params ) {
		
		$boundary = wp_generate_uuid4();
		$body = "--$boundary\r\n";
		$body .= 'Content-Disposition: form-data; name="purpose"' . "\r\n\r\n";
		$body .= 'fine-tune' . "\r\n";
		$body .= "--$boundary\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $params['file_name'] . "\"\r\n";
		$body .= "Content-Type: application/octet-stream\r\n\r\n";
		$body .= $params['file_data'] . "\r\n";
		$body .= "--$boundary--\r\n";
		
		$this->headers['Content-Type'] = "multipart/form-data; boundary=$boundary";
		$this->headers['Content-Length'] = strlen($body);
		
		$url = $this->getApiUploadUrl();
		$params = array('body' => $body);
		return $this->sendRequest($url, 'POST', $params, 'file');
	}
	private function sendRequest( $url, $method, $params = array(), $type = '' ) {
		$stream = false;
		if (array_key_exists('stream', $params) && $params['stream']) {
			$stream = true;
		}
		
		$options = array(
			'timeout' => $this->timeout,
			'headers' => $this->headers,
			'method' => $method,
			'stream' => $stream,
		);
		if ('POST' == $method) {
			$fields = empty($params['body']) ? json_encode($params) : $params['body'];
			$options['body'] = $fields;
		}
		WaicFrame::_()->saveDebugLogging(array('endpoint' => $url, 'Send request' => $options));
		$pause = time() - $this->lastTime;
		if ($pause < $this->sleep) {
			sleep($this->sleep - $pause);
		}
		$response = wp_remote_request($url, $options);
		if (is_wp_error($response)) {
			WaicFrame::_()->pushError($response->get_error_message());
			return false;
		}
		if ($stream) {
			$data = $this->response;
		} else {
			$data = wp_remote_retrieve_body($response);
		}
		$this->lastTime = time();
		WaicFrame::_()->saveDebugLogging(array('Result from API' => $data));
		$results = array('error' => 1, 'his_id' => 0, 'tokens' => 0, 'length' => 0, 'data' => '');
		$data = json_decode( $data );
		if (isset($data->usage) && isset($data->usage->total_tokens)) {
			$results['tokens'] = $data->usage->total_tokens;
		}
		if (isset($data->error) && isset($data->error->message)) {
			$results['msg'] = trim($data->error->message);
			if (empty($results['msg']) && isset($data->error->type)) {
				$results['msg'] = $data->error->type;
			}
		} else if (isset($data->content) && is_array($data->content)) {
			$results['error'] = 0;
			if (!empty($data->content[0]->type) && $data->content[0]->type == 'tool_use') {
				$results = $this->addToolsRequest($results, $data->content[0]);
			} else if (!empty($data->content[1]->type) && $data->content[1]->type == 'tool_use') {
				$results = $this->addToolsRequest($results, $data->content[1]);
			} else if (isset($data->content[0]->text)) {
				$results['data'] = trim($data->content[0]->text);
				$results['error'] = 0;
			}
			if (empty($results['data'])) {
				$results['error'] = 1;
				$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences (empty data).', 'ai-copilot-content-generator');
			} else {
				$results['length'] = WaicUtils::getCountWords( $results['data'] );
			}
		} else if ('file' == $type && isset($data->id)) {
			$results['error'] = 0;
			$results['file_id'] = $data->id;
		} else {
			$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences.', 'ai-copilot-content-generator');
		}

		return array('results' => $results, 'params' => $params);
	}
	public function addToolsRequest( $results, $content ) {
		$obj = new stdClass();
		$obj->function = new stdClass();
		$obj->function->id = empty($content->id) ? 'toolu_1' : $content->id;
		$obj->function->name = empty($content->name) ? '' : $content->name;
		$obj->function->arguments = empty($content->input) ? '' : json_encode($content->input);
		
		$results['tools'] = array($obj);
		$results['tools_message'] = array('role' => 'assistant', 'content' => array(json_decode(json_encode($content), true)));
		$results['data'] = 'tool_calls';
		$results['error'] = 0;
		return $results;
	}
	public function getToolsAnswer( $answer, $tool ) {
		$answer = empty($answer) ? array('result' => false) : array('result' => $answer);
		return array(
			'role' => 'user',
			'content' => array(array(
				'type' => 'tool_result',
				'tool_use_id' => $tool->function->id,
				'content' => json_encode($answer),
			))
		);
	}
}
