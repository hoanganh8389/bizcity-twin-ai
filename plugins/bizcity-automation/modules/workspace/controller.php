<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkspaceController extends WaicController {

	protected $_code = 'workspace';

	public function getNoncedMethods() {
		return array('doActionTask', 'getCurrentTaskData', 'getHistoryList', 'deleteTasks', 'editTaskName', 'dismissNotice');
	}
	
	public function getHistoryList() {
		$res = new WaicResponse();
		$res->ignoreShellData();

		$params = WaicReq::get('post');
		$params['exclude'] = array('workflow', 'template');
		$result = $this->getModel('tasks')->getHistory($params);
		
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
	
	public function doActionTask() {
		$res = new WaicResponse();
		$taskId = WaicReq::getVar('task_id', 'post');
		$action = WaicReq::getVar('task_action', 'post');
		$force = WaicReq::getVar('force_action', 'post');
		$edited = WaicReq::getVar('edited', 'post', '', true);
		$refresh = WaicReq::getVar('refresh', 'post');

		if (!empty($edited) || !empty($refresh)) {
			if (!$this->getModel()->editResults($taskId, $edited, $refresh)) {
				$res->pushError(WaicFrame::_()->getErrors());
				return $res->ajaxExec();
			}
		}
		
		switch ($action) {
			case 'start':
				$result = $this->getModel()->startGeneration($taskId, $force);
				break;
			case 'stop':
				$result = $this->getModel()->stopGeneration($taskId);
				break;
			case 'publish':
				$publish = WaicReq::getVar('publish', 'post');
				$result = empty($publish) ? true : $this->getModel()->publishTask($taskId, $publish);
				break;
			case 'cancel':
				$result = $this->getModel()->cancelGeneration($taskId);
				break;
			case 'delete':
				$deleted = WaicReq::getVar('deleted', 'post');
				$result = empty($deleted) ? true : $this->getModel()->deleteTaskEtaps($taskId, $deleted);
				break;
			default:
				$result = true;
				break;
		}
		if (-1 === $result) {
			$res->confirm = WaicFrame::_()->getErrors();
		} else if (!$result) {
			$res->pushError(WaicFrame::_()->getErrors());
		}
		return $res->ajaxExec();
	}
	public function runGeneration() {
		$res = new WaicResponse();
		$this->getModule()->runGenerationTask(true);
		return $res->ajaxExec();
	}
	public function getCurrentTaskResults() {
		$res = new WaicResponse();
		$taskId = WaicReq::getVar('task_id', 'post');
		$postId = WaicReq::getVar('post_id', 'post');
		$taskModel = $this->getModel('tasks');
		$task = $taskModel->getTask($taskId);
		if ($task) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$res->addData('task', $task);
				$res->addData('actions', $taskModel->getTaskActions($taskId, $task['status']));
				if (1 == $task['cnt'] || !empty($postId)) {
					$res->addData('table', $module->getView()->getTableResults($task, $postId));
				}
			} else {
				$res->pushError(esc_html(__('Not fount feature module', 'ai-copilot-content-generator') . ' ' . $feature));
			}
		} else {
			$res->pushError(esc_html(__('Not fount task', 'ai-copilot-content-generator') . ' id=' . $taskId));
		}
		return $res->ajaxExec();
	}
	public function editTaskName() {
		$res = new WaicResponse();
		$id = WaicReq::getVar('task_id', 'post');
		$title = $this->getModel('tasks')->updateTaskTitle($id, WaicReq::getVar('new_name', 'post'));
		if ($title) {
			$res->addData('title', $title);
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}

		return $res->ajaxExec();
	} 
	public function deleteTasks() {
		$res = new WaicResponse();
		$ids = WaicReq::getVar('ids', 'post');
		$param = WaicReq::getVar('param');
		if ($this->getModel()->deleteTasks($ids, ( 'deleteContent' == $param ))) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}

		return $res->ajaxExec();
	}
	public function publishTasks() {
		$res = new WaicResponse();
		$ids = WaicReq::getVar('ids', 'post');
		if ($this->getModel()->publishTasks($ids)) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}

		return $res->ajaxExec();
	}
	public function unpublishTasks() {
		$res = new WaicResponse();
		$ids = WaicReq::getVar('ids', 'post');
		if ($this->getModel()->unpublishTasks($ids)) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}

		return $res->ajaxExec();
	}
	public function dismissNotice() {
		$res = new WaicResponse();
		$slug = WaicReq::getVar('slug', 'post');
		$slugs = array('waic-notice-dismiss-domains');
		if (!empty($slug) && in_array($slug, $slugs)) {
			update_option($slug, 1);
		}
		return $res->ajaxExec();
	}

}
