// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — ZNS Trigger Rules Tab
// Quy tắc gửi Zalo ZNS theo sự kiện: WooCommerce, CF7, User đăng ký, CRM,...
// Đây là component UI-first — BE endpoints tại bizcity-channel/v1/zns-automation/*
// Pattern: tương tự EmailTab "rules" + ZnsAutomationTab (per-form config)

import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
	Plus, Trash2, RefreshCw, Settings, Zap, CheckCircle2, AlertTriangle,
	XCircle, ChevronDown, ChevronUp, FlaskConical, FileText, BarChart2,
	List, Eye, ToggleLeft, ToggleRight, Download, Calendar, Filter,
	ArrowUpDown, Info,
} from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import { Badge } from '../../components/ui/card.jsx';

// ─── Boot config ───────────────────────────────────────────────────────────
const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

// Channel Gateway fetch helper (bizcity-channel/v1)
async function cgFetch( path, opts = {} ) {
	const base = BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/';
	const url  = base.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );
	const res  = await fetch( url, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   BOOT.restNonce || '',
			...( opts.headers || {} ),
		},
		credentials: 'same-origin',
		...opts,
	} );
	if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
	return res.json();
}

// CRM REST fetch helper (bizcity-crm/v1)
async function crmFetch( path, opts = {} ) {
	const base = BOOT.restUrl || '/wp-json/bizcity-crm/v1/';
	const url  = base.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );
	const res  = await fetch( url, {
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   BOOT.restNonce || '',
			...( opts.headers || {} ),
		},
		credentials: 'same-origin',
		...opts,
	} );
	return res.json();
}

// ─── Danh sách sự kiện có thể kích hoạt ZNS (mirror Registry) ────────────
const EVENT_GROUPS = [
	{
		group: 'woocommerce', label: 'WooCommerce',
		events: [
			{ key: 'woo_order_created',    label: 'Đơn hàng vừa tạo',         placeholders: [ 'order_id','order_number','order_total','customer_name','customer_phone','billing_address','site_name' ] },
			{ key: 'woo_payment_complete',  label: 'Thanh toán thành công',    placeholders: [ 'order_id','order_number','order_total','customer_name','customer_phone','site_name' ] },
			{ key: 'woo_order_processing',  label: 'Đơn đang xử lý',           placeholders: [ 'order_id','order_number','order_total','customer_name','customer_phone','site_name' ] },
			{ key: 'woo_order_completed',   label: 'Giao hàng hoàn tất',       placeholders: [ 'order_id','order_number','order_total','customer_name','customer_phone','shipping_address','product_names','site_name' ] },
			{ key: 'woo_order_cancelled',   label: 'Đơn bị hủy',               placeholders: [ 'order_id','order_number','customer_name','customer_phone','site_name' ] },
			{ key: 'woo_order_refunded',    label: 'Hoàn tiền',                 placeholders: [ 'order_id','order_number','customer_name','customer_phone','site_name' ] },
			{ key: 'woo_order_on_hold',     label: 'Đơn chờ xác nhận',         placeholders: [ 'order_id','order_number','customer_name','customer_phone','site_name' ] },
		],
	},
	{
		group: 'cf7', label: 'Contact Form 7',
		events: [
			{ key: 'cf7_any_form', label: 'Bất kỳ form nào submit', placeholders: [ 'form_title','form_id','phone','name','email','site_name' ] },
		],
	},
	{
		group: 'wordpress', label: 'WordPress User',
		events: [
			{ key: 'user_registered',      label: 'Đăng ký tài khoản mới',      placeholders: [ 'user_login','user_email','user_display_name','user_phone','site_name','site_url' ] },
			{ key: 'user_password_reset',  label: 'Mật khẩu vừa được đặt lại', placeholders: [ 'user_login','user_display_name','user_phone','site_name' ] },
		],
	},
	{
		group: 'crm', label: 'CRM',
		events: [
			{ key: 'crm_contact_created', label: 'Contact mới được tạo',   placeholders: [ 'contact_name','contact_phone','contact_email','contact_id','site_name' ] },
			{ key: 'crm_lead_created',    label: 'Lead mới',                placeholders: [ 'contact_name','contact_phone','contact_email','lead_source','site_name' ] },
			{ key: 'crm_invoice_paid',    label: 'Hóa đơn đã thanh toán',  placeholders: [ 'invoice_number','invoice_total','customer_name','customer_phone','site_name' ] },
		],
	},
	{
		group: 'custom', label: 'Custom / OTP',
		events: [
			{ key: 'bizcity_otp_requested',           label: 'OTP được tạo',      placeholders: [ 'phone','otp_code','expires_in','user_name','site_name' ] },
			{ key: 'bizcity_appointment_confirmed',   label: 'Hẹn lịch xác nhận', placeholders: [ 'phone','customer_name','appointment_time','site_name' ] },
		],
	},
];

// Flatten all events
const ALL_EVENTS = EVENT_GROUPS.flatMap( g => g.events.map( e => ( { ...e, group_label: g.label } ) ) );

function getEventDef( key ) {
	return ALL_EVENTS.find( e => e.key === key ) || null;
}

// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — dynamic CF7 forms from email-events API
// Defined after EVENT_GROUPS so const is in scope
function useDynamicEventGroups() {
	const [ groups, setGroups ] = useState( EVENT_GROUPS );
	useEffect( () => {
		crmFetch( 'email-events' ).then( ( r ) => {
			const all = ( r && r.ok && Array.isArray( r.data ) ) ? r.data : [];
			const cf7Dynamic = all.filter( ( e ) =>
				e.key && ( e.key === 'cf7_form_submitted' || e.key.indexOf( 'cf7_form_' ) === 0 )
			);
			if ( cf7Dynamic.length === 0 ) { return; } // keep static fallback if no forms
			setGroups( EVENT_GROUPS.map( ( g ) => {
				if ( g.group !== 'cf7' ) { return g; }
				return {
					...g,
					events: [
						{ key: 'cf7_any_form', label: 'Bất kỳ form nào submit', placeholders: [ 'form_title', 'form_id', 'phone', 'name', 'email', 'site_name' ] },
						...cf7Dynamic.map( ( e ) => ( {
							key: e.key,
							label: e.label || e.key,
							placeholders: e.placeholders || [ 'form_title', 'form_id', 'phone', 'name', 'email', 'site_name' ],
						} ) ),
					],
				};
			} ) );
		} ).catch( () => {} );
	}, [] );
	return groups;
}

// ─── Helpers ─────────────────────────────────────────────────────────────
function StatusDot( { ok } ) {
	return (
		<span style={ {
			display: 'inline-block', width: 8, height: 8, borderRadius: '50%',
			background: ok ? '#22c55e' : '#f59e0b', marginRight: 4,
		} } />
	);
}

function SectionTitle( { icon: Icon, children } ) {
	return (
		<h4 style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, fontWeight: 700, marginBottom: 10, color: '#1e293b' } }>
			{ Icon && <Icon size={ 14 } style={ { color: '#6366f1' } } /> }
			{ children }
		</h4>
	);
}

function FieldRow( { label, children, hint } ) {
	return (
		<div style={ { marginBottom: 12 } }>
			<Label style={ { fontSize: 12, fontWeight: 600, marginBottom: 4, display: 'block' } }>{ label }</Label>
			{ children }
			{ hint && <p style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>{ hint }</p> }
		</div>
	);
}

