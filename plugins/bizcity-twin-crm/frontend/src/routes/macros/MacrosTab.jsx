import React, { useState } from 'react';
import {
	useGetMacrosQuery,
	useCreateMacroMutation,
	useUpdateMacroMutation,
	useDeleteMacroMutation,
	usePreviewMacroMutation,
	useRenderTemplateMutation,
} from '../../redux/api/crmApi.js';

const VIS = [ 'private', 'team', 'public' ];

function MacroForm( { initial = null, onDone } ) {
	const [ name, setName ] = useState( initial?.name || '' );
	const [ visibility, setVis ] = useState( initial?.visibility || 'private' );
	const [ content, setContent ] = useState( initial?.content || 'Xin chào {{contact.name}},\n\n' );
	const [ create, { isLoading: c1 } ] = useCreateMacroMutation();
	const [ update, { isLoading: c2 } ] = useUpdateMacroMutation();
	const [ render, { data: rendered, isLoading: rendering } ] = useRenderTemplateMutation();

	const onPreview = () => render( { template: content } );
	const submit = async ( e ) => {
		e.preventDefault();
		if ( ! name.trim() ) { return; }
		const body = { name: name.trim(), visibility, content };
		if ( initial?.id ) { await update( { id: initial.id, ...body } ); }
		else { await create( body ); setName( '' ); }
		onDone && onDone();
	};

	return (
		<form className="bzc-form" onSubmit={ submit }>
			<input className="bzc-input" placeholder="Tên macro" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
			<select className="bzc-input" value={ visibility } onChange={ ( e ) => setVis( e.target.value ) }>
				{ VIS.map( ( v ) => <option key={ v } value={ v }>{ v }</option> ) }
			</select>
			<textarea className="bzc-textarea" rows={ 8 } value={ content } onChange={ ( e ) => setContent( e.target.value ) } />
			<div className="bzc-form-actions">
				<button className="bzc-btn" type="button" onClick={ onPreview } disabled={ rendering }>Xem trước</button>
				<button className="bzc-btn-primary" type="submit" disabled={ c1 || c2 }>
					{ initial?.id ? 'Lưu' : 'Tạo macro' }
				</button>
			</div>
			{ rendered && (
				<div className="bzc-preview">
					<div className="bzc-preview-title">Preview:</div>
					<pre className="bzc-pre">{ typeof rendered === 'string' ? rendered : ( rendered.rendered || JSON.stringify( rendered ) ) }</pre>
				</div>
			) }
		</form>
	);
}

export default function MacrosTab() {
	const { data: macros = [], isFetching } = useGetMacrosQuery();
	const [ del ] = useDeleteMacroMutation();
	const [ , { } ] = usePreviewMacroMutation(); // hook reservation
	const [ editing, setEditing ] = useState( null );

	return (
		<div className="bzc-tabpane bzc-tabpane-split">
			<div className="bzc-tabpane-main">
				<header className="bzc-tabpane-header">
					<h2>Macros</h2>
					<span className="bzc-muted">{ macros.length } macro</span>
				</header>
				{ isFetching && <div className="bzc-muted">Đang tải…</div> }
				<ul className="bzc-list">
					{ macros.map( ( m ) => (
						<li key={ m.id } className="bzc-list-item">
							<strong>{ m.name }</strong>
							<span className="bzc-badge">{ m.visibility }</span>
							<span className="bzc-list-actions">
								<button className="bzc-btn-ghost" onClick={ () => setEditing( m ) }>Sửa</button>
								<button className="bzc-btn-ghost bzc-danger"
									// eslint-disable-next-line no-alert
									onClick={ () => window.confirm( 'Xoá macro "' + m.name + '"?' ) && del( { id: m.id } ) }>Xoá</button>
							</span>
						</li>
					) ) }
					{ ! macros.length && ! isFetching && <li className="bzc-empty bzc-muted">Chưa có macro.</li> }
				</ul>
			</div>
			<aside className="bzc-side-panel">
				<header><h3>{ editing ? 'Sửa macro' : 'Tạo macro' }</h3>{ editing && <button className="bzc-btn-ghost" onClick={ () => setEditing( null ) }>×</button> }</header>
				<div className="bzc-side-body">
					<MacroForm key={ editing?.id || 'new' } initial={ editing } onDone={ () => setEditing( null ) } />
				</div>
			</aside>
		</div>
	);
}
