import React, { useState, useMemo } from 'react';
import { RefreshCw, Download, CheckCircle2, XCircle, AlertCircle, Filter } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Input } from '../../components/ui/input.jsx';
import { useGetEmailSendLogsQuery, useGetEmailSendLogStatsQuery, useGetEmailCf7CampaignStatsQuery } from '../../redux/api/crmApi.js';
// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — CF7 per-campaign import added

/*
 * [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — Email Send Log dashboard
 * Shows per-send history table + summary stats cards + CSV export button.
 */

const PERIODS = [
	{ value: 'today', label: 'Hôm nay' },
	{ value: '7d',    label: '7 ngày' },
	{ value: '30d',   label: '30 ngày' },
	{ value: 'all',   label: 'Tất cả' },
];

const STATUS_MAP = {
	sent:    { label: 'Đã gửi',  icon: CheckCircle2, color: '#16a34a', bg: '#f0fdf4', border: '#86efac' },
	failed:  { label: 'Thất bại', icon: XCircle,     color: '#dc2626', bg: '#fef2f2', border: '#fca5a5' },
	skipped: { label: 'Bỏ qua',  icon: AlertCircle,  color: '#d97706', bg: '#fffbeb', border: '#fcd34d' },
};

function StatusBadge( { status } ) {
	const s = STATUS_MAP[ status ] || STATUS_MAP.skipped;
	const Icon = s.icon;
	return (
		<span style={ {
			display: 'inline-flex', alignItems: 'center', gap: 3,
			fontSize: 11, padding: '2px 8px', borderRadius: 20, fontWeight: 600,
			color: s.color, background: s.bg, border: '1px solid ' + s.border,
		} }>
			<Icon size={ 10 } />{ s.label }
		</span>
	);
}

function StatCard( { label, value, sub, color } ) {
	return (
		<div style={ {
			background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10,
			padding: '14px 18px', minWidth: 120,
		} }>
			<div style={ { fontSize: 22, fontWeight: 700, color: color || '#1e293b' } }>{ value }</div>
			<div style={ { fontSize: 12, color: '#64748b', marginTop: 2 } }>{ label }</div>
			{ sub && <div style={ { fontSize: 11, color: '#94a3b8', marginTop: 2 } }>{ sub }</div> }
		</div>
	);
}

