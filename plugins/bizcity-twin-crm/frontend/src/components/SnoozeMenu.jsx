/**
 * SnoozeMenu — PHASE 0.35 M-FE.W2.
 *
 * Dropdown attached to a kebab/clock trigger that lets the agent snooze a
 * conversation for a preset duration or pick a custom datetime. Calls
 * `useSnoozeConversationMutation` (which posts `{duration_seconds}` to BE).
 *
 * Renders nothing for the trigger — caller passes children that act as the
 * Radix dropdown trigger (so it can sit beside Resolve / kebab in the header).
 */
import React, { useState } from 'react';
import {
	DropdownMenu,
	DropdownMenuTrigger,
	DropdownMenuContent,
	DropdownMenuItem,
	DropdownMenuSeparator,
	DropdownMenuLabel,
} from './ui/dropdown-menu.jsx';
import {
	useSnoozeConversationMutation,
	useUnsnoozeConversationMutation,
} from '../redux/api/crmApi.js';

const PRESETS = [
	{ label: '1 giờ',      seconds: 60 * 60 },
	{ label: '3 giờ',      seconds: 3 * 60 * 60 },
	{ label: 'Đến mai 9h', custom: 'tomorrow_9' },
	{ label: 'Tuần sau',   seconds: 7 * 24 * 60 * 60 },
];

function tomorrow9() {
	const d = new Date();
	d.setDate( d.getDate() + 1 );
	d.setHours( 9, 0, 0, 0 );
	return Math.max( 60, Math.floor( ( d.getTime() - Date.now() ) / 1000 ) );
}

export default function SnoozeMenu( { convId, isSnoozed, snoozedUntil, children } ) {
	const [ snooze, snoozeState ]     = useSnoozeConversationMutation();
	const [ unsnooze, unsnoozeState ] = useUnsnoozeConversationMutation();
	const [ customOpen, setCustomOpen ] = useState( false );
	const [ customVal, setCustomVal ]   = useState( '' );

	const busy = snoozeState.isLoading || unsnoozeState.isLoading;

	const pick = async ( preset ) => {
		if ( ! convId || busy ) { return; }
		const dur = preset.custom === 'tomorrow_9' ? tomorrow9() : preset.seconds;
		try { await snooze( { convId, duration_seconds: dur } ).unwrap(); }
		catch ( _ ) { /* no-op — header re-renders on next poll */ }
	};

	const pickCustom = async () => {
		if ( ! customVal || ! convId || busy ) { return; }
		try {
			await snooze( { convId, until: new Date( customVal ).toISOString() } ).unwrap();
			setCustomOpen( false );
			setCustomVal( '' );
		} catch ( _ ) { /* no-op */ }
	};

	const wake = async () => {
		if ( ! convId || busy ) { return; }
		try { await unsnooze( { convId } ).unwrap(); } catch ( _ ) { /* no-op */ }
	};

	return (
		<DropdownMenu>
			<DropdownMenuTrigger asChild>{ children }</DropdownMenuTrigger>
			<DropdownMenuContent align="end" className="min-w-[200px]">
				<DropdownMenuLabel>
					{ isSnoozed
						? <span className="text-amber-700">💤 Đang snooze</span>
						: <span>💤 Snooze hội thoại</span>
					}
				</DropdownMenuLabel>
				{ isSnoozed && snoozedUntil ? (
					<div className="px-2 pb-1 text-[10px] text-slate-500 font-mono">
						Đến { String( snoozedUntil ).replace( 'T', ' ' ).slice( 0, 16 ) }
					</div>
				) : null }
				<DropdownMenuSeparator />
				{ PRESETS.map( ( p ) => (
					<DropdownMenuItem
						key={ p.label }
						disabled={ busy }
						onSelect={ ( e ) => { e.preventDefault(); pick( p ); } }
					>{ p.label }</DropdownMenuItem>
				) ) }
				<DropdownMenuItem
					disabled={ busy }
					onSelect={ ( e ) => { e.preventDefault(); setCustomOpen( ( v ) => ! v ); } }
				>🗓 Tuỳ chỉnh…</DropdownMenuItem>
				{ customOpen ? (
					<div className="px-2 py-1.5 border-t border-slate-100 flex items-center gap-1">
						<input
							type="datetime-local"
							value={ customVal }
							onChange={ ( e ) => setCustomVal( e.target.value ) }
							onClick={ ( e ) => e.stopPropagation() }
							onKeyDown={ ( e ) => e.stopPropagation() }
							className="flex-1 px-1.5 py-1 border border-slate-200 text-[11px]"
						/>
						<button type="button"
							disabled={ ! customVal || busy }
							onClick={ ( e ) => { e.stopPropagation(); pickCustom(); } }
							className="px-2 py-1 text-[11px] bg-indigo-600 text-white disabled:opacity-50"
						>OK</button>
					</div>
				) : null }
				{ isSnoozed ? (
					<>
						<DropdownMenuSeparator />
						<DropdownMenuItem disabled={ busy } onSelect={ ( e ) => { e.preventDefault(); wake(); } }>
							⏰ Đánh thức ngay
						</DropdownMenuItem>
					</>
				) : null }
			</DropdownMenuContent>
		</DropdownMenu>
	);
}
