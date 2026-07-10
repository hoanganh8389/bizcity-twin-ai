// [2026-06-13 Johnny Chu] PHASE-0.44 B.1 — TaskDetailDrawer + checklist (Deplao ERP parity)
import React, { useMemo, useState, useCallback } from 'react';
import { Plus, CheckSquare, Square, Trash2, ChevronRight, ChevronLeft, X } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Badge, Card, CardHeader, CardTitle, CardBody } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/tabs.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import {
	useGetCrmTasksQuery,
	useCreateCrmTaskMutation,
	useUpdateCrmTaskMutation,
	useDeleteCrmTaskMutation,
} from '../../redux/api/crmApi.js';

// [2026-06-13 Johnny Chu] PHASE-0.40 G6.2 — 5-column Kanban board (Deplao parity: open/in_progress/review/blocked/done)
const PRI_VARIANT = { high: 'danger', medium: 'warn', low: 'muted' };
const STATUSES = [ 'open', 'in_progress', 'review', 'blocked', 'done' ];
const STATUS_LABELS = { open: 'Mới', in_progress: 'Đang làm', review: 'Xét duyệt', blocked: 'Bị chặn', done: 'Hoàn thành' };
const STATUS_COLORS = { open: '#3b82f6', in_progress: '#f59e0b', review: '#8b5cf6', blocked: '#ef4444', done: '#10b981' };

// [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — helper to get current WP user id from BOOT
function currentUserId() {
	return ( window.BIZCITY_CRM_BOOT && window.BIZCITY_CRM_BOOT.currentUserId )
		? Number( window.BIZCITY_CRM_BOOT.currentUserId )
		: 0;
}

// [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — overdue check (due_date < today, not done)
function isOverdue( task ) {
	if ( task.status === 'done' || ! task.due_date ) { return false; }
	return task.due_date < new Date().toISOString().slice( 0, 10 );
}

function relatedLabel( t ) {
	if ( ! t.related_entity_type ) { return ''; }
	return `${ t.related_entity_type } #${ t.related_entity_id || '?' }`;
}

function TaskForm( { onSubmit, onCancel } ) {
	const [ data, setData ] = useState( { title: '', priority: 'medium', status: 'open', due_date: '', assignee_id: 0, related_entity_type: '', related_entity_id: '', notes: '' } );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( data ); } }>
			<div><Label>Tiêu đề</Label><Input value={ data.title } onChange={ ( e ) => setData( { ...data, title: e.target.value } ) } required autoFocus /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Ưu tiên</Label>
					<select className="bzc-input" value={ data.priority } onChange={ ( e ) => setData( { ...data, priority: e.target.value } ) }>
						{ [ 'low', 'medium', 'high' ].map( ( p ) => <option key={ p } value={ p }>{ p }</option> ) }
					</select>
				</div>
				<div><Label>Trạng thái</Label>
					<select className="bzc-input" value={ data.status } onChange={ ( e ) => setData( { ...data, status: e.target.value } ) }>
						{ STATUSES.map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
					</select>
				</div>
				<div><Label>Hạn</Label><Input type="date" value={ data.due_date } onChange={ ( e ) => setData( { ...data, due_date: e.target.value } ) } /></div>
				<div><Label>Phụ trách (user id)</Label><Input type="number" value={ data.assignee_id } onChange={ ( e ) => setData( { ...data, assignee_id: Number( e.target.value ) || 0 } ) } /></div>
				<div><Label>Liên kết (type)</Label><Input value={ data.related_entity_type } onChange={ ( e ) => setData( { ...data, related_entity_type: e.target.value } ) } placeholder="opportunity, account…" /></div>
				<div><Label>Liên kết (id)</Label><Input type="number" value={ data.related_entity_id } onChange={ ( e ) => setData( { ...data, related_entity_id: Number( e.target.value ) || '' } ) } /></div>
			</div>
			<div><Label>Ghi chú</Label><Textarea rows={ 4 } value={ data.notes } onChange={ ( e ) => setData( { ...data, notes: e.target.value } ) } /></div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel }>Huỷ</Button>
				<Button type="submit" variant="primary">Tạo task</Button>
			</div>
		</form>
	);
}

