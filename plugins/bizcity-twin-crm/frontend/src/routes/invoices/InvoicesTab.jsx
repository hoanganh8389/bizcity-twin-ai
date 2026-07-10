import React, { useMemo, useState } from 'react';
import { Plus, FileText, Send, DollarSign, ExternalLink, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Badge, Card, CardHeader, CardTitle, CardBody } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody, SheetFooter } from '../../components/ui/sheet.jsx';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/tabs.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import { DataTable } from '../../components/ui/data-table.jsx';
import LineItemsEditor from '../../components/invoice/LineItemsEditor.jsx';
import { formatMoney, isoDate } from '../../lib/utils.js';
import { VND } from '../../lib/mockData.js';
import {
	useGetCrmInvoicesQuery,
	useGetCrmInvoiceQuery,
	useCreateCrmInvoiceMutation,
	useDeleteCrmInvoiceMutation,
	useTransitionCrmInvoiceMutation,
	useAddCrmInvoicePaymentMutation,
	useDeleteCrmInvoicePaymentMutation,
	useSendCrmInvoiceMutation,
} from '../../redux/api/crmApi.js';

const STATUS_VARIANT = {
	draft: 'muted', sent: 'default', issued: 'default',
	partially_paid: 'warn', paid: 'ok',
	cancelled: 'danger', voided: 'danger', refunded: 'warn',
	overdue: 'danger',
};
const STATUS_OPTIONS = [ 'draft', 'sent', 'paid', 'overdue', 'voided', 'refunded' ];
const ALLOWED_NEXT = {
	draft:    [ 'sent', 'voided' ],
	sent:     [ 'paid', 'overdue', 'voided' ],
	overdue:  [ 'paid', 'voided' ],
	paid:     [ 'refunded' ],
	voided:   [],
	refunded: [],
};

function StatusBadge( { value } ) {
	return <Badge variant={ STATUS_VARIANT[ value ] || 'default' }>{ value }</Badge>;
}

const REST_BASE = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT?.restUrl ) || '/wp-json/bizcity-crm/v1/';

// FE LineItemsEditor uses {qty, unit_price, discount_percent, tax_rate}; BE uses {quantity, unit_price, discount_pct, tax_pct}.
const beLineToFe = ( l ) => ( {
	id: l.id,
	product_id: l.product_id ? Number( l.product_id ) : null,
	description: l.description || '',
	qty: Number( l.quantity || 0 ),
	unit_price: Number( l.unit_price || 0 ),
	discount_percent: Number( l.discount_pct || 0 ),
	tax_rate: Number( l.tax_pct || 0 ),
} );
const feLineToBe = ( l ) => ( {
	product_id: l.product_id ? Number( l.product_id ) : null,
	description: l.description || '',
	quantity: Number( l.qty || 0 ),
	unit_price: Number( l.unit_price || 0 ),
	discount_pct: Number( l.discount_percent || 0 ),
	tax_pct: Number( l.tax_rate || 0 ),
} );

function InvoiceForm( { onSubmit, onCancel, busy } ) {
	const [ header, setHeader ] = useState( {
		number: '',
		account_id: '',
		issue_date: isoDate( new Date() ),
		due_date: isoDate( new Date( Date.now() + 14 * 86400000 ) ),
		currency: VND,
	} );
	const [ lines, setLines ] = useState( [] );

	return (
		<form className="bzc-form" onSubmit={ ( e ) => { e.preventDefault(); onSubmit( { ...header, account_id: Number( header.account_id ) || null, lines: lines.map( feLineToBe ) } ); } }>
			<div className="bzc-form-grid-2">
				<div><Label>Số HĐ (để trống = auto)</Label><Input value={ header.number } onChange={ ( e ) => setHeader( { ...header, number: e.target.value } ) } placeholder="auto: INV-YYYYMM-####" /></div>
				<div><Label>Account ID</Label><Input type="number" value={ header.account_id } onChange={ ( e ) => setHeader( { ...header, account_id: e.target.value } ) } /></div>
				<div><Label>Ngày phát hành</Label><Input type="date" value={ header.issue_date } onChange={ ( e ) => setHeader( { ...header, issue_date: e.target.value } ) } /></div>
				<div><Label>Hạn TT</Label><Input type="date" value={ header.due_date } onChange={ ( e ) => setHeader( { ...header, due_date: e.target.value } ) } /></div>
				<div><Label>Currency</Label>
					<select className="bzc-input" value={ header.currency } onChange={ ( e ) => setHeader( { ...header, currency: e.target.value } ) }>
						<option value="VND">VND</option><option value="USD">USD</option><option value="EUR">EUR</option>
					</select>
				</div>
			</div>
			<h4 className="bzc-section-title">Dòng hoá đơn</h4>
			<LineItemsEditor lines={ lines } currency={ header.currency } onChange={ setLines } />
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel } disabled={ busy }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ busy }>{ busy ? 'Đang lưu…' : 'Lưu draft' }</Button>
			</div>
		</form>
	);
}

