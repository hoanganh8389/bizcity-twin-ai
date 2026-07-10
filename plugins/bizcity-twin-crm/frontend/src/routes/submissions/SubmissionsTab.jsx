// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — CF7 Submissions menu (below Contacts)
// v2: added Dashboard analytics tab + Excel export + PDF/Word export
// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity column, view icon, activity dialog, dashboard activity stats
// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — unified submissions: follow_status badge, assignee column, bulk-assign, source_type filter
import React, { useState, useMemo, useRef } from 'react';
import {
	RefreshCw, ExternalLink, ChevronLeft, ChevronRight,
	User, Filter, CheckCircle2, XCircle, Clock, Minus,
	BarChart2, FileSpreadsheet, Printer, FileText, Download,
	Eye, StickyNote, Phone, Calendar, Mail, CheckSquare, Plus, Loader2, MessageSquarePlus,
	UserCheck, ArrowRight, Users, TrendingUp, Award,
} from 'lucide-react';
import { BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import {
	useGetCf7SubmissionsQuery,
	useGetCf7SubmissionFormsQuery,
	useGetCf7SubmissionsStatsQuery,
	useGetSubmissionActivitiesQuery,
	useCreateSubmissionActivityMutation,
	useGetSubmissionActivityStatsQuery,
	useAssignSubmissionMutation,
	useBulkAssignSubmissionsMutation,
	useCf7BulkAssignMutation,
	useUpdateSubmissionStatusMutation,
	// [2026-07-05 Johnny Chu] PHASE-0.46 M3 — push to pipeline
	usePushSubmissionToPipelineMutation,
	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — assignable users
	useGetCrmAssignableUsersQuery,
	// [2026-06-30 Johnny Chu] PHASE-0.46 — work dashboards
	useGetTeamWorkStatsQuery,
	useGetMyWorkStatsQuery,
} from '../../redux/api/crmApi.js';
import { useDispatch } from 'react-redux';
import { setActiveTab } from '../../redux/uiTabs.js';

// ── Boot constants ────────────────────────────────────────────────────────────
const BOOT         = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const CG_URL       = BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/';
const NONCE        = BOOT.restNonce || '';
// [2026-06-30 Johnny Chu] PHASE-0.46 — manager flag + current user ID for dashboards
const IS_MANAGER   = !! ( BOOT.isManager );
const CURRENT_USER_ID = parseInt( BOOT.currentUserId || 0, 10 );

// ── CSV / download utilities ───────────────────────────────────────────────────

function buildCsv( rows ) {
	if ( ! rows.length ) { return ''; }
	const esc = ( v ) => {
		const s = ( v === null || v === undefined ) ? '' : ( typeof v === 'object' ? JSON.stringify( v ) : String( v ) );
		return '"' + s.replace( /"/g, '""' ) + '"';
	};
	// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — include activity columns in export
	const base    = [ 'id', 'form_id', 'form_title', 'email', 'phone', 'crm_action', 'crm_contact_id', 'crm_error', 'submitted_at', 'source_url', 'activity_status', 'activity_count', 'activity_title', 'activity_body', 'activity_at' ];
	const rawKeys = Array.from( new Set( rows.flatMap( ( r ) => ( r.raw_data ? Object.keys( r.raw_data ) : [] ) ) ) );
	const headers = [ ...base, ...rawKeys ];
	const lines   = [ headers.join( ',' ) ];
	rows.forEach( ( r ) => {
		const row = [
			...base.map( ( k ) => esc( r[ k ] ) ),
			...rawKeys.map( ( k ) => esc( r.raw_data ? r.raw_data[ k ] : '' ) ),
		];
		lines.push( row.join( ',' ) );
	} );
	return '\uFEFF' + lines.join( '\r\n' ); // BOM so Excel opens UTF-8 correctly
}

function downloadBlob( content, filename, mime ) {
	const blob = new Blob( [ content ], { type: mime } );
	const url  = URL.createObjectURL( blob );
	const a    = document.createElement( 'a' );
	a.href     = url;
	a.download = filename;
	a.click();
	URL.revokeObjectURL( url );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function CrmActionBadge( { action } ) {
	if ( ! action ) { return <span style={ { color: '#94a3b8', fontSize: 11 } }>—</span>; }
	const map = {
		created: { label: 'Tạo mới',  color: '#16a34a', bg: '#f0fdf4' },
		updated: { label: 'Cập nhật', color: '#2563eb', bg: '#eff6ff' },
		skipped: { label: 'Bỏ qua',   color: '#64748b', bg: '#f1f5f9' },
		error:   { label: 'Lỗi',      color: '#dc2626', bg: '#fef2f2' },
	};
	const s = map[ action ] || { label: action, color: '#64748b', bg: '#f1f5f9' };
	return (
		<span style={ { fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 600, color: s.color, background: s.bg } }>
			{ s.label }
		</span>
	);
}

/** Collect all unique keys from raw_data across current page rows, ordered by frequency. */
function collectRawKeys( rows ) {
	const freq = {};
	rows.forEach( ( r ) => {
		if ( r.raw_data && typeof r.raw_data === 'object' ) {
			Object.keys( r.raw_data ).forEach( ( k ) => { freq[ k ] = ( freq[ k ] || 0 ) + 1; } );
		}
	} );
	return Object.entries( freq ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] ).map( ( [ k ] ) => k );
}

/** Render a raw_data cell value (handles arrays, objects, strings). */
function RawCell( { value } ) {
	if ( value === null || value === undefined ) { return <span style={ { color: '#cbd5e1' } }>—</span>; }
	if ( Array.isArray( value ) ) { return <span>{ value.join( ', ' ) }</span>; }
	if ( typeof value === 'object' ) { return <span style={ { fontSize: 10, color: '#94a3b8' } }>{ JSON.stringify( value ) }</span>; }
	return <span>{ String( value ) }</span>;
}

// ── Activity helpers ──────────────────────────────────────────────────────────

// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — FollowStatusBadge
function FollowStatusBadge( { status, onClick } ) {
	const map = {
		new:           { label: 'Mới',          color: '#64748b', bg: '#f1f5f9' },
		contacted:     { label: 'Đã liên lạc',  color: '#2563eb', bg: '#eff6ff' },
		qualified:     { label: 'Đủ ĐK',        color: '#16a34a', bg: '#f0fdf4' },
		proposal_sent: { label: 'Đã báo giá',   color: '#7c3aed', bg: '#f5f3ff' },
		negotiating:   { label: 'Đàm phán',     color: '#d97706', bg: '#fffbeb' },
		closed_won:    { label: 'Thành công',   color: '#15803d', bg: '#dcfce7' },
		closed_lost:   { label: 'Thất bại',     color: '#dc2626', bg: '#fef2f2' },
		invalid:       { label: 'Không hợp lệ', color: '#94a3b8', bg: '#f8fafc' },
	};
	const s = map[ status ] || { label: status || '—', color: '#94a3b8', bg: '#f8fafc' };
	return (
		<span
			onClick={ onClick }
			style={ {
				fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 600,
				color: s.color, background: s.bg,
				cursor: onClick ? 'pointer' : 'default',
				display: 'inline-block',
			} }
			title={ onClick ? 'Click để thay đổi trạng thái' : undefined }
		>
			{ s.label }
		</span>
	);
}

// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — inline status dropdown (replaces window.prompt)
const STATUS_OPTIONS = [
	{ v: 'new',           label: 'Mới' },
	{ v: 'contacted',     label: 'Đã liên lạc' },
	{ v: 'qualified',     label: 'Đủ Điều Kiện' },
	{ v: 'proposal_sent', label: 'Đã báo giá' },
	{ v: 'negotiating',   label: 'Đàm phán' },
	{ v: 'closed_won',    label: 'Thành công' },
	{ v: 'closed_lost',   label: 'Thất bại' },
	{ v: 'invalid',       label: 'Không hợp lệ' },
];
function StatusDropdown( { currentStatus, onPick } ) {
	return (
		<select
			value={ currentStatus || 'new' }
			onChange={ ( e ) => { if ( e.target.value !== currentStatus ) { onPick( e.target.value ); } } }
			onClick={ ( e ) => e.stopPropagation() }
			style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 8, padding: '2px 4px', cursor: 'pointer', background: '#fff' } }
			title="Thay đổi trạng thái"
		>
			{ STATUS_OPTIONS.map( ( o ) => (
				<option key={ o.v } value={ o.v }>{ o.label }</option>
			) ) }
		</select>
	);
}

// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — AssigneeTag: colored avatar-style chip for assigned user
const ASSIGNEE_COLORS = [
	[ '#ede9fe', '#7c3aed' ], [ '#e0f2fe', '#0369a1' ], [ '#dcfce7', '#15803d' ],
	[ '#fef9c3', '#a16207' ], [ '#ffe4e6', '#be123c' ], [ '#f0fdf4', '#166534' ],
	[ '#fff7ed', '#c2410c' ], [ '#f0f9ff', '#0284c7' ],
];
function assigneeColor( name ) {
	let h = 0;
	for ( let i = 0; i < ( name || '' ).length; i++ ) { h = ( h * 31 + ( name || '' ).charCodeAt( i ) ) | 0; }
	return ASSIGNEE_COLORS[ Math.abs( h ) % ASSIGNEE_COLORS.length ];
}
function AssigneeTag( { name, userId, small } ) {
	if ( ! name ) { return null; }
	const [ bg, fg ] = assigneeColor( name );
	const initials = name.split( ' ' ).filter( Boolean ).slice( 0, 2 ).map( ( w ) => w[ 0 ].toUpperCase() ).join( '' );
	return (
		<span
			title={ userId ? ( name + ' — User #' + userId ) : name }
			style={ {
				display: 'inline-flex', alignItems: 'center', gap: 4,
				background: bg, color: fg, borderRadius: 20,
				padding: small ? '1px 6px 1px 2px' : '2px 8px 2px 3px',
				fontSize: small ? 10 : 11, fontWeight: 600, whiteSpace: 'nowrap',
			} }
		>
			<span style={ {
				display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
				width: small ? 14 : 16, height: small ? 14 : 16, borderRadius: '50%',
				background: fg, color: bg, fontSize: small ? 8 : 9, fontWeight: 700, flexShrink: 0,
			} }>
				{ initials }
			</span>
			{ name.split( ' ' )[ 0 ] }
		</span>
	);
}

// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — SourceTypeBadge
function SourceTypeBadge( { type } ) {
	const map = {
		cf7:             { label: '📋 CF7',       color: '#16a34a', bg: '#f0fdf4' },
		campaign_qr:     { label: '📷 QR',        color: '#ea580c', bg: '#fff7ed' },
		campaign_ref:    { label: '🔗 Ref',       color: '#0891b2', bg: '#ecfeff' },
		loyalty:         { label: '⭐ Loyalty',   color: '#b45309', bg: '#fefce8' },
		lucky_wheel:     { label: '🎡 Vòng quay', color: '#7c3aed', bg: '#f5f3ff' },
		broadcast_reply: { label: '📨 Broadcast', color: '#2563eb', bg: '#eff6ff' },
		webchat_optin:   { label: '💬 WebChat',   color: '#0369a1', bg: '#f0f9ff' },
		manual:          { label: '✍️ Thủ công',  color: '#64748b', bg: '#f1f5f9' },
		import:          { label: '📥 Import',    color: '#475569', bg: '#f8fafc' },
	};
	const s = map[ type ] || { label: type || '—', color: '#94a3b8', bg: '#f8fafc' };
	return (
		<span style={ { fontSize: 11, padding: '2px 8px', borderRadius: 10, fontWeight: 600, color: s.color, background: s.bg } }>
			{ s.label }
		</span>
	);
}

