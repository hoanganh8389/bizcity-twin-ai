/**
 * Automation Rules — visual builder.
 *
 * Backend contract (M2):
 *   GET    /automation-rules                  -> { rules:[…], count }
 *   POST   /automation-rules                  body: { name, event_name, conditions:{operator,rules:[…]}, actions:[{type,params}], active, inbox_id?, description? }
 *   PUT    /automation-rules/:id              same body
 *   DELETE /automation-rules/:id
 *   POST   /automation-rules/:id/dry-run      body: { event_payload:{…} }
 *   GET    /automation-actions                -> { actions:[…] }
 *
 * BE validates:
 *   - event_name BẮT BUỘC + phải nằm trong SUBSCRIBED_EVENTS (12).
 *   - actions BẮT BUỘC non-empty array, action.type phải thuộc registry.
 */
import React, { useMemo, useState } from 'react';
import { Plus, Trash2, Play, Pencil, Power, Copy, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label as FieldLabel } from '../../components/ui/input.jsx';
import {
	useGetAutomationRulesQuery,
	useGetAutomationActionsQuery,
	useCreateAutomationRuleMutation,
	useUpdateAutomationRuleMutation,
	useDeleteAutomationRuleMutation,
	useDryRunAutomationRuleMutation,
} from '../../redux/api/crmApi.js';

/* ──────────────────────────────────────────────────────────────────────────
 * Catalog (mirror BE — keep in sync with class-automation-engine.php / evaluator)
 * ────────────────────────────────────────────────────────────────────────── */

const EVENT_OPTIONS = [
	{ value: 'crm_message_received',         label: 'Tin nhắn vào (inbound)' },
	{ value: 'crm_message_sent',             label: 'Tin nhắn ra (outbound)' },
	{ value: 'crm_conversation_opened',      label: 'Mở hội thoại mới' },
	{ value: 'crm_conversation_resolved',    label: 'Đóng hội thoại' },
	{ value: 'crm_conversation_snoozed',     label: 'Snooze hội thoại' },
	{ value: 'crm_conversation_unsnoozed',   label: 'Bỏ snooze' },
	{ value: 'crm_label_assigned',           label: 'Gán label' },
	{ value: 'crm_label_removed',            label: 'Gỡ label' },
	{ value: 'crm_assignee_changed',         label: 'Đổi người phụ trách' },
	{ value: 'crm_sla_breached',             label: 'SLA quá hạn' },
	{ value: 'crm_sla_met',                  label: 'SLA đạt' },
	{ value: 'crm_campaign_visit_recorded',  label: 'Khách click chiến dịch' },
];

const FIELD_OPTIONS = [
	{ value: 'status',            label: 'conversation.status',        suggest: [ 'open', 'pending', 'resolved', 'snoozed' ] },
	{ value: 'priority',          label: 'conversation.priority (0-3)' },
	{ value: 'inbox_id',          label: 'conversation.inbox_id' },
	{ value: 'assignee_id',       label: 'conversation.assignee_id' },
	{ value: 'labels',            label: 'conversation.labels' },
	{ value: 'content',           label: 'message.content (text)' },
	{ value: 'message_type',      label: 'message.message_type',       suggest: [ 'incoming', 'outgoing', 'note', 'system' ] },
	{ value: 'sender_type',       label: 'message.sender_type',        suggest: [ 'contact', 'agent', 'bot', 'system' ] },
	{ value: 'content_type',      label: 'message.content_type',       suggest: [ 'text', 'image', 'video', 'file' ] },
	{ value: 'contact_email',     label: 'contact.email' },
	{ value: 'contact_phone',     label: 'contact.phone' },
	{ value: 'contact_name',      label: 'contact.name' },
	{ value: 'is_business_hours', label: 'context.is_business_hours (bool)' },
];

