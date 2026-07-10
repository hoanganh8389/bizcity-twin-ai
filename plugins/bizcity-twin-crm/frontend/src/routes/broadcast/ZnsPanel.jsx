/**
 * ZnsPanel — wrapper panel cho sidebar nav "Zalo ZNS"
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Top-level ZNS panel in Automation rules group.
 * Chứa 3 sub-tabs: Trigger Rules | CF7 Automation | Thống kê
 */
import React, { useState } from 'react';
import ZnsTriggerTab    from '../email/ZnsTriggerTab.jsx';
import ZnsAutomationTab from '../email/ZnsAutomationTab.jsx';
import ZnsTab           from '../email/ZnsTab.jsx';

const TABS = [
{ id: 'trigger', label: '⚡ Trigger Rules' },
{ id: 'cf7',     label: '🤖 CF7 ZNS' },
{ id: 'stats',   label: '📊 Thống kê' },
];

export default function ZnsPanel() {
const [ tab, setTab ] = useState( 'trigger' );

return (
<div className="bzc-pane">
<header className="bzc-pane-header" style={ { padding: '12px 20px 0', borderBottom: '1px solid #e2e8f0' } }>
<div style={ { display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', marginBottom: 0 } }>
<div>
<h2 style={ { fontWeight: 700, fontSize: 15, margin: 0 } }>Zalo ZNS Automation</h2>
<p style={ { fontSize: 12, color: '#64748b', margin: '2px 0 0' } }>Quy tắc gửi Zalo Notification Service theo sự kiện hoặc form CF7</p>
</div>
</div>
<div style={ { display: 'flex', gap: 0, marginTop: 10 } }>
{ TABS.map( ( t ) => (
<button
key={ t.id }
onClick={ () => setTab( t.id ) }
style={ {
padding: '8px 18px', fontSize: 13, cursor: 'pointer',
background: 'none', border: 'none',
borderBottom: tab === t.id ? '2px solid #6366f1' : '2px solid transparent',
color: tab === t.id ? '#4338ca' : '#64748b',
fontWeight: tab === t.id ? 700 : 400,
marginBottom: -1,
} }
>{ t.label }</button>
) ) }
</div>
</header>

<div className="bzc-pane-body" style={ { padding: '16px 20px' } }>
{ tab === 'trigger' && <ZnsTriggerTab /> }
{ tab === 'cf7'     && <ZnsAutomationTab /> }
{ tab === 'stats'   && <ZnsTab /> }
</div>
</div>
);
}