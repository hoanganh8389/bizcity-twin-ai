<?php
/**
 * BizCity Twin AI — Module Registry (Phase 0.99.3 dogfood).
 *
 * Implements the public `bizcity_register_module` filter declared in
 * `docs/extension/HOOKS.md`. Sub-plugin authors can register modules that
 * implement `BizCity_Module_Interface` (or extend `BizCity_Module_Base`)
 * and the framework will call `boot()` exactly once per module after
 * `plugins_loaded` priority 20, with requirement gating + duplicate-id
 * detection + per-module exception isolation.
 *
 * Backward compat: this is OPT-IN. Existing modules that still register
 * via `add_action('plugins_loaded', [Class, 'init'])` continue to work
 * unchanged. The registry only handles modules pushed through the filter.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinCore
 * @since      1.0.0  (Phase 0.99.3 — 2026-06-01)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Module_Registry' ) ) {

	final class BizCity_Module_Registry {

		/** @var BizCity_Module_Registry|null */
		private static $instance = null;

		/** @var array<string,array{module:BizCity_Module_Interface,booted:bool,error:string,duration_ms:int}> */
		private $modules = [];

		/** @var bool guard against double-boot at hook level. */
		private $booted_all = false;

		public static function instance() {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public static function init() {
			// Run AFTER core/* bootstraps had a chance to attach listeners.
			add_action( 'plugins_loaded', [ self::instance(), 'boot_all' ], 20 );
		}

		/**
		 * Discover + boot every registered module.
		 */
		public function boot_all() {
			if ( $this->booted_all ) {
				return;
			}
			$this->booted_all = true;

			/**
			 * Filter: bizcity_register_module
			 *
			 * Push modules implementing BizCity_Module_Interface onto the
			 * provided array.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int,BizCity_Module_Interface> $modules
			 */
			$modules = apply_filters( 'bizcity_register_module', [] );

			if ( ! is_array( $modules ) || empty( $modules ) ) {
				return;
			}

			foreach ( $modules as $module ) {
				$this->boot_one( $module );
			}
		}

		/**
		 * Boot one module with isolation + requirement gating.
		 *
		 * @param mixed $module Expected to implement BizCity_Module_Interface.
		 */
		private function boot_one( $module ) {
			if ( ! is_object( $module ) || ! ( $module instanceof BizCity_Module_Interface ) ) {
				$this->record(
					'__invalid_' . spl_object_hash( (object) $module ),
					$module,
					false,
					'Object does not implement BizCity_Module_Interface.',
					0
				);
				return;
			}

			$id = (string) $module->id();
			if ( $id === '' ) {
				$this->record( '__empty_id_' . spl_object_hash( $module ), $module, false, 'Module id is empty.', 0 );
				return;
			}
			if ( isset( $this->modules[ $id ] ) ) {
				$this->record( $id, $module, false, 'Duplicate module id.', 0 );
				return;
			}

			$req_err = $this->check_requirements( $module->requires() );
			if ( $req_err !== '' ) {
				$this->record( $id, $module, false, $req_err, 0 );
				return;
			}

			$t0 = microtime( true );
			try {
				$module->boot();
				$dur = (int) round( ( microtime( true ) - $t0 ) * 1000 );
				$this->record( $id, $module, true, '', $dur );
			} catch ( \Throwable $e ) {
				$dur = (int) round( ( microtime( true ) - $t0 ) * 1000 );
				$this->record( $id, $module, false, 'boot() threw: ' . $e->getMessage(), $dur );
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[BizCity] Module ' . $id . ' boot() failed: ' . $e->getMessage() );
				}
			}
		}

		/**
		 * Validate `requires()` map. Returns '' on success, error message otherwise.
		 *
		 * @param array<string,mixed> $req
		 * @return string
		 */
		private function check_requirements( $req ) {
			if ( ! is_array( $req ) ) {
				return '';
			}
			if ( ! empty( $req['php'] ) && version_compare( PHP_VERSION, (string) $req['php'], '<' ) ) {
				return sprintf( 'Requires PHP >= %s (have %s).', $req['php'], PHP_VERSION );
			}
			if ( ! empty( $req['wp'] ) ) {
				$wp_ver = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '0';
				if ( version_compare( $wp_ver, (string) $req['wp'], '<' ) ) {
					return sprintf( 'Requires WP >= %s (have %s).', $req['wp'], $wp_ver );
				}
			}
			if ( ! empty( $req['modules'] ) && is_array( $req['modules'] ) ) {
				foreach ( $req['modules'] as $dep_id ) {
					if ( ! isset( $this->modules[ (string) $dep_id ]['booted'] )
						|| $this->modules[ (string) $dep_id ]['booted'] !== true ) {
						return sprintf( 'Missing dependency module: %s.', $dep_id );
					}
				}
			}
			return '';
		}

		/**
		 * @param string                             $id
		 * @param mixed                              $module
		 * @param bool                               $booted
		 * @param string                             $error
		 * @param int                                $duration_ms
		 */
		private function record( $id, $module, $booted, $error, $duration_ms ) {
			$this->modules[ $id ] = [
				'module'      => $module instanceof BizCity_Module_Interface ? $module : null,
				'booted'      => (bool) $booted,
				'error'       => (string) $error,
				'duration_ms' => (int) $duration_ms,
			];
		}

		/**
		 * Public introspection — used by diagnostics probe + admin pages.
		 *
		 * @return array<string,array{id:string,version:string,booted:bool,error:string,duration_ms:int}>
		 */
		public function inventory() {
			$out = [];
			foreach ( $this->modules as $id => $row ) {
				$mod = $row['module'];
				$out[ $id ] = [
					'id'          => $id,
					'version'     => $mod ? (string) $mod->version() : '',
					'booted'      => (bool) $row['booted'],
					'error'       => (string) $row['error'],
					'duration_ms' => (int) $row['duration_ms'],
				];
			}
			return $out;
		}
	}
}

BizCity_Module_Registry::init();