// OA account selector — calls CG API
function OaSelector( { value, onChange, disabled } ) {
	const [ oaList, setOaList ] = useState( [] );
	const [ manual, setManual ] = useState( false );

	useEffect( () => {
		cgFetch( 'cf7/zns-oa-accounts' )
			.then( r => { if ( r && r.success && Array.isArray( r.data ) ) { setOaList( r.data ); } } )
			.catch( () => {} );
	}, [] );

	if ( manual || oaList.length === 0 ) {
		return (
			<div style={ { display: 'flex', gap: 6 } }>
				<Input
					value={ value || '' }
					onChange={ e => onChange( e.target.value ) }
					placeholder="Nhập OA ID (để trống = dùng global)"
					disabled={ disabled }
					style={ { flex: 1, fontSize: 12 } }
				/>
				{ oaList.length > 0 && (
					<button style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer' } }
						onClick={ () => setManual( false ) }>Chọn từ danh sách</button>
				) }
			</div>
		);
	}

	return (
		<div style={ { display: 'flex', gap: 6 } }>
			<select
				value={ value || '' }
				onChange={ e => onChange( e.target.value ) }
				disabled={ disabled }
				style={ { flex: 1, fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }
			>
				<option value="">— Dùng OA ID mặc định (global) —</option>
				{ oaList.map( oa => (
					<option key={ oa.oa_id } value={ oa.oa_id }>{ oa.label || oa.name || oa.oa_id }</option>
				) ) }
			</select>
			<button style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer' } }
				onClick={ () => setManual( true ) }>Nhập thủ công</button>
		</div>
	);
}

// Placeholder chips — hiển thị các biến available của event được chọn
function PlaceholderChips( { eventKey, onInsert } ) {
	const def = getEventDef( eventKey );
	if ( ! def ) { return null; }
	return (
		<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 4 } }>
			{ def.placeholders.map( p => (
				<button
					key={ p }
					onClick={ () => onInsert && onInsert( p ) }
					title="Click để dùng placeholder này"
					style={ {
						padding: '2px 8px', fontSize: 11, borderRadius: 10,
						background: '#f1f5f9', border: '1px solid #cbd5e1',
						color: '#475569', cursor: onInsert ? 'pointer' : 'default',
					} }
				>
					{ '{{' + p + '}}' }
				</button>
			) ) }
		</div>
	);
}

// TempVars editor — bảng mapping biến ZNS → placeholder / literal
function TempVarsEditor( { value, onChange, eventKey, disabled } ) {
	const vars = Array.isArray( value ) ? value : [];
	const def   = getEventDef( eventKey );

	function updateRow( idx, field, val ) {
		const next = vars.map( ( r, i ) => i === idx ? { ...r, [ field ]: val } : r );
		onChange( next );
	}

	function addRow() {
		onChange( [ ...vars, { var_name: '', source: 'placeholder', field: '', value: '' } ] );
	}

	function removeRow( idx ) {
		onChange( vars.filter( ( _, i ) => i !== idx ) );
	}

	return (
		<div>
			{ vars.length === 0 && (
				<p style={ { fontSize: 12, color: '#94a3b8', marginBottom: 8 } }>Chưa có biến nào. Thêm biến để map TempData của template ZNS.</p>
			) }
			{ vars.map( ( row, idx ) => (
				<div key={ idx } style={ { display: 'grid', gridTemplateColumns: '1fr 110px 1fr 28px', gap: 6, marginBottom: 6, alignItems: 'center' } }>
					<Input
						value={ row.var_name || '' }
						onChange={ e => updateRow( idx, 'var_name', e.target.value ) }
						placeholder="Tên biến ZNS"
						disabled={ disabled }
						style={ { fontSize: 12 } }
					/>
					<select
						value={ row.source || 'placeholder' }
						onChange={ e => updateRow( idx, 'source', e.target.value ) }
						disabled={ disabled }
						style={ { fontSize: 12, padding: '6px 6px', border: '1px solid #e2e8f0', borderRadius: 6 } }
					>
						<option value="placeholder">Placeholder</option>
						<option value="literal">Cố định</option>
					</select>
					{ row.source === 'placeholder' ? (
						<select
							value={ row.field || '' }
							onChange={ e => updateRow( idx, 'field', e.target.value ) }
							disabled={ disabled }
							style={ { fontSize: 12, padding: '6px 6px', border: '1px solid #e2e8f0', borderRadius: 6 } }
						>
							<option value="">— Chọn placeholder —</option>
							{ ( def ? def.placeholders : [] ).map( p => (
								<option key={ p } value={ p }>{ '{{' + p + '}}' }</option>
							) ) }
						</select>
					) : (
						<Input
							value={ row.value || '' }
							onChange={ e => updateRow( idx, 'value', e.target.value ) }
							placeholder="Giá trị cố định"
							disabled={ disabled }
							style={ { fontSize: 12 } }
						/>
					) }
					<button
						onClick={ () => removeRow( idx ) }
						disabled={ disabled }
						style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#ef4444', padding: 4 } }
					><Trash2 size={ 13 } /></button>
				</div>
			) ) }
			<button
				onClick={ addRow }
				disabled={ disabled }
				style={ {
					display: 'flex', alignItems: 'center', gap: 4, fontSize: 12,
					color: '#6366f1', background: 'none', border: '1px dashed #c7d2fe',
					borderRadius: 6, padding: '5px 12px', cursor: 'pointer', marginTop: 4,
				} }
			><Plus size={ 12 } /> Thêm biến</button>
		</div>
	);
}

