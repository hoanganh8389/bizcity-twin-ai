import React from 'react';
import { Trash2, Plus } from 'lucide-react';
import { Input } from '../ui/input.jsx';
import { Button } from '../ui/button.jsx';
import { formatMoney } from '../../lib/utils.js';
import { useInvoiceMath } from '../../hooks/useInvoiceMath.js';
import { useGetCrmProductsQuery } from '../../redux/api/crmApi.js';

/**
 * LineItemsEditor — controlled inline editor for invoice/contract line items.
 * Pattern ported from NextCRM `components/crm/invoices/LineItems.tsx`.
 *
 * Props:
 *   - lines: [{id?, product_id?, description, qty, unit_price, discount_percent, tax_rate}]
 *   - currency
 *   - onChange(nextLines)
 *   - readOnly
 */
export default function LineItemsEditor( { lines = [], currency = 'VND', onChange, readOnly = false } ) {
	const math = useInvoiceMath( lines );

	// PHASE 0.35 M-CRM.M2 — product picker. Pull active products once; small list
	// (≤ 200) so a plain <select> is fine. For huge catalogs we'd swap to a
	// cmdk combobox with debounced search.
	const { data: products = [] } = useGetCrmProductsQuery( { limit: 200, status: 'active' } );

	const update = ( idx, patch ) => {
		const next = lines.map( ( l, i ) => i === idx ? { ...l, ...patch } : l );
		onChange && onChange( next );
	};

	const onPickProduct = ( idx, productId ) => {
		const pid = Number( productId ) || null;
		if ( ! pid ) {
			update( idx, { product_id: null } );
			return;
		}
		const p = products.find( ( x ) => Number( x.id ) === pid );
		if ( ! p ) { update( idx, { product_id: pid } ); return; }
		update( idx, {
			product_id: pid,
			description: p.name + ( p.sku ? ` (${ p.sku })` : '' ),
			unit_price: Number( p.unit_price || 0 ),
			tax_rate: Number( p.tax_rate || 0 ),
		} );
	};

	const add = () => {
		onChange && onChange( [
			...lines,
			{ id: 'tmp-' + Date.now(), product_id: null, description: '', qty: 1, unit_price: 0, discount_percent: 0, tax_rate: 10 },
		] );
	};

	const remove = ( idx ) => {
		const next = lines.filter( ( _, i ) => i !== idx );
		onChange && onChange( next );
	};

	return (
		<div className="bzc-line-editor">
			<table className="bzc-table bzc-line-table">
				<thead>
					<tr>
						<th style={ { width: 40 } }>#</th>
						<th style={ { width: 220 } }>Sản phẩm</th>
						<th>Mô tả</th>
						<th style={ { width: 70 } }>SL</th>
						<th style={ { width: 130 } }>Đơn giá</th>
						<th style={ { width: 80 } }>CK %</th>
						<th style={ { width: 80 } }>VAT %</th>
						<th style={ { width: 130, textAlign: 'right' } }>Thành tiền</th>
						{ ! readOnly && <th style={ { width: 40 } }></th> }
					</tr>
				</thead>
				<tbody>
					{ math.lines.map( ( l, idx ) => (
						<tr key={ l.id ?? idx } className="bzc-row">
							<td>{ idx + 1 }</td>
							<td>
								<select
									className="bzc-input"
									disabled={ readOnly }
									value={ l.product_id || '' }
									onChange={ ( e ) => onPickProduct( idx, e.target.value ) }
								>
									<option value="">— Chọn / hoặc nhập tay —</option>
									{ products.map( ( p ) => (
										<option key={ p.id } value={ p.id }>
											{ p.sku ? `[${ p.sku }] ` : '' }{ p.name }{ p.unit_price ? ` · ${ formatMoney( p.unit_price, p.currency || currency ) }` : '' }
										</option>
									) ) }
								</select>
							</td>
							<td>
								<Input
									disabled={ readOnly }
									value={ l.description || '' }
									onChange={ ( e ) => update( idx, { description: e.target.value } ) }
									placeholder="Mô tả sản phẩm/dịch vụ"
								/>
							</td>
							<td>
								<Input
									disabled={ readOnly }
									type="number" min="0" step="1"
									value={ l.qty }
									onChange={ ( e ) => update( idx, { qty: e.target.value } ) }
								/>
							</td>
							<td>
								<Input
									disabled={ readOnly }
									type="number" min="0" step="1000"
									value={ l.unit_price }
									onChange={ ( e ) => update( idx, { unit_price: e.target.value } ) }
								/>
							</td>
							<td>
								<Input
									disabled={ readOnly }
									type="number" min="0" max="100" step="0.5"
									value={ l.discount_percent }
									onChange={ ( e ) => update( idx, { discount_percent: e.target.value } ) }
								/>
							</td>
							<td>
								<Input
									disabled={ readOnly }
									type="number" min="0" max="100" step="0.5"
									value={ l.tax_rate }
									onChange={ ( e ) => update( idx, { tax_rate: e.target.value } ) }
								/>
							</td>
							<td style={ { textAlign: 'right', fontVariantNumeric: 'tabular-nums' } }>{ formatMoney( l.total, currency ) }</td>
							{ ! readOnly && (
								<td>
									<button type="button" className="bzc-icon-btn" onClick={ () => remove( idx ) } title="Xoá dòng">
										<Trash2 size={ 14 } />
									</button>
								</td>
							) }
						</tr>
					) ) }
					{ math.lines.length === 0 && (
						<tr><td colSpan={ readOnly ? 8 : 9 } className="bzc-empty bzc-muted">Chưa có dòng nào.</td></tr>
					) }
				</tbody>
			</table>

			{ ! readOnly && (
				<div className="bzc-line-toolbar">
					{ /* type="button" — CRITICAL: prevents accidental form submit when this editor is rendered inside a parent <form>. */ }
					<Button type="button" size="sm" onClick={ add }><Plus size={ 12 } /> Thêm dòng</Button>
				</div>
			) }

			<div className="bzc-line-totals">
				<div className="bzc-line-totals-row"><span className="bzc-muted">Tạm tính</span><span>{ formatMoney( math.subtotal, currency ) }</span></div>
				{ math.totalDiscount > 0 && (
					<div className="bzc-line-totals-row"><span className="bzc-muted">Chiết khấu</span><span>− { formatMoney( math.totalDiscount, currency ) }</span></div>
				) }
				{ Object.entries( math.taxBreakdown ).filter( ( [ , v ] ) => v > 0 ).map( ( [ rate, val ] ) => (
					<div key={ rate } className="bzc-line-totals-row"><span className="bzc-muted">VAT { rate }%</span><span>{ formatMoney( val, currency ) }</span></div>
				) ) }
				<div className="bzc-line-totals-row bzc-line-totals-grand">
					<span>Tổng cộng</span><strong>{ formatMoney( math.grandTotal, currency ) }</strong>
				</div>
			</div>
		</div>
	);
}
