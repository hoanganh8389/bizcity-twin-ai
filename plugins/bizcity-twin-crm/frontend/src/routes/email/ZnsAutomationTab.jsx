// [2026-06-25 Johnny Chu] PHASE-CRM-ZNS-AUTO — ZNS Automation tab in Email & CF7 page
// Lists all CF7 forms, per-form ZNS config, integrated test send per form
// Calls bizcity-channel/v1 (BOOT.channelRestUrl) — same-origin, X-WP-Nonce shared
import React, { useState, useCallback, useEffect } from 'react';
import {
	CheckCircle2, XCircle, Settings, FlaskConical, Save, Plus, Trash2,
	Bell, AlertTriangle, RefreshCw, ExternalLink,
} from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Label, Textarea } from '../../components/ui/input.jsx';

const BOOT   = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const CH_URL = BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/';
const NONCE  = BOOT.restNonce || '';

// ── Channel Gateway fetch helper ────────────────────────────────────────────
async function cgFetch( path, opts = {} ) {
	const url = CH_URL.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' );
	const res  = await fetch( url, {
		credentials: 'same-origin',
		headers: Object.assign( {
			'Content-Type':  'application/json',
			'X-WP-Nonce':    NONCE,
		}, opts.headers || {} ),
		...opts,
	} );
	return res.json();
}