const OPERATOR_OPTIONS = [
	{ value: 'equals',        label: 'bằng (=)' },
	{ value: 'not_equals',    label: 'khác (≠)' },
	{ value: 'contains',      label: 'chứa' },
	{ value: 'not_contains',  label: 'không chứa' },
	{ value: 'in',            label: 'thuộc danh sách (in)' },
	{ value: 'not_in',        label: 'không thuộc danh sách' },
	{ value: 'gt',            label: 'lớn hơn (>)' },
	{ value: 'gte',           label: '≥' },
	{ value: 'lt',            label: 'nhỏ hơn (<)' },
	{ value: 'lte',           label: '≤' },
	{ value: 'regex',         label: 'regex' },
	{ value: 'is_empty',      label: 'rỗng' },
	{ value: 'is_not_empty',  label: 'không rỗng' },
];

const VALUELESS_OPS = new Set( [ 'is_empty', 'is_not_empty' ] );
const ARRAY_OPS     = new Set( [ 'in', 'not_in' ] );

/* ──────────────────────────────────────────────────────────────────────────
 * Helpers
 * ────────────────────────────────────────────────────────────────────────── */

function eventLabel( name ) {
	return EVENT_OPTIONS.find( ( e ) => e.value === name )?.label || name;
}

function emptyRule() {
	return {
		name:        '',
		description: '',
		event_name:  'crm_message_received',
		inbox_id:    null,
		active:      false,
		conditions:  { operator: 'all', rules: [] },
		actions:     [],
	};
}

