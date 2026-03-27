<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicIntegration extends WaicBaseObject {
	protected $_code = '';
	protected $_category = '';
	protected $_logo = '';
	protected $_type = '';
	protected $_name = '';
	protected $_desc = '';
	protected $_order = '';
	protected $_settings = array();
	protected $_integration = false;
	protected $_params = null;
	protected $_encrypted = null;
	protected $_decrypted = null;
	protected $_cipher = 'AES-128-CBC';
	protected $_key = null;
	protected $_iv = null;
	protected $_privatParams = array();
	protected $_signalParams = array();
	private $_keyiv = 'a23y56f89i12nms6';
	private $_authProxy = 'https://bizcity.vn/';
	private $_authProxySecret = 'a9c9022402df8368899a7ebeeed93e08cc63837c33b8efe745c47fe11db4e113';
	
	public function getCode() {
		return $this->_code;
	}
	public function getLogo() {
		return $this->_logo;
	}
	public function getCategory() {
		return $this->_category;
	}
	public function getType() {
		return $this->_type;
	}
	public function getName() {
		return $this->_name;
	}
	public function getDesc() {
		return $this->_desc;
	}
	public function getSettings() {
		return $this->_settings;
	}
	public function getOrder() {
		return $this->_order;
	}
	public function getEncriptKey() {
		if (is_null($this->_key)) {
			$this->_key = substr(hash('sha256', $this->_code . $this->_keyiv, true), 0, 16);
		}
		return $this->_key;
	}
	public function getEncriptIv() {
		if (is_null($this->_iv)) {
			$this->_iv = substr(hash('sha256', $this->_keyiv . $this->_code, true), 0, 16);
		}
		return $this->_iv;
	}
	
	public function setIntegration( $integration ) {
		$this->_integration = $integration;
		$this->_params = $this->_integration && is_array($this->_integration) ? $this->_integration : array();
		$this->_encrypted = null;
		$this->_decrypted = null;
	}
	public function getParams() {
		if (is_null($this->_params)) {
			$this->_params = $this->_integration && is_array($this->_integration) ? $this->_integration : array();
		}
		return $this->_params;
	}
	public function encryptBase64( $value ) {
		return base64_encode(openssl_encrypt($value, $this->_cipher, $this->getEncriptKey(), 0, $this->getEncriptIv()));
	}
	public function decryptBase64( $value ) {
		return openssl_decrypt(base64_decode($value), $this->_cipher, $this->getEncriptKey(), 0, $this->getEncriptIv());
	}
	public function getEncryptedParams() {
		if (is_null($this->_encrypted)) {
			$params = $this->getParams();
			if (empty($params['_encrypt'])) {
				$settings = $this->getSettings();
				foreach ($params as $key => $value) {
					$setting = WaicUtils::getArrayValue('params', array(), 2);
					if (in_array($key, $this->_privatParams) || (isset($settings[$key]) && !empty($settings[$key]['encrypt']))) {
						$params[$key] = $this->encryptBase64($value);
					}
				}
			} else {
				$this->_encrypted = $params;
			}
			$params['_encrypt'] = 1;
			$this->_encrypted = $params;
		}
		return $this->_encrypted;
	}
	public function getDecryptedParams( $private = true ) {
		if (is_null($this->_decrypted)) {
			$params = $this->getParams();
			if (empty($params['_encrypt'])) {
				$this->_decrypted = $params;
			} else {
				$settings = $this->getSettings();
				foreach ($params as $key => $value) {
					$setting = WaicUtils::getArrayValue('params', array(), 2);
					if (in_array($key, $this->_privatParams) || (isset($settings[$key]) && !empty($settings[$key]['encrypt']))) {
						$params[$key] = $this->decryptBase64($value);
					}
				}
			}
			$params['_encrypt'] = 0;
			$this->_decrypted = $params;
		}
		if (!$private && !empty($this->_privatParams)) {
			$result = $this->_decrypted;
			foreach ($this->_privatParams as $key) {
				unset($result[$key]);
			}
			return $result;
		}
		return $this->_decrypted;
	}
	public function getParam( $key, $def = '', $typ = 0, $zero = false, $leer = false ) {
		return WaicUtils::getArrayValue($this->getParams(), $key, $def, $typ, false, $zero, $leer); 
	}
	public function getEncryptedParam( $key, $def = '', $typ = 0, $zero = false, $leer = false ) {
		return WaicUtils::getArrayValue($this->getEncryptedParams(), $key, $def, $typ, false, $zero, $leer); 
	}
	public function getDecryptedParam( $key, $def = '', $typ = 0, $zero = false, $leer = false ) {
		return WaicUtils::getArrayValue($this->getDecryptedParams(), $key, $def, $typ, false, $zero, $leer); 
	}
	
	// add only decrypted values
	public function addParam( $key, $value ) {
		$params = $this->getParams();
		if (!empty($params['_encrypt'])) {
			if (in_array($key, $this->_privatParams)) {
				$value = $this->encryptBase64($value);
			}
		}
		$this->_params[$key] = $value;
				
		$this->_encrypted = null;
		$this->_decrypted = null;
	}
	public function doTest( $need = false ) {
		$params = $this->getParams();
		if ($need || !isset($params['_status'])) {
			$this->addParam('_status', 1);
		}
	}
	public function getAuthProxyUrl() {
		return $this->_authProxy . 'wp-json/aops/v1/oauth/init';
	}
	public function getAuthProxyRefreshUrl() {
		return $this->_authProxy . 'wp-json/aops/v1/oauth/refresh';
	}
	public function getAuthProxySecret( $body ) {
		return base64_encode(hash_hmac('sha256', $body, $this->_authProxySecret, true));
	}
	
	public function unpackTokenPackage( $jwt ) {
		$secret = $this->_authProxySecret;
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return 'Invalid format token_package';
		}
		//$header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
		$signature = base64_decode(strtr($parts[2], '-_', '+/'));

		$expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
		if (!hash_equals($expected, $signature)) {
			return 'The signature is incorrect';
		}
		$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
		if (!is_array($payload)) {
			return 'Incorrect body JWT';
		}
		/*if (isset($payload['iat'], $payload['ttl']) && time() > $payload['iat'] + $payload['ttl']) {
			return 'JWT expired';
		}*/
		return $payload;
	}
	public function addPrivateParams( $old ) {
		if (empty($this->_signalParams) || empty($this->_privatParams) || empty($old) || !is_array($old)) {
			return;
		}
		$changed = false;
		foreach ($this->_signalParams as $key) {
			$value = WaicUtils::getArrayValue($old, $key);
			if ($this->getParam($key) != $value) {
				$changed = true;
				break;
			}
		}
		if (!$changed) {
			$isEnc = !empty($old['_encrypt']);
			foreach ($this->_privatParams as $key) {
				$value = WaicUtils::getArrayValue($old, $key);
				if ($isEnc) {
					$value = $this->decryptBase64($value);
				}
				$this->addParam($key, $value);
			}
		}
	}
}
