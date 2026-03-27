<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicUri {
	/**
	 * Tell link form method to replace symbols for special html caracters only for ONE output
	 */
	private static $_oneHtmlEnc = false;
	
	public static function getPluginLink() {
		return 'https://bizcity.vn' ;
	}
	public static function generatePluginLink( $params = '' ) {
		$mainLink = self::getPluginLink();
		if (!empty($params)) {
			return $mainLink . ( strpos($mainLink , '?') ? '&' : '?' ) . $params;
		}
		return $mainLink;
	}

	public static function fileToPageParam( $file ) {
		$file = str_replace(WAIC_DS, '/', $file);
		return substr($file, strpos($file, WAIC_PLUG_NAME));
	}
	public static function _( $params ) {
		global $wp_rewrite;
		$link = '';
		if ( is_string($params) && ( strpos($params, 'http') === 0 || strpos($params, WAIC_PLUG_NAME) !== false ) ) {
			if (self::isHttps()) {
				$params = self::makeHttps($params);
			}
			return $params;
		} elseif (is_array($params) && isset($params['page_id'])) {
			$link = get_page_link($params['page_id']);
			unset($params['page_id']);
		} elseif (is_array($params) && isset($params['baseUrl'])) {
			$link = $params['baseUrl'];
			unset($params['baseUrl']);
		} else {
			$link = WAIC_URL;
		}
		if (!empty($params)) {
			$query = is_array($params) ? http_build_query($params, '', '&') : $params;
			$link .= ( strpos($link, '?') === false ? '?' : '&' ) . $query;
		}
		if (self::$_oneHtmlEnc) {
			$link = str_replace('&', '&amp;', $link);
			self::$_oneHtmlEnc = false;
		}
		return $link;
	}
	public static function page( $id ) {
		return get_page_link($id);
	}
	public static function mod( $name, $action = '', $data = null ) {
		$params = array('mod' => $name);
		if ($action) {
			$params['action'] = $action;
		}
		$params['pl'] = WAIC_CODE;
		if ($data) {
			if (is_array($data)) {
				$params = array_merge($params, $data);
				if ( isset($data['reqType']) && ( 'ajax' == $data['reqType'] ) ) {
					$params['baseUrl'] = admin_url('admin-ajax.php');
				}
			} elseif (is_string($data)) {
				$params = http_build_query($params);
				$params .= '&' . $data;
			}
		}
		return self::_($params);
	}
	/**
	 * Get current path
	 *
	 * @return string current link
	 */
	public static function getCurrent() {
		$url = ( empty($_SERVER['HTTP_HOST']) ? '' : sanitize_text_field($_SERVER['HTTP_HOST']) ) . ( empty($_SERVER['SCRIPT_NAME']) ? '' : sanitize_text_field($_SERVER['SCRIPT_NAME']) );
		if (!empty($_SERVER['HTTPS'])) {
			return 'https://' . $url;
		} else {
			return 'http://' . $url;
		}
	}
	public static function getFullUrl() {
		$url = isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';
		$url .= ( empty($_SERVER['HTTP_HOST']) ? '' : sanitize_text_field($_SERVER['HTTP_HOST']) ) . ( empty($_SERVER['REQUEST_URI']) ? '' : sanitize_text_field($_SERVER['REQUEST_URI']) );
		return $url;
	}
	/**
	 * Replace symbols to special html caracters in one output
	 */
	public static function oneHtmlEnc() {
		self::$_oneHtmlEnc = true;
	}
	public static function makeHttps( $link ) {
		if (strpos($link, 'https:') === false) {
			$link = str_replace('http:', 'https:', $link);
		}
		return $link;
	}
	public static function isHttps() {
		return is_ssl();
	}
	/**
	 * If url is without http:// - just domain name for example - we will add it here
	 *
	 * @param string $url Url to check
	 * @return string Checked and corrected URL (if this will be required)
	 */
	public static function normal( $url ) {
		$url = trim($url);
		if (strpos($url, 'http') !== 0) {
			$url = 'http://' . $url;
		}
		return $url;
	}
}
