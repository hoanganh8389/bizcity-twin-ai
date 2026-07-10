/**
 * M-CRM.M5 — Sales Pipeline tab (Kanban + Leads + Opportunities + Contracts).
 *
 * Wired live to `/bizcity-crm/v1/crm-leads|crm-opportunities|crm-contracts`.
 * Drag-and-drop on PipelineBoard fires `updateCrmOpportunity({ id, stage })`.
 *
 * @since 2026-05-25 (BE done M-CRM.M1; FE switched from mock fixtures).
 */
import React, { useMemo, useState, useEffect, useCallback } from 'react';
import { Plus, GripVertical, TrendingUp, Loader2, Search, X, ChevronLeft, ChevronRight, Download, Activity, Receipt, FileCheck } from 'lucide-react';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/tabs.jsx';
import { Button } from '../../components/ui/button.jsx';
import { Badge, Card, CardHeader, CardTitle, CardBody } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Textarea, Label } from '../../components/ui/input.jsx';
import { DataTable } from '../../components/ui/data-table.jsx';
import AuditTimeline from '../../components/audit/AuditTimeline.jsx';
import ActivityFeed from '../../components/activity/ActivityFeed.jsx';
import { formatMoney } from '../../lib/utils.js';
// [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — removed MOCK_ACTIVITIES
import {
	useGetCrmLeadsQuery,
	useCreateCrmLeadMutation,
	useGetCrmOpportunitiesQuery,
	useCreateCrmOpportunityMutation,
	useUpdateCrmOpportunityMutation,
	useGetCrmContractsQuery,
	useCreateCrmContractMutation,
	useGetEntityAuditLogQuery,
} from '../../redux/api/crmApi.js';

/* Stages must mirror BE accepted enum (see post_crm_opportunity defaults). */
const SALES_STAGES = [
	{ id: 'prospecting',   label: 'Prospecting',   color: '#94a3b8' },
	{ id: 'qualification', label: 'Qualification', color: '#0ea5e9' },
	{ id: 'proposal',      label: 'Proposal',      color: '#f59e0b' },
	{ id: 'negotiation',   label: 'Negotiation',   color: '#8b5cf6' },
	{ id: 'closed_won',    label: 'Closed Won',    color: '#22c55e' },
	{ id: 'closed_lost',   label: 'Closed Lost',   color: '#ef4444' },
];

function StatusBadge( { value } ) {
	const map = { new: 'default', contacted: 'warn', qualified: 'ok', unqualified: 'danger', converted: 'ok', lost: 'muted' };
	return <Badge variant={ map[ value ] || 'default' }>{ value || '—' }</Badge>;
}

function ContractStatusBadge( { value } ) {
	const map = { active: 'ok', draft: 'default', expired: 'muted', cancelled: 'danger', renewed: 'ok' };
	return <Badge variant={ map[ value ] || 'default' }>{ value || '—' }</Badge>;
}

/* ─────────────────── Channel badge (inline — refs CH maps defined below) ─────────────────── */

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — inline channel badge on kanban cards + table cells
function ChannelBadge( { source } ) {
	if ( ! source ) { return null; }
	const color = CH_COLOR_MAP[ source ] || '#64748b';
	const short = CH_SHORT_MAP[ source ] || source.toUpperCase().slice( 0, 3 );
	return (
		<span className="bzc-ch-inline" style={ { background: color + '22', color, borderColor: color + '55' } }>
			{ short }
		</span>
	);
}

