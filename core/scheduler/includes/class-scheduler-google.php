<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler — Google Calendar Integration
 *
 * Bridges local events ↔ Google Calendar via OAuth.
 * Reuses the OAuth pattern from bizcity-automation's googlecalendar.php integration,
 * but stores credentials in wp_options (site-level) instead of per-workflow.
 *
 * Extension points:
 *   - bizcity_scheduler_google_synced  → fires after pull/push sync
 *   - bizcity_scheduler_google_error   → fires on API errors (for monitoring)
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Google {

	private static $instance = null;

	const OPTION_KEY = 'bizcity_scheduler_google';
	const AUTH_URI   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const OAUTH_STATE_TRANSIENT_PREFIX = 'bizcity_scheduler_oauth_state_';
	const OAUTH_STATE_TTL              = 900;

	/**
	 * Token backend identifiers used in {@see resolve_account_context()}.
	 * Framework consumers can branch on `$context['source']`.
	 */
	const SOURCE_LEGACY   = 'legacy_site';
	const SOURCE_BZGOOGLE = 'bzgoogle_hub';
	const SOURCE_HUB      = 'bizcity_llm_hub'; // bizcity-llm-router Google OAuth (v1.9.0)

	/** @var string Google Calendar API endpoints */
	private $token_uri  = 'https://oauth2.googleapis.com/token';
	private $events_uri = 'https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events';
	private $test_uri   = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Auto-sync new local events to Google
		add_action( 'bizcity_scheduler_event_created', [ $this, 'on_event_created' ], 10, 2 );
		add_action( 'bizcity_scheduler_event_updated', [ $this, 'on_event_updated' ], 10, 3 );
		add_action( 'bizcity_scheduler_event_deleted', [ $this, 'on_event_deleted' ], 10, 1 );
	}

	/* ================================================================
	 *  Phase 4 — Multi-Account Adapter (BZGoogle Hub)
	 * ================================================================
	 *
	 * The scheduler ships two token backends:
	 *   1. SOURCE_LEGACY   — site-level OAuth config in wp_options
	 *                        (managed by Settings UI; back-compat).
	 *   2. SOURCE_BZGOOGLE — per-user multi-account hub provided by the
	 *                        bizgpt-tool-google plugin (BZGoogle_Token_Store).
	 *
	 * Resolution order for an event:
	 *   1. $event->google_account_id (explicit binding from create/update)
	 *   2. apply_filters('bizcity_scheduler_google_account_for_event', null, $event, $user_id)
	 *   3. First active BZGoogle account for $user_id (auto-select)
	 *   4. Fall back to legacy site-level config
	 *
	 * Framework hooks any plugin can use:
	 *   - filter:  bizcity_scheduler_google_account_for_event(?int, $event, int $user_id) → ?int
	 *   - filter:  bizcity_scheduler_google_calendar_for_event(string, $event, array $ctx) → string
	 *   - filter:  bizcity_scheduler_google_account_context(array, $event, int $user_id)  → array
	 *   - action:  bizcity_scheduler_google_synced(string $direction, int $event_id, mixed $payload)
	 *   - action:  bizcity_scheduler_google_error(string $stage, string $message, ?int $event_id, ?array $context)
	 */

	/**
	 * Whether the BZGoogle Hub plugin is available on this site.
	 */
	public function bzgoogle_available(): bool {
		return class_exists( 'BZGoogle_Token_Store' );
	}

	/**
	 * Whether the bizcity-llm-router Google OAuth hub is available.
	 * Returns true when the client is configured and the hub has Google creds.
	 */
	public function is_hub_available(): bool {
		if ( ! function_exists( 'bizcity_llm_is_ready' ) || ! bizcity_llm_is_ready() ) {
			return false;
		}
		if ( ! function_exists( 'bizcity_llm_google_status' ) ) {
			return false;
		}
		$status = bizcity_llm_google_status();
		return ! empty( $status['has_credentials'] );
	}

	/**
	 * Resolve the Google account context for an event (or a user).
	 *
	 * Returns an array on success or WP_Error if no usable account is found.
	 *   [
	 *     'source'         => self::SOURCE_BZGOOGLE | self::SOURCE_LEGACY,
	 *     'account_id'     => int|null  (bzgoogle row id; null for legacy)
	 *     'access_token'   => string,
	 *     'calendar_id'    => string,
	 *     'google_email'   => string,
	 *   ]
	 *
	 * @param int         $user_id  Owner of the event (may be 0 → falls back to legacy only).
	 * @param object|null $event    Optional event row used for explicit binding + filter context.
	 * @return array|\WP_Error
	 */
	public function resolve_account_context( int $user_id, $event = null ) {
		$account_id = null;

		// 1. Explicit binding on event.
		if ( is_object( $event ) && ! empty( $event->google_account_id ) ) {
			$account_id = (int) $event->google_account_id;
		}

		/**
		 * Filter: let plugins override account selection.
		 *
		 * @param int|null $account_id  Currently chosen bzgoogle account id (or null).
		 * @param object|null $event
		 * @param int $user_id
		 */
		$account_id = apply_filters( 'bizcity_scheduler_google_account_for_event', $account_id, $event, $user_id );

		// 2. Try bizcity-llm-router Hub Google OAuth first.
		if ( $this->is_hub_available() ) {
			$context = $this->resolve_via_hub();
			if ( ! is_wp_error( $context ) ) {
				$context = $this->apply_calendar_resolver( $context, $event );
				return apply_filters( 'bizcity_scheduler_google_account_context', $context, $event, $user_id );
			}
		}

		// 3. Try BZGoogle Hub.
		if ( $this->bzgoogle_available() && $user_id > 0 ) {
			$context = $this->resolve_via_bzgoogle( $user_id, $account_id );
			if ( ! is_wp_error( $context ) ) {
				$context = $this->apply_calendar_resolver( $context, $event );
				return apply_filters( 'bizcity_scheduler_google_account_context', $context, $event, $user_id );
			}
		}

		// 4. Fall back to legacy site-level config.
		$legacy = $this->resolve_via_legacy();
		if ( is_wp_error( $legacy ) ) {
			return $legacy;
		}
		$legacy = $this->apply_calendar_resolver( $legacy, $event );
		return apply_filters( 'bizcity_scheduler_google_account_context', $legacy, $event, $user_id );
	}

	/**
	 * Convenience wrapper: "can this user push to Google at all?".
	 */
	public function is_connected_for_user( int $user_id ): bool {
		if ( $this->bzgoogle_available() && $user_id > 0
			&& \BZGoogle_Token_Store::has_valid_token( get_current_blog_id(), $user_id ) ) {
			return true;
		}
		return $this->is_connected();
	}

	/**
	 * List Google accounts available for a user — used by FE picker UI.
	 * Returns a normalized shape regardless of backend.
	 *
	 * @return array<int, array{ id: int|string, source: string, label: string, email: string, is_default: bool, status: string }>
	 */
	public function list_user_accounts( int $user_id ): array {
		$out = [];

		if ( $this->bzgoogle_available() && $user_id > 0 ) {
			$rows = \BZGoogle_Token_Store::get_accounts( get_current_blog_id(), $user_id );
			$first = true;
			foreach ( (array) $rows as $row ) {
				$status = isset( $row->status ) ? (string) $row->status : 'active';
				if ( 'disconnected' === $status ) {
					continue;
				}
				$out[] = [
					'id'         => (int) $row->id,
					'source'     => self::SOURCE_BZGOOGLE,
					'label'      => (string) ( $row->google_email ?: ( 'Account #' . $row->id ) ),
					'email'      => (string) ( $row->google_email ?? '' ),
					'is_default' => $first,
					'status'     => $status,
				];
				$first = false;
			}
		}

		// Legacy shows up as a virtual account when no bzgoogle row exists or as fallback option.
		if ( $this->is_connected() ) {
			$config = $this->get_config();
			$out[] = [
				'id'         => 'legacy',
				'source'     => self::SOURCE_LEGACY,
				'label'      => sprintf( '[Site] %s', $config['calendar_id'] ?: 'primary' ),
				'email'      => '',
				'is_default' => empty( $out ),
				'status'     => 'active',
			];
		}

		return $out;
	}

	/**
	 * Resolve a token via the BZGoogle Hub.
	 */
	private function resolve_via_bzgoogle( int $user_id, ?int $account_id ) {
		$blog_id = get_current_blog_id();

		$google_email = '';
		if ( $account_id ) {
			// Look up the requested account row.
			$rows = \BZGoogle_Token_Store::get_accounts( $blog_id, $user_id );
			$picked = null;
			foreach ( (array) $rows as $row ) {
				if ( (int) $row->id === $account_id ) {
					$picked = $row;
					break;
				}
			}
			if ( ! $picked ) {
				return new \WP_Error( 'bzgoogle_account_not_found', sprintf( 'BZGoogle account #%d không thuộc user #%d.', $account_id, $user_id ) );
			}
			$google_email = (string) ( $picked->google_email ?? '' );
		}

		$token = \BZGoogle_Token_Store::get_token( $blog_id, $user_id, $google_email );
		if ( empty( $token ) || empty( $token['access_token'] ) ) {
			return new \WP_Error( 'bzgoogle_no_token', 'Không có token BZGoogle hợp lệ cho user.' );
		}

		// Recover account_id when we let BZGoogle pick the default.
		if ( ! $account_id ) {
			$rows = \BZGoogle_Token_Store::get_accounts( $blog_id, $user_id );
			foreach ( (array) $rows as $row ) {
				if ( ( $token['google_email'] ?? '' ) === ( $row->google_email ?? '' ) ) {
					$account_id = (int) $row->id;
					break;
				}
			}
		}

		return [
			'source'       => self::SOURCE_BZGOOGLE,
			'account_id'   => $account_id ? (int) $account_id : null,
			'access_token' => (string) $token['access_token'],
			'calendar_id'  => 'primary',
			'google_email' => (string) ( $token['google_email'] ?? $google_email ),
		];
	}

	/**
	 * Resolve a token via the bizcity-llm-router Google OAuth hub.
	 */
	private function resolve_via_hub() {
		if ( ! function_exists( 'bizcity_llm_google_token' ) ) {
			return new \WP_Error( 'hub_unavailable', 'bizcity_llm_google_token helper not loaded.' );
		}
		$token_data = bizcity_llm_google_token();
		if ( empty( $token_data['connected'] ) || empty( $token_data['access_token'] ) ) {
			$err = $token_data['error'] ?? 'Not connected via hub.';
			return new \WP_Error( 'hub_not_connected', $err );
		}
		$config = $this->get_config();
		return [
			'source'       => self::SOURCE_HUB,
			'account_id'   => null,
			'access_token' => (string) $token_data['access_token'],
			'calendar_id'  => $config['calendar_id'] ?: 'primary',
			'google_email' => (string) ( $token_data['google_email'] ?? '' ),
		];
	}

	/**
	 * Resolve a token via the legacy site-level option (back-compat path).
	 */
	private function resolve_via_legacy() {
		if ( ! $this->is_connected() ) {
			return new \WP_Error( 'not_connected', 'Google Calendar chưa kết nối (cả BZGoogle và site-level).' );
		}
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$config = $this->get_config();
		return [
			'source'       => self::SOURCE_LEGACY,
			'account_id'   => null,
			'access_token' => (string) $token,
			'calendar_id'  => $config['calendar_id'] ?: 'primary',
			'google_email' => '',
		];
	}

	/**
	 * Apply per-event calendar override from the event row + filter chain.
	 */
	private function apply_calendar_resolver( array $context, $event ): array {
		if ( is_object( $event ) && ! empty( $event->google_calendar_id ) ) {
			$context['calendar_id'] = (string) $event->google_calendar_id;
		}
		$context['calendar_id'] = (string) apply_filters(
			'bizcity_scheduler_google_calendar_for_event',
			$context['calendar_id'],
			$event,
			$context
		);
		if ( '' === $context['calendar_id'] ) {
			$context['calendar_id'] = 'primary';
		}
		return $context;
	}

	/* ================================================================
	 *  OAuth Settings (stored in wp_options)
	 * ================================================================ */

	/**
	 * Get saved Google config.
	 *
	 * @return array {client_id, client_secret, refresh_token, access_token, expires_at, calendar_id, connected}
	 */
	public function get_config(): array {
		$defaults = [
			'client_id'     => '',
			'client_secret' => '',
			'refresh_token' => '',
			'access_token'  => '',
			'expires_at'    => 0,
			'calendar_id'   => 'primary',
			'connected'     => false,
		];
		return wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
	}

	/**
	 * Save Google config.
	 */
	public function save_config( array $config ): void {
		update_option( self::OPTION_KEY, $config, false ); // not autoloaded (contains secret)
	}

	/**
	 * Connection status for REST API / Admin UI.
	 */
	public function get_connection_status(): array {
		$config = $this->get_config();
		return [
			'connected'   => $this->is_connected(),
			'calendar_id' => $config['calendar_id'],
			'redirect_uri' => $this->get_redirect_uri(),
		];
	}

	public function get_redirect_uri(): string {
		return rest_url( 'bizcity-scheduler/v1/google/callback' );
	}

	/**
	 * Admin payload for settings UI.
	 */
	public function get_settings_payload(): array {
		// ── Hub mode (bizcity-llm-router): no local credentials needed ──────
		if ( $this->is_hub_available() && function_exists( 'bizcity_llm_google_status' ) ) {
			$status   = bizcity_llm_google_status();
			$auth_url = '';
			if ( function_exists( 'bizcity_llm_google_auth_url' ) ) {
				$return_url = admin_url( 'admin.php?page=bizcity-scheduler&tab=google' );
				$result     = bizcity_llm_google_auth_url( $return_url, [ 'https://www.googleapis.com/auth/calendar' ] );
				$auth_url   = $result['auth_url'] ?? '';
			}
			$config = $this->get_config();
			return [
				'connected'         => ! empty( $status['connected'] ),
				'google_email'      => $status['google_email'] ?? '',
				'calendar_id'       => $config['calendar_id'] ?: 'primary',
				'redirect_uri'      => $status['redirect_uri'] ?? '',
				'auth_url'          => $auth_url,
				'has_client_secret' => false,
				'has_refresh_token' => ! empty( $status['connected'] ),
				'hub_app'           => true,
				'source'            => self::SOURCE_HUB,
			];
		}

		// ── Legacy / BZGoogle path ────────────────────────────────────────────
		$config   = $this->get_config();
		$auth_url = '';

		if ( ! empty( $config['client_id'] ) && ! empty( $config['client_secret'] ) ) {
			$auth_url = $this->get_auth_url();
		}

		return [
			'connected'         => $this->is_connected(),
			'client_id'         => $config['client_id'],
			'calendar_id'       => $config['calendar_id'] ?: 'primary',
			'redirect_uri'      => $this->get_redirect_uri(),
			'auth_url'          => $auth_url,
			'has_client_secret' => ! empty( $config['client_secret'] ),
			'has_refresh_token' => ! empty( $config['refresh_token'] ),
			'hub_app'           => false,
			'source'            => $this->bzgoogle_available() ? self::SOURCE_BZGOOGLE : self::SOURCE_LEGACY,
		];
	}

	/**
	 * Save admin-provided Google settings.
	 */
	public function save_settings( array $data ): array {
		$config           = $this->get_config();
		$old_client_id    = $config['client_id'];
		$old_client_secret = $config['client_secret'];

		if ( array_key_exists( 'client_id', $data ) && '' !== trim( (string) $data['client_id'] ) ) {
			$config['client_id'] = sanitize_text_field( (string) $data['client_id'] );
		}

		if ( array_key_exists( 'client_secret', $data ) && '' !== trim( (string) $data['client_secret'] ) ) {
			$config['client_secret'] = sanitize_text_field( (string) $data['client_secret'] );
		}

		if ( array_key_exists( 'calendar_id', $data ) ) {
			$calendar_id = sanitize_text_field( (string) $data['calendar_id'] );
			$config['calendar_id'] = '' !== $calendar_id ? $calendar_id : 'primary';
		}

		$credentials_changed = $config['client_id'] !== $old_client_id || $config['client_secret'] !== $old_client_secret;
		if ( $credentials_changed ) {
			$config['refresh_token'] = '';
			$config['access_token']  = '';
			$config['expires_at']    = 0;
			$config['connected']     = false;
		}

		$this->save_config( $config );

		return $this->get_settings_payload();
	}

	/**
	 * Disconnect Google Calendar without deleting client credentials.
	 */
	public function disconnect(): array {
		// ── Hub mode ─────────────────────────────────────────────────────────
		if ( $this->is_hub_available() && function_exists( 'bizcity_llm_google_disconnect' ) ) {
			bizcity_llm_google_disconnect();
			return $this->get_settings_payload();
		}

		// ── Legacy path ───────────────────────────────────────────────────────
		$config = $this->get_config();
		$config['refresh_token'] = '';
		$config['access_token']  = '';
		$config['expires_at']    = 0;
		$config['connected']     = false;
		$this->save_config( $config );

		return $this->get_settings_payload();
	}

	public function get_auth_url(): string {
		$config = $this->get_config();
		if ( empty( $config['client_id'] ) ) {
			return '';
		}

		$state = $this->create_oauth_state();

		return add_query_arg( [
			'client_id'             => $config['client_id'],
			'redirect_uri'          => $this->get_redirect_uri(),
			'response_type'         => 'code',
			'access_type'           => 'offline',
			'prompt'                => 'consent',
			'include_granted_scopes' => 'true',
			'scope'                 => 'https://www.googleapis.com/auth/calendar',
			'state'                 => $state,
		], self::AUTH_URI );
	}

	/**
	 * Is Google Calendar connected?
	 */
	public function is_connected(): bool {
		$config = $this->get_config();
		// Must have a refresh_token OR a non-expired access_token to be considered connected.
		if ( ! empty( $config['refresh_token'] ) ) {
			return true;
		}
		if ( ! empty( $config['access_token'] ) && ! empty( $config['expires_at'] ) && $config['expires_at'] > time() ) {
			return true;
		}
		return false;
	}

	/* ================================================================
	 *  Access Token Management
	 * ================================================================ */

	/**
	 * Get a valid access token, refreshing if expired.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		// ── Hub mode ─────────────────────────────────────────────────────────
		if ( $this->is_hub_available() && function_exists( 'bizcity_llm_google_token' ) ) {
			$data = bizcity_llm_google_token();
			if ( ! empty( $data['connected'] ) && ! empty( $data['access_token'] ) ) {
				return (string) $data['access_token'];
			}
			return new \WP_Error( 'hub_token_error', $data['error'] ?? 'Hub Google token not available.' );
		}

		// ── Legacy path ───────────────────────────────────────────────────────
		$config = $this->get_config();

		// Check if current token is still valid
		if ( ! empty( $config['access_token'] ) && $config['expires_at'] > time() + 60 ) {
			return $config['access_token'];
		}

		// Refresh
		if ( empty( $config['refresh_token'] ) ) {
			return new \WP_Error( 'no_refresh_token', 'Google Calendar not connected.' );
		}

		$response = wp_remote_post( $this->token_uri, [
			'body' => [
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'refresh_token' => $config['refresh_token'],
				'grant_type'    => 'refresh_token',
			],
		] );

		if ( is_wp_error( $response ) ) {
			do_action( 'bizcity_scheduler_google_error', 'token_refresh', $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['access_token'] ) ) {
			$error = $body['error_description'] ?? ( $body['error'] ?? 'Unknown error' );
			do_action( 'bizcity_scheduler_google_error', 'token_refresh', $error );
			return new \WP_Error( 'token_refresh_failed', $error );
		}

		// Save refreshed token
		$config['access_token'] = $body['access_token'];
		$config['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		$this->save_config( $config );

		return $config['access_token'];
	}

	/**
	 * Exchange OAuth code returned by Google for tokens.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_oauth_callback( \WP_REST_Request $req ) {
		$code = sanitize_text_field( (string) $req->get_param( 'code' ) );
		$state = sanitize_text_field( (string) $req->get_param( 'state' ) );
		if ( empty( $code ) ) {
			return new \WP_Error( 'missing_code', 'Google callback missing code.' );
		}

		$oauth_state = $this->consume_oauth_state( $state );
		if ( empty( $oauth_state ) ) {
			return new \WP_Error( 'invalid_state', 'Google callback state is invalid.' );
		}

		$config = $this->get_config();
		if ( empty( $config['client_id'] ) || empty( $config['client_secret'] ) ) {
			return new \WP_Error( 'missing_client_credentials', 'Google client credentials are not configured.' );
		}

		$response = wp_remote_post( $this->token_uri, [
			'body' => [
				'code'          => $code,
				'client_id'     => $config['client_id'],
				'client_secret' => $config['client_secret'],
				'redirect_uri'  => $this->get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );

		if ( is_wp_error( $response ) ) {
			do_action( 'bizcity_scheduler_google_error', 'oauth_callback', $response->get_error_message() );
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 || empty( $body['access_token'] ) ) {
			$error = $body['error_description'] ?? ( $body['error'] ?? 'OAuth exchange failed.' );
			do_action( 'bizcity_scheduler_google_error', 'oauth_callback', $error );
			return new \WP_Error( 'oauth_callback_failed', $error );
		}

		$config['access_token'] = $body['access_token'];
		$config['expires_at']   = time() + (int) ( $body['expires_in'] ?? 3600 );
		$config['connected']    = true;
		if ( ! empty( $body['refresh_token'] ) ) {
			$config['refresh_token'] = $body['refresh_token'];
		}

		$this->save_config( $config );

		return [
			'connected'   => true,
			'calendar_id' => $config['calendar_id'] ?: 'primary',
			'redirect_to' => $oauth_state['redirect_to'] ?? admin_url( 'admin.php?page=bizcity-scheduler' ),
		];
	}

	private function create_oauth_state(): string {
		$token = wp_generate_password( 48, false, false );
		$key   = self::OAUTH_STATE_TRANSIENT_PREFIX . md5( $token );

		set_transient( $key, [
			'created_at'  => time(),
			'redirect_to' => admin_url( 'admin.php?page=bizcity-scheduler' ),
			'user_id'     => get_current_user_id(),
		], self::OAUTH_STATE_TTL );

		return $token;
	}

	private function consume_oauth_state( string $state ): array {
		if ( '' === $state ) {
			return [];
		}

		$key   = self::OAUTH_STATE_TRANSIENT_PREFIX . md5( $state );
		$data  = get_transient( $key );
		delete_transient( $key );

		return is_array( $data ) ? $data : [];
	}

	/* ================================================================
	 *  Push to Google Calendar
	 * ================================================================ */

	/**
	 * Create event on Google Calendar.
	 *
	 * @param object $event  Local DB event row (must have user_id; may have google_account_id).
	 * @return array|WP_Error  On success: ['google_event_id' => str, 'google_account_id' => ?int, 'google_calendar_id' => str]
	 */
	public function push_event( $event ) {
		$user_id = (int) ( $event->user_id ?? 0 );
		$context = $this->resolve_account_context( $user_id, $event );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$endpoint = str_replace( '{calendarId}', urlencode( $context['calendar_id'] ), $this->events_uri );

		$google_event = $this->format_for_google( $event );

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Authorization' => 'Bearer ' . $context['access_token'],
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( $google_event ),
		] );

		if ( is_wp_error( $response ) ) {
			do_action( 'bizcity_scheduler_google_error', 'push', $response->get_error_message(), (int) ( $event->id ?? 0 ), $context );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$error = $body['error']['message'] ?? 'API error ' . $code;
			do_action( 'bizcity_scheduler_google_error', 'push', $error, (int) ( $event->id ?? 0 ), $context );
			return new \WP_Error( 'push_failed', $error );
		}

		do_action( 'bizcity_scheduler_google_synced', 'push', $event->id ?? 0, $body['id'] ?? '', $context );

		return [
			'google_event_id'   => (string) ( $body['id'] ?? '' ),
			'google_account_id' => $context['account_id'],
			'google_calendar_id' => $context['calendar_id'],
		];
	}

	/**
	 * Update existing Google Calendar event.
	 *
	 * @param object $event  Local DB event row.
	 * @return true|\WP_Error
	 */
	public function update_google_event( $event ) {
		if ( empty( $event->google_event_id ) ) {
			return new \WP_Error( 'missing_google_event_id', 'Google event ID is required.' );
		}
		$user_id = (int) ( $event->user_id ?? 0 );
		$context = $this->resolve_account_context( $user_id, $event );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$endpoint = str_replace( '{calendarId}', urlencode( $context['calendar_id'] ), $this->events_uri );
		$payload  = $this->format_for_google( $event );

		$response = wp_remote_request( $endpoint . '/' . urlencode( $event->google_event_id ), [
			'method'  => 'PATCH',
			'headers' => [
				'Authorization' => 'Bearer ' . $context['access_token'],
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			do_action( 'bizcity_scheduler_google_error', 'patch', $response->get_error_message(), (int) ( $event->id ?? 0 ), $context );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) {
			$error = $body['error']['message'] ?? 'API error ' . $code;
			do_action( 'bizcity_scheduler_google_error', 'patch', $error, (int) ( $event->id ?? 0 ), $context );
			return new \WP_Error( 'patch_failed', $error );
		}

		do_action( 'bizcity_scheduler_google_synced', 'patch', $event->id ?? 0, $event->google_event_id, $context );

		return true;
	}

	/**
	 * Delete event from Google Calendar.
	 *
	 * @param string      $google_event_id
	 * @param object|null $event_or_ctx  Optional event row OR a context array (so callers without
	 *                                   the row can pass {user_id, account_id, calendar_id}).
	 */
	public function delete_google_event( string $google_event_id, $event_or_ctx = null ) {
		if ( empty( $google_event_id ) ) {
			return;
		}

		if ( is_array( $event_or_ctx ) && isset( $event_or_ctx['access_token'] ) ) {
			$context = $event_or_ctx;
		} else {
			$user_id = is_object( $event_or_ctx ) ? (int) ( $event_or_ctx->user_id ?? 0 ) : 0;
			$context = $this->resolve_account_context( $user_id, $event_or_ctx );
			if ( is_wp_error( $context ) ) {
				return;
			}
		}

		$endpoint = str_replace( '{calendarId}', urlencode( $context['calendar_id'] ), $this->events_uri );

		wp_remote_request( $endpoint . '/' . urlencode( $google_event_id ), [
			'method'  => 'DELETE',
			'headers' => [ 'Authorization' => 'Bearer ' . $context['access_token'] ],
		] );
	}

	/* ================================================================
	 *  Pull from Google Calendar
	 * ================================================================ */

	/**
	 * Sync events from Google Calendar into local DB.
	 *
	 * @param int      $user_id
	 * @param int|null $account_id  Optional BZGoogle account to use; null = auto/legacy.
	 * @return int|WP_Error  Number of events synced.
	 */
	public function sync_from_google( int $user_id, ?int $account_id = null ) {
		// Build a virtual "event" for the resolver so explicit $account_id is honoured.
		$probe = (object) [ 'user_id' => $user_id, 'google_account_id' => $account_id ?: null ];
		$context = $this->resolve_account_context( $user_id, $probe );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$endpoint = str_replace( '{calendarId}', urlencode( $context['calendar_id'] ), $this->events_uri );

		// Fetch next 30 days
		$time_min = gmdate( 'Y-m-d\TH:i:s\Z' );
		$time_max = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+30 days' ) );

		$response = wp_remote_get( $endpoint . '?' . http_build_query( [
			'timeMin'      => $time_min,
			'timeMax'      => $time_max,
			'singleEvents' => 'true',
			'orderBy'      => 'startTime',
			'maxResults'   => 100,
		] ), [
			'headers' => [ 'Authorization' => 'Bearer ' . $context['access_token'] ],
		] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $body['error']['message'] ?? ( 'Google Calendar API error ' . $http_code );
			do_action( 'bizcity_scheduler_google_error', 'sync_pull', $msg, 0, $context );
			error_log( '[BizCity Scheduler] sync_from_google HTTP ' . $http_code . ': ' . $msg );
			return new \WP_Error( 'google_api_error', $msg );
		}

		$items = $body['items'] ?? [];
		$mgr   = BizCity_Scheduler_Manager::instance();
		$count = 0;

		foreach ( $items as $item ) {
			$result = $this->upsert_remote_event( $item, $user_id, $context );
			if ( false === $result ) {
				continue;
			}

			$count++;
		}

		do_action( 'bizcity_scheduler_google_synced', 'pull', $user_id, $count, $context );

		return $count;
	}

	/* ================================================================
	 *  Event Hooks (auto-sync on CRUD)
	 * ================================================================ */

	/**
	 * When a local event is created → push to Google.
	 */
	public function on_event_created( $event, array $data ): void {
		if ( ! is_object( $event ) ) {
			return;
		}
		// Skip if already has a google_event_id (came from Google sync)
		if ( ! empty( $event->google_event_id ) ) {
			return;
		}
		// Need a connected backend for this user.
		$user_id = (int) ( $event->user_id ?? 0 );
		if ( ! $this->is_connected_for_user( $user_id ) ) {
			return;
		}

		$result = $this->push_event( $event );
		if ( is_wp_error( $result ) ) {
			error_log( '[BizCity Scheduler] push_event failed for event #' . ( $event->id ?? '?' ) . ': ' . $result->get_error_message() );
			do_action( 'bizcity_scheduler_google_error', 'push', $result->get_error_message(), (int) ( $event->id ?? 0 ), null );
			return;
		}
		if ( ! empty( $result['google_event_id'] ) ) {
			$update = [
				'google_event_id'    => $result['google_event_id'],
				'google_calendar_id' => $result['google_calendar_id'],
				'google_synced_at'   => current_time( 'mysql' ),
			];
			if ( ! empty( $result['google_account_id'] ) ) {
				$update['google_account_id'] = (int) $result['google_account_id'];
			}
			BizCity_Scheduler_Manager::instance()->update_event( (int) $event->id, $update );
		}
	}

	public function on_event_updated( $event, $old, array $changed ): void {
		if ( ! is_object( $event ) || empty( $event->google_event_id ) ) {
			return;
		}
		$user_id = (int) ( $event->user_id ?? 0 );
		if ( ! $this->is_connected_for_user( $user_id ) ) {
			return;
		}

		if ( ! $this->should_sync_update( $changed ) ) {
			return;
		}

		$result = $this->update_google_event( $event );
		if ( is_wp_error( $result ) ) {
			return;
		}

		$this->touch_google_synced_at( (int) $event->id );
	}

	public function on_event_deleted( $event ): void {
		if ( ! is_object( $event ) || empty( $event->google_event_id ) ) {
			return;
		}
		$this->delete_google_event( (string) $event->google_event_id, $event );
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Format local event → Google Calendar API format.
	 */
	private function format_for_google( $event ): array {
		$g = [];

		if ( ! empty( $event->title ) ) {
			$g['summary'] = $event->title;
		}
		if ( ! empty( $event->description ) ) {
			$g['description'] = $event->description;
		}

		$all_day = ! empty( $event->all_day );

		if ( $all_day ) {
			$start_date = wp_date( 'Y-m-d', strtotime( $event->start_at ) );
			$end_source = $event->end_at ?: $event->start_at;
			$end_date   = wp_date( 'Y-m-d', strtotime( '+1 day', strtotime( $end_source ) ) );

			$g['start'] = [ 'date' => $start_date ];
			$g['end']   = [ 'date' => $end_date ];
		} else {
			$tz       = wp_timezone();
			$tz_name  = wp_timezone_string();
			$start_dt = new \DateTimeImmutable( $event->start_at, $tz );
			$end_dt   = new \DateTimeImmutable( $event->end_at ?: $event->start_at, $tz );

			$g['start'] = [ 'dateTime' => $start_dt->format( 'c' ), 'timeZone' => $tz_name ];
			$g['end']   = [
				'dateTime' => $end_dt->format( 'c' ),
				'timeZone' => $tz_name,
			];
		}

		if ( ! empty( $event->reminder_min ) && $event->reminder_min > 0 ) {
			$g['reminders'] = [
				'useDefault' => false,
				'overrides'  => [ [ 'method' => 'popup', 'minutes' => (int) $event->reminder_min ] ],
			];
		}

		return $g;
	}

	/**
	 * Create or update local event from Google without causing sync loops.
	 *
	 * @param array      $item     Google event JSON.
	 * @param int        $user_id
	 * @param array      $context  Resolved account context (source, account_id, calendar_id).
	 */
	private function upsert_remote_event( array $item, int $user_id, array $context ) {
		$google_id = $item['id'] ?? '';
		if ( empty( $google_id ) ) {
			return false;
		}

		$mgr      = BizCity_Scheduler_Manager::instance();
		$local    = $this->map_google_item_to_local( $item, $user_id, $context );
		$table    = $mgr->get_table();
		$existing = $this->find_local_event_by_google_id( $table, $google_id, $user_id );

		if ( ! $existing ) {
			$mgr->create_event( $local );
			return true;
		}

		$remote_updated = ! empty( $item['updated'] ) ? strtotime( $item['updated'] ) : 0;
		$local_synced   = ! empty( $existing->google_synced_at ) ? strtotime( $existing->google_synced_at ) : 0;
		if ( $remote_updated && $local_synced && $remote_updated <= $local_synced ) {
			return false;
		}

		global $wpdb;
		$wpdb->update(
			$table,
			[
				'title'              => $local['title'],
				'description'        => $local['description'],
				'start_at'           => $local['start_at'],
				'end_at'             => $local['end_at'],
				'all_day'            => $local['all_day'],
				'google_calendar_id' => $local['google_calendar_id'],
				'google_account_id'  => $local['google_account_id'],
				'google_synced_at'   => $local['google_synced_at'],
			],
			[ 'id' => (int) $existing->id ],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ],
			[ '%d' ]
		);

		return true;
	}

	private function find_local_event_by_google_id( string $table, string $google_id, int $user_id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE google_event_id = %s AND user_id = %d LIMIT 1",
			$google_id,
			$user_id
		) );
	}

	private function map_google_item_to_local( array $item, int $user_id, array $context ): array {
		$start   = $item['start']['dateTime'] ?? $item['start']['date'] ?? '';
		$end     = $item['end']['dateTime'] ?? $item['end']['date'] ?? '';
		$all_day = isset( $item['start']['date'] ) ? 1 : 0;

		if ( $all_day ) {
			$end_ts = ! empty( $end ) ? strtotime( '-1 day', strtotime( $end ) ) : strtotime( $start );
			$start_at = $start . ' 00:00:00';
			$end_at   = wp_date( 'Y-m-d 23:59:59', $end_ts );
		} else {
			$start_at = wp_date( 'Y-m-d H:i:s', strtotime( $start ) );
			$end_at   = ! empty( $end ) ? wp_date( 'Y-m-d H:i:s', strtotime( $end ) ) : null;
		}

		return [
			'user_id'            => $user_id,
			'title'              => sanitize_text_field( $item['summary'] ?? 'Untitled' ),
			'description'        => wp_kses_post( $item['description'] ?? '' ),
			'start_at'           => $start_at,
			'end_at'             => $end_at,
			'all_day'            => $all_day,
			'google_event_id'    => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'google_calendar_id' => (string) ( $context['calendar_id'] ?? 'primary' ),
			'google_account_id'  => isset( $context['account_id'] ) ? (int) $context['account_id'] : null,
			'google_synced_at'   => current_time( 'mysql' ),
			'source'             => 'google_sync',
			'ai_context'         => 'Synced from Google Calendar',
		];
	}

	/**
	 * Only sync fields that change the remote event payload.
	 */
	private function should_sync_update( array $changed ): bool {
		$sync_fields = [ 'title', 'description', 'start_at', 'end_at', 'all_day', 'reminder_min' ];

		foreach ( $changed as $field ) {
			if ( in_array( $field, $sync_fields, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Update sync timestamp without firing scheduler hooks again.
	 */
	private function touch_google_synced_at( int $event_id ): void {
		global $wpdb;

		$mgr = BizCity_Scheduler_Manager::instance();
		$wpdb->update(
			$mgr->get_table(),
			[ 'google_synced_at' => current_time( 'mysql' ) ],
			[ 'id' => $event_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}
