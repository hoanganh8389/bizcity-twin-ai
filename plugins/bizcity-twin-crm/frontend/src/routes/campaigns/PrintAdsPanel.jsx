/**
 * PHASE 0.42 M-PA.W3 — Print Ads Panel
 *
 * Rendered inside `CampaignDetail` (`CampaignsTab.jsx`) as a collapsible
 * `<details>` block, mirroring the AssetStudio pattern.
 *
 * 3-step composer:
 *   Step 1  Template grid (filter by type)
 *   Step 2  Overrides form (cta_text · discount · custom_detail · model)
 *   Step 3  Loading → result (download · copy URL · regenerate)
 *
 * Bottom: "Ảnh đã tạo" gallery (history) of all generations for this campaign.
 */

import React, { useMemo, useState } from 'react';
import {
	useGetPrintAdsTemplatesQuery,
	useGetPrintAdsGenerationsQuery,
	useGeneratePrintAdMutation,
} from '../../redux/api/crmApi.js';

const TYPES = [
	{ value: '',                 label: 'Tất cả' },
	{ value: 'voucher',          label: '🎟️ Voucher' },
	{ value: 'print_ad',         label: '📰 Print Ad' },
	{ value: 'qr_card',          label: '📱 QR Card' },
	{ value: 'business_card',    label: '💼 Business Card' },
	{ value: 'event_invite',     label: '🎉 Event Invite' },
];

const FALLBACK_MODELS = [ 'flux-pro', 'flux-flex', 'flux-max', 'gemini-image', 'gpt-image', 'seedream' ];

export default function PrintAdsPanel( { campaignId } ) {
	const [ typeFilter, setTypeFilter ] = useState( '' );
	const [ step, setStep ]             = useState( 1 ); // 1 grid | 2 overrides | 3 result
	const [ selectedTpl, setSelected ]  = useState( null );
	const [ overrides, setOverrides ]   = useState( {
		cta_text: '', discount: '', custom_detail: '', model: '',
	} );

	const { data: templates = [], isFetching: tplFetching } =
		useGetPrintAdsTemplatesQuery( { id: campaignId, type: typeFilter || undefined } );

	const { data: generations = [], isFetching: genFetching, refetch: refetchHistory } =
		useGetPrintAdsGenerationsQuery( { id: campaignId, limit: 30 } );

	const [ generate, { isLoading: generating, data: lastResult, error: genErr, reset: resetMutation } ] =
		useGeneratePrintAdMutation();

	const filteredTemplates = useMemo( () => {
		if ( ! typeFilter ) { return templates; }
		return templates.filter( ( t ) => t.template_type === typeFilter );
	}, [ templates, typeFilter ] );

	const pickTemplate = ( tpl ) => {
		setSelected( tpl );
		setOverrides( ( o ) => ( {
			...o,
			model: tpl.recommended_model || '',
		} ) );
		setStep( 2 );
	};

	const submitGenerate = async () => {
		if ( ! selectedTpl ) { return; }
		setStep( 3 );
		try {
			await generate( {
				id: campaignId,
				template_id: selectedTpl.id,
				overrides,
			} ).unwrap();
			refetchHistory();
		} catch ( _ ) { /* surfaced via genErr */ }
	};

	const resetComposer = () => {
		setSelected( null );
		setOverrides( { cta_text: '', discount: '', custom_detail: '', model: '' } );
		setStep( 1 );
		resetMutation && resetMutation();
	};

	const copy = ( s ) => {
		if ( ! s ) { return; }
		try { navigator.clipboard.writeText( s ); } catch ( _ ) {}
	};

	/* ─────────────────────────────────────────────────────────── */

	return (
		<div style={ { display: 'grid', gap: 12, padding: 12, background: '#fff', borderRadius: 6, border: '1px solid #e2e8f0' } }>
			<Header step={ step } onReset={ resetComposer } />

			{ step === 1 && (
				<>
					<TypeChips value={ typeFilter } onChange={ setTypeFilter } />
					{ tplFetching && ! templates.length && <Muted>Đang tải template…</Muted> }
					{ ! tplFetching && ! filteredTemplates.length && (
						<Muted>Không có template — vào <code>CRM → Print Templates</code> để seed.</Muted>
					) }
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))', gap: 10 } }>
						{ filteredTemplates.map( ( t ) => (
							<TemplateCard key={ t.id } tpl={ t } onPick={ pickTemplate } />
						) ) }
					</div>
				</>
			) }

			{ step === 2 && selectedTpl && (
				<OverridesForm
					tpl={ selectedTpl }
					value={ overrides }
					onChange={ setOverrides }
					onBack={ () => setStep( 1 ) }
					onSubmit={ submitGenerate }
				/>
			) }

			{ step === 3 && (
				<ResultPane
					generating={ generating }
					result={ lastResult }
					error={ genErr }
					onCopy={ copy }
					onRegenerate={ submitGenerate }
					onNew={ resetComposer }
				/>
			) }

			{ /* History */ }
			<details style={ { marginTop: 6 } } open>
				<summary style={ { cursor: 'pointer', fontWeight: 600, fontSize: 12, color: '#0f172a' } }>
					🖼️ Ảnh đã tạo ({ generations.length })
				</summary>
				{ genFetching && ! generations.length && <Muted>Đang tải lịch sử…</Muted> }
				<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 8, marginTop: 8 } }>
					{ generations.map( ( g ) => (
						<HistoryCard key={ g.id } gen={ g } onCopy={ copy } />
					) ) }
					{ ! generations.length && ! genFetching && <Muted>Chưa có ảnh nào.</Muted> }
				</div>
			</details>
		</div>
	);
}

