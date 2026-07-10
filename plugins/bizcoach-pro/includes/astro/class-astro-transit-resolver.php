<?php
/**
 * BizCoach Pro — Astro Transit Resolver (Sprint C.0b · 2026-06-04).
 *
 * Single canonical resolver for "what transit data should we feed the LLM
 * RIGHT NOW for this coachee?" question. Implements the DB-first contract
 * specified in CORE-PHASE-A-ATOMIC-TOOLS.md §7.3:
 *
 *     resolve()
 *       ├─ 1. lookup `bccm_transit_snapshots` for the requested period
 *       │     ├─ HIT  → source='db'                  (no API call)
 *       │     └─ MISS → step 2
 *       ├─ 2. apply_filters('bcpro_astro_transit_pre_fetch', ...)
 *       │     for plug-in providers (Prokerala, etc.)
 *       │     └─ HIT  → source='filter'              (persist via legacy)
 *       ├─ 3. schedule wp-cron `bccm_transit_prefetch_cron` + return
 *       │     graceful "natal-only" markdown for the LLM
 *       │     → source='prefetch_scheduled', _degraded='transit_pending'
 *       └─ 4. emit event `bizcoach_astro_transit_resolved`.
 *
 * Why a thin wrapper around `bccm_transit_build_context()`?
 *   The legacy engine already implements the DB-first lookup with the
 *   nearest-date tolerance window and the auto-prefetch fallback. Re-
 *   implementing the same algorithm here would duplicate logic + risk
 *   drift. This class just exposes a structured, fail-OPEN API surface
 *   that CAP providers (R-PP, §0.3) can consume safely.
 *
 * R-GW-8 / CAP-1: when (eventually) we need a synchronous API call to
 * FreeAstroAPI, route through `BizCoach_Pro_Astro_Client` (Bearer to
 * `bizcity.vn` gateway). For now, the cron-driven prefetch path
 * (`bccm_transit_prefetch_cron` in legacy/lib/astro-transit.php) keeps
 * the live API call OFF the user-blocking turn — exactly what the
 * "DB-first" rule wants.
 *
 * @since  0.36.x  (2026-06-04 Johnny Chu — PHASE-A C.0b)
 * @see    plugins/bizcoach-pro/legacy/lib/astro-transit.php
 * @see    core/docs/CORE-PHASE-A-ATOMIC-TOOLS.md §7.3
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Transit_Resolver' ) ) { return; }

class BizCoach_Pro_Astro_Transit_Resolver {

	/** Allowed period inputs (subset of legacy `bccm_transit_*` periods). */
	const PERIODS = array( 'day', 'week', 'month', 'year', '5year', 'custom_year' );

	/** @var BizCoach_Pro_Astro_Transit_Resolver|null */
	private static $instance = null;

	public static function instance() {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	/**
	 * Resolve transit data for a coachee within a period.
	 *
	 * @param int    $coachee_id
	 * @param string $period      One of self::PERIODS. Free-form free-text
	 *                            ('hôm nay', 'tuần này') is accepted via
	 *                            $opts['detect_from_message'] — see below.
	 * @param array  $opts {
	 *     @type int    $user_id              WP user fallback when coachee_id=0.
	 *     @type string $detect_from_message  If set, run `bccm_transit_detect_intent()`
	 *                                        on this string and override $period.
	 *     @type bool   $allow_stale          Accept rows past TTL window. Default true
	 *                                        (legacy tolerance window already handles
	 *                                        this — kept for forward compat).
	 *     @type string $trace_id             Event-bus correlation id.
	 * }
	 * @return array {
	 *     @type int      $coachee_id
	 *     @type int      $user_id
	 *     @type string   $period
	 *     @type array    $time_range   Legacy shape ['period','days','label'].
	 *     @type string   $markdown     LLM-ready context block (may be empty
	 *                                  when natal data is missing).
	 *     @type string   $source       'db' | 'prefetch_scheduled' |
	 *                                  'natal_only' | 'unavailable'.
	 *     @type int      $rows_count   Snapshots actually used in the build.
	 *     @type string   $fetched_at   Newest fetched_at timestamp from DB
	 *                                  ('' when source != 'db').
	 *     @type string|null $_degraded Reason code or null on full success.
	 * }
	 */
	public function resolve( $coachee_id, $period = 'day', $opts = array() ) {
		// [2026-06-04 Johnny Chu] PHASE-A C.0b — DB-first resolver entrypoint.
		$coachee_id = (int) $coachee_id;
		$user_id    = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : 0;
		$trace_id   = isset( $opts['trace_id'] ) ? (string) $opts['trace_id'] : '';

		$out = array(
			'coachee_id' => $coachee_id,
			'user_id'    => $user_id,
			'trace_id'   => $trace_id,
			'period'     => '',
			'time_range' => array(),
			'markdown'   => '',
			'source'     => 'unavailable',
			'rows_count' => 0,
			'fetched_at' => '',
			'_degraded'  => null,
		);

		if ( $coachee_id <= 0 && $user_id <= 0 ) {
			$out['_degraded'] = 'transit_no_subject';
			return $out;
		}

		// 1. Ensure legacy engine loaded (idempotent require).
		if ( ! $this->ensure_legacy_loaded() ) {
			$out['_degraded'] = 'transit_engine_missing';
			return $out;
		}

		// 2. Resolve time_range — either via free-text intent detect, or
		//    the explicit $period argument.
		$time_range = null;
		if ( ! empty( $opts['detect_from_message'] ) && function_exists( 'bccm_transit_detect_intent' ) ) {
			$detected = bccm_transit_detect_intent( (string) $opts['detect_from_message'] );
			if ( is_array( $detected ) ) {
				$time_range = $detected;
			}
		}
		if ( ! $time_range ) {
			$time_range = $this->time_range_from_period( (string) $period );
		}
		$out['period']     = (string) ( $time_range['period'] ?? 'month' );
		$out['time_range'] = $time_range;

		// 3. DB row count — used to discriminate 'db' vs 'prefetch_scheduled'.
		$rows_count = $this->count_snapshots_in_window( $coachee_id, $user_id );
		$out['rows_count'] = $rows_count;

		// 4. Pre-fetch filter — third-party providers (Prokerala, etc.)
		//    can supply a pre-computed markdown context here.
		/**
		 * Filter: bcpro_astro_transit_pre_fetch
		 *
		 * @param array|null $payload      Pre-computed { markdown, fetched_at, source }
		 *                                 or null to fall through to legacy.
		 * @param int        $coachee_id
		 * @param array      $time_range
		 * @param array      $opts
		 */
		$pre = apply_filters( 'bcpro_astro_transit_pre_fetch', null, $coachee_id, $time_range, $opts );
		if ( is_array( $pre ) && ! empty( $pre['markdown'] ) ) {
			$out['markdown']   = (string) $pre['markdown'];
			$out['source']     = (string) ( $pre['source'] ?? 'filter' );
			$out['fetched_at'] = (string) ( $pre['fetched_at'] ?? current_time( 'mysql' ) );
			$this->emit_resolved( $out, $trace_id );
			return $out;
		}

		// 5. Delegate to legacy engine — it returns markdown context (DB
		//    snapshots formatted for the LLM) OR the graceful "natal-only"
		//    guidance block when the cache misses (which also schedules
		//    `bccm_transit_prefetch_cron`).
		if ( ! function_exists( 'bccm_transit_build_context' ) ) {
			$out['_degraded'] = 'transit_build_context_missing';
			$this->emit_resolved( $out, $trace_id );
			return $out;
		}

		$markdown = (string) bccm_transit_build_context( $coachee_id, $user_id, $time_range );
		$out['markdown'] = $markdown;

		if ( $markdown === '' ) {
			// Natal data missing — engine returns '' when traits/positions absent.
			$out['source']    = 'natal_only';
			$out['_degraded'] = 'astro_birth_data_missing';
		} elseif ( $rows_count > 0 ) {
			$out['source']     = 'db';
			$out['fetched_at'] = $this->newest_fetched_at( $coachee_id, $user_id );
		} else {
			// Engine returned guidance block + scheduled cron prefetch.
			$out['source']    = 'prefetch_scheduled';
			$out['_degraded'] = 'transit_pending';
		}

		$this->emit_resolved( $out, $trace_id );
		return $out;
	}

	/**
	 * Render the resolver result as one or more CAP passages
	 * (R-PP-4 / CAP-4 shape).
	 *
	 * @param array $resolved Output of resolve().
	 * @return array<int,array{title:string,body:string,metadata:array}>
	 */
	public function to_passages( array $resolved ) {
		if ( empty( $resolved['markdown'] ) ) { return array(); }
		$tr    = isset( $resolved['time_range'] ) && is_array( $resolved['time_range'] ) ? $resolved['time_range'] : array();
		$label = isset( $tr['label'] ) ? (string) $tr['label'] : (string) $resolved['period'];
		$trace = isset( $resolved['trace_id'] ) ? (string) $resolved['trace_id'] : '';

		// [2026-06-04 Johnny Chu] PHASE-A C.3c — surface provenance link so CAP
		// passages carry a verifiable public source (`/my-transit/`). Was missing
		// → LLM + FE timeline had no source to cite. Fail-OPEN: empty string when
		// the public router helper is absent (e.g. router file not deployed yet).
		$coachee_id = (int) ( isset( $resolved['coachee_id'] ) ? $resolved['coachee_id'] : 0 );
		$period     = (string) ( isset( $resolved['period'] ) ? $resolved['period'] : 'day' );
		$source_url = '';
		if ( $coachee_id > 0 && function_exists( 'bcpro_get_transit_public_url' ) ) {
			$source_url = (string) bcpro_get_transit_public_url( $coachee_id, $period );
		}

		// [2026-06-10 Johnny Chu] ASTRO-CITE 3 — embed [astro:*#URL] tokens so the
		// LLM can cite natal chart and transit page in its answer.
		$cite_header = '';
		$natal_url   = '';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — prefer public natal page URL, never raw chart image URL.
		if ( $coachee_id > 0 && function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$natal_url = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}
		if ( $natal_url === '' && $coachee_id > 0 && function_exists( 'bcpro_get_astro_artifact_links' ) ) {
			$alinks = (array) bcpro_get_astro_artifact_links( $coachee_id, '', $period );
			// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — prefer detailed western page over wheel-only URL.
			if ( ! empty( $alinks['western_vi'] ) ) {
				$natal_url = (string) $alinks['western_vi'];
			} elseif ( ! empty( $alinks['wheel'] ) ) {
				$natal_url = (string) $alinks['wheel'];
			}
		}
		if ( $natal_url === '' && $coachee_id > 0 && function_exists( 'bcpro_get_astro_public_url' ) ) {
			$natal_url = (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
		}
		if ( $natal_url !== '' ) {
			$cite_header .= "[astro:natal#{$natal_url}] Bản đồ sao natal chart\n";
		}
		if ( $source_url !== '' ) {
			$cite_header .= "[astro:transit#{$source_url}] Lịch quá cảnh {$label}\n";
		}
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 unified artifact links in CAP metadata.
		$artifact_links = function_exists( 'bcpro_get_astro_artifact_links' )
			? (array) bcpro_get_astro_artifact_links( $coachee_id, '', $period )
			: array();
		$body = $cite_header !== '' ? $cite_header . "\n" . (string) $resolved['markdown'] : (string) $resolved['markdown'];

		return array(
			array(
				'title'    => 'Transit — ' . $label,
				'body'     => $body,
				'metadata' => array(
					'source'     => isset( $resolved['source'] ) ? $resolved['source'] : '',
					'source_url' => $source_url,
					// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — preserve source provenance in CAP metadata.
					'source_provenance' => array(
						'provider'   => 'bizcoach_transit_resolver',
						'resolver'   => 'db_first_resolve',
						'source'     => isset( $resolved['source'] ) ? $resolved['source'] : '',
						'trace_id'   => $trace,
						'fetched_at' => isset( $resolved['fetched_at'] ) ? $resolved['fetched_at'] : '',
					),
					'fetched_at' => isset( $resolved['fetched_at'] ) ? $resolved['fetched_at'] : '',
					'period'     => $period,
					'_degraded'  => isset( $resolved['_degraded'] ) ? $resolved['_degraded'] : null,
					'kind'       => 'astro_transit_report',
					'artifact_links' => $artifact_links,
				),
			),
		);
	}

	/* ──────────────────────────────────────────────────────────────
	 * Helpers
	 * ────────────────────────────────────────────────────────────── */

	/** Idempotently require the legacy transit lib + dependencies. */
	private function ensure_legacy_loaded() {
		if ( function_exists( 'bccm_transit_build_context' ) ) { return true; }
		if ( ! defined( 'BCPRO_DIR' ) ) { return false; }
		$candidates = array(
			BCPRO_DIR . 'legacy/lib/astro-transit.php',
			BCPRO_DIR . 'legacy/lib/astro-helpers.php',
		);
		foreach ( $candidates as $f ) {
			if ( file_exists( $f ) ) { require_once $f; }
		}
		return function_exists( 'bccm_transit_build_context' );
	}

	/** Map {period} → legacy time_range array. */
	private function time_range_from_period( $period ) {
		$p = strtolower( (string) $period );
		switch ( $p ) {
			case 'day':
				return array( 'period' => 'day', 'days' => 0, 'label' => 'Hôm nay' );
			case 'tomorrow':
				return array( 'period' => 'day', 'days' => 1, 'label' => 'Ngày mai' );
			case 'week':
				return array( 'period' => 'week', 'days' => 7, 'label' => '7 ngày tới' );
			case 'year':
				return array( 'period' => 'year', 'days' => 365, 'label' => '1 năm tới' );
			case '5year':
				return array( 'period' => '5year', 'days' => 1825, 'label' => '5 năm tới' );
			case 'month':
			default:
				return array( 'period' => 'month', 'days' => 30, 'label' => '1 tháng tới' );
		}
	}

	/** Count usable snapshots within today-7d → today+400d window. */
	private function count_snapshots_in_window( $coachee_id, $user_id ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G6 use SELECT 1 + dual cache (R-SHOW-TABLES).
		if ( ! $this->table_exists_cached( $tbl ) ) { return 0; }
		if ( $user_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl}
				 WHERE ( coachee_id = %d OR user_id = %d )
				   AND target_date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )
				   AND target_date <= DATE_ADD( CURDATE(), INTERVAL 400 DAY )",
				$coachee_id,
				$user_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl}
				 WHERE coachee_id = %d
				   AND target_date >= DATE_SUB( CURDATE(), INTERVAL 7 DAY )
				   AND target_date <= DATE_ADD( CURDATE(), INTERVAL 400 DAY )",
				$coachee_id
			);
		}
		return (int) $wpdb->get_var( $sql );
	}

	/** Newest fetched_at across the active snapshot window. */
	private function newest_fetched_at( $coachee_id, $user_id ) {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G6 use SELECT 1 + dual cache (R-SHOW-TABLES).
		if ( ! $this->table_exists_cached( $tbl ) ) { return ''; }
		if ( $user_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT MAX(fetched_at) FROM {$tbl} WHERE coachee_id = %d OR user_id = %d",
				$coachee_id,
				$user_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT MAX(fetched_at) FROM {$tbl} WHERE coachee_id = %d",
				$coachee_id
			);
		}
		return (string) $wpdb->get_var( $sql );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G6 table exists check with dual cache.
	 * Rule R-SHOW-TABLES: avoid SHOW TABLES LIKE scans on multisite.
	 */
	private function table_exists_cached( $table_name ) {
		$table_name = (string) $table_name;
		if ( $table_name === '' ) { return false; }

		static $static_cache = array();
		if ( isset( $static_cache[ $table_name ] ) ) {
			return (bool) $static_cache[ $table_name ];
		}

		$cache_key = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table_name );
		$present   = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$table_name
				)
			);
			wp_cache_set( $cache_key, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}

		$static_cache[ $table_name ] = (bool) $present;
		return $static_cache[ $table_name ];
	}

	/** Emit observability event (Twin_Event_Bus when available, else action). */
	private function emit_resolved( array $row, $trace_id ) {
		$payload = array(
			'trace_id'   => (string) $trace_id,
			'coachee_id' => (int) $row['coachee_id'],
			'period'     => (string) $row['period'],
			'source'     => (string) $row['source'],
			'rows'       => (int) $row['rows_count'],
			'_degraded'  => $row['_degraded'],
		);
		do_action( 'bizcoach_astro_transit_resolved', $payload );
	}
}
