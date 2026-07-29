<?php
/**
 * BizCity Zalo Hotline — Admin page (rendered inside Channel Gateway hub).
 *
 * Ports the legacy mu-plugin renderers from
 *   wp-content/mu-plugins/bizcity-admin-hook/includes/flows/shortcode_login.php
 *   wp-content/mu-plugins/bizcity-admin-hook/includes/class-bizcity-adminhook-adminmenu.php
 * which previously lived at:
 *   • admin.php?page=zalo-users-admin   → twf_zalo_users_admin_page()
 *   • admin.php?page=zalo-guider        → twf_telegram_command_widget_content()
 *   • admin.php?page=zalo-video-guider  → bizcity_guides_admin_page()
 *
 * They are now consolidated into a single hub sub-page:
 *   admin.php?page=bizchat-gateway&group=channels&sub=zalo-hotline
 *
 * Tables are GLOBAL (multisite-shared, no `wp_` prefix): `global_user_admin`.
 * The legacy code uses `$globaldb` (a custom wpdb pointing at the global DB);
 * we fall back to `$wpdb` when `$globaldb` is unavailable.
 *
 * @package BizCity\AdminHookZalo
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bizcity_zh_db' ) ) {
	/**
	 * Resolve the wpdb instance that owns the `global_*` tables.
	 *
	 * @return wpdb
	 */
	function bizcity_zh_db() {
		global $globaldb, $wpdb;
		return ( isset( $globaldb ) && $globaldb instanceof wpdb ) ? $globaldb : $wpdb;
	}
}

