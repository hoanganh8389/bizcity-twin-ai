<?php
/**
 * Legacy Old Functions - Migrated from fb/ and messenger/ folders
 * 
 * This file contains functions from the old fb/ and messenger/ directories
 * that are still needed by the bizcity-facebook-bot plugin.
 * 
 * All functions are wrapped with if(!function_exists()) to prevent conflicts.
 * 
 * Source files (can be archived after migration):
 * - mu-plugins/fb/facebook_scheduler.php (commented out - not migrated)
 * - mu-plugins/fb/facebook_sendpost.php
 * - mu-plugins/messenger/functions.php
 * - mu-plugins/messenger/inbox.php
 * - mu-plugins/messenger/comment.php
 * - mu-plugins/messenger/comment_config.php
 * 
 * @package BizCity_Facebook_Bot
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================================
 * FROM: messenger/functions.php
 * ============================================================================ */

/**
 * Get FB Customer profile
 */
if ( ! function_exists( 'messenger_get_fb_customer' ) ) {
	function messenger_get_fb_customer( $page_id, $client_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'bizcity_facebook_customers';
		if ( empty( $client_id ) ) return false;

		// Check cache first
		$cache_key = 'inbox_customer_' . $client_id;
		$cached = wp_cache_get( $cache_key, 'fb_customer' );
		if ( $cached !== false && is_array( $cached ) ) return $cached;

		$pages = get_option( 'fb_pages_connected' );
		$target_id = $page_id;
		$access_token = null;

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( $page['id'] === $target_id ) {
					$access_token = $page['access_token'];
					break;
				}
			}
		}

		// PHASE 0.34 — prefer per-page token from `wp_*_bizcity_facebook_bots` (canonical, post-migration).
		if ( empty( $access_token ) && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( (string) $page_id );
			if ( $bot ) {
				$access_token = is_object( $bot ) ? ( $bot->page_access_token ?? '' ) : ( $bot['page_access_token'] ?? '' );
			}
		}

		// Get profile from Facebook
		$page_access_token = get_option( 'messenger_page_token' );
		$token = $access_token ?: $page_access_token;
		if ( empty( $token ) ) {
			error_log( "[fb] messenger_get_fb_customer: no access token for page {$page_id}" );
			return false;
		}
		$profile_url = "https://graph.facebook.com/v18.0/{$client_id}?fields=first_name,last_name,profile_pic&access_token=" . rawurlencode( $token );
		$response = wp_remote_get( $profile_url, array( 'timeout' => 8 ) );
		$profile = array();
		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			$profile = json_decode( $body, true );
			if ( ! is_array( $profile ) ) { $profile = array(); }
			if ( isset( $profile['error'] ) ) {
				error_log( '[fb] messenger_get_fb_customer Graph error: ' . wp_json_encode( $profile['error'] ) );
				$profile = array();
			}
		}

		// Get info from Facebook profile
		$name = trim( ( $profile['first_name'] ?? '' ) . ' ' . ( $profile['last_name'] ?? '' ) );
		$profile['name'] = $name ?? '';
		$email = $profile['email'] ?? '';
		$fb_link = ! empty( $client_id ) ? 'https://facebook.com/' . $client_id : '';
		$profile_pic = $profile['profile_pic'] ?? '';

		// Check if exists
		$wpdb->suppress_errors( true );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE client_id = %s", $client_id ) );
		$wpdb->suppress_errors( false );

		if ( ! $exists ) {
			// Insert
			$wpdb->insert( $table_name, array(
				'client_id'   => $client_id,
				'page_id'     => $page_id,
				'name'        => $name,
				'email'       => $email,
				'fb_link'     => $fb_link,
				'profile_pic' => $profile_pic,
			) );
		}

		// Set cache
		wp_cache_set( $cache_key, $profile, 'fb_customer', 12 * HOUR_IN_SECONDS );

		return $profile;
	}
}

/**
 * Send notice to Zalo admin
 */
if ( ! function_exists( 'send_notice_to_zalo_admin' ) ) {
	function send_notice_to_zalo_admin( $msg, $client_id, $client_name, $blog_domain = '', $platform = 'FB Hook' ) {
		$msg = "🧠 Em đã gửi trả lời tự động cho khách \n"
			. "🗨️ <b>Nội dung:</b> $msg \n\n"
			. "🌐 <b>Kiến thức tìm hiểu từ:</b> <code> $blog_domain</code>\n"
			. "🔑 <b>Nền tảng:</b> <code>$platform</code>\n"
			. "👤<b>Tên khách:</b> <code>$client_name</code>\n"
			. "👤 <b>Mã định danh của khách:</b> <code>$client_id</code>\n"
			. "Nếu sếp chưa hài lòng câu trả lời của em, hãy truy cập <code>https://$blog_domain/wp-admin/admin.php?page=messenger-inbox-page </code>\n"
			. "Em sẽ gửi tin nhắn cho khách giúp sếp 🧠";

		$reply_markup = '';
		if ( function_exists( 'twf_list_client_ids_by_blog_id' ) ) {
			$chat_ids = twf_list_client_ids_by_blog_id( get_current_blog_id(), true );
			foreach ( $chat_ids as $chat_id ) {
				if ( function_exists( 'twf_telegram_send_message' ) ) {
					twf_telegram_send_message( $chat_id, $msg, 'HTML', $reply_markup );
				}
			}
		}
	}
}

/**
 * Get profile by client_id
 */
