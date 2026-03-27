<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicErrors {
	const FATAL = 'fatal';
	const MOD_INSTALL = 'mod_install';
	private static $errors = array();
	private static $haveErrors = false;
	
	public static $current = array();
	public static $displayed = false;
	
	public static function push( $error, $type = 'common' ) {
		if (!isset(self::$errors[$type])) {
			self::$errors[$type] = array();
		}
		if (is_array($error)) {
			self::$errors[$type] = array_merge(self::$errors[$type], $error);
		} else {
			self::$errors[$type][] = $error;
		}
		self::$haveErrors = true;
		
		if ('session' == $type) {
			self::setSession(self::$errors[$type]);
		}
	}
	public static function setSession( $error ) {
		$sesErrors = self::getSession();
		if (empty($sesErrors)) {
			$sesErrors = array();
		}
		if (is_array($error)) {
			$sesErrors = array_merge($sesErrors, $error);
		} else {
			$sesErrors[] = $error;
		}
		WaicReq::setVar('sesErrors', $sesErrors, 'session');
	}
	public static function init() {
		$waicErrors = WaicReq::getVar('waicErrors');
		if (!empty($waicErrors)) {
			if (!is_array($waicErrors)) {
				$waicErrors = array( $waicErrors );
			}
			$waicErrors = array_map('htmlspecialchars', array_map('stripslashes', array_map('trim', $waicErrors)));
			if (!empty($waicErrors)) {
				self::$current = $waicErrors;
				if (is_admin()) {
					add_action('admin_notices', array('WaicErrors', 'showAdminErrors'));
				} else {
					add_filter('the_content', array('WaicErrors', 'appendErrorsContent'), 99999);
				}
			}
		}
	}
	public static function showAdminErrors() {
		if (self::$current) {
			foreach (self::$current as $error) {
				echo '<div class="error notice is-dismissible"><p><strong>' . esc_html($error) . '</strong></p></div>';
			}
		}
	}
	public static function appendErrorsContent( $content ) {
		if (!self::$displayed && !empty(self::$current)) {
			$content = '<div class="toeErrorMsg">' . implode('<br />', self::$current) . '</div>' . $content;
			self::$displayed = true;
		}
		return $content;
	}
	public static function getSession() {
		return WaicReq::getVar('sesErrors', 'session');
	}
	public static function clearSession() {
		WaicReq::clearVar('sesErrors', 'session');
	}
	public static function get( $type = '' ) {
		$res = array();
		if (!empty(self::$errors)) {
			if (empty($type)) {
				foreach (self::$errors as $e) {
					foreach ($e as $error) {
						$res[] = $error;
					}
				}
			} else {
				$res = self::$errors[$type];
			}
		}
		return $res;
	}
	public static function haveErrors( $type = '' ) {
		if (empty($type)) {
			return self::$haveErrors;
		} else {
			return isset(self::$errors[$type]);
		}
	}
}
