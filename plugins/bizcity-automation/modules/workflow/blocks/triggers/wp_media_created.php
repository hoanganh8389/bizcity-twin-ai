<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wp_media_created extends WaicTrigger {
	protected $_code = 'wp_media_created';
	protected $_hook = 'add_attachment';
	protected $_subtype = 2;
	protected $_order = 30;
	
	public function __construct( $block = null ) {
		$this->_name = __('Media created', 'ai-copilot-content-generator');
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
			'excerpt' => array(
				'type' => 'input',
				'label' => __('Caption contains', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'mime' => array(
				'type' => 'multiple',
				'label' => __('Mime type', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getMimeTypeList(),
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
		$this->_variables = array_merge($this->getDTVariables(), $this->getMediaVariables());
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 1) {
			return false;
		}
		$attachmentId = $args[0];
		$attachment = get_post($attachmentId);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return false;
		}
		
		$title = $this->getParam('title');
		if (!empty($title)) {
			if (WaicUtils::mbstrpos($attachment->post_title, $title) === false) {
				return false;
			}
		} 
		
		$excerpt = $this->getParam('excerpt');
		if (!empty($excerpt)) {
			if (WaicUtils::mbstrpos($attachment->post_excerpt, $excerpt) === false) {
				return false;
			}
		} 
		
		$mimes = $this->getParam('mime', array(), 2);
		if (!empty($mimes)) {
			$filetype = wp_check_filetype(get_attached_file($attachmentId)); 
			if (!in_array($filetype['type'], $mimes)) {
				return false;
			}
		}
		
		$author = $this->getParam('author', 0, 1);
		if (!empty($author) && $attachment->post_author != $author) {
			return false;
		}
		
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_media_id' => $attachmentId, 'obj_id' => $attachmentId);
	}
	
}
