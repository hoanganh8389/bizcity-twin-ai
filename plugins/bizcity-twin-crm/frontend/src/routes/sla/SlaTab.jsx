import React, { useEffect, useMemo, useState } from 'react';
import {
	useGetInboxesQuery,
	useGetWorkingHoursQuery,
	useSaveWorkingHoursMutation,
	useGetSlaPoliciesQuery,
	useCreateSlaPolicyMutation,
	useUpdateSlaPolicyMutation,
	useDeleteSlaPolicyMutation,
	useTickSlaMutation,
} from '../../redux/api/crmApi.js';

const DAYS = [ 'CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7' ];

function defaultHours() {
	return DAYS.map( ( _, i ) => ( {
		day_of_week: i,
		open_time: i === 0 || i === 6 ? '' : '09:00',
		close_time: i === 0 || i === 6 ? '' : '18:00',
		is_closed: i === 0 || i === 6 ? 1 : 0,
	} ) );
}

function WorkingHoursPanel( { inboxes } ) {
	const [ inboxId, setInboxId ] = useState( inboxes[ 0 ]?.id || 0 );
	const { data, isFetching } = useGetWorkingHoursQuery( inboxId, { skip: ! inboxId } );
	const [ save, { isLoading: saving } ] = useSaveWorkingHoursMutation();
	const [ rows, setRows ] = useState( defaultHours );

	useEffect( () => {
		if ( data && data.length ) {
			const sorted = [ ...data ].sort( ( a, b ) => a.day_of_week - b.day_of_week );
			setRows( sorted );
		} else if ( ! isFetching ) {
			setRows( defaultHours() );
		}
	}, [ data, isFetching ] );

	const update = ( idx, patch ) => setRows( ( r ) => r.map( ( row, i ) => i === idx ? { ...row, ...patch } : row ) );

	const onSave = ( e ) => { e.preventDefault(); if ( inboxId ) { save( { inbox_id: inboxId, rows } ); } };

	return (
		<form className="bzc-card bzc-form" onSubmit={ onSave }>
			<div className="bzc-card-title">Working hours</div>
			<div className="bzc-form-row">
				<label>Inbox</label>
				<select className="bzc-input" value={ inboxId } onChange={ ( e ) => setInboxId( Number( e.target.value ) ) }>
					{ inboxes.map( ( ix ) => <option key={ ix.id } value={ ix.id }>{ ix.name }</option> ) }
				</select>
			</div>
			<table className="bzc-table">
				<thead><tr><th>Ngày</th><th>Đóng</th><th>Mở</th><th>Đóng</th></tr></thead>
				<tbody>
					{ rows.map( ( row, i ) => (
						<tr key={ row.day_of_week }>
							<td>{ DAYS[ row.day_of_week ] }</td>
							<td>
								<input type="checkbox" checked={ !! row.is_closed }
									onChange={ ( e ) => update( i, { is_closed: e.target.checked ? 1 : 0 } ) } />
							</td>
							<td>
								<input type="time" className="bzc-input" disabled={ !! row.is_closed }
									value={ row.open_time || '' } onChange={ ( e ) => update( i, { open_time: e.target.value } ) } />
							</td>
							<td>
								<input type="time" className="bzc-input" disabled={ !! row.is_closed }
									value={ row.close_time || '' } onChange={ ( e ) => update( i, { close_time: e.target.value } ) } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<button className="bzc-btn-primary" type="submit" disabled={ saving || ! inboxId }>{ saving ? 'Đang lưu…' : 'Lưu' }</button>
		</form>
	);
}

function PolicyForm( { initial = null, onDone } ) {
	const [ name, setName ] = useState( initial?.name || '' );
	const [ frt,  setFrt  ] = useState( initial?.first_response_time_threshold || 1800 );
	const [ nrt,  setNrt  ] = useState( initial?.next_response_time_threshold || 3600 );
	const [ rt,   setRt   ] = useState( initial?.resolution_time_threshold || 86400 );
	const [ bh,   setBh   ] = useState( !! ( initial?.business_hours_only ?? 1 ) );
	const [ create, { isLoading: c1 } ] = useCreateSlaPolicyMutation();
	const [ update, { isLoading: c2 } ] = useUpdateSlaPolicyMutation();

	const submit = async ( e ) => {
		e.preventDefault();
		const body = {
			name: name.trim(),
			first_response_time_threshold: Number( frt ),
			next_response_time_threshold: Number( nrt ),
			resolution_time_threshold: Number( rt ),
			business_hours_only: bh ? 1 : 0,
		};
		if ( ! body.name ) { return; }
		if ( initial?.id ) { await update( { id: initial.id, ...body } ); }
		else { await create( body ); setName( '' ); }
		onDone && onDone();
	};

	return (
		<form className="bzc-form" onSubmit={ submit }>
			<input className="bzc-input" placeholder="Tên policy" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
			<label>FRT (giây)</label>
			<input className="bzc-input" type="number" value={ frt } onChange={ ( e ) => setFrt( e.target.value ) } />
			<label>NRT (giây)</label>
			<input className="bzc-input" type="number" value={ nrt } onChange={ ( e ) => setNrt( e.target.value ) } />
			<label>Resolution (giây)</label>
			<input className="bzc-input" type="number" value={ rt } onChange={ ( e ) => setRt( e.target.value ) } />
			<label><input type="checkbox" checked={ bh } onChange={ ( e ) => setBh( e.target.checked ) } /> Chỉ đếm trong giờ làm việc</label>
			<button className="bzc-btn-primary" type="submit" disabled={ c1 || c2 }>{ initial?.id ? 'Lưu' : 'Tạo policy' }</button>
		</form>
	);
}

function SlaPoliciesPanel() {
	const { data: list = [], isFetching } = useGetSlaPoliciesQuery();
	const [ del ] = useDeleteSlaPolicyMutation();
	const [ tick, { isLoading: ticking } ] = useTickSlaMutation();
	const [ editing, setEditing ] = useState( null );

	return (
		<div className="bzc-card-row">
			<div className="bzc-card">
				<div className="bzc-card-title bzc-card-title-row">
					<span>SLA Policies</span>
					<button className="bzc-btn" onClick={ () => tick() } disabled={ ticking }>
						{ ticking ? 'Đang tick…' : 'Force tick SLA' }
					</button>
				</div>
				{ isFetching && <div className="bzc-muted">Đang tải…</div> }
				<table className="bzc-table">
					<thead><tr><th>Tên</th><th>FRT</th><th>NRT</th><th>RT</th><th>BH</th><th></th></tr></thead>
					<tbody>
						{ list.map( ( p ) => (
							<tr key={ p.id } className="bzc-row">
								<td>{ p.name }</td>
								<td>{ p.first_response_time_threshold }s</td>
								<td>{ p.next_response_time_threshold }s</td>
								<td>{ p.resolution_time_threshold }s</td>
								<td>{ p.business_hours_only ? '✓' : '—' }</td>
								<td>
									<button className="bzc-btn-ghost" onClick={ () => setEditing( p ) }>Sửa</button>
									<button className="bzc-btn-ghost bzc-danger"
										// eslint-disable-next-line no-alert
										onClick={ () => window.confirm( 'Xoá policy ' + p.name + '?' ) && del( { id: p.id } ) }>Xoá</button>
								</td>
							</tr>
						) ) }
						{ ! list.length && ! isFetching && <tr><td colSpan={ 6 } className="bzc-empty bzc-muted">Chưa có policy.</td></tr> }
					</tbody>
				</table>
			</div>
			<div className="bzc-card">
				<div className="bzc-card-title">{ editing ? 'Sửa policy' : 'Tạo policy mới' }</div>
				<PolicyForm key={ editing?.id || 'new' } initial={ editing } onDone={ () => setEditing( null ) } />
			</div>
		</div>
	);
}

export default function SlaTab() {
	const { data: inboxes = [] } = useGetInboxesQuery();
	const [ sub, setSub ] = useState( 'wh' );
	const list = useMemo( () => inboxes, [ inboxes ] );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<h2>SLA &amp; Working Hours</h2>
				<div className="bzc-tabpane-actions bzc-subtabs">
					<button className={ 'bzc-subtab ' + ( sub === 'wh'  ? 'is-active' : '' ) } onClick={ () => setSub( 'wh' ) }>Giờ làm việc</button>
					<button className={ 'bzc-subtab ' + ( sub === 'sla' ? 'is-active' : '' ) } onClick={ () => setSub( 'sla' ) }>Policies</button>
				</div>
			</header>
			{ sub === 'wh'  ? <WorkingHoursPanel inboxes={ list } /> : <SlaPoliciesPanel /> }
		</div>
	);
}
