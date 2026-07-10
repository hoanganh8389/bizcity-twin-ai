/**
 * BroadcastCreateDialog — 4-step wizard for ZNS + Email mass-send
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Rebuilt for CRM SPA.
 * [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Fix: OA dropdown, Enter key, channel filter, parseFile rows.
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — ZnsTemplatePicker replaces manual ZNS fields.
 * Step 1: Name + Type  |  Step 2: Channel config
 * Step 3: Recipients   |  Step 4: Review + Submit
 */
import React, { useState, useRef, useCallback, useEffect } from 'react';
import { X, Plus, Trash2, Upload, Download, Info, Users, CheckCircle } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import {
Dialog,
DialogContent,
DialogHeader,
DialogTitle,
DialogFooter,
} from '../../components/ui/dialog.jsx';
import {
useCreateBroadcastZnsMutation,
useParseFileMutation,
useGetContactsQuery,
useGetZnsOaAccountsQuery,
} from '../../redux/api/cgBroadcastApi.js';
import ZnsTemplatePicker from '../../components/ZnsTemplatePicker.jsx';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

function templateUrl() {
const base = ( BOOT.channelRestUrl || '/wp-json/bizcity-channel/v1/' ).replace( /\/$/, '' );
return base + '/broadcasts/template';
}

/* ── helpers ─────────────────────────────────────────────────────── */

function emptyTempVar() {
return { var_name: '', source: 'recipient', field: 'name', value: '' };
}

function StepIndicator( { step, total } ) {
return (
<div className="flex items-center gap-2 mb-6">
{ Array.from( { length: total } ).map( ( _, i ) => (
<React.Fragment key={ i }>
<div className={ 'w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold ' +
( i + 1 < step  ? 'bg-indigo-600 text-white' :
  i + 1 === step ? 'bg-indigo-600 text-white ring-2 ring-indigo-200' :
                   'bg-slate-100 text-slate-400' ) }>
{ i + 1 < step ? <CheckCircle size={ 14 } /> : i + 1 }
</div>
{ i < total - 1 && (
<div className={ 'flex-1 h-0.5 ' + ( i + 1 < step ? 'bg-indigo-500' : 'bg-slate-200' ) } />
) }
</React.Fragment>
) ) }
</div>
);
}

/* ── Step 1: Name + Type ─────────────────────────────────────────── */

function StepBasicInfo( { form, setForm } ) {
return (
<div className="space-y-4">
<div>
<Label className="mb-1 block">Tên chiến dịch <span className="text-red-500">*</span></Label>
<Input
value={ form.name }
onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) }
placeholder="VD: Khuyến mãi tháng 7"
/>
</div>
<div>
<Label className="mb-2 block">Loại kênh gửi</Label>
<div className="grid grid-cols-2 gap-3">
{ [ { value: 'zns', label: 'ZNS (Zalo)', desc: 'Gửi qua Zalo Notification Service' },
    { value: 'email', label: 'Gmail / SMTP', desc: 'Gửi qua tài khoản email' } ].map( ( t ) => (
<button
key={ t.value }
type="button"
onClick={ () => setForm( { ...form, type: t.value } ) }
className={ 'p-4 rounded-xl border-2 text-left transition-colors ' +
( form.type === t.value
? 'border-indigo-500 bg-indigo-50'
: 'border-slate-200 hover:border-slate-300' ) }
>
<div className="font-semibold text-sm">{ t.label }</div>
<div className="text-xs text-slate-500 mt-0.5">{ t.desc }</div>
</button>
) ) }
</div>
</div>
</div>
);
}

/* ── Step 2: Channel config ──────────────────────────────────────── */

function TempVarRow( { row, idx, onChange, onRemove } ) {
return (
<tr className="border-b last:border-0">
<td className="py-1 pr-2 w-28">
<Input
value={ row.var_name }
onChange={ ( e ) => onChange( idx, { var_name: e.target.value } ) }
placeholder="varName"
className="text-xs"
/>
</td>
<td className="py-1 pr-2 w-28">
<select
value={ row.source }
onChange={ ( e ) => onChange( idx, { source: e.target.value } ) }
className="bzc-input text-xs w-full"
>
<option value="recipient">Recipient</option>
<option value="literal">Giá trị cố định</option>
</select>
</td>
<td className="py-1 pr-2">
{ row.source === 'recipient' ? (
<select
value={ row.field }
onChange={ ( e ) => onChange( idx, { field: e.target.value } ) }
className="bzc-input text-xs w-full"
>
{ [ 'name', 'phone', 'email' ].map( ( f ) => <option key={ f } value={ f }>{ f }</option> ) }
</select>
) : (
<Input
value={ row.value }
onChange={ ( e ) => onChange( idx, { value: e.target.value } ) }
placeholder="Giá trị"
className="text-xs"
/>
) }
</td>
<td className="py-1 w-8 text-right">
<button type="button" onClick={ () => onRemove( idx ) } className="text-slate-400 hover:text-red-500">
<Trash2 size={ 13 } />
</button>
</td>
</tr>
);
}

