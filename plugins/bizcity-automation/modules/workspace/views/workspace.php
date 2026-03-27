<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicWorkspaceView extends WaicView {

	public function showAdminInfo() {
		$key = 'waic-notice-dismiss-domains';
		$dismiss = get_option($key);
		
		if (empty($dismiss)) {
			$url = site_url();
			$parts = explode('.', $url);
			$cnt = count($parts);
			if ($cnt > 1) {
				$domain = $parts[$cnt - 1];
				$exclusions = array(
					'by' => __('Belarus', 'ai-copilot-content-generator'),
					'cn' => __('China', 'ai-copilot-content-generator'),
					'ir' => __('Iran', 'ai-copilot-content-generator'),
					'kp' => __('North Korea', 'ai-copilot-content-generator'),
					'ru' => __('Russia', 'ai-copilot-content-generator'),
				);
				if (isset($exclusions[$domain])) {
					WaicAssets::_()->loadCoreJs();
					WaicFrame::_()->addScript('waic-notice-dismiss', $this->getModule()->getModPath() . 'assets/js/admin.notice.dismiss.js');
			
					$message = '<b>' . esc_html__('Your country, domain, or server location may not be supported by some AI providers (like OpenAI, Anthropic (Claude AI), or Google AI (Gemini)).', 'ai-copilot-content-generator') . '</b><br>' . 
						esc_html__('As a result, AIWU might not function correctly on your site. Some of the unsupported jurisdictions include', 'ai-copilot-content-generator') . ': <br>';
					foreach ($exclusions as $d => $n) {
						$message .= '.' . $d . ' (' . $n . ')<br>'; 
					}
					$message .= esc_html__('Please check the provider’s official list of supported regions for confirmation.', 'ai-copilot-content-generator');
					
					$this->assign('message', $message);
					$this->assign('add_class', 'waic-notice-dismiss');
					$this->assign('dis_slug', $key);
					WaicHtml::echoEscapedHtml($this->getContent('showAdminInfo'));
					return;
				}
			}
		}
		$taskId = $this->getModel()->getRunningTask();
		if (!empty($taskId)) {
			$this->assign( 'message',
				'<b>' . esc_html__('AI task is running!', 'ai-copilot-content-generator') . '</b><br/>' .
				esc_html__('You can watch the generation process on the task results page', 'ai-copilot-content-generator') .
				': <a href="' . $this->getModule()->getTaskUrl($taskId) . '">' . esc_html__('Go', 'ai-copilot-content-generator') . '</a>'
			);
			WaicHtml::echoEscapedHtml($this->getContent('showAdminInfo'));
		}
		$need = WaicFrame::_()->getModule('options')->getModel()->get('plugin', 'notifications');

		if (0 !== $need) {
			// BizCity: postscreate module đã bị remove => bỏ qua block này nếu không tồn tại
			$postscreate = WaicFrame::_()->getModule('postscreate');
			if ($postscreate && method_exists($postscreate, 'getModel')) {
				$taskId = $postscreate->getModel()->getWaitingPublish();
				if (!empty($taskId)) {
					$this->assign( 'message',
						'<b>' . esc_html__('New Post Generated and Ready for Review!', 'ai-copilot-content-generator') . '</b><br/>' .
						esc_html__('A new post has been generated. Please review the draft in the History section. You can either publish it or delete it to maintain content quality and relevance', 'ai-copilot-content-generator') .
						': <a href="' . $this->getModule()->getTaskUrl($taskId) . '">' . esc_html__('Review', 'ai-copilot-content-generator') . '</a>'
					);
					WaicHtml::echoEscapedHtml($this->getContent('showAdminInfo'));
				}
			}
		}
	}
	public function showWorkspace() {
		$assets = WaicAssets::_();
		$assets->loadCoreJs();
		$assets->loadDataTables(array('buttons', 'responsive'));
		$assets->loadAdminEndCss();
		
		$frame = WaicFrame::_();
		$path = $this->getModule()->getModPath() . 'assets/';
		$frame->addScript('bizcity-history', $path . 'js/admin.history.js');
		$frame->addScript('bizcity-workspace-import', $path . 'js/admin.workspace.import.js');
		
		$module = $this->getModule();
		$module->getModel('history')->calcTokens();

		$features = $module->getWorkspaceFeatures();
		$lang = array(
			'btn-delete' => esc_html__('Delete', 'ai-copilot-content-generator'),
			'btn-publish' => esc_html__('Publish', 'ai-copilot-content-generator'),
			'btn-unpublish' => esc_html__('Unpublish', 'ai-copilot-content-generator'),
			'confirm-delete' => esc_html__('Are you sure you want to delete all these tasks?', 'ai-copilot-content-generator') . '<div class="wbw-settings-fields mt-3"><input type="checkbox">' . esc_html__('delete generated content', 'ai-copilot-content-generator') . '</div>',
			'confirm-publish' => esc_html__('Are you sure you want to publish all these tasks?', 'ai-copilot-content-generator'),
			'confirm-unpublish' => esc_html__('Are you sure you want to unpublish all these tasks?', 'ai-copilot-content-generator'),
			'pageNext' => esc_html__('Next', 'ai-copilot-content-generator'),
			'pagePrev' => esc_html__('Prev', 'ai-copilot-content-generator'),
			'lengthMenu' => esc_html__('per page', 'ai-copilot-content-generator'),
			'tableLoading' => esc_html__('Loading...', 'ai-copilot-content-generator'),
		);
		
		$curTab = WaicReq::getVar('cur');
		
		$this->assign('lang', $lang);
		$this->assign('tabs', $module->getWorkspaceTabsList(is_null($curTab) || empty($curTab) ? '' : $curTab));
		$this->assign('features', $features);
		$this->assign('features_list', array_merge(array('' => __('All features', 'ai-copilot-content-generator')), $module->getFeaturesList(false)));
		$this->assign('img_path', $path . 'img');
		$this->assign('is_pro', $frame->isPro());
		$this->assign('api_key', $frame->getModule('options')->get('api', 'api_key'));
		$this->assign('deep_seek_api_key', $frame->getModule('options')->get('api', 'deep_seek_api_key'));
		$this->assign('gemini_api_key', $frame->getModule('options')->get('api', 'gemini_api_key'));

		return parent::getContent('adminWorkspace');
	}
}
