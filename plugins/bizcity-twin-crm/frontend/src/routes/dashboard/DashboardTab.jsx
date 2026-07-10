// [2026-06-30 Johnny Chu] PHASE-0.46 — Dashboard rewrite: CRM submissions funnel + agent perf + Woo
import React, { useState, useMemo } from 'react';
import {
ArrowUpRight, ArrowDownRight, Users, CheckSquare,
ShoppingCart, TrendingUp, UserCheck, Award, Target, Clock,
} from 'lucide-react';
import {
AreaChart, Area, XAxis, YAxis, CartesianGrid,
Tooltip, ResponsiveContainer,
} from 'recharts';
import { Card, CardHeader, CardTitle, CardBody, Badge } from '../../components/ui/card.jsx';
import ActivityFeed from '../../components/activity/ActivityFeed.jsx';
import { formatMoney } from '../../lib/utils.js';
import {
useGetReportsWooSummaryQuery,
useGetReportsWooTopCustomersQuery,
useGetReportsWooTrendQuery,
useGetCrmTasksQuery,
useGetCrmOverviewQuery,
} from '../../redux/api/crmApi.js';

// ── Boot ──────────────────────────────────────────────────────────────────
const BOOT       = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const IS_MANAGER = !! ( BOOT.isManager );

// ── Status meta ──────────────────────────────────────────────────────────
const STATUS_META = {
new:           { label: 'Mới',            color: '#2563eb', bg: '#eff6ff' },
contacted:     { label: 'Đã liên lạc',    color: '#0891b2', bg: '#ecfeff' },
qualified:     { label: 'Đủ điều kiện',   color: '#ca8a04', bg: '#fefce8' },
proposal_sent: { label: 'Đã báo giá',     color: '#c2410c', bg: '#fff7ed' },
negotiating:   { label: 'Đàm phán',       color: '#9333ea', bg: '#fdf4ff' },
closed_won:    { label: 'Thành công',      color: '#16a34a', bg: '#f0fdf4' },
closed_lost:   { label: 'Thất bại',       color: '#dc2626', bg: '#fef2f2' },
invalid:       { label: 'Không hợp lệ',   color: '#94a3b8', bg: '#f8fafc' },
};
function sm( s ) { return STATUS_META[ s ] || { label: s, color: '#64748b', bg: '#f1f5f9' }; }

// ── KPI Card ──────────────────────────────────────────────────────────────
function KpiCard( { icon: Icon, label, value, sub, delta, color, bg } ) {
const up   = delta > 0;
const hasD = delta !== undefined && delta !== null && delta !== 0;
return (
<div style={ { background: bg || '#f8fafc', borderRadius: 14, padding: '16px 20px', display: 'flex', flexDirection: 'column', gap: 6, flex: 1, minWidth: 140 } }>
<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
<span style={ { fontSize: 11, fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.05em' } }>{ label }</span>
{ Icon && <Icon size={ 15 } style={ { color: color || '#64748b' } } /> }
</div>
<div style={ { fontSize: 26, fontWeight: 800, color: color || '#334155', lineHeight: 1.1 } }>{ value }</div>
{ ( sub || hasD ) && (
<div style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 11 } }>
{ hasD && (
<span style={ { display: 'flex', alignItems: 'center', gap: 2, color: up ? '#16a34a' : '#dc2626', fontWeight: 600 } }>
{ up ? <ArrowUpRight size={ 11 } /> : <ArrowDownRight size={ 11 } /> }
{ Math.abs( delta ) }%
</span>
) }
{ sub && <span style={ { color: '#94a3b8' } }>{ sub }</span> }
</div>
) }
</div>
);
}