// ── OA ID Selector — loads from Channel Gateway registry ────────────────────
function OaSelector( { value, onChange, placeholder = 'Chọn OA đã cấu hình...' } ) {
	const [ accounts, setAccounts ] = useState( null ); // null = loading
	const [ manual, setManual ]     = useState( false );

	useEffect( () => {
		cgFetch( 'cf7/zns-oa-accounts' )
			.then( ( r ) => setAccounts( r && r.success ? ( r.data || [] ) : [] ) )
			.catch( () => setAccounts( [] ) );
	}, [] );

	if ( accounts === null ) {
		return <div style={ { fontSize: 12, color: '#94a3b8', padding: '6px 0' } }>Đang tải danh sách OA...</div>;
	}
	if ( accounts.length === 0 || manual ) {
		return (
			<div>
				<Input value={ value } onChange={ ( e ) => onChange( e.target.value ) } placeholder="Nhập OA ID thủ công" />
				{ accounts.length > 0 && (
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
				{ accounts.map( ( a ) => (
					<option key={ a.oa_id } value={ a.oa_id }>{ a.label } — { a.oa_id }</option>
				) ) }
			</select>
			<button onClick={ () => setManual( true ) } style={ { fontSize: 11, color: '#6366f1', background: 'none', border: 'none', cursor: 'pointer', padding: 0, marginTop: 2 } }>
				Nhập OA ID khác thủ công →
			</button>
		</div>
	);
}

// ── CF7 Field Selector — shows field names + sample values from form ─────────
function Cf7FieldSelect( { fields, value, onChange, placeholder = 'Chọn field từ form...', showSample = true } ) {
	if ( ! fields || fields.length === 0 ) {
		// No fields loaded — show text input so saved value is always visible
		return (
			<Input
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				placeholder="Nhập tên field CF7 (vd: parent-name)"
			/>
		);
	}
	// If saved value not present in fields list, add it as a visible option
	const inList = value && fields.some( ( f ) => f.name === value );
	return (
		<select
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
			style={ {
				width: '100%', padding: '7px 10px', borderRadius: 6,
				border: '1px solid #e2e8f0', fontSize: 12,
				background: '#fff', color: value ? '#334155' : '#94a3b8',
			} }
		>
			<option value="">{ placeholder }</option>
			{ ! inList && value && (
				<option value={ value }>{ value } ✓ (đã lưu)</option>
			) }
			{ fields.map( ( f ) => (
				<option key={ f.name } value={ f.name }>
					{ f.name }{ showSample && f.sample ? ' — ' + f.sample.substring( 0, 40 ) : '' }
				</option>
			) ) }
		</select>
	);
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function StatusDot( { on } ) {
	return (
		<span style={ {
			display: 'inline-block', width: 8, height: 8, borderRadius: '50%',
			background: on ? '#16a34a' : '#cbd5e1', marginRight: 6,
		} } />
	);
}

function ConfigBadge( { cfg } ) {
	if ( ! cfg ) { return <span style={ { fontSize: 11, color: '#94a3b8' } }>Chưa cấu hình</span>; }
	if ( ! cfg.enabled ) {
		return <span style={ { fontSize: 11, color: '#94a3b8', background: '#f1f5f9', padding: '2px 8px', borderRadius: 10 } }>Tắt</span>;
	}
	return <span style={ { fontSize: 11, color: '#16a34a', background: '#f0fdf4', padding: '2px 8px', borderRadius: 10, fontWeight: 600 } }>✓ Bật</span>;
}

// ── ZNS Settings panel (global credentials) ─────────────────────────────────
function ZnsGlobalSettings( { onClose } ) {
	const [ d, setD ]       = useState( { api_key: '', secret_key: '', oa_id: '' } );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ]   = useState( false );
	const [ msg, setMsg ]         = useState( null );

	useEffect( () => {
		cgFetch( 'cf7/zns-settings' ).then( ( r ) => {
			if ( r && r.success && r.data ) {
				setD( { api_key: r.data.api_key || '', secret_key: r.data.secret_key || '', oa_id: r.data.oa_id || '' } );
			}
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	const save = async () => {
		setSaving( true );
		setMsg( null );
		try {
			const r = await cgFetch( 'cf7/zns-settings', { method: 'POST', body: JSON.stringify( d ) } );
			setMsg( r.success ? { ok: true, text: 'Đã lưu cài đặt.' } : { ok: false, text: r.error || 'Lưu thất bại.' } );
			if ( r.success && r.data ) {
				setD( { api_key: r.data.api_key || '', secret_key: r.data.secret_key ? '***' : '', oa_id: r.data.oa_id || '' } );
			}
		} catch ( e ) {
			setMsg( { ok: false, text: e.message } );
		}
		setSaving( false );
	};

	return (
		<div>
			{ loading && <div style={ { color: '#94a3b8', fontSize: 12 } }>Đang tải...</div> }
			{ ! loading && (
				<div style={ { display: 'flex', flexDirection: 'column', gap: 12 } }>
					<div style={ { padding: '10px 14px', background: '#fffbeb', borderRadius: 8, fontSize: 12, color: '#92400e', border: '1px solid #fde68a' } }>
						⚠️ Để tránh lỗi <strong>799 (OAID is not config)</strong>: OA ID phải trùng chính xác với Zalo Official Account đã được liên kết với ApiKey trong tài khoản eSMS. Đăng nhập eSMS → <em>Dịch vụ ZNS</em> → kiểm tra OA đã liên kết.
					</div>
					<div>
						<Label>eSMS API Key</Label>
						<Input value={ d.api_key } onChange={ ( e ) => setD( ( p ) => ( { ...p, api_key: e.target.value } ) ) } placeholder="ApiKey từ eSMS dashboard" />
					</div>
					<div>
						<Label>eSMS Secret Key</Label>
						<Input type="password" value={ d.secret_key } onChange={ ( e ) => setD( ( p ) => ( { ...p, secret_key: e.target.value } ) ) } placeholder="Nhập mới để đổi (để trống = giữ nguyên)" />
					</div>
					<div>
						<Label>Zalo OA ID (OAID)</Label>
						<OaSelector value={ d.oa_id } onChange={ ( v ) => setD( ( p ) => ( { ...p, oa_id: v } ) ) } placeholder="Chọn Zalo OA đã cấu hình..." />
						<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>
							Phải khớp với OA đã liên kết trong eSMS ZNS.
						</p>
					</div>
					{ msg && (
						<div style={ { padding: '8px 12px', borderRadius: 6, background: msg.ok ? '#f0fdf4' : '#fef2f2', color: msg.ok ? '#16a34a' : '#dc2626', fontSize: 12 } }>
							{ msg.text }
						</div>
					) }
					<div style={ { display: 'flex', gap: 8 } }>
						<Button variant="primary" onClick={ save } disabled={ saving }>
							<Save size={ 13 } /> { saving ? 'Đang lưu...' : 'Lưu cài đặt' }
						</Button>
						{ onClose && <Button variant="ghost" onClick={ onClose }>Đóng</Button> }
					</div>
				</div>
			) }
		</div>
	);
}

// ── Per-form ZNS Config + Test Send ─────────────────────────────────────────
function ZnsFormSheet( { form, onClose } ) {
	const formId = form?.form_id;
	const [ cfg, setCfg ]     = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ]   = useState( false );
	const [ saveMsg, setSaveMsg ] = useState( null );

	// CF7 form field names + sample values (from latest submission)
	const [ formFields, setFormFields ] = useState( [] );

	// Test panel state — testVars now has { key, field, value } where field = CF7 field name
	const [ testPhone, setTestPhone ]   = useState( '' );
	const [ testVars,  setTestVars ]    = useState( [ { key: '', field: '', value: '' } ] );
	const [ testSandbox, setTestSandbox ] = useState( true ); // default: sandbox safe
	const [ testing,   setTesting ]     = useState( false );
	const [ testResult, setTestResult ] = useState( null );

	// Load config + form fields in parallel
	useEffect( () => {
		if ( ! formId ) { return; }
		Promise.all( [
			cgFetch( 'cf7/forms/' + formId + '/zns-config' ).catch( () => null ),
			cgFetch( 'cf7/forms/' + formId + '/fields' ).catch( () => null ),
		] ).then( ( [ rcfg, rfields ] ) => {
			if ( rcfg && rcfg.success ) {
				const c = rcfg.data || {};
				setCfg( {
					enabled:     !! c.enabled,
					temp_id:     c.temp_id || '',
					oa_id:       c.oa_id || '',
					sandbox:     c.sandbox !== false,
					campaign_id: c.campaign_id || '',
					temp_vars:   Array.isArray( c.temp_vars ) && c.temp_vars.length > 0 ? c.temp_vars : [ { var_name: '', source: 'mapped', mapped_field: '', literal_value: '' } ],
				} );
			} else if ( ! rcfg || ! rcfg.success ) {
				// fallback default so cfg is never null
				setCfg( {
					enabled: false, temp_id: '', oa_id: '', sandbox: true,
					campaign_id: '', temp_vars: [ { var_name: '', source: 'mapped', mapped_field: '', literal_value: '' } ],
				} );
			}
			if ( rfields && rfields.success && Array.isArray( rfields.data ) ) {
				setFormFields( rfields.data );
			}
			setLoading( false );
		} ).catch( () => {
			// If both fail, still set a safe default cfg so UI doesn't crash
			setCfg( { enabled: false, temp_id: '', oa_id: '', sandbox: true, campaign_id: '', temp_vars: [ { var_name: '', source: 'mapped', mapped_field: '', literal_value: '' } ] } );
			setLoading( false );
		} );
	}, [ formId ] );

	const set = ( k, v ) => setCfg( ( p ) => ( { ...p, [ k ]: v } ) );

	const updateVar = ( i, field, val ) => setCfg( ( p ) => ( {
		...p,
		temp_vars: p.temp_vars.map( ( tv, idx ) => idx === i ? { ...tv, [ field ]: val } : tv ),
	} ) );
	const addVar    = () => setCfg( ( p ) => ( { ...p, temp_vars: [ ...p.temp_vars, { var_name: '', source: 'mapped', mapped_field: '', literal_value: '' } ] } ) );
	const removeVar = ( i ) => setCfg( ( p ) => ( { ...p, temp_vars: p.temp_vars.filter( ( _, idx ) => idx !== i ) } ) );

	const save = async () => {
		setSaving( true ); setSaveMsg( null );
		try {
			const r = await cgFetch( 'cf7/forms/' + formId + '/zns-config', {
				method: 'POST',
				body: JSON.stringify( { formId, ...cfg } ),
			} );
		if ( r.success ) {
			// Sync cfg with server-normalised data so UI always reflects what's stored
			const c = r.data || {};
			setCfg( ( prev ) => ( {
				...prev,
				enabled:     !! c.enabled,
				temp_id:     c.temp_id  || prev.temp_id,
				oa_id:       c.oa_id    || prev.oa_id,
				sandbox:     !! c.sandbox,
				campaign_id: c.campaign_id || '',
				temp_vars:   Array.isArray( c.temp_vars ) && c.temp_vars.length > 0 ? c.temp_vars : prev.temp_vars,
			} ) );
			setSaveMsg( { ok: true, text: 'Đã lưu.' } );
		} else {
			setSaveMsg( { ok: false, text: r.error || 'Lỗi khi lưu.' } );
		}
		} catch ( e ) { setSaveMsg( { ok: false, text: e.message } ); }
		setSaving( false );
	};

	// Build test mapped_fields from testVars
	const handleTest = async () => {
		setTesting( true ); setTestResult( null );
		try {
			const mapped = {};
			testVars.forEach( ( v ) => { if ( v.key.trim() ) { mapped[ v.key.trim() ] = v.value; } } );
			const r = await cgFetch( 'cf7/forms/' + formId + '/zns-test', {
				method: 'POST',
				body: JSON.stringify( { phone: testPhone.trim(), mapped_fields: mapped, force_sandbox: testSandbox } ),
			} );
			// r.success wraps data
			const d = ( r && r.success && r.data ) ? r.data : r;
			setTestResult( d );
		} catch ( e ) { setTestResult( { sent: false, error: e.message } ); }
		setTesting( false );
	};

	if ( loading ) {
		return <div style={ { padding: 24, color: '#94a3b8', fontSize: 13 } }>Đang tải cấu hình...</div>;
	}

	const SOURCE_OPTIONS = [ { v: 'mapped', l: 'Từ CF7 field' }, { v: 'literal', l: 'Giá trị cố định' } ];

	return (
		<div style={ { display: 'flex', flexDirection: 'column', gap: 0 } }>
			{ /* ── Config section ── */ }
			<section style={ { marginBottom: 20 } }>
				<SectionTitle icon="⚙️">Cấu hình ZNS</SectionTitle>

				<div style={ { display: 'flex', flexDirection: 'column', gap: 10 } }>
					{ /* Enable toggle */ }
					<div style={ { display: 'flex', alignItems: 'center', gap: 10 } }>
						<input type="checkbox" id="zns-enabled" checked={ !! cfg.enabled } onChange={ ( e ) => set( 'enabled', e.target.checked ) } style={ { width: 16, height: 16 } } />
						<label htmlFor="zns-enabled" style={ { fontSize: 13, color: '#334155', cursor: 'pointer' } }>
							Bật tự động gửi ZNS khi form được submit
						</label>
					</div>

					{ /* TempID */ }
					<div>
						<Label>Template ID (TempID) *</Label>
						<Input value={ cfg.temp_id } onChange={ ( e ) => set( 'temp_id', e.target.value ) } placeholder="Ví dụ: 595298" />
						<p style={ { fontSize: 11, color: '#94a3b8', marginTop: 2 } }>Lấy từ eSMS dashboard → ZNS Templates.</p>
					</div>

					{ /* OA ID (override per-form) */ }
					<div>
						<Label>OA ID (tuỳ chọn — ghi đè global)</Label>
						<OaSelector value={ cfg.oa_id } onChange={ ( v ) => set( 'oa_id', v ) } placeholder="Để trống → dùng OA ID global" />
					</div>

					{ /* Campaign ID */ }
					<div>
						<Label>Campaign ID (tuỳ chọn)</Label>
						<Input value={ cfg.campaign_id } onChange={ ( e ) => set( 'campaign_id', e.target.value ) } placeholder="Nhãn phân loại chiến dịch trong eSMS" />
					</div>

					{ /* Sandbox */ }
					<div style={ { display: 'flex', alignItems: 'center', gap: 8 } }>
						<input type="checkbox" id="zns-sandbox" checked={ !! cfg.sandbox } onChange={ ( e ) => set( 'sandbox', e.target.checked ) } style={ { width: 14, height: 14 } } />
						<label htmlFor="zns-sandbox" style={ { fontSize: 12, color: '#64748b', cursor: 'pointer' } }>
							Sandbox mode (eSMS không gửi tin thật, không tính phí)
						</label>
					</div>

					{ /* TempData vars */ }
					<div>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 } }>
							<Label style={ { marginBottom: 0 } }>Biến TempData (variables của template)</Label>
							<button onClick={ addVar } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#6366f1', fontSize: 12, display: 'flex', alignItems: 'center', gap: 3 } }>
								<Plus size={ 12 } /> Thêm biến
							</button>
						</div>
						<p style={ { fontSize: 11, color: '#94a3b8', marginBottom: 8 } }>
							Mapping biến template eSMS ↔ field CF7. Tên biến phải khớp tên trong template ZNS (ví dụ: <code>customer_name</code>).
						</p>
						{ cfg.temp_vars.map( ( tv, i ) => (
							<div key={ i } style={ { display: 'grid', gridTemplateColumns: '1fr 100px 1fr auto', gap: 6, marginBottom: 6, alignItems: 'center' } }>
								<Input placeholder="Tên biến template" value={ tv.var_name } onChange={ ( e ) => updateVar( i, 'var_name', e.target.value ) } />
								<select
									value={ tv.source || 'mapped' }
									onChange={ ( e ) => updateVar( i, 'source', e.target.value ) }
									style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '5px 6px' } }
								>
									{ SOURCE_OPTIONS.map( ( o ) => <option key={ o.v } value={ o.v }>{ o.l }</option> ) }
								</select>
								{ ( tv.source || 'mapped' ) === 'mapped' ? (
									<Cf7FieldSelect
										fields={ formFields }
										value={ tv.mapped_field || tv.field || '' }
										onChange={ ( v ) => {
											// [2026-06-25 Johnny Chu] PHASE-CG-CF7-ZNS — set both field and mapped_field
											// so PHP sanitize_temp_vars always finds a non-empty value regardless of which key it reads
											updateVar( i, 'mapped_field', v );
											updateVar( i, 'field', v );
										} }
										placeholder="Chọn field CF7..."
										showSample={ false }
									/>
								) : (
									<Input placeholder="Giá trị cố định" value={ tv.literal_value || '' } onChange={ ( e ) => updateVar( i, 'literal_value', e.target.value ) } />
								) }
								{ cfg.temp_vars.length > 1 && (
									<button onClick={ () => removeVar( i ) } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 } }>
										<Trash2 size={ 13 } />
									</button>
								) }
							</div>
						) ) }
					</div>

					{ saveMsg && (
						<div style={ { padding: '8px 12px', borderRadius: 6, background: saveMsg.ok ? '#f0fdf4' : '#fef2f2', color: saveMsg.ok ? '#16a34a' : '#dc2626', fontSize: 12 } }>
							{ saveMsg.text }
						</div>
					) }
					<Button variant="primary" onClick={ save } disabled={ saving } style={ { alignSelf: 'flex-start' } }>
						<Save size={ 13 } /> { saving ? 'Đang lưu...' : 'Lưu cấu hình' }
					</Button>
				</div>
			</section>

			{ /* ── Test Send section ── */ }
			<section style={ { borderTop: '1px solid #e2e8f0', paddingTop: 20 } }>
				<SectionTitle icon="🧪">Gửi thử ZNS (Sandbox)</SectionTitle>
				<p style={ { fontSize: 12, color: '#64748b', marginBottom: 12 } }>
					Gửi thử với TempID và OA ID đã cấu hình bên trên. Mặc định <strong>Sandbox=1</strong> — không tính phí thật. Bỏ tick để gửi thật.
				</p>
				<div style={ { display: 'flex', flexDirection: 'column', gap: 10 } }>
					<div>
						<Label>Số điện thoại nhận *</Label>
						<Input value={ testPhone } onChange={ ( e ) => setTestPhone( e.target.value ) } placeholder="0901234567" />
					</div>

					{ /* TempData for test — dynamic key-value rows */ }
					<div>
						<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 } }>
							<Label style={ { marginBottom: 0 } }>Giá trị biến (TempData) cho test</Label>
							<button onClick={ () => setTestVars( ( v ) => [ ...v, { key: '', field: '', value: '' } ] ) }
								style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#6366f1', fontSize: 12, display: 'flex', alignItems: 'center', gap: 3 } }>
								<Plus size={ 12 } /> Thêm
							</button>
						</div>
						<p style={ { fontSize: 11, color: '#94a3b8', marginBottom: 6 } }>
							Chọn biến template (ví dụ: <code>ten_khach_hang</code>) → chọn field CF7 → giá trị tự điền từ lần submit gần nhất. Có thể sửa trước khi gửi.
						</p>
						{ formFields.length > 0 && (
							<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 6, marginBottom: 4, alignItems: 'center' } }>
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Tên biến template</span>
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Field CF7</span>
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Giá trị gửi đi</span>
								<span />
							</div>
						) }
						{ ! formFields.length && (
							<div style={ { display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 4 } }>
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Tên biến trong template</span>
								<span style={ { fontSize: 11, color: '#64748b', fontWeight: 600 } }>Giá trị gửi đi</span>
								<span />
							</div>
						) }
						{ testVars.map( ( tv, i ) => (
							formFields.length > 0 ? (
								// 3-column mode: var name | field selector (auto-fill) | editable value
								<div key={ i } style={ { display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: 6, marginBottom: 6, alignItems: 'center' } }>
									<Input
										placeholder="ten_khach_hang"
										value={ tv.key }
										onChange={ ( e ) => setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, key: e.target.value } : r ) ) }
									/>
									<Cf7FieldSelect
										fields={ formFields }
										value={ tv.field || '' }
										onChange={ ( fieldName ) => {
											const sample = formFields.find( ( f ) => f.name === fieldName )?.sample || '';
											setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, field: fieldName, value: sample } : r ) );
										} }
										placeholder="Chọn field CF7..."
										showSample={ true }
									/>
									<Input
										placeholder="Giá trị gửi đi (có thể sửa)"
										value={ tv.value }
										onChange={ ( e ) => setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, value: e.target.value } : r ) ) }
									/>
									{ testVars.length > 1 && (
										<button onClick={ () => setTestVars( ( v ) => v.filter( ( _, idx ) => idx !== i ) ) }
											style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 } }>
											<Trash2 size={ 13 } />
										</button>
									) }
								</div>
							) : (
								// 2-column fallback (no submissions yet)
								<div key={ i } style={ { display: 'grid', gridTemplateColumns: '1fr 2fr auto', gap: 6, marginBottom: 6, alignItems: 'center' } }>
									<Input placeholder="ten_khach_hang" value={ tv.key } onChange={ ( e ) => setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, key: e.target.value } : r ) ) } />
									<Input placeholder="Chu Hoàng Anh (giá trị thực tế)" value={ tv.value } onChange={ ( e ) => setTestVars( ( v ) => v.map( ( r, idx ) => idx === i ? { ...r, value: e.target.value } : r ) ) } />
									{ testVars.length > 1 && (
										<button onClick={ () => setTestVars( ( v ) => v.filter( ( _, idx ) => idx !== i ) ) }
											style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 } }>
											<Trash2 size={ 13 } />
										</button>
									) }
								</div>
							)
						) ) }
					</div>

					{ /* Sandbox override */ }
					<div style={ { display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', background: testSandbox ? '#fffbeb' : '#fef2f2', borderRadius: 6, border: '1px solid ' + ( testSandbox ? '#fde68a' : '#fca5a5' ) } }>
						<input
							type="checkbox" id="test-sandbox-toggle"
							checked={ testSandbox }
							onChange={ ( e ) => setTestSandbox( e.target.checked ) }
							style={ { width: 14, height: 14, cursor: 'pointer' } }
						/>
						<label htmlFor="test-sandbox-toggle" style={ { fontSize: 12, cursor: 'pointer', color: testSandbox ? '#92400e' : '#991b1b', fontWeight: 500 } }>
							{ testSandbox
								? '🧪 Sandbox=1 — eSMS không gửi tin thật, không tính phí'
								: '🚨 Gửi THẬT — sẽ gửi tin ZNS thật, tính phí. Bỏ tick để quay về sandbox.' }
						</label>
					</div>

					<Button
						variant="primary"
						onClick={ handleTest }
						disabled={ ! testPhone.trim() || ! cfg.temp_id.trim() || testing }
						style={ { alignSelf: 'flex-start', background: testSandbox ? undefined : '#dc2626' } }
					>
						<FlaskConical size={ 13 } /> { testing ? 'Đang gửi...' : ( testSandbox ? '📤 Gửi thử (Sandbox)' : '🚨 Gửi thật (Real)' ) }
					</Button>

					{ ! cfg.temp_id && (
						<div style={ { fontSize: 12, color: '#f59e0b' } }>⚠️ Chưa có TempID — hãy nhập và lưu cấu hình trước.</div>
					) }

					{ testResult && (
						<div style={ {
							padding: '12px 16px', borderRadius: 8, marginTop: 4,
							background: testResult.sent ? '#f0fdf4' : '#fef2f2',
							border: '1px solid ' + ( testResult.sent ? '#86efac' : '#fca5a5' ),
						} }>
							{ testResult.sent ? (
								<>
									<div style={ { color: '#16a34a', fontWeight: 700, fontSize: 13, display: 'flex', gap: 6, alignItems: 'center' } }>
										<CheckCircle2 size={ 14 } /> Gửi thành công!
									</div>
									<div style={ { fontSize: 12, color: '#166534', marginTop: 8, display: 'flex', flexDirection: 'column', gap: 3 } }>
										<span>📧 Phone: <strong>{ testResult.phone }</strong></span>
										<span>📋 TempID: <strong>{ testResult.temp_id }</strong></span>
										<span>🏢 OA ID: <strong>{ testResult.oa_id }</strong></span>
										<span>🆔 SMSID: <strong>{ testResult.sms_id || '(pending)' }</strong></span>
										<span>🔢 Code: <strong>{ testResult.code }</strong></span>
								{ testResult.sandbox
									? <span style={ { color: '#92400e', fontSize: 11, background: '#fffbeb', padding: '2px 6px', borderRadius: 4 } }>🧪 Sandbox — không gửi tin thật</span>
									: <span style={ { color: '#991b1b', fontSize: 11, background: '#fef2f2', padding: '2px 6px', borderRadius: 4 } }>🚨 Gửi THẬT — đã gửi tin ZNS thật</span>
								}
									</div>
									{ testResult.temp_data && Object.keys( testResult.temp_data ).length > 0 && (
										<pre style={ { fontSize: 11, background: '#fff', padding: '6px 8px', borderRadius: 4, marginTop: 8, overflow: 'auto' } }>
											{ JSON.stringify( testResult.temp_data, null, 2 ) }
										</pre>
									) }
								</>
							) : (
								<div style={ { color: '#dc2626', fontSize: 13 } }>
									<div style={ { display: 'flex', gap: 6, alignItems: 'flex-start', fontWeight: 700 } }>
										<XCircle size={ 14 } style={ { marginTop: 2 } } />
										<div>
											Gửi thất bại
											{ testResult.code === '799' && (
												<div style={ { marginTop: 4, fontWeight: 400, fontSize: 12, color: '#78350f', background: '#fffbeb', padding: '6px 10px', borderRadius: 6, border: '1px solid #fde68a' } }>
													<strong>Lỗi 799 — OAID is not config:</strong> OA ID chưa được liên kết với ApiKey trong tài khoản eSMS. Vào eSMS dashboard → <em>Dịch vụ ZNS</em> → liên kết Zalo OA Account với tài khoản eSMS.
												</div>
											) }
										</div>
									</div>
									<div style={ { fontSize: 12, marginTop: 6 } }>{ testResult.error }</div>
									{ testResult.code && <div style={ { fontSize: 11, color: '#94a3b8', marginTop: 3 } }>eSMS code: { testResult.code }</div> }
								</div>
							) }
						</div>
					) }
				</div>
			</section>
		</div>
	);
}

