/**
 * CF7AutomationTab.jsx
 *
 * [2026-06-24 Johnny Chu] PHASE-CF7-AUTO
 *
 * UI component for "CF7 Automation" — sub-section of EmailTab.
 * Displays CF7-specific email automation rules (reply_type: template | ai_reply)
 * and a statistics dashboard.
 *
 * Sub-tabs: 'rules' | 'stats'
 */
import React, { useState, useMemo, useRef, useCallback } from 'react';
import { Bot, LayoutGrid, Plus, Pencil, Trash2, FlaskConical, Zap, BarChart2, CheckCircle2, AlertTriangle, RefreshCw, FileText, Archive, FileCode, Download } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import { Badge } from '../../components/ui/card.jsx';
import {
	useGetEmailEventsQuery,
	useGetEmailEventRulesQuery,
	useGetGmailSmtpAccountsQuery,
	useCreateEmailEventRuleMutation,
	useUpdateEmailEventRuleMutation,
	useDeleteEmailEventRuleMutation,
	useTestEmailEventRuleMutation,
	useGetCf7AutomationStatsQuery,
} from '../../redux/api/crmApi.js';

// ── Stat card ────────────────────────────────────────────────────────────────
function StatCard( { label, value, icon: Icon, color } ) {
	return (
		<div style={ {
			background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10,
			padding: '14px 18px', display: 'flex', alignItems: 'center', gap: 12,
			minWidth: 140, flex: 1,
		} }>
			<div style={ {
				width: 36, height: 36, borderRadius: 8, background: color || '#eef2ff',
				display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
			} }>
				{ Icon && <Icon size={ 16 } style={ { color: '#6366f1' } } /> }
			</div>
			<div>
				<div style={ { fontSize: 22, fontWeight: 700, color: '#1e293b', lineHeight: 1.1 } }>{ value ?? '—' }</div>
				<div style={ { fontSize: 11, color: '#94a3b8', marginTop: 2 } }>{ label }</div>
			</div>
		</div>
	);
}

// ── Reply type badge ─────────────────────────────────────────────────────────
function ReplyTypeBadge( { type } ) {
	if ( type === 'ai_reply' ) {
		return (
			<span style={ {
				display: 'inline-flex', alignItems: 'center', gap: 3,
				background: '#f0fdf4', border: '1px solid #86efac',
				color: '#16a34a', fontSize: 11, padding: '1px 7px', borderRadius: 20, fontWeight: 600,
			} }>
				<Bot size={ 10 } /> AI
			</span>
		);
	}
	return (
		<span style={ {
			display: 'inline-flex', alignItems: 'center', gap: 3,
			background: '#eef2ff', border: '1px solid #c7d2fe',
			color: '#4338ca', fontSize: 11, padding: '1px 7px', borderRadius: 20, fontWeight: 600,
		} }>
			Template
		</span>
	);
}

// ── Rule form (CF7-specific) ─────────────────────────────────────────────────
// ── Detect file type from URL → icon + label ────────────────────────────────
function getFileTypeMeta( url ) {
	if ( ! url ) { return null; }
	const ext = ( url.split( '?' )[ 0 ].split( '.' ).pop() || '' ).toLowerCase();
	const map = {
		pdf:  { icon: FileText,  color: '#dc2626', bg: '#fef2f2', label: 'PDF',   verb: 'Tải về PDF'      },
		zip:  { icon: Archive,   color: '#d97706', bg: '#fffbeb', label: 'ZIP',   verb: 'Tải về file ZIP' },
		rar:  { icon: Archive,   color: '#d97706', bg: '#fffbeb', label: 'RAR',   verb: 'Tải về file RAR' },
		doc:  { icon: FileText,  color: '#2563eb', bg: '#eff6ff', label: 'DOC',   verb: 'Tải về Word'     },
		docx: { icon: FileText,  color: '#2563eb', bg: '#eff6ff', label: 'DOCX',  verb: 'Tải về Word'     },
		xls:  { icon: FileCode,  color: '#16a34a', bg: '#f0fdf4', label: 'XLS',   verb: 'Tải về Excel'    },
		xlsx: { icon: FileCode,  color: '#16a34a', bg: '#f0fdf4', label: 'XLSX',  verb: 'Tải về Excel'    },
		mp4:  { icon: Download,  color: '#7c3aed', bg: '#f5f3ff', label: 'MP4',   verb: 'Tải về video'    },
		mp3:  { icon: Download,  color: '#7c3aed', bg: '#f5f3ff', label: 'MP3',   verb: 'Tải về audio'    },
	};
	return map[ ext ] || { icon: Download, color: '#64748b', bg: '#f8fafc', label: ext.toUpperCase() || 'FILE', verb: 'Tải về file' };
}

