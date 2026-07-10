/**
 * automationCareApi — RTK Query slice cho CRM-PATH-3 care recipe surface.
 *
 * Base URL: BOOT.automationRestUrl → /wp-json/bizcity-automation/v1/
 * Tất cả calls đều qua same-origin proxy (X-WP-Nonce).
 *
 * Endpoints:
 *   GET  templates?category=cskh&category=care&limit=100   → listCrmTemplates (template library)
 *   GET  workflows?zone=crm&limit=100                      → listCrmWorkflows (instantiated recipes)
 *   POST templates/{id}/crm-instantiate                    → crmInstantiateTemplate
 *   POST workflows/{id}/bind                               → bindWorkflow
 *   PUT  workflows/{id}                                    → updateWorkflow (toggle enabled)
 *   GET  runs?zone=crm&workflow_id={id}&per_page=20        → listCrmRuns
 *
 * Response shape:
 *   templates list: { ok, total, rows[], categories[], sources[] }  → use r.rows
 *   workflows list: { ok, total, rows[] }                           → use r.rows
 *   runs list:      { ok, total, data[] }                           → use r.data
 *
 * [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — create RTK slice for care recipe API
 * [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 FIX — correct endpoints + transformResponse (r.rows not r.data)
 */
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

export const automationCareApi = createApi( {
	reducerPath: 'automationCareApi',
	baseQuery: fetchBaseQuery( {
		baseUrl: BOOT.automationRestUrl || '/wp-json/bizcity-automation/v1/',
		prepareHeaders: ( headers ) => {
			if ( BOOT.restNonce ) { headers.set( 'X-WP-Nonce', BOOT.restNonce ); }
			return headers;
		},
		credentials: 'same-origin',
	} ),
	tagTypes: [ 'CrmTemplate', 'CrmWorkflow', 'CrmRun' ],
	endpoints: ( b ) => ( {

		/**
		 * Gallery: care templates from the templates table.
		 * Endpoint: GET /templates?category=cskh (also fetches category=care as separate query,
		 * merged client-side). Using limit=100 to get all care templates in one shot.
		 * Response: { ok, total, rows[], categories[], sources[] }
		 */
		listCrmTemplates: b.query( {
			query: () => 'templates?category=cskh&limit=100',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.rows ) ? r.rows : [] ),
			providesTags: [ 'CrmTemplate' ],
		} ),

		/**
		 * Instantiated recipes on this site (zone=crm workflows).
		 * Endpoint: GET /workflows?zone=crm&limit=100
		 * Response: { ok, total, rows[] }
		 */
		listCrmWorkflows: b.query( {
			query: () => 'workflows?zone=crm&limit=100',
			transformResponse: ( r ) => ( r && r.ok && Array.isArray( r.rows ) ? r.rows : [] ),
			providesTags: [ 'CrmWorkflow' ],
		} ),

		/** Instantiate a care template → creates a new workflow for this site */
		crmInstantiateTemplate: b.mutation( {
			query: ( { templateId, name } ) => ( {
				url: `templates/${ templateId }/crm-instantiate`,
				method: 'POST',
				body: { name: name || undefined },
			} ),
			invalidatesTags: [ 'CrmWorkflow' ],
		} ),

		/** Bind a recipe to a Zone-1 channel inbox */
		bindWorkflow: b.mutation( {
			query: ( { workflowId, inbox_id, platform_code } ) => ( {
				url: `workflows/${ workflowId }/bind`,
				method: 'POST',
				body: { inbox_id, platform_code },
			} ),
			invalidatesTags: [ 'CrmWorkflow' ],
		} ),

		/** Toggle enabled / rename / update a workflow */
		updateWorkflow: b.mutation( {
			query: ( { id, ...patch } ) => ( {
				url: `workflows/${ id }`,
				method: 'PUT',
				body: patch,
			} ),
			invalidatesTags: [ 'CrmWorkflow' ],
		} ),

		/** Run history for a specific recipe — response: { ok, total, rows[] } */
		listCrmRuns: b.query( {
			query: ( { workflowId, page = 1 } ) =>
				`runs?workflow_id=${ workflowId }&limit=20&offset=${ ( page - 1 ) * 20 }`,
			transformResponse: ( r ) => ( r && r.ok
				? { rows: r.rows || [], total: r.total || 0 }
				: { rows: [], total: 0 } ),
			providesTags: ( res, err, arg ) => [ { type: 'CrmRun', id: arg.workflowId } ],
		} ),
	} ),
} );

export const {
	useListCrmTemplatesQuery,
	useListCrmWorkflowsQuery,
	useCrmInstantiateTemplateMutation,
	useBindWorkflowMutation,
	useUpdateWorkflowMutation,
	useListCrmRunsQuery,
} = automationCareApi;
