<?php
/**
 * Action: Generate Content (LLM + optional Notebook Skeleton injection).
 *
 * Viết nội dung (fb_post, web_post, script, summary, email, custom) bằng
 * BizCity_LLM_Client. Nếu notebook_id > 0 và skeleton ready → inject skeleton
 * block vào system messages theo R-SK RULE-5.
 *
 * Output vars:
 *   {{n_X.content}}          — nội dung đã generate (markdown / text)
 *   {{n_X.content_type}}     — loại: fb_post | web_post | script | summary | email | custom
 *   {{n_X.notebook_id}}      — notebook đã bind (0 = không bind)
 *   {{n_X.skeleton_version}} — version skeleton đã inject (0 nếu không inject)
 *   {{n_X.tokens}}           — token usage
 *   {{n_X.ok}}               — bool
 *
 * Field type `notebook_picker` trong meta → FE sẽ render dropdown danh sách
 * notebook của user (W9). Giá trị lưu là integer notebook_id.
 *
 * [2026-06-16 Johnny Chu] PHASE-ATH W8 — new block action.generate_content.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-ATH W8 (2026-06-16)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Generate_Content extends BizCity_Automation_Block_Base {

	const CONTENT_TYPES = array( 'fb_post', 'web_post', 'script', 'summary', 'email', 'custom' );

	/** System prompt prefix per content_type (fallback khi không có skeleton). */
	const SYSTEM_PROMPTS = array(
		'fb_post'  => "Bạn là chuyên gia viết content mạng xã hội tiếng Việt. Viết bài Facebook ngắn gọn, cuốn hút, 4-6 dòng, kèm 3-5 hashtag phù hợp. Tránh dùng từ quảng cáo lộ liễu. Kết thúc bằng call-to-action nhẹ nhàng.",
		'web_post' => "Bạn là blogger chuyên nghiệp tiếng Việt. Viết bài blog đầy đủ có tiêu đề, intro, 2-3 đoạn nội dung chính, kết luận. Văn phong thân thiện, có dẫn chứng cụ thể. Dùng Markdown.",
		'script'   => "Bạn là người lên kịch bản nội dung mạng xã hội tiếng Việt. Viết kịch bản ngắn gọn, có hook mở đầu, nội dung chính có dẫn chứng/số liệu, kết luận hành động. Chia đoạn rõ ràng.",
		'summary'  => "Bạn là trợ lý tổng hợp thông tin tiếng Việt. Tổng hợp thông tin ngắn gọn, đúng trọng tâm, gạch đầu dòng khi cần, giữ nguyên số liệu/trích dẫn quan trọng.",
		'email'    => "Bạn là chuyên gia viết email marketing tiếng Việt. Viết email có subject line hấp dẫn, greeting cá nhân, nội dung súc tích, CTA rõ ràng. Định dạng phù hợp gửi email.",
		'custom'   => "Bạn là trợ lý viết content tiếng Việt chuyên nghiệp. Viết theo yêu cầu cụ thể trong prompt.",
	);

	public function id(): string   { return 'action.generate_content'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tạo nội dung (AI)',
			'short'    => 'generate_content',
			'category' => 'ai',
			'color'    => '#7c3aed',
			'icon'     => 'sparkles',
			'defaults' => array(
				'label'            => 'generate_content',
				'content_type'     => 'fb_post',
				'notebook_id'      => 0,
				'prompt_template'  => '{{trigger.text}}',
				'tone'             => '',
				'max_words'        => 300,
				'character_id'     => 0,
			),
			'fields' => array(
				array( 'name' => 'label',           'label' => 'Tên hiển thị',           'type' => 'text' ),
				array( 'name' => 'content_type',    'label' => 'Loại nội dung',          'type' => 'select', 'options' => self::CONTENT_TYPES ),
				array( 'name' => 'notebook_id',     'label' => 'Notebook tham chiếu',    'type' => 'notebook_picker', 'hint' => 'Bind cứng notebook để inject skeleton vào prompt (R-SK)' ),
				array( 'name' => 'prompt_template', 'label' => 'Nội dung yêu cầu',       'type' => 'textarea', 'hint' => 'Hỗ trợ {{n_X.answer_md}}, {{trigger.text}}, {{vars.*}}' ),
				array( 'name' => 'tone',            'label' => 'Giọng văn (tuỳ chọn)',   'type' => 'text', 'hint' => 'vd: thân thiện, chuyên nghiệp, hài hước' ),
				array( 'name' => 'max_words',       'label' => 'Giới hạn từ',            'type' => 'number', 'hint' => 'mặc định 300' ),
				array( 'name' => 'character_id',    'label' => 'Pin Guru (tuỳ chọn)',    'type' => 'number', 'hint' => '0 = không pin' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-06-16 Johnny Chu] PHASE-ATH W8 — execute generate_content.

		// ── 1. Resolve params ────────────────────────────────────────────────
		$content_type = (string) ( $data['content_type'] ?? 'fb_post' );
		if ( ! in_array( $content_type, self::CONTENT_TYPES, true ) ) {
			$content_type = 'fb_post';
		}

		$notebook_id = (int) ( $data['notebook_id'] ?? 0 );
		$max_words   = max( 50, min( 2000, (int) ( $data['max_words'] ?? 300 ) ) );
		$tone        = trim( (string) ( $data['tone'] ?? '' ) );

		$prompt_raw = (string) $this->resolve( $data['prompt_template'] ?? '{{trigger.text}}', $ctx );
		if ( $prompt_raw === '' ) {
			$this->note_event( 'generate_content_skipped', array( 'reason' => 'invalid_param', 'detail' => 'prompt empty' ) );
			return $this->_degraded( $content_type, $notebook_id, 'invalid_param', 'Prompt rỗng.' );
		}

		// ── 2. LLM Client gate (R-GW-8) ─────────────────────────────────────
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->_degraded( $content_type, $notebook_id, 'gateway_missing', 'BizCity_LLM_Client not loaded.' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return $this->_degraded( $content_type, $notebook_id, 'gateway_not_ready', 'BizCity API key chưa cấu hình.' );
		}

		// ── 3. System prompt ─────────────────────────────────────────────────
		$system_base = isset( self::SYSTEM_PROMPTS[ $content_type ] )
			? self::SYSTEM_PROMPTS[ $content_type ]
			: self::SYSTEM_PROMPTS['custom'];

		if ( $tone !== '' ) {
			$system_base .= ' Giọng văn: ' . $tone . '.';
		}
		$system_base .= ' Trả lời ≤' . $max_words . ' từ.';

		// ── 4. Skeleton injection (R-SK RULE-5) ─────────────────────────────
		$skeleton_block   = '';
		$skeleton_version = 0;

		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Skeleton_Adapter' ) ) {
			try {
				if ( BizCity_KG_Skeleton_Adapter::is_ready( $notebook_id ) ) {
					$skeleton_block   = (string) BizCity_KG_Skeleton_Adapter::get_prompt_block( $notebook_id, 'full' );
					$skeleton_version = (int) BizCity_KG_Skeleton_Adapter::get_version( $notebook_id );
				}
			} catch ( \Throwable $e ) {
				// Graceful fallback — adapter not available (R-SK invariant).
				$skeleton_block = '';
			}
		}

		// ── 5. Build messages ────────────────────────────────────────────────
		$messages = array();
		$messages[] = array( 'role' => 'system', 'content' => $system_base );

		if ( $skeleton_block !== '' ) {
			// Inject skeleton AFTER main system prompt, BEFORE user prompt (R-SK RULE-5).
			$messages[] = array( 'role' => 'system', 'content' => $skeleton_block );
		}

		$messages[] = array( 'role' => 'user', 'content' => $prompt_raw );

		// ── 6. Resolve model ─────────────────────────────────────────────────
		$model = $llm->get_model( 'chat' );
		/**
		 * Filter: bizcity_automation_generate_content_model
		 * @param string $model
		 * @param string $content_type
		 */
		$model = (string) apply_filters( 'bizcity_automation_generate_content_model', $model, $content_type );

		// ── 7. LLM call ──────────────────────────────────────────────────────
		$started = microtime( true );
		$result  = null;
		try {
			$result = $llm->chat( $messages, array(
				'model'       => $model,
				'temperature' => 0.7,
				'max_tokens'  => (int) ( $max_words * 2.5 ), // rough token estimate
				'purpose'     => 'automation_generate_content',
			) );
		} catch ( \Throwable $e ) {
			return $this->_degraded( $content_type, $notebook_id, 'llm_exception', $e->getMessage() );
		}
		$ms = (int) ( ( microtime( true ) - $started ) * 1000 );

		// ── 8. Handle response ───────────────────────────────────────────────
		// [2026-06-16 Johnny Chu] PHASE-ATH W8 GAP-2 fix: BizCity_LLM_Client::chat() always
		// returns array { success, message, model, usage, error } — never WP_Error.
		// Previous is_wp_error() check was dead code. Correct check: empty($result['success']).
		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			$error_code = is_array( $result ) ? (string) ( $result['error'] ?? 'llm_error' ) : 'invalid_response';
			$error_msg  = is_array( $result ) ? (string) ( $result['error'] ?? '' ) : 'chat() returned non-array';
			$this->note_event( 'generate_content_failed', array(
				'reason'       => $error_code,
				'content_type' => $content_type,
				'notebook_id'  => $notebook_id,
				'ms'           => $ms,
			) );
			return $this->_degraded( $content_type, $notebook_id, $error_code, $error_msg );
		}

		// chat() response shape: { success, message, model, model_primary, fallback_used, provider, usage, error }
		$content = (string) ( $result['message'] ?? '' );
		$tokens  = (int) ( $result['usage']['total_tokens'] ?? 0 );

		if ( $content === '' ) {
			return $this->_degraded( $content_type, $notebook_id, 'empty_response', 'LLM returned empty content.' );
		}

		$this->note_event( 'generate_content_ok', array(
			'content_type'    => $content_type,
			'notebook_id'     => $notebook_id,
			'skeleton_version'=> $skeleton_version,
			'tokens'          => $tokens,
			'ms'              => $ms,
		) );

		return array(
			'ok'              => true,
			'content'         => trim( $content ),
			'content_type'    => $content_type,
			'notebook_id'     => $notebook_id,
			'skeleton_version'=> $skeleton_version,
			'tokens'          => $tokens,
			'ms'              => $ms,
		);
	}

	/**
	 * Fail-OPEN degraded return (R-GW-8).
	 */
	private function _degraded( string $content_type, int $notebook_id, string $reason, string $detail = '' ) {
		$this->note_event( 'generate_content_skipped', array(
			'reason'       => $reason,
			'detail'       => $detail,
			'content_type' => $content_type,
			'notebook_id'  => $notebook_id,
		) );
		return array(
			'ok'              => false,
			'_degraded'       => true,
			'content'         => '',
			'content_type'    => $content_type,
			'notebook_id'     => $notebook_id,
			'skeleton_version'=> 0,
			'tokens'          => 0,
			'ms'              => 0,
			'reason'          => $reason,
		);
	}
}
