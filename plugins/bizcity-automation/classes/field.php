<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicField {
	public $name = '';
	public $html = '';
	public $type = '';
	public $default = '';
	public $value = '';
	public $label = '';
	public $maxlen = 0;
	public $id = 0;
	public $htmlParams = array();
	public $validate = array();
	public $description = '';
	/**
	 * Wheter or not add error html element right after input field
	 * if bool - will be added standard element
	 * if string - it will be add this string
	 */
	public $errorEl = false;
	/**
	 * Name of method in table object to prepare data before insert / update operations
	 */
	public $adapt = array( 'WaicHtml' => '', 'dbFrom' => '', 'dbTo' => '' );
	/**
	 * Init database field representation
	 *
	 * @param string $html html type of field (text, textarea, etc. @see html class)
	 * @param string $type database type (int, varcahr, etc.)
	 * @param mixed $default default value for this field
	 */
	public function __construct( $name, $html = 'text', $type = 'other', $default = '', $label = '', $maxlen = 0, $adaption = array(), $validate = '', $description = '' ) {
		$this->name = $name;
		$this->html = $html;
		$this->type = $type;
		$this->default = $default;
		$this->value = $default;    //Init field value same as default
		$this->label = $label;
		$this->maxlen = $maxlen;
		$this->description = $description;
		if ($adaption) {
			$this->adapt = $adaption;
		}
		if ($validate) {
			$this->setValidation($validate);
		}
		if (( 'varchar' == $type ) && !empty($maxlen) && !in_array('validLen', $this->validate)) {
			$this->addValidation('validLen');
		}
	}
	/**
	 * Function setErrorEl
	 *
	 * @param mixed $errorEl - if bool and "true" - than we will use standard error element, if string - we will use this string as error element
	 */
	public function setErrorEl( $errorEl ) {
		$this->errorEl = $errorEl;
	}
	public function getErrorEl() {
		return $this->errorEl;
	}
	public function setValidation( $validate ) {
		if (is_array($validate)) {
			$this->validate = $validate;
		} else {
			$m = '';
			if (strpos($validate, ',')) {
				$this->validate = array_map('trim', explode(',', $validate));
			} else {
				$this->validate = array(trim($validate));
			}
		}
	}
	public function addValidation( $validate ) {
		$this->validate[] = $validate;
	}
	/**
	 * Set $value property. 
	 * Sure - it is public and can be set directly, but it can be more 
	 * comfortable to use this method in future
	 *
	 * @param mixed $value value to be set
	 */
	public function setValue( $value, $fromDB = false ) {
		if (isset($this->adapt['dbFrom']) && $this->adapt['dbFrom'] && $fromDB) {
			$value = WaicFieldAdapter::_($value, $this->adapt['dbFrom'], WaicFieldAdapter::DB);
		}
		$this->value = $value;
	}
	public function setLabel( $label ) {
		$this->label = $label;
	}
	public function setHtml( $html ) {
		$this->html = $html;
	}
	public function getHtml() {
		return $this->html;
	}
	public function setName( $name ) {
		$this->name = $name;
	}
	public function getName() {
		return $this->name;
	}
	public function getValue() {
		return $this->value;
	}
	public function getLabel() {
		return $this->label;
	}
	public function setID( $id ) {
		$this->id = $id;
	}
	public function getID() {
		return $this->id;
	}
	public function setAdapt( $adapt ) {
		$this->adapt = $adapt;
	}
	public function displayValue() {
		$value = '';
		switch ($this->html) {
			case 'checkboxlist':
				$options = $this->getHtmlParam('OptionsWaic');
				$value = array();
				if (!empty($options) && is_array($options)) {
					foreach ($options as $opt) {
						if (isset($opt['checked']) && $opt['checked']) {
							$value[] = $opt['text'];
						}
					}
				}
				if (empty($value)) {
					$value = esc_html__('N/A', 'ai-copilot-content-generator');
				} else {
					$value = implode('<br />', $value);
				}
				break;
			case 'selectbox':
			case 'radiobuttons':
				$options = $this->getHtmlParam('OptionsWaic');
				if (!empty($options) && !empty($options[ $this->value ])) {
					$value = $options[ $this->value ];
				} else {
					$value = esc_html__('N/A', 'ai-copilot-content-generator');
				}
				break;
			default:
				if ('' == $this->value) {
					$value = esc_html__('N/A', 'ai-copilot-content-generator');
				} else {
					$m = '';
					if (is_array($this->value)) {
						$options = $this->getHtmlParam('OptionsWaic');
						if (!empty($options) && is_array($options)) {
							$valArr = array();
							foreach ($this->value as $v) {
								$valArr[] = $options[$v];
							}
							$value = waicRecImplode('<br />', $valArr);
						} else {
							$value = waicRecImplode('<br />', $this->value);
						}
					} else {
						$value = $this->value;
					}
				}
				break;
		}
		return $value;
	}
	public function showValue() {
		WaicHtml::echoEscapedHtml($this->displayValue());
	}
	public function addHtmlParam( $name, $value ) {
		$this->htmlParams[$name] = $value;
	}
	/**
	 * Alias for addHtmlParam();
	 */
	public function setHtmlParam( $name, $value ) {
		$this->addHtmlParam($name, $value);
	}
	public function setHtmlParams( $params ) {
		$this->htmlParams = $params;
	}
	public function getHtmlParam( $name ) {
		return isset($this->htmlParams[$name]) ? $this->htmlParams[$name] : false;
	}
	/**
	 * Check if the element exists in array
	 *
	 * @param array $param 
	 */
	public function checkVarFromParam( $param, $element ) {
		return WaicUtils::xmlAttrToStr($param, $element);
	}

	/**
	 * Prepares configuration options
	 * 
	 * @param file $xml
	 * @return array $config_params 
	 */
	public function prepareConfigOptions( $xml ) {
		// load xml structure of parameters
		$config = simplexml_load_file($xml);           
		$config_params = array();
		foreach ($config->params->param as $param) {
			// read the variables
			$name = $this->checkVarFromParam($param, 'name');
			$type = $this->checkVarFromParam($param, 'type');
			$label = $this->checkVarFromParam($param, 'label');
			$helper = $this->checkVarFromParam($param, 'WaicHelper');
			$module = $this->checkVarFromParam($param, 'WaicModule');
			$values = $this->checkVarFromParam($param, 'values');
			$default = $this->checkVarFromParam($param, 'default');
			$description = $this->checkVarFromParam($param, 'description');
			if ('' == $name) {
				continue;
			}
			// fill in the variables to configuration array
			$config_params[$name] = array(
				'type' => $type,
				'label' => $label,
				'WaicHelper' => $helper,
				'WaicModule' => $module,
				'values' => $values,
				'default' => $default,
				'description' => $description,
			);
		}
		return $config_params;
	}
	public function setDescription( $desc ) {
		$this->description = $desc;
	}
	public function getDescription() {
		return $this->description;
	}
	/**
	 * This method will prepare internal value to it's type
	 *
	 * @see $this->type
	 * @return mixed - prepared value on the basis of $this->type
	 */
	public function valToType() {
		switch ($this->type) {
			case 'int':
			case 'mediumint':
			case 'smallint':
				$this->value = (int) $this->value;
				break;
			case 'float':
				$this->value = (float) $this->value;
				break;
			case 'double':
			case 'decimal':
				$this->value = (float) $this->value;
				break;
		}
		return $this->type;
	}
}
