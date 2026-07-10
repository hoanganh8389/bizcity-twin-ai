import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * cn() — shadcn-style className combiner. Resolves Tailwind conflicts.
 */
export function cn( ...inputs ) {
	return twMerge( clsx( inputs ) );
}

/**
 * Format currency with `Intl.NumberFormat`. Falls back to USD if no code.
 */
export function formatMoney( amount, code = 'VND', locale = 'vi-VN' ) {
	try {
		return new Intl.NumberFormat( locale, { style: 'currency', currency: code } ).format( Number( amount || 0 ) );
	} catch ( e ) {
		return Number( amount || 0 ).toLocaleString( locale ) + ' ' + code;
	}
}

/**
 * Pluck a date-only string `YYYY-MM-DD` from a Date or ISO string.
 */
export function isoDate( d ) {
	const dt = d instanceof Date ? d : new Date( d );
	return dt.toISOString().slice( 0, 10 );
}
