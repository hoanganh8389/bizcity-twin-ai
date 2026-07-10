import React, { useState, useMemo, useEffect } from 'react';
import {
	useGetOrderBanksQuery,
	useLazySearchOrderProductsQuery,
	useGetConversationOrdersQuery,
	useCreateConversationOrderMutation,
	useGetSavedBanksQuery,
	useAddSavedBankMutation,
	useDeleteSavedBankMutation,
	useLazyGetSingleOrderQuery,
	useSendOrderToCustomerMutation,
} from '../redux/api/crmApi.js';

/**
 * Order placement tab (PHASE-0.36-ORDER-PLACEMENT §FE).
 *
 * Renders inside ContactDrawer as a sibling of the Contact tab. Lets the
 * CRM agent quickly: pick products (or enter a custom amount) → choose a
 * bank → click "Tạo đơn". Result panel shows order #, checkout URL, view
 * URL and the VietQR image so the agent can copy or share into the chat.
 */
export default function OrderTab( { convId, contact } ) {
	const { data: banks, refetch: refetchBanks } = useGetOrderBanksQuery( undefined, { skip: ! convId } );
	const { data: pastOrders = [], refetch: refetchPast } = useGetConversationOrdersQuery( convId, { skip: ! convId } );
	const [ triggerSearch, { data: products = [], isFetching: searching } ] = useLazySearchOrderProductsQuery();
	const [ createOrder, createState ] = useCreateConversationOrderMutation();
	const { data: savedBanks = [], refetch: refetchSaved } = useGetSavedBanksQuery();
	const [ addSavedBank, addBankState ] = useAddSavedBankMutation();
	const [ deleteSavedBank ] = useDeleteSavedBankMutation();
	const [ triggerOrderPreview ] = useLazyGetSingleOrderQuery();
	const [ sendOrderToCustomer, sendOrderState ] = useSendOrderToCustomerMutation();

	const [ q, setQ ]                   = useState( '' );
	const [ cart, setCart ]             = useState( [] ); // [{product_id,name,qty,price}]
	const [ customAmount, setCustom ]   = useState( '' );
	const [ paymentOption, setPayOpt ]  = useState( '' );
	const [ note, setNote ]             = useState( '' );
	const [ result, setResult ]         = useState( null );
	const [ error, setError ]           = useState( null );
	const [ showBankForm, setShowBankForm ] = useState( false );
	const [ bankForm, setBankForm ]     = useState( { bank_id: '', bank_label: '', bin: '', account_no: '', account_name: '' } );
	const [ previewMap, setPreviewMap ] = useState( {} ); // { orderId: orderDetail|'loading' }
	const [ sendStatus, setSendStatus ] = useState( {} ); // { 'orderId:mode': 'sending'|'sent'|'error:xxx' }

	const adapter   = banks?.adapter;
	const bankOpts  = banks?.options || [];
	const bankSource = bankOpts[0]?.source || '';

	// Auto-pick first bank when list arrives.
	useEffect( () => {
		if ( ! paymentOption && bankOpts.length > 0 ) {
			setPayOpt( bankOpts[ 0 ].value );
		}
	}, [ bankOpts, paymentOption ] );

	const total = useMemo( () => {
		if ( cart.length === 0 ) { return parseFloat( customAmount ) || 0; }
		return cart.reduce( ( s, it ) => s + ( it.qty * it.price ), 0 );
	}, [ cart, customAmount ] );

	const onSearch = ( e ) => {
		e?.preventDefault?.();
		triggerSearch( { q, limit: 15 } );
	};

	const addToCart = ( p ) => {
		setCart( ( c ) => {
			const idx = c.findIndex( ( x ) => x.product_id === p.id );
			if ( idx >= 0 ) {
				const next = c.slice(); next[ idx ] = { ...next[ idx ], qty: next[ idx ].qty + 1 };
				return next;
			}
			return [ ...c, { product_id: p.id, name: p.title, qty: 1, price: p.price } ];
		} );
	};
	const updateQty   = ( pid, qty ) => setCart( ( c ) => c.map( ( x ) => x.product_id === pid ? { ...x, qty: Math.max( 1, qty | 0 ) } : x ) );
	const updatePrice = ( pid, price ) => setCart( ( c ) => c.map( ( x ) => x.product_id === pid ? { ...x, price: parseFloat( price ) || 0 } : x ) );
	const removeRow   = ( pid ) => setCart( ( c ) => c.filter( ( x ) => x.product_id !== pid ) );

	const submit = async () => {
		setError( null ); setResult( null );
		try {
			const payload = {
				convId,
				items: cart.length > 0 ? cart : [],
				custom_amount: cart.length === 0 ? ( parseFloat( customAmount ) || 0 ) : 0,
				payment_option: paymentOption,
				note,
			};
			const res = await createOrder( payload ).unwrap();
			setResult( res );
			setCart( [] ); setCustom( '' ); setNote( '' );
			refetchPast();
		} catch ( e ) {
			setError( e?.data?.error || e?.error || 'unknown_error' );
		}
	};

	const togglePreview = async ( orderId ) => {
		if ( previewMap[ orderId ] && previewMap[ orderId ] !== 'loading' ) {
			setPreviewMap( ( m ) => { const n = { ...m }; delete n[ orderId ]; return n; } );
			return;
		}
		setPreviewMap( ( m ) => ( { ...m, [ orderId ]: 'loading' } ) );
		try {
			const res = await triggerOrderPreview( orderId ).unwrap();
			setPreviewMap( ( m ) => ( { ...m, [ orderId ]: res } ) );
		} catch ( e ) {
			setPreviewMap( ( m ) => ( { ...m, [ orderId ]: { error: e?.data?.error || 'preview_failed' } } ) );
		}
	};

	const sendToCustomer = async ( orderId, mode ) => {
		const key = `${ orderId }:${ mode }`;
		setSendStatus( ( s ) => ( { ...s, [ key ]: 'sending' } ) );
		try {
			const res = await sendOrderToCustomer( { convId, order_id: orderId, mode } ).unwrap();
			setSendStatus( ( s ) => ( { ...s, [ key ]: res?.sent ? 'sent' : 'error:dispatch_failed' } ) );
		} catch ( e ) {
			setSendStatus( ( s ) => ( { ...s, [ key ]: 'error:' + ( e?.data?.error || 'send_failed' ) } ) );
		}
	};

	const submitBankForm = async () => {
		try {
			await addSavedBank( bankForm ).unwrap();
			setBankForm( { bank_id: '', bank_label: '', bin: '', account_no: '', account_name: '' } );
			setShowBankForm( false );
			refetchSaved(); refetchBanks();
		} catch ( e ) {
			setError( 'add_bank_failed:' + ( e?.data?.error || e?.error || 'unknown' ) );
		}
	};

	const removeBank = async ( idx ) => {
		if ( ! window.confirm( 'Xoá tài khoản ngân hàng này?' ) ) { return; }
		try {
			await deleteSavedBank( { idx } ).unwrap();
			refetchSaved(); refetchBanks();
		} catch ( e ) {
			setError( 'delete_bank_failed:' + ( e?.data?.error || 'unknown' ) );
		}
	};

	if ( ! convId ) {
		return <div className="p-4 text-xs text-slate-400 italic">Chọn 1 hội thoại để đặt đơn.</div>;
	}

	return (
		<div className="flex-1 overflow-y-auto p-3 text-xs space-y-4">
			{ adapter ? (
				<div className="text-[10px] text-slate-500 flex items-center gap-1">
					<span>Adapter:</span>
					<code className="bg-slate-100 px-1">{ adapter.slug }</code>
					<span>· { adapter.label }</span>
					{ bankSource === 'bacs' ? (
						<span className="ml-auto px-1.5 py-[1px] bg-amber-100 text-amber-700 rounded text-[9px] uppercase">BACS · không có QR</span>
					) : bankSource === 'manual' ? (
						<span className="ml-auto px-1.5 py-[1px] bg-rose-100 text-rose-700 rounded text-[9px] uppercase">Chưa cấu hình bank</span>
					) : bankSource === 'ttck' ? (
						<span className="ml-auto px-1.5 py-[1px] bg-emerald-100 text-emerald-700 rounded text-[9px] uppercase">TTCK · QR auto</span>
					) : null }
				</div>
			) : (
				<div className="px-3 py-2 bg-rose-50 border border-rose-200 text-rose-700 rounded">
					⚠ Không có order adapter khả dụng. Cần kích hoạt WooCommerce.
				</div>
			) }

			{ /* Past orders */ }
			{ pastOrders.length > 0 ? (
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Đơn gần đây ({ pastOrders.length })</h4>
					<ul className="space-y-1">
						{ pastOrders.slice( 0, 8 ).map( ( o ) => {
							const expanded = previewMap[ o.id ];
							const sLink    = sendStatus[ `${ o.id }:link` ];
							const sQR      = sendStatus[ `${ o.id }:qr` ];
							const sRecap   = sendStatus[ `${ o.id }:recap` ];
							return (
								<li key={ o.id } className="border border-slate-100 bg-slate-50/50 rounded">
									<div className="flex items-center gap-2 px-2 py-1.5">
										<span className="font-mono text-[10px]">#{ o.id }</span>
										<span className={ 'px-1.5 py-[1px] text-[9px] uppercase font-semibold rounded ' + ( o.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : o.status === 'pending' || o.status === 'on-hold' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600' ) }>{ o.status }</span>
										<span className="flex-1 truncate text-[11px]">{ o.gateway || '—' }</span>
										<span className="font-semibold text-[11px]">{ Number( o.total ).toLocaleString( 'vi-VN' ) } { o.currency }</span>
									</div>
									<div className="flex flex-wrap gap-1 px-2 pb-1.5 border-t border-slate-100/60 pt-1">
										<button type="button" onClick={ () => togglePreview( o.id ) } className="px-1.5 py-0.5 text-[10px] bg-white border border-slate-200 rounded hover:bg-slate-50">{ expanded ? '▲ Đóng' : '👁 Preview' }</button>
										<button type="button" disabled={ sRecap === 'sending' } onClick={ () => sendToCustomer( o.id, 'recap' ) } className="px-1.5 py-0.5 text-[10px] bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:bg-slate-300">{ sRecap === 'sending' ? '⏳' : sRecap === 'sent' ? '✓ Recap' : '📤 Gửi recap' }</button>
										<button type="button" disabled={ sLink === 'sending' } onClick={ () => sendToCustomer( o.id, 'link' ) } className="px-1.5 py-0.5 text-[10px] bg-white border border-indigo-300 text-indigo-700 rounded hover:bg-indigo-50 disabled:opacity-50">{ sLink === 'sending' ? '⏳' : sLink === 'sent' ? '✓ Link' : '🔗 Gửi link' }</button>
										<button type="button" disabled={ sQR === 'sending' } onClick={ () => sendToCustomer( o.id, 'qr' ) } className="px-1.5 py-0.5 text-[10px] bg-white border border-emerald-300 text-emerald-700 rounded hover:bg-emerald-50 disabled:opacity-50">{ sQR === 'sending' ? '⏳' : sQR === 'sent' ? '✓ QR' : '🏦 Gửi QR' }</button>
										<a href={ o.admin_url } target="_blank" rel="noreferrer" className="px-1.5 py-0.5 text-[10px] bg-white border border-slate-200 rounded hover:bg-slate-50 ml-auto">⚙ Admin</a>
									</div>
									{ ( sLink && sLink.startsWith?.( 'error' ) ) || ( sQR && sQR.startsWith?.( 'error' ) ) || ( sRecap && sRecap.startsWith?.( 'error' ) ) ? (
										<div className="px-2 pb-1 text-[10px] text-rose-600">{ sLink?.startsWith?.( 'error' ) ? sLink : '' } { sQR?.startsWith?.( 'error' ) ? sQR : '' } { sRecap?.startsWith?.( 'error' ) ? sRecap : '' }</div>
									) : null }
									{ expanded === 'loading' ? (
										<div className="px-3 py-2 text-[10px] text-slate-400 italic border-t border-slate-100">Đang tải chi tiết…</div>
									) : expanded && expanded.error ? (
										<div className="px-3 py-2 text-[10px] text-rose-600 border-t border-slate-100">{ expanded.error }</div>
									) : expanded ? (
										<div className="px-3 py-2 text-[11px] border-t border-slate-100 space-y-1.5 bg-white">
											{ expanded.items?.length > 0 ? (
												<ul className="space-y-0.5">
													{ expanded.items.map( ( it, i ) => (
														<li key={ i } className="flex justify-between gap-2"><span className="truncate">{ it.name } × { it.qty }</span><span className="font-semibold">{ Number( it.total ).toLocaleString( 'vi-VN' ) } đ</span></li>
													) ) }
												</ul>
											) : null }
											{ expanded.payment ? (
												<div className="bg-slate-50 border border-slate-100 rounded p-1.5 space-y-0.5 text-[10px]">
													<div><b>{ expanded.payment.bank_label }</b> · STK <code>{ expanded.payment.account_no }</code> ({ expanded.payment.account_name })</div>
													<div>Nội dung: <code className="bg-amber-100 px-1">{ expanded.payment.content }</code></div>
													{ expanded.payment.qr_img_url ? (
														<div className="text-center pt-1"><img src={ expanded.payment.qr_img_url } alt="QR" className="inline-block max-w-[160px] border border-slate-200 rounded" /></div>
													) : <div className="italic text-slate-500">Không có QR (cần BIN cho VietQR).</div> }
												</div>
											) : null }
										</div>
									) : null }
								</li>
							);
						} ) }
					</ul>
				</section>
			) : null }

			{ /* Product search */ }
			<section>
				<h4 className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold mb-1">1. Sản phẩm</h4>
				<form onSubmit={ onSearch } className="flex gap-1 mb-2">
					<input value={ q } onChange={ ( e ) => setQ( e.target.value ) } placeholder="Tên hoặc SKU…" className="flex-1 border border-slate-200 px-2 py-1 rounded text-[12px]" />
					<button type="submit" className="px-2 py-1 bg-indigo-600 text-white rounded text-[11px] font-semibold">Tìm</button>
				</form>
				{ searching ? <div className="text-[10px] text-slate-400 italic">Đang tìm…</div> : null }
				{ products.length > 0 ? (
					<ul className="max-h-48 overflow-y-auto border border-slate-100 rounded divide-y divide-slate-50">
						{ products.map( ( p ) => (
							<li key={ p.id } className="flex items-center gap-2 px-2 py-1.5 hover:bg-slate-50">
								{ p.image ? <img src={ p.image } alt="" className="w-8 h-8 object-cover rounded" /> : <div className="w-8 h-8 bg-slate-100 rounded" /> }
								<div className="flex-1 min-w-0">
									<div className="truncate font-medium">{ p.title }</div>
									<div className="text-[10px] text-slate-500">{ p.sku || '—' } · { p.price_html || ( Number( p.price ).toLocaleString( 'vi-VN' ) + ' ₫' ) }</div>
								</div>
								<button type="button" onClick={ () => addToCart( p ) } className="px-2 py-0.5 text-[11px] border border-indigo-200 text-indigo-700 rounded hover:bg-indigo-50">+ Thêm</button>
							</li>
						) ) }
					</ul>
				) : null }
			</section>

			{ /* Cart */ }
			{ cart.length > 0 ? (
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Giỏ ({ cart.length })</h4>
					<table className="w-full text-[11px] border border-slate-100">
						<thead><tr className="bg-slate-50"><th className="text-left px-1 py-1">Sản phẩm</th><th className="px-1 py-1">SL</th><th className="px-1 py-1">Giá</th><th></th></tr></thead>
						<tbody>
							{ cart.map( ( it ) => (
								<tr key={ it.product_id } className="border-t border-slate-50">
									<td className="px-1 py-1 truncate max-w-[120px]" title={ it.name }>{ it.name }</td>
									<td className="px-1 py-1"><input type="number" min="1" value={ it.qty } onChange={ ( e ) => updateQty( it.product_id, e.target.value ) } className="w-12 border border-slate-200 px-1 rounded" /></td>
									<td className="px-1 py-1"><input type="number" min="0" step="1000" value={ it.price } onChange={ ( e ) => updatePrice( it.product_id, e.target.value ) } className="w-20 border border-slate-200 px-1 rounded" /></td>
									<td className="px-1 py-1"><button type="button" onClick={ () => removeRow( it.product_id ) } className="text-rose-500 hover:text-rose-700">×</button></td>
								</tr>
							) ) }
						</tbody>
					</table>
				</section>
			) : (
				<section>
					<h4 className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold mb-1">Hoặc nhập số tiền</h4>
					<input type="number" min="0" step="1000" value={ customAmount } onChange={ ( e ) => setCustom( e.target.value ) } placeholder="VD: 250000" className="w-full border border-slate-200 px-2 py-1 rounded text-[12px]" />
				</section>
			) }

			{ /* Bank */ }
			<section>
				<div className="flex items-center justify-between mb-1">
					<h4 className="text-[10px] uppercase tracking-wide text-slate-500 font-semibold">2. Tài khoản nhận</h4>
					<button type="button" onClick={ () => setShowBankForm( ( v ) => ! v ) } className="text-[10px] text-indigo-600 hover:underline">{ showBankForm ? '× Đóng' : '+ Thêm bank (CRM)' }</button>
				</div>
				{ bankOpts.length === 0 ? (
					<div className="text-[10px] text-rose-600">Chưa cấu hình tài khoản. Nhấn <b>+ Thêm bank (CRM)</b> để nhập trực tiếp (BIN + STK + tên) — sẽ có VietQR ngay, không cần WC BACS hay TTCK.</div>
				) : bankSource === 'manual' ? (
					<div className="text-[10px] text-amber-700">BACS đã bật nhưng chưa có tài khoản nào. Nhấn <b>+ Thêm bank (CRM)</b> để nhập STK + BIN — sẽ có QR auto.</div>
				) : (
					<select value={ paymentOption } onChange={ ( e ) => setPayOpt( e.target.value ) } className="w-full border border-slate-200 px-2 py-1 rounded text-[12px]">
						{ bankOpts.map( ( o ) => (
							<option key={ o.value } value={ o.value }>[{ o.source }] { o.bank_label } — { o.account_no } ({ o.account_name })</option>
						) ) }
					</select>
				) }
				{ showBankForm ? (
					<div className="mt-2 p-2 bg-slate-50 border border-slate-100 rounded space-y-1.5">
						<div className="grid grid-cols-2 gap-1.5">
							<input value={ bankForm.bank_label } onChange={ ( e ) => setBankForm( { ...bankForm, bank_label: e.target.value } ) } placeholder="Tên NH (vd: Vietcombank) *" className="border border-slate-200 px-2 py-1 rounded text-[11px]" />
							<input value={ bankForm.bank_id }    onChange={ ( e ) => setBankForm( { ...bankForm, bank_id: e.target.value } ) }    placeholder="Mã NH (slug, vd: vcb)" className="border border-slate-200 px-2 py-1 rounded text-[11px]" />
							<input value={ bankForm.bin }        onChange={ ( e ) => setBankForm( { ...bankForm, bin: e.target.value } ) }        placeholder="BIN (vd: 970436 - VCB)" className="border border-slate-200 px-2 py-1 rounded text-[11px]" />
							<input value={ bankForm.account_no } onChange={ ( e ) => setBankForm( { ...bankForm, account_no: e.target.value } ) } placeholder="Số tài khoản *" className="border border-slate-200 px-2 py-1 rounded text-[11px]" />
							<input value={ bankForm.account_name } onChange={ ( e ) => setBankForm( { ...bankForm, account_name: e.target.value } ) } placeholder="Chủ tài khoản (in HOA)" className="border border-slate-200 px-2 py-1 rounded text-[11px] col-span-2" />
						</div>
						<div className="text-[9px] text-slate-500">Tra cứu BIN: <a href="https://api.vietqr.io/v2/banks" target="_blank" rel="noreferrer" className="underline">api.vietqr.io/v2/banks</a> (vd: VCB=970436, TCB=970407, MB=970422, ACB=970416, BIDV=970418).</div>
						<div className="flex gap-1.5">
							<button type="button" disabled={ addBankState.isLoading } onClick={ submitBankForm } className="px-2 py-1 text-[11px] bg-indigo-600 text-white rounded font-semibold disabled:bg-slate-300">{ addBankState.isLoading ? '⏳ Đang lưu…' : '💾 Lưu' }</button>
							<button type="button" onClick={ () => setShowBankForm( false ) } className="px-2 py-1 text-[11px] bg-white border border-slate-200 rounded">Huỷ</button>
						</div>
					</div>
				) : null }
				{ savedBanks.length > 0 ? (
					<div className="mt-1.5 space-y-0.5">
						{ savedBanks.map( ( b, i ) => (
							<div key={ i } className="flex items-center gap-1.5 text-[10px] px-1.5 py-0.5 border border-slate-100 rounded">
								<span className="px-1 bg-indigo-50 text-indigo-700 rounded text-[9px]">CRM</span>
								<span className="flex-1 truncate">{ b.bank_label } · { b.account_no } ({ b.account_name }) { b.bin ? <code className="text-emerald-600">QR✓</code> : <code className="text-amber-600">no-BIN</code> }</span>
								<button type="button" onClick={ () => removeBank( i ) } className="text-rose-500 hover:text-rose-700">×</button>
							</div>
						) ) }
					</div>
				) : null }
			</section>

			{ /* Note */ }
			<section>
				<textarea value={ note } onChange={ ( e ) => setNote( e.target.value ) } placeholder="Ghi chú (optional)…" rows={ 2 } className="w-full border border-slate-200 px-2 py-1 rounded text-[12px]" />
			</section>

			{ /* Submit */ }
			<div className="flex items-center justify-between">
				<div className="text-[12px]">Tổng: <span className="font-bold text-indigo-700">{ total.toLocaleString( 'vi-VN' ) } ₫</span></div>
				<button
					type="button"
					disabled={ createState.isLoading || total <= 0 || ! paymentOption || ! adapter }
					onClick={ submit }
					className="px-3 py-1.5 bg-indigo-600 disabled:bg-slate-300 text-white rounded text-[12px] font-semibold"
				>{ createState.isLoading ? '⏳ Đang tạo…' : ( bankSource === 'ttck' ? '📦 Tạo đơn + QR' : '📦 Tạo đơn' ) }</button>
			</div>

			{ error ? (
				<div className="px-3 py-2 bg-rose-50 border border-rose-200 text-rose-700 rounded text-[11px]">Lỗi: { error }</div>
			) : null }

			{ result ? (
				<div className="border border-emerald-200 bg-emerald-50 rounded p-3 space-y-2">
					<div className="font-semibold text-emerald-800">✅ Đã tạo đơn #{ result.order_id }</div>
					<div className="text-[11px] text-slate-700">
						Tổng: <b>{ Number( result.total ).toLocaleString( 'vi-VN' ) } { result.currency }</b> · status: { result.status }
					</div>
					{ result.payment ? (
						<>
							<div className="text-[11px] bg-white border border-emerald-200 rounded p-2 space-y-0.5">
								<div><b>{ result.payment.bank_label }</b></div>
								<div>STK: <code>{ result.payment.account_no }</code> — { result.payment.account_name }</div>
								<div>Số tiền: <b>{ Number( result.payment.amount ).toLocaleString( 'vi-VN' ) } đ</b></div>
								<div>Nội dung: <code className="bg-amber-100 px-1">{ result.payment.content }</code></div>
							</div>
							{ result.payment.qr_img_url ? (
								<div className="text-center">
									<img src={ result.payment.qr_img_url } alt="VietQR" className="inline-block max-w-[220px] border border-slate-200 rounded" />
								</div>
							) : null }
						</>
					) : null }
					<div className="flex flex-wrap gap-1.5">
						<a href={ result.checkout_url } target="_blank" rel="noreferrer" className="px-2 py-1 text-[11px] bg-white border border-indigo-300 text-indigo-700 rounded hover:bg-indigo-50">🔗 Checkout link</a>
						<button type="button" onClick={ () => navigator.clipboard?.writeText( result.checkout_url ) } className="px-2 py-1 text-[11px] bg-white border border-slate-300 rounded hover:bg-slate-50">📋 Copy link</button>
						<a href={ result.admin_url } target="_blank" rel="noreferrer" className="px-2 py-1 text-[11px] bg-white border border-slate-300 rounded hover:bg-slate-50">⚙ Mở admin</a>
					</div>
				</div>
			) : null }
		</div>
	);
}
