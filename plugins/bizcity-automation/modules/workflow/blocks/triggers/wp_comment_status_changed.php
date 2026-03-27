<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_comment_status_changed extends WaicTrigger {
	protected $_code = 'wp_comment_status_changed';
	protected $_hook = 'transition_comment_status';
	protected $_subtype = 2;
	protected $_order = 22;
	
	public function __construct( $block = null ) {
		$this->_name = __('Comment Status Changed', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
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
		$statuses = $this->getCommentStatusesList();

		$this->_settings = array(
			'status_old' => array(
				'type' => 'multiple',
				'label' => __('Comment Old Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'status_new' => array(
				'type' => 'multiple',
				'label' => __('Comment New Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'created_from' => array(
				'type' => 'date',
				'label' => __('Created date', 'ai-copilot-content-generator'),
				'default' => '',
				'add' => array('created_to'),
			),
			'created_to' => array(
				'type' => 'date',
				'label' => '',
				'default' => '',
				'inner' => true,
			),
			'content' => array(
				'type' => 'input',
				'label' => __('Content contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'role' => array(
				'type' => 'multiple',
				'label' => __('User Role', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getRolesList(),
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
		$this->_variables = array_merge(
			$this->getProductVariables(), 
			$this->getCommentVariables(__('Comment', 'ai-copilot-content-generator'), __('Post', 'ai-copilot-content-generator')),
			array('com_status_old' => __('Comment Old Status', 'ai-copilot-content-generator')),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 3) {
			return false;
		}
		$newStatus = $args[0];
		$oldStatus = $args[1];
		$comment = $args[2];
		
		if (!$comment || !in_array($comment->comment_type, array('', 'comment'))) {
			return false;
		}
		$commentId = $comment->comment_ID;

		$stOld = $this->getParam('status_old', array(), 2);
		if (!empty($stOld)) {
			if (!in_array($oldStatus, $stOld)) {
				return false;
			}
		}
		$stNew = $this->getParam('status_new', array(), 2);
		if (!empty($stNew)) {
			if (!in_array($newStatus, $stNew)) {
				return false;
			}
		}
		$comDate = substr($comment->comment_date, 0, 10);
		$from = $this->getParam('created_from');
		if (!empty($from)) {
			if ($comDate < $from) {
				return false;
			}
		}
		$to = $this->getParam('created_to');
		if (!empty($to)) {
			if ($comDate > $to) {
				return false;
			}
		}

		$content = $this->getParam('content');
		if (!empty($content)) {
			$commentContent = trim($comment->comment_content);
			if (WaicUtils::mbstrpos($commentContent, $content) === false) {
				return false;
			}
		}
		
		$roles = $this->getParam('role', array(), 2);
		$userId = $comment->user_id;
		if (!empty($roles)) {
			if (!$userId) {
				return false;
			}
			$user = get_user_by('id', (int) $userId);
			if (!$user) {
				return false;
			}
			$userRoles = $user->roles;
			$found = false;
			foreach ($roles as $role) {
				if (in_array($role, $userRoles)) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				return false;
			}
		}
		$postId = (int) $comment->comment_post_ID;
		
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_comment_id' => $commentId, 'com_status_old' => $oldStatus, 'waic_post_id' => $postId, 'waic_user_id' => $userId, 'obj_id' => $commentId);
	}
}
