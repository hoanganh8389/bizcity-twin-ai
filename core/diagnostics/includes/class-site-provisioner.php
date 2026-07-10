<?php
/**
 * Bizcity Twin AI — Site Provisioner
 *
 * Unified table-installer orchestrator. Solves the multisite "new blog has
 * MISSING tables" problem by running every module's installer callback at
 * `wp_initialize_site` (new blog created) and `admin_init` (self-heal on
 * existing blog admin pageload, throttled).
 *
 * Modules register via filter `bizcity_register_installers`:
 *
 *   add_filter( 'bizcity_register_installers', function( $list ) {
 *       $list[] = [
 *           'id'           => 'knowledge',
 *           'label'        => 'Knowledge (sources/chunks)',
 *           'callback'     => [ 'BizCity_Knowledge_Database', 'maybe_create_tables' ],
 *           'version_opt'  => 'bizcity_knowledge_db_version', // optional, for diag display
 *           'expected_ver' => '3.21.0',                       // optional, for diag display
 *       ];
 *       return $list;
 *   } );
 *
 * Public API:
 *   - BizCity_Site_Provisioner::run_all( bool $force = false ): array
 *   - BizCity_Site_Provisioner::get_installers(): array
 *   - BizCity_Site_Provisioner::get_log(): array
 *
 * Force re-run URL (admin only): ?bizcity_provision=1
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since 2026-05-21
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Site_Provisioner', false ) ) {
	return;
}

class BizCity_Site_Provisioner {

	const LOG_OPTION        = 'bizcity_provisioner_log';
	const LOG_MAX           = 50;
	const SELF_HEAL_TRANS   = 'bizcity_provisioner_recent';
	// [2026-06-11 Johnny Chu] HOTFIX — tăng từ 300s (5 min) lên 3600s (1 hour).
	// Sau khi version-gate + write_log guard được fix, steady-state self-heal
	// không làm gì cả (100% skipped). Chỉ cần chạy đủ thường để phát hiện
	// bảng bị miss trên shard mới — 1 tiếng là đủ an toàn.
	const SELF_HEAL_TTL     = 3600; // 1 hour between self-heal passes
	const FORCE_QUERY_ARG   = 'bizcity_provision';

	/** Register WP hooks (idempotent). */
	public static function register_hooks(): void {
		// New blog in multisite → install for that blog.
		add_action( 'wp_initialize_site', [ __CLASS__, 'on_new_site' ], 99, 1 );

		// Self-heal on admin pageload (throttled, capability-gated).
		add_action( 'admin_init', [ __CLASS__, 'maybe_self_heal' ], 5 );
	}

	/**
	 * Collect installer registrations.
	 *
	 * @return array<int,array{id:string,label:string,callback:callable,version_opt?:string,expected_ver?:string}>
	 */
	public static function get_installers(): array {
		$raw  = apply_filters( 'bizcity_register_installers', [] );
		$norm = [];
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['callback'] ) ) {
				continue;
			}
			if ( ! is_callable( $row['callback'] ) ) {
				continue;
			}
			$norm[] = [
				'id'           => (string) $row['id'],
				'label'        => (string) ( $row['label']       ?? $row['id'] ),
				'callback'     => $row['callback'],
				'version_opt'  => (string) ( $row['version_opt'] ?? '' ),
				'expected_ver' => (string) ( $row['expected_ver'] ?? '' ),
			];
		}
		return $norm;
	}

	/** Multisite — fired when a new blog is created. */
	public static function on_new_site( $new_site ): void {
		$blog_id = is_object( $new_site ) ? (int) $new_site->blog_id : (int) $new_site;
		if ( $blog_id <= 0 ) {
			return;
		}
		if ( ! function_exists( 'switch_to_blog' ) ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			self::run_all( true );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Self-heal: run installers once per blog every SELF_HEAL_TTL when admin
	 * loads /wp-admin/. Each installer is internally idempotent (db_version
	 * gate), so this is cheap on the steady state.
	 */
	public static function maybe_self_heal(): void {
		// Only on admin context, only for admins.
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Manual force via ?bizcity_provision=1 — bypass throttle.
		$force = ! empty( $_GET[ self::FORCE_QUERY_ARG ] );

		// NEVER run self-heal during AJAX / REST / cron — those hit admin_init
		// too. A single missed transient on a busy KG-building AJAX request
		// would otherwise stall the response for hundreds of dbDelta queries.
		if ( ! $force ) {
			if ( ( defined( 'DOING_AJAX' )  && DOING_AJAX )
			  || ( defined( 'DOING_CRON' ) && DOING_CRON )
			  || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
		}

		if ( ! $force && get_transient( self::SELF_HEAL_TRANS ) ) {
			return; // recently ran on this blog
		}

		// [2026-06-04 Johnny Chu] SCH-BC W5 — set transient TR\u01af\u1edaC run_all() \u0111\u1ec3
		// d\u1ed3n l\u1eafn ti\u1ebfng \u1ed5n duplicate khi admin_init fire nhi\u1ec1u l\u1ea7n trong c\u00f9ng
		// 1 request (race window c\u0169 = th\u1eddi gian run_all() \u2248 50-500ms gi\u00e1
		// b\u1eb1ng h\u00e0ng ch\u1ee5c duplicate SELECT t\u1eeb installers \u0111\u0103ng k\u00fd).
		set_transient( self::SELF_HEAL_TRANS, time(), self::SELF_HEAL_TTL );
		try {
			self::run_all( $force );
		} catch ( \Throwable $e ) {
			delete_transient( self::SELF_HEAL_TRANS );
			throw $e;
		}
	}

	/**
	 * Execute every registered installer in order.
	 *
	 * @param bool $force Force re-run (does not influence each callback's own version gate).
	 * @return array Per-installer result rows.
	 */
	public static function run_all( bool $force = false ): array {
		$installers = self::get_installers();
		$results    = [];

		foreach ( $installers as $i ) {
			$id  = $i['id'];
			$cb  = $i['callback'];
			$opt = $i['version_opt'];
			$exp = $i['expected_ver'];

			$ver_before = $opt ? (string) get_option( $opt, '' ) : '';

			$row = [
				'id'         => $id,
				'label'      => $i['label'],
				'ver_before' => $ver_before,
				'expected'   => $exp,
				'action'     => 'noop',
				'detail'     => '',
				'took_ms'    => 0,
			];

			// Version-gate: when expected_ver is declared and matches the stored
			// option, skip the callback entirely (dbDelta on already-current
			// schemas can still emit costly ALTER ... CHANGE COLUMN statements
			// due to syntax-comparison quirks).
			if ( ! $force && $opt !== '' && $exp !== '' && $ver_before === $exp ) {
				$row['action']    = 'skipped';
				$row['detail']    = 'version_opt already at expected_ver';
				$row['ver_after'] = $ver_before;
				$results[]        = $row;
				continue;
			}

			$t0 = microtime( true );
			try {
				call_user_func( $cb );
				$row['action'] = 'ran';
			} catch ( \Throwable $e ) {
				$row['action'] = 'error';
				$row['detail'] = $e->getMessage();
			}
			$row['took_ms']   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$row['ver_after'] = $opt ? (string) get_option( $opt, '' ) : '';

			$results[] = $row;
		}

		// [2026-06-11 Johnny Chu] HOTFIX — Only log when schema actually changed
		// (version option bumped) OR an error occurred. Pure noops ('ran' with
		// ver_before === ver_after) mean the callback returned early via its own
		// internal guard — no DB work was done, no point persisting an audit entry.
		// This prevents the 1456ms UPDATE wp_options call every 5 minutes on
		// steady-state installs where all schemas are already at expected version.
		$has_action = false;
		foreach ( $results as $r ) {
			if ( $r['action'] === 'error' ) {
				$has_action = true;
				break;
			}
			// 'ran' is meaningful only when the version option actually changed.
			if ( $r['action'] === 'ran'
				&& ( ( $r['ver_before'] ?? '' ) !== ( $r['ver_after'] ?? '' ) )
			) {
				$has_action = true;
				break;
			}
		}
		if ( $has_action || $force ) {
			self::write_log( [
				'ts'      => gmdate( 'Y-m-d H:i:s' ),
				'blog_id' => (int) get_current_blog_id(),
				'prefix'  => self::current_prefix(),
				'user'    => (int) get_current_user_id(),
				'force'   => $force,
				'results' => $results,
			] );
		}

		return $results;
	}

	/**
	 * Execute a single installer by its registered id. Used by the per-row
	 * "🔧 Fix" / "🔧 Repair" buttons in the Diagnostics admin page.
	 *
	 * @param string $id    Installer id (matches `BizCity_Site_Provisioner::get_installers()[]['id']`).
	 * @param bool   $force Logged as forced for audit-trail clarity.
	 * @return array|null   Result row (same shape as `run_all()` entries), or null when id not found.
	 */
	public static function run_one( string $id, bool $force = true ): ?array {
		$id = trim( $id );
		if ( $id === '' ) {
			return null;
		}
		$installers = self::get_installers();
		$found      = null;
		foreach ( $installers as $i ) {
			if ( ( $i['id'] ?? '' ) === $id ) {
				$found = $i;
				break;
			}
		}
		if ( ! $found ) {
			return null;
		}

		$cb         = $found['callback'];
		$opt        = $found['version_opt'];
		$exp        = $found['expected_ver'];
		$ver_before = $opt ? (string) get_option( $opt, '' ) : '';

		$row = [
			'id'         => $found['id'],
			'label'      => $found['label'],
			'ver_before' => $ver_before,
			'expected'   => $exp,
			'action'     => 'noop',
			'detail'     => '',
			'took_ms'    => 0,
		];

		// FORCE semantics: installers typically short-circuit when their
		// `version_opt` already matches `expected_ver`. On shards where the
		// option lingers but the physical tables were dropped (routing
		// changes / manual cleanup), the installer would noop and the user
		// sees `ran 0ms ver X → X` with the table still MISSING. When the
		// caller asks for a force re-run, clear the version gate first so
		// dbDelta actually executes. dbDelta is idempotent — re-running on
		// a healthy DB is a no-op at the SQL level.
		if ( $force && $opt ) {
			delete_option( $opt );
		}

		$t0 = microtime( true );
		global $wpdb;
		$prev_show = isset( $wpdb ) ? $wpdb->show_errors : null;
		if ( isset( $wpdb ) ) {
			$wpdb->show_errors( false );
			$wpdb->last_error = '';
		}
		try {
			call_user_func( $cb );
			$row['action'] = 'ran';
		} catch ( \Throwable $e ) {
			$row['action'] = 'error';
			$row['detail'] = $e->getMessage();
		}
		if ( isset( $wpdb ) ) {
			if ( ! empty( $wpdb->last_error ) && $row['action'] !== 'error' ) {
				$row['action'] = 'error';
				$row['detail'] = 'wpdb: ' . $wpdb->last_error;
			}
			if ( $prev_show !== null ) {
				$wpdb->show_errors( $prev_show );
			}
		}
		$row['took_ms']   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$row['ver_after'] = $opt ? (string) get_option( $opt, '' ) : '';

		// [2026-06-04 Johnny Chu] SCH-BC W4 — fire post-run hook so listeners
		// (e.g. backfill cache invalidator) can react to a fix-button click.
		do_action( 'bizcity_run_installer_done', $found['id'], $row );

		self::write_log( [
			'ts'      => gmdate( 'Y-m-d H:i:s' ),
			'blog_id' => (int) get_current_blog_id(),
			'prefix'  => self::current_prefix(),
			'user'    => (int) get_current_user_id(),
			'force'   => $force,
			'scope'   => 'one:' . $found['id'],
			'results' => [ $row ],
		] );

		return $row;
	}

	/** Audit log accessor. */
	public static function get_log(): array {
		$raw = get_option( self::LOG_OPTION, [] );
		return is_array( $raw ) ? $raw : [];
	}

	/** Append capped audit entry. */
	private static function write_log( array $entry ): void {
		$log   = self::get_log();
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_option( self::LOG_OPTION, $log, false );
	}

	private static function current_prefix(): string {
		global $wpdb;
		return ( $wpdb instanceof \wpdb ) ? (string) $wpdb->prefix : '';
	}
}