// ── Funnel bar ──────────────────────────────────────────────────────────
function FunnelBar( { status, cnt, maxCnt } ) {
const m   = sm( status );
const pct = maxCnt ? Math.max( 4, ( cnt / maxCnt ) * 100 ) : 4;
return (
<div style={ { display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 } }>
<div style={ { width: 88, fontSize: 11, color: '#64748b', textAlign: 'right', flexShrink: 0 } }>{ m.label }</div>
<div style={ { flex: 1, background: '#f1f5f9', borderRadius: 6, height: 20, overflow: 'hidden', position: 'relative' } }>
<div style={ { width: pct + '%', background: m.color, height: '100%', borderRadius: 6, transition: 'width 0.5s', opacity: 0.85 } } />
<span style={ { position: 'absolute', left: 8, top: 2, fontSize: 11, fontWeight: 700, color: pct > 25 ? '#fff' : m.color } }>{ cnt }</span>
</div>
<div style={ { width: 44, fontSize: 11, color: '#334155', fontWeight: 700, textAlign: 'right', flexShrink: 0 } }>
{ maxCnt ? ( ( cnt / maxCnt ) * 100 ).toFixed( 0 ) + '%' : '\u2014' }
</div>
</div>
);
}

// ── Woo revenue mini chart ──────────────────────────────────────────────
function RevenueChart( { data } ) {
const max = Math.max( ...data.map( ( d ) => d.revenue ), 1 );
return (
<div className="bzc-chart">
{ data.map( ( d ) => {
const h = ( d.revenue / max ) * 100;
return (
<div key={ d.month } className="bzc-chart-col">
<div className="bzc-chart-bar" style={ { height: h + '%' } } title={ formatMoney( d.revenue ) } />
<div className="bzc-chart-x">{ d.month.slice( 5 ) }</div>
</div>
);
} ) }
</div>
);
}

// ── Date range picker ──────────────────────────────────────────────────
function DateRangePicker( { from, to, onChange } ) {
return (
<div style={ { display: 'flex', gap: 6, alignItems: 'center', fontSize: 12 } }>
<input type="date" value={ from } onChange={ ( e ) => onChange( e.target.value, to ) }
style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
<span style={ { color: '#94a3b8' } }>&rarr;</span>
<input type="date" value={ to } onChange={ ( e ) => onChange( from, e.target.value ) }
style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
</div>
);
}

// ── Custom tooltip ──────────────────────────────────────────────────────
function TrendTooltip( { active, payload, label } ) {
if ( ! active || ! payload || ! payload.length ) { return null; }
return (
<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: '8px 12px', fontSize: 12 } }>
<div style={ { fontWeight: 700, color: '#334155', marginBottom: 2 } }>{ label }</div>
<div style={ { color: '#6366f1' } }>{ payload[ 0 ].value } leads</div>
</div>
);
}

function Stat() { return null; } // legacy stub

export default function DashboardTab() {
const [ from, setFrom ] = useState( () => new Date().toISOString().slice( 0, 7 ) + '-01' );
const [ to,   setTo   ] = useState( () => new Date().toISOString().slice( 0, 10 ) );

const wooArgs = { from: '-30 days', to: 'now' };
const { data: wooSummary, isError: wooError } = useGetReportsWooSummaryQuery( wooArgs );
const { data: wooTop }  = useGetReportsWooTopCustomersQuery( { ...wooArgs, limit: 5 } );
const { data: wooTrend } = useGetReportsWooTrendQuery( { months: 6 } );
const { data: tasks = [] } = useGetCrmTasksQuery( { limit: 50 } );
const { data: overview, isFetching: ovLoading } = useGetCrmOverviewQuery( { from, to } );

const leads    = overview?.leads    || {};
const agents   = overview?.agents   || [];
const contacts = overview?.contacts || {};
const wcActive = wooSummary?.wc_active !== false;
const wooSum   = wooSummary?.summary || {};

const byStatus   = leads.by_status   || [];
const dailyTrend = leads.daily_trend || [];
const maxFunnelCnt = byStatus.length ? Math.max( ...byStatus.map( ( d ) => d.cnt ), 1 ) : 1;

const upcomingTasks = useMemo(
() => tasks.filter( ( t ) => ! t.completed && t.status !== 'done' && t.status !== 'closed' )
.sort( ( a, b ) => String( a.due_date || '9999' ).localeCompare( String( b.due_date || '9999' ) ) )
.slice( 0, 5 ),
[ tasks ],
);

const chartData = useMemo( () => {
if ( Array.isArray( wooTrend?.months ) && wooTrend.months.length > 0 ) {
return wooTrend.months.map( ( m ) => ( { month: m.month, revenue: Number( m.gross || 0 ) } ) );
}
const out = [];
const today = new Date();
for ( let i = 5; i >= 0; i-- ) {
const d = new Date( today.getFullYear(), today.getMonth() - i, 1 );
out.push( { month: d.toISOString().slice( 0, 7 ), revenue: 0 } );
}
return out;
}, [ wooTrend ] );

const topCustomers = Array.isArray( wooTop?.customers ) ? wooTop.customers : [];

return (
<div className="bzc-tabpane">
<header className="bzc-tabpane-header" style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12 } }>
<div>
<h2 className="bzc-tabpane-title">Dashboard</h2>
<p className="bzc-tabpane-subtitle">CRM Submissions &middot; Leads funnel &middot; Hi&#7879;u su&#7845;t nh&#226;n vi&#234;n &middot; Woo Revenue</p>
</div>
<DateRangePicker from={ from } to={ to } onChange={ ( f, t ) => { setFrom( f ); setTo( t ); } } />
</header>

