import React from 'react';
import SideNav from './shell/SideNav.jsx';
import TopBar from './shell/TopBar.jsx';
import Workspace from './shell/Workspace.jsx';
import CommandPalette from './shell/CommandPalette.jsx';

/**
 * PHASE 0.35 M-FE.W17 — NextCRM-style left sidebar shell.
 * SideNav (grouped modules; Inbox now lives under the CRM group) +
 * TopBar (search / theme / user) + Workspace (active panel).
 * The legacy 4-pane Inbox lives inside the `inbox` panel unchanged.
 */
export default function App() {
	return (
		<div className="bzc-shell bzc-app-grid">
			<SideNav />
			<div className="bzc-app-main">
				<TopBar />
				<Workspace />
			</div>
			<CommandPalette />
		</div>
	);
}

