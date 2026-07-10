import React, { useMemo, useState, useRef } from 'react';
import {
	Upload, FileText, FileSpreadsheet, Image as ImageIcon,
	Video, FileCode, File as FileIcon, Trash2, ExternalLink, StickyNote, Plus, Pencil, X,
} from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { DataTable } from '../../components/ui/data-table.jsx';
import { Badge } from '../../components/ui/card.jsx';
import { Input } from '../../components/ui/input.jsx';
import {
	Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription, SheetBody, SheetFooter,
} from '../../components/ui/sheet.jsx';
import {
	useListBzDocumentsQuery,
	useUploadBzDocumentMutation,
	useDeleteBzDocumentMutation,
} from '../../redux/api/bzdocApi.js';
// [2026-06-07 Johnny Chu] PHASE-0.40 G6-cleanup — NotesDoc hooks
import {
	useGetCrmNotesDocsQuery,
	useCreateCrmNoteDocMutation,
	useUpdateCrmNoteDocMutation,
	useDeleteCrmNoteDocMutation,
} from '../../redux/api/crmApi.js';

const TYPE_ICONS = {
	image: ImageIcon,
	video: Video,
	pdf: FileText,
	markdown: FileCode,
	json: FileCode,
	dataset: FileSpreadsheet,
	document: FileText,
};

const FILTERS = [
	{ key: 'all',       label: 'Tất cả',    args: {} },
	{ key: 'upload',    label: 'Đã tải lên', args: { origin: 'upload' } },
	{ key: 'generated', label: 'Đã sinh',   args: { origin: 'generated' } },
	{ key: 'image',     label: 'Ảnh',       args: { doc_type: 'image' } },
	{ key: 'video',     label: 'Video',     args: { doc_type: 'video' } },
	{ key: 'document',  label: 'Tài liệu',  args: { doc_type: 'document' } },
];

function fmtSize( n ) {
	if ( ! n ) { return '—'; }
	if ( n < 1024 ) { return n + ' B'; }
	if ( n < 1024 * 1024 ) { return ( n / 1024 ).toFixed( 1 ) + ' KB'; }
	return ( n / 1024 / 1024 ).toFixed( 1 ) + ' MB';
}

function fmtDate( v ) {
	if ( ! v ) { return '—'; }
	const d = new Date( typeof v === 'string' ? v.replace( ' ', 'T' ) : v );
	if ( ! Number.isFinite( d.getTime() ) ) { return '—'; }
	return d.toLocaleDateString( 'vi-VN' ) + ' ' + d.toLocaleTimeString( 'vi-VN', { hour: '2-digit', minute: '2-digit' } );
}

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const TWIN_BASE = ( BOOT.twinUrl || '' ).replace( /\/$/, '' );

function docHref( docId ) {
	if ( ! docId ) { return null; }
	return `${ TWIN_BASE }/?plugin=doc&id=${ docId }`;
}

function notebookHref( nbId ) {
	if ( ! nbId ) { return null; }
	return `${ TWIN_BASE }/?plugin=twinchat&notebook_id=${ nbId }`;
}