/* ─────────────────────────────────────────────────────────────────────
 *  twf_telegram_command_widget_content()
 *  Ported verbatim from mu-plugins/bizcity-admin-hook/includes/flows/shortcode_login.php
 * ───────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'twf_telegram_command_widget_content' ) ) {
	function twf_telegram_command_widget_content() {
		$zalo_sdt  = '0562608899';
		$zalo_link = 'https://zalo.me/' . preg_replace( '/^0/', '', $zalo_sdt );

		$yt_url   = 'https://youtu.be/7VQVT31Krp4?si=5gcNviud-7l_gKZl';
		$yt_embed = 'https://www.youtube.com/embed/7VQVT31Krp4';
		?>
		<style>
			.twf-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 6px 18px rgba(0,0,0,.06);margin-top:16px;}
			.twf-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
			.twf-title h2{margin:0;font-size:18px;}
			.twf-pill{padding:4px 10px;border-radius:999px;border:1px solid #dbeafe;background:#eff6ff;color:#1d4ed8;font-weight:600;font-size:12px;}
			.twf-muted{color:#6b7280;font-size:13px;}
			.twf-layout{display:grid;grid-template-columns:1.3fr .7fr;gap:16px;margin-top:10px;}
			@media (max-width:1100px){.twf-layout{grid-template-columns:1fr;}}
			.twf-box{border:1px solid #eef2f7;border-radius:14px;padding:14px 16px;background:#fff;}
			.twf-steps{padding-left:18px;margin:0;}
			.twf-steps li{margin:12px 0;line-height:1.7;}
			.twf-box code{background:#f3f4f6;padding:2px 6px;border-radius:8px;font-size:12.5px;}
			.twf-inline{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px;}
			.twf-btn{padding:8px 12px;border-radius:12px;border:1px solid #d1d5db;background:#fff;text-decoration:none;font-weight:600;color:#111827;}
			.twf-btn-primary{border-color:#a7f3d0;background:#ecfdf5;color:#065f46;}
			.twf-callout{margin-top:14px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:14px;padding:12px 14px;}
			.twf-video{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#000;}
			.twf-video iframe{width:100%;aspect-ratio:16/9;border:0;display:block;}
			.twf-video-note{margin-top:10px;font-size:13px;color:#6b7280;}
		</style>

		<div class="twf-card">
			<div class="twf-title">
				<h2>Hướng dẫn kết nối &amp; ra lệnh quản trị với BizGPT</h2>
				<span class="twf-pill">qua Zalo</span>
			</div>

			<p class="twf-muted">Xác thực quyền quản trị website và điều khiển mọi thứ chỉ bằng tin nhắn.</p>

			<div class="twf-layout">
				<div class="twf-box">
					<ol class="twf-steps">
						<li>
							<b>Bước 1:</b> Nhắn:
							<code>tôi muốn quản trị website [tên web]</code>
							<div class="twf-inline">
								<a class="twf-btn twf-btn-primary" href="<?php echo esc_url( $zalo_link ); ?>" target="_blank">
									Nhắn Zalo <?php echo esc_html( $zalo_sdt ); ?>
								</a>
							</div>
						</li>
						<li><b>Bước 2:</b> Nhấn link xác thực dạng: <code>https://[domain]/telegram-login/?zid=...</code></li>
						<li>
							<b>Bước 3:</b> Ra lệnh quản trị:
							<ul>
								<li><code>Đăng sản phẩm: Tên | Giá | Mô tả</code></li>
								<li><code>Viết bài về [chủ đề]</code></li>
								<li><code>Đăng lên facebook [chủ đề]</code></li>
								<li><code>Thống kê doanh số tuần này</code></li>
								<li><code>Danh sách bài viết / sản phẩm</code></li>
								<li><code>HDSD</code> / <code>hướng dẫn</code></li>
							</ul>
						</li>
						<li><b>Bước 4:</b> Nếu quản lý nhiều web: <code>liệt kê danh sách web tôi quản lý</code></li>
					</ol>

					<div class="twf-callout">
						<b>TIP:</b> Nếu thấy "không ra lệnh được" → nhắn lại
						<code>HDSD</code> hoặc <code>tôi muốn quản trị website [tên web]</code>
					</div>
				</div>

				<div class="twf-box">
					<h3 style="margin-top:0">🎥 Video hướng dẫn</h3>
					<div class="twf-video">
						<iframe src="<?php echo esc_url( $yt_embed ); ?>"
						        title="Hướng dẫn quản trị website qua Zalo"
						        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
						        allowfullscreen></iframe>
					</div>
					<div class="twf-video-note">
						Không xem được video?
						<a href="<?php echo esc_url( $yt_url ); ?>" target="_blank">Xem trực tiếp trên YouTube</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

// Re-register the legacy dashboard widget so it still appears on wp-admin/index.php.
add_action( 'wp_dashboard_setup', function () {
	if ( function_exists( 'twf_telegram_command_widget_content' ) ) {
		wp_add_dashboard_widget(
			'twf_telegram_command_widget',
			'🪄 Hướng dẫn sử dụng Trợ lý BizGPT qua Zalo',
			'twf_telegram_command_widget_content'
		);
	}
} );

/* ─────────────────────────────────────────────────────────────────────
 *  twf_zalo_users_admin_page()
 *  Ported from legacy mu-plugin. Lists `global_user_admin` mappings for
 *  the current blog. Supports inline edit (client_id, user_level) + delete.
 * ───────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'twf_zalo_users_admin_page' ) ) {
	function twf_zalo_users_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		$db      = bizcity_zh_db();
		$blog_id = get_current_blog_id();
		$table   = 'global_user_admin'; // global table — no prefix.

		// Xử lý xóa (GET, with nonce check).
		if ( isset( $_GET['remove_zalo'] ) && $_GET['remove_zalo'] ) {
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'zh_remove_' . (int) $_GET['remove_zalo'] ) ) {
				$id = (int) $_GET['remove_zalo'];
				$db->delete( $table, [ 'id' => $id ], [ '%d' ] );
				echo '<div class="updated notice"><p>Đã xóa kết nối Zalo cho user ID: ' . esc_html( (string) $id ) . '</p></div>';
			}
		}

		// Xử lý sửa (POST, with nonce check).
		if ( isset( $_POST['save_zalo_user'] ) ) {
			if ( isset( $_POST['_wpnonce_zh'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_zh'] ) ), 'zh_save_user' ) ) {
				$id         = (int) ( $_POST['id'] ?? 0 );
				$client_id  = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
				$user_level = sanitize_text_field( wp_unslash( $_POST['user_level'] ?? '' ) );
				$db->update(
					$table,
					[
						'client_id'  => $client_id,
						'user_level' => $user_level,
						'updated_at' => current_time( 'mysql' ),
					],
					[ 'id' => $id ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
				echo '<div class="updated notice"><p>Đã cập nhật thông tin user ID: ' . esc_html( (string) $id ) . '</p></div>';
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$users = $db->get_results( $db->prepare(
			"SELECT * FROM {$table} WHERE blog_id = %d AND client_id != '' ORDER BY id DESC",
			$blog_id
		) );

		$base_url = admin_url( 'admin.php?page=bizchat-gateway&group=channels&sub=zalo-hotline' );
		?>
		<div class="wrap">
			<h2>👥 Danh sách User kết nối Zalo (blog #<?php echo (int) $blog_id; ?>)</h2>
			<?php if ( empty( $users ) ) : ?>
				<div class="notice notice-info inline"><p>Chưa có user nào trong blog này kết nối Zalo.</p></div>
			<?php else : ?>
				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th>ID</th>
							<th>User ID</th>
							<th>User Login</th>
							<th>Client ID (Zalo)</th>
							<th>Domain</th>
							<th>User Level</th>
							<th>Created</th>
							<th>Updated</th>
							<th>Thao tác</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $users as $user ) :
							$user_data  = get_userdata( (int) $user->user_id );
							$user_login = $user_data ? $user_data->user_login : '';
							$remove_url = wp_nonce_url(
								add_query_arg( 'remove_zalo', (int) $user->id, $base_url ),
								'zh_remove_' . (int) $user->id
							);
							?>
							<tr>
								<td><?php echo (int) $user->id; ?></td>
								<td><?php echo (int) $user->user_id; ?></td>
								<td><?php echo esc_html( (string) $user_login ); ?></td>
								<td>
									<form method="POST" style="display:inline;">
										<?php wp_nonce_field( 'zh_save_user', '_wpnonce_zh' ); ?>
										<input type="hidden" name="id" value="<?php echo esc_attr( (string) $user->id ); ?>">
										<input type="text" name="client_id" value="<?php echo esc_attr( (string) $user->client_id ); ?>" size="18">
								</td>
								<td><?php echo esc_html( (string) $user->domain ); ?></td>
								<td>
										<input type="text" name="user_level" value="<?php echo esc_attr( (string) $user->user_level ); ?>" size="8">
										<button type="submit" name="save_zalo_user" class="button">Lưu</button>
									</form>
								</td>
								<td><?php echo esc_html( (string) $user->created_at ); ?></td>
								<td><?php echo esc_html( (string) $user->updated_at ); ?></td>
								<td>
									<a href="<?php echo esc_url( $remove_url ); ?>" class="button"
									   onclick="return confirm('Xóa kết nối Zalo của user này?');">Xóa</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		twf_telegram_command_widget_content();
	}
}

/* ─────────────────────────────────────────────────────────────────────
 *  bizcity_youtube_embed_url()
 *  Ported from legacy. Converts youtu.be / watch?v= URLs to /embed/.
 * ───────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'bizcity_youtube_embed_url' ) ) {
	function bizcity_youtube_embed_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		$id   = '';

		if ( strpos( $host, 'youtu.be' ) !== false ) {
			$path = $parts['path'] ?? '';
			$id   = trim( $path, '/' );
		}
		if ( ! $id && ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'm.youtube.com' ) !== false ) ) {
			$query = [];
			parse_str( $parts['query'] ?? '', $query );
			if ( ! empty( $query['v'] ) ) {
				$id = $query['v'];
			}
		}
		if ( ! $id && ! empty( $parts['path'] ) && strpos( $parts['path'], '/embed/' ) !== false ) {
			$id = trim( str_replace( '/embed/', '', $parts['path'] ), '/' );
		}
		$id = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $id );
		if ( ! $id ) {
			return '';
		}
		return 'https://www.youtube.com/embed/' . $id;
	}
}

/* ─────────────────────────────────────────────────────────────────────
 *  bizcity_guides_admin_page()
 *  Ported verbatim. Hard-coded video grid — no admin config needed.
 * ───────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'bizcity_guides_admin_page' ) ) {
	function bizcity_guides_admin_page() {
		$videos = [
			[ 'title' => 'Ra lệnh cho AI đăng bài lên nhiều fanpage',                                                  'url' => 'https://youtu.be/VdOdSuwlhCY?si=OL4aDuqFH3byQNpj', 'tag' => 'Facebook' ],
			[ 'title' => 'Ra lệnh cho AI tạo đơn hàng',                                                                'url' => 'https://youtu.be/jHmmUkQiyMQ?si=DccItjqg3fOaqlE1', 'tag' => 'Orders' ],
			[ 'title' => 'Ra lệnh cho AI đăng sản phẩm mới lên web',                                                   'url' => 'https://youtu.be/0tN64lcUzvI?si=fR78uO6bzA8hfsfA', 'tag' => 'Products' ],
			[ 'title' => 'Ra lệnh cho AI tạo bài viết mới theo chủ đề và đăng lên web',                                'url' => 'https://youtu.be/-FkE5i9YQrc?si=X2jKjYG6-AHhGfcU', 'tag' => 'Posts' ],
			[ 'title' => 'Ra lệnh cho AI viết bài, tạo ảnh, đăng lên nhiều website cùng lúc (một chủ quản lý)',         'url' => 'https://youtu.be/Wem7DHnHOZ0?si=Aok99kH3Y3erfU24', 'tag' => 'Multi-site' ],
			[ 'title' => 'Ra lệnh cho AI báo cáo đơn hàng trong tháng',                                                'url' => 'https://youtu.be/Wem7DHnHOZ0?si=Aok99kH3Y3erfU24', 'tag' => 'Reports' ],
			[ 'title' => 'Ra lệnh cho AI báo cáo tổng doanh số trong tháng',                                           'url' => 'https://youtu.be/29Y-80aMLaE?si=Nb1giqLFRhlz8Qvx', 'tag' => 'Reports' ],
			[ 'title' => 'Ra lệnh cho AI xuất Excel toàn bộ đơn hàng trong tháng trên web',                            'url' => 'https://youtu.be/cvW5Bl-fE_Q?si=6nH9oQbc4fZMd3_k', 'tag' => 'Excel' ],
			[ 'title' => 'Ra lệnh cho AI tổng hợp & xuất báo cáo doanh số trong tuần',                                 'url' => 'https://youtu.be/Ev-Z8QRxvoE?si=LBpOi6BPg-ESBAWK', 'tag' => 'Weekly' ],
			[ 'title' => 'Ra lệnh cho AI đặt lịch đăng bài tự động trên web',                                          'url' => 'https://youtu.be/DB9xvFWRgJ4?si=M9_oj3AY8DoWqbrh', 'tag' => 'Schedule' ],
			[ 'title' => 'Ra lệnh cho AI sửa / cập nhật sản phẩm trên web',                                            'url' => 'https://youtu.be/JZ5CUQ_L9K4?si=kIrB4ymhz3qzFpTz', 'tag' => 'Update' ],
		];
		?>
		<div class="wrap">
			<style>
				.bizcity-guides-wrap{max-width:1200px}
				.bizcity-guides-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:6px 0 14px;}
				.bizcity-guides-head h1{margin:0;font-size:20px}
				.bizcity-guides-sub{color:#6b7280;margin-top:6px;font-size:13px}
				.bizcity-guides-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
				.bizcity-guides-tools input{border-radius:12px;border:1px solid #e5e7eb;padding:8px 10px;min-width:320px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);}
				.bizcity-guides-grid{display:grid;grid-template-columns:repeat(3, 1fr);gap:14px;}
				@media (max-width:1200px){.bizcity-guides-grid{grid-template-columns:repeat(2, 1fr);}}
				@media (max-width:782px){.bizcity-guides-grid{grid-template-columns:1fr;}.bizcity-guides-tools input{min-width:100%;}}
				.bizcity-card{background:#fff;border:1px solid rgba(229,231,235,.9);border-radius:16px;padding:14px;box-shadow:0 8px 22px rgba(0,0,0,.06);position:relative;overflow:hidden;}
				.bizcity-card:before{content:"";position:absolute;inset:-40px;background:radial-gradient(circle at 10% 10%, rgba(59,130,246,.10), transparent 55%),radial-gradient(circle at 90% 0%, rgba(16,185,129,.08), transparent 50%),radial-gradient(circle at 70% 90%, rgba(168,85,247,.08), transparent 55%);filter:blur(12px);z-index:0;}
				.bizcity-card > *{position:relative;z-index:1}
				.bizcity-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px;}
				.bizcity-title{font-family:'tahoma';margin:0;font-size:14px;line-height:1.4;color:#111827;font-weight:800;}
				.bizcity-tag{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:rgba(239,246,255,.9);border:1px solid rgba(219,234,254,.9);color:#1d4ed8;font-size:12px;font-weight:700;white-space:nowrap;}
				.bizcity-video{border-radius:14px;overflow:hidden;border:1px solid rgba(229,231,235,.9);background:#000;}
				.bizcity-video iframe{width:100%;aspect-ratio:16/9;border:0;display:block;}
				.bizcity-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px;}
				.bizcity-actions a.button{border-radius:12px;}
				.bizcity-muted{color:#6b7280;font-size:12px;margin-top:8px}
			</style>

			<div class="bizcity-guides-wrap">
				<div class="bizcity-guides-head">
					<div>
						<h1>BizCity • Hướng dẫn "ra lệnh cho AI" làm việc bằng Video</h1>
						<div class="bizcity-guides-sub">Các video hướng dẫn "ra lệnh cho AI" theo từng nghiệp vụ: đăng bài, đơn hàng, sản phẩm, báo cáo, lịch đăng…</div>
					</div>
					<div class="bizcity-guides-tools">
						<input id="bizcityGuideSearch" type="text" placeholder="Tìm nhanh: đơn hàng, sản phẩm, báo cáo, fanpage..." />
					</div>
				</div>

				<div class="bizcity-guides-grid" id="bizcityGuidesGrid">
					<?php foreach ( $videos as $v ) :
						$title = (string) ( $v['title'] ?? '' );
						$url   = (string) ( $v['url']   ?? '' );
						$tag   = (string) ( $v['tag']   ?? 'Video' );
						$embed = bizcity_youtube_embed_url( $url );
						if ( ! $embed ) {
							continue;
						}
						?>
						<div class="bizcity-card" data-title="<?php echo esc_attr( mb_strtolower( $title ) ); ?>">
							<div class="bizcity-card-top">
								<h3 class="bizcity-title"><?php echo esc_html( $title ); ?></h3>
								<span class="bizcity-tag"><?php echo esc_html( $tag ); ?></span>
							</div>
							<div class="bizcity-video">
								<iframe src="<?php echo esc_url( $embed ); ?>"
								        title="<?php echo esc_attr( $title ); ?>"
								        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
								        allowfullscreen></iframe>
							</div>
							<div class="bizcity-actions">
								<a class="button button-secondary" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Mở YouTube</a>
							</div>
							<div class="bizcity-muted">Tip: Nếu xem không được iframe, bấm "Mở YouTube".</div>
						</div>
					<?php endforeach; ?>
				</div>

				<script>
					(function () {
						const input = document.getElementById('bizcityGuideSearch');
						const grid  = document.getElementById('bizcityGuidesGrid');
						if (!input || !grid) return;
						input.addEventListener('input', function () {
							const q = (input.value || '').trim().toLowerCase();
							grid.querySelectorAll('.bizcity-card').forEach(card => {
								const t = (card.getAttribute('data-title') || '');
								card.style.display = (!q || t.includes(q)) ? '' : 'none';
							});
						});
					})();
				</script>
			</div>
		</div>
		<?php
	}
}

// Re-register the legacy [bizcity_guides] shortcode so frontend pages keep working.
if ( ! shortcode_exists( 'bizcity_guides' ) ) {
	add_shortcode( 'bizcity_guides', function () {
		ob_start();
		bizcity_guides_admin_page();
		return ob_get_clean();
	} );
}

/* ─────────────────────────────────────────────────────────────────────
 *  bizcity_zalo_hotline_render_page()
 *  Single hub callback — composes the three legacy pages on one screen.
 *  No configuration UI: everything is "just nhắn tin theo hướng dẫn + video".
 * ───────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'bizcity_zalo_hotline_render_page' ) ) {
	function bizcity_zalo_hotline_render_page(): void {
		$td = 'bizcity-twin-ai';
		?>
		<div class="wrap">
			<h1>📞 <?php esc_html_e( 'Zalo Hotline (ZNS) — Quản trị qua Zalo', $td ); ?></h1>
			<p style="max-width:840px;color:#50575e;">
				<?php esc_html_e( 'Trang này chỉ hiển thị danh sách user đã kết nối Zalo và video hướng dẫn. Không cần cấu hình gì thêm — chỉ nhắn tin theo hướng dẫn là dùng được.', $td ); ?>
			</p>

			<p style="margin:8px 0 16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bizchat-gateway&group=channels' ) ); ?>">← Channels</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bizchat-gateway&group=integrations' ) ); ?>">Integrations</a>
			</p>
		</div>
		<?php

		// Block 1: Users + Telegram command widget (widget appended automatically).
		twf_zalo_users_admin_page();

		// Block 2: Video guides grid.
		bizcity_guides_admin_page();
	}
}
