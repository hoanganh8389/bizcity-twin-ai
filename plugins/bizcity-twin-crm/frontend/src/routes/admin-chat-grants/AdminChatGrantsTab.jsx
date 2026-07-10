import React, { useState } from 'react';
import { ShieldCheck, ShieldOff, Check, X, Pencil, Save, Hourglass, Clock, BarChart2, ClipboardList, Filter } from 'lucide-react';
import {
	useGetAdminChatGrantsQuery,
	useApproveAdminChatGrantMutation,
	useRevokeAdminChatGrantMutation,
	useUpdateAdminChatGrantMutation,
	useGetReportsAgentQuery,
	useGetAdminChatAuditQuery,
} from '../../redux/api/crmApi.js';
// [2026-06-07 Johnny Chu] PHASE-0.40 G7.3 — Nhân viên: agent KPI panel
// [2026-06-13 Johnny Chu] PHASE-3.5-WC — Audit log viewer panel

const STATUS_TABS = [
	{ key: '',        label: 'Tất cả' },
	{ key: 'pending', label: 'Chờ duyệt' },
	{ key: 'active',  label: 'Đang hiệu lực' },
	{ key: 'revoked', label: 'Đã thu hồi' },
];

function StatusBadge( { status } ) {
	const map = {
		active:  { bg: '#dcfce7', fg: '#15803d', icon: <Check size={ 11 } />, label: 'Active' },
		pending: { bg: '#fef9c3', fg: '#a16207', icon: <Hourglass size={ 11 } />, label: 'Pending' },
		revoked: { bg: '#fee2e2', fg: '#b91c1c', icon: <X size={ 11 } />, label: 'Revoked' },
	};
	const m = map[ status ] || { bg: '#f1f5f9', fg: '#475569', label: status };
	return (
		<span style={ { display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 8px', borderRadius: 10, fontSize: 11, fontWeight: 600, background: m.bg, color: m.fg } }>
			{ m.icon } { m.label }
		</span>
	);
}

function PrdPill( { label, on, title } ) {
	return (
		<span
			title={ title }
			style={ {
				display: 'inline-block', width: 24, height: 24, lineHeight: '24px', textAlign: 'center',
				borderRadius: '50%', fontSize: 11, fontWeight: 700, marginRight: 4,
				background: on ? '#22c55e' : '#cbd5e1', color: on ? '#fff' : '#475569',
			} }
		>{ label }</span>
	);
}

function EditRow( { grant, onClose } ) {
	const [ p, setP ] = useState( !! grant.allow_producer );
	const [ r, setR ] = useState( !! grant.allow_retriever );
	const [ d, setD ] = useState( !! grant.allow_distributor );
	const [ quota, setQuota ] = useState( grant.quota_per_day );
	const [ overrides, setOverrides ] = useState(
		grant.tool_overrides ? JSON.stringify( grant.tool_overrides, null, 2 ) : ''
	);
	const [ err, setErr ] = useState( '' );
	const [ update, { isLoading } ] = useUpdateAdminChatGrantMutation();

	const submit = async ( e ) => {
		e.preventDefault();
		let parsed = null;
		if ( overrides.trim() !== '' ) {
			try { parsed = JSON.parse( overrides ); } catch ( e2 ) { setErr( 'JSON không hợp lệ.' ); return; }
		}
		setErr( '' );
		try {
			await update( {
				id: grant.id,
				allow_producer: p ? 1 : 0,
				allow_retriever: r ? 1 : 0,
				allow_distributor: d ? 1 : 0,
				quota_per_day: parseInt( quota, 10 ) || 0,
				tool_overrides_json: parsed,
			} ).unwrap();
			onClose();
		} catch ( e3 ) { setErr( 'Lưu thất bại.' ); }
	};

	return (
		<form onSubmit={ submit } style={ { background: '#f8fafc', padding: 16, borderRadius: 6 } }>
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 16, marginBottom: 12 } }>
				<label><input type="checkbox" checked={ p } onChange={ ( e ) => setP( e.target.checked ) } /> <strong>Producer</strong> <span className="bzc-muted">(safe)</span></label>
				<label><input type="checkbox" checked={ r } onChange={ ( e ) => setR( e.target.checked ) } /> <strong>Retriever</strong> <span className="bzc-muted">(costs $)</span></label>
				<label><input type="checkbox" checked={ d } onChange={ ( e ) => setD( e.target.checked ) } /> <strong style={ { color: '#b91c1c' } }>Distributor ⚠</strong> <span className="bzc-muted">(irreversible)</span></label>
			</div>
			<div style={ { marginBottom: 12 } }>
				<label className="bzc-label">Quota / ngày (Retriever)</label>
				<input className="bzc-input bzc-input-sm" type="number" min="0" value={ quota } onChange={ ( e ) => setQuota( e.target.value ) } style={ { width: 120 } } />
			</div>
			<div style={ { marginBottom: 12 } }>
				<label className="bzc-label">Tool overrides (JSON, verb: allow|confirm|deny)</label>
				<textarea
					className="bzc-input"
					rows={ 5 }
					value={ overrides }
					onChange={ ( e ) => setOverrides( e.target.value ) }
					placeholder={ '{"post_facebook":"deny","gen_image":"allow"}' }
					style={ { fontFamily: 'monospace', fontSize: 12 } }
				/>
			</div>
			{ err && <div style={ { color: '#b91c1c', marginBottom: 8 } }>{ err }</div> }
			<div style={ { display: 'flex', gap: 8 } }>
				<button type="submit" className="bzc-btn-primary bzc-btn-sm" disabled={ isLoading }><Save size={ 12 } /> Lưu</button>
				<button type="button" className="bzc-btn-ghost bzc-btn-sm" onClick={ onClose }>Huỷ</button>
			</div>
		</form>
	);
}

