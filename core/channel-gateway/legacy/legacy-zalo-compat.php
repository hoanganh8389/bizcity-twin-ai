<?php
/**
 * Channel Gateway — Legacy Zalo / Inbox Compat Functions
 *
 * Ported from mu-plugins/backup/zalo/functions.php.
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

// ── bizgpt_log_inbox_admin_msg ────────────────────────────────────────────────

if ( ! function_exists( 'bizgpt_log_inbox_admin_msg' ) ) {
	/**
	 * Log tin nhắn vào global_inbox_admin.
	 *
	 * @param array $data
	 */
	function bizgpt_log_inbox_admin_msg( $data = array() ) {
		global $globaldb;
		if ( ! isset( $globaldb ) ) {
			return;
		}

		$tbl          = 'global_inbox_admin';
		$conversation = $data['conversation'] ?? array();
		$comment      = $data['comment']      ?? array();
		$message      = $data['message']      ?? array();

		$row = array(
			'client_id'     => substr( (string) ( $data['client_id'] ?? '' ), 0, 32 ),
			'client_name'   => substr( (string) ( $conversation['client_name'] ?? '' ), 0, 255 ),
			'platform_type' => substr( (string) ( $data['platform_type'] ?? '' ), 0, 20 ),
			'page_id'       => substr( (string) ( $data['page_id'] ?? '' ), 0, 40 ),
			'blog_id'       => get_current_blog_id(),
			'message_id'    => substr( (string) ( $message['message_id'] ?? '' ), 0, 64 ),
			'message_text'  => sanitize_text_field(
				$message['message_text'] ?? ( $conversation['last_message'] ?? ( $comment['message'] ?? '' ) )
			),
			'message_type'  => $message['message_type'] ?? ( $conversation['last_message_type'] ?? '' ),
			'created_at'    => current_time( 'mysql' ),
			'meta'          => wp_json_encode( $data ),
		);

		$globaldb->insert( $tbl, $row );
	}
}

// ── bizgpt_log_inbox_msg ──────────────────────────────────────────────────────

if ( ! function_exists( 'bizgpt_log_inbox_msg' ) ) {
	/**
	 * Log tin nhắn vào bảng wp_{n}_bizgpt_inbox (per-blog).
	 *
	 * @param array $data
	 */
	function bizgpt_log_inbox_msg( $data = array() ) {
		global $wpdb;
		$tbl          = $wpdb->prefix . 'bizgpt_inbox';
		$conversation = $data['conversation'] ?? array();
		$comment      = $data['comment']      ?? array();
		$message      = $data['message']      ?? array();

		$message_id = $message['message_id'] ?? '';
		if ( ! empty( $message_id ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl} WHERE message_id = %s",
				$message_id
			) );
			if ( $exists ) {
				return;
			}
		}

		$msg_url = $conversation['msg_url'] ?? '';
		if ( ! empty( $msg_url ) ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl} WHERE message_text LIKE %s",
				'%' . $wpdb->esc_like( $msg_url ) . '%'
			) );
			if ( $exists ) {
				return;
			}
		}

		$message_text = sanitize_text_field(
			$message['message_text'] ?? ( $conversation['last_message'] ?? ( $comment['message'] ?? '' ) )
		);
		if ( empty( $message_text ) ) {
			return;
		}

		$row = array(
			'client_id'     => substr( (string) ( $data['client_id'] ?? '' ), 0, 32 ),
			'client_name'   => substr( (string) ( $conversation['client_name'] ?? '' ), 0, 255 ),
			'platform_type' => substr( (string) ( $data['platform_type'] ?? '' ), 0, 20 ),
			'page_id'       => substr( (string) ( $data['page_id'] ?? '' ), 0, 40 ),
			'message_id'    => substr( (string) ( $message['message_id'] ?? '' ), 0, 64 ),
			'message_text'  => $message_text,
			'message_type'  => $message['message_type'] ?? ( $conversation['last_message_type'] ?? '' ),
			'created_at'    => current_time( 'mysql' ),
			'meta'          => wp_json_encode( $data ),
		);

		$wpdb->insert( $tbl, $row );
	}
}

