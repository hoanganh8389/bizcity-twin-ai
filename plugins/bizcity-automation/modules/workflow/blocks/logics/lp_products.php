<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_lp_products extends WaicLogic {
	protected $_code = 'lp_products';
	protected $_subtype = 3;
	protected $_order = 20;
	
	public function __construct( $block = null ) {
		$this->_name = __('Search Products', 'ai-copilot-content-generator');
		$this->_desc = __('Repeat actions for multiple products', 'ai-copilot-content-generator');
		$this->_sublabel = array('name');
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
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'ids' => array(
				'type' => 'input',
				'label' => __('Ids separated with commas', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'type' => array(
				'type' => 'select',
				'label' => __('Product Types', 'ai-copilot-content-generator'),
				'default' => 'product',
				'options' => array_merge($this->getProductTypesList(), array('' => __('Any type', 'ai-copilot-content-generator'))),
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Product Name contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'desc' => array(
				'type' => 'input',
				'label' => __('Description contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'short_desc' => array(
				'type' => 'input',
				'label' => __('Short Description contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'price_mode' => array(
				'type' => 'select',
				'label' => __('Product Price', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => '',
					'_regular_price' => __('Regular Price', 'ai-copilot-content-generator'),
					'_sale_price' => __('Sale Price', 'ai-copilot-content-generator'),
					'_price' => __('Current Price', 'ai-copilot-content-generator'),
				),
			),
			'price_from' => array(
				'type' => 'number',
				'label' => __('Price Range', 'ai-copilot-content-generator'),
				'default' => 0,
				'step' => '0.01',
				'min' => 0,
				'show' => array('price_mode' => array('_regular_price', '_sale_price', '_price')),
				'add' => array('price_to'),
			),
			'price_to' => array(
				'type' => 'number',
				'label' => '',
				'default' => 0,
				'step' => '0.01',
				'min' => 0,
				'show' => array('price_mode' => array('_regular_price', '_sale_price', '_price')),
				'inner' => true,
			),
			'stock_status' => array(
				'type' => 'multiple',
				'label' => __('Product Stock Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getStockStatusesList(),
			),
			
			'categories' => array(
				'type' => 'multiple',
				'label' => __('Product Categories', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_cat', $args),
			),
			'tags' => array(
				'type' => 'multiple',
				'label' => __('Product Tags', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_tag', $args),
			),
			'status' => array(
				'type' => 'multiple',
				'label' => __('Publishing Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => get_post_statuses(),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Created by', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getUsersList(array(0 => '')),
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
		$this->_variables = array(
			'count_steps' => __('Total Number of Products', 'ai-copilot-content-generator'),
			'count_errors' => __('Number of Errors', 'ai-copilot-content-generator'),
			'count_success' => __('Number of Successful Steps', 'ai-copilot-content-generator'),
			'loop_vars' => array_merge(array('step' => __('Step', 'ai-copilot-content-generator')), $this->getProductVariables()),
		);
		return $this->_variables;
	}
	
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!empty($this->_results)) {
			return $this->_results;
		}
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		
		$postType = array('product', 'product_variation');
		$prType = $this->getParam('type');

		if (!empty($prType)) {
			$postType = $prType;
		}
		$statuses = $this->getParam('status', array(), 2);
		if (empty($statuses)) {
			$statuses = 'any';
		}
		
		$args = array(
			'post_type' => $postType,
			'post_status' => $statuses,
			'ignore_sticky_posts' => true,
			'posts_per_page' => -1,
			'fields' => 'ids',
			'tax_query' => array(),
			'meta_query' => array(),
		);

		$ids = $this->replaceVariables($this->getParam('ids'), $variables);
		if (!empty($ids)) {
			$args['post__in'] = $this->controlIdsArray(explode(',', $ids));
		}
		
		$needWhereSearch = false;
		$title = $this->replaceVariables($this->getParam('title'), $variables);
		if (!empty($title)) {
			$args['waic_product_title'] = $title;
			$needWhereSearch = true;
		}
		$desc = $this->replaceVariables($this->getParam('desc'), $variables);
		if (!empty($desc)) {
			$args['waic_product_desc'] = $desc;
			$needWhereSearch = true;
		}
		$shortDesc = $this->replaceVariables($this->getParam('short_desc'), $variables);
		if (!empty($shortDesc)) {
			$args['waic_product_short_desc'] = $shortDesc;
			$needWhereSearch = true;
		}
		
		if ($needWhereSearch) {
			add_filter('posts_where', array($this, 'addSearchByWhere'), 10, 2 );
		}
		$priceMode = $this->getParam('price_mode');
		if (!empty($priceMode)) {
			$args['meta_query'][] = array(
				'key' => $priceMode,
				'compare' => 'BETWEEN',
				'type' => 'NUMERIC',
				'value' => array((float) $this->getParam('price_from', 0), (float) $this->getParam('price_to', 0)),
			);
		}
		$stockStatus = $this->getParam('stock_status', array(), 2);
		if (!empty($stockStatus)) {
			$args['meta_query'][] = array(
				'key' => '_stock_status',
				'value' => $stockStatus,
				'compare' => 'IN',
			);
		}
		$categories = $this->getParam('categories', array(), 2);
		if (!empty($categories)) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field' => 'term_id',
				'terms' => $categories,
				'operator' => 'IN',
				'include_children' => false,
			);
		}
		$tags = $this->getParam('tags', array(), 2);
		if (!empty($tags)) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_tag',
				'field' => 'term_id',
				'terms' => $tags,
				'operator' => 'IN',
				'include_children' => false,
			);
		}

		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			$args['author'] = $author;
		}
		
		if (!empty($args['meta_query'])) {
			$args['meta_query']['relation'] = 'AND'; 
		}
		if (!empty($args['tax_query'])) {
			$args['tax_query']['relation'] = 'AND'; 
		}
		$result = new WP_Query($args);
		$cnt = 0;
		$loopIds = array();
		if ($result->have_posts()) {
			$cnt = $result->found_posts;
			$loopIds = $result->posts;
		}
		wp_reset_query();
		
		$this->_results = array(
			'result' => array(
				'loop' => $loopIds,
				'count_steps' => $cnt,
				'count_errors' => 0,
				'count_success' => 0,
			),
			'error' => '',
			'status' => 3,
			'cnt' => $cnt,
			'sourceHandle' => ( $cnt > 0 ? 'output-then' : 'output-else' ),
		);
		return $this->_results;
	}
	
	public function addLoopVariables( $step, $workflow ) {
		if (!isset($this->_results['result'])) {
			return array();
		}
		
		$result = $this->_results['result'];
		$variables = $result;
		$variables['step'] = $step;
		if (empty($step)) {
			return $variables;
		}
		
		$id = WaicUtils::getArrayValue(WaicUtils::getArrayValue($result, 'loop', array(), 2), ( $step - 1 ), 0, 1);
		if (!empty($id)) {
			$variables = $workflow->addProductVariables($variables, $id);
		}
		return $variables;
	}
	public function addSearchByWhere( $where, $wp_query ) {
		global $wpdb;
		if (!empty($wp_query->get( 'waic_product_title' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_product_title' ) ) ) . '%\'';
		}
		if (!empty($wp_query->get( 'waic_product_desc' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_content LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_product_desc' ) ) ) . '%\'';
		}
		if (!empty($wp_query->get( 'waic_product_short_desc' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_excerpt LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_product_short_desc' ) ) ) . '%\'';
		}
		return $where;
	}

}
