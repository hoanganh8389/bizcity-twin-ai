<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicModInstaller {
	private static $_current = array();
	/**
	 * Install new WaicModule into plugin
	 *
	 * @param string $module new WaicModule data (@see classes/tables/modules.php)
	 * @param string $path path to the main plugin file from what module is installed
	 * @return bool true - if install success, else - false
	 */
	public static function install( $module, $path ) {
		$exPlugDest = explode('plugins', $path);
		if (!empty($exPlugDest[1])) {
			$module['ex_plug_dir'] = str_replace(WAIC_DS, '', $exPlugDest[1]);
		}
		$path = $path . WAIC_DS . $module['code'];
		if (!empty($module) && !empty($path) && is_dir($path)) {
			if (self::isModule($path)) {
				$filesMoved = false;
				if (empty($module['ex_plug_dir'])) {
					$filesMoved = self::moveFiles($module['code'], $path);
				} else {
					$filesMoved = true;     //Those modules doesn't need to move their files
				}
				if ($filesMoved) {
					if (WaicFrame::_()->getTable('modules')->exists($module['code'], 'code')) {
						WaicFrame::_()->getTable('modules')->delete(array('code' => $module['code']));
					}
					if ('license' != $module['code']) {
						$module['active'] = 0;
					}
					WaicFrame::_()->getTable('modules')->insert($module);
					self::_runModuleInstall($module);
					self::_installTables($module);
					return true;
				} else {
					WaicErrors::push(esc_html(__('Move files failed', 'ai-copilot-content-generator') . ': ' . $module['code']), WaicErrors::MOD_INSTALL);
				}
			} else {
				WaicErrors::push(esc_html($module['code'] . ' ' . __('is not plugin module', 'ai-copilot-content-generator')), WaicErrors::MOD_INSTALL);
			}
		}
		return false;
	}
	protected static function _runModuleInstall( $module, $action = 'install' ) {
		$moduleLocationDir = WAIC_MODULES_DIR;
		if (!empty($module['ex_plug_dir'])) {
			$moduleLocationDir = WaicUtils::getPluginDir( $module['ex_plug_dir'] );
		}
		if (is_dir($moduleLocationDir . $module['code'])) {
			if (!class_exists(waicStrFirstUp(WAIC_CODE) . $module['code'])) {
				waicImportClass(waicStrFirstUp(WAIC_CODE) . $module['code'], $moduleLocationDir . $module['code'] . WAIC_DS . 'mod.php');
			}
			$moduleClass = waicToeGetClassName($module['code']);
			$moduleObj = new $moduleClass($module);
			if ($moduleObj) {
				$moduleObj->$action();
			}
		}
	}
	/**
	 * Check whether is or no module in given path
	 *
	 * @param string $path path to the module
	 * @return bool true if it is module, else - false
	 */
	public static function isModule( $path ) {
		return true;
	}
	/**
	 * Move files to plugin modules directory
	 *
	 * @param string $code code for module
	 * @param string $path path from what module will be moved
	 * @return bool is success - true, else - false
	 */
	public static function moveFiles( $code, $path ) {
		if (!is_dir(WAIC_MODULES_DIR . $code)) {
			if (mkdir(WAIC_MODULES_DIR . $code)) {
				WaicUtils::copyDirectories($path, WAIC_MODULES_DIR . $code);
				return true;
			} else {
				/* translators: %s: module directory */
				WaicErrors::push(esc_html(sprintf(__('Cannot create module directory. Try to set permission to %s directory 755 or 777', 'ai-copilot-content-generator'), WAIC_MODULES_DIR)), WaicErrors::MOD_INSTALL);
			}
		} else {
			return true;
		}
		return false;
	}
	private static function _getPluginLocations() {
		$locations = array();
		$plug = WaicReq::getVar('plugin');
		if (empty($plug)) {
			$plug = WaicReq::getVar('checked');
			$plug = $plug[0];
		}
		$locations['plugPath'] = plugin_basename( trim( $plug ) );
		$locations['plugDir'] = dirname(WP_PLUGIN_DIR . WAIC_DS . $locations['plugPath']);
		$locations['plugMainFile'] = WP_PLUGIN_DIR . WAIC_DS . $locations['plugPath'];
		$locations['xmlPath'] = $locations['plugDir'] . WAIC_DS . 'install.xml';
		return $locations;
	}
	private static function _getModulesFromXml( $xmlPath ) {
		$xml = WaicUtils::getXml($xmlPath);
		if ($xml) {
			if (isset($xml->modules) && isset($xml->modules->mod)) {
				$modules = array();
				$xmlMods = $xml->modules->children();
				foreach ($xmlMods->mod as $mod) {
					$modules[] = $mod;
				}
				if (empty($modules)) {
					WaicErrors::push(esc_html__('No modules were found in XML file', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
				} else {
					return $modules;
				}
			} else {
				WaicErrors::push(esc_html__('Invalid XML file', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
			}
		} else {
			WaicErrors::push(esc_html__('No XML file were found', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
		}
		return false;
	}
	/**
	 * Check whether modules is installed or not, if not and must be activated - install it
	 *
	 * @param array $codes array with modules data to store in database
	 * @param string $path path to plugin file where modules is stored (__FILE__ for example)
	 * @return bool true if check ok, else - false
	 */
	public static function check( $extPlugName = '' ) {
		if (WAIC_TEST_MODE) {
			add_action('activated_plugin', array(WaicFrame::_(), 'savePluginActivationErrors'));
		}
		$locations = self::_getPluginLocations();
		$modules = self::_getModulesFromXml($locations['xmlPath']);
		if ($modules) {
			foreach ($modules as $m) {
				$modDataArr = WaicUtils::xmlNodeAttrsToArr($m);
				if (!empty($modDataArr)) {
					//If module Exists - just activate it, we can't check this using WaicFrame::moduleExists because this will not work for multy-site WP
					if (WaicFrame::_()->getTable('modules')->exists($modDataArr['code'], 'code')) {
						self::activate($modDataArr);
					} else {                                           //  if not - install it
						$m = '';
						if (!self::install($modDataArr, $locations['plugDir'])) {
							WaicErrors::push(esc_html(__('Install failed', 'ai-copilot-content-generator') . ': ' . $modDataArr['code']), WaicErrors::MOD_INSTALL);
						}
					}
				}
			}
		} else {
			WaicErrors::push(esc_html__('Error Activate module', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
		}
		if (WaicErrors::haveErrors(WaicErrors::MOD_INSTALL)) {
			self::displayErrors(false);
			return false;
		}
		update_option(WAIC_CODE . '_full_installed', 1);
		return true;
	}
	/**
	 * Public alias for _getCheckRegPlugs()
	 * We will run this each time plugin start to check modules activation messages
	 */
	public static function checkActivationMessages() {
	}
	/**
	 * Deactivate module after deactivating external plugin
	 */
	public static function deactivate() {
		$locations = self::_getPluginLocations();
		$modules = self::_getModulesFromXml($locations['xmlPath']);
		if ($modules) {
			foreach ($modules as $m) {
				$modDataArr = WaicUtils::xmlNodeAttrsToArr($m);
				if (WaicFrame::_()->moduleActive($modDataArr['code'])) { //If module is active - then deacivate it
					if (WaicFrame::_()->getModule('adminmenu')->getModel('modules')->put(array(
						'id' => WaicFrame::_()->getModule($modDataArr['code'])->getID(),
						'active' => 0,
					))->error) {
						WaicErrors::push(esc_html__('Error Deactivation module', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
					}
				}
			}
		}
		if (WaicErrors::haveErrors(WaicErrors::MOD_INSTALL)) {
			self::displayErrors(false);
			return false;
		}
		return true;
	}
	public static function activate( $modDataArr ) {
		$locations = self::_getPluginLocations();
		$modules = self::_getModulesFromXml($locations['xmlPath']);
		if ($modules) {
			foreach ($modules as $m) {
				$modDataArr = WaicUtils::xmlNodeAttrsToArr($m);
				if (!WaicFrame::_()->moduleActive($modDataArr['code']) && 'license' == $modDataArr['code']) { //If module is not active - then acivate it
					if (WaicFrame::_()->getModule('adminmenu')->getModel('modules')->put(array(
						'code' => $modDataArr['code'],
						'active' => 1,
					))->error) {
						WaicErrors::push(esc_html__('Error Activating module', 'ai-copilot-content-generator'), WaicErrors::MOD_INSTALL);
					} else {
						$dbModData = WaicFrame::_()->getModule('adminmenu')->getModel('modules')->get(array('code' => $modDataArr['code']));
						if (!empty($dbModData) && !empty($dbModData[0])) {
							$modDataArr['ex_plug_dir'] = $dbModData[0]['ex_plug_dir'];
						}
						self::_runModuleInstall($modDataArr, 'activate');
					}
				}
			}
		}
	} 
	/**
	 * Display all errors for module installer, must be used ONLY if You realy need it
	 */
	public static function displayErrors( $exit = true ) {
		$errors = WaicErrors::get(WaicErrors::MOD_INSTALL);
		foreach ($errors as $e) {
			echo '<b class="woobewoo-error">' . esc_html($e) . '</b><br />';
		}
		if ($exit) {
			exit();
		}
	}
	public static function uninstall() {
		$locations = self::_getPluginLocations();
		$modules = self::_getModulesFromXml($locations['xmlPath']);
		if ($modules) {
			foreach ($modules as $m) {
				$modDataArr = WaicUtils::xmlNodeAttrsToArr($m);
				self::_uninstallTables($modDataArr);
				WaicFrame::_()->getModule('adminmenu')->getModel('modules')->delete(array('code' => $modDataArr['code']));
				WaicUtils::deleteDir(WAIC_MODULES_DIR . $modDataArr['code']);
				if ('license' == $modDataArr['code']) {
					WaicFrame::_()->getModule('options')->getModel()->save('lic', 'license_save_name', '');
				}
			}
		}
	}
	protected static function _uninstallTables( $module ) {
		if (is_dir(WAIC_MODULES_DIR . $module['code'] . WAIC_DS . 'tables')) {
			$tableFiles = WaicUtils::getFilesList(WAIC_MODULES_DIR . $module['code'] . WAIC_DS . 'tables');
			if (!empty($tableNames)) {
				foreach ($tableFiles as $file) {
					$tableName = str_replace('.php', '', $file);
					if (WaicFrame::_()->getTable($tableName)) {
						WaicFrame::_()->getTable($tableName)->uninstall();
					}
				}
			}
		}
	}
	public static function _installTables( $module, $action = 'install' ) {
		$modDir = empty($module['ex_plug_dir']) ? WAIC_MODULES_DIR . $module['code'] . WAIC_DS : WaicUtils::getPluginDir($module['ex_plug_dir']) . $module['code'] . WAIC_DS; 
		if (is_dir($modDir . 'tables')) {
			$tableFiles = WaicUtils::getFilesList($modDir . 'tables');
			if (!empty($tableFiles)) {
				WaicFrame::_()->extractTables($modDir . 'tables' . WAIC_DS);
				foreach ($tableFiles as $file) {
					$tableName = str_replace('.php', '', $file);
					if (WaicFrame::_()->getTable($tableName)) {
						WaicFrame::_()->getTable($tableName)->$action();
					}
				}
			}
		}
	}
}
