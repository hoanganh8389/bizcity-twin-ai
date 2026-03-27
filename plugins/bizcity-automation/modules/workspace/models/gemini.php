<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicGeminiModel extends WaicModel implements WaicAIProviderInterface {

	public $engine = 'gemini';
	private $apiKey;
	private $apiUrl = 'https://generativelanguage.googleapis.com/v1beta';
	//private $apiUrl = 'https://api.proxyapi.ru/google/v1beta';
	private $model;
	private $imageModel;
	private $apiOptions = null;
	private $sleep = 20;
	private $lastTime = 0;
	private $headers;
	public $response;

	private $geminiParams = array();

	private function getApiChatCompletionsUrl() {
		return $this->apiUrl . '/models/' . $this->model . ':generateContent?key=' . $this->apiKey;
	}

	private function getApiImageUrl() {
		$url = $this->apiUrl . '/models/' . $this->imageModel;
		
		if (strpos($this->imageModel, 'imagen') === 0) {
			$url .= ':predict';
		} else {
			$url .= ':generateContent';
		}
		return $url . '?key=' . $this->apiKey;
		/*switch ($this->imageModel) {
			case 'gemini-2.0-flash-preview-image-generation':
				return $this->apiUrl . '/models/gemini-2.0-flash-preview-image-generation:generateContent?key=' . $this->apiKey;
			default:
				return $this->apiUrl . '/models/' . $this->imageModel . ':predict?key=' . $this->apiKey;
		}*/
	}

	public function init() {
		$this->apiKey = WaicFrame::_()->getModule('options')->get('api', 'gemini_api_key');

		return $this;
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
		if ($this->apiKey != $real['gemini_api_key']) {
			$this->apiKey = $real['gemini_api_key'];
		}
		if (empty($this->apiKey)) {
			WaicFrame::_()->pushError(esc_html__('Gemini API key not found', 'ai-copilot-content-generator'));
			return false;
		}

		$this->model = $this->apiOptions['gemini_model'];
		$this->imageModel = $this->apiOptions['gemini_img_model'];

		$perMinute = (int) $real['pre_minute'];
		if (1 > $perMinute) {
			$perMinute = 1;
		}
		$this->sleep = round(60 / $perMinute);

		$this->headers = array(
			//'Authorization' => 'Bearer ' . $this->apiKey,
			'Content-Type' => ( empty($options['contentType']) ? 'application/json' : $options['contentType'] ),
		);

		return true;
	}

	public function getEngine() {
		return $this->engine;
	}

	public function getText( $params, $stream = null ) {
		WaicFrame::_()->saveDebugLogging(array('Model' => array('model' => $this->model)));
		$options = $this->apiOptions;
		$defaults = WaicFrame::_()->getModule('options')->getModel()->getDefaults('api');
		if (empty($params['prompt']) && empty($params['messages'])) {
			WaicFrame::_()->pushError(esc_html__('Error: prompt is empty.', 'ai-copilot-content-generator'));
			return false;
		}

		if (empty($params['messages'])) {
			$params['messages'] = array(
				array(
					'role' => 'system',
					'content' => $params['prompt'],
				),
			);
			unset($params['prompt']);
		}

		$this->geminiParams = array(
			'system_instruction' => array('parts' => array()),
			'contents' => array(),
			'generationConfig' => array(),
		);

		if (isset($params['messages'])) {
			foreach ($params['messages'] as $message) {
				if ('system' === $message['role']) {
					$this->geminiParams['system_instruction']['parts'][] = array('text' => $message['content']);
				} else if (isset($message['content'][0]['image_url'])) {
					$mime = preg_match('#^data:(image/[^;]+)#', $message['content'][0]['image_url']['url'], $matches) ? $matches[1] : '';
					$dataB64 = preg_replace('#^.+base64,#', '', $message['content'][0]['image_url']['url']);
					$this->geminiParams['contents'][] = array(
						'role' => 'user',
						'parts' => array(
							array(
								'inline_data' => array(
									'mime_type' => $mime,
									'data' => $dataB64,
								),
							),
						),
					);
				} else {
					$this->geminiParams['contents'][] = array(
						'role' => in_array($message['role'], array('user', 'function')) ? $message['role'] : 'model',
						'parts' => empty($message['parts']) ? array(array('text' => $message['content'])) : $message['parts'],
					);
				}
			}
		}
		if (empty($this->geminiParams['system_instruction']['parts'])) {
			unset($this->geminiParams['system_instruction']);
		}

		if (empty($this->geminiParams['contents'])) {
			$this->geminiParams['contents'][] = array(
				'role' => 'user',
				'parts' => array(
					array('text' => 'Generate a title based on the given instructions.'),
				),
			);
		}
		if (!empty($params['tools'])) {
			$fns = array();
			foreach ($params['tools'] as $tool) {
				if (isset($tool['type']) && $tool['type'] === 'function' && isset($tool['function'])) {
					$fns[] = $tool['function'];
				}
			}
			if (!empty($fns)) {
				$this->geminiParams['tools'] = array(array('functionDeclarations' => $fns));
			}
		}

		$this->geminiParams['generationConfig'] = array(
			'temperature' => isset($options['temperature']) ? $options['temperature'] : (float) WaicUtils::getArrayValue($options, 'temperature', $defaults['temperature'], 1),
			'maxOutputTokens' => isset($options['tokens']) ? $options['tokens'] : (int) WaicUtils::getArrayValue($options, 'tokens', $defaults['tokens'], 1),
			'topP' => isset($options['top_p']) ? $options['top_p'] : (int) WaicUtils::getArrayValue($options, 'top_p', $defaults['top_p'], 1),
			'topK' => isset($options['top_k']) ? $options['top_k'] : (int) WaicUtils::getArrayValue($options, 'top_k', $defaults['top_k'], 1),
		);

		return $this->sendRequest($this->getApiChatCompletionsUrl(), 'POST', $params);
	}

	private function sendRequest( $url, $method = 'POST', $params = array(), $type = '' ) {
		//WaicFrame::_()->saveDebugLogging(array('Send request' => $this->geminiParams));
		$fields = json_encode($this->geminiParams);

		$options = array(
			'timeout' => 200,
			'headers' => $this->headers,
			'method' => $method,
			'body' => $fields,
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

		$data = wp_remote_retrieve_body($response);

		WaicFrame::_()->saveDebugLogging(array('Result from API' => $data));
		$results = array('error' => 1, 'msg' => '', 'his_id' => 0, 'tokens' => 0, 'length' => 0, 'data' => '');
		$data = json_decode($data);

		if (isset($data->error->message)) {
			$results['msg'] = $data->error->message;
		}

		if ('image' === $type) {
			$imageData = false;
			if (isset($data->candidates[0]->content->parts[0]->inlineData->data)) {
				$imageData = base64_decode($data->candidates[0]->content->parts[0]->inlineData->data);
			} else if (isset($data->candidates[0]->content->parts[1]->inlineData->data)) {
				$imageData = base64_decode($data->candidates[0]->content->parts[1]->inlineData->data);
			} else if (isset($data->predictions[0]->bytesBase64Encoded)) {
				$imageData = base64_decode($data->predictions[0]->bytesBase64Encoded);
			}
			if ($imageData) {
				$filename = uniqid('gemini_image_') . '.png';
				$filePath = get_temp_dir() . $filename;
				file_put_contents($filePath, $imageData);

				$results['error'] = 0;
				$results['data'] = $filePath;
			} else if (empty($results['msg'])) {
				$results['msg'] = 'File not found';
			}
		} else if (!empty($data->candidates[0]->content->parts[0]->functionCall)) {
			$results = $this->addToolsRequest($results, $data->candidates[0]->content->parts[0]);
		} else if (!empty($data->candidates[0]->content->parts[1]->functionCall)) {
			$results = $this->addToolsRequest($results, $data->candidates[0]->content->parts[1]);
		} else if (isset($data->candidates[0]->content->parts[0]->text)) {
			$results['error'] = 0;
			$results['data'] = $data->candidates[0]->content->parts[0]->text;
		}

		if (isset($data->usageMetadata->totalTokenCount)) {
			$results['tokens'] = $data->usageMetadata->totalTokenCount;
		}

		return array('results' => $results, 'params' => $params);//array('model' => $this->model));
	}
	public function addToolsRequest( $results, $part ) {
		$fc = $part->functionCall;
		$obj = new stdClass();
		$obj->function = new stdClass();
		$obj->function->name = $fc->name;
		$obj->function->arguments = empty($fc->args) ? '' : json_encode($fc->args);
		
		$results['tools'] = array($obj);
		$results['tools_message'] = array('role' => 'model', 'parts' => array(array('functionCall' => $fc, 'thoughtSignature' => $part->thoughtSignature)));
		$results['data'] = 'tool_calls';
		$results['error'] = 0;
		return $results;
	}
	public function getToolsAnswer( $answer, $tool ) {
		$answer = empty($answer) ? array('result' => false) : array('result' => $answer);
		return array(
			'role' => 'function',
			'parts' => array(array('functionResponse' => array('name' => $tool->function->name, 'response' => $answer))),
		);
	}

	public function getImage( $params ) {
		WaicFrame::_()->saveDebugLogging(array('Image Model' => array('model' => $this->imageModel)));
		if (empty($params['prompt'])) {
			WaicFrame::_()->pushError(esc_html__('Error: prompt is empty.', 'ai-copilot-content-generator'));
			return false;
		}
		if (strpos($this->imageModel, 'imagen') === 0) {
			$this->geminiParams = array(
				'instances' => array(
					array('prompt' => $params['prompt']),
				),
				'parameters' => array(
					'numberOfImages' => 1,
					'sampleCount' => 1,
					'aspectRatio' => WaicUtils::getArrayValue($params, 'gemini_size', '1:1'),
				),
			);
		} else {
			$this->geminiParams = array(
				'contents' => array(
					array('parts' => array(array('text' => $params['prompt']))),
				),
				'generationConfig' => array(
					'responseModalities' => array('Text', 'Image'),
				),
			);
		}

		return $this->sendRequest($this->getApiImageUrl(), 'POST', $params, 'image');
	}
}
