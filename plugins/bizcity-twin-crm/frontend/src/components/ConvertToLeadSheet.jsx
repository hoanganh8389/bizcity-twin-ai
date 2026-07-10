/**
 * ConvertToLeadSheet — PHASE 0.35 M-Bridge.W2 (FE).
 *
 * Right-side drawer launched from `ConversationDetail` header. Prefills name /
 * email / phone from the conversation's bound contact, lets the agent override
 * before POSTing to `bizcity-crm/v1/conversations/{id}/convert-to-lead`.
 *
 * Idempotency: BE returns `existing:true` + the open Lead row when a Lead in
 * status NOT IN ('lost','converted') already exists for the same contact —
 * we surface that as an info banner instead of treating it as an error.
 */
import React, { useEffect, useMemo, useState } from 'react';
import {
	Sheet,
	SheetContent,
	SheetHeader,
	SheetTitle,
	SheetDescription,
	SheetBody,
	SheetFooter,
	SheetClose,
} from './ui/sheet.jsx';
import {
	useGetConversationQuery,
	useGetCrmLeadsQuery,
	useConvertConvToLeadMutation,
} from '../redux/api/crmApi.js';

function splitName( full ) {
	const s = String( full || '' ).trim();
	if ( ! s ) { return { first_name: '', last_name: '' }; }
	const parts = s.split( /\s+/ );
	if ( parts.length === 1 ) { return { first_name: parts[ 0 ], last_name: '' }; }
	return { first_name: parts[ 0 ], last_name: parts.slice( 1 ).join( ' ' ) };
}

