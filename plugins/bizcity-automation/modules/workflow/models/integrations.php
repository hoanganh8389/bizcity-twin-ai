<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicIntegrationsModel extends WaicModel {
	private $_statuses = null;
	private $_integPath = null;
	private $_customPath = null;
	private $_categories = null;
	private $_integCodes = null;
	private $_integCats = null;
	private $_integClasses = array();
	private $_integObejcts = array();
	//private $_integrations = array();
	private $_integSaved = array();
	private $_integPreVar = WAIC_CODE . '_intergations_';
	
	public function __construct() {
		//$this->_setTbl('flowlogs');
	}
	
	public function getStatuses( $st = null ) {
		if (is_null($this->_statuses)) {
			$this->_statuses = array(
				0 => '',
				1 => __('Connected', 'ai-copilot-content-generator'),
				7 => __('Error', 'ai-copilot-content-generator'),
			);
		}
		return is_null($st) ? $this->_statuses : ( isset($this->_statuses[$st]) ? $this->_statuses[$st] : '' );
	}
	
	public function getPath() {
		if (is_null($this->_integPath)) {
			$this->_integPath = $this->getModule()->getModDir() . 'integrations' . WAIC_DS;
		}
		return $this->_integPath;
	}
	public function getCustomPath() {
		if (is_null($this->_customPath)) {
			$path = WaicFrame::_()->getModule('options')->get('plugin', 'integ_path');
			$this->_customPath = ( empty($path) || !is_dir(ABSPATH . $path) ? false : ABSPATH . $path );
		}
		return $this->_customPath;
	}
	public function getCategories() {
		if (is_null($this->_categories)) {
			$this->_categories = array(
				'email' => __('Email Providers', 'ai-copilot-content-generator'),
				'calendar' => __('Calendar & Meetings', 'ai-copilot-content-generator'),
				'social' => __('Social Media', 'ai-copilot-content-generator'),
				'messenger' => __('Messengers', 'ai-copilot-content-generator'),
				'document' => __('Documents', 'ai-copilot-content-generator'),
				'files' => __('Files', 'ai-copilot-content-generator'),
				'crm' => __('CRM', 'ai-copilot-content-generator'),
				'db' => __('Databases', 'ai-copilot-content-generator'),
			);
			$this->_categories = WaicDispatcher::applyFilters('getIntegCategories', $this->_categories);
		}
		return $this->_categories;
	}
	public function getIntegClass( $code ) {
		if (!isset($this->_integClasses[$code])) {
			$name = waicStrFirstUp(WAIC_CODE) . 'Integration_' . $code;
			$file = $code . '.php';
			$custom = $this->getCustomPath();
			$found = false;
			if ($custom) {
				$found = waicImportClass($name, $custom . $file);
			}
			if (!$found) {
				$found = waicImportClass($name, $this->getPath() . $file);
			}
			$this->_integClasses[$code] = $name;
		}
		return $this->_integClasses[$code];
	}
	public function getLeerIntegration( $code ) {
		if (empty($code)) {
			return false;
		}
		if (!isset($this->_integObejcts[$code])) {
			$integClass = $this->getIntegClass($code);
			$this->_integObejcts[$code] = class_exists($integClass) ? new $integClass() : false;
		}
		return $this->_integObejcts[$code];
	}
	public function getIntegration( $code, $account ) {
		if (!is_array($account)) {
			$num = (int) $account;
			$account = $this->getSavedIntegrations($code, $num);
			if (!$account) {
				return false;
			}
		}
		$integClass = $this->getIntegClass($code);
		return class_exists($integClass) ? new $integClass($account) : false;
	}
	public function saveIntegrations( $code, $accounts ) {
		$oldAccounts = get_option($this->_integPreVar . $code);
		$existOld = $oldAccounts && is_array($oldAccounts);
		$forSave = array();
		$forReturn = array();
		if (is_array($accounts)) {
			foreach ($accounts as $account) {
				$integration = $this->getIntegration($code, $account);
				if ($integration) {
					$uniqId = $integration->getParam('uniq_id');
					if (!empty($uniqId) && $existOld) {
						$oAccount = false;
						foreach ($oldAccounts as $old) {
							if (!empty($old['uniq_id']) && $old['uniq_id'] == $uniqId) {
								$oAccount = $old;
							}
						}
						if ($oAccount) {
							$integration->addPrivateParams($oAccount);
						}
					}
					$integration->doTest();
					$forSave[] = $integration->getEncryptedParams();
					$forReturn[] = $integration->getDecryptedParams(false);
					/*if ($params) {
						$forSave[] = $params;
					}*/
				}
			}
			//$accounts = $forSave;
		}
		update_option($this->_integPreVar . $code, empty($forSave) ? '' : $forSave);
		return $forReturn;
	}
	public function getSavedIntegrations( $code, $num = false ) {
		if (!isset($this->_integSaved[$code])) {
			$this->_integSaved[$code] = get_option($this->_integPreVar . $code);
		} 
		return false === $num ? $this->_integSaved[$code] : ( isset($this->_integSaved[$code][$num]) ? $this->_integSaved[$code][$num] : false );
	}
	public function getAllSavedIntegrations( $decrypted = true, $private = true ) {
		$codes = $this->getIntegCodes(false);
		foreach ($codes as $code) {
			if (!isset($this->_integSaved[$code])) {
				$accounts = get_option($this->_integPreVar . $code);
				if ($decrypted && !empty($accounts)) {
					foreach ($accounts as $n => $account) {
						$integration = $this->getIntegration($code, $account);
						if ($integration) {
							$accounts[$n] = $integration->getDecryptedParams($private);
						}
					}
				}
				$this->_integSaved[$code] = $accounts;
			}
		}
		return $this->_integSaved;
	}
	private function loadAllIntegrations() {
		$pathes = array($this->getCustomPath(), $this->getPath());
		$integs = array();
		$cats = array();
		$categories = $this->getCategories();
		
		foreach ($pathes as $path) {
			if (empty($path)) {
				continue;
			}
			$files = scandir($path);
			foreach ($files as $file) {
				if ($file === '.' || $file === '..') {
					continue;
				}
				if (is_file($path . WAIC_DS . $file)) {
					$code = str_replace('.php', '', $file);
					if (empty($integs[$code])) {
						$integration = $this->getLeerIntegration($code);
						if ($integration) {
							$cat = $integration->getCategory();
							if (isset($categories[$cat])) {
								$cats[$cat] = $categories[$cat];
								$integs[$code] = array(
									'code' => $code,
									'category' => $cat,
									'logo' => $integration->getLogo(),
									'name' => $integration->getName(),
									'desc' => $integration->getDesc(),
									'settings' => $integration->getSettings(),
									'order' => $integration->getOrder(),
								);
							}
						}
					}
				}
			}
		}
		uasort($integs, function($a, $b) {
			return $a['order'] <=> $b['order'];
		});
		$this->_integCodes = $integs;
		$this->_integCats = $cats;
	}
	
	public function getIntegCodes( $full = true ) {
		if (is_null($this->_integCodes)) {
			$this->loadAllIntegrations();
		}
		return $full ? $this->_integCodes : array_keys($this->_integCodes);
	}
	public function getIntegCategories() {
		if (is_null($this->_integCats)) {
			$this->loadAllIntegrations();
		}
		return $this->_integCats;
	}
	public function getIntegList( $cat ) {
		if (is_null($this->_integCodes)) {
			$this->loadAllIntegrations();
		}
		$list = array();
		foreach ($this->_integCodes as $code => $data) {
			if ($cat == $data['category']) {
				$list[$code] = $data['name'];
			}
		} 
		return $list;
	}
	public function isAccountConnected( $account ) {
		return $account && isset($account['_status']) && 1 === ( (int) $account['_status'] );
	}
		
	public function getIntegAccountsList( $cat, $key = '' ) {
		if (is_null($this->_integCodes)) {
			$this->loadAllIntegrations();
		}
		if (!empty($key)) {
			$keys = is_array($key) ? $key : array($key);
		}
		$list = array();
		foreach ($this->_integCodes as $code => $data) {
			if ($cat == $data['category']) {
				if (!empty($key) && !in_array($code, $keys)) {
					continue;
				} 
				$accounts = $this->getSavedIntegrations($code);
				if ($accounts && is_array($accounts)) {
					foreach ($accounts as $num => $account) {
						if ($this->isAccountConnected($account)) {
							$list[$code . '-' . $num] = $data['name'] . ' - ' . ( empty($account['name']) ? ( $num + 1 ) : $account['name'] );
						}
					}
				}
			}
		} 
		return $list;
	}
}