export default function DocumentsTab() {
	// [2026-06-07 Johnny Chu] PHASE-0.40 G6-cleanup — top-level tab: Files | Ghi chú nội bộ
	const [ topTab, setTopTab ] = useState( 'files' );
	const [ filter, setFilter ] = useState( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ uploadOpen, setUploadOpen ] = useState( false );

	const args = useMemo( () => {
		const f = FILTERS.find( ( x ) => x.key === filter ) || FILTERS[ 0 ];
		const a = { ...f.args, limit: 100 };
		if ( search.trim() ) { a.q = search.trim(); }
		return a;
	}, [ filter, search ] );

	const { data, isFetching, refetch } = useListBzDocumentsQuery( args );
	const docs  = ( data && Array.isArray( data.documents ) ) ? data.documents : [];
	const total = ( data && Number.isFinite( data.total ) ) ? data.total : docs.length;

	const [ deleteDoc ] = useDeleteBzDocumentMutation();

	const onDelete = async ( row ) => {
		if ( ! window.confirm( `Xoá "${ row.title || row.file_url || ('#' + row.id) }"?` ) ) { return; }
		try {
			await deleteDoc( row.id ).unwrap();
		} catch ( err ) {
			console.error( '[bzdoc] delete failed', err );
			alert( 'Xoá thất bại. Xem console.' );
		}
	};

	const cols = useMemo( () => [
		{ accessorKey: 'title', header: 'Tên', cell: ( c ) => {
			const r    = c.row.original;
			const Icon = TYPE_ICONS[ r.doc_type ] || FileIcon;
			const name = r.title || r.file_url?.split( '/' ).pop() || ( '#' + r.id );
			const link = docHref( r.id );
			return (
				<span className="bzc-doc-name" style={ { display: 'flex', alignItems: 'center', gap: 6 } }>
					<Icon size={ 14 } />
					<a href={ link } target="_parent">{ name }</a>
					{ r.file_url && (
						<a href={ r.file_url } target="_blank" rel="noreferrer" title="Tải file gốc" style={ { opacity: 0.5, lineHeight: 0 } }>
							<ExternalLink size={ 11 } />
						</a>
					) }
				</span>
			);
		} },
		{ accessorKey: 'doc_type', header: 'Loại', cell: ( c ) => <Badge variant="muted">{ c.getValue() || '—' }</Badge> },
		{ id: 'notebook', header: 'Notebook', cell: ( c ) => {
			const nb   = c.row.original.notebook_id;
			const href = notebookHref( nb );
			if ( ! nb ) { return <span className="bzc-muted">—</span>; }
			return (
				<a href={ href } target="_parent" title={ `Mở notebook #${ nb }` } style={ { display: 'inline-flex', alignItems: 'center', gap: 4 } }>
					#{ nb } <ExternalLink size={ 11 } />
				</a>
			);
		} },
		{ accessorKey: 'generator', header: 'Generator', cell: ( c ) => <Badge variant="muted">{ c.getValue() || '—' }</Badge> },
		{ accessorKey: 'origin', header: 'Origin', cell: ( c ) => {
			const v = c.getValue();
			return <Badge variant={ v === 'upload' ? 'warn' : 'ok' }>{ v || '—' }</Badge>;
		} },
		{ accessorKey: 'size_bytes', header: 'Dung lượng', cell: ( c ) => fmtSize( c.getValue() ) },
		{ accessorKey: 'created_at', header: 'Thời gian', cell: ( c ) => fmtDate( c.getValue() ) },
		{ id: 'actions', header: '', cell: ( c ) => (
			<Button size="sm" onClick={ () => onDelete( c.row.original ) } title="Xoá">
				<Trash2 size={ 12 } />
			</Button>
		) },
	], [] );

	return (
		<div className="bzc-tabpane">
			{ /* [2026-06-07 Johnny Chu] PHASE-0.40 G6-cleanup — top tab switcher */ }
			<div style={ { display: 'flex', gap: 0, borderBottom: '1px solid #e5e7eb', marginBottom: 0 } }>
				{ [ { key: 'files', label: 'Files', icon: <FileText size={ 13 } /> }, { key: 'notes', label: 'Ghi chú nội bộ', icon: <StickyNote size={ 13 } /> } ].map( ( t ) => (
					<button
						key={ t.key }
						type="button"
						onClick={ () => setTopTab( t.key ) }
						style={ {
							display: 'flex', alignItems: 'center', gap: 5, padding: '8px 16px', fontSize: 13, fontWeight: 500,
							background: 'none', border: 'none', cursor: 'pointer',
							borderBottom: topTab === t.key ? '2px solid #2563eb' : '2px solid transparent',
							color: topTab === t.key ? '#2563eb' : '#6b7280',
						} }
					>{ t.icon }{ t.label }</button>
				) ) }
			</div>
			{ topTab === 'notes' && <NotesDocPanel /> }
			{ topTab === 'files' && (
			<>
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Documents</h2>
					<p className="bzc-tabpane-subtitle">
						Kho tài liệu hợp nhất — upload + sinh tự động.
						{ ' ' }
						{ isFetching ? <em className="bzc-muted">— đang tải…</em> : <strong>({ total })</strong> }
					</p>
				</div>
				<Button variant="primary" onClick={ () => setUploadOpen( true ) }>
					<Upload size={ 12 } /> Tải lên
				</Button>
			</header>

			<div style={ { display: 'flex', gap: 6, flexWrap: 'wrap', margin: '8px 0 12px' } }>
				{ FILTERS.map( ( f ) => (
					<Button
						key={ f.key }
						size="sm"
						variant={ filter === f.key ? 'primary' : 'ghost' }
						onClick={ () => setFilter( f.key ) }
					>
						{ f.label }
					</Button>
				) ) }
				<div style={ { flex: 1, minWidth: 200 } }>
					<Input
						placeholder="Tìm kiếm tên file, URL…"
						value={ search }
						onChange={ ( e ) => setSearch( e.target.value ) }
					/>
				</div>
			</div>

			<DataTable columns={ cols } data={ docs } />

			<UploadSheet
				open={ uploadOpen }
				onOpenChange={ setUploadOpen }
				onUploaded={ () => { setUploadOpen( false ); refetch(); } }
			/>
		</>
		) }
		</div>
	);
}

