/**
 * BizCity Scheduler API slice (framework-level).
 *
 * Talks directly to the unified `bizcity-scheduler/v1/*` REST namespace
 * owned by the bizcity-twin-ai core scheduler.  Any plugin shipping a React
 * SPA can drop this file in (or import from a shared package) — it depends
 * only on `window.BIZCITY_CRM_BOOT.schedulerRestUrl` (or any other boot
 * variable that exposes the same string).
 *
 * Phase 6 of the M-CRM.M12 v2 calendar unification — see
 * PHASE-0.35-WAVES.md §A.
 */
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

const SCHEDULER_BASE = BOOT.schedulerRestUrl || '/wp-json/bizcity-scheduler/v1/';
// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — channel gateway base for FB retry
const CHANNEL_BASE   = BOOT.channelRestUrl   || '/wp-json/bizcity-channel/v1/';

/**
 * Map server row (DATETIME strings + JSON metadata) into the legacy CRM
 * shape that CalendarTab.jsx expects: { id, title, type, start_at (unix),
 * end_at (unix), attendees[], related_entity_*, google_account_id, status }.
 */
function shapeEvent( r ) {
	if ( ! r ) { return null; }
	let meta = {};
	if ( r.metadata ) {
		if ( typeof r.metadata === 'string' ) {
			try { meta = JSON.parse( r.metadata ) || {}; } catch ( _e ) { meta = {}; }
		} else if ( typeof r.metadata === 'object' ) {
			meta = r.metadata;
		}
	}
	const toUnix = ( v ) => {
		if ( v == null || v === '' ) { return 0; }
		if ( typeof v === 'number' ) { return v; }
		const t = Date.parse( typeof v === 'string' ? v.replace( ' ', 'T' ) : v );
		return Number.isFinite( t ) ? Math.floor( t / 1000 ) : 0;
	};
	return {
		id:                  Number( r.id ),
		title:               String( r.title || '' ),
		type:                String( r.event_type || 'meeting' ),
		start_at:            toUnix( r.start_at ),
		end_at:              toUnix( r.end_at ),
		all_day:             !! Number( r.all_day ),
		status:              String( r.status || 'active' ),
		source:              String( r.source || 'user' ),
		reminder_min:        Number( r.reminder_min || 0 ),
		attendees:           Array.isArray( meta.attendees ) ? meta.attendees : [],
		contact_id:          meta.contact_id ? Number( meta.contact_id ) : null,
		conversation_id:     meta.conversation_id ? Number( meta.conversation_id ) : null,
		related_entity_type: meta.related_entity_type || null,
		related_entity_id:   meta.related_entity_id ? Number( meta.related_entity_id ) : null,
		google_event_id:     r.google_event_id || '',
		google_calendar_id:  r.google_calendar_id || '',
		google_account_id:   r.google_account_id ? Number( r.google_account_id ) : null,
		created_by:          Number( r.user_id || 0 ),
	};
}

/**
 * Convert a CRM-shaped payload (unix `start_at`, top-level `attendees`,
 * `contact_id`, etc.) into the scheduler REST body (DATETIME + nested keys).
 */