// ─── Rule Form (drawer) ────────────────────────────────────────────────────
function RuleForm( { initial, onSubmit, onCancel, busy } ) {
	const isNew = ! initial || ! initial.id;
	const [ name,      setName      ] = useState( initial?.name || '' );
	const [ eventKey,  setEventKey  ] = useState( initial?.event_key || '' );
	const [ tempId,    setTempId    ] = useState( initial?.temp_id || '' );
	const [ oaId,      setOaId      ] = useState( initial?.oa_id || '' );
	const [ sandbox,   setSandbox   ] = useState( initial?.sandbox || false );
	const [ campaign,  setCampaign  ] = useState( initial?.campaign_id || '' );
	const [ tempVars,  setTempVars  ] = useState( () => {
		if ( initial?.temp_vars_json ) {
			try { return JSON.parse( initial.temp_vars_json ); } catch { return []; }
		}
		return initial?.temp_vars || [];
	} );
	const [ err, setErr ] = useState( '' );

	// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — dynamic CF7 forms from email-events API
	const eventGroups = useDynamicEventGroups();

	function handleSubmit( e ) {
		e.preventDefault();
		if ( ! name.trim() ) { setErr( 'Tên quy tắc không được trống.' ); return; }
		if ( ! eventKey )    { setErr( 'Chưa chọn sự kiện kích hoạt.' ); return; }
		if ( ! tempId.trim() ) { setErr( 'Template ID (TempID) không được trống.' ); return; }
		setErr( '' );
		onSubmit( {
			name:         name.trim(),
			event_key:    eventKey,
			temp_id:      tempId.trim(),
			oa_id:        oaId.trim(),
			sandbox:      sandbox ? 1 : 0,
			campaign_id:  campaign.trim(),
			temp_vars_json: JSON.stringify( tempVars ),
			enabled:      1,
		} );
	}

	const selectedDef = getEventDef( eventKey );

	return (
		<form onSubmit={ handleSubmit }>
			{ err && (
				<div style={ { background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, padding: '8px 12px', fontSize: 12, color: '#dc2626', marginBottom: 12 } }>
					{ err }
				</div>
			) }

			<FieldRow label="Tên quy tắc *">
				<Input value={ name } onChange={ e => setName( e.target.value ) } placeholder="VD: Xác nhận đơn hàng WooCommerce" disabled={ busy } style={ { fontSize: 13 } } />
			</FieldRow>

			<FieldRow label="Sự kiện kích hoạt *" hint="Chọn nhóm sự kiện để ZNS tự động gửi">
				<select
					value={ eventKey }
					onChange={ e => { setEventKey( e.target.value ); setTempVars( [] ); } }
					disabled={ busy }
					style={ { width: '100%', fontSize: 12, padding: '8px 10px', border: '1px solid #e2e8f0', borderRadius: 6 } }
				>
					<option value="">— Chọn sự kiện —</option>
					{ eventGroups.map( g => (
						<optgroup key={ g.group } label={ g.label }>
							{ g.events.map( ev => (
								<option key={ ev.key } value={ ev.key }>{ ev.label }</option>
							) ) }
						</optgroup>
					) ) }
				</select>
			</FieldRow>

			{ eventKey && (
				<div style={ { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '8px 12px', marginBottom: 12 } }>
					<p style={ { fontSize: 11, color: '#64748b', marginBottom: 4, fontWeight: 600 } }>Placeholders available:</p>
					<PlaceholderChips eventKey={ eventKey } />
				</div>
			) }

			<FieldRow label="Template ID (TempID) *" hint="ID template ZNS đã đăng ký với eSMS / Zalo OA">
				<Input value={ tempId } onChange={ e => setTempId( e.target.value ) } placeholder="VD: 595298" disabled={ busy } style={ { fontSize: 13 } } />
			</FieldRow>

			<FieldRow label="OA ID (để trống = dùng mặc định)" hint="Ghi đè OA ID cho quy tắc này. Để trống sẽ dùng OA trong Cấu hình chung.">
				<OaSelector value={ oaId } onChange={ setOaId } disabled={ busy } />
			</FieldRow>

			<FieldRow label="Campaign ID" hint="Tên chiến dịch ghi vào eSMS (tối đa 254 ký tự)">
				<Input value={ campaign } onChange={ e => setCampaign( e.target.value ) } placeholder="VD: Woo Order 2026 Q3" disabled={ busy } style={ { fontSize: 13 } } />
			</FieldRow>

			<div style={ { marginBottom: 16 } }>
				<SectionTitle icon={ Zap }>Biến template (TempData)</SectionTitle>
				<p style={ { fontSize: 11, color: '#64748b', marginBottom: 8 } }>
					Map các biến trong template ZNS → placeholder của sự kiện hoặc giá trị cố định.
				</p>
				<TempVarsEditor value={ tempVars } onChange={ setTempVars } eventKey={ eventKey } disabled={ busy } />
			</div>

			<FieldRow label="">
				<label style={ { display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 12 } }>
					<input type="checkbox" checked={ sandbox } onChange={ e => setSandbox( e.target.checked ) } disabled={ busy } />
					<span>Sandbox mode — Không gửi tin thật, không trừ tiền</span>
					{ sandbox && <Badge style={ { background: '#fef9c3', color: '#854d0e', fontSize: 10 } }>TEST</Badge> }
				</label>
			</FieldRow>

			<div style={ { display: 'flex', gap: 8, marginTop: 20 } }>
				<Button type="submit" variant="primary" disabled={ busy } style={ { flex: 1 } }>
					{ busy ? 'Đang lưu...' : ( isNew ? 'Tạo quy tắc' : 'Lưu thay đổi' ) }
				</Button>
				<Button type="button" variant="ghost" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
			</div>
		</form>
	);
}