function SectionTitle( { icon, children } ) {
	return (
		<div style={ { fontWeight: 700, fontSize: 13, color: '#334155', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 6 } }>
			{ icon } { children }
		</div>
	);
}

// ── Main ZnsAutomationTab ─────────────────────────────────────────────────────
export default function ZnsAutomationTab() {
	const [ forms, setForms ]           = useState( [] );
	const [ configs, setConfigs ]       = useState( {} );
	const [ loadingForms, setLoadingForms ] = useState( true );
	const [ selectedForm, setSelectedForm ] = useState( null ); // { id, title }
	const [ showSettings, setShowSettings ] = useState( false );

	const loadForms = useCallback( () => {
		setLoadingForms( true );
		cgFetch( 'cf7/forms' ).then( ( r ) => {
			const list = ( r && r.success && Array.isArray( r.data ) ) ? r.data : [];
			setForms( list );
			// Load config for each form
			const cfg = {};
			Promise.all( list.map( ( f ) =>
				cgFetch( 'cf7/forms/' + f.form_id + '/zns-config' )
					.then( ( cr ) => { if ( cr && cr.success ) { cfg[ f.form_id ] = cr.data; } } )
					.catch( () => {} )
			) ).then( () => setConfigs( { ...cfg } ) );
			setLoadingForms( false );
		} ).catch( () => setLoadingForms( false ) );
	}, [] );

	useEffect( () => { loadForms(); }, [ loadForms ] );

	return (
		<div>
			{ /* ── Header ── */ }
			<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 } }>
				<div>
					<h3 style={ { fontWeight: 700, fontSize: 15, margin: 0, display: 'flex', alignItems: 'center', gap: 6 } }>
						<Bell size={ 15 } style={ { color: '#f59e0b' } } /> Quy tắc tự động gửi ZNS
					</h3>
					<p style={ { fontSize: 12, color: '#64748b', margin: '2px 0 0' } }>
						Mỗi form CF7 có thể gắn một template ZNS. Khi form được submit → hệ thống tự động gửi ZNS về số điện thoại khách hàng.
					</p>
				</div>
				<div style={ { display: 'flex', gap: 8 } }>
					<Button variant="ghost" onClick={ loadForms } disabled={ loadingForms }>
						<RefreshCw size={ 13 } className={ loadingForms ? 'spin' : '' } />
					</Button>
					<Button onClick={ () => setShowSettings( true ) }>
						<Settings size={ 13 } /> Cài đặt eSMS
					</Button>
				</div>
			</div>

			{ /* ── Error 799 hint ── */ }
			<div style={ { padding: '10px 14px', background: '#fffbeb', borderRadius: 8, fontSize: 12, color: '#92400e', border: '1px solid #fde68a', marginBottom: 14 } }>
				<strong>⚠️ Về lỗi 799 (OAID is not config):</strong> OA ID phải là ID Zalo Official Account đã được liên kết với ApiKey trong tài khoản eSMS.
				Đăng nhập <a href="https://esms.vn" target="_blank" rel="noreferrer" style={ { color: '#92400e' } }>eSMS dashboard</a> → Dịch vụ ZNS → Liên kết Zalo OA → kiểm tra OA đang active.
			</div>

			{ /* ── Forms table ── */ }
			{ loadingForms && <div style={ { color: '#94a3b8', fontSize: 13 } }>Đang tải danh sách form...</div> }
			{ ! loadingForms && forms.length === 0 && (
				<div style={ { textAlign: 'center', padding: '40px 0', color: '#94a3b8', fontSize: 13 } }>
					Chưa có form CF7 nào. Cài đặt Contact Form 7 và tạo ít nhất một form.
				</div>
			) }
			{ forms.length > 0 && (
				<table style={ { width: '100%', borderCollapse: 'collapse', fontSize: 13 } }>
					<thead>
						<tr style={ { background: '#f8fafc' } }>
							<th style={ TH }>Tên form</th>
							<th style={ TH }>Form ID</th>
							<th style={ TH }>Template ID</th>
							<th style={ TH }>Trạng thái ZNS</th>
							<th style={ TH }>Thao tác</th>
						</tr>
					</thead>
					<tbody>
						{ forms.map( ( f ) => {
						const cfg = configs[ f.form_id ];
						return (
							<tr key={ f.form_id } style={ { borderBottom: '1px solid #f1f5f9' } }>
								<td style={ { ...TD, fontWeight: 600, color: '#334155' } }>{ f.form_title }</td>
								<td style={ { ...TD, fontFamily: 'monospace', color: '#64748b' } }>cf7_form_{ f.form_id }</td>
									<td style={ { ...TD, fontFamily: 'monospace', color: cfg?.temp_id ? '#16a34a' : '#94a3b8' } }>
										{ cfg?.temp_id || '—' }
									</td>
									<td style={ TD }><ConfigBadge cfg={ cfg } /></td>
									<td style={ TD }>
										<button
											onClick={ () => setSelectedForm( f ) }
											style={ { display: 'flex', alignItems: 'center', gap: 4, background: 'none', border: '1px solid #e2e8f0', borderRadius: 6, padding: '4px 12px', cursor: 'pointer', fontSize: 12, color: '#6366f1', fontWeight: 600 } }
										>
											<Settings size={ 12 } /> Cấu hình & Test
										</button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ /* ── Form config + test sheet ── */ }
			<Sheet open={ !! selectedForm } onOpenChange={ ( o ) => { if ( ! o ) { setSelectedForm( null ); loadForms(); } } }>
				<SheetContent side="right" style={ { width: 580, maxWidth: '95vw', overflowY: 'auto' } }>
					<SheetHeader>
						<SheetTitle>
							{ selectedForm?.form_title }
							<span style={ { fontSize: 12, fontWeight: 400, color: '#64748b', marginLeft: 8 } }>
								ZNS Config
							</span>
						</SheetTitle>
					</SheetHeader>
					<SheetBody>
						{ selectedForm && <ZnsFormSheet form={ selectedForm } onClose={ () => { setSelectedForm( null ); loadForms(); } } /> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* ── Global settings sheet ── */ }
			<Sheet open={ showSettings } onOpenChange={ ( o ) => { if ( ! o ) { setShowSettings( false ); } } }>
				<SheetContent side="right" style={ { width: 480 } }>
					<SheetHeader>
						<SheetTitle>⚙️ Cài đặt eSMS ZNS — Global</SheetTitle>
					</SheetHeader>
					<SheetBody>
						<ZnsGlobalSettings onClose={ () => setShowSettings( false ) } />
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}

const TH = { padding: '8px 12px', textAlign: 'left', color: '#64748b', fontWeight: 600, borderBottom: '1px solid #e2e8f0', fontSize: 12 };
const TD = { padding: '10px 12px', color: '#334155', verticalAlign: 'middle' };
