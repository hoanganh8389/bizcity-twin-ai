<?php
/**
 * BizCoach Pro — Artifact Adapter (read facade over legacy bccm_* tables).
 *
 * Per user directive 2026-05-15: "ko đổi bảng, giữ nguyên bảng cũ, cấu trúc db cũ".
 * No new schema. This class is a thin read adapter mapping the artifact concept
 * (1 produced unit = 1 row in `bccm_coachees`) into structures the new Persona
 * Provider can serialize for guru ingest.
 *
 * Authoritative storage:
 *   - {prefix}bccm_coachees       — artifact instance (id, user_id, coach_type,
 *                                    full_name, dob, company_*, JSON columns
 *                                    ai_summary/vision_json/swot_json/...).
 *   - {prefix}bccm_gen_results    — generator outputs (1 row per gen_key per
 *                                    coachee), authoritative since legacy
 *                                    install.php@509 backfilled JSON columns.
 *   - {prefix}bccm_astro          — natal chart (Western/Vedic) from
 *                                    freeastrologyapi.com (lib/astro-api-free.php).
 *   - {prefix}bccm_transit_snapshots — pre-fetched transit data
 *                                      (lib/astro-transit.php prefetch loop).
 *
 * Writes are NOT done here in Sprint H — they remain in legacy paths
 * (`bccm_save_gen_result()`, `tool_create_natal_chart()`). Sprint K will
 * migrate writes after legacy plugin is archived.
 *
 * @since 0.1.0 (PHASE-0.36)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Artifact_Service' ) ) { return; }

class BizCoach_Pro_Artifact_Service {

	/**
	 * Get coachee row + all generator results for a given coachee_id.
	 *
	 * @param int $coachee_id
	 * @return array|null  ['id','user_id','coach_type','full_name','dob','title',
	 *                      'profile'=>raw row, 'gens'=>[gen_key=>['label','status','result']]]
	 */
	public static function get_artifact( $coachee_id ) {
		global $wpdb;
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) { return null; }

		// Cache layer (CACHE-STRATEGY.md §4) — key is single integer id, fully
		// invalidated by `bcpro/cache/invalidate` action on coachee/gens write.
		$producer = function () use ( $wpdb, $coachee_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}bccm_coachees WHERE id = %d LIMIT 1",
					$coachee_id
				),
				ARRAY_A
			);
			if ( ! $row ) { return null; }

			$gens = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT gen_key, gen_fn, gen_label, result_json, status, error_msg, created_at, updated_at
					 FROM {$wpdb->prefix}bccm_gen_results
					 WHERE coachee_id = %d
					 ORDER BY id ASC",
					$coachee_id
				),
				ARRAY_A
			);

			$gens_map = array();
			foreach ( (array) $gens as $g ) {
				$key = (string) $g['gen_key'];
				$gens_map[ $key ] = array(
					'label'      => (string) $g['gen_label'],
					'fn'         => (string) $g['gen_fn'],
					'status'     => (string) $g['status'],
					'error_msg'  => (string) $g['error_msg'],
					'result'     => self::decode_json( $g['result_json'] ),
					'result_raw' => (string) $g['result_json'],
					'created_at' => (string) $g['created_at'],
					'updated_at' => (string) $g['updated_at'],
				);
			}

			return array(
				'id'         => (int) $row['id'],
				'user_id'    => (int) $row['user_id'],
				'coach_type' => (string) $row['coach_type'],
				'full_name'  => (string) $row['full_name'],
				'dob'        => isset( $row['dob'] ) ? (string) $row['dob'] : '',
				'title'      => self::derive_title( $row ),
				'profile'    => $row,
				'gens'       => $gens_map,
			);
		};

		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			return BizCoach_Pro_Cache::remember(
				'bcpro_coachees',
				'id:' . $coachee_id,
				1800,
				$producer
			);
		}
		return $producer();
	}

	/**
	 * Get natal chart row for a coachee (joined with coachee context).
	 *
	 * @return array|null  ['chart_type','positions','summary','traits','llm_report','chart_svg']
	 */
	public static function get_natal_chart( $coachee_id, $chart_type = 'western' ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bccm_astro
				 WHERE coachee_id = %d AND chart_type = %s LIMIT 1",
				(int) $coachee_id, sanitize_key( $chart_type )
			),
			ARRAY_A
		);
		if ( ! $row ) { return null; }
		$row['summary_decoded']   = self::decode_json( $row['summary']    ?? '' );
		$row['traits_decoded']    = self::decode_json( $row['traits']     ?? '' );
		$row['positions_decoded'] = self::decode_json( $row['positions']  ?? '' );
		$row['llm_report_decoded'] = self::decode_json( $row['llm_report'] ?? '' );
		return $row;
	}

	/**
	 * Get transit snapshot for a coachee at target_date.
	 */
	public static function get_transit_snapshot( $coachee_id, $target_date ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bccm_transit_snapshots
				 WHERE coachee_id = %d AND target_date = %s LIMIT 1",
				(int) $coachee_id, sanitize_text_field( (string) $target_date )
			),
			ARRAY_A
		);
		if ( ! $row ) { return null; }
		$row['planets_decoded'] = self::decode_json( $row['planets_json'] ?? '' );
		$row['aspects_decoded'] = self::decode_json( $row['aspects_json'] ?? '' );
		return $row;
	}

	/**
	 * List artifacts for a user, newest first. coach_type optional filter.
	 *
	 * @return array of ['id','coach_type','full_name','dob','created_at','updated_at']
	 */
	public static function list_for_user( $user_id, $coach_type = '', $limit = 50 ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$limit   = max( 1, min( 200, (int) $limit ) );

		$producer = function () use ( $wpdb, $user_id, $coach_type, $limit ) {
			if ( $coach_type !== '' ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, coach_type, full_name, dob, created_at, updated_at
						 FROM {$wpdb->prefix}bccm_coachees
						 WHERE user_id = %d AND coach_type = %s
						 ORDER BY id DESC LIMIT %d",
						$user_id, sanitize_key( $coach_type ), $limit
					),
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, coach_type, full_name, dob, created_at, updated_at
						 FROM {$wpdb->prefix}bccm_coachees
						 WHERE user_id = %d
						 ORDER BY id DESC LIMIT %d",
						$user_id, $limit
					),
					ARRAY_A
				);
			}
			return is_array( $rows ) ? $rows : array();
		};

		// User-scoped index — uses version-stamp pattern so any write for this
		// user (which bumps the stamp) makes all variants of this key orphan.
		// See CACHE-STRATEGY.md §5 + class-cache.php::bump_user_version().
		if ( class_exists( 'BizCoach_Pro_Cache' ) && $user_id > 0 ) {
			$ver = BizCoach_Pro_Cache::get_user_version( $user_id );
			$key = sprintf( 'user:%d:v:%d:type:%s:lim:%d',
				$user_id, $ver,
				$coach_type !== '' ? sanitize_key( $coach_type ) : '_',
				$limit
			);
			return BizCoach_Pro_Cache::remember( 'bcpro_coachee_idx', $key, 600, $producer );
		}
		return $producer();
	}

	/**
	 * Count coachees of a given coach_type (any user).
	 * Used by parity matrix to surface "is anyone using this template?".
	 */
	public static function count_for_coach_type( $coach_type ) {
		global $wpdb;
		$coach_type = sanitize_key( (string) $coach_type );
		if ( $coach_type === '' ) { return 0; }
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bccm_coachees WHERE coach_type = %s",
				$coach_type
			)
		);
	}

	/**
	 * Latest coachee row for a given coach_type — newest first.
	 *
	 * @return array|null  ['id','user_id','full_name','dob','created_at']
	 */
	public static function latest_for_coach_type( $coach_type ) {
		global $wpdb;
		$coach_type = sanitize_key( (string) $coach_type );
		if ( $coach_type === '' ) { return null; }
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, full_name, dob, created_at, updated_at
				 FROM {$wpdb->prefix}bccm_coachees
				 WHERE coach_type = %s
				 ORDER BY id DESC LIMIT 1",
				$coach_type
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Latest coachee that has a non-empty public_key in `bccm_action_plans`.
	 * Used by parity matrix + REST list to surface a guaranteed-clickable
	 * preview URL even when the very latest coachee is mid-pipeline.
	 *
	 * @return array|null  ['id','user_id','full_name','public_key']
	 */
	public static function latest_published_for_coach_type( $coach_type ) {
		global $wpdb;
		$coach_type = sanitize_key( (string) $coach_type );
		if ( $coach_type === '' ) { return null; }
		$plans_tbl = $wpdb->prefix . 'bccm_action_plans';
		$has_tbl = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plans_tbl ) ) === $plans_tbl;
		if ( ! $has_tbl ) { return null; }
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.id, c.user_id, c.full_name, c.dob, c.created_at, c.updated_at, p.public_key
				 FROM {$wpdb->prefix}bccm_coachees c
				 INNER JOIN {$plans_tbl} p
				   ON p.coachee_id = c.id AND p.public_key <> '' AND p.status = 'active'
				 WHERE c.coach_type = %s
				 ORDER BY p.id DESC LIMIT 1",
				$coach_type
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Resolve the public preview URL for a coachee — reads
	 * `bccm_action_plans.public_key` (set by legacy generator pipeline) and
	 * builds the pretty permalink registered by
	 * `BizCoach_Pro_Coach_Builder::register_rewrite()`: /coach-builder/<key>/.
	 *
	 * (Replaces the legacy `/coachee-map/<key>/` permalink which was bound
	 * to the read-only template in bizcoach-map; the new /coach-builder/
	 * landing supports share / pin / print + per-section progressive load.)
	 *
	 * Returns '' when no plan / no public_key has been generated yet (this
	 * is the parity gap user needs visibility into).
	 */
	public static function get_public_url( $coachee_id ) {
		global $wpdb;
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) { return ''; }

		$tbl = $wpdb->prefix . 'bccm_action_plans';
		$has = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
		if ( ! $has ) { return ''; }

		$key = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT public_key FROM {$tbl} WHERE coachee_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				$coachee_id
			)
		);
		if ( $key === '' ) { return ''; }
		return home_url( 'coach-builder/' . rawurlencode( $key ) . '/' );
	}

	/**
	 * List recent coachees for a coach_type (any user). Each row decorated
	 * with `public_url` (empty string if generator hasn't published yet).
	 *
	 * @return array of ['id','user_id','full_name','dob','created_at','public_url']
	 */
	public static function list_for_coach_type( $coach_type, $limit = 5 ) {
		global $wpdb;
		$coach_type = sanitize_key( (string) $coach_type );
		if ( $coach_type === '' ) { return array(); }
		$limit = max( 1, min( 50, (int) $limit ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, full_name, dob, created_at, updated_at
				 FROM {$wpdb->prefix}bccm_coachees
				 WHERE coach_type = %s
				 ORDER BY id DESC LIMIT %d",
				$coach_type, $limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$r['public_url'] = self::get_public_url( (int) $r['id'] );
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Astro/Transit report URLs surfaced by legacy bizcoach-map handlers.
	 * Returns associative array of [label => url], empty if coachee has no
	 * astro row. Includes:
	 *   - natal_share : public hash URL /my-natal-chart/?id=&hash=  (no nonce, twinchat-friendly)
	 *   - natal_full  : admin-ajax bccm_natal_report_full (Western + Vedic)
	 *   - transit_*   : admin-ajax bccm_transit_report (week/month/year)
	 *
	 * NOTE: admin-ajax URLs are nonce-protected → only valid for the current
	 * logged-in admin viewing the diag page. The natal_share URL is the
	 * canonical artifact for twinchat ingest.
	 */
	public static function get_astro_report_urls( $coachee_id ) {
		global $wpdb;
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) { return array(); }

		// Confirm astro row exists.
		$has_astro = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bccm_astro WHERE coachee_id = %d",
			$coachee_id
		) );
		if ( $has_astro === 0 ) { return array(); }

		$out = array();

		if ( function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$out['natal_share'] = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		if ( is_admin() && function_exists( 'wp_create_nonce' ) ) {
			$natal_n   = wp_create_nonce( 'bccm_natal_report_full' );
			$transit_n = wp_create_nonce( 'bccm_transit_report' );
			$pdf_n     = wp_create_nonce( 'bccm_natal_pdf' );
			$prok_n    = wp_create_nonce( 'bccm_prokerala_natal_pdf' );
			$base = admin_url( 'admin-ajax.php' );
			// Sprint H.6 — prefer hash public URLs for sharable luận giải pages.
			$router_ok = class_exists( 'BizCoach_Pro_Astro_Public_Router' );
			$out['natal_full_western'] = $router_ok
				? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'western' )
				: $base . '?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . $natal_n;
			$out['natal_full_vedic']   = $router_ok
				? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic' )
				: $base . '?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . $natal_n;
			$out['natal_full_chinese'] = $router_ok
				? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'chinese' )
				: $base . '?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=chinese&_wpnonce=' . $natal_n;
			$out['transit_week']       = $base . '?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce='  . $transit_n;
			$out['transit_month']      = $base . '?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_n;
			$out['transit_year']       = $base . '?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce='  . $transit_n;
			$out['natal_pdf']          = $base . '?action=bccm_natal_pdf&coachee_id=' . $coachee_id . '&_wpnonce=' . $pdf_n;
			$out['prokerala_pdf']      = $base . '?action=bccm_prokerala_natal_pdf&coachee_id=' . $coachee_id . '&_wpnonce=' . $prok_n;
		}

		return $out;
	}

	/**
	 * Per-template artifact URL bundle for a coachee. Returns associative
	 * array of [label_key => url] safe to expose in F.10 / REST.
	 *
	 * Always includes:
	 *   - public_map      : /coach-builder/<key>/  (after backfill)
	 *   - admin_profile   : wp-admin profile edit screen for the user (admin-only)
	 *   - rest_passages   : /wp-json/bizcoach-pro/v1/coach-maps/{id}/passages
	 *
	 * Astro adds 8 extras (natal share + 5 reports + 2 PDFs).
	 * Other templates currently rely on the public_map render only.
	 */
	public static function get_artifact_urls( $coachee_id, $coach_type = '' ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) { return array(); }

		$out = array();
		$pub = self::get_public_url( $coachee_id );
		if ( $pub !== '' ) { $out['public_map'] = $pub; }

		$out['rest_passages'] = rest_url( 'bizcoach-pro/v1/coach-maps/' . $coachee_id . '/passages' );

		if ( is_admin() && function_exists( 'admin_url' ) ) {
			global $wpdb;
			$user_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id = %d",
				$coachee_id
			) );
			if ( $user_id > 0 ) {
				$out['admin_user'] = admin_url( 'user-edit.php?user_id=' . $user_id );
			}
		}

		$slug = $coach_type !== '' ? sanitize_key( $coach_type ) : '';
		if ( $slug === '' ) {
			global $wpdb;
			$slug = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT coach_type FROM {$wpdb->prefix}bccm_coachees WHERE id = %d",
				$coachee_id
			) );
		}

		if ( $slug === 'astro_coach' ) {
			$out = array_merge( $out, self::get_astro_report_urls( $coachee_id ) );
		}

		return $out;
	}

	/* ─── helpers ─── */

	private static function decode_json( $val ) {
		if ( $val === '' || $val === null ) { return null; }
		if ( is_array( $val ) || is_object( $val ) ) { return $val; }
		$d = json_decode( (string) $val, true );
		return ( json_last_error() === JSON_ERROR_NONE ) ? $d : null;
	}

	private static function derive_title( array $row ): string {
		$name = isset( $row['full_name'] ) ? trim( (string) $row['full_name'] ) : '';
		$type = isset( $row['coach_type'] ) ? (string) $row['coach_type'] : 'coach_map';
		if ( $name !== '' ) { return sanitize_text_field( $name . ' · ' . $type ); }
		return sanitize_text_field( $type . ' #' . (int) ( $row['id'] ?? 0 ) );
	}
}
