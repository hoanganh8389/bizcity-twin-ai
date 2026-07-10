import React, { useMemo, useState } from 'react';
import { Plus, Building2, Globe } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Badge } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/tabs.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import { DataTable } from '../../components/ui/data-table.jsx';
import AuditTimeline from '../../components/audit/AuditTimeline.jsx';
import ActivityFeed from '../../components/activity/ActivityFeed.jsx';
import { formatMoney } from '../../lib/utils.js';
import {
	useGetCrmAccountsQuery,
	useGetCrmContactsQuery,
	useCreateCrmAccountMutation,
	useGetEntityAuditLogQuery,
} from '../../redux/api/crmApi.js';

function StatusBadge( { value } ) {
	return <Badge variant={ value === 'active' ? 'ok' : 'muted' }>{ value }</Badge>;
}

function AccountForm( { onSubmit, onCancel, initial } ) {
	const [ data, setData ] = useState( initial || { name: '', industry: '', size: '10-50', country: 'VN', website: '', owner: 'me', annual_revenue: 0, status: 'active' } );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( data ); } }>
			<div><Label>Tên công ty</Label><Input value={ data.name } onChange={ ( e ) => setData( { ...data, name: e.target.value } ) } required autoFocus /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Ngành</Label><Input value={ data.industry } onChange={ ( e ) => setData( { ...data, industry: e.target.value } ) } /></div>
				<div><Label>Quy mô</Label>
					<select className="bzc-input" value={ data.size } onChange={ ( e ) => setData( { ...data, size: e.target.value } ) }>
						{ [ '1-10', '10-50', '50-200', '200-500', '500+' ].map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
					</select>
				</div>
				<div><Label>Website</Label><Input value={ data.website } onChange={ ( e ) => setData( { ...data, website: e.target.value } ) } placeholder="https://" /></div>
				<div><Label>Quốc gia</Label><Input value={ data.country } onChange={ ( e ) => setData( { ...data, country: e.target.value } ) } /></div>
				<div><Label>Doanh thu năm (VND)</Label><Input type="number" value={ data.annual_revenue } onChange={ ( e ) => setData( { ...data, annual_revenue: Number( e.target.value ) } ) } /></div>
				<div><Label>Trạng thái</Label>
					<select className="bzc-input" value={ data.status } onChange={ ( e ) => setData( { ...data, status: e.target.value } ) }>
						<option value="active">active</option>
						<option value="inactive">inactive</option>
					</select>
				</div>
			</div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel }>Huỷ</Button>
				<Button type="submit" variant="primary">Lưu account</Button>
			</div>
		</form>
	);
}

function AccountDetailSheet( { account, onClose } ) {
	const { data: contacts = [] } = useGetCrmContactsQuery(
		account ? { account_id: account.id } : undefined,
		{ skip: ! account }
	);
	if ( ! account ) { return null; }
	return (
		<Sheet open={ !! account } onOpenChange={ ( v ) => { if ( ! v ) { onClose(); } } }>
			<SheetContent className="bzc-sheet-wide">
				<SheetHeader>
					<SheetTitle><Building2 size={ 16 } style={ { display: 'inline', marginRight: 6 } } />{ account.name }</SheetTitle>
				</SheetHeader>
				<SheetBody>
					<div className="bzc-kv-grid">
						<div><span className="bzc-muted">Ngành</span><strong>{ account.industry || '—' }</strong></div>
						<div><span className="bzc-muted">Quy mô</span><strong>{ account.size || '—' }</strong></div>
						<div><span className="bzc-muted">Quốc gia</span><strong>{ account.country || '—' }</strong></div>
						<div><span className="bzc-muted">Website</span><strong><Globe size={ 11 } /> { account.website || '—' }</strong></div>
						<div><span className="bzc-muted">Doanh thu năm</span><strong>{ formatMoney( account.annual_revenue ) }</strong></div>
						<div><span className="bzc-muted">Owner</span><strong>{ account.owner_id || '—' }</strong></div>
					</div>

					<Tabs defaultValue="contacts" className="bzc-mt-md">
						<TabsList>
							<TabsTrigger value="contacts">Contacts ({ contacts.length })</TabsTrigger>
							<TabsTrigger value="activities">Activities</TabsTrigger>
							<TabsTrigger value="history">History</TabsTrigger>
						</TabsList>
						<TabsContent value="contacts">
							<ul className="bzc-mini-list">
								{ contacts.length ? contacts.map( ( c ) => (
									<li key={ c.id }>
										<strong>{ c.name || `${ c.first_name } ${ c.last_name }` }</strong>
										<span className="bzc-muted">{ c.title || '—' } · { c.email || '—' }</span>
									</li>
								) ) : <li className="bzc-muted">Chưa có contact gắn với account này.</li> }
							</ul>
						</TabsContent>
						<TabsContent value="activities"><ActivityFeed accountId={ account.id } /></TabsContent>
					<TabsContent value="history"><AccountAuditPanel accountId={ account.id } /></TabsContent>
					</Tabs>
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

function AccountAuditPanel( { accountId } ) {
	const { data, isFetching } = useGetEntityAuditLogQuery(
		{ entity_type: 'crm_account', entity_id: accountId },
		{ skip: ! accountId }
	);
	const entries = data?.entries || [];
	return isFetching
		? <div className="bzc-muted text-xs">Đang tải…</div>
		: <AuditTimeline entries={ entries } />;
}

export default function AccountsTab() {
	const { data: accounts = [], isFetching } = useGetCrmAccountsQuery();
	const [ createAccount, { isLoading: creating } ] = useCreateCrmAccountMutation();
	const [ formOpen, setFormOpen ] = useState( false );
	const [ detail, setDetail ] = useState( null );

	const cols = useMemo( () => [
		{ accessorKey: 'name',      header: 'Tên' },
		{ accessorKey: 'industry',  header: 'Ngành' },
		{ accessorKey: 'size',      header: 'Quy mô' },
		{ accessorKey: 'country',   header: 'Quốc gia' },
		{ accessorKey: 'annual_revenue', header: 'Doanh thu', cell: ( c ) => formatMoney( c.getValue() ) },
		{ accessorKey: 'opportunities_count',  header: 'Opps' },
		{ accessorKey: 'status',    header: 'Status', cell: ( c ) => <StatusBadge value={ c.getValue() } /> },
		{ accessorKey: 'owner_id',  header: 'Owner' },
	], [] );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Accounts</h2>
					<p className="bzc-tabpane-subtitle">Quản lý công ty / khách hàng tổ chức. { isFetching ? '— đang tải…' : `(${ accounts.length })` }</p>
				</div>
				<Button variant="primary" onClick={ () => setFormOpen( true ) }><Plus size={ 12 } /> Account mới</Button>
			</header>

			<DataTable columns={ cols } data={ accounts } onRowClick={ setDetail } />

			<Sheet open={ formOpen } onOpenChange={ setFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Account mới</SheetTitle></SheetHeader>
					<SheetBody>
						<AccountForm
							onCancel={ () => setFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createAccount( data ).unwrap();
									setFormOpen( false );
								} catch ( err ) {
									console.error( '[bizcity-crm] create account failed', err );
									alert( 'Lưu account thất bại. Xem console.' );
								}
							} }
						/>
						{ creating && <p className="bzc-muted bzc-mt-sm">Đang lưu…</p> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			<AccountDetailSheet account={ detail } onClose={ () => setDetail( null ) } />
		</div>
	);
}
