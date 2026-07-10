<?php
/**
 * BizCoach Pro — Transit Public Router
 *
 * Hash-protected public URL for transit timeline / snapshot pages:
 *
 *   /my-transit/?id=&hash=&period=day|week|month|year|custom[&start=YYYY-MM-DD&end=YYYY-MM-DD]
 *   /my-transit/?id=&hash=&day=YYYY/MM/DD  (alias sugar for exact day)
 *
 * Auth model mirrors Astro_Public_Router (HMAC-SHA256, chart_type='transit').
 * Period & date params are NOT in the signed payload — they only select which
 * slice to render, not which coachee. This lets a single share link cover all
 * ranges (day/week/month/year/custom).
 *
 * Rendering is delegated to bccm_transit_report_handler() in
 * legacy/lib/astro-transit-report.php, which is patched (alongside
 * astro-report-llm.php) to accept the public context via
 * bcpro_astro_public_ctx_matches() instead of requiring nonce + admin cap.
 *
 * @since 0.36.x
 */

defined( 'ABSPATH' ) || exit;

// Global helper — defined FIRST so it survives the class guard
// (mirrors the pattern in class-astro-public-router.php).
if ( ! function_exists( 'bcpro_get_transit_public_url' ) ) {
	/**
	 * @param int    $coachee_id
	 * @param string $period   day|week|month|year|custom
	 * @param array  $extra    ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD', 'regenerate' => 1]
	 */
	function bcpro_get_transit_public_url( $coachee_id, $period = 'week', $extra = array() ) {
		if ( ! class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) { return ''; }
		return BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, $period, $extra );
	}
}

if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) { return; }

class BizCoach_Pro_Transit_Public_Router {

	const SLUG       = 'my-transit';
	const QUERY_VAR  = 'bcpro_transit_view';
	const CHART_TYPE = 'transit';

	/** Allowed period values (also drives default range_start/range_end). */
	const PERIODS = array( 'day', 'week', 'month', 'year', 'custom' );