/* ─────────────────── Searchable + paginated table wrapper ─────────────────── */

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — search by name/phone/email + pagination + CSV export button
function SearchableTable( { columns, data, onRowClick, pageSize = 50, onExport, exportLabel } ) {
	const [ q, setQ ]       = useState( '' );
	const [ page, setPage ] = useState( 1 );
	useEffect( () => { setPage( 1 ); }, [ q ] );

	const filtered = useMemo( () => {
		const raw = q.trim().toLowerCase();
		if ( ! raw ) { return data; }
		return data.filter( ( r ) =>
			String( r.name || '' ).toLowerCase().includes( raw ) ||
			String( r.phone || '' ).toLowerCase().includes( raw ) ||
			String( r.email || '' ).toLowerCase().includes( raw ) ||
			String( r.company || '' ).toLowerCase().includes( raw ) ||
			String( r.title || '' ).toLowerCase().includes( raw )
		);
	}, [ data, q ] );

	const totalPages = Math.max( 1, Math.ceil( filtered.length / pageSize ) );
	const safePage   = Math.min( page, totalPages );
	const pageData   = filtered.slice( ( safePage - 1 ) * pageSize, safePage * pageSize );

	return (
		<div>
			<div className="bzc-table-toolbar">
				<div className="bzc-search-input-wrap">
					<Search size={ 13 } className="bzc-search-icon" />
					<input
						className="bzc-search-input"
						placeholder="Tìm tên, SĐT, email…"
						value={ q }
						onChange={ ( e ) => setQ( e.target.value ) }
					/>
					{ q && (
						<button className="bzc-search-clear" onClick={ () => setQ( '' ) } type="button">
							<X size={ 11 } />
						</button>
					) }
				</div>
				<span className="bzc-muted bzc-table-count">{ filtered.length } / { data.length }</span>
				{ onExport && (
					<button className="bzc-export-inline-btn" onClick={ onExport } type="button">
						<Download size={ 12 } /> { exportLabel || 'Xuất CSV' }
					</button>
				) }
			</div>
			<DataTable columns={ columns } data={ pageData } onRowClick={ onRowClick } />
			{ totalPages > 1 && (
				<div className="bzc-pagination">
					<button className="bzc-page-btn" disabled={ safePage <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
						<ChevronLeft size={ 14 } />
					</button>
					<span className="bzc-page-info">Trang { safePage } / { totalPages }</span>
					<button className="bzc-page-btn" disabled={ safePage >= totalPages } onClick={ () => setPage( ( p ) => p + 1 ) }>
						<ChevronRight size={ 14 } />
					</button>
				</div>
			) }
		</div>
	);
}

/* ─────────────────── Pipeline (Kanban) ─────────────────── */

function PipelineBoard( { opps, onSelect, onStageChange, isUpdating, onQuickAction } ) {
	// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — loading overlay + channel badge + quick action buttons
	const [ dragId, setDragId ] = useState( null );
	const [ dragOverStage, setDragOverStage ] = useState( null );

	const grouped = useMemo( () => {
		const map = Object.fromEntries( SALES_STAGES.map( ( s ) => [ s.id, [] ] ) );
		opps.forEach( ( o ) => { ( map[ o.stage ] || ( map[ o.stage ] = [] ) ).push( o ); } );
		return map;
	}, [ opps ] );

	const totalsByStage = useMemo( () => {
		const out = {};
		SALES_STAGES.forEach( ( s ) => {
			out[ s.id ] = ( grouped[ s.id ] || [] ).reduce( ( a, o ) => a + Number( o.amount || 0 ), 0 );
		} );
		return out;
	}, [ grouped ] );

	const onDrop = ( stageId ) => ( e ) => {
		e.preventDefault();
		setDragOverStage( null );
		if ( ! dragId ) { return; }
		const opp = opps.find( ( o ) => o.id === dragId );
		setDragId( null );
		if ( ! opp || opp.stage === stageId ) { return; }
		onStageChange( opp, stageId );
	};

	return (
		<div style={ { position: 'relative' } }>
			{ isUpdating && (
				<div className="bzc-kanban-overlay">
					<Loader2 size={ 22 } className="bzc-spin" />
					<span>Lưu thay đổi…</span>
				</div>
			) }
			<div className="bzc-kanban">
				{ SALES_STAGES.map( ( stage ) => {
					const isOver       = dragOverStage === stage.id && dragId;
					const sourceStage  = dragId ? ( opps.find( ( o ) => o.id === dragId )?.stage ) : null;
					const isSelfTarget = sourceStage === stage.id;
					return (
					<div
						key={ stage.id }
						className={ 'bzc-kanban-col' + ( isOver && ! isSelfTarget ? ' bzc-kanban-col--drop' : '' ) }
						onDragOver={ ( e ) => { e.preventDefault(); if ( dragOverStage !== stage.id ) { setDragOverStage( stage.id ); } } }
						onDragLeave={ () => { if ( dragOverStage === stage.id ) { setDragOverStage( null ); } } }
						onDrop={ onDrop( stage.id ) }
					>
						<div className="bzc-kanban-col-head" style={ { borderTopColor: stage.color } }>
							<strong>{ stage.label }</strong>
							<span className="bzc-muted">{ ( grouped[ stage.id ] || [] ).length } · { formatMoney( totalsByStage[ stage.id ] || 0 ) }</span>
						</div>
						<div className="bzc-kanban-col-body">
							{ ( grouped[ stage.id ] || [] ).map( ( o ) => (
								<div
									key={ o.id }
									className={ 'bzc-kanban-card' + ( dragId === o.id ? ' bzc-kanban-card--dragging' : '' ) }
									draggable={ ! isUpdating }
									onDragStart={ () => setDragId( o.id ) }
									onDragEnd={ () => { setDragId( null ); setDragOverStage( null ); } }
									onClick={ () => onSelect( o ) }
								>
									<div className="bzc-kanban-card-head">
										<span className="bzc-kanban-card-title">{ o.name || `#${ o.id }` }</span>
										<ChannelBadge source={ o.source } />
									</div>
									{ ( o.phone || o.email ) && (
										<div className="bzc-kanban-card-contact bzc-muted">{ o.phone || o.email }</div>
									) }
									<div className="bzc-kanban-card-amount">{ formatMoney( o.amount, o.currency ) }</div>
									<div className="bzc-kanban-card-foot">
										<Badge variant="muted">{ o.probability ?? 0 }%</Badge>
										<span className="bzc-muted">{ o.close_date || '—' }</span>
									</div>
									<div className="bzc-kanban-card-actions" onClick={ ( e ) => e.stopPropagation() }>
										<button className="bzc-card-action-btn" title="Ghi activity" onClick={ () => onQuickAction( 'activity', o ) }><Activity size={ 11 } /></button>
										<button className="bzc-card-action-btn" title="Hoá đơn" onClick={ () => onQuickAction( 'invoice', o ) }><Receipt size={ 11 } /></button>
										<button className="bzc-card-action-btn" title="Hợp đồng" onClick={ () => onQuickAction( 'contract', o ) }><FileCheck size={ 11 } /></button>
									</div>
								</div>
							) ) }
							{ ! grouped[ stage.id ]?.length && <div className="bzc-kanban-empty bzc-muted">Trống</div> }
						</div>
					</div>
					);
				} ) }
			</div>
		</div>
	);
}

/* ─────────────────── Lead form (Sheet) ─────────────────── */

function LeadForm( { onSubmit, onCancel, submitting } ) {
	const [ data, setData ] = useState( {
		first_name: '', last_name: '', company: '', email: '', phone: '',
		source: 'web', status: 'new', rating: 50, notes: '',
	} );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( data ); } }>
			<div className="bzc-form-grid-2">
				<div><Label>Họ</Label><Input value={ data.last_name } onChange={ ( e ) => setData( { ...data, last_name: e.target.value } ) } /></div>
				<div><Label>Tên</Label><Input value={ data.first_name } onChange={ ( e ) => setData( { ...data, first_name: e.target.value } ) } autoFocus /></div>
			</div>
			<div><Label>Công ty</Label><Input value={ data.company } onChange={ ( e ) => setData( { ...data, company: e.target.value } ) } /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Email</Label><Input type="email" value={ data.email } onChange={ ( e ) => setData( { ...data, email: e.target.value } ) } /></div>
				<div><Label>Phone</Label><Input value={ data.phone } onChange={ ( e ) => setData( { ...data, phone: e.target.value } ) } /></div>
			</div>
			<div className="bzc-form-grid-2">
				<div><Label>Nguồn</Label>
					<select className="bzc-input" value={ data.source } onChange={ ( e ) => setData( { ...data, source: e.target.value } ) }>
						{ [ 'web', 'facebook', 'zalo', 'referral', 'other' ].map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
					</select>
				</div>
				<div><Label>Trạng thái</Label>
					<select className="bzc-input" value={ data.status } onChange={ ( e ) => setData( { ...data, status: e.target.value } ) }>
						{ [ 'new', 'contacted', 'qualified', 'unqualified', 'converted', 'lost' ].map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
					</select>
				</div>
			</div>
			<div><Label>Rating (0-100)</Label><Input type="number" min="0" max="100" value={ data.rating } onChange={ ( e ) => setData( { ...data, rating: Number( e.target.value ) } ) } /></div>
			<div><Label>Ghi chú</Label><Textarea rows={ 3 } value={ data.notes } onChange={ ( e ) => setData( { ...data, notes: e.target.value } ) } /></div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ submitting }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ submitting }>{ submitting ? 'Đang lưu…' : 'Lưu lead' }</Button>
			</div>
		</form>
	);
}

