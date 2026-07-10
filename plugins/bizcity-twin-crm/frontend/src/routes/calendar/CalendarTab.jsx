import React, { useEffect, useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Plus, RefreshCw, RotateCcw, Settings2, Trash2 } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Badge } from '../../components/ui/card.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input, Label } from '../../components/ui/input.jsx';
import {
	useGetSchedulerEventsQuery,
	useCreateSchedulerEventMutation,
	useUpdateSchedulerEventMutation,
	useDeleteSchedulerEventMutation,
	useGetGoogleAccountsQuery,
	useSyncGoogleMutation,
	useGetGoogleSettingsQuery,
	useSaveGoogleSettingsMutation,
	useDisconnectGoogleMutation,
	// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — FB post retry
	useRetryFbPostMutation,
} from '../../redux/api/schedulerApi.js';
// [2026-06-07 Johnny Chu] PHASE-0.40 G6.3 — CRM Events overlay on calendar
import {
	useGetCrmEventsQuery,
} from '../../redux/api/crmApi.js';

const TYPE_VARIANT = { meeting: 'default', internal: 'muted', workshop: 'warn', training: 'ok' };
const WEEKDAYS = [ 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN' ];

function startOfMonth( d ) { return new Date( d.getFullYear(), d.getMonth(), 1 ); }
function addMonths( d, n ) { return new Date( d.getFullYear(), d.getMonth() + n, 1 ); }
function daysInMonth( d ) { return new Date( d.getFullYear(), d.getMonth() + 1, 0 ).getDate(); }
function fmtMonth( d ) { return d.toLocaleDateString( 'vi-VN', { month: 'long', year: 'numeric' } ); }
function isoDay( d ) {
	const y = d.getFullYear(); const m = String( d.getMonth() + 1 ).padStart( 2, '0' ); const day = String( d.getDate() ).padStart( 2, '0' );
	return `${ y }-${ m }-${ day }`;
}

function EventForm( { defaultDate, initial, accounts = [], onSubmit, onCancel } ) {
	const dt = ( d, h ) => {
		const y = d.getFullYear(); const m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		const dd = String( d.getDate() ).padStart( 2, '0' ); const hh = String( h ).padStart( 2, '0' );
		return `${ y }-${ m }-${ dd }T${ hh }:00`;
	};
	const fromUnix = ( ts ) => {
		const d = new Date( Number( ts ) * 1000 );
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
	};
	const base = defaultDate || new Date();
	const [ data, setData ] = useState( () => initial ? {
		title:             initial.title || '',
		type:              initial.type || 'meeting',
		start_local:       fromUnix( initial.start_at ),
		end_local:         fromUnix( initial.end_at ),
		attendees:         ( initial.attendees || [] ).join( ', ' ),
		google_account_id: initial.google_account_id || '',
	} : {
		title: '', type: 'meeting',
		start_local: dt( base, 9 ), end_local: dt( base, 10 ),
		attendees: '', google_account_id: '',
	} );
	return (
		<form className="bzc-form" onSubmit={ ( e ) => {
			e.preventDefault();
			onSubmit( {
				title:             data.title,
				type:              data.type,
				start_at:          Math.floor( new Date( data.start_local ).getTime() / 1000 ),
				end_at:            Math.floor( new Date( data.end_local ).getTime() / 1000 ),
				attendees:         data.attendees ? data.attendees.split( /[\s,;]+/ ).filter( Boolean ) : [],
				google_account_id: data.google_account_id ? Number( data.google_account_id ) : null,
			} );
		} }>
			<div><Label>Tiêu đề</Label><Input value={ data.title } onChange={ ( e ) => setData( { ...data, title: e.target.value } ) } required autoFocus /></div>
			<div className="bzc-form-grid-2">
				<div><Label>Loại</Label>
					<select className="bzc-input" value={ data.type } onChange={ ( e ) => setData( { ...data, type: e.target.value } ) }>
						{ [ 'meeting', 'internal', 'workshop', 'training', 'personal', 'task', 'reminder' ].map( ( t ) => <option key={ t } value={ t }>{ t }</option> ) }
					</select>
				</div>
				<div><Label>Google account</Label>
					<select className="bzc-input" value={ data.google_account_id } onChange={ ( e ) => setData( { ...data, google_account_id: e.target.value } ) }>
						<option value="">— Mặc định —</option>
						{ accounts.filter( ( a ) => a.source === 'bzgoogle_hub' ).map( ( a ) => (
							<option key={ a.id } value={ a.id }>{ a.label }</option>
						) ) }
					</select>
				</div>
				<div><Label>Bắt đầu</Label><Input type="datetime-local" value={ data.start_local } onChange={ ( e ) => setData( { ...data, start_local: e.target.value } ) } required /></div>
				<div><Label>Kết thúc</Label><Input type="datetime-local" value={ data.end_local } onChange={ ( e ) => setData( { ...data, end_local: e.target.value } ) } required /></div>
			</div>
			<div><Label>Người tham gia (email, ngăn cách bởi dấu phẩy)</Label><Input value={ data.attendees } onChange={ ( e ) => setData( { ...data, attendees: e.target.value } ) } placeholder="alice@example.com, bob@example.com" /></div>
			<div className="bzc-form-actions">
				<Button type="button" onClick={ onCancel }>Huỷ</Button>
				<Button type="submit" variant="primary">{ initial ? 'Cập nhật' : 'Lưu sự kiện' }</Button>
			</div>
		</form>
	);
}

export default function CalendarTab() {
	const [ cursor, setCursor ] = useState( () => startOfMonth( new Date() ) );
	const [ detail, setDetail ] = useState( null );
	const [ formOpen, setFormOpen ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const [ googleOpen, setGoogleOpen ] = useState( false );

	const { from, to } = useMemo( () => {
		const start = startOfMonth( cursor );
		const end   = new Date( cursor.getFullYear(), cursor.getMonth() + 1, 0, 23, 59, 59 );
		return { from: Math.floor( start.getTime() / 1000 ), to: Math.floor( end.getTime() / 1000 ) };
	}, [ cursor ] );

	const { data: events = [], isFetching } = useGetSchedulerEventsQuery( { from, to } );
	const [ createEvent, { isLoading: creating } ] = useCreateSchedulerEventMutation();
	const [ updateEvent, { isLoading: updating } ] = useUpdateSchedulerEventMutation();
	const [ deleteEvent, { isLoading: deleting } ] = useDeleteSchedulerEventMutation();
	// [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — FB post retry mutation
	const [ retryFbPost, { isLoading: retrying } ] = useRetryFbPostMutation();
	const { data: googleData } = useGetGoogleAccountsQuery();
	const [ syncGoogle, { isLoading: syncing } ] = useSyncGoogleMutation();
	const accounts = googleData?.accounts || [];

	// [2026-06-07 Johnny Chu] PHASE-0.40 G6.3 — CRM events overlay
	const { data: crmEventsData } = useGetCrmEventsQuery( { from, to, limit: 200 } );
	const crmEvents = crmEventsData?.events || crmEventsData || [];

	const grid = useMemo( () => {
		// Monday-first grid.
		const first = startOfMonth( cursor );
		const startWeekday = ( first.getDay() + 6 ) % 7; // 0 = Mon
		const total = daysInMonth( cursor );
		const cells = [];
		for ( let i = 0; i < startWeekday; i++ ) { cells.push( null ); }
		for ( let d = 1; d <= total; d++ ) { cells.push( new Date( cursor.getFullYear(), cursor.getMonth(), d ) ); }
		while ( cells.length % 7 !== 0 ) { cells.push( null ); }
		return cells;
	}, [ cursor ] );

	const eventsByDay = useMemo( () => {
		const out = {};
		events.forEach( ( e ) => {
			const day = isoDay( new Date( ( e.start_at || 0 ) * 1000 ) );
			( out[ day ] || ( out[ day ] = [] ) ).push( e );
		} );
		return out;
	}, [ events ] );

	// [2026-06-07 Johnny Chu] PHASE-0.40 G6.3 — CRM events by day
	const crmEventsByDay = useMemo( () => {
		const out = {};
		( Array.isArray( crmEvents ) ? crmEvents : [] ).forEach( ( e ) => {
			const ts = e.scheduled_at || e.start_at || e.created_at || '';
			if ( ! ts ) { return; }
			const day = ts.length >= 10 ? ts.slice( 0, 10 ) : isoDay( new Date( ts * 1000 ) );
			( out[ day ] || ( out[ day ] = [] ) ).push( e );
		} );
		return out;
	}, [ crmEvents ] );

	const today = new Date();

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title">Calendar</h2>
					<p className="bzc-tabpane-subtitle">Lịch tháng — meeting, workshop, training. { isFetching ? '— đang tải…' : `(${ events.length })` }</p>
				</div>
				<div className="bzc-cal-toolbar">
					<Button size="sm" onClick={ () => setCursor( ( c ) => addMonths( c, -1 ) ) }><ChevronLeft size={ 12 } /></Button>
					<strong className="bzc-cal-month">{ fmtMonth( cursor ) }</strong>
					<Button size="sm" onClick={ () => setCursor( ( c ) => addMonths( c, 1 ) ) }><ChevronRight size={ 12 } /></Button>
					<Button size="sm" onClick={ () => setCursor( startOfMonth( new Date() ) ) }>Hôm nay</Button>
					{ accounts.length > 0 && (
						<Button size="sm" disabled={ syncing } onClick={ async () => {
							try { await syncGoogle( {} ).unwrap(); } catch ( err ) { alert( 'Sync Google thất bại: ' + ( err?.data?.error || err.message || 'unknown' ) ); }
						} }><RefreshCw size={ 12 } /> { syncing ? 'Đang sync…' : 'Sync Google' }</Button>
					) }
					<Button size="sm" title="Cấu hình Google Calendar" onClick={ () => setGoogleOpen( true ) }><Settings2 size={ 12 } /> Google</Button>
					<Button size="sm" variant="primary" onClick={ () => { setEditing( null ); setFormOpen( true ); } }><Plus size={ 12 } /> Sự kiện</Button>
				</div>
			</header>

			<div className="bzc-cal-grid">
				{ WEEKDAYS.map( ( w ) => <div key={ w } className="bzc-cal-weekday">{ w }</div> ) }
				{ grid.map( ( cell, idx ) => {
					if ( ! cell ) { return <div key={ 'e' + idx } className="bzc-cal-cell bzc-cal-cell-empty" />; }
					const dayKey = isoDay( cell );
					const events = eventsByDay[ dayKey ] || [];
					const isToday = isoDay( today ) === dayKey;
					return (
						<div key={ dayKey } className={ 'bzc-cal-cell ' + ( isToday ? 'is-today' : '' ) }>
							<div className="bzc-cal-cell-head">{ cell.getDate() }</div>
							<div className="bzc-cal-cell-events">
								{ events.slice( 0, 3 ).map( ( e ) => (
									<button key={ e.id } type="button" className="bzc-cal-event" onClick={ () => setDetail( e ) }>
										<span>{ new Date( ( e.start_at || 0 ) * 1000 ).toLocaleTimeString( 'vi-VN', { hour: '2-digit', minute: '2-digit' } ) }</span>
										<span className="bzc-cal-event-title">{ e.title }</span>
									</button>
								) ) }
								{ events.length > 3 && <div className="bzc-muted bzc-cal-event-more">+{ events.length - 3 } khác</div> }
								{ /* [2026-06-07 Johnny Chu] PHASE-0.40 G6.3 — CRM events overlay dots */ }
								{ ( crmEventsByDay[ dayKey ] || [] ).slice( 0, 2 ).map( ( ce ) => (
									<div key={ 'crm-' + ce.id } title={ ce.title || ce.type } style={ { fontSize: 10, color: '#8b5cf6', background: '#ede9fe', borderRadius: 2, padding: '1px 4px', marginTop: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }>
										◆ { ce.title || ce.type || 'CRM event' }
									</div>
								) ) }
								{ ( crmEventsByDay[ dayKey ] || [] ).length > 2 && <div style={ { fontSize: 10, color: '#8b5cf6' } }>+{ ( crmEventsByDay[ dayKey ] ).length - 2 } CRM</div> }
							</div>
						</div>
					);
				} ) }
			</div>

			<Sheet open={ !! detail } onOpenChange={ ( v ) => { if ( ! v ) { setDetail( null ); } } }>
				<SheetContent>
					<SheetHeader><SheetTitle>{ detail?.title }</SheetTitle></SheetHeader>
					<SheetBody>
						{ detail && (
							<>
								<div className="bzc-kv">
									<dt>Loại</dt><dd><Badge variant={ TYPE_VARIANT[ detail.type ] || 'default' }>{ detail.type }</Badge></dd>
									<dt>Bắt đầu</dt><dd>{ new Date( ( detail.start_at || 0 ) * 1000 ).toLocaleString( 'vi-VN' ) }</dd>
									<dt>Kết thúc</dt><dd>{ new Date( ( detail.end_at || 0 ) * 1000 ).toLocaleString( 'vi-VN' ) }</dd>
									<dt>Tham gia</dt><dd>{ detail.attendees?.join( ', ' ) || '—' }</dd>
									{ detail.google_event_id && <><dt>Google</dt><dd>✅ Đã đồng bộ ({ detail.google_calendar_id || 'primary' })</dd></> }
									{ detail.contact_id && <><dt>Contact</dt><dd>#{ detail.contact_id }</dd></> }
									{ /* [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — FB post status */ }
									{ detail.event_type === 'fb_post' && ( () => {
										const meta = detail.metadata ? ( typeof detail.metadata === 'string' ? JSON.parse( detail.metadata ) : detail.metadata ) : {};
										const fbStatus = meta.fb_publish_status || 'pending';
										const statusColor = fbStatus === 'published' ? '#16a34a' : fbStatus === 'failed' ? '#dc2626' : '#ca8a04';
										return (
											<><dt>FB Status</dt><dd style={ { color: statusColor, fontWeight: 600 } }>{ fbStatus }{ meta.fb_error ? ` — ${meta.fb_error}` : '' }</dd></>
										);
									} )() }
								</div>
								<div className="bzc-form-actions bzc-mt-md">
									<Button onClick={ () => { setEditing( detail ); setDetail( null ); setFormOpen( true ); } }>Sửa</Button>
									{ /* [2026-06-13 Johnny Chu] PHASE-0.40 G7 CG-SCHEDULER-P7 — Retry button for failed FB posts */ }
									{ detail.event_type === 'fb_post' && ( () => {
										const meta = detail.metadata ? ( typeof detail.metadata === 'string' ? JSON.parse( detail.metadata ) : detail.metadata ) : {};
										if ( meta.fb_publish_status !== 'failed' ) return null;
										return (
											<Button
												variant="outline"
												disabled={ retrying }
												onClick={ async () => {
													try {
														await retryFbPost( detail.id ).unwrap();
														setDetail( null );
													} catch ( err ) {
														alert( 'Retry thất bại: ' + ( err?.data?.message || err.message || 'unknown' ) );
													}
												} }
											>
												<RotateCcw size={ 12 } /> { retrying ? 'Đang retry…' : 'Thử lại đăng FB' }
											</Button>
										);
									} )() }
									<Button variant="danger" disabled={ deleting } onClick={ async () => {
										if ( ! confirm( 'Xoá sự kiện này?' ) ) { return; }
										try {
											await deleteEvent( detail.id ).unwrap();
											setDetail( null );
										} catch ( err ) {
											alert( 'Xoá thất bại: ' + ( err?.data?.error || err.message || 'unknown' ) );
										}
									} }><Trash2 size={ 12 } /> Xoá</Button>
								</div>
							</>
						) }
					</SheetBody>
				</SheetContent>
			</Sheet>

			<Sheet open={ formOpen } onOpenChange={ ( v ) => { setFormOpen( v ); if ( ! v ) { setEditing( null ); } } }>
				<SheetContent>
					<SheetHeader><SheetTitle>{ editing ? 'Sửa sự kiện' : 'Sự kiện mới' }</SheetTitle></SheetHeader>
					<SheetBody>
						<EventForm
							defaultDate={ cursor }
							initial={ editing }
							accounts={ accounts }
							onCancel={ () => { setFormOpen( false ); setEditing( null ); } }
							onSubmit={ async ( payload ) => {
								try {
									if ( editing ) {
										await updateEvent( { id: editing.id, ...payload } ).unwrap();
									} else {
										await createEvent( payload ).unwrap();
									}
									setFormOpen( false );
									setEditing( null );
								} catch ( err ) {
									console.error( '[bizcity-crm] save event failed', err );
									alert( 'Lưu sự kiện thất bại: ' + ( err?.data?.error || err.message || 'unknown' ) );
								}
							} }
						/>
						{ ( creating || updating ) && <p className="bzc-muted bzc-mt-sm">Đang lưu…</p> }
					</SheetBody>
				</SheetContent>
			</Sheet>

			<Sheet open={ googleOpen } onOpenChange={ setGoogleOpen }>
				<SheetContent>
					<SheetHeader><SheetTitle>Google Calendar</SheetTitle></SheetHeader>
					<SheetBody>
						<GoogleSettingsForm open={ googleOpen } />
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}

function GoogleSettingsForm( { open } ) {
	const { data: settings, isFetching, refetch } = useGetGoogleSettingsQuery( undefined, { skip: ! open } );
	const [ saveSettings, { isLoading: saving } ] = useSaveGoogleSettingsMutation();
	const [ disconnectGoogle, { isLoading: disconnecting } ] = useDisconnectGoogleMutation();
	const [ form, setForm ] = useState( { client_id: '', client_secret: '', calendar_id: 'primary' } );

	useEffect( () => {
		if ( settings ) {
			setForm( {
				client_id:     settings.client_id || '',
				client_secret: '',
				calendar_id:   settings.calendar_id || 'primary',
			} );
		}
	}, [ settings ] );

	if ( ! open ) { return null; }
	if ( isFetching && ! settings ) { return <p className="bzc-muted">Đang tải cấu hình…</p>; }

	const connected   = !! settings?.connected;
	const hasSecret   = !! settings?.has_client_secret;
	const redirectUri = settings?.redirect_uri || '';
	const authUrl     = settings?.auth_url || '';

	const handleSave = async ( e ) => {
		e.preventDefault();
		const body = { calendar_id: form.calendar_id || 'primary' };
		if ( form.client_id.trim() ) { body.client_id = form.client_id.trim(); }
		if ( form.client_secret.trim() ) { body.client_secret = form.client_secret.trim(); }
		try {
			await saveSettings( body ).unwrap();
			setForm( ( f ) => ( { ...f, client_secret: '' } ) );
			refetch();
		} catch ( err ) {
			alert( 'Lưu thất bại: ' + ( err?.data?.error || err.message || 'unknown' ) );
		}
	};

	const handleDisconnect = async () => {
		if ( ! confirm( 'Ngắt kết nối Google Calendar?' ) ) { return; }
		try {
			await disconnectGoogle().unwrap();
			refetch();
		} catch ( err ) {
			alert( 'Ngắt thất bại: ' + ( err?.data?.error || err.message || 'unknown' ) );
		}
	};

	return (
		<form className="bzc-form" onSubmit={ handleSave }>
			<div className="bzc-form-row">
				<Badge variant={ connected ? 'ok' : 'muted' }>{ connected ? 'Đã kết nối' : 'Chưa kết nối' }</Badge>
			</div>

			<div>
				<Label>Client ID</Label>
				<Input value={ form.client_id } onChange={ ( e ) => setForm( { ...form, client_id: e.target.value } ) } placeholder="xxxxx.apps.googleusercontent.com" />
			</div>
			<div>
				<Label>Client Secret { hasSecret && <span className="bzc-muted">(đang có — để trống nếu giữ nguyên)</span> }</Label>
				<Input type="password" value={ form.client_secret } onChange={ ( e ) => setForm( { ...form, client_secret: e.target.value } ) } placeholder={ hasSecret ? '••••••••' : 'GOCSPX-…' } />
			</div>
			<div>
				<Label>Calendar ID</Label>
				<Input value={ form.calendar_id } onChange={ ( e ) => setForm( { ...form, calendar_id: e.target.value } ) } placeholder="primary" />
			</div>
			<div>
				<Label>Redirect URI <span className="bzc-muted">(copy vào Google Console)</span></Label>
				<Input value={ redirectUri } readOnly onFocus={ ( e ) => e.target.select() } />
			</div>

			<div className="bzc-form-actions">
				<Button type="submit" variant="primary" disabled={ saving }>{ saving ? 'Đang lưu…' : 'Lưu cấu hình' }</Button>
				{ authUrl && (
					<Button type="button" onClick={ () => window.open( authUrl, '_blank', 'noopener' ) }>
						{ connected ? 'Kết nối lại' : 'Kết nối Google' }
					</Button>
				) }
				{ connected && (
					<Button type="button" variant="danger" disabled={ disconnecting } onClick={ handleDisconnect }>
						{ disconnecting ? 'Đang ngắt…' : 'Ngắt kết nối' }
					</Button>
				) }
			</div>

			{ ! authUrl && (
				<p className="bzc-muted bzc-mt-sm">Nhập Client ID + Secret và lưu trước, sau đó nút "Kết nối Google" sẽ hiện.</p>
			) }
		</form>
	);
}
