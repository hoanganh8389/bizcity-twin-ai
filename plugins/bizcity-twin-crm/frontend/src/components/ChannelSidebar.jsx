import React, { useMemo } from 'react';
import { useGetInboxesQuery } from '../redux/api/crmApi.js';
import { channelMeta } from '../lib/format.js';

/**
 * Left rail — Chatwoot-style inbox tree, grouped by channel_type with
 * channel icon, accent colour, and per-inbox count badge (when > 0).
 */
export default function ChannelSidebar( { selectedInboxId, onSelectInbox } ) {
	const { data: inboxes = [], isLoading } = useGetInboxesQuery( undefined, { pollingInterval: 5000 } );
	const groups = useMemo( () => {
		const g = {};
		inboxes.forEach( ( i ) => { ( g[ i.channel_type ] = g[ i.channel_type ] || [] ).push( i ); } );
		return g;
	}, [ inboxes ] );

	const showAll = selectedInboxId === 0;

	return (
		<nav className="bg-white overflow-y-auto flex flex-col">
			<header className="px-4 py-3 border-b border-slate-50 sticky top-0 bg-white z-10">
				<div className="flex items-center gap-2">
					<span className="w-7 h-7 rounded-md bg-gradient-to-br from-indigo-500 to-violet-600 text-white flex items-center justify-center text-xs font-bold">B</span>
					<div className="flex-1 min-w-0">
						<div className="text-[13px] font-semibold leading-tight truncate">Twin&nbsp;CRM</div>
						<div className="text-[10px] text-slate-400 leading-tight">Inbox console</div>
					</div>
				</div>
			</header>

			<button
				type="button"
				onClick={ () => onSelectInbox( 0 ) }
				className={ 'mx-2 mt-2 mb-1 px-3 py-2 text-left text-[12px] flex items-center gap-2 ' + ( showAll ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'hover:bg-slate-50 text-slate-700' ) }
			>
				<span className="text-base leading-none">📥</span>
				<span className="flex-1">All conversations</span>
			</button>

			{ isLoading ? (
				<div className="p-4 text-slate-400 text-xs">Loading…</div>
			) : inboxes.length === 0 ? (
				<div className="p-4 text-slate-400 text-xs italic">Chưa có inbox nào.</div>
			) : (
				<div className="pb-4">
					{ Object.keys( groups ).sort().map( ( ch ) => {
						const meta = channelMeta( ch );
						return (
							<div key={ ch } className="mt-2">
								<div className="text-[10px] uppercase text-slate-400 px-4 py-1 tracking-wider flex items-center gap-1.5">
									<span>{ meta.icon }</span>
									<span>{ meta.label }</span>
									<span className="ml-auto font-mono normal-case text-slate-300">{ groups[ ch ].length }</span>
								</div>
								{ groups[ ch ].map( ( i ) => {
									const active = selectedInboxId === i.id;
									return (
										<button key={ i.id } type="button"
											onClick={ () => onSelectInbox( i.id ) }
											className={ 'w-full text-left px-4 py-2 cursor-pointer flex items-center gap-2 text-[12px] transition-colors ' + ( active ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'hover:bg-slate-50' ) }
											title={ i.name }
										>
											<span className="w-1.5 h-1.5 rounded-full shrink-0" style={ { background: meta.color } } />
											<span className="truncate flex-1">{ i.name }</span>
											{ i.unread_count ? (
												<span className="text-[10px] bg-indigo-500 text-white font-semibold px-1.5 py-[1px] rounded-full">{ i.unread_count }</span>
											) : null }
										</button>
									);
								} ) }
							</div>
						);
					} ) }
				</div>
			) }
		</nav>
	);
}
