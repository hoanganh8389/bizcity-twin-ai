import React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '../../lib/utils.js';

/**
 * Sheet — side drawer (right by default). Built on Radix Dialog.
 * Used for entity create/edit forms.
 */
export const Sheet        = DialogPrimitive.Root;
export const SheetTrigger = DialogPrimitive.Trigger;
export const SheetClose   = DialogPrimitive.Close;

const SIDES = {
	right:  'bzc-sheet-right',
	left:   'bzc-sheet-left',
	top:    'bzc-sheet-top',
	bottom: 'bzc-sheet-bottom',
};

export const SheetContent = React.forwardRef( function SheetContent(
	{ className, children, side = 'right', ...props },
	ref
) {
	return (
		<DialogPrimitive.Portal>
			<DialogPrimitive.Overlay className="bzc-dialog-overlay" />
			<DialogPrimitive.Content
				ref={ ref }
				className={ cn( 'bzc-sheet-content', SIDES[ side ], className ) }
				{ ...props }
			>
				{ children }
				<DialogPrimitive.Close className="bzc-dialog-close" aria-label="Close">
					<X size={ 16 } />
				</DialogPrimitive.Close>
			</DialogPrimitive.Content>
		</DialogPrimitive.Portal>
	);
} );

export function SheetHeader( { className, ...props } ) { return <div className={ cn( 'bzc-sheet-header', className ) } { ...props } />; }
export function SheetFooter( { className, ...props } ) { return <div className={ cn( 'bzc-sheet-footer', className ) } { ...props } />; }
export function SheetBody( { className, ...props } )   { return <div className={ cn( 'bzc-sheet-body', className ) } { ...props } />; }
export const SheetTitle       = React.forwardRef( ( p, r ) => <DialogPrimitive.Title ref={ r } className={ cn( 'bzc-sheet-title', p.className ) } { ...p } /> );
export const SheetDescription = React.forwardRef( ( p, r ) => <DialogPrimitive.Description ref={ r } className={ cn( 'bzc-sheet-desc', p.className ) } { ...p } /> );