function UploadSheet( { open, onOpenChange, onUploaded } ) {
	const fileRef = useRef( null );
	const [ title, setTitle ] = useState( '' );
	const [ notebookId, setNotebookId ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState( '' );
	const [ uploadDoc ] = useUploadBzDocumentMutation();

	const reset = () => {
		setTitle( '' );
		setNotebookId( '' );
		setErr( '' );
		setBusy( false );
		if ( fileRef.current ) { fileRef.current.value = ''; }
	};

	const submit = async ( e ) => {
		e.preventDefault();
		setErr( '' );
		const f = fileRef.current && fileRef.current.files && fileRef.current.files[ 0 ];
		if ( ! f ) { setErr( 'Hãy chọn file để tải lên.' ); return; }
		setBusy( true );
		try {
			await uploadDoc( {
				file: f,
				notebook_id: notebookId ? Number( notebookId ) : 0,
				title: title || f.name,
			} ).unwrap();
			reset();
			onUploaded && onUploaded();
		} catch ( ex ) {
			console.error( '[bzdoc] upload failed', ex );
			setErr( ( ex && ex.data && ex.data.message ) || 'Tải lên thất bại.' );
			setBusy( false );
		}
	};

	return (
		<Sheet open={ open } onOpenChange={ ( v ) => { if ( ! v ) { reset(); } onOpenChange( v ); } }>
			<SheetContent side="right" style={ { width: 420 } }>
				<SheetHeader>
					<SheetTitle>Tải lên tài liệu</SheetTitle>
					<SheetDescription>File sẽ lưu vào Media Library và đăng ký vào kho `bzdoc_documents`.</SheetDescription>
				</SheetHeader>
				<form onSubmit={ submit }>
					<SheetBody>
						<div style={ { display: 'flex', flexDirection: 'column', gap: 12 } }>
							<label style={ { display: 'flex', flexDirection: 'column', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>File</span>
								<input ref={ fileRef } type="file" />
							</label>
							<label style={ { display: 'flex', flexDirection: 'column', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>Tiêu đề (tuỳ chọn)</span>
								<Input value={ title } onChange={ ( e ) => setTitle( e.target.value ) } placeholder="Mặc định = tên file" />
							</label>
							<label style={ { display: 'flex', flexDirection: 'column', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>Notebook ID (tuỳ chọn)</span>
								<Input
									type="number"
									min="0"
									value={ notebookId }
									onChange={ ( e ) => setNotebookId( e.target.value ) }
									placeholder="Để trống nếu file độc lập"
								/>
							</label>
							{ err && <p style={ { color: 'var(--bzc-danger, #c00)', fontSize: 12, margin: 0 } }>{ err }</p> }
						</div>
					</SheetBody>
					<SheetFooter>
						<Button type="button" variant="ghost" onClick={ () => onOpenChange( false ) } disabled={ busy }>Huỷ</Button>
						<Button type="submit" variant="primary" disabled={ busy }>
							<Upload size={ 12 } /> { busy ? 'Đang tải…' : 'Tải lên' }
						</Button>
					</SheetFooter>
				</form>
			</SheetContent>
		</Sheet>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.44 B.2 — FolderTree helper: build tree from parent_id refs
function buildNoteTree( notes ) {
	const map = {};
	const roots = [];
	notes.forEach( function( n ) { map[ n.id ] = Object.assign( {}, n, { _children: [] } ); } );
	notes.forEach( function( n ) {
		if ( n.parent_id && map[ n.parent_id ] ) {
			map[ n.parent_id ]._children.push( map[ n.id ] );
		} else {
			roots.push( map[ n.id ] );
		}
	} );
	return roots;
}

// [2026-06-13 Johnny Chu] PHASE-0.44 B.2 — FolderTree node: expand/collapse + select
function FolderTreeNode( { node, depth, activeId, onSelect } ) {
	const [ expanded, setExpanded ] = React.useState( false );
	const hasKids = node._children && node._children.length > 0;
	const isActive = activeId === node.id;
	return (
		<div>
			<div
				style={ {
					paddingLeft: 8 + depth * 14, paddingTop: 4, paddingBottom: 4, paddingRight: 8,
					display: 'flex', alignItems: 'center', gap: 4, cursor: 'pointer', borderRadius: 5,
					background: isActive ? '#eff6ff' : 'transparent',
					color: isActive ? '#1d4ed8' : '#374151', fontSize: 12,
					fontWeight: isActive ? 600 : 400,
				} }
				onClick={ function() { onSelect( node.id ); } }
			>
				{ hasKids ? (
					<button
						type="button"
						style={ { background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: '#9ca3af', lineHeight: 1, flexShrink: 0 } }
						onClick={ function( e ) { e.stopPropagation(); setExpanded( function( v ) { return ! v; } ); } }
					>
						{ expanded ? '▾' : '▸' }
					</button>
				) : <span style={ { width: 10, flexShrink: 0 } } /> }
				<span style={ { overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>{ node.title }</span>
			</div>
			{ expanded && hasKids && node._children.map( function( child ) {
				return <FolderTreeNode key={ child.id } node={ child } depth={ depth + 1 } activeId={ activeId } onSelect={ onSelect } />;
			} ) }
		</div>
	);
}

// [2026-06-07 Johnny Chu] PHASE-0.40 G6-cleanup — NotesDocPanel: CRUD for crm_notes_doc
// [2026-06-13 Johnny Chu] PHASE-0.44 B.2 — reworked with FolderTree sidebar + parent_id
function NotesDocPanel() {
	const [ activeParentId, setActiveParentId ] = React.useState( null ); // null = root
	const [ editNote, setEditNote ] = useState( null );
	const [ formTitle, setFormTitle ] = useState( '' );
	const [ formContent, setFormContent ] = useState( '' );
	const [ formFolder, setFormFolder ] = useState( '' );
	const [ formPinned, setFormPinned ] = useState( false );
	const [ formParentId, setFormParentId ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	// Fetch all notes (limit 200) so we can build tree client-side
	const { data: allNotesRaw = [], isFetching, refetch } = useGetCrmNotesDocsQuery( { limit: 200 } );
	const allNotes = Array.isArray( allNotesRaw ) ? allNotesRaw : ( allNotesRaw.notes || [] );

	const tree = useMemo( function() { return buildNoteTree( allNotes ); }, [ allNotes ] );

	// Notes to display: children of activeParentId (or root-level if null)
	const visibleNotes = useMemo( function() {
		return allNotes.filter( function( n ) {
			return activeParentId === null ? ! n.parent_id : n.parent_id === activeParentId;
		} );
	}, [ allNotes, activeParentId ] );

	const [ createNote ] = useCreateCrmNoteDocMutation();
	const [ updateNote ] = useUpdateCrmNoteDocMutation();
	const [ deleteNote ] = useDeleteCrmNoteDocMutation();

	function openNew() {
		setFormTitle( '' ); setFormContent( '' ); setFormFolder( '' ); setFormPinned( false );
		setFormParentId( activeParentId );
		setEditNote( {} );
	}
	function openEdit( n ) {
		setFormTitle( n.title || '' ); setFormContent( n.content || '' );
		setFormFolder( n.folder || '' ); setFormPinned( !! n.pinned );
		setFormParentId( n.parent_id || null );
		setEditNote( n );
	}
	function handleSubmit( e ) {
		e.preventDefault();
		if ( ! formTitle.trim() ) { return; }
		setBusy( true );
		const payload = { title: formTitle.trim(), content: formContent, folder: formFolder, pinned: formPinned ? 1 : 0, parent_id: formParentId || null };
		const p = ( editNote && editNote.id ) ? updateNote( { id: editNote.id, ...payload } ) : createNote( payload );
		p.finally( function() { setBusy( false ); setEditNote( null ); refetch(); } );
	}
	function handleDelete( id ) {
		if ( ! window.confirm( 'Xoá ghi chú này?' ) ) { return; }
		deleteNote( id ).finally( function() { refetch(); } );
	}

	return (
		<div style={ { display: 'flex', gap: 0, minHeight: 400 } }>
			{ /* FolderTree sidebar */ }
			<div style={ { width: 200, flexShrink: 0, borderRight: '1px solid #e5e7eb', paddingRight: 8, paddingTop: 4 } }>
				<div style={ { fontSize: 11, fontWeight: 600, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: 1, marginBottom: 6, paddingLeft: 8 } }>Thư mục</div>
				{ /* Root item */ }
				<div
					style={ {
						paddingLeft: 8, paddingTop: 4, paddingBottom: 4, paddingRight: 8,
						cursor: 'pointer', borderRadius: 5, fontSize: 12,
						background: activeParentId === null ? '#eff6ff' : 'transparent',
						color: activeParentId === null ? '#1d4ed8' : '#374151',
						fontWeight: activeParentId === null ? 600 : 400,
					} }
					onClick={ function() { setActiveParentId( null ); } }
				>📁 Tất cả (gốc)</div>
				{ isFetching && <div style={ { fontSize: 11, color: '#9ca3af', padding: '4px 8px' } }>Đang tải…</div> }
				{ tree.map( function( node ) {
					return (
						<FolderTreeNode
							key={ node.id }
							node={ node }
							depth={ 0 }
							activeId={ activeParentId }
							onSelect={ setActiveParentId }
						/>
					);
				} ) }
			</div>

			{ /* Main area */ }
			<div style={ { flex: 1, minWidth: 0, paddingLeft: 16 } }>
				<div style={ { display: 'flex', alignItems: 'center', gap: 8, marginBottom: 14, flexWrap: 'wrap' } }>
					{ activeParentId !== null && (
						<span style={ { fontSize: 12, color: '#6b7280' } }>
							Ghi chú con của #{ activeParentId }
						</span>
					) }
					<button type="button" onClick={ openNew }
						style={ { marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 4, padding: '5px 12px', borderRadius: 6, fontSize: 12, background: '#2563eb', color: '#fff', border: 'none', cursor: 'pointer', fontWeight: 500 } }>
						<Plus size={ 13 } /> Ghi chú mới
					</button>
				</div>

				{ isFetching && <div style={ { color: '#6b7280', fontSize: 12, marginBottom: 8 } }>Đang tải…</div> }
				{ visibleNotes.length === 0 && ! isFetching && (
					<div style={ { color: '#9ca3af', fontSize: 12, padding: '24px 0', textAlign: 'center' } }>Chưa có ghi chú nào.</div>
				) }

				<div style={ { display: 'grid', gap: 8 } }>
					{ visibleNotes.map( function( n ) {
						const childCount = allNotes.filter( function( c ) { return c.parent_id === n.id; } ).length;
						return (
							<div key={ n.id } style={ { padding: '10px 14px', background: n.pinned ? '#fffbeb' : '#fff', border: '1px solid ' + ( n.pinned ? '#fbbf24' : '#e5e7eb' ), borderRadius: 8, display: 'flex', gap: 12, alignItems: 'flex-start' } }>
								<div style={ { flex: 1, minWidth: 0 } }>
									<div style={ { display: 'flex', alignItems: 'center', gap: 6, marginBottom: 2 } }>
										{ n.pinned ? <span style={ { fontSize: 11, color: '#d97706', fontWeight: 600 } }>📌</span> : null }
										<span style={ { fontWeight: 600, fontSize: 13, cursor: childCount > 0 ? 'pointer' : 'default', color: childCount > 0 ? '#2563eb' : '#111' } }
											onClick={ childCount > 0 ? function() { setActiveParentId( n.id ); } : undefined }
										>{ n.title }</span>
										{ n.folder ? <span style={ { fontSize: 10, color: '#6b7280', background: '#f3f4f6', padding: '1px 6px', borderRadius: 10 } }>{ n.folder }</span> : null }
										{ childCount > 0 && <span style={ { fontSize: 10, color: '#6b7280', background: '#dbeafe', padding: '1px 6px', borderRadius: 10 } }>{ childCount } ghi chú con</span> }
									</div>
									{ n.content ? <div style={ { fontSize: 12, color: '#4b5563', whiteSpace: 'pre-wrap', wordBreak: 'break-word', maxHeight: 80, overflow: 'hidden' } }>{ n.content }</div> : null }
								</div>
								<div style={ { display: 'flex', gap: 4, flexShrink: 0 } }>
									<button type="button" onClick={ function() { openEdit( n ); } }
										style={ { padding: '3px 8px', fontSize: 11, borderRadius: 5, border: '1px solid #d1d5db', background: '#f9fafb', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 3 } }>
										<Pencil size={ 11 } /> Sửa
									</button>
									<button type="button" onClick={ function() { handleDelete( n.id ); } }
										style={ { padding: '3px 8px', fontSize: 11, borderRadius: 5, border: '1px solid #fca5a5', background: '#fff', color: '#dc2626', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 3 } }>
										<Trash2 size={ 11 } /> Xoá
									</button>
								</div>
							</div>
						);
					} ) }
				</div>

				{ editNote !== null && (
					<div style={ { position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.3)', zIndex: 9999, display: 'flex', alignItems: 'center', justifyContent: 'center' } }
						onClick={ function( e ) { if ( e.target === e.currentTarget ) { setEditNote( null ); } } }>
						<form onSubmit={ handleSubmit } style={ { background: '#fff', borderRadius: 10, padding: 24, width: 480, maxWidth: '95vw', boxShadow: '0 8px 32px rgba(0,0,0,0.18)' } }>
							<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 } }>
								<span style={ { fontWeight: 700, fontSize: 15 } }>{ editNote.id ? 'Sửa ghi chú' : 'Ghi chú mới' }</span>
								<button type="button" onClick={ function() { setEditNote( null ); } } style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' } }><X size={ 16 } /></button>
							</div>
							<label style={ { display: 'block', marginBottom: 10 } }>
								<span style={ { fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 } }>Tiêu đề *</span>
								<input value={ formTitle } onChange={ function( e ) { setFormTitle( e.target.value ); } } required
									style={ { width: '100%', padding: '6px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, boxSizing: 'border-box' } } />
							</label>
							<label style={ { display: 'block', marginBottom: 10 } }>
								<span style={ { fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 } }>Nội dung</span>
								<textarea value={ formContent } onChange={ function( e ) { setFormContent( e.target.value ); } } rows={ 5 }
									style={ { width: '100%', padding: '6px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, boxSizing: 'border-box', resize: 'vertical' } } />
							</label>
							<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, marginBottom: 12 } }>
								<label>
									<span style={ { fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 } }>Folder (nhãn)</span>
									<input value={ formFolder } onChange={ function( e ) { setFormFolder( e.target.value ); } }
										placeholder="vd: inbox, drafts…"
										style={ { width: '100%', padding: '6px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, boxSizing: 'border-box' } } />
								</label>
								<label>
									{ /* [2026-06-13 Johnny Chu] PHASE-0.44 B.2 — parent note ID for hierarchy */ }
									<span style={ { fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 } }>Ghi chú cha (ID)</span>
									<input type="number" min="0" value={ formParentId || '' }
										onChange={ function( e ) { setFormParentId( e.target.value ? Number( e.target.value ) : null ); } }
										placeholder="Để trống = gốc"
										style={ { width: '100%', padding: '6px 10px', border: '1px solid #d1d5db', borderRadius: 6, fontSize: 13, boxSizing: 'border-box' } } />
								</label>
							</div>
							<label style={ { display: 'flex', alignItems: 'center', gap: 6, marginBottom: 16 } }>
								<input type="checkbox" checked={ formPinned } onChange={ function( e ) { setFormPinned( e.target.checked ); } } />
								<span style={ { fontSize: 12 } }>Ghim ghi chú này</span>
							</label>
							<div style={ { display: 'flex', justifyContent: 'flex-end', gap: 8 } }>
								<button type="button" onClick={ function() { setEditNote( null ); } } disabled={ busy }
									style={ { padding: '6px 16px', borderRadius: 6, border: '1px solid #d1d5db', background: '#fff', cursor: 'pointer', fontSize: 13 } }>Huỷ</button>
								<button type="submit" disabled={ busy || ! formTitle.trim() }
									style={ { padding: '6px 16px', borderRadius: 6, border: 'none', background: '#2563eb', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 500 } }>{ busy ? 'Đang lưu…' : 'Lưu' }</button>
							</div>
						</form>
					</div>
				) }
			</div>
		</div>
	);
}