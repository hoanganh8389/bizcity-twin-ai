// [2026-06-13 Johnny Chu] PHASE-0.44 C.1 — Integration Registry UI (Deplao IntegrationPage parity)
import React, { useMemo, useState, useCallback } from 'react';
import { Plug, RefreshCw, Activity, AlertTriangle, CheckCircle2, MinusCircle, X, ChevronLeft } from 'lucide-react';
import {
	useGetInboxesQuery,
	useGetInboxHealthQuery,
	useGetIntegrationsQuery,
	useSaveIntegrationMutation,
	useDeleteIntegrationMutation,
} from '../../redux/api/crmApi.js';

// ─── Integration Catalog (adapted from Deplao IntegrationPage.tsx) ────────────

const TABS = [
	{ key: 'inbox',    label: 'Kênh Inbox',      icon: '📡' },
	{ key: 'pos',      label: 'POS / Bán hàng',  icon: '🛒' },
	{ key: 'payment',  label: 'Thanh toán',       icon: '💳' },
	{ key: 'shipping', label: 'Vận chuyển',       icon: '📦' },
];

const CATALOG = {
	pos: [
		{
			type: 'woo', name: 'WooCommerce', icon: '🛍️', color: 'bg-purple-500', comingSoon: false,
			desc: 'Kết nối trực tiếp WooCommerce trên WordPress. Tra đơn hàng, khách hàng, sản phẩm ngay trong CRM.',
			credentialFields: [],
			note: 'Được quản lý bởi plugin WooCommerce — không cần cấu hình thêm.',
		},
		{
			type: 'kiotviet', name: 'KiotViet', icon: '🛒', color: 'bg-orange-500', comingSoon: true,
			desc: 'Tra cứu đơn hàng, khách hàng ngay trong chat. Tạo đơn hàng từ workflow.',
			credentialFields: [
				{ key: 'clientId',     label: 'Client ID',     placeholder: 'KiotViet client_id' },
				{ key: 'clientSecret', label: 'Client Secret', secret: true },
				{ key: 'retailerName', label: 'Tên gian hàng', placeholder: 'vd: myshop' },
			],
		},
		{
			type: 'haravan', name: 'Haravan', icon: '🏪', color: 'bg-indigo-500', comingSoon: true,
			desc: 'Nền tảng TMĐT Việt Nam. Tra cứu đơn hàng, khách hàng Haravan trong chat.',
			credentialFields: [
				{ key: 'accessToken',    label: 'Access Token', secret: true },
				{ key: 'retailerDomain', label: 'Tên shop (subdomain)', placeholder: 'vd: myshop' },
			],
		},
		{
			type: 'sapo', name: 'Sapo', icon: '🟢', color: 'bg-emerald-500', comingSoon: true,
			desc: 'Quản lý bán hàng đa kênh Sapo. Tra cứu đơn, khách hàng theo SĐT.',
			credentialFields: [
				{ key: 'apiKey',     label: 'API Key' },
				{ key: 'secretKey',  label: 'Secret Key', secret: true },
				{ key: 'storeDomain', label: 'Tên store', placeholder: 'vd: myshop' },
			],
		},
		{
			type: 'nhanh', name: 'Nhanh.vn', icon: '⚡', color: 'bg-yellow-600', comingSoon: true,
			desc: 'Phần mềm bán hàng đa kênh Nhanh.vn. Quản lý đơn hàng, kho, khách hàng.',
			credentialFields: [
				{ key: 'appId',       label: 'App ID' },
				{ key: 'businessId',  label: 'Business ID' },
				{ key: 'accessToken', label: 'Access Token v3', secret: true },
			],
		},
		{
			type: 'pancake', name: 'Pancake POS', icon: '🥞', color: 'bg-amber-500', comingSoon: true,
			desc: 'Pancake POS/OMS. Tra cứu khách hàng, đơn hàng, sản phẩm và tạo đơn ngay trong chat.',
			credentialFields: [
				{ key: 'accessToken', label: 'API Key', secret: true },
				{ key: 'shopId',      label: 'Shop ID' },
			],
		},
	],
	payment: [
		{
			type: 'casso', name: 'Casso', icon: '💳', color: 'bg-green-600', comingSoon: false,
			desc: 'Nhận webhook khi có giao dịch chuyển khoản VietQR. Tự động xác nhận đơn.',
			credentialFields: [
				{ key: 'apiKey',    label: 'API Key', secret: true, placeholder: 'Casso API Key' },
				{ key: 'secretKey', label: 'Secret Key (webhook)', secret: true },
			],
		},
		{
			type: 'sepay', name: 'SePay', icon: '💰', color: 'bg-teal-600', comingSoon: false,
			desc: 'Nhận webhook giao dịch từ SePay. Kích hoạt workflow tự động khi nhận tiền.',
			credentialFields: [
				{ key: 'apiKey',           label: 'API Key', secret: true },
				{ key: 'webhookSecretKey', label: 'Webhook Secret', secret: true },
			],
		},
	],
	shipping: [
		{
			type: 'ghn', name: 'GHN Express', icon: '📦', color: 'bg-red-500', comingSoon: false,
			desc: 'Tracking vận đơn GHN. Khách hỏi trạng thái đơn → tự động reply (read-only).',
			credentialFields: [
				{ key: 'token',  label: 'Token GHN', secret: true },
				{ key: 'shopId', label: 'Shop ID' },
			],
			note: 'Tạo đơn ship tự động → sẽ có sau khi tích hợp với WooCommerce Shipping.',
		},
		{
			type: 'ghtk', name: 'GHTK', icon: '🚚', color: 'bg-blue-500', comingSoon: false,
			desc: 'Tracking vận đơn GHTK. Tự động gửi cập nhật trạng thái đơn cho khách.',
			credentialFields: [
				{ key: 'token', label: 'Token GHTK', secret: true },
			],
		},
	],
};

