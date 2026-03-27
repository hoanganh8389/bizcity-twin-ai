<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicFrame extends WaicBaseObject {
	private $_modules = array();
	private $_tables = array();
	private $_allModules = array();
	/**
	 * Uses to know if we are on one of the plugin pages
	 */
	private $_inPlugin = false;
	/**
	 * Array to hold all scripts and add them in one time in addScripts method
	 */
	private $_scripts = array();
	private $_scriptsInitialized = false;
	private $_styles = array();
	private $_stylesInitialized = false;
	private $_useFootAssets = false;

	private $_scriptsVars = array();
	private $_mod = '';
	private $_action = '';
	/**
	 * Object with result of executing non-ajax module request
	 */
	private $_res = null;

	public function __construct() {
		$this->_res = waicToeCreateObj('response', array());
	}
	public static function getInstance() {
		static $instance;
		if (!$instance) {
			$instance = new WaicFrame();
		}
		return $instance;
	}
	public static function _() {
		return self::getInstance();
	}
	public function parseRoute() {
		// Check plugin
		$pl = WaicReq::getVar('pl');
		if (WAIC_CODE == $pl) {
			$mod = WaicReq::getMode();
			if ($mod) {
				$this->_mod = $mod;
			}
			$action = WaicReq::getVar('action');
			if ($action) {
				$this->_action = $action;
			}
		}
	}
	public function setMod( $mod ) {
		$this->_mod = $mod;
	}
	public function getMod() {
		return $this->_mod;
	}
	public function setAction( $action ) {
		$this->_action = $action;
	}
	public function getAction() {
		return $this->_action;
	}
	/**
	 * BizCity: Allowlist modules to load.
	 * Workflow cần tối thiểu: adminmenu + options + workspace + workflow
	 */
	protected function _getBizcityAllowedModules() {
		$allow = array('adminmenu', 'options', 'workspace', 'mcp', 'workflow');
		/**
		 * Dev override (MU-plugin/theme có thể chỉnh):
		 * add_filter('bizcity_waic_allowed_modules', fn($a)=>['adminmenu','options','workspace','workflow']);
		 */
		if (function_exists('apply_filters')) {
			$allow = apply_filters('bizcity_waic_allowed_modules', $allow);
		}
		return is_array($allow) ? array_values(array_unique($allow)) : array('adminmenu', 'options', 'workspace','mcp', 'workflow');
	}

	protected function _extractModules() {
		$allowed = $this->_getBizcityAllowedModules();

		$activeModules = $this->getTable('modules')->get($this->getTable('modules')->alias() . '.*');
		if ($activeModules) {
			foreach ($activeModules as $m) {
				$code = $m['code'];

				// ✅ BizCity: chỉ load module nằm trong allowlist
				if (!empty($allowed) && !in_array($code, $allowed, true)) {
					continue;
				}

				$moduleLocationDir = WAIC_MODULES_DIR;
				if (!empty($m['ex_plug_dir'])) {
					$moduleLocationDir = WaicUtils::getExtModDir( $m['ex_plug_dir'] );
				}
				if (is_dir($moduleLocationDir . $code)) {
					$this->_allModules[$m['code']] = 1;
					if ((bool) $m['active']) {
						waicImportClass(waicStrFirstUp(WAIC_CODE) . $code, $moduleLocationDir . $code . WAIC_DS . 'mod.php');
						$moduleClass = waicToeGetClassName($code);
						if (class_exists($moduleClass)) {
							$this->_modules[$code] = new $moduleClass($m);
							if (is_dir($moduleLocationDir . $code . WAIC_DS . 'tables')) {
								$this->_extractTables($moduleLocationDir . $code . WAIC_DS . 'tables' . WAIC_DS);
							}
						}
					}
				}
			}
		}

		// BizCity: nếu DB thiếu core modules (cài đặt cũ/DB migrate chưa chạy), vẫn load theo allowlist.
		foreach ((array) $allowed as $code) {
			if (isset($this->_modules[$code])) {
				continue;
			}
			$moduleLocationDir = WAIC_MODULES_DIR;
			if (!is_dir($moduleLocationDir . $code)) {
				continue;
			}

			waicImportClass(waicStrFirstUp(WAIC_CODE) . $code, $moduleLocationDir . $code . WAIC_DS . 'mod.php');
			$moduleClass = waicToeGetClassName($code);
			if (!class_exists($moduleClass)) {
				continue;
			}
			$this->_allModules[$code] = 1;
			$this->_modules[$code] = new $moduleClass(array(
				'code' => $code,
				'active' => 1,
				'type_id' => 1,
				'label' => waicStrFirstUp($code),
			));
			if (is_dir($moduleLocationDir . $code . WAIC_DS . 'tables')) {
				$this->_extractTables($moduleLocationDir . $code . WAIC_DS . 'tables' . WAIC_DS);
			}
		}
	}
	protected function _initModules() {
		if (!empty($this->_modules)) {
			foreach ($this->_modules as $mod) {
				 $mod->init();
			}
		}
	}
	public function init() {
		WaicReq::init();
		WaicCache::_()->init();
		
		$this->_extractTables();

		$this->_extractModules();

		$this->_initModules();

		WaicDispatcher::doAction('afterModulesInit');

		WaicModInstaller::checkActivationMessages();

		$this->_execModules();
		if ($this->isSuccessInit()) {
			WaicAssets::_()->init();
		}

		$addAssetsAction = $this->usePackAssets() && !is_admin() ? 'wp_footer' : 'init';

		add_action($addAssetsAction, array($this, 'addScripts'));
		add_action($addAssetsAction, array($this, 'addStyles'));
		//add_action('wp_enqueue_scripts', array($this, 'addStyles'));
		global $waicLangOK;
		register_activation_hook(WAIC_DIR . WAIC_DS . WAIC_MAIN_FILE, array('WaicUtils', 'activatePlugin')); //See classes/install.php file
		register_uninstall_hook(WAIC_DIR . WAIC_DS . WAIC_MAIN_FILE, array('WaicUtils', 'deletePlugin'));
		register_deactivation_hook(WAIC_DIR . WAIC_DS . WAIC_MAIN_FILE, array( 'WaicUtils', 'deactivatePlugin' ) );

		add_action('init', array($this, 'connectLang'));
		//WaicUtils::setTimeZone();
	}
	public function isSuccessInit() {
		return !empty($this->_modules) && $this->getModule('options') && $this->getModule('adminmenu');
	}
	public function connectLang() {
		global $waicLangOK;
		$waicLangOK = load_plugin_textdomain('ai-copilot-content-generator', false, WAIC_PLUG_NAME . '/languages/');
	}
	/**
	 * Check permissions for action in controller by $code and made corresponding action
	 *
	 * @param string $code Code of controller that need to be checked
	 * @param string $action Action that need to be checked
	 * @return bool true if ok, else - should exit from application
	 */
	public function checkPermissions( $code, $action ) {
		//return true;
		if ($this->havePermissions($code, $action)) {
			return true;
		} else {
			exit(esc_html_e('You have no permissions to view this page', 'ai-copilot-content-generator'));
		}
	}
	/**
	 * Check permissions for action in controller by $code
	 *
	 * @param string $code Code of controller that need to be checked
	 * @param string $action Action that need to be checked
	 * @return bool true if ok, else - false
	 */
	public function havePermissions( $code, $action ) {
		$res = true;
		$mod = $this->getModule($code);
		$action = strtolower($action);
		if ($mod) {
			$permissions = $mod->getController()->getPermissions();
			if (!empty($permissions)) {  // Special permissions
				$user = new WaicUser();
				if (isset($permissions[WAIC_METHODS]) && !empty($permissions[WAIC_METHODS])) {
					foreach ($permissions[WAIC_METHODS] as $method => $permissions) {   // Make case-insensitive
						$permissions[WAIC_METHODS][strtolower($method)] = $permissions;
					}
					if (array_key_exists($action, $permissions[WAIC_METHODS])) {        // Permission for this method exists
						$currentUserPosition = $user->getCurrentUserPosition();
						if ( ( is_array($permissions[ WAIC_METHODS ][ $action ] ) && !in_array($currentUserPosition, $permissions[ WAIC_METHODS ][ $action ]) )
							|| ( !is_array($permissions[ WAIC_METHODS ][ $action ]) && $permissions[WAIC_METHODS][$action] != $currentUserPosition )
						) {
							$res = false;
						}
					}
				}
				if (isset($permissions[WAIC_USERLEVELS]) && !empty($permissions[WAIC_USERLEVELS])) {
					$currentUserPosition = $user->getCurrentUserPosition();
					// For multi-sites network admin role is undefined, let's do this here
					if (is_multisite() && is_admin() && is_super_admin()) {
						$currentUserPosition = WAIC_ADMIN;
					}
					foreach ($permissions[WAIC_USERLEVELS] as $userlevel => $methods) {
						if (is_array($methods)) {
							$lowerMethods = array_map('strtolower', $methods);          // Make case-insensitive
							if (in_array($action, $lowerMethods)) {                      // Permission for this method exists
								if ($currentUserPosition != $userlevel) {
									$res = false;
								}
								break;
							}
						} else {
							$lowerMethod = strtolower($methods);            // Make case-insensitive
							if ($lowerMethod == $action) {                   // Permission for this method exists
								if ($currentUserPosition != $userlevel) {
									$res = false;
								}
								break;
							}
						}
					}
				}
			}
			if ($res) { // Additional check for nonces
				$noncedMethods = $mod->getController()->getNoncedMethods();
				if (!empty($noncedMethods)) {
					$noncedMethods = array_map('strtolower', $noncedMethods);
					if (in_array($action, $noncedMethods)) {
						check_ajax_referer('waic-nonce', 'waicNonce');
					}
				}
			}
		}
		return $res;
	}
	public function getRes() {
		return $this->_res;
	}
	public function execAfterWpInit() {
		$this->_doExec();
	}
	/**
	 * Check if method for module require some special permission. We can detect users permissions only after wp init action was done.
	 */
	protected function _execOnlyAfterWpInit() {
		$res = false;
		$mod = $this->getModule( $this->_mod );
		$action = strtolower( $this->_action );
		if ($mod) {
			$permissions = $mod->getController()->getPermissions();
			if (!empty($permissions)) {  // Special permissions
				if (isset($permissions[WAIC_METHODS]) && !empty($permissions[WAIC_METHODS])) {
					foreach ($permissions[WAIC_METHODS] as $method => $permissions) {   // Make case-insensitive
						$permissions[WAIC_METHODS][strtolower($method)] = $permissions;
					}
					if (array_key_exists($action, $permissions[WAIC_METHODS])) {        // Permission for this method exists
						$res = true;
					}
				}
				if (isset($permissions[WAIC_USERLEVELS]) && !empty($permissions[WAIC_USERLEVELS])) {
					$res = true;
				}
			}
			
			if (!$res) {
				$noncedMethods = $mod->getController()->getNoncedMethods();
				if (!empty($noncedMethods)) {
					$noncedMethods = array_map('strtolower', $noncedMethods);
					if (in_array($action, $noncedMethods)) {
						$res = true;
					}
				}
			}
		}
		return $res;
	}
	protected function _execModules() {
		if ($this->_mod) {
			// If module exist and is active
			$mod = $this->getModule($this->_mod);
			if ($mod && !empty($this->_action)) {
				if ($this->_execOnlyAfterWpInit()) {
					add_action('init', array($this, 'execAfterWpInit'));
				} else {
					$this->_doExec();
				}
			}
		}
	}
	protected function _doExec() {
		$mod = $this->getModule($this->_mod);
		if ($mod && $this->checkPermissions($this->_mod, $this->_action)) {
			switch (WaicReq::getVar('reqType')) {
				case 'ajax':
					add_action('wp_ajax_' . $this->_action, array($mod->getController(), $this->_action));
					add_action('wp_ajax_nopriv_' . $this->_action, array($mod->getController(), $this->_action));
					break;
				default:
					$this->_res = $mod->exec($this->_action);
					break;
			}
		}
	}
	protected function _extractTables( $tablesDir = WAIC_TABLES_DIR ) {
		$mDirHandle = opendir($tablesDir);
		while ( ( $file = readdir($mDirHandle) ) !== false ) {
			if ( is_file($tablesDir . $file) && ( '.' != $file ) && ( '..' != $file ) && strpos($file, '.php') ) {
				$this->_extractTable( str_replace('.php', '', $file), $tablesDir );
			}
		}
	}
	protected function _extractTable( $tableName, $tablesDir = WAIC_TABLES_DIR ) {
		waicImportClass('noClassNameHere', $tablesDir . $tableName . '.php');
		$this->_tables[$tableName] = WaicTable::_($tableName);
	}
	/**
	 * Public alias for _extractTables method
	 *
	 * @see _extractTables
	 */
	public function extractTables( $tablesDir ) {
		if (!empty($tablesDir)) {
			$this->_extractTables($tablesDir);
		}
	}
	public function exec() {
		//deprecated
	}
	public function getTables() {
		return $this->_tables;
	}
	/**
	 * Return table by name
	 *
	 * @param string $tableName table name in database
	 * @return object table
	 * @example WaicFrame::_()->getTable('products')->getAll()
	 */
	public function getTable( $tableName ) {
		if (empty($this->_tables[$tableName])) {
			$this->_extractTable($tableName);
		}
		return $this->_tables[$tableName];
	}
	public function getModules( $filter = array() ) {
		$res = array();
		if (empty($filter)) {
			$res = $this->_modules;
		} else {
			foreach ($this->_modules as $code => $mod) {
				if (isset($filter['type'])) {
					if (is_numeric($filter['type']) && $filter['type'] == $mod->getTypeID()) {
						$res[$code] = $mod;
					} elseif ($filter['type'] == $mod->getType()) {
						$res[$code] = $mod;
					}
				}
			}
		}
		return $res;
	}

	public function getModule( $code ) {
		return ( isset($this->_modules[$code]) ? $this->_modules[$code] : null );
	}
	public function inPlugin() {
		return $this->_inPlugin;
	}
	public function usePackAssets() {
		if (!$this->_useFootAssets && $this->getModule('options') && $this->getModule('options')->get('foot_assets')) {
			$this->_useFootAssets = true;
		}
		return $this->_useFootAssets;
	}
	/**
	 * Push data to script array to use it all in addScripts method
	 *
	 * @see wp_enqueue_script definition
	 */
	public function addScript( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false, $vars = array() ) {
		$src = empty($src) ? $src : WaicUri::_($src);
		if (!$ver) {
			$ver = WAIC_VERSION;
		}
		if ($this->_scriptsInitialized) {
			wp_enqueue_script($handle, $src, $deps, $ver, $in_footer);
		} else {
			$this->_scripts[] = array(
				'handle' => $handle,
				'src' => $src,
				'deps' => $deps,
				'ver' => $ver,
				'in_footer' => $in_footer,
				'vars' => $vars,
			);
		}
	}
	/**
	 * Add all scripts from _scripts array to wordpress
	 */
	public function addScripts() {
		if (!empty($this->_scripts)) {
			foreach ($this->_scripts as $s) {
				wp_enqueue_script($s['handle'], $s['src'], $s['deps'], $s['ver'], $s['in_footer']);

				if ($s['vars'] || isset($this->_scriptsVars[$s['handle']])) {
					$vars = array();
					if ($s['vars']) {
						$vars = $s['vars'];
					}
					if ($this->_scriptsVars[$s['handle']]) {
						$vars = array_merge($vars, $this->_scriptsVars[$s['handle']]);
					}
					if ($vars) {
						foreach ($vars as $k => $v) {
							wp_localize_script($s['handle'], $k, is_array($v) ? $v : array($v));
						}
					}
				}
			}
		}
		$this->_scriptsInitialized = true;
	}
	public function addJSVar( $script, $name, $val ) {
		if ($this->_scriptsInitialized) {
			wp_localize_script($script, $name, is_array($val) ? $val : array($val));
		} else {
			$this->_scriptsVars[$script][$name] = $val;
		}
	}

	public function addStyle( $handle, $src = false, $deps = array(), $ver = false, $media = 'all' ) {
		$src = empty($src) ? $src : WaicUri::_($src);
		if (!$ver) {
			$ver = WAIC_VERSION;
		}
		if ($this->_stylesInitialized) {
			wp_enqueue_style($handle, $src, $deps, $ver, $media);
		} else {
			$this->_styles[] = array(
				'handle' => $handle,
				'src' => $src,
				'deps' => $deps,
				'ver' => $ver,
				'media' => $media,
			);
		}
	}
	public function addStyles() {
		if (!empty($this->_styles)) {
			foreach ($this->_styles as $s) {
				wp_enqueue_style($s['handle'], $s['src'], $s['deps'], $s['ver'], $s['media']);
			}
		}
		$this->_stylesInitialized = true;
	}
	//Very interesting thing going here.............
	public function loadPlugins() {
		require_once ABSPATH . 'wp-includes/pluggable.php';
	}
	public function loadWPSettings() {
		require_once ABSPATH . 'wp-settings.php';
	}
	public function loadLocale() {
		require_once ABSPATH . 'wp-includes/locale.php';
	}
	public function moduleActive( $code ) {
		return isset($this->_modules[$code]);
	}
	public function moduleExists( $code ) {
		if ($this->moduleActive($code)) {
			return true;
		}
		return isset($this->_allModules[$code]);
	}
	public function isTplEditor() {
		$tplEditor = WaicReq::getVar('tplEditor');
		return (bool) $tplEditor;
	}
	/**
	 * This is custom method for each plugin and should be modified if you create copy from this instance.
	 */
	public function isAdminPlugOptsPage() {
		$page = WaicReq::getVar('page');
		if (is_admin() && !empty($page) && strpos($page, self::_()->getModule('adminmenu')->getMainSlug()) !== false) {
			return true;
		}
		return false;
	}
	public function isAdminPlugPage() {
		if ($this->isAdminPlugOptsPage()) {
			return true;
		}
		return false;
	}
	public function licenseDeactivated() {
		return ( !$this->getModule('license') && $this->moduleExists('license') );
	}
	public function savePluginActivationErrors() {
		update_option(WAIC_CODE . '_plugin_activation_errors', ob_get_contents());
	}
	public function getActivationErrors() {
		return get_option(WAIC_CODE . '_plugin_activation_errors');
	}
	public function isPro( $isActive = true ) {
		if ($isActive) {
			return $this->moduleExists('license') && $this->getModule('license') && $this->moduleExists('postscreatepro') && $this->getModule('postscreatepro');
		}
		return $this->moduleExists('license') && $this->getModule('license');
	}
	public function getProUrl() {
		return 'bizcity.vn';
	}
	private function _writeLog( $data, $type ) {
		if (is_array($data)) {
			ob_start();
			var_dump($data); 
			$data = ob_get_clean();
		}
		$data = gmdate('c') . ' ' . $type . ': ' . $data . PHP_EOL;
		file_put_contents(WAIC_LOG_DIR . 'waic' . gmdate('Y-m-d') . '.log', $data, FILE_APPEND);
	}
	public function saveDebugLogging( $debug = '', $wc = true, $typ = 'DEBUG' ) {
		if ($this->getModule('options')->getModel()->get('plugin', 'logging') == 1) {
			if (!empty($debug)) {
				if ($wc && function_exists('wc_get_logger')) {
					$logger = wc_get_logger();
					if ($logger) {
						$logger->debug(wc_print_r($debug, true), array('source' => 'waic-debug-logging'));
					} 
				} else {
					$this->_writeLog($debug, $typ);
				}
			} else if ($this->haveErrors()) {
				if ($wc && function_exists('wc_get_logger')) {
					$logger = wc_get_logger();
					if ($logger) {
						$logger->Warning(wc_print_r($this->getErrors(), true), array('source' => 'waic-debug-logging'));
					} 
				} else {
					$this->_writeLog($this->getErrors(), 'WARNING');
				}
			}
		}
	}
	public function printInlineJs( $js, $attrs = array() ) {
		if (function_exists('wp_print_inline_script_tag')) {
			wp_print_inline_script_tag($js, $attrs);
		}
	}
}
