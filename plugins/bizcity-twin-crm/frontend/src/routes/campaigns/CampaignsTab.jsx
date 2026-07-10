/**
 * Campaigns Tab — PHASE 0.35 M6.W8 (FE Campaign Authoring UI).
 *
 * Single-file split layout:
 *   left  : DataTable-lite (name / code / status / visits / conversions)
 *   right : Sheet form (create or edit) + Detail pane (QR + funnel + share link)
 *
 * Uses crmApi RTK slice (M6.W8 endpoints):
 *   getCampaigns / getCampaign / createCampaign / updateCampaign / deleteCampaign
 *   getCampaignStats / getCampaignUrl
 *
 * BE backed by:
 *   GET    /campaigns                         (M6.W1)
 *   POST   /campaigns                         (M6.W1)
 *   GET    /campaigns/{id}                    (M6.W1)
 *   PATCH  /campaigns/{id}                    (M6.W1)
 *   DELETE /campaigns/{id}                    (M6.W1)
 *   GET    /campaigns/{id}/stats              (M6.W1)
 *   GET    /campaigns/{id}/url                (M6.W2)
 *   GET    /campaigns/{id}/qr.svg|.png        (M6.W2)
 *
 * Bound character / welcome template / notebook fields are intentionally OMITTED
 * here — they ship in M6.W9 (schema bump + binding bridge) along with the
 * /campaigns/{id}/dropdowns endpoint that populates the selects.
 */
import React, { useMemo, useState } from 'react';
import { Megaphone, Plus, RefreshCw, Pencil, Trash2, Search } from 'lucide-react';
import { Button } from '../../components/ui/button.jsx';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetBody } from '../../components/ui/sheet.jsx';
import { Input } from '../../components/ui/input.jsx';
import {
	useGetCampaignsQuery,
	useGetCampaignStatsQuery,
	useGetCampaignUrlQuery,
	useGetCampaignFunnelQuery,
	useGetCampaignDropdownsQuery,
	useGetCampaignMessengerLinkQuery,
	usePreviewCampaignPromptMutation,
	useCreateCampaignMutation,
	useUpdateCampaignMutation,
	useDeleteCampaignMutation,
} from '../../redux/api/crmApi.js';

import AssetStudioPanel from './AssetStudioPanel.jsx';
import PrintAdsPanel    from './PrintAdsPanel.jsx';

const STATUSES = [
	{ value: 'draft',    label: 'Draft' },
	{ value: 'active',   label: 'Active' },
	{ value: 'paused',   label: 'Paused' },
	{ value: 'archived', label: 'Archived' },
];

/* PHASE 0.35 M6.W15 — scenario action enum (mirrors BE Campaign_Repository::ACTION_*) */
const ACTION_TYPES = [
	{ value: 'send_message',      label: 'Gửi message',           hint: 'Render template + insert outbound message vào conversation.' },
	{ value: 'run_shortcode',     label: 'Chạy shortcode',        hint: 'Thực thi shortcode (whitelist) và đẩy kết quả làm message.' },
	{ value: 'kg_grounded_reply', label: 'KG grounded reply',     hint: 'Sinh trả lời từ notebook + prompt (RAG).' },
	{ value: 'delay_only',        label: 'Chỉ reminder (no-op)',  hint: 'Không gửi gì ngay; chỉ schedule reminder ở dưới.' },
];

const REMINDER_UNITS = [
	{ value: 'minutes', label: 'phút' },
	{ value: 'hours',   label: 'giờ' },
	{ value: 'days',    label: 'ngày' },
];

const REST_BASE = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT && window.BIZCITY_CRM_BOOT.restUrl ) || '/wp-json/bizcity-crm/v1/';

function statusPill( status ) {
	const colorMap = {
		draft:    '#94a3b8',
		active:   '#10b981',
		paused:   '#f59e0b',
		archived: '#64748b',
	};
	return (
		<span className="bzc-chip" style={ { background: colorMap[ status ] || '#64748b', color: '#fff' } }>
			{ status || 'draft' }
		</span>
	);
}

/* PHASE 0.35 M6.W16 — colored badge per scenario action_type, used in list table. */
function actionBadge( type ) {
	const map = {
		send_message:      { bg: '#dbeafe', fg: '#1d4ed8', label: 'message' },
		run_shortcode:     { bg: '#fef3c7', fg: '#b45309', label: 'shortcode' },
		kg_grounded_reply: { bg: '#ede9fe', fg: '#6d28d9', label: 'KG reply' },
		delay_only:        { bg: '#f1f5f9', fg: '#475569', label: 'delay-only' },
	};
	const cfg = map[ type ] || { bg: '#f1f5f9', fg: '#64748b', label: type || '—' };
	return (
		<span className="bzc-chip" style={ { background: cfg.bg, color: cfg.fg, fontSize: 11 } }>
			{ cfg.label }
		</span>
	);
}

function slugify( s ) {
	return ( s || '' )
		.toString()
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.replace( /đ/g, 'd' )
		.replace( /[^a-z0-9-]+/g, '-' )
		.replace( /-+/g, '-' )
		.replace( /^-|-$/g, '' );
}

/* Convert scenario_attrs_json (stored on the row) → array of {k,v} pairs for editing. */
function attrsObjectToPairs( obj ) {
	if ( ! obj || typeof obj !== 'object' ) { return []; }
	return Object.keys( obj ).slice( 0, 20 ).map( ( k ) => ( { k, v: String( obj[ k ] ?? '' ) } ) );
}
function attrsPairsToObject( pairs ) {
	const out = {};
	pairs.forEach( ( p ) => {
		const k = ( p.k || '' ).trim();
		if ( k ) { out[ k ] = String( p.v ?? '' ); }
	} );
	return out;
}

