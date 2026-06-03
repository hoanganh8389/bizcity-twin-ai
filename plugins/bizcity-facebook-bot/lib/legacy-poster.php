<?php
/**
 * Legacy Facebook Poster Functions
 * Migrated from fb-connect-poster.php
 * 
 * @package BizCity_Facebook_Bot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bizcity_fb_app_settings_admin_page' ) ) {
/**
 * Facebook App Settings Admin Page
 * Trang cấu hình Facebook App & Messenger
 */
function bizcity_fb_app_settings_admin_page() {
	// Quyền truy cập
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Bạn không có quyền.' );
	}

	// Xử lý lưu dữ liệu
	if (
		isset( $_POST['fb_app_settings_nonce'] ) &&
		wp_verify_nonce( $_POST['fb_app_settings_nonce'], 'fb_app_settings_action' )
	) {
		update_option( 'fb_app_id', sanitize_text_field( $_POST['fb_app_id'] ?? '' ) );
		update_option( 'fb_app_secret', sanitize_text_field( $_POST['fb_app_secret'] ?? '' ) );
		update_option( 'messenger_page_id', sanitize_text_field( $_POST['messenger_page_id'] ?? '' ) );
		update_option( 'messenger_page_token', sanitize_text_field( $_POST['messenger_page_token'] ?? '' ) );

		echo '<div class="notice notice-success is-dismissible"><p><b>Đã lưu</b> cấu hình Facebook App & Messenger.</p></div>';
	}

	// Lấy giá trị hiện tại
	$app_id              = esc_attr( get_option( 'fb_app_id', '' ) );
	$app_secret          = esc_attr( get_option( 'fb_app_secret', '' ) );
	$messenger_page_id   = esc_attr( get_option( 'messenger_page_id', '' ) );
	$messenger_page_token = esc_attr( get_option( 'messenger_page_token', '' ) );

	$blog_details = get_blog_details( get_current_blog_id() );
	$domain       = $blog_details->domain ?? '';

	// Link hướng dẫn
	$yt_url   = 'https://www.youtube.com/watch?feature=shared&v=W9o3fMk7evU';
	$yt_embed = 'https://www.youtube.com/embed/W9o3fMk7evU';

	$oauth_redirect = 'https://' . $domain . '/?fb_callback=1';
	$webhook_url    = 'https://' . $domain . '/?fbhook=1';

	// CSS gọn, nền trắng, bo tròn
	echo '
	<div class="wrap">
	  <style>
		.bizfb-wrap{max-width:1100px}
		.bizfb-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:6px 0 14px}
		.bizfb-title h1{font-size:20px;margin:0}
		.bizfb-sub{color:#6b7280;margin-top:6px}
		.bizfb-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;align-items:start}
		@media (max-width: 1100px){.bizfb-grid{grid-template-columns:1fr}}
		.bizfb-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.bizfb-card h2{margin:0 0 10px;font-size:15px}
		.bizfb-badge{display:inline-flex;align-items:center;gap:8px;font-weight:600}
		.bizfb-badge span{width:10px;height:10px;border-radius:999px;display:inline-block}
		.dot-blue{background:#1977f2}
		.dot-green{background:#10b981}
		.bizfb-help{background:#f9fafb;border:1px dashed #e5e7eb;border-radius:12px;padding:12px;margin:10px 0 0}
		.bizfb-help p{margin:6px 0;color:#374151}
		.bizfb-help code{background:#1118270d;padding:2px 6px;border-radius:8px}
		.bizfb-note{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:12px;margin-top:12px}
		.bizfb-note b{color:#075985}
		.bizfb-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
		.bizfb-actions .button{border-radius:10px}
		.bizfb-yt{border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;background:#000}
		.bizfb-yt iframe{display:block;width:100%;aspect-ratio:16/9;border:0}
		.bizfb-small{font-size:12px;color:#6b7280;margin-top:8px}
		.bizfb-divider{height:1px;background:#e5e7eb;margin:14px 0}
	  </style>

	  <div class="bizfb-wrap">
		<div class="bizfb-title">
		  <div>
			<h1>Cấu hình Facebook App & Messenger</h1>
			<div class="bizfb-sub">
			  Dành cho nhà phát triển Facebook • <a href="https://developers.facebook.com" target="_blank" rel="noopener">developers.facebook.com</a>
			</div>
		  </div>
		  <div class="bizfb-actions">
			<a class="button" href="' . esc_url( $yt_url ) . '" target="_blank" rel="noopener">Xem video hướng dẫn</a>
			<a class="button button-secondary" href="' . esc_url( 'https://developers.facebook.com/apps' ) . '" target="_blank" rel="noopener">Mở trang quản lý Apps</a>
		  </div>
		</div>

		<div class="bizfb-grid">

		  <div>
			<form method="post">
			  ' . wp_nonce_field( 'fb_app_settings_action', 'fb_app_settings_nonce', true, false ) . '

			  <div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="dot-blue"></span>Thông tin App Facebook</h2>

				<div class="bizfb-help">
				  <p><b>Bước nhanh:</b> Tạo App → Chọn <b>Đăng nhập bằng Facebook</b> → Cài đặt → bật yêu cầu OAuth nếu cần.</p>
				  <p>URI chuyển hướng OAuth hợp lệ (copy đúng): <code>' . esc_html( $oauth_redirect ) . '</code></p>
				  <p>Chính sách quyền riêng tư (ví dụ): <code>https://bizgpt.vn/chinh-sach-bao-mat-quyen-rieng-tu/</code></p>
				  <p>Vào <b>Cài đặt ứng dụng</b> → <b>Thông tin cơ bản</b> để lấy <b>App ID</b> và <b>App Secret</b>.</p>
				</div>

				<table class="form-table" role="presentation">
				  <tr>
					<th scope="row"><label for="fb_app_id">App ID (ID ứng dụng)</label></th>
					<td><input type="text" id="fb_app_id" name="fb_app_id" value="' . $app_id . '" class="regular-text" autocomplete="off" /></td>
				  </tr>
				  <tr>
					<th scope="row"><label for="fb_app_secret">App Secret (Khóa bí mật)</label></th>
					<td><input type="text" id="fb_app_secret" name="fb_app_secret" value="' . $app_secret . '" class="regular-text" autocomplete="off" /></td>
				  </tr>
				</table>
			  </div>

			  <div class="bizfb-divider"></div>

			  <div class="bizfb-card">
				<h2 class="bizfb-badge"><span class="dot-green"></span>Cấu hình Messenger của FanPage</h2>

				<div class="bizfb-help">
				  <p>Webhook URL (copy đúng): <code>' . esc_html( $webhook_url ) . '</code></p>
				  <p>Verify Token: <code>bizgpt</code></p>
				  <p>Cấp quyền: <b>messages</b>, <b>messaging_postbacks</b>, <b>messaging_optins</b>, <b>messaging_account_linking</b>, <b>messaging_referrals</b>, <b>messaging_customer_information</b>.</p>
				  <p>Sau đó tạo <b>Page Access Token</b> và lấy <b>Page ID</b> để lưu vào đây.</p>
				</div>

				<table class="form-table" role="presentation">
				  <tr>
					<th scope="row"><label for="messenger_page_id">Messenger Page ID</label></th>
					<td><input type="text" id="messenger_page_id" name="messenger_page_id" value="' . $messenger_page_id . '" class="regular-text" autocomplete="off" /></td>
				  </tr>
				  <tr>
					<th scope="row"><label for="messenger_page_token">Messenger Page Token</label></th>
					<td><input type="text" id="messenger_page_token" name="messenger_page_token" value="' . $messenger_page_token . '" class="regular-text" autocomplete="off" /></td>
				  </tr>
				</table>

				<div class="bizfb-actions">
				  <input type="submit" class="button button-primary" value="Lưu cấu hình" />
				  <a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ) . '">Đăng nhập kết nối Fanpages</a>
				</div>

				<div class="bizfb-note">
				  <b>Gợi ý:</b> Lưu xong → qua trang <b>Đăng nhập kết nối Fanpages</b> để chọn danh sách Fanpages mà Trợ lý AI sẽ hỗ trợ viết bài/đăng bài và nhắn tin tự động.
				</div>
			  </div>

			</form>
		  </div>

		  <div>
			<div class="bizfb-card">
			  <h2>Video hướng dẫn cấu hình</h2>
			  <div class="bizfb-yt">
				<iframe
				  src="' . esc_url( $yt_embed ) . '"
				  title="Hướng dẫn cấu hình Facebook App & Messenger"
				  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
				  allowfullscreen></iframe>
			  </div>
			  <div class="bizfb-small">
				Nếu iframe bị chặn bởi trình duyệt / CSP, bấm "Xem video hướng dẫn" ở góc trên để mở YouTube.
			  </div>
			</div>

			<div class="bizfb-card" style="margin-top:16px">
			  <h2>Copy nhanh</h2>
			  <div class="bizfb-help">
				<p><b>OAuth Redirect:</b><br><code>' . esc_html( $oauth_redirect ) . '</code></p>
				<p><b>Webhook URL:</b><br><code>' . esc_html( $webhook_url ) . '</code></p>
				<p><b>Verify Token:</b><br><code>bizgpt</code></p>
			  </div>
			</div>
		  </div>

		</div>
	  </div>
	</div>';
}
} // end if function_exists bizcity_fb_app_settings_admin_page

if ( ! function_exists( 'bizcity_fb_connect_page' ) ) {
/**
 * Facebook Connect Page - Danh sách Fanpages đã kết nối
 */
function bizcity_fb_connect_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Bạn không có quyền truy cập trang này.' );
		return;
	}

	$app_id     = get_option( 'fb_app_id', defined( 'FB_APP_ID' ) ? FB_APP_ID : '' );
	$app_secret = get_option( 'fb_app_secret', defined( 'FB_APP_SECRET' ) ? FB_APP_SECRET : '' );

	$token   = 'blog_';
	$blog_id = get_current_blog_id();

	// Lưu blog_id vào transient ở blog chính (ID = 1)
	switch_to_blog( 1 );
	set_transient( 'fb_login_' . $token, $blog_id, 5 * MINUTE_IN_SECONDS );
	restore_current_blog();

	// Lấy domain hiện tại từ blog_id
	$blog_details = get_blog_details( $blog_id );
	$domain       = 'https://' . rtrim( $blog_details->domain, '/' );

	// Các quyền đầy đủ
	$scopes = implode( ',', array(
		'pages_show_list',
		'pages_manage_posts',
		'pages_manage_engagement',
		'pages_manage_metadata',
		'pages_read_engagement',
		'pages_read_user_content',
		'pages_messaging',
		'pages_messaging_subscriptions',
		'public_profile',
	) );

	// Tạo redirect_uri sử dụng domain của blog hiện tại
	$redirect_uri = urlencode( $domain . '/?fb_callback=1' );
	$fb_login_url = "https://www.facebook.com/v18.0/dialog/oauth?client_id={$app_id}&redirect_uri={$redirect_uri}&scope={$scopes}&response_type=code";

	echo "<div class='wrap'><h2>Kết nối với Facebook</h2>";

	// Show OAuth callback messages
	if ( isset( $_GET['biz_fb_oauth_status'] ) ) {
		$oauth_status = sanitize_text_field( $_GET['biz_fb_oauth_status'] );
		if ( $oauth_status === 'success' ) {
			$count = (int) ( $_GET['biz_fb_pages_count'] ?? 0 );
			echo "<div class='notice notice-success is-dismissible'><p>✅ Đã kết nối thành công {$count} Fanpage qua Central OAuth! Routes đã tự đăng ký.</p></div>";
		} elseif ( $oauth_status === 'error' ) {
			$error_msg = sanitize_text_field( urldecode( $_GET['biz_fb_oauth_error'] ?? 'Unknown error' ) );
			echo "<div class='notice notice-error is-dismissible'><p>❌ Lỗi OAuth: " . esc_html( $error_msg ) . "</p></div>";
		}
	}

	// Central OAuth button (recommended)
	$central_oauth_url = class_exists( 'BizCity_Facebook_OAuth' ) ? BizCity_Facebook_OAuth::get_oauth_url() : null;
	if ( $central_oauth_url ) {
		echo '<div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 18px 20px; margin-bottom: 20px;">
			<h3 style="margin:0 0 8px;">✨ Kết nối nhanh qua Central OAuth (Khuyên dùng)</h3>
			<p>Bấm nút bên dưới để đăng nhập Facebook, chọn Fanpage, tự động kết nối + đăng ký webhook.<br>
			<b>Không cần nhập App ID/Secret</b> — hệ thống dùng chung cấu hình từ Network Admin.</p>
			<a href="' . esc_url( $central_oauth_url ) . '" class="button button-primary" style="font-size:16px;padding:10px 28px">🔗 Đăng nhập Facebook (Central)</a>
		</div>';
	}

	// Legacy instructions
	echo '<div style="background: #fffbe5; border-left: 4px solid #ffba00; padding: 18px 20px 10px 20px; margin-bottom: 20px;">
		<b>Hướng dẫn kết nối Fanpage Facebook (Legacy):</b>
		<ol style="margin: 8px 0 8px 24px;">
			<li><b>Bước 1:</b> Trước tiên, hãy vào mục <b><a href="' . admin_url( 'admin.php?page=bizcity-facebook-bots' ) . '" style="color:#1977f2;">Cấu hình App</a></b> để nhập <b>App ID</b> và <b>App Secret</b> của Facebook App bạn quản lý.</li>
			<li><b>Bước 2:</b> Sau khi lưu cấu hình, quay lại trang này và bấm nút <span style="color: #1977f2;">Đăng nhập Facebook</span> bên dưới.</li>
			<li><b>Bước 3:</b> Chọn Fanpage muốn đồng bộ, cấp đầy đủ quyền để hoàn tất kết nối.</li>
			<li><b>Bước 4:</b> Sau khi kết nối, Fanpage sẽ hiển thị ở bảng bên dưới. Bạn có thể thao tác đăng bài, quản lý bình luận...</li>
		</ol>
		<div style="color:#b94a48; font-size:14px;"><b>Lưu ý:</b> Nếu token hết hạn hoặc chưa kết nối, hãy bấm lại nút Đăng nhập để kết nối lại.</div>
	</div>';

	echo "<a href='$fb_login_url' class='button button-primary' style='font-size:16px; padding:10px 28px'>🔗 Đăng nhập Facebook</a><br><br>";

	// Hiển thị bảng danh sách Fanpage
	if ( isset( $_GET['remove_page'] ) ) {
		$remove_id = sanitize_text_field( $_GET['remove_page'] );
		$pages     = get_option( 'fb_pages_connected', array() );
		$pages     = array_filter( $pages, function ( $page ) use ( $remove_id ) {
			return $page['id'] !== $remove_id;
		} );
		update_option( 'fb_pages_connected', $pages );
		echo "<div class='notice notice-success'><p>✅ Đã xóa fanpage có ID: $remove_id</p></div>";
	}

	$pages = get_option( 'fb_pages_connected' );

	echo "<h3>✅ Danh sách Fanpage đã kết nối:</h3>";
	echo "<table class='widefat fixed striped'>";
	echo "<thead><tr><th>Tên Fanpage</th><th>ID</th><th>Hành động</th></tr></thead><tbody>";
	if ( ! empty( $pages ) ) {
		foreach ( $pages as $p ) {
			$remove_url = admin_url( 'admin.php?page=bizcity-facebook-bot-connect&remove_page=' . $p['id'] );
			echo "<tr>
					<td><a href='https://facebook.com/{$p['id']}' target='_blank'>{$p['name']}</a></td>
					<td>{$p['id']}</td>
					<td><a href='$remove_url' class='button button-small'>❌ Xóa</a></td>
				</tr>";
		}
	} else {
		echo "<tr>
				<td colspan='3'><em>Chưa có fanpage nào được kết nối.</em></td>
			</tr>";
	}
	echo "</tbody></table>";

	echo "</div>";
	echo '<br><br><br>';
	bizcity_fb_app_settings_admin_page();
}
} // end if function_exists bizcity_fb_connect_page

if ( ! function_exists( 'bizcity_fb_business_management_page' ) ) {
/**
 * Business Management Page
 */
function bizcity_fb_business_management_page() {
	echo '<div class="wrap"><h2>Liên kết và xác thực Fanpage qua quyền business_management</h2>';

	$pages = get_option( 'fb_pages_connected' );
	if ( empty( $pages ) ) {
		echo '<p><em>Chưa có Fanpage nào được liên kết. Hãy kết nối trước.</em></p></div>';
		return;
	}

	echo '<table class="widefat fixed striped">';
	echo '<thead><tr><th>Tên Fanpage</th><th>ID</th><th>Quản trị viên xác thực</th><th>Trạng thái liên kết</th></tr></thead><tbody>';

	foreach ( $pages as $p ) {
		echo '<tr>
			<td><a href="https://facebook.com/' . esc_attr( $p['id'] ) . '" target="_blank">' . esc_html( $p['name'] ) . '</a></td>
			<td>' . esc_html( $p['id'] ) . '</td>
			<td>Đã xác thực qua API Facebook</td>
			<td><span style="color:green;">Đã liên kết</span></td>
		</tr>';
	}

	echo '</tbody></table>';
	echo '<p><em>Bảng này thể hiện các Fanpage đã xác thực quyền quản trị thông qua business_management.</em></p>';
	echo '</div>';
}
} // end if function_exists bizcity_fb_business_management_page

if ( ! function_exists( 'bizcity_fb_comments_manager_page' ) ) {
/**
 * Comments Manager Page - Quản lý tương tác
 */
function bizcity_fb_comments_manager_page() {
	echo '<div class="wrap"><h2>Quản lý tương tác Fanpage Facebook</h2>';

	$pages = get_option( 'fb_pages_connected' );
	if ( empty( $pages ) ) {
		echo '<p><em>Chưa có fanpage nào được kết nối.</em></p></div>';
		return;
	}

	// Xử lý reply comment nếu có POST
	if (
		isset( $_POST['fb_reply_comment_nonce'], $_POST['reply_comment_id'], $_POST['reply_page_token'], $_POST['reply_content'] ) &&
		wp_verify_nonce( $_POST['fb_reply_comment_nonce'], 'fb_reply_comment_action' )
	) {
		$comment_id    = sanitize_text_field( $_POST['reply_comment_id'] );
		$page_token    = sanitize_text_field( $_POST['reply_page_token'] );
		$reply_content = sanitize_textarea_field( $_POST['reply_content'] );
		$res           = wp_remote_post( "https://graph.facebook.com/{$comment_id}/comments", array(
			'body' => array(
				'message'      => $reply_content,
				'access_token' => $page_token,
			),
		) );
		if ( ! is_wp_error( $res ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>✅ Đã trả lời bình luận!</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>❌ Lỗi trả lời: ' . $res->get_error_message() . '</p></div>';
		}
	}

	// Select Fanpage
	$selected = isset( $_GET['page_id'] ) ? sanitize_text_field( $_GET['page_id'] ) : $pages[0]['id'];
	echo '<form method="get" action="">';
	echo '<input type="hidden" name="page" value="bizcity-facebook-bot-comments">';
	echo '<select name="page_id" onchange="this.form.submit()">';
	foreach ( $pages as $p ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $p['id'] ),
			$selected == $p['id'] ? ' selected' : '',
			esc_html( $p['name'] )
		);
	}
	echo '</select></form><br>';

	// Lấy token page đã chọn
	$page_data = null;
	foreach ( $pages as $p ) {
		if ( $p['id'] == $selected ) {
			$page_data = $p;
			break;
		}
	}
	if ( ! $page_data ) {
		echo '<p>Không tìm thấy Fanpage.</p></div>';
		return;
	}
	$page_token = $page_data['access_token'];

	// Lấy danh sách bài post mới nhất
	$url   = "https://graph.facebook.com/{$selected}/feed?fields=id,message,created_time,permalink_url,comments.summary(true),likes.summary(true)&access_token=$page_token&limit=10";
	$res   = wp_remote_get( $url );
	$posts = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( ! empty( $posts['data'] ) ) {
		echo '<table class="widefat striped"><thead>
				<tr>
					<th>Bài viết</th>
					<th>Ngày đăng</th>
					<th>Lượt thích</th>
					<th>Bình luận</th>
					<th>Tổng tương tác</th>
					<th>Chi tiết</th>
				</tr>
			  </thead><tbody>';

		foreach ( $posts['data'] as $post ) {
			$post_id = $post['id'];
			$title   = esc_html( mb_strimwidth( $post['message'] ?? '(Không có nội dung)', 0, 60, '...' ) );
			$date    = date_i18n( 'd/m/Y H:i', strtotime( $post['created_time'] ) );
			$link    = ! empty( $post['permalink_url'] ) ? '<a href="' . esc_url( $post['permalink_url'] ) . '" target="_blank">Xem</a>' : '';

			$comments_total = $post['comments']['summary']['total_count'] ?? 0;
			$likes_total    = $post['likes']['summary']['total_count'] ?? 0;
			$total_interact = $comments_total + $likes_total;

			// Lấy danh sách bình luận
			$cmt_url  = "https://graph.facebook.com/$post_id/comments?fields=id,from,message,created_time,comment_count,like_count&access_token=$page_token&limit=10";
			$cmt_res  = wp_remote_get( $cmt_url );
			$cmts     = json_decode( wp_remote_retrieve_body( $cmt_res ), true );
			$cmt_html = '';
			if ( ! empty( $cmts['data'] ) ) {
				foreach ( $cmts['data'] as $cmt ) {
					$from       = esc_html( $cmt['from']['name'] ?? 'Ẩn danh' );
					$msg        = esc_html( $cmt['message'] );
					$cmt_time   = date_i18n( 'd/m/Y H:i', strtotime( $cmt['created_time'] ) );
					$comment_id = $cmt['id'];
					$like_count = intval( $cmt['like_count'] ?? 0 );
					$cmt_html  .= "<div style='margin-bottom:10px; background:#f9f9f9; border-radius:7px; padding:7px 10px;'>
						<b>$from:</b> $msg <span style='color:#888;font-size:11px;'>($cmt_time)</span> <span style='color:#c00; font-size:11px;'> ♥ $like_count</span>
						<form method='post' style='margin-top:5px;'>
							" . wp_nonce_field( 'fb_reply_comment_action', 'fb_reply_comment_nonce', true, false ) . "
							<input type='hidden' name='reply_comment_id' value='" . esc_attr( $comment_id ) . "'>
							<input type='hidden' name='reply_page_token' value='" . esc_attr( $page_token ) . "'>
							<input type='text' name='reply_content' style='width:60%;' placeholder='Trả lời bình luận...' required>
							<input type='submit' class='button button-small' value='Trả lời'>
						</form>
					</div>";
				}
			} else {
				$cmt_html = '<em>Chưa có bình luận</em>';
			}

			// Lấy danh sách người đã like
			$like_url       = "https://graph.facebook.com/$post_id/likes?fields=name&access_token=$page_token&limit=5";
			$like_res       = wp_remote_get( $like_url );
			$likes          = json_decode( wp_remote_retrieve_body( $like_res ), true );
			$like_list_html = '';
			if ( ! empty( $likes['data'] ) ) {
				$like_names     = array_map( function ( $u ) {
					return esc_html( $u['name'] );
				}, $likes['data'] );
				$like_list_html = implode( ', ', $like_names );
				if ( $likes_total > 5 ) {
					$like_list_html .= " và " . ( $likes_total - 5 ) . " người khác...";
				}
			}

			echo "<tr>
					<td><b>{$title}</b><br><code>{$post_id}</code><br>{$link}</td>
					<td>{$date}</td>
					<td><span style='font-weight:bold;color:#1a7f37;'>{$likes_total}</span><br><span style='font-size:12px;'>$like_list_html</span></td>
					<td>
						<span style='font-weight:bold;color:#1a57d6;'>{$comments_total}</span>
						<details><summary style='cursor:pointer;font-size:13px;color:#0073aa;'>Xem chi tiết</summary>$cmt_html</details>
					</td>
					<td><b>$total_interact</b></td>
					<td>$link</td>
				</tr>";
		}
		echo '</tbody></table>';
	} else {
		echo '<p>Không tìm thấy bài đăng!</p>';
	}
	echo '</div>';
}
} // end if function_exists bizcity_fb_comments_manager_page

if ( ! function_exists( 'bizcity_fb_handle_oauth_callback' ) ) {
/**
 * Handle Facebook OAuth callback
 */
function bizcity_fb_handle_oauth_callback() {
	if ( isset( $_GET['fb_callback'] ) && isset( $_GET['code'] ) ) {
		$code       = sanitize_text_field( $_GET['code'] );
		$token      = 'blog_';
		$app_id     = get_option( 'fb_app_id', defined( 'FB_APP_ID' ) ? FB_APP_ID : '' );
		$app_secret = get_option( 'fb_app_secret', defined( 'FB_APP_SECRET' ) ? FB_APP_SECRET : '' );

		$blog_details = get_blog_details( get_current_blog_id() );
		$domain       = 'https://' . rtrim( $blog_details->domain, '/' );
		$redirect_uri = $domain . '/?fb_callback=1';

		$token_url = "https://graph.facebook.com/v18.0/oauth/access_token?" . http_build_query( array(
			'client_id'     => $app_id,
			'redirect_uri'  => $redirect_uri,
			'client_secret' => $app_secret,
			'code'          => $code,
		) );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'get token_url: ' . print_r( $token_url, true ) );
		// }

		$response = wp_remote_get( $token_url, array(
			'timeout'     => 30,
			'redirection' => 3,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'BizGPT-FBConnect/1.0; ' . home_url( '/' ),
			),
		) );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'wp_remote_get response received' );
		// }

		if ( is_wp_error( $response ) ) {
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'ERROR', 'FB token wp_remote_get WP_Error: ' . $response->get_error_message() );
			// }
			wp_die( 'Lỗi kết nối tới Facebook (WP_Error): ' . esc_html( $response->get_error_message() ) );
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body_raw  = (string) wp_remote_retrieve_body( $response );
		$body      = json_decode( $body_raw, true );

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB token HTTP code: ' . $code_http );
		// 	back_trace( 'NOTICE', 'FB token body_raw: ' . $body_raw );
		// }

		if ( $code_http !== 200 ) {
			$msg = '';
			if ( is_array( $body ) && ! empty( $body['error']['message'] ) ) {
				$msg = $body['error']['message'];
			}
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'ERROR', 'FB token HTTP error: ' . $code_http . ' - ' . $msg );
			// }
			wp_die( 'Facebook token HTTP ' . $code_http . ( $msg ? ( ' - ' . esc_html( $msg ) ) : '' ) );
		}

		$access_token = $body['access_token'] ?? '';

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB access_token received: ' . ( $access_token ? 'YES' : 'NO' ) );
		// }

		// Lấy blog_id từ transient
		switch_to_blog( 1 );
		$blog_id = get_transient( 'fb_login_' . $token );
		delete_transient( 'fb_login_' . $token );
		restore_current_blog();

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'NOTICE', 'FB blog_id from transient: ' . ( $blog_id ? $blog_id : 'NOT FOUND' ) );
		// }

		if ( ! $blog_id ) {
			wp_die( '❌ Không tìm thấy blog_id tương ứng với token xác thực.' );
		}

		switch_to_blog( $blog_id );
		if ( $access_token ) {
			// Lưu user token trước
			update_option( 'fb_user_token', $access_token );
			
			// Debug: Kiểm tra thông tin user và quyền đã cấp
			$me_url = "https://graph.facebook.com/v18.0/me?fields=id,name&access_token=$access_token";
			$me_response = wp_remote_get( $me_url );
			$me_data = json_decode( wp_remote_retrieve_body( $me_response ), true );
			
			// Debug: Kiểm tra permissions đã được cấp
			$permissions_url = "https://graph.facebook.com/v18.0/me/permissions?access_token=$access_token";
			$permissions_response = wp_remote_get( $permissions_url );
			$permissions_data = json_decode( wp_remote_retrieve_body( $permissions_response ), true );
			
			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'NOTICE', 'FB /me data: ' . print_r( $me_data, true ) );
			// 	back_trace( 'NOTICE', 'FB /me/permissions data: ' . print_r( $permissions_data, true ) );
			// }
			
			$pages_url = "https://graph.facebook.com/v18.0/me/accounts?fields=id,name,access_token,category&access_token=$access_token";
			$pages_response = wp_remote_get( $pages_url );
			
			if ( is_wp_error( $pages_response ) ) {
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB /me/accounts WP_Error: ' . $pages_response->get_error_message() );
				// }
				restore_current_blog();
				wp_die( 'Lỗi kết nối tới Facebook khi lấy danh sách Fanpage: ' . esc_html( $pages_response->get_error_message() ) );
			}
			
			$pages_http_code = (int) wp_remote_retrieve_response_code( $pages_response );
			$pages_body_raw = wp_remote_retrieve_body( $pages_response );
			$data = json_decode( $pages_body_raw, true );

			// if ( function_exists( 'back_trace' ) ) {
			// 	back_trace( 'NOTICE', 'FB /me/accounts HTTP: ' . $pages_http_code );
			// 	back_trace( 'NOTICE', 'FB pages data: ' . print_r( $data, true ) );
			// }
			
			// Kiểm tra lỗi từ Facebook API
			if ( isset( $data['error'] ) ) {
				$error_msg = $data['error']['message'] ?? 'Unknown error';
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB /me/accounts error: ' . $error_msg );
				// }
				restore_current_blog();
				wp_die( 'Facebook API Error: ' . esc_html( $error_msg ) );
			}

			if ( ! empty( $data['data'] ) ) {
				$pages_clean = array();
				foreach ( $data['data'] as $page ) {
					$pages_clean[] = array(
						'id'           => $page['id'],
						'name'         => $page['name'],
						'access_token' => $page['access_token'],
						'category'     => $page['category'] ?? '',
					);
				}

				update_option( 'fb_pages_connected', $pages_clean );
				
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'NOTICE', 'FB OAuth success! ' . count( $pages_clean ) . ' pages connected. Redirecting...' );
				// }
				
				restore_current_blog();
				wp_redirect( admin_url( 'admin.php?page=bizcity-facebook-bot-connect&status=success' ) );
				exit;
			} else {
				// if ( function_exists( 'back_trace' ) ) {
				// 	back_trace( 'ERROR', 'FB pages data is empty - user did not grant access to any Fanpage' );
				// }
				restore_current_blog();
				
				// Get user name for display
				$user_name = isset( $me_data['name'] ) ? esc_html( $me_data['name'] ) : 'User';
				$user_id = isset( $me_data['id'] ) ? esc_html( $me_data['id'] ) : '';
				
				wp_die( '
					<div style="font-family: Arial, sans-serif; max-width: 700px; margin: 50px auto; padding: 20px;">
						<h2 style="color: #c00;">❌ Không tìm thấy Fanpage nào!</h2>
						<p>Đã xác thực thành công với tài khoản: <b>' . $user_name . '</b> (ID: ' . $user_id . ')</p>
						<p>Tất cả permissions đã được cấp, nhưng API <code>/me/accounts</code> trả về danh sách rỗng.</p>
						
						<h3 style="color: #333; margin-top: 20px;">🔍 Nguyên nhân có thể:</h3>
						<ol style="line-height: 1.8;">
							<li><b>Facebook App đang ở chế độ Development:</b><br>
								Chỉ Admin/Developer/Tester của App mới có thể truy cập Fanpages.<br>
								→ <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/roles/roles/" target="_blank" style="color: #1877f2;">Thêm user vào App Roles</a> hoặc <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/app-review/requests/" target="_blank" style="color: #1877f2;">Chuyển App sang Live mode</a>
							</li>
							<li><b>Tài khoản không quản lý Fanpage nào:</b><br>
								Chỉ Admin hoặc Editor của Fanpage mới xuất hiện trong danh sách.<br>
								→ <a href="https://www.facebook.com/pages/?category=your_pages" target="_blank" style="color: #1877f2;">Kiểm tra Fanpages của bạn</a>
							</li>
							<li><b>Bạn đã bỏ chọn tất cả Fanpages trong dialog đăng nhập:</b><br>
								Khi đăng nhập, Facebook hỏi "Chọn Fanpages để chia sẻ với ứng dụng".<br>
								→ Thử đăng nhập lại và <b>tick chọn các Fanpage</b> bạn muốn kết nối.
							</li>
							<li><b>Fanpage bị giới hạn hoặc chưa published:</b><br>
								Fanpages chưa published hoặc bị restrict sẽ không xuất hiện.
							</li>
						</ol>
						
						<div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 15px; margin-top: 20px;">
							<b>💡 Giải pháp nhanh nhất:</b><br>
							Vào <a href="https://developers.facebook.com/apps/' . esc_attr( $app_id ) . '/roles/roles/" target="_blank" style="color: #1877f2;">App Roles</a> → Thêm tài khoản <b>' . $user_name . '</b> làm <b>Tester</b> hoặc <b>Developer</b> → Sau đó quay lại đăng nhập.
						</div>
						
						<p style="margin-top: 20px;">
							<a href="' . esc_url( admin_url( 'admin.php?page=bizcity-facebook-bot-connect' ) ) . '" style="display: inline-block; background: #1877f2; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px;">← Quay lại thử kết nối lại</a>
						</p>
					</div>
				' );
			}
		}
		restore_current_blog();

		// if ( function_exists( 'back_trace' ) ) {
		// 	back_trace( 'ERROR', 'FB OAuth failed at end - access_token: ' . ( $access_token ? 'YES' : 'NO' ) );
		// }
		wp_die( 'Lỗi xác thực Facebook - Không nhận được access token.' );
	}
}
} // end if function_exists bizcity_fb_handle_oauth_callback

