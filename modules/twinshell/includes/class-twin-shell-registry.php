<?php
/**
 * Twin Shell — Plugin Registry.
 *
 * Single source of truth for plugins that can be hosted inside the Twin Shell
 * iframe wrapper at /twin/. Plugins register via the
 * `bizcity_twin_register_plugins` filter.
 *
 * Schema (per Phase 0.11 §3.2):
 *   id             string   Unique plugin id (e.g. 'twinchat', 'doc')
 *   label          string   Human-readable label (i18n-translated by caller)
 *   icon           string   lucide-react icon id
 *   emoji          string   optional, takes precedence over icon (used by legacy sidebars)
 *   mode           string   'embed' (default) | 'home' | 'workspace' | 'route' | 'link'
 *   public_slug    string   Front-end URL fragment for the plugin page (e.g. '/twinchat/')
 *   target_url     string   Absolute URL for `mode = 'link'` entries (admin pages etc.)
 *   capability     string   WP capability required (default 'read')
 *   section        string   'top' | 'bottom'
 *   params         array    Whitelisted query keys forwarded into the iframe URL
 *   desc           string   Optional one-line description
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Shell_Registry {

	/** Filter applied to merge external plugin entries. */
	const FILTER = 'bizcity_twin_register_plugins';

	/** Reserved query keys never forwarded to the iframe URL. */
	const RESERVED_KEYS = [ 'plugin', '_view', '_t', 'bizcity_iframe' ];

	private static $instance = null;
	private $cache = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return all registered plugins, normalized.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$raw = apply_filters( self::FILTER, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$out  = [];
		$seen = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$id = sanitize_key( $entry['id'] );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;

			$out[] = [
				'id'          => $id,
				'label'       => isset( $entry['label'] ) ? (string) $entry['label'] : $id,
				'icon'        => isset( $entry['icon'] ) ? (string) $entry['icon'] : 'puzzle',
				'emoji'       => isset( $entry['emoji'] ) ? (string) $entry['emoji'] : '',
				'mode'        => isset( $entry['mode'] ) ? (string) $entry['mode'] : 'embed',
				'public_slug' => isset( $entry['public_slug'] ) ? (string) $entry['public_slug'] : '',
				'target_url'  => isset( $entry['target_url'] ) ? esc_url_raw( $entry['target_url'] ) : '',
				'capability'  => isset( $entry['capability'] ) ? (string) $entry['capability'] : 'read',
				'section'     => ( isset( $entry['section'] ) && 'bottom' === $entry['section'] ) ? 'bottom' : 'top',
				'params'      => isset( $entry['params'] ) && is_array( $entry['params'] ) ? array_values( array_unique( array_map( 'sanitize_key', $entry['params'] ) ) ) : [],
				'desc'        => isset( $entry['desc'] ) ? (string) $entry['desc'] : '',
			];
		}

		$this->cache = $out;
		return $out;
	}

	/**
	 * Get a single plugin entry by id.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public function get( $id ) {
		$id = sanitize_key( $id );
		foreach ( $this->all() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Get the default plugin id (first 'top' entry in registry, or 'twinchat'
	 * if present).
	 *
	 * @return string
	 */
	public function default_id() {
		$plugins = $this->all();
		foreach ( $plugins as $p ) {
			if ( 'twinchat' === $p['id'] ) {
				return 'twinchat';
			}
		}
		if ( ! empty( $plugins ) ) {
			return $plugins[0]['id'];
		}
		return '';
	}

	/**
	 * Check whether the given REQUEST_URI path matches the public_slug of any
	 * registered plugin. Used by the bridge auto-injector.
	 *
	 * @param string $request_uri
	 * @return array|null Matched plugin entry, or null.
	 */
	public function match_request_uri( $request_uri ) {
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return null;
		}
		$path = '/' . trim( $path, '/' ) . '/';

		foreach ( $this->all() as $p ) {
			$slug = trim( (string) $p['public_slug'], '/' );
			if ( '' === $slug ) {
				continue;
			}
			$slug = '/' . $slug . '/';
			// Match either exact slug or slug-prefix (so /tool-image/foo/ counts).
			if ( $path === $slug || 0 === strpos( $path, $slug ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Build the iframe URL for a given plugin id, forwarding only whitelisted
	 * params from the current /twin/ request.
	 *
	 * @param string $plugin_id
	 * @param array  $query  Current query (typically $_GET).
	 * @return string Absolute URL or '' if plugin not found / has no public_slug.
	 */
	public function build_iframe_url( $plugin_id, $query = [] ) {
		$p = $this->get( $plugin_id );
		if ( ! $p || empty( $p['public_slug'] ) ) {
			return '';
		}

		$forward = [];
		$allowed = $p['params'];
		foreach ( $query as $k => $v ) {
			if ( in_array( $k, self::RESERVED_KEYS, true ) ) {
				continue;
			}
			if ( ! empty( $allowed ) && ! in_array( $k, $allowed, true ) ) {
				continue;
			}
			if ( is_scalar( $v ) ) {
				$forward[ sanitize_key( $k ) ] = (string) $v;
			}
		}

		// Tell the embedded plugin it's running inside the shell.
		// Uses the legacy `bizcity_iframe=1` flag so existing pages that already
		// hide their header/footer/adminbar on this query var keep working.
		$forward['bizcity_iframe'] = '1';

		$url = home_url( '/' . trim( $p['public_slug'], '/' ) . '/' );
		if ( ! empty( $forward ) ) {
			$url = add_query_arg( $forward, $url );
		}
		return $url;
	}

	/**
	 * Map registry entries to the legacy webchat sidebar shape.
	 *
	 * Output schema (consumed by modules/webchat React sidebar):
	 *   [ 'slug' => id, 'label' => label, 'icon' => emoji, 'type' => 'link',
	 *     'src' => url, 'section' => 'top'|'bottom', 'mode' => mode, 'pluginId' => id ]
	 *
	 * - For `mode = 'link'` entries → `src` = `target_url` as-is.
	 * - For everything else (embed/home/workspace/route) → `src` routes through
	 *   the Twin Shell at `/twin/?plugin=<id>` so the page opens inside the
	 *   ActivityBar shell.
	 * - Filtered through current_user_can() against each entry's capability.
	 * - Final result passed through the legacy `bizcity_sidebar_nav` filter
	 *   for back-compat (other plugins may still hook this; new plugins should
	 *   prefer `bizcity_twin_register_plugins`).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function as_sidebar_nav() {
		$shell_url = home_url( '/twin/' );
		$out       = [];

		foreach ( $this->all() as $p ) {
			if ( ! current_user_can( $p['capability'] ?: 'read' ) ) {
				continue;
			}

			if ( 'link' === $p['mode'] ) {
				$src = $p['target_url'] !== ''
					? $p['target_url']
					: ( $p['public_slug'] !== '' ? home_url( '/' . trim( $p['public_slug'], '/' ) . '/' ) : '' );
			} else {
				$src = add_query_arg( [ 'plugin' => $p['id'] ], $shell_url );
			}
			if ( '' === $src ) {
				continue;
			}

			$out[] = [
				'slug'     => $p['id'],
				'label'    => $p['label'],
				'icon'     => $p['emoji'] !== '' ? $p['emoji'] : '',
				'type'     => 'link',
				'src'      => esc_url_raw( $src ),
				'section'  => $p['section'],
				'mode'     => $p['mode'],
				'pluginId' => $p['id'],
			];
		}

		return apply_filters( 'bizcity_sidebar_nav', $out );
	}

	/** Reset cache — only useful in tests. */
	public function reset_cache() {
		$this->cache = null;
	}
}
