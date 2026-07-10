import React from 'react';
import { cn } from '../../lib/utils.js';

export const Table       = React.forwardRef( ( { className, ...p }, r ) => <table ref={ r } className={ cn( 'bzc-table', className ) } { ...p } /> );
export const TableHeader = React.forwardRef( ( { className, ...p }, r ) => <thead ref={ r } className={ cn( '', className ) } { ...p } /> );
export const TableBody   = React.forwardRef( ( { className, ...p }, r ) => <tbody ref={ r } className={ cn( '', className ) } { ...p } /> );
export const TableRow    = React.forwardRef( ( { className, ...p }, r ) => <tr ref={ r } className={ cn( 'bzc-row', className ) } { ...p } /> );
export const TableHead   = React.forwardRef( ( { className, ...p }, r ) => <th ref={ r } className={ cn( '', className ) } { ...p } /> );
export const TableCell   = React.forwardRef( ( { className, ...p }, r ) => <td ref={ r } className={ cn( '', className ) } { ...p } /> );
