// [2026-07-05 Johnny Chu] PHASE-0.46 M1 — "Việc của tôi" screen (Screen B) for sales reps
// Shows: overdue tasks, today's assigned submissions, personal pipeline summary

import React, { useState } from 'react';
import { AlertCircle, Clock, Target, RefreshCw, CheckCircle2, ExternalLink } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import {
	useGetCrmTasksQuery,
	useUpdateCrmTaskMutation,
	useGetCrmOpportunitiesQuery,
	useGetSubmissionsQuery,
	useUpdateSubmissionStatusMutation,
} from '../../redux/api/crmApi.js';
import { useDispatch } from 'react-redux';
import { setActiveTab } from '../../redux/uiTabs.js';

const BOOT = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT ) || {};
const IS_MANAGER = !! BOOT.isManager;

// ── Follow-status badge (inline, no dep on SubmissionsTab) ─────────────────────
function FollowBadge( { status } ) {
	const MAP = {
		new:           { label: 'Mới',         color: '#64748b', bg: '#f1f5f9' },
		contacted:     { label: 'Đã liên lạc', color: '#2563eb', bg: '#eff6ff' },
		qualified:     { label: 'Đủ ĐK',       color: '#16a34a', bg: '#f0fdf4' },
		proposal_sent: { label: 'Báo giá',     color: '#7c3aed', bg: '#f5f3ff' },
		negotiating:   { label: 'Đàm phán',    color: '#d97706', bg: '#fffbeb' },
		closed_won:    { label: 'Thành công',  color: '#15803d', bg: '#dcfce7' },
		closed_lost:   { label: 'Thất bại',    color: '#dc2626', bg: '#fef2f2' },
		invalid:       { label: 'Không HĐ',   color: '#94a3b8', bg: '#f8fafc' },
	};
	const s = MAP[ status ] || { label: status || '—', color: '#94a3b8', bg: '#f8fafc' };
	return <span style={ { fontSize: 10, padding: '2px 7px', borderRadius: 8, fontWeight: 600, color: s.color, background: s.bg } }>{ s.label }</span>;
}

// ── Stage mini-pill ────────────────────────────────────────────────────────────
function StagePill( { stage } ) {
	const MAP = {
		prospecting:   '#94a3b8',
		qualification: '#0ea5e9',
		proposal:      '#f59e0b',
		negotiation:   '#8b5cf6',
		closed_won:    '#22c55e',
		closed_lost:   '#ef4444',
	};
	const color = MAP[ stage ] || '#94a3b8';
	const label = stage ? stage.charAt( 0 ).toUpperCase() + stage.slice( 1 ).replace( '_', ' ' ) : '—';
	return (
		<span style={ { fontSize: 10, padding: '2px 7px', borderRadius: 8, fontWeight: 600, color, background: color + '22', border: '1px solid ' + color + '44' } }>
			{ label }
		</span>
	);
}

// ── Section wrapper ────────────────────────────────────────────────────────────
function Section( { icon, title, count, children, accent = '#64748b', emptyText } ) {
	return (
		<div style={ { marginBottom: 20, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10, overflow: 'hidden' } }>
			<div style={ { display: 'flex', alignItems: 'center', gap: 8, padding: '10px 16px', background: '#f8fafc', borderBottom: '1px solid #e2e8f0' } }>
				{ React.cloneElement( icon, { size: 14, style: { color: accent } } ) }
				<span style={ { fontSize: 13, fontWeight: 700, color: '#1e293b' } }>{ title }</span>
				{ count > 0 && (
					<span style={ { fontSize: 11, padding: '1px 7px', borderRadius: 10, background: accent + '22', color: accent, fontWeight: 600, marginLeft: 2 } }>{ count }</span>
				) }
			</div>
			<div style={ { padding: '10px 0' } }>
				{ count === 0
					? <div style={ { fontSize: 12, color: '#94a3b8', textAlign: 'center', padding: '16px 0' } }>{ emptyText || '—' }</div>
					: children }
			</div>
		</div>
	);
}

// ── Main component ─────────────────────────────────────────────────────────────

