<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicUtils {
	public static $currentTZ = null;
	public static $currentDateFormat = null;
	public static $currentDateFormatDB = 'Y-m-d';
	
	public static function jsonEncode( $arr, $ent = false ) {
		return ( is_array($arr) || is_object($arr) ) ? waicJsonEncodeUTFnormal($arr, $ent) : waicJsonEncodeUTFnormal(array());
	}
	public static function jsonDecode( $str ) {
		if (is_array($str)) {
			return $str;
		}
		if (is_object($str)) {
			return (array) $str;
		}
		return empty($str) ? array() : json_decode($str, true);
	}
	public static function unserialize( $data ) {
		return unserialize($data);
	}
	public static function serialize( $data ) {
		return serialize($data);
	}
	public static function createDir( $path, $params = array('chmod' => null, 'httpProtect' => false) ) {
		if (@mkdir($path)) {
			if (!is_null($params['chmod'])) {
				@chmod($path, $params['chmod']);
			}
			if (!empty($params['httpProtect'])) {
				self::httpProtectDir($path);
			}
			return true;
		}
		return false;
	}
	public static function httpProtectDir( $path ) {
		$content = 'DENY FROM ALL';
		if (strrpos($path, WAIC_DS) != strlen($path)) {
			$path .= WAIC_DS;
		}
		if (file_put_contents($path . '.htaccess', $content)) {
			return true;
		}
		return false;
	}
	/**
	 * Copy all files from one directory ($source) to another ($destination)
	 *
	 * @param string $source path to source directory
	 * @params string $destination path to destination directory
	 */
	public static function copyDirectories( $source, $destination ) {
		if (is_dir($source)) {
			@mkdir($destination);
			$directory = dir($source);
			while ( false !== ( $readdirectory = $directory->read() ) ) {
				if ( ( '.' == $readdirectory ) || ( '..' == $readdirectory ) ) {
					continue;
				}
				$PathDir = $source . '/' . $readdirectory; 
				if (is_dir($PathDir)) {
					self::copyDirectories( $PathDir, $destination . '/' . $readdirectory );
					continue;
				}
				copy( $PathDir, $destination . '/' . $readdirectory );
			}
			$directory->close();
		} else {
			copy( $source, $destination );
		}
	}
	public static function getIP() {
		$res = '';
		if (!isset($_SERVER['HTTP_CLIENT_IP']) || empty($_SERVER['HTTP_CLIENT_IP'])) {
			if (!isset($_SERVER['HTTP_X_REAL_IP']) || empty($_SERVER['HTTP_X_REAL_IP'])) {
				if (!isset($_SERVER['HTTP_X_SUCURI_CLIENTIP']) || empty($_SERVER['HTTP_X_SUCURI_CLIENTIP'])) {
					if (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
						$res = empty($_SERVER['REMOTE_ADDR']) ? '' : sanitize_text_field($_SERVER['REMOTE_ADDR']);
					} else {
						$res = sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']);
					}
				} else {
					$res = sanitize_text_field($_SERVER['HTTP_X_SUCURI_CLIENTIP']);
				}
			} else {
				$res = sanitize_text_field($_SERVER['HTTP_X_REAL_IP']);
			}
		} else {
			$res = sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
		}
		
		return $res;
	}
	
	/**
	 * Parse xml file into simpleXML object
	 *
	 * @param string $path path to xml file
	 * @return mixed object SimpleXMLElement if success, else - false
	 */
	public static function getXml( $path ) {
		if (is_file($path)) {
			return simplexml_load_file($path);
		}
		return false;
	}
	/**
	 * Check if the element exists in array
	 *
	 * @param array $param 
	 */
	public static function xmlAttrToStr( $param, $element ) {
		if (isset($param[$element])) {
			// convert object element to string
			return (string) $param[$element];
		} else {
			return '';
		}
	}
	public static function xmlNodeAttrsToArr( $node ) {
		$arr = array();
		foreach ($node->attributes() as $a => $b) {
			$arr[$a] = self::xmlAttrToStr($node, $a);
		}
		return $arr;
	}
	public static function deleteFile( $str ) {
		return @unlink($str);
	}
	public static function deleteDir( $str ) {
		if (is_file($str)) {
			return self::deleteFile($str);
		} elseif (is_dir($str)) {
			$scan = glob(rtrim($str, '/') . '/*');
			foreach ($scan as $index => $path) {
				self::deleteDir($path);
			}
			return @rmdir($str);
		}
	}
	/**
	 * Retrives list of directories ()
	 */
	public static function getDirList( $path ) {
		$res = array();
		if (is_dir($path)) {
			$files = scandir($path);
			foreach ($files as $f) {
				if ( ( '.' == $f ) || ( '..' == $f ) || ( '.svn' == $f ) ) {
					continue;
				}
				if (!is_dir($path . $f)) {
					continue;
				}
				$res[$f] = array('path' => $path . $f . WAIC_DS);
			}
		}
		return $res;
	}
	/**
	 * Retrives list of files
	 */
	public static function getFilesList( $path ) {
		$files = array();
		if (is_dir($path)) {
			$dirHandle = opendir($path);
			while ( ( $file = readdir($dirHandle) ) !== false ) {
				if ( ( '.' != $file ) && ( '..' != $file ) && ( '.svn' != $f ) && is_file($path . WAIC_DS . $file) ) {
					$files[] = $file;
				}
			}
		}
		return $files;
	}
	/**
	 * Check if $var is object or something another in future
	 */
	public static function is( $var, $what = '' ) {
		if (!is_object($var)) {
			return false;
		}
		if (get_class($var) == $what) {
			return true;
		}
		return false;
	}
	/**
	 * Get array with all monthes of year, uses in paypal pro and sagepay payment modules for now, than - who knows)
	 *
	 * @return array monthes
	 */
	public static function getMonthesArray() {
		static $monthsArray = array();
		//Some cache
		if (!empty($monthsArray)) {
			return $monthsArray;
		}
		for ($i = 1; $i < 13; $i++) {
			$monthsArray[sprintf('%02d', $i)] = strftime('%B', mktime(0, 0, 0, $i, 1, 2000));
		}
		return $monthsArray;
	}
	public static function getWeekDaysArray() {
		$timestamp = strtotime('next Sunday');
		$days = array();
		for ($i = 0; $i < 7; $i++) {
			$day = strftime('%A', $timestamp);
			$days[ strtolower($day) ] = $day;
			$timestamp = strtotime('+1 day', $timestamp);
		}
		return $days;
	}
	/**
	 * Get an array with years range from current year
	 *
	 * @param int $from - how many years from today ago
	 * @param int $to - how many years in future
	 * @param $formatKey - format for keys in array, @see strftime
	 * @param $formatVal - format for values in array, @see strftime
	 * @return array - years 
	 */
	public static function getYearsArray( $from, $to, $formatKey = '%Y', $formatVal = '%Y' ) {
		$today = getdate();
		$yearsArray = array();
		for ($i = $today['year'] - $from; $i <= $today['year'] + $to; $i++) {
			$yearsArray[strftime($formatKey, mktime(0, 0, 0, 1, 1, $i))] = strftime($formatVal, mktime(0, 0, 0, 1, 1, $i));
		}
		return $yearsArray;
	}
	/**
	 * Make replacement in $text, where it will be find all keys with prefix ":" and replace it with corresponding value
	 *
	 * @see email_templatesModel::renderContent()
	 * @see checkoutView::getSuccessPage()
	 */
	public static function makeVariablesReplacement( $text, $variables ) {
		if (!empty($text) && !empty($variables) && is_array($variables)) {
			foreach ($variables as $k => $v) {
				$text = str_replace(':' . $k, $v, $text);
			}
			return $text;
		}
		return false;
	}
	/**
	 * Retrive full directory of plugin
	 *
	 * @param string $name - plugin name
	 * @return string full path in file system to plugin directory
	 */
	public static function getPluginDir( $name = '' ) {
		return WP_PLUGIN_DIR . WAIC_DS . $name . WAIC_DS;
	}
	public static function getPluginPath( $name = '' ) {
		$path = plugins_url($name) . '/';
		if (substr($path, 0, 4) != 'http') {
			$home = home_url();
			if (is_ssl() && substr($home, 0, 5) != 'https') {
				$home = 'https' . substr($home, 4);
			}
			$path = $home . ( substr($path, 0, 1) == '/' ? '' : '/' ) . $path;
		}
		return $path;
	}
	public static function getExtModDir( $plugName ) {
		return self::getPluginDir($plugName);
	}
	public static function getExtModPath( $plugName ) {
		return self::getPluginPath($plugName);
	}
	public static function getCurrentWPThemePath() {
		return get_template_directory_uri();
	}
	public static function isThisCommercialEdition() {
		foreach (WaicFrame::_()->getModules() as $m) {
			if (is_object($m) && $m->isExternal()) {
				return true;
			}
		}
		return false;
	}
	public static function checkNum( $val, $default = 0 ) {
		if (!empty($val) && is_numeric($val)) {
			return $val;
		}
		return $default;
	}
	public static function checkString( $val, $default = '' ) {
		if (!empty($val) && is_string($val)) {
			return $val;
		}
		return $default;
	}
	/**
	 * Retrives extension of file
	 *
	 * @param string $path - path to a file
	 * @return string - file extension
	 */
	public static function getFileExt( $path ) {
		return strtolower( pathinfo($path, PATHINFO_EXTENSION) );
	}
	public static function getRandStr( $length = 10, $allowedChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890', $params = array() ) {
		$result = '';
		$allowedCharsLen = strlen($allowedChars);
		if (isset($params['only_lowercase']) && $params['only_lowercase']) {
			$allowedChars = strtolower($allowedChars);
		}
		while (strlen($result) < $length) {
			$result .= substr($allowedChars, rand(0, $allowedCharsLen), 1);
		}

		return $result;
	}
	/**
	 * Get current host location
	 *
	 * @return string host string
	 */
	public static function getHost() {
		return empty($_SERVER['HTTP_HOST']) ? '' : sanitize_text_field($_SERVER['HTTP_HOST']);
	}
	/**
	 * Check if device is mobile
	 *
	 * @return bool true if user are watching this site from mobile device
	 */
	public static function isMobile() {
		waicImportClass('Mobile_Detect', WAIC_HELPERS_DIR . 'mobileDetect.php');
		$mobileDetect = new Mobile_Detect();
		return $mobileDetect->isMobile();
	}
	/**
	 * Check if device is tablet
	 *
	 * @return bool true if user are watching this site from tablet device
	 */
	public static function isTablet() {
		waicImportClass('Mobile_Detect', WAIC_HELPERS_DIR . 'mobileDetect.php');
		$mobileDetect = new Mobile_Detect();
		return $mobileDetect->isTablet();
	}
	public static function getUploadsDir() {
		$uploadDir = wp_upload_dir();
		return $uploadDir['basedir'];
	}
	public static function getUploadsPath() {
		$uploadDir = wp_upload_dir();
		return $uploadDir['baseurl'];
	}
	public static function arrToCss( $data ) {
		$res = '';
		if (!empty($data)) {
			foreach ($data as $k => $v) {
				$res .= $k . ':' . $v . ';';
			}
		}
		return $res;
	}
	/**
	 * Activate all CSP Plugins
	 * 
	 * @return NULL Check if it's site or multisite and activate.
	 */
	public static function activatePlugin( $networkwide ) {
		global $wpdb;
		if (WAIC_TEST_MODE) {
			add_action('activated_plugin', array(WaicFrame::_(), 'savePluginActivationErrors'));
		}
		if (function_exists('is_multisite') && is_multisite() && $networkwide) {
			$blog_id = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
			foreach ($blog_id as $id) {
				if (switch_to_blog($id)) {
					WaicInstaller::init();
				} 
			}
			restore_current_blog();
			return;
		} else {
			WaicInstaller::init();
		}
	}

	/**
	 * Delete All CSP Plugins
	 * 
	 * @return NULL Check if it's site or multisite and decativate it.
	 */
	public static function deletePlugin() {
		global $wpdb;
		if (function_exists('is_multisite') && is_multisite()) {
			$blog_id = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
			foreach ($blog_id as $id) {
				if (switch_to_blog($id)) {
					WaicInstaller::delete();
				} 
			}
			restore_current_blog();
			return;
		} else {
			WaicInstaller::delete();
		}
	}
	public static function deactivatePlugin( $networkwide ) {
		global $wpdb;
		if (function_exists('is_multisite') && is_multisite() && $networkwide) {
			$blog_id = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
			foreach ($blog_id as $id) {
				if (switch_to_blog($id)) {
					WaicInstaller::deactivate();
				} 
			}
			restore_current_blog();
			return;
		} else {
			WaicInstaller::deactivate();
		}
	}
	public static function isWritable( $filename ) {
		return is_writable($filename);
	}
	
	public static function isReadable( $filename ) {
		return is_readable($filename);
	}
	
	public static function fileExists( $filename ) {
		return file_exists($filename);
	}
	public static function isPluginsPage() {
		return ( basename(WaicReq::getVar('SCRIPT_NAME', 'server')) === 'plugins.php' );
	}
	public static function isSessionStarted() {
		if (version_compare(PHP_VERSION, '5.4.0') >= 0 && function_exists('session_status')) {
			return !( session_status() == PHP_SESSION_NONE );
		} else {
			return !( session_id() == '' );
		}
	}
	public static function generateBgStyle( $data ) {
		$stageBgStyles = array();
		$stageBgStyle = '';
		switch ($data['type']) {
			case 'color':
				$stageBgStyles[] = 'background-color: ' . $data['color'];
				$stageBgStyles[] = 'opacity: ' . $data['opacity'];
				break;
			case 'img':
				$stageBgStyles[] = 'background-image: url(' . $data['img'] . ')';
				switch ($data['img_pos']) {
					case 'center':
						$stageBgStyles[] = 'background-repeat: no-repeat';
						$stageBgStyles[] = 'background-position: center center';
						break;
					case 'tile':
						$stageBgStyles[] = 'background-repeat: repeat';
						break;
					case 'stretch':
						$stageBgStyles[] = 'background-repeat: no-repeat';
						$stageBgStyles[] = '-moz-background-size: 100% 100%';
						$stageBgStyles[] = '-webkit-background-size: 100% 100%';
						$stageBgStyles[] = '-o-background-size: 100% 100%';
						$stageBgStyles[] = 'background-size: 100% 100%';
						break;
				}
				break;
		}
		if (!empty($stageBgStyles)) {
			$stageBgStyle = implode(';', $stageBgStyles);
		}
		return $stageBgStyle;
	}
	/**
	 * Parse wordpress post/page/custom post type content for images and return it's IDs if there are images
	 *
	 * @param string $content Post/page/custom post type content
	 * @return array List of images IDs from content
	 */
	public static function parseImgIds( $content ) {
		$res = array();
		preg_match_all('/wp-image-(?<ID>\d+)/', $content, $matches);
		if ($matches && isset($matches['ID']) && !empty($matches['ID'])) {
			$res = $matches['ID'];
		}
		return $res;
	}
	/**
	 * Retrive file path in file system from provided URL, it should be in wp-content/uploads
	 *
	 * @param string $url File url path, should be in wp-content/uploads
	 * @return string Path in file system to file
	 */
	public static function getUploadFilePathFromUrl( $url ) {
		$uploadsPath = self::getUploadsPath();
		$uploadsDir = self::getUploadsDir();
		return str_replace($uploadsPath, $uploadsDir, $url);
	}
	/**
	 * Retrive file URL from provided file system path, it should be in wp-content/uploads
	 *
	 * @param string $path File path, should be in wp-content/uploads
	 * @return string URL to file
	 */
	public static function getUploadUrlFromFilePath( $path ) {
		$uploadsPath = self::getUploadsPath();
		$uploadsDir = self::getUploadsDir();
		return str_replace($uploadsDir, $uploadsPath, $path);
	}
	public static function getUserAgent() {
		$userAgent = self::getUserBrowserString();
		if (strpos($userAgent, 'Mozilla') !== false) {
			$userAgent = 'Mozilla/5.0';
		} else if (strpos($userAgent, 'python-httpx') !== false) {
			$userAgent = 'python-httpx/0.27.0';
		} else if (strpos($userAgent, 'node') !== false) {
			$userAgent = 'node';
		}
		return $userAgent;
	}
	public static function setAdminUser() {
		$users = get_users(array('role' => 'administrator'));
		$admin = !empty($users) ? $users[0] : false;
		if ($admin) {
			wp_set_current_user($admin->ID, $admin->user_login);
		}
	}
	public static function getUserBrowserString() {
		return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : false;
	}
	public static function getBrowser() {
		$u_agent = self::getUserBrowserString();
		$bname = 'Unknown';
		$platform = 'Unknown';
		$version = '';
		$pattern = '';
		
		if ($u_agent) {
			//First get the platform?
			if (preg_match('/linux/i', $u_agent)) {
				$platform = 'linux';
			} elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
				$platform = 'mac';
			} elseif (preg_match('/windows|win32/i', $u_agent)) {
				$platform = 'windows';
			}
			// Next get the name of the useragent yes seperately and for good reason
			if ( ( preg_match('/MSIE/i', $u_agent) && !preg_match('/Opera/i', $u_agent) ) || ( strpos($u_agent, 'Trident/7.0; rv:11.0') !== false ) ) {
				$bname = 'Internet Explorer';
				$ub = 'MSIE';
			} elseif (preg_match('/Firefox/i', $u_agent)) {
				$bname = 'Mozilla Firefox';
				$ub = 'Firefox';
			} elseif (preg_match('/Chrome/i', $u_agent)) {
				$bname = 'Google Chrome';
				$ub = 'Chrome';
			} elseif (preg_match('/Safari/i', $u_agent)) {
				$bname = 'Apple Safari';
				$ub = 'Safari';
			} elseif (preg_match('/Opera/i', $u_agent)) {
				$bname = 'Opera';
				$ub = 'Opera';
			} elseif (preg_match('/Netscape/i', $u_agent)) {
				$bname = 'Netscape';
				$ub = 'Netscape';
			}

			// finally get the correct version number
			$known = array('Version', $ub, 'other');
			$pattern = '#(?<browser>' . join('|', $known) . ')[/ ]+(?<version>[0-9.|a-zA-Z.]*)#';

			// see how many we have
			$i = count($matches['browser']);
			if (1 != $i) {
				//we will have two since we are not using 'other' argument yet
				//see if version is before or after the name
				if ( strripos($u_agent, 'Version') < strripos($u_agent, $ub) ) {
					$version = $matches['version'][0];
				} else {
					$version = $matches['version'][1];
				}
			} else {
				$version = $matches['version'][0];
			}
		}

		// check if we have a number
		if ( ( null == $version ) || ( '' == $version ) ) {
			$version = '?';
		}

		return array(
			'userAgent' => $u_agent,
			'name'      => $bname,
			'version'   => $version,
			'platform'  => $platform,
			'pattern'    => $pattern,
		);
	}
	public static function getBrowsersList() {
		return array('Unknown', 'Internet Explorer', 'Mozilla Firefox', 'Google Chrome', 'Apple Safari', 'Opera', 'Netscape');
	}
	public static function getLangCode2Letter() {
		$langCode = self::getLangCode();
		return strlen($langCode) > 2 ? substr($langCode, 0, 2) : $langCode;
	}
	public static function getLangCode() {
		return get_locale();
	}
	public static function getBrowserLangCode() {
		return isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && !empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])
			? strtolower(substr(sanitize_text_field($_SERVER['HTTP_ACCEPT_LANGUAGE']), 0, 2))
			: self::getLangCode2Letter();
	}
	public static function getTimeRange() {
		$time = array();
		$hours = range(1, 11);
		array_unshift($hours, 12);
		$k = 0;
		$count = count($hours);
		for ($i = 0; $i < 4 * $count; $i++) {
			$newItem = $hours[ $k ];
			$newItem .= ':' . ( ( $i % 2 ) ? '30' : '00' );
			$newItem .= ( $i < $count * 2 ) ? 'am' : 'pm';
			if ($i % 2) {
				$k++;
			}
			if ($i == $count * 2 - 1) {
				$k = 0;
			}
			$time[] = $newItem;
		}
		return array_combine($time, $time);
	}
	public static function getSearchEnginesList() {
		return array(
			'google.com' => array('label' => 'Google'),
			'yahoo.com' => array('label' => 'Yahoo!'),
			'youdao.com' => array('label' => 'Youdao'),
			'yandex' => array('label' => 'Yandex'),
			'sogou.com' => array('label' => 'Sogou'),
			'qwant.com' => array('label' => 'Qwant'),
			'bing.com' => array('label' => 'Bing'),
			'munax.com' => array('label' => 'Munax'),
		);
	}
	public static function getSocialList() {
		return array(
			'facebook.com' => array('label' => 'Facebook'),
			'pinterest.com' => array('label' => 'Pinterest'),
			'instagram.com' => array('label' => 'Instagram'),
			'yelp.com' => array('label' => 'Yelp'),
			'vk.com' => array('label' => 'VKontakte'),
			'myspace.com' => array('label' => 'Myspace'),
			'linkedin.com' => array('label' => 'LinkedIn'),
			'plus.google.com' => array('label' => 'Google+'),
			'google.com' => array('label' => 'Google'),
		);
	}
	public static function getReferalUrl() {
		// Simple for now
		return WaicReq::getVar('HTTP_REFERER', 'server');
	}
	public static function getReferalHost() {
		$refUrl = self::getReferalUrl();
		if (!empty($refUrl)) {
			$refer = parse_url( $refUrl );
			if ($refer && isset($refer['host']) && !empty($refer['host'])) {
				return $refer['host'];
			}
		}
		return false;
	}
	public static function getCurrentUserRole() {
		$roles = self::getCurrentUserRoleList();
		if ($roles) {
			$ncaps = count($roles);
			$role = $roles[$ncaps - 1];
			return $role;
		}
		return false;
	}
	public static function getCurrentUserRoleList() {
		global $current_user, $wpdb;
		if ($current_user) {
			$roleKey = $wpdb->prefix . 'capabilities';
			if (isset($current_user->$roleKey) && !empty($current_user->$roleKey)) {
				return array_keys($current_user->$roleKey);
			}
		}
		return false;
	}
	public static function getAllUserRoles() {
		return get_editable_roles();
	}
	public static function getAllUserRolesList() {
		$res = array();
		$roles = self::getAllUserRoles();
		if (!empty($roles)) {
			foreach ($roles as $k => $data) {
				$res[ $k ] = $data['name'];
			}
		}
		return $res;
	}
	public static function rgbToArray( $rgb ) {
		$rgb = array_map('trim', explode(',', trim(str_replace(array('rgb', 'a', '(', ')'), '', $rgb))));
		return $rgb;
	}
	public static function hexToRgb( $hex ) {
		if (strpos($hex, 'rgb') !== false) { // Maybe it's already in rgb format - just return it as array
			return self::rgbToArray($hex);
		}
		$hex = str_replace('#', '', $hex);

		if (strlen($hex) == 3) {
			$r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
			$g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
			$b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
		} else {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		}
		$rgb = array($r, $g, $b);
		return $rgb; // returns an array with the rgb values
	}
	public static function hexToRgbaStr( $hex, $alpha = 1 ) {
		$rgbArr = self::hexToRgb($hex);
		return 'rgba(' . implode(',', $rgbArr) . ',' . $alpha . ')';
	}
	
	/**
	 * $typ: 1 - numeric, 2 - array 
	 */

	public static function getArrayValue( $params, $name, $default = '', $typ = 0, $arr = false, $zero = false, $leer = false ) {
		if (!isset($params[$name])) {
			return $default;
		}
		if (empty($params[$name])) {
			return ( $zero && ( '0' === $params[$name] || 0 === $params[$name] ) ) ? 0 : ( $leer && '' === $params[$name]  ? '' : $default );
		}
		$value = $params[$name];
		if (1 == $typ) {
			if (!is_numeric($value)) {
				return $default;
			}
		} elseif (2 == $typ) {
			if (!is_array($value)) {
				return $default;
			}
		}
		if (( false !== $arr ) && !in_array($value, $arr)) {
			return $default;
		}
		return $value;
	}

	public static function controlNumericValues( $values, $field = 'int' ) {
		foreach ($values as $k => $val) {
			$values[$k] = ( 'dec' == $field ? (float) $val : (int) $val );
		}
		return $values;
	}
	public static function getTimeZone() {
		if (is_null(self::$currentTZ)) {
			$tz = wp_timezone_string();
			if (strpos($tz, ':')) {
				$offset = $tz;
				list($hours, $minutes) = explode(':', $offset);
				if (is_numeric($hours) && is_numeric($minutes)) {
					$seconds = $hours * 60 * 60 + $minutes * 60;
					$tz = timezone_name_from_abbr('', $seconds, 1);
					if (false === $tz) {
						$tz = timezone_name_from_abbr('', $seconds, 0);
					}
				}
			}
			self::$currentTZ = ( false !== $tz ? new DateTimeZone($tz) : new DateTimeZone() );
		}
		return self::$currentTZ;
	}
	public static function getCurrentDateFormatDB() {
		return self::$currentDateFormatDB;
	}
	public static function getCurrentDateFormat() {
		if (is_null(self::$currentDateFormat)) {
			$format = WaicFrame::_()->getModule('options')->get('plugin', 'date_format');
			self::$currentDateFormat = empty($format) ? WAIC_DATE_FORMAT : $format;
		}
		return self::$currentDateFormat;
	}
	public static function getCurrentDateTimeFormat() {
		return self::getCurrentDateFormat() . ' H:i';
	}
	public static function getCurrentDateTimeFormatDB() {
		return self::getCurrentDateFormatDB() . ' H:i';
	}
		
	public static function getJSDateFormat( $format = '' ) {
		if (empty($format)) {
			$format = self::getCurrentDateFormat();
		}
		return str_replace(array('Y', 'm', 'd'), array('yy', 'mm', 'dd'), $format);
	}
	public static function getJSTimeFormat( $format = 'H:i' ) {
		return str_replace(array('H', 'i'), array('HH', 'mm'), $format);
	}
	public static function getFormatedDateTime( $dt, $format = '' ) {
		if (empty($format)) {
			$format = self::getCurrentDateTimeFormat();
		}
		return gmdate($format, $dt);
	}
	public static function getFormatedDateTimeDB( $dt, $format = '' ) {
		if (empty($format)) {
			$format = self::getCurrentDateTimeFormatDB() . ':s';
		}
		return gmdate($format, $dt);
	}
	public static function getFirstDateMonthDB( $dt = false ) {
		$format = 'Y-m-01';
		if (false == $dt) {
			$data = new DateTime('now', self::getTimeZone());
			return $data->format($format);
		}
		return gmdate($format, $dt);
	}
	public static function getConvertedDate( $dt = false, $format = '' ) {
		if ('' === $format) {
			$format = self::getCurrentDateFormat();
		}
		if (false == $dt) {
			$data = new DateTime('now', self::getTimeZone());
			return $data->format($format);
		}
		return gmdate($format, $dt);
	}
	
	public static function addDays( $days, $format = '', $years = 0 ) {
		if ('' === $format) {
			$format = self::getCurrentDateTimeFormat();
		}
		$data = new DateTime('now', self::getTimeZone());
		$days = (int) $days;
		if (!empty($days)) {
			if ($days > 0) {
				$data->add(DateInterval::createFromDateString($days . ' days'));
			} else {
				$data->sub(DateInterval::createFromDateString(( $days * ( -1 ) ) . ' days'));
			}
		}
		$years = (int) $years;
		if (!empty($years)) {
			if ($years > 0) {
				$data->add(DateInterval::createFromDateString($years . ' years'));
			} else {
				$data->sub(DateInterval::createFromDateString(( $years * ( -1 ) ) . ' years'));
			}
		}
		return $format ? $data->format($format) : $data->format('U') + $data->format('Z');
	}
	public static function addInterval( $dt, $cnt, $units ) {
		// Y-m-d H:i
		// $units = minutes, hours, days
		$date = new DateTime($dt);
		$date->modify(( $cnt > 0 ? '+' : '-' ) . $cnt . ' ' . $units);
		return $date->format('Y-m-d H:i');
	}
	public static function getTimestamp() {
		$data = new DateTime('now', self::getTimeZone());
		// FIX: format('U') already returns Unix timestamp with timezone applied
		// Adding format('Z') causes double offset (e.g. GMT+7 becomes +14 hours)
		return (int) $data->format('U');
	}
	public static function getTimestampDB() {
		return self::getFormatedDateTime(self::getTimestamp(), 'Y-m-d H:i:s');
	}
	public static function getTimestampFrom( $dt ) {
		return self::checkDateTime($dt, self::getCurrentDateTimeFormatDB());
	}
	public static function checkDateTime( $dt, $format = '' ) {
		if (empty($format)) {
			$format = self::getCurrentDateTimeFormat();
		}
		$data = date_create_from_format($format, $dt, self::getTimeZone());
		return $data ? $data->format('U') + $data->format('Z') : false;
	}
	public static function checkDateTimeFormat( $dt, $format = '' ) {
		$date = DateTime::createFromFormat($format, $dt);
		return $date && $date->format($format) === $dt;
	}
	public static function convertDateFormat( $dt, $from = '', $to = 'Y-m-d' ) {
		if (empty($dt)) {
			return $dt;
		}
		if (empty($from)) {
			$from = self::getCurrentDateFormat();
		}
		if ($from == $to) {
			return $dt;
		}
		$dt = self::checkDateTime($dt, $from);
		return $dt ? self::getFormatedDateTime($dt, $to) : false;
	}
	public static function convertDateTimeFormat( $dt, $from = '', $to = '' ) {
		if (empty($dt)) {
			return $dt;
		}
		if (empty($from)) {
			$from = self::getCurrentDateTimeFormat();
		}
		if (empty($to)) {
			$to = self::getCurrentDateTimeFormatDB();
		}
		if ($from == $to) {
			return $dt;
		}
		$dt = self::checkDateTime($dt, $from);
		return $dt ? self::getFormatedDateTime($dt, $to) : false;
	}
	public static function convertDateTimeToFront( $dt ) {
		return self::convertDateTimeFormat($dt, self::getCurrentDateTimeFormatDB(), self::getCurrentDateTimeFormat());
	}
	public static function convertDateTimeToDB( $dt ) {
		return self::convertDateTimeFormat($dt);
	}
	public static function convertDateTimeToISO8601( $dt, $tz ) {
		$dt = DateTime::createFromFormat('Y-m-d H:i', $dt, $tz ? new DateTimeZone($tz) : self::getTimeZone());
		return $dt->format(DateTime::RFC3339);
	}
	
	public static function colourBrightness( $hex, $percent ) {
		// Work out if hash given
		$hash = '';
		if (stristr($hex, '#')) {
			$hex = str_replace('#', '', $hex);
			$hash = '#';
		}
		/// HEX TO RGB
		$rgb = array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
		//// CALCULATE
		for ($i = 0; $i < 3; $i++) {
			// See if brighter or darker
			if ($percent > 0) {
				// Lighter
				$rgb[$i] = round($rgb[$i] * $percent) + round(255 * ( 1 - $percent ));
			} else {
				// Darker
				$positivePercent = $percent - ( $percent * 2 );
				$rgb[$i] = round($rgb[$i] * ( 1 - $positivePercent )); // round($rgb[$i] * (1-$positivePercent));
			}
			// In case rounding up causes us to go to 256
			if ($rgb[$i] > 255) {
				$rgb[$i] = 255;
			}
		}
		//// RBG to Hex
		$hex = '';
		for ($i = 0; $i < 3; $i++) {
			// Convert the decimal digit to hex
			$hexDigit = dechex($rgb[$i]);
			// Add a leading zero if necessary
			if (strlen($hexDigit) == 1) {
				$hexDigit = '0' . $hexDigit;
			}
			// Append to the hex string
			$hex .= $hexDigit;
		}
		return $hash . $hex;
	}
	public static function isHPOS() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}
	public static function isWooCommercePluginActivated() {
		return class_exists( 'WooCommerce' );
	}
	public static function isExistMB() {
		return function_exists('mb_substr');
	}
	public static function mbstrlen( $s ) {
		return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
	}
	public static function mbsubstr( $s, $i, $l = null ) {
		return function_exists('mb_substr') ? mb_substr($s, $i, $l) : substr($s, $i, $l);
	}
	public static function mbstrrpos( $h, $n, $o = 0 ) {
		return function_exists('mb_strrpos') ? mb_strrpos($h, $n, $o) : strrpos($h, $n, $o);
	}
	public static function mbstrpos( $h, $n, $o = 0 ) {
		return function_exists('mb_strpos') ? mb_strpos($h, $n, $o) : strpos($h, $n, $o);
	}
	public static function getRealUserIp() {
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_GT_VIEWER_IP'])) {
			$ip = $_SERVER['HTTP_X_GT_VIEWER_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = $_SERVER['REMOTE_ADDR'];
		} else {
			 $ip = '127.0.0.1';
		}
		$part = explode(':', $ip);
		return $part[0];
	}
	public static function insertKeyValuePair( $arr, $key, $val, $after ) {
		$start = $arr;
		$end = array();
		$index = array_search($after, array_keys($arr));
		if (false !== $index) {
			$end = array_splice($arr, $index + 1);
			$start = array_splice($arr, 0, $index + 1);
		} 
		return array_merge($start, array($key => $val), $end);
	}
	public static function getCountWords( $text ) {
		$text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES)));
		$text = preg_replace('/[\n]+/', ' ', $text);
		$text = preg_replace('/[\s]+/', '@SEPARATOR@', $text);
		$text_array = explode('@SEPARATOR@', $text);
		$count = count($text_array);
		$last_key = end($text_array);
		if (empty($last_key)) {
			$count--;
		}
		return $count;
	}

	public static function checkEmptyApiKeys( $apiParams, &$error ) {
		switch ($apiParams['engine']) {
			case 'open-ai':
				if (empty($apiParams['api_key'])) {
					$error = __('API key for OpenAI is required', 'ai-copilot-content-generator');
					return false;
				}
				break;
			case 'gemini':
				if (empty($apiParams['gemini_api_key'])) {
					$error = __('API key for Gemini is required', 'ai-copilot-content-generator');
					return false;
				}
				break;
			case 'deep-seek':
				if (empty($apiParams['deep_seek_api_key'])) {
					$error = __('API key for DeepSeek is required', 'ai-copilot-content-generator');
					return false;
				}
				break;
			default:
				break;
		}

		return true;
	}
	public static function controlUploatedFile( $file, $extensions = array() ) {
		$result = '';
		if (isset($file['error']) && !empty($file['error']) && UPLOAD_ERR_OK !== $file['error']) {
			switch ($file['error']) {
				case UPLOAD_ERR_INI_SIZE:
				case UPLOAD_ERR_FORM_SIZE:
					$result = esc_html__('The uploaded file exceeds the max size of uploaded files.', 'ai-copilot-content-generator');
					break;
				case UPLOAD_ERR_PARTIAL:
					$result = esc_html__('The uploaded file was only partially uploaded.', 'ai-copilot-content-generator');
					break;
				case UPLOAD_ERR_NO_FILE:
					$result = esc_html__('No file was uploaded.', 'ai-copilot-content-generator');
					break;
				case UPLOAD_ERR_NO_TMP_DIR:
					$result = esc_html__('Missing a temporary folder.', 'ai-copilot-content-generator');
					break;
				case UPLOAD_ERR_CANT_WRITE:
					$result = esc_html__('Failed to write file to disk.', 'ai-copilot-content-generator');
					break;
				default:
					$result = esc_html__('Unexpected error.', 'ai-copilot-content-generator');
			}
		} else {
			$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			if (!in_array($extension, $extensions)) {
				$result = esc_html__('Unsupported file type', 'ai-copilot-content-generator');
			}
		}
		return $result;
	}
	public static function calcTokensByModel( $model, $text ) {
		waicImportClass('WaicTokinizerFactory', WAIC_HELPERS_DIR . 'tokinizerFactory.php');

		$enc = WaicTokinizerFactory::createByModelName($model);
		$tokens = $enc->encode($text);
		return count($tokens);
	}
	public static function calcTokensByEncoding( $encoding, $text ) {
		//https://platform.openai.com/tokenizer
		waicImportClass('WaicTokinizerFactory', WAIC_HELPERS_DIR . 'tokinizerFactory.php');

		$enc = WaicTokinizerFactory::createByEncodingName($encoding);
		$tokens = $enc->encode($text);
		return count($tokens);
	}
	public static function getObjectTaxonomiesList( $obj ) {
		$list = array();
		$taxonomies = get_object_taxonomies($obj, 'objects');

		foreach ($taxonomies as $taxonomy) {
			$list[$taxonomy->name] = $taxonomy->label;
		}
		return $list;
	}
	public static function getTaxonomyTermsList( $postId, $taxonomy ) {
		$list = '';
		$terms = get_the_terms( $postId, $taxonomy );
		if ( ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$list .= $term->name . ', ';
			}
			$list = substr($list, 0, -2);
		}
		return $list;
	}
	public static function markdownToHtml( $content ) {
		waicImportClass('Parsedown', WAIC_HELPERS_DIR . 'parsedown.php');
		$Parsedown = new Parsedown();
		$content = $Parsedown->text( $content );
		return $content;
	}
	public static function flattenJson($data, $prefix = '') {
		$result = array();

		foreach ($data as $key => $value) {
			$newKey = $prefix === '' ? $key : $prefix . '/' . $key;

			if (is_array($value) || is_object($value)) {
				$result += self::flattenJson((array) $value, $newKey);
			} else {
				$result[$newKey] = $value;
			}
		}
		return $result;
	}
	public static function arrayToXml($data, $root = 'request') {
		if (!class_exists('SimpleXMLElement')) {
			return '';
		}
		$xml = new SimpleXMLElement("<{$root}></{$root}>");

		$add = function($value, $key) use (&$xml) {
			if (is_array($value)) {
				$child = $xml->addChild($key);
				foreach ($value as $k => $v) {
					if (is_array($v)) {
						$subChild = $child->addChild(is_numeric($k) ? "item{$k}" : $k);
						foreach ($v as $subKey => $subVal) {
							$subChild->addChild(is_numeric($subKey) ? "item{$subKey}" : $subKey, htmlspecialchars((string)$subVal));
						}
					} else {
						$child->addChild(is_numeric($k) ? "item{$k}" : $k, htmlspecialchars((string)$v));
					}
				}
			} else {
				$xml->addChild($key, htmlspecialchars((string)$value));
			}
		};
		array_walk($data, $add);
		return $xml->asXML();
	}
	public static function responseToArray( $response ) {
		$body = wp_remote_retrieve_body($response);
		$contentType = wp_remote_retrieve_header($response, 'content-type');
		if (strpos($contentType, ';') !== false) {
			$contentType = explode(';', $contentType)[0];
		}

		$vars = array();

		switch (trim(strtolower($contentType))) {
			case 'application/json':
			case 'text/json':
				$vars = json_decode($body, true);
				break;
			case 'application/xml':
			case 'text/xml':
				libxml_use_internal_errors(true);
				$xml = simplexml_load_string($body);
				if ($xml !== false) {
					$vars = json_decode(json_encode($xml), true);
				}
				break;
			case 'application/x-www-form-urlencoded':
				parse_str($body, $vars);
				break;
			case 'text/plain':
			case 'text/html':
				$lines = explode("\n", $body);
				foreach ($lines as $line) {
					if (strpos($line, '=') !== false) {
						[$key, $value] = explode('=', $line, 2);
						$vars[trim($key)] = trim($value);
					}
				}
				break;
			default:
				$vars = ['raw' => $body];
				break;
		}
		return $vars;
	}
}
