<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicTrigger_wc_review_status_changed extends WaicTrigger {
	protected $_code = 'wc_review_status_changed';
	protected $_hook = 'transition_comment_status';
	protected $_subtype = 2;
	protected $_order = 4;
	
	public function __construct( $block = null ) {
		$this->_name = __('Review Status Changed', 'ai-copilot-content-generator');
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
		$statuses = $this->getCommentStatusesList();

		$this->_settings = array(
			'status_old' => array(
				'type' => 'multiple',
				'label' => __('Review Old Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
			'status_new' => array(
				'type' => 'multiple',
				'label' => __('Review New Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $statuses,
			),
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
			array('com_status_old' => __('Review Old Status', 'ai-copilot-content-generator')),
			$this->getUserVariables(),
		);
		return $this->_variables;
	}

	public function controlRun( $args = array() ) {
		if (count($args) < 3) {
			return false;
		}
		$newStatus = $args[0];
		$oldStatus = $args[1];
		$comment = $args[2];
		
		if (!$comment || 'review' !== $comment->comment_type) {
			return false;
		}
		$commentId = $comment->comment_ID;

		$stOld = $this->getParam('status_old', array(), 2);
		if (!empty($stOld)) {
			if (!in_array($oldStatus, $stOld)) {
				return false;
			}
		}
		$stNew = $this->getParam('status_new', array(), 2);
		if (!empty($stNew)) {
			if (!in_array($newStatus, $stNew)) {
				return false;
			}
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
		return array('date' => date('Y-m-d'), 'time' => date('H:i:s'), 'waic_comment_id' => $commentId, 'com_status_old' => $oldStatus, 'waic_product_id' => $postId, 'waic_user_id' => $userId, 'obj_id' => $commentId);
	}
}