{ /* ── SECTION 1: CRM KPI row ── */ }
<div style={ { marginBottom: 4 } }>
<div style={ { fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 10 } }>
CRM Submissions { ovLoading && <span style={ { fontWeight: 400 } }>&#273;ang t&#7843;i&#8230;</span> }
</div>
<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 } }>
<KpiCard icon={ TrendingUp }  label="Tổng leads"          value={ leads.total ?? '…' }             sub={ `kỳ trước: ${ leads.prev ?? 0 }` }            delta={ leads.delta_pct }                        color="#6366f1" bg="#eef2ff" />
<KpiCard icon={ UserCheck }   label="Đã giao việc"        value={ leads.assigned ?? '…' }          sub={ `${ leads.unassigned ?? 0 } chưa giao` }       color="#0891b2" bg="#ecfeff" />
<KpiCard icon={ Clock }       label="Đang xử lý"          value={ leads.in_progress ?? '…' }       sub="contacted → đàm phán"                           color="#ca8a04" bg="#fefce8" />
<KpiCard icon={ Award }       label="Thành công ✓"        value={ leads.closed_won ?? '…' }        sub={ `thất bại: ${ leads.closed_lost ?? 0 }` }      color="#16a34a" bg="#f0fdf4" />
<KpiCard icon={ Target }      label="Tỉ lệ chuyển đổi"   value={ leads.conversion_rate !== undefined ? leads.conversion_rate + '%' : '…' } sub="closed_won / tổng" color={ ( leads.conversion_rate ?? 0 ) >= 20 ? '#16a34a' : '#f59e0b' } bg={ ( leads.conversion_rate ?? 0 ) >= 20 ? '#f0fdf4' : '#fffbeb' } />
<KpiCard icon={ Users }       label="Contacts"            value={ contacts.total ?? '…' }          sub={ `+${ contacts.new_period ?? 0 } mới kỳ này` }  color="#7c3aed" bg="#f5f3ff" />
</div>
</div>

