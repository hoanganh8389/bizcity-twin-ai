<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicDate {
	public static function _( $time = null ) {
		if (is_null($time)) {
			$time = time();
		}
		return gmdate(WAIC_DATE_FORMAT_HIS, $time);
	}
}
