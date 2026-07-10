/**
 * NAV_GROUPS — single source of truth for the left sidebar.
 * Each item.id maps 1:1 to a key in `Workspace.PANELS`.
 *
 * Tabs use `g + hotkey` shortcut (when not in a text input).
 */
// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — add HeartHandshake icon for crm-care tab
import {
	LayoutDashboard, Inbox, Users, Building2, Briefcase, FileText, FileSignature,
	Receipt, Mail, Calendar, CheckSquare, FolderOpen, BarChart3, Bot,
	Tag, Sparkles, Settings, Clock4, Activity, Plug, Megaphone, Filter, ShieldCheck, Send, HeartHandshake, ClipboardList, MessageSquare, Globe,
	// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — CheckCircle2 for "Việc của tôi"
	CheckCircle2,
} from 'lucide-react';

export const NAV_GROUPS = [
	{
		id: 'workspace',
		label: 'Workspace',
		items: [
			{ id: 'dashboard', label: 'Dashboard',     icon: LayoutDashboard, hotkey: 'd' },
			{ id: 'funnel',    label: 'Phễu marketing', icon: Filter,          hotkey: 'f' },
			// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — link to Web Builder, badge PRO, href navigates twin shell
			{ id: 'pagebuilder', label: 'Page Builder', icon: Globe, hotkey: 'x', badge: 'PRO', badgeClass: 'is-pro', href: '/twin/?plugin=web' },
			// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — "Việc của tôi" shortcut for sales reps
			{ id: 'mywork',    label: 'Việc của tôi',   icon: CheckCircle2,    hotkey: 'w' },
		],
	},
	{
		id: 'crm',
		label: 'CRM',
		items: [
			{ id: 'inbox',         label: 'Inbox',         icon: Inbox,        hotkey: 'i', badge: 'live' },
			{ id: 'accounts',      label: 'Accounts',      icon: Building2,    hotkey: 'b' },
			{ id: 'contacts',      label: 'Contacts',      icon: Users,          hotkey: 'n' },
			// [2026-06-25 Johnny Chu] PHASE-CRM-SUBMISSIONS — CF7 Submissions menu
			{ id: 'submissions',   label: 'Submissions',   icon: ClipboardList,  hotkey: 'q' },
			{ id: 'sales',         label: 'Sales pipeline', icon: Briefcase,     hotkey: 'p' },
			// Opps live inside Sales tab. Contracts shown there too.
		],
	},
	{
		id: 'finance',
		label: 'Finance',
		items: [
			{ id: 'invoices', label: 'Invoices', icon: Receipt, hotkey: 'v' },
		],
	},
	{
		id: 'communication',
		label: 'Automation rules',
		items: [
			{ id: 'email',     label: 'Email & CF7', icon: Mail,    hotkey: 'e' },
			// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Zalo ZNS top-level nav item
			{ id: 'zns-rules', label: 'Zalo ZNS',    icon: MessageSquare, hotkey: 'j' },
		],
	},
	{
		id: 'productivity',
		label: 'Productivity',
		items: [
			{ id: 'tasks',     label: 'Tasks',     icon: CheckSquare, hotkey: 'k' },
			{ id: 'calendar',  label: 'Calendar',  icon: Calendar,    hotkey: 'y' },
			{ id: 'documents', label: 'Documents', icon: FolderOpen,  hotkey: 'o' },
		],
	},
	{
		id: 'automation',
		label: 'Automation & Insights',
		items: [
			{ id: 'reports',    label: 'Reports',    icon: BarChart3,      hotkey: 'r' },
			{ id: 'automation', label: 'Automation', icon: Bot,            hotkey: 'a' },
			// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — CSKH care recipe tab (Path B)
			{ id: 'crm-care',   label: 'CSKH tự động', icon: HeartHandshake, hotkey: 'z' },
			{ id: 'campaigns',  label: 'Campaigns',  icon: Megaphone,      hotkey: 'g' },
			{ id: 'broadcast',  label: 'Broadcast',  icon: Send,           hotkey: 'w' },
		],
	},
	{
		id: 'settings',
		label: 'Settings',
		items: [
			{ id: 'labels',   label: 'Labels',           icon: Tag,      hotkey: 'l' },
			{ id: 'macros',   label: 'Macros',           icon: Sparkles, hotkey: 'm' },
			{ id: 'attrs',    label: 'Custom Attributes', icon: Settings, hotkey: 't' },
			{ id: 'sla',      label: 'SLA & Hours',      icon: Clock4,   hotkey: 's' },
			{ id: 'channels', label: 'Channels',         icon: Plug,     hotkey: 'c' },
			{ id: 'admin-chat-grants', label: 'Admin Chat Grants', icon: ShieldCheck, hotkey: 'h' },
			{ id: 'audit',    label: 'Audit log',        icon: Activity, hotkey: 'u' },
		],
	},
];

/** Flat list for hotkey + lookup. */
export const ALL_NAV_ITEMS = NAV_GROUPS.flatMap( ( g ) => g.items.map( ( i ) => ( { ...i, group: g.id, groupLabel: g.label } ) ) );

export function findNavItem( id ) {
	return ALL_NAV_ITEMS.find( ( i ) => i.id === id );
}
