<?php
/**
 * Legacy Functions from fb-messenger-hook.php
 * 
 * These functions are kept for backward compatibility with existing code.
 * They handle Messenger messages, images, referrals, and Facebook comments.
 * 
 * @package BizCity_Facebook_Bot
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format input data for BizGPT
 */
if ( ! function_exists( 'bizgpt_format_input_data' ) ) {
	function bizgpt_format_input_data( $page_id, $client_id, $client_profile, $message_text, $message_id, $timestamp, $attachment_urls = array() ) {
		return array(
			'event'         => 'message.create',
			'page_id'       => $page_id,
			'client_id'     => $client_id,
			'platform_type' => 'FB_MESS',
			'conversation'  => array(
				'conversation_id'   => "{$page_id}_{$client_id}",
				'client_name'       => $client_profile['name'] ?? '',
				'client_phone'      => $client_profile['phone'] ?? '',
				'last_message'      => $message_text ?: ( $attachment_urls[0] ?? '' ),
				'img_url'           => $attachment_urls[0] ?? '',
				'last_message_id'   => $message_id,
				'last_message_time' => $timestamp,
				'last_message_type' => 'client',
			),
			'message'       => array(
				'message_id'   => $message_id,
				'message_type' => 'client',
				'created_at'   => current_time( 'mysql' ),
			),
		);
	}
}

/**
 * Handle Messenger Message
 */
if ( ! function_exists( 'handle_messenger_message' ) ) {
	function handle_messenger_message( $page_id, $messaging ) {
		$client_id    = $messaging['sender']['id'] ?? '';
		$message      = $messaging['message'] ?? array();
		$message_text = $message['text'] ?? '';
		$attachments  = $message['attachments'] ?? array();
		$timestamp    = $messaging['timestamp'] ?? '';
		$referral     = $messaging['referral'] ?? array();

		$client_profile = function_exists( 'messenger_get_fb_customer' ) 
			? messenger_get_fb_customer( $page_id, $client_id ) 
			: array( 'name' => '' );

		$message_id      = $message['mid'] ?? '';
		$attachment_urls = array();
		foreach ( $attachments as $att ) {
			if ( ! empty( $att['payload']['url'] ) ) {
				$attachment_urls[] = $att['payload']['url'];
			}
		}

		$input_data = bizgpt_format_input_data( $page_id, $client_id, $client_profile, $message_text, $message_id, $timestamp, $attachment_urls );

		set_transient( 'hook_data', array(
			'user_id'     => 0,
			'client_id'   => $client_id,
			'session_id'  => '',
			'page_id'     => $page_id,
			'platform'    => 'FB_MESS',
			'client_name' => $client_profile['name'] ?? '',
		), 10 * MINUTE_IN_SECONDS );

		// Handle referral only
		if ( ! empty( $referral ) && empty( $message ) ) {
			if ( function_exists( 'back_trace' ) ) {
				back_trace( 'NOTICE', '📌 Nhận referral không kèm tin nhắn' );
			}
			handle_referral( $messaging, $client_id, $page_id, '', array() );
			return;
		}

		// Prevent duplicate
		if ( $message_id && get_transient( "fb_msg_{$client_id}_{$message_id}" ) ) {
			return;
		}
		set_transient( "fb_msg_{$client_id}_{$message_id}", 1, 2 * MINUTE_IN_SECONDS );

		// Log inbox message
        /*
		if ( function_exists( 'bizgpt_log_inbox_msg' ) ) {
			bizgpt_log_inbox_msg( $input_data );
		}
        */
		// Handle image
		if ( ! empty( $attachment_urls ) ) {
			foreach ( $attachment_urls as $img_url ) {
				handle_messenger_image( $page_id, $client_id, $img_url );
			}
			return;
		}

		// Handle text
		if ( ! empty( $message_text ) ) {
			handle_messenger_text( $page_id, $client_id, $message_text, $input_data );
		}

		// Handle referral with message
		handle_referral( $messaging, $client_id, $page_id, $client_profile['name'] ?? '', $input_data );
	}
}

