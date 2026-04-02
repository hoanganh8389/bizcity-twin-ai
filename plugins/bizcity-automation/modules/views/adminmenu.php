<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAdminmenuView extends WaicView {
	public function getAdminPage() {
		$accets = WaicAssets::_();
		$accets->loadCoreJs();
		$accets->loadAdminEndCss();
		
		$tabs = $this->getModule()->getTabs();
		$activeTab = $this->getModule()->getActiveTab();
		
		// BizCity: debug - nếu không có tab nào, hiển thị thông báo rõ ràng
		if (empty($tabs)) {
			$content = __('Không có tab nào được đăng ký. Vui lòng kiểm tra cấu hình modules.', 'ai-copilot-content-generator');
		} else {
			$content = __('Nội dung đang tải...', 'ai-copilot-content-generator');
			$tabData = isset($tabs[$activeTab]) ? $tabs[$activeTab] : array();
			if (isset($tabData['callback'])) {
				$content = call_user_func($tabData['callback']);
			} else {
				// Tab không tồn tại, hiển thị thông báo với danh sách tabs có sẵn
				$availableTabs = array_keys($tabs);
				$content = sprintf(
					__('Tab "%s" không tồn tại. Các tab khả dụng: %s', 'ai-copilot-content-generator'),
					$activeTab,
					implode(', ', $availableTabs)
				);
			}
		} 
		// if updated 
		$activeTab = $this->getModule()->getActiveTab();
		
		$this->assign('tabs', $tabs);
		$this->assign('activeTab', $activeTab);
		$this->assign('bread', empty($tabData['bread']) ? false : $tabData['bread']);
		$this->assign('lastBread', $this->getModule()->getLastBread());
		$this->assign('lastBreadId', empty($tabData['last_Id']) ? false : $tabData['last_Id']);
		$this->assign('content', $content);

		// BizCity: promo module may be disabled/removed -> avoid fatal
		$guide = '';
		$promo = WaicFrame::_()->getModule('promo');
		if ($promo && method_exists($promo, 'getView')) {
			$promoView = $promo->getView();
			if ($promoView && method_exists($promoView, 'printGuidePopup')) {
				$guide = $promoView->printGuidePopup(false, $activeTab);
			}
		}
		$this->assign('guide', $guide);

		$this->assign('mainUrl', $this->getModule()->getTabUrl());
		$this->assign('is_pro', WaicFrame::_()->isPro(false));

		parent::display('adminNavPage');
	}

	public function displayAdminFooter() {
		parent::display('adminFooter');
	}
}
