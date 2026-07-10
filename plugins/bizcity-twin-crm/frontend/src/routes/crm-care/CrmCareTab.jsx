/**
 * CrmCareTab — "Tự động hoá CSKH" tab in the CRM SPA.
 *
 * CRM-PATH-3 spec (PHASE-0.41):
 * - Recipe gallery: browse care templates (zone=crm, is_template=1), click "Kích hoạt" to
 *   instantiate → creates a workflow on this site.
 * - My recipes: list instantiated workflows (zone=crm, is_template=0) with:
 *     • Enable/disable toggle
 *     • Bind-to-channel button (picks Zone-1 inbox from site inboxes)
 *     • View run history (read-only drawer)
 *
 * Users with `bizcity_crm_manage` cap (Path B) can use this tab.
 * Users with `manage_options` (Path A admin) also see it.
 * Neither can open the ReactFlow canvas from here — this is read/bind only.
 *
 * [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — create care recipe FE surface
 */
import React, { useState } from 'react';
import { Bot, Plus, Power, PowerOff, Link2, Clock, ChevronDown, ChevronUp, AlertCircle, CheckCircle2, Loader2, Zap } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Card, CardHeader, CardTitle, CardBody, Badge } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import {
	useListCrmTemplatesQuery,
	useListCrmWorkflowsQuery,
	useCrmInstantiateTemplateMutation,
	useBindWorkflowMutation,
	useUpdateWorkflowMutation,
	useListCrmRunsQuery,
} from '../../redux/api/automationCareApi.js';
import { useGetInboxesQuery } from '../../redux/api/crmApi.js';

/* ─────────────────────────── helpers ─────────────────────────── */

/** Human-friendly label for a category slug */
function categoryLabel( cat ) {
	const MAP = {
		care:  'Chăm sóc KH',
		cskh:  'Chăm sóc KH',
		reply: 'Trả lời tự động',
		route: 'Phân loại / chuyển tiếp',
		tag:   'Gán nhãn',
	};
	return MAP[ cat ] || cat || 'Công thức';
}

/** Zone-1 platform codes (customer channels only — R-ZONE) */
const ZONE1_CODES = new Set( [ 'zalo_oa', 'zalo_personal', 'facebook', 'messenger', 'webchat', 'email' ] );

/* ─────────────────────────── sub-components ──────────────────── */

/**
 * TemplateCard — one care template in the recipe gallery.
 */
