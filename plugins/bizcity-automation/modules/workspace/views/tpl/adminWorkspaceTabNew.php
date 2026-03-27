<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$module = $this->getModule();
$cntFeatures = 0;
$isApiKey = !( empty( $props['api_key'] ) && empty( $props['deep_seek_api_key'] ) && empty( $props['gemini_api_key'] ) );
if ( !$isApiKey ) {
	?>
	<div class="wbw-alert-block wbw-alert-aikey">
		<div class="wbw-alert-title"><span>!</span> <?php echo esc_html_e('Connect your AI Provider API Key', 'ai-copilot-content-generator'); ?></div>

		<div class="wbw-alert-info"><?php esc_html_e('Our plugin supports OpenAI, Gemini, and DeepSeek API. To activate AI features, please enter your API key in the settings.', 'ai-copilot-content-generator'); ?></div>
		<div class="wbw-aikey-form">
			<a href="<?php echo esc_url($module->getFeatureUrl('settings')); ?>"><button class="wbp-button wbw-button-form wbw-button-main"><?php echo esc_html_e('API Settings', 'ai-copilot-content-generator'); ?></button></a>
		</div>
	</div>
<?php } ?>
<section class="wbw-body-workspace">
	<ul class="wbw-ws-group">
	<?php foreach ($props['features'] as $key => $block) { ?>
		<?php 
			if (!empty($block['hidden'])) {
				continue;
			}
			if (!empty($block['full'])) {
				$needPlh = $cntFeatures % 3;
				if ($cntFeatures % 2 != 0) {
					echo '<li class="wbw-ws-block wbw-ws-plh"></li>';
					if (1 == $needPlh) {
						echo '<li class="wbw-ws-block wbw-ws-plh"></li>';
					}
				} else {
					if (0 < $needPlh) {
						echo '<li class="wbw-ws-block wbw-ws-plh"></li>';
					}
					if (1 == $needPlh) {
						echo '<li class="wbw-ws-block wbw-ws-plh"></li>';
					}
				}
				$cntFeatures = 0;
			} else {
				$cntFeatures++;
			}
			
		?>
		<li class="wbw-ws-block<?php echo empty($block['class']) ? '' : ' ' . esc_attr($block['class']); ?>">
			<a href="<?php echo esc_url(empty($block['link']) ? $module->getFeatureUrl($key) : $block['link']); ?>" class="wbw-feature-link"<?php echo empty($block['target']) ? '' : ' target="' . esc_attr($block['target']) . '"'; ?>>
				<div class="wbw-ws-block-in">
					<img src="<?php echo esc_url($props['img_path'] . '/' . $key . '.png'); ?>" alt="?">
					<div class="wbw-ws-block-text">
						<div class="wbw-ws-title"><?php echo esc_html($block['title']); ?></div>
						<div class="wbw-ws-desc"><?php echo esc_html($block['desc']); ?></div>
					</div>
				</div>
			</a>
		</li>
	<?php } ?>
	</ul>
	<div class="wbw-clear"></div>
</section>
