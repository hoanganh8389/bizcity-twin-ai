<?php
/**
 * Class BizCoach_Pro_Natal_Report_Public_Page
 *
 * Public shareable natal chart report page at /natal-report/?data=BASE64.
 * Decodes the same ReportEnvelope produced by open-chart.vercel.app, calls
 * BizCoach_Pro_Astro_Client, then server-side renders a full HTML page whose
 * CSS and layout exactly mirror the open-chart reference design.
 *
 * Cache Contract:
 *   group  : bcpro_natal_report
 *   key    : natal_{md5(data)}  → NatalChartResponse envelope (array), TTL 3600
 *   key    : svg_{md5(data)}    → SVG string, TTL 3600
 *   inv    : not applicable (read-only cache per unique payload hash)
 *
 * @package BizCoach_Pro
 * @since   0.3.24
 * [2026-06-12 Johnny Chu] PHASE-NATAL-REPORT — initial ship
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( class_exists( 'BizCoach_Pro_Natal_Report_Public_Page' ) ) { return; }

final class BizCoach_Pro_Natal_Report_Public_Page {

	const PAGE_SLUG     = 'natal-report';
	const QUERY_VAR     = 'bcpro_natal_report';
	const CACHE_GROUP   = 'bcpro_natal_report';
	const CACHE_TTL     = 3600;

	/* ----------------------------------------------------------------
	 * Boot
	 * -------------------------------------------------------------- */

	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ) );
		// Flush guard is handled by the central BizCity_Rewrite_Flush_Registry
		// registered in bizcoach-pro.php via BCPRO_REWRITE_VERSION. No extra flush here.
	}

	public static function register_rewrite() {
		add_rewrite_rule(
			'^natal-report/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * @param array $vars
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function maybe_handle() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_data = isset( $_GET['data'] ) ? sanitize_text_field( wp_unslash( $_GET['data'] ) ) : '';

		if ( $raw_data === '' ) {
			self::render_error_page( 'Không tìm thấy dữ liệu báo cáo.', 'Hãy thử mở lại link từ ứng dụng.' );
			exit;
		}

		$payload = self::decode_payload( $raw_data );
		if ( ! $payload ) {
			self::render_error_page( 'Link báo cáo không hợp lệ hoặc đã hết hạn.', 'Tạo lại báo cáo từ trang chiêm tinh của bạn.' );
			exit;
		}

		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — replace old dual direct-client calls
		// (which returned V2 envelope with name_en/sign_en keys not read by render functions)
		// with bccm_astro_fetch_full_chart_via_gateway_v2() that returns legacy-FAA shaped data
		// PLUS angles_details / stelliums / interpretation. Single render_v2_* cache key.
		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — bump render cache key
		// to apply new wheel image URL preference + section ordering immediately.
		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT — include bottom long-form EN text.
		$cache_key_render = 'render_v4_' . md5( $raw_data );
		$render_bundle    = false;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$render_bundle = BizCity_Cache::get( self::CACHE_GROUP, $cache_key_render );
		}

		if ( false !== $render_bundle && is_array( $render_bundle ) && isset( $render_bundle['natal_data'] ) ) {
			$natal_data = $render_bundle['natal_data'];
			$svg_data   = isset( $render_bundle['svg_data'] ) ? (string) $render_bundle['svg_data'] : '';
		} else {
			// Allow up to 120s: two gateway calls (natal ~30s + SVG ~30s) + optional download.
			@set_time_limit( 120 );

			if ( function_exists( 'bccm_astro_fetch_full_chart_via_gateway_v2' ) ) {
				$birth_input = array(
					'year'        => isset( $payload['year'] )   ? (int)    $payload['year']   : 2000,
					'month'       => isset( $payload['month'] )  ? (int)    $payload['month']  : 1,
					'day'         => isset( $payload['day'] )    ? (int)    $payload['day']    : 1,
					'hour'        => isset( $payload['hour'] )   ? (int)    $payload['hour']   : 12,
					'minute'      => isset( $payload['minute'] ) ? (int)    $payload['minute'] : 0,
					'lat'         => isset( $payload['lat'] )    ? (float)  $payload['lat']    : 0.0,
					'lng'         => isset( $payload['lng'] )    ? (float)  $payload['lng']    : 0.0,
					'tz_str'      => isset( $payload['tz_str'] ) ? (string) $payload['tz_str'] : 'UTC',
					'name'        => isset( $payload['name'] )   ? (string) $payload['name']   : '',
					'birth_place' => isset( $payload['city'] )   ? (string) $payload['city']   : '',
				);
				$v2_result = bccm_astro_fetch_full_chart_via_gateway_v2( $birth_input );
				if ( is_wp_error( $v2_result ) || empty( $v2_result['success'] ) ) {
					$err_msg = is_wp_error( $v2_result )
						? $v2_result->get_error_message()
						: 'Lỗi kết nối máy chủ chiêm tinh.';
					self::render_error_page( 'Không thể tải dữ liệu chiêm tinh.', $err_msg );
					exit;
				}
				$bundle = self::build_render_data( $v2_result, $payload );
			} else {
				// Legacy fallback: direct natal call (old path — no normalization for patterns).
				$natal_payload = array_merge( $payload, array(
					'include_features'  => array( 'asc', 'mc', 'chiron', 'lilith', 'true_node', 'mean_node' ),
					'include_dominants' => true,
					'interpretation'    => array( 'enable' => true, 'style' => 'improved' ),
				) );
				$natal_result = BizCoach_Pro_Astro_Client::natal_western( $natal_payload, array( 'timeout' => 45 ) );
				if ( empty( $natal_result['success'] ) ) {
					$err_msg = isset( $natal_result['error'] ) ? (string) $natal_result['error'] : 'Lỗi kết nối máy chủ.';
					self::render_error_page( 'Không thể tải dữ liệu chiêm tinh.', $err_msg );
					exit;
				}
				$env = (array) ( $natal_result['envelope'] ?? array() );
				$svg_payload = array(
					'year'   => isset( $payload['year'] )   ? $payload['year']   : 2000,
					'month'  => isset( $payload['month'] )  ? $payload['month']  : 1,
					'day'    => isset( $payload['day'] )    ? $payload['day']    : 1,
					'hour'   => isset( $payload['hour'] )   ? $payload['hour']   : 12,
					'minute' => isset( $payload['minute'] ) ? $payload['minute'] : 0,
					'lat'    => isset( $payload['lat'] )    ? $payload['lat']    : 0,
					'lng'    => isset( $payload['lng'] )    ? $payload['lng']    : 0,
					'tz_str' => isset( $payload['tz_str'] ) ? $payload['tz_str'] : 'UTC',
					'name'   => isset( $payload['name'] )   ? $payload['name']   : '',
					'house_system' => 'placidus', 'zodiac_type' => 'tropical',
				);
				$svg_res = BizCoach_Pro_Astro_Client::chart_svg_western( $svg_payload, array( 'timeout' => 30 ) );
				$svg_raw = ( ! empty( $svg_res['success'] ) && ! empty( $svg_res['envelope']['svg'] ) )
				           ? (string) $svg_res['envelope']['svg'] : '';
				$bundle  = array( 'natal_data' => $env, 'svg_data' => $svg_raw );
			}

			$natal_data = $bundle['natal_data'];
			$svg_data   = isset( $bundle['svg_data'] ) ? (string) $bundle['svg_data'] : '';
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::set( self::CACHE_GROUP, $cache_key_render, $bundle, self::CACHE_TTL );
			}
		}

		self::render_page( $payload, $natal_data, $svg_data );
		exit;
	}

	/* ----------------------------------------------------------------
	 * Payload decoder — same ReportEnvelope as open-chart.vercel.app
	 * ----------------------------------------------------------------
	 * Accepted formats:
	 *   (a) base64url-encoded JSON string → decode → parse
	 *   (b) plain JSON string (for testing)
	 * ReportEnvelope: { payload: NatalRequestPayload, createdAt: number }
	 * Returns the inner NatalRequestPayload array or false on failure.
	 * -------------------------------------------------------------- */

	/**
	 * @param string $raw
	 * @return array|false
	 */
	private static function decode_payload( $raw ) {
		// Try base64url → base64 → JSON
		$base64 = strtr( $raw, '-_', '+/' );
		$pad    = strlen( $base64 ) % 4;
		if ( $pad ) {
			$base64 .= str_repeat( '=', 4 - $pad );
		}
		$decoded = base64_decode( $base64, true );
		if ( false === $decoded ) {
			// Maybe it's already plain JSON
			$decoded = $raw;
		}

		$envelope = json_decode( $decoded, true );
		if ( ! is_array( $envelope ) ) {
			return false;
		}

		// Unwrap ReportEnvelope { payload: {...} }
		if ( isset( $envelope['payload'] ) && is_array( $envelope['payload'] ) ) {
			$p = $envelope['payload'];
		} else {
			// Bare NatalRequestPayload
			$p = $envelope;
		}

		// Require bare minimum: year
		if ( ! isset( $p['year'] ) ) {
			return false;
		}

		return $p;
	}

	/* ================================================================
	 * Render data builder — normalize gateway V2 / legacy-FAA shapes
	 * [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX
	 * ============================================================== */

	/**
	 * Normalize bccm_astro_fetch_full_chart_via_gateway_v2() result into the flat format
	 * expected by render_planet_table / render_houses_table / aspect table in render_page().
	 *
	 * Handles 3 possible input shapes per entity:
	 *   legacy-FAA  — planet.en / zodiac_sign.name.en / normDegree / isRetro
	 *   V2 envelope — id / name_en / sign_en / sign_degree / retrograde
	 *   already-flat — id / name / sign / pos / house / retrograde (from cache)
	 *
	 * @param  array $v2      Return value of bccm_astro_fetch_full_chart_via_gateway_v2().
	 * @param  array $payload Original ReportEnvelope payload (for subject name fallback).
	 * @return array { natal_data:array, svg_data:string }
	 */
	private static function build_render_data( array $v2, array $payload ) {
		// ── Planets ──────────────────────────────────────────────────────────
		$planets = array();
		foreach ( (array) ( isset( $v2['planets'] ) ? $v2['planets'] : array() ) as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			if ( isset( $p['planet'] ) ) {
				// Legacy-FAA shape (_bccm_g2_v2planet_to_legacy).
				$pname = (string) ( isset( $p['planet']['en'] ) ? $p['planet']['en'] : '' );
				$pid   = str_replace( array( ' ', '-' ), '_', strtolower( $pname ) );
				$sign  = isset( $p['zodiac_sign']['name']['en'] )
				         ? (string) $p['zodiac_sign']['name']['en'] : '';
				$pos   = (float) ( isset( $p['normDegree'] ) ? $p['normDegree'] : 0 );
				$retro = isset( $p['isRetro'] ) ? ( $p['isRetro'] === 'true' ) : false;
				$house = (int) ( isset( $p['house'] ) ? $p['house'] : 0 );
			} elseif ( isset( $p['id'] ) ) {
				// V2 envelope raw format.
				$pid   = strtolower( (string) $p['id'] );
				$pname = (string) ( isset( $p['name_en'] ) ? $p['name_en'] : ( isset( $p['name'] ) ? $p['name'] : ucfirst( $pid ) ) );
				$sign  = (string) ( isset( $p['sign_en'] ) ? $p['sign_en'] : ( isset( $p['sign'] ) ? $p['sign'] : '' ) );
				$pos   = (float) ( isset( $p['sign_degree'] ) ? $p['sign_degree'] : ( isset( $p['normDegree'] ) ? $p['normDegree'] : ( isset( $p['pos'] ) ? $p['pos'] : 0 ) ) );
				$retro = ! empty( $p['retrograde'] );
				$house = (int) ( isset( $p['house'] ) ? $p['house'] : 0 );
			} else {
				// Already flat (e.g. served from cache).
				$pid   = (string) ( isset( $p['id'] )   ? $p['id']   : '' );
				$pname = (string) ( isset( $p['name'] ) ? $p['name'] : '' );
				$sign  = (string) ( isset( $p['sign'] ) ? $p['sign'] : '' );
				$pos   = (float)  ( isset( $p['pos'] )  ? $p['pos']  : 0 );
				$retro = ! empty( $p['retrograde'] );
				$house = (int)   ( isset( $p['house'] ) ? $p['house'] : 0 );
			}
			if ( $pid === '' && $pname !== '' ) {
				$pid = str_replace( array( ' ', '-' ), '_', strtolower( $pname ) );
			}
			$planets[] = array(
				'id'         => $pid,
				'name'       => $pname,
				'sign'       => $sign,
				'pos'        => $pos,
				'house'      => $house,
				'retrograde' => $retro,
			);
		}

		// ── Houses ───────────────────────────────────────────────────────────
		$houses = array();
		foreach ( (array) ( isset( $v2['houses'] ) ? $v2['houses'] : array() ) as $h ) {
			if ( ! is_array( $h ) ) { continue; }
			if ( isset( $h['zodiac_sign'] ) ) {
				// Legacy-FAA shape (_bccm_g2_v2house_to_legacy).
				$hnum = (int)   ( isset( $h['house'] ) ? $h['house'] : ( isset( $h['House'] ) ? $h['House'] : 0 ) );
				$sign = isset( $h['zodiac_sign']['name']['en'] )
				        ? (string) $h['zodiac_sign']['name']['en'] : '';
				$pos  = (float) ( isset( $h['normDegree'] ) ? $h['normDegree'] : ( isset( $h['degree'] ) ? $h['degree'] : 0 ) );
			} elseif ( isset( $h['sign_en'] ) ) {
				// V2 envelope format.
				$hnum = (int)    ( isset( $h['house'] )       ? $h['house']       : 0 );
				$sign = (string) $h['sign_en'];
				$pos  = (float)  ( isset( $h['cusp_degree'] ) ? $h['cusp_degree'] : 0 );
			} else {
				// Already flat.
				$hnum = (int)   ( isset( $h['house'] ) ? $h['house'] : 0 );
				$sign = (string) ( isset( $h['sign'] ) ? $h['sign']  : '' );
				$pos  = (float)  ( isset( $h['pos'] )  ? $h['pos']   : 0 );
			}
			$houses[] = array( 'house' => $hnum, 'sign' => $sign, 'pos' => $pos );
		}
		usort( $houses, function( $a, $b ) { return (int) $a['house'] - (int) $b['house']; } );

		// ── Aspects ──────────────────────────────────────────────────────────
		$major_types = array( 'conjunction', 'opposition', 'trine', 'square', 'sextile' );
		$aspects     = array();
		foreach ( (array) ( isset( $v2['aspects'] ) ? $v2['aspects'] : array() ) as $a ) {
			if ( ! is_array( $a ) ) { continue; }
			if ( isset( $a['planet_1_en'] ) ) {
				// Legacy-FAA shape (_bccm_g2_v2aspect_to_legacy).
				$p1   = (string) $a['planet_1_en'];
				$p2   = (string) ( isset( $a['planet_2_en'] ) ? $a['planet_2_en'] : '' );
				$type = strtolower( (string) ( isset( $a['type'] ) ? $a['type'] : ( isset( $a['aspect_en'] ) ? $a['aspect_en'] : '' ) ) );
				$orb  = (float) ( isset( $a['orb'] ) ? $a['orb'] : 0 );
				$deg  = (float) ( isset( $a['aspect_degree'] ) ? $a['aspect_degree'] : 0 );
			} elseif ( isset( $a['planet1'] ) ) {
				// Raw FAA2 provider shape (planet1 TitleCase key).
				$p1   = ucwords( strtolower( (string) $a['planet1'] ) );
				$p2   = ucwords( strtolower( (string) ( isset( $a['planet2'] ) ? $a['planet2'] : '' ) ) );
				$type = strtolower( (string) ( isset( $a['aspect'] ) ? $a['aspect'] : ( isset( $a['type'] ) ? $a['type'] : '' ) ) );
				$orb  = (float) ( isset( $a['orb'] ) ? $a['orb'] : 0 );
				$deg  = (float) ( isset( $a['angle'] ) ? $a['angle'] : ( isset( $a['aspect_degree'] ) ? $a['aspect_degree'] : 0 ) );
			} elseif ( isset( $a['p1'] ) || isset( $a['p1_name'] ) ) {
				// V2-normalized or already flat.
				$p1   = (string) ( isset( $a['p1'] ) ? $a['p1'] : $a['p1_name'] );
				$p2   = (string) ( isset( $a['p2'] ) ? $a['p2'] : ( isset( $a['p2_name'] ) ? $a['p2_name'] : '' ) );
				$type = strtolower( (string) ( isset( $a['type_en'] ) ? $a['type_en'] : ( isset( $a['type'] ) ? $a['type'] : '' ) ) );
				$orb  = (float) ( isset( $a['orb'] ) ? $a['orb'] : 0 );
				$deg  = (float) ( isset( $a['angle'] ) ? $a['angle'] : ( isset( $a['deg'] ) ? $a['deg'] : ( isset( $a['aspect_degree'] ) ? $a['aspect_degree'] : 0 ) ) );
			} else {
				continue; // Unrecognized shape.
			}
			$is_major = isset( $a['is_major'] )
			            ? (bool) $a['is_major']
			            : in_array( $type, $major_types, true );
			$aspects[] = array(
				'p1'       => $p1,
				'p2'       => $p2,
				'type'     => $type,
				'orb'      => $orb,
				'deg'      => $deg,
				'is_major' => $is_major,
			);
		}

		// ── SVG / chart image ────────────────────────────────────────────────
		$svg_data  = '';
		$img_url   = '';
		$chart_url = (string) ( isset( $v2['chart_url'] ) ? $v2['chart_url'] : '' );
		if ( $chart_url !== '' ) {
			$svg_trim = ltrim( $chart_url );
			if ( 0 === strpos( $svg_trim, '<svg' ) || false !== strpos( $chart_url, '</svg>' ) ) {
				$svg_data = $chart_url;
			} elseif ( 0 === strpos( $chart_url, 'http://' ) || 0 === strpos( $chart_url, 'https://' ) || 0 === strpos( $chart_url, 'data:' ) ) {
				// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — keep image as URL source
				// (wp-content/uploads/.../bizcoach-astro-charts/{id}_natal.svg), do not inline.
				$img_url = $chart_url;
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — prefer local uploads SVG URL
		// saved in bccm_astro.chart_svg for this coachee when available.
		$coachee_id     = isset( $payload['coachee_id'] ) ? (int) $payload['coachee_id'] : 0;
		$local_chart_url = self::resolve_local_chart_img_url( $coachee_id );
		if ( $local_chart_url !== '' ) {
			$img_url  = $local_chart_url;
			$svg_data = '';
		}

		// ── Assemble natal_data ──────────────────────────────────────────────
		$name_from = (string) ( isset( $v2['birth_data']['name'] ) ? $v2['birth_data']['name'] : ( isset( $payload['name'] ) ? $payload['name'] : '' ) );
		$natal_data = array(
			'subject'        => array( 'name' => $name_from ),
			'planets'        => $planets,
			'houses'         => $houses,
			'aspects'        => $aspects,
			'angles_details' => (array) ( isset( $v2['angles_details'] ) ? $v2['angles_details'] : array() ),
			'interpretation' => isset( $v2['interpretation'] ) ? $v2['interpretation'] : null,
			'dominants'      => null,
			'stelliums'      => (array) ( isset( $v2['stelliums'] ) ? $v2['stelliums'] : array() ),
			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT — attach cached chapter texts
			// so the public EN page can show full long-form interpretation at bottom.
			'llm_sections'   => self::load_llm_report_sections( isset( $payload['coachee_id'] ) ? (int) $payload['coachee_id'] : 0, 'western' ),
			'chart_img_url'  => $img_url,
		);

		return array( 'natal_data' => $natal_data, 'svg_data' => $svg_data );
	}

	/**
	 * Resolve latest locally saved western wheel SVG URL from bccm_astro.chart_svg.
	 *
	 * @param int $coachee_id
	 * @return string
	 */
	private static function resolve_local_chart_img_url( $coachee_id ) {
		if ( $coachee_id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro';
		$url   = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT chart_svg FROM {$table} WHERE coachee_id=%d AND chart_type='western' AND chart_svg IS NOT NULL AND chart_svg<>'' ORDER BY id DESC LIMIT 1",
				$coachee_id
			)
		);
		if ( $url === '' ) {
			return '';
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) || 0 === strpos( $url, '/' ) ) {
			return $url;
		}
		return '';
	}

	/* ================================================================
	 * HTML rendering
	 * ============================================================== */

	/**
	 * @param array  $payload    Decoded NatalRequestPayload
	 * @param array  $natal_data Natal envelope (NatalChartResponse)
	 * @param string $svg_data   Raw SVG markup or empty string
	 */
	private static function render_page( $payload, $natal_data, $svg_data ) {
		$subject   = isset( $natal_data['subject'] ) && is_array( $natal_data['subject'] )
		             ? $natal_data['subject'] : array();
		$planets   = isset( $natal_data['planets'] ) && is_array( $natal_data['planets'] )
		             ? $natal_data['planets'] : array();
		$houses    = isset( $natal_data['houses'] ) && is_array( $natal_data['houses'] )
		             ? $natal_data['houses'] : array();
		$aspects   = isset( $natal_data['aspects'] ) && is_array( $natal_data['aspects'] )
		             ? $natal_data['aspects'] : array();
		$angles_d  = isset( $natal_data['angles_details'] ) && is_array( $natal_data['angles_details'] )
		             ? $natal_data['angles_details'] : array();
		$interp      = isset( $natal_data['interpretation'] ) ? $natal_data['interpretation'] : null;
		$dominants   = isset( $natal_data['dominants'] ) ? $natal_data['dominants'] : null;
		$stelliums   = isset( $natal_data['stelliums'] ) && is_array( $natal_data['stelliums'] )
		               ? $natal_data['stelliums'] : array();
		$llm_sections = isset( $natal_data['llm_sections'] ) && is_array( $natal_data['llm_sections'] )
		               ? $natal_data['llm_sections'] : array();
		$chart_img_url = isset( $natal_data['chart_img_url'] ) ? (string) $natal_data['chart_img_url'] : '';

		$name        = isset( $subject['name'] ) ? (string) $subject['name'] : ( isset( $payload['name'] ) ? (string) $payload['name'] : '' );
		$font_url    = BCPRO_URL . '_library/open-chart-main/public/fonts/starfont-sans.ttf';
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// Build section IDs for planet interpretation anchor links
		$planet_section_ids = array();
		foreach ( $planets as $p ) {
			if ( isset( $p['id'] ) && ! in_array( (string) $p['id'], array( 'true_node', 'mean_node', 'lilith', 'mean_lilith', 'chiron' ), true ) ) {
				$planet_section_ids[] = (string) $p['id'];
			}
		}

		?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $name ? $name . ' — Natal Chart Report' : 'Natal Chart Report' ); ?></title>