/* --------------------------------------------------------------------------
 * Form
 * -------------------------------------------------------------------------- */

function CampaignForm( { initial, onDone } ) {
	const editing = !! ( initial && initial.id );
	const [ name,     setName     ] = useState( initial?.name        || '' );
	const [ code,     setCode     ] = useState( initial?.code        || '' );
	const [ status,   setStatus   ] = useState( initial?.status      || 'draft' );
	const [ landing,  setLanding  ] = useState( initial?.landing_url || '' );
	const [ utmSrc,   setUtmSrc   ] = useState( initial?.utm_source   || '' );
	const [ utmMed,   setUtmMed   ] = useState( initial?.utm_medium   || '' );
	const [ utmCam,   setUtmCam   ] = useState( initial?.utm_campaign || '' );
	const [ points,   setPoints   ] = useState( initial?.loyalty_points_award != null ? String( initial.loyalty_points_award ) : '0' );
	const [ welcomeId, setWelcomeId ] = useState( initial?.welcome_template_id ? String( initial.welcome_template_id ) : '' );
	const [ charId,    setCharId    ] = useState( initial?.bound_character_id  ? String( initial.bound_character_id  ) : '' );
	const [ nbId,      setNbId      ] = useState( initial?.bound_notebook_id   ? String( initial.bound_notebook_id   ) : '' );
	const [ codeTouched, setCodeTouched ] = useState( editing );

	/* PHASE 0.35 M6.W15 — scenario builder fields */
	const [ actionType,    setActionType    ] = useState( initial?.scenario_action_type || 'send_message' );
	const [ scShortcode,   setScShortcode   ] = useState( initial?.scenario_shortcode   || '' );
	const [ scTemplate,    setScTemplate    ] = useState( initial?.scenario_template    || '' );
	const [ scPrompt,      setScPrompt      ] = useState( initial?.scenario_prompt      || '' );
	const [ scAttrs,       setScAttrs       ] = useState( () => attrsObjectToPairs( initial?.scenario_attrs ) );
	const [ remDelay,      setRemDelay      ] = useState( initial?.reminder_delay != null ? String( initial.reminder_delay ) : '0' );
	const [ remUnit,       setRemUnit       ] = useState( initial?.reminder_unit || 'minutes' );
	const [ remText,       setRemText       ] = useState( initial?.reminder_text || '' );
	const [ remOnly,       setRemOnly       ] = useState( !! initial?.reminder_only );

	// [2026-06-07 Johnny Chu] PHASE-0.40 G4.3 — multi-variant state (Deplao parity)
	const [ variants,     setVariants     ] = useState( () => ( initial?.variants && initial.variants.length ? initial.variants : [] ) );
	const [ variantMode,  setVariantMode  ] = useState( initial?.variant_mode || 'random' );
	const addVariant    = () => setVariants( ( prev ) => ( prev.length >= 10 ? prev : [ ...prev, { text: '' } ] ) );
	const removeVariant = ( idx ) => setVariants( ( prev ) => prev.filter( ( _, i ) => i !== idx ) );
	const setVariantText = ( idx, val ) => setVariants( ( prev ) => prev.map( ( v, i ) => ( i === idx ? { ...v, text: val } : v ) ) );

	// M6.W9 — dropdowns are scoped to the (potentially-existing) campaign id; on
	// create we still hit the route with id=0 which yields the same global lists.
	const { data: dropdowns } = useGetCampaignDropdownsQuery( initial?.id || 0 );

	const [ create, { isLoading: c1, error: e1 } ] = useCreateCampaignMutation();
	const [ update, { isLoading: c2, error: e2 } ] = useUpdateCampaignMutation();
	const [ previewPrompt, { data: previewData, isLoading: previewing, error: previewErr } ] = usePreviewCampaignPromptMutation();
	const busy  = c1 || c2;
	const error = e1 || e2;

	const onNameChange = ( e ) => {
		setName( e.target.value );
		if ( ! codeTouched ) { setCode( slugify( e.target.value ) ); }
	};

	const setAttrPair = ( idx, patch ) => {
		setScAttrs( ( prev ) => prev.map( ( p, i ) => ( i === idx ? { ...p, ...patch } : p ) ) );
	};
	const addAttrPair    = () => setScAttrs( ( prev ) => ( prev.length >= 20 ? prev : [ ...prev, { k: '', v: '' } ] ) );
	const removeAttrPair = ( idx ) => setScAttrs( ( prev ) => prev.filter( ( _, i ) => i !== idx ) );

	const onPreviewPrompt = async () => {
		if ( ! editing ) { return; }
		try {
			await previewPrompt( {
				id: initial.id,
				prompt:    scPrompt || null,
				notebook_id: nbId ? parseInt( nbId, 10 ) : null,
				template:  scTemplate || null,
			} ).unwrap();
		} catch ( _ ) { /* surfaced via previewErr */ }
	};

	const submit = async ( e ) => {
		e.preventDefault();
		if ( ! name.trim() || ! code.trim() ) { return; }
		const body = {
			name: name.trim(),
			code: code.trim(),
			status,
			landing_url: landing.trim(),
			utm_source: utmSrc.trim(),
			utm_medium: utmMed.trim(),
			utm_campaign: utmCam.trim(),
			loyalty_points_award: parseInt( points, 10 ) || 0,
			welcome_template_id: welcomeId ? parseInt( welcomeId, 10 ) : null,
			bound_character_id:  charId    ? parseInt( charId,    10 ) : null,
			bound_notebook_id:   nbId      ? parseInt( nbId,      10 ) : null,

			/* M6.W15 scenario payload */
			scenario_action_type: actionType,
			scenario_shortcode:   actionType === 'run_shortcode'     ? scShortcode.trim() || null : null,
			scenario_template:    actionType === 'send_message'      ? scTemplate.trim()  || null : null,
			scenario_prompt:      actionType === 'kg_grounded_reply' ? scPrompt.trim()    || null : null,
			scenario_attrs:       actionType === 'run_shortcode'     ? attrsPairsToObject( scAttrs ) : null,
			reminder_delay:       Math.max( 0, parseInt( remDelay, 10 ) || 0 ),
			reminder_unit:        remUnit,
			reminder_text:        remText.trim() || null,
			reminder_only:        remOnly ? 1 : 0,
			// [2026-06-07 Johnny Chu] PHASE-0.40 G4.3 — multi-variant payload
			variants:             variants.filter( ( v ) => v.text && v.text.trim() ),
			variant_mode:         variantMode,
		};
		try {
			if ( editing ) { await update( { id: initial.id, ...body } ).unwrap(); }
			else           { await create( body ).unwrap(); }
			onDone && onDone();
		} catch ( _ ) { /* surfaced via error state below */ }
	};

	const shortcodes    = dropdowns?.shortcodes    || [];
	const reminderUnits = dropdowns?.reminder_units || REMINDER_UNITS.map( ( u ) => u.value );
	const remUnitLabel  = ( v ) => REMINDER_UNITS.find( ( u ) => u.value === v )?.label || v;

	return (
		<form className="bzc-form" onSubmit={ submit } style={ { padding: 14, display: 'grid', gap: 10 } }>
			<label style={ { display: 'grid', gap: 4 } }>
				<span className="bzc-muted" style={ { fontSize: 12 } }>Tên campaign *</span>
				<input className="bzc-input" value={ name } onChange={ onNameChange } placeholder="Khai trương 2026" autoFocus />
			</label>

			<label style={ { display: 'grid', gap: 4 } }>
				<span className="bzc-muted" style={ { fontSize: 12 } }>Code (auto-slug, dùng cho ref=camp_*)</span>
				<input
					className="bzc-input" value={ code }
					onChange={ ( e ) => { setCode( e.target.value ); setCodeTouched( true ); } }
					placeholder="khai-truong-2026" pattern="[a-z0-9-]+"
				/>
			</label>

			<label style={ { display: 'grid', gap: 4 } }>
				<span className="bzc-muted" style={ { fontSize: 12 } }>Trạng thái</span>
				<select className="bzc-input" value={ status } onChange={ ( e ) => setStatus( e.target.value ) }>
					{ STATUSES.map( ( s ) => <option key={ s.value } value={ s.value }>{ s.label }</option> ) }
				</select>
			</label>

			<label style={ { display: 'grid', gap: 4 } }>
				<span className="bzc-muted" style={ { fontSize: 12 } }>Landing URL (web mode)</span>
				<input className="bzc-input" value={ landing } onChange={ ( e ) => setLanding( e.target.value ) } placeholder="https://example.com/landing" />
			</label>

			<details>
				<summary style={ { cursor: 'pointer', fontSize: 12, color: '#475569' } }>UTM tags</summary>
				<div style={ { display: 'grid', gap: 8, marginTop: 8 } }>
					<input className="bzc-input" placeholder="utm_source (vd: qr)" value={ utmSrc } onChange={ ( e ) => setUtmSrc( e.target.value ) } />
					<input className="bzc-input" placeholder="utm_medium (vd: poster)" value={ utmMed } onChange={ ( e ) => setUtmMed( e.target.value ) } />
					<input className="bzc-input" placeholder="utm_campaign (mặc định = code)" value={ utmCam } onChange={ ( e ) => setUtmCam( e.target.value ) } />
				</div>
			</details>

			<label style={ { display: 'grid', gap: 4 } }>
				<span className="bzc-muted" style={ { fontSize: 12 } }>Điểm thưởng khi conversion (M6.W5)</span>
				<input className="bzc-input" type="number" min="0" value={ points } onChange={ ( e ) => setPoints( e.target.value ) } />
			</label>

			<details open>
				<summary style={ { cursor: 'pointer', fontSize: 12, color: '#475569' } }>Welcome / persona binding (M6.W9)</summary>
				<div style={ { display: 'grid', gap: 8, marginTop: 8 } }>
					<label style={ { display: 'grid', gap: 4 } }>
						<span className="bzc-muted" style={ { fontSize: 12 } }>Welcome template (macro)</span>
						<select className="bzc-input" value={ welcomeId } onChange={ ( e ) => setWelcomeId( e.target.value ) }>
							<option value="">— không gửi —</option>
							{ ( dropdowns?.templates || [] ).map( ( t ) => (
								<option key={ t.id } value={ t.id }>{ t.name }</option>
							) ) }
						</select>
					</label>
					<label style={ { display: 'grid', gap: 4 } }>
						<span className="bzc-muted" style={ { fontSize: 12 } }>Bound character (chuyển persona AI)</span>
						<select className="bzc-input" value={ charId } onChange={ ( e ) => setCharId( e.target.value ) }>
							<option value="">— giữ nguyên —</option>
							{ ( dropdowns?.characters || [] ).map( ( c ) => (
								<option key={ c.id } value={ c.id }>{ c.name }</option>
							) ) }
						</select>
					</label>
					<label style={ { display: 'grid', gap: 4 } }>
						<span className="bzc-muted" style={ { fontSize: 12 } }>Bound notebook (KG grounding)</span>
						<select className="bzc-input" value={ nbId } onChange={ ( e ) => setNbId( e.target.value ) }>
							<option value="">— không gắn —</option>
							{ ( dropdowns?.notebooks || [] ).map( ( n ) => (
								<option key={ n.id } value={ n.id }>{ n.name }</option>
							) ) }
						</select>
					</label>
				</div>
			</details>

			{ /* PHASE 0.35 M6.W15 — Scenario Builder */ }
			<details open>
				<summary style={ { cursor: 'pointer', fontSize: 12, color: '#475569', fontWeight: 600 } }>
					Kịch bản dispatch (M6.W15)
				</summary>
				<div style={ { display: 'grid', gap: 10, marginTop: 8, padding: 10, background: '#f8fafc', borderRadius: 6 } }>

					{ /* Action type — radio grid */ }
					<div style={ { display: 'grid', gap: 6 } }>
						<span className="bzc-muted" style={ { fontSize: 12 } }>Loại action *</span>
						<div style={ { display: 'grid', gap: 6 } }>
							{ ACTION_TYPES.map( ( opt ) => (
								<label
									key={ opt.value }
									style={ {
										display: 'flex', alignItems: 'flex-start', gap: 8,
										padding: 8, borderRadius: 4, cursor: 'pointer',
										background: actionType === opt.value ? '#eef2ff' : '#fff',
										border: '1px solid ' + ( actionType === opt.value ? '#6366f1' : '#e2e8f0' ),
									} }
								>
									<input
										type="radio" name="scenario_action_type"
										value={ opt.value } checked={ actionType === opt.value }
										onChange={ ( e ) => setActionType( e.target.value ) }
										style={ { marginTop: 2 } }
									/>
									<span>
										<div style={ { fontSize: 13, fontWeight: 500 } }>{ opt.label }</div>
										<div className="bzc-muted" style={ { fontSize: 11 } }>{ opt.hint }</div>
									</span>
								</label>
							) ) }
						</div>
					</div>

					{ /* Branch fields */ }
					{ actionType === 'send_message' && (
						<label style={ { display: 'grid', gap: 4 } }>
							<span className="bzc-muted" style={ { fontSize: 12 } }>Template message (hỗ trợ {`{{vars}}`})</span>
							<textarea
								className="bzc-input" rows={ 4 }
								placeholder="Chào {{contact.name}}, cảm ơn bạn đã quan tâm campaign {{campaign.name}}…"
								value={ scTemplate } onChange={ ( e ) => setScTemplate( e.target.value ) }
							/>
						</label>
					) }

					{ actionType === 'run_shortcode' && (
						<>
							<label style={ { display: 'grid', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>
									Shortcode (whitelist {shortcodes.length} mục — server từ chối các tag khác)
								</span>
								<select className="bzc-input" value={ scShortcode } onChange={ ( e ) => setScShortcode( e.target.value ) }>
									<option value="">— chọn shortcode —</option>
									{ shortcodes.map( ( sc ) => (
										<option key={ sc } value={ sc }>{ sc }</option>
									) ) }
								</select>
							</label>
							<div style={ { display: 'grid', gap: 4 } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
									<span className="bzc-muted" style={ { fontSize: 12 } }>
										Attrs (key=value · tối đa 20 · render thành {`[shortcode key="value"]`})
									</span>
									<button type="button" className="bzc-btn-ghost" onClick={ addAttrPair } disabled={ scAttrs.length >= 20 }>
										+ Thêm attr
									</button>
								</div>
								{ scAttrs.length === 0 && (
									<div className="bzc-muted" style={ { fontSize: 11, fontStyle: 'italic' } }>
										Chưa có attr — bấm “+ Thêm attr” để cấu hình.
									</div>
								) }
								{ scAttrs.map( ( p, idx ) => (
									<div key={ idx } style={ { display: 'grid', gridTemplateColumns: '1fr 1fr 32px', gap: 6 } }>
										<input
											className="bzc-input" placeholder="key (vd: keyword)"
											value={ p.k } onChange={ ( e ) => setAttrPair( idx, { k: e.target.value } ) }
										/>
										<input
											className="bzc-input" placeholder="value"
											value={ p.v } onChange={ ( e ) => setAttrPair( idx, { v: e.target.value } ) }
										/>
										<button
											type="button" className="bzc-btn-ghost bzc-danger"
											title="Xoá" onClick={ () => removeAttrPair( idx ) }
										>×</button>
									</div>
								) ) }
							</div>
						</>
					) }

					{ actionType === 'kg_grounded_reply' && (
						<>
							<label style={ { display: 'grid', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>
									Prompt RAG ({nbId ? 'grounded vào notebook đã chọn ở trên' : 'chưa chọn notebook — sẽ trả về template thuần'})
								</span>
								<textarea
									className="bzc-input" rows={ 4 }
									placeholder="Hãy giới thiệu chương trình ưu đãi {{campaign.name}} cho khách hàng dựa trên thông tin từ notebook."
									value={ scPrompt } onChange={ ( e ) => setScPrompt( e.target.value ) }
								/>
							</label>
							<label style={ { display: 'grid', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 12 } }>Fallback template (khi KG không tìm thấy match)</span>
								<textarea
									className="bzc-input" rows={ 2 }
									placeholder="Cảm ơn bạn quan tâm campaign {{campaign.name}}!"
									value={ scTemplate } onChange={ ( e ) => setScTemplate( e.target.value ) }
								/>
							</label>
							{ editing && (
								<div>
									<button type="button" className="bzc-btn-ghost" onClick={ onPreviewPrompt } disabled={ previewing }>
										{ previewing ? 'Đang preview…' : '⚡ Preview prompt rendered' }
									</button>
									{ !! previewErr && (
										<div className="bzc-muted bzc-danger" style={ { fontSize: 11, marginTop: 6 } }>
											{ previewErr?.data?.error || 'Preview lỗi.' }
										</div>
									) }
									{ !! previewData && (
										<pre style={ {
											fontSize: 11, background: '#0f172a', color: '#e2e8f0',
											padding: 8, borderRadius: 4, marginTop: 6, overflow: 'auto',
											maxHeight: 200, whiteSpace: 'pre-wrap',
										} }>
											{ previewData.preview || previewData.rendered || JSON.stringify( previewData, null, 2 ) }
										</pre>
									) }
								</div>
							) }
						</>
					) }

					{ actionType === 'delay_only' && (
						<div className="bzc-muted" style={ { fontSize: 12, fontStyle: 'italic' } }>
							Không gửi gì khi user nhắn đầu tiên — chỉ schedule reminder bên dưới (nếu &gt; 0).
						</div>
					) }

					{ /* Reminder section — luôn hiển thị */ }
					<div style={ { borderTop: '1px solid #e2e8f0', paddingTop: 10, marginTop: 4, display: 'grid', gap: 8 } }>
						<div className="bzc-muted" style={ { fontSize: 12, fontWeight: 600 } }>Reminder (W14 reaper)</div>
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 } }>
							<label style={ { display: 'grid', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 11 } }>Delay (0 = không gửi reminder)</span>
								<input
									className="bzc-input" type="number" min="0" step="1"
									value={ remDelay } onChange={ ( e ) => setRemDelay( e.target.value ) }
								/>
							</label>
							<label style={ { display: 'grid', gap: 4 } }>
								<span className="bzc-muted" style={ { fontSize: 11 } }>Đơn vị</span>
								<select className="bzc-input" value={ remUnit } onChange={ ( e ) => setRemUnit( e.target.value ) }>
									{ reminderUnits.map( ( u ) => (
										<option key={ u } value={ u }>{ remUnitLabel( u ) }</option>
									) ) }
								</select>
							</label>
						</div>
						<label style={ { display: 'grid', gap: 4 } }>
							<span className="bzc-muted" style={ { fontSize: 11 } }>Nội dung reminder (nếu trống sẽ dùng template ở trên)</span>
							<textarea
								className="bzc-input" rows={ 2 }
								placeholder="Bạn có quan tâm chương trình {{campaign.name}} chứ ạ?"
								value={ remText } onChange={ ( e ) => setRemText( e.target.value ) }
							/>
						</label>
						<label style={ { display: 'flex', alignItems: 'center', gap: 6, fontSize: 12 } }>
							<input
								type="checkbox" checked={ remOnly }
								onChange={ ( e ) => setRemOnly( e.target.checked ) }
							/>
							<span>
								Chỉ gửi reminder, bỏ qua dispatch lần đầu
								<span className="bzc-muted" style={ { marginLeft: 4 } }>
									(reminder_only=1 — ép dispatcher skip immediate, chỉ schedule reminder)
								</span>
							</span>
						</label>
					</div>
				</div>
			</details>

			{ /* [2026-06-07 Johnny Chu] PHASE-0.40 G4.3 — Multi-variant content (Deplao parity) */ }
			<details>
				<summary style={ { cursor: 'pointer', fontSize: 12, color: '#475569' } }>Nội dung đa biến thể (Deplao parity)</summary>
				<div style={ { marginTop: 8, display: 'grid', gap: 8 } }>
					<div style={ { fontSize: 11, color: '#64748b' } }>
						Thêm nhiều phiên bản nội dung. Hệ thống sẽ chọn theo chế độ bên dưới khi broadcast.
					</div>
					{ variants.map( ( v, idx ) => (
						<div key={ idx } style={ { display: 'flex', gap: 6, alignItems: 'flex-start' } }>
							<span style={ { fontSize: 11, color: '#94a3b8', paddingTop: 6, minWidth: 20 } }>{ idx + 1 }.</span>
							<textarea
								className="bzc-input" rows={ 2 } style={ { flex: 1 } }
								placeholder={ `Nội dung biến thể ${idx + 1} — dùng {name}, {phone}, {userId}` }
								value={ v.text || '' }
								onChange={ ( e ) => setVariantText( idx, e.target.value ) }
							/>
							<button type="button" onClick={ () => removeVariant( idx ) }
								style={ { background: 'none', border: 'none', cursor: 'pointer', color: '#f87171', paddingTop: 4 } }
								title="Xóa biến thể này"
							>✕</button>
						</div>
					) ) }
					{ variants.length < 10 && (
						<button type="button" className="bzc-btn-outline" onClick={ addVariant } style={ { fontSize: 12, padding: '3px 10px' } }>
							+ Thêm biến thể
						</button>
					) }
					{ variants.length > 1 && (
						<label style={ { display: 'flex', gap: 8, alignItems: 'center', fontSize: 12 } }>
							<span className="bzc-muted">Chế độ:</span>
							<select className="bzc-input" style={ { width: 'auto' } } value={ variantMode } onChange={ ( e ) => setVariantMode( e.target.value ) }>
								<option value="random">Ngẫu nhiên (Random)</option>
								<option value="all">Xoay vòng (Round-robin)</option>
							</select>
						</label>
					) }
				</div>
			</details>

			{ !! error && (
				<div className="bzc-muted bzc-danger" style={ { fontSize: 12 } }>
					{ error?.data?.error || error?.error || 'Có lỗi xảy ra.' }
				</div>
			) }

			<button className="bzc-btn-primary" type="submit" disabled={ busy }>
				{ editing ? 'Cập nhật campaign' : '+ Tạo campaign' }
			</button>
		</form>
	);
}

/* --------------------------------------------------------------------------
 * Detail (QR + funnel + share)
 * -------------------------------------------------------------------------- */

function CampaignDetail( { campaign } ) {
	const id = campaign.id;
	const { data: stats }  = useGetCampaignStatsQuery( id, { pollingInterval: 15000 } );
	const { data: funnel } = useGetCampaignFunnelQuery( id, { pollingInterval: 15000 } );
	const { data: urlInfo } = useGetCampaignUrlQuery( id );
	/* PHASE 0.35 M6.W16 — dedicated Messenger m.me probe (richer than url endpoint;
	 * exposes ref token + per-page link when admin has multiple pages bound). */
	const [ pageId, setPageId ] = useState( '' );
	const { data: msLink, isFetching: msLinkFetching, error: msLinkErr } = useGetCampaignMessengerLinkQuery(
		{ id, page_id: pageId || undefined },
		{ refetchOnMountOrArgChange: true }
	);

	const qrSvgUrl = REST_BASE + 'campaigns/' + id + '/qr.svg';
	const qrPngUrl = REST_BASE + 'campaigns/' + id + '/qr.png';

	const trackedUrl = urlInfo?.tracked_url || urlInfo?.url || campaign.landing_url || '';
	const messengerUrl = urlInfo?.messenger_url || '';

	const visits        = funnel?.visits         ?? stats?.visits      ?? 0;
	const conversations = funnel?.conversations  ?? 0;
	const resolved      = funnel?.resolved       ?? 0;
	const conversions   = funnel?.conversions    ?? stats?.conversions ?? 0;
	const pointsAwarded = funnel?.points_awarded  ?? 0;
	const rate = visits > 0 ? ( ( conversions / visits ) * 100 ).toFixed( 1 ) : '0.0';

	const copy = ( s ) => {
		if ( ! s ) { return; }
		try { navigator.clipboard.writeText( s ); } catch ( _ ) { /* noop */ }
	};

	return (
		<div style={ { padding: 14, display: 'grid', gap: 14 } }>
			{ /* Funnel cards (M6.W7 — 4 stages + cvr) */ }
			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 8 } }>
				<div style={ { padding: 10, background: '#eff6ff', borderRadius: 6, textAlign: 'center' } }>
					<div style={ { fontSize: 22, fontWeight: 700, color: '#1d4ed8' } }>{ visits }</div>
					<div style={ { fontSize: 11, color: '#475569' } }>Visits</div>
				</div>
				<div style={ { padding: 10, background: '#f1f5f9', borderRadius: 6, textAlign: 'center' } }>
					<div style={ { fontSize: 22, fontWeight: 700, color: '#475569' } }>{ conversations }</div>
					<div style={ { fontSize: 11, color: '#475569' } }>Conversations</div>
				</div>
				<div style={ { padding: 10, background: '#ecfdf5', borderRadius: 6, textAlign: 'center' } }>
					<div style={ { fontSize: 22, fontWeight: 700, color: '#047857' } }>{ resolved }</div>
					<div style={ { fontSize: 11, color: '#475569' } }>Resolved</div>
				</div>
				<div style={ { padding: 10, background: '#fef3c7', borderRadius: 6, textAlign: 'center' } }>
					<div style={ { fontSize: 22, fontWeight: 700, color: '#b45309' } }>{ pointsAwarded }</div>
					<div style={ { fontSize: 11, color: '#475569' } }>Pts awarded</div>
				</div>
			</div>
			<div style={ { display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#64748b', marginTop: -6 } }>
				<span>Conversions: <strong>{ conversions }</strong></span>
				<span>CVR: <strong>{ rate }%</strong></span>
			</div>

			{ /* QR preview */ }
			<div style={ { textAlign: 'center', padding: 12, background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8 } }>
				<img src={ qrSvgUrl } alt={ 'QR ' + campaign.code } style={ { width: 180, height: 180 } } onError={ ( e ) => { e.currentTarget.style.display = 'none'; } } />
				<div style={ { display: 'flex', gap: 6, justifyContent: 'center', marginTop: 8 } }>
					<a className="bzc-btn-ghost" href={ qrSvgUrl } download={ campaign.code + '.svg' }>⬇ SVG</a>
					<a className="bzc-btn-ghost" href={ qrPngUrl } download={ campaign.code + '.png' }>⬇ PNG</a>
				</div>
			</div>

			{ /* PHASE 0.35 M6.W16 — Messenger LinkBox (m.me + ref token + page picker) */ }
			<div style={ { display: 'grid', gap: 6, padding: 10, background: '#f8fafc', borderRadius: 6, border: '1px solid #e2e8f0' } }>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
					<span style={ { fontSize: 12, fontWeight: 600, color: '#1e293b' } }>Messenger m.me link</span>
					{ actionBadge( campaign.scenario_action_type ) }
				</div>
				<input
					className="bzc-input" placeholder="page_id (để trống = page mặc định)"
					value={ pageId } onChange={ ( e ) => setPageId( e.target.value.replace( /[^0-9]/g, '' ) ) }
					style={ { fontSize: 11 } }
				/>
				{ msLinkFetching && <div className="bzc-muted" style={ { fontSize: 11 } }>Đang resolve…</div> }
				{ !! msLinkErr && (
					<div className="bzc-muted bzc-danger" style={ { fontSize: 11 } }>
						{ msLinkErr?.data?.error || 'Không lấy được link.' }
					</div>
				) }
				{ !! msLink && !! ( msLink.url || msLink.messenger_url ) && (
					<>
						<div style={ { display: 'flex', gap: 6 } }>
							<input
								className="bzc-input" readOnly
								value={ msLink.url || msLink.messenger_url || '' }
								onFocus={ ( e ) => e.target.select() }
								style={ { flex: 1, fontSize: 11 } }
							/>
							<button type="button" className="bzc-btn-ghost" onClick={ () => copy( msLink.url || msLink.messenger_url || '' ) }>Copy</button>
						</div>
						{ !! ( msLink.ref || msLink.ref_token ) && (
							<div className="bzc-muted" style={ { fontSize: 10 } }>
								ref token: <code>{ msLink.ref || msLink.ref_token }</code>
								{ !! msLink.page_id && <> · page #{ msLink.page_id }</> }
							</div>
						) }
					</>
				) }
				{ !! msLink && ! ( msLink.url || msLink.messenger_url ) && messengerUrl && (
					<div style={ { display: 'flex', gap: 6 } }>
						<input className="bzc-input" readOnly value={ messengerUrl } onFocus={ ( e ) => e.target.select() } style={ { flex: 1, fontSize: 11 } } />
						<button type="button" className="bzc-btn-ghost" onClick={ () => copy( messengerUrl ) }>Copy</button>
					</div>
				) }
			</div>
			{ !! trackedUrl && (
				<div style={ { display: 'grid', gap: 4 } }>
					<span className="bzc-muted" style={ { fontSize: 11 } }>Tracked landing URL (UTM)</span>
					<div style={ { display: 'flex', gap: 6 } }>
						<input className="bzc-input" readOnly value={ trackedUrl } onFocus={ ( e ) => e.target.select() } style={ { flex: 1, fontSize: 11 } } />
						<button type="button" className="bzc-btn-ghost" onClick={ () => copy( trackedUrl ) }>Copy</button>
					</div>
				</div>
			) }

			{ /* PHASE 0.35 M6.W21 — Marketing Asset Studio (brand kit + per-template preview/download) */ }
			<div style={ { borderTop: '1px solid #e2e8f0', marginTop: 6, paddingTop: 6 } }>
				<details>
					<summary style={ { cursor: 'pointer', padding: '6px 0', fontWeight: 600, fontSize: 13, color: '#0f172a' } }>
						🎨 Marketing Asset Studio
					</summary>
					<AssetStudioPanel campaignId={ id } />
				</details>
			</div>

			{ /* PHASE 0.42 M-PA.W3 — Print Ads composer (voucher / print ad / QR card …) */ }
			<div style={ { borderTop: '1px solid #e2e8f0', marginTop: 6, paddingTop: 6 } }>
				<details>
					<summary style={ { cursor: 'pointer', padding: '6px 0', fontWeight: 600, fontSize: 13, color: '#0f172a' } }>
						🖼️ Tạo ảnh quảng cáo (Voucher / Print Ad / QR Card)
					</summary>
					<PrintAdsPanel campaignId={ id } />
				</details>
			</div>
		</div>
	);
}

/* --------------------------------------------------------------------------
 * Tab root
 * -------------------------------------------------------------------------- */

export default function CampaignsTab() {
	const { data: campaigns = [], isFetching, refetch } = useGetCampaignsQuery();
	const [ del ] = useDeleteCampaignMutation();
	const [ editing, setEditing ] = useState( null ); // null | 'new' | { ...row }
	const [ selectedId, setSelectedId ] = useState( null );
	const [ filterStatus, setFilterStatus ] = useState( '' );
	const [ q, setQ ] = useState( '' );

	const filtered = useMemo( () => {
		let rows = campaigns;
		if ( filterStatus ) { rows = rows.filter( ( c ) => c.status === filterStatus ); }
		if ( q.trim() ) {
			const needle = q.trim().toLowerCase();
			rows = rows.filter( ( c ) =>
				( c.name || '' ).toLowerCase().includes( needle )
				|| ( c.code || '' ).toLowerCase().includes( needle )
			);
		}
		return rows;
	}, [ campaigns, filterStatus, q ] );

	const selected = useMemo(
		() => campaigns.find( ( c ) => c.id === selectedId ) || null,
		[ campaigns, selectedId ]
	);

	const onDelete = ( c ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Xoá campaign "' + c.name + '" (code: ' + c.code + ')?' ) ) { return; }
		del( { id: c.id } );
		if ( selectedId === c.id ) { setSelectedId( null ); }
	};

	const showSheet = editing !== null || selected !== null;
	const sheetTitle = editing === 'new'
		? 'Tạo campaign mới'
		: ( editing && editing !== 'new' )
			? `Sửa · ${ editing.name }`
			: ( selected ? selected.name : '' );

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title flex items-center gap-2">
						<Megaphone size={ 20 } /> Campaigns
					</h2>
					<p className="bzc-tabpane-subtitle">
						Tạo landing/QR/m.me, theo dõi visit → conversation → conversion.
						{ ' — ' }{ isFetching ? 'đang tải…' : `${ filtered.length }/${ campaigns.length } campaign` }
					</p>
				</div>
				<div className="flex items-center gap-2">
					<div className="relative">
						<Search size={ 14 } className="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" />
						<Input
							value={ q }
							onChange={ ( e ) => setQ( e.target.value ) }
							placeholder="Tìm theo tên / code…"
							className="!pl-7 !py-1 !text-xs !w-52"
						/>
					</div>
					<select
						className="bzc-input !w-auto !py-1 !text-xs"
						value={ filterStatus }
						onChange={ ( e ) => setFilterStatus( e.target.value ) }
					>
						<option value="">Tất cả trạng thái</option>
						{ STATUSES.map( ( s ) => <option key={ s.value } value={ s.value }>{ s.label }</option> ) }
					</select>
					<button
						type="button"
						onClick={ () => refetch() }
						disabled={ isFetching }
						className="p-1.5 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
						title="Làm mới"
					>
						<RefreshCw size={ 14 } className={ isFetching ? 'animate-spin' : '' } />
					</button>
					<Button variant="primary" onClick={ () => { setSelectedId( null ); setEditing( 'new' ); } }>
						<Plus size={ 14 } /> Campaign mới
					</Button>
				</div>
			</header>

			{ ! filtered.length && ! isFetching && (
				<div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
					<Megaphone size={ 32 } className="mx-auto text-gray-400" />
					<p className="mt-3 text-sm text-gray-600">
						{ campaigns.length ? 'Không có campaign khớp bộ lọc.' : 'Chưa có campaign nào.' }
					</p>
					{ ! campaigns.length && (
						<Button variant="primary" className="mt-4" onClick={ () => { setSelectedId( null ); setEditing( 'new' ); } }>
							<Plus size={ 14 } /> Tạo campaign đầu tiên
						</Button>
					) }
				</div>
			) }

			{ !! filtered.length && (
				<div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
					<table className="w-full text-sm">
						<thead className="bg-gray-50 text-xs text-gray-600">
							<tr>
								<th className="text-left px-3 py-2 font-medium">Name</th>
								<th className="text-left px-3 py-2 font-medium">Code</th>
								<th className="text-left px-3 py-2 font-medium w-24">Status</th>
								<th className="text-left px-3 py-2 font-medium w-28">Action</th>
								<th className="text-right px-3 py-2 font-medium w-20">Visits</th>
								<th className="text-right px-3 py-2 font-medium w-24">Conv.</th>
								<th className="text-left px-3 py-2 font-medium w-32">Created</th>
								<th className="w-28" />
							</tr>
						</thead>
						<tbody className="divide-y divide-gray-100">
							{ filtered.map( ( c ) => {
								const isSel = selectedId === c.id;
								return (
									<tr
										key={ c.id }
										onClick={ () => { setEditing( null ); setSelectedId( c.id ); } }
										className={ 'cursor-pointer hover:bg-gray-50 ' + ( isSel ? 'bg-indigo-50' : '' ) }
									>
										<td className="px-3 py-2">
											<button
												type="button"
												onClick={ ( e ) => { e.stopPropagation(); setSelectedId( null ); setEditing( c ); } }
												className="text-left text-gray-900 hover:text-indigo-600 font-medium"
											>
												{ c.name }
											</button>
										</td>
										<td className="px-3 py-2 text-xs"><code className="text-gray-600">{ c.code }</code></td>
										<td className="px-3 py-2">{ statusPill( c.status ) }</td>
										<td className="px-3 py-2">{ actionBadge( c.scenario_action_type ) }</td>
										<td className="px-3 py-2 text-right text-xs text-gray-700">{ c.visits_count ?? c.visits ?? 0 }</td>
										<td className="px-3 py-2 text-right text-xs text-gray-700">{ c.conversions_count ?? c.conversions ?? 0 }</td>
										<td className="px-3 py-2 text-xs text-gray-400">
											{ c.created_at ? new Date( c.created_at.replace( ' ', 'T' ) + 'Z' ).toLocaleDateString() : '' }
										</td>
										<td className="px-3 py-2 text-right whitespace-nowrap">
											<button
												type="button"
												onClick={ ( e ) => { e.stopPropagation(); setSelectedId( null ); setEditing( c ); } }
												className="p-1 text-gray-400 hover:text-gray-700"
												title="Sửa"
											><Pencil size={ 14 } /></button>
											<button
												type="button"
												onClick={ ( e ) => { e.stopPropagation(); onDelete( c ); } }
												className="p-1 text-gray-400 hover:text-red-600"
												title="Xoá"
											><Trash2 size={ 14 } /></button>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			<Sheet open={ showSheet } onOpenChange={ ( v ) => { if ( ! v ) { setEditing( null ); setSelectedId( null ); } } }>
				<SheetContent className="!max-w-3xl">
					<SheetHeader>
						<SheetTitle>{ sheetTitle }</SheetTitle>
					</SheetHeader>
					<SheetBody>
						{ editing !== null && (
							<CampaignForm
								key={ editing === 'new' ? 'new' : editing.id }
								initial={ editing === 'new' ? null : editing }
								onDone={ () => { setEditing( null ); refetch(); } }
							/>
						) }
						{ editing === null && selected && (
							<CampaignDetail campaign={ selected } />
						) }
					</SheetBody>
				</SheetContent>
			</Sheet>
		</div>
	);
}
