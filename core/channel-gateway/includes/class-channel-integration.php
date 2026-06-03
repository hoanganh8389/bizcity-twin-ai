<?php
/**
 * BizCity Channel Integration — Abstract Channel Adapter (PHASE 0.37 Shared API)
 *
 * PRIMARY EXTENSION POINT for any new communication channel.
 *
 * Plugin authors extend this class and register via the standard hook:
 *
 *   add_action( 'bizcity_register_integrations', function( $registry ) {
 *       $registry->register( new My_Zalo_Bot_Channel() );
 *   });
 *
 * The class handles:
 *   - Credential storage (encrypted via parent BizCity_Integration)
 *   - Channel Role default (cskh / admin / user / custom)
 *   - Inbound normalization contract
 *   - Outbound dispatch contract
 *   - Webhook verification contract
 *   - Health check for dashboard R-CH-8
 *   - Workflow block declarations (trigger + action slugs)
 *   - Auto-registration with BizCity_Gateway_Bridge for outbound routing
 *
 * Design principles (R-CH):
 *   - ONE class per channel type (not one per instance/page/OA)
 *   - Multiple accounts (pages, OA IDs) stored as option list via parent
 *   - category is always 'channel'
 *   - All inbound must flow through Gateway_Bridge::fire_trigger()
 *   - All outbound must flow through Gateway_Sender::send_envelope()
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37
 * @see        PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL.md
 * @see        PHASE-0-RULE-CHANNEL-ONLY.md
 */

defined( 'ABSPATH' ) || exit;

abstract class BizCity_Channel_Integration extends BizCity_Integration {

	/**
	 * Category is always 'channel' — do NOT override in subclasses.
	 */
	protected string $category = 'channel';

	/**
	 * Platform identifier, uppercase.
	 * Must match BizCity_Channel_Role::PLATFORM_DEFAULTS keys.
	 * E.g. 'ZALO_BOT', 'FACEBOOK', 'TELEGRAM', 'SMTP'.
	 */
	protected string $platform = '';

	/**
	 * Default Channel Role for this channel.
	 * @see BizCity_Channel_Role
	 */
	protected string $default_role = 'cskh';

	/**
	 * Chat ID prefix used by Gateway Bridge to route outbound.
	 * E.g. 'zalobot_', 'fb_', 'telegram_'. Keep the trailing underscore.
	 */
	protected string $chat_id_prefix = '';

	/**
	 * Workflow trigger block slugs fired when a message arrives on this channel.
	 * Register your own slugs in modules/workflow/blocks/.
	 * E.g. ['wu_zalobot_text_received', 'wu_zalobot_image_received']
	 *
	 * @var string[]
	 */
	protected array $trigger_blocks = [];

	/**
	 * Workflow action block slugs that send via this channel.
	 * E.g. ['wp_send_zalo_bot_text', 'wp_send_zalo_bot_image']
	 *
	 * @var string[]
	 */
	protected array $action_blocks = [];

	/* ═══════════════════════════════════════════
	 *  Getters (final — no override)
	 * ═══════════════════════════════════════════ */

	final public function inbound_platform(): string {
		return strtoupper( $this->platform );
	}

	final public function get_default_role(): string {
		return $this->default_role;
	}

	final public function get_chat_id_prefix(): string {
		return $this->chat_id_prefix;
	}

	final public function get_trigger_blocks(): array {
		return $this->trigger_blocks;
	}

	final public function get_action_blocks(): array {
		return $this->action_blocks;
	}

	/* ═══════════════════════════════════════════
	 *  Inbound contract (MUST override)
	 * ═══════════════════════════════════════════ */

	/**
	 * Verify the inbound webhook signature.
	 *
	 * Called by BizCity_Webhook_Router BEFORE normalization.
	 * Return true if signature is valid, WP_Error otherwise.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	abstract public function verify_webhook( WP_REST_Request $request );

	/**
	 * Normalize the inbound webhook payload into a canonical envelope.
	 *
	 * The canonical envelope is:
	 *
	 *   [
	 *     'platform'    => 'ZALO_BOT',
	 *     'instance_id' => '12345',         // OA ID, page ID, etc.
	 *     'chat_id'     => 'zalobot_1_abc', // platform-prefix + bot_id + user_id
	 *     'sender_id'   => 'abc',           // raw sender id on the platform
	 *     'text'        => 'Hello',
	 *     'type'        => 'text',          // text|image|file|video|sticker
	 *     'media_url'   => '',
	 *     'raw'         => [...],           // original webhook payload
	 *     'timestamp'   => 1234567890,
	 *   ]
	 *
	 * @param WP_REST_Request $request
	 * @param array           $account  Decrypted account credentials for instance_id.
	 * @return array Canonical envelope, or empty array to skip.
	 */
	abstract public function normalize_inbound( WP_REST_Request $request, array $account ): array;

