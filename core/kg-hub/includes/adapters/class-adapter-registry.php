<?php
/**
 * BizCity KG-Hub — Source Adapter Registry (E1)
 *
 * Singleton dispatcher that maps (ext, mime) → adapter instance.
 *
 * Registration order: built-ins first (in `register_defaults()`), then any
 * caller may add via the `bizcity_kg_source_adapters` filter:
 *
 *   add_filter( 'bizcity_kg_source_adapters', function( $registry ) {
 *       $registry->register( new My_Custom_Adapter() );
 *       return $registry;
 *   } );
 *
 * Resolution: iterate registered adapters in registration order, first
 * `supports()` true wins. MIME match counts the same as ext match — adapters
 * are responsible for ordering their own checks.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub\Adapters
 * @since      2026-05-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Adapter_Registry {

	/** @var BizCity_KG_Adapter_Registry|null */
	private static $instance = null;

	/** @var BizCity_KG_Source_Adapter[] */
	private $adapters = [];

	/** @var bool */
	private $defaults_loaded = false;

	private function __construct() {}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_defaults();
			// Allow other modules to plug in. They receive the registry instance
			// itself so they can call ->register() in-place.
			$out = apply_filters( 'bizcity_kg_source_adapters', self::$instance );
			if ( $out instanceof self ) {
				self::$instance = $out;
			}
		}
		return self::$instance;
	}

	/**
	 * Register a built-in adapter once.
	 */
	public function register( BizCity_KG_Source_Adapter $adapter ) {
		$this->adapters[] = $adapter;
		return $this;
	}

	/**
	 * Resolve the first adapter that claims to support given ext+mime.
	 *
	 * @param string $ext
	 * @param string $mime
	 * @return BizCity_KG_Source_Adapter|null
	 */
	public function resolve( $ext, $mime = '' ) {
		$ext  = strtolower( (string) $ext );
		$mime = strtolower( (string) $mime );
		foreach ( $this->adapters as $a ) {
			if ( $a::supports( $ext, $mime ) ) {
				/**
				 * Fires once per resolve so diagnostics + event stream can
				 * record which engine handled a given (ext,mime) pair.
				 * Phase 0.42 — LiteParse rollout observability.
				 *
				 * @param string $adapter_id  e.g. 'liteparse', 'pdf', 'office'
				 * @param string $ext
				 * @param string $mime
				 */
				do_action( 'bizcity_kg_adapter_chosen', $a::id(), $ext, $mime );
				return $a;
			}
		}
		do_action( 'bizcity_kg_adapter_chosen', '', $ext, $mime );
		return null;
	}

	/**
	 * @return BizCity_KG_Source_Adapter[]
	 */
	public function all() {
		return $this->adapters;
	}

	/**
	 * Built-in adapter registration. Keep small; load order = priority.
	 */
	private function register_defaults() {
		if ( $this->defaults_loaded ) {
			return;
		}
		$this->defaults_loaded = true;

		$dir = __DIR__;

		// Phase 0.42 — LiteParse (Tier-2) layout-preserving adapter. Registered
		// FIRST so it wins resolve() for Pro users; its supports() returns false
		// for Free / cron / anonymous contexts so Tier-1 PDF/Office still works.
		if ( file_exists( $dir . '/class-liteparse-adapter.php' ) ) {
			require_once $dir . '/class-liteparse-adapter.php';
			if ( class_exists( 'BizCity_KG_LiteParse_Adapter' ) ) {
				$this->register( new BizCity_KG_LiteParse_Adapter() );
			}
		}

		if ( file_exists( $dir . '/class-pdf-adapter.php' ) ) {
			require_once $dir . '/class-pdf-adapter.php';
			if ( class_exists( 'BizCity_KG_Pdf_Adapter' ) ) {
				$this->register( new BizCity_KG_Pdf_Adapter() );
			}
		}
		if ( file_exists( $dir . '/class-office-adapter.php' ) ) {
			require_once $dir . '/class-office-adapter.php';
			if ( class_exists( 'BizCity_KG_Office_Adapter' ) ) {
				$this->register( new BizCity_KG_Office_Adapter() );
			}
		}
		// Wave E0.AV — audio/video adapter (multimodal Vision LLM via gateway).
		if ( file_exists( $dir . '/class-av-adapter.php' ) ) {
			require_once $dir . '/class-av-adapter.php';
			if ( class_exists( 'BizCity_KG_AV_Adapter' ) ) {
				$this->register( new BizCity_KG_AV_Adapter() );
			}
		}
	}
}
