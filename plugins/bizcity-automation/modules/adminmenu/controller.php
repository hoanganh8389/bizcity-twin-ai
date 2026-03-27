<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAdminmenuController extends WaicController {
	public function addNoticeAction() {
		$res = new WaicResponse();
		$code = WaicReq::getVar('code', 'post');
		$choice = WaicReq::getVar('choice', 'post');
		if (!empty($code) && !empty($choice)) {
			$optModel = WaicFrame::_()->getModule('options')->getModel();
			switch ($choice) {
				case 'hide':
					$optModel->save('hide_' . $code, 1);
					break;
				case 'later':
					$optModel->save('later_' . $code, time());
					break;
				case 'done':
					$optModel->save('done_' . $code, 1);
					break;
			}
			$this->getModel()->checkAndSend( true );
		}
		$res->ajaxExec();
	}
}