/* ─────────────────────────────────────────────────────────────
 * Subcomponents
 * ───────────────────────────────────────────────────────────── */

function Header( { step, onReset } ) {
	const titles = { 1: '1 · Chọn template', 2: '2 · Tuỳ chỉnh nội dung', 3: '3 · Kết quả' };
	return (
		<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
			<strong style={ { fontSize: 13, color: '#0f172a' } }>{ titles[ step ] }</strong>
			{ step !== 1 && (
				<button type="button" className="bzc-btn-ghost" onClick={ onReset } style={ { fontSize: 11 } }>
					↺ Bắt đầu lại
				</button>
			) }
		</div>
	);
}

function TypeChips( { value, onChange } ) {
	return (
		<div style={ { display: 'flex', flexWrap: 'wrap', gap: 6 } }>
			{ TYPES.map( ( t ) => (
				<button
					key={ t.value || 'all' }
					type="button"
					onClick={ () => onChange( t.value ) }
					style={ {
						padding: '4px 10px', fontSize: 12, borderRadius: 999,
						border: '1px solid ' + ( value === t.value ? '#1d4ed8' : '#cbd5e1' ),
						background: value === t.value ? '#1d4ed8' : '#fff',
						color: value === t.value ? '#fff' : '#475569',
						cursor: 'pointer',
					} }
				>{ t.label }</button>
			) ) }
		</div>
	);
}

function TemplateCard( { tpl, onPick } ) {
	const aspectRatio = ( () => {
		switch ( tpl.target_aspect ) {
			case '1:1':  return '1 / 1';
			case '4:5':  return '4 / 5';
			case '9:16': return '9 / 16';
			case '16:9': return '16 / 9';
			default:     return '1 / 1';
		}
	} )();

	return (
		<button
			type="button"
			onClick={ () => onPick( tpl ) }
			style={ {
				display: 'flex', flexDirection: 'column', gap: 6,
				padding: 8, background: '#f8fafc',
				border: '1px solid #e2e8f0', borderRadius: 6,
				cursor: 'pointer', textAlign: 'left',
			} }
		>
			<div style={ {
				width: '100%', aspectRatio, background: '#e2e8f0', borderRadius: 4,
				display: 'flex', alignItems: 'center', justifyContent: 'center',
				color: '#64748b', fontSize: 11, overflow: 'hidden',
				backgroundImage: tpl.ref_image_url ? `url(${ tpl.ref_image_url })` : 'none',
				backgroundSize: 'cover', backgroundPosition: 'center',
			} }>
				{ ! tpl.ref_image_url && <span>{ tpl.template_type }</span> }
			</div>
			<div style={ { fontSize: 12, fontWeight: 600, color: '#0f172a' } }>{ tpl.title }</div>
			<div style={ { fontSize: 10, color: '#64748b' } }>
				{ tpl.recommended_model } · { tpl.target_aspect }
			</div>
		</button>
	);
}

