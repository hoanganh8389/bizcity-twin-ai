<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Channel_Gateway
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Legacy outbound send primitives — the four functions the rest of the stack
 * still calls after core/helper-legacy was archived (CORE-REDUCTION WP-12 R4).
 *
 *   biz_send_message()          — alias of twf_telegram_send_message()
 *   twf_telegram_send_message() — universal "send text to chat_id" entry; Zalo Bot,
 *                                  WEBCHAT and ADMINCHAT are routed by the
 *                                  `twf_send_message_override` filter (bootstrap.php
 *                                  priority 7, bizcity-zalo-bot priority 7)
 *   twf_telegram_send_photo()   — same for images
 *   bizgpt_zalo_format()        — strip Markdown/LaTeX for Zalo plain text
 *
 * Callers: Channel Gateway adapters (Telegram, Zalo Hotline), Gateway Sender,
 * knowledge notifications, bundled bizcity-zalo-bizcity / bizcity-zalo-bot /
 * bizcity-facebook-bot, the bizcity-web mu-plugin (unguarded call on site
 * creation). Bodies are copied from core/helper-legacy/flows/legacy_functions.php
 * unchanged, except that optional helpers are now behind function_exists().
 *
 * Loaded by bizcity-twin-ai.php under the same gate helper-legacy had.
 */

defined( 'ABSPATH' ) || exit;

// [2026-09-26 Claude Opus 5.5] CORE-REDUCTION WP-12 R4 — per-request batch of "sent" messages; bizcity-zalo-bizcity resets and reads it.
if ( ! isset( $GLOBALS['twf_chat_msg_batch'] ) || ! is_array( $GLOBALS['twf_chat_msg_batch'] ) ) {
	$GLOBALS['twf_chat_msg_batch'] = [];
}

if ( ! function_exists( 'biz_send_message' ) ) {
	function biz_send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
		return twf_telegram_send_message( $chat_id, $text, $parse_mode, $reply_markup );
	}
}

if ( ! function_exists( 'twf_telegram_send_message' ) ) {
	function twf_telegram_send_message( $chat_id, $text, $parse_mode = 'HTML', $reply_markup = null ) {
		if ( function_exists( 'bizgpt_log_chat_message' ) ) {
			bizgpt_log_chat_message( $chat_id, $text, 'bot', '', 'telegram' );
		}
		// A client of this blog that uses Zalo gets the message on Zalo.
		if ( function_exists( 'twf_check_client_use_zalo' ) && function_exists( 'send_zalo_botbanhang' )
			&& ( $client_id = twf_check_client_use_zalo( $chat_id ) ) ) {
			return send_zalo_botbanhang( bizgpt_zalo_format( $text ), $client_id, 'text' );
		}

		$override = apply_filters( 'twf_send_message_override', false, $chat_id, $text, $parse_mode, $reply_markup );
		if ( $override !== false ) {
			return $override;
		}

		$GLOBALS['twf_chat_msg_batch'][] = [
			'chat_id' => $chat_id,
			'msg'     => $text,
		];

		// Lets other flows read the reply text (not only Telegram).
		$text = apply_filters( 'twf_telegram_send_message_response', $text, $chat_id );

		$token = get_option( 'twf_bot_token' );
		$url   = "https://api.telegram.org/bot{$token}/sendMessage";

		$text = trim( (string) $text );
		if ( ! $text ) {
			return;
		}
		if ( function_exists( 'back_trace' ) ) {
			back_trace( 'NOTICE', 'check connection telegram: ' . $text );
		}
		// Telegram accepts at most 4096 characters per message.
		if ( mb_strlen( $text, 'UTF-8' ) > 4000 ) {
			$text = mb_substr( $text, 0, 3990, 'UTF-8' ) . '…';
		}

		$payload = [
			'chat_id'                  => $chat_id,
			'text'                     => $text,
			'parse_mode'               => $parse_mode,
			'disable_web_page_preview' => true,
		];
		if ( $reply_markup ) {
			$payload['reply_markup'] = json_encode( $reply_markup );
		}

		$response = wp_remote_post( $url, [
			'body'    => $payload,
			'timeout' => 12,
		] );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), 1 );
		if ( ! $json || empty( $json['ok'] ) ) {
			return false;
		}
		return $json;
	}
}

if ( ! function_exists( 'bizgpt_zalo_format' ) ) {
	function bizgpt_zalo_format( $text ) {
		// 1. Remove LaTeX \[ \] and \( \)
		$text = preg_replace( [ '/\\\\\[/', '/\\\\\]/', '/\\\\\(/', '/\\\\\)/' ], '', $text );
		// 2. Remove Markdown headings ## ###
		$text = preg_replace( '/^#{1,6}\s*/m', '', $text );
		// 3. Drop **bold** and __bold__
		$text = str_replace( [ '**', '__' ], '', $text );
		// 4. \text{...} => ...
		$text = preg_replace( '/\\\\text\{(.*?)\}/', '$1', $text );
		// 5. \left( and \right) => ( )
		$text = str_replace( [ '\\left', '\\right' ], '', $text );
		// 6. \times => *
		$text = str_replace( '\\times', '*', $text );
		// 7. \sqrt{X} => √(X)
		$text = preg_replace( '/\\\\sqrt\{(.*?)\}/', '√($1)', $text );
		// 8. Remove stray `\\`
		$text = str_replace( '\\\\', '', $text );
		// 9. Collapse blank lines
		$text = preg_replace( "/\n{2,}/", "\n", $text );

		return trim( $text );
	}
}

if ( ! function_exists( 'twf_telegram_send_photo' ) ) {
	function twf_telegram_send_photo( $chat_id, $photo_url, $caption = '', $extra = array() ) {
		if ( function_exists( 'bizgpt_log_chat_message' ) ) {
			$msg = '<img src="' . $photo_url . '" class="bizgpt-photo-msg" style="max-width:220px;max-height:160px;border-radius:8px;display:block;margin:6px auto;">';
			if ( $caption ) {
				$msg .= '<div style="margin-top:4px">' . esc_html( $caption ) . '</div>';
			}
			$GLOBALS['twf_chat_msg_batch'][] = [
				'chat_id' => $chat_id,
				'msg'     => $msg,
			];
			bizgpt_log_chat_message( $chat_id, $msg, 'bot', '', 'telegram' );
		}
		// A client of this blog that uses Zalo gets the image on Zalo.
		if ( function_exists( 'twf_check_client_use_zalo' ) && function_exists( 'send_zalo_botbanhang' )
			&& ( $client_id = twf_check_client_use_zalo( $chat_id ) ) ) {
			if ( function_exists( 'back_trace' ) ) {
				back_trace( 'NOTICE', 'twf_telegram_send_photo: ' . $photo_url );
			}
			return send_zalo_botbanhang( $photo_url, $client_id, 'image' );
		}

		$override = apply_filters( 'twf_telegram_send_photo_override', false, $chat_id, $photo_url, $caption, $extra );
		if ( $override !== false ) {
			return $override;
		}

		$bot_token = get_option( 'twf_bot_token' );
		$url       = "https://api.telegram.org/bot$bot_token/sendPhoto";
		$body      = [
			'chat_id'    => $chat_id,
			'photo'      => $photo_url,
			'caption'    => $caption,
			'parse_mode' => 'HTML',
		];
		if ( ! empty( $extra ) ) {
			$body = array_merge( $body, $extra );
		}

		// Lets web chat pick up the image.
		do_action( 'twf_telegram_send_photo_response', $photo_url, $caption, $chat_id );

		return wp_remote_post( $url, [ 'body' => $body ] );
	}
}
