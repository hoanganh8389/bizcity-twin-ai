<?php
/**
 * BizCity Integration Registry — Unified Service Connection Manager
 *
 * Discovers, registers, and manages 3rd-party integrations across the platform.
 * Integrations can be registered by:
 *   1. Dropping a file in core/channel-gateway/integrations/ (auto-scan)
 *   2. Hooking into 'bizcity_register_integrations' action
 *
 * Storage: wp_options keyed as 'bizcity_integ_{code}' (array of account configs).
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Integration_Registry {

	private static ?self $instance = null;

	/** @var BizCity_Integration[] Registered integrations keyed by code */
	private array $integrations = [];

	/** @var array Category definitions */
	private array $categories = [];

	/** @var bool Whether discovery has run */
	private bool $loaded = false;

	private const OPTION_PREFIX = 'bizcity_integ_';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/* ═══════════════════════════════════════════
	 *  Registration
	 * ═══════════════════════════════════════════ */

	/**
	 * Register an integration instance.
	 */
	public function register( BizCity_Integration $integration ): void {
		$code = $integration->get_code();
		if ( $code ) {
			$this->integrations[ $code ] = $integration;
		}
	}

	/**
	 * Load all integrations (auto-scan + hook).
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		$this->categories = [
			'channel'   => __( 'Kênh hội thoại', 'bizcity-twin-ai' ), // PHASE 0.37: channel always first
			'email'     => __( 'Email', 'bizcity-twin-ai' ),
			'calendar'  => __( 'Lịch & Họp', 'bizcity-twin-ai' ),
			'messenger' => __( 'Tin nhắn', 'bizcity-twin-ai' ),
			'crm'       => __( 'CRM', 'bizcity-twin-ai' ),
			'db'        => __( 'Database', 'bizcity-twin-ai' ),
			'storage'   => __( 'Lưu trữ', 'bizcity-twin-ai' ),
			'social'    => __( 'Mạng xã hội', 'bizcity-twin-ai' ),
			'other'     => __( 'Khác', 'bizcity-twin-ai' ),
		];

		// Auto-scan built-in integrations directory.
		$dir = dirname( __DIR__ ) . '/integrations/';
		if ( is_dir( $dir ) ) {
			foreach ( glob( $dir . '*.php' ) as $file ) {
				require_once $file;
				$code  = basename( $file, '.php' );
				$class = 'BizCity_Integration_' . ucfirst( $code );
				if ( class_exists( $class ) && ! isset( $this->integrations[ $code ] ) ) {
					$this->register( new $class() );
				}
			}
		}

		/**
		 * Let external plugins register integrations.
		 *
		 * @param BizCity_Integration_Registry $registry
		 */
		do_action( 'bizcity_register_integrations', $this );

		// Sort by order.
		uasort( $this->integrations, fn( $a, $b ) => $a->get_order() <=> $b->get_order() );
	}

	/* ═══════════════════════════════════════════
	 *  Getters
	 * ═══════════════════════════════════════════ */

	/**
	 * Get all registered integrations.
	 *
	 * @return BizCity_Integration[]
	 */
	public function get_all(): array {
		$this->load();
		return $this->integrations;
	}

	/**
	 * Get integration by code.
	 */
	public function get( string $code ): ?BizCity_Integration {
		$this->load();
		return $this->integrations[ $code ] ?? null;
	}

	/**
	 * Get all categories that have at least one integration.
	 */
	public function get_active_categories(): array {
		$this->load();
		$active = [];
		foreach ( $this->integrations as $integ ) {
			$cat = $integ->get_category();
			if ( isset( $this->categories[ $cat ] ) && ! isset( $active[ $cat ] ) ) {
				$active[ $cat ] = $this->categories[ $cat ];
			}
		}
		return $active;
	}

	/**
	 * Get all category definitions.
	 */
	public function get_categories(): array {
		$this->load();
		return $this->categories;
	}

	/* ═══════════════════════════════════════════
	 *  Account Storage (wp_options)
	 * ═══════════════════════════════════════════ */

	/**
	 * Get all saved accounts for an integration code.
	 *
	 * @param string $code
	 * @param bool   $decrypt
	 * @return array Array of account arrays.
	 */
	public function get_accounts( string $code, bool $decrypt = false ): array {
		$raw = get_option( self::OPTION_PREFIX . $code, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		if ( ! $decrypt ) {
			return $raw;
		}

		$integ = $this->get( $code );
		if ( ! $integ ) {
			return $raw;
		}

		$decrypted = [];
		foreach ( $raw as $account ) {
			$clone = clone $integ;
			$clone->set_account( $account );
			$decrypted[] = $clone->get_decrypted_params( false ); // exclude private
		}
		return $decrypted;
	}

	/**
	 * Get a specific account by index.
	 */
	public function get_account( string $code, int $index ): ?array {
		$accounts = $this->get_accounts( $code );
		return $accounts[ $index ] ?? null;
	}

	/**
	 * Save accounts for an integration.
	 *
	 * @param string $code
	 * @param array  $accounts  Array of account arrays (plain-text values).
	 * @return array Decrypted accounts (without private params) for UI response.
	 */
	public function save_accounts( string $code, array $accounts ): array {
		$integ = $this->get( $code );
		if ( ! $integ ) {
			return [];
		}

		$old_accounts = $this->get_accounts( $code );
		$for_save   = [];
		$for_return = [];

		foreach ( $accounts as $i => $account_data ) {
			$clone = clone $integ;
			$clone->set_account( $account_data );

			// Merge private params from old account if signal params unchanged.
			if ( isset( $old_accounts[ $i ] ) ) {
				$old_clone = clone $integ;
				$old_clone->set_account( $old_accounts[ $i ] );
				$clone->merge_private_from_old( $old_clone->get_decrypted_params() );
			}

			// Test connection.
			$clone->do_test();

			$for_save[]   = $clone->get_encrypted_params();
			$for_return[] = $clone->get_decrypted_params( false );
		}

		update_option( self::OPTION_PREFIX . $code, $for_save, false );
		return $for_return;
	}

	/**
	 * Check if an integration code has at least one connected account.
	 */
	public function has_connected( string $code ): bool {
		$accounts = $this->get_accounts( $code );
		foreach ( $accounts as $acc ) {
			if ( ( (int) ( $acc['_status'] ?? 0 ) ) === 1 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get connected account list for a category (for tool dropdowns, etc.)
	 *
	 * @param string      $category
	 * @param string|null $code_filter  Restrict to one code.
	 * @return array [ 'code-index' => 'Name — Profile name', ... ]
	 */
	public function get_connected_list( string $category, ?string $code_filter = null ): array {
		$this->load();
		$list = [];

		foreach ( $this->integrations as $code => $integ ) {
			if ( $integ->get_category() !== $category ) {
				continue;
			}
			if ( $code_filter && $code !== $code_filter ) {
				continue;
			}

			$accounts = $this->get_accounts( $code );
			foreach ( $accounts as $idx => $acc ) {
				if ( ( (int) ( $acc['_status'] ?? 0 ) ) === 1 ) {
					$label = $integ->get_name() . ' — ' . ( $acc['name'] ?? ( $idx + 1 ) );
					$list[ $code . '-' . $idx ] = $label;
				}
			}
		}

		return $list;
	}

	/**
	 * Get all integration metadata for admin UI.
	 */
	public function get_admin_data(): array {
		$this->load();
		$data = [];

		foreach ( $this->integrations as $code => $integ ) {
			$info              = $integ->to_admin_array();
			$info['accounts']  = $this->get_accounts( $code, true );
			$info['connected'] = $this->has_connected( $code );
			$data[ $code ]     = $info;
		}

		return $data;
	}

	/* ═══════════════════════════════════════════
	 *  Channel Account CRUD (PHASE 0.37 — R-CH-2)
	 *
	 *  All channel credential storage flows through here.
	 *  Accounts are stored as indexed array under OPTION_PREFIX + code.
	 *  Each account carries a _uid (stable slug key) for addressability.
	 * ═══════════════════════════════════════════ */

	/**
	 * Save (add or update) a single channel account.
	 *
	 * If $account_data contains a non-empty '_uid', update the matching slot.
	 * Otherwise, append a new account with a fresh _uid.
	 *
	 * @param string $code         Integration code (e.g. 'zalo_bot').
	 * @param array  $account_data Plain-text account fields. Encrypted fields are encrypted on save.
	 * @return array|WP_Error Saved account (without private params), or WP_Error on failure.
	 */
	public function save_channel_account( string $code, array $account_data ) {
		$integ = $this->get( $code );
		if ( ! $integ ) {
			return new \WP_Error( 'not_found', "Integration '{$code}' not registered." );
		}

		$accounts = $this->get_accounts( $code );
		$uid      = sanitize_key( $account_data['_uid'] ?? '' );

		// Find existing slot index.
		$target_idx = null;
		if ( $uid ) {
			foreach ( $accounts as $idx => $acc ) {
				if ( ( $acc['_uid'] ?? '' ) === $uid ) {
					$target_idx = $idx;
					break;
				}
			}
		}

		$clone = clone $integ;

		if ( $target_idx !== null ) {
			// Update: merge new data over old, preserving private params if signal unchanged.
			$old_clone = clone $integ;
			$old_clone->set_account( $accounts[ $target_idx ] );
			$merged = array_merge( $accounts[ $target_idx ], $account_data );
			$clone->set_account( $merged );
			$clone->merge_private_from_old( $old_clone->get_decrypted_params() );
		} else {
			// New account: generate uid.
			if ( ! $uid ) {
				$uid = $code . '_' . substr( md5( uniqid( $code, true ) ), 0, 8 );
			}
			$account_data['_uid'] = $uid;
			$clone->set_account( $account_data );
		}

		$clone->do_test();
		$encrypted = $clone->get_encrypted_params();
		$encrypted['_uid'] = $uid;

		if ( $target_idx !== null ) {
			$accounts[ $target_idx ] = $encrypted;
		} else {
			$accounts[] = $encrypted;
		}

		update_option( self::OPTION_PREFIX . $code, $accounts, false );
		return $clone->get_decrypted_params( false );
	}

	/**
	 * List channel accounts — single code or all channel integrations.
	 *
	 * @param string|null $code  If null, returns accounts for all channel integrations.
	 * @param bool        $decrypt Decrypt private params. Default false (masked).
	 * @return array Keyed by "{code}/{_uid}" (single code) or "{code}" => [...accounts...].
	 */
	public function list_channel_accounts( ?string $code = null, bool $decrypt = false ): array {
		$this->load();

		if ( $code !== null ) {
			return $this->get_accounts( $code, $decrypt );
		}

		$out = [];
		foreach ( $this->integrations as $c => $integ ) {
			if ( $integ->get_category() === 'channel' ) {
				$out[ $c ] = $this->get_accounts( $c, $decrypt );
			}
		}
		return $out;
	}

	/**
	 * Delete a channel account by uid.
	 *
	 * @param string $code Integration code.
	 * @param string $uid  Account uid.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete_channel_account( string $code, string $uid ): bool {
		$accounts   = $this->get_accounts( $code );
		$filtered   = array_values( array_filter( $accounts, fn( $acc ) => ( $acc['_uid'] ?? '' ) !== $uid ) );
		if ( count( $filtered ) === count( $accounts ) ) {
			return false; // not found
		}
		update_option( self::OPTION_PREFIX . $code, $filtered, false );
		return true;
	}

	/**
	 * Update just the status fields of one account (after connection test).
	 *
	 * @param string $code         Integration code.
	 * @param string $uid          Account uid.
	 * @param array  $encrypted    Full encrypted account (from get_encrypted_params()).
	 * @return bool
	 */
	public function update_channel_account_status( string $code, string $uid, array $encrypted ): bool {
		$accounts = $this->get_accounts( $code );
		foreach ( $accounts as $idx => $acc ) {
			if ( ( $acc['_uid'] ?? '' ) === $uid ) {
				$accounts[ $idx ] = array_merge( $acc, $encrypted );
				update_option( self::OPTION_PREFIX . $code, $accounts, false );
				return true;
			}
		}
		return false;
	}
}
