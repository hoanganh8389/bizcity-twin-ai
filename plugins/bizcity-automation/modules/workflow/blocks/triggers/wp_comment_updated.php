<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_comment_updated extends WaicTrigger {
	protected $_code = 'wp_comment_updated';
	protected $_hook = 'edit_comment';
	protected $_subtype = 2;
	protected $_order = 21;
	
	public function __construct( $block = null ) {
		$this->_name = __('Comment Updated', 'ai-copilot-content-generator');
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

		$this->_settings = array(
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
			'statuses' => array(
				'type' => 'multiple',
				'label' => __('Comment Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getCommentStatusesList(),
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
			$this->getPostVariables(), 
			$this->getCommentVariables(__('Comment', 'ai-copilot-content-generator'), __('Post', 'ai-copilot-content-generator')),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 1) {
			return false;
		}
		$commentId = $args[0];

		$comment = get_comment($commentId);
		if (!$comment || !in_array($comment->comment_type, array('', 'comment'))) {
			return false;
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
		
		$statuses = $this->getParam('statuses', array(), 2);
		if (!empty($statuses)) {
			if (!in_array(wp_get_comment_status($commentId), $statuses)) {
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
		
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_comment_id' => $commentId, 'waic_post_id' => $postId, 'waic_user_id' => $userId, 'obj_id' => $commentId);
	}
}
