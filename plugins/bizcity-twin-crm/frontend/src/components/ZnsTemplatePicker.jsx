/**
 * ZnsTemplatePicker — Shared picker component for ZNS template catalog.
 *
 * UX flow:
 *  1. Catalog dropdown (if templates exist) → auto-fills TempID + OA ID + var names.
 *     OA ID field hidden when selected template already has an OA.
 *  2. If no template matches / user clicks "Nhập tay" → manual TempID + OA ID inputs.
 *  3. Variable rows always editable. Source dropdown: "Recipient field" or "Giá trị cố định".
 *     Recipient source: {name} = tên người nhận (CSV col "name"), {phone} = số điện thoại.
 *
 * Props (all controlled):
 *   tempId         string            — current TempID value
 *   oaId           string            — current OA ID value
 *   tempVars       [{k, v}]          — current variable values array
 *   onChangeTempId (newId) => void
 *   onChangeOaId   (newOaId) => void
 *   onChangeTempVars ([{k,v}]) => void
 *
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — new component.
 * [2026-06-28 Johnny Chu] PHASE-CG-ZNS-TEMPLATE-CATALOG — UX overhaul: catalog-first, OA hidden when template provides it, recipient-field source selector.
 */
import React, { useState } from 'react';
import { useGetZnsTemplatesQuery } from '../redux/api/crmZnsTemplatesApi';
import { Input, Label } from './ui/input';
import { Button } from './ui/button';

// Recipient field options used in source=recipient rows
const RECIPIENT_FIELDS = [
	{ value: '{name}',  label: 'name — tên người nhận (CSV)' },
	{ value: '{phone}', label: 'phone — số điện thoại' },
	{ value: '{email}', label: 'email' },
];

