import React, { useEffect, useMemo, useState, useCallback, useRef } from 'react';
import { Settings, Plus, Send, Trash2, Star, Zap, RefreshCw, CheckCircle2, AlertTriangle, Paperclip, X as XIcon, FlaskConical } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import { Badge } from '../../components/ui/card.jsx';
import EmailLogsTab from './EmailLogsTab.jsx';
// [2026-06-24 Johnny Chu] HOTFIX — CF7 tab now uses dedicated component with simplified form
import CF7AutomationTab from './CF7AutomationTab.jsx';
// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — ZNS (Zalo Notification Service) tab
import ZnsTab from './ZnsTab.jsx';
// [2026-06-25 Johnny Chu] PHASE-CRM-ZNS-AUTO — ZNS Automation (per-form config + test)
import ZnsAutomationTab from './ZnsAutomationTab.jsx';
// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — ZNS Trigger Rules (event-driven: Woo, CF7, User,...)
import ZnsTriggerTab from './ZnsTriggerTab.jsx';
import {
	useGetGmailSmtpAccountsQuery,
	useCreateGmailSmtpAccountMutation,
	useUpdateGmailSmtpAccountMutation,
	useDeleteGmailSmtpAccountMutation,
	useTestGmailSmtpAccountMutation,
	usePromoteGmailSmtpAccountMutation,
	useGetEmailEventsQuery,
	useGetEmailEventRulesQuery,
	useCreateEmailEventRuleMutation,
	useUpdateEmailEventRuleMutation,
	useDeleteEmailEventRuleMutation,
	useTestEmailEventRuleMutation,
	useGetCrmSmtpStatusQuery,
	// [2026-06-20 Johnny Chu] HOTFIX — test-send tab
	useTestEmailSmtpSendMutation,
} from '../../redux/api/crmApi.js';

/* ─────────────────────────────────────────────────────────────────────
 * PHASE 0.37.1 — Email client tab redesign
 *  • Bỏ IMAP poller / per-account inbox cũ.
 *  • Cho phép cấu hình Gmail SMTP (App Password) → bridge sang core/smtp
 *    (option `bizcity_smtp_settings`) để wp_mail() chạy site-wide.
 *  • Mapping event → email rule (Checkout, Contact, Lead, Invoice paid…).
 * ───────────────────────────────────────────────────────────────────── */

function GmailSmtpForm( { initial, onSubmit, onCancel, busy } ) {
	const [ d, setD ] = useState( {
		label: '',
		from_email: '',
		from_name: '',
		smtp_host: 'smtp.gmail.com',
		smtp_port: 587,
		smtp_secure: 'tls',
		smtp_user: '',
		is_default: 0,
		is_active: 1,
		...( initial || {} ),
		smtp_pass: '', // never prefill password
	} );
	const set = ( k, v ) => setD( ( p ) => ( { ...p, [ k ]: v } ) );

	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( d ); } }>
			<details className="bzc-guide-details" style={ { marginBottom: 12 } }>
				<summary style={ { cursor: 'pointer', fontWeight: 600 } }>📌 Hướng dẫn tạo Gmail App Password</summary>
				<ol style={ { margin: '8px 0 0', paddingLeft: 18, lineHeight: 1.8, fontSize: 13 } }>
					<li>Bật <strong>Xác minh 2 bước</strong> tại <a href="https://myaccount.google.com/security" target="_blank" rel="noopener noreferrer">myaccount.google.com/security</a>.</li>
					<li>Truy cập <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">myaccount.google.com/apppasswords</a> → chọn app <strong>"Mail"</strong> → nhấn <strong>Tạo</strong>.</li>
					<li>Google hiển thị mật khẩu 16 ký tự — chép và dán vào ô <em>App Password</em> bên dưới. Khoảng trắng được tự động xoá khi lưu.</li>
					<li>Host = <code>smtp.gmail.com</code>, Port = <code>587</code>, Mã hoá = <code>TLS</code>. Username chính là địa chỉ Gmail của bạn.</li>
				</ol>
			</details>

			<div className="bzc-form-grid-2">
				<div>
					<Label>Nhãn</Label>
					<Input value={ d.label } onChange={ ( e ) => set( 'label', e.target.value ) } placeholder="VD: Gmail công ty" />
				</div>
				<div>
					<Label>From Name</Label>
					<Input value={ d.from_name } onChange={ ( e ) => set( 'from_name', e.target.value ) } placeholder="BizCity" />
				</div>
				<div>
					<Label>Gmail (username) *</Label>
					<Input type="email" required value={ d.smtp_user } onChange={ ( e ) => set( 'smtp_user', e.target.value ) } placeholder="you@gmail.com" />
				</div>
				<div>
					<Label>From Email</Label>
					<Input type="email" value={ d.from_email } onChange={ ( e ) => set( 'from_email', e.target.value ) } placeholder="(mặc định = username)" />
				</div>
				<div style={ { gridColumn: '1 / -1' } }>
					<Label>App Password (16 ký tự, có thể có khoảng trắng) { initial?.has_password && <span className="bzc-muted" style={ { fontWeight: 400 } }>— (đã có, để trống để giữ nguyên)</span> }</Label>
					<Input type="password" value={ d.smtp_pass } onChange={ ( e ) => set( 'smtp_pass', e.target.value ) } placeholder={ initial?.has_password ? '••••••••••••••••' : 'xxxx xxxx xxxx xxxx' } autoComplete="new-password" />
				</div>
				<div>
					<Label>SMTP Host</Label>
					<Input value={ d.smtp_host } onChange={ ( e ) => set( 'smtp_host', e.target.value ) } />
				</div>
				<div>
					<Label>Port</Label>
					<Input type="number" value={ d.smtp_port } onChange={ ( e ) => set( 'smtp_port', Number( e.target.value ) ) } />
				</div>
				<div>
					<Label>Bảo mật</Label>
					<select className="bzc-input" value={ d.smtp_secure } onChange={ ( e ) => set( 'smtp_secure', e.target.value ) }>
						<option value="tls">TLS (587)</option>
						<option value="ssl">SSL (465)</option>
					</select>
				</div>
			</div>

			<div style={ { display: 'flex', gap: 14, marginTop: 12, flexWrap: 'wrap' } }>
				<label className="bzc-checkbox"><input type="checkbox" checked={ !! d.is_active } onChange={ ( e ) => set( 'is_active', e.target.checked ? 1 : 0 ) } /> Kích hoạt</label>
				<label className="bzc-checkbox"><input type="checkbox" checked={ !! d.is_default } onChange={ ( e ) => set( 'is_default', e.target.checked ? 1 : 0 ) } /> Đặt làm mặc định</label>
			</div>

			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>{ busy ? 'Đang lưu…' : 'Lưu' }</Button>
			</div>
		</form>
	);
}

// ─── [2026-06-19 Johnny Chu] PHASE-CG-CF7 — Tiny HTML toolbar for body textarea ─────

const TINY_TOOLBAR_BTNS = [
	{ label: 'B',   title: 'Bold',       open: '<strong>', close: '</strong>', btnStyle: { fontWeight: 700 } },
	{ label: 'I',   title: 'Italic',     open: '<em>',     close: '</em>',     btnStyle: { fontStyle: 'italic' } },
	{ label: 'U',   title: 'Underline',  open: '<u>',      close: '</u>',      btnStyle: { textDecoration: 'underline' } },
	{ label: 'H2',  title: 'Heading 2',  open: '<h2>',     close: '</h2>',     btnStyle: {} },
	{ label: 'P',   title: 'Paragraph',  open: '<p>',      close: '</p>',      btnStyle: {} },
	{ label: 'LI',  title: 'List item',  open: '<li>',     close: '</li>',     btnStyle: {} },
	{ label: 'HR',  title: 'Divider',    open: '\n<hr />\n', close: '',         btnStyle: {}, noClose: true },
	{ label: '🔗',  title: 'Link',       open: null,       close: null,        btnStyle: {}, type: 'link' },
];