	/* [2026-06-04 Johnny Chu] PHASE-A C.3b — Part B defaults (all filterable). */
	const RL_VIEW_LIMIT    = 30;   // max view hits per IP per window
	const RL_VIEW_WINDOW   = 60;   // seconds
	const RL_REGEN_LIMIT   = 6;    // max regenerate per coachee+IP per window
	const RL_REGEN_WINDOW  = 3600; // seconds
	const CACHE_TTL        = 1800; // machine (md/json) output cache TTL seconds

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — cache invalidation on snapshot save.
		add_action( 'bccm_transit_snapshot_saved', array( __CLASS__, 'invalidate_for_coachee' ), 10, 2 );
	}

	public static function register_rewrites() {
		add_rewrite_rule(
			'^' . preg_quote( self::SLUG, '/' ) . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'transit_period';
		$vars[] = 'transit_start';
		$vars[] = 'transit_end';
		return (array) $vars;
	}

	public static function maybe_handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) { return; }

		$coachee_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$hash       = isset( $_GET['hash'] ) ? sanitize_text_field( wp_unslash( $_GET['hash'] ) ) : '';
		$period     = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'week';
		$start      = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
		$end        = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : '';
		$regenerate = ! empty( $_GET['regenerate'] ) ? 1 : 0;
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — new params: date (specific day)
		// + format (md|json) for agent-fetchable machine output.
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — accept `day=` alias from Zalo messages
		// where users commonly copy URLs like day=2026/07/06.
		$day        = isset( $_GET['day'] ) ? sanitize_text_field( wp_unslash( $_GET['day'] ) ) : '';
		$date       = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$format     = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'html';
		if ( ! in_array( $format, array( 'html', 'md', 'markdown', 'json' ), true ) ) { $format = 'html'; }
		if ( $format === 'markdown' ) { $format = 'md'; }

		if ( $coachee_id <= 0 || $hash === '' ) {
			status_header( 400 );
			wp_die( esc_html__( 'Thiếu tham số id hoặc hash.', 'bizcoach-pro' ) );
		}

		// `day=YYYY/MM/DD` and `date=YYYY-MM-DD` sugar → render exact day on day view.
		if ( $day !== '' && $date === '' ) {
			$date = $day;
		}
		$date = self::normalize_date_param( $date );
		if ( $date !== '' ) {
			// [2026-07-06 Johnny Chu] HOTFIX — keep date links on period=day.
			// Routing to custom here forces timeline flow and may skip day snapshot cards.
			$period = 'day';
			$start  = $date;
			$end    = $date;
		}

		if ( ! in_array( $period, self::PERIODS, true ) ) { $period = 'week'; }

		if ( ! self::verify_hash( $coachee_id, $hash ) ) {
			status_header( 403 );
			wp_die( esc_html__( 'Đường dẫn không hợp lệ hoặc đã hết hạn.', 'bizcoach-pro' ) );
		}

		// ── Anti-DDoS / quota guard (Part B.2) ──────────────────────────
		$rl = self::enforce_rate_limit( $coachee_id, $regenerate );
		if ( ! empty( $rl['blocked'] ) ) {
			self::respond_rate_limited( $rl, $format );
			return; // unreachable (respond exits) — defensive.
		}

		// Regenerate busts cache for this coachee (Part B.3).
		if ( $regenerate ) { self::bump_cache_version( $coachee_id ); }

		// ── Machine output for agent fetch (Part B.1) ───────────────────
		if ( $format === 'md' || $format === 'json' ) {
			self::serve_machine( $coachee_id, $period, $date, $format, $regenerate );
			return; // unreachable (serve_machine exits).
		}

		// ── HTML render (legacy handler) ────────────────────────────────
		// Expose context to legacy handler (chart_type='transit' so
		// bcpro_astro_public_ctx_matches($coachee, 'transit') returns true).
		$GLOBALS['bcpro_public_astro_ctx'] = array(
			'coachee_id' => $coachee_id,
			'chart_type' => self::CHART_TYPE,
			'hash'       => $hash,
		);

		self::ensure_renderer_loaded();

		// Synthesize GET payload for the legacy AJAX handler.
		$_GET['coachee_id'] = $coachee_id;
		$_GET['period']     = $period;
		if ( $start !== '' ) { $_GET['start'] = $start; }
		if ( $end   !== '' ) { $_GET['end']   = $end;   }
		if ( $regenerate )   { $_GET['regenerate'] = 1; }

		if ( ! function_exists( 'bccm_transit_report_handler' ) ) {
			status_header( 500 );
			wp_die( esc_html__( 'Transit renderer chưa được nạp.', 'bizcoach-pro' ) );
		}

		bccm_transit_report_handler();
		exit;
	}

	private static function ensure_renderer_loaded() {
		if ( function_exists( 'bccm_transit_report_handler' ) ) { return; }
		$candidates = array(
			BCPRO_DIR . 'legacy/lib/astro-transit-report.php',
			BCPRO_DIR . 'legacy/lib/astro-transit.php',
			BCPRO_DIR . 'legacy/lib/astro-transit-timeline.php',
			BCPRO_DIR . 'legacy/lib/astro-transit-ai.php',
			BCPRO_DIR . 'legacy/lib/astro-report-llm.php',
			BCPRO_DIR . 'legacy/lib/astro-helpers.php',
		);
		foreach ( $candidates as $f ) {
			if ( file_exists( $f ) ) { require_once $f; }
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * Part B — params · quota · cache (2026-06-04 Johnny Chu · PHASE-A C.3b)
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Normalize a `date=` query param to a strict YYYY-MM-DD, or '' if invalid.
	 * Rejects anything outside a sane astrology window (today-3y … today+3y).
	 */
	private static function normalize_date_param( $raw ) {
		$raw = trim( (string) $raw );
		if ( $raw === '' ) { return ''; }
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — support slash format from chat URLs.
		$raw = str_replace( '/', '-', $raw );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) { return ''; }
		$ts = strtotime( $raw . ' 00:00:00' );
		if ( ! $ts ) { return ''; }
		// Re-format to canonical to reject impossible dates (e.g. 2026-02-31).
		if ( gmdate( 'Y-m-d', $ts ) !== $raw ) { return ''; }
		$min = strtotime( '-3 years', current_time( 'timestamp' ) );
		$max = strtotime( '+3 years', current_time( 'timestamp' ) );
		if ( $ts < $min || $ts > $max ) { return ''; }
		return $raw;
	}

	/** Best-effort client IP — REMOTE_ADDR only (XFF is spoofable → unsafe for quota). */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$ip = preg_replace( '/[^0-9a-fA-F:.]/', '', $ip );
		return $ip !== '' ? $ip : '0.0.0.0';
	}

	/**
	 * Anti-DDoS / quota guard. Two tiers:
	 *   - per-IP view throttle (cheap reads)
	 *   - per-coachee+IP regenerate throttle (expensive recompute)
	 *
	 * @return array{blocked:bool, retry?:int, scope?:string, limit?:int}
	 */
	private static function enforce_rate_limit( $coachee_id, $regenerate ) {
		if ( ! apply_filters( 'bcpro_transit_rate_limit_enabled', true, $coachee_id ) ) {
			return array( 'blocked' => false );
		}

		$ip = self::client_ip();

		// Tier 1 — per-IP view throttle.
		$limit  = (int) apply_filters( 'bcpro_transit_rate_limit', self::RL_VIEW_LIMIT, $coachee_id );
		$window = (int) apply_filters( 'bcpro_transit_rate_window', self::RL_VIEW_WINDOW, $coachee_id );
		if ( $limit > 0 ) {
			$key = 'bcpro_tr_rl_' . md5( $ip );
			$n   = (int) get_transient( $key );
			$n++;
			set_transient( $key, $n, $window );
			if ( $n > $limit ) {
				return array( 'blocked' => true, 'retry' => $window, 'scope' => 'ip', 'limit' => $limit );
			}
		}

		// Tier 2 — regenerate is expensive (live recompute) → stricter.
		if ( $regenerate ) {
			$rlimit  = (int) apply_filters( 'bcpro_transit_regen_limit', self::RL_REGEN_LIMIT, $coachee_id );
			$rwindow = (int) apply_filters( 'bcpro_transit_regen_window', self::RL_REGEN_WINDOW, $coachee_id );
			if ( $rlimit > 0 ) {
				$rkey = 'bcpro_tr_rgn_' . (int) $coachee_id . '_' . md5( $ip );
				$m    = (int) get_transient( $rkey );
				$m++;
				set_transient( $rkey, $m, $rwindow );
				if ( $m > $rlimit ) {
					return array( 'blocked' => true, 'retry' => $rwindow, 'scope' => 'regenerate', 'limit' => $rlimit );
				}
			}
		}

		return array( 'blocked' => false );
	}

	/** Emit a 429 response honoring the requested output format, then exit. */
	private static function respond_rate_limited( array $rl, $format ) {
		$retry = isset( $rl['retry'] ) ? (int) $rl['retry'] : 60;
		$scope = isset( $rl['scope'] ) ? (string) $rl['scope'] : 'ip';
		status_header( 429 );
		header( 'Retry-After: ' . $retry );
		nocache_headers();
		if ( $format === 'json' ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array(
				'ok'          => false,
				'error'       => 'rate_limited',
				'scope'       => $scope,
				'retry_after' => $retry,
			) );
		} elseif ( $format === 'md' ) {
			header( 'Content-Type: text/markdown; charset=utf-8' );
			echo '> ⚠️ rate_limited (' . esc_html( $scope ) . ') — thử lại sau ' . (int) $retry . 's.';
		} else {
			wp_die(
				esc_html__( 'Quá nhiều yêu cầu — vui lòng thử lại sau ít phút.', 'bizcoach-pro' ),
				esc_html__( 'Rate limited', 'bizcoach-pro' ),
				array( 'response' => 429 )
			);
		}
		exit;
	}

	/* ── Cache version (per coachee) — bumped on snapshot save / regenerate ── */

	private static function cache_version( $coachee_id ) {
		return (int) get_option( 'bcpro_transit_cachever_' . (int) $coachee_id, 1 );
	}

	private static function bump_cache_version( $coachee_id ) {
		$v = self::cache_version( $coachee_id ) + 1;
		update_option( 'bcpro_transit_cachever_' . (int) $coachee_id, $v, false );
		return $v;
	}

	private static function machine_cache_key( $coachee_id, $period, $date, $format ) {
		$sig = implode( '|', array(
			(int) $coachee_id,
			(string) $period,
			(string) $date,
			(string) $format,
			self::cache_version( $coachee_id ),
			current_time( 'Y-m-d' ),
		) );
		return 'bcpro_tr_pub_' . md5( $sig );
	}

	/**
	 * Cache invalidation hook target. Fired from bccm_transit_save_snapshot()
	 * via do_action( 'bccm_transit_snapshot_saved', $coachee_id, $user_id ).
	 */
	public static function invalidate_for_coachee( $coachee_id, $user_id = 0 ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id > 0 ) { self::bump_cache_version( $coachee_id ); }

		// Also clear the legacy build_context transients (keyed by id+period+date)
		// so the next chat turn rebuilds with the fresh snapshot.
		$ids     = array_filter( array( $coachee_id, (int) $user_id ) );
		$periods = array( 'day', 'week', 'month', 'year', '5year', 'custom_year' );
		$today   = current_time( 'Y-m-d' );
		foreach ( array_unique( $ids ) as $cid ) {
			foreach ( $periods as $p ) {
				delete_transient( 'bccm_transit_' . (int) $cid . '_' . $p . '_' . $today );
			}
		}
	}

	/**
	 * Serve agent-fetchable machine output (md / json) from the DB-first
	 * resolver, with version-aware caching. Exits.
	 */
	private static function serve_machine( $coachee_id, $period, $date, $format, $regenerate ) {
		$ck = self::machine_cache_key( $coachee_id, $period, $date, $format );

		if ( ! $regenerate ) {
			$hit = get_transient( $ck );
			if ( $hit !== false && is_array( $hit ) && isset( $hit['body'], $hit['ctype'] ) ) {
				header( 'Content-Type: ' . $hit['ctype'] );
				header( 'X-BCPro-Cache: HIT' );
				nocache_headers();
				echo $hit['body']; // phpcs:ignore — already-rendered trusted markdown/json.
				exit;
			}
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Transit_Resolver' ) ) {
			self::output_machine( $format, array(
				'ok'         => false,
				'error'      => 'transit_resolver_missing',
				'coachee_id' => (int) $coachee_id,
				'period'     => (string) $period,
				'source'     => 'unavailable',
				'rows_count' => 0,
			), '', 'MISS', '' );
		}

		$resolver = BizCoach_Pro_Astro_Transit_Resolver::instance();
		$resolved = $resolver->resolve( (int) $coachee_id, (string) $period, array(
			'trace_id' => 'pub_' . self::client_ip(),
		) );

		$markdown = isset( $resolved['markdown'] ) ? (string) $resolved['markdown'] : '';
		$payload  = array(
			'ok'         => ( $markdown !== '' ),
			'coachee_id' => (int) $coachee_id,
			'period'     => isset( $resolved['period'] ) ? (string) $resolved['period'] : (string) $period,
			'date'       => (string) $date,
			'source'     => isset( $resolved['source'] ) ? (string) $resolved['source'] : 'unavailable',
			'rows_count' => isset( $resolved['rows_count'] ) ? (int) $resolved['rows_count'] : 0,
			'fetched_at' => isset( $resolved['fetched_at'] ) ? (string) $resolved['fetched_at'] : '',
			'_degraded'  => isset( $resolved['_degraded'] ) ? $resolved['_degraded'] : null,
		);
		if ( $markdown === '' && empty( $payload['error'] ) ) {
			$payload['error'] = ( isset( $resolved['_degraded'] ) && $resolved['_degraded'] )
				? (string) $resolved['_degraded']
				: 'transit_empty';
		}

		// Cache only successful renders (don't pin transient errors).
		$store = ( $markdown !== '' );
		self::output_machine( $format, $payload, $markdown, 'MISS', $store ? $ck : '' );
	}

	/** Render + (optionally) cache a machine payload, then exit. */
	private static function output_machine( $format, array $payload, $markdown, $cache_state, $cache_key = '' ) {
		$period_lbl = isset( $payload['period'] ) ? (string) $payload['period'] : '';
		$source_lbl = isset( $payload['source'] ) ? (string) $payload['source'] : '';
		$rows_lbl   = isset( $payload['rows_count'] ) ? (int) $payload['rows_count'] : 0;

		if ( $format === 'json' ) {
			$ctype = 'application/json; charset=utf-8';
			$body  = wp_json_encode(
				array_merge( $payload, array( 'markdown' => (string) $markdown ) ),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
		} else {
			$ctype  = 'text/markdown; charset=utf-8';
			$err    = empty( $payload['ok'] ) ? ' error=' . ( isset( $payload['error'] ) ? $payload['error'] : 'unknown' ) : '';
			$header = '<!-- transit period=' . $period_lbl . ' source=' . $source_lbl . ' rows=' . $rows_lbl . $err . " -->\n";
			$body   = $header . ( $markdown !== '' ? (string) $markdown : '> (Không có dữ liệu transit khả dụng.)' );
		}

		if ( $cache_key !== '' ) {
			$ttl = (int) apply_filters( 'bcpro_transit_cache_ttl', self::CACHE_TTL, $payload );
			if ( $ttl > 0 ) {
				set_transient( $cache_key, array( 'body' => $body, 'ctype' => $ctype ), $ttl );
			}
		}

		header( 'Content-Type: ' . $ctype );
		header( 'X-BCPro-Cache: ' . $cache_state );
		nocache_headers();
		echo $body; // phpcs:ignore — trusted server-rendered output.
		exit;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Hash helpers
	 * ────────────────────────────────────────────────────────────── */

	public static function generate_hash( $coachee_id ) {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bcpro_astro';
		$msg  = (int) $coachee_id . '|' . self::CHART_TYPE;
		return substr( hash_hmac( 'sha256', $msg, $salt ), 0, 32 );
	}

	public static function verify_hash( $coachee_id, $hash ) {
		return hash_equals( self::generate_hash( $coachee_id ), (string) $hash );
	}

	/**
	 * Public URL builder.
	 *
	 * @param int    $coachee_id
	 * @param string $period      day|week|month|year|custom
	 * @param array  $extra       ['start','end','date','day','format','regenerate']
	 */
	public static function get_public_url( $coachee_id, $period = 'week', $extra = array() ) {
		if ( ! in_array( $period, self::PERIODS, true ) ) { $period = 'week'; }
		$args = array(
			'id'     => (int) $coachee_id,
			'hash'   => self::generate_hash( $coachee_id ),
			'period' => $period,
		);
		if ( ! empty( $extra['start'] ) )      { $args['start']      = (string) $extra['start']; }
		if ( ! empty( $extra['end'] ) )        { $args['end']        = (string) $extra['end']; }
		if ( ! empty( $extra['day'] ) )        { $args['day']        = (string) $extra['day']; }
		if ( ! empty( $extra['date'] ) )       { $args['date']       = (string) $extra['date']; }
		if ( ! empty( $extra['format'] ) )     { $args['format']     = (string) $extra['format']; }
		if ( ! empty( $extra['regenerate'] ) ) { $args['regenerate'] = 1; }
		return add_query_arg( $args, home_url( '/' . self::SLUG . '/' ) );
	}

	public static function flush_on_activation() {
		self::register_rewrites();
		flush_rewrite_rules( false );
	}
}
