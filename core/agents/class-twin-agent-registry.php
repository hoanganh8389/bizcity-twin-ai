<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.15 — Task 1.15.2
 * BizCity_Twin_Agent_Registry — Agent registration and resolution.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Agent_Registry' ) ) return;

/**
 * BizCity Twin Agent Registry
 *
 * Singleton registry that maps agent names to BizCity_Twin_Agent instances.
 * Agents register themselves via the WP filter `bizcity_register_agent`:
 *
 *   add_filter( 'bizcity_register_agent', function( array $agents ): array {
 *       $agents['my_agent'] = new BizCity_Twin_Agent( 'my_agent', 'Instructions...' );
 *       return $agents;
 *   } );
 *
 * The registry is resolved lazily on first call to get() or all().
 */
final class BizCity_Twin_Agent_Registry {

	/** @var self|null */
	private static $instance = null;

	/** @var array<string, BizCity_TwinShell_Agent>|null  null = not yet resolved */
	private $agents = null;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Registration via WP filter (called at runtime, not boot time)
	 * ================================================================ */

	/**
	 * Resolve the agent map via the `bizcity_register_agent` filter.
	 * Called once (lazy) and cached.
	 *
	 * @return array<string, BizCity_Twin_Agent>
	 */
	private function resolve(): array {
		if ( $this->agents !== null ) {
			return $this->agents;
		}

		/**
		 * Filter: bizcity_register_agent
		 *
		 * Modules add BizCity_Twin_Agent instances to this array.
		 * Key = agent name (string).
		 *
		 * @param array<string, BizCity_Twin_Agent> $agents
		 * @return array<string, BizCity_Twin_Agent>
		 */
		$raw = apply_filters( 'bizcity_register_agent', [] );

		$this->agents = [];
		foreach ( $raw as $name => $agent ) {
			if ( $agent instanceof BizCity_TwinShell_Agent ) {
				$this->agents[ (string) $name ] = $agent;
			}
		}

		return $this->agents;
	}

	/* ================================================================
	 *  Public API
	 * ================================================================ */

	/**
	 * Resolve and return a specific agent by name.
	 *
	 * @param string $name
	 * @return BizCity_TwinShell_Agent|null  Null if not registered.
	 */
	public function get( string $name ): ?BizCity_TwinShell_Agent {
		$agents = $this->resolve();
		return $agents[ $name ] ?? null;
	}

	/**
	 * Return all registered agents.
	 *
	 * @return array<string, BizCity_TwinShell_Agent>
	 */
	public function all(): array {
		return $this->resolve();
	}

	/**
	 * Check whether an agent with the given name is registered.
	 *
	 * @param string $name
	 * @return bool
	 */
	public function has( string $name ): bool {
		return $this->get( $name ) !== null;
	}

	/**
	 * Flush the resolved agent cache (useful for testing).
	 */
	public function flush(): void {
		$this->agents = null;
	}
}
