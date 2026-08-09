<?php
/**
 * Gateway Bridge — Adapter registry, inbound routing, outbound dispatch
 *
 * Singleton: receives registered adapters from channel plugins,
 * routes inbound webhooks to the correct adapter, and dispatches
 * outbound messages via the correct adapter.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Gateway_Bridge {

	/** @var self|null */
	private static $instance = null;

	/** @var BizCity_Channel_Adapter[] Registered adapters keyed by platform. */
	private $adapters = [];

	/** @var BizCity_Channel_Integration[] New channel integrations keyed by platform. */
	private $integrations = [];

	/** @var string[] Prefix → platform lookup. */
	private $prefix_map = [];

	/** @var string[] Endpoint → platform lookup. */
	private $endpoint_map = [];

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ─── Adapter Registry ─── */

	/**
	 * Register a channel adapter.
	 *
	 * Called by channel plugins via:
	 *   add_action('bizcity_register_channel', function($bridge){ $bridge->register_adapter(...); });
	 *
	 * @param BizCity_Channel_Adapter $adapter
	 */
	public function register_adapter( BizCity_Channel_Adapter $adapter ): void {
		$platform = strtoupper( $adapter->get_platform() );

		$this->adapters[ $platform ] = $adapter;

		// Build prefix map
		$prefix = $adapter->get_prefix();
		if ( $prefix !== '' ) {
			$this->prefix_map[ $prefix ] = $platform;
		}

		// Build endpoint map
		foreach ( $adapter->get_endpoints() as $ep ) {
			$this->endpoint_map[ $ep ] = $platform;
		}

	}

	/**
	 * Get a registered adapter by platform.
	 *
	 * @param string $platform
	 * @return BizCity_Channel_Adapter|null
	 */
	public function get_adapter( string $platform ): ?BizCity_Channel_Adapter {
		return $this->adapters[ strtoupper( $platform ) ] ?? null;
	}

	/**
	 * Get a registered channel integration by platform.
	 *
	 * @param string $platform
	 * @return BizCity_Channel_Integration|null
	 */
	public function get_channel_integration( string $platform ) {
		return $this->integrations[ strtoupper( $platform ) ] ?? null;
	}

	/**
	 * Get all registered adapters.
	 *
	 * @return BizCity_Channel_Adapter[]
	 */
	public function get_adapters(): array {
		return $this->adapters;
	}

	/**
	 * Detect platform from chat_id prefix.
	 *
	 * Checks registered adapters first, then falls back to legacy detection.
	 *
	 * @param string $chat_id
	 * @return string Platform name (uppercase) or 'UNKNOWN'.
	 */
	public function detect_platform( string $chat_id ): string {
		// Check registered adapters by prefix (longest match first)
		$sorted_prefixes = $this->prefix_map;
		uksort( $sorted_prefixes, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		});

		foreach ( $sorted_prefixes as $prefix => $platform ) {
			if ( strpos( $chat_id, $prefix ) === 0 ) {
				return $platform;
			}
		}

		// Numeric → check if any adapter claims numeric IDs (prefix = '')
		if ( preg_match( '/^-?\d+$/', $chat_id ) ) {
			foreach ( $this->adapters as $platform => $adapter ) {
				if ( $adapter->get_prefix() === '' ) {
					return $platform;
				}
			}
		}

		// Legacy fallback — hardcoded prefixes for backward compat
		return $this->detect_platform_legacy( $chat_id );
	}

	/**
	 * Legacy platform detection (backward compat).
	 *
	 * Used when no registered adapter matches. Mirrors the original
	 * bizcity_gateway_detect_platform() from gateway-functions.php.
	 *
	 * @param string $chat_id
	 * @return string
	 */
	private function detect_platform_legacy( string $chat_id ): string {
		if ( strpos( $chat_id, 'zalobot_' )    === 0 ) return 'ZALO_BOT';
		if ( strpos( $chat_id, 'webchat_' )    === 0 ) return 'WEBCHAT';
		if ( strpos( $chat_id, 'sess_' )       === 0 ) return 'WEBCHAT';
		if ( strpos( $chat_id, 'wcs_' )        === 0 ) return 'WEBCHAT';
		if ( strpos( $chat_id, 'adminchat_' )  === 0 ) return 'ADMINCHAT';
		if ( strpos( $chat_id, 'admin_chat_' ) === 0 ) return 'ADMINCHAT';
		if ( strpos( $chat_id, 'admin_' )      === 0 ) return 'ADMINCHAT';
		if ( strpos( $chat_id, 'fb_' )         === 0 ) return 'FACEBOOK';
		if ( strpos( $chat_id, 'messenger_' )  === 0 ) return 'FACEBOOK';
		if ( strpos( $chat_id, 'zalo_' )       === 0 ) return 'ZALO_PERSONAL';
		if ( preg_match( '/^-?\d+$/', $chat_id ) )     return 'TELEGRAM';

		return 'UNKNOWN';
	}

	/* ─── Inbound Handling ─── */

	/**
	 * Handle an inbound webhook request.
	 *
	 * 1. Lookup adapter by endpoint
	 * 2. Verify webhook
	 * 3. Normalize payload
	 * 4. Resolve user + blog
	 * 5. Fire hooks + gateway trigger
	 *
	 * @param string $endpoint  The matched endpoint path.
	 * @param array  $request   ['headers' => [...], 'body' => string|array, 'params' => [...]]
	 * @return array|false Normalized payload or false on failure.
	 */
	public function handle_inbound( string $endpoint, array $request ) {
		$platform = $this->endpoint_map[ $endpoint ] ?? null;
		if ( ! $platform ) {
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_ERROR, 'inbound_adapter_missing', 'No adapter was registered for the inbound endpoint.', array( 'endpoint' => $endpoint ) );
			}
			return false;
		}

		$adapter = $this->adapters[ $platform ] ?? null;
		if ( ! $adapter ) {
			return false;
		}

		// Verify webhook authenticity
		if ( ! $adapter->verify_webhook( $request ) ) {
			/**
			 * Fires when webhook verification fails.
			 *
			 * @param array  $request  Raw request data.
			 * @param string $platform Platform identifier.
			 */
			do_action( 'bizcity_channel_verify_failed', $request, $platform );
			if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
				BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_WARN, 'webhook_verification_failed', 'Channel webhook verification failed.', array( 'platform' => $platform ) );
			}
			return false;
		}

		// Parse body if string
		$raw_data = is_string( $request['body'] ?? '' )
			? json_decode( $request['body'], true ) ?: []
			: ( $request['body'] ?? [] );

		// Normalize inbound → standard payload
		$payload = $adapter->normalize_inbound( $raw_data );
		$payload['platform'] = $platform;

		// Resolve user: chat_id → wp_user_id
		if ( class_exists( 'BizCity_User_Resolver' ) ) {
			$resolve_chat_id = (string) ( $payload['chat_id'] ?? '' );
			if ( $platform === 'ZALO_BOT' ) {
				// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — canonical identity for Zalo Bot is sender user_id, not thread/group chat_id.
				$zalo_user_id = (string) ( $payload['user_id'] ?? '' );
				$zalo_bot_id  = (string) ( $payload['bot_id'] ?? $payload['account_id'] ?? '' );
				if ( $zalo_user_id !== '' && $zalo_bot_id !== '' ) {
					$resolve_chat_id = 'zalobot_' . $zalo_bot_id . '_' . $zalo_user_id;
					$payload['identity_chat_id'] = $resolve_chat_id;
				}
			}
			$payload['wp_user_id'] = BizCity_User_Resolver::instance()->resolve( $resolve_chat_id );
		}

		// Resolve blog: chat_id → blog_id (multisite)
		if ( is_multisite() && class_exists( 'BizCity_Blog_Resolver' ) ) {
			$payload['blog_id'] = BizCity_Blog_Resolver::instance()->resolve( $payload['chat_id'] ?? '', $payload );
			if ( $payload['blog_id'] && $payload['blog_id'] !== get_current_blog_id() ) {
				switch_to_blog( $payload['blog_id'] );
			}
		}

		/**
		 * Fires after an inbound channel message is received and normalized.
		 *
		 * @param array $payload Standard normalized payload.
		 */
		do_action( 'bizcity_channel_message_received', $payload );

		// Fire gateway trigger → Intent Engine / Chat Gateway
		$this->fire_trigger( $payload, $raw_data );

		return $payload;
	}

	/**
	 * Fire unified gateway trigger.
	 *
	 * Delegates to bizcity_aiwu_fire_twf_process_flow() if available,
	 * else fires waic_twf_process_flow directly.
	 *
	 * Automatically resolves Channel Role if not already set in trigger,
	 * and injects it so downstream handlers (Chat Gateway, Focus Router)
	 * receive the correct context configuration.
	 *
	 * @param array $trigger  Normalized trigger payload.
	 * @param array $raw      Original raw data.
	 * @return bool
	 */
	public function fire_trigger( array $trigger, array $raw = [] ): bool {
		$GLOBALS['bizcity_gateway_trigger_fired'] = true;

		$platform = $trigger['platform'] ?? 'unknown';
		$text     = $trigger['message'] ?? $trigger['text'] ?? '';
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_CHANNEL_GATEWAY, BizCity_Channel_File_Logger::LEVEL_INFO, 'trigger_firing', 'Channel Gateway trigger dispatch started.', array( 'platform' => $platform, 'text_len' => function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text ) ) );
		}

		// ── Resolve Channel Role if not already set ──
		if ( empty( $trigger['channel_role'] ) && class_exists( 'BizCity_Channel_Role' ) ) {
			$trigger['channel_role'] = BizCity_Channel_Role::resolve(
				strtoupper( $platform ),
				$trigger['bot_id'] ?? null,
				(int) ( $trigger['wp_user_id'] ?? 0 )
			);
		}

		// Build legacy-compat trigger format
		$compat_trigger = $this->build_legacy_trigger( $trigger );

		// Carry channel_role through to legacy trigger (consumed by Chat Gateway / Focus Router)
		if ( ! empty( $trigger['channel_role'] ) ) {
			$compat_trigger['channel_role'] = $trigger['channel_role'];
		}
		if ( ! empty( $trigger['wp_user_id'] ) ) {
			$compat_trigger['wp_user_id'] = (int) $trigger['wp_user_id'];
		}

		if ( function_exists( 'bizcity_aiwu_fire_twf_process_flow' ) ) {
			// [2026-07-31 Johnny Chu] R-CH-NS — legacy dispatcher has a mixed return contract; normalize it before this bool method returns.
			$result = bizcity_aiwu_fire_twf_process_flow( $compat_trigger, $raw, 'waic_twf_process_flow' );
			return ! is_wp_error( $result ) && false !== $result;
		}

		do_action( 'waic_twf_process_flow', $compat_trigger, $raw );
		return (int) has_action( 'waic_twf_process_flow' ) > 0;
	}

	/**
	 * Convert standard payload → legacy trigger format.
	 *
	 * Legacy code expects 'text', 'client_id', 'chat_id', 'display_name', etc.
	 * New payloads use 'message', 'user_id', 'client_name'.
	 *
	 * @param array $payload
	 * @return array
	 */
	private function build_legacy_trigger( array $payload ): array {
		$first_attachment = is_array( $payload['attachments'][0] ?? null ) ? $payload['attachments'][0] : [];
		$image_url        = (string) ( $first_attachment['url'] ?? $payload['image_url'] ?? '' );
		$attachment_type  = (string) ( $first_attachment['type'] ?? ( $image_url !== '' ? 'image' : 'text' ) );
		return [
			'platform'        => strtolower( $payload['platform'] ?? '' ),
			'client_id'       => $payload['user_id'] ?? $payload['chat_id'] ?? '',
			'chat_id'         => $payload['chat_id'] ?? '',
			'session_id'      => $payload['chat_id'] ?? '',
			'user_id'         => $payload['user_id'] ?? '',
			'display_name'    => $payload['client_name'] ?? '',
			'text'            => $payload['message'] ?? '',
			'message_id'      => $payload['message_id'] ?? '',
			'attachment_url'  => $image_url,
			'attachment_type' => $attachment_type,
			'image_url'       => $image_url,
			'audio_url'       => '',
			'bot_id'          => $payload['bot_id'] ?? '',
			'bot_name'        => $payload['bot_name'] ?? '',
			'raw'             => $payload['raw'] ?? [],
		];
	}

	/* ─── PHASE 0.37 Additions ─── */

	/**
	 * Alias for get_adapters() — used by BizCity_Channel_Menu_Registry + REST API.
	 *
	 * @return array
	 */
	public function get_all_adapters(): array {
		return $this->adapters;
	}

	/**
	 * Get the chat_id prefix for a given platform (reverse prefix_map lookup).
	 *
	 * @param string $platform
	 * @return string Prefix string or '' if not mapped.
	 */
	public function get_prefix_for_platform( string $platform ): string {
		$platform = strtoupper( $platform );
		foreach ( $this->prefix_map as $prefix => $plt ) {
			if ( $plt === $platform ) {
				return $prefix;
			}
		}
		return '';
	}

	/**
	 * Register a BizCity_Channel_Integration with the bridge.
	 *
	 * Called from BizCity_Channel_Integration::register_with_gateway().
	 * Registers prefix and endpoint maps from get_chat_id_prefix() and get_inbound_platform().
	 *
	 * @param BizCity_Channel_Integration $channel
	 */
	public function register_channel_integration( BizCity_Channel_Integration $channel ): void {
		// [2026-08-01 Johnny Chu] HOTFIX — keep integration objects out of the
		// adapter registry; get_adapter() promises BizCity_Channel_Adapter and
		// outbound legacy callers invoke the adapter interface directly.
		$platform = strtoupper( $channel->inbound_platform() );
		if ( ! $platform ) {
			return;
		}

		$this->integrations[ $platform ] = $channel;

		$prefix = $channel->get_chat_id_prefix();
		if ( $prefix !== '' ) {
			$this->prefix_map[ $prefix ] = $platform;
		}

		do_action( 'bizcity_channel_registered', $channel, $platform );
	}
}
