import React, { useState, useCallback, useRef } from 'react';
import {
	useSendReplyMutation,
	useSendNoteMutation,
	useAiReplyMutation,
} from '../redux/api/crmApi.js';
import MacroPalette from './MacroPalette.jsx';

/**
 * Composer — Chatwoot-style 2-tab Reply / Private Note.
 *
 * Backed by:
 *   POST bizcity-crm/v1/conversations/{id}/messages   (manual reply, stamps responder_kind=manual)
 *   POST bizcity-crm/v1/conversations/{id}/notes      (private note, no outbound dispatch)
 *
 * Keyboard: Cmd/Ctrl+Enter = send.
 */

const QUICK_EMOJI = [ '👍', '🙏', '😊', '❤️', '🔥', '✅' ];

export default function Composer( { convId } ) {
	const [ tab, setTab ]         = useState( 'reply' );
	const [ text, setText ]       = useState( '' );
	const [ showEmoji, setShowEmoji ] = useState( false );
	const [ showMacros, setShowMacros ] = useState( false );
	const [ sendReply, replyState ] = useSendReplyMutation();
	const [ sendNote,  noteState  ] = useSendNoteMutation();
	const [ aiReply,   aiState    ] = useAiReplyMutation();
	const taRef = useRef( null );

	const busy = replyState.isLoading || noteState.isLoading || aiState.isLoading;

	const submit = useCallback( async () => {
		const content = text.trim();
		if ( ! content || ! convId || busy ) { return; }
		try {
			if ( tab === 'reply' ) { await sendReply( { convId, content } ).unwrap(); }
			else                   { await sendNote(  { convId, content } ).unwrap(); }
			setText( '' );
		} catch ( e ) {
			// keep text so user can retry; error rendered below.
		}
	}, [ tab, text, convId, busy, sendReply, sendNote ] );

	const onKey = ( e ) => {
		if ( ( e.metaKey || e.ctrlKey ) && e.key === 'Enter' ) {
			e.preventDefault();
			submit();
		}
	};

	const triggerAi = useCallback( async () => {
		if ( ! convId || aiState.isLoading ) { return; }
		try {
			// Optional: use composer text as override prompt; else replier picks
			// the latest inbound message automatically.
			const override = text.trim();
			await aiReply( {
				convId,
				prompt: override || undefined,
				dispatch: true,
			} ).unwrap();
			setText( '' );
		} catch ( e ) { /* keep text on failure */ }
	}, [ convId, text, aiState.isLoading, aiReply ] );

	// [2026-06-07 Johnny Chu] PHASE-0.40 G5.3 — AI suggest: dry_run=true inserts suggestion into composer, doesn't send.
	const triggerAiSuggest = useCallback( async () => {
		if ( ! convId || aiState.isLoading ) { return; }
		try {
			const result = await aiReply( {
				convId,
				prompt: text.trim() || undefined,
				dispatch: false,
			} ).unwrap();
			// If the server returns a suggested reply text, insert it into composer.
			const suggestion = result?.data?.reply || result?.reply || '';
			if ( suggestion ) {
				setText( suggestion );
				if ( taRef.current ) { taRef.current.focus(); }
			}
		} catch ( e ) { /* fail silently — user keeps existing text */ }
	}, [ convId, text, aiState.isLoading, aiReply ] );

	const insertEmoji = ( emo ) => {
		setText( ( t ) => ( t ? t + ' ' + emo : emo ) );
		setShowEmoji( false );
		if ( taRef.current ) { taRef.current.focus(); }
	};

	const isReply = tab === 'reply';
	const aiErr   = aiState.error?.data?.error?.message || aiState.error?.error;
	const errMsg  = ( isReply ? replyState.error : noteState.error )?.data?.error?.message
		|| ( isReply ? replyState.error?.error : noteState.error?.error )
		|| aiErr;

	const tabBtn = ( key, label ) => {
		const on = tab === key;
		const colour = key === 'reply' ? 'border-indigo-500 text-indigo-700' : 'border-amber-500 text-amber-700';
		return (
			<button type="button" onClick={ () => setTab( key ) }
				className={ 'relative px-3 py-1.5 text-[12px] font-medium ' + ( on ? colour : 'text-slate-500 hover:text-slate-700' ) }
			>
				{ label }
				{ on ? <span className={ 'absolute left-0 right-0 -bottom-px h-[2px] ' + ( key === 'reply' ? 'bg-indigo-500' : 'bg-amber-500' ) } /> : null }
			</button>
		);
	};

	return (
		<div className={ 'border-t border-slate-50 ' + ( isReply ? 'bg-white' : 'bg-amber-50/40' ) }>
			{ /* Tab bar */ }
			<div className="flex items-center px-3 border-b border-slate-50">
				{ tabBtn( 'reply', '↩ Reply' ) }
				{ tabBtn( 'note',  '📝 Private note' ) }
				<span className="ml-auto text-[10px] text-slate-400">
					<kbd className="font-mono bg-slate-100 px-1 rounded">⌘/Ctrl</kbd> + <kbd className="font-mono bg-slate-100 px-1 rounded">Enter</kbd>
				</span>
			</div>

			{ /* Textarea */ }
			<textarea
				ref={ taRef }
				value={ text }
				onChange={ ( e ) => setText( e.target.value ) }
				onKeyDown={ onKey }
				placeholder={ isReply ? 'Nhập trả lời gửi cho khách…' : 'Ghi chú nội bộ — không gửi cho khách…' }
				rows={ 3 }
				className={ 'w-full px-3 py-2 text-[13px] border-0 outline-none resize-none ' + ( isReply ? 'bg-white' : 'bg-amber-50/50' ) }
			/>

			{ errMsg ? <div className="px-3 pb-1 text-[11px] text-rose-600">⚠ { String( errMsg ) }</div> : null }

			{ /* Toolbar + send */ }
			<div className="flex items-center gap-1 px-2 pb-2 relative">
				<button type="button" onClick={ () => setShowEmoji( ( v ) => ! v ) } className="w-7 h-7 hover:bg-slate-50 text-slate-500" title="Emoji">😊</button>
				<button type="button" disabled className="w-7 h-7 hover:bg-slate-50 text-slate-400 disabled:opacity-50" title="Đính kèm (sắp có)">📎</button>
				<button type="button"
					onClick={ () => setShowMacros( ( v ) => ! v ) }
					disabled={ ! convId }
					className={ 'w-7 h-7 disabled:opacity-50 ' + ( showMacros ? 'bg-indigo-100 text-indigo-700' : 'hover:bg-slate-50 text-slate-500' ) }
					title="Macro / Câu trả lời nhanh"
				>⚡</button>
				<button type="button"
					disabled={ ! convId || aiState.isLoading }
					onClick={ triggerAiSuggest }
					className="px-2 h-7 text-[11px] font-semibold bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 disabled:opacity-50"
					title="Gợi ý câu trả lời — chèn vào composer để chỉnh trước khi gửi"
				>{ aiState.isLoading ? '…' : '💡 Gợi ý' }</button>
				<button type="button"
					disabled={ ! convId || aiState.isLoading }
					onClick={ triggerAi }
					className="px-2 h-7 text-[11px] font-semibold bg-violet-50 hover:bg-violet-100 text-violet-700 border border-violet-200 disabled:opacity-50"
					title={ text.trim()
						? 'AI trả lời với prompt bạn nhập (grống theo notebook)'
						: 'AI trả lời tin nhắn cuối của khách (grống theo notebook)' }
				>{ aiState.isLoading ? '… thinking' : ( text.trim() ? '🤖 AI · prompt' : '🤖 AI reply' ) }</button>

				{ showEmoji ? (
					<div className="absolute bottom-9 left-2 bg-white border border-slate-100 shadow-sm p-1 flex gap-0.5 z-20">
						{ QUICK_EMOJI.map( ( e ) => (
							<button key={ e } type="button" onClick={ () => insertEmoji( e ) } className="w-7 h-7 hover:bg-slate-50 text-base">{ e }</button>
						) ) }
					</div>
				) : null }
				{ showMacros ? (
					<MacroPalette
						convId={ convId }
						onClose={ () => setShowMacros( false ) }
						onInsertText={ ( t ) => setText( ( prev ) => prev ? ( prev + '\n' + t ) : t ) }
					/>
				) : null }

				<div className="ml-auto flex items-center gap-2">
					{ text.length > 800 ? <span className="text-[10px] text-amber-600 font-mono">{ text.length }</span> : null }
					<span className="text-[10px] text-slate-400">
						{ isReply ? <>stamp <span className="font-mono">manual</span></> : <>nội bộ — không gửi đi</> }
					</span>
					<button type="button"
						disabled={ ! text.trim() || busy || ! convId }
						onClick={ submit }
						className={ 'px-3 py-1.5 text-[12px] font-semibold disabled:opacity-40 ' + ( isReply ? 'bg-indigo-600 text-white hover:bg-indigo-700' : 'bg-amber-500 text-white hover:bg-amber-600' ) }
					>{ busy ? 'Sending…' : ( isReply ? 'Send ➜' : 'Save note' ) }</button>
				</div>
			</div>
		</div>
	);
}
