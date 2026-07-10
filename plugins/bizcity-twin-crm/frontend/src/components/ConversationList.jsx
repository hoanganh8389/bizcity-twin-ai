import React, { useMemo, useState } from 'react';
import { useGetConversationsQuery, useGetInboxesQuery, useGetLabelsQuery } from '../redux/api/crmApi.js';
import { fmtRelTime, channelMeta, avatarGradient, initials } from '../lib/format.js';
import SLABadge from './SLABadge.jsx';

const STATUS_FILTERS = [
	{ key: 'open',     label: 'Open',     dot: 'bg-emerald-500' },
	{ key: 'pending',  label: 'Pending',  dot: 'bg-amber-500'   },
	{ key: 'snoozed',  label: 'Snoozed',  dot: 'bg-violet-500'  },
	{ key: 'resolved', label: 'Resolved', dot: 'bg-slate-400'   },
	{ key: 'all',      label: 'All',      dot: 'bg-indigo-500'  },
];

function StatusPill( { status } ) {
	const cls = status === 'open' ? 'bg-emerald-100 text-emerald-700'
		: status === 'pending' ? 'bg-amber-100 text-amber-700'
		: status === 'resolved' ? 'bg-slate-100 text-slate-500'
		: 'bg-slate-100 text-slate-600';
	return <span className={ 'text-[9px] uppercase tracking-wide px-1.5 py-[1px] rounded font-semibold ' + cls }>{ status }</span>;
}

const PAGE_SIZE = 50;

