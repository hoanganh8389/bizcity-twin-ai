<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_create_page extends WaicAction {
	protected $_code = 'wp_create_page';
	protected $_order = 30;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create Page', 'ai-copilot-content-generator');
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
		$args = array(
			'parent' => 0,
			'hide_empty' => 0,
			'orderby' => 'name',
			'order' => 'asc',
		);
		$this->_settings = array(
			'title' => array(
				'type' => 'input',
				'label' => __('Page Title', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'body' => array(
				'type' => 'textarea',
				'label' => __('Page Body', 'ai-copilot-content-generator') . ' *',
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
				'html' => true,
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
				'default' => 'open',
				'options' => array('open' => __('Open', 'ai-copilot-content-generator'), 'closed' => __('Closed', 'ai-copilot-content-generator')),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Post Author', 'ai-copilot-content-generator'),
				'default' => get_current_user_id(),
				'options' => $wordspace->getUsersList(),
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Page Status', 'ai-copilot-content-generator'),
				'default' => 'draft',
				'options' => get_page_statuses(),
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
		$post = array(
			'post_type' => 'page',
			'post_title' => $this->replaceVariables($this->getParam('title'), $variables),
			'post_content' => $this->replaceVariables($this->getParam('body'), $variables),
			'post_excerpt' => $this->replaceVariables($this->getParam('excerpt'), $variables),
			'post_author' => $this->getParam('author', 0, 1),
			'post_status' => $this->getParam('status'),
			'comment_status' => $this->getParam('comments'),
		);

		$error = '';
		if (empty($post['post_title'])) {
			$error = 'Page title needed';
		} else if (empty($post['post_content'])) {
			$error = 'Page body needed';
		}
		$postId = 0;
		if (empty($error)) {
			if (empty($post['status'])) {
				$post['status'] = 'publish';
			}
			$postId = wp_insert_post($post, true);
			if (is_wp_error($postId)) {
				$error = $postId->get_error_message();
			} 
		}
		if (empty($error)) {
			$image = (int) $this->replaceVariables($this->getParam('image'), $variables);
			if (!empty($image)) {
				$alt = $this->replaceVariables($this->getParam('alt'), $variables);
				if (empty($alt)) {
					$alt = $post->post_title;
				}
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
