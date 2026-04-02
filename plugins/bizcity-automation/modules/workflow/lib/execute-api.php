<?php
if (!defined('ABSPATH')) exit;

/**
 * Workflow Execution API
 * Execute workflow nodes step-by-step with real-time logging and status tracking
 */
class WaicWorkflowExecuteAPI {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_waic_workflow_execute', array($this, 'executeWorkflow'));
        add_action('wp_ajax_waic_workflow_execute_node', array($this, 'executeNode'));
        add_action('wp_ajax_waic_workflow_get_execution_status', array($this, 'getExecutionStatus'));
        add_action('wp_ajax_waic_workflow_stop_execution', array($this, 'stopExecution'));
    }
    
    /**
     * Execute entire workflow
     */
    public function executeWorkflow() {
        try {
            check_ajax_referer('waic-nonce', 'nonce');
            
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $nodes = json_decode(stripslashes($_POST['nodes'] ?? '[]'), true);
            $edges = json_decode(stripslashes($_POST['edges'] ?? '[]'), true);
            $testData = json_decode(stripslashes($_POST['test_data'] ?? '{}'), true);
            
            if (empty($nodes)) {
                wp_send_json_error(['message' => 'No nodes to execute']);
                return;
            }
            
            // Create execution ID (allow task_id = 0 for testing unsaved workflows)
            $executionId = 'waic_exec_' . ($taskId > 0 ? $taskId : 'temp') . '_' . time();
            
            // Phase 1.1: Extract pipeline context (from Step Executor or frontend)
            $pipeline_id = sanitize_text_field( $_POST['pipeline_id'] ?? '' );

            // Initialize execution state
            $executionState = [
                'execution_id' => $executionId,
                'task_id' => $taskId,
                'status' => 'running',
                'started_at' => current_time('mysql'),
                'current_node' => null,
                'nodes' => $nodes,
                'edges' => $edges,
                'test_data' => $testData,
                'node_status' => [], // Track each node's status
                'variables' => [], // Store output variables from each node
                'logs' => [],
                'error' => null,
                // Phase 1.1 — Pipeline context (empty = manual workflow, hooks won't fire)
                'pipeline_id'              => $pipeline_id,
                'user_id'                  => get_current_user_id(),
                'session_id'               => sanitize_text_field( $_POST['session_id'] ?? '' ),
                'channel'                  => sanitize_text_field( $_POST['channel'] ?? 'adminchat' ),
                'intent_conversation_id'   => sanitize_text_field( $_POST['intent_conversation_id'] ?? '' ),
                'node_step_map'            => $this->buildNodeStepMap( $nodes, $edges ),
            ];
            
            // Store in transient (expire after 1 hour)
            set_transient($executionId, $executionState, 3600);
            
            // Store active execution ID
            update_option('waic_active_execution_' . $taskId, $executionId);
            
            $this->addLog($executionId, 'NOTICE', 'Workflow execution started', [
                'task_id' => $taskId,
                'total_nodes' => count($nodes),
            ]);
            
            // Check if we need to execute immediately or let frontend handle it
            $autoExecute = (bool) ($_POST['auto_execute'] ?? false);
            
            if ($autoExecute) {
                // Execute all nodes in sequence and cleanup
                $result = $this->executeAllNodes($executionId);
                
                // Cleanup after execution completes
                $finalState = get_transient($executionId);
                if ($finalState) {
                    $this->cleanupExecution($executionId, $finalState);
                }
                
                wp_send_json_success($result);
            } else {
                // Let frontend handle node-by-node execution
                wp_send_json_success([
                    'execution_id' => $executionId,
                    'message' => 'Workflow execution started',
                    'total_nodes' => count($nodes),
                ]);
            }
            
        } catch (Exception $e) {
            error_log('WAIC Execute Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Execute single node - STANDALONE (với workflow context để lấy listener data)
     */
    public function executeNode() {
        try {
            check_ajax_referer('waic-nonce', 'nonce');
            
            $nodeId = sanitize_text_field($_POST['node_id'] ?? '');
            $nodeType = sanitize_text_field($_POST['node_type'] ?? '');
            $nodeCode = sanitize_text_field($_POST['node_code'] ?? '');
            $settings = json_decode(stripslashes($_POST['settings'] ?? '{}'), true);
            $nodes = json_decode(stripslashes($_POST['nodes'] ?? '[]'), true);
            $edges = json_decode(stripslashes($_POST['edges'] ?? '[]'), true);
            
            if (empty($nodeCode)) {
                wp_send_json_error(['message' => 'Node code is required']);
                return;
            }
            
            // Create temporary node structure
            $node = [
                'id' => $nodeId,
                'type' => $nodeType,
                'data' => [
                    'code' => $nodeCode,
                    'settings' => $settings,
                ],
            ];
            
            // Build execution state với workflow context
            $executionState = [
                'task_id' => 0,
                'nodes' => $nodes,
                'edges' => $edges,
                'variables' => [],
                'test_data' => [],
                'ancestor_data' => [], // NEW: Store data from ALL ancestor nodes
            ];
            
            // Lấy data từ TẤT CẢ ancestor nodes (triggers, actions, logic)
            $ancestorData = $this->getListenerDataForNode($nodeId, $nodes, $edges);
            if (!empty($ancestorData)) {
                error_log('[WAIC Execute] Found data from ' . count($ancestorData) . ' ancestor nodes');
                $executionState['ancestor_data'] = $ancestorData;
            } else {
                error_log('[WAIC Execute] No ancestor data found');
            }
            
            error_log('[WAIC Execute] Standalone execution for node: ' . $nodeCode);
            error_log('[WAIC Execute] Settings: ' . json_encode($settings));
            error_log('[WAIC Execute] Ancestor data: ' . json_encode($executionState['ancestor_data']));
            
            // Execute the node
            $result = $this->executeNodeAction('standalone_' . time(), $node, $executionState);
            
            if ($result['success']) {
                wp_send_json_success([
                    'node_id' => $nodeId,
                    'status' => 'success',
                    'data' => $result['data'],
                    'message' => $result['message'] ?? 'Node executed successfully',
                ]);
            } else {
                wp_send_json_error([
                    'node_id' => $nodeId,
                    'status' => 'error',
                    'message' => $result['message'],
                ]);
            }
            
        } catch (Exception $e) {
            error_log('WAIC Execute Node Error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Execute node action - REAL EXECUTION
     */
    private function executeNodeAction($executionId, $node, $executionState) {
        $nodeId = $node['id'];
        $nodeType = $node['type'];
        $nodeData = $node['data'] ?? [];
        $nodeCode = $nodeData['code'] ?? '';
        $settings = $nodeData['settings'] ?? [];
        
        // Get available variables from previous nodes
        $variables = $this->buildVariablesArray($executionState, $nodeId);
        
        error_log('[WAIC Execute] Built variables for node ' . $nodeId . ': ' . json_encode($variables));
        
        try {
            // Get workflow module
            $frame = WaicFrame::_();
            $workflowMod = $frame->getModule('workflow');
            
            if (!$workflowMod) {
                throw new Exception('Workflow module not found');
            }
            
            if ($nodeType === 'trigger') {
                // For triggers, check listener first for real data, fallback to test data
                $testData = $this->getListenerData($nodeId, $executionState);
                
                // Load base classes for trigger (same pattern as action nodes)
                $baseClasses = [
                    dirname(__FILE__) . '/../../../classes/baseObject.php' => 'WaicBaseObject',
                    dirname(__FILE__) . '/../../../classes/builderBlock.php' => 'WaicBuilderBlock',
                    dirname(__FILE__) . '/../blocks/trigger.php' => 'WaicTrigger',
                ];
                foreach ($baseClasses as $path => $className) {
                    if (file_exists($path) && !class_exists($className)) {
                        require_once $path;
                    }
                }
                
                // For subtype 0 (manual/instant) triggers: run controlRun() for full output
                if (!empty($nodeCode)) {
                    $triggerClassName = 'WaicTrigger_' . $nodeCode;
                    if (!class_exists($triggerClassName)) {
                        $triggerFilePath = dirname(__FILE__) . '/../blocks/triggers/' . $nodeCode . '.php';
                        if (file_exists($triggerFilePath)) {
                            require_once $triggerFilePath;
                        }
                    }
                    if (!class_exists($triggerClassName)) {
                        $extPaths = WaicDispatcher::applyFilters('getExternalBlocksPaths', []);
                        foreach ($extPaths as $extPath) {
                            $extFile = rtrim($extPath, '/\\') . '/triggers/' . $nodeCode . '.php';
                            if (file_exists($extFile)) {
                                require_once $extFile;
                                break;
                            }
                        }
                    }
                    if (class_exists($triggerClassName)) {
                        $triggerBlock = new $triggerClassName($node);
                        if ($triggerBlock->getSubtype() === 0) {
                            $testData = $triggerBlock->controlRun($testData);
                        }
                    }
                }
                
                return [
                    'success' => true,
                    'data' => $testData,
                    'message' => 'Trigger executed with real data',
                ];
            }
            
            if ($nodeType === 'action' && !empty($nodeCode)) {
                // Create action block instance GIỐNG workflow.php
                $actionClassName = 'WaicAction_' . $nodeCode;
                
                back_trace('NOTICE', 'Creating action block instance: ' . $actionClassName);
                
                // Load base classes in correct order
                $baseClasses = [
                    dirname(__FILE__) . '/../../../classes/baseObject.php' => 'WaicBaseObject',
                    dirname(__FILE__) . '/../../../classes/builderBlock.php' => 'WaicBuilderBlock',
                    dirname(__FILE__) . '/../blocks/action.php' => 'WaicAction',
                ];
                
                foreach ($baseClasses as $path => $className) {
                    if (file_exists($path) && !class_exists($className)) {
                        require_once $path;
                    }
                }
                
                // Check if class exists, if not try to load it
                if (!class_exists($actionClassName)) {
                    // 1. Built-in blocks path
                    $actionFilePath = dirname(__FILE__) . '/../blocks/actions/' . $nodeCode . '.php';
                    if (file_exists($actionFilePath)) {
                        require_once $actionFilePath;
                    }
                }
                
                // 2. External plugin paths (bizcity-bot-webchat, etc.)
                if (!class_exists($actionClassName)) {
                    $extPaths = WaicDispatcher::applyFilters('getExternalBlocksPaths', []);
                    foreach ($extPaths as $extPath) {
                        $extFile = rtrim($extPath, '/\\') . '/actions/' . $nodeCode . '.php';
                        if (file_exists($extFile)) {
                            require_once $extFile;
                            break;
                        }
                    }
                }
                
                if (!class_exists($actionClassName)) {
                    throw new Exception("Action class not found: {$actionClassName}");
                }
                
                // QUAN TRỌNG: Pass TOÀN BỘ node object vào constructor như workflow.php
                // Constructor sẽ gọi setBlock() và settings tự động load từ node['data']['settings']
                $actionBlock = new $actionClassName($node);
                
                if (!($actionBlock instanceof WaicAction)) {
                    throw new Exception("Action block is not instance of WaicAction: {$actionClassName}");
                }
                
                // Execute action với variables đã build (có nested structure)
                $taskId = $executionState['task_id'];
                
                // Set run_id để block có thể trigger resume (HIL, delay, etc.)
                $actionBlock->setRunId($executionId);
                
                // ════════════════════════════════════════════════════════════
                // Phase 1.1 — PRE-EXECUTE MIDDLEWARE (only when pipeline_id)
                // Handles: HIL slot gathering, variable injection, confirm
                // Protected: middleware crash → block runs normally
                // Skip when _direct_pipeline is set (BFS loop handles it directly)
                // ════════════════════════════════════════════════════════════
                $pipeline_id = $executionState['pipeline_id'] ?? '';
                $skip_filter_mw = ! empty( $executionState['_direct_pipeline'] );
                if ( ! empty( $pipeline_id ) && ! $skip_filter_mw ) {
                    try {
                        $pre_context = apply_filters( 'waic_pipeline_pre_execute', [
                            'proceed'   => true,
                            'node'      => $node,
                            'variables' => $variables,
                            'block'     => $actionBlock,
                        ], $pipeline_id, $nodeCode, $executionState );
                    } catch ( \Throwable $mw_ex ) {
                        error_log( '[Pipeline MW] pre_execute filter CRASHED: ' . $mw_ex->getMessage() );
                        $pre_context = [
                            'proceed'   => true,
                            'node'      => $node,
                            'variables' => $variables,
                            'block'     => $actionBlock,
                        ];
                    }

                    // HIL waiting → pause pipeline (un_confirm pattern)
                    if ( ! empty( $pre_context['waiting'] ) ) {
                        // Persist waiting state into transient so resumeExecution() can find it
                        $executionState['status']        = 'waiting';
                        $executionState['waiting_until']  = $pre_context['waiting'];
                        $executionState['current_node']   = $node['id'];
                        $executionState['waiting_status']  = 0;
                        set_transient( $executionId, $executionState, 3600 );

                        return [
                            'success' => true,
                            'data'    => [
                                'status'  => 0,
                                'waiting' => $pre_context['waiting'],
                                'result'  => $pre_context['result'] ?? [],
                            ],
                        ];
                    }

                    // User rejected this step → skip block execution entirely
                    if ( isset( $pre_context['proceed'] ) && $pre_context['proceed'] === false ) {
                        return [
                            'success' => true,
                            'data'    => [
                                'status'       => 3,
                                'result'       => [ 'skipped' => true, 'reason' => 'user_rejected' ],
                                'sourceHandle' => 'output-right',
                            ],
                        ];
                    }

                    // Middleware injected data into node settings → re-instantiate
                    if ( ! empty( $pre_context['injected_node'] ) ) {
                        $node        = $pre_context['injected_node'];
                        $actionBlock = new $actionClassName( $node );
                        $actionBlock->setRunId( $executionId );
                    }
                }

                error_log('[WAIC Execute] About to call getResults() for: ' . $nodeCode);
                error_log('[WAIC Execute] TaskId: ' . $taskId);
                error_log('[WAIC Execute] Variables count: ' . count($variables));
                
                $result = $actionBlock->getResults($taskId, $variables, 0);
                
                error_log('[WAIC Execute] getResults() returned for: ' . $nodeCode);
                error_log('[WAIC Execute] Result keys: ' . json_encode(array_keys($result)));
                
                // ════════════════════════════════════════════════════════════
                // Phase 1.1 — POST-EXECUTE MIDDLEWARE (only when pipeline_id)
                // Handles: evidence save, verify, todos checkpoint, notify
                // Protected: middleware crash → original result preserved
                // Skip when _direct_pipeline is set (BFS loop handles it)
                // ════════════════════════════════════════════════════════════
                if ( ! empty( $pipeline_id ) && ! $skip_filter_mw ) {
                    try {
                        $result = apply_filters( 'waic_pipeline_post_execute',
                            $result, $pipeline_id, $nodeCode, $node, $executionState
                        );
                    } catch ( \Throwable $mw_ex ) {
                        error_log( '[Pipeline MW] post_execute filter CRASHED: ' . $mw_ex->getMessage() );
                        // $result remains untouched — block output preserved
                    }
                }
                
                $this->addLog($executionId, 'NOTICE', "Action result for {$nodeCode}", [
                    'raw_result' => $result,
                ]);
                
                // Check if execution was successful
                if (isset($result['error']) && !empty($result['error'])) {
                    return [
                        'success' => false,
                        'message' => $result['error'],
                    ];
                }
                
                // Extract output variables from result
                $outputData = [];
                if (isset($result['result']) && is_array($result['result'])) {
                    $outputData = $result['result'];
                } else {
                    foreach ($result as $key => $value) {
                        if ($key !== 'error' && $key !== 'status') {
                            $outputData[$key] = $value;
                        }
                    }
                }
                
                // Convert nested arrays to JSON strings (giống workflow.php xử lý)
                // Điều này đảm bảo {{node#13.checklist_json}} sẽ ra JSON string chứ không phải "Array,Array"
                foreach ($outputData as $key => $value) {
                    if (is_array($value) && $this->isNestedArray($value)) {
                        $outputData[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                }
                
                // LƯU KẾT QUẢ vào transient để nodes tiếp theo có thể lấy (giống workflow.php)
                // Lưu vào global transient với key 'waic_trigger_data_{nodeId}'
                if (!empty($outputData) && !empty($nodeId)) {
                    set_transient('waic_trigger_data_' . $nodeId, $outputData, 300);
                    error_log('[WAIC Execute] Saved action result to transient for node: ' . $nodeId);
                    error_log('[WAIC Execute] Output data keys: ' . json_encode(array_keys($outputData)));
                }
                
                return [
                    'success' => true,
                    'data' => $outputData,
                    'message' => "Action {$nodeCode} executed successfully",
                ];
            }
            
            if ($nodeType === 'logic' && !empty($nodeCode)) {
                // Create logic block instance GIỐNG workflow.php
                $logicClassName = 'WaicLogic_' . $nodeCode;
                
                back_trace('NOTICE', 'Creating logic block instance: ' . $logicClassName);
                
                // Load base classes in correct order
                $baseClasses = [
                    dirname(__FILE__) . '/../../../classes/baseObject.php' => 'WaicBaseObject',
                    dirname(__FILE__) . '/../../../classes/builderBlock.php' => 'WaicBuilderBlock',
                    dirname(__FILE__) . '/../blocks/logic.php' => 'WaicLogic',
                ];
                
                foreach ($baseClasses as $path => $className) {
                    if (file_exists($path) && !class_exists($className)) {
                        require_once $path;
                    }
                }
                
                // Check if class exists, if not try to load it
                if (!class_exists($logicClassName)) {
                    $logicFilePath = dirname(__FILE__) . '/../blocks/logics/' . $nodeCode . '.php';
                    if (file_exists($logicFilePath)) {
                        require_once $logicFilePath;
                    }
                }
                
                if (!class_exists($logicClassName)) {
                    throw new Exception("Logic class not found: {$logicClassName}");
                }
                
                // QUAN TRỌNG: Pass TOÀN BỘ node object vào constructor
                $logicBlock = new $logicClassName($node);
                
                // Execute logic với variables đã build (có nested structure)
                $taskId = $executionState['task_id'];
                
                // Set run_id để block có thể trigger resume (HIL, delay, etc.)
                $logicBlock->setRunId($executionId);
                
                $result = $logicBlock->getResults($taskId, $variables, 0);
                
                $this->addLog($executionId, 'NOTICE', "Logic result for {$nodeCode}", [
                    'raw_result' => $result,
                ]);
                
                return [
                    'success' => true,
                    'data' => $result ?? [],
                    'message' => "Logic {$nodeCode} executed successfully",
                ];
            }
            
            throw new Exception("Unknown node type: {$nodeType}");
            
        } catch (Exception $e) {
            // Fire failure hook so middleware can update todos + save error evidence
            // Skip when _direct_pipeline is set (BFS loop handles errors)
            if ( ! empty( $pipeline_id ) && ! $skip_filter_mw ) {
                do_action( 'waic_pipeline_node_failed', $pipeline_id, $nodeCode, $node, $e->getMessage(), $executionState );
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Execute logic node (IF, Switch, etc.)
     */
    private function executeLogicNode($executionId, $node, $variables) {
        $nodeCode = $node['data']['code'] ?? '';
        
        // Simple IF logic implementation
        if ($nodeCode === 'if_condition') {
            $condition = $node['data']['settings']['condition'] ?? '';
            $condition = $this->replaceVariablesInString($condition, $variables);
            
            // Evaluate condition (simple string comparison)
            $result = $this->evaluateCondition($condition);
            
            return [
                'success' => true,
                'data' => [
                    'condition_result' => $result,
                    'output' => $result ? 'true' : 'false',
                ],
            ];
        }
        
        return [
            'success' => true,
            'data' => ['executed' => true],
        ];
    }
    
    /**
     * Execute all nodes in workflow sequentially (for auto_execute mode)
     */
    private function executeAllNodes($executionId) {
        $executionState = get_transient($executionId);
        
        if (!$executionState) {
            return ['success' => false, 'message' => 'Execution state not found'];
        }
        
        $nodes = $executionState['nodes'];
        $edges = $executionState['edges'];
        
        // Find trigger node (starting point)
        $triggerNode = null;
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'trigger') {
                $triggerNode = $node;
                break;
            }
        }
        
        if (!$triggerNode) {
            return ['success' => false, 'message' => 'No trigger node found'];
        }
        
        // Execute nodes using BFS
        $queue = [$triggerNode['id']];
        $visited = [];
        $executionOrder = [];
        
        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);
            
            if (in_array($currentNodeId, $visited)) {
                continue;
            }
            
            $visited[] = $currentNodeId;
            
            // Find node by ID
            $currentNode = null;
            foreach ($nodes as $node) {
                if ($node['id'] === $currentNodeId) {
                    $currentNode = $node;
                    break;
                }
            }
            
            if (!$currentNode) {
                continue;
            }
            
            // Execute node
            $result = $this->executeNodeAction($executionId, $currentNode, $executionState);
            
            // ⭐ GIỐNG WORKFLOW.PHP: Check delay/HIL bằng cách kiểm tra 'waiting' key trực tiếp
            // workflow.php line 926-929: if (!empty($results['waiting']))
            $nodeResults = $result['data'] ?? [];
            
            // Check if this is a delay/HIL node (status=0, waiting timestamp set)
            if ($result['success'] && !empty($nodeResults['waiting'])) {
                $waiting_timestamp = $nodeResults['waiting'];
                $status = $nodeResults['status'] ?? 0;
                
                error_log('[ExecuteAPI] DELAY/HIL detected! Status: ' . $status . ', Waiting until: ' . date('Y-m-d H:i:s', $waiting_timestamp));
                
                $executionState['status'] = 'waiting';
                $executionState['waiting_until'] = $waiting_timestamp;
                $executionState['waiting_status'] = $status; // 0=waiting, 3=completed
                $executionState['current_node'] = $currentNodeId;
                $executionState['last_sourceHandle'] = $nodeResults['sourceHandle'] ?? 'output-right';
                set_transient($executionId, $executionState, 3600);
                
                return [
                    'success' => true,
                    'paused' => true,
                    'message' => 'Workflow paused (delay/HIL) - waiting until: ' . date('Y-m-d H:i:s', $waiting_timestamp),
                    'waiting_until' => $waiting_timestamp,
                    'status' => $status,
                    'execution_order' => $executionOrder,
                ];
            }
            
            // Check for errors (status=7)
            if ($result['success']) {
                $status = $nodeResults['status'] ?? 3; // Default: success
                
                if ($status === 7) {
                    // Error status
                    $executionState['node_status'][$currentNodeId] = 'error';
                    $executionState['error'] = $nodeResults['error'] ?? 'Unknown error';
                    $executionState['status'] = 'failed';
                    set_transient($executionId, $executionState, 3600);
                    
                    return [
                        'success' => false,
                        'message' => 'Node execution failed: ' . $currentNodeId,
                        'error' => $executionState['error'],
                        'execution_order' => $executionOrder,
                    ];
                }
                
                // Success - save variables and continue
                $executionState['node_status'][$currentNodeId] = 'success';
                $executionState['variables'][$currentNodeId] = $nodeResults['result'] ?? $nodeResults;
                $executionOrder[] = $currentNodeId;
                
                // Get sourceHandle for branch routing (default: output-right)
                $sourceHandle = $nodeResults['sourceHandle'] ?? 'output-right';
                $executionState['last_sourceHandle'] = $sourceHandle;
                
            } else {
                // executeNodeAction failed
                $executionState['node_status'][$currentNodeId] = 'error';
                $executionState['error'] = $result['message'];
                $executionState['status'] = 'failed';
                set_transient($executionId, $executionState, 3600);
                
                return [
                    'success' => false,
                    'message' => 'Execution failed at node: ' . $currentNodeId,
                    'error' => $result['message'],
                    'execution_order' => $executionOrder,
                ];
            }
            
            // Update state
            set_transient($executionId, $executionState, 3600);
            
            // Find next nodes - respect sourceHandle for branch routing
            $currentSourceHandle = $executionState['last_sourceHandle'] ?? 'output-right';
            foreach ($edges as $edge) {
                $edgeSourceHandle = $edge['sourceHandle'] ?? 'output-right';
                
                // Match source node AND sourceHandle
                if ($edge['source'] === $currentNodeId 
                    && $edgeSourceHandle === $currentSourceHandle 
                    && !in_array($edge['target'], $visited)) {
                    $queue[] = $edge['target'];
                    error_log('[ExecuteAPI] Next node via ' . $currentSourceHandle . ': ' . $edge['target']);
                }
            }
        }
        
        // Mark as completed
        $executionState['status'] = 'completed';
        set_transient($executionId, $executionState, 3600);
        
        return [
            'success' => true,
            'message' => 'Workflow executed successfully',
            'execution_order' => $executionOrder,
            'total_nodes' => count($executionOrder),
        ];
    }
    
    /**
     * Build node → step_index map using BFS traversal order.
     * Used by Phase 1.1 pipeline middleware for evidence step tracking.
     *
     * @param array $nodes Workflow nodes.
     * @param array $edges Workflow edges.
     * @return array [ node_id => step_index ]
     */
    private function buildNodeStepMap( array $nodes, array $edges ) {
        $map = [];

        // Find trigger node (BFS root)
        $triggerId = null;
        foreach ( $nodes as $n ) {
            if ( ( $n['type'] ?? '' ) === 'trigger' ) {
                $triggerId = $n['id'];
                break;
            }
        }
        if ( ! $triggerId ) {
            // Fallback: assign by array order
            foreach ( $nodes as $i => $n ) {
                $map[ $n['id'] ] = $i;
            }
            return $map;
        }

        // Build adjacency from edges
        $adj = [];
        foreach ( $edges as $edge ) {
            $adj[ $edge['source'] ][] = $edge['target'];
        }

        // BFS
        $queue   = [ $triggerId ];
        $visited = [];
        $step    = 0;
        while ( ! empty( $queue ) ) {
            $id = array_shift( $queue );
            if ( in_array( $id, $visited, true ) ) {
                continue;
            }
            $visited[]  = $id;
            $map[ $id ] = $step++;
            foreach ( $adj[ $id ] ?? [] as $child ) {
                $queue[] = $child;
            }
        }

        return $map;
    }

    /**
     * Build variables array from previous nodes
     */
    private function buildVariablesArray($executionState, $currentNodeId) {
        $variables = [];
        
        // PRIORITY 1: Add data from ancestor nodes (từ getListenerDataForNode)
        // Đây là data thật từ các nodes đã execute trước đó
        if (!empty($executionState['ancestor_data'])) {
            foreach ($executionState['ancestor_data'] as $nodeKey => $nodeData) {
                // $nodeKey đã có format 'node#13', 'node#5'...
                $variables[$nodeKey] = $nodeData;
                
                // Also add flat for backward compatibility
                foreach ($nodeData as $key => $value) {
                    if (!isset($variables[$key])) {
                        $variables[$key] = $value;
                    }
                }
            }
            error_log('[WAIC Execute] Built variables from ' . count($executionState['ancestor_data']) . ' ancestor nodes');
        }
        
        // PRIORITY 2: Add test data as trigger variables (backward compatibility)
        if (!empty($executionState['test_data'])) {
            // Tìm trigger node ID để add prefix đúng
            $triggerNodeId = null;
            if (!empty($executionState['nodes'])) {
                foreach ($executionState['nodes'] as $node) {
                    if (($node['type'] ?? '') === 'trigger') {
                        $triggerNodeId = $node['id'];
                        break;
                    }
                }
            }
            
            // Add NESTED STRUCTURE cho replaceVariables()
            foreach ($executionState['test_data'] as $key => $value) {
                if (!isset($variables[$key])) {
                    $variables[$key] = $value; // Flat
                }
                
                if ($triggerNodeId) {
                    $nodeKey = "node#{$triggerNodeId}";
                    if (!isset($variables[$nodeKey])) {
                        $variables[$nodeKey] = [];
                    }
                    if (!isset($variables[$nodeKey][$key])) {
                        $variables[$nodeKey][$key] = $value;
                    }
                }
            }
            
            error_log('[WAIC Execute] Added test_data for backward compatibility');
        }
        
        // PRIORITY 3: Add variables from completed nodes (trong execution chain)
        if (!empty($executionState['variables'])) {
            foreach ($executionState['variables'] as $nodeKeyOrId => $nodeVars) {
                // Skip current node
                if ($nodeKeyOrId === $currentNodeId || $nodeKeyOrId === 'node#' . $currentNodeId) {
                    continue;
                }
                
                // Normalize to node#ID format
                $nodeKey = (strpos($nodeKeyOrId, 'node#') === 0) ? $nodeKeyOrId : 'node#' . $nodeKeyOrId;
                
                if (!isset($variables[$nodeKey])) {
                    $variables[$nodeKey] = $nodeVars;
                } else {
                    // Merge if already exists from ancestor_data
                    $variables[$nodeKey] = array_merge($variables[$nodeKey], $nodeVars);
                }
                
                // Also add flat for backward compatibility
                if (is_array($nodeVars)) {
                    foreach ($nodeVars as $key => $value) {
                        if (!isset($variables[$key])) {
                            $variables[$key] = $value;
                        }
                    }
                }
                
                error_log('[WAIC Execute] Added variables from ' . $nodeKey);
            }
        }
        
        // Inject execution state meta so blocks (planner, verifier) can access them
        $variables['_pipeline_id']            = $executionState['pipeline_id'] ?? '';
        $variables['_session_id']             = $executionState['session_id'] ?? '';
        $variables['_user_id']                = $executionState['user_id'] ?? 0;
        $variables['_channel']                = $executionState['channel'] ?? 'adminchat';
        $variables['_intent_conversation_id'] = $executionState['intent_conversation_id'] ?? '';
        
        return $variables;
    }
    private function replaceVariablesInString($string, $variables) {
        foreach ($variables as $key => $value) {
            $string = str_replace("{{" . $key . "}}", $value, $string);
        }
        return $string;
    }
    
    /**
     * Evaluate simple condition
     */
    private function evaluateCondition($condition) {
        // Simple evaluation: check if condition contains common operators
        if (preg_match('/(.+)(==|!=|>|<|>=|<=)(.+)/', $condition, $matches)) {
            $left = trim($matches[1]);
            $operator = $matches[2];
            $right = trim($matches[3]);
            
            switch ($operator) {
                case '==':
                    return $left == $right;
                case '!=':
                    return $left != $right;
                case '>':
                    return $left > $right;
                case '<':
                    return $left < $right;
                case '>=':
                    return $left >= $right;
                case '<=':
                    return $left <= $right;
            }
        }
        
        // If no operator, check if value is truthy
        return !empty($condition);
    }
    
    /**
     * Get execution status
     */
    public function getExecutionStatus() {
        check_ajax_referer('waic-nonce', 'nonce');
        
        $executionId = sanitize_text_field($_POST['execution_id'] ?? '');
        
        if (empty($executionId)) {
            wp_send_json_error(['message' => 'Execution ID is required']);
            return;
        }
        
        $executionState = get_transient($executionId);
        
        if (!$executionState) {
            wp_send_json_error(['message' => 'Execution not found or expired']);
            return;
        }
        
        // If execution is complete, cleanup
        if (in_array($executionState['status'], ['completed', 'failed', 'stopped'])) {
            // Return final status first
            $response = [
                'execution_id' => $executionId,
                'status' => $executionState['status'],
                'current_node' => $executionState['current_node'],
                'node_status' => $executionState['node_status'],
                'logs' => array_slice($executionState['logs'], -50),
                'error' => $executionState['error'],
                'cleaned_up' => false,
            ];
            
            // Cleanup in background
            $this->cleanupExecution($executionId, $executionState);
            $response['cleaned_up'] = true;
            
            wp_send_json_success($response);
        } else {
            wp_send_json_success([
                'execution_id' => $executionId,
                'status' => $executionState['status'],
                'current_node' => $executionState['current_node'],
                'node_status' => $executionState['node_status'],
                'logs' => array_slice($executionState['logs'], -50),
                'error' => $executionState['error'],
            ]);
        }
    }
    
    /**
     * Stop execution
     */
    public function stopExecution() {
        check_ajax_referer('waic-nonce', 'nonce');
        
        $executionId = sanitize_text_field($_POST['execution_id'] ?? '');
        
        if (empty($executionId)) {
            wp_send_json_error(['message' => 'Execution ID is required']);
            return;
        }
        
        $executionState = get_transient($executionId);
        
        if ($executionState) {
            $executionState['status'] = 'stopped';
            $this->addLog($executionId, 'NOTICE', 'Execution stopped by user');
            set_transient($executionId, $executionState, 3600);
            
            // Cleanup
            $this->cleanupExecution($executionId, $executionState);
        }
        
        wp_send_json_success(['message' => 'Execution stopped']);
    }
    
    /**
     * Get real data from listener if available
     */
    private function getListenerData($nodeId, $executionState) {
        // Check if there's a listener with captured data
        $listenerId = get_option('waic_active_listener_' . $nodeId);
        
        if ($listenerId) {
            $listenerData = get_transient($listenerId);
            
            if ($listenerData && !empty($listenerData['captured_data'])) {
                error_log('[WAIC Execute] Using real captured data from listener: ' . $listenerId);
                
                // Clean up listener after using data
                delete_transient($listenerId);
                delete_option('waic_active_listener_' . $nodeId);
                
                return $listenerData['captured_data'];
            }
        }
        
        // Fallback to test data
        error_log('[WAIC Execute] No listener data, using test data');
        return $executionState['test_data'] ?? [];
    }
    
    /**
     * Tìm tất cả ancestor nodes và lấy data của chúng (cho standalone execution)
     * Bao gồm trigger nodes, action nodes, logic nodes - TẤT CẢ nodes trước node hiện tại
     */
    private function getListenerDataForNode($nodeId, $nodes, $edges) {
        if (empty($nodes) || empty($edges)) {
            error_log('[WAIC Execute] getListenerDataForNode: nodes or edges empty');
            return [];
        }
        
        // Tìm TẤT CẢ ancestor nodes (traverse backwards)
        $ancestorNodes = [];
        $visited = [];
        $queue = [$nodeId];
        
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            
            if (in_array($currentId, $visited)) {
                continue;
            }
            $visited[] = $currentId;
            
            // Tìm node hiện tại
            $currentNode = null;
            foreach ($nodes as $n) {
                if ($n['id'] === $currentId) {
                    $currentNode = $n;
                    break;
                }
            }
            
            if (!$currentNode) {
                continue;
            }
            
            // Lưu TẤT CẢ ancestors (không chỉ triggers)
            if ($currentId !== $nodeId) {
                $ancestorNodes[] = $currentNode;
                error_log('[WAIC Execute] Found ancestor node: ' . $currentId . ' (type: ' . ($currentNode['type'] ?? 'unknown') . ')');
            }
            
            // Tìm các nodes connect VÀO node này
            foreach ($edges as $edge) {
                if ($edge['target'] === $currentId && !in_array($edge['source'], $visited)) {
                    $queue[] = $edge['source'];
                }
            }
        }
        
        error_log('[WAIC Execute] Total ancestor nodes found: ' . count($ancestorNodes));
        
        // Lấy data từ TẤT CẢ ancestor nodes và merge lại
        $allData = [];
        
        foreach ($ancestorNodes as $ancestor) {
            $ancestorId = $ancestor['id'];
            $ancestorType = $ancestor['type'] ?? 'unknown';
            
            error_log('[WAIC Execute] Checking ancestor node: ' . $ancestorId . ' (type: ' . $ancestorType . ')');
            
            // Try listener first (Execute Workflow mode)
            $listenerId = get_option('waic_active_listener_' . $ancestorId);
            
            if ($listenerId) {
                $listenerData = get_transient($listenerId);
                
                if ($listenerData && !empty($listenerData['captured_data'])) {
                    error_log('[WAIC Execute] Found listener data from node: ' . $ancestorId);
                    
                    // Add data with node prefix
                    $nodeKey = 'node#' . $ancestorId;
                    $allData[$nodeKey] = $listenerData['captured_data'];
                    
                    continue; // Found data, move to next node
                }
            }
            
            // Try global transient (Execute Test standalone mode)
            $globalKey = 'waic_trigger_data_' . $ancestorId;
            $globalData = get_transient($globalKey);
            
            if ($globalData) {
                error_log('[WAIC Execute] Found global data from node: ' . $ancestorId);
                
                // Add data with node prefix
                $nodeKey = 'node#' . $ancestorId;
                $allData[$nodeKey] = $globalData;
            }
        }
        
        error_log('[WAIC Execute] Built variables from ' . count($allData) . ' ancestor nodes');
        
        return $allData;
    }
    
    /**
     * Cleanup execution transients and options
     */
    private function cleanupExecution($executionId, $executionState) {
        $taskId = $executionState['task_id'] ?? 0;
        
        error_log('[WAIC Execute] Cleaning up execution: ' . $executionId);
        
        // Delete execution transient
        delete_transient($executionId);
        
        // Delete active execution option
        if ($taskId > 0) {
            delete_option('waic_active_execution_' . $taskId);
        }
        
        // Clean up any remaining listener data for nodes in this workflow
        if (!empty($executionState['nodes'])) {
            foreach ($executionState['nodes'] as $node) {
                $nodeId = $node['id'] ?? '';
                if ($nodeId) {
                    $listenerId = get_option('waic_active_listener_' . $nodeId);
                    if ($listenerId) {
                        delete_transient($listenerId);
                        delete_option('waic_active_listener_' . $nodeId);
                    }
                }
            }
        }
        
        error_log('[WAIC Execute] Cleanup complete');
    }
    
    /**
     * Add log entry
     */
    private function addLog($executionId, $level, $message, $data = []) {
        $executionState = get_transient($executionId);
        
        if ($executionState) {
            $logEntry = [
                'timestamp' => current_time('mysql'),
                'level' => $level,
                'message' => $message,
                'data' => $data,
            ];
            
            $executionState['logs'][] = $logEntry;
            set_transient($executionId, $executionState, 3600);
            
            // Also log to PHP error log for debugging
            error_log("[WAIC Workflow] [{$level}] {$message}: " . json_encode($data));
        }
    }
    
    /**
     * Check if array is nested (contains array values)
     * Để quyết định có cần JSON encode không
     */
    private function isNestedArray($array) {
        if (!is_array($array)) {
            return false;
        }
        
        foreach ($array as $value) {
            if (is_array($value) || is_object($value)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Execute workflow in background (for test mode via AJAX)
     * Chạy toàn bộ workflow và cập nhật state realtime vào transient
     */
    public function executeWorkflowBackground($executionId) {
        $executionState = get_transient($executionId);
        
        if (!$executionState) {
            error_log('[WAIC Test] Execution state not found: ' . $executionId);
            return false;
        }
        
        if (($executionState['mode'] ?? '') !== 'test') {
            error_log('[WAIC Test] Not a test execution: ' . $executionId);
            return false;
        }
        
        // Check if still waiting for trigger
        if ($executionState['status'] === 'waiting_for_trigger') {
            error_log('[WAIC Test] Still waiting for trigger data: ' . $executionId);
            return false;
        }

        // ⭐ Guard against duplicate execution (race condition).
        // If another thread is already executing (has visited nodes),
        // don't start a second execution.
        if ( $executionState['status'] === 'running'
             && ! empty( $executionState['visited_nodes'] )
             && empty( $executionState['pending_delay_node'] ) ) {
            error_log('[WAIC Test] Execution already in progress, skipping duplicate: ' . $executionId);
            return false;
        }

        // Mark as running (idempotent if already set by ajaxTestWorkflow)
        $executionState['status'] = 'running';
        set_transient($executionId, $executionState, 3600);
        
        error_log('[WAIC Test] Starting background execution: ' . $executionId);
        
        $nodes = $executionState['nodes'] ?? [];
        $edges = $executionState['edges'] ?? [];
        
        // Lazy-build node_step_map if not set (ajaxTestWorkflowExecute doesn't have buildNodeStepMap)
        if ( empty( $executionState['node_step_map'] ) && ! empty( $nodes ) && ! empty( $edges ) ) {
            $executionState['node_step_map'] = $this->buildNodeStepMap( $nodes, $edges );
            $this->updateExecutionState( $executionId, [ 'node_step_map' => $executionState['node_step_map'] ], true );
            error_log( '[WAIC Test] Lazy-built node_step_map: ' . count( $executionState['node_step_map'] ) . ' nodes' );
        }
        
        // Find trigger node
        $triggerNode = null;
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'trigger') {
                $triggerNode = $node;
                break;
            }
        }
        
        if (!$triggerNode) {
            $this->updateExecutionState($executionId, [
                'status' => 'failed',
                'error' => 'No trigger node found',
            ]);
            return false;
        }
        
        // 🔔 Send notification when trigger is activated
        $triggerLabel = $triggerNode['data']['label'] ?? 'Unknown Trigger';
        $triggerType = $triggerNode['data']['trigger'] ?? 'unknown';
        
        // Get trigger data from execution state
        $executionState = get_transient($executionId);
        $triggerData = $executionState['variables']['trigger_data'] ?? [];
        
        // Build notification message for frontend
        $frontendMessage = "🎯 Workflow đã được kích hoạt!\n\n";
        $frontendMessage .= "Trigger: {$triggerLabel}\n";
        
        if (!empty($triggerData['text'])) {
            $text = mb_substr($triggerData['text'], 0, 100);
            if (mb_strlen($triggerData['text']) > 100) {
                $text .= '...';
            }
            $frontendMessage .= "Nội dung: {$text}\n";
        }
        
        $frontendMessage .= "\n⏳ Đang xử lý...";
        
        // Update state with notification
        $this->updateExecutionState($executionId, [
            'notification' => $frontendMessage,
        ], true);
        
        // Send Zalo notification to admin
        
        
        // Execute nodes using BFS (giống executeAllNodes nhưng với realtime updates)
        // Get execution state to restore visited nodes (persistent across resume)
        $executionState = get_transient($executionId);
        $visited = $executionState['visited_nodes'] ?? [];
        $pendingDelayNode = $executionState['pending_delay_node'] ?? null;
        
        // Determine starting queue
        if ($pendingDelayNode) {
            // Resuming from delay/HIL - start from next nodes
            error_log('[WAIC Test] Resuming from node: ' . $pendingDelayNode);
            
            $queue = [];
            // ⭐ FIX: Get sourceHandle from state (set by delay/HIL node when it returned result)
            // - Delay node: always 'output-right'
            // - HIL confirm: 'output-then' or 'output-else' based on user response
            $currentSourceHandle = $executionState['last_sourceHandle'] ?? 'output-right';
            error_log('[WAIC Test] Using sourceHandle from state: ' . $currentSourceHandle);
            
            foreach ($edges as $edge) {
                $edgeSourceHandle = $edge['sourceHandle'] ?? 'output-right';
                
                if ($edge['source'] === $pendingDelayNode 
                    && $edgeSourceHandle === $currentSourceHandle) {
                    $queue[] = $edge['target'];
                    error_log('[WAIC Test] Starting from next node via ' . $currentSourceHandle . ': ' . $edge['target']);
                }
            }
            
            if (empty($queue)) {
                error_log('[WAIC Test] No next nodes after delay, workflow complete');
                $this->updateExecutionState($executionId, [
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                ], true);
                return true;
            }
        } else {
            // Normal execution from trigger
            $queue = [$triggerNode['id']];
            error_log('[WAIC Test] Starting normal execution from trigger');
        }
        
        $executionOrder = [];
        $actionStepCounter = 0; // Tracks action-only step index (matches planner todo step_index)
        $originalBlogId = get_current_blog_id(); // Save for multisite safety
        
        while (!empty($queue)) {
            // Check if stopped
            $currentState = get_transient($executionId);
            if (($currentState['status'] ?? '') === 'stopped') {
                error_log('[WAIC Test] Execution stopped by user: ' . $executionId);
                break;
            }
            
            $currentNodeId = array_shift($queue);
            
            if (in_array($currentNodeId, $visited)) {
                error_log('[WAIC Test] Skipping visited node: ' . $currentNodeId);
                continue;
            }
            
            $visited[] = $currentNodeId;
            
            // Save visited nodes to state (persistent)
            $this->updateExecutionState($executionId, [
                'visited_nodes' => $visited,
            ], true);
            
            // Find node by ID
            $currentNode = null;
            foreach ($nodes as $node) {
                if ($node['id'] === $currentNodeId) {
                    $currentNode = $node;
                    break;
                }
            }
            
            if (!$currentNode) {
                continue;
            }
            
            // Update status: executing
            $this->updateExecutionState($executionId, [
                'current_node' => $currentNodeId,
                'node_status' => [$currentNodeId => 'executing'],
            ], true); // merge mode
            
            error_log('[WAIC Test] Executing node: ' . $currentNodeId);
            
            // Add small delay để frontend kịp poll và hiển thị status
            usleep(500000); // 0.5 second
            
            // Execute node
            $executionState = get_transient($executionId); // Refresh state
            $result = $this->executeNodeAction($executionId, $currentNode, $executionState);
            
            $nodeResults = $result['data'] ?? [];
            
            // Check delay/HIL (waiting status)
            if ($result['success'] && !empty($nodeResults['waiting'])) {
                $waiting_timestamp = $nodeResults['waiting'];
                $now = time();
                $wait_seconds = $waiting_timestamp - $now;
                
                error_log('[WAIC Test] Node waiting detected: ' . date('Y-m-d H:i:s', $waiting_timestamp));
                error_log('[WAIC Test] Wait duration: ' . $wait_seconds . ' seconds');
                
                // Mark as waiting and CONTINUE (không break)
                $this->updateExecutionState($executionId, [
                    'status' => 'waiting',
                    'waiting_until' => $waiting_timestamp,
                    'current_node' => $currentNodeId,
                    'node_status' => [$currentNodeId => 'waiting'],
                ], true);
                
                error_log('[WAIC Test] Delay node set to waiting, workflow will pause');
                
                // DON'T break - let it add next nodes to queue first
                // But mark this node as "pending_delay" so it won't execute again
                $executionState = get_transient($executionId);
                $executionState['pending_delay_node'] = $currentNodeId;
                set_transient($executionId, $executionState, 3600);
                
                // Now break to stop execution
                break;
            }
            
            // Check error (after delay check)
            if ($result['success']) {
                $status = $nodeResults['status'] ?? 3;
                
                if ($status === 7) {
                    // Error
                    $this->updateExecutionState($executionId, [
                        'status' => 'failed',
                        'error' => $nodeResults['error'] ?? 'Unknown error',
                        'node_status' => [$currentNodeId => 'error'],
                    ], true);
                    
                    error_log('[WAIC Test] Node failed: ' . $currentNodeId);
                    break;
                }
                
                // Success - FIXED: Use node#ID format to match production
                $nodeKey = 'node#' . $currentNodeId;
                $this->updateExecutionState($executionId, [
                    'node_status' => [$currentNodeId => 'success'],
                    'variables' => [$nodeKey => $nodeResults['result'] ?? $nodeResults],
                    'completed_nodes' => [$currentNodeId],
                ], true);
                
                // Capture pipeline_id from planner output → enable middleware for subsequent nodes
                $pipeline_from_result = $nodeResults['pipeline_id']
                    ?? ($nodeResults['result']['pipeline_id'] ?? '');
                if ( ! empty( $pipeline_from_result ) && empty( $executionState['pipeline_id'] ) ) {
                    $this->updateExecutionState( $executionId, [
                        'pipeline_id' => $pipeline_from_result,
                    ], true );
                    $executionState['pipeline_id'] = $pipeline_from_result;
                    error_log( '[WAIC Test] Captured pipeline_id from node output: ' . $pipeline_from_result );
                }
                
                // ════════════════════════════════════════════════════════════
                // DIRECT PIPELINE INTEGRATION — Evidence, Todos, Messenger
                // Runs inline in BFS loop for immediate feedback, bypassing
                // the filter-based middleware (which requires pipeline_id
                // at executeNodeAction call time).
                // ════════════════════════════════════════════════════════════
                $node_code = $currentNode['data']['code'] ?? '';
                $active_pipeline_id = $executionState['pipeline_id'] ?? '';
                $is_pipeline_node = in_array( $node_code, [ 'it_todos_planner', 'it_summary_verifier' ], true );
                
                if ( ! empty( $active_pipeline_id ) && ! $is_pipeline_node && ! empty( $node_code ) ) {
                    // Refresh execution state but PRESERVE critical keys set during this run
                    $local_session_id = $executionState['session_id'] ?? '';
                    $local_user_id    = $executionState['user_id'] ?? 0;
                    $local_channel    = $executionState['channel'] ?? 'adminchat';
                    $freshState = get_transient( $executionId );
                    if ( $freshState ) {
                        $executionState = array_merge( $executionState, $freshState );
                    }
                    // Restore critical keys (transient merge may have lost them)
                    if ( empty( $executionState['session_id'] ) && ! empty( $local_session_id ) ) {
                        $executionState['session_id'] = $local_session_id;
                    }
                    if ( empty( $executionState['user_id'] ) && ! empty( $local_user_id ) ) {
                        $executionState['user_id'] = $local_user_id;
                    }
                    if ( empty( $executionState['channel'] ) && ! empty( $local_channel ) ) {
                        $executionState['channel'] = $local_channel;
                    }
                    
                    error_log( '[WAIC Test] Direct integration for ' . $node_code . ' | pipeline=' . $active_pipeline_id . ' | session=' . ( $executionState['session_id'] ?? 'EMPTY' ) . ' | user=' . ( $executionState['user_id'] ?? 0 ) );
                    
                    $result_data = $nodeResults['result'] ?? $nodeResults;
                    // If result_data is empty but nodeResults has direct data, use nodeResults itself
                    if ( empty( $result_data ) && ! empty( $nodeResults ) ) {
                        $result_data = $nodeResults;
                    }
                    $step_index  = $actionStepCounter++;  // Action-only index matching planner todos
                    $node_success = empty( $nodeResults['error'] ) && ( ( $nodeResults['status'] ?? 3 ) === 3 );
                    $verified = false;
                    
                    // Resolve effective tool name for todos matching:
                    // it_call_tool returns actual tool (e.g. write_article) in result
                    $effective_tool = $nodeResults['tool_name']
                        ?? ( $nodeResults['result']['tool_name'] ?? $node_code );
                    
                    // --- Evidence Save ---
                    $matched_todo_id = 0;
                    try {
                        if ( class_exists( 'BizCity_Intent_Pipeline_Evidence' ) ) {
                            $ev_id = BizCity_Intent_Pipeline_Evidence::save( [
                                'pipeline_id' => $active_pipeline_id,
                                'step_index'  => $step_index,
                                'tool_name'   => $effective_tool,
                                'user_id'     => $executionState['user_id'] ?? 0,
                                'session_id'  => $executionState['session_id'] ?? '',
                                'result'      => is_array( $result_data ) ? $result_data : [],
                                'verified'    => $verified,
                            ] );
                            error_log( '[WAIC Test] Evidence saved: ' . ( $ev_id ?: 'failed' ) . ' for ' . $effective_tool );

                            // Link todo_id ↔ evidence conversation
                            if ( $ev_id && class_exists( 'BizCity_Intent_Todos' ) ) {
                                global $wpdb;
                                $todos_table = BizCity_Intent_Database::instance()->todos_table();
                                $conv_table  = BizCity_Intent_Database::instance()->conversations_table();
                                // Find matching todo by pipeline_id + step_index
                                $matched_todo_id = (int) $wpdb->get_var( $wpdb->prepare(
                                    "SELECT id FROM {$todos_table} WHERE pipeline_id = %s AND step_index = %d LIMIT 1",
                                    $active_pipeline_id, $step_index
                                ) );
                                if ( $matched_todo_id > 0 ) {
                                    $wpdb->update( $conv_table, [ 'todo_id' => $matched_todo_id ], [ 'conversation_id' => $ev_id ] );
                                }
                            }
                        }
                    } catch ( \Throwable $e ) {
                        error_log( '[WAIC Test] Evidence save error: ' . $e->getMessage() );
                    }
                    
                    // --- Todos Update ---
                    try {
                        if ( class_exists( 'BizCity_Intent_Todos' ) ) {
                            $todo_status = $node_success ? 'COMPLETED' : 'FAILED';
                            $todo_score  = $node_success ? 75 : 0;
                            $output_parts = [];
                            if ( is_array( $result_data ) ) {
                                if ( ! empty( $result_data['post_id'] ) )  $output_parts[] = 'post #' . $result_data['post_id'];
                                if ( ! empty( $result_data['post_url'] ) ) $output_parts[] = $result_data['post_url'];
                                if ( ! empty( $result_data['url'] ) )      $output_parts[] = $result_data['url'];
                                if ( ! empty( $result_data['message'] ) )  $output_parts[] = mb_substr( $result_data['message'], 0, 100 );
                            }
                            BizCity_Intent_Todos::update_status(
                                $active_pipeline_id,
                                $effective_tool,
                                $todo_status,
                                [
                                    'score'          => $todo_score,
                                    'output_summary' => implode( ' | ', $output_parts ),
                                    'error_message'  => $nodeResults['error'] ?? '',
                                    'step_index'     => $step_index,
                                ]
                            );
                            error_log( '[WAIC Test] Todo updated: ' . $effective_tool . ' → ' . $todo_status );
                        }
                    } catch ( \Throwable $e ) {
                        error_log( '[WAIC Test] Todo update error: ' . $e->getMessage() );
                    }
                    
                    // --- Messenger: Send node result to chat ---
                    try {
                        // Ensure correct blog context (it_call_tool may have switched blogs)
                        if ( is_multisite() && get_current_blog_id() !== $originalBlogId ) {
                            error_log( '[WAIC Test] Blog switch detected! Current=' . get_current_blog_id() . ' Expected=' . $originalBlogId . ' — restoring' );
                            switch_to_blog( $originalBlogId );
                        }
                        
                        // Set intent_conversation_id to evidence conv_id for HIL scoping
                        if ( ! empty( $ev_id ) ) {
                            $executionState['intent_conversation_id'] = $ev_id;
                        }
                        
                        $msg_session = $executionState['session_id'] ?? '';
                        if ( empty( $msg_session ) ) {
                            error_log( '[WAIC Test] Messenger SKIPPED — session_id EMPTY for ' . $effective_tool );
                        }
                        
                        if ( class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $msg_session ) ) {
                            $total_steps = 0;
                            // Count non-planner/verifier action nodes
                            foreach ( $executionState['nodes'] ?? [] as $n ) {
                                $nc = $n['data']['code'] ?? '';
                                if ( ( $n['type'] ?? '' ) === 'action' && ! in_array( $nc, [ 'it_todos_planner', 'it_summary_verifier' ], true ) ) {
                                    $total_steps++;
                                }
                            }
                            if ( $total_steps < 1 ) $total_steps = $actionStepCounter; // fallback
                            
                            $msg_result = false;
                            if ( $node_success ) {
                                $msg_result = BizCity_Pipeline_Messenger::send_node_result(
                                    $executionState,
                                    $effective_tool,
                                    is_array( $result_data ) ? $result_data : [],
                                    $step_index,
                                    $total_steps,
                                    $matched_todo_id
                                );
                            } else {
                                $msg_result = BizCity_Pipeline_Messenger::send_error(
                                    $executionState,
                                    $effective_tool,
                                    $nodeResults['error'] ?? 'Unknown error',
                                    $step_index,
                                    $total_steps,
                                    $matched_todo_id
                                );
                            }
                            error_log( '[WAIC Test] Messenger sent for ' . $effective_tool . ' | result=' . var_export( $msg_result, true ) . ' | blog=' . get_current_blog_id() );
                            
                            // Check for DB error (send returns false or log_message returns false)
                            if ( $msg_result === false ) {
                                global $wpdb;
                                error_log( '[WAIC Test] Messenger FAILED for ' . $effective_tool . ' | wpdb_error: ' . ( $wpdb->last_error ?: 'none' ) . ' | table_prefix: ' . $wpdb->prefix );
                            }
                        }
                    } catch ( \Throwable $e ) {
                        error_log( '[WAIC Test] Messenger error: ' . $e->getMessage() );
                    }
                }
                
                $executionOrder[] = $currentNodeId;
                $executionState['last_sourceHandle'] = $nodeResults['sourceHandle'] ?? 'output-right';
                
                error_log('[WAIC Test] Node success: ' . $currentNodeId);
                
                // Add small delay sau mỗi node để frontend kịp update
                usleep(300000); // 0.3 second
                
            } else {
                // Execution failed
                $this->updateExecutionState($executionId, [
                    'status' => 'failed',
                    'error' => $result['message'],
                    'node_status' => [$currentNodeId => 'error'],
                ], true);
                
                error_log('[WAIC Test] Node execution error: ' . $result['message']);
                break;
            }
            
            // Find next nodes
            $currentSourceHandle = $executionState['last_sourceHandle'] ?? 'output-right';
            foreach ($edges as $edge) {
                $edgeSourceHandle = $edge['sourceHandle'] ?? 'output-right';
                
                if ($edge['source'] === $currentNodeId 
                    && $edgeSourceHandle === $currentSourceHandle 
                    && !in_array($edge['target'], $visited)) {
                    $queue[] = $edge['target'];
                    error_log('[WAIC Test] Next node: ' . $edge['target']);
                }
            }
        }
        
        // Check final status
        $finalState = get_transient($executionId);
        if (($finalState['status'] ?? '') === 'running' || ($finalState['status'] ?? '') === 'ready_to_run') {
            // Completed successfully
            $this->updateExecutionState($executionId, [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
            ], true);
            
            error_log('[WAIC Test] Execution completed: ' . $executionId);
        }
        
        return true;
    }
    
    /**
     * Update execution state (helper for background execution)
     */
    private function updateExecutionState($executionId, $updates, $merge = false) {
        $state = get_transient($executionId);
        
        if (!$state) {
            return false;
        }
        
        if ($merge) {
            // Merge arrays for node_status, variables, visited_nodes
            foreach ($updates as $key => $value) {
                if (in_array($key, ['node_status', 'variables']) && is_array($value)) {
                    $old = $state[$key] ?? [];
                    // Use array union (+) to preserve numeric keys (node IDs)
                    $state[$key] = $value + $old; // New values override old
                    error_log('[WAIC Test] Merged ' . $key . ': ' . json_encode($state[$key]));
                } elseif (in_array($key, ['visited_nodes', 'completed_nodes']) && is_array($value)) {
                    // Merge array lists (append, deduplicate)
                    $state[$key] = array_unique(array_merge($state[$key] ?? [], $value));
                } else {
                    $state[$key] = $value;
                }
            }
        } else {
            // Direct assignment
            foreach ($updates as $key => $value) {
                $state[$key] = $value;
            }
        }
        
        // Add log if status changed
        if (isset($updates['status'])) {
            $state['logs'][] = [
                'timestamp' => current_time('mysql'),
                'level' => 'NOTICE',
                'message' => 'Status changed to: ' . $updates['status'],
            ];
        }
        
        set_transient($executionId, $state, 3600);
        return true;
    }
}

// Initialize
WaicWorkflowExecuteAPI::getInstance();

/**
 * Resume workflow execution after HIL/delay complete
 * Called by HIL helper when user confirms
 */
function waic_execute_workflow_resume($executionId) {
    error_log('[WAIC] Resume workflow requested for execution_id: ' . $executionId);
    
    // Get execution state
    $state = get_transient($executionId);
    
    if ($state === false) {
        error_log('[WAIC] Resume failed: Execution state not found for ' . $executionId);
        return false;
    }
    
    if ($state['status'] !== 'waiting') {
        error_log('[WAIC] Resume skipped: Execution status is ' . $state['status'] . ', not waiting');
        return false;
    }
    
    error_log('[WAIC] Resuming workflow from waiting state...');
    
    // Update status to running
    $state['status'] = 'running';
    $state['logs'][] = [
        'timestamp' => current_time('mysql'),
        'level' => 'NOTICE',
        'message' => 'Workflow resumed after HIL/delay complete',
    ];
    set_transient($executionId, $state, 3600);
    
    // ⭐ Execute workflow continuation NGAY LẬP TỨC
    try {
        $api = WaicWorkflowExecuteAPI::getInstance();
        
        // Get workflow data from state
        $taskId = $state['task_id'];
        $nodes = $state['nodes'];
        $edges = $state['edges'];
        $triggerData = $state['trigger_data'] ?? [];
        
        error_log('[WAIC] Calling executeWorkflowBackground to continue execution...');
        
        // Call executeWorkflowBackground to continue from pending node
        $api->executeWorkflowBackground($executionId, $nodes, $edges, $triggerData);
        
        error_log('[WAIC] Resume execution completed successfully');
        
        return true;
    } catch (Exception $e) {
        error_log('[WAIC] Resume execution failed: ' . $e->getMessage());
        
        // Update state to error
        $state['status'] = 'error';
        $state['logs'][] = [
            'timestamp' => current_time('mysql'),
            'level' => 'ERROR',
            'message' => 'Resume failed: ' . $e->getMessage(),
        ];
        set_transient($executionId, $state, 3600);
        
        return false;
    }
}
