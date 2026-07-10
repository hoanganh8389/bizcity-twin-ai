<?php
/**
 * TwinBrain — Astro Context Recall (PHASE-FAA2-TWINBRAIN A1).
 *
 * Lightweight read layer for injecting the current user's primary astrology
 * profile as extra context into regular TwinBrain turns when the prompt
 * contains astro-related keywords (without switching to full web_mode='astro').
 *
 * Ownership contract (§2.1 TWINBRAIN-ASTRO-CONTEXT-CITATION.md):
 *   user_id → bccm_coachees WHERE user_id=%d AND is_self=1
 *   Fallback (no is_self col): first row by id ASC.
 * Never resolves by coachee_id directly — always go through user_id.
 *
 * Fail-open: returns `active:false` when bizcoach-pro tables are missing,
 * no primary profile found, or any DB error. Does NOT throw.
 *
 * Cache: 5 min per (blog_id, user_id) via object cache group 'bizcity_twinbrain'.
 *
 * Usage:
 *   $ctx = BizCity_TwinBrain_Astro_Recall::collect_for_user( $user_id, $prompt );
 *   if ( $ctx['active'] && $ctx['context_md'] !== '' ) {
 *       $opts['extra_context_md']    = $ctx['context_md'];
 *       $opts['extra_context_label'] = 'THÔNG TIN CHIÊM TINH — đọc kỹ trước khi trả lời';
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-05 (PHASE-FAA2-TWINBRAIN A1)
 */

// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — Astro Recall read layer.

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Astro_Recall {

	const CACHE_GROUP   = 'bizcity_twinbrain';
	const CACHE_TTL     = 300;  // 5 min

	/**
	 * Vietnamese + English astro intent keywords.
	 * Used in prompt_has_astro_intent().
	 */
	private static $ASTRO_KEYWORDS = array(
		'chiêm tinh', 'cung hoàng đạo', 'lá số tử vi', 'natal', 'transit',
		'quá cảnh', 'sao thủy', 'sao kim', 'sao hỏa', 'sao mộc', 'sao thổ',
		'mặt trời', 'mặt trăng', 'cung mọc', 'ascendant', 'thiên đỉnh', 'midheaven',
		'nhà 7', 'nhà tình cảm', 'ngày tốt', 'ký hợp đồng theo sao',
		'western astrology', 'astro', 'horoscope', 'zodiac',
		'nghịch hành', 'retrograde', 'bản đồ sao',
	);

	// =====================================================================
	//  Public API
	// =====================================================================

	/**
	 * Check whether a prompt likely needs astro context.
	 *
	 * @param string $prompt User's prompt text.
	 * @return bool
	 */
	public static function prompt_has_astro_intent( $prompt ) {
		$lower = mb_strtolower( (string) $prompt );
		foreach ( self::$ASTRO_KEYWORDS as $kw ) {
			if ( strpos( $lower, (string) $kw ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Collect astro context for a user.
	 *
	 * @param int    $user_id       WordPress user ID.
	 * @param string $prompt        The user's prompt (for context size hints).
	 * @param array  $opts {
	 *   @type int   transit_days      Number of recent transit days to include (default 7).
	 *   @type int   report_sections   Max report sections to include (default 3).
	 *   @type bool  bypass_cache      Set true to force re-fetch (default false).
	 * }
	 * @return array{
	 *   active: bool,
	 *   coachee_id: int,
	 *   profile: array,
	 *   citations: array,
	 *   context_md: string,
	 *   counts: array,
	 *   _degraded: string|null
	 * }
	 */
	public static function collect_for_user( $user_id, $prompt = '', $opts = array() ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return self::inactive_result( 'no_user' );
		}

		$transit_days    = isset( $opts['transit_days'] )    ? max( 1, (int) $opts['transit_days'] )    : 7;
		$report_sections = isset( $opts['report_sections'] ) ? max( 0, (int) $opts['report_sections'] ) : 3;
		$bypass_cache    = ! empty( $opts['bypass_cache'] );

		$cache_key = 'astro_ctx:b' . (int) get_current_blog_id() . ':u' . $user_id . ':t' . $transit_days . ':s' . $report_sections;

		if ( ! $bypass_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = self::do_collect( $user_id, $transit_days, $report_sections );

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	// =====================================================================
	//  Core collection logic
	// =====================================================================

	/**
	 * @return array
	 */
	private static function do_collect( $user_id, $transit_days, $report_sections ) {
		if ( ! bizcity_tbl_exists( self::coachees_table() ) ) {
			return self::inactive_result( 'bizcoach_pro_not_installed' );
		}

		// 1. Resolve primary profile.
		$coachee = self::resolve_primary_coachee( $user_id );
		if ( ! $coachee['found'] ) {
			return self::inactive_result( 'no_primary_profile' );
		}

		$coachee_id = (int) $coachee['id'];
		$counts     = array( 'natal' => 0, 'report_sections' => 0, 'transit_days' => 0 );
		$citations  = array();
		$parts      = array();

		$profile_block = self::build_profile_header( $coachee );
		if ( $profile_block !== '' ) {
			$parts[] = $profile_block;
		}

		// 2. Natal natal summary.
		$natal = self::fetch_natal_summary( $coachee_id );
		if ( $natal !== '' ) {
			$token     = '[astro:natal#' . $coachee_id . ']';
			$parts[]   = "## Natal Chart Summary {$token}\n" . $natal;
			$citations[ $token ] = array( 'type' => 'natal', 'coachee_id' => $coachee_id );
			$counts['natal']     = 1;
		}

		// 3. Report sections (from llm_report cache).
		if ( $report_sections > 0 ) {
			$sections = self::fetch_report_sections( $coachee_id, $report_sections );
			foreach ( $sections as $idx => $sec ) {
				$token     = '[astro:report#' . $coachee_id . '/s' . $idx . ']';
				$parts[]   = "## Report: " . $sec['title'] . " {$token}\n" . $sec['excerpt'];
				$citations[ $token ] = array( 'type' => 'report', 'coachee_id' => $coachee_id, 'section_idx' => $idx );
				$counts['report_sections']++;
			}
		}

		// 4. Recent + upcoming transit snapshots.
		if ( $transit_days > 0 ) {
			$transits = self::fetch_recent_transits( $coachee_id, $transit_days );
			foreach ( $transits as $row ) {
				$date  = (string) $row['date'];
				$token = '[astro:transit#' . $coachee_id . '/' . $date . ']';
				$parts[]   = "### Transit {$date} {$token}\n" . $row['excerpt'];
				$citations[ $token ] = array( 'type' => 'transit_day', 'coachee_id' => $coachee_id, 'date' => $date );
				$counts['transit_days']++;
			}
		}

		$context_md = implode( "\n\n", $parts );

		$result = array(
			'active'     => true,
			'coachee_id' => $coachee_id,
			'profile'    => array(
				'name'    => (string) $coachee['full_name'],
				'is_self' => (bool)   $coachee['is_self'],
			),
			'citations'  => $citations,
			'context_md' => $context_md,
			'counts'     => $counts,
			'_degraded'  => $context_md === '' ? 'no_astro_data' : null,
		);

		if ( $context_md === '' ) {
			$result['active'] = false;
		}

		return $result;
	}

	// =====================================================================
	//  Data fetchers
	// =====================================================================

	/**
	 * Resolve the user's primary coachee row.
	 *
	 * @param int $user_id
	 * @return array{found:bool,id:int,full_name:string,birth_date:string,birth_time:string,is_self:bool}
	 */
	private static function resolve_primary_coachee( $user_id ) {
		global $wpdb;
		$table = self::coachees_table();

		$empty = array( 'found' => false, 'id' => 0, 'full_name' => '', 'birth_date' => '', 'birth_time' => '', 'is_self' => false );

		// Try with is_self column first.
		$has_is_self = self::column_exists( $table, 'is_self' );

		if ( $has_is_self ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, full_name, birth_date, birth_time, is_self FROM `{$table}` WHERE user_id = %d AND is_self = 1 LIMIT 1",
				$user_id
			), ARRAY_A );
		} else {
			$row = null;
		}

		// Fallback: first row for user_id.
		if ( ! is_array( $row ) || empty( $row ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, full_name, birth_date, birth_time" . ( $has_is_self ? ', is_self' : ', 0 AS is_self' ) . " FROM `{$table}` WHERE user_id = %d ORDER BY id ASC LIMIT 1",
				$user_id
			), ARRAY_A );
		}

		if ( ! is_array( $row ) || empty( $row ) ) {
			return $empty;
		}

		return array(
			'found'      => true,
			'id'         => (int)    $row['id'],
			'full_name'  => (string) ( $row['full_name'] ?? '' ),
			'birth_date' => (string) ( $row['birth_date'] ?? '' ),
			'birth_time' => (string) ( $row['birth_time'] ?? '' ),
			'is_self'    => ! empty( $row['is_self'] ),
		);
	}

	/**
	 * Build profile header section.
	 *
	 * @param array $coachee
	 * @return string
	 */
	private static function build_profile_header( $coachee ) {
		$name  = (string) $coachee['full_name'];
		$bdate = (string) $coachee['birth_date'];
		$btime = (string) $coachee['birth_time'];

		if ( $name === '' && $bdate === '' ) {
			return '';
		}

		$line = '# Thông tin chiêm tinh chủ nhân';
		if ( $name !== '' ) {
			$line .= ' — ' . $name;
		}
		if ( $bdate !== '' || $btime !== '' ) {
			$line .= "\n- Ngày sinh: " . $bdate;
			if ( $btime !== '' ) {
				$line .= ' ' . $btime;
			}
		}
		return $line;
	}

	/**
	 * Fetch natal summary text from bccm_astro.
	 *
	 * @param int $coachee_id
	 * @return string Excerpt (max 800 chars) or empty string.
	 */
	private static function fetch_natal_summary( $coachee_id ) {
		if ( ! bizcity_tbl_exists( self::astro_table() ) ) {
			return '';
		}

		global $wpdb;
		$table = self::astro_table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT summary, traits FROM `{$table}` WHERE coachee_id = %d AND chart_type = 'western' LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return '';
		}

		$parts = array();

		// summary JSON: { big3: {sun:, moon:, asc:}, summary: string }
		if ( ! empty( $row['summary'] ) ) {
			$summary = json_decode( (string) $row['summary'], true );
			if ( is_array( $summary ) ) {
				if ( isset( $summary['big3'] ) && is_array( $summary['big3'] ) ) {
					$big3 = $summary['big3'];
					$items = array();
					if ( ! empty( $big3['sun'] ) )  { $items[] = 'Sun: ' . $big3['sun']; }
					if ( ! empty( $big3['moon'] ) ) { $items[] = 'Moon: ' . $big3['moon']; }
					if ( ! empty( $big3['asc'] ) )  { $items[] = 'Asc: ' . $big3['asc']; }
					if ( ! empty( $items ) ) {
						$parts[] = '**Big 3:** ' . implode( ', ', $items );
					}
				}
				if ( isset( $summary['summary'] ) && is_string( $summary['summary'] ) && $summary['summary'] !== '' ) {
					$parts[] = mb_substr( $summary['summary'], 0, 400 );
				}
			}
		}

		// traits (brief keywords)
		if ( ! empty( $row['traits'] ) && empty( $parts ) ) {
			$traits = json_decode( (string) $row['traits'], true );
			if ( is_array( $traits ) ) {
				$kws = array();
				foreach ( array_slice( $traits, 0, 6 ) as $k => $v ) {
					$kws[] = ( is_string( $k ) ? $k . ': ' : '' ) . ( is_string( $v ) ? $v : '' );
				}
				if ( ! empty( $kws ) ) {
					$parts[] = '**Traits:** ' . implode( ' · ', $kws );
				}
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return mb_substr( implode( "\n", $parts ), 0, 800 );
	}

	/**
	 * Fetch up to $max report sections from bccm_astro.llm_report.
	 *
	 * @param int $coachee_id
	 * @param int $max
	 * @return array<int, array{title:string,excerpt:string}>  keyed by section index.
	 */
	private static function fetch_report_sections( $coachee_id, $max ) {
		if ( ! bizcity_tbl_exists( self::astro_table() ) ) {
			return array();
		}

		global $wpdb;
		$table = self::astro_table();
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT llm_report FROM `{$table}` WHERE coachee_id = %d AND chart_type = 'western' LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! is_array( $row ) || empty( $row['llm_report'] ) ) {
			return array();
		}

		$decoded  = json_decode( (string) $row['llm_report'], true );
		$sections = isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ? $decoded['sections'] : array();

		$result = array();
		$count  = 0;
		foreach ( $sections as $idx => $raw ) {
			if ( $count >= $max ) {
				break;
			}
			if ( ! is_string( $raw ) || $raw === '' ) {
				continue;
			}
			$lines = preg_split( '/\r\n|\r|\n/', trim( $raw ) );
			$title = '';
			if ( is_array( $lines ) && ! empty( $lines ) ) {
				$title = trim( strip_tags( $lines[0] ) );
			}
			$excerpt = mb_substr( trim( strip_tags( $raw ) ), 0, 250 );
			if ( $excerpt !== '' ) {
				$result[ (int) $idx ] = array( 'title' => $title, 'excerpt' => $excerpt );
				$count++;
			}
		}

		return $result;
	}

	/**
	 * Fetch recent/upcoming transit rows for a coachee.
	 *
	 * Selects rows spanning [-3 days, +$days days] from today, ordered by date.
	 *
	 * @param int $coachee_id
	 * @param int $days
	 * @return array<int, array{date:string,excerpt:string}>
	 */
	private static function fetch_recent_transits( $coachee_id, $days ) {
		if ( ! bizcity_tbl_exists( self::transit_table() ) ) {
			return array();
		}

		global $wpdb;
		$table    = self::transit_table();
		$date_col = self::transit_date_column( $table );
		if ( $date_col === '' ) {
			return array();
		}

		$today_ts = current_time( 'timestamp', true );
		$from     = gmdate( 'Y-m-d', $today_ts - 3 * DAY_IN_SECONDS );
		$to       = gmdate( 'Y-m-d', $today_ts + $days * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT `{$date_col}` AS snap_date, planets_json, aspects_json
			   FROM `{$table}`
			  WHERE coachee_id = %d
			    AND `{$date_col}` BETWEEN %s AND %s
			  ORDER BY `{$date_col}` ASC
			  LIMIT %d",
			$coachee_id,
			$from,
			$to,
			$days + 3
		), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$date    = (string) ( $row['snap_date'] ?? '' );
			$aspects = ! empty( $row['aspects_json'] ) ? json_decode( (string) $row['aspects_json'], true ) : array();
			$planets = ! empty( $row['planets_json'] ) ? json_decode( (string) $row['planets_json'], true ) : array();

			$aspects_count = is_array( $aspects ) ? count( $aspects ) : 0;
			$retro_list    = array();
			if ( is_array( $planets ) ) {
				foreach ( $planets as $p ) {
					if ( is_array( $p ) && ! empty( $p['retrograde'] ) ) {
						$retro_list[] = (string) ( $p['name'] ?? '' );
					}
				}
			}

			$excerpt = 'Transit ' . $date . ' · ' . $aspects_count . ' aspects';
			if ( ! empty( $retro_list ) ) {
				$excerpt .= ' · Retrograde: ' . implode( ', ', array_filter( $retro_list ) );
			}

			$result[] = array( 'date' => $date, 'excerpt' => $excerpt );
		}

		return $result;
	}

	// =====================================================================
	//  Schema helpers
	// =====================================================================

	private static function coachees_table() {
		global $wpdb;
		return $wpdb->prefix . 'bccm_coachees';
	}

	private static function astro_table() {
		global $wpdb;
		return $wpdb->prefix . 'bccm_astro';
	}

	private static function transit_table() {
		global $wpdb;
		return $wpdb->prefix . 'bccm_transit_snapshots';
	}

	/**
	 * Return the date column name for transit_snapshots (target_date or snap_date).
	 * Result is static-cached per table name.
	 *
	 * @param string $table Fully-qualified table name.
	 * @return string Column name or empty string on failure.
	 */
	private static function transit_date_column( $table ) {
		static $cache = array();
		if ( isset( $cache[ $table ] ) ) {
			return $cache[ $table ];
		}

		$col = '';
		if ( self::column_exists( $table, 'target_date' ) ) {
			$col = 'target_date';
		} elseif ( self::column_exists( $table, 'snap_date' ) ) {
			$col = 'snap_date';
		}

		$cache[ $table ] = $col;
		return $col;
	}

	/**
	 * Check if a column exists in a table using information_schema (R-SHOW-TABLES pattern).
	 * Dual-cached (static + wp_cache, 1h).
	 *
	 * @param string $table  Fully-qualified table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( $table, $column ) {
		static $s = array();
		$k = $table . '|' . $column;
		if ( isset( $s[ $k ] ) ) {
			return $s[ $k ];
		}

		$ck = 'bz_col_' . (int) get_current_blog_id() . '_' . crc32( $k );
		$cached = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false !== $cached ) {
			$s[ $k ] = (bool) $cached;
			return $s[ $k ];
		}

		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table,
			$column
		) );

		wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		$s[ $k ] = (bool) $present;
		return $s[ $k ];
	}

	// =====================================================================
	//  Helpers
	// =====================================================================

	/**
	 * Return a fail-open inactive result.
	 *
	 * @param string $reason
	 * @return array
	 */
	private static function inactive_result( $reason ) {
		return array(
			'active'     => false,
			'coachee_id' => 0,
			'profile'    => array( 'name' => '', 'is_self' => false ),
			'citations'  => array(),
			'context_md' => '',
			'counts'     => array( 'natal' => 0, 'report_sections' => 0, 'transit_days' => 0 ),
			'_degraded'  => $reason,
		);
	}
}
