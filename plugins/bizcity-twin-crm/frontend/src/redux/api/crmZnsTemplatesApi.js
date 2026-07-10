/**
 * crmZnsTemplatesApi — RTK Query endpoint for ZNS template catalog (read-only proxy).
 *
 * Proxy route: bizcity-crm/v1/zns-templates  →  BizCity_CF7_ZNS_Templates::get_all()
 * Namespace rule (R-CH-NS): CRM SPA must NOT call bizcity-channel/v1 directly.
 *
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — new API slice.
 */
import { cgBroadcastApi } from './cgBroadcastApi';

export const crmZnsTemplatesApi = cgBroadcastApi.injectEndpoints( {
	endpoints: ( build ) => ( {
		/**
		 * List ZNS templates from catalog.
		 * Returns: { ok: bool, templates: [{temp_id, name, oa_id, vars[], status, ...}], count: int }
		 * Params: { status?: 'active'|'inactive'|'all' }
		 */
		getZnsTemplates: build.query( {
			query: ( { status = 'active' } = {} ) => `zns-templates?status=${ encodeURIComponent( status ) }`,
			// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — CRM wrap() nests payload under data{}
			transformResponse: ( r ) => {
				const inner = ( r && r.data ) ? r.data : r;
				return ( inner && inner.ok ) ? ( inner.templates || [] ) : [];
			},
		} ),
	} ),
	overrideExisting: false,
} );

export const { useGetZnsTemplatesQuery } = crmZnsTemplatesApi;
