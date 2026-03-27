<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Class to adapt field before display and save/get DB
 * return ONLY htmlParams property
 *
 * @see field
 */
class WaicFieldAdapter {
	const DB = 'WaicDb';
	const HTML = 'WaicHtml';
	const STR = 'str';
	public static $userfieldDest = array('registration', 'shipping', 'billing');
	public static $countries = array();
	public static $states = array();
	/**
	 * Executes field Adaption process
	 *
	 * @param object type field or value $fieldOrValue if DB adaption - this must be a value of field, elase if html - field object
	 */
	public static function _( $fieldOrValue, $method, $type ) {
		if (method_exists('WaicFieldAdapter', $method)) {
			switch ($type) {
				case self::DB:
					return self::$method($fieldOrValue);
					break;
				case self::HTML:
					self::$method($fieldOrValue);
					break;
				case self::STR:
					return self::$method($fieldOrValue);
					break;
			}
		}
		return $fieldOrValue;
	}
	public static function intToDB( $val ) {
		return intval($val);
	}
	public static function floatToDB( $val ) {
		return floatval($val);
	}
	public static function userFieldDestToDB( $value ) {
		return WaicUtils::jsonEncode($value);
	}
	public static function userFieldDestFromDB( $value ) {
		return WaicUtils::jsonDecode($value);
	}
}