<style>
/* ---------------------------------------------------------------
 * Base / Reset
 * ------------------------------------------------------------- */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body {
  font-family: "Avenir Next", Avenir, "Helvetica Neue", Helvetica, Arial, sans-serif;
  background: #fff;
  color: #000;
  -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: underline; }
a:hover { text-decoration: none; }
table { border-collapse: collapse; width: 100%; }
th, td { text-align: left; vertical-align: top; }

/* ---------------------------------------------------------------
 * StarFont Sans — astrological glyphs
 * ASCII chars map to planet / sign / aspect symbols.
 * ------------------------------------------------------------- */
@font-face {
  font-family: "StarFont Sans";
  src: url("<?php echo esc_url( $font_url ); ?>") format("truetype");
  font-display: swap;
}
.glyph {
  font-family: "StarFont Sans", serif;
  font-size: 1em;
  line-height: 1;
}
.glyph-lg {
  font-family: "StarFont Sans", serif;
  font-size: 1.4em;
  line-height: 1;
}

/* ---------------------------------------------------------------
 * Layout wrapper
 * ------------------------------------------------------------- */
.container {
  max-width: 1152px;
  margin: 0 auto;
  padding: 40px 24px;
}

/* ---------------------------------------------------------------
 * Header
 * ------------------------------------------------------------- */
