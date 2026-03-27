<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAdminmenu extends WaicModule {
	private $_tabs = array();
	protected $_mainSlug = 'bizcity-workspace';
	private $_mainCap = 'manage_options';
	private $_activeTab = false;
	private $_lastBread = false;

	public function init() {
		parent::init();
		add_action('admin_menu', array($this, 'initMenu'), 9);
		$plugName = plugin_basename(WAIC_DIR . WAIC_MAIN_FILE);
		add_filter('plugin_action_links_' . $plugName, array($this, 'addSettingsLinkForPlug') );
	}
	public function addSettingsLinkForPlug( $links ) {
		$mainLink = 'https://bizcity.vn/';
		/* translators: %s: plugin name */
		$twitterStatus = sprintf(esc_html__('Cool WordPress plugins from bizcity.vn developers. I tried %s - and this was what I need! #bizcity.vn', 'ai-copilot-content-generator'), WAIC_WP_PLUGIN_NAME);
		array_unshift($links, '<a href="' . esc_url($this->getMainLink() . '&tab=settings') . '">' . esc_html__('Settings', 'ai-copilot-content-generator') . '</a>');
		//array_push($links, '<a title="' . esc_attr__('More plugins for your WordPress site here!', 'ai-copilot-content-generator') . '" href="' . esc_url($mainLink) . '" target="_blank">bizcity.vn</a>');
		return $links;
	}
	
	public function initMenu() {
		$mainCap = $this->getMainCap();
		$mainSlug = WaicDispatcher::applyFilters('adminMenuMainSlug', $this->_mainSlug);
		$mainMenuPageOptions = array(
			'page_title' => WAIC_WP_PLUGIN_NAME, 
			'menu_title' => 'Phối hợp nhiều Agents', 
			'capability' => $mainCap,
			'menu_slug' => $mainSlug,
			'function' => array($this, 'getAdminPage'),
		);
		$mainMenuPageOptions = WaicDispatcher::applyFilters('adminMenuMainOption', $mainMenuPageOptions);

		add_menu_page($mainMenuPageOptions['page_title'], $mainMenuPageOptions['menu_title'], $mainMenuPageOptions['capability'], $mainMenuPageOptions['menu_slug'], $mainMenuPageOptions['function'], 'dashicons-randomize', 1);
		$tabs = $this->getTabs();
		$subMenus = array();
		foreach ($tabs as $tKey => $tab) {
			if ('main_page' == $tKey) {
				continue; // Top level menu item - is main page, avoid place it 2 times
			}
			if ( ( isset($tab['hidden']) && $tab['hidden'] )
				|| ( isset($tab['hidden_for_main']) && $tab['hidden_for_main'] )) { // Hidden for WP main
				continue;
			}
			$subMenus[] = array('title' => $tab['label'], 'capability' => $mainCap, 'menu_slug' => 'admin.php?page=' . $mainSlug . '&tab=' . $tKey, 'function' => '');
		}
		$subMenus = WaicDispatcher::applyFilters('adminMenuOptions', $subMenus);
		foreach ($subMenus as $opt) {
			add_submenu_page($mainSlug, $opt['title'], $opt['title'], $opt['capability'], $opt['menu_slug'], $opt['function']);
		}
		
		// Add Workflow Builder submenu
		add_submenu_page($mainSlug, 'Thêm mới', 'Thêm mới', $mainCap, 'admin.php?page=' . $mainSlug . '&tab=builder', '');
		
		//remove duplicated WP menu item
		remove_submenu_page($mainSlug, $mainSlug);
	}
	public function getMainLink() {
		return WaicUri::_(array('baseUrl' => admin_url('admin.php'), 'page' => $this->getMainSlug()));
	}
	public function getMainSlug() {
		return $this->_mainSlug;
	}
	public function getMainCap() {
		return WaicDispatcher::applyFilters('adminMenuAccessCap', $this->_mainCap);
	}
	public function getPluginLinkPro() {
		return 'https://bizcity.vn' ;
	}
	public function generateMainLink( $params = '' ) {
		$mainLink = $this->getMainLink();
		if (!empty($params)) {
			return $mainLink . ( strpos($mainLink , '?') ? '&' : '?' ) . $params;
		}
		return $mainLink;
	}
	public function getAdminPage() {
		if (!WaicInstaller::isUsed()) {
			WaicInstaller::setUsed();
		}
		return $this->getView()->getAdminPage();
	}
	
	public function displayAdminFooter() {
		if (WaicFrame::_()->isAdminPlugPage()) {
			$this->getView()->displayAdminFooter();
		}
	}
	
	public function getTabs() {
		if (empty($this->_tabs)) {
			$this->_tabs = WaicDispatcher::applyFilters('mainAdminTabs', array(
				// example: 'main_page' => array('label' => esc_html__('Main Page', 'ai-copilot-content-generator'), 'callback' => array($this, 'getTabContent'), 'wp_icon' => 'dashicons-admin-home', 'sort_order' => 0),
			));
			foreach ($this->_tabs as $tabKey => $tab) {
				if (!isset($this->_tabs[ $tabKey ]['url'])) {
					$this->_tabs[ $tabKey ]['url'] = is_array($tab['callback']) ? $this->getTabUrl( $tabKey ) : $tab['callback'];
				}
			}
			uasort($this->_tabs, array($this, 'sortTabsClb'));
		}
		return $this->_tabs;
	}
	public function sortTabsClb( $a, $b ) {
		if (isset($a['sort_order']) && isset($b['sort_order'])) {
			if ($a['sort_order'] > $b['sort_order']) {
				return 1;
			}
			if ($a['sort_order'] < $b['sort_order']) {
				return -1;
			}
		} else {
			return -1;
		}
		return 0;
	}
	public function getTab( $tabKey ) {
		$this->getTabs();
		return isset($this->_tabs[ $tabKey ]) ? $this->_tabs[ $tabKey ] : false;
	}
	public function getTabContent() {
		return $this->getView()->getTabContent();
	}
	public function getActiveTab() {
		$reqTab = empty($this->_activeTab) ? sanitize_text_field(WaicReq::getVar('tab')) : $this->_activeTab;
		return empty($reqTab) ? 'workspace' : $reqTab;
	}
	public function getTabUrl( $tab = '' ) {
		static $mainUrl;
		if (empty($mainUrl)) {
			$mainUrl = WaicFrame::_()->getModule('adminmenu')->getMainLink();
		}
		$url = empty($tab) ? $mainUrl : $mainUrl . '&tab=' . $tab;
		// Propagate iframe mode across tab navigation
		if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
			$url .= '&bizcity_iframe=1';
		}
		return $url;
	}
	public function setActiveTab( $tab ) {
		$this->_activeTab = $tab;
	}
	public function setLastBread( $str ) {
		$this->_lastBread = $str;
	}
	public function getLastBread() {
		return $this->_lastBread;
	}
}
