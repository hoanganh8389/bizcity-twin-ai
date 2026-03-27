<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicLogic_un_branch extends WaicLogic {
	protected $_code = 'un_branch';
	protected $_subtype = 2;
	protected $_order = 1;

	public function __construct( $block = null ) {
		$this->_name = __('Rẽ nhánh điều kiện (Nếu / Ngược lại)', 'ai-copilot-content-generator');
		$this->_desc = __('So sánh một giá trị (Criteria) với Value/Values để quyết định đi nhánh ĐÚNG hoặc SAI.', 'ai-copilot-content-generator');
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
				'label' => __('Tên node', 'ai-copilot-content-generator'),
				'default' => 'IF',
				'desc' => __('Đặt tên để dễ nhìn trên sơ đồ, ví dụ: "Kiểm tra còn thiếu thông tin?"', 'ai-copilot-content-generator'),
			),

			// ===== Criteria =====
			'criteria' => array(
				'type' => 'input',
				'label' => __('Giá trị cần kiểm tra (Criteria)', 'ai-copilot-content-generator'),
				'default' => '{{gap_count}}',
				'variables' => true,
				'desc' => __(
					'Nhập giá trị/biến cần đem đi so sánh. Ví dụ phổ biến:' . "\n" .
					'- {{gap_count}} (số trường còn thiếu)' . "\n" .
					'- {{task_intent}} (ý định)' . "\n" .
					'- {{task_entity}} (đối tượng)' . "\n" .
					'- {{gap_has__ten_san_pham_goi}} (có thiếu tên sản phẩm/gói hay không)',
					'ai-copilot-content-generator'
				),
			),

			// ===== Operator =====
			'operator' => array(
				'type' => 'select',
				'label' => __('Phép so sánh', 'ai-copilot-content-generator'),
				'default' => 'greater_than',
				'options' => array(
					'equals' => __('Bằng (=)', 'ai-copilot-content-generator'),
					'contains' => __('Chứa (contains)', 'ai-copilot-content-generator'),
					'does_not_equal' => __('Khác (!=)', 'ai-copilot-content-generator'),
					'greater_than' => __('Lớn hơn (>)', 'ai-copilot-content-generator'),
					'less_than' => __('Nhỏ hơn (<)', 'ai-copilot-content-generator'),
					'is_one_of' => __('Nằm trong danh sách (is one of)', 'ai-copilot-content-generator'),
					'is_not_one_of' => __('Không nằm trong danh sách (is not one of)', 'ai-copilot-content-generator'),
					'is_known' => __('Có dữ liệu (không rỗng)', 'ai-copilot-content-generator'),
					'is_unknown' => __('Không có dữ liệu (rỗng)', 'ai-copilot-content-generator'),
				),
				'desc' => __(
					'Chọn phép so sánh để quyết định rẽ nhánh:' . "\n" .
					'- "Lớn hơn (>)": thường dùng cho số như {{gap_count}} > 0' . "\n" .
					'- "Bằng (=)": so sánh text/number' . "\n" .
					'- "Chứa": kiểm tra chuỗi có chứa từ khoá' . "\n" .
					'- "Có dữ liệu": chỉ cần Criteria không rỗng là ĐÚNG',
					'ai-copilot-content-generator'
				),
			),

			// ===== Value (single) =====
			'value' => array(
				'type' => 'input',
				'label' => __('Giá trị so sánh (Value)', 'ai-copilot-content-generator'),
				'default' => '0',
				'show' => array('operator' => array('equals', 'contains', 'does_not_equal', 'greater_than', 'less_than')),
				'variables' => true,
				'desc' => __(
					'Nhập giá trị để so với Criteria. Ví dụ mẫu:' . "\n" .
					'- Criteria={{gap_count}}, Operator="Lớn hơn", Value=0  → Nếu còn thiếu thông tin' . "\n" .
					'- Criteria={{task_intent}}, Operator="Bằng", Value=inquire_price → Nếu khách hỏi giá' . "\n" .
					'- Criteria={{node#1.twf_text}}, Operator="Chứa", Value=task → Nếu tin nhắn có chữ "task"',
					'ai-copilot-content-generator'
				),
			),

			// ===== Values (list) =====
			'values' => array(
				'type' => 'input',
				'label' => __('Danh sách giá trị (Values, ngăn cách bằng dấu phẩy)', 'ai-copilot-content-generator'),
				'default' => 'inquire_price,inquire_feature,inquire_purchase',
				'show' => array('operator' => array('is_one_of', 'is_not_one_of')),
				'variables' => true,
				'desc' => __(
					'Dùng khi bạn muốn kiểm tra Criteria có nằm trong 1 nhóm hay không.' . "\n" .
					'Ví dụ:' . "\n" .
					'- Criteria={{task_intent}}, Operator="Nằm trong danh sách", Values=inquire_price,inquire_purchase',
					'ai-copilot-content-generator'
				),
			),

			// ===== Compare type =====
			'compare' => array(
				'type' => 'select',
				'label' => __('Kiểu so sánh (So sánh theo dạng gì)', 'ai-copilot-content-generator'),
				'default' => 'number',
				'show' => array('operator' => array('equals', 'does_not_equal', 'greater_than', 'less_than', 'is_one_of', 'is_not_one_of')),
				'options' => array(
					'text' => __('Chuỗi (text)', 'ai-copilot-content-generator'),
					'number' => __('Số (number)', 'ai-copilot-content-generator'),
				),
				'desc' => __(
					'Chọn kiểu so sánh:' . "\n" .
					'- Số: dùng cho {{gap_count}}, giá tiền, điểm số...' . "\n" .
					'- Chuỗi: dùng cho intent/entity, từ khoá...' ,
					'ai-copilot-content-generator'
				),
			),

			// ===== Quick guide / templates (UI-only, nhưng vẫn ok dạng textarea) =====
			'__guide' => array(
				'type' => 'textarea',
				'label' => __('Mẫu cấu hình nhanh (tham khảo)', 'ai-copilot-content-generator'),
				'default' =>
"1) Còn thiếu thông tin?\n".
"- Giá trị cần kiểm tra: {{gap_count}}\n".
"- Phép so sánh: Lớn hơn (>)\n".
"- Giá trị so sánh: 0\n".
"- So sánh theo: Số\n\n".
"2) Khách hỏi giá?\n".
"- Giá trị cần kiểm tra: {{task_intent}}\n".
"- Phép so sánh: Bằng (=)\n".
"- Giá trị so sánh: inquire_price\n".
"- So sánh theo: Chuỗi\n\n".
"3) Có thiếu tên sản phẩm?\n".
"- Giá trị cần kiểm tra: {{gap_has__ten_san_pham_goi}}\n".
"- Phép so sánh: Có dữ liệu (không rỗng)\n\n".
"Lưu ý: Field này chỉ để xem mẫu, không ảnh hưởng logic chạy.",
				'desc' => __('Copy mẫu ở đây rồi dán vào các field bên trên cho nhanh.', 'ai-copilot-content-generator'),
			),
		);
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		$result = false;

		$criteria = $this->replaceVariables($this->getParam('criteria'), $variables);

		$value = $this->replaceVariables($this->getParam('value'), $variables);
		$values = explode(',', $this->replaceVariables($this->getParam('values'), $variables));

		$isNumber = $this->getParam('compare') == 'number';
		if ($isNumber) {
			$criteria = (float) $criteria;
			$value = (float) $value;
			if (!empty($values)) {
				$values = $this->controlIdsArray($values);
			}
		}

		switch ($this->getParam('operator')) {
			case 'equals':
				if ($criteria == $value) $result = true;
				break;
			case 'contains':
				if (WaicUtils::mbstrpos($criteria, $value) !== false) $result = true;
				break;
			case 'does_not_equal':
				if ($criteria != $value) $result = true;
				break;
			case 'greater_than':
				if ($criteria > $value) $result = true;
				break;
			case 'less_than':
				if ($criteria < $value) $result = true;
				break;
			case 'is_one_of':
				if (in_array($criteria, $values)) $result = true;
				break;
			case 'is_not_one_of':
				if (!in_array($criteria, $values)) $result = true;
				break;
			case 'is_known':
				if (!empty($criteria)) $result = true;
				break;
			case 'is_unknown':
				if (empty($criteria)) $result = true;
				break;
		}

		$this->_results = array(
			'result' => array('result' => $result),
			'error' => '',
			'status' => 3,
			'sourceHandle' => $result ? 'output-then' : 'output-else',
		);
		return $this->_results;
	}
}
