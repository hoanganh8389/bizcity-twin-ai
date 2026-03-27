<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_task extends WaicAction {
	protected $_code = 'ai_generate_task';
	protected $_order = 103;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate Task Reminder', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo nhắc việc tự động từ AI, hỗ trợ lặp lại hàng ngày và nhắc lúc.', 'ai-copilot-content-generator');
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
				'label' => __('Nội dung nhắc việc (AI sẽ phân tích)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Tiêu đề công việc (tùy chọn)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'remind_at' => array(
				'type' => 'input',
				'label' => __('Nhắc lúc (YYYY-MM-DD HH:MM)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'repeat_daily' => array(
				'type' => 'select',
				'label' => __('Lặp lại hàng ngày?', 'ai-copilot-content-generator'),
				'default' => '0',
				'options' => array(
					'0' => __('Không', 'ai-copilot-content-generator'),
					'1' => __('Có', 'ai-copilot-content-generator'),
				),
			),
			'category' => array(
				'type' => 'select',
				'label' => __('Phân loại', 'ai-copilot-content-generator'),
				'default' => 'khac',
				'options' => array(
					'gia_dinh' => __('Gia đình', 'ai-copilot-content-generator'),
					'van_phong' => __('Văn phòng', 'ai-copilot-content-generator'),
					'du_an' => __('Dự án', 'ai-copilot-content-generator'),
					'khac' => __('Khác', 'ai-copilot-content-generator'),
				),
			),
			'arr' => array(
				'type' => 'textarea',
				'label' => __('Mảng dữ liệu AI (JSON, nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'platform' => array(
				'type' => 'input',
				'label' => __('Platform (telegram/zalo)', 'ai-copilot-content-generator'),
				'default' => 'telegram',
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
			'task_id' => __('ID nhắc việc', 'ai-copilot-content-generator'),
			'task_url' => __('Link nhắc việc', 'ai-copilot-content-generator'),
			'remind_at' => __('Thời gian nhắc', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id      = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$message_text = $this->replaceVariables($this->getParam('message'), $variables);
		$title        = $this->replaceVariables($this->getParam('title'), $variables);
		$remind_at    = $this->replaceVariables($this->getParam('remind_at'), $variables);
		$repeat_daily = $this->replaceVariables($this->getParam('repeat_daily'), $variables);
		$category     = $this->replaceVariables($this->getParam('category'), $variables);
		$arr          = $this->replaceVariables($this->getParam('arr'), $variables);
		$platform     = $this->replaceVariables($this->getParam('platform'), $variables);

		// Kiểm tra function tồn tại
		if (!function_exists('biz_create_task')) {
			$error = 'Plugin BizGPT Task chưa được kích hoạt hoặc file bizgpt_task.php chưa được load.';
			$this->_results = array(
				'result' => array(
					'task_id' => 0,
					'task_url' => '',
					'remind_at' => '',
				),
				'error' => $error,
				'status' => 7,
			);
			return $this->_results;
		}

		if (is_string($arr) && !empty($arr)) {
			$arr = json_decode($arr, true);
		}
		if (!is_array($arr)) $arr = array();

		// Chuẩn bị message
		$message = array(
			'text' => $message_text,
		);

		// Bổ sung thông tin vào arr nếu có
		if (!empty($title)) {
			$arr['title'] = $title;
		}
		if (!empty($remind_at)) {
			$arr['remind_at'] = $remind_at;
		}
		if (!empty($repeat_daily)) {
			$arr['repeat_daily'] = ($repeat_daily === '1' || $repeat_daily === true);
		}
		if (!empty($category)) {
			$arr['category'] = $category;
		}

		// Gọi hàm tạo task
		$task_id = biz_create_task($chat_id, $message, $arr, $platform);

		$error = '';
		if (empty($task_id) || is_wp_error($task_id)) {
			$error = is_wp_error($task_id) ? $task_id->get_error_message() : 'Không tạo được nhắc việc';
		}

		// Lấy thông tin remind_at từ post meta
		$saved_remind_at = '';
		if ($task_id) {
			$saved_remind_at = get_post_meta($task_id, '_reminder_time', true);
		}

		$this->_results = array(
			'result' => array(
				'task_id' => (int)$task_id,
				'task_url' => $task_id ? get_permalink($task_id) : '',
				'remind_at' => $saved_remind_at,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