	/* ═══════════════════════════════════════════
	 *  Outbound contract (MUST override)
	 * ═══════════════════════════════════════════ */

	/**
	 * Send a message via this channel.
	 *
	 * Called by BizCity_Gateway_Sender::send_envelope().
	 *
	 * @param array $msg     Outbound envelope:
	 *   [
	 *     'platform'    => 'ZALO_BOT',
	 *     'instance_id' => '12345',
	 *     'recipient'   => 'abc',
	 *     'text'        => 'Reply text',
	 *     'type'        => 'text',
	 *     'media_url'   => '',
	 *     'meta'        => [...],
	 *   ]
	 * @param array $account Decrypted account credentials.
	 * @return array|WP_Error ['sent'=>bool, 'error'=>string, 'platform'=>string, 'mid'=>string]
	 */
	abstract public function send_outbound( array $msg, array $account );

	/* ═══════════════════════════════════════════
	 *  Health (R-CH-8 — SHOULD override)
	 * ═══════════════════════════════════════════ */

	/**
	 * Return health status of the LAST used account.
	 *
	 * Override to make a lightweight API call or return cached result.
	 *
	 * @return array{ok:bool|null, latency_ms:int, last_error:string, last_success_at:string, token_expires_at:string}
	 */
	public function health(): array {
		$account = $this->get_account();
		$status  = (int) ( $account['_status'] ?? 0 );
		return [
			'ok'              => $status === 1 ? true : ( $status === 0 ? null : false ),
			'latency_ms'      => 0,
			'last_error'      => (string) ( $account['_status_error'] ?? '' ),
			'last_success_at' => (string) ( $account['_last_success_at'] ?? '' ),
			'token_expires_at'=> (string) ( $account['_token_expires_at'] ?? '' ),
		];
	}

	/* ═══════════════════════════════════════════
	 *  Connection test (parent override)
	 * ═══════════════════════════════════════════ */

	/**
	 * Test connection. Calls send_outbound with a ping payload.
	 * Override for a lighter-weight check if the API has a dedicated test endpoint.
	 */
	public function do_test(): void {
		$result = $this->send_outbound(
			[
				'platform'    => $this->inbound_platform(),
				'instance_id' => '',
				'recipient'   => '__ping__',
				'text'        => '',
				'type'        => 'ping',
			],
			$this->get_decrypted_params( true )
		);

		if ( is_wp_error( $result ) ) {
			$this->account['_status']       = 7;
			$this->account['_status_error'] = $result->get_error_message();
		} else {
			$ok = ! empty( $result['sent'] );
			$this->account['_status']       = $ok ? 1 : 7;
			$this->account['_status_error'] = $ok ? '' : ( (string) $result['error'] );
		}
		$this->account['_last_success_at'] = $ok ? gmdate( 'Y-m-d H:i:s' ) : ( $this->account['_last_success_at'] ?? '' );
	}

	/* ═══════════════════════════════════════════
	 *  Self-registration helper
	 * ═══════════════════════════════════════════ */

	/**
	 * Register this channel with both Integration_Registry AND Gateway_Bridge.
	 *
	 * Call from plugin bootstrap:
	 *
	 *   add_action( 'bizcity_register_integrations', [ new My_Channel(), 'register_with_gateway' ] );
	 *
	 * OR (preferred — one-liner):
	 *
	 *   add_action( 'bizcity_register_channel', [ $this, 'register_with_gateway' ] );
	 *
	 * @param mixed $bridge_or_registry  Gateway_Bridge (from bizcity_register_channel)
	 *                                   or Integration_Registry (from bizcity_register_integrations).
	 */
	public function register_with_gateway( $bridge_or_registry = null ): void {
		// Register with Integration Registry (credential storage).
		if ( $bridge_or_registry instanceof BizCity_Integration_Registry ) {
			$bridge_or_registry->register( $this );
		} elseif ( class_exists( 'BizCity_Integration_Registry' ) ) {
			BizCity_Integration_Registry::instance()->register( $this );
		}

		// Register adapter-facing info with Gateway Bridge.
		if ( $this->platform && class_exists( 'BizCity_Gateway_Bridge' ) ) {
			$bridge = ( $bridge_or_registry instanceof BizCity_Gateway_Bridge )
				? $bridge_or_registry
				: BizCity_Gateway_Bridge::instance();
			$bridge->register_channel_integration( $this );
		}
	}

	/* ═══════════════════════════════════════════
	 *  Admin UI metadata override
	 * ═══════════════════════════════════════════ */

	public function to_admin_array(): array {
		return array_merge( parent::to_admin_array(), [
			'platform'       => $this->inbound_platform(),
			'default_role'   => $this->get_default_role(),
			'chat_id_prefix' => $this->get_chat_id_prefix(),
			'trigger_blocks' => $this->get_trigger_blocks(),
			'action_blocks'  => $this->get_action_blocks(),
		] );
	}
}