/**
 * Handle Messenger Text - Call bizgpt_chatbot_run_guest_flows
 */
if ( ! function_exists( 'handle_messenger_text' ) ) {
	function handle_messenger_text( $page_id, $client_id, $message_text, $input_data ) {
		if ( ! function_exists( 'bizgpt_chatbot_run_guest_flows' ) || ! function_exists( 'fb_messenger_reply' ) ) {
			return;
		}

		$client_context = '';
		$arr = bizgpt_chatbot_run_guest_flows( $message_text, 'FB_MESS', $input_data, $client_context );

		if ( is_array( $arr ) ) {
			foreach ( $arr as $item ) {
				if ( ! empty( $item['msg'] ) ) {
					$msg_text = html_entity_decode( wp_strip_all_tags( str_replace( '<br>', "\n", $item['msg'] ) ), ENT_QUOTES, 'UTF-8' );
					fb_messenger_reply( $page_id, $client_id, 'AI: ' . $msg_text );
				}
			}
		}
	}
}

/**
 * Handle Messenger Image
 */
if ( ! function_exists( 'handle_messenger_image' ) ) {
	function handle_messenger_image( $page_id, $client_id, $img_url ) {
		if ( ! function_exists( 'fb_messenger_reply' ) ) {
			return;
		}

		$img_hash      = md5( $img_url );
		$transient_key = 'fb_img_' . $client_id . '_' . $img_hash;

		if ( get_transient( $transient_key ) ) {
			$reply_text = 'Bạn vừa gửi 1 hình ảnh trùng lặp trong vòng dưới 2 phút. Tôi chưa được cho phép để giải thích về ảnh liên tục.!';
			fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );
			return;
		}
		set_transient( $transient_key, 1, 2 * MINUTE_IN_SECONDS );

		$reply_text = 'Dạ. Bạn vừa gửi 1 hình ảnh. ';
		fb_messenger_reply( $page_id, $client_id, 'AI: ' . $reply_text );

		// Process with GPT Vision if available
		$api_key = get_option( 'twf_openai_api_key' );

		if ( function_exists( 'send_chatbot_chatgpt_upload_files' ) && function_exists( 'chatbot_chatgpt_custom_gpt_call_api' ) && ! empty( $api_key ) ) {
			$facebook_access_token = esc_attr( get_option( 'twf_facebook_access_token', '' ) );
			$facebook_page_id      = esc_attr( get_option( 'twf_facebook_page_id', '' ) );

			$img_response = send_chatbot_chatgpt_upload_files( $img_url, $api_key, $facebook_page_id, $facebook_access_token );
			$file_id      = $img_response['id'] ?? '';
			$file_type    = 'vision';
			$file_ids     = array();
			$file_ids[]   = $file_id;
			$file_ids[ $file_id ] = $file_type;

			$assistant_id   = 'asst_O85cidOL5HdRvUaSOETinlEE';
			$message        = 'Ảnh gì đây: ' . $file_id;
			$client_context = '';
			$thread_id      = '';
			$session_id     = '';
			$user_id        = 0;

			$response = chatbot_chatgpt_custom_gpt_call_api( $api_key, $client_context . $message, $assistant_id, $thread_id, $session_id, $user_id, $page_id, $file_ids );

			if ( ! empty( $response ) ) {
				fb_messenger_reply( $page_id, $client_id, 'AI: ' . $response );
			}
		}
	}
}

/**
 * Handle Referral - Call bizgpt_run_flow_steps
 */