/* ─────────────────── Opportunity form (Sheet) ─────────────────── */

function OpportunityForm( { onSubmit, onCancel, submitting } ) {
	const [ data, setData ] = useState( {
		name: '', stage: 'qualification', amount: 0, currency: 'VND',
		probability: 50, close_date: '', description: '',
	} );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( data ); } }>
			<div><Label>Tên cơ hội</Label><Input value={ data.name } onChange={ ( e ) => setData( { ...data, name: e.target.value } ) } autoFocus required /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Stage</Label>
					<select className="bzc-input" value={ data.stage } onChange={ ( e ) => setData( { ...data, stage: e.target.value } ) }>
						{ SALES_STAGES.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
					</select>
				</div>
				<div><Label>Xác suất (%)</Label><Input type="number" min="0" max="100" value={ data.probability } onChange={ ( e ) => setData( { ...data, probability: Number( e.target.value ) } ) } /></div>
			</div>
			<div className="bzc-form-grid-2">
				<div><Label>Giá trị</Label><Input type="number" min="0" value={ data.amount } onChange={ ( e ) => setData( { ...data, amount: Number( e.target.value ) } ) } /></div>
				<div><Label>Currency</Label>
					<select className="bzc-input" value={ data.currency } onChange={ ( e ) => setData( { ...data, currency: e.target.value } ) }>
						{ [ 'VND', 'USD', 'EUR' ].map( ( c ) => <option key={ c } value={ c }>{ c }</option> ) }
					</select>
				</div>
			</div>
			<div><Label>Dự kiến đóng</Label><Input type="date" value={ data.close_date } onChange={ ( e ) => setData( { ...data, close_date: e.target.value } ) } /></div>
			<div><Label>Mô tả</Label><Textarea rows={ 3 } value={ data.description } onChange={ ( e ) => setData( { ...data, description: e.target.value } ) } /></div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ submitting }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ submitting }>{ submitting ? 'Đang lưu…' : 'Lưu cơ hội' }</Button>
			</div>
		</form>
	);
}

/* ─────────────────── Contract form (Sheet) ─────────────────── */

