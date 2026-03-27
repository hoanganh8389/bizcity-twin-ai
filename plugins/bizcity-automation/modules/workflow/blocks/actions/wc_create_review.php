<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wc_create_review extends WaicAction {
	protected $_code = 'wc_create_review';
	protected $_order = 10;
	
	public function __construct( $block = null ) {
		$this->_name = __('Create Review', 'ai-copilot-content-generator');
		//$this->_desc = __('Action', 'ai-copilot-content-generator') . ': wp_login';
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
		$this->_settings = array(
			'id' => array(
				'type' => 'input',
				'label' => __('Product ID *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'content' => array(
				'type' => 'textarea',
				'label' => __('Review Content', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'rating' => array(
				'type' => 'select',
				'label' => __('Rating', 'ai-copilot-content-generator'),
				'default' => '5',
				'options' => array('5' => 5, '4' => 4, '3' => 3, '2' => 2, '1' => 1),
			),
			'status' => array(
				'type' => 'select',
				'label' => __('Review Status', 'ai-copilot-content-generator'),
				'default' => '',
				'options' => $this->getCommentStatusesList(),
			),
			'author' => array(
				'type' => 'select',
				'label' => __('Review Author', 'ai-copilot-content-generator'),
				'default' => get_current_user_id(),
				'options' => $wordspace->getUsersList(array(0 => '')),
			),
			'parent' => array(
				'type' => 'input',
				'label' => __('Reply to Review Id', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
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
		$this->_variables = $this->getCommentVariables();
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		if (!WaicUtils::isWooCommercePluginActivated()) {
			$this->_results = array('result' => array(), 'error' => 'WooCommerce is not activated', 'status' => 7);
			return $this->_results;
		}
		$error = '';
		$productId = (int) $this->replaceVariables($this->getParam('id'), $variables);
		if (empty($productId)) {
			$error = 'Product ID needed';
		} else {
			$product = wc_get_product($productId);
			if (!$product) {
				$error = 'Product not found';
			} else if ($product->is_type('variation')) {
				$error = 'It is impossible to create a review to variation';
			}
		}
		
		if (empty($error)) {
			$comment = array('comment_post_ID' => $productId, 'comment_type' => 'review');
			
			$content = $this->getParam('content');
			if (!empty($content)) {
				$comment['comment_content'] = $this->replaceVariables($content, $variables);
			}
			$status = $this->getParam('status');
			if ('approved' == $status) {
				$status = 1;
			} else if ('spam' != $status && 'trash' != $status) {
				$status = 0;
			}
			$comment['comment_approved'] = $status;
			
			$userId = $this->getParam('author', 0, 1);
			$name = '';
			$email = '';
			if (!empty($userId)) {
				$user = get_userdata($userId);
				if ($user) {
					$name = $user->display_name;
					$email = $user->user_email;
				} else {
					$userId = 0;
				}
			}
			$comment['user_id'] = $userId;
			$comment['comment_author'] = $name;
			$comment['comment_author_email'] = $email;
		}
		if (empty($error)) {
			$parent = (int) $this->replaceVariables($this->getParam('parent'), $variables);
			if (!empty($parent)) {
				$reply = get_comment($parent);
				if (!$reply) {
					$error = 'Review for reply not found';
				} else if ('review' != $reply->comment_type) {
					$error = 'Comment for reply is not review';
				} else {
					$comment['comment_parent'] = $parent;
				}
			}
		}
		
		$commentId = 0;
		if (empty($error)) {
			$commentId = wp_insert_comment($comment);
			if ($commentId) {
				$rating = $this->getParam('rating', 0, 1);
				if ($rating < 1) {
					$rating = 1;
				} else if ($rating > 5) {
					$rating = 5;
				}
				add_comment_meta($commentId, 'rating', $rating);
			}
		}

		$this->_results = array(
			'result' => array('waic_comment_id' => (int) $commentId),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