function TinyToolbar( { targetRef, value, onChange } ) {
	const exec = ( btn ) => {
		const el = targetRef.current;
		if ( ! el ) { return; }
		const start = el.selectionStart || 0;
		const end   = el.selectionEnd   || 0;
		const sel   = value.slice( start, end );
		var insert;
		if ( btn.type === 'link' ) {
			var url = prompt( 'Nhập URL:', 'https://' );
			if ( ! url ) { return; }
			insert = '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + ( sel || url ) + '</a>';
		} else if ( btn.noClose ) {
			insert = btn.open;
		} else {
			insert = btn.open + sel + btn.close;
		}
		const newVal = value.slice( 0, start ) + insert + value.slice( end );
		onChange( newVal );
		requestAnimationFrame( () => {
			el.focus();
			const newPos = start + insert.length;
			el.setSelectionRange( newPos, newPos );
		} );
	};
	return (
		<div style={ {
			display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center',
			padding: '4px 6px', background: '#f8fafc',
			border: '1px solid #e2e8f0', borderBottom: 'none',
			borderRadius: '6px 6px 0 0',
		} }>
			{ TINY_TOOLBAR_BTNS.map( ( btn ) => (
				<button
					key={ btn.title }
					type="button"
					title={ btn.title }
					onMouseDown={ ( e ) => { e.preventDefault(); exec( btn ); } }
					style={ {
						padding: '2px 8px', fontSize: 12, lineHeight: '1.6',
						background: 'none', border: '1px solid transparent',
						borderRadius: 4, cursor: 'pointer', color: '#374151',
						...btn.btnStyle,
					} }
					onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#e0e7ff'; e.currentTarget.style.borderColor = '#c7d2fe'; } }
					onMouseLeave={ ( e ) => { e.currentTarget.style.background = 'none'; e.currentTarget.style.borderColor = 'transparent'; } }
				>
					{ btn.label }
				</button>
			) ) }
		</div>
	);
}

// ─── [2026-06-19 Johnny Chu] PHASE-CG-CF7 — Placeholder pill + inline autocomplete ───

/** Find the start index of an unclosed {{ before cursorPos, or -1. */
function findOpenBrace( value, cursorPos ) {
	const before = value.slice( 0, cursorPos );
	const idx = before.lastIndexOf( '{{' );
	if ( idx === -1 ) { return -1; }
	// If there's a closing }} after the opening {{ → it's already closed
	if ( before.slice( idx + 2 ).includes( '}}' ) ) { return -1; }
	return idx;
}

/** Clickable pill bar — click inserts {{name}} into the focused template field. */
function PlaceholderPills( { placeholders, onInsert } ) {
	if ( ! placeholders?.length ) { return null; }
	return (
		<div style={ { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 5, margin: '4px 0 10px' } }>
			<span style={ { fontSize: 11, color: '#94a3b8', marginRight: 2, whiteSpace: 'nowrap' } }>Chèn biến:</span>
			{ placeholders.map( ( p ) => (
				<button
					key={ p }
					type="button"
					onClick={ () => onInsert( `{{${ p }}}` ) }
					style={ {
						fontSize: 11, fontFamily: 'monospace',
						padding: '2px 9px', borderRadius: 20,
						border: '1px solid #c7d2fe',
						background: '#eef2ff', color: '#4338ca',
						cursor: 'pointer', whiteSpace: 'nowrap',
						transition: 'background 0.12s, border-color 0.12s',
						lineHeight: 1.6,
					} }
					onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#c7d2fe'; } }
					onMouseLeave={ ( e ) => { e.currentTarget.style.background = '#eef2ff'; } }
					title={ `Chèn {{${ p }}} vào trường đang chọn` }
				>
					{ `{{${ p }}}` }
				</button>
			) ) }
		</div>
	);
}

/**
 * TemplateField — input or textarea with:
 *   • focus tracking (onFocusField → parent can insert placeholder at cursor)
 *   • autocomplete dropdown when user types {{
 *
 * [2026-06-19 Johnny Chu] PHASE-CG-CF7
 */