function TaskRow( { task, onToggle, onClick, onMove } ) {
	const stIdx = STATUSES.indexOf( task.status );
	return (
		<div className="bzc-task-row group">
			<button type="button" className="bzc-task-check" onClick={ () => onToggle( task ) } title="Đánh dấu hoàn thành">
				{ task.completed ? <CheckSquare size={ 16 } /> : <Square size={ 16 } /> }
			</button>
			<button type="button" className="bzc-task-body flex-1 min-w-0" onClick={ () => onClick( task ) }>
				<div className={ 'bzc-task-title ' + ( task.completed ? 'is-done' : '' ) }>{ task.title }</div>
				<div className="bzc-task-meta">
					<Badge variant={ PRI_VARIANT[ task.priority ] }>{ task.priority }</Badge>
					<span className="bzc-muted">user#{ task.assignee_id || '?' }</span>
					{ task.related_entity_type && <span className="bzc-muted">· { relatedLabel( task ) }</span> }
					{ task.due_date && <span className="bzc-muted">· hạn { task.due_date }</span> }
				</div>
			</button>
			{ /* [2026-06-13 Johnny Chu] PHASE-0.44 B.1 — inline move buttons */ }
			<div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0">
				{ stIdx > 0 && (
					<button type="button" title={ 'Lùi về ' + STATUS_LABELS[ STATUSES[ stIdx - 1 ] ] }
						className="p-1 text-slate-400 hover:text-indigo-500 hover:bg-indigo-50 rounded"
						onClick={ ( e ) => { e.stopPropagation(); onMove( task, STATUSES[ stIdx - 1 ] ); } }
					><ChevronLeft size={ 13 } /></button>
				) }
				{ stIdx < STATUSES.length - 1 && (
					<button type="button" title={ 'Chuyển sang ' + STATUS_LABELS[ STATUSES[ stIdx + 1 ] ] }
						className="p-1 text-slate-400 hover:text-indigo-500 hover:bg-indigo-50 rounded"
						onClick={ ( e ) => { e.stopPropagation(); onMove( task, STATUSES[ stIdx + 1 ] ); } }
					><ChevronRight size={ 13 } /></button>
				) }
			</div>
		</div>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.44 B.1 — TaskDetailDrawer: full inline edit + checklist
function TaskDetailDrawer( { task, onClose } ) {
	const [ updateTask ] = useUpdateCrmTaskMutation();
	const [ deleteTask ] = useDeleteCrmTaskMutation();

	// Editable field state — seeded from task
	const [ title,     setTitle ]     = useState( task.title     || '' );
	const [ status,    setStatus ]    = useState( task.status    || 'open' );
	const [ priority,  setPriority ]  = useState( task.priority  || 'medium' );
	const [ dueDate,   setDueDate ]   = useState( task.due_date  || '' );
	const [ notes,     setNotes ]     = useState( task.notes     || '' );
	const [ assignee,  setAssignee ]  = useState( task.assignee_id || '' );
	const [ saving, setSaving ] = useState( false );
	const [ deleting, setDeleting ] = useState( false );

	// Checklist — stored as JSON array of {id, text, done}
	const parseChecklist = ( raw ) => {
		if ( ! raw ) { return []; }
		try { return JSON.parse( raw ); } catch { return []; }
	};
	const [ items, setItems ] = useState( () => parseChecklist( task.checklist_json ) );
	const [ newItemText, setNewItemText ] = useState( '' );

	const doneCount = items.filter( ( i ) => i.done ).length;

	const addItem = () => {
		const t = newItemText.trim();
		if ( ! t ) { return; }
		setItems( ( prev ) => [ ...prev, { id: Date.now(), text: t, done: false } ] );
		setNewItemText( '' );
	};
	const toggleItem = ( id ) => setItems( ( prev ) => prev.map( ( i ) => i.id === id ? { ...i, done: ! i.done } : i ) );
	const removeItem = ( id ) => setItems( ( prev ) => prev.filter( ( i ) => i.id !== id ) );

	const save = useCallback( async () => {
		setSaving( true );
		try {
			await updateTask( {
				id: task.id, title, status, priority,
				due_date: dueDate || null,
				notes, assignee_id: assignee ? Number( assignee ) : null,
				checklist_json: JSON.stringify( items ),
				completed: status === 'done',
			} ).unwrap();
			onClose();
		} catch ( err ) {
			console.error( '[bizcity-crm] update task failed', err );
			setSaving( false );
		}
	}, [ task.id, title, status, priority, dueDate, notes, assignee, items, updateTask, onClose ] );

	const handleDelete = async () => {
		if ( ! window.confirm( 'Xoá task này?' ) ) { return; }
		setDeleting( true );
		try { await deleteTask( task.id ).unwrap(); onClose(); } catch { setDeleting( false ); }
	};

	return (
		<Sheet open onOpenChange={ ( v ) => { if ( ! v ) { onClose(); } } }>
			<SheetContent className="w-[480px] max-w-full">
				<SheetHeader>
					<SheetTitle className="flex items-center justify-between gap-2">
						<input
							className="flex-1 text-base font-semibold bg-transparent border-none outline-none focus:ring-1 focus:ring-indigo-400 rounded px-1 -ml-1"
							value={ title } onChange={ ( e ) => setTitle( e.target.value ) }
							placeholder="Tiêu đề task…"
						/>
					</SheetTitle>
				</SheetHeader>
				<SheetBody className="flex flex-col gap-4 overflow-y-auto">

					{ /* Status + Priority row */ }
					<div className="grid grid-cols-2 gap-3">
						<div>
							<Label className="text-xs">Trạng thái</Label>
							<select className="bzc-input mt-1 text-sm" value={ status } onChange={ ( e ) => setStatus( e.target.value ) }>
								{ STATUSES.map( ( s ) => <option key={ s } value={ s }>{ STATUS_LABELS[ s ] }</option> ) }
							</select>
						</div>
						<div>
							<Label className="text-xs">Ưu tiên</Label>
							<select className="bzc-input mt-1 text-sm" value={ priority } onChange={ ( e ) => setPriority( e.target.value ) }>
								{ [ 'low', 'medium', 'high' ].map( ( p ) => <option key={ p } value={ p }>{ p }</option> ) }
							</select>
						</div>
						<div>
							<Label className="text-xs">Hạn hoàn thành</Label>
							<Input type="date" className="mt-1 text-sm" value={ dueDate } onChange={ ( e ) => setDueDate( e.target.value ) } />
						</div>
						<div>
							<Label className="text-xs">Phụ trách (User ID)</Label>
							<Input type="number" className="mt-1 text-sm" value={ assignee } onChange={ ( e ) => setAssignee( e.target.value ) } placeholder="0" />
						</div>
					</div>

					{ /* Notes */ }
					<div>
						<Label className="text-xs">Ghi chú</Label>
						<Textarea rows={ 3 } className="mt-1 text-sm" value={ notes } onChange={ ( e ) => setNotes( e.target.value ) } placeholder="Mô tả / ghi chú…" />
					</div>

					{ /* Checklist */ }
					<div>
						<div className="flex items-center justify-between mb-1">
							<Label className="text-xs">Checklist { items.length > 0 && <span className="text-slate-400 font-normal">({ doneCount }/{ items.length })</span> }</Label>
						</div>
						{ items.length > 0 && (
							<div className="mb-2 h-1.5 rounded-full bg-slate-200 overflow-hidden">
								<div className="h-full bg-emerald-400 rounded-full transition-all" style={ { width: ( items.length ? Math.round( doneCount / items.length * 100 ) : 0 ) + '%' } } />
							</div>
						) }
						<ul className="flex flex-col gap-1 mb-2">
							{ items.map( ( item ) => (
								<li key={ item.id } className="flex items-center gap-2 group/item">
									<button type="button" onClick={ () => toggleItem( item.id ) } className="flex-shrink-0 text-slate-400 hover:text-emerald-500">
										{ item.done ? <CheckSquare size={ 14 } className="text-emerald-500" /> : <Square size={ 14 } /> }
									</button>
									<span className={ 'flex-1 text-sm ' + ( item.done ? 'line-through text-slate-400' : '' ) }>{ item.text }</span>
									<button type="button" onClick={ () => removeItem( item.id ) } className="opacity-0 group-hover/item:opacity-100 text-slate-300 hover:text-red-400">
										<X size={ 12 } />
									</button>
								</li>
							) ) }
						</ul>
						<div className="flex gap-2">
							<Input
								className="flex-1 text-sm"
								value={ newItemText }
								onChange={ ( e ) => setNewItemText( e.target.value ) }
								onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); addItem(); } } }
								placeholder="Thêm mục checklist… (Enter)"
							/>
							<Button size="sm" type="button" variant="outline" onClick={ addItem }>+</Button>
						</div>
					</div>

					{ /* Footer */ }
					<div className="flex items-center justify-between pt-2 border-t mt-auto">
						<Button size="sm" variant="ghost" className="text-red-400 hover:text-red-600" disabled={ deleting } onClick={ handleDelete }>
							<Trash2 size={ 13 } className="mr-1" /> { deleting ? 'Đang xoá…' : 'Xoá task' }
						</Button>
						<div className="flex gap-2">
							<Button size="sm" variant="outline" onClick={ onClose }>Huỷ</Button>
							<Button size="sm" variant="primary" disabled={ saving } onClick={ save }>
								{ saving ? 'Đang lưu…' : 'Lưu' }
							</Button>
						</div>
					</div>
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