function toSchedulerPayload( body ) {
	const out = {};
	if ( body.title != null )        { out.title       = String( body.title ); }
	if ( body.type != null )         { out.event_type  = String( body.type ); }
	if ( body.event_type != null )   { out.event_type  = String( body.event_type ); }
	if ( body.description != null )  { out.description = String( body.description ); }
	if ( body.all_day != null )      { out.all_day     = body.all_day ? 1 : 0; }
	if ( body.reminder_min != null ) { out.reminder_min = Number( body.reminder_min ); }
	if ( body.source != null )       { out.source      = String( body.source ); }
	if ( body.status != null )       { out.status      = String( body.status ); }
	if ( body.google_account_id != null ) { out.google_account_id = Number( body.google_account_id ); }

	const fmtMysql = ( unix ) => {
		const d = new Date( Number( unix ) * 1000 );
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) } ${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }:${ pad( d.getSeconds() ) }`;
	};
	if ( body.start_at != null ) { out.start_at = typeof body.start_at === 'number' ? fmtMysql( body.start_at ) : body.start_at; }
	if ( body.end_at   != null ) { out.end_at   = typeof body.end_at   === 'number' ? fmtMysql( body.end_at   ) : body.end_at; }

	// Pack metadata fields the BE will store in the `metadata` JSON column.
	const meta = {};
	if ( Array.isArray( body.attendees ) )            { meta.attendees           = body.attendees; }
	if ( body.contact_id != null )                    { meta.contact_id          = Number( body.contact_id ); }
	if ( body.conversation_id != null )               { meta.conversation_id     = Number( body.conversation_id ); }
	if ( body.related_entity_type != null )           { meta.related_entity_type = String( body.related_entity_type ); }
	if ( body.related_entity_id != null )             { meta.related_entity_id   = Number( body.related_entity_id ); }
	if ( Object.keys( meta ).length ) { out.metadata = meta; }

	return out;
}

export const schedulerApi = createApi( {
	reducerPath: 'schedulerApi',
	baseQuery: fetchBaseQuery( {
		baseUrl: SCHEDULER_BASE,
		prepareHeaders: ( headers ) => {
			if ( BOOT.restNonce ) { headers.set( 'X-WP-Nonce', BOOT.restNonce ); }
			return headers;
		},
		credentials: 'same-origin',
	} ),
	tagTypes: [ 'SchedulerEvent', 'SchedulerGoogle' ],
	endpoints: ( b ) => ( {
		// GET /events?from=YYYY-MM-DD HH:MM:SS&to=...&status=
		// `from`/`to` in the FE are unix seconds — converted to MySQL DATETIME on send.
		getSchedulerEvents: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				// IMPORTANT: server stores `start_at` as MySQL DATETIME in site local TZ.
				// Format `from`/`to` in LOCAL time, not UTC — otherwise events later
				// than (24h - tz_offset) of the day get clipped (e.g. VN+7 → events
				// after 17:00 disappear from day view).
				const fmt = ( unix ) => {
					const d = new Date( Number( unix ) * 1000 );
					const pad = ( n ) => String( n ).padStart( 2, '0' );
					return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) } ${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }:${ pad( d.getSeconds() ) }`;
				};
				if ( args.from != null ) { params.set( 'from', typeof args.from === 'number' ? fmt( args.from ) : args.from ); }
				if ( args.to   != null ) { params.set( 'to',   typeof args.to   === 'number' ? fmt( args.to   ) : args.to   ); }
				if ( args.status )       { params.set( 'status', args.status ); }
				const qs = params.toString();
				return 'events' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => {
				const list = Array.isArray( r?.events ) ? r.events : ( Array.isArray( r ) ? r : [] );
				let out = list.map( shapeEvent ).filter( Boolean );
				return out;
			},
			providesTags: ( res ) => [
				'SchedulerEvent',
				...( ( res || [] ).map( ( e ) => ( { type: 'SchedulerEvent', id: e.id } ) ) ),
			],
		} ),

		createSchedulerEvent: b.mutation( {
			query: ( body ) => ( { url: 'events', method: 'POST', body: toSchedulerPayload( body ) } ),
			transformResponse: ( r ) => shapeEvent( r?.event || r ),
			invalidatesTags: [ 'SchedulerEvent' ],
		} ),

		updateSchedulerEvent: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `events/${ id }`, method: 'PATCH', body: toSchedulerPayload( body ) } ),
			transformResponse: ( r ) => shapeEvent( r?.event || r ),
			invalidatesTags: ( res, err, arg ) => [ 'SchedulerEvent', { type: 'SchedulerEvent', id: arg.id } ],
		} ),

		deleteSchedulerEvent: b.mutation( {
			query: ( id ) => ( { url: `events/${ id }`, method: 'DELETE' } ),
			invalidatesTags: ( res, err, id ) => [ 'SchedulerEvent', { type: 'SchedulerEvent', id } ],
		} ),

		getGoogleAccounts: b.query( {
			query: () => 'google/accounts',
			transformResponse: ( r ) => ( {
				accounts:           Array.isArray( r?.accounts ) ? r.accounts : [],
				bzgoogle_available: !! r?.bzgoogle_available,
			} ),
			providesTags: [ 'SchedulerGoogle' ],
		} ),

		syncGoogle: b.mutation( {
			query: ( { account_id } = {} ) => ( {
				url: 'google/sync',
				method: 'POST',
				body: account_id ? { account_id: Number( account_id ) } : {},
			} ),
			invalidatesTags: [ 'SchedulerEvent', 'SchedulerGoogle' ],
		} ),

		getGoogleSettings: b.query( {
			query: () => 'google/settings',
			providesTags: [ 'SchedulerGoogle' ],
		} ),

		saveGoogleSettings: b.mutation( {
			query: ( body ) => ( { url: 'google/settings', method: 'POST', body } ),
			invalidatesTags: [ 'SchedulerGoogle' ],
		} ),

		disconnectGoogle: b.mutation( {
			query: () => ( { url: 'google/disconnect', method: 'POST' } ),
			invalidatesTags: [ 'SchedulerGoogle', 'SchedulerEvent' ],
		} ),

		// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — retry failed FB post.
		// Calls bizcity-channel/v1 (different namespace — uses absolute URL).
		retryFbPost: b.mutation( {
			query: ( id ) => ( {
				url:    CHANNEL_BASE + 'fb-posts/' + id + '/retry',
				method: 'POST',
			} ),
			invalidatesTags: [ 'SchedulerEvent' ],
		} ),
	} ),
} );

export const {
	useGetSchedulerEventsQuery,
	useCreateSchedulerEventMutation,
	useUpdateSchedulerEventMutation,
	useDeleteSchedulerEventMutation,
	useGetGoogleAccountsQuery,
	useSyncGoogleMutation,
	useGetGoogleSettingsQuery,
	useSaveGoogleSettingsMutation,
	useDisconnectGoogleMutation,
	useRetryFbPostMutation,
} = schedulerApi;
