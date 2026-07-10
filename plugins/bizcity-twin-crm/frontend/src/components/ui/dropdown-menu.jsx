import React from 'react';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import { cn } from '../../lib/utils.js';

export const DropdownMenu        = DropdownMenuPrimitive.Root;
export const DropdownMenuTrigger = DropdownMenuPrimitive.Trigger;
export const DropdownMenuGroup   = DropdownMenuPrimitive.Group;

export const DropdownMenuContent = React.forwardRef( function DropdownMenuContent(
	{ className, sideOffset = 6, ...props }, ref
) {
	return (
		<DropdownMenuPrimitive.Portal>
			<DropdownMenuPrimitive.Content ref={ ref } sideOffset={ sideOffset } className={ cn( 'bzc-menu-content', className ) } { ...props } />
		</DropdownMenuPrimitive.Portal>
	);
} );

export const DropdownMenuItem = React.forwardRef( ( { className, inset, ...props }, ref ) =>
	<DropdownMenuPrimitive.Item ref={ ref } className={ cn( 'bzc-menu-item', inset && 'pl-7', className ) } { ...props } />
);
export const DropdownMenuLabel     = React.forwardRef( ( { className, ...p }, r ) => <DropdownMenuPrimitive.Label ref={ r } className={ cn( 'bzc-menu-label', p.className ) } { ...p } /> );
export const DropdownMenuSeparator = React.forwardRef( ( { className, ...p }, r ) => <DropdownMenuPrimitive.Separator ref={ r } className={ cn( 'bzc-menu-sep', p.className ) } { ...p } /> );
