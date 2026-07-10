/**
 * BizCity CRM — Referral Session Tracker
 * [2026-07-02 Johnny Chu] PHASE-0.47 W2 — capture first-touch UTM + referrer on ALL public pages.
 *
 * Stores a rolling 30-min session cookie `_bzref` with:
 *   first_touch_url, first_touch_at, utm_*, referrer, channel_detected, session_id, affiliate_code.
 *
 * On CF7 form submit (wpcf7mailsent):
 *   - Reads cookie + current context → sets hidden input[name="_bzref_data"] value.
 *
 * Pre-fills hidden inputs on DOMContentLoaded so PHP can read via $posted['_bzref_data'].
 *
 * @version 1.0.0
 * @since   PHASE-0.47 W2
 */
( function () {
	'use strict';

	var COOKIE_NAME = '_bzref';
	var COOKIE_TTL  = 30 * 60 * 1000; // 30 minutes rolling (ms)
	var FIELD_NAME  = '_bzref_data';

	// ── Helpers ──────────────────────────────────────────────────────────────

	function parseParams( search ) {
		var p = {};
		if ( ! search || search.length < 2 ) { return p; }
		var pairs = search.slice( 1 ).split( '&' );
		for ( var i = 0; i < pairs.length; i++ ) {
			var kv = pairs[ i ].split( '=' );
			if ( kv[ 0 ] ) {
				try {
					p[ decodeURIComponent( kv[ 0 ] ) ] = decodeURIComponent( ( kv[ 1 ] || '' ).replace( /\+/g, ' ' ) );
				} catch ( e ) { /* skip malformed */ }
			}
		}
		return p;
	}

	function detectChannel( params, referrer ) {
		var src = ( ( params.utm_source || '' ).toLowerCase() );
		var ref = ( ( referrer      || '' ).toLowerCase() );
		if ( src === 'zns' || ref.indexOf( 'zns.zalo.me' ) >= 0 )                            { return 'zns'; }
		if ( ref.indexOf( 'ladi.vn' ) >= 0 || ref.indexOf( 'ladivn.' ) >= 0 )                { return 'ladi'; }
		if ( ref.indexOf( 'zalo.me' ) >= 0 || ref.indexOf( 'zalo.com.vn' ) >= 0 )            { return 'zalo'; }
		if ( ref.indexOf( 'facebook.com' ) >= 0 || ref.indexOf( 'fb.com' ) >= 0 )            { return 'facebook'; }
		if ( ref.indexOf( 't.me' ) >= 0 || ref.indexOf( 'telegram.me' ) >= 0 )               { return 'telegram'; }
		if ( src === 'affiliate' || params.aff_code || params.aff || params.ref )             { return 'affiliate'; }
		if ( ! referrer || ref.indexOf( location.hostname ) >= 0 || ref === '' )              { return 'direct'; }
		return 'organic';
	}

	function readCookie() {
		try {
			var m = document.cookie.match( new RegExp( '(?:^|; )' + COOKIE_NAME + '=([^;]*)' ) );
			return m ? JSON.parse( decodeURIComponent( m[ 1 ] ) ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function writeCookie( data ) {
		try {
			var exp = new Date( Date.now() + COOKIE_TTL );
			document.cookie =
				COOKIE_NAME + '=' + encodeURIComponent( JSON.stringify( data ) ) +
				'; expires=' + exp.toUTCString() +
				'; path=/; SameSite=Lax';
		} catch ( e ) { /* noop if storage blocked */ }
	}

	function genSessionId() {
		var r = '';
		var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		for ( var i = 0; i < 12; i++ ) {
			r += chars.charAt( Math.floor( Math.random() * chars.length ) );
		}
		return 'bzref_' + r;
	}

	// ── Capture first-touch ───────────────────────────────────────────────────

	var params   = parseParams( location.search );
	var referrer = document.referrer;
	var existing = readCookie();

	var data;
	if ( existing && existing.first_touch_url ) {
		data = existing;
	} else {
		data = {
			first_touch_url : location.href,
			first_touch_at  : new Date().toISOString(),
			session_id      : genSessionId(),
			utm_source      : '',
			utm_medium      : '',
			utm_campaign    : '',
			utm_content     : '',
			utm_term        : '',
			referrer        : referrer || '',
			channel_detected: 'direct',
			affiliate_code  : '',
			affiliate_member_id: '',
		};
	}

	// Update UTM / channel from current page (last-touch if UTM present)
	var hasUtm = params.utm_source || params.utm_campaign;
	if ( hasUtm ) {
		data.utm_source   = params.utm_source   || data.utm_source   || '';
		data.utm_medium   = params.utm_medium   || data.utm_medium   || '';
		data.utm_campaign = params.utm_campaign || data.utm_campaign || '';
		data.utm_content  = params.utm_content  || data.utm_content  || '';
		data.utm_term     = params.utm_term     || data.utm_term     || '';
		data.referrer     = referrer || data.referrer || '';
		data.channel_detected = detectChannel( params, referrer );
	} else if ( ! existing ) {
		// First page load without UTM — still detect channel from referrer
		data.channel_detected = detectChannel( {}, referrer );
	}

	// Affiliate / referral codes
	if ( params.aff_code || params.aff ) {
		data.affiliate_code = params.aff_code || params.aff || '';
		data.channel_detected = 'affiliate';
	}
	if ( params.ref && ! data.affiliate_code ) {
		data.affiliate_code = params.ref;
	}
	if ( params.member_id ) {
		data.affiliate_member_id = params.member_id;
	}

	writeCookie( data );

	// ── Pre-fill hidden input on DOM ready ───────────────────────────────────

	function fillHiddenInputs() {
		var snapshot = JSON.stringify( readCookie() || data );
		var inputs   = document.querySelectorAll( 'input[name="' + FIELD_NAME + '"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			inputs[ i ].value = snapshot;
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', fillHiddenInputs );
	} else {
		fillHiddenInputs();
	}

	// ── Attach to CF7 wpcf7mailsent ──────────────────────────────────────────

	document.addEventListener( 'wpcf7mailsent', function ( e ) {
		var finalData = readCookie() || data;
		finalData.form_page_url      = location.href;
		finalData.form_submitted_at  = new Date().toISOString();
		writeCookie( finalData );

		// Also store in sessionStorage as fallback
		try { sessionStorage.setItem( '_bzref_last', JSON.stringify( finalData ) ); } catch ( ex ) { /* noop */ }

		// Refresh hidden inputs in the form (in case form re-submits)
		fillHiddenInputs();
	}, false );

} )();
