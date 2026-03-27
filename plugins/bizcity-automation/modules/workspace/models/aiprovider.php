<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAiproviderModel extends WaicModel implements WaicAIProviderInterface {

	private $provider = null;
	private $imageProvider = null;
	private $taskId;
	private $feature = '';
	private $userId;
	private $userIP;
	private $genMode;
	private $saveError = true;
	
	public function getEngine( $type = '' ) {
		switch ( $type ) {
			case 'image':
				return $this->imageProvider->getEngine();
			default:
				return $this->provider->getEngine();
		}
	}

	public function getInstance( $params ) {
		$defaults = WaicFrame::_()->getModule('options')->getModel()->getDefaults('api');

		$engine = $this->getModelName(WaicUtils::getArrayValue($params, 'engine'));
		if (empty($engine)) {
			WaicFrame::_()->pushError(esc_html__('AI Provider not found.', 'ai-copilot-content-generator'));
			return false;
		}
		
		$this->provider = $this->getModule()->getModel($engine);
		if ( !$this->provider ) {
			WaicFrame::_()->pushError(esc_html__('AI Provider not found', 'ai-copilot-content-generator'));
			return false;
		}
		
		$this->imageProvider = $this->getModule()->getModel($this->getModelName(WaicUtils::getArrayValue($params, 'image_engine', $defaults['image_engine'])));

		return $this;
	}

	public function init( $taskId = 0, $userId = 0, $userIP = '', $genMode = 0, $saveError = true ) {
		$this->taskId = $taskId;
		$this->userId = $userId;
		$this->userIP = $userIP;
		$this->genMode = $genMode;
		$this->saveError = $saveError;
		if (!empty($taskId)) {
			$this->feature = WaicFrame::_()->getModule('workspace')->getModel('tasks')->getTaskFeature($taskId);
		}

		if ( $this->imageProvider ) {
			$this->imageProvider->init();
		}

		return $this->provider->init();
	}
	public function setFeature( $feature ) {
		$this->feature = $feature;
	}
	public function setSaveError( $saveError ) {
		$this->saveError = $saveError;
	}

	public function setApiOptions( $options ) {
		$result =  $this->provider->setApiOptions($options);
		if (false === $result && $this->saveError) {
			WaicFrame::_()->getModule('workspace')->getModel('tasks')->updateTask($this->taskId, array('status' => 7, 'message' => substr(WaicFrame::_()->getLastError(), 0, 240)));
		}

		if ( $this->imageProvider ) {
			$this->imageProvider->setApiOptions($options);
		}

		return $result;
	}
	
	public function getModels() {
		return $this->provider->getModels();
	}

	public function getText( $params, $stream = null, $type = '' ) {
		$step = 0;
		$isTools = false;
		$tokens = 0;
		$maxSteps = 5;
		if (!empty($params['tools'])) {
			$toolsOptions = $params['tools']['options'];
			$params['tools'] = $this->getToolsList($params['tools']['functions']);
			$isTools = true;
		}
		do {
			$step++;
			if($isTools) {
				set_time_limit(300);
			}
			$data = $this->provider->getText( $params, $stream );

			if (false === $data) {
				$results['error'] = 1;
				$results['msg'] = WaicFrame::_()->getLastError();
				return $results;
			}

			$results = $data['results'];
			$params = $data['params'];
			if ($isTools && $step < $maxSteps && $results['data'] == 'tool_calls' && !empty($results['tools']) && !empty($results['tools'][0])) {
				$tool = $results['tools'][0];
				$name = empty($tool->function->name) ? '' : $tool->function->name;
				if (empty($tool->function->arguments)) {
					$args = array();
				} else if (is_string($tool->function->arguments)) {
					$args = json_decode($tool->function->arguments, true);
				} else {
					$args = $tool->function->arguments;
				}
				$answer = $this->doTool($name, $args, $toolsOptions);
				if (!empty($params['messages']) && is_array($params['messages'])) {
					$params['messages'][] = empty($results['tools_message']) ? array(
						'role' => 'assistant',
						'tool_calls' => array($tool),
					) : $results['tools_message'];
					$params['messages'][] = $this->provider->getToolsAnswer($answer, $tool);
				}
				$tokens += $results['tokens'];
				continue;
			}
			$results['tokens'] += $tokens;
			$history = $this->getHistory($results, $params, $type);
			$results['his_id'] = WaicFrame::_()->getModule('workspace')->getModel('history')->saveHistory($history);
			if ($results['data'] == 'tool_calls') {
				$results['data'] = __('Not found', 'ai-copilot-content-generator');
			}
			break;
		} while (true);

		return $results;
	}

	public function getImage( $params ) {
		if ( !$this->imageProvider ) {
			WaicFrame::_()->pushError(esc_html__('Image AI Provider not found', 'ai-copilot-content-generator'));
			return false;
		}

		$data = $this->imageProvider->getImage( $params );

		if (false === $data) {
			$results['error'] = 1;
			$results['msg'] = WaicFrame::_()->getLastError();

			return $results;
		}


		$results = $data['results'];
		$params = $data['params'];

		$history = $this->getHistory($results, $params, '', 'image');

		$results['his_id'] = WaicFrame::_()->getModule('workspace')->getModel('history')->saveHistory($history);

		return $results;
	}

	private function getModelName( $engine ) {
		return str_replace('-', '', $engine);
	}

	private function getHistory( $results, $params, $type = '', $typeProvider = '' ) {
		$history = array(
			'engine' => $this->getEngine($typeProvider),
			'model' => empty($params['model']) ? $type : $params['model'],
			'task_id' => $this->taskId,
			'feature' => $this->feature,
			'user_id' => $this->userId,
			'ip' => $this->userIP,
			'mode' => $this->genMode,
		);
		$history['status'] = $results['error'];
		$history['tokens'] = $results['tokens'];

		return $history;
	}
	
	public function sendFile( $params ) {
		$data = $this->provider->sendFile( $params );

		if (false === $data) {
			$results['error'] = 1;
			$results['msg'] = WaicFrame::_()->getLastError();

			return $results;
		}

		$results = $data['results'];
		$params = $data['params'];

		$history = $this->getHistory($results, $params, 'train');

		$results['his_id'] = WaicFrame::_()->getModule('workspace')->getModel('history')->saveHistory($history);

		return $results;
	}
	public function getFineTunes( $params, $method = 'POST', $job = false ) {
		$data = $this->provider->getFineTunes( $params, $method, $job );

		if (false === $data) {
			$results['error'] = 1;
			$results['msg'] = WaicFrame::_()->getLastError();

			return $results;
		}

		$results = $data['results'];
		$params = $data['params'];

		$history = $this->getHistory($results, $params, 'check_train');

		$results['his_id'] = WaicFrame::_()->getModule('workspace')->getModel('history')->saveHistory($history);

		return $results;
	}
	public function sendEmbeddings( $params, $method = 'POST' ) {
		$data = $this->provider->sendEmbeddings( $params, $method );

		if (false === $data) {
			$results['error'] = 1;
			$results['msg'] = WaicFrame::_()->getLastError();

			return $results;
		}

		$results = $data['results'];
		$params = $data['params'];

		$history = $this->getHistory($results, $params, 'embeddings');

		$results['his_id'] = WaicFrame::_()->getModule('workspace')->getModel('history')->saveHistory($history);

		return $results;
	}
	public function addTaxonomiesArgs( $args ) {
		$args['taxonomies'] = array(
			'type' => 'array',
			'description' => 'List of taxonomy filters (categories, tags, attributes). Each item specifies taxonomy slug, value, and logic.',
			'items' => array(
				'type' => 'object',
				'properties' => array(
					'taxonomy' => array(
						'type' => 'string',
						'description' => 'Taxonomy slug (e.g., product_cat, product_tag, pa_color).'
					),
					'values' => array(
						'type' => 'array',
						'description' => 'List of term slugs or values for this taxonomy',
						'items' => array('type' => 'string'),
					),
					'logic' => array(
						'type' => 'string',
						'description' => 'Logic to combine multiple values inside this taxonomy',
						'enum' => array('AND', 'OR'),
					),
				),
				'required' => array('taxonomy', 'values'),
			),
		);
		$args['tax_logic'] = array(
			'type' => 'string',
			'enum' => array('AND', 'OR'),
			'description' => 'Logic to combine different taxonomies together',
		);
		return $args;
	}
	public function getTool( $key ) {
		$tool = false;
		switch ($key) {
			case 'search_products':
			case 'search_products_tax':
				$tool = array(
					'type' => 'function',
					'function' => array(
						'name' => 'search_products',
						'description' => 'Search WooCommerce products on this website. Use when the user asks about: products to buy, items for sale, specific product types, products with certain features or characteristics. Searches across product titles, descriptions, SKUs, taxonomies. Returns matching products with their details.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'query' => array('type' => 'string', 'description' => 'Text search across title, description, excerpt'),
								'sku' => array('type' => 'string', 'description' => 'Search by product SKU (Stock Keeping Unit). Use when the user specifies an product number or code.'),
								'price_min' => array('type' => 'number', 'description' => 'Minimum price'),
								'price_max' => array('type' => 'number', 'description' => 'Maximum price'),
								'on_sale' => array('type' => 'boolean', 'description' => 'Only discounted products'),
								'featured' => array('type' => 'boolean', 'description' => 'Only featured products'),
								'sort_by' => array('type' => 'string', 'enum' => array('price_asc', 'price_desc', 'popularity', 'rating', 'date'), 'description' => 'Sorting method'),
								'in_stock' => array('type' => 'boolean', 'description' => 'Only in-stock products (default: true)'),
							), 
						),
					),
				);
				if ('search_products_tax' == $key) {
					$tool['function']['parameters']['properties'] = $this->addTaxonomiesArgs($tool['function']['parameters']['properties']);
				}
				break;
			case 'get_taxonomy_values':
				$tool = array(
					'type' => 'function',
					'function' => array(
						'name' => 'get_taxonomy_values',
						'description' => 'Return possible values (terms) for one or more taxonomies. The result is always a dictionary where the KEY is the term slug (used in queries) and the VALUE is the human-readable name.',
						'parameters' => array(
							'type' => 'object',
							'properties' => array(
								'taxonomies' => array(
									'type' => 'array',
									'description' => 'List of taxonomy slugs to fetch values for (e.g., "product_cat", "product_tag", "pa_color").',
									'items' => array('type' => 'string'),
								)
							),
							'required' => array('taxonomies'),
						),
					),
				);
				break;
		}
		return $tool;
	}
	public function getToolsList( $tools ) {
		$list = array();
		foreach ($tools as $t) {
			$tool = $this->getTool($t);
			if ($tool) {
				$list[] = $tool;
			}
		}
		
		return WaicDispatcher::applyFilters('call_tools', $list);
	}
	public function doTool( $tool, $args, $options ) {
		$result = array();
		switch ($tool) {
			case 'get_taxonomy_values':
				if (isset($args['taxonomies']) && is_array($args['taxonomies'])) {
					foreach ($args['taxonomies'] as $taxonomy) {
						$terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true));
						if (!is_wp_error($terms) && !empty($terms)) {
							$values = []; 
							foreach ($terms as $term) {
								$values[$term->slug] = $term->name;
							} 
							$result[$taxonomy] = $values;
						}
					}
				}
				break;
			case 'search_products':
				if (!WaicUtils::isWooCommercePluginActivated()) {
					break;
				}
				$query = WaicUtils::getArrayValue($args, 'query');
				
				$queryArgs = array(
					'post_type' => array('product'),
					'post_status' => 'publish',
					'ignore_sticky_posts' => true,
					'posts_per_page' => WaicUtils::getArrayValue($options, 'prod_limit', 3, 1),
					//'fields' => 'ids',
					'tax_query' => array(),
					'meta_query' => array(),
				);
		
				if (!empty($query)) {
					$queryArgs['waic_search_query'] = $query;
					add_filter('posts_where', array($this, 'addSearchByWhere'), 10, 2 );
				}
				if (!isset($args['in_stock']) || $args['in_stock']) {
					$queryArgs['meta_query'][] = array(
						'key' => '_stock_status',
						'value' => array('instock'),
						'compare' => 'IN',
					);
				}
				if (!empty($args['sku'])) {
					$queryArgs['meta_query'][] = array(
						'key' => '_sku',
						'value' => trim($args['sku']),
						'compare' => '=',
					);
				}
				if (!empty($args['price_min'])) {
					$queryArgs['meta_query'][] = array(
						'key' => '_price',
						'value' => (float)$args['price_min'],
						'compare' => '>=',
						'type' => 'NUMERIC',
					);
				}
				if (!empty($args['price_max'])) {
					$queryArgs['meta_query'][] = array(
						'key' => '_price',
						'value' => (float)$args['price_max'],
						'compare' => '<=',
						'type' => 'NUMERIC',
					);
				}
				if (isset($args['on_sale']) && $args['on_sale']) {
					$saleIds = wc_get_product_ids_on_sale();
					if ($args['on_sale']) {
						$queryArgs['post__in'] = array_merge(array(0), $saleIds);
					} /*else if (!empty($saleIds)) {
						$queryArgs['post__not_in'] = $saleIds;
					}*/
				}
				
				if (isset($args['featured']) && $args['featured']) {
					$queryArgs['tax_query'][] = array(
						'taxonomy' => 'product_visibility',
						'field' => 'slug',
						'terms' => array('featured'),
						'operator' => $args['featured'] ? 'IN' : 'NOT IN',
					);
				}

				$include = WaicUtils::getArrayValue($options, 'prod_include');
				if (!empty($include)) {
					if ('ids' == $include) {
						$ids = trim(WaicUtils::getArrayValue($options, 'prod_inc_ids'));
						if (!empty($ids)) {
							$ids = WaicUtils::controlNumericValues(explode(',', $ids));
							$queryArgs['post__in'] = empty($queryArgs['post__in']) ? $ids : array_intersect($queryArgs['post__in'], $ids);
						}
					} else if ('cat' == $include) {
						$cats = WaicUtils::getArrayValue($options, 'prod_inc_cat', array(), 2);
						if (!empty($cats)) {
							$queryArgs['tax_query'][] = array(
								'taxonomy' => 'product_cat',
								'field' => 'term_id',
								'terms' => $cats,
								'operator' => 'IN',
								'include_children' => false,
							);
						}
					}
				}
				$exclude = WaicUtils::getArrayValue($options, 'prod_exclude');
				if (!empty($exclude)) {
					if ('ids' == $exclude) {
						$ids = trim(WaicUtils::getArrayValue($options, 'prod_exc_ids'));
						if (!empty($ids)) {
							$ids = WaicUtils::controlNumericValues(explode(',', $ids));
							$queryArgs['post__not_in'] = empty($queryArgs['post__not_in']) ? $ids : array_merge($queryArgs['post__not_in'], $ids);
						}
					} else if ('cat' == $exclude) {
						$cats = WaicUtils::getArrayValue($options, 'prod_exc_cat', array(), 2);
						if (!empty($cats)) {
							$queryArgs['tax_query'][] = array(
								'taxonomy' => 'product_cat',
								'field' => 'term_id',
								'terms' => $cats,
								'operator' => 'NOT IN',
								'include_children' => false,
							);
						}
					}
				}
				
				if (!empty($queryArgs['tax_query'])) {
					$queryArgs['tax_query']['relation'] = 'AND';
				}

				$taxonomies = WaicUtils::getArrayValue($args, 'taxonomies', array(), 2);
				if (!empty($taxonomies)) {
					$taxQuery = array('relation' => WaicUtils::getArrayValue($args, 'tax_logic') === 'OR' ? 'OR' : 'AND');
					foreach ($taxonomies as $tax) {
						$taxQuery[] = array(
							'taxonomy' => $tax['taxonomy'],
							'field' => 'slug', 
							'terms' => $tax['values'], 
							'operator' =>  WaicUtils::getArrayValue($tax, 'logic') === 'AND' ? 'AND' : 'IN',
							'include_children' => $tax['taxonomy'] == 'product_cat',
						);
					}
					if (empty($queryArgs['tax_query'])) {
						$queryArgs['tax_query'] = $taxQuery;
					} else {
						$queryArgs['tax_query'][] = $taxQuery;
					}
				}
				
				$sortBy = WaicUtils::getArrayValue($args, 'sort_by');
				switch ($sortBy) {
					case 'price_asc':
					case 'price_desc':
						$queryArgs['meta_key'] = '_price';
						$queryArgs['orderby'] = 'meta_value_num';
						$queryArgs['order'] = ( 'price_asc' == $sortBy ? 'ASC' : 'DESC' );
						break;
					case 'popularity':
						$queryArgs['meta_key'] = 'total_sales';
						$queryArgs['orderby'] = 'meta_value_num';
						$queryArgs['order'] = 'DESC';
						break;
					case 'rating':
						$queryArgs['meta_key'] = '_wc_average_rating';
						$queryArgs['orderby'] = 'meta_value_num';
						$queryArgs['order'] = 'DESC';
						break;
					case 'date':
						$queryArgs['orderby'] = 'date';
						$queryArgs['order'] = 'DESC';
						break;
				}
				
				$select = new WP_Query($queryArgs);
				if ($select->have_posts()) {
					foreach ($select->posts as $post) {
						$result[] = array(
							'id' => $post->ID,
							'title' => $post->post_title,
							'short_description' => $post->post_excerpt,
						);
					}
				}
				wp_reset_query();
				break;
		}
		return $result;
	}
	
	public function addSearchByWhere( $where, $wp_query ) {
		global $wpdb;
		if (!empty($wp_query->get( 'waic_search_query' ))) {
			$s = esc_sql($wp_query->get('waic_search_query'));
			$where .= ' AND (' . $wpdb->posts . '.post_title LIKE \'%' . $s . '%\' OR ' . $wpdb->posts . '.post_content LIKE \'%' . $s . '%\' OR ' . $wpdb->posts . '.post_excerpt LIKE \'%' . $s . '%\')';
		}
		return $where;
	}
}
