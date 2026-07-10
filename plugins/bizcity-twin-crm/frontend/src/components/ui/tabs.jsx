import React from 'react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import { cn } from '../../lib/utils.js';

export const Tabs = TabsPrimitive.Root;

export const TabsList = React.forwardRef( ( { className, ...p }, r ) =>
	<TabsPrimitive.List ref={ r } className={ cn( 'bzc-rtabs-list', p.className ) } { ...p } />
);

export const TabsTrigger = React.forwardRef( ( { className, ...p }, r ) =>
	<TabsPrimitive.Trigger ref={ r } className={ cn( 'bzc-rtabs-trigger', p.className ) } { ...p } />
);

export const TabsContent = React.forwardRef( ( { className, ...p }, r ) =>
	<TabsPrimitive.Content ref={ r } className={ cn( 'bzc-rtabs-content', p.className ) } { ...p } />
);
