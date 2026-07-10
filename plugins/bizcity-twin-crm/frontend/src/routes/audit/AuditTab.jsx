import React from 'react';
import { Activity, ExternalLink, ShieldCheck, Construction } from 'lucide-react';

/**
 * Audit tab — pointer to WP-Admin Intent Monitor.
 *
 * `/events` REST cho FE sẽ được mở ở M-FE.W7. Trong khi chờ, tab này hiển thị
 * 2 deep-link tiện lợi: Intent Monitor (full event_stream) + Phase 0.35 Diag.
 */
export default function AuditTab() {
	const boot     = window.BIZCITY_CRM_BOOT || {};
	const monitorUrl = boot.intentMonitorUrl
		|| '/wp-admin/admin.php?page=bizcity-twin-ai-intent-monitor';
	const diagUrl    = boot.crmDiagUrl
		|| '/wp-admin/admin.php?page=bizcity-twin-crm-diagnostic';

	const Card = ( { Icon, title, desc, href, cta } ) => (
		<a
			href={ href }
			target="_blank"
			rel="noreferrer"
			className="group flex flex-col rounded-lg border border-gray-200 bg-white p-4 hover:border-indigo-400 hover:shadow-sm transition"
		>
			<div className="flex items-center gap-2 text-indigo-600">
				<Icon size={ 18 } />
				<h3 className="font-semibold text-gray-900">{ title }</h3>
			</div>
			<p className="mt-2 text-sm text-gray-600 flex-1">{ desc }</p>
			<div className="mt-3 inline-flex items-center gap-1 text-xs text-indigo-600 font-medium group-hover:translate-x-0.5 transition">
				{ cta } <ExternalLink size={ 12 } />
			</div>
		</a>
	);

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title flex items-center gap-2">
						<Activity size={ 20 } /> Audit
					</h2>
					<p className="bzc-tabpane-subtitle">
						Toàn bộ <code>event_stream</code> CRM (label assigned, sla breached, automation fired…) đã được phơi bày trong Intent Monitor.
					</p>
				</div>
			</header>

			<div className="grid grid-cols-1 md:grid-cols-2 gap-3">
				<Card
					Icon={ Activity }
					title="CRM Audit · Intent Monitor"
					desc="Bộ lọc theo type / parent_uuid / user — xem chain trace từ inbound → automation → outbound. Hỗ trợ export CSV."
					href={ monitorUrl }
					cta="Mở Intent Monitor"
				/>
				<Card
					Icon={ ShieldCheck }
					title="Phase 0.35 Diagnostic"
					desc="Bảng PASS/FAIL từng probe (REST · DB · hook · permission) — verify schema + endpoint trước khi deploy."
					href={ diagUrl }
					cta="Mở Diagnostic"
				/>
			</div>

			<div className="mt-4 rounded-lg border border-dashed border-amber-300 bg-amber-50/50 p-3 flex gap-2 items-start text-xs text-amber-800">
				<Construction size={ 14 } className="mt-0.5 shrink-0" />
				<div>
					<strong>Roadmap M-FE.W7:</strong> REST <code>GET /events?type_prefix=crm_&limit=50&parent_uuid=…</code> sẽ được giải khoá để render Audit Timeline trực tiếp trong SPA (component <code>AuditTimeline.jsx</code> đã sẵn ở M-FE.W10).
				</div>
			</div>
		</div>
	);
}
