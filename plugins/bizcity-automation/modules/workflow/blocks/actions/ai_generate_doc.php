<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_doc extends WaicAction {
	protected $_code = 'ai_generate_doc';
	protected $_order = 102;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate Document', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo tài liệu lưu trữ từ file/ảnh, tự động OCR và phân loại.', 'ai-copilot-content-generator');
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
				'label' => __('Tiêu đề tài liệu', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'summary' => array(
				'type' => 'textarea',
				'label' => __('Tóm tắt nội dung', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'file_url' => array(
				'type' => 'input',
				'label' => __('File URL (ảnh/PDF/Word)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'attach_id' => array(
				'type' => 'input',
				'label' => __('Attachment ID (nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'tags' => array(
				'type' => 'input',
				'label' => __('Tags (phân cách bằng dấu phẩy)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'category' => array(
				'type' => 'select',
				'label' => __('Phân loại', 'ai-copilot-content-generator'),
				'default' => 'khac',
				'options' => array(
					'gia_dinh' => __('Gia đình', 'ai-copilot-content-generator'),
					'ca_nhan' => __('Cá nhân', 'ai-copilot-content-generator'),
					'cong_viec' => __('Công việc', 'ai-copilot-content-generator'),
					'khac' => __('Khác', 'ai-copilot-content-generator'),
				),
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
			'doc_id' => __('ID tài liệu', 'ai-copilot-content-generator'),
			'doc_url' => __('Link tài liệu', 'ai-copilot-content-generator'),
			'attach_id' => __('Attachment ID đã dùng', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id   = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$title     = $this->replaceVariables($this->getParam('title'), $variables);
		$summary   = $this->replaceVariables($this->getParam('summary'), $variables);
		$file_url  = $this->replaceVariables($this->getParam('file_url'), $variables);
		$attach_id = $this->replaceVariables($this->getParam('attach_id'), $variables);
		$tags      = $this->replaceVariables($this->getParam('tags'), $variables);
		$category  = $this->replaceVariables($this->getParam('category'), $variables);

		// Kiểm tra function tồn tại
		if (!function_exists('biz_create_doc')) {
			$error = 'Plugin BizGPT Document chưa được kích hoạt hoặc file bizgpt_doc.php chưa được load.';
			$this->_results = array(
				'result' => array(
					'doc_id' => 0,
					'doc_url' => '',
					'attach_id' => '',
				),
				'error' => $error,
				'status' => 7,
			);
			return $this->_results;
		}

		// Nếu có attach_id, lấy file URL từ media
		if (!empty($attach_id) && is_numeric($attach_id)) {
			$file_url = wp_get_attachment_url((int)$attach_id);
		}

		// Chuẩn bị message
		$message = array(
			'caption' => $summary,
		);

		// Chuẩn bị data
		$data = array(
			'image_url' => $file_url,
			'title' => $title,
			'summary' => $summary,
			'tags' => $tags,
			'category' => $category,
		);

		// Gọi hàm tạo tài liệu
		$doc_id = biz_create_doc($chat_id, $message, $file_url, $data);

		$error = '';
		if (empty($doc_id) || is_wp_error($doc_id)) {
			$error = is_wp_error($doc_id) ? $doc_id->get_error_message() : 'Không tạo được tài liệu';
		}

		$this->_results = array(
			'result' => array(
				'doc_id' => (int)$doc_id,
				'doc_url' => $doc_id ? get_permalink($doc_id) : '',
				'attach_id' => $attach_id,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
