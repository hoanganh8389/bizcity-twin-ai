<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_page extends WaicAction {
	protected $_code = 'wp_update_page';
	protected $_order = 31;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Page', 'ai-copilot-content-generator');
		$this->_desc = __('Only filled fields will be updated.', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Page ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Page Title', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'body' => array(
				'type' => 'textarea',
				'label' => __('Page Body', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'excerpt' => array(
				'type' => 'textarea',
				'label' => __('Page Excerpt', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'variables' => true,
			),
			'image' => array(
				'type' => 'input',
				'label' => __('Featured Image', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'alt' => array(
				'type' => 'input',
				'label' => __('Image Alt', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'comments' => array(
				'type' => 'select',
				'label' => __('Discussion', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array('' => '', 'open' => __('Open', 'ai-copilot-content-generator'), 'closed' => __('Closed', 'ai-copilot-content-generator')),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Page Author', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => WaicFrame::_()->getModule('workspace')->getUsersList(array('' => '')),
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Page Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), get_page_statuses()),
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
		$this->_variables = $this->getPageVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$post = array();
		
		$title = $this->getParam('title');
		if (!empty($title)) {
			$post['post_title'] = $this->replaceVariables($title, $variables);
		}
		$body = $this->getParam('body');
		if (!empty($body)) {
			$post['post_content'] = $this->replaceVariables($body, $variables);
		}
		$excerpt = $this->getParam('excerpt');
		if (!empty($excerpt)) {
			$post['post_excerpt'] = $this->replaceVariables($excerpt, $variables);
		}
		$comments = $this->getParam('comments');
		if (!empty($comments)) {
			$post['comment_status'] = $comments;
		}
		$status = $this->getParam('status');
		if (!empty($status)) {
			$post['post_status'] = $status;
		}
		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			$post['post_author'] = $author;
		}
		
		$postId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		
		if (empty($postId)) {
			$error = 'Page ID needed';
		} else {
			$old = get_post($postId);
			if (!$old) {
				$error = 'Page not found (ID=' . $postId . ')';
			}
		}

		if (empty($error) && !empty($post)) {
			$post['ID'] = $postId;
			$postId = wp_update_post($post);
			if (is_wp_error($postId)) {
				$error = $postId->get_error_message();
			}
		}
		if (empty($error)) {
			$image = (int) $this->replaceVariables($this->getParam('image'), $variables);
			$alt = trim($this->replaceVariables($this->getParam('alt'), $variables));
			if (!empty($image) || !empty($alt)) {
				$error = $this->addPostImage($postId, $image, $alt);
			}
		}
		
		$this->_results = array(
			'result' => array('waic_page_id' => (int) $postId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