// ─── HealthBadge (inbox health check) ─────────────────────────────────────────

function HealthBadge( { id } ) {
	const { data, isFetching, refetch } = useGetInboxHealthQuery( id, { pollingInterval: 60_000 } );
	const status = data?.status || 'unknown';
	const meta = {
		green:   { label: 'OK',           cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200', Icon: CheckCircle2 },
		yellow:  { label: 'Chậm',         cls: 'bg-amber-50 text-amber-700 ring-amber-200',       Icon: AlertTriangle },
		red:     { label: 'Mất kết nối',  cls: 'bg-red-50 text-red-700 ring-red-200',             Icon: AlertTriangle },
		unknown: { label: isFetching ? 'Kiểm tra…' : 'Chưa rõ', cls: 'bg-gray-50 text-gray-500 ring-gray-200', Icon: MinusCircle },
	}[ status ] || { label: status, cls: 'bg-gray-50 text-gray-500 ring-gray-200', Icon: MinusCircle };
	const tip = ( data?.last_error ? `Lỗi: ${ data.last_error } · ` : '' )
		+ ( data?.last_inbound_at ? `Inbound gần nhất: ${ data.last_inbound_at } UTC` : 'Chưa có inbound' );
	return (
		<button type="button" onClick={ () => refetch() } title={ tip + ' · click để kiểm tra lại' }
			className={ 'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium ring-1 ' + meta.cls }
		><meta.Icon size={ 10 } /> { meta.label }</button>
	);
}

function typePill( type ) {
	const t = ( type || '' ).toLowerCase();
	const colors = {
		zalo: 'bg-blue-50 text-blue-700 ring-blue-200', facebook: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
		messenger: 'bg-indigo-50 text-indigo-700 ring-indigo-200', telegram: 'bg-sky-50 text-sky-700 ring-sky-200',
		webchat: 'bg-emerald-50 text-emerald-700 ring-emerald-200', email: 'bg-amber-50 text-amber-700 ring-amber-200',
		sms: 'bg-rose-50 text-rose-700 ring-rose-200',
	};
	const cls = colors[ t ] || 'bg-gray-50 text-gray-600 ring-gray-200';
	return <span className={ 'inline-flex px-2 py-0.5 rounded-full text-[10px] font-medium ring-1 ' + cls }>{ type || '—' }</span>;
}

// ─── IntegrationCard ──────────────────────────────────────────────────────────

function IntegrationCard( { catalog, savedStatus, onConfigure, onDisconnect } ) {
	const enabled = savedStatus?.enabled || false;
	const hasCreds = savedStatus?.has_credentials || false;

	return (
		<div className={ 'rounded-lg border p-4 flex gap-3 transition-colors ' + ( enabled ? 'border-emerald-200 bg-emerald-50/40' : 'border-gray-200 bg-white hover:bg-gray-50' ) }>
			<div className={ 'w-10 h-10 rounded-lg flex items-center justify-center text-xl flex-shrink-0 ' + ( catalog.color || 'bg-gray-200' ) }>
				{ catalog.icon }
			</div>
			<div className="flex-1 min-w-0">
				<div className="flex items-center gap-2 flex-wrap">
					<span className="font-semibold text-sm text-gray-900">{ catalog.name }</span>
					{ catalog.comingSoon ? (
						<span className="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium ring-1 ring-amber-200">Sắp có</span>
					) : enabled ? (
						<span className="text-[10px] bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full font-medium ring-1 ring-emerald-200 flex items-center gap-1">
							<CheckCircle2 size={ 9 } /> Đã kết nối
						</span>
					) : (
						<span className="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full font-medium ring-1 ring-gray-200">Chưa kết nối</span>
					) }
				</div>
				<p className="text-xs text-gray-500 mt-0.5 leading-relaxed">{ catalog.desc }</p>
				{ catalog.note && <p className="text-[10px] text-indigo-500 mt-1">ℹ️ { catalog.note }</p> }
			</div>
			<div className="flex flex-col gap-1.5 flex-shrink-0 items-end justify-center">
				{ ! catalog.comingSoon && ! ( catalog.type === 'woo' ) && (
					<>
						<button type="button"
							className="px-3 py-1 rounded text-xs font-medium bg-indigo-600 text-white hover:bg-indigo-700 transition-colors"
							onClick={ () => onConfigure( catalog ) }
						>{ hasCreds ? 'Cấu hình lại' : 'Kết nối' }</button>
						{ enabled && (
							<button type="button"
								className="px-3 py-1 rounded text-xs font-medium border border-red-200 text-red-500 hover:bg-red-50"
								onClick={ () => onDisconnect( catalog.type ) }
							>Ngắt kết nối</button>
						) }
					</>
				) }
				{ catalog.type === 'woo' && enabled && (
					<a href="/wp-admin/admin.php?page=wc-settings" target="_blank" rel="noreferrer"
						className="px-3 py-1 rounded text-xs font-medium border border-purple-200 text-purple-600 hover:bg-purple-50">
						Cài đặt Woo →
					</a>
				) }
			</div>
		</div>
	);
}

// ─── ConfigureDrawer ──────────────────────────────────────────────────────────

function ConfigureDrawer( { catalog, onClose, onSaved } ) {
	const [ saveIntegration, { isLoading } ] = useSaveIntegrationMutation();
	const [ formValues, setFormValues ] = useState( {} );
	const [ error, setError ] = useState( '' );

	const handleSubmit = useCallback( async ( e ) => {
		e.preventDefault();
		setError( '' );
		// Require all non-secret fields have value
		for ( const field of catalog.credentialFields ) {
			if ( ! field.secret && ! formValues[ field.key ]?.trim() ) {
				setError( `Vui lòng nhập "${ field.label }"` ); return;
			}
		}
		try {
			await saveIntegration( { type: catalog.type, label: catalog.name, credentials: formValues } ).unwrap();
			onSaved();
			onClose();
		} catch ( err ) {
			setError( err?.data?.message || 'Lưu thất bại. Xem console.' );
		}
	}, [ catalog, formValues, saveIntegration, onSaved, onClose ] );

	return (
		<div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center" onClick={ ( e ) => { if ( e.target === e.currentTarget ) { onClose(); } } }>
			<div className="bg-white rounded-xl shadow-2xl w-full max-w-md p-6">
				<div className="flex items-center justify-between mb-4">
					<div className="flex items-center gap-2">
						<span className="text-2xl">{ catalog.icon }</span>
						<span className="font-bold text-base">Kết nối { catalog.name }</span>
					</div>
					<button type="button" onClick={ onClose } className="text-gray-400 hover:text-gray-600"><X size={ 18 } /></button>
				</div>
				<p className="text-xs text-gray-500 mb-4">{ catalog.desc }</p>
				<form onSubmit={ handleSubmit } className="flex flex-col gap-3">
					{ catalog.credentialFields.map( ( field ) => (
						<label key={ field.key } className="flex flex-col gap-1">
							<span className="text-xs font-medium text-gray-700">{ field.label }{ ! field.secret ? ' *' : '' }</span>
							<input
								type={ field.secret ? 'password' : 'text' }
								className="border border-gray-200 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
								placeholder={ field.placeholder || '' }
								value={ formValues[ field.key ] || '' }
								onChange={ ( e ) => setFormValues( ( prev ) => ( { ...prev, [ field.key ]: e.target.value } ) ) }
							/>
						</label>
					) ) }
					{ error && <p className="text-xs text-red-500">{ error }</p> }
					<div className="flex justify-end gap-2 pt-2">
						<button type="button" onClick={ onClose } className="px-4 py-1.5 rounded border border-gray-200 text-sm text-gray-600 hover:bg-gray-50">Huỷ</button>
						<button type="submit" disabled={ isLoading } className="px-4 py-1.5 rounded bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50">
							{ isLoading ? 'Đang lưu…' : 'Lưu kết nối' }
						</button>
					</div>
				</form>
			</div>
		</div>
	);
}

// ─── IntegrationsPanel ─────────────────────────────────────────────────────────

function IntegrationsPanel( { initialTab } ) {
	const [ intTab, setIntTab ] = useState( initialTab || 'pos' );
	const [ configuring, setConfiguring ] = useState( null );
	const { data: integrations = [], refetch } = useGetIntegrationsQuery();
	const [ deleteIntegration ] = useDeleteIntegrationMutation();

	const statusMap = useMemo( () => {
		const m = {};
		integrations.forEach( ( i ) => { m[ i.type ] = i; } );
		return m;
	}, [ integrations ] );

	const handleDisconnect = async ( type ) => {
		if ( ! window.confirm( `Ngắt kết nối ${ type }?` ) ) { return; }
		await deleteIntegration( type );
	};

	const intTabDef = TABS.filter( ( t ) => t.key !== 'inbox' );
	const catalogItems = CATALOG[ intTab ] || [];

	return (
		<div>
			{ /* sub-tabs */ }
			<div className="flex gap-1 mb-4 border-b border-gray-200 pb-1">
				{ intTabDef.map( ( t ) => (
					<button key={ t.key } type="button"
						onClick={ () => setIntTab( t.key ) }
						className={ 'flex items-center gap-1.5 px-3 py-1.5 rounded-t text-xs font-medium transition-colors border-b-2 -mb-px ' + ( intTab === t.key ? 'border-indigo-600 text-indigo-700 bg-indigo-50/60' : 'border-transparent text-gray-500 hover:text-gray-700' ) }
					>{ t.icon } { t.label }</button>
				) ) }
			</div>
			<div className="flex flex-col gap-3">
				{ catalogItems.map( ( cat ) => (
					<IntegrationCard
						key={ cat.type }
						catalog={ cat }
						savedStatus={ statusMap[ cat.type ] }
						onConfigure={ setConfiguring }
						onDisconnect={ handleDisconnect }
					/>
				) ) }
			</div>
			{ configuring && (
				<ConfigureDrawer
					catalog={ configuring }
					onClose={ () => setConfiguring( null ) }
					onSaved={ () => refetch() }
				/>
			) }
		</div>
	);
}

// ─── Main ChannelsTab ─────────────────────────────────────────────────────────

export default function ChannelsTab() {
	const [ activeTab, setActiveTab ] = useState( 'inbox' );
	const { data: inboxes = [], isFetching, refetch } = useGetInboxesQuery();
	const adminUrl = ( window.BIZCITY_CRM_BOOT && window.BIZCITY_CRM_BOOT.adminUrl )
		|| '/wp-admin/admin.php?page=bizchat-gateway';

	return (
		<div className="bzc-tabpane">
			<header className="bzc-tabpane-header">
				<div>
					<h2 className="bzc-tabpane-title flex items-center gap-2">
						<Plug size={ 20 } /> Channels &amp; Tích hợp
					</h2>
					<p className="bzc-tabpane-subtitle">
						Kênh inbox · Tích hợp POS · Thanh toán · Vận chuyển
					</p>
				</div>
				<div className="flex items-center gap-2">
					{ activeTab === 'inbox' && (
						<button type="button" onClick={ () => refetch() } disabled={ isFetching }
							className="p-1.5 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-50" title="Làm mới">
							<RefreshCw size={ 14 } className={ isFetching ? 'animate-spin' : '' } />
						</button>
					) }
					<a href={ adminUrl } target="_blank" rel="noreferrer"
						className="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700">
						<Activity size={ 12 } /> Gateway →
					</a>
				</div>
			</header>

			{ /* Tab bar */ }
			<div className="flex gap-1 mb-5 border-b border-gray-200 pb-1">
				{ TABS.map( ( t ) => (
					<button key={ t.key } type="button"
						onClick={ () => setActiveTab( t.key ) }
						className={ 'flex items-center gap-1.5 px-4 py-2 rounded-t text-sm font-medium transition-colors border-b-2 -mb-px ' + ( activeTab === t.key ? 'border-indigo-600 text-indigo-700 bg-indigo-50/60' : 'border-transparent text-gray-500 hover:text-gray-700' ) }
					>{ t.icon } { t.label }</button>
				) ) }
			</div>

			{ /* Inbox tab */ }
			{ activeTab === 'inbox' && (
				<>
					{ ! inboxes.length && ! isFetching && (
						<div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
							<Plug size={ 32 } className="mx-auto text-gray-400" />
							<p className="mt-3 text-sm text-gray-600">Chưa có inbox nào được kết nối.</p>
							<p className="text-xs text-gray-400 mb-4">Vào <strong>BizChat Gateway</strong> → tab Channels để cấu hình page / token / OA.</p>
							<a href={ adminUrl } target="_blank" rel="noreferrer"
								className="inline-flex items-center gap-1 px-3 py-1.5 rounded bg-indigo-600 text-white text-xs font-medium hover:bg-indigo-700">
								<Activity size={ 12 } /> Mở BizChat Gateway →
							</a>
						</div>
					) }
					{ !! inboxes.length && (
						<div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
							<table className="w-full text-sm">
								<thead className="bg-gray-50 text-xs text-gray-600">
									<tr>
										<th className="text-left px-3 py-2 font-medium">Tên inbox</th>
										<th className="text-left px-3 py-2 font-medium w-32">Loại</th>
										<th className="text-left px-3 py-2 font-medium">Page / Token</th>
										<th className="text-right px-3 py-2 font-medium w-24">ID</th>
										<th className="text-left px-3 py-2 font-medium w-36">Health</th>
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100">
									{ inboxes.map( ( ix ) => (
										<tr key={ ix.id } className="hover:bg-gray-50">
											<td className="px-3 py-2 font-medium text-gray-900">{ ix.name }</td>
											<td className="px-3 py-2">{ typePill( ix.channel_type || ix.type ) }</td>
											<td className="px-3 py-2 text-xs"><code className="text-gray-600">{ ix.page_id || ix.identifier || '—' }</code></td>
											<td className="px-3 py-2 text-right text-xs text-gray-400">#{ ix.id }</td>
											<td className="px-3 py-2"><HealthBadge id={ ix.id } /></td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
					<p className="text-xs text-gray-400 mt-3">Health poll mỗi 60s · { isFetching ? 'Đang tải…' : `${ inboxes.length } inbox` }</p>
				</>
			) }

			{ /* POS / Payment / Shipping tabs */ }
			{ activeTab !== 'inbox' && <IntegrationsPanel key={ activeTab } initialTab={ activeTab } /> }
		</div>
	);
}
