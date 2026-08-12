<?php
/**
 * BizCity Twin AI — Core public contracts (Phase 0.99.2).
 *
 * This file defines the OPT-IN interfaces and abstract base class that
 * 3rd-party sub-plugin authors can rely on for forward compatibility
 * across `bizcity-twin-ai` framework versions.
 *
 * Backward compat: existing modules using the legacy `add_action('plugins_loaded',
 * [Class, 'init'])` pattern continue to work. Adopting these contracts is
 * RECOMMENDED for new modules but NOT required.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Contracts
 * @since      1.0.0  (Phase 0.99.2 — 2026-06-01)
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────────────────────────────────
 * 1. Module contract
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Module_Interface' ) ) {

	/**
	 * A "module" is a self-contained capability bundle that registers
	 * REST routes, hooks, FE bundles, cron jobs, schema, etc. when booted.
	 *
	 * The framework calls `boot()` exactly once after `plugins_loaded`.
	 * Implementations MUST be idempotent (multiple `boot()` calls = no-op).
	 *
	 * Stable since: 1.0.0
	 */
	interface BizCity_Module_Interface {

		/**
		 * Stable machine id (`snake.case` or `dot.notation` allowed).
		 * Used for diagnostics rows, filter prefixes, logging.
		 *
		 * @return string e.g. `modules.twinchat`
		 */
		public function id();

		/**
		 * Semver of the module (NOT the framework version).
		 *
		 * @return string e.g. `1.2.0`
		 */
		public function version();

		/**
		 * Hard requirements. Framework will SKIP `boot()` and emit
		 * an admin notice if any requirement fails.
		 *
		 * Recognised keys:
		 *   - `php`    : minimum PHP version (`7.4`)
		 *   - `wp`     : minimum WP version (`6.0`)
		 *   - `framework`: minimum bizcity-twin-ai framework version
		 *   - `modules`: array of module ids that must be present
		 *
		 * @return array<string,mixed>
		 */
		public function requires();

		/**
		 * One-shot bootstrap. Register hooks here, NOT in constructor.
		 * Called inside `plugins_loaded` priority 20.
		 *
		 * @return void
		 */
		public function boot();
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 2. Module base class (optional helper)
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! class_exists( 'BizCity_Module_Base' ) ) {

	/**
	 * Convenience base class. Sub-plugin authors can extend this instead of
	 * implementing the full interface. Override only what differs.
	 *
	 * @since 1.0.0
	 */
	abstract class BizCity_Module_Base implements BizCity_Module_Interface {

		/** @var bool guard against double-boot */
		private $booted = false;

		/** Module id — child class MUST override or set $module_id property. */
		protected $module_id = '';

		/** Module version — child class MUST override or set $module_version. */
		protected $module_version = '0.0.0';

		/** Requirements map — child class can override. */
		protected $module_requires = [
			'php' => '7.4',
			'wp'  => '6.0',
		];

		public function id() {
			return (string) $this->module_id;
		}

		public function version() {
			return (string) $this->module_version;
		}

		public function requires() {
			return (array) $this->module_requires;
		}

		final public function boot() {
			if ( $this->booted ) {
				return;
			}
			$this->booted = true;
			$this->register();
		}

		/**
		 * Child class implements actual hook/route/cron registration here.
		 *
		 * @return void
		 */
		abstract protected function register();
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 3. Shared identity helper contracts
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Phone_Normalizer_Interface' ) ) {

	/**
	 * Canonical phone identity contract used by CRM, Woo, loyalty and channels.
	 * Implementations MUST return a leading-zero digit string or '';
	 * callers MUST NOT introduce a second normalization policy.
	 *
	 * @since 1.1.0 (PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS)
	 */
	interface BizCity_Phone_Normalizer_Interface {
		public static function normalize_vn( string $raw ): string;
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 4. LLM Client contract (server-side, R-GW-8)
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_LLM_Client_Interface' ) ) {

	/**
	 * Contract that `BizCity_LLM_Client` (and any 3rd-party replacement)
	 * MUST satisfy. Sub-plugins type-hint against this interface so they
	 * can be unit-tested with mock implementations.
	 *
	 * Stable since: 1.0.0
	 *
	 * @see core/bizcity-llm/includes/class-llm-client.php
	 */
	interface BizCity_LLM_Client_Interface {

		/**
		 * Whether `bizcity_llm_gateway_url` + `bizcity_llm_api_key` are
		 * configured. Caller MUST check before invoking other methods.
		 *
		 * @return bool
		 */
		public function is_ready();

		/**
		 * Chat completion via gateway.
		 *
		 * @param array<int,array{role:string,content:mixed}> $messages
		 * @param array<string,mixed>                          $options
		 *        Recognised: model, temperature, max_tokens, stream, purpose,
		 *                    timeout, response_format.
		 * @return array{success:bool,message?:string,_degraded?:bool,data?:mixed}
		 */
		public function chat( array $messages, array $options = [] );

		/**
		 * Image generation via gateway. Forwards `input_images[]` for
		 * multimodal vision-grounded models (Gemini-Image, GPT-Image).
		 *
		 * @param string              $prompt
		 * @param array<string,mixed> $options model, size, n, input_images[],
		 *                                     stream, timeout.
		 * @return array{success:bool,images?:array,error?:string,_degraded?:bool}
		 */
		public function generate_image( $prompt, array $options = [] );

		/**
		 * Embedding vectors via gateway.
		 *
		 * @param string|array<int,string> $inputs
		 * @param array<string,mixed>      $options model, dimensions.
		 * @return array{success:bool,vectors?:array,error?:string,_degraded?:bool}
		 */
		public function embed( $inputs, array $options = [] );

		/**
		 * Read entitlement (tier, quota, expiry) for current site.
		 * Fail-OPEN: returns synthetic `tier=free` + `_degraded:true` on
		 * gateway error (NEVER throw).
		 *
		 * @return array{tier:string,quota:array,_degraded?:bool}
		 */
		public function get_entitlement();
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 4. Tool contract (registry-pluggable AI tool)
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Tool_Interface' ) ) {

	/**
	 * Contract for a single tool that an agent can call (function-calling
	 * style). Register via `bizcity_register_tools` filter or
	 * `BizCity_Twin_Tool_Registry::register()`.
	 *
	 * Stable since: 1.0.0
	 */
	interface BizCity_Tool_Interface {

		/** Stable id (`snake.case`). */
		public function id();

		/** Human label (i18n-friendly). */
		public function label();

		/**
		 * OpenAI / OpenRouter function-calling schema.
		 *
		 * @return array{name:string,description:string,parameters:array}
		 */
		public function schema();

		/**
		 * Execute with sanitized $args (already validated against schema).
		 *
		 * @param array<string,mixed> $args
		 * @param array<string,mixed> $context  caller_id, conv_id, user_id…
		 * @return array{success:bool,result?:mixed,error?:string}
		 */
		public function run( array $args, array $context = [] );
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 5. Agent contract (already in core/agents/, re-declared here for stability)
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Agent_Interface' ) ) {

	/**
	 * Minimal stable contract for an agent (subset of `BizCity_Twin_Agent`).
	 * Future framework versions guarantee this surface stays compatible.
	 *
	 * Stable since: 1.0.0
	 */
	interface BizCity_Agent_Interface {

		public function id();

		public function name();

		/** @return array<string,mixed> */
		public function meta();

		/**
		 * @param string              $input  user message
		 * @param array<string,mixed> $context  conv_id, user_id, history…
		 * @return array{success:bool,reply?:string,tool_calls?:array,_degraded?:bool}
		 */
		public function run( $input, array $context = [] );
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 6. Security capability guard contract
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Capability_Guard_Interface' ) ) {

	/**
	 * Public contract for least-privilege permission enforcement.
	 *
	 * Stable since: 1.1.0
	 */
	interface BizCity_Capability_Guard_Interface {

		/**
		 * [2026-07-30 Johnny Chu] PHASE-1.22-SEC — permission check contract.
		 *
		 * @param string              $scope   e.g. `kg.read`, `content.publish`.
		 * @param array<string,mixed> $context tenant/site/user/action context.
		 * @return bool
		 */
		public function can( $scope, array $context = [] );

		/**
		 * Resolve scope level for tenant isolation checks.
		 *
		 * @param string $scope
		 * @return string one of `tenant|site|user|none`.
		 */
		public function scope_level( $scope );
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 7. Runtime execution policy contract
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Runtime_Policy_Interface' ) ) {

	/**
	 * Public contract for runtime reliability policy used by tools/channels.
	 *
	 * Stable since: 1.1.0
	 */
	interface BizCity_Runtime_Policy_Interface {

		/**
		 * [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — runtime contract provider.
		 *
		 * @return array<string,mixed> idempotency/retry/dlq/lock/circuit policy.
		 */
		public function policy();
	}
}

/* ─────────────────────────────────────────────────────────────────────────
 * 8. Admin navigation contract (opt-in)
 * ──────────────────────────────────────────────────────────────────────── */

if ( ! interface_exists( 'BizCity_Admin_Navigation_Provider_Interface' ) ) {

	/**
	 * Opt-in metadata provider for the central admin navigation registry.
	 *
	 * Providers describe navigation only. They MUST NOT register WordPress menu
	 * pages directly, query DB/Redis, repair schema or load heavy renderers.
	 *
	 * Stable since: 1.2.0
	 */
	interface BizCity_Admin_Navigation_Provider_Interface {

		/** @return string Stable provider id. */
		public function navigation_id();

		/** @return array<int,array<string,mixed>> Contract-shaped navigation items. */
		public function navigation_items();
	}
}

if ( ! class_exists( 'BizCity_Admin_Navigation_Item' ) ) {

	/**
	 * Small DTO that keeps navigation metadata separate from page renderers.
	 *
	 * Stable since: 1.2.0
	 */
	final class BizCity_Admin_Navigation_Item {

		/** @var array<string,mixed> */
		private $data;

		/**
		 * [2026-08-11 Johnny Chu] PHASE-1.26-CONTRACT — normalize one opt-in navigation item without executing registration.
		 *
		 * @param array<string,mixed> $data
		 */
		public function __construct( array $data ) {
			$this->data = $data;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return $this->data;
		}

		/** @return string */
		public function id(): string {
			return isset( $this->data['id'] ) ? (string) $this->data['id'] : '';
		}

		/** @return string */
		public function slug(): string {
			return isset( $this->data['slug'] ) ? (string) $this->data['slug'] : '';
		}

		/** @return string */
		public function parent(): string {
			return isset( $this->data['parent'] ) ? (string) $this->data['parent'] : '';
		}

		/** @return string */
		public function slot(): string {
			return isset( $this->data['slot'] ) ? (string) $this->data['slot'] : '';
		}

		/** @return string */
		public function origin(): string {
			return isset( $this->data['origin'] ) ? (string) $this->data['origin'] : 'extension';
		}
	}
}
