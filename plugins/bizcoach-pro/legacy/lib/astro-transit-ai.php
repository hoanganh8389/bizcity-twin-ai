<?php
/**
 * BizCoach Pro — Transit AI Interpretation (lazy-loaded)
 *
 * Hooks the `bccm_transit_ai_sections` action emitted by
 * astro-transit-timeline.php and renders placeholder boxes per section.
 * Each placeholder triggers an AJAX call to `bccm_transit_llm_section`
 * which calls BizCity_LLM_Client (R-GW pattern, no direct OpenAI).
 *
 * AJAX action: bccm_transit_llm_section
 *   GET params: coachee_id, section (0..4), period, start, end,
 *               hash (public flow) | _wpnonce (admin flow), [regenerate]
 *
 * Cache: each (coachee_id, range_start, range_end, section_idx) result
 * stored in wp_options under `bccm_transit_ai_<md5>` (autoload=no).
 *
 * @since 0.36.x
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bccm_transit_ai_sections_list' ) ) {
function bccm_transit_ai_sections_list() {
	return array(
		0 => array( 'key' => 'overview',   'icon' => '🔭', 'title' => 'Tổng quan chu kỳ' ),
		1 => array( 'key' => 'work_path',  'icon' => '💼', 'title' => 'Sự nghiệp · Tài chính' ),
		2 => array( 'key' => 'love',       'icon' => '❤️', 'title' => 'Tình cảm · Quan hệ' ),
		3 => array( 'key' => 'wellbeing',  'icon' => '🌿', 'title' => 'Sức khoẻ · Năng lượng' ),
		4 => array( 'key' => 'action',     'icon' => '🎯', 'title' => 'Lời khuyên hành động' ),
	);
}
}

if ( ! function_exists( 'bccm_transit_ai_cache_key' ) ) {
function bccm_transit_ai_cache_key( $coachee_id, $period, $start, $end, $section ) {
	return 'bccm_transit_ai_' . md5( wp_json_encode( array(
		'c' => (int) $coachee_id,
		'p' => (string) $period,
		's' => (string) $start,
		'e' => (string) $end,
		'x' => (int) $section,
	) ) );
}
}

/* ─────────────────────────────────────────────────────────────────
 * Render placeholders (lazy boxes + JS loader)
 * Hooked from astro-transit-timeline.php via do_action('bccm_transit_ai_sections', $payload, $transits, $exact_events)
 * ───────────────────────────────────────────────────────────────── */

add_action( 'bccm_transit_ai_sections', 'bccm_transit_render_ai_placeholders', 10, 3 );

