<?php
/**
 * Action: Run Astro Transit (day-by-day range from bccm_transit_snapshots).
 *
 * Lấy transit cho khoảng thời gian (ngày mai, tuần, tháng, ...) từ DB cache
 * (bccm_transit_snapshots) và build text Zalo-friendly + /my-transit/ public URL.
 *
 * Output vars:
 *   {{n_X.ok}}                   — bool
 *   {{n_X.coachee_id}}           — int
 *   {{n_X.period_label}}         — "ngày mai", "tuần tới (7 ngày)"
 *   {{n_X.range_start}}          — YYYY-MM-DD
 *   {{n_X.range_end}}            — YYYY-MM-DD
 *   {{n_X.days_count}}           — số ngày
 *   {{n_X.transit_text}}         — Bảng transit Zalo-friendly (≤800 ký tự)
 *   {{n_X.days_json}}            — JSON array per-day ({date,date_label,retrograde,aspects[]})
 *   {{n_X.transit_foreach_md}}   — Luận giải foreach từng ngày + kết luận cuối
 *   {{n_X.best_day}}             — YYYY-MM-DD ngày tốt nhất theo scoring deterministic
 *   {{n_X.best_day_score}}       — điểm ngày tốt nhất
 *   {{n_X.final_recommendation}} — câu kết luận cuối dựa trên từng ngày
 *   {{n_X.transit_url}}          — /my-transit/ public URL
 *   {{n_X.transit_url_single}}   — link tổng quan duy nhất (backward-compatible)
 *   {{n_X.day_links_text}}       — danh sách link trực tiếp theo từng ngày (Zalo-friendly)
 *   {{n_X.has_transit}}          — 1 nếu có data transit meaningful, 0 nếu rỗng
 *   {{n_X.sync_30_url}}          — link mở /my-transit/ để sync/cập nhật 30 ngày
 *   {{n_X.natal_url}}            — /my-natal-chart/ public URL
 *   {{n_X.artifact_links}}       — object links chuẩn W3 (wheel/western/vedic/chinese/transit_*)
 *   {{n_X.retrograde_planets}}   — "Mercury ℞, Saturn ℞" hoặc ""
 *   {{n_X.key_aspects}}          — Top 3 aspect tight nhất (text)
 *   {{n_X.passages_md}}          — Full markdown cho LLM (không gửi Zalo)
 *
 * Fields:
 *   coachee_id   — int hoặc {{n1.coachee_id}}
 *   chat_id      — {{trigger.chat_id}} (để build transit URL)
 *   period       — day|3day|5day|week|10day|20day|month|year hoặc {{n1.period}}
 *   num_days     — số ngày explicit (ưu tiên hơn period) hoặc {{n1.num_days}}
 *   start_date   — "" (= ngày mai) hoặc YYYY-MM-DD
 *   outer_only   — true = chỉ Jupiter→Pluto+Chiron; false = all
 *   format       — "short" (Zalo ≤800c) hoặc "full" (markdown cho LLM)
 *
 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — new block for astro automation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-ASTRO-WORKFLOW (2026-07-03)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Run_Astro_Transit extends BizCity_Automation_Block_Base {

	const ASPECT_VI  = array(
		'Conjunction'    => 'Hợp',
		'Opposition'     => 'Đối',
		'Trine'          => 'Tam hợp',
		'Square'         => 'Vuông',
		'Sextile'        => 'Lục hợp',
		'Quincunx'       => 'Bất điều hòa',
		'Semi-Sextile'   => 'Bán lục hợp',
		'Sesquiquadrate' => 'Sesquiquadrate',
	);

	const PLANET_VI  = array(
		'Sun'     => '☉Mặt Trời', 'Moon'    => '☽Mặt Trăng',
		'Mercury' => '☿Sao Thủy', 'Venus'   => '♀Sao Kim',
		'Mars'    => '♂Sao Hỏa',  'Jupiter' => '♃Sao Mộc',
		'Saturn'  => '♄Sao Thổ',  'Uranus'  => '♅Thiên Vương',
		'Neptune' => '♆Hải Vương', 'Pluto'  => '♇Diêm Vương',
		'Chiron'  => '⚷Chiron',   'True Node' => '☊True Node',
	);

	const OUTER_PLANETS = array( 'Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','True Node','Mean Node' );

	public function id(): string   { return 'action.run_astro_transit'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Transit Day-by-Day',
			'short'    => 'run_astro_transit',
			'category' => 'astro',
			'color'    => '#7c3aed',
			'icon'     => 'moon',
			'defaults' => array(
				'label'       => 'Transit Day-by-Day',
				'coachee_id'  => '{{n1.coachee_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'period'      => '{{n1.period}}',
				'num_days'    => '{{n1.num_days}}',
				'start_date'  => '',
				'outer_only'  => true,
				'format'      => 'short',
			),
			'fields' => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị',       'type' => 'text' ),
				array( 'name' => 'coachee_id', 'label' => 'Coachee ID',          'type' => 'text',   'hint' => '{{n1.coachee_id}}' ),
				array( 'name' => 'chat_id',    'label' => 'Chat ID (Zalo)',       'type' => 'text',   'hint' => '{{trigger.chat_id}}' ),
				array( 'name' => 'period',     'label' => 'Khoảng thời gian',    'type' => 'select',
					'options' => array(
						array( 'value' => 'day',   'label' => 'Ngày mai (1 ngày)' ),
						array( 'value' => '3day',  'label' => '3 ngày tới' ),
						array( 'value' => '5day',  'label' => '5 ngày tới' ),
						array( 'value' => 'week',  'label' => 'Tuần tới (7 ngày)' ),
						array( 'value' => '10day', 'label' => '10 ngày tới' ),
						array( 'value' => '20day', 'label' => '20 ngày tới' ),
						array( 'value' => 'month', 'label' => 'Tháng tới (30 ngày)' ),
						array( 'value' => 'year',  'label' => 'Năm tới (365 ngày)' ),
					),
				),
				array( 'name' => 'num_days',    'label' => 'Số ngày (ưu tiên)',  'type' => 'number', 'hint' => '{{n1.num_days}}; >0 sẽ override period' ),
				array( 'name' => 'start_date',  'label' => 'Ngày bắt đầu',       'type' => 'text',   'hint' => 'YYYY-MM-DD hoặc để trống = ngày mai' ),
				array( 'name' => 'outer_only',  'label' => 'Chỉ sao chậm',       'type' => 'toggle', 'hint' => 'Bật = Jupiter→Pluto+Chiron; Tắt = tất cả' ),
				array( 'name' => 'format',      'label' => 'Định dạng',           'type' => 'select',
					'options' => array(
						array( 'value' => 'short', 'label' => 'Ngắn gọn (Zalo ≤800c)' ),
						array( 'value' => 'full',  'label' => 'Đầy đủ (Markdown cho LLM)' ),
					),
				),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — execute run_astro_transit
		$coachee_id  = (int) $this->resolve( $data['coachee_id'] ?? 0, $ctx );
		$chat_id     = (string) $this->resolve( $data['chat_id']    ?? '{{trigger.chat_id}}', $ctx );
		$period      = (string) $this->resolve( $data['period']     ?? 'week', $ctx );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — explicit horizon from classifier/output.
		$num_days    = (int) $this->resolve( $data['num_days']      ?? 0, $ctx );
		$start_raw   = (string) $this->resolve( $data['start_date'] ?? '', $ctx );
		$outer_only  = ! empty( $data['outer_only'] );
		$format      = (string) ( $data['format'] ?? 'short' );

		// [2026-07-06 Johnny Chu] HOTFIX — infer flexible horizon from trigger text:
		// supports "3 ngày tới", "5 ngày tới", "tuần tới", "ngày 07/07".
		$inferred = $this->infer_horizon_from_context( $ctx, $period, $num_days, $start_raw );
		$period   = (string) $inferred['period'];
		$num_days = (int) $inferred['num_days'];
		$start_raw= (string) $inferred['start_date'];

		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — backward compat: old workflow nodes have
		// start_date:"" hardcoded. When empty, auto-read from n1.start_date in ctx so existing
		// (non-re-imported) workflows also get correct start date without requiring DB update.
		if ( $start_raw === '' ) {
			$n1_start = (string) ( $ctx['n1']['start_date'] ?? '' );
			if ( $n1_start !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $n1_start ) ) {
				$start_raw = $n1_start;
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-ASTRO-WORKFLOW — cron self-setup fallback:
		// if coachee_id is empty, auto-resolve from workflow owner (runner injects _owner_user_id).
		if ( $coachee_id <= 0 ) {
			$coachee_id = (int) ( $ctx['n1']['coachee_id'] ?? 0 );
		}
		if ( $coachee_id <= 0 ) {
			$owner_user_id = (int) ( $ctx['_owner_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? get_current_user_id() );
			if ( $owner_user_id > 0 ) {
				$coachee_id = $this->resolve_self_coachee_id( $owner_user_id );
			}
		}

		// [2026-07-06 Johnny Chu] HOTFIX — enforce ownership: incoming coachee_id phải thuộc owner.
		$owner_user_id = (int) ( $ctx['_owner_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? get_current_user_id() );
		if ( $owner_user_id > 0 && $coachee_id > 0 && ! $this->coachee_belongs_to_user( $coachee_id, $owner_user_id ) ) {
			$incoming = $coachee_id;
			$coachee_id = $this->resolve_self_coachee_id( $owner_user_id );
			$this->note_event( 'run_astro_transit_coachee_override', array(
				'owner_user_id'        => $owner_user_id,
				'incoming_coachee_id'  => $incoming,
				'resolved_coachee_id'  => $coachee_id,
				'reason'               => 'cross_user_coachee_mismatch',
			) );
		}

		if ( $coachee_id <= 0 ) {
			return $this->_fail(
				'coachee_id_empty',
				'Không resolve được coachee_id. Vào hồ sơ chiêm tinh đặt hồ sơ chính chủ (is_self=1) hoặc điền coachee_id trong node Transit hôm nay.'
			);
		}

		// 1. Build date range
		$range = $this->build_range( $period, $start_raw, $num_days );

		// 2. Load transit snapshots from DB cache
		$snapshots = $this->load_snapshots( $coachee_id, $range );

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — FAA2 live fallback khi DB cache chưa có dữ liệu
		if ( empty( $snapshots ) ) {
			$live      = $this->load_live_snapshots( $coachee_id, $range );
			if ( ! empty( $live ) ) {
				// Persist vào DB để lần sau dùng cache — không block response
				$this->persist_snapshots( $coachee_id, $live );
				$snapshots = $live;
			} else {
				// Không có natal traits → queue async rebuild transit
				$this->queue_transit_rebuild( $coachee_id );
			}
		}

		$sync_30_url = $this->build_sync_30_url( $coachee_id, $chat_id );
		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — strict guard: transit rows exist but
		// aspects are empty across all days => treat as NO DATA and stop workflow to avoid
		// wasteful LLM replies built on empty transit context.
		if ( ! $this->has_meaningful_transit_data( $snapshots, $outer_only ) ) {
			$this->note_event( 'run_astro_transit_empty', array(
				'coachee_id'  => $coachee_id,
				'period'      => $period,
				'range_start' => $range['start'],
				'range_end'   => $range['end'],
			) );
			$this->notify_no_transit_sync( $chat_id, $sync_30_url, $range, $coachee_id );
			return new WP_Error(
				'transit_empty',
				'Chưa có dữ liệu transit day-by-day. Vui lòng sync/cập nhật 30 ngày trước khi luận giải.'
			);
		}

		// 3. Build outputs
		$transit_text = $this->build_transit_text( $snapshots, $format, $outer_only );
		$retro_str    = $this->extract_retrograde( $snapshots );
		$key_aspects  = $this->extract_key_aspects( $snapshots );
		$passages_md  = $this->build_passages_md( $snapshots, $range );
		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — per-day full markdown for LLM multi-day analysis
		$transit_days_md = $this->build_days_llm_md( $snapshots, $outer_only );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — machine-readable day rows for foreach message block.
		$day_items = $this->build_day_items( $snapshots, $outer_only, $coachee_id, $chat_id );
		$query_text = (string) ( $ctx['trigger']['text'] ?? $ctx['trigger']['message'] ?? '' );
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — unified each-day compose
		// (LLM-first + deterministic fallback) shared with Web Astro runtime.
		$day_analysis = $this->build_day_analysis_summary( $day_items, array(
			'query'        => $query_text,
			'period_label' => (string) ( $range['label'] ?? '' ),
			'surface'      => 'automation',
			'source_url'   => $this->build_transit_url( $coachee_id, $period, $chat_id ),
		) );
		$day_items = $this->apply_day_messages_to_items(
			$day_items,
			isset( $day_analysis['day_messages'] ) && is_array( $day_analysis['day_messages'] )
				? $day_analysis['day_messages']
				: array()
		);
		$day_links_text = $this->build_day_links_text( $day_items );
		if ( $range['days'] > 1 && $range['days'] <= 7 && ! empty( $day_analysis['transit_foreach_md'] ) ) {
			$transit_text = (string) $day_analysis['transit_foreach_md'];
		}

		$transit_url_single = $this->build_transit_url( $coachee_id, $period, $chat_id );
		// [2026-07-07 Johnny Chu] HOTFIX — for multi-day asks, expose per-day URLs right at transit_url
		// so legacy reply templates ({{n4.transit_url}}) can show one URL per day immediately.
		$transit_url = $this->build_transit_url_payload( $day_items, $transit_url_single, (int) $range['days'] );
		$natal_url    = function_exists( 'bccm_get_natal_chart_public_url' )
			? bccm_get_natal_chart_public_url( $coachee_id )
			: '';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 unified artifact links in automation ctx.
		$artifact_links = $this->build_artifact_links( $coachee_id, $period, $chat_id );

		$this->note_event( 'run_astro_transit_done', array(
			'coachee_id'   => $coachee_id,
			'period'       => $period,
			'range_start'  => $range['start'],
			'range_end'    => $range['end'],
			'snap_count'   => count( $snapshots ),
		) );

		return array(
			'ok'                 => true,
			'has_transit'        => 1,
			'coachee_id'         => $coachee_id,
			'period_label'       => $range['label'],
			'range_start'        => $range['start'],
			'range_end'          => $range['end'],
			'days_count'         => $range['days'],
			'transit_text'       => $transit_text,
			'day_links_text'     => $day_links_text,
			// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — per-day full data for LLM multi-day analysis
			'transit_days_md'    => $transit_days_md,
			'days_json'          => wp_json_encode( $day_items, JSON_UNESCAPED_UNICODE ),
			'transit_foreach_md' => (string) ( $day_analysis['transit_foreach_md'] ?? '' ),
			'best_day'           => (string) ( $day_analysis['best_day'] ?? '' ),
			'best_day_score'     => (float) ( $day_analysis['best_day_score'] ?? 0 ),
			'final_recommendation' => (string) ( $day_analysis['final_recommendation'] ?? '' ),
			'transit_url'        => $transit_url,
			'transit_url_single' => $transit_url_single,
			'sync_30_url'        => $sync_30_url,
			'natal_url'          => $natal_url,
			'artifact_links'     => $artifact_links,
			'retrograde_planets' => $retro_str,
			'key_aspects'        => $key_aspects,
			'passages_md'        => $passages_md,
		);
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * [2026-07-05 Johnny Chu] PHASE-ASTRO-WORKFLOW — resolve default self coachee by owner user.
	 */
	private function resolve_self_coachee_id( int $user_id ): int {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return 0; }

		if ( function_exists( 'bccm_get_self_coachee' ) ) {
			$row = bccm_get_self_coachee( $user_id );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return (int) $row['id'];
			}
		}

		if ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			$row = bccm_get_or_create_user_coachee( $user_id, 'ADMINCHAT', 'mental_coach' );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return (int) $row['id'];
			}
		}

		return 0;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — ownership guard for coachee_id from upstream nodes.
	 */
	private function coachee_belongs_to_user( int $coachee_id, int $user_id ): bool {
		$coachee_id = (int) $coachee_id;
		$user_id    = (int) $user_id;
		if ( $coachee_id <= 0 || $user_id <= 0 ) { return false; }

		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return true;
		}

		$ok = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id,
			$user_id
		) );

		return $ok === 1;
	}

	private function build_range( string $period, string $start_raw, int $num_days = 0 ): array {
		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — use WP current_time() not PHP strtotime('+1 day').
		// PHP server runs UTC; Vietnam is UTC+7. At 00:38 VN time = still yesterday UTC.
		// strtotime('+1 day') from UTC gives today-Vietnam, not tomorrow-Vietnam.
		// Using current_time('Y-m-d') respects WP timezone setting → correct.
		$today = current_time( 'Y-m-d' );
		$start = ( $start_raw !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) )
			? $start_raw
			: date( 'Y-m-d', strtotime( $today . ' +1 day' ) );

		$period_days = array(
			'day'   => 1,   '3day'  => 3,  'week'  => 7,
			'5day'  => 5,
			'10day' => 10,  '20day' => 20, 'month' => 30, 'year' => 365,
		);
		$label_map = array(
			'day'   => 'ngày mai',
			'3day'  => '3 ngày tới',
			'5day'  => '5 ngày tới',
			'week'  => 'tuần tới (7 ngày)',
			'10day' => '10 ngày tới',
			'20day' => '20 ngày tới',
			'month' => 'tháng tới (30 ngày)',
			'year'  => 'năm tới',
		);
		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — dynamic label for day period based on offset.
		// Avoids showing "ngày mai" for start_date that is actually "hôm nay" or "ngày kia".
		if ( $period === 'day' ) {
			$offset = (int) floor( ( strtotime( $start ) - strtotime( $today ) ) / 86400 );
			if ( $offset <= 0 ) {
				$label_map['day'] = 'hôm nay';
			} elseif ( $offset === 1 ) {
				$label_map['day'] = 'ngày mai';
			} elseif ( $offset === 2 ) {
				$label_map['day'] = 'ngày kia';
			} else {
				$label_map['day'] = $start . ' (' . $offset . ' ngày nữa)';
			}
		}
		$days = $period_days[ $period ] ?? 7;
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — num_days override to avoid horizon mismatch.
		if ( $num_days > 0 ) {
			$days = min( 365, $num_days );
			if ( $days === 1 ) {
				$label_map['day'] = $label_map['day'] ?? 'hôm nay';
			} else {
				$label_map[ $period ] = $days . ' ngày tới';
			}
		}
		$end  = date( 'Y-m-d', strtotime( $start . ' +' . ( $days - 1 ) . ' days' ) );
		return array(
			'start' => $start,
			'end'   => $end,
			'days'  => $days,
			'label' => $label_map[ $period ] ?? $period,
		);
	}

	private function load_live_snapshots( int $coachee_id, array $range ): array {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — Gọi FAA2 transit_range trực tiếp khi DB cache chưa
		// có dữ liệu transit (user chưa bấm "Cập nhật 30 ngày"). Planet positions có 1h cache shared →
		// mọi request cùng ngày đều tiết kiệm API call (chỉ 1 API call/ngày mới).
		global $wpdb;
		$tbl_astro = $wpdb->prefix . 'bccm_astro';
		$row       = $wpdb->get_row( $wpdb->prepare(
			"SELECT traits FROM {$tbl_astro} WHERE coachee_id = %d AND chart_type = 'western' LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $row || empty( $row['traits'] ) ) { return array(); }
		$traits        = json_decode( (string) $row['traits'], true );
		$natal_planets = ( is_array( $traits ) && isset( $traits['positions'] ) )
			? (array) $traits['positions']
			: array();
		if ( empty( $natal_planets ) ) { return array(); }

		if ( ! class_exists( 'BizCity_Astro_Router' ) || ! class_exists( 'Astro_Provider_FAA2_Western' ) ) {
			return array();
		}
		if ( method_exists( 'BizCity_Astro_Router', 'boot' ) ) {
			BizCity_Astro_Router::boot();
		}
		$faa2 = BizCity_Astro_Router::get_provider( 'faa2_western' );
		if ( ! $faa2 || ! method_exists( $faa2, 'transit_range' ) || ! $faa2->is_ready() ) {
			return array();
		}

		$result = $faa2->transit_range( array(
			'start_date'    => $range['start'],
			'num_days'      => $range['days'],
			'natal_planets' => $natal_planets,
			'outer_only'    => true,
		) );

		if ( empty( $result['success'] ) || empty( $result['daily'] ) ) { return array(); }

		// Convert FAA2 daily format → snapshots format (compatible with build_transit_text)
		$out = array();
		foreach ( $result['daily'] as $day ) {
			$date = (string) ( $day['date'] ?? '' );
			if ( $date === '' ) { continue; }

			// FAA2 transit_planets keyed by planet name; build array with 'name' + 'is_retro'
			$planets_arr = array();
			foreach ( (array) ( $day['transit_planets'] ?? array() ) as $pname => $pdata ) {
				$is_r          = (bool) ( $pdata['is_retro'] ?? $pdata['retrograde'] ?? false );
				$planets_arr[] = array(
					'name'     => (string) $pname,
					'sign_en'  => (string) ( $pdata['sign_en'] ?? $pdata['sign'] ?? '' ),
					'house'    => (int)    ( $pdata['house'] ?? 0 ),
					'is_retro' => $is_r,
					'isRetro'  => $is_r ? 'true' : 'false',
				);
			}

			$out[ $date ] = array(
				'date'    => $date,
				'planets' => $planets_arr,
				'aspects' => (array) ( $day['aspects'] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * Persist FAA2 live snapshots vào bccm_transit_snapshots để lần sau cache hit.
	 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — persist live data.
	 */
	private function persist_snapshots( int $coachee_id, array $snapshots ): void {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';
		// Guard: bảng phải tồn tại
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return;
		}
		foreach ( $snapshots as $date => $snap ) {
			$planets_json = wp_json_encode( (array) ( $snap['planets'] ?? array() ), JSON_UNESCAPED_UNICODE );
			$aspects_json = wp_json_encode( (array) ( $snap['aspects'] ?? array() ), JSON_UNESCAPED_UNICODE );
			// [2026-07-06 Johnny Chu] PHASE-ASTRO-WORKFLOW — fix snap_date → target_date (canonical column).
			// INSERT IGNORE: không ghi đè row đã có từ daily cron
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$tbl} (coachee_id, target_date, planets_json, aspects_json, fetched_at)
				 VALUES (%d, %s, %s, %s, %s)",
				$coachee_id,
				(string) $date,
				(string) $planets_json,
				(string) $aspects_json,
				current_time( 'mysql' )
			) );
		}
	}

	/**
	 * Queue async rebuild transit khi không có natal traits (FAA2 cần natal planets).
	 * Dùng bcpro_async_rebuild_transit nếu có, fallback wp_schedule_single_event.
	 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — queue fallback.
	 */
	private function queue_transit_rebuild( int $coachee_id ): void {
		if ( $coachee_id <= 0 ) { return; }
		// Resolve owner uid từ bccm_coachees
		global $wpdb;
		$uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id = %d LIMIT 1",
			$coachee_id
		) );
		if ( $uid <= 0 ) { return; }
		$args = array( $coachee_id, $uid );
		if ( ! wp_next_scheduled( 'bcpro_async_rebuild_transit', $args ) ) {
			wp_schedule_single_event( time() + 10, 'bcpro_async_rebuild_transit', $args );
		}
	}

	private function load_snapshots( int $coachee_id, array $range ): array {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';

		// R-SHOW-TABLES: use information_schema, not SHOW TABLES
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) ) {
			if ( ! _bizcity_legacy_tbl_exists( $tbl ) ) { return array(); }
		}

		// [2026-07-06 Johnny Chu] PHASE-ASTRO-WORKFLOW — fix snap_date → target_date (canonical column).
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT target_date, planets_json, aspects_json
			 FROM {$tbl}
			 WHERE coachee_id = %d
			   AND target_date BETWEEN %s AND %s
			 ORDER BY target_date ASC",
			$coachee_id, $range['start'], $range['end']
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$planets = json_decode( (string) ( $row['planets_json'] ?? '' ), true );
			$aspects = json_decode( (string) ( $row['aspects_json'] ?? '' ), true );
			$out[ $row['target_date'] ] = array(
				'date'    => $row['target_date'],
				'planets' => is_array( $planets ) ? $planets : array(),
				'aspects' => is_array( $aspects ) ? $aspects : array(),
			);
		}
		return $out;
	}

	private function build_transit_text( array $snapshots, string $format, bool $outer_only ): string {
		if ( empty( $snapshots ) ) {
			return '⚠️ Chưa có dữ liệu transit. Vui lòng bấm "Cập nhật 30 ngày" trong trang chiêm tinh.';
		}
		$is_short    = $format !== 'full';
		$budget      = 780;
		$total_chars = 0;
		$lines       = array();
		$day_names   = array( 'CN','T2','T3','T4','T5','T6','T7' );

		foreach ( $snapshots as $date => $snap ) {
			$d        = new DateTime( $date );
			$day_str  = $d->format( 'd/m' ) . ' (' . $day_names[ (int) $d->format( 'w' ) ] . ')';
			$aspects  = is_array( $snap['aspects'] ) ? $snap['aspects'] : array();
			$planets  = is_array( $snap['planets'] ) ? $snap['planets'] : array();

			// Retrograde
			$retro = array();
			foreach ( $planets as $p ) {
				$pname = (string) ( $p['name'] ?? ( $p['planet']['en'] ?? '' ) );
				if ( $outer_only && ! in_array( $pname, self::OUTER_PLANETS, true ) ) { continue; }
				$is_r  = isset( $p['is_retro'] )
					? (bool) $p['is_retro']
					: ( strtolower( (string) ( $p['isRetro'] ?? 'false' ) ) === 'true' );
				if ( $is_r ) {
					$retro[] = ( self::PLANET_VI[ $pname ] ?? $pname ) . '℞';
				}
			}

			// Top aspects
			usort( $aspects, function ( $a, $b ) {
				return ( (float) ( $a['orb'] ?? 9 ) ) <=> ( (float) ( $b['orb'] ?? 9 ) );
			} );
			$asp_strs = array();
			$orb_limit = $is_short ? 2.5 : 4.0;
			$asp_cap   = $is_short ? 2 : 5;
			$shown     = 0;
			foreach ( $aspects as $asp ) {
				if ( (float) ( $asp['orb'] ?? 9 ) > $orb_limit ) { continue; }
				$tp  = self::PLANET_VI[ $asp['transit_planet'] ?? '' ] ?? ( $asp['transit_planet'] ?? '' );
				$np  = self::PLANET_VI[ $asp['natal_planet']   ?? '' ] ?? ( $asp['natal_planet']   ?? '' );
				$a   = self::ASPECT_VI[ $asp['aspect']         ?? '' ] ?? ( $asp['aspect']         ?? '' );
				$orb = isset( $asp['orb'] ) ? round( (float) $asp['orb'], 1 ) . '°' : '';
				if ( $outer_only ) {
					$tp_raw = $asp['transit_planet'] ?? '';
					if ( ! in_array( $tp_raw, self::OUTER_PLANETS, true ) ) { continue; }
				}
				$asp_strs[] = "{$tp} {$a} natal {$np} ({$orb})";
				if ( ++$shown >= $asp_cap ) { break; }
			}

			$day_line = "📅 {$day_str}";
			if ( ! empty( $retro ) )    { $day_line .= ' | ℞: ' . implode( ', ', $retro ); }
			if ( ! empty( $asp_strs ) ) { $day_line .= "\n  ⟶ " . implode( "\n  ⟶ ", $asp_strs ); }

			if ( $is_short && ( $total_chars + strlen( $day_line ) + 2 ) > $budget ) {
				$remaining = count( $snapshots ) - count( $lines );
				$lines[]   = '... (' . $remaining . ' ngày tiếp theo)';
				break;
			}
			$lines[]      = $day_line;
			$total_chars += strlen( $day_line ) + 2;
		}

		return implode( "\n\n", $lines );
	}

	private function extract_retrograde( array $snapshots ): string {
		$seen = array();
		foreach ( $snapshots as $snap ) {
			foreach ( (array) ( $snap['planets'] ?? array() ) as $p ) {
				$pname = (string) ( $p['name'] ?? ( $p['planet']['en'] ?? '' ) );
				$is_r  = isset( $p['is_retro'] )
					? (bool) $p['is_retro']
					: ( strtolower( (string) ( $p['isRetro'] ?? 'false' ) ) === 'true' );
				if ( $is_r ) { $seen[ $pname ] = true; }
			}
		}
		if ( empty( $seen ) ) { return ''; }
		$parts = array();
		foreach ( array_keys( $seen ) as $n ) {
			$parts[] = ( self::PLANET_VI[ $n ] ?? $n ) . ' ℞';
		}
		return implode( ', ', $parts );
	}

	private function extract_key_aspects( array $snapshots ): string {
		$tightest = array();
		$seen_key = array();
		foreach ( $snapshots as $snap ) {
			foreach ( (array) ( $snap['aspects'] ?? array() ) as $asp ) {
				$key = ( $asp['transit_planet'] ?? '' ) . '_' . ( $asp['natal_planet'] ?? '' ) . '_' . ( $asp['aspect'] ?? '' );
				$orb = (float) ( $asp['orb'] ?? 9 );
				if ( ! isset( $seen_key[ $key ] ) || $orb < $seen_key[ $key ] ) {
					$seen_key[ $key ] = $orb;
					$tightest[ $key ] = $asp;
				}
			}
		}
		uasort( $tightest, function ( $a, $b ) {
			return ( (float) ( $a['orb'] ?? 9 ) ) <=> ( (float) ( $b['orb'] ?? 9 ) );
		} );
		$out = array();
		foreach ( array_slice( array_values( $tightest ), 0, 3 ) as $asp ) {
			$tp = self::PLANET_VI[ $asp['transit_planet'] ?? '' ] ?? ( $asp['transit_planet'] ?? '' );
			$np = self::PLANET_VI[ $asp['natal_planet']   ?? '' ] ?? ( $asp['natal_planet']   ?? '' );
			$a  = self::ASPECT_VI[ $asp['aspect']         ?? '' ] ?? ( $asp['aspect']         ?? '' );
			$out[] = "{$tp} {$a} natal {$np}";
		}
		return implode( ' · ', $out );
	}

	// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — per-day full markdown for LLM multi-day analysis.
	// Không bị cắt budget. Mỗi ngày là 1 section rõ ràng với toàn bộ aspects + retrograde để LLM
	// có thể phân tích từng ngày độc lập trước khi kết luận.
	private function build_days_llm_md( array $snapshots, bool $outer_only ): string {
		if ( empty( $snapshots ) ) { return ''; }
		$day_names = array( 'Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy' );
		$sections  = array();

		foreach ( $snapshots as $date => $snap ) {
			$d        = new DateTime( $date );
			$dow      = $day_names[ (int) $d->format( 'w' ) ];
			$heading  = "=== NGÀY " . $d->format( 'd/m/Y' ) . " ({$dow}) ===";

			$planets  = is_array( $snap['planets'] ?? null ) ? $snap['planets'] : array();
			$aspects  = is_array( $snap['aspects'] ?? null ) ? $snap['aspects'] : array();

			// Retrograde planets
			$retro = array();
			foreach ( $planets as $p ) {
				$pname = (string) ( $p['name'] ?? ( $p['planet']['en'] ?? '' ) );
				if ( $outer_only && ! in_array( $pname, self::OUTER_PLANETS, true ) ) { continue; }
				$is_r = isset( $p['is_retro'] )
					? (bool) $p['is_retro']
					: ( strtolower( (string) ( $p['isRetro'] ?? 'false' ) ) === 'true' );
				if ( $is_r ) { $retro[] = ( self::PLANET_VI[ $pname ] ?? $pname ) . ' ℞'; }
			}

			// All aspects (full, no char limit)
			usort( $aspects, function ( $a, $b ) {
				return ( (float) ( $a['orb'] ?? 9 ) ) <=> ( (float) ( $b['orb'] ?? 9 ) );
			} );
			$asp_lines = array();
			foreach ( $aspects as $asp ) {
				$orb = (float) ( $asp['orb'] ?? 9 );
				if ( $orb > 4.0 ) { continue; }
				$tp_raw = $asp['transit_planet'] ?? '';
				if ( $outer_only && ! in_array( $tp_raw, self::OUTER_PLANETS, true ) ) { continue; }
				$tp = self::PLANET_VI[ $tp_raw ] ?? $tp_raw;
				$np = self::PLANET_VI[ $asp['natal_planet'] ?? '' ] ?? ( $asp['natal_planet'] ?? '' );
				$a  = self::ASPECT_VI[ $asp['aspect'] ?? '' ] ?? ( $asp['aspect'] ?? '' );
				$asp_lines[] = "- {$tp} {$a} natal {$np} (orb " . round( $orb, 2 ) . '°)';
			}

			$block = array( $heading );
			if ( ! empty( $retro ) ) {
				$block[] = 'Sao nghịch hành: ' . implode( ', ', $retro );
			}
			if ( ! empty( $asp_lines ) ) {
				$block[] = 'Aspects nổi bật:';
				foreach ( $asp_lines as $al ) { $block[] = $al; }
			} else {
				$block[] = '(Không có aspect đáng kể trong ngày này)';
			}
			$sections[] = implode( "\n", $block );
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Build compact structured rows for each day to feed reply_zalo_each_day.
	 *
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — foreach-ready day list.
	 */
	private function build_day_items( array $snapshots, bool $outer_only, int $coachee_id, string $chat_id ): array {
		$day_names = array( 'CN','T2','T3','T4','T5','T6','T7' );
		$out       = array();
		$prev_anchor = '';
		$total_days  = count( $snapshots );
		$idx         = 0;

		foreach ( $snapshots as $date => $snap ) {
			$d          = new DateTime( $date );
			$date_label = $d->format( 'd/m' ) . ' (' . $day_names[ (int) $d->format( 'w' ) ] . ')';
			$planets    = is_array( $snap['planets'] ?? null ) ? $snap['planets'] : array();
			$aspects    = is_array( $snap['aspects'] ?? null ) ? $snap['aspects'] : array();

			$retro = array();
			foreach ( $planets as $p ) {
				$pname = (string) ( $p['name'] ?? ( $p['planet']['en'] ?? '' ) );
				if ( $outer_only && ! in_array( $pname, self::OUTER_PLANETS, true ) ) { continue; }
				$is_r = isset( $p['is_retro'] )
					? (bool) $p['is_retro']
					: ( strtolower( (string) ( $p['isRetro'] ?? 'false' ) ) === 'true' );
				if ( $is_r ) {
					$retro[] = self::PLANET_VI[ $pname ] ?? $pname;
				}
			}

			usort( $aspects, function ( $a, $b ) {
				return ( (float) ( $a['orb'] ?? 9 ) ) <=> ( (float) ( $b['orb'] ?? 9 ) );
			} );

			$asp_lines = array();
			foreach ( $aspects as $asp ) {
				$orb = (float) ( $asp['orb'] ?? 9 );
				if ( $orb > 4.0 ) { continue; }
				$tp_raw = (string) ( $asp['transit_planet'] ?? '' );
				if ( $outer_only && ! in_array( $tp_raw, self::OUTER_PLANETS, true ) ) { continue; }
				$tp = self::PLANET_VI[ $tp_raw ] ?? $tp_raw;
				$np = self::PLANET_VI[ $asp['natal_planet'] ?? '' ] ?? ( $asp['natal_planet'] ?? '' );
				$a  = self::ASPECT_VI[ $asp['aspect'] ?? '' ] ?? ( $asp['aspect'] ?? '' );
				$asp_lines[] = $tp . ' ' . $a . ' natal ' . $np . ' (' . round( $orb, 1 ) . '°)';
				if ( count( $asp_lines ) >= 4 ) { break; }
			}

			$retro_str = implode( ', ', $retro );
			$score = round( $this->score_day_item( array(
				'aspects'    => $asp_lines,
				'retrograde' => $retro_str,
			) ), 2 );
			$analysis = $this->build_day_progressive_analysis(
				$asp_lines,
				$retro_str,
				$score,
				$prev_anchor,
				$coachee_id
			);

			$out[] = array(
				'date'       => (string) $date,
				'date_label' => $date_label,
				'day_index'  => $idx + 1,
				'day_total'  => $total_days,
				// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — direct URL for Zalo (no HTML anchor).
				'day_url'    => $this->build_transit_day_url( $coachee_id, (string) $date, $chat_id ),
				'retrograde' => $retro_str,
				'score'      => $score,
				'analysis'   => $analysis,
				'aspects'    => $asp_lines,
			);

			$prev_anchor = $this->build_day_anchor( $asp_lines, $score );
			$idx++;
		}

		return $out;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — progressive day analysis (day N references day N-1).
	 */
	private function build_day_progressive_analysis( array $aspects, string $retro_str, float $score, string $prev_anchor, int $coachee_id ): string {
		$tone = 'nhịp năng lượng trung tính';
		if ( $score >= 56 ) {
			$tone = 'năng lượng thuận để chủ động';
		} elseif ( $score <= 46 ) {
			$tone = 'năng lượng thử thách, cần đi chậm';
		}

		$focus = ! empty( $aspects )
			? 'Transit nổi bật: ' . $aspects[0] . '.'
			: 'Không có aspect mạnh, ưu tiên quan sát thay vì quyết định lớn.';

		$carry = $prev_anchor !== ''
			? 'Tiếp nối ngày trước: ' . $prev_anchor . '. '
			: 'Ngày mở chu kỳ mới. ';

		$retro_note = $retro_str !== ''
			? 'Có yếu tố nghịch hành: ' . $retro_str . '. '
			: '';

		$advice = 'Nên giữ kỷ luật, chốt từng việc nhỏ rồi mới mở rộng.';
		if ( $score >= 56 ) {
			$advice = 'Nên chủ động chốt việc quan trọng, ưu tiên việc đang có đà thuận.';
		} elseif ( $score <= 46 ) {
			$advice = 'Nên rà soát rủi ro và tránh quyết định nóng, nhất là tài chính/công việc.';
		}

		return trim(
			'Dựa trên hồ sơ natal chính chủ #' . $coachee_id . ', ' . $tone . '. '
			. $carry
			. $focus . ' '
			. $retro_note
			. $advice
		);
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — compact anchor passed to next day analysis.
	 */
	private function build_day_anchor( array $aspects, float $score ): string {
		if ( ! empty( $aspects ) ) {
			return $aspects[0] . ' | score=' . round( $score, 1 );
		}
		return 'nhịp nhẹ, score=' . round( $score, 1 );
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — infer period/num_days/start_date from context text.
	 */
	private function infer_horizon_from_context( array $ctx, string $period, int $num_days, string $start_raw ): array {
		$period_raw = trim( (string) $period );
		$period_is_tpl = strpos( $period_raw, '{{' ) !== false;
		$period = $this->normalize_period_key( $period_raw );
		$start_raw = trim( (string) $start_raw );
		if ( $start_raw !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_raw ) ) {
			$start_raw = '';
		}

		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$text = (string) ( $trigger['text'] ?? $trigger['message'] ?? '' );
		$hint = $this->extract_horizon_hint_from_text( $text );

		if ( $hint['start_date'] !== '' && $start_raw === '' ) {
			$start_raw = $hint['start_date'];
		}
		if ( $hint['num_days'] > 0 && ( $num_days <= 0 || $period_is_tpl || isset( $ctx['n1'] ) ) ) {
			$num_days = (int) $hint['num_days'];
		}
		if ( $hint['period'] !== '' && ( $period === '' || $period_is_tpl || $num_days <= 0 ) ) {
			$period = (string) $hint['period'];
		}

		if ( $period === '' ) {
			$period = 'week';
		}

		return array(
			'period'     => $period,
			'num_days'   => max( 0, (int) $num_days ),
			'start_date' => $start_raw,
		);
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — parse Vietnamese horizon hints from raw text.
	 */
	private function extract_horizon_hint_from_text( string $text ): array {
		$out = array(
			'period'     => '',
			'num_days'   => 0,
			'start_date' => '',
		);
		$txt = $this->normalize_query_text( $text );
		if ( $txt === '' ) {
			return $out;
		}

		$today = current_time( 'Y-m-d' );
		if ( strpos( $txt, 'hom nay' ) !== false ) {
			$out['period']     = 'day';
			$out['num_days']   = 1;
			$out['start_date'] = $today;
		}
		if ( strpos( $txt, 'ngay kia' ) !== false ) {
			$out['period']     = 'day';
			$out['num_days']   = 1;
			$out['start_date'] = date( 'Y-m-d', strtotime( $today . ' +2 day' ) );
		} elseif ( strpos( $txt, 'ngay mai' ) !== false ) {
			$out['period']     = 'day';
			$out['num_days']   = 1;
			$out['start_date'] = date( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		}

		if ( preg_match( '/(\d{1,3})\s*ngay/u', $txt, $m ) ) {
			$n = (int) $m[1];
			if ( $n > 0 ) {
				$out['num_days'] = min( 365, $n );
				$out['period']   = $this->period_from_days( $out['num_days'] );
			}
		}
		if ( strpos( $txt, 'tuan toi' ) !== false ) {
			$out['period']   = 'week';
			$out['num_days'] = 7;
		}
		if ( strpos( $txt, 'thang toi' ) !== false ) {
			$out['period']   = 'month';
			$out['num_days'] = 30;
		}

		if ( preg_match( '/ngay\s*(\d{1,2})[\/\-\.](\d{1,2})(?:[\/\-\.](\d{2,4}))?/u', $txt, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = isset( $m[3] ) ? (int) $m[3] : (int) date( 'Y', strtotime( $today ) );
			if ( isset( $m[3] ) && $year < 100 ) {
				$year += 2000;
			}
			if ( checkdate( $month, $day, $year ) ) {
				$candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
				if ( ! isset( $m[3] ) && $candidate < $today ) {
					$year++;
					if ( checkdate( $month, $day, $year ) ) {
						$candidate = sprintf( '%04d-%02d-%02d', $year, $month, $day );
					}
				}
				$out['start_date'] = $candidate;
				if ( $out['num_days'] <= 0 ) {
					$out['period']   = 'day';
					$out['num_days'] = 1;
				}
			}
		}

		return $out;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — normalize user phrase to ascii for cheap matching.
	 */
	private function normalize_query_text( string $text ): string {
		$text = trim( (string) $text );
		if ( $text === '' ) { return ''; }
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		return strtolower( $text );
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — normalize period key from cfg/context.
	 */
	private function normalize_period_key( string $period ): string {
		$k = strtolower( trim( $period ) );
		if ( $k === '' || strpos( $k, '{{' ) !== false ) { return ''; }
		$map = array(
			'day' => 'day',
			'1day' => 'day',
			'3day' => '3day',
			'5day' => '5day',
			'week' => 'week',
			'7day' => 'week',
			'10day' => '10day',
			'20day' => '20day',
			'month' => 'month',
			'30day' => 'month',
			'year' => 'year',
			'365day' => 'year',
		);
		return isset( $map[ $k ] ) ? $map[ $k ] : '';
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — reverse map day count to canonical period key.
	 */
	private function period_from_days( int $days ): string {
		$days = max( 1, (int) $days );
		if ( $days === 1 ) { return 'day'; }
		if ( $days === 3 ) { return '3day'; }
		if ( $days === 5 ) { return '5day'; }
		if ( $days === 7 ) { return 'week'; }
		if ( $days === 10 ) { return '10day'; }
		if ( $days === 20 ) { return '20day'; }
		if ( $days >= 30 && $days < 365 ) { return 'month'; }
		if ( $days >= 365 ) { return 'year'; }
		return 'week';
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — build per-day direct link list for Zalo.
	 */
	private function build_day_links_text( array $day_items ): string {
		if ( empty( $day_items ) ) { return ''; }
		$lines = array( '🔗 Link transit theo từng ngày:' );
		foreach ( $day_items as $day ) {
			$url = trim( (string) ( $day['day_url'] ?? '' ) );
			if ( $url === '' ) { continue; }
			$lbl = (string) ( $day['date_label'] ?? $day['date'] ?? '' );
			$lines[] = '• ' . $lbl . ': ' . $url;
		}
		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/**
	 * [2026-07-07 Johnny Chu] HOTFIX — normalize transit_url output for legacy templates.
	 * Multi-day: newline list of per-day URLs (period=day&date=YYYY-MM-DD).
	 * Single-day: keep one URL string to preserve old behavior.
	 */
	private function build_transit_url_payload( array $day_items, string $single_url, int $days ): string {
		if ( $days <= 1 ) {
			return $single_url;
		}

		$urls = array();
		foreach ( $day_items as $day ) {
			$url = trim( (string) ( $day['day_url'] ?? '' ) );
			if ( $url === '' ) { continue; }
			$urls[] = $url;
		}

		if ( empty( $urls ) ) {
			return $single_url;
		}

		return implode( "\n", array_values( array_unique( $urls ) ) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — transit data is meaningful only when
	 * at least one day has at least one aspect (after optional outer-only filter).
	 */
	private function has_meaningful_transit_data( array $snapshots, bool $outer_only ): bool {
		if ( empty( $snapshots ) ) { return false; }
		foreach ( $snapshots as $snap ) {
			$aspects = is_array( $snap['aspects'] ?? null ) ? $snap['aspects'] : array();
			foreach ( $aspects as $asp ) {
				$tp_raw = (string) ( $asp['transit_planet'] ?? '' );
				if ( $outer_only && ! in_array( $tp_raw, self::OUTER_PLANETS, true ) ) { continue; }
				return true;
			}
		}
		return false;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — direct per-day transit URL.
	 */
	private function build_transit_day_url( int $coachee_id, string $date, string $chat_id ): string {
		$date = trim( $date );
		if ( $coachee_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		// [2026-07-07 Johnny Chu] HOTFIX — force canonical per-day URL format:
		// ...?period=day&date=YYYY-MM-DD
		$base = '';
		if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
			$base = (string) bcpro_get_transit_public_url( $coachee_id, 'day' );
		}
		if ( $base === '' ) {
			$base = $this->build_transit_url( $coachee_id, 'day', $chat_id );
		}
		if ( $base === '' ) { return ''; }

		$base = remove_query_arg( array( 'day', 'date' ), $base );
		return (string) add_query_arg(
			array(
				'period' => 'day',
				'date'   => $date,
			),
			$base
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — URL for user-triggered 30-day sync.
	 */
	private function build_sync_30_url( int $coachee_id, string $chat_id ): string {
		if ( $coachee_id <= 0 ) { return ''; }
		if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' )
			&& method_exists( 'BizCoach_Pro_Transit_Public_Router', 'get_public_url' ) ) {
			return (string) BizCoach_Pro_Transit_Public_Router::get_public_url(
				$coachee_id,
				'month',
				array( 'regenerate' => 1 )
			);
		}
		$base = $this->build_transit_url( $coachee_id, 'month', $chat_id );
		if ( $base === '' ) { return ''; }
		$sep = strpos( $base, '?' ) !== false ? '&' : '?';
		return $base . $sep . 'regenerate=1';
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-IMG-TPL — notify user to sync 30-day transit and stop.
	 */
	private function notify_no_transit_sync( string $chat_id, string $sync_30_url, array $range, int $coachee_id ): void {
		if ( $chat_id === '' || ! function_exists( 'bizcity_channel_send' ) ) { return; }
		$msg = '⚠️ Hiện chưa có dữ liệu transit từng ngày để luận giải chính xác.'
			. "\n" . 'Vui lòng mở link dưới đây và bấm Sync/Cập nhật 30 ngày tới, rồi nhắn lại.';
		if ( $sync_30_url !== '' ) {
			$msg .= "\n\n" . '🔗 ' . $sync_30_url;
		}
		bizcity_channel_send( $chat_id, $msg );
		$this->note_event( 'run_astro_transit_sync_prompt_sent', array(
			'coachee_id'  => $coachee_id,
			'range_start' => (string) ( $range['start'] ?? '' ),
			'range_end'   => (string) ( $range['end'] ?? '' ),
			'has_url'     => $sync_30_url !== '' ? 1 : 0,
		) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — build foreach-ready day analysis + final recommendation.
	 */
	private function build_day_analysis_summary( array $day_items, array $opts = array() ): array {
		if ( empty( $day_items ) ) {
			return array(
				'transit_foreach_md'   => '',
				'best_day'             => '',
				'best_day_score'       => 0,
				'final_recommendation' => '',
				'day_messages'         => array(),
			);
		}

		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — shared composer first.
		if ( class_exists( 'BizCity_TwinBrain_Astro_Transit_Eachday_Composer' ) ) {
			$composed = BizCity_TwinBrain_Astro_Transit_Eachday_Composer::instance()->compose( $day_items, array(
				'query'                 => (string) ( $opts['query'] ?? '' ),
				'period_label'          => (string) ( $opts['period_label'] ?? '' ),
				'surface'               => (string) ( $opts['surface'] ?? 'automation' ),
				'source_url'            => (string) ( $opts['source_url'] ?? '' ),
				'target_tokens_per_day' => 620,
			) );
			if ( ! empty( $composed['success'] ) && ! empty( $composed['transit_foreach_md'] ) ) {
				return array(
					'transit_foreach_md'   => (string) $composed['transit_foreach_md'],
					'best_day'             => (string) ( $composed['best_day'] ?? '' ),
					'best_day_score'       => (float) ( $composed['best_day_score'] ?? 0 ),
					'final_recommendation' => (string) ( $composed['final_recommendation'] ?? '' ),
					'day_messages'         => isset( $composed['day_messages'] ) && is_array( $composed['day_messages'] )
						? $composed['day_messages']
						: array(),
				);
			}
		}

		$best_day       = '';
		$best_day_score = -INF;
		$lines          = array(
			'📊 Đánh giá transit theo từng ngày (deterministic):',
		);

		foreach ( $day_items as $idx => $day ) {
			$date       = (string) ( $day['date'] ?? '' );
			$date_label = (string) ( $day['date_label'] ?? $date );
			$score      = round( $this->score_day_item( is_array( $day ) ? $day : array() ), 2 );
			$aspects    = isset( $day['aspects'] ) && is_array( $day['aspects'] ) ? $day['aspects'] : array();
			$retro_str  = (string) ( $day['retrograde'] ?? '' );
			$retro_cnt  = $retro_str === '' ? 0 : count( array_filter( array_map( 'trim', explode( ',', $retro_str ) ) ) );

			$lines[] = ( $idx + 1 ) . '. ' . $date_label . ': score=' . $score
				. ' | aspects=' . count( $aspects ) . ' | retro=' . $retro_cnt;

			if ( $score > $best_day_score ) {
				$best_day_score = $score;
				$best_day       = $date;
			}
		}

		$final_recommendation = '';
		if ( $best_day !== '' ) {
			$final_recommendation = 'Ngày phù hợp nhất để xử lý việc quan trọng: ' . $best_day
				. ' (score=' . round( $best_day_score, 2 ) . ').';
			$lines[] = '';
			$lines[] = '🎯 Kết luận cuối: ' . $final_recommendation;
		}

		return array(
			'transit_foreach_md'   => implode( "\n", $lines ),
			'best_day'             => $best_day,
			'best_day_score'       => is_finite( $best_day_score ) ? $best_day_score : 0,
			'final_recommendation' => $final_recommendation,
			'day_messages'         => array(),
		);
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — merge composed day messages
	 * back into day_items so action.reply_zalo_each_day can send richer per-day output.
	 */
	private function apply_day_messages_to_items( array $day_items, array $day_messages ): array {
		if ( empty( $day_items ) || empty( $day_messages ) ) {
			return $day_items;
		}

		foreach ( $day_items as $i => $day ) {
			if ( ! is_array( $day ) ) { continue; }
			$date = (string) ( $day['date'] ?? '' );
			if ( $date === '' ) { continue; }
			if ( isset( $day_messages[ $date ] ) && trim( (string) $day_messages[ $date ] ) !== '' ) {
				$day_items[ $i ]['analysis'] = (string) $day_messages[ $date ];
			}
		}

		return $day_items;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — deterministic score for one day item.
	 */
	private function score_day_item( array $day ): float {
		$score    = 50.0;
		$aspects  = isset( $day['aspects'] ) && is_array( $day['aspects'] ) ? $day['aspects'] : array();
		$retro    = (string) ( $day['retrograde'] ?? '' );
		$retro_ct = $retro === '' ? 0 : count( array_filter( array_map( 'trim', explode( ',', $retro ) ) ) );

		foreach ( $aspects as $line ) {
			$score += $this->score_aspect_line( (string) $line );
		}
		$score -= (float) ( $retro_ct * 2 );
		return $score;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — aspect scoring aligned with runtime timeline scoring.
	 */
	private function score_aspect_line( string $line ): float {
		$weights = array(
			'tam hợp'     => 6,
			'trine'       => 6,
			'lục hợp'     => 4,
			'sextile'     => 4,
			'hợp'         => 2,
			'conjunction' => 2,
			'đối'         => -5,
			'opposition'  => -5,
			'vuông'       => -6,
			'square'      => -6,
			'quincunx'    => -3,
			'bất điều hòa'=> -3,
		);

		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		$base    = 0.0;
		foreach ( $weights as $k => $w ) {
			if ( strpos( $line_lc, $k ) !== false ) {
				$base = (float) $w;
				break;
			}
		}

		$orb = 2.0;
		if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
			$orb = (float) $m[1];
		}
		$orb_factor = max( 0.2, ( 4.0 - min( 4.0, $orb ) ) / 4.0 );
		return $base * $orb_factor;
	}

	private function build_passages_md( array $snapshots, array $range ): string {
		$lines = array( "## Transit {$range['label']} ({$range['start']} → {$range['end']})" );
		foreach ( $snapshots as $date => $snap ) {
			$lines[] = "\n### {$date}";
			foreach ( (array) ( $snap['aspects'] ?? array() ) as $asp ) {
				$lines[] = sprintf(
					'- **%s** %s → natal %s (orb %.1f°)',
					$asp['transit_planet'] ?? '',
					$asp['aspect']         ?? '',
					$asp['natal_planet']   ?? '',
					(float) ( $asp['orb']  ?? 0 )
				);
			}
		}
		return implode( "\n", $lines );
	}

	private function build_transit_url( int $coachee_id, string $period, string $chat_id ): string {
		// PHP 7.4: no named args, no nullsafe
		if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
			return (string) bcpro_get_transit_public_url( $coachee_id, $period );
		}
		return home_url( '/my-transit/?coachee_id=' . $coachee_id . '&period=' . rawurlencode( $period ) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 artifact catalog object.
	 */
	private function build_artifact_links( int $coachee_id, string $period, string $chat_id ): array {
		$defaults = array(
			'wheel'              => '',
			'western_vi'         => '',
			'western_en'         => '',
			'western_regenerate' => '',
			'vedic'              => '',
			'chinese'            => '',
			'transit_day'        => '',
			'transit_week'       => '',
			'transit_month'      => '',
			'transit_year'       => '',
		);

		if ( function_exists( 'bcpro_get_astro_artifact_links' ) ) {
			$links = bcpro_get_astro_artifact_links( $coachee_id, $chat_id, $period );
			if ( is_array( $links ) ) {
				return array_merge( $defaults, $links );
			}
		}

		if ( $coachee_id > 0 ) {
			$defaults['wheel'] = function_exists( 'bccm_get_natal_chart_public_url' )
				? (string) bccm_get_natal_chart_public_url( $coachee_id )
				: '';
			$defaults['transit_day']   = function_exists( 'bcpro_get_transit_public_url' ) ? (string) bcpro_get_transit_public_url( $coachee_id, 'day' ) : '';
			$defaults['transit_week']  = function_exists( 'bcpro_get_transit_public_url' ) ? (string) bcpro_get_transit_public_url( $coachee_id, 'week' ) : '';
			$defaults['transit_month'] = function_exists( 'bcpro_get_transit_public_url' ) ? (string) bcpro_get_transit_public_url( $coachee_id, 'month' ) : '';
			$defaults['transit_year']  = function_exists( 'bcpro_get_transit_public_url' ) ? (string) bcpro_get_transit_public_url( $coachee_id, 'year' ) : '';
		}

		return $defaults;
	}

	private function _fail( string $reason, string $detail = '' ): array {
		$this->note_event( 'run_astro_transit_failed', array(
			'reason' => $reason,
			'detail' => $detail,
		) );
		return array(
			'ok'                 => false,
			'has_transit'        => 0,
			'coachee_id'         => 0,
			'period_label'       => '',
			'range_start'        => '',
			'range_end'          => '',
			'days_count'         => 0,
			'transit_text'       => '⚠️ ' . $detail,
			'day_links_text'     => '',
			'days_json'          => '[]',
			'transit_foreach_md' => '',
			'best_day'           => '',
			'best_day_score'     => 0,
			'final_recommendation' => '',
			'transit_url'        => '',
			'transit_url_single' => '',
			'sync_30_url'        => '',
			'natal_url'          => '',
			'artifact_links'     => $this->build_artifact_links( 0, 'day', '' ),
			'retrograde_planets' => '',
			'key_aspects'        => '',
			'passages_md'        => '',
		);
	}
}