.report-header { margin-bottom: 24px; }
.report-title {
  font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
  margin-bottom: 4px;
}
.report-subtitle {
  font-size: 0.875rem;
  color: #555;
  margin-bottom: 12px;
}
.btn-border {
  display: inline-block;
  border: 1px solid #000;
  padding: 6px 16px;
  font-size: 0.875rem;
  cursor: pointer;
  background: transparent;
  text-decoration: none;
  line-height: 1.5;
}
.btn-border:hover { background: #000; color: #fff; text-decoration: none; }

/* ---------------------------------------------------------------
 * Divider
 * ------------------------------------------------------------- */
hr.divider { border: none; border-top: 1px solid #000; margin: 24px 0; }

/* ---------------------------------------------------------------
 * Two-column grid
 * ------------------------------------------------------------- */
.report-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 32px;
}
@media (min-width: 1024px) {
  .report-grid {
    grid-template-columns: 2fr 1fr;
    align-items: start;
  }
}

/* ---------------------------------------------------------------
 * Main column
 * ------------------------------------------------------------- */
.main-col { min-width: 0; }

/* ---------------------------------------------------------------
 * Sidebar column
 * ------------------------------------------------------------- */
.sidebar-col {
  min-width: 0;
}
@media (min-width: 1024px) {
  .sidebar-col {
    position: sticky;
    top: 24px;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
  }
}

/* ---------------------------------------------------------------
 * Section headings
 * ------------------------------------------------------------- */
.section-heading {
  font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
  font-size: 1.1rem;
  font-weight: 700;
  border-bottom: 1px solid #000;
  padding-bottom: 6px;
  margin-bottom: 12px;
}
.section-heading-sm {
  font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
  font-size: 0.95rem;
  font-weight: 700;
  margin-bottom: 6px;
}
.subsection-label {
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: #555;
  margin-bottom: 2px;
  margin-top: 12px;
}

/* ---------------------------------------------------------------
 * Sidebar info table
 * ------------------------------------------------------------- */
.info-table td { padding: 3px 0; font-size: 0.8125rem; }
.info-table td:first-child { color: #555; width: 100px; padding-right: 8px; }
.info-table td:last-child  { font-weight: 500; }

/* ---------------------------------------------------------------
 * Planet positions table
 * ------------------------------------------------------------- */
.planet-table { font-size: 0.8125rem; }
.planet-table th {
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  padding: 4px 6px 4px 0;
  border-bottom: 1px solid #000;
  color: #555;
}
.planet-table td { padding: 4px 6px 4px 0; border-bottom: 1px solid #e5e5e5; }
.planet-table tr:last-child td { border-bottom: none; }
.planet-table .glyph-col { width: 24px; text-align: center; }
.planet-retro { color: #b45309; font-size: 0.7em; vertical-align: super; }

/* ---------------------------------------------------------------
 * Sidebar subsection label rows
 * ------------------------------------------------------------- */
.sidebar-block { margin-bottom: 20px; }

/* ---------------------------------------------------------------
 * Wheel SVG
 * ------------------------------------------------------------- */
.natal-wheel {
  width: 100%;
  max-width: 600px;
  margin: 0 auto;
  display: block;
}
.natal-wheel svg { width: 100%; height: auto; display: block; }

/* ---------------------------------------------------------------
 * Export PDF bar
 * ------------------------------------------------------------- */
.main-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}

/* ---------------------------------------------------------------
 * Interpretation section
 * ------------------------------------------------------------- */
.interp-section { margin-bottom: 32px; }
.interp-heading {
  font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
  font-size: 1.25rem;
  font-weight: 700;
  margin-bottom: 8px;
}
.interp-item { margin-bottom: 16px; }
.interp-item-title {
  font-weight: 600;
  margin-bottom: 4px;
}
.interp-item-body { font-size: 0.9375rem; line-height: 1.6; color: #111; }
.interp-item-body.interp-rich p { margin: 0 0 10px; }
.interp-item-body.interp-rich h4,
.interp-item-body.interp-rich h5,
.interp-item-body.interp-rich h6 {
	font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
	margin: 14px 0 6px;
	line-height: 1.35;
}
.interp-item-body.interp-rich h4 { font-size: 1.05rem; border-bottom: 1px solid #e5e5e5; padding-bottom: 2px; }
.interp-item-body.interp-rich h5 { font-size: 0.98rem; }
.interp-item-body.interp-rich h6 { font-size: 0.92rem; }
.interp-item-body.interp-rich ul,
.interp-item-body.interp-rich ol { margin: 8px 0 12px 20px; }
.interp-item-body.interp-rich li { margin-bottom: 4px; }
.interp-item-body.interp-rich blockquote {
	margin: 10px 0;
	padding: 8px 12px;
	border-left: 3px solid #cbd5e1;
	background: #f8fafc;
}
.interp-item-body.interp-rich hr {
	border: none;
	border-top: 1px dashed #d1d5db;
	margin: 14px 0;
}
.interp-tags { margin-top: 6px; }
.interp-tag {
  display: inline-block;
  font-size: 0.7rem;
  border: 1px solid #bbb;
  padding: 1px 6px;
  margin-right: 4px;
  margin-bottom: 2px;
  border-radius: 2px;
  color: #555;
}

/* ---------------------------------------------------------------
 * Planet interpretation section
 * ------------------------------------------------------------- */
.planet-section {
  margin-bottom: 32px;
  padding-top: 8px;
  border-top: 1px solid #e5e5e5;
}
.planet-section-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}
.planet-section-icon {
  font-family: "StarFont Sans", serif;
  font-size: 1.5rem;
  line-height: 1;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid #000;
  border-radius: 50%;
  flex-shrink: 0;
}
.planet-section-title {
  font-family: "Iowan Old Style", "Book Antiqua", Palatino, "Palatino Linotype", serif;
  font-size: 1rem;
  font-weight: 700;
}
.planet-section-subtitle { font-size: 0.8125rem; color: #555; }

/* ---------------------------------------------------------------
 * Aspects table
 * ------------------------------------------------------------- */
.aspects-table { font-size: 0.8125rem; }
.aspects-table th {
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #555;
  padding: 4px 8px 4px 0;
  border-bottom: 1px solid #000;
}
.aspects-table td { padding: 3px 8px 3px 0; border-bottom: 1px solid #e5e5e5; }
.aspects-table tr:last-child td { border-bottom: none; }
.aspect-type-con { color: #111; }
.aspect-type-opp { color: #dc2626; }
.aspect-type-tri { color: #16a34a; }
.aspect-type-squ { color: #d97706; }
.aspect-type-sex { color: #2563eb; }
.aspect-type-qui { color: #7c3aed; }
.aspect-type-default { color: #555; }

/* ---------------------------------------------------------------
 * Elements bar
 * ------------------------------------------------------------- */
.element-row { margin-bottom: 10px; }
.element-label {
  display: flex;
  justify-content: space-between;
  font-size: 0.8125rem;
  margin-bottom: 3px;
}
.element-bar-track {
  height: 6px;
  background: #e5e5e5;
  border-radius: 3px;
  overflow: hidden;
}
.element-bar-fill {
  height: 100%;
  border-radius: 3px;
}
.element-fire  { background: #ef4444; }
.element-earth { background: #78716c; }
.element-air   { background: #60a5fa; }
.element-water { background: #6366f1; }

/* ---------------------------------------------------------------
 * Chart Patterns / Stelliums
 * ------------------------------------------------------------- */
.patterns-section { margin-bottom: 32px; padding-top: 8px; border-top: 1px solid #e5e5e5; }
.patterns-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 10px; }
.pattern-card {
  border: 1px solid #000;
  padding: 12px 16px;
  min-width: 160px;
  flex: 0 0 auto;
  max-width: 280px;
}
.pattern-card-title { font-weight: 700; font-size: 0.9375rem; margin-bottom: 2px; }
.pattern-card-sub { font-size: 0.8125rem; color: #555; margin-bottom: 4px; }
.pattern-card-desc { font-size: 0.8125rem; color: #444; line-height: 1.5; margin-bottom: 4px; }
.pattern-card-planets { font-size: 0.8125rem; font-style: italic; color: #333; }

/* ---------------------------------------------------------------
 * Page summary nav (inside sidebar)
 * ------------------------------------------------------------- */
.page-summary {
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid #e5e5e5;
}
.page-summary-title { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #555; margin-bottom: 8px; }
.page-summary a {
  display: block;
  font-size: 0.8125rem;
  padding: 3px 0;
  color: #555;
  text-decoration: none;
  border-left: 2px solid transparent;
  padding-left: 8px;
}
.page-summary a:hover { color: #000; border-left-color: #000; }

/* ---------------------------------------------------------------
 * Print
 * ------------------------------------------------------------- */
@media print {
  .main-toolbar, .page-summary, .btn-border { display: none !important; }
  .sidebar-col { position: static; max-height: none; overflow: visible; }
  .report-grid { grid-template-columns: 2fr 1fr; }
}
</style>
</head>
<body>
<div class="container">

  <!-- HEADER -->
  <div class="report-header">
    <h1 class="report-title">Natal Chart Report</h1>
    <p class="report-subtitle">Generated from FreeAstroAPI data.</p>
    <a href="<?php echo esc_url( home_url( '/my-western-astrology/' ) ); ?>" class="btn-border">New report</a>
  </div>

  <hr class="divider">

  <!-- REPORT GRID -->
  <div class="report-grid">

    <!-- ============================================================
         MAIN COLUMN
    ============================================================ -->
    <div class="main-col">

      <!-- Toolbar: Export PDF -->
      <div class="main-toolbar">
        <button class="btn-border" onclick="window.print()">Export PDF</button>
      </div>

      <!-- Natal Wheel SVG -->
      <div id="natal-wheel" style="margin-bottom:32px;">
        <h2 class="section-heading">Natal Wheel</h2>
        <?php if ( $svg_data ) : ?>
        <div class="natal-wheel">
          <?php echo wp_kses( $svg_data, self::allowed_svg_tags() ); ?>
        </div>
        <?php elseif ( $chart_img_url !== '' ) : ?>
        <div class="natal-wheel">
          <img src="<?php echo esc_url( $chart_img_url ); ?>" alt="Natal Chart Wheel" style="width:100%;max-width:600px;display:block;margin:0 auto;" loading="lazy" />
        </div>
        <?php else : ?>
        <p style="color:#888;font-size:0.875rem;">Không thể tải biểu đồ chiêm tinh.</p>
        <?php endif; ?>
      </div>

      <!-- ASC / 1st House section -->
      <?php
      $asc_detail = isset( $angles_d['asc'] ) && is_array( $angles_d['asc'] ) ? $angles_d['asc'] : null;
      if ( $asc_detail ) {
        self::render_angle_section( 'asc', $asc_detail, $natal_data );
      }
      ?>

      <!-- MC / 10th House section -->
      <?php
      $mc_detail = isset( $angles_d['mc'] ) && is_array( $angles_d['mc'] ) ? $angles_d['mc'] : null;
      if ( $mc_detail ) {
        self::render_angle_section( 'mc', $mc_detail, $natal_data );
      }
      ?>

      <!-- All Aspect Dynamics -->
      <?php if ( $aspects ) : ?>
      <div id="aspects" class="planet-section">
        <h2 class="section-heading">All Aspect Dynamics</h2>
        <table class="aspects-table">
          <thead>
            <tr>
              <th>Planet 1</th>
              <th>Planet 2</th>
              <th>Type</th>
              <th>Orb</th>
              <th>Deg</th>
              <th>Major</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $aspects as $asp ) :
              $p1       = isset( $asp['p1_name'] ) ? $asp['p1_name'] : ( isset( $asp['p1'] ) ? $asp['p1'] : '' );
              $p2       = isset( $asp['p2_name'] ) ? $asp['p2_name'] : ( isset( $asp['p2'] ) ? $asp['p2'] : '' );
              $type     = isset( $asp['type'] ) ? $asp['type'] : '';
              $orb      = isset( $asp['orb'] ) ? round( (float) $asp['orb'], 2 ) : '';
              $deg      = isset( $asp['deg'] ) ? $asp['deg'] : '';
              $is_major = ! empty( $asp['is_major'] ) ? '★' : '';
              $type_cls = self::aspect_class( $type );
            ?>
            <tr>
              <td><?php echo esc_html( $p1 ); ?></td>
              <td><?php echo esc_html( $p2 ); ?></td>
              <td class="<?php echo esc_attr( $type_cls ); ?>"><?php echo esc_html( $type ); ?></td>
              <td><?php echo esc_html( $orb ); ?>°</td>
              <td><?php echo esc_html( $deg ); ?></td>
              <td><?php echo esc_html( $is_major ); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

			<!-- Planet sections (Sun, Moon, Mercury, Venus, Mars, Jupiter, Saturn…) -->
			<?php
			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — place All Aspect Dynamics
			// before per-planet interpretations as requested.
			$core_planet_ids = array( 'sun', 'moon', 'mercury', 'venus', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto' );
			foreach ( $core_planet_ids as $planet_id ) {
				$planet_obj = self::find_planet( $planets, $planet_id );
				if ( ! $planet_obj ) { continue; }
				self::render_planet_section( $planet_obj, $natal_data );
			}
			?>

      <!-- Chart Patterns & Stelliums -->
      <?php if ( $stelliums ) { self::render_patterns_section( $stelliums ); } ?>

      <!-- Thematic Interpretation sections -->
      <?php
      if ( $interp ) {
        self::render_interpretation_sections( $interp );
      }
      ?>

		<?php
		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT — show extended English
		// chapters (markdown text) below the standard interpretation blocks.
		if ( ! empty( $llm_sections ) ) {
			self::render_llm_longform_sections( $llm_sections );
		}
		?>

    </div><!-- /.main-col -->

    <!-- ============================================================
         SIDEBAR
    ============================================================ -->
    <div class="sidebar-col">

      <!-- Subject info -->
      <div class="sidebar-block">
        <h2 class="section-heading-sm" style="font-size:1.1rem;border-bottom:1px solid #000;padding-bottom:6px;margin-bottom:10px;">
          <?php echo esc_html( $name ? $name : 'Natal Chart' ); ?>
        </h2>
        <?php self::render_subject_info( $subject, $payload ); ?>
      </div>

      <hr class="divider" style="margin:12px 0;">

      <!-- Planet positions -->
      <div class="sidebar-block">
        <p class="subsection-label">Planet positions</p>
        <?php self::render_planet_table( $planets, $houses, false ); ?>
      </div>

      <?php
      // Objects: true_node, mean_node, lilith, chiron
      $object_ids = array( 'true_node', 'mean_node', 'lilith', 'mean_lilith', 'chiron' );
      $objects    = array();
      foreach ( $object_ids as $oid ) {
        $obj = self::find_planet( $planets, $oid );
        if ( $obj ) { $objects[] = $obj; }
      }
      if ( $objects ) :
      ?>
      <div class="sidebar-block">
        <p class="subsection-label">Objects</p>
        <?php self::render_planet_table( $objects, $houses, true ); ?>
      </div>
      <?php endif; ?>

      <!-- Angles -->
      <div class="sidebar-block">
        <p class="subsection-label">Angles</p>
        <table class="planet-table">
          <thead>
            <tr>
              <th class="glyph-col"><span class="glyph">1</span></th>
              <th>Name</th>
              <th>Position</th>
            </tr>
          </thead>
          <tbody>
            <?php
            foreach ( array( 'asc' => 'Ascendant', 'mc' => 'Midheaven' ) as $key => $label ) {
              $det = isset( $angles_d[ $key ] ) && is_array( $angles_d[ $key ] ) ? $angles_d[ $key ] : null;
              if ( ! $det ) { continue; }
              $sign_name = isset( $det['sign'] ) ? $det['sign'] : '';
              $pos       = isset( $det['position'] ) ? self::format_dms( $det['position'] ) : '';
              $glyph     = strtolower( $key ) === 'asc' ? '1' : '3';
              echo '<tr>';
              echo '<td class="glyph-col"><span class="glyph">' . esc_html( $glyph ) . '</span></td>';
              echo '<td>' . esc_html( $label ) . '</td>';
              echo '<td>' . esc_html( $sign_name . ' ' . $pos ) . '</td>';
              echo '</tr>';
            }
            ?>
          </tbody>
        </table>
      </div>

      <!-- Houses -->
      <div class="sidebar-block">
        <p class="subsection-label">Houses (<?php echo esc_html( ucfirst( isset( $payload['house_system'] ) ? $payload['house_system'] : 'Placidus' ) ); ?>)</p>
        <?php self::render_houses_table( $houses ); ?>
      </div>

      <!-- Elements / Dominants -->
      <?php if ( $dominants && is_array( $dominants ) ) : ?>
      <div class="sidebar-block">
        <p class="subsection-label">Elements</p>
        <?php self::render_elements( $dominants ); ?>
      </div>
      <?php endif; ?>

      <!-- Page Summary / TOC -->
      <div class="page-summary">
        <p class="page-summary-title">Page Summary</p>
        <a href="#natal-wheel">Natal Wheel</a>
        <?php if ( $asc_detail ) : ?>
        <a href="#section-asc">1st House — Ascendant</a>
        <?php endif; ?>
        <?php if ( $mc_detail ) : ?>
        <a href="#section-mc">10th House — Midheaven</a>
        <?php endif; ?>
				<?php if ( $aspects ) : ?>
				<a href="#aspects">All Aspects</a>
				<?php endif; ?>
        <?php foreach ( $core_planet_ids as $pid ) :
          $po = self::find_planet( $planets, $pid );
          if ( ! $po ) { continue; }
          $pname = isset( $po['name'] ) ? $po['name'] : ucfirst( $pid );
        ?>
        <a href="#section-planet-<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $pname ); ?></a>
        <?php endforeach; ?>
        <?php if ( $stelliums ) : ?>
        <a href="#patterns">Chart Patterns</a>
        <?php endif; ?>
        <?php if ( $interp ) : ?>
        <a href="#interpretation">Thematic Interpretation</a>
        <?php endif; ?>
      </div>

    </div><!-- /.sidebar-col -->
  </div><!-- /.report-grid -->
</div><!-- /.container -->
</body>
</html>
<?php
	}

	/* ================================================================
	 * Render helpers
	 * ============================================================== */

	/**
	 * @param array $subject
	 * @param array $payload
	 */
	private static function render_subject_info( $subject, $payload ) {
		$rows = array();

		// Date
		$year  = isset( $payload['year'] )   ? (int) $payload['year']   : 0;
		$month = isset( $payload['month'] )  ? (int) $payload['month']  : 0;
		$day   = isset( $payload['day'] )    ? (int) $payload['day']    : 0;
		if ( $year && $month && $day ) {
			$rows['Date'] = sprintf( '%02d/%02d/%d', $day, $month, $year );
		}

		$hour   = isset( $payload['hour'] )   ? (int) $payload['hour']   : 0;
		$minute = isset( $payload['minute'] ) ? (int) $payload['minute'] : 0;
		$rows['Time'] = sprintf( '%02d:%02d', $hour, $minute );

		$tz = isset( $payload['tz_str'] ) ? $payload['tz_str'] : '';
		if ( $tz ) { $rows['Timezone'] = $tz; }

		$city = isset( $payload['city'] ) ? $payload['city'] : '';
		if ( $city ) { $rows['Location'] = $city; }

		$lat = isset( $payload['lat'] ) ? $payload['lat'] : null;
		$lng = isset( $payload['lng'] ) ? $payload['lng'] : null;
		if ( $lat !== null && $lng !== null ) {
			$rows['Coordinates'] = number_format( (float) $lat, 4 ) . ', ' . number_format( (float) $lng, 4 );
		}

		$hs = isset( $payload['house_system'] ) ? ucfirst( $payload['house_system'] ) : 'Placidus';
		$rows['System'] = $hs;

		$zt = isset( $payload['zodiac_type'] ) ? ucfirst( $payload['zodiac_type'] ) : 'Tropical';
		$rows['Zodiac'] = $zt;

		if ( isset( $subject['utc_time'] ) ) {
			$rows['UTC'] = esc_html( (string) $subject['utc_time'] );
		}
		if ( isset( $subject['julian_day'] ) ) {
			$rows['Julian day'] = number_format( (float) $subject['julian_day'], 4 );
		}

		echo '<table class="info-table">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</table>';
	}

	/**
	 * @param array $planets
	 * @param array $houses
	 * @param bool  $is_objects  true = objects block (no fixed core filter)
	 */
	private static function render_planet_table( $planets, $houses, $is_objects ) {
		$exclude_if_core = array( 'true_node', 'mean_node', 'lilith', 'mean_lilith', 'chiron' );

		echo '<table class="planet-table">';
		echo '<thead><tr>';
		echo '<th class="glyph-col"></th>';
		echo '<th>Name</th>';
		echo '<th>Sign / Pos</th>';
		echo '<th>House</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $planets as $p ) {
			$pid = isset( $p['id'] ) ? (string) $p['id'] : '';

			if ( $is_objects ) {
				if ( ! in_array( $pid, array( 'true_node', 'mean_node', 'lilith', 'mean_lilith', 'chiron' ), true ) ) {
					continue;
				}
			} else {
				if ( in_array( $pid, $exclude_if_core, true ) ) {
					continue;
				}
			}

			$glyph    = self::planet_glyph( $pid );
			$pname    = isset( $p['name'] ) ? (string) $p['name'] : ucfirst( $pid );
			$sign     = isset( $p['sign'] ) ? (string) $p['sign'] : '';
			$pos      = isset( $p['pos'] )  ? self::format_dms( $p['pos'] ) : '';
			$house    = isset( $p['house'] ) ? (int) $p['house'] : 0;
			$retro    = ! empty( $p['retrograde'] );

			echo '<tr>';
			echo '<td class="glyph-col"><span class="glyph">' . esc_html( $glyph ) . '</span></td>';
			echo '<td>' . esc_html( $pname ) . ( $retro ? ' <sup class="planet-retro">R</sup>' : '' ) . '</td>';
			echo '<td>' . esc_html( $sign . ' ' . $pos ) . '</td>';
			echo '<td>' . ( $house ? esc_html( (string) $house ) : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array $houses
	 */
	private static function render_houses_table( $houses ) {
		if ( ! $houses ) { return; }

		echo '<table class="planet-table">';
		echo '<thead><tr><th>House</th><th>Sign</th><th>Pos</th></tr></thead>';
		echo '<tbody>';

		foreach ( $houses as $h ) {
			$num  = isset( $h['house'] ) ? (int) $h['house'] : 0;
			$sign = isset( $h['sign'] )  ? (string) $h['sign'] : '';
			$pos  = isset( $h['pos'] )   ? self::format_dms( $h['pos'] ) : '';
			echo '<tr>';
			echo '<td>' . esc_html( (string) $num ) . '</td>';
			echo '<td>' . esc_html( $sign ) . '</td>';
			echo '<td>' . esc_html( $pos ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array $dominants
	 */
	private static function render_elements( $dominants ) {
		$elements = array(
			'fire'  => array( 'label' => 'Fire',  'class' => 'element-fire',  'value' => 0.0 ),
			'earth' => array( 'label' => 'Earth', 'class' => 'element-earth', 'value' => 0.0 ),
			'air'   => array( 'label' => 'Air',   'class' => 'element-air',   'value' => 0.0 ),
			'water' => array( 'label' => 'Water', 'class' => 'element-water', 'value' => 0.0 ),
		);

		$elem_src = null;
		if ( isset( $dominants['elements'] ) && is_array( $dominants['elements'] ) ) {
			$elem_src = $dominants['elements'];
		} elseif ( isset( $dominants['element'] ) && is_array( $dominants['element'] ) ) {
			$elem_src = $dominants['element'];
		}

		if ( $elem_src ) {
			foreach ( $elem_src as $key => $val ) {
				$k = strtolower( (string) $key );
				if ( isset( $elements[ $k ] ) ) {
					$elements[ $k ]['value'] = round( (float) $val, 1 );
				}
			}
		}

		$total = 0.0;
		foreach ( $elements as $e ) { $total += $e['value']; }
		if ( $total < 0.001 ) { $total = 1.0; }

		foreach ( $elements as $elem ) {
			$pct = round( $elem['value'] / $total * 100 );
			echo '<div class="element-row">';
			echo '<div class="element-label"><span>' . esc_html( $elem['label'] ) . '</span><span>' . esc_html( (string) $pct ) . '%</span></div>';
			echo '<div class="element-bar-track"><div class="element-bar-fill ' . esc_attr( $elem['class'] ) . '" style="width:' . esc_attr( (string) $pct ) . '%"></div></div>';
			echo '</div>';
		}
	}

	/**
	 * Render an angle detail section (ASC / MC).
	 * @param string $key        'asc'|'mc'
	 * @param array  $detail
	 * @param array  $natal_data Full natal chart response
	 */
	private static function render_angle_section( $key, $detail, $natal_data ) {
		$id = 'section-' . esc_attr( $key );

		if ( $key === 'asc' ) {
			$house_num  = '1st';
			$house_name = 'Ascendant (ASC)';
			$house_desc = 'Physical personality';
			$glyph      = '1';
		} else {
			$house_num  = '10th';
			$house_name = 'Midheaven (MC)';
			$house_desc = 'Career &amp; public life';
			$glyph      = '3';
		}

		$sign     = isset( $detail['sign'] ) ? (string) $detail['sign'] : '';
		$sign_gl  = self::sign_glyph( $sign );
		$position = isset( $detail['position'] ) ? self::format_dms( $detail['position'] ) : '';

		$ruler_id   = isset( $detail['ruler'] ) ? strtolower( (string) $detail['ruler'] ) : '';
		$ruler_name = isset( $detail['ruler_name'] ) ? (string) $detail['ruler_name'] : ucfirst( $ruler_id );

		echo '<div id="' . esc_attr( $id ) . '" class="planet-section">';
		echo '<div class="planet-section-header">';
		echo '<div class="planet-section-icon"><span class="glyph">' . esc_html( $glyph ) . '</span></div>';
		echo '<div>';
		echo '<div class="planet-section-title">' . esc_html( $house_num ) . ' House — ' . esc_html( $house_name ) . '</div>';
		echo '<div class="planet-section-subtitle">' . esc_html( $house_desc ) . '</div>';
		echo '</div>';
		echo '</div>';

		// Placement
		echo '<p class="subsection-label">Placement</p>';
		echo '<p style="font-size:0.9375rem;line-height:1.6;">';
		echo esc_html( $house_name . ' in ' . $sign . ' ' . $position );
		if ( $sign_gl ) {
			echo ' <span class="glyph">' . esc_html( $sign_gl ) . '</span>';
		}
		echo '</p>';

		// Ruler
		if ( $ruler_name ) {
			echo '<p class="subsection-label">Ruler</p>';
			$ruler_gl = self::planet_glyph( $ruler_id );
			echo '<p style="font-size:0.9375rem;line-height:1.6;">' . esc_html( $ruler_name );
			if ( $ruler_gl ) {
				echo ' <span class="glyph">' . esc_html( $ruler_gl ) . '</span>';
			}
			echo '</p>';
		}

		// Ruler placement in house / sign
		$ruler_house = '';
		$ruler_sign  = '';
		if ( $ruler_id && isset( $natal_data['planets'] ) && is_array( $natal_data['planets'] ) ) {
			$rplanet = self::find_planet( $natal_data['planets'], $ruler_id );
			if ( $rplanet ) {
				$ruler_house = isset( $rplanet['house'] ) ? (int) $rplanet['house'] : '';
				$ruler_sign  = isset( $rplanet['sign'] )  ? (string) $rplanet['sign'] : '';
			}
		}
		if ( $ruler_sign || $ruler_house ) {
			echo '<p class="subsection-label">Ruler placement</p>';
			echo '<p style="font-size:0.9375rem;line-height:1.6;">';
			echo esc_html( $ruler_name . ' in ' . $ruler_sign . ( $ruler_house ? ', House ' . $ruler_house : '' ) );
			echo '</p>';
		}

		// Interpretation text from angles_details
		if ( isset( $detail['interpretation'] ) && $detail['interpretation'] ) {
			echo '<p class="subsection-label">Interpretation</p>';
			$interp_text = is_array( $detail['interpretation'] ) ? implode( "\n\n", $detail['interpretation'] ) : (string) $detail['interpretation'];
			$paragraphs  = explode( "\n\n", $interp_text );
			foreach ( $paragraphs as $para ) {
				$para = trim( $para );
				if ( $para ) {
					echo '<p style="font-size:0.9375rem;line-height:1.6;margin-bottom:8px;">' . esc_html( $para ) . '</p>';
				}
			}
		}

		echo '</div>'; // /.planet-section
	}

	/**
	 * Render a planet's interpretation section.
	 * [2026-06-10 Johnny Chu] PHASE-NATAL-REPORT — rewritten to use atoms from interpretation.sections
	 * matching open-chart-main parseInterpretationAtoms / getAtomBody pattern.
	 * @param array $planet     NatalPlanet object
	 * @param array $natal_data Full chart response (for ruler placement lookup)
	 */
	private static function render_planet_section( $planet, $natal_data ) {
		$pid    = isset( $planet['id'] )   ? (string) $planet['id']   : '';
		$pname  = isset( $planet['name'] ) ? (string) $planet['name'] : ucfirst( $pid );
		$sign   = isset( $planet['sign'] ) ? (string) $planet['sign'] : '';
		$house  = isset( $planet['house'] ) ? (int) $planet['house'] : 0;
		$pos    = isset( $planet['pos'] )  ? self::format_dms( $planet['pos'] ) : '';
		$retro  = ! empty( $planet['retrograde'] );
		$glyph  = self::planet_glyph( $pid );
		$sg     = self::sign_glyph( $sign );

		$id = 'section-planet-' . esc_attr( $pid );

		echo '<div id="' . esc_attr( $id ) . '" class="planet-section">';
		echo '<div class="planet-section-header">';
		echo '<div class="planet-section-icon"><span class="glyph">' . esc_html( $glyph ) . '</span></div>';
		echo '<div>';
		echo '<div class="planet-section-title">' . esc_html( $pname . ' in ' . $sign ) . ( $sg ? ' <span class="glyph">' . esc_html( $sg ) . '</span>' : '' ) . '</div>';
		echo '<div class="planet-section-subtitle">' . esc_html( $pos . ( $house ? ' · House ' . $house : '' ) . ( $retro ? ' · Retrograde' : '' ) ) . '</div>';
		echo '</div>';
		echo '</div>';

		// Parse interpretation atoms from interpretation.sections (FAA API structure)
		// Same pattern as open-chart parseInterpretationAtoms / getAtomBody.
		$interp_raw = isset( $natal_data['interpretation'] ) ? $natal_data['interpretation'] : null;
		$atoms      = self::parse_interpretation_atoms( $interp_raw );
		$has_any_detail = false;

		if ( $atoms ) {
			// "Planet in Sign" atom
			$sign_atom = self::find_atom( $atoms, $pid, $pname, 'planet_sign' );
			if ( $sign_atom ) {
				$has_any_detail = true;
				echo '<p class="subsection-label">In ' . esc_html( $sign ) . '</p>';
				self::render_atom_body( $sign_atom['body'] );
			}

			// "Planet in House" atom
			if ( $house ) {
				$house_atom = self::find_atom( $atoms, $pid, $pname, 'planet_house' );
				if ( $house_atom ) {
					$has_any_detail = true;
					echo '<p class="subsection-label">In House ' . esc_html( (string) $house ) . '</p>';
					self::render_atom_body( $house_atom['body'] );
				}
			}

			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — fallback for feeds that
			// don't emit strict planet_sign/planet_house atoms; keep detailed text per planet.
			$related_atoms = self::find_related_planet_atoms( $atoms, $pid, $pname, 2 );
			if ( ! empty( $related_atoms ) ) {
				$has_any_detail = true;
				echo '<p class="subsection-label">Detailed interpretation</p>';
				foreach ( $related_atoms as $ra ) {
					self::render_atom_body( $ra['body'] );
				}
			}
		}

		if ( ! $has_any_detail ) {
			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — never leave a planet block
			// empty; show concise fallback synthesis from sign/house placement.
			echo '<p class="subsection-label">Interpretation</p>';
			echo '<p style="font-size:0.9375rem;line-height:1.6;margin-bottom:8px;">'
				. esc_html( $pname . ' in ' . $sign . ( $house ? ' (House ' . $house . ')' : '' )
				. ' highlights how this planetary function naturally expresses itself in your chart. Focus on recurring patterns in this life area to unlock its strengths and integrate its challenges.' )
				. '</p>';
		}

		echo '</div>'; // /.planet-section
	}

	/**
	 * Render Thematic Interpretation sections.
	 * @param mixed $interp
	 */
	private static function render_interpretation_sections( $interp ) {
		if ( ! is_array( $interp ) ) { return; }

		$sections_raw = isset( $interp['sections'] ) ? $interp['sections'] : $interp;
		if ( ! is_array( $sections_raw ) ) { return; }

		$section_order = array(
			'core_self'        => 'Personality &amp; Core Self',
			'mind'             => 'Mind &amp; Emotional Patterns',
			'love_relating'    => 'Love &amp; Relationships',
			'work_path'        => 'Career &amp; Direction',
			'social_collective'=> 'Social Themes',
			'karmic_healing'   => 'Healing &amp; Growth Themes',
		);

		$has_sections = false;
		foreach ( $section_order as $key => $title ) {
			if ( empty( $sections_raw[ $key ] ) ) { continue; }
			if ( ! is_array( $sections_raw[ $key ] ) ) { continue; }
			$items = $sections_raw[ $key ];
			if ( ! $items ) { continue; }
			if ( ! $has_sections ) {
				echo '<div id="interpretation">';
				echo '<h2 class="section-heading" style="margin-top:32px;">Thematic Interpretation</h2>';
				$has_sections = true;
			}
			echo '<div class="interp-section">';
			echo '<h3 class="interp-heading">' . wp_kses_post( $title ) . '</h3>';
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$ititle = isset( $item['title'] ) ? (string) $item['title'] : '';
				$ibody  = isset( $item['body'] )  ? (string) $item['body']  : '';
				$itags  = isset( $item['tags'] )  && is_array( $item['tags'] ) ? $item['tags'] : array();
				if ( ! $ibody ) { continue; }
				echo '<div class="interp-item">';
				if ( $ititle ) {
					echo '<p class="interp-item-title">' . esc_html( $ititle ) . '</p>';
				}
				// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — render interpretation body as rich HTML
				// (markdown -> html) instead of escaped plaintext to avoid visible ** and excessive <br> gaps.
				self::render_rich_body( $ibody, 'interp-item-body' );
				if ( $itags ) {
					echo '<div class="interp-tags">';
					foreach ( $itags as $tag ) {
						echo '<span class="interp-tag">' . esc_html( (string) $tag ) . '</span>';
					}
					echo '</div>';
				}
				echo '</div>'; // /.interp-item
			}
			echo '</div>'; // /.interp-section
		}

		if ( $has_sections ) {
			echo '</div>'; // /#interpretation
		}
	}

	/* ================================================================
	 * Interpretation atom helpers (mirrors open-chart parseInterpretationAtoms + getAtomBody)
	 * [2026-06-10 Johnny Chu] PHASE-NATAL-REPORT
	 * ============================================================== */

	/**
	 * Flatten all atoms from interpretation.sections.
	 * Each atom: [ 'key'=>string, 'category'=>string, 'title'=>string, 'body'=>string ]
	 * @param  mixed $interpretation
	 * @return array  flat list of atom arrays
	 */
	private static function parse_interpretation_atoms( $interpretation ) {
		if ( ! is_array( $interpretation ) ) { return array(); }
		$sections = isset( $interpretation['sections'] ) ? $interpretation['sections'] : array();
		if ( ! is_array( $sections ) ) { return array(); }

		$atoms = array();
		foreach ( $sections as $entries ) {
			if ( ! is_array( $entries ) ) { continue; }
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) { continue; }
				$title = isset( $entry['title'] ) ? trim( (string) $entry['title'] ) : '';
				$body  = isset( $entry['body'] )  ? trim( (string) $entry['body'] )  : '';
				if ( ! $title || ! $body ) { continue; }
				$atoms[] = array(
					'key'      => isset( $entry['key'] )      ? (string) $entry['key']      : '',
					'category' => isset( $entry['category'] ) ? (string) $entry['category'] : '',
					'title'    => $title,
					'body'     => $body,
				);
			}
		}
		return $atoms;
	}

	/**
	 * Find a single atom for a planet by category (planet_sign | planet_house).
	 * Matches on key pattern `planet.{id}.{sign|house}.*` OR title prefix `{name} in ...`.
	 * @param  array  $atoms    from parse_interpretation_atoms()
	 * @param  string $id       planet id e.g. 'sun'
	 * @param  string $pname    planet name e.g. 'Sun'
	 * @param  string $category 'planet_sign' or 'planet_house'
	 * @return array|null
	 */
	private static function find_atom( $atoms, $id, $pname, $category ) {
		$id_lower    = strtolower( $id );
		$name_lower  = strtolower( $pname );
		$house_title = $name_lower . ' in house';
		foreach ( $atoms as $atom ) {
			if ( $atom['category'] !== $category ) { continue; }
			$key   = strtolower( $atom['key'] );
			$title = strtolower( $atom['title'] );
			if ( $category === 'planet_sign' ) {
				if ( false !== strpos( $key, 'planet.' . $id_lower . '.sign' ) ) { return $atom; }
				if ( 0 === strpos( $title, $name_lower . ' in ' ) && false === strpos( $title, 'house' ) ) { return $atom; }
			} elseif ( $category === 'planet_house' ) {
				if ( false !== strpos( $key, 'planet.' . $id_lower . '.house' ) ) { return $atom; }
				if ( 0 === strpos( $title, $house_title ) ) { return $atom; }
			}
		}
		return null;
	}

	/**
	 * Find additional interpretation atoms related to a planet when strict sign/house
	 * categories are missing from provider output.
	 *
	 * @param array  $atoms
	 * @param string $id
	 * @param string $pname
	 * @param int    $limit
	 * @return array
	 */
	private static function find_related_planet_atoms( $atoms, $id, $pname, $limit = 2 ) {
		$id_lower   = strtolower( (string) $id );
		$name_lower = strtolower( (string) $pname );
		$found      = array();
		$seen       = array();

		foreach ( $atoms as $atom ) {
			if ( ! is_array( $atom ) ) { continue; }
			if ( empty( $atom['body'] ) ) { continue; }
			$cat = isset( $atom['category'] ) ? (string) $atom['category'] : '';
			if ( $cat === 'planet_sign' || $cat === 'planet_house' ) {
				continue;
			}

			$key   = strtolower( isset( $atom['key'] ) ? (string) $atom['key'] : '' );
			$title = strtolower( isset( $atom['title'] ) ? (string) $atom['title'] : '' );
			$body  = (string) $atom['body'];
			$body_l = strtolower( $body );

			$match = false;
			if ( $key !== '' && false !== strpos( $key, 'planet.' . $id_lower ) ) {
				$match = true;
			}
			if ( ! $match && $title !== '' && ( false !== strpos( $title, $name_lower ) || 0 === strpos( $title, $id_lower ) ) ) {
				$match = true;
			}
			if ( ! $match && $cat === 'aspect' && false !== strpos( $body_l, $name_lower ) ) {
				$match = true;
			}

			if ( ! $match ) {
				continue;
			}

			$hash = md5( $body );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$found[] = array(
				'key'      => isset( $atom['key'] ) ? (string) $atom['key'] : '',
				'category' => $cat,
				'title'    => isset( $atom['title'] ) ? (string) $atom['title'] : '',
				'body'     => $body,
			);

			if ( count( $found ) >= (int) $limit ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Render a body string as paragraphs.
	 * @param string $body
	 */
	private static function render_atom_body( $body ) {
		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — rich HTML render for atom bodies.
		self::render_rich_body( (string) $body, 'interp-item-body' );
	}

	/**
	 * Render markdown-like text as sanitized rich HTML.
	 *
	 * @param string $text
	 * @param string $css_class
	 * @return void
	 */
	private static function render_rich_body( $text, $css_class = 'interp-item-body' ) {
		$html = self::markdown_to_rich_html( (string) $text );
		if ( trim( $html ) === '' ) {
			return;
		}
		echo '<div class="' . esc_attr( $css_class ) . ' interp-rich">' . wp_kses_post( $html ) . '</div>';
	}

	/**
	 * Convert markdown-like text into HTML (fallback parser).
	 * Reuses bccm_llm_md_to_html when available.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function markdown_to_rich_html( $text ) {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			return '';
		}

		if ( function_exists( 'bccm_llm_md_to_html' ) ) {
			return (string) bccm_llm_md_to_html( $text );
		}

		$html = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$html = preg_replace( '/^---+$/m', '<hr class="ai-divider">', $html );
		$html = preg_replace( '/^####\s+(.+)$/m', '<h6 class="ai-h6">$1</h6>', $html );
		$html = preg_replace( '/^###\s+(.+)$/m',  '<h5 class="ai-h5">$1</h5>', $html );
		$html = preg_replace( '/^##\s+(.+)$/m',   '<h4 class="ai-h4">$1</h4>', $html );
		$html = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $html );
		$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $html );
		$html = preg_replace( '/^>\s+(.+)$/m', '<blockquote>$1</blockquote>', $html );

		$html = preg_replace_callback( '/(?:^-\s+.+\n?)+/m', function ( $m ) {
			$items = preg_replace( '/^-\s+(.+)$/m', '<li>$1</li>', trim( $m[0] ) );
			return "<ul>\n" . $items . "\n</ul>\n";
		}, $html );

		$html = preg_replace_callback( '/(?:^\d+\.\s+.+\n?)+/m', function ( $m ) {
			$items = preg_replace( '/^\d+\.\s+(.+)$/m', '<li>$1</li>', trim( $m[0] ) );
			return "<ol>\n" . $items . "\n</ol>\n";
		}, $html );

		$parts = preg_split( '/\n{2,}/', $html );
		$out   = '';
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' ) {
				continue;
			}
			if ( preg_match( '/^<(h[1-6]|ul|ol|blockquote|hr|div|table|figure|p)/', $part ) ) {
				$out .= $part . "\n";
			} else {
				$out .= '<p>' . nl2br( $part ) . "</p>\n";
			}
		}

		return $out;
	}

	/* ================================================================
	 * Patterns / Stelliums renderer
	 * [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX
	 * ============================================================== */

	/**
	 * Render chart patterns / stelliums section.
	 * Handles two shapes from FAA2 gateway:
	 *   Shape A: { sign:string, planets:string[], count:int } — stellium groupings
	 *   Shape B: { name:string, description:string, planets:string[] } — named patterns
	 *
	 * @param array $stelliums
	 */
	private static function render_patterns_section( array $stelliums ) {
		if ( empty( $stelliums ) ) { return; }

		echo '<div id="patterns" class="patterns-section">';
		echo '<h2 class="section-heading">Chart Patterns</h2>';
		echo '<div class="patterns-grid">';

		foreach ( $stelliums as $s ) {
			if ( ! is_array( $s ) ) { continue; }

			if ( isset( $s['sign'] ) ) {
				// Shape A: stellium by sign.
				$title   = 'Stellium in ' . esc_html( (string) $s['sign'] );
				$sub     = isset( $s['count'] ) ? ( (int) $s['count'] . ' planets' ) : '';
				$desc    = '';
				$planets = isset( $s['planets'] ) && is_array( $s['planets'] )
				           ? implode( ', ', array_map( 'esc_html', $s['planets'] ) ) : '';
			} elseif ( isset( $s['name'] ) ) {
				// Shape B: named chart pattern.
				$title   = esc_html( (string) $s['name'] );
				$sub     = '';
				$desc    = isset( $s['description'] ) ? esc_html( (string) $s['description'] ) : '';
				$planets = isset( $s['planets'] ) && is_array( $s['planets'] )
				           ? implode( ', ', array_map( 'esc_html', $s['planets'] ) ) : '';
			} else {
				continue;
			}

			echo '<div class="pattern-card">';
			echo '<div class="pattern-card-title">' . $title . '</div>';
			if ( $sub !== '' ) {
				echo '<div class="pattern-card-sub">' . esc_html( $sub ) . '</div>';
			}
			if ( $desc !== '' ) {
				echo '<div class="pattern-card-desc">' . $desc . '</div>';
			}
			if ( $planets !== '' ) {
				echo '<div class="pattern-card-planets">' . $planets . '</div>';
			}
			echo '</div>';
		}

		echo '</div></div>';
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT — load cached llm_report sections
	 * for the target chart so public natal-report can render full EN long-form text.
	 *
	 * @param int    $coachee_id
	 * @param string $chart_type
	 * @return array
	 */
	private static function load_llm_report_sections( $coachee_id, $chart_type = 'western' ) {
		$coachee_id = (int) $coachee_id;
		$chart_type = sanitize_key( (string) $chart_type );
		if ( $coachee_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro';
		$raw = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT llm_report FROM {$table} WHERE coachee_id=%d AND chart_type=%s AND llm_report IS NOT NULL AND llm_report<>'' ORDER BY id DESC LIMIT 1",
				$coachee_id,
				$chart_type
			)
		);
		if ( $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['sections'] ) || ! is_array( $decoded['sections'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded['sections'] as $sec ) {
			if ( is_string( $sec ) && trim( $sec ) !== '' ) {
				$out[] = $sec;
			}
		}
		return $out;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT — render long-form LLM sections
	 * as rich HTML (markdown converted + sanitized) at the page bottom.
	 *
	 * @param array $sections
	 * @return void
	 */
	private static function render_llm_longform_sections( $sections ) {
		if ( empty( $sections ) || ! is_array( $sections ) ) {
			return;
		}

		echo '<div id="interpretation-longform" class="interp-section" style="margin-top:36px;">';
		echo '<h2 class="section-heading">Extended English Interpretation</h2>';
		foreach ( $sections as $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$txt = trim( $text );
			if ( $txt === '' ) {
				continue;
			}
			echo '<div class="interp-item" style="margin-bottom:14px;">';
			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — rich HTML render for long-form EN sections.
			self::render_rich_body( $txt, 'interp-item-body' );
			echo '</div>';
		}
		echo '</div>';
	}

	/* ================================================================
	 * Error page
	 * ============================================================== */

	/**
	 * @param string $message
	 * @param string $hint
	 */
	private static function render_error_page( $message, $hint = '' ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'HTTP/1.1 400 Bad Request' );
		?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lỗi — Natal Chart Report</title>
<style>
body{font-family:"Avenir Next",Avenir,"Helvetica Neue",sans-serif;background:#fff;color:#000;padding:60px 24px;max-width:600px;margin:0 auto;}
h1{font-family:"Iowan Old Style",Palatino,serif;font-size:1.5rem;font-weight:700;margin-bottom:12px;}
p{font-size:0.9375rem;line-height:1.6;color:#444;}
a{color:#000;border:1px solid #000;padding:6px 16px;text-decoration:none;display:inline-block;margin-top:16px;font-size:0.875rem;}
a:hover{background:#000;color:#fff;}
</style>
</head>
<body>
<h1>Không thể hiển thị báo cáo</h1>
<p><?php echo esc_html( $message ); ?></p>
<?php if ( $hint ) : ?>
<p><?php echo esc_html( $hint ); ?></p>
<?php endif; ?>
<a href="<?php echo esc_url( home_url( '/my-western-astrology/' ) ); ?>">← Quay lại trang chiêm tinh</a>
</body>
</html>
<?php
	}

	/* ================================================================
	 * Pure helpers
	 * ============================================================== */

	/**
	 * Convert decimal degrees to dms string: e.g. "14°22'W" or "14.37°"
	 * @param  mixed $deg
	 * @return string
	 */
	private static function format_dms( $deg ) {
		$deg    = (float) $deg;
		$d      = (int) $deg;
		$min_f  = ( $deg - $d ) * 60;
		$m      = (int) $min_f;
		return $d . '°' . sprintf( '%02d', $m ) . "'";
	}

	/**
	 * Planet ID → StarFont Sans char.
	 * @param  string $id
	 * @return string
	 */
	private static function planet_glyph( $id ) {
		$map = array(
			'sun'            => 's',
			'moon'           => 'a',
			'mercury'        => 'f',
			'venus'          => 'g',
			'mars'           => 'h',
			'jupiter'        => 'j',
			'saturn'         => 's',
			'uranus'         => 'F',
			'neptune'        => 'G',
			'pluto'          => 'J',
			'true_node'      => 'k',
			'mean_node'      => 'k',
			'north_node'     => 'k',
			'south_node'     => '?',
			'lilith'         => 'L',
			'black_moon_lilith' => 'L',
			'mean_lilith'    => 'L',
			'chiron'         => 'D',
			'ceres'          => 'C',
			'vesta'          => '_',
			'pallas'         => ':',
			'juno'           => ';',
			'fortune'        => 'L',
			'vertex'         => '!',
			'earth'          => 'E',
			'asc'            => '1',
			'ascendant'      => '1',
			'mc'             => '3',
			'midheaven'      => '3',
		);
		$k = strtolower( $id );
		return isset( $map[ $k ] ) ? $map[ $k ] : '?';
	}

	/**
	 * Sign name → StarFont Sans char.
	 * @param  string $sign
	 * @return string
	 */
	private static function sign_glyph( $sign ) {
		$map = array(
			'aries'       => 'x',
			'taurus'      => 'c',
			'gemini'      => 'v',
			'cancer'      => 'b',
			'leo'         => 'n',
			'virgo'       => 'm',
			'libra'       => 'X',
			'scorpio'     => 'C',
			'sagittarius' => 'V',
			'capricorn'   => 'B',
			'aquarius'    => 'N',
			'pisces'      => 'M',
			'ari'         => 'x',
			'tau'         => 'c',
			'gem'         => 'v',
			'can'         => 'b',
			'leo_short'   => 'n',
			'vir'         => 'm',
			'lib'         => 'X',
			'sco'         => 'C',
			'sag'         => 'V',
			'cap'         => 'B',
			'aqu'         => 'N',
			'pis'         => 'M',
		);
		$k = strtolower( $sign );
		return isset( $map[ $k ] ) ? $map[ $k ] : '';
	}

	/**
	 * Aspect type → CSS class suffix.
	 * @param  string $type
	 * @return string
	 */
	private static function aspect_class( $type ) {
		$t = strtolower( (string) $type );
		if ( $t === 'conjunction' )  { return 'aspect-type-con'; }
		if ( $t === 'opposition' )   { return 'aspect-type-opp'; }
		if ( $t === 'trine' )        { return 'aspect-type-tri'; }
		if ( $t === 'square' )       { return 'aspect-type-squ'; }
		if ( $t === 'sextile' )      { return 'aspect-type-sex'; }
		if ( $t === 'quintile' )     { return 'aspect-type-qui'; }
		return 'aspect-type-default';
	}

	/**
	 * Find a planet in the planets array by id.
	 * @param  array  $planets
	 * @param  string $id
	 * @return array|null
	 */
	private static function find_planet( $planets, $id ) {
		foreach ( $planets as $p ) {
			if ( isset( $p['id'] ) && strtolower( (string) $p['id'] ) === strtolower( $id ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Allowed SVG tags / attrs for wp_kses when embedding the natal wheel SVG.
	 * @return array
	 */
	private static function allowed_svg_tags() {
		$common_attrs = array(
			'id'         => array(),
			'class'      => array(),
			'style'      => array(),
			'transform'  => array(),
			'fill'       => array(),
			'stroke'     => array(),
			'stroke-width' => array(),
			'opacity'    => array(),
			'd'          => array(),
			'cx'         => array(),
			'cy'         => array(),
			'r'          => array(),
			'x'          => array(),
			'y'          => array(),
			'x1'         => array(),
			'y1'         => array(),
			'x2'         => array(),
			'y2'         => array(),
			'width'      => array(),
			'height'     => array(),
			'viewBox'    => array(),
			'xmlns'      => array(),
			'font-size'  => array(),
			'font-family'=> array(),
			'text-anchor'=> array(),
			'dominant-baseline' => array(),
		);
		return array(
			'svg'     => array_merge( $common_attrs, array( 'preserveAspectRatio' => array() ) ),
			'g'       => $common_attrs,
			'circle'  => $common_attrs,
			'ellipse' => $common_attrs,
			'line'    => $common_attrs,
			'path'    => $common_attrs,
			'polygon' => array_merge( $common_attrs, array( 'points' => array() ) ),
			'rect'    => array_merge( $common_attrs, array( 'rx' => array(), 'ry' => array() ) ),
			'text'    => $common_attrs,
			'tspan'   => $common_attrs,
			'defs'    => array(),
			'use'     => array_merge( $common_attrs, array( 'href' => array(), 'xlink:href' => array() ) ),
			'symbol'  => $common_attrs,
			'clipPath'=> $common_attrs,
			'mask'    => $common_attrs,
			'linearGradient' => array_merge( $common_attrs, array( 'gradientUnits' => array(), 'gradientTransform' => array(), 'x1' => array(), 'y1' => array(), 'x2' => array(), 'y2' => array() ) ),
			'radialGradient' => array_merge( $common_attrs, array( 'gradientUnits' => array(), 'cx' => array(), 'cy' => array(), 'r' => array(), 'fx' => array(), 'fy' => array() ) ),
			'stop'    => array_merge( $common_attrs, array( 'offset' => array(), 'stop-color' => array(), 'stop-opacity' => array() ) ),
			'title'   => array(),
			'desc'    => array(),
		);
	}
}
