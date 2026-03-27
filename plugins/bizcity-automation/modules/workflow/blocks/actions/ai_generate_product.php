<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_product extends WaicAction {
	protected $_code = 'ai_generate_product';
	protected $_order = 100;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate Product', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo sản phẩm WooCommerce từ AI, tự động lấy ảnh hoặc dùng attach_id có sẵn.', 'ai-copilot-content-generator');
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
				'label' => __('Thông điệp mô tả sản phẩm', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'arr' => array(
				'type' => 'textarea',
				'label' => __('Mảng dữ liệu AI (nếu có)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'image_url' => array(
				'type' => 'input',
				'label' => __('Ảnh sản phẩm (URL, nếu có)', 'ai-copilot-content-generator'),
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
			'product_id' => __('ID sản phẩm', 'ai-copilot-content-generator'),
			'product_url' => __('Link sản phẩm', 'ai-copilot-content-generator'),
			'attach_id' => __('Attachment ID đã dùng', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id   = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$title   = $this->replaceVariables($this->getParam('message'), $variables);
		$arr       = $this->replaceVariables($this->getParam('arr'), $variables);
		$image_url = $this->replaceVariables($this->getParam('image_url'), $variables);
		$attach_id = $this->replaceVariables($this->getParam('attach_id'), $variables);

		if (is_string($arr) && !empty($arr)) {
			$arr = json_decode($arr, true);
		}
		if (!is_array($arr)) $arr = array();

		if (!function_exists('biz_create_product')) {
			require_once WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/woo.php';
		}


		// Nếu có attach_id, lấy ảnh từ media
		if (!empty($attach_id) && is_numeric($attach_id)) {
			$image_url = wp_get_attachment_url((int)$attach_id);
		}
        $message = array(
			'text'    => $title,
			'caption' => $title,
		);
        $arr['info']['title'] = $title;

		// Gọi hàm xử lý đăng sản phẩm AI
		$product_id = biz_create_product($message, $chat_id, $arr, $image_url);

		$error = '';
		if (empty($product_id) || is_wp_error($product_id)) {
			$error = is_wp_error($product_id) ? $product_id->get_error_message() : 'Không tạo được sản phẩm';
		}

		$this->_results = array(
			'result' => array(
				'product_id' => (int)$product_id,
				'product_url' => $product_id ? get_permalink($product_id) : '',
				'attach_id' => $attach_id,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