function StepChannelConfig( { form, setForm } ) {
// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — bridge picker [{k,v}] ↔ form [{var_name,value}]
// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — bridge: preserve source + field from picker
	const pickerVars = ( form.temp_vars || [] ).map( ( r ) => ( {
		k:      r.var_name || r.k || '',
		v:      r.value    || r.v || '',
		source: r.source || 'literal',
	} ) );
	// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — use functional setForm(prev=>) so
	// batched React 18 state updates don't overwrite each other with stale snapshots.
	const setPickerVars = ( vars ) => setForm( ( prev ) => ( { ...prev, temp_vars: vars.map( ( { k, v, source } ) => ( {
		var_name: k,
		source:   source || 'literal',
		value:    v,
	} ) ) } ) );

if ( form.type === 'zns' ) {
return (
<div className="space-y-4">
  {/* [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — ZnsTemplatePicker replaces manual fields */}
  <ZnsTemplatePicker
    tempId={ form.zns_temp_id }
    oaId={ form.oa_id }
    tempVars={ pickerVars }
    onChangeTempId={ ( v ) => setForm( ( prev ) => ( { ...prev, zns_temp_id: v } ) ) }
    onChangeOaId={ ( v ) => setForm( ( prev ) => ( { ...prev, oa_id: v } ) ) }
    onChangeTempVars={ setPickerVars }
  />
<div className="flex items-center gap-2 text-xs text-slate-500">
<input
type="checkbox"
id="sandbox"
checked={ form.sandbox }
onChange={ ( e ) => setForm( { ...form, sandbox: e.target.checked } ) }
className="rounded"
/>
<label htmlFor="sandbox">Chế độ Sandbox (test, không tính phí)</label>
</div>
</div>
);
}

/* Email */
return (
<div className="space-y-3">
<div>
<Label className="mb-1 block">Tiêu đề email (Subject) <span className="text-red-500">*</span></Label>
<Input
value={ form.email_subject }
onChange={ ( e ) => setForm( { ...form, email_subject: e.target.value } ) }
placeholder="VD: Khuyến mãi đặc biệt dành cho bạn"
/>
</div>
<div>
<Label className="mb-1 block">Nội dung email <span className="text-red-500">*</span></Label>
<p className="text-xs text-slate-400 mb-1">Dùng <code className="bg-slate-100 px-1 rounded">{'{name}'}</code> <code className="bg-slate-100 px-1 rounded">{'{phone}'}</code> <code className="bg-slate-100 px-1 rounded">{'{email}'}</code> để cá nhân hoá.</p>
<Textarea
value={ form.email_body }
onChange={ ( e ) => setForm( { ...form, email_body: e.target.value } ) }
rows={ 8 }
placeholder="Nội dung HTML hoặc text thuần…"
/>
</div>
<div className="grid grid-cols-2 gap-3">
<div>
<Label className="mb-1 block">Tên người gửi</Label>
<Input
value={ form.from_name }
onChange={ ( e ) => setForm( { ...form, from_name: e.target.value } ) }
placeholder="VD: BizCity Support"
/>
</div>
<div>
<Label className="mb-1 block">Email người gửi</Label>
<Input
type="email"
value={ form.from_email }
onChange={ ( e ) => setForm( { ...form, from_email: e.target.value } ) }
placeholder="no-reply@example.com"
/>
</div>
</div>
</div>
);
}

/* ── Step 3: Recipients ──────────────────────────────────────────── */

// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 6 — channel source filter options
const CONTACT_SOURCES = [
{ value: '', label: 'Tất cả kênh' },
{ value: 'facebook', label: 'Facebook' },
{ value: 'messenger', label: 'Messenger' },
{ value: 'zalo_oa', label: 'Zalo OA' },
{ value: 'zalo_personal', label: 'Zalo Cá nhân' },
{ value: 'webchat', label: 'WebChat' },
{ value: 'telegram', label: 'Telegram' },
{ value: 'cf7', label: 'Contact Form 7' },
{ value: 'email', label: 'Email' },
{ value: 'inbox', label: 'Nhập tay' },
];

