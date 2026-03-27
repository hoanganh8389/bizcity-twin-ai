<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_page_updated extends WaicTrigger {
	protected $_code = 'wp_page_updated';
	protected $_hook = 'post_updated';
	protected $_subtype = 2;
	protected $_order = 11;
	
	public function __construct( $block = null ) {
		$this->_name = __('Page updated', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': post_updated';
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
			'ids' => array(
				'type' => 'input',
				'label' => __('Ids separated with commas', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'old_title' => array(
				'type' => 'input',
				'label' => __('Old Title contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'new_title' => array(
				'type' => 'input',
				'label' => __('New Title contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'old_body' => array(
				'type' => 'input',
				'label' => __('Old Body contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'new_body' => array(
				'type' => 'input',
				'label' => __('New Body contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'old_status' => array(
				'type' => 'select',
				'label' => __('Old Post Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'new_status' => array(
				'type' => 'select',
				'label' => __('New Post Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Post Author', 'ai-copilot-content-generator'),
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
		$this->_variables = array_merge($this->getDTVariables(), $this->getPageVariables());
		return $this->_variables;
	}
	
	public function controlRun( $args = array() ) {
		if (count($args) < 3) {
			return false;
		}
		$postId = $args[0];
		
		$ids = $this->getParam('ids');
		if (!empty($ids)) {
			if (!in_array($postId, $this->controlIdsArray(explode(',', $ids)))) {
				return false;
			}
		}
		
		$postAfter = $args[1];
		$postBefore = $args[2];
		
		if ($postBefore->post_type !== 'page') {
			return false;
		}
		
		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			if ($postAfter->post_author != $author) {
				return false;
			}
		} 
		
		$oldStatus = $this->getParam('old_status');
		if (!empty($oldStatus)) {
			if ($postBefore->post_status != $oldStatus) {
				return false;
			}
		} 
		$newStatus = $this->getParam('new_status');
		if (!empty($newStatus)) {
			if ($postAfter->post_status != $newStatus) {
				return false;
			}
		}
		
		$oldTitle = $this->getParam('old_title');
		if (!empty($oldTitle)) {
			if (WaicUtils::mbstrpos($postBefore->post_title, $oldTitle) === false) {
				return false;
			}
		} 
		$newTitle = $this->getParam('new_title');
		if (!empty($newTitle)) {
			if (WaicUtils::mbstrpos($postAfter->post_title, $newTitle) === false) {
				return false;
			}
		}
		
		$oldBody = $this->getParam('old_body');
		if (!empty($oldBody)) {
			if (WaicUtils::mbstrpos($postBefore->post_content, $oldBody) === false) {
				return false;
			}
		} 
		$newBody = $this->getParam('new_body');
		if (!empty($newBody)) {
			if (WaicUtils::mbstrpos($postAfter->post_content, $newBody) === false) {
				return false;
			}
		}
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_page_id' => $postId, 'obj_id' => $postId);
	}
	
}
