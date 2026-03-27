<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicHtml {
	public static $fontsList = array();
	public static $categoriesOptions = array();
	public static $productsOptions = array();
	public static $colsType = 'standart';
	public static $colClasses = array(
		'standart' => array('label' => 'col-3 col-xl-2', 'values' => 'col-8 col-sm-9 col-xl-9', 'full' => 'col-12'),
		'compact' => array('label' => 'col-3 col-xl-3 col-sm-5', 'info' => 'col-xs-2 col-sm-1', 'values' => 'col-9 col-xl-9 col-sm-7', 'full' => 'col-12'),
		'popup' => array('label' => 'col-5 col-sm-3', 'info' => 'col-1', 'values' => 'col-7 col-sm-9', 'full' => 'col-12'),
		'super' => array('label' => 'col-3 col-sm-2', 'info' => 'col-1', 'values' => 'col-8 col-sm-9', 'full' => 'col-12'),
	);
	public static function setColType( $type ) {
		if (isset(self::$colClasses[$type])) {
			self::$colsType = $type;
		}
	}
	public static function blockClasses( $type ) {
		return 'options-' . $type . ' ' . WaicUtils::getArrayValue(self::$colClasses[self::$colsType], $type);
	}
	public static function echoEscapedHtml( $html ) {
		add_filter('esc_html', array('WaicHtml', 'skipHtmlEscape'), 99, 2);
		echo esc_html($html);
		remove_filter('esc_html', array('WaicHtml', 'skipHtmlEscape'), 99, 2);
	}
	public static function skipHtmlEscape( $safe_text, $text ) {
		return $text;
	}
	public static function block( $name, $params = array('attrs' => '', 'value' => '') ) {
		$output .= '<p class="toe_' . self::nameToClassId($name) . '">' . $params['value'] . '</p>';
		return $output;
	}
	public static function nameToClassId( $name, $params = array() ) {
		if (!empty($params) && isset($params['attrs']) && strpos($params['attrs'], 'id="') !== false) {
			preg_match('/id="(.+)"/ui', $params['attrs'], $idMatches);
			if ($idMatches[1]) {
				return $idMatches[1];
			}
		}
		return str_replace(array('[', ']'), '', $name);
	}
	public static function textarea( $name, $params = array('attrs' => '', 'value' => '', 'rows' => 8, 'cols' => 50) ) {
		$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
		$params['rows'] = isset($params['rows']) ? $params['rows'] : 8;
		$params['cols'] = isset($params['cols']) ? $params['cols'] : 50;
		if (isset($params['required']) && $params['required']) {
			$params['attrs'] .= ' required '; // HTML5 "required" validation attr
		}
		if (isset($params['placeholder']) && $params['placeholder']) {
			$params['attrs'] .= ' placeholder="' . esc_attr($params['placeholder']) . '"'; // HTML5 "required" validation attr
		}
		if (isset($params['disabled']) && $params['disabled']) {
			$params['attrs'] .= ' disabled ';
		}
		if (isset($params['readonly']) && $params['readonly']) {
			$params['attrs'] .= ' readonly ';
		}
		if (isset($params['auto_width']) && $params['auto_width']) {
			unset($params['rows']);
			unset($params['cols']);
		}
		echo '<textarea' . ( empty($name) ? '' : ' name="' . esc_attr($name) . '"' ) . ' ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo ( isset($params['rows']) ? ' rows="' . esc_attr($params['rows']) . '"' : '' ) .
			( isset($params['cols']) ? ' cols="' . esc_attr($params['cols']) . '"' : '' ) . '>' .
			( isset($params['value']) ? esc_html($params['value']) : '' ) .
		'</textarea>';
	}
	public static function input( $name, $params = array('attrs' => '', 'type' => 'text', 'value' => '') ) {
		$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
		$params['attrs'] .= self::_dataToAttrs($params);
		if (isset($params['required']) && $params['required']) {
			$params['attrs'] .= ' required '; // HTML5 "required" validation attr
		}
		if (isset($params['placeholder']) && $params['placeholder']) {
			$params['attrs'] .= ' placeholder="' . esc_attr($params['placeholder']) . '"'; // HTML5 "required" validation attr
		}
		if (isset($params['disabled']) && $params['disabled']) {
			$params['attrs'] .= ' disabled ';
		}
		if (isset($params['readonly']) && $params['readonly']) {
			$params['attrs'] .= ' readonly ';
		}
		$params['type'] = isset($params['type']) ? $params['type'] : 'text';
		$params['value'] = isset($params['value']) ? $params['value'] : '';

		echo '<input type="' . esc_attr($params['type']) . '"' . ( empty($name) ? '' : ' name="' . esc_attr($name) . '"' ) . ' value="' . esc_attr($params['value']) . '" ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo ' />';
	}
	public static function inputShortcode( $name, $params = array() ) {
		$value = $params['value'];
		self::input('', array('value' => $value, 'attrs' => 'readonly class="wbw-flat-input wbw-nosave wbw-shortcode wbw-width' . ( strlen($value) <= 20 ? 200 : 300 ) . '"'));
	}
	private static function _dataToAttrs( $params ) {
		$res = '';
		foreach ($params as $k => $v) {
			if (strpos($k, 'data-') === 0) {
				$res .= ' ' . $k . '="' . $v . '"';
			}
		}
		return $res;
	}
	public static function text( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'text';
		self::input($name, $params);
	}
	public static function email( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'email';
		self::input($name, $params);
	}
	public static function reset( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'reset';
		self::input($name, $params);
	}
	public static function password( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'password';
		self::input($name, $params);
	}
	public static function hidden( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'hidden';
		self::input($name, $params);
	}
	public static function number( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'number';
		if (!isset($params['attrs'])) {
			$params['attrs'] = '';
		}
		if (isset($params['min'])) {
			$params['attrs'] .= ' min="' . $params['min'] . '"';
		}
		if (isset($params['max'])) {
			$params['attrs'] .= ' max="' . $params['max'] . '"';
		}
		self::input($name, $params);
	}
	public static function checkbox( $name, $params = array('attrs' => '', 'value' => '', 'checked' => '') ) {
		$params['type'] = 'checkbox';
		$params['checked'] = isset($params['checked']) && $params['checked'] ? ' checked' : '';
		if ( !isset($params['value']) || null == $params['value'] ) {
			$params['value'] = 1;
		}
		if (!isset($params['attrs'])) {
			$params['attrs'] = '';
		}
		$params['attrs'] .= $params['checked'];
		self::input($name, $params);
	}
	public static function checkboxToggle( $name, $params = array('attrs' => '', 'value' => '', 'checked' => '') ) {
		$params['type'] = 'checkbox';
		$params['checked'] = isset($params['checked']) && $params['checked'] ? 'checked' : '';
		if ( !isset($params['value']) || ( null === $params['value'] ) ) {
			$params['value'] = 1;
		}
		$id = ( empty($params['id']) ? self::nameToClassId($name) . mt_rand(9, 9999) : $params['id'] );
		$params['attrs'] = 'id="' . esc_attr($id) . '" class="toggle" ' . ( isset($params['attrs']) ? $params['attrs'] . ' ' : '' ) . $params['checked'];
		
		self::input($name, $params);
		echo '<label for="' . esc_attr($id) . '" class="toggle"></label>';
	}
	public static function checkboxlist( $name, $params = array('options' => array(), 'attrs' => '', 'checked' => '', 'delim' => '<br />', 'usetable' => 5), $delim = '<br />' ) {
		if (!strpos($name, '[]')) {
			$name .= '[]';
		}
		$i = 0;
		if ($params['options']) {
			if (!isset($params['delim'])) {
				$params['delim'] = $delim;
			}
			if (!empty($params['usetable'])) {
				echo '<table><tr>';
			}
			foreach ($params['options'] as $v) {
				if (!empty($params['usetable'])) {
					if ( ( 0 != $i ) && ( 0 == $i%$params['usetable'] ) ) {
						echo '</tr><tr>';
					}
					echo '<td>';
				}
				self::checkboxToggle($name, array(
					'attrs' => !empty($params['attrs']),
					'value' => empty($v['value']) ? $v['id'] : $v['value'],
					'checked' => $v['checked'],
					'id' => $v['id'],
				));
				echo '&nbsp;';
				if (!empty($v['text'])) {
					self::echoEscapedHtml($v['text']);
				}
				if (!empty($params['delim'])) {
					self::echoEscapedHtml($params['delim']);
				}
				if (!empty($params['usetable'])) {
					echo '</td>';
				}
				$i++;
			}
			if (!empty($params['usetable'])) {
				echo '</tr></table>';
			}
		}
	}
	public static function submit( $name, $params = array('attrs' => '', 'value' => '') ) {
		$params['type'] = 'submit';
		self::input($name, $params);
	}
	public static function img( $src, $usePlugPath = 1, $params = array('width' => '', 'height' => '', 'attrs' => '') ) {
		if ($usePlugPath) {
			$src = WAIC_IMG_PATH . $src;
		}
		echo '<img src="' . esc_url($src) . '" '
				. ( isset($params['width']) ? 'width="' . esc_attr($params['width']) . '"' : '' )
				. ' '
				. ( isset($params['height']) ? 'height="' . esc_attr($params['height']) . '"' : '' )
				. ' ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo ' />';
	}
	public static function selectbox( $name, $params = array('attrs' => '', 'options' => array(), 'value' => '') ) {
		$attrs = WaicUtils::getArrayValue($params, 'attrs');
		if (WaicUtils::getArrayValue($params, 'required', false)) {
			$attrs .= ' required ';
		}
		echo '<select' . ( empty($name) ? '' : ' name="' . esc_attr($name) . '"' ) . ' ';
		if (!empty($attrs)) {
			self::echoEscapedHtml($attrs);
		}
		echo '>';
		$default = WaicUtils::getArrayValue($params, 'default');
		if (!empty($default)) {
			echo '<option value="">' . esc_html($default) . '</option>';
		}
		$existValue = isset($params['value']);
		$keyValue = WaicUtils::getArrayValue($params, 'key') == 'value';
		$add = isset($params['add']) ? $params['add'] : '';
		if (!empty($params['options'])) {
			foreach ($params['options'] as $k => $v) {
				$key = ( $keyValue ? $v : $k ) . $add;
				$a = '';
				if (is_array($v)) {
					$a = isset($v['attrs']) ? $v['attrs'] : '';
					$v = isset($v['label']) ? $v['label'] : '???';
				}
				echo '<option value="' . esc_attr($key) . '"' . ( $existValue && $key == $params['value'] ? ' selected="true"' : '' );
				if (!empty($a)) {
					self::echoEscapedHtml($a);
				}
				echo '>' . esc_html($v) . '</option>';
			}
		}
		echo '</select>';
	}
	public static function selectlist( $name, $params = array('attrs' => '', 'size' => 5, 'class' => '', 'options' => array(), 'value' => '') ) {
		if (!strpos($name, '[]')) {
			$name .= '[]';
		}
		if ( !isset($params['size']) || !is_numeric($params['size']) || ( '' == $params['size'] ) ) {
			$params['size'] = 5;
		}
		$params['class'] = isset($params['class']) ? $params['class'] : '';
		$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
		$params['attrs'] .= self::_dataToAttrs($params);

		echo '<select multiple="multiple" class="wbw-chosen ' . esc_attr($params['class']) . '" size="' . esc_attr($params['size']) . '"' . ( empty($name) ? '' : ' name="' . esc_attr($name) . '"' ) . ' ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '>';

		$params['value'] = isset($params['value']) ? (array) $params['value'] : array();
		$keyValue = WaicUtils::getArrayValue($params, 'key') == 'value';
		$options = $params['options'];
		if (!empty($params['value'])) {
			foreach ($params['value'] as $v) {
				$k = ( $keyValue ? array_search($v, $options) : ( isset($options[$v]) ? $v : false ) );
				if (false !== $k) {
					echo '<option value="' . esc_attr($v) . '" selected>' . esc_html($options[$k]) . '</option>';
					unset($options[$k]);
				}
			}
		}
		if (!empty($options)) {
			foreach ($options as $k => $v) {
				$key = ( $keyValue ? $v : $k );
				echo '<option value="' . esc_attr($key) . '">' . esc_html($v) . '</option>';
			}
		}
		echo '</select>';
	}
	public static function file( $name, $params = array() ) {
		$id = ( empty($params['id']) ? self::nameToClassId($name) . mt_rand(9, 9999) : $params['id'] );
		echo '<div class="wbw-inputfile">
			<input type="file" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">
			<label for="' . esc_attr($id) . '">
				<div class="wbw-namefile"></div>
				<div class="button buttonfile">
					<i class="fa fa-upload" aria-hidden="true"></i>' . esc_html__('Select file', 'ai-copilot-content-generator') . '
				</div>
			</label>
		</div>';
	}
	public static function button( $params = array('attrs' => '', 'value' => '') ) {
		echo '<button ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '>' . esc_html($params['value']) . '</button>';
	}
	public static function buttonA( $params = array('attrs' => '', 'value' => '') ) {
		echo '<a href="#" ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '>' . esc_html($params['value']) . '</a>';
	}
	public static function inputButton( $params = array('attrs' => '', 'value' => '') ) {
		if (!is_array($params)) {
			$params = array();
		}
		$params['type'] = 'button';
		self::input('', $params);
	}
	public static function radiobuttons( $name, $params = array('attrs' => '', 'options' => array(), 'value' => '') ) {
		if (isset($params['options']) && is_array($params['options']) && !empty($params['options'])) {
			//$params['labeled'] = isset($params['labeled']) ? $params['labeled'] : false;
			$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
			$ul = !empty($params['ul']);
			$params['no_br'] = isset($params['no_br']) && !$ul ? $params['no_br'] : false;
			if (empty($params['value'])) {
				$params['value'] = 0;
			}
			if ($ul) {
				echo '<ul>';
			}
			foreach ($params['options'] as $key => $val) {
				$checked = ( $key == $params['value'] ) ? 'checked="checked"' : '';
				$id = $name . '_' . $key;
				if ($ul) {
					echo '<li>';
				}
				self::input($name, array('attrs' => $params['attrs'] . ' ' . $checked . ' id="' . esc_attr($id) . '"', 'type' => 'radio', 'value' => $key));
				echo '<label for="' . esc_attr($id) . '">' . esc_html($val) . '</label>';
				if ($ul) {
					echo '</li>';
				} else if (!$params['no_br']) {
					echo '<br />';
				}
			}
			if ($ul) {
				echo '</ul>';
			}
		}
	}
	public static function radiobutton( $name, $params = array('attrs' => '', 'value' => '', 'checked' => '') ) {
		$params['type'] = 'radio';
		$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
		if (isset($params['checked']) && $params['checked']) {
			$params['attrs'] .= ' checked';
		}
		self::input($name, $params);
	}
	public static function formStart( $name, $params = array('action' => '', 'method' => 'GET', 'attrs' => '', 'hideMethodInside' => false) ) {
		$params['attrs'] = isset($params['attrs']) ? $params['attrs'] : '';
		$params['action'] = isset($params['action']) ? $params['action'] : '';
		$params['method'] = isset($params['method']) ? $params['method'] : 'GET';
		echo '<form name="' . esc_attr($name) . '" action="' . esc_attr($params['action']) . '" method="' . esc_attr($params['method']) . '" ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '>';

		if (isset($params['hideMethodInside']) && $params['hideMethodInside']) {
			self::hidden('method', array('value' => $params['method']));
		}
	}
	public static function formEnd() {
		echo '</form>';
	}
	public static function colorPicker( $name, $params = array('value' => '', 'label' => '') ) {
		$value = isset($params['value']) ? $params['value'] : '';
		$label = isset($params['label']) ? $params['label'] : '';
		echo '<div class="wbw-color-picker">
			<div class="wbw-color-wrapper">
				<div class="wbw-color-preview"></div>
			</div>
			<input type="text" class="wbw-color-input" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '>';
		if (!empty($label)) {
			echo '<label class="right-label">' . esc_html($label) . '</label>';
		}
		echo '</div>';
	}
	public static function slider( $name, $params = array('value' => '', 'label' => '') ) {
		
		//https://github.com/IonDen/ion.rangeSlider?tab=readme-ov-file
		$skin = empty($params['skin']) ? 'round' : $params['skin'];
		echo '<div class="wbw-slider wbw-slider-' . esc_attr($skin) . '">' .
			' <input type="text" name="' . esc_attr($name) .
			( empty($params['class']) ? '' : '" class="' . esc_attr($params['class']) ) .
			( empty($params['id']) ? '' : '" id="' . esc_attr($params['id']) ) .
			'" value="' . esc_attr(empty($params['value']) ? '' : $params['value']) .
			'" data-hide-min-max="' . esc_attr(empty($params['hide-min-max']) ? '0' : $params['hide-min-max']) .  
			'" data-skin="' . esc_attr($skin) .
			'" data-type="' . esc_attr(empty($params['type']) ? 'single' : $params['type']) . 
			'" data-step="' . esc_attr(empty($params['step']) ? '1' : $params['step']) . 
			'" data-min="' . esc_attr(empty($params['min']) ? '0' : $params['min']) . 
			'" data-max="' . esc_attr(empty($params['max']) ? '100' : $params['max']) . 
			'">' .
			'</div>';
	}
	public static function nonceForAction( $action ) {
		self::hidden('_wpnonce', array('value' => wp_create_nonce(strtolower($action))));
	}
	public static function selectIcon( $name, $params ) {
		echo '<div class="button chooseLoaderIcon">' . esc_html__('Choose Icon', 'ai-copilot-content-generator') . '</div>';
	}
	public static function proOptionLink( $url = '', $label = '' ) {
		echo '<a href="' . esc_url( empty($url) ? WaicUri::generatePluginLink() : $url ) . '" target="_blank" class="wbw-prolink">' . ( empty($label) ? esc_html__('PRO', 'ai-copilot-content-generator') : esc_html($label) ) . '</a>';
	}
	public static function selectFontList( $name, $params = array('attrs' => '', 'value' => '') ) {
		$attrs = WaicUtils::getArrayValue($params, 'attrs');
		$value = WaicUtils::getArrayValue($params, 'value');

		echo '<select name="' . esc_attr($name) . '" ';
		if (!empty($params['attrs'])) {
			self::echoEscapedHtml($params['attrs']);
		}
		echo '><option value="">' . esc_html__('Default', 'ai-copilot-content-generator') . '</option>';

		$standart = WaicDispatcher::applyFilters('getFontsList', array(), 'standart');
		$fonts = array_merge($standart, WaicDispatcher::applyFilters('getFontsList', array(), ''));
		natsort($fonts);
		
		foreach ($fonts as $font) {
			echo '<option value="' . esc_attr($font) . '" data-standart="' . ( in_array($font, $standart) ? 1 : 0 ) . '" ' . ( $font == $value ? ' selected="true"' : '' ) . '>' . esc_html($font) . '</option>';
		}
		echo '</select>';
	}
	public static function fontStyleBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_font';
		$block = $preBlock . '[' . $name . ']';
		self::selectbox($block, array(
			'options' => self::getAllFontsList(),
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-small-field"',
		));
		$name = $preName . '_style';
		$block = $preBlock . '[' . $name . ']';
		self::selectbox($block, array(
			'options' => self::getFontStyles(),
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-mini"',
		));
		$name = $preName . '_color';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		$name = $preName . '_size';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
	}
	public static function sizeBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_width';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
		$name = $preName . '_height';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
	}
	public static function colorSizeBlock( $preBlock, $preName, $data, $withFilter = false ) {
		$name = $preName . '_color';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		if ($withFilter) {
			$name = $preName . '_color_filter';
			$block = $preBlock . '[' . $name . ']';
			self::hidden($block, array(
				'value' => WaicUtils::getArrayValue($data, $name),
				'attrs' => 'class="wbw-color-filter"',
			));
		}
		self::sizeBlock( $preBlock, $preName, $data );
	}
	public static function bgSizeBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_bg';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		self::sizeBlock( $preBlock, $preName, $data );
	}
	public static function bgPaddingsBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_bg';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		self::paddingsBlock( $preBlock, $preName, $data );
	}
	public static function bgHeightPadBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_bg';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		$name = $preName . '_height';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
		$name = $preName . '_left';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
		$name = $preName . '_right';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
	}
	public static function bordersBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_border_color';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		$name = $preName . '_border_style';
		$block = $preBlock . '[' . $name . ']';
		self::selectbox($block, array(
			'options' => self::getBorderStyles(),
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-mini"',
		));
		$name = $preName . '_border_size';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
	}
	public static function iconColorSizeBlock( $preBlock, $preName, $data, $icons, $check = false ) {
		if ($check) {
			$name = 'e_' . $preName;
			$block = $preBlock . '[' . $name . ']';
			self::checkbox($block, array(
				'checked' => WaicUtils::getArrayValue($data, $name, 0),
			));
		}
		$name = $preName . '_icon';
		$block = $preBlock . '[' . $name . ']';
		self::selectbox($block, array(
			'options' => $icons,
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-small-field"',
		));
		$name = $preName . '_color';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		$name = $preName . '_size';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
		));
	}
	public static function shadowBlock( $preBlock, $preName, $data ) {
		$name = $preName . '_shadow_color';
		$block = $preBlock . '[' . $name . ']';
		self::colorpicker($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
		));
		$name = $preName . '_shadow_alpha';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
		$name = $preName . '_shadow_x';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
		$name = $preName . '_shadow_y';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
		$name = $preName . '_shadow_blur';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
		$name = $preName . '_shadow_spread';
		$block = $preBlock . '[' . $name . ']';
		self::number($block, array(
			'value' => WaicUtils::getArrayValue($data, $name),
			'attrs' => 'class="wbw-field-micro"',
			'min' => 0,
		));
	}
	public static function cornerRadiusBlock( $preBlock, $preName, $data ) {
		$sides = array('t', 'r', 'b', 'l');
		foreach ($sides as $s) {
			$name = $preName . '_corner_' . $s;
			$block = $preBlock . '[' . $name . ']';
			self::number($block, array(
				'value' => WaicUtils::getArrayValue($data, $name),
				'attrs' => 'class="wbw-field-micro"',
				'min' => 0,
			));
		}
	}
	
	public static function paddingsBlock( $preBlock, $preName, $data, $typ = 'padding' ) {
		$sides = array('t', 'r', 'b', 'l');
		foreach ($sides as $s) {
			$name = $preName . '_' . $typ . '_' . $s;
			$block = $preBlock . '[' . $name . ']';
			self::number($block, array(
				'value' => WaicUtils::getArrayValue($data, $name),
				'attrs' => 'class="wbw-field-micro"',
				'min' => 0,
			));
		}
	}
	public static function getFontsList() {
		return array(
			'ABeeZee',
			'Abel',
			'Abril Fatface',
			'Aclonica',
			'Acme',
			'Actor',
			'Adamina',
			'Advent Pro',
			'Aguafina Script',
			'Akronim',
			'Aladin',
			'Aldrich',
			'Alef',
			'Alegreya',
			'Alegreya SC',
			'Alegreya Sans',
			'Alegreya Sans SC',
			'Alex Brush',
			'Alfa Slab One',
			'Alice',
			'Alike',
			'Alike Angular',
			'Allan',
			'Allerta',
			'Allerta Stencil',
			'Allura',
			'Almendra',
			'Almendra Display',
			'Almendra SC',
			'Amarante',
			'Amaranth',
			'Amatic SC',
			'Amethysta',
			'Amiri',
			'Anaheim',
			'Andada',
			'Andika',
			'Angkor',
			'Annie Use Your Telescope',
			'Anonymous Pro',
			'Antic',
			'Antic Didone',
			'Antic Slab',
			'Anton',
			'Arapey',
			'Arbutus',
			'Arbutus Slab',
			'Architects Daughter',
			'Archivo Black',
			'Archivo Narrow',
			'Arimo',
			'Arizonia',
			'Armata',
			'Artifika',
			'Arvo',
			'Asap',
			'Asset',
			'Astloch',
			'Asul',
			'Atomic Age',
			'Aubrey',
			'Audiowide',
			'Autour One',
			'Average',
			'Average Sans',
			'Averia Gruesa Libre',
			'Averia Libre',
			'Averia Sans Libre',
			'Averia Serif Libre',
			'Bad Script',
			'Balthazar',
			'Bangers',
			'Basic',
			'Battambang',
			'Baumans',
			'Bayon',
			'Belgrano',
			'Belleza',
			'BenchNine',
			'Bentham',
			'Berkshire Swash',
			'Bevan',
			'Bigelow Rules',
			'Bigshot One',
			'Bilbo',
			'Bilbo Swash Caps',
			'Biryani',
			'Bitter',
			'Black Ops One',
			'Bokor',
			'Bonbon',
			'Boogaloo',
			'Bowlby One',
			'Bowlby One SC',
			'Brawler',
			'Bree Serif',
			'Bubblegum Sans',
			'Bubbler One',
			'Buenard',
			'Butcherman',
			'Butterfly Kids',
			'Cabin',
			'Cabin Condensed',
			'Cabin Sketch',
			'Caesar Dressing',
			'Cagliostro',
			'Calligraffitti',
			'Cambay',
			'Cambo',
			'Candal',
			'Cantarell',
			'Cantata One',
			'Cantora One',
			'Capriola',
			'Cardo',
			'Carme',
			'Carrois Gothic',
			'Carrois Gothic SC',
			'Carter One',
			'Caudex',
			'Cedarville Cursive',
			'Ceviche One',
			'Changa One',
			'Chango',
			'Chau Philomene One',
			'Chela One',
			'Chelsea Market',
			'Chenla',
			'Cherry Cream Soda',
			'Cherry Swash',
			'Chewy',
			'Chicle',
			'Chivo',
			'Cinzel',
			'Cinzel Decorative',
			'Clicker Script',
			'Coda',
			'Codystar',
			'Combo',
			'Comfortaa',
			'Coming Soon',
			'Concert One',
			'Condiment',
			'Content',
			'Contrail One',
			'Convergence',
			'Cookie',
			'Copse',
			'Corben',
			'Courgette',
			'Cousine',
			'Coustard',
			'Covered By Your Grace',
			'Crafty Girls',
			'Creepster',
			'Crete Round',
			'Crimson Text',
			'Croissant One',
			'Crushed',
			'Cuprum',
			'Cutive',
			'Cutive Mono',
			'Damion',
			'Dancing Script',
			'Dangrek',
			'Dawning of a New Day',
			'Days One',
			'Dekko',
			'Delius',
			'Delius Swash Caps',
			'Delius Unicase',
			'Della Respira',
			'Denk One',
			'Devonshire',
			'Dhurjati',
			'Didact Gothic',
			'Diplomata',
			'Diplomata SC',
			'Domine',
			'Donegal One',
			'Doppio One',
			'Dorsa',
			'Dosis',
			'Dr Sugiyama',
			'Droid Sans',
			'Droid Sans Mono',
			'Droid Serif',
			'Duru Sans',
			'Dynalight',
			'EB Garamond',
			'Eagle Lake',
			'Eater',
			'Economica',
			'Ek Mukta',
			'Electrolize',
			'Elsie',
			'Elsie Swash Caps',
			'Emblema One',
			'Emilys Candy',
			'Engagement',
			'Englebert',
			'Enriqueta',
			'Erica One',
			'Esteban',
			'Euphoria Script',
			'Ewert',
			'Exo',
			'Exo 2',
			'Expletus Sans',
			'Fanwood Text',
			'Fascinate',
			'Fascinate Inline',
			'Faster One',
			'Fasthand',
			'Fauna One',
			'Federant',
			'Federo',
			'Felipa',
			'Fenix',
			'Finger Paint',
			'Fira Mono',
			'Fira Sans',
			'Fjalla One',
			'Fjord One',
			'Flamenco',
			'Flavors',
			'Fondamento',
			'Fontdiner Swanky',
			'Forum',
			'Francois One',
			'Freckle Face',
			'Fredericka the Great',
			'Fredoka One',
			'Freehand',
			'Fresca',
			'Frijole',
			'Fruktur',
			'Fugaz One',
			'GFS Didot',
			'GFS Neohellenic',
			'Gabriela',
			'Gafata',
			'Galdeano',
			'Galindo',
			'Gentium Basic',
			'Gentium Book Basic',
			'Geo',
			'Geostar',
			'Geostar Fill',
			'Germania One',
			'Gidugu',
			'Gilda Display',
			'Give You Glory',
			'Glass Antiqua',
			'Glegoo',
			'Gloria Hallelujah',
			'Goblin One',
			'Gochi Hand',
			'Gorditas',
			'Goudy Bookletter 1911',
			'Graduate',
			'Grand Hotel',
			'Gravitas One',
			'Great Vibes',
			'Griffy',
			'Gruppo',
			'Gudea',
			'Gurajada',
			'Habibi',
			'Halant',
			'Hammersmith One',
			'Hanalei',
			'Hanalei Fill',
			'Handlee',
			'Hanuman',
			'Happy Monkey',
			'Headland One',
			'Henny Penny',
			'Herr Von Muellerhoff',
			'Hind',
			'Holtwood One SC',
			'Homemade Apple',
			'Homenaje',
			'IM Fell DW Pica',
			'IM Fell DW Pica SC',
			'IM Fell Double Pica',
			'IM Fell Double Pica SC',
			'IM Fell English',
			'IM Fell English SC',
			'IM Fell French Canon',
			'IM Fell French Canon SC',
			'IM Fell Great Primer',
			'IM Fell Great Primer SC',
			'Iceberg',
			'Iceland',
			'Imprima',
			'Inconsolata',
			'Inder',
			'Indie Flower',
			'Inika',
			'Irish Grover',
			'Istok Web',
			'Italiana',
			'Italianno',
			'Jacques Francois',
			'Jacques Francois Shadow',
			'Jaldi',
			'Jim Nightshade',
			'Jockey One',
			'Jolly Lodger',
			'Josefin Sans',
			'Josefin Slab',
			'Joti One',
			'Judson',
			'Julee',
			'Julius Sans One',
			'Junge',
			'Jura',
			'Just Another Hand',
			'Just Me Again Down Here',
			'Kalam',
			'Kameron',
			'Kantumruy',
			'Karla',
			'Karma',
			'Kaushan Script',
			'Kavoon',
			'Kdam Thmor',
			'Keania One',
			'Kelly Slab',
			'Kenia',
			'Khand',
			'Khmer',
			'Khula',
			'Kite One',
			'Knewave',
			'Kotta One',
			'Koulen',
			'Kranky',
			'Kreon',
			'Kristi',
			'Krona One',
			'Kurale',
			'La Belle Aurore',
			'Laila',
			'Lakki Reddy',
			'Lancelot',
			'Lateef',
			'Lato',
			'League Script',
			'Leckerli One',
			'Ledger',
			'Lekton',
			'Lemon',
			'Libre Baskerville',
			'Life Savers',
			'Lilita One',
			'Lily Script One',
			'Limelight',
			'Linden Hill',
			'Lobster',
			'Lobster Two',
			'Londrina Outline',
			'Londrina Shadow',
			'Londrina Sketch',
			'Londrina Solid',
			'Lora',
			'Love Ya Like A Sister',
			'Loved by the King',
			'Lovers Quarrel',
			'Luckiest Guy',
			'Lusitana',
			'Lustria',
			'Macondo',
			'Macondo Swash Caps',
			'Magra',
			'Maiden Orange',
			'Mako',
			'Mallanna',
			'Mandali',
			'Marcellus',
			'Marcellus SC',
			'Marck Script',
			'Margarine',
			'Marko One',
			'Marmelad',
			'Martel',
			'Martel Sans',
			'Marvel',
			'Mate',
			'Mate SC',
			'Maven Pro',
			'McLaren',
			'Meddon',
			'MedievalSharp',
			'Medula One',
			'Megrim',
			'Meie Script',
			'Merienda',
			'Merienda One',
			'Merriweather',
			'Merriweather Sans',
			'Metal',
			'Metal Mania',
			'Metamorphous',
			'Metrophobic',
			'Michroma',
			'Milonga',
			'Miltonian',
			'Miltonian Tattoo',
			'Miniver',
			'Miss Fajardose',
			'Modak',
			'Modern Antiqua',
			'Molengo',
			'Monda',
			'Monofett',
			'Monoton',
			'Monsieur La Doulaise',
			'Montaga',
			'Montez',
			'Montserrat',
			'Montserrat Alternates',
			'Montserrat Subrayada',
			'Moul',
			'Moulpali',
			'Mountains of Christmas',
			'Mouse Memoirs',
			'Mr Bedfort',
			'Mr Dafoe',
			'Mr De Haviland',
			'Mrs Saint Delafield',
			'Mrs Sheppards',
			'Muli',
			'Mystery Quest',
			'NTR',
			'Neucha',
			'Neuton',
			'New Rocker',
			'News Cycle',
			'Niconne',
			'Nixie One',
			'Nobile',
			'Nokora',
			'Norican',
			'Nosifer',
			'Nothing You Could Do',
			'Noticia Text',
			'Noto Sans',
			'Noto Serif',
			'Nova Cut',
			'Nova Flat',
			'Nova Mono',
			'Nova Oval',
			'Nova Round',
			'Nova Script',
			'Nova Slim',
			'Nova Square',
			'Numans',
			'Nunito',
			'Odor Mean Chey',
			'Offside',
			'Old Standard TT',
			'Oldenburg',
			'Oleo Script',
			'Oleo Script Swash Caps',
			'Open Sans',
			'Oranienbaum',
			'Orbitron',
			'Oregano',
			'Orienta',
			'Original Surfer',
			'Oswald',
			'Over the Rainbow',
			'Overlock',
			'Overlock SC',
			'Ovo',
			'Oxygen',
			'Oxygen Mono',
			'PT Mono',
			'PT Sans',
			'PT Sans Caption',
			'PT Sans Narrow',
			'PT Serif',
			'PT Serif Caption',
			'Pacifico',
			'Palanquin',
			'Palanquin Dark',
			'Paprika',
			'Parisienne',
			'Passero One',
			'Passion One',
			'Pathway Gothic One',
			'Patrick Hand',
			'Patrick Hand SC',
			'Patua One',
			'Paytone One',
			'Peddana',
			'Peralta',
			'Permanent Marker',
			'Petit Formal Script',
			'Petrona',
			'Philosopher',
			'Piedra',
			'Pinyon Script',
			'Pirata One',
			'Plaster',
			'Play',
			'Playball',
			'Playfair Display',
			'Playfair Display SC',
			'Podkova',
			'Poiret One',
			'Poller One',
			'Poly',
			'Pompiere',
			'Pontano Sans',
			'Port Lligat Sans',
			'Port Lligat Slab',
			'Pragati Narrow',
			'Prata',
			'Preahvihear',
			'Press Start 2P',
			'Princess Sofia',
			'Prociono',
			'Prosto One',
			'Puritan',
			'Purple Purse',
			'Quando',
			'Quantico',
			'Quattrocento',
			'Quattrocento Sans',
			'Questrial',
			'Quicksand',
			'Quintessential',
			'Qwigley',
			'Racing Sans One',
			'Radley',
			'Rajdhani',
			'Raleway',
			'Raleway Dots',
			'Ramabhadra',
			'Ramaraja',
			'Rambla',
			'Rammetto One',
			'Ranchers',
			'Rancho',
			'Ranga',
			'Rationale',
			'Ravi Prakash',
			'Redressed',
			'Reenie Beanie',
			'Revalia',
			'Ribeye',
			'Ribeye Marrow',
			'Righteous',
			'Risque',
			'Roboto',
			'Roboto Condensed',
			'Roboto Slab',
			'Rochester',
			'Rock Salt',
			'Rokkitt',
			'Romanesco',
			'Ropa Sans',
			'Rosario',
			'Rosarivo',
			'Rouge Script',
			'Rozha One',
			'Rubik Mono One',
			'Rubik One',
			'Ruda',
			'Rufina',
			'Ruge Boogie',
			'Ruluko',
			'Rum Raisin',
			'Ruslan Display',
			'Russo One',
			'Ruthie',
			'Rye',
			'Sacramento',
			'Sail',
			'Salsa',
			'Sanchez',
			'Sancreek',
			'Sansita One',
			'Sarina',
			'Sarpanch',
			'Satisfy',
			'Scada',
			'Scheherazade',
			'Schoolbell',
			'Seaweed Script',
			'Sevillana',
			'Seymour One',
			'Shadows Into Light',
			'Shadows Into Light Two',
			'Shanti',
			'Share',
			'Share Tech',
			'Share Tech Mono',
			'Shojumaru',
			'Short Stack',
			'Siemreap',
			'Sigmar One',
			'Signika',
			'Signika Negative',
			'Simonetta',
			'Sintony',
			'Sirin Stencil',
			'Six Caps',
			'Skranji',
			'Slabo 13px',
			'Slabo 27px',
			'Slackey',
			'Smokum',
			'Smythe',
			'Sniglet',
			'Snippet',
			'Snowburst One',
			'Sofadi One',
			'Sofia',
			'Sonsie One',
			'Sorts Mill Goudy',
			'Source Code Pro',
			'Source Sans Pro',
			'Source Serif Pro',
			'Special Elite',
			'Spicy Rice',
			'Spinnaker',
			'Spirax',
			'Squada One',
			'Sree Krushnadevaraya',
			'Stalemate',
			'Stalinist One',
			'Stardos Stencil',
			'Stint Ultra Condensed',
			'Stint Ultra Expanded',
			'Stoke',
			'Strait',
			'Sue Ellen Francisco',
			'Sumana',
			'Sunshiney',
			'Supermercado One',
			'Suranna',
			'Suravaram',
			'Suwannaphum',
			'Swanky and Moo Moo',
			'Syncopate',
			'Tangerine',
			'Taprom',
			'Tauri',
			'Teko',
			'Telex',
			'Tenali Ramakrishna',
			'Tenor Sans',
			'Text Me One',
			'The Girl Next Door',
			'Tienne',
			'Timmana',
			'Tinos',
			'Titan One',
			'Titillium Web',
			'Trade Winds',
			'Trocchi',
			'Trochut',
			'Trykker',
			'Tulpen One',
			'Ubuntu',
			'Ubuntu Condensed',
			'Ubuntu Mono',
			'Ultra',
			'Uncial Antiqua',
			'Underdog',
			'Unica One',
			'UnifrakturMaguntia',
			'Unkempt',
			'Unlock',
			'Unna',
			'VT323',
			'Vampiro One',
			'Varela',
			'Varela Round',
			'Vast Shadow',
			'Vesper Libre',
			'Vibur',
			'Vidaloka',
			'Viga',
			'Voces',
			'Volkhov',
			'Vollkorn',
			'Voltaire',
			'Waiting for the Sunrise',
			'Wallpoet',
			'Walter Turncoat',
			'Warnes',
			'Wellfleet',
			'Wendy One',
			'Wire One',
			'Yanone Kaffeesatz',
			'Yellowtail',
			'Yeseva One',
			'Yesteryear',
			'Zeyada',
		);
	}

	public static function getStandardFontsList() {
		return array(
			'Georgia',
			'Palatino Linotype',
			'Times New Roman',
			'Arial',
			'Helvetica',
			'Arial Black',
			'Gadget',
			'Comic Sans MS',
			'Impact',
			'Charcoal',
			'Lucida Sans Unicode',
			'Lucida Grande',
			'Tahoma',
			'Geneva',
			'Trebuchet MS',
			'Verdana',
			'Geneva',
			'Courier New',
			'Courier',
			'Lucida Console',
			'Monaco',
		);
	}

	public static function getAllFontsList() {
		if (empty(self::$fontsList)) {
			$fontsList = array_merge(self::getFontsList(), self::getStandardFontsList());
			natsort( $fontsList );
			array_unshift( $fontsList, '');
			$options = array();
			foreach ( $fontsList as $font ) {
				$options[ $font ] = $font;
			}
			self::$fontsList = $options;
		}
		return self::$fontsList;
	}
	public static function getFontStyles() {
		return array( '' => '', 'n' => 'normal', 'b' => 'bold', 'i' => 'italic', 'bi' => 'bold + italic' );
	}
	public static function getBorderStyles() {
		return array( '' => '', 'solid' => 'solid', 'dashed' => 'dashed', 'dotted' => 'dotted', 'double' => 'double' );
	}
}