function TemplateCard( { tpl, onActivate, activating } ) {
	return (
		<Card className="bzc-care-tpl-card">
			<CardHeader>
				<div className="bzc-care-tpl-icon">
					<Zap size={ 18 } />
				</div>
				<div className="bzc-care-tpl-meta">
					<CardTitle>{ tpl.name }</CardTitle>
					<Badge variant="muted">{ categoryLabel( tpl.category ) }</Badge>
				</div>
			</CardHeader>
			<CardBody>
				{ tpl.description && <p className="bzc-care-tpl-desc">{ tpl.description }</p> }
				<Button
					variant="primary"
					size="sm"
					disabled={ activating }
					onClick={ () => onActivate( tpl ) }
				>
					{ activating ? <Loader2 size={ 14 } className="bzc-spin" /> : <Plus size={ 14 } /> }
					Kích hoạt công thức
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * RunHistoryDrawer — read-only run log for a recipe.
 */
function RunHistoryDrawer( { workflow, open, onClose } ) {
	const { data, isFetching } = useListCrmRunsQuery(
		{ workflowId: workflow ? workflow.id : 0 },
		{ skip: ! open || ! workflow }
	);
	const runs = ( data && Array.isArray( data.rows ) ) ? data.rows : [];

	return (
		<Sheet open={ open } onOpenChange={ ( v ) => { if ( ! v ) onClose(); } }>
			<SheetContent side="right" className="bzc-care-runs-sheet">
				<SheetHeader>
					<SheetTitle>
						Lịch sử chạy{ workflow ? ' — ' + workflow.name : '' }
					</SheetTitle>
				</SheetHeader>
				<SheetBody>
					{ isFetching && (
						<div className="bzc-care-runs-loading">
							<Loader2 size={ 18 } className="bzc-spin" /> Đang tải…
						</div>
					) }
					{ ! isFetching && runs.length === 0 && (
						<p className="bzc-muted">Chưa có lần chạy nào.</p>
					) }
					{ runs.map( ( run ) => (
						<div key={ run.id } className="bzc-care-run-row">
							<div className="bzc-care-run-status">
								{ run.status === 'done' || run.status === 'success'
									? <CheckCircle2 size={ 14 } className="bzc-color-ok" />
									: run.status === 'failed'
										? <AlertCircle size={ 14 } className="bzc-color-danger" />
										: <Clock size={ 14 } className="bzc-muted-icon" />
								}
								<span className="bzc-care-run-label">
									{ run.status || 'running' }
								</span>
							</div>
							<div className="bzc-care-run-time bzc-muted">
								{ run.created_at ? new Date( run.created_at * 1000 ).toLocaleString( 'vi-VN' ) : '—' }
							</div>
							{ run.trigger_summary && (
								<div className="bzc-care-run-trigger bzc-muted">
									{ run.trigger_summary }
								</div>
							) }
						</div>
					) ) }
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

/**
 * BindChannelDrawer — let user pick a Zone-1 inbox to bind a recipe to.
 */
function BindChannelDrawer( { workflow, open, onClose, onBind, binding } ) {
	const [ selectedInboxId, setSelectedInboxId ] = useState( '' );
	const { data: inboxes = [] } = useGetInboxesQuery( undefined, { skip: ! open } );
	const zone1Inboxes = inboxes.filter( ( ix ) => ZONE1_CODES.has( ix.platform_code || ix.channel_type || '' ) );

	function handleBind() {
		if ( ! selectedInboxId ) { return; }
		const inbox = zone1Inboxes.find( ( ix ) => String( ix.id ) === String( selectedInboxId ) );
		onBind( {
			workflowId: workflow.id,
			inbox_id:   Number( selectedInboxId ),
			platform_code: inbox ? ( inbox.platform_code || inbox.channel_type ) : '',
		} );
	}

	return (
		<Sheet open={ open } onOpenChange={ ( v ) => { if ( ! v ) { onClose(); setSelectedInboxId( '' ); } } }>
			<SheetContent side="right" className="bzc-care-bind-sheet">
				<SheetHeader>
					<SheetTitle>
						Gắn kênh{ workflow ? ' — ' + workflow.name : '' }
					</SheetTitle>
				</SheetHeader>
				<SheetBody>
					<p className="bzc-care-bind-hint">
						Chọn Inbox (Zone-1 kênh khách hàng) để công thức này tự động chạy khi nhận tin nhắn.
					</p>
					{ zone1Inboxes.length === 0 && (
						<p className="bzc-muted">Chưa có kênh Zone-1 nào được kết nối.</p>
					) }
					{ zone1Inboxes.map( ( ix ) => (
						<label key={ ix.id } className="bzc-care-inbox-option">
							<input
								type="radio"
								name="bind-inbox"
								value={ ix.id }
								checked={ String( selectedInboxId ) === String( ix.id ) }
								onChange={ () => setSelectedInboxId( ix.id ) }
							/>
							<span>{ ix.name || ix.channel_identifier || ix.id }</span>
							<Badge variant="muted" className="bzc-care-inbox-code">
								{ ix.platform_code || ix.channel_type }
							</Badge>
						</label>
					) ) }
					<div className="bzc-care-bind-actions">
						<Button
							variant="primary"
							disabled={ ! selectedInboxId || binding }
							onClick={ handleBind }
						>
							{ binding ? <Loader2 size={ 14 } className="bzc-spin" /> : <Link2 size={ 14 } /> }
							Gắn kênh
						</Button>
						<Button variant="ghost" onClick={ onClose }>Huỷ</Button>
					</div>
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

/**
 * WorkflowRow — one instantiated recipe in "My recipes" list.
 */
function WorkflowRow( { wf, onToggle, onBind, onHistory, toggling } ) {
	const isEnabled = Boolean( wf.enabled );
	const bindings = ( wf.channel_bindings && Array.isArray( wf.channel_bindings ) )
		? wf.channel_bindings
		: [];

	return (
		<Card className={ 'bzc-care-wf-card' + ( isEnabled ? ' is-enabled' : ' is-disabled' ) }>
			<div className="bzc-care-wf-header">
				<div className="bzc-care-wf-icon">
					<Bot size={ 16 } />
				</div>
				<div className="bzc-care-wf-name">{ wf.name }</div>
				<div className="bzc-care-wf-badges">
					<Badge variant={ isEnabled ? 'ok' : 'muted' }>
						{ isEnabled ? 'Đang hoạt động' : 'Tắt' }
					</Badge>
					{ bindings.map( ( b, i ) => (
						<Badge key={ i } variant="muted">{ b.platform_code || b.inbox_id }</Badge>
					) ) }
				</div>
			</div>
			<div className="bzc-care-wf-actions">
				<Button
					variant="ghost"
					size="sm"
					disabled={ toggling }
					onClick={ () => onToggle( wf ) }
					title={ isEnabled ? 'Tắt công thức' : 'Bật công thức' }
				>
					{ toggling
						? <Loader2 size={ 14 } className="bzc-spin" />
						: isEnabled ? <PowerOff size={ 14 } /> : <Power size={ 14 } />
					}
					{ isEnabled ? 'Tắt' : 'Bật' }
				</Button>
				<Button
					variant="ghost"
					size="sm"
					onClick={ () => onBind( wf ) }
					title="Gắn kênh khách hàng"
				>
					<Link2 size={ 14 } /> Kênh
				</Button>
				<Button
					variant="ghost"
					size="sm"
					onClick={ () => onHistory( wf ) }
					title="Xem lịch sử chạy"
				>
					<Clock size={ 14 } /> Lịch sử
				</Button>
			</div>
		</Card>
	);
}

/* ─────────────────────────── main tab ────────────────────────── */

export default function CrmCareTab() {
	const { data: templates = [], isLoading: loadingTpl, error: tplErr } = useListCrmTemplatesQuery();
	const { data: workflows = [], isLoading: loadingWf,  error: wfErr,  refetch: refetchWf } = useListCrmWorkflowsQuery();

	const [ instantiate, { isLoading: instantiating, error: instantiateErr } ] = useCrmInstantiateTemplateMutation();
	const [ bind,        { isLoading: binding } ]                               = useBindWorkflowMutation();
	const [ update,      { isLoading: updating } ]                              = useUpdateWorkflowMutation();

	// Per-item state for loading spinners
	const [ activatingId, setActivatingId ]   = useState( null );
	const [ togglingId,   setTogglingId ]      = useState( null );

	// Drawer states
	const [ bindTarget,    setBindTarget ]    = useState( null );
	const [ historyTarget, setHistoryTarget ] = useState( null );

	// Section collapse
	const [ galleryOpen, setGalleryOpen ] = useState( true );

	/* --- handlers --- */

	async function handleActivate( tpl ) {
		setActivatingId( tpl.id );
		try {
			await instantiate( { templateId: tpl.id } ).unwrap();
			refetchWf();
		} catch ( e ) {
			// eslint-disable-next-line no-console
			console.error( 'CrmCareTab: instantiate failed', e );
		} finally {
			setActivatingId( null );
		}
	}

	async function handleToggle( wf ) {
		setTogglingId( wf.id );
		try {
			await update( { id: wf.id, enabled: ! wf.enabled } ).unwrap();
		} catch ( e ) {
			console.error( 'CrmCareTab: toggle failed', e );
		} finally {
			setTogglingId( null );
		}
	}

	async function handleBind( args ) {
		try {
			await bind( args ).unwrap();
			setBindTarget( null );
		} catch ( e ) {
			console.error( 'CrmCareTab: bind failed', e );
		}
	}

	/* --- render --- */

	return (
		<div className="bzc-care-tab">
			<div className="bzc-care-topbar">
				<h2 className="bzc-care-heading">
					<Bot size={ 20 } /> Tự động hoá CSKH
				</h2>
				<p className="bzc-care-subhead bzc-muted">
					Chọn công thức chăm sóc khách hàng, gắn vào kênh Zone-1, rồi bật. Không cần lập trình.
				</p>
			</div>

			{ /* ── Recipe gallery ── */ }
			<section className="bzc-care-section">
				<button
					type="button"
					className="bzc-care-section-toggle"
					onClick={ () => setGalleryOpen( ( v ) => ! v ) }
				>
					<span>Bộ công thức có sẵn</span>
					{ galleryOpen ? <ChevronUp size={ 15 } /> : <ChevronDown size={ 15 } /> }
				</button>

				{ galleryOpen && (
					<>
						{ ( loadingTpl ) && (
							<div className="bzc-care-loading">
								<Loader2 size={ 18 } className="bzc-spin" /> Đang nạp công thức…
							</div>
						) }
						{ tplErr && (
							<p className="bzc-care-error">
								<AlertCircle size={ 14 } /> Không nạp được danh sách công thức.
							</p>
						) }
						{ instantiateErr && (
							<p className="bzc-care-error">
								<AlertCircle size={ 14 } />{ ' ' }
								{ instantiateErr.data && instantiateErr.data.message
									? instantiateErr.data.message
									: 'Kích hoạt thất bại. Thử lại.' }
							</p>
						) }
						{ ! loadingTpl && templates.length === 0 && ! tplErr && (
							<p className="bzc-muted">Chưa có công thức CSKH nào. Quản trị viên có thể thêm qua Automation Builder.</p>
						) }
						<div className="bzc-care-tpl-grid">
							{ templates.map( ( tpl ) => (
								<TemplateCard
									key={ tpl.id }
									tpl={ tpl }
									activating={ activatingId === tpl.id && instantiating }
									onActivate={ handleActivate }
								/>
							) ) }
						</div>
					</>
				) }
			</section>

			{ /* ── My recipes ── */ }
			<section className="bzc-care-section">
				<div className="bzc-care-section-header">
					<span>Công thức của tôi</span>
					{ workflows.length > 0 && (
						<Badge variant="muted">{ workflows.length }</Badge>
					) }
				</div>

				{ ( loadingWf ) && (
					<div className="bzc-care-loading">
						<Loader2 size={ 18 } className="bzc-spin" /> Đang nạp…
					</div>
				) }
				{ wfErr && (
					<p className="bzc-care-error">
						<AlertCircle size={ 14 } /> Không nạp được danh sách công thức đã kích hoạt.
					</p>
				) }
				{ ! loadingWf && workflows.length === 0 && ! wfErr && (
					<p className="bzc-muted">Chưa có công thức nào được kích hoạt. Kích hoạt từ bộ có sẵn phía trên.</p>
				) }
				<div className="bzc-care-wf-list">
					{ workflows.map( ( wf ) => (
						<WorkflowRow
							key={ wf.id }
							wf={ wf }
							toggling={ togglingId === wf.id && updating }
							onToggle={ handleToggle }
							onBind={ setBindTarget }
							onHistory={ setHistoryTarget }
						/>
					) ) }
				</div>
			</section>

			{ /* ── Drawers ── */ }
			<BindChannelDrawer
				workflow={ bindTarget }
				open={ Boolean( bindTarget ) }
				onClose={ () => setBindTarget( null ) }
				onBind={ handleBind }
				binding={ binding }
			/>
			<RunHistoryDrawer
				workflow={ historyTarget }
				open={ Boolean( historyTarget ) }
				onClose={ () => setHistoryTarget( null ) }
			/>
		</div>
	);
}