function StepRecipients( { form, setForm } ) {
const [ tab, setTab ]           = useState( 'upload' );
const [ parseErr, setParseErr ] = useState( '' );
const [ contactQ, setContactQ ] = useState( '' );
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 6 — channel source filter
const [ contactSource, setContactSource ] = useState( '' );
const fileRef                   = useRef( null );

const [ parseFile, { isLoading: isParsing } ] = useParseFileMutation();
const { data: contactsData, isFetching: loadingContacts } = useGetContactsQuery(
{ q: contactQ, source: contactSource, limit: 100 },
{ skip: tab !== 'contacts' }
);
const contacts = contactsData || [];

const handleFile = useCallback( async ( e ) => {
const file = e.target.files && e.target.files[ 0 ];
if ( ! file ) { return; }
setParseErr( '' );
const fd = new FormData();
fd.append( 'file', file );
try {
const res = await parseFile( fd ).unwrap();
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 2 — PHP wrap() returns { ok, data: { rows, count } }
const rows = res && res.ok && res.data && Array.isArray( res.data.rows ) ? res.data.rows : null;
if ( rows ) {
setForm( ( prev ) => ( { ...prev, recipients: rows } ) );
} else {
const msg = ( res && res.ok === false && res.error && res.error.message ) || ( res && res.data && res.data.message ) || 'Không đọc được file.';
setParseErr( msg );
}
} catch ( err ) {
setParseErr( 'Lỗi upload: ' + ( ( err && err.data && err.data.message ) || 'không rõ.' ) );
}
}, [ parseFile, setForm ] );

const toggleContact = ( c ) => {
const exists = form.recipients.some( ( r ) => r.phone === c.phone || r.email === c.email );
if ( exists ) {
setForm( { ...form, recipients: form.recipients.filter( ( r ) => r.phone !== c.phone && r.email !== c.email ) } );
} else {
setForm( { ...form, recipients: [ ...form.recipients, { name: c.name || c.display_name || '', phone: c.phone || '', email: c.email || '' } ] } );
}
};

const allSelected = contacts.length > 0 && contacts.every( ( c ) => form.recipients.some( ( r ) => r.phone === c.phone || r.email === c.email ) );
const toggleAll = () => {
if ( allSelected ) {
const phones = new Set( contacts.map( ( c ) => c.phone ) );
const emails = new Set( contacts.map( ( c ) => c.email ) );
setForm( { ...form, recipients: form.recipients.filter( ( r ) => ! phones.has( r.phone ) && ! emails.has( r.email ) ) } );
} else {
const toAdd = contacts
.filter( ( c ) => ! form.recipients.some( ( r ) => r.phone === c.phone || r.email === c.email ) )
.map( ( c ) => ( { name: c.name || c.display_name || '', phone: c.phone || '', email: c.email || '' } ) );
setForm( { ...form, recipients: [ ...form.recipients, ...toAdd ] } );
}
};

return (
<div className="space-y-3">
{/* Tab bar */}
<div className="flex gap-1 border-b pb-0 mb-3">
{ [ { id: 'upload', label: 'Upload file' }, { id: 'contacts', label: 'Chọn từ Contacts' } ].map( ( t ) => (
<button
key={ t.id }
type="button"
onClick={ () => setTab( t.id ) }
className={ 'px-4 py-2 text-sm border-b-2 -mb-px transition-colors ' +
( tab === t.id ? 'border-indigo-500 text-indigo-600 font-medium' : 'border-transparent text-slate-500 hover:text-slate-700' ) }
>
{ t.label }
</button>
) ) }
{ form.recipients.length > 0 && (
<span className="ml-auto self-center text-xs text-emerald-600 font-medium">
✓ { form.recipients.length } người nhận
</span>
) }
</div>

{ tab === 'upload' && (
<div className="space-y-3">
{/* Download hint */}
<div className="flex items-start gap-2 p-3 bg-blue-50 border border-blue-200 rounded-lg">
<Info size={ 14 } className="text-blue-600 mt-0.5 shrink-0" />
<div className="text-xs text-blue-800 space-y-1">
<p className="font-semibold">Hỗ trợ: CSV · XLSX (tối đa 5.000 dòng)</p>
<p>Phân tách: <code className="bg-blue-100 px-1 rounded">;</code> hoặc <code className="bg-blue-100 px-1 rounded">,</code> — tự nhận dạng</p>
<p>Cột cần có: <code className="bg-blue-100 px-1 rounded">Tên</code> · <code className="bg-blue-100 px-1 rounded">Số điện thoại</code> · <code className="bg-blue-100 px-1 rounded">Email</code></p>
<p className="text-blue-600">Alias: <code className="bg-blue-100 px-1 rounded">name / ten / ho_ten</code> · <code className="bg-blue-100 px-1 rounded">phone / sdt / dien_thoai</code></p>
<a
href={ templateUrl() }
download="broadcast-template.csv"
className="inline-flex items-center gap-1 px-2.5 py-1 bg-blue-600 text-white rounded text-xs font-medium hover:bg-blue-700 transition-colors"
>
<Download size={ 11 } /> Tải file mẫu CSV
</a>
</div>
</div>

{/* Drop zone */}
<div
className="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-indigo-400 transition-colors"
onClick={ () => fileRef.current && fileRef.current.click() }
onDragOver={ ( e ) => e.preventDefault() }
onDrop={ ( e ) => {
e.preventDefault();
const f = e.dataTransfer.files && e.dataTransfer.files[ 0 ];
if ( f ) {
const dt = new DataTransfer();
dt.items.add( f );
fileRef.current.files = dt.files;
handleFile( { target: fileRef.current } );
}
} }
>
<Upload size={ 28 } className="mx-auto text-slate-300 mb-2" />
{ isParsing
? <p className="text-sm text-slate-500">Đang xử lý file…</p>
: (
<>
<p className="text-sm text-slate-500 font-medium">Kéo thả hoặc click để chọn</p>
<p className="text-xs text-slate-400 mt-0.5">CSV, XLSX, XLS</p>
</>
)
}
</div>
<input ref={ fileRef } type="file" accept=".csv,.xlsx,.xls" className="hidden" onChange={ handleFile } />

{ parseErr && <p className="text-xs text-red-500">{ parseErr }</p> }

{ form.recipients.length > 0 && (
<div className="text-xs text-slate-600 bg-emerald-50 border border-emerald-200 rounded p-2">
✓ Đã đọc <strong>{ form.recipients.length }</strong> người nhận.
Ví dụ: { form.recipients.slice( 0, 2 ).map( ( r ) => r.name || r.phone || r.email ).join( ', ' ) }
{ form.recipients.length > 2 && ' …' }
</div>
) }
</div>
) }

{ tab === 'contacts' && (
<div className="space-y-2">
{ /* [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 6 — channel source filter pills */ }
<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4, marginBottom: 6 } }>
{ CONTACT_SOURCES.map( ( s ) => (
<button
key={ s.value }
type="button"
onClick={ () => setContactSource( s.value ) }
style={ {
fontSize: 11, padding: '3px 9px', borderRadius: 12, border: '1px solid',
cursor: 'pointer', transition: 'all 0.15s',
background: contactSource === s.value ? '#4f46e5' : '#f8fafc',
borderColor: contactSource === s.value ? '#4f46e5' : '#e2e8ef',
color: contactSource === s.value ? '#fff' : '#475569',
} }
>{ s.label }</button>
) ) }
</div>
<Input
value={ contactQ }
onChange={ ( e ) => setContactQ( e.target.value ) }
placeholder="Tìm theo tên, SĐT, email…"
/>
<div className="border rounded-lg overflow-y-auto max-h-56">
{ loadingContacts && <p className="text-xs text-slate-400 p-3">Đang tải…</p> }
{ ! loadingContacts && contacts.length === 0 && (
<p className="text-xs text-slate-400 p-3">Không tìm thấy liên hệ.{ contactQ ? ' Thử từ khoá khác.' : ' Nhập từ khoá để tìm kiếm.' }</p>
) }
{ contacts.length > 0 && (
<>
<div className="px-3 py-2 border-b flex items-center gap-2 bg-slate-50">
<input type="checkbox" checked={ allSelected } onChange={ toggleAll } className="rounded" />
<span className="text-xs text-slate-500">Chọn tất cả ({ contacts.length })</span>
</div>
{ contacts.map( ( c, i ) => {
const selected = form.recipients.some( ( r ) => r.phone === c.phone || r.email === c.email );
return (
<label key={ i } className="flex items-center gap-2 px-3 py-2 hover:bg-slate-50 cursor-pointer border-b last:border-0">
<input type="checkbox" checked={ selected } onChange={ () => toggleContact( c ) } className="rounded" />
<div className="flex-1 text-xs">
<div className="font-medium">{ c.name || c.display_name || '—' }</div>
<div className="text-slate-400">{ [ c.phone, c.email ].filter( Boolean ).join( ' · ' ) }</div>
</div>
</label>
);
} ) }
</>
) }
</div>
</div>
) }
</div>
);
}

