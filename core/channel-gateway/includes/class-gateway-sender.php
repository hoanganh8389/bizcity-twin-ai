<?php
/**
 * Gateway Sender — Unified outbound message dispatch
 *
 * Looks up the appropriate adapter by chat_id prefix and delegates send_outbound().
 * Falls back to legacy send functions when no adapter is registered.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Gateway_Sender {

	/** @var self|null */
	private static $instance = null;
	/** @var array<int,mixed> */
	private $trace_stack = array();

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Send a message to any channel.
	 *
	 * @param string $chat_id  Chat ID with platform prefix.
	 * @param string $message  Message text.
	 * @param string $type     Type: 'text', 'image', 'file'.
	 * @param array  $extra    Extra data (image_url, caption, bot_id…).
	 * @return array ['sent' => bool, 'error' => string, 'platform' => string]
	 */
	public function send( string $chat_id, string $message, string $type = 'text', array $extra = [] ): array {
		$chat_id = trim( $chat_id );
		if ( $chat_id === '' ) {
			return [ 'sent' => false, 'error' => 'Empty chat_id', 'platform' => '' ];
		}

		$bridge   = BizCity_Gateway_Bridge::instance();
		$platform = $bridge->detect_platform( $chat_id );

		/**
		 * Filter outbound message before sending.
		 *
		 * @param string $message  Message text.
		 * @param string $chat_id  Target chat ID.
		 * @param string $platform Detected platform.
		 */
		$message = apply_filters( 'bizcity_channel_before_send', $message, $chat_id, $platform );

		// [2026-07-07 Johnny Chu] HOTFIX — stamp outbound with trace source so ops can
		// distinguish CRM autoreply vs automation fallback vs explicit workflow actions.
		$trace = $this->build_trace_context( $chat_id, $platform, $type, $message, $extra );
		$this->push_trace_context( $trace );

		error_log( sprintf( '[Channel Gateway] 📤 Sending to %s | platform=%s | type=%s', $chat_id, $platform, $type ) );
		error_log( sprintf(
			'[Channel Gateway TRACE] id=%s source=%s platform=%s chat_id=%s len=%d hash=%s',
			(string) ( $trace['trace_id'] ?? '' ),
			(string) ( $trace['source'] ?? 'unknown' ),
			$platform,
			$chat_id,
			(int) ( $trace['message_len'] ?? 0 ),
			(string) ( $trace['message_hash'] ?? '' )
		) );

		try {

			// Try registered adapter first
			$adapter = $bridge->get_adapter( $platform );
			if ( $adapter ) {
				$options = array_merge( $extra, [ 'type' => $type ] );
				$sent    = $adapter->send_outbound( $chat_id, $message, $options );
				$result  = [
					'sent'     => $sent,
					'error'    => $sent ? '' : 'Adapter send_outbound returned false',
					'platform' => $platform,
				];

				/**
				 * Fires after an outbound message is sent.
				 *
				 * @param array  $result   Send result.
				 * @param string $chat_id  Target chat ID.
				 * @param string $platform Platform identifier.
				 */
				do_action( 'bizcity_channel_after_send', $result, $chat_id, $platform );

				do_action( 'bizcity_channel_outbound_logged', array(
					'chat_id'  => $chat_id,
					'platform' => $platform,
					'message'  => $message,
					'type'     => $type,
					'extra'    => array_merge( $extra, array( '_trace' => $trace ) ),
					'sent'     => (bool) $result['sent'],
					'error'    => (string) $result['error'],
				) );

				$this->log_outbound( $chat_id, $message, $platform, $result['sent'] );
				return $result;
			}

			// No adapter registered — use legacy send
			$result = $this->send_legacy( $chat_id, $message, $type, $extra, $platform );

			do_action( 'bizcity_channel_after_send', $result, $chat_id, $platform );

			do_action( 'bizcity_channel_outbound_logged', array(
				'chat_id'  => $chat_id,
				'platform' => $result['platform'],
				'message'  => $message,
				'type'     => $type,
				'extra'    => array_merge( $extra, array( '_trace' => $trace ) ),
				'sent'     => (bool) $result['sent'],
				'error'    => (string) $result['error'],
			) );

			$this->log_outbound( $chat_id, $message, $result['platform'], $result['sent'] );

			return $result;
		} finally {
			$this->pop_trace_context();
		}
	}

	/**
	 * [2026-07-07 Johnny Chu] HOTFIX — build one trace envelope per outbound send.
	 */
	private function build_trace_context( string $chat_id, string $platform, string $type, string $message, array $extra ): array {
		$ctx = isset( $GLOBALS['_bizcity_outbound_trace_ctx'] ) && is_array( $GLOBALS['_bizcity_outbound_trace_ctx'] )
			? (array) $GLOBALS['_bizcity_outbound_trace_ctx']
			: array();

		$source = trim( (string) ( $extra['_trace_source'] ?? $extra['source'] ?? $ctx['source'] ?? '' ) );
		if ( $source === '' ) {
			$source = $this->detect_trace_source_from_backtrace();
		}

		$trace_id = trim( (string) ( $extra['_trace_id'] ?? $ctx['trace_id'] ?? '' ) );
		if ( $trace_id === '' ) {
			$trace_id = 'cg-' . substr( sha1( $chat_id . '|' . microtime( true ) . '|' . mt_rand() ), 0, 12 );
		}

		return array(
			'trace_id'     => $trace_id,
			'source'       => $source,
			'chat_id'      => $chat_id,
			'platform'     => $platform,
			'type'         => $type,
			'message_len'  => mb_strlen( $message ),
			'message_hash' => substr( sha1( $message ), 0, 12 ),
			'ctx'          => $ctx,
		);
	}

	/**
	 * [2026-07-07 Johnny Chu] HOTFIX — best-effort caller fingerprint for outbound origin.
	 */
	private function detect_trace_source_from_backtrace(): string {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 );
		foreach ( $frames as $f ) {
			$cls  = strtolower( (string) ( $f['class'] ?? '' ) );
			$func = strtolower( (string) ( $f['function'] ?? '' ) );
			$file = strtolower( (string) ( $f['file'] ?? '' ) );

			if ( strpos( $cls, 'bizcity_crm_ai_replier' ) !== false ) { return 'crm.ai_replier'; }
			if ( strpos( $cls, 'bizcity_automation_default_reply' ) !== false ) { return 'automation.default_reply'; }
			if ( strpos( $cls, 'bizcity_automation_trigger_matcher' ) !== false ) { return 'automation.matcher'; }
			if ( strpos( $cls, 'bizcity_automation_action_' ) !== false ) { return 'automation.action'; }
			if ( strpos( $file, 'class-ai-autoreply-listener.php' ) !== false ) { return 'crm.autoreply_listener'; }
			if ( $func === 'bizcity_channel_send' ) { continue; }
		}
		return 'unknown';
	}

	/**
	 * [2026-07-07 Johnny Chu] HOTFIX — expose current send trace to downstream adapters/APIs.
	 */
	private function push_trace_context( array $trace ): void {
		$this->trace_stack[] = array_key_exists( '_bizcity_channel_send_trace', $GLOBALS )
			? $GLOBALS['_bizcity_channel_send_trace']
			: null;
		$GLOBALS['_bizcity_channel_send_trace'] = $trace;
	}

	/**
	 * [2026-07-07 Johnny Chu] HOTFIX — restore previous trace after send.
	 */
	private function pop_trace_context(): void {
		$prev = array_pop( $this->trace_stack );
		if ( $prev === null ) {
			unset( $GLOBALS['_bizcity_channel_send_trace'] );
			return;
		}
		$GLOBALS['_bizcity_channel_send_trace'] = $prev;
	}

	/**
	 * Legacy send — mirrors bizcity_gateway_send_message() from gateway-functions.php.
	 *
	 * Maintains backward compat until all channels have adapter plugins.
	 *
	 * @param string $chat_id
	 * @param string $message
	 * @param string $type
	 * @param array  $extra
	 * @param string $platform
	 * @return array
	 */
	private function send_legacy( string $chat_id, string $message, string $type, array $extra, string $platform ): array {
		$platform_lower = strtolower( $platform );

		switch ( $platform_lower ) {
			case 'zalo_personal':
			case 'zalo':
				$client_id = preg_replace( '/^zalo_/', '', $chat_id );
				if ( function_exists( 'send_zalo_botbanhang' ) ) {
					$send_type = ( $type === 'image' ) ? 'image' : 'text';
					$res = send_zalo_botbanhang( $message, $client_id, $send_type );
					return [ 'sent' => (bool) $res, 'error' => '', 'platform' => 'ZALO_PERSONAL' ];
				}
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_PERSONAL' ];
				}
				return [ 'sent' => false, 'error' => 'Zalo send function not available', 'platform' => 'ZALO_PERSONAL' ];

			case 'zalo_bot':
				$raw_user_id  = preg_replace( '/^zalobot_/', '', $chat_id );
				$parsed_bot_id = isset( $extra['bot_id'] ) ? (int) $extra['bot_id'] : 0;
				if ( ! $parsed_bot_id && preg_match( '/^(\d+)_(.+)$/', $raw_user_id, $m ) ) {
					$parsed_bot_id = (int) $m[1];
					$raw_user_id   = $m[2];
				}

				// [2026-06-13 Johnny Chu] ZA-2 — Try BizCity_Channel_Integration (Zalo OA via
				// BizCity_Integration_Registry) FIRST.  The new OA adapter stores tokens in the
				// Integration Registry; the legacy BizCity_Zalo_Bot_Database path below is for
				// the old bizcity-zalo-bot plugin and will fail to find OA accounts.
				if (
					class_exists( 'BizCity_Integration_Registry' ) &&
					class_exists( 'BizCity_Channel_Integration' )
				) {
					$_oa_registry = BizCity_Integration_Registry::instance();
					$_oa_channel  = null;
					$_oa_account  = array();
					foreach ( $_oa_registry->get_all() as $_integ ) {
						if (
							( $_integ instanceof BizCity_Channel_Integration ) &&
							strtoupper( $_integ->inbound_platform() ) === 'ZALO_BOT'
						) {
							$_oa_channel = $_integ;
							break;
						}
					}
					if ( $_oa_channel ) {
						$_oa_accounts = $_oa_registry->get_accounts( $_oa_channel->get_code() );
						foreach ( $_oa_accounts as $_acc ) {
							if (
								(string) ( $_acc['oa_id'] ?? '' ) === (string) $parsed_bot_id ||
								(string) ( $_acc['_uid']  ?? '' ) === (string) $parsed_bot_id
							) {
								$_oa_account = $_acc;
								break;
							}
						}
						// Single-account fallback.
						if ( empty( $_oa_account ) && count( $_oa_accounts ) === 1 ) {
							$_oa_account = $_oa_accounts[0];
						}
					}
					if ( $_oa_channel && ! empty( $_oa_account ) ) {
						$_clone = clone $_oa_channel;
						$_clone->set_account( $_oa_account );
						$_decrypted = $_clone->get_decrypted_params();
						$_result    = $_oa_channel->send_outbound(
							array(
								'recipient' => $raw_user_id,
								'text'      => $message,
								'type'      => $type,
							),
							$_decrypted
						);
						unset( $_oa_registry, $_oa_channel, $_oa_account, $_oa_accounts, $_clone, $_decrypted );
						if ( is_array( $_result ) ) {
							return $_result;
						}
						if ( is_wp_error( $_result ) ) {
							return array( 'sent' => false, 'error' => $_result->get_error_message(), 'platform' => 'ZALO_BOT' );
						}
					}
					unset( $_oa_registry, $_oa_channel, $_oa_account );
				}

				// Resolve blog for zalo bot (legacy bizcity-zalo-bot plugin path)
				$target_blog_id = 0;
				if ( class_exists( 'BizCity_Blog_Resolver' ) ) {
					$target_blog_id = BizCity_Blog_Resolver::instance()->resolve_bot_blog( $parsed_bot_id );
				} elseif ( function_exists( 'bizcity_gateway_resolve_bot_blog_id' ) ) {
					$target_blog_id = bizcity_gateway_resolve_bot_blog_id( $parsed_bot_id );
				}

				$switched = false;
				if ( $target_blog_id && is_multisite() && $target_blog_id !== get_current_blog_id() ) {
					switch_to_blog( $target_blog_id );
					$switched = true;
				}

				if ( class_exists( 'BizCity_Zalo_Bot_Database' ) && class_exists( 'BizCity_Zalo_Bot_API' ) ) {
					$db  = BizCity_Zalo_Bot_Database::instance();
					$bot = $parsed_bot_id ? $db->get_bot( $parsed_bot_id ) : null;
					if ( ! $bot ) {
						$bots = $db->get_active_bots();
						$bot  = ! empty( $bots ) ? end( $bots ) : null;
					}

					if ( $bot && ! empty( $bot->bot_token ) ) {
						$api      = new BizCity_Zalo_Bot_API( $bot->bot_token );
						$response = $api->send_message( $raw_user_id, $message );
						if ( $switched ) restore_current_blog();

						if ( is_wp_error( $response ) ) {
							return [ 'sent' => false, 'error' => $response->get_error_message(), 'platform' => 'ZALO_BOT' ];
						}
						return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_BOT' ];
					}
				}

				if ( $switched ) restore_current_blog();

				// Fallback to zalo personal
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( 'zalo_' . $raw_user_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ZALO_BOT_FALLBACK' ];
				}
				return [ 'sent' => false, 'error' => 'Zalo Bot plugin not active', 'platform' => 'ZALO_BOT' ];

			case 'webchat':
				if ( class_exists( 'BizCity_WebChat_Trigger' ) ) {
					BizCity_WebChat_Trigger::instance()->send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'WEBCHAT' ];
				}
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( [
						'session_id'    => $chat_id,
						'user_id'       => 0,
						'client_name'   => 'AI Bot',
						'message_id'    => uniqid( 'gw_' ),
						'message_text'  => $message,
						'message_from'  => 'bot',
						'platform_type' => 'WEBCHAT',
					] );
					return [ 'sent' => true, 'error' => '', 'platform' => 'WEBCHAT' ];
				}
				return [ 'sent' => false, 'error' => 'WebChat plugin not active', 'platform' => 'WEBCHAT' ];

			case 'adminchat':
				if ( class_exists( 'BizCity_WebChat_Database' ) ) {
					BizCity_WebChat_Database::instance()->log_message( [
						'session_id'    => $chat_id,
						'user_id'       => 0,
						'client_name'   => 'AI Bot',
						'message_id'    => uniqid( 'gw_adminchat_' ),
						'message_text'  => $message,
						'message_from'  => 'bot',
						'platform_type' => 'ADMINCHAT',
					] );
					return [ 'sent' => true, 'error' => '', 'platform' => 'ADMINCHAT' ];
				}
				return [ 'sent' => false, 'error' => 'AdminChat database not available', 'platform' => 'ADMINCHAT' ];

			case 'facebook':
				$fb_id = preg_replace( '/^(fb_|messenger_)/', '', $chat_id );
				if ( function_exists( 'fbm_send_text_to_user' ) ) {
					$res = fbm_send_text_to_user( $fb_id, $message );
					return [ 'sent' => (bool) $res, 'error' => '', 'platform' => 'FACEBOOK' ];
				}
				return [ 'sent' => false, 'error' => 'Facebook send function not available', 'platform' => 'FACEBOOK' ];

			case 'telegram':
				if ( function_exists( 'twf_telegram_send_message' ) ) {
					twf_telegram_send_message( $chat_id, $message, 'HTML' );
					return [ 'sent' => true, 'error' => '', 'platform' => 'TELEGRAM' ];
				}
				return [ 'sent' => false, 'error' => 'Telegram function not available', 'platform' => 'TELEGRAM' ];

			default:
				if ( function_exists( 'biz_send_message' ) ) {
					biz_send_message( $chat_id, $message );
					return [ 'sent' => true, 'error' => '', 'platform' => 'FALLBACK' ];
				}
				return [ 'sent' => false, 'error' => 'No send method available for: ' . $chat_id, 'platform' => 'UNKNOWN' ];
		}
	}

	/**
	 * Log outbound message to global_inbox_admin.
	 *
	 * @param string $chat_id
	 * @param string $message
	 * @param string $platform
	 * @param bool   $sent
	 */
	private function log_outbound( string $chat_id, string $message, string $platform, bool $sent ): void {
		global $wpdb;

		$table = $wpdb->base_prefix . 'global_inbox_admin';

		// Only log if table exists (checked once per request)
		static $table_exists = null;
		if ( null === $table_exists ) {
			$table_exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		}
		if ( ! $table_exists ) {
			return;
		}

		$wpdb->insert( $table, [
			'client_id'     => $chat_id,
			'client_name'   => 'AI Bot',
			'platform_type' => strtoupper( $platform ),
			'message_text'  => mb_substr( $message, 0, 10000 ),
			'message_type'  => 'outbound',
			'created_at'    => current_time( 'mysql' ),
			'blog_id'       => get_current_blog_id(),
		] );
	}

	/* ═══════════════════════════════════════════
	 *  PHASE 0.37 — Normalized Outbound API
	 *
	 *  send_envelope() is the R-CH-5 clean surface.
	 *  All new code should call this instead of send().
	 *  send() is preserved for backward compat.
	 * ═══════════════════════════════════════════ */

	/**
	 * Send via canonical envelope.
	 *
	 * Resolves channel integration from platform + instance_id,
	 * decrypts credentials, calls send_outbound().
	 * Falls back to legacy send() if no registered channel integration.
	 *
	 * @param array $envelope {
	 *   @type string $platform    Platform key, e.g. 'ZALO_BOT', 'FACEBOOK'. Required.
	 *   @type string $instance_id OA ID / Page ID / channel instance. Required.
	 *   @type string $recipient   Recipient user ID on the platform. Required.
	 *   @type string $message     Message text. Required.
	 *   @type string $type        Message type: text|image|file|video. Default 'text'.
	 *   @type array  $meta        Extra platform-specific fields.
	 * }
	 * @return array|WP_Error ['sent'=>bool, 'error'=>string, 'platform'=>string, 'mid'=>string]
	 */
	public function send_envelope( array $envelope ) {
		$platform    = strtoupper( trim( $envelope['platform'] ?? '' ) );
		$instance_id = trim( $envelope['instance_id'] ?? '' );
		$recipient   = trim( $envelope['recipient'] ?? '' );
		$message     = trim( $envelope['message'] ?? '' );
		$type        = sanitize_key( $envelope['type'] ?? 'text' ) ?: 'text';
		$meta        = (array) ( $envelope['meta'] ?? [] );

		if ( ! $platform || ! $recipient ) {
			return new \WP_Error( 'missing_fields', 'send_envelope requires platform + recipient.' );
		}

		/**
		 * Filter outbound envelope before send.
		 *
		 * @param array $envelope
		 */
		$envelope = apply_filters( 'bizcity_channel_envelope_before_send', $envelope );

		// Look up BizCity_Channel_Integration for this platform.
		$registry = class_exists( 'BizCity_Integration_Registry' ) ? BizCity_Integration_Registry::instance() : null;
		$channel  = null;
		$account  = [];

		if ( $registry ) {
			foreach ( $registry->get_all() as $integ ) {
				if (
					( $integ instanceof BizCity_Channel_Integration ) &&
					strtoupper( $integ->inbound_platform() ) === $platform
				) {
					$channel = $integ;
					break;
				}
			}

			// Resolve account by instance_id.
			if ( $channel ) {
				$code     = $channel->get_code();
				$accounts = $registry->get_accounts( $code );
				foreach ( $accounts as $acc ) {
					if (
						( $acc['instance_id'] ?? '' ) === $instance_id ||
						( $acc['oa_id'] ?? '' )       === $instance_id ||
						( $acc['page_id'] ?? '' )      === $instance_id ||
						( $acc['_uid'] ?? '' )         === $instance_id
					) {
						$account = $acc;
						break;
					}
				}
				// If still no match but there's only one account, use it.
				if ( empty( $account ) && count( $accounts ) === 1 ) {
					$account = $accounts[0];
				}
			}
		}

		if ( $channel && ! empty( $account ) ) {
			// Decrypt credentials.
			$clone = clone $channel;
			$clone->set_account( $account );
			$decrypted = $clone->get_decrypted_params();

			$out_msg = [
				'platform'    => $platform,
				'instance_id' => $instance_id,
				'recipient'   => $recipient,
				'text'        => $message,
				'type'        => $type,
				'meta'        => $meta,
			];

			$result = $channel->send_outbound( $out_msg, $decrypted );

			if ( is_wp_error( $result ) ) {
				error_log( sprintf( '[Channel Gateway] 📤 send_envelope ERROR %s | %s', $platform, $result->get_error_message() ) );
				return $result;
			}

			do_action( 'bizcity_channel_after_send', $result, $recipient, $platform );
			$this->log_outbound( $recipient, $message, $platform, (bool) ( $result['sent'] ?? false ) );
			return $result;
		}

		// Fallback: legacy send() — construct chat_id from prefix + recipient.
		error_log( sprintf(
			'[Channel Gateway] ⚠️ send_envelope fallback to legacy for platform=%s instance=%s (no channel integration found)',
			$platform, $instance_id
		) );
		$prefix  = BizCity_Gateway_Bridge::instance()->get_prefix_for_platform( $platform );
		$chat_id = $prefix ? $prefix . $recipient : $recipient;
		return $this->send( $chat_id, $message, $type, $meta );
	}
}
