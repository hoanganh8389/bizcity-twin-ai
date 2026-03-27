<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkflow extends WaicModule {
	
	
	public function init() {
		// Cron intervals are now registered early in bootstrap.php
		// Keep this as fallback for backward compatibility
		add_filter('cron_schedules', array($this, 'addCustomCronIntervals'), 10);
		
		WaicDispatcher::addFilter('mainAdminTabs', array($this, 'addAdminTab'));
		add_action('rest_api_init', array($this, 'webhookRestApiInit'));
		add_action('init', array($this, 'controlUrlTrigger'));

		add_action('waic_create_scheduled_flow', array($this, 'doScheduledFlows'), 10, 1);
		add_action('waic_run_workflow', array($this, 'doWorkflowRuns'), 10, 1);

		// Run cron events after intervals are registered
		add_action('init', array($this, 'runCronEvents'), 25);
		
		// Fallback: try again later if model not ready
		add_action('wp_loaded', array($this, 'ensureCronEvents'), 10);

		// FIX: đừng gọi ngay trong init() vì đôi lúc module/model chưa attach đủ
		add_action('init', array($this, 'bootHookedFlows'), 20);

		add_action('admin_enqueue_scripts', array($this, 'disableConflictingScripts'), 100);
		
		// Load AI Dashboard API
		require_once dirname(__FILE__) . '/lib/ai-dashboard-api.php';
		
		// Load Workflow Execute API
		require_once dirname(__FILE__) . '/lib/execute-api.php';
		
		// Load HIL Helper Functions
		require_once dirname(__FILE__) . '/includes/hil-helper.php';
		
		// Load HIL Integration (Zalo webhook)
		require_once dirname(__FILE__) . '/includes/hil-integration.php';
	}

	public function bootHookedFlows() {
		$model = $this->getModel('workflow');
		if ($model) {
			$model->doHookedFlows();
			return;
		}
		error_log('[TWF] bootHookedFlows() failed: workflow model is null');
	}

	public function webhookRestApiInit() {
		register_rest_route('aiwu/v1', '/oauth2callback', [
			'methods' => 'GET',
			'callback' => array($this, 'oauthRedirect'),
			'permission_callback' => '__return_true',
		]);
		$this->getModel('workflow')->registerWebhookRoutes();
	}
	public function oauthRedirect() {
		$code = WaicReq::getVar('code');
		$integ = WaicReq::getVar('cur');

		header('Content-Type: text/html; charset=utf-8');
    		echo "<script>
			window.opener.postMessage({type: 'oauth_code', code: '$code'}, '*');
			window.close();
		</script>";
	}

	public function controlUrlTrigger() {
		$url = $_SERVER['REQUEST_URI'];
		if (
			is_admin() ||
			wp_doing_ajax() ||
			(strpos($url, '/wp-json/') === 0) ||
			(strpos($url, '/wp-cron.php') === 0) ||
			(strpos($url, '/favicon.ico') === 0) ||
			preg_match('#\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|map)(\?.*)?$#', $url)
		) {
			return;
		}
	
		$this->getModel('workflow')->runUrlTriggers($url);
	}
	/*
	public function disableConflictingScripts() {
		$screen = get_current_screen();
		if ($screen->id === 'toplevel_page_waic-workspace') {
			wp_deregister_script('svg-painter');
			wp_deregister_script('heartbeat');
			wp_deregister_script('customize-controls');
			//wp_deregister_script('media-editor');
			wp_deregister_script('block-editor');
			wp_deregister_script('updates');
		}
	}
	*/
	public function disableConflictingScripts() {
		if (!function_exists('get_current_screen')) {
			return;
		}

		$screen = get_current_screen();
		$pluginScreenId = 'toplevel_page_' . WaicFrame::_()->getModule('adminmenu')->getMainSlug();
		if (!$screen || empty($screen->id) || $screen->id !== $pluginScreenId) {
			return;
		}

		// Dequeue non-essential WP UI scripts that conflict with ReactFlow builder
		foreach (array('customize-controls', 'block-editor', 'updates') as $handle) {
			if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
				wp_dequeue_script($handle);
			}
		}

		// Embed mode: aggressively dequeue all third-party scripts/styles
		if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
			$this->dequeueThirdPartyAssets();
		}
	}

	/**
	 * Dequeue third-party scripts/styles for clean embed rendering.
	 * Uses whitelist — only keeps WP core + plugin's own assets.
	 */
	private function dequeueThirdPartyAssets() {
		global $wp_scripts, $wp_styles;

		$allowed_script_prefixes = array(
			'waic-', 'jquery', 'wp-', 'utils', 'media-',
			'backbone', 'underscore', 'heartbeat', 'admin-bar',
			'common', 'hoverIntent', 'schedule', 'postbox',
		);
		$allowed_style_prefixes = array(
			'waic-', 'wp-', 'admin-', 'dashicons', 'common',
			'forms', 'buttons', 'media', 'colors',
		);

		if ( $wp_scripts ) {
			foreach ( array_keys( $wp_scripts->registered ) as $handle ) {
				if ( ! wp_script_is( $handle, 'enqueued' ) ) {
					continue;
				}
				$keep = false;
				foreach ( $allowed_script_prefixes as $prefix ) {
					if ( strpos( $handle, $prefix ) === 0 ) {
						$keep = true;
						break;
					}
				}
				if ( ! $keep ) {
					wp_dequeue_script( $handle );
				}
			}
		}

		if ( $wp_styles ) {
			foreach ( array_keys( $wp_styles->registered ) as $handle ) {
				if ( ! wp_style_is( $handle, 'enqueued' ) ) {
					continue;
				}
				$keep = false;
				foreach ( $allowed_style_prefixes as $prefix ) {
					if ( strpos( $handle, $prefix ) === 0 ) {
						$keep = true;
						break;
					}
				}
				if ( ! $keep ) {
					wp_dequeue_style( $handle );
				}
			}
		}
	}
	public function addAdminTab( $tabs ) {
		$code = $this->getCode();
		$tabs[$code] = array('label' => esc_html__('Workflows', 'ai-copilot-content-generator'), 'callback' => array($this, 'showWorkflow'), 'fa_icon' => 'fa-list', 'sort_order' => 5, 'add_bread' => $this->getCode());
		
		$tabs['builder'] = array(
			'label' => esc_html__('Builder', 'ai-copilot-content-generator'), 
			'hidden'     => 1,
			'sort_order' => 0,
			'callback' => array($this, 'showWorkflowBuilder'), 
			'bread'      => false,
			'last_Id' => 'waicTaskNameWrapper'
		);
		$tabs['template'] = array(
			'label' => esc_html__('Template', 'ai-copilot-content-generator'), 
			'hidden'     => 1,
			'sort_order' => 1,
			'callback' => array($this, 'createWorkflowByTemplate'), 
			'bread'      => false,
			'last_Id' => 'waicTaskNameWrapper'
		);
		$tabs['oauth'] = array(
			'label' => '', 
			'hidden'     => 1,
			'sort_order' => 0,
			'callback' => array($this, 'oauthRedirect'), 
			'bread'      => false,
		);
		return $tabs;
	}
	public function showWorkflow() {
		$taskId = WaicReq::getVar('task_id');
		if (!empty($taskId)) {
			return $this->getView()->showWorkflowBuilder($taskId);
		}
		return $this->getView()->showWorkflow();
	}
	
	public function showWorkflowBuilder() {
		$taskId = WaicReq::getVar('task_id');
		$feature = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getTaskFeature($taskId);
		if ('template' == $feature) {
			$taskId = $this->getModel()->createWorkflowByTemplate($taskId);
		}
		return $this->getView()->showWorkflowBuilder($taskId);
	}
	
	public function createWorkflowByTemplate() {
		$taskId = WaicReq::getVar('task_id');
		$taskId = $this->getModel()->createWorkflowByTemplate($taskId);
		$url = WaicFrame::_()->getModule('workspace')->getTaskUrl($taskId, 'builder');
		if (headers_sent()) {
			echo '<script type="text/javascript"> document.location.href="' . $url . '"; </script>';
		} else {
			wp_redirect($url);
		}
		
		exit;
		//return $this->getView()->showWorkflowBuilder($taskId);
	}
	
	public function getWorkflowTabsList( $current = '' ) {
		$tabs = array(
			'new' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Tạo mới', 'ai-copilot-content-generator'),
			),
			'history' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Sự kiện', 'ai-copilot-content-generator'),
			),
			'integrations' => array(
				'class' => '',
				'pro' => false,
				'label' => __('Tích hợp bên ngoài', 'ai-copilot-content-generator'),
			),
		);

		if (empty($current) || !isset($tabs[$current])) {
			reset($tabs);
			$current = key($tabs);
		}
		$tabs[$current]['class'] .= ' current';
		
		return WaicDispatcher::applyFilters('getWorkspaceTabsList', $tabs);
	}
	
	/**
	 * Register custom cron intervals for workflow execution
	 */
	public function addCustomCronIntervals($schedules) {
		if (!isset($schedules['waic_interval1'])) {
			$schedules['waic_interval1'] = array(
				'interval' => 60, // 1 minute
				'display'  => __('Every Minute (WAIC)', 'ai-copilot-content-generator')
			);
		}
		if (!isset($schedules['waic_interval5'])) {
			$schedules['waic_interval5'] = array(
				'interval' => 300, // 5 minutes
				'display'  => __('Every 5 Minutes (WAIC)', 'ai-copilot-content-generator')
			);
		}
		return $schedules;
	}
	
	
	private static $cron_log_shown = false;
	
	public function runCronEvents( $force = false ) {
		// Ensure model is available
		$model = $this->getModel('workflow');
		if (!$model) {
			if (!self::$cron_log_shown) {
				error_log('[WAIC Workflow] Workflow model not available yet, skipping cron setup');
				self::$cron_log_shown = true;
			}
			return;
		}
		
		// Cron intervals should be available now (registered early in bootstrap.php)
		// Just verify they exist
		$schedules = wp_get_schedules();
		if (!isset($schedules['waic_interval1']) || !isset($schedules['waic_interval5'])) {
			// This should not happen with early registration, but log once if it does
			if (!self::$cron_log_shown) {
				error_log('[WAIC Workflow] WARN: Custom cron intervals not found. Check bootstrap.php early registration.');
				self::$cron_log_shown = true;
			}
			return;
		}
		
		$existScheduled = $model->existScheduledFlows();
		if (empty($existScheduled)) {
			wp_clear_scheduled_hook('waic_create_scheduled_flow');
		} else if (!wp_next_scheduled('waic_create_scheduled_flow')) {
			$result = wp_schedule_event( time(), 'waic_interval5', 'waic_create_scheduled_flow' );
			if (is_wp_error($result)) {
				error_log('[WAIC Workflow] Failed to schedule waic_create_scheduled_flow: ' . $result->get_error_message());
			}
		} else if ($force) {
			wp_reschedule_event( time(), 'waic_interval5', 'waic_create_scheduled_flow' );
		}
		
		if (!wp_next_scheduled('waic_run_workflow')) {
			$result = wp_schedule_event( time(), 'waic_interval1', 'waic_run_workflow' );
			if (is_wp_error($result)) {
				error_log('[WAIC Workflow] Failed to schedule waic_run_workflow: ' . $result->get_error_message());
			}
		}
	}
	
	/**
	 * Fallback to ensure cron events are setup if init() failed
	 */
	public function ensureCronEvents() {
		// Only run if not already scheduled
		if (!wp_next_scheduled('waic_run_workflow')) {
			$this->runCronEvents();
		}
	}
	
	public function doScheduledFlows() {
		$result = $this->getModel()->doScheduledFlows();
		if (!$result) {
			WaicFrame::_()->saveDebugLogging();
		}
	}
	public function doWorkflowRuns() {
		$result = $this->getModel()->doFlowRuns();
		if (!$result) {
			WaicFrame::_()->saveDebugLogging();
		}
		// Khi workflow kết thúc, dọn cron delay nếu có
		if (class_exists('WaicLogic_un_delay') && method_exists('WaicLogic_un_delay', 'clear_delay_cron')) {
			// Lấy run_id hiện tại nếu có
			$run_id = defined('WAIC_CURRENT_RUN_ID') ? WAIC_CURRENT_RUN_ID : 0;
			if ($run_id) {
				WaicLogic_un_delay::clear_delay_cron($run_id);
			}
		}
	}
	
	/**
	 * Admin AJAX: Execute workflow in test mode (realtime, không dùng cron)
	 */
	public function ajaxTestWorkflowExecute() {
		check_ajax_referer('waic-nonce', 'waicNonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
			return;
		}
		
		$taskId = (int) ($_POST['task_id'] ?? 0);
		$nodes = isset($_POST['nodes']) ? json_decode(stripslashes($_POST['nodes']), true) : [];
		$edges = isset($_POST['edges']) ? json_decode(stripslashes($_POST['edges']), true) : [];
		$settings = isset($_POST['settings']) ? json_decode(stripslashes($_POST['settings']), true) : [];
		
		if (empty($nodes)) {
			wp_send_json_error(['message' => 'No nodes to execute']);
			return;
		}
		
		// Create execution state
		$executionId = 'waic_test_' . $taskId . '_' . time();
		
		// Find trigger node
		$triggerNode = null;
		foreach ($nodes as $node) {
			if (($node['type'] ?? '') === 'trigger') {
				$triggerNode = $node;
				break;
			}
		}
		
		if (!$triggerNode) {
			wp_send_json_error(['message' => 'No trigger node found']);
			return;
		}
		
		$triggerNodeId = $triggerNode['id'];
		
		// Check if listener already has data
		$listenerId = get_option('waic_active_listener_' . $triggerNodeId);
		$hasListenerData = false;
		$testData = [];
		
		if ($listenerId) {
			$listenerData = get_transient($listenerId);
			if ($listenerData && !empty($listenerData['captured_data'])) {
				$testData = $listenerData['captured_data'];
				$hasListenerData = true;
			}
		}
		
		// Initialize execution state
		$executionState = [
			'execution_id' => $executionId,
			'task_id' => $taskId,
			'status' => $hasListenerData ? 'ready_to_run' : 'waiting_for_trigger',
			'mode' => 'test',
			'started_at' => current_time('mysql'),
			'current_node' => $hasListenerData ? $triggerNodeId : null,
			'trigger_node_id' => $triggerNodeId,
			'listener_id' => $listenerId,
			'nodes' => $nodes,
			'edges' => $edges,
			'settings' => $settings,
			'test_data' => $testData,
			'node_status' => $hasListenerData ? [$triggerNodeId => 'executing'] : [$triggerNodeId => 'waiting'],
			'variables' => [],
			'logs' => [],
			'error' => null,
			'completed_nodes' => [],
		];
		
		set_transient($executionId, $executionState, 3600);
		update_option('waic_test_execution_' . $taskId, $executionId);
		
		// If we have listener data, start execution immediately
		if ($hasListenerData) {
			// Return success response first
			wp_send_json_success([
				'execution_id' => $executionId,
				'status' => 'ready_to_run',
				'message' => 'Test execution starting with captured data...',
			]);
			
			// Close connection và tiếp tục xử lý
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			} else {
				// Fallback: flush output
				if (ob_get_level() > 0) {
					ob_end_flush();
				}
				flush();
			}
            
            // Execute workflow ngay sau khi response đã được gửi
            $executeAPI = WaicWorkflowExecuteAPI::getInstance();
            $executeAPI->executeWorkflowBackground($executionId);
            exit;
		} else {
			// Need to wait for trigger data
			wp_send_json_success([
				'execution_id' => $executionId,
				'status' => 'waiting_for_trigger',
				'trigger_node_id' => $triggerNodeId,
				'message' => 'Waiting for trigger event. Please activate listener first.',
			]);
		}
	}
	
	/**
	 * Admin AJAX: Poll test execution status (realtime updates)
	 */
	public function ajaxTestWorkflowPoll() {
		check_ajax_referer('waic-nonce', 'waicNonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
			return;
		}
		
		$executionId = sanitize_text_field($_POST['execution_id'] ?? '');
		
		if (empty($executionId)) {
			wp_send_json_error(['message' => 'Execution ID required']);
			return;
		}
		
		$executionState = get_transient($executionId);
		
		if (!$executionState) {
			wp_send_json_error(['message' => 'Execution not found or expired']);
			return;
		}
		
		// If waiting for trigger, check if listener has data now
		if ($executionState['status'] === 'waiting_for_trigger') {
			$triggerNodeId = $executionState['trigger_node_id'] ?? null;
			
			if ($triggerNodeId) {
				$listenerId = get_option('waic_active_listener_' . $triggerNodeId);
				
				if ($listenerId) {
					$listenerData = get_transient($listenerId);
					
					if ($listenerData && !empty($listenerData['captured_data'])) {
						// Trigger data available! Update state and start execution
						$executionState['test_data'] = $listenerData['captured_data'];
						$executionState['status'] = 'running';
						$executionState['logs'][] = [
							'timestamp' => current_time('mysql'),
							'level' => 'NOTICE',
							'message' => 'Trigger data captured, starting execution...',
						];
						
						set_transient($executionId, $executionState, 3600);
					
					error_log('[WAIC Test] Trigger data captured, executing inline...');
					
					// Execute workflow INLINE - đảm bảo polling bắt được updates
					$executeAPI = WaicWorkflowExecuteAPI::getInstance();
					$executeAPI->executeWorkflowBackground($executionId);
					
					// Refresh state sau khi execute
					$executionState = get_transient($executionId);
					}
				}
			}
		}
		
		// If waiting for delay, check if time has passed
		if ($executionState['status'] === 'waiting') {
			$waitingUntil = $executionState['waiting_until'] ?? 0;
			$currentTime = WaicUtils::getTimestamp();
			
			if ($waitingUntil > 0 && $currentTime >= $waitingUntil) {
				// Delay has passed! Mark delay node success
				$delayNodeId = $executionState['pending_delay_node'] ?? null;
				
				error_log('[WAIC Test] Delay completed for node: ' . $delayNodeId);
				
				// Mark delay node as success
				if ($delayNodeId) {
					$executionState['node_status'][$delayNodeId] = 'success';
					
					// Add delay node to visited list (so it won't execute again)
					if (!in_array($delayNodeId, $executionState['visited_nodes'] ?? [])) {
						$executionState['visited_nodes'][] = $delayNodeId;
					}
				}
				
				// DON'T clear pending_delay_node yet - let executeWorkflowBackground() use it first
				// It will be cleared after resume completes
				$executionState['status'] = 'running';
				set_transient($executionId, $executionState, 3600);
				
				// Resume execution
				error_log('[WAIC Test] Resuming execution...');
				$executeAPI = WaicWorkflowExecuteAPI::getInstance();
				$executeAPI->executeWorkflowBackground($executionId);
				
				// NOW clear the flag after resume
				$executionState = get_transient($executionId);
				if (isset($executionState['pending_delay_node'])) {
					unset($executionState['pending_delay_node']);
					set_transient($executionId, $executionState, 3600);
				}
				
				// Refresh state sau khi execute
				$executionState = get_transient($executionId);
			}
		}
		
		// Return current state
		wp_send_json_success([
			'execution_id' => $executionId,
			'status' => $executionState['status'] ?? 'unknown',
			'current_node' => $executionState['current_node'] ?? null,
			'node_status' => $executionState['node_status'] ?? [],
			'completed_nodes' => $executionState['completed_nodes'] ?? [],
			'variables' => $executionState['variables'] ?? [],
			'logs' => array_slice($executionState['logs'] ?? [], -50), // Last 50 logs
			'error' => $executionState['error'] ?? null,
			'notification' => $executionState['notification'] ?? null,
			'waiting_until' => $executionState['waiting_until'] ?? null,
			'trigger_node_id' => $executionState['trigger_node_id'] ?? null,
		]);
	}
	
	/**
	 * Admin AJAX: Stop test execution
	 */
	public function ajaxTestWorkflowStop() {
		check_ajax_referer('waic-nonce', 'waicNonce');
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Permission denied']);
			return;
		}
		
		$executionId = sanitize_text_field($_POST['execution_id'] ?? '');
		
		if (empty($executionId)) {
			wp_send_json_error(['message' => 'Execution ID required']);
			return;
		}
		
		$executionState = get_transient($executionId);
		
		if ($executionState) {
			$executionState['status'] = 'stopped';
			$executionState['stopped_at'] = current_time('mysql');
			$executionState['logs'][] = [
				'timestamp' => current_time('mysql'),
				'level' => 'NOTICE',
				'message' => 'Test execution stopped by user',
			];
			
			set_transient($executionId, $executionState, 3600);
		}
		
		wp_send_json_success(['message' => 'Execution stopped']);
	}
}

