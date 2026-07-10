/**
 * [2026-06-07 Johnny Chu] PHASE-0.40 G5.2 — Global Search Panel (Deplao parity)
 *
 * Keyboard shortcut: Ctrl+K (or Cmd+K) opens/closes.
 * Uses existing GET /conversations?search=<q> endpoint.
 */
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useGetConversationsQuery } from '../redux/api/crmApi.js';

export default function GlobalSearchPanel( { onSelectConv } ) {
	const [ open,  setOpen  ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const inputRef = useRef( null );

	// Ctrl+Shift+F / Cmd+Shift+F opens conversation search (different from Ctrl+K CommandPalette).
	useEffect( () => {
		const handler = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && e.key === 'f' ) {
				e.preventDefault();
				setOpen( ( v ) => ! v );
			}
			if ( e.key === 'Escape' ) { setOpen( false ); }
		};
		window.addEventListener( 'keydown', handler );
		return () => window.removeEventListener( 'keydown', handler );
	}, [] );

	useEffect( () => {
		if ( open && inputRef.current ) {
			setTimeout( () => inputRef.current && inputRef.current.focus(), 60 );
		} else if ( ! open ) {
			setQuery( '' );
		}
	}, [ open ] );

	const debouncedQ = useDebounce( query, 320 );
	const { data, isFetching } = useGetConversationsQuery(
		{ inboxId: 0, page: 1, per_page: 20, search: debouncedQ },
		{ skip: debouncedQ.length < 2 }
	);
	const results = data?.conversations || data?.data || [];

	const pick = useCallback( ( conv ) => {
		setOpen( false );
		if ( onSelectConv ) { onSelectConv( conv.id, conv.inbox_id ); }
	}, [ onSelectConv ] );

	if ( ! open ) {
		return (
			<button
				type="button"
				onClick={ () => setOpen( true ) }
				title="Tìm kiếm hội thoại (Ctrl+Shift+F)"
				style={ { background: 'none', border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 10px', fontSize: 12, color: '#64748b', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4 } }
			>
				🔍 <span style={ { fontSize: 11 } }>Ctrl+⇧+F</span>
			</button>
		);
	}

	return (
		<div style={ {
			position: 'fixed', inset: 0, zIndex: 9999, background: 'rgba(0,0,0,0.35)',
			display: 'flex', alignItems: 'flex-start', justifyContent: 'center', paddingTop: 80,
		} } onClick={ ( e ) => { if ( e.target === e.currentTarget ) { setOpen( false ); } } }>
			<div style={ { background: '#fff', borderRadius: 12, boxShadow: '0 20px 60px rgba(0,0,0,0.18)', width: 560, maxWidth: '90vw', overflow: 'hidden' } }>
				<div style={ { display: 'flex', alignItems: 'center', padding: '10px 14px', borderBottom: '1px solid #f1f5f9', gap: 8 } }>
					<span style={ { color: '#94a3b8', fontSize: 16 } }>🔍</span>
					<input
						ref={ inputRef }
						type="text"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
						placeholder="Tìm conversation, liên hệ, nội dung…"
						style={ { flex: 1, border: 'none', outline: 'none', fontSize: 14, color: '#1e293b' } }
					/>
					{ isFetching && <span style={ { fontSize: 11, color: '#94a3b8' } }>Đang tìm…</span> }
					<kbd onClick={ () => setOpen( false ) } style={ { fontSize: 11, background: '#f1f5f9', border: '1px solid #e2e8f0', borderRadius: 4, padding: '1px 5px', cursor: 'pointer', color: '#64748b' } }>Esc</kbd>
				</div>
				{ debouncedQ.length >= 2 && (
					<div style={ { maxHeight: 360, overflowY: 'auto' } }>
						{ results.length === 0 && ! isFetching
							? <div style={ { padding: '16px 14px', color: '#94a3b8', fontSize: 13 } }>Không tìm thấy kết quả.</div>
							: results.map( ( conv ) => (
								<div
									key={ conv.id }
									onClick={ () => pick( conv ) }
									style={ { padding: '8px 14px', cursor: 'pointer', borderBottom: '1px solid #f8fafc', display: 'flex', gap: 10, alignItems: 'center' } }
									onMouseEnter={ ( e ) => ( e.currentTarget.style.background = '#f8fafc' ) }
									onMouseLeave={ ( e ) => ( e.currentTarget.style.background = '' ) }
								>
									<div style={ { flex: 1, minWidth: 0 } }>
										<div style={ { fontSize: 13, fontWeight: 500, color: '#1e293b', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }>
											{ conv.meta?.sender?.name || conv.contact?.name || `#${ conv.id }` }
										</div>
										<div style={ { fontSize: 11, color: '#94a3b8', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }>
											{ conv.last_message || conv.summary || '' }
										</div>
									</div>
									<span style={ { fontSize: 11, color: '#cbd5e1', flexShrink: 0 } }>
										{ conv.created_at ? conv.created_at.slice( 0, 10 ) : '' }
									</span>
								</div>
							) )
						}
					</div>
				) }
				{ debouncedQ.length < 2 && (
					<div style={ { padding: '12px 14px', color: '#94a3b8', fontSize: 12 } }>
						Nhập ít nhất 2 ký tự để tìm kiếm.
					</div>
				) }
			</div>
		</div>
	);
}

function useDebounce( value, delay ) {
	const [ debounced, setDebounced ] = useState( value );
	useEffect( () => {
		const t = setTimeout( () => setDebounced( value ), delay );
		return () => clearTimeout( t );
	}, [ value, delay ] );
	return debounced;
}
