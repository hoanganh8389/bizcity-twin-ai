/**
 * PHASE 0.35 M6.W21 — Asset Studio Panel
 *
 * Renders the Marketing Asset Studio surface inside `CampaignDetail`:
 *   - Brand Kit editor (logo URL, colors, hotline) — POSTs PUT /marketing/brand-kit
 *   - Per-template preview cards (SVG embed via REST URL with cache-busting hash)
 *   - Format chips (SVG / PNG / JPG / PDF) + Download / Regenerate buttons
 *   - Imagick / GD availability badges from manifest
 *
 * The actual asset render is served by GET /campaigns/{id}/assets/{key}.{ext}
 * which streams the binary directly. We embed via <img src> for raster formats
 * and <object data> for SVG.
 */

import React, { useMemo, useState } from 'react';
import {
	useGetBrandKitQuery,
	useUpdateBrandKitMutation,
	useGetCampaignAssetManifestQuery,
	useRegenerateCampaignAssetMutation,
} from '../../redux/api/crmApi.js';

const REST_BASE = ( typeof window !== 'undefined' && window.BIZCITY_CRM_BOOT && window.BIZCITY_CRM_BOOT.restUrl ) || '/wp-json/bizcity-crm/v1/';

function BrandKitEditor() {
	const { data, isFetching } = useGetBrandKitQuery();
	const [ updateKit, { isLoading: saving } ] = useUpdateBrandKitMutation();
	const [ form, setForm ] = useState( null );

	const kit = form || data?.kit || {};
	const set = ( k, v ) => setForm( { ...kit, [ k ]: v } );

	const submit = async ( e ) => {
		e.preventDefault();
		await updateKit( kit ).unwrap().catch( () => {} );
		setForm( null ); // re-pull from server
	};

	if ( isFetching && ! data ) {
		return <div style={ { padding: 12, color: '#64748b' } }>Đang tải brand kit…</div>;
	}

	const field = ( label, k, type = 'text' ) => (
		<label style={ { display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: '#475569' } }>
			<span>{ label }</span>
			<input
				type={ type }
				value={ kit[ k ] || '' }
				onChange={ ( e ) => set( k, e.target.value ) }
				style={ { padding: '6px 8px', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 13 } }
			/>
		</label>
	);

	return (
		<form onSubmit={ submit } style={ { display: 'grid', gap: 8, padding: 12, background: '#f8fafc', borderRadius: 6, border: '1px solid #e2e8f0' } }>
			<div style={ { fontWeight: 600, fontSize: 13, color: '#0f172a' } }>Brand Kit (áp dụng cho tất cả asset)</div>
			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8 } }>
				{ field( 'Tên thương hiệu', 'brand_name' ) }
				{ field( 'Hotline', 'hotline' ) }
				{ field( 'Logo URL', 'logo_url', 'url' ) }
				{ field( 'Font family', 'font_family' ) }
				{ field( 'Màu chính (#hex)', 'primary_color' ) }
				{ field( 'Màu phụ (#hex)', 'secondary_color' ) }
			</div>
			<div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
				<button type="submit" disabled={ saving || ! form } style={ { padding: '6px 14px', background: '#1d4ed8', color: '#fff', border: 'none', borderRadius: 4, fontSize: 13, opacity: ( saving || ! form ) ? 0.6 : 1 } }>
					{ saving ? 'Đang lưu…' : 'Lưu brand kit' }
				</button>
				{ form && (
					<button type="button" onClick={ () => setForm( null ) } style={ { padding: '6px 14px', background: '#fff', color: '#475569', border: '1px solid #cbd5e1', borderRadius: 4, fontSize: 13 } }>Hủy</button>
				) }
				{ data?.hash && (
					<span style={ { marginLeft: 'auto', fontFamily: 'monospace', fontSize: 11, color: '#64748b' } }>hash: { data.hash.slice( 0, 8 ) }</span>
				) }
			</div>
		</form>
	);
}

