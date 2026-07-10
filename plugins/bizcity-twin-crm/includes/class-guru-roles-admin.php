<?php
/**
 * BizCity CRM — Twin Guru Role/Template admin sub-screen.
 *
 * Adds a sub-menu under the CRM menu listing every Twin Guru, with inline
 * dropdowns for `crm_role` (External / Internal / Both) and `crm_template`
 * (Customer Service, Telesale, Page Inbox, Comment Reply, Seeding, Internal,
 * None). Saves into `bizcity_characters.settings` JSON.
 *
 * Standalone screen so the existing core/knowledge character-edit view is
 * untouched — and admins can configure all gurus in one place.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Guru_Roles_Admin {

	const SLUG = 'bizcity-crm-guru-roles';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_post_bizcity_crm_guru_role_save', array( __CLASS__, 'handle_save' ) );

		// Embed Role + Service Template selectors directly into the core
		// character-edit Overview tab so admins manage everything in one place.
		add_action( 'bizcity_knowledge_character_meta_rows', array( __CLASS__, 'render_inline_rows' ), 20 );
		add_action( 'bizcity_knowledge_character_saved',     array( __CLASS__, 'persist_inline_save' ), 20, 2 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'bizcity-crm',                              // parent (CRM root)
			'Twin Guru Roles',
			'Guru Roles',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		add_submenu_page(
			'bizcity-crm',
			'Persona Analytics',
			'Persona Analytics',
			'manage_options',
			'bizcity-crm-persona-analytics',
			array( __CLASS__, 'render_analytics' )
		);
		add_submenu_page(
			'bizcity-crm',
			'Persona Sandbox',
			'Persona Sandbox',
			'manage_options',
			'bizcity-crm-persona-sandbox',
			array( __CLASS__, 'render_sandbox' )
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		check_admin_referer( 'bizcity_crm_guru_role_save' );

		$cid      = (int) ( $_POST['character_id'] ?? 0 );
		$role     = (string) ( $_POST['crm_role']     ?? 'both' );
		$template = (string) ( $_POST['crm_template'] ?? 'none' );
		$extras   = array();
		if ( isset( $_POST['crm_custom_persona'] ) ) { $extras['custom_persona'] = wp_kses_post( wp_unslash( (string) $_POST['crm_custom_persona'] ) ); }
		if ( isset( $_POST['crm_custom_style'] ) )   { $extras['custom_style']   = wp_kses_post( wp_unslash( (string) $_POST['crm_custom_style'] ) ); }
		$ok       = BizCity_CRM_Service_Templates::save_for_character( $cid, $role, $template, $extras );

		$ref = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG );
		wp_safe_redirect( add_query_arg( array(
			'updated' => $ok ? '1' : '0',
			'cid'     => $cid,
		), $ref ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			echo '<div class="wrap"><h1>Twin Guru Roles</h1><p>Knowledge module not loaded.</p></div>';
			return;
		}
		$kdb        = BizCity_Knowledge_Database::instance();
		$characters = (array) $kdb->get_characters( array( 'limit' => 200 ) );
		$templates  = BizCity_CRM_Service_Templates::entitled();
		?>
		<div class="wrap">
			<h1>Twin Guru — Vai trò & Template phục vụ</h1>
			<p class="description">
				Mỗi Twin Guru chọn 1 <b>vai trò</b> (External = phục vụ khách bên ngoài qua FB/Zalo; Internal = trợ lý nội bộ; Both = cả hai)
				và 1 <b>template phục vụ</b> (Customer Service / Telesale / Page Inbox / Comment Reply / Seeding / Internal Assistant).
				Khi inbound CRM message khớp với Guru này, AI Replier sẽ tự inject persona prefix + style guide + ngưỡng độ dài tương ứng vào prompt.
			</p>
			<?php if ( isset( $_GET['updated'] ) ): ?>
				<div class="notice notice-<?php echo $_GET['updated'] === '1' ? 'success' : 'error'; ?> is-dismissible">
					<p><?php echo $_GET['updated'] === '1' ? 'Đã lưu cho character #' . (int) ( $_GET['cid'] ?? 0 ) : 'Lỗi khi lưu'; ?></p>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="margin-top:12px;">
				<thead>
					<tr>
						<th style="width:60px;">#</th>
						<th>Tên Guru</th>
						<th style="width:140px;">Vai trò</th>
						<th style="width:240px;">Template phục vụ</th>
						<th style="width:200px;">Cấu hình hiện tại</th>
						<th style="width:100px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $characters as $c ):
					$cid      = (int) $c->id;
					$resolved = BizCity_CRM_Service_Templates::resolve_for_character( $cid );
					$cur_role = $resolved['char_role'];
					$cur_tpl  = $resolved['slug'];
				?>
					<tr>
						<td><?php echo $cid; ?></td>
						<td><strong><?php echo esc_html( $c->name ); ?></strong>
							<div class="row-actions"><a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-character-edit&id=' . $cid ) ); ?>">Edit Guru →</a></div>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="frm-<?php echo $cid; ?>" style="display:contents;">
								<?php wp_nonce_field( 'bizcity_crm_guru_role_save' ); ?>
								<input type="hidden" name="action" value="bizcity_crm_guru_role_save" />
								<input type="hidden" name="character_id" value="<?php echo $cid; ?>" />
								<select name="crm_role">
									<option value="external" <?php selected( $cur_role, 'external' ); ?>>External (FB/Zalo)</option>
									<option value="internal" <?php selected( $cur_role, 'internal' ); ?>>Internal (nội bộ)</option>
									<option value="both"     <?php selected( $cur_role, 'both' ); ?>>Both</option>
								</select>
						</td>
						<td>
								<select name="crm_template">
								<?php foreach ( $templates as $slug => $tpl ): ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cur_tpl, $slug ); ?> <?php disabled( ! empty( $tpl['_premium'] ) && empty( $tpl['_entitled'] ) ); ?>>
										<?php echo esc_html( $tpl['label'] ); ?><?php echo ! empty( $tpl['_premium'] ) ? ( ! empty( $tpl['_entitled'] ) ? ' ✨ PREMIUM' : ' 🔒 PREMIUM (locked)' ) : ''; ?>
									</option>
								<?php endforeach; ?>
								</select>
						</td>
						<td>
							<?php $tpl = $resolved['template']; ?>
							<div style="font-size:11px;color:#555;line-height:1.5;">
								<div>📏 max ~<?php echo (int) $tpl['max_chars_target']; ?> chars</div>
								<div>🎯 <?php echo (int) $tpl['max_tokens_hint']; ?> tokens</div>
								<div>📡 <?php echo esc_html( implode( '/', (array) $tpl['allowed_channels'] ) ?: '—' ); ?></div>
								<div style="opacity:.6;">src: <?php echo esc_html( $resolved['source'] ); ?></div>
							</div>
						</td>
						<td>
							<button type="submit" class="button button-primary">Lưu</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:32px;">Templates đang có</h2>
			<table class="widefat striped">
				<thead><tr>
					<th>Slug</th><th>Label</th><th>Role</th><th>Persona prefix (rút gọn)</th><th>Max chars</th><th>Channels</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $templates as $slug => $t ): ?>
					<tr>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><?php echo esc_html( $t['label'] ); ?></td>
						<td><?php echo esc_html( $t['role_scope'] ); ?></td>
						<td style="font-size:11px;opacity:.8;"><?php echo esc_html( mb_substr( (string) $t['persona_prefix'], 0, 140 ) . ( mb_strlen( (string) $t['persona_prefix'] ) > 140 ? '…' : '' ) ); ?></td>
						<td><?php echo (int) $t['max_chars_target']; ?></td>
						<td><?php echo esc_html( implode( ', ', (array) $t['allowed_channels'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render two extra rows inside the core character-edit Overview table:
	 *   1. Vai trò Guru (External / Internal / Both)
	 *   2. Service Template (Customer Service / Telesale / …)
	 *
	 * Hooked on `bizcity_knowledge_character_meta_rows`.
	 */
	public static function render_inline_rows( $character ): void {
		if ( ! class_exists( 'BizCity_CRM_Service_Templates' ) ) { return; }
		$cid = is_object( $character ) ? (int) ( $character->id ?? 0 ) : 0;

		$cur_role    = 'both';
		$cur_tpl     = 'none';
		$cur_persona = '';
		$cur_style   = '';
		if ( $cid > 0 && is_object( $character ) ) {
			$resolved = BizCity_CRM_Service_Templates::resolve_for_character( $cid );
			$cur_role = $resolved['char_role'];
			$cur_tpl  = $resolved['slug'];

			// Read raw settings to preserve user-typed overlays even when empty.
			$settings = isset( $character->settings ) && $character->settings
				? ( is_array( $character->settings ) ? $character->settings : ( json_decode( (string) $character->settings, true ) ?: array() ) )
				: array();
			$cur_persona = (string) ( $settings[ BizCity_CRM_Service_Templates::META_KEY_CUSTOM_PERSONA ] ?? '' );
			$cur_style   = (string) ( $settings[ BizCity_CRM_Service_Templates::META_KEY_CUSTOM_STYLE ]   ?? '' );
		}
		$templates = BizCity_CRM_Service_Templates::entitled();
		?>
		<tr>
			<th colspan="2" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 12px;">
				<div style="font-size:13px;font-weight:600;color:#0a4b78;">📞 Twin CRM — Vai trò &amp; Template phục vụ</div>
				<div style="font-size:11px;font-weight:400;color:#50575e;margin-top:2px;">
					Chọn vai trò Guru + bấm 1 template làm điểm khởi đầu, sau đó tự do chỉnh sửa Persona &amp; Style guide bên dưới (override sẽ được lưu riêng cho Guru này).
				</div>
			</th>
		</tr>
		<tr>
			<th><label for="crm_role">Vai trò Guru</label></th>
			<td>
				<select name="crm_role" id="crm_role">
					<option value="external" <?php selected( $cur_role, 'external' ); ?>>External — phục vụ khách qua FB / Zalo / Telegram</option>
					<option value="internal" <?php selected( $cur_role, 'internal' ); ?>>Internal — trợ lý nội bộ (CRM web, twinchat)</option>
					<option value="both"     <?php selected( $cur_role, 'both' ); ?>>Both — cả hai</option>
				</select>
				<p class="description">Listener AI Replier dùng cờ này để lọc binding theo channel khi nhiều Guru cùng được attach.</p>
			</td>
		</tr>
		<tr>
			<th><label>Service Template</label></th>
			<td>
				<input type="hidden" name="crm_template" id="crm_template" value="<?php echo esc_attr( $cur_tpl ); ?>" />
				<div id="crm_template_chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
					<?php foreach ( $templates as $slug => $tpl ):
						$locked  = ! empty( $tpl['_premium'] ) && empty( $tpl['_entitled'] );
						$is_cur  = ( $slug === $cur_tpl );
						$badge   = ! empty( $tpl['_premium'] ) ? ( ! empty( $tpl['_entitled'] ) ? ' ✨' : ' 🔒' ) : '';
					?>
					<button type="button"
						class="button crm-tpl-chip<?php echo $is_cur ? ' crm-tpl-chip-active' : ''; ?>"
						data-slug="<?php echo esc_attr( $slug ); ?>"
						data-locked="<?php echo $locked ? '1' : '0'; ?>"
						<?php disabled( $locked ); ?>
						style="<?php echo $is_cur ? 'background:#2271b1;color:#fff;border-color:#135e96;' : ''; ?>border-radius:14px;padding:2px 12px;font-size:12px;">
						<?php echo esc_html( $tpl['label'] . $badge ); ?>
					</button>
					<?php endforeach; ?>
				</div>
				<div style="font-size:11px;color:#646970;margin-bottom:4px;">
					Đang chọn: <code id="crm_tpl_current"><?php echo esc_html( $cur_tpl ); ?></code>
					· <a href="#" id="crm_tpl_apply_preset" style="text-decoration:none;">↻ Khôi phục từ template</a>
					(sẽ ghi đè 2 ô bên dưới bằng nội dung gốc của template hiện chọn)
				</div>

				<label style="display:block;font-weight:600;margin-top:10px;">Persona prefix (vai trò &amp; mục tiêu)</label>
				<textarea name="crm_custom_persona" id="crm_custom_persona" rows="4" style="width:100%;font-family:inherit;font-size:12px;" placeholder="VD: Bạn là CSKH BizCity. Niềm nở, kiên nhẫn. Giải quyết đúng vấn đề khách hỏi rồi đề xuất bước tiếp theo."><?php echo esc_textarea( $cur_persona ); ?></textarea>
				<p class="description" style="margin-top:2px;">Để trống = dùng nguyên persona của template đang chọn.</p>

				<label style="display:block;font-weight:600;margin-top:10px;">Style guide (phong cách trả lời, độ dài, emoji…)</label>
				<textarea name="crm_custom_style" id="crm_custom_style" rows="6" style="width:100%;font-family:inherit;font-size:12px;" placeholder="VD:&#10;- Xưng 'em', gọi khách 'anh/chị'.&#10;- 4-8 câu, có cấu trúc rõ.&#10;- Khi cần chốt, hỏi 1 câu mở để khách chọn."><?php echo esc_textarea( $cur_style ); ?></textarea>
				<p class="description" style="margin-top:2px;">Để trống = dùng nguyên style guide của template. Áp dụng ngưỡng độ dài/token theo template (xem badge bên dưới).</p>

				<div id="crm_template_meta" style="margin-top:8px;padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-size:11px;color:#3c434a;line-height:1.55;">
					<div><b>Budget:</b> <span id="crm_tpl_budget">—</span></div>
					<div style="margin-top:2px;"><b>Channels:</b> <code id="crm_tpl_channels">—</code></div>
				</div>
			</td>
		</tr>
		<script>
		(function() {
			var TPLS    = <?php echo wp_json_encode( $templates ); ?>;
			var hidden  = document.getElementById( 'crm_template' );
			var chips   = document.querySelectorAll( '.crm-tpl-chip' );
			var label   = document.getElementById( 'crm_tpl_current' );
			var budget  = document.getElementById( 'crm_tpl_budget' );
			var chans   = document.getElementById( 'crm_tpl_channels' );
			var taP     = document.getElementById( 'crm_custom_persona' );
			var taS     = document.getElementById( 'crm_custom_style' );
			var apply   = document.getElementById( 'crm_tpl_apply_preset' );
			if ( ! hidden || ! chips.length ) { return; }

			function paintMeta() {
				var slug = hidden.value, t = TPLS[ slug ];
				label.textContent = slug;
				if ( ! t ) { budget.textContent = chans.textContent = '—'; return; }
				budget.textContent = '~' + ( t.max_chars_target || 0 ) + ' chars · ' + ( t.max_tokens_hint || 0 ) + ' tokens · chunk ≤ ' + ( t.per_chunk_max_chars || 0 );
				chans.textContent  = ( t.allowed_channels || [] ).join( ', ' ) || '—';
			}
			function setActive( slug ) {
				chips.forEach( function( b ) {
					var on = b.getAttribute( 'data-slug' ) === slug;
					b.classList.toggle( 'crm-tpl-chip-active', on );
					b.style.background    = on ? '#2271b1' : '';
					b.style.color         = on ? '#fff'    : '';
					b.style.borderColor   = on ? '#135e96' : '';
				} );
			}
			function fillFromTemplate( slug, force ) {
				var t = TPLS[ slug ]; if ( ! t ) { return; }
				if ( force || ! taP.value.trim() ) { taP.value = t.persona_prefix || ''; }
				if ( force || ! taS.value.trim() ) { taS.value = t.style_guide    || ''; }
			}
			chips.forEach( function( b ) {
				b.addEventListener( 'click', function() {
					if ( b.getAttribute( 'data-locked' ) === '1' ) { return; }
					var slug = b.getAttribute( 'data-slug' );
					hidden.value = slug;
					setActive( slug );
					paintMeta();
					// First-time pick (textareas empty) → auto-fill so user has a starting point.
					fillFromTemplate( slug, false );
				} );
			} );
			apply && apply.addEventListener( 'click', function( e ) {
				e.preventDefault();
				if ( ! confirm( 'Ghi đè Persona + Style guide bằng nội dung gốc của template "' + hidden.value + '" ?' ) ) { return; }
				fillFromTemplate( hidden.value, true );
			} );
			paintMeta();
		})();
		</script>
		<?php
	}

	/**
	 * Persist Role + Template after the core character-saved AJAX. Reads from
	 * raw $_POST (passed as $data). Skips when keys are absent so external
	 * callers (programmatic update_character) aren't disturbed.
	 *
	 * Hooked on `bizcity_knowledge_character_saved`.
	 */
	public static function persist_inline_save( int $character_id, array $data ): void {
		if ( $character_id <= 0 ) { return; }
		if ( ! isset( $data['crm_role'] ) && ! isset( $data['crm_template'] )
		  && ! isset( $data['crm_custom_persona'] ) && ! isset( $data['crm_custom_style'] ) ) { return; }
		$role     = isset( $data['crm_role'] )     ? (string) $data['crm_role']     : 'both';
		$template = isset( $data['crm_template'] ) ? (string) $data['crm_template'] : 'none';
		$extras   = array();
		if ( isset( $data['crm_custom_persona'] ) ) { $extras['custom_persona'] = wp_kses_post( (string) $data['crm_custom_persona'] ); }
		if ( isset( $data['crm_custom_style'] ) )   { $extras['custom_style']   = wp_kses_post( (string) $data['crm_custom_style'] ); }
		BizCity_CRM_Service_Templates::save_for_character( $character_id, $role, $template, $extras );
	}

	/**
	 * Persona Analytics admin page (PHASE-0.35-GURU-SERVICES §J).
	 * Pure-AJAX shell — JS calls /wp-json/bizcity-crm/v1/persona/analytics.
	 */
	public static function render_analytics(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1>Persona Analytics</h1>
			<p class="description">
				Số lượt AI auto-reply theo template trong N ngày gần nhất, cộng với độ trễ trung bình và độ dài trung bình của câu trả lời. Dữ liệu lấy từ <code>ai_metadata_json.steps[0].detail.service_template.slug</code>.
			</p>
			<p>
				<label>Khoảng thời gian: <select id="pa-days">
					<option value="1">1 ngày</option>
					<option value="7" selected>7 ngày</option>
					<option value="30">30 ngày</option>
					<option value="90">90 ngày</option>
				</select></label>
				<button type="button" class="button" id="pa-refresh">↻ Làm mới</button>
			</p>

			<h2>Tổng theo template</h2>
			<table class="widefat striped" id="pa-totals">
				<thead><tr>
					<th>Template</th><th>Số reply</th><th>Avg latency (ms)</th>
				</tr></thead>
				<tbody><tr><td colspan="3"><em>Loading…</em></td></tr></tbody>
			</table>

			<h2 style="margin-top:24px;">Chi tiết theo ngày</h2>
			<table class="widefat striped" id="pa-bydate">
				<thead><tr>
					<th>Ngày</th><th>Template</th><th>Số reply</th><th>Avg latency (ms)</th><th>Avg chars</th>
				</tr></thead>
				<tbody><tr><td colspan="5"><em>Loading…</em></td></tr></tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var root  = '<?php echo esc_js( esc_url_raw( rest_url( 'bizcity-crm/v1/persona/analytics' ) ) ); ?>';
			function load() {
				var days = document.getElementById('pa-days').value || 7;
				fetch( root + '?days=' + days, { headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' } } )
					.then(function(r){ return r.json(); })
					.then(function(j){
						var d = j && j.data ? j.data : { totals: [], by_day: [] };
						var t1 = document.querySelector('#pa-totals tbody');
						t1.innerHTML = (d.totals || []).map(function(r){
							return '<tr><td><code>'+r.template_slug+'</code></td><td>'+r.reply_count+'</td><td>'+r.avg_latency_ms+'</td></tr>';
						}).join('') || '<tr><td colspan="3"><em>(Chưa có dữ liệu)</em></td></tr>';
						var t2 = document.querySelector('#pa-bydate tbody');
						t2.innerHTML = (d.by_day || []).map(function(r){
							return '<tr><td>'+r.day+'</td><td><code>'+r.template_slug+'</code></td><td>'+r.reply_count+'</td><td>'+r.avg_latency_ms+'</td><td>'+r.avg_reply_chars+'</td></tr>';
						}).join('') || '<tr><td colspan="5"><em>(Chưa có dữ liệu)</em></td></tr>';
					})
					.catch(function(e){ alert('Lỗi: '+e); });
			}
			document.getElementById('pa-refresh').addEventListener('click', load);
			document.getElementById('pa-days').addEventListener('change', load);
			load();
		})();
		</script>
		<?php
	}

	/**
	 * Persona Sandbox admin page (PHASE-0.35-GURU-SERVICES §I).
	 * Choose a Guru + channel + sample message → preview the auto-reply
	 * with persona prefix applied, without dispatching anywhere.
	 */
	public static function render_sandbox(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'forbidden' ); }
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			echo '<div class="wrap"><h1>Persona Sandbox</h1><p>Knowledge module not loaded.</p></div>';
			return;
		}
		$nonce      = wp_create_nonce( 'wp_rest' );
		$kdb        = BizCity_Knowledge_Database::instance();
		$characters = (array) $kdb->get_characters( array( 'limit' => 200 ) );
		?>
		<div class="wrap">
			<h1>Persona Sandbox</h1>
			<p class="description">Mock 1 inbound message để xem AI Replier sẽ trả như thế nào với persona/template hiện tại của Guru — KHÔNG insert vào CRM, KHÔNG gửi ra channel.</p>

			<table class="form-table" style="max-width:900px;">
				<tr>
					<th>Guru (Character)</th>
					<td>
						<select id="ps-character" style="min-width:340px;">
							<?php foreach ( $characters as $c ): ?>
								<option value="<?php echo (int) $c->id; ?>"><?php echo esc_html( $c->name . ' (#' . $c->id . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>Channel</th>
					<td>
						<select id="ps-channel">
							<option value="facebook">facebook</option>
							<option value="zalo">zalo</option>
							<option value="telegram">telegram</option>
							<option value="web">web</option>
							<option value="crm">crm</option>
							<option value="twinchat">twinchat</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Notebook ID (optional)</th>
					<td><input type="number" id="ps-notebook" style="width:120px;" placeholder="auto"></td>
				</tr>
				<tr>
					<th>Tin nhắn của khách</th>
					<td>
						<textarea id="ps-message" rows="3" style="width:100%;max-width:700px;" placeholder="VD: Cho mình hỏi gói SaaS của shop có gì khác bản trial?"></textarea>
					</td>
				</tr>
				<tr><th></th><td><button type="button" class="button button-primary" id="ps-run">▶ Chạy thử</button></td></tr>
			</table>

			<div id="ps-result" style="margin-top:20px;display:none;">
				<h2>Kết quả</h2>
				<div id="ps-meta" style="background:#f6f7f7;border:1px solid #dcdcde;padding:10px;border-radius:4px;font-family:monospace;font-size:12px;white-space:pre-wrap;"></div>
				<h3 style="margin-top:14px;">Reply</h3>
				<div id="ps-reply" style="background:#fff;border:1px solid #2271b1;padding:12px;border-radius:4px;font-size:14px;line-height:1.55;white-space:pre-wrap;"></div>
				<h3 style="margin-top:14px;">Persona prefix đã inject</h3>
				<pre id="ps-prefix" style="background:#fefce8;border:1px solid #facc15;padding:10px;border-radius:4px;white-space:pre-wrap;font-size:11px;"></pre>
			</div>
		</div>
		<script>
		(function(){
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var root  = '<?php echo esc_js( esc_url_raw( rest_url( 'bizcity-crm/v1/sandbox/test-persona' ) ) ); ?>';
			document.getElementById('ps-run').addEventListener('click', function(){
				var btn = this; btn.disabled = true; btn.textContent = '⏳ Đang chạy…';
				fetch(root, {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
					body: JSON.stringify({
						character_id: parseInt(document.getElementById('ps-character').value, 10),
						channel_type: document.getElementById('ps-channel').value,
						notebook_id:  parseInt(document.getElementById('ps-notebook').value, 10) || 0,
						message:      document.getElementById('ps-message').value
					})
				}).then(function(r){ return r.json(); }).then(function(j){
					btn.disabled = false; btn.textContent = '▶ Chạy thử';
					if ( ! j.ok ) { alert('Lỗi: ' + (j.error || 'unknown')); return; }
					var d = j.data;
					document.getElementById('ps-result').style.display = 'block';
					document.getElementById('ps-meta').textContent =
						'Template: ' + (d.service && d.service.slug) + ' (' + (d.service && d.service.template && d.service.template.label) + ')\n' +
						'Source: ' + (d.service && d.service.source) + '  ·  Char role: ' + (d.service && d.service.char_role) + '\n' +
						'Notebook: #' + d.notebook_id + '  ·  Passages: ' + (d.rag && d.rag.passages || 0) + '  ·  Mode: ' + (d.rag && d.rag.mode || '—') + '\n' +
						'Provider: ' + d.provider + '  ·  Model: ' + d.model + '\n' +
						'Prompt: ' + d.prompt_chars + ' chars  ·  Reply: ' + d.reply_chars + ' chars' + (d.trimmed ? ' (TRIMMED)' : '');
					document.getElementById('ps-reply').textContent  = d.reply || '(empty)';
					document.getElementById('ps-prefix').textContent = d.persona_prefix || '(none)';
				}).catch(function(e){
					btn.disabled = false; btn.textContent = '▶ Chạy thử';
					alert('Lỗi: ' + e);
				});
			});
		})();
		</script>
		<?php
	}
}
