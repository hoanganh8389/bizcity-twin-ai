import React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '../../lib/utils.js';

export const Dialog        = DialogPrimitive.Root;
export const DialogTrigger = DialogPrimitive.Trigger;
export const DialogClose   = DialogPrimitive.Close;
export const DialogPortal  = DialogPrimitive.Portal;

export const DialogOverlay = React.forwardRef( function DialogOverlay( { className, ...props }, ref ) {
	return <DialogPrimitive.Overlay ref={ ref } className={ cn( 'bzc-dialog-overlay', className ) } { ...props } />;
} );

export const DialogContent = React.forwardRef( function DialogContent( { className, children, ...props }, ref ) {
	return (
		<DialogPortal>
			<DialogOverlay />
			<DialogPrimitive.Content ref={ ref } className={ cn( 'bzc-dialog-content', className ) } { ...props }>
				{ children }
				<DialogPrimitive.Close className="bzc-dialog-close" aria-label="Close">
					<X size={ 16 } />
				</DialogPrimitive.Close>
			</DialogPrimitive.Content>
		</DialogPortal>
	);
} );

export function DialogHeader( { className, ...props } ) { return <div className={ cn( 'bzc-dialog-header', className ) } { ...props } />; }
export function DialogFooter( { className, ...props } ) { return <div className={ cn( 'bzc-dialog-footer', className ) } { ...props } />; }
export const DialogTitle       = React.forwardRef( ( p, r ) => <DialogPrimitive.Title ref={ r } className={ cn( 'bzc-dialog-title', p.className ) } { ...p } /> );
export const DialogDescription = React.forwardRef( ( p, r ) => <DialogPrimitive.Description ref={ r } className={ cn( 'bzc-dialog-desc', p.className ) } { ...p } /> );
