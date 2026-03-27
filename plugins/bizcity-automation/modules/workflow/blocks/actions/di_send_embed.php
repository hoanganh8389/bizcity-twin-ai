<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicAction_di_send_embed extends WaicAction {
	protected $_code = 'di_send_embed';
	protected $_order = 1;
	
	public function __construct( $block = null ) {
		$this->_name = __('Send Embed', 'ai-copilot-content-generator');
		$this->_desc = __('Send Embed to Discord channel', 'ai-copilot-content-generator');
		$this->setBlock($block);
	}
	public function getSettings() {
		if (empty($this->_settings)) {
			$this->setSettings();
		}
		return $this->_settings;
	}
	
	public function setSettings() {
		$accounts = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegAccountsList('messenger', 'discord');
		if (empty($accounts)) {
			$accounts = array('' => __('No connected accounts found', 'ai-copilot-content-generator'));
		}
		$keys = array_keys($accounts);
		
		$this->_settings = array(
			'account' => array(
				'type' => 'select',
				'label' => __('Account', 'ai-copilot-content-generator') . ' *',
				'options' => $accounts,
				'default' => $keys[0],
			),
			'title' => array(
				'type' => 'input',
				'label' => __('Title (max 256)', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'description' => array(
				'type' => 'textarea',
				'label' => __('Description (max 4096)', 'ai-copilot-content-generator'),
				'default' => '',
				'rows' => 4,
				'variables' => true,
			),
			'url' => array(
				'type' => 'input',
				'label' => __('Embed URL', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'color' => array(
				'type' => 'input',
				'label' => __('Color (HEX)', 'ai-copilot-content-generator'),
				'default' => '#3498db',
				'variables' => true,
			),
			'thumbnail' => array(
				'type' => 'input',
				'label' => __('Thumbnail URL', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'image' => array(
				'type' => 'input',
				'label' => __('Image URL', 'ai-copilot-content-generator'),
				'default' => '',
				'variables' => true,
			),
			'footer' => array(
				'type' => 'input',
				'label' => __('Footer Text (max 2048)', 'ai-copilot-content-generator'),
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
			'success' => __('Operation Success', 'ai-copilot-content-generator'),
			'title' => __('Title', 'ai-copilot-content-generator'),
			'description' => __('Description', 'ai-copilot-content-generator'),
			'url' => __('Embed Url', 'ai-copilot-content-generator'),
			'color' => __('Color DEC', 'ai-copilot-content-generator'),
			'thumbnail_url' => __('Thumbnail URL', 'ai-copilot-content-generator'),
			'image_url' => __('Image URL', 'ai-copilot-content-generator'),
			'footer_text' => __('Footer Text', 'ai-copilot-content-generator'),
		);
		return $this->_variables;
	}
	public function getResults( $taskId, $variables, $step = 0 ) {
		$account = $this->getParam('account');
		
		$integration = false;
		if (empty($account)) {
			$error = 'Account is empty';
		} else {
			$parts = explode('-', $account);
			if (count($parts) != 2) {
				$error = 'Account settings error';
			} else {
				$integCode = $parts[0];
				if ('discord' !== $integCode) {
					$error = 'Account code unacceptable';
				} else {
					$accountNum = (int) $parts[1];
					$integration = WaicFrame::_()->getModule('workflow')->getModel('integrations')->getIntegration($integCode, $accountNum);
					if (!$integration) {
						$error = 'Intergation account not found';
					}
				}
			}
		}
		$result = array();
		if (empty($error)) {
			$title = $this->replaceVariables($this->getParam('title'), $variables);
			$desc = $this->replaceVariables($this->getParam('description'), $variables);
			if (empty($message) && empty($desc)) {
				$error = 'Embed must have at least a title or description';
			} else {
				if (!empty($title) && WaicUtils::mbstrlen($title) > 256) {
					$error = 'Title is too long (max 256 characters)';
				} else if (!empty($desc) && WaicUtils::mbstrlen($desc) > 4096) {
					$error = 'Description is too long (max 4096 characters)';
				}
			}
		}
		if (empty($error) && $integration) {
			$data = array('title' => $title, 'description' => $desc);
			$url = $this->replaceVariables($this->getParam('url'), $variables);
			if (!empty($url)) {
				$data['url'] = $url;
			}
			$color = str_replace('#', '', $this->replaceVariables($this->getParam('color'), $variables));
			if (!empty($color)) {
				$data['color'] = hexdec($color);
			}
			$thumbnail = $this->replaceVariables($this->getParam('thumbnail'), $variables);
			if (!empty($thumbnail)) {
				$data['thumbnail'] = array('url' => $thumbnail);
			}
			$image = $this->replaceVariables($this->getParam('image'), $variables);
			if (!empty($image)) {
				$data['image'] = array('url' => $image);
			}
			$footer = $this->replaceVariables($this->getParam('footer'), $variables);
			if (!empty($footer)) {
				$data['footer'] = array('text' => $footer);
			}
			$result = $integration->doSendMessage(array('embeds' => array($data)));
			$error = empty($result['error']) ? '' : $result['error'];
			if (isset($result['embeds']) && isset($result['embeds'][0])) {
				$result = $result['embeds'][0];
			}
			if (isset($result['footer'])) {
				$result['footer_text'] = $result['footer']['text'];
			}
			if (isset($result['thumbnail'])) {
				$result['thumbnail_url'] = $result['thumbnail']['url'];
			}
			if (isset($result['image'])) {
				$result['image_url'] = $result['image']['url'];
			}
			unset($result['error'], $result['footer'], $result['embeds'], $result['thumbnail'], $result['image']);
		}
		if (empty($error)) {
			$result['success'] = 1;
		}
		
		$this->_results = array(
			'result' => $result,
			'error' => $error,
			'status' => empty($error) ? 3 : 7,
		);
		return $this->_results;
	}
	
}
