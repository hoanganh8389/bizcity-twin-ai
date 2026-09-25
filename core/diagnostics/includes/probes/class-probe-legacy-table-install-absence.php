<?php
/**
 * Runtime DDV for PHASE-1.30-FAIL-CLOSED: retired legacy tables stay absent.
 *
 * `core.legacy_table.install_prevention` proves the central policy answers
 * "blocked". This probe proves the consequence on the current blog/shard:
 *
 *   Disk    — every owner installer that still carries a legacy DDL string is
 *             deployed without a fail-open guard (install when the policy class
 *             is missing).
 *   Loader  — the policy is loaded, every non-quarantine catalog table is
 *             install-blocked, and the WebChat local gate blocks retired
 *             projections.
 *   Runtime — one routed information_schema read classifies every
 *             non-quarantine table as absent, retained (created before the
 *             retirement cutoff) or recreated (created after it).
 *
 * The probe never calls an installer, never issues DDL/DML and never queries a
 * legacy table itself; TABLE_ROWS is the engine estimate, not COUNT(*).
 *
 * Operator baseline: set BIZCITY_LEGACY_INSTALL_BASELINE (constant or CLI env,
 * server-local "Y-m-d H:i:s") to the deploy time of the fail-closed fix. A
 * retired table created after that baseline is a FAIL; without a baseline a
 * post-retirement table is a WARN because it cannot be attributed.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-18
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BizCity_Legacy_Install_Absence_Rules', false ) ) {
	/**
	 * Pure rules shared by the probe and its PHPUnit test (no WordPress, no DB).
	 * Declared before the probe-interface guard so unit tests can load it alone.
	 */
	final class BizCity_Legacy_Install_Absence_Rules {

		/** Earliest central retirement date recorded in BizCity_Legacy_Table_Policy. */
		const RETIRED_SINCE = '2026-08-26 00:00:00';

		const ABSENT             = 'absent';
		const RETAINED           = 'retained';
		const RECREATED_UNKNOWN  = 'recreated_after_retirement';
		const RECREATED_AFTER_FIX = 'recreated_after_fix';

		/** Remove PHP comments so a stamped example in a comment never counts as code. */
		public static function strip_comments( $code ) {
			// [2026-09-18 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-FAIL-CLOSED — extract pure probe rules for unit evidence.
			$code = preg_replace( '#/\*.*?\*/#s', '', (string) $code );
			return (string) preg_replace( '#(^|\s)//[^\n]*#', '$1', (string) $code );
		}

		/** True when code installs a legacy table even though the policy class is missing. */
		public static function has_fail_open_guard( $code ) {
			$policy = 'class_exists\(\s*[\'"]BizCity_Legacy_Table_Policy[\'"]\s*\)';
			$patterns = array(
				// if ( ! class_exists( Policy ) || ! Policy::install_blocked( $t ) ) { dbDelta(...) }
				'/!\s*' . $policy . '\s*\|\|\s*!\s*BizCity_Legacy_Table_Policy::install_blocked/',
				// if ( class_exists( Policy ) && Policy::install_blocked( $t ) ) { return; } — installs when the class is missing.
				'/(?<![!\s])\s*' . $policy . '\s*&&\s*BizCity_Legacy_Table_Policy::install_blocked\s*\(/',
			);
			$code = self::strip_comments( $code );
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $code ) ) {
					return true;
				}
			}
			return false;
		}

		/** Normalize an operator baseline to "Y-m-d H:i:s", or '' when unusable. */
		public static function normalize_baseline( $value ) {
			$value = trim( (string) $value );
			if ( $value === '' ) {
				return '';
			}
			$ts = strtotime( $value . ' UTC' );
			if ( false === $ts ) {
				$ts = strtotime( $value );
			}
			return false === $ts ? '' : gmdate( 'Y-m-d H:i:s', (int) $ts );
		}

		/**
		 * Classify one retired table from routed information_schema metadata.
		 *
		 * @param bool   $present     Table physically exists on this shard.
		 * @param string $create_time CREATE_TIME ("Y-m-d H:i:s") or '' when unknown.
		 * @param string $baseline    Normalized deploy baseline or ''.
		 * @return string One of the class constants.
		 */
		public static function classify( $present, $create_time, $baseline ) {
			if ( ! $present ) {
				return self::ABSENT;
			}
			$create_time = trim( (string) $create_time );
			if ( $create_time !== '' && strcmp( $create_time, self::RETIRED_SINCE ) < 0 ) {
				return self::RETAINED;
			}
			if ( $baseline !== '' && $create_time !== '' && strcmp( $create_time, (string) $baseline ) >= 0 ) {
				return self::RECREATED_AFTER_FIX;
			}
			return self::RECREATED_UNKNOWN;
		}

		/** Map a classification to a probe step status. */
		public static function step_status( $classification ) {
			switch ( $classification ) {
				case self::RECREATED_AFTER_FIX:
					return 'fail';
				case self::RECREATED_UNKNOWN:
					return 'warn';
				case self::RETAINED:
					return 'info';
				default:
					return 'pass';
			}
		}
	}
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Table_Install_Absence', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Install_Absence implements BizCity_Diagnostics_Probe {

	/** Owner files that still build a legacy DDL string and must stay fail-closed. */
	private static $owner_files = array(
		'core/twin-core/includes/class-twin-state-schema.php',
		'core/knowledge/includes/class-database.php',
		'core/knowledge/includes/class-user-memory.php',
		// [2026-09-24 Claude Opus 5] CORE-REDUCTION-WP-01 K-07 —
		// `core/knowledge/includes/class-skill-database.php` was retired under R-ORPHAN-FILE:
		// a duplicate BizCity_Skill_Database that no bootstrap required, and the only deployed
		// installer for the retired table `bizcity_skill_logs`. The canonical owner
		// (core/skills/includes/class-skill-database.php) is deliberately NOT listed: it builds
		// DDL for `bizcity_skills` only, which is an active table, so it has no legacy installer
		// to keep fail-closed.
		'core/kg-hub/includes/class-kg-cleanup-service.php',
		'core/intent/includes/conversation/class-rolling-memory.php',
		'core/intent/includes/conversation/class-episodic-memory.php',
		'core/intent/includes/orchestration/class-intent-engine.php',
		'core/intent/includes/infrastructure/class-intent-logger.php',
		'modules/webchat/includes/class-webchat-database.php',
	);

	public function id(): string {
		return 'core.legacy_table.install_absence';
	}

	public function label(): string {
		return 'Legacy tables - fail-closed installers and physical absence';
	}

	public function description(): string {
		return 'Verifies retired legacy tables are not recreated: no fail-open install guard is deployed and no retired table was created after retirement on this shard.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 19;
	}

	public function icon(): string {
		return 'database-off';
	}

	public function estimate_ms(): int {
		return 300;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
			return new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-18 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-FAIL-CLOSED — prove retired tables stay absent across Disk/Loader/Runtime without invoking installers or legacy SQL.
		$steps  = array();
		$fails  = array();
		$warns  = array();
		$emit   = function ( array $step ) use ( &$steps, $ctx ) {
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		// ── Disk ───────────────────────────────────────────────────────
		$root = dirname( __DIR__, 4 ); // probes → includes → diagnostics → core → plugin root.
		$deployed = 0;
		foreach ( self::$owner_files as $relative ) {
			$path = $root . '/' . $relative;
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$emit( array( 'label' => 'Disk: ' . $relative, 'status' => 'info', 'detail' => 'Owner file not deployed on this surface; it cannot install a legacy table.' ) );
				continue;
			}
			$deployed++;
			// [2026-09-18 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-FAIL-CLOSED — share the unit-tested fail-open detector.
			$open = BizCity_Legacy_Install_Absence_Rules::has_fail_open_guard( (string) file_get_contents( $path ) );
			if ( $open ) {
				$fails[] = 'fail_open_guard:' . $relative;
			}
			$emit( array(
				'label'  => 'Disk: ' . $relative,
				'status' => $open ? 'fail' : 'pass',
				'detail' => $open ? 'Fail-open legacy install guard: the table is created when the policy class is missing.' : 'Legacy install guard is fail-closed.',
			) );
		}

		// ── Loader ─────────────────────────────────────────────────────
		$rows = array();
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || ! empty( $row['quarantine_only'] ) ) {
				continue;
			}
			$rows[] = $row;
		}
		$unblocked = array();
		foreach ( $rows as $row ) {
			if ( ! BizCity_Legacy_Table_Policy::install_blocked( (string) $row['name'] ) ) {
				$unblocked[] = (string) $row['name'];
			}
		}
		if ( $unblocked ) {
			$fails[] = 'policy_not_blocked:' . implode( ',', $unblocked );
		}
		$emit( array(
			'label'  => 'Loader: central policy blocks ' . count( $rows ) . ' non-quarantine tables',
			'status' => $unblocked ? 'fail' : 'pass',
			'detail' => $unblocked ? 'Not install-blocked: ' . implode( ', ', $unblocked ) : 'install_blocked=true for every non-quarantine catalog row.',
		) );
		if ( class_exists( 'BizCity_WebChat_Database' ) && method_exists( 'BizCity_WebChat_Database', 'table_write_blocked' ) ) {
			$wc_blocked = (bool) BizCity_WebChat_Database::table_write_blocked( 'bizcity_webchat_projects' );
			if ( ! $wc_blocked ) {
				$fails[] = 'webchat_projects_gate_open';
			}
			$emit( array(
				'label'  => 'Loader: WebChat retired projection gate',
				'status' => $wc_blocked ? 'pass' : 'fail',
				'detail' => $wc_blocked ? 'bizcity_webchat_projects write/install is blocked.' : 'bizcity_webchat_projects is still installable.',
			) );
		} else {
			$emit( array( 'label' => 'Loader: WebChat retired projection gate', 'status' => 'info', 'detail' => 'WebChat database owner is not loaded in this context.' ) );
		}

		// ── Runtime ────────────────────────────────────────────────────
		$baseline = self::baseline();
		$emit( array(
			'label'  => 'Runtime: attribution baseline',
			'status' => 'info',
			'detail' => $baseline !== '' ? 'BIZCITY_LEGACY_INSTALL_BASELINE=' . $baseline : 'No baseline set; post-retirement tables are WARN, not FAIL.',
		) );
		$physical = array();
		$deferred = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( is_multisite() && ( (string) ( $row['prefix_scope'] ?? 'blog' ) === 'base' || ! empty( $row['raw'] ) ) ) {
				$deferred[] = $name;
				continue;
			}
			$physical[ BizCity_Legacy_Table_Policy::physical_name( $name ) ] = $name;
		}
		$found = self::read_metadata( array_keys( $physical ) );
		if ( null === $found ) {
			$fails[] = 'metadata_query_failed';
			$emit( array( 'label' => 'Runtime: information_schema read', 'status' => 'fail', 'detail' => 'Routed information_schema read failed; no physical evidence collected.' ) );
		}
		$counts = array( 'absent' => 0, 'retained' => 0, 'recreated' => 0, 'deferred' => count( $deferred ) );
		foreach ( $physical as $table => $suffix ) {
			if ( null === $found ) {
				break;
			}
			$present  = isset( $found[ $table ] );
			$created  = $present ? (string) $found[ $table ]['create_time'] : '';
			// [2026-09-18 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-FAIL-CLOSED — classification lives in the unit-tested rules class.
			$class = BizCity_Legacy_Install_Absence_Rules::classify( $present, $created, $baseline );
			if ( BizCity_Legacy_Install_Absence_Rules::ABSENT === $class ) {
				$counts['absent']++;
				continue;
			}
			$detail = 'created=' . ( $created !== '' ? $created : 'unknown' ) . '; table_rows≈' . (int) $found[ $table ]['table_rows'];
			$status = BizCity_Legacy_Install_Absence_Rules::step_status( $class );
			if ( BizCity_Legacy_Install_Absence_Rules::RETAINED === $class ) {
				$counts['retained']++;
				$emit( array( 'label' => 'Runtime: ' . $suffix, 'status' => $status, 'detail' => 'Retained pre-retirement table (G5 cleanup candidate); ' . $detail ) );
				continue;
			}
			$counts['recreated']++;
			if ( 'fail' === $status ) {
				$fails[] = $class . ':' . $suffix;
				$emit( array( 'label' => 'Runtime: ' . $suffix, 'status' => $status, 'detail' => 'Created after the fail-closed baseline — an installer still recreates it; ' . $detail ) );
			} else {
				$warns[] = $class . ':' . $suffix;
				$emit( array( 'label' => 'Runtime: ' . $suffix, 'status' => $status, 'detail' => 'Created after retirement (pre-fix fail-open guard, table rebuild or unknown CREATE_TIME); ' . $detail ) );
			}
		}
		foreach ( $deferred as $suffix ) {
			$emit( array( 'label' => 'Runtime: ' . $suffix, 'status' => 'info', 'detail' => 'Base-prefix/global table on multisite — verify from the network owner, not a tenant shard.' ) );
		}

		$status = $fails ? 'fail' : ( $warns ? 'warn' : 'pass' );
		$summary = sprintf(
			'%d non-quarantine tables: %d absent, %d retained pre-retirement, %d created after retirement, %d deferred (global); %d owner files fail-closed.',
			count( $rows ),
			$counts['absent'],
			$counts['retained'],
			$counts['recreated'],
			$counts['deferred'],
			$deployed - count( array_filter( $fails, function ( $f ) { return strpos( $f, 'fail_open_guard:' ) === 0; } ) )
		);
		$result = array(
			'status'   => $status,
			'summary'  => $summary,
			'error'    => implode( ', ', array_merge( $fails, $warns ) ),
			'steps'    => $steps,
			'counts'   => $counts,
			'baseline' => $baseline,
		);
		if ( 'pass' !== $status ) {
			$result['fix_hint'] = $fails
				? 'Replace fail-open guards with "! class_exists(Policy) || install_blocked(...) => skip", deploy, then rerun: php bin/diagnostics-run.php --filter=core.legacy_table.install_absence --skip-provision --skip-network --format=json'
				: 'Confirm TABLE_ROWS is 0 and no owner writes it, set BIZCITY_LEGACY_INSTALL_BASELINE to the fix deploy time, rerun this probe; remove confirmed-empty tables only through the G5 approved-drop path.';
		}
		return $result;
	}

	public function cleanup(): void {}

	/** Operator-supplied deploy baseline for attribution, or an empty string. */
	private static function baseline() {
		$value = defined( 'BIZCITY_LEGACY_INSTALL_BASELINE' ) ? (string) BIZCITY_LEGACY_INSTALL_BASELINE : (string) getenv( 'BIZCITY_LEGACY_INSTALL_BASELINE' );
		return BizCity_Legacy_Install_Absence_Rules::normalize_baseline( $value );
	}

	/**
	 * One routed information_schema read for the current tenant database.
	 *
	 * Mirrors BizCity_Table_Metadata::route_hint(): the unquoted `wp_{blog_id}_*`
	 * comment makes wp-content/db.php route the query to the owning shard.
	 *
	 * @param array<int,string> $tables Physical table names.
	 * @return array<string,array{create_time:string,table_rows:int}>|null
	 */
	private static function read_metadata( array $tables ) {
		if ( empty( $tables ) ) {
			return array();
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		$hint = preg_match( '/^wp_\d+_[a-z0-9_]+$/i', (string) $tables[0] ) ? ' /* route:' . $tables[0] . ' */' : '';
		$placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT TABLE_NAME AS t, CREATE_TIME AS c, TABLE_ROWS AS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})" . $hint,
			$tables
		), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
			return null;
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['t'] ] = array(
				'create_time' => (string) ( $row['c'] ?? '' ),
				'table_rows'  => (int) ( $row['r'] ?? 0 ),
			);
		}
		return $out;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Install_Absence';
	return $list;
} );
