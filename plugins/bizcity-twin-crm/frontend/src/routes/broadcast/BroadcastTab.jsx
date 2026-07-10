/**
 * BroadcastTab — ZNS + Email Mass-Send
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Rebuilt for CRM SPA, replaces Zalo-personal version.
 * [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Added Console tab (cron monitor + per-campaign progress)
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — ZnsTemplatePicker in TestPanel + ConfigSheet
 * API: bizcity-crm/v1/broadcasts/*
 */
import React, { useState, useEffect } from 'react';
import { Send, Plus, RefreshCw, Trash2, Pause, Play, XCircle, Mail, MessageSquare, Download, Users, Terminal, CheckCircle, AlertTriangle, Clock, Zap, SlidersHorizontal, RotateCcw, FlaskConical, Bug } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '../../components/ui/sheet.jsx';
import { Input } from '../../components/ui/input.jsx';
import {
useListBroadcastsQuery,
useDeleteBroadcastMutation,
useStartBroadcastMutation,
usePauseBroadcastMutation,
useCancelBroadcastMutation,
useGetBroadcastProgressQuery,
useGetCronConsoleQuery,
useGetBroadcastRecipientsQuery,
useGetBroadcastActivityQuery,
useUpdateBroadcastMutation,
useRestartBroadcastMutation,
} from '../../redux/api/cgBroadcastApi.js';
import BroadcastCreateDialog from './BroadcastCreateDialog.jsx';
import ZnsTemplatePicker from '../../components/ZnsTemplatePicker.jsx';

/* helpers */
const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};

// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Bug fix — template is in bizcity-crm/v1, not bizcity-channel/v1
function templateUrl() {
const base = ( BOOT.restUrl || '/wp-json/bizcity-crm/v1/' ).replace( /\/$/, '' );
return base + '/broadcasts/template';
}

const STATUS_STYLE = {
draft:     'bg-slate-100 text-slate-700',
sending:   'bg-yellow-100 text-yellow-800',
paused:    'bg-blue-100 text-blue-700',
done:      'bg-emerald-100 text-emerald-700',
cancelled: 'bg-slate-100 text-slate-400',
};
const STATUS_LABEL = {
draft: 'Draft', sending: 'Đang gửi', paused: 'Tạm dừng', done: 'Xong', cancelled: 'Đã huỷ',
};

function StatusBadge( { status } ) {
return (
<span className={ 'text-xs px-2 py-0.5 rounded-full font-medium ' + ( STATUS_STYLE[ status ] || STATUS_STYLE.draft ) }>
{ STATUS_LABEL[ status ] || status }
</span>
);
}

function ProgressBar( { sent, total } ) {
const pct = total > 0 ? Math.min( 100, Math.round( ( sent / total ) * 100 ) ) : 0;
return (
<div className="flex items-center gap-2">
<div className="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
<div className="h-full bg-emerald-500 rounded-full transition-all" style={ { width: pct + '%' } } />
</div>
<span className="text-xs text-slate-500 tabular-nums w-8 text-right">{ pct }%</span>
</div>
);
}

function BroadcastRow( { bc, busy, onViewRecips, onConfig, onStart, onPause, onCancel, onRestart, onDelete } ) {
const canEdit  = bc.status === 'draft' || bc.status === 'paused';
const isSending = bc.status === 'sending';
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Bug fix — poll 3s for realtime progress; also poll for paused/cancelled to show final counts
const { data: prog } = useGetBroadcastProgressQuery( bc.id, {
pollingInterval: isSending ? 3000 : 0,
skip: ! isSending && bc.status !== 'paused',
} );

const total  = ( prog && prog.total  !== undefined ) ? prog.total  : ( bc.total_count  || 0 );
const sent   = ( prog && prog.sent   !== undefined ) ? prog.sent   : ( bc.sent_count   || 0 );
const failed = ( prog && prog.failed !== undefined ) ? prog.failed : ( bc.failed_count || 0 );
const queued = ( prog && prog.queued !== undefined ) ? prog.queued : 0;
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — canRestart: done/cancelled OR sending with all recipients processed (queue exhausted)
const canRestart = bc.status === 'done' || bc.status === 'cancelled'
    || ( isSending && total > 0 && queued === 0 );

// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — full action set
const canCancel = bc.status === 'sending' || bc.status === 'paused';
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — expandable test-send panel
const [ testOpen,    setTestOpen    ] = useState( false );
const [ testPhone,   setTestPhone   ] = useState( '' );
const [ testLoading, setTestLoading ] = useState( false );
const [ testResult,  setTestResult  ] = useState( null ); // null | { ok, msg }
// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — bug logs panel
const [ bugOpen,     setBugOpen     ] = useState( false );

// Parse ZNS meta from message_template
let _mt = {};
try { if ( bc.message_template ) { _mt = JSON.parse( bc.message_template ) || {}; } } catch ( _e ) { /* noop */ }
const _inner = ( _mt.meta && typeof _mt.meta === 'object' ) ? _mt.meta : _mt;
const [ testTempId, setTestTempId ] = useState( String( _inner.zns_temp_id || _inner.template_id || '' ) );
const [ testOaId,   setTestOaId   ] = useState( String( _inner.oa_id || '' ) );
// TempData rows: [{k:'',v:''}]
const initTempVars = (() => {
  const tv = _inner.temp_vars;
  if ( Array.isArray( tv ) && tv.length ) { return tv.map( ( t ) => ( { k: t.var_name || t.k || '', v: '' } ) ); }
  return [ { k: '', v: '' } ];
})();
const [ testVars, setTestVars ] = useState( initTempVars );

const handleTestSend = async () => {
  setTestLoading( true ); setTestResult( null );
  const base = ( BOOT.restUrl || '/wp-json/bizcity-crm/v1/' ).replace( /\/$/, '' );
  const body = {
    phone:     testPhone.trim(),
    temp_id:   testTempId.trim(),
    oa_id:     testOaId.trim(),
    temp_data: Object.fromEntries( testVars.filter( ( r ) => r.k.trim() ).map( ( r ) => [ r.k.trim(), r.v ] ) ),
  };
  try {
    const res = await fetch( base + '/zns-send-logs/test', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BOOT.restNonce || '' },
      body: JSON.stringify( body ),
    } );
    const json = await res.json();
    if ( json && json.ok && json.data && json.data.sent ) {
      setTestResult( { ok: true, msg: 'Gửi thành công! SMSID: ' + ( json.data.sms_id || '—' ) } );
    } else {
      const errMsg = ( json && json.data && json.data.error ) || ( json && json.error ) || 'Gửi thất bại.';
      setTestResult( { ok: false, msg: errMsg } );
    }
  } catch ( e ) {
    setTestResult( { ok: false, msg: e.message || 'Network error' } );
  } finally {
    setTestLoading( false );
  }
};