if ( ! function_exists( 'handle_referral' ) ) {
	function handle_referral( $messaging, $client_id, $page_id, $client_name = '', $input_data = array() ) {
		$ref = $messaging['referral']['ref'] ?? $messaging['postback']['referral']['ref'] ?? '';
		if ( ! $ref ) {
			return;
		}

		$ref_decrypted = $ref;
		if ( function_exists( 'twf_decrypt_chat_id' ) ) {
			$ref_decrypted = twf_decrypt_chat_id( $ref );
			if ( ! $ref_decrypted ) {
				return;
			}
		}

		// Spam lock (3 minutes)
		$lock_key = 'bizgpt_ref_lock_' . md5( $page_id . '|' . $client_id . '|' . $ref_decrypted );
		if ( get_transient( $lock_key ) && get_current_blog_id() === '418' ) {
			return;
		}
		set_transient( $lock_key, 1, 3 * MINUTE_IN_SECONDS );

		if ( function_exists( 'bizgpt_run_flow_steps' ) && function_exists( 'fb_messenger_reply' ) ) {
			// Guard: bizgpt_run_flow_steps requires int flow_id. Skip for
			// Ref_Codec tokens like `camp_<token>` (handled by CampaignTracker).
			$flow_id_int = is_numeric( $ref_decrypted ) ? (int) $ref_decrypted : 0;
			if ( $flow_id_int <= 0 ) {
				return;
			}
			$arr = bizgpt_run_flow_steps( $flow_id_int, $input_data );
			if ( is_array( $arr ) ) {
				foreach ( $arr as $item ) {
					if ( ! empty( $item['msg'] ) ) {
						fb_messenger_reply( $page_id, $client_id, 'AI: ' . $item['msg'] );
					}
				}
			}
		}
	}
}

/**
 * Handle Facebook Feed Event (Comments)
 */
if ( ! function_exists( 'handle_facebook_feed_event' ) ) {
	function handle_facebook_feed_event( $page_id, $change ) {
		$value = $change['value'] ?? array();

		if ( ( $value['item'] ?? '' ) !== 'comment' || empty( $value['message'] ) ) {
			return;
		}

		$comment_id_parts = explode( '_', $value['comment_id'] ?? '' );
		$comment_id       = end( $comment_id_parts );
		$message          = $value['message'];
		$from_name        = $value['from']['name'] ?? 'Người dùng';
		$from_id          = $value['from']['id'] ?? '';
		$post_id          = $value['post_id'] ?? '';

		// Prevent duplicate
		$transient_key = 'fb_comment_' . $comment_id;
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 3 * MINUTE_IN_SECONDS );

		// Skip if comment from page itself
		if ( $from_id === $page_id ) {
			error_log( "[FB] Bỏ qua comment do chính page tạo ra (ID: $from_id)" );
			return;
		}

		// Get access token
		$pages        = get_option( 'fb_pages_connected' );
		$access_token = null;

		if ( is_array( $pages ) ) {
			foreach ( $pages as $page ) {
				if ( ( $page['id'] ?? '' ) === $page_id ) {
					$access_token = $page['access_token'] ?? null;
					break;
				}
			}
		}

		if ( ! $access_token ) {
			return;
		}

		// Check post type
		$post_type     = 'feed';
		$check_url     = "https://graph.facebook.com/v18.0/{$post_id}?fields=id,permalink_url,type,message,description,story&access_token={$access_token}";
		$check_response = wp_remote_get( $check_url );
		$check_data    = json_decode( wp_remote_retrieve_body( $check_response ), true );

		if ( ! empty( $check_data['type'] ) && $check_data['type'] === 'video' ) {
			$post_type = 'live_video';
		}

		$post_caption = $check_data['message'] ?? $check_data['description'] ?? $check_data['story'] ?? '';

		// Generate AI reply
		$ai_reply = '';
		if ( function_exists( 'bizgpt_router_comment_flow' ) ) {
			$ai_reply = bizgpt_router_comment_flow( $message, $post_caption, $page_id, $from_id, $from_name );
		}

		if ( empty( $ai_reply ) ) {
			$ai_reply = 'Cảm ơn bạn đã để lại bình luận! Chúng tôi sẽ hỗ trợ bạn ngay.';
		}

		// Send comment reply
		if ( function_exists( 'fb_messenger_reply_comment' ) ) {
			fb_messenger_reply_comment( $comment_id, $ai_reply, $access_token );
		}

		// Send notification to admin
		$notification = 'Khách đã nhắn bình luận: ' . $message . "\n\n" . 'AI đã trả lời: ' . $ai_reply;
		$blog_domain  = $_SERVER['HTTP_HOST'] ?? '';

		if ( function_exists( 'send_notice_to_zalo_admin' ) ) {
			send_notice_to_zalo_admin( $notification, $from_id, $from_name, $blog_domain, $post_type . ' Comment' );
		}

		// Log comment
		if ( function_exists( 'bizgpt_log_comment_ai' ) ) {
			bizgpt_log_comment_ai( array(
				'page_id'     => $page_id,
				'post_id'     => $post_id,
				'post_type'   => $post_type,
				'comment_id'  => $value['comment_id'] ?? '',
				'parent_id'   => null,
				'sender_id'   => $from_id,
				'sender_name' => $from_name,
				'message'     => $message,
				'ai_reply'    => $ai_reply,
				'client_id'   => $_SERVER['HTTP_HOST'] ?? null,
			) );
		}
	}
}

