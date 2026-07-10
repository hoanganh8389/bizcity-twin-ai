// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — channel source filter state
import React, { useMemo, useState, useRef, useEffect, useCallback } from 'react';
import { Plus, Mail, Phone, Star, Tags, X as XIcon, Columns, Download, Radio } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Badge } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/tabs.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import { DataTable } from '../../components/ui/data-table.jsx';
import AuditTimeline from '../../components/audit/AuditTimeline.jsx';
import ActivityFeed from '../../components/activity/ActivityFeed.jsx';
// [2026-06-13 Johnny Chu] PHASE-0.45 — removed MOCK_ACTIVITIES; now using live activityFeed + invoices
import {
	useGetCrmContactsQuery,
	useGetCrmContactSourcesQuery,
	useGetCrmAccountsQuery,
	useCreateCrmContactMutation,
	useGetCrmContactWooOrdersQuery,
	useGetEntityAuditLogQuery,
	useClassifyContactMutation,
	useBulkClassifyContactsMutation,
	useGetCrmInvoicesQuery,
	useArchiveCrmContactMutation,
	useUnarchiveCrmContactMutation,
	useGetCrmLeadsQuery,
} from '../../redux/api/crmApi.js';
// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7 — import broadcast wizard.
import BroadcastCreateDialog from '../broadcast/BroadcastCreateDialog.jsx';

// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — channel pill metadata (matches Sales Pipeline)
const CHANNEL_FILTER_META = {
	facebook:      { label: 'Facebook',       color: '#1877F2' },
	messenger:     { label: 'Messenger',       color: '#7B3FE4' },
	zalo_oa:       { label: 'Zalo OA',         color: '#0068FF' },
	zalo_personal: { label: 'Zalo Cá nhân',    color: '#00B0FF' },
	zalo:          { label: 'Zalo Cá nhân',    color: '#00B0FF' },
	webchat:       { label: 'WebChat',          color: '#10B981' },
	telegram:      { label: 'Telegram',         color: '#0088CC' },
	cf7:           { label: 'Contact Form 7',  color: '#F59E0B' },
	email:         { label: 'Email',            color: '#EF4444' },
	inbox:         { label: 'Nhập tay',         color: '#8B5CF6' },
	crm_manual:    { label: 'Nhập tay',         color: '#8B5CF6' },
};
const CHANNEL_ORDER = [ 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'zalo', 'webchat', 'telegram', 'cf7', 'email', 'inbox', 'crm_manual' ];

// hex color → "r, g, b" string for rgba()
function hexRgb( hex ) {
	const h = hex.replace( '#', '' );
	const r = parseInt( h.substring( 0, 2 ), 16 );
	const g = parseInt( h.substring( 2, 4 ), 16 );
	const b = parseInt( h.substring( 4, 6 ), 16 );
	return `${ r }, ${ g }, ${ b }`;
}