export default function ConversationList( { inboxId, selectedConvId, onSelectConv } ) {
	const [ statusFilter, setStatusFilter ] = useState( 'open' );
	const [ q, setQ ]                       = useState( '' );
	const [ labelId, setLabelId ]           = useState( 0 );
	const [ priority, setPriority ]         = useState( 0 );
	const [ limit, setLimit ]               = useState( PAGE_SIZE );

	// Reset page size whenever the active filters change so we don't keep
	// over-fetching after the user pivots inboxes / status / label.
	React.useEffect( () => { setLimit( PAGE_SIZE ); }, [ inboxId, statusFilter, labelId, priority ] );

	const { data: convs = [], isLoading, isFetching } = useGetConversationsQuery(
		{
			inbox_id:  inboxId || undefined,
			status:    statusFilter === 'all' ? undefined : statusFilter,
			label_id:  labelId || undefined,
			priority:  priority || undefined,
			q:         q.trim() || undefined,
			limit,
		},
		{ pollingInterval: 3000 }
	);
	// "Load more" is meaningful only when the API returned a full page —
	// fewer rows means we already hit the tail.
	const canLoadMore = convs.length >= limit;
	const { data: inboxes = [] } = useGetInboxesQuery();
	const { data: labelCatalog = [] } = useGetLabelsQuery();
	const inboxMap = useMemo( () => Object.fromEntries( inboxes.map( ( i ) => [ i.id, i ] ) ), [ inboxes ] );
	const labelByTitle = useMemo( () => {
		const out = {};
		for ( const l of labelCatalog ) { out[ ( l.title || l.name || '' ).toLowerCase() ] = l; }
		return out;
	}, [ labelCatalog ] );

	const filtered = useMemo( () => {
		if ( ! q.trim() ) { return convs; }
		const needle = q.trim().toLowerCase();
		return convs.filter( ( c ) => {
			const name = ( c.contact?.name || '' ).toLowerCase();
			const last = ( c.last_message?.content || '' ).toLowerCase();
			return name.includes( needle ) || last.includes( needle ) || String( c.id ).includes( needle );
		} );
	}, [ convs, q ] );

	const headerLabel = inboxId ? ( inboxMap[ inboxId ]?.name || `Inbox #${ inboxId }` ) : 'All conversations';

	return (
		<section className="conv-list-pane bg-white overflow-hidden flex flex-col">
			<header className="px-3 pt-3 pb-2 border-b border-slate-50 sticky top-0 bg-white z-10 space-y-2">
				<div className="flex items-center justify-between">
					<div className="font-semibold text-[13px] truncate">{ headerLabel }</div>
					<span className="text-[10px] text-slate-400 font-mono">{ filtered.length }/{ convs.length }</span>
				</div>
				<div className="relative">
					<span className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
					<input
						value={ q }
						onChange={ ( e ) => setQ( e.target.value ) }
						placeholder="Tìm theo tên, nội dung, #id…"
						className="w-full pl-7 pr-2 py-1.5 text-[12px] bg-slate-50 border border-slate-100 outline-none focus:border-indigo-300 focus:bg-white"
					/>
				</div>
				<div className="flex gap-1">
					{ STATUS_FILTERS.map( ( f ) => {
						const on = statusFilter === f.key;
						return (
							<button key={ f.key } type="button"
								onClick={ () => setStatusFilter( f.key ) }
								className={ 'flex items-center gap-1 px-2 py-1 text-[11px] transition-colors ' + ( on ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-500 hover:bg-slate-50' ) }
							>
								<span className={ 'w-1.5 h-1.5 rounded-full ' + f.dot } />
								<span>{ f.label }</span>
							</button>
						);
					} ) }
				</div>
				{ labelCatalog.length > 0 && (
					<div className="flex gap-1 flex-wrap">
						<button type="button"
							onClick={ () => setLabelId( 0 ) }
							className={ 'px-2 py-0.5 text-[10px] rounded-full border ' + ( labelId === 0 ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-semibold' : 'border-slate-200 text-slate-500 hover:bg-slate-50' ) }
						>tất cả</button>
						{ labelCatalog.slice( 0, 8 ).map( ( l ) => {
							const on = labelId === Number( l.id );
							const color = ( l.color || '#64748b' );
							return (
								<button key={ l.id } type="button"
									onClick={ () => setLabelId( on ? 0 : Number( l.id ) ) }
									title={ l.description || l.title }
									style={ on ? { background: color, borderColor: color, color: '#fff' } : { borderColor: color, color } }
									className="px-2 py-0.5 text-[10px] rounded-full border font-semibold"
								>{ l.title || l.name }</button>
							);
						} ) }
					</div>
				) }
				{/* Priority filter */}
				<div className="flex gap-1 items-center flex-wrap">
					<span className="text-[10px] text-slate-400 mr-1">Ưu tiên:</span>
					{ [ [ 0, 'Tất cả' ], [ 1, 'Thấp' ], [ 3, 'Trung' ], [ 5, 'Cao' ] ].map( ( [ val, lbl ] ) => (
						<button key={ val } type="button"
							onClick={ () => setPriority( val ) }
							className={ 'px-2 py-0.5 text-[10px] rounded-full border ' + ( priority === val ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-semibold' : 'border-slate-200 text-slate-500 hover:bg-slate-50' ) }
						>{ lbl }</button>
					) ) }
				</div>
			</header>

			<div className="flex-1 overflow-y-auto">
				{ isLoading && convs.length === 0 ? (
					<div className="p-4 text-slate-400 text-xs">Loading…</div>
				) : filtered.length === 0 ? (
					<div className="p-6 text-center text-slate-400 text-xs italic">
						<div className="empty-art mb-2">📭</div>
						{ q ? 'Không khớp tìm kiếm.' : 'Chưa có hội thoại.' }
					</div>
				) : filtered.map( ( c ) => {
					const active  = selectedConvId === c.id;
					const last    = c.last_message;
					const preview = last ? ( last.content || '(attachment)' ) : '(no message)';
					const isInc   = last?.message_type === 'incoming';
					const unread  = isInc && ! active && c.last_activity_at;
					const inbox   = inboxMap[ c.inbox_id ];
					const cMeta   = channelMeta( inbox?.channel_type );
					const grad    = avatarGradient( c.contact?.name || c.id );
					return (
						// [2026-06-21 Johnny Chu] HOTFIX — border-slate-100 (was slate-50 = nearly invisible on white), py-3+gap-3 for breathing room
						<div key={ c.id } onClick={ () => onSelectConv( c.id, c.inbox_id ) }
							className={ 'group flex gap-3 px-3 py-3 cursor-pointer border-b border-slate-100 transition-colors ' + ( active ? 'row-active' : 'hover:bg-slate-50' ) }
						>
							<div className="relative shrink-0">
								{ c.contact?.avatar_url
									? <img src={ c.contact.avatar_url } className="w-10 h-10 rounded-full object-cover bg-slate-100" alt="" />
									: <div className={ 'w-10 h-10 rounded-full bg-gradient-to-br text-white flex items-center justify-center font-semibold text-[12px] ' + grad }>{ initials( c.contact?.name, '?' ) }</div>
								}
								<span
									className="absolute -bottom-0.5 -right-0.5 w-4 h-4 rounded-full border-2 border-white text-[9px] flex items-center justify-center"
									style={ { background: cMeta.color, color: '#fff' } }
									title={ cMeta.label }
								>{ cMeta.icon }</span>
							</div>
							<div className="min-w-0 flex-1">
								<div className="flex items-center gap-1.5">
									<span className={ 'truncate text-[13px] ' + ( unread ? 'font-bold text-slate-900' : 'font-semibold text-slate-800' ) }>
										{ c.contact?.name || `#${ c.id }` }
									</span>
									<span className="ml-auto text-[10px] text-slate-400 shrink-0">{ fmtRelTime( c.last_activity_at || c.created_at ) }</span>
								</div>
								<div className="flex items-center gap-1.5">
									<span className={ 'truncate flex-1 text-[12px] ' + ( unread ? 'text-slate-700' : 'text-slate-500' ) }>
										{ last && ! isInc ? <span className="text-slate-400">↩ </span> : null }
										{ preview }
									</span>
									{ unread ? <span className="w-2 h-2 rounded-full bg-indigo-500 shrink-0" /> : null }
								</div>
								<div className="flex items-center gap-1.5 mt-1 flex-wrap">
									<StatusPill status={ c.status } />
									{ c.is_snoozed ? (
										<span
											className="text-[9px] px-1.5 py-[1px] rounded font-semibold bg-violet-100 text-violet-700 border border-violet-200 inline-flex items-center gap-0.5"
											title={ c.snoozed_until_iso ? `Snoozed đến ${ c.snoozed_until_iso }` : 'Snoozed' }
										>💤{ c.snoozed_until_iso ? ' ' + String( c.snoozed_until_iso ).slice( 5, 16 ).replace( 'T', ' ' ) : '' }</span>
									) : null }
									<SLABadge convId={ c.id } />
									{ ( c.labels || [] ).slice( 0, 3 ).map( ( title ) => {
										const meta = labelByTitle[ String( title ).toLowerCase() ];
										const color = meta?.color || '#64748b';
										return (
											<span key={ title }
												style={ { background: color + '22', color, border: '1px solid ' + color + '55' } }
												className="text-[9px] px-1.5 py-[1px] rounded font-semibold"
											>{ title }</span>
										);
									} ) }
									{ inbox ? <span className="text-[9px] text-slate-400 truncate ml-auto" title={ inbox.name }>· { inbox.name }</span> : null }
								</div>
							</div>
						</div>
					);
				} ) }
				{ filtered.length > 0 && canLoadMore && ! q && (
					<div className="p-3 text-center border-t border-slate-50">
						<button
							type="button"
							onClick={ () => setLimit( ( prev ) => prev + PAGE_SIZE ) }
							disabled={ isFetching }
							className="px-3 py-1.5 text-[12px] font-semibold rounded-md border border-indigo-200 text-indigo-700 bg-indigo-50 hover:bg-indigo-100 disabled:opacity-60 disabled:cursor-wait transition-colors"
						>
							{ isFetching ? '⏳ Đang tải…' : `⬇ Tải thêm (${ PAGE_SIZE })` }
						</button>
						<div className="mt-1 text-[10px] text-slate-400 font-mono">đã hiển thị { convs.length }</div>
					</div>
				) }
				{ filtered.length > 0 && ! canLoadMore && ! q && convs.length > PAGE_SIZE && (
					<div className="p-3 text-center text-[10px] text-slate-400 italic border-t border-slate-50">
						— Đã hết hội thoại ({ convs.length }) —
					</div>
				) }
			</div>
		</section>
	);
}