/**
 * Replace placeholders in text
 */
if ( ! function_exists( 'bizgpt_replace_placeholders' ) ) {
	function bizgpt_replace_placeholders( $text, $placeholders = array() ) {
		foreach ( $placeholders as $key => $value ) {
			$text = str_replace( "{{$key}}", $value, $text );
		}
		return $text;
	}
}

/**
 * Router for comment flow
 */
if ( ! function_exists( 'bizgpt_router_comment_flow' ) ) {
	function bizgpt_router_comment_flow( $message_text, $post_caption, $page_id, $client_id = '', $from_name = '' ) {
		$api_key      = get_option( 'twf_openai_api_key' );
		$router       = bizgpt_parse_comment_flow( $message_text );
		$placeholders = array(
			'customer_name' => $from_name ?: 'bạn',
		);

		$prompt = "Khách hàng tên $from_name vừa bình luận trên bài viết: \"$message_text\". Chủ đề được bình luận là: \"$post_caption\". Hãy phản hồi lịch sự, chuyên nghiệp và hữu ích.";

		$router_key = is_array( $router ) ? ( $router['router'] ?? '' ) : $router;

		switch ( $router_key ) {
			case 'price_flow':
				$reply = get_option( 'bizgpt_reply_price_flow' ) ?: 'Chúng tôi có nhiều mức giá khác nhau tùy theo sản phẩm. Bạn có thể cho tôi biết bạn quan tâm đến sản phẩm nào không?';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				if ( function_exists( 'fb_messenger_reply' ) ) {
					fb_messenger_reply( $page_id, $client_id, $reply );
				}
				break;

			case 'shipping_flow':
				$reply = get_option( 'bizgpt_reply_shipping_flow' ) ?: 'Chúng tôi giao hàng nội thành miễn phí.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'contact_flow':
				$reply = get_option( 'bizgpt_reply_contact_flow' ) ?: 'Bạn có thể liên hệ với chúng tôi qua hotline nhé.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'demo_flow':
				$reply = get_option( 'bizgpt_reply_demo_flow' ) ?: 'Chúng tôi có bản dùng thử miễn phí nhé ạ.';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			case 'praise_flow':
				$reply = get_option( 'bizgpt_reply_praise_flow' ) ?: 'Hihi. Cảm ơn bạn đã khen!';
				$reply = bizgpt_replace_placeholders( $reply, $placeholders );
				break;

			default:
				$reply = '';
				if ( function_exists( 'chatbot_chatgpt_simple_prompt_for_comment' ) ) {
					$reply = chatbot_chatgpt_simple_prompt_for_comment( $api_key, $prompt );
				}
				break;
		}

		return $reply;
	}
}

/**
 * Parse comment flow
 */