export default function ZnsTemplatePicker( {
	tempId,
	oaId,
	tempVars,
	onChangeTempId,
	onChangeOaId,
	onChangeTempVars,
} ) {
	const { data: templates = [], isLoading } = useGetZnsTemplatesQuery( {} );
	const [ showManual, setShowManual ] = useState( false );

	// Which catalog template is currently selected
	const selectedTpl  = templates.find( ( t ) => t.temp_id === tempId ) || null;
	const fromCatalog  = !! selectedTpl;
	// Hide OA field when template already provides an OA ID
	const tplHasOa     = fromCatalog && !! selectedTpl.oa_id;

	// Pick from catalog dropdown
	const handlePickTemplate = ( e ) => {
		const tid = e.target.value;
		if ( tid === '__manual__' || tid === '' ) {
			setShowManual( true );
			onChangeTempId( '' );
			onChangeOaId( '' );
			onChangeTempVars( [] );
			return;
		}
		setShowManual( false );
		const tpl = templates.find( ( t ) => t.temp_id === tid );
		if ( ! tpl ) { return; }
		onChangeTempId( tpl.temp_id );
		onChangeOaId( tpl.oa_id || '' );
		// Auto-fill var names; set source=recipient + field={name} for common "name" vars, else literal
		onChangeTempVars( ( tpl.vars || [] ).map( ( v ) => {
			const lc = ( v.var_name || '' ).toLowerCase();
			const isNameField = lc === 'ten' || lc.startsWith( 'ten_' ) || lc === 'name' || lc.startsWith( 'ho_ten' );
			return {
				k:      v.var_name,
				v:      isNameField ? '{name}' : '',
				source: isNameField ? 'recipient' : 'literal',
			};
		} ) );
	};

	// Var helpers
	const addVar = () => onChangeTempVars( [ ...( tempVars || [] ), { k: '', v: '', source: 'literal' } ] );

	const updateVar = ( idx, patch ) => {
		const next = ( tempVars || [] ).map( ( row, i ) => i === idx ? { ...row, ...patch } : row );
		onChangeTempVars( next );
	};

	const removeVar = ( idx ) => onChangeTempVars( ( tempVars || [] ).filter( ( _, i ) => i !== idx ) );

	// Always show catalog section — even if empty so user gets a prompt to add templates first
	const showCatalog = true;
	const manualMode  = showManual || templates.length === 0;

	return (
		<div className="space-y-3">

			{/* ── Step 1: catalog selector ── */}
			<div>
				<Label className="mb-1 block text-xs font-semibold">📋 Chọn từ catalog template</Label>
				{ isLoading && <p className="text-xs text-slate-400">Đang tải danh sách template…</p> }
				{ ! isLoading && templates.length === 0 && (
					<p className="text-xs text-slate-400 italic">Chưa có template nào trong catalog — nhập TempID và OA ID thủ công bên dưới, hoặc thêm template tại Channel Gateway → Zalo ZNS → Templates.</p>
				) }
				{ ! isLoading && templates.length > 0 && (
					<>
					<select
						className="w-full rounded border border-slate-200 bg-white px-2 py-1.5 text-sm"
						value={ fromCatalog && ! showManual ? tempId : ( showManual ? '__manual__' : '' ) }
						onChange={ handlePickTemplate }
					>
						<option value="">— Chọn template từ danh sách —</option>
						{ templates.map( ( t ) => (
							<option key={ t.temp_id } value={ t.temp_id }>
								{ t.name || t.temp_id }{ t.oa_id ? ` (OA: ${ t.oa_id })` : '' }
							</option>
						) ) }
						<option value="__manual__">✏️ Nhập thủ công TempID / OA ID</option>
					</select>
					{ fromCatalog && ! showManual && (
						<p className="mt-1 text-xs text-slate-500">
							Template: <strong>{ selectedTpl.name }</strong>
							{ selectedTpl.description ? ` — ${ selectedTpl.description }` : '' }
							{ tplHasOa ? ` · OA: ${ selectedTpl.oa_id }` : '' }
						</p>
					) }
					</>
				) }
			</div>

			{/* ── Step 2: manual TempID + OA ID (shown when no catalog or manual mode) ── */}
			{ ( manualMode || fromCatalog ) && (
				<div className="grid grid-cols-2 gap-2">
					<div>
						<Label className="mb-1 block text-xs">ZNS TempID *</Label>
						<Input
							value={ tempId || '' }
							onChange={ ( e ) => onChangeTempId( e.target.value ) }
							placeholder="595298"
							className="h-8 text-sm"
							readOnly={ fromCatalog && ! showManual }
							style={ fromCatalog && ! showManual ? { background: '#f8fafc', cursor: 'default' } : {} }
						/>
					</div>
					{ ! tplHasOa && (
						<div>
							<Label className="mb-1 block text-xs">OA ID{ tplHasOa ? '' : '' }</Label>
							<Input
								value={ oaId || '' }
								onChange={ ( e ) => onChangeOaId( e.target.value ) }
								placeholder="402129037615218619"
								className="h-8 text-sm"
							/>
						</div>
					) }
				</div>
			) }

			{/* ── Step 3: variables ── */}
			<div>
				<div className="mb-1 flex items-center justify-between">
					<Label className="text-xs font-semibold">Biến template (TempData)</Label>
					<Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={ addVar }>
						+ Thêm biến
					</Button>
				</div>

				{ ( tempVars || [] ).length === 0 && (
					<p className="text-xs text-slate-400">
						{ fromCatalog ? 'Template không có biến.' : 'Chưa có biến. Chọn template hoặc thêm tay.' }
					</p>
				) }

				{ ( tempVars || [] ).map( ( row, idx ) => {
					const varMeta = selectedTpl
						? ( selectedTpl.vars || [] ).find( ( v ) => v.var_name === row.k )
						: null;
					const isRecipient = row.source === 'recipient';

					return (
						<div key={ idx } className="mb-2 rounded border border-slate-100 bg-slate-50 p-2">
							<div className="flex items-center gap-1.5 mb-1">
								{/* Var name */}
								<Input
									value={ row.k || '' }
									onChange={ ( e ) => updateVar( idx, { k: e.target.value } ) }
									placeholder="var_name"
									className="h-7 w-32 shrink-0 font-mono text-xs"
									readOnly={ fromCatalog && ! showManual }
									style={ fromCatalog && ! showManual ? { background: '#f1f5f9' } : {} }
								/>
								{/* Source toggle */}
								<select
									value={ row.source || 'literal' }
									onChange={ ( e ) => updateVar( idx, { source: e.target.value, v: e.target.value === 'recipient' ? '{name}' : '' } ) }
									className="h-7 rounded border border-slate-200 bg-white px-1 text-xs"
									style={ { minWidth: 110 } }
								>
									<option value="literal">Giá trị cố định</option>
									<option value="recipient">Từ người nhận</option>
								</select>
								{/* Value / field */}
								{ isRecipient ? (
									<select
										value={ row.v || '{name}' }
										onChange={ ( e ) => updateVar( idx, { v: e.target.value } ) }
										className="h-7 flex-1 rounded border border-slate-200 bg-white px-1 text-xs"
									>
										{ RECIPIENT_FIELDS.map( ( f ) => (
											<option key={ f.value } value={ f.value }>{ f.label }</option>
										) ) }
									</select>
								) : (
									<Input
										value={ row.v || '' }
										onChange={ ( e ) => updateVar( idx, { v: e.target.value } ) }
										placeholder={ varMeta ? varMeta.example || 'Giá trị' : 'Giá trị cố định' }
										title={ varMeta ? varMeta.description || '' : '' }
										className="h-7 flex-1 text-xs"
									/>
								) }
								<button type="button" onClick={ () => removeVar( idx ) } className="shrink-0 text-slate-400 hover:text-red-500" title="Xoá">×</button>
							</div>
							{ varMeta && varMeta.description && (
								<p className="text-xs text-slate-400 pl-0.5">{ varMeta.description }</p>
							) }
						</div>
					);
				} ) }

				{ ( tempVars || [] ).some( ( r ) => r.source === 'recipient' ) && (
					<p className="mt-1 text-xs text-slate-400 italic">
						"Từ người nhận": <strong>&#x7B;name&#x7D;</strong> = tên trong CSV / danh bạ, <strong>&#x7B;phone&#x7D;</strong> = số điện thoại.
						Nếu CSV không có cột <em>name</em>, hệ thống tự dùng số điện thoại làm fallback.
					</p>
				) }
			</div>
		</div>
	);
}

