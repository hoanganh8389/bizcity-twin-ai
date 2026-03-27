<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTasksModel extends WaicModel {

	public function __construct() {
		$this->_setTbl('tasks');
	}
	public function getStatuses( $st = null ) {
		$statuses = array(
			0 => __('New', 'ai-copilot-content-generator'),
			1 => __('Prepared', 'ai-copilot-content-generator'),
			2 => __('Processing', 'ai-copilot-content-generator'),
			3 => __('Generated', 'ai-copilot-content-generator'),
			4 => __('Published', 'ai-copilot-content-generator'), /*Generated & Published*/
			5 => __('Scheduled', 'ai-copilot-content-generator'), /*wait cycle time*/
			6 => __('Stopped', 'ai-copilot-content-generator'),
			7 => __('Error', 'ai-copilot-content-generator'),
			8 => __('Pause', 'ai-copilot-content-generator'), /*wait user*/
			9 => __('Canceled', 'ai-copilot-content-generator'), /*deleted published posts & not need generate*/
		);
		return is_null($st) ? $statuses : ( isset($statuses[$st]) ? $statuses[$st] : '' );
	}
	public function canTaskUpdate( $st ) {
		return in_array($st, array(1, 3, 5, 7, 8));
	}
	public function canTaskStop( $st ) {
		return 2 == $st;
	}
	public function canTaskStart( $st ) {
		return in_array($st, array(1, 3, 5, 7, 8));
	}
	public function canTaskPublished( $st ) {
		return 3 == $st;
	}
	public function canTaskCancel( $st ) {
		return 2 != $st;
	}
	public function canTaskSave( $st ) {
		return !in_array($st, array(2, 4, 9));
	}
	public function isTaskRunning( $st ) {
		return 2 == $st || 1 == $st;
	}
	public function isPublished( $st ) {
		return 4 == $st;
	}
	public function isTaskInPause( $st ) {
		return 8 == $st;
	}
	public function getMinCycle() {
		$minCycle = WaicDb::get('SELECT min(cycle) FROM `@__tasks` WHERE cycle>0 AND status<7', 'one');
		return $minCycle ? (int) $minCycle : 0;
	}
	public function getScheduledTask() {
		$task = WaicDb::get("SELECT id FROM `@__tasks` WHERE cycle>0 AND status=5 AND (start IS NULL OR TIMESTAMPADD(SECOND,cycle,start)<'" . WaicUtils::getTimestampDB() . "') ORDER BY IFNULL(start, 0) LIMIT 1", 'one');
		return $task ? (int) $task : 0;
	}
	
	public function getTaskActions( $id, $st ) {
		$isStarting = $this->getModule()->getModel('workspace')->getRunningTask() == $id ? 1 : 0;
		//$isRunning = $isStarting;
		return array(
			'start' => (int) ( !$isStarting && $this->canTaskStart($st) ),
			'stop' => (int) ( $isStarting || $this->canTaskStop($st) ),
			'update' => (int) ( !$isStarting && $this->canTaskUpdate($st) ),
			'cancel' => (int) ( !$isStarting && $this->canTaskCancel($st) ),
			'running' => (int) $isStarting, //$this->isTaskRunning($st),
			'publish' => (int) ( !$isStarting && $this->canTaskPublished($st) ),
			'save' => (int) ( !$isStarting && $this->canTaskSave($st) ),
			'published' => (int) ( !$isStarting && $this->isPublished($st) ),
			'starting' => $isStarting,
		);
	}
	
	public function saveTask( $feature, $id, $params = array() ) {
		// only for new tasts or after canceled
		$id = (int) $id;
		$title = sanitize_text_field(WaicUtils::getArrayValue($params, 'task_title'));
		if (empty($title)) {
			$title = 'Sự kiện ' . gmdate('Y-m-d-h-i-s');
		}
		unset($params['task_title']);
		
		$columns = array(
			'feature' => $feature,
			'author' => get_current_user_id(),
			'title' => $title,
			'params' => WaicUtils::jsonEncode($params, true),
			'status' => 0,
		);

		$columns = WaicDispatcher::applyFilters('addTaskColumns_' . $feature, $columns, $params, $id);

		if (empty($id)) {
			$id = $this->insert($columns);
		} else {
			$ts = WaicUtils::getTimestampDB();
			$columns['updated'] = $ts;
			$columns['created'] = $ts;
			$this->updateById($columns, $id);
		}
		return $id;
	}
	public function getTask( $id ) {
		$id = (int) $id;
		if (empty($id)) {
			return array();
		}
		$task = $this->getById($id);
		if ($task) {
			$task['params'] = WaicUtils::jsonDecode($task['params']);
		}

		return $task;
	}
	public function updateTask( $id, $params = array() ) {
		$id = (int) $id;
		$params['updated'] = WaicUtils::getTimestampDB();
		if ( isset($params['status']) && ( 4 == $params['status'] || 3 == $params['status'] ) ) {
			$task = $this->getTask($id);
			if ($task && !empty($task['cycle'])) {
				$params['status'] = 5;
			}
		}
		$this->updateById($params, $id);
		
		return $id;
	}
	public function updateTaskTitle( $id, $title ) {
		$id = (int) $id;
		$title = strip_tags(str_replace(array('"', "'"), array('', ''), stripslashes($title)));
		$this->updateById(array('title' => $title), $id);
		
		return $title;
	}
	public function getTaskTitle( $id ) {
		$task = $this->getById($id);
		return $task ? $task['title'] : '';
	}
	public function runTask( $id ) {
		$task = $this->getTask($id);
		if (empty($task)) {
			WaicFrame::_()->pushError(esc_html__('Generation task not found', 'ai-copilot-content-generator'));
			return false;
		}
		$status = $task['status']; 
		if ($this->canTaskStart($status)) {
			$task['status'] = 2;
			$task['message'] = '';
			return $this->updateById(array('status' => 2, 'start' => WaicUtils::getTimestampDB(), 'message' => '', 'end' => null), $id) ? $task : false;
		}
		if ($this->isTaskRunning($status)) {
			return $task;
		}
		WaicFrame::_()->pushError(esc_html(__('The task has a status', 'ai-copilot-content-generator') . ' ' . $this->getStatuses($status) . ' ' . __('and cannot be started', 'ai-copilot-content-generator')));
		return false;
	}
	public function getTaskFeature( $id ) {
		$task = $this->getById($id);
		return $task ? $task['feature'] : '';
	}
	
	public function getPreparedTask() {
		return $this->setSelectFields('id')->setWhere(array('status' => 1))->setLimit(1)->getFromTbl(array('return' => 'one'));
	}
	
	public function getTaskByParams( $params, $field = false ) {
		if ($field) {
			$this->setSelectFields($field);
		}
		$task = $this->setWhere($params)->setLimit(1)->getFromTbl(array('return' => $field ? 'one' : 'row'));
		if ($task && !$field && isset($task['params'])) {
			$task['params'] = WaicUtils::jsonDecode($task['params']);
		}
		return $task;
	}
	public function getCountTasksByParams( $params ) {
		return $this->setWhere($params)->getCount();
	}
	
	public function stopTasks( $id = null ) {
		$data = array(
			'updated' => WaicUtils::getTimestampDB(),
			'status' => 8,
		);
		$where = array('status' => 2);
		if (!is_null($id)) {
			$where['id'] = (int) $id;
		}
		return $this->update($data, $where);
	}
	public function cancelTask( $id, $clear = true ) {
		$feature = $this->getTaskFeature($id);
		$module = WaicFrame::_()->getModule($feature);
		if ($module) {
			$model = $module->getModel();
			if ($model->isDeleteByCancel()) {
				$model->clearEtaps($id);
			}
		}
		$data = array(
			'updated' => WaicUtils::getTimestampDB(),
			'status' => 9,
			'step' => 0, 
			'steps' => 0, 
		);
		return $this->updateById($data, (int) $id);
	}
	public function stopTask( $id ) {
		$data = array(
			'updated' => WaicUtils::getTimestampDB(),
			'status' => 8,
		);
		return $this->updateById($data, (int) $id);
	}
	public function getTasksList( $where = array() ) {
		$list = array();
		$tasks = $this->setSelectFields('id, title')->setWhere($where)->getFromTbl();
		foreach ($tasks as $task) {
			$list[$task['id']] = $task['title'];
		}
		return $list;
	}
	
	public function getHistory( $params ) {
		$compact = !empty($params['compact']);
		$length = WaicUtils::getArrayValue($params, 'length', 10, 1);
		$start = WaicUtils::getArrayValue($params, 'start', 0, 1);
		//$search = WaicUtils::getArrayValue(WaicUtils::getArrayValue($params, 'search', array(), 2), 'value');

		/*if (!empty($search)) {
			$model->addWhere(array('additionalCondition' => "title like '%" . $search . "%'"));
		}*/
		$order = WaicUtils::getArrayValue($params, 'order', array(), 2);
		$orderBy = 0;
		$sortOrder = 'DESC';
		if ($compact) {
			$orders = array('id', 'id', 'title', 'tokens', 'status', 'created', 'author');
		} else {
			$orders = array('id', 'id', 'title', 'feature', 'tokens', 'status', 'created', 'author');
		}
		if (isset($order[0])) {
			$orderBy = WaicUtils::getArrayValue($order[0], 'column', $orderBy, 1);
			$sortOrder = WaicUtils::getArrayValue($order[0], 'dir', $sortOrder);
		}
		$feature = WaicUtils::getArrayValue($params, 'feature');
		if (!empty($feature)) {
			$list = WaicFrame::_()->getModule('workspace')->getFeaturesList();
			if (isset($list[$feature])) {
				$this->setWhere(array('feature' => $feature));
			}
		}
		$exclude = WaicUtils::getArrayValue($params, 'exclude');
		if (!empty($exclude)) {
			$this->setWhere(array('additionalCondition' => 'feature' . ( is_array($exclude) ? " NOT IN('" . implode("','", $exclude) . "')" : "!='" . $exclude . "'" )));
		}

		// Get total pages count for current request
		$totalCount = $this->getCount(array('clear' => array('selectFields')));
		if ($length > 0) {
			if ($start >= $totalCount) {
				$start = 0;
			}
			$this->setLimit($start . ', ' . $length);
		}
		if (!isset($orders[$orderBy])) {
			$orderBy = 6;
		}

		$this->setOrderBy($orders[$orderBy])->setSortOrder($sortOrder);
		$data = $this->getFromTbl();
		
		$data = empty($data) ? array() : $this->_prepareHistoryForTbl($data, $compact, empty($params['actions']) ? false : $params['actions']);
		return array(
			'data' => $data,
			'total' => $totalCount,
		);
	}
	
	public function _prepareHistoryForTbl( $tasks, $compact, $actions = false ) {
		$rows = array();

		$module = WaicFrame::_()->getModule('workspace');
		$features = $module->getFeaturesList();
		$statuses = $this->getStatuses();

		// Propagate bizcity_iframe from AJAX POST to $_GET so URL builders can see it
		if ( empty( $_GET['bizcity_iframe'] ) && ! empty( $_POST['bizcity_iframe'] ) && $_POST['bizcity_iframe'] === '1' ) {
			$_GET['bizcity_iframe'] = '1';
		}

		foreach ($tasks as $task) {
			$id = $task['id'];
			$feature = $task['feature'];
			$user = get_userdata($task['author']);
			$url = esc_url($module->getTaskUrl($id, $feature));
			$row = array(
				'<input type="checkbox" class="waicCheckOne" data-id="' . $id . '">',
				$id,
				'<a href="' . $url . '" class="waic-edit-link">' . ( empty($task['title']) ? '???' : $task['title'] ) . '</a>',
			);
			if (!$compact) {
				$row[] = ( empty($features[$feature]) ? '???' : $features[$feature] );
			}
			$row[] = $task['tokens'];
			$row[] = $statuses[$task['status']];
			$row[] = $task['created'];
			$row[] = ( $user ? $user->get('display_name') : $task['author'] );
			if (!$compact) {
				$row[] = '<a href="' . $url . '&show_settings=1"><i class="fa fa-gear"></i></a>';
			}
			if ($actions) {
				$row[] = $actions;
			}
			$rows[] = $row;
		}
		return $rows;
	}
}
