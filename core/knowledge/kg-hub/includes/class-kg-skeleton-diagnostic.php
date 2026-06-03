<?php
/**
 * BizCity KG-Hub — Notebook Skeleton Diagnostics (PHASE-0-RULE-SKELETON)
 *
 * Single-responsibility diagnostic class for the Skeleton-First foundation
 * introduced in Sprint 0★ of PHASE-0-RULE-SKELETON.  Intentionally decoupled
 * from the general `BizCity_Diagnostics` tool so KG-Hub diagnostics live
 * alongside the KG-Hub classes they inspect.
 *
 * Exposed via `wp bizcity diag skeleton-*` (delegated from
 * `BizCity_Diagnostics::dispatch`) and the browser entry
 * `?cmd=skeleton-*` on the same dispatcher.
 *
 * Methods:
 *   audit_blog( $blog_id )          — per-blog health snapshot
 *   audit_network()                 — multisite walk of audit_blog
 *   rebuild( $notebook_id, $stuck ) — re-queue single or all stuck/failed
 *   roadmap( $blog_id )             — phase scorecard (PASS/FAIL/SKIP per milestone)
 *   fix( $dry_run, $blog_id )       — bundled remediation (locks + requeue + cache)
 *   logs( $limit, $blog_id )        — tail recent reflect-job events
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md
 * @see        PHASE-0-RULE-NAMESPACE.md
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Diagnostic {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 99 );
	}

	public function register_menu() {
		add_submenu_page(
			'tools.php',
			'KG Skeleton Diagnostics',
			'KG Skeleton',
			'manage_options',
			'bizcity-kg-skeleton-diag',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }

		$cmd     = isset( $_GET['cmd'] )      ? sanitize_key( wp_unslash( (string) $_GET['cmd'] ) ) : '';
		$network = ! empty( $_GET['network'] );
		$blog_id = isset( $_GET['blog'] )     ? (int) $_GET['blog']     : 0;
		$nb      = isset( $_GET['notebook'] ) ? (int) $_GET['notebook'] : 0;
		$stuck   = ! empty( $_GET['stuck'] );
		$dry     = ! empty( $_GET['dry_run'] ) || ! empty( $_GET['dry-run'] );
		$limit   = isset( $_GET['limit'] )    ? (int) $_GET['limit']    : 20;

		$mutating = in_array( $cmd, [ 'skeleton-rebuild', 'skeleton-fix' ], true );
		$nonce_ok = ! $mutating || ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( (string) $_GET['_wpnonce'], 'bizcity_kg_skel_diag' ) );

		$result = null;
		if ( $cmd && $nonce_ok ) {
			switch ( $cmd ) {
				case 'skeleton-audit':
					$result = $network ? $this->audit_network() : $this->audit_blog( $blog_id );
					break;
				case 'skeleton-rebuild':
					$result = $this->rebuild( $nb, $stuck || $nb <= 0, $blog_id );
					break;
				case 'skeleton-roadmap':
					$result = $this->roadmap( $blog_id );
					break;
				case 'skeleton-fix':
					$result = $this->fix( $dry, $blog_id );
					break;
				case 'skeleton-logs':
					$result = $this->logs( $limit, $blog_id );
					break;
				default:
					$result = [ 'ok' => false, 'reason' => 'unknown cmd', 'cmd' => $cmd ];
			}
		} elseif ( $cmd && ! $nonce_ok ) {
			$result = [ 'ok' => false, 'reason' => 'invalid nonce' ];
		}

		$nonce = wp_create_nonce( 'bizcity_kg_skel_diag' );
		$btn   = function ( $label, $args ) use ( $nonce ) {
			$args['page'] = 'bizcity-kg-skeleton-diag';
			if ( in_array( $args['cmd'] ?? '', [ 'skeleton-rebuild', 'skeleton-fix' ], true ) ) {
				$args['_wpnonce'] = $nonce;
			}
			$url = add_query_arg( $args, admin_url( 'tools.php' ) );
			return '<a class="button" style="margin:2px" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		};

		echo '<div class="wrap"><h1>BizCity KG — Notebook Skeleton Diagnostics</h1>';
		echo '<p>PHASE-0-RULE-SKELETON — notebook skeleton lifecycle health, rebuild, roadmap &amp; repair.</p>';

		echo '<h2>Read-only</h2>';
		echo $btn( '🔎 Audit (this blog)', [ 'cmd' => 'skeleton-audit' ] );
		if ( is_multisite() ) {
			echo $btn( '🌐 Audit (network)', [ 'cmd' => 'skeleton-audit', 'network' => 1 ] );
		}
		echo $btn( '🗺️ Roadmap',  [ 'cmd' => 'skeleton-roadmap' ] );
		echo $btn( '📜 Logs (20)', [ 'cmd' => 'skeleton-logs', 'limit' => 20 ] );

		echo '<h2>Mutating</h2>';
		echo $btn( '🧪 Fix (dry-run)',       [ 'cmd' => 'skeleton-fix', 'dry_run' => 1 ] );
		echo $btn( '🛠 Fix (apply)',         [ 'cmd' => 'skeleton-fix' ] );
		echo $btn( '♻ Rebuild stuck/failed',     [ 'cmd' => 'skeleton-rebuild', 'stuck' => 1 ] );

		echo '<h2>Custom (notebook ID)</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '" style="margin-bottom:12px">';
		echo '<input type="hidden" name="page" value="bizcity-kg-skeleton-diag">';
		echo '<input type="hidden" name="cmd"  value="skeleton-rebuild">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">';
		echo '<label>Notebook ID: <input type="number" name="notebook" min="1" style="width:100px"></label> ';
		echo '<button class="button button-primary" type="submit">Rebuild single notebook</button>';
		echo '</form>';

		if ( $cmd ) {
			echo '<h2>$ ' . esc_html( $cmd ) . '</h2>';
			echo '<pre style="background:#1e1e1e;color:#eee;padding:12px;border-radius:6px;max-height:600px;overflow:auto;white-space:pre-wrap">';
			echo esc_html( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			echo '</pre>';
		}
		echo '</div>';
	}

	// ----------------------------------------------------------------
	// Constants
	// ----------------------------------------------------------------

	/** Seconds above which a `building` row is considered crashed. */
	const STUCK_SECONDS = 900;

	/** Max notebooks one rebuild call may re-queue. */
	const MAX_REQUEUE   = 200;

	// ----------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------

	private function required_columns(): array {
		return [
			'skeleton_json',
			'skeleton_version',
			'skeleton_built_at',
			'skeleton_status',
		];
	}

	/** Snapshot of pending reflect jobs across both AS and WP-Cron. */
	private function queue_snapshot(): array {
		$hook   = 'bizcity_kg_skeleton_reflect_job';
		$as_cnt = null;
		$as_grp = 'bizcity-kg-skeleton';

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions( [
				'hook'     => $hook,
				'group'    => $as_grp,
				'status'   => 'pending',
				'per_page' => 0,
			], 'ids' );
			$as_cnt  = is_array( $pending ) ? count( $pending ) : 0;
		}

		$cron_cnt = 0;
		$cron     = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				if ( ! empty( $events[ $hook ] ) && is_array( $events[ $hook ] ) ) {
					$cron_cnt += count( $events[ $hook ] );
				}
			}
		}

		return [
			'hook'             => $hook,
			'action_scheduler' => [
				'available' => function_exists( 'as_schedule_single_action' ),
				'group'     => $as_grp,
				'pending'   => $as_cnt,
			],
			'wp_cron_pending'  => $cron_cnt,
		];
	}

	private function roadmap_item( string $id, string $title, bool $ok, array $evidence = [], bool $skip = false ): array {
		return [
			'id'       => $id,
			'title'    => $title,
			'status'   => $skip ? 'SKIP' : ( $ok ? 'PASS' : 'FAIL' ),
			'evidence' => $evidence,
		];
	}

	// ----------------------------------------------------------------
	// §1 — audit_blog / audit_network
	// ----------------------------------------------------------------

	public function audit_blog( int $blog_id = 0 ): array {
		global $wpdb;
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();

		try {
			$classes = [
				'BizCity_KG_Database'         => class_exists( 'BizCity_KG_Database' ),
				'BizCity_KG_Skeleton_Adapter' => class_exists( 'BizCity_KG_Skeleton_Adapter' ),
				'BizCity_KG_Skeleton_Service' => class_exists( 'BizCity_KG_Skeleton_Service' ),
				'BizCity_KG_Skeleton_Prompt'  => class_exists( 'BizCity_KG_Skeleton_Prompt' ),
				'BizCity_KG_Skeleton_REST'    => class_exists( 'BizCity_KG_Skeleton_REST' ),
			];

			if ( ! $classes['BizCity_KG_Database'] ) {
				return [
					'ok'      => false,
					'blog_id' => $blog_id,
					'reason'  => 'BizCity_KG_Database not loaded — KG hub not bootstrapped on this blog',
					'classes' => $classes,
				];
			}

			$tbl   = BizCity_KG_Database::instance()->tbl_notebooks();
			$prev  = $wpdb->suppress_errors( true );
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl );
			$wpdb->suppress_errors( $prev );

			if ( ! $exists ) {
				return [
					'ok'      => false,
					'blog_id' => $blog_id,
					'reason'  => 'kg_notebooks table missing — run `wp bizcity diag repair`',
					'classes' => $classes,
					'table'   => $tbl,
				];
			}

			// Schema check — all 4 Sprint 0★ columns present.
			$cols    = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}", 0 );
			$cols    = is_array( $cols ) ? $cols : [];
			$needed  = $this->required_columns();
			$missing = array_values( array_diff( $needed, $cols ) );

			if ( $missing ) {
				return [
					'ok'              => false,
					'blog_id'         => $blog_id,
					'reason'          => 'skeleton columns missing — bump KG SCHEMA_VERSION + repair',
					'missing_columns' => $missing,
					'classes'         => $classes,
				];
			}

			// Status histogram.
			$rows = $wpdb->get_results(
				"SELECT COALESCE(NULLIF(skeleton_status, ''), 'none') AS s, COUNT(*) AS c
				   FROM {$tbl}
				   GROUP BY s",
				ARRAY_A
			);
			$counts = [
				'none'     => 0,
				'pending'  => 0,
				'building' => 0,
				'ready'    => 0,
				'stale'    => 0,
				'failed'   => 0,
			];
			$other = 0;
			foreach ( (array) $rows as $r ) {
				$s = (string) $r['s'];
				$c = (int) $r['c'];
				if ( array_key_exists( $s, $counts ) ) {
					$counts[ $s ] = $c;
				} else {
					$other += $c;
				}
			}
			$total = array_sum( $counts ) + $other;

			// Stuck = status='building' AND no update within STUCK_SECONDS.
			$stuck_threshold = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS );
			$stuck_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, owner_id, name, skeleton_version, skeleton_built_at, updated_at
				   FROM {$tbl}
				  WHERE skeleton_status = 'building'
				    AND ( skeleton_built_at IS NULL OR skeleton_built_at < %s )
				    AND ( updated_at         IS NULL OR updated_at         < %s )
				  ORDER BY updated_at ASC
				  LIMIT 25",
				$stuck_threshold, $stuck_threshold
			), ARRAY_A );

			$failed_rows = $wpdb->get_results(
				"SELECT id, owner_id, name, skeleton_version, skeleton_built_at, updated_at
				   FROM {$tbl}
				  WHERE skeleton_status = 'failed'
				  ORDER BY updated_at DESC
				  LIMIT 25",
				ARRAY_A
			);

			$queue = $this->queue_snapshot();

			// Index check.
			$idx_rows  = $wpdb->get_results(
				"SHOW INDEX FROM {$tbl} WHERE Key_name = 'idx_nb_skeleton_status'",
				ARRAY_A
			);
			$has_index = is_array( $idx_rows ) && count( $idx_rows ) > 0;

			$ok = ( $counts['failed'] === 0 )
			      && ( count( $stuck_rows ) === 0 )
			      && $has_index;

			return [
				'ok'        => $ok,
				'blog_id'   => $blog_id,
				'reference' => 'PHASE-0-RULE-SKELETON Sprint 0★',
				'classes'   => $classes,
				'schema'    => [
					'table'         => $tbl,
					'columns_ok'    => true,
					'index_present' => $has_index,
				],
				'totals'    => [
					'notebooks' => $total,
					'other'     => $other,
				] + $counts,
				'stuck'     => [
					'count'     => count( $stuck_rows ),
					'threshold' => self::STUCK_SECONDS . 's',
					'rows'      => $stuck_rows,
				],
				'failed'    => [
					'count' => count( $failed_rows ),
					'rows'  => $failed_rows,
				],
				'queue'     => $queue,
			];
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	public function audit_network(): array {
		if ( ! is_multisite() ) {
			return [ 'ok' => false, 'reason' => 'not multisite — use skeleton-audit (single blog)' ];
		}
		$blogs = get_sites( [ 'fields' => 'ids', 'number' => 0 ] );
		$out   = [];
		$ok    = true;
		foreach ( (array) $blogs as $bid ) {
			$row = $this->audit_blog( (int) $bid );
			$out[ (int) $bid ] = $row;
			if ( empty( $row['ok'] ) ) {
				$ok = false;
			}
		}
		return [ 'ok' => $ok, 'blog_count' => count( $blogs ), 'blogs' => $out ];
	}

	// ----------------------------------------------------------------
	// §2 — rebuild
	// ----------------------------------------------------------------

	/**
	 * Re-queue rebuild for one notebook ($notebook_id > 0), OR for every
	 * notebook in {failed, stuck-building} when $stuck = true.
	 * Bounded by MAX_REQUEUE.
	 */
	public function rebuild( int $notebook_id = 0, bool $stuck = false, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			if ( ! class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
				return [ 'ok' => false, 'reason' => 'adapter not loaded' ];
			}
			if ( $notebook_id > 0 ) {
				BizCity_KG_Skeleton_Adapter::mark_dirty( $notebook_id );
				return [
					'ok'          => true,
					'mode'        => 'single',
					'notebook_id' => $notebook_id,
					'blog_id'     => $blog_id > 0 ? $blog_id : get_current_blog_id(),
				];
			}
			if ( ! $stuck ) {
				return [ 'ok' => false, 'reason' => 'pass --notebook=N or --stuck' ];
			}

			global $wpdb;
			$tbl       = BizCity_KG_Database::instance()->tbl_notebooks();
			$threshold = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS );
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$tbl}
				  WHERE ( skeleton_status = 'failed' )
				     OR ( skeleton_status = 'building'
				          AND ( updated_at IS NULL OR updated_at < %s ) )
				  ORDER BY updated_at ASC
				  LIMIT %d",
				$threshold, self::MAX_REQUEUE
			) );
			$ids = array_map( 'intval', (array) $ids );

			foreach ( $ids as $id ) {
				BizCity_KG_Skeleton_Adapter::mark_dirty( $id );
			}
			return [
				'ok'        => true,
				'mode'      => 'stuck',
				'requeued'  => count( $ids ),
				'cap'       => self::MAX_REQUEUE,
				'blog_id'   => $blog_id > 0 ? $blog_id : get_current_blog_id(),
				'notebooks' => $ids,
			];
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	// ----------------------------------------------------------------
	// §3 — roadmap: phase milestone scorecard
	//
	// Scores every milestone shipped under PHASE-0-RULE-SKELETON
	// (rule docs, namespace migration, F-1..F-12, Sprint 0.5
	// hardening) against the live codebase + database.
	// Returns PASS / FAIL / SKIP per item + overall score %.
	// ----------------------------------------------------------------

	public function roadmap( int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			// Plugin root: bizcity-twin-ai/core/knowledge/kg-hub/includes/ → 4 levels up.
			$plugin_dir = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );

			$file_exists = function ( string $rel ) use ( $plugin_dir ): bool {
				return is_file( $plugin_dir . '/' . ltrim( $rel, '/' ) );
			};
			$class_has_const = function ( string $cls, string $const ): bool {
				return class_exists( $cls ) && defined( $cls . '::' . $const );
			};
			$class_has_method = function ( string $cls, string $method ): bool {
				return class_exists( $cls ) && method_exists( $cls, $method );
			};

			// REST routes registered (after rest_api_init).
			$rest_routes = [];
			if ( did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
				$rest_routes = array_keys( rest_get_server()->get_routes() );
			}
			$has_route = function ( string $needle ) use ( $rest_routes ): bool {
				foreach ( $rest_routes as $r ) {
					if ( strpos( $r, $needle ) !== false ) { return true; }
				}
				return false;
			};

			$milestones = [];

			// ---- Rule docs ----
			$milestones[] = $this->roadmap_item( 'docs.rule-skeleton',
				'PHASE-0-RULE-SKELETON.md present',
				$file_exists( 'PHASE-0-RULE-SKELETON.md' ),
				[ 'path' => 'PHASE-0-RULE-SKELETON.md' ] );
			$milestones[] = $this->roadmap_item( 'docs.rule-namespace',
				'PHASE-0-RULE-NAMESPACE.md present',
				$file_exists( 'PHASE-0-RULE-NAMESPACE.md' ),
				[ 'path' => 'PHASE-0-RULE-NAMESPACE.md' ] );
			$milestones[] = $this->roadmap_item( 'docs.phase-6.5-design',
				'PHASE-6.5-DOC-V2-DESIGN.md present',
				$file_exists( 'PHASE-6.5-DOC-V2-DESIGN.md' ),
				[ 'path' => 'PHASE-6.5-DOC-V2-DESIGN.md' ] );

			// ---- Namespace migration ----
			$alias_ok = class_exists( 'BZKG_Skeleton_Adapter' )
			            && class_exists( 'BizCity_KG_Skeleton_Adapter' );
			$milestones[] = $this->roadmap_item( 'namespace.classes',
				'BZKG_* → BizCity_KG_Skeleton_* renamed (with class_alias for B/C)',
				class_exists( 'BizCity_KG_Skeleton_Adapter' )
				&& class_exists( 'BizCity_KG_Skeleton_Service' )
				&& class_exists( 'BizCity_KG_Skeleton_Prompt' )
				&& class_exists( 'BizCity_KG_Skeleton_REST' ),
				[ 'legacy_alias' => $alias_ok ] );

			$milestones[] = $this->roadmap_item( 'namespace.rest',
				'REST namespace bizcity/kg/v1/notebook(s) registered',
				$has_route( 'bizcity/kg/v1/notebook' ),
				[
					'new_route'    => $has_route( 'bizcity/kg/v1/notebook' ),
					'legacy_route' => $has_route( 'bzkg/v1/notebook' ),
					'sample'       => array_values( array_filter( $rest_routes, function ( $r ) {
						return strpos( $r, 'bizcity/kg/v1/notebook' ) !== false
						       || strpos( $r, 'bzkg/v1/notebook' ) !== false;
					} ) ),
				] );

			// ---- DB schema (Sprint 0★) ----
			global $wpdb;
			$cols_present = [];
			$index_ok     = false;
			$tbl          = '';
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
				$prev = $wpdb->suppress_errors( true );
				$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$tbl}", 0 );
				$wpdb->suppress_errors( $prev );
				$cols         = is_array( $cols ) ? $cols : [];
				$cols_present = array_values( array_intersect( $this->required_columns(), $cols ) );
				$idx_rows     = $wpdb->get_results(
					"SHOW INDEX FROM {$tbl} WHERE Key_name = 'idx_nb_skeleton_status'",
					ARRAY_A
				);
				$index_ok = is_array( $idx_rows ) && count( $idx_rows ) > 0;
			}
			$milestones[] = $this->roadmap_item( 'schema.columns',
				'kg_notebooks has skeleton_{json,version,built_at,status} (Sprint 0★)',
				count( $cols_present ) === count( $this->required_columns() ),
				[ 'table' => $tbl, 'columns_present' => $cols_present ] );
			$milestones[] = $this->roadmap_item( 'schema.index',
				'idx_nb_skeleton_status present',
				$index_ok,
				[ 'table' => $tbl ] );

			// ---- F-series guarantees ----
			$milestones[] = $this->roadmap_item( 'F-1.chunk-loader-filter',
				"filter `bizcity_kg_skeleton_load_chunks` callable (default loader)",
				$class_has_method( 'BizCity_KG_Skeleton_Service', 'run_job' ),
				[ 'filter' => 'bizcity_kg_skeleton_load_chunks' ] );

			$milestones[] = $this->roadmap_item( 'F-2.legacy-source-bridge',
				'mark_dirty_by_source($cortex,$src,$tbl) bridge present',
				$class_has_method( 'BizCity_KG_Skeleton_Service', 'mark_dirty_by_source' ),
				[] );

			$milestones[] = $this->roadmap_item( 'F-3.transient-lock',
				'transient lock TTL 180s (LOCK_TTL constant)',
				$class_has_const( 'BizCity_KG_Skeleton_Service', 'LOCK_TTL' ),
				[
					'ttl' => $class_has_const( 'BizCity_KG_Skeleton_Service', 'LOCK_TTL' )
						? constant( 'BizCity_KG_Skeleton_Service::LOCK_TTL' ) : null,
				] );

			$cost_guard_loaded = class_exists( 'BizCity_KG_Cost_Guard' );
			$cost_guard_wired  = $class_has_const( 'BizCity_KG_Skeleton_Service', 'COST_GUARD_OPERATION' );
			$milestones[] = $this->roadmap_item( 'F-4.cost-guard',
				'BizCity_KG_Skeleton_Service pre-checks via BizCity_KG_Cost_Guard before LLM call',
				$cost_guard_loaded && $cost_guard_wired,
				[
					'guard_loaded' => $cost_guard_loaded,
					'service_wired' => $cost_guard_wired,
					'operation'    => $cost_guard_wired ? constant( 'BizCity_KG_Skeleton_Service::COST_GUARD_OPERATION' ) : null,
				] );

			$milestones[] = $this->roadmap_item( 'F-5.cap-filter',
				"filter `bizcity_kg_user_can_read_notebook` (user_can_read)",
				$class_has_method( 'BizCity_KG_Skeleton_Adapter', 'user_can_read' ),
				[] );

			$milestones[] = $this->roadmap_item( 'F-6.prompt-block-cap',
				'PROMPT_BLOCK_MAX_BYTES = 16384',
				$class_has_const( 'BizCity_KG_Skeleton_Adapter', 'PROMPT_BLOCK_MAX_BYTES' ),
				[
					'max_bytes' => $class_has_const( 'BizCity_KG_Skeleton_Adapter', 'PROMPT_BLOCK_MAX_BYTES' )
						? constant( 'BizCity_KG_Skeleton_Adapter::PROMPT_BLOCK_MAX_BYTES' ) : null,
				] );

			$milestones[] = $this->roadmap_item( 'F-7.action-scheduler',
				'Action Scheduler preferred (group bizcity-kg-skeleton)',
				$class_has_const( 'BizCity_KG_Skeleton_Service', 'AS_GROUP' )
				&& function_exists( 'as_schedule_single_action' ),
				[
					'group'     => $class_has_const( 'BizCity_KG_Skeleton_Service', 'AS_GROUP' )
						? constant( 'BizCity_KG_Skeleton_Service::AS_GROUP' ) : null,
					'as_loaded' => function_exists( 'as_schedule_single_action' ),
				] );

			$milestones[] = $this->roadmap_item( 'F-10.cache-flush',
				'flush_cache() clears both content + status caches',
				$class_has_method( 'BizCity_KG_Skeleton_Adapter', 'flush_cache' ),
				[] );

			$milestones[] = $this->roadmap_item( 'F-11.chunk-content-cap',
				'CHUNK_CONTENT_MAX_CHARS = 3000',
				$class_has_const( 'BizCity_KG_Skeleton_Service', 'CHUNK_CONTENT_MAX_CHARS' ),
				[
					'max_chars' => $class_has_const( 'BizCity_KG_Skeleton_Service', 'CHUNK_CONTENT_MAX_CHARS' )
						? constant( 'BizCity_KG_Skeleton_Service::CHUNK_CONTENT_MAX_CHARS' ) : null,
				] );

			$milestones[] = $this->roadmap_item( 'F-12.validate-hard-limits',
				'BizCity_KG_Skeleton_Prompt::validate() with LABEL/SUMMARY/KEY_POINT word caps',
				$class_has_method( 'BizCity_KG_Skeleton_Prompt', 'validate' )
				&& $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'LABEL_MAX_WORDS' )
				&& $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'SUMMARY_MAX_WORDS' )
				&& $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'KEY_POINT_MAX_WORDS' ),
				[
					'label_max'   => $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'LABEL_MAX_WORDS' )
						? constant( 'BizCity_KG_Skeleton_Prompt::LABEL_MAX_WORDS' ) : null,
					'summary_max' => $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'SUMMARY_MAX_WORDS' )
						? constant( 'BizCity_KG_Skeleton_Prompt::SUMMARY_MAX_WORDS' ) : null,
					'kp_max'      => $class_has_const( 'BizCity_KG_Skeleton_Prompt', 'KEY_POINT_MAX_WORDS' )
						? constant( 'BizCity_KG_Skeleton_Prompt::KEY_POINT_MAX_WORDS' ) : null,
				] );

			// ---- Diagnostics tooling ----
			$milestones[] = $this->roadmap_item( 'tooling.skeleton-audit',
				'skeleton-audit + audit_blog() present in BizCity_KG_Skeleton_Diagnostic',
				method_exists( $this, 'audit_blog' ),
				[] );
			$milestones[] = $this->roadmap_item( 'tooling.skeleton-rebuild',
				'skeleton-rebuild + rebuild() present in BizCity_KG_Skeleton_Diagnostic',
				method_exists( $this, 'rebuild' ),
				[] );
			$milestones[] = $this->roadmap_item( 'tooling.skeleton-roadmap',
				'skeleton-roadmap + roadmap() present in BizCity_KG_Skeleton_Diagnostic',
				method_exists( $this, 'roadmap' ),
				[] );
			$milestones[] = $this->roadmap_item( 'tooling.skeleton-fix',
				'skeleton-fix + fix() present in BizCity_KG_Skeleton_Diagnostic',
				method_exists( $this, 'fix' ),
				[] );
			$milestones[] = $this->roadmap_item( 'tooling.skeleton-logs',
				'skeleton-logs + logs() present in BizCity_KG_Skeleton_Diagnostic',
				method_exists( $this, 'logs' ),
				[] );

			// ---- Shared FE assets (Sprint 0★ S0.7–S0.9, RULE-3 / RULE-4) ----
			// Co-located with kg-hub package (single source of truth, no shared/ pollution).
			$shared_dir = $plugin_dir . '/core/knowledge/kg-hub/assets/';
			$shared_rel = 'core/knowledge/kg-hub/assets/';
			$milestones[] = $this->roadmap_item( 'S0.9.fe-hook',
				'kg-hub bztwin-skeleton.js (useNotebookSkeleton helper, RULE-3)',
				is_file( $shared_dir . 'bztwin-skeleton.js' ),
				[ 'path' => $shared_rel . 'bztwin-skeleton.js' ] );
			$milestones[] = $this->roadmap_item( 'S0.7.fe-selector',
				'<bztwin-notebook-selector> web component shipped (RULE-3)',
				is_file( $shared_dir . 'bztwin-skeleton.js' )
					&& (bool) preg_match( '/bztwin-notebook-selector/i', (string) @file_get_contents( $shared_dir . 'bztwin-skeleton.js' ) ),
				[] );
			$milestones[] = $this->roadmap_item( 'S0.8.fe-preview',
				'<bztwin-skeleton-preview> web component shipped (RULE-4)',
				is_file( $shared_dir . 'bztwin-skeleton.js' )
					&& (bool) preg_match( '/bztwin-skeleton-preview/i', (string) @file_get_contents( $shared_dir . 'bztwin-skeleton.js' ) ),
				[] );
			$milestones[] = $this->roadmap_item( 'S0.fe-css',
				'kg-hub bztwin-skeleton.css present',
				is_file( $shared_dir . 'bztwin-skeleton.css' ),
				[ 'path' => $shared_rel . 'bztwin-skeleton.css' ] );
			$milestones[] = $this->roadmap_item( 'S0.fe-enqueue',
				'PHP loader enqueues shared skeleton assets + localized config',
				class_exists( 'BizCity_KG_Skeleton_Assets' )
					&& method_exists( 'BizCity_KG_Skeleton_Assets', 'enqueue' ),
				[] );

			// ---- bizcity-doc plugin conformance (Sprint 0★ S0.11–S0.15, RULE-7) ----
			// bizcity-doc lives nested under bizcity-twin-ai/plugins/bizcity-doc.
			$bcdoc_dir   = $plugin_dir . '/plugins/bizcity-doc';
			$bcdoc_root  = is_dir( $bcdoc_dir ) ? $bcdoc_dir : null;
			$installer   = $bcdoc_root ? $bcdoc_root . '/includes/class-installer.php'   : null;
			$rest_api    = $bcdoc_root ? $bcdoc_root . '/includes/class-rest-api.php'    : null;
			$frontend    = $bcdoc_root ? $bcdoc_root . '/includes/class-frontend.php'    : null;
			// Sprint 0★: selector was moved from PromptInput.tsx → SourceSidebar.tsx.
			// Check SourceSidebar first; fall back to PromptInput for forward-compat.
			$source_sidebar = $bcdoc_root ? $bcdoc_root . '/app/src/components/SourceSidebar.tsx' : null;
			$prompt_form    = $bcdoc_root ? $bcdoc_root . '/app/src/components/PromptInput.tsx'   : null;
			$selector_src  = '';
			if ( $source_sidebar && is_file( $source_sidebar ) ) {
				$selector_src = (string) @file_get_contents( $source_sidebar );
			} elseif ( $prompt_form && is_file( $prompt_form ) ) {
				$selector_src = (string) @file_get_contents( $prompt_form );
			}
			$milestones[] = $this->roadmap_item( 'S0.2.bcdoc-schema',
				'bzdoc_documents.source_skeleton_version column declared',
				$installer && is_file( $installer )
					&& (bool) preg_match( '/source_skeleton_version/', (string) @file_get_contents( $installer ) ),
				[ 'path' => 'plugins/bizcity-doc/includes/class-installer.php' ] );
			$milestones[] = $this->roadmap_item( 'S0.11.bcdoc-stream',
				'bizcity-doc handle_generate_stream() injects skeleton via Adapter::get_prompt_block()',
				$rest_api && is_file( $rest_api )
					&& (bool) preg_match( '/get_prompt_block/', (string) @file_get_contents( $rest_api ) ),
				[ 'path' => 'plugins/bizcity-doc/includes/class-rest-api.php' ] );
			$bcdoc_enqueue = $frontend && is_file( $frontend )
					&& (bool) preg_match( '/BizCity_KG_Skeleton_Assets::enqueue/', (string) @file_get_contents( $frontend ) );
			$milestones[] = $this->roadmap_item( 'S0.fe-enqueue.bcdoc',
				'bizcity-doc Frontend enqueues bztwin-skeleton via BizCity_KG_Skeleton_Assets::enqueue()',
				$bcdoc_enqueue,
				[ 'path' => 'plugins/bizcity-doc/includes/class-frontend.php' ] );
			$prompt_src = $prompt_form && is_file( $prompt_form ) ? (string) @file_get_contents( $prompt_form ) : '';
			// S0.12/S0.13 — notebook selector lives in SourceSidebar (after Sprint 0★ refactor).
			$has_selector = (bool) preg_match( '/bztwin-notebook-selector/', $selector_src )
				|| (bool) preg_match( '/bztwin-notebook-selector/', $prompt_src );
			$has_preview  = (bool) preg_match( '/bztwin-skeleton-preview|show-preview/', $selector_src )
				|| (bool) preg_match( '/bztwin-skeleton-preview|show-preview/', $prompt_src );
			$milestones[] = $this->roadmap_item( 'S0.12.bcdoc-selector',
				'bizcity-doc mounts <bztwin-notebook-selector> (SourceSidebar or PromptInput)',
				$has_selector,
				[ 'path' => 'plugins/bizcity-doc/app/src/components/SourceSidebar.tsx' ] );
			$milestones[] = $this->roadmap_item( 'S0.13.bcdoc-preview',
				'bizcity-doc mounts skeleton preview (show-preview attr or <bztwin-skeleton-preview>)',
				$has_preview,
				[ 'path' => 'plugins/bizcity-doc/app/src/components/SourceSidebar.tsx' ] );
			$has_stale = is_file( $shared_dir . 'bztwin-skeleton.js' )
					&& (bool) preg_match( '/bztwin-skel-stale-banner/', (string) @file_get_contents( $shared_dir . 'bztwin-skeleton.js' ) );
			$milestones[] = $this->roadmap_item( 'S0.14.fe-stale',
				'<bztwin-skeleton-preview> renders a stale banner when status==="stale"',
				$has_stale,
				[] );
			// S0.15 sign-off — auto-PASS once 11/12/13/14 + S0.2 + S0.fe-enqueue.bcdoc are green.
			$rule7_ok = $bcdoc_enqueue && $has_selector && $has_preview && $has_stale
				&& $installer && is_file( $installer )
				&& (bool) preg_match( '/source_skeleton_version/', (string) @file_get_contents( $installer ) )
				&& $rest_api && is_file( $rest_api )
				&& (bool) preg_match( '/get_prompt_block/', (string) @file_get_contents( $rest_api ) );
			$milestones[] = $this->roadmap_item( 'S0.15.bcdoc-rule7',
				'bizcity-doc RULE-7 conformance sign-off (auto from S0.2/0.11/0.12/0.13/0.14)',
				$rule7_ok,
				[ 'depends_on' => [ 'S0.2', 'S0.11', 'S0.12', 'S0.13', 'S0.14' ] ] );

			// ---- Score ----
			$pass = $fail = $skip = 0;
			foreach ( $milestones as $m ) {
				if ( $m['status'] === 'PASS' ) { $pass++; }
				elseif ( $m['status'] === 'SKIP' ) { $skip++; }
				else { $fail++; }
			}
			$gradable = $pass + $fail;
			$score    = $gradable > 0 ? (int) round( ( $pass / $gradable ) * 100 ) : 0;

			return [
				'ok'         => $fail === 0,
				'reference'  => 'PHASE-0-RULE-SKELETON',
				'blog_id'    => $blog_id > 0 ? $blog_id : get_current_blog_id(),
				'score'      => $score,
				'totals'     => [ 'pass' => $pass, 'fail' => $fail, 'skip' => $skip, 'count' => count( $milestones ) ],
				'milestones' => $milestones,
				'next_steps' => array_values( array_filter( array_map( function ( $m ) {
					if ( $m['status'] === 'FAIL' ) {
						return $m['id'] . ' — ' . $m['title'];
					}
					if ( $m['status'] === 'SKIP' ) {
						return $m['id'] . ' (deferred) — ' . $m['title'];
					}
					return null;
				}, $milestones ) ) ),
			];
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	// ----------------------------------------------------------------
	// §4 — fix: bundled remediation
	//
	// Composite: clear orphan transient locks + requeue stuck/failed
	// notebooks + flush adapter cache. Idempotent.
	// --dry-run reports what *would* run without mutating state.
	// ----------------------------------------------------------------

	public function fix( bool $dry_run = false, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			global $wpdb;
			$report = [
				'ok'        => true,
				'dry_run'   => $dry_run,
				'reference' => 'PHASE-0-RULE-SKELETON',
				'blog_id'   => $blog_id > 0 ? $blog_id : get_current_blog_id(),
				'actions'   => [],
			];

			// 1. Orphan transient locks.
			$locks = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				  WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_bizcity_kg_skel_lock_' ) . '%'
			) );
			$lock_count = is_array( $locks ) ? count( $locks ) : 0;
			$cleared    = 0;
			if ( ! $dry_run && $lock_count > 0 ) {
				foreach ( (array) $locks as $opt ) {
					$key = preg_replace( '/^_transient_/', '', (string) $opt );
					if ( $key && delete_transient( $key ) ) {
						$cleared++;
					}
				}
			}
			$report['actions'][] = [
				'step'    => 'clear-orphan-locks',
				'found'   => $lock_count,
				'cleared' => $cleared,
			];

			// 2. Requeue stuck / failed notebooks.
			if ( $dry_run ) {
				if ( class_exists( 'BizCity_KG_Database' ) ) {
					$tbl       = BizCity_KG_Database::instance()->tbl_notebooks();
					$threshold = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS );
					$count     = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$tbl}
						  WHERE skeleton_status = 'failed'
						     OR ( skeleton_status = 'building'
						          AND ( updated_at IS NULL OR updated_at < %s ) )",
						$threshold
					) );
					$report['actions'][] = [
						'step'         => 'requeue-stuck-failed',
						'would_requeue' => min( $count, self::MAX_REQUEUE ),
						'cap'          => self::MAX_REQUEUE,
					];
				}
			} else {
				$rebuild = $this->rebuild( 0, true, 0 );
				$report['actions'][] = [
					'step'     => 'requeue-stuck-failed',
					'requeued' => isset( $rebuild['requeued'] ) ? (int) $rebuild['requeued'] : 0,
					'cap'      => self::MAX_REQUEUE,
				];
			}

			// 3. Flush adapter cache.
			if ( ! $dry_run && class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
				BizCity_KG_Skeleton_Adapter::flush_cache( 0 );
			}
			$report['actions'][] = [
				'step'    => 'flush-adapter-cache',
				'applied' => ! $dry_run && class_exists( 'BizCity_KG_Skeleton_Adapter' ),
			];

			return $report;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	// ----------------------------------------------------------------
	// §5 — logs: recent-events tail
	//
	// Surfaces the last N reflect-job events from Action Scheduler
	// (with failure log messages) + most-recently-built notebooks.
	// ----------------------------------------------------------------

	public function logs( int $limit = 20, int $blog_id = 0 ): array {
		$switched = false;
		if ( $blog_id > 0 && is_multisite() && get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			$limit = max( 1, min( 100, $limit ) );
			$out   = [
				'ok'        => true,
				'reference' => 'PHASE-0-RULE-SKELETON',
				'blog_id'   => $blog_id > 0 ? $blog_id : get_current_blog_id(),
				'limit'     => $limit,
			];

			// Recent AS actions (any status).
			$actions = [];
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$ids = as_get_scheduled_actions( [
					'hook'     => 'bizcity_kg_skeleton_reflect_job',
					'group'    => 'bizcity-kg-skeleton',
					'per_page' => $limit,
					'orderby'  => 'date',
					'order'    => 'DESC',
				], 'ids' );
				if ( function_exists( 'ActionScheduler' ) && is_array( $ids ) ) {
					$store = \ActionScheduler::store();
					foreach ( $ids as $aid ) {
						$action = $store->fetch_action( (int) $aid );
						if ( ! $action || ! method_exists( $action, 'get_args' ) ) { continue; }
						$status  = method_exists( $store, 'get_status' ) ? (string) $store->get_status( (int) $aid ) : '';
						$date    = method_exists( $store, 'get_date' )   ? $store->get_date( (int) $aid )            : null;
						$actions[] = [
							'id'     => (int) $aid,
							'status' => $status,
							'date'   => $date ? $date->format( 'Y-m-d H:i:s' ) : null,
							'args'   => (array) $action->get_args(),
						];
					}
				} else {
					$actions = is_array( $ids ) ? array_map( 'intval', $ids ) : [];
				}
			}
			$out['as_actions'] = $actions;

			// Failure messages from AS log table.
			global $wpdb;
			$logs_tbl = $wpdb->prefix . 'actionscheduler_logs';
			$prev     = $wpdb->suppress_errors( true );
			$tbl_ok   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_tbl ) ) === $logs_tbl );
			$wpdb->suppress_errors( $prev );
			$failures = [];
			if ( $tbl_ok ) {
				$failures = $wpdb->get_results( $wpdb->prepare(
					"SELECT l.action_id, l.log_date_gmt, l.message
					   FROM {$logs_tbl} l
					   JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id
					  WHERE a.hook = %s
					    AND ( l.message LIKE %s OR l.message LIKE %s )
					  ORDER BY l.log_id DESC
					  LIMIT %d",
					'bizcity_kg_skeleton_reflect_job',
					'%failed%',
					'%error%',
					$limit
				), ARRAY_A );
			}
			$out['failure_logs'] = $failures ?: [];

			// Most recently built notebooks.
			$recent = [];
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
				$recent = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, owner_id, name, skeleton_status, skeleton_version, skeleton_built_at
					   FROM {$tbl}
					  WHERE skeleton_built_at IS NOT NULL
					  ORDER BY skeleton_built_at DESC
					  LIMIT %d",
					$limit
				), ARRAY_A );
			}
			$out['recent_built'] = $recent ?: [];

			return $out;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
