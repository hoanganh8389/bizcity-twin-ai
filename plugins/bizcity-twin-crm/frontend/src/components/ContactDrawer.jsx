import React, { useState } from 'react';
import { useGetConversationQuery, useGetContactQuery, usePatchContactMutation } from '../redux/api/crmApi.js';
import OrderTab from './OrderTab.jsx';
import LabelChips from './LabelChips.jsx';

/**
 * Right-rail Contact Drawer (PHASE 0.34 FE-M6).
 *
 * - Fetches the active conversation to learn its contact_id.
 * - Then fetches /contacts/{id} aggregator (contact + inboxes + recent
 *   conversations + bound Twin Gurus).
 *
 * Pure read-only for M6 — edit/merge actions land in M7+.
 */
export default function ContactDrawer( { convId, onSelectConv } ) {
	const { data: conv } = useGetConversationQuery( convId, { skip: ! convId } );
	const contactId      = conv?.contact?.id || 0;
	const { data: payload, isFetching } = useGetContactQuery( contactId, { skip: ! contactId } );
	const [ tab, setTab ] = useState( 'contact' );
	// [2026-06-13 Johnny Chu] HOTFIX — email collection warning state
	const [ patchContact, patchState ] = usePatchContactMutation();
	const [ emailInput, setEmailInput ] = useState( '' );
	const [ emailEditing, setEmailEditing ] = useState( false );

	if ( ! convId ) {
		return (
			<aside className="contact-drawer bg-white p-4 text-xs text-slate-400 italic overflow-y-auto">
				Chọn một hội thoại để xem chi tiết liên hệ.
			</aside>
		);
	}
	if ( ! contactId ) {
		return (
			<aside className="contact-drawer bg-white p-4 text-xs text-slate-500 overflow-y-auto">
				Đang lấy thông tin liên hệ…
			</aside>
		);
	}

	const c        = payload?.contact;
	const inboxes  = payload?.inboxes || [];
	const convs    = payload?.conversations || [];
	const gurus    = payload?.gurus || [];
	const initials = ( c?.name || '?' ).trim().split( /\s+/ ).map( ( w ) => w[ 0 ] ).slice( 0, 2 ).join( '' ).toUpperCase();

	return (
		<aside className="contact-drawer bg-white flex flex-col overflow-hidden">
			<div className="border-b border-slate-100 bg-white flex items-stretch text-[12px]">
				<button
					type="button"
					onClick={ () => setTab( 'contact' ) }
					className={ 'flex-1 px-3 py-2.5 font-semibold border-b-2 ' + ( tab === 'contact' ? 'border-indigo-500 text-indigo-700 bg-indigo-50/40' : 'border-transparent text-slate-500 hover:bg-slate-50' ) }
				>👤 Contact</button>
				<button
					type="button"
					onClick={ () => setTab( 'order' ) }
					className={ 'flex-1 px-3 py-2.5 font-semibold border-b-2 ' + ( tab === 'order' ? 'border-indigo-500 text-indigo-700 bg-indigo-50/40' : 'border-transparent text-slate-500 hover:bg-slate-50' ) }
				>📦 Đặt đơn</button>
			</div>

			{ tab === 'order' ? (
				<OrderTab convId={ convId } contact={ payload?.contact } />
			) : (
			<>
			<div className="px-4 py-3 border-b border-slate-50 bg-white font-semibold flex items-center justify-between">
				<span>Contact</span>
				<span className="text-[10px] font-normal text-gray-400">PHASE 0.34 · FE-M6</span>
			</div>

			<div className="flex-1 overflow-y-auto p-4 space-y-5">
				{ isFetching && ! payload ? (
					<div className="text-xs text-gray-400 italic">Loading…</div>
				) : null }

				{ /* ── Identity card ── */ }
				<section>
					<div className="flex items-center gap-3">
						{ c?.avatar_url
							? <img src={ c.avatar_url } alt="" className="w-12 h-12 rounded-full object-cover bg-gray-100" />
							: <div className="w-12 h-12 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center font-semibold text-sm">{ initials }</div>
						}
						<div className="min-w-0">
							<div className="font-semibold truncate">{ c?.name || '(no name)' }</div>
							<div className="text-[11px] text-gray-500 truncate">#{ c?.id }{ c?.wp_user_id ? ` · WP user #${ c.wp_user_id }` : '' }</div>
						</div>
					</div>
					<dl className="mt-3 text-xs space-y-1">
						{ c?.email ? <div className="flex gap-2"><dt className="w-14 text-gray-500">Email</dt><dd className="break-all">{ c.email }</dd></div> : null }
						{ c?.phone ? <div className="flex gap-2"><dt className="w-14 text-gray-500">Phone</dt><dd>{ c.phone }</dd></div> : null }
						{ c?.created_at ? <div className="flex gap-2"><dt className="w-14 text-gray-500">Created</dt><dd className="text-gray-600">{ c.created_at }</dd></div> : null }
						{ c?.updated_at ? <div className="flex gap-2"><dt className="w-14 text-gray-500">Updated</dt><dd className="text-gray-600">{ c.updated_at }</dd></div> : null }
					</dl>

					{ /* [2026-06-13 Johnny Chu] HOTFIX — warn agent to collect email for automation */ }
					{ ! c?.email ? (
						<div className="mt-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">
							<div className="flex items-start gap-2">
								<span className="mt-0.5 shrink-0 text-amber-500">⚠️</span>
								<div className="flex-1">
									<span className="font-semibold">Chưa có email.</span>
									{ ' ' }Hỏi khách để lấy email — cần thiết để kích hoạt tự động gửi email (Gmail/SMTP).
									{ ! emailEditing ? (
										<button
											type="button"
											onClick={ () => { setEmailInput( '' ); setEmailEditing( true ); } }
											className="ml-1 underline decoration-dotted hover:no-underline font-semibold"
										>Nhập ngay →</button>
									) : null }
								</div>
							</div>
							{ emailEditing ? (
								<form
									className="mt-2 flex gap-1.5"
									onSubmit={ ( e ) => {
										e.preventDefault();
										if ( ! emailInput.trim() ) { return; }
										patchContact( { id: contactId, email: emailInput.trim() } )
											.unwrap()
											.then( () => { setEmailEditing( false ); setEmailInput( '' ); } )
											.catch( () => {} );
									} }
								>
									<input
										type="email"
										required
										value={ emailInput }
										onChange={ ( e ) => setEmailInput( e.target.value ) }
										placeholder="email@domain.com"
										className="flex-1 min-w-0 border border-amber-300 rounded px-2 py-1 text-[11px] bg-white focus:outline-none focus:ring-1 focus:ring-amber-400"
									/>
									<button
										type="submit"
										disabled={ patchState.isLoading }
										className="px-2 py-1 rounded bg-amber-500 text-white text-[11px] font-semibold hover:bg-amber-600 disabled:opacity-50"
									>{ patchState.isLoading ? '…' : 'Lưu' }</button>
									<button
										type="button"
										onClick={ () => setEmailEditing( false ) }
										className="px-2 py-1 rounded border border-amber-200 text-amber-700 text-[11px] hover:bg-amber-100"
									>Huỷ</button>
								</form>
							) : null }
							{ patchState.isError ? (
								<div className="mt-1 text-[10px] text-red-600">Lưu thất bại — kiểm tra email hợp lệ.</div>
							) : null }
						</div>
					) : null }
				</section>

				{ /* ── Additional attributes (FB profile, custom fields, …) ── */ }
				{ c?.attributes && typeof c.attributes === 'object' && Object.keys( c.attributes ).length > 0 ? (
					<section>
						<h4 className="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-2">Attributes</h4>
						<dl className="text-xs space-y-1">
							{ Object.entries( c.attributes ).map( ( [ k, v ] ) => (
								<div key={ k } className="flex gap-2">
									<dt className="w-24 text-gray-500 truncate" title={ k }>{ k }</dt>
									<dd className="break-all flex-1 text-gray-700">{ typeof v === 'object' ? JSON.stringify( v ) : String( v ) }</dd>
								</div>
							) ) }
						</dl>
					</section>
				) : null }

				{ /* ── Conversation labels (M3.W2) ── */ }
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-2">Labels (hội thoại)</h4>
					<LabelChips convId={ convId } />
				</section>

				{ /* ── Twin Guru pills ── */ }
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-2">Twin Gurus on duty</h4>
					{ gurus.length === 0 ? (
						<div className="text-xs text-gray-400 italic">— no guru bound to this contact's channels —</div>
					) : (
						<ul className="flex flex-wrap gap-1.5">
							{ gurus.map( ( g ) => {
								const tone = g.mode === 'manual' ? 'rose' : g.mode === 'hybrid' ? 'amber' : 'emerald';
								const cls  = {
									rose:    'bg-rose-50 text-rose-800 border-rose-200',
									amber:   'bg-amber-50 text-amber-800 border-amber-200',
									emerald: 'bg-emerald-50 text-emerald-800 border-emerald-200',
								}[ tone ];
								return (
									<li
										key={ `${ g.character_id }-${ g.platform }-${ g.account_id }` }
										className={ 'inline-flex items-center gap-1.5 px-2 py-1 rounded-full border text-[11px] ' + cls }
										title={ `${ g.platform } · account=${ g.account_id } · mode=${ g.mode }` }
									>
										{ g.avatar
											? <img src={ g.avatar } className="w-4 h-4 rounded-full object-cover" alt="" />
											: <span className="w-4 h-4 rounded-full bg-white/60 text-[8px] flex items-center justify-center font-bold">G</span>
										}
										<span className="font-medium">{ g.name || `Guru #${ g.character_id }` }</span>
										<span className="font-mono text-[9px] opacity-70">{ g.platform }</span>
									</li>
								);
							} ) }
						</ul>
					) }
				</section>

				{ /* ── Inboxes touched ── */ }
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-2">Channels</h4>
					{ inboxes.length === 0 ? (
						<div className="text-xs text-gray-400 italic">—</div>
					) : (
						<ul className="space-y-1 text-xs">
							{ inboxes.map( ( i ) => (
								<li key={ i.id } className="flex items-center justify-between gap-2 px-2 py-1.5 border border-slate-50 bg-slate-50/50">
									<span className="truncate"><span className="font-mono text-[10px] text-gray-500">{ i.channel_type }</span> · { i.name }</span>
									<span className="text-[10px] text-gray-400 shrink-0">#{ i.id }</span>
								</li>
							) ) }
						</ul>
					) }
				</section>

				{ /* ── Recent conversations ── */ }
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-2">Recent conversations ({ convs.length })</h4>
					{ convs.length === 0 ? (
						<div className="text-xs text-gray-400 italic">—</div>
					) : (
						<ul className="space-y-1">
							{ convs.map( ( cv ) => {
								const active = cv.id === convId;
								const status = cv.status;
								const sCls   = status === 'open' ? 'bg-emerald-100 text-emerald-700'
									: status === 'pending' ? 'bg-amber-100 text-amber-700'
									: status === 'resolved' ? 'bg-slate-100 text-slate-600'
									: 'bg-gray-100 text-gray-600';
								return (
									<li key={ cv.id }>
										<button
											type="button"
											className={ 'w-full text-left px-2 py-1.5 border text-xs flex items-start gap-2 ' + ( active ? 'border-indigo-200 bg-indigo-50' : 'border-slate-50 hover:bg-slate-50' ) }
											onClick={ () => ( onSelectConv && ! active ) ? onSelectConv( cv.id, cv.inbox_id ) : null }
										>
											<span className={ 'shrink-0 inline-block px-1.5 py-[1px] text-[9px] uppercase font-semibold rounded ' + sCls }>{ status }</span>
											<span className="min-w-0 flex-1">
												<span className="block truncate text-gray-800">{ cv.last_message?.content || <em className="text-gray-400">(no message)</em> }</span>
												<span className="block text-[10px] text-gray-400 truncate">#{ cv.id } · { cv.last_activity_at || cv.created_at }</span>
											</span>
										</button>
									</li>
								);
							} ) }
						</ul>
					) }
				</section>
			</div>
			</>
			) }
		</aside>
	);
}
