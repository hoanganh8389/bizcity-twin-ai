/**
 * SLABadge — compact countdown / breach indicator for a conversation's
 * applied SLA. PHASE 0.35 M4.W4.
 *
 * Props:
 *   convId  number — conversation id (required)
 *   size    'sm' | 'md'  — visual scale (default 'sm')
 *   inline  boolean — when true, renders as a compact pill suitable for a list row
 *
 * Behaviour:
 *   - Uses RTK Query useGetConversationSlaQuery; returns null when no SLA applied
 *   - Picks the most-relevant deadline (frt → nrt → rt) based on `state`
 *   - Auto-rerenders every 30s so countdown stays fresh without polling REST
 */
import React, { useEffect, useState } from 'react';
import { useGetConversationSlaQuery } from '../redux/api/crmApi.js';

function fmtDelta( seconds ) {
	const abs = Math.abs( seconds );
	if ( abs < 60 )      { return Math.round( abs ) + 's'; }
	if ( abs < 3600 )    { return Math.round( abs / 60 ) + 'm'; }
	if ( abs < 86400 )   { return Math.round( abs / 3600 ) + 'h'; }
	return Math.round( abs / 86400 ) + 'd';
}

function pickDeadline( applied ) {
	if ( ! applied ) { return null; }
	const state = ( applied.state || '' ).toLowerCase();
	// Prefer the deadline tied to the current state when present.
	if ( state === 'breached_frt' && applied.frt_due_at )    { return { kind: 'FRT', at: applied.frt_due_at, breached: true }; }
	if ( state === 'breached_nrt' && applied.nrt_due_at )    { return { kind: 'NRT', at: applied.nrt_due_at, breached: true }; }
	if ( state === 'breached_rt'  && applied.rt_due_at  )    { return { kind: 'RT',  at: applied.rt_due_at,  breached: true }; }
	if ( applied.frt_due_at ) { return { kind: 'FRT', at: applied.frt_due_at, breached: false }; }
	if ( applied.nrt_due_at ) { return { kind: 'NRT', at: applied.nrt_due_at, breached: false }; }
	if ( applied.rt_due_at  ) { return { kind: 'RT',  at: applied.rt_due_at,  breached: false }; }
	return null;
}

export default function SLABadge( { convId, size = 'sm', inline = false } ) {
	const { data, isFetching } = useGetConversationSlaQuery( convId, { skip: ! convId } );
	const [ , forceTick ] = useState( 0 );
	useEffect( () => {
		const t = setInterval( () => forceTick( ( v ) => v + 1 ), 30000 );
		return () => clearInterval( t );
	}, [] );

	if ( ! convId ) { return null; }
	if ( isFetching && ! data ) { return null; }

	const applied = data?.applied;
	const dl      = pickDeadline( applied );
	if ( ! dl ) { return null; }

	const now      = Math.floor( Date.now() / 1000 );
	const delta    = dl.at - now;
	const breached = dl.breached || delta < 0;
	const warn     = ! breached && delta < 600; // 10m

	const palette = breached
		? { bg: '#fee2e2', fg: '#b91c1c', icon: '🔴' }
		: warn
			? { bg: '#fef3c7', fg: '#b45309', icon: '⏱' }
			: { bg: '#ecfdf5', fg: '#047857', icon: '⏱' };

	const fontSize = size === 'md' ? 12 : 10;
	const padding  = size === 'md' ? '3px 8px' : '1px 6px';
	const text     = breached ? 'SLA breach ' + dl.kind : dl.kind + ' ' + ( delta >= 0 ? 'in ' : '−' ) + fmtDelta( delta );

	const style = {
		display:        inline ? 'inline-flex' : 'inline-flex',
		alignItems:     'center',
		gap:            4,
		padding,
		borderRadius:   4,
		fontSize,
		fontWeight:     600,
		lineHeight:     1.2,
		background:     palette.bg,
		color:          palette.fg,
		whiteSpace:     'nowrap',
	};

	return (
		<span style={ style } title={ 'SLA · state=' + applied.state + ' · due=' + new Date( dl.at * 1000 ).toLocaleString() }>
			<span aria-hidden>{ palette.icon }</span>
			<span>{ text }</span>
		</span>
	);
}
