import React from 'react';
import { Plus, Pencil, Trash2, RotateCcw, User } from 'lucide-react';
import { Badge } from '../ui/card.jsx';

const ICONS = { created: Plus, updated: Pencil, deleted: Trash2, restored: RotateCcw };
const VARIANTS = { created: 'ok', updated: 'default', deleted: 'danger', restored: 'warn' };

function timeAgo( iso ) {
	const ms = Date.now() - new Date( iso ).getTime();
	const m = Math.floor( ms / 60000 );
	if ( m < 1 ) { return 'vừa xong'; }
	if ( m < 60 ) { return m + ' phút'; }
	const h = Math.floor( m / 60 );
	if ( h < 24 ) { return h + ' giờ'; }
	return Math.floor( h / 24 ) + ' ngày';
}

function ChangeRow( { field, old, next } ) {
	return (
		<div className="bzc-audit-change">
			<code className="bzc-audit-field">{ field }</code>
			<span className="bzc-audit-old">{ old === null || old === undefined ? '—' : String( old ) }</span>
			<span className="bzc-audit-arrow">→</span>
			<span className="bzc-audit-new">{ next === null || next === undefined ? '—' : String( next ) }</span>
		</div>
	);
}

/**
 * AuditEntry — single timeline node.
 * Pattern ported from NextCRM `components/crm/audit-log/Entry.tsx`.
 */
export function AuditEntry( { entry } ) {
	const Icon = ICONS[ entry.action ] || Pencil;
	const v = VARIANTS[ entry.action ] || 'default';
	const changes = entry.changes && typeof entry.changes === 'object' ? Object.entries( entry.changes ) : [];
	return (
		<div className="bzc-audit-entry">
			<div className={ 'bzc-audit-dot bzc-audit-dot-' + v }><Icon size={ 12 } /></div>
			<div className="bzc-audit-card">
				<div className="bzc-audit-head">
					<Badge variant={ v }>{ entry.action }</Badge>
					<span className="bzc-audit-user"><User size={ 11 } /> { entry.user || 'system' }</span>
					<span className="bzc-audit-time">{ timeAgo( entry.created_at ) } trước</span>
				</div>
				{ changes.length > 0 && (
					<div className="bzc-audit-changes">
						{ changes.map( ( [ field, diff ] ) => (
							<ChangeRow key={ field } field={ field } old={ diff?.old } next={ diff?.new } />
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}

/**
 * AuditTimeline — vertical timeline of changes for a single entity.
 */
export default function AuditTimeline( { entries = [], emptyMessage = 'Chưa có lịch sử thay đổi.' } ) {
	if ( ! entries.length ) {
		return <div className="bzc-empty bzc-muted">{ emptyMessage }</div>;
	}
	return (
		<div className="bzc-audit-timeline">
			{ entries.map( ( e ) => <AuditEntry key={ e.id } entry={ e } /> ) }
		</div>
	);
}