function parseValueByOperator( raw, op ) {
	if ( VALUELESS_OPS.has( op ) ) { return null; }
	if ( ARRAY_OPS.has( op ) ) {
		return ( raw || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
	}
	const trimmed = ( raw || '' ).trim();
	if ( /^-?\d+(\.\d+)?$/.test( trimmed ) ) { return Number( trimmed ); }
	if ( trimmed === 'true' )  { return true; }
	if ( trimmed === 'false' ) { return false; }
	return trimmed;
}

function valueToString( v ) {
	if ( v === null || v === undefined ) { return ''; }
	if ( Array.isArray( v ) ) { return v.join( ', ' ); }
	return String( v );
}

/* ──────────────────────────────────────────────────────────────────────────
 * Condition Builder
 * ────────────────────────────────────────────────────────────────────────── */

function ConditionBuilder( { value, onChange } ) {
	const cond = value || { operator: 'all', rules: [] };

	const setOp = ( operator ) => onChange( { ...cond, operator } );

	const setRules = ( rules ) => onChange( { ...cond, rules } );

	const addRule = () => setRules( [ ...cond.rules, { field: 'content', op: 'contains', value: '' } ] );

	const updateRule = ( i, patch ) => {
		const next = cond.rules.slice();
		next[ i ] = { ...next[ i ], ...patch };
		// reset value when switching to valueless op
		if ( patch.op && VALUELESS_OPS.has( patch.op ) ) { next[ i ].value = null; }
		setRules( next );
	};

	const removeRule = ( i ) => setRules( cond.rules.filter( ( _, j ) => j !== i ) );

	return (
		<div className="rounded-lg border border-gray-200 bg-gray-50 p-3 flex flex-col gap-3">
			<div className="flex items-center justify-between">
				<div className="text-xs text-gray-600">
					Điều kiện —{ ' ' }
					<select
						className="bzc-input !inline-block !w-auto !py-0.5 !text-xs"
						value={ cond.operator || 'all' }
						onChange={ ( e ) => setOp( e.target.value ) }
					>
						<option value="all">tất cả phải đúng (AND)</option>
						<option value="any">bất kỳ điều kiện nào đúng (OR)</option>
					</select>
				</div>
				<Button type="button" onClick={ addRule }><Plus size={ 12 } /> Thêm điều kiện</Button>
			</div>

			{ cond.rules.length === 0 && (
				<div className="text-xs italic text-gray-400">— Không có điều kiện = rule luôn match khi event xảy ra —</div>
			) }

			{ cond.rules.map( ( r, i ) => {
				const fieldDef = FIELD_OPTIONS.find( ( f ) => f.value === r.field );
				return (
					<div key={ i } className="flex flex-wrap items-center gap-2 bg-white rounded border border-gray-200 p-2">
						<select
							className="bzc-input !w-auto !py-1 !text-xs flex-1 min-w-[180px]"
							value={ r.field || '' }
							onChange={ ( e ) => updateRule( i, { field: e.target.value } ) }
						>
							{ FIELD_OPTIONS.map( ( f ) => (
								<option key={ f.value } value={ f.value }>{ f.label }</option>
							) ) }
						</select>
						<select
							className="bzc-input !w-auto !py-1 !text-xs"
							value={ r.op || 'equals' }
							onChange={ ( e ) => updateRule( i, { op: e.target.value } ) }
						>
							{ OPERATOR_OPTIONS.map( ( o ) => (
								<option key={ o.value } value={ o.value }>{ o.label }</option>
							) ) }
						</select>
						{ ! VALUELESS_OPS.has( r.op ) && (
							<Input
								className="!py-1 !text-xs flex-1 min-w-[140px]"
								placeholder={ ARRAY_OPS.has( r.op ) ? 'giá trị, ngăn cách bằng dấu phẩy' : 'giá trị' }
								list={ fieldDef?.suggest ? `field-suggest-${ r.field }-${ i }` : undefined }
								value={ valueToString( r.value ) }
								onChange={ ( e ) => updateRule( i, { value: parseValueByOperator( e.target.value, r.op ) } ) }
							/>
						) }
						{ fieldDef?.suggest && (
							<datalist id={ `field-suggest-${ r.field }-${ i }` }>
								{ fieldDef.suggest.map( ( s ) => <option key={ s } value={ s } /> ) }
							</datalist>
						) }
						<button
							type="button"
							onClick={ () => removeRule( i ) }
							className="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-600"
							title="Xoá điều kiện"
						>
							<Trash2 size={ 14 } />
						</button>
					</div>
				);
			} ) }
		</div>
	);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Action Builder
 * ────────────────────────────────────────────────────────────────────────── */

function ActionItem( { action, catalog, onChange, onRemove } ) {
	const def = catalog.find( ( a ) => a.type === action.type );
	const params = action.params || {};

	const setParam = ( key, val ) => onChange( { ...action, params: { ...params, [ key ]: val } } );

	const renderField = ( key, schema ) => {
		const t       = schema?.type || 'string';
		const reqd    = !! schema?.required;
		const current = params[ key ];
		if ( t === 'integer' ) {
			return (
				<Input
					type="number"
					value={ current ?? '' }
					onChange={ ( e ) => setParam( key, e.target.value === '' ? '' : Number( e.target.value ) ) }
					placeholder={ reqd ? '* bắt buộc' : 'tuỳ chọn' }
				/>
			);
		}
		if ( key === 'content' || key === 'extra_json' ) {
			return (
				<Textarea
					rows={ key === 'content' ? 3 : 2 }
					value={ current || '' }
					onChange={ ( e ) => setParam( key, e.target.value ) }
					placeholder={ reqd ? '* bắt buộc' : 'tuỳ chọn' }
				/>
			);
		}
		return (
			<Input
				value={ current || '' }
				onChange={ ( e ) => setParam( key, e.target.value ) }
				placeholder={ reqd ? '* bắt buộc' : 'tuỳ chọn' }
			/>
		);
	};

	return (
		<div className="rounded-lg border border-gray-200 bg-white p-3 flex flex-col gap-2">
			<div className="flex items-center justify-between gap-2">
				<select
					className="bzc-input !py-1 !text-sm flex-1"
					value={ action.type }
					onChange={ ( e ) => onChange( { type: e.target.value, params: {} } ) }
				>
					{ catalog.map( ( a ) => (
						<option key={ a.type } value={ a.type }>{ a.label } ({ a.type })</option>
					) ) }
				</select>
				<button
					type="button"
					onClick={ onRemove }
					className="p-1.5 rounded hover:bg-red-50 text-gray-400 hover:text-red-600"
					title="Xoá action"
				>
					<Trash2 size={ 14 } />
				</button>
			</div>
			{ def?.description && (
				<p className="text-[11px] text-gray-500">{ def.description }</p>
			) }
			{ def && Object.keys( def.param_schema || {} ).length > 0 && (
				<div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
					{ Object.entries( def.param_schema ).map( ( [ key, schema ] ) => (
						<div key={ key }>
							<FieldLabel className="!text-[11px]">
								{ key }{ schema?.required && <span className="text-red-500"> *</span> }
								<span className="text-gray-400 ml-1">({ schema?.type || 'string' })</span>
							</FieldLabel>
							{ renderField( key, schema ) }
						</div>
					) ) }
				</div>
			) }
		</div>
	);
}

function ActionBuilder( { value, catalog, onChange } ) {
	const list = Array.isArray( value ) ? value : [];

	const addAction = () => {
		const first = catalog[ 0 ];
		if ( ! first ) { return; }
		onChange( [ ...list, { type: first.type, params: {} } ] );
	};

	const updateAt = ( i, next ) => {
		const copy = list.slice();
		copy[ i ] = next;
		onChange( copy );
	};

	const removeAt = ( i ) => onChange( list.filter( ( _, j ) => j !== i ) );

	return (
		<div className="rounded-lg border border-gray-200 bg-gray-50 p-3 flex flex-col gap-3">
			<div className="flex items-center justify-between">
				<div className="text-xs text-gray-600">
					Hành động — chạy <strong>tuần tự</strong> khi điều kiện match
					{ list.length === 0 && <span className="text-red-500 ml-1">(bắt buộc ≥ 1)</span> }
				</div>
				<Button type="button" onClick={ addAction } disabled={ ! catalog.length }>
					<Plus size={ 12 } /> Thêm action
				</Button>
			</div>
			{ list.length === 0 && (
				<div className="text-xs italic text-gray-400">— Chưa có action. Phải có ≥ 1 action để lưu rule. —</div>
			) }
			{ list.map( ( a, i ) => (
				<ActionItem
					key={ i }
					action={ a }
					catalog={ catalog }
					onChange={ ( next ) => updateAt( i, next ) }
					onRemove={ () => removeAt( i ) }
				/>
			) ) }
		</div>
	);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Dry-run Panel
 * ────────────────────────────────────────────────────────────────────────── */

function DryRunPanel( { ruleId } ) {
	const [ run, { data, isLoading, error, reset } ] = useDryRunAutomationRuleMutation();
	const [ payloadText, setPayloadText ] = useState( '{\n  "conversation_id": 0,\n  "message_id": 0\n}' );

	const exec = async () => {
		let payload = {};
		try { payload = JSON.parse( payloadText ); }
		catch ( _ ) { alert( 'Payload không phải JSON hợp lệ.' ); return; }
		try { await run( { id: ruleId, payload } ).unwrap(); }
		catch ( _ ) { /* surfaced via error */ }
	};

	return (
		<div className="rounded-lg border border-gray-200 bg-gray-50 p-3 flex flex-col gap-2">
			<div className="text-xs font-semibold text-gray-700">Thử nghiệm (dry-run)</div>
			<FieldLabel className="!text-[11px]">Event payload (JSON)</FieldLabel>
			<Textarea
				rows={ 5 }
				value={ payloadText }
				onChange={ ( e ) => { setPayloadText( e.target.value ); reset(); } }
				className="!font-mono !text-xs"
			/>
			<div>
				<Button type="button" variant="primary" onClick={ exec } disabled={ isLoading }>
					<Play size={ 12 } /> { isLoading ? 'Đang chạy…' : 'Chạy thử' }
				</Button>
			</div>
			{ error && (
				<div className="text-xs text-red-600">
					Lỗi: { error?.data?.message || String( error?.error || error ) }
				</div>
			) }
			{ data && (
				<div className="rounded border border-gray-200 bg-white p-2 text-xs flex flex-col gap-2">
					<div className="flex items-center gap-2">
						{ data.matched
							? <span className="inline-flex items-center gap-1 text-emerald-700"><CheckCircle2 size={ 14 } /> MATCHED</span>
							: <span className="inline-flex items-center gap-1 text-amber-700"><AlertTriangle size={ 14 } /> NOT MATCHED</span>
						}
						<span className="text-gray-400">· { data.actions?.length || 0 } action result(s)</span>
					</div>
					<pre className="bg-gray-900 text-gray-100 rounded p-2 overflow-auto max-h-64 text-[11px]">{ JSON.stringify( data, null, 2 ) }</pre>
				</div>
			) }
		</div>
	);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Rule Form (Sheet)
 * ────────────────────────────────────────────────────────────────────────── */

function RuleForm( { initial, catalog, busy, onSubmit, onCancel, ruleId } ) {
	const [ rule, setRule ] = useState( () => ( initial ? {
		name:        initial.name || '',
		description: initial.description || '',
		event_name:  initial.event_name || 'crm_message_received',
		inbox_id:    initial.inbox_id ?? null,
		active:      !! initial.active,
		conditions:  initial.conditions && typeof initial.conditions === 'object'
			? { operator: initial.conditions.operator || 'all', rules: Array.isArray( initial.conditions.rules ) ? initial.conditions.rules : [] }
			: { operator: 'all', rules: [] },
		actions:     Array.isArray( initial.actions ) ? initial.actions : [],
	} : emptyRule() ) );
	const [ err, setErr ] = useState( '' );

	const set = ( patch ) => setRule( ( r ) => ( { ...r, ...patch } ) );

	const submit = async ( e ) => {
		e.preventDefault();
		setErr( '' );
		if ( ! rule.name.trim() ) { setErr( 'Tên rule không được trống.' ); return; }
		if ( ! rule.actions.length ) { setErr( 'Phải có ít nhất 1 action.' ); return; }
		try {
			await onSubmit( {
				...rule,
				name:        rule.name.trim(),
				description: rule.description?.trim() || '',
				inbox_id:    rule.inbox_id || null,
				active:      rule.active ? 1 : 0,
			} );
		} catch ( ex ) {
			const msg = ex?.data?.message || ex?.error || 'Lưu thất bại. Xem console.';
			setErr( msg );
			// eslint-disable-next-line no-console
			console.error( '[bizcity-crm] save rule failed', ex );
		}
	};

	return (
		<form onSubmit={ submit } className="flex flex-col gap-4">
			<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
				<div>
					<FieldLabel>Tên rule *</FieldLabel>
					<Input
						autoFocus
						value={ rule.name }
						onChange={ ( e ) => set( { name: e.target.value } ) }
						placeholder="VD: Auto-tag VIP khi chứa từ khóa"
					/>
				</div>
				<div>
					<FieldLabel>Trigger event *</FieldLabel>
					<select
						className="bzc-input"
						value={ rule.event_name }
						onChange={ ( e ) => set( { event_name: e.target.value } ) }
					>
						{ EVENT_OPTIONS.map( ( e ) => (
							<option key={ e.value } value={ e.value }>{ e.label } · { e.value }</option>
						) ) }
					</select>
				</div>
			</div>
			<div>
				<FieldLabel>Mô tả</FieldLabel>
				<Textarea
					rows={ 2 }
					value={ rule.description }
					onChange={ ( e ) => set( { description: e.target.value } ) }
					placeholder="Mô tả mục đích cho team."
				/>
			</div>
			<div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
				<div>
					<FieldLabel>Inbox (lọc theo inbox, để trống = mọi inbox)</FieldLabel>
					<Input
						type="number"
						value={ rule.inbox_id ?? '' }
						onChange={ ( e ) => set( { inbox_id: e.target.value === '' ? null : Number( e.target.value ) } ) }
						placeholder="VD: 12"
					/>
				</div>
				<label className="flex items-center gap-2 self-end text-sm pb-2">
					<input
						type="checkbox"
						checked={ !! rule.active }
						onChange={ ( e ) => set( { active: e.target.checked } ) }
					/>
					Bật rule ngay sau khi lưu
				</label>
			</div>

			<ConditionBuilder
				value={ rule.conditions }
				onChange={ ( conditions ) => set( { conditions } ) }
			/>

			<ActionBuilder
				value={ rule.actions }
				catalog={ catalog }
				onChange={ ( actions ) => set( { actions } ) }
			/>

			{ !! ruleId && <DryRunPanel ruleId={ ruleId } /> }

			{ err && <div className="text-sm text-red-600">{ err }</div> }

			<div className="flex justify-end gap-2 pt-2 border-t">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>
					{ busy ? 'Đang lưu…' : ( initial?.id ? 'Cập nhật' : 'Tạo rule' ) }
				</Button>
			</div>
		</form>
	);
}

/* ──────────────────────────────────────────────────────────────────────────
 * Main Tab
 * ────────────────────────────────────────────────────────────────────────── */

export default function AutomationTab() {
	const { data: rules    = [], isFetching, refetch } = useGetAutomationRulesQuery();
	const { data: catalog  = [] }                      = useGetAutomationActionsQuery();
	const [ createRule, { isLoading: creating } ] = useCreateAutomationRuleMutation();
	const [ updateRule, { isLoading: updating } ] = useUpdateAutomationRuleMutation();
	const [ deleteRule ]                          = useDeleteAutomationRuleMutation();

	const [ sheetOpen, setSheetOpen ] = useState( false );
	const [ editing,   setEditing   ] = useState( null );

	const openNew  = () => { setEditing( null ); setSheetOpen( true ); };
	const openEdit = ( r ) => { setEditing( r ); setSheetOpen( true ); };
	const close    = () => { setSheetOpen( false ); setEditing( null ); };

	const toggleActive = async ( r ) => {
		try { await updateRule( { id: r.id, name: r.name, event_name: r.event_name, conditions: r.conditions, actions: r.actions, active: r.active ? 0 : 1, inbox_id: r.inbox_id ?? null, description: r.description || '' } ).unwrap(); }
		catch ( ex ) {
			// eslint-disable-next-line no-console
			console.error( '[bizcity-crm] toggle rule failed', ex );
		}
	};

	const onSubmit = async ( body ) => {
		if ( editing?.id ) {
			await updateRule( { id: editing.id, ...body } ).unwrap();
		} else {
			const created = await createRule( body ).unwrap();
			if ( created?.id ) { setEditing( created ); }
		}
		refetch();
		// keep sheet open after create so dry-run becomes available; close on edit
		if ( editing?.id ) { close(); }
	};

	const onDelete = async ( r ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `Xoá rule "${ r.name }"?` ) ) { return; }
		try { await deleteRule( { id: r.id } ).unwrap(); }
		catch ( ex ) {
			// eslint-disable-next-line no-console
			console.error( '[bizcity-crm] delete rule failed', ex );
			alert( 'Xoá thất bại.' );
		}
	};

	const onDuplicate = ( r ) => {
		setEditing( {
			...r,
			id:     undefined,
			name:   r.name + ' (copy)',
			active: false,
		} );
		setSheetOpen( true );
	};

	const grouped = useMemo( () => {
		const out = {};
		rules.forEach( ( r ) => { ( out[ r.event_name ] || ( out[ r.event_name ] = [] ) ).push( r ); } );
		return out;
	}, [ rules ] );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Automation Rules</h2>
					<p className="bzc-tabpane-subtitle">
						Tự động xử lý theo event. { isFetching ? 'Đang tải…' : `${ rules.length } rule · ${ catalog.length } action types khả dụng` }
					</p>
				</div>
				<Button variant="primary" onClick={ openNew }>
					<Plus size={ 14 } /> Rule mới
				</Button>
			</header>

			{ ! rules.length && ! isFetching && (
				<div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
					<Power size={ 32 } className="mx-auto text-gray-400" />
					<p className="mt-3 text-sm text-gray-600">Chưa có automation rule nào.</p>
					<p className="text-xs text-gray-400 mb-4">VD: auto-tag VIP, auto-assign theo từ khoá, gửi welcome message…</p>
					<Button variant="primary" onClick={ openNew }><Plus size={ 14 } /> Tạo rule đầu tiên</Button>
				</div>
			) }

			{ Object.entries( grouped ).map( ( [ evt, list ] ) => (
				<section key={ evt } className="mb-6">
					<h3 className="text-xs uppercase tracking-wide text-gray-500 font-semibold mb-2">
						{ eventLabel( evt ) } <span className="text-gray-400">· { evt } · ({ list.length })</span>
					</h3>
					<div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
						<table className="w-full text-sm">
							<thead className="bg-gray-50 text-xs text-gray-600">
								<tr>
									<th className="text-left px-3 py-2 font-medium">Tên</th>
									<th className="text-left px-3 py-2 font-medium">Conditions</th>
									<th className="text-left px-3 py-2 font-medium">Actions</th>
									<th className="text-right px-3 py-2 font-medium">Chạy</th>
									<th className="text-center px-3 py-2 font-medium w-24">Bật</th>
									<th className="w-32" />
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-100">
								{ list.map( ( r ) => (
									<tr key={ r.id } className="hover:bg-gray-50">
										<td className="px-3 py-2">
											<button
												type="button"
												onClick={ () => openEdit( r ) }
												className="text-left text-gray-900 hover:text-indigo-600 font-medium"
											>
												{ r.name }
											</button>
											{ r.inbox_id ? <div className="text-[10px] text-gray-400">inbox #{ r.inbox_id }</div> : null }
										</td>
										<td className="px-3 py-2 text-xs text-gray-500">
											{ r.conditions?.rules?.length
												? `${ r.conditions.rules.length } điều kiện (${ ( r.conditions.operator || 'all' ).toUpperCase() })`
												: <span className="italic">không có</span>
											}
										</td>
										<td className="px-3 py-2 text-xs">
											<div className="flex flex-wrap gap-1">
												{ ( r.actions || [] ).map( ( a, i ) => (
													<span key={ i } className="inline-flex px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 text-[10px]">
														{ a.type }
													</span>
												) ) }
												{ ! ( r.actions || [] ).length && <span className="italic text-red-500">trống</span> }
											</div>
										</td>
										<td className="px-3 py-2 text-right text-xs text-gray-500">{ r.run_count || 0 }</td>
										<td className="px-3 py-2 text-center">
											<button
												type="button"
												onClick={ () => toggleActive( r ) }
												className={
													'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold ' +
													( r.active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' )
												}
												title={ r.active ? 'Đang bật — click để tắt' : 'Đang tắt — click để bật' }
											>
												{ r.active ? 'ON' : 'OFF' }
											</button>
										</td>
										<td className="px-3 py-2 text-right">
											<button type="button" onClick={ () => openEdit( r ) }    className="p-1 text-gray-400 hover:text-gray-700"  title="Sửa"><Pencil size={ 14 } /></button>
											<button type="button" onClick={ () => onDuplicate( r ) } className="p-1 text-gray-400 hover:text-gray-700"  title="Nhân bản"><Copy size={ 14 } /></button>
											<button type="button" onClick={ () => onDelete( r ) }    className="p-1 text-gray-400 hover:text-red-600"   title="Xoá"><Trash2 size={ 14 } /></button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</section>
			) ) }

			<Sheet open={ sheetOpen } onOpenChange={ ( v ) => ( v ? setSheetOpen( true ) : close() ) }>
				<SheetContent className="!max-w-3xl">
					<SheetHeader>
						<SheetTitle>{ editing?.id ? `Sửa rule · ${ editing.name }` : 'Tạo rule mới' }</SheetTitle>
					</SheetHeader>
					<SheetBody>
						<RuleForm
							key={ editing?.id || 'new' }
							initial={ editing }
							catalog={ catalog }
							busy={ creating || updating }
							onCancel={ close }
							onSubmit={ onSubmit }
							ruleId={ editing?.id }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}
