<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wc_new_order extends WaicTrigger {
	protected $_code = 'wc_new_order';
	protected $_hook = 'woocommerce_new_order';
	protected $_subtype = 2;
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('New Order Created', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$wordspace = WaicFrame::_()->getModule('workspace');
		$args = array(
			'parent' => 0,
			'hide_empty' => 0,
			'orderby' => 'name',
			'order' => 'asc',
		);
		
		$this->_settings = array(
			'status' => array(
				'type' => 'multiple',
				'label' => __('Order Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getOrderStatuses(),
			),
			'total' => array(
				'type' => 'select',
				'label' => __('Order Total', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => __('Any amount', 'ai-copilot-content-generator'),
					'more' => __('More than', 'ai-copilot-content-generator'),
					'less' => __('Less than', 'ai-copilot-content-generator'),
					'between' => __('Between', 'ai-copilot-content-generator'),
				),
			),
			'total_value' => array(
				'type' => 'input',
				'label' => __('Value', 'ai-copilot-content-generator'),
				'default' => 0,
				'show' => array('total' => array('more', 'less')),
			),
			'total_from' => array(
				'type' => 'input',
				'label' => __('Range', 'ai-copilot-content-generator'),
				'default' => 0,
				'show' => array('total' => array('between')),
				'add' => array('total_to'),
			),
			'total_to' => array(
				'type' => 'input',
				'label' => '',
				'default' => 0,
				'show' => array('total' => array('between')),
				'inner' => true,
			),
			'exist_coupon' => array(
				'type' => 'select',
				'label' => __('Coupon Applied', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => __('', 'ai-copilot-content-generator'),
					'yes' => __('Yes', 'ai-copilot-content-generator'),
					'no' => __('No', 'ai-copilot-content-generator'),
				),
			),
			'coupon' => array(
				'type' => 'select',
				'label' => __('Coupons', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), $this->getCouponList()),
				'show' => array('exist_coupon' => array('yes', 'no')),
			),
			'customer' => array(
				'type' => 'select',
				'label' => __('Customer Type', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => __('Any customer', 'ai-copilot-content-generator'),
					'guest' => __('Guest', 'ai-copilot-content-generator'),
					'registered' => __('Registered user', 'ai-copilot-content-generator'),
					'first' => __('First-time customer', 'ai-copilot-content-generator'),
					'return' => __('Returning customer', 'ai-copilot-content-generator'),
				),
			),
			'products' => array(
				'type' => 'input',
				'label' => __('Products contains (ids)', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'categories' => array(
				'type' => 'multiple',
				'label' => __('Categories contains', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_cat', $args),
			),
			'tags' => array(
				'type' => 'multiple',
				'label' => __('Tags contains', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_tag', $args),
			),
		);
	}
	
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = $this->_variables = array_merge(
			$this->getDTVariables(), 
			$this->getOrderVariables(),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 1) {
			return false;
		}
		
		$orderId = $args[0];
		$order = wc_get_order($orderId);
		$userId = $order->get_customer_id();

		$statuses = $this->getParam('status', array(), 2);
		if (!empty($statuses)) {
			if (!in_array($order->get_status(), $statuses)) {
				return false;
			}
		}
		$existCoupon = $this->getParam('exist_coupon');
		if (!empty($existCoupon)) {
			$coupon = urldecode($this->getParam('coupon'));
			$codes = $order->get_coupon_codes();
			if ('no' == $existCoupon) {
				if (empty($coupon) && !empty($codes)) {
					return false;
				}
				if (!empty($coupon) && in_array($coupon, $codes)) {
					return false;
				}
			} else {
				if (empty($coupon) && empty($codes)) {
					return false;
				}
				if (!empty($coupon) && !in_array($coupon, $codes)) {
					return false;
				}
			}
		}
		
		$totalMode = $this->getParam('total');
		if (!empty($totalMode)) {
			$total = (float) $order->get_total();
			if ('between' == $totalMode) {
				$totalFrom = $this->getParam('total_from', 0, 1);
				$totalTo = $this->getParam('total_to', 0, 1);
				if ($total < $totalFrom || $total > $totalTo) {
					return false;
				}
			} else {
				$totalValue = $this->getParam('total_value', 0, 1);
				if ('more' == $totalMode && $total <= $totalValue) {
					return false;
				}
				if ('less' == $totalMode && $total >= $totalValue) {
					return false;
				}
			}
		}
		$customerMode = $this->getParam('customer');
		if (!empty($customerMode)) {
			if ('guest' == $customerMode) {
				if ($userId) {
					return false;
				}
			} else if ('registered' == $customerMode) {
				if (!$userId) {
					return false;
				}
			} else {
				$args = array(
					'limit' => -1,
					'return' => 'ids',
					'status' => array('completed', 'wc-completed'),
					'exclude' => array($orderId),
				);
				if ($userId) {
					$args['customer_id'] = $userId;
				} else {
					$args['billing_email'] = $order->get_billing_email();
				}
				$orderIds = wc_get_orders($args);
				$orderCount = count($orderIds);
				if ('first' == $customerMode && $orderCount > 0) {
					return false;
				} else if ('return' == $customerMode && $orderCount == 0) {
					return false;
				}
			}
		}
		$products = $this->getParam('products');
		if (!empty($products)) {
			$products = $this->controlIdsArray(explode(',', $products));
			if (!$this->isInOrderProducts($order, $products)) {
				return false;
			}
		}
		$categories = $this->controlIdsArray($this->getParam('categories', array(), 2));
		if (!empty($categories)) {
			if (!$this->isInOrderTaxonomy($order, 'product_cat', $categories)) {
				return false;
			}
		}
		$tags = $this->controlIdsArray($this->getParam('tags', array(), 2));
		if (!empty($tags)) {
			if (!$this->isInOrderTaxonomy($order, 'product_tag', $tags)) {
				return false;
			}
		}
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_order_id' => $orderId, 'waic_user_id' => $userId, 'obj_id' => $orderId);
	}
}