// ── bizgpt_check_zalo_admin_permission ───────────────────────────────────────

if ( ! function_exists( 'bizgpt_check_zalo_admin_permission' ) ) {
	/**
	 * Kiểm tra client_id có quyền quản trị blog không.
	 *
	 * @param string   $client_id
	 * @param int|null $blog_id
	 * @return string|null blog_id nếu có quyền, null nếu không
	 */
	function bizgpt_check_zalo_admin_permission( $client_id, $blog_id = null ) {
		global $globaldb;
		if ( empty( $client_id ) || ! isset( $globaldb ) ) {
			return false;
		}

		$blog_id = $blog_id ? (int) $blog_id : get_current_blog_id();

		return $globaldb->get_var( $globaldb->prepare(
			'SELECT blog_id FROM global_user_admin WHERE client_id = %s ORDER BY updated_at DESC',
			$client_id
		) );
	}
}

// ── bizgpt_prompt_select_site_to_admin ───────────────────────────────────────

if ( ! function_exists( 'bizgpt_prompt_select_site_to_admin' ) ) {
	/**
	 * Gửi prompt chọn website quản trị nếu có nhiều site.
	 *
	 * @param string $client_id
	 * @return mixed
	 */
	function bizgpt_prompt_select_site_to_admin( $client_id ) {
		$sites = function_exists( 'bizgpt_get_admin_sites_by_client' )
			? bizgpt_get_admin_sites_by_client( $client_id )
			: array();

		if ( empty( $sites ) ) {
			if ( function_exists( 'twf_telegram_send_message' ) ) {
				return twf_telegram_send_message( 'zalo_' . $client_id, 'Bạn chưa được cấp quyền quản trị website nào.' );
			}
			return null;
		}

		if ( count( $sites ) === 1 ) {
			return $sites[0]->blog_id;
		}

		$msg = 'Bạn đang quản trị ' . count( $sites ) . " website. Vui lòng nhắn cho tôi tên miền để chọn:\n\n";
		foreach ( $sites as $site ) {
			$msg .= '- ' . $site->domain . "\n";
		}
		$msg .= "\nVí dụ: Tôi muốn quản trị web `chaychualanh.com`";

		if ( function_exists( 'twf_telegram_send_message' ) ) {
			return twf_telegram_send_message( 'zalo_' . $client_id, $msg );
		}

		return null;
	}
}

// ── bizgpt_find_blog_id_by_domain ─────────────────────────────────────────────

if ( ! function_exists( 'bizgpt_find_blog_id_by_domain' ) ) {
	/**
	 * Tìm blog_id theo domain người dùng nhắn.
	 *
	 * @param string $client_id
	 * @param string $domain
	 * @return string|null
	 */
	function bizgpt_find_blog_id_by_domain( $client_id, $domain ) {
		global $globaldb;
		if ( ! isset( $globaldb ) ) {
			return null;
		}
		return $globaldb->get_var( $globaldb->prepare(
			'SELECT blog_id FROM global_user_admin WHERE client_id = %s AND domain LIKE %s LIMIT 1',
			$client_id,
			'%' . $domain . '%'
		) );
	}
}

// ── bizgpt_get_admin_sites_by_client ─────────────────────────────────────────

if ( ! function_exists( 'bizgpt_get_admin_sites_by_client' ) ) {
	/**
	 * Lấy danh sách site theo client_id.
	 *
	 * @param string $client_id
	 * @return array
	 */
	function bizgpt_get_admin_sites_by_client( $client_id ) {
		global $globaldb;
		if ( ! isset( $globaldb ) ) {
			return array();
		}
		$results = $globaldb->get_results( $globaldb->prepare(
			'SELECT blog_id, domain FROM global_user_admin WHERE client_id = %s ORDER BY updated_at DESC',
			$client_id
		) );
		return is_array( $results ) ? $results : array();
	}
}

