import React, { useMemo, useState } from 'react';
import {
	flexRender,
	getCoreRowModel,
	getSortedRowModel,
	getFilteredRowModel,
	getPaginationRowModel,
	useReactTable,
} from '@tanstack/react-table';
import { ChevronDown, ChevronUp, ChevronsUpDown, Search } from 'lucide-react';
import { cn } from '../../lib/utils.js';
import { Input } from './input.jsx';
import { Button } from './button.jsx';

/**
 * DataTable — TanStack Table v8 wrapper.
 * Pattern ported from NextCRM `components/ui/data-table.tsx`.
 *
 * Props:
 *  - columns: ColumnDef<T>[]
 *  - data:    T[]
 *  - searchKey: column id used for the global search box (optional)
 *  - searchPlaceholder
 *  - emptyMessage
 *  - pageSize (default 10)
 *  - onRowClick(row.original)
 */
export function DataTable( {
	columns,
	data,
	searchKey,
	searchPlaceholder = 'Tìm kiếm…',
	emptyMessage = 'Không có dữ liệu.',
	pageSize = 10,
	onRowClick,
	className,
} ) {
	const [ sorting, setSorting ] = useState( [] );
	const [ globalFilter, setGlobalFilter ] = useState( '' );
	const [ pagination, setPagination ] = useState( { pageIndex: 0, pageSize } );

	const table = useReactTable( {
		data: data || [],
		columns,
		state: { sorting, globalFilter, pagination },
		onSortingChange: setSorting,
		onGlobalFilterChange: setGlobalFilter,
		onPaginationChange: setPagination,
		getCoreRowModel: getCoreRowModel(),
		getSortedRowModel: getSortedRowModel(),
		getFilteredRowModel: getFilteredRowModel(),
		getPaginationRowModel: getPaginationRowModel(),
		globalFilterFn: 'includesString',
	} );

	const total = table.getFilteredRowModel().rows.length;

	return (
		<div className={ cn( 'bzc-dt', className ) }>
			{ searchKey !== false && (
				<div className="bzc-dt-toolbar">
					<div className="bzc-dt-search">
						<Search size={ 14 } />
						<Input
							value={ globalFilter }
							onChange={ ( e ) => setGlobalFilter( e.target.value ) }
							placeholder={ searchPlaceholder }
						/>
					</div>
					<div className="bzc-dt-meta bzc-muted">{ total } dòng</div>
				</div>
			) }

			<div className="bzc-dt-scroll">
				<table className="bzc-table">
					<thead>
						{ table.getHeaderGroups().map( ( hg ) => (
							<tr key={ hg.id }>
								{ hg.headers.map( ( h ) => {
									const sortable = h.column.getCanSort();
									const sorted = h.column.getIsSorted();
									return (
										<th
											key={ h.id }
											style={ { width: h.getSize() === 150 ? undefined : h.getSize() } }
											onClick={ sortable ? h.column.getToggleSortingHandler() : undefined }
											className={ sortable ? 'bzc-dt-th-sort' : '' }
										>
											<span className="bzc-dt-th">
												{ flexRender( h.column.columnDef.header, h.getContext() ) }
												{ sortable && (
													sorted === 'asc'  ? <ChevronUp size={ 12 } /> :
													sorted === 'desc' ? <ChevronDown size={ 12 } /> :
													<ChevronsUpDown size={ 12 } className="bzc-dt-sort-idle" />
												) }
											</span>
										</th>
									);
								} ) }
							</tr>
						) ) }
					</thead>
					<tbody>
						{ table.getRowModel().rows.length ? table.getRowModel().rows.map( ( row ) => (
							<tr
								key={ row.id }
								className="bzc-row"
								onClick={ onRowClick ? () => onRowClick( row.original ) : undefined }
								style={ onRowClick ? { cursor: 'pointer' } : undefined }
							>
								{ row.getVisibleCells().map( ( cell ) => (
									<td key={ cell.id }>{ flexRender( cell.column.columnDef.cell, cell.getContext() ) }</td>
								) ) }
							</tr>
						) ) : (
							<tr><td colSpan={ columns.length } className="bzc-empty bzc-muted">{ emptyMessage }</td></tr>
						) }
					</tbody>
				</table>
			</div>

			{ table.getPageCount() > 1 && (
				<div className="bzc-dt-pagination">
					<span className="bzc-muted">
						Trang { table.getState().pagination.pageIndex + 1 } / { table.getPageCount() }
					</span>
					<div className="bzc-dt-pager">
						<Button size="sm" onClick={ () => table.previousPage() } disabled={ ! table.getCanPreviousPage() }>‹ Trước</Button>
						<Button size="sm" onClick={ () => table.nextPage() } disabled={ ! table.getCanNextPage() }>Sau ›</Button>
					</div>
				</div>
			) }
		</div>
	);
}

export default DataTable;
