<?php
/**
 * BizCity Lead Source Tracker
 *
 * Captures UTM params, referrer, browser/device info and classifies the
 * traffic channel (zalo, facebook, google, email, direct, ...) for every
 * form submission — works with CF7, Flatsome native forms, and BZPB forms.
 *
 * Two-part system:
 *   1. JS snippet (see ::enqueue_tracking_script) — writes UTM + referrer
 *      to sessionStorage key 'bz_src' on every page view. On CF7/form
 *      submit, copies bz_src into a hidden input '_bz_src'.
 *   2. PHP (see ::capture_from_request) — reads $_POST['_bz_src'] or
 *      HTTP_REFERER fallback, returns a structured source_meta array
 *      ready to be JSON-encoded into bizcity_crm_submissions.source_meta_json.
 *
 * source_meta schema (used in bizcity_crm_submissions.source_meta_json):
 * {
 *   channel:      string   // zalo | facebook | google | email | direct | referral | other
 *   utm_source:   string
 *   utm_medium:   string
 *   utm_campaign: string
 *   utm_content:  string
 *   utm_term:     string
 *   referrer:     string   // sanitized referrer domain
 *   landing_url:  string   // page URL where user landed
 *   form_url:     string   // page URL where form was submitted
 *   device:       string   // desktop | mobile | tablet
 *   browser:      string   // chrome | firefox | safari | edge | other
 *   os:           string   // windows | mac | android | ios | linux | other
 *   ip_hash:      string   // SHA-1 of IP (no PII stored directly — OWASP A02)
 *   user_agent:   string   // truncated UA string (255 chars max)
 *   form_id:      int
 *   form_title:   string
 * }
 *
 * [2026-07-02 Johnny Chu] PHASE-0.46 M1 — new cross-cutting source tracker
 *
 * @package BizCity\ChannelGateway
 * @since   2026-07-02
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Lead_Source_Tracker' ) ) {
	return;
}

class BizCity_Lead_Source_Tracker {

	/**
	 * JS sessionStorage key for source data.
	 */
	const SESSION_KEY = 'bz_src';

	/**
	 * POST field name injected by the JS snippet into form submissions.
	 */
	const POST_FIELD = '_bz_src';

	/**
	 * Channel constants — keep in sync with SourceTypeBadge.jsx on FE.
	 */
	const CH_ZALO     = 'zalo';
	const CH_FACEBOOK = 'facebook';
	const CH_GOOGLE   = 'google';
	const CH_EMAIL    = 'email';
	const CH_DIRECT   = 'direct';
	const CH_REFERRAL = 'referral';
	const CH_OTHER    = 'other';

	// ── Hooks ──────────────────────────────────────────────────────────

	/**
	 * Register frontend hooks.
	 * Called from channel-gateway bootstrap (unconditional — runs on every page).
	 */
	public static function init() {
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — inject tracking JS on all public pages
		add_action( 'wp_footer', array( __CLASS__, 'inject_tracking_script' ), 5 );
	}

	// ── Frontend script ────────────────────────────────────────────────

	/**
	 * Inject inline JS that:
	 *  1. Reads UTM params + document.referrer on first page load → stores in sessionStorage.
	 *  2. On every CF7 form submit → appends hidden input _bz_src with the stored JSON.
	 *  3. On every native form with class bzpb-contact-form → same injection.
	 *
	 * The script is tiny (~600 bytes minified) and deferred; does NOT load any external files.
	 */
	public static function inject_tracking_script() {
		if ( is_admin() ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
<script id="bz-src-tracker">
(function(){
var SK='<?php echo esc_js( self::SESSION_KEY ); ?>';
function getP(k){try{var u=new URL(window.location.href);return u.searchParams.get(k)||'';}catch(e){return '';}}
function getReferrer(){try{var r=document.referrer;return r?new URL(r).origin:'';}catch(e){return document.referrer||'';}}
var existing;try{existing=JSON.parse(sessionStorage.getItem(SK)||'null');}catch(e){existing=null;}
if(!existing){
  var src={
    utm_source:getP('utm_source'),utm_medium:getP('utm_medium'),
    utm_campaign:getP('utm_campaign'),utm_content:getP('utm_content'),
    utm_term:getP('utm_term'),
    referrer:getReferrer(),
    landing_url:window.location.href.split('?')[0].substring(0,200)
  };
  try{sessionStorage.setItem(SK,JSON.stringify(src));}catch(e){}
}
function injectField(form){
  if(!form||form.querySelector('input[name="<?php echo esc_js( self::POST_FIELD ); ?>"]'))return;
  try{
    var d=sessionStorage.getItem(SK)||'';
    var h=document.createElement('input');
    h.type='hidden';h.name='<?php echo esc_js( self::POST_FIELD ); ?>';h.value=d;
    form.appendChild(h);
  }catch(e){}
}
// CF7 forms
document.querySelectorAll('.wpcf7-form').forEach(injectField);
// BZPB / Flatsome native forms (any form with data-bz-track attr or class)
document.querySelectorAll('form[data-bz-track],form.bzpb-contact-form,form.flatsome-form').forEach(injectField);
// MutationObserver: handle dynamically rendered forms
if(window.MutationObserver){
  new MutationObserver(function(ml){
    ml.forEach(function(m){
      m.addedNodes.forEach(function(n){
        if(n.nodeType!==1)return;
        var forms=n.matches&&n.matches('form')?[n]:Array.from(n.querySelectorAll('form')||[]);
        forms.forEach(injectField);
      });
    });
  }).observe(document.body,{childList:true,subtree:true});
}
})();
</script>
		<?php
		// phpcs:enable
	}

	// ── Server-side capture ────────────────────────────────────────────

	/**
	 * Build a complete source_meta array from the current HTTP request.
	 *
	 * Reads _bz_src from POST (injected by JS) with HTTP_REFERER + REQUEST_URI fallback.
	 * Never throws — always returns an array (may be partially empty on error).
	 *
	 * @param array $extra  Additional fields to merge (form_id, form_title, etc.).
	 * @return array
	 */
	public static function capture_from_request( array $extra = array() ) {
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — parse source from POST field injected by JS
		$js_src = array();
		if ( ! empty( $_POST[ self::POST_FIELD ] ) ) {
			$raw = stripslashes( (string) $_POST[ self::POST_FIELD ] );
			$raw = wp_check_invalid_utf8( $raw );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$js_src = $decoded;
			}
		}

		$utm_source   = sanitize_text_field( $js_src['utm_source']   ?? '' );
		$utm_medium   = sanitize_text_field( $js_src['utm_medium']   ?? '' );
		$utm_campaign = sanitize_text_field( $js_src['utm_campaign'] ?? '' );
		$utm_content  = sanitize_text_field( $js_src['utm_content']  ?? '' );
		$utm_term     = sanitize_text_field( $js_src['utm_term']     ?? '' );
		$referrer     = sanitize_text_field( $js_src['referrer']     ?? '' );
		$landing_url  = esc_url_raw( $js_src['landing_url'] ?? '' );

		// Fallback: HTTP_REFERER if JS didn't inject referrer
		if ( '' === $referrer && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer = self::sanitize_referrer_domain( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		// Form submission URL (current request)
		$form_url = '';
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$form_url = esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			$form_url = substr( $form_url, 0, 300 );
		}

		// User-Agent → device + browser + OS
		$ua_string = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
			: '';
		$ua_parsed = self::parse_user_agent( $ua_string );

		// IP → hashed (OWASP A02: no PII in DB)
		$ip     = self::get_client_ip();
		$ip_hash = $ip ? substr( sha1( $ip ), 0, 12 ) : '';

		// Classify channel
		$channel = self::classify_channel( $utm_source, $utm_medium, $referrer );

		$meta = array(
			'channel'      => $channel,
			'utm_source'   => $utm_source,
			'utm_medium'   => $utm_medium,
			'utm_campaign' => $utm_campaign,
			'utm_content'  => $utm_content,
			'utm_term'     => $utm_term,
			'referrer'     => $referrer,
			'landing_url'  => $landing_url,
			'form_url'     => $form_url,
			'device'       => $ua_parsed['device'],
			'browser'      => $ua_parsed['browser'],
			'os'           => $ua_parsed['os'],
			'ip_hash'      => $ip_hash,
			'user_agent'   => $ua_string,
		);

		// Merge caller-provided extras (form_id, form_title, etc.)
		foreach ( $extra as $k => $v ) {
			$meta[ sanitize_key( $k ) ] = $v;
		}

		return $meta;
	}

	// ── Channel classification ─────────────────────────────────────────

	/**
	 * Classify traffic channel from utm_source, utm_medium, referrer.
	 *
	 * Rules (priority order):
	 *  1. UTM source/medium keyword match.
	 *  2. Referrer domain match.
	 *  3. Both empty → 'direct'.
	 *
	 * @param string $utm_source
	 * @param string $utm_medium
	 * @param string $referrer
	 * @return string channel constant
	 */
	public static function classify_channel( $utm_source, $utm_medium, $referrer ) {
		// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — channel classification rules
		$src = strtolower( (string) $utm_source );
		$med = strtolower( (string) $utm_medium );
		$ref = strtolower( (string) $referrer );

		// Zalo (Zalo mini-app, ZNS reply, Zalo ads, Zalo QR)
		if ( false !== strpos( $src, 'zalo' ) || false !== strpos( $ref, 'zalo' ) || false !== strpos( $ref, 'zaloapp' ) ) {
			return self::CH_ZALO;
		}

		// Facebook / Instagram
		if ( false !== strpos( $src, 'facebook' )
			|| false !== strpos( $src, 'fb' )
			|| false !== strpos( $src, 'instagram' )
			|| false !== strpos( $ref, 'facebook.com' )
			|| false !== strpos( $ref, 'fb.com' )
			|| false !== strpos( $ref, 'l.facebook.com' )
			|| false !== strpos( $ref, 'instagram.com' )
			|| 'cpc' === $med && ( false !== strpos( $src, 'fb' ) || false !== strpos( $src, 'facebook' ) ) ) {
			return self::CH_FACEBOOK;
		}

		// Google (Organic + Ads)
		if ( false !== strpos( $src, 'google' )
			|| false !== strpos( $ref, 'google.com' )
			|| false !== strpos( $ref, 'google.com.vn' )
			|| 'cpc' === $med && false !== strpos( $src, 'google' ) ) {
			return self::CH_GOOGLE;
		}

		// Email / Newsletter
		if ( in_array( $med, array( 'email', 'newsletter', 'mail' ), true )
			|| false !== strpos( $src, 'email' )
			|| false !== strpos( $src, 'newsletter' ) ) {
			return self::CH_EMAIL;
		}

		// Direct (no referrer, no UTM)
		if ( '' === $utm_source && '' === $referrer ) {
			return self::CH_DIRECT;
		}

		// Referral (has referrer but doesn't match above)
		if ( '' !== $referrer ) {
			return self::CH_REFERRAL;
		}

		return self::CH_OTHER;
	}

	// ── User-Agent parser ──────────────────────────────────────────────

	/**
	 * Lightweight UA parser: device, browser, OS.
	 * Does NOT use external libraries — keeps it simple for PHP 7.4.
	 *
	 * @param  string $ua
	 * @return array {device: string, browser: string, os: string}
	 */
	public static function parse_user_agent( $ua ) {
		$ua_lower = strtolower( (string) $ua );

		// Device
		$device = 'desktop';
		if ( false !== strpos( $ua_lower, 'mobile' ) || false !== strpos( $ua_lower, 'android' ) && false !== strpos( $ua_lower, 'mobile' ) ) {
			$device = 'mobile';
		} elseif ( false !== strpos( $ua_lower, 'tablet' ) || false !== strpos( $ua_lower, 'ipad' ) ) {
			$device = 'tablet';
		} elseif ( false !== strpos( $ua_lower, 'android' ) ) {
			$device = 'mobile'; // Android without 'mobile' = tablet, but most are phones
		}

		// OS
		$os = 'other';
		if ( false !== strpos( $ua_lower, 'android' ) ) {
			$os = 'android';
		} elseif ( false !== strpos( $ua_lower, 'iphone' ) || false !== strpos( $ua_lower, 'ipad' ) || false !== strpos( $ua_lower, 'ipod' ) ) {
			$os = 'ios';
		} elseif ( false !== strpos( $ua_lower, 'windows' ) ) {
			$os = 'windows';
		} elseif ( false !== strpos( $ua_lower, 'mac os' ) || false !== strpos( $ua_lower, 'macintosh' ) ) {
			$os = 'mac';
		} elseif ( false !== strpos( $ua_lower, 'linux' ) ) {
			$os = 'linux';
		}

		// Browser (order matters — Edge/OPR must be before Chrome/Safari)
		$browser = 'other';
		if ( false !== strpos( $ua_lower, 'edg/' ) || false !== strpos( $ua_lower, 'edge/' ) ) {
			$browser = 'edge';
		} elseif ( false !== strpos( $ua_lower, 'opr/' ) || false !== strpos( $ua_lower, 'opera' ) ) {
			$browser = 'opera';
		} elseif ( false !== strpos( $ua_lower, 'samsungbrowser' ) ) {
			$browser = 'samsung';
		} elseif ( false !== strpos( $ua_lower, 'zalo' ) ) {
			$browser = 'zalo'; // Zalo in-app browser
		} elseif ( false !== strpos( $ua_lower, 'fbav' ) || false !== strpos( $ua_lower, 'fban' ) ) {
			$browser = 'facebook_app'; // Facebook in-app browser
		} elseif ( false !== strpos( $ua_lower, 'chrome' ) ) {
			$browser = 'chrome';
		} elseif ( false !== strpos( $ua_lower, 'safari' ) ) {
			$browser = 'safari';
		} elseif ( false !== strpos( $ua_lower, 'firefox' ) ) {
			$browser = 'firefox';
		}

		return array(
			'device'  => $device,
			'browser' => $browser,
			'os'      => $os,
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────

	/**
	 * Extract only the domain from a full referrer URL.
	 *
	 * @param  string $referrer_url
	 * @return string e.g. "zalo.me" or "" on failure
	 */
	public static function sanitize_referrer_domain( $referrer_url ) {
		if ( '' === (string) $referrer_url ) {
			return '';
		}
		$host = wp_parse_url( (string) $referrer_url, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		$host = strtolower( sanitize_text_field( (string) $host ) );
		// Remove www. prefix
		$host = preg_replace( '/^www\./', '', $host );
		return (string) substr( $host, 0, 100 );
	}

	/**
	 * Get real client IP, respecting common proxy headers.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$candidates = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'REMOTE_ADDR',
		);
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For may be a comma-separated list — take the first
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
