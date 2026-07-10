import React from 'react';
import { cn } from '../../lib/utils.js';

export function Badge( { className, variant = 'default', ...props } ) {
	const v = {
		default: 'bzc-badge',
		muted:   'bzc-badge bzc-badge-muted',
		ok:      'bzc-badge bzc-badge-ok',
		warn:    'bzc-badge bzc-badge-warn',
		danger:  'bzc-badge bzc-badge-danger',
	}[ variant ] || 'bzc-badge';
	return <span className={ cn( v, className ) } { ...props } />;
}

export function Card( { className, ...props } ) {
	return <div className={ cn( 'bzc-card', className ) } { ...props } />;
}
export function CardHeader( { className, ...props } ) { return <div className={ cn( 'bzc-card-header', className ) } { ...props } />; }
export function CardTitle( { className, ...props } )  { return <div className={ cn( 'bzc-card-title', className ) } { ...props } />; }
export function CardBody( { className, ...props } )   { return <div className={ cn( 'bzc-card-body', className ) } { ...props } />; }

export default Card;
