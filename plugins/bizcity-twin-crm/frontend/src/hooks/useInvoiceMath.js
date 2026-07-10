import { useMemo } from 'react';

/**
 * useInvoiceMath — pure totals calculator for invoice line items.
 *
 * Each line: { qty, unit_price, discount_percent, tax_rate }
 *
 * Returns:
 *   {
 *     lines:    [{ ...line, subtotal, vat, total }],
 *     subtotal,
 *     totalDiscount,
 *     totalTax,
 *     grandTotal,
 *     taxBreakdown: { '10': 1234, '8': 0, ... }
 *   }
 */
export function useInvoiceMath( rawLines ) {
	return useMemo( () => {
		const lines = ( rawLines || [] ).map( ( l ) => {
			const qty = Number( l.qty || 0 );
			const unit = Number( l.unit_price || 0 );
			const discount = Number( l.discount_percent || 0 );
			const tax = Number( l.tax_rate || 0 );
			const gross = qty * unit;
			const subtotal = gross * ( 1 - discount / 100 );
			const vat = subtotal * tax / 100;
			const total = subtotal + vat;
			return { ...l, subtotal, vat, total };
		} );

		const subtotal = lines.reduce( ( s, l ) => s + l.subtotal, 0 );
		const totalDiscount = lines.reduce( ( s, l ) => {
			const gross = Number( l.qty || 0 ) * Number( l.unit_price || 0 );
			return s + ( gross - l.subtotal );
		}, 0 );
		const totalTax = lines.reduce( ( s, l ) => s + l.vat, 0 );
		const grandTotal = subtotal + totalTax;

		const taxBreakdown = {};
		lines.forEach( ( l ) => {
			const key = String( Number( l.tax_rate || 0 ) );
			taxBreakdown[ key ] = ( taxBreakdown[ key ] || 0 ) + l.vat;
		} );

		return { lines, subtotal, totalDiscount, totalTax, grandTotal, taxBreakdown };
	}, [ rawLines ] );
}

export default useInvoiceMath;
