<?php
/**
 * BizCity TwinBrain Synthesizer — Stage 4.
 *
 * Anti-averaging summarizer. Takes K perspective answers + (optional) tool
 * results and asks an `medium` LLM (gateway-only / R-GW) to produce structured
 * JSON. The prompt is LOCKED to forbid averaging stances — explicit consensus
 * vs tensions sections are mandatory (R-MPR-5). Output schema:
 *
 *   {
 *     consensus:      [...]   // points all/most perspectives agree on
 *     tensions:       [...]   // explicit disagreements (verbatim)
 *     recommendation: '...'   // only when user asked for advice
 *     answer_md:      '...'   // every factual sentence carries [nb:ID/pID]
 *     citations:      [...]   // extracted citation tokens (R-BRAIN-1)
 *   }
 *
 * Failure modes (all degrade safely instead of throwing):
 *   • gateway not configured → text-fallback synthesizer (concat perspectives).
 *   • LLM JSON malformed     → regex-extract sections, fill missing keys with [].
 *   • zero successful subs   → still produce an honest "no perspective answered"
 *                              answer with explicit error annotation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Synthesizer {

	const SYNTH_PURPOSE     = 'twinbrain_synthesis';
	const SYNTH_TEMPERATURE = 0.2;
	const MAX_TOKENS        = 1200;
	/* PHASE-0.35 / F7.E3 — deeper budget when guru bound. */
	const MAX_TOKENS_GURU   = 2000;
	const MAX_TOOL_RESULT   = 800;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public function synthesize( string $trace_id, string $prompt, array $answers, array $tool_results = [], array $opts = array() ): array {
		// No perspectives at all → degraded honest answer.
		if ( empty( $answers ) ) {
			return $this->empty_synthesis( $prompt, 'no_perspectives', $opts );
		}

		// Gateway not configured → text-fallback (preserves contract).
		if ( ! $this->gateway_ready() ) {
			return $this->text_fallback( $prompt, $answers, 'gateway_unavailable' );
		}

		$messages = $this->build_messages( $prompt, $answers, $tool_results, $opts );

		$client = BizCity_LLM_Client::instance();
		$model  = (string) apply_filters(
			'bizcity_twinbrain_synth_model',
			$client->get_model( 'chat' )
		);

		$result = $client->chat( $messages, [
			'purpose'     => self::SYNTH_PURPOSE,
			'model'       => $model,
			'temperature' => self::SYNTH_TEMPERATURE,
			'max_tokens'  => ! empty( $opts['guru_id'] ) ? self::MAX_TOKENS_GURU : self::MAX_TOKENS,
			'extra_body'  => [
				// Hint JSON mode for providers that honour it (OpenAI/OpenRouter).
				'response_format' => [ 'type' => 'json_object' ],
			],
		] );

		if ( empty( $result['success'] ) ) {
			error_log( '[TwinBrain][synth] gateway error: ' . ( $result['error'] ?? 'unknown' ) );
			return $this->text_fallback( $prompt, $answers, 'gateway_error:' . ( $result['error'] ?? '' ) );
		}

		$parsed = $this->parse_synthesis( (string) ( $result['message'] ?? '' ) );
		$parsed['citations'] = $this->merge_citations( $answers, $parsed['answer_md'] );
		$parsed['model']     = (string) ( $result['model'] ?? $model );
		$parsed['tokens']    = (int) ( $result['usage']['total_tokens'] ?? 0 );
		return $parsed;
	}

	/* =================================================================
	 *  Prompt
	 * ================================================================ */

	private function build_messages( string $prompt, array $answers, array $tool_results, array $opts = array() ): array {
		// TBR.W7/W10 (2026-05-21): split incoming $answers into
		//   (a) local notebook perspectives (mode unset / 'local')
		//   (b) web research rows from Stage 2.5 (mode='quick'|'deep')
		// Web rows have a different shape (no notebook_id) so they get a
		// dedicated block + the prompt is extended to teach the LLM the
		// `[web:N#URL]` citation token alongside `[nb:X/pY]`.
		$nb_blocks  = [];
		$web_blocks = [];
		foreach ( $answers as $a ) {
			$mode = (string) ( $a['mode'] ?? '' );
			if ( $mode === 'quick' || $mode === 'deep' ) {
				$web_blocks[] = $this->render_web_block( $a );
				continue;
			}
			$nb_blocks[] = sprintf(
				"### Notebook \"%s\" (id=%d)\n- STANCE: %s\n- CONFIDENCE: %.2f\n- ANSWER:\n%s",
				(string) ( $a['label'] ?? '' ),
				(int)    ( $a['notebook_id'] ?? 0 ),
				(string) ( $a['stance']     ?? 'unknown' ),
				(float)  ( $a['confidence'] ?? 0.0 ),
				wp_strip_all_tags( (string) ( $a['answer_md'] ?? '' ) )
			);
		}
		$perspectives_block = $nb_blocks
			? implode( "\n\n", $nb_blocks )
			: "_Không có notebook nào trả lời — toàn bộ stance='unknown' hoặc câu hỏi ngoài KG._";
		$web_block = $web_blocks
			? "\n\n## WEB RESEARCH (Stage 2.5)\n" . implode( "\n\n", $web_blocks )
			: '';

		$tools_block = '';
		if ( ! empty( $tool_results ) ) {
			$tool_lines = [];
			foreach ( $tool_results as $t ) {
				$tool_lines[] = sprintf(
					'- `%s` → %s',
					(string) ( $t['skill'] ?? '' ),
					mb_substr( (string) ( $t['result'] ?? '' ), 0, self::MAX_TOOL_RESULT )
				);
			}
			$tools_block = "\n\n### TOOL RESULTS\n" . implode( "\n", $tool_lines );
		}
		$owner_block = $this->render_context_bank_owner_block( (array) ( $opts['context_bank_owner_records'] ?? array() ) );

		/* PHASE-0.35 / F7.E3 — deeper answer when guru bound. Tarot-style
		 * answers need ~800 words to fit ImEN · ý nghĩa · thuận · nghịch ·
		 * tình huống · lời khuyên. Default 400 stays for whole-KG mode. */
		$has_guru = ! empty( $opts['guru_id'] );
		$ans_cap  = $has_guru ? 800 : 400;
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — apply surface-level grounding policy before Guru defaults.
		$twinweb_policy = $this->twinweb_grounding_policy( $opts );
		$grounding_profile = is_array( $twinweb_policy ) ? (string) ( $twinweb_policy['profile'] ?? 'balanced' ) : 'strict';
		$persona_hint = $has_guru
			? "\n8. answer_md PHẢI giữ nguyên khung cấu trúc persona mà các lăng kính trả về (không cắt bằng những heading như 'Ý nghĩa', 'Thuận', 'Nghịch', 'Tình huống', 'Lời khuyên'). Tổng hợp từng section thay vì collapse về 1 đoạn văn."
			: '';
		$twinweb_prompt_block = is_array( $twinweb_policy )
			? $this->render_twinweb_policy_block( $twinweb_policy )
			: '';

		// TBR.W10 — enable web citation rule only when a web block is in scope.
		$web_rule = $this->render_grounding_rule( $web_blocks, $grounding_profile );

		/* [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.3 — "tóm tắt cuộc họp/ghi
		 * chú hôm nay" framing. This is UX/prompt-template polish only: the
		 * candidates are already scoped to today's captured notebooks by
		 * BizCity_TwinBrain_Runtime's S5.2 force_ids override before this
		 * class ever sees them. No parallel summarizer, no new retrieval —
		 * just an extra instruction line asking the SAME anti-averaging
		 * consensus/tensions schema to read like a meeting/day recap instead
		 * of a generic Q&A answer. */
		$day_scope_hint = ! empty( $opts['day_scope_summary'] )
			? "\n10. Prompt này hỏi về ghi chú/cuộc họp \"hôm nay\" — answer_md PHẢI trình bày dạng tóm tắt theo trình tự nội dung đã ghi (theo notebook/nguồn), nêu rõ các điểm chính, quyết định và việc cần làm (nếu có) thay vì trả lời một câu hỏi rời rạc; vẫn giữ nguyên schema JSON và citation token."
			: '';

		$system = <<<SYS
Bạn là Synthesizer của TwinBrain — một bộ tổng hợp đa lăng kính.

QUY TẮC TUYỆT ĐỐI:
1. KHÔNG được trung bình hoá lập trường. Nếu 3 notebook nói YES, 2 nói NO, bạn KHÔNG được kết luận "có lẽ" — phải nêu rõ CONSENSUS và TENSIONS riêng biệt.
2. CONSENSUS = điểm mà từ 2 lăng kính trở lên đồng thuận, kèm tên notebook.
3. TENSIONS = các xung đột verbatim — viết rõ "Notebook X nói A, Notebook Y phản bác B".
4. RECOMMENDATION chỉ điền khi user xin lời khuyên rõ ràng (verb: "nên", "có nên", "should", "recommend").
5. {$this->render_notebook_citation_rule( $grounding_profile )}
6. Trả về JSON object thuần — KHÔNG markdown wrapper, KHÔNG ```json fence.
{$web_rule}{$persona_hint}{$twinweb_prompt_block}{$day_scope_hint}

SCHEMA OUTPUT (BẮT BUỘC):
{
  "consensus":      ["string", ...],
  "tensions":       ["string", ...],
  "recommendation": "string (rỗng nếu không xin advice)",
  "answer_md":      "string markdown ≤{$ans_cap} từ, có citation token"
}
SYS;

		$user = "## CÂU HỐI\n{$prompt}\n\n## CÁC LĂNG KÍNH (LOCAL)\n{$perspectives_block}{$web_block}{$tools_block}{$owner_block}\n\nTrả về JSON theo schema.";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];
	}

	private function render_context_bank_owner_block( array $owner_records ): string {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — let MPR consume bounded Skill bodies only after canonical pointer authorization and owner resolution.
		$lines = array();
		$count = 0;
		foreach ( $owner_records as $owner_record ) {
			if ( ! is_array( $owner_record ) || (string) ( $owner_record['owner'] ?? '' ) !== 'core/skills' || ! is_array( $owner_record['record'] ?? null ) ) {
				continue;
			}
			$record = $owner_record['record'];
			$content = trim( wp_strip_all_tags( (string) ( $record['content'] ?? $record['content_md'] ?? '' ) ) );
			if ( $content === '' ) {
				continue;
			}
			$lines[] = sprintf(
				"### Skill owner reference (%s)\n- KEY: %s\n- VERSION: %s\n- CONTENT:\n%s",
				(string) ( $record['title'] ?? $record['slug'] ?? $record['skill_key'] ?? 'Skill' ),
				(string) ( $record['skill_key'] ?? '' ),
				(string) ( $record['version'] ?? '' ),
				mb_substr( $content, 0, 2000 )
			);
			$count++;
			if ( $count >= 10 ) {
				break;
			}
		}
		return empty( $lines ) ? '' : "\n\n## CANONICAL SKILL OWNER REFERENCES\n" . implode( "\n\n", $lines );
	}

	/**
	 * TwinWeb prompt/grounding policy. Returns null for non-TwinWeb or disabled config.
	 */
	private function twinweb_grounding_policy( array $opts ) {
		$surface = isset( $opts['surface'] ) ? sanitize_key( (string) $opts['surface'] ) : '';
		$policy  = isset( $opts['twinweb_grounding_policy'] ) && is_array( $opts['twinweb_grounding_policy'] )
			? $opts['twinweb_grounding_policy']
			: array();
		if ( $surface !== 'twinweb' || empty( $policy ) || empty( $policy['enabled'] ) ) {
			return null;
		}
		return $policy;
	}

	/**
	 * Render notebook citation rule by grounding profile.
	 */
	private function render_notebook_citation_rule( string $profile ): string {
		if ( $profile === 'loose' ) {
			return 'answer_md có thể diễn giải tự nhiên ngoài RAG khi cần, nhưng các dữ kiện/trích dẫn quan trọng lấy từ notebook PHẢI kèm token `[nb:<notebook_id>/p<passage_id>]`; nếu suy luận ngoài notebook thì nói rõ là suy luận/mở rộng.';
		}
		if ( $profile === 'balanced' ) {
			return 'answer_md PHẢI chèn citation token `[nb:<notebook_id>/p<passage_id>]` cho các luận điểm chính có nguồn từ notebook; không cần cite lặp lại ở mọi câu diễn giải/phụ trợ.';
		}
		return 'answer_md PHẢI chèn citation token `[nb:<notebook_id>/p<passage_id>]` ở mỗi luận điểm có nguồn từ ANSWER của notebook.';
	}

	/**
	 * Render web citation rule by grounding profile.
	 */
	private function render_grounding_rule( array $web_blocks, string $profile ): string {
		if ( empty( $web_blocks ) ) {
			return "7. Turn này không có web research — chỉ dùng [nb:X/pY] khi trích notebook.";
		}
		if ( $profile === 'loose' ) {
			return "7. Khi dùng WEB RESEARCH cho dữ kiện quan trọng, dùng token `[web:<N>#<URL>]`; có thể đưa kiến thức nền ngoài RAG nếu hữu ích, nhưng phải phân biệt rõ phần có nguồn với phần suy luận/tư vấn.";
		}
		if ( $profile === 'balanced' ) {
			return "7. Khi dùng thông tin chính từ ## WEB RESEARCH, citation dùng token `[web:<N>#<URL>]` đúng index trong CITATION MAP. CONSENSUS / TENSIONS nên so sánh nguồn nội bộ vs web khi có khác biệt đáng kể.";
		}
		return "7. Khi dùng thông tin từ ## WEB RESEARCH, citation BẮT BUỘC dùng token `[web:<N>#<URL>]` (đúng index trong CITATION MAP ở block đó). KHÔNG đổi [web:N] thành footnote số. CONSENSUS / TENSIONS phải so sánh nguồn nội bộ (notebook) vs nguồn ngoại (web) một cách tường minh khi cả hai cùng có dữ liệu.";
	}

	/**
	 * Render TwinWeb/Guru prompt overrides.
	 */
	private function render_twinweb_policy_block( array $policy ): string {
		$lines = array();
		$profile = isset( $policy['profile'] ) ? sanitize_key( (string) $policy['profile'] ) : 'balanced';
		$citation_mode = isset( $policy['citation_mode'] ) ? sanitize_key( (string) $policy['citation_mode'] ) : 'key_claims';
		$lines[] = '';
		$lines[] = '9. TWINWEB GROUNDING PROFILE = `' . $profile . '`; citation_mode = `' . $citation_mode . '`. Đây là cấu hình surface TwinWeb và ưu tiên hơn mặc định Guru cho turn này.';

		$twinweb_prompt = trim( wp_strip_all_tags( (string) ( $policy['twinweb_system_prompt'] ?? '' ) ) );
		if ( $twinweb_prompt !== '' ) {
			$lines[] = "\n## TWINWEB SYSTEM PROMPT OVERRIDE\n" . mb_substr( $twinweb_prompt, 0, 1600 );
		}

		$override_guru = ! empty( $policy['override_guru'] );
		$guru_prompt   = trim( wp_strip_all_tags( (string) ( $policy['guru_system_prompt'] ?? '' ) ) );
		if ( $override_guru && $guru_prompt !== '' ) {
			$lines[] = "\n## GURU SYSTEM PROMPT OVERRIDE\n" . mb_substr( $guru_prompt, 0, 1600 );
		}

		return "\n" . implode( "\n", $lines );
	}

	/**
	 * TBR.W7/W10 (2026-05-21) — render a Stage 2.5 web row (Quick or Deep)
	 * as a synthesizer block. Includes the citation map so the LLM can echo
	 * stable `[web:N#URL]` tokens in its final answer_md.
	 */
	private function render_web_block( array $row ): string {
		$mode    = (string) ( $row['mode']  ?? 'web' );
		$label   = strtoupper( $mode );
		$ans     = wp_strip_all_tags( (string) ( $row['answer_md'] ?? '' ) );
		$err     = (string) ( $row['error'] ?? '' );
		$results = (array)  ( $row['results'] ?? [] );

		$lines = [ sprintf( '### WEB · %s (cite=%d, results=%d%s)',
			$label,
			(int) ( $row['citation_count'] ?? 0 ),
			count( $results ),
			$err !== '' ? ', error=' . $err : ''
		) ];

		if ( $ans !== '' ) {
			$lines[] = '- ANSWER:';
			$lines[] = mb_substr( $ans, 0, 1600 );
		}

		if ( ! empty( $results ) ) {
			$lines[] = '- CITATION MAP (dùng cho [web:N#URL]):';
			foreach ( array_slice( $results, 0, 10 ) as $i => $r ) {
				$lines[] = sprintf(
					'  [web:%d#%s] %s — %s — %s',
					$i + 1,
					(string) ( $r['url']    ?? '' ),
					(string) ( $r['title']  ?? '' ),
					(string) ( $r['domain'] ?? '' ),
					mb_substr( (string) ( $r['snippet'] ?? '' ), 0, 180 )
				);
			}
		}
		return implode( "\n", $lines );
	}

	/* =================================================================
	 *  Parsing
	 * ================================================================ */

	private function parse_synthesis( string $message ): array {
		$out = [
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'answer_md'      => '',
			'citations'      => [],
		];

		// Strip optional ```json fences a model might emit despite instructions.
		$msg = trim( $message );
		if ( strpos( $msg, '```' ) !== false ) {
			$msg = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $msg );
		}

		$decoded = json_decode( $msg, true );
		if ( is_array( $decoded ) ) {
			$out['consensus']      = array_values( array_map( 'strval', (array) ( $decoded['consensus']      ?? [] ) ) );
			$out['tensions']       = array_values( array_map( 'strval', (array) ( $decoded['tensions']       ?? [] ) ) );
			$out['recommendation'] = (string) ( $decoded['recommendation'] ?? '' );
			$out['answer_md']      = (string) ( $decoded['answer_md']      ?? '' );
			if ( $out['answer_md'] === '' ) {
				$out['answer_md'] = $this->compose_answer_md( $out );
			}
			return $out;
		}

		// Fallback: model returned prose. Salvage what we can — not great but
		// better than throwing. Treat the whole thing as answer_md.
		$out['answer_md'] = $msg;
		return $out;
	}

	private function compose_answer_md( array $sections ): array {
		$parts = [];
		if ( ! empty( $sections['consensus'] ) ) {
			$parts[] = "**Consensus**\n- " . implode( "\n- ", $sections['consensus'] );
		}
		if ( ! empty( $sections['tensions'] ) ) {
			$parts[] = "**Tensions**\n- " . implode( "\n- ", $sections['tensions'] );
		}
		if ( ! empty( $sections['recommendation'] ) ) {
			$parts[] = "**Recommendation**\n" . $sections['recommendation'];
		}
		return implode( "\n\n", $parts );
	}

	/* =================================================================
	 *  Citations (R-BRAIN-1)
	 * ================================================================ */

	private function merge_citations( array $answers, string $answer_md ): array {
		// 1) Carry over citations from each perspective so admin replay can
		//    open passages even if synthesizer didn't echo the token.
		//    TBR.W10 (2026-05-21): also preserve `web:` citations emitted by
		//    Stage 2.5 perspectives (W6 Quick / W7 Deep) — they use a
		//    different shape `{token, kind:'web', web_index, web_url, ...}`.
		$out  = [];
		$seen = [];
		foreach ( $answers as $a ) {
			foreach ( (array) ( $a['citations'] ?? [] ) as $c ) {
				$kind = (string) ( $c['kind'] ?? 'nb' );

				// --- Web perspective citations (W6/W7). Keyed by URL since
				//     the same web result may appear across reruns. ----------
				if ( $kind === 'web' ) {
					$url = (string) ( $c['web_url'] ?? '' );
					$key = 'web:' . ( $url !== '' ? $url : ( $c['token'] ?? '' ) );
					if ( isset( $seen[ $key ] ) ) continue;
					$seen[ $key ] = true;
					$out[] = [
						'token'     => (string) ( $c['token'] ?? '' ),
						'kind'      => 'web',
						'web_index' => (int)    ( $c['web_index'] ?? 0 ),
						'web_url'   => $url,
						'web_host'  => (string) ( $c['web_host']  ?? '' ),
						'web_title' => (string) ( $c['web_title'] ?? '' ),
						'source'    => 'perspective',
					];
					continue;
				}

				// --- Notebook passage citations (default). -------------------
				$nb = (int) ( $c['notebook_id'] ?? $a['notebook_id'] ?? 0 );
				$pp = (int) ( $c['passage_id']  ?? 0 );
				$key = 'nb:' . $nb . ':' . $pp;
				if ( isset( $seen[ $key ] ) ) continue;
				$seen[ $key ] = true;
				$out[] = [
					'token'       => $c['token'] ?? sprintf( '[nb:%d/p%d]', $nb, $pp ),
					'kind'        => 'nb',
					'notebook_id' => $nb,
					'passage_id'  => $pp,
					'source'      => 'perspective',
				];
			}
		}

		// 2) Pull any extra tokens the synthesizer surfaced in answer_md.
		if ( preg_match_all( '/\[nb:(\d+)\/p(\d+)\]/', $answer_md, $m ) ) {
			foreach ( $m[0] as $i => $tok ) {
				$nb  = (int) $m[1][ $i ];
				$pp  = (int) $m[2][ $i ];
				$key = 'nb:' . $nb . ':' . $pp;
				if ( isset( $seen[ $key ] ) ) continue;
				$seen[ $key ] = true;
				$out[] = [
					'token'       => $tok,
					'kind'        => 'nb',
					'notebook_id' => $nb,
					'passage_id'  => $pp,
					'source'      => 'synthesizer',
				];
			}
		}

		// 3) Pull web tokens echoed in the synthesizer answer_md (TBR.W10).
		if ( preg_match_all( '/\[web:(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $wm, PREG_SET_ORDER ) ) {
			foreach ( $wm as $match ) {
				$idx = (int) $match[1];
				$url = $match[2] ?? '';
				$key = 'web:' . ( $url !== '' ? $url : ( 'idx:' . $idx ) );
				if ( isset( $seen[ $key ] ) ) continue;
				$seen[ $key ] = true;
				$out[] = [
					'token'     => $match[0],
					'kind'      => 'web',
					'web_index' => $idx,
					'web_url'   => $url,
					'web_host'  => '',
					'web_title' => '',
					'source'    => 'synthesizer',
				];
			}
		}
		return $out;
	}

	/* =================================================================
	 *  Fallbacks
	 * ================================================================ */

	private function text_fallback( string $prompt, array $answers, string $reason ): array {
		$nb_lines = [];
		foreach ( $answers as $a ) {
			$nb_lines[] = sprintf(
				'- **%s** (`%s`, conf %.2f): %s',
				$a['label']      ?? '',
				$a['stance']     ?? 'unknown',
				(float) ( $a['confidence'] ?? 0 ),
				wp_strip_all_tags( (string) ( $a['answer_md'] ?? '' ) )
			);
		}
		$body = "_TwinBrain text-fallback synthesis (`{$reason}`). Configure llm-router for full anti-averaging mode._\n\n"
		      . "**Câu hỏi:** {$prompt}\n\n**Perspective summary:**\n" . implode( "\n", $nb_lines );

		return [
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'answer_md'      => $body,
			'citations'      => $this->merge_citations( $answers, $body ),
			'fallback'       => $reason,
		];
	}

	private function empty_synthesis( string $prompt, string $reason, array $opts = array() ): array {
		// [2026-08-14 Johnny Chu] HOTFIX-GOAL-LOOP-OUTBOUND — an empty MPR
		// result is an internal state, not a customer-facing answer. Continue
		// the active Goal Loop with its single next-best question when available.
		$goal = is_array( $opts['goal_loop_state'] ?? null )
			? $opts['goal_loop_state']
			: ( is_array( $opts['goal_loop'] ?? null ) ? $opts['goal_loop'] : array() );
		$action = is_array( $goal['next_best_action'] ?? null ) ? $goal['next_best_action'] : array();
		$question = trim( (string) ( $action['question_text'] ?? $action['label'] ?? '' ) );
		$primary_goal = trim( (string) ( $goal['primary_goal'] ?? $goal['conversation_goal']['title'] ?? '' ) );
		if ( $question === '' ) {
			$question = $primary_goal !== ''
				? 'Bạn muốn mình cùng bạn chốt bước tiếp theo nào cho mục tiêu này?'
				: 'Bạn muốn mình tiếp tục hỗ trợ mục tiêu cụ thể nào?';
		}
		$answer = $primary_goal !== ''
			? 'Mình đang tiếp tục mục tiêu: ' . $primary_goal . "\n\nĐể đi tiếp, " . $question
			: $question;
		return [
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'answer_md'      => trim( $answer ),
			'citations'      => [],
			'fallback'       => $reason,
			'internal_reason' => $reason,
			'goal_loop_followup' => array(
				'question' => $question,
				'source'   => $primary_goal !== '' ? 'goal_loop' : 'triage',
			),
		];
	}

	private function gateway_ready(): bool {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return false;
		try {
			return BizCity_LLM_Client::instance()->is_ready();
		} catch ( \Throwable $e ) { return false; }
	}
}
