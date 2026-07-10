<?php
/**
 * Action: Run Astro (BizCity TwinBrain Astro Engine).
 *
 * Automation-context wrapper cho BizCity_TwinBrain_Web_Astro.
 *
 * Pipeline:
 *   1. Resolve user_id từ chat_id (filter bizcity_automation_astro_user_id_from_chat_id).
 *   2. Gọi BizCity_TwinBrain_Web_Astro::run() → passages + period + coachee_id.
 *   3. Nếu có bản đồ sao (passages > 0):
 *      - Build natal_url + transit_url + create_chart_url.
 *      - Nếu field compose=true: gọi LLM compose reading ngắn gọn (Zalo-friendly).
 *   4. Nếu KHÔNG có bản đồ sao:
 *      - Trả has_chart=false + create_chart_url (có chat_id param) để gửi link tạo chart.
 *
 * Output vars:
 *   {{n_X.ok}}                — bool
 *   {{n_X.has_chart}}         — 1 / 0
 *   {{n_X.coachee_id}}        — int
 *   {{n_X.coachee_name}}      — tên coachee hoặc ''
 *   {{n_X.period}}            — day|week|month|year|5year
 *   {{n_X.period_label}}      — tiếng Việt: "ngày hôm nay", "tuần này"...
 *   {{n_X.num_days}}          — số ngày explicit (classifier horizon, fallback từ period)
 *   {{n_X.natal_url}}         — URL bản đồ sao (có chat_id param)
 *   {{n_X.transit_url}}       — URL transit (có chat_id + period param)
 *   {{n_X.create_chart_url}}  — URL tạo bản đồ sao mới (có chat_id + return params)
 *   {{n_X.artifact_links}}    — object links chuẩn W3 (wheel/western/vedic/chinese/transit_*)
 *   {{n_X.analysis}}          — Nhận định LLM (nếu compose=true và has_chart=true)
 *   {{n_X.passages_count}}    — số passage artifacts nạp được
 *
 * Filters:
 *   bizcity_automation_astro_user_id_from_chat_id($user_id, $chat_id, $instance_id)
 *   bizcity_automation_astro_natal_url($url, $coachee_id, $chat_id)
 *   bizcity_automation_astro_transit_url($url, $coachee_id, $period, $chat_id)
 *   bizcity_automation_astro_create_chart_url($url, $chat_id, $instance_id)
 *
 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT-ASTRO — action.run_astro block.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-ZALOBOT-ASTRO (2026-06-18)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Run_Astro extends BizCity_Automation_Block_Base {

	const LLM_TIMEOUT_S    = 18;
	const LLM_MAX_TOKENS   = 420;
	const LLM_TEMPERATURE  = 0.35;
	const PASSAGES_MAX     = 5;   // cap passages fed to LLM

	public function id(): string   { return 'action.run_astro'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Chiêm tinh (TwinBrain Astro)',
			'short'    => 'run_astro',
			'category' => 'ai',
			'color'    => '#7c3aed',
			'icon'     => 'star',
			'defaults' => array(
				'label'       => 'run_astro',
				'chat_id'     => '{{trigger.chat_id}}',
				'instance_id' => '{{trigger.account_id}}',
				'query'       => '{{trigger.text}}',
				'compose'     => true,
			),
			'fields' => array(
				array( 'name' => 'label',       'label' => 'Tên hiển thị',                 'type' => 'text' ),
				array( 'name' => 'chat_id',     'label' => 'Chat ID (Zalo)',               'type' => 'text', 'hint' => 'Mặc định {{trigger.chat_id}}' ),
				array( 'name' => 'instance_id', 'label' => 'Bot / OA Instance ID',         'type' => 'text', 'hint' => 'Mặc định {{trigger.instance_id}}' ),
				array( 'name' => 'query',       'label' => 'Câu hỏi chiêm tinh',           'type' => 'textarea', 'hint' => 'Mặc định {{trigger.text}}' ),
				array( 'name' => 'compose',     'label' => 'Tạo nhận định bằng AI',       'type' => 'toggle', 'hint' => 'Bật = gọi LLM soạn nhận định; Tắt = chỉ lấy links' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-18 Johnny Chu] PHASE-ZALOBOT-ASTRO — execute run_astro.
		$chat_id     = trim( (string) $this->resolve( $data['chat_id']     ?? '{{trigger.chat_id}}',     $ctx ) );
		$instance_id = trim( (string) $this->resolve( $data['instance_id'] ?? '{{trigger.instance_id}}', $ctx ) );
		$query       = trim( (string) $this->resolve( $data['query']       ?? '{{trigger.text}}',        $ctx ) );
		$do_compose  = ! empty( $data['compose'] );
		if ( $query === '' ) { $query = 'chiêm tinh hôm nay'; }

		/* ── 1. Resolve user_id → coachee_id ──────────────────────────── */
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — ưu tiên wp_user_id đã được trigger enrichment,
		// fallback BizCity_User_Resolver nếu trigger chưa enrich (replay/manual/cron).
		$user_id = (int) ( $ctx['t1']['wp_user_id'] ?? $ctx['trigger']['wp_user_id'] ?? 0 );
		if ( ! $user_id && $chat_id !== '' && class_exists( 'BizCity_User_Resolver' ) ) {
			$user_id = (int) BizCity_User_Resolver::instance()->resolve( $chat_id );
		}
		// Allow override via filter for custom integrations
		if ( ! $user_id ) {
			$user_id = (int) apply_filters( 'bizcity_automation_astro_user_id_from_chat_id', 0, $chat_id, $instance_id );
		}

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — direct coachee lookup từ user_id.
		// Bypass TwinBrain name-extract LLM khi đã có user_id từ Zalo link.
		$direct_coachee_id   = 0;
		$direct_coachee_name = '';
		$direct_natal_url    = '';
		if ( $user_id > 0 ) {
			// [2026-07-06 Johnny Chu] HOTFIX — R-COACHEE: ưu tiên hồ sơ chính chủ (is_self=1).
			$profile_row = $this->resolve_self_coachee_row( $user_id );
			if ( ! empty( $profile_row['id'] ) ) {
				$direct_coachee_id   = (int) $profile_row['id'];
				$direct_coachee_name = (string) ( $profile_row['full_name'] ?? '' );
			}
		}
		// Fallback: bccm_get_natal_chart_url_by_user (có thể trả về URL trực tiếp)
		if ( $user_id > 0 && $direct_coachee_id <= 0 && function_exists( 'bccm_get_natal_chart_url_by_user' ) ) {
			$direct_natal_url = (string) bccm_get_natal_chart_url_by_user( $user_id );
		}

		/* ── 2. Run Astro engine ────────────────────────────────────────── */
		if ( ! class_exists( 'BizCity_TwinBrain_Web_Astro' ) ) {
			return $this->_degraded( $chat_id, 'astro_engine_missing', 'BizCity_TwinBrain_Web_Astro not loaded' );
		}

		$trace_id = 'auto_astro_' . uniqid( '', true );
		try {
			$row = BizCity_TwinBrain_Web_Astro::instance()->run( $trace_id, $query, array(
				'user_id'    => $user_id,
				'coachee_id' => $direct_coachee_id,  // hint direct → skip LLM name-extract
			) );
		} catch ( \Throwable $e ) {
			return $this->_degraded( $chat_id, 'astro_exception', $e->getMessage() );
		}

		// Merge: ưu tiên self-profile từ user_id; nếu engine trả coachee ngoài ownership thì ép về self.
		$engine_coachee_id = (int) ( $row['coachee_id_resolved'] ?? 0 );
		$coachee_id        = $direct_coachee_id > 0 ? $direct_coachee_id : $engine_coachee_id;
		if ( $user_id > 0 && $coachee_id > 0 && ! $this->coachee_belongs_to_user( $coachee_id, $user_id ) ) {
			// [2026-07-06 Johnny Chu] HOTFIX — chặn cross-user coachee leak trong automation.
			$coachee_id = $this->resolve_self_coachee_id( $user_id );
		}
		if ( $direct_coachee_id > 0 && $engine_coachee_id > 0 && $engine_coachee_id !== $direct_coachee_id ) {
			$this->note_event( 'run_astro_coachee_override', array(
				'user_id'           => $user_id,
				'engine_coachee_id' => $engine_coachee_id,
				'self_coachee_id'   => $direct_coachee_id,
				'final_coachee_id'  => $coachee_id,
				'reason'            => 'enforce_self_profile',
			) );
		}
		$coachee_name = $direct_coachee_name !== '' ? $direct_coachee_name : (string) ( $row['name_extracted'] ?? '' );
		$period       = (string) ( $row['period'] ?? 'day' );
		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — prefer classify label (handles "Ngày kia", "7 ngày tới"…)
		$period_label = (string) ( $row['label'] ?? '' );
		if ( $period_label === '' ) { $period_label = $this->period_label( $period ); }
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — expose explicit horizon for downstream transit block.
		$num_days     = (int) ( $row['num_days'] ?? 0 );
		if ( $num_days <= 0 ) { $num_days = $this->period_days( $period ); }
		$passages     = is_array( $row['passages'] ) ? $row['passages'] : array();
		$has_chart    = $coachee_id > 0 && ! empty( $passages ) && ( (string) ( $row['_degraded'] ?? '' ) === '' );

		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — compute start_date from start_offset
		// offset=1 = tomorrow = build_range default → pass '' so build_range uses its default
		// offset=0 = today; offset=2 = day after tomorrow → pass explicit YYYY-MM-DD
		$start_offset = (int) ( $row['start_offset'] ?? 1 );
		if ( $start_offset === 1 ) {
			$start_date = '';  // build_range default = tomorrow
		} else {
			$start_date = date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . $start_offset . ' days' ) );
		}

		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — topic detection from query
		$topics      = is_array( $row['topics'] ?? null ) ? (array) $row['topics'] : array();
		if ( empty( $topics ) && function_exists( '_bccm_detect_astro_topics' ) ) {
			$topics = _bccm_detect_astro_topics( mb_strtolower( $query ) );
		}
		$topic_label = $this->format_topic_label( $topics );

		// Always build create_chart_url (used in both paths)
		$create_url = $this->build_create_url( $chat_id, $instance_id );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W3 unified artifact links in automation ctx.
		$artifact_links = $this->build_artifact_links( $coachee_id, $period, $chat_id );

		// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — unify subject profile
		// context with TwinBrain runtime (cached under bcpro).
		$natal_positions_md = '';
		$transit_context_md = '';
		if ( $coachee_id > 0 && class_exists( 'BizCity_TwinBrain_Astro_Subject_Profile_Service' ) ) {
			$subject_profile = BizCity_TwinBrain_Astro_Subject_Profile_Service::instance()->resolve_by_coachee(
				$coachee_id,
				$user_id,
				$coachee_name
			);
			if ( is_array( $subject_profile ) ) {
				$natal_positions_md = trim( (string) ( $subject_profile['natal_profile_md'] ?? '' ) );
				$transit_context_md = trim( (string) ( $subject_profile['transit_context_md'] ?? '' ) );
				if ( $coachee_name === '' ) {
					$coachee_name = (string) ( $subject_profile['coachee_name'] ?? '' );
				}
			}
		}
		if ( $natal_positions_md === '' ) {
			// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — fallback legacy natal
			// positions extractor for backward compatibility.
			$natal_positions_md = $this->build_natal_positions_md( $coachee_id );
		}

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — debug trace để kiểm tra dữ liệu có lấy đúng không
		$debug_info = 'user_id=' . $user_id
			. ' direct_coachee=' . $direct_coachee_id
			. ' engine_coachee=' . (int) ( $row['coachee_id_resolved'] ?? 0 )
			. ' passages=' . count( $passages )
			. ' classify=' . (string) ( $row['classify_source'] ?? '?' )
			. ' period=' . $period
			. ' _degraded=' . (string) ( $row['_degraded'] ?? '' );

		$this->note_event( 'run_astro_lookup', array(
			'coachee_id'    => $coachee_id,
			'user_id'       => $user_id,
			'has_chart'     => $has_chart,
			'period'        => $period,
			'passages_count'=> count( $passages ),
			'classify_src'  => (string) ( $row['classify_source'] ?? '' ),
			'_degraded'     => (string) ( $row['_degraded'] ?? '' ),
		) );

		/* ── 3a. No chart path ──────────────────────────────────────────── */
		if ( ! $has_chart ) {
			// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — has_chart trả int 0/1 để condition == 1 so sánh đúng
			return array(
				'ok'                 => false,
				'has_chart'          => 0,
				'coachee_id'         => $coachee_id,
				'coachee_name'       => $coachee_name,
				'period'             => $period,
				'period_label'       => $period_label,
				'num_days'           => $num_days,
				'start_date'         => $start_date,
				'topics'             => $topics,
				'topic_label'        => $topic_label,
				'natal_url'          => $direct_natal_url,
				'natal_url_full'     => $direct_natal_url,
				'natal_positions_md' => $natal_positions_md,
				'transit_context_md' => $transit_context_md,
				'transit_url'        => '',
				'create_chart_url'   => $create_url,
				'artifact_links'     => $artifact_links,
				'analysis'           => '',
				'passages_count'     => 0,
				'user_id'            => $user_id,
				'debug_info'         => $debug_info,
			);
		}

		/* ── 3b. Has chart: build URLs ──────────────────────────────────── */
		$natal_url   = $this->build_natal_url( $coachee_id, $chat_id );
		$transit_url = $this->build_transit_url( $coachee_id, $period, $chat_id );

		/* ── 4. LLM compose (optional) ──────────────────────────────────── */
		$analysis = '';
		if ( $do_compose ) {
			$analysis = $this->compose_analysis( $passages, $period_label, $coachee_name );
		}

		// Build natal_url_full: URL tuyệt đối cho Zalo (không là admin URL)
		$natal_url_full = $natal_url;
		if ( function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$natal_url_full = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — has_chart trả int 1 để condition == 1 khớp
		return array(
			'ok'                 => true,
			'has_chart'          => 1,
			'coachee_id'         => $coachee_id,
			'coachee_name'       => $coachee_name !== '' ? $coachee_name : 'bạn',
			'user_id'            => $user_id,
			'period'             => $period,
			'period_label'       => $period_label,
			'num_days'           => $num_days,
			'start_date'         => $start_date,
			'topics'             => $topics,
			'topic_label'        => $topic_label,
			'natal_url'          => $natal_url,
			'natal_url_full'     => $natal_url_full,
			'natal_positions_md' => $natal_positions_md,
			'transit_context_md' => $transit_context_md,
			'transit_url'        => $transit_url,
			'create_chart_url'   => $create_url,
			'artifact_links'     => $artifact_links,
			'analysis'           => $analysis,
			'passages_count'     => count( $passages ),
			'debug_info'         => $debug_info,
		);
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================= */

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — canonical self-profile resolver for automation.
	 *
	 * @return array
	 */
	private function resolve_self_coachee_row( int $user_id ): array {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return array(); }

		if ( function_exists( 'bccm_get_self_coachee' ) ) {
			$row = bccm_get_self_coachee( $user_id );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return $row;
			}
		}

		if ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			$row = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				if ( function_exists( 'bccm_set_self_coachee' ) ) {
					bccm_set_self_coachee( $user_id, (int) $row['id'] );
				}
				return $row;
			}
		}

		return array();
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — resolve self coachee id from canonical row.
	 */
	private function resolve_self_coachee_id( int $user_id ): int {
		$row = $this->resolve_self_coachee_row( $user_id );
		return ! empty( $row['id'] ) ? (int) $row['id'] : 0;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — ownership guard for coachee_id.
	 */
	private function coachee_belongs_to_user( int $coachee_id, int $user_id ): bool {
		$coachee_id = (int) $coachee_id;
		$user_id    = (int) $user_id;
		if ( $coachee_id <= 0 || $user_id <= 0 ) { return false; }

		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return true; // avoid false negatives on schema drifted sites
		}

		$ok = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id,
			$user_id
		) );

		return $ok === 1;
	}

	/**
	 * Compose Zalo-friendly astro reading via LLM.
	 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT-ASTRO
	 *
	 * @param array  $passages
	 * @param string $period_label
	 * @param string $name
	 * @return string
	 */
	private function compose_analysis( array $passages, string $period_label, string $name ): string {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) { return ''; }
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) { return ''; }

		// Build context from passages (cap at PASSAGES_MAX to keep prompt short)
		$ctx_parts = array();
		$capped    = array_slice( $passages, 0, self::PASSAGES_MAX );
		foreach ( $capped as $p ) {
			$title = (string) ( $p['title'] ?? '' );
			$body  = (string) ( $p['body']  ?? '' );
			if ( $body !== '' ) {
				$ctx_parts[] = ( $title !== '' ? '### ' . $title . "\n" : '' ) . mb_substr( $body, 0, 600 );
			}
		}
		if ( empty( $ctx_parts ) ) { return ''; }
		$context = implode( "\n\n---\n\n", $ctx_parts );

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — explicit prompt với aspects/signs/houses/retrograde
		$name_part  = $name !== '' ? ' cho ' . $name : '';
		$system_msg = 'Bạn là chuyên gia chiêm tinh Tây phương. Dựa ĐÚNG vào dữ liệu được cung cấp, '
			. 'viết nhận định ngắn gọn (≤280 từ) bằng tiếng Việt, phù hợp gửi qua Zalo. '
			. 'QUAN TRỌNG: Phải nêu cụ thể các dẫn chứng sau khi nhận định — '
			. '(a) Góc chiếu (aspect) nào tác động: Conjunction/Opposition/Trine/Square + orb bao nhiêu độ; '
			. '(b) Hành tinh nào transit qua house/cung nào; '
			. '(c) Có hành tinh nghịch hành (℞) nào ảnh hưởng không; '
			. '(d) Ít nhất 1-2 citation dạng "[Tên hành tinh aspect Natal hành tinh — nguồn: tên passage]". '
			. 'Không dùng markdown/ký tự định dạng đặc biệt. '
			. 'Bố cục: 1) Tổng quan ' . $period_label . $name_part . '; '
			. '2) Điểm nổi bật với dẫn chứng cụ thể; 3) Lời khuyên thực tế.';
		$user_msg   = "Thời kỳ: " . $period_label . $name_part . ".\n\nDữ liệu natal chart và transit:\n" . $context;

		$messages = array(
			array( 'role' => 'system', 'content' => $system_msg ),
			array( 'role' => 'user',   'content' => $user_msg ),
		);

		$result = $client->chat( $messages, array(
			'purpose'    => 'automation_astro_compose',
			'max_tokens' => self::LLM_MAX_TOKENS,
			'temperature'=> self::LLM_TEMPERATURE,
			'timeout'    => self::LLM_TIMEOUT_S,
		) );

		if ( ! is_array( $result ) || empty( $result['success'] ) ) { return ''; }
		return trim( (string) ( $result['message'] ?? '' ) );
	}

	private function period_label( string $period ): string {
		$map = array(
			'day'   => 'ngày hôm nay',
			'3day'  => '3 ngày tới',
			'5day'  => '5 ngày tới',
			'week'  => 'tuần này',
			'month' => 'tháng này',
			'year'  => 'năm nay',
			'5year' => '5 năm tới',
		);
		return isset( $map[ $period ] ) ? $map[ $period ] : $period;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — normalize period to day count.
	 */
	private function period_days( string $period ): int {
		$map = array(
			'day'   => 1,
			'3day'  => 3,
			'5day'  => 5,
			'week'  => 7,
			'10day' => 10,
			'20day' => 20,
			'month' => 30,
			'year'  => 365,
			'5year' => 365,
		);
		return isset( $map[ $period ] ) ? (int) $map[ $period ] : 7;
	}

	/**
	 * Format topic slugs as Vietnamese comma-separated label.
	 * [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW
	 */
	private function format_topic_label( array $topics ): string {
		$vi = array(
			'finance' => 'Tài chính',
			'career'  => 'Sự nghiệp',
			'love'    => 'Tình duyên',
			'health'  => 'Sức khỏe',
			'study'   => 'Học tập',
			'family'  => 'Gia đình',
		);
		$parts = array();
		foreach ( $topics as $slug ) {
			$parts[] = isset( $vi[ $slug ] ) ? $vi[ $slug ] : $slug;
		}
		return empty( $parts ) ? '' : implode( ', ', $parts );
	}

	/**
	 * Extract natal planet positions from bccm_astro.traits and format as text for LLM context.
	 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — natal positions text for n3 LLM.
	 */
	private function build_natal_positions_md( int $coachee_id ): string {
		if ( $coachee_id <= 0 ) { return ''; }
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_astro';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT traits FROM {$tbl} WHERE coachee_id = %d AND chart_type = 'western' LIMIT 1",
			$coachee_id
		), ARRAY_A );

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — fallback: nếu chưa có traits thì queue natal regen async
		if ( ! $row || empty( $row['traits'] ) ) {
			$this->queue_natal_regen( $coachee_id );
			return '(Bản đồ sao chưa được tính — đang xếp hàng tạo lại. Vui lòng hỏi lại sau ít phút.)';
		}
		$traits    = json_decode( (string) $row['traits'], true );
		$positions = ( is_array( $traits ) && isset( $traits['positions'] ) )
			? (array) $traits['positions']
			: array();
		if ( empty( $positions ) ) { return ''; }

		$planet_vi = array(
			'Sun'       => 'Mặt Trời ☉',  'Moon'       => 'Mặt Trăng ☽',
			'Mercury'   => 'Sao Thủy ☿',  'Venus'      => 'Sao Kim ♀',
			'Mars'      => 'Sao Hỏa ♂',   'Jupiter'    => 'Sao Mộc ♃',
			'Saturn'    => 'Sao Thổ ♄',   'Uranus'     => 'Thiên Vương ♅',
			'Neptune'   => 'Hải Vương ♆', 'Pluto'      => 'Diêm Vương ♇',
			'Chiron'    => 'Chiron ⚷',    'Ascendant'  => 'Mọc (ASC)',
			'Midheaven' => 'Thiên Đỉnh (MC)',
		);
		$order = array( 'Sun','Moon','Mercury','Venus','Mars','Jupiter','Saturn','Uranus','Neptune','Pluto','Chiron','Ascendant','Midheaven' );

		$lines = array();
		foreach ( $order as $pname ) {
			if ( ! isset( $positions[ $pname ] ) ) { continue; }
			$p     = (array) $positions[ $pname ];
			$vi    = $planet_vi[ $pname ] ?? $pname;
			$sign  = (string) ( $p['sign_en'] ?? $p['sign'] ?? '' );
			$house = (int)    ( $p['house_number'] ?? $p['house'] ?? 0 );
			$deg   = (float)  ( $p['norm_degree'] ?? $p['normDegree'] ?? $p['sign_degree'] ?? 0 );
			$retro = ( ! empty( $p['is_retro'] ) || ( isset( $p['isRetro'] ) && $p['isRetro'] === 'true' ) )
				? ' (nghịch hành ℞)' : '';
			$lines[] = sprintf( '%s: %s%s — Nhà %d (%.1f°)', $vi, $sign, $retro, $house, $deg );
		}
		return empty( $lines ) ? '' : "Natal chart planets:\n" . implode( "\n", $lines );
	}

	/**
	 * Queue async natal chart regeneration khi bccm_astro.traits trống.
	 * [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — fallback natal regen.
	 */
	private function queue_natal_regen( int $coachee_id ): void {
		if ( $coachee_id <= 0 ) { return; }
		global $wpdb;
		$uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id = %d LIMIT 1",
			$coachee_id
		) );
		if ( $uid <= 0 ) { return; }
		// Dùng bcpro_async_rebuild_transit (bao gồm natal regen nếu traits rỗng)
		$args = array( $coachee_id, $uid );
		if ( ! wp_next_scheduled( 'bcpro_async_rebuild_transit', $args ) ) {
			wp_schedule_single_event( time() + 10, 'bcpro_async_rebuild_transit', $args );
		}
	}

	private function build_natal_url( int $coachee_id, string $chat_id ): string {
		$default = admin_url(
			'admin.php?page=bizcoach-pro&tab=natal-chart'
			. '&coachee_id=' . $coachee_id
			. '&chat_id=' . rawurlencode( $chat_id )
		);
		return (string) apply_filters( 'bizcity_automation_astro_natal_url', $default, $coachee_id, $chat_id );
	}

	private function build_transit_url( int $coachee_id, string $period, string $chat_id ): string {
		$default = admin_url(
			'admin.php?page=bizcoach-pro&tab=transit'
			. '&coachee_id=' . $coachee_id
			. '&period=' . rawurlencode( $period )
			. '&chat_id=' . rawurlencode( $chat_id )
		);
		return (string) apply_filters( 'bizcity_automation_astro_transit_url', $default, $coachee_id, $period, $chat_id );
	}

	private function build_create_url( string $chat_id, string $instance_id ): string {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — public /astro/ URL (admin URL không dùng được với user thường)
		$params = array( 'register' => '1' );
		if ( $chat_id !== '' ) {
			$params['zalo_chat_id'] = $chat_id;
		}
		if ( $instance_id !== '' && strpos( $instance_id, '{{' ) === false ) {
			$params['bot_id'] = $instance_id;
		}
		$default = add_query_arg( $params, home_url( '/astro/' ) );
		return (string) apply_filters( 'bizcity_automation_astro_create_chart_url', $default, $chat_id, $instance_id );
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

	private function _degraded( string $chat_id, string $reason, string $detail = '' ): array {
		$this->note_event( 'run_astro_failed', array(
			'reason'  => $reason,
			'detail'  => $detail,
			'chat_id' => mb_substr( $chat_id, 0, 60 ),
		) );
		return array(
			'ok'              => false,
			'has_chart'       => false,
			'coachee_id'      => 0,
			'coachee_name'    => '',
			'period'          => 'day',
			'period_label'    => 'ngày hôm nay',
			'num_days'        => 1,
			'natal_url'       => '',
			'transit_url'     => '',
			'create_chart_url'=> $this->build_create_url( $chat_id, '' ),
			'artifact_links'  => $this->build_artifact_links( 0, 'day', $chat_id ),
			'analysis'        => '',
			'passages_count'  => 0,
		);
	}
}