export default function AdminChatGrantsTab() {
	// [2026-06-13 Johnny Chu] PHASE-3.5-WC — top-level tab: Grants vs Audit Log
	const [ mainTab, setMainTab ] = useState( 'grants' );
	const [ status, setStatus ] = useState( '' );
	const [ editingId, setEditingId ] = useState( 0 );
	const { data, isFetching, refetch } = useGetAdminChatGrantsQuery( { status: status || undefined, limit: 200 } );
	const [ approve ] = useApproveAdminChatGrantMutation();
	const [ revoke  ] = useRevokeAdminChatGrantMutation();

	const rows   = ( data && data.rows )   || [];
	const counts = ( data && data.counts ) || { pending: 0, active: 0, revoked: 0 };

	const onApprove = async ( id ) => { try { await approve( { id } ).unwrap(); } catch ( e ) {} refetch(); };
	const onRevoke  = async ( id ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Thu hồi grant này?' ) ) { return; }
		try { await revoke( { id } ).unwrap(); } catch ( e ) {}
		refetch();
	};

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<h2><ShieldCheck size={ 18 } style={ { verticalAlign: 'middle', marginRight: 6 } } /> Admin Chat Grants</h2>
				<span className="bzc-muted">
					Phân quyền 3 trục (User × Guru × Channel) cho truy cập Twin Guru qua Zalo / FB / Telegram.
				</span>
			</header>
			{ mainTab === 'grants' && (
				<>
				{ /* [2026-06-13 Johnny Chu] PHASE-3.5-WC — main tab switcher */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '2px solid var(--bzc-border)', paddingLeft: 16 } }>
				{ [ { key: 'grants', label: 'Grants', icon: <ShieldCheck size={ 13 } /> }, { key: 'audit', label: 'Audit Log', icon: <ClipboardList size={ 13 } /> } ].map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						style={ {
							display: 'inline-flex', alignItems: 'center', gap: 5, padding: '8px 16px',
							border: 'none', background: 'none', cursor: 'pointer', fontSize: 13,
							fontWeight: mainTab === t.key ? 600 : 400,
							color: mainTab === t.key ? 'var(--bzc-text)' : 'var(--bzc-text-muted)',
							borderBottom: mainTab === t.key ? '2px solid var(--bzc-primary, #2563eb)' : '2px solid transparent',
							marginBottom: -2,
						} }
						onClick={ () => setMainTab( t.key ) }
					>{ t.icon } { t.label }</button>
				) ) }
			</div>
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 8, padding: '8px 16px', borderBottom: '1px solid var(--bzc-border)' } }>
				{ STATUS_TABS.map( ( t ) => {
					const n = t.key ? counts[ t.key ] : ( counts.pending + counts.active + counts.revoked );
					const active = status === t.key;
					return (
						<button
							key={ t.key || 'all' }
							type="button"
							className="bzc-link"
							style={ { fontWeight: active ? 600 : 400, color: active ? 'var(--bzc-text)' : 'var(--bzc-text-muted)' } }
							onClick={ () => setStatus( t.key ) }
						>
							{ t.label } <span className="bzc-muted">({ n })</span>
						</button>
					);
				} ) }
			</div>

			<div style={ { padding: 16 } }>
				{ isFetching && <div className="bzc-muted">Đang tải…</div> }
				{ ! isFetching && rows.length === 0 && (
					<div className="bzc-empty bzc-muted" style={ { padding: 32, textAlign: 'center' } }>
						Chưa có grant nào.
					</div>
				) }

				{ rows.length > 0 && (
					<table className="widefat striped" style={ { width: '100%', borderCollapse: 'collapse' } }>
						<thead>
							<tr style={ { textAlign: 'left', borderBottom: '1px solid var(--bzc-border)' } }>
								<th style={ { padding: 8 } }>WHO</th>
								<th style={ { padding: 8 } }>WHAT</th>
								<th style={ { padding: 8 } }>WHERE</th>
								<th style={ { padding: 8 } }>Status</th>
								<th style={ { padding: 8 } }>P / R / D</th>
								<th style={ { padding: 8 } }>Quota</th>
								<th style={ { padding: 8 } }>Granted</th>
								<th style={ { padding: 8 } }></th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( g ) => (
								<React.Fragment key={ g.id }>
									<tr style={ { borderBottom: '1px solid var(--bzc-border)' } }>
										<td style={ { padding: 8 } }>
											<strong>{ g.user_name || ( '#' + g.user_id ) }</strong>
											<div className="bzc-muted" style={ { fontSize: 11 } }>{ g.user_email }</div>
										</td>
										<td style={ { padding: 8 } }>
											<strong>{ g.character_name || '—' }</strong>
											<div className="bzc-muted" style={ { fontSize: 11 } }>char #{ g.character_id }</div>
										</td>
										<td style={ { padding: 8 } }>
											<code>{ g.platform }</code>
											<div className="bzc-muted" style={ { fontSize: 11 } }>{ ( g.chat_id || '' ).slice( 0, 24 ) }</div>
										</td>
										<td style={ { padding: 8 } }><StatusBadge status={ g.status } /></td>
										<td style={ { padding: 8 } }>
											<PrdPill label="P" on={ !! g.allow_producer } title="Producer" />
											<PrdPill label="R" on={ !! g.allow_retriever } title="Retriever" />
											<PrdPill label="D" on={ !! g.allow_distributor } title="Distributor" />
											{ g.tool_overrides && Object.keys( g.tool_overrides ).length > 0 && (
												<span className="bzc-muted" style={ { fontSize: 10 } }>+{ Object.keys( g.tool_overrides ).length }</span>
											) }
										</td>
										<td style={ { padding: 8 } }>
											{ g.quota_used_today } / { g.quota_per_day }
											{ g.quota_reset_at && (
												<div className="bzc-muted" style={ { fontSize: 10 } }>
													<Clock size={ 9 } /> { String( g.quota_reset_at ).slice( 0, 16 ) }
												</div>
											) }
										</td>
										<td style={ { padding: 8, fontSize: 11 } } className="bzc-muted">
											{ g.granted_at ? String( g.granted_at ).slice( 0, 16 ) : '—' }
										</td>
										<td style={ { padding: 8, whiteSpace: 'nowrap' } }>
											{ g.status === 'pending' && (
												<button type="button" className="bzc-btn-primary bzc-btn-sm" onClick={ () => onApprove( g.id ) }>
													<Check size={ 12 } /> Duyệt
												</button>
											) }
											{ g.status !== 'revoked' && (
												<>
													{ ' ' }
													<button type="button" className="bzc-btn-ghost bzc-btn-sm" onClick={ () => setEditingId( editingId === g.id ? 0 : g.id ) }>
														<Pencil size={ 12 } /> Sửa
													</button>
													{ ' ' }
													<button type="button" className="bzc-btn-ghost bzc-btn-sm bzc-danger" onClick={ () => onRevoke( g.id ) }>
														<ShieldOff size={ 12 } /> Revoke
													</button>
												</>
											) }
										</td>
									</tr>
									{ editingId === g.id && (
										<tr>
											<td colSpan={ 8 } style={ { padding: 8 } }>
												<EditRow grant={ g } onClose={ () => setEditingId( 0 ) } />
											</td>
										</tr>
									) }
								</React.Fragment>
							) ) }
						</tbody>
					</table>
				) }

				<div style={ { marginTop: 24, padding: 16, background: '#fef3c7', borderLeft: '4px solid #f59e0b', borderRadius: 4, fontSize: 13 } }>
					⚠️ <strong>Distributor (D)</strong> = post FB / send email / schedule outbound — <em>không reversible</em>.
					Bật toggle này = ủy quyền user gửi tin/đăng bài qua Guru. Cân nhắc kỹ.
				</div>
				{ /* [2026-06-07 Johnny Chu] PHASE-0.40 G7.3 — Nhân viên: agent KPI panel */ }
				<StaffKpiPanel />
					</div>
				</>
			) }
			{ mainTab === 'audit' && <AuditLogPanel /> }
		</div>
	);
}

