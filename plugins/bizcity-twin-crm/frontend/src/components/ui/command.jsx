import React from 'react';
import { Command as CommandPrimitive } from 'cmdk';
import { Search } from 'lucide-react';
import { cn } from '../../lib/utils.js';

/**
 * Command — cmdk wrapper styled to .bzc-cmd-*.
 */
export const Command = React.forwardRef( ( { className, ...p }, r ) => (
	<CommandPrimitive ref={ r } className={ cn( 'bzc-cmd', className ) } { ...p } />
) );

export const CommandInput = React.forwardRef( ( { className, ...p }, r ) => (
	<div className="bzc-cmd-input-wrap">
		<Search size={ 14 } />
		<CommandPrimitive.Input ref={ r } className={ cn( 'bzc-cmd-input', className ) } { ...p } />
	</div>
) );

export const CommandList     = React.forwardRef( ( { className, ...p }, r ) => <CommandPrimitive.List ref={ r } className={ cn( 'bzc-cmd-list', className ) } { ...p } /> );
export const CommandEmpty    = React.forwardRef( ( { className, ...p }, r ) => <CommandPrimitive.Empty ref={ r } className={ cn( 'bzc-cmd-empty', className ) } { ...p } /> );
export const CommandGroup    = React.forwardRef( ( { className, ...p }, r ) => <CommandPrimitive.Group ref={ r } className={ cn( 'bzc-cmd-group', className ) } { ...p } /> );
export const CommandItem     = React.forwardRef( ( { className, ...p }, r ) => <CommandPrimitive.Item ref={ r } className={ cn( 'bzc-cmd-item', className ) } { ...p } /> );
export const CommandSeparator= React.forwardRef( ( { className, ...p }, r ) => <CommandPrimitive.Separator ref={ r } className={ cn( 'bzc-cmd-sep', className ) } { ...p } /> );
