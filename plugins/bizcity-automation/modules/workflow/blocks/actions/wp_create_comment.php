<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_create_comment extends WaicAction {
	protected $_code = 'wp_create_comment';
	protected $_order = 20;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create Comment', 'ai-copilot-content-generator');
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
				'label' => __('Post ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'textarea',
				'label' => __('Comment Content', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Comment Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getCommentStatusesList(),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Comment Author', 'ai-copilot-content-generator'),
				'default' => get_current_user_id(),
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
		$postId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($postId)) {
			$error = 'Post ID needed';
		} else {
			$post = get_post($postId);
			if (!$post) {
				$error = 'Post not found';
			}
		}
		
		if (empty($error)) {
			$comment = array('comment_post_ID' => $postId, 'comment_type' => 'comment');
			
			$content = $this->getParam('content');
			if (!empty($content)) {
				$comment['comment_content'] = $this->replaceVariables($content, $variables);
			}
			$status = $this->getParam('status');
			if ('approved' == $status) {
				$status = 1;
			} else if ('spam' != $status && 'trash' != $status) {
				$status = 0;
			}
			$comment['comment_approved'] = $status;
			
			$userId = $this->getParam('author', 0, 1);
			$name = '';
			$email = '';
			if (!empty($userId)) {
				$user = get_userdata($userId);
				if ($user) {
					$name = $user->display_name;
					$email = $user->user_email;
				} else {
					$userId = 0;
				}
			}
			$comment['user_id'] = $userId;
			$comment['comment_author'] = $name;
			$comment['comment_author_email'] = $email;
		}
		if (empty($error)) {
			$parent = (int) $this->replaceVariables($this->getParam('parent'), $variables);
			if (!empty($parent)) {
				$reply = get_comment($parent);
				if (!$reply) {
					$error = 'Comment for reply not found';
				} else if ('comment' != $reply->comment_type) {
					$error = 'Parent is not comment';
				} else {
					$comment['comment_parent'] = $parent;
				}
			}
		}
		
		$commentId = 0;
		if (empty($error)) {
			$commentId = wp_insert_comment($comment);
		}

		$this->_results = array(
			'result' => array('waic_comment_id' => (int) $commentId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
