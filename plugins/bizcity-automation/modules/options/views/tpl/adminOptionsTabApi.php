<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$options = WaicUtils::getArrayValue($props['options'], 'api', array(), 2);
$notShow = WaicUtils::getArrayValue($props, 'not_show', array('vision' => 1), 2);
$variations = WaicUtils::getArrayValue($props['variations'], 'api', array(), 2);
$defaults = WaicUtils::getArrayValue($props['defaults'], 'api', array(), 2);
$readOnly = WaicUtils::getArrayValue($props, 'read_only') == 1;
$tokens = WaicUtils::getArrayValue($variations, 'tokens', array(), 2);
$curModels = array();
foreach ($variations['engines'] as $m => $v) {
	$var = ( 'open-ai' == $m ? 'model' : ( 'deep-seek' == $m ? 'deep_seek_model' : $m . '_model' ) );
	$curModels[$m] = WaicUtils::getArrayValue($options, $var, $defaults[$var]);
	
}
/*$curModel = WaicUtils::getArrayValue($options, 'model', $defaults['model']);
$curDeepSeekModel = WaicUtils::getArrayValue($options, 'deep_seek_model', $defaults['deep_seek_model']);
$curGeminiModel = WaicUtils::getArrayValue($options, 'gemini_model', $defaults['gemini_model']);*/
$curEngine = WaicUtils::getArrayValue($options, 'engine', $defaults['engine']);
$curImageEngine = WaicUtils::getArrayValue($options, 'image_engine', $defaults['image_engine']);

