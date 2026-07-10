import React, { useEffect, useRef, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Search, Bell, User, ShieldCheck, Check, X } from 'lucide-react';
import { setCommandOpen } from '../redux/uiPrefs.js';
import { setActiveTab } from '../redux/uiTabs.js';
import { findNavItem } from './navConfig.js';
import ThemeToggle from './ThemeToggle.jsx';
import {
	crmApi,
	useGetAdminChatGrantsVersionQuery,
	useGetAdminChatGrantsQuery,
	useApproveAdminChatGrantMutation,
	useRevokeAdminChatGrantMutation,
} from '../redux/api/crmApi.js';

/**
 * Notifications bell — currently surfaces pending Admin Chat grants only.
 *
 * Cost-aware polling strategy:
 *  - Poll only the lightweight /version endpoint every 60s (returns
 *    { version, pending } — version bumps on any grant mutation server-side).
 *  - Full list query is fetched lazily only when the dropdown opens
 *    OR when version changes after first load (server cache invalidates too).
 *  - Server caches list per (version,status,limit) in object-cache; mutations
 *    bump version → cache key changes → fresh data, old key expires naturally.
 */
function NotificationsBell() {
	const dispatch = useDispatch();
	const [ open, setOpen ] = useState( false );
	const ref = useRef( null );
	const lastVersionRef = useRef( 0 );

	// Lightweight poll (1 SQL count + cached): used to drive the badge.
	const { data: ver } = useGetAdminChatGrantsVersionQuery( undefined, {
		pollingInterval: 60000,
		refetchOnMountOrArgChange: true,
	} );
	const count = ( ver && ver.pending ) || 0;
	const version = ( ver && ver.version ) || 0;

	// Heavy list: skip until user opens panel (or server tells us version changed).
	const { data, isFetching, refetch } = useGetAdminChatGrantsQuery(
		{ status: 'pending', limit: 20 },
		{ skip: ! open && lastVersionRef.current === 0 }
	);

	// When server-side version changes, drop cache so next open / next fetch is fresh.
	useEffect( () => {
		if ( version === 0 ) { return; }
		if ( lastVersionRef.current !== 0 && version !== lastVersionRef.current ) {
			dispatch( crmApi.util.invalidateTags( [ 'AdminChatGrant' ] ) );
		}
		lastVersionRef.current = version;
	}, [ version, dispatch ] );

	const [ approve, { isLoading: approving } ] = useApproveAdminChatGrantMutation();
	const [ revoke,  { isLoading: revoking  } ] = useRevokeAdminChatGrantMutation();

	const pending = ( data && data.rows ) || [];

	useEffect( () => {
		if ( ! open ) { return undefined; }
		const onDocClick = ( e ) => {
			if ( ref.current && ! ref.current.contains( e.target ) ) { setOpen( false ); }
		};
		document.addEventListener( 'mousedown', onDocClick );
		return () => document.removeEventListener( 'mousedown', onDocClick );
	}, [ open ] );

	const onApprove = async ( id ) => { try { await approve( { id } ).unwrap(); } catch ( e ) { /* noop */ } refetch(); };
	const onRevoke  = async ( id ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Thu hồi grant này?' ) ) { return; }
		try { await revoke( { id } ).unwrap(); } catch ( e ) { /* noop */ } refetch();
	};
	const openManager = () => { dispatch( setActiveTab( 'admin-chat-grants' ) ); setOpen( false ); };

	return (
		<div className="bzc-notif-wrap" ref={ ref }>
			<button
				type="button"
				className="bzc-icon-btn bzc-notif-btn"
				aria-label="Notifications"
				title={ count ? `${ count } grant chờ duyệt` : 'Thông báo' }
				onClick={ () => setOpen( ( v ) => ! v ) }
			>
				<Bell size={ 16 } />
				{ count > 0 && <span className="bzc-notif-dot">{ count > 99 ? '99+' : count }</span> }
			</button>
			{ open && (
				<div className="bzc-notif-panel" role="menu">
					<header className="bzc-notif-head">
						<strong>Thông báo</strong>
						<button type="button" className="bzc-link" onClick={ openManager }>Quản lý grants →</button>
					</header>
					<div className="bzc-notif-body">
						{ isFetching && pending.length === 0 && <div className="bzc-notif-empty">Đang tải…</div> }
						{ ! isFetching && pending.length === 0 && (
							<div className="bzc-notif-empty">Không có thông báo mới.</div>
						) }
						{ pending.map( ( g ) => (
							<div key={ g.id } className="bzc-notif-item">
								<div className="bzc-notif-icon"><ShieldCheck size={ 16 } /></div>
								<div className="bzc-notif-content">
									<div className="bzc-notif-title">
										Yêu cầu cấp quyền Admin Chat
									</div>
									<div className="bzc-notif-meta">
										<strong>{ g.user_name || ( '#' + g.user_id ) }</strong>
										{ ' → ' }
										<em>{ g.character_name || ( 'guru #' + g.character_id ) }</em>
										{ ' • ' }
										<code>{ g.platform }</code>
									</div>
									<div className="bzc-notif-actions">
										<button
											type="button"
											className="bzc-btn-primary bzc-btn-sm"
											disabled={ approving }
											onClick={ () => onApprove( g.id ) }
										>
											<Check size={ 12 } /> Duyệt
										</button>
										<button
											type="button"
											className="bzc-btn-ghost bzc-btn-sm"
											disabled={ revoking }
											onClick={ () => onRevoke( g.id ) }
										>
											<X size={ 12 } /> Từ chối
										</button>
									</div>
								</div>
							</div>
						) ) }
					</div>
				</div>
			) }
		</div>
	);
}

/**
 * TopBar — slim header above the workspace.
 * Shows breadcrumb of the active module + global ⌘K trigger + theme toggle + user.
 */
export default function TopBar() {
	const dispatch = useDispatch();
	const activeTab = useSelector( ( s ) => s.uiTabs.activeTab );
	const item = findNavItem( activeTab );

	return (
		<header className="bzc-topbar" role="banner">
			<div className="bzc-breadcrumb">
				{ item ? (
					<>
						<span className="bzc-muted">{ item.groupLabel }</span>
						<span className="bzc-breadcrumb-sep">/</span>
						<strong>{ item.label }</strong>
					</>
				) : <strong>Twin CRM</strong> }
			</div>

			<div className="bzc-topbar-actions">
				<button
					type="button"
					className="bzc-cmd-hint"
					onClick={ () => dispatch( setCommandOpen( true ) ) }
					title="Command palette (Ctrl/⌘ + K)"
				>
					<Search size={ 13 } />
					<span>Tìm kiếm…</span>
					<kbd>⌘K</kbd>
				</button>
				<NotificationsBell />
				<ThemeToggle />
				<button type="button" className="bzc-user-chip" title="Tài khoản">
					<span className="bzc-user-avatar"><User size={ 14 } /></span>
					<span className="bzc-user-name">Admin</span>
				</button>
			</div>
		</header>
	);
}