function ChannelFilterBar( { sources, activeSource, activeCf7FormId, onSelectSource, onSelectCf7Form } ) {
	const { cf7_total = 0, cf7_forms = [], channels = {} } = sources || {};

	// Build ordered channel list — always show all known channels (badge only when count > 0)
	const channelList = [];
	const knownSeen = new Set();
	for ( const code of CHANNEL_ORDER ) {
		knownSeen.add( code );
		// Skip alias duplicates: if zalo_personal already added, skip zalo (same label)
		if ( code === 'zalo' && knownSeen.has( 'zalo_personal' ) ) { continue; }
		const count = code === 'cf7' ? cf7_total : ( channels[ code ] || 0 );
		channelList.push( { code, count } );
	}
	Object.entries( channels ).forEach( ( [ code, count ] ) => {
		if ( ! knownSeen.has( code ) && count > 0 ) { channelList.push( { code, count } ); }
	} );

	return (
		<div className="bzc-channel-selector">
			<span className="bzc-channel-selector-label">Kênh</span>
			<div className="bzc-channel-pills">
				{ /* "Tất cả kênh" pill */ }
				<button
					type="button"
					className={ `bzc-ch-pill${ ! activeSource ? ' bzc-ch-pill--on' : '' }` }
					title="Tất cả kênh"
					style={ ! activeSource
						? { background: '#475569', borderColor: '#475569', color: '#fff' }
						: { borderColor: 'rgba(71,85,105,0.333)', color: '#475569' }
					}
					onClick={ () => onSelectSource( null, null ) }
				>
					<span className="bzc-ch-dot" style={ { background: ! activeSource ? 'rgba(255,255,255,0.65)' : '#475569' } }></span>
					<span className="bzc-ch-label">Tất cả kênh</span>
				</button>

				{ channelList.map( ( { code, count } ) => {
					const meta = CHANNEL_FILTER_META[ code ] || { label: code, color: '#64748b' };
					const isActive = activeSource === code;
					const rgb = hexRgb( meta.color );
					return (
						<button
							key={ code }
							type="button"
							className={ `bzc-ch-pill${ isActive ? ' bzc-ch-pill--on' : '' }` }
							title={ meta.label }
							style={ isActive
								? { background: '#475569', borderColor: '#475569', color: '#fff' }
								: { borderColor: `rgba(${ rgb }, 0.333)`, color: meta.color }
							}
							onClick={ () => onSelectSource( code, null ) }
						>
							<span className="bzc-ch-dot" style={ { background: isActive ? 'rgba(255,255,255,0.65)' : meta.color } }></span>
							<span className="bzc-ch-label">{ meta.label }</span>
							{ count > 0 && (
								<span
									className="bzc-ch-count"
									style={ isActive
										? { background: 'rgba(255,255,255,0.2)', color: '#fff' }
										: { background: `rgba(${ rgb }, 0.133)`, color: meta.color }
									}
								>{ count }</span>
							) }
						</button>
					);
				} ) }
			</div>

			{ /* CF7 sub-filter — only when CF7 active and multiple forms exist */ }
			{ activeSource === 'cf7' && cf7_forms.length > 0 && ( () => {
				const cf7Color = '#F59E0B';
				const cf7Rgb = hexRgb( cf7Color );
				return (
					<div className="bzc-channel-pills" style={ { marginTop: 6, paddingLeft: 16, borderLeft: `3px solid ${ cf7Color }` } }>
						<button
							type="button"
							className={ `bzc-ch-pill${ ! activeCf7FormId ? ' bzc-ch-pill--on' : '' }` }
							title="Tất cả form"
							style={ ! activeCf7FormId
								? { background: '#475569', borderColor: '#475569', color: '#fff' }
								: { borderColor: `rgba(${ cf7Rgb }, 0.333)`, color: cf7Color }
							}
							onClick={ () => onSelectCf7Form( null ) }
						>
							<span className="bzc-ch-dot" style={ { background: ! activeCf7FormId ? 'rgba(255,255,255,0.65)' : cf7Color } }></span>
							<span className="bzc-ch-label">Tất cả form</span>
						</button>
						{ cf7_forms.map( ( f ) => {
							const isF = activeCf7FormId === f.form_id;
							return (
								<button
									key={ f.form_id }
									type="button"
									className={ `bzc-ch-pill${ isF ? ' bzc-ch-pill--on' : '' }` }
									title={ f.form_title || ( 'Form #' + f.form_id ) }
									style={ isF
										? { background: '#475569', borderColor: '#475569', color: '#fff' }
										: { borderColor: `rgba(${ cf7Rgb }, 0.333)`, color: cf7Color }
									}
									onClick={ () => onSelectCf7Form( f.form_id ) }
								>
									<span className="bzc-ch-dot" style={ { background: isF ? 'rgba(255,255,255,0.65)' : cf7Color } }></span>
									<span className="bzc-ch-label">{ f.form_title || ( 'Form #' + f.form_id ) }</span>
									{ f.count > 0 && (
										<span className="bzc-ch-count" style={ isF
											? { background: 'rgba(255,255,255,0.2)', color: '#fff' }
											: { background: `rgba(${ cf7Rgb }, 0.133)`, color: cf7Color }
										}>{ f.count }</span>
									) }
								</button>
							);
						} ) }
					</div>
				);
			} )() }
		</div>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.45 — helper to parse tags string
function parseTags( str ) {
	if ( ! str || ! str.trim() ) { return []; }
	return str.split( ',' ).map( ( t ) => t.trim() ).filter( Boolean );
}

function ContactForm( { accounts, onSubmit, onCancel } ) {
	const [ data, setData ] = useState( { first_name: '', last_name: '', email: '', phone: '', title: '', account_id: '', tags: [] } );
	// [2026-06-13 Johnny Chu] PHASE-0.45 — tags as comma-separated input
	const [ tagsInput, setTagsInput ] = useState( '' );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => {
			e.preventDefault();
			onSubmit( { ...data, tags: parseTags( tagsInput ) } );
		} }>
			<div className="bzc-form-grid-2">
				<div><Label>Họ</Label><Input value={ data.first_name } onChange={ ( e ) => setData( { ...data, first_name: e.target.value } ) } required autoFocus /></div>
				<div><Label>Tên</Label><Input value={ data.last_name } onChange={ ( e ) => setData( { ...data, last_name: e.target.value } ) } required /></div>
				<div><Label>Email</Label><Input type="email" value={ data.email } onChange={ ( e ) => setData( { ...data, email: e.target.value } ) } /></div>
				<div><Label>Số điện thoại</Label><Input value={ data.phone } onChange={ ( e ) => setData( { ...data, phone: e.target.value } ) } /></div>
				<div><Label>Chức danh</Label><Input value={ data.title } onChange={ ( e ) => setData( { ...data, title: e.target.value } ) } /></div>
				<div><Label>Account</Label>
					<select className="bzc-input" value={ data.account_id } onChange={ ( e ) => setData( { ...data, account_id: e.target.value ? Number( e.target.value ) : '' } ) }>
						<option value="">— chọn —</option>
						{ accounts.map( ( a ) => <option key={ a.id } value={ a.id }>{ a.name }</option> ) }
					</select>
				</div>
				{ /* full-width tags row */ }
				<div style={ { gridColumn: '1 / -1' } }>
					<Label>Tags <span className="bzc-muted">(cách nhau bởi dấu phẩy)</span></Label>
					<Input
						value={ tagsInput }
						placeholder="vip, prospect, retargeting…"
						onChange={ ( e ) => setTagsInput( e.target.value ) }
					/>
				</div>
			</div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel }>Huỷ</Button>
				<Button type="submit" variant="primary">Lưu contact</Button>
			</div>
		</form>
	);
}

