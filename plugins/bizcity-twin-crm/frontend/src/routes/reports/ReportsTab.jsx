// [2026-06-13 Johnny Chu] PHASE-0.44 A.2 — Recharts parity (Deplao AnalyticsPage)
import React, { useMemo, useState, useEffect } from 'react';
import {
	AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
	XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts';
import {
	useGetReportsAggregateQuery,
	useGetReportsAutoVsHumanQuery,
	useRunRollupNowMutation,
	useGetInboxesQuery,
	useGetLabelsQuery,
	// [2026-06-07 Johnny Chu] PHASE-0.40 G3.2 — Deplao parity 6 report hooks
	useGetReportsMessageQuery,
	useGetReportsResponseQuery,
	useGetReportsAgentQuery,
	useGetReportsCampaignQuery,
	useGetReportsWorkflowQuery,
	useGetReportsAiQuery,
	// [2026-07-05 Johnny Chu] PHASE-0.46 M5 — team performance
	useGetTeamPerformanceQuery,
} from '../../redux/api/crmApi.js';

const METRICS = [
	{ key: 'conversations_opened',  label: 'Hội thoại mở' },
	{ key: 'conversations_closed',  label: 'Hội thoại đóng' },
	{ key: 'first_response_time',   label: 'FRT (giây)', avg: true },
	{ key: 'resolution_time',       label: 'TTR (giây)', avg: true },
	{ key: 'csat_score',            label: 'CSAT', avg: true },
	{ key: 'auto_replies',          label: 'AI tự động' },
	{ key: 'manual_replies',        label: 'Người trả lời' },
	{ key: 'sla_breaches',          label: 'SLA breach' },
];

function isoDate( d ) { return d.toISOString().slice( 0, 10 ); }

function KpiCard( { metric, range } ) {
	const { data, isFetching, error } = useGetReportsAggregateQuery( {
		metric: metric.key, group_by: 'none', from: range.from, to: range.to,
	} );
	const value = data && data.totals ? ( metric.avg ? data.totals.avg : data.totals.sum ) : null;
	return (
		<div className="bzc-kpi">
			<div className="bzc-kpi-label">{ metric.label }</div>
			<div className="bzc-kpi-value">
				{ isFetching ? '…' : error ? '—' : ( value === null || value === undefined ? '0' : Math.round( value * 100 ) / 100 ) }
			</div>
		</div>
	);
}

function AutoVsHuman( { range } ) {
	const { data, isFetching } = useGetReportsAutoVsHumanQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const auto  = data?.auto || 0;
	const human = data?.human || 0;
	const total = auto + human;
	const pAuto = total ? Math.round( ( auto / total ) * 100 ) : 0;
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.2 — PieChart thay CSS bar (Deplao parity)
	const pieData = [
		{ name: 'AI', value: auto, fill: '#3b82f6' },
		{ name: 'Human', value: human, fill: '#f59e0b' },
	];
	const PieTooltip = ( { active, payload } ) => {
		if ( ! active || ! payload || ! payload.length ) { return null; }
		const d = payload[ 0 ].payload;
		return (
			<div style={ { background: '#1e293b', border: '1px solid #334155', borderRadius: 8, padding: '6px 10px', fontSize: 12 } }>
				<span style={ { color: d.fill, fontWeight: 700 } }>{ d.name }: </span>
				<span style={ { color: '#f1f5f9' } }>{ d.value } ({ total ? Math.round( d.value / total * 100 ) : 0 }%)</span>
			</div>
		);
	};
	return (
		<div className="bzc-card">
			<div className="bzc-card-title">AI vs Human (replies)</div>
			<ResponsiveContainer width="100%" height={ 160 }>
				<PieChart>
					<Pie data={ pieData } cx="50%" cy="50%" innerRadius={ 45 } outerRadius={ 70 } paddingAngle={ 2 } dataKey="value">
						{ pieData.map( ( entry ) => <Cell key={ entry.name } fill={ entry.fill } /> ) }
					</Pie>
					<Tooltip content={ <PieTooltip /> } />
					<Legend formatter={ ( v, entry ) => <span style={ { fontSize: 11, color: '#94a3b8' } }>{ v } { entry.payload.value } ({ total ? Math.round( entry.payload.value / total * 100 ) : 0 }%)</span> } />
				</PieChart>
			</ResponsiveContainer>
			<div style={ { fontSize: 11, color: '#64748b', textAlign: 'center' } }>AI {auto} ({pAuto}%) vs Human {human} ({ 100 - pAuto }%)</div>
		</div>
	);
}

/**
 * BreakdownTable — PHASE 0.35 M5.W3.
 * Renders a sortable table for an aggregate(group_by={agent_id|inbox_id|label_id}) call.
 * Resolves keys to human labels via the optional `nameMap` lookup.
 */
function BreakdownTable( { title, metric, groupBy, range, nameMap, emptyHint, valueFormatter } ) {
	const { data, isFetching, error } = useGetReportsAggregateQuery( {
		metric, group_by: groupBy, from: range.from, to: range.to,
	} );
	const [ sortKey, setSortKey ] = useState( 'value' );
	const [ sortDir, setSortDir ] = useState( 'desc' );

	const rows = useMemo( () => {
		const src = ( data && Array.isArray( data.rows ) ) ? data.rows : [];
		const mapped = src.map( ( r ) => ( {
			key:   r.key,
			label: ( nameMap && nameMap[ String( r.key ) ] ) || ( r.key ? '#' + r.key : '— unassigned —' ),
			value: Number( r.value || r.sum || 0 ),
			count: Number( r.count || 0 ),
			avg:   Number( r.avg || 0 ),
		} ) );
		mapped.sort( ( a, b ) => {
			const av = a[ sortKey ]; const bv = b[ sortKey ];
			if ( typeof av === 'string' ) { return sortDir === 'asc' ? av.localeCompare( bv ) : bv.localeCompare( av ); }
			return sortDir === 'asc' ? ( av - bv ) : ( bv - av );
		} );
		return mapped;
	}, [ data, nameMap, sortKey, sortDir ] );

	const toggle = ( k ) => {
		if ( sortKey === k ) { setSortDir( sortDir === 'asc' ? 'desc' : 'asc' ); }
		else { setSortKey( k ); setSortDir( k === 'label' ? 'asc' : 'desc' ); }
	};
	const arrow = ( k ) => sortKey === k ? ( sortDir === 'asc' ? ' ▴' : ' ▾' ) : '';
	const fmt = valueFormatter || ( ( v ) => Math.round( v * 100 ) / 100 );

	return (
		<div className="bzc-card">
			<div className="bzc-card-title">{ title }</div>
			{ isFetching && ! data ? (
				<div className="bzc-muted" style={ { padding: 8, fontSize: 12 } }>Đang tải…</div>
			) : error ? (
				<div className="bzc-muted bzc-danger" style={ { padding: 8, fontSize: 12 } }>Lỗi tải dữ liệu.</div>
			) : rows.length === 0 ? (
				<div className="bzc-muted" style={ { padding: 8, fontSize: 12, fontStyle: 'italic' } }>
					{ emptyHint || 'Không có dữ liệu trong phạm vi.' }
				</div>
			) : (
				<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
					<thead>
						<tr style={ { textAlign: 'left', color: '#64748b', fontSize: 11 } }>
							<th style={ { padding: '4px 6px', cursor: 'pointer', userSelect: 'none' } } onClick={ () => toggle( 'label' ) }>{ groupBy === 'agent_id' ? 'Agent' : groupBy === 'inbox_id' ? 'Inbox' : 'Label' }{ arrow( 'label' ) }</th>
							<th style={ { padding: '4px 6px', textAlign: 'right', cursor: 'pointer', userSelect: 'none' } } onClick={ () => toggle( 'value' ) }>Total{ arrow( 'value' ) }</th>
							<th style={ { padding: '4px 6px', textAlign: 'right', cursor: 'pointer', userSelect: 'none' } } onClick={ () => toggle( 'count' ) }>N{ arrow( 'count' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.slice( 0, 20 ).map( ( r ) => (
							<tr key={ String( r.key ) } style={ { borderTop: '1px solid #f1f5f9' } }>
								<td style={ { padding: '6px', maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } } title={ r.label }>{ r.label }</td>
								<td style={ { padding: '6px', textAlign: 'right', fontWeight: 600 } }>{ fmt( r.value ) }</td>
								<td style={ { padding: '6px', textAlign: 'right', color: '#94a3b8' } }>{ r.count || '—' }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

// ── [2026-06-07 Johnny Chu] PHASE-0.40 G3.2 — Deplao parity 6 report panels ──

function ReportsMessagePanel( { range } ) {
	const { data, isFetching } = useGetReportsMessageQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const total  = data?.total || 0;
	const today  = data?.today || 0;
	const avg    = data?.avg_per_day || 0;
	const series = ( data?.series || [] ).map( ( r ) => ( { name: r.day, count: r.count } ) );
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.2 — AreaChart thay data table (Deplao parity)
	const AreaTooltip = ( { active, payload, label } ) => {
		if ( ! active || ! payload || ! payload.length ) { return null; }
		return (
			<div style={ { background: '#1e293b', border: '1px solid #334155', borderRadius: 8, padding: '6px 10px', fontSize: 12 } }>
				<p style={ { color: '#94a3b8', marginBottom: 2 } }>{ label }</p>
				<p style={ { color: '#3b82f6', fontWeight: 700 } }>{ payload[ 0 ].value } tin nhắn</p>
			</div>
		);
	};
	return (
		<div className="bzc-card" style={ { gridColumn: '1/-1' } }>
			<div className="bzc-card-title">Khối lượng tin nhắn</div>
			<div style={ { display: 'flex', gap: 24, padding: '8px 0', flexWrap: 'wrap' } }>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Tổng kỳ</div><div className="bzc-kpi-value">{ total }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Hôm nay</div><div className="bzc-kpi-value">{ today }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">TB/ngày</div><div className="bzc-kpi-value">{ avg }</div></div>
			</div>
			{ series.length > 0 ? (
				<ResponsiveContainer width="100%" height={ 180 }>
					<AreaChart data={ series } margin={ { top: 4, right: 8, left: -20, bottom: 0 } }>
						<defs>
							<linearGradient id="msgGrad" x1="0" y1="0" x2="0" y2="1">
								<stop offset="5%" stopColor="#3b82f6" stopOpacity={ 0.25 } />
								<stop offset="95%" stopColor="#3b82f6" stopOpacity={ 0 } />
							</linearGradient>
						</defs>
						<CartesianGrid strokeDasharray="3 3" stroke="#334155" strokeOpacity={ 0.5 } />
						<XAxis dataKey="name" tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } />
						<YAxis tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } />
						<Tooltip content={ <AreaTooltip /> } />
						<Area type="monotone" dataKey="count" stroke="#3b82f6" strokeWidth={ 2 } fill="url(#msgGrad)" name="Tin nhắn" />
					</AreaChart>
				</ResponsiveContainer>
			) : (
				<div style={ { fontSize: 12, color: '#64748b', padding: '12px 0', fontStyle: 'italic' } }>Chưa có dữ liệu trong kỳ này.</div>
			) }
		</div>
	);
}

function ReportsResponsePanel( { range } ) {
	const { data, isFetching } = useGetReportsResponseQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const avg = data?.avg_min ?? data?.totals?.avg ?? 0;
	return (
		<div className="bzc-card">
			<div className="bzc-card-title">Thời gian phản hồi</div>
			<div className="bzc-kpi"><div className="bzc-kpi-label">TB phút</div><div className="bzc-kpi-value">{ Math.round( avg * 10 ) / 10 }</div></div>
		</div>
	);
}

function ReportsAgentPanel( { range } ) {
	const { data, isFetching } = useGetReportsAgentQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const agents = ( data?.agents || [] ).map( ( a ) => ( { name: a.name, msgs: a.msg_count || 0 } ) ).sort( ( a, b ) => b.msgs - a.msgs );
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.2 — BarChart thay table (Deplao parity)
	const BarTooltip = ( { active, payload, label } ) => {
		if ( ! active || ! payload || ! payload.length ) { return null; }
		return (
			<div style={ { background: '#1e293b', border: '1px solid #334155', borderRadius: 8, padding: '6px 10px', fontSize: 12 } }>
				<p style={ { color: '#94a3b8', marginBottom: 2 } }>{ label }</p>
				<p style={ { color: '#10b981', fontWeight: 700 } }>{ payload[ 0 ].value } tin nhắn</p>
			</div>
		);
	};
	return (
		<div className="bzc-card" style={ { gridColumn: '1/-1' } }>
			<div className="bzc-card-title">KPI Nhân viên</div>
			{ agents.length === 0 ? <div className="bzc-muted">Chưa có dữ liệu.</div> : (
				<ResponsiveContainer width="100%" height={ 200 }>
					<BarChart data={ agents } margin={ { top: 4, right: 8, left: -20, bottom: 30 } } barSize={ 28 }>
						<CartesianGrid strokeDasharray="3 3" stroke="#334155" strokeOpacity={ 0.5 } />
						<XAxis dataKey="name" tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } angle={ -30 } textAnchor="end" />
						<YAxis tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } />
						<Tooltip content={ <BarTooltip /> } />
						<Bar dataKey="msgs" name="Tin nhắn" fill="#10b981" radius={ [ 4, 4, 0, 0 ] } />
					</BarChart>
				</ResponsiveContainer>
			) }
		</div>
	);
}

function ReportsCampaignPanel( { range } ) {
	const { data, isFetching } = useGetReportsCampaignQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const campaigns = data?.campaigns || [];
	return (
		<div className="bzc-card" style={ { gridColumn: '1/-1' } }>
			<div className="bzc-card-title">Hiệu quả Campaign</div>
			<div style={ { display: 'flex', gap: 24, padding: '8px 0' } }>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Lượt truy cập</div><div className="bzc-kpi-value">{ data?.total_visits || 0 }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Conversion</div><div className="bzc-kpi-value">{ data?.total_conversions || 0 }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Tỉ lệ</div><div className="bzc-kpi-value">{ data?.conversion_rate || 0 }%</div></div>
			</div>
			{ campaigns.length > 0 && (
				<table style={ { width: '100%', fontSize: 12, borderCollapse: 'collapse' } }>
					<thead><tr>
						<th style={ { textAlign: 'left', padding: '2px 6px' } }>Campaign</th>
						<th style={ { textAlign: 'right', padding: '2px 6px' } }>Truy cập</th>
						<th style={ { textAlign: 'right', padding: '2px 6px' } }>Conversion</th>
					</tr></thead>
					<tbody>{ campaigns.map( ( c ) => (
						<tr key={ c.id }><td style={ { padding: '2px 6px' } }>{ c.name }</td><td style={ { textAlign: 'right' } }>{ c.visits }</td><td style={ { textAlign: 'right' } }>{ c.conversions }</td></tr>
					) ) }</tbody>
				</table>
			) }
		</div>
	);
}

function ReportsWorkflowPanel( { range } ) {
	const { data, isFetching } = useGetReportsWorkflowQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	return (
		<div className="bzc-card">
			<div className="bzc-card-title">Automation Workflow</div>
			<div style={ { display: 'flex', gap: 24, padding: '8px 0' } }>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Tổng</div><div className="bzc-kpi-value">{ data?.total || 0 }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">OK</div><div className="bzc-kpi-value" style={ { color: '#10b981' } }>{ data?.success || 0 }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Lỗi</div><div className="bzc-kpi-value" style={ { color: '#f87171' } }>{ data?.failed || 0 }</div></div>
			</div>
		</div>
	);
}

function ReportsAiPanel( { range } ) {
	const { data, isFetching } = useGetReportsAiQuery( { from: range.from, to: range.to } );
	if ( isFetching ) { return <div className="bzc-card">Đang tải…</div>; }
	const byService = ( data?.by_service || [] ).map( ( s ) => ( { name: s.service, calls: s.calls || 0, tokens: s.tokens ? Number( s.tokens ) : 0 } ) );
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.2 — BarChart by service thay table (Deplao parity)
	const AITooltip = ( { active, payload, label } ) => {
		if ( ! active || ! payload || ! payload.length ) { return null; }
		return (
			<div style={ { background: '#1e293b', border: '1px solid #334155', borderRadius: 8, padding: '6px 10px', fontSize: 12 } }>
				<p style={ { color: '#94a3b8', marginBottom: 2 } }>{ label }</p>
				{ payload.map( ( p ) => (
					<p key={ p.name } style={ { color: p.fill || p.stroke, fontWeight: 700 } }>{ p.name }: { Number( p.value ).toLocaleString() }</p>
				) ) }
			</div>
		);
	};
	const PIE_COLORS = [ '#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#06b6d4', '#f97316' ];
	return (
		<div className="bzc-card">
			<div className="bzc-card-title">Tiêu thụ AI</div>
			<div style={ { display: 'flex', gap: 24, padding: '8px 0' } }>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Gọi</div><div className="bzc-kpi-value">{ data?.total_calls || 0 }</div></div>
				<div className="bzc-kpi"><div className="bzc-kpi-label">Tokens</div><div className="bzc-kpi-value">{ ( data?.total_tokens || 0 ).toLocaleString() }</div></div>
			</div>
			{ byService.length > 0 ? (
				<ResponsiveContainer width="100%" height={ 160 }>
					<BarChart data={ byService } margin={ { top: 4, right: 8, left: -20, bottom: 20 } } barSize={ 24 }>
						<CartesianGrid strokeDasharray="3 3" stroke="#334155" strokeOpacity={ 0.4 } />
						<XAxis dataKey="name" tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } angle={ -20 } textAnchor="end" />
						<YAxis tick={ { fontSize: 10, fill: '#64748b' } } tickLine={ false } axisLine={ false } />
						<Tooltip content={ <AITooltip /> } />
						<Bar dataKey="calls" name="Gọi" radius={ [ 4, 4, 0, 0 ] }>
							{ byService.map( ( _, i ) => <Cell key={ i } fill={ PIE_COLORS[ i % PIE_COLORS.length ] } /> ) }
						</Bar>
					</BarChart>
				</ResponsiveContainer>
			) : <div style={ { fontSize: 12, color: '#64748b', fontStyle: 'italic' } }>Chưa có dữ liệu.</div> }
		</div>
	);
}

const DEPLAO_TABS = [
	{ key: 'message',  label: 'Tin nhắn',  Panel: ReportsMessagePanel },
	{ key: 'response', label: 'Phản hồi',  Panel: ReportsResponsePanel },
	{ key: 'agent',    label: 'Nhân viên', Panel: ReportsAgentPanel },
	{ key: 'campaign', label: 'Campaign',  Panel: ReportsCampaignPanel },
	{ key: 'workflow', label: 'Workflow',  Panel: ReportsWorkflowPanel },
	{ key: 'ai',       label: 'AI usage',  Panel: ReportsAiPanel },
];

function DeplaoReportTabs( { range } ) {
	const [ active, setActive ] = useState( 'message' );
	const tab = DEPLAO_TABS.find( ( t ) => t.key === active ) || DEPLAO_TABS[0];
	const { Panel } = tab;
	return (
		<div style={ { marginTop: 20 } }>
			<div style={ { display: 'flex', gap: 4, borderBottom: '1px solid #e2e8f0', marginBottom: 12 } }>
				{ DEPLAO_TABS.map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						onClick={ () => setActive( t.key ) }
						style={ {
							padding: '4px 12px', fontSize: 12, cursor: 'pointer',
							background: 'none', border: 'none',
							borderBottom: active === t.key ? '2px solid #3b82f6' : '2px solid transparent',
							color: active === t.key ? '#3b82f6' : '#64748b', fontWeight: active === t.key ? 600 : 400,
						} }
					>{ t.label }</button>
				) ) }
			</div>
			<div className="bzc-card-grid">
				<Panel range={ range } />
			</div>
		</div>
	);
}

// [2026-07-05 Johnny Chu] PHASE-0.46 M5 — Team Performance dashboard panel
// [2026-06-30 Johnny Chu] PHASE-0.46 M5 FIX — extracted IIFE to named component; added useEffect date sync

function TeamPerformanceBody( { rows, fmt } ) {
	const totalSubs = rows.reduce( ( s, r ) => s + ( r.submissions_assigned || 0 ), 0 );
	const totalWon  = rows.reduce( ( s, r ) => s + ( r.opps_closed_won || 0 ), 0 );
	const totalVal  = rows.reduce( ( s, r ) => s + parseFloat( r.pipeline_value_won || 0 ), 0 );
	const totalQual = rows.reduce( ( s, r ) => s + ( r.submissions_qualified || 0 ), 0 );
	const globalCr  = totalQual > 0 ? Math.round( totalWon / totalQual * 100 ) : 0;

	return (
		<>
			{ /* KPI summary */ }
			<div style={ { display: 'flex', gap: 14, flexWrap: 'wrap', marginBottom: 14 } }>
				{ [
					{ label: 'Tổng submissions', value: totalSubs },
					{ label: 'Closed Won',        value: totalWon },
					{ label: 'CR% tổng',          value: globalCr + '%' },
					{ label: 'Revenue đã chốt',   value: fmt( totalVal ) },
				].map( ( k ) => (
					<div key={ k.label } style={ { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '10px 18px', minWidth: 130 } }>
						<div style={ { fontSize: 11, color: '#64748b' } }>{ k.label }</div>
						<div style={ { fontSize: 20, fontWeight: 700, color: '#1e293b', marginTop: 2 } }>{ k.value }</div>
					</div>
				) ) }
			</div>

			{ /* Leaderboard table */ }
			<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
				<thead>
					<tr style={ { textAlign: 'left', color: '#64748b', fontSize: 11, borderBottom: '2px solid #e2e8f0' } }>
						<th style={ { padding: '6px 10px' } }>Nhân viên</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Giao</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Đã LH</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Tiềm năng</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Opp</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Won</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>CR%</th>
						<th style={ { padding: '6px 10px', textAlign: 'right' } }>Revenue</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r, i ) => {
						const cr = r.submissions_qualified > 0
							? Math.round( ( r.opps_closed_won || 0 ) / r.submissions_qualified * 100 )
							: 0;
						const contactPct = r.submissions_assigned > 0
							? Math.round( ( r.submissions_contacted || 0 ) / r.submissions_assigned * 100 )
							: 0;
						return (
							<tr key={ r.wp_user_id || i } style={ { borderBottom: '1px solid #f1f5f9' } }>
								<td style={ { padding: '8px 10px', fontWeight: 600, color: '#1e293b' } }>
									{ r.display_name || ( 'User #' + r.wp_user_id ) }
								</td>
								<td style={ { padding: '8px 10px', textAlign: 'right' } }>{ r.submissions_assigned || 0 }</td>
								<td style={ { padding: '8px 10px', textAlign: 'right', color: '#2563eb' } }>
									{ r.submissions_contacted || 0 }
									{ r.submissions_assigned > 0 && (
										<span style={ { color: '#94a3b8', fontSize: 10 } }> ({ contactPct }%)</span>
									) }
								</td>
								<td style={ { padding: '8px 10px', textAlign: 'right', color: '#7c3aed' } }>
									{ r.submissions_qualified || 0 }
								</td>
								<td style={ { padding: '8px 10px', textAlign: 'right' } }>{ r.opps_created || 0 }</td>
								<td style={ { padding: '8px 10px', textAlign: 'right', color: '#16a34a', fontWeight: 600 } }>{ r.opps_closed_won || 0 }</td>
								<td style={ { padding: '8px 10px', textAlign: 'right' } }>
									<span style={ { color: cr >= 20 ? '#16a34a' : cr >= 10 ? '#d97706' : '#ef4444', fontWeight: 600 } }>
										{ cr }%
									</span>
								</td>
								<td style={ { padding: '8px 10px', textAlign: 'right', fontWeight: 600 } }>
									{ fmt( r.pipeline_value_won ) }
								</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</>
	);
}

function TeamPerformancePanel( { defaultFrom, defaultTo } ) {
	const [ from, setFrom ] = useState( defaultFrom );
	const [ to,   setTo   ] = useState( defaultTo   );
	const IS_MANAGER = ( typeof window !== 'undefined' && !! window.BIZCITY_CRM_BOOT?.isManager );

	// [2026-06-30 Johnny Chu] PHASE-0.46 M5 FIX — sync local date state when parent range changes
	useEffect( () => { setFrom( defaultFrom ); }, [ defaultFrom ] );
	useEffect( () => { setTo( defaultTo ); },     [ defaultTo   ] );

	const { data, isFetching, refetch } = useGetTeamPerformanceQuery(
		{ from, to },
		{ skip: ! IS_MANAGER }
	);
	const rows = Array.isArray( data?.rows ) ? data.rows : [];

	const fmt = ( v ) => new Intl.NumberFormat( 'vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 } ).format( parseFloat( v ) || 0 );

	if ( ! IS_MANAGER ) {
		return (
			<div style={ { padding: 16, color: '#94a3b8', fontSize: 13 } }>
				Bạn cần quyền Manager để xem báo cáo này.
			</div>
		);
	}

	return (
		<div>
			{ /* Date filter bar */ }
			<div style={ { display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' } }>
				<label style={ { fontSize: 12 } }>
					Từ: <input type="date" value={ from } onChange={ ( e ) => setFrom( e.target.value ) } style={ { fontSize: 12, padding: '3px 6px', border: '1px solid #e2e8f0', borderRadius: 6 } } />
				</label>
				<label style={ { fontSize: 12 } }>
					Đến: <input type="date" value={ to } onChange={ ( e ) => setTo( e.target.value ) } style={ { fontSize: 12, padding: '3px 6px', border: '1px solid #e2e8f0', borderRadius: 6 } } />
				</label>
				<button
					type="button"
					onClick={ () => refetch() }
					style={ { fontSize: 12, padding: '3px 10px', border: '1px solid #e2e8f0', borderRadius: 6, cursor: 'pointer' } }
				>
					Áp dụng
				</button>
				{ isFetching && <span style={ { fontSize: 11, color: '#94a3b8' } }>Đang tải…</span> }
			</div>

			{ rows.length === 0 && ! isFetching && (
				<div style={ { fontSize: 12, color: '#94a3b8', fontStyle: 'italic', padding: '12px 0' } }>
					Không có dữ liệu trong khoảng thời gian này.
				</div>
			) }

			{ rows.length > 0 && <TeamPerformanceBody rows={ rows } fmt={ fmt } /> }
		</div>
	);
}

export default function ReportsTab() {
	const [ rollup, { isLoading: rolling } ] = useRunRollupNowMutation();
	const [ days, setDays ] = useState( 7 );
	const range = useMemo( () => {
		const to = new Date();
		const from = new Date( Date.now() - ( days - 1 ) * 86400 * 1000 );
		return { from: isoDate( from ), to: isoDate( to ) };
	}, [ days ] );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<h2>Reports</h2>
				<div className="bzc-tabpane-actions">
					<select value={ days } onChange={ ( e ) => setDays( Number( e.target.value ) ) }>
						<option value={ 1 }>Hôm nay</option>
						<option value={ 7 }>7 ngày</option>
						<option value={ 30 }>30 ngày</option>
						<option value={ 90 }>90 ngày</option>
					</select>
					<button className="bzc-btn" onClick={ () => rollup() } disabled={ rolling }>
						{ rolling ? 'Đang chạy…' : 'Rollup ngay' }
					</button>
				</div>
			</header>

			<section className="bzc-kpi-grid">
				{ METRICS.map( ( m ) => <KpiCard key={ m.key } metric={ m } range={ range } /> ) }
			</section>

			<section className="bzc-card-grid">
				<AutoVsHuman range={ range } />
				<div className="bzc-card">
					<div className="bzc-card-title">Phạm vi</div>
					<div className="bzc-card-body">
						<div><strong>Từ:</strong> { range.from }</div>
						<div><strong>Đến:</strong> { range.to }</div>
						<div className="bzc-muted">Daily rollup chạy 03:00 mỗi ngày qua cron.</div>
					</div>
				</div>
			</section>

			<Breakdowns range={ range } />
			{ /* [2026-06-07 Johnny Chu] PHASE-0.40 G3.2 — Deplao parity 6-tab report section */ }
			<DeplaoReportTabs range={ range } />		{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M5 — Team Performance section */ }
		<section style={ { marginTop: 24 } }>
			<h3 style={ { fontSize: 15, fontWeight: 700, color: '#1e293b', marginBottom: 12, borderBottom: '2px solid #e2e8f0', paddingBottom: 8 } }>
				Hiệu quả Nhân viên Telesale
			</h3>
			<TeamPerformancePanel defaultFrom={ range.from } defaultTo={ range.to } />
		</section>		</div>
	);
}

function Breakdowns( { range } ) {
	const { data: inboxes = [] } = useGetInboxesQuery();
	const { data: labels  = [] } = useGetLabelsQuery();
	const inboxMap = useMemo( () => Object.fromEntries( inboxes.map( ( i ) => [ String( i.id ), i.name ] ) ), [ inboxes ] );
	const labelMap = useMemo( () => Object.fromEntries( labels.map( ( l ) => [ String( l.id ), l.title || l.name ] ) ), [ labels ] );

	return (
		<section className="bzc-card-grid" style={ { marginTop: 16 } }>
			<BreakdownTable
				title="Conversations đóng theo Agent"
				metric="conversations_closed" groupBy="agent_id" range={ range }
				emptyHint="Chưa có agent nào resolve trong kỳ này."
			/>
			<BreakdownTable
				title="FRT trung bình theo Inbox"
				metric="first_response_time" groupBy="inbox_id" range={ range }
				nameMap={ inboxMap }
				valueFormatter={ ( v ) => v < 60 ? Math.round( v ) + 's' : ( Math.round( v / 60 * 10 ) / 10 ) + 'm' }
			/>
			<BreakdownTable
				title="Hội thoại theo Label"
				metric="conversations_opened" groupBy="label_id" range={ range }
				nameMap={ labelMap }
			/>
		</section>
	);
}
