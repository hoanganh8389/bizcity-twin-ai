/**
 * Labels — full CRUD using shadcn primitives.
 *
 * Backend (M3.W1):
 *   GET    /labels                  -> { labels:[…], count }
 *   POST   /labels                  body: { title, color, description?, show_on_sidebar? }
 *   PUT    /labels/:id              body: same
 *   DELETE /labels/:id
 *
 * Field name BẮT BUỘC dùng `title` — KHÔNG dùng `name` (BE sẽ trả 422 invalid_title).
 */
import React, { useState } from 'react';
import { Plus, Pencil, Trash2, Tag } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label as FieldLabel } from '../../components/ui/input.jsx';
import {
	useGetLabelsQuery,
	useCreateLabelMutation,
	useUpdateLabelMutation,
	useDeleteLabelMutation,
} from '../../redux/api/crmApi.js';

const PALETTE = [
	'#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316',
	'#f59e0b', '#10b981', '#14b8a6', '#0ea5e9', '#3b82f6',
	'#64748b', '#111827',
];

function emptyForm() {
	return { title: '', color: PALETTE[ 0 ], description: '', show_on_sidebar: true };
}

function LabelForm( { initial, busy, onSubmit, onCancel } ) {
	const [ data, setData ] = useState( () => ( initial ? {
		title:           initial.title || '',
		color:           initial.color || PALETTE[ 0 ],
		description:     initial.description || '',
		show_on_sidebar: initial.show_on_sidebar !== false,
	} : emptyForm() ) );
	const [ err, setErr ] = useState( '' );

	const set = ( patch ) => setData( ( d ) => ( { ...d, ...patch } ) );

	const submit = async ( e ) => {
		e.preventDefault();
		const title = ( data.title || '' ).trim();
		if ( ! title ) { setErr( 'Tên label không được để trống.' ); return; }
		setErr( '' );
		try {
			await onSubmit( { ...data, title } );
		} catch ( ex ) {
			setErr( ex?.data?.message || ex?.error || 'Lưu thất bại. Xem console.' );
			// eslint-disable-next-line no-console
			console.error( '[bizcity-crm] label submit failed', ex );
		}
	};

	return (
		<form onSubmit={ submit } className="flex flex-col gap-4">
			<div>
				<FieldLabel>Tên label</FieldLabel>
				<Input
					autoFocus
					value={ data.title }
					onChange={ ( e ) => set( { title: e.target.value } ) }
					placeholder="VD: VIP, Khiếu nại, Theo dõi…"
				/>
			</div>
			<div>
				<FieldLabel>Mô tả (tuỳ chọn)</FieldLabel>
				<Textarea
					rows={ 2 }
					value={ data.description }
					onChange={ ( e ) => set( { description: e.target.value } ) }
					placeholder="Mô tả ngắn cho team."
				/>
			</div>
			<div>
				<FieldLabel>Màu</FieldLabel>
				<div className="flex flex-wrap gap-2">
					{ PALETTE.map( ( c ) => {
						const on = c === data.color;
						return (
							<button
								key={ c }
								type="button"
								title={ c }
								onClick={ () => set( { color: c } ) }
								className={
									'w-7 h-7 rounded-full border-2 transition-transform ' +
									( on ? 'border-gray-900 scale-110 ring-2 ring-offset-1 ring-gray-300' : 'border-transparent hover:scale-105' )
								}
								style={ { background: c } }
							/>
						);
					} ) }
					<label className="flex items-center gap-2 ml-3 text-xs text-gray-600">
						<span>Custom:</span>
						<input
							type="color"
							value={ data.color }
							onChange={ ( e ) => set( { color: e.target.value } ) }
							className="w-7 h-7 p-0 border border-gray-200 rounded cursor-pointer"
						/>
					</label>
				</div>
			</div>
			<label className="flex items-center gap-2 text-sm text-gray-700">
				<input
					type="checkbox"
					checked={ !! data.show_on_sidebar }
					onChange={ ( e ) => set( { show_on_sidebar: e.target.checked } ) }
				/>
				Hiện ngoài sidebar (Inbox)
			</label>

			<div className="mt-3 flex items-center gap-3">
				<span
					className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
					style={ { background: data.color, color: '#fff' } }
				>
					<Tag size={ 12 } /> { data.title || 'Preview' }
				</span>
				<span className="text-xs text-gray-400">— preview</span>
			</div>

			{ err && <div className="text-sm text-red-600">{ err }</div> }

			<div className="flex justify-end gap-2 pt-2 border-t">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>
					{ busy ? 'Đang lưu…' : ( initial ? 'Cập nhật' : 'Tạo label' ) }
				</Button>
			</div>
		</form>
	);
}