if ( ! function_exists( 'bccm_transit_render_ai_placeholders' ) ) {
function bccm_transit_render_ai_placeholders( $payload, $transits, $exact_events ) {
	$sections   = bccm_transit_ai_sections_list();
	$coachee_id = (int) $payload['coachee_id'];
	$period     = (string) $payload['period'];
	$start      = (string) $payload['range_start'];
	$end        = (string) $payload['range_end'];

	// Auth handle for AJAX loader. If we are in a public hash context,
	// pass the hash; otherwise generate a nonce.
	$ctx  = $GLOBALS['bcpro_public_astro_ctx'] ?? array();
	$hash = $ctx['hash'] ?? '';
	$auth_qs = '';
	if ( $hash !== '' && isset( $ctx['chart_type'] ) && $ctx['chart_type'] === 'transit' ) {
		$auth_qs = '&id=' . (int) $coachee_id . '&hash=' . rawurlencode( $hash );
	} else {
		$auth_qs = '&_wpnonce=' . rawurlencode( wp_create_nonce( 'bccm_transit_llm_section' ) );
	}
	?>
	<div class="transit-ai-grid">
	<?php foreach ( $sections as $idx => $sec ) :
		$cache_key   = bccm_transit_ai_cache_key( $coachee_id, $period, $start, $end, $idx );
		$existing    = get_option( $cache_key, '' );
		$has_cached  = is_string( $existing ) && $existing !== '';
	?>
		<div class="ai-section" data-section="<?php echo (int) $idx; ?>" id="transit-ai-<?php echo (int) $idx; ?>">
			<h3><?php echo esc_html( $sec['icon'] . ' ' . $sec['title'] ); ?>
				<button type="button" class="ai-regen" data-section="<?php echo (int) $idx; ?>"
					style="float:right;background:#fff;border:1px solid #fde047;color:#854d0e;padding:3px 10px;border-radius:6px;font-size:11px;cursor:pointer;">🔄</button>
			</h3>
			<div class="content-inner">
				<?php if ( $has_cached ) : ?>
					<?php echo function_exists( 'bccm_llm_md_to_html' ) ? bccm_llm_md_to_html( $existing ) : wpautop( esc_html( $existing ) ); ?>
				<?php else : ?>
					<div class="loading">⏳ Đang sinh luận giải AI…</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
	</div>
	<script>
	(function () {
		var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var coachee = <?php echo (int) $coachee_id; ?>;
		var period  = <?php echo wp_json_encode( $period ); ?>;
		var start   = <?php echo wp_json_encode( $start ); ?>;
		var endD    = <?php echo wp_json_encode( $end ); ?>;
		var authQS  = <?php echo wp_json_encode( $auth_qs ); ?>;

		function load(idx, regen) {
			var box = document.getElementById('transit-ai-' + idx);
			if (!box) return;
			var inner = box.querySelector('.content-inner');
			if (!regen && inner.querySelector('.loading') === null) return; // already loaded
			inner.innerHTML = '<div class="loading">⏳ Đang sinh luận giải AI…</div>';
			var url = ajaxUrl
				+ '?action=bccm_transit_llm_section'
				+ '&coachee_id=' + coachee
				+ '&section=' + idx
				+ '&period=' + encodeURIComponent(period)
				+ '&start=' + encodeURIComponent(start)
				+ '&end=' + encodeURIComponent(endD)
				+ (regen ? '&regenerate=1' : '')
				+ authQS;
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.text(); })
				.then(function (html) { inner.innerHTML = html; })
				.catch(function (e) { inner.innerHTML = '<div style="color:#dc2626;">Lỗi: ' + e.message + '</div>'; });
		}

		// Stagger load to spread the LLM hits.
		document.querySelectorAll('.ai-section[data-section]').forEach(function (el, i) {
			var idx = parseInt(el.getAttribute('data-section'), 10);
			if (el.querySelector('.loading')) {
				setTimeout(function () { load(idx, false); }, 400 * i);
			}
		});

		document.querySelectorAll('.ai-regen').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var idx = parseInt(btn.getAttribute('data-section'), 10);
				load(idx, true);
			});
		});
	})();
	</script>
	<style>
	.transit-ai-grid { display:flex; flex-direction:column; gap:12px; }
	.transit-ai-grid .ai-section h3 { margin:0 0 8px; }
	.transit-ai-grid .ai-list { margin: 6px 0 6px 22px; }
	.transit-ai-grid .ai-h4 { font-size:14px; color:#92400e; margin:10px 0 4px; }
	.transit-ai-grid .ai-h5 { font-size:13px; color:#a16207; margin:8px 0 3px; }
	</style>
	<?php
}
}

