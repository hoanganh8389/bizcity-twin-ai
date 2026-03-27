<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkflowController extends WaicController {

	protected $_code = 'workflow';

	public function __construct() {
		parent::__construct('workflow');
		
		// Start output buffering early for AJAX requests
		if (defined('DOING_AJAX') && DOING_AJAX) {
			// Suppress PHP errors to prevent output corruption
			@ini_set('display_errors', '0');
			@ini_set('display_startup_errors', '0');
			
			// Clean any existing buffers first
			while (ob_get_level()) {
				ob_end_clean();
			}
			// Start fresh buffer
			ob_start();
		}
		
		// Load listener API (loads hook handlers)
		// Note: This will register waic_twf_process_flow hook but has early exit for AJAX
		require_once dirname(__FILE__) . '/lib/listener-api.php';
		
		// Register REST endpoints
		add_action('rest_api_init', array($this, 'registerTestWebhookEndpoint'));
	}
	
	/**
	 * Register test webhook endpoint
	 */
	public function registerTestWebhookEndpoint() {
		register_rest_route('waic/v1', '/webhook-test/(?P<node_id>[a-zA-Z0-9_-]+)', array(
			'methods' => ['POST', 'GET'],
			'callback' => array($this, 'handleTestWebhook'),
			'permission_callback' => array($this, 'canAccessWebhookTest'),
		));
		
		// NEW: Clean REST endpoint for workflow history
		register_rest_route('waic/v1', '/workflow/history', array(
			'methods' => ['POST', 'GET'],
			'callback' => array($this, 'restGetHistoryList'),
			'permission_callback' => function() {
				return current_user_can('manage_options');
			},
		));
	}

	/**
	 * Permission check for webhook test endpoint
	 */
	public function canAccessWebhookTest( $request ) {
		// Allow admins
		if (current_user_can('manage_options')) {
			return true;
		}

		// Check for webhook secret token
		$expected = get_option('bizcity_webhook_secret', '');
		if (!empty($expected)) {
			$token = $request->get_header('X-Webhook-Token');
			if (empty($token)) {
				$token = $request->get_param('token');
			}
			if (!empty($token) && hash_equals($expected, (string) $token)) {
				return true;
			}
		}

		return false;
	}
	
	/**
	 * Handle test webhook
	 */
	public function handleTestWebhook($request) {
		$nodeId = $request->get_param('node_id');
		$eventData = $request->get_json_params() ?: $request->get_params();
		
		// Add request headers
		$eventData['_headers'] = $request->get_headers();
		$eventData['_method'] = $request->get_method();
		$eventData['_timestamp'] = current_time('mysql');
		
		// Debug logging
		error_log('[WAIC Webhook] Received webhook for node: ' . $nodeId);
		error_log('[WAIC Webhook] Event data: ' . json_encode($eventData));
		
		// Check if listener exists
		$listenerId = get_option('waic_active_listener_' . $nodeId);
		error_log('[WAIC Webhook] Listener ID: ' . ($listenerId ?: 'none'));
		
		if ($listenerId) {
			$listenerData = get_transient($listenerId);
			error_log('[WAIC Webhook] Listener data exists: ' . ($listenerData ? 'yes' : 'no'));
		}
		
		// Capture event
		$captured = WaicWorkflowListenerAPI::captureWebhookEvent($nodeId, $eventData);
		
		error_log('[WAIC Webhook] Captured: ' . ($captured ? 'yes' : 'no'));
		
		if ($captured) {
			return new WP_REST_Response([
				'success' => true,
				'message' => 'Event captured successfully',
				'node_id' => $nodeId
			], 200);
		}
		
		return new WP_REST_Response([
			'success' => false,
			'message' => 'No active listener for this node',
			'node_id' => $nodeId,
			'debug' => [
				'listener_id' => $listenerId ?: null,
				'option_key' => 'waic_active_listener_' . $nodeId
			]
		], 404);
	}

	public function getNoncedMethods() {
		return array('saveWorkflow', 'stopWorkflow', 'runWorkflow', 'getLogData', 'getHistoryList', 'saveIntegration', 'createTemplate', 'deleteTemplate', 'getJSON', 'importTemplate', 'importWorkflowJson', 'testRouterPrompt');
	}

	/**
	 * Test helper for custom router prompt.
	 * Runs AI once and returns:
	 * - json_raw: normalized raw JSON string
	 * - json: decoded JSON string
	 * - suggested_output_map: generated mapping from returned JSON (if possible)
	 */
	public function testRouterPrompt() {
		$res = new WaicResponse();

		$params = WaicReq::getVar('params', 'post');
		$params = empty($params) ? array() : json_decode(wp_unslash($params), true);
		if (!is_array($params)) $params = array();

		$model = WaicFrame::_()->getModule('workspace')->getModel('aiprovider');

		$taskId = (int) WaicUtils::getArrayValue($params, 'task_id', 0, 1);
		$apiOptions = array(
			'engine' => 'openai',
			'model' => WaicUtils::getArrayValue($params, 'model', ''),
			'tokens' => (int) WaicUtils::getArrayValue($params, 'tokens', 1200, 1),
			'temperature' => (float) WaicUtils::getArrayValue($params, 'temperature', 0.2),
		);

		$aiProvider = $model->getInstance($apiOptions);
		if (!$aiProvider) {
			$res->pushError(WaicFrame::_()->getErrors());
			return $res->ajaxExec();
		}
		$aiProvider->init($taskId);
		if (!$aiProvider->setApiOptions($apiOptions)) {
			$res->pushError(WaicFrame::_()->getErrors());
			return $res->ajaxExec();
		}

		$prompt = (string) WaicUtils::getArrayValue($params, 'prompt', '');
		if (trim($prompt) === '') {
			$res->pushError(__('Prompt đang trống.', 'ai-copilot-content-generator'));
			return $res->ajaxExec();
		}

		$result = $aiProvider->getText(array('prompt' => $prompt));
		if (!empty($result['error'])) {
			$res->pushError(empty($result['msg']) ? __('AI Error', 'ai-copilot-content-generator') : $result['msg']);
			return $res->ajaxExec();
		}

		$text = empty($result['data']) ? '' : (string) $result['data'];
		$jsonRaw = $this->normalizeJsonRaw($text);

		$decoded = json_decode($jsonRaw, true);
		if (!is_array($decoded)) {
			$res->pushError(__('Invalid JSON', 'ai-copilot-content-generator'));
			$res->addData('json_raw', $jsonRaw);
			return $res->ajaxExec();
		}

		$res->addData('json_raw', $jsonRaw);
		$res->addData('json', json_encode($decoded, JSON_UNESCAPED_UNICODE));

		// Suggest mapping
		$suggested = $this->suggestOutputMapFromJson($decoded);
		$res->addData('suggested_output_map', $suggested);
		$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		return $res->ajaxExec();
	}

	private function normalizeJsonRaw($text) {
		$text = trim((string)$text);
		if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
			$text = trim($m[1]);
		}
		$posObj = strpos($text, '{');
		$posArr = strpos($text, '[');
		if ($posObj === false && $posArr === false) return $text;
		$start = $posObj;
		if ($start === false || ($posArr !== false && $posArr < $posObj)) {
			$start = $posArr;
		}
		return trim(substr($text, $start));
	}

	private function suggestOutputMapFromJson($decoded) {
		$paths = array();
		$walk = function($val, $prefix) use (&$walk, &$paths) {
			if (is_array($val)) {
				foreach ($val as $k => $v) {
					if (is_int($k)) continue; // skip arrays-of-items
					$p = $prefix === '' ? $k : ($prefix . '.' . $k);
					if (is_array($v)) {
						$walk($v, $p);
					} else {
						$paths[] = $p;
					}
				}
			}
		};
		$walk($decoded, '');
		$paths = array_values(array_unique(array_filter($paths)));
		if (empty($paths)) return '';

		$lines = array();
		foreach ($paths as $p) {
			$var = str_replace('.', '_', $p);
			$lines[] = $var . '=' . $p . ':text';
		}
		return implode("\n", $lines);
	}
	
	/**
	 * REST API version of getHistoryList - clean JSON response
	 */
	public function restGetHistoryList($request) {
		// Force clean output before processing
		while (ob_get_level()) {
			ob_end_clean();
		}
		ob_start();
		
		$params = $request->get_params();
		$params['feature'] = 'workflow';
		$params['compact'] = true;
		$params['actions'] = '<div class="waic-table-actions">
						<a href="#" class="waic-action-template wbw-tooltip" title="' . esc_html__('Save as template', 'ai-copilot-content-generator'). '"><i class="fa fa-clipboard"></i></a>
						<a href="#" class="waic-action-export wbw-tooltip" title="' . esc_html__('Export JSON', 'ai-copilot-content-generator'). '"><i class="fa fa-download"></i></a>
					</div>';
		
		$result = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getHistory($params);
		
		// Clean any leaked output during getHistory
		$leaked = ob_get_clean();
		if (!empty($leaked)) {
			error_log('[REST History] Leaked output: ' . strlen($leaked) . ' bytes');
		}
		
		if ($result) {
			$response = [
				'error' => false,
				'errors' => [],
				'messages' => [],
				'data' => $result['data'],
				'recordsFiltered' => $result['total'],
				'recordsTotal' => $result['total'],
				'draw' => intval($params['draw'] ?? 0),
			];
		} else {
			$response = [
				'error' => true,
				'errors' => WaicFrame::_()->getErrors(),
				'messages' => [],
				'data' => [],
				'recordsFiltered' => 0,
				'recordsTotal' => 0,
				'draw' => intval($params['draw'] ?? 0),
			];
		}
		
		// Use wp_send_json for guaranteed clean output
		wp_send_json($response);
		exit;
	}
	
	public function getHistoryList() {
		$res = new WaicResponse();
		$res->ignoreShellData();

		$params = WaicReq::get('post');
		$params['feature'] = 'workflow';
		$params['compact'] = true;
		$params['actions'] = '<div class="waic-table-actions">
						<a href="#" class="waic-action-template wbw-tooltip" title="' . esc_html__('Save as template', 'ai-copilot-content-generator'). '"><i class="fa fa-clipboard"></i></a>
						<a href="#" class="waic-action-export wbw-tooltip" title="' . esc_html__('Export JSON', 'ai-copilot-content-generator'). '"><i class="fa fa-download"></i></a>
					</div>';
		$result = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getHistory($params);
		
		if ($result) {
			$res->data = $result['data'];

			$res->recordsFiltered = $result['total'];
			$res->recordsTotal = $result['total'];
			$res->draw = WaicUtils::getArrayValue($params, 'draw', 0, 1);

		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}
		$res->ajaxExec();
	}
	
	public function saveWorkflow() {
		$res = new WaicResponse();
		$params = json_decode(stripslashes(WaicReq::getVar('flow', 'post', '', true, false)), true);
		$params['task_title'] = WaicReq::getVar('title', 'post');
		$error = '';
		$taskId = WaicReq::getVar('task_id', 'post');
		$workspace = WaicFrame::_()->getModule('workspace');
		$taskModel = $workspace->getModel('tasks');
		if (!empty($taskId)) {
			$task = $taskModel->getById($taskId);
			if ($task && $taskModel->isPublished($task['status'])) {
				$res->pushError(esc_html__('To save changes, first stop the workflow.', 'ai-copilot-content-generator'));
				return $res->ajaxExec();
			}
		}
		$errNodes = array();
		if (!$this->getModel()->controlTaskParameters($params, $error, $errNodes, $taskId)) {
			$res->pushError($error);
			$res->addData('err_nodes', $errNodes);
		} else {
			$params = $this->getModel()->convertTaskParameters($params);
			$id = $taskModel->saveTask($this->_code, $taskId, $params);
			
			if (empty($id)) {
				$res->pushError(WaicFrame::_()->getErrors());
			} else {
				$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
				$res->addData('taskUrl', WaicFrame::_()->getModule('workspace')->getTaskUrl($id, $this->_code));
			}
		}
		return $res->ajaxExec();
	}
	public function saveIntegration() {
		$res = new WaicResponse();
		$code = WaicReq::getVar('code', 'post');
		$accounts = WaicReq::getVar('accounts', 'post');
		
		$accounts = $this->getModel('integrations')->saveIntegrations($code, $accounts);

		if (false === $accounts) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
			$res->addData('accounts', $accounts);
		}
		return $res->ajaxExec();
	}
	public function runWorkflow() {
		$res = new WaicResponse();
		$status = $this->getModel()->publishResults(WaicReq::getVar('task_id', 'post'), false, true);

		if (empty($status)) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
			$res->addData('status', $status);
		}
		return $res->ajaxExec();
	}
	public function stopWorkflow() {
		$res = new WaicResponse();
		$status = $this->getModel()->unpublishEtaps(WaicReq::getVar('task_id', 'post'),true);

		if (empty($status)) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
			$res->addData('status', $status);
		}
		return $res->ajaxExec();
	}
	public function getLogData() {
		$res = new WaicResponse();
		$taskId = WaicReq::getVar('task_id', 'post');
		$dd = WaicReq::getVar('date', 'post');
		
		$html = $this->getView()->getLogData($taskId, $dd);
		$res->setHtml($html);

		return $res->ajaxExec();
	}
	public function createTemplate() {
		$res = new WaicResponse();
		$params = WaicReq::getVar('params', 'post');
		$params = empty($params) ? array() : json_decode(wp_unslash($params), true);

		$result = $this->getModel()->createTemplate($params);

		if (false === $result) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		}
		return $res->ajaxExec();
	}
	public function importTemplate() {
		$res = new WaicResponse();
		$params = WaicReq::getVar('params', 'post');
		$params = empty($params) ? array() : json_decode(wp_unslash($params), true);

		$result = $this->getModel()->importTemplate($params);

		if (false === $result) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		}
		return $res->ajaxExec();
	}
	public function deleteTemplate() {
		$res = new WaicResponse();
		$id = WaicReq::getVar('id', 'post');
		$result = $this->getModel()->deleteTemplate($id);

		if (false === $result) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		}
		return $res->ajaxExec();
	}
	public function getJSON() {
		$res = new WaicResponse();
		$id = WaicReq::getVar('id', 'post');
		$result = $this->getModel()->getJSON($id);

		if (false === $result) {
			$res->pushError(WaicFrame::_()->getErrors());
		} else {
			$res->addData('json', $result);
		}
		return $res->ajaxExec();
	}

	/**
	 * Import a workflow definition from JSON file content.
	 * Creates a new task (feature = workflow), publishes it (run), and returns builder URL.
	 */
	public function importWorkflowJson() {
		$res = new WaicResponse();

		$title = WaicReq::getVar('title', 'post');
		$json = WaicReq::getVar('json', 'post', '', true, false);
		$run = (int) WaicReq::getVar('run', 'post', 1);

		if (empty($json)) {
			$res->pushError(esc_html__('JSON is empty.', 'ai-copilot-content-generator'));
			return $res->ajaxExec();
		}

		$flow = json_decode(wp_unslash($json), true);
		if (!is_array($flow)) {
			$res->pushError(esc_html__('Invalid JSON.', 'ai-copilot-content-generator'));
			return $res->ajaxExec();
		}

		if (empty($flow['nodes']) || !is_array($flow['nodes'])) {
			$res->pushError(esc_html__('Invalid workflow JSON: missing nodes.', 'ai-copilot-content-generator'));
			return $res->ajaxExec();
		}
		if (empty($flow['edges']) || !is_array($flow['edges'])) {
			$flow['edges'] = array();
		}

		$error = '';
		$errNodes = array();
		if (!$this->getModel()->controlTaskParameters($flow, $error, $errNodes, 0)) {
			$res->pushError($error ? $error : esc_html__('Invalid workflow.', 'ai-copilot-content-generator'));
			$res->addData('err_nodes', $errNodes);
			return $res->ajaxExec();
		}

		$flow = $this->getModel()->convertTaskParameters($flow);
		$flow['task_title'] = $title;

		$workspace = WaicFrame::_()->getModule('workspace');
		$taskModel = $workspace->getModel('tasks');
		$taskId = $taskModel->saveTask($this->_code, 0, $flow);

		if (empty($taskId)) {
			$res->pushError(WaicFrame::_()->getErrors());
			return $res->ajaxExec();
		}

		$status = 0;
		if ($run) {
			$status = (int) $this->getModel()->publishResults($taskId, false, true);
		}

		$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		$res->addData('task_id', (int) $taskId);
		$res->addData('status', $status);
		$res->addData('taskUrl', $workspace->getTaskUrl($taskId, 'builder'));
		return $res->ajaxExec();
	}
}