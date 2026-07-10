import React, { useState } from 'react';
import {
	useGetCustomAttrsQuery,
	useCreateCustomAttrMutation,
	useUpdateCustomAttrMutation,
	useDeleteCustomAttrMutation,
} from '../../redux/api/crmApi.js';

const TARGETS = [ 'contact', 'conversation' ];
const TYPES   = [ 'text', 'number', 'link', 'date', 'list', 'checkbox', 'email', 'phone' ];

function AttrForm( { initial = null, onDone } ) {
	const [ name, setName ] = useState( initial?.name || '' );
	const [ key, setKey ] = useState( initial?.attr_key || '' );
	const [ target, setTarget ] = useState( initial?.attr_target || 'contact' );
	const [ display, setDisplay ] = useState( initial?.display_type || 'text' );
	const [ create, { isLoading: c1 } ] = useCreateCustomAttrMutation();
	const [ update, { isLoading: c2 } ] = useUpdateCustomAttrMutation();

	const submit = async ( e ) => {
		e.preventDefault();
		if ( ! name.trim() || ! key.trim() ) { return; }
		const body = { name: name.trim(), attr_key: key.trim(), attr_target: target, display_type: display };
		if ( initial?.id ) { await update( { id: initial.id, ...body } ); }
		else { await create( body ); setName( '' ); setKey( '' ); }
		onDone && onDone();
	};

	return (
		<form className="bzc-form" onSubmit={ submit }>
			<input className="bzc-input" placeholder="Tên hiển thị" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
			<input className="bzc-input" placeholder="Khoá (snake_case)" value={ key } onChange={ ( e ) => setKey( e.target.value ) } disabled={ !! initial?.id } />
			<div className="bzc-form-row">
				<label>Đối tượng</label>
				<select className="bzc-input" value={ target } onChange={ ( e ) => setTarget( e.target.value ) }>
					{ TARGETS.map( ( t ) => <option key={ t } value={ t }>{ t }</option> ) }
				</select>
			</div>
			<div className="bzc-form-row">
				<label>Kiểu</label>
				<select className="bzc-input" value={ display } onChange={ ( e ) => setDisplay( e.target.value ) }>
					{ TYPES.map( ( t ) => <option key={ t } value={ t }>{ t }</option> ) }
				</select>
			</div>
			<button className="bzc-btn-primary" type="submit" disabled={ c1 || c2 }>
				{ initial?.id ? 'Lưu' : 'Tạo attribute' }
			</button>
		</form>
	);
}

export default function AttrsTab() {
	const { data: attrs = [], isFetching } = useGetCustomAttrsQuery();
	const [ del ] = useDeleteCustomAttrMutation();
	const [ editing, setEditing ] = useState( null );

	return (
		<div className="bzc-tabpane bzc-tabpane-split">
			<div className="bzc-tabpane-main">
				<header className="bzc-tabpane-header">
					<h2>Custom Attributes</h2>
					<span className="bzc-muted">{ attrs.length } attribute</span>
				</header>
				{ isFetching && <div className="bzc-muted">Đang tải…</div> }
				<table className="bzc-table">
					<thead><tr><th>Tên</th><th>Khoá</th><th>Đối tượng</th><th>Kiểu</th><th></th></tr></thead>
					<tbody>
						{ attrs.map( ( a ) => (
							<tr key={ a.id } className="bzc-row">
								<td>{ a.name }</td>
								<td><code>{ a.attr_key }</code></td>
								<td>{ a.attr_target }</td>
								<td>{ a.display_type }</td>
								<td>
									<button className="bzc-btn-ghost" onClick={ () => setEditing( a ) }>Sửa</button>
									<button className="bzc-btn-ghost bzc-danger"
										// eslint-disable-next-line no-alert
										onClick={ () => window.confirm( 'Xoá ' + a.name + '?' ) && del( { id: a.id } ) }>Xoá</button>
								</td>
							</tr>
						) ) }
						{ ! attrs.length && ! isFetching && <tr><td colSpan={ 5 } className="bzc-empty bzc-muted">Chưa có attribute.</td></tr> }
					</tbody>
				</table>
			</div>
			<aside className="bzc-side-panel">
				<header><h3>{ editing ? 'Sửa attribute' : 'Tạo attribute' }</h3>{ editing && <button className="bzc-btn-ghost" onClick={ () => setEditing( null ) }>×</button> }</header>
				<div className="bzc-side-body">
					<AttrForm key={ editing?.id || 'new' } initial={ editing } onDone={ () => setEditing( null ) } />
				</div>
			</aside>
		</div>
	);
}