// ─── Tab: Quy tắc gửi ─────────────────────────────────────────────────────
function RulesTab( { onOpenSettings } ) {
	const [ rules,    setRules    ] = useState( [] );
	const [ loading,  setLoading  ] = useState( false );
	const [ editRule, setEditRule ] = useState( null );
	const [ showSheet, setShowSheet ] = useState( false );
	const [ saving,   setSaving   ] = useState( false );
	const [ toast,    setToast    ] = useState( '' );

	const loadRules = useCallback( () => {
		setLoading( true );
		cgFetch( 'zns-automation/rules' )
			.then( r => { if ( r && r.success ) { setRules( Array.isArray( r.data ) ? r.data : ( r.data?.items || [] ) ); } } )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => { loadRules(); }, [] ); // eslint-disable-line

	function openAdd()  { setEditRule( null ); setShowSheet( true ); }
	function openEdit( rule ) { setEditRule( rule ); setShowSheet( true ); }

	async function handleSave( data ) {
		setSaving( true );
		try {
			let url, method;
			if ( editRule?.id ) {
				url    = 'zns-automation/rules/' + editRule.id;
				method = 'PUT';
			} else {
				url    = 'zns-automation/rules';
				method = 'POST';
			}
			const r = await cgFetch( url, { method, body: JSON.stringify( data ) } );
			if ( r && r.success ) {
				setShowSheet( false );
				setToast( editRule?.id ? 'Đã cập nhật quy tắc.' : 'Đã tạo quy tắc mới.' );
				loadRules();
			} else {
				setToast( 'Lỗi: ' + ( r?.message || 'không xác định' ) );
			}
		} catch ( e ) {
			setToast( 'Lỗi kết nối: ' + e.message );
		} finally {
			setSaving( false );
			setTimeout( () => setToast( '' ), 4000 );
		}
	}

	async function toggleEnabled( rule ) {
		try {
			const r = await cgFetch( 'zns-automation/rules/' + rule.id + '/toggle', { method: 'POST' } );
			if ( r && r.success ) { loadRules(); }
		} catch { /* silent */ }
	}

	async function deleteRule( rule ) {
		if ( ! window.confirm( 'Xóa quy tắc "' + rule.name + '"?' ) ) { return; }
		try {
			await cgFetch( 'zns-automation/rules/' + rule.id, { method: 'DELETE' } );
			loadRules();
		} catch { /* silent */ }
	}

	return (
		<div>
			{ /* Header */ }
			<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 } }>
				<div>
					<p style={ { fontSize: 12, color: '#64748b', marginTop: 2 } }>
						Mỗi quy tắc gắn 1 sự kiện (WooCommerce, CF7, đăng ký...) với 1 template ZNS. Hệ thống tự gửi khi sự kiện xảy ra.
					</p>
				</div>
				<div style={ { display: 'flex', gap: 8 } }>
					<Button variant="ghost" onClick={ onOpenSettings } style={ { fontSize: 12 } }>
						<Settings size={ 13 } /> Cấu hình eSMS
					</Button>
					<Button variant="ghost" onClick={ loadRules } disabled={ loading } style={ { fontSize: 12 } }>
						<RefreshCw size={ 13 } style={ { animation: loading ? 'spin 1s linear infinite' : 'none' } } />
					</Button>
					<Button variant="primary" onClick={ openAdd } style={ { fontSize: 12 } }>
						<Plus size={ 13 } /> Thêm quy tắc
					</Button>
				</div>
			</div>

			{ toast && (
				<div style={ { background: toast.startsWith( 'Lỗi' ) ? '#fef2f2' : '#f0fdf4', border: '1px solid ' + ( toast.startsWith( 'Lỗi' ) ? '#fecaca' : '#bbf7d0' ), borderRadius: 8, padding: '8px 12px', fontSize: 12, marginBottom: 12, color: toast.startsWith( 'Lỗi' ) ? '#dc2626' : '#16a34a' } }>
					{ toast }
				</div>
			) }

			{ loading && (
				<div style={ { textAlign: 'center', padding: 32, color: '#94a3b8', fontSize: 13 } }>Đang tải...</div>
			) }

			{ ! loading && rules.length === 0 && (
				<div style={ { textAlign: 'center', padding: 48, color: '#94a3b8', border: '2px dashed #e2e8f0', borderRadius: 12 } }>
					<Zap size={ 32 } style={ { margin: '0 auto 12px', display: 'block', color: '#c7d2fe' } } />
					<p style={ { fontWeight: 600, fontSize: 14, marginBottom: 4, color: '#64748b' } }>Chưa có quy tắc ZNS nào</p>
					<p style={ { fontSize: 12, marginBottom: 16 } }>Thêm quy tắc để tự động gửi Zalo ZNS theo sự kiện WooCommerce, CF7, đăng ký...</p>
					<Button variant="primary" onClick={ openAdd }><Plus size={ 13 } /> Thêm quy tắc đầu tiên</Button>
				</div>
			) }

			{ ! loading && rules.length > 0 && (
				<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 13 } }>
					<thead>
						<tr style={ { borderBottom: '2px solid #f1f5f9' } }>
							<th style={ { padding: '8px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Tên quy tắc</th>
							<th style={ { padding: '8px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Sự kiện</th>
							<th style={ { padding: '8px 10px', textAlign: 'left', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Template</th>
							<th style={ { padding: '8px 10px', textAlign: 'center', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Đã gửi</th>
							<th style={ { padding: '8px 10px', textAlign: 'center', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Trạng thái</th>
							<th style={ { padding: '8px 10px', textAlign: 'right', color: '#64748b', fontWeight: 600, fontSize: 12 } }>Thao tác</th>
						</tr>
					</thead>
					<tbody>
						{ rules.map( ( rule ) => {
							const evDef = getEventDef( rule.event_key );
							return (
								<tr key={ rule.id } style={ { borderBottom: '1px solid #f8fafc' } }>
									<td style={ { padding: '10px 10px' } }>
										<span style={ { fontWeight: 600, color: '#1e293b' } }>{ rule.name }</span>
										{ rule.sandbox ? <Badge style={ { marginLeft: 6, background: '#fef9c3', color: '#854d0e', fontSize: 10 } }>SANDBOX</Badge> : null }
									</td>
									<td style={ { padding: '10px 10px', color: '#64748b', fontSize: 12 } }>
										{ evDef ? (
											<span>
												<span style={ { fontSize: 10, color: '#94a3b8', display: 'block' } }>{ evDef.group_label }</span>
												{ evDef.label }
											</span>
										) : (
											<span style={ { color: '#ef4444' } }>{ rule.event_key }</span>
										) }
									</td>
									<td style={ { padding: '10px 10px', fontSize: 12 } }>
										<code style={ { background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, fontSize: 11 } }>
											{ rule.temp_id || '—' }
										</code>
									</td>
									<td style={ { padding: '10px 10px', textAlign: 'center', fontSize: 12, color: '#64748b' } }>
										{ rule.fire_count || 0 }
										{ rule.last_error && (
											<span title={ rule.last_error } style={ { marginLeft: 4, color: '#ef4444', cursor: 'help' } }>
												<AlertTriangle size={ 11 } />
											</span>
										) }
									</td>
									<td style={ { padding: '10px 10px', textAlign: 'center' } }>
										<button
											onClick={ () => toggleEnabled( rule ) }
											title={ rule.enabled ? 'Đang bật — click để tắt' : 'Đang tắt — click để bật' }
											style={ { background: 'none', border: 'none', cursor: 'pointer', color: rule.enabled ? '#22c55e' : '#94a3b8' } }
										>
											{ rule.enabled ? <ToggleRight size={ 20 } /> : <ToggleLeft size={ 20 } /> }
										</button>
									</td>
									<td style={ { padding: '10px 10px', textAlign: 'right' } }>
										<div style={ { display: 'flex', gap: 4, justifyContent: 'flex-end' } }>
											<Button variant="ghost" onClick={ () => openEdit( rule ) } style={ { fontSize: 11, padding: '4px 8px' } }>
												Sửa
											</Button>
											<Button variant="ghost" onClick={ () => deleteRule( rule ) } style={ { fontSize: 11, padding: '4px 8px', color: '#ef4444' } }>
												<Trash2 size={ 11 } />
											</Button>
										</div>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ /* Add/Edit Sheet */ }
			<Sheet open={ showSheet } onOpenChange={ setShowSheet }>
				<SheetContent style={ { maxWidth: 580 } }>
					<SheetHeader>
						<SheetTitle>{ editRule?.id ? 'Sửa quy tắc ZNS' : 'Thêm quy tắc ZNS mới' }</SheetTitle>
					</SheetHeader>
					<SheetBody>
						<RuleForm
							initial={ editRule }
							onSubmit={ handleSave }
							onCancel={ () => setShowSheet( false ) }
							busy={ saving }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}

// ─── Tab: Cấu hình eSMS ───────────────────────────────────────────────────
function ConfigTab() {
	const [ settings,  setSettings  ] = useState( null );
	const [ loading,   setLoading   ] = useState( true );
	const [ saving,    setSaving    ] = useState( false );
	const [ apiKey,    setApiKey    ] = useState( '' );
	const [ secretKey, setSecretKey ] = useState( '' );
	const [ oaId,      setOaId      ] = useState( '' );
	const [ showPass,  setShowPass  ] = useState( false );
	const [ toast,     setToast     ] = useState( '' );

	useEffect( () => {
		cgFetch( 'zns-automation/settings' )
			.then( r => {
				if ( r && r.success && r.data ) {
					const s = r.data;
					setApiKey( s.api_key || '' );
					setSecretKey( s.secret_key_masked || '' );
					setOaId( s.oa_id || '' );
					setSettings( s );
				}
			} )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [] );

	async function handleSave( e ) {
		e.preventDefault();
		setSaving( true );
		try {
			const body = { api_key: apiKey, oa_id: oaId };
			if ( secretKey && ! secretKey.includes( '*' ) ) { body.secret_key = secretKey; }
			const r = await cgFetch( 'zns-automation/settings', { method: 'POST', body: JSON.stringify( body ) } );
			if ( r && r.success ) {
				setToast( 'Đã lưu cấu hình eSMS.' );
				setSettings( r.data || settings );
			} else {
				setToast( 'Lỗi: ' + ( r?.message || 'không xác định' ) );
			}
		} catch ( e ) {
			setToast( 'Lỗi kết nối: ' + e.message );
		} finally {
			setSaving( false );
			setTimeout( () => setToast( '' ), 4000 );
		}
	}

	if ( loading ) {
		return <div style={ { padding: 32, textAlign: 'center', color: '#94a3b8' } }>Đang tải cấu hình...</div>;
	}

	const isConfigured = settings?.is_configured;

	return (
		<div style={ { maxWidth: 520 } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '12px 16px', borderRadius: 8, background: isConfigured ? '#f0fdf4' : '#fffbeb', border: '1px solid ' + ( isConfigured ? '#bbf7d0' : '#fde68a' ), marginBottom: 20 } }>
				{ isConfigured ? <CheckCircle2 size={ 16 } style={ { color: '#16a34a' } } /> : <AlertTriangle size={ 16 } style={ { color: '#d97706' } } /> }
				<div>
					<p style={ { fontSize: 13, fontWeight: 600, color: isConfigured ? '#15803d' : '#92400e' } }>
						{ isConfigured ? 'eSMS đã kết nối' : 'Chưa cấu hình eSMS' }
					</p>
					{ isConfigured && settings?.oa_id && (
						<p style={ { fontSize: 11, color: '#16a34a' } }>OA ID: { settings.oa_id }</p>
					) }
				</div>
			</div>

			{ toast && (
				<div style={ { background: toast.startsWith( 'Lỗi' ) ? '#fef2f2' : '#f0fdf4', border: '1px solid ' + ( toast.startsWith( 'Lỗi' ) ? '#fecaca' : '#bbf7d0' ), borderRadius: 8, padding: '8px 12px', fontSize: 12, marginBottom: 12, color: toast.startsWith( 'Lỗi' ) ? '#dc2626' : '#16a34a' } }>
					{ toast }
				</div>
			) }

			<form onSubmit={ handleSave }>
				<SectionTitle icon={ Settings }>Credentials eSMS</SectionTitle>
				<p style={ { fontSize: 12, color: '#64748b', marginBottom: 16, lineHeight: 1.6 } }>
					Nhập API Key và Secret Key từ tài khoản eSMS. Dùng chung cho tất cả ZNS Trigger Rules.
					{ ' ' }<a href="https://esms.vn" target="_blank" rel="noopener noreferrer" style={ { color: '#6366f1' } }>Đăng ký eSMS →</a>
				</p>

				<FieldRow label="API Key *">
					<Input value={ apiKey } onChange={ e => setApiKey( e.target.value ) } placeholder="eSMS API Key" disabled={ saving } style={ { fontSize: 13 } } />
				</FieldRow>

				<FieldRow label="Secret Key *">
					<div style={ { position: 'relative' } }>
						<Input
							type={ showPass ? 'text' : 'password' }
							value={ secretKey }
							onChange={ e => setSecretKey( e.target.value ) }
							placeholder="Secret Key (gõ mới để thay đổi)"
							disabled={ saving }
							style={ { fontSize: 13, paddingRight: 60 } }
						/>
						<button
							type="button"
							onClick={ () => setShowPass( ! showPass ) }
							style={ { position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', fontSize: 11, color: '#6366f1' } }
						>
							{ showPass ? 'Ẩn' : 'Hiện' }
						</button>
					</div>
					{ secretKey && secretKey.includes( '*' ) && (
						<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 2 } }>Secret Key đã lưu. Gõ mới để thay đổi.</p>
					) }
				</FieldRow>

				<FieldRow label="Zalo OA ID mặc định" hint="OA ID dùng khi quy tắc không ghi đè. Lấy từ Zalo Official Account Manager.">
					<OaSelector value={ oaId } onChange={ setOaId } disabled={ saving } />
				</FieldRow>

				<Button type="submit" variant="primary" disabled={ saving || ! apiKey }>
					{ saving ? 'Đang lưu...' : 'Lưu cấu hình' }
				</Button>
			</form>
		</div>
	);
}

// ─── Tab: Test & Debug ────────────────────────────────────────────────────
function TestTab() {
	const [ rules,    setRules    ] = useState( [] );
	const [ ruleId,   setRuleId   ] = useState( '' );
	const [ phone,    setPhone    ] = useState( '' );
	const [ sandbox,  setSandbox  ] = useState( true );
	const [ overrides, setOverrides ] = useState( [] );
	const [ sending,  setSending  ] = useState( false );
	const [ result,   setResult   ] = useState( null );
	const [ dryRun,   setDryRun   ] = useState( false );

	useEffect( () => {
		cgFetch( 'zns-automation/rules?enabled=1' )
			.then( r => { if ( r && r.success ) { setRules( Array.isArray( r.data ) ? r.data : ( r.data?.items || [] ) ); } } )
			.catch( () => {} );
	}, [] );

	const selectedRule = rules.find( r => String( r.id ) === String( ruleId ) );
	const eventDef = selectedRule ? getEventDef( selectedRule.event_key ) : null;

	// Khi chọn rule, tự populate overrides từ placeholders
	useEffect( () => {
		if ( eventDef ) {
			setOverrides( eventDef.placeholders.map( p => ( { key: p, value: '' } ) ) );
		} else {
			setOverrides( [] );
		}
	}, [ ruleId ] ); // eslint-disable-line

	function updateOverride( idx, val ) {
		setOverrides( prev => prev.map( ( r, i ) => i === idx ? { ...r, value: val } : r ) );
	}

	async function handleSend() {
		if ( ! ruleId )  { alert( 'Chọn quy tắc.' ); return; }
		if ( ! dryRun && ! phone ) { alert( 'Nhập số điện thoại.' ); return; }
		setSending( true );
		setResult( null );
		try {
			const overrideMap = {};
			overrides.forEach( o => { if ( o.value ) { overrideMap[ o.key ] = o.value; } } );

			const endpoint = dryRun ? 'zns-automation/test/dry-run' : 'zns-automation/test';
			const r = await cgFetch( endpoint, {
				method: 'POST',
				body: JSON.stringify( {
					rule_id:               parseInt( ruleId ),
					phone:                 phone,
					sandbox:               sandbox,
					override_placeholders: overrideMap,
				} ),
			} );
			setResult( r );
		} catch ( e ) {
			setResult( { success: false, message: e.message } );
		} finally {
			setSending( false );
		}
	}

	return (
		<div style={ { maxWidth: 560 } }>
			<SectionTitle icon={ FlaskConical }>Gửi ZNS thử / Dry-run</SectionTitle>
			<p style={ { fontSize: 12, color: '#64748b', marginBottom: 16, lineHeight: 1.6 } }>
				Chọn quy tắc và nhập dữ liệu mẫu để kiểm tra. Dùng <strong>Sandbox</strong> để không gửi tin thật và không trừ tiền.
			</p>

			<FieldRow label="Chọn quy tắc">
				<select
					value={ ruleId }
					onChange={ e => setRuleId( e.target.value ) }
					style={ { width: '100%', fontSize: 12, padding: '8px 10px', border: '1px solid #e2e8f0', borderRadius: 6 } }
				>
					<option value="">— Chọn quy tắc —</option>
					{ rules.map( r => (
						<option key={ r.id } value={ r.id }>{ r.name } ({ r.event_key })</option>
					) ) }
				</select>
			</FieldRow>

			{ ! dryRun && (
				<FieldRow label="Số điện thoại nhận" hint="Số điện thoại thật để test gửi. Dùng sandbox để không gửi thật.">
					<Input value={ phone } onChange={ e => setPhone( e.target.value ) } placeholder="VD: 0901234567" style={ { fontSize: 13 } } />
				</FieldRow>
			) }

			{ eventDef && overrides.length > 0 && (
				<div style={ { marginBottom: 16 } }>
					<SectionTitle>Override giá trị placeholder (tuỳ chọn)</SectionTitle>
					{ overrides.map( ( o, idx ) => (
						<div key={ o.key } style={ { display: 'grid', gridTemplateColumns: '140px 1fr', gap: 8, marginBottom: 6, alignItems: 'center' } }>
							<label style={ { fontSize: 11, color: '#64748b', fontFamily: 'monospace' } }>{ '{{' + o.key + '}}' }</label>
							<Input value={ o.value } onChange={ e => updateOverride( idx, e.target.value ) } placeholder="Giá trị mẫu" style={ { fontSize: 12 } } />
						</div>
					) ) }
				</div>
			) }

			<div style={ { display: 'flex', gap: 16, marginBottom: 16 } }>
				<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', fontSize: 12 } }>
					<input type="checkbox" checked={ sandbox } onChange={ e => setSandbox( e.target.checked ) } />
					<span>Sandbox (không gửi thật)</span>
					{ sandbox && <Badge style={ { background: '#fef9c3', color: '#854d0e', fontSize: 10 } }>SAFE</Badge> }
				</label>
				<label style={ { display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', fontSize: 12 } }>
					<input type="checkbox" checked={ dryRun } onChange={ e => setDryRun( e.target.checked ) } />
					<span>Dry-run (build payload, KHÔNG gửi HTTP)</span>
				</label>
			</div>

			{ ! sandbox && ! dryRun && (
				<div style={ { background: '#fff7ed', border: '1px solid #fed7aa', borderRadius: 8, padding: '8px 12px', marginBottom: 12, fontSize: 12, color: '#c2410c' } }>
					<AlertTriangle size={ 12 } style={ { display: 'inline', marginRight: 4 } } />
					Sandbox đang <strong>tắt</strong> — tin ZNS sẽ được gửi thật và trừ tiền tài khoản eSMS.
				</div>
			) }

			<Button variant="primary" onClick={ handleSend } disabled={ sending || ! ruleId } style={ { width: '100%' } }>
				{ sending ? 'Đang gửi...' : ( dryRun ? '🔍 Dry-run (xem payload)' : '📨 Gửi test ZNS' ) }
			</Button>

			{ result && (
				<div style={ { marginTop: 16, background: result.success ? '#f0fdf4' : '#fef2f2', border: '1px solid ' + ( result.success ? '#bbf7d0' : '#fecaca' ), borderRadius: 8, padding: 14 } }>
					<div style={ { display: 'flex', alignItems: 'center', gap: 6, marginBottom: 8 } }>
						{ result.success ? <CheckCircle2 size={ 14 } style={ { color: '#16a34a' } } /> : <XCircle size={ 14 } style={ { color: '#dc2626' } } /> }
						<span style={ { fontWeight: 700, fontSize: 13, color: result.success ? '#15803d' : '#dc2626' } }>
							{ result.success ? ( dryRun ? 'Payload build thành công' : 'Gửi thành công' ) : 'Thất bại' }
						</span>
					</div>
					{ result.data && (
						<pre style={ { fontSize: 11, overflow: 'auto', maxHeight: 300, background: '#fff', padding: 10, borderRadius: 6, margin: 0 } }>
							{ JSON.stringify( result.data, null, 2 ) }
						</pre>
					) }
					{ ! result.success && result.message && (
						<p style={ { fontSize: 12, color: '#dc2626' } }>{ result.message }</p>
					) }
				</div>
			) }
		</div>
	);
}

// ─── Tab: Logs ─────────────────────────────────────────────────────────────
function LogsTab() {
	const [ entries, setEntries ] = useState( [] );
	const [ loading, setLoading ] = useState( false );
	const [ date,    setDate    ] = useState( '' );
	const [ level,   setLevel   ] = useState( '' );
	const [ evFilter,setEvFilter] = useState( '' );
	const [ dates,   setDates   ] = useState( [] );

	useEffect( () => {
		cgFetch( 'zns-automation/logs/dates' )
			.then( r => { if ( r && r.success && r.data ) { setDates( r.data ); } } )
			.catch( () => {} );
	}, [] );

	const loadLogs = useCallback( () => {
		setLoading( true );
		const params = new URLSearchParams();
		if ( date )     { params.set( 'date',  date ); }
		if ( level )    { params.set( 'level', level ); }
		if ( evFilter ) { params.set( 'event', evFilter ); }
		params.set( 'limit', '200' );
		cgFetch( 'zns-automation/logs?' + params.toString() )
			.then( r => { if ( r && r.success && r.data ) { setEntries( r.data.entries || [] ); } } )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [ date, level, evFilter ] );

	useEffect( () => { loadLogs(); }, [ loadLogs ] );

	const LEVEL_COLORS = { info: '#3b82f6', warn: '#f59e0b', error: '#ef4444' };

	return (
		<div>
			<div style={ { display: 'flex', gap: 10, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' } }>
				<select value={ date } onChange={ e => setDate( e.target.value ) }
					style={ { fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }>
					<option value="">Hôm nay</option>
					{ dates.map( d => <option key={ d } value={ d }>{ d }</option> ) }
				</select>
				<select value={ level } onChange={ e => setLevel( e.target.value ) }
					style={ { fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }>
					<option value="">Tất cả mức</option>
					<option value="info">Info</option>
					<option value="warn">Warn</option>
					<option value="error">Error</option>
				</select>
				<select value={ evFilter } onChange={ e => setEvFilter( e.target.value ) }
					style={ { fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }>
					<option value="">Tất cả event</option>
					<option value="zns_send_ok">zns_send_ok</option>
					<option value="zns_send_failed">zns_send_failed</option>
					<option value="zns_send_attempt">zns_send_attempt</option>
					<option value="zns_skip_no_phone">zns_skip_no_phone</option>
					<option value="zns_dispatch_exception">zns_dispatch_exception</option>
					<option value="zns_hook_triggered">zns_hook_triggered</option>
				</select>
				<Button variant="ghost" onClick={ loadLogs } disabled={ loading } style={ { fontSize: 12 } }>
					<RefreshCw size={ 12 } style={ { animation: loading ? 'spin 1s linear infinite' : 'none' } } /> Tải lại
				</Button>
			</div>

			{ loading && <div style={ { padding: 20, textAlign: 'center', color: '#94a3b8', fontSize: 12 } }>Đang đọc file log...</div> }

			{ ! loading && entries.length === 0 && (
				<div style={ { padding: 32, textAlign: 'center', color: '#94a3b8', fontSize: 12 } }>
					<FileText size={ 28 } style={ { display: 'block', margin: '0 auto 8px', color: '#cbd5e1' } } />
					Không có log nào trong khoảng thời gian này.
				</div>
			) }

			{ ! loading && entries.length > 0 && (
				<div style={ { fontFamily: 'monospace', fontSize: 11 } }>
					{ entries.map( ( e, idx ) => (
						<LogRow key={ idx } entry={ e } levelColors={ LEVEL_COLORS } />
					) ) }
				</div>
			) }
		</div>
	);
}

function LogRow( { entry, levelColors } ) {
	const [ expanded, setExpanded ] = useState( false );
	const color = levelColors[ entry.level ] || '#64748b';
	return (
		<div style={ { borderBottom: '1px solid #f1f5f9', padding: '6px 4px' } }>
			<div
				onClick={ () => setExpanded( ! expanded ) }
				style={ { display: 'grid', gridTemplateColumns: '160px 50px 180px 1fr 20px', gap: 8, alignItems: 'center', cursor: 'pointer' } }
			>
				<span style={ { color: '#94a3b8' } }>{ entry.ts }</span>
				<span style={ { color, fontWeight: 700, textTransform: 'uppercase', fontSize: 10 } }>{ entry.level }</span>
				<span style={ { color } }>{ entry.event }</span>
				<span style={ { color: '#475569', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>{ entry.msg }</span>
				<span style={ { color: '#94a3b8' } }>{ expanded ? <ChevronUp size={ 12 } /> : <ChevronDown size={ 12 } /> }</span>
			</div>
			{ expanded && entry.ctx && (
				<pre style={ { background: '#f8fafc', padding: '8px 10px', borderRadius: 6, margin: '6px 0 2px', fontSize: 10, overflow: 'auto', maxHeight: 200 } }>
					{ JSON.stringify( entry.ctx, null, 2 ) }
				</pre>
			) }
		</div>
	);
}

// ─── Tab: Phân tích ────────────────────────────────────────────────────────
function StatsTab() {
	const [ period,  setPeriod  ] = useState( '30d' );
	const [ stats,   setStats   ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		setLoading( true );
		cgFetch( 'zns-automation/stats?period=' + period )
			.then( r => { if ( r && r.success && r.data ) { setStats( r.data ); } else { setStats( null ); } } )
			.catch( () => setStats( null ) )
			.finally( () => setLoading( false ) );
	}, [ period ] );

	const ESMS_ERRORS = {
		'100': 'Thành công',
		'101': 'Sai ApiKey / SecretKey',
		'789': 'TempID chưa cấu hình cho OA ID',
		'99':  'Lỗi hệ thống eSMS',
	};

	return (
		<div>
			<div style={ { display: 'flex', alignItems: 'center', gap: 10, marginBottom: 16 } }>
				<span style={ { fontSize: 12, fontWeight: 600 } }>Kỳ:</span>
				{ [ '7d','30d','90d' ].map( p => (
					<button key={ p } onClick={ () => setPeriod( p ) }
						style={ {
							padding: '4px 12px', fontSize: 12, borderRadius: 16, cursor: 'pointer', border: 'none',
							background: period === p ? '#6366f1' : '#f1f5f9',
							color: period === p ? '#fff' : '#64748b', fontWeight: period === p ? 700 : 400,
						} }
					>{ p }</button>
				) ) }
			</div>

			{ loading && <div style={ { padding: 32, textAlign: 'center', color: '#94a3b8' } }>Đang tải...</div> }

			{ ! loading && ! stats && (
				<div style={ { padding: 32, textAlign: 'center', color: '#94a3b8', fontSize: 12 } }>
					Chưa có dữ liệu thống kê. Hãy thêm quy tắc và gửi ZNS để xem báo cáo.
				</div>
			) }

			{ ! loading && stats && (
				<>
					{ /* KPI Cards */ }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, marginBottom: 20 } }>
						{ [
							{ label: 'Tổng gửi',    value: stats.total || 0,   color: '#6366f1' },
							{ label: 'Thành công',  value: stats.success || 0, color: '#22c55e' },
							{ label: 'Thất bại',    value: stats.failed || 0,  color: '#ef4444' },
							{ label: 'Tỉ lệ (%)',   value: ( stats.success_rate || 0 ).toFixed( 1 ), color: '#f59e0b' },
						].map( card => (
							<div key={ card.label } style={ { background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 10, padding: '14px 16px', textAlign: 'center' } }>
								<p style={ { fontSize: 22, fontWeight: 800, color: card.color, margin: '0 0 4px' } }>{ card.value }</p>
								<p style={ { fontSize: 11, color: '#94a3b8', margin: 0 } }>{ card.label }</p>
							</div>
						) ) }
					</div>

					{ /* By Event */ }
					{ stats.by_event && stats.by_event.length > 0 && (
						<div style={ { marginBottom: 20 } }>
							<SectionTitle icon={ BarChart2 }>Breakdown theo sự kiện</SectionTitle>
							<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
								<thead>
									<tr style={ { borderBottom: '2px solid #f1f5f9' } }>
										<th style={ { padding: '6px 8px', textAlign: 'left', color: '#94a3b8', fontWeight: 600 } }>Sự kiện</th>
										<th style={ { padding: '6px 8px', textAlign: 'right', color: '#94a3b8', fontWeight: 600 } }>Tổng</th>
										<th style={ { padding: '6px 8px', textAlign: 'right', color: '#94a3b8', fontWeight: 600 } }>Thành công</th>
										<th style={ { padding: '6px 8px', textAlign: 'right', color: '#94a3b8', fontWeight: 600 } }>Tỉ lệ</th>
									</tr>
								</thead>
								<tbody>
									{ stats.by_event.map( ( ev, i ) => (
										<tr key={ i } style={ { borderBottom: '1px solid #f8fafc' } }>
											<td style={ { padding: '8px 8px' } }>
												<span>{ ev.label || ev.event_key }</span>
											</td>
											<td style={ { padding: '8px 8px', textAlign: 'right' } }>{ ev.count }</td>
											<td style={ { padding: '8px 8px', textAlign: 'right', color: '#16a34a' } }>{ ev.success }</td>
											<td style={ { padding: '8px 8px', textAlign: 'right' } }>
												{ ev.count ? Math.round( ( ev.success / ev.count ) * 100 ) : 0 }%
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ /* By Day */ }
					{ stats.by_day && stats.by_day.length > 0 && (
						<div style={ { marginBottom: 20 } }>
							<SectionTitle icon={ Calendar }>Theo ngày</SectionTitle>
							<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
								<thead>
									<tr style={ { borderBottom: '2px solid #f1f5f9' } }>
										<th style={ { padding: '6px 8px', textAlign: 'left', color: '#94a3b8', fontWeight: 600 } }>Ngày</th>
										<th style={ { padding: '6px 8px', textAlign: 'right', color: '#94a3b8', fontWeight: 600 } }>Tổng</th>
										<th style={ { padding: '6px 8px', textAlign: 'right', color: '#94a3b8', fontWeight: 600 } }>Thành công</th>
									</tr>
								</thead>
								<tbody>
									{ [ ...stats.by_day ].reverse().map( ( d, i ) => (
										<tr key={ i } style={ { borderBottom: '1px solid #f8fafc' } }>
											<td style={ { padding: '7px 8px', fontFamily: 'monospace' } }>{ d.date }</td>
											<td style={ { padding: '7px 8px', textAlign: 'right' } }>{ d.total }</td>
											<td style={ { padding: '7px 8px', textAlign: 'right', color: '#16a34a' } }>{ d.success }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ /* Top errors */ }
					{ stats.top_errors && stats.top_errors.length > 0 && (
						<div>
							<SectionTitle icon={ AlertTriangle }>Lỗi phổ biến</SectionTitle>
							{ stats.top_errors.map( ( e, i ) => (
								<div key={ i } style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 10px', background: '#fef2f2', borderRadius: 6, marginBottom: 6, fontSize: 12 } }>
									<code style={ { background: '#fecaca', padding: '2px 6px', borderRadius: 4, fontSize: 11, fontWeight: 700, color: '#dc2626' } }>{ e.esms_code }</code>
									<span style={ { flex: 1 } }>{ ESMS_ERRORS[ e.esms_code ] || e.desc || '—' }</span>
									<Badge style={ { background: '#fee2e2', color: '#dc2626', fontSize: 11 } }>{ e.count } lần</Badge>
								</div>
							) ) }
						</div>
					) }
				</>
			) }
		</div>
	);
}

// ─── Tab: Danh sách gửi ────────────────────────────────────────────────────
function SendsTab() {
	const [ items,     setItems    ] = useState( [] );
	const [ loading,   setLoading  ] = useState( false );
	const [ total,     setTotal    ] = useState( 0 );
	const [ page,      setPage     ] = useState( 1 );
	const PER = 50;
	const [ filters,   setFilters  ] = useState( { event_key: '', success: '', date_from: '', date_to: '' } );
	const [ exporting, setExporting] = useState( false );

	// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — dynamic CF7 forms in filter
	const eventGroups = useDynamicEventGroups();

	const loadSends = useCallback( () => {
		setLoading( true );
		const p = new URLSearchParams();
		p.set( 'page', String( page ) );
		p.set( 'per_page', String( PER ) );
		if ( filters.event_key ) { p.set( 'event_key', filters.event_key ); }
		if ( filters.success !== '' ) { p.set( 'success', filters.success ); }
		if ( filters.date_from ) { p.set( 'date_from', filters.date_from ); }
		if ( filters.date_to )   { p.set( 'date_to', filters.date_to ); }
		cgFetch( 'zns-automation/sends?' + p.toString() )
			.then( r => {
				if ( r && r.success && r.data ) {
					setItems( r.data.items || [] );
					setTotal( r.data.total || 0 );
				}
			} )
			.catch( () => {} )
			.finally( () => setLoading( false ) );
	}, [ page, filters ] );

	useEffect( () => { loadSends(); }, [ loadSends ] );

	async function handleExport( format ) {
		setExporting( true );
		try {
			const p = new URLSearchParams( { format } );
			if ( filters.event_key ) { p.set( 'event_key', filters.event_key ); }
			if ( filters.success !== '' ) { p.set( 'success', filters.success ); }
			if ( filters.date_from ) { p.set( 'date_from', filters.date_from ); }
			if ( filters.date_to )   { p.set( 'date_to', filters.date_to ); }
			const base = BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/';
			window.location.href = base.replace( /\/$/, '' ) + '/zns-automation/sends/export?' + p.toString() + '&_wpnonce=' + ( BOOT.restNonce || '' );
		} finally {
			setTimeout( () => setExporting( false ), 2000 );
		}
	}

	const totalPages = Math.ceil( total / PER );

	return (
		<div>
			{ /* Filters */ }
			<div style={ { display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap', alignItems: 'center' } }>
				<select
					value={ filters.event_key }
					onChange={ e => setFilters( f => ( { ...f, event_key: e.target.value } ) ) }
					style={ { fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }
				>
					<option value="">Tất cả sự kiện</option>
					{ eventGroups.map( g => (
						<optgroup key={ g.group } label={ g.label }>
							{ g.events.map( ev => <option key={ ev.key } value={ ev.key }>{ ev.label }</option> ) }
						</optgroup>
					) ) }
				</select>
				<select
					value={ filters.success }
					onChange={ e => setFilters( f => ( { ...f, success: e.target.value } ) ) }
					style={ { fontSize: 12, padding: '6px 8px', border: '1px solid #e2e8f0', borderRadius: 6 } }
				>
					<option value="">Tất cả kết quả</option>
					<option value="1">Thành công</option>
					<option value="0">Thất bại</option>
				</select>
				<Input type="date" value={ filters.date_from } onChange={ e => setFilters( f => ( { ...f, date_from: e.target.value } ) ) }
					style={ { fontSize: 12, width: 140 } } />
				<span style={ { fontSize: 12, color: '#94a3b8' } }>—</span>
				<Input type="date" value={ filters.date_to } onChange={ e => setFilters( f => ( { ...f, date_to: e.target.value } ) ) }
					style={ { fontSize: 12, width: 140 } } />
				<Button variant="ghost" onClick={ () => { setPage( 1 ); loadSends(); } } disabled={ loading } style={ { fontSize: 12 } }>
					<RefreshCw size={ 12 } /> Lọc
				</Button>
				<div style={ { marginLeft: 'auto', display: 'flex', gap: 6 } }>
					<Button variant="ghost" onClick={ () => handleExport( 'csv' ) } disabled={ exporting } style={ { fontSize: 12 } }>
						<Download size={ 12 } /> CSV
					</Button>
					<Button variant="ghost" onClick={ () => handleExport( 'xlsx' ) } disabled={ exporting } style={ { fontSize: 12 } }>
						<Download size={ 12 } /> Excel
					</Button>
				</div>
			</div>

			{ loading && <div style={ { padding: 24, textAlign: 'center', color: '#94a3b8', fontSize: 13 } }>Đang tải...</div> }

			{ ! loading && items.length === 0 && (
				<div style={ { padding: 32, textAlign: 'center', color: '#94a3b8', fontSize: 12 } }>
					<List size={ 28 } style={ { display: 'block', margin: '0 auto 8px', color: '#cbd5e1' } } />
					Chưa có lịch sử gửi nào.
				</div>
			) }

			{ ! loading && items.length > 0 && (
				<>
					<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 12 } }>
						<thead>
							<tr style={ { borderBottom: '2px solid #f1f5f9' } }>
								{ [ 'Tên quy tắc','Sự kiện','Số điện thoại','Template','Mã eSMS','Kết quả','Thời gian' ].map( h => (
									<th key={ h } style={ { padding: '7px 8px', textAlign: 'left', color: '#94a3b8', fontWeight: 600, fontSize: 11 } }>{ h }</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ items.map( item => (
								<tr key={ item.id } style={ { borderBottom: '1px solid #f8fafc' } }>
									<td style={ { padding: '8px 8px', fontWeight: 500 } }>{ item.rule_name || '—' }</td>
									<td style={ { padding: '8px 8px', color: '#64748b' } }>
										{ getEventDef( item.event_key )?.label || item.event_key }
									</td>
									<td style={ { padding: '8px 8px', fontFamily: 'monospace' } }>{ item.phone }</td>
									<td style={ { padding: '8px 8px' } }>
										<code style={ { background: '#f1f5f9', padding: '2px 5px', borderRadius: 4, fontSize: 11 } }>{ item.temp_id }</code>
									</td>
									<td style={ { padding: '8px 8px', fontFamily: 'monospace', fontSize: 11 } }>{ item.esms_code }</td>
									<td style={ { padding: '8px 8px' } }>
										{ item.success ? (
											<span style={ { color: '#16a34a', display: 'flex', alignItems: 'center', gap: 3 } }><CheckCircle2 size={ 12 } /> OK</span>
										) : (
											<span style={ { color: '#dc2626', display: 'flex', alignItems: 'center', gap: 3 } }><XCircle size={ 12 } /> Lỗi</span>
										) }
										{ item.sandbox ? <Badge style={ { background: '#fef9c3', color: '#854d0e', fontSize: 9, marginLeft: 4 } }>TEST</Badge> : null }
									</td>
									<td style={ { padding: '8px 8px', color: '#94a3b8', fontSize: 11 } }>{ item.sent_at }</td>
								</tr>
							) ) }
						</tbody>
					</table>

					{ /* Pagination */ }
					{ totalPages > 1 && (
						<div style={ { display: 'flex', justifyContent: 'center', gap: 6, marginTop: 14 } }>
							<Button variant="ghost" disabled={ page <= 1 } onClick={ () => setPage( p => p - 1 ) } style={ { fontSize: 12 } }>← Trước</Button>
							<span style={ { fontSize: 12, padding: '6px 12px', color: '#64748b' } }>{ page } / { totalPages } ({ total } mục)</span>
							<Button variant="ghost" disabled={ page >= totalPages } onClick={ () => setPage( p => p + 1 ) } style={ { fontSize: 12 } }>Sau →</Button>
						</div>
					) }
				</>
			) }
		</div>
	);
}

// ─── MAIN COMPONENT ────────────────────────────────────────────────────────
export default function ZnsTriggerTab() {
	// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — ZNS Trigger Rules main tab
	const SUBTABS = [
		{ id: 'rules',   label: '📋 Quy tắc gửi' },
		{ id: 'config',  label: '⚙️ Cấu hình eSMS' },
		{ id: 'test',    label: '🧪 Test & Debug' },
		{ id: 'logs',    label: '📄 Logs' },
		{ id: 'stats',   label: '📊 Phân tích' },
		{ id: 'sends',   label: '📬 Danh sách gửi' },
	];

	const [ activeSubTab, setActiveSubTab ] = useState( 'rules' );
	const [ showConfig,   setShowConfig   ] = useState( false );

	return (
		<div style={ { padding: '0 0 24px' } }>
			{ /* Sub-tab bar */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '1px solid #e2e8f0', marginBottom: 18 } }>
				{ SUBTABS.map( t => (
					<button
						key={ t.id }
						onClick={ () => setActiveSubTab( t.id ) }
						style={ {
							padding: '8px 16px', fontSize: 12, cursor: 'pointer',
							background: 'none', border: 'none',
							borderBottom: activeSubTab === t.id ? '2px solid #6366f1' : '2px solid transparent',
							color: activeSubTab === t.id ? '#4338ca' : '#64748b',
							fontWeight: activeSubTab === t.id ? 700 : 400,
							marginBottom: -1,
						} }
					>{ t.label }</button>
				) ) }
			</div>

			{ activeSubTab === 'rules'  && <RulesTab onOpenSettings={ () => setActiveSubTab( 'config' ) } /> }
			{ activeSubTab === 'config' && <ConfigTab /> }
			{ activeSubTab === 'test'   && <TestTab /> }
			{ activeSubTab === 'logs'   && <LogsTab /> }
			{ activeSubTab === 'stats'  && <StatsTab /> }
			{ activeSubTab === 'sends'  && <SendsTab /> }
		</div>
	);
}