const ACTIVITY_ICONS = { note: StickyNote, call: Phone, meeting: Calendar, email: Mail, task: CheckSquare };

const ACTIVITY_META = {
	note:    { label: 'Note',    color: '#6366f1', bg: '#eef2ff' },
	call:    { label: 'Call',    color: '#16a34a', bg: '#f0fdf4' },
	meeting: { label: 'Meeting', color: '#f59e0b', bg: '#fffbeb' },
	email:   { label: 'Email',   color: '#2563eb', bg: '#eff6ff' },
	task:    { label: 'Task',    color: '#7c3aed', bg: '#f5f3ff' },
};

/** Compact note preview for the "Ghi chú activity" column */
function ActivityNoteCell( { title, body, at, count } ) {
	if ( ! title && ! count ) {
		return <span style={ { color: '#cbd5e1', fontSize: 11 } }>—</span>;
	}
	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 1 } }>
			{ title && (
				<span style={ { fontSize: 11, fontWeight: 600, color: '#334155', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block', maxWidth: 210 } }
					title={ title }>
					{ title }
				</span>
			) }
			{ body && (
				<span style={ { fontSize: 10, color: '#64748b', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', display: 'block', maxWidth: 210 } }
					title={ body }>
					{ body }
				</span>
			) }
			{ at && (
				<span style={ { fontSize: 9, color: '#94a3b8' } }>{ timeAgo( at ) }</span>
			) }
		</div>
	);
}

function ActivityBadge( { type, count, onClick } ) {	if ( ! type && ! count ) {
		return (
			<button
				onClick={ onClick }
				style={ { background: 'none', border: '1px dashed #cbd5e1', borderRadius: 8, padding: '2px 8px', cursor: 'pointer', fontSize: 11, color: '#94a3b8', display: 'inline-flex', alignItems: 'center', gap: 3 } }
				title="Thêm activity"
			>
				<Plus size={ 10 } /> Thêm
			</button>
		);
	}
	const m = ACTIVITY_META[ type ] || { label: type, color: '#64748b', bg: '#f1f5f9' };
	const Icon = ACTIVITY_ICONS[ type ] || StickyNote;
	return (
		<button
			onClick={ onClick }
			style={ { background: m.bg, border: 'none', borderRadius: 8, padding: '2px 8px', cursor: 'pointer', fontSize: 11, color: m.color, fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: 4 } }
			title={ 'Xem activities' }
		>
			<Icon size={ 10 } /> { m.label }{ count > 1 ? ` +${ count - 1 }` : '' }
		</button>
	);
}

function timeAgo( iso ) {
	const ms = Date.now() - new Date( iso ).getTime();
	const m = Math.floor( ms / 60000 );
	if ( m < 1 ) { return 'vừa xong'; }
	if ( m < 60 ) { return m + ' phút trước'; }
	const h = Math.floor( m / 60 );
	if ( h < 24 ) { return h + ' giờ trước'; }
	return Math.floor( h / 24 ) + ' ngày trước';
}

// ── Main component ────────────────────────────────────────────────────────────

// [2026-07-01 Johnny Chu] PHASE-0.46 M1 — dedupe submission rows by phone+email
function dedupeByPhoneEmail( list ) {
	const rows = Array.isArray( list ) ? list : [];
	if ( rows.length <= 1 ) { return rows; }
	const normalizePhone = ( v ) => String( v || '' ).replace( /\D+/g, '' );
	const normalizeEmail = ( v ) => String( v || '' ).trim().toLowerCase();
	const seen = new Set();
	const out  = [];
	for ( const r of rows ) {
		const phone = normalizePhone( r.phone );
		const email = normalizeEmail( r.email );
		if ( phone === '' && email === '' ) {
			out.push( r );
			continue;
		}
		const key = phone + '|' + email;
		if ( seen.has( key ) ) {
			continue;
		}
		seen.add( key );
		out.push( r );
	}
	return out;
}

export default function SubmissionsTab() {
	const dispatch = useDispatch();

	// ── View tabs ────────────────────────────────────────────────────────────
	const [ activeView, setActiveView ] = useState( 'list' ); // 'list' | 'dashboard'

	// ── Filters ─────────────────────────────────────────────────────────────
	const [ formId,      setFormId ]      = useState( 0 );
	const [ crmAction,   setCrmAction ]   = useState( '' );
	const [ from,        setFrom ]        = useState( '' );
	const [ to,          setTo ]          = useState( '' );
	const [ page,        setPage ]        = useState( 1 );
	const [ exporting,   setExporting ]   = useState( false );
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — unified submission filters
	const [ followFilter, setFollowFilter ] = useState( '' ); // follow_status filter
	const [ sourceFilter, setSourceFilter ] = useState( '' ); // source_type filter
	const [ bulkSelected, setBulkSelected ] = useState( [] ); // IDs checked for bulk-assign
	const [ bulkUserId,   setBulkUserId ]   = useState( '' );
	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — per-page state (replaces constant)
	const [ perPage, setPerPage ] = useState( 30 );

	// ── Selected submission (detail sheet) ──────────────────────────────────────
	const [ selected,       setSelected ]       = useState( null );
	// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity dialog target
	const [ activityTarget, setActivityTarget ] = useState( null ); // submission object

	// ── Mutations (PHASE-0.46 M1) ───────────────────────────────────────────
	const [ assignSub        ] = useAssignSubmissionMutation();
	const [ bulkAssign       ] = useBulkAssignSubmissionsMutation();
	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — auto-sync-and-assign CF7 rows
	const [ cf7BulkAssign    ] = useCf7BulkAssignMutation();
	const [ updateStatus     ] = useUpdateSubmissionStatusMutation();
	// [2026-07-05 Johnny Chu] PHASE-0.46 M3 — push to pipeline mutation
	const [ pushToPipeline   ] = usePushSubmissionToPipelineMutation();

	// ── Queries ─────────────────────────────────────────────────────────────
	const { data: formsData, isFetching: formsFetching } = useGetCf7SubmissionFormsQuery();
	const forms = formsData || [];

	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — pass followFilter/sourceFilter + perPage to query
	const { data, isFetching, refetch } = useGetCf7SubmissionsQuery(
		{ form_id: formId, crm_action: crmAction, from, to, page, per_page: perPage,
		  follow_status: followFilter, source_type: sourceFilter },
		{ refetchOnMountOrArgChange: true }
	);

	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — load assignable users for dropdown
	const { data: assignableUsers = [] } = useGetCrmAssignableUsersQuery();

	const rows  = data?.rows  || [];
	const total = data?.total || 0;
	const pages = data?.pages || 1;

	// ── Dynamic columns from raw_data keys ───────────────────────────────────
	const rawKeys = useMemo( () => collectRawKeys( rows ), [ rows ] );

	// Reset page when filters change
	const applyFilter = ( fn ) => { fn(); setPage( 1 ); };

	// ── Excel Export ─────────────────────────────────────────────────────────
	const handleExportExcel = async () => {
		setExporting( true );
		try {
			const p = new URLSearchParams();
			if ( formId )    { p.set( 'form_id',    formId ); }
			if ( crmAction ) { p.set( 'crm_action', crmAction ); }
			if ( from )      { p.set( 'from',       from ); }
			if ( to )        { p.set( 'to',         to ); }
			const url = CG_URL.replace( /\/$/, '' ) + '/cf7/submissions/export?' + p.toString();
			const r   = await fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': NONCE },
			} );
			const json = await r.json();
			const allRows = ( json.success ? json.data || [] : [] );
			if ( allRows.length === 0 ) {
				alert( 'Không có dữ liệu để xuất.' );
				setExporting( false );
				return;
			}
			const csv      = buildCsv( allRows );
			const dateName = new Date().toISOString().slice( 0, 10 );
			downloadBlob( csv, 'submissions_' + dateName + '.csv', 'text/csv;charset=utf-8;' );
		} catch ( e ) {
			alert( 'Lỗi khi xuất: ' + e.message );
		}
		setExporting( false );
	};

	return (
		<div className="bzc-tab-page">
			{ /* ── Header ── */ }
			<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 } }>
				<div>
					<h2 style={ { fontWeight: 700, fontSize: 18, margin: 0 } }>Submissions</h2>
					<p style={ { fontSize: 12, color: '#64748b', margin: '2px 0 0' } }>
						Lịch sử dữ liệu gửi từ Contact Form 7. Tổng: <strong>{ total }</strong>
					</p>
				</div>
				<div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
					{ activeView === 'list' && (
						<Button
							variant="ghost"
							onClick={ handleExportExcel }
							disabled={ exporting }
							title="Xuất danh sách ra Excel (.csv)"
							style={ { display: 'flex', alignItems: 'center', gap: 5, fontSize: 12 } }
						>
							<FileSpreadsheet size={ 14 } style={ { color: '#16a34a' } } />
							{ exporting ? 'Đang xuất...' : 'Xuất Excel' }
						</Button>
					) }
					<button onClick={ refetch } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#64748b', padding: 6 } } title="Làm mới">
						<RefreshCw size={ 15 } className={ isFetching ? 'spin' : '' } />
					</button>
				</div>
			</div>

			{ /* ── View Tabs ── */ }
			<div style={ { display: 'flex', gap: 2, marginBottom: 16, borderBottom: '2px solid #e2e8f0' } }>
				{ [ { v: 'list', label: '📋 Danh sách', icon: null }, { v: 'dashboard', label: '📊 Dashboard', icon: null } ].map( ( t ) => (
					<button
						key={ t.v }
						onClick={ () => setActiveView( t.v ) }
						style={ {
							padding: '7px 18px', fontSize: 13, background: 'none', border: 'none', cursor: 'pointer',
							borderBottom: activeView === t.v ? '2px solid #6366f1' : '2px solid transparent',
							color: activeView === t.v ? '#6366f1' : '#64748b',
							fontWeight: activeView === t.v ? 700 : 400,
							marginBottom: -2,
						} }
					>{ t.label }</button>
				) ) }
			</div>

			{ activeView === 'dashboard' ? (
				<SubmissionsDashboard formId={ formId } />
			) : (
				<>

			{ /* ── Form pills ── */ }
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 6, marginBottom: 12 } }>
				<button
					onClick={ () => applyFilter( () => setFormId( 0 ) ) }
					style={ {
						padding: '4px 14px', fontSize: 12, borderRadius: 20, cursor: 'pointer', border: 'none',
						background: formId === 0 ? '#475569' : '#f1f5f9',
						color:      formId === 0 ? '#fff'    : '#64748b',
						fontWeight: formId === 0 ? 700 : 400,
					} }
				>Tất cả form</button>
				{ forms.map( ( f ) => (
					<button
						key={ f.form_id }
						onClick={ () => applyFilter( () => setFormId( f.form_id ) ) }
						style={ {
							padding: '4px 14px', fontSize: 12, borderRadius: 20, cursor: 'pointer', border: 'none',
							background: formId === f.form_id ? '#f59e0b' : '#fef3c7',
							color:      formId === f.form_id ? '#fff'    : '#92400e',
							fontWeight: formId === f.form_id ? 700 : 400,
						} }
					>
						{ f.form_title || ( 'Form #' + f.form_id ) }
						<span style={ { marginLeft: 5, opacity: 0.75 } }>{ f.total }</span>
					</button>
				) ) }
				{ formsFetching && <span style={ { fontSize: 11, color: '#94a3b8' } }>Đang tải...</span> }
			</div>

			{ /* ── Filters bar ── */ }
			<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14, alignItems: 'center' } }>
				{ /* CRM action filter */ }
				<div style={ { display: 'flex', gap: 4 } }>
					{ [ { v: '', l: 'Tất cả' }, { v: 'created', l: '🟢 Tạo mới' }, { v: 'updated', l: '🔵 Cập nhật' }, { v: 'skipped', l: '⚫ Bỏ qua' }, { v: 'error', l: '🔴 Lỗi' } ].map( ( o ) => (
						<button
							key={ o.v }
							onClick={ () => applyFilter( () => setCrmAction( o.v ) ) }
							style={ {
								padding: '3px 10px', fontSize: 11, borderRadius: 20, cursor: 'pointer', border: 'none',
								background: crmAction === o.v ? '#6366f1' : '#f1f5f9',
								color:      crmAction === o.v ? '#fff'    : '#64748b',
								fontWeight: crmAction === o.v ? 700 : 400,
							} }
						>{ o.l }</button>
					) ) }
				</div>

				{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — follow_status filter */ }
				<select
					value={ followFilter }
					onChange={ ( e ) => applyFilter( () => setFollowFilter( e.target.value ) ) }
					style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px', color: '#475569' } }
				>
					<option value="">Trạng thái follow</option>
					<option value="new">Mới</option>
					<option value="contacted">Đã liên lạc</option>
					<option value="qualified">Đủ ĐK</option>
					<option value="proposal_sent">Đã báo giá</option>
					<option value="negotiating">Đàm phán</option>
					<option value="closed_won">Thành công</option>
					<option value="closed_lost">Thất bại</option>
					<option value="invalid">Không hợp lệ</option>
				</select>

				{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — source_type filter */ }
				<select
					value={ sourceFilter }
					onChange={ ( e ) => applyFilter( () => setSourceFilter( e.target.value ) ) }
					style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px', color: '#475569' } }
				>
					<option value="">Nguồn</option>
					<option value="cf7">📋 CF7 Form</option>
					<option value="campaign_qr">📷 Campaign QR</option>
					<option value="campaign_ref">🔗 Ref Link</option>
					<option value="loyalty">⭐ Loyalty</option>
					<option value="lucky_wheel">🎡 Vòng quay</option>
					<option value="manual">✍️ Thủ công</option>
				</select>

				{ /* [2026-06-30 Johnny Chu] PHASE-0.46 FIX — date range + per-page + pagination in one bar */ }
				<div style={ { display: 'flex', gap: 6, alignItems: 'center', marginLeft: 'auto', flexWrap: 'wrap' } }>
					<Filter size={ 13 } style={ { color: '#94a3b8' } } />
					<input
						type="date"
						value={ from }
						onChange={ ( e ) => applyFilter( () => setFrom( e.target.value ) ) }
						style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } }
					/>
					<span style={ { fontSize: 12, color: '#94a3b8' } }>–</span>
					<input
						type="date"
						value={ to }
						onChange={ ( e ) => applyFilter( () => setTo( e.target.value ) ) }
						style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } }
					/>
					{ ( from || to ) && (
						<button onClick={ () => applyFilter( () => { setFrom( '' ); setTo( '' ); } ) }
							style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer' } }>
							Xoá
						</button>
					) }
					<span style={ { width: 1, height: 16, background: '#e2e8f0', display: 'inline-block', margin: '0 2px' } } />
					<select
						value={ perPage }
						onChange={ ( e ) => { setPerPage( Number( e.target.value ) ); setPage( 1 ); } }
						style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 6px', color: '#475569' } }
						title="Số dòng mỗi trang"
					>
						<option value={ 10 }>10 / trang</option>
						<option value={ 20 }>20 / trang</option>
						<option value={ 30 }>30 / trang</option>
						<option value={ 50 }>50 / trang</option>
						<option value={ 100 }>100 / trang</option>
					</select>
					<span style={ { width: 1, height: 16, background: '#e2e8f0', display: 'inline-block', margin: '0 2px' } } />
					<Button size="sm" variant="ghost" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) } style={ { padding: '2px 6px', height: 'auto' } }>
						<ChevronLeft size={ 13 } />
					</Button>
					<span style={ { fontSize: 11, color: '#64748b', whiteSpace: 'nowrap' } }>
						{ isFetching ? '…' : `${ page } / ${ pages }` }
					</span>
					<Button size="sm" variant="ghost" disabled={ page >= pages } onClick={ () => setPage( ( p ) => p + 1 ) } style={ { padding: '2px 6px', height: 'auto' } }>
						<ChevronRight size={ 13 } />
					</Button>
				</div>
			</div>

			{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — Bulk assign bar */ }
			{ bulkSelected.length > 0 && (
				<div style={ { display: 'flex', gap: 10, alignItems: 'center', background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: 8, padding: '8px 14px', marginBottom: 12 } }>
					<UserCheck size={ 14 } style={ { color: '#2563eb' } } />
					<span style={ { fontSize: 12, color: '#1e40af', fontWeight: 600 } }>{ bulkSelected.length } đã chọn</span>
					<span style={ { fontSize: 12, color: '#64748b' } }>→ Giao phụ trách cho:</span>
					{ /* [2026-06-30 Johnny Chu] PHASE-0.46 FIX — dropdown thay text input WP User ID */ }
					<select
						value={ bulkUserId }
						onChange={ ( e ) => setBulkUserId( e.target.value ) }
						style={ { fontSize: 12, border: '1px solid #bfdbfe', borderRadius: 6, padding: '3px 8px', minWidth: 160 } }
					>
						<option value="">— Chọn nhân viên —</option>
						{ assignableUsers.map( ( u ) => (
							<option key={ u.id } value={ u.id }>{ u.display_name }</option>
						) ) }
					</select>
					<Button
						size="sm"
						disabled={ ! bulkUserId }
						style={ { background: bulkUserId ? '#2563eb' : undefined, color: bulkUserId ? '#fff' : undefined, fontWeight: 600 } }
						onClick={ async () => {
							if ( ! bulkUserId ) { return; }
							// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — cf7_ids direct; BE auto-syncs unified sub
							const cf7Ids = bulkSelected.filter( Boolean );
							if ( cf7Ids.length === 0 ) { return; }
							await cf7BulkAssign( { cf7_ids: cf7Ids, wp_user_id: parseInt( bulkUserId, 10 ) } );
							setBulkSelected( [] );
							setBulkUserId( '' );
							refetch();
						} }
					>
						✓ Xác nhận giao việc
					</Button>
					<button onClick={ () => setBulkSelected( [] ) } style={ { fontSize: 11, color: '#64748b', background: 'none', border: 'none', cursor: 'pointer' } }>Bỏ chọn</button>
				</div>
			) }

			{ /* ── Table ── */ }
			{ isFetching && <div style={ { fontSize: 12, color: '#94a3b8', marginBottom: 10 } }>Đang tải...</div> }
			{ ! isFetching && rows.length === 0 && (
				<div style={ { textAlign: 'center', padding: '40px 0', color: '#94a3b8', fontSize: 13 } }>
					Không có submissions nào phù hợp với bộ lọc.
				</div>
			) }
			{ dedupeByPhoneEmail( rows ).length > 0 && (
				<div style={ { overflowX: 'auto', border: '1px solid #e2e8f0', borderRadius: 10 } }>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
						<thead>
							<tr style={ { background: '#f8fafc' } }>
								<th style={ TH }>Thời gian</th>
								{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — bulk select checkbox */ }
								<th style={ { ...TH, width: 28 } }>
									<input
										type="checkbox"
										checked={ bulkSelected.length > 0 && bulkSelected.length === rows.length }
										onChange={ ( e ) => setBulkSelected( e.target.checked ? rows.map( ( r ) => r.id ) : [] ) }
									/>
								</th>
								<th style={ { ...TH, width: 28 } }></th>
								{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity cols first */ }
								<th style={ { ...TH, minWidth: 86 } }>Activity</th>
								<th style={ { ...TH, minWidth: 180, maxWidth: 240 } }>Ghi chú activity</th>
								<th style={ TH }>Form</th>
								{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — Trạng thái + Phụ trách cols */ }
								<th style={ { ...TH, minWidth: 100 } }>Trạng thái</th>
								<th style={ { ...TH, minWidth: 110 } }>Phụ trách</th>
								<th style={ TH }>Email</th>
								<th style={ TH }>Phone</th>
								{ /* Dynamic columns from raw_data */ }
								{ rawKeys.map( ( k ) => (
									<th key={ k } style={ { ...TH, maxWidth: 140 } }>{ k }</th>
								) ) }
								<th style={ TH }>CRM</th>
								<th style={ TH }>Contact</th>
							</tr>
						</thead>
						<tbody>
							{ dedupeByPhoneEmail( rows ).map( ( r ) => (
								<tr
									key={ r.id }
									style={ { borderBottom: '1px solid #f1f5f9', background: bulkSelected.includes( r.id ) ? '#eff6ff' : '' } }
									onMouseEnter={ ( e ) => { if ( ! bulkSelected.includes( r.id ) ) { e.currentTarget.style.background = '#f8fafc'; } } }
									onMouseLeave={ ( e ) => { if ( ! bulkSelected.includes( r.id ) ) { e.currentTarget.style.background = ''; } } }
								>
									<td style={ { ...TD, whiteSpace: "pre", fontSize: 11, color: "#64748b", minWidth: 90 } } onClick={ () => setSelected( r ) } role="button">
										{ r.submitted_at ? r.submitted_at.slice( 0, 16 ).replace( 'T', '\n' ) : '—' }
									</td>
									{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — bulk select */ }
									<td style={ { ...TD, width: 28 } }>
										<input
											type="checkbox"
											checked={ bulkSelected.includes( r.id ) }
											onChange={ ( e ) => setBulkSelected( ( prev ) =>
												e.target.checked ? [ ...prev, r.id ] : prev.filter( ( x ) => x !== r.id )
											) }
										/>
									</td>
									{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — view icon */ }
									<td style={ { ...TD, width: 28, paddingRight: 2 } }>
										<button
											onClick={ ( e ) => { e.stopPropagation(); setSelected( r ); } }
											style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 2, borderRadius: 4, display: 'flex', alignItems: 'center' } }
											title={ 'Xem submission #' + r.id }
										>
											<Eye size={ 13 } />
										</button>
									</td>
									{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity badge + note, placed right after icon */ }
									{ /* [2026-06-30 Johnny Chu] PHASE-0.46 FIX — Pipeline button moved here, no standalone column */ }
									<td style={ TD }>
										<div style={ { display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'flex-start' } }>
											<ActivityBadge
												type={ r.activity_status || '' }
												count={ r.activity_count || 0 }
												onClick={ ( e ) => { e.stopPropagation(); setActivityTarget( r ); } }
											/>
											{ r.pipeline_opp_id ? (
												<button
													onClick={ () => dispatch( setActiveTab( 'sales' ) ) }
													style={ { fontSize: 10, padding: '2px 7px', borderRadius: 8, background: '#dcfce7', color: '#15803d', border: 'none', cursor: 'pointer', fontWeight: 600, whiteSpace: 'nowrap' } }
													title={ 'Opp #' + r.pipeline_opp_id }
												>
													✓ Pipeline
												</button>
											) : r.unified_submission_id ? (
												<button
													onClick={ ( e ) => { e.stopPropagation(); pushToPipeline( r.unified_submission_id ); } }
													style={ { fontSize: 10, padding: '2px 7px', borderRadius: 8, background: '#f1f5f9', color: '#94a3b8', border: '1px dashed #cbd5e1', cursor: 'pointer', whiteSpace: 'nowrap' } }
													title="Thêm vào Pipeline"
												>
													+ Pipeline
												</button>
											) : null }
										</div>
									</td>
									<td style={ { ...TD, maxWidth: 220, cursor: 'pointer' } } onClick={ ( e ) => { e.stopPropagation(); setActivityTarget( r ); } }>
										<ActivityNoteCell title={ r.activity_title } body={ r.activity_body } at={ r.activity_at } count={ r.activity_count } />
									</td>
									<td style={ { ...TD, maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: '#92400e', cursor: 'pointer' } } onClick={ () => setSelected( r ) }>
										{ r.form_title || ( 'Form #' + r.form_id ) }
									</td>
									{ /* [2026-07-05 Johnny Chu] PHASE-0.46 M1 — follow_status + assignee */ }
									{ /* [2026-06-30 Johnny Chu] PHASE-0.46 FIX — badge + inline dropdown, no window.prompt */ }
									<td style={ { ...TD, minWidth: 130 } }>
										{ r.unified_submission_id ? (
											<div style={ { display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'flex-start' } }>
												<FollowStatusBadge status={ r.follow_status || 'new' } />
												<StatusDropdown
													currentStatus={ r.follow_status || 'new' }
													onPick={ ( next ) => updateStatus( { id: r.unified_submission_id, follow_status: next } ) }
												/>
											</div>
										) : (
											<FollowStatusBadge status={ r.follow_status || '' } />
										) }
									</td>
									<td style={ { ...TD, maxWidth: 140 } }>
										{ r.assignee_name ? (
											<div style={ { display: 'flex', flexDirection: 'column', gap: 3, alignItems: 'flex-start' } }>
												<AssigneeTag name={ r.assignee_name } userId={ r.assigned_to_wp_user_id } />
												<select
													defaultValue=""
													onChange={ ( e ) => { const uid = parseInt( e.target.value, 10 ); if ( uid ) { assignSub( { id: r.unified_submission_id, wp_user_id: uid } ); } e.target.value = ''; } }
													onClick={ ( e ) => e.stopPropagation() }
													style={ { fontSize: 10, border: '1px solid #e2e8f0', borderRadius: 6, padding: '1px 3px', color: '#94a3b8', cursor: 'pointer', maxWidth: 120 } }
													title="Giao lại cho người khác"
												>
													<option value="" disabled>↺ Giao lại…</option>
													{ assignableUsers.map( ( u ) => ( <option key={ u.id } value={ u.id }>{ u.display_name }</option> ) ) }
												</select>
											</div>
										) : r.unified_submission_id ? (
											<select
												defaultValue=""
												onChange={ ( e ) => { const uid = parseInt( e.target.value, 10 ); if ( uid ) { assignSub( { id: r.unified_submission_id, wp_user_id: uid } ); } e.target.value = ''; } }
												onClick={ ( e ) => e.stopPropagation() }
												style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '2px 4px', color: '#6366f1', cursor: 'pointer', maxWidth: 120 } }
												title="Chọn để giao submission"
											>
												<option value="" disabled>+ Giao cho…</option>
												{ assignableUsers.map( ( u ) => ( <option key={ u.id } value={ u.id }>{ u.display_name }</option> ) ) }
											</select>
										) : (
											<span style={ { fontSize: 10, color: '#94a3b8', fontStyle: 'italic' } }>Chưa đồng bộ</span>
										) }
									</td>
									<td style={ { ...TD, color: '#2563eb', maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', cursor: 'pointer' } } onClick={ () => setSelected( r ) }>
										{ r.email || '—' }
									</td>
									<td style={ { ...TD, fontFamily: 'monospace', cursor: 'pointer' } } onClick={ () => setSelected( r ) }>{ r.phone || '—' }</td>
									{ /* Dynamic raw_data columns */ }
									{ rawKeys.map( ( k ) => (
										<td key={ k } style={ { ...TD, maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', cursor: 'pointer' } } onClick={ () => setSelected( r ) }>
											<RawCell value={ r.raw_data ? r.raw_data[ k ] : undefined } />
										</td>
									) ) }
									<td style={ { ...TD, cursor: 'pointer' } } onClick={ () => setSelected( r ) }><CrmActionBadge action={ r.crm_action } /></td>
									<td style={ TD }>
										{ r.crm_contact_id ? (
											<button
												onClick={ ( e ) => { e.stopPropagation(); dispatch( setActiveTab( 'contacts' ) ); } }
												style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 3 } }
												title={ 'Contact #' + r.crm_contact_id }
											>
												<User size={ 11 } /> #{ r.crm_contact_id }
											</button>
										) : '—' }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ /* [2026-06-30 Johnny Chu] PHASE-0.46 FIX — bottom filter/paging bar (duplicate of top) */ }
			<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginTop: 14, alignItems: 'center', borderTop: '1px solid #f1f5f9', paddingTop: 12 } }>
				{ /* CRM action filter */ }
				<div style={ { display: 'flex', gap: 4 } }>
					{ [ { v: '', l: 'Tất cả' }, { v: 'created', l: '🟢 Tạo mới' }, { v: 'updated', l: '🔵 Cập nhật' }, { v: 'skipped', l: '⚫ Bỏ qua' }, { v: 'error', l: '🔴 Lỗi' } ].map( ( o ) => (
						<button
							key={ o.v }
							onClick={ () => applyFilter( () => setCrmAction( o.v ) ) }
							style={ {
								padding: '3px 10px', fontSize: 11, borderRadius: 20, cursor: 'pointer', border: 'none',
								background: crmAction === o.v ? '#6366f1' : '#f1f5f9',
								color:      crmAction === o.v ? '#fff'    : '#64748b',
								fontWeight: crmAction === o.v ? 700 : 400,
							} }
						>{ o.l }</button>
					) ) }
				</div>
				<select
					value={ followFilter }
					onChange={ ( e ) => applyFilter( () => setFollowFilter( e.target.value ) ) }
					style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px', color: '#475569' } }
				>
					<option value="">Trạng thái follow</option>
					<option value="new">Mới</option>
					<option value="contacted">Đã liên lạc</option>
					<option value="qualified">Đủ ĐK</option>
					<option value="proposal_sent">Đã báo giá</option>
					<option value="negotiating">Đàm phán</option>
					<option value="closed_won">Thành công</option>
					<option value="closed_lost">Thất bại</option>
					<option value="invalid">Không hợp lệ</option>
				</select>
				<select
					value={ sourceFilter }
					onChange={ ( e ) => applyFilter( () => setSourceFilter( e.target.value ) ) }
					style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px', color: '#475569' } }
				>
					<option value="">Nguồn</option>
					<option value="cf7">📋 CF7 Form</option>
					<option value="campaign_qr">📷 Campaign QR</option>
					<option value="campaign_ref">🔗 Ref Link</option>
					<option value="loyalty">⭐ Loyalty</option>
					<option value="lucky_wheel">🎡 Vòng quay</option>
					<option value="manual">✍️ Thủ công</option>
				</select>
				<div style={ { display: 'flex', gap: 6, alignItems: 'center', marginLeft: 'auto', flexWrap: 'wrap' } }>
					<Filter size={ 13 } style={ { color: '#94a3b8' } } />
					<input
						type="date" value={ from }
						onChange={ ( e ) => applyFilter( () => setFrom( e.target.value ) ) }
						style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } }
					/>
					<span style={ { fontSize: 12, color: '#94a3b8' } }>–</span>
					<input
						type="date" value={ to }
						onChange={ ( e ) => applyFilter( () => setTo( e.target.value ) ) }
						style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } }
					/>
					{ ( from || to ) && (
						<button onClick={ () => applyFilter( () => { setFrom( '' ); setTo( '' ); } ) }
							style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer' } }>Xoá</button>
					) }
					<span style={ { width: 1, height: 16, background: '#e2e8f0', display: 'inline-block', margin: '0 2px' } } />
					<select
						value={ perPage }
						onChange={ ( e ) => { setPerPage( Number( e.target.value ) ); setPage( 1 ); } }
						style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 6px', color: '#475569' } }
						title="Số dòng mỗi trang"
					>
						<option value={ 10 }>10 / trang</option>
						<option value={ 20 }>20 / trang</option>
						<option value={ 30 }>30 / trang</option>
						<option value={ 50 }>50 / trang</option>
						<option value={ 100 }>100 / trang</option>
					</select>
					<span style={ { width: 1, height: 16, background: '#e2e8f0', display: 'inline-block', margin: '0 2px' } } />
					<span style={ { fontSize: 11, color: '#64748b' } }>{ total } dòng</span>
					<Button size="sm" variant="ghost" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) } style={ { padding: '2px 6px', height: 'auto' } }>
						<ChevronLeft size={ 13 } />
					</Button>
					<span style={ { fontSize: 11, color: '#64748b', whiteSpace: 'nowrap' } }>
						{ isFetching ? '…' : `${ page } / ${ pages }` }
					</span>
					<Button size="sm" variant="ghost" disabled={ page >= pages } onClick={ () => setPage( ( p ) => p + 1 ) } style={ { padding: '2px 6px', height: 'auto' } }>
						<ChevronRight size={ 13 } />
					</Button>
				</div>
			</div>

			{ /* ── Detail Sheet ── */ }
			<Sheet open={ !! selected } onOpenChange={ ( o ) => { if ( ! o ) { setSelected( null ); } } }>
				<SheetContent side="right" style={ { width: 520 } }>
					<SheetHeader>
						<SheetTitle>
							Submission #{ selected?.id }
							{ selected?.form_title && (
								<span style={ { fontSize: 12, fontWeight: 400, color: '#64748b', marginLeft: 8 } }>
									{ selected.form_title }
								</span>
							) }
						</SheetTitle>
					</SheetHeader>
					<SheetBody>
						{ selected && <SubmissionDetail sub={ selected } dispatch={ dispatch } onAddActivity={ () => { setSelected( null ); setActivityTarget( selected ); } } /> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — Activity Sheet ── */ }
			<Sheet open={ !! activityTarget } onOpenChange={ ( o ) => { if ( ! o ) { setActivityTarget( null ); } } }>
				<SheetContent side="right" style={ { width: 480 } }>
					<SheetHeader>
						<SheetTitle style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
							<MessageSquarePlus size={ 18 } style={ { color: '#6366f1' } } />
							Activities
							{ activityTarget && (
								<span style={ { fontSize: 12, fontWeight: 400, color: '#64748b' } }>
									· { activityTarget.email || activityTarget.phone || ( '#' + activityTarget.id ) }
								</span>
							) }
						</SheetTitle>
					</SheetHeader>
					<SheetBody>
						{ activityTarget && (
							<SubmissionActivityPanel
								submission={ activityTarget }
								onClose={ () => setActivityTarget( null ) }
							/>
						) }
					</SheetBody>
				</SheetContent>
			</Sheet>

				</> /* end list view */
			) }
		</div>
	);
}

