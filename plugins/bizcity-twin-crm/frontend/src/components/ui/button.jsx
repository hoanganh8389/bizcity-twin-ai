import React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cn } from '../../lib/utils.js';

/**
 * Button — shadcn-style with variants. Maps to .bzc-btn-* classes.
 * variant: default | primary | ghost | danger | outline
 * size:    default | sm | lg | icon
 */
const VARIANTS = {
	default: 'bzc-btn',
	primary: 'bzc-btn-primary',
	ghost:   'bzc-btn-ghost',
	danger:  'bzc-btn-danger',
	outline: 'bzc-btn',
};

const SIZES = {
	default: '',
	sm:      'bzc-btn-sm',
	lg:      'bzc-btn-lg',
	icon:    'bzc-btn-icon',
};

export const Button = React.forwardRef( function Button(
	{ className, variant = 'default', size = 'default', asChild = false, ...props },
	ref
) {
	const Comp = asChild ? Slot : 'button';
	return (
		<Comp
			ref={ ref }
			className={ cn( VARIANTS[ variant ] || VARIANTS.default, SIZES[ size ] || '', className ) }
			{ ...props }
		/>
	);
} );

export default Button;
