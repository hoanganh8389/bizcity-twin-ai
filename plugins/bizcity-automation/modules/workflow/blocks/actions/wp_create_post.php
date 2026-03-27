<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_create_post extends WaicAction {
	protected $_code = 'wp_create_post';
	protected $_order = 10;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create Post', 'ai-copilot-content-generator');
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
				'label' => __('Post Title *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'body' => array(
				'type' => 'textarea',
				'label' => __('Post Body *', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'categories' => array(
				'type' => 'multiple',
				'label' => __('Post Categories', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('category', $args),
			),
			'tags' => array(
				'type' => 'multiple',
				'label' => __('Post Tags', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('post_tag', $args),
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Post Status', 'ai-copilot-content-generator'),
				'default' => 'draft',
				'options' => get_post_statuses(),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Post Author', 'ai-copilot-content-generator'),
				'default' => get_current_user_id(),
				'options' => $wordspace->getUsersList(),
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
		$this->_variables = $this->getPostVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$post = array(
			'post_type' => 'post',
			'post_title' => $this->replaceVariables($this->getParam('title'), $variables),
			'post_content' => $this->replaceVariables($this->getParam('body'), $variables),
			'post_author' => $this->getParam('author', 0, 1),
			'post_category' => $this->controlIdsArray($this->getParam('categories', array(), 2)),
			'tags_input' => $this->controlIdsArray($this->getParam('tags', array(), 2)),
			'post_status' => $this->getParam('status'),
		);

		$error = '';
		if (empty($post['post_title'])) {
			$error = 'Post title needed';
		} else if (empty($post['post_content'])) {
			$error = 'Post body needed';
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

		$this->_results = array(
			'result' => array('waic_post_id' => (int) $postId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