if ( ! function_exists( 'get_profile_by_client_id' ) ) {
	function get_profile_by_client_id( $client_id ) {
		global $wpdb;
		if ( empty( $client_id ) ) return array();

		$cache_key = 'inbox_customer_' . $client_id;
		$cached = wp_cache_get( $cache_key, 'fb_customer' );
		if ( $cached !== false && is_array( $cached ) ) return $cached;

		// Try new table first
		$table_name = $wpdb->prefix . 'bizcity_facebook_customers';
		$wpdb->suppress_errors( true );
		$profile = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, email, fb_link, profile_pic FROM $table_name WHERE client_id = %s", $client_id
		), ARRAY_A );
		$wpdb->suppress_errors( false );

		// Fallback to old table
		if ( empty( $profile ) ) {
			$old_table = $wpdb->prefix . 'bizgpt_inbox_customer';
			$wpdb->suppress_errors( true );
			$profile = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, email, fb_link, profile_pic FROM $old_table WHERE client_id = %s", $client_id
			), ARRAY_A );
			$wpdb->suppress_errors( false );
		}

		if ( $profile && is_array( $profile ) ) {
			wp_cache_set( $cache_key, $profile, 'fb_customer', 120 * HOUR_IN_SECONDS );
			return $profile;
		}

		return array();
	}
}

/**
 * Take thread control from another app
 */