return (
<React.Fragment>
<tr className="border-b last:border-0 hover:bg-slate-50 transition-colors">
<td className="px-4 py-2.5">
<div className="font-medium text-sm">{ bc.name }</div>
<div className="text-xs text-slate-400 flex items-center gap-1 mt-0.5">
{ bc.type === 'email'
? <><Mail size={ 11 } className="inline-block" /> Gmail / SMTP</>
: <><MessageSquare size={ 11 } className="inline-block" /> ZNS</>
}
</div>
</td>
<td className="px-2 py-2.5"><StatusBadge status={ bc.status } /></td>
<td className="px-2 py-2.5 w-44">
{ total > 0 ? (
<>
<ProgressBar sent={ sent } total={ total } />
<div className="text-xs text-slate-400 mt-0.5 text-right tabular-nums">
{ sent } gửi{ failed > 0 ? <span className="text-red-400 ml-1">&nbsp;{ failed } lỗi</span> : null } / { total }
</div>
</>
) : <span className="text-xs text-slate-400">—</span> }
</td>
<td className="px-2 py-2.5 text-xs text-slate-400">
{ bc.created_at ? new Date( bc.created_at ).toLocaleString( 'vi-VN' ) : '—' }
</td>
<td className="px-4 py-2.5">
<div className="flex items-center justify-end gap-1">
{ /* ── Xem danh sách — always */ }
<Button size="xs" variant="ghost" title="Xem danh sách" onClick={ () => onViewRecips( bc ) }>
<Users size={ 13 } />
</Button>
{ /* ── Cấu hình — draft/paused */ }
<Button size="xs" variant="ghost" title="Cấu hình chiến dịch" disabled={ ! canEdit } onClick={ canEdit ? () => onConfig( bc ) : undefined }
className={ canEdit ? '' : 'opacity-30 cursor-not-allowed' }>
<SlidersHorizontal size={ 12 } />
</Button>
{ /* ── Bắt đầu / Tiếp tục — draft/paused */ }
<Button size="xs" variant={ canEdit ? 'outline' : 'ghost' } title="Bắt đầu / Tiếp tục" disabled={ ! canEdit || busy === bc.id }
onClick={ canEdit ? () => onStart( bc.id ) : undefined }
className={ canEdit ? '' : 'opacity-30 cursor-not-allowed' }>
<Play size={ 12 } />
</Button>
{ /* ── Tạm dừng — sending only */ }
<Button size="xs" variant={ bc.status === 'sending' ? 'outline' : 'ghost' } title="Tạm dừng"
disabled={ bc.status !== 'sending' || busy === bc.id }
onClick={ bc.status === 'sending' ? () => onPause( bc.id ) : undefined }
className={ bc.status === 'sending' ? '' : 'opacity-30 cursor-not-allowed' }>
<Pause size={ 12 } />
</Button>
{ /* ── Huỷ — sending/paused */ }
<Button size="xs" variant="ghost" title="Huỷ" disabled={ ! canCancel || busy === bc.id }
onClick={ canCancel ? () => onCancel( bc.id ) : undefined }
className={ 'text-orange-400 hover:text-orange-600' + ( canCancel ? '' : ' opacity-30 cursor-not-allowed' ) }>
<XCircle size={ 12 } />
</Button>
{ /* ── Gửi lại — done/cancelled/queue-exhausted */ }
<Button size="xs" variant="ghost" title="Gửi lại vòng mới (reset tất cả về chờ)"
disabled={ ! canRestart || busy === bc.id }
onClick={ canRestart ? () => onRestart( bc.id ) : undefined }
className={ 'text-indigo-500 hover:text-indigo-700' + ( canRestart ? '' : ' opacity-30 cursor-not-allowed' ) }>
<RotateCcw size={ 12 } />
</Button>
{ /* ── Bug Logs — always, shows failed recipients */ }
<Button size="xs" variant="ghost" title="Xem lỗi gửi (Bug Logs)"
onClick={ () => setBugOpen( ( o ) => ! o ) }
className={ 'text-red-400 hover:text-red-600' + ( bugOpen ? ' bg-red-50' : '' ) }>
<Bug size={ 12 } />
</Button>
{ /* ── Test ZNS — ZNS only */ }
{ bc.type !== 'email' && (
<Button size="xs" variant="ghost" title="Gửi thử ZNS (Sandbox)"
className={ 'text-violet-500 hover:text-violet-700' + ( testOpen ? ' bg-violet-50' : '' ) }
onClick={ () => { setTestOpen( ( o ) => ! o ); setTestResult( null ); } }>
<FlaskConical size={ 12 } />
</Button>
) }
</div>
</td>
</tr>
{ testOpen && bc.type !== 'email' && (
<tr className="border-b bg-violet-50/60">
<td colSpan={ 5 } className="px-4 py-3">
<div className="max-w-2xl space-y-3">
{/* Header */}
<div className="flex items-center justify-between">
<div className="text-xs font-semibold text-violet-700 flex items-center gap-1.5">
<FlaskConical size={ 13 } /> Gửi thử ZNS — Sandbox mode (eSMS không tính phí thật)
</div>
<button type="button" onClick={ () => setTestOpen( false ) } className="text-slate-400 hover:text-slate-600 text-xs">✕ Đóng</button>
</div>
{/* Row 1: phone */}
<div className="flex-1 min-w-[140px]">
<label className="block text-xs font-medium text-slate-600 mb-1">Số điện thoại <span className="text-red-500">*</span></label>
<Input
className="h-7 text-xs"
placeholder="0901234567"
value={ testPhone }
onChange={ ( e ) => setTestPhone( e.target.value ) }
/>
</div>
{/* [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — replace manual tempId/oaId/vars with ZnsTemplatePicker */}
<ZnsTemplatePicker
  tempId={ testTempId }
  oaId={ testOaId }
  tempVars={ testVars }
  onChangeTempId={ setTestTempId }
  onChangeOaId={ setTestOaId }
  onChangeTempVars={ setTestVars }
/>
{/* Result */}
{ testResult && (
<div className={ 'rounded-lg px-3 py-2 text-xs ' + ( testResult.ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-600 border border-red-200' ) }>
{ testResult.ok ? '✓ ' : '✗ ' }{ testResult.msg }
</div>
) }
{/* Send button */}
<button
type="button"
disabled={ testLoading || ! testPhone.trim() || ! testTempId.trim() }
onClick={ handleTestSend }
className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-md bg-violet-600 text-white hover:bg-violet-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
>
<FlaskConical size={ 12 } />
{ testLoading ? 'Đang gửi…' : '📤 Gửi thử ZNS (Sandbox)' }
</button>
</div>
</td>
</tr>
) }
{ /* [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — Bug Logs panel */ }
{ bugOpen && <BugLogsPanel bc={ bc } /> }
</React.Fragment>
);
}

// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — inline bug log panel (failed recipients with error detail)
function BugLogsPanel( { bc } ) {
const { data, isFetching } = useGetBroadcastRecipientsQuery(
{ id: bc.id, status: 'failed', limit: 50, offset: 0 }, { skip: false }
);
const items = ( data && data.items ) || [];
const total = ( data && data.counts && data.counts.failed ) || 0;
return (
<tr className="border-b bg-red-950/5">
<td colSpan={ 5 } className="px-4 py-3">
<div className="space-y-2">
<div className="flex items-center gap-2 text-xs font-semibold text-red-700">
<Bug size={ 12 } />
Bug Logs — Recipients lỗi{ total > 0 ? ` (${ total })` : '' }
{ isFetching && <span className="text-slate-400 animate-pulse ml-2">Đang tải…</span> }
</div>
{ items.length === 0 && ! isFetching && (
<p className="text-xs text-slate-400 italic">Không có lỗi nào.</p>
) }
{ items.length > 0 && (
<div className="overflow-x-auto rounded border border-red-100">
<table className="w-full text-xs font-mono">
<thead className="bg-red-50 border-b border-red-100">
<tr className="text-left text-slate-500">
<th className="px-2 py-1.5 font-medium">SĐT</th>
<th className="px-2 py-1.5 font-medium w-20">eSMS code</th>
<th className="px-2 py-1.5 font-medium w-24">TempID</th>
<th className="px-2 py-1.5 font-medium w-36">OA ID</th>
<th className="px-2 py-1.5 font-medium">Lỗi</th>
<th className="px-2 py-1.5 font-medium">Payload gửi</th>
</tr>
</thead>
<tbody>
{ items.map( ( r ) => {
let parsed = null;
try { parsed = r.error ? JSON.parse( r.error ) : null; } catch ( _ ) { parsed = null; }
const esmsCode  = parsed ? ( parsed.esms_code  || '—' ) : '—';
const esmsError = parsed ? ( parsed.esms_error || parsed.reason || r.error || '—' ) : ( r.error || '—' );
const tempId    = parsed ? ( parsed.temp_id  || '—' ) : '—';
const oaId      = parsed ? ( parsed.oa_id    || '—' ) : '—';
const tempData  = parsed && parsed.temp_data ? parsed.temp_data : null;
return (
<tr key={ r.id } className="border-b border-red-50 last:border-0 hover:bg-red-50/40">
<td className="px-2 py-1.5 text-slate-600">{ r.contact_phone || r.contact_email || '—' }</td>
<td className="px-2 py-1.5 text-amber-700 font-bold">{ esmsCode }</td>
<td className="px-2 py-1.5 text-indigo-600">{ tempId }</td>
<td className="px-2 py-1.5 text-slate-500">{ oaId }</td>
<td className="px-2 py-1.5 text-red-700 break-all max-w-[180px]">{ esmsError }</td>
<td className="px-2 py-1.5 text-slate-500">
{ tempData
? Object.entries( tempData ).map( ( [ k, v ] ) => (
<span key={ k } className="mr-2"><span className="text-indigo-500">{ k }</span>=<span className="text-emerald-700">{ String( v ) }</span></span>
) )
: '—'
}
</td>
</tr>
);
} ) }
</tbody>
</table>
</div>
) }
</div>
</td>
</tr>
);
}

/* ── ConfigSheet — edit draft/paused campaign settings ───────────────── */

function ConfigSheet( { bc, onClose } ) {
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — inline config editor for draft/paused broadcasts
const [ updateBroadcast, { isLoading } ] = useUpdateBroadcastMutation();

// Parse meta from message_template JSON
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — message_template structure is {broadcast_type, meta:{zns_temp_id, oa_id, ...}}
// ZNS fields are nested under .meta, not at top level.
let initMeta = {};
try { if ( bc.message_template ) { initMeta = JSON.parse( bc.message_template ) || {}; } } catch ( _e ) { /* noop */ }
const innerMeta = ( initMeta.meta && typeof initMeta.meta === 'object' ) ? initMeta.meta : initMeta;

const [ name,     setName     ] = useState( bc.name || bc.title || '' );
const [ tempId,   setTempId   ] = useState( String( innerMeta.zns_temp_id || innerMeta.template_id || '' ) );
const [ oaId,     setOaId     ] = useState( String( innerMeta.oa_id || '' ) );
// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — also store temp_vars for picker
const initCfgVars = ( () => {
  const tv = innerMeta.temp_vars;
  if ( Array.isArray( tv ) && tv.length ) { return tv.map( ( t ) => ( { k: t.var_name || t.k || '', v: t.value || t.v || '' } ) ); }
  return [];
} )();
const [ cfgVars, setCfgVars ] = useState( initCfgVars );
const [ delaySec, setDelaySec ] = useState( String( bc.delay_sec !== undefined ? bc.delay_sec : 5 ) );
const [ err,      setErr      ] = useState( '' );
const [ saved,    setSaved    ] = useState( false );

const DELAY_OPTIONS = [
[ '0', '0 giây (tối đa tốc độ)' ],
[ '3', '3 giây' ],
[ '5', '5 giây' ],
[ '10', '10 giây' ],
[ '30', '30 giây' ],
[ '60', '1 phút' ],
[ '120', '2 phút' ],
[ '300', '5 phút' ],
];

const handleSave = async () => {
setErr( '' );
if ( ! name.trim() ) { setErr( 'Tên chiến dịch không được để trống.' ); return; }
try {
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — meta patch: preserve top-level {broadcast_type} and nested {meta:{...}}
// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — also save temp_vars from picker
const updatedInnerMeta = { ...innerMeta };
if ( tempId ) { updatedInnerMeta.zns_temp_id = tempId; updatedInnerMeta.template_id = tempId; }
if ( oaId )   { updatedInnerMeta.oa_id       = oaId; }
if ( cfgVars && cfgVars.length ) {
  updatedInnerMeta.temp_vars = cfgVars.map( ( { k, v } ) => ( { var_name: k, source: 'literal', value: v } ) );
}
const metaPatch = { ...initMeta, meta: updatedInnerMeta };

await updateBroadcast( {
id:        bc.id,
title:     name.trim(),
meta:      metaPatch,
delay_sec: parseInt( delaySec, 10 ) || 0,
} ).unwrap();
setSaved( true );
setTimeout( onClose, 800 );
} catch ( e ) {
setErr( ( e && e.data && e.data.error && e.data.error.message ) || 'Lưu thất bại.' );
}
};

return (
<Sheet open onOpenChange={ ( o ) => ! o && onClose() }>
{/* [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — onPointerDownOutside: prevent Radix from
    treating the button click that opened the sheet as an "outside click" (which would
    close the sheet immediately on the same event tick). */}
<SheetContent
side="right"
className="w-[440px] max-w-full"
onPointerDownOutside={ ( e ) => e.preventDefault() }
onInteractOutside={ ( e ) => e.preventDefault() }
>
<SheetHeader>
<SheetTitle className="flex items-center gap-2">
<SlidersHorizontal size={ 16 } />
Cấu hình chiến dịch
<span className="text-xs font-normal text-slate-400 ml-1">ID #{ bc.id }</span>
</SheetTitle>
</SheetHeader>
<div className="p-4 space-y-4">
{ saved && <div className="text-sm text-emerald-600 bg-emerald-50 rounded-lg px-3 py-2">✓ Đã lưu cấu hình.</div> }
{ err   && <div className="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{ err }</div> }

<div>
<label className="mb-1 block text-xs font-medium text-slate-700">Tên chiến dịch <span className="text-red-500">*</span></label>
<Input value={ name } onChange={ ( e ) => setName( e.target.value ) } placeholder="Tên chiến dịch" />
</div>

{ ( bc.type === 'zns' || initMeta.broadcast_type === 'zns' || ! initMeta.broadcast_type ) && ( // eslint-disable-line no-mixed-operators
<>
{/* [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — ZnsTemplatePicker replaces manual tempId/oaId inputs */}
<ZnsTemplatePicker
  tempId={ tempId }
  oaId={ oaId }
  tempVars={ cfgVars }
  onChangeTempId={ setTempId }
  onChangeOaId={ setOaId }
  onChangeTempVars={ setCfgVars }
/>
</>
) }

<div>
<label className="mb-1 block text-xs font-medium text-slate-700">Delay giữa các batch</label>
<select
value={ delaySec }
onChange={ ( e ) => setDelaySec( e.target.value ) }
className="bzc-input w-full"
>
{ DELAY_OPTIONS.map( ( [ v, l ] ) => <option key={ v } value={ v }>{ l }</option> ) }
</select>
<p className="text-xs text-slate-400 mt-1">Khoảng cách nghỉ sau mỗi batch gửi để tránh spam.</p>
</div>

<div className="pt-2 flex justify-end gap-2">
<Button variant="ghost" onClick={ onClose }>Hủy</Button>
<Button onClick={ handleSave } disabled={ isLoading }>
{ isLoading ? 'Đang lưu…' : 'Lưu cấu hình' }
</Button>
</div>
</div>
</SheetContent>
</Sheet>
);
}

// [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — parse rich error JSON from dispatcher
/** Extract human-readable short error from raw error string (may be JSON). */
function getShortError( raw ) {
if ( ! raw ) { return ''; }
try { const p = JSON.parse( raw ); return p.esms_error || p.reason || raw; } catch ( _ ) { return raw; }
}

function ZnsErrorCell( { raw } ) {
if ( ! raw ) { return <span className="text-slate-300">—</span>; }
let parsed = null;
try { parsed = JSON.parse( raw ); } catch ( _ ) { parsed = null; }
if ( ! parsed || typeof parsed !== 'object' ) {
// Plain string error (legacy or non-ZNS)
return <span className="text-red-600 break-all leading-tight text-xs">{ raw }</span>;
}
const [ open, setOpen ] = React.useState( false );
const short = parsed.esms_error || parsed.reason || 'error';
return (
<div className="text-xs">
<button
type="button"
onClick={ () => setOpen( ( o ) => ! o ) }
className="text-red-600 hover:text-red-800 flex items-center gap-1 leading-tight text-left"
title="Nhấn để xem chi tiết lỗi"
>
<AlertTriangle size={ 11 } className="shrink-0" />
<span className="break-all">{ short }</span>
<span className="text-slate-400 ml-1 shrink-0">{ open ? '▲' : '▼' }</span>
</button>
{ open && (
<div className="mt-1.5 rounded border border-red-200 bg-red-50 p-2 space-y-1 font-mono text-[11px]">
{ parsed.temp_id  && <div><span className="text-slate-500 mr-1">TempID:</span><span className="text-slate-700">{ parsed.temp_id }</span></div> }
{ parsed.oa_id    && <div><span className="text-slate-500 mr-1">OA ID:</span><span className="text-slate-700">{ parsed.oa_id }</span></div> }
{ parsed.esms_code && <div><span className="text-slate-500 mr-1">eSMS code:</span><span className="text-amber-700">{ parsed.esms_code }</span></div> }
{ parsed.esms_error && <div><span className="text-slate-500 mr-1">eSMS error:</span><span className="text-red-700 break-all">{ parsed.esms_error }</span></div> }
{ parsed.temp_data && Object.keys( parsed.temp_data ).length > 0 && (
<div>
<div className="text-slate-500 mb-0.5">Payload (temp_data):</div>
{ Object.entries( parsed.temp_data ).map( ( [ k, v ] ) => (
<div key={ k } className="pl-2"><span className="text-indigo-600">{ k }</span><span className="text-slate-400">: </span><span className="text-emerald-700">{ String( v ) }</span></div>
) ) }
</div>
) }
</div>
) }
</div>
);
}

function RecipientsSheet( { bc, onClose } ) {
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Real recipients list with tabs + error log
const [ statusTab, setStatusTab ] = useState( '' ); // '' = all
const [ page, setPage ]           = useState( 0 );
const LIMIT = 50;

const { data, isFetching } = useGetBroadcastRecipientsQuery(
	{ id: bc.id, status: statusTab, limit: LIMIT, offset: page * LIMIT },
	{ pollingInterval: bc.status === 'sending' ? 5000 : 0 }
);

const items  = ( data && data.items  ) || [];
const total  = ( data && data.total  ) || 0;
const counts = ( data && data.counts ) || {};
const totalPages = Math.ceil( total / LIMIT );

const TABS = [
	{ key: '',       label: 'Tất cả',  count: ( counts.sent || 0 ) + ( counts.failed || 0 ) + ( counts.queued || 0 ) + ( counts.skipped || 0 ), color: 'text-slate-600' },
	{ key: 'sent',   label: '✓ Thành công', count: counts.sent   || 0, color: 'text-emerald-600' },
	{ key: 'failed', label: '✗ Lỗi',    count: counts.failed || 0, color: 'text-red-500' },
	{ key: 'queued', label: '⏳ Chờ',   count: counts.queued || 0, color: 'text-yellow-600' },
];

const STATUS_STYLE = {
	sent:    'bg-emerald-100 text-emerald-700',
	failed:  'bg-red-100 text-red-700',
	queued:  'bg-yellow-100 text-yellow-700',
	skipped: 'bg-slate-100 text-slate-500',
};
const STATUS_LABEL = { sent: 'Đã gửi', failed: 'Lỗi', queued: 'Chờ', skipped: 'Bỏ qua' };

return (
<Sheet open onOpenChange={ ( o ) => ! o && onClose() }>
<SheetContent side="right" className="w-[620px] max-w-full flex flex-col">
<SheetHeader>
<SheetTitle className="flex items-center gap-2">
<Users size={ 16 } />
{ bc.name || bc.title } — Danh sách người nhận
</SheetTitle>
</SheetHeader>
<div className="flex-1 flex flex-col overflow-hidden">
{/* Tab bar */}
<div className="flex items-center gap-0 border-b px-4 shrink-0">
{ TABS.map( ( t ) => (
<button
key={ t.key }
type="button"
onClick={ () => { setStatusTab( t.key ); setPage( 0 ); } }
className={ 'flex items-center gap-1.5 px-3 py-2 text-xs font-medium border-b-2 transition-colors whitespace-nowrap ' +
( statusTab === t.key
? 'border-indigo-500 text-indigo-600'
: 'border-transparent text-slate-500 hover:text-slate-700' ) }
>
<span className={ t.color }>{ t.label }</span>
{ t.count > 0 && <span className="bg-slate-100 text-slate-600 rounded-full px-1.5 text-[10px] font-semibold">{ t.count }</span> }
</button>
) ) }
{ isFetching && <span className="ml-auto text-xs text-slate-400 animate-pulse pr-4">Đang tải…</span> }
</div>

{/* Table */}
<div className="flex-1 overflow-y-auto">
{ items.length === 0 && ! isFetching && (
<div className="py-12 text-center text-slate-400 text-sm">Không có dữ liệu.</div>
) }
{ items.length > 0 && (
<table className="w-full text-xs">
<thead className="sticky top-0 bg-slate-50 border-b z-10">
<tr className="text-left text-slate-500">
<th className="px-3 py-2 font-medium w-36">Tên</th>
<th className="px-3 py-2 font-medium w-28 font-mono">SĐT</th>
<th className="px-2 py-2 font-medium w-20">Trạng thái</th>
<th className="px-2 py-2 font-medium w-28">Thời gian gửi</th>
<th className="px-2 py-2 font-medium">Lỗi / Ghi chú</th>
</tr>
</thead>
<tbody>
{ items.map( ( r ) => (
<tr key={ r.id } className={ 'border-b last:border-0 hover:bg-slate-50 ' + ( r.status === 'failed' ? 'bg-red-50/40' : '' ) }>
<td className="px-3 py-2">
{ r.contact_name
  ? <span className="font-medium text-slate-800">{ r.contact_name }</span>
  : <span className="text-slate-300 italic text-xs">(chưa có tên)</span>
}
</td>
<td className="px-3 py-2 font-mono text-slate-600 tabular-nums">
{ r.contact_phone || r.contact_email || <span className="text-slate-300">—</span> }
</td>
<td className="px-2 py-2">
<span className={ 'text-xs px-1.5 py-0.5 rounded-full ' + ( STATUS_STYLE[ r.status ] || 'bg-slate-100 text-slate-500' ) }>
{ STATUS_LABEL[ r.status ] || r.status }
</span>
</td>
<td className="px-2 py-2 text-slate-400 tabular-nums">
{ r.sent_at ? new Date( r.sent_at ).toLocaleString( 'vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' } ) : '—' }
</td>
<td className="px-2 py-2">
{ r.error ? <ZnsErrorCell raw={ r.error } /> : <span className="text-slate-300">—</span> }
</td>
</tr>
) ) }
</tbody>
</table>
) }
</div>

{/* Pagination */}
{ totalPages > 1 && (
<div className="flex items-center justify-between px-4 py-2 border-t shrink-0 text-xs text-slate-500">
<span>Trang { page + 1 } / { totalPages } · Tổng { total } người nhận</span>
<div className="flex items-center gap-1">
<Button size="xs" variant="outline" disabled={ page === 0 } onClick={ () => setPage( page - 1 ) }>‹ Trước</Button>
<Button size="xs" variant="outline" disabled={ page >= totalPages - 1 } onClick={ () => setPage( page + 1 ) }>Tiếp ›</Button>
</div>
</div>
) }
{ totalPages <= 1 && total > 0 && (
<div className="px-4 py-2 border-t text-xs text-slate-400 shrink-0">Tổng { total } người nhận</div>
) }
</div>
</SheetContent>
</Sheet>
);
}

/* ── Console Tab ─────────────────────────────────────────────────── */

function CronCountdown( { nextTick, serverTs } ) {
const [ secs, setSecs ] = useState( null );
useEffect( () => {
if ( ! nextTick || ! serverTs ) { setSecs( null ); return; }
const update = () => {
const now = Math.floor( Date.now() / 1000 );
const diff = nextTick - now;
setSecs( diff > 0 ? diff : 0 );
};
update();
const t = setInterval( update, 1000 );
return () => clearInterval( t );
}, [ nextTick, serverTs ] );
if ( secs === null ) { return <span className="text-slate-400">—</span>; }
return <span className={ 'font-mono font-semibold ' + ( secs <= 10 ? 'text-emerald-600' : 'text-slate-700' ) }>{ secs }s</span>;
}

function ConsoleCampaignRow( { bc } ) {
const p      = bc.progress || {};
const total  = p.total   || 0;
const sent   = p.sent    || 0;
const failed = p.failed  || 0;
const queued = p.queued  || 0;
const pct    = p.percent || 0;
const isSending = bc.status === 'sending';
const color  = isSending ? 'bg-emerald-500' : bc.status === 'paused' ? 'bg-yellow-400' : bc.status === 'done' ? 'bg-emerald-400' : 'bg-slate-300';
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — auto-expand activity log for active campaigns
const [ expanded, setExpanded ] = useState( isSending || bc.status === 'done' );

// Live activity log — last 30 sent/failed rows, polls 3s when sending
const { data: actItems = [], isFetching: actFetching } = useGetBroadcastActivityQuery(
{ id: bc.id, limit: 30 },
{ pollingInterval: isSending ? 3000 : 0, skip: ! expanded }
);

const STATUS_BC = {
draft: 'bg-slate-100 text-slate-600',
sending: 'bg-yellow-100 text-yellow-800 animate-pulse',
paused: 'bg-blue-100 text-blue-700',
done: 'bg-emerald-100 text-emerald-700',
cancelled: 'bg-slate-100 text-slate-400',
};
const LABEL_BC = { draft: 'Draft', sending: 'Đang gửi', paused: 'Tạm dừng', done: 'Xong', cancelled: 'Đã huỷ' };

return (
<div className="border rounded-xl bg-white shadow-sm overflow-hidden">
{/* Campaign header + progress */}
<div className="p-3">
<div className="flex items-start justify-between mb-2">
<div>
<div className="font-medium text-sm flex items-center gap-1.5">
{ bc.type === 'email' ? <Mail size={ 12 } className="text-blue-400" /> : <MessageSquare size={ 12 } className="text-green-500" /> }
{ bc.name || bc.title || '—' }
</div>
<div className="text-xs text-slate-400 mt-0.5">ID #{ bc.id } · { bc.type === 'email' ? 'Gmail/SMTP' : 'ZNS' }</div>
</div>
<span className={ 'text-xs px-2 py-0.5 rounded-full font-medium ' + ( STATUS_BC[ bc.status ] || 'bg-slate-100 text-slate-500' ) }>
{ LABEL_BC[ bc.status ] || bc.status }
</span>
</div>
{ total > 0 && (
<>
<div className="h-2 bg-slate-100 rounded-full overflow-hidden mb-1.5">
<div className={ 'h-full rounded-full transition-all duration-500 ' + color } style={ { width: pct + '%' } } />
</div>
<div className="flex items-center gap-3 text-xs text-slate-500 tabular-nums">
<span className="flex items-center gap-1"><CheckCircle size={ 10 } className="text-emerald-500" /> { sent } gửi OK</span>
<span className="flex items-center gap-1"><AlertTriangle size={ 10 } className="text-red-400" /> { failed } lỗi</span>
<span className="flex items-center gap-1"><Clock size={ 10 } className="text-slate-400" /> { queued } chờ</span>
<span className="ml-auto font-semibold text-slate-700">{ pct }%</span>
</div>
{ p.last_sent_at && (
<div className="text-xs text-slate-400 mt-1">Lần gửi cuối: { new Date( p.last_sent_at ).toLocaleString( 'vi-VN' ) }</div>
) }
</>
) }
{ total === 0 && <div className="text-xs text-slate-400">Chưa có recipient nào được enqueue.</div> }
</div>

{/* Activity log toggle + panel */}
<div className="border-t border-slate-100">
<button
type="button"
onClick={ () => setExpanded( ( e ) => ! e ) }
className="flex items-center w-full gap-1.5 px-3 py-1.5 text-xs text-slate-500 hover:bg-slate-50 hover:text-slate-700 transition-colors"
>
<Terminal size={ 11 } />
<span>Activity Log</span>
{ isSending && <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block" /> }
{ actFetching && expanded && <span className="text-slate-400 animate-pulse ml-1">sync…</span> }
<span className="ml-auto text-slate-300">{ expanded ? '▲' : '▼' }</span>
</button>

{ expanded && (
<div className="max-h-52 overflow-y-auto border-t border-slate-100 bg-slate-950 text-xs font-mono">
{ actItems.length === 0 && ! actFetching && (
<div className="px-4 py-3 text-slate-500">Đạng chờ lệnh… Chưa có hoạt động nào được ghi nhận.</div>
) }
{ actItems.length === 0 && actFetching && (
<div className="px-4 py-3 text-slate-500 animate-pulse">Đang tải…</div>
) }
{ actItems.map( ( row ) => {
const ts = row.sent_at
? new Date( row.sent_at ).toLocaleTimeString( 'vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false } )
: '--:--:--';
const ok = row.status === 'sent';
const name  = row.contact_name  || '';
const phone = row.contact_phone || row.contact_email || '';
return (
<div
key={ row.id }
className={ 'flex items-baseline gap-2 px-3 py-1 border-b border-slate-800 last:border-0 ' + ( ok ? '' : 'bg-red-950/30' ) }
>
<span className="text-slate-600 shrink-0 w-16 tabular-nums">{ ts }</span>
<span className={ 'shrink-0 font-bold ' + ( ok ? 'text-emerald-400' : 'text-red-400' ) }>
{ ok ? '  OK' : ' ERR' }
</span>
{ name  && <span className="text-slate-300 truncate max-w-[130px]">{ name }</span> }
{ phone && <span className="text-slate-500 shrink-0 tabular-nums">{ phone }</span> }
{ ! ok && row.error && (
<span className="text-red-400 break-all flex-1 leading-tight" title={ row.error }>
{ ( ( s ) => s.length > 80 ? s.slice( 0, 80 ) + '…' : s )( getShortError( row.error ) ) }
</span>
) }
{ ok && <span className="text-slate-700 ml-auto text-[10px]">sent</span> }
</div>
);
} ) }
</div>
) }
</div>
</div>
);
}

function ConsoleTab() {
const { data: console_, isFetching } = useGetCronConsoleQuery( undefined, {
pollingInterval: 5000,
} );
const items     = ( console_ && console_.items )     || [];
const nextTick  = console_ ? console_.next_tick  : null;
const serverTs  = console_ ? console_.server_ts  : null;
const isPaused  = console_ ? console_.is_paused  : false;
const batchSize = console_ ? console_.batch_size : 30;

const active  = items.filter( ( bc ) => bc.status === 'sending' );
const waiting = items.filter( ( bc ) => bc.status === 'draft' || bc.status === 'paused' );
const done    = items.filter( ( bc ) => bc.status === 'done' || bc.status === 'cancelled' );

return (
<div className="bzc-pane-body overflow-y-auto p-4 space-y-4">
{/* Cron status header */}
<div className={ 'rounded-xl p-3 border text-sm flex flex-wrap items-center gap-4 ' + ( isPaused ? 'bg-orange-50 border-orange-200' : 'bg-emerald-50 border-emerald-200' ) }>
<div className="flex items-center gap-1.5 font-medium">
<Zap size={ 14 } className={ isPaused ? 'text-orange-400' : 'text-emerald-500' } />
{ isPaused ? 'Cron PAUSED' : 'Cron đang chạy' }
</div>
<div className="text-slate-500 text-xs">Hook: <code className="bg-white px-1 rounded">bizcity_crm_broadcast_tick</code></div>
<div className="text-xs text-slate-500">Batch: { batchSize } / phút</div>
<div className="text-xs flex items-center gap-1">
<Clock size={ 11 } className="text-slate-400" />
Tick tiếp theo: <CronCountdown nextTick={ nextTick } serverTs={ serverTs } />
</div>
{ isFetching && <div className="ml-auto text-xs text-slate-400 animate-pulse">Đang cập nhật…</div> }
</div>

{ active.length > 0 && (
<div>
<div className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2 flex items-center gap-1">
<span className="w-2 h-2 rounded-full bg-yellow-400 animate-pulse inline-block" />
Đang gửi ({ active.length })
</div>
<div className="space-y-2">{ active.map( ( bc ) => <ConsoleCampaignRow key={ bc.id } bc={ bc } /> ) }</div>
</div>
) }

{ waiting.length > 0 && (
<div>
<div className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Chờ / Tạm dừng ({ waiting.length })</div>
<div className="space-y-2">{ waiting.map( ( bc ) => <ConsoleCampaignRow key={ bc.id } bc={ bc } /> ) }</div>
</div>
) }

{ done.length > 0 && (
<div>
<div className="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Hoàn thành / Đã huỷ ({ done.length })</div>
<div className="space-y-2">{ done.map( ( bc ) => <ConsoleCampaignRow key={ bc.id } bc={ bc } /> ) }</div>
</div>
) }

{ items.length === 0 && ! isFetching && (
<div className="py-16 text-center text-slate-400">
<Terminal size={ 36 } className="mx-auto mb-3 text-slate-200" />
<p className="text-sm">Chưa có chiến dịch nào trong hệ thống.</p>
</div>
) }
</div>
);
}

/* ── Main BroadcastTab ───────────────────────────────────────────── */
export default function BroadcastTab() {
const [ showCreate, setShowCreate ] = useState( false );
const [ busyId, setBusyId ]         = useState( null );
const [ viewBC, setViewBC ]         = useState( null );
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — config sheet state
const [ configBC, setConfigBC ]     = useState( null );
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST — Tab switcher: 'list' | 'console'
const [ activeTab, setActiveTab ]   = useState( 'list' );

const { data, isFetching, refetch } = useListBroadcastsQuery( {} );
const items = ( data && data.items ) ? data.items : [];

const [ startBroadcast ]   = useStartBroadcastMutation();
const [ pauseBroadcast ]   = usePauseBroadcastMutation();
const [ cancelBroadcast ]  = useCancelBroadcastMutation();
const [ deleteBroadcast ]  = useDeleteBroadcastMutation();
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — restart: re-queue all recipients for a new round
const [ restartBroadcast ] = useRestartBroadcastMutation();

const withBusy = ( id, fn ) => {
setBusyId( id );
fn().catch( () => {} ).finally( () => setBusyId( null ) );
};

return (
<div className="bzc-pane bzc-broadcast-pane">
{/* Header */}
<div className="bzc-pane-header border-b">
<div className="flex items-center justify-between px-4 py-3">
<div>
<h2 className="font-semibold text-base flex items-center gap-2">
<Send size={ 16 } />
Broadcast — ZNS &amp; Email
</h2>
<p className="text-xs text-slate-400 mt-0.5">
Gửi ZNS hoặc Email hàng loạt từ danh sách hoặc file CSV.
</p>
</div>
<div className="flex items-center gap-2">
{ activeTab === 'list' && (
<>
<a
href={ templateUrl() }
download="broadcast-template.csv"
className="inline-flex items-center gap-1 px-2.5 py-1.5 border border-slate-200 rounded-md text-xs text-slate-500 hover:text-slate-800 hover:border-slate-400 transition-colors"
title="Tải file mẫu CSV"
>
<Download size={ 12 } />
File mẫu CSV
</a>
<Button size="sm" variant="ghost" onClick={ refetch } disabled={ isFetching } title="Làm mới">
<RefreshCw size={ 14 } className={ isFetching ? 'animate-spin' : '' } />
</Button>
<Button size="sm" onClick={ () => setShowCreate( true ) }>
<Plus size={ 14 } className="mr-1" />
Tạo chiến dịch
</Button>
</>
) }
</div>
</div>
{/* Tab switcher */}
<div className="flex items-center gap-0 px-4 border-t border-slate-100">
{ [ { id: 'list', label: 'Danh sách', icon: Send },
    { id: 'console', label: 'Console', icon: Terminal } ].map( ( t ) => {
const Icon = t.icon;
return (
<button
key={ t.id }
type="button"
onClick={ () => setActiveTab( t.id ) }
className={ 'flex items-center gap-1.5 px-3 py-2 text-xs font-medium border-b-2 transition-colors ' +
( activeTab === t.id
? 'border-indigo-500 text-indigo-600'
: 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ) }
>
<Icon size={ 12 } /> { t.label }
</button>
);
} ) }
</div>
</div>

{/* Body */}
{ activeTab === 'console' ? <ConsoleTab /> : (
<div className="bzc-pane-body overflow-y-auto">
{ isFetching && <p className="text-sm text-slate-400 px-4 py-4">Đang tải…</p> }

{ ! isFetching && items.length === 0 && (
<div className="px-4 py-16 text-center">
<Send size={ 36 } className="mx-auto text-slate-200 mb-3" />
<p className="text-sm text-slate-400">Chưa có chiến dịch nào.</p>
<p className="text-xs text-slate-400 mt-1">Nhấn "+ Tạo chiến dịch" để bắt đầu.</p>
</div>
) }

{ items.length > 0 && (
<table className="w-full text-sm">
<thead>
<tr className="text-left text-xs text-slate-500 border-b bg-slate-50">
<th className="px-4 py-2 font-medium">Tên chiến dịch</th>
<th className="px-2 py-2 font-medium">Trạng thái</th>
<th className="px-2 py-2 font-medium w-44">Tiến độ</th>
<th className="px-2 py-2 font-medium">Tạo lúc</th>
<th className="px-4 py-2 font-medium text-right">Hành động</th>
</tr>
</thead>
<tbody>
{ items.map( ( bc ) => (
<BroadcastRow
key={ bc.id }
bc={ bc }
busy={ busyId }
onViewRecips={ setViewBC }
onConfig={ setConfigBC }
onStart={ ( id ) => withBusy( id, () => startBroadcast( id ).unwrap() ) }
onPause={ ( id ) => withBusy( id, () => pauseBroadcast( id ).unwrap() ) }
onCancel={ ( id ) => {
if ( ! window.confirm( 'Huỷ chiến dịch? Những tin chưa gửi sẽ bỏ qua.' ) ) { return; }
withBusy( id, () => cancelBroadcast( id ).unwrap() );
} }
onRestart={ ( id ) => {
if ( ! window.confirm( 'Reset toàn bộ người nhận về "chờ" và gửi lại từ đầu?' ) ) { return; }
withBusy( id, () => restartBroadcast( id ).unwrap() );
} }
onDelete={ ( id ) => {
if ( ! window.confirm( 'Xoá chiến dịch này?' ) ) { return; }
withBusy( id, () => deleteBroadcast( id ).unwrap() );
} }
/>
) ) }
</tbody>
</table>
) }
</div>
) }

{ showCreate && <BroadcastCreateDialog onClose={ () => { setShowCreate( false ); } } /> }
{ viewBC   && <RecipientsSheet bc={ viewBC }   onClose={ () => setViewBC( null ) } /> }
{ configBC && <ConfigSheet     bc={ configBC } onClose={ () => setConfigBC( null ) } /> }
</div>
);
}