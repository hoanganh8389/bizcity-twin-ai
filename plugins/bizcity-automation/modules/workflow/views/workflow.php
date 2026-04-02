<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkflowView extends WaicView {
	
	public function showWorkflow() {
		$assets = WaicAssets::_();
		$assets->loadCoreJs();
		$assets->loadDataTables(array('buttons', 'responsive'));
		$assets->loadAdminEndCss();
		
		$frame = WaicFrame::_();
		$path = $this->getModule()->getModPath() . 'assets/';
		$frame->addScript('waic-workflow', $path . 'admin.workflow.js', array(), WAIC_VERSION);
		$frame->addStyle('waic-workflow', $path . 'admin.workflow.css', array(), WAIC_VERSION);
		$frame->addScript('waic-workflow-import', $path . 'admin.workflow.import.js', array(), WAIC_VERSION);
		$frame->addScript('waic-workflow-export', $path . 'admin.workflow.export.js', array(), WAIC_VERSION);
		
		$module = $this->getModule();
		$workspace = $frame->getModule('workspace');
		$workspace->getModel('history')->calcTokens();

		//$features = $module->getWorkspaceFeatures();
		$lang = array(
			'btn-delete' => esc_html__('Delete', 'ai-copilot-content-generator'),
			'btn-publish' => esc_html__('Publish', 'ai-copilot-content-generator'),
			'btn-unpublish' => esc_html__('Unpublish', 'ai-copilot-content-generator'),
			'confirm-delete' => esc_html__('Are you sure you want to delete all these tasks?', 'ai-copilot-content-generator') . '<div class="wbw-settings-fields mt-3"><input type="checkbox">' . esc_html__('delete generated content', 'ai-copilot-content-generator') . '</div>',
			'confirm-publish' => esc_html__('Are you sure you want to publish all these tasks?', 'ai-copilot-content-generator'),
			'confirm-unpublish' => esc_html__('Are you sure you want to unpublish all these tasks?', 'ai-copilot-content-generator'),
			'pageNext' => esc_html__('Next', 'ai-copilot-content-generator'),
			'pagePrev' => esc_html__('Prev', 'ai-copilot-content-generator'),
			'lengthMenu' => esc_html__('per page', 'ai-copilot-content-generator'),
			'tableLoading' => esc_html__('Loading...', 'ai-copilot-content-generator'),
			'btn-save' => esc_html__('Save', 'ai-copilot-content-generator'),
			'btn-cancel' => esc_html__('Cancel', 'ai-copilot-content-generator'),
			'btn-copy' => esc_html__('Copy', 'ai-copilot-content-generator'),
			'label-status' => esc_html__('Status', 'ai-copilot-content-generator'),
			'confirm-template' => esc_html__('Create template', 'ai-copilot-content-generator'),
			'template-name' => esc_html__('Template Name', 'ai-copilot-content-generator'),
			'template-desc' => esc_html__('Template Description', 'ai-copilot-content-generator'),
			'confirm-tmp-delete' => esc_html__('Are you sure you want to delete template?', 'ai-copilot-content-generator'),
		);
		
		$curTab = WaicReq::getVar('cur');
		
		$this->assign('lang', $lang);
		$this->assign('tabs', $module->getWorkflowTabsList(is_null($curTab) || empty($curTab) ? '' : $curTab));
		$this->assign('templates', $module->getModel()->getWorkflowTemplates());
		$this->assign('tmp_url', $workspace->getFeatureUrl('template'));
		$this->assign('new_url', $workspace->getFeatureUrl('builder'));
		$this->assign('ai_url', $workspace->getFeatureUrl('settings'));
		//$this->assign('img_path', WAIC_IMG_DIR);
		$this->assign('is_pro', $frame->isPro());

		return parent::getContent('adminWorkflow');
	}

	public function showWorkflowBuilder( $taskId = 0 ) {
        $taskId = (int) $taskId;
        $frame = WaicFrame::_();
        $path = $this->getModule()->getModPath() . 'assets/';

        wp_enqueue_script('wp-i18n');
        wp_enqueue_editor();
        wp_enqueue_script('media-upload');

        // Builder bundle
        $frame->addScript('waic-workflow-editor', $path . 'workflow.editor.js', array('jquery','wp-element','wp-hooks'), WAIC_VERSION);
        $frame->addStyle('waic-workflow-editor-style', $path . 'style-main.jsx.css', array(), WAIC_VERSION);
        $frame->addStyle('waic-workflow-editor-main', $path . 'main.jsx.css', array(), WAIC_VERSION);

        // BizCity: AI Dashboard Dialog CSS
        $frame->addStyle('waic-workflow-ai-dashboard', $path . 'ai.dashboard.dialog.css', array(), WAIC_VERSION);
        
        // BizCity: N8N 3-Column Layout CSS
        $frame->addStyle('waic-workflow-n8n-3col', $path . 'node.detail.3col.n8n.css', array(), WAIC_VERSION);

        // BizCity: also load admin.workflow.js/css in builder (for repeater UI enhancement)
        $frame->addScript('waic-workflow', $path . 'admin.workflow.js', array('jquery'), WAIC_VERSION);
        $frame->addStyle('waic-workflow', $path . 'admin.workflow.css', array(), WAIC_VERSION);

        // BizCity: AJAX Test Runner for development mode (assets-static to avoid npm build overwrite)
        $staticPath = $this->getModule()->getModPath() . 'assets-static/';
        $frame->addScript('waic-test-runner', $staticPath . 'test-runner.js', array('jquery'), WAIC_VERSION, true);

        $task = empty($taskId) 
			? array(
				'params' => array(
					'nodes' => array(
						array(
							'id' => '1', 
							'type' => 'trigger', 
							'position' => array('x' => 350, 'y' => 200), 
							'data' => array(
								'type'     => 'trigger',
								'category' => 'bc',
								'code'     => 'bc_instant_run',
								'label'    => '⚡ Instant Run — Chạy ngay',
								'settings' => array( 'default_text' => '', 'default_image_url' => '' ),
								'dragged'  => true,
							),
						),
					),
				),
				'enges' => array(),
				'settings' => array(),
			)
			: WaicFrame::_()->getModule('workspace')->getModel('tasks')->getTask($taskId);
		$model = $this->getModule()->getModel();
		//$this->assign('triggers', $model->getTriggers());
		$jsData = array(
			'blocks' => $model->getAllBlocksSettings(),
			'global' => $model->getWorkflowSettings(),
			'task_id' => $taskId,
			'status' => WaicUtils::getArrayValue($task, 'status'),
			'title' => WaicUtils::getArrayValue($task, 'title'),
			'today' => WaicUtils::getFormatedDateTime(WaicUtils::getTimestamp(), 'Y-m-d'),
			'lang' => array(
				'title_plh' =>  __('Enter workflow title', 'ai-copilot-content-generator'),
				'title_dialog' => __('Enter a title for this scenario', 'ai-copilot-content-generator'),
				'settings_dialog' => __('Workflow Settings', 'ai-copilot-content-generator'),
				'log_dialog' => __('Workflow Log', 'ai-copilot-content-generator'),
				'editor_dialog' => __('HTML Editor', 'ai-copilot-content-generator'),
				'date_label' => __('Date', 'ai-copilot-content-generator'),
				'btn_save' => __('OK', 'ai-copilot-content-generator'),
				'btn_cancel' => __('Cancel', 'ai-copilot-content-generator'),
				'btn_run' => __('Publish', 'ai-copilot-content-generator'),
				'btn_stop' => __('Stop', 'ai-copilot-content-generator'),
				'сhoose' => __('Choose', 'ai-copilot-content-generator'),
				'choose_trigger' => __('Choose Trigger', 'ai-copilot-content-generator'),
				'choose_action' => __('Choose Action', 'ai-copilot-content-generator'),
				'choose_logic' => __('Choose Logic', 'ai-copilot-content-generator'),
				'search' => __('Search', 'ai-copilot-content-generator'),
				'variables' => __('Variables', 'ai-copilot-content-generator'),
				'copy' => __('Copy', 'ai-copilot-content-generator'),
				'var_dialog' => __('Choose Variable', 'ai-copilot-content-generator'),
				'var_none' => __('No variables available. The node may not be connected to parent nodes with required variables.', 'ai-copilot-content-generator'),
				'var_plh_user_meta' => __('Enter Meta Key', 'ai-copilot-content-generator'),
				'var_plh_post_meta' => __('Enter Meta Key', 'ai-copilot-content-generator'),
				'var_plh_prod_meta' => __('Enter Meta Key', 'ai-copilot-content-generator'),
				'var_plh_prod_attr' => __('Enter Taxonomy', 'ai-copilot-content-generator'),
				'var_plh_webhook_field' => __('Enter Field Key', 'ai-copilot-content-generator'),
				'var_tooltip_webhook_field' => __('Enter Webhook Field Key. Separate keys with / to create nested data.', 'ai-copilot-content-generator') . '</br></br>' . __('Example', 'ai-copilot-content-generator') . ':</br>user/id</br>address/street</br>description',
				'var_plh_webhook_result' => __('Enter Field Key', 'ai-copilot-content-generator'),
				'var_tooltip_webhook_result' => __('Enter Webhook Result Field Key. Separate keys with / to create nested data.', 'ai-copilot-content-generator') . '</br></br>' . __('Example', 'ai-copilot-content-generator') . ':</br>code</br>message</br>data/status',
				'var_plh_query_param' => __('Enter Query Parameter Key', 'ai-copilot-content-generator'),
				'var_plh_run_vars' => __('Enter run variable code', 'ai-copilot-content-generator'),
				'var_plh_json_field' => __('Enter JSON Field Key', 'ai-copilot-content-generator'),
			),
			'flow' => $model->convertTaskParameters(WaicUtils::getArrayValue($task, 'params'), false),
		);
		
		WaicFrame::_()->addJSVar('waic-workflow-editor', 'WAIC_WORKFLOW', $jsData);

		return parent::getContent('workflowBuilder');
	}
	public function getLogData( $taskId, $dd ) {
		$html = '';
		$taskId = (int) $taskId;
		
		if (!WaicUtils::checkDateTime($dd, 'Y-m-d')) {
			$dd = false;
		}

		$modelFlow = $this->getModel();
		$stats = $modelFlow->getStatuses();
		$logs = $modelFlow->getFlowLog($taskId, $dd);
		
		$runIdPrev = 0;
		$statuses = $this->getModel('flowlogs')->getStatuses();
		$runPrev = false;
		$cnt = 0;
		$loop = array();
		
		foreach ($logs as $log) {
			$runId = $log['run_id'];
			if ($runId != $runIdPrev) {
				if (!empty($runPrev)) {
					if (!empty($runPrev['run_ended'])) {
						$html .= '<div class="waic-log-one"><div class="waic-log-date">' . $runPrev['run_ended'] . '</div><div class="waic-log-main">Workflow ' . $stats[$runPrev['run_status']] . '</div>' . ( empty($runPrev['run_error']) ? '' : ' -<div class="waic-log-error">' . $runPrev['run_error'] . '</div>' ) . '</div>';
					}
					$html .= '</div>';
				}
				$html .= '<div class="waic-log-run">';
				$trigger = $modelFlow->getDefBlock('trigger', $log['tr_code']);
				$html .= '<div class="waic-log-one"><div class="waic-log-date">' . $log['added'] . '</div> [' . $log['tr_id'] . '] v' . $log['version'] . ' Trigger ' . ( $trigger ? $trigger->getName() : '???' ) . ( empty($log['obj_id']) ? '' : ': ObjId = ' . $log['obj_id'] )  . '</div>';
				$html .= '<div class="waic-log-one"><div class="waic-log-date">' . $log['run_started'] . '</div><div class="waic-log-main">Workflow started</div></div>';
				$runIdPrev = $runId;
				$runPrev = $log;
			}
			$blType = $log['bl_type'];
			$block = $modelFlow->getDefBlock(empty($blType) ? 'action' : 'logic', $log['bl_code']);
			$existLog = $log['log_id'] > 0;
			if ($existLog) {
				$r = empty($log['result']) ? array() : WaicUtils::jsonDecode($log['result']);
				$status = $log['log_status'];
				$result = $statuses[$status];
				if (3 == $status) {
					if (3 == $blType) {
						$cnt = $log['cnt'];
						$loop = empty($r['loop']) ? array() : $r['loop'];
						$result = ' Found ' . $cnt . ' items' ;
					} else if (2 == $blType) {
						$result = empty($r['result']) ? 'FALSE' : 'TRUE';
					} else {
						foreach ($r as $k => $v) {
							if (strpos($k, 'waic_', 0) === 0) {
								$result .= ' (Result = ' . $v . ')';
								break;
							}
						}
					}
				} else if (7 == $status) {
					$result .= ' -<div class="waic-log-error">' . $log['log_error'] . '</div>';
				}
				$step = $log['step'];
				$loopKey = $step - 1;
				$html .= '<div class="waic-log-one"><div class="waic-log-date">' . $log['log_started'] . '</div> [' . $log['bl_id'] . '] ' . $block->getName() .  
					( empty($step) ? '' : ' ' . $step . '/' . $cnt . ( empty($loop[$loopKey]) || is_array($loop[$loopKey]) ? '' : ' (Step = ' . $loop[$loopKey] . ')' ) ) . 
					': ' . $result . '</div>';
			}
		}
		if (empty($dd) && count($logs) >= 100) {
			$html .= '<div>...</div>';
		} else {
			if (!empty($runPrev)) {
				if (!empty($runPrev['run_ended'])) {
					$html .= '<div class="waic-log-one"><div class="waic-log-date">' . $runPrev['run_ended'] . 
						'</div><div class="waic-log-main">Workflow ' . $stats[$runPrev['run_status']] . '</div>' . 
						( empty($runPrev['run_error']) ? '' : ' -<div class="waic-log-error">' . $runPrev['run_error'] . '</div>' ) . '</div>';
				}
				$html .= '</div>';
			}
		}

		if (empty($html)) {
			$html = '<div class="waic-log-error">' . __('No logs found', '') . '</div>';
		} else {
			$html .= '</div>';
		}
		return $html;
	}
}
