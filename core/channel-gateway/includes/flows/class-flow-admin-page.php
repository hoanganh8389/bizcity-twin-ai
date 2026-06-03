<?php
/**
 * BizCity Channel Gateway — Flows · Admin Page
 *
 * Backup wp-admin form UI for managing flows. Same UX as the legacy
 * `bizgpt-custom-flows/admin/manage-flows-page.php`, plus a new
 * "Trả lời trực tiếp / Sinh qua LLM" radio (`reply_mode` column).
 *
 * The React-based UI (Phase C) will live under `core/channel-gateway/frontend/`
 * and gradually replace this PHP form.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CG_Flow_Admin_Page {

	const MENU_SLUG = 'bizcity-cg-flows';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 99 );
	}

	public static function register_menu(): void {
		// Submenu under Channel Gateway hub (parent slug: bizchat-gateway).
		add_submenu_page(
			'bizchat-gateway',
			'CG · Flows (Kịch bản trả lời)',
			'Flows (Kịch bản)',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
		// Also keep legacy slug `bizgpt_flows` mapped for back-compat links.
		add_submenu_page(
			'',
			'CG · Flows (legacy alias)',
			'',
			'manage_options',
			'bizgpt_flows',
			array( __CLASS__, 'render' )
		);
	}

	private static function generate_prompt_from_shortcode( string $shortcode ): string {
		if ( ! preg_match( '/\[(\w+)(.*?)\]/', $shortcode, $m ) ) { return ''; }
		$type       = $m[1];
		$atts_block = trim( $m[2] );
		preg_match_all( '/(\w+)\s*=\s*"\{?(?:params\.)?(\w+)\}?"/', $atts_block, $am, PREG_SET_ORDER );
		$params = array_map( static fn( $r ) => $r[2], $am );

		$prompt  = "Bạn là hệ thống phân tích ý định khách hàng. Trả về đúng JSON như sau:\n\n";
		$prompt .= "{\n  \"type\": \"$type\",\n";
		if ( ! empty( $params ) ) {
			$prompt .= "  \"params\": {\n";
			foreach ( $params as $i => $p ) {
				$comma   = $i < count( $params ) - 1 ? ',' : '';
				$prompt .= "    \"$p\": \"...\"$comma\n";
			}
			$prompt .= "  }\n";
		} else {
			$prompt .= "  \"params\": {}\n";
		}
		$prompt .= "}\n\nLưu ý:\n- Chỉ trả kết quả JSON hợp lệ, không giải thích.";
		return $prompt;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		global $wpdb;
		$table = BizCity_CG_Flow_Installer::table();

		// Ensure destination table exists (dbDelta ADD-only — idempotent).
		BizCity_CG_Flow_Installer::ensure_table();

		// 1-shot migration attempt from legacy plugin table.
		$mig = BizCity_CG_Flow_Installer::maybe_migrate_from_legacy();
		if ( ! empty( $mig['copied'] ) ) {
			$reason   = (string) ( $mig['reason'] ?? '' );
			$src_desc = ( strpos( $reason, 'interim' ) !== false || strpos( $reason, 'renamed' ) !== false )
				? 'interim <code>wp_bizcity_cg_flows</code> (đã RENAME thành <code>wp_bizcity_crm_flows</code>, drop bảng cũ)'
				: 'legacy <code>wp_bizgpt_custom_flows</code> (copy preserving id)';
			$ver = ( ! empty( $mig['from'] ) && ! empty( $mig['to'] ) ) ? ' · DB ' . esc_html( $mig['from'] ) . ' → ' . esc_html( $mig['to'] ) : '';
			echo '<div class="notice notice-info is-dismissible"><p><b>Đã migrate</b> ' . (int) $mig['copied'] . ' kịch bản từ ' . $src_desc . $ver . '.</p></div>';
		} elseif ( ! empty( $mig['reason'] ) && in_array( $mig['reason'], array( 'dropped_interim_empty', 'dropped_interim_dst_populated' ), true ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><b>Cleanup:</b> đã drop bảng interim <code>wp_bizcity_cg_flows</code> (anti-duplicate R-DCL-NAME).</p></div>';
		} elseif ( ! empty( $mig['reason'] ) && strpos( (string) $mig['reason'], 'failed' ) !== false ) {
			echo '<div class="notice notice-error"><p><b>Migration FAIL:</b> ' . esc_html( (string) $mig['reason'] ) . '</p></div>';
		}

		self::print_styles();

		$id     = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$flow   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ) ) : null;
		$config = ( $flow && ! empty( $flow->action_config ) )
			? ( json_decode( $flow->action_config, true )['attributes'] ?? array() )
			: array();

		// === Save ===
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['save_flow'] ) ) {
			check_admin_referer( 'bizcity_cg_flow_save' );
			$mid       = (int) ( $_POST['flow_id'] ?? 0 );
			$message   = mb_strtolower( sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) ), 'UTF-8' );
			$shortcode = sanitize_textarea_field( wp_unslash( $_POST['shortcode'] ?? '' ) );
			$atype     = in_array( ( $_POST['action_type'] ?? '' ), array( 'run_shortcode', 'send_message' ), true )
				? $_POST['action_type'] : 'run_shortcode';
			$reply_mode = in_array( ( $_POST['reply_mode'] ?? '' ), array( 'direct', 'llm' ), true )
				? $_POST['reply_mode'] : 'direct';
			$delay_only = empty( $_POST['delay_only'] ) ? 0 : 1;

			$attr_keys    = (array) ( $_POST['attr_key'] ?? array() );
			$attr_prompts = (array) ( $_POST['attr_prompt'] ?? array() );
			$attrs = array();
			foreach ( $attr_keys as $i => $key ) {
				$k = sanitize_text_field( trim( (string) $key ) );
				$p = sanitize_textarea_field( trim( (string) ( $attr_prompts[ $i ] ?? '' ) ) );
				if ( '' !== $k ) { $attrs[] = array( 'key' => $k, 'prompt' => $p ); }
			}
			$action_config = wp_json_encode( array( 'attributes' => $attrs ), JSON_UNESCAPED_UNICODE );

			// Prompt:
			//   send_message + reply_mode=direct → giữ nguyên text user nhập ở textarea Prompt.
			//   send_message + reply_mode=llm    → directive cho LLM (rephrase shortcode → reply text).
			//   run_shortcode                    → auto-generate intent parser prompt từ shortcode.
			$prompt_in = isset( $_POST['Prompt'] ) ? wp_unslash( $_POST['Prompt'] ) : '';
			if ( 'send_message' === $atype ) {
				if ( '' !== trim( $prompt_in ) ) {
					$prompt = $prompt_in;
				} elseif ( 'llm' === $reply_mode ) {
					$prompt = 'Hãy trả lời trong phạm vi không quá 200 chữ về những thông tin liên quan đến vấn đề ' . $shortcode . ' và những gì bạn có từ website này là ok.';
				} else {
					$prompt = $shortcode;
				}
			} else {
				$prompt = '' !== trim( $prompt_in ) ? $prompt_in : self::generate_prompt_from_shortcode( $shortcode );
				foreach ( $attrs as $attr ) {
					if ( ! empty( $attr['key'] ) && ! empty( $attr['prompt'] ) ) {
						$prompt .= "\n- Nếu thiếu tham số \"{$attr['key']}\", hãy hỏi: \"{$attr['prompt']}\"";
					}
				}
			}

			$data = array(
				'message'           => $message,
				'message_khong_dau' => BizCity_CG_Flow_Handler::strip_accents( $message ),
				'shortcode'         => $shortcode,
				'action_type'       => $atype,
				'action_config'     => $action_config,
				'prompt'            => $prompt,
				'reply_mode'        => $reply_mode,
				'updated_at'        => current_time( 'mysql' ),
				'reminder_delay'    => (int) ( $_POST['reminder_delay'] ?? 0 ),
				'reminder_unit'     => sanitize_text_field( $_POST['reminder_unit'] ?? 'minutes' ),
				'reminder_text'     => sanitize_textarea_field( wp_unslash( $_POST['reminder_text'] ?? '' ) ),
				'delay_only'        => $delay_only,
			);

			wp_cache_delete( 'flow_row_' . $mid, 'bizcity_crm_flows' );

			if ( $mid ) {
				$wpdb->update( $table, $data, array( 'id' => $mid ) );
				echo '<div class="notice notice-success is-dismissible"><p><b>Đã cập nhật</b> kịch bản.</p></div>';
			} else {
				$wpdb->insert( $table, $data );
				echo '<div class="notice notice-success is-dismissible"><p><b>Đã thêm</b> kịch bản mới.</p></div>';
			}
			$id     = $mid ?: (int) $wpdb->insert_id;
			$flow   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ) ) : null;
			$config = ( $flow && ! empty( $flow->action_config ) )
				? ( json_decode( $flow->action_config, true )['attributes'] ?? array() )
				: array();
		}

		// === Delete ===
		if ( isset( $_GET['delete'] ) && is_numeric( $_GET['delete'] ) ) {
			check_admin_referer( 'bizcity_cg_flow_delete_' . (int) $_GET['delete'] );
			$wpdb->delete( $table, array( 'id' => (int) $_GET['delete'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>✅ Đã xoá kịch bản.</p></div>';
		}

		$flows   = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" );
		$page_id = get_option( 'messenger_page_id' );

		self::render_layout( $flow, $config, $flows, $page_id );
		self::print_scripts();
	}

	private static function render_layout( $flow, array $config, array $flows, $page_id ): void {
		echo '<div class="wrap"><div class="bc-wrap">';
		echo '<div class="bc-head"><div><h1>Channel Gateway · Kịch bản trả lời (Flows)</h1>
			<div class="bc-sub">Đã port từ <code>bizgpt-custom-flows</code> sang <code>core/channel-gateway/includes/flows/</code>. Sau khi React UI sẵn sàng (Phase C), trang này sẽ chuyển sang chế độ deprecated.</div></div></div>';
		echo '<div class="bc-grid">';

		/* ===== LEFT: Form ===== */
		echo '<div class="bc-card"><h2 class="bc-badge"><span class="bc-dot green"></span>' . ( $flow ? 'Sửa kịch bản' : 'Thêm kịch bản mới' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'bizcity_cg_flow_save' );
		echo '<input type="hidden" name="flow_id" value="' . esc_attr( $flow->id ?? 0 ) . '">';

		echo '<div class="bc-field"><label>Tình huống (khi khách nhắn tin)</label>
			<input class="bc-input" type="text" name="message" required value="' . esc_attr( $flow->message ?? '' ) . '">
			<small>Tip: nhập "ý định" ngắn: <span class="bc-kbd">giá bán</span>, <span class="bc-kbd">freeship</span>...</small></div>';

		echo '<div class="bc-field"><label>Loại hành động</label>
			<select class="bc-select" id="action_type" name="action_type">
				<option value="run_shortcode" ' . selected( ( $flow->action_type ?? '' ), 'run_shortcode', false ) . '>Chạy shortcode</option>
				<option value="send_message" ' . selected( ( $flow->action_type ?? '' ), 'send_message', false ) . '>Gửi tin nhắn</option>
			</select></div>';

		// reply_mode (chỉ hiển thị khi send_message — JS toggle)
		$rm = (string) ( $flow->reply_mode ?? 'direct' );
		echo '<div class="bc-field bc-reply-mode" style="display:' . ( 'send_message' === ( $flow->action_type ?? '' ) ? 'block' : 'none' ) . '">
			<label>Cách trả lời cho khách</label>
			<label style="font-weight:400;display:block;margin:6px 0">
				<input type="radio" name="reply_mode" value="direct" ' . checked( 'direct', $rm, false ) . '> Trả lời trực tiếp văn bản trong "Prompt" bên dưới (không gọi LLM)
			</label>
			<label style="font-weight:400;display:block">
				<input type="radio" name="reply_mode" value="llm" ' . checked( 'llm', $rm, false ) . '> Sinh tin nhắn qua LLM (dùng "Prompt" làm chỉ thị cho AI)
			</label></div>';

		echo '<div class="bc-field"><label>Nội dung / Shortcode</label>
			<textarea class="bc-textarea" name="shortcode" required placeholder="[tim_bai_viet]">' . esc_textarea( $flow->shortcode ?? '' ) . '</textarea>
			<div class="bc-help">
				<p><b>Biến link:</b>
					<code class="bc-kbd bc-var" onclick="bcInsertShortcode(this.textContent)">{{client_id}}</code>
					<code class="bc-kbd bc-var" onclick="bcInsertShortcode(this.textContent)">{{client_name}}</code>
					<code class="bc-kbd bc-var" onclick="bcInsertShortcode(this.textContent)">{{page_id}}</code>
				</p>
				<p><b>Shortcode có sẵn</b> <small>(nhấn để chèn)</small>:<br>';
		foreach ( array( 'tim_san_pham', 'tim_bai_viet', 'tim_chuong_trinh_uu_dai', 'kiem_tra_diem', 'doi_diem', 'dat_hang', 'tin_tuc_moi_nhat' ) as $sc ) {
			echo '<code class="bc-kbd bc-sc" onclick="bcInsertShortcode(this.textContent)">[' . esc_html( $sc ) . ']</code> ';
		}
		echo '</p></div></div>';

		echo '<div class="bc-divider"></div><h3 style="margin:0 0 8px">Nhắc lại (Reminder)</h3>
			<div class="bc-row">
				<div class="bc-field" style="flex:1;min-width:160px;margin:0"><label>Sau</label>
					<input type="number" name="reminder_delay" value="' . esc_attr( $flow->reminder_delay ?? 0 ) . '" min="0" style="width:120px;border-radius:10px"></div>
				<div class="bc-field" style="flex:1;min-width:160px;margin:0"><label>Đơn vị</label>
					<select class="bc-select" name="reminder_unit" style="max-width:240px">
						<option value="minutes" ' . selected( ( $flow->reminder_unit ?? '' ), 'minutes', false ) . '>phút</option>
						<option value="hours" ' . selected( ( $flow->reminder_unit ?? '' ), 'hours', false ) . '>giờ</option>
						<option value="days" ' . selected( ( $flow->reminder_unit ?? '' ), 'days', false ) . '>ngày</option>
					</select></div>
			</div>
			<div class="bc-field"><label>Nội dung nhắc lại</label>
				<textarea class="bc-textarea" name="reminder_text">' . esc_textarea( $flow->reminder_text ?? '' ) . '</textarea></div>
			<div class="bc-field"><label><input type="checkbox" name="delay_only" value="1" ' . checked( ! empty( $flow->delay_only ), true, false ) . '> Không trả lời ngay, chỉ gửi sau thời gian chờ</label></div>';

		echo '<div class="bc-divider"></div><div class="bc-field"><label>Prompt cho AI</label>
			<div class="bc-note"><b>Gợi ý:</b> Có thể để trống — hệ thống tự sinh từ shortcode + attrs.</div>
			<textarea class="bc-textarea" name="Prompt" placeholder="(tự sinh)">' . esc_textarea( $flow->prompt ?? '' ) . '</textarea></div>';

		echo '<div class="bc-divider"></div><h3 style="margin:0 0 8px">Thuộc tính cần thiết cho AI</h3>
			<div class="bc-field"><div id="attributes-wrapper">';
		if ( ! empty( $config ) ) {
			foreach ( $config as $a ) {
				$k = esc_attr( $a['key'] ?? '' );
				$p = esc_textarea( $a['prompt'] ?? '' );
				echo '<div class="attribute-row"><input type="text" name="attr_key[]" placeholder="Key" class="bc-input" value="' . $k . '"><textarea name="attr_prompt[]" class="bc-textarea" placeholder="Câu hỏi khi thiếu">' . $p . '</textarea><button type="button" class="remove-attr">×</button></div>';
			}
		} else {
			echo '<div class="attribute-row"><input type="text" name="attr_key[]" placeholder="Key" class="bc-input"><textarea name="attr_prompt[]" class="bc-textarea" placeholder="Câu hỏi khi thiếu"></textarea><button type="button" class="remove-attr">×</button></div>';
		}
		echo '</div><p style="margin:10px 0 0"><button type="button" class="button" id="add-attr">+ Thêm thuộc tính</button></p></div>';

		echo '<div class="bc-row" style="margin-top:14px"><button type="submit" class="button button-primary" name="save_flow">Lưu kịch bản</button>
			<span class="bc-pill">' . ( $flow ? 'Đang sửa ID: ' . (int) $flow->id : 'Thêm mới' ) . '</span></div></form></div>';

		/* ===== RIGHT: List ===== */
		echo '<div><div class="bc-card"><h2 class="bc-badge"><span class="bc-dot blue"></span>Danh sách kịch bản (' . count( $flows ) . ')</h2><div class="bc-table-wrap"><table class="widefat striped"><thead><tr><th width="5%">ID</th><th width="18%">Tình huống</th><th>Phản hồi</th><th width="10%">Action</th><th width="10%">Reply mode</th><th>Link</th><th width="10%">Thao tác</th></tr></thead><tbody>';
		if ( $flows ) {
			foreach ( $flows as $f ) {
				$edit_url = add_query_arg( array( 'page' => self::MENU_SLUG, 'edit' => $f->id ), admin_url( 'admin.php' ) );
				$del_url  = wp_nonce_url(
					add_query_arg( array( 'page' => self::MENU_SLUG, 'delete' => $f->id ), admin_url( 'admin.php' ) ),
					'bizcity_cg_flow_delete_' . (int) $f->id
				);
				echo '<tr><td>' . (int) $f->id . '</td><td>' . esc_html( $f->message ) . '</td><td><code class="bc-kbd">' . esc_html( mb_strimwidth( (string) $f->shortcode, 0, 80, '…' ) ) . '</code></td><td><span class="bc-pill">' . esc_html( $f->action_type ) . '</span></td><td><span class="bc-pill">' . esc_html( $f->reply_mode ?? 'direct' ) . '</span></td>';
				echo '<td>';
				// Use codec so token format matches legacy waic_twf (`twf_encrypt_chat_id`) when present;
				// falls back to internal AES (compatible wire format) when legacy plugin archived.
				if ( $page_id ) {
					$link = BizCity_CG_Flow_Ref_Codec::build_messenger_link( (string) $page_id, (int) $f->id );
					if ( '' !== $link ) {
						echo '<div class="bc-linkbox"><input type="text" readonly value="' . esc_attr( $link ) . '" onclick="this.select();" style="width:260px"><a href="' . esc_url( $link ) . '" target="_blank" class="button">Mở</a></div>';
					} else {
						echo '<span class="bc-muted">-</span>';
					}
				} else {
					echo '<span class="bc-muted">-</span>';
				}
				echo '</td><td><a href="' . esc_url( $edit_url ) . '" class="button">Sửa</a> <a href="' . esc_url( $del_url ) . '" class="button" onclick="return confirm(\'Xoá?\')">Xoá</a></td></tr>';
			}
		} else {
			echo '<tr><td colspan="7" style="text-align:center">Chưa có kịch bản nào.</td></tr>';
		}
		echo '</tbody></table></div></div></div>';
		echo '</div></div></div>';
	}

	private static function print_styles(): void {
		echo '<style>
		.bc-wrap{max-width:100%}
		.bc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin:8px 0 14px}
		.bc-head h1{margin:0;font-size:20px;line-height:1.25}
		.bc-sub{margin-top:6px;color:#6b7280}
		.bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:14px}
		.bc-badge{display:inline-flex;align-items:center;gap:8px;font-weight:800}
		.bc-dot{width:10px;height:10px;border-radius:999px;display:inline-block;background:#64748b}
		.bc-dot.blue{background:#1977f2}.bc-dot.green{background:#10b981}.bc-dot.amber{background:#f59e0b}
		.bc-help{background:#f9fafb;border:1px dashed #e5e7eb;border-radius:12px;padding:12px;margin:10px 0 0}
		.bc-note{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:12px;margin-top:6px}
		.bc-divider{height:1px;background:#e5e7eb;margin:14px 0}
		.bc-grid{display:grid;grid-template-columns:35% 65%;gap:16px;align-items:start}
		@media (max-width:1280px){.bc-grid{grid-template-columns:1fr}}
		.bc-table-wrap{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
		.bc-kbd{font-family:ui-monospace,Menlo,Monaco,Consolas,monospace}
		.bc-kbd.bc-sc,.bc-kbd.bc-var{cursor:pointer;display:inline-block;margin:2px 3px;padding:2px 7px;border-radius:6px;border:1px solid #c7d2fe;background:#eef2ff;color:#3730a3}
		.bc-kbd.bc-sc:hover,.bc-kbd.bc-var:hover{background:#c7d2fe}
		.bc-pill{display:inline-flex;align-items:center;font-size:12px;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;color:#334155}
		.bc-field{margin:12px 0}.bc-field label{display:block;font-weight:800;margin-bottom:6px}
		.bc-input,.bc-select,.bc-textarea{width:100%;max-width:100%;border-radius:10px}
		.bc-textarea{min-height:110px}
		.bc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
		.bc-muted{color:#6b7280}
		.bc-linkbox{display:flex;align-items:flex-start;gap:8px;flex-wrap:wrap}
		.attribute-row{display:flex;gap:8px;margin-bottom:8px;align-items:flex-start}
		.attribute-row input{width:22%}.attribute-row textarea{flex:1;min-height:64px}
		.attribute-row .remove-attr{background:#fff;border:1px solid #e5e7eb;border-radius:10px;color:#b91c1c;font-size:18px;cursor:pointer;line-height:1;padding:6px 10px}
		</style>';
	}

	private static function print_scripts(): void {
		?>
		<script>
		(function(){
			var at = document.getElementById('action_type');
			var rm = document.querySelector('.bc-reply-mode');
			function sync(){ if(rm) rm.style.display = (at && at.value === 'send_message') ? 'block' : 'none'; }
			if(at){ at.addEventListener('change', sync); sync(); }
			var addBtn = document.getElementById('add-attr');
			var wrap   = document.getElementById('attributes-wrapper');
			if(addBtn && wrap){
				addBtn.addEventListener('click', function(){
					var d = document.createElement('div');
					d.className = 'attribute-row';
					d.innerHTML = '<input type="text" name="attr_key[]" placeholder="Key" class="bc-input"><textarea name="attr_prompt[]" class="bc-textarea" placeholder="Câu hỏi khi thiếu"></textarea><button type="button" class="remove-attr">×</button>';
					wrap.appendChild(d);
				});
			}
			document.addEventListener('click', function(e){
				if(e.target && e.target.classList && e.target.classList.contains('remove-attr')){
					var row = e.target.closest('.attribute-row'); if(row) row.remove();
				}
			});
			window.bcInsertShortcode = function(text){
				var ta = document.querySelector('textarea[name="shortcode"]'); if(!ta) return;
				var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
				ta.value = ta.value.slice(0,s) + text + ta.value.slice(e);
				ta.focus(); ta.selectionEnd = s + text.length;
			};
		})();
		</script>
		<?php
	}
}