if ( ! function_exists( 'fb_messenger_take_thread_control' ) ) {
	function fb_messenger_take_thread_control( $page_access_token, $psid ) {
		$url = "https://graph.facebook.com/v18.0/me/take_thread_control?access_token=$page_access_token";
		$body = array(
			'recipient' => array( 'id' => $psid ),
			'metadata'  => 'BizGPT lấy lại quyền kiểm soát thread từ app khác',
		);

		$response = wp_remote_post( $url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => json_encode( $body ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[FB] ❌ Lỗi khi take_thread_control: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		error_log( "[FB] ✅ Đã gọi take_thread_control (code $code): " . wp_remote_retrieve_body( $response ) );
		return $code === 200;
	}
}

/**
 * Reply to Messenger - supports text and image
 */
if ( ! function_exists( 'fb_messenger_reply' ) ) {
	function fb_messenger_reply( $page_id, $client_id, $reply_text ) {
		$profile     = function_exists( 'get_profile_by_client_id' ) ? get_profile_by_client_id( $client_id ) : array();
		$client_name = $profile['name'] ?? '';

		// PHASE 0.34 — resolve PER-PAGE access token (canonical: bot DB → fb_pages_connected → legacy global).
		$page_access_token = '';
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			$bot = BizCity_Facebook_Bot_Database::instance()->get_bot_by_page_id( (string) $page_id );
			if ( $bot ) {
				$page_access_token = is_object( $bot ) ? ( $bot->page_access_token ?? '' ) : ( $bot['page_access_token'] ?? '' );
			}
		}
		if ( empty( $page_access_token ) ) {
			foreach ( (array) get_option( 'fb_pages_connected', array() ) as $p ) {
				if ( ( $p['id'] ?? '' ) == $page_id && ! empty( $p['access_token'] ) ) {
					$page_access_token = (string) $p['access_token'];
					break;
				}
			}
		}
		if ( empty( $page_access_token ) ) {
			$page_access_token = (string) get_option( 'messenger_page_token' );
		}

		if ( empty( $page_access_token ) ) {
			error_log( "[fb_messenger_reply] no access token for page {$page_id}" );
			do_action( 'bizcity_facebook_message_sent', array(
				'page_id' => (string) $page_id, 'user_id' => (string) $client_id,
				'message' => (string) $reply_text, 'sent_ok' => false,
				'error'   => 'no_token_for_page', 'platform' => 'FB_MESS',
			) );
			return false;
		}

		$url = "https://graph.facebook.com/v18.0/me/messages?access_token=" . $page_access_token;

		// Check if reply_text is an image URL
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
			'body'        => json_encode( $payload ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'timeout'     => 15,
			'redirection' => 5,
			'blocking'    => true,
		);

		$response = wp_remote_post( $url, $args );

		// PHASE 0.34 — emit outbound action so the new CRM (and gateway ledger if any subscriber)
		// can mirror this AI/manual reply. Safe no-op if no subscriber.
		if ( function_exists( 'do_action' ) ) {
			$mid       = '';
			$err       = '';
			$http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$body_arr  = is_wp_error( $response ) ? array() : (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$ok        = ! is_wp_error( $response ) && $http_code === 200 && empty( $body_arr['error'] );
			if ( $ok && ! empty( $body_arr['message_id'] ) ) {
				$mid = (string) $body_arr['message_id'];
			}
			if ( ! $ok ) {
				$err = is_wp_error( $response )
					? $response->get_error_message()
					: ( isset( $body_arr['error']['message'] ) ? (string) $body_arr['error']['message'] : ( 'http_' . $http_code ) );
				error_log( "[fb_messenger_reply] FAIL page={$page_id} psid={$client_id} http={$http_code} err={$err}" );
			}
			do_action( 'bizcity_facebook_message_sent', array(
				'page_id'     => (string) $page_id,
				'user_id'     => (string) $client_id,
				'message'     => (string) $reply_text,
				'message_id'  => $mid,
				'timestamp'   => (int) round( microtime( true ) * 1000 ),
				'platform'    => 'FB_MESS',
				'event'       => array(),
				'sent_ok'     => $ok,
				'http_code'   => $http_code,
				'error'       => $err,
				'contact_name'=> (string) $client_name,
			) );
			return $ok;
		}
	}
}

/**
 * Get or update page access token
 */
if ( ! function_exists( 'get_or_update_page_access_token' ) ) {
	function get_or_update_page_access_token( $fb_page_id ) {
		$pages = get_option( 'fb_pages_connected', array() );
		foreach ( $pages as $page ) {
			if ( $page['id'] == $fb_page_id && ! empty( $page['access_token'] ) ) {
				return $page['access_token'];
			}
		}

		$user_token = get_option( 'fb_user_token' );
		if ( ! $user_token ) return '';

		$response = wp_remote_get( "https://graph.facebook.com/v18.0/me/accounts?access_token=$user_token" );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['data'] ) ) {
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


/* ============================================================================
 * FROM: messenger/inbox.php
 * ============================================================================ */

/**
 * Messenger inbox page UI
 */
if ( ! function_exists( 'messenger_inbox_page' ) ) {
	function messenger_inbox_page() {
		?>
		<style>
			.bizgpt-messenger-root {top:0;left:0;right:0;bottom:0;z-index:9999;background:#f6f8fb;}
			.bizgpt-ms-sidebar {float:left;width:320px;max-width:100vw;height:95vh;background:#fff;box-shadow:1px 2px 10px #0001;overflow-y:auto;}
			.bizgpt-ms-sidebar-head {padding:28px 18px 12px 26px;font-size:15px;font-weight:700;border-bottom:1px solid #e5e9f2;background:#1859ab;color:#fff;}
			.bizgpt-ms-uitem {display:flex;align-items:center;padding:13px 8px;cursor:pointer;border-bottom:1px solid #f0f1f8;}
			.bizgpt-ms-uitem.active {background:#eaefff;}
			.bizgpt-ms-uavatar {width:46px;height:46px;border-radius:50%;background:#e2ecfb;color:#1f3495;display:flex;align-items:center;justify-content:center;font-size:27px;font-weight:700;margin-right:13px;position:relative;}
			.bizgpt-ms-uplatform {font-size:12px;border-radius:6px;padding:3px 8px 2px 8px;display:inline-block;background:#d1fade;color:#1b4600; margin-left:6px;}
			.bizgpt-ms-uplatform.fb {background:#e5dcfc;color:#321091;}
			.bizgpt-ms-uplatform.zalo {background:#eafafe;color:#1877f2;}
			.bizgpt-ms-uinfo {flex:1;}
			.bizgpt-ms-uname {font-weight:600;}
			.bizgpt-ms-uirow {font-size:12px;opacity:0.7;}
			.bizgpt-ms-content {margin-left:320px;height:95vh;display:flex;flex-direction:column;}
			.bizgpt-ms-c-title {font-size:20px;padding:26px 24px 18px 24px; border-bottom:1.5px solid #e4e8ef;background:#fafbfc;}
			.bizgpt-ms-c-thread {flex:1;overflow-y:auto;padding:24px;display:flex;flex-direction:column;}
			.bizgpt-ms-msg {display:flex;margin-bottom:9px;align-items:flex-end;}
			.bizgpt-ms-msg.user {flex-direction:row-reverse;}
			.bizgpt-ms-msg .bubble {padding:13px 22px;border-radius:22px;max-width:550px;}
			.bizgpt-ms-msg.user .bubble {background:#3182f6;color:#fff;border-bottom-right-radius:7px;margin-right:9px;}
			.bizgpt-ms-msg.bot .bubble {background:#7ed957;color:#133;border-bottom-left-radius:7px;margin-left:9px;}
			.bizgpt-ms-msg.page .bubble {background:#e4e8ef;color:#222;}
			.bizgpt-ms-msg .avatar {width:37px;height:37px;border-radius:50%;background:#e4eefd;display:flex;align-items:center;justify-content:center;font-size:18px;border:1.5px solid #e7edfa;}
			.bizgpt-ms-inputbar {padding:21px 20px;border-top:1.5px solid #e6ebf2;background:#fafbfc;display:flex;}
			.bizgpt-ms-input {flex:1;font-size:16px;border-radius:13px;padding:12px 18px;border:1px solid #e1e7ed;}
			.bizgpt-ms-btn {padding:11px 38px;font-size:17px;border-radius:11px;background:#1859ab;border:none;color:#fff;cursor:pointer;margin-left:12px;}
			@media (max-width:900px){.bizgpt-ms-content, .bizgpt-ms-sidebar{float:none;width:100vw;margin:0;height:98vh}.bizgpt-ms-content{margin-left:0 !important;}}
		</style>
		<div class="bizgpt-messenger-root">
			<div class="bizgpt-ms-sidebar">
				<div class="bizgpt-ms-sidebar-head">Danh bạ FB Messenger</div>
				<div id="bizgpt-users"></div>
			</div>
			<div class="bizgpt-ms-content">
				<div class="bizgpt-ms-c-title" id="bizgpt-chat-title"></div>
				<div class="bizgpt-ms-c-thread" id="bizgpt-thread"></div>
				<div class="bizgpt-ms-inputbar">
					<input type="text" class="bizgpt-ms-input" id="bizgpt-chat-input" placeholder="Nhập nội dung...">
					<button class="bizgpt-ms-btn" id="bizgpt-chat-send">Gửi</button>
				</div>
			</div>
		</div>
		<script>
		jQuery(function($){
			window.last_id = 0;
			let renderedMsgIds = new Set();
			let curr_client='', curr_platform='', curr_name='', curr_pageid='', last_msg_id=0;
			let msUserList = [], pollInterval = null;

			function getPlatformLabel(platform){
				if(platform==='ZALO_PERSONAL') return '<span class="bizgpt-ms-uplatform zalo">Zalo</span>';
				if(platform==='FB_MESS') return '<span class="bizgpt-ms-uplatform fb">Facebook</span>';
				return '<span class="bizgpt-ms-uplatform">'+platform+'</span>';
			}
			function getUAvatar(name,p){
				if(p==='ZALO_PERSONAL') return '<div class="bizgpt-ms-uavatar" style="background:#bfe6fc;color:#1877f2;">ZL</div>';
				if(p==='FB_MESS') return '<div class="bizgpt-ms-uavatar" style="background:#ded3f3;color:#623ebc;">FB</div>';
				return '<div class="bizgpt-ms-uavatar">'+(name?name[0]:'U')+'</div>';
			}
			function renderUserList(list){
				let html = "";
				list.forEach(function(u){
					let profile = u.profile || {};
					let avatarHtml = profile.profile_pic
						? '<img src="'+profile.profile_pic+'" class="bizgpt-ms-uavatar" style="object-fit:cover;width:46px;height:46px;border-radius:50%;margin-right:13px;">'
						: getUAvatar(u.client_name, u.platform_type);
					let name = profile.name || u.client_name || u.client_id;
					html += '<div class="bizgpt-ms-uitem" data-client="'+u.client_id+'" data-platform="'+u.platform_type+'" data-name="'+name+'" data-pageid="'+(u.page_id||'')+'">'
						+ avatarHtml
						+ '<div class="bizgpt-ms-uinfo">'
						+ '<div class="bizgpt-ms-uname">'+name+' '+getPlatformLabel(u.platform_type)+'</div>'
						+ '<div class="bizgpt-ms-uirow" style="color:#888;">'+(profile.email ? 'Email: '+profile.email+'' : '')+(name ? '<br>ID: '+u.client_id : '')+'</div>'
						+ '</div></div>';
				});
				$('#bizgpt-users').html(html || '<div style="padding:16px;font-size:15px;">Không có khách nào.</div>');
			}
			function highlightUser(cid,plat){ $('.bizgpt-ms-uitem').removeClass('active'); $('.bizgpt-ms-uitem[data-client="'+cid+'"][data-platform="'+plat+'"]').addClass('active'); }
			function showThreadTitle(name, plat, profile) {
				profile = profile || {};
				let emailHtml = profile.email ? '<div style="font-size:13px;color:#888;">Email: '+profile.email+'</div>' : '';
				let clientIdHtml = profile.client_id ? '<div style="font-size:13px;color:#888;">Client ID: '+profile.client_id+'</div>' : '';
				let fbLinkHtml = profile.fb_link ? '<div style="font-size:13px;"><a href="'+profile.fb_link+'" target="_blank">FB Link</a></div>' : '';
				$('#bizgpt-chat-title').html('<div>'+(name||curr_client)+' - '+getPlatformLabel(plat)+'</div>'+emailHtml+fbLinkHtml+clientIdHtml);
			}
			function loadHistory(cid, plat, name, pageid) {
				if(!pageid) { alert('Không xác định được page_id!'); return; }
				renderedMsgIds.clear();
				window.last_id = 0;
				$('#bizgpt-thread').html('<div style="color:#888;">Đang tải hội thoại...</div>');
				$.post(ajaxurl, {action:'messenger_pull_inbox_fn',pull:'history',client_id:cid,platform:plat,page_id:pageid}, function(d) {
					let html = '';
					let profile = d.profile || {};
					if (d.success && Array.isArray(d.messages)) d.messages.forEach(function(msg){
						if (!msg.id) return;
						html += renderMsg(msg);
						let id = parseInt(msg.id, 10);
						renderedMsgIds.add(id);
						if (id > window.last_id) window.last_id = id;
					});
					$('#bizgpt-thread').html(html);
					$('#bizgpt-thread').scrollTop(9999999);
					convertFbcdnLinksToImages('#bizgpt-thread');
					curr_client = cid; curr_platform = plat; curr_name = name;
					highlightUser(cid, plat);
					showThreadTitle(name, plat, profile);
				});
			}
			function renderMsg(msg){
				let type = msg.message_type=='client'?'user':(msg.message_type=='bot'?'bot':'page');
				let avatar = type=='user'?'🙎':(type=='bot'?'🤖':'🧑‍💼');
				return '<div class="bizgpt-ms-msg '+type+'"><div class="avatar">'+avatar+'</div><div class="bubble">'+((msg.message_text||'').replace(/\n/g,'<br>'))+'</div></div>';
			}
			function fetchUsers(){
				$.post(ajaxurl,{action:'messenger_pull_inbox_fn',pull:'users'},function(res){
					if(res.success&&res.data){ msUserList = res.data; renderUserList(msUserList); }
				});
			}
			window.bizgpt_waiting_reply = false;
			function pollNewMsg(){
				if(window.bizgpt_waiting_reply) return;
				if(!curr_client || !curr_platform || !curr_pageid) return;
				$.post(ajaxurl, {action:'messenger_pull_inbox_fn',pull:'new',client_id:curr_client,platform:curr_platform,last_id:window.last_id||0,page_id:curr_pageid}, function(d) {
					if(d.success && Array.isArray(d.data) && d.data.length) appendNewMsgs(d.data);
				});
			}
			function appendNewMsgs(msgs){
				if(!msgs||!msgs.length) return;
				let $tmp = $('<div></div>');
				msgs.forEach(function(msg){
					let id = msg.id ? parseInt(msg.id,10) : null;
					if (!id || renderedMsgIds.has(id)) return;
					$tmp.append(renderMsg(msg));
					renderedMsgIds.add(id);
					if (id > window.last_id) window.last_id = id;
				});
				convertFbcdnLinksToImages($tmp);
				$('#bizgpt-thread').append($tmp.children());
				$('#bizgpt-thread').scrollTop(9999999);
			}
			$(document).on('click', '.bizgpt-ms-uitem', function() {
				let cid = $(this).data('client'), plat = $(this).data('platform'), name = $(this).data('name'), pageid = $(this).data('pageid');
				if(cid===curr_client && plat===curr_platform && pageid===curr_pageid) return;
				curr_pageid = pageid;
				loadHistory(cid, plat, name, pageid);
			});
			$('#bizgpt-chat-send').click(function(){
				let msg = $('#bizgpt-chat-input').val().trim();
				if(!msg||!curr_client)return;
				$('#bizgpt-chat-input').val('').focus();
				window.bizgpt_waiting_reply = true;
				$.post(ajaxurl,{action:'messenger_send_admin_msg',client_id:curr_client,platform:curr_platform,client_name:curr_name,msg:msg,page_id:curr_pageid},function(res){
					if(res.success && res.data && res.data.id){
						let new_id = parseInt(res.data.id, 10);
						if (new_id > window.last_id) window.last_id = new_id;
						renderedMsgIds.add(new_id);
					}
					window.bizgpt_waiting_reply = false;
					if(!res.success) alert("Lỗi gửi tin!");
				});
			});
			$('#bizgpt-chat-input').on('keydown',function(e){ if(e.key=='Enter'){$('#bizgpt-chat-send').click();} });
			fetchUsers();
			setInterval(fetchUsers, 30000);
			setInterval(pollNewMsg, 2000);
			setTimeout(function(){
				let $first = $('.bizgpt-ms-uitem:first');
				if($first.length){
					let cid = $first.data('client'), plat = $first.data('platform'), name = $first.data('name'), pageid = $first.data('pageid');
					curr_pageid = pageid;
					loadHistory(cid, plat, name, pageid);
				}
			},1000);
			function convertFbcdnLinksToImages(containerSelector) {
				$(containerSelector).find('.bubble').each(function() {
					let html = $(this).html();
					if (/<img[^>]+src="https?:\/\/[^"]*\.fbcdn\.net[^"]*"/i.test(html)) return;
					html = html.replace(/(https?:\/\/[^\s"']*\.fbcdn\.net[^\s"']*)/gi, function(url) {
						return '<img src="'+url+'" style="max-width:320px;display:block;margin:8px 0;" />';
					});
					$(this).html(html);
				});
			}
		});
		</script>
		<?php
	}
}

/**
 * Log message to inbox when admin sends
 */
if ( ! function_exists( 'bizgpt_log_to_inbox_when_send' ) ) {
	function bizgpt_log_to_inbox_when_send( $client_id, $msg, $client_name = '' ) {
		$page_id = get_option( 'messenger_page_id' );
		global $wpdb;
		$client_id   = sanitize_text_field( $client_id ?? '' );
		$client_name = sanitize_text_field( $client_name ?? '' );
		$msg         = sanitize_textarea_field( $msg ?? '' );

		if ( ! $client_id && ! $msg ) {
			return;
		}

		$platform = 'FB_MESS';

		// Try new table first
		$tbl = $wpdb->prefix . 'bizcity_facebook_inbox';
		$wpdb->suppress_errors( true );
		$result = $wpdb->insert( $tbl, array(
			'client_id'     => $client_id,
			'client_name'   => $client_name,
			'page_id'       => $page_id,
			'message_id'    => uniqid( 'adminmsg' ),
			'message_text'  => $msg,
			'message_type'  => 'page',
			'sender_type'   => 'bot',
			'created_at'    => current_time( 'mysql' ),
		) );
		$wpdb->suppress_errors( false );

		// Fallback to old table if new doesn't exist
		if ( $result === false ) {
			$old_tbl = $wpdb->prefix . 'bizgpt_inbox';
			$wpdb->insert( $old_tbl, array(
				'client_id'      => $client_id,
				'client_name'    => $client_name,
				'platform_type'  => $platform,
				'page_id'        => $page_id,
				'message_id'     => uniqid( 'adminmsg' ),
				'message_text'   => $msg,
				'message_type'   => 'page',
				'created_at'     => current_time( 'mysql' ),
			) );
		}

		$current_blog_id = get_current_blog_id();
		$blog_detail = get_blog_details( $current_blog_id );
		$blog_domain = $blog_detail ? $blog_detail->domain : '';

		if ( function_exists( 'send_notice_to_zalo_admin' ) ) {
			send_notice_to_zalo_admin( $msg, $client_id, $client_name, $blog_domain );
		}
	}
}

/**
 * AJAX: Send admin message
 */
if ( ! function_exists( 'messenger_send_admin_msg_fn' ) ) {
	function messenger_send_admin_msg_fn() {
		$page_id = get_option( 'messenger_page_id' );
		global $wpdb;
		$client_id   = sanitize_text_field( $_POST['client_id'] ?? '' );
		$platform    = sanitize_text_field( $_POST['platform'] ?? '' );
		$client_name = sanitize_text_field( $_POST['client_name'] ?? '' );
		$msg         = 'Admin: ' . sanitize_textarea_field( $_POST['msg'] ?? '' );

		if ( ! $client_id || ! $platform || ! $msg || ! $page_id ) {
			wp_send_json_error( array( 'error' => 'Thiếu page_id hoặc trường cần thiết!' ) );
		}

		if ( $platform == 'FB_MESS' ) {
			fb_messenger_reply( $page_id, $client_id, $msg );
		} elseif ( $platform == 'ZALO_PERSONAL' ) {
			if ( function_exists( 'send_zalo_botbanhang' ) ) {
				send_zalo_botbanhang( $msg, $client_id );
			}
		}

		wp_send_json_success();
	}
}

/**
 * AJAX: Pull inbox messages
 */
if ( ! function_exists( 'messenger_pull_inbox_fn' ) ) {
	function messenger_pull_inbox_fn() {
		global $wpdb;
		$pull = $_POST['pull'] ?? '';

		// Try new table, fallback to old
		$tbl = $wpdb->prefix . 'bizcity_facebook_inbox';
		$wpdb->suppress_errors( true );
		$test = $wpdb->get_var( "SHOW TABLES LIKE '$tbl'" );
		$wpdb->suppress_errors( false );
		if ( ! $test ) {
			$tbl = $wpdb->prefix . 'bizgpt_inbox';
		}

		if ( $pull == 'users' ) {
			$wpdb->suppress_errors( true );
			// Check which column exists
			$has_platform_type = $wpdb->get_var( "SHOW COLUMNS FROM $tbl LIKE 'platform_type'" );
			$has_sender_type = $wpdb->get_var( "SHOW COLUMNS FROM $tbl LIKE 'sender_type'" );
			
			if ( $has_platform_type ) {
				$users = $wpdb->get_results(
					"SELECT * FROM $tbl
					WHERE id IN (
						SELECT MAX(id) FROM $tbl GROUP BY client_id, platform_type, page_id
					) AND platform_type IN ('FB_MESS')
					ORDER BY id DESC", ARRAY_A
				);
			} else {
				$users = $wpdb->get_results(
					"SELECT * FROM $tbl
					WHERE id IN (
						SELECT MAX(id) FROM $tbl GROUP BY client_id, page_id
					)
					ORDER BY id DESC", ARRAY_A
				);
			}
			$wpdb->suppress_errors( false );

			foreach ( $users as &$user ) {
				$user['profile'] = function_exists( 'get_profile_by_client_id' ) ? get_profile_by_client_id( $user['client_id'] ) : array();
			}
			wp_send_json_success( $users );
		}

		if ( $pull == 'thread' || $pull == 'history' ) {
			$client_id = sanitize_text_field( $_POST['client_id'] ?? '' );
			$platform  = sanitize_text_field( $_POST['platform'] ?? '' );
			$page_id   = sanitize_text_field( $_POST['page_id'] ?? '' );

			if ( ! $client_id || ! $platform || ! $page_id ) {
				wp_send_json_success( array() );
			}

			$wpdb->suppress_errors( true );
			$has_platform_type = $wpdb->get_var( "SHOW COLUMNS FROM $tbl LIKE 'platform_type'" );
			
			if ( $has_platform_type ) {
				$msgs = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM $tbl WHERE client_id=%s AND message_type IN ('page', 'client') AND platform_type=%s ORDER BY id ASC",
					$client_id, $platform
				), ARRAY_A );
			} else {
				$msgs = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM $tbl WHERE client_id=%s ORDER BY id ASC",
					$client_id
				), ARRAY_A );
			}
			$wpdb->suppress_errors( false );

			$profile = function_exists( 'get_profile_by_client_id' ) ? get_profile_by_client_id( $client_id ) : array();
			wp_send_json_success( array(
				'messages'  => $msgs,
				'profile'   => $profile,
				'client_id' => $client_id,
			) );
		}

		if ( $pull == 'new' ) {
			$client_id = sanitize_text_field( $_POST['client_id'] ?? '' );
			$platform  = sanitize_text_field( $_POST['platform'] ?? '' );
			$last_id   = intval( $_POST['last_id'] ?? 0 );
			$page_id   = sanitize_text_field( $_POST['page_id'] ?? '' );

			if ( ! $client_id || ! $platform || ! $page_id ) {
				wp_send_json_success( array() );
			}

			$wpdb->suppress_errors( true );
			$has_platform_type = $wpdb->get_var( "SHOW COLUMNS FROM $tbl LIKE 'platform_type'" );
			
			if ( $has_platform_type ) {
				$msgs = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM $tbl WHERE client_id=%s AND message_type IN ('page', 'client') AND platform_type=%s AND id>%d ORDER BY id ASC",
					$client_id, $platform, $last_id
				), ARRAY_A );
			} else {
				$msgs = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM $tbl WHERE client_id=%s AND id>%d ORDER BY id ASC",
					$client_id, $last_id
				), ARRAY_A );
			}
			$wpdb->suppress_errors( false );

			wp_send_json_success( $msgs );
		}

		wp_send_json_error();
	}
}

// Register AJAX actions
add_action( 'wp_ajax_messenger_send_admin_msg', 'messenger_send_admin_msg_fn' );
add_action( 'wp_ajax_messenger_pull_inbox_fn', 'messenger_pull_inbox_fn' );


/* ============================================================================
 * FROM: messenger/comment.php
 * ============================================================================ */

/**
 * Comment log page
 */
if ( ! function_exists( 'bizgpt_comment_log_page' ) ) {
	function bizgpt_comment_log_page() {
		global $wpdb;
		
		// Try new table first
		$table = $wpdb->prefix . 'bizcity_facebook_comments';
		$wpdb->suppress_errors( true );
		$test = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
		$wpdb->suppress_errors( false );
		if ( ! $test ) {
			$table = $wpdb->prefix . 'bizgpt_inbox_comment';
		}
		
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 100" );
		$wpdb->suppress_errors( false );

		echo '<div class="wrap"><h2>Nhật ký Comment</h2>
		<p>Danh sách các bình luận và phản hồi của AI. Bạn có thể click vào link để xem chi tiết từng bình luận trên Facebook.</p>
		<table class="widefat"><thead><tr>
			<th>Page/Post</th><th>Khách</th><th>Nội dung</th><th>AI reply</th><th>Chi tiết</th></tr></thead><tbody>';

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$fb_comment_link = "https://www.facebook.com/{$row->post_id}/comments/{$row->comment_id}";
				echo "<tr>
					<td>" . esc_html( $row->page_id ) . "<br><small>" . esc_html( $row->post_id ) . "</small></td>
					<td><strong>" . esc_html( $row->sender_name ) . "</strong><br>ID: " . esc_html( $row->sender_id ) . "</td>
					<td>" . esc_html( $row->message ) . "</td>
					<td>" . esc_html( $row->ai_reply ) . "</td>
					<td><a href='" . esc_url( $fb_comment_link ) . "' target='_blank'>Xem</a></td>
				</tr>";
			}
		} else {
			echo '<tr><td colspan="5">Chưa có dữ liệu.</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}

/**
 * Comment detail page
 */
if ( ! function_exists( 'bizgpt_comment_detail_page' ) ) {
	function bizgpt_comment_detail_page() {
		global $wpdb;
		$comment_id = sanitize_text_field( $_GET['comment_id'] ?? '' );
		
		// Try new table first
		$table = $wpdb->prefix . 'bizcity_facebook_comments';
		$wpdb->suppress_errors( true );
		$test = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
		$wpdb->suppress_errors( false );
		if ( ! $test ) {
			$table = $wpdb->prefix . 'bizgpt_inbox_comment';
		}

		$wpdb->suppress_errors( true );
		$logs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE comment_id = %s OR parent_comment_id = %s ORDER BY created_at ASC",
			$comment_id, $comment_id
		) );
		$wpdb->suppress_errors( false );

		echo "<div class='wrap'><h2>Chi tiết comment ID: " . esc_html( $comment_id ) . "</h2><ol>";
		if ( $logs ) {
			foreach ( $logs as $log ) {
				echo "<li><strong>" . esc_html( $log->sender_name ) . ":</strong> " . esc_html( $log->message ) . "<br>
					  <em>AI:</em> " . esc_html( $log->ai_reply ) . "</li><hr>";
			}
		}
		echo "</ol></div>";
	}
}

/**
 * Log comment and AI reply
 */
if ( ! function_exists( 'bizgpt_log_comment_ai' ) ) {
	function bizgpt_log_comment_ai( $args ) {
		global $wpdb;
		
		// Try new table first
		$table = $wpdb->prefix . 'bizcity_facebook_comments';
		$wpdb->suppress_errors( true );
		$test = $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
		$wpdb->suppress_errors( false );
		if ( ! $test ) {
			$table = $wpdb->prefix . 'bizgpt_inbox_comment';
		}

		// Skip if comment from page itself
		if ( $args['sender_id'] == $args['page_id'] ) return;

		$wpdb->suppress_errors( true );
		$wpdb->insert( $table, array(
			'bot_id'            => 0,
			'page_id'           => $args['page_id'] ?? '',
			'post_id'           => $args['post_id'] ?? '',
			'post_type'         => $args['post_type'] ?? 'feed',
			'comment_id'        => $args['comment_id'] ?? '',
			'parent_comment_id' => $args['parent_id'] ?? null,
			'sender_id'         => $args['sender_id'] ?? '',
			'sender_name'       => $args['sender_name'] ?? '',
			'message'           => $args['message'] ?? '',
			'ai_reply'          => $args['ai_reply'] ?? '',
		) );
		$wpdb->suppress_errors( false );
	}
}


/* ============================================================================
 * FROM: messenger/comment_config.php
 * ============================================================================ */

/**
 * Admin style fallback CSS
 */
if ( ! function_exists( 'bizcity_admin_style_fallback_css' ) ) {
	function bizcity_admin_style_fallback_css() {
		static $done = false;
		if ( $done ) return;
		$done = true;

		echo '<style id="bizcity-admin-fallback">
		.bc-wrap{max-width:1120px}
		.bc-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin:8px 0 14px}
		.bc-head h1{margin:0;font-size:20px;line-height:1.25}
		.bc-sub{margin-top:6px;color:#6b7280}
		.bc-actions{display:flex;gap:10px;flex-wrap:wrap}
		.bc-actions .button{border-radius:12px}
		.bc-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;align-items:start}
		@media (max-width: 1100px){.bc-grid{grid-template-columns:1fr}}
		.bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.bc-card h2{margin:0 0 10px;font-size:15px}
		.bc-badge{display:inline-flex;align-items:center;gap:8px;font-weight:700}
		.bc-dot{width:10px;height:10px;border-radius:999px;display:inline-block;background:#64748b}
		.bc-dot.green{background:#10b981}
		.bc-dot.blue{background:#1977f2}
		.bc-dot.amber{background:#f59e0b}
		.bc-help{background:#f9fafb;border:1px dashed #e5e7eb;border-radius:12px;padding:12px;margin:10px 0 0}
		.bc-help p{margin:6px 0;color:#374151}
		.bc-help code{background:#1118270d;padding:2px 6px;border-radius:8px}
		.bc-note{background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:12px;margin-top:12px}
		.bc-note b{color:#075985}
		.bc-divider{height:1px;background:#e5e7eb;margin:14px 0}
		.bc-field{margin:12px 0}
		.bc-field label{display:block;font-weight:700;margin-bottom:6px}
		.bc-field small{display:block;color:#6b7280;margin-top:6px}
		.bc-input, .bc-textarea, .bc-select{width:100%;max-width:100%;border-radius:10px}
		.bc-textarea{min-height:110px}
		.bc-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
		.bc-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:6px 10px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;color:#334155}
		.bc-table-wrap{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
		.bc-table-wrap table{margin:0;border:0}
		.bc-kbd{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
		.bc-danger{color:#b91c1c;font-weight:700}
		</style>';
	}
}

/**
 * Parse comment flow dynamic - match keywords
 */
if ( ! function_exists( 'bizgpt_parse_comment_flow_dynamic' ) ) {
	function bizgpt_parse_comment_flow_dynamic( $message_text ) {
		global $wpdb;
		$text = strtolower( $message_text );
		
		// Try new table first (but this might not exist - use comment_flows)
		$table = $wpdb->prefix . 'bizgpt_comment_flows';

		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( "SELECT * FROM $table" );
		$wpdb->suppress_errors( false );

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$keywords = array_map( 'trim', explode( ',', $row->keywords ) );
				foreach ( $keywords as $kw ) {
					if ( stripos( $text, $kw ) !== false ) {
						if ( function_exists( 'back_trace' ) ) {
							back_trace( 'NOTICE', 'Bắt được từ khóa: ' . $kw );
						}
						return $row->reply;
					}
				}
			}
		}

		return '';
	}
}


/* ============================================================================
 * FROM: fb/facebook_sendpost.php
 * ============================================================================ */

/**
 * Suggest Facebook posts using AI
 */
if ( ! function_exists( 'bizgpt_suggest_facebook_posts' ) ) {
	function bizgpt_suggest_facebook_posts( $chu_de, $hot_trend = '', $so_luong = 3 ) {
		$so_luong = intval( $so_luong );
		if ( $so_luong <= 0 || $so_luong > 10 ) $so_luong = 3;

		$args = array(
			'post_type'      => 'biz_facebook',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);
		$posts = get_posts( $args );
		$titles = array();
		foreach ( $posts as $pid ) {
			$title = get_the_title( $pid );
			if ( $title ) $titles[] = $title;
		}
		$context_titles = $titles ? "Các tiêu đề đã đăng gần đây: " . implode( "; ", $titles ) : '';

		$prompt = "Hãy gợi ý $so_luong bài đăng Facebook hấp dẫn về chủ đề: \"$chu_de\""
			. ( $hot_trend ? ", theo hot trend: \"$hot_trend\"" : "" )
			. ". $context_titles. Mỗi gợi ý gồm: tiêu đề, nội dung, hashtag và caption, mỗi bài tách rõ ràng, trình bày dễ copy-paste đăng lên fanpage. Đảm bảo sáng tạo, dễ viral, thu hút bình luận, không trùng tiêu đề cũ.";

		$api_key = get_option( 'twf_openai_api_key' );
		if ( function_exists( 'chatbot_chatgpt_call_omni_tele' ) ) {
			$ai_result = chatbot_chatgpt_call_omni_tele( $api_key, $prompt, true );
		} else {
			$ai_result = "AI function not available.";
		}

		$msg = "Dưới đây là $so_luong gợi ý bài đăng cho chủ đề \"$chu_de\":\n";
		$msg .= $ai_result;

		return $msg;
	}
}

/**
 * Send post to all connected FB pages
 */
if ( ! function_exists( 'fb_send_post' ) ) {
	function fb_send_post( $title, $message, $image_url = '' ) {
		$pages = get_option( 'fb_pages_connected' );
        back_trace( 'INFO', 'Sending FB post to pages: ' . print_r( $pages, true ) );
		if ( ! $pages || ! is_array( $pages ) ) return false;

		$fb_links = array();
		foreach ( $pages as $page ) {
			$page_id = $page['id'];
			$token   = $page['access_token'];
			$endpoint = "https://graph.facebook.com/$page_id/photos";

			$args = array(
				'body' => array(
					'caption'      => "$title\n\n$message",
					'url'          => $image_url,
					'access_token' => $token,
				),
			);

			$res = wp_remote_post( $endpoint, $args );
			if ( is_wp_error( $res ) ) {
				error_log( "FB POST ERROR for page $page_id: " . $res->get_error_message() );
				continue;
			}
			$body = wp_remote_retrieve_body( $res );
			$data = json_decode( $body, true );

			if ( ! empty( $data['post_id'] ) ) {
				$fb_link = "https://www.facebook.com/{$page_id}/posts/{$data['post_id']}";
				$fb_links[] = $fb_link;
			}
		}
		return $fb_links;
	}
}
