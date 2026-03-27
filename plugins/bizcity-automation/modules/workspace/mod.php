<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkspace extends WaicModule {
	
	public function init() {
		#WaicDispatcher::addFilter('mainAdminTabs', array($this, 'addAdminTab'));
		add_action('waic_run_generation_task', array($this, 'doGenerationTask'), 10, 1);
		add_action('waic_run_delayed_actions', array($this, 'doDelayedActions'), 10, 1);
		add_action('waic_run_scheduled_task', array($this, 'doScheduledTasks'), 10, 1);
		add_filter('cron_schedules', array($this, 'addCronInterval'));
				
		if ( is_admin() ) {
			add_action('admin_notices', array($this, 'showAdminInfo'));
		}
		$this->runPreparedTask();
		$this->runSchedulededTask();
	}
	
	public function addCronInterval( $schedules ) {
		$schedules['waic_interval'] = array(
			'interval' => 60 * 15,
			'display'  => 'Every 15 minutes',
		);
		$schedules['waic_interval5'] = array(
			'interval' => 60 * 5,
			'display'  => 'Every 5 minutes',
		);
		$schedules['waic_interval1'] = array(
			'interval' => 60,
			'display'  => 'Every minute',
		);
		return $schedules;
	}
	
	public function showAdminInfo() {
		return $this->getView()->showAdminInfo();
	}
	
	public function addAdminTab( $tabs ) {
        // BizCity: hiển thị workspace menu
        $code = $this->getCode();
        $tabs[$code] = array(
            'label' => esc_html__('Workspace', 'ai-copilot-content-generator'),
            'callback' => array($this, 'showWorkspace'),
            'fa_icon' => 'fa-th-large',
            'sort_order' => 10,
            'add_bread' => $this->getCode()
        );
        return $tabs;
    }
	
	public function showWorkspace() {
		return $this->getView()->showWorkspace();
	}
	public function showHistory() {
		return $this->getView()->showHistory();
	}
	public function getWorkspaceTabsList( $current = '' ) {
		$tabs = array(
			'new' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Tạo mới', 'ai-copilot-content-generator'),
			),
			'history' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Kịch bản', 'ai-copilot-content-generator'),
			),
		);

		if (empty($current) || !isset($tabs[$current])) {
			reset($tabs);
			$current = key($tabs);
		}
		$tabs[$current]['class'] .= ' current';
		
		return WaicDispatcher::applyFilters('getWorkspaceTabsList', $tabs);
	}
	
	public function getWorkspaceFeatures() {
        // BizCity: workspace features
        $features = array(
            'workflow' => array(
                'title' => __('Workflow Builder', 'ai-copilot-content-generator'),
                'desc'  => __('Tạo tự động hoá bằng trình kéo-thả: trigger → AI → WordPress actions.', 'ai-copilot-content-generator'),
                'class' => 'wbw-ws-block-big',
                'fake'  => true,
                'hidden'=> true,
            ),
            'template' => array(
                'title'  => __('Workflow Template', 'ai-copilot-content-generator'),
                'desc'   => '',
                'fake'   => true,
                'hidden' => true,
            ),
            'mcp' => array(
                'title' => __('Model Context Protocol', 'ai-copilot-content-generator'),
                'desc'  => __('MCP Server để kết nối với AI clients (Claude Desktop, Cursor, v.v.)', 'ai-copilot-content-generator'),
                'class' => 'wbw-ws-block',
                'fake'  => false,
            ),
        );

        return $features;
    }
	public function getFeaturesList( $fake = true ) {
		$blocks = $this->getWorkspaceFeatures();
		$features = array();
		foreach ($blocks as $key => $block) {
			if ($fake || empty($block['fake'])) {
				$features[$key] = $block['title'];
			}
		}
		return $features;
	}
		
	public function getFeatureUrl( $feature = '', $cur = '' ) {
		static $mainUrl;
		if (empty($mainUrl)) {
			$mainUrl = WaicFrame::_()->getModule('adminmenu')->getMainLink();
		}
		$url = $mainUrl;
		if (!empty($feature)) {
			$url .= '&tab=' . $feature;
		}
		if (!empty($cur)) {
			$url .= '&cur=' . $cur;
		}
		// Propagate iframe mode across navigation
		if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
			$url .= '&bizcity_iframe=1';
		}
		return $url;
	}
	public function getTaskUrl( $taskId, $feature = '' ) {
		static $mainUrl;
		if (empty($mainUrl)) {
			$mainUrl = WaicFrame::_()->getModule('adminmenu')->getMainLink();
		}
		if (empty($feature)) {
			$feature = $this->getModel('tasks')->getTaskFeature($taskId);
			/*if ($task) {
				$feature = $task['feature'];
				$module = WaicFrame::_()->getModule($feature);
				if ($module) {
					return $module->showTaskTabContent($task);
				}
			}*/
		}
		/*if ('workflow' == $feature) {
			$feature = 'builder';
		}*/
		$url = $mainUrl . '&tab=' . ( empty($feature) ? $this->getCode() : $feature ) . ( empty($taskId) ? '' : '&task_id=' . $taskId );
		// Propagate iframe mode across navigation
		if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
			$url .= '&bizcity_iframe=1';
		}
		return $url;
	}
	/*public function getStopTaskUrl( $taskId ) {
		static $mainUrl;
		if (empty($mainUrl)) {
			$mainUrl = WaicFrame::_()->getModule('adminmenu')->getMainLink();
		}
		return $mainUrl . '&tab=' . $this->getCode() . '&task_id=' . $taskId;
	}*/

	public function getTaxonomyHierarchy( $taxonomy, $argsIn, $parent = true, $r = 0 ) {
		$taxonomy = is_array( $taxonomy ) ? array_shift( $taxonomy ) : $taxonomy;
		$args = array(
			'taxonomy' => $taxonomy,
			'hide_empty' => $argsIn['hide_empty'],
		);
		if (isset($argsIn['order'])) {
			$args['orderby'] = !empty($argsIn['orderby']) ? $argsIn['orderby'] : 'name';
			$args['order']   = $argsIn['order'];
		}

		if ( !empty($argsIn['parent']) && 0 !== $argsIn['parent'] ) {
			$args['parent'] = $argsIn['parent'];
		} else {
			$args['parent'] = 0;
		}

		if ('' === $taxonomy) {
			return false;
		}

		if ( 'product_cat' === $taxonomy && $parent ) {
			$args['parent'] = 0;
		}
		$terms = get_terms( $args );
		$children = array();
		if (!is_wp_error($terms)) {
			foreach ( $terms as $term ) {
				if (empty($argsIn['only_parent'])) {
					if (!empty($term->term_id)) {
						$args = array(
							'hide_empty' => $argsIn['hide_empty'],
							'parent' => $term->term_id,
						);
						if (isset($argsIn['order'])) {
							$args['order']   = $argsIn['order'];
							$args['orderby'] = !empty($argsIn['orderby']) ? $argsIn['orderby'] : 'name';
						}
						$term->children = $this->getTaxonomyHierarchy( $taxonomy, $args, false, $r + 1 );
					}
				}
				//$children[ $term->term_id ] = $term;
				$children[ $term->term_id ] = str_repeat('—', $r) . $term->name;
				foreach ($term->children as $k => $t) {
					$children[ $k ] = str_repeat('—', $r) . $t;
				}
			}
		}
		return $children;
	}
	public function getUsersList( $arr = false ) {
		$list = is_array($arr) ? $arr : array();
		$users = get_users();
		if ($users) {
			foreach ($users as $user) {
				$list[$user->ID] = $user->display_name;
			}
		}
		return $list;
	}
	public function getCustomTaxonomiesList( $type = 'post', $add = '' ) {
		$isProduct = ( 'product' == $type );
		if ($isProduct) {
			$exclude = array('product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class');
		} else {
			$exclude = array('category', 'post_tag', 'post_format');
		}
		
		$taxs = array();
		foreach ( get_object_taxonomies($type, 'objects') as $slug => $tax ) {
			if ( ! in_array( $slug, $exclude ) ) {
				if (!$isProduct || strpos($slug, 'pa_') !== 0) {
					$taxs[$slug] = $add . $tax->label;
				}
			}
		}
		return $taxs;
	}
	public function runPreparedTask() {
		$model = $this->getModel();
		if (!$model || !method_exists($model, 'isRunningFlag')) {
			return;
		}
		if (!wp_next_scheduled('waic_run_generation_task') && !$model->isRunningFlag()) {
			$need = false;
			if (!empty($model->getRunningTask())) {
				$need = true;
			} else {
				$prepared = $this->getModel('tasks')->getPreparedTask();
				if (!empty($prepared)) {
					$model->setRunningTask($prepared);
					$need = true;
				}
			}
			if ($need) {
				if (!wp_next_scheduled('waic_run_generation_task')) {
					wp_schedule_single_event(time(), 'waic_run_generation_task');
				}
			}
		}
		//wp_clear_scheduled_hook('waic_run_delayed_actions');
		if (!wp_next_scheduled('waic_run_delayed_actions')) {
			wp_schedule_event(time(), 'hourly', 'waic_run_delayed_actions');
		}
	}
	public function runSchedulededTask( $force = false ) {
		$minCycle = $this->getModel('tasks')->getMinCycle();
		if (wp_next_scheduled('waic_run_scheduled_task')) {
			if (empty($minCycle)) {
				$timestamp = wp_next_scheduled('waic_run_scheduled_task');
				wp_unschedule_event( $timestamp, 'waic_run_scheduled_task');
			}
		} else if (!empty($minCycle)) {
			wp_reschedule_event( time(), 'waic_interval', 'waic_run_scheduled_task' );
		}
		if ($force && wp_next_scheduled('waic_run_scheduled_task')) {
			/**
			 * Do custom action
			 * 
			 * @since 3.4
			*/
			do_action('waic_run_scheduled_task');
		}
	}
	
	public function doScheduledTasks() {
		$model = $this->getModel();
		$result = $model->doScheduledTasks();
		if (!$result) {
			$model->setStoppingTaskGeneration();
			$model->resetRunningFlag();
			WaicFrame::_()->saveDebugLogging();
		}
	}
	
	public function runGenerationTask( $force = false ) {
		if (!wp_next_scheduled('waic_run_generation_task') && !$this->getModel()->isRunningFlag()) {
			wp_schedule_single_event(time(), 'waic_run_generation_task');
		}
		if ($force) {
			/**
			 * Do custom action
			 * 
			 * @since 3.4
			*/
			do_action('waic_run_generation_task');
		}
	}
	public function doGenerationTask() {
		$model = $this->getModel();
		$result = $model->doGenerationTasks();
		if (!$result) {
			$model->setStoppingTaskGeneration();
			$model->resetRunningFlag();
			WaicFrame::_()->saveDebugLogging();
		}
	}
	public function doDelayedActions() {
		$result = $this->getModel()->doDelayedActions();
		if (!$result) {
			WaicFrame::_()->saveDebugLogging();
		}
	}
}
