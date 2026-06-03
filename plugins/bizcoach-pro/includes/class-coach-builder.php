<?php
/**
 * BizCoach Pro — Coach Builder (frontend landing + AI quick-fill).
 *
 * Adds:
 *   - Public landing page at /coach-builder/ (and /coach-builder/?type=biz_coach).
 *     One-page Step 1 (My Profile) + Step 2 (Coach Template selection + 20 answers).
 *   - REST endpoints under bizcoach-pro/v1/coach-builder/* for AI fill + save.
 *   - Asset injection on legacy admin Step 2 page (bccm_step2_coach_template) so
 *     the same "✨ AI fill nhanh" UI appears in both contexts.
 *
 * Reuses: bccm_coach_types(), bccm_ensure_action_plan(), bccm_tables() from
 * the legacy/ shadow. Reuses BizCity_Router_Proxy::chat() + BizCity_Router_Models::get_model().
 *
 * @since 0.3.0 (Sprint K.B)
 */
defined( 'ABSPATH' ) || exit;

class BizCoach_Pro_Coach_Builder {

	const REST_NS    = 'bizcoach-pro/v1';
	const PAGE_SLUG  = 'coach-builder';
	const QV         = 'bcpro_builder';
	const QV_KEY     = 'bcpro_builder_key';
	const FLUSH_FLAG = 'bcpro_builder_rewrite_v2';

