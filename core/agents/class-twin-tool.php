<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Agents
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Phase 0.15 — Task 1.15.3
 * BizCity_Twin_Tool — Tool specification value object.
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinShell_Tool' ) ) return;

/**
 * BizCity TwinShell Tool
 *
 * Represents a callable tool that an agent may invoke. Vòng 1: spec only
 * (no HIL approval gate — `needs_approval` reserved for Vòng 2).
 *
 * NOTE: Renamed from `BizCity_Twin_Tool` to avoid collision with the legacy
 * Sprint 4.7a interface `BizCity_Twin_Tool` in core/twin-core/. The TwinShell
 * runtime (Phase 0.13/0.15) uses its own concrete class.
 *
 * Usage:
 *   $tool = new BizCity_TwinShell_Tool(
 *       'search_products',
 *       'Search the product catalog',
 *       ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
 *       function( array $args, array $ctx ) { return [...]; }
 *   );
 */
final class BizCity_TwinShell_Tool {

	/** @var string Tool identifier — snake_case */
	public $name;

	/** @var string Short description for the LLM */
	public $description;

	/** @var array JSON Schema for parameters */
	public $parameters_schema;

	/** @var callable function(array $args, array $ctx): mixed */
	private $execute_callback;

	/**
	 * Optional gate — return false to hide this tool for the current context.
	 * Signature: function(array $ctx): bool
	 *
	 * @var callable|null
	 */
	private $is_enabled_callback;

	/**
	 * Optional HIL gate — return true to require human approval before execution.
	 * Signature: function(array $args, array $ctx): bool
	 *
	 * Vòng 2 — Task 2.15.1.
	 *
	 * @var callable|bool|null  bool shorthand: true = always need approval, false/null = never
	 */
	private $needs_approval_callback;

	/**
	 * @param string             $name
	 * @param string             $description
	 * @param array              $parameters_schema  JSON Schema object
	 * @param callable           $execute_callback   function(array $args, array $ctx): mixed
	 * @param callable|null      $is_enabled_callback  function(array $ctx): bool (optional)
	 * @param callable|bool|null $needs_approval       function(array $args, array $ctx): bool, OR true/false shorthand
	 */
	public function __construct(
		string $name,
		string $description,
		array $parameters_schema,
		$execute_callback,
		$is_enabled_callback = null,
		$needs_approval = null
	) {
		$this->name                    = $name;
		$this->description             = $description;
		$this->parameters_schema       = $parameters_schema;
		$this->execute_callback        = $execute_callback;
		$this->is_enabled_callback     = $is_enabled_callback;
		$this->needs_approval_callback = $needs_approval;
	}

	/**
	 * Execute the tool with given arguments.
	 *
	 * @param array $args  Decoded JSON arguments from the LLM.
	 * @param array $ctx   Run context (user_id, blog_id, run_id, ...).
	 * @return mixed
	 */
	public function execute( array $args, array $ctx = [] ) {
		return call_user_func( $this->execute_callback, $args, $ctx );
	}

	/**
	 * Check whether this tool is available in the current context.
	 *
	 * @param array $ctx
	 * @return bool  Always true when no is_enabled_callback is set.
	 */
	public function is_enabled( array $ctx = [] ): bool {
		if ( $this->is_enabled_callback === null ) {
			return true;
		}
		return (bool) call_user_func( $this->is_enabled_callback, $ctx );
	}

	/**
	 * Whether this tool call requires human approval (HIL).
	 *
	 * Vòng 2 — Task 2.15.1.
	 *
	 * @param array $args  Decoded JSON arguments from the LLM.
	 * @param array $ctx   Run context.
	 * @return bool
	 */
	public function needs_approval( array $args = [], array $ctx = [] ): bool {
		if ( $this->needs_approval_callback === null ) {
			return false;
		}
		if ( is_bool( $this->needs_approval_callback ) ) {
			return $this->needs_approval_callback;
		}
		return (bool) call_user_func( $this->needs_approval_callback, $args, $ctx );
	}

	/**
	 * Return the OpenAI-compatible function spec for this tool.
	 *
	 * @return array
	 */
	public function to_function_spec(): array {
		return [
			'type'     => 'function',
			'function' => [
				'name'        => $this->name,
				'description' => $this->description,
				'parameters'  => $this->parameters_schema,
			],
		];
	}
}
