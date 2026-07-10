import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useGetMessagesQuery, useGetConversationQuery, useGetInboxesQuery, useResolveConversationMutation, useGetLastSkipQuery } from '../redux/api/crmApi.js';
import Composer from './Composer.jsx';
import ThinkingTimeline from './ThinkingTimeline.jsx';
import SLABadge from './SLABadge.jsx';
import SnoozeMenu from './SnoozeMenu.jsx';
import ConvertToLeadSheet from './ConvertToLeadSheet.jsx';
import LabelChips from './LabelChips.jsx';
import { fmtAbsTime, channelMeta, avatarGradient, initials } from '../lib/format.js';

/**
 * Trace stamp pill (PHASE 0.34 manifesto). Renders responder_kind + optional
 * character / user references with deep-links.
 */
function ResponderPill( { kind, userId, characterId, characterEditUrl, userEditUrl } ) {
	if ( ! kind && ! userId && ! characterId ) { return null; }
	const palette = {
		auto:   'bg-emerald-100 text-emerald-800 border-emerald-300',
		manual: 'bg-rose-100 text-rose-800 border-rose-300',
		hybrid: 'bg-amber-100 text-amber-800 border-amber-300',
		system: 'bg-slate-100 text-slate-700 border-slate-300',
	};
	const cls   = palette[ kind ] || palette.system;
	const label = kind || 'system';
	const stop  = ( e ) => e.stopPropagation();
	return (
		<span
			className={ 'inline-flex items-center gap-1 px-1.5 py-[1px] text-[9px] font-semibold uppercase rounded border ' + cls }
			title={ `responder_kind=${ kind || '∅' }${ userId ? ` · user_id=${ userId }` : '' }${ characterId ? ` · character_id=${ characterId }` : '' }` }
		>
			{ label }
			{ characterId ? (
				characterEditUrl
					? <a href={ characterEditUrl } target="_blank" rel="noopener" onClick={ stop } className="font-mono normal-case underline decoration-dotted hover:no-underline">·G{ characterId }</a>
					: <span className="font-mono normal-case">·G{ characterId }</span>
			) : null }
			{ userId ? (
				userEditUrl
					? <a href={ userEditUrl } target="_blank" rel="noopener" onClick={ stop } className="font-mono normal-case underline decoration-dotted hover:no-underline">·U{ userId }</a>
					: <span className="font-mono normal-case">·U{ userId }</span>
			) : null }
		</span>
	);
}

function dayLabel( raw ) {
	const ts = raw ? Date.parse( String( raw ).replace( ' ', 'T' ) ) : 0;
	if ( ! ts ) { return ''; }
	const d  = new Date( ts ); d.setHours( 0, 0, 0, 0 );
	const t  = new Date();    t.setHours( 0, 0, 0, 0 );
	const y  = new Date( t ); y.setDate( y.getDate() - 1 );
	if ( d.getTime() === t.getTime() ) { return 'Today'; }
	if ( d.getTime() === y.getTime() ) { return 'Yesterday'; }
	return new Date( ts ).toLocaleDateString( undefined, { weekday: 'short', day: '2-digit', month: 'short' } );
}

