import React, { useEffect } from 'react';
import { useSelector, useDispatch } from 'react-redux';
import { setActiveTab } from '../redux/uiTabs.js';
import { setCommandOpen } from '../redux/uiPrefs.js';
import ThemeToggle from './ThemeToggle.jsx';

export const TABS = [
	{ id: 'inbox',      label: 'Inbox',       hotkey: 'i' },
	{ id: 'sales',      label: 'Sales',       hotkey: 'p' },
	{ id: 'invoices',   label: 'Invoices',    hotkey: 'v' },
	{ id: 'email',      label: 'Email',       hotkey: 'e' },
	{ id: 'reports',    label: 'Reports',     hotkey: 'r' },
	{ id: 'automation', label: 'Automation',  hotkey: 'a' },
	{ id: 'labels',     label: 'Labels',      hotkey: 'l' },
	{ id: 'macros',     label: 'Macros',      hotkey: 'm' },
	{ id: 'attrs',      label: 'Custom Attrs', hotkey: 't' },
	{ id: 'sla',        label: 'SLA & Hours', hotkey: 's' },
	{ id: 'audit',      label: 'Audit',       hotkey: 'u' },
	{ id: 'channels',   label: 'Channels',    hotkey: 'c' },
];

/**
 * Top tab bar — TwinChat-style horizontal nav. Sticky, slate-200 bottom border.
 * Syncs with URL hash `#tab=<id>` for deep-link share.
 */
export default function TabBar() {
	const dispatch = useDispatch();
	const activeTab = useSelector( ( s ) => s.uiTabs.activeTab );

	// Hash sync (one-way out, two-way in).
	useEffect( () => {
		const readHash = () => {
			const m = ( window.location.hash || '' ).match( /tab=([a-z_-]+)/ );
			if ( m && TABS.find( ( t ) => t.id === m[ 1 ] ) ) {
				dispatch( setActiveTab( m[ 1 ] ) );
			}
		};
		readHash();
		window.addEventListener( 'hashchange', readHash );
		return () => window.removeEventListener( 'hashchange', readHash );
	}, [ dispatch ] );

	useEffect( () => {
		const cur = ( window.location.hash || '' ).match( /tab=([a-z_-]+)/ );
		if ( ! cur || cur[ 1 ] !== activeTab ) {
			const next = '#tab=' + activeTab;
			if ( window.location.hash !== next ) {
				history.replaceState( null, '', next );
			}
		}
	}, [ activeTab ] );

	// Keyboard: g + <hotkey> jump (Chatwoot parity).
	useEffect( () => {
		let pendingG = false;
		let timer = null;
		const onKey = ( e ) => {
			if ( e.target && /^(INPUT|TEXTAREA|SELECT)$/i.test( e.target.tagName ) ) { return; }
			if ( e.metaKey || e.ctrlKey || e.altKey ) { return; }
			if ( e.key === 'g' ) {
				pendingG = true;
				clearTimeout( timer );
				timer = setTimeout( () => ( pendingG = false ), 900 );
				return;
			}
			if ( pendingG ) {
				const t = TABS.find( ( x ) => x.hotkey === e.key.toLowerCase() );
				if ( t ) { dispatch( setActiveTab( t.id ) ); }
				pendingG = false;
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ dispatch ] );

	return (
		<nav className="bzc-tabbar" role="tablist" aria-label="CRM workspace">
			<div className="bzc-tabbar-brand">
				<span className="bzc-brand-mark">B</span>
				<span className="bzc-brand-text">Twin CRM</span>
				<span className="bzc-brand-sub">Inbox console</span>
			</div>
			<div className="bzc-tabbar-tabs">
				{ TABS.map( ( t ) => (
					<button
						key={ t.id }
						role="tab"
						aria-selected={ activeTab === t.id }
						className={ 'bzc-tab ' + ( activeTab === t.id ? 'is-active' : '' ) }
						onClick={ () => dispatch( setActiveTab( t.id ) ) }
						title={ `g + ${ t.hotkey }` }
					>
						{ t.label }
					</button>
				) ) }
			</div>
			<div className="bzc-tabbar-actions">
				<button
					type="button"
					className="bzc-cmd-hint"
					onClick={ () => dispatch( setCommandOpen( true ) ) }
					title="Command palette (Ctrl/⌘ + K)"
				>
					<span>Tìm kiếm</span>
					<kbd>⌘K</kbd>
				</button>
				<ThemeToggle />
			</div>
		</nav>
	);
}
