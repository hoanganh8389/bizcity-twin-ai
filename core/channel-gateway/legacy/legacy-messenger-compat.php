<?php
/**
 * Channel Gateway — Legacy Messenger Compat Functions
 *
 * Ported from mu-plugins/backup/messenger.archived/functions.php.
 * All functions wrapped in function_exists() guards to prevent fatal conflicts
 * when the legacy mu-plugin is still active on older installs.
 *
 * [2026-06-11 Johnny Chu] HOTFIX — moved into channel-gateway/legacy/ so these
 * helpers are available to channel-gateway classes without depending on mu-plugin.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway\Legacy
 * @since      2026-06-11
 */

defined( 'ABSPATH' ) || exit;

// ── _bizcity_legacy_tbl_exists — shared table-existence cache ────────────────
// [2026-06-21 Johnny Chu] HOTFIX — SELECT 1 from information_schema + dual cache.
// Static array  : free after first check per request (no DB at all).
// wp_cache layer: cross-request (Redis/Memcached when available), 1-hour TTL.
// Uses SELECT 1 (not SHOW TABLES) — no DB error on missing table, pure read.
if ( ! function_exists( '_bizcity_legacy_tbl_exists' ) ) {
	function _bizcity_legacy_tbl_exists( $table_name ) {
		static $s = array();
		if ( isset( $s[ $table_name ] ) ) {
			return $s[ $table_name ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $table_name ] = (bool) $present;
		return $s[ $table_name ];
	}
}

// ── messenger_get_fb_customer ─────────────────────────────────────────────────

if ( ! function_exists( 'messenger_get_fb_customer' ) ) {
	/**
	 * Thêm hoặc cập nhật thông tin khách hàng FB theo client_id.
	 *
	 * @param string $page_id
	 * @param string $client_id
	 * @return array|false
	 */
	function messenger_get_fb_customer( $page_id, $client_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bizgpt_inbox_customer';
		if ( empty( $client_id ) ) {
			return false;
		}
		// [2026-06-21 Johnny Chu] HOTFIX — guard missing legacy table via cached SELECT 1.
		// Blogs created after migration only have bizcity_facebook_customers.
		if ( ! _bizcity_legacy_tbl_exists( $table_name ) ) {
			return false;
		}

		$cache_key = 'inbox_customer_' . $client_id;
		$cached    = wp_cache_get( $cache_key, 'fb_customer' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$pages        = get_option( 'fb_pages_connected' );
		$target_id    = $page_id;
		$access_token = null;

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( $page['id'] === $target_id ) {
					$access_token = $page['access_token'];
					break;
				}
			}
		}

		$profile_url = 'https://graph.facebook.com/v23.0/' . rawurlencode( $client_id )
			. '?fields=first_name,last_name,profile_pic&access_token=' . rawurlencode( (string) $access_token );
		$response    = wp_remote_get( $profile_url, array( 'timeout' => 10 ) );
		$profile     = array();
		if ( ! is_wp_error( $response ) ) {
			$body    = wp_remote_retrieve_body( $response );
			$profile = json_decode( $body, true );
			if ( ! is_array( $profile ) ) {
				$profile = array();
			}
		}

		$name        = trim( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) );
		$profile['name'] = $name;
		$email       = $profile['email'] ?? '';
		$fb_link     = ! empty( $client_id ) ? 'https://facebook.com/' . $client_id : '';
		$profile_pic = $profile['profile_pic'] ?? '';

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE client_id = %s",
			$client_id
		) );

		if ( ! $exists ) {
			$wpdb->insert( $table_name, array(
				'client_id'   => $client_id,
				'name'        => $name,
				'email'       => $email,
				'fb_link'     => $fb_link,
				'profile_pic' => $profile_pic,
				'updated_at'  => current_time( 'mysql' ),
			) );
		}

		wp_cache_set( $cache_key, $profile, 'fb_customer', 12 * HOUR_IN_SECONDS );
		return $profile;
	}
}

// ── send_notice_to_zalo_admin ─────────────────────────────────────────────────

if ( ! function_exists( 'send_notice_to_zalo_admin' ) ) {
	/**
	 * Gửi thông báo về zalo admin cho chủ sau khi bot reply.
	 *
	 * @param string $msg
	 * @param string $client_id
	 * @param string $client_name
	 * @param string $blog_domain
	 * @param string $platform
	 */
	function send_notice_to_zalo_admin( $msg, $client_id, $client_name, $blog_domain = '', $platform = 'FB Hook' ) {
		$msg = "🧠 Em đã gửi trả lời tự động cho khách \n"
			. "🗨️ <b>Nội dung:</b> {$msg} \n\n"
			. "🌐 <b>Kiến thức tìm hiểu từ:</b> <code> {$blog_domain}</code>\n"
			. "🔑 <b>Nền tảng:</b> <code>{$platform}</code>\n"
			. "👤<b>Tên khách:</b> <code>{$client_name}</code>\n"
			. "👤 <b>Mã định danh của khách:</b> <code>{$client_id}</code>\n"
			. "Nếu sếp chưa hài lòng câu trả lời của em, hãy truy cập"
			. " <code>https://{$blog_domain}/wp-admin/admin.php?page=messenger-inbox-page</code>\n"
			. 'Em sẽ gửi tin nhắn cho khách giúp sếp 🧠';

		$reply_markup = '';
		$chat_ids     = function_exists( 'twf_list_client_ids_by_blog_id' )
			? twf_list_client_ids_by_blog_id( get_current_blog_id(), true )
			: array();

		foreach ( $chat_ids as $chat_id ) {
			if ( function_exists( 'twf_telegram_send_message' ) ) {
				twf_telegram_send_message( $chat_id, $msg, 'HTML', $reply_markup );
			}
		}
	}
}

