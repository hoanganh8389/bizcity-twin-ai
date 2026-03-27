<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_post_created extends WaicTrigger {
	protected $_code = 'wp_post_created';
	protected $_hook = 'wp_after_insert_post';
	protected $_subtype = 2;
	protected $_order = 3;
	
	public function __construct( $block = null ) {
		$this->_name = __('Post created', 'ai-copilot-content-generator');
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
		$statuses = array_merge(array('' => ''), get_post_statuses());
		$this->_settings = array(
			'title' => array(
				'type' => 'input',
				'label' => __('Title contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'body' => array(
				'type' => 'input',
				'label' => __('Body contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Post Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Author', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getUsersList(array(0 => '')),
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
		$this->_variables = array_merge($this->getDTVariables(), $this->getPostVariables());
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 4) {
			return false;
		}
		$postId = $args[0];
		$post = $args[1];
		$update = $args[2];
		$postBefore = $args[3];

		if ($post->post_status === 'auto-draft') {
			return false;
		}
		if ($post->post_type !== 'post') {
			return false;
		}
		if ($postBefore && $postBefore->ID !== 0) {
			if ($postBefore->post_status !== 'auto-draft' || $post->post_status === 'auto-draft') {
				return false;
			}
		}
		
		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			if ($post->post_author !== $author) {
				return false;
			}
		} 
		
		$status = $this->getParam('status');
		if (!empty($status)) {
			if ($post->post_status != $status) {
				return false;
			}
		} 
		
		$title = $this->getParam('title');
		if (!empty($title)) {
			if (WaicUtils::mbstrpos($post->post_title, $title) === false) {
				return false;
			}
		} 
		
		$body = $this->getParam('body');
		if (!empty($body)) {
			if (WaicUtils::mbstrpos($post->post_content, $body) === false) {
				return false;
			}
		} 
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_post_id' => $postId, 'obj_id' => $postId);
	}
	
}
