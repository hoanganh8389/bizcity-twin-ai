/**
 * MacroPalette — PHASE 0.35 M-FE.W2.
 *
 * Searchable popover that lists saved Macros for the active inbox / agent.
 * Hover/highlight calls `previewMacro` to render the template against the
 * current conversation (so variables like {{ contact.name }} expand). Click
 * fires `runMacro` which executes the macro server-side (insert + log).
 *
 * Renders only the popover panel — caller controls the trigger button.
 */
import React, { useEffect, useMemo, useState } from 'react';
import {
	useGetMacrosQuery,
	usePreviewMacroMutation,
	useRunMacroMutation,
} from '../redux/api/crmApi.js';

export default function MacroPalette( { convId, onClose, onInsertText } ) {
	const { data: macros = [], isLoading } = useGetMacrosQuery();
	const [ preview, previewState ] = usePreviewMacroMutation();
	const [ run, runState ]         = useRunMacroMutation();
	const [ q, setQ ]               = useState( '' );
	const [ activeId, setActiveId ] = useState( 0 );
	const [ previewText, setPreviewText ] = useState( '' );

	const filtered = useMemo( () => {
		const needle = q.trim().toLowerCase();
		if ( ! needle ) { return macros; }
		return macros.filter( ( m ) => String( m.name || '' ).toLowerCase().includes( needle ) );
	}, [ macros, q ] );

	useEffect( () => {
		if ( ! activeId || ! convId ) { setPreviewText( '' ); return; }
		let cancelled = false;
		preview( { id: activeId, conversation_id: convId } ).unwrap()
			.then( ( r ) => { if ( ! cancelled ) { setPreviewText( r?.preview || r?.body || r?.content || JSON.stringify( r || {} ) ); } } )
			.catch( () => { if ( ! cancelled ) { setPreviewText( '(không preview được)' ); } } );
		return () => { cancelled = true; };
	}, [ activeId, convId ] );

	const execute = async ( macroId ) => {
		if ( ! convId || runState.isLoading ) { return; }
		try {
			const r = await run( { id: macroId, conversation_id: convId } ).unwrap();
			if ( onInsertText && r?.preview ) { onInsertText( r.preview ); }
			if ( onClose ) { onClose(); }
		} catch ( _ ) { /* keep palette open so user can retry */ }
	};

	return (
		<div className="absolute bottom-9 left-2 z-30 w-[360px] max-h-[320px] bg-white border border-slate-200 shadow-lg rounded-md flex flex-col overflow-hidden">
			<div className="px-2 py-1.5 border-b border-slate-100 flex items-center gap-2 bg-slate-50">
				<span className="text-[12px]">⚡</span>
				<input
					autoFocus
					value={ q }
					onChange={ ( e ) => setQ( e.target.value ) }
					placeholder="Tìm macro theo tên…"
					className="flex-1 px-1.5 py-1 text-[12px] bg-white border border-slate-100 outline-none focus:border-indigo-300"
				/>
				<button type="button" onClick={ onClose } className="px-1.5 text-slate-400 hover:text-slate-700 text-[14px]" title="Đóng">×</button>
			</div>

			<div className="flex-1 overflow-y-auto">
				{ isLoading ? (
					<div className="p-3 text-[11px] text-slate-400 italic">Đang tải…</div>
				) : filtered.length === 0 ? (
					<div className="p-3 text-[11px] text-slate-400 italic">
						{ macros.length === 0 ? 'Chưa có macro nào.' : 'Không khớp.' }
					</div>
				) : filtered.map( ( m ) => {
					const on = activeId === Number( m.id );
					return (
						<button key={ m.id } type="button"
							onMouseEnter={ () => setActiveId( Number( m.id ) ) }
							onClick={ () => execute( Number( m.id ) ) }
							disabled={ runState.isLoading }
							className={ 'w-full text-left px-2 py-1.5 text-[12px] border-b border-slate-50 disabled:opacity-50 ' + ( on ? 'bg-indigo-50' : 'hover:bg-slate-50' ) }
						>
							<div className="font-semibold text-slate-800 truncate">{ m.name || `Macro #${ m.id }` }</div>
							{ m.description ? <div className="text-[10px] text-slate-500 truncate">{ m.description }</div> : null }
						</button>
					);
				} ) }
			</div>

			{ activeId && previewText ? (
				<div className="border-t border-slate-100 p-2 bg-slate-50 max-h-[120px] overflow-y-auto">
					<div className="text-[9px] uppercase text-slate-400 font-semibold mb-1">Preview</div>
					<div className="text-[11px] text-slate-700 whitespace-pre-wrap break-words">
						{ previewState.isLoading ? '⏳…' : previewText }
					</div>
				</div>
			) : null }
		</div>
	);
}
