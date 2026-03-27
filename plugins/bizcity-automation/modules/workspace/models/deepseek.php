<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicDeepseekModel extends WaicModel implements WaicAIProviderInterface {
	public $engine = 'deep-seek';
	private $apiKey;
	private $apiUrl = 'https://api.deepseek.com';
	private $apiOptions = null;
	private $sleep = 20;
	private $lastTime = 0;
	private $headers;
	public $response;
	
	public function getEngine() {
		return $this->engine;
	}
	
	private function getApiChatCompletionsUrl() {
		return $this->apiUrl . '/chat/completions';
	}

	public function init() {
		$this->apiKey = WaicFrame::_()->getModule('options')->get('api', 'deep_seek_api_key');
		add_action('http_api_curl', array($this, 'addSettingsForStreamDeepSeek'));
		return $this;
	}

	public function addSettingsForStreamDeepSeek( $handle ) {
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
		if ($this->apiKey != $real['deep_seek_api_key']) {
			$this->apiKey = $real['deep_seek_api_key'];
		}
		if (empty($this->apiKey)) {
			WaicFrame::_()->pushError(esc_html__('DeepSeek API key not found', 'ai-copilot-content-generator'));
			return false;
		}

		$perMinute = (int) $real['pre_minute'];
		if (1 > $perMinute) {
			$perMinute = 1;
		}
		$this->sleep = round(60 / $perMinute);

		$this->headers = array(
			'Authorization' => 'Bearer ' . $this->apiKey,
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

		if ( !empty($params['deep_seek_model']) ) {
			$params['model'] = $params['deep_seek_model'];
		}

		if (empty($params['model'])) {
			$params['model'] = WaicUtils::getArrayValue($options, 'deep_seek_model', $defaults['deep_seek_model']);
		}
		if (empty($params['temperature'])) {
			$params['temperature'] = (float) WaicUtils::getArrayValue($options, 'temperature', $defaults['temperature'], 1);
		}
		if (empty($params['max_tokens'])) {
			$params['max_tokens'] = (int) WaicUtils::getArrayValue($options, 'tokens', $defaults['tokens'], 1);
		}
		if (!empty($tokens[$params['model']]) && $tokens[$params['model']] < $params['max_tokens']) {
			$params['max_tokens'] = $tokens[$params['model']];
		}
		if (empty($params['frequency_penalty'])) {
			$params['frequency_penalty'] = (float) WaicUtils::getArrayValue($options, 'frequency', $defaults['frequency'], 1);
		}
		if (empty($params['presence_penalty'])) {
			$params['presence_penalty'] = (float) WaicUtils::getArrayValue($options, 'presence', $defaults['presence'], 1);
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

		$url = $this->getApiChatCompletionsUrl();

		return $this->sendRequest($url, 'POST', $params);
	}

	public function getImage( $params ) {
		return array(
			'results' => array(
				'error' => 1,
				'msg' => esc_html__('DeepSeek does not support image generation. Try using another engine.', 'ai-copilot-content-generator'),
				'tokens' => 0,
			),
			'params' => $params,
		);
	}

	private function sendRequest( $url, $method, $params = array(), $type = '' ) {
		//WaicFrame::_()->saveDebugLogging(array('Send request' => $params));
		$fields = empty($params['body']) ? json_encode($params) : $params['body'];

		$stream = false;
		if (array_key_exists('stream', $params) && $params['stream']) {
			$stream = true;
		}

		$options = array(
			'timeout' => 200,
			'headers' => $this->headers,
			'method' => $method,
			'body' => $fields,
			'stream' => $stream,
		);
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

		if (isset($data->error)) {
			$results['msg'] = trim($data->error->message);
			if ( str_contains($results['msg'], 'Authentication Fails') ) {
				$results['msg'] = esc_html__('Incorrect API key provided. You can find your API key at', 'ai-copilot-content-generator') . ' https://www.deepseek.com/';
			}
		} else if (isset($data->choices) && is_array($data->choices)) {
			$results['error'] = 0;
			$results['tokens'] = $data->usage->total_tokens;
			if (!empty($data->choices[0]->message->tool_calls)) {
				$results['tools'] = $data->choices[0]->message->tool_calls;
				$results['tools_message'] = array('role' => 'assistant', 'content' => '', 'tool_calls' => $data->choices[0]->message->tool_calls);
				$results['data'] = 'tool_calls';
			} else if (isset($data->choices[0]->message->content)) {
				$results['data'] = trim($data->choices[0]->message->content);
			} else if (isset($data->choices[0]->text)) {
				$results['data'] = trim($data->choices[0]->text);
			}
			if (empty($results['data'])) {
				$results['error'] = 1;
				if ($results['tokens'] > $params['max_tokens']) {
					$results['msg'] = esc_html__('Generation failed: Max tokens too low. Increase the limit and try again.', 'ai-copilot-content-generator');
				} else {
					$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences (empty data).', 'ai-copilot-content-generator');
				}
			} else {
				$results['length'] = WaicUtils::getCountWords( $results['data'] );
			}
		} else {
			$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences.', 'ai-copilot-content-generator');
		}

		return array('results' => $results, 'params' => $params);
	}
	public function getToolsAnswer( $answer, $tool ) {
		$answer = empty($answer) ? array('result' => false) : array('result' => $answer);
		return array(
			'role' => 'tool',
			'tool_call_id' => empty($tool->id) ? 'call_1' : $tool->id,
			'content' => json_encode($answer),
		);
	}
}
