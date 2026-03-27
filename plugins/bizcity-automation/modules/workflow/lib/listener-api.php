<?php
if (!defined('ABSPATH')) exit;

/**
 * Workflow Trigger Listener API
 * Handle "Listen for test event" functionality like N8N
 */
class WaicWorkflowListenerAPI {
    
    private static $instance = null;
    private $debug = false; // Set to true to enable debug logging
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_waic_workflow_listen_trigger', array($this, 'startListener'));
        add_action('wp_ajax_waic_workflow_stop_listener', array($this, 'stopListener'));
        add_action('wp_ajax_waic_workflow_poll_listener', array($this, 'pollListener'));
        
        // Hook vào workflow trigger system (universal for all trigger types)
        // Hook is always registered but captureWorkflowTrigger has early exit if no active listeners
        add_action('waic_twf_process_flow', array($this, 'captureWorkflowTrigger'), 1, 2);
    }
    
    /**
     * Debug logging helper
     */
    private function log($message) {
        if ($this->debug) {
            error_log('[WAIC Listener] ' . $message);
        }
    }
    
    /**
     * Start listening for trigger events
     */
    public function startListener() {
        try {
            check_ajax_referer('waic-nonce', 'nonce');
            
            $nodeId = sanitize_text_field($_POST['node_id'] ?? '');
            $triggerCode = sanitize_text_field($_POST['trigger_code'] ?? '');
            $settings = json_decode(stripslashes($_POST['trigger_settings'] ?? '{}'), true);
            $autoExecute = sanitize_text_field($_POST['auto_execute'] ?? '') === 'true';
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $nodes = json_decode(stripslashes($_POST['nodes'] ?? '[]'), true);
            $edges = json_decode(stripslashes($_POST['edges'] ?? '[]'), true);
            
            if (empty($nodeId)) {
                wp_send_json_error(['message' => 'Node ID is required']);
                return;
            }
            
            if (empty($triggerCode)) {
                wp_send_json_error(['message' => 'Trigger code is required']);
                return;
            }
            
            // Generate test webhook URL or setup listener
            $listenerData = $this->setupListener($nodeId, $triggerCode, $settings, $autoExecute, $taskId, $nodes, $edges);
            
            if ($listenerData) {
                wp_send_json_success($listenerData);
            } else {
                wp_send_json_error(['message' => 'Failed to setup listener']);
            }
        } catch (Exception $e) {
            error_log('WAIC Listener Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Setup listener based on trigger type
     */
    private function setupListener($nodeId, $triggerCode, $settings, $autoExecute = false, $taskId = 0, $nodes = [], $edges = []) {
        $listenerId = 'waic_listener_' . $nodeId . '_' . time();
        
        $this->log('Setting up listener: ' . $listenerId . ' for trigger: ' . $triggerCode);
        
        set_transient($listenerId, [
            'node_id' => $nodeId,
            'trigger_code' => $triggerCode,
            'settings' => $settings,
            'status' => 'listening',
            'started_at' => current_time('mysql'),
            'captured_data' => null,
            'auto_execute' => $autoExecute,
            'task_id' => $taskId,
            'nodes' => $nodes,
            'edges' => $edges
        ], 300);
        
        update_option('waic_active_listener_' . $nodeId, $listenerId);
        
        // Invalidate cache to force query on next trigger
        delete_transient('waic_has_active_listeners');
        
        return [
            'listener_id' => $listenerId,
            'webhook_url' => home_url('/wp-json/waic/v1/webhook-test/' . $nodeId),
            'message' => 'Listening for test event...',
            'trigger_type' => $triggerCode
        ];
    }
    
    /**
     * Poll for captured events
     */
    public function pollListener() {
        check_ajax_referer('waic-nonce', 'nonce');
        
        $nodeId = sanitize_text_field($_POST['node_id'] ?? '');
        $listenerId = get_option('waic_active_listener_' . $nodeId);
        
        if (empty($listenerId)) {
            wp_send_json_success(['event_captured' => false]);
            return;
        }
        
        $listenerData = get_transient($listenerId);
        
        if (!$listenerData) {
            wp_send_json_success(['event_captured' => false]);
            return;
        }
        
        if (!empty($listenerData['captured_data'])) {
            $this->log('Event captured for node: ' . $nodeId);
            
            wp_send_json_success([
                'event_captured' => true,
                'event_data' => $listenerData['captured_data']
            ]);
        } else {
            wp_send_json_success(['event_captured' => false]);
        }
    }
    
    /**
     * Stop listener
     */
    public function stopListener() {
        check_ajax_referer('waic-nonce', 'nonce');
        
        $nodeId = sanitize_text_field($_POST['node_id'] ?? '');
        $listenerId = get_option('waic_active_listener_' . $nodeId);
        
        if ($listenerId) {
            delete_transient($listenerId);
            delete_option('waic_active_listener_' . $nodeId);
            // Invalidate cache
            delete_transient('waic_has_active_listeners');
        }
        
        wp_send_json_success(['message' => 'Listener stopped']);
    }
    
    /**
     * Capture webhook event (called from REST endpoint)
     */
    public static function captureWebhookEvent($nodeId, $eventData) {
        $listenerId = get_option('waic_active_listener_' . $nodeId);
        
        if ($listenerId) {
            $listenerData = get_transient($listenerId);
            
            if ($listenerData && $listenerData['status'] === 'listening') {
                $listenerData['captured_data'] = $eventData;
                $listenerData['status'] = 'captured';
                $listenerData['captured_at'] = current_time('mysql');
                
                set_transient($listenerId, $listenerData, 300);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Generic method to capture trigger data to both listener and global transient
     */
    private function captureTriggerData($triggerData, $nodeId = null) {
        // If nodeId provided, save directly to that node
        if ($nodeId) {
            // Save to active listener if exists
            $listenerId = get_option('waic_active_listener_' . $nodeId);
            if ($listenerId) {
                $listenerData = get_transient($listenerId);
                if ($listenerData && $listenerData['status'] === 'listening') {
                    $listenerData['captured_data'] = $triggerData;
                    $listenerData['status'] = 'captured';
                    $listenerData['captured_at'] = current_time('mysql');
                    set_transient($listenerId, $listenerData, 300);
                    $this->log('Captured to listener for node: ' . $nodeId);
                }
            }
            
            // Always save to global transient for Execute Test mode
            set_transient('waic_trigger_data_' . $nodeId, $triggerData, 300);
            $this->log('Saved to global transient: waic_trigger_data_' . $nodeId);
            return;
        }
        
        // If no nodeId, find all matching trigger nodes in active workflows
        global $wpdb;
        
        // Check if table exists first
        $table_name = $wpdb->prefix . WAIC_DB_PREF . 'workflows';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists !== $table_name) {
            return;
        }
        
        $workflows = $wpdb->get_results(
            "SELECT id, params FROM {$table_name} 
            WHERE status = 9 AND params LIKE '%trigger%'",
            ARRAY_A
        );
        
        if (empty($workflows)) {
            return;
        }
        
        foreach ($workflows as $workflow) {
            $params = json_decode($workflow['params'], true);
            if (!$params || empty($params['nodes'])) {
                continue;
            }
            
            foreach ($params['nodes'] as $node) {
                if ($node['type'] === 'trigger') {
                    $nodeId = $node['id'];
                    
                    // Save to active listener if exists
                    $listenerId = get_option('waic_active_listener_' . $nodeId);
                    if ($listenerId) {
                        $listenerData = get_transient($listenerId);
                        if ($listenerData && $listenerData['status'] === 'listening') {
                            $listenerData['captured_data'] = $triggerData;
                            $listenerData['status'] = 'captured';
                            $listenerData['captured_at'] = current_time('mysql');
                            set_transient($listenerId, $listenerData, 300);
                        }
                    }
                    
                    // Always save to global transient
                    set_transient('waic_trigger_data_' . $nodeId, $triggerData, 300);
                }
            }
        }
    }
    
    /**
     * Capture workflow trigger event (universal for all trigger types)
     */
    public function captureWorkflowTrigger($triggerData, $rawData) {
        // Skip if not needed - check if we have active listeners first
        $has_recent_listeners = get_transient('waic_has_active_listeners');
        if ($has_recent_listeners === 'no') {
            return; // No active listeners, skip processing
        }
        
        // Use output buffering to prevent any stray output from corrupting
        // AJAX JSON responses (e.g. admin chat, webchat send message).
        ob_start();
        
        try {
            $this->log('captureWorkflowTrigger called with ' . (is_array($triggerData) ? count($triggerData) : 0) . ' data keys');
            
            // Method 1: Scan saved active workflows (status=9) for trigger nodes with listeners
            $this->captureTriggerData($triggerData);
            
            // Method 2: Directly scan ALL active listeners in wp_options
            // This catches test executions on unsaved/draft workflows (status != 9)
            $this->captureToAllActiveListeners($triggerData);
        } catch (Exception $e) {
            // Silently log error without breaking the flow
            error_log('[WAIC Listener] captureWorkflowTrigger error: ' . $e->getMessage());
        } catch (Throwable $e) {
            // Catch any fatal errors (PHP 7+)
            error_log('[WAIC Listener] captureWorkflowTrigger fatal: ' . $e->getMessage());
        }
        
        ob_end_clean(); // Discard any stray output
    }

    
    /**
     * Scan wp_options for ALL active listeners and capture trigger data to them.
     * 
     * This is critical for "Execute Test" mode where the workflow may not be
     * saved/published (status != 9) yet. The listener was started via
     * waic_workflow_listen_trigger but captureTriggerData() only finds
     * listeners through status=9 workflow DB query — missing test executions.
     *
     * @param array $triggerData Normalized trigger payload
     */
    private function captureToAllActiveListeners($triggerData) {
        if (!is_array($triggerData)) return;
        
        // Quick check: if no recent listener activity, skip expensive query
        $has_recent_listeners = get_transient('waic_has_active_listeners');
        if ($has_recent_listeners === 'no') {
            return;
        }
        
        global $wpdb;
        
        // Suppress any db errors during this query
        $wpdb->suppress_errors(true);
        
        // Find all waic_active_listener_* options (these are set by startListener)
        // Limit to 20 to prevent performance issues
        $listeners = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE 'waic\_active\_listener\_%'
             AND option_value != ''
             LIMIT 20",
            ARRAY_A
        );
        
        // Re-enable error reporting
        $wpdb->suppress_errors(false);
        
        if (empty($listeners)) {
            $this->log('No active listeners found in wp_options');
            // Cache negative result for 10 seconds to reduce db queries
            set_transient('waic_has_active_listeners', 'no', 10);
            return;
        }
        
        // Mark that we have active listeners
        set_transient('waic_has_active_listeners', 'yes', 30);
        
        $this->log('Found ' . count($listeners) . ' active listener(s) in wp_options');
        
        $captured_count = 0;
        
        foreach ($listeners as $opt) {
            $listenerId = $opt['option_value'];
            if (empty($listenerId)) continue;
            
            $listenerData = get_transient($listenerId);
            
            // Skip if transient expired or already captured
            if (!$listenerData || ($listenerData['status'] ?? '') !== 'listening') {
                continue;
            }
            
            // Extract nodeId from option name: waic_active_listener_{nodeId}
            $nodeId = str_replace('waic_active_listener_', '', $opt['option_name']);
            
            // Optional: filter by trigger_code if listener requires a specific one
            $listenerTriggerCode = $listenerData['trigger_code'] ?? '';
            if (!empty($listenerTriggerCode)) {
                // For gateway: trigger_code might be bizcity_gateway_message_received
                // For webchat: bizcity_webchat_message_received
                // For zalo: bizcity_twf_message_received
                // We capture for ALL — the trigger block's controlRun will filter
                $this->log('Listener ' . $listenerId . ' wants trigger_code: ' . $listenerTriggerCode);
            }
            
            // Save captured data to listener transient
            $listenerData['captured_data'] = $triggerData;
            $listenerData['status'] = 'captured';
            $listenerData['captured_at'] = current_time('mysql');
            set_transient($listenerId, $listenerData, 300);
            
            // Also save to global transient for Execute Test fallback
            set_transient('waic_trigger_data_' . $nodeId, $triggerData, 300);
            
            $captured_count++;
            $this->log('✅ Captured trigger data to listener: ' . $listenerId . ' (node: ' . $nodeId . ')');
        }
        
        if ($captured_count > 0) {
            $this->log('Total captured: ' . $captured_count . ' listener(s)');
        }
    }
}

// Initialize
WaicWorkflowListenerAPI::getInstance();
