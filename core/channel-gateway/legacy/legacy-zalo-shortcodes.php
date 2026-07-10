<?php
/**
 * Channel Gateway — Legacy Zalo Shortcodes
 *
 * Ported from mu-plugins/backup/zalo/shortcode_login.php.
 * Shortcode registration wrapped in shortcode_exists() guard to prevent
 * duplicate registration conflicts if old mu-plugin is still active.
 *
 * [2026-06-11 Johnny Chu] HOTFIX — moved into channel-gateway/legacy/ so
 * the zalo_login_form shortcode is owned by this plugin, not a mu-plugin backup.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Legacy
 * @since      2026-06-11
 */

defined( 'ABSPATH' ) || exit;

// ── zalo_login_form shortcode ─────────────────────────────────────────────────

if ( ! shortcode_exists( 'zalo_login_form' ) ) {
	add_shortcode( 'zalo_login_form', 'bizcity_legacy_zalo_login_form_shortcode' );
}

if ( ! function_exists( 'bizcity_legacy_zalo_login_form_shortcode' ) ) {
	/**
	 * Render form liên kết tài khoản WP với Zalo client_id.
	 *
	 * Usage: [zalo_login_form]
	 * Query param: ?zid=<encrypted_chat_id>
	 *
	 * @return string
	 */
	function bizcity_legacy_zalo_login_form_shortcode() {
		ob_start();

		$zid = isset( $_GET['zid'] ) ? sanitize_text_field( wp_unslash( $_GET['zid'] ) ) : '';
		if ( empty( $zid ) ) {
			return 'Link không hợp lệ.';
		}

		if ( ! function_exists( 'twf_decrypt_chat_id' ) ) {
			return 'Thiếu hàm xác thực — liên hệ admin.';
		}

		$client_id = twf_decrypt_chat_id( $zid, 'vietqr' );
		if ( ! $client_id ) {
			return 'Link không hợp lệ.';
		}

		if ( isset( $_POST['user_login'] ) && isset( $_POST['user_pass'] ) ) {
			// Verify nonce trước khi xử lý đăng nhập.
			if ( ! isset( $_POST['bizcity_zalo_login_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bizcity_zalo_login_nonce'] ) ), 'bizcity_zalo_login' )
			) {
				echo '<div class="error">Yêu cầu không hợp lệ (nonce).</div>';
			} else {
				$user = wp_signon( array(
					'user_login'    => sanitize_user( wp_unslash( $_POST['user_login'] ) ),
					'user_password' => $_POST['user_pass'],
					'remember'      => true,
				), is_ssl() );

				if ( ! is_wp_error( $user ) ) {
					$user_id = $user->ID;
					$blog_id = get_current_blog_id();
					update_user_meta( $user_id, 'zalo_client_id_' . $blog_id, $client_id );

					global $globaldb;
					if ( isset( $globaldb ) ) {
						$domain = get_home_url();
						$globaldb->insert( 'global_user_admin', array(
							'blog_id'       => $blog_id,
							'client_id'     => $client_id,
							'user_id'       => $user_id,
							'user_slave_id' => $user_id,
							'domain'        => $domain,
							'user_level'    => 'administrator',
						) );
					}

					echo '<div class="success">Đã liên kết tài khoản Zalo. Bạn có thể quay lại Zalo.</div>';
				} else {
					echo '<div class="error">Đăng nhập thất bại: ' . esc_html( $user->get_error_message() ) . '</div>';
				}
			}
		} else {
			$nonce = wp_create_nonce( 'bizcity_zalo_login' );
			?>
			<form method="post">
				<?php wp_nonce_field( 'bizcity_zalo_login', 'bizcity_zalo_login_nonce' ); ?>
				<p><label>Tên đăng nhập: <input type="text" name="user_login" autocomplete="username"></label></p>
				<p><label>Mật khẩu: <input type="password" name="user_pass" autocomplete="current-password"></label></p>
				<p><button type="submit">Liên kết với Zalo</button></p>
			</form>
			<?php
		}

		return ob_get_clean();
	}
}