function ContractForm( { onSubmit, onCancel, submitting } ) {
	const [ data, setData ] = useState( {
		title: '', code: '', status: 'draft',
		account_id: '', contact_id: '', opportunity_id: '',
		start_date: '', end_date: '', signed_date: '',
		amount: 0, currency: 'VND', terms: '',
	} );
	const submit = ( e ) => {
		e.preventDefault();
		const payload = { ...data };
		[ 'account_id', 'contact_id', 'opportunity_id' ].forEach( ( k ) => {
			payload[ k ] = payload[ k ] === '' || payload[ k ] === null ? null : Number( payload[ k ] );
		} );
		[ 'start_date', 'end_date', 'signed_date', 'code', 'terms' ].forEach( ( k ) => {
			if ( payload[ k ] === '' ) { delete payload[ k ]; }
		} );
		onSubmit( payload );
	};
	return (
		<form className="bzc-form" onSubmit={ submit }>
			<div><Label>Tiêu đề <span className="bzc-required">*</span></Label>
				<Input value={ data.title } onChange={ ( e ) => setData( { ...data, title: e.target.value } ) } autoFocus required />
			</div>
			<div className="bzc-form-grid-2">
				<div><Label>Mã HĐ</Label>
					<Input placeholder="CT-YYYYMMDD-xxxxx (auto nếu để trống)" value={ data.code } onChange={ ( e ) => setData( { ...data, code: e.target.value } ) } />
				</div>
				<div><Label>Status</Label>
					<select className="bzc-input" value={ data.status } onChange={ ( e ) => setData( { ...data, status: e.target.value } ) }>
						{ [ 'draft', 'active', 'expired', 'cancelled', 'renewed' ].map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
					</select>
				</div>
			</div>
			<div className="bzc-form-grid-2">
				<div><Label>Account ID</Label><Input type="number" min="0" value={ data.account_id } onChange={ ( e ) => setData( { ...data, account_id: e.target.value } ) } /></div>
				<div><Label>Contact ID</Label><Input type="number" min="0" value={ data.contact_id } onChange={ ( e ) => setData( { ...data, contact_id: e.target.value } ) } /></div>
			</div>
			<div><Label>Opportunity ID</Label><Input type="number" min="0" value={ data.opportunity_id } onChange={ ( e ) => setData( { ...data, opportunity_id: e.target.value } ) } /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Bắt đầu</Label><Input type="date" value={ data.start_date } onChange={ ( e ) => setData( { ...data, start_date: e.target.value } ) } /></div>
				<div><Label>Kết thúc</Label><Input type="date" value={ data.end_date } onChange={ ( e ) => setData( { ...data, end_date: e.target.value } ) } /></div>
			</div>
			<div><Label>Ngày ký</Label><Input type="date" value={ data.signed_date } onChange={ ( e ) => setData( { ...data, signed_date: e.target.value } ) } /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Giá trị</Label><Input type="number" min="0" value={ data.amount } onChange={ ( e ) => setData( { ...data, amount: Number( e.target.value ) } ) } /></div>
				<div><Label>Currency</Label>
					<select className="bzc-input" value={ data.currency } onChange={ ( e ) => setData( { ...data, currency: e.target.value } ) }>
						{ [ 'VND', 'USD', 'EUR' ].map( ( c ) => <option key={ c } value={ c }>{ c }</option> ) }
					</select>
				</div>
			</div>
			<div><Label>Điều khoản</Label><Textarea rows={ 3 } value={ data.terms } onChange={ ( e ) => setData( { ...data, terms: e.target.value } ) } /></div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ submitting }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ submitting }>{ submitting ? 'Đang lưu…' : 'Lưu hợp đồng' }</Button>
			</div>
		</form>
	);
}

/* ─────────────────── Detail Sheet (shared) ─────────────────── */

