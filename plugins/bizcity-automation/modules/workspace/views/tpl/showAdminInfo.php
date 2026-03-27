<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;
$addClass = empty($props['add_class']) ? '' : ' ' . $props['add_class'];
$disSlug = empty($props['dis_slug']) ? '' : $props['dis_slug'];
?>
<div class="error notice is-dismissible<?php echo esc_attr($addClass); ?>" data-slug="<?php echo esc_attr($disSlug); ?>">
	<p><?php WaicHtml::echoEscapedHtml($this->props['message']); ?></p>
</div>