function AssetPreviewCard( { campaignId, tpl, brandHash, onRegenerate } ) {
	const [ format, setFormat ] = useState( 'svg' );
	const [ stamp, setStamp ]  = useState( 0 ); // bust browser cache after regenerate

	const baseUrl = `${ REST_BASE }campaigns/${ campaignId }/assets/${ tpl.key }.${ format }`;
	const url = `${ baseUrl }?h=${ encodeURIComponent( brandHash || '' ) }&s=${ stamp }`;

	const FORMATS = [ 'svg', 'png', 'jpg', 'pdf' ];

	return (
		<div style={ { border: '1px solid #e2e8f0', borderRadius: 8, overflow: 'hidden', background: '#fff', display: 'flex', flexDirection: 'column' } }>
			<div style={ { padding: '8px 12px', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8 } }>
				<div>
					<div style={ { fontWeight: 600, fontSize: 13, color: '#0f172a' } }>{ tpl.label }</div>
					<div style={ { fontSize: 11, color: '#64748b' } }>{ tpl.width }×{ tpl.height } · { tpl.paper }</div>
				</div>
				<div style={ { display: 'flex', gap: 4 } }>
					{ FORMATS.map( ( f ) => (
						<button
							key={ f }
							type="button"
							onClick={ () => setFormat( f ) }
							style={ {
								padding: '3px 8px',
								fontSize: 11,
								textTransform: 'uppercase',
								background: format === f ? '#1d4ed8' : '#f1f5f9',
								color:      format === f ? '#fff' : '#475569',
								border: 'none',
								borderRadius: 3,
								cursor: 'pointer',
							} }
						>{ f }</button>
					) ) }
				</div>
			</div>

			<div style={ { padding: 12, background: '#f8fafc', minHeight: 180, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
				{ format === 'pdf' ? (
					<a href={ url } target="_blank" rel="noopener noreferrer" style={ { fontSize: 13, color: '#1d4ed8', textDecoration: 'underline' } }>
						Mở PDF trong tab mới
					</a>
				) : format === 'svg' ? (
					<object data={ url } type="image/svg+xml" style={ { width: '100%', maxHeight: 240 } }>SVG preview</object>
				) : (
					<img src={ url } alt={ tpl.label } style={ { maxWidth: '100%', maxHeight: 240, objectFit: 'contain' } } />
				) }
			</div>

			<div style={ { padding: '6px 12px', borderTop: '1px solid #e2e8f0', display: 'flex', gap: 6, justifyContent: 'flex-end' } }>
				<a href={ url } download={ `campaign-${ campaignId }-${ tpl.key }.${ format }` } style={ { padding: '4px 10px', background: '#f1f5f9', color: '#1e293b', borderRadius: 3, fontSize: 12, textDecoration: 'none' } }>
					Tải xuống
				</a>
				<button
					type="button"
					onClick={ async () => { await onRegenerate( tpl.key ); setStamp( Date.now() ); } }
					style={ { padding: '4px 10px', background: '#fff', color: '#475569', border: '1px solid #cbd5e1', borderRadius: 3, fontSize: 12 } }
				>Regenerate</button>
			</div>
		</div>
	);
}

export default function AssetStudioPanel( { campaignId } ) {
	const { data: manifest, isFetching, refetch } = useGetCampaignAssetManifestQuery( campaignId, { skip: ! campaignId } );
	const [ regenerate ] = useRegenerateCampaignAssetMutation();

	const onRegenerate = async ( key ) => {
		await regenerate( { id: campaignId, key } ).unwrap().catch( () => {} );
		refetch();
	};

	const templates = manifest?.templates || [];
	const brandHash = manifest?.brand_hash || '';

	const engineBadge = ( label, on ) => (
		<span style={ {
			padding: '2px 8px',
			fontSize: 11,
			borderRadius: 999,
			background: on ? '#dcfce7' : '#fee2e2',
			color:      on ? '#166534' : '#991b1b',
		} }>{ label }: { on ? 'sẵn sàng' : 'thiếu' }</span>
	);

	return (
		<div style={ { display: 'grid', gap: 14, padding: 14 } }>
			<BrandKitEditor />

			<div style={ { display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' } }>
				<div style={ { fontWeight: 600, fontSize: 13, color: '#0f172a' } }>Marketing Assets</div>
				{ manifest && engineBadge( 'Imagick (PNG/JPG/PDF)', !! manifest.imagick ) }
				{ manifest && engineBadge( 'GD (PNG fallback)', !! manifest.gd ) }
				{ manifest && ! manifest.imagick && (
					<span style={ { fontSize: 11, color: '#92400e', background: '#fef3c7', padding: '2px 8px', borderRadius: 4 } }>
						⚠ Không có Imagick → PNG/JPG/PDF sẽ fallback về SVG.
					</span>
				) }
			</div>

			{ isFetching && <div style={ { color: '#64748b', fontSize: 13 } }>Đang tải manifest…</div> }

			<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))', gap: 12 } }>
				{ templates.map( ( tpl ) => (
					<AssetPreviewCard
						key={ tpl.key }
						campaignId={ campaignId }
						tpl={ tpl }
						brandHash={ brandHash }
						onRegenerate={ onRegenerate }
					/>
				) ) }
			</div>

			{ ! isFetching && templates.length === 0 && (
				<div style={ { padding: 14, color: '#64748b', fontSize: 13, textAlign: 'center' } }>Chưa có template nào.</div>
			) }
		</div>
	);
}
