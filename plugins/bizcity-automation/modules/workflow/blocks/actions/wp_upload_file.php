<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_wp_upload_file extends WaicAction {
	protected $_code = 'wp_upload_file';
	protected $_order = 11;

	public function __construct( $block = null ) {
		$this->_name = __('Upload File', 'ai-copilot-content-generator');
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
			'file_url' => array(
				'type' => 'input',
				'label' => __('File URL *', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'chat_id' => array(
				'type' => 'input',
				'label' => __('Chat ID', 'ai-copilot-content-generator'),
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
		$this->_variables = array(
			'file_id' => __('Attachment ID', 'ai-copilot-content-generator'),
			'file_url' => __('File URL', 'ai-copilot-content-generator'),
			'file_type' => __('File Type', 'ai-copilot-content-generator'),
			'file_name' => __('File Name', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$file_url = $this->replaceVariables($this->getParam('file_url'), $variables);
		$chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
		$error = '';
		$result = array();
		$attach_id = 0;
		$file_info = array();
		if (empty($file_url)) {
			$error = 'File URL is required';
		} else {
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/media.php');
			$tmp = download_url($file_url);
			if (is_wp_error($tmp)) {
				$error = 'Download failed: ' . $tmp->get_error_message();
			} else {
				$file = array(
					'name' => basename($file_url),
					'tmp_name' => $tmp,
				);
				$attach_id = media_handle_sideload($file, 0);
				if (is_wp_error($attach_id)) {
					$error = 'Upload failed: ' . $attach_id->get_error_message();
				} else {
					$url = wp_get_attachment_url($attach_id);
					$type = get_post_mime_type($attach_id);
					$name = get_the_title($attach_id);
					$file_info = array(
						'file_id' => $attach_id,
						'file_url' => $url,
						'file_type' => $type,
						'file_name' => $name,
					);
				}
				@unlink($tmp);
			}
		}
		// Phản hồi về Telegram
		if (function_exists('twf_telegram_send_message') && !empty($chat_id)) {
			if (empty($error)) {
				$msg = "✅ Upload thành công!\nID: {$attach_id}\nURL: {$file_info['file_url']}\nType: {$file_info['file_type']}\nName: {$file_info['file_name']}";
			} else {
				$msg = "❌ Upload thất bại: {$error}";
			}
			twf_telegram_send_message($chat_id, $msg);
		}
		$this->_results = array(
			'result' => $file_info,
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
}
