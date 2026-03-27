<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicModulesModel extends WaicModel {
	public function __construct() {
		$this->_setTbl('modules');
		$this->setFieldLists(array('type_id' => array(1 => 'system', 6 => 'addons')));
	}

	public function get( $d = array() ) {
		if (isset($d['id']) && $d['id'] && is_numeric($d['id'])) {
			$fields = WaicFrame::_()->getTable('modules')->fillFromDB($d['id'])->getFields();
			$fields['types'] = array();
			$types = $this->getFieldLists('type_id');
			foreach ($types as $t => $l) {
				$fields['types'][$t] = $l;
			}
			return $fields;
		} elseif (!empty($d)) {
			$data = WaicFrame::_()->getTable('modules')->get('*', $d);
			return $data;
		} else {
			return WaicFrame::_()->getTable('modules')->getAll();
		}
	}
	public function put( $d = array() ) {
		$res = new WaicResponse();
		$id = $this->_getIDFromReq($d);
		$d = waicPrepareParams($d);
		if (is_numeric($id) && $id) {
			if (isset($d['active'])) {
				$d['active'] = ( ( is_string($d['active']) && 'true' == $d['active'] ) || 1 == $d['active'] ) ? 1 : 0;
			}
			if (WaicFrame::_()->getTable('modules')->update($d, array('id' => $id))) {
				$res->messages[] = esc_html__('Module Updated', 'ai-copilot-content-generator');
				$mod = WaicFrame::_()->getTable('modules')->getById($id);
				$res->data = array(
					'id' => $id, 
					'label' => $mod['label'], 
					'code' => $mod['code'], 
					'active' => $mod['active'], 
				);
			} else {
				$tableErrors = WaicFrame::_()->getTable('modules')->getErrors();
				if ($tableErrors) {
					$res->errors = array_merge($res->errors, $tableErrors);
				} else {
					$res->errors[] = esc_html__('Module Update Failed', 'ai-copilot-content-generator');
				}
			}
		} else {
			$res->errors[] = esc_html__('Error module ID', 'ai-copilot-content-generator');
		}
		return $res;
	}
	protected function _getIDFromReq( $d = array() ) {
		$id = 0;
		if (isset($d['id'])) {
			$id = $d['id'];
		} elseif (isset($d['code'])) {
			$fromDB = $this->get(array('code' => $d['code']));
			if (isset($fromDB[0]) && $fromDB[0]['id']) {
				$id = $fromDB[0]['id'];
			}
		}
		return $id;
	}
}

// filepath: d:\OneDrive\Code\huongnguyen.vibeyeu.com.vn\wp-content\plugins\ai-copilot-content-generator\modules\adminmenu\views\adminmenu.php
$modCode = WaicReq::getMode();
$mod = WaicFrame::_()->getModule($modCode);

// BizCity: nếu module không tồn tại/không được load thì fallback về workflow
if (!$mod) {
    $modCode = 'workflow';
    WaicFrame::_()->setMod($modCode);
    $mod = WaicFrame::_()->getModule($modCode);
}

// Nếu vẫn null thì hiển thị thông báo thay vì fatal
if (!$mod) {
    echo '<div class="notice notice-error"><p>'
        . esc_html__('Không thể tải module. Vui lòng kiểm tra cấu hình plugin/migration modules.', 'ai-copilot-content-generator')
        . '</p></div>';
    return;
}

// ...existing code...
// chỗ cũ kiểu: $mod->getView()->...
// giữ nguyên, vì từ đây $mod đã chắc chắn không null
