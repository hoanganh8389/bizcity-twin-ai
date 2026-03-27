<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkspaceModel extends WaicModel {
	public $runningTaskId = 1;
	public $runningFlagId = 2;
	public $runningFlagTimeout = 5; //min
	
	public function __construct() {
		$this->_setTbl('workspace');
	}
	
	public function editResults( $id, $edited, $refresh ) {
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getTask($id);
		
		$status = $task['status'];
		
		if ($tasksModel->canTaskUpdate($status)) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$model = $module->getModel();
				$refreshed = $model->editResults($id, $edited, $refresh);
				if (false !== $refreshed) {
					//$task = $this->controlSteps($task, $model);
					$steps = $model->getGeneratedSteps($id);
					$update = array('step' => $steps);
					if (true !== $refreshed) {
						$update['status'] = 8;
					}
					$tasksModel->updateTask($id, $update);
				
					/*if (!empty($steps)) {
						$step = ( $task['step'] > $task['steps'] ? $task['steps'] : $task['step'] ) - $steps;
						$update = array('status' => 8, 'step' => ( $step < 0 ? 0 : $step ));
						if (!$tasksModel->updateTask($id, $update)) {
							return false;
						}
					}*/
				} else {
					return false;
				}
			} else {
				WaicFrame::_()->pushError(esc_html__('Not fount feature module', 'ai-copilot-content-generator'));
				return false;
			}
		} else {
			WaicFrame::_()->pushError(esc_html(__('It is impossible to update task, since the task is in the status', 'ai-copilot-content-generator') . ' ' . $tasksModel->getStatuses($status)));
			return false;
		}
		return true;
	}
	
	public function publishTask( $id, $publish ) {
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getTask($id);
		
		$status = $task['status'];
		
		if ($tasksModel->canTaskUpdate($status)) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$featureModel = $module->getModel();
				if (!$featureModel->publishResults($id, $publish)) {
					return false;
				}
				if ($featureModel->isAllPublished($id)) {
					if (!$tasksModel->updateTask($id, array('status' => 4))) {
						return false;
					}
				}
			} else {
				WaicFrame::_()->pushError(esc_html__('Not fount feature module', 'ai-copilot-content-generator'));
				return false;
			}
		} else {
			WaicFrame::_()->pushError(esc_html(__('It is impossible to update task, since the task is in the status', 'ai-copilot-content-generator') . ' ' . $tasksModel->getStatuses($status)));
			return false;
		}
		return true;
	}
	
	/*public function refreshResults( $id, $refresh ) {
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getTask($id);
		
		$status = $task['status'];
		
		if ($tasksModel->canTaskUpdate($status)) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$steps = $module->getModel()->refreshResults($id, $refresh);
				if (false !== $steps) {
					$step = ( $task['step'] > $task['steps'] ? $task['steps'] : $task['step'] ) - $steps;
					$update = array('status' => 8, 'step' => ( $step < 0 ? 0 : $step ));
					if (!$tasksModel->updateTask($id, $update)) {
						return false;
					}
				} else {
					return false;
				}
			} else {
				WaicFrame::_()->pushError(esc_html__('Not fount feature module', 'ai-copilot-content-generator'));
				return false;
			}
		} else {
			WaicFrame::_()->pushError(esc_html(__('It is impossible to update task, since the task is in the status', 'ai-copilot-content-generator') . ' ' . $tasksModel->getStatuses($status)));
			return false;
		}
		return true;
	}*/

	public function startGeneration( $id, $force = false ) {
		if (empty($id)) {
			WaicFrame::_()->pushError(esc_html__('Generation ID not found', 'ai-copilot-content-generator'));
			return false;
		}
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getTask($id);
		
		if (empty($task)) {
			WaicFrame::_()->pushError(esc_html__('Generation task not found', 'ai-copilot-content-generator'));
			return false;
		}
		$status = $task['status'];
		$run = false;
		
		if (empty($status)) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$steps = $module->getModel()->prepareGeneration($task);
			} else {
				WaicFrame::_()->pushError(esc_html__('Not found feature module', 'ai-copilot-content-generator'));
				return false;
			}
			if (false === $steps) {
				WaicFrame::_()->saveDebugLogging();
				return false;
			}
			/*$task['step'] = 0;
			$task['start'] = 0;
			$task['end'] = 0;*/
			$params = array(
				'step' => 0,
				'start' => null,
				'end' => null,
				'status' => 1,
				'cnt' => $steps['cnt'],
				'steps' => $steps['steps'],
			);
			//return true;
			if (!$tasksModel->updateTask($id, $params)) {
				return false;
			}
			$run = true;
		} else {
			$run = $tasksModel->canTaskStart($status);
		}
		if ($run) {
			$running = false;
			$runningTask = $this->getRunningTask();
			if (empty($runningTask) || $runningTask == $id || $force) {
				$this->setRunningTask($id);
				$running = true;
			} 
			$this->getModule()->runGenerationTask();
			if (!$running && !$force) {
				$tasksModel->updateTask($id, array('status' => 1));
				WaicFrame::_()->pushError(esc_html__('Another task is now running! This task is in the queue and will be launched automatically as soon as possible. If you want, you can forcefully stop a running task and start this one. Want to?', 'ai-copilot-content-generator'));
				return -1;
			}
			wp_cron();
		} else {
			WaicFrame::_()->pushError(esc_html(__('It is impossible to start generation, since the task is in the status', 'ai-copilot-content-generator') . ' ' . $tasksModel->getStatuses($status)));
			return false;
		}
		
		return true;
	}
	public function stopGeneration( $id = 0 ) {
		$this->setStoppingTaskGeneration();
		return $this->getModule()->getModel('tasks')->stopTask($id);
	}
	public function cancelGeneration( $id ) {
		$this->setStoppingTaskGeneration();
		return $this->getModule()->getModel('tasks')->cancelTask($id);
	}
	public function setRunningTask( $id ) {
		$this->updateById(array('value' => $id), $this->runningTaskId);
	}
	public function setStoppingTaskGeneration() {
		$this->updateById(array('value' => 0), $this->runningTaskId);
	}
	public function getRunningTask() {
		$option = $this->getById($this->runningTaskId);
		//$taskId = $this->setWhere(array('name' => 'task'))->setSelectFields('value')->getFromTbl(array('return' => 'one'));
		return empty($option) ? 0 : (int) $option['value'];
	}
	public function setRunningFlag( $id = 0 ) {
		$id = empty($id) ? $this->runningFlagId : (int) $id;
		$this->updateById(array('value' => time()), $id);
	}
	public function resetRunningFlag( $id = 0 ) {
		$id = empty($id) ? $this->runningFlagId : (int) $id;
		$this->updateById(array('value' => 0), $id);
	}
	public function isRunningFlag( $id = 0, $t = 0 ) {
		$id = empty($id) ? $this->runningFlagId : (int) $id;
		$option = $this->getById($id);
		$flag = empty($option) ? 0 : (int) $option['value'];
		if (empty($flag)) {
			return false;
		}
		$t = empty($t) ? $this->runningFlagTimeout : (int) $t;
		if (( time() - $flag ) > $t * 60) {
			$this->resetRunningFlag();
			return false;
		}
		return true;
	}

	public function doScheduledTasks() {
		if ($this->isRunningFlag()) {
			return true;
		}
		
		WaicFrame::_()->saveDebugLogging('**************BEGIN CYCLE*********************');
		do {
			if (!$this->doGenerationTasks()) {
				return false;
			}
			$tackId = (int) $this->getRunningTask();
			if (empty($tackId)) {
				$taskId = (int) $this->getModule()->getModel('tasks')->getScheduledTask();
				if (empty($taskId)) {
					break;
				}
				$this->setRunningTask($taskId);
			} else {
				break;
			}
		} while (true);
		WaicFrame::_()->saveDebugLogging('**************END CYCLE*********************');
		return true;
	}
	
	public function doGenerationTasks() {
		if ($this->isRunningFlag()) {
			return true;
		}
		
		$taskId = (int) $this->getRunningTask();
		if (empty($taskId)) {
			$this->getModule()->getModel('tasks')->stopTasks();
			return true;
		}

		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getTask($taskId);
		
		WaicFrame::_()->saveDebugLogging('***********************************');
		$this->setRunningFlag();
		$apiOptions = WaicUtils::getArrayValue($task['params'], 'api', array(), 2);
		$aiProvider = false;
		if (!empty($apiOptions)) {
			$aiProvider = $this->getModule()->getModel('aiprovider')->getInstance($apiOptions);
			if (!$aiProvider) {
				return false;
			}

			$aiProvider->init($taskId);
		}

		if (!$this->doGenerationTask($taskId, $aiProvider)) {
			$this->resetRunningFlag();
			return false;
		}
		$this->setStoppingTaskGeneration();

		$this->resetRunningFlag();
		return true;
	}

	public function doGenerationTask( $id, $aiProvider ) {
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->runTask($id);
		
		$task = $tasksModel->getTask($id); //
		if (empty($task)) {
			return false;
		}
		$feature = $task['feature'];
		$module = WaicFrame::_()->getModule($feature);

		if (!$module) {
			WaicFrame::_()->pushError(esc_html(__('Not found feature module', 'ai-copilot-content-generator') . ' ' . $feature));
			return false;
		}
		if ($aiProvider && !$aiProvider->setApiOptions(WaicUtils::getArrayValue($task['params'], 'api', array(), 2))) {
			return false;
		}
		$model = $module->getModel();
		$task = $this->controlSteps($task, $model);
		//if (!empty($task['steps']) && $task['steps'] > 3) { 
			set_time_limit(0);
		//}
		$results = $module->getModel()->doGeneration($task, $aiProvider);
		$task = $this->controlSteps($task, $model);
		if (is_array($results)) {
			return $tasksModel->updateTask($id, $results);
		}
		//$task = $this->controlSteps($task, $model);
		return false;
	}
	public function deleteTasks( $ids, $withContent = true ) {
		if (!is_array($ids)) {
			return true;
		}
		$tasksModel = $this->getModule()->getModel('tasks');
		
		foreach ($ids as $id) {
			$id = (int) $id;
			$task = $tasksModel->getById($id);
			if (empty($task)) {
				continue;
			}
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);

			if (!$module) {
				continue;
			}
			if ($module->getModel()->clearEtaps($id, false, $withContent)) {
				$tasksModel->delete(array('id' => $id));
			}
		}
		return true;
	}
	public function deleteTaskEtaps( $taskId, $ids ) {
		$taskId = (int) $taskId;
		if (empty($taskId) || !is_array($ids)) {
			return true;
		}
		$tasksModel = $this->getModule()->getModel('tasks');
		$task = $tasksModel->getById($taskId);
		if (!empty($task)) {
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);
			if ($module) {
				$model = $module->getModel();
				$model->clearEtaps($taskId, $ids); 
				$task = $this->controlSteps($task, $model);
			}
		}
		
		return true;
	}

	public function unpublishTasks( $ids ) {
		if (!is_array($ids)) {
			return true;
		}
		$tasksModel = $this->getModule()->getModel('tasks');
		
		foreach ($ids as $id) {
			$id = (int) $id;
			$task = $tasksModel->getById($id);
			if (empty($task)) {
				continue;
			}
			$feature = $task['feature'];
			/*if ('postsfields' == $feature) {
				continue;
			}*/
			$module = WaicFrame::_()->getModule($feature);

			if (!$module) {
				continue;
			}
			$featureModel = $module->getModel();
			if (!$featureModel->canUnpublish()) {
				continue;
			}
			
			if ($featureModel->unpublishEtaps($id)) {
				if ($tasksModel->isPublished($task['status']) && !$featureModel->isAllPublished($id)) {
					if (!$tasksModel->updateTask($id, array('status' => 3))) {
						return false;
					}
				}
			}
		}
		return true;
	}
	public function publishTasks( $ids ) {
		if (!is_array($ids)) {
			return true;
		}
		$tasksModel = $this->getModule()->getModel('tasks');
		
		foreach ($ids as $id) {
			$id = (int) $id;
			$task = $tasksModel->getById($id);
			if (empty($task)) {
				continue;
			}
			$feature = $task['feature'];
			$module = WaicFrame::_()->getModule($feature);

			if (!$module) {
				continue;
			}
			$featureModel = $module->getModel();
			if ($featureModel->publishResults($id, 'all')) {
				if (!$tasksModel->isPublished($task['status']) && $featureModel->isAllPublished($id)) {
					if (!$tasksModel->updateTask($id, array('status' => 4))) {
						return true;
					}
				}
			}
		}
		return true;
	}
	
	public function controlSteps( $task, $model ) {
		$id = $task['id'];
		$steps = $model->getAllCountSteps($id);
		if ($steps['step'] != $task['step'] || $steps['steps'] != $task['steps'] || $steps['cnt'] != $task['cnt']) {
			if ($steps['steps'] < $steps['step']) {
				$steps['step'] = $steps['steps'];
			}
			$this->getModule()->getModel('tasks')->updateTask($id, $steps);
			$task = array_merge($task, $steps);
		}
		return $task;
	}
	public function controlStop( $taskId, $withSleep = true ) {
		$this->setRunningFlag();
		
		$needStop = false;
		$running = (int) $this->getRunningTask();
		if ($running != $taskId) {
			$needStop = true;
		}
		if (!$needStop) {
			$this->getModule()->getModel('tasks')->updateById(array('status' => 2), $taskId);
		}
		WaicFrame::_()->saveDebugLogging('Need Stop? ' . ( $needStop ? 'YES' : 'NO' ));
		return $needStop;
	}
	
	public function doDelayedActions() {
        // BizCity: postscreate module may be disabled/removed
        $postscreate = WaicFrame::_()->getModule('postscreate');
        if (!$postscreate || !method_exists($postscreate, 'getModel')) {
            return true;
        }
        $model = $postscreate->getModel();
        if (!$model || !method_exists($model, 'publishDelayedPosts')) {
            return true;
        }

        // publish posts
        if (!$model->publishDelayedPosts($this)) {
            return false;
        }
        return true;
    }
	public function getWorkspaceFlag( $name, $field ) {
		$flag = $this->setSelectFields($field)->setWhere(array('name' => $name))->setLimit(1)->getFromTbl(array('return' => 'one'));
		return empty($flag) ? false : $flag; 
	}
	public function setWorkspaceFlag( $id, $name, $value ) {
		if (empty($id) && !empty($name)) {
			$id = $this->getWorkspaceFlag($name, 'id');
		}
		if (empty($id)) {
			if (!empty($name)) {
				$this->insert(array('name' => $name, 'value' => $value));
			}
		} else {
			$this->updateById(array('value' => $value), $id);
		}
		return true;
	}
	public function getWorkspaceFlagById( $id, $field = false ) {
		$option = $this->getById($id);
		return empty($option) ? 0 : ( $field ? (int) $option[$field] : $option );
	}
}