function EntityDetailSheet( { entity, kind, defaultTab, onClose, onNewContract } ) {
	// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — defaultTab prop + Contracts tab + channel badge
	if ( ! entity ) { return null; }
	const title = entity.name || entity.title || entity.code || ( '#' + entity.id );

	const entityTypeMap = { Lead: 'crm_lead', Opportunity: 'crm_opportunity', Contract: 'crm_contract' };
	const entityType = entityTypeMap[ kind ] || 'crm_lead';
	const { data: auditData } = useGetEntityAuditLogQuery(
		{ entity_type: entityType, entity_id: entity.id },
		{ skip: ! entity.id }
	);
	const auditEntries = auditData?.entries || [];

	return (
		<Sheet open={ !! entity } onOpenChange={ ( v ) => { if ( ! v ) { onClose(); } } }>
			<SheetContent className="bzc-sheet-wide">
				<SheetHeader>
					<SheetTitle>
						<span>{ kind } · { title }</span>
						{ entity.source && <ChannelBadge source={ entity.source } /> }
					</SheetTitle>
				</SheetHeader>
				<SheetBody>
					<Tabs defaultValue={ defaultTab || 'overview' }>
						<TabsList>
							<TabsTrigger value="overview">Tổng quan</TabsTrigger>
							<TabsTrigger value="activities">Activities</TabsTrigger>
							{ kind === 'Opportunity' && <TabsTrigger value="contracts">Hợp đồng</TabsTrigger> }
							<TabsTrigger value="history">Lịch sử</TabsTrigger>
						</TabsList>
						<TabsContent value="overview">
							<dl className="bzc-kv">
								{ Object.entries( entity ).map( ( [ k, v ] ) => (
									<React.Fragment key={ k }>
										<dt>{ k }</dt><dd>{ typeof v === 'object' ? JSON.stringify( v ) : String( v ?? '—' ) }</dd>
									</React.Fragment>
								) ) }
							</dl>
							{ kind === 'Opportunity' && (
								<div className="bzc-sheet-actions">
									<button className="bzc-sheet-action-btn" onClick={ () => onNewContract && onNewContract( entity ) }>
										<FileCheck size={ 12 } /> Tạo hợp đồng
									</button>
								</div>
							) }
						</TabsContent>
						<TabsContent value="activities">
							{/* [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — global live feed for this entity */}
							<ActivityFeed global={ true } />
						</TabsContent>
						{ kind === 'Opportunity' && (
							<TabsContent value="contracts">
								<div className="bzc-muted" style={ { fontSize: 12, padding: '8px 0 12px' } }>Hợp đồng liên kết với cơ hội này.</div>
								<button className="bzc-sheet-action-btn" onClick={ () => onNewContract && onNewContract( entity ) }>
									<Plus size={ 12 } /> Tạo hợp đồng mới
								</button>
							</TabsContent>
						) }
						<TabsContent value="history">
							<AuditTimeline entries={ auditEntries } />
						</TabsContent>
					</Tabs>
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

/* ─────────────────── Channel constants + export helper ─────────────────── */

// [2026-06-13 Johnny Chu] PHASE-0.45 — channel source list (mirrors bizcity_crm_inboxes channel types)
// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — added color + short codes for pill selector
const CHANNEL_SOURCES = [
	{ value: '',              label: 'Tất cả kênh',   color: '#475569', short: '⊕' },
	{ value: 'facebook',      label: 'Facebook',       color: '#1877f2', short: 'FB' },
	{ value: 'messenger',     label: 'Messenger',      color: '#7b3fe4', short: 'MS' },
	{ value: 'zalo_oa',       label: 'Zalo OA',        color: '#0068ff', short: 'ZOA' },
	{ value: 'zalo_personal', label: 'Zalo Cá nhân',   color: '#00b0ff', short: 'ZCN' },
	{ value: 'webchat',       label: 'WebChat',        color: '#10b981', short: 'WC' },
	{ value: 'telegram',      label: 'Telegram',       color: '#0088cc', short: 'TG' },
	{ value: 'cf7',           label: 'Contact Form 7', color: '#f59e0b', short: 'CF7' },
	{ value: 'email',         label: 'Email',          color: '#ef4444', short: 'EM' },
	{ value: 'manual',        label: 'Nhập tay',       color: '#8b5cf6', short: 'MT' },
];

const CH_COLOR_MAP = Object.fromEntries( CHANNEL_SOURCES.map( ( c ) => [ c.value, c.color ] ) );
const CH_SHORT_MAP = Object.fromEntries( CHANNEL_SOURCES.map( ( c ) => [ c.value, c.short ] ) );

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — CSV export helper (UTF-8 BOM for Excel VN)
function exportCSV( data, filename ) {
	if ( ! data.length ) { return; }
	const keys = Object.keys( data[ 0 ] );
	const csv = [
		keys.join( ',' ),
		...data.map( ( r ) => keys.map( ( k ) => JSON.stringify( r[ k ] ?? '' ) ).join( ',' ) ),
	].join( '\n' );
	const blob = new Blob( [ '\ufeff' + csv ], { type: 'text/csv;charset=utf-8;' } );
	const url  = URL.createObjectURL( blob );
	const a    = Object.assign( document.createElement( 'a' ), { href: url, download: filename } );
	a.click();
	URL.revokeObjectURL( url );
}

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — replace dropdown ChannelFilter with prominent pill row
function ChannelSelector( { value, onChange, counts = {} } ) {
	return (
		<div className="bzc-channel-selector">
			<span className="bzc-channel-selector-label">Kênh</span>
			<div className="bzc-channel-pills">
				{ CHANNEL_SOURCES.map( ( ch ) => {
					const active = value === ch.value;
					const cnt    = ch.value ? ( counts[ ch.value ] ?? 0 ) : null;
					return (
						<button
							key={ ch.value }
							type="button"
							className={ 'bzc-ch-pill' + ( active ? ' bzc-ch-pill--on' : '' ) }
							style={ active
								? { background: ch.color, borderColor: ch.color, color: '#fff' }
								: { borderColor: ch.color + '55', color: ch.color }
							}
							onClick={ () => onChange( ch.value ) }
							title={ ch.label }
						>
							<span className="bzc-ch-dot" style={ { background: active ? 'rgba(255,255,255,.65)' : ch.color } } />
							<span className="bzc-ch-label">{ ch.label }</span>
							{ cnt != null && cnt > 0 && (
								<span className="bzc-ch-count" style={ { background: active ? 'rgba(0,0,0,.18)' : ch.color + '22', color: active ? '#fff' : ch.color } }>{ cnt }</span>
							) }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}

// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — bottom export bar (req #5)
function ExportBar( { leads, opps, contracts } ) {
	const today = new Date().toISOString().slice( 0, 10 );
	return (
		<div className="bzc-export-bar">
			<span className="bzc-export-bar-label"><Download size={ 13 } /> Xuất báo cáo</span>
			<button className="bzc-export-btn" disabled={ ! leads.length } onClick={ () => exportCSV( leads, 'leads_' + today + '.csv' ) }>
				<Download size={ 11 } /> Leads ({ leads.length })
			</button>
			<button className="bzc-export-btn" disabled={ ! opps.length } onClick={ () => exportCSV( opps, 'opps_' + today + '.csv' ) }>
				<Download size={ 11 } /> Cơ hội ({ opps.length })
			</button>
			<button className="bzc-export-btn" disabled={ ! contracts.length } onClick={ () => exportCSV( contracts, 'contracts_' + today + '.csv' ) }>
				<Download size={ 11 } /> Hợp đồng ({ contracts.length })
			</button>
		</div>
	);
}

export default function SalesTab() {
	// [2026-06-13 Johnny Chu] PHASE-0.45 — channel/source filter state
	// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-5 — detailTab + channelCounts + useCallback
	// [2026-07-05 Johnny Chu] PHASE-0.46 UI — owner_id filter for per-user pipeline view
	const [ sourceFilter, setSourceFilter ] = useState( '' );
	const [ ownerFilter,  setOwnerFilter  ] = useState( 'me' ); // 'me' | 'all' | '<int>'

	const BOOT_UID    = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT?.currentUserId ) || 0;
	const IS_MANAGER  = ( typeof window !== 'undefined' && !! window.BIZCITY_CRM_BOOT?.isManager );

	// Resolve owner_id to send to BE: 'me' resolves via BE guard (pass 'me' as string)
	const ownerIdParam = ownerFilter === 'all' ? undefined : ( ownerFilter === 'me' ? 'me' : parseInt( ownerFilter, 10 ) );

	const { data: leads = [],     isLoading: ldL, error: errL } = useGetCrmLeadsQuery( { limit: 500, source: sourceFilter || undefined } );
	const { data: opps  = [],     isLoading: ldO, error: errO } = useGetCrmOpportunitiesQuery( { limit: 1000, source: sourceFilter || undefined, owner_id: ownerIdParam } );
	const { data: contracts = [], isLoading: ldC, error: errC } = useGetCrmContractsQuery( { limit: 500 } );

	const [ createLead,         { isLoading: creatingLead } ] = useCreateCrmLeadMutation();
	const [ createOpportunity,  { isLoading: creatingOpp  } ] = useCreateCrmOpportunityMutation();
	const [ updateOpportunity,  { isLoading: updatingOpp  } ] = useUpdateCrmOpportunityMutation();
	const [ createContract,     { isLoading: creatingCt   } ] = useCreateCrmContractMutation();

	const [ leadFormOpen, setLeadFormOpen ] = useState( false );
	const [ oppFormOpen,  setOppFormOpen  ] = useState( false );
	const [ ctFormOpen,   setCtFormOpen   ] = useState( false );
	const [ detail,       setDetail       ] = useState( null );
	const [ detailKind,   setDetailKind   ] = useState( '' );
	const [ detailTab,    setDetailTab    ] = useState( 'overview' );

	const openDetail = useCallback( ( kind, tab ) => ( row ) => {
		setDetail( row );
		setDetailKind( kind );
		setDetailTab( tab || 'overview' );
	}, [] );

	const closeDetail = useCallback( () => {
		setDetail( null );
		setDetailKind( '' );
		setDetailTab( 'overview' );
	}, [] );

	const handleStageChange = useCallback( async ( opp, stage ) => {
		try {
			await updateOpportunity( { id: opp.id, stage } ).unwrap();
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( '[SalesTab] stage change failed', e );
			alert( 'Không thể đổi stage: ' + ( e?.data?.message || e?.message || 'unknown' ) );
		}
	}, [ updateOpportunity ] );

	const handleQuickAction = useCallback( ( action, opp ) => {
		setDetail( opp );
		setDetailKind( 'Opportunity' );
		setDetailTab( action === 'activity' ? 'activities' : 'contracts' );
	}, [] );

	const handleNewContractFromDetail = useCallback( () => {
		closeDetail();
		setCtFormOpen( true );
	}, [ closeDetail ] );

	/* ─ Channel counts for pill badges ─ */
	const channelCounts = useMemo( () => {
		const counts = {};
		[ ...opps, ...leads ].forEach( ( r ) => {
			if ( r.source ) { counts[ r.source ] = ( counts[ r.source ] || 0 ) + 1; }
		} );
		return counts;
	}, [ opps, leads ] );

	/* ─ Column defs ─ */
	const leadCols = useMemo( () => [
		{ accessorKey: 'name',       header: 'Tên' },
		{ accessorKey: 'company',    header: 'Công ty' },
		{ accessorKey: 'phone',      header: 'SĐT' },
		{ accessorKey: 'email',      header: 'Email' },
		{ accessorKey: 'source',     header: 'Kênh', cell: ( ctx ) => <ChannelBadge source={ ctx.getValue() } /> },
		{ accessorKey: 'status',     header: 'Trạng thái', cell: ( ctx ) => <StatusBadge value={ ctx.getValue() } /> },
		{ accessorKey: 'rating',     header: 'Rating' },
		{
			accessorKey: 'lead_score',
			header: 'Score',
			cell: ( ctx ) => {
				const v = ctx.getValue() ?? 0;
				const color = v >= 70 ? 'text-emerald-600' : v >= 40 ? 'text-amber-600' : 'text-slate-400';
				return <span className={ 'font-mono text-xs font-bold ' + color }>{ v }</span>;
			},
		},
		{
			accessorKey: 'segment',
			header: 'Segment',
			cell: ( ctx ) => {
				const seg = ctx.getValue();
				if ( ! seg ) { return null; }
				const color = seg === 'VIP' ? 'bg-amber-100 text-amber-700' : seg === 'A' ? 'bg-emerald-100 text-emerald-700' : seg === 'B' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600';
				return <span className={ 'bzc-badge text-xs px-1.5 py-0.5 rounded-full ' + color }>{ seg }</span>;
			},
		},
		{ accessorKey: 'owner_id',   header: 'Owner' },
	], [] );

	const oppCols = useMemo( () => [
		{ accessorKey: 'name',             header: 'Cơ hội' },
		{ accessorKey: 'source',           header: 'Kênh', cell: ( ctx ) => <ChannelBadge source={ ctx.getValue() } /> },
		{ accessorKey: 'account_id',       header: 'Khách hàng' },
		{ accessorKey: 'stage',            header: 'Stage', cell: ( ctx ) => <Badge variant="muted">{ ctx.getValue() }</Badge> },
		{ accessorKey: 'amount',           header: 'Giá trị',     cell: ( ctx ) => formatMoney( ctx.getValue(), ctx.row.original.currency ) },
		{ accessorKey: 'probability',      header: 'Xác suất',    cell: ( ctx ) => ctx.getValue() + '%' },
		{ accessorKey: 'expected_revenue', header: 'DT kỳ vọng', cell: ( ctx ) => formatMoney( ctx.getValue(), ctx.row.original.currency ) },
		{ accessorKey: 'owner_id',         header: 'Owner' },
		{ accessorKey: 'close_date',       header: 'Đóng dự kiến' },
	], [] );

	const contractCols = useMemo( () => [
		{ accessorKey: 'code',        header: 'Mã HĐ' },
		{ accessorKey: 'title',       header: 'Tiêu đề' },
		{ accessorKey: 'account_id',  header: 'Khách hàng' },
		{ accessorKey: 'status',      header: 'Trạng thái', cell: ( ctx ) => <ContractStatusBadge value={ ctx.getValue() } /> },
		{ accessorKey: 'start_date',  header: 'Bắt đầu' },
		{ accessorKey: 'end_date',    header: 'Kết thúc' },
		{ accessorKey: 'signed_date', header: 'Ký kết' },
		{ accessorKey: 'amount',      header: 'Giá trị', cell: ( ctx ) => formatMoney( ctx.getValue(), ctx.row.original.currency ) },
	], [] );

	const totalPipeline    = useMemo( () => opps.reduce( ( s, o ) => s + Number( o.amount || 0 ), 0 ), [ opps ] );
	const weightedPipeline = useMemo( () => opps.reduce( ( s, o ) => s + Number( o.amount || 0 ) * Number( o.probability || 0 ) / 100, 0 ), [ opps ] );
	const openCount        = useMemo( () => opps.filter( ( o ) => ! String( o.stage || '' ).startsWith( 'closed' ) ).length, [ opps ] );

	const anyError   = errL || errO || errC;
	const anyLoading = ldL || ldO || ldC;

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Sales pipeline</h2>
					<p className="bzc-tabpane-subtitle">Lead · Opportunity · Contract — wired tới <code>/bizcity-crm/v1/*</code>.</p>
				</div>
				{ anyLoading && (
					<span className="bzc-muted" style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 } }>
						<Loader2 size={ 12 } className="bzc-spin" /> Đang tải…
					</span>
				) }
			</header>

			{ /* ── Prominent channel selector ── */ }
			<ChannelSelector value={ sourceFilter } onChange={ setSourceFilter } counts={ channelCounts } />

			{ anyError && (
				<Card><CardBody>
					<div className="bzc-error">
						Lỗi tải dữ liệu CRM: { String( anyError?.data?.message || anyError?.error || anyError?.status || 'unknown' ) }
					</div>
				</CardBody></Card>
			) }

			<div className="bzc-kpi-grid bzc-kpi-grid-3">
				<Card><CardHeader><CardTitle>Tổng pipeline</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ formatMoney( totalPipeline ) }</div></CardBody></Card>
				<Card><CardHeader><CardTitle>Pipeline có trọng số</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ formatMoney( weightedPipeline ) }</div></CardBody></Card>
				<Card><CardHeader><CardTitle>Số cơ hội mở</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ openCount }</div></CardBody></Card>
			</div>

			<Tabs defaultValue="pipeline" className="bzc-sales-tabs">
				<TabsList>
					<TabsTrigger value="pipeline"><TrendingUp size={ 12 } /> Pipeline</TabsTrigger>
					<TabsTrigger value="leads">Leads ({ leads.length })</TabsTrigger>
					<TabsTrigger value="opps">Cơ hội ({ opps.length })</TabsTrigger>
					<TabsTrigger value="contracts">Hợp đồng ({ contracts.length })</TabsTrigger>
				</TabsList>

				<TabsContent value="pipeline">
					<div className="bzc-tabpane-toolbar">
						<Button variant="primary" onClick={ () => setOppFormOpen( true ) }><Plus size={ 12 } /> Cơ hội mới</Button>
						<span className="bzc-muted" style={ { fontSize: 12 } }><GripVertical size={ 12 } /> Kéo-thả để đổi stage — lưu trực tiếp về BE.</span>
						{ /* [2026-07-05 Johnny Chu] PHASE-0.46 UI — owner filter picker */ }
						<div style={ { marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 } }>
							<span style={ { fontSize: 11, color: '#64748b' } }>Pipeline của:</span>
							<select
								value={ ownerFilter }
								onChange={ ( e ) => setOwnerFilter( e.target.value ) }
								style={ { fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, padding: '3px 10px', color: '#1e293b' } }
							>
								<option value="me">Tôi</option>
								{ IS_MANAGER && <option value="all">Tất cả</option> }
							</select>
						</div>
					</div>
					{ /* [2026-07-05 Johnny Chu] PHASE-0.46 UI — pipeline summary bar */ }
					{ opps.length > 0 && ( () => {
						const total_val = opps.reduce( ( s, o ) => s + parseFloat( o.amount || 0 ), 0 );
						const won       = opps.filter( ( o ) => o.stage === 'closed_won' ).length;
						const pct       = opps.length > 0 ? Math.round( won / opps.length * 100 ) : 0;
						return (
							<div style={ { display: 'flex', gap: 18, padding: '6px 16px', background: '#f8fafc', borderBottom: '1px solid #e2e8f0', fontSize: 12, color: '#475569' } }>
								<span>{ opps.length } cơ hội</span>
								<span>·</span>
								<span>{ new Intl.NumberFormat( 'vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 } ).format( total_val ) }</span>
								<span>·</span>
								<span style={ { color: '#16a34a', fontWeight: 600 } }>{ won } Closed Won ({ pct }%)</span>
							</div>
						);
					} )() }
					<PipelineBoard
						opps={ opps }
						onSelect={ openDetail( 'Opportunity' ) }
						onStageChange={ handleStageChange }
						isUpdating={ updatingOpp }
						onQuickAction={ handleQuickAction }
					/>
				</TabsContent>

				<TabsContent value="leads">
					<div className="bzc-tabpane-toolbar">
						<Button variant="primary" onClick={ () => setLeadFormOpen( true ) }><Plus size={ 12 } /> Lead mới</Button>
					</div>
					<SearchableTable
						columns={ leadCols }
						data={ leads }
						onRowClick={ openDetail( 'Lead' ) }
						onExport={ () => exportCSV( leads, 'leads_' + new Date().toISOString().slice( 0, 10 ) + '.csv' ) }
						exportLabel="Xuất CSV"
					/>
				</TabsContent>

				<TabsContent value="opps">
					<div className="bzc-tabpane-toolbar">
						<Button variant="primary" onClick={ () => setOppFormOpen( true ) }><Plus size={ 12 } /> Cơ hội mới</Button>
					</div>
					<SearchableTable
						columns={ oppCols }
						data={ opps }
						onRowClick={ openDetail( 'Opportunity' ) }
						onExport={ () => exportCSV( opps, 'opps_' + new Date().toISOString().slice( 0, 10 ) + '.csv' ) }
						exportLabel="Xuất CSV"
					/>
				</TabsContent>

				<TabsContent value="contracts">
					<div className="bzc-tabpane-toolbar">
						<Button variant="primary" onClick={ () => setCtFormOpen( true ) }><Plus size={ 12 } /> Hợp đồng mới</Button>
					</div>
					<SearchableTable
						columns={ contractCols }
						data={ contracts }
						onRowClick={ openDetail( 'Contract' ) }
						onExport={ () => exportCSV( contracts, 'contracts_' + new Date().toISOString().slice( 0, 10 ) + '.csv' ) }
						exportLabel="Xuất CSV"
					/>
				</TabsContent>
			</Tabs>

			{ /* ── Export bar ── */ }
			<ExportBar leads={ leads } opps={ opps } contracts={ contracts } />

			<Sheet open={ leadFormOpen } onOpenChange={ setLeadFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Lead mới</SheetTitle></SheetHeader>
					<SheetBody>
						<LeadForm
							submitting={ creatingLead }
							onCancel={ () => setLeadFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createLead( data ).unwrap();
									setLeadFormOpen( false );
								} catch ( e ) {
									alert( 'Lỗi tạo lead: ' + ( e?.data?.message || e?.message || 'unknown' ) );
								}
							} }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			<Sheet open={ oppFormOpen } onOpenChange={ setOppFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Cơ hội mới</SheetTitle></SheetHeader>
					<SheetBody>
						<OpportunityForm
							submitting={ creatingOpp }
							onCancel={ () => setOppFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createOpportunity( data ).unwrap();
									setOppFormOpen( false );
								} catch ( e ) {
									alert( 'Lỗi tạo opportunity: ' + ( e?.data?.message || e?.message || 'unknown' ) );
								}
							} }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			<Sheet open={ ctFormOpen } onOpenChange={ setCtFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Hợp đồng mới</SheetTitle></SheetHeader>
					<SheetBody>
						<ContractForm
							submitting={ creatingCt }
							onCancel={ () => setCtFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createContract( data ).unwrap();
									setCtFormOpen( false );
								} catch ( e ) {
									alert( 'Lỗi tạo hợp đồng: ' + ( e?.data?.message || e?.message || 'unknown' ) );
								}
							} }
						/>
					</SheetBody>
				</SheetContent>
			</Sheet>

			<EntityDetailSheet
				entity={ detail }
				kind={ detailKind }
				defaultTab={ detailTab }
				onClose={ closeDetail }
				onNewContract={ handleNewContractFromDetail }
			/>
		</div>
	);
}

