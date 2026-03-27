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

		error_log( sprintf(
			'[Channel Gateway] ✅ Adapter registered: %s (prefix=%s, endpoints=%s)',
			$platform,
			$prefix ?: '(numeric)',
			implode( ', ', $adapter->get_endpoints() )
		) );
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
			error_log( '[Channel Gateway] ❌ No adapter registered for endpoint: ' . $endpoint );
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
			error_log( '[Channel Gateway] ⚠️ Webhook verification failed for: ' . $platform );
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
			$payload['wp_user_id'] = BizCity_User_Resolver::instance()->resolve( $payload['chat_id'] ?? '' );
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
	 * @param array $trigger  Normalized trigger payload.
	 * @param array $raw      Original raw data.
	 * @return bool
	 */
	public function fire_trigger( array $trigger, array $raw = [] ): bool {
		$GLOBALS['bizcity_gateway_trigger_fired'] = true;

		$platform = $trigger['platform'] ?? 'unknown';
		$text     = $trigger['message'] ?? $trigger['text'] ?? '';
		error_log( sprintf(
			'[Channel Gateway] 🚀 Firing trigger | platform=%s | text=%s',
			$platform,
			mb_substr( $text, 0, 60 )
		) );

		// Build legacy-compat trigger format
		$compat_trigger = $this->build_legacy_trigger( $trigger );

		if ( function_exists( 'bizcity_aiwu_fire_twf_process_flow' ) ) {
			return bizcity_aiwu_fire_twf_process_flow( $compat_trigger, $raw, 'waic_twf_process_flow' );
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
		return [
			'platform'        => strtolower( $payload['platform'] ?? '' ),
			'client_id'       => $payload['user_id'] ?? $payload['chat_id'] ?? '',
			'chat_id'         => $payload['chat_id'] ?? '',
			'session_id'      => $payload['chat_id'] ?? '',
			'user_id'         => $payload['user_id'] ?? '',
			'display_name'    => $payload['client_name'] ?? '',
			'text'            => $payload['message'] ?? '',
			'message_id'      => $payload['message_id'] ?? '',
			'attachment_url'  => '',
			'attachment_type' => $payload['attachments'][0]['type'] ?? 'text',
			'image_url'       => $payload['attachments'][0]['url'] ?? '',
			'audio_url'       => '',
			'bot_id'          => $payload['bot_id'] ?? '',
			'bot_name'        => $payload['bot_name'] ?? '',
			'raw'             => $payload['raw'] ?? [],
		];
	}
}
