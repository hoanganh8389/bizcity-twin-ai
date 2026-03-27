<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicOpenrouterModel extends WaicModel implements WaicAIProviderInterface {
	public $engine = 'openrouter';
	private $apiKey;
	private $sleep = 20;
	private $lastTime = 0;

	private $headers;
	private $timeout = 200;
	public $response;
	
	private $streamMethod = null;
	private $apiOptions = null;
	//private $apiPrompts = null;
	private $apiUrl = 'https://openrouter.ai/api';
	private $apiVersion = 'v1';
	
	public function getEngine() {
		return $this->engine;
	}
	
	public function getApiChatCompletionsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/chat/completions';
	}
	public function getApiModelsUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/models';
	}
	public function getApiImageUrl() {
		return $this->apiUrl . '/' . $this->apiVersion . '/chat/completions';
	}

	public function init() {
		$this->apiKey = WaicFrame::_()->getModule('options')->get('api', 'openrouter_api_key');
		add_action('http_api_curl', array($this, 'addSettingsForStreamOpenRouter'));
		return $this;
	}
	
	public function setTimeout( $timeout ) {
		$this->timeout = $timeout;
	}
	
	public function addSettingsForStreamOpenRouter( $handle ) {
		if (null !== $this->streamMethod) {
			//curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
			//curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
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
		if (empty($real['openrouter_api_key'])) {
			$real['openrouter_api_key'] = '';
		}
		$this->apiOptions = $real;
		if ($this->apiKey != $real['openrouter_api_key']) {
			$this->apiKey = $real['openrouter_api_key'];
		}
		if (empty($this->apiKey)) {
			WaicFrame::_()->pushError(esc_html__('OpenRouter API key not found', 'ai-copilot-content-generator'));
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
	public function getModels() {
		$url = $this->getApiModelsUrl();
		$options = array(
			'headers' => $this->headers,
			'timeout' => $this->timeout,
		);
		WaicFrame::_()->saveDebugLogging(array('endpoint' => $url, 'Send request' => $options));
		
		$response = wp_remote_get($url, $options);

		if (is_wp_error($response)) {
			WaicFrame::_()->pushError($response->get_error_message());
			return false;
		}
		$data = wp_remote_retrieve_body($response);
		WaicFrame::_()->saveDebugLogging(array('Result from API' => $data));
		$models = array();
		$images = array();
		$tokens = array();
		$data = json_decode($data, true);
		if (!empty($data['data'])) {
			foreach ($data['data'] as $model) {
				if (!isset($model['architecture'])) {
					continue;
				}
				$output = WaicUtils::getArrayValue($model['architecture'], 'output_modalities', array(), 2);
				
				$id = $model['id'];
				$name = isset($model['name']) ? $model['name'] : $id;
				$maxTokens = 16000;
				if (isset($model['top_provider'])) {
					if (isset($model['top_provider']['max_completion_tokens'])) {
						$maxTokens = $model['top_provider']['max_completion_tokens'];
					} else if (isset($model['top_provider']['context_length'])) {
						$maxTokens = $model['top_provider']['context_length'];
					}
				} else if (isset($model['context_length'])) {
					$maxTokens = $model['context_length'];
				}
				$tokens[$id] = $maxTokens;
				
				if (in_array('text', $output)) {
					$models[$id] = $name;
				}
				if (in_array('image', $output)) {
					$images[$id] = $name;
				}
			}
        }
		if (empty($models)) {
			$models['openrouter/auto'] = 'openrouter/auto';
		} else {
			asort($models);
		}
		if (empty($images)) {
			$images['openai/gpt-5-image'] = 'OpenAI: GPT-5 Image';
		} else {
			asort($images);
		}
		return array('models' => $models, 'img_models' => $images,  'tokens' => $tokens);
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
			$params['model'] = WaicUtils::getArrayValue($options, 'openrouter_model', $defaults['openrouter_model']);
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
		/*$newModels = array('gpt-5', 'gpt-5-mini', 'gpt-5-nano');
		if (in_array($params['model'], $newModels)) {
			$params['max_completion_tokens'] = $params['max_tokens'];
			unset($params['max_tokens'], $params['temperature'], $params['presence_penalty'], $params['frequency_penalty']);
		}*/
		
		$url = $this->getApiChatCompletionsUrl();
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
			$params['model'] = WaicUtils::getArrayValue($options, 'openrouter_img_model', $defaults['openrouter_img_model']);
		}
		$params['modalities'] = array('text', 'image');
		/*if ('dall-e-3-hd' == $params['model']) {
			$params['model'] = 'dall-e-3';
			$params['quality'] = 'hd';
		}*/
		/*if (empty($params['size']) || ( 'dall-e-3' == $params['model'] && ( '512x512' == $params['size'] || '256x256' == $params['size'] ) ) ) {
			$params['size'] = '1024x1024';
		}*/
		if (isset($params['gemini_size'])) {
			$params['image_config'] = array('aspect_ratio' => $params['gemini_size']);
			unset($params['gemini_size']);
		}
		//$params['size'] = $this->getImageSize(WaicUtils::getArrayValue($params, 'size'), $params['model']);
		

		if (empty($params['n'])) {
			$params['n'] = 1;
		}

		$url = $this->getApiImageUrl();  
		return $this->sendRequest($url, 'POST', $params, 'image');
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
		//WaicFrame::_()->saveDebugLogging(array('Result from API' => $data));
		$results = array('error' => 1, 'his_id' => 0, 'tokens' => 0, 'length' => 0, 'data' => '');
		$data = json_decode( $data );
		WaicFrame::_()->saveDebugLogging(array('Result from API2' => $data));
		if (isset($data->usage) && isset($data->usage->total_tokens)) {
			$results['tokens'] = $data->usage->total_tokens;
		}
		if (isset($data->error) && isset($data->error->message)) {
			$results['msg'] = trim($data->error->message);
			if (empty($results['msg']) && isset($data->error->code) && 'invalid_api_key' == $data->error->code) {
				$results['msg'] = esc_html__('Incorrect API key provided', 'ai-copilot-content-generator');
			}
			return array('results' => $results, 'params' => $params);
		} else if (isset($data->choices) && is_array($data->choices)) {
			$results['error'] = 0;
			$results['tokens'] = $data->usage->total_tokens;
			if ('image' === $type) {
				if (isset($data->choices[0]->images) && isset($data->choices[0]->images[0]->image_url->url)) {
					$imgUrl = $data->choices[0]->images[0]->image_url->url;
					$parts = explode(',', $imgUrl);
					if (count($parts) > 1) {
						$imgUrl = $parts[1];
					}
					
					$imageData = base64_decode($imgUrl, true);
					if (false === $imageData) {
						$results['error'] = 1;
						$results['msg'] = esc_html__('Image error', 'ai-copilot-content-generator');
					} else {
						$filename = uniqid('openrouter_image_') . '.png';
						$filePath = get_temp_dir() . $filename;
						file_put_contents($filePath, $imageData);
						$results['error'] = 0;
						$results['data'] = $filePath;
					}
				}
			} else if (!empty($data->choices[0]->message->tool_calls)) {
				$results['tools'] = $data->choices[0]->message->tool_calls;
				$results['data'] = 'tool_calls';
			} else if (isset($data->choices[0]->message->content)) {
				$results['data'] = trim($data->choices[0]->message->content);
			} else if (isset($data->choices[0]->text)) {
				$results['data'] = trim($data->choices[0]->text);
			}
			if (empty($results['data'])) {
				$results['error'] = 1;
				$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences (empty data).', 'ai-copilot-content-generator');
			} else {
				$results['length'] = WaicUtils::getCountWords( $results['data'] );
			}
		}
		if (empty($results['data'])) {
			if ('image' == $type && isset($data->images) && is_array($data->images)) {
				if (isset($data->images[0]->image_url->url)) {
					$imgUrl = $data->images[0]->image_url->url;
					$parts = explode(',', $imgUrl);
					if (count($parts) > 1) {
						$imgUrl = $parts[1];
					}
					$imageData = base64_decode($imgUrl, true);
					if (false === $imageData) {
						$results['error'] = 1;
						$results['msg'] = esc_html__('Image error', 'ai-copilot-content-generator');
					} else {
						$filename = uniqid('openrouter_image_') . '.png';
						$filePath = get_temp_dir() . $filename;
						file_put_contents($filePath, $imageData);
						$results['error'] = 0;
						$results['data'] = $filePath;
					}
				}
			} else {
				$results['error'] = 1;
				$results['msg'] = esc_html__('The model predicted a completion that begins with a stop sequence, resulting in no output. Consider adjusting your prompt or stop sequences.', 'ai-copilot-content-generator');
			}
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
	public function getToolsAnswer( $answer, $tool ) {
		return array(
			'role' => 'tool',
			'tool_call_id' => empty($tool->id) ? 'call_1' : $tool->id,
			'content' => array(array('type' => 'text', 'text' => json_encode($answer))),
		);
	}
}
