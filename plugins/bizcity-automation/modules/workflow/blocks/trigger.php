<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicTrigger extends WaicBuilderBlock {
	protected $_schStart = null;
	protected $_period = 0; //sec
	protected $_hook = '';
	
	public function __construct() {
		$this->_type = 'trigger';
	}
	
	public function getSchStart() {
		return $this->_schStart;
	}
	public function getPeriod( $settings = array() ) {
		if (!empty($settings)) {
			$cooldown = WaicUtils::getArrayValue($settings, 'cooldown', 0, 1);
			$k = 86400; // one day
			if (!empty($cooldown)) {
				$units = WaicUtils::getArrayValue($settings, 'units');
				if ('m' == $units) {
					$k = 60; 
				} else if ('h' == $units) {
					$k = 3600; 
				}
			}
			$this->_period = $cooldown * $k; 
		}
		return $this->_period;
	}
	public function getHook() {
		return $this->_hook;
	}
	public function controlRun( $args = array() ) {
		return true;
	}
	public static function isInOrderProducts( $order, $products ) {
		if (!$order) {
			return false;
		}
		foreach ($order->get_items() as $item) {
			if (in_array($item->get_product_id(), $products)) {
				return true;
			}
		}
		return false;
	}
	public static function isInOrderTaxonomy( $order, $taxonomy, $termIds ) {
		if (!$order) {
			return false;
		}
		foreach ($order->get_items() as $item) {
			$prId = $item->get_product_id();
			$terms = get_the_terms($prId, $taxonomy);
			if (!empty($terms)) {
				foreach ($terms as $term) {
					if (in_array($term->term_id, $termIds)) {
						return true;
					}
				}
			}
        }
		return false;
	}
	public static function isInPostTaxonomy( $postId, $taxonomy, $termIds ) {
		if (!$postId) {
			return false;
		}
		$terms = get_the_terms($postId, $taxonomy);
		if (!empty($terms)) {
			foreach ($terms as $term) {
				if (in_array($term->term_id, $termIds)) {
					return true;
				}
			}
        }
		return false;
	}
}