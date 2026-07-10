/**
 * cgBroadcastApi — RTK Query slice for bizcity-crm/v1/broadcasts/*
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Broadcast ZNS + Email API for CRM SPA
 * [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Fixed baseUrl to use BOOT.restUrl (bizcity-crm/v1)
 * since broadcasts routes live in the CRM REST controller, not channel-gateway.
 * getContacts/parseFile also added here.
 */
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

// ZNS OA accounts API (bizcity-channel/v1) — separate baseUrl.
const ZNS_BASE = BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/';

export const cgBroadcastApi = createApi( {
	reducerPath: 'cgBroadcastApi',
	baseQuery: fetchBaseQuery( {
		// Broadcasts (list/create/parse-file/contacts) are served from bizcity-crm/v1.
		baseUrl: BOOT.restUrl || '/wp-json/bizcity-crm/v1/',
		prepareHeaders: ( headers ) => {
			if ( BOOT.restNonce ) { headers.set( 'X-WP-Nonce', BOOT.restNonce ); }
			return headers;
		},
		credentials: 'same-origin',
	} ),
	tagTypes: [ 'CgBroadcast', 'CgBroadcastProgress', 'ZnsOaAccounts' ],
	endpoints: ( b ) => ( {
		listBroadcasts: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.status ) { p.set( 'status', args.status ); }
				if ( args.type )   { p.set( 'type',   args.type   ); }
				if ( args.page )   { p.set( 'page',   args.page   ); }
				const qs = p.toString();
				return 'broadcasts' + ( qs ? '?' + qs : '' );
			},
			// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Bug fix — wrap() returns { ok, data: { items, total } }
			// Also normalise name (PHP shape uses 'title') and type (embedded in message_template JSON)
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return { items: [], total: 0 }; }
				const raw = r.data || { items: [], total: 0 };
				return {
					...raw,
					items: ( raw.items || [] ).map( ( bc ) => {
						let btype = bc.type || 'zns';
						try {
							if ( ! bc.type && bc.message_template ) {
								const mt = JSON.parse( bc.message_template );
								btype = ( mt && mt.broadcast_type ) || 'zns';
							}
						} catch ( _e ) { /* noop */ }
						return { ...bc, name: bc.name || bc.title || '', type: btype };
					} ),
				};
			},
			providesTags: [ 'CgBroadcast' ],
		} ),

		getBroadcast: b.query( {
			query: ( id ) => 'broadcasts/' + id,
			// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — wrap() { ok, data: {...} }
			transformResponse: ( r ) => ( r && r.ok ? ( r.data || null ) : null ),
			providesTags: ( res, err, id ) => [ { type: 'CgBroadcast', id } ],
		} ),

		getBroadcastProgress: b.query( {
			query: ( id ) => 'broadcasts/' + id + '/progress',
			// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — wrap() { ok, data: { id, status, total, sent, failed, queued, percent } }
			transformResponse: ( r ) => ( r && r.ok ? ( r.data || null ) : null ),
			providesTags: ( res, err, id ) => [ { type: 'CgBroadcastProgress', id } ],
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Console: all active broadcasts + cron status, poll 5s
		getCronConsole: b.query( {
			query: () => 'broadcasts/cron-console',
			transformResponse: ( r ) => ( r && r.ok ? ( r.data || null ) : null ),
			providesTags: [ 'CgBroadcastProgress' ],
		} ),

		createBroadcast: b.mutation( {
			query: ( body ) => ( { url: 'broadcasts', method: 'POST', body } ),
			invalidatesTags: [ 'CgBroadcast' ],
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — PATCH /broadcasts/{id} to edit title/meta/delay_sec (draft/paused only)
		updateBroadcast: b.mutation( {
			query: ( { id, ...body } ) => ( { url: 'broadcasts/' + id, method: 'POST', body } ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'CgBroadcast', id }, 'CgBroadcast' ],
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — create ZNS/Email broadcast with meta+recipients.
		// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — also invalidate CgBroadcastProgress so ConsoleTab refreshes
		createBroadcastZns: b.mutation( {
			query: ( body ) => ( { url: 'broadcasts/create-zns', method: 'POST', body } ),
			invalidatesTags: [ 'CgBroadcast', 'CgBroadcastProgress' ],
		} ),

		deleteBroadcast: b.mutation( {
			query: ( id ) => ( { url: 'broadcasts/' + id, method: 'DELETE' } ),
			invalidatesTags: [ 'CgBroadcast' ],
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Bug fix — route is /send not /start
		startBroadcast: b.mutation( {
			query: ( id ) => ( { url: 'broadcasts/' + id + '/send', method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CgBroadcast', id }, 'CgBroadcast' ],
		} ),

		pauseBroadcast: b.mutation( {
			query: ( id ) => ( { url: 'broadcasts/' + id + '/pause', method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CgBroadcast', id }, 'CgBroadcast' ],
		} ),

		cancelBroadcast: b.mutation( {
			query: ( id ) => ( { url: 'broadcasts/' + id + '/cancel', method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CgBroadcast', id }, 'CgBroadcast' ],
		} ),

		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Restart: reset all recipients to queued, resume sending
		restartBroadcast: b.mutation( {
			query: ( id ) => ( { url: 'broadcasts/' + id + '/restart', method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CgBroadcast', id }, 'CgBroadcast', 'CgBroadcastProgress' ],
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — GET /broadcasts/{id}/recipients with JOIN + status filter
		getBroadcastRecipients: b.query( {
			query: ( { id, status = '', limit = 50, offset = 0 } ) => {
				const p = new URLSearchParams();
				if ( status  ) { p.set( 'status', status   ); }
				if ( limit   ) { p.set( 'limit',  limit    ); }
				if ( offset  ) { p.set( 'offset', offset   ); }
				const qs = p.toString();
				return 'broadcasts/' + id + '/recipients' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? ( r.data || { items: [], total: 0, counts: {} } ) : { items: [], total: 0, counts: {} } ),
			providesTags: ( res, err, { id } ) => [ { type: 'CgBroadcastProgress', id } ],
		} ),

		// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — GET /broadcasts/{id}/recipients?activity=1
		// Returns last 30 sent+failed rows ordered by sent_at DESC — used by Console activity log
		getBroadcastActivity: b.query( {
			query: ( { id, limit = 30 } ) => {
				const p = new URLSearchParams( { activity: '1', limit: String( limit ) } );
				return 'broadcasts/' + id + '/recipients?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? ( ( r.data && r.data.items ) || [] ) : [] ),
			providesTags: ( res, err, { id } ) => [ { type: 'CgBroadcastProgress', id } ],
		} ),

		parseFile: b.mutation( {
			// FormData — fetchBaseQuery serialises it correctly when body is FormData.
			// Response: { success, rows: [{name,phone,email}], count }
			query: ( formData ) => ( { url: 'broadcasts/parse-file', method: 'POST', body: formData } ),
		} ),

		getContacts: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.q )      { p.set( 'q',      args.q      ); }
				if ( args.source ) { p.set( 'source',  args.source  ); }
				if ( args.limit )  { p.set( 'limit',   args.limit   ); }
				const qs = p.toString();
				return 'broadcasts/contacts' + ( qs ? '?' + qs : '' );
			},
			// PHP wrap() returns { ok, data: { items: [...], total } }
			transformResponse: ( r ) => ( r && r.ok ? ( ( r.data && r.data.items ) || [] ) : [] ),
		} ),

		// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — fetch ZNS OA accounts for OA dropdown.
		// Uses an absolute URL to bizcity-channel/v1 namespace.
		getZnsOaAccounts: b.query( {
			queryFn: async ( _arg, _api, _extra, baseQuery ) => {
				// Call bizcity-channel/v1 namespace via window.fetch (different base).
				const nonce  = BOOT.restNonce || '';
				const url    = ZNS_BASE.replace( /\/$/, '' ) + '/cf7/zns-oa-accounts';
				const resp   = await fetch( url, {
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				} );
				if ( ! resp.ok ) { return { data: [] }; }
				const json = await resp.json();
				// ZNS REST ok() returns { success, data: [...] } — NOT items.
				const items = ( json && json.success && Array.isArray( json.data ) ) ? json.data : [];
				return { data: items };
			},
			providesTags: [ 'ZnsOaAccounts' ],
		} ),
	} ),
} );

export const {
	useListBroadcastsQuery,
	useGetBroadcastQuery,
	useGetBroadcastProgressQuery,
	useCreateBroadcastMutation,
	useUpdateBroadcastMutation,
	useCreateBroadcastZnsMutation,
	useDeleteBroadcastMutation,
	useStartBroadcastMutation,
	usePauseBroadcastMutation,
	useCancelBroadcastMutation,
	useRestartBroadcastMutation,
	useGetBroadcastRecipientsQuery,
	useGetBroadcastActivityQuery,
	useParseFileMutation,
	useGetContactsQuery,
	useGetZnsOaAccountsQuery,
	useGetCronConsoleQuery,
} = cgBroadcastApi;