export default function TasksTab() {
	const { data: tasks = [], isFetching } = useGetCrmTasksQuery();
	const [ createTask, { isLoading: creating } ] = useCreateCrmTaskMutation();
	const [ updateTask ] = useUpdateCrmTaskMutation();
	const [ formOpen, setFormOpen ] = useState( false );
	const [ detail, setDetail ] = useState( null );

	const grouped = useMemo( () => {
		// [2026-06-07 Johnny Chu] PHASE-0.40 G6.2 — 5 statuses
		const out = { open: [], in_progress: [], review: [], blocked: [], done: [] };
		tasks.forEach( ( t ) => { ( out[ t.status ] || ( out[ t.status ] = [] ) ).push( t ); } );
		return out;
	}, [ tasks ] );

	// [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — My Tasks + Overdue filters
	const myUid = currentUserId();
	const myTasks   = useMemo( () => tasks.filter( ( t ) => myUid && t.assignee_id === myUid && t.status !== 'done' ), [ tasks, myUid ] );
	const overdueTasks = useMemo( () => tasks.filter( isOverdue ), [ tasks ] );

	const toggle = async ( task ) => {
		const nextCompleted = ! task.completed;
		try {
			await updateTask( {
				id: task.id,
				completed: nextCompleted,
				status: nextCompleted ? 'done' : 'open',
			} ).unwrap();
		} catch ( err ) {
			console.error( '[bizcity-crm] toggle task failed', err );
		}
	};

	// [2026-06-13 Johnny Chu] PHASE-0.44 B.1 — inline column move
	const moveTask = async ( task, newStatus ) => {
		try {
			await updateTask( { id: task.id, status: newStatus, completed: newStatus === 'done' } ).unwrap();
		} catch ( err ) {
			console.error( '[bizcity-crm] move task failed', err );
		}
	};

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Tasks</h2>
					<p className="bzc-tabpane-subtitle">Việc cần làm — board theo trạng thái. { isFetching ? '— đang tải…' : `(${ tasks.length })` }</p>
				</div>
				<Button variant="primary" onClick={ () => setFormOpen( true ) }><Plus size={ 12 } /> Task mới</Button>
			</header>

			<Tabs defaultValue="board">
				<TabsList>
					<TabsTrigger value="board">Board</TabsTrigger>
					<TabsTrigger value="list">Danh sách</TabsTrigger>
					{/* [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — My Tasks + Overdue */}
					<TabsTrigger value="mine">Việc của tôi { myTasks.length > 0 && <Badge variant="warn" style={ { marginLeft: 4 } }>{ myTasks.length }</Badge> }</TabsTrigger>
					<TabsTrigger value="overdue">Quá hạn { overdueTasks.length > 0 && <Badge variant="danger" style={ { marginLeft: 4 } }>{ overdueTasks.length }</Badge> }</TabsTrigger>
				</TabsList>

				<TabsContent value="board">
					<div className="bzc-task-board">
						{ STATUSES.map( ( st ) => (
							<Card key={ st }>
								<CardHeader><CardTitle style={ { color: STATUS_COLORS[ st ] } }>{ STATUS_LABELS[ st ] || st } ({ ( grouped[ st ] || [] ).length })</CardTitle></CardHeader>
								<CardBody>
									<div className="bzc-task-list">
										{ ( grouped[ st ] || [] ).map( ( t ) => (
											<TaskRow key={ t.id } task={ t } onToggle={ toggle } onClick={ setDetail } onMove={ moveTask } />
										) ) }
										{ ! grouped[ st ]?.length && <div className="bzc-empty bzc-muted">Trống</div> }
									</div>
								</CardBody>
							</Card>
						) ) }
					</div>
				</TabsContent>

				<TabsContent value="list">
					<div className="bzc-task-list">
						{ tasks.map( ( t ) => <TaskRow key={ t.id } task={ t } onToggle={ toggle } onClick={ setDetail } onMove={ moveTask } /> ) }
					</div>
				</TabsContent>

				{/* [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — Việc của tôi */}
				<TabsContent value="mine">
					<div className="bzc-task-list">
						{ myTasks.length > 0
							? myTasks.map( ( t ) => <TaskRow key={ t.id } task={ t } onToggle={ toggle } onClick={ setDetail } onMove={ moveTask } /> )
							: <div className="bzc-empty bzc-muted">{ myUid ? 'Bạn không có việc nào đang mở.' : 'Không xác định được user hiện tại.' }</div>
						}
					</div>
				</TabsContent>

				{/* [2026-06-13 Johnny Chu] PHASE-0.44 C.3 — Quá hạn */}
				<TabsContent value="overdue">
					<div className="bzc-task-list">
						{ overdueTasks.length > 0
							? overdueTasks.map( ( t ) => <TaskRow key={ t.id } task={ t } onToggle={ toggle } onClick={ setDetail } onMove={ moveTask } /> )
							: <div className="bzc-empty bzc-muted">Không có task quá hạn.</div>
						}
					</div>
				</TabsContent>
			</Tabs>

			{ /* Create task sheet */ }
			<Sheet open={ formOpen } onOpenChange={ setFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Task mới</SheetTitle></SheetHeader>
					<SheetBody>
						<TaskForm
							onCancel={ () => setFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createTask( data ).unwrap();
									setFormOpen( false );
								} catch ( err ) {
									console.error( '[bizcity-crm] create task failed', err );
									alert( 'Lưu task thất bại. Xem console.' );
								}
							} }
						/>
						{ creating && <p className="bzc-muted bzc-mt-sm">Đang lưu…</p> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			{ /* [2026-06-13 Johnny Chu] PHASE-0.44 B.1 — TaskDetailDrawer replaces simple kv sheet */ }
			{ detail && <TaskDetailDrawer task={ detail } onClose={ () => setDetail( null ) } /> }
		</div>
	);
}