// ── Table cell styles ────────────────────────────────────────────────────────
const TH = { padding: '8px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0', whiteSpace: 'nowrap', fontSize: 11 };
const TD = { padding: '7px 10px', color: '#334155', verticalAlign: 'top' };

// ── Detail panel ─────────────────────────────────────────────────────────────

function SubmissionDetail( { sub, dispatch, onAddActivity } ) {
	const rawKeys   = sub.raw_data    ? Object.keys( sub.raw_data )    : [];
	const mappedKeys = sub.mapped_data ? Object.keys( sub.mapped_data ) : [];

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 20 } }>
			{ /* ── Activity shortcut ── */ }
			{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — quick open activity panel */ }
			<div style={ { display: 'flex', justifyContent: 'flex-end' } }>
				<Button
					size="sm"
					variant="ghost"
					onClick={ onAddActivity }
					style={ { fontSize: 12, display: 'flex', alignItems: 'center', gap: 5, color: '#6366f1', border: '1px solid #e0e7ff', borderRadius: 8 } }
				>
					<MessageSquarePlus size={ 13 } /> { sub.activity_count > 0 ? sub.activity_count + ' Activities' : 'Thêm Activity' }
				</Button>
			</div>
			{ /* ── Meta ── */ }
			<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 16px' } }>
				<MetaRow label="Thời gian" value={ sub.submitted_at ? sub.submitted_at.replace( 'T', ' ' ).slice( 0, 19 ) : '—' } />
				<MetaRow label="Form" value={ sub.form_title || ( 'Form #' + sub.form_id ) } />
				<MetaRow label="Email" value={ sub.email || '—' } />
				<MetaRow label="Phone" value={ sub.phone || '—' } mono />
				<MetaRow label="CRM action" value={ <CrmActionBadge action={ sub.crm_action } /> } />
				{ sub.crm_contact_id && (
					<div>
						<div style={ { fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', marginBottom: 2 } }>Contact</div>
						<button
							onClick={ () => dispatch( setActiveTab( 'contacts' ) ) }
							style={ { fontSize: 12, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 4, padding: 0 } }
						>
							<User size={ 12 } /> Xem Contact #{ sub.crm_contact_id }
						</button>
					</div>
				) }
				{ sub.source_url && (
					<div style={ { gridColumn: '1 / -1' } }>
						<div style={ { fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', marginBottom: 2 } }>Nguồn URL</div>
						<a href={ sub.source_url } target="_blank" rel="noreferrer" style={ { fontSize: 11, color: '#6366f1', display: 'flex', alignItems: 'center', gap: 3, wordBreak: 'break-all' } }>
							<ExternalLink size={ 11 } /> { sub.source_url }
						</a>
					</div>
				) }
				{ sub.crm_error && (
					<div style={ { gridColumn: '1 / -1', padding: '6px 10px', background: '#fef2f2', borderRadius: 6, fontSize: 11, color: '#dc2626' } }>
						⚠️ CRM lỗi: { sub.crm_error }
					</div>
				) }
			</div>

			{ /* ── Raw Data (tất cả fields) ── */ }
			{ rawKeys.length > 0 && (
				<div>
					<SectionTitle>📋 Dữ liệu form (Raw)</SectionTitle>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 8, overflow: 'hidden' } }>
						<thead>
							<tr style={ { background: '#f8fafc' } }>
								<th style={ { padding: '6px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 11, borderBottom: '1px solid #e2e8f0', width: '40%' } }>Trường</th>
								<th style={ { padding: '6px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 11, borderBottom: '1px solid #e2e8f0' } }>Giá trị</th>
							</tr>
						</thead>
						<tbody>
							{ rawKeys.map( ( k ) => (
								<tr key={ k } style={ { borderBottom: '1px solid #f1f5f9' } }>
									<td style={ { padding: '6px 10px', color: '#64748b', fontFamily: 'monospace', fontSize: 11, background: '#fafafa' } }>{ k }</td>
									<td style={ { padding: '6px 10px', color: '#1e293b', wordBreak: 'break-word' } }>
										<RawCellDetail value={ sub.raw_data[ k ] } />
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ /* ── Mapped Data ── */ }
			{ mappedKeys.length > 0 && (
				<div>
					<SectionTitle>🗂 Dữ liệu đã mapping vào CRM</SectionTitle>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 8, overflow: 'hidden' } }>
						<thead>
							<tr style={ { background: '#f8fafc' } }>
								<th style={ { padding: '6px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 11, borderBottom: '1px solid #e2e8f0', width: '40%' } }>Trường CRM</th>
								<th style={ { padding: '6px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 11, borderBottom: '1px solid #e2e8f0' } }>Giá trị</th>
							</tr>
						</thead>
						<tbody>
							{ mappedKeys.map( ( k ) => (
								<tr key={ k } style={ { borderBottom: '1px solid #f1f5f9' } }>
									<td style={ { padding: '6px 10px', color: '#6366f1', fontFamily: 'monospace', fontSize: 11, background: '#f5f3ff' } }>{ k }</td>
									<td style={ { padding: '6px 10px', color: '#1e293b', wordBreak: 'break-word' } }>
										<RawCellDetail value={ sub.mapped_data[ k ] } />
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

function MetaRow( { label, value, mono } ) {
	return (
		<div>
			<div style={ { fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 2 } }>{ label }</div>
			<div style={ { fontSize: 13, color: '#1e293b', fontFamily: mono ? 'monospace' : undefined } }>{ value }</div>
		</div>
	);
}

function SectionTitle( { children } ) {
	return <div style={ { fontWeight: 700, fontSize: 13, color: '#334155', marginBottom: 8, paddingBottom: 4, borderBottom: '1px solid #e2e8f0' } }>{ children }</div>;
}

function RawCellDetail( { value } ) {
	if ( value === null || value === undefined ) { return <span style={ { color: '#cbd5e1' } }>—</span>; }
	if ( Array.isArray( value ) ) {
		return (
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4 } }>
				{ value.map( ( v, i ) => (
					<span key={ i } style={ { background: '#eff6ff', color: '#2563eb', padding: '2px 8px', borderRadius: 10, fontSize: 11 } }>{ String( v ) }</span>
				) ) }
			</div>
		);
	}
	if ( typeof value === 'object' ) {
		return <pre style={ { fontSize: 10, background: '#f8fafc', padding: '4px 8px', borderRadius: 4, margin: 0, overflow: 'auto' } }>{ JSON.stringify( value, null, 2 ) }</pre>;
	}
	return <span>{ String( value ) }</span>;
}

// ── Submission Activity Panel ─────────────────────────────────────────────────
// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY

function SubmissionActivityPanel( { submission, onClose } ) {
	const { data: activities = [], isFetching, refetch } = useGetSubmissionActivitiesQuery( { id: submission.id } );
	const [ createActivity, { isLoading: creating } ] = useCreateSubmissionActivityMutation();
	const [ showForm, setShowForm ] = useState( false );
	const [ type,  setType  ] = useState( 'note' );
	const [ title, setTitle ] = useState( '' );
	const [ body,  setBody  ] = useState( '' );
	const [ err,   setErr   ] = useState( '' );

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		if ( ! title.trim() ) { setErr( 'Vui lòng nhập tiêu đề.' ); return; }
		setErr( '' );
		const r = await createActivity( { id: submission.id, type, title: title.trim(), body } );
		if ( r.error ) { setErr( 'Lỗi khi lưu activity.' ); return; }
		setTitle( '' );
		setBody( '' );
		setType( 'note' );
		setShowForm( false );
		refetch();
	};

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 16 } }>
			{ /* ── Mini submission header ── */ }
			<div style={ { padding: '10px 12px', background: '#f8fafc', borderRadius: 8, fontSize: 12, display: 'flex', flexDirection: 'column', gap: 4 } }>
				<div style={ { fontWeight: 700, color: '#334155' } }>Submission #{ submission.id }</div>
				{ submission.email && <div style={ { color: '#64748b' } }>{ submission.email }</div> }
				{ submission.phone && <div style={ { color: '#64748b', fontFamily: 'monospace' } }>{ submission.phone }</div> }
				<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
					<CrmActionBadge action={ submission.crm_action } />
				</div>
			</div>

			{ /* ── Toolbar ── */ }
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
				<div style={ { fontSize: 13, fontWeight: 600, color: '#334155' } }>
					{ activities.length > 0 ? activities.length + ' activities' : 'Chưa có activity' }
				</div>
				<Button
					size="sm"
					onClick={ () => setShowForm( ( v ) => ! v ) }
					style={ { display: 'flex', alignItems: 'center', gap: 5, fontSize: 12 } }
				>
					<Plus size={ 13 } /> Activity mới
				</Button>
			</div>

			{ /* ── Add form ── */ }
			{ showForm && (
				<form onSubmit={ handleSubmit } style={ { border: '1px solid #e2e8f0', borderRadius: 10, padding: 14, display: 'flex', flexDirection: 'column', gap: 10, background: '#fafafa' } }>
					<div>
						<Label style={ { fontSize: 11, marginBottom: 4 } }>Loại</Label>
						<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4 } }>
							{ [ 'note', 'call', 'meeting', 'email', 'task' ].map( ( t ) => {
								const Icon = ACTIVITY_ICONS[ t ] || StickyNote;
								const m = ACTIVITY_META[ t ] || {};
								return (
									<button
										key={ t }
										type="button"
										onClick={ () => setType( t ) }
										style={ {
											padding: '4px 10px', fontSize: 11, borderRadius: 20, border: 'none', cursor: 'pointer',
											background: type === t ? ( m.bg || '#eef2ff' ) : '#f1f5f9',
											color:      type === t ? ( m.color || '#6366f1' ) : '#64748b',
											fontWeight: type === t ? 700 : 400,
											display: 'inline-flex', alignItems: 'center', gap: 4,
										} }
									>
										<Icon size={ 12 } /> { t }
									</button>
								);
							} ) }
						</div>
					</div>
					<div>
						<Label style={ { fontSize: 11, marginBottom: 4 } }>Tiêu đề</Label>
						<input
							value={ title }
							onChange={ ( e ) => setTitle( e.target.value ) }
							autoFocus
							placeholder="Tiêu đề activity..."
							style={ { width: '100%', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '6px 10px', boxSizing: 'border-box' } }
						/>
					</div>
					<div>
						<Label style={ { fontSize: 11, marginBottom: 4 } }>Nội dung (tuỳ chọn)</Label>
						<textarea
							value={ body }
							onChange={ ( e ) => setBody( e.target.value ) }
							rows={ 3 }
							placeholder="Ghi chú, kết quả cuộc gọi,..."
							style={ { width: '100%', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '6px 10px', boxSizing: 'border-box', resize: 'vertical' } }
						/>
					</div>
					{ err && <div style={ { fontSize: 11, color: '#dc2626' } }>{ err }</div> }
					<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
						<Button type="button" variant="ghost" size="sm" onClick={ () => setShowForm( false ) }>Huỷ</Button>
						<Button type="submit" size="sm" disabled={ creating }>
							{ creating ? <Loader2 size={ 13 } className="spin" /> : 'Tạo activity' }
						</Button>
					</div>
				</form>
			) }

			{ /* ── Activity list ── */ }
			{ isFetching && <div style={ { fontSize: 12, color: '#94a3b8' } }>Đang tải...</div> }
			{ ! isFetching && activities.length === 0 && ! showForm && (
				<div style={ { textAlign: 'center', padding: '24px 0', color: '#94a3b8', fontSize: 12 } }>
					Chưa có activity nào. Nhấn "+ Activity mới" để bắt đầu.
				</div>
			) }
			<div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
				{ activities.map( ( a ) => {
					const Icon = ACTIVITY_ICONS[ a.type ] || StickyNote;
					const m = ACTIVITY_META[ a.type ] || { color: '#64748b', bg: '#f1f5f9' };
					return (
						<div key={ a.id } style={ { display: 'flex', gap: 10, padding: '10px 12px', border: '1px solid #f1f5f9', borderRadius: 8, background: '#fff' } }>
							<div style={ { width: 28, height: 28, borderRadius: '50%', background: m.bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, marginTop: 1 } }>
								<Icon size={ 13 } style={ { color: m.color } } />
							</div>
							<div style={ { flex: 1, minWidth: 0 } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 } }>
									<strong style={ { fontSize: 12, color: '#1e293b' } }>{ a.title }</strong>
									<span style={ { fontSize: 10, color: '#94a3b8', whiteSpace: 'nowrap', flexShrink: 0 } }>{ timeAgo( a.created_at ) }</span>
								</div>
								{ a.user && <div style={ { fontSize: 10, color: '#64748b', marginTop: 1 } }>{ a.user }</div> }
								{ a.body && <div style={ { fontSize: 11, color: '#475569', marginTop: 4, whiteSpace: 'pre-wrap', wordBreak: 'break-word' } }>{ a.body }</div> }
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

// ── Dashboard Analytics ───────────────────────────────────────────────────────

// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity breakdown stat cards row
const ACT_TYPE_CATALOG = [
	{ type: 'note',    label: 'Note',    color: '#6366f1', bg: '#eef2ff' },
	{ type: 'call',    label: 'Call',    color: '#16a34a', bg: '#f0fdf4' },
	{ type: 'meeting', label: 'Meeting', color: '#f59e0b', bg: '#fffbeb' },
	{ type: 'email',   label: 'Email',   color: '#2563eb', bg: '#eff6ff' },
	{ type: 'task',    label: 'Task',    color: '#7c3aed', bg: '#f5f3ff' },
];

function ActivityStatCards( { byType = [], total = 0 } ) {
	// Build a lookup: type → count
	const map = {};
	byType.forEach( ( d ) => { map[ d.type ] = d.count; } );

	// Only show types that appear in catalog + any unknown types that have data
	const catalogTypes = ACT_TYPE_CATALOG.map( ( c ) => c.type );
	const extraTypes   = byType
		.filter( ( d ) => ! catalogTypes.includes( d.type ) )
		.map( ( d ) => ( { type: d.type, label: d.type, color: '#64748b', bg: '#f1f5f9' } ) );
	const allCols = [ ...ACT_TYPE_CATALOG, ...extraTypes ];

	if ( total === 0 ) {
		return (
			<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 24, padding: '12px 16px', background: '#f8fafc', borderRadius: 10, fontSize: 12, color: '#94a3b8', alignItems: 'center' } }>
				<MessageSquarePlus size={ 14 } style={ { color: '#cbd5e1' } } />
				Chưa có activity nào được ghi nhận. Nhấn vào cột Activity trong danh sách để bắt đầu.
			</div>
		);
	}

	return (
		<div style={ { marginBottom: 24 } }>
			<div style={ { fontSize: 11, fontWeight: 600, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.05em', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 } }>
				<MessageSquarePlus size={ 13 } style={ { color: '#7c3aed' } } />
				Activities — { total } tổng
			</div>
			<div style={ { display: 'flex', gap: 8, flexWrap: 'wrap' } }>
				{ allCols.map( ( c ) => {
					const Icon  = ACTIVITY_ICONS[ c.type ] || StickyNote;
					const count = map[ c.type ] || 0;
					const pct   = total ? Math.round( ( count / total ) * 100 ) : 0;
					return (
						<div key={ c.type } style={ {
							background: c.bg, borderRadius: 12, padding: '14px 18px',
							minWidth: 100, flex: '1 1 auto',
							display: 'flex', flexDirection: 'column', gap: 6,
							opacity: count === 0 ? 0.45 : 1,
						} }>
							<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
								<Icon size={ 14 } style={ { color: c.color } } />
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em' } }>{ c.label }</span>
							</div>
							<div style={ { fontSize: 26, fontWeight: 800, color: c.color, lineHeight: 1 } }>{ count }</div>
							<div style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
								<div style={ { flex: 1, background: 'rgba(0,0,0,0.07)', borderRadius: 3, height: 4, overflow: 'hidden' } }>
									<div style={ { width: pct + '%', background: c.color, height: '100%', borderRadius: 3, transition: 'width 0.5s' } } />
								</div>
								<span style={ { fontSize: 10, color: '#94a3b8', flexShrink: 0 } }>{ pct }%</span>
							</div>
						</div>
					);
				} ) }
			</div>
		</div>
	);
}

function StatCard( { label, value, color = '#6366f1', bg = '#eef2ff' } ) {
	return (
		<div style={ { background: bg, borderRadius: 12, padding: '16px 20px', minWidth: 100, flex: 1 } }>
			<div style={ { fontSize: 11, color: '#64748b', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4 } }>{ label }</div>
			<div style={ { fontSize: 28, fontWeight: 800, color } }>{ value.toLocaleString() }</div>
		</div>
	);
}

function HBarChart( { data, maxVal, colorFn } ) {
	if ( ! data.length ) { return <div style={ { color: '#94a3b8', fontSize: 12 } }>Không có dữ liệu.</div>; }
	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 6 } }>
			{ data.map( ( d, i ) => (
				<div key={ i } style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
					<div style={ { width: 130, fontSize: 11, color: '#64748b', textAlign: 'right', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flexShrink: 0 } }
						title={ d.label }>{ d.label }</div>
					<div style={ { flex: 1, background: '#f1f5f9', borderRadius: 4, height: 16, overflow: 'hidden' } }>
						<div style={ { width: maxVal ? Math.max( 2, ( d.value / maxVal ) * 100 ) + '%' : '0%', background: colorFn ? colorFn( i ) : '#6366f1', height: '100%', borderRadius: 4, transition: 'width 0.4s' } } />
					</div>
					<div style={ { width: 40, fontSize: 11, color: '#334155', fontWeight: 600, textAlign: 'right', flexShrink: 0 } }>{ d.value }</div>
				</div>
			) ) }
		</div>
	);
}

function VBarChart( { data } ) {
	if ( ! data.length ) { return <div style={ { color: '#94a3b8', fontSize: 12 } }>Không có dữ liệu.</div>; }
	const maxV = Math.max( ...data.map( ( d ) => d.total || 0 ), 1 );
	const BAR_H = 120;
	return (
		<div style={ { display: 'flex', alignItems: 'flex-end', gap: 3, overflowX: 'auto', paddingBottom: 4, minHeight: BAR_H + 32 } }>
			{ data.map( ( d, i ) => (
				<div key={ i } style={ { display: 'flex', flexDirection: 'column', alignItems: 'center', minWidth: 28, flex: '0 0 auto' } }>
					<div style={ { fontSize: 9, color: '#64748b', marginBottom: 2, whiteSpace: 'nowrap' } }>{ d.total > 0 ? d.total : '' }</div>
					<div
						title={ d.date + ': ' + d.total }
						style={ {
							width: 22,
							height: Math.max( 3, ( ( d.total || 0 ) / maxV ) * BAR_H ) + 'px',
							background: d.created > 0 ? '#6366f1' : ( d.updated > 0 ? '#3b82f6' : '#e2e8f0' ),
							borderRadius: '3px 3px 0 0',
							transition: 'height 0.3s',
						} }
					/>
					<div style={ { fontSize: 8, color: '#94a3b8', marginTop: 2, writing: 'horizontal-tb' } }>
						{ d.date ? d.date.slice( 5 ) : '' }
					</div>
				</div>
			) ) }
		</div>
	);
}

function exportDashboardAsPdf( ref ) {
	if ( ! ref.current ) { return; }
	const printWin = window.open( '', '_blank' );
	printWin.document.write( `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Submissions Dashboard</title>
<style>body{font-family:Arial,sans-serif;padding:20px;color:#1e293b}
h1{font-size:20px;margin-bottom:16px}
.card{display:inline-block;background:#eef2ff;border-radius:10px;padding:14px 20px;margin:4px;vertical-align:top;min-width:100px}
.card-label{font-size:10px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:4px}
.card-val{font-size:26px;font-weight:800;color:#6366f1}
table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
th{background:#f8fafc;padding:6px 10px;text-align:left;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600}
td{padding:6px 10px;border-bottom:1px solid #f1f5f9}
@media print{button{display:none}}
</style></head><body>` );
	printWin.document.write( ref.current.innerHTML );
	printWin.document.write( '</body></html>' );
	printWin.document.close();
	printWin.focus();
	printWin.print();
}

function exportDashboardAsWord( ref ) {
	if ( ! ref.current ) { return; }
	const html = `<!DOCTYPE html>
<html xmlns:o='urn:schemas-microsoft-com:office:office'
      xmlns:w='urn:schemas-microsoft-com:office:word'
      xmlns='http://www.w3.org/TR/REC-html40'>
<head><meta charset="utf-8"><title>Submissions Dashboard</title>
<style>body{font-family:Arial,sans-serif;padding:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:6px 10px}</style>
</head><body>${ ref.current.innerHTML }</body></html>`;
	downloadBlob( html, 'submissions_dashboard_' + new Date().toISOString().slice( 0, 10 ) + '.doc', 'application/msword' );
}

// ── [2026-06-30 Johnny Chu] PHASE-0.46 — Status color lookup for dashboards
const STATUS_COLOR_MAP = {
	new:           { bg: '#eff6ff', color: '#2563eb', label: 'Mới' },
	contacted:     { bg: '#f0fdf4', color: '#16a34a', label: 'Đã liên lạc' },
	qualified:     { bg: '#fefce8', color: '#ca8a04', label: 'Đủ ĐK' },
	proposal_sent: { bg: '#fff7ed', color: '#c2410c', label: 'Báo giá' },
	negotiating:   { bg: '#fdf4ff', color: '#9333ea', label: 'Đàm phán' },
	closed_won:    { bg: '#f0fdf4', color: '#15803d', label: 'Thành công' },
	closed_lost:   { bg: '#fef2f2', color: '#dc2626', label: 'Thất bại' },
	invalid:       { bg: '#f8fafc', color: '#64748b', label: 'Không HLệ' },
};
function statusMeta( s ) {
	return STATUS_COLOR_MAP[ s ] || { bg: '#f1f5f9', color: '#64748b', label: s || 'Chưa rõ' };
}

// ── [2026-06-30 Johnny Chu] PHASE-0.46 — KpiCard helper
function KpiCard( { label, value, bg, color, icon: Icon } ) {
	return (
		<div style={ { background: bg || '#f8fafc', borderRadius: 12, padding: '14px 18px', flex: 1, minWidth: 120, display: 'flex', flexDirection: 'column', gap: 4 } }>
			{ Icon && <Icon size={ 16 } style={ { color: color || '#64748b', marginBottom: 2 } } /> }
			<div style={ { fontSize: 22, fontWeight: 800, color: color || '#334155', lineHeight: 1 } }>{ value }</div>
			<div style={ { fontSize: 11, color: '#64748b', fontWeight: 500 } }>{ label }</div>
		</div>
	);
}

// ── [2026-06-30 Johnny Chu] PHASE-0.46 — TeamWorkDashboard (manager-only)
const CHART_COLORS = [ '#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#f97316', '#14b8a6' ];
function TeamWorkDashboard() {
	const [ from, setFrom ] = useState( () => {
		const d = new Date(); d.setDate( d.getDate() - 30 );
		return d.toISOString().slice( 0, 10 );
	} );
	const [ to, setTo ] = useState( () => new Date().toISOString().slice( 0, 10 ) );

	const { data, isFetching } = useGetTeamWorkStatsQuery( { from, to } );
	const agents   = data?.agents   || [];
	const totals   = data?.totals   || { total: 0, assigned: 0, unassigned: 0 };
	const byStatus = data?.by_status || [];

	// Build bar chart data: one bar per agent, stacked by status
	const STATUS_KEYS = [ 'new', 'contacted', 'qualified', 'proposal_sent', 'negotiating', 'closed_won', 'closed_lost', 'invalid' ];
	const barData = agents.slice( 0, 15 ).map( ( a ) => {
		const row = { name: ( a.display_name || 'Chưa giao' ).split( ' ' ).slice( -1 )[ 0 ] };
		STATUS_KEYS.forEach( ( s ) => { row[ s ] = a.by_status[ s ] || 0; } );
		row._total = a.total;
		return row;
	} );

	// Pie: by overall status
	const pieData = byStatus.map( ( d ) => ( { name: statusMeta( d.status ).label, value: d.cnt, status: d.status } ) );

	return (
		<div>
			{ /* Date range pickers */ }
			<div style={ { display: 'flex', gap: 8, alignItems: 'center', marginBottom: 18, flexWrap: 'wrap' } }>
				<label style={ { fontSize: 12, color: '#64748b' } }>Từ</label>
				<input type="date" value={ from } onChange={ ( e ) => setFrom( e.target.value ) }
					style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
				<label style={ { fontSize: 12, color: '#64748b' } }>Đến</label>
				<input type="date" value={ to } onChange={ ( e ) => setTo( e.target.value ) }
					style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
				{ isFetching && <span style={ { fontSize: 12, color: '#94a3b8' } }>Đang tải...</span> }
			</div>

			{ /* KPI cards */ }
			<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 20 } }>
				<KpiCard label="Tổng submissions" value={ totals.total }     bg="#eef2ff" color="#6366f1" icon={ BarChart2 } />
				<KpiCard label="Đã giao việc"      value={ totals.assigned }  bg="#f0fdf4" color="#16a34a" icon={ UserCheck } />
				<KpiCard label="Chưa giao"         value={ totals.unassigned } bg="#fff7ed" color="#ea580c" icon={ Users } />
				<KpiCard label="Số nhân viên"      value={ agents.length }    bg="#f5f3ff" color="#7c3aed" icon={ Award } />
			</div>

			{ /* Bar chart: per agent */ }
			{ barData.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>📊 Submissions theo nhân viên</div>
					<ResponsiveContainer width="100%" height={ 260 }>
						<BarChart data={ barData } layout="vertical" margin={ { left: 60, right: 20, top: 4, bottom: 4 } }>
							<CartesianGrid strokeDasharray="3 3" horizontal={ false } />
							<XAxis type="number" tick={ { fontSize: 11 } } />
							<YAxis type="category" dataKey="name" tick={ { fontSize: 11 } } width={ 60 } />
							<Tooltip formatter={ ( v, n ) => [ v, statusMeta( n ).label ] } />
							<Legend formatter={ ( v ) => statusMeta( v ).label } wrapperStyle={ { fontSize: 11 } } />
							{ STATUS_KEYS.map( ( s, i ) => (
								<Bar key={ s } dataKey={ s } stackId="a" fill={ statusMeta( s ).color } name={ s } />
							) ) }
						</BarChart>
					</ResponsiveContainer>
				</div>
			) }

			{ /* Pie chart: overall by status */ }
			{ pieData.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>🥧 Phân bổ trạng thái toàn nhóm</div>
					<div style={ { display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' } }>
						<ResponsiveContainer width={ 200 } height={ 200 }>
							<PieChart>
								<Pie data={ pieData } dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={ 80 } label={ false }>
									{ pieData.map( ( d, i ) => <Cell key={ d.status } fill={ statusMeta( d.status ).color } /> ) }
								</Pie>
								<Tooltip formatter={ ( v, n ) => [ v, n ] } />
							</PieChart>
						</ResponsiveContainer>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 6 } }>
							{ pieData.map( ( d ) => {
								const m = statusMeta( d.status );
								return (
									<div key={ d.status } style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 } }>
										<div style={ { width: 10, height: 10, borderRadius: 2, background: m.color, flexShrink: 0 } } />
										<span style={ { color: '#64748b' } }>{ m.label }</span>
										<span style={ { fontWeight: 700, color: '#334155', marginLeft: 'auto', paddingLeft: 8 } }>{ d.value }</span>
									</div>
								);
							} ) }
						</div>
					</div>
				</div>
			) }

			{ /* Agent table */ }
			{ agents.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>👥 Chi tiết theo nhân viên</div>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
						<thead>
							<tr style={ { background: '#f8fafc' } }>
								<th style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0' } }>Nhân viên</th>
								<th style={ { padding: '7px 10px', textAlign: 'right', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0' } }>Tổng</th>
								{ STATUS_KEYS.map( ( s ) => (
									<th key={ s } style={ { padding: '7px 6px', textAlign: 'right', color: statusMeta( s ).color, fontWeight: 600, borderBottom: '1px solid #e2e8f0', whiteSpace: 'nowrap' } }>
										{ statusMeta( s ).label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ agents.map( ( a ) => (
								<tr key={ a.user_id || 'unassigned' } style={ { borderBottom: '1px solid #f1f5f9' } }>
									<td style={ { padding: '7px 10px', color: '#334155', fontWeight: 500 } }>{ a.display_name || 'Chưa giao' }</td>
									<td style={ { padding: '7px 10px', textAlign: 'right', fontWeight: 700, color: '#6366f1' } }>{ a.total }</td>
									{ STATUS_KEYS.map( ( s ) => (
										<td key={ s } style={ { padding: '7px 6px', textAlign: 'right', color: ( a.by_status[ s ] || 0 ) > 0 ? statusMeta( s ).color : '#cbd5e1' } }>
											{ a.by_status[ s ] || 0 }
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
			{ agents.length === 0 && ! isFetching && (
				<div style={ { color: '#94a3b8', fontSize: 13, textAlign: 'center', padding: 40 } }>Chưa có dữ liệu phân việc trong khoảng thời gian này.</div>
			) }
		</div>
	);
}

// ── [2026-06-30 Johnny Chu] PHASE-0.46 — MyWorkDashboard (all users, filtered by current user)
function MyWorkDashboard() {
	const [ from, setFrom ] = useState( () => {
		const d = new Date(); d.setDate( d.getDate() - 30 );
		return d.toISOString().slice( 0, 10 );
	} );
	const [ to, setTo ] = useState( () => new Date().toISOString().slice( 0, 10 ) );

	const { data, isFetching } = useGetMyWorkStatsQuery( { from, to } );
	const byStatus = data?.by_status || [];
	const totals   = data?.totals    || { total: 0 };
	const recent   = data?.recent    || [];

	// Build summary KPIs from by_status
	const statusMap = {};
	byStatus.forEach( ( d ) => { statusMap[ d.status ] = d.cnt; } );
	const inProgress = ( statusMap.contacted || 0 ) + ( statusMap.qualified || 0 ) + ( statusMap.proposal_sent || 0 ) + ( statusMap.negotiating || 0 );
	const done       = ( statusMap.closed_won || 0 ) + ( statusMap.closed_lost || 0 ) + ( statusMap.invalid || 0 );

	const pieData = byStatus
		.filter( ( d ) => d.cnt > 0 )
		.map( ( d ) => ( { name: statusMeta( d.status ).label, value: d.cnt, status: d.status } ) );

	const formatDate = ( s ) => ( s ? s.slice( 0, 10 ) : '' );

	return (
		<div>
			{ /* Date range */ }
			<div style={ { display: 'flex', gap: 8, alignItems: 'center', marginBottom: 18, flexWrap: 'wrap' } }>
				<label style={ { fontSize: 12, color: '#64748b' } }>Từ</label>
				<input type="date" value={ from } onChange={ ( e ) => setFrom( e.target.value ) }
					style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
				<label style={ { fontSize: 12, color: '#64748b' } }>Đến</label>
				<input type="date" value={ to } onChange={ ( e ) => setTo( e.target.value ) }
					style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 8px' } } />
				{ isFetching && <span style={ { fontSize: 12, color: '#94a3b8' } }>Đang tải...</span> }
			</div>

			{ /* KPI cards */ }
			<div style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 20 } }>
				<KpiCard label="Tổng của tôi"     value={ totals.total }        bg="#eef2ff" color="#6366f1" icon={ CheckCircle2 } />
				<KpiCard label="Mới"               value={ statusMap.new || 0 } bg="#eff6ff" color="#2563eb" icon={ TrendingUp } />
				<KpiCard label="Đang xử lý"       value={ inProgress }          bg="#fefce8" color="#ca8a04" icon={ Clock } />
				<KpiCard label="Đã đóng"          value={ done }                bg="#f0fdf4" color="#16a34a" icon={ CheckSquare } />
			</div>

			{ /* Pie chart */ }
			{ pieData.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>🥧 Công việc của tôi theo trạng thái</div>
					<div style={ { display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' } }>
						<ResponsiveContainer width={ 200 } height={ 200 }>
							<PieChart>
								<Pie data={ pieData } dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={ 80 } label={ false }>
									{ pieData.map( ( d ) => <Cell key={ d.status } fill={ statusMeta( d.status ).color } /> ) }
								</Pie>
								<Tooltip formatter={ ( v, n ) => [ v, n ] } />
							</PieChart>
						</ResponsiveContainer>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 6 } }>
							{ pieData.map( ( d ) => {
								const m = statusMeta( d.status );
								return (
									<div key={ d.status } style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 } }>
										<div style={ { width: 10, height: 10, borderRadius: 2, background: m.color, flexShrink: 0 } } />
										<span style={ { color: '#64748b' } }>{ m.label }</span>
										<span style={ { fontWeight: 700, color: '#334155', marginLeft: 'auto', paddingLeft: 8 } }>{ d.value }</span>
									</div>
								);
							} ) }
						</div>
					</div>
				</div>
			) }

			{ /* Bar chart by status */ }
			{ pieData.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>📊 Chi tiết theo trạng thái</div>
					<div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
						{ byStatus.filter( ( d ) => d.cnt > 0 ).map( ( d ) => {
							const m   = statusMeta( d.status );
							const pct = totals.total ? Math.max( 3, ( d.cnt / totals.total ) * 100 ) : 0;
							return (
								<div key={ d.status } style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
									<div style={ { width: 70, fontSize: 11, color: '#64748b', textAlign: 'right', flexShrink: 0 } }>{ m.label }</div>
									<div style={ { flex: 1, background: '#f1f5f9', borderRadius: 4, height: 16, overflow: 'hidden' } }>
										<div style={ { width: pct + '%', background: m.color, height: '100%', borderRadius: 4, transition: 'width 0.4s' } } />
									</div>
									<div style={ { width: 28, fontSize: 12, fontWeight: 700, color: '#334155', textAlign: 'right', flexShrink: 0 } }>{ d.cnt }</div>
								</div>
							);
						} ) }
					</div>
				</div>
			) }

			{ /* Recent 10 assignments */ }
			{ recent.length > 0 && (
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>📋 10 submissions gần nhất của tôi</div>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
						<thead>
							<tr style={ { background: '#f8fafc' } }>
								{ [ 'Ngày', 'Khách hàng', 'Email/Điện thoại', 'Trạng thái' ].map( ( h ) => (
									<th key={ h } style={ { padding: '7px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0', fontSize: 11 } }>{ h }</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ recent.map( ( r ) => {
								const m = statusMeta( r.follow_status );
								return (
									<tr key={ r.id } style={ { borderBottom: '1px solid #f1f5f9' } }>
										<td style={ { padding: '7px 10px', color: '#64748b', whiteSpace: 'nowrap' } }>{ formatDate( r.submitted_at ) }</td>
										<td style={ { padding: '7px 10px', color: '#334155' } }>{ r.name || '—' }</td>
										<td style={ { padding: '7px 10px', color: '#64748b' } }>{ r.email || r.phone || '—' }</td>
										<td style={ { padding: '7px 10px' } }>
											<span style={ { background: m.bg, color: m.color, padding: '2px 8px', borderRadius: 12, fontSize: 11, fontWeight: 600 } }>{ m.label }</span>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
			{ totals.total === 0 && ! isFetching && (
				<div style={ { color: '#94a3b8', fontSize: 13, textAlign: 'center', padding: 40 } }>Chưa có submissions nào được giao cho bạn trong khoảng thời gian này.</div>
			) }
		</div>
	);
}

function SubmissionsDashboard( { formId } ) {
	// [2026-06-30 Johnny Chu] PHASE-0.46 — sub-tabs: overview / team / mine
	const [ dashView, setDashView ] = useState( 'overview' );

	const [ days, setDays ] = useState( 30 );
	const dashRef = useRef( null );

	const { data: stats, isFetching } = useGetCf7SubmissionsStatsQuery( { days, form_id: formId || 0 } );
	// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity type breakdown
	const { data: actStats } = useGetSubmissionActivityStatsQuery();

	const totals = stats?.totals || { total: 0, created: 0, updated: 0, skipped: 0, error: 0 };
	const byDate = stats?.by_date || [];
	const byForm = stats?.by_form || [];

	// [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — activity breakdown
	const actByType = actStats?.by_type || [];
	const actTotal  = actStats?.total || 0;

	const actionColors = [ '#16a34a', '#2563eb', '#64748b', '#dc2626' ];
	const actionData   = [
		{ label: 'Tạo mới',  value: totals.created },
		{ label: 'Cập nhật', value: totals.updated },
		{ label: 'Bỏ qua',   value: totals.skipped },
		{ label: 'Lỗi',      value: totals.error },
	].filter( ( d ) => d.value > 0 );

	const formData = byForm.map( ( f ) => ( { label: f.form_title || ( 'Form #' + f.form_id ), value: f.total } ) )
		.sort( ( a, b ) => b.value - a.value )
		.slice( 0, 10 );
	const maxFormVal = formData.length ? formData[ 0 ].value : 1;

	return (
		<div>
			{ /* [2026-06-30 Johnny Chu] PHASE-0.46 — Dashboard sub-tab switcher */ }
			<div style={ { display: 'flex', gap: 6, marginBottom: 20, borderBottom: '2px solid #f1f5f9', paddingBottom: 0 } }>
				{ [
					{ id: 'overview', label: '📋 Tổng quan CF7' },
					{ id: 'mine',     label: '🙋 Việc của tôi' },
					...( IS_MANAGER ? [ { id: 'team', label: '👥 Phân việc nhóm' } ] : [] ),
				].map( ( t ) => (
					<button key={ t.id } onClick={ () => setDashView( t.id ) }
						style={ {
							padding: '6px 14px', fontSize: 12, fontWeight: dashView === t.id ? 700 : 400,
							border: 'none', borderBottom: dashView === t.id ? '2px solid #6366f1' : '2px solid transparent',
							background: 'transparent', cursor: 'pointer', color: dashView === t.id ? '#6366f1' : '#64748b',
							marginBottom: -2, borderRadius: 0,
						} }
					>{ t.label }</button>
				) ) }
			</div>

			{ dashView === 'mine' && <MyWorkDashboard /> }
			{ dashView === 'team' && IS_MANAGER && <TeamWorkDashboard /> }
			{ dashView === 'overview' && (
			<div>
			{ /* Controls + Export */ }
			<div style={ { display: 'flex', gap: 10, alignItems: 'center', marginBottom: 20, flexWrap: 'wrap' } }>
				<div style={ { display: 'flex', gap: 4 } }>
					{ [ 7, 14, 30, 90 ].map( ( d ) => (
						<button key={ d } onClick={ () => setDays( d ) }
							style={ { padding: '4px 12px', fontSize: 12, borderRadius: 20, border: 'none', cursor: 'pointer', background: days === d ? '#6366f1' : '#f1f5f9', color: days === d ? '#fff' : '#64748b', fontWeight: days === d ? 700 : 400 } }>
							{ d } ngày
						</button>
					) ) }
				</div>
				<div style={ { marginLeft: 'auto', display: 'flex', gap: 8 } }>
					<Button variant="ghost" onClick={ () => exportDashboardAsPdf( dashRef ) }
						style={ { fontSize: 12, display: 'flex', alignItems: 'center', gap: 5 } }>
						<Printer size={ 13 } /> Xuất PDF
					</Button>
					<Button variant="ghost" onClick={ () => exportDashboardAsWord( dashRef ) }
						style={ { fontSize: 12, display: 'flex', alignItems: 'center', gap: 5 } }>
						<FileText size={ 13 } /> Xuất Word
					</Button>
				</div>
			</div>

			{ isFetching && <div style={ { color: '#94a3b8', fontSize: 13, marginBottom: 12 } }>Đang tải dữ liệu...</div> }

			<div ref={ dashRef }>
				{ /* ── Summary Cards — Submissions ── */ }
				<div id="dash-summary" style={ { display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 12 } }>
					<StatCard label="Tổng submissions" value={ totals.total }   color="#6366f1" bg="#eef2ff" />
					<StatCard label="Tạo mới"          value={ totals.created } color="#16a34a" bg="#f0fdf4" />
					<StatCard label="Cập nhật"         value={ totals.updated } color="#2563eb" bg="#eff6ff" />
					<StatCard label="Bỏ qua"           value={ totals.skipped } color="#64748b" bg="#f8fafc" />
					<StatCard label="Lỗi"              value={ totals.error }   color="#dc2626" bg="#fef2f2" />
				</div>

				{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY — per-type activity stat cards */ }
				<ActivityStatCards byType={ actByType } total={ actTotal } />

				{ /* ── By Date chart ── */ }
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>
						📅 Submissions { days } ngày gần đây
					</div>
					{ byDate.length === 0
						? <div style={ { color: '#94a3b8', fontSize: 12 } }>Không có dữ liệu trong khoảng này.</div>
						: <VBarChart data={ byDate } /> }
				</div>

				{ /* ── By Form chart ── */ }
				{ byForm.length > 0 && (
					<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
						<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>
							📝 Theo Form (top 10)
						</div>
						<HBarChart data={ formData } maxVal={ maxFormVal } colorFn={ () => '#f59e0b' } />
					</div>
				) }

				{ /* ── By Action chart ── */ }
				{ actionData.length > 0 && (
					<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
						<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>
							🏷 Theo CRM Action
						</div>
						<HBarChart data={ actionData } maxVal={ Math.max( ...actionData.map( ( d ) => d.value ), 1 ) }
							colorFn={ ( i ) => actionColors[ i ] || '#6366f1' } />
					</div>
				) }

				{ /* ── Activity breakdown chart ── */ }
				{ /* [2026-06-29 Johnny Chu] PHASE-CRM-SUB-ACTIVITY */ }
				<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20, marginBottom: 20 } }>
					<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 8 } }>
						<MessageSquarePlus size={ 15 } style={ { color: '#6366f1' } } />
						Activities theo loại
						{ actTotal > 0 && <span style={ { fontSize: 12, fontWeight: 400, color: '#64748b' } }>· Tổng { actTotal }</span> }
					</div>
					{ actByType.length === 0
						? <div style={ { color: '#94a3b8', fontSize: 12 } }>Chưa có activity nào.</div>
						: (
							<div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
								{ actByType.map( ( d ) => {
									const Icon = ACTIVITY_ICONS[ d.type ] || StickyNote;
									const m = ACTIVITY_META[ d.type ] || { color: '#64748b', bg: '#f1f5f9', label: d.type };
									const pct = actTotal ? Math.max( 2, ( d.count / actTotal ) * 100 ) : 0;
									return (
										<div key={ d.type } style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
											<div style={ { width: 22, height: 22, borderRadius: '50%', background: m.bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 } }>
												<Icon size={ 12 } style={ { color: m.color } } />
											</div>
											<div style={ { width: 60, fontSize: 11, color: '#64748b', textAlign: 'right', flexShrink: 0 } }>{ m.label }</div>
											<div style={ { flex: 1, background: '#f1f5f9', borderRadius: 4, height: 14, overflow: 'hidden' } }>
												<div style={ { width: pct + '%', background: m.color, height: '100%', borderRadius: 4, transition: 'width 0.4s' } } />
											</div>
											<div style={ { width: 36, fontSize: 11, fontWeight: 700, color: '#334155', textAlign: 'right', flexShrink: 0 } }>{ d.count }</div>
										</div>
									);
								} ) }
							</div>
						)
					}
				</div>

				{ /* ── By Form table ── */ }
				{ byForm.length > 0 && (
					<div style={ { background: '#fff', border: '1px solid #e2e8f0', borderRadius: 12, padding: 20 } }>
						<div style={ { fontWeight: 700, fontSize: 14, color: '#334155', marginBottom: 12 } }>
							📊 Chi tiết theo Form
						</div>
						<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
							<thead>
								<tr style={ { background: '#f8fafc' } }>
									{ [ 'Form', 'Tổng', 'Tạo mới', 'Cập nhật', 'Bỏ qua', 'Lỗi' ].map( ( h ) => (
										<th key={ h } style={ { padding: '7px 10px', textAlign: h === 'Form' ? 'left' : 'right', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0', fontSize: 11 } }>{ h }</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ byForm.map( ( f ) => (
									<tr key={ f.form_id } style={ { borderBottom: '1px solid #f1f5f9' } }>
										<td style={ { padding: '7px 10px', color: '#334155' } }>{ f.form_title || ( 'Form #' + f.form_id ) }</td>
										<td style={ { padding: '7px 10px', textAlign: 'right', fontWeight: 700, color: '#6366f1' } }>{ f.total }</td>
										<td style={ { padding: '7px 10px', textAlign: 'right', color: '#16a34a' } }>{ f.created }</td>
										<td style={ { padding: '7px 10px', textAlign: 'right', color: '#2563eb' } }>{ f.updated }</td>
										<td style={ { padding: '7px 10px', textAlign: 'right', color: '#64748b' } }>{ f.skipped }</td>
										<td style={ { padding: '7px 10px', textAlign: 'right', color: '#dc2626' } }>{ f.error }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
			</div>
			) }
		</div>
	);
}

