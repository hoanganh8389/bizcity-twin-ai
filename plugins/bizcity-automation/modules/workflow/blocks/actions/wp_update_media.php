<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_media extends WaicAction {
	protected $_code = 'wp_update_media';
	protected $_order = 40;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Media', 'ai-copilot-content-generator');
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
				'label' => __('Media ID', 'ai-copilot-content-generator') . ' *',
				'default' => '',
				'variables' => true,
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Media Title', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'textarea',
				'label' => __('Media Description', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'excerpt' => array(
				'type' => 'textarea',
				'label' => __('Media Caption', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'variables' => true,
			),
			'alt' => array(
				'type' => 'input',
				'label' => __('Alternative Text', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Media Author', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => WaicFrame::_()->getModule('workspace')->getUsersList(array('' => '')),
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
		$this->_variables = $this->getMediaVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$post = array();
		
		$postId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($postId)) {
			$error = 'Media ID needed';
		} else {
			$old = get_post($postId);
			if (!$old || $old->post_type !== 'attachment') {
				$error = 'Media not found (ID=' . $postId . ')';
			}
		}
		if (empty($error)) {
			$title = $this->getParam('title');
			if (!empty($title)) {
				$post['post_title'] = $this->replaceVariables($title, $variables);
			}
			$content = $this->getParam('content');
			if (!empty($content)) {
				$post['post_content'] = $this->replaceVariables($content, $variables);
			}
			$excerpt = $this->getParam('excerpt');
			if (!empty($excerpt)) {
				$post['post_excerpt'] = $this->replaceVariables($excerpt, $variables);
			}

			$author = $this->getParam('author', 0, 1);
			if (!empty($author)) {
				$post['post_author'] = $author;
			}
		}
		if (!empty($post)) {
			$post['ID'] = $postId;
			$updatedId = wp_update_post($post, true);
			if (is_wp_error($updatedId)) {
				$error = $updatedId->get_error_message();
			}
		}

		if (empty($error)) {
			$alt = trim($this->replaceVariables($this->getParam('alt'), $variables));
			if (!empty($alt)) {
				update_post_meta($postId, '_wp_attachment_image_alt', $alt);
			}
		}
		
		$this->_results = array(
			'result' => array('waic_media_id' => (int) $postId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