/* ── Step 4: Review + Settings ───────────────────────────────────── */

function StepReview( { form, setForm } ) {
return (
<div className="space-y-4">
<div className="bg-slate-50 rounded-lg p-4 text-sm space-y-2">
<div className="flex justify-between">
<span className="text-slate-500">Tên chiến dịch</span>
<span className="font-medium">{ form.name }</span>
</div>
<div className="flex justify-between">
<span className="text-slate-500">Kênh gửi</span>
<span className="font-medium">{ form.type === 'zns' ? 'ZNS (Zalo)' : 'Gmail / SMTP' }</span>
</div>
<div className="flex justify-between">
<span className="text-slate-500">Số người nhận</span>
<span className="font-semibold text-indigo-600">{ form.recipients.length }</span>
</div>
{ form.type === 'zns' && form.zns_temp_id && (
<div className="flex justify-between">
<span className="text-slate-500">Template ID</span>
<span>{ form.zns_temp_id }</span>
</div>
) }
{ form.type === 'email' && form.email_subject && (
<div className="flex justify-between">
<span className="text-slate-500">Tiêu đề</span>
<span className="truncate max-w-[200px]">{ form.email_subject }</span>
</div>
) }
</div>

<div className="grid grid-cols-2 gap-3">
<div>
<Label className="mb-1 block">Số gửi mỗi batch</Label>
<select
value={ form.batch_size }
onChange={ ( e ) => setForm( { ...form, batch_size: Number( e.target.value ) } ) }
className="bzc-input w-full"
>
{ [ 5, 10, 20, 50 ].map( ( v ) => <option key={ v } value={ v }>{ v } tin / batch</option> ) }
</select>
</div>
<div>
<Label className="mb-1 block">Delay giữa các batch</Label>
<select
value={ form.delay_sec }
onChange={ ( e ) => setForm( { ...form, delay_sec: Number( e.target.value ) } ) }
className="bzc-input w-full"
>
{ [ [ 0, 'Không delay' ], [ 5, '5 giây' ], [ 15, '15 giây' ], [ 30, '30 giây' ], [ 60, '1 phút' ], [ 120, '2 phút' ], [ 180, '3 phút' ] ].map( ( [ v, l ] ) => (
<option key={ v } value={ v }>{ l }</option>
) ) }
</select>
</div>
</div>

<div className="flex items-center gap-2 text-sm">
<input
type="checkbox"
id="auto_start"
checked={ form.auto_start }
onChange={ ( e ) => setForm( { ...form, auto_start: e.target.checked } ) }
className="rounded"
/>
<label htmlFor="auto_start" className="cursor-pointer">
Bắt đầu gửi ngay sau khi tạo
</label>
</div>
</div>
);
}

