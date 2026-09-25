<?php
/**
 * Bizcity Twin AI — KG Source Registry
 *
 * Central registry that collects entries from the
 * `bizcity_kg_register_source_table` filter (PHASE-0-RULE-KG-HUB-CONTRACT.md §2).
 *
 * Each plugin/module that wants to participate in KG-Hub registers a
 * source-table descriptor with the following required fields:
 *
 *   slug          string  — unique key (e.g. "twinchat", "webchat", "notebooklm")
 *   label         string  — human label
 *   scope_type    string  — "notebook" | "project" | "session" | "doc" | "page" | …
 *   parent_fk     string  — FK column in sources_table (e.g. "notebook_id")
 *   sources_table string  — fully-qualified table name (with $wpdb->prefix)
 *   chunks_table  string  — fully-qualified chunks table name
 *   service_class string  — class implementing the ingest contract
 *
 * Optional:
 *   capability        string — required cap to read (default 'read')
 *   manage_capability string — required cap to ingest (default 'edit_posts')
 *   icon              string — dashicon slug
 *   list_scopes_cb    callable — fn(int $user_id): array of { id, label }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Registry {

	const REQUIRED_FIELDS = [
		'slug',
		'label',
		'scope_type',
		'parent_fk',
		'sources_table',
		'chunks_table',
		'service_class',
	];

	private static $instance = null;

	/** @var array<string,array> */
	private $registry = [];

	/** @var bool */
	private $loaded = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Run the filter and validate. Memoized per request.
	 *
	 * @return array<string,array>
	 */
	public function load() {
		if ( $this->loaded ) {
			return $this->registry;
		}
		$entries = apply_filters( 'bizcity_kg_register_source_table', [] );
		if ( ! is_array( $entries ) ) {
			$entries = [];
		}

		$out = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) continue;
			$norm = $this->normalize( $entry );
			if ( $norm === null ) continue;
			$out[ $norm['slug'] ] = $norm;
		}
		$this->registry = $out;
		$this->loaded   = true;
		return $this->registry;
	}

	/**
	 * Force re-load (used after late-registered plugins).
	 */
	public function reload() {
		$this->loaded = false;
		return $this->load();
	}

	public function all() {
		return $this->load();
	}

	/**
	 * Get registry entry by plugin slug.
	 *
	 * @param string $slug
	 * @return array|null
	 */
	public function get( $slug ) {
		$slug = (string) $slug;
		$reg  = $this->load();
		return $reg[ $slug ] ?? null;
	}

	/**
	 * Get all entries matching a scope_type.
	 *
	 * @param string $scope_type
	 * @return array<int,array>
	 */
	public function get_by_scope_type( $scope_type ) {
		$scope_type = (string) $scope_type;
		$out = [];
		foreach ( $this->load() as $entry ) {
			if ( $entry['scope_type'] === $scope_type ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Resolve the entry that owns a particular sources_table name.
	 */
	public function get_by_sources_table( $table ) {
		$table = (string) $table;
		foreach ( $this->load() as $entry ) {
			if ( $entry['sources_table'] === $table ) {
				return $entry;
			}
		}
		return null;
	}

	/* ──────────────────────  Internals  ────────────────────── */

	private function normalize( array $entry ) {
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $entry[ $field ] ) || ! is_string( $entry[ $field ] ) ) {
				if ( WP_DEBUG ) {
					error_log( '[BizCity_KG_Source_Registry] Missing required field: ' . $field
						. ' in entry ' . wp_json_encode( $entry ) );
				}
				return null;
			}
		}
		if ( ! class_exists( $entry['service_class'] ) ) {
			if ( WP_DEBUG ) {
				error_log( '[BizCity_KG_Source_Registry] service_class not found: ' . $entry['service_class'] );
			}
			return null;
		}

		return [
			'slug'              => sanitize_key( $entry['slug'] ),
			'label'             => (string) $entry['label'],
			'scope_type'        => sanitize_key( $entry['scope_type'] ),
			'parent_fk'         => sanitize_key( $entry['parent_fk'] ),
			'sources_table'     => (string) $entry['sources_table'],
			'chunks_table'      => (string) $entry['chunks_table'],
			'service_class'     => (string) $entry['service_class'],
			'capability'        => isset( $entry['capability'] ) ? (string) $entry['capability'] : 'read',
			'manage_capability' => isset( $entry['manage_capability'] ) ? (string) $entry['manage_capability'] : 'edit_posts',
			'icon'              => isset( $entry['icon'] ) ? (string) $entry['icon'] : 'dashicons-database',
			'list_scopes_cb'    => isset( $entry['list_scopes_cb'] ) && is_callable( $entry['list_scopes_cb'] )
				? $entry['list_scopes_cb']
				: null,
		];
	}
}