?>
<section class="wbw-body-options-api">
	<div class="wbw-group-title">
		<?php esc_html_e('API keys', 'ai-copilot-content-generator'); ?>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Open AI API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your OpenAI API key to enable content generation, text analysis, and automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-create-an-account-and-obtain-your-open-ai-api-key/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
			<?php 
				WaicHtml::text('api[api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your Open AI Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>

	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Gemini API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your Google AI (Gemini) API key to enable content generation, summarization, and automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-obtain-a-google-ai-gemini-api-key/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::text('api[gemini_api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'gemini_api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your Gemini Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>

	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('DeepSeek API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your DeepSeek API key to enable fast, cost-efficient AI text generation and automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-get-your-deepseek-api-key/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::text('api[deep_seek_api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'deep_seek_api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your DeepSeek Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Claude API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your Anthropic (Claude AI) API key to enable advanced content generation, reasoning, and automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-get-an-anthropic-claude-ai-api-key-complete-guide/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::text('api[claude_api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'claude_api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your Claude Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Perplexity API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your Perplexity AI API key to enable real-time, search-based insights and content automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-get-a-perplexity-ai-api-key-for-aiwu-plugin/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::text('api[perplexity_api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'perplexity_api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your Perplexity Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('OpenRouter API key', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Connect your OpenRouter API key to enable real-time, search-based insights and content automation.', 'ai-copilot-content-generator') . '<br>' . __('Don\'t have one?', 'ai-copilot-content-generator') . ' <a href="https://bizgpt.vn/knowledge-base/how-to-get-a-openrouter-ai-api-key-for-aiwu-plugin/" target="_blank">' . __('Explore this guide', 'ai-copilot-content-generator') . '</a> ' . __('to create an account and obtain your API key.', 'ai-copilot-content-generator')); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::text('api[openrouter_api_key]', array(
					'value' => WaicUtils::getArrayValue($options, 'openrouter_api_key', ''),
					'attrs' => 'aria-hidden="true" autocomplete="off" class="waic-fake-password" placeholder="' . esc_attr__('Enter your OpenRouter Api key', 'ai-copilot-content-generator') . '"',
				));
				?>
			</div>
		</div>
	</div>

	<div class="wbw-group-title">
		<?php esc_html_e('Text generation', 'ai-copilot-content-generator'); ?>
	</div>

	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('AI Provider', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select which AI model to use', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::selectbox('api[engine]', array(
					'options' => $variations['engines'],
					'value' => $curEngine,
					'attrs' => 'id="waicEngineSelect"',
				));
				?>
			</div>
		</div>
	</div>

	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Model', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10 waic-api-models-block" data-tokens="<?php echo esc_attr(htmlentities(WaicUtils::jsonEncode($tokens), ENT_COMPAT)); ?>">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select the model you wish to use for content generation. Different models have varying capabilities, such as language understanding and creativity.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field<?php echo ( 'open-ai' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="open-ai">
			<?php 
				WaicHtml::selectbox('api[model]', array(
					'options' => $variations['model']['open-ai'],
					'value' => $curModels['open-ai'],
					'attrs' => 'id="waicApiModel" class="waic-api-models-select"',
				));
				?>
			</div>
			<div class="wbw-settings-field<?php echo ( 'deep-seek' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="deep-seek">
				<?php
				WaicHtml::selectbox('api[deep_seek_model]', array(
					'options' => $variations['model']['deep-seek'],
					'value' => $curModels['deep-seek'],
					'attrs' => 'id="waicApiDeepSeekModel" class="waic-api-models-select"',
				));
				?>
			</div>
			<div class="wbw-settings-field<?php echo ( 'gemini' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="gemini">
				<?php
				WaicHtml::selectbox('api[gemini_model]', array(
					'options' => $variations['model']['gemini'],
					'value' => $curModels['gemini'],
					'attrs' => 'id="waicApiGeminiModel" class="waic-api-models-select"',
				));
				?>
			</div>
			<div class="wbw-settings-field<?php echo ( 'claude' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="claude">
				<?php
				WaicHtml::selectbox('api[claude_model]', array(
					'options' => $variations['model']['claude'],
					'value' => $curModels['claude'],
					'attrs' => 'id="waicApiClaudeModel" class="waic-api-models-select"',
				));
				?>
			</div>
			<div class="wbw-settings-field<?php echo ( 'perplexity' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="perplexity">
				<?php
				WaicHtml::selectbox('api[perplexity_model]', array(
					'options' => $variations['model']['perplexity'],
					'value' => $curModels['perplexity'],
					'attrs' => 'id="waicApiPerplexityModel" class="waic-api-models-select"',
				));
				?>
			</div>
			<div class="wbw-settings-field<?php echo ( 'openrouter' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="openrouter">
				<?php
				WaicHtml::selectbox('api[openrouter_model]', array(
					'options' => $variations['model']['openrouter'],
					'value' => $curModels['openrouter'],
					'attrs' => 'id="waicApiOpenrouterModel" class="waic-api-models-select"',
				));
				?>
				<button class="wbw-button wbw-button-small m-0 waic-api-models-check"><?php esc_html_e('Check models', 'ai-copilot-content-generator'); ?></button>
			</div>
		</div>
	</div>
<?php 
$embeddings = WaicDispatcher::applyFilters('getEmbeddingsList', array());
if (!empty($embeddings)) {
	$embeddings = array(0 => __('Select', 'ai-copilot-content-generator')) + $embeddings;
?>
	<div class="wbw-settings-form row wbw-mb-ver10<?php echo ( 'open-ai' == $curEngine ? '' : ' wbw-hidden'); ?>" data-parent-select="api[engine]" data-select-value="open-ai">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Embedding', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Select Embedding', 'ai-copilot-content-generator') . '<br><br>' . __('Defines the maximum allowed file size for image uploads. If an image exceeds this limit, it will be rejected. The default recommendation is 5MB to balance quality and performance. Larger images may generate extensive context, increasing token usage.', 'ai-copilot-content-generator')); ?>">
			<?php
				WaicHtml::selectbox('api[embedding]', array(
					'options' => $embeddings,
					'value' => WaicUtils::getArrayValue($options, 'embedding'),
				));
			?>
		</div>
	</div>
<?php } ?>
<?php if (empty($notShow['language'])) { ?>
	<div class="wbw-settings-form row">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Language', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Choose the language in which you want your content generated.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::selectbox('api[language]', array(
					'options' => $variations['language'],
					'value' => WaicUtils::getArrayValue($options, 'language', $defaults['language']),
				));
			?>
			</div>
		</div>
	</div>
<?php } ?>
	<div class="wbw-settings-form row wbw-mb-ver10">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Tone of voice', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Specify the desired tone of voice for the generated content.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::selectbox('api[tone]', array(
					'options' => $variations['tone'],
					'value' => WaicUtils::getArrayValue($options, 'tone', $defaults['tone']),
				));
				?>
			</div>
		</div>
	</div>