/* ─────────────────────────────────────────────────────────────────
 * AJAX handler — generate one section
 * ───────────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_bccm_transit_llm_section',        'bccm_transit_llm_section_handler' );
add_action( 'wp_ajax_nopriv_bccm_transit_llm_section', 'bccm_transit_llm_section_handler' );

if ( ! function_exists( 'bccm_transit_llm_section_handler' ) ) {
function bccm_transit_llm_section_handler() {
	$coachee_id = isset( $_GET['coachee_id'] ) ? (int) $_GET['coachee_id'] : 0;
	$section    = isset( $_GET['section'] )    ? (int) $_GET['section']    : -1;
	$period     = isset( $_GET['period'] )     ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month';
	$start      = isset( $_GET['start'] )      ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
	$end        = isset( $_GET['end'] )        ? sanitize_text_field( wp_unslash( $_GET['end'] ) )   : '';
	$regenerate = ! empty( $_GET['regenerate'] );

	if ( $coachee_id <= 0 || $section < 0 ) { wp_die( 'Bad request' ); }

	$sections = bccm_transit_ai_sections_list();
	if ( ! isset( $sections[ $section ] ) ) { wp_die( 'Bad section' ); }

	// Auth: public hash (chart_type=transit) OR admin nonce.
	$public_ok = false;
	if ( ! empty( $_GET['hash'] ) && class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) {
		$public_ok = BizCoach_Pro_Transit_Public_Router::verify_hash( $coachee_id, sanitize_text_field( wp_unslash( $_GET['hash'] ) ) );
	}
	if ( ! $public_ok ) {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Unauthorized' ); }
		check_ajax_referer( 'bccm_transit_llm_section', '_wpnonce' );
	}

	// Cache lookup.
	$cache_key = bccm_transit_ai_cache_key( $coachee_id, $period, $start, $end, $section );
	if ( ! $regenerate ) {
		$cached = get_option( $cache_key, '' );
		if ( is_string( $cached ) && $cached !== '' ) {
			echo function_exists( 'bccm_llm_md_to_html' ) ? bccm_llm_md_to_html( $cached ) : wpautop( esc_html( $cached ) );
			wp_die();
		}
	}

	// Recompute the timeline (cached transient → fast on subsequent calls).
	global $wpdb;
	$t = function_exists( 'bccm_tables' ) ? bccm_tables() : array( 'profiles' => $wpdb->prefix . 'bccm_coachees' );
	$coachee = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['profiles']} WHERE id=%d", $coachee_id ), ARRAY_A );
	if ( ! $coachee ) { wp_die( 'Coachee not found' ); }

	$user_id = (int) ( $coachee['user_id'] ?? 0 );
	$astro_row = null;
	if ( $user_id ) {
		$astro_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND (traits IS NOT NULL)",
			$user_id
		), ARRAY_A );
	}
	if ( ! $astro_row ) {
		$astro_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type='western'",
			$coachee_id
		), ARRAY_A );
	}
	if ( ! $astro_row ) { wp_die( 'Natal chart missing' ); }
	$natal_traits = json_decode( $astro_row['traits'], true ) ?: array();
	$birth        = $natal_traits['birth_data'] ?? array();

	$range = bccm_transit_timeline_compute_range( $period, $start, $end );
	if ( is_wp_error( $range ) ) { wp_die( esc_html( $range->get_error_message() ) ); }

	$result = bccm_transit_timeline_fetch_cached( $coachee_id, $astro_row, $birth, $range, false );
	if ( empty( $result['success'] ) ) { wp_die( 'Timeline unavailable' ); }

	$transits = (array) $result['transits'];

	// Build LLM prompt.
	$sec_meta = $sections[ $section ];
	$prompt   = bccm_transit_llm_build_user_prompt( $sec_meta, $coachee, $natal_traits, $range, $transits );
	$system   = bccm_transit_llm_system_prompt( $sec_meta );

	if ( ! function_exists( 'bccm_llm_call_openai' ) ) {
		$ll = dirname( __FILE__ ) . '/astro-report-llm.php';
		if ( file_exists( $ll ) ) { require_once $ll; }
	}
	if ( ! function_exists( 'bccm_llm_call_openai' ) ) {
		wp_die( 'LLM caller unavailable' );
	}

	$content = bccm_llm_call_openai( $system, $prompt, array(
		'max_tokens'  => 1600,
		'temperature' => 0.7,
		'timeout'     => 90,
	) );

	if ( is_wp_error( $content ) ) {
		echo '<div style="color:#dc2626;">Lỗi AI: ' . esc_html( $content->get_error_message() ) . '</div>';
		wp_die();
	}

	$content = (string) $content;
	update_option( $cache_key, $content, false );

	echo function_exists( 'bccm_llm_md_to_html' ) ? bccm_llm_md_to_html( $content ) : wpautop( esc_html( $content ) );
	wp_die();
}
}

if ( ! function_exists( 'bccm_transit_llm_system_prompt' ) ) {
function bccm_transit_llm_system_prompt( $sec_meta ) {
	return "Bạn là một chuyên gia chiêm tinh học (Western Astrology) viết tiếng Việt tự nhiên, chuyên sâu, "
		. "tập trung vào ý nghĩa thực tiễn của các transit (chuyển dịch hành tinh) đối với cuộc sống đời thường. "
		. "Hãy giữ giọng văn ấm áp, có chiều sâu chiêm nghiệm, KHÔNG mê tín, KHÔNG hứa hẹn tuyệt đối. "
		. "Sử dụng Markdown: ## tiêu đề chính, ### tiêu đề phụ, **in đậm**, danh sách bằng `-`. "
		. "Chủ đề: " . ( $sec_meta['title'] ?? 'Tổng quan transit' ) . ". "
		. "Tránh trùng lặp với các phần khác. Tập trung vào những transit nổi bật được liệt kê.";
}
}

if ( ! function_exists( 'bccm_transit_llm_build_user_prompt' ) ) {
function bccm_transit_llm_build_user_prompt( $sec_meta, $coachee, $natal_traits, $range, $transits ) {
	$name = (string) ( $coachee['full_name'] ?? '—' );
	$bd   = $natal_traits['birth_data'] ?? array();
	$dob  = ! empty( $bd['day'] ) ? sprintf( '%02d/%02d/%04d', $bd['day'], $bd['month'], $bd['year'] ) : '—';

	// Pick top transits: slow planets first, then by exact-hit presence, cap 25 rows.
	$slow_set = array( 'jupiter', 'saturn', 'uranus', 'neptune', 'pluto', 'chiron', 'north_node' );
	usort( $transits, function ( $a, $b ) use ( $slow_set ) {
		$ra = in_array( strtolower( $a['transit_planet'] ?? '' ), $slow_set, true ) ? 0 : 1;
		$rb = in_array( strtolower( $b['transit_planet'] ?? '' ), $slow_set, true ) ? 0 : 1;
		if ( $ra !== $rb ) { return $ra - $rb; }
		$ea = ! empty( $a['exact_hits_in_month'] ) || ! empty( $a['exact_datetimes'] );
		$eb = ! empty( $b['exact_hits_in_month'] ) || ! empty( $b['exact_datetimes'] );
		return ( $eb ? 1 : 0 ) - ( $ea ? 1 : 0 );
	} );
	$top = array_slice( $transits, 0, 25 );

	$lines = array();
	foreach ( $top as $t ) {
		$lines[] = sprintf(
			'- %s (%s) — từ %s đến %s · duration %.1f ngày · %s%s',
			(string) ( $t['label'] ?? '?' ),
			(string) ( $t['category'] ?? 'medium' ),
			date( 'd/m/Y', strtotime( $t['start_datetime'] ?? '' ) ),
			date( 'd/m/Y', strtotime( $t['end_datetime']   ?? '' ) ),
			(float)  ( $t['duration_days'] ?? 0 ),
			( $t['pass_type'] ?? 'direct' ) === 'retrograde' ? '℞ nghịch hành' : 'thuận hành',
			( ! empty( $t['exact_hits_in_month'] ) || ! empty( $t['exact_datetimes'] ) )
				? ' · EXACT tại ' . implode( ', ', array_map(
					function ( $d ) { return date( 'd/m', strtotime( $d ) ); },
					(array) ( $t['exact_hits_in_month'] ?? $t['exact_datetimes'] )
				) )
				: '',
		);
	}
	$transit_block = $lines ? implode( "\n", $lines ) : '(không có transit nổi bật)';

	$focus_hint = array(
		'overview'  => 'Tóm tắt năng lượng chu kỳ này, các theme chính, hành tinh chủ đạo.',
		'work_path' => 'Phân tích các transit ảnh hưởng đến sự nghiệp, tài chính, định hướng công việc.',
		'love'      => 'Phân tích các transit ảnh hưởng đến tình cảm, các mối quan hệ thân thiết, hôn nhân.',
		'wellbeing' => 'Phân tích các transit ảnh hưởng đến sức khỏe, năng lượng, nhịp sinh học, stress.',
		'action'    => 'Đưa ra 3–5 lời khuyên hành động cụ thể, có thể thực hiện ngay, kèm timeframe.',
	);
	$focus = $focus_hint[ $sec_meta['key'] ?? '' ] ?? '';

	return "Người dùng: **{$name}** · Sinh: {$dob}\n"
		. "Khoảng transit: **{$range['range_start']} → {$range['range_end']}** (mode: {$range['mode']})\n\n"
		. "Các transit nổi bật trong khoảng này:\n"
		. $transit_block . "\n\n"
		. "**Yêu cầu:** {$focus}\n"
		. "Viết khoảng 250–400 từ, dùng Markdown, có 2-3 ### tiểu mục.\n"
		. "Trích dẫn cụ thể transit theo tên (ví dụ: \"Saturn vuông góc với Mặt Trời natal\") khi phân tích.";
}
}