export default function LabelsTab() {
	const { data: labels = [], isFetching, refetch } = useGetLabelsQuery();
	const [ createLabel, { isLoading: creating } ] = useCreateLabelMutation();
	const [ updateLabel, { isLoading: updating } ] = useUpdateLabelMutation();
	const [ deleteLabel ]                          = useDeleteLabelMutation();
	const [ sheetOpen, setSheetOpen ] = useState( false );
	const [ editing,   setEditing   ] = useState( null );

	const openNew  = () => { setEditing( null ); setSheetOpen( true ); };
	const openEdit = ( l ) => { setEditing( l ); setSheetOpen( true ); };
	const close    = () => { setSheetOpen( false ); setEditing( null ); };

	const onSubmit = async ( body ) => {
		if ( editing?.id ) {
			await updateLabel( { id: editing.id, ...body } ).unwrap();
		} else {
			await createLabel( body ).unwrap();
		}
		close();
		refetch();
	};

	const onDelete = async ( l ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( `Xoá label "${ l.title }"? (Các hội thoại đang gán sẽ bị bỏ liên kết)` ) ) { return; }
		try { await deleteLabel( { id: l.id } ).unwrap(); }
		catch ( ex ) {
			// eslint-disable-next-line no-console
			console.error( '[bizcity-crm] delete label failed', ex );
			alert( 'Xoá thất bại. Xem console.' );
		}
	};

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Labels</h2>
					<p className="bzc-tabpane-subtitle">
						Nhãn dán hội thoại — dùng để phân loại Inbox.
						{ isFetching ? ' — đang tải…' : ` (${ labels.length } nhãn)` }
					</p>
				</div>
				<Button variant="primary" onClick={ openNew }>
					<Plus size={ 14 } /> Tạo label
				</Button>
			</header>

			{ ! labels.length && ! isFetching && (
				<div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
					<Tag size={ 32 } className="mx-auto text-gray-400" />
					<p className="mt-3 text-sm text-gray-600">Chưa có label nào.</p>
					<p className="text-xs text-gray-400 mb-4">Tạo label để bắt đầu phân loại hội thoại.</p>
					<Button variant="primary" onClick={ openNew }><Plus size={ 14 } /> Tạo label đầu tiên</Button>
				</div>
			) }

			{ !! labels.length && (
				<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
					{ labels.map( ( l ) => (
						<div
							key={ l.id }
							className="group rounded-lg border border-gray-200 bg-white p-3 hover:border-gray-400 transition-colors flex flex-col gap-2"
						>
							<div className="flex items-center justify-between gap-2">
								<span
									className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold max-w-full truncate"
									style={ { background: l.color || '#64748b', color: '#fff' } }
									title={ l.title }
								>
									<Tag size={ 12 } /> { l.title }
								</span>
								<div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
									<button
										type="button"
										onClick={ () => openEdit( l ) }
										className="p-1.5 rounded hover:bg-gray-100 text-gray-500 hover:text-gray-800"
										title="Sửa"
									>
										<Pencil size={ 14 } />
									</button>
									<button
										type="button"
										onClick={ () => onDelete( l ) }
										className="p-1.5 rounded hover:bg-red-50 text-gray-500 hover:text-red-600"
										title="Xoá"
									>
										<Trash2 size={ 14 } />
									</button>
								</div>
							</div>
							{ l.description ? (
								<p className="text-xs text-gray-500 line-clamp-2">{ l.description }</p>
							) : (
								<p className="text-xs text-gray-300 italic">— không có mô tả —</p>
							) }
							<div className="text-[10px] text-gray-400 mt-auto">
								{ l.show_on_sidebar !== false ? 'Hiện ở sidebar' : 'Ẩn khỏi sidebar' }
								{ l.color && <> · <span style={ { color: l.color } }>{ l.color }</span></> }
							</div>
						</div>
					) ) }
				</div>
			) }

			<Sheet open={ sheetOpen } onOpenChange={ ( v ) => ( v ? setSheetOpen( true ) : close() ) }>
				<SheetContent>
					<SheetHeader>
						<SheetTitle>{ editing ? `Sửa label · ${ editing.title }` : 'Tạo label mới' }</SheetTitle>
					</SheetHeader>
					<SheetBody>
						<LabelForm
							key={ editing?.id || 'new' }
							initial={ editing }
							busy={ creating || updating }
							onCancel={ close }
							onSubmit={ onSubmit }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}
