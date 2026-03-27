<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_post extends WaicAction {
	protected $_code = 'wp_update_post';
	protected $_order = 11;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Post', 'ai-copilot-content-generator');
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
				'label' => __('Post ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Post Title', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'body' => array(
				'type' => 'textarea',
				'label' => __('Post Body', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'html' => true,
				'variables' => true,
			),
			'excerpt' => array(
				'type' => 'textarea',
				'label' => __('Post Excerpt', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 3,
				'variables' => true,
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Post Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array_merge(array('' => ''), get_post_statuses()),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Post Author', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => WaicFrame::_()->getModule('workspace')->getUsersList(array('' => '')),
			),
			// NEW: UI chọn thumbnail id
			'thumbnail_id' => array(
				'type' => 'input',
				'label' => __('Thumbnail ID (Attachment)', 'ai-copilot-content-generator'),
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
		$this->_variables = $this->getPostVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$post = array('ID' => (int) $this->replaceVariables($this->getParam('id'), $variables));

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
		$status = $this->getParam('status');
		if (!empty($status)) {
			$post['post_status'] = $status;
		}
		$author = $this->getParam('author', 0, 1);
		if (!empty($author)) {
			$post['post_author'] = $author;
		}
		$thumbnail_id = $this->replaceVariables($this->getParam('thumbnail_id'), $variables);

		if (empty($post['ID'])) {
			$error = 'Post ID needed';
		} else {
			$old = get_post($post['ID']);
			if (!$old) {
				$error = 'Post not found (ID=' . $post['ID'] . ')';
			}
		}

		if (empty($error)) {
			$postId = wp_update_post($post);
			if (is_wp_error($postId)) {
				$error = $postId->get_error_message();
			} else {
				// Nếu có attachment_id, set thumbnail
				if (!empty($variables['attachment_id'])) {
					set_post_thumbnail($postId, $variables['attachment_id']);
				}
			}
		}

		$this->_results = array(
			'result' => array('waic_post_id' => (int) $postId, 'thumbnail_id' => $thumbnail_id),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
