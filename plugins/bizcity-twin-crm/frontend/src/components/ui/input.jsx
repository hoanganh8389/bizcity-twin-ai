import React from 'react';
import { cn } from '../../lib/utils.js';

export const Input = React.forwardRef( function Input( { className, ...props }, ref ) {
	return <input ref={ ref } className={ cn( 'bzc-input', className ) } { ...props } />;
} );

export const Textarea = React.forwardRef( function Textarea( { className, ...props }, ref ) {
	return <textarea ref={ ref } className={ cn( 'bzc-textarea', className ) } { ...props } />;
} );

export const Label = React.forwardRef( function Label( { className, ...props }, ref ) {
	return <label ref={ ref } className={ cn( 'bzc-label', className ) } { ...props } />;
} );

export default Input;
