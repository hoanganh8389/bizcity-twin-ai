import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — helper to call channel gateway from CRM context.
const cgBaseQuery = fetchBaseQuery( {
	baseUrl: BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/',
	prepareHeaders: ( headers ) => {
		if ( BOOT.restNonce ) { headers.set( 'X-WP-Nonce', BOOT.restNonce ); }
		return headers;
	},
	credentials: 'same-origin',
} );

export const crmApi = createApi( {
	reducerPath: 'crmApi',
	baseQuery: fetchBaseQuery( {
		baseUrl: BOOT.restUrl || '/wp-json/bizcity-crm/v1/',
		prepareHeaders: ( headers ) => {
			if ( BOOT.restNonce ) { headers.set( 'X-WP-Nonce', BOOT.restNonce ); }
			return headers;
		},
		credentials: 'same-origin',
	} ),
	tagTypes: [ 'Inbox', 'Conversation', 'Message', 'Contact', 'Order', 'Label', 'Macro', 'Attr', 'WorkingHours', 'SlaPolicy', 'AutomationRule', 'ConvSla', 'CrmAccount', 'CrmContact', 'CrmTask', 'CrmEvent', 'CrmDocument', 'CrmNoteDoc', 'CrmLead', 'CrmOpportunity', 'CrmOppLine', 'CrmContract', 'CrmContractLine', 'CrmProduct', 'CrmProductCategory', 'CrmInvoice', 'CrmEmailAccount', 'CrmEmailThread', 'GmailSmtp', 'EmailEventRule', 'Campaign', 'CampaignFunnel', 'CampaignDropdowns', 'LoyaltyBalance', 'BizGptFlow', 'BrandKit', 'CampaignAssetManifest', 'PrintAdsTemplates', 'PrintAdsGenerations', 'AdminChatGrant', 'AdminChatGrantVersion', 'CrmAuditLog', 'AdminChatAudit', 'Broadcast', 'Integration', 'EmailSendLog', 'ZnsSendLog', 'Cf7Submission', 'CrmSubmission' ],
	endpoints: ( b ) => ( {
		getInboxes: b.query( {
			query: () => 'inboxes',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'Inbox' ],
		} ),
		getConversations: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.inbox_id ) { params.set( 'inbox_id', args.inbox_id ); }
				if ( args.status )   { params.set( 'status',   args.status   ); }
				if ( args.label_id ) { params.set( 'label_id', args.label_id ); }
				if ( args.snoozed !== undefined && args.snoozed !== null && args.snoozed !== '' ) {
					params.set( 'snoozed', args.snoozed ? '1' : '0' );
				}
				if ( args.limit )    { params.set( 'limit',    args.limit    ); }
				if ( args.before_id ){ params.set( 'before_id', args.before_id ); }
				const qs = params.toString();
				return 'conversations' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'Conversation' ],
		} ),
		getMessages: b.query( {
			query: ( { convId, after_id = 0, limit = 200 } ) =>
				`conversations/${ convId }/messages?after_id=${ after_id }&limit=${ limit }`,
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: ( res, err, arg ) => [ { type: 'Message', id: arg.convId } ],
		} ),
		sendReply: b.mutation( {
			query: ( { convId, content, content_type = 'text', responder_kind = 'manual', character_id } ) => ( {
				url: `conversations/${ convId }/messages`,
				method: 'POST',
				body: { content, content_type, responder_kind, character_id },
			} ),
			invalidatesTags: ( res, err, arg ) => [
				{ type: 'Message', id: arg.convId },
				'Conversation',
			],
		} ),
		sendNote: b.mutation( {
			query: ( { convId, content } ) => ( {
				url: `conversations/${ convId }/notes`,
				method: 'POST',
				body: { content },
			} ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'Message', id: arg.convId } ],
		} ),
		resolveConversation: b.mutation( {
			query: ( { convId } ) => ( {
				url: `conversations/${ convId }/resolve`,
				method: 'POST',
			} ),
			invalidatesTags: [ 'Conversation' ],
		} ),
		aiReply: b.mutation( {
			query: ( { convId, prompt, dispatch = true, notebook_id, character_id } ) => ( {
				url: `conversations/${ convId }/ai-reply`,
				method: 'POST',
				body: { prompt, dispatch, notebook_id, character_id },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [
				{ type: 'Message', id: arg.convId },
				'Conversation',
			],
		} ),
		getConversation: b.query( {
			query: ( convId ) => `conversations/${ convId }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, convId ) => [ { type: 'Conversation', id: convId } ],
		} ),
		getContact: b.query( {
			query: ( contactId ) => `contacts/${ contactId }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, contactId ) => [ { type: 'Contact', id: contactId } ],
		} ),
		// [2026-06-13 Johnny Chu] HOTFIX — PATCH contact email/phone from inbox drawer
		patchContact: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `contacts/${ id }`, method: 'PATCH', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'Contact', id: arg.id } ],
		} ),
		getLastSkip: b.query( {
			query: ( convId ) => `conversations/${ convId }/last-skip`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getOrderBanks: b.query( {
			query: () => 'order-adapter/banks',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { adapter: null, options: [] } ),
		} ),
		searchOrderProducts: b.query( {
			query: ( { q = '', limit = 20 } = {} ) => `order-adapter/products?q=${ encodeURIComponent( q ) }&limit=${ limit }`,
			transformResponse: ( r ) => ( r && r.ok ? ( r.data.products || [] ) : [] ),
		} ),
		getConversationOrders: b.query( {
			query: ( convId ) => `conversations/${ convId }/orders`,
			transformResponse: ( r ) => ( r && r.ok ? ( r.data.orders || [] ) : [] ),
			providesTags: ( res, err, convId ) => [ { type: 'Order', id: convId } ],
		} ),
		createConversationOrder: b.mutation( {
			query: ( { convId, items, custom_amount, payment_option, note } ) => ( {
				url: `conversations/${ convId }/orders`,
				method: 'POST',
				body: { items, custom_amount, payment_option, note },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [
				{ type: 'Order', id: arg.convId },
				{ type: 'Message', id: arg.convId },
				'Conversation',
			],
		} ),
		/* PHASE-0.36b — single order preview, send-to-customer, saved banks */
		getSingleOrder: b.query( {
			query: ( orderId ) => `orders/${ orderId }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		sendOrderToCustomer: b.mutation( {
			query: ( { convId, order_id, mode = 'recap' } ) => ( {
				url: `conversations/${ convId }/send-order`,
				method: 'POST',
				body: { order_id, mode },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [
				{ type: 'Message', id: arg.convId },
				'Conversation',
			],
		} ),
		getSavedBanks: b.query( {
			query: () => 'order-adapter/saved-banks',
			transformResponse: ( r ) => ( r && r.ok ? ( r.data.banks || [] ) : [] ),
		} ),
		addSavedBank: b.mutation( {
			query: ( body ) => ( {
				url: 'order-adapter/saved-banks',
				method: 'POST',
				body,
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		deleteSavedBank: b.mutation( {
			query: ( { idx } ) => ( {
				url: `order-adapter/saved-banks?idx=${ idx }`,
				method: 'DELETE',
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* ------------------------------------------------------------------
		 * PHASE 0.35 M-FE — Workspace tabs (Labels, Macros, Attrs, SLA, Automation, Reports, CSAT)
		 * ------------------------------------------------------------------ */

		/* M3.W1 — Labels (BE envelope: { ok:true, data:{ labels:[…], count } }) */
		getLabels: b.query( {
			query: () => 'labels',
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.labels ) ? r.data.labels : [];
			},
			providesTags: [ 'Label' ],
		} ),
		createLabel: b.mutation( {
			query: ( body ) => ( { url: 'labels', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'Label' ],
		} ),
		updateLabel: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `labels/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'Label' ],
		} ),
		deleteLabel: b.mutation( {
			query: ( { id } ) => ( { url: `labels/${ id }`, method: 'DELETE' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'Label' ],
		} ),
		getConversationLabels: b.query( {
			query: ( convId ) => `conversations/${ convId }/labels`,
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.labels ) ? r.data.labels : [];
			},
			providesTags: ( res, err, convId ) => [ { type: 'Label', id: `conv-${ convId }` } ],
		} ),
		setConversationLabels: b.mutation( {
			query: ( { convId, labels } ) => ( {
				url: `conversations/${ convId }/labels`,
				method: 'POST',
				body: { labels },
			} ),
			invalidatesTags: ( res, err, arg ) => [
				{ type: 'Label', id: `conv-${ arg.convId }` },
				'Conversation',
			],
		} ),

		/* M3.W3 — Custom Attributes (BE envelope: { ok:true, data:{ attributes:[…] } }) */
		getCustomAttrs: b.query( {
			query: ( target ) => 'custom-attributes' + ( target ? `?attr_target=${ target }` : '' ),
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.attributes ) ? r.data.attributes : [];
			},
			providesTags: [ 'Attr' ],
		} ),
		createCustomAttr: b.mutation( {
			query: ( body ) => ( { url: 'custom-attributes', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'Attr' ],
		} ),
		updateCustomAttr: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `custom-attributes/${ id }`, method: 'PUT', body } ),
			invalidatesTags: [ 'Attr' ],
		} ),
		deleteCustomAttr: b.mutation( {
			query: ( { id } ) => ( { url: `custom-attributes/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'Attr' ],
		} ),

		/* M3.W5 — Macros + template render (BE envelope: { ok:true, data:{ macros:[…], count } }) */
		getMacros: b.query( {
			query: () => 'macros',
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.macros ) ? r.data.macros : [];
			},
			providesTags: [ 'Macro' ],
		} ),
		createMacro: b.mutation( {
			query: ( body ) => ( { url: 'macros', method: 'POST', body } ),
			invalidatesTags: [ 'Macro' ],
		} ),
		updateMacro: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `macros/${ id }`, method: 'PUT', body } ),
			invalidatesTags: [ 'Macro' ],
		} ),
		deleteMacro: b.mutation( {
			query: ( { id } ) => ( { url: `macros/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'Macro' ],
		} ),
		previewMacro: b.mutation( {
			query: ( { id, conversation_id } ) => ( {
				url: `macros/${ id }/preview`,
				method: 'POST',
				body: { conversation_id },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		runMacro: b.mutation( {
			query: ( { id, conversation_id } ) => ( {
				url: `macros/${ id }/run`,
				method: 'POST',
				body: { conversation_id },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'Message', id: arg.conversation_id }, 'Conversation' ],
		} ),
		renderTemplate: b.mutation( {
			query: ( { template, conversation_id, contact_id } ) => ( {
				url: 'render-template',
				method: 'POST',
				body: { template, conversation_id, contact_id },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* M4 — Working Hours + SLA */
		getWorkingHours: b.query( {
			query: ( inboxId ) => `working-hours?inbox_id=${ inboxId }`,
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: ( res, err, id ) => [ { type: 'WorkingHours', id } ],
		} ),
		saveWorkingHours: b.mutation( {
			query: ( { inbox_id, rows } ) => ( {
				url: 'working-hours',
				method: 'POST',
				body: { inbox_id, rows },
			} ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'WorkingHours', id: arg.inbox_id } ],
		} ),
		getSlaPolicies: b.query( {
			query: () => 'sla-policies',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'SlaPolicy' ],
		} ),
		createSlaPolicy: b.mutation( {
			query: ( body ) => ( { url: 'sla-policies', method: 'POST', body } ),
			invalidatesTags: [ 'SlaPolicy' ],
		} ),
		updateSlaPolicy: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `sla-policies/${ id }`, method: 'PUT', body } ),
			invalidatesTags: [ 'SlaPolicy' ],
		} ),
		deleteSlaPolicy: b.mutation( {
			query: ( { id } ) => ( { url: `sla-policies/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'SlaPolicy' ],
		} ),
		getConversationSla: b.query( {
			query: ( convId ) => `conversations/${ convId }/sla`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'ConvSla', id } ],
		} ),
		tickSla: b.mutation( {
			query: () => ( { url: 'sla/tick', method: 'POST', body: { force: true } } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* M2 — Automation rules
		 * BE envelopes:
		 *   GET /automation-rules    -> { rules:[…],   count }
		 *   GET /automation-actions  -> { actions:[…] }
		 *   POST /automation-rules/:id/dry-run body: { event_payload: {…} }
		 */
		getAutomationRules: b.query( {
			query: () => 'automation-rules',
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.rules ) ? r.data.rules : [];
			},
			providesTags: [ 'AutomationRule' ],
		} ),
		getAutomationRule: b.query( {
			query: ( id ) => `automation-rules/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'AutomationRule', id } ],
		} ),
		createAutomationRule: b.mutation( {
			query: ( body ) => ( { url: 'automation-rules', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'AutomationRule' ],
		} ),
		updateAutomationRule: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `automation-rules/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [ 'AutomationRule', { type: 'AutomationRule', id: arg.id } ],
		} ),
		deleteAutomationRule: b.mutation( {
			query: ( { id } ) => ( { url: `automation-rules/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'AutomationRule' ],
		} ),
		dryRunAutomationRule: b.mutation( {
			query: ( { id, payload } ) => ( {
				url: `automation-rules/${ id }/dry-run`,
				method: 'POST',
				body: { event_payload: payload || {} },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
		} ),
		getAutomationActions: b.query( {
			query: () => 'automation-actions',
			transformResponse: ( r ) => {
				if ( ! r || ! r.ok ) { return []; }
				if ( Array.isArray( r.data ) ) { return r.data; }
				return Array.isArray( r.data?.actions ) ? r.data.actions : [];
			},
		} ),

		/* M5 — Reports */
		getReportsAggregate: b.query( {
			query: ( { metric, group_by = 'none', from, to, inbox_id, agent_id } ) => {
				const p = new URLSearchParams( { metric, group_by } );
				if ( from ) { p.set( 'from', from ); }
				if ( to )   { p.set( 'to',   to ); }
				if ( inbox_id ) { p.set( 'inbox_id', inbox_id ); }
				if ( agent_id ) { p.set( 'agent_id', agent_id ); }
				return 'reports/aggregate?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsAutoVsHuman: b.query( {
			query: ( { from, to, inbox_id } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to )   { p.set( 'to',   to ); }
				if ( inbox_id ) { p.set( 'inbox_id', inbox_id ); }
				const qs = p.toString();
				return 'reports/auto-vs-human' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		runRollupNow: b.mutation( {
			query: ( body = {} ) => ( { url: 'reports/rollup/run-now', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		// [2026-06-07 Johnny Chu] PHASE-0.40 G3.2 — Deplao parity 6 report endpoints
		getReportsMessage: b.query( {
			query: ( { from, to, days, inbox_id } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				if ( inbox_id ) { p.set( 'inbox_id', inbox_id ); }
				return 'reports/message' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsResponse: b.query( {
			query: ( { from, to, days } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				return 'reports/response' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsAgent: b.query( {
			query: ( { from, to, days } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				return 'reports/agent' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsCampaign: b.query( {
			query: ( { from, to, days } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				return 'reports/campaign' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsWorkflow: b.query( {
			query: ( { from, to, days } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				return 'reports/workflow' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsAi: b.query( {
			query: ( { from, to, days } = {} ) => {
				const p = new URLSearchParams();
				if ( from ) { p.set( 'from', from ); }
				if ( to   ) { p.set( 'to',   to ); }
				if ( days ) { p.set( 'days', days ); }
				return 'reports/ai' + ( p.toString() ? '?' + p.toString() : '' );
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* M-CRM.M8.W5 — Woo Reports Bridge */
		getReportsWooSummary: b.query( {
			query: ( { from = '-30 days', to = 'now' } = {} ) => {
				const p = new URLSearchParams( { from, to } );
				return 'reports/woo-summary?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsWooTopCustomers: b.query( {
			query: ( { from = '-30 days', to = 'now', limit = 10 } = {} ) => {
				const p = new URLSearchParams( { from, to, limit: String( limit ) } );
				return 'reports/woo-top-customers?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsWooByCampaign: b.query( {
			query: ( { from = '-30 days', to = 'now' } = {} ) => {
				const p = new URLSearchParams( { from, to } );
				return 'reports/woo-by-campaign?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getReportsWooTrend: b.query( {
			query: ( { months = 6 } = {} ) => `reports/woo-trend?months=${ months }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		migrateBizContacts: b.mutation( {
			query: ( body = {} ) => ( { url: 'admin/migrate-biz-contacts', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
		} ),
		getCrmContactWooOrders: b.query( {
			query: ( { id, limit = 10 } ) => `crm-contacts/${ id }/woo-orders?limit=${ limit }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* M5.W5 — CSAT */
		submitCsat: b.mutation( {
			query: ( { convId, score } ) => ( {
				url: `csat/${ convId }`,
				method: 'POST',
				body: { score },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* M1.W4 — Snooze.
		 * Body accepts EITHER { duration_seconds:int } (preferred) OR { until: ISO-8601 }.
		 * BE rejects until_ts in the past — both paths are validated server-side. */
		snoozeConversation: b.mutation( {
			query: ( { convId, duration_seconds, until } ) => ( {
				url: `conversations/${ convId }/snooze`,
				method: 'POST',
				body: duration_seconds ? { duration_seconds } : { until },
			} ),
			invalidatesTags: [ 'Conversation' ],
		} ),
		unsnoozeConversation: b.mutation( {
			query: ( { convId } ) => ( { url: `conversations/${ convId }/unsnooze`, method: 'POST' } ),
			invalidatesTags: [ 'Conversation' ],
		} ),

		/* M7.W4 — Inbox health (Channels tab dot + sidebar status). */
		// BE returns: { status:'green'|'yellow'|'red'|'unknown', last_inbound_at, last_error, details }.
		getInboxHealth: b.query( {
			query: ( id ) => `inboxes/${ id }/health`,
			transformResponse: ( r ) => ( r && r.ok && r.data ? r.data : { status: 'unknown' } ),
			providesTags: ( res, err, id ) => [ { type: 'Inbox', id: 'health-' + id } ],
			keepUnusedDataFor: 60,
		} ),

		/* ── PHASE 0.35 M-FE.W17 — CRM Modules (Accounts · Biz-Contacts · Tasks · Events · Documents) ── */

		getCrmAccounts: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.q )        { params.set( 'q',        args.q ); }
				if ( args.status )   { params.set( 'status',   args.status ); }
				if ( args.industry ) { params.set( 'industry', args.industry ); }
				if ( args.limit )    { params.set( 'limit',    args.limit ); }
				if ( args.offset )   { params.set( 'offset',   args.offset ); }
				const qs = params.toString();
				return 'crm-accounts' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.accounts ) ? r.data.accounts : [] ),
			providesTags: [ 'CrmAccount' ],
		} ),
		getCrmAccount: b.query( {
			query: ( id ) => `crm-accounts/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmAccount', id } ],
		} ),
		createCrmAccount: b.mutation( {
			query: ( body ) => ( { url: 'crm-accounts', method: 'POST', body } ),
			invalidatesTags: [ 'CrmAccount' ],
		} ),
		updateCrmAccount: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-accounts/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ 'CrmAccount', { type: 'CrmAccount', id: arg.id } ],
		} ),
		deleteCrmAccount: b.mutation( {
			query: ( id ) => ( { url: `crm-accounts/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmAccount' ],
		} ),

		getCrmContacts: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.q )           { params.set( 'q',           args.q ); }
				if ( args.account_id )  { params.set( 'account_id',  args.account_id ); }
				if ( args.limit )       { params.set( 'limit',        args.limit ); }
				if ( args.offset )      { params.set( 'offset',       args.offset ); }
				// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — channel/source filter
				if ( args.source )      { params.set( 'source',       args.source ); }
				if ( args.cf7_form_id ) { params.set( 'cf7_form_id',  args.cf7_form_id ); }
				// [2026-06-22 Johnny Chu] CF7-CONTACTS-FIX — forward view param (archived/active)
				if ( args.view )        { params.set( 'view',         args.view ); }
				const qs = params.toString();
				return 'crm-contacts' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.contacts ) ? r.data.contacts : [] ),
			providesTags: [ 'CrmContact' ],
		} ),

		// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — source catalog (CF7 forms + channels)
		getCrmContactSources: b.query( {
			query: () => 'crm-contacts/sources',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { cf7_total: 0, cf7_forms: [], channels: {} } ),
			providesTags: [ 'CrmContact' ],
		} ),
		getCrmContact: b.query( {
			query: ( id ) => `crm-contacts/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmContact', id } ],
		} ),
		createCrmContact: b.mutation( {
			query: ( body ) => ( { url: 'crm-contacts', method: 'POST', body } ),
			invalidatesTags: [ 'CrmContact' ],
		} ),
		updateCrmContact: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-contacts/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ 'CrmContact', { type: 'CrmContact', id: arg.id } ],
		} ),
		deleteCrmContact: b.mutation( {
			query: ( id ) => ( { url: `crm-contacts/${ id }`, method: 'DELETE' } ),
			invalidateTags: [ 'CrmContact' ],
		} ),

		// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — archive / unarchive (tag-based, no DB schema change)
		archiveCrmContact: b.mutation( {
			query: ( id ) => ( { url: `crm-contacts/${ id }/archive`, method: 'POST' } ),
			invalidatesTags: [ 'CrmContact' ],
		} ),
		unarchiveCrmContact: b.mutation( {
			query: ( id ) => ( { url: `crm-contacts/${ id }/unarchive`, method: 'POST' } ),
			invalidatesTags: [ 'CrmContact' ],
		} ),

		getCrmTasks: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.q )           { params.set( 'q',           args.q ); }
				if ( args.status )      { params.set( 'status',      args.status ); }
				if ( args.assignee_id ) { params.set( 'assignee_id', args.assignee_id ); }
				// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — overdue filter
				if ( args.due_before )  { params.set( 'due_before',  args.due_before ); }
				if ( args.limit )       { params.set( 'limit',       args.limit ); }
				if ( args.offset )      { params.set( 'offset',      args.offset ); }
				const qs = params.toString();
				return 'crm-tasks' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.tasks ) ? r.data.tasks : [] ),
			providesTags: [ 'CrmTask' ],
		} ),
		getCrmTask: b.query( {
			query: ( id ) => `crm-tasks/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmTask', id } ],
		} ),
		createCrmTask: b.mutation( {
			query: ( body ) => ( { url: 'crm-tasks', method: 'POST', body } ),
			invalidatesTags: [ 'CrmTask' ],
		} ),
		updateCrmTask: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-tasks/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ 'CrmTask', { type: 'CrmTask', id: arg.id } ],
		} ),
		deleteCrmTask: b.mutation( {
			query: ( id ) => ( { url: `crm-tasks/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmTask' ],
		} ),

		getCrmEvents: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.from )  { params.set( 'from',  args.from ); }
				if ( args.to )    { params.set( 'to',    args.to ); }
				if ( args.limit ) { params.set( 'limit', args.limit ); }
				const qs = params.toString();
				return 'crm-events' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.events ) ? r.data.events : [] ),
			providesTags: [ 'CrmEvent' ],
		} ),
		getCrmEvent: b.query( {
			query: ( id ) => `crm-events/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmEvent', id } ],
		} ),
		createCrmEvent: b.mutation( {
			query: ( body ) => ( { url: 'crm-events', method: 'POST', body } ),
			invalidatesTags: [ 'CrmEvent' ],
		} ),
		updateCrmEvent: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-events/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ 'CrmEvent', { type: 'CrmEvent', id: arg.id } ],
		} ),
		deleteCrmEvent: b.mutation( {
			query: ( id ) => ( { url: `crm-events/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmEvent' ],
		} ),

		getCrmDocuments: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.related_entity_type ) { params.set( 'related_entity_type', args.related_entity_type ); }
				if ( args.related_entity_id )   { params.set( 'related_entity_id',   args.related_entity_id ); }
				if ( args.limit )               { params.set( 'limit',               args.limit ); }
				if ( args.offset )              { params.set( 'offset',              args.offset ); }
				const qs = params.toString();
				return 'crm-documents' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.documents ) ? r.data.documents : [] ),
			providesTags: [ 'CrmDocument' ],
		} ),
		getCrmDocument: b.query( {
			query: ( id ) => `crm-documents/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmDocument', id } ],
		} ),
		createCrmDocument: b.mutation( {
			query: ( body ) => ( { url: 'crm-documents', method: 'POST', body } ),
			invalidatesTags: [ 'CrmDocument' ],
		} ),
		deleteCrmDocument: b.mutation( {
			query: ( id ) => ( { url: `crm-documents/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmDocument' ],
		} ),

		// [2026-06-07 Johnny Chu] PHASE-0.40 G6.4 — Notes Doc CRUD hooks
		getCrmNotesDocs: b.query( {
			query: ( { folder = '', q = '', pinned = undefined, limit = 50, offset = 0 } = {} ) => {
				const p = new URLSearchParams( { limit, offset } );
				if ( folder ) { p.set( 'folder', folder ); }
				if ( q )      { p.set( 'q', q ); }
				if ( pinned !== undefined ) { p.set( 'pinned', pinned ? '1' : '0' ); }
				return 'crm-notes-doc?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? ( r.data?.notes || [] ) : [] ),
			providesTags: [ 'CrmNoteDoc' ],
		} ),
		getCrmNoteDoc: b.query( {
			query: ( id ) => `crm-notes-doc/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( r ) => r ? [ { type: 'CrmNoteDoc', id: r.id } ] : [],
		} ),
		createCrmNoteDoc: b.mutation( {
			query: ( body ) => ( { url: 'crm-notes-doc', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'CrmNoteDoc' ],
		} ),
		updateCrmNoteDoc: b.mutation( {
			query: ( { id, ...patch } ) => ( { url: `crm-notes-doc/${ id }`, method: 'PATCH', body: patch } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( r ) => r ? [ 'CrmNoteDoc', { type: 'CrmNoteDoc', id: r.id } ] : [ 'CrmNoteDoc' ],
		} ),
		deleteCrmNoteDoc: b.mutation( {
			query: ( id ) => ( { url: `crm-notes-doc/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmNoteDoc' ],
		} ),

		/* ============ M-CRM.M1 — Sales Pipeline ============ */

		/* Leads */
		getCrmLeads: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.status )     { params.set( 'status', args.status ); }
				if ( args.owner_id )   { params.set( 'owner_id', args.owner_id ); }
				if ( args.contact_id ) { params.set( 'contact_id', args.contact_id ); }
				// [2026-06-13 Johnny Chu] PHASE-0.45 — channel/source filter
				if ( args.source )     { params.set( 'source', args.source ); }
				if ( args.q )          { params.set( 'q', args.q ); }
				if ( args.limit )      { params.set( 'limit', args.limit ); }
				if ( args.offset )     { params.set( 'offset', args.offset ); }
				const qs = params.toString();
				return 'crm-leads' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( Array.isArray( r?.data?.leads ) ? r.data.leads : [] ),
			providesTags: [ 'CrmLead' ],
		} ),
		getCrmLead: b.query( {
			query: ( id ) => `crm-leads/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmLead', id } ],
		} ),
		createCrmLead: b.mutation( {
			query: ( body ) => ( { url: 'crm-leads', method: 'POST', body } ),
			invalidatesTags: [ 'CrmLead' ],
		} ),
		updateCrmLead: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-leads/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmLead', id: arg.id }, 'CrmLead' ],
		} ),
		deleteCrmLead: b.mutation( {
			query: ( id ) => ( { url: `crm-leads/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmLead' ],
		} ),
		convertCrmLead: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-leads/${ id }/convert`, method: 'POST', body } ),
			invalidatesTags: [ 'CrmLead', 'CrmAccount', 'CrmContact', 'CrmOpportunity' ],
		} ),

		/* M-Bridge.W2 — Convert active inbox conversation → CRM Lead.
		 * Body: { first_name?, last_name?, email?, phone?, company?, source?, notes? }
		 * Returns: shape_crm_lead + { existing:bool }  (existing=true means BE
		 *   surfaced a pre-existing open lead for this contact, no new row created). */
		convertConvToLead: b.mutation( {
			query: ( { convId, ...body } ) => ( {
				url: `conversations/${ convId }/convert-to-lead`,
				method: 'POST',
				body,
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'CrmLead' ],
		} ),

		/* Opportunities */
		getCrmOpportunities: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.stage )      { params.set( 'stage', args.stage ); }
				if ( args.status )     { params.set( 'status', args.status ); }
				if ( args.owner_id )   { params.set( 'owner_id', args.owner_id ); }
				if ( args.account_id ) { params.set( 'account_id', args.account_id ); }
				// [2026-06-13 Johnny Chu] PHASE-0.45 — channel/source filter
				if ( args.source )     { params.set( 'source', args.source ); }
				if ( args.q )          { params.set( 'q', args.q ); }
				if ( args.limit )      { params.set( 'limit', args.limit ); }
				if ( args.offset )     { params.set( 'offset', args.offset ); }
				const qs = params.toString();
				return 'crm-opportunities' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( Array.isArray( r?.data?.opportunities ) ? r.data.opportunities : [] ),
			providesTags: [ 'CrmOpportunity' ],
		} ),
		getCrmOpportunity: b.query( {
			query: ( id ) => `crm-opportunities/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmOpportunity', id } ],
		} ),
		createCrmOpportunity: b.mutation( {
			query: ( body ) => ( { url: 'crm-opportunities', method: 'POST', body } ),
			invalidatesTags: [ 'CrmOpportunity' ],
		} ),
		updateCrmOpportunity: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-opportunities/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmOpportunity', id: arg.id }, 'CrmOpportunity' ],
		} ),
		deleteCrmOpportunity: b.mutation( {
			query: ( id ) => ( { url: `crm-opportunities/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmOpportunity' ],
		} ),
		getOpportunityLines: b.query( {
			query: ( id ) => `crm-opportunities/${ id }/lines`,
			transformResponse: ( r ) => ( Array.isArray( r?.data?.lines ) ? r.data.lines : [] ),
			providesTags: ( res, err, id ) => [ { type: 'CrmOppLine', id } ],
		} ),
		putOpportunityLines: b.mutation( {
			query: ( { id, lines } ) => ( { url: `crm-opportunities/${ id }/lines`, method: 'PUT', body: { lines } } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmOppLine', id: arg.id }, { type: 'CrmOpportunity', id: arg.id }, 'CrmOpportunity' ],
		} ),

		/* Contracts */
		getCrmContracts: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.status )     { params.set( 'status', args.status ); }
				if ( args.owner_id )   { params.set( 'owner_id', args.owner_id ); }
				if ( args.account_id ) { params.set( 'account_id', args.account_id ); }
				if ( args.q )          { params.set( 'q', args.q ); }
				if ( args.limit )      { params.set( 'limit', args.limit ); }
				if ( args.offset )     { params.set( 'offset', args.offset ); }
				const qs = params.toString();
				return 'crm-contracts' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( Array.isArray( r?.data?.contracts ) ? r.data.contracts : [] ),
			providesTags: [ 'CrmContract' ],
		} ),
		getCrmContract: b.query( {
			query: ( id ) => `crm-contracts/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmContract', id } ],
		} ),
		createCrmContract: b.mutation( {
			query: ( body ) => ( { url: 'crm-contracts', method: 'POST', body } ),
			invalidatesTags: [ 'CrmContract' ],
		} ),
		updateCrmContract: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-contracts/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmContract', id: arg.id }, 'CrmContract' ],
		} ),
		deleteCrmContract: b.mutation( {
			query: ( id ) => ( { url: `crm-contracts/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmContract' ],
		} ),
		getContractLines: b.query( {
			query: ( id ) => `crm-contracts/${ id }/lines`,
			transformResponse: ( r ) => ( Array.isArray( r?.data?.lines ) ? r.data.lines : [] ),
			providesTags: ( res, err, id ) => [ { type: 'CrmContractLine', id } ],
		} ),
		putContractLines: b.mutation( {
			query: ( { id, lines } ) => ( { url: `crm-contracts/${ id }/lines`, method: 'PUT', body: { lines } } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmContractLine', id: arg.id }, { type: 'CrmContract', id: arg.id }, 'CrmContract' ],
		} ),

		/* M-CRM.M1.W2 — Product Catalog + Categories */
		getCrmProductCategories: b.query( {
			query: ( params = {} ) => ( { url: 'crm-product-categories', params } ),
			transformResponse: ( r ) => ( Array.isArray( r?.data?.categories ) ? r.data.categories : [] ),
			providesTags: [ 'CrmProductCategory' ],
		} ),
		createCrmProductCategory: b.mutation( {
			query: ( body ) => ( { url: 'crm-product-categories', method: 'POST', body } ),
			invalidatesTags: [ 'CrmProductCategory' ],
		} ),
		updateCrmProductCategory: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-product-categories/${ id }`, method: 'PUT', body } ),
			invalidatesTags: [ 'CrmProductCategory' ],
		} ),
		deleteCrmProductCategory: b.mutation( {
			query: ( id ) => ( { url: `crm-product-categories/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmProductCategory' ],
		} ),

		getCrmProducts: b.query( {
			query: ( params = {} ) => ( { url: 'crm-products', params } ),
			transformResponse: ( r ) => ( Array.isArray( r?.data?.products ) ? r.data.products : [] ),
			providesTags: [ 'CrmProduct' ],
		} ),
		getCrmProduct: b.query( {
			query: ( id ) => `crm-products/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmProduct', id } ],
		} ),
		createCrmProduct: b.mutation( {
			query: ( body ) => ( { url: 'crm-products', method: 'POST', body } ),
			invalidatesTags: [ 'CrmProduct' ],
		} ),
		updateCrmProduct: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-products/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmProduct', id: arg.id }, 'CrmProduct' ],
		} ),
		deleteCrmProduct: b.mutation( {
			query: ( id ) => ( { url: `crm-products/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmProduct' ],
		} ),

		/* ========= M-CRM.M2 — Invoicing ========= */
		getCrmInvoices: b.query( {
			query: ( params = {} ) => ( { url: 'crm-invoices', params } ),
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'CrmInvoice' ],
		} ),
		getCrmInvoice: b.query( {
			query: ( id ) => `crm-invoices/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmInvoice', id } ],
		} ),
		createCrmInvoice: b.mutation( {
			query: ( body ) => ( { url: 'crm-invoices', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
			invalidatesTags: [ 'CrmInvoice' ],
		} ),
		updateCrmInvoice: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-invoices/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmInvoice', id: arg.id }, 'CrmInvoice' ],
		} ),
		deleteCrmInvoice: b.mutation( {
			query: ( id ) => ( { url: `crm-invoices/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmInvoice' ],
		} ),
		transitionCrmInvoice: b.mutation( {
			query: ( { id, status } ) => ( { url: `crm-invoices/${ id }/transition`, method: 'POST', body: { status } } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmInvoice', id: arg.id }, 'CrmInvoice' ],
		} ),
		addCrmInvoicePayment: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-invoices/${ id }/payments`, method: 'POST', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmInvoice', id: arg.id }, 'CrmInvoice' ],
		} ),
		deleteCrmInvoicePayment: b.mutation( {
			query: ( pid ) => ( { url: `crm-invoices/payments/${ pid }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmInvoice' ],
		} ),
		sendCrmInvoice: b.mutation( {
			query: ( { id, to, subject } ) => ( { url: `crm-invoices/${ id }/send`, method: 'POST', body: { to, subject } } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmInvoice', id: arg.id }, 'CrmInvoice' ],
		} ),

		/* ========= M-CRM.M3 — Email Client ========= */
		getCrmEmailAccounts: b.query( {
			query: () => 'crm-email-accounts',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'CrmEmailAccount' ],
		} ),
		getCrmEmailAccount: b.query( {
			query: ( id ) => `crm-email-accounts/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmEmailAccount', id } ],
		} ),
		createCrmEmailAccount: b.mutation( {
			query: ( body ) => ( { url: 'crm-email-accounts', method: 'POST', body } ),
			invalidatesTags: [ 'CrmEmailAccount' ],
		} ),
		updateCrmEmailAccount: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `crm-email-accounts/${ id }`, method: 'PUT', body } ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CrmEmailAccount', id: arg.id }, 'CrmEmailAccount' ],
		} ),
		deleteCrmEmailAccount: b.mutation( {
			query: ( id ) => ( { url: `crm-email-accounts/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'CrmEmailAccount' ],
		} ),
		syncCrmEmailAccount: b.mutation( {
			query: ( id ) => ( { url: `crm-email-accounts/${ id }/sync`, method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CrmEmailAccount', id }, 'CrmEmailThread' ],
		} ),
		// Auto-provision IMAP account from Core SMTP credentials (same App Password).
		importCrmEmailAccountFromSmtp: b.mutation( {
			query: () => ( { url: 'crm-email-accounts/from-smtp', method: 'POST' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'CrmEmailAccount' ],
		} ),
		// Test IMAP connection for an existing account without side effects.
		testCrmEmailAccountImap: b.mutation( {
			query: ( id ) => ( { url: `crm-email-accounts/${ id }/test-imap`, method: 'POST' } ),
			transformResponse: ( r ) => r,  // returns { ok, message } directly
		} ),
		getCrmEmailThreads: b.query( {
			query: ( params = {} ) => ( { url: 'crm-email-threads', params } ),
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'CrmEmailThread' ],
		} ),
		getCrmEmailThread: b.query( {
			query: ( id ) => `crm-email-threads/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmEmailThread', id } ],
		} ),
		markCrmEmailThreadRead: b.mutation( {
			query: ( id ) => ( { url: `crm-email-threads/${ id }/read`, method: 'POST' } ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CrmEmailThread', id }, 'CrmEmailThread' ],
		} ),
		sendCrmEmail: b.mutation( {
			query: ( body ) => ( { url: 'crm-email-send', method: 'POST', body } ),
			invalidatesTags: [ 'CrmEmailThread' ],
		} ),
		getCrmSmtpStatus: b.query( {
			query: () => 'crm-smtp-status',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { configured: false, preview: {} } ),
		} ),

		/* ========= PHASE 0.37.1 — Gmail SMTP + Email Automation ========= */
		getGmailSmtpAccounts: b.query( {
			query: () => 'gmail-smtp-accounts',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'GmailSmtp' ],
		} ),
		createGmailSmtpAccount: b.mutation( {
			query: ( body ) => ( { url: 'gmail-smtp-accounts', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'GmailSmtp' ],
		} ),
		updateGmailSmtpAccount: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `gmail-smtp-accounts/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'GmailSmtp' ],
		} ),
		deleteGmailSmtpAccount: b.mutation( {
			query: ( id ) => ( { url: `gmail-smtp-accounts/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'GmailSmtp' ],
		} ),
		testGmailSmtpAccount: b.mutation( {
			query: ( { id, to } ) => ( { url: `gmail-smtp-accounts/${ id }/test`, method: 'POST', body: { to } } ),
			transformResponse: ( r ) => r,
		} ),
		promoteGmailSmtpAccount: b.mutation( {
			query: ( id ) => ( { url: `gmail-smtp-accounts/${ id }/promote`, method: 'POST' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),
		getEmailEvents: b.query( {
			query: () => 'email-events',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
		} ),
		getEmailEventRules: b.query( {
			query: ( params = {} ) => ( { url: 'email-event-rules', params } ),
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'EmailEventRule' ],
		} ),
		createEmailEventRule: b.mutation( {
			query: ( body ) => ( { url: 'email-event-rules', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'EmailEventRule' ],
		} ),
		updateEmailEventRule: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `email-event-rules/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'EmailEventRule' ],
		} ),
		deleteEmailEventRule: b.mutation( {
			query: ( id ) => ( { url: `email-event-rules/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'EmailEventRule' ],
		} ),
		testEmailEventRule: b.mutation( {
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — pass test_to so BE resolves real email
			query: ( { id, ctx, test_to } ) => ( { url: `email-event-rules/${ id }/test`, method: 'POST', body: { ctx: ctx || {}, test_to: test_to || '' } } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
		} ),

		// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — Email send log
		getEmailSendLogs: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.status )    { p.set( 'status',    args.status ); }
				if ( args.rule_id )   { p.set( 'rule_id',   args.rule_id ); }
				if ( args.event_key ) { p.set( 'event_key', args.event_key ); }
				if ( args.date_from ) { p.set( 'date_from', args.date_from ); }
				if ( args.date_to )   { p.set( 'date_to',   args.date_to ); }
				if ( args.page )      { p.set( 'page',      args.page ); }
				if ( args.per_page )  { p.set( 'per_page',  args.per_page ); }
				p.set( 'is_test', args.is_test ?? 0 );
				return 'email-send-logs?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : { rows: [], total: 0 } ),
			providesTags: [ 'EmailSendLog' ],
		} ),
		getEmailSendLogStats: b.query( {
			query: ( period = '7d' ) => `email-send-logs/stats?period=${ period }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : {} ),
			providesTags: [ 'EmailSendLog' ],
		} ),
		// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — per-CF7-form campaign stats
		getEmailCf7CampaignStats: b.query( {
			query: ( period = '7d' ) => `email-send-logs/cf7-campaigns?period=${ period }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : { forms: [], period: '7d' } ),
			providesTags: [ 'EmailSendLog' ],
		} ),
		// [2026-06-20 Johnny Chu] HOTFIX — test-send from CRM email debug panel
		testEmailSmtpSend: b.mutation( {
			query: ( body ) => ( { url: 'email-smtp/test-send', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
			invalidatesTags: [ 'EmailSendLog' ],
		} ),
		// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — CF7 Automation stats
		getCf7AutomationStats: b.query( {
			query: ( days ) => `cf7-automation/stats?days=${ days || 30 }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: [ 'EmailEventRule', 'EmailSendLog' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS (Zalo ZNS) send log hooks
		getZnsSendLogs: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.status )    { p.set( 'status',    args.status ); }
				if ( args.form_id )   { p.set( 'form_id',   args.form_id ); }
				if ( args.date_from ) { p.set( 'date_from', args.date_from ); }
				if ( args.date_to )   { p.set( 'date_to',   args.date_to ); }
				if ( args.page )      { p.set( 'page',      args.page ); }
				if ( args.per_page )  { p.set( 'per_page',  args.per_page ); }
				p.set( 'is_test', args.is_test ?? -1 );
				return 'zns-send-logs?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : { rows: [], total: 0 } ),
			providesTags: [ 'ZnsSendLog' ],
		} ),
		getZnsSendLogStats: b.query( {
			query: ( period = '7d' ) => `zns-send-logs/stats?period=${ period }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : { total_sent: 0, total_failed: 0, success_rate: 0, by_day: [], by_form: [] } ),
			providesTags: [ 'ZnsSendLog' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS send log hooks
		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — standalone test-send (Sandbox=1) from CRM tab
		testZnsSend: b.mutation( {
			query: ( body ) => ( { url: 'zns-send-logs/test', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : r ),
			invalidatesTags: [ 'ZnsSendLog' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Fetch Zalo OA accounts from Channel Gateway
		// Uses cgBaseQuery (bizcity-channel/v1) via custom queryFn
		getZaloOaAccounts: b.query( {
			queryFn: async ( _arg, _api, _extra, _baseQuery ) => {
				try {
					const res = await cgBaseQuery( 'cf7/zns-oa-accounts', _api, _extra );
					if ( res.error ) { return { data: [] }; }
					const d = res.data;
					return { data: ( d && d.success ? d.data || [] : [] ) };
				} catch ( e ) {
					return { data: [] };
				}
			},
			providesTags: [ 'ZnsSendLog' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — CF7 Submissions tab
		getCf7SubmissionForms: b.query( {
			query: () => 'cf7-submissions/forms',
			transformResponse: ( r ) => ( r && r.ok ? r.data : [] ),
			providesTags: [ 'Cf7Submission' ],
		} ),
		getCf7Submissions: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.form_id )      { p.set( 'form_id',      args.form_id ); }
				if ( args.crm_action )   { p.set( 'crm_action',   args.crm_action ); }
				if ( args.from )         { p.set( 'from',         args.from ); }
				if ( args.to )           { p.set( 'to',           args.to ); }
				if ( args.page )         { p.set( 'page',         args.page ); }
				if ( args.per_page )     { p.set( 'per_page',     args.per_page ); }
				// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — pass unified submission filters
				if ( args.follow_status ) { p.set( 'follow_status', args.follow_status ); }
				if ( args.source_type )   { p.set( 'source_type',   args.source_type ); }
				if ( args.assignee_id )   { p.set( 'assignee_id',   args.assignee_id ); }
				return 'cf7-submissions?' + p.toString();
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : { rows: [], total: 0, pages: 1 } ),
			providesTags: ( res, err, args ) => [ 'Cf7Submission', 'CrmSubmission' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — analytics stats via Channel Gateway
		getCf7SubmissionsStats: b.query( {
			queryFn: async ( args = {}, _api, _extra ) => {
				const p = new URLSearchParams();
				if ( args.days )    { p.set( 'days',    args.days ); }
				if ( args.form_id ) { p.set( 'form_id', args.form_id ); }
				const res = await cgBaseQuery( { url: 'cf7/submissions/stats?' + p.toString() }, _api, _extra );
				if ( res.error ) {
					return { data: { totals: { total: 0, created: 0, updated: 0, skipped: 0, error: 0 }, by_date: [], by_form: [] } };
				}
				const d = res.data;
				return { data: ( d && d.success ? d.data : { totals: { total: 0, created: 0, updated: 0, skipped: 0, error: 0 }, by_date: [], by_form: [] } ) };
			},
			providesTags: [ 'Cf7Submission' ],
		} ),

		// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — export all rows via Channel Gateway
		getCf7SubmissionsExport: b.query( {
			queryFn: async ( args = {}, _api, _extra ) => {
				const p = new URLSearchParams();
				if ( args.form_id )    { p.set( 'form_id',    args.form_id ); }
				if ( args.crm_action ) { p.set( 'crm_action', args.crm_action ); }
				if ( args.from )       { p.set( 'from',       args.from ); }
				if ( args.to )         { p.set( 'to',         args.to ); }
				const res = await cgBaseQuery( { url: 'cf7/submissions/export?' + p.toString() }, _api, _extra );
				if ( res.error ) { return { data: [] }; }
				const d = res.data;
				return { data: ( d && d.success ? d.data || [] : [] ) };
			},
		} ),

		// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — GET /cf7-submissions/{id}/activities
		getSubmissionActivities: b.query( {
			query: ( { id, limit = 100, offset = 0 } ) =>
				`cf7-submissions/${ id }/activities?limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data?.activities ) ? r.data.activities : [] ),
			providesTags: ( res, err, { id } ) => [ { type: 'Cf7Submission', id: `acts-${ id }` } ],
		} ),

		// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — POST /cf7-submissions/{id}/activities
		createSubmissionActivity: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `cf7-submissions/${ id }/activities`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [
				{ type: 'Cf7Submission', id: `acts-${ id }` },
				'Cf7Submission',
			],
		} ),

		// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — GET /cf7-submissions/activities/stats
		getSubmissionActivityStats: b.query( {
			query: () => 'cf7-submissions/activities/stats',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { by_type: [], total: 0 } ),
			providesTags: [ 'Cf7Submission' ],
		} ),

		/* ------------------------------------------------------------------
		 * PHASE 0.35 M6.W8 — Campaign Authoring UI
		 * BE wired in M6.W1 (CRUD + /stats) and M6.W2 (/url + /qr.svg + /qr.png).
		 * ------------------------------------------------------------------ */
		getCampaigns: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.status ) { params.set( 'status', args.status ); }
				if ( args.q ) { params.set( 'q', args.q ); }
				if ( args.limit ) { params.set( 'limit', args.limit ); }
				const qs = params.toString();
				return 'campaigns' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'Campaign' ],
		} ),
		getCampaign: b.query( {
			query: ( id ) => `campaigns/${ id }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'Campaign', id } ],
		} ),
		createCampaign: b.mutation( {
			query: ( body ) => ( { url: 'campaigns', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'Campaign' ],
		} ),
		updateCampaign: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `campaigns/${ id }`, method: 'PATCH', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'Campaign', id: arg.id }, 'Campaign' ],
		} ),
		deleteCampaign: b.mutation( {
			query: ( { id } ) => ( { url: `campaigns/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'Campaign' ],
		} ),
		getCampaignStats: b.query( {
			query: ( id ) => `campaigns/${ id }/stats`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'Campaign', id: `stats-${ id }` } ],
		} ),
		getCampaignUrl: b.query( {
			query: ( id ) => `campaigns/${ id }/url`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* PHASE 0.35 M6.W7 — Funnel report (4 cards) */
		getCampaignFunnel: b.query( {
			query: ( id ) => `campaigns/${ id }/funnel`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CampaignFunnel', id } ],
		} ),

		/* PHASE 0.35 M6.W9 — Dropdowns helper for the form */
		getCampaignDropdowns: b.query( {
			query: ( id ) => `campaigns/${ id }/dropdowns`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : { templates: [], characters: [], notebooks: [], shortcodes: [], reminder_units: [ 'minutes', 'hours', 'days' ], action_types: [ 'send_message', 'run_shortcode', 'kg_grounded_reply', 'delay_only' ] } ),
			providesTags: [ 'CampaignDropdowns' ],
		} ),

		/* PHASE 0.35 M6.W11 — Messenger m.me link (W16 LinkBox) */
		getCampaignMessengerLink: b.query( {
			query: ( { id, page_id } = {} ) => {
				const qs = page_id ? `?page_id=${ encodeURIComponent( page_id ) }` : '';
				return `campaigns/${ id }/messenger-link${ qs }`;
			},
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* PHASE 0.35 M6.W11 — Preview rendered prompt (W15/W16 prompt-preview button) */
		previewCampaignPrompt: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `campaigns/${ id }/preview-prompt`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* PHASE 0.35 M6.W18-W22 — Marketing Asset Studio */
		getBrandKit: b.query( {
			query: () => 'marketing/brand-kit',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { kit: {}, hash: '' } ),
			providesTags: [ 'BrandKit' ],
		} ),
		updateBrandKit: b.mutation( {
			query: ( body ) => ( { url: 'marketing/brand-kit', method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'BrandKit', 'CampaignAssetManifest' ],
		} ),
		getMarketingTemplates: b.query( {
			query: () => 'marketing/templates',
			transformResponse: ( r ) => ( r && r.ok ? r.data : { templates: [], formats: [] } ),
		} ),
		getCampaignAssetManifest: b.query( {
			query: ( id ) => `campaigns/${ id }/assets/manifest`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CampaignAssetManifest', id } ],
		} ),
		regenerateCampaignAsset: b.mutation( {
			query: ( { id, key } ) => ( { url: `campaigns/${ id }/assets/${ key }/regenerate`, method: 'POST' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'CampaignAssetManifest', id: arg && arg.id } ],
		} ),

		/* PHASE 0.42 M-PA.W2 — Campaign Print-Ads (voucher / print ad / QR card / business card / event invite) */
		getPrintAdsTemplates: b.query( {
			query: ( { id, type } = {} ) => {
				const qs = type ? `?type=${ encodeURIComponent( type ) }` : '';
				return `campaigns/${ id }/print-ads/templates${ qs }`;
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.templates ) ? r.templates : [] ),
			providesTags: [ 'PrintAdsTemplates' ],
		} ),
		getPrintAdsGenerations: b.query( {
			query: ( { id, limit } = {} ) => {
				const qs = limit ? `?limit=${ encodeURIComponent( limit ) }` : '';
				return `campaigns/${ id }/print-ads${ qs }`;
			},
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.generations ) ? r.generations : [] ),
			providesTags: ( res, err, arg ) => [ { type: 'PrintAdsGenerations', id: arg && arg.id } ],
		} ),
		generatePrintAd: b.mutation( {
			query: ( { id, template_id, overrides } ) => ( {
				url: `campaigns/${ id }/print-ads/generate`,
				method: 'POST',
				body: { template_id, overrides: overrides || {} },
			} ),
			transformResponse: ( r ) => ( r && r.ok ? r : null ),
			invalidatesTags: ( res, err, arg ) => [ { type: 'PrintAdsGenerations', id: arg && arg.id } ],
		} ),

		/* PHASE 0.35 M6.W5 — Loyalty (award + balance) */
		awardLoyalty: b.mutation( {
			query: ( body ) => ( { url: 'loyalty/award', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: ( res, err, arg ) => arg && arg.contact_id
				? [ { type: 'LoyaltyBalance', id: arg.contact_id }, { type: 'CampaignFunnel', id: arg.campaign_id || 'all' } ]
				: [ 'LoyaltyBalance' ],
		} ),
		getLoyaltyBalance: b.query( {
			query: ( contactId ) => `loyalty/balance/${ contactId }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			providesTags: ( res, err, contactId ) => [ { type: 'LoyaltyBalance', id: contactId } ],
		} ),

		/* PHASE 0.35 M6.W6 — BizGPT flow importer */
		previewFlowImport: b.query( {
			query: ( limit = 100 ) => `flows/import/preview?limit=${ limit }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : { available: false, flows: [] } ),
			providesTags: [ 'BizGptFlow' ],
		} ),
		importFlows: b.mutation( {
			query: ( body ) => ( { url: 'flows/import', method: 'POST', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'BizGptFlow', 'Macro', 'AutomationRule' ],
		} ),

		// Funnel dashboard — bundled aggregator
		getFunnelOverview: b.query( {
			query: ( days = 7 ) => `dashboard/funnel-overview?days=${ days }`,
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
		} ),

		/* PHASE 3.5 Wave B — Admin Chat grants (3-axis delegation). */
		getAdminChatGrantsVersion: b.query( {
			query: () => 'admin-chat-grants/version',
			transformResponse: ( r ) => ( r && r.ok && r.data ? r.data : { version: 0, pending: 0 } ),
			providesTags: [ 'AdminChatGrantVersion' ],
		} ),
		getAdminChatGrants: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.status ) { params.set( 'status', args.status ); }
				if ( args.limit )  { params.set( 'limit', args.limit ); }
				const qs = params.toString();
				return 'admin-chat-grants' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && r.data ? r.data : { rows: [], counts: { pending: 0, active: 0, revoked: 0 }, version: 0 } ),
			providesTags: [ 'AdminChatGrant' ],
			keepUnusedDataFor: 600,
		} ),
		approveAdminChatGrant: b.mutation( {
			query: ( { id } ) => ( { url: `admin-chat-grants/${ id }/approve`, method: 'POST' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'AdminChatGrant', 'AdminChatGrantVersion' ],
		} ),
		revokeAdminChatGrant: b.mutation( {
			query: ( { id } ) => ( { url: `admin-chat-grants/${ id }/revoke`, method: 'POST' } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'AdminChatGrant', 'AdminChatGrantVersion' ],
		} ),
		updateAdminChatGrant: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `admin-chat-grants/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r && r.ok ? r.data : null ),
			invalidatesTags: [ 'AdminChatGrant', 'AdminChatGrantVersion' ],
		} ),

		// M-CRM.M1.W3 — Audit log (v1.17.0)
		getEntityAuditLog: b.query( {
			query: ( { entity_type, entity_id, limit = 50, offset = 0 } ) =>
				`audit?entity_type=${ entity_type }&entity_id=${ entity_id }&limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => {
				if ( ! r?.ok ) return { entries: [], total: 0 };
				return r.data || { entries: [], total: 0 };
			},
			providesTags: ( res, err, { entity_type, entity_id } ) => [
				{ type: 'CrmAuditLog', id: `${ entity_type }:${ entity_id }` },
			],
		} ),

		// [2026-06-07 Johnny Chu] PHASE-3.5-WC — Admin-chat audit log (v1.22.0)
		getAdminChatAudit: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				Object.entries( args ).forEach( ( [ k, v ] ) => { if ( v !== undefined && v !== '' ) p.set( k, v ); } );
				return 'admin-chat-audit?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data || { rows: [], total: 0 } : { rows: [], total: 0 } ),
			providesTags: [ 'AdminChatAudit' ],
		} ),

		// M-CRM.M4.Inbox v1.18.0 — Broadcasts
		getBroadcasts: b.query( {
			query: ( args = {} ) => `broadcasts?${ new URLSearchParams( Object.fromEntries( Object.entries( args ).filter( ( [ , v ] ) => v !== undefined && v !== '' ) ) ).toString() }`,
			transformResponse: ( r ) => ( r?.ok ? r.data : { items: [], total: 0 } ),
			providesTags: [ 'Broadcast' ],
		} ),
		getBroadcast: b.query( {
			query: ( id ) => `broadcasts/${ id }`,
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'Broadcast', id } ],
		} ),
		createBroadcast: b.mutation( {
			query: ( body ) => ( { url: 'broadcasts', method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'Broadcast' ],
		} ),
		updateBroadcast: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `broadcasts/${ id }`, method: 'PUT', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'Broadcast', id }, 'Broadcast' ],
		} ),
		deleteBroadcast: b.mutation( {
			query: ( id ) => ( { url: `broadcasts/${ id }`, method: 'DELETE' } ),
			invalidatesTags: [ 'Broadcast' ],
		} ),
		sendBroadcast: b.mutation( {
			query: ( id ) => ( { url: `broadcasts/${ id }/send`, method: 'POST' } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, id ) => [ { type: 'Broadcast', id }, 'Broadcast' ],
		} ),
		getBroadcastRecipients: b.query( {
			query: ( { id, limit = 50, offset = 0 } ) => `broadcasts/${ id }/recipients?limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => ( r?.ok ? r.data : { items: [], total: 0 } ),
			providesTags: ( res, err, { id } ) => [ { type: 'Broadcast', id: `recipients:${ id }` } ],
		} ),
		// [2026-06-07 Johnny Chu] PHASE-0.43 M4 — Broadcast Mass-Send new endpoints
		broadcastEnqueue: b.mutation( {
			query: ( { id, contact_ids } ) => ( { url: `broadcasts/${ id }/enqueue`, method: 'POST', body: { contact_ids } } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'Broadcast', id: `recipients:${ id }` } ],
		} ),
		getBroadcastProgress: b.query( {
			query: ( id ) => `broadcasts/${ id }/progress`,
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'Broadcast', id: `progress:${ id }` } ],
		} ),
		pauseBroadcast: b.mutation( {
			query: ( id ) => ( { url: `broadcasts/${ id }/pause`, method: 'POST' } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, id ) => [ { type: 'Broadcast', id }, 'Broadcast' ],
		} ),
		cancelBroadcast: b.mutation( {
			query: ( id ) => ( { url: `broadcasts/${ id }/cancel`, method: 'POST' } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, id ) => [ { type: 'Broadcast', id }, 'Broadcast' ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.44 A.1 — clone broadcast (copy title+variants+flags+delay+limits)
		cloneBroadcast: b.mutation( {
			query: ( id ) => ( { url: `broadcasts/${ id }/clone`, method: 'POST' } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'Broadcast' ],
		} ),
		// POST /conversations/bulk-label
		bulkLabelConversations: b.mutation( {
			query: ( body ) => ( { url: 'conversations/bulk-label', method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'Conversation' ],
		} ),
		// POST /contacts/{id}/classify
		classifyContact: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `contacts/${ id }/classify`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'Contact', id }, 'CrmContact' ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.44 A.3 — POST /contacts/bulk-classify (Deplao parity)
		bulkClassifyContacts: b.mutation( {
			query: ( body ) => ( { url: 'contacts/bulk-classify', method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'CrmContact' ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.45 — GET /contacts/{id}/activities
		getContactActivities: b.query( {
			query: ( { id, limit = 100, offset = 0 } ) =>
				`contacts/${ id }/activities?limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => ( r?.ok && Array.isArray( r.data?.activities ) ? r.data.activities : [] ),
			providesTags: ( res, err, { id } ) => [ { type: 'CrmContact', id: `acts-${ id }` } ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.45 — POST /contacts/{id}/activities
		createContactActivity: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `contacts/${ id }/activities`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'CrmContact', id: `acts-${ id }` } ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.44 C.1 — Integration registry
		getIntegrations: b.query( {
			query: () => 'integrations',
			transformResponse: ( r ) => ( r?.ok && Array.isArray( r.data?.integrations ) ? r.data.integrations : [] ),
			providesTags: [ 'Integration' ],
		} ),
		saveIntegration: b.mutation( {
			query: ( { type, ...body } ) => ( { url: `integrations/${ type }`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'Integration' ],
		} ),
		deleteIntegration: b.mutation( {
			query: ( type ) => ( { url: `integrations/${ type }`, method: 'DELETE' } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'Integration' ],
		} ),
		// [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — Account activities + global recent activities
		getAccountActivities: b.query( {
			query: ( { id, limit = 50, offset = 0 } ) =>
				`accounts/${ id }/activities?limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => ( r?.ok && Array.isArray( r.data?.activities ) ? r.data.activities : [] ),
			providesTags: ( res, err, { id } ) => [ { type: 'CrmAccount', id: `acts-${ id }` } ],
		} ),
		createAccountActivity: b.mutation( {
			query: ( { id, ...body } ) => ( { url: `accounts/${ id }/activities`, method: 'POST', body } ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'CrmAccount', id: `acts-${ id }` } ],
		} ),
		getRecentActivities: b.query( {
			query: ( { limit = 30, offset = 0 } = {} ) =>
				`activities?limit=${ limit }&offset=${ offset }`,
			transformResponse: ( r ) => ( r?.ok && Array.isArray( r.data?.activities ) ? r.data.activities : [] ),
			providesTags: [ 'CrmContact', 'CrmAccount' ],
		} ),

		// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — list of WP users assignable as CRM agents
		getCrmAssignableUsers: b.query( {
			query: () => 'users/assignable',
			transformResponse: ( r ) => ( r?.ok && Array.isArray( r.data ) ? r.data : [] ),
			providesTags: [ 'CrmAssignableUser' ],
			keepUnusedDataFor: 600, // cache 10 min — user list is rarely changing
		} ),

		// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — Unified Submissions endpoints
		getSubmissions: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.source_type   ) { p.set( 'source_type',   args.source_type   ); }
				if ( args.follow_status ) { p.set( 'follow_status', args.follow_status ); }
				if ( args.assignee      ) { p.set( 'assignee',      String( args.assignee ) ); }
				if ( args.from          ) { p.set( 'from',          args.from          ); }
				if ( args.to            ) { p.set( 'to',            args.to            ); }
				if ( args.page          ) { p.set( 'page',          args.page          ); }
				if ( args.per_page      ) { p.set( 'per_page',      args.per_page      ); }
				return 'submissions?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data : { rows: [], total: 0, pages: 0 } ),
			providesTags: [ 'CrmSubmission' ],
		} ),
		getSubmission: b.query( {
			query: ( id ) => `submissions/${ id }`,
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			providesTags: ( res, err, id ) => [ { type: 'CrmSubmission', id } ],
		} ),
		assignSubmission: b.mutation( {
			query: ( { id, wp_user_id } ) => ( {
				url: `submissions/${ id }/assign`,
				method: 'POST',
				body: { wp_user_id },
			} ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'CrmSubmission' ],
		} ),
		bulkAssignSubmissions: b.mutation( {
			query: ( { submission_ids, wp_user_id } ) => ( {
				url: 'submissions/bulk-assign',
				method: 'POST',
				body: { submission_ids, wp_user_id },
			} ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'CrmSubmission' ],
		} ),
		// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — CF7 bulk-assign with auto-sync (sync-and-assign in one step)
		cf7BulkAssign: b.mutation( {
			query: ( { cf7_ids, wp_user_id } ) => ( {
				url: 'cf7-submissions/bulk-assign',
				method: 'POST',
				body: { cf7_ids, wp_user_id },
			} ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: [ 'CrmSubmission' ],
		} ),
		updateSubmissionStatus: b.mutation( {
			query: ( { id, follow_status } ) => ( {
				url: `submissions/${ id }/status`,
				method: 'PATCH',
				body: { follow_status },
			} ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, { id } ) => [ { type: 'CrmSubmission', id }, 'CrmSubmission' ],
		} ),
		getTeamPerformance: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.from        ) { p.set( 'from',        args.from        ); }
				if ( args.to          ) { p.set( 'to',          args.to          ); }
				if ( args.wp_user_id  ) { p.set( 'wp_user_id',  args.wp_user_id  ); }
				return 'reports/team-performance?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data : { rows: [], from: '', to: '' } ),
			providesTags: [ 'CrmSubmission' ],
		} ),
		// [2026-06-30 Johnny Chu] PHASE-0.46 — team work distribution by assignee + status
		getTeamWorkStats: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.from ) { p.set( 'from', args.from ); }
				if ( args.to   ) { p.set( 'to',   args.to   ); }
				return 'reports/team-work?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data : { agents: [], totals: {}, by_status: [] } ),
			providesTags: [ 'CrmSubmission' ],
		} ),
		// [2026-06-30 Johnny Chu] PHASE-0.46 — personal work stats for current user
		getMyWorkStats: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.from ) { p.set( 'from', args.from ); }
				if ( args.to   ) { p.set( 'to',   args.to   ); }
				return 'reports/my-work-stats?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data : { by_status: [], totals: {}, recent: [] } ),
			providesTags: [ 'CrmSubmission' ],
		} ),
		// [2026-06-30 Johnny Chu] PHASE-0.46 — CRM overview for Dashboard
		getCrmOverview: b.query( {
			query: ( args = {} ) => {
				const p = new URLSearchParams();
				if ( args.from ) { p.set( 'from', args.from ); }
				if ( args.to   ) { p.set( 'to',   args.to   ); }
				return 'reports/crm-overview?' + p.toString();
			},
			transformResponse: ( r ) => ( r?.ok ? r.data : { leads: {}, agents: [], contacts: {} } ),
			providesTags: [ 'CrmSubmission' ],
		} ),
		// [2026-07-05 Johnny Chu] PHASE-0.46 M3 — push submission to pipeline
		pushSubmissionToPipeline: b.mutation( {
			query: ( id ) => ( {
				url: `submissions/${ id }/push-to-pipeline`,
				method: 'POST',
				body: {},
			} ),
			transformResponse: ( r ) => ( r?.ok ? r.data : null ),
			invalidatesTags: ( res, err, id ) => [ { type: 'CrmSubmission', id }, 'CrmSubmission', 'CrmOpportunity' ],
		} ),
	} ),
} );

export const {
	useGetInboxesQuery,
	useGetConversationsQuery,
	useGetMessagesQuery,
	useGetConversationQuery,
	useGetContactQuery,
	usePatchContactMutation,
	useGetLastSkipQuery,
	useGetOrderBanksQuery,
	useSearchOrderProductsQuery,
	useLazySearchOrderProductsQuery,
	useGetConversationOrdersQuery,
	useCreateConversationOrderMutation,
	useGetSingleOrderQuery,
	useLazyGetSingleOrderQuery,
	useSendOrderToCustomerMutation,
	useGetSavedBanksQuery,
	useAddSavedBankMutation,
	useDeleteSavedBankMutation,
	useSendReplyMutation,
	useSendNoteMutation,
	useResolveConversationMutation,
	useAiReplyMutation,

	/* M-FE workspace tabs */
	useGetLabelsQuery,
	useCreateLabelMutation,
	useUpdateLabelMutation,
	useDeleteLabelMutation,
	useGetConversationLabelsQuery,
	useSetConversationLabelsMutation,

	useGetCustomAttrsQuery,
	useCreateCustomAttrMutation,
	useUpdateCustomAttrMutation,
	useDeleteCustomAttrMutation,

	useGetMacrosQuery,
	useCreateMacroMutation,
	useUpdateMacroMutation,
	useDeleteMacroMutation,
	usePreviewMacroMutation,
	useRunMacroMutation,
	useRenderTemplateMutation,

	useGetWorkingHoursQuery,
	useSaveWorkingHoursMutation,
	useGetSlaPoliciesQuery,
	useCreateSlaPolicyMutation,
	useUpdateSlaPolicyMutation,
	useDeleteSlaPolicyMutation,
	useGetConversationSlaQuery,
	useTickSlaMutation,

	useGetAutomationRulesQuery,
	useGetAutomationRuleQuery,
	useCreateAutomationRuleMutation,
	useUpdateAutomationRuleMutation,
	useDeleteAutomationRuleMutation,
	useDryRunAutomationRuleMutation,
	useGetAutomationActionsQuery,

	useGetReportsAggregateQuery,
	useGetReportsAutoVsHumanQuery,
	useRunRollupNowMutation,

	// [2026-06-07 Johnny Chu] PHASE-0.40 G3.2 — Deplao parity report hooks
	useGetReportsMessageQuery,
	useGetReportsResponseQuery,
	useGetReportsAgentQuery,
	useGetReportsCampaignQuery,
	useGetReportsWorkflowQuery,
	useGetReportsAiQuery,

	/* M-CRM.M8.W5 — Woo reports */
	useGetReportsWooSummaryQuery,
	useGetReportsWooTopCustomersQuery,
	useGetReportsWooByCampaignQuery,
	useGetReportsWooTrendQuery,
	useMigrateBizContactsMutation,
	useGetCrmContactWooOrdersQuery,

	useSubmitCsatMutation,
	useSnoozeConversationMutation,
	useUnsnoozeConversationMutation,
	useGetInboxHealthQuery,

	/* M-FE.W17 — CRM modules */
	useGetCrmAccountsQuery,
	useGetCrmAccountQuery,
	useCreateCrmAccountMutation,
	useUpdateCrmAccountMutation,
	useDeleteCrmAccountMutation,

	useGetCrmContactsQuery,
	useGetCrmContactSourcesQuery,
	useGetCrmContactQuery,
	useCreateCrmContactMutation,
	useUpdateCrmContactMutation,
	useDeleteCrmContactMutation,
	useArchiveCrmContactMutation,
	useUnarchiveCrmContactMutation,

	useGetCrmTasksQuery,
	useGetCrmTaskQuery,
	useCreateCrmTaskMutation,
	useUpdateCrmTaskMutation,
	useDeleteCrmTaskMutation,

	useGetCrmEventsQuery,
	useGetCrmEventQuery,
	useCreateCrmEventMutation,
	useUpdateCrmEventMutation,
	useDeleteCrmEventMutation,

	useGetCrmDocumentsQuery,
	useGetCrmDocumentQuery,
	useCreateCrmDocumentMutation,
	useDeleteCrmDocumentMutation,

	// [2026-06-07 Johnny Chu] PHASE-0.40 G6.4 — Notes Doc CRUD
	useGetCrmNotesDocsQuery,
	useGetCrmNoteDocQuery,
	useCreateCrmNoteDocMutation,
	useUpdateCrmNoteDocMutation,
	useDeleteCrmNoteDocMutation,

	/* M-CRM.M1 — Sales Pipeline */
	useGetCrmLeadsQuery,
	useGetCrmLeadQuery,
	useCreateCrmLeadMutation,
	useUpdateCrmLeadMutation,
	useDeleteCrmLeadMutation,
	useConvertCrmLeadMutation,
	useConvertConvToLeadMutation,

	useGetCrmOpportunitiesQuery,
	useGetCrmOpportunityQuery,
	useCreateCrmOpportunityMutation,
	useUpdateCrmOpportunityMutation,
	useDeleteCrmOpportunityMutation,
	useGetOpportunityLinesQuery,
	usePutOpportunityLinesMutation,

	useGetCrmContractsQuery,
	useGetCrmContractQuery,
	useCreateCrmContractMutation,
	useUpdateCrmContractMutation,
	useDeleteCrmContractMutation,
	useGetContractLinesQuery,
	usePutContractLinesMutation,

	/* M-CRM.M1.W2 — Product Catalog */
	useGetCrmProductCategoriesQuery,
	useCreateCrmProductCategoryMutation,
	useUpdateCrmProductCategoryMutation,
	useDeleteCrmProductCategoryMutation,

	useGetCrmProductsQuery,
	useGetCrmProductQuery,
	useCreateCrmProductMutation,
	useUpdateCrmProductMutation,
	useDeleteCrmProductMutation,

	/* M-CRM.M2 — Invoicing */
	useGetCrmInvoicesQuery,
	useGetCrmInvoiceQuery,
	useCreateCrmInvoiceMutation,
	useUpdateCrmInvoiceMutation,
	useDeleteCrmInvoiceMutation,
	useTransitionCrmInvoiceMutation,
	useAddCrmInvoicePaymentMutation,
	useDeleteCrmInvoicePaymentMutation,
	useSendCrmInvoiceMutation,

	/* M-CRM.M3 — Email Client */
	useGetCrmEmailAccountsQuery,
	useGetCrmEmailAccountQuery,
	useCreateCrmEmailAccountMutation,
	useUpdateCrmEmailAccountMutation,
	useDeleteCrmEmailAccountMutation,
	useSyncCrmEmailAccountMutation,
	useImportCrmEmailAccountFromSmtpMutation,
	useTestCrmEmailAccountImapMutation,
	useGetCrmEmailThreadsQuery,
	useGetCrmEmailThreadQuery,
	useMarkCrmEmailThreadReadMutation,
	useSendCrmEmailMutation,
	useGetCrmSmtpStatusQuery,

	/* PHASE 0.37.1 — Gmail SMTP + Email Automation */
	useGetGmailSmtpAccountsQuery,
	useCreateGmailSmtpAccountMutation,
	useUpdateGmailSmtpAccountMutation,
	useDeleteGmailSmtpAccountMutation,
	useTestGmailSmtpAccountMutation,
	usePromoteGmailSmtpAccountMutation,
	useGetEmailEventsQuery,
	useGetEmailEventRulesQuery,
	useCreateEmailEventRuleMutation,
	useUpdateEmailEventRuleMutation,
	useDeleteEmailEventRuleMutation,
	useTestEmailEventRuleMutation,
	useGetEmailSendLogsQuery,
	useGetEmailSendLogStatsQuery,
	useGetEmailCf7CampaignStatsQuery,
	useTestEmailSmtpSendMutation,
	useGetCf7AutomationStatsQuery, // [2026-06-24 Johnny Chu] PHASE-CF7-AUTO
	// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS send log
	useGetZnsSendLogsQuery,
	useGetZnsSendLogStatsQuery,
	useTestZnsSendMutation,
	useGetZaloOaAccountsQuery,
	// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS
	useGetCf7SubmissionFormsQuery,
	useGetCf7SubmissionsQuery,
	useGetCf7SubmissionsStatsQuery,
	useGetCf7SubmissionsExportQuery,
	// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY
	useGetSubmissionActivitiesQuery,
	useCreateSubmissionActivityMutation,
	useGetSubmissionActivityStatsQuery,

	/* M6.W8 — Campaigns */
	useGetCampaignsQuery,
	useGetCampaignQuery,
	useCreateCampaignMutation,
	useUpdateCampaignMutation,
	useDeleteCampaignMutation,
	useGetCampaignStatsQuery,
	useGetCampaignUrlQuery,

	/* M6.W5/W6/W7/W9 */
	useGetCampaignFunnelQuery,
	useGetCampaignDropdownsQuery,
	useAwardLoyaltyMutation,
	useGetLoyaltyBalanceQuery,
	useLazyGetLoyaltyBalanceQuery,
	usePreviewFlowImportQuery,
	useImportFlowsMutation,

	/* M6.W11 / W15 / W16 — Messenger link + prompt preview */
	useGetCampaignMessengerLinkQuery,
	usePreviewCampaignPromptMutation,

	/* M6.W18-W22 — Marketing Asset Studio */
	useGetBrandKitQuery,
	useUpdateBrandKitMutation,
	useGetMarketingTemplatesQuery,
	useGetCampaignAssetManifestQuery,
	useRegenerateCampaignAssetMutation,

	/* M-PA.W2 — Print Ads (composer + REST) */
	useGetPrintAdsTemplatesQuery,
	useGetPrintAdsGenerationsQuery,
	useGeneratePrintAdMutation,

	/* Funnel Dashboard — bundled aggregator */
	useGetFunnelOverviewQuery,

	/* PHASE 3.5 Wave B — Admin Chat grants */
	useGetAdminChatGrantsQuery,
	useGetAdminChatGrantsVersionQuery,
	useApproveAdminChatGrantMutation,
	useRevokeAdminChatGrantMutation,
	useUpdateAdminChatGrantMutation,

	/* M-CRM.M1.W3 — Audit log */
	useGetEntityAuditLogQuery,
	useGetAdminChatAuditQuery, // [2026-06-07 Johnny Chu] PHASE-3.5-WC

	/* M-CRM.M4.Inbox v1.18.0 — Broadcasts + bulk ops + classify */
	useGetBroadcastsQuery,
	useGetBroadcastQuery,
	useCreateBroadcastMutation,
	useUpdateBroadcastMutation,
	useDeleteBroadcastMutation,
	useSendBroadcastMutation,
	useGetBroadcastRecipientsQuery,
	useGetBroadcastProgressQuery,
	useBroadcastEnqueueMutation,
	usePauseBroadcastMutation,
	useCancelBroadcastMutation,
	useCloneBroadcastMutation,
	useBulkLabelConversationsMutation,
	useClassifyContactMutation,
// [2026-06-13 Johnny Chu] PHASE-0.44 A.3 -- BulkClassify contacts (Deplao parity)
useBulkClassifyContactsMutation,
// [2026-06-13 Johnny Chu] PHASE-0.45 -- activities per contact
useGetContactActivitiesQuery,
useCreateContactActivityMutation,
// [2026-06-13 Johnny Chu] PHASE-0.44 C.1 — Integration registry
useGetIntegrationsQuery,
useSaveIntegrationMutation,
useDeleteIntegrationMutation,
// [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — Account/global activities
useGetAccountActivitiesQuery,
useCreateAccountActivityMutation,
useGetRecentActivitiesQuery,
// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — Unified submissions + team performance
useGetSubmissionsQuery,
useGetSubmissionQuery,
useAssignSubmissionMutation,
useBulkAssignSubmissionsMutation,
useUpdateSubmissionStatusMutation,
useGetTeamPerformanceQuery,
usePushSubmissionToPipelineMutation,
// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — assignable users list
useGetCrmAssignableUsersQuery,
// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — CF7 bulk-assign with auto-sync
useCf7BulkAssignMutation,
// [2026-06-30 Johnny Chu] PHASE-0.46 — team + personal work dashboards
useGetTeamWorkStatsQuery,
useGetMyWorkStatsQuery,
// [2026-06-30 Johnny Chu] PHASE-0.46 — CRM overview dashboard
useGetCrmOverviewQuery,
} = crmApi;
