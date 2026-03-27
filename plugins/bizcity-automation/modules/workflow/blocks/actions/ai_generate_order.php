<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_ai_generate_order extends WaicAction {
	protected $_code = 'ai_generate_order';
	protected $_order = 104;

	public function __construct( $block = null ) {
		$this->_name = __('AI Generate WooCommerce Order', 'ai-copilot-content-generator');
		$this->_desc = __('Tạo đơn hàng WooCommerce từ AI, tự động nhận diện sản phẩm, khách hàng và thanh toán.', 'ai-copilot-content-generator');
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
				'label' => __('Thông tin đơn hàng (AI sẽ phân tích)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'customer_name' => array(
				'type' => 'input',
				'label' => __('Tên khách hàng (tùy chọn)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'customer_phone' => array(
				'type' => 'input',
				'label' => __('SĐT khách hàng (tùy chọn)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'customer_address' => array(
				'type' => 'input',
				'label' => __('Địa chỉ giao hàng (tùy chọn)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'payment_method' => array(
				'type' => 'select',
				'label' => __('Phương thức thanh toán', 'ai-copilot-content-generator'),
				'default' => 'cod',
				'options' => array(
					'cod' => __('COD (tiền mặt)', 'ai-copilot-content-generator'),
					'bank_transfer' => __('Chuyển khoản', 'ai-copilot-content-generator'),
				),
			),
			'shipping_cost' => array(
				'type' => 'input',
				'label' => __('Phí ship (số)', 'ai-copilot-content-generator'),
				'default' => '0',
				'variables' => true,
			),
			'discount' => array(
				'type' => 'input',
				'label' => __('Giảm giá (số)', 'ai-copilot-content-generator'),
				'default' => '0',
				'variables' => true,
			),
			'order_note' => array(
				'type' => 'textarea',
				'label' => __('Ghi chú đơn hàng', 'ai-copilot-content-generator'),
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
			'order_id' => __('ID đơn hàng', 'ai-copilot-content-generator'),
			'order_url' => __('Link đơn hàng', 'ai-copilot-content-generator'),
			'order_total' => __('Tổng tiền đơn hàng', 'ai-copilot-content-generator'),
			'qr_code_url' => __('QR Code thanh toán', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$chat_id          = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$message_text     = $this->replaceVariables($this->getParam('message'), $variables);
		$customer_name    = $this->replaceVariables($this->getParam('customer_name'), $variables);
		$customer_phone   = $this->replaceVariables($this->getParam('customer_phone'), $variables);
		$customer_address = $this->replaceVariables($this->getParam('customer_address'), $variables);
		$payment_method   = $this->replaceVariables($this->getParam('payment_method'), $variables);
		$shipping_cost    = $this->replaceVariables($this->getParam('shipping_cost'), $variables);
		$discount         = $this->replaceVariables($this->getParam('discount'), $variables);
		$order_note       = $this->replaceVariables($this->getParam('order_note'), $variables);

		// Kiểm tra function tồn tại
		if (!function_exists('biz_create_order')) {
			$error = 'Plugin BizGPT Order chưa được kích hoạt hoặc file orders.php chưa được load.';
			$this->_results = array(
				'result' => array(
					'order_id' => 0,
					'order_url' => '',
					'order_total' => 0,
					'qr_code_url' => '',
				),
				'error' => $error,
				'status' => 7,
			);
			return $this->_results;
		}

		// Chuẩn bị message
		$message = array(
			'text' => $message_text,
		);

		// Bổ sung thông tin khách hàng nếu có
		if (!empty($customer_name) || !empty($customer_phone) || !empty($customer_address)) {
			// Thêm vào message để AI parse
			if (!empty($customer_name)) {
				$message['text'] .= "\nKhách hàng: " . $customer_name;
			}
			if (!empty($customer_phone)) {
				$message['text'] .= "\nSĐT: " . $customer_phone;
			}
			if (!empty($customer_address)) {
				$message['text'] .= "\nĐịa chỉ: " . $customer_address;
			}
			if (!empty($payment_method)) {
				$message['text'] .= "\nThanh toán: " . $payment_method;
			}
			if (!empty($shipping_cost)) {
				$message['text'] .= "\nPhí ship: " . $shipping_cost;
			}
			if (!empty($discount)) {
				$message['text'] .= "\nGiảm giá: " . $discount;
			}
			if (!empty($order_note)) {
				$message['text'] .= "\nGhi chú: " . $order_note;
			}
		}

		// Gọi hàm tạo đơn hàng
		// Hàm này không return order_id mà gửi thông báo, nên cần sửa lại
		// Tạm thời capture output hoặc sửa hàm gốc
		ob_start();
		biz_create_order($message, $chat_id);
		$output = ob_get_clean();

		// Vì hàm gốc không return order_id, ta cần lấy order mới nhất của user
		// Hoặc sửa hàm gốc để return order_id
		global $wpdb;
		$order_id = $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} 
			WHERE post_type = 'shop_order' 
			AND post_author = %d 
			ORDER BY post_date DESC 
			LIMIT 1",
			get_current_user_id()
		));

		$error = '';
		if (empty($order_id)) {
			$error = 'Không tạo được đơn hàng hoặc không tìm thấy đơn hàng vừa tạo';
		}

		$order_total = 0;
		$qr_code_url = '';
		if ($order_id) {
			$order = wc_get_order($order_id);
			if ($order) {
				$order_total = $order->get_total();
				$qr_code_url = get_home_url() . '/vietqr/?order_id=' . $order_id . '&get_amount=' . $order_total . '&type=image.jpg';
			}
		}

		$this->_results = array(
			'result' => array(
				'order_id' => (int)$order_id,
				'order_url' => $order_id ? get_home_url() . '/pos-screen-print/?order_id=' . $order_id : '',
				'order_total' => $order_total,
				'qr_code_url' => $qr_code_url,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