// ── Rule form (CF7-specific — simplified) ───────────────────────────────────
// [2026-06-24 Johnny Chu] HOTFIX — simplified CF7 rule form: just thank-you text + attachment link
function CF7RuleForm( { initial, accounts, cf7Events, onSubmit, onCancel, busy } ) {
	const safeCf7Events = Array.isArray( cf7Events ) ? cf7Events : [];
	const safeAccounts  = Array.isArray( accounts )  ? accounts  : [];

	// [2026-06-24 Johnny Chu] HOTFIX — preserve newlines; only strip HTML tags (not \n)
	const extractPlainText = ( html ) => {
		if ( ! html ) { return ''; }
		return html
			.replace( /<\/p>/gi, '\n' )          // closing </p> → newline
			.replace( /<br\s*\/?>/gi, '\n' )      // <br> → newline
			.replace( /<[^>]+>/g, '' )            // strip remaining tags
			.replace( /&nbsp;/g, ' ' )
			.replace( /&amp;/g, '&' )
			.replace( /&lt;/g, '<' )
			.replace( /&gt;/g, '>' )
			.replace( /\n{3,}/g, '\n\n' )         // max 2 consecutive blank lines
			.trim();
	};

	const [ name,        setName        ] = useState( initial?.name        ?? '' );
	const [ eventKey,    setEventKey    ] = useState( initial?.event_key   ?? ( safeCf7Events[ 0 ]?.key || 'cf7_form_submitted' ) );
	// [2026-06-24 Johnny Chu] HOTFIX — keep original subject_template, don't overwrite with generic default
	const [ subject,     setSubject     ] = useState( initial?.subject_template ?? 'Cảm ơn bạn đã gửi form — {{site_name}}' );
	// [2026-06-24 Johnny Chu] HOTFIX — use cf7_notice (dedicated plain-text field) as source;
	// fall back to extractPlainText(body_template) for older records that don't have cf7_notice yet
	const [ thankYou,    setThankYou    ] = useState(
		initial?.cf7_notice
			? initial.cf7_notice
			: ( extractPlainText( initial?.body_template ) || 'Cảm ơn {{name}}, chúng tôi đã nhận được thông tin của bạn và sẽ liên hệ sớm nhất có thể.' )
	);
	const [ attachUrl,   setAttachUrl   ] = useState( initial?.attachment_url ?? '' );
	const [ accountId,   setAccountId   ] = useState( initial?.account_id  ?? '' );
	const [ isEnabled,   setIsEnabled   ] = useState( initial?.is_enabled  ?? 1 );

	const fileMeta = getFileTypeMeta( attachUrl );
	const FileIcon = fileMeta?.icon || Download;

	// Build full body_template HTML from plain text + optional download button
	const buildBody = () => {
		const paragraphs = thankYou.split( '\n' ).filter( Boolean ).map( ( l ) => `<p>${ l }</p>` ).join( '\n' );
		if ( ! attachUrl ) { return paragraphs; }
		const verb = fileMeta?.verb || 'Tải về file';
		const btn = `<p style="margin-top:16px">` +
			`<a href="${ attachUrl }" download style="display:inline-block;padding:10px 20px;background:#4f46e5;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px">` +
			`⬇ ${ verb }</a></p>`;
		return paragraphs + '\n' + btn;
	};

	const handleSubmit = ( e ) => {
		e.preventDefault();
		onSubmit( {
			name,
			event_key:        eventKey,
			account_id:       accountId ? Number( accountId ) : '',
			is_enabled:       isEnabled,
			reply_type:       'template',
			to_template:      '{{email}}',
			cc_template:      '',
			bcc_template:     '',
			subject_template: subject,
			// [2026-06-24 Johnny Chu] HOTFIX — save plain text to cf7_notice + build HTML body_template
			cf7_notice:       thankYou,
			body_template:    buildBody(),
			attachment_url:   attachUrl,
		} );
	};

	return (
		<form className="bzc-form" onSubmit={ handleSubmit }>

			{ /* Name */ }
			<div>
				<Label>Tên quy tắc *</Label>
				<Input required value={ name } onChange={ ( e ) => setName( e.target.value ) }
					placeholder="VD: Tự động gửi Ebook sau khi đăng ký" />
			</div>

			{ /* Form CF7 selector */ }
			<div>
				<Label>Form CF7 kích hoạt *</Label>
				<select className="bzc-input" required value={ eventKey }
					onChange={ ( e ) => setEventKey( e.target.value ) }>
					{ safeCf7Events.map( ( ev ) => (
						<option key={ ev.key } value={ ev.key }>{ ev.label }</option>
					) ) }
				</select>
			</div>

			{ /* [2026-06-24 Johnny Chu] HOTFIX — cf7_notice (Notice) moved above subject per UX request */ }
			{ /* Thank-you message / cf7_notice */ }
			<div>
				<Label>Nội dung email cảm ơn (cf7_notice)</Label>
				<Textarea rows={ 5 } value={ thankYou }
					onChange={ ( e ) => setThankYou( e.target.value ) }
					placeholder="Cảm ơn {{name}}, chúng tôi đã nhận được thông tin và sẽ liên hệ sớm." />
				<div style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>
					Dùng <code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{name}}`}</code>
					,{ ' ' }<code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{email}}`}</code>
					,{ ' ' }<code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{site_name}}`}</code>
					{ ' ' }để chèn dữ liệu từ form. Lưu riêng vào cột <code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>cf7_notice</code>.
				</div>
			</div>

			{ /* Subject */ }
			<div>
				<Label>Tiêu đề email *</Label>
				<Input required value={ subject } onChange={ ( e ) => setSubject( e.target.value ) }
					placeholder="Cảm ơn bạn đã gửi form — {{site_name}}" />
				<div style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>
					Dùng <code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{name}}`}</code>
					,{ ' ' }<code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{email}}`}</code>
					,{ ' ' }<code style={ { background: '#f1f5f9', padding: '0 3px', borderRadius: 3 } }>{`{{site_name}}`}</code>{ ' ' }để chèn dữ liệu.
				</div>
			</div>

			{ /* Attachment URL */ }
			<div>
				<Label>Link tải file đính kèm (tuỳ chọn)</Label>
				<div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
					<Input value={ attachUrl }
						onChange={ ( e ) => setAttachUrl( e.target.value ) }
						placeholder="https://example.com/ebook.pdf"
						style={ { flex: 1 } } />
					{ attachUrl && fileMeta && (
						<span style={ {
							display: 'inline-flex', alignItems: 'center', gap: 4,
							background: fileMeta.bg, color: fileMeta.color,
							border: `1px solid ${ fileMeta.color }33`,
							padding: '3px 10px', borderRadius: 6, fontSize: 12, fontWeight: 600,
							whiteSpace: 'nowrap', flexShrink: 0,
						} }>
							<FileIcon size={ 13 } />{ fileMeta.label }
						</span>
					) }
				</div>
				{ /* Preview download button */ }
				{ attachUrl && fileMeta && (
					<div style={ { marginTop: 8, padding: '10px 14px', background: '#f8fafc', border: '1px dashed #cbd5e1', borderRadius: 8, fontSize: 12 } }>
						<div style={ { color: '#64748b', marginBottom: 6, fontSize: 11 } }>Xem trước nút trong email:</div>
						<a href={ attachUrl } target="_blank" rel="noopener noreferrer"
							style={ {
								display: 'inline-block', padding: '8px 18px',
								background: '#4f46e5', color: '#fff',
								borderRadius: 6, textDecoration: 'none', fontWeight: 600, fontSize: 13,
							} }>
							⬇ { fileMeta.verb }
						</a>
					</div>
				) }
			</div>

			{ /* SMTP account */ }
			<div>
				<Label>Tài khoản gửi (Gmail SMTP)</Label>
				<select className="bzc-input" value={ accountId ?? '' }
					onChange={ ( e ) => setAccountId( e.target.value ) }>
					<option value="">— Dùng wp_mail() mặc định —</option>
					{ safeAccounts.map( ( a ) => (
						<option key={ a.id } value={ a.id }>{ a.label || a.from_email || a.smtp_user }</option>
					) ) }
				</select>
			</div>

			{ /* Enable toggle */ }
			<div>
				<label className="bzc-checkbox">
					<input type="checkbox" checked={ !! isEnabled }
						onChange={ ( e ) => setIsEnabled( e.target.checked ? 1 : 0 ) } />
					Kích hoạt quy tắc này
				</label>
			</div>

			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>
					{ busy ? 'Đang lưu…' : 'Lưu' }
				</Button>
			</div>
		</form>
	);
}

// ── Stats sub-tab ────────────────────────────────────────────────────────────
function CF7StatsPanel() {
	const [ days, setDays ] = useState( 30 );
	const { data, isFetching, refetch } = useGetCf7AutomationStatsQuery( days );

	const stats = data || {};
	const byForm = Array.isArray( stats.by_form ) ? stats.by_form : [];

	return (
		<div>
			{ /* Summary cards */ }
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 10, marginBottom: 16 } }>
				<StatCard label="Lần gửi form" value={ stats.total_submissions } icon={ LayoutGrid } color="#eef2ff" />
				<StatCard label="Email đã gửi" value={ stats.total_sent } icon={ CheckCircle2 } color="#f0fdf4" />
				<StatCard label="Gửi thất bại" value={ stats.total_failed } icon={ AlertTriangle } color="#fff7ed" />
				<StatCard label="Quy tắc Template" value={ stats.template_rules_count } icon={ Zap } color="#faf5ff" />
				<StatCard label="Quy tắc AI" value={ stats.ai_rules_count } icon={ Bot } color="#f0fdf4" />
			</div>


			{ /* Period selector + refresh */ }
			<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 } }>
				<Label style={ { marginBottom: 0, whiteSpace: 'nowrap' } }>Khoảng thời gian:</Label>
				<select className="bzc-input" value={ days }
					onChange={ ( e ) => setDays( Number( e.target.value ) ) }
					style={ { width: 130 } }>
					<option value={ 7 }>7 ngày</option>
					<option value={ 14 }>14 ngày</option>
					<option value={ 30 }>30 ngày</option>
					<option value={ 90 }>90 ngày</option>
				</select>
				<Button size="sm" onClick={ refetch } disabled={ isFetching }>
					<RefreshCw size={ 12 } className={ isFetching ? 'bzc-spin' : '' } />
					{ isFetching ? 'Đang tải…' : 'Làm mới' }
				</Button>
				{ stats._degraded && (
					<span style={ { fontSize: 11, color: '#f59e0b', marginLeft: 4 } }>
						⚠ Một số bảng chưa sẵn sàng, thống kê có thể chưa đầy đủ.
					</span>
				) }
			</div>

			{ /* Per-form table */ }
			{ byForm.length === 0 ? (
				<div className="bzc-empty bzc-muted" style={ { marginTop: 12 } }>
					Chưa có dữ liệu submission trong khoảng thời gian này.
				</div>
			) : (
				<div style={ { overflowX: 'auto' } }>
					<table className="bzc-table" style={ { minWidth: 500 } }>
						<thead>
							<tr>
								<th>Form</th>
								<th style={ { textAlign: 'right' } }>Submissions</th>
								<th style={ { textAlign: 'right' } }>Email gửi</th>
								<th style={ { textAlign: 'right' } }>Thất bại</th>
								<th style={ { textAlign: 'right' } }>Tỷ lệ gửi</th>
							</tr>
						</thead>
						<tbody>
							{ byForm.map( ( row ) => {
								const rate = row.submissions > 0
									? Math.round( ( row.sent / row.submissions ) * 100 )
									: 0;
								return (
									<tr key={ row.form_id }>
										<td>
											<div style={ { fontWeight: 600, fontSize: 13 } }>{ row.form_title || `Form #${ row.form_id }` }</div>
											<div style={ { fontSize: 11, color: '#94a3b8' } }>ID: { row.form_id }</div>
										</td>
										<td style={ { textAlign: 'right', fontVariantNumeric: 'tabular-nums' } }>{ row.submissions }</td>
										<td style={ { textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: '#16a34a', fontWeight: 600 } }>{ row.sent }</td>
										<td style={ { textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: row.failed > 0 ? '#dc2626' : '#94a3b8' } }>{ row.failed }</td>
										<td style={ { textAlign: 'right', fontVariantNumeric: 'tabular-nums' } }>
											<span style={ {
												background: rate >= 80 ? '#f0fdf4' : rate >= 50 ? '#fff7ed' : '#fef2f2',
												color: rate >= 80 ? '#16a34a' : rate >= 50 ? '#d97706' : '#dc2626',
												padding: '1px 7px', borderRadius: 12, fontSize: 12, fontWeight: 600,
											} }>{ rate }%</span>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

