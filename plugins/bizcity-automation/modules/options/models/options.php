<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicOptionsModel extends WaicModel {
	private $_values = array();
	private $_valuesLoaded = false;
	private $_htmlParams = null;
	
	public function get( $gr, $key = '' ) {
		$this->_loadOptValues($gr);
		return empty($key) ? $this->_values[$gr] : ( isset($this->_values[$gr][$key]) ? $this->_values[$gr][$key] : false );
	}
	public function reset( $gr ) {
		unset($this->_values[$gr]);
	}
	public function isEmpty( $gr, $key ) {
		$value = $this->get($gr, $key);
		return ( false === $value );
	}
	public function save( $gr, $key, $val, $ignoreDbUpdate = false ) {
		$this->_loadOptValues($gr);
		if (!isset($this->_values[$gr][$key]) || $this->_values[$gr][$key] != $val) {
			$this->_values[$gr][$key] = $val;
			if (!$ignoreDbUpdate) {
				$this->_updateOptsInDb($gr);
			}
			return true;
		}
		return false;
	}
	public function getAll() {
		$tabs = $this->getModule()->getOptionsTabsList();
		foreach ($tabs as $gr => $d) {
			$this->_loadOptValues($gr);
		}
		return $this->_values;
	}
	public function correctOptions( $options, $gr ) {
		
		if ('api' == $gr && !empty($options[$gr]) && is_array($options[$gr])) {
			$checkOptions = array('common_language', 'human_style');
			foreach ($checkOptions as $opt) {
				if (empty($options[$gr][$opt])) {
					$options[$gr][$opt] = 0;
				}
			}
		}
		if ('plugin' == $gr && !empty($options[$gr]) && is_array($options[$gr])) {
			$checkOptions = array('notifications', 'logging', 'user_statistics');
			foreach ($checkOptions as $opt) {
				if (empty($options[$gr][$opt])) {
					$options[$gr][$opt] = 0;
				}
			}
		}
		if ('mcp' == $gr && !empty($options[$gr]) && is_array($options[$gr])) {
			$checkOptions = array('e_mcp', 'mcp_logging');
			foreach ($checkOptions as $opt) {
				if (empty($options[$gr][$opt])) {
					$options[$gr][$opt] = 0;
				}
			}
		}
		return $options;
	}

	public function saveOptions( $data = array(), $tabs = false ) {
		$leer = true;

		if (is_array($data)) {
			if (false === $tabs) {
				$tabs = $this->getModule()->getOptionsTabsList();
			}
			//$needRecalcPoints = false;
			foreach ($data as $gr => $d) {
				if (isset($tabs[$gr]) && is_array($d)) {
					$leer = false;
					$needSave = false;
					/*if ($tabs[$gr]['remove']) {
						$this->reset($gr);
						$needSave = true;
					}*/
					//var_dump($d);
					foreach ($d as $key => $val) {
						if ($this->save($gr, $key, $val, true)) {
							$needSave = true;
						}
					}
					if ($needSave) {
						$this->_updateOptsInDb($gr);
					}
				}
			}
		}
		if ($leer) {
			$this->pushError(esc_html__('Empty data to save option', 'ai-copilot-content-generator'));
			return false;
		}
		return true;
	}
	public function removeOptions( $gr ) {
		$tabs = $this->getModule()->getOptionsTabsList();
		if (isset($tabs[$gr])) {
			update_option(WAIC_CODE . '_options_' . $gr, '');
			unset($this->_values[$gr]);
		}
		return true;
	}
	private function _updateOptsInDb( $gr ) {
		update_option(WAIC_CODE . '_options_' . $gr, $this->_values[$gr]);
	}
	private function _loadOptValues( $gr ) {
		if (!isset($this->_values[$gr])) {
			$this->_values[$gr] = get_option(WAIC_CODE . '_options_' . $gr);
			if (empty($this->_values[$gr])) {
				if ('mcp' == $gr) {
					$this->_values[$gr] = get_option(WAIC_CODE . '_options_plugin');
				}
			}
			if (empty($this->_values[$gr])) {
				$this->_values[$gr] = array();
			}
			$html = $this->getHtmlParams($gr);
			foreach ($html as $key) {
				if (!empty($this->_values[$gr][$key])) {
					$this->_values[$gr][$key] = base64_decode($this->_values[$gr][$key]);
				}
			}
		}
	}
	public function getHtmlParams( $gr ) {
		if (is_null($this->_htmlParams)) {
			$params = $this->getDefaults('prompts');
			$this->_htmlParams['prompts'] = array_keys($params);
			$this->_htmlParams['router'] = array('prompt_template', 'types_list', 'types_json', 'output_map');
		}
		return empty($this->_htmlParams[$gr]) ? array() : $this->_htmlParams[$gr];
	}
	public function getDefaults( $gr = '', $key = '', $def = '' ) {
		$defaults = array(
			'api' => array(
				'api_key' => '',
				'model' => 'gpt-4o',
				'deep_seek_model' => 'deepseek-chat',
				'gemini_model' => 'gemini-2.5-flash',
				'claude_model' => 'claude-haiku-4-5',
				'perplexity_model' => 'sonar',
				'openrouter_model' => 'openrouter/auto',
				'engine' => 'open-ai',
				'image_engine' => 'open-ai',
				'language' => 'en',
				'tone' => 'profe',
				'temperature' => 0.7,
				'tokens' => 1500,
				'pre_minute' => 3,
				'top_p' => 0.01,
				'top_k' => 3,
				'frequency' => 0.01,
				'presence' => 0.01,
				'img_model' => 'dall-e-3',
				'gemini_img_model' => 'gemini-2.5-flash-image',
				'openrouter_img_model' => 'openai/gpt-5-image',
				'common_language' => 0,
				'human_style' => 0,
			),
			'mcp' => array(
				'e_mcp' => 0,
				'mcp_logging' => 0,
			),
			'plugin' => array(
				'user_statistics' => 1,
				'logging' => 0,
				'notifications' => 1,
				'date_format' => WAIC_DATE_FORMAT,
				'blocks_path' => ''
			),
			'router' => array(
				'enabled' => 1,
				'types_list' => "huong_dan\nviet_bai\ntao_san_pham\ntra_loi_khach\nkhac",
				'prompt_template' => "Bạn là bộ phân loại yêu cầu và trích xuất dữ liệu cho hệ thống Workflow.\n\nINPUT (tin nhắn người dùng):\n{message}\n\nDANH SÁCH CASE/TYPE:\n{types}\n\nYÊU CẦU BẮT BUỘC:\n- Chỉ trả về DUY NHẤT 1 JSON hợp lệ. Không thêm chữ, không markdown.\n- Không được bọc trong ```json.\n- Nếu thiếu dữ liệu: dùng \"\" hoặc 0 hoặc null.\n\nSCHEMA JSON (phải trả đúng key):\n{schema}",
				'types_json' => "[\n  {\n    \"type\": \"huong_dan\",\n    \"desc\": \"Hướng dẫn / giải thích / FAQ\"\n  },\n  {\n    \"type\": \"viet_bai\",\n    \"desc\": \"Viết bài / tạo nội dung\",\n    \"fields\": [\"info.title\", \"info.content\", \"info.keywords\", \"info.category\"]\n  },\n  {\n    \"type\": \"tao_san_pham\",\n    \"desc\": \"Tạo sản phẩm / mô tả sản phẩm\",\n    \"fields\": [\"info.product_name\", \"info.price\", \"info.image_url\"]\n  },\n  {\n    \"type\": \"tra_loi_khach\",\n    \"desc\": \"Trả lời khách hàng / CSKH\"\n  },\n  {\n    \"type\": \"khac\",\n    \"desc\": \"Không rõ / ngoài phạm vi\"\n  }\n]",
				'output_map' => "type=type:text\nconfidence=confidence:int\nreply=reply:text\ninfo_title=info.title:text\ninfo_content=info.content:text\ninfo_keywords=info.keywords:text\ninfo_category=info.category:text\ninfo_product_name=info.product_name:text\ninfo_price=info.price:float\ninfo_image_url=info.image_url:text\ninfo_audio_url=info.audio_url:text",
			),
			'prompts' => array(
				'title_topic' => 'Create a compelling and SEO-friendly article title based on the details provided below: ' . PHP_EOL .
					'- Topic: {topic} ' . PHP_EOL .
					'- Keywords (optional, to integrate if relevant): {keywords} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- Additional input for the title: {additional_prompt_for_title} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Title length: 50-60 characters ' . PHP_EOL . PHP_EOL .
					'Ensure the title adheres to the specified guidelines, follows best SEO practices, and is captivating for readers. Return only the title without any additional symbols or text.',
				'title_sections' => 'Create a compelling and SEO-friendly article title based on the details provided below: ' . PHP_EOL .
					'- Topic: {topic} ' . PHP_EOL .
					'- Outline (section headers): {sections} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- Additional input for the title: {additional_prompt_for_title} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Title length: 50-60 characters ' . PHP_EOL . PHP_EOL .
					'Ensure the title adheres to the specified guidelines, follows best SEO practices, and is captivating for readers. Return only the title without any additional symbols or text.',
				'title_body' => 'Create a compelling and SEO-friendly article title based on the details provided below: ' . PHP_EOL .
					'- Article body: {original_article_body} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- Additional input for the title: {additional_prompt_for_title} ' . PHP_EOL .
					'- Additional context: {topic} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Keywords to include: {keywords} ' . PHP_EOL .
					'- Title length: 50-60 characters ' . PHP_EOL . PHP_EOL .
					'Ensure the title adheres to the specified guidelines, incorporates the provided context and keywords, follows best SEO practices, and captivates readers. Return only the title without any additional symbols or text.',
				'body' => 'Generate an engaging, informative, and well-structured article body using the information provided below. ' . PHP_EOL . PHP_EOL .
					'- Title: {title} ' . PHP_EOL .
					'- Keywords: {keywords} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- {use_common_language} ' . PHP_EOL .
					'- {use_human_like_style} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Additional input for the body: {additional_prompt_for_body} ' . PHP_EOL . PHP_EOL .
					'Use HTML tags for headings, paragraphs (<p>), lists and other necessary formatting. Do not include any document-level tags like <html>, <head>, or <body>. ' . PHP_EOL . PHP_EOL .
					'Ensure the content is optimized for SEO, compelling, and well-organized. Return only the article body (without title) in clean HTML without any accompanying text.',
				'sections' => 'Generate a structured and engaging numbered list outline containing {number_of_sections} headings for an article based on the following details: ' . PHP_EOL . PHP_EOL .
					'- Topic: {topic} ' . PHP_EOL .
					'- Title of the article: {title} ' . PHP_EOL .
					'- Keywords (optional): {keywords} ' . PHP_EOL .
					'- Number of sections: {number_of_sections} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Additional input for the body: {additional_prompt_for_body} ' . PHP_EOL . PHP_EOL .
					'Ensure the outline follows best SEO practices, is engaging and informative, and contains only a numbered list of top-level headings (no nesting). Return only the numbered list without any additional text or symbols. ',
				'body_section' => 'We are building an article titled {title} with multiple sections: ' . PHP_EOL .
					'{sections} ' . PHP_EOL . PHP_EOL .
					'Details for generating the content:  ' . PHP_EOL .
					'- Keywords: {keywords} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- {use_common_language} ' . PHP_EOL .
					'- {use_human_like_style} ' . PHP_EOL .
					'- Additional input for the article as a whole: {additional_prompt} ' . PHP_EOL .
					'- Additional input for the body: {additional_prompt_for_body} ' . PHP_EOL . PHP_EOL .
					'Your task: ' . PHP_EOL .
					'Create content for the section titled {section#} following these guidelines: ' . PHP_EOL .
					'- Use <h3> for the section title {section#}. ' . PHP_EOL .
					'- Write approximately {length_for_body} words of engaging, informative content for this section of the article. ' . PHP_EOL .
					'- Use HTML tags appropriately for headings, paragraphs (<p>), lists, and text formatting to enhance readability and structure. ' . PHP_EOL .
					'- Important: Do not include any document-level tags like <html>, <head>, or <body>. Do not use code blocks, backticks, or markdown formatting in the response. Provide only plain HTML. ' . PHP_EOL .
					'- Ensure the content follows SEO best practices, including keyword usage, readability, and proper structure.' . PHP_EOL . PHP_EOL .
					'Return only the section heading and body content formatted in clean HTML without any additional text.',
				'categories' => 'Generate article categories based on the provided content: ' . PHP_EOL .
					'- Article title: {title} ' . PHP_EOL .
					'- Article body: {body} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Number of categories: {number_of_categories} ' . PHP_EOL .
					'- Additional input for the categories: {additional_prompt_for_categories} ' . PHP_EOL . PHP_EOL .
					'Your response should include {number_of_categories} categories, separated by commas, without any accompanying text or symbols.',
				'tags' => 'Generate article tags based on the provided content: ' . PHP_EOL . PHP_EOL .
					'- Article title: {title} ' . PHP_EOL .
					'- Article body: {body} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Number of tags: {number_of_tags} ' . PHP_EOL .
					'- Additional input for the tags: {additional_prompt_for_tags} ' . PHP_EOL . PHP_EOL .
					'Your response should include {number_of_tags} tags, separated by commas, without any accompanying text or symbols.',
				'excerpt' => 'Generate an excerpt for the article, focusing on the key aspects: ' . PHP_EOL .
					'- Title: {title} ' . PHP_EOL .
					'- Body: {body} ' . PHP_EOL .
					'- Keywords (optional): {keywords} ' . PHP_EOL .
					'- Desired excerpt length: {length_for_excerpt} characters ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL .
					'- Additional input for the Excerpt: {additional_prompt_for_excerpt} ' . PHP_EOL . PHP_EOL .
					'The response should contain only the excerpt without any accompanying text or formatting.',
				'custom' => 'Generate content for a custom field of the article, adhering to the specific requirements and context provided: ' . PHP_EOL . PHP_EOL .
					'- Article Title: {title} ' . PHP_EOL .
					'- Article Body: {body} ' . PHP_EOL .
					'- Keywords (optional): {keywords} ' . PHP_EOL .
					'- Language: {language} ' . PHP_EOL .
					'- Tone of voice: {tone_of_voice} ' . PHP_EOL . PHP_EOL .
					'Generation Requirement: ' . PHP_EOL .
					'- Custom Field Type: {field_type_for_custom} ' . PHP_EOL .
					'- Desired Length: {length_for_custom} ' . PHP_EOL .
					'- Custom Field Description by User: {additional_prompt_for_custom} ' . PHP_EOL . PHP_EOL .
					'The response should directly address the custom field purpose, strictly adhere to the Custom Field Type, match the Desired Length, and correspond to the Custom Field Description by User. The response should be presented without any accompanying text.',
				'image' => 'Create a high-quality featured image for the article that visually interprets its central themes and ideas, based on the provided details: ' . PHP_EOL . PHP_EOL .
					'- Article Title: {title} ' . PHP_EOL .
					'- Image Preset: {image_preset}. {image_preset_description} ' . PHP_EOL .
					'- Additional input for the Image: {additional_prompt_for_image} ' . PHP_EOL . PHP_EOL .
					'The image should embody the main concepts and mood of the article without including any text, ensuring it complements the content effectively. This visual representation should enhance the article appeal and provide deeper insight into its themes.',
				'image_alt' => 'We created an image for an article about the topic "{title}". Please generate an Alt text for this image. ' . PHP_EOL .
					'The Alt text must be between 10-70 characters long. Your response should contain only the Alt text, with no additional text or explanations.',
			),
		);
		$defaults = WaicDispatcher::applyFilters('getOptionsDefaults', $defaults);
		if (!empty($gr)) {
			$defaults = isset($defaults[$gr]) ? $defaults[$gr] : array();
		}
		return empty($key) ? $defaults : ( isset($defaults[$key]) ? $defaults[$key] : $def );
	}
	public function getVariations( $gr = '', $key = '', $var = '' ) {
		$vars = array(
			'api' => array(
				'engines' => array(
					'open-ai' => 'Open AI',
					'gemini' => 'Gemini',
					'deep-seek' => 'Deep Seek',
					'claude' => 'Claude',
					'perplexity' => 'Perplexity',
					'openrouter' => 'OpenRouter',
				),
				'model' => array(
					'open-ai' => array(
						'gpt-5' => 'GPT-5',
						'gpt-5-mini' => 'GPT-5 mini',
						'gpt-5-nano' => 'GPT-5 nano',
						'gpt-4.1' => 'GPT-4.1', 
						'gpt-4.1-mini' => 'GPT-4.1 mini', 
						'gpt-4.1-nano' => 'GPT-4.1 nano',
						'gpt-4o' => 'GPT-4o',
						'gpt-4o-mini' => 'GPT-4o-mini',
						'o1-preview' => 'O1-preview',
						'o1-mini' => 'O1-mini',
						'gpt-4-turbo' => 'GPT-4 Turbo',
						'gpt-4' => 'GPT-4',
					),
					'deep-seek' => array(
						'deepseek-chat' => 'deepseek-chat',
						'deepseek-reasoner' => 'deepseek-reasoner',
					),
					'gemini' => array(
						'gemini-3-pro-preview' => 'Gemini 3 Pro Preview',
						'gemini-3-flash-preview' => 'Gemini 3 Flash Preview',
						'gemini-2.5-flash' => 'Gemini 2.5 Flash',
						'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite',
						'gemini-2.5-pro' => 'Gemini 2.5 Pro',
						'gemini-2.0-flash' => 'Gemini 2.0 Flash',
						'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash-Lite',
						'gemini-embedding-exp' => 'Gemini Embedding',
					),
					'claude' => array(
						'claude-haiku-4-5' => 'Claude Haiku 4.5',
						'claude-sonnet-4-5' => 'Claude Sonnet 4.5',
						'claude-opus-4-1' => 'Claude Opus 4.1',
						'claude-sonnet-4-0' => 'Claude Sonnet 4',
						'claude-opus-4-0' => 'Claude Opus 4',
						'claude-3-5-haiku-latest' => 'Claude Haiku 3.5',
					),
					'perplexity' => array(
						'sonar' => 'Sonar',
						'sonar-pro' => 'Sonar Pro',
						'sonar-reasoning' => 'Sonar Reasoning',
						'sonar-reasoning-pro' => 'Sonar Reasoning Pro',
						'sonar-deep-research' => 'Sonar Deep Research',
					),
					'openrouter' => array(
						'openrouter/auto' => 'Auto Router',
					)
				),
				'tokens' => array(
					'gpt-5' => 128000,
					'gpt-5-mini' => 128000,
					'gpt-5-nano' => 128000,
					'gpt-4.1' => 32768, 
					'gpt-4.1-mini' => 32768, 
					'gpt-4.1-nano' => 32768,
					'gpt-4o' => 16384,
					'gpt-4o-mini' => 16384,
					'o1-preview' => 32768,
					'o1-mini' => 65536,
					'gpt-4-turbo' => 4096,
					'gpt-4' => 8192,
					'deepseek-chat' => 8192,
					'deepseek-reasoner' => 65536,
					'gemini-3-pro-preview' => 65536,
					'gemini-3-flash-preview' => 65536,
					'gemini-2.5-flash' => 65536,
					'gemini-2.5-flash-lite' => 65536,
					'gemini-2.5-pro' => 65536,
					'gemini-2.0-flash' => 8192,
					'gemini-2.0-flash-lite' => 8192,
					'gemini-embedding-exp' => 3072,
					'claude-haiku-4-5' => 65536,
					'claude-sonnet-4-5' => 65536,
					'claude-opus-4-1' => 32768,
					'claude-sonnet-4-0' => 65536,
					'claude-opus-4-0' => 32768,
					'claude-3-5-haiku-latest' => 8192,
					'sonar' => 65536,
					'sonar-pro' => 128000,
					'sonar-reasoning' => 65536,
					'sonar-reasoning-pro' => 65536,
					'sonar-deep-research' => 65536,
				),
				'language' => array(
					'en' => 'English',
					'af' => 'Afrikaans',
					'ar' => 'Arabic',
					'an' => 'Armenian',
					'bs' => 'Bosnian',
					'bg' => 'Bulgarian',
					'zh' => 'Chinese (Simplified)',
					'zt' => 'Chinese (Traditional)',
					'hr' => 'Croatian',
					'cs' => 'Czech',
					'da' => 'Danish',
					'nl' => 'Dutch',
					'et' => 'Estonian',
					'fil' => 'Filipino',
					'fi' => 'Finnish',
					'fr' => 'French',
					'de' => 'German',
					'el' => 'Greek',
					'he' => 'Hebrew',
					'hi' => 'Hindi',
					'hu' => 'Hungarian',
					'id' => 'Indonesian',
					'it' => 'Italian',
					'ja' => 'Japanese',
					'ko' => 'Korean',
					'lv' => 'Latvian',
					'lt' => 'Lithuanian',
					'ms' => 'Malay',
					'no' => 'Norwegian',
					'fa' => 'Persian',
					'pl' => 'Polish',
					'pt' => 'Portuguese',
					'ro' => 'Romanian',
					'ru' => 'Russian',
					'sr' => 'Serbian',
					'sk' => 'Slovak',
					'sl' => 'Slovenian',
					'es' => 'Spanish',
					'sv' => 'Swedish',
					'th' => 'Thai',
					'tr' => 'Turkish',
					'uk' => 'Ukranian',
					'vi' => 'Vietnamese',
				),
				'tone' => array(
					'Formal' => __('Formal', 'ai-copilot-content-generator'),
					'Assertive' => __('Assertive', 'ai-copilot-content-generator'),
					'Authoritative' => __('Authoritative', 'ai-copilot-content-generator'),
					'Cheerful' => __('Cheerful', 'ai-copilot-content-generator'),
					'Confident' => __('Confident', 'ai-copilot-content-generator'),
					'Conversational' => __('Conversational', 'ai-copilot-content-generator'),
					'Factual' => __('Factual', 'ai-copilot-content-generator'),
					'Friendly' => __('Friendly', 'ai-copilot-content-generator'),
					'Humorous' => __('Humorous', 'ai-copilot-content-generator'),
					'Informal' => __('Informal', 'ai-copilot-content-generator'),
					'Inspirational' => __('Inspirational', 'ai-copilot-content-generator'),
					'Neutral' => __('Neutral', 'ai-copilot-content-generator'),
					'Nostalgic' => __('Nostalgic', 'ai-copilot-content-generator'),
					'Polite' => __('Polite', 'ai-copilot-content-generator'),
					'Professional' => __('Professional', 'ai-copilot-content-generator'),
					'Romantic' => __('Romantic', 'ai-copilot-content-generator'),
					'Sarcastic' => __('Sarcastic', 'ai-copilot-content-generator'),
					'Scientific' => __('Scientific', 'ai-copilot-content-generator'),
					'Sensitive' => __('Sensitive', 'ai-copilot-content-generator'),
					'Serious' => __('Serious', 'ai-copilot-content-generator'),
					'Sincere' => __('Sincere', 'ai-copilot-content-generator'),
					'Skeptical' => __('Skeptical', 'ai-copilot-content-generator'),
					'Suspenseful' => __('Suspenseful', 'ai-copilot-content-generator'),
					'Sympathetic' => __('Sympathetic', 'ai-copilot-content-generator'),
					'Curious' => __('Curious', 'ai-copilot-content-generator'),
					'Disappointed' => __('Disappointed', 'ai-copilot-content-generator'),
					'Encouraging' => __('Encouraging', 'ai-copilot-content-generator'),
					'Optimistic' => __('Optimistic', 'ai-copilot-content-generator'),
					'Surprised' => __('Surprised', 'ai-copilot-content-generator'),
					'Worried' => __('Worried', 'ai-copilot-content-generator'),
				),
				'img_model' => array(
					'dall-e-3' => 'Dall-E 3',
					'dall-e-2' => 'Dall-E 2',
					'dall-e-3-hd' => 'Dall-E 3-HD',
				),
				'gemini_img_model' => array(
					'gemini-3-pro-image-preview' => 'Gemini 3 Pro Preview',
					'gemini-2.5-flash-image' => 'Gemini 2.5 Flash',
					'imagen-4.0-generate-001' => 'Imagen 4',
					'imagen-4.0-ultra-generate-001' => 'Imagen 4 Ultra',
					'imagen-4.0-fast-generate-001' => 'Imagen 4 Fast',
				),
				'openrouter_img_model' => array(
					'openai/gpt-5-image' => 'OpenAI: GPT-5 Image',
				),
			),
			'plugin' => array(
				'date_format' => array(
					'd/m/Y' => '22/05/2024',
					'd.m.Y' => '22.05.2024',
					'Y-m-d' => '2024-05-22',
				),
			),
		);
		$vars = WaicDispatcher::applyFilters('getOptionsVariations', $vars);
		if (empty($gr) || 'api' == $gr) {
			$models = $this->get('models');
			if (!empty($models) && is_array($models)) {
				foreach ($models as $e => $data) {
					$vars['api']['model'][$e] = $data;
				}
			}
			$imgModels = $this->get('img_models');
			if (!empty($imgModels) && is_array($imgModels)) {
				foreach ($imgModels as $e => $data) {
					$vars['api'][$e . '_img_model'] = $data;
				}
			}
			$tokens = $this->get('tokens');
			if (!empty($tokens) && is_array($tokens)) {
				foreach ($tokens as $e => $data) {
					$vars['api']['tokens'] = array_merge($vars['api']['tokens'], $data);
				}
			}
		}
		
		if (!empty($gr)) {
			$vars = isset($vars[$gr]) ? $vars[$gr] : array();
			if (!empty($key)) {
				$vars = isset($vars[$key]) ? $vars[$key] : array();
				if (!empty($var)) {
					$vars = isset($vars[$var]) ? $vars[$var] : '';
				}
			}
		}
		
		return $vars;
	}
	public function getImagePresetDescriptions( $preset = '' ) {
		$desc = array(
			'Realistic' => 'The image should be highly realistic, capturing the details and nuances of the subject matter as if it were a photograph taken in the real world.',
			'4k' => 'The image should be in 4K resolution, ensuring exceptional clarity and detail, making every element of the picture crisp and vivid.',
			'High resolution' => 'The image should be of very high resolution, providing sharp and clear visuals that highlight even the smallest details.',
			'Trending in artstation' => 'The image should reflect the latest trends and styles popular on ArtStation, showcasing contemporary artistic techniques and high-quality digital art.',
			'Artstation three' => 'The image should adhere to the high standards of ArtStation, focusing on professional-grade artistry with intricate details and creative concepts.',
			'3D Render' => 'The image should be a detailed 3D render, presenting a lifelike and meticulously crafted three-dimensional scene or object.',
			'Digital painting' => 'The image should resemble a digital painting, with rich colors, textures, and brush strokes that give it an artistic and hand-painted feel.',
			'Amazing art' => 'The image should be visually stunning and captivating, showcasing amazing artistry that grabs the viewer\'s attention and evokes a sense of wonder.',
			'Expert' => 'The image should demonstrate expert-level skill and craftsmanship, with precise execution and professional quality that highlights the creator\'s proficiency.',
			'Stunning' => 'The image should be breathtakingly beautiful and impressive, with striking visuals that leave a lasting impact on the viewer.',
			'Creative' => 'The image should be highly creative and imaginative, pushing the boundaries of conventional art to present something unique and thought-provoking.',
			'Popular' => 'The image should reflect styles and themes that are currently popular and widely appreciated, resonating with a broad audience.',
			'Inspired' => 'The image should be inspired and original, drawing on various influences to create a fresh and innovative visual interpretation.',
			'Surreal' => 'The image should be surreal, featuring dream-like and fantastical elements that create an otherworldly and imaginative scene.',
			'Abstract' => 'The image should be abstract, focusing on shapes, colors, and forms that don\'t represent reality directly but evoke emotion and thought.',
			'Fantasy' => 'The image should be a fantasy scene, filled with magical and mythical elements that transport the viewer to a fantastical world.',
			'Pop art' => 'The image should be in the style of pop art, with bold colors, simple shapes, and a playful, vibrant aesthetic.',
			'Vector' => 'The image should be a vector graphic, characterized by clean lines, flat colors, and scalability without loss of quality.',
			'Landscape' => 'The image should depict a landscape, capturing the beauty and expanse of natural or urban settings with attention to detail and atmosphere.',
			'Portrait' => 'The image should be a portrait, focusing on capturing the personality and essence of the subject with detailed facial expressions and features.',
			'Iconic' => 'The image should be iconic, featuring elements that are instantly recognizable and memorable, leaving a strong visual impression.',
			'Neo expressionism' => 'The image should be in the style of neo-expressionism, with bold, raw, and emotional visuals that convey intense feelings and dynamic compositions.',
			'Landscape painting' => 'The image should resemble a landscape painting, with artistic interpretations of natural scenes, using brush strokes and color palettes typical of traditional painting.',
			'Digital Art' => 'The image should be a piece of digital art, showcasing the creative use of digital tools and techniques to produce a visually compelling and contemporary artwork.',
			'Abstract Art' => 'The image should be an abstract artwork, emphasizing non-representational forms, colors, and textures to evoke thoughts and emotions.',
			'Surrealistic Art' => 'The image should be surrealistic, combining realistic details with fantastical and dream-like elements to create a surreal and imaginative scene.',
			'Portrait Painting' => 'The image should resemble a portrait painting, capturing the likeness and character of the subject with artistic brushwork and detail.',
			'Neon' => 'The image should feature neon colors and elements, creating a vibrant and glowing effect that stands out with bright, luminescent tones.',
			'Neon light' => 'The image should emulate neon lighting, with glowing, electric hues that create a dynamic and eye-catching visual effect.',
		);
		return empty($preset) ? $desc : ( isset($desc[$preset]) ? $desc[$preset] : '' );
	}
	public function checkApiModels( $engine, $apiKey ) {
		$workspace = WaicFrame::_()->getModule('workspace');
		$apiOptions = array('engine' => $engine, $engine . '_api_key' => $apiKey);
		$aiProvider = $workspace->getModel('aiprovider')->getInstance($apiOptions);
		if (!$aiProvider) {
			return false;
		}
		if ($aiProvider->setApiOptions($apiOptions) === false) {
			return WaicFrame::_()->getLastError();
		}
		
		$results = $aiProvider->getModels();
		$this->save('models', $engine, isset($results['models']) ? $results['models'] : array());
		$this->save('img_models', $engine, isset($results['img_models']) ? $results['img_models'] : array());
		$this->save('tokens', $engine, isset($results['tokens']) ? $results['tokens'] : array());
		return $results;
	}
}
