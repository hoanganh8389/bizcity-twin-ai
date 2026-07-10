/**
 * FunnelPanel — Marketing funnel evaluation dashboard.
 *
 * Single-source data: GET /dashboard/funnel-overview?days=N
 * Lazy-loaded by Workspace.jsx. Pure React + inline SVG (no extra deps).
 */
import React, { useState } from 'react';
import {
	TrendingUp, Users, AlertTriangle, Eye, MessageSquare, Timer, RefreshCw,
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody, Badge } from '../../components/ui/card.jsx';
import { useGetFunnelOverviewQuery } from '../../redux/api/crmApi.js';
import { formatMoney } from '../../lib/utils.js';

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

function formatNumber( n ) {
	const v = Number( n || 0 );
	if ( v >= 1_000_000 ) return ( v / 1_000_000 ).toFixed( 1 ).replace( /\.0$/, '' ) + 'M';
	if ( v >= 1_000 )     return ( v / 1_000 ).toFixed( 1 ).replace( /\.0$/, '' ) + 'K';
	return String( v );
}

function formatDuration( seconds ) {
	const s = Number( seconds || 0 );
	if ( ! s ) return '—';
	if ( s < 60 ) return `${ s }s`;
	if ( s < 3600 ) return `${ Math.round( s / 60 ) }m`;
	const h = Math.floor( s / 3600 );
	const m = Math.round( ( s % 3600 ) / 60 );
	return m ? `${ h }h ${ m }m` : `${ h }h`;
}

function formatDayShort( iso ) {
	if ( ! iso ) return '';
	const parts = String( iso ).split( '-' );
	return parts.length === 3 ? `${ parts[ 2 ] }/${ parts[ 1 ] }` : iso;
}

/* ------------------------------------------------------------------ */
/* KPI Card                                                            */
/* ------------------------------------------------------------------ */

