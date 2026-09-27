<?php
/**
 * PHASE-0.80 doc 26 (OB-4) — wp-admin "BizCity — Bắt đầu": the first screen a new site admin sees.
 *
 *   ① Kết nối BizCity   ② Quét QR Zalo   ③ Nhắn thử và xem tin về CRM
 *
 * Everything shown comes from the ONE owner BizCity_Zalo_Connection_Status (GET zalo-connection/status),
 * read in the browser so wp-admin never waits on the Hub. No new build: PHP + a small vanilla script.
 *
 * Also: one redirect here right after the plugin is activated (not on bulk/network activation),
 * a Dashboard widget, and an admin notice until the three steps are done once.
 *
 * Until OB-5 ("Kết nối BizCity" one-click) lands, step ① offers: open bizcity.vn to create a key, or
 * paste it here (saved through the existing LLM Settings AJAX `bizcity_llm_save_key` — one key owner).
 * OB-6: step ② creates the first number and shows its QR inline (existing zalo-bridge create/qr/qr-status routes),
 * and that first number answers right away with the default Guru (filter below, D-OB-3) + a 24 h yellow band.
 *
 * // [2026-09-27 Claude Opus 5.5] PHASE-0.80 doc 26 OB-4
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Zalo_Start_Page {

	const SLUG            = 'bizcity-start';
	const REDIRECT_OPTION = 'bizcity_start_redirect';
	const DONE_OPTION     = 'bizcity_start_done';
	const MAIN_PLUGIN     = 'bizcity-twin-ai/bizcity-twin-ai.php';

	public static function init(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'wp_ajax_bizcity_start_done', array( __CLASS__, 'ajax_done' ) );
		add_filter( 'bizcity_zalo_personal_account_created', array( __CLASS__, 'first_number_bot' ), 10, 4 );
	}

	public static function can(): bool {
		return class_exists( 'BizCity_Zalo_Bridge_REST' ) ? BizCity_Zalo_Bridge_REST::can_manage() : current_user_can( 'manage_options' );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/** Remember to show the start page once, only for a single-plugin activation of Twin AI on this site. */
	public static function on_activated( $plugin, $network_wide = false ): void {
		if ( $plugin !== self::MAIN_PLUGIN || $network_wide ) {
			return;
		}
		update_option( self::REDIRECT_OPTION, 1, false );
	}

	public static function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'WP_CLI' ) && WP_CLI ) || is_network_admin() || isset( $_GET['activate-multi'] ) || ! self::can() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		delete_option( self::REDIRECT_OPTION );
		if ( get_option( self::DONE_OPTION ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Top-level "🚀 Bắt đầu" until the three steps are done once; afterwards it lives under Bảng tin. */
	public static function menu(): void {
		$cap = class_exists( 'BizCity_Network_Admin_Capability' ) ? BizCity_Network_Admin_Capability::menu_cap() : 'manage_options';
		if ( get_option( self::DONE_OPTION ) ) {
			add_submenu_page( 'index.php', 'BizCity — Bắt đầu', 'BizCity — Bắt đầu', $cap, self::SLUG, array( __CLASS__, 'render' ) );
		} else {
			add_menu_page( 'BizCity — Bắt đầu', '🚀 Bắt đầu', $cap, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-flag', 2 );
		}
	}

	public static function dashboard_widget(): void {
		if ( ! self::can() ) {
			return;
		}
		wp_add_dashboard_widget( 'bizcity_start_widget', 'Zalo · BizCity', array( __CLASS__, 'render_widget' ) );
	}

	/** "Ẩn" on the notice: a site that does not use Zalo is not reminded again (the page stays reachable). */
	public static function maybe_dismiss(): void {
		if ( empty( $_GET['bizcity_start_dismiss'] ) || ! self::can() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'bizcity_start_dismiss' );
		update_option( self::DONE_OPTION, 'dismissed:' . gmdate( 'c' ), false );
		wp_safe_redirect( remove_query_arg( array( 'bizcity_start_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/** Cheap reminder: no network call, only the done flag. */
	public static function notice(): void {
		if ( get_option( self::DONE_OPTION ) || ! self::can() || is_network_admin() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( strpos( (string) $screen->id, self::SLUG ) !== false || $screen->id === 'dashboard' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Zalo chưa sẵn sàng.</strong> Làm 3 bước (kết nối BizCity, quét QR, nhắn thử) để bot trả lời khách qua Zalo. <a class="button button-primary" style="margin-left:8px" href="' . esc_url( self::url() ) . '">Mở trang Bắt đầu</a> <a style="margin-left:8px" href="' . esc_url( wp_nonce_url( add_query_arg( 'bizcity_start_dismiss', '1' ), 'bizcity_start_dismiss' ) ) . '">Ẩn (website không dùng Zalo)</a></p></div>';
	}

	/* ================================================================
	 *  OB-6 / D-OB-3 — the site's FIRST Zalo number answers right away with the default Guru (guru:0)
	 * ================================================================ */

	const FIRST_BOT_OPTION = 'bizcity_start_first_bot';

	/** @var array<string,callable> test seams: bindings(), accounts(), upsert(array), default_guru() */
	public static $io = array();

	/**
	 * Filter `bizcity_zalo_personal_account_created`. Only for the first personal number of this site (no Zalo
	 * Personal binding yet): bind the default Guru in `auto` — private chats answered, groups only on @mention,
	 * bot pauses when staff replies. Every later number keeps today's behaviour (no binding ⇒ silent until the
	 * admin picks a Guru). Way back: filter `bizcity_zalo_first_number_bot_on` ⇒ false, or switch the bot off.
	 */
	public static function first_number_bot( $created, $bridge_id = '', $kind = 'personal', $owner_id = 0 ) {
		$created = is_array( $created ) ? $created : array();
		$bridge_id = (string) $bridge_id;
		if ( 'personal' !== $kind || '' === $bridge_id || empty( $created['ok'] ) ) {
			return $created;
		}
		if ( ! apply_filters( 'bizcity_zalo_first_number_bot_on', true, $bridge_id ) ) {
			return $created;
		}
		foreach ( (array) self::io( 'bindings' ) as $b ) {
			if ( is_array( $b ) && strtoupper( (string) ( $b['platform'] ?? '' ) ) === 'ZALO_PERSONAL' ) {
				return $created; // not the first number (or this one is already bound)
			}
		}
		// An older site may have numbers with no binding yet (silent by choice): only the very first number of the site qualifies.
		foreach ( (array) self::io( 'accounts' ) as $a ) {
			if ( is_array( $a ) && (string) ( $a['bridge_account_id'] ?? '' ) !== $bridge_id && ! in_array( (string) ( $a['status'] ?? '' ), array( 'orphaned', 'deleted' ), true ) ) {
				return $created;
			}
		}
		$guru = (int) self::io( 'default_guru' );
		if ( $guru <= 0 ) {
			return $created;
		}
		$binding_id = (int) self::io( 'upsert', array(
			'platform'     => 'ZALO_PERSONAL',
			'account_id'   => $bridge_id,
			'character_id' => $guru,
			'mode'         => 'auto',
			'office_hours' => class_exists( 'BizCity_Bot_Office_Hours' ) ? BizCity_Bot_Office_Hours::defaults() : array( 'enabled' => false, 'require_mention_in_group' => true, 'pause_on_manual_reply' => true ),
			'meta'         => array( 'source' => 'ob6_first_number', 'at' => gmdate( 'c' ) ),
		) );
		if ( $binding_id <= 0 ) {
			return $created;
		}
		update_option( self::FIRST_BOT_OPTION, array( 'account_id' => $bridge_id, 'binding_id' => $binding_id, 'guru_id' => $guru, 'at' => time() ), false );
		$created['bot'] = array( 'enabled' => true, 'mode' => 'auto', 'guru_ref' => 'guru:0', 'source' => 'first_number' );
		return $created;
	}

	/** Yellow band for 24 h after the first number went live with the default Guru (D-OB-3). */
	public static function first_bot_banner(): ?array {
		$f = get_option( self::FIRST_BOT_OPTION );
		if ( ! is_array( $f ) || empty( $f['at'] ) || time() - (int) $f['at'] > DAY_IN_SECONDS ) {
			return null;
		}
		return array( 'account_id' => (string) ( $f['account_id'] ?? '' ), 'guru_id' => (int) ( $f['guru_id'] ?? 0 ) );
	}

	private static function io( string $name, ...$args ) {
		if ( isset( self::$io[ $name ] ) ) {
			return call_user_func_array( self::$io[ $name ], $args );
		}
		switch ( $name ) {
			case 'bindings':
				return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::all() : array( array( 'platform' => 'ZALO_PERSONAL' ) ); // unknown ⇒ do nothing
			case 'accounts':
				return class_exists( 'BizCity_Zalo_Mapping_Repo' ) ? BizCity_Zalo_Mapping_Repo::list_personal_accounts( array( 'limit' => 5 ) ) : array( array( 'bridge_account_id' => '?' ) ); // unknown ⇒ do nothing
			case 'default_guru':
				return class_exists( 'BizCity_Guru_Context_Resolver' ) ? BizCity_Guru_Context_Resolver::default_character_id( true ) : 0;
			case 'upsert':
				return class_exists( 'BizCity_Channel_Binding' ) ? BizCity_Channel_Binding::upsert( (array) $args[0] ) : 0;
		}
		return null;
	}

	public static function ajax_done(): void {
		check_ajax_referer( 'bizcity_start', 'nonce' );
		if ( ! self::can() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		update_option( self::DONE_OPTION, gmdate( 'c' ), false );
		wp_send_json_success();
	}

	/** Browser config shared by the page and the widget (no secret). */
	private static function config( string $mode ): array {
		$gateway = class_exists( 'BizCity_LLM_Client' ) ? rtrim( BizCity_LLM_Client::instance()->get_gateway_url(), '/' ) : 'https://bizcity.vn';
		return array(
			'mode'       => $mode,
			'restUrl'    => esc_url_raw( rest_url( 'bizcity-channel/v1/' ) ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'keyNonce'   => wp_create_nonce( 'bizcity_llm_admin' ),
			'doneNonce'  => wp_create_nonce( 'bizcity_start' ),
			'done'       => (bool) get_option( self::DONE_OPTION ),
			'startUrl'   => self::url(),
			'createKeyUrl' => $gateway . '/my-account/api-keys/',
			// [2026-09-27] PHASE-0.80 doc 26 OB-5 — one-click "Kết nối BizCity"; empty when the connect-flow class
			// is not loaded yet (older deploy), and the button falls back to createKeyUrl.
			'connectUrl' => class_exists( 'BizCity_LLM_Connect_Flow' ) ? BizCity_LLM_Connect_Flow::start_url() : '',
			'notice'     => ( $mode === 'page' && class_exists( 'BizCity_LLM_Connect_Flow' ) ) ? BizCity_LLM_Connect_Flow::pop_notice() : null,
			'qrUrl'      => admin_url( 'admin.php?page=bizchat-gateway-spa#/p/zalo_personal' ),
			'testUrl'    => admin_url( 'admin.php?page=bizchat-gateway-spa#/p/zalo_personal/test' ),
			// [2026-09-27] OB-6 — inline "add number + QR" in step ② and the 24 h band after the first number went live.
			'guruUrl'    => admin_url( 'admin.php?page=bizchat-gateway-spa#/gateway/agents' ),
			'firstBot'   => self::first_bot_banner(),
			'crmUrl'     => home_url( '/crm/?tab=inbox' ),
			'site'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		);
	}

	public static function render(): void {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'Permission denied.', 'bizcity-twin-ai' ) );
		}
		echo '<div class="wrap"><h1>Bắt đầu với BizCity</h1><p style="max-width:720px">Ba bước để khách nhắn Zalo là bot trả lời và tin nhắn về hộp thư CRM. Mỗi bước tự kiểm tra; bước nào xanh là xong.</p>';
		self::mount( 'page' );
		echo '</div>';
	}

	public static function render_widget(): void {
		self::mount( 'widget' );
	}

	private static function mount( string $mode ): void {
		$id = 'bzc-start-' . $mode;
		echo '<div id="' . esc_attr( $id ) . '" class="bzc-start bzc-start--' . esc_attr( $mode ) . '"><p>Đang kiểm tra kết nối…</p></div>';
		static $assets = false;
		if ( ! $assets ) {
			$assets = true;
			echo '<style>' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS
			echo '<script>' . self::js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static JS
		}
		echo '<script>window.BizCityStart && window.BizCityStart(' . wp_json_encode( $id ) . ',' . wp_json_encode( self::config( $mode ) ) . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON
	}

	private static function css(): string {
		return '.bzc-start{max-width:760px}.bzc-start__step{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;margin:12px 0;display:flex;gap:14px;align-items:flex-start}'
			. '.bzc-start__num{flex:none;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;background:#f0f0f1;color:#50575e}'
			. '.bzc-start__step--ok .bzc-start__num{background:#00a32a;color:#fff}.bzc-start__step--fail .bzc-start__num{background:#d63638;color:#fff}.bzc-start__step--warn .bzc-start__num{background:#dba617;color:#fff}'
			. '.bzc-start__body{flex:1;min-width:0}.bzc-start__title{font-size:15px;font-weight:600;margin:3px 0}.bzc-start__msg{color:#50575e;margin:4px 0}.bzc-start__actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}'
			. '.bzc-start__row{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.bzc-start__row input{min-width:260px;flex:1}.bzc-start__pill{display:inline-block;border-radius:99px;padding:0 8px;font-size:12px;font-weight:600;margin-left:6px}'
			. '.bzc-start__pill--hub{background:#ede9fe;color:#6d28d9}.bzc-start__pill--zca{background:#f0f0f1;color:#3c434a}.bzc-start__foot{color:#646970;font-size:12px;margin-top:8px}.bzc-start__bubble{margin-top:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:12px 12px 12px 4px;padding:8px 12px;white-space:pre-wrap;max-width:560px}'
			. '.bzc-start__qr{display:block;width:220px;height:220px;margin-top:10px;border:1px solid #dcdcde;border-radius:8px;image-rendering:pixelated}.bzc-start__band{background:#fcf9e8;border:1px solid #dba617;border-radius:10px;padding:10px 14px;margin:12px 0}'
			. '.bzc-start--widget .bzc-start__step{border:0;padding:6px 0;margin:0}.bzc-start--widget .bzc-start__num{width:22px;height:22px;font-size:12px}.bzc-start--widget .bzc-start__title{font-size:13px}';
	}

	/** Vanilla renderer of the connection report into the three steps (page) or three lines (widget). */
	private static function js(): string {
		return <<<'JS'
(function(){
if(window.BizCityStart){return;}
function el(tag,attrs,kids){var e=document.createElement(tag);attrs=attrs||{};for(var k in attrs){if(k==='text'){e.textContent=attrs[k];}else if(k==='cls'){e.className=attrs[k];}else if(k.indexOf('on')===0){e.addEventListener(k.slice(2),attrs[k]);}else{e.setAttribute(k,attrs[k]);}}(kids||[]).forEach(function(c){if(c){e.appendChild(c);}});return e;}
function btn(label,primary,onclick,href){var a=el(href?'a':'button',{cls:'button'+(primary?' button-primary':''),text:label});if(href){a.href=href;if(/^https?:\/\//.test(href)&&href.indexOf(location.host)<0){a.target='_blank';a.rel='noopener';}}else{a.type='button';a.addEventListener('click',onclick);}return a;}
function worst(ls){var o={fail:3,warn:2,ok:1,skip:0};return (ls||[]).reduce(function(a,l){return o[l.status]>o[a.status]?l:a;},{status:'ok'});}
window.BizCityStart=function(id,cfg){
 var root=document.getElementById(id);if(!root){return;}
 var state={report:null,error:null,pasting:false,note:'',trying:'',tryBusy:false,tryResult:null,qr:null,phone:''};
 function api(path,opt){opt=opt||{};var h={'Accept':'application/json','X-WP-Nonce':cfg.restNonce};if(opt.body){h['Content-Type']='application/json';}
  return fetch(cfg.restUrl+path,{method:opt.method||'GET',credentials:'same-origin',headers:h,body:opt.body?JSON.stringify(opt.body):undefined}).then(function(r){return r.json().then(function(j){if(!r.ok){throw j||{};}return j;});});}
 function load(force){root.setAttribute('aria-busy','true');api('zalo-connection/status'+(force?'?force=1':'')).then(function(r){state.report=r;state.error=null;draw();maybeDone();},function(e){state.error=e;draw();});}
 function saveKey(v){state.note='Đang lưu…';draw();var fd=new FormData();fd.append('action','bizcity_llm_save_key');fd.append('nonce',cfg.keyNonce);fd.append('api_key',v);
  fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){state.pasting=false;state.note='Đã lưu mã kết nối. Đang kiểm tra lại…';load(true);}else{state.note=(j&&j.data&&(j.data.message||j.data))||'Không lưu được mã.';draw();}},function(){state.note='Không lưu được mã.';draw();});}
 function echo(acc){state.note='Đang gửi thử một tin từ BizCity về website…';draw();api('zalo-connection/echo',{method:'POST',body:{account_id:acc}}).then(function(r){state.note=(r.layer&&r.layer.message)||'Đã kiểm tra.';draw();},function(e){state.note=(e&&e.message)||'Không kiểm tra được.';draw();});}
 function setupDone(r){var bad=(r.layers||[]).some(function(l){return l.status==='fail'&&['L0','L1','L2','L3','L4','L5'].indexOf(l.id)>=0;});return !bad&&(r.accounts||[]).some(function(a){return a.session==='connected';});}
 function tryBot(acc,text){state.tryBusy=true;state.tryResult=null;draw();api('zalo-connection/bot-test',{method:'POST',body:{account_id:acc,text:text}}).then(function(r){state.tryResult=r;state.tryBusy=false;draw();},function(e){state.tryResult={success:false,message:(e&&e.message)||'Không chạy thử được.'};state.tryBusy=false;draw();});}
 function normPhone(v){var d=String(v||'').replace(/\D/g,'');if(d.indexOf('84')===0&&d.length===11){d='0'+d.slice(2);}return /^0(3|5|7|8|9)\d{8}$/.test(d)?d:'';}
 function failMsg(e,fb){return ((e&&e.message)||fb)+(e&&e.hint?' — '+e.hint:'');}
 function addNumber(raw){var p=normPhone(raw);state.phone=raw;if(!p){state.qr={id:'',msg:'Số điện thoại chưa đúng. Nhập số Zalo 10 chữ số, ví dụ 0912345678.'};draw();return;}
  state.qr={id:'',busy:true,msg:'Đang tạo số Zalo…'};draw();
  api('zalo-bridge/accounts',{method:'POST',body:{label:p.replace(/(\d{4})(\d{3})(\d{3})/,'$1 $2 $3')}}).then(function(r){if(!r||r.ok===false||!r.id){throw r||{};}state.qr={id:String(r.id),bot:r.bot||null};showQr(state.qr.id);}).catch(function(e){state.qr={id:'',msg:failMsg(e,'Không tạo được số Zalo.')};draw();});}
 function showQr(id){var q=state.qr&&state.qr.id===id?state.qr:(state.qr={id:id});q.busy=true;q.img='';q.status='';q.msg='Đang lấy mã QR…';q.tries=0;draw();
  api('zalo-bridge/accounts/'+encodeURIComponent(id)+'/qr',{method:'POST'}).then(function(r){if(!r||r.ok===false||!r.qr_base64){throw r||{};}if(state.qr!==q){return;}q.busy=false;q.img=r.qr_base64.indexOf('data:')===0?r.qr_base64:'data:image/png;base64,'+r.qr_base64;q.msg='Mở Zalo trên điện thoại → biểu tượng QR → quét mã này.';draw();pollQr(q);}).catch(function(e){if(state.qr!==q){return;}q.busy=false;q.msg=failMsg(e,'Không lấy được mã QR.');q.status='error';draw();});}
 function pollQr(q){setTimeout(function(){if(state.qr!==q||!document.body.contains(root)){return;}q.tries++;
  api('zalo-bridge/accounts/'+encodeURIComponent(q.id)+'/qr-status').then(function(r){if(state.qr!==q){return;}var s=String((r&&(r.status||r.qr_status))||'');
   if(s==='connected'){q.status='connected';q.img='';q.msg='Đã đăng nhập Zalo.'+(q.bot&&q.bot.enabled?' Bot đã bật với Guru mặc định.':'');draw();load(true);return;}
   if(['expired','logged_out','revoked'].indexOf(s)>=0||(r&&r.ok===false&&r.reason_bucket)){q.status='expired';q.img='';q.msg=(r&&r.ok===false&&r.message)?failMsg(r,''):'Mã QR đã hết hạn.';draw();return;}
   if(q.tries>=60){q.status='expired';q.img='';q.msg='Chưa thấy quét mã sau 3 phút.';draw();return;}pollQr(q);},function(){if(state.qr===q&&q.tries<60){pollQr(q);}});},3000);}
 function maybeDone(){if(cfg.done||!state.report||!setupDone(state.report)){return;}var fd=new FormData();fd.append('action','bizcity_start_done');fd.append('nonce',cfg.doneNonce);fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd});cfg.done=true;}
 function siteStep(r){var ls=(r.layers||[]).filter(function(l){return ['L0','L1','L2','L3','L4','L5'].indexOf(l.id)>=0;});var w=worst(ls);var s=r.site||{};
  var st={status:w.status==='skip'?'ok':w.status,title:'Kết nối BizCity',msg:w.status==='ok'?('Đã kết nối'+(s.plan&&s.plan.label?' · gói '+s.plan.label:'')+(s.domain?' · '+s.domain:'')+'.'):w.message,hint:w.hint||'',actions:[]};
  if(w.status==='fail'){var a=w.action;
   if(a==='connect'){if(cfg.connectUrl){st.actions.push(btn('Kết nối BizCity',true,null,cfg.connectUrl));}else{st.actions.push(btn('Tạo mã kết nối trên bizcity.vn',true,null,cfg.createKeyUrl));}st.actions.push(btn('Tôi đã có mã — dán vào đây',false,function(){state.pasting=true;draw();}));}
   else if(a==='upgrade'||a==='renew'){st.actions.push(btn(a==='renew'?'Gia hạn gói':'Nâng cấp gói',true,null,(s.plan&&s.plan.upgrade_url)||cfg.createKeyUrl.replace('api-keys/','plans/')));}
   else if(a==='move_domain'||a==='open_account'){st.actions.push(btn('Mở tài khoản BizCity',true,null,cfg.createKeyUrl));st.actions.push(btn('Dán mã khác',false,function(){state.pasting=true;draw();}));}
   else{st.actions.push(btn('Kiểm tra lại',true,function(){load(true);}));}}
  return st;}
 function qrStep(r){var acc=r.accounts||[];var on=acc.filter(function(a){return a.session==='connected';});
  if(!acc.length){return cfg.mode==='page'?{status:'todo',title:'Quét QR Zalo',msg:'Nhập số Zalo của cửa hàng, bấm nút, rồi quét mã QR hiện ra bằng Zalo trên điện thoại.',addForm:true,actions:[]}:{status:'todo',title:'Quét QR Zalo',msg:'Chưa có số Zalo nào.',actions:[]};}
  if(!on.length){var first=acc[0];return {status:'warn',title:'Quét QR Zalo',msg:'Số Zalo chưa đăng nhập. Bấm "Hiện mã QR" và quét bằng Zalo trên điện thoại.',actions:cfg.mode==='page'?[btn('Hiện mã QR',true,function(){showQr(String(first.account_id));}),btn('Mở trang quản lý số',false,null,cfg.qrUrl)]:[],accounts:acc};}
  return {status:worst([].concat.apply([],acc.map(function(a){return a.layers||[];}))).status==='fail'?'fail':'ok',title:'Quét QR Zalo',msg:on.length+' số đã đăng nhập Zalo.',accounts:acc,actions:[btn('Quản lý số Zalo',false,null,cfg.qrUrl)]};}
 function tryStep(r){var l8=(r.layers||[]).filter(function(l){return l.id==='L8';})[0];var acc=(r.accounts||[]).filter(function(a){return a.session==='connected';});
  if(!acc.length){return {status:'todo',title:'Nhắn thử',msg:'Sau khi quét QR, nhờ ai đó nhắn vào số Zalo để thấy bot trả lời và tin về CRM.',actions:[]};}
  var hub=acc.filter(function(a){return a.bridge==='zalo_hub';})[0];var tryBtn=hub?btn('Thử bot ngay',false,function(){state.trying=state.trying?'':hub.account_id;draw();}):null;
  if(l8&&l8.status==='ok'){return {status:'ok',title:'Nhắn thử',msg:'Tin nhắn Zalo đã về website.',actions:[btn('Mở hộp thư CRM',false,null,cfg.crmUrl)].concat(tryBtn?[tryBtn]:[])};}
  return {status:'warn',title:'Nhắn thử',msg:'Dùng một Zalo khác nhắn vào số '+(acc[0].label||'')+' rồi xem tin trong hộp thư CRM'+(hub?', hoặc bấm "Thử bot ngay" để xem bot trả lời mà không cần điện thoại.':'.'),actions:(tryBtn?[tryBtn]:[]).concat([btn('Mở hộp thư CRM',!hub,null,cfg.crmUrl),btn('Kiểm tra đường tin về',false,function(){echo(acc[0].account_id);})])};}
 function draw(){root.removeAttribute('aria-busy');root.innerHTML='';
  if(state.error){var m=state.error.message||'Không đọc được trạng thái kết nối.';root.appendChild(el('p',{text:m}));root.appendChild(btn('Thử lại',true,function(){load(true);}));return;}
  var r=state.report;if(!r){root.appendChild(el('p',{text:'Đang kiểm tra kết nối…'}));return;}
  if(cfg.firstBot){var fb=cfg.firstBot;var hubAcc=(r.accounts||[]).filter(function(a){return String(a.account_id)===String(fb.account_id)&&a.bridge==='zalo_hub'&&a.session==='connected';})[0];
   root.appendChild(el('div',{cls:'bzc-start__band'},[el('strong',{text:'Bot đang trả lời khách bằng Guru mặc định.'}),document.createTextNode(' Tin nhắn riêng được trả lời ngay; trong nhóm chỉ trả lời khi được @nhắc tên; nhân viên nhắn tay thì bot tạm dừng.'),
    el('div',{cls:'bzc-start__actions'},[hubAcc&&cfg.mode==='page'?btn('Thử bot',false,function(){state.trying=hubAcc.account_id;draw();}):null,btn('Sửa lời giới thiệu',false,null,cfg.guruUrl)])]));}
  var steps=[siteStep(r)];var siteOk=steps[0].status==='ok'||steps[0].status==='warn';
  steps.push(siteOk?qrStep(r):{status:'todo',title:'Quét QR Zalo',msg:'Làm bước 1 trước.',actions:[]});
  steps.push(siteOk?tryStep(r):{status:'todo',title:'Nhắn thử',msg:'Làm bước 1 và 2 trước.',actions:[]});
  steps.forEach(function(s,i){var body=el('div',{cls:'bzc-start__body'},[el('div',{cls:'bzc-start__title',text:s.title}),el('div',{cls:'bzc-start__msg',text:s.msg})]);
   if(s.hint&&cfg.mode==='page'){body.appendChild(el('div',{cls:'bzc-start__msg',text:s.hint}));}
   if(s.accounts&&cfg.mode==='page'){s.accounts.forEach(function(a){var w=worst(a.layers);body.appendChild(el('div',{cls:'bzc-start__msg'},[document.createTextNode((a.label||('Số #'+a.account_id))+' '),el('span',{cls:'bzc-start__pill bzc-start__pill--'+(a.bridge==='zalo_hub'?'hub':'zca'),text:a.bridge_label}),document.createTextNode(w.status==='ok'?'':(' · '+w.message))]));});}
   if(i===0&&state.pasting&&cfg.mode==='page'){var inp=el('input',{type:'text',cls:'regular-text',placeholder:'biz-…',autocomplete:'off'});body.appendChild(el('div',{cls:'bzc-start__row'},[inp,btn('Lưu và kiểm tra',true,function(){if(inp.value.trim()){saveKey(inp.value.trim());}})]));}
   if(i===1&&cfg.mode==='page'){var qs=state.qr;
    if(s.addForm&&!(qs&&qs.id)){var ph=el('input',{type:'tel',cls:'regular-text',placeholder:'Số Zalo, ví dụ 0912 345 678',autocomplete:'tel',inputmode:'tel'});ph.value=state.phone||'';var go=function(){if(!(qs&&qs.busy)){addNumber(ph.value);}};ph.addEventListener('keydown',function(ev){if(ev.key==='Enter'){go();}});body.appendChild(el('div',{cls:'bzc-start__row'},[ph,btn(qs&&qs.busy?'Đang tạo…':'Tạo số và hiện QR',true,go)]));}
    if(qs&&qs.msg){body.appendChild(el('div',{cls:'bzc-start__msg',text:qs.msg}));}
    if(qs&&qs.img){body.appendChild(el('img',{cls:'bzc-start__qr',src:qs.img,alt:'Mã QR đăng nhập Zalo'}));}
    if(qs&&qs.id&&(qs.status==='expired'||qs.status==='error')){body.appendChild(el('div',{cls:'bzc-start__actions'},[btn('Lấy mã QR mới',true,function(){showQr(qs.id);})]));}}
   if(s.actions.length&&cfg.mode==='page'&&!(i===1&&state.qr&&state.qr.img)){body.appendChild(el('div',{cls:'bzc-start__actions'},s.actions));}
   if(i===2&&state.trying&&cfg.mode==='page'){var q=el('input',{type:'text',cls:'regular-text',placeholder:'Gõ câu khách hay hỏi, ví dụ: Shop mở cửa mấy giờ?',maxlength:'2000'});body.appendChild(el('div',{cls:'bzc-start__row'},[q,btn(state.tryBusy?'Bot đang trả lời…':'Gửi thử',true,function(){if(q.value.trim()&&!state.tryBusy){tryBot(state.trying,q.value.trim());}})]));var tr=state.tryResult;if(tr&&tr.success){body.appendChild(el('div',{cls:'bzc-start__bubble',text:tr.blocked_prompt_leak?'(Câu trả lời bị chặn vì lộ cấu hình bot.)':(tr.reply||'(Bot không trả lời gì.)')}));body.appendChild(el('div',{cls:'bzc-start__foot',text:'Trả lời bởi '+tr.engine_label+' · chạy thử, không gửi Zalo'+(tr.would_reply?' · khách thật lúc này sẽ nhận câu trả lời tương tự.':' · khách thật lúc này sẽ KHÔNG nhận trả lời vì: '+((tr.silence||[]).map(function(x){return x.message;}).join('; ')||'chưa rõ'))}));}else if(tr){body.appendChild(el('div',{cls:'bzc-start__foot',text:(tr.message||'Không chạy thử được.')+(tr.hint?' — '+tr.hint:'')}));}}
   root.appendChild(el('div',{cls:'bzc-start__step bzc-start__step--'+s.status},[el('div',{cls:'bzc-start__num',text:s.status==='ok'?'✓':String(i+1)}),body]));});
  if(state.note&&cfg.mode==='page'){root.appendChild(el('p',{cls:'bzc-start__foot',text:state.note}));}
  var foot=el('div',{cls:'bzc-start__actions'});
  if(cfg.mode==='widget'){foot.appendChild(btn('Mở trang Bắt đầu',r.summary.status!=='ok',null,cfg.startUrl));}
  else{foot.appendChild(btn('Kiểm tra lại',false,function(){load(true);}));foot.appendChild(btn('Chi tiết kỹ thuật',false,null,cfg.testUrl));}
  root.appendChild(foot);}
 if(cfg.notice){state.note=cfg.notice.status==='success'?'Đã kết nối BizCity'+(cfg.notice.domain?' cho '+cfg.notice.domain:'')+'.':(cfg.notice.message||'Không kết nối được BizCity.');}
 load(!!cfg.notice&&cfg.notice.status==='success');
};
})();
JS;
	}
}
