<?php
/**
 * Twin Shell — Learning Hub Plugin SDK (Wave D).
 *
 * Cortex registry that lets any plugin (twinchat, bzdoc, webchat, intent, …)
 * contribute a learning-aggregator implementation, plus a widget registry for
 * the React Learning Hub bundle.
 *
 * Public extension points:
 *
 *   apply_filters( 'twin_shell_learning_scopes', array $scopes, int $user_id )
 *     → return [
 *         'cortex_id' => [
 *           'label'     => 'Twin Chat',
 *           'aggregator'=> callable( int $user_id, bool $site_scope ): array,
 *           'analytics' => callable( int $user_id, string $range, bool $site_scope ): array,
 *           'capability'=> 'read',
 *         ],
 *         …
 *       ]
 *
 *   do_action( 'twin_shell_learning_register_widgets', BizCity_Twin_Shell_Learning_SDK $registry )
 *     → call $registry->register_widget([ 'id'=>…, 'title'=>…, 'mount_point'=>…, 'asset_url'=>… ])
 *
 * The default 'twinchat' cortex is auto-registered when
 * BizCity_TwinChat_Learning_Aggregator is loadable.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell\Learning
 * @since 0.13.38
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Learning_SDK {

	const FILTER_SCOPES   = 'twin_shell_learning_scopes';
	const ACTION_WIDGETS  = 'twin_shell_learning_register_widgets';

	private static $instance = null;

	/** @var array<string, array> */
	private $widgets = [];

	/** @var array<string, array>|null */
	private $cortex_cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function bind() {
		// Auto-register the built-in twinchat cortex.
		add_filter( self::FILTER_SCOPES, [ $this, 'register_default_twinchat_cortex' ], 5, 2 );

		// Fire widget registration once, on `init` priority 8 so plugin bootstraps
		// (which run at priority 6) have already loaded.
		add_action( 'init', function () {
			do_action( self::ACTION_WIDGETS, $this );
		}, 8 );
	}

	// ── Cortex registry ─────────────────────────────────────────────────

	/**
	 * Default cortex: TwinChat aggregator.
	 *
	 * @internal
	 */
	public function register_default_twinchat_cortex( $scopes, $user_id ) {
		unset( $user_id );
		if ( ! is_array( $scopes ) ) {
			$scopes = [];
		}
		if ( ! isset( $scopes['twinchat'] ) && class_exists( 'BizCity_TwinChat_Learning_Aggregator' ) ) {
			$agg = BizCity_TwinChat_Learning_Aggregator::instance();
			$scopes['twinchat'] = [
				'label'      => 'Twin Chat',
				'capability' => 'read',
				'aggregator' => static function ( $uid, $site_scope ) use ( $agg ) {
					return $agg->summary( (int) $uid, (bool) $site_scope );
				},
				'analytics'  => static function ( $uid, $range, $site_scope ) use ( $agg ) {
					return $agg->analytics( (int) $uid, (string) $range, (bool) $site_scope );
				},
			];
		}
		return $scopes;
	}

	/**
	 * All registered cortex descriptors visible to $user_id (cap-checked).
	 *
	 * Caching is intentionally per-request; aggregator results have their own
	 * transient layer.
	 *
	 * @param int $user_id
	 * @return array<string, array>
	 */
	public function cortexes( $user_id ) {
		$uid = (int) $user_id;
		if ( null === $this->cortex_cache ) {
			$raw = apply_filters( self::FILTER_SCOPES, [], $uid );
			$out = [];
			if ( is_array( $raw ) ) {
				foreach ( $raw as $id => $desc ) {
					$id = sanitize_key( (string) $id );
					if ( '' === $id || ! is_array( $desc ) ) {
						continue;
					}
					if ( empty( $desc['aggregator'] ) || ! is_callable( $desc['aggregator'] ) ) {
						continue;
					}
					$cap = isset( $desc['capability'] ) ? (string) $desc['capability'] : 'read';
					if ( $cap && ! user_can( $uid, $cap ) ) {
						continue;
					}
					$out[ $id ] = [
						'id'         => $id,
						'label'      => isset( $desc['label'] ) ? (string) $desc['label'] : $id,
						'capability' => $cap,
						'aggregator' => $desc['aggregator'],
						'analytics'  => isset( $desc['analytics'] ) && is_callable( $desc['analytics'] ) ? $desc['analytics'] : null,
					];
				}
			}
			$this->cortex_cache = $out;
		}
		return $this->cortex_cache;
	}

	/**
	 * @param string $cortex_id
	 * @param int    $user_id
	 * @return array|null
	 */
	public function get_cortex( $cortex_id, $user_id ) {
		$cortexes = $this->cortexes( $user_id );
		$id = sanitize_key( (string) $cortex_id );
		return $cortexes[ $id ] ?? null;
	}

	/**
	 * Reset cortex cache (useful after dynamic re-register, e.g. from CLI).
	 */
	public function flush_cortex_cache() {
		$this->cortex_cache = null;
	}

	// ── Widget registry (for future per-plugin custom widgets) ──────────

	public function register_widget( array $descriptor ) {
		$id = sanitize_key( (string) ( $descriptor['id'] ?? '' ) );
		if ( '' === $id ) {
			return false;
		}
		$this->widgets[ $id ] = [
			'id'          => $id,
			'title'       => isset( $descriptor['title'] ) ? (string) $descriptor['title'] : $id,
			'mount_point' => isset( $descriptor['mount_point'] ) ? sanitize_key( (string) $descriptor['mount_point'] ) : 'extras',
			'asset_url'   => isset( $descriptor['asset_url'] ) ? esc_url_raw( (string) $descriptor['asset_url'] ) : '',
			'capability'  => isset( $descriptor['capability'] ) ? (string) $descriptor['capability'] : 'read',
		];
		return true;
	}

	/**
	 * @param int $user_id
	 * @return array<int, array>
	 */
	public function widgets( $user_id ) {
		$uid = (int) $user_id;
		$out = [];
		foreach ( $this->widgets as $w ) {
			if ( $w['capability'] && ! user_can( $uid, $w['capability'] ) ) {
				continue;
			}
			$out[] = $w;
		}
		return $out;
	}
}
