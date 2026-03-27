<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicOptionsController extends WaicController {
	public function getNoncedMethods() {
		return array('saveOptions', 'restoreOptions', 'saveApiKey', 'checkApiModels');
	}
	public function saveOptions() {
		$res = new WaicResponse();

		$model = $this->getModel();
		$gr = WaicReq::getVar('group');
		$params = WaicReq::getVar('params', 'post', null, $model->getHtmlParams($gr));
		$params = $model->correctOptions($params, $gr);

		if ($model->saveOptions($params)) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}
		return $res->ajaxExec();
	}
	public function restoreOptions() {
		$res = new WaicResponse();
		$model = $this->getModel();
		$gr = WaicReq::getVar('group');
		$isApi = ( 'api' == $gr );
		if ($isApi) {
			$apiKey = $model->get('api', 'api_key');
			$deepSeekApiKey = $model->get('api', 'deep_seek_api_key');
			$geminiApiKey = $model->get('api', 'gemini_api_key');
		}
		if ($model->removeOptions($gr)) {
			if ($isApi) {
				$model->save('api', 'api_key', $apiKey);
				$model->save('api', 'deep_seek_api_key', $deepSeekApiKey);
				$model->save('api', 'gemini_api_key', $geminiApiKey);
			}
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}
		return $res->ajaxExec();
	}
	public function saveApiKey() {
		$res = new WaicResponse();
		if ($this->getModel()->save('api', 'api_key', WaicReq::getVar('key', 'post'))) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}
		return $res->ajaxExec();
	}
	public function checkApiModels() {
		$res = new WaicResponse();
		$provider = WaicReq::getVar('provider', 'post');
		$apiKey = WaicReq::getVar('api_key', 'post');
		$results = $this->getModel()->checkApiModels($provider, $apiKey);
		
		if (is_array($results)) {
			$res->addMessage(esc_html__('Done', 'ai-copilot-content-generator'));
			$res->addData('results', $results);
		} else {
			$res->pushError(WaicFrame::_()->getErrors());
		}

		return $res->ajaxExec();
	}
}