function StaffKpiPanel() {
	// [2026-06-07 Johnny Chu] PHASE-0.40 G7.3 fix — correct 30-day window
	const today         = new Date().toISOString().slice( 0, 10 );
	const thirtyDaysAgo = new Date( Date.now() - 30 * 24 * 60 * 60 * 1000 ).toISOString().slice( 0, 10 );
	const { data: kpi, isFetching } = useGetReportsAgentQuery( { from: thirtyDaysAgo, to: today, days: 30 } );
	const agents = ( kpi && kpi.agents ) ? kpi.agents : [];

	return (
		<div style={ { marginTop: 28, padding: '12px 16px', background: '#f0fdf4', borderRadius: 8, border: '1px solid #bbf7d0' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 } }>
				<BarChart2 size={ 16 } color="#16a34a" />
				<span style={ { fontSize: 13, fontWeight: 600, color: '#15803d' } }>KPI Nhân viên (30 ngày)</span>
				{ isFetching && <span style={ { fontSize: 11, color: '#86efac' } }>Đang tải…</span> }
			</div>
			{ agents.length === 0 && ! isFetching && (
				<div style={ { fontSize: 12, color: '#6b7280' } }>Chưa có dữ liệu KPI. API /reports/agent trả về danh sách nhân viên và chỉ số hội thoại.</div>
			) }
			{ agents.length > 0 && (
				<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
					<thead>
						<tr style={ { background: '#dcfce7' } }>
							<th style={ { padding: '6px 10px', textAlign: 'left', color: '#166534' } }>Nhân viên</th>
							<th style={ { padding: '6px 10px', textAlign: 'right', color: '#166534' } }>Tin nhắn (30 ngày)</th>
							{ /* [2026-06-13 Johnny Chu] PHASE-0.40 G3 CRM-B04 — show real first_response_avg_min */ }
							<th style={ { padding: '6px 10px', textAlign: 'right', color: '#166534' } }>FRT trung bình</th>
						</tr>
					</thead>
					<tbody>
						{ agents.map( ( ag ) => (
							<tr key={ ag.id } style={ { borderBottom: '1px solid #bbf7d0' } }>
								<td style={ { padding: '5px 10px' } }>{ ag.name || ( 'Agent #' + ag.id ) }</td>
								<td style={ { padding: '5px 10px', textAlign: 'right' } }>{ ag.msg_count ?? 0 }</td>
								<td style={ { padding: '5px 10px', textAlign: 'right', color: ( ag.first_response_avg_min > 0 ) ? '#15803d' : '#94a3b8' } }>
									{ ( ag.first_response_avg_min > 0 ) ? `${ ag.first_response_avg_min } phút` : '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

/**
 * [2026-06-13 Johnny Chu] PHASE-3.5-WC — Audit Log Viewer Panel
 * Displays bizcity_crm_admin_chat_audit rows with filter by status/action/user.
 */
const AUDIT_STATUS_OPTS = [
	{ value: '',                label: 'Mọi trạng thái' },
	{ value: 'success',         label: '✅ Success' },
	{ value: 'attempted',       label: '⏳ Attempted' },
	{ value: 'denied',          label: '🚫 Denied' },
	{ value: 'confirm_pending', label: '🔔 Confirm pending' },
	{ value: 'confirm_expired', label: '⏰ Confirm expired' },
];

function AuditLogPanel() {
	const [ filterStatus, setFilterStatus ] = useState( '' );
	const [ filterAction, setFilterAction ] = useState( '' );
	const [ page, setPage ] = useState( 0 );
	const limit = 50;

	const { data, isFetching } = useGetAdminChatAuditQuery(
		{ status: filterStatus || undefined, action: filterAction || undefined, limit, offset: page * limit },
		{ pollingInterval: 30000 }
	);

	const rows  = ( data && data.rows  ) ? data.rows  : [];
	const total = ( data && data.total ) ? data.total : 0;

	return (
		<div style={ { padding: 16 } }>
			{ /* Filter bar */ }
			<div style={ { display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' } }>
				<Filter size={ 14 } color="#64748b" />
				<select
					className="bzc-input bzc-input-sm"
					style={ { fontSize: 12, padding: '4px 8px' } }
					value={ filterStatus }
					onChange={ ( e ) => { setFilterStatus( e.target.value ); setPage( 0 ); } }
				>
					{ AUDIT_STATUS_OPTS.map( ( o ) => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
				</select>
				<input
					className="bzc-input bzc-input-sm"
					style={ { fontSize: 12, padding: '4px 8px', width: 160 } }
					placeholder="Lọc theo action..."
					value={ filterAction }
					onChange={ ( e ) => { setFilterAction( e.target.value.trim() ); setPage( 0 ); } }
				/>
				{ isFetching && <span style={ { fontSize: 11, color: '#94a3b8' } }>Đang tải…</span> }
				<span style={ { fontSize: 11, color: '#94a3b8', marginLeft: 'auto' } }>{ total } bản ghi</span>
			</div>

			{ rows.length === 0 && ! isFetching && (
				<div style={ { padding: 32, textAlign: 'center', color: '#94a3b8', fontSize: 13 } }>
					Chưa có audit log nào. Audit log được ghi khi Guru thực thi skill qua Admin Chat Grant.
				</div>
			) }

			{ rows.length > 0 && (
				<table className="widefat striped" style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
					<thead>
						<tr style={ { background: '#f1f5f9', borderBottom: '1px solid var(--bzc-border)' } }>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Thời gian</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>User</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Guru</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Action</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Status</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Chat ID</th>
							<th style={ { padding: '6px 10px', textAlign: 'left' } }>Input</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => {
							const statusColors = {
								success:         { bg: '#dcfce7', fg: '#15803d' },
								attempted:       { bg: '#fef9c3', fg: '#a16207' },
								denied:          { bg: '#fee2e2', fg: '#b91c1c' },
								confirm_pending: { bg: '#ede9fe', fg: '#7c3aed' },
								confirm_expired: { bg: '#f1f5f9', fg: '#475569' },
							};
							const sc = statusColors[ row.status ] || { bg: '#f1f5f9', fg: '#475569' };
							let inputSummary = '—';
							if ( row.input_json ) {
								try {
									const inp = typeof row.input_json === 'string' ? JSON.parse( row.input_json ) : row.input_json;
									inputSummary = Object.keys( inp ).slice( 0, 2 ).map( ( k ) => k + '=' + String( inp[ k ] ).slice( 0, 20 ) ).join( ', ' );
								} catch ( _e ) { inputSummary = String( row.input_json ).slice( 0, 40 ); }
							}
							return (
								<tr key={ row.id } style={ { borderBottom: '1px solid var(--bzc-border)' } }>
									<td style={ { padding: '5px 10px', whiteSpace: 'nowrap', color: '#475569' } }>
										{ String( row.created_at || '' ).slice( 0, 16 ) }
									</td>
									<td style={ { padding: '5px 10px' } }>
										{ row.user_id ? `#${ row.user_id }` : '—' }
									</td>
									<td style={ { padding: '5px 10px', color: '#475569' } }>
										{ row.guru_id ? `Guru #${ row.guru_id }` : '—' }
									</td>
									<td style={ { padding: '5px 10px' } }>
										<code style={ { fontSize: 11, background: '#f1f5f9', padding: '1px 5px', borderRadius: 3 } }>
											{ row.action || '—' }
										</code>
									</td>
									<td style={ { padding: '5px 10px' } }>
										<span style={ { display: 'inline-block', padding: '2px 8px', borderRadius: 10, fontSize: 10, fontWeight: 600, background: sc.bg, color: sc.fg } }>
											{ row.status }
										</span>
									</td>
									<td style={ { padding: '5px 10px', maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: '#475569', fontSize: 11 } }>
										{ ( row.chat_id || '' ).slice( 0, 30 ) }
									</td>
									<td style={ { padding: '5px 10px', color: '#475569', fontSize: 11, maxWidth: 200, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>
										{ inputSummary }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ /* Pagination */ }
			{ total > limit && (
				<div style={ { display: 'flex', gap: 8, justifyContent: 'center', marginTop: 12 } }>
					<button type="button" className="bzc-btn-ghost bzc-btn-sm" disabled={ page === 0 } onClick={ () => setPage( page - 1 ) }>← Trang trước</button>
					<span style={ { fontSize: 12, color: '#64748b', alignSelf: 'center' } }>
						{ page * limit + 1 }–{ Math.min( ( page + 1 ) * limit, total ) } / { total }
					</span>
					<button type="button" className="bzc-btn-ghost bzc-btn-sm" disabled={ ( page + 1 ) * limit >= total } onClick={ () => setPage( page + 1 ) }>Trang sau →</button>
				</div>
			) }
		</div>
	);
}
