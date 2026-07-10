import React, { useState } from 'react';
import { Routes, Route, useParams, useNavigate } from 'react-router-dom';
import ChannelSidebar from '../../components/ChannelSidebar.jsx';
import ConversationList from '../../components/ConversationList.jsx';
import ConversationDetail from '../../components/ConversationDetail.jsx';
import ContactDrawer from '../../components/ContactDrawer.jsx';
// [2026-06-07 Johnny Chu] PHASE-0.40 G5.2 — Global search panel
import GlobalSearchPanel from '../../components/GlobalSearchPanel.jsx';

function InboxView() {
	const { inboxId, convId } = useParams();
	const navigate = useNavigate();
	// [2026-06-07 Johnny Chu] PHASE-0.40 G5.1 — "Gộp tài khoản" toggle (Deplao parity)
	// When merged=true, pass inboxId=0 to ConversationList so it loads ALL inboxes.
	const [ merged, setMerged ] = useState( false );
	const onSelectInbox = ( id ) => { setMerged( false ); navigate( `/inbox/${ id }` ); };
	const onSelectConv  = ( id, ibx ) => navigate( `/inbox/${ ibx || inboxId || 0 }/conv/${ id }` );
	const effectiveInboxId = merged ? 0 : ( Number( inboxId ) || 0 );

	return (
		<div className="bizcity-crm-root">
			<ChannelSidebar selectedInboxId={ Number( inboxId ) || 0 } onSelectInbox={ onSelectInbox } />
			<div style={ { display: 'flex', flexDirection: 'column', flex: 1, minWidth: 0 } }>
				{ /* G5.1 merged accounts toggle + G5.2 global search */ }
				<div style={ { padding: '4px 10px', borderBottom: '1px solid #f1f5f9', display: 'flex', alignItems: 'center', gap: 8, background: '#fff' } }>
					<label style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#64748b', cursor: 'pointer' } }>
						<input type="checkbox" checked={ merged } onChange={ ( e ) => setMerged( e.target.checked ) } />
						Gộp tài khoản
					</label>
					{ merged && <span style={ { fontSize: 11, color: '#3b82f6' } }>Đang hiển thị tất cả inbox</span> }
					<div style={ { marginLeft: 'auto' } }>
						<GlobalSearchPanel onSelectConv={ onSelectConv } />
					</div>
				</div>
				<ConversationList inboxId={ effectiveInboxId } selectedConvId={ Number( convId ) || 0 } onSelectConv={ onSelectConv } />
			</div>
			<ConversationDetail convId={ Number( convId ) || 0 } />
			<ContactDrawer convId={ Number( convId ) || 0 } onSelectConv={ onSelectConv } />
		</div>
	);
}

/**
 * Inbox panel (Workspace tab "inbox") — preserves the PHASE 0.34 4-pane layout.
 *
 * Routing notes:
 *   The shell mounts under `HashRouter`. The CRM SPA uses the hash for tab
 *   selection (`#tab=inbox`), so nested paths like `/inbox/:inboxId` rarely
 *   match. We add a wildcard `*` route as fallback so the 4-pane view always
 *   renders even when the hash is `tab=inbox` (or anything else); useParams
 *   simply returns `undefined` and the panes fall back to inboxId=0/convId=0.
 *   Deep-link support like `#/inbox/5/conv/12` still works via the explicit
 *   routes above the wildcard.
 */
export default function InboxPanel() {
	return (
		<Routes>
			<Route path="/inbox/:inboxId/conv/:convId" element={ <InboxView /> } />
			<Route path="/inbox/:inboxId" element={ <InboxView /> } />
			<Route path="/" element={ <InboxView /> } />
			<Route path="*" element={ <InboxView /> } />
		</Routes>
	);
}
