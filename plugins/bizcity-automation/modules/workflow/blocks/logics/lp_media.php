<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicLogic_lp_media extends WaicLogic {
	protected $_code = 'lp_media';
	protected $_subtype = 3;
	protected $_order = 9;
	
	public function __construct( $block = null ) {
		$this->_name = __('Search Media', 'ai-copilot-content-generator');
		$this->_desc = __('Repeat actions for multiple Media', 'ai-copilot-content-generator');
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
			'title' => array(
				'type' => 'input',
				'label' => __('Title contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'input',
				'label' => __('Description contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'excerpt' => array(
				'type' => 'input',
				'label' => __('Caption contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'date_mode' => array(
				'type' => 'select',
				'label' => __('Media Date', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => '',
					'after' => __('After', 'ai-copilot-content-generator'),
					'before' => __('Before', 'ai-copilot-content-generator'),
					'last' => __('Last', 'ai-copilot-content-generator'),
				),
			),
			'date' => array(
				'type' => 'date',
				'label' => __('Select date & time', 'ai-copilot-content-generator'),
				'default' => '',
				'show' => array('date_mode' => array('after', 'before')),
				'add' => array('time'),
			),
			'time' => array(
				'type' => 'time',
				'label' => '',
				'default' => '00:00',
				'show' => array('date_mode' => array('after', 'before')),
				'inner' => true,
			),
			'period' => array(
				'type' => 'number',
				'label' => __('Select period', 'ai-copilot-content-generator'),
				'default' => '7',
				'show' => array('date_mode' => array('last')),
				'add' => array('units'),
			),
			'units' => array(
				'type' => 'select',
				'label' => '',
				'default' => 'days',
				'options' => array('days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes'),
				'inner' => true,
			),
			'alt' => array(
				'type' => 'input',
				'label' => __('Alt Text contains', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'mime' => array(
				'type' => 'multiple',
				'label' => __('Mime type', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getMimeTypeList(),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Media Author', 'ai-copilot-content-generator'),
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
			'count_steps' => __('Total Number of Media', 'ai-copilot-content-generator'),
			'count_errors' => __('Number of Errors', 'ai-copilot-content-generator'),
			'count_success' => __('Number of Successful Steps', 'ai-copilot-content-generator'),
			'loop_vars' => array_merge(array('step' => __('Step', 'ai-copilot-content-generator')), $this->getMediaVariables()),
		);
		return $this->_variables;
	}
	
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!empty($this->_results)) {
			return $this->_results;
		}
		$args = array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'posts_per_page' => -1,
			'fields' => 'ids',
		);
		$ids = $this->replaceVariables($this->getParam('ids'), $variables);
		if (!empty($ids)) {
			$args['post__in'] = $this->controlIdsArray(explode(',', $ids));
		}
		
		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			$args['author'] = $author;
		}
		
		$mimes = $this->getParam('mime', array(), 2);
		if (!empty($mimes)) {
			$args['post_mime_type'] = $mimes;
		}
		
		$needWhereSearch = false;
		$title = $this->replaceVariables($this->getParam('title'), $variables);
		if (!empty($title)) {
			$args['waic_post_title'] = $title;
			$needWhereSearch = true;
		}
		$content = $this->replaceVariables($this->getParam('content'), $variables);
		if (!empty($content)) {
			$args['waic_post_content'] = $body;
			$needWhereSearch = true;
		}
		$excerpt = $this->replaceVariables($this->getParam('excerpt'), $variables);
		if (!empty($excerpt)) {
			$args['waic_post_excerpt'] = $excerpt;
			$needWhereSearch = true;
		}
		$alt = $this->replaceVariables($this->getParam('alt'), $variables);
		if (!empty($alt)) {
			$args['waic_post_alt'] = $alt;
			add_filter('posts_join', array($this, 'addJoinToQuery'), 10, 2);
			$needWhereSearch = true;
		}
		if ($needWhereSearch) {
			add_filter('posts_where', array($this, 'addSearchByWhere'), 10, 2 );
		}
		
		$dateMode = $this->getParam('date_mode');
		if (!empty($dateMode)) {
			$args['date_query'] = array();
			if ('last' == $dateMode) {
				$period = $this->getParam('period', 0, 1);
				if (!empty($period)) {
					$args['date_query'] = array('after' => $period . ' ' . $this->getParam('units', 'days') . ' ago'); 
				}
			} else {
				$d = $this->getParam('date');
				$t = $this->getParam('time');
				if (!empty($d) && !empty($t)) {
					$args['date_query'] = array($dateMode => $d . ' ' . $t . ':00');
				}
			}
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
			$variables = $workflow->addMediaVariables($variables, $id);
		}
		return $variables;
	}
	public function addSearchByWhere( $where, $wp_query ) {
		global $wpdb;
		if (!empty($wp_query->get( 'waic_post_title' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_post_title' ) ) ) . '%\'';
		}
		if (!empty($wp_query->get( 'waic_post_content' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_content LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_post_content' ) ) ) . '%\'';
		}
		if (!empty($wp_query->get( 'waic_post_excerpt' ))) {
			$where .= ' AND ' . $wpdb->posts . '.post_excerpt LIKE \'%' . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_post_excerpt' ) ) ) . '%\'';
		}
		if (!empty($wp_query->get( 'waic_post_alt' ))) {
			$where .= " AND waic_altmeta.meta_value LIKE '%" . esc_sql( $wpdb->esc_like( $wp_query->get( 'waic_post_alt' ) ) ) . "%'";
		}
		return $where;
	}
	public function addJoinToQuery( $where, $wp_query ) {
		global $wpdb;
		$join .= ' LEFT JOIN ' . $wpdb->postmeta . ' AS waic_altmeta ON (' . $wpdb->posts . ".ID = waic_altmeta.post_id AND waic_altmeta.meta_key='_wp_attachment_image_alt')"; 
		return $join;
	}

}
