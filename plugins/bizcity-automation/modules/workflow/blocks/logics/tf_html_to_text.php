<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data Transformation - HTML -> Text
 */
class WaicLogic_tf_html_to_text extends WaicLogic {
    protected $_code = 'tf_html_to_text';
    protected $_subtype = 2;
    protected $_order = 12;

    public function __construct( $block = null ) {
        $this->_name = __('HTML → Text', 'ai-copilot-content-generator');
        $this->_desc = __('Chuyển HTML sang text (strip tags + decode entities).', 'ai-copilot-content-generator');
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
                'default' => 'HTML2TEXT',
            ),
            'html' => array(
                'type' => 'textarea',
                'label' => __('HTML input', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 8,
                'variables' => true,
            ),
            'collapse_ws' => array(
                'type' => 'select',
                'label' => __('Gộp khoảng trắng', 'ai-copilot-content-generator'),
                'default' => 1,
                'options' => array(0 => __('no', 'ai-copilot-content-generator'), 1 => __('yes', 'ai-copilot-content-generator')),
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->_variables = array(
                'text' => __('Text output', 'ai-copilot-content-generator'),
                'length' => __('Length', 'ai-copilot-content-generator'),
            );
        }
        return $this->_variables;
    }

    private function htmlToText($html, $collapseWs = true) {
        $html = (string) $html;

        // Keep some line breaks before strip
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\s*\/p\s*>/i', "\n", $html);
        $html = preg_replace('/<\s*\/div\s*>/i', "\n", $html);
        $html = preg_replace('/<\s*\/li\s*>/i', "\n", $html);

        $text = wp_strip_all_tags($html, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));

        if ($collapseWs) {
            // normalize whitespace, keep new lines
            $text = preg_replace("/[\t\f\v ]+/", ' ', $text);
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
            $text = trim($text);
        }

        return $text;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $html = $this->replaceVariables($this->getParam('html'), $variables);
        $collapse = (int) $this->getParam('collapse_ws', 1, 1) === 1;

        $text = $this->htmlToText($html, $collapse);

        $this->_results = array(
            'result' => array(
                'text' => $text,
                'length' => strlen((string) $text),
            ),
            'error' => '',
            'status' => 3,
        );

        return $this->_results;
    }
}
