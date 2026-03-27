<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_facebook extends WaicAction {
	protected $_code = 'ai_generate_facebook';
	protected $_order = 101;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate Facebook Post', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo bài đăng Facebook từ AI, tự động sinh title/content/hashtag và đăng lên fanpage.', 'ai-copilot-content-generator');
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
			'message' => array(
				'type' => 'textarea',
				'label' => __('Thông điệp chủ đề bài Facebook', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'image_url' => array(
				'type' => 'input',
				'label' => __('Ảnh bài đăng (URL, nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'attach_id' => array(
				'type' => 'input',
				'label' => __('Attachment ID (nếu có)', 'ai-copilot-content-generator'),
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
			'post_id' => __('ID bài Facebook', 'ai-copilot-content-generator'),
			'post_url' => __('Link bài Facebook', 'ai-copilot-content-generator'),
			'attach_id' => __('Attachment ID đã dùng', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id   = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$message_text = $this->replaceVariables($this->getParam('message'), $variables);
		$image_url = $this->replaceVariables($this->getParam('image_url'), $variables);
		$attach_id = $this->replaceVariables($this->getParam('attach_id'), $variables);

		// Kiểm tra function tồn tại
		if (!function_exists('biz_create_facebook')) {
			$error = 'Plugin BizGPT Facebook chưa được kích hoạt hoặc file bizgpt_facebook.php chưa được load.';
			$this->_results = array(
				'result' => array(
					'post_id' => 0,
					'post_url' => '',
					'attach_id' => '',
				),
				'error' => $error,
				'status' => 7,
			);
			return $this->_results;
		}

		// Chuẩn bị message
		$message = array(
			'text'    => $message_text,
			'caption' => $message_text,
		);

		// Nếu có attach_id, lấy ảnh từ media
		if (!empty($attach_id) && is_numeric($attach_id)) {
			$image_url = wp_get_attachment_url((int)$attach_id);
		}

		$data = array(
			'image_url' => $image_url,
		);

		// Gọi hàm tạo bài Facebook
		$post_id = biz_create_facebook($chat_id, $message, $data);

		$error = '';
		if (empty($post_id) || is_wp_error($post_id)) {
			$error = is_wp_error($post_id) ? $post_id->get_error_message() : 'Không tạo được bài Facebook';
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