	public static function init() {
		add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars',        array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_landing' ) );
		add_action( 'rest_api_init',     array( __CLASS__, 'register_routes' ) );

		// One-shot rewrite flush so /coach-builder/ works without manual flush.
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );

		// Inject AI-fill assets into legacy admin Step 2 page.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_admin_assets' ) );
		add_action( 'admin_footer',          array( __CLASS__, 'maybe_print_admin_inline' ) );
	}

	/* ----- rewrite + landing render ----- */

	public static function register_rewrite() {
		add_rewrite_rule( '^' . self::PAGE_SLUG . '/?$', 'index.php?' . self::QV . '=1', 'top' );
		// Shareable map URL: /coach-builder/{public_key}/  (uuid form e.g. 8-4-4-4-12)
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/([A-Za-z0-9\-]{8,})/?$',
			'index.php?' . self::QV . '=1&' . self::QV_KEY . '=$matches[1]',
			'top'
		);
	}
	public static function register_query_vars( $vars ) {
		$vars[] = self::QV;
		$vars[] = self::QV_KEY;
		return $vars;
	}
	public static function maybe_flush_rewrite() {
		$cur = '1.' . ( defined( 'BCPRO_VERSION' ) ? BCPRO_VERSION : '0' );
		if ( get_option( self::FLUSH_FLAG ) === $cur ) { return; }
		flush_rewrite_rules( false );
		update_option( self::FLUSH_FLAG, $cur );
	}
	public static function maybe_render_landing() {
		if ( ! get_query_var( self::QV ) ) { return; }
		$tpl = BCPRO_DIR . 'templates/page-coach-builder.php';
		if ( file_exists( $tpl ) ) {
			status_header( 200 );
			nocache_headers();
			include $tpl;
			exit;
		}
	}

	/* ----- REST routes ----- */

	public static function register_routes() {
		$perm_open = '__return_true'; // public landing + admin both call these

		register_rest_route( self::REST_NS, '/coach-builder/coach-types', array(
			'methods'             => 'GET',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_list_coach_types' ),
		) );
		register_rest_route( self::REST_NS, '/coach-builder/ai-fill', array(
			'methods'             => 'POST',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_ai_fill' ),
		) );
		register_rest_route( self::REST_NS, '/coach-builder/save', array(
			'methods'             => 'POST',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_save' ),
		) );
		register_rest_route( self::REST_NS, '/coach-builder/template', array(
			'methods'             => 'GET',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_get_template' ),
		) );
		register_rest_route( self::REST_NS, '/coach-builder/generate-section', array(
			'methods'             => 'POST',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_generate_section' ),
		) );
		register_rest_route( self::REST_NS, '/coach-builder/section-status', array(
			'methods'             => 'GET',
			'permission_callback' => $perm_open,
			'callback'            => array( __CLASS__, 'rest_section_status' ),
		) );
	}

	/**
	 * GET /template?type=X → {type, label, fields:[{key,label,type,options?,placeholder?}],
	 *                        questions:[...], generators:[{key,label}]}
	 */
	public static function rest_get_template( WP_REST_Request $req ) {
		$type = sanitize_text_field( (string) $req->get_param( 'type' ) );
		if ( $type === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_type' ), 400 );
		}
		if ( ! function_exists( 'bccm_coach_type_base_registry' ) ) {
			return new WP_REST_Response( array( 'error' => 'legacy_not_loaded' ), 503 );
		}
		$reg = bccm_coach_type_base_registry();
		if ( ! isset( $reg[ $type ] ) ) {
			return new WP_REST_Response( array( 'error' => 'unknown_type' ), 404 );
		}
		$cfg    = $reg[ $type ];
		$fields = array();
		foreach ( (array) ( $cfg['fields'] ?? array() ) as $key => $f ) {
			$fields[] = array(
				'key'         => (string) $key,
				'label'       => (string) ( $f['label'] ?? $key ),
				'type'        => (string) ( $f['type'] ?? 'text' ),
				'placeholder' => (string) ( $f['placeholder'] ?? '' ),
				'step'        => isset( $f['step'] ) ? (string) $f['step'] : '',
				'options'     => isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : null,
			);
		}
		$gens = array();
		foreach ( (array) ( $cfg['generators'] ?? array() ) as $g ) {
			$gens[] = array(
				'key'   => (string) ( $g['key'] ?? '' ),
				'label' => (string) ( $g['label'] ?? '' ),
			);
		}
		return rest_ensure_response( array(
			'type'       => $type,
			'label'      => (string) ( $cfg['label'] ?? $type ),
			'fields'     => $fields,
			'questions'  => self::questions_for( $type ),
			'generators' => $gens,
		) );
	}

	/**
	 * POST /generate-section
	 * body: { public_key, gen_key }
	 * → { ok, gen_key, label, content_md, cached:bool }
	 *
	 * Generic generator: builds prompt from coach_type + section label + profile + answers,
	 * stores markdown into bccm_gen_results.result_json (no profile column overwrite).
	 */
	public static function rest_generate_section( WP_REST_Request $req ) {
		$public_key = sanitize_text_field( (string) $req->get_param( 'public_key' ) );
		$gen_key    = sanitize_text_field( (string) $req->get_param( 'gen_key' ) );
		$force      = (bool) $req->get_param( 'force' );
		if ( $public_key === '' || $gen_key === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_params' ), 400 );
		}
		if ( ! function_exists( 'bccm_tables' ) || ! function_exists( 'bccm_coach_type_base_registry' ) ) {
			return new WP_REST_Response( array( 'error' => 'legacy_not_loaded' ), 503 );
		}
		global $wpdb;
		$t = bccm_tables();

		$plan = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t['plans']} WHERE public_key=%s AND status='active' LIMIT 1",
			$public_key
		), ARRAY_A );
		if ( ! $plan ) {
			return new WP_REST_Response( array( 'error' => 'plan_not_found' ), 404 );
		}
		$coachee_id = (int) $plan['coachee_id'];
		$profile    = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id
		), ARRAY_A );
		if ( ! $profile ) {
			return new WP_REST_Response( array( 'error' => 'profile_not_found' ), 404 );
		}
		$coach_type = (string) ( $profile['coach_type'] ?? '' );

		// Resolve generator label from registry.
		$reg = bccm_coach_type_base_registry();
		$gen_label = $gen_key;
		$gen_fn    = '';
		if ( isset( $reg[ $coach_type ]['generators'] ) ) {
			foreach ( $reg[ $coach_type ]['generators'] as $g ) {
				if ( ( $g['key'] ?? '' ) === $gen_key ) {
					$gen_label = (string) $g['label'];
					$gen_fn    = (string) ( $g['fn'] ?? '' );
					break;
				}
			}
		}

		// Cache hit?
		if ( ! $force && function_exists( 'bccm_get_gen_results' ) ) {
			$existing = bccm_get_gen_results( $coachee_id, $gen_key );
			if ( $existing && ! empty( $existing['result_json'] ) && ( $existing['status'] ?? '' ) === 'success' ) {
				$cached = self::extract_section_markdown( $existing['result_json'] );
				if ( $cached !== '' ) {
					return rest_ensure_response( array(
						'ok'         => true,
						'gen_key'    => $gen_key,
						'label'      => $gen_label,
						'content_md' => $cached,
						'cached'     => true,
					) );
				}
			}
		}

		if ( ! class_exists( 'BizCity_Router_Proxy' ) || ! BizCity_Router_Proxy::is_ready() ) {
			return new WP_REST_Response( array( 'error' => 'llm_router_unavailable' ), 503 );
		}

		// Load answers.
		$ans_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT answers FROM {$t['answers']} WHERE coachee_id=%d ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		$answers = $ans_row ? (array) json_decode( $ans_row['answers'] ?? '[]', true ) : array();
		$questions = self::questions_for( $coach_type );
		$qa_pairs  = array();
		foreach ( $questions as $i => $q ) {
			$qa_pairs[] = array( 'q' => $q, 'a' => isset( $answers[ $i ] ) ? (string) $answers[ $i ] : '' );
		}

		// Profile extras.
		$extra = array();
		if ( ! empty( $profile['extra_fields_json'] ) ) {
			$ex = json_decode( $profile['extra_fields_json'], true );
			if ( is_array( $ex ) ) { $extra = $ex; }
		}

		$coach_label = (string) ( $reg[ $coach_type ]['label'] ?? $coach_type );

		$sys = "Bạn là **{$coach_label}** — một huấn luyện viên chuyên sâu. " .
			"Hãy viết phần \"{$gen_label}\" cho khách hàng dưới dạng **markdown tiếng Việt**, " .
			"súc tích nhưng giàu insight. Cấu trúc gợi ý:\n" .
			"1. Một đoạn dẫn ngắn (2–3 câu).\n" .
			"2. Các heading `###` cho từng ý chính, kèm bullet/số/bảng nếu phù hợp.\n" .
			"3. Một block `> 💡` đúc kết / lời khuyên hành động cuối phần.\n" .
			"KHÔNG dùng heading cấp 1/2 (`#`, `##`). KHÔNG bọc trong code-fence. " .
			"Chỉ trả về **MARKDOWN thuần**, không thêm lời chào hay giải thích meta.";
		$user_payload = array(
			'section'        => $gen_label,
			'coach_type'     => $coach_type,
			'profile'        => array(
				'full_name'        => $profile['full_name'] ?? '',
				'dob'              => $profile['dob'] ?? '',
				'phone'            => $profile['phone'] ?? '',
				'current_role'     => $profile['current_role'] ?? '',
				'years_experience' => $profile['years_experience'] ?? '',
				'education_level'  => $profile['education_level'] ?? '',
				'company_name'     => $profile['company_name'] ?? '',
				'company_industry' => $profile['company_industry'] ?? '',
			),
			'extra_fields'   => $extra,
			'questions_answers' => $qa_pairs,
		);
		$messages = array(
			array( 'role' => 'system', 'content' => $sys ),
			array( 'role' => 'user',   'content' => wp_json_encode( $user_payload, JSON_UNESCAPED_UNICODE ) ),
		);

		$model = BizCity_Router_Models::get_model( 'chat' );
		$resp  = BizCity_Router_Proxy::chat( $model, $messages, array(
			'temperature' => 0.6,
			'max_tokens'  => 2200,
		) );
		if ( empty( $resp['success'] ) ) {
			return new WP_REST_Response( array( 'error' => 'llm_failed', 'detail' => $resp['error'] ?? '' ), 502 );
		}
		$md = trim( (string) ( $resp['message'] ?? '' ) );
		// Strip wrapping code-fence if model added one.
		if ( preg_match( '/^```(?:markdown|md)?\s*(.+)```\s*$/is', $md, $m ) ) { $md = trim( $m[1] ); }
		if ( $md === '' ) {
			return new WP_REST_Response( array( 'error' => 'empty_response' ), 502 );
		}

		// Persist (wraps in JSON envelope so future structured upgrades fit).
		if ( function_exists( 'bccm_save_gen_result' ) ) {
			bccm_save_gen_result(
				$coachee_id, $gen_key, $gen_fn ?: 'bcpro_generic_section',
				$gen_label, $coach_type,
				array( 'content_md' => $md, 'generated_by' => 'bcpro_coach_builder' ),
				'' // no profile column overwrite — we keep raw JSON in gen_results only
			);
			// Cache invalidation — generator wrote a new gen_results row.
			// Also bump coachee/coachee_idx because get_artifact() blends both.
			do_action( 'bcpro/cache/invalidate', 'gens',    array( 'id' => $coachee_id ) );
			do_action( 'bcpro/cache/invalidate', 'coachee', array( 'id' => $coachee_id ) );
		}

		return rest_ensure_response( array(
			'ok'         => true,
			'gen_key'    => $gen_key,
			'label'      => $gen_label,
			'content_md' => $md,
			'cached'     => false,
			'model'      => $resp['model'] ?? $model,
		) );
	}

	/**
	 * GET /section-status?key=X → {public_key, generators:[{key,label,status,has_content}]}
	 */
	public static function rest_section_status( WP_REST_Request $req ) {
		$public_key = sanitize_text_field( (string) $req->get_param( 'key' ) );
		if ( $public_key === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_key' ), 400 );
		}
		if ( ! function_exists( 'bccm_tables' ) ) {
			return new WP_REST_Response( array( 'error' => 'legacy_not_loaded' ), 503 );
		}
		global $wpdb;
		$t    = bccm_tables();
		$plan = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t['plans']} WHERE public_key=%s LIMIT 1", $public_key
		), ARRAY_A );
		if ( ! $plan ) {
			return new WP_REST_Response( array( 'error' => 'plan_not_found' ), 404 );
		}
		$coachee_id = (int) $plan['coachee_id'];
		$profile    = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, coach_type, full_name FROM {$t['profiles']} WHERE id=%d", $coachee_id
		), ARRAY_A );
		$coach_type = (string) ( $profile['coach_type'] ?? '' );

		$reg  = function_exists( 'bccm_coach_type_base_registry' ) ? bccm_coach_type_base_registry() : array();
		$gens = (array) ( $reg[ $coach_type ]['generators'] ?? array() );

		$existing = function_exists( 'bccm_get_gen_results' ) ? (array) bccm_get_gen_results( $coachee_id ) : array();
		$by_key   = array();
		foreach ( $existing as $row ) {
			$by_key[ $row['gen_key'] ?? '' ] = $row;
		}

		$out = array();
		foreach ( $gens as $g ) {
			$key = (string) ( $g['key'] ?? '' );
			$row = $by_key[ $key ] ?? null;
			$has = $row && ! empty( $row['result_json'] ) && ( ($row['status'] ?? '') === 'success' );
			$item = array(
				'key'         => $key,
				'label'       => (string) ( $g['label'] ?? $key ),
				'status'      => $row ? (string) ( $row['status'] ?? '' ) : 'pending',
				'has_content' => (bool) $has,
			);
			// Inline cached markdown so resume flow doesn't need 1 generate-section roundtrip per cached section.
			if ( $has ) {
				$md = self::extract_section_markdown( $row['result_json'] );
				if ( $md !== '' ) { $item['content_md'] = $md; }
			}
			$out[] = $item;
		}

		return rest_ensure_response( array(
			'public_key' => $public_key,
			'coach_type' => $coach_type,
			'full_name'  => (string) ( $profile['full_name'] ?? '' ),
			'generators' => $out,
		) );
	}

	/** Pull markdown out of a gen_result JSON envelope (handles legacy structured shapes). */
	public static function extract_section_markdown( $json_str ) {
		$dec = json_decode( (string) $json_str, true );
		if ( ! is_array( $dec ) ) { return ''; }
		if ( isset( $dec['content_md'] ) && is_string( $dec['content_md'] ) ) {
			return (string) $dec['content_md'];
		}
		// Legacy structured shapes (overview, vision, swot, etc.) — render as JSON code-block fallback.
		return "```json\n" . wp_json_encode( $dec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n```";
	}

	/**
	 * GET /coach-types → [{type, label, fields[], questions[]}]
	 */
	public static function rest_list_coach_types( $req ) {
		if ( ! function_exists( 'bccm_coach_types' ) ) {
			return new WP_REST_Response( array( 'error' => 'legacy_not_loaded' ), 503 );
		}
		$types = bccm_coach_types();
		$out   = array();
		foreach ( $types as $slug => $cfg ) {
			$out[] = array(
				'type'      => $slug,
				'label'     => $cfg['label'] ?? $slug,
				'fields'    => array_values( (array) ( $cfg['fields'] ?? array() ) ),
				'questions' => self::questions_for( $slug ),
			);
		}
		return rest_ensure_response( array( 'types' => $out ) );
	}

	/**
	 * POST /ai-fill
	 * body: { coach_type, summary: { job_title, years, education, goal, ... } }
	 * → { answers: ["...", "...", ...] }
	 */
	public static function rest_ai_fill( WP_REST_Request $req ) {
		$coach_type = sanitize_text_field( (string) $req->get_param( 'coach_type' ) );
		$summary    = (array) $req->get_param( 'summary' );
		if ( $coach_type === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_coach_type' ), 400 );
		}
		if ( ! class_exists( 'BizCity_Router_Proxy' ) || ! class_exists( 'BizCity_Router_Models' ) ) {
			return new WP_REST_Response( array( 'error' => 'llm_router_unavailable' ), 503 );
		}
		if ( ! BizCity_Router_Proxy::is_ready() ) {
			return new WP_REST_Response( array( 'error' => 'llm_router_no_key' ), 503 );
		}

		$questions = self::questions_for( $coach_type );
		// Frontend may send the canonical question list (admin Step 2 reads them from the table,
		// landing reads from /template). Trust the client list if non-empty — keeps AI fill working
		// even when DB-side template row is missing or coach_type alias mismatches.
		$client_qs = (array) $req->get_param( 'questions' );
		if ( ! empty( $client_qs ) ) {
			$qs_clean = array();
			foreach ( $client_qs as $q ) {
				$q = trim( (string) wp_strip_all_tags( (string) $q ) );
				if ( $q !== '' ) { $qs_clean[] = mb_substr( $q, 0, 500 ); }
			}
			if ( ! empty( $qs_clean ) ) { $questions = $qs_clean; }
		}
		if ( empty( $questions ) ) {
			return new WP_REST_Response( array( 'error' => 'no_questions_for_type' ), 404 );
		}

		// Sanitize summary. `freeform` field accepts up to 4000 chars (single big textarea
		// where user describes themselves narratively). Other fields stay at 500 chars cap.
		$summary_clean = array();
		$i = 0;
		foreach ( $summary as $k => $v ) {
			if ( $i++ >= 12 ) { break; }
			$key   = sanitize_key( (string) $k );
			$cap   = ( $key === 'freeform' ) ? 4000 : 500;
			$summary_clean[ $key ] = mb_substr( (string) wp_strip_all_tags( (string) $v ), 0, $cap );
		}

		$model = BizCity_Router_Models::get_model( 'chat' );
		$sys   = 'Bạn là Coach AI. Dựa trên thông tin tóm tắt người dùng cung cấp, ' .
			'hãy trả lời từng câu hỏi (tối đa 2-3 câu / câu hỏi, súc tích, đúng văn phong cá nhân, ' .
			'tiếng Việt tự nhiên, không markdown). Trả về NGHIÊM NGẶT JSON object dạng ' .
			'{"answers":["...", "...", ...]} với số phần tử bằng đúng số câu hỏi, theo thứ tự được hỏi. ' .
			'Nếu không đủ thông tin, hãy suy luận hợp lý nhất.';
		$user_payload = array(
			'coach_type'      => $coach_type,
			'user_summary'    => $summary_clean,
			'questions'       => $questions,
			'expected_count'  => count( $questions ),
			'output_contract' => '{"answers":[...string]}',
		);
		$messages = array(
			array( 'role' => 'system', 'content' => $sys ),
			array( 'role' => 'user',   'content' => wp_json_encode( $user_payload, JSON_UNESCAPED_UNICODE ) ),
		);

		$resp = BizCity_Router_Proxy::chat( $model, $messages, array(
			'temperature' => 0.4,
			'max_tokens'  => 3500,
			'extra_body'  => array( 'response_format' => array( 'type' => 'json_object' ) ),
		) );
		if ( empty( $resp['success'] ) ) {
			return new WP_REST_Response( array( 'error' => 'llm_failed', 'detail' => $resp['error'] ?? '' ), 502 );
		}

		$json = self::extract_json_object( (string) $resp['message'] );
		$ans  = is_array( $json['answers'] ?? null ) ? $json['answers'] : array();
		// Pad / trim to exact length.
		$out = array();
		for ( $i = 0; $i < count( $questions ); $i++ ) {
			$out[] = isset( $ans[ $i ] ) ? (string) $ans[ $i ] : '';
		}
		return rest_ensure_response( array(
			'answers' => $out,
			'model'   => $resp['model'] ?? $model,
			'usage'   => $resp['usage'] ?? array(),
		) );
	}

	/**
	 * POST /save
	 * body: { coach_type, profile:{full_name,dob,phone,email,job_title,...},
	 *         summary:{...}, answers:[...20] }
	 * → { ok, public_key, public_url }
	 */
	public static function rest_save( WP_REST_Request $req ) {
		if ( ! function_exists( 'bccm_ensure_action_plan' ) || ! function_exists( 'bccm_tables' ) ) {
			return new WP_REST_Response( array( 'error' => 'legacy_not_loaded' ), 503 );
		}
		global $wpdb;
		$t = bccm_tables();

		$coach_type = sanitize_text_field( (string) $req->get_param( 'coach_type' ) );
		$profile    = (array) $req->get_param( 'profile' );
		$extra_in   = (array) $req->get_param( 'extra_fields' );
		$answers    = array_values( (array) $req->get_param( 'answers' ) );
		$summary    = (array) $req->get_param( 'summary' );

		if ( $coach_type === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_coach_type' ), 400 );
		}
		$full_name = trim( (string) ( $profile['full_name'] ?? '' ) );
		if ( $full_name === '' ) {
			return new WP_REST_Response( array( 'error' => 'missing_full_name' ), 400 );
		}

		$dob = (string) ( $profile['dob'] ?? '' );
		if ( $dob !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) { $dob = ''; }
		$user_id = get_current_user_id() ?: null;

		// Whitelist extra-field keys against registry, sanitize into top-level columns
		// (legacy schema stores e.g. current_role / years_experience as actual columns).
		$reg          = function_exists( 'bccm_coach_type_base_registry' ) ? bccm_coach_type_base_registry() : array();
		$reg_fields   = (array) ( $reg[ $coach_type ]['fields'] ?? array() );
		$extra_clean  = array();
		$column_extra = array();
		$existing_cols = self::profile_columns();
		foreach ( $reg_fields as $fkey => $fdef ) {
			$fkey = (string) $fkey;
			if ( ! array_key_exists( $fkey, $extra_in ) ) { continue; }
			$val  = (string) wp_strip_all_tags( (string) $extra_in[ $fkey ] );
			$val  = mb_substr( $val, 0, 500 );
			$extra_clean[ $fkey ] = $val;
			// If the legacy schema has a column with this name, store there too.
			if ( in_array( $fkey, $existing_cols, true ) ) {
				$column_extra[ $fkey ] = $val;
			}
		}

		$now    = current_time( 'mysql' );
		$row    = array(
			'user_id'           => $user_id,
			'platform_type'     => 'WEB_LANDING',
			'coach_type'        => $coach_type,
			'full_name'         => sanitize_text_field( $full_name ),
			'phone'             => sanitize_text_field( (string) ( $profile['phone'] ?? '' ) ),
			'address'           => sanitize_text_field( (string) ( $profile['address'] ?? '' ) ),
			'dob'               => $dob ?: null,
			'extra_fields_json' => wp_json_encode( array(
				'profile_extras' => array_diff_key( $profile,
					array_flip( array( 'full_name', 'phone', 'address', 'dob' ) ) ),
				'extra_fields'   => $extra_clean,
				'summary'        => $summary,
			), JSON_UNESCAPED_UNICODE ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		// Merge whitelisted column-level extras (current_role, years_experience, ...).
		foreach ( $column_extra as $ck => $cv ) {
			$row[ $ck ] = $cv;
		}
		$ok = $wpdb->insert( $t['profiles'], $row );
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'error' => 'db_insert_failed', 'detail' => $wpdb->last_error ), 500 );
		}
		$coachee_id = (int) $wpdb->insert_id;

		// Save answers (single row JSON, mirrors legacy admin save).
		$tpl_row    = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$t['templates']} WHERE coach_type=%s LIMIT 1", $coach_type
		), ARRAY_A );
		$template_id = $tpl_row ? (int) $tpl_row['id'] : 0;
		$wpdb->insert( $t['answers'], array(
			'coachee_id'  => $coachee_id,
			'template_id' => $template_id,
			'answers'     => wp_json_encode( $answers, JSON_UNESCAPED_UNICODE ),
			'updated_at'  => $now,
		) );

		// Plan + public_key.
		$public_key = bccm_ensure_action_plan( $coachee_id );
		$public_url = function_exists( 'bccm_public_map_url' )
			? bccm_public_map_url( $public_key )
			: home_url( '/coachee-map/' . rawurlencode( $public_key ) . '/' );

		// Cache invalidation — coachee created → flush any cached lists for
		// this user + the per-id slot (see CACHE-STRATEGY.md §4).
		do_action( 'bcpro/cache/invalidate', 'coachee', array(
			'id'      => $coachee_id,
			'user_id' => $user_id,
		) );
		do_action( 'bcpro/cache/invalidate', 'plan', array( 'id' => $coachee_id ) );

		return rest_ensure_response( array(
			'ok'          => true,
			'coachee_id'  => $coachee_id,
			'public_key'  => $public_key,
			'public_url'  => $public_url,
		) );
	}

	/* ----- helpers ----- */

	/** Get question list for a coach_type from templates DB; falls back to []. */
	public static function questions_for( $coach_type ) {
		// Delegate to bizcoach-map loader (handles slug variants: career_coach / career-coach / careercoach,
		// LIKE fallback, and default-questions fallback — same behavior as admin Step 2).
		if ( function_exists( 'bccm_get_questions_for' ) ) {
			$qs = bccm_get_questions_for( $coach_type );
			// bccm_get_questions_for pads to 20 with empty strings; strip empties for frontend.
			$qs = array_values( array_filter( array_map( 'strval', (array) $qs ), 'strlen' ) );
			return $qs;
		}

		// Fallback: legacy direct query (kept for safety if bizcoach-map not loaded).
		if ( ! function_exists( 'bccm_tables' ) ) { return array(); }
		global $wpdb;
		$t   = bccm_tables();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT questions FROM {$t['templates']} WHERE coach_type=%s LIMIT 1",
			$coach_type
		), ARRAY_A );
		if ( ! $row || empty( $row['questions'] ) ) { return array(); }
		$dec = json_decode( $row['questions'], true );
		if ( ! is_array( $dec ) ) { return array(); }
		$out = array();
		foreach ( $dec as $q ) {
			if ( is_string( $q ) ) { $out[] = $q; }
			elseif ( is_array( $q ) && isset( $q['q'] ) ) { $out[] = (string) $q['q']; }
		}
		return $out;
	}

	/** Cache the actual column list of the profiles table (for safe extra-field merging). */
	public static function profile_columns() {
		static $cols = null;
		if ( $cols !== null ) { return $cols; }
		if ( ! function_exists( 'bccm_tables' ) ) { return array(); }
		global $wpdb;
		$t   = bccm_tables();
		$res = $wpdb->get_col( "SHOW COLUMNS FROM {$t['profiles']}" );
		$cols = is_array( $res ) ? array_map( 'strval', $res ) : array();
		return $cols;
	}

	/** Best-effort extract a JSON object from LLM text (handles ```json fences). */
	public static function extract_json_object( $txt ) {
		$txt = trim( (string) $txt );
		if ( $txt === '' ) { return array(); }
		// Strip markdown fences.
		if ( preg_match( '/```(?:json)?\s*(.+?)```/is', $txt, $m ) ) { $txt = $m[1]; }
		// Find first { ... last }.
		$start = strpos( $txt, '{' );
		$end   = strrpos( $txt, '}' );
		if ( $start === false || $end === false || $end <= $start ) { return array(); }
		$candidate = substr( $txt, $start, $end - $start + 1 );
		$dec = json_decode( $candidate, true );
		return is_array( $dec ) ? $dec : array();
	}

	/* ----- admin page injection (Step 2) ----- */

	public static function maybe_enqueue_admin_assets( $hook ) {
		if ( ! self::is_admin_step2() ) { return; }
		$css = BCPRO_DIR . 'assets/coach-builder.css';
		$js  = BCPRO_DIR . 'assets/coach-builder.js';
		$css_v = file_exists( $css ) ? (string) filemtime( $css ) : '1';
		$js_v  = file_exists( $js )  ? (string) filemtime( $js )  : '1';
		wp_enqueue_style(  'bcpro-coach-builder', BCPRO_URL . 'assets/coach-builder.css', array(), $css_v );
		wp_enqueue_script( 'bcpro-coach-builder', BCPRO_URL . 'assets/coach-builder.js',  array(), $js_v, true );
		wp_localize_script( 'bcpro-coach-builder', 'BCPRO_BUILDER', array(
			'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/coach-builder/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'mode'    => 'admin',
		) );
	}
	public static function maybe_print_admin_inline() {
		if ( ! self::is_admin_step2() ) { return; }
		// Small hook target — JS will mount the AI-fill widget right above the questions table.
		?>
		<div id="bcpro-ai-fill-mount" data-bcpro-mode="admin" style="display:none"></div>
		<?php
	}
	private static function is_admin_step2() {
		return is_admin() && isset( $_GET['page'] ) && $_GET['page'] === 'bccm_step2_coach_template';
	}
}
