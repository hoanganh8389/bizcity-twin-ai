<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_update_product_taxonomy extends WaicAction {
	protected $_code = 'wc_update_product_taxonomy';
	protected $_order = 4;
	
	public function __construct( $block = null ) {
		$this->_name = __('Update Product Taxonomy', 'ai-copilot-content-generator');
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
		$taxonomies = WaicUtils::getObjectTaxonomiesList(array('product', 'product_variation'));
		
		unset($taxonomies['product_type']);
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Product ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'taxonomy' => array(
				'type' => 'select',
				'label' => __('Taxonomy', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $taxonomies,
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
		$this->_variables = $this->getProductVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		
		$error = '';
		$productId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		$taxonomy = $this->getParam('taxonomy');
		/*if (empty($taxonomy)) {
			$taxonomy = $this->replaceVariables($this->getParam('new'), $variables);
		}*/
		$terms = trim($this->replaceVariables($this->getParam('terms'), $variables));
		$terms = empty($terms) ? array() : explode(',', $terms);
		$mode = $this->getParam('mode', 'add');
		
		if (empty($productId)) {
			$error = 'Product ID needed';
		} else if (empty($taxonomy)) {
			$error = 'Taxonomy needed';
		} else if (!taxonomy_exists($taxonomy)) {
			$error = 'Taxonomy not found';
		} 
		if (empty($error)) {
			$product = wc_get_product($productId);
			if (!$product) {
				$error = 'Product not found (ID=' . $productId . ')';
			}
		}

		$termIds = array();
		$termSlugs = array();
		$isDelete = 'delete' == $mode;
		$deleteTaxonomy = ( $isDelete && empty($terms) );
		$isPA = substr($taxonomy, 0, 3) === 'pa_';
		if (!$isDelete) {
			if (empty($terms) && !$isPA) {
				$error = 'Terms needed';
			}
		}
		if (empty($error)) {
			$isVariation = $product->is_type('variation');
			
			if ($isVariation && !$isPA) {
				if (!is_object_in_taxonomy('product_variation', $taxonomy)) {
					$error = 'It is impossible to establish a taxonomy ' . $taxonomy . ' to variation';
				}
			}
		}

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
						if ($isDelete) {
							continue;
						}
						$term = wp_insert_term($value, $taxonomy);
						if (is_wp_error($term)) {
							continue;
						}
						$term = get_term_by('id', $term['term_id'], $taxonomy);
					}
				}
				if ($term && !is_wp_error($term)) {
					$termIds[] = $term->term_id;
					$termSlugs[] = empty($term->slug) ? $term->name : $term->slug;
				}
			}
			switch ($mode) {
				case 'replace':
					wp_set_object_terms($productId, $termIds, $taxonomy, false);
					break;
				case 'delete':
					$current = wp_get_object_terms($productId, $taxonomy, array('fields' => 'ids'));
					$remaining = array_diff($current, $termIds);
					wp_set_object_terms($productId, $remaining, $taxonomy, false);
					break;
				default: 
					wp_set_object_terms($productId, $termIds, $taxonomy, true);
					break;
			}
			if ($isPA) {
				$attributes = $product->get_attributes();
				$attribute = $attributes && isset($attributes[$taxonomy]) ? $attributes[$taxonomy] : false;
				$oSlugs = array();
				$isNew = false;
				if ($deleteTaxonomy) {
					unset($attributes[$taxonomy]);
					$attribute = false;
				} else {
					if (false === $attribute && !$isDelete) {
						$isNew = true;
						if ($isVariation) {
							$attribute = '';
						} else {
							$attribute = new WC_Product_Attribute();
							$attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
							$attribute->set_name($taxonomy);
							$attribute->set_visible(true);
							$attribute->set_variation(false);
						}
					}
					switch ($mode) {
						case 'replace':
							if ($isVariation) {
								$attribute = empty($termSlugs[0]) ? '' : $termSlugs[0];
							} else {
								$attribute->set_options($termSlugs);
							}
							break;
						case 'delete':
							if ($isVariation) {
								if (is_string($attribute) && in_array($attribute, $termSlugs)) {
									$attribute = '';
								}
							} else {
								if (false !== $attribute) {
									$newTerms = array();
									$options = $attribute->get_options();
									foreach ($options as $slug) {
										if (is_numeric($slug)) {
											$term = get_term_by('id', $slug, $taxonomy);
											if ($term) {
												$slug = $term->slug;
											}
										}
										if (!in_array($slug, $termSlugs)) {
											$newTerms[] = $slug;
										}
									}
									$attribute->set_options($newTerms);
								}
							}
							break;
						default: 
							if ($isVariation) {
								$attribute = empty($termSlugs[0]) ? '' : $termSlugs[0];
							} else {
								if ($isNew) {
									$attribute->set_options($termSlugs);
								} else {
									$newTerms = array();
									$options = $attribute->get_options();
									foreach ($options as $slug) {
										if (is_numeric($slug)) {
											$term = get_term_by('id', $slug, $taxonomy);
											if ($term) {
												$slug = $term->slug;
											}
										}
										$newTerms[] = $slug;
									}
									$newTerms = array_unique(array_merge($newTerms,$termSlugs));
									$attribute->set_options($newTerms);
								}
							}
							break;

					}
				}
				if (false !== $attribute) {
					$attributes[$taxonomy] = $attribute;
				}
				$product->set_attributes($attributes);
				$product->save();
			}
		}
		
		$this->_results = array(
			'result' => array('waic_product_id' => $productId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