export default function ConversationDetail( { convId } ) {
	const { data: messages = [] } = useGetMessagesQuery(
		convId ? { convId, limit: 200 } : { skip: true, convId: 0 },
		{ pollingInterval: 2000, skip: ! convId }
	);
	const { data: conv } = useGetConversationQuery( convId, { skip: ! convId } );
	const { data: inboxes = [] } = useGetInboxesQuery();
	const { data: lastSkip } = useGetLastSkipQuery( convId, { skip: ! convId, pollingInterval: 30000 } );
	const [ resolve, resolveState ] = useResolveConversationMutation();
	const [ skipDismissed, setSkipDismissed ] = useState( null );
	const [ convertOpen, setConvertOpen ] = useState( false );
	const [ labelsOpen, setLabelsOpen ]   = useState( false );

	const inbox = inboxes.find( ( i ) => i.id === conv?.inbox_id );
	const cMeta = channelMeta( inbox?.channel_type );
	const grad  = avatarGradient( conv?.contact?.name || convId );

	const scrollRef = useRef( null );
	const [ atBottom, setAtBottom ] = useState( true );
	useEffect( () => {
		if ( scrollRef.current && atBottom ) {
			scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
		}
	}, [ messages.length, atBottom ] );
	const onScroll = () => {
		const el = scrollRef.current; if ( ! el ) { return; }
		setAtBottom( el.scrollHeight - el.scrollTop - el.clientHeight < 80 );
	};

	const grouped = useMemo( () => {
		// Insert day separators between dates.
		const out = []; let lastDay = '';
		for ( const m of messages ) {
			const d = dayLabel( m.created_at );
			if ( d && d !== lastDay ) { out.push( { __sep: d, id: 'sep-' + d } ); lastDay = d; }
			out.push( m );
		}
		return out;
	}, [ messages ] );

	// Derive most recent persona from the latest auto-reply ai_metadata so
	// the conversation header surfaces which Service Template the Guru on
	// Duty is actually using right now.
	const lastPersona = useMemo( () => {
		for ( let i = messages.length - 1; i >= 0; i-- ) {
			const m = messages[ i ];
			if ( m?.responder_kind !== 'auto' || ! m.ai_metadata ) { continue; }
			const steps = Array.isArray( m.ai_metadata.steps ) ? m.ai_metadata.steps : [];
			const ctx   = steps.find( ( s ) => s.name === 'resolve_context' );
			const tpl   = ctx?.detail?.service_template;
			if ( tpl?.slug ) { return tpl; }
		}
		return null;
	}, [ messages ] );

	if ( ! convId ) {
		return (
			<section className="conv-pane bg-white flex flex-col items-center justify-center text-slate-400">
				<div className="empty-art mb-3">💬</div>
				<div className="text-sm italic">Chọn một hội thoại từ danh sách bên trái.</div>
			</section>
		);
	}

	const contactName = conv?.contact?.name || `Contact #${ conv?.contact?.id || '?' }`;

	return (
		<section className="conv-pane bg-white flex flex-col overflow-hidden">
			{ /* —— Sticky header with contact identity + actions —— */ }
			<header className="px-4 py-2.5 border-b border-slate-50 bg-white flex items-center gap-3">
				<div className="relative shrink-0">
					{ conv?.contact?.avatar_url
						? <img src={ conv.contact.avatar_url } className="w-9 h-9 rounded-full object-cover" alt="" />
						: <div className={ 'w-9 h-9 rounded-full bg-gradient-to-br text-white flex items-center justify-center font-semibold text-[12px] ' + grad }>{ initials( conv?.contact?.name, '?' ) }</div>
					}
					<span
						className="absolute -bottom-0.5 -right-0.5 w-4 h-4 rounded-full border-2 border-white text-[9px] flex items-center justify-center"
						style={ { background: cMeta.color, color: '#fff' } }
						title={ cMeta.label }
					>{ cMeta.icon }</span>
				</div>
				<div className="min-w-0 flex-1">
					<div className="flex items-center gap-1.5">
						<span className="font-semibold text-[13px] truncate">{ contactName }</span>
						{ conv?.status ? (
							<span className={ 'text-[9px] uppercase tracking-wide px-1.5 py-[1px] rounded font-semibold ' + (
								conv.status === 'open' ? 'bg-emerald-100 text-emerald-700'
								: conv.status === 'pending' ? 'bg-amber-100 text-amber-700'
								: conv.status === 'resolved' ? 'bg-slate-100 text-slate-500'
								: 'bg-slate-100 text-slate-600'
							) }>{ conv.status }</span>
						) : null }
						{ ( () => {
							const nb = conv?.notebook_id || inbox?.default_notebook_id || 0;
							return nb
								? <span className="text-[9px] px-1.5 py-[1px] rounded font-semibold bg-violet-100 text-violet-700 border border-violet-200" title="AI auto-reply notebook">📘 nb#{ nb }</span>
								: <span className="text-[9px] px-1.5 py-[1px] rounded font-semibold bg-rose-50 text-rose-700 border border-rose-200" title="No notebook attached → AI auto-reply disabled, legacy bot will respond">⚠ no notebook</span>;
						} )() }
						{ lastPersona ? (
							<span
								className={ 'text-[9px] px-1.5 py-[1px] rounded font-semibold border ' + (
									lastPersona.role_scope === 'external' ? 'bg-amber-100 text-amber-800 border-amber-200'
									: lastPersona.role_scope === 'internal' ? 'bg-sky-100 text-sky-800 border-sky-200'
									: 'bg-slate-100 text-slate-700 border-slate-200'
								) }
								title={ `Service Template: ${ lastPersona.label || lastPersona.slug }\nRole: ${ lastPersona.role_scope || '—' } · char: ${ lastPersona.char_role || '—' }\nBudget: ~${ lastPersona.max_chars_target || 0 }ch / ${ lastPersona.max_tokens_hint || 0 }tok` }
							>🎭 { lastPersona.slug }</span>
						) : null }
						<SLABadge convId={ convId } size="md" />
					</div>
					<div className="text-[11px] text-slate-500 truncate">
						<span className="font-mono">#{ convId }</span>
						{ inbox ? <> · { inbox.name } <span className="text-slate-400">({ cMeta.label })</span></> : null }
					</div>
				</div>
				<div className="flex items-center gap-1 relative">
					<button type="button"
						onClick={ () => setLabelsOpen( ( v ) => ! v ) }
						className={ 'px-2 py-1 text-[11px] border ' + ( labelsOpen ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : 'border-slate-100 hover:bg-slate-50 text-slate-600' ) }
						title="Gán labels"
					>🏷 Labels</button>
					<button type="button"
						onClick={ () => setConvertOpen( true ) }
						className="px-2 py-1 text-[11px] border border-emerald-200 text-emerald-700 bg-emerald-50 hover:bg-emerald-100 font-semibold"
						title="Tạo Lead trong sales pipeline"
					>🎯 Lead</button>
					<SnoozeMenu
						convId={ convId }
						isSnoozed={ !! conv?.is_snoozed }
						snoozedUntil={ conv?.snoozed_until_iso || conv?.snoozed_until }
					>
						<button type="button"
							className={ 'px-2 py-1 text-[11px] border ' + ( conv?.is_snoozed ? 'bg-amber-50 border-amber-200 text-amber-700' : 'border-slate-100 hover:bg-slate-50 text-slate-600' ) }
							title={ conv?.is_snoozed ? 'Đang snooze — click để xem / wake' : 'Snooze hội thoại' }
						>💤 { conv?.is_snoozed ? 'Snoozed' : 'Snooze' }</button>
					</SnoozeMenu>
					{ conv?.status !== 'resolved' ? (
						<button type="button"
							disabled={ resolveState.isLoading }
							onClick={ () => resolve( { convId } ) }
							className="px-2.5 py-1 text-[11px] border border-slate-100 hover:bg-slate-50 text-slate-600"
							title="Đánh dấu đã xử lý"
						>✓ Resolve</button>
					) : (
						<span className="px-2.5 py-1 text-[11px] bg-slate-50 text-slate-500">Resolved</span>
					) }

					{ labelsOpen ? (
						<div className="absolute right-0 top-full mt-1 z-20 bg-white border border-slate-200 shadow-lg rounded-md p-2 w-[260px]">
							<div className="flex items-center justify-between mb-1.5">
								<span className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">Labels</span>
								<button type="button" onClick={ () => setLabelsOpen( false ) } className="text-slate-400 hover:text-slate-700 text-[14px] leading-none">×</button>
							</div>
							<LabelChips convId={ convId } />
						</div>
					) : null }
				</div>
			</header>

			{ /* —— Scrollable message stream —— */ }
			{ ( () => {
				const sk = lastSkip && lastSkip.skip ? lastSkip.skip : null;
				if ( ! sk ) { return null; }
				const sig = `${ sk.kind || '' }|${ sk.at || '' }|${ sk.msg_id || '' }`;
				if ( skipDismissed === sig ) { return null; }
				const kindLabel = sk.kind === 'role_mismatch' ? '🚫 AI bị skip vì sai vai trò'
					: sk.kind === 'cooldown' ? '⏳ AI bị skip vì cooldown'
					: sk.kind === 'no_notebook' ? '📘 AI bị skip vì thiếu notebook'
					: '⚠ AI auto-reply bị skip';
				return (
					<div className="mx-3 mt-2 mb-1 px-3 py-2 rounded border border-rose-200 bg-rose-50 text-rose-800 text-[11px] flex items-start gap-2">
						<div className="flex-1 min-w-0">
							<div className="font-semibold">{ kindLabel }</div>
							<div className="text-[10px] opacity-80 mt-0.5">
								{ sk.reason || '—' }
								{ sk.template ? <> · template: <code>{ sk.template }</code></> : null }
								{ sk.character_id ? <> · char#{ sk.character_id }</> : null }
								{ sk.channel ? <> · { sk.channel }</> : null }
							</div>
						</div>
						<button type="button" onClick={ () => setSkipDismissed( sig ) } className="text-rose-500 hover:text-rose-700 font-bold text-[14px] leading-none" title="Ẩn">×</button>
					</div>
				);
			} )() }
			<div ref={ scrollRef } onScroll={ onScroll } className="flex-1 overflow-y-auto px-4 py-3 bg-slate-50 relative">
				{ grouped.length === 0 ? (
					<div className="text-center text-slate-400 italic text-xs mt-10">— Chưa có tin nhắn —</div>
				) : grouped.map( ( m ) => {
					if ( m.__sep ) {
						return (
							<div key={ m.id } className="flex items-center gap-2 my-3">
								<div className="flex-1 h-px bg-slate-200" />
								<span className="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">{ m.__sep }</span>
								<div className="flex-1 h-px bg-slate-200" />
							</div>
						);
					}
					const out  = m.message_type === 'outgoing';
					const note = m.message_type === 'private_note';
					if ( note ) {
						return (
							<div key={ m.id } className="flex justify-center mb-2">
								<div className="max-w-[82%] px-3 py-2 bg-amber-50/70 border border-amber-100 text-amber-900 text-xs">
									<div className="flex items-center gap-1.5 mb-0.5">
										<span className="font-semibold">📝 Private note</span>
										<ResponderPill kind={ m.responder_kind } userId={ m.responder_user_id } characterId={ m.character_id } characterEditUrl={ m.character_edit_url } userEditUrl={ m.responder_user_edit_url } />
									</div>
									<div className="break-words whitespace-pre-wrap">{ m.content }</div>
								</div>
							</div>
						);
					}
					const showTimeline = out && m.responder_kind === 'auto' && !! m.ai_metadata;
					return (
						<div key={ m.id } className={ 'flex flex-col mb-1.5 ' + ( out ? 'items-end' : 'items-start' ) }>
						<div className={
								'max-w-[72%] px-3 py-2 text-[13px] break-words ' + ( out
									? 'bg-indigo-500 text-white rounded-2xl rounded-br-sm'
									: 'bg-white border border-slate-100 text-slate-800 rounded-2xl rounded-bl-sm'
								)
							}>
								{ m.content
									? <div className="whitespace-pre-wrap">{ m.content }</div>
									: <em className={ out ? 'text-indigo-200' : 'text-slate-400' }>(no text)</em>
								}
								{ ( m.attachments || [] ).map( ( a ) => (
									a.file_type === 'image'
										? <img key={ a.id } src={ a.data_url } className="max-w-[260px] rounded-lg mt-1.5 block" alt="" />
										: <a key={ a.id } href={ a.data_url } target="_blank" rel="noopener" className="block mt-1 underline text-[12px]">📎 { a.file_type }</a>
								) ) }
								<div className={ 'flex flex-wrap items-center gap-1.5 mt-1 text-[10px] ' + ( out ? 'text-indigo-100/90' : 'text-slate-400' ) }>
									<span>{ fmtAbsTime( m.created_at ) }</span>
									{ out ? <ResponderPill kind={ m.responder_kind } userId={ m.responder_user_id } characterId={ m.character_id } characterEditUrl={ m.character_edit_url } userEditUrl={ m.responder_user_edit_url } /> : null }
									{ m.ai_metadata ? <span title="AI metadata">🧠</span> : null }
									{ m.status === 'sent' && out ? <span title="sent">✓</span> : null }
									{ m.status === 'failed' ? <span className={ ( out ? 'text-rose-200' : 'text-rose-500' ) + ' font-semibold' } title="dispatch failed">✗ failed</span> : null }
								</div>
							</div>
							{ showTimeline ? <ThinkingTimeline aiMetadata={ m.ai_metadata } /> : null }
						</div>
					);
				} ) }

				{ ! atBottom ? (
					<button type="button"
						onClick={ () => { if ( scrollRef.current ) { scrollRef.current.scrollTop = scrollRef.current.scrollHeight; } } }
						className="sticky bottom-2 ml-auto block bg-white border border-slate-100 rounded-full w-8 h-8 text-slate-500 hover:text-slate-700"
						title="Scroll to bottom"
					>↓</button>
				) : null }
			</div>

			<Composer convId={ convId } />
			<ConvertToLeadSheet convId={ convId } open={ convertOpen } onOpenChange={ setConvertOpen } />
		</section>
	);
}
