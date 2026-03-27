<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicBuilderBlock extends WaicBaseObject {
	protected $_id = 0;
	protected $_type = '';
	protected $_subtype = 0; /* suptype for types*/
	protected $_code = '';
	protected $_name = '';
	protected $_desc = '';
	protected $_category = null;
	protected $_settings = array();
	protected $_sublabel = array();
	protected $_params = null;
	protected $_variables = array();
	protected $_results = array();
	protected $_builder = array();
	protected $_block = false;
	protected $_order = 0;
	protected $_dtVars = false;
	protected $_userVars = false;
	protected $_postVars = false;
	protected $_pageVars = false;
	protected $_orderVars = false;
	protected $_productVars = false;
	protected $_commentVars = false;
	protected $_mediaVars = false;
	protected $_flowVars = false;
	
	protected $_runId = 0;
	protected $_flowId = 0;
	protected $_taskId = 0;
	
	public function getCode() {
		return $this->_code;
	}
	public function getType() {
		return $this->_type;
	}
	public function getSubtype() {
		return $this->_subtype;
	}
	public function getName() {
		return $this->_name;
	}
	public function getDesc() {
		return $this->_desc;
	}
	public function getSettings() {
		return $this->_settings;
	}
	public function getSublabel() {
		return $this->_sublabel;
	}
	public function getOrder() {
		return $this->_order;
	}
	public function getVariables() {
		return $this->_variables;
	}
	public function getResults( $t, $v, $s = 0 ) {
		return $this->_results;
	}
	public function setBlock( $block ) {
		$this->_block = $block;
		$this->_id = $block ? WaicUtils::getArrayValue($block, 'id', 0, 1) : 0;
	}
	public function getId() {
		return $this->_id;
	}
	public function setRunId( $id ) {
		$this->_runId = $id;
	}
	public function setFlowId( $id ) {
		$this->_flowId = $id;
	}
	public function setTaskId( $id ) {
		$this->_taskId = $id;
	}
	public function getParams() {
		if (is_null($this->_params)) {
			$this->_params = $this->_block ? WaicUtils::getArrayValue(WaicUtils::getArrayValue($this->_block, 'data', array(), 2), 'settings', array(), 2) : array();
		}
		return $this->_params;
	}
	public function getParam( $key, $def = '', $typ = 0, $zero = false, $leer = false ) {
		$params = $this->getParams();
		return WaicUtils::getArrayValue($this->getParams(), $key, $def, $typ, false, $zero, $leer); 
	}
	public function replaceVariables( $str, $variables ) {
		preg_match_all('/\{\{(.*?)\}\}/', $str, $matches);
		if (empty($matches[1])) {
			return $str;
		}
		
		foreach ($matches[1] as $var) {
			$replace = false;
			$parts = explode('.', $var);
			if (count($parts) == 2) {
				$node = $parts[0];// . '#' . $step;
				$variable = $parts[1];
				
				if (isset($variables[$node]) && isset($variables[$node][$variable])) {
					$replace = $variables[$node][$variable];
					if (is_array($replace)) {
						$replace = implode(',', $replace);
					}
				}
			}
			
			$str = str_replace('{{' . $var . '}}', $replace, $str);
		}
		return $str;
	}
	public function controlIdsArray( $ids ) {
		if (!empty($ids) && is_array($ids)) {
			foreach ($ids as $k => $id) {
				$ids[$k] = (int) $id;
			}
		}
		return $ids;
	}
	public function getDTVariables() {
		if (empty($this->_dtVars)) {
			$this->_dtVars = array(
				'date' => __('Date', 'ai-copilot-content-generator'),
				'time' => __('Time', 'ai-copilot-content-generator'),
			);
		}
		return $this->_dtVars;
	}
	public function getPostVariables() {
		if (empty($this->_postVars)) {
			$this->_postVars = array(
				'post_ID' => __('Post ID', 'ai-copilot-content-generator'),
				'post_type' => __('Post Type', 'ai-copilot-content-generator'),
				'post_title' => __('Post Title', 'ai-copilot-content-generator'),
				'post_status' => __('Post Status', 'ai-copilot-content-generator'),
				'post_content' => __('Post Content', 'ai-copilot-content-generator'),
				'post_excerpt' => __('Post Excerpt', 'ai-copilot-content-generator'),
				'post_permalink' => __('Post Permalink', 'ai-copilot-content-generator'),
				'post_image' => __('Post Featured Image', 'ai-copilot-content-generator'),
				'post_categories' => __('Post Categories', 'ai-copilot-content-generator'),
				'post_tags' => __('Post Tags', 'ai-copilot-content-generator'),
				'post_date' => __('Post Date', 'ai-copilot-content-generator'),
				'post_modified' => __('Post Modified', 'ai-copilot-content-generator'),
				'post_meta' => __('Post Meta Key *', 'ai-copilot-content-generator'),
			);
		}
		return $this->_postVars;
	}
	
	public function getPageVariables() {
		if (empty($this->_pageVars)) {
			$this->_pageVars = array(
				'page_ID' => __('Page ID', 'ai-copilot-content-generator'),
				'page_title' => __('Page Title', 'ai-copilot-content-generator'),
				'page_status' => __('Page Status', 'ai-copilot-content-generator'),
				'page_content' => __('Page Content', 'ai-copilot-content-generator'),
				'page_excerpt' => __('Page Excerpt', 'ai-copilot-content-generator'),
				'page_permalink' => __('Page Permalink', 'ai-copilot-content-generator'),
				'page_image' => __('Page Featured Image', 'ai-copilot-content-generator'),
				'page_comment' => __('Discussion', 'ai-copilot-content-generator'),
				'page_date' => __('Page Created', 'ai-copilot-content-generator'),
				'page_modified' => __('Page Modified', 'ai-copilot-content-generator'),
			);
		}
		return $this->_pageVars;
	}

	public function getUserVariables() {
		if (empty($this->_userVars)) {
			$this->_userVars = array(
				'user_ID' => __('User ID', 'ai-copilot-content-generator'),
				'user_login' => __('User Login', 'ai-copilot-content-generator'),
				'user_email' => __('User Email', 'ai-copilot-content-generator'),
				'user_url' => __('User Url', 'ai-copilot-content-generator'),
				'user_nicename' => __('User Nicename', 'ai-copilot-content-generator'),
				'display_name' => __('Display Name', 'ai-copilot-content-generator'),
				'user_registered' => __('User Registered', 'ai-copilot-content-generator'),
				'user_roles' => __('User Roles', 'ai-copilot-content-generator'),
				'user_caps' => __('User Сapabilities', 'ai-copilot-content-generator'),
				'user_meta' => __('User Meta Key *', 'ai-copilot-content-generator'),
			);
		}
		return $this->_userVars;
	}
	public function getOrderVariables() {
		if (empty($this->_orderVars)) {
			$this->_orderVars = array(
				'order_ID' => __('Order ID', 'ai-copilot-content-generator'),
				'order_status' => __('Order Status', 'ai-copilot-content-generator'),
				'order_currency' => __('Order Currency', 'ai-copilot-content-generator'),
				'order_total' => __('Order Total', 'ai-copilot-content-generator'),
				'order_subtotal' => __('Order Subtotal', 'ai-copilot-content-generator'),
				'order_coupons' => __('Order Coupons (codes)', 'ai-copilot-content-generator'),
				'order_discount_total' => __('Order Discount Total', 'ai-copilot-content-generator'),
				'order_shipping_total' => __('Order Shipping Total', 'ai-copilot-content-generator'),
				'order_item_count' => __('Item Count', 'ai-copilot-content-generator'),
				'order_products' => __('Order Products (ids)', 'ai-copilot-content-generator'),
				'order_categories' => __('Order Categories (ids)', 'ai-copilot-content-generator'),
				'order_tags' => __('Order Tags (ids)', 'ai-copilot-content-generator'),
				'order_shipping_method' => __('Shipping Method', 'ai-copilot-content-generator'),
				'order_payment_method' => __('Order Payment Method', 'ai-copilot-content-generator'),
				'order_date_created' => __('Order Date Created', 'ai-copilot-content-generator'),
				'order_date_paid' => __('Order Date Paid', 'ai-copilot-content-generator'),
				'order_date_completed' => __('Order Date Completed', 'ai-copilot-content-generator'),
				'customer_id' => __('Customer ID', 'ai-copilot-content-generator'),
				'billing_email' => __('Billing Email', 'ai-copilot-content-generator'),
				'billing_first_name' => __('Billing First Name', 'ai-copilot-content-generator'),
				'billing_last_name' => __('Billing Last Name', 'ai-copilot-content-generator'),
			);
		}
		return $this->_orderVars;
	}
	public function getProductVariables() {
		if (empty($this->_productVars)) {
			$this->_productVars = array(
				'prod_ID' => __('Product ID', 'ai-copilot-content-generator'),
				'prod_name' => __('Product Name', 'ai-copilot-content-generator'),
				'prod_sku' => __('Product SKU', 'ai-copilot-content-generator'),
				'prod_type' => __('Product Type', 'ai-copilot-content-generator'),
				'prod_parent' => __('Product Parent (id)', 'ai-copilot-content-generator'),
				'prod_status' => __('Product Status', 'ai-copilot-content-generator'),
				'prod_desc' => __('Product Description', 'ai-copilot-content-generator'),
				'prod_short_desc' => __('Product Short Description', 'ai-copilot-content-generator'),
				'prod_permalink' => __('Product Permalink', 'ai-copilot-content-generator'),
				'prod_image_main' => __('Product Main Image (id)', 'ai-copilot-content-generator'),
				'prod_gallery_images' => __('Product Gallery Images (ids)', 'ai-copilot-content-generator'),
				'prod_categories' => __('Product Categories', 'ai-copilot-content-generator'),
				'prod_tags' => __('Product Tags', 'ai-copilot-content-generator'),
				'prod_created' => __('Product Created', 'ai-copilot-content-generator'),
				'prod_modified' => __('Product Modified', 'ai-copilot-content-generator'),
				'prod_rating' => __('Product Rating', 'ai-copilot-content-generator'),
				'prod_price' => __('Product Price', 'ai-copilot-content-generator'),
				'prod_regular_price' => __('Product Regular Price', 'ai-copilot-content-generator'),
				'prod_sale_price' => __('Product Sale Price', 'ai-copilot-content-generator'),
				'prod_stock_status' => __('Product Stock Status', 'ai-copilot-content-generator'),
				'prod_stock_quantity' => __('Product Stock Quantity', 'ai-copilot-content-generator'),
				'prod_attr' => __('Product Attributes *', 'ai-copilot-content-generator'),
				'prod_meta' => __('Product Meta Key *', 'ai-copilot-content-generator'),
			);
		}
		return $this->_productVars;
	}
	public function getCommentVariables( $name = false, $obj = false ) {
		if (empty($this->_commentVars)) {
			if (!$name) {
				$name = __('Comment', 'ai-copilot-content-generator');
			} 
			$name .= ' ';
			if (!$obj) {
				$obj = __('Post', 'ai-copilot-content-generator');
			} 
			$this->_commentVars = array(
				'com_ID' => $name . __('ID', 'ai-copilot-content-generator'),
				'com_post_ID' => $name . $obj . ' ' . __('ID', 'ai-copilot-content-generator'),
				'com_user_id' => $name . __('User (id)', 'ai-copilot-content-generator'),
				'com_type' => $name . __('Type', 'ai-copilot-content-generator'),
				'com_date' => $name . __('Date', 'ai-copilot-content-generator'),
				'com_content' => $name . __('Content', 'ai-copilot-content-generator'),
				'com_status' => $name . __('Status', 'ai-copilot-content-generator'),
				'com_rating' => $name . __('Rating', 'ai-copilot-content-generator'),
			);
		}
		return $this->_commentVars;
	}
	public function getMediaVariables() {
		if (empty($this->_mediaVars)) {
			$this->_mediaVars = array(
				'media_ID' => __('Media ID', 'ai-copilot-content-generator'),
				'media_title' => __('Media Title', 'ai-copilot-content-generator'),
				'media_content' => __('Media Description', 'ai-copilot-content-generator'),
				'media_excerpt' => __('Media Caption', 'ai-copilot-content-generator'),
				'media_alt' => __('Alternative Text', 'ai-copilot-content-generator'),
				'media_type' => __('Media Mime Type', 'ai-copilot-content-generator'),
				'media_permalink' => __('Media Permalink', 'ai-copilot-content-generator'),
				'media_date' => __('Media Created', 'ai-copilot-content-generator'),
				'media_modified' => __('Media Modified', 'ai-copilot-content-generator'),
			);
		}
		return $this->_mediaVars;
	}
	public function getWorkflowVariables() {
		if (empty($this->_flowVars)) {
			$this->_flowVars = array(
				'flow_id' => __('Workflow ID', 'ai-copilot-content-generator'),
				'flow_ver' => __('Workflow Version', 'ai-copilot-content-generator'),
				'flow_status_id' => __('Workflow Status ID', 'ai-copilot-content-generator'),
				'flow_status' => __('Workflow Status', 'ai-copilot-content-generator'),
				'run_ID' => __('Run ID', 'ai-copilot-content-generator'),
				'run_status_id' => __('Run Status ID', 'ai-copilot-content-generator'),
				'run_status' => __('Run Status', 'ai-copilot-content-generator'),
				'run_started' => __('Run Started', 'ai-copilot-content-generator'),
				'run_ended' => __('Run Ended', 'ai-copilot-content-generator'),
				'run_error' => __('Run Error', 'ai-copilot-content-generator'),
				'run_tokens' => __('Run Tokens', 'ai-copilot-content-generator'),
				'run_obj_id' => __('Run Object ID', 'ai-copilot-content-generator'),
				//'run_vars' => __('Run Variable *', 'ai-copilot-content-generator'),
			);
		}
		return $this->_flowVars;
	}

	public function getCouponList() {
		$coupons = get_posts(array(
			'posts_per_page'   => -1,
			'orderby'          => 'name',
			'order'            => 'asc',
			'post_type'        => 'shop_coupon',
			'post_status'      => 'publish',
		));

		$lists = array(); 

		foreach ($coupons as $coupon) {
			$lists[$coupon->post_title] = empty($coupon->post_excerpt) ? $coupon->post_title : $coupon->post_excerpt;
		}

		return $lists;
	}
	public function getRolesList() {
		global $wp_roles;
		$roles = array();
		foreach ($wp_roles->roles as $roleName => $roleData) {
			$roles[$roleName] = $roleName;
		}
		return $roles;
	}
	public function getCommentStatusesList() {
		return array(
			'unapproved' => __('Unapproved', 'ai-copilot-content-generator'),
			'approved' => __('Approved', 'ai-copilot-content-generator'),
			'spam' => __('Spam', 'ai-copilot-content-generator'),
			'trash' => __('Trash', 'ai-copilot-content-generator'),
		);
	}
	public function getProductTypesList() {
		return array(
			'product' => __('Product', 'ai-copilot-content-generator'),
			'product_variation' => __('Product variations', 'ai-copilot-content-generator'),
		);
			
		/*if (function_exists('wc_get_product_types')) {
			//$types = wc_get_product_types();
			$types['product_variation'] = __('Product variations', 'ai-copilot-content-generator');
		}*/
		return $types;
	}
	public function getStockStatusesList() {
		$list = array();
		if (function_exists('wc_get_product_stock_status_options')) {
			$list = wc_get_product_stock_status_options();
		}
		return $list;
	}
	public function calcExpression( $e ) {
		error_log('calcExpression='.$e);
		if (preg_match('/^[0-9\.\+\-\*\/\(\) ]+$/', $e)) {
			$result = eval("return $e;");
			return $result;
		} 
		return false;
	}
	public static function getOrderStatuses() {
		$cleaned = array();
		if (function_exists('wc_get_order_statuses')) {
			$statuses = wc_get_order_statuses();
			foreach ($statuses as $key => $label) {
				$cleaned[str_replace('wc-', '', $key)] = $label;
			}
		}
		return $cleaned;
	}
	public function getFieldsArray( $fields, $field, $result ) {
		if (is_array($fields) && !empty($fields)) {
			foreach ($fields as $key => $value) {
				$result[$field . '[' . $key . ']'] = $value;
			}
		}
		return $result;
	}
	public function getTimeZones() {
		$timezones = DateTimeZone::listIdentifiers();
		$list = array();
		foreach ($timezones as $tz) {
			$list[$tz] = $tz;
		}
		return $list;
	}
	public function getDurationInMinutes( $cnt, $units ) {
		$cnt = (int) $cnt;
		if ('h' == $units) {
			$cnt *= 60;
		} else if ('d' == $units) {
			$cnt *= 1440;
		} 
		return $cnt;
	}
	public static function getMimeTypeList() {
		$types = wp_get_mime_types();
		$list = array();
		foreach ($types as $t) {
			$list[$t] = $t;
		}
		return $list;
	}
}
