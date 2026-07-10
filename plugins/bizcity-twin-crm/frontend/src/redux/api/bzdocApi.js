/**
 * BzDoc Documents Hub API slice (R-OF / PHASE-0-RULE-OUTPUT-FILES).
 *
 * Talks to the canonical `bzdoc/v1/documents*` REST surface owned by the
 * bizcity-doc plugin. Used by:
 *   - twin-crm Documents tab (this slice)
 *   - TwinChat Notebook Files tab (future)
 *
 * Boot config:
 *   window.BIZCITY_CRM_BOOT.bzdocRestUrl   (default '/wp-json/bzdoc/v1/')
 *   window.BIZCITY_CRM_BOOT.restNonce      (re-uses the CRM nonce)
 */
import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const BASE = BOOT.bzdocRestUrl || '/wp-json/bzdoc/v1/';

export const bzdocApi = createApi( {
	reducerPath: 'bzdocApi',
	baseQuery: fetchBaseQuery( {
		baseUrl: BASE,
		prepareHeaders: ( headers ) => {
			const nonce = BOOT.restNonce;
			if ( nonce ) { headers.set( 'X-WP-Nonce', nonce ); }
			return headers;
		},
	} ),
	tagTypes: [ 'BzDocument', 'BzHealth' ],
	endpoints: ( b ) => ( {
		listBzDocuments: b.query( {
			query: ( args = {} ) => {
				const params = new URLSearchParams();
				if ( args.notebook_id ) { params.set( 'notebook_id', String( args.notebook_id ) ); }
				if ( args.doc_type ) { params.set( 'doc_type', String( args.doc_type ) ); }
				if ( args.generator ) { params.set( 'generator', String( args.generator ) ); }
				if ( args.origin ) { params.set( 'origin', String( args.origin ) ); }
				if ( args.q ) { params.set( 'q', String( args.q ) ); }
				if ( args.status ) { params.set( 'status', String( args.status ) ); }
				params.set( 'limit', String( args.limit || 50 ) );
				params.set( 'offset', String( args.offset || 0 ) );
				if ( args.sort ) { params.set( 'sort', String( args.sort ) ); }
				const qs = params.toString();
				return 'documents' + ( qs ? '?' + qs : '' );
			},
			transformResponse: ( r ) => ( r && r.ok && r.data ) ? r.data : { documents: [], total: 0 },
			providesTags: ( result ) => {
				const docs = ( result && Array.isArray( result.documents ) ) ? result.documents : [];
				return [
					{ type: 'BzDocument', id: 'LIST' },
					...docs.map( ( d ) => ( { type: 'BzDocument', id: d.id } ) ),
				];
			},
		} ),

		uploadBzDocument: b.mutation( {
			query: ( { file, notebook_id, title, parent_event_uuid } ) => {
				const fd = new FormData();
				fd.append( 'file', file );
				if ( notebook_id ) { fd.append( 'notebook_id', String( notebook_id ) ); }
				if ( title ) { fd.append( 'title', title ); }
				if ( parent_event_uuid ) { fd.append( 'parent_event_uuid', parent_event_uuid ); }
				return { url: 'documents', method: 'POST', body: fd };
			},
			invalidatesTags: [ { type: 'BzDocument', id: 'LIST' }, 'BzHealth' ],
		} ),

		deleteBzDocument: b.mutation( {
			query: ( id ) => ( { url: `documents/${ Number( id ) }`, method: 'DELETE' } ),
			invalidatesTags: ( _r, _e, id ) => [
				{ type: 'BzDocument', id: 'LIST' },
				{ type: 'BzDocument', id },
			],
		} ),

		getBzHealth: b.query( {
			query: () => 'documents/health',
			transformResponse: ( r ) => ( r && r.ok && r.data ) ? r.data : null,
			providesTags: [ 'BzHealth' ],
		} ),
	} ),
} );

export const {
	useListBzDocumentsQuery,
	useUploadBzDocumentMutation,
	useDeleteBzDocumentMutation,
	useGetBzHealthQuery,
} = bzdocApi;