export default function MyWorkTab() {
	const dispatch = useDispatch();
	const today    = new Date().toISOString().slice( 0, 10 );
	const [ updateTask ] = useUpdateCrmTaskMutation();
	const [ updateStatus ] = useUpdateSubmissionStatusMutation();

	// Overdue tasks
	const { data: overdueTasksData, isFetching: ldTasks, refetch: refetchTasks } = useGetCrmTasksQuery(
		{ assignee_id: 'me', status: 'todo', due_before: today },
		{ refetchOnMountOrArgChange: true }
	);
	const overdueTasks = Array.isArray( overdueTasksData ) ? overdueTasksData : ( overdueTasksData?.tasks || [] );

	// Today's assigned submissions
	const { data: subData, isFetching: ldSubs, refetch: refetchSubs } = useGetSubmissionsQuery(
		{ assignee: 'me', follow_status: 'new,contacted', from: today, to: today, per_page: 50 },
		{ refetchOnMountOrArgChange: true }
	);
	const submissions = subData?.rows || [];

	// Personal pipeline
	const { data: opps = [], isFetching: ldOpps, refetch: refetchOpps } = useGetCrmOpportunitiesQuery(
		{ owner_id: 'me', status: 'open', limit: 100 },
		{ refetchOnMountOrArgChange: true }
	);

	const loading   = ldTasks || ldSubs || ldOpps;
	const totalValue = opps.reduce( ( s, o ) => s + parseFloat( o.amount || 0 ), 0 );

	const refetchAll = () => { refetchTasks(); refetchSubs(); refetchOpps(); };

	return (
		<div style={ { padding: 20, maxWidth: 900, margin: '0 auto' } }>
			{ /* Header */ }
			<div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 20 } }>
				<div>
					<h2 style={ { fontSize: 18, fontWeight: 700, color: '#1e293b', margin: 0 } }>Việc của tôi</h2>
					<p style={ { fontSize: 12, color: '#64748b', margin: '3px 0 0' } }>{ today } · Tasks quá hạn · Submissions được giao · Pipeline cá nhân</p>
				</div>
				<Button variant="ghost" size="sm" onClick={ refetchAll } disabled={ loading }>
					<RefreshCw size={ 13 } style={ { animation: loading ? 'spin 1s linear infinite' : 'none' } } />
				</Button>
			</div>

			{ /* ── Section 1: Overdue tasks ── */ }
			<Section
				icon={ <AlertCircle /> }
				title="Tasks quá hạn"
				count={ overdueTasks.length }
				accent="#dc2626"
				emptyText="Không có task quá hạn 🎉"
			>
				{ overdueTasks.map( ( t ) => (
					<div
						key={ t.id }
						style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 16px', borderBottom: '1px solid #f8fafc' } }
					>
						<button
							onClick={ () => updateTask( { id: t.id, status: 'done' } ).then( () => refetchTasks() ) }
							style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', flexShrink: 0 } }
							title="Đánh dấu xong"
						>
							<CheckCircle2 size={ 16 } />
						</button>
						<div style={ { flex: 1 } }>
							<div style={ { fontSize: 13, fontWeight: 600, color: '#1e293b' } }>{ t.title || 'Task #' + t.id }</div>
							{ t.body && <div style={ { fontSize: 11, color: '#64748b', marginTop: 2 } }>{ t.body }</div> }
						</div>
						<div style={ { flexShrink: 0, fontSize: 11, color: '#dc2626', display: 'flex', alignItems: 'center', gap: 3 } }>
							<Clock size={ 11 } />
							{ t.due_at ? t.due_at.slice( 0, 10 ) : '—' }
						</div>
					</div>
				) ) }
			</Section>

			{ /* ── Section 2: Today's assigned submissions ── */ }
			<Section
				icon={ <ExternalLink /> }
				title="Submissions được giao hôm nay"
				count={ submissions.length }
				accent="#2563eb"
				emptyText="Chưa có submission nào được giao hôm nay"
			>
				{ submissions.map( ( s ) => (
					<div
						key={ s.id }
						style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 16px', borderBottom: '1px solid #f8fafc', cursor: 'pointer' } }
						onClick={ () => dispatch( setActiveTab( 'submissions' ) ) }
					>
						<div style={ { flex: 1 } }>
							<div style={ { fontSize: 13, fontWeight: 600, color: '#1e293b' } }>
								{ s.contact_name || s.contact_email || s.contact_phone || ( 'Sub #' + s.id ) }
							</div>
							<div style={ { fontSize: 11, color: '#64748b', marginTop: 2 } }>
								{ s.contact_phone && <span style={ { marginRight: 8 } }>{ s.contact_phone }</span> }
								{ s.source_type && <span style={ { color: '#94a3b8' } }>{ s.source_type.replace( '_', ' ' ) }</span> }
							</div>
						</div>
						<div style={ { display: 'flex', gap: 6, alignItems: 'center', flexShrink: 0 } }>
							<FollowBadge status={ s.follow_status } />
							<select
								value={ s.follow_status || 'new' }
								onClick={ ( e ) => e.stopPropagation() }
								onChange={ ( e ) => {
									e.stopPropagation();
									updateStatus( { id: s.id, follow_status: e.target.value } ).then( () => refetchSubs() );
								} }
								style={ { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 6, padding: '2px 6px', color: '#475569' } }
							>
								<option value="new">Mới</option>
								<option value="contacted">Đã liên lạc</option>
								<option value="qualified">Đủ ĐK</option>
								<option value="proposal_sent">Báo giá</option>
								<option value="negotiating">Đàm phán</option>
								<option value="closed_won">Thành công</option>
								<option value="closed_lost">Thất bại</option>
								<option value="invalid">Không HĐ</option>
							</select>
						</div>
					</div>
				) ) }
			</Section>

			{ /* ── Section 3: Personal pipeline summary ── */ }
			<Section
				icon={ <Target /> }
				title="Pipeline của tôi"
				count={ opps.length }
				accent="#7c3aed"
				emptyText="Chưa có cơ hội nào. Chuyển submissions sang Pipeline để bắt đầu."
			>
				{ /* Pipeline summary bar */ }
				{ opps.length > 0 && (
					<div style={ { padding: '8px 16px 4px', borderBottom: '1px solid #f1f5f9', display: 'flex', gap: 16, fontSize: 12, color: '#475569' } }>
						<span>{ opps.length } cơ hội</span>
						<span>·</span>
						<span>{ new Intl.NumberFormat( 'vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 } ).format( totalValue ) }</span>
						<button
							onClick={ () => dispatch( setActiveTab( 'sales' ) ) }
							style={ { marginLeft: 'auto', fontSize: 11, color: '#7c3aed', background: 'none', border: 'none', cursor: 'pointer' } }
						>
							Mở Kanban →
						</button>
					</div>
				) }
				{ opps.map( ( o ) => (
					<div
						key={ o.id }
						style={ { display: 'flex', alignItems: 'center', gap: 10, padding: '8px 16px', borderBottom: '1px solid #f8fafc', cursor: 'pointer' } }
						onClick={ () => dispatch( setActiveTab( 'sales' ) ) }
					>
						<div style={ { flex: 1 } }>
							<div style={ { fontSize: 13, fontWeight: 600, color: '#1e293b' } }>{ o.name || ( 'Opp #' + o.id ) }</div>
							{ o.close_date && (
								<div style={ { fontSize: 11, color: '#94a3b8', marginTop: 2 } }>
									<Clock size={ 10 } style={ { display: 'inline', marginRight: 3 } } />
									Close: { o.close_date.slice( 0, 10 ) }
								</div>
							) }
						</div>
						<div style={ { display: 'flex', gap: 8, alignItems: 'center', flexShrink: 0 } }>
							{ o.amount > 0 && (
								<span style={ { fontSize: 12, fontWeight: 600, color: '#1e293b' } }>
									{ new Intl.NumberFormat( 'vi-VN', { style: 'currency', currency: 'VND', maximumFractionDigits: 0 } ).format( o.amount ) }
								</span>
							) }
							<StagePill stage={ o.stage } />
						</div>
					</div>
				) ) }
			</Section>
		</div>
	);
}