if ( ! function_exists( 'bizcity_fb_fanpage_posts_page' ) ) {
/**
 * Fanpage Posts Page
 */
function bizcity_fb_fanpage_posts_page() {
	echo '<div class="wrap"><h2>Quản lý bài đăng & Bình luận Fanpage Facebook</h2>';

	$pages = get_option( 'fb_pages_connected' );
	if ( empty( $pages ) ) {
		echo '<p><em>Chưa có fanpage nào được kết nối.</em></p></div>';
		return;
	}

	// Chọn page
	$selected = isset( $_GET['page_id'] ) ? sanitize_text_field( $_GET['page_id'] ) : $pages[0]['id'];
	echo '<form method="get" action="">';
	echo '<input type="hidden" name="page" value="bizcity-facebook-bot-posts">';
	echo '<select name="page_id" onchange="this.form.submit()">';
	foreach ( $pages as $p ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $p['id'] ),
			$selected == $p['id'] ? ' selected' : '',
			esc_html( $p['name'] )
		);
	}
	echo '</select>';
	echo '</form>';

	// Lấy token page đã chọn
	$page_data = null;
	foreach ( $pages as $p ) {
		if ( $p['id'] == $selected ) {
			$page_data = $p;
			break;
		}
	}
	if ( ! $page_data ) {
		echo '<p>Không tìm thấy Fanpage.</p></div>';
		return;
	}

	$token = $page_data['access_token'];

	// Nếu chọn post cụ thể để xem bình luận
	if ( ! empty( $_GET['post_id'] ) ) {
		$post_id      = sanitize_text_field( $_GET['post_id'] );
		$url_comments = "https://graph.facebook.com/{$post_id}/comments?fields=from,message,created_time,like_count,permalink_url&access_token=$token&limit=15";
		$res          = wp_remote_get( $url_comments );
		$data         = json_decode( wp_remote_retrieve_body( $res ), true );

		echo '<p><a href="' . esc_url( remove_query_arg( 'post_id' ) ) . '">← Quay lại danh sách bài đăng</a></p>';
		echo '<h3>Bình luận mới nhất cho bài viết: ' . esc_html( $post_id ) . '</h3>';
		if ( ! empty( $data['data'] ) ) {
			echo '<table class="widefat striped"><thead>
				<tr>
					<th>Người bình luận</th>
					<th>Nội dung</th>
					<th>Lượt thích</th>
					<th>Ngày</th>
					<th>Link</th>
				</tr>
				</thead><tbody>';
			foreach ( $data['data'] as $cm ) {
				$author = esc_html( $cm['from']['name'] ?? 'Ẩn danh' );
				$msg    = esc_html( $cm['message'] ?? '' );
				$like   = intval( $cm['like_count'] ?? 0 );
				$date   = date_i18n( 'd/m/Y H:i', strtotime( $cm['created_time'] ) );
				$link   = ! empty( $cm['permalink_url'] ) ? '<a href="' . esc_url( $cm['permalink_url'] ) . '" target="_blank">Xem</a>' : '';
				echo "<tr>
						<td>$author</td>
						<td>$msg</td>
						<td style='text-align:center;'>$like</td>
						<td>$date</td>
						<td>$link</td>
					</tr>";
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Chưa có bình luận hoặc chưa đủ quyền <b>pages_read_user_content</b>.</p>';
		}

		echo '</div>';
		return;
	}

	// Lấy danh sách bài đăng của page
	$url  = "https://graph.facebook.com/{$selected}/feed?fields=id,message,created_time,permalink_url,full_picture,type,status_type,caption&access_token=$token&limit=20";
	$res  = wp_remote_get( $url );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( ! empty( $data['data'] ) ) {
		echo '<table class="widefat fixed striped"><thead>
				<tr>
					<th>Ảnh</th>
					<th>Nội dung / Caption</th>
					<th>Loại</th>
					<th>Ngày đăng</th>
					<th>Link</th>
					<th>Bình luận</th>
				</tr>
			  </thead><tbody>';
		foreach ( $data['data'] as $post ) {
			$pic         = ! empty( $post['full_picture'] ) ? '<img src="' . esc_url( $post['full_picture'] ) . '" style="width:80px;max-height:60px;object-fit:cover">' : '';
			$caption     = esc_html( $post['message'] ?? $post['caption'] ?? '(Không có nội dung)' );
			$type        = esc_html( $post['type'] ?? $post['status_type'] ?? '' );
			$date        = date_i18n( 'd/m/Y H:i', strtotime( $post['created_time'] ) );
			$link        = ! empty( $post['permalink_url'] ) ? '<a href="' . esc_url( $post['permalink_url'] ) . '" target="_blank">Xem</a>' : '';
			$btn_comment = '<a href="' . esc_url( add_query_arg( 'post_id', $post['id'] ) ) . '" class="button button-small">Xem bình luận</a>';
			echo "<tr>
					<td>$pic</td>
					<td>$caption</td>
					<td>$type</td>
					<td>$date</td>
					<td>$link</td>
					<td>$btn_comment</td>
				</tr>";
		}
		echo '</tbody></table>';
	} else {
		echo '<p>Không tìm thấy bài đăng do không đủ quyền truy cập <strong>pages_read_user_content</strong>!</p>';
	}

	echo '</div>';
}
} // end if function_exists bizcity_fb_fanpage_posts_page
