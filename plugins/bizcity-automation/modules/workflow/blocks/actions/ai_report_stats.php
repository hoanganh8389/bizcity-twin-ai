<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_report_stats extends WaicAction {
	protected $_code = 'ai_report_stats';
	protected $_order = 105;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate Report Statistics', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo báo cáo thống kê đơn hàng, sản phẩm, khách hàng theo yêu cầu AI.', 'ai-copilot-content-generator');
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
			'report_type' => array(
				'type' => 'select',
				'label' => __('Loại báo cáo', 'ai-copilot-content-generator'),
				'default' => 'order_stats',
				'options' => array(
					'order_stats' => __('Thống kê đơn hàng', 'ai-copilot-content-generator'),
					'top_products' => __('Sản phẩm bán chạy', 'ai-copilot-content-generator'),
					'top_customers' => __('Khách hàng top', 'ai-copilot-content-generator'),
				),
			),
			'date_from' => array(
				'type' => 'input',
				'label' => __('Từ ngày (YYYY-MM-DD)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'date_to' => array(
				'type' => 'input',
				'label' => __('Đến ngày (YYYY-MM-DD)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'days' => array(
				'type' => 'input',
				'label' => __('Số ngày gần nhất (nếu không chọn ngày)', 'ai-copilot-content-generator'),
				'default' => '3',
				'variables' => true,
			),
			'message' => array(
				'type' => 'textarea',
				'label' => __('Yêu cầu báo cáo (AI sẽ phân tích)', 'ai-copilot-content-generator'),
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
			'report_data' => __('Dữ liệu báo cáo (JSON)', 'ai-copilot-content-generator'),
			'report_message' => __('Thông điệp báo cáo', 'ai-copilot-content-generator'),
			'file_url' => __('Link file báo cáo (CSV)', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id      = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$report_type  = $this->replaceVariables($this->getParam('report_type'), $variables);
		$date_from    = $this->replaceVariables($this->getParam('date_from'), $variables);
		$date_to      = $this->replaceVariables($this->getParam('date_to'), $variables);
		$days         = $this->replaceVariables($this->getParam('days'), $variables);
		$message_text = $this->replaceVariables($this->getParam('message'), $variables);

		$error = '';
		$report_data = array();
		$report_message = '';
		$file_url = '';

		// Xác định ngày nếu không có
		if (empty($date_from) || empty($date_to)) {
			if (empty($days)) $days = 3;
			$date_to = current_time('Y-m-d');
			$date_from = date('Y-m-d', strtotime($date_to . ' -' . ($days - 1) . ' days'));
		}

		switch ($report_type) {
			case 'order_stats':
				// Kiểm tra function
				if (!function_exists('twf_get_order_stats_range')) {
					$error = 'Plugin BizGPT Thống kê chưa được kích hoạt hoặc file thongke.php chưa được load.';
					break;
				}
				
				$stats = twf_get_order_stats_range($date_from, $date_to);
				$report_data = $stats;
				
				if (function_exists('twf_format_range_report')) {
					$report_message = twf_format_range_report($stats, false);
				} else {
					$report_message = json_encode($stats, JSON_UNESCAPED_UNICODE);
				}
				
				// Gửi thông báo qua Telegram nếu có chat_id
				if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
					twf_telegram_send_message($chat_id, $report_message);
				}
				break;

			case 'top_products':
				// Kiểm tra function
				if (!function_exists('twf_bao_cao_top_product')) {
					$error = 'Plugin BizGPT Thống kê hàng hóa chưa được kích hoạt hoặc file thongke_hanghoa.php chưa được load.';
					break;
				}
				
				$result = twf_bao_cao_top_product($chat_id, $date_to, (int)$days);
				if ($result) {
					$report_data = $result;
					$report_message = "Đã tạo báo cáo sản phẩm bán chạy từ {$date_from} đến {$date_to}";
				} else {
					$error = 'Không có dữ liệu sản phẩm';
				}
				break;

			case 'top_customers':
				// Kiểm tra function
				if (!function_exists('twf_bao_cao_top_customers')) {
					$error = 'Plugin BizGPT Thống kê khách hàng chưa được kích hoạt hoặc file thongke_khachhang.php chưa được load.';
					break;
				}
				
				twf_bao_cao_top_customers($chat_id, $date_to, (int)$days);
				$report_message = "Đã tạo báo cáo khách hàng top từ {$date_from} đến {$date_to}";
				break;

			default:
				$error = 'Loại báo cáo không hợp lệ';
				break;
		}

		$this->_results = array(
			'result' => array(
				'report_data' => $report_data,
				'report_message' => $report_message,
				'file_url' => $file_url,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
