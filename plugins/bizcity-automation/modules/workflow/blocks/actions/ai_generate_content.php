<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAction_ai_generate_content extends WaicAction {
	protected $_code = 'ai_generate_content';
	protected $_order = 99;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate SEO Content', 'ai-copilot-content-generator');
		$this->_desc = __('Sinh bài viết chuẩn SEO, tự động lấy ảnh hoặc dùng attach_id có sẵn.', 'ai-copilot-content-generator');
		$this->_sublabel = array('name');
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
			'name' => array(
				'type' => 'input',
				'label' => __('Node Name', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'chat_id' => array(
				'type' => 'input',
				'label' => __('Chat ID', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Tiêu đề (nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'textarea',
				'label' => __('Nội dung (nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'image_url' => array(
				'type' => 'input',
				'label' => __('Ảnh đại diện (URL, nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'attach_id' => array(
				'type' => 'input',
				'label' => __('Attachment ID (nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'arr' => array(
				'type' => 'textarea',
				'label' => __('Mảng dữ liệu AI (nếu có)', 'ai-copilot-content-generator'),
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
		$this->_variables = array(
			'post_id' => __('ID bài viết', 'ai-copilot-content-generator'),
			'post_url' => __('Link bài viết', 'ai-copilot-content-generator'),
			'attach_id' => __('Attachment ID đã dùng', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id   = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$title     = $this->replaceVariables($this->getParam('title'), $variables);
		$content   = $this->replaceVariables($this->getParam('content'), $variables);
		$image_url = $this->replaceVariables($this->getParam('image_url'), $variables);
		$arr       = $this->replaceVariables($this->getParam('arr'), $variables);
		$attach_id = $this->replaceVariables($this->getParam('attach_id'), $variables);

		if (is_string($arr) && !empty($arr)) {
			$arr = json_decode($arr, true);
		}
		if (!is_array($arr)) $arr = array();

		if (!function_exists('biz_create_content')) {
			require_once WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/content.php';
		}
		$message = array(
			'text'    => $title,
			'caption' => $title,
		);
        $arr['info']['title'] = $title;
        $arr['info']['content'] = $content;

		// Nếu có attach_id, lấy ảnh từ media
		if (!empty($attach_id) && is_numeric($attach_id)) {
			$image_url = wp_get_attachment_url((int)$attach_id);
		}

		$post_id = biz_create_content($message, $chat_id, $title, $image_url, $arr);

		$error = '';
		if (empty($post_id) || is_wp_error($post_id)) {
			$error = is_wp_error($post_id) ? $post_id->get_error_message() : 'Không tạo được bài viết';
		}

		$this->_results = array(
			'result' => array(
				'post_id' => (int)$post_id,
				'post_url' => $post_id ? get_permalink($post_id) : '',
				'attach_id' => $attach_id,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