// ── get_profile_by_client_id ──────────────────────────────────────────────────

if ( ! function_exists( 'get_profile_by_client_id' ) ) {
	/**
	 * Lấy profile từ bảng inbox_customer theo client_id.
	 *
	 * @param string $client_id
	 * @return array
	 */
	function get_profile_by_client_id( $client_id ) {
		global $wpdb;
		if ( empty( $client_id ) ) {
			return array();
		}

		$cache_key = 'inbox_customer_' . $client_id;
		$cached    = wp_cache_get( $cache_key, 'fb_customer' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$table_name = $wpdb->prefix . 'bizgpt_inbox_customer';
		// [2026-06-21 Johnny Chu] HOTFIX — same guard as messenger_get_fb_customer.
		if ( ! _bizcity_legacy_tbl_exists( $table_name ) ) {
			return array();
		}
		$profile    = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, email, fb_link, profile_pic FROM {$table_name} WHERE client_id = %s",
			$client_id
		), ARRAY_A );

		if ( $profile && is_array( $profile ) ) {
			wp_cache_set( $cache_key, $profile, 'fb_customer', 120 * HOUR_IN_SECONDS );
			return $profile;
		}

		return array();
	}
}

// ── fb_messenger_take_thread_control ─────────────────────────────────────────

if ( ! function_exists( 'fb_messenger_take_thread_control' ) ) {
	/**
	 * Dành lại quyền kiểm soát thread cho app của mình.
	 *
	 * @param string $page_access_token
	 * @param string $psid
	 * @return bool
	 */
	function fb_messenger_take_thread_control( $page_access_token, $psid ) {
		$url  = 'https://graph.facebook.com/v18.0/me/take_thread_control?access_token=' . rawurlencode( $page_access_token );
		$body = array(
			'recipient' => array( 'id' => $psid ),
			'metadata'  => 'BizGPT lấy lại quyền kiểm soát thread từ app khác',
		);

		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[FB] Lỗi khi take_thread_control: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code === 200;
	}
}

// ── fb_messenger_reply ────────────────────────────────────────────────────────

if ( ! function_exists( 'fb_messenger_reply' ) ) {
	/**
	 * Gửi reply về Messenger (text hoặc image).
	 *
	 * @param string $page_id
	 * @param string $client_id
	 * @param string $reply_text
	 */
	function fb_messenger_reply( $page_id, $client_id, $reply_text ) {
		$page_access_token = get_option( 'messenger_page_token' );
		if ( ! $page_access_token ) {
			return;
		}

		$profile     = function_exists( 'get_profile_by_client_id' ) ? get_profile_by_client_id( $client_id ) : array();
		$client_name = $profile['name'] ?? '';
		$url         = 'https://graph.facebook.com/v18.0/me/messages?access_token=' . rawurlencode( $page_access_token );

		if ( preg_match( '/https?:\/\/[^\s"]+\.(jpg|jpeg|png|gif|bmp|webp)|fbcdn\.net/i', $reply_text ) ) {
			$payload = array(
				'recipient' => array( 'id' => $client_id ),
				'message'   => array(
					'attachment' => array(
						'type'    => 'image',
						'payload' => array(
							'url'         => trim( $reply_text ),
							'is_reusable' => true,
						),
					),
				),
			);
		} else {
			$payload = array(
				'recipient' => array( 'id' => $client_id ),
				'message'   => array( 'text' => strip_tags( $reply_text ) ),
			);
		}

		$args = array(
			'body'        => wp_json_encode( $payload ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'timeout'     => 15,
			'redirection' => 5,
			'blocking'    => true,
		);

		wp_remote_post( $url, $args );

		if ( function_exists( 'bizgpt_log_to_inbox_when_send' ) ) {
			bizgpt_log_to_inbox_when_send( $client_id, $reply_text, $client_name );
		}
	}
}

// ── get_or_update_page_access_token ──────────────────────────────────────────

if ( ! function_exists( 'get_or_update_page_access_token' ) ) {
	/**
	 * Lấy hoặc cập nhật page access token theo fb_page_id.
	 *
	 * @param string $fb_page_id
	 * @return string
	 */
	function get_or_update_page_access_token( $fb_page_id ) {
		$pages = get_option( 'fb_pages_connected', array() );
		foreach ( $pages as $page ) {
			if ( $page['id'] == $fb_page_id && ! empty( $page['access_token'] ) ) {
				return $page['access_token'];
			}
		}

		$user_token = get_option( 'fb_user_token' );
		if ( ! $user_token ) {
			return '';
		}

		$response = wp_remote_get(
			'https://graph.facebook.com/v18.0/me/accounts?access_token=' . rawurlencode( $user_token ),
			array( 'timeout' => 10 )
		);
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $page ) {
				if ( $page['id'] == $fb_page_id && ! empty( $page['access_token'] ) ) {
					$pages[] = array(
						'id'           => $page['id'],
						'name'         => $page['name'] ?? '',
						'access_token' => $page['access_token'],
					);
					update_option( 'fb_pages_connected', $pages );
					return $page['access_token'];
				}
			}
		}

		return '';
	}
}