// Add in any admin-loaded file (mu-plugin bootstrap / workflow module init)

add_action('admin_post_waic_delete_scenario', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', 403);
    }

    $taskId = isset($_GET['task_id']) ? (int) $_GET['task_id'] : 0;
    if ($taskId <= 0) {
        wp_die('Missing task_id', 400);
    }

    check_admin_referer('waic_delete_scenario_' . $taskId);

    global $wpdb;
    $tblWorkflows = $wpdb->prefix . WAIC_DB_PREF . 'workflows';
    $tblTasks     = $wpdb->prefix . WAIC_DB_PREF . 'tasks';

    // Delete workflow rows first, then task
    $wpdb->delete($tblWorkflows, array('task_id' => $taskId), array('%d'));
    $wpdb->delete($tblTasks, array('id' => $taskId), array('%d'));

    $redirect = wp_get_referer();
    if (!$redirect) {
        $redirect = admin_url('admin.php?page=waic-workspace&tab=new');
    }

    $redirect = add_query_arg('waic_notice', 'scenario_deleted', $redirect);
    // Propagate iframe mode after delete
    if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
        $redirect = add_query_arg('bizcity_iframe', '1', $redirect);
    }
    wp_safe_redirect($redirect);
    exit;
});

/**
 * Admin AJAX Test Workflow Execution Handlers
 * These methods handle realtime test execution via AJAX (không dùng cron)
 */
add_action('admin_init', function() {
	$module = WaicFrame::_()->getModule('workflow');
	if ($module) {
		add_action('wp_ajax_waic_test_workflow_execute', array($module, 'ajaxTestWorkflowExecute'));
		add_action('wp_ajax_waic_test_workflow_poll', array($module, 'ajaxTestWorkflowPoll'));
		add_action('wp_ajax_waic_test_workflow_stop', array($module, 'ajaxTestWorkflowStop'));
	}
}, 20);

/**
 * Background execution handler for test workflows
 */
add_action('waic_test_workflow_background', function($executionId) {
	if (empty($executionId)) {
		return;
	}
	
	$executeAPI = WaicWorkflowExecuteAPI::getInstance();
	if (method_exists($executeAPI, 'executeWorkflowBackground')) {
		$executeAPI->executeWorkflowBackground($executionId);
	}
});