function OverridesForm( { tpl, value, onChange, onBack, onSubmit } ) {
	const set = ( k, v ) => onChange( { ...value, [ k ]: v } );

	// Client-side preview of the template prompt with override values filled in.
	// Server-side vars ({qr_url}, {qr_image_url}, {brand_name}, {campaign_title}…)
	// will be resolved by the backend on generate.
	const promptPreview = useMemo( () => {
		let s = tpl.base_prompt || '';
		if ( value.cta_text )      s = s.replaceAll( '{cta_text}',      value.cta_text );
		if ( value.discount )      s = s.replaceAll( '{discount}',      value.discount );
		if ( value.custom_detail ) s = s.replaceAll( '{custom_detail}', value.custom_detail );
		return s;
	}, [ tpl.base_prompt, value.cta_text, value.discount, value.custom_detail ] );

	const field = ( label, k, placeholder ) => (
		<label style={ { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' } }>
			<span>{ label }</span>
			<input
				className="bzc-input"
				value={ value[ k ] || '' }
				placeholder={ placeholder }
				onChange={ ( e ) => set( k, e.target.value ) }
				style={ { fontSize: 13 } }
			/>
		</label>
	);

	return (
		<div style={ { display: 'grid', gap: 10 } }>
			<div style={ { padding: 10, background: '#f1f5f9', borderRadius: 6, fontSize: 11, color: '#475569' } }>
				<div style={ { fontWeight: 600, color: '#0f172a', marginBottom: 4 } }>{ tpl.title }</div>
				<div style={ { fontFamily: 'monospace', whiteSpace: 'pre-wrap' } }>{ tpl.description || '—' }</div>
			</div>
			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 } }>
				{ field( 'CTA text', 'cta_text', 'VD: Quét mã để nhận ưu đãi' ) }
				{ field( 'Discount', 'discount', 'VD: 30% / Mua 1 tặng 1' ) }
			</div>
			<label style={ { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' } }>
				<span>Chi tiết thêm (tự do)</span>
				<textarea
					className="bzc-input" rows={ 2 }
					value={ value.custom_detail || '' }
					placeholder="VD: Áp dụng đến 31/12 · Chỉ tại Cơ sở 1"
					onChange={ ( e ) => set( 'custom_detail', e.target.value ) }
					style={ { fontSize: 13, fontFamily: 'inherit' } }
				/>
			</label>
			<label style={ { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' } }>
				<span>Model</span>
				<select
					className="bzc-input"
					value={ value.model || tpl.recommended_model || '' }
					onChange={ ( e ) => set( 'model', e.target.value ) }
				>
					{ ! FALLBACK_MODELS.includes( tpl.recommended_model ) && tpl.recommended_model && (
						<option value={ tpl.recommended_model }>{ tpl.recommended_model } (đề xuất)</option>
					) }
					{ FALLBACK_MODELS.map( ( m ) => (
						<option key={ m } value={ m }>
							{ m }{ m === tpl.recommended_model ? ' (đề xuất)' : '' }
						</option>
					) ) }
				</select>
			</label>
<details style={ { fontSize: 11 } }>
			<summary style={ { cursor: 'pointer', color: '#475569', fontWeight: 600, userSelect: 'none' } }>
				{ '\ud83d\udcdd Preview prompt (server s\u1ebd \u0111i\u1ec1n {qr_url}, {qr_image_url}, {brand_name}, {campaign_title}\u2026)' }
			</summary>
			<pre style={ { background: '#f8fafc', padding: 8, borderRadius: 4, whiteSpace: 'pre-wrap', marginTop: 4, maxHeight: 140, overflow: 'auto', fontSize: 11, color: '#334155', border: '1px solid #e2e8f0' } }>
				{ promptPreview }
			</pre>
		</details>
		<div style={ { display: 'flex', gap: 8, justifyContent: 'flex-end' } }>
				<button type="button" className="bzc-btn-ghost" onClick={ onBack }>← Chọn template khác</button>
				<button type="button" className="bzc-btn-primary" onClick={ onSubmit }>
					🪄 Tạo ảnh
				</button>
			</div>
		</div>
	);
}

function ResultPane( { generating, result, error, onCopy, onRegenerate, onNew } ) {
	if ( generating ) {
		return (
			<div style={ { padding: 24, textAlign: 'center', color: '#475569' } }>
				<div style={ { fontSize: 28, marginBottom: 6 } }>⏳</div>
				<div style={ { fontSize: 13 } }>Đang tạo ảnh… (có thể mất 10–30 giây)</div>
			</div>
		);
	}
	if ( error ) {
		const msg = error?.data?.message || error?.error || 'Tạo ảnh thất bại.';
		return (
			<div style={ { padding: 14, background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 6 } }>
				<div style={ { color: '#991b1b', fontWeight: 600, marginBottom: 4 } }>❌ Lỗi</div>
				<div style={ { fontSize: 12, color: '#7f1d1d' } }>{ String( msg ) }</div>
				<div style={ { display: 'flex', gap: 8, marginTop: 10 } }>
					<button type="button" className="bzc-btn-ghost" onClick={ onRegenerate }>↻ Thử lại</button>
					<button type="button" className="bzc-btn-ghost" onClick={ onNew }>← Bắt đầu lại</button>
				</div>
			</div>
		);
	}
	if ( ! result ) { return <Muted>Chưa có kết quả.</Muted>; }

	const url   = result.image_url || result.thumb_url || '';
	const thumb = result.thumb_url || url;

	return (
		<div style={ { display: 'grid', gap: 10 } }>
			<div style={ { padding: 10, background: '#ecfdf5', border: '1px solid #bbf7d0', borderRadius: 6, color: '#166534', fontSize: 13 } }>
				✅ Đã tạo ảnh #{ result.generation_id } — model <code>{ result.model }</code> · { result.size }
			</div>
			{ url && (
				<a href={ url } target="_blank" rel="noopener noreferrer" style={ { display: 'block' } }>
					<img src={ thumb } alt="generated" style={ { maxWidth: '100%', borderRadius: 6, border: '1px solid #e2e8f0' } } />
				</a>
			) }
			<details>
				<summary style={ { cursor: 'pointer', fontSize: 11, color: '#475569' } }>📝 Prompt đã dùng</summary>
				<pre style={ { fontSize: 11, background: '#f8fafc', padding: 8, borderRadius: 4, whiteSpace: 'pre-wrap', maxHeight: 160, overflow: 'auto' } }>
					{ result.merged_prompt }
				</pre>
			</details>
			<div style={ { display: 'flex', gap: 8, flexWrap: 'wrap' } }>
				<a className="bzc-btn-ghost" href={ url } download>⬇ Tải ảnh</a>
				<button type="button" className="bzc-btn-ghost" onClick={ () => onCopy( url ) }>📋 Copy URL</button>
				<button type="button" className="bzc-btn-ghost" onClick={ onRegenerate }>↻ Regenerate</button>
				<button type="button" className="bzc-btn-primary" onClick={ onNew }>+ Tạo cái khác</button>
			</div>
		</div>
	);
}

function HistoryCard( { gen, onCopy } ) {
	const failed  = gen.status === 'failed';
	const pending = gen.status === 'pending';
	const thumb   = gen.thumb_url || gen.image_url || '';

	return (
		<div style={ {
			border: '1px solid #e2e8f0', borderRadius: 6, padding: 6,
			background: failed ? '#fef2f2' : '#fff',
			display: 'flex', flexDirection: 'column', gap: 4,
		} }>
			{ thumb ? (
				<a href={ gen.image_url } target="_blank" rel="noopener noreferrer">
					<img src={ thumb } alt={ '#' + gen.id } style={ { width: '100%', borderRadius: 4 } } />
				</a>
			) : (
				<div style={ { aspectRatio: '1 / 1', background: '#f1f5f9', borderRadius: 4, display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 18 } }>
					{ failed ? '❌' : pending ? '⏳' : '—' }
				</div>
			) }
			<div style={ { fontSize: 10, color: '#64748b', display: 'flex', justifyContent: 'space-between' } }>
				<span>#{ gen.id }</span>
				<span>{ gen.model }</span>
			</div>
			{ gen.image_url && (
				<button type="button" className="bzc-btn-ghost" style={ { fontSize: 10, padding: '2px 4px' } } onClick={ () => onCopy( gen.image_url ) }>
					📋 URL
				</button>
			) }
			{ failed && gen.error && (
				<div style={ { fontSize: 10, color: '#991b1b' } } title={ gen.error }>{ gen.error.slice( 0, 40 ) }…</div>
			) }
		</div>
	);
}

function Muted( { children } ) {
	return <div style={ { fontSize: 12, color: '#64748b', padding: '6px 0' } }>{ children }</div>;
}
