import React, { useState } from 'react';
import { RefreshCw, CheckCircle2, XCircle, AlertCircle, MessageSquare, FlaskConical, Plus, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import { useGetZnsSendLogsQuery, useGetZnsSendLogStatsQuery, useTestZnsSendMutation, useGetZaloOaAccountsQuery } from '../../redux/api/crmApi.js';

// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — OA ID selector (loads from Channel Gateway registry)
function OaSelector( { value, onChange, placeholder = 'Chọn OA đã cấu hình...' } ) {
	const { data: oaAccounts = [], isLoading } = useGetZaloOaAccountsQuery();
	const [ manual, setManual ] = useState( false );

	if ( isLoading ) {
		return <div style={ { fontSize: 12, color: '#94a3b8', padding: '6px 0' } }>Đang tải danh sách OA...</div>;
	}
	if ( oaAccounts.length === 0 || manual ) {
		return (
			<div>
				<Input value={ value } onChange={ ( e ) => onChange( e.target.value ) } placeholder="Nhập OA ID thủ công" />
				{ oaAccounts.length > 0 && (
					<button onClick={ () => setManual( false ) } style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', padding: 0, marginTop: 2 } }>
						← Chọn từ danh sách
					</button>
				) }
			</div>
		);
	}
	return (
		<div>
			<select
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				style={ { width: '100%', padding: '8px 10px', borderRadius: 6, border: '1px solid #e2e8f0', fontSize: 13, background: '#fff', color: value ? '#334155' : '#94a3b8' } }
			>
				<option value="">{ placeholder }</option>
				{ oaAccounts.map( ( a ) => (
					<option key={ a.oa_id } value={ a.oa_id }>{ a.label } — { a.oa_id }</option>
				) ) }
			</select>
			<button onClick={ () => setManual( true ) } style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', padding: 0, marginTop: 2 } }>
				Nhập OA ID khác thủ công →
			</button>
		</div>
	);
}

/*
 * [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS (Zalo Notification Service) Tab
 * Shows ZNS send history + stats cards + chart, mirroring EmailLogsTab.
 */

const PERIODS = [
	{ value: 'today', label: 'Hôm nay' },
	{ value: '7d',    label: '7 ngày' },
	{ value: '30d',   label: '30 ngày' },
	{ value: 'all',   label: 'Tất cả' },
];

const STATUS_MAP = {
	sent:    { label: 'Đã gửi',   icon: CheckCircle2, color: '#16a34a', bg: '#f0fdf4', border: '#86efac' },
	failed:  { label: 'Thất bại', icon: XCircle,      color: '#dc2626', bg: '#fef2f2', border: '#fca5a5' },
	skipped: { label: 'Bỏ qua',   icon: AlertCircle,  color: '#d97706', bg: '#fffbeb', border: '#fcd34d' },
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
				const hSent   = total > 0 ? Math.round( ( sent   / maxVal ) * 52 ) : 0;
				const hFailed = total > 0 ? Math.round( ( failed / maxVal ) * 52 ) : 0;
				return (
					<div key={ d.day } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', flex: 1 } }>
						<div title={ 'Gửi OK: ' + sent } style={ { width: '100%', height: hSent,   background: '#22c55e', borderRadius: '2px 2px 0 0', minHeight: sent   > 0 ? 2 : 0 } } />
						<div title={ 'Thất bại: ' + failed } style={ { width: '100%', height: hFailed, background: '#ef4444', borderRadius: '2px 2px 0 0', minHeight: failed > 0 ? 2 : 0 } } />
						<div style={ { fontSize: 9, color: '#94a3b8', marginTop: 2, transform: 'rotate(-35deg)', transformOrigin: 'center', whiteSpace: 'nowrap' } }>
							{ d.day ? d.day.slice( 5 ) : '' }
						</div>
					</div>
				);
			} ) }
		</div>
	);
}

