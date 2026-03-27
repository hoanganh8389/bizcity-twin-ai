<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wc_review_updated extends WaicTrigger {
	protected $_code = 'wc_review_updated';
	protected $_hook = 'edit_comment';
	protected $_subtype = 2;
	protected $_order = 3;
	
	public function __construct( $block = null ) {
		$this->_name = __('Review Updated', 'ai-copilot-content-generator');
		$this->_desc = __('Action', 'ai-copilot-content-generator') . ': ' . $this->_hook;
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$wordspace = WaicFrame::_()->getModule('workspace');
		$args = array(
			'parent' => 0,
			'hide_empty' => 0,
			'orderby' => 'name',
			'order' => 'asc',
		);

		$this->_settings = array(
			'created_from' => array(
				'type' => 'date',
				'label' => __('Created date', 'ai-copilot-content-generator'),
				'default' => '',
				'add' => array('created_to'),
			),
			'created_to' => array(
				'type' => 'date',
				'label' => '',
				'default' => '',
				'inner' => true,
			),
			'statuses' => array(
				'type' => 'multiple',
				'label' => __('Review Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getCommentStatusesList(),
			),
			'content' => array(
				'type' => 'select',
				'label' => __('Review Content', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => __('Anything goes', 'ai-copilot-content-generator'),
					'has' => __('Content exist', 'ai-copilot-content-generator'),
					'empty' => __('No content', 'ai-copilot-content-generator'),
				),
			),
			'rating' => array(
				'type' => 'select',
				'label' => __('Rating', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => array(
					'' => __('Any Rating', 'ai-copilot-content-generator'),
					'more' => __('More than', 'ai-copilot-content-generator'),
					'less' => __('Less than', 'ai-copilot-content-generator'),
					'between' => __('Between', 'ai-copilot-content-generator'),
				),
			),
			'rating_value' => array(
				'type' => 'number',
				'label' => __('Value', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
				'max' => 5,
				'show' => array('rating' => array('more', 'less')),
			),
			'rating_from' => array(
				'type' => 'number',
				'label' => __('Range', 'ai-copilot-content-generator'),
				'default' => 0,
				'min' => 0,
				'max' => 5,
				'show' => array('rating' => array('between')),
				'add' => array('rating_to'),
			),
			'rating_to' => array(
				'type' => 'number',
				'label' => '',
				'default' => 5,
				'min' => 0,
				'max' => 5,
				'show' => array('rating' => array('between')),
				'inner' => true,
			),
			'role' => array(
				'type' => 'multiple',
				'label' => __('User Role', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getRolesList(),
			),
			'products' => array(
				'type' => 'input',
				'label' => __('Products contains (ids)', 'ai-copilot-content-generator'),
				'default' => '',
			),
			'categories' => array(
				'type' => 'multiple',
				'label' => __('Categories contains', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_cat', $args),
			),
			'tags' => array(
				'type' => 'multiple',
				'label' => __('Tags contains', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $wordspace->getTaxonomyHierarchy('product_tag', $args),
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
		$this->_variables = array_merge(
			$this->getProductVariables(), 
			$this->getCommentVariables(__('Review', 'ai-copilot-content-generator'), __('Product', 'ai-copilot-content-generator')),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 1) {
			return false;
		}
		$commentId = $args[0];

		$comment = get_comment($commentId);
		if (!$comment || 'review' !== $comment->comment_type) {
			return false;
		}
		$comDate = substr($comment->comment_date, 0, 10);
		$from = $this->getParam('created_from');
		if (!empty($from)) {
			if ($comDate < $from) {
				return false;
			}
		}
		$to = $this->getParam('created_to');
		if (!empty($to)) {
			if ($comDate > $to) {
				return false;
			}
		}
		
		$statuses = $this->getParam('statuses', array(), 2);
		if (!empty($statuses)) {
			if (!in_array(wp_get_comment_status($commentId), $statuses)) {
				return false;
			}
		}
		$content = $this->getParam('content');
		if (!empty($content)) {
			$commentContent = trim($comment->comment_content);
			if ('has' === $content && empty($commentContent)) {
				return false;
			}
			if ('empty' === $content && !empty($commentContent)) {
				return false;
			}
		}
		$ratingMode = $this->getParam('rating');
		if (!empty($ratingMode)) {
			$rating = get_comment_meta($commentId, 'rating', true);
			if ('' === $rating) {
				return false;
			}
			$rating = (int) $rating;
			if ('between' == $ratingMode) {
				$ratingFrom = $this->getParam('rating_from', 0, 1);
				$ratingTo = $this->getParam('rating_to', 0, 1);
				if ($rating < $ratingFrom || $rating > $ratingTo) {
					return false;
				}
			} else {
				$ratingValue = $this->getParam('rating_value', 0, 1);
				if ('more' == $ratingMode && $rating <= $ratingValue) {
					return false;
				}
				if ('less' == $ratingMode && $rating >= $ratingValue) {
					return false;
				}
			}
		}
		$roles = $this->getParam('role', array(), 2);
		$userId = $comment->user_id;
		if (!empty($roles)) {
			if (!$userId) {
				return false;
			}
			$user = get_user_by('id', (int) $userId);
			if (!$user) {
				return false;
			}
			$userRoles = $user->roles;
			$found = false;
			foreach ($roles as $role) {
				if (in_array($role, $userRoles)) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				return false;
			}
		}
		$postId = (int) $comment->comment_post_ID;
		$products = $this->getParam('products');
		if (!empty($products)) {
			$products = $this->controlIdsArray(explode(',', $products));
			if (!in_array($postId, $products)) {
				return false;
			}
		}
		$categories = $this->controlIdsArray($this->getParam('categories', array(), 2));
		if (!empty($categories)) {
			if (!$this->isInPostTaxonomy($postId, 'product_cat', $categories)) {
				return false;
			}
		}
		$tags = $this->controlIdsArray($this->getParam('tags', array(), 2));
		if (!empty($tags)) {
			if (!$this->isInPostTaxonomy($postId, 'product_tag', $tags)) {
				return false;
			}
		}
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_comment_id' => $commentId, 'waic_product_id' => $postId, 'waic_user_id' => $userId, 'obj_id' => $commentId);
	}
}