function TemplateField( { as: As = 'input', fieldKey, value, onChange, onFocusField, placeholders, rows, required, placeholder, style, onMount } ) {
	const elRef = useRef( null );
	const closeTimer = useRef( null );

	useEffect( () => {
		if ( onMount && elRef.current ) { onMount( elRef.current ); }
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps
	const [ dd, setDd ] = useState( { open: false, filter: '', braceStart: -1 } );

	const handleChange = ( e ) => {
		const val = e.target.value;
		const cursor = e.target.selectionStart ?? val.length;
		onChange( val );
		const braceIdx = findOpenBrace( val, cursor );
		if ( braceIdx !== -1 ) {
			setDd( { open: true, filter: val.slice( braceIdx + 2, cursor ).toLowerCase(), braceStart: braceIdx } );
		} else {
			setDd( { open: false, filter: '', braceStart: -1 } );
		}
	};

	const handleKeyDown = ( e ) => {
		if ( dd.open && e.key === 'Escape' ) { setDd( { open: false, filter: '', braceStart: -1 } ); }
	};

	// Track cursor movement (arrow / click inside field) to recheck
	const handleSelect = ( e ) => {
		const val = e.target.value;
		const cursor = e.target.selectionStart ?? val.length;
		const braceIdx = findOpenBrace( val, cursor );
		if ( braceIdx !== -1 ) {
			setDd( { open: true, filter: val.slice( braceIdx + 2, cursor ).toLowerCase(), braceStart: braceIdx } );
		} else if ( dd.open ) {
			setDd( { open: false, filter: '', braceStart: -1 } );
		}
	};

	const handleFocus = () => {
		if ( closeTimer.current ) { clearTimeout( closeTimer.current ); }
		if ( onFocusField ) { onFocusField( fieldKey, elRef.current ); }
	};

	const handleBlur = () => {
		closeTimer.current = setTimeout( () => setDd( { open: false, filter: '', braceStart: -1 } ), 150 );
	};

	const pickSuggestion = ( p ) => {
		if ( closeTimer.current ) { clearTimeout( closeTimer.current ); }
		const el = elRef.current;
		if ( ! el ) { return; }
		const cursor = el.selectionStart ?? value.length;
		const { braceStart } = dd;
		const newVal = value.slice( 0, braceStart ) + `{{${ p }}}` + value.slice( cursor );
		onChange( newVal );
		setDd( { open: false, filter: '', braceStart: -1 } );
		requestAnimationFrame( () => {
			el.focus();
			const pos = braceStart + p.length + 4; // {{ + }} = 4 chars
			el.setSelectionRange( pos, pos );
		} );
	};

	const safePlaceholders = Array.isArray( placeholders )
		? placeholders
		: ( placeholders && typeof placeholders === 'object' ? Object.values( placeholders ) : [] );

	const filtered = safePlaceholders
		.map( ( p ) => String( p ) )
		.filter( ( p ) => dd.filter === '' || p.toLowerCase().includes( dd.filter ) );

	const fieldProps = {
		ref: elRef,
		value,
		onChange: handleChange,
		onKeyDown: handleKeyDown,
		onSelect: handleSelect,
		onFocus: handleFocus,
		onBlur: handleBlur,
		required,
		placeholder,
		className: 'bzc-input',
		style,
	};

	return (
		<div style={ { position: 'relative' } }>
			{ As === 'textarea'
				? <textarea { ...fieldProps } rows={ rows } />
				: <input { ...fieldProps } /> }

			{ dd.open && filtered.length > 0 && (
				<div style={ {
					position: 'absolute', top: '100%', left: 0, right: 0,
					background: '#fff', border: '1px solid #c7d2fe',
					borderRadius: 8, boxShadow: '0 6px 24px rgba(99,102,241,0.14)',
					zIndex: 200, maxHeight: 200, overflowY: 'auto',
					marginTop: 2,
				} }>
					{ filtered.map( ( p ) => (
						<button
							key={ p }
							type="button"
							onMouseDown={ ( e ) => { e.preventDefault(); pickSuggestion( p ); } }
							style={ {
								display: 'flex', alignItems: 'center', gap: 4,
								width: '100%', textAlign: 'left',
								padding: '7px 14px', fontSize: 12,
								background: 'none', border: 'none', cursor: 'pointer',
								borderBottom: '1px solid #f1f5f9',
							} }
							onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#eef2ff'; } }
							onMouseLeave={ ( e ) => { e.currentTarget.style.background = ''; } }
						>
							<span style={ { color: '#a5b4fc', fontFamily: 'monospace', fontSize: 11 } }>{'{{'}</span>
							<span style={ { fontFamily: 'monospace', fontWeight: 700, color: '#3730a3' } }>{ p }</span>
							<span style={ { color: '#a5b4fc', fontFamily: 'monospace', fontSize: 11 } }>{'}}'}</span>
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

// ─── RuleForm (updated with PlaceholderPills + TemplateField) ───────────────
function RuleForm( { initial, accounts, events, onSubmit, onCancel, busy } ) {
	const safeEvents = Array.isArray( events ) ? events : [];
	const safeAccounts = Array.isArray( accounts ) ? accounts : [];
	// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — normalise ai_config_json (may arrive as object)
	const [ d, setD ] = useState( () => {
		const normaliseAi = () => {
			const raw = initial && initial.ai_config_json;
			if ( ! raw ) { return ''; }
			if ( typeof raw === 'string' ) { return raw; }
			try { return JSON.stringify( raw, null, 2 ); } catch ( e ) { return ''; }
		};
		return {
			name: '',
			event_key: safeEvents && safeEvents[ 0 ] ? safeEvents[ 0 ].key : '',
			account_id: '',
			is_enabled: 1,
			reply_type: 'template',
			to_template: '',
			cc_template: '',
			bcc_template: '',
			subject_template: '',
			body_template: '',
			attachment_url: '',
			...( initial || {} ),
			ai_config_json: normaliseAi(),
		};
	} );
	const set = ( k, v ) => setD( ( p ) => ( { ...p, [ k ]: v } ) );

	// Track focused template field by key + element to support reliable pill insertion
	const focusedRef = useRef( { key: '', el: null } );
	// Ref to the body textarea DOM node (used by TinyToolbar)
	const bodyRef = useRef( null );

	const handleFocusField = ( key, el ) => { focusedRef.current = { key: key || '', el: el || null }; };

	// Pill click → insert token at cursor of focused field
	const insertPlaceholder = ( token ) => {
		const key = focusedRef.current?.key || '';
		const el = focusedRef.current?.el || null;
		if ( ! key || typeof d[ key ] !== 'string' ) { return; }
		const cur = d[ key ] || '';
		const start = ( el && typeof el.selectionStart === 'number' ) ? el.selectionStart : cur.length;
		const end   = ( el && typeof el.selectionEnd === 'number' ) ? el.selectionEnd : cur.length;
		const newVal = cur.slice( 0, start ) + token + cur.slice( end );
		set( key, newVal );
		requestAnimationFrame( () => {
			if ( ! el ) { return; }
			el.focus();
			const pos = start + token.length;
			el.setSelectionRange( pos, pos );
		} );
	};

	// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — open WP media picker
	// [2026-06-22 Johnny Chu] EMAIL-ATTACH-FIX — set window.__bzMediaPickerOpen so Sheet
	//   onInteractOutside guard (below) prevents Radix from closing the sheet while the
	//   WP media overlay is visible (overlay is outside Sheet DOM → triggers outside-click).
	const openMediaPicker = useCallback( () => {
		if ( ! window.wp || ! window.wp.media ) {
			alert( 'Thư viện media chưa sẵn sàng. Vui lòng thử lại hoặc nhập URL trực tiếp.' );
			return;
		}
		const frame = window.wp.media( {
			title: 'Chọn file đính kèm (PDF / ebook)',
			button: { text: 'Chọn file này' },
			multiple: false,
			library: { type: [ 'application/pdf', 'application/zip', 'image' ] },
		} );
		window.__bzMediaPickerOpen = true;
		frame.on( 'select', () => {
			window.__bzMediaPickerOpen = false;
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			set( 'attachment_url', attachment.url || '' );
		} );
		frame.on( 'escape', () => { window.__bzMediaPickerOpen = false; } );
		frame.on( 'close', () => { window.__bzMediaPickerOpen = false; } );
		frame.open();
	}, [] );

	const evt = useMemo( () => safeEvents.find( ( e ) => e.key === d.event_key ), [ safeEvents, d.event_key ] );
	const placeholders = useMemo( () => {
		const raw = evt?.placeholders;
		if ( Array.isArray( raw ) ) { return raw; }
		if ( raw && typeof raw === 'object' ) { return Object.values( raw ); }
		return [];
	}, [ evt ] );
	// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — detect CF7 event to show reply_type selector
	const isCf7Event = d.event_key === 'cf7_form_submitted' || d.event_key.indexOf( 'cf7_form_' ) === 0;
	const promptPrefix = useMemo( () => {
		if ( ! d.ai_config_json ) { return ''; }
		try { const p = JSON.parse( d.ai_config_json ); return p && typeof p.prompt_prefix === 'string' ? p.prompt_prefix : ''; }
		catch ( e ) { return ''; }
	}, [ d.ai_config_json ] );
	const setPromptPrefix = ( val ) => set( 'ai_config_json', val ? JSON.stringify( { prompt_prefix: val } ) : '' );

	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( d ); } }>
			<div className="bzc-form-grid-2">
				<div style={ { gridColumn: '1 / -1' } }>
					<Label>Tên quy tắc *</Label>
					<Input required value={ d.name } onChange={ ( e ) => set( 'name', e.target.value ) } placeholder="VD: Gửi mail cảm ơn khi đơn hoàn tất" />
				</div>
				<div>
					<Label>Sự kiện kích hoạt *</Label>
					<select className="bzc-input" required value={ d.event_key } onChange={ ( e ) => set( 'event_key', e.target.value ) }>
						{ safeEvents.map( ( e ) => <option key={ e.key } value={ e.key }>{ e.label }</option> ) }
					</select>
				</div>
				<div>
					<Label>Tài khoản gửi (Gmail SMTP)</Label>
					<select className="bzc-input" value={ d.account_id ?? '' } onChange={ ( e ) => set( 'account_id', e.target.value ? Number( e.target.value ) : '' ) }>
						<option value="">— Dùng wp_mail() mặc định —</option>
						{ safeAccounts.map( ( a ) => <option key={ a.id } value={ a.id }>{ a.label || a.from_email || a.smtp_user }</option> ) }
					</select>
				</div>
			</div>

			{ /* Placeholder pills — click to insert into focused field */ }
			{ placeholders.length > 0 && (
				<PlaceholderPills placeholders={ placeholders } onInsert={ insertPlaceholder } />
			) }

			{ /* [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — reply type selector (CF7 only) */ }
			{ isCf7Event && (
				<div style={ { padding: '10px 14px', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, marginBottom: 4 } }>
					<Label style={ { marginBottom: 6, display: 'block' } }>Loại trả lời tự động</Label>
					<div style={ { display: 'flex', gap: 16, marginBottom: d.reply_type === 'ai_reply' ? 12 : 0 } }>
						<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', fontSize: 13 } }>
							<input type="radio" name="reply_type" value="template"
								checked={ d.reply_type !== 'ai_reply' }
								onChange={ () => set( 'reply_type', 'template' ) } />
							<strong>📝 Template cố định</strong>
						</label>
						<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', fontSize: 13 } }>
							<input type="radio" name="reply_type" value="ai_reply"
								checked={ d.reply_type === 'ai_reply' }
								onChange={ () => set( 'reply_type', 'ai_reply' ) } />
							<strong>🤖 AI trả lời tự động</strong>
						</label>
					</div>
					{ d.reply_type === 'ai_reply' && (
						<>
							<div style={ { padding: '8px 12px', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 6, fontSize: 12, color: '#15803d', marginBottom: 10 } }>
								<strong>🤖 Chế độ AI:</strong> AI sẽ đọc toàn bộ thông tin từ form CF7 và tự soạn email trả lời. Hướng dẫn phín dưới đây là tùy chọn.
							</div>
							<Label>Hướng dẫn cho AI (tùy chọn)</Label>
							<Textarea rows={ 4 } value={ promptPrefix } onChange={ ( e ) => setPromptPrefix( e.target.value ) }
								placeholder="VD: Trả lời bằng tiếng Việt lịch sự. Nhắc khách hàng sẽ có phản hồi trong 24h. Ký tên: Đội ngũ CSKH." />
							<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>Dề trống để dùng prompt mặc định (tiếng Việt, giọng CSKH chuyên nghiệp).</p>
						</>
					) }
				</div>
			) }

			<div>
				<Label>To *</Label>
				<TemplateField
					fieldKey="to_template"
					value={ d.to_template }
					onChange={ ( v ) => set( 'to_template', v ) }
					onFocusField={ handleFocusField }
					placeholders={ placeholders }
					required
					placeholder="{{email}} hoặc admin@site.com"
				/>
			</div>
			<div className="bzc-form-grid-2">
				<div>
					<Label>Cc</Label>
					<TemplateField fieldKey="cc_template" value={ d.cc_template } onChange={ ( v ) => set( 'cc_template', v ) } onFocusField={ handleFocusField } placeholders={ placeholders } />
				</div>
				<div>
					<Label>Bcc</Label>
					<TemplateField fieldKey="bcc_template" value={ d.bcc_template } onChange={ ( v ) => set( 'bcc_template', v ) } onFocusField={ handleFocusField } placeholders={ placeholders } />
				</div>
			</div>
			<div>
				<Label>Subject *</Label>
				<TemplateField
					fieldKey="subject_template"
					value={ d.subject_template }
					onChange={ ( v ) => set( 'subject_template', v ) }
					onFocusField={ handleFocusField }
					placeholders={ placeholders }
					required
					placeholder="VD: Cảm ơn bạn đã đăng ký — {{name}}"
				/>
			</div>
			<div>
				<Label>Body (HTML hoặc text)</Label>
				{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7 — tiny editor toolbar */ }
				<TinyToolbar targetRef={ bodyRef } value={ d.body_template } onChange={ ( v ) => set( 'body_template', v ) } />
				<TemplateField
					as="textarea"
					fieldKey="body_template"
					rows={ 10 }
					value={ d.body_template }
					onChange={ ( v ) => set( 'body_template', v ) }
					onFocusField={ handleFocusField }
					placeholders={ placeholders }
					placeholder={ '<p>Chào {{name}},</p>\n<p>Cảm ơn bạn đã đăng ký nhận quà từ {{site_name}}.</p>' }
					onMount={ ( el ) => { bodyRef.current = el; } }
					style={ { borderRadius: '0 0 6px 6px', borderTop: 'none' } }
				/>
			</div>

			{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7 — PDF ebook attachment */ }
			<div>
				<Label>
					<Paperclip size={ 12 } style={ { display: 'inline', marginRight: 4, verticalAlign: 'middle' } } />
					File đính kèm (PDF ebook, tài liệu…)
				</Label>
				<div style={ { display: 'flex', gap: 6, alignItems: 'center' } }>
					<Input
						value={ d.attachment_url }
						onChange={ ( e ) => set( 'attachment_url', e.target.value ) }
						placeholder="https://example.com/wp-content/uploads/ebook.pdf"
						style={ { flex: 1 } }
					/>
					{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7 — always show button; guard is inside openMediaPicker */ }
					<Button type="button" size="sm" onClick={ openMediaPicker } title="Chọn từ thư viện media">
						📂 Media
					</Button>
					{ d.attachment_url && (
						<button
							type="button"
							onClick={ () => set( 'attachment_url', '' ) }
							style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 } }
							title="Xoá file đính kèm"
						>
							<XIcon size={ 14 } />
						</button>
					) }
				</div>
				{ d.attachment_url && (
					<p style={ { fontSize: 11, color: '#64748b', marginTop: 4 } }>
						<a href={ d.attachment_url } target="_blank" rel="noopener noreferrer">{ d.attachment_url }</a>
					</p>
				) }
				<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>
					Để trống nếu không cần đính kèm. URL phải truy cập được từ server.
				</p>
			</div>

			<label className="bzc-checkbox"><input type="checkbox" checked={ !! d.is_enabled } onChange={ ( e ) => set( 'is_enabled', e.target.checked ? 1 : 0 ) } /> Bật quy tắc</label>

			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>{ busy ? 'Đang lưu…' : 'Lưu' }</Button>
			</div>
		</form>
	);
}

// [2026-06-20 Johnny Chu] HOTFIX — map Gmail SMTP error codes → friendly Vietnamese messages + guidance
function parseSmtpError( rawError ) {
	const s = String( rawError || '' );
	const low = s.toLowerCase();
	// ── Daily limit ──────────────────────────────────────────────────────────
	if ( low.includes( '5.4.5' ) || low.includes( 'daily user sending limit' ) || low.includes( 'daily sending limit' ) ) {
		return {
			title: 'Vượt giới hạn gửi trong ngày (550 5.4.5)',
			body: 'Tài khoản Gmail này đã gửi quá nhiều email hôm nay. Gmail miễn phí giới hạn 500 email/ngày; Google Workspace giới hạn 2.000 email/ngày.',
			steps: [
				'Chờ đến 7:00 sáng (0:00 UTC) là quota reset tự động.',
				'Thêm tài khoản Gmail dự phòng trong mục Tài khoản Gmail SMTP → hệ thống failover tự động.',
				'Nâng lên Google Workspace để tăng giới hạn lên 2.000 email/ngày.',
			],
		};
	}
	// ── Auth / App Password ───────────────────────────────────────────────────
	if ( low.includes( '5.7.8' ) || low.includes( 'username and password not accepted' ) || low.includes( 'invalid credentials' ) ) {
		return {
			title: 'Sai mật khẩu ứng dụng (535 5.7.8)',
			body: 'Gmail từ chối xác thực — App Password không đúng hoặc đã bị thu hồi.',
			steps: [
				'Vào myaccount.google.com/apppasswords → tạo lại App Password mới.',
				'Sao chép đúng 16 ký tự (bỏ khoảng trắng) → dán vào ô App Password trong CRM.',
				'Đảm bảo tài khoản Gmail đã bật Xác minh 2 bước.',
			],
		};
	}
	// ── 2FA required ─────────────────────────────────────────────────────────
	if ( low.includes( '5.7.14' ) || low.includes( 'please log in via your web browser' ) || low.includes( 'application-specific password required' ) ) {
		return {
			title: 'Cần dùng App Password (534 5.7.14)',
			body: 'Tài khoản Gmail này đã bật Xác minh 2 bước nhưng đang dùng mật khẩu thường. Gmail yêu cầu App Password riêng cho ứng dụng.',
			steps: [
				'Vào myaccount.google.com/apppasswords.',
				'Tạo App Password mới → chọn loại "Mail" → copy 16 ký tự.',
				'Dán vào ô App Password trong CRM (KHÔNG dùng mật khẩu đăng nhập Gmail).',
			],
		};
	}
	// ── SPF / DMARC / Unauthenticated ─────────────────────────────────────────
	if ( low.includes( '5.7.26' ) || low.includes( 'unauthenticated email' ) || low.includes( 'does not meet minimum authentication' ) ) {
		return {
			title: 'Email bị từ chối vì chưa xác thực domain (550 5.7.26)',
			body: 'Gmail yêu cầu SPF và DMARC hợp lệ kể từ 2024. Domain người gửi chưa có record đầy đủ.',
			steps: [
				'Vào Cloudflare DNS → thêm TXT @ với nội dung: v=spf1 include:_spf.google.com ~all',
				'Thêm TXT _dmarc với nội dung: v=DMARC1; p=none; rua=mailto:you@gmail.com',
				'Chờ 5–15 phút để DNS propagate → gửi lại.',
			],
		};
	}
	// ── Relay denied / not allowed ────────────────────────────────────────────
	if ( low.includes( '5.7.0' ) || low.includes( 'relay access denied' ) || low.includes( 'not allowed to send' ) ) {
		return {
			title: 'Không được phép relay (550 5.7.0)',
			body: 'Gmail không cho phép tài khoản này gửi thay mặt địa chỉ From. Thường do From Email khác với Gmail đã xác thực.',
			steps: [
				'Trong mục Tài khoản Gmail SMTP, đặt From Email = Gmail username (hismartmilk.cskh@gmail.com).',
				'Hoặc trong Gmail Settings → Accounts → thêm "Send mail as" cho địa chỉ domain của bạn.',
			],
		};
	}
	// ── Message size ─────────────────────────────────────────────────────────
	if ( low.includes( '5.3.4' ) || low.includes( 'message size' ) || low.includes( 'exceeds size limit' ) ) {
		return {
			title: 'Email quá lớn (552 5.3.4)',
			body: 'Gmail giới hạn tổng kích thước email (header + body + attachment) tối đa 35 MB.',
			steps: [
				'Giảm kích thước file PDF đính kèm (dùng PDF compressor online như smallpdf.com).',
				'Hoặc lưu file trên Google Drive / S3 → gửi link download thay vì đính kèm trực tiếp.',
			],
		};
	}
	// ── Account disabled ─────────────────────────────────────────────────────
	if ( low.includes( '5.2.1' ) || low.includes( 'account disabled' ) || low.includes( 'suspended' ) ) {
		return {
			title: 'Tài khoản Gmail bị tạm khóa (550 5.2.1)',
			body: 'Google đã tạm khóa tài khoản này do gửi quá nhiều email hoặc vi phạm chính sách.',
			steps: [
				'Đăng nhập Gmail trên trình duyệt → làm theo hướng dẫn mở khóa tài khoản.',
				'Sau khi mở khóa, đợi 24h rồi gửi lại.',
				'Cân nhắc thêm tài khoản dự phòng để tránh downtime.',
			],
		};
	}
	// ── Connection / timeout ─────────────────────────────────────────────────
	if ( low.includes( 'connection' ) && ( low.includes( 'timeout' ) || low.includes( 'refused' ) || low.includes( 'timed out' ) ) ) {
		return {
			title: 'Không kết nối được đến smtp.gmail.com',
			body: 'Server WordPress không thể mở kết nối TCP đến smtp.gmail.com:587. Thường do firewall hosting chặn port outbound.',
			steps: [
				'Liên hệ nhà cung cấp hosting → yêu cầu mở port 587 (STARTTLS) hoặc 465 (SSL) ra ngoài.',
				'Thử đổi Port sang 465 + Bảo mật SSL trong cài đặt tài khoản SMTP.',
				'Nếu hosting chặn hoàn toàn SMTP outbound, cân nhắc dùng API gửi email (SendGrid, Mailgun).',
			],
		};
	}
	// ── Fallback ─────────────────────────────────────────────────────────────
	return null;
}

export default function EmailTab() {
	const { data: accountsRaw = [], isLoading: accLoading, refetch: refetchAcc } = useGetGmailSmtpAccountsQuery();
	const { data: eventsRaw = [] }   = useGetEmailEventsQuery();
	const { data: rulesRaw = [], isLoading: rulesLoading, refetch: refetchRules } = useGetEmailEventRulesQuery();
	const { data: smtpStatus }    = useGetCrmSmtpStatusQuery();

	const accounts = useMemo( () => ( Array.isArray( accountsRaw ) ? accountsRaw : [] ), [ accountsRaw ] );
	const events = useMemo( () => ( Array.isArray( eventsRaw ) ? eventsRaw : [] ), [ eventsRaw ] );
	const rules = useMemo( () => ( Array.isArray( rulesRaw ) ? rulesRaw : [] ), [ rulesRaw ] );

	const [ createAccount, { isLoading: cAcc } ]   = useCreateGmailSmtpAccountMutation();
	const [ updateAccount, { isLoading: uAcc } ]   = useUpdateGmailSmtpAccountMutation();
	const [ deleteAccount ]                        = useDeleteGmailSmtpAccountMutation();
	const [ testAccount,   { isLoading: tAcc } ]   = useTestGmailSmtpAccountMutation();
	const [ promoteAccount ]                       = usePromoteGmailSmtpAccountMutation();

	const [ createRule, { isLoading: cR } ] = useCreateEmailEventRuleMutation();
	const [ updateRule, { isLoading: uR } ] = useUpdateEmailEventRuleMutation();
	const [ deleteRule ]                    = useDeleteEmailEventRuleMutation();
	const [ testRule,   { isLoading: tR } ] = useTestEmailEventRuleMutation();

	const [ accountSheet, setAccountSheet ] = useState( false );
	const [ editingAccount, setEditingAccount ] = useState( null );
	const [ ruleSheet, setRuleSheet ] = useState( false );
	const [ editingRule, setEditingRule ] = useState( null );
	// [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — sub-tab
	const [ activeTab, setActiveTab ] = useState( 'rules' ); // 'rules' | 'logs' | 'debug'

	// [2026-06-20 Johnny Chu] HOTFIX — debug panel state
	const [ debugTo,      setDebugTo      ] = useState( '' );
	const [ debugSubject, setDebugSubject ] = useState( '' );
	const [ debugResult,  setDebugResult  ] = useState( null );
	const [ doDebugSend,  { isLoading: debugSending } ] = useTestEmailSmtpSendMutation();
	const handleDebugSend = async () => {
		setDebugResult( null );
		try {
			const res = await doDebugSend( { to: debugTo, subject: debugSubject || undefined } ).unwrap();
			setDebugResult( { ok: true, data: res } );
		} catch ( err ) {
			const errData = err?.data?.error;
			const errStr = errData
				? ( typeof errData === 'object' ? ( errData.message || JSON.stringify( errData ) ) : String( errData ) )
				: ( err?.data?.message || err?.error || err?.message || 'Lỗi kết nối' );
			setDebugResult( { ok: false, error: errStr } );
		}
	};

	const eventLabel = ( k ) => events.find( ( e ) => e.key === k )?.label || k;

	const handleSaveAccount = async ( data ) => {
		try {
			if ( editingAccount?.id ) {
				const payload = { id: editingAccount.id, ...data };
				if ( ! payload.smtp_pass ) { delete payload.smtp_pass; }
				await updateAccount( payload ).unwrap();
			} else {
				await createAccount( data ).unwrap();
			}
			setAccountSheet( false );
			setEditingAccount( null );
			refetchAcc();
		} catch ( e ) { alert( 'Lưu thất bại: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleTest = async ( a ) => {
		const to = window.prompt( 'Gửi email kiểm tra đến địa chỉ:', a.smtp_user || '' );
		if ( ! to ) { return; }
		try {
			const res = await testAccount( { id: a.id, to } ).unwrap();
			if ( res?.ok ) { alert( '✅ Gửi thành công! Kiểm tra hộp thư ' + to ); }
			else { alert( '❌ Lỗi: ' + ( res?.error || 'unknown' ) ); }
			refetchAcc();
		} catch ( e ) { alert( 'Lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handlePromote = async ( a ) => {
		if ( ! window.confirm( 'Đặt "' + ( a.label || a.smtp_user ) + '" làm SMTP mặc định của toàn site (wp_mail)?' ) ) { return; }
		try {
			await promoteAccount( a.id ).unwrap();
			alert( '✅ Đã set làm SMTP toàn site. Mọi wp_mail() sẽ dùng tài khoản này.' );
		} catch ( e ) { alert( 'Lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleDeleteAcc = async ( id ) => {
		if ( ! window.confirm( 'Xoá tài khoản này?' ) ) { return; }
		try { await deleteAccount( id ).unwrap(); refetchAcc(); }
		catch ( e ) { alert( 'Lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleSaveRule = async ( data ) => {
		try {
			if ( editingRule?.id ) {
				await updateRule( { id: editingRule.id, ...data } ).unwrap();
			} else {
				await createRule( data ).unwrap();
			}
			setRuleSheet( false );
			setEditingRule( null );
			refetchRules();
		} catch ( e ) { alert( 'Lưu thất bại: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — test rule state (modal prompt)
	const [ testRuleModal, setTestRuleModal ] = useState( null ); // { rule, testTo, sending, result }

	const openTestModal = ( r ) => setTestRuleModal( { rule: r, testTo: '', sending: false, result: null } );

	const handleTestRule = async () => {
		if ( ! testRuleModal ) { return; }
		const { rule, testTo } = testRuleModal;
		const to = testTo.trim();
		if ( ! to || ! /^[^@]+@[^@]+\.[^@]+$/.test( to ) ) {
			setTestRuleModal( ( p ) => ( { ...p, result: { ok: false, error: 'Vui lòng nhập địa chỉ email hợp lệ.' } } ) );
			return;
		}
		setTestRuleModal( ( p ) => ( { ...p, sending: true, result: null } ) );
		try {
			const res = await testRule( { id: rule.id, ctx: {}, test_to: to } ).unwrap();
			setTestRuleModal( ( p ) => ( { ...p, sending: false, result: res } ) );
			refetchRules();
		} catch ( e ) {
			setTestRuleModal( ( p ) => ( { ...p, sending: false, result: { ok: false, error: e?.data?.error?.message || e.message || 'unknown' } } ) );
		}
	};

	const handleDeleteRule = async ( id ) => {
		if ( ! window.confirm( 'Xoá quy tắc này?' ) ) { return; }
		try { await deleteRule( id ).unwrap(); refetchRules(); }
		catch ( e ) { alert( 'Lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Email &amp; CF7 Rules</h2>
					<p className="bzc-tabpane-subtitle">Cấu hình Gmail SMTP và tự động hoá email theo sự kiện (checkout, contact, lead, invoice paid…).</p>
				</div>
				<div style={ { display: 'flex', gap: 6 } }>
					<Button onClick={ () => { setEditingAccount( null ); setAccountSheet( true ); } }>
						<Settings size={ 12 } /> Cài đặt Gmail SMTP
					</Button>
					<Button variant="primary" disabled={ accounts.length === 0 && ! smtpStatus?.configured } onClick={ () => { setEditingRule( null ); setRuleSheet( true ); } }>
						<Plus size={ 12 } /> Thêm quy tắc
					</Button>
				</div>
			</header>

			{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — sub-tab bar */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '1px solid #e2e8f0', marginBottom: 16 } }>
				{ [ { id: 'rules', label: 'Quy tắc gửi email' }, { id: 'cf7', label: '🤖 CF7 Automation' }, { id: 'logs', label: '📊 Lịch sử & báo cáo' }, { id: 'debug', label: '🧪 Gửi thử / Debug' }, { id: 'zns-auto', label: '🔔 Zalo ZNS Automation' }, { id: 'zns', label: '📊 ZNS Thống kê' }, { id: 'zns-trigger', label: '⚡ ZNS Trigger' } ].map( ( t ) => (
					<button
						key={ t.id }
						onClick={ () => setActiveTab( t.id ) }
						style={ {
							padding: '8px 18px', fontSize: 13, cursor: 'pointer',
							background: 'none', border: 'none', borderBottom: activeTab === t.id ? '2px solid #6366f1' : '2px solid transparent',
							color: activeTab === t.id ? '#4338ca' : '#64748b', fontWeight: activeTab === t.id ? 700 : 400,
							marginBottom: -1,
						} }
					>{ t.label }</button>
				) ) }
			</div>

			{ /* [2026-06-24 Johnny Chu] HOTFIX — replaced inline section with CF7AutomationTab (has simplified CF7RuleForm) */ }
			{ activeTab === 'cf7' && <CF7AutomationTab /> }
			{ activeTab === 'logs' && <EmailLogsTab /> }
{ /* [2026-06-25 Johnny Chu] PHASE-CRM-ZNS-AUTO — Zalo ZNS Automation tab */ }
					{ activeTab === 'zns-auto' && <ZnsAutomationTab /> }
					{ /* [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — Zalo ZNS tab */ }
			{ activeTab === 'zns' && <ZnsTab /> }
			{ /* [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — ZNS Trigger Rules (event-driven automation) */ }
			{ activeTab === 'zns-trigger' && <ZnsTriggerTab /> }

			{ /* [2026-06-20 Johnny Chu] HOTFIX — Debug / test-send panel */ }
			{ activeTab === 'debug' && (
				<div style={ { maxWidth: 600 } }>
					<h4 style={ { fontWeight: 700, fontSize: 14, marginBottom: 6, display: 'flex', alignItems: 'center', gap: 6 } }>
						<FlaskConical size={ 14 } style={ { color: '#6366f1' } } /> Gửi email thử — Debug SMTP
					</h4>
					<p style={ { fontSize: 12, color: '#64748b', marginBottom: 14, lineHeight: 1.6 } }>
						Sử dụng tài khoản Gmail mặc định (hoặc tài khoản active đầu tiên). Gửi email plain-text để xác nhận SMTP đang chạy.
					</p>
					<div style={ { display: 'flex', flexDirection: 'column', gap: 10 } }>
						<div>
							<Label>Email nhận *</Label>
							<Input type="email" placeholder="you@gmail.com" value={ debugTo } onChange={ ( e ) => setDebugTo( e.target.value ) } />
						</div>
						<div>
							<Label>Tiêu đề (tuỳ chọn)</Label>
							<Input type="text" placeholder="Test SMTP delivery..." value={ debugSubject } onChange={ ( e ) => setDebugSubject( e.target.value ) } />
						</div>
						<Button variant="primary" onClick={ handleDebugSend } disabled={ ! debugTo.trim() || debugSending }>
							{ debugSending
								? <><RefreshCw size={ 12 } style={ { marginRight: 4, animation: 'spin 1s linear infinite' } } />Đang gửi…</>
								: <><Send size={ 12 } style={ { marginRight: 4 } } />Gửi test</>
							}
						</Button>
					</div>
					{ debugResult && (
						<div style={ {
							marginTop: 14, padding: '12px 16px', borderRadius: 8, fontSize: 12,
							background: debugResult.ok ? '#f0fdf4' : '#fef2f2',
							border: '1px solid ' + ( debugResult.ok ? '#86efac' : '#fca5a5' ),
						} }>
							{ debugResult.ok ? (
								<>
									<div style={ { fontWeight: 700, color: '#15803d', marginBottom: 8 } }>✅ Gửi thành công (SMTP 250 OK)</div>
									<div style={ { color: '#475569', lineHeight: 1.7 } }>
										<strong>Tại sao email của bạn bị chặn trước đây?</strong><br />
										Đây là nguyên nhân gốc (3 tầng):
										<ol style={ { margin: '6px 0 0 18px', lineHeight: 1.9 } }>
											<li><strong>Envelope mismatch:</strong> <code>from_email = info@vibeyeu.com.vn</code> khác <code>smtp_user = @gmail.com</code>.
											Gmail nhận 250 OK nhưng post-accept filter thấy MAIL FROM không khớp → reject. ❓✅ Đã fix: <code>Sender = smtp_user</code>.</li>
											<li><strong>Thiếu List-Unsubscribe:</strong> Email tỷ lệ có attachment (ebook PDF) + link → Gmail phân loại là <em>bulk marketing</em>. Gmail Bulk Sender Guidelines 2024 yêu cầu header này. ❓✅ Đã fix: thêm <code>List-Unsubscribe + Precedence: bulk</code>.</li>
											<li><strong>DNS cần bổ sung:</strong> SPF record của <code>vibeyeu.com.vn</code> chưa có <code>include:_spf.google.com</code> và chưa có DMARC TXT record.</li>
										</ol>
										<div style={ { marginTop: 8, padding: '8px 12px', background: '#f1f5f9', borderRadius: 6, fontFamily: 'monospace', fontSize: 11 } }>
											<div style={ { marginBottom: 4 } }>✅ DNS viết thêm vào zone file:</div>
											<div>TXT @ &quot;v=spf1 include:_spf.google.com ~all&quot;</div>
											<div>TXT _dmarc &quot;v=DMARC1; p=none; rua=mailto:info@vibeyeu.com.vn&quot;</div>
										</div>
									</div>
								</>
							) : (
								<>
									{ ( () => {
										const parsed = parseSmtpError( debugResult.error );
										if ( parsed ) {
											return (
												<>
													<div style={ { fontWeight: 700, color: '#dc2626', marginBottom: 6 } }>
														❌ { parsed.title }
													</div>
													<div style={ { color: '#475569', marginBottom: 8, lineHeight: 1.6 } }>
														{ parsed.body }
													</div>
													<div style={ { fontWeight: 600, color: '#1e293b', marginBottom: 4 } }>Cách khắc phục:</div>
													<ol style={ { margin: '0 0 0 18px', padding: 0, lineHeight: 2 } }>
														{ parsed.steps.map( ( step, i ) => (
															<li key={ i } style={ { color: '#334155' } }>{ step }</li>
														) ) }
													</ol>
												</>
											);
										}
										return (
											<div style={ { color: '#dc2626', fontWeight: 600 } }>
												❌ { debugResult.error.split( '\n' )[ 0 ] }
											</div>
										);
									} )() }
									{ debugResult.error.includes( '[SMTP LOG]' ) && (
										<pre style={ {
											marginTop: 10, padding: '8px 10px', borderRadius: 6,
											background: '#1e1e2e', color: '#cdd6f4', fontSize: 10,
											overflowX: 'auto', whiteSpace: 'pre-wrap', wordBreak: 'break-all',
											maxHeight: 220, overflowY: 'auto',
										} }>{ debugResult.error.split( '[SMTP LOG]' )[ 1 ].trim() }</pre>
									) }
								</>
							) }
						</div>
					) }
				</div>
			) }

			{ activeTab === 'rules' && <>

			{ /* ──────── Section 1: SMTP accounts ──────── */ }
			<section className="bzc-card" style={ { padding: 14, marginBottom: 16 } }>
				<h3 style={ { marginTop: 0, fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 } }>
					<Settings size={ 14 } /> Tài khoản Gmail SMTP
				</h3>

				{ smtpStatus?.configured && (
					<div style={ { padding: '8px 12px', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 6, fontSize: 12, marginBottom: 10 } }>
						<CheckCircle2 size={ 12 } style={ { display: 'inline', marginRight: 4, verticalAlign: 'middle' } } />
						{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7 — show CRM account email, not global mu-plugin email */ }
						{ smtpStatus.source === 'crm_gmail' ? (
							<>
								<strong>Gmail SMTP đang dùng:</strong>{ ' ' }
								{ smtpStatus.preview?.label && <span style={ { fontWeight: 600 } }>{ smtpStatus.preview.label } — </span> }
								<span style={ { color: '#166534' } }>{ smtpStatus.preview?.user || smtpStatus.preview?.from }</span>
								<span className="bzc-muted" style={ { marginLeft: 8 } }>{ smtpStatus.preview?.host }:{ smtpStatus.preview?.port }</span>
							</>
						) : (
							<>
								<strong>SMTP toàn site:</strong>{ ' ' }
								{ smtpStatus.preview?.host }:{ smtpStatus.preview?.port } · { smtpStatus.preview?.from || smtpStatus.preview?.user }
							</>
						) }
					</div>
				) }
				{ ! smtpStatus?.configured && (
					<div style={ { padding: '8px 12px', background: '#fffbeb', border: '1px solid #fcd34d', borderRadius: 6, fontSize: 12, marginBottom: 10 } }>
						<AlertTriangle size={ 12 } style={ { display: 'inline', marginRight: 4, verticalAlign: 'middle' } } />
						Chưa có tài khoản Gmail SMTP nào. Nhấn <strong>Cài đặt Gmail SMTP</strong> để thêm tài khoản của bạn.
					</div>
				) }

				{ accLoading && <div className="bzc-muted">Đang tải…</div> }
				{ ! accLoading && accounts.length === 0 && (
					<div className="bzc-empty bzc-muted">Chưa có tài khoản. Nhấn <strong>Cài đặt Gmail SMTP</strong> để thêm.</div>
				) }
				{ accounts.length > 0 && (
					<table className="bzc-table" style={ { width: '100%', fontSize: 13 } }>
						<thead>
							<tr>
								<th style={ { textAlign: 'left' } }>Nhãn</th>
								<th style={ { textAlign: 'left' } }>Gmail</th>
								<th style={ { textAlign: 'left' } }>From</th>
								<th>Trạng thái</th>
								<th style={ { textAlign: 'right' } }>Hành động</th>
							</tr>
						</thead>
						<tbody>
							{ accounts.map( ( a ) => (
								<tr key={ a.id } style={ { borderTop: '1px solid #eee' } }>
									<td style={ { padding: '6px 4px' } }>
										{ !! a.is_default && <Star size={ 12 } style={ { color: '#f59e0b', display: 'inline', marginRight: 4 } } /> }
										{ a.label || '—' }
									</td>
									<td>{ a.smtp_user }</td>
									<td>{ a.from_email || a.smtp_user } { a.from_name ? <span className="bzc-muted">({ a.from_name })</span> : null }</td>
									<td style={ { textAlign: 'center' } }>
										{ a.is_active ? <Badge variant="success">Active</Badge> : <Badge variant="muted">Tắt</Badge> }
										{ a.last_test_ok === 1 && <Badge variant="success" style={ { marginLeft: 4 } }>Test ✓</Badge> }
										{ a.last_test_ok === 0 && <Badge variant="warn" style={ { marginLeft: 4 } }>Test ✗</Badge> }
									</td>
									<td style={ { textAlign: 'right' } }>
										<Button size="sm" disabled={ tAcc } onClick={ () => handleTest( a ) } title="Gửi mail kiểm tra"><Send size={ 11 } /></Button>{ ' ' }
										{ ! a.is_default && (
											<Button size="sm" onClick={ () => handlePromote( a ) } title="Đặt làm SMTP toàn site"><Star size={ 11 } /></Button>
										) }{ ' ' }
										<Button size="sm" onClick={ () => { setEditingAccount( a ); setAccountSheet( true ); } }><Settings size={ 11 } /></Button>{ ' ' }
										<Button size="sm" onClick={ () => handleDeleteAcc( a.id ) }><Trash2 size={ 11 } /></Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</section>

			{ /* ──────── Section 2: Email automation rules ──────── */ }
			<section className="bzc-card" style={ { padding: 14 } }>
				<h3 style={ { marginTop: 0, fontSize: 14, display: 'flex', alignItems: 'center', gap: 6 } }>
					<Zap size={ 14 } /> Quy tắc gửi email tự động
				</h3>
				<p className="bzc-muted" style={ { fontSize: 12, marginTop: 0 } }>Mỗi quy tắc gắn 1 sự kiện (checkout, contact, lead, invoice paid, custom). Khi sự kiện xảy ra → template được render với placeholders và gửi qua tài khoản chỉ định.</p>

				{ rulesLoading && <div className="bzc-muted">Đang tải…</div> }
				{ ! rulesLoading && rules.length === 0 && (
					<div className="bzc-empty bzc-muted">Chưa có quy tắc. Nhấn <strong>Thêm quy tắc</strong> để bắt đầu.</div>
				) }
				{ rules.length > 0 && (
					<table className="bzc-table" style={ { width: '100%', fontSize: 13 } }>
						<thead>
							<tr>
								<th style={ { textAlign: 'left' } }>Tên</th>
								<th style={ { textAlign: 'left' } }>Sự kiện</th>
								<th style={ { textAlign: 'left' } }>Tài khoản</th>
								<th>Đính kèm</th>
								<th>Bật</th>
								<th>Đã chạy</th>
								<th style={ { textAlign: 'right' } }>Hành động</th>
							</tr>
						</thead>
						<tbody>
							{ rules.map( ( r ) => {
								const acc = accounts.find( ( a ) => Number( a.id ) === Number( r.account_id ) );
								return (
									<tr key={ r.id } style={ { borderTop: '1px solid #eee' } }>
										<td style={ { padding: '6px 4px' } }>{ r.name }</td>
										<td><code style={ { fontSize: 11 } }>{ eventLabel( r.event_key ) }</code></td>
										<td>{ acc ? ( acc.label || acc.smtp_user ) : <span className="bzc-muted">— wp_mail —</span> }</td>
										{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7 — attachment indicator */ }
										<td style={ { textAlign: 'center' } }>
											{ r.attachment_url
												? <a href={ r.attachment_url } target="_blank" rel="noopener noreferrer" title={ r.attachment_url }><Paperclip size={ 12 } style={ { color: '#6366f1' } } /></a>
												: <span className="bzc-muted">—</span> }
										</td>
										<td style={ { textAlign: 'center' } }>{ r.is_enabled ? <Badge variant="success">ON</Badge> : <Badge variant="muted">OFF</Badge> }</td>
										<td style={ { textAlign: 'center' } }>
											{ r.fire_count || 0 }
											{ r.last_fire_status === 'fail' && <Badge variant="warn" style={ { marginLeft: 4 } }>last:fail</Badge> }
										</td>
										<td style={ { textAlign: 'right' } }>
											<Button size="sm" disabled={ tR } onClick={ () => openTestModal( r ) } title="Test gửi ngay"><Send size={ 11 } /></Button>{ ' ' }
											<Button size="sm" onClick={ () => { setEditingRule( r ); setRuleSheet( true ); } }><Settings size={ 11 } /></Button>{ ' ' }
											<Button size="sm" onClick={ () => handleDeleteRule( r.id ) }><Trash2 size={ 11 } /></Button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				) }
			</section>

			{ /* [2026-06-19 Johnny Chu] PHASE-CG-CF7-LOG — close rules tab fragment */ }
			</> }

			{ /* ──────── Sheet: Gmail SMTP (always mounted — opened from any tab) ──────── */ }
			{ /* [2026-06-24 Johnny Chu] HOTFIX — moved outside activeTab=rules block so CF7 tab can also open it */ }
			<Sheet open={ accountSheet } onOpenChange={ ( v ) => { setAccountSheet( v ); if ( ! v ) { setEditingAccount( null ); } } }>
				<SheetContent className="bzc-sheet-wide">
					<SheetHeader><SheetTitle>{ editingAccount ? 'Sửa tài khoản Gmail' : 'Cài đặt Gmail SMTP' }</SheetTitle></SheetHeader>
					<SheetBody>
						<GmailSmtpForm
							initial={ editingAccount }
							onSubmit={ handleSaveAccount }
							onCancel={ () => { setAccountSheet( false ); setEditingAccount( null ); } }
							busy={ cAcc || uAcc }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* ──────── Sheet: Rule (always mounted — opened from rules tab AND cf7 tab) ──────── */ }
			{ /* [2026-06-24 Johnny Chu] HOTFIX — moved outside activeTab=rules block so CF7 tab can also open it */ }
			<Sheet open={ ruleSheet } onOpenChange={ ( v ) => { setRuleSheet( v ); if ( ! v ) { setEditingRule( null ); } } }>
				{ /* [2026-06-22 Johnny Chu] EMAIL-ATTACH-FIX — prevent Sheet dismiss while WP media picker overlay is active */ }
				<SheetContent className="bzc-sheet-wide" onInteractOutside={ ( e ) => { if ( window.__bzMediaPickerOpen ) { e.preventDefault(); } } }>
					<SheetHeader><SheetTitle>{ editingRule ? 'Sửa quy tắc' : 'Thêm quy tắc gửi mail tự động' }</SheetTitle></SheetHeader>
					<SheetBody>
						<RuleForm
							initial={ editingRule }
							accounts={ accounts }
							events={ events }
							onSubmit={ handleSaveRule }
							onCancel={ () => { setRuleSheet( false ); setEditingRule( null ); } }
							busy={ cR || uR }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* ──────── Modal: Test rule (always mounted) ──────── */ }
			{ /* [2026-06-24 Johnny Chu] HOTFIX — moved outside activeTab=rules block so CF7 tab can also open it */ }
			{ testRuleModal && (
				<div style={ {
					position: 'fixed', inset: 0, zIndex: 9000,
					background: 'rgba(15,23,42,0.45)',
					display: 'flex', alignItems: 'center', justifyContent: 'center',
				} }
					onClick={ ( e ) => { if ( e.target === e.currentTarget ) { setTestRuleModal( null ); } } }
				>
					<div style={ {
						background: '#fff', borderRadius: 12,
						boxShadow: '0 20px 60px rgba(0,0,0,0.22)',
						padding: '28px 32px', width: '100%', maxWidth: 440,
					} }>
						<h3 style={ { margin: '0 0 6px', fontSize: 16 } }>🧪 Test gửi email</h3>
						<p style={ { fontSize: 13, color: '#64748b', margin: '0 0 18px' } }>
							Nhập email nhận thử. Server sẽ render template với dữ liệu mẫu và gửi thật.
						</p>

						{ ! testRuleModal.result ? (
							<>
								<Label>Địa chỉ nhận *</Label>
								<Input
									type="email"
									autoFocus
									value={ testRuleModal.testTo }
									onChange={ ( e ) => setTestRuleModal( ( p ) => ( { ...p, testTo: e.target.value } ) ) }
									onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); handleTestRule(); } } }
									placeholder="you@gmail.com"
									style={ { marginBottom: 16 } }
								/>
								<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
									<Button type="button" onClick={ () => setTestRuleModal( null ) } disabled={ testRuleModal.sending }>Huỷ</Button>
									<Button type="button" variant="primary" onClick={ handleTestRule } disabled={ testRuleModal.sending }>
										{ testRuleModal.sending ? 'Đang gửi…' : <><Send size={ 12 } style={ { marginRight: 4 } } />Gửi test</> }
									</Button>
								</div>
							</>
						) : (
							<>
								{ testRuleModal.result.ok ? (
									<div style={ { padding: '14px 16px', background: '#f0fdf4', border: '1px solid #86efac', borderRadius: 8, marginBottom: 16 } }>
										<CheckCircle2 size={ 14 } style={ { display: 'inline', marginRight: 6, color: '#16a34a', verticalAlign: 'middle' } } />
										<strong style={ { color: '#15803d' } }>Gửi thành công!</strong>
										<span style={ { fontSize: 12, color: '#166534', marginLeft: 6 } }>Kiểm tra hộp thư { testRuleModal.testTo }</span>
									</div>
								) : (
									<div style={ { padding: '14px 16px', background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 8, marginBottom: 16 } }>
										<AlertTriangle size={ 14 } style={ { display: 'inline', marginRight: 6, color: '#dc2626', verticalAlign: 'middle' } } />
										<strong style={ { color: '#dc2626' } }>Gửi thất bại</strong>
										<div style={ { fontSize: 12, color: '#991b1b', marginTop: 4 } }>{ testRuleModal.result.error || testRuleModal.result.status }</div>
										{ ( testRuleModal.result.error === 'wp_mail_returned_false' ) && (
											<div style={ { fontSize: 11, color: '#7f1d1d', marginTop: 6, lineHeight: 1.5 } }>
												💡 <strong>Gợi ý:</strong> Chọn tài khoản <em>Gmail SMTP</em> trong quy tắc thay vì dùng wp_mail() mặc định, hoặc nhấn <strong>⭐ Đặt làm SMTP toàn site</strong> cho tài khoản Gmail đã cấu hình.
											</div>
										) }
									</div>
								) }
								<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
									<Button type="button" onClick={ () => setTestRuleModal( ( p ) => ( { ...p, result: null } ) ) }>Gửi lại</Button>
									<Button type="button" variant="primary" onClick={ () => setTestRuleModal( null ) }>Đóng</Button>
								</div>
							</>
						) }
					</div>
				</div>
			) }
		</div>
	);
}