// ── Main component ───────────────────────────────────────────────────────────
export default function CF7AutomationTab() {
	const [ subTab, setSubTab ] = useState( 'rules' ); // 'rules' | 'stats'
	const [ ruleSheet, setRuleSheet ] = useState( false );
	const [ editingRule, setEditingRule ] = useState( null );
	const [ testModal, setTestModal ] = useState( null ); // { rule, testTo, result, busy }

	// Queries
	const { data: allEvents = [] } = useGetEmailEventsQuery();
	const { data: allRules = [], isLoading: rulesLoading } = useGetEmailEventRulesQuery( '' );
	const { data: accounts = [] } = useGetGmailSmtpAccountsQuery();

	// Mutations
	const [ createRule, { isLoading: cR } ] = useCreateEmailEventRuleMutation();
	const [ updateRule, { isLoading: uR } ] = useUpdateEmailEventRuleMutation();
	const [ deleteRule ] = useDeleteEmailEventRuleMutation();
	const [ testRuleMut ] = useTestEmailEventRuleMutation();

	// Filter: only CF7 events + only CF7 rules
	const cf7Events = useMemo(
		() => ( Array.isArray( allEvents ) ? allEvents : [] ).filter(
			( e ) => e.key && ( e.key === 'cf7_form_submitted' || e.key.indexOf( 'cf7_form_' ) === 0 )
		),
		[ allEvents ]
	);

	const cf7Rules = useMemo(
		() => ( Array.isArray( allRules ) ? allRules : [] ).filter(
			( r ) => r.event_key && ( r.event_key === 'cf7_form_submitted' || r.event_key.indexOf( 'cf7_form_' ) === 0 )
		),
		[ allRules ]
	);

	const handleOpenAdd = () => {
		setEditingRule( null );
		setRuleSheet( true );
	};

	const handleEdit = ( rule ) => {
		setEditingRule( rule );
		setRuleSheet( true );
	};

	const handleDelete = async ( id ) => {
		if ( ! window.confirm( 'Xoá quy tắc CF7 này?' ) ) { return; }
		await deleteRule( id );
	};

	const handleToggleEnabled = async ( rule ) => {
		await updateRule( { id: rule.id, body: { is_enabled: rule.is_enabled ? 0 : 1 } } );
	};

	// [2026-06-24 Johnny Chu] HOTFIX — mutation expects { id, ...fields } not { id, body: fields }
	const handleSaveRule = async ( d ) => {
		if ( editingRule ) {
			await updateRule( { id: editingRule.id, ...d } );
		} else {
			await createRule( d );
		}
		setRuleSheet( false );
		setEditingRule( null );
	};

	const handleTestOpen = ( rule ) => {
		setTestModal( { rule, testTo: '', result: null, busy: false } );
	};

	const handleTestRun = async () => {
		if ( ! testModal ) { return; }
		const to = testModal.testTo.trim();
		if ( ! to ) { return; }
		setTestModal( ( p ) => ( { ...p, busy: true } ) );
		try {
			const res = await testRuleMut( { id: testModal.rule.id, test_to: to } ).unwrap();
			setTestModal( ( p ) => ( { ...p, busy: false, result: res } ) );
		} catch ( err ) {
			setTestModal( ( p ) => ( { ...p, busy: false, result: { ok: false, error: err } } ) );
		}
	};

	// Sub-tab bar
	const TABS = [
		{ id: 'rules', label: 'Quy tắc CF7' },
		{ id: 'stats', label: '📊 Thống kê' },
	];

	return (
		<div>
			{ /* Sub-tab bar */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '1px solid #e2e8f0', marginBottom: 16 } }>
				{ TABS.map( ( t ) => (
					<button key={ t.id } type="button" onClick={ () => setSubTab( t.id ) }
						style={ {
							padding: '7px 16px', fontSize: 13, cursor: 'pointer',
							background: 'none', border: 'none',
							borderBottom: subTab === t.id ? '2px solid #6366f1' : '2px solid transparent',
							color: subTab === t.id ? '#4338ca' : '#64748b',
							fontWeight: subTab === t.id ? 700 : 400,
							marginBottom: -1,
						} }>
						{ t.label }
					</button>
				) ) }
			</div>

			{ /* Stats sub-tab */ }
			{ subTab === 'stats' && <CF7StatsPanel /> }

			{ /* Rules sub-tab */ }
			{ subTab === 'rules' && (
				<section className="bzc-card" style={ { padding: 14 } }>
					<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 } }>
						<div>
							<h4 style={ { margin: 0, fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 } }>
								<Bot size={ 14 } style={ { color: '#6366f1' } } /> Quy tắc tự động trả lời CF7
							</h4>
							<p style={ { margin: '3px 0 0', fontSize: 12, color: '#94a3b8' } }>
								Mỗi quy tắc gắn với 1 form CF7. Khi form được submit → gửi email trả lời theo template hoặc dùng AI soạn nội dung.
							</p>
						</div>
						<Button size="sm" variant="primary" onClick={ handleOpenAdd }
							style={ { display: 'flex', alignItems: 'center', gap: 4 } }>
							<Plus size={ 12 } /> Thêm quy tắc
						</Button>
					</div>

					{ rulesLoading && <div className="bzc-muted">Đang tải…</div> }
					{ ! rulesLoading && cf7Rules.length === 0 && (
						<div className="bzc-empty bzc-muted">Chưa có quy tắc CF7. Nhấn <strong>Thêm quy tắc</strong> để bắt đầu.</div>
					) }

					{ cf7Rules.length > 0 && (
						<div style={ { overflowX: 'auto' } }>
							<table className="bzc-table">
								<thead>
									<tr>
										<th>Tên quy tắc</th>
										<th>Form kích hoạt</th>
										<th>Loại trả lời</th>
										<th>Trạng thái</th>
										<th style={ { width: 120 } }>Thao tác</th>
									</tr>
								</thead>
								<tbody>
									{ cf7Rules.map( ( rule ) => (
										<tr key={ rule.id }>
											<td>
												<div style={ { fontWeight: 600, fontSize: 13 } }>{ rule.name }</div>
												<div style={ { fontSize: 11, color: '#94a3b8' } }>{ rule.to_template }</div>
											</td>
											<td>
												<code style={ { fontSize: 11, background: '#f1f5f9', padding: '2px 6px', borderRadius: 4 } }>
													{ rule.event_key }
												</code>
											</td>
											<td><ReplyTypeBadge type={ rule.reply_type || 'template' } /></td>
											<td>
												<button type="button"
													onClick={ () => handleToggleEnabled( rule ) }
													style={ {
														padding: '2px 10px', borderRadius: 20, fontSize: 11,
														border: 'none', cursor: 'pointer', fontWeight: 600,
														background: rule.is_enabled ? '#f0fdf4' : '#f8fafc',
														color: rule.is_enabled ? '#16a34a' : '#94a3b8',
													} }>
													{ rule.is_enabled ? '✓ Bật' : 'Tắt' }
												</button>
											</td>
											<td>
												<div style={ { display: 'flex', gap: 4 } }>
													<Button size="sm" title="Sửa" onClick={ () => handleEdit( rule ) }>
														<Pencil size={ 11 } />
													</Button>
													<Button size="sm" title="Test gửi" onClick={ () => handleTestOpen( rule ) }>
														<FlaskConical size={ 11 } />
													</Button>
													<Button size="sm" title="Xoá" onClick={ () => handleDelete( rule.id ) }>
														<Trash2 size={ 11 } />
													</Button>
												</div>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</section>
			) }

			{ /* Rule edit/create sheet */ }
			<Sheet open={ ruleSheet } onOpenChange={ setRuleSheet }
				onInteractOutside={ ( e ) => {
					if ( window.__bzMediaPickerOpen ) { e.preventDefault(); }
				} }>
				<SheetContent>
					<SheetHeader>
						<SheetTitle>{ editingRule ? 'Sửa quy tắc CF7' : 'Thêm quy tắc CF7' }</SheetTitle>
					</SheetHeader>
					<SheetBody>
						<CF7RuleForm
							initial={ editingRule }
							accounts={ accounts }
							cf7Events={ cf7Events }
							onSubmit={ handleSaveRule }
							onCancel={ () => { setRuleSheet( false ); setEditingRule( null ); } }
							busy={ cR || uR }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* Test rule modal */ }
			{ testModal && (
				<div style={ {
					position: 'fixed', inset: 0, zIndex: 9000,
					background: 'rgba(15,23,42,0.45)',
					display: 'flex', alignItems: 'center', justifyContent: 'center',
				} }
					onClick={ ( e ) => { if ( e.target === e.currentTarget ) { setTestModal( null ); } } }>
					<div style={ {
						background: '#fff', borderRadius: 12,
						boxShadow: '0 20px 60px rgba(0,0,0,0.22)',
						padding: '28px 32px', width: '100%', maxWidth: 440,
					} }>
						<h3 style={ { margin: '0 0 6px', fontSize: 16 } }>🧪 Test gửi email CF7</h3>
						<p style={ { fontSize: 13, color: '#64748b', margin: '0 0 4px' } }>
							<strong>{ testModal.rule.name }</strong>
						</p>
						<p style={ { fontSize: 12, color: '#94a3b8', margin: '0 0 18px' } }>
							Server sẽ render template (hoặc gọi AI) với dữ liệu mẫu và gửi thật đến email bên dưới.
						</p>

						{ ! testModal.result ? (
							<>
								<Label>Địa chỉ nhận *</Label>
								<Input
									type="email"
									autoFocus
									value={ testModal.testTo }
									onChange={ ( e ) => setTestModal( ( p ) => ( { ...p, testTo: e.target.value } ) ) }
									onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); handleTestRun(); } } }
									placeholder="you@gmail.com"
									style={ { marginBottom: 16 } }
								/>
								<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
									<Button onClick={ () => setTestModal( null ) }>Huỷ</Button>
									<Button variant="primary"
										disabled={ testModal.busy || ! testModal.testTo.trim() }
										onClick={ handleTestRun }>
										{ testModal.busy ? 'Đang gửi…' : '📨 Gửi thử' }
									</Button>
								</div>
							</>
						) : (
							<>
								{ testModal.result && testModal.result.success !== false ? (
									<div style={ { padding: '10px 14px', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, fontSize: 13, marginBottom: 12, color: '#15803d' } }>
										✅ Email đã gửi thành công tới <strong>{ testModal.testTo }</strong>.
									</div>
								) : (
									<div style={ { padding: '10px 14px', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, fontSize: 13, marginBottom: 12, color: '#dc2626' } }>
										❌ Gửi thất bại. { testModal.result?.error?.message || 'Xem PHP error log để biết thêm.' }
									</div>
								) }
								<div style={ { display: 'flex', justifyContent: 'flex-end' } }>
									<Button onClick={ () => setTestModal( null ) }>Đóng</Button>
								</div>
							</>
						) }
					</div>
				</div>
			) }
		</div>
	);
}
