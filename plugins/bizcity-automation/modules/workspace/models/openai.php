<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicOpenaiModel extends WaicModel implements WaicAIProviderInterface {
	public $engine = 'open-ai';
	private $apiKey;
	private $sleep = 20;
	private $lastTime = 0;

	private $headers;
	private $timeout = 200;
	public $response;
	
	private $streamMethod = null;
	private $apiOptions = null;
	//private $apiPrompts = null;
	private $apiUrl = 'https://api.openai.com';
	private $apiVersion = 'v1';
	
	public function getEngine() {
		return $this->engine;
	}
	
	public function getApiCompletionsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/completions';
	}
	public function getApiChatCompletionsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/chat/completions';
	}
	public function getApiImageUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/images/generations';
	}
	public function getApiUploadUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/files';
	}
	public function getApiFineTunesUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/fine_tuning/jobs';
	}
	public function getApiEmbeddingsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/embeddings';
	}

	public function init() {
		$this->apiKey = WaicFrame::_()->getModule('options')->get('api', 'api_key');
		add_action('http_api_curl', array($this, 'addSettingsForStreamOpenAI'));
		return $this;
	}
	
	public function setTimeout( $timeout ) {
		$this->timeout = $timeout;
	}
	
	public function isLegacyModels( $model ) {
		$legacyModels = array(
			'text-davinci-001',
			'davinci',
			'babbage',
			'text-babbage-001',
			'curie-instruct-beta',
			'text-davinci-003',
			'text-curie-001',
			'davinci-instruct-beta',
			'text-davinci-002',
			'ada',
			'text-ada-001',
			'curie',
			'gpt-3.5-turbo-instruct',
		);
		return in_array($model, $legacyModels);
	}

	public function addSettingsForStreamOpenAI( $handle ) {
		if (null !== $this->streamMethod) {
			curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ( $info, $data ) {
				return call_user_func($this->streamMethod, $this, $data);
			});
		}
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
		if ($this->apiKey != $real['api_key']) {
			$this->apiKey = $real['api_key'];
		}
		if (empty($this->apiKey)) {
			$this->apiKey = get_option('twf_openai_api_key');
			WaicFrame::_()->pushError(esc_html__('Đang kết nối BizGPT', 'ai-copilot-content-generator'));
			#return false;
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
		if (null != $stream && array_key_exists('stream', $params)) {
			if (!$params['stream']) {
				WaicFrame::_()->pushError(esc_html__('Please provide a stream function.', 'ai-copilot-content-generator'));
				return false;
			}
			$this->streamMethod = $stream;
		}
		$options = $this->apiOptions;
		if (empty($params['prompt']) && empty($params['messages'])) {
			WaicFrame::_()->pushError(esc_html__('Error: prompt is empty.', 'ai-copilot-content-generator'));
			return false;
		}
		$defaults = WaicFrame::_()->getModule('options')->getModel()->getDefaults('api');
		$tokens = WaicFrame::_()->getModule('options')->getModel()->getVariations('api', 'tokens');
		
		if (empty($params['model'])) {
			$params['model'] = WaicUtils::getArrayValue($options, 'model', $defaults['model']);
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

		if ($this->isLegacyModels($params['model'])) { 
			$url = $this->getApiCompletionsUrl();
		} else {
			$chatModel = true;
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
		}
		$newModels = array('gpt-5', 'gpt-5-mini', 'gpt-5-nano');
		if (in_array($params['model'], $newModels)) {
			$params['max_completion_tokens'] = $params['max_tokens'];
			unset($params['max_tokens'], $params['temperature'], $params['presence_penalty'], $params['frequency_penalty']);
		}
		
		return $this->sendRequest($url, 'POST', $params);
	}
	public function getImage( $params ) {
		$options = $this->apiOptions;
		
		$defaults = WaicFrame::_()->getModule('options')->getModel()->getDefaults('api');
		
		if (empty($params['prompt'])) {
			WaicFrame::_()->pushError(esc_html__('Error: prompt is empty.', 'ai-copilot-content-generator'));
			return false;
		}
		if (empty($params['model'])) {
			$params['model'] = WaicUtils::getArrayValue($options, 'img_model', $defaults['img_model']);
		}
		if ('dall-e-3-hd' == $params['model']) {
			$params['model'] = 'dall-e-3';
			$params['quality'] = 'hd';
		}
		/*if (empty($params['size']) || ( 'dall-e-3' == $params['model'] && ( '512x512' == $params['size'] || '256x256' == $params['size'] ) ) ) {
			$params['size'] = '1024x1024';
		}*/
		$params['size'] = $this->getImageSize(WaicUtils::getArrayValue($params, 'size'), $params['model']);

		if (empty($params['n'])) {
			$params['n'] = 1;
		}

		$url = $this->getApiImageUrl();  
		return $this->sendRequest($url, 'POST', $params, 'image');
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
		if (isset($params['gemini_size'])) {
			unset($params['gemini_size']);
		}
		//WaicFrame::_()->saveDebugLogging(array('endpoint' => $url, 'Send request' => $params));

		$stream = false;
		if (array_key_exists('stream', $params) && $params['stream']) {
			$stream = true;
		}
		//$isChat = false;
		$options = array(
			'timeout' => $this->timeout,
			'headers' => $this->headers,
			'method' => $method,
			//'body' => $fields,
			'stream' => $stream,
		);
		$forLog = $options;
		if ('POST' == $method) {
			$forLog['body'] = empty($params['body']) ? $params : $params['body'];
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
			if (empty($result['msg']) && isset($data->error->code) && 'invalid_api_key' == $data->error->code) {
				$results['msg'] = esc_html__('Incorrect API key provided. You can find your API key at', 'ai-copilot-content-generator') . ' https://platform.openai.com/account/api-keys.';
			}
			if (strpos($results['msg'], 'exceeded your current quota') !== false) {
				$results['msg'] .= ' ' . esc_html__('Please note that this message is coming from OpenAI and it is not related to our plugin. It means that you do not have enough credit from OpenAI. You can check your usage here:', 'ai-copilot-content-generator') . ' https://platform.openai.com/account/usage';
			}
		} else if (isset($data->choices) && is_array($data->choices)) {
			$results['error'] = 0;
			if (!empty($data->choices[0]->message->tool_calls)) {
				$results['tools'] = $data->choices[0]->message->tool_calls;
				$results['data'] = 'tool_calls';
			} else if (isset($data->choices[0]->message->content)) {
				$results['data'] = trim($data->choices[0]->message->content);
			} else if (isset($data->choices[0]->text)) {
				$results['data'] = trim($data->choices[0]->text);
			} else if (!empty($data->choices[0]->tool_calls)) {
				$results['tools'] = $data->choices[0]->tool_calls;
				$results['data'] = 'tool_calls';
			}
			if (empty($results['data'])) {
				$results['error'] = 1;
				$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences (empty data).', 'ai-copilot-content-generator');
			} else {
				$results['length'] = WaicUtils::getCountWords( $results['data'] );
			}
		} else if ('image' == $type && isset($data->data) && is_array($data->data)) {
			$results['error'] = 0;
			$results['data'] = sanitize_url($data->data[0]->url);
		} else if ('file' == $type && isset($data->id)) {
			$results['error'] = 0;
			$results['file_id'] = $data->id;
		} else if ('ft-job' == $type) {
			$results['error'] = 0;
			$results['data'] = $data;
		} else if ('embeddings' == $type && isset($data->data) ) {
			$results['error'] = 0;
			$results['data'] = $data->data;
		} else {
			$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences.', 'ai-copilot-content-generator');
		}

		return array('results' => $results, 'params' => $params);
	}
	
	public function getImageSize( $orient = '', $model = '' ) {
		$def = '1024x1024';
		if ('dall-e-2' == $model) {
			return $def;
		}
		$sizes = array(
			'horizontal' => '1792x1024',
			'vertical' => '1024x1792',
			'square' => '1024x1024',
		);
		return empty($orient) ? '1024x1024' : ( isset($sizes[$orient]) ? $sizes[$orient] : $orient );
	}
	public function getFineTunes( $params, $method = 'POST', $job = false ) {
		$url = $this->getApiFineTunesUrl();
		if (!empty($job)) {
			$url .= '/' . $job;
		}
		return $this->sendRequest($url, $method, $params, 'ft-job');
	}
	public function sendEmbeddings( $params, $method = 'POST' ) {
		$url = $this->getApiEmbeddingsUrl();
		
		if (empty($params['model'])) {
			$params['model'] = WaicUtils::getArrayValue($this->apiOptions, 'model');
		}
		return $this->sendRequest($url, $method, $params, 'embeddings');
	}
	public function getToolsAnswer( $answer, $tool ) {
		return array(
			'role' => 'tool',
			'tool_call_id' => empty($tool->id) ? 'call_1' : $tool->id,
			'content' => array(array('type' => 'text', 'text' => json_encode($answer))),
		);
	}
}
