<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_update_post_taxonomy extends WaicAction {
	protected $_code = 'wp_update_post_taxonomy';
	protected $_order = 14;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Post Taxonomy', 'ai-copilot-content-generator');
		//$this->_desc = __('Only filled fields will be updated.', 'ai-copilot-content-generator');
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
			'id' => array(
				'type' => 'input',
				'label' => __('Post ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'taxonomy' => array(
				'type' => 'select',
				'label' => __('Taxonomy', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => WaicUtils::getObjectTaxonomiesList('post'),
			),
			'terms' => array(
				'type' => 'input',
				'label' => __('Terms sep. with commas', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'mode' => array(
				'type' => 'select',
				'label' => __('Mode', 'ai-copilot-content-generator'),
				'default' => 'add',
				'options' => array(
					'add' => 'Add',
					'replace' => 'Replace',
					'delete' => 'Delete',
				),
			),
		);
	}
	public function getVariables() {
		if (empty($this->_variables)) {
			$this->setVariables();
		}
		return $this->_variables;
	}
	public function setVariables() {
		$this->_variables = $this->getPostVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$error = '';
		$postId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		$taxonomy = $this->getParam('taxonomy');
		/*if (empty($taxonomy)) {
			$taxonomy = $this->replaceVariables($this->getParam('new'), $variables);
		}*/
		$terms = explode(',', $this->replaceVariables($this->getParam('terms'), $variables));
		$mode = $this->getParam('mode', 'add');
		
		if (empty($postId)) {
			$error = 'Post ID needed';
		} else if (empty($taxonomy)) {
			$error = 'Taxonomy needed';
		} else if (!taxonomy_exists($taxonomy)) {
			$error = 'Taxonomy not found';
		} else if ('delete' != $mode) {
			if (empty($terms)) {
				$error = 'Terms needed';
			}
		}
		if (empty($error)) {
			$post = get_post($postId);
			if (!$post) {
				$error = 'Post not found (ID=' . $postId . ')';
			}
		}
		$termIds = array();
		if (empty($error)) {
			foreach ($terms as $value) {
				$value = trim($value);
				if (is_numeric($value)) {
					$term = get_term_by('id', (int) $value, $taxonomy);
				} else {
					$term = get_term_by('slug', $value, $taxonomy);
					if (!$term) {
						$term = get_term_by('name', $value, $taxonomy);
					}
					if (!$term) {
						$term = wp_insert_term($value, $taxonomy);
						if (is_wp_error($term)) {
							continue;
						}
						$term = get_term_by('id', $term['term_id'], $taxonomy);
					}
				}
				if ($term && !is_wp_error($term)) {
					$termIds[] = $term->term_id;
				}
			}
			switch ($mode) {
				case 'replace':
					wp_set_object_terms($postId, $termIds, $taxonomy, false);
					break;
				case 'delete':
					$current = wp_get_object_terms($postId, $taxonomy, array('fields' => 'ids'));
					$remaining = array_diff($current, $termIds);
					wp_set_object_terms($postId, $remaining, $taxonomy, false);
					break;
				default: 
					wp_set_object_terms($postId, $termIds, $taxonomy, true);
					break;
			}
		}
		
		$this->_results = array(
			'result' => array('waic_post_id' => $postId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