function MiniBarChart( { days } ) {
	if ( ! days || days.length === 0 ) {
		return <div style={ { color: '#94a3b8', fontSize: 12, padding: '12px 0' } }>Chưa có dữ liệu.</div>;
	}
	const maxVal = Math.max( 1, ...days.map( ( d ) => ( parseInt( d.sent ) || 0 ) + ( parseInt( d.failed ) || 0 ) ) );
	return (
		<div style={ { display: 'flex', alignItems: 'flex-end', gap: 4, height: 60 } }>
			{ days.map( ( d ) => {
				const sent   = parseInt( d.sent )   || 0;
				const failed = parseInt( d.failed ) || 0;
				const total  = sent + failed;
				const h = Math.round( ( total / maxVal ) * 52 );
				const fh = total > 0 ? Math.round( ( failed / total ) * h ) : 0;
				return (
					<div key={ d.day } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', flex: 1 } } title={ `${ d.day }\nGửi: ${ sent }\nLỗi: ${ failed }` }>
						<div style={ { width: '100%', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', height: 52 } }>
							{ fh > 0 && <div style={ { height: fh, background: '#fca5a5', borderRadius: '2px 2px 0 0' } } /> }
							{ ( h - fh ) > 0 && <div style={ { height: h - fh, background: '#86efac', borderRadius: fh > 0 ? 0 : '2px 2px 0 0' } } /> }
						</div>
						<div style={ { fontSize: 9, color: '#94a3b8', marginTop: 2, transform: 'rotate(-45deg)', transformOrigin: 'top left', whiteSpace: 'nowrap' } }>
							{ d.day.slice( 5 ) }
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

export default function EmailLogsTab() {
	const [ period, setPeriod ]     = useState( '7d' );
	const [ statusFilter, setStatus ] = useState( '' );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ]     = useState( '' );
	const [ page, setPage ]         = useState( 1 );
	const perPage = 50;

	const queryArgs = useMemo( () => ( {
		status:    statusFilter,
		date_from: dateFrom,
		date_to:   dateTo,
		page,
		per_page:  perPage,
		is_test:   0,
	} ), [ statusFilter, dateFrom, dateTo, page ] );

	const { data: logsData, isFetching, refetch } = useGetEmailSendLogsQuery( queryArgs );
	const { data: stats, isFetching: statsFetching } = useGetEmailSendLogStatsQuery( period );
	// [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — CF7 per-campaign stats
	const [ cf7Period, setCf7Period ] = useState( '7d' );
	const { data: cf7Data, isFetching: cf7Fetching } = useGetEmailCf7CampaignStatsQuery( cf7Period );

	const rows  = logsData?.rows  || [];
	const total = logsData?.total || 0;
	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	const restUrl = window.BIZCITY_CRM_BOOT?.restUrl || '';
	const nonce   = window.BIZCITY_CRM_BOOT?.restNonce || '';

	const handleExport = () => {
		const p = new URLSearchParams();
		if ( statusFilter ) { p.set( 'status', statusFilter ); }
		if ( dateFrom ) { p.set( 'date_from', dateFrom ); }
		if ( dateTo )   { p.set( 'date_to',   dateTo ); }
		p.set( '_wpnonce', nonce );
		window.open( restUrl + 'email-send-logs/export?' + p.toString(), '_blank' );
	};

	return (
		<div style={ { padding: '0 0 32px' } }>

			{ /* ── Stats cards ── */ }
			<div style={ { marginBottom: 18 } }>
				<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 10 } }>
					<strong style={ { fontSize: 14 } }>Thống kê gửi email</strong>
					<div style={ { display: 'flex', gap: 4 } }>
						{ PERIODS.map( ( p ) => (
							<button
								key={ p.value }
								onClick={ () => setPeriod( p.value ) }
								style={ {
									padding: '3px 10px', fontSize: 12, borderRadius: 20, cursor: 'pointer',
									border: '1px solid ' + ( period === p.value ? '#6366f1' : '#e2e8f0' ),
									background: period === p.value ? '#eef2ff' : '#fff',
									color: period === p.value ? '#4338ca' : '#64748b', fontWeight: period === p.value ? 700 : 400,
								} }
							>{ p.label }</button>
						) ) }
					</div>
					{ statsFetching && <RefreshCw size={ 12 } style={ { animation: 'spin 1s linear infinite', color: '#94a3b8' } } /> }
				</div>
				<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap' } }>
					<StatCard label="Tổng gửi thành công" value={ stats?.total_sent   ?? '—' } color="#16a34a" />
					<StatCard label="Thất bại"             value={ stats?.total_failed ?? '—' } color="#dc2626" />
					<StatCard label="Tỷ lệ thành công"    value={ ( stats?.success_rate ?? '—' ) + ( stats ? '%' : '' ) } color="#2563eb" />
				</div>
				{ stats?.by_day && stats.by_day.length > 0 && (
					<div style={ { marginTop: 12, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 18px' } }>
						<div style={ { fontSize: 12, color: '#64748b', marginBottom: 8 } }>Biểu đồ gửi theo ngày <span style={ { color: '#86efac' } }>■</span> thành công <span style={ { color: '#fca5a5' } }>■</span> thất bại</div>
						<MiniBarChart days={ stats.by_day } />
					</div>
				) }
				{ stats?.by_rule && stats.by_rule.length > 0 && (
					<div style={ { marginTop: 10, fontSize: 12 } }>
						<div style={ { fontWeight: 600, marginBottom: 6, color: '#475569' } }>Theo quy tắc</div>
						<table style={ { width: '100%', borderCollapse: 'collapse' } }>
							<thead>
								<tr style={ { background: '#f8fafc', textAlign: 'left', fontSize: 11, color: '#94a3b8' } }>
									<th style={ { padding: '5px 8px' } }>Quy tắc</th>
									<th style={ { padding: '5px 8px', textAlign: 'right' } }>Gửi</th>
									<th style={ { padding: '5px 8px', textAlign: 'right' } }>Lỗi</th>
								</tr>
							</thead>
							<tbody>
								{ stats.by_rule.map( ( r ) => (
									<tr key={ r.rule_id } style={ { borderBottom: '1px solid #f1f5f9' } }>
										<td style={ { padding: '5px 8px' } }>{ r.rule_name || '#' + r.rule_id }</td>
										<td style={ { padding: '5px 8px', textAlign: 'right', color: '#16a34a', fontWeight: 600 } }>{ r.sent }</td>
										<td style={ { padding: '5px 8px', textAlign: 'right', color: '#dc2626' } }>{ r.failed }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ /* [2026-06-20 Johnny Chu] PHASE-CG-CF7-LOG — CF7 campaign report */ }
				<div style={ { marginTop: 10, fontSize: 12 } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 } }>
						<strong style={ { color: '#475569' } }>📋 Email theo CF7 Form (Campaign)</strong>
						<div style={ { display: 'flex', gap: 3 } }>
							{ PERIODS.map( ( p ) => (
								<button
									key={ p.value }
									onClick={ () => setCf7Period( p.value ) }
									style={ {
										padding: '2px 9px', fontSize: 11, borderRadius: 20, cursor: 'pointer',
										border: '1px solid ' + ( cf7Period === p.value ? '#6366f1' : '#e2e8f0' ),
										background: cf7Period === p.value ? '#eef2ff' : '#fff',
										color: cf7Period === p.value ? '#4338ca' : '#64748b',
										fontWeight: cf7Period === p.value ? 700 : 400,
									} }
								>{ p.label }</button>
							) ) }
						</div>
						{ cf7Fetching && <RefreshCw size={ 11 } style={ { animation: 'spin 1s linear infinite', color: '#94a3b8' } } /> }
					</div>
					{ ( ! cf7Data?.forms || cf7Data.forms.length === 0 ) && ! cf7Fetching ? (
						<div style={ { color: '#94a3b8', fontSize: 12, padding: '8px 0' } }>Chưa có dữ liệu CF7 trong kỳ này.</div>
					) : (
						<table style={ { width: '100%', borderCollapse: 'collapse' } }>
							<thead>
								<tr style={ { background: '#f8fafc', textAlign: 'left', fontSize: 11, color: '#94a3b8' } }>
									<th style={ { padding: '5px 8px' } }>CF7 Form</th>
									<th style={ { padding: '5px 8px', textAlign: 'right' } }>Gửi</th>
									<th style={ { padding: '5px 8px', textAlign: 'right' } }>Lỗi</th>
									<th style={ { padding: '5px 8px', textAlign: 'right' } }>Tỷ lệ</th>
									<th style={ { padding: '5px 8px' } }>Lần cuối</th>
								</tr>
							</thead>
							<tbody>
								{ ( cf7Data?.forms || [] ).map( ( f ) => (
									<tr key={ f.event_key } style={ { borderBottom: '1px solid #f1f5f9' } }>
										<td style={ { padding: '5px 8px' } }>
											<span style={ { fontWeight: 600 } }>{ f.form_title }</span>
											{ f.form_id > 0 && (
												<span style={ { fontSize: 10, color: '#94a3b8', marginLeft: 5 } }>#{ f.form_id }</span>
											) }
										</td>
										<td style={ { padding: '5px 8px', textAlign: 'right', color: '#16a34a', fontWeight: 600 } }>{ f.sent }</td>
										<td style={ { padding: '5px 8px', textAlign: 'right', color: f.failed > 0 ? '#dc2626' : '#94a3b8' } }>{ f.failed }</td>
										<td style={ { padding: '5px 8px', textAlign: 'right', color: '#2563eb' } }>{ f.success_rate }%</td>
										<td style={ { padding: '5px 8px', color: '#64748b', fontSize: 11 } }>
											{ f.last_sent_at ? f.last_sent_at.slice( 0, 16 ).replace( 'T', ' ' ) : '—' }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</div>
			</div>

			{ /* ── Filters + actions ── */ }
			<div style={ { display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 10 } }>
				<Filter size={ 14 } style={ { color: '#94a3b8' } } />
				<select
					className="bzc-input" style={ { width: 130, fontSize: 12 } }
					value={ statusFilter }
					onChange={ ( e ) => { setStatus( e.target.value ); setPage( 1 ); } }
				>
					<option value="">Tất cả trạng thái</option>
					<option value="sent">Đã gửi</option>
					<option value="failed">Thất bại</option>
					<option value="skipped">Bỏ qua</option>
				</select>
				<Input
					type="date" value={ dateFrom }
					onChange={ ( e ) => { setDateFrom( e.target.value ); setPage( 1 ); } }
					style={ { width: 140, fontSize: 12 } } placeholder="Từ ngày"
				/>
				<span style={ { color: '#94a3b8', fontSize: 12 } }>→</span>
				<Input
					type="date" value={ dateTo }
					onChange={ ( e ) => { setDateTo( e.target.value ); setPage( 1 ); } }
					style={ { width: 140, fontSize: 12 } } placeholder="Đến ngày"
				/>
				<Button size="sm" onClick={ () => { setStatus( '' ); setDateFrom( '' ); setDateTo( '' ); setPage( 1 ); } }>
					Xoá lọc
				</Button>
				<div style={ { marginLeft: 'auto', display: 'flex', gap: 6 } }>
					<Button size="sm" onClick={ refetch } disabled={ isFetching }>
						<RefreshCw size={ 12 } style={ isFetching ? { animation: 'spin 1s linear infinite' } : {} } />
						&nbsp;Làm mới
					</Button>
					<Button size="sm" variant="secondary" onClick={ handleExport } title="Xuất CSV">
						<Download size={ 12 } />&nbsp;Xuất CSV
					</Button>
				</div>
			</div>

			{ /* ── Table ── */ }
			<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' } }>
				{ isFetching && (
					<div style={ { padding: '12px 16px', fontSize: 12, color: '#94a3b8', borderBottom: '1px solid #f1f5f9' } }>
						<RefreshCw size={ 12 } style={ { display: 'inline', marginRight: 4, animation: 'spin 1s linear infinite' } } />
						Đang tải…
					</div>
				) }
				{ rows.length === 0 && ! isFetching && (
					<div style={ { padding: 32, textAlign: 'center', color: '#94a3b8', fontSize: 13 } }>
						Chưa có log nào. Log sẽ được ghi khi có email được gửi qua quy tắc tự động.
					</div>
				) }
				{ rows.length > 0 && (
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
						<thead>
							<tr style={ { background: '#f8fafc', textAlign: 'left', color: '#94a3b8', fontSize: 11 } }>
								<th style={ { padding: '8px 12px' } }>Thời gian</th>
								<th style={ { padding: '8px 12px' } }>Trạng thái</th>
								<th style={ { padding: '8px 12px' } }>Người nhận</th>
								<th style={ { padding: '8px 12px' } }>Tiêu đề</th>
								<th style={ { padding: '8px 12px' } }>Quy tắc</th>
								<th style={ { padding: '8px 12px' } }>Sự kiện</th>
								<th style={ { padding: '8px 12px' } }>Nguồn SMTP</th>
								<th style={ { padding: '8px 12px' } }>Lỗi</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( r ) => (
								<tr key={ r.id } style={ { borderBottom: '1px solid #f1f5f9' } }>
									<td style={ { padding: '7px 12px', whiteSpace: 'nowrap', color: '#64748b' } }>
										{ r.sent_at ? r.sent_at.slice( 0, 16 ).replace( 'T', ' ' ) : '' }
									</td>
									<td style={ { padding: '7px 12px' } }>
										<StatusBadge status={ r.status } />
									</td>
									<td style={ { padding: '7px 12px', maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } } title={ r.recipient_email }>
										{ r.recipient_email }
									</td>
									<td style={ { padding: '7px 12px', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } } title={ r.subject }>
										{ r.subject }
									</td>
									<td style={ { padding: '7px 12px', color: '#475569' } }>
										{ r.rule_name || ( r.rule_id ? '#' + r.rule_id : '—' ) }
									</td>
									<td style={ { padding: '7px 12px', fontFamily: 'monospace', fontSize: 11, color: '#6366f1' } }>
										{ r.event_key }
									</td>
									<td style={ { padding: '7px 12px', color: '#64748b' } }>
										{ r.smtp_source === 'crm_gmail' ? '📧 Gmail' : r.smtp_source === 'wp_mail' ? '🔧 wp_mail' : r.smtp_source || '—' }
										{ parseInt( r.has_attachment ) ? ' 📎' : '' }
									</td>
									<td style={ { padding: '7px 12px', color: '#dc2626', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } } title={ r.error_message }>
										{ r.error_message || '' }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>

			{ /* ── Pagination ── */ }
			{ totalPages > 1 && (
				<div style={ { display: 'flex', gap: 6, alignItems: 'center', marginTop: 10, fontSize: 12, color: '#64748b' } }>
					<button disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) } style={ { padding: '3px 10px', borderRadius: 6, border: '1px solid #e2e8f0', background: page <= 1 ? '#f8fafc' : '#fff', cursor: page <= 1 ? 'default' : 'pointer' } }>←</button>
					<span>Trang { page } / { totalPages } · { total } bản ghi</span>
					<button disabled={ page >= totalPages } onClick={ () => setPage( page + 1 ) } style={ { padding: '3px 10px', borderRadius: 6, border: '1px solid #e2e8f0', background: page >= totalPages ? '#f8fafc' : '#fff', cursor: page >= totalPages ? 'default' : 'pointer' } }>→</button>
				</div>
			) }
		</div>
	);
}
