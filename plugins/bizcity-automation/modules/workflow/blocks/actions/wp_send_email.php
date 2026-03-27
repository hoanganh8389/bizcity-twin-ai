<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_send_email extends WaicAction {
	protected $_code = 'wp_send_email';
	protected $_order = 0;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Email', 'ai-copilot-content-generator');
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
		$this->_settings = array(
			'to' => array(
				'type' => 'input',
				'label' => __('To Email *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'from' => array(
				'type' => 'input',
				'label' => __('From Email', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'from_name' => array(
				'type' => 'input',
				'label' => __('From Name', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'subject' => array(
				'type' => 'input',
				'label' => __('Subject', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'body' => array(
				'type' => 'textarea',
				'label' => __('Message *', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 6,
				'html' => true,
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
		$this->_variables = array(
			'to' => __('To Email', 'ai-copilot-content-generator'),
			'from' => __('From Email', 'ai-copilot-content-generator'),
			'from_name' => __('From Name', 'ai-copilot-content-generator'),
			'subject' => __('Subject', 'ai-copilot-content-generator'),
			'message' => __('Message', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$toEmail = $this->replaceVariables($this->getParam('to'), $variables);
		$toName = $this->replaceVariables($this->getParam('to_name'), $variables);
		$fromEmail = $this->replaceVariables($this->getParam('from'), $variables);
		$fromName = $this->replaceVariables($this->getParam('from_name'), $variables);
		
		if (empty($fromEmail)) {
			$fromEmail = get_option('admin_email');
		}
		
		$message = $this->replaceVariables($this->getParam('body'), $variables);
		
		$error = '';
		if (empty($fromEmail)) {
			$error = 'From Email is empty';
		} else if (!is_email($fromEmail)) {
			$error = 'From Email is not correct';
		} else if (empty($toEmail)) {
			$error = 'To Email is empty';
		} else if (empty($message)) {
			$error = 'The Message is empty';
		} else {
			$toEmails = explode(',', $toEmail);
			foreach ($toEmails as $i => $email) {
				$e = trim($email);
				if (is_email($e)) {
					$toEmails[$i] = $e;
				} else {
					$error = 'To Email is not correct';
				}
				$toEmail = implode(',', $toEmails);
			}
		}
		
		$subject = '';
		if (empty($error)) {
			if (empty($fromName)) {
				$fromName = get_bloginfo('name');
			}
			
			$headers = array(
				'Content-type: text/html; charset=utf-8',
				'Content-Transfer-Encoding: 8bit',
				'From: ' . $fromName . ' <' . $fromEmail . '>',
			);
			$subject = $this->replaceVariables($this->getParam('subject'), $variables);
			if (empty($subject)) {
				$subject = 'From ' . get_bloginfo('name');
			}
			if (!wp_mail($toEmail, $subject, $message, $headers)) {
				$error = 'Error by sending email';
			}
			/*if (!wp_mail($toEmail, html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'), html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $headers)) {
				$error = 'Error by sending email';
			}*/
		}
		
		$this->_results = array(
			'result' => array(
				'to' => $toEmail,
				'from' => $fromEmail,
				'from_name' => $fromName,
				'subject' => $subject,
				'message' => $message,
			),
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