export default function ZnsTab() {
	const [ period,   setPeriod ]   = useState( '7d' );
	const [ page,     setPage ]     = useState( 1 );
	const [ subTab,   setSubTab ]   = useState( 'stats' ); // 'stats' | 'logs' | 'test'

	// ── Test panel state ──────────────────────────────────────────────────────
	const [ testPhone,    setTestPhone ]    = useState( '' );
	const [ testTempId,   setTestTempId ]   = useState( '' );
	const [ testOaId,     setTestOaId ]     = useState( '' );
	const [ testVars,     setTestVars ]     = useState( [ { key: '', value: '' } ] ); // TempData rows
	const [ testResult,   setTestResult ]   = useState( null );
	const [ doTestSend,   { isLoading: testSending } ] = useTestZnsSendMutation();

	const { data: stats,    isFetching: statsFetching, refetch: refetchStats } = useGetZnsSendLogStatsQuery( period );
	const { data: logsData, isFetching: logsFetching,  refetch: refetchLogs  } = useGetZnsSendLogsQuery( { page, is_test: -1 }, { skip: subTab !== 'logs' } );

	const rows  = logsData?.rows  || [];
	const total = logsData?.total || 0;
	const perPage = 50;
	const totalPages = Math.max( 1, Math.ceil( total / perPage ) );

	const handleRefresh = () => {
		refetchStats();
		if ( subTab === 'logs' ) { refetchLogs(); }
	};

	const handleTestSend = async () => {
		setTestResult( null );
		// Build temp_data from key/value rows (ignore empty keys)
		const temp_data = {};
		testVars.forEach( ( v ) => { if ( v.key.trim() ) { temp_data[ v.key.trim() ] = v.value; } } );
		try {
			const res = await doTestSend( {
				phone:     testPhone.trim(),
				temp_id:   testTempId.trim(),
				oa_id:     testOaId.trim() || undefined,
				temp_data: Object.keys( temp_data ).length > 0 ? temp_data : undefined,
			} ).unwrap();
			setTestResult( { ok: res?.sent, data: res } );
		} catch ( err ) {
			const msg = err?.data?.data?.error || err?.data?.error?.message || err?.message || 'Lỗi kết nối';
			setTestResult( { ok: false, error: msg } );
		}
	};

	const addTestVar = () => setTestVars( ( v ) => [ ...v, { key: '', value: '' } ] );
	const removeTestVar = ( i ) => setTestVars( ( v ) => v.filter( ( _, idx ) => idx !== i ) );
	const updateTestVar = ( i, field, val ) => setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, [ field ]: val } : r ) );

	return (
		<div>
			{ /* ── Sub-tab bar ── */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '1px solid #e2e8f0', marginBottom: 16 } }>
				{ [ { id: 'stats', label: '📊 Thống kê' }, { id: 'logs', label: '📋 Lịch sử gửi' }, { id: 'test', label: '🧪 Gửi thử / Debug' } ].map( ( t ) => (
					<button
						key={ t.id }
						onClick={ () => setSubTab( t.id ) }
						style={ {
							padding: '7px 16px', fontSize: 13, cursor: 'pointer',
							background: 'none', border: 'none',
							borderBottom: subTab === t.id ? '2px solid #6366f1' : '2px solid transparent',
							color: subTab === t.id ? '#4338ca' : '#64748b',
							fontWeight: subTab === t.id ? 700 : 400,
							marginBottom: -1,
						} }
					>{ t.label }</button>
				) ) }

				{ /* Period selector — only for stats/logs tabs */ }
				{ subTab !== 'test' && (
					<div style={ { marginLeft: 'auto', display: 'flex', gap: 4, alignItems: 'center', paddingBottom: 4 } }>
						{ PERIODS.map( ( p ) => (
							<button key={ p.value } onClick={ () => setPeriod( p.value ) } style={ {
								padding: '3px 12px', fontSize: 12, cursor: 'pointer', borderRadius: 20,
								background: period === p.value ? '#6366f1' : '#f1f5f9',
								color:      period === p.value ? '#fff'    : '#64748b',
								border: 'none', fontWeight: period === p.value ? 700 : 400,
							} }>{ p.label }</button>
						) ) }
						<button onClick={ handleRefresh } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#64748b', padding: '3px 6px' } }
							title="Làm mới">
							<RefreshCw size={ 14 } className={ statsFetching || logsFetching ? 'spin' : '' } />
						</button>
					</div>
				) }
			</div>

			{ /* ── Stats sub-tab ── */ }
			{ subTab === 'stats' && (
				<div>
					{ /* Stat cards */ }
					<div style={ { display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 20 } }>
						<StatCard label="Tổng đã gửi" value={ stats?.total_sent ?? 0 } color="#16a34a" />
						<StatCard label="Thất bại"    value={ stats?.total_failed ?? 0 } color="#dc2626" />
						<StatCard
							label="Tỷ lệ thành công"
							value={ ( stats?.success_rate ?? 0 ) + '%' }
							sub={ stats ? ( 'Gồm ' + ( ( stats.total_sent || 0 ) + ( stats.total_failed || 0 ) ) + ' lần gửi' ) : '' }
							color="#6366f1"
						/>
					</div>

					{ /* Mini bar chart */ }
					<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 18px', marginBottom: 20 } }>
						<div style={ { fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 10, display: 'flex', alignItems: 'center', gap: 6 } }>
							<MessageSquare size={ 13 } style={ { color: '#6366f1' } } /> Biểu đồ ZNS theo ngày
							<span style={ { marginLeft: 8, fontSize: 11, color: '#94a3b8', fontWeight: 400 } }>
								<span style={ { color: '#22c55e' } }>■</span> Gửi OK &nbsp;
								<span style={ { color: '#ef4444' } }>■</span> Thất bại
							</span>
						</div>
						<MiniBarChart days={ stats?.by_day || [] } />
					</div>

					{ /* Per-form breakdown */ }
					{ stats?.by_form && stats.by_form.length > 0 && (
						<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 18px' } }>
							<div style={ { fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 10 } }>Theo form</div>
							<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
								<thead>
									<tr style={ { background: '#f8fafc' } }>
										<th style={ { padding: '6px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Form</th>
										<th style={ { padding: '6px 10px', textAlign: 'right', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Gửi OK</th>
										<th style={ { padding: '6px 10px', textAlign: 'right', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Thất bại</th>
									</tr>
								</thead>
								<tbody>
									{ stats.by_form.map( ( f, i ) => (
										<tr key={ f.form_id + '-' + i } style={ { borderBottom: '1px solid #f1f5f9' } }>
											<td style={ { padding: '7px 10px', color: '#334155' } }>
												{ f.form_title || ( 'Form #' + f.form_id ) }
											</td>
											<td style={ { padding: '7px 10px', textAlign: 'right', color: '#16a34a', fontWeight: 600 } }>{ f.sent }</td>
											<td style={ { padding: '7px 10px', textAlign: 'right', color: '#dc2626', fontWeight: 600 } }>{ f.failed }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ statsFetching && <div style={ { fontSize: 12, color: '#94a3b8', marginTop: 12 } }>Đang tải...</div> }
					{ ! statsFetching && ! stats && <div style={ { fontSize: 12, color: '#94a3b8', marginTop: 12 } }>Chưa có dữ liệu. ZNS sẽ xuất hiện ở đây sau khi được gửi qua form CF7.</div> }
				</div>
			) }

			{ /* ── Logs sub-tab ── */ }
			{ subTab === 'logs' && (
				<div>
					{ logsFetching && <div style={ { fontSize: 12, color: '#94a3b8', marginBottom: 10 } }>Đang tải...</div> }
					{ rows.length === 0 && ! logsFetching && (
						<div style={ { fontSize: 13, color: '#94a3b8', padding: '24px 0', textAlign: 'center' } }>
							Chưa có lịch sử gửi ZNS.
						</div>
					) }
					{ rows.length > 0 && (
						<>
							<div style={ { overflowX: 'auto' } }>
								<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
									<thead>
										<tr style={ { background: '#f8fafc' } }>
											<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Form</th>
											<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Số điện thoại</th>
											<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Template ID</th>
											<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Trạng thái</th>
											<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>SMSID / Lỗi</th>
											<th style={ { padding: '7px 10px', textAlign: 'right', color: '#64748b', borderBottom: '1px solid #e2e8f0' } }>Thời gian</th>
										</tr>
									</thead>
									<tbody>
										{ rows.map( ( r ) => (
											<tr key={ r.id } style={ { borderBottom: '1px solid #f1f5f9', background: r.is_test ? '#fffbeb' : undefined } }>
												<td style={ { padding: '7px 10px', color: '#334155' } }>
													{ r.form_title || ( 'Form #' + r.form_id ) }
													{ !! r.is_test && <span style={ { marginLeft: 5, fontSize: 10, color: '#d97706', background: '#fef9c3', borderRadius: 8, padding: '1px 5px' } }>TEST</span> }
												</td>
												<td style={ { padding: '7px 10px', color: '#64748b', fontFamily: 'monospace' } }>{ r.phone }</td>
												<td style={ { padding: '7px 10px', color: '#64748b', fontFamily: 'monospace' } }>{ r.temp_id }</td>
												<td style={ { padding: '7px 10px' } }><StatusBadge status={ r.status } /></td>
												<td style={ { padding: '7px 10px', color: r.sms_id ? '#334155' : '#dc2626', maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
													{ r.sms_id || r.error_message || '—' }
												</td>
												<td style={ { padding: '7px 10px', textAlign: 'right', color: '#94a3b8', whiteSpace: 'nowrap' } }>
													{ r.sent_at ? r.sent_at.slice( 0, 16 ).replace( 'T', ' ' ) : '—' }
												</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>

							{ /* Pagination */ }
							<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 12, fontSize: 12, color: '#64748b' } }>
								<span>Tổng: { total } bản ghi</span>
								<div style={ { display: 'flex', gap: 6 } }>
									<Button size="sm" variant="ghost" disabled={ page <= 1 } onClick={ () => setPage( p => p - 1 ) }>← Trước</Button>
									<span style={ { padding: '4px 8px' } }>{ page } / { totalPages }</span>
									<Button size="sm" variant="ghost" disabled={ page >= totalPages } onClick={ () => setPage( p => p + 1 ) }>Sau →</Button>
								</div>
							</div>
						</>
					) }
				</div>
			) }

			{ /* ── Test sub-tab ── */ }
			{ subTab === 'test' && (
				<div style={ { maxWidth: 560 } }>
					<h4 style={ { fontWeight: 700, fontSize: 14, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 } }>
						<FlaskConical size={ 14 } style={ { color: '#6366f1' } } /> Gửi thử ZNS — Sandbox mode
					</h4>
					<p style={ { fontSize: 12, color: '#64748b', marginBottom: 14, lineHeight: 1.6 } }>
						Sử dụng thông tin xác thực eSMS đã cài đặt trong Channel Gateway → CF7 ZNS.
						Tất cả yêu cầu ở đây đều gửi <strong>Sandbox=1</strong> (eSMS không tính phí thật).
						Log sẽ được ghi vào <code>bizcity-channel-logs/zalo_zns/YYYY-MM-DD.jsonl</code>.
					</p>

					<div style={ { display: 'flex', flexDirection: 'column', gap: 10 } }>
						<div>
							<Label>Số điện thoại nhận *</Label>
							<Input
								type="text"
								placeholder="0901234567"
								value={ testPhone }
								onChange={ ( e ) => setTestPhone( e.target.value ) }
							/>
						</div>
						<div>
							<Label>Template ID (TempID) *</Label>
							<Input
								type="text"
								placeholder="123456"
								value={ testTempId }
								onChange={ ( e ) => setTestTempId( e.target.value ) }
							/>
							<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>
								ID template ZNS từ eSMS dashboard. Ghi đè TempID đã lưu trong cấu hình form.
							</p>
						</div>
						<div>
							<Label>OA ID (tuỳ chọn — dùng global nếu để trống)</Label>
							<OaSelector value={ testOaId } onChange={ setTestOaId } placeholder="Dùng OA ID global từ cài đặt..." />
						</div>

						{ /* TempData (biến nội dung template) */ }
						<div>
							<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 } }>
								<Label style={ { marginBottom: 0 } }>TempData — Biến nội dung template</Label>
								<button onClick={ addTestVar } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#6366f1', fontSize: 12, display: 'flex', alignItems: 'center', gap: 3 } }>
									<Plus size={ 12 } /> Thêm biến
								</button>
							</div>
						<p style={ { fontSize: 11, color: '#94a3b8', marginBottom: 6 } }>
							Ví dụ: template có <code>{'{{ten_khach_hang}}'}</code> → nhập <strong>ten_khach_hang</strong> | <strong>Chu Hoàng Anh</strong>. Tên biến lấy từ eSMS dashboard → ZNS Templates → xem danh sách biến.
						</p>
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 4, paddingLeft: 0 } }>
							<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Tên biến trong template</span>
							<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Giá trị gửi đi</span>
							<span />
						</div>
						{ testVars.map( ( tv, i ) => (
							<div key={ i } style={ { display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 6, alignItems: 'center' } }>
								<Input
									type="text"
									placeholder="ten_khach_hang"
									value={ tv.key }
									onChange={ ( e ) => updateTestVar( i, 'key', e.target.value ) }
								/>
								<Input
									type="text"
									placeholder="Chu Hoàng Anh (giá trị thực tế)"
									value={ tv.value }
									onChange={ ( e ) => updateTestVar( i, 'value', e.target.value ) }
									/>
									{ testVars.length > 1 && (
										<button onClick={ () => removeTestVar( i ) } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 } }>
											<Trash2 size={ 13 } />
										</button>
									) }
								</div>
							) ) }
						</div>

						<Button
							variant="primary"
							onClick={ handleTestSend }
							disabled={ ! testPhone.trim() || ! testTempId.trim() || testSending }
						>
							{ testSending ? 'Đang gửi...' : '📤 Gửi thử ZNS (Sandbox)' }
						</Button>
					</div>

					{ /* Result */ }
					{ testResult && (
						<div style={ {
							marginTop: 16, padding: '12px 16px', borderRadius: 8,
							background: testResult.ok ? '#f0fdf4' : '#fef2f2',
							border: '1px solid ' + ( testResult.ok ? '#86efac' : '#fca5a5' ),
						} }>
							{ testResult.ok ? (
								<>
									<div style={ { display: 'flex', alignItems: 'center', gap: 6, color: '#16a34a', fontWeight: 700, fontSize: 13 } }>
										<CheckCircle2 size={ 14 } /> Gửi thành công!
									</div>
									<div style={ { marginTop: 8, fontSize: 12, color: '#166534', display: 'flex', flexDirection: 'column', gap: 4 } }>
										<span>📧 Phone: <strong>{ testResult.data?.phone }</strong></span>
										<span>📋 TempID: <strong>{ testResult.data?.temp_id }</strong></span>
										<span>🆔 SMSID: <strong>{ testResult.data?.sms_id || '(chờ eSMS xác nhận)' }</strong></span>
										<span>🔢 Code: <strong>{ testResult.data?.code }</strong></span>
										<span style={ { color: '#64748b', fontSize: 11 } }>✅ Sandbox=1 — không tính phí thật</span>
									</div>
									{ testResult.data?.temp_data && Object.keys( testResult.data.temp_data ).length > 0 && (
										<div style={ { marginTop: 8, fontSize: 11, color: '#64748b' } }>
											<strong>TempData đã gửi:</strong>
											<pre style={ { background: '#fff', padding: '6px 8px', borderRadius: 4, marginTop: 4, fontSize: 11, overflow: 'auto' } }>
												{ JSON.stringify( testResult.data.temp_data, null, 2 ) }
											</pre>
										</div>
									) }
								</>
							) : (
								<div style={ { color: '#dc2626', fontSize: 13 } }>
									<div style={ { display: 'flex', gap: 6, alignItems: 'flex-start', fontWeight: 700 } }>
										<XCircle size={ 14 } style={ { marginTop: 2, flexShrink: 0 } } />
										<div>
											<strong>Gửi thất bại</strong>
											{ testResult.data?.code === '799' && (
												<div style={ { marginTop: 4, fontWeight: 400, fontSize: 12, color: '#78350f', background: '#fffbeb', padding: '6px 10px', borderRadius: 6, border: '1px solid #fde68a' } }>
													<strong>Lỗi 799 — OAID is not config:</strong> OA ID chưa được liên kết với ApiKey trong tài khoản eSMS. Đăng nhập <a href="https://esms.vn" target="_blank" rel="noreferrer" style={ { color: '#92400e' } }>eSMS dashboard</a> → Dịch vụ ZNS → Liên kết Zalo OA Account với tài khoản eSMS, sau đó kiểm tra OA ID đã nhập đúng chưa.
												</div>
											) }
										</div>
									</div>
									<div style={ { fontSize: 12, marginTop: 6 } }>{ testResult.error || testResult.data?.error || 'Lỗi không xác định' }</div>
									{ testResult.data?.code && <div style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>eSMS code: { testResult.data.code }</div> }
								</div>
							) }
						</div>
					) }
				</div>
			) }
		</div>
	);
}