function KpiCard( { icon: Icon, label, value, sub, tone = 'default' } ) {
	const toneClass = {
		default: '',
		warn:    ' bzc-funnel-kpi--warn',
		danger:  ' bzc-funnel-kpi--danger',
		good:    ' bzc-funnel-kpi--good',
	}[ tone ] || '';
	return (
		<div className={ 'bzc-funnel-kpi' + toneClass }>
			<div className="bzc-funnel-kpi__head">
				<span className="bzc-funnel-kpi__icon"><Icon size={ 16 } /></span>
				<span className="bzc-funnel-kpi__label">{ label }</span>
			</div>
			<div className="bzc-funnel-kpi__value">{ value }</div>
			{ sub ? <div className="bzc-funnel-kpi__sub">{ sub }</div> : null }
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Time-series area chart (reach + inbox)                              */
/* ------------------------------------------------------------------ */

function TimeSeriesChart( { data } ) {
	const W = 720;
	const H = 220;
	const PAD = { l: 36, r: 12, t: 12, b: 28 };
	if ( ! data || data.length === 0 ) {
		return <div className="bzc-empty bzc-muted">Chưa có dữ liệu.</div>;
	}
	const reachMax = Math.max( ...data.map( ( d ) => d.reach ), 1 );
	const inboxMax = Math.max( ...data.map( ( d ) => d.inbox ), 1 );
	const yMax = Math.max( reachMax, inboxMax );

	const innerW = W - PAD.l - PAD.r;
	const innerH = H - PAD.t - PAD.b;
	const stepX = data.length > 1 ? innerW / ( data.length - 1 ) : innerW;

	const xy = ( i, v ) => [ PAD.l + i * stepX, PAD.t + innerH - ( v / yMax ) * innerH ];

	const path = ( key ) => data
		.map( ( d, i ) => {
			const [ x, y ] = xy( i, d[ key ] );
			return ( i === 0 ? 'M' : 'L' ) + x.toFixed( 1 ) + ' ' + y.toFixed( 1 );
		} )
		.join( ' ' );

	const area = ( key ) => {
		const top = path( key );
		const [ x0 ] = xy( 0, 0 );
		const [ xN ] = xy( data.length - 1, 0 );
		const baseline = `L${ xN } ${ PAD.t + innerH } L${ x0 } ${ PAD.t + innerH } Z`;
		return top + baseline;
	};

	const yTicks = 4;
	const ticks = Array.from( { length: yTicks + 1 }, ( _, i ) => Math.round( ( yMax * i ) / yTicks ) );

	return (
		<svg viewBox={ `0 0 ${ W } ${ H }` } className="bzc-funnel-chart" role="img" aria-label="Reach và Inbox theo ngày">
			<defs>
				<linearGradient id="bzcReachGrad" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%"  stopColor="#6366f1" stopOpacity="0.35" />
					<stop offset="100%" stopColor="#6366f1" stopOpacity="0" />
				</linearGradient>
				<linearGradient id="bzcInboxGrad" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0%"  stopColor="#22c55e" stopOpacity="0.35" />
					<stop offset="100%" stopColor="#22c55e" stopOpacity="0" />
				</linearGradient>
			</defs>
			{ /* Y grid */ }
			{ ticks.map( ( t, i ) => {
				const y = PAD.t + innerH - ( t / yMax ) * innerH;
				return (
					<g key={ i }>
						<line x1={ PAD.l } y1={ y } x2={ W - PAD.r } y2={ y } stroke="#e5e7eb" strokeDasharray="3 3" />
						<text x={ PAD.l - 6 } y={ y + 3 } textAnchor="end" fontSize="10" fill="#94a3b8">{ formatNumber( t ) }</text>
					</g>
				);
			} ) }
			{ /* X labels (sparse) */ }
			{ data.map( ( d, i ) => {
				if ( data.length > 10 && i % Math.ceil( data.length / 8 ) !== 0 && i !== data.length - 1 ) return null;
				const x = PAD.l + i * stepX;
				return <text key={ d.day } x={ x } y={ H - 8 } textAnchor="middle" fontSize="10" fill="#64748b">{ formatDayShort( d.day ) }</text>;
			} ) }
			{ /* Areas + lines */ }
			<path d={ area( 'reach' ) } fill="url(#bzcReachGrad)" />
			<path d={ area( 'inbox' ) } fill="url(#bzcInboxGrad)" />
			<path d={ path( 'reach' ) } fill="none" stroke="#6366f1" strokeWidth="2" />
			<path d={ path( 'inbox' ) } fill="none" stroke="#22c55e" strokeWidth="2" />
			{ /* Points */ }
			{ data.map( ( d, i ) => {
				const [ rx, ry ] = xy( i, d.reach );
				const [ ix, iy ] = xy( i, d.inbox );
				return (
					<g key={ d.day }>
						<circle cx={ rx } cy={ ry } r="2.5" fill="#6366f1">
							<title>{ `${ d.day } · Reach ${ d.reach }` }</title>
						</circle>
						<circle cx={ ix } cy={ iy } r="2.5" fill="#22c55e">
							<title>{ `${ d.day } · Inbox ${ d.inbox }` }</title>
						</circle>
					</g>
				);
			} ) }
		</svg>
	);
}

/* ------------------------------------------------------------------ */
/* Donut chart (by channel)                                            */
/* ------------------------------------------------------------------ */

function DonutChart( { data } ) {
	const total = data.reduce( ( s, d ) => s + d.conversations, 0 );
	if ( total === 0 ) {
		return <div className="bzc-empty bzc-muted">Chưa có hội thoại trong khoảng này.</div>;
	}
	const W = 180, R = 70, r = 44, cx = W / 2, cy = W / 2;
	let acc = 0;
	const arcs = data.map( ( d ) => {
		const frac = d.conversations / total;
		const a0 = acc * Math.PI * 2 - Math.PI / 2;
		acc += frac;
		const a1 = acc * Math.PI * 2 - Math.PI / 2;
		const large = frac > 0.5 ? 1 : 0;
		const x0 = cx + R * Math.cos( a0 ), y0 = cy + R * Math.sin( a0 );
		const x1 = cx + R * Math.cos( a1 ), y1 = cy + R * Math.sin( a1 );
		const x2 = cx + r * Math.cos( a1 ), y2 = cy + r * Math.sin( a1 );
		const x3 = cx + r * Math.cos( a0 ), y3 = cy + r * Math.sin( a0 );
		const path = `M${ x0 } ${ y0 } A${ R } ${ R } 0 ${ large } 1 ${ x1 } ${ y1 } L${ x2 } ${ y2 } A${ r } ${ r } 0 ${ large } 0 ${ x3 } ${ y3 } Z`;
		return { ...d, path, frac };
	} );
	return (
		<div className="bzc-funnel-donut">
			<svg viewBox={ `0 0 ${ W } ${ W }` } width={ W } height={ W }>
				{ arcs.map( ( a ) => (
					<path key={ a.channel } d={ a.path } fill={ a.color }>
						<title>{ `${ a.label } · ${ a.conversations } (${ Math.round( a.frac * 100 ) }%)` }</title>
					</path>
				) ) }
				<text x={ cx } y={ cy - 4 } textAnchor="middle" fontSize="22" fontWeight="700" fill="#0f172a">{ formatNumber( total ) }</text>
				<text x={ cx } y={ cy + 14 } textAnchor="middle" fontSize="10" fill="#64748b">hội thoại</text>
			</svg>
			<ul className="bzc-funnel-legend">
				{ arcs.map( ( a ) => (
					<li key={ a.channel }>
						<span className="bzc-funnel-legend__dot" style={ { background: a.color } } />
						<span className="bzc-funnel-legend__label">{ a.label }</span>
						<span className="bzc-funnel-legend__val">{ a.conversations } · { Math.round( a.frac * 100 ) }%</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Source-quality stacked bars                                         */
/* ------------------------------------------------------------------ */

function SourceQualityChart( { data } ) {
	if ( ! data || data.length === 0 ) {
		return <div className="bzc-empty bzc-muted">Chưa có dữ liệu nguồn.</div>;
	}
	const max = Math.max( ...data.map( ( d ) => d.new + d.returning ), 1 );
	return (
		<div className="bzc-funnel-bars">
			{ data.map( ( d ) => {
				const total = d.new + d.returning;
				const w = ( total / max ) * 100;
				const newPct = total > 0 ? ( d.new / total ) * 100 : 0;
				return (
					<div key={ d.channel } className="bzc-funnel-bar-row">
						<div className="bzc-funnel-bar-label">{ d.label }</div>
						<div className="bzc-funnel-bar-track">
							<div className="bzc-funnel-bar-fill" style={ { width: w + '%' } }>
								<div className="bzc-funnel-bar-new"       style={ { width: newPct + '%' } } title={ `Mới: ${ d.new }` } />
								<div className="bzc-funnel-bar-returning" style={ { width: ( 100 - newPct ) + '%' } } title={ `Quay lại: ${ d.returning }` } />
							</div>
						</div>
						<div className="bzc-funnel-bar-val">{ d.new } / { d.returning }</div>
					</div>
				);
			} ) }
			<div className="bzc-funnel-bars__legend">
				<span><i className="bzc-funnel-legend__dot" style={ { background: '#3b82f6' } } /> Khách mới</span>
				<span><i className="bzc-funnel-legend__dot" style={ { background: '#cbd5e1' } } /> Quay lại</span>
			</div>
		</div>
	);
}

/* ------------------------------------------------------------------ */
/* Top campaigns table                                                 */
/* ------------------------------------------------------------------ */

function TopCampaignsTable( { rows } ) {
	if ( ! rows || rows.length === 0 ) {
		return <div className="bzc-empty bzc-muted">Chưa có chiến dịch nào có lượt truy cập trong khoảng này.</div>;
	}
	return (
		<table className="bzc-funnel-table">
			<thead>
				<tr>
					<th style={ { width: 32 } }>#</th>
					<th>Chiến dịch</th>
					<th style={ { textAlign: 'right' } }>Lượt xem</th>
					<th style={ { textAlign: 'right' } }>Inbox</th>
					<th style={ { textAlign: 'right' } }>Tỷ lệ chuyển đổi</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( r ) => {
					const tone = r.conversion_pct >= 5 ? 'ok' : r.conversion_pct >= 1 ? 'warn' : 'muted';
					return (
						<tr key={ r.campaign_id }>
							<td><span className="bzc-funnel-rank">{ r.rank }</span></td>
							<td>
								<div className="bzc-funnel-camp-title">{ r.title }</div>
								<div className="bzc-muted" style={ { fontSize: 11 } }>{ r.code }</div>
							</td>
							<td style={ { textAlign: 'right' } }>{ formatNumber( r.reads ) }</td>
							<td style={ { textAlign: 'right' } }>{ formatNumber( r.inbox ) }</td>
							<td style={ { textAlign: 'right' } }>
								<Badge variant={ tone }>{ r.conversion_pct }%</Badge>
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

/* ------------------------------------------------------------------ */
/* Main panel                                                          */
/* ------------------------------------------------------------------ */

const RANGE_OPTIONS = [
	{ value: 7,  label: '7 ngày' },
	{ value: 14, label: '14 ngày' },
	{ value: 30, label: '30 ngày' },
	{ value: 90, label: '90 ngày' },
];

export default function FunnelPanel() {
	const [ days, setDays ] = useState( 7 );
	const { data, isFetching, isError, refetch } = useGetFunnelOverviewQuery( days );

	const kpi          = data?.kpi || {};
	const timeseries   = data?.timeseries || [];
	const byChannel    = data?.by_channel || [];
	const sourceQual   = data?.source_quality || [];
	const topCampaigns = data?.top_articles || [];
	const brandName    = data?.brand_name || '';

	const reachDelta = kpi.reach_delta_pct;
	const reachSub = reachDelta === null || reachDelta === undefined
		? 'so với kỳ trước: chưa có dữ liệu'
		: `${ reachDelta >= 0 ? '+' : '' }${ reachDelta }% so với kỳ trước`;

	return (
		<div className="bzc-tabpane bzc-funnel">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Đánh giá phễu marketing</h2>
					<p className="bzc-tabpane-subtitle">
						{ brandName ? <>Brand: <strong>{ brandName }</strong> · </> : null }
						Tổng quan reach → inbox → chuyển đổi theo khoảng thời gian.
					</p>
				</div>
				<div className="bzc-funnel-actions">
					<div className="bzc-funnel-range" role="tablist" aria-label="Khoảng thời gian">
						{ RANGE_OPTIONS.map( ( opt ) => (
							<button
								key={ opt.value }
								type="button"
								role="tab"
								aria-selected={ opt.value === days }
								className={ 'bzc-funnel-range__btn' + ( opt.value === days ? ' is-active' : '' ) }
								onClick={ () => setDays( opt.value ) }
							>
								{ opt.label }
							</button>
						) ) }
					</div>
					<button type="button" className="bzc-btn bzc-btn-ghost" onClick={ () => refetch() } title="Làm mới">
						<RefreshCw size={ 14 } /> Làm mới
					</button>
				</div>
			</header>

			{ isError && (
				<Card><CardBody><span className="bzc-muted">Không tải được dữ liệu phễu. Kiểm tra REST <code>dashboard/funnel-overview</code>.</span></CardBody></Card>
			) }

			{ /* KPI strip */ }
			<div className="bzc-funnel-kpis">
				<KpiCard
					icon={ TrendingUp }
					label="Doanh thu pipeline"
					value={ isFetching ? '…' : formatMoney( kpi.revenue_pipeline || 0 ) }
					sub={ `${ kpi.pipeline_count || 0 } cơ hội đang xử lý` }
					tone="good"
				/>
				<KpiCard
					icon={ Users }
					label="Khách trong phễu"
					value={ isFetching ? '…' : formatNumber( kpi.pipeline_count ) }
					sub="đang ở trạng thái mở / chờ"
				/>
				<KpiCard
					icon={ AlertTriangle }
					label="Cần chăm sóc gấp"
					value={ isFetching ? '…' : formatNumber( kpi.urgent_followups ) }
					sub="chờ phản hồi > 24 giờ"
					tone={ kpi.urgent_followups > 0 ? 'danger' : 'default' }
				/>
				<KpiCard
					icon={ Eye }
					label="Reach"
					value={ isFetching ? '…' : formatNumber( kpi.reach_total ) }
					sub={ reachSub }
				/>
				<KpiCard
					icon={ MessageSquare }
					label="Tin nhắn vào"
					value={ isFetching ? '…' : formatNumber( kpi.inbox_total ) }
					sub="tin incoming trong khoảng"
				/>
				<KpiCard
					icon={ Timer }
					label="Thời gian phản hồi TB"
					value={ isFetching ? '…' : formatDuration( kpi.avg_response_seconds ) }
					sub="lần đầu trả lời"
					tone={ kpi.avg_response_seconds > 1800 ? 'warn' : 'good' }
				/>
			</div>

			{ /* Charts row */ }
			<div className="bzc-funnel-grid">
				<Card className="bzc-funnel-grid__wide">
					<CardHeader>
						<CardTitle>Reach &amp; Inbox theo ngày</CardTitle>
						<div className="bzc-funnel-chart-legend">
							<span><i className="bzc-funnel-legend__dot" style={ { background: '#6366f1' } } /> Reach</span>
							<span><i className="bzc-funnel-legend__dot" style={ { background: '#22c55e' } } /> Inbox</span>
						</div>
					</CardHeader>
					<CardBody>
						{ isFetching && timeseries.length === 0
							? <div className="bzc-empty bzc-muted">Đang tải…</div>
							: <TimeSeriesChart data={ timeseries } /> }
					</CardBody>
				</Card>

				<Card>
					<CardHeader><CardTitle>Hội thoại theo kênh</CardTitle></CardHeader>
					<CardBody>
						{ isFetching && byChannel.length === 0
							? <div className="bzc-empty bzc-muted">Đang tải…</div>
							: <DonutChart data={ byChannel } /> }
					</CardBody>
				</Card>

				<Card className="bzc-funnel-grid__wide">
					<CardHeader><CardTitle>Chất lượng nguồn (mới vs quay lại)</CardTitle></CardHeader>
					<CardBody>
						{ isFetching && sourceQual.length === 0
							? <div className="bzc-empty bzc-muted">Đang tải…</div>
							: <SourceQualityChart data={ sourceQual } /> }
					</CardBody>
				</Card>

				<Card>
					<CardHeader><CardTitle>Top chiến dịch</CardTitle></CardHeader>
					<CardBody style={ { padding: 0 } }>
						{ isFetching && topCampaigns.length === 0
							? <div className="bzc-empty bzc-muted" style={ { padding: 16 } }>Đang tải…</div>
							: <TopCampaignsTable rows={ topCampaigns } /> }
					</CardBody>
				</Card>
			</div>
		</div>
	);
}
