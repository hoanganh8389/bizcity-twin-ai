<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
abstract class WaicView extends WaicBaseObject {
	/*
	 * @deprecated
	 */
	protected $_tpl = WAIC_DEFAULT;
	/*
	 * @var string name of theme to load from templates, if empty - default values will be used
	 */
	protected $_theme = '';
	/*
	 * @var string module code for this view
	 */
	protected $_code = '';
	public $props = array();

	public function display( $tpl = '' ) {
		$tpl = ( empty($tpl) ) ? $this->_tpl : $tpl;
		$content = $this->getContent($tpl);
		if (false !== $content) {
			WaicHtml::echoEscapedHtml($content);
		}
	}
	public function getPath( $tpl ) {
		$path = '';
		$parentModule = WaicFrame::_()->getModule( $this->_code );
		if (file_exists($parentModule->getModDir() . 'views' . WAIC_DS . 'tpl' . WAIC_DS . $tpl . '.php')) { //Then try to find it in module directory
			$path = $parentModule->getModDir() . WAIC_DS . 'views' . WAIC_DS . 'tpl' . WAIC_DS . $tpl . '.php';
		}
		return $path;
	}
	public function getModule() {
		return WaicFrame::_()->getModule( $this->_code );
	}
	public function getModel( $code = '' ) {
		return WaicFrame::_()->getModule( $this->_code )->getController()->getModel($code);
	}
	public function getContent( $tpl = '' ) {
		$tpl = ( empty($tpl) ) ? $this->_tpl : $tpl;
		$path = $this->getPath($tpl);
		if ($path) {
			$content = '';
			ob_start();
			require $path;
			$content = ob_get_contents();
			ob_end_clean();
			return $content;
		}
		return false;
	}
	public function setTheme( $theme ) {
		$this->_theme = $theme;
	}
	public function getTheme() {
		return $this->_theme;
	}
	public function setTpl( $tpl ) {
		$this->_tpl = $tpl;
	}
	public function getTpl() {
		return $this->_tpl;
	}
	public function init() {
	}
	public function assign( $name, $value ) {
		$this->props[$name] = $value;
	}
	public function setCode( $code ) {
		$this->_code = $code;
	}
	public function getCode() {
		return $this->_code;
	}

	/**
	 * This will display form for our widgets
	 */
	public function displayWidgetForm( $data = array(), $widget = array(), $formTpl = 'form' ) {
		$this->assign('data', $data);
		$this->assign('widget', $widget);
		if (WaicFrame::_()->isTplEditor()) {
			if ($this->getPath($formTpl . '_ext')) {
				$formTpl .= '_ext';
			}
		}
		self::display($formTpl);
	}
	public function sizeToPxPt( $size ) {
		if (!strpos($size, 'px') && !strpos($size, '%')) {
			$size .= 'px';
		}
		return $size;
	}
	public function getInlineContent( $tpl = '' ) {
		return preg_replace('/\s+/', ' ', $this->getContent($tpl));
	}
	
	public function includeTemplate( $tpl, $params ) {
		foreach ($params as $param => $data) {
			$this->assign($param, $data);
		}
		return self::display($tpl);
	}
	public function includeExtTemplate( $module, $tpl, $params = array() ) {
		if (!WaicFrame::_()->moduleActive($module)) {
			return false;
		}
		$path = '';
		$eModule = WaicFrame::_()->getModule($module);
		if (!$eModule) {
			return false;
		}
		if (file_exists($eModule->getModDir() . 'views' . WAIC_DS . 'tpl' . WAIC_DS . $tpl . '.php')) { 
			$path = $eModule->getModDir() . WAIC_DS . 'views' . WAIC_DS . 'tpl' . WAIC_DS . $tpl . '.php';
		}
		if (!$path || empty($path)) {
			return false;
		}
		//var_dump($params);
		/*foreach ($params as $param => $data) {
			$this->assign($param, $data);
		}*/
		$content = '';
		ob_start();
		require $path;
		$content = ob_get_contents();
		ob_end_clean();
		if (false !== $content) {
			WaicHtml::echoEscapedHtml($content);
		}
		return false;
	}
	public function getTaxonomyHierarchy( $parent = 0, $pre = '', $tax = 'product_cat' ) {
		$args    = array(
			'hide_empty' => true,
			'parent'     => $parent,
			'taxonomy' => $tax,
		);
		$terms = get_terms($args);
		$taxes = array();
		foreach ( $terms as $term ) {
			if ( ! empty( $term->term_id ) ) {
				//$taxes[$term->term_id] = $pre . esc_html( $term->name );
				$taxes[$term->term_id] = '[' . $term->term_id . '] ' . esc_html( $term->name );
				$children = $this->getTaxonomyHierarchy( $term->term_id, $pre . '&nbsp;&nbsp;&nbsp;', $tax );
				//var_dump($taxes);
				if (!empty($children)) {
					$taxes = $taxes + $children;
				}
			}
		}
		return $taxes;
	}
}
