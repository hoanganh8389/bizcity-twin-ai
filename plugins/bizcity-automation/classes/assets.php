<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAssets {
	protected $_styles = array();
	private $_cdnUrl = '';

	public function init() {
		$this->getCdnUrl();
		if (is_admin()) {
			$isAdminPlugOptsPage = WaicFrame::_()->isAdminPlugOptsPage();
			if ($isAdminPlugOptsPage) {
				$this->loadAdminCoreJs();
				$this->loadCoreCss();
				$this->loadBootstrap();
				$this->loadFontAwesome();
				$this->loadJqueryUi();
				WaicFrame::_()->addScript('waic-admin-options', WAIC_JS_PATH . 'admin.options.js', array(), false, true);
				add_action('admin_enqueue_scripts', array($this, 'loadMediaScripts'));
				add_action('init', array($this, 'connectAdditionalAdminAssets'));
			}
			// Some common styles - that need to be on all admin pages - be careful with them
			//WaicFrame::_()->addStyle('woobewoo-for-all-admin-' . WAIC_CODE, WAIC_CSS_PATH . 'woobewoo-for-all-admin.css');
		}
	}
	public static function getInstance() {
		static $instance;
		if (!$instance) {
			$instance = new WaicAssets();
		}
		return $instance;
	}
	public static function _() {
		return self::getInstance();
	}
	public function getCdnUrl() {
		if (empty($this->_cdnUrl)) {
			if ((int) WaicFrame::_()->getModule('options')->get('use_local_cdn')) {
				$uploadsDir = wp_upload_dir( null, false );
				$this->_cdnUrl = $uploadsDir['baseurl'] . '/' . WAIC_CODE . '/';
				if (WaicUri::isHttps()) {
					$this->_cdnUrl = str_replace('http://', 'https://', $this->_cdnUrl);
				}
			} else {
				$this->_cdnUrl = ( WaicUri::isHttps() ? 'https' : 'http' ) . '://woobewoo-14700.kxcdn.com/';
			}
		}
		return $this->_cdnUrl;
	}

	public function connectAdditionalAdminAssets() {
		if (is_rtl()) {
			WaicFrame::_()->addStyle('waic-style-rtl', WAIC_CSS_PATH . 'style-rtl.css');
		}
	}
	public function loadMediaScripts() {
		if (function_exists('wp_enqueue_media')) {
			wp_enqueue_media();
		}
	}
	public function loadAdminCoreJs() {
		WaicFrame::_()->addScript('jquery-ui-dialog');
		//WaicFrame::_()->addScript('jquery-ui-slider');
	}
	public function loadSlider( $nonce = true ) {
		$path = WAIC_LIB_PATH . 'slider/';
		WaicFrame::_()->addScript('wbw-slider', $path . 'ion.rangeSlider.min.js', array('jquery'));
		WaicFrame::_()->addStyle('wbw-slider', $path . 'ion.rangeSlider.css');
	}
	public function loadCoreJs( $nonce = true, $front = false, $promo = false ) {
		static $loaded = false;
		if (!$loaded) {
			WaicFrame::_()->addScript('jquery');
			WaicFrame::_()->addScript('waic-core', WAIC_JS_PATH . 'core.js');
			if (!$front && !$promo) {
				WaicFrame::_()->addScript('waic-notify-js', WAIC_JS_PATH . 'notify.js', array(), false, true);
			}
			$ajaxurl = admin_url('admin-ajax.php');
			
			$jsData = array(
				'siteUrl' => WAIC_SITE_URL,
				'imgPath' => WAIC_IMG_PATH,
				'plugName' => WAIC_PLUG_NAME . '/' . WAIC_MAIN_FILE,
				'ajaxurl' => $ajaxurl,
				'WAIC_CODE' => WAIC_CODE,
				'isPro' => WaicFrame::_()->isPro(),
				'dateFormat' => WaicUtils::getJSDateFormat(),
				'timeFormat' => WaicUtils::getJSTimeFormat(),
			);
			if ($promo && function_exists('getProPlugDirWaic')) {
				$jsData['plugNamePro'] = getProPlugDirWaic() . '/' . getProPlugFileWaic();
			}
			
			if ($nonce) {
				$jsData['waicNonce'] = wp_create_nonce('waic-nonce');
				$jsData['wpRestNonce'] = wp_create_nonce('wp_rest');
			}
			$jsData = WaicDispatcher::applyFilters('jsInitVariables', $jsData);
			WaicFrame::_()->addJSVar('waic-core', 'WAIC_DATA', $jsData);
			if (!$front && !$promo) {
				$this->loadTooltipster();
			}
			$loaded = true;
		}
	}
	public function loadTooltipster() {
		$path = WAIC_LIB_PATH . 'tooltipster/';
		WaicFrame::_()->addScript('tooltipster', $path . 'jquery.tooltipster.min.js');
		WaicFrame::_()->addStyle('tooltipster', $path . 'tooltipster.css');
	}
	public function loadLoaders() {
		WaicFrame::_()->addStyle('waic-loaders', WAIC_CSS_PATH . 'loaders.css');
	}
	public function loadCoreCss() {
		$this->_styles = array(
			//'waic-style' => array('path' => WAIC_CSS_PATH . 'style.css', 'for' => 'admin'),
			'waic-woobewoo-ui' => array('path' => WAIC_CSS_PATH . 'wbw-ui.css', 'for' => 'admin'),
			'dashicons' => array('for' => 'admin'),
			'bootstrap-alerts' => array('path' => WAIC_CSS_PATH . 'bootstrap-alerts.css', 'for' => 'admin'),
		);
		foreach ($this->_styles as $s => $sInfo) {
			if (!empty($sInfo['path'])) {
				WaicFrame::_()->addStyle($s, $sInfo['path']);
			} else {
				WaicFrame::_()->addStyle($s);
			}
		}
		$this->loadFontAwesome();
	}
	public function loadAdminEndCss() {
		WaicFrame::_()->addStyle('waic-admin-options', WAIC_CSS_PATH . 'admin.options.css');
	}
	public function loadColorPicker() {
		$path = WAIC_LIB_PATH . 'colorpicker/';
		WaicFrame::_()->addScript('waic-colorpicker', $path . 'colorpicker.js');
		WaicFrame::_()->addStyle('waic-colorpicker', $path . 'colorpicker.css');
	}
	public function loadJqueryUi() {
		static $loaded = false;
		if (!$loaded) {
			$this->loadDatePicker();
			WaicFrame::_()->addScript('jquery-ui');
			WaicFrame::_()->addStyle('jquery-ui', WAIC_CSS_PATH . 'jquery-ui.min.css');
			$loaded = true;
		}
	}
	public function loadDataTables( $extensions = array(), $jqueryui = false ) {
		$frame = WaicFrame::_();
		$path = WAIC_LIB_PATH . 'datatables/';
		$frame->addScript('waic-dt-js', $path . 'js/jquery.dataTables.min.js');
		$frame->addStyle('waic-dt-css', $path . 'css/jquery.dataTables.min.css');

		foreach ($extensions as $ext) {
			$frame->addScript('waic-dt-' . $ext, $path . 'js/dataTables.' . $ext . '.min.js');
			$frame->addStyle('waic-dt-' . $ext, $path . 'css/' . $ext . '.dataTables.min.css');
		}
	}
	public function loadFontAwesome() {
		WaicFrame::_()->addStyle('waic-font-awesome', WAIC_CSS_PATH . 'font-awesome.min.css');
	}
	public function loadChosenSelects() {
		$path = WAIC_LIB_PATH . 'multiselect/';
		WaicFrame::_()->addStyle('waic-jquery-multiselect', $path . 'multiselect.min.css');
		WaicFrame::_()->addScript('waic-jquery-multiselect', $path . 'multiselect.jquery.js');
	}
	public function loadDateTimePicker() {
		$path = WAIC_LIB_PATH . 'datetimepicker/';
		WaicFrame::_()->addScript('jquery-ui-datepicker');
		WaicFrame::_()->addStyle('waic-jquery-datetime', $path . 'jquery-ui-timepicker-addon.css');
		WaicFrame::_()->addScript('waic-jquery-datetime', $path . 'jquery-ui-timepicker-addon.js');
	}
	public function loadDatePicker() {
		WaicFrame::_()->addScript('jquery-ui-datepicker');
	}
	public function loadSortable() {
		static $loaded = false;
		if (!$loaded) {
			WaicFrame::_()->addScript('jquery-ui-core');
			WaicFrame::_()->addScript('jquery-ui-widget');
			WaicFrame::_()->addScript('jquery-ui-mouse');

			WaicFrame::_()->addScript('jquery-ui-draggable');
			WaicFrame::_()->addScript('jquery-ui-sortable');
			$loaded = true;
		}
	}
	public function loadBootstrap() {
		static $loaded = false;
		if (!$loaded) {
			WaicFrame::_()->addStyle('bootstrap.min', WAIC_CSS_PATH . 'bootstrap.min.css');
			$loaded = true;
		}
	}
	public function loadCodemirror() {
		$path = WAIC_LIB_PATH . 'codemirror/';
		WaicFrame::_()->addStyle('waic-codemirror', $path . 'codemirror.css');
		WaicFrame::_()->addStyle('waic-codemirror-addon-hint', $path . 'addon/hint/show-hint.css');
		WaicFrame::_()->addScript('waic-codemirror', $path . 'codemirror.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-addon-show-hint', $modPath . 'lib/codemirror/addon/hint/show-hint.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-addon-xml-hint', $modPath . 'lib/codemirror/addon/hint/xml-hint.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-addon-html-hint', $modPath . 'lib/codemirror/addon/hint/html-hint.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-mode-xml', $modPath . 'lib/codemirror/mode/xml/xml.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-mode-javascript', $modPath . 'lib/codemirror/mode/javascript/javascript.js');
		WaicFrame::_()->addScript('waic-codemirror-mode-css', $path . 'mode/css/css.js');
		//WaicFrame::_()->addScript('wtbp-codemirror-mode-htmlmixed', $modPath . 'lib/codemirror/mode/htmlmixed/htmlmixed.js');
	}
}