function InvoiceDetailSheet( { invoiceId, onClose } ) {
	const { data: inv, isFetching } = useGetCrmInvoiceQuery( invoiceId, { skip: ! invoiceId } );
	const [ transition, { isLoading: tBusy } ] = useTransitionCrmInvoiceMutation();
	const [ addPayment, { isLoading: pBusy } ] = useAddCrmInvoicePaymentMutation();
	const [ delPayment ] = useDeleteCrmInvoicePaymentMutation();
	const [ sendInvoice, { isLoading: sBusy } ] = useSendCrmInvoiceMutation();
	const [ delInvoice ] = useDeleteCrmInvoiceMutation();

	const [ payAmount, setPayAmount ] = useState( '' );
	const [ payMethod, setPayMethod ] = useState( 'transfer' );
	const [ sendTo, setSendTo ] = useState( '' );

	if ( ! invoiceId ) { return null; }
	const lines    = ( inv?.lines || [] ).map( beLineToFe );
	const payments = inv?.payments || [];
	const status   = inv?.status   || 'draft';
	const currency = inv?.currency || VND;
	const next     = ALLOWED_NEXT[ status ] || [];

	const pdfUrl = invoiceId ? `${ REST_BASE }crm-invoices/${ invoiceId }/pdf?_wpnonce=${ window.BIZCITY_CRM_BOOT?.restNonce || '' }` : '#';

	const handleAddPayment = async () => {
		if ( ! payAmount ) { return; }
		try {
			await addPayment( { id: invoiceId, amount: Number( payAmount ), method: payMethod, paid_at: isoDate( new Date() ) } ).unwrap();
			setPayAmount( '' );
		} catch ( e ) { alert( 'Lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleSend = async () => {
		if ( ! sendTo ) { return; }
		try {
			await sendInvoice( { id: invoiceId, to: sendTo } ).unwrap();
			alert( 'Đã gửi tới ' + sendTo );
			setSendTo( '' );
		} catch ( e ) { alert( 'Lỗi gửi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleTransition = async ( s ) => {
		try { await transition( { id: invoiceId, status: s } ).unwrap(); }
		catch ( e ) { alert( 'Không đổi trạng thái: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	const handleDelete = async () => {
		if ( ! window.confirm( 'Xoá hoá đơn này?' ) ) { return; }
		try { await delInvoice( invoiceId ).unwrap(); onClose(); }
		catch ( e ) { alert( 'Không xoá được: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	return (
		<Sheet open={ !! invoiceId } onOpenChange={ ( v ) => { if ( ! v ) { onClose(); } } }>
			<SheetContent className="bzc-sheet-wide">
				<SheetHeader>
					<SheetTitle>
						<FileText size={ 16 } style={ { display: 'inline', marginRight: 6 } } />
						{ inv?.number || ( isFetching ? 'Đang tải…' : 'Hoá đơn #' + invoiceId ) }
						{ inv?.wc_order_id ? (
							<a
								href={ inv.wc_order_admin_url || '#' }
								target="_blank"
								rel="noreferrer noopener"
								className="bzc-badge bzc-badge-info"
								style={ { marginLeft: 8, fontSize: 11, fontWeight: 500, textDecoration: 'none' } }
								title={ 'Woo order #' + inv.wc_order_id }
							>
								Mở trong WooCommerce ↗
							</a>
						) : null }
					</SheetTitle>
				</SheetHeader>
				<SheetBody>
					{ isFetching && ! inv ? <div className="bzc-empty bzc-muted">Đang tải…</div> : (
						<>
							<div className="bzc-kv-grid">
								<div><span className="bzc-muted">Trạng thái</span><StatusBadge value={ status } /></div>
								<div><span className="bzc-muted">Phát hành</span><strong>{ inv?.issue_date }</strong></div>
								<div><span className="bzc-muted">Hạn TT</span><strong>{ inv?.due_date || '—' }</strong></div>
								<div><span className="bzc-muted">Tổng</span><strong>{ formatMoney( inv?.total, currency ) }</strong></div>
								<div><span className="bzc-muted">Đã thu</span><strong>{ formatMoney( inv?.amount_paid, currency ) }</strong></div>
								<div><span className="bzc-muted">Còn nợ</span><strong>{ formatMoney( inv?.amount_due, currency ) }</strong></div>
							</div>

							{ next.length > 0 && (
								<div className="bzc-form-actions" style={ { marginTop: 12, gap: 6 } }>
									<span className="bzc-muted" style={ { alignSelf: 'center' } }>Chuyển trạng thái:</span>
									{ next.map( ( s ) => (
										<Button key={ s } size="sm" disabled={ tBusy } onClick={ () => handleTransition( s ) }>→ { s }</Button>
									) ) }
								</div>
							) }

							<Tabs defaultValue="lines" className="bzc-mt-md">
								<TabsList>
									<TabsTrigger value="lines">Dòng HĐ ({ lines.length })</TabsTrigger>
									<TabsTrigger value="payments">Thanh toán ({ payments.length })</TabsTrigger>
									<TabsTrigger value="send">Gửi qua email</TabsTrigger>
								</TabsList>

								<TabsContent value="lines">
									<LineItemsEditor lines={ lines } currency={ currency } readOnly />
								</TabsContent>

								<TabsContent value="payments">
									<table className="bzc-table">
										<thead><tr><th>Ngày</th><th>Method</th><th>Ref</th><th style={ { textAlign: 'right' } }>Số tiền</th><th></th></tr></thead>
										<tbody>
											{ payments.length === 0 && <tr><td colSpan="5" className="bzc-muted">Chưa có thanh toán.</td></tr> }
											{ payments.map( ( p ) => (
												<tr key={ p.id }>
													<td>{ p.paid_at }</td>
													<td>{ p.method || '—' }</td>
													<td>{ p.reference || '—' }</td>
													<td style={ { textAlign: 'right' } }>{ formatMoney( p.amount, currency ) }</td>
													<td><Button size="sm" onClick={ () => delPayment( p.id ) }><Trash2 size={ 12 } /></Button></td>
												</tr>
											) ) }
										</tbody>
									</table>
									{ ( status === 'sent' || status === 'overdue' || status === 'paid' ) && (
										<div className="bzc-form-grid-2" style={ { marginTop: 12 } }>
											<div><Label>Số tiền</Label><Input type="number" step="0.01" value={ payAmount } onChange={ ( e ) => setPayAmount( e.target.value ) } /></div>
											<div><Label>Method</Label>
												<select className="bzc-input" value={ payMethod } onChange={ ( e ) => setPayMethod( e.target.value ) }>
													<option value="transfer">Transfer</option><option value="cash">Cash</option>
													<option value="card">Card</option><option value="other">Other</option>
												</select>
											</div>
											<div style={ { gridColumn: '1 / -1' } }>
												<Button variant="primary" disabled={ pBusy || ! payAmount } onClick={ handleAddPayment }><DollarSign size={ 12 } /> Ghi nhận thanh toán</Button>
											</div>
										</div>
									) }
								</TabsContent>

								<TabsContent value="send">
									<div className="bzc-form-grid-2">
										<div style={ { gridColumn: '1 / -1' } }><Label>Email người nhận</Label><Input type="email" value={ sendTo } onChange={ ( e ) => setSendTo( e.target.value ) } placeholder="customer@example.com" /></div>
										<div>
											<Button variant="primary" disabled={ sBusy || ! sendTo } onClick={ handleSend }><Send size={ 12 } /> Gửi hoá đơn</Button>
										</div>
										<div style={ { textAlign: 'right' } }>
											<a href={ pdfUrl } target="_blank" rel="noopener noreferrer" className="bzc-btn"><ExternalLink size={ 12 } /> Xem/In PDF</a>
										</div>
									</div>
								</TabsContent>
							</Tabs>
						</>
					) }
				</SheetBody>
				<SheetFooter>
					<Button onClick={ handleDelete } disabled={ ! ( status === 'draft' || status === 'voided' ) }><Trash2 size={ 12 } /> Xoá</Button>
					<Button onClick={ onClose }>Đóng</Button>
				</SheetFooter>
			</SheetContent>
		</Sheet>
	);
}

export default function InvoicesTab() {
	const [ statusFilter, setStatusFilter ] = useState( '' );
	const [ formOpen, setFormOpen ] = useState( false );
	const [ detailId, setDetailId ] = useState( null );

	const { data: invoices = [], isFetching } = useGetCrmInvoicesQuery(
		statusFilter ? { status: statusFilter, limit: 200 } : { limit: 200 }
	);
	const [ createInvoice, { isLoading: cBusy } ] = useCreateCrmInvoiceMutation();

	const cols = useMemo( () => [
		{ accessorKey: 'number',     header: 'Số HĐ' },
		{ accessorKey: 'account_id', header: 'Account', cell: ( c ) => c.getValue() || '—' },
		{ accessorKey: 'status',     header: 'Status', cell: ( c ) => <StatusBadge value={ c.getValue() } /> },
		{ accessorKey: 'issue_date', header: 'Phát hành' },
		{ accessorKey: 'due_date',   header: 'Hạn TT', cell: ( c ) => c.getValue() || '—' },
		{ accessorKey: 'total',      header: 'Tổng',   cell: ( c ) => formatMoney( c.getValue(), c.row.original.currency ) },
		{ accessorKey: 'amount_due', header: 'Còn nợ', cell: ( c ) => formatMoney( c.getValue(), c.row.original.currency ) },
	], [] );

	const totalIssued = invoices.filter( ( i ) => i.status !== 'draft' && i.status !== 'voided' ).reduce( ( s, i ) => s + Number( i.total || 0 ), 0 );
	const totalPaid   = invoices.reduce( ( s, i ) => s + Number( i.amount_paid || 0 ), 0 );
	const totalDue    = invoices.reduce( ( s, i ) => s + Number( i.amount_due  || 0 ), 0 );

	const handleCreate = async ( payload ) => {
		try {
			const res = await createInvoice( payload ).unwrap();
			setFormOpen( false );
			if ( res?.id ) { setDetailId( res.id ); }
		} catch ( e ) { alert( 'Tạo lỗi: ' + ( e?.data?.error?.message || e.message ) ); }
	};

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Invoices</h2>
					<p className="bzc-tabpane-subtitle">Hoá đơn — vòng đời, thanh toán &amp; PDF (live API · M-CRM.M2).</p>
				</div>
			</header>

			<div className="bzc-kpi-grid bzc-kpi-grid-3">
				<Card><CardHeader><CardTitle>Đã phát hành</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ formatMoney( totalIssued ) }</div></CardBody></Card>
				<Card><CardHeader><CardTitle>Đã thu</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ formatMoney( totalPaid ) }</div></CardBody></Card>
				<Card><CardHeader><CardTitle>Còn phải thu</CardTitle></CardHeader><CardBody><div className="bzc-kpi-num">{ formatMoney( totalDue ) }</div></CardBody></Card>
			</div>

			<div className="bzc-tabpane-toolbar">
				<select className="bzc-input bzc-input-sm" value={ statusFilter } onChange={ ( e ) => setStatusFilter( e.target.value ) }>
					<option value="">Tất cả trạng thái</option>
					{ STATUS_OPTIONS.map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
				</select>
				<Button variant="primary" onClick={ () => setFormOpen( true ) }><Plus size={ 12 } /> Hoá đơn mới</Button>
			</div>

			{ isFetching && invoices.length === 0
				? <div className="bzc-empty bzc-muted">Đang tải…</div>
				: <DataTable columns={ cols } data={ invoices } onRowClick={ ( row ) => setDetailId( row.id ) } />
			}

			<Sheet open={ formOpen } onOpenChange={ setFormOpen }>
				<SheetContent className="bzc-sheet-wide">
					<SheetHeader><SheetTitle>Hoá đơn mới</SheetTitle></SheetHeader>
					<SheetBody>
						<InvoiceForm onSubmit={ handleCreate } onCancel={ () => setFormOpen( false ) } busy={ cBusy } />
					</SheetBody>
				</SheetContent>
			</Sheet>

			<InvoiceDetailSheet invoiceId={ detailId } onClose={ () => setDetailId( null ) } />
		</div>
	);
}