// ── twf_update_client_login_time ─────────────────────────────────────────────

if ( ! function_exists( 'twf_update_client_login_time' ) ) {
	/**
	 * Cập nhật updated_at mỗi khi thực hiện 1 yêu cầu.
	 *
	 * @param string $client_id
	 */
	function twf_update_client_login_time( $client_id ) {
		global $globaldb;
		if ( empty( $client_id ) || ! isset( $globaldb ) ) {
			return;
		}
		$globaldb->update(
			'global_user_admin',
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'client_id' => $client_id )
		);
	}
}

// ── bizgpt_generate_zalo_admin_login_url ─────────────────────────────────────

if ( ! function_exists( 'bizgpt_generate_zalo_admin_login_url' ) ) {
	/**
	 * Sinh URL hoặc blog_id để admin đăng nhập qua Zalo.
	 *
	 * @param string $client_id
	 * @param string $domain
	 * @return int|false
	 */
	function bizgpt_generate_zalo_admin_login_url( $client_id, $domain ) {
		global $globaldb;
		if ( empty( $client_id ) || empty( $domain ) || ! isset( $globaldb ) ) {
			return false;
		}

		$domain_clean = preg_replace( '#^https?://(www\.)?#', '', rtrim( $domain, '/' ) );

		$row = $globaldb->get_row( $globaldb->prepare(
			"SELECT blog_id, updated_at
			   FROM global_user_admin
			  WHERE client_id = %s AND REPLACE(domain, 'https://', '') = %s
			  ORDER BY updated_at DESC
			  LIMIT 1",
			$client_id,
			$domain_clean
		) );

		if ( $row && ! empty( $row->blog_id ) ) {
			if ( function_exists( 'update_zalo_option' ) ) {
				update_zalo_option( $client_id, (int) $row->blog_id );
			}
			return (int) $row->blog_id;
		}

		return false;
	}
}

// ── twf_list_sites_by_client_id ───────────────────────────────────────────────

if ( ! function_exists( 'twf_list_sites_by_client_id' ) ) {
	/**
	 * Trả về chuỗi danh sách web theo client_id (dùng reply Zalo).
	 *
	 * @param string $client_id
	 * @return string
	 */
	function twf_list_sites_by_client_id( $client_id ) {
		global $globaldb;
		if ( empty( $client_id ) ) {
			return 'Không tìm thấy client_id.';
		}
		if ( ! isset( $globaldb ) ) {
			return 'Database chưa khởi tạo.';
		}

		$results = $globaldb->get_results( $globaldb->prepare(
			'SELECT domain, blog_id FROM global_user_admin WHERE client_id = %s ORDER BY updated_at DESC',
			$client_id
		) );

		if ( empty( $results ) ) {
			return 'Sếp chưa được cấp quyền quản trị bất kỳ website nào.';
		}

		$msg = "🧭 Sếp đang quản trị các website sau:\n\n";
		foreach ( $results as $row ) {
			$msg .= '🌐 ' . $row->domain . "\n";
		}
		return $msg;
	}
}

// ── twf_list_client_ids_by_blog_id ────────────────────────────────────────────

