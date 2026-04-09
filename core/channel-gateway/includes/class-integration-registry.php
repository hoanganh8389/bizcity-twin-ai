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
}
