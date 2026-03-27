<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_comment extends WaicAction {
	protected $_code = 'wp_update_comment';
	protected $_order = 21;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Comment', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': wp_login';
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
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Comment ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'textarea',
				'label' => __('Comment Content', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Comment Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), $this->getCommentStatusesList()),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Comment Author', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getUsersList(array(0 => '')),
			),
			'parent' => array(
				'type' => 'input',
				'label' => __('Reply to Comment Id', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
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
		$this->_variables = $this->getCommentVariables(__('Comment', 'ai-copilot-content-generator'), __('Post', 'ai-copilot-content-generator'));
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$commentId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($commentId)) {
			$error = 'Comment ID needed';
		} else {
			$comment = get_comment($commentId);
			if (!$comment) {
				$error = 'Comment not found';
			} else if (!in_array($comment->comment_type, array('', 'comment'))) {
				$error = 'It is not a comment';
			}
		}
		
		if (empty($error)) {
			$data = array('comment_ID' => $commentId);
			
			$content = $this->getParam('content');
			if (!empty($content)) {
				$data['comment_content'] = $this->replaceVariables($content, $variables);
			}
			$status = $this->getParam('status');
			if (!empty($status)) {
				if ('approved' == $status) {
					$status = 1;
				} else if ('spam' != $status && 'trash' != $status) {
					$status = 0;
				}
				$data['comment_approved'] = $status;
			}
			
			$userId = $this->getParam('author', 0, 1);
			if (!empty($userId)) {
				$name = '';
				$email = '';
				$user = get_userdata($userId);
				if ($user) {
					$name = $user->display_name;
					$email = $user->user_email;
				} else {
					$userId = 0;
				}
				$data['user_id'] = $userId;
				$data['comment_author'] = $name;
				$data['comment_author_email'] = $email;
			}
		}
		if (empty($error)) {
			$parent = (int) $this->replaceVariables($this->getParam('parent'), $variables);
			if (!empty($parent)) {
				$reply = get_comment($parent);
				if (!$reply) {
					$error = 'Comment for reply not found';
				} else if ('comment' != $reply->comment_type) {
					$error = 'Parent for reply is not comment';
				} else {
					$data['comment_parent'] = $parent;
				}
			}
		}
		if (empty($error)) {
			wp_update_comment($data);
		}
		
		$this->_results = array(
			'result' => array('waic_comment_id' => (int) $commentId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
