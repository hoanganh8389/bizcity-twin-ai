import React, { Suspense, lazy } from 'react';
import { useSelector } from 'react-redux';

const DashboardTab    = lazy( () => import( '../routes/dashboard/DashboardTab.jsx' ) );
const FunnelPanel     = lazy( () => import( '../routes/funnel/FunnelPanel.jsx' ) );
const InboxPanel      = lazy( () => import( '../routes/inbox/InboxPanel.jsx' ) );
const AccountsTab     = lazy( () => import( '../routes/accounts/AccountsTab.jsx' ) );
const ContactsTab     = lazy( () => import( '../routes/contacts/ContactsTab.jsx' ) );
const SalesTab        = lazy( () => import( '../routes/sales/SalesTab.jsx' ) );
const InvoicesTab     = lazy( () => import( '../routes/invoices/InvoicesTab.jsx' ) );
const EmailTab        = lazy( () => import( '../routes/email/EmailTab.jsx' ) );
const TasksTab        = lazy( () => import( '../routes/tasks/TasksTab.jsx' ) );
const CalendarTab     = lazy( () => import( '../routes/calendar/CalendarTab.jsx' ) );
const DocumentsTab    = lazy( () => import( '../routes/documents/DocumentsTab.jsx' ) );
const ReportsTab      = lazy( () => import( '../routes/reports/ReportsTab.jsx' ) );
const AutomationTab   = lazy( () => import( '../routes/automation/AutomationTab.jsx' ) );
const CampaignsTab    = lazy( () => import( '../routes/campaigns/CampaignsTab.jsx' ) );
const LabelsTab       = lazy( () => import( '../routes/labels/LabelsTab.jsx' ) );
const MacrosTab       = lazy( () => import( '../routes/macros/MacrosTab.jsx' ) );
const AttrsTab        = lazy( () => import( '../routes/attributes/AttrsTab.jsx' ) );
const SlaTab          = lazy( () => import( '../routes/sla/SlaTab.jsx' ) );
const AuditTab        = lazy( () => import( '../routes/audit/AuditTab.jsx' ) );
const ChannelsTab     = lazy( () => import( '../routes/channels/ChannelsTab.jsx' ) );
const AdminChatGrantsTab = lazy( () => import( '../routes/admin-chat-grants/AdminChatGrantsTab.jsx' ) );
const BroadcastTab       = lazy( () => import( '../routes/broadcast/BroadcastTab.jsx' ) );
// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — care recipe tab (Path B)
const CrmCareTab         = lazy( () => import( '../routes/crm-care/CrmCareTab.jsx' ) );
// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — CF7 Submissions tab
const SubmissionsTab     = lazy( () => import( '../routes/submissions/SubmissionsTab.jsx' ) );
// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Zalo ZNS top-level panel (Automation rules group)
const ZnsPanel           = lazy( () => import( '../routes/broadcast/ZnsPanel.jsx' ) );
// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — "Việc của tôi" screen for sales reps
const MyWorkTab          = lazy( () => import( '../routes/mywork/MyWorkTab.jsx' ) );

const PANELS = {
	dashboard:  DashboardTab,
	funnel:     FunnelPanel,
	inbox:      InboxPanel,
	accounts:   AccountsTab,
	contacts:   ContactsTab,
	sales:      SalesTab,
	invoices:   InvoicesTab,
	email:      EmailTab,
	tasks:      TasksTab,
	calendar:   CalendarTab,
	documents:  DocumentsTab,
	reports:    ReportsTab,
	automation: AutomationTab,
	campaigns:  CampaignsTab,
	labels:     LabelsTab,
	macros:     MacrosTab,
	attrs:      AttrsTab,
	sla:        SlaTab,
	audit:      AuditTab,
	channels:   ChannelsTab,
	'admin-chat-grants': AdminChatGrantsTab,
	broadcast:           BroadcastTab,
	'zns-rules':         ZnsPanel,
	'crm-care':          CrmCareTab,
	submissions:         SubmissionsTab,
	mywork:              MyWorkTab,
};

function Loader() {
	return (
		<div className="bzc-pane-loader">
			<div className="bzc-spinner" />
			<span>Đang nạp panel…</span>
		</div>
	);
}

export default function Workspace() {
	const activeTab = useSelector( ( s ) => s.uiTabs.activeTab );
	const Panel = PANELS[ activeTab ] || DashboardTab;
	return (
		<div className="bzc-workspace" role="tabpanel" data-tab={ activeTab }>
			<Suspense fallback={ <Loader /> }>
				<Panel />
			</Suspense>
		</div>
	);
}
