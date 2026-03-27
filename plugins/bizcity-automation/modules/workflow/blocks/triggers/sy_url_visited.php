<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_sy_url_visited extends WaicTrigger {
	protected $_code = 'sy_url_visited';
	protected $_subtype = 4;
	protected $_order = 4;
	
	public function __construct( $block = false ) {
		parent::__construct();
		$this->_name = __('A URL is visited', 'ai-copilot-content-generator');
		$this->_desc = __('The trigger is activated when the page is visited.', 'ai-copilot-content-generator');
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
				'type' => 'input',
				'label' => __('URL', 'ai-copilot-content-generator'),
				'tooltip' => __('You can use simple patterns to match URLs and query parameters.', 'ai-copilot-content-generator') .
					'</br><b>' . __('The following symbols are supported', 'ai-copilot-content-generator') . '</b>:</br>' .
					'* — ' . __('matches any number of any characters', 'ai-copilot-content-generator') . '</br>' .
					'| — ' . __('means logical OR between alternatives', 'ai-copilot-content-generator') . '</br>' .
					'@ — ' . __('matches a number (one or more digits)', 'ai-copilot-content-generator') . '</br>' .
					'</br><b>' . __('Examples', 'ai-copilot-content-generator') . '</b>:</br>' .
					'/promo/* — ' . __('matches any URL starting with', 'ai-copilot-content-generator') . ' /promo/ (e.g. /promo/spring-sale)</br>' .
					'*utm_campaign=(spring|summer)_sale — ' . __('matches either spring_sale or summer_sale', 'ai-copilot-content-generator') . '</br>' .
					'ref=@&type=(a|b|c) — ' . __('matches combined parameters like', 'ai-copilot-content-generator') . ' ref=99&type=b</br>' .
					'<b>' . __('Note:', 'ai-copilot-content-generator') . '</b> Parentheses () are allowed only for grouping with |',
				'default' => '',
			),
			'params' => array(
				'type' => 'textarea',
				'label' => __('Query parameters', 'ai-copilot-content-generator'),
				'tooltip' => __('Enter paars Key & Value of each parameter on a new line. Separate the Key and Value with the equals symbol. You can use simple patterns to match URLs and query parameters (see the tooltip for the Url field).', 'ai-copilot-content-generator') . '</br></br>' . __('Example:', 'ai-copilot-content-generator') . ':</br>ref=partner1</br>utm_source=(google|facebook)</br>utm_campaign=spring_sale</br>*page*=@',
				'default' => '',
			),
		);
	}
	public function convertStringRegex( $str, $add = '', $slash = false ) {
		$special = array('|', '(', ')');
		$regex = preg_replace_callback('/\*|@|\(|\)|\||./', function( $m ) use ($special) {
			$token = $m[0];
			if ($token === '*') {
				return '.*';
			} else if ($token === '@') {
				return '\d+';
			} else if (in_array($token, $special)) {
				return $token;
			}
			return preg_quote($token, '#');
		}, $str);
		return empty($regex) ? '' : '#^' . ( $slash ? str_replace('\\', '\\\\', $regex) : $regex ) . $add . '$#';
	}
	public function getHook() {
		$url = trim($this->getParam('url'));
		$params = $this->getParam('params');
		if (!empty($url)) {
			if (preg_match('#^https?://[^/]+#i', $url, $match)) {
				$url = substr($url, strlen($match[0]));
			}
			//$url = rtrim($url, " \t\n\r\0\x0B/");
			$url = rtrim($url, " \t\n\r\0\x0B");
			if (!str_starts_with($url, '/')) {
				$url = '/' . $url;
			}
			$url = $this->convertStringRegex($url, (empty($params) ? '' : '.*'), true);
		}
		return $url;
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
			array(
				'query_url' => __('Query Url', 'ai-copilot-content-generator'),
				'query_param' => __('Query Parameter *', 'ai-copilot-content-generator'),
			),
		);
		return $this->_variables;
	}
	public function controlRun( $args = array() ) {
		$url = $args;
		$params = $this->getParam('params');
		$query = parse_url($url, PHP_URL_QUERY);
		parse_str($query, $queryParams);

		if (!empty($params)) {
			if (empty($queryParams)) {
				return false;
			}
			
			$list = preg_split('/\r\n|\r|\n/', $params);
			foreach ($list as $l) {
				if (empty($l)) {
					continue;
				}
				$parts = explode('=', $l);
				if (count($parts) > 0) {
					$key = trim($parts[0]);
					$value = empty($parts[1]) ? '' : trim($parts[1]);
					$keyRegex = is_string($key) && !empty($key) ? $this->convertStringRegex($key) : false;
					$valueRegex = is_string($value) && !empty($value) ? $this->convertStringRegex($value) : false;
					$matched = false;
					foreach ($queryParams as $paramKey => $paramValue) {
						$keyMatch = $keyRegex ? preg_match($keyRegex, $paramKey) : true;
						$valueMatch = $valueRegex ? preg_match($valueRegex, $paramValue) : true;
						
						if ($keyMatch && $valueMatch) {
							$matched = true;
							break;
						}
					}

					if (!$matched) {
						return false;
					}
				}
			}
		}
		
		$result = array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'query_url' => $url);
		$fields = WaicUtils::flattenJson($queryParams);
		
		$result = $this->getFieldsArray($fields, 'query_param', $result);
		return $result;
	}
}
