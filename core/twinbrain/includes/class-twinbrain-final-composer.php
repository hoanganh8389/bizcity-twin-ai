<?php
/**
 * BizCity TwinBrain Final Composer — Stage 4.5 (PHASE 0.36-UNIFIED · TBR.W16).
 *
 * Layer 4.5 sits BETWEEN Synthesizer (Layer 4) and Citation Resolver
 * (Layer 7). Its job is to take the structured Synthesizer output —
 * `{consensus[], tensions[], recommendation, answer_md, citations[]}`
 * — plus the raw inputs (perspectives + web rows + tool results) and
 * produce a **streaming, narrative final answer** that the user actually
 * sees in the chat. The Synthesizer answer_md remains in the timeline
 * as the "behind the scenes" analysis card; this composer's output is
 * the headline.
 *
 * Why a separate layer (vs just streaming the Synthesizer call):
 *   1. Synthesizer must return STRUCTURED JSON (consensus / tensions
 *      arrays). Streaming JSON tokens to the UI is unhelpful — the FE
 *      cannot incrementally render half-parsed objects without flicker.
 *   2. Final Composer outputs FREE-FORM markdown — every token can be
 *      rendered immediately, citation chips hot-swap as `[nb:X/pY]` /
 *      `[web:N#URL]` tokens close.
 *   3. Decouples "what the panel of perspectives concluded" from "what
 *      the user reads" — important when persona / guru voicing differs
 *      from clinical synthesis style.
 *
 * Streaming contract (R-EVT-4):
 *   on_token($delta, $accumulated)  — called per SSE delta from gateway
 *   returns: {
 *     success, answer_md, model, tokens, ms, error,
 *     fallback (when LLM down → echoes synthesizer.answer_md unchanged)
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-21 (PHASE 0.36-UNIFIED · TBR.W16)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Final_Composer {

	const PURPOSE         = 'twinbrain_final_compose';
	const TEMPERATURE     = 0.35;
	const MAX_TOKENS      = 1400;
	const MAX_TOKENS_GURU = 2200;
	const TIMEOUT_S       = 60;

	/** Truncation caps for prompt context blocks. */
	const ANS_TRUNC      = 1200;
	const WEB_SNIPPET    = 220;
	const MAX_WEB_ROWS   = 8;
	const MAX_NB_ROWS    = 6;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Compose final answer with streaming token delivery.
	 *
	 * @param string        $trace_id    Turn trace id (for gateway logs).
	 * @param string        $prompt      User prompt.
	 * @param array         $synth       Synthesizer output (full row).
	 * @param array         $answers     All perspective rows (local nb + web).
	 * @param array         $opts        { guru_id?, model?, locale? }
	 * @param callable|null $on_token    Optional fn($delta, $accumulated) for SSE relay.
	 * @return array { success, answer_md, model, tokens, ms, fallback, error }
	 */
	public function compose_stream(
		string $trace_id,
		string $prompt,
		array $synth,
		array $answers,
		array $opts = [],
		$on_token = null
	): array {
		$t0 = microtime( true );

		// Degrade gracefully when gateway not configured: just echo the
		// synthesizer's answer_md so the FE row still gets populated.
		if ( ! $this->gateway_ready() ) {
			$ans = (string) ( $synth['answer_md'] ?? '' );
			if ( is_callable( $on_token ) && $ans !== '' ) {
				// Single emit so FE final-row still renders something.
				call_user_func( $on_token, $ans, $ans );
			}
			return [
				'success'   => $ans !== '',
				'answer_md' => $ans,
				'model'     => '',
				'tokens'    => 0,
				'ms'        => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'fallback'  => 'gateway_unavailable',
				'error'     => '',
			];
		}

		$messages = $this->build_messages( $prompt, $synth, $answers, $opts );

		$client = BizCity_LLM_Client::instance();
		$model  = (string) ( $opts['model'] ?? '' );
		if ( $model === '' ) {
			$model = (string) apply_filters(
				'bizcity_twinbrain_final_compose_model',
				$client->get_model( 'chat' )
			);
		}

		$has_guru = ! empty( $opts['guru_id'] );
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — allow per-call
		// temperature/max_tokens override so astro mode can widen output depth.
		$temperature = isset( $opts['final_compose_temperature'] )
			? (float) $opts['final_compose_temperature']
			: self::TEMPERATURE;
		if ( $temperature < 0 ) {
			$temperature = 0;
		} elseif ( $temperature > 1.5 ) {
			$temperature = 1.5;
		}

		$max_tokens = $has_guru ? self::MAX_TOKENS_GURU : self::MAX_TOKENS;
		if ( isset( $opts['final_compose_max_tokens'] ) ) {
			$max_tokens = max( 200, (int) $opts['final_compose_max_tokens'] );
		}

		// Accumulator wrapper so we capture full text even if caller passes
		// no on_token (probe mode).
		$accumulated = '';
		$delta_n     = 0;
		$relay = function ( $delta, $full ) use ( &$accumulated, &$delta_n, $on_token ) {
			$accumulated = (string) $full;
			$delta_n++;
			if ( is_callable( $on_token ) ) {
				call_user_func( $on_token, (string) $delta, (string) $full );
			}
		};

		$result = $client->chat_stream(
			$messages,
			[
				'purpose'     => self::PURPOSE,
				'model'       => $model,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'timeout'     => self::TIMEOUT_S,
				// [2026-07-07 Johnny Chu] HOTFIX — forward keepalive callback so
				// runtime can emit `final_keepalive` SSE while waiting next token.
				'on_keepalive' => isset( $opts['on_keepalive'] ) ? $opts['on_keepalive'] : null,
				// Trace id propagation lets the gateway link this stream to
				// the parent turn in usage / debug logs.
				'extra_body'  => [
					'site_url'   => home_url(),
					'trace_id'   => $trace_id,
				],
			],
			$relay
		);

		$elapsed = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		// Streaming returned empty / errored → fall back to synthesizer answer
		// to keep the user-facing message non-blank.
		$final_text = trim( (string) ( $result['message'] ?? $accumulated ) );
		if ( empty( $result['success'] ) || $final_text === '' ) {
			$fallback_text = (string) ( $synth['answer_md'] ?? '' );
			if ( is_callable( $on_token ) && $fallback_text !== '' && $delta_n === 0 ) {
				// FE never received any deltas — emit synth as a single chunk.
				call_user_func( $on_token, $fallback_text, $fallback_text );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][final-compose] trace=' . $trace_id
					. ' fallback err=' . ( $result['error'] ?? 'empty' )
					. ' deltas=' . $delta_n
					. ' synth_len=' . mb_strlen( $fallback_text )
				);
			}
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — pass quota_exhausted + quota_layer
			// through so runtime can emit SSE error event for FE QuotaErrorBanner.
			// [2026-06-10 Johnny Chu] R-QUOTA-KEY — also forward usage counters.
			return [
				'success'           => $fallback_text !== '',
				'answer_md'         => $fallback_text,
				'model'             => (string) ( $result['model'] ?? $model ),
				'tokens'            => (int) ( $result['usage']['total_tokens'] ?? 0 ),
				'ms'                => $elapsed,
				'fallback'          => 'stream_empty:' . ( $result['error'] ?? 'unknown' ),
				'error'             => (string) ( $result['error'] ?? '' ),
				'quota_exhausted'   => ! empty( $result['quota_exhausted'] ),
				'quota_layer'       => isset( $result['quota_layer'] )     ? (string) $result['quota_layer']     : '',
				'tier'              => isset( $result['tier'] )             ? (string) $result['tier']             : '',
				'used_requests'     => isset( $result['used_requests'] )    ? (int)    $result['used_requests']    : 0,
				'cap_requests_day'  => isset( $result['cap_requests_day'] ) ? (int)    $result['cap_requests_day'] : 0,
				'used_usd'          => isset( $result['used_usd'] )         ? (float)  $result['used_usd']         : 0.0,
				'cap_usd'           => isset( $result['cap_usd'] )          ? (float)  $result['cap_usd']          : 0.0,
				'reset_at'          => isset( $result['reset_at'] )         ? (string) $result['reset_at']         : '',
				'quota_period'      => isset( $result['quota_period'] )     ? (string) $result['quota_period']     : 'day',
				'master_level'      => isset( $result['master_level'] )     ? (string) $result['master_level']     : '',
			];
		}

		return [
			'success'         => true,
			'answer_md'       => $final_text,
			'model'           => (string) ( $result['model'] ?? $model ),
			'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
			'ms'              => $elapsed,
			'fallback'        => '',
			'error'           => '',
			'quota_exhausted' => false,
			'quota_layer'     => '',
			'tier'            => '',
		];
	}

	/* =================================================================
	 *  Prompt
	 * ================================================================ */

	private function build_messages( string $prompt, array $synth, array $answers, array $opts ): array {
		$has_guru = ! empty( $opts['guru_id'] );
		$ans_cap  = $has_guru ? 700 : 450;
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — per-call answer
		// cap override for long-form astro each-day replies.
		if ( isset( $opts['final_compose_ans_cap'] ) ) {
			$ans_cap = max( 300, (int) $opts['final_compose_ans_cap'] );
		}

		// Split answers same as Synthesizer for symmetry.
		$nb_lines  = [];
		$web_lines = [];
		foreach ( $answers as $a ) {
			$mode = (string) ( $a['mode'] ?? '' );
			if ( $mode === 'quick' || $mode === 'deep' ) {
				$web_lines[] = $a;
				continue;
			}
			$nb_lines[] = $a;
		}

		$nb_block  = $this->render_nb_compact( array_slice( $nb_lines,  0, self::MAX_NB_ROWS ) );
		$web_block = $this->render_web_compact( array_slice( $web_lines, 0, self::MAX_WEB_ROWS ) );

		// Synthesizer summary — give the LLM the structured findings so it
		// doesn't have to re-derive them; its job is purely VOICE + flow.
		$consensus = (array) ( $synth['consensus'] ?? [] );
		$tensions  = (array) ( $synth['tensions']  ?? [] );
		$rec       = trim( (string) ( $synth['recommendation'] ?? '' ) );
		$synth_md  = trim( (string) ( $synth['answer_md']      ?? '' ) );

		$synth_block = "### B\u00c1O C\u00c1O T\u1eea SYNTHESIZER\n";
		if ( $consensus ) {
			$synth_block .= "**Consensus:**\n- " . implode( "\n- ", array_map( 'wp_strip_all_tags', $consensus ) ) . "\n";
		}
		if ( $tensions ) {
			$synth_block .= "\n**Tensions:**\n- " . implode( "\n- ", array_map( 'wp_strip_all_tags', $tensions ) ) . "\n";
		}
		if ( $rec !== '' ) {
			$synth_block .= "\n**Recommendation:** " . wp_strip_all_tags( $rec ) . "\n";
		}
		if ( $synth_md !== '' ) {
			$synth_block .= "\n**Synthesizer answer (raw):**\n" . mb_substr( wp_strip_all_tags( $synth_md ), 0, self::ANS_TRUNC );
		}

		$has_web = ! empty( $web_lines );
		$web_rule = $has_web
			? "5. Khi tr\u00edch ngu\u1ed3n web, B\u1eaeT BU\u1ed8C d\u00f9ng token `[web:<N>#<URL>]` (\u0111\u00fang index trong CITATION MAP \u1edf khung WEB SOURCES). KH\u00d4NG \u0111\u1ed5i th\u00e0nh footnote s\u1ed1 ho\u1eb7c [^1]."
			: "5. Turn n\u00e0y kh\u00f4ng c\u00f3 ngu\u1ed3n web \u2014 ch\u1ec9 d\u00f9ng [nb:X/pY] khi tr\u00edch notebook.";

		$persona_hint = $has_guru
			? "\n6. Gi\u1eef gi\u1ecdng v\u0103n persona/guru \u0111ang b\u1ecb b\u00ecnh \u2014 KH\u00d4NG d\u00f9ng gi\u1ecdng b\u00e1o c\u00e1o trung t\u00ednh n\u1ebfu persona y\u00eau c\u1ea7u kh\u00e1c (vd persona Tarot dung gi\u1ecdng huy\u1ec1n b\u00ed; persona m\u1eb9 d\u00f9ng gi\u1ecdng \u1ea5m \u00e1p)."
			: '';

		$system = <<<SYS
B\u1ea1n l\u00e0 Final Composer c\u1ee7a TwinBrain \u2014 vi\u1ebft c\u00e2u tr\u1ea3 l\u1eddi cu\u1ed1i c\u00f9ng cho user.

NGUY\u00caN T\u1eaeC:
1. \u0110\u00e2y l\u00e0 tin nh\u1eafn user s\u1ebd \u0111\u1ecdc \u2014 vi\u1ebft m\u01b0\u1ee3t, t\u1ef1 nhi\u00ean, ti\u1ebfng Vi\u1ec7t. Markdown nh\u1eb9 (heading, list khi c\u1ea7n).
2. D\u00f9ng B\u00c1O C\u00c1O T\u1eea SYNTHESIZER l\u00e0m x\u01b0\u01a1ng s\u1ed1ng. KH\u00d4NG ph\u1ea3n b\u00e1c synthesizer; nhi\u1ec7m v\u1ee5 c\u1ee7a b\u1ea1n l\u00e0 di\u1ec5n \u0111\u1ea1t l\u1ea1i th\u00e0nh c\u00e2u tr\u1ea3 l\u1eddi h\u00fau \u00edch.
3. T\u1ed1i \u0111a {$ans_cap} t\u1eeb. C\u1ea5u tr\u00fac \u0111\u1ec1 ngh\u1ecb: m\u1edf b\u00e0i ng\u1eafn \u2192 \u0111i\u1ec3m ch\u00ednh (consensus) \u2192 l\u01b0u \u00fd / m\u00e2u thu\u1eabn (tensions) \u2192 (n\u1ebfu c\u00f3) khuy\u1ebfn ngh\u1ecb \u2192 k\u1ebft.
4. M\u1ed7i lu\u1eadn \u0111i\u1ec3m c\u00f3 ngu\u1ed3n notebook PH\u1ea2I k\u00e8m citation `[nb:<notebook_id>/p<passage_id>]` (sao ch\u00e9p \u0111\u00fang token t\u1eeb synthesizer).
{$web_rule}{$persona_hint}
7. Khi th\u00f4ng tin m\u00e2u thu\u1eabn, n\u00eau r\u00f5 \u201cm\u1ed9t s\u1ed1 ngu\u1ed3n n\u00f3i X, ngu\u1ed3n kh\u00e1c n\u00f3i Y\u201d \u2014 KH\u00d4NG trung b\u00ecnh ho\u00e1.
8. KH\u00d4NG xu\u1ea5t JSON, KH\u00d4NG ```fence. Ch\u1ec9 markdown thu\u1ea7n.
9. N\u1ebfu kh\u1ed1i MEMORY (\ud83e\udde0) ph\u00eda d\u01b0\u1edbi cung c\u1ea5p th\u00f4ng tin user \u0111\u00e3 d\u1eb7n / s\u1edf th\u00edch / m\u1ee5c ti\u00eau li\u00ean quan c\u00e2u h\u1ecfi \u2192 t\u00f4n tr\u1ecdng v\u00e0 echo token `[mem:U#<id>]` (ho\u1eb7c `[mem:E#<id>]`, `[mem:R#<id>]`) ngay c\u1ea1nh c\u00e2u v\u0103n s\u1eed d\u1ee5ng memory \u0111\u00f3. KH\u00d4NG b\u1ecf qua y\u00eau c\u1ea7u user \u0111\u00e3 d\u1eb7n.
SYS;

		// Wave 2.8 TBR.MEM-6 — Mode 3 function-call tools (default ON từ
		// 2026-05-24 sau khi probe `twinbrain.memory.tool-calls` PASS). Filter
		// `bizcity_twinbrain_memory_tools_enabled` cho phép tắt khẩn cấp.
		// Khi ON, append schema 3 tool vào system prompt → LLM được phép emit
		// `<tool name="memory_*">{...}</tool>` inline trong câu trả lời.
		// Dispatcher (gọi từ Runtime sau final_done) parse + execute + rewrite
		// chip `[mem:U#<id>]`.
		$tools_enabled = (bool) apply_filters(
			'bizcity_twinbrain_memory_tools_enabled',
			true,
			$opts
		);
		if ( $tools_enabled && class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) ) {
			$tools_block = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->render_prompt_section();
			if ( $tools_block !== '' ) {
				$system .= "\n\n" . $tools_block
					. "\n## MEMORY TOOL USAGE NOTES\n"
					. "- CH\u1ec8 d\u00f9ng memory tool khi th\u1ef1c s\u1ef1 c\u1ea7n (user y\u00eau c\u1ea7u, ho\u1eb7c nh\u1eadn th\u1ea5y fact m\u1edbi quan tr\u1ecdng).\n"
					. "- Tool block c\u00f3 th\u1ec3 \u0111\u1eb7t INLINE gi\u1eefa c\u00e2u tr\u1ea3 l\u1eddi \u2014 system s\u1ebd t\u1ef1 strip block v\u00e0 thay b\u1eb1ng citation `[mem:U#<id>]` (cho memory_remember).\n"
					. "- KH\u00d4NG g\u1ecdi `memory_remember` cho th\u00f4ng tin \u0111\u00e3 c\u00f3 trong memory recall block \u1edf d\u01b0\u1edbi.\n"
					. "- KH\u00d4NG g\u1ecdi `memory_recall` n\u1ebfu memory block \u0111\u00e3 \u0111\u1ee7 th\u00f4ng tin.\n"
					. "- T\u1ed1i \u0111a 3 write call (`memory_remember` + `memory_forget` c\u1ed9ng d\u1ed3n) + 5 `memory_recall` m\u1ed7i turn.";
			}
		}

		$user_parts = [
			"## C\u00c2U H\u1ed0I C\u1ee6A USER\n" . $prompt,
			$synth_block,
		];

		/* [2026-06-04 Johnny Chu] PHASE-A C.3b — Full-context injection.
		 * Astro (và các mode cần ngữ cảnh dài) truyền `extra_context_md` để
		 * đưa NGUYÊN dữ liệu (vd transit markdown 8KB) vào prompt mà KHÔNG bị
		 * cắt bởi ANS_TRUNC=1200 như nhánh synthesizer. Cap rộng (12KB) để
		 * tránh prompt nổ token; filter cho phép tuỳ chỉnh. */
		$extra_ctx = trim( (string) ( $opts['extra_context_md'] ?? '' ) );
		if ( $extra_ctx !== '' ) {
			$extra_cap = (int) apply_filters(
				'bizcity_twinbrain_final_compose_extra_ctx_cap',
				12000,
				$opts
			);
			$extra_label = (string) ( $opts['extra_context_label'] ?? 'FULL CONTEXT DATA (read carefully)' );
			$user_parts[] = "### " . $extra_label . "\n"
				. mb_substr( $extra_ctx, 0, max( 1000, $extra_cap ) );

			// [2026-06-10 Johnny Chu] ASTRO-CITE 3 — inject explicit citation rule
			// into system prompt when astro token URLs are present in context.
			// Without this, LLM treats [astro:*#URL] as data, not as citable links.
			if ( strpos( $extra_ctx, '[astro:' ) !== false ) {
				$astro_subject_line_min = isset( $opts['astro_subject_line_min'] )
					? max( 8, (int) $opts['astro_subject_line_min'] )
					: 20;
				$astro_subject_line_max = isset( $opts['astro_subject_line_max'] )
					? max( $astro_subject_line_min, (int) $opts['astro_subject_line_max'] )
					: max( $astro_subject_line_min, 24 );
				$astro_subject_mode = isset( $opts['astro_subject_mode'] )
					? sanitize_key( (string) $opts['astro_subject_mode'] )
					: 'temporal_signal';
				$astro_deep_analysis_requested = ! empty( $opts['astro_deep_analysis_requested'] );
				$astro_focus_domains = isset( $opts['astro_focus_domains'] ) && is_array( $opts['astro_focus_domains'] )
					? array_values( array_unique( array_filter( array_map( 'sanitize_key', $opts['astro_focus_domains'] ) ) ) )
					: array();

				$system .= "\n10. Trong phần DỮ LIỆU CHIÊM TINH đầu context có các token dạng `[astro:natal#URL]` và `[astro:transit#URL]`. "
					. "KHI đề cập bản đồ sao cá nhân hoặc lịch quá cảnh trong câu trả lời, BẮT BUỘC copy nguyên token đó vào đúng vị trí câu văn. "
					. "Ví dụ: \"...ảnh hưởng đến bản đồ sao của bạn [astro:natal#https://...]\". "
					. "KHÔNG viết lại URL, KHÔNG bỏ token, KHÔNG thay bằng dấu ngoặc vuông khác.";
				// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — astro response contract: 3 blocks + citation boundary.
				$system .= "\n11. Đây là chế độ ASTRO. BẮT BUỘC chia câu trả lời thành ĐÚNG 3 block, theo thứ tự:\n"
					. "- `## 1) Chủ thể` : xác định người được luận + thông tin natal/birth liên quan, có [astro:natal#URL] nếu dùng dữ liệu bản đồ sao.\n"
					. "- `## 2) Transit` : phân tích ảnh hưởng theo cửa sổ thời gian đã parse (không đảo lộn ngày), có [astro:transit#URL] hoặc [astro:transit_day#URL] cho từng luận điểm.\n"
					. "- `## 3) Kết luận` : kết luận ngắn gọn và hành động gợi ý.\n"
					. "Không thêm block thứ 4, không gộp 3 block vào một đoạn.";
				$system .= "\n12. Ranh giới citation bắt buộc: token `[mem:*]` chỉ dùng cho dữ kiện memory; token `[astro:*]` chỉ dùng cho dữ kiện chiêm tinh. "
					. "Không được dùng chéo namespace trong cùng luận điểm.";
			}
			if ( strpos( $extra_ctx, '## PHÂN TÍCH TRANSIT THEO TỪNG NGÀY (DETERMINISTIC)' ) !== false ) {
				$system .= "\n13. Với câu hỏi transit nhiều ngày, BẮT BUỘC trả lời theo thứ tự từng ngày trong cửa sổ (đủ tất cả ngày), "
					. "mỗi ngày có nhận định ngắn. Sau đó mới có mục 'Kết luận cuối' chọn 1 ngày tốt nhất dựa trên dữ liệu từng ngày. "
					. "KHÔNG được bỏ qua phần foreach theo ngày.";
			}
				// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — enforce subject depth contract.
				$system .= "\n14. Block `## 1) Chủ thể` BẮT BUỘC dài khoảng {$astro_subject_line_min}-{$astro_subject_line_max} dòng. "
					. "Không rút gọn còn vài câu. Ưu tiên diễn giải natal + tính cách + động lực sự nghiệp + xu hướng hành vi, "
					. "và gắn token [astro:natal#...] / [mem:*] đúng namespace khi dùng dữ kiện.";
				if ( strpos( $astro_subject_mode, 'subject_default_tomorrow' ) === 0 ) {
					$system .= "\n15. Mode này là KHÔNG có tín hiệu thời gian trong câu hỏi: "
						. "Block `## 2) Transit` mặc định nói về ngày mai (start_offset=1), giữ ngắn gọn hơn block Chủ thể, "
						. "và vẫn phải có [astro:transit_day#...] hoặc [astro:transit#...] cho kết luận transit.";
				} else {
					$system .= "\n15. Mode này có tín hiệu thời gian/tương lai: vẫn giữ block Chủ thể ở độ dài yêu cầu trên, "
						. "sau đó mới mở rộng block Transit theo đúng cửa sổ thời gian đã parse.";
				}
				// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — hard rule for
				// deep-analysis prompts (chi tiết/kỹ lưỡng/phân tích sâu).
				if ( $astro_deep_analysis_requested ) {
					$domain_labels = array(
						'career'  => 'sự nghiệp/công việc',
						'finance' => 'tài chính/tiền bạc',
						'love'    => 'tình duyên/tình cảm',
						'family'  => 'gia đình',
						'life'    => 'cuộc đời/đường đời',
					);
					$focus_label_rows = array();
					foreach ( $astro_focus_domains as $_d ) {
						if ( isset( $domain_labels[ $_d ] ) ) {
							$focus_label_rows[] = $domain_labels[ $_d ];
						}
					}
					if ( empty( $focus_label_rows ) ) {
						$focus_label_rows = array_values( $domain_labels );
					}
					$system .= "\n16. User đang yêu cầu phân tích sâu/chi tiết: trong block `## 1) Chủ thể`, "
						. "bắt buộc triển khai thành nhiều đoạn rõ ràng theo các trục: "
						. implode( '; ', $focus_label_rows )
						. ". Mỗi trục nêu hiện trạng + điểm mạnh + rủi ro + hành động gợi ý ngắn.";
					$system .= "\n17. Khi có mode deep, tuyệt đối không trả lời chung chung. "
						. "Nếu thiếu dữ kiện cho một trục, phải nói rõ giả định và mức chắc chắn; "
						. "không được bỏ qua trục user đã hỏi.";
				}
		}

		/* Wave 2.8 (TBR.MEM-3) — prepend Memory Recall block to user message
		 * so the LLM sees user identity / preferences / explicit "hãy nhớ"
		 * notes before reading synthesizer output. Block already includes
		 * `[mem:U#<id>]` tokens for citation echo. */
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );
		if ( $memory_block !== '' ) {
			array_unshift( $user_parts, $memory_block );
		}
		if ( $nb_block !== '' ) {
			$user_parts[] = "### NOTEBOOK PERSPECTIVES (compact)\n" . $nb_block;
		}
		if ( $web_block !== '' ) {
			$user_parts[] = "### WEB SOURCES + CITATION MAP\n" . $web_block;
		}
		$user_parts[] = "Vi\u1ebft c\u00e2u tr\u1ea3 l\u1eddi cu\u1ed1i c\u00f9ng cho user theo nguy\u00ean t\u1eafc tr\u00ean.";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => implode( "\n\n", $user_parts ) ],
		];
	}

	private function render_nb_compact( array $rows ): string {
		if ( ! $rows ) return '';
		$out = [];
		foreach ( $rows as $a ) {
			$out[] = sprintf(
				'- **%s** (id=%d, stance=%s/%.2f): %s',
				(string) ( $a['label']       ?? '' ),
				(int)    ( $a['notebook_id'] ?? 0 ),
				(string) ( $a['stance']      ?? 'unknown' ),
				(float)  ( $a['confidence']  ?? 0.0 ),
				mb_substr( wp_strip_all_tags( (string) ( $a['answer_md'] ?? '' ) ), 0, 360 )
			);
		}
		return implode( "\n", $out );
	}

	private function render_web_compact( array $rows ): string {
		if ( ! $rows ) return '';
		$out = [];
		foreach ( $rows as $row ) {
			$mode    = strtoupper( (string) ( $row['mode'] ?? 'web' ) );
			$results = (array) ( $row['results'] ?? [] );
			$ans     = trim( wp_strip_all_tags( (string) ( $row['answer_md'] ?? '' ) ) );

			$out[] = sprintf( '#### WEB · %s (%d results)', $mode, count( $results ) );
			if ( $ans !== '' ) {
				$out[] = '_' . mb_substr( $ans, 0, 600 ) . '_';
			}
			foreach ( array_slice( $results, 0, 8 ) as $i => $r ) {
				$out[] = sprintf(
					'  [web:%d#%s] %s — %s — %s',
					$i + 1,
					(string) ( $r['url']    ?? '' ),
					(string) ( $r['title']  ?? '' ),
					(string) ( $r['domain'] ?? '' ),
					mb_substr( (string) ( $r['snippet'] ?? '' ), 0, self::WEB_SNIPPET )
				);
			}
		}
		return implode( "\n", $out );
	}

	/* =================================================================
	 *  Chat-mode compose (TBR.W18 — 2026-05-28)
	 * ================================================================ */

	/**
	 * Stream a casual chat answer using ONLY memory_block + prompt.
	 *
	 * Used by Runtime auto-degrade branch when K=0 candidates,
	 * K=0 tool candidates, web_mode=off but Memory_Recall produced
	 * a non-empty block (≥ MIN bytes). Bypasses Perspective / Web /
	 * Tool / Synthesizer layers — only the user-facing answer is
	 * generated. The prompt is intentionally lighter: no "BÁO CÁO
	 * TỪ SYNTHESIZER" framing, no citation enforcement (memory
	 * citations `[mem:U#<id>]` still allowed because memory_block
	 * embeds them).
	 *
	 * @param string        $trace_id  Turn trace id.
	 * @param string        $prompt    User prompt.
	 * @param array         $opts      { memory_block, guru_id?, model?, locale? }
	 * @param callable|null $on_token  fn($delta, $accumulated) for SSE relay.
	 * @return array {success, answer_md, model, tokens, ms, fallback, error}
	 */
	public function compose_chat_stream(
		string $trace_id,
		string $prompt,
		array $opts = [],
		$on_token = null
	): array {
		$t0 = microtime( true );

		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );

		if ( ! $this->gateway_ready() ) {
			$msg = $memory_block !== ''
				? "Mình đã ghi nhớ một số thông tin về bạn nhưng hiện chưa kết nối được hệ thống LLM. Vui lòng thử lại sau."
				: '';
			if ( is_callable( $on_token ) && $msg !== '' ) {
				call_user_func( $on_token, $msg, $msg );
			}
			return [
				'success'   => $msg !== '',
				'answer_md' => $msg,
				'model'     => '',
				'tokens'    => 0,
				'ms'        => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'fallback'  => 'gateway_unavailable',
				'error'     => '',
			];
		}

		$has_guru = ! empty( $opts['guru_id'] );
		$ans_cap  = $has_guru ? 500 : 320;

		$persona_hint = $has_guru
			? "\n6. Giữ giọng persona/guru đang được bind — không dùng giọng báo cáo trung tính."
			: '';

		// [2026-06-03 Johnny Chu] HOTFIX — Companion mode: empathic system
		// prompt khi user chọn pill `Chat` (web_mode='chat'). Dùng memory để
		// thấu cảm / tâm sự / đồng hành, không phải đưa kiến thức.
		$companion = ! empty( $opts['companion_mode'] );

		if ( $companion ) {
			$system = <<<SYS
Bạn là **người bạn đồng hành** của user — một presence ấm áp, lắng nghe, biết ghi nhớ những gì user đã chia sẻ. KHÔNG có notebook / web research / tool nào cho turn này, và đó là chủ đích — user muốn TRÒ CHUYỆN, không cần tra cứu kiến thức.

NGUYÊN TẮC GIAO TIẾP:
1. Giọng văn ấm, gần gũi, tự nhiên như nhắn tin với một người bạn thân. Tiếng Việt. Tối đa {$ans_cap} từ.
2. Ưu tiên **lắng nghe và phản chiếu cảm xúc** trước, gợi ý / lời khuyên sau (và chỉ khi user thực sự xin). Đặt câu hỏi mở khi phù hợp để user mở lòng tiếp.
3. Dùng MEMORY BLOCK để nhớ tên / sở thích / chuyện cũ user đã kể — gọi đúng tên, nhắc lại context cũ một cách tinh tế (không liệt kê dài dòng). Khi nhắc tới fact từ memory, vẫn echo `[mem:U#<id>]` / `[mem:E#<id>]` / `[mem:R#<id>]` ngay cạnh câu văn.
4. KHÔNG bịa fact ngoài MEMORY BLOCK. Nếu user hỏi info cụ thể không có trong memory → thành thật "mình chưa biết phần này", và quay lại dòng cảm xúc / câu chuyện chính.
5. KHÔNG dùng heading lớn / bullet list / fence code / JSON. Văn xuôi, 1-3 đoạn ngắn. Có thể dùng emoji nhẹ nhàng (1-2 cái) khi phù hợp tâm trạng.{$persona_hint}

NHỚ: User mở chế độ này vì cần một người bạn, không cần một trợ lý kiến thức.
SYS;
		} else {
			$system = <<<SYS
Bạn là TwinBrain ở **chế độ trò chuyện** — không có notebook / web research / tool nào cho turn này. Chỉ có MEMORY BLOCK (lịch sử ngắn + ghi chú user dặn) và câu hỏi hiện tại.

NGUYÊN TẮC:
1. Trả lời tự nhiên, ngắn gọn, giọng trò chuyện (chat), tiếng Việt. Tối đa {$ans_cap} từ.
2. KHÔNG bịa fact ngoài MEMORY BLOCK. Nếu user hỏi info ngoài phạm vi memory → trả lời chân thật "Mình chưa có thông tin chi tiết về ý này, bạn cho mình thêm context nhé?" hoặc gợi ý user gắn notebook / bật web search.
3. Nếu MEMORY BLOCK chứa fact liên quan câu hỏi → echo token `[mem:U#<id>]` / `[mem:E#<id>]` / `[mem:R#<id>]` ngay cạnh câu văn sử dụng fact đó.
4. KHÔNG dùng heading lớn / bullet list trừ khi câu trả lời thực sự là enumerate. Ưu tiên 1-3 đoạn văn ngắn.
5. KHÔNG xuất JSON, KHÔNG ```fence.{$persona_hint}
SYS;
		}

		// Memory tool schema (opt-in) — same filter as compose_stream.
		$tools_enabled = (bool) apply_filters(
			'bizcity_twinbrain_memory_tools_enabled',
			true,
			$opts
		);
		if ( $tools_enabled && class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) ) {
			$tools_block = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->render_prompt_section();
			if ( $tools_block !== '' ) {
				$system .= "\n\n" . $tools_block
					. "\n## MEMORY TOOL USAGE NOTES (chat mode)\n"
					. "- Chế độ chat chủ yếu để trả lời nhanh; chỉ gọi `memory_remember` khi user dặn rõ ('hãy nhớ ...').\n"
					. "- KHÔNG gọi `memory_recall` — block memory đã có sẵn ở dưới.\n"
					. "- Tối đa 2 write call mỗi turn.";
			}
		}

		$user_parts = [];
		if ( $memory_block !== '' ) {
			$user_parts[] = $memory_block;
		}
		$user_parts[] = "## CÂU HỎI CỦA USER\n" . $prompt;
		$user_parts[] = "Trả lời theo nguyên tắc ở system prompt.";

		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => implode( "\n\n", $user_parts ) ],
		];

		$client = BizCity_LLM_Client::instance();
		$model  = (string) ( $opts['model'] ?? '' );
		if ( $model === '' ) {
			$model = (string) apply_filters(
				'bizcity_twinbrain_final_compose_chat_model',
				$client->get_model( 'chat' )
			);
		}

		$accumulated = '';
		$delta_n     = 0;
		$relay = function ( $delta, $full ) use ( &$accumulated, &$delta_n, $on_token ) {
			$accumulated = (string) $full;
			$delta_n++;
			if ( is_callable( $on_token ) ) {
				call_user_func( $on_token, (string) $delta, (string) $full );
			}
		};

		$result = $client->chat_stream(
			$messages,
			[
				'purpose'     => self::PURPOSE . '_chat',
				'model'       => $model,
				'temperature' => self::TEMPERATURE,
				'max_tokens'  => $has_guru ? self::MAX_TOKENS_GURU : self::MAX_TOKENS,
				'timeout'     => self::TIMEOUT_S,
				// [2026-07-07 Johnny Chu] HOTFIX — forward keepalive callback so
				// runtime can emit `final_keepalive` SSE while waiting next token.
				'on_keepalive' => isset( $opts['on_keepalive'] ) ? $opts['on_keepalive'] : null,
				'extra_body'  => [
					'site_url' => home_url(),
					'trace_id' => $trace_id,
					'mode'     => $companion ? 'chat_companion' : 'chat_auto_degrade',
				],
			],
			$relay
		);

		$elapsed    = (int) ( ( microtime( true ) - $t0 ) * 1000 );
		$final_text = trim( (string) ( $result['message'] ?? $accumulated ) );

		if ( empty( $result['success'] ) || $final_text === '' ) {
			$fallback_text = $memory_block !== ''
				? "Mình chưa tổng hợp được câu trả lời đầy đủ, nhưng có ghi nhớ một số thông tin liên quan. Bạn có thể hỏi lại với context cụ thể hơn không?"
				: '';
			if ( is_callable( $on_token ) && $fallback_text !== '' && $delta_n === 0 ) {
				call_user_func( $on_token, $fallback_text, $fallback_text );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][final-compose-chat] trace=' . $trace_id
					. ' fallback err=' . ( $result['error'] ?? 'empty' )
					. ' deltas=' . $delta_n
				);
			}
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — pass quota_exhausted + quota_layer through.
			return [
				'success'         => $fallback_text !== '',
				'answer_md'       => $fallback_text,
				'model'           => (string) ( $result['model'] ?? $model ),
				'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
				'ms'              => $elapsed,
				'fallback'        => 'chat_stream_empty:' . ( $result['error'] ?? 'unknown' ),
				'error'           => (string) ( $result['error'] ?? '' ),
				'quota_exhausted' => ! empty( $result['quota_exhausted'] ),
				'quota_layer'     => isset( $result['quota_layer'] ) ? (string) $result['quota_layer'] : '',
				'tier'            => isset( $result['tier'] ) ? (string) $result['tier'] : '',
			];
		}

		return [
			'success'         => true,
			'answer_md'       => $final_text,
			'model'           => (string) ( $result['model'] ?? $model ),
			'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
			'ms'              => $elapsed,
			'fallback'        => '',
			'error'           => '',
			'quota_exhausted' => false,
			'quota_layer'     => '',
			'tier'            => '',
		];
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================ */

	private function gateway_ready(): bool {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return false;
		$c = BizCity_LLM_Client::instance();
		return method_exists( $c, 'is_ready' ) ? $c->is_ready() : true;
	}
}