<?php if (empty($notShow['vision'])) { ?>
	<div class="wbw-settings-form row wbw-mb-ver10<?php echo ( 'deep-seek' == $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="open-ai gemini claude perplexity">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Vision', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php echo esc_attr(__('Enables image processing capabilities. Allows users to attach images to their messages. The AI will analyze the images and respond based on their context. If enabled, it is recommended to increase the Context Max Length value, as image analysis can add a significant amount of data. For example, a 1MB image may generate approximately 80,000–120,000 characters of context.', 'ai-copilot-content-generator') . '<br><br>' . __('Defines the maximum allowed file size for image uploads. If an image exceeds this limit, it will be rejected. The default recommendation is 5MB to balance quality and performance. Larger images may generate extensive context, increasing token usage.', 'ai-copilot-content-generator')); ?>">
			<?php
				WaicHtml::checkbox('api[vision]', array(
					'checked' => WaicUtils::getArrayValue($options, 'vision', 0, 1),
				));
			?>
			<div class="wbw-settings-label"><?php esc_html_e('Max File Size', 'ai-copilot-content-generator'); ?></div>
			<?php
				WaicHtml::number('api[max_file_size]', array(
					'value' => WaicUtils::getArrayValue($options, 'max_file_size', 5, 1),
				));
			?>
			<div class="wbw-settings-label">MB</div>
		</div>
	</div>
<?php } ?>
<?php if (empty($notShow['common_language'])) { ?>
	<div class="wbw-settings-form row wbw-mb-ver10">
		<div class="wbw-settings-label col-2"></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Enable this option if you want the article body to be written in simple, common language that is easy to understand. This setting ensures that the content is accessible to a broad audience by avoiding technical jargon and complex terms.', 'ai-copilot-content-generator'); ?>">
			<?php
				WaicHtml::checkbox('api[common_language]', array(
					'checked' => WaicUtils::getArrayValue($options, 'common_language', 0, 1),
				));
			?>
			<div class="wbw-settings-label"><?php esc_html_e('Use Only Common Language', 'ai-copilot-content-generator'); ?></div>
		</div>
	</div>
<?php } ?>
<?php if (empty($notShow['human_style'])) { ?>
	<div class="wbw-settings-form row wbw-mb-ver10">
		<div class="wbw-settings-label col-2"></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Enable this option if you want the article body to be written in a natural, human-like style. This setting ensures that the generated content mimics the tone and flow of human writing, making it more engaging and relatable for readers.', 'ai-copilot-content-generator'); ?>">
			<?php
				WaicHtml::checkbox('api[human_style]', array(
					'checked' => WaicUtils::getArrayValue($options, 'human_style', 0, 1),
				));
			?>
			<div class="wbw-settings-label"><?php esc_html_e('Use Only Human-Like Language Style', 'ai-copilot-content-generator'); ?></div>
		</div>
	</div>
<?php } ?>
	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Temperature', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Adjust the creativity of the generated content. A higher temperature results in more creative outputs, while a lower temperature produces more predictable text. Values above 1 are not recommended.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::slider('api[temperature]', array(
					'value' => WaicUtils::getArrayValue($options, 'temperature', $defaults['temperature']),
					'min' => 0,
					'max' => 2,
					'step' => '0.01',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Max tokens', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Set the maximum number of tokens (words and characters) for the generated content. Higher numbers allow for longer outputs.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				//$realCurModel = 'open-ai' == $curEngine  ? $curModel : ('deep-seek' == $curEngine? $curDeepSeekModel : $curGeminiModel);
				$realCurModel = WaicUtils::getArrayValue($curModels, $curEngine);
				WaicHtml::slider('api[tokens]', array(
					'value' => WaicUtils::getArrayValue($options, 'tokens', $defaults['tokens']),
					'min' => 1,
					'max' => WaicUtils::getArrayValue($tokens, $realCurModel, 4096, 1),
					'step' => '1',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
					'id' => 'waicApiTokens',
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Requests per minute', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Set the maximum requests per minute.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::slider('api[pre_minute]', array(
					'value' => WaicUtils::getArrayValue($options, 'pre_minute', $defaults['pre_minute']),
					'min' => 1,
					'max' => 20,
					'step' => '1',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
	<?php if (empty($notShow['img_model'])) { ?>
		<div class="wbw-group-title">
			<?php esc_html_e('Image generation', 'ai-copilot-content-generator'); ?>
		</div>

		<div class="wbw-settings-form row">
			<div class="wbw-settings-label col-2"><?php esc_html_e('AI Provider', 'ai-copilot-content-generator'); ?></div>
			<div class="wbw-settings-fields col-10">
				<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select which AI image model to use', 'ai-copilot-content-generator'); ?>">
				<div class="wbw-settings-field">
					<?php
					WaicHtml::selectbox('api[image_engine]', array(
						'options' => array('open-ai' => 'Open AI', 'gemini' => 'Gemini', 'openrouter' => 'Open Router'),
						'value' => $curImageEngine,
						'attrs' => 'id="waicImageEngineSelect"',
					));
					?>
				</div>
			</div>
		</div>

		<div class="wbw-settings-form row<?php echo ( 'open-ai' != $curImageEngine ? ' wbw-hidden' : '' ); ?>" id="waicImageModel" data-parent-select="api[image_engine]" data-select-value="open-ai">
			<div class="wbw-settings-label col-2"><?php esc_html_e('Image model', 'ai-copilot-content-generator'); ?></div>
			<div class="wbw-settings-fields col-10">
				<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select the model you wish to use for image generation.', 'ai-copilot-content-generator'); ?>">
				<div class="wbw-settings-field">
					<?php
					WaicHtml::selectbox('api[img_model]', array(
						'options' => $variations['img_model'],
						'value' => WaicUtils::getArrayValue($options, 'img_model', $defaults['img_model']),
					));
					?>
				</div>
			</div>
		</div>

		<div class="wbw-settings-form row<?php echo ( 'gemini' != $curImageEngine ? ' wbw-hidden' : '' ); ?>" id="waicGeminiImageModel" data-parent-select="api[image_engine]" data-select-value="gemini">
			<div class="wbw-settings-label col-2"><?php esc_html_e('Image model', 'ai-copilot-content-generator'); ?></div>
			<div class="wbw-settings-fields col-10">
				<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select the model you wish to use for image generation.', 'ai-copilot-content-generator'); ?>">
				<div class="wbw-settings-field">
					<?php
					WaicHtml::selectbox('api[gemini_img_model]', array(
						'options' => $variations['gemini_img_model'],
						'value' => WaicUtils::getArrayValue($options, 'gemini_img_model', $defaults['gemini_img_model']),
					));
					?>
				</div>
			</div>
		</div>
		<div class="wbw-settings-form row<?php echo ( 'openrouter' != $curImageEngine ? ' wbw-hidden' : '' ); ?>" id="waicOpenRouterImageModel" data-parent-select="api[image_engine]" data-select-value="openrouter">
			<div class="wbw-settings-label col-2"><?php esc_html_e('Image model', 'ai-copilot-content-generator'); ?></div>
			<div class="wbw-settings-fields col-10">
				<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Select the model you wish to use for image generation.', 'ai-copilot-content-generator'); ?>">
				<div class="wbw-settings-field">
					<?php
					WaicHtml::selectbox('api[openrouter_img_model]', array(
						'options' => $variations['openrouter_img_model'],
						'value' => WaicUtils::getArrayValue($options, 'openrouter_img_model', $defaults['openrouter_img_model']),
					));
					?>
				</div>
			</div>
		</div>
	<?php } ?>

	<div class="wbw-group-title">
		<?php esc_html_e('Advanced settings', 'ai-copilot-content-generator'); ?>
	</div>

	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Top P', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Control the diversity of the generated content by setting the probability threshold. Higher values allow more variation in the responses.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::slider('api[top_p]', array(
					'value' => WaicUtils::getArrayValue($options, 'top_p', $defaults['top_p']),
					'min' => 0,
					'max' => 1,
					'step' => '0.01',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row wbw-nomargin-ver<?php echo ( 'gemini' != $curEngine ? ' wbw-hidden' : '' ); ?>" data-parent-select="api[engine]" data-select-value="gemini">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Top K', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Changes how the model selects tokens for output.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
				<?php
				WaicHtml::slider('api[top_k]', array(
					'value' => WaicUtils::getArrayValue($options, 'top_k', $defaults['top_k']),
					'min' => 1,
					'max' => 10,
					'step' => '1',
					'hide-min-max' => 10,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Frequency penalty', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Adjust this to decrease or increase the likelihood of repeating the same information in the output. Negative values make repetition more likely.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::slider('api[frequency]', array(
					'value' => WaicUtils::getArrayValue($options, 'frequency', $defaults['frequency']),
					'min' => -2,
					'max' => 2,
					'step' => '0.01',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
	<div class="wbw-settings-form row wbw-nomargin-ver">
		<div class="wbw-settings-label col-2"><?php esc_html_e('Presence penalty', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-settings-fields col-10">
			<img src="<?php echo esc_url(WAIC_IMG_PATH . '/info.png'); ?>" class="wbw-tooltip" title="<?php esc_html_e('Modify this to discourage or encourage the presence of new.', 'ai-copilot-content-generator'); ?>">
			<div class="wbw-settings-field">
			<?php
				WaicHtml::slider('api[presence]', array(
					'value' => WaicUtils::getArrayValue($options, 'presence', $defaults['presence']),
					'min' => -2,
					'max' => 2,
					'step' => '0.01',
					'hide-min-max' => 1,
					'class' => ( $readOnly ? 'disabled' : '' ),
				));
				?>
			</div>
		</div>
	</div>
</section>