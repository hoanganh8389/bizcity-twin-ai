<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicBizcityModel extends WaicModel implements WaicAIProviderInterface {
    public $engine = 'bizcity';
    private $apiKey;
    private $sleep = 20;
    private $lastTime = 0;

    private $headers;
    private $timeout = 200;
    public $response;

    private $streamMethod = null;
    private $apiOptions = null;

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
        // BizCity default key (no need user to fill plugin settings)
        $this->apiKey = (string) get_option('twf_openai_api_key');
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
        return in_array($model, $legacyModels, true);
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

        // Merge overrides (model/tokens/etc). NOTE: DO NOT require api_key from options.
        foreach ($options as $key => $value) {
            if (!empty($value)) {
                $real[$key] = $value;
            }
        }

        $this->apiOptions = $real;

        // Priority:
        // 1) explicit api_key passed from node (e.g. video node)
        // 2) WP option twf_openai_api_key (BizCity default)
        $overrideKey = !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $this->apiKey = $overrideKey !== '' ? $overrideKey : (string) get_option('twf_openai_api_key');

        if (empty($this->apiKey)) {
            WaicFrame::_()->pushError(esc_html__('Missing API key (twf_openai_api_key).', 'ai-copilot-content-generator'));
            return false;
        }

        $perMinute = (int) ($real['pre_minute'] ?? 1);
        if (1 > $perMinute) $perMinute = 1;
        $this->sleep = (int) round(60 / $perMinute);

        $this->headers = array(
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => ( empty($options['contentType']) ? 'application/json' : $options['contentType'] ),
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
        $tokens   = WaicFrame::_()->getModule('options')->getModel()->getVariations('api', 'tokens');

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
            if (empty($params['messages'])) {
                $params['messages'] = array(
                    array('role' => 'user', 'content' => $params['prompt']),
                );
                unset($params['prompt']);
            }
            $url = $this->getApiChatCompletionsUrl();
        }

        $newModels = array('gpt-5', 'gpt-5-mini', 'gpt-5-nano');
        if (in_array($params['model'], $newModels, true)) {
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

        $params['size'] = $this->getImageSize(WaicUtils::getArrayValue($params, 'size'), $params['model']);
        if (empty($params['n'])) $params['n'] = 1;

        $url = $this->getApiImageUrl();
        return $this->sendRequest($url, 'POST', $params, 'image');
    }

    private function sendRequest( $url, $method, $params = array(), $type = '' ) {
        if (isset($params['gemini_size'])) unset($params['gemini_size']);

        $stream = (array_key_exists('stream', $params) && $params['stream']) ? true : false;

        $options = array(
            'timeout' => $this->timeout,
            'headers' => $this->headers,
            'method'  => $method,
            'stream'  => $stream,
        );

        if ('POST' === $method) {
            $fields = empty($params['body']) ? json_encode($params) : $params['body'];
            $options['body'] = $fields;
        }

        // Redact Authorization in logs
        $logOptions = $options;
        if (!empty($logOptions['headers']['Authorization'])) {
            $logOptions['headers']['Authorization'] = 'Bearer ***';
        }
        WaicFrame::_()->saveDebugLogging(array('endpoint' => $url, 'Send request' => $logOptions));

        $pause = time() - $this->lastTime;
        if ($pause < $this->sleep) {
            sleep($this->sleep - $pause);
        }

        $response = wp_remote_request($url, $options);
        if (is_wp_error($response)) {
            WaicFrame::_()->pushError($response->get_error_message());
            return false;
        }

        $data = $stream ? $this->response : wp_remote_retrieve_body($response);
        $this->lastTime = time();

        WaicFrame::_()->saveDebugLogging(array('Result from API' => $data));

        $results = array('error' => 1, 'his_id' => 0, 'tokens' => 0, 'length' => 0, 'data' => '');
        $decoded = json_decode($data);

        if (isset($decoded->usage) && isset($decoded->usage->total_tokens)) {
            $results['tokens'] = $decoded->usage->total_tokens;
        }

        if (isset($decoded->error) && isset($decoded->error->message)) {
            $results['msg'] = trim($decoded->error->message);
        } else if (isset($decoded->choices) && is_array($decoded->choices)) {
            $results['error'] = 0;

            if (!empty($decoded->choices[0]->message->tool_calls)) {
                $results['tools'] = $decoded->choices[0]->message->tool_calls;
                $results['data'] = 'tool_calls';
            } else if (isset($decoded->choices[0]->message->content)) {
                $results['data'] = trim($decoded->choices[0]->message->content);
            } else if (isset($decoded->choices[0]->text)) {
                $results['data'] = trim($decoded->choices[0]->text);
            }

            if (empty($results['data'])) {
                $results['error'] = 1;
                $results['msg'] = esc_html__('Empty data.', 'ai-copilot-content-generator');
            } else {
                $results['length'] = WaicUtils::getCountWords($results['data']);
            }
        } else if ('image' === $type && isset($decoded->data) && is_array($decoded->data)) {
            $results['error'] = 0;
            $results['data'] = sanitize_url($decoded->data[0]->url);
        } else {
            $results['msg'] = esc_html__('Unexpected response.', 'ai-copilot-content-generator');
        }

        return array('results' => $results, 'params' => $params);
    }

    public function getImageSize( $orient = '', $model = '' ) {
        $def = '1024x1024';
        if ('dall-e-2' == $model) return $def;

        $sizes = array(
            'horizontal' => '1792x1024',
            'vertical'   => '1024x1792',
            'square'     => '1024x1024',
        );

        return empty($orient) ? '1024x1024' : ( isset($sizes[$orient]) ? $sizes[$orient] : $orient );
    }
}