if ( ! function_exists( 'bizgpt_parse_comment_flow' ) ) {
	function bizgpt_parse_comment_flow( $message_text ) {
		$text   = strtolower( (string) $message_text );
		$router = '';
		$reply  = '';

		if ( preg_match( '/\b(giá|bn tiền|nhiêu tiền|cost|ib|inbox|price)\b/u', $text ) ) {
			$router = 'price_flow';
		} elseif ( preg_match( '/\b(ship|vận chuyển|giao hàng|free ship|giao hang)\b/u', $text ) ) {
			$router = 'shipping_flow';
		} elseif ( preg_match( '/\b(sđt|số điện thoại|liên hệ|call|zalo)\b/u', $text ) ) {
			$router = 'contact_flow';
		} elseif ( preg_match( '/\b(dùng thử|test|demo)\b/u', $text ) ) {
			$router = 'demo_flow';
		}

		if ( empty( $router ) && ! empty( $message_text ) ) {
			$router = 'default_flow';
			if ( function_exists( 'bizgpt_parse_comment_flow_dynamic' ) ) {
				$reply = (string) bizgpt_parse_comment_flow_dynamic( $message_text );
			}
		}

		return array(
			'router' => $router ?: 'default_flow',
			'reply'  => $reply,
		);
	}
}

/**
 * Get reply by router
 */
if ( ! function_exists( 'bizgpt_comment_flow_reply_by_router' ) ) {
	function bizgpt_comment_flow_reply_by_router( $router, $data_hook = array() ) {
		$router = trim( $router );

		switch ( $router ) {
			case 'price_flow':
				return 'Dạ bạn cần hỏi giá sản phẩm nào ạ? Bạn gửi tên sản phẩm hoặc ảnh giúp mình nhé.';

			case 'shipping_flow':
				return 'Dạ shop có giao hàng toàn quốc. Bạn cho mình xin tỉnh/thành + quận/huyện để mình báo phí và thời gian giao dự kiến nhé.';

			case 'contact_flow':
				return 'Dạ bạn cần liên hệ nhanh, bạn để lại SĐT hoặc nhắn Zalo giúp mình nhé. CSKH sẽ hỗ trợ ngay.';

			case 'demo_flow':
				return 'Dạ bạn muốn dùng thử/ demo, bạn cho mình biết nhu cầu cụ thể để mình hướng dẫn gói phù hợp nhé.';

			default:
				return '';
		}
	}
}

/**
 * Reply to Facebook comment
 */
if ( ! function_exists( 'fb_messenger_reply_comment' ) ) {
	function fb_messenger_reply_comment( $comment_id, $message, $access_token = '' ) {
		// Try public comment first
		$url      = "https://graph.facebook.com/v23.0/{$comment_id}/comments";
		$response = wp_remote_post( $url, array(
			'body' => array(
				'message'      => $message,
				'access_token' => $access_token,
			),
		) );

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Fallback to private reply
		if ( is_wp_error( $response ) || isset( $data['error'] ) ) {
			$url_private      = "https://graph.facebook.com/v18.0/{$comment_id}/private_replies";
			$response_private = wp_remote_post( $url_private, array(
				'body'    => array(
					'message'      => $message,
					'access_token' => $access_token,
				),
				'timeout' => 20,
			) );
		}
	}
}

/**
 * Simple prompt for comment — routes through BizCity LLM Router
 */
if ( ! function_exists( 'chatbot_chatgpt_simple_prompt_for_comment' ) ) {
	function chatbot_chatgpt_simple_prompt_for_comment( $api_key, $prompt, $model = 'gpt-4.1-nano' ) {
		if ( ! $prompt ) {
			return '';
		}

		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$messages = array(
				array(
					'role'    => 'system',
					'content' => 'Bạn là một trợ lý AI thân thiện, giúp doanh nghiệp trả lời bình luận của khách trên Facebook một cách chuyên nghiệp, ngắn gọn và lịch sự.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);
			$result = bizcity_llm_chat( $messages, array( 'purpose' => 'executor', 'timeout' => 20 ) );
			return $result['message'] ?? '';
		}

		// Fallback: không có LLM Router thì trả về rỗng, tránh gọi trực tiếp OpenAI
		return '';
	}
}
