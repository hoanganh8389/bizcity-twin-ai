<?php
/**
 * BizCity CF7 Tracking Frontend
 *
 * Injects client-side tracking pixel event listeners for CF7 forms
 * that have `page_tracking` configured via the BizCity Page Builder.
 *
 * On every public page request, reads `bizcity_cg_cf7_mappings` and
 * injects a `wpcf7mailsent` event listener for each form with a pixel
 * configured. The listener fires:
 *   - `fbq('track', event)` for FB Pixel
 *   - `gtag('event', event)` for GA4
 *   - `window.dataLayer.push(...)` for GTM
 *
 * Security: no secrets/tokens injected — only public pixel event names.
 *
 * [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — CF7 client-side tracking event injector
 *
 * @package BizCity\ChannelGateway\CF7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BizCity_CF7_Tracking_Frontend
 */
class BizCity_CF7_Tracking_Frontend {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — front-end only
		add_action( 'wp_footer', array( __CLASS__, 'inject_tracking_scripts' ), 99 );
	}

	/**
	 * Inject wpcf7mailsent listener + pixel fire calls into wp_footer.
	 * Runs only on public pages (is_admin() guard).
	 *
	 * @return void
	 */
	public static function inject_tracking_scripts() {
		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM Wave 4 — bail in admin
		if ( is_admin() ) {
			return;
		}

		$mappings = get_option( 'bizcity_cg_cf7_mappings', array() );
		if ( ! is_array( $mappings ) || empty( $mappings ) ) {
			return;
		}

		// Collect forms with page_tracking configured
		$trackable = array();
		foreach ( $mappings as $form_id => $cfg ) {
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$pt = isset( $cfg['page_tracking'] ) && is_array( $cfg['page_tracking'] )
				? $cfg['page_tracking']
				: array();

			$fb_pixel = sanitize_text_field( $pt['fb_pixel_id'] ?? '' );
			$ga_id    = sanitize_text_field( $pt['ga_id'] ?? '' );
			$gtm_id   = sanitize_text_field( $pt['gtm_id'] ?? '' );
			$event    = sanitize_key( $pt['tracking_event'] ?? 'lead_submit' );

			// Only inject if at least one pixel is configured
			if ( ! $fb_pixel && ! $ga_id && ! $gtm_id ) {
				continue;
			}

			// Validate pixel IDs — FB pixel: 10-20 digits; GA4: G-XXXXXXXX; GTM: GTM-XXXXXXX
			if ( $fb_pixel && ! preg_match( '/^[0-9]{10,20}$/', $fb_pixel ) ) {
				$fb_pixel = '';
			}
			if ( $ga_id && ! preg_match( '/^G-[A-Z0-9]+$/i', $ga_id ) ) {
				$ga_id = '';
			}
			if ( $gtm_id && ! preg_match( '/^GTM-[A-Z0-9]{4,8}$/i', $gtm_id ) ) {
				$gtm_id = '';
			}

			if ( ! $fb_pixel && ! $ga_id && ! $gtm_id ) {
				continue;
			}

			$trackable[] = array(
				'form_id'   => (int) $form_id,
				'fb_pixel'  => $fb_pixel,
				'ga_id'     => $ga_id,
				'gtm_id'    => $gtm_id,
				'event'     => $event ?: 'lead_submit',
			);
		}

		if ( empty( $trackable ) ) {
			return;
		}

		// Build JS config — safe to expose (no secrets)
		$js_config = wp_json_encode( $trackable );
		if ( ! $js_config ) {
			return;
		}

		?>
<script id="bizcity-cf7-tracking">
/* BizCity CF7 Tracking — PHASE-PB-LEADFORM Wave 4 */
(function(){
	var forms=<?php echo $js_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded by wp_json_encode ?>;
	if(!forms||!forms.length){return;}
	var byId={};
	forms.forEach(function(f){byId[f.form_id]=f;});

	document.addEventListener('wpcf7mailsent',function(e){
		var id=e&&e.detail&&e.detail.contactFormId?parseInt(e.detail.contactFormId,10):0;
		if(!id||!byId[id]){return;}
		var cfg=byId[id];

		/* FB Pixel */
		if(cfg.fb_pixel&&typeof window.fbq==='function'){
			window.fbq('track','Lead',{form_id:id,event:cfg.event});
		}

		/* GA4 */
		if(cfg.ga_id&&typeof window.gtag==='function'){
			window.gtag('event',cfg.event,{form_id:id,send_to:cfg.ga_id});
		}

		/* GTM dataLayer */
		if(cfg.gtm_id){
			window.dataLayer=window.dataLayer||[];
			window.dataLayer.push({
				event:'bizcity_cf7_submit',
				cf7_event:cfg.event,
				cf7_form_id:id
			});
		}
	},false);
}());
</script>
		<?php
	}
}
