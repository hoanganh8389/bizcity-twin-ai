import React, { useEffect, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { ChevronsLeft, ChevronsRight } from 'lucide-react';
import { setActiveTab } from '../redux/uiTabs.js';
import { NAV_GROUPS, ALL_NAV_ITEMS } from './navConfig.js';

const COLLAPSED_KEY = 'bzc-sidenav-collapsed';

/**
 * SideNav — NextCRM-style collapsible left rail with grouped sections.
 * Replaces the old top TabBar. Inbox now lives under the "CRM" group.
 */
export default function SideNav() {
	const dispatch = useDispatch();
	const activeTab = useSelector( ( s ) => s.uiTabs.activeTab );
	const [ collapsed, setCollapsed ] = useState( () => {
		try { return localStorage.getItem( COLLAPSED_KEY ) === '1'; } catch ( e ) { return false; }
	} );

	useEffect( () => {
		try { localStorage.setItem( COLLAPSED_KEY, collapsed ? '1' : '0' ); } catch ( e ) { /* noop */ }
		document.documentElement.dataset.sidenav = collapsed ? 'collapsed' : 'expanded';
	}, [ collapsed ] );

	// Hash sync (one-way out, two-way in).
	useEffect( () => {
		const readHash = () => {
			const m = ( window.location.hash || '' ).match( /tab=([a-z_-]+)/ );
			if ( m && ALL_NAV_ITEMS.find( ( t ) => t.id === m[ 1 ] ) ) {
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

	// `g + hotkey` jump.
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
				const t = ALL_NAV_ITEMS.find( ( x ) => x.hotkey === e.key.toLowerCase() );
				if ( t ) { dispatch( setActiveTab( t.id ) ); }
				pendingG = false;
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ dispatch ] );

	return (
		<aside className={ 'bzc-sidenav ' + ( collapsed ? 'is-collapsed' : '' ) } aria-label="Twin CRM navigation">
			<div className="bzc-sidenav-brand">
				<span className="bzc-brand-mark">B</span>
				{ ! collapsed && (
					<div className="bzc-sidenav-brand-text">
						<strong>Twin CRM</strong>
						<span className="bzc-muted">Workspace</span>
					</div>
				) }
			</div>

			<nav className="bzc-sidenav-scroll">
				{ NAV_GROUPS.map( ( group ) => (
					<div key={ group.id } className="bzc-sidenav-group">
						{ ! collapsed && <div className="bzc-sidenav-group-label">{ group.label }</div> }
						{ group.items.map( ( it ) => {
							const Icon = it.icon;
							const active = activeTab === it.id;
						// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — href items navigate twin shell, not dispatch tab
						const handleClick = it.href
							? () => { try { window.top.location.href = it.href; } catch ( e ) { window.location.href = it.href; } }
							: () => dispatch( setActiveTab( it.id ) );
						return (
							<button
								key={ it.id }
								type="button"
								role={ it.href ? 'link' : 'tab' }
								aria-selected={ active }
								className={ 'bzc-sidenav-item ' + ( active ? 'is-active' : '' ) }
								onClick={ handleClick }
								title={ collapsed ? it.label + ( it.hotkey ? '   (g+' + it.hotkey + ')' : '' ) : ( it.hotkey ? 'g + ' + it.hotkey : it.label ) }
							>
								<Icon size={ 16 } className="bzc-sidenav-icon" />
								{ ! collapsed && <span className="bzc-sidenav-label">{ it.label }</span> }
								{ ! collapsed && it.badge && <span className={ 'bzc-sidenav-badge' + ( it.badgeClass ? ' ' + it.badgeClass : '' ) }>{ it.badge }</span> }
								</button>
							);
						} ) }
					</div>
				) ) }
			</nav>

			<button
				type="button"
				className="bzc-sidenav-collapse"
				onClick={ () => setCollapsed( ( v ) => ! v ) }
				title={ collapsed ? 'Mở rộng' : 'Thu gọn' }
			>
				{ collapsed ? <ChevronsRight size={ 14 } /> : <><ChevronsLeft size={ 14 } /> <span>Thu gọn</span></> }
			</button>
		</aside>
	);
}
