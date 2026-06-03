/* PHASE-0.3 H.2 — Multisite-safe user picker
 *
 * Mount any text input by adding `data-bcpro-user-picker` and
 * `data-target="#hidden_user_id_input"`. The hidden input receives
 * the selected user_id; the visible input shows display name.
 *
 * Optional attrs:
 *   data-system="vedic|chinese|western"  → REST hint
 *   data-target-name="user_display_name" → mirror display into another input
 */
(function () {
	'use strict';

	if ( typeof window.bcproUserPicker !== 'object' ) return;
	var CFG = window.bcproUserPicker;

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments, self = this;
			clearTimeout( t );
			t = setTimeout( function () { fn.apply( self, args ); }, ms );
		};
	}

	function esc( s ) {
		return String( s || '' ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function fetchUsers( q, system ) {
		var url = CFG.restUrl + '?q=' + encodeURIComponent( q );
		if ( system ) url += '&system=' + encodeURIComponent( system );
		return fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce, 'Accept': 'application/json' }
		} ).then( function ( r ) {
			if ( r.status === 429 ) return { error: 'rate_limited', results: [] };
			return r.json();
		} );
	}

	function renderResults( panel, data, sys ) {
		panel.innerHTML = '';
		if ( data.error === 'rate_limited' ) {
			panel.innerHTML = '<div class="bcpro-up-msg">' + esc( CFG.i18n.rate_limit ) + '</div>';
			panel.style.display = 'block';
			return;
		}
		if ( ! data.results || data.results.length === 0 ) {
			panel.innerHTML = '<div class="bcpro-up-msg">' + esc( CFG.i18n.no_results ) + '</div>';
			panel.style.display = 'block';
			return;
		}
		data.results.forEach( function ( u ) {
			var has = u.has || {};
			var badges = '';
			[ 'western', 'vedic', 'chinese' ].forEach( function ( s ) {
				if ( has[ s ] ) {
					var cls = ( s === sys ) ? 'bcpro-up-badge bcpro-up-badge--warn' : 'bcpro-up-badge';
					badges += '<span class="' + cls + '">' + s + '</span>';
				}
			} );
			var emailHtml = u.email ? '<span class="bcpro-up-email">' + esc( u.email ) + '</span>' : '';
			var row = document.createElement( 'div' );
			row.className = 'bcpro-up-row';
			row.setAttribute( 'data-user-id', u.id );
			row.setAttribute( 'data-display', u.display );
			row.innerHTML =
				'<div class="bcpro-up-name"><strong>' + esc( u.display ) + '</strong> ' +
				'<span class="bcpro-up-login">@' + esc( u.login ) + '</span> ' +
				emailHtml + '</div>' +
				( badges ? '<div class="bcpro-up-badges">' + CFG.i18n.has_chart + ': ' + badges + '</div>' : '' );
			panel.appendChild( row );
		} );
		panel.style.display = 'block';
	}

	function mount( input ) {
		if ( input._bcproUpMounted ) return;
		input._bcproUpMounted = true;
		input.setAttribute( 'autocomplete', 'off' );
		if ( ! input.getAttribute( 'placeholder' ) ) {
			input.setAttribute( 'placeholder', CFG.i18n.placeholder );
		}

		var sys       = input.getAttribute( 'data-system' ) || '';
		var targetSel = input.getAttribute( 'data-target' );
		var dispSel   = input.getAttribute( 'data-target-display' );
		var target    = targetSel ? document.querySelector( targetSel ) : null;
		var dispOut   = dispSel ? document.querySelector( dispSel ) : null;

		var wrap = document.createElement( 'div' );
		wrap.className = 'bcpro-up-wrap';
		input.parentNode.insertBefore( wrap, input );
		wrap.appendChild( input );

		var panel = document.createElement( 'div' );
		panel.className = 'bcpro-up-panel';
		panel.style.display = 'none';
		wrap.appendChild( panel );

		var doSearch = debounce( function () {
			var q = input.value.trim();
			if ( q.length < 2 ) { panel.style.display = 'none'; return; }
			panel.innerHTML = '<div class="bcpro-up-msg">' + esc( CFG.i18n.searching ) + '</div>';
			panel.style.display = 'block';
			fetchUsers( q, sys ).then( function ( data ) { renderResults( panel, data, sys ); } )
				.catch( function () { panel.innerHTML = '<div class="bcpro-up-msg">network error</div>'; } );
		}, 250 );

		input.addEventListener( 'input', doSearch );
		input.addEventListener( 'focus', function () { if ( input.value.trim().length >= 2 ) doSearch(); } );

		panel.addEventListener( 'mousedown', function ( e ) {
			var row = e.target.closest( '.bcpro-up-row' );
			if ( ! row ) return;
			e.preventDefault();
			var uid = row.getAttribute( 'data-user-id' );
			var dn  = row.getAttribute( 'data-display' );
			input.value = dn;
			if ( target ) target.value = uid;
			if ( dispOut ) dispOut.value = dn;
			panel.style.display = 'none';
			input.dispatchEvent( new CustomEvent( 'bcpro:user-selected', { detail: { id: uid, display: dn } } ) );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! wrap.contains( e.target ) ) panel.style.display = 'none';
		} );
	}

	function mountAll() {
		document.querySelectorAll( 'input[data-bcpro-user-picker]' ).forEach( mount );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mountAll );
	} else {
		mountAll();
	}

	// Re-mount when DOM changes (e.g. tab switch).
	var mo = new MutationObserver( mountAll );
	mo.observe( document.body, { childList: true, subtree: true } );
})();