export default function ConvertToLeadSheet( { convId, open, onOpenChange } ) {
	const { data: conv } = useGetConversationQuery( convId, { skip: ! convId || ! open } );
	const contactId      = conv?.contact?.id || 0;

	/* Detect existing open Lead for this contact (idempotency hint). */
	const { data: existingLeads = [] } = useGetCrmLeadsQuery(
		contactId ? { contact_id: contactId, limit: 5 } : undefined,
		{ skip: ! contactId || ! open }
	);
	const openLead = useMemo( () => {
		return ( existingLeads || [] ).find(
			( l ) => l && l.status && ! [ 'lost', 'converted' ].includes( String( l.status ).toLowerCase() )
		) || null;
	}, [ existingLeads ] );

	const [ form, setForm ] = useState( {
		first_name: '', last_name: '', email: '', phone: '', company: '', source: '', notes: '',
	} );
	const [ result, setResult ] = useState( null );

	/* Prefill from contact whenever the sheet opens or the conversation arrives. */
	useEffect( () => {
		if ( ! open || ! conv?.contact ) { return; }
		const c    = conv.contact || {};
		const name = splitName( c.name );
		setForm( ( prev ) => ( {
			...prev,
			first_name: prev.first_name || name.first_name,
			last_name:  prev.last_name  || name.last_name,
			email:      prev.email      || c.email || '',
			phone:      prev.phone      || c.phone || '',
			source:     prev.source     || `inbox:conv#${ convId }`,
		} ) );
	}, [ open, conv?.contact?.id, convId ] );

	/* Reset on close so a fresh open re-prefills cleanly. */
	useEffect( () => {
		if ( ! open ) {
			setResult( null );
			setForm( { first_name: '', last_name: '', email: '', phone: '', company: '', source: '', notes: '' } );
		}
	}, [ open ] );

	const [ convert, convertState ] = useConvertConvToLeadMutation();

	const onChange = ( k ) => ( e ) => setForm( ( p ) => ( { ...p, [ k ]: e.target.value } ) );

	const valid = (
		form.first_name.trim() || form.last_name.trim() ||
		form.email.trim() || form.phone.trim() || form.company.trim()
	);

	const submit = async () => {
		if ( ! valid || ! convId || convertState.isLoading ) { return; }
		try {
			const data = await convert( { convId, ...form } ).unwrap();
			setResult( data );
		} catch ( e ) {
			setResult( { _error: e?.data?.error?.message || e?.error || 'convert_failed' } );
		}
	};

	const errMsg = result?._error
		|| convertState.error?.data?.error?.message
		|| convertState.error?.error
		|| null;

	return (
		<Sheet open={ open } onOpenChange={ onOpenChange }>
			<SheetContent side="right" className="w-[420px]">
				<SheetHeader>
					<SheetTitle>🎯 Convert to Lead</SheetTitle>
					<SheetDescription>
						Tạo Lead trong sales pipeline từ hội thoại #{ convId }.
					</SheetDescription>
				</SheetHeader>

				<SheetBody className="space-y-3">
					{ openLead ? (
						<div className="px-3 py-2 border border-amber-200 bg-amber-50 text-amber-800 text-[12px] rounded">
							ℹ Contact này đã có Lead đang mở <span className="font-mono font-semibold">#{ openLead.id }</span>
							{ openLead.status ? <> ({ openLead.status })</> : null } — submit sẽ trả về lead này thay vì tạo mới.
						</div>
					) : null }

					{ result && ! result._error ? (
						<div className="px-3 py-2 border border-emerald-200 bg-emerald-50 text-emerald-800 text-[12px] rounded">
							{ result.existing
								? <>✓ Lead đã tồn tại: <span className="font-mono font-semibold">#{ result.id }</span></>
								: <>✅ Đã tạo Lead <span className="font-mono font-semibold">#{ result.id }</span></>
							}
						</div>
					) : null }

					{ errMsg ? (
						<div className="px-3 py-2 border border-rose-200 bg-rose-50 text-rose-700 text-[12px] rounded">
							⚠ { String( errMsg ) }
						</div>
					) : null }

					<div className="grid grid-cols-2 gap-2">
						<label className="text-[11px] text-slate-600 col-span-1">First name
							<input value={ form.first_name } onChange={ onChange( 'first_name' ) }
								className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none" />
						</label>
						<label className="text-[11px] text-slate-600 col-span-1">Last name
							<input value={ form.last_name } onChange={ onChange( 'last_name' ) }
								className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none" />
						</label>
					</div>
					<label className="text-[11px] text-slate-600 block">Email
						<input type="email" value={ form.email } onChange={ onChange( 'email' ) }
							className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none" />
					</label>
					<label className="text-[11px] text-slate-600 block">Phone
						<input value={ form.phone } onChange={ onChange( 'phone' ) }
							className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none" />
					</label>
					<label className="text-[11px] text-slate-600 block">Company
						<input value={ form.company } onChange={ onChange( 'company' ) }
							className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none" />
					</label>
					<label className="text-[11px] text-slate-600 block">Source
						<input value={ form.source } onChange={ onChange( 'source' ) }
							className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none font-mono" />
					</label>
					<label className="text-[11px] text-slate-600 block">Notes
						<textarea rows={ 3 } value={ form.notes } onChange={ onChange( 'notes' ) }
							className="w-full mt-0.5 px-2 py-1 border border-slate-200 text-[12px] focus:border-indigo-300 outline-none resize-none" />
					</label>

					{ ! valid ? (
						<div className="text-[10px] text-slate-400 italic">Cần ít nhất một trong: tên, email, phone hoặc công ty.</div>
					) : null }
				</SheetBody>

				<SheetFooter className="flex items-center gap-2 justify-end">
					<SheetClose className="px-3 py-1.5 text-[12px] border border-slate-200 text-slate-600 hover:bg-slate-50">
						{ result && ! result._error ? 'Đóng' : 'Huỷ' }
					</SheetClose>
					<button type="button"
						disabled={ ! valid || convertState.isLoading }
						onClick={ submit }
						className="px-3 py-1.5 text-[12px] font-semibold bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
					>
						{ convertState.isLoading ? '⏳ Đang tạo…' : ( openLead ? '↩ Trả về Lead hiện có' : '🎯 Tạo Lead' ) }
					</button>
				</SheetFooter>
			</SheetContent>
		</Sheet>
	);
}