if ( ! function_exists( 'twf_list_client_ids_by_blog_id' ) ) {
	/**
	 * Lấy danh sách client_id theo blog_id (dùng để gửi thông báo).
	 *
	 * @param int  $blog_id
	 * @param bool $force_refresh
	 * @return array
	 */
	function twf_list_client_ids_by_blog_id( $blog_id, $force_refresh = false ) {
		if ( empty( $blog_id ) ) {
			$blog_id = get_current_blog_id();
		}

		$cache_key   = 'zalo_list_client_ids_blog_' . (int) $blog_id;
		$cache_group = 'zalo_list_client_ids_blog';

		if ( ! $force_refresh ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
			$transient = get_transient( $cache_key );
			if ( false !== $transient && is_array( $transient ) ) {
				wp_cache_set( $cache_key, $transient, $cache_group, 10 * MINUTE_IN_SECONDS );
				return $transient;
			}
		}

		global $globaldb;
		$results = isset( $globaldb ) ? $globaldb->get_col( $globaldb->prepare(
			'SELECT DISTINCT client_id FROM global_user_admin WHERE blog_id = %d ORDER BY updated_at DESC',
			(int) $blog_id
		) ) : array();

		$client_ids = is_array( $results ) ? $results : array();
		set_transient( $cache_key, $client_ids, 12 * HOUR_IN_SECONDS );
		wp_cache_set( $cache_key, $client_ids, $cache_group, 10 * MINUTE_IN_SECONDS );

		return $client_ids;
	}
}

// ── twf_check_client_use_zalo ─────────────────────────────────────────────────

if ( ! function_exists( 'twf_check_client_use_zalo' ) ) {
	/**
	 * Kiểm tra client_id có dùng Zalo AI không.
	 *
	 * @param string $chat_id
	 * @param bool   $force_refresh
	 * @return string|false
	 */
	function twf_check_client_use_zalo( $chat_id, $force_refresh = false ) {
		$client_id   = str_replace( 'zalo_', '', $chat_id );
		$blog_id     = get_current_blog_id();
		$cache_key   = 'zalo_chatid_' . $client_id . '_' . $blog_id;
		$cache_group = 'zalo_chat_check';

		if ( ! $force_refresh ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( false !== $cached ) {
				return $cached;
			}
			$transient = get_transient( $cache_key );
			if ( false !== $transient ) {
				wp_cache_set( $cache_key, $transient, $cache_group, 10 * MINUTE_IN_SECONDS );
				return $transient;
			}
		}

		global $globaldb;
		$exists = isset( $globaldb ) ? $globaldb->get_var( $globaldb->prepare(
			'SELECT id FROM global_user_admin WHERE blog_id = %d AND client_id = %s LIMIT 1',
			$blog_id,
			$client_id
		) ) : null;

		$result = $exists ? $client_id : false;
		wp_cache_set( $cache_key, $result, $cache_group, 10 * MINUTE_IN_SECONDS );
		set_transient( $cache_key, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}
}

// ── fbm_search_product ────────────────────────────────────────────────────────

if ( ! function_exists( 'fbm_search_product' ) ) {
	/**
	 * Tìm sản phẩm qua WP_Query theo title.
	 *
	 * @param string $keyword
	 * @return WP_Query
	 */
	function fbm_search_product( $keyword ) {
		return new WP_Query( array(
			'post_type'      => 'product',
			's'              => $keyword,
			'posts_per_page' => 3,
		) );
	}
}

// ── sanitize_text_with_line_breaks ────────────────────────────────────────────

if ( ! function_exists( 'sanitize_text_with_line_breaks' ) ) {
	/**
	 * Sanitize text, giữ nguyên line breaks.
	 *
	 * @param string $input
	 * @return string
	 */
	function sanitize_text_with_line_breaks( $input ) {
		if ( function_exists( 'convertAnchorTags' ) ) {
			$input = convertAnchorTags( $input );
		}
		if ( function_exists( 'cleanAndFormatText' ) ) {
			$input = cleanAndFormatText( $input );
		}
		$input = str_replace( '"', '', $input );
		$input = str_replace( PHP_EOL, '\n', $input );
		return $input;
	}
}

// ── has_image_extension ───────────────────────────────────────────────────────

if ( ! function_exists( 'has_image_extension' ) ) {
	/**
	 * Kiểm tra URL có phải link ảnh không.
	 *
	 * @param string $url
	 * @return int|false
	 */
	function has_image_extension( $url ) {
		return preg_match( '/\.(jpg|jpeg|png|gif|webp)$/i', $url );
	}
}
