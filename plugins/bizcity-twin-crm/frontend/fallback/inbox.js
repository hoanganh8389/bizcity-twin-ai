/* BizCity CRM — fallback inbox SPA (no-build).
 * Uses wp.element (React 18 bundled with WP core) + wp.apiFetch.
 * Production-ready interim; replaced by Vite bundle when frontend/dist/ exists.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.element ) {
		console.error( '[bizcity-crm] wp.element not available' );
		return;
	}
	var React    = wp.element;
	var apiFetch = wp.apiFetch;
	var h        = React.createElement;
	var useState = React.useState;
	var useEffect = React.useEffect;
	var useRef   = React.useRef;
	var useCallback = React.useCallback;

	var BOOT = window.BIZCITY_CRM_BOOT || {};
	var REST_URL  = BOOT.restUrl  || '/wp-json/bizcity-crm/v1/';
	var REST_NONCE = BOOT.restNonce || '';
	var POLL_MS   = BOOT.pollMs   || 3000;
	var I18N      = BOOT.i18n     || {};

	function api( path, params ) {
		var url = REST_URL.replace( /\/+$/, '' ) + '/' + path.replace( /^\/+/, '' );
		if ( params ) {
			var qs = Object.keys( params )
				.filter( function ( k ) { return params[ k ] !== undefined && params[ k ] !== null && params[ k ] !== ''; } )
				.map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ); } )
				.join( '&' );
			if ( qs ) { url += '?' + qs; }
		}
		return fetch( url, {
			headers: { 'X-WP-Nonce': REST_NONCE, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		} ).then( function ( r ) { return r.json(); } );
	}

	function usePolling( fn, deps, intervalMs ) {
		var [ state, setState ] = useState( { loading: true, data: null, error: null } );
		var stopped = useRef( false );
		useEffect( function () {
			stopped.current = false;
			function tick() {
				if ( stopped.current ) { return; }
				fn().then( function ( res ) {
					if ( stopped.current ) { return; }
					if ( res && res.ok ) {
						setState( { loading: false, data: res.data, error: null } );
					} else {
						setState( { loading: false, data: null, error: ( res && res.error && res.error.message ) || 'error' } );
					}
				} ).catch( function ( e ) {
					if ( stopped.current ) { return; }
					setState( { loading: false, data: null, error: String( e ) } );
				} );
			}
			tick();
			var t = setInterval( tick, intervalMs || POLL_MS );
			return function () { stopped.current = true; clearInterval( t ); };
		}, deps || [] );
		return state;
	}

	/* ---------- ChannelSidebar ---------- */
	function ChannelSidebar( props ) {
		var st = usePolling( function () { return api( 'inboxes' ); }, [] );
		var inboxes = st.data || [];
		var groups = {};
		inboxes.forEach( function ( i ) {
			groups[ i.channel_type ] = groups[ i.channel_type ] || [];
			groups[ i.channel_type ].push( i );
		} );

		return h( 'div', { className: 'crm-sidebar' },
			h( 'div', { className: 'crm-sidebar__title' }, 'Inboxes' ),
			st.loading ? h( 'div', { className: 'crm-empty' }, '...' ) :
				inboxes.length === 0 ? h( 'div', { className: 'crm-empty' }, I18N.noChannels || 'No channels' ) :
					Object.keys( groups ).map( function ( ch ) {
						return h( 'div', { className: 'crm-sidebar__group', key: ch },
							h( 'div', { className: 'crm-sidebar__group-title' }, ch.toUpperCase() ),
							groups[ ch ].map( function ( i ) {
								var active = props.selectedInboxId === i.id;
								return h( 'div', {
									key: i.id,
									className: 'crm-sidebar__item' + ( active ? ' is-active' : '' ),
									onClick: function () { props.onSelectInbox( i.id ); },
								}, i.name );
							} )
						);
					} )
		);
	}

	/* ---------- ConversationList ---------- */
	function ConversationList( props ) {
		var st = usePolling(
			function () { return api( 'conversations', { inbox_id: props.inboxId || '', limit: 50 } ); },
			[ props.inboxId ]
		);
		var convs = st.data || [];
		return h( 'div', { className: 'crm-convlist' },
			h( 'div', { className: 'crm-convlist__title' }, 'Conversations' ),
			st.loading ? h( 'div', { className: 'crm-empty' }, '...' ) :
				convs.length === 0 ? h( 'div', { className: 'crm-empty' }, I18N.noConversations || 'No conversations' ) :
					convs.map( function ( c ) {
						var active = props.selectedConvId === c.id;
						var preview = c.last_message ? c.last_message.content : '';
						return h( 'div', {
							key: c.id,
							className: 'crm-convitem' + ( active ? ' is-active' : '' ),
							onClick: function () { props.onSelectConv( c.id ); },
						},
							h( 'div', { className: 'crm-convitem__avatar' },
								c.contact && c.contact.avatar_url
									? h( 'img', { src: c.contact.avatar_url, alt: '' } )
									: ( c.contact && c.contact.name ? c.contact.name.charAt( 0 ).toUpperCase() : '?' )
							),
							h( 'div', { className: 'crm-convitem__body' },
								h( 'div', { className: 'crm-convitem__name' }, ( c.contact && c.contact.name ) || ( '#' + c.id ) ),
								h( 'div', { className: 'crm-convitem__preview' }, ( preview || '' ).substring( 0, 80 ) )
							),
							h( 'div', { className: 'crm-convitem__meta' },
								h( 'span', { className: 'crm-badge crm-badge--' + c.status }, c.status )
							)
						);
					} )
		);
	}

	/* ---------- ConversationDetail ---------- */
	function ConversationDetail( props ) {
		var convId = props.convId;
		var st = usePolling(
			function () { return convId ? api( 'conversations/' + convId + '/messages', { limit: 200 } ) : Promise.resolve( { ok: true, data: [] } ); },
			[ convId ],
			2000
		);
		var scrollRef = useRef( null );
		var messages = st.data || [];
		useEffect( function () {
			if ( scrollRef.current ) { scrollRef.current.scrollTop = scrollRef.current.scrollHeight; }
		}, [ messages.length ] );

		if ( ! convId ) {
			return h( 'div', { className: 'crm-detail crm-detail--empty' }, I18N.selectConv || 'Select a conversation.' );
		}
		return h( 'div', { className: 'crm-detail' },
			h( 'div', { className: 'crm-detail__header' }, 'Conversation #' + convId ),
			h( 'div', { className: 'crm-detail__messages', ref: scrollRef },
				messages.map( function ( m ) {
					var side = m.message_type === 'outgoing' ? 'out' : 'in';
					return h( 'div', { key: m.id, className: 'crm-msg crm-msg--' + side },
						h( 'div', { className: 'crm-msg__bubble' },
							h( 'div', { className: 'crm-msg__content' }, m.content || '(no text)' ),
							( m.attachments || [] ).map( function ( a ) {
								return a.file_type === 'image'
									? h( 'img', { key: a.id, src: a.data_url, className: 'crm-msg__img', alt: '' } )
									: h( 'a', { key: a.id, href: a.data_url, target: '_blank', rel: 'noopener' }, '📎 ' + a.file_type );
							} ),
							h( 'div', { className: 'crm-msg__meta' },
									m.sender_type, ' · ', new Date( ( m.created_at || '' ).replace( ' ', 'T' ) ).toLocaleString(),
								m.ai_metadata ? h( 'span', { className: 'crm-msg__ai', title: 'AI metadata available' }, ' · 🧠' ) : null
							)
						)
					);
				} )
			),
			h( 'div', { className: 'crm-detail__reply' },
				h( 'input', { type: 'text', placeholder: 'Reply (M2)…', disabled: true, className: 'crm-reply__input' } ),
				h( 'button', { disabled: true, className: 'button button-primary' }, 'Send' )
			)
		);
	}

	/* ---------- App ---------- */
	function App() {
		var [ inboxId, setInboxId ] = useState( 0 );
		var [ convId,  setConvId  ] = useState( 0 );
		return h( 'div', { className: 'bizcity-crm-root' },
			h( ChannelSidebar,    { selectedInboxId: inboxId, onSelectInbox: function ( id ) { setInboxId( id ); setConvId( 0 ); } } ),
			h( ConversationList,  { inboxId: inboxId, selectedConvId: convId, onSelectConv: setConvId } ),
			h( ConversationDetail, { convId: convId } )
		);
	}

	function mount() {
		var el = document.getElementById( 'bizcity-crm-inbox-root' );
		if ( ! el ) { return; }
		if ( wp.element.createRoot ) {
			wp.element.createRoot( el ).render( h( App ) );
		} else {
			wp.element.render( h( App ), el );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp );
