import React, { useState } from 'react';
import { StickyNote, Phone, Calendar, Mail, CheckSquare, Plus, Loader2 } from 'lucide-react';
import { Button } from '../ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../ui/sheet.jsx';
import { Input, Textarea, Label } from '../ui/input.jsx';
// [2026-06-13 Johnny Chu] PHASE-0.45 — live activities query
import {
	useGetContactActivitiesQuery,
	useCreateContactActivityMutation,
	// [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — account + global activities
	useGetAccountActivitiesQuery,
	useCreateAccountActivityMutation,
	useGetRecentActivitiesQuery,
} from '../../redux/api/crmApi.js';

const ICONS = { note: StickyNote, call: Phone, meeting: Calendar, email: Mail, task: CheckSquare };

// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — follow_status color map for activity card
const STATUS_COLOR = {
	new:           { label: 'Mới',          color: '#2563eb', bg: '#eff6ff' },
	contacted:     { label: 'Đã LH',        color: '#0891b2', bg: '#ecfeff' },
	qualified:     { label: 'Đủ ĐK',        color: '#ca8a04', bg: '#fefce8' },
	proposal_sent: { label: 'Báo giá',      color: '#c2410c', bg: '#fff7ed' },
	negotiating:   { label: 'Đàm phán',     color: '#9333ea', bg: '#fdf4ff' },
	closed_won:    { label: 'Thành công',   color: '#16a34a', bg: '#f0fdf4' },
	closed_lost:   { label: 'Thất bại',     color: '#dc2626', bg: '#fef2f2' },
	invalid:       { label: 'Không HĐ',     color: '#94a3b8', bg: '#f8fafc' },
};

function timeAgo( iso ) {
	const ms = Date.now() - new Date( iso ).getTime();
	const m = Math.floor( ms / 60000 );
	if ( m < 1 ) { return 'vừa xong'; }
	if ( m < 60 ) { return m + ' phút trước'; }
	const h = Math.floor( m / 60 );
	if ( h < 24 ) { return h + ' giờ trước'; }
	return Math.floor( h / 24 ) + ' ngày trước';
}

export function ActivityCard( { activity } ) {
	const Icon = ICONS[ activity.type ] || StickyNote;
	// [2026-06-30 Johnny Chu] PHASE-0.46 FIX — show contact name/phone/email + follow_status
	const st    = activity.follow_status ? ( STATUS_COLOR[ activity.follow_status ] || { label: activity.follow_status, color: '#64748b', bg: '#f1f5f9' } ) : null;
	const name  = activity.contact_name  || '';
	const phone = activity.contact_phone || '';
	const email = activity.contact_email || '';
	const form  = activity.form_title    || '';
	return (
		<div className="bzc-activity-card">
			<div className={ 'bzc-activity-icon bzc-activity-icon-' + activity.type }>
				<Icon size={ 14 } />
			</div>
			<div className="bzc-activity-body">
				<div className="bzc-activity-head">
					<strong>{ activity.title }</strong>
					<span className="bzc-muted">{ activity.user } · { timeAgo( activity.created_at ) }</span>
				</div>
				{ ( name || phone || email ) && (
					<div style={ { fontSize: 11, color: '#334155', marginTop: 2, display: 'flex', flexWrap: 'wrap', gap: '2px 8px', alignItems: 'center' } }>
						{ name  && <span style={ { fontWeight: 600 } }>{ name }</span> }
						{ phone && <span style={ { color: '#0891b2' } }>📞 { phone }</span> }
						{ email && <span style={ { color: '#64748b', fontSize: 10 } }>{ email }</span> }
						{ st && (
							<span style={ { background: st.bg, color: st.color, padding: '1px 6px', borderRadius: 8, fontWeight: 600, fontSize: 10 } }>
								{ st.label }
							</span>
						) }
					</div>
				) }
				{ form && ! name && (
					<div style={ { fontSize: 10, color: '#94a3b8', marginTop: 1 } }>{ form }</div>
				) }
				{ activity.body && <div className="bzc-activity-text">{ activity.body }</div> }
			</div>
		</div>
	);
}

function ActivityForm( { onSubmit, onCancel, submitting } ) {
	const [ type, setType ] = useState( 'note' );
	const [ title, setTitle ] = useState( '' );
	const [ body, setBody ] = useState( '' );

	const submit = ( e ) => {
		e.preventDefault();
		if ( ! title.trim() ) { return; }
		onSubmit( { type, title: title.trim(), body } );
	};

	return (
		<form className="bzc-form" onSubmit={ submit }>
			<div>
				<Label>Loại</Label>
				<div className="bzc-segmented">
					{ [ 'note', 'call', 'meeting', 'email', 'task' ].map( ( t ) => {
						const I = ICONS[ t ];
						return (
							<button
								type="button" key={ t }
								className={ 'bzc-segmented-btn ' + ( type === t ? 'is-active' : '' ) }
								onClick={ () => setType( t ) }
							>
								<I size={ 14 } /> <span>{ t }</span>
							</button>
						);
					} ) }
				</div>
			</div>
			<div>
				<Label>Tiêu đề</Label>
				<Input value={ title } onChange={ ( e ) => setTitle( e.target.value ) } autoFocus />
			</div>
			<div>
				<Label>Nội dung</Label>
				<Textarea rows={ 5 } value={ body } onChange={ ( e ) => setBody( e.target.value ) } />
			</div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel }>Huỷ</Button>
				<Button type="submit" variant="primary" disabled={ submitting }>
					{ submitting ? <Loader2 size={ 12 } className="bzc-spin" /> : null }
					Tạo activity
				</Button>
			</div>
		</form>
	);
}

/**
 * ActivityFeed — paginated activity list with create form.
 *
 * Modes:
 *  - Contact live: pass `contactId` (number) → fetches from BE, creates via mutation.
 *  - Account live:  pass `accountId`  (number) → fetches account activities.
 *  - Global live:   pass `global={true}` → fetches recent activities across all entities (read-only).
 *  - Static: omit all entity props → uses `entries` prop + optional `onCreate` callback.
 *
 * [2026-06-13 Johnny Chu] PHASE-0.45 — wired to /contacts/{id}/activities REST
 * [2026-06-13 Johnny Chu] PHASE-0.44 C.2 — added accountId + global modes
 */
export default function ActivityFeed( { contactId, accountId, global: isGlobal, entries: initialEntries = [], onCreate } ) {
	const [ open, setOpen ] = useState( false );

	/* ── Live mode: contactId provided ── */
	const { data: contactEntries = [], isLoading: loadingContact } = useGetContactActivitiesQuery(
		{ id: contactId },
		{ skip: ! contactId }
	);
	const [ createContactActivity, { isLoading: creatingContact } ] = useCreateContactActivityMutation();

	/* ── Account mode ── */
	const { data: accountEntries = [], isLoading: loadingAccount } = useGetAccountActivitiesQuery(
		{ id: accountId },
		{ skip: ! accountId }
	);
	const [ createAccountActivity, { isLoading: creatingAccount } ] = useCreateAccountActivityMutation();

	/* ── Global recent mode ── */
	const { data: globalEntries = [], isLoading: loadingGlobal } = useGetRecentActivitiesQuery(
		undefined,
		{ skip: ! isGlobal }
	);

	/* ── Static fallback mode ── */
	const [ staticEntries, setStaticEntries ] = useState( initialEntries );

	const isLive = Boolean( contactId || accountId || isGlobal );
	const isLoading = loadingContact || loadingAccount || loadingGlobal;
	const creating  = creatingContact || creatingAccount;
	const entries   = contactId ? contactEntries
		: accountId ? accountEntries
		: isGlobal  ? globalEntries
		: staticEntries;

	const handleCreate = async ( formData ) => {
		if ( contactId ) {
			try {
				await createContactActivity( { id: contactId, ...formData } ).unwrap();
			} catch ( e ) {
				alert( 'Lỗi tạo activity: ' + ( e?.data?.message || e?.message || 'unknown' ) );
				return;
			}
		} else if ( accountId ) {
			try {
				await createAccountActivity( { id: accountId, ...formData } ).unwrap();
			} catch ( e ) {
				alert( 'Lỗi tạo activity: ' + ( e?.data?.message || e?.message || 'unknown' ) );
				return;
			}
		} else if ( ! isGlobal ) {
			const next = { id: 'tmp-' + Date.now(), user: 'me', created_at: new Date().toISOString(), ...formData };
			setStaticEntries( ( prev ) => [ next, ...prev ] );
			onCreate && onCreate( next );
		}
		setOpen( false );
	};

	const showSpinner = isLive && isLoading;

	return (
		<div className="bzc-activity-feed">
			<div className="bzc-activity-toolbar">
				<span className="bzc-muted">
					{ showSpinner
						? <Loader2 size={ 12 } className="bzc-spin" />
						: entries.length + ' activity' }
				</span>
				{ ! isGlobal && (
					<Button size="sm" variant="primary" onClick={ () => setOpen( true ) }>
						<Plus size={ 12 } /> Activity mới
					</Button>
				) }
			</div>
			<div className="bzc-activity-list">
				{ entries.length ? entries.map( ( a ) => <ActivityCard key={ a.id } activity={ a } /> ) : (
					<div className="bzc-empty bzc-muted">Chưa có activity.</div>
				) }
			</div>
			<Sheet open={ open } onOpenChange={ setOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Activity mới</SheetTitle></SheetHeader>
					<SheetBody>
						<ActivityForm onSubmit={ handleCreate } onCancel={ () => setOpen( false ) } submitting={ creating } />
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}