function WooOrdersPanel( { contactId } ) {
	const { data, isFetching, isError } = useGetCrmContactWooOrdersQuery( { id: contactId, limit: 20 }, { skip: ! contactId } );
	if ( isFetching ) { return <div className="bzc-empty bzc-muted">Đang tải…</div>; }
	if ( isError )    { return <div className="bzc-empty bzc-muted">Không tải được đơn hàng Woo.</div>; }
	if ( ! data?.wc_active ) { return <div className="bzc-empty bzc-muted">WooCommerce không hoạt động trên site này.</div>; }
	const orders = Array.isArray( data?.orders ) ? data.orders : [];
	if ( orders.length === 0 ) { return <div className="bzc-empty bzc-muted">Chưa có đơn nào.</div>; }
	return (
		<ul className="bzc-mini-list">
			{ orders.map( ( o ) => (
				<li key={ o.id || o.order_id }>
					<strong>
						{ o.admin_url
							? <a href={ o.admin_url } target="_blank" rel="noreferrer noopener">#{ o.number || o.id || o.order_id } ↗</a>
							: ( '#' + ( o.number || o.id || o.order_id ) ) }
					</strong>
					<span className="bzc-muted">
						{ ( o.status || '—' ) } · { ( o.date_created || o.created_at || '' ) } · { o.total != null ? Number( o.total ).toLocaleString() : '—' }
					</span>
				</li>
			) ) }
		</ul>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.45 — editable tags chip widget (read-only display only; full edit via ContactForm)
function TagsEditor( { contact } ) {
	const tags = Array.isArray( contact.tags ) ? contact.tags : [];
	if ( tags.length === 0 ) { return <span className="bzc-muted">—</span>; }
	return (
		<div style={ { display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 2 } }>
			{ tags.map( ( t ) => (
				<Badge key={ t } variant="muted" style={ { fontSize: 11 } }>{ t }</Badge>
			) ) }
		</div>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.45 — invoices panel per contact
function ContactInvoicesPanel( { contactId } ) {
	const { data, isFetching, isError } = useGetCrmInvoicesQuery(
		{ contact_id: contactId, limit: 50 },
		{ skip: ! contactId }
	);
	const invoices = data?.data?.invoices || data?.invoices || [];
	if ( isFetching ) { return <div className="bzc-empty bzc-muted">Đang tải…</div>; }
	if ( isError )    { return <div className="bzc-empty bzc-muted">Không tải được invoices.</div>; }
	if ( invoices.length === 0 ) { return <div className="bzc-empty bzc-muted">Chưa có invoice.</div>; }
	return (
		<ul className="bzc-mini-list">
			{ invoices.map( ( inv ) => (
				<li key={ inv.id }>
					<strong>#{ inv.id } — { inv.title || inv.description || '—' }</strong>
					<span className="bzc-muted">
						{ inv.status || '—' } · { inv.issued_at || inv.created_at || '' }
						{ inv.total != null ? ' · ' + Number( inv.total ).toLocaleString() + ( inv.currency ? ' ' + inv.currency : '' ) : '' }
					</span>
				</li>
			) ) }
		</ul>
	);
}

function ContactDetailSheet( { contact, accounts, onClose } ) {
	if ( ! contact ) { return null; }
	const account = accounts.find( ( a ) => a.id === contact.account_id );
	const billing = contact.additional_attributes?.billing || null;
	const isWooCustomer = !! contact.wp_user_id;
	return (
		<Sheet open={ !! contact } onOpenChange={ ( v ) => { if ( ! v ) { onClose(); } } }>
			<SheetContent className="bzc-sheet-wide">
				<SheetHeader>
					<SheetTitle>
						{ contact.name || `${ contact.first_name } ${ contact.last_name }` }
						{ isWooCustomer && (
							<Badge variant="info" style={ { marginLeft: 8, fontSize: 11 } } title={ 'wp_user_id=' + contact.wp_user_id }>Woo customer</Badge>
						) }
					</SheetTitle>
				</SheetHeader>
				<SheetBody>
					<div className="bzc-kv-grid">
						<div><span className="bzc-muted">Email</span><strong><Mail size={ 11 } /> { contact.email || '—' }</strong></div>
						<div><span className="bzc-muted">Phone</span><strong><Phone size={ 11 } /> { contact.phone || '—' }</strong></div>
						<div><span className="bzc-muted">Chức danh</span><strong>{ contact.title || '—' }</strong></div>
						<div><span className="bzc-muted">Account</span><strong>{ account?.name || '—' }</strong></div>
						<div><span className="bzc-muted">Owner</span><strong>{ contact.owner_id || '—' }</strong></div>
						{ /* [2026-06-13 Johnny Chu] PHASE-0.45 — editable tags */ }
						<div style={ { gridColumn: '1 / -1' } }>
							<span className="bzc-muted">Tags</span>
							<TagsEditor contact={ contact } />
						</div>
					</div>

					{ billing && (
						<div className="bzc-mt-md" style={ { padding: 12, background: '#f7f9fc', border: '1px solid #e3e8ef', borderRadius: 6 } }>
							<div style={ { fontWeight: 600, marginBottom: 6 } }>Billing address (Woo)</div>
							<div className="bzc-muted" style={ { fontSize: 13, lineHeight: 1.5 } }>
								{ [ billing.first_name, billing.last_name ].filter( Boolean ).join( ' ' ) || '—' }<br />
								{ billing.company || '' }{ billing.company ? <br /> : null }
								{ billing.address_1 || '' }{ billing.address_2 ? ', ' + billing.address_2 : '' }<br />
								{ [ billing.city, billing.state, billing.postcode ].filter( Boolean ).join( ', ' ) }<br />
								{ billing.country || '' }
							</div>
						</div>
					) }

					<Tabs defaultValue="activities" className="bzc-mt-md">
						<TabsList>
							<TabsTrigger value="activities">Activities</TabsTrigger>
							{ isWooCustomer && <TabsTrigger value="woo">Đơn hàng Woo</TabsTrigger> }
					{ /* [2026-06-13 Johnny Chu] PHASE-0.45 — invoices tab */ }
					<TabsTrigger value="invoices">Invoices</TabsTrigger>
					<TabsTrigger value="history">History</TabsTrigger>
					<TabsTrigger value="classify"><Star size={ 12 } className="mr-1" />Classify</TabsTrigger>
				</TabsList>
				{ /* [2026-06-13 Johnny Chu] PHASE-0.45 — live activities (was MOCK_ACTIVITIES) */ }
				<TabsContent value="activities"><ActivityFeed contactId={ contact.id } /></TabsContent>
				<TabsContent value="invoices"><ContactInvoicesPanel contactId={ contact.id } /></TabsContent>
						{ isWooCustomer && (
							<TabsContent value="woo"><WooOrdersPanel contactId={ contact.id } /></TabsContent>
						) }
					<TabsContent value="history">
						<ContactAuditPanel contactId={ contact.id } />
					</TabsContent>
					<TabsContent value="classify">
						<ClassifyPanel contact={ contact } />
					</TabsContent>
					</Tabs>
				</SheetBody>
			</SheetContent>
		</Sheet>
	);
}

/* Real audit panel */
function ContactAuditPanel( { contactId } ) {
	const { data, isFetching } = useGetEntityAuditLogQuery(
		{ entity_type: 'contact', entity_id: contactId },
		{ skip: ! contactId }
	);
	const entries = data?.entries || [];
	return isFetching
		? <div className="bzc-empty bzc-muted">Đang tải…</div>
		: <AuditTimeline entries={ entries } />;
}

const SEGMENTS = [ 'A', 'B', 'C', 'VIP' ];

function ClassifyPanel( { contact } ) {
	const [ classify, { isLoading } ] = useClassifyContactMutation();
	const [ score,   setScore ]   = useState( contact.lead_score ?? 0 );
	const [ segment, setSegment ] = useState( contact.segment    ?? '' );
	const [ msg,     setMsg ]     = useState( '' );

	const submit = async ( e ) => {
		e.preventDefault();
		setMsg( '' );
		try {
			await classify( { id: contact.id, lead_score: score, segment } ).unwrap();
			setMsg( 'Đã lưu.' );
		} catch ( ex ) {
			setMsg( ex?.data?.message || 'Lưu thất bại.' );
		}
	};

	return (
		<form onSubmit={ submit } className="flex flex-col gap-4 py-2">
			<div className="flex flex-col gap-1">
				<Label>Lead Score: <strong>{ score }</strong></Label>
				<input
					type="range" min={ 0 } max={ 100 } step={ 5 }
					value={ score }
					onChange={ ( e ) => setScore( Number( e.target.value ) ) }
					className="w-full"
				/>
			</div>
			<div className="flex flex-col gap-1">
				<Label>Segment</Label>
				<select className="bzc-input" value={ segment } onChange={ ( e ) => setSegment( e.target.value ) }>
					<option value="">— không phân loại —</option>
					{ SEGMENTS.map( ( s ) => <option key={ s } value={ s }>{ s }</option> ) }
				</select>
			</div>
			{ msg && <p className="text-sm text-emerald-600">{ msg }</p> }
			<div>
				<Button type="submit" size="sm" disabled={ isLoading }>
					{ isLoading ? 'Đang lưu…' : 'Lưu phân loại' }
				</Button>
			</div>
		</form>
	);
}

// [2026-06-13 Johnny Chu] PHASE-0.44 A.3 — BulkActionBar component (Deplao parity)
const SEGMENTS_BULK = [ 'A', 'B', 'C', 'VIP' ];

function BulkActionBar( { selectedIds, onClear, onBulkClassify, busy } ) {
	const count = selectedIds.length;
	const [ showSegmentPicker, setShowSegmentPicker ] = useState( false );
	const pickerRef = useRef( null );

	useEffect( () => {
		if ( ! showSegmentPicker ) { return; }
		const handler = ( e ) => {
			if ( pickerRef.current && ! pickerRef.current.contains( e.target ) ) { setShowSegmentPicker( false ); }
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ showSegmentPicker ] );

	if ( count === 0 ) { return null; }

	return (
		<div style={ { position: 'fixed', bottom: 24, left: '50%', transform: 'translateX(-50%)', zIndex: 60, display: 'flex', alignItems: 'center', gap: 10, background: '#1e293b', border: '1px solid #334155', borderRadius: 20, padding: '8px 18px', boxShadow: '0 8px 32px rgba(0,0,0,0.3)' } }>
			<span style={ { fontSize: 13, fontWeight: 700, color: '#60a5fa', whiteSpace: 'nowrap' } }>{ count } đã chọn</span>
			<div style={ { width: 1, height: 20, background: '#475569' } } />
			{ /* Segment picker */ }
			<div ref={ pickerRef } style={ { position: 'relative' } }>
				<button
					type="button"
					disabled={ busy }
					onClick={ () => setShowSegmentPicker( ( v ) => ! v ) }
					style={ { display: 'flex', alignItems: 'center', gap: 5, fontSize: 12, color: '#cbd5e1', cursor: 'pointer', background: 'none', border: 'none', padding: '4px 8px', borderRadius: 8, transition: 'background 0.15s' } }
					onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#334155'; } }
					onMouseLeave={ ( e ) => { e.currentTarget.style.background = 'none'; } }
					title="Phân loại segment hàng loạt"
				>
					<Tags size={ 13 } />
					Phân loại
				</button>
				{ showSegmentPicker && (
					<div style={ { position: 'absolute', bottom: '100%', left: 0, marginBottom: 6, background: '#1e293b', border: '1px solid #334155', borderRadius: 10, padding: 4, minWidth: 140, zIndex: 70, boxShadow: '0 4px 16px rgba(0,0,0,0.4)' } }>
						{ SEGMENTS_BULK.map( ( seg ) => (
							<button
								key={ seg }
								type="button"
								disabled={ busy }
								onClick={ () => { setShowSegmentPicker( false ); onBulkClassify( seg ); } }
								style={ { display: 'block', width: '100%', padding: '6px 14px', fontSize: 12, textAlign: 'left', color: '#e2e8f0', background: 'none', border: 'none', cursor: 'pointer', borderRadius: 6 } }
								onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#334155'; } }
								onMouseLeave={ ( e ) => { e.currentTarget.style.background = 'none'; } }
							>Gán Segment <strong>{ seg }</strong></button>
						) ) }
						<div style={ { height: 1, background: '#334155', margin: '2px 8px' } } />
						<button
							type="button"
							disabled={ busy }
							onClick={ () => { setShowSegmentPicker( false ); onBulkClassify( '' ); } }
							style={ { display: 'block', width: '100%', padding: '6px 14px', fontSize: 12, textAlign: 'left', color: '#94a3b8', background: 'none', border: 'none', cursor: 'pointer', borderRadius: 6 } }
							onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#334155'; } }
							onMouseLeave={ ( e ) => { e.currentTarget.style.background = 'none'; } }
						>Xoá phân loại</button>
					</div>
				) }
			</div>
			<button
				type="button"
				onClick={ onClear }
				style={ { display: 'flex', alignItems: 'center', fontSize: 12, color: '#94a3b8', cursor: 'pointer', background: 'none', border: 'none', padding: '4px 6px', borderRadius: 8 } }
				title="Bỏ chọn"
			>
				<XIcon size={ 14 } />
			</button>
		</div>
	);
}

// [2026-06-19 Johnny Chu] PHASE-0.44 A.4 — Column definitions (always + optional + CF7 extras)
const ALL_COLUMNS = [
	{ id: 'name',      header: 'Tên',        always: true },
	{ id: 'email',     header: 'Email',       defaultOn: true },
	{ id: 'phone',     header: 'Phone',       defaultOn: true },
	{ id: 'segment',   header: 'Segment',     defaultOn: true },
	{ id: 'lead_score',header: 'Score',       defaultOn: true },
	{ id: 'account',   header: 'Account',     defaultOn: true },
	{ id: 'title',     header: 'Chức danh',   defaultOn: false },
	{ id: 'tags',      header: 'Tags',        defaultOn: false },
	{ id: 'owner_id',  header: 'Owner',       defaultOn: false },
	{ id: 'age',       header: 'Tuổi',        defaultOn: false },
	{ id: 'birthday',  header: 'Ngày sinh',   defaultOn: false },
	{ id: 'address',   header: 'Địa chỉ',     defaultOn: false },
];

const COL_STORAGE_KEY = 'bizcity_crm_contacts_cols_v1';

function loadVisibleCols() {
	try {
		const saved = localStorage.getItem( COL_STORAGE_KEY );
		if ( saved ) { return new Set( JSON.parse( saved ) ); }
	} catch ( _e ) {}
	return new Set( ALL_COLUMNS.filter( ( c ) => c.always || c.defaultOn ).map( ( c ) => c.id ) );
}

function saveVisibleCols( set ) {
	try { localStorage.setItem( COL_STORAGE_KEY, JSON.stringify( [ ...set ] ) ); } catch ( _e ) {}
}

// [2026-06-19 Johnny Chu] PHASE-0.44 A.4 — Column picker dropdown
function ColumnPicker( { visibleCols, onToggle } ) {
	const [ open, setOpen ] = useState( false );
	const ref = useRef( null );

	useEffect( () => {
		if ( ! open ) { return; }
		const handler = ( e ) => {
			if ( ref.current && ! ref.current.contains( e.target ) ) { setOpen( false ); }
		};
		document.addEventListener( 'mousedown', handler );
		return () => document.removeEventListener( 'mousedown', handler );
	}, [ open ] );

	return (
		<div ref={ ref } style={ { position: 'relative' } }>
			<button
				type="button"
				onClick={ () => setOpen( ( v ) => ! v ) }
				style={ {
					display: 'inline-flex', alignItems: 'center', gap: 5,
					fontSize: 12, fontWeight: 500, color: '#475569',
					background: open ? '#f1f5f9' : '#fff',
					border: '1px solid #e2e8ef', borderRadius: 6,
					padding: '5px 10px', cursor: 'pointer',
					transition: 'background 0.15s',
				} }
				title="Chọn cột hiển thị"
			>
				<Columns size={ 13 } />
				Cột hiển thị
			</button>
			{ open && (
				<div style={ {
					position: 'absolute', top: '100%', right: 0, marginTop: 4,
					background: '#fff', border: '1px solid #e2e8ef', borderRadius: 8,
					boxShadow: '0 4px 16px rgba(0,0,0,0.1)', padding: '6px 0',
					minWidth: 180, zIndex: 50,
				} }>
					{ ALL_COLUMNS.map( ( col ) => (
						<label
							key={ col.id }
							style={ {
								display: 'flex', alignItems: 'center', gap: 8,
								padding: '5px 14px', fontSize: 13, cursor: col.always ? 'default' : 'pointer',
								color: col.always ? '#94a3b8' : '#1e293b',
								userSelect: 'none',
							} }
							onMouseEnter={ ( e ) => { if ( ! col.always ) { e.currentTarget.style.background = '#f8fafc'; } } }
							onMouseLeave={ ( e ) => { e.currentTarget.style.background = ''; } }
						>
							<input
								type="checkbox"
								checked={ col.always || visibleCols.has( col.id ) }
								disabled={ col.always }
								onChange={ () => { if ( ! col.always ) { onToggle( col.id ); } } }
								style={ { cursor: col.always ? 'default' : 'pointer' } }
							/>
							{ col.header }
							{ col.always && <span style={ { fontSize: 10, color: '#94a3b8', marginLeft: 2 } }>(cố định)</span> }
						</label>
					) ) }
				</div>
			) }
		</div>
	);
}

// [2026-06-19 Johnny Chu] PHASE-0.44 A.4 — render a single cell by column id
// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — FB badge for contacts with name matching /^FB \d+/
const FB_NAME_RE = /^FB \d+$/;
function FbBadge() {
	return (
		<span title="Facebook" style={ {
			display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
			width: 16, height: 16, borderRadius: '50%', background: '#1877F2',
			color: '#fff', fontSize: 9, fontWeight: 700, marginRight: 5, flexShrink: 0,
		} }>f</span>
	);
}
function renderCell( colId, c, accounts ) {
	switch ( colId ) {
		case 'name': {
			const displayName = c.name || ( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ).trim() || '—';
			const isFb = FB_NAME_RE.test( displayName );
			return (
				<td key="name" className="font-medium" style={ { display: 'flex', alignItems: 'center' } }>
					{ isFb && <FbBadge /> }
					{ displayName }
				</td>
			);
		}
		case 'email':
			return <td key="email" className="bzc-muted">{ c.email || '—' }</td>;
		case 'phone':
			return <td key="phone" className="bzc-muted">{ c.phone || '—' }</td>;
		case 'segment': {
			const seg = c.segment;
			const segCl = seg === 'VIP' ? 'bg-amber-100 text-amber-700' : seg === 'A' ? 'bg-emerald-100 text-emerald-700' : seg === 'B' ? 'bg-blue-100 text-blue-700' : seg === 'C' ? 'bg-slate-100 text-slate-600' : '';
			return <td key="segment">{ seg ? <span className={ 'bzc-badge text-xs px-1.5 py-0.5 rounded-full ' + segCl }>{ seg }</span> : null }</td>;
		}
		case 'lead_score': {
			const score = c.lead_score ?? 0;
			const scoreCl = score >= 70 ? 'text-emerald-600' : score >= 40 ? 'text-amber-600' : 'text-slate-400';
			return <td key="lead_score">{ score > 0 ? <span className={ 'font-mono text-xs font-bold ' + scoreCl }>{ score }</span> : null }</td>;
		}
		case 'account': {
			const account = accounts.find( ( a ) => a.id === c.account_id );
			return <td key="account" className="bzc-muted">{ account?.name || '—' }</td>;
		}
		case 'title':
			return <td key="title" className="bzc-muted">{ c.title || '—' }</td>;
		case 'tags': {
			const tags = Array.isArray( c.tags ) ? c.tags : [];
			return (
				<td key="tags">
					{ tags.length > 0
						? <div style={ { display: 'flex', flexWrap: 'wrap', gap: 2 } }>
							{ tags.map( ( t ) => <span key={ t } className="bzc-badge text-xs px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600">{ t }</span> ) }
						</div>
						: <span className="bzc-muted">—</span> }
				</td>
			);
		}
		case 'owner_id':
			return <td key="owner_id" className="bzc-muted">{ c.owner_id || '—' }</td>;
		case 'age': {
			const age = c.additional_attributes?.age ?? c.age ?? null;
			return <td key="age" className="bzc-muted">{ age != null ? age : '—' }</td>;
		}
		case 'birthday': {
			const bd = c.additional_attributes?.birthday ?? c.birthday ?? null;
			return <td key="birthday" className="bzc-muted">{ bd || '—' }</td>;
		}
		case 'address': {
			const addr = c.additional_attributes?.address ?? c.address ?? null;
			return <td key="address" className="bzc-muted" style={ { maxWidth: 180, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }>{ addr || '—' }</td>;
		}
		default:
			return <td key={ colId }>—</td>;
	}
}

export default function ContactsTab() {
	// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — channel source filter state
	const [ activeSource, setActiveSource ]       = useState( null );
	const [ activeCf7FormId, setActiveCf7FormId ] = useState( null );
	// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — view: 'active' | 'archived'
	const [ viewMode, setViewMode ] = useState( 'active' );

	const contactQueryArgs = useMemo( () => {
		const args = {};
		if ( activeSource )    { args.source = activeSource; }
		if ( activeCf7FormId ) { args.cf7_form_id = activeCf7FormId; }
		if ( viewMode === 'archived' ) { args.view = 'archived'; }
		return args;
	}, [ activeSource, activeCf7FormId, viewMode ] );

	// [2026-06-22 Johnny Chu] CF7-CONTACTS-FIX — refetchOnMountOrArgChange: true ensures RTK Query
	// always dispatches a fresh network request when filter args change (e.g. clicking a CF7 form pill)
	// instead of serving stale cache. Without this, old cached empty results persist across filter switches.
	const { data: contacts = [], isFetching } = useGetCrmContactsQuery( contactQueryArgs, { refetchOnMountOrArgChange: true } );
	const { data: sourcesData } = useGetCrmContactSourcesQuery();

	// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — fetch CF7 leads to build form list
	// Server-side sources endpoint may return cf7_forms:[] if PHP not yet updated; fallback to leads.
	const { data: cf7Leads = [] } = useGetCrmLeadsQuery(
		{ source: 'cf7', limit: 500 },
		{ skip: activeSource !== 'cf7' }
	);
	const cf7FormsFromLeads = useMemo( () => {
		const formMap = {};
		cf7Leads.forEach( ( lead ) => {
			const formId = parseInt( lead.custom?.form_id ?? 0, 10 );
			if ( ! formId ) { return; }
			if ( ! formMap[ formId ] ) {
				formMap[ formId ] = { form_id: formId, form_title: lead.custom?.form_title || ( 'Form #' + formId ), contact_ids: new Set(), source: 'cf7:' + formId };
			}
			if ( lead.contact_id ) { formMap[ formId ].contact_ids.add( lead.contact_id ); }
		} );
		return Object.values( formMap )
			.map( ( f ) => ( { ...f, count: f.contact_ids.size } ) )
			.sort( ( a, b ) => b.count - a.count );
	}, [ cf7Leads ] );
	const [ archiveContact ]   = useArchiveCrmContactMutation();
	const [ unarchiveContact ] = useUnarchiveCrmContactMutation();

	// [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — fallback: derive channel counts from loaded contacts
	const derivedSources = useMemo( () => {
		// Use server data, but patch in cf7_forms from leads if server returns empty
		const base = sourcesData || ( () => {
			const channelMap = {};
			let cf7Total = 0;
			const cf7FormMap = {};
			contacts.forEach( ( c ) => {
				const src = c.acquisition_source || '';
				if ( src.startsWith( 'cf7:' ) ) {
					cf7Total++;
					const fid = parseInt( src.slice( 4 ), 10 );
					if ( ! cf7FormMap[ fid ] ) { cf7FormMap[ fid ] = { form_id: fid, form_title: 'Form #' + fid, count: 0, source: src }; }
					cf7FormMap[ fid ].count++;
				} else if ( src === 'cf7' ) {
					cf7Total++;
				} else if ( src ) {
					const key = src.startsWith( 'inbox:' ) ? 'inbox' : src;
					channelMap[ key ] = ( channelMap[ key ] || 0 ) + 1;
				}
			} );
			return { cf7_total: cf7Total, cf7_forms: Object.values( cf7FormMap ), channels: channelMap };
		} )();
		// Patch: if cf7_total > 0 but cf7_forms still empty, use leads-derived forms
		if ( ( base.cf7_total > 0 ) && ( ! base.cf7_forms || base.cf7_forms.length === 0 ) && cf7FormsFromLeads.length > 0 ) {
			return { ...base, cf7_forms: cf7FormsFromLeads };
		}
		return base;
	}, [ sourcesData, contacts, cf7FormsFromLeads ] );

	const { data: accounts = [] } = useGetCrmAccountsQuery();
	const [ createContact, { isLoading: creating } ] = useCreateCrmContactMutation();
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.3 — bulk classify
	const [ bulkClassify, { isLoading: bulking } ] = useBulkClassifyContactsMutation();
	const [ formOpen, setFormOpen ] = useState( false );
	const [ detail, setDetail ] = useState( null );
	// [2026-06-13 Johnny Chu] PHASE-0.44 A.3 — row selection state
	const [ selectedIds, setSelectedIds ] = useState( [] );
	// [2026-06-19 Johnny Chu] PHASE-0.44 A.4 — column visibility (persisted)
	const [ visibleCols, setVisibleCols ] = useState( () => loadVisibleCols() );
	// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7 — broadcast dialog state + pre-filled recipients
	const [ broadcastOpen, setBroadcastOpen ] = useState( false );
	const [ broadcastInitRecipients, setBroadcastInitRecipients ] = useState( null );
	const toggleCol = ( id ) => {
		setVisibleCols( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( id ) ) { next.delete( id ); } else { next.add( id ); }
			saveVisibleCols( next );
			return next;
		} );
	};
	const activeCols = ALL_COLUMNS.filter( ( c ) => c.always || visibleCols.has( c.id ) );

	const toggleSelect = ( id ) => {
		setSelectedIds( ( prev ) => prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ] );
	};
	const toggleAll = () => {
		setSelectedIds( ( prev ) => prev.length === contacts.length ? [] : contacts.map( ( c ) => c.id ) );
	};
	const handleBulkClassify = async ( segment ) => {
		if ( selectedIds.length === 0 ) { return; }
		try {
			await bulkClassify( { contact_ids: selectedIds, segment } ).unwrap();
			setSelectedIds( [] );
		} catch ( err ) {
			console.error( '[bizcity-crm] bulk classify failed', err );
		}
	};

	const cols = useMemo( () => [
		{ id: 'name', header: 'Tên', accessorFn: ( r ) => r.name || ( r.first_name + ' ' + r.last_name ) },
		{ accessorKey: 'email',      header: 'Email' },
		{ accessorKey: 'phone',      header: 'Phone' },
		{ accessorKey: 'title',      header: 'Chức danh' },
		{ id: 'account', header: 'Account', accessorFn: ( r ) => accounts.find( ( a ) => a.id === r.account_id )?.name || '—' },
		{ accessorKey: 'tags',       header: 'Tags',    cell: ( c ) => ( c.getValue() || [] ).map( ( t ) => <Badge key={ t } variant="muted" className="bzc-mr-xs">{ t }</Badge> ) },
		{
			accessorKey: 'lead_score',
			header: 'Score',
			cell: ( c ) => {
				const v = c.getValue() ?? 0;
				const color = v >= 70 ? 'text-emerald-600' : v >= 40 ? 'text-amber-600' : 'text-slate-400';
				return v > 0 ? <span className={ 'font-mono text-xs font-bold ' + color }>{ v }</span> : null;
			},
		},
		{
			accessorKey: 'segment',
			header: 'Segment',
			cell: ( c ) => {
				const seg = c.getValue();
				if ( ! seg ) return null;
				const cl = seg === 'VIP' ? 'bg-amber-100 text-amber-700' : seg === 'A' ? 'bg-emerald-100 text-emerald-700' : seg === 'B' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600';
				return <span className={ 'bzc-badge text-xs px-1.5 py-0.5 rounded-full ' + cl }>{ seg }</span>;
			},
		},
		{ accessorKey: 'owner_id',   header: 'Owner' },
	], [ accounts ] );

	// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7a — export selected/all contacts as CSV.
	const handleExportCsv = useCallback( () => {
		const targets = selectedIds.length > 0
			? contacts.filter( ( c ) => selectedIds.includes( c.id ) )
			: contacts;
		if ( targets.length === 0 ) { return; }
		const header = 'name,phone,email';
		const rows = targets.map( ( c ) => {
			const name  = ( c.name || ( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ).trim() || '' ).replace( /,/g, ' ' );
			const phone = ( c.phone || '' ).replace( /,/g, '' );
			const email = ( c.email || '' ).replace( /,/g, '' );
			return name + ',' + phone + ',' + email;
		} );
		const csv = header + '\n' + rows.join( '\n' );
		const blob = new Blob( [ '\uFEFF' + csv ], { type: 'text/csv;charset=utf-8;' } );
		const url  = URL.createObjectURL( blob );
		const a    = document.createElement( 'a' );
		a.href     = url;
		a.download = 'contacts-broadcast.csv';
		a.click();
		URL.revokeObjectURL( url );
	}, [ contacts, selectedIds ] );

	// [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7b — open broadcast wizard with pre-filled contacts.
	const handleBroadcastSelected = useCallback( () => {
		const targets = selectedIds.length > 0
			? contacts.filter( ( c ) => selectedIds.includes( c.id ) )
			: contacts;
		if ( targets.length === 0 ) { return; }
		const recipients = targets.map( ( c ) => ( {
			id:    c.id,
			name:  ( c.name || ( ( c.first_name || '' ) + ' ' + ( c.last_name || '' ) ).trim() || '' ),
			phone: c.phone || '',
			email: c.email || '',
		} ) );
		setBroadcastInitRecipients( recipients );
		setBroadcastOpen( true );
	}, [ contacts, selectedIds ] );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Contacts</h2>
					<p className="bzc-tabpane-subtitle">Danh bạ người liên hệ. { isFetching ? '— đang tải…' : `(${ contacts.length })` }</p>
				</div>
				{ /* [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7 — broadcast + export buttons */ }
				<div style={ { display: 'flex', gap: 6, alignItems: 'center' } }>
					<button
						type="button"
						title={ selectedIds.length > 0 ? `Xuất CSV (${ selectedIds.length } đã chọn)` : 'Xuất tất cả CSV' }
						onClick={ handleExportCsv }
						style={ {
							display: 'inline-flex', alignItems: 'center', gap: 5,
							fontSize: 12, fontWeight: 500, color: '#475569',
							background: '#fff', border: '1px solid #e2e8ef', borderRadius: 6,
							padding: '5px 10px', cursor: 'pointer',
						} }
					>
						<Download size={ 13 } />
						Xuất CSV{ selectedIds.length > 0 ? ` (${ selectedIds.length })` : '' }
					</button>
					<button
						type="button"
						title={ selectedIds.length > 0 ? `Gửi Broadcast (${ selectedIds.length } đã chọn)` : 'Gửi Broadcast tất cả' }
						onClick={ handleBroadcastSelected }
						disabled={ contacts.length === 0 }
						style={ {
							display: 'inline-flex', alignItems: 'center', gap: 5,
							fontSize: 12, fontWeight: 500, color: '#fff',
							background: '#4f46e5', border: '1px solid #4f46e5', borderRadius: 6,
							padding: '5px 10px', cursor: 'pointer', opacity: contacts.length === 0 ? 0.5 : 1,
						} }
					>
						<Radio size={ 13 } />
						Gửi Broadcast{ selectedIds.length > 0 ? ` (${ selectedIds.length })` : '' }
					</button>
					<Button variant="primary" onClick={ () => setFormOpen( true ) }><Plus size={ 12 } /> Contact mới</Button>
				</div>
			</header>

			{ /* [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — view toggle: Active / Archived */ }
			{ viewMode === 'active' && (
				<ChannelFilterBar
					sources={ derivedSources }
					activeSource={ activeSource }
					activeCf7FormId={ activeCf7FormId }
					onSelectSource={ ( src, formId ) => { setActiveSource( src ); setActiveCf7FormId( formId ); } }
					onSelectCf7Form={ ( formId ) => setActiveCf7FormId( formId ) }
				/>
			) }

			<div style={ { display: 'flex', gap: 6, marginBottom: 10 } }>
				<button
					type="button"
					className={ `bzc-ch-pill${ viewMode === 'active' ? ' bzc-ch-pill--on' : '' }` }
					style={ viewMode === 'active'
						? { background: '#475569', borderColor: '#475569', color: '#fff' }
						: { borderColor: 'rgba(71,85,105,0.333)', color: '#475569' }
					}
					onClick={ () => { setViewMode( 'active' ); setSelectedIds( [] ); } }
				>
					<span className="bzc-ch-dot" style={ { background: viewMode === 'active' ? 'rgba(255,255,255,0.65)' : '#475569' } }></span>
					<span className="bzc-ch-label">Danh sách</span>
				</button>
				<button
					type="button"
					className={ `bzc-ch-pill${ viewMode === 'archived' ? ' bzc-ch-pill--on' : '' }` }
					style={ viewMode === 'archived'
						? { background: '#64748b', borderColor: '#64748b', color: '#fff' }
						: { borderColor: 'rgba(100,116,139,0.333)', color: '#64748b' }
					}
					onClick={ () => { setViewMode( 'archived' ); setActiveSource( null ); setActiveCf7FormId( null ); setSelectedIds( [] ); } }
				>
					<span className="bzc-ch-dot" style={ { background: viewMode === 'archived' ? 'rgba(255,255,255,0.65)' : '#94a3b8' } }></span>
					<span className="bzc-ch-label">Đã lưu trữ</span>
				</button>
			</div>

			{ /* [2026-06-14 Johnny Chu] PHASE-0.44 A.3 — checkbox table restyled to .bzc-dt pattern (parity with AccountsTab) */ }
			<div className="bzc-dt">
				<div className="bzc-dt-toolbar" style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between' } }>
					<div className="bzc-dt-meta bzc-muted">{ contacts.length } contacts</div>
					{ /* [2026-06-19 Johnny Chu] PHASE-0.44 A.4 — column picker */ }
					<ColumnPicker visibleCols={ visibleCols } onToggle={ toggleCol } />
				</div>
				<div className="bzc-dt-scroll">
					<table className="bzc-table">
						<thead>
							<tr>
								<th style={ { width: 32 } }>
									<input type="checkbox" className="cursor-pointer"
										checked={ contacts.length > 0 && selectedIds.length === contacts.length }
										onChange={ toggleAll }
									/>
								</th>
								{ activeCols.map( ( col ) => <th key={ col.id }>{ col.header }</th> ) }
								<th style={ { width: 110 } }></th>
							</tr>
						</thead>
						<tbody>
							{ isFetching && (
								<tr><td colSpan={ activeCols.length + 1 } className="bzc-empty bzc-muted">Đang tải…</td></tr>
							) }
							{ ! isFetching && contacts.length === 0 && (
								<tr><td colSpan={ activeCols.length + 1 } className="bzc-empty bzc-muted">Chưa có contact nào.</td></tr>
							) }
							{ contacts.map( ( c ) => {
								const isChecked = selectedIds.includes( c.id );
								return (
									<tr key={ c.id }
										className={ 'bzc-row' + ( isChecked ? ' bzc-row--selected' : '' ) }
										onClick={ () => setDetail( c ) }
										style={ { cursor: 'pointer' } }
									>
										<td onClick={ ( e ) => e.stopPropagation() }>
											<input type="checkbox" className="cursor-pointer" checked={ isChecked } onChange={ () => toggleSelect( c.id ) } />
										</td>
										{ activeCols.map( ( col ) => renderCell( col.id, c, accounts ) ) }
										{ /* [2026-06-22 Johnny Chu] PHASE-0.39 CONTACTS-FILTER — archive/unarchive */ }
										<td onClick={ ( e ) => e.stopPropagation() } style={ { textAlign: 'right', paddingRight: 8 } }>
											<button
												type="button"
												title={ viewMode === 'archived' ? 'Khôi phục' : 'Lưu trữ' }
												style={ { fontSize: 11, padding: '2px 8px', borderRadius: 6, border: '1px solid #e2e8ef', background: '#f8fafc', color: '#64748b', cursor: 'pointer' } }
												onClick={ async () => {
													try {
														if ( viewMode === 'archived' ) {
															await unarchiveContact( c.id ).unwrap();
														} else {
															await archiveContact( c.id ).unwrap();
														}
													} catch ( err ) {
														console.error( '[bzc-crm] archive error', err );
													}
												} }
											>{ viewMode === 'archived' ? '↩ Khôi phục' : '🗃️ Lưu trữ' }</button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			</div>

			<BulkActionBar
				selectedIds={ selectedIds }
				onClear={ () => setSelectedIds( [] ) }
				onBulkClassify={ handleBulkClassify }
				busy={ bulking }
			/>

			<Sheet open={ formOpen } onOpenChange={ setFormOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Contact mới</SheetTitle></SheetHeader>
					<SheetBody>
						<ContactForm
							accounts={ accounts }
							onCancel={ () => setFormOpen( false ) }
							onSubmit={ async ( data ) => {
								try {
									await createContact( data ).unwrap();
									setFormOpen( false );
								} catch ( err ) {
									console.error( '[bizcity-crm] create contact failed', err );
									alert( 'Lưu contact thất bại. Xem console.' );
								}
							} }
						/>
						{ creating && <p className="bzc-muted bzc-mt-sm">Đang lưu…</p> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			<ContactDetailSheet contact={ detail } accounts={ accounts } onClose={ () => setDetail( null ) } />

			{ /* [2026-06-28 Johnny Chu] PHASE-CG-BROADCAST Issue 7b — broadcast wizard with pre-filled recipients */ }
			{ broadcastOpen && (
				<BroadcastCreateDialog
					initialRecipients={ broadcastInitRecipients }
					onClose={ () => { setBroadcastOpen( false ); setBroadcastInitRecipients( null ); } }
				/>
			) }
		</div>
	);
}