/* ── Main dialog ─────────────────────────────────────────────────── */

const STEPS = [ 'Thông tin', 'Cấu hình kênh', 'Người nhận', 'Xem lại' ];
const TOTAL = STEPS.length;

function initForm( initialRecipients ) {
return {
name:          '',
type:          'zns',
zns_temp_id:   '',
oa_id:         '',
temp_vars:     [],
sandbox:       false,
email_subject: '',
email_body:    '',
from_name:     '',
from_email:    '',
smtp_uid:      '',
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7b — pre-fill from ContactsTab
recipients:    initialRecipients || [],
batch_size:    10,
delay_sec:     5,
auto_start:    false,
};
}

export default function BroadcastCreateDialog( { onClose, initialRecipients } ) {
const [ step, setStep ] = useState( initialRecipients && initialRecipients.length > 0 ? 3 : 1 );
const [ form, setForm ] = useState( () => initForm( initialRecipients ) );
const [ error, setError ] = useState( '' );

// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — use createBroadcastZns that accepts meta+recipients.
const [ createBroadcast, { isLoading } ] = useCreateBroadcastZnsMutation();

const canNext = () => {
if ( step === 1 ) { return form.name.trim().length > 0; }
if ( step === 2 ) {
if ( form.type === 'zns' )   { return form.zns_temp_id.trim().length > 0; }
if ( form.type === 'email' ) { return form.email_subject.trim().length > 0 && form.email_body.trim().length > 0; }
}
if ( step === 3 ) { return form.recipients.length > 0; }
return true;
};

// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 4 — Enter key handler to advance steps.
const handleKeyDown = useCallback( ( e ) => {
if ( e.key !== 'Enter' ) { return; }
const tag = e.target && e.target.tagName;
// Don't intercept Enter inside textarea, select, or button
if ( tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' ) { return; }
e.preventDefault();
if ( step < TOTAL && canNext() ) { setStep( step + 1 ); }
}, [ step, canNext ] );

const handleSubmit = async () => {
setError( '' );

// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — build temp_data object from temp_vars.
// source=recipient rows use {name}/{phone}/{email} tokens → BE substitutes per-recipient.
// source=literal rows use the raw value directly.
const buildTempData = ( vars ) => {
	const out = {};
	( vars || [] ).forEach( ( r ) => {
		const key = ( r.var_name || r.k || '' ).trim();
		if ( ! key ) { return; }
		out[ key ] = r.source === 'recipient' ? ( r.value || r.v || '{name}' ) : ( r.value || r.v || '' );
	} );
	return out;
};

const meta = form.type === 'zns'
? { zns_temp_id: form.zns_temp_id, oa_id: form.oa_id, temp_data: buildTempData( form.temp_vars ), sandbox: form.sandbox }
: { email_subject: form.email_subject, email_body: form.email_body, from_name: form.from_name, from_email: form.from_email, smtp_uid: form.smtp_uid };

// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — use create-zns endpoint for ZNS/Email broadcasts.
const body = {
name:       form.name,
type:       form.type,
batch_size: form.batch_size,
delay_sec:  form.delay_sec,
auto_start: form.auto_start ? 1 : 0,
meta:       meta,
recipients: form.recipients,
};

try {
const res = await createBroadcast( body ).unwrap();
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — wrap() returns { ok, data }
if ( res && res.ok ) {
onClose();
} else {
const msg = ( res && res.error && res.error.message ) || ( res && res.data && res.data.message ) || 'Tạo chiến dịch thất bại.';
setError( msg );
}
} catch ( err ) {
setError( ( err && err.data && err.data.message ) || 'Lỗi kết nối.' );
}
};

return (
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 4 — onKeyDown Enter handler on outer div.
<Dialog open onOpenChange={ ( o ) => ! o && onClose() }>
<DialogContent className="max-w-xl w-full">
<DialogHeader>
<DialogTitle className="flex items-center gap-2">
Tạo chiến dịch broadcast
<span className="ml-auto text-xs font-normal text-slate-400">
Bước { step } / { TOTAL }: { STEPS[ step - 1 ] }
</span>
</DialogTitle>
</DialogHeader>

<div className="px-6 py-4" onKeyDown={ handleKeyDown }>
<StepIndicator step={ step } total={ TOTAL } />

{ step === 1 && <StepBasicInfo form={ form } setForm={ setForm } /> }
{ step === 2 && <StepChannelConfig form={ form } setForm={ setForm } /> }
{ step === 3 && <StepRecipients form={ form } setForm={ setForm } /> }
{ step === 4 && <StepReview form={ form } setForm={ setForm } /> }

{ error && <p className="text-xs text-red-500 mt-3">{ error }</p> }
</div>

<DialogFooter className="flex items-center justify-between px-6 py-4 border-t">
<Button variant="ghost" onClick={ step > 1 ? () => setStep( step - 1 ) : onClose }>
{ step > 1 ? '← Quay lại' : 'Huỷ' }
</Button>
<div className="flex items-center gap-2">
{ step < TOTAL && (
<Button disabled={ ! canNext() } onClick={ () => setStep( step + 1 ) }>
Tiếp theo →
</Button>
) }
{ step === TOTAL && (
<Button
variant="primary"
disabled={ isLoading || form.recipients.length === 0 }
onClick={ handleSubmit }
>
{ isLoading ? 'Đang tạo…' : ( form.auto_start ? 'Tạo & Gửi ngay' : 'Tạo chiến dịch' ) }
</Button>
) }
</div>
</DialogFooter>
</DialogContent>
</Dialog>
);
}