{ /* ── SECTION 2: Funnel + Trend ── */ }
<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 } }>
<Card>
<CardHeader><CardTitle>&#128202; Ph&#7877;u chuy&#7875;n &#273;&#7893;i Leads</CardTitle></CardHeader>
<CardBody>
{ byStatus.length === 0 && ! ovLoading && <div style={ { color: '#94a3b8', fontSize: 12, textAlign: 'center', padding: 20 } }>Ch&#432;a c&#243; d&#7919; li&#7879;u trong k&#7923; n&#224;y.</div> }
{ byStatus.map( ( d ) => (
<FunnelBar key={ d.status } status={ d.status } cnt={ d.cnt } maxCnt={ maxFunnelCnt } />
) ) }
</CardBody>
</Card>

<Card>
<CardHeader><CardTitle>&#128200; Xu h&#432;&#7899;ng leads theo ng&#224;y</CardTitle></CardHeader>
<CardBody>
{ dailyTrend.length === 0 && ! ovLoading ? (
<div style={ { color: '#94a3b8', fontSize: 12, textAlign: 'center', padding: 20 } }>Ch&#432;a c&#243; d&#7919; li&#7879;u.</div>
) : (
<ResponsiveContainer width="100%" height={ 190 }>
<AreaChart data={ dailyTrend } margin={ { top: 4, right: 8, left: -20, bottom: 0 } }>
<defs>
<linearGradient id="gradLeads" x1="0" y1="0" x2="0" y2="1">
<stop offset="5%"  stopColor="#6366f1" stopOpacity={ 0.3 } />
<stop offset="95%" stopColor="#6366f1" stopOpacity={ 0 } />
</linearGradient>
</defs>
<CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
<XAxis dataKey="day" tick={ { fontSize: 10 } } tickFormatter={ ( v ) => v.slice( 5 ) } />
<YAxis tick={ { fontSize: 10 } } allowDecimals={ false } />
<Tooltip content={ <TrendTooltip /> } />
<Area type="monotone" dataKey="cnt" stroke="#6366f1" strokeWidth={ 2 } fill="url(#gradLeads)" />
</AreaChart>
</ResponsiveContainer>
) }
</CardBody>
</Card>
</div>

{ /* ── SECTION 3: Agent performance table (manager-only) ── */ }
{ IS_MANAGER && (
<Card style={ { marginBottom: 14 } }>
<CardHeader><CardTitle>&#128101; Hi&#7879;u su&#7845;t nh&#226;n vi&#234;n</CardTitle></CardHeader>
<CardBody style={ { padding: 0 } }>
{ agents.length === 0 && ! ovLoading ? (
<div style={ { color: '#94a3b8', fontSize: 12, textAlign: 'center', padding: 24 } }>
Ch&#432;a c&#243; d&#7919; li&#7879;u &#8212; &#273;&#7843;m b&#7843;o submissions &#273;&#227; &#273;&#432;&#7907;c giao cho nh&#226;n vi&#234;n.
</div>
) : (
<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
<thead>
<tr style={ { background: '#f8fafc' } }>
{ [ 'Nh&#226;n vi&#234;n', 'T&#7893;ng', 'M&#7899;i', '&#272;ang XL', 'Th&#224;nh c&#244;ng', 'Th&#7845;t b&#7841;i', 'T&#7881; l&#7879; C&#272;' ].map( ( h, i ) => (
<th key={ h } style={ { padding: '9px 12px', textAlign: i === 0 ? 'left' : 'right', color: '#64748b', fontWeight: 600, borderBottom: '2px solid #e2e8f0', fontSize: 11, whiteSpace: 'nowrap' } } dangerouslySetInnerHTML={ { __html: h } } />
) ) }
</tr>
</thead>
<tbody>
{ agents.map( ( a ) => (
<tr key={ a.user_id } style={ { borderBottom: '1px solid #f1f5f9' } }>
<td style={ { padding: '9px 12px', fontWeight: 600, color: '#334155' } }>
<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
<div style={ { width: 28, height: 28, borderRadius: '50%', background: '#eef2ff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 800, color: '#6366f1', flexShrink: 0 } }>
{ ( a.name || '?' ).split( ' ' ).pop()[ 0 ].toUpperCase() }
</div>
{ a.name }
</div>
</td>
<td style={ { padding: '9px 12px', textAlign: 'right', fontWeight: 700, color: '#6366f1' } }>{ a.total }</td>
<td style={ { padding: '9px 12px', textAlign: 'right', color: '#2563eb' } }>{ a.cnt_new }</td>
<td style={ { padding: '9px 12px', textAlign: 'right', color: '#ca8a04' } }>{ a.in_progress }</td>
<td style={ { padding: '9px 12px', textAlign: 'right', color: '#16a34a', fontWeight: 700 } }>{ a.cnt_won }</td>
<td style={ { padding: '9px 12px', textAlign: 'right', color: '#dc2626' } }>{ a.cnt_lost }</td>
<td style={ { padding: '9px 12px', textAlign: 'right' } }>
<span style={ { background: a.rate >= 25 ? '#f0fdf4' : a.rate >= 10 ? '#fffbeb' : '#fef2f2', color: a.rate >= 25 ? '#16a34a' : a.rate >= 10 ? '#ca8a04' : '#dc2626', padding: '2px 8px', borderRadius: 12, fontWeight: 700, fontSize: 11 } }>
{ a.rate }%
</span>
</td>
</tr>
) ) }
</tbody>
</table>
) }
</CardBody>
</Card>
) }

{ /* ── SECTION 4: Woo + Tasks KPI ── */ }
<div style={ { marginBottom: 8 } }>
<div style={ { fontSize: 11, fontWeight: 700, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.06em', marginBottom: 10 } }>WooCommerce &amp; Tasks</div>
<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 } }>
<KpiCard icon={ ShoppingCart } label="Doanh thu Woo (30d)" value={ wcActive ? formatMoney( wooSum.gross || 0 ) : '—' } sub={ wcActive ? `${ wooSum.order_count || 0 } đơn · AOV ${ formatMoney( wooSum.aov || 0 ) }` : 'Woo không hoạt động' } color="#f59e0b" bg="#fffbeb" />
<KpiCard icon={ CheckSquare }  label="Task đang mở"        value={ upcomingTasks.length }  sub="hạn gần nhất trước" color="#64748b" bg="#f8fafc" />
</div>
</div>

{ wooError && (
<Card style={ { marginBottom: 14 } }><CardBody><span className="bzc-muted">Không tải được dữ liệu Woo. Kiểm tra <code>BizCity_CRM_Woo_Reports_Bridge</code>.</span></CardBody></Card>
) }

{ /* ── SECTION 5: Lower grid ── */ }
<div className="bzc-dash-grid">
<Card className="bzc-dash-chart">
<CardHeader><CardTitle>Doanh thu 6 tháng (Woo)</CardTitle></CardHeader>
<CardBody><RevenueChart data={ chartData } /></CardBody>
</Card>

<Card>
<CardHeader><CardTitle>Top khách Woo (30d)</CardTitle></CardHeader>
<CardBody>
{ ! wcActive ? (
<div className="bzc-empty bzc-muted">Woo không hoạt động.</div>
) : topCustomers.length === 0 ? (
<div className="bzc-empty bzc-muted">Chưa có đơn nào trong 30 ngày.</div>
) : (
<ul className="bzc-mini-list">
{ topCustomers.map( ( c ) => (
<li key={ c.contact_id || c.wp_user_id || c.email || c.name }>
<strong>{ c.name || c.email || 'Khách lẻ' }</strong>
<span className="bzc-muted">{ c.order_count } đơn · { formatMoney( c.gross || 0 ) }</span>
</li>
) ) }
</ul>
) }
</CardBody>
</Card>

<Card>
<CardHeader><CardTitle>Task sắp tới</CardTitle></CardHeader>
<CardBody>
{ upcomingTasks.length === 0 ? (
<div className="bzc-empty bzc-muted">Không có task mở.</div>
) : (
<ul className="bzc-mini-list">
{ upcomingTasks.map( ( t ) => (
<li key={ t.id }>
<strong>{ t.title }</strong>
<span className="bzc-muted">
{ t.due_date || '\u2014' } &middot; <Badge variant={ t.priority === 'high' ? 'danger' : t.priority === 'medium' ? 'warn' : 'muted' }>{ t.priority || 'low' }</Badge>
</span>
</li>
) ) }
</ul>
) }
</CardBody>
</Card>

<Card className="bzc-dash-activity">
<CardHeader><CardTitle>Activity gần đây</CardTitle></CardHeader>
<CardBody><ActivityFeed global={ true } /></CardBody>
</Card>
</div>
</div>
);
}