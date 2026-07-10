/**
 * LabelChips — multi-select label chip editor for a conversation.
 * PHASE 0.35 M3.W2.
 *
 * Props:
 *   convId   number — conversation id (required)
 *   compact  boolean — when true, hides the "+ add" button when labels exist (read-mostly mode)
 *
 * Behaviour:
 *   - Reads `useGetLabelsQuery` for the catalog and
 *     `useGetConversationLabelsQuery` for the assigned set.
 *   - Toggling a chip optimistically calls `setConversationLabels` with the new array.
 */
import React, { useMemo, useState } from 'react';
import {
	useGetLabelsQuery,
	useGetConversationLabelsQuery,
	useSetConversationLabelsMutation,
} from '../redux/api/crmApi.js';

function chipStyle( color, on ) {
	const safe = ( color || '#64748b' ).trim() || '#64748b';
	return {
		display:        'inline-flex',
		alignItems:     'center',
		gap:            4,
		padding:        '2px 8px',
		borderRadius:   999,
		fontSize:       11,
		fontWeight:     600,
		lineHeight:     1.4,
		cursor:         'pointer',
		userSelect:     'none',
		border:         '1px solid ' + safe,
		background:     on ? safe : '#fff',
		color:          on ? '#fff' : safe,
		transition:     'background .12s, color .12s',
	};
}

export default function LabelChips( { convId, compact = false } ) {
	const { data: catalog = [] } = useGetLabelsQuery();
	const { data: assigned = [] } = useGetConversationLabelsQuery( convId, { skip: ! convId } );
	const [ save, { isLoading: saving } ] = useSetConversationLabelsMutation();
	const [ adding, setAdding ] = useState( false );

	const assignedIds = useMemo(
		() => new Set( assigned.map( ( l ) => Number( l.id || l.label_id ) ) ),
		[ assigned ]
	);

	const toggle = async ( labelId ) => {
		if ( ! convId || saving ) { return; }
		const next = assignedIds.has( labelId )
			? Array.from( assignedIds ).filter( ( id ) => id !== labelId )
			: Array.from( assignedIds ).concat( labelId );
		try { await save( { convId, labels: next } ).unwrap(); }
		catch ( _ ) { /* noop */ }
	};

	if ( ! convId ) { return null; }
	if ( ! catalog.length ) {
		return <span style={ { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' } }>Chưa có label nào.</span>;
	}

	const showCatalog = adding || ( ! compact && assigned.length === 0 );
	const list        = showCatalog ? catalog : assigned;

	return (
		<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' } }>
			{ list.map( ( l ) => {
				const id = Number( l.id || l.label_id );
				const on = assignedIds.has( id );
				return (
					<span
						key={ id }
						onClick={ () => toggle( id ) }
						style={ chipStyle( l.color, on ) }
						title={ l.description || l.title }
					>
						{ on ? '✓ ' : '+ ' }{ l.title || l.name }
					</span>
				);
			} ) }
			{ ! compact && (
				<button
					type="button"
					onClick={ () => setAdding( ( v ) => ! v ) }
					style={ {
						padding:    '2px 8px',
						borderRadius: 999,
						fontSize:   11,
						background: '#f1f5f9',
						color:      '#475569',
						border:     '1px dashed #cbd5e1',
						cursor:     'pointer',
					} }
				>
					{ adding ? 'Xong' : '+ chỉnh' }
				</button>
			) }
			{ saving && <span style={ { fontSize: 10, color: '#94a3b8' } }>đang lưu…</span> }
		</div>
	);
}
