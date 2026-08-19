<?php
/**
 * TwinWeb Agent Tool Adapters.
 *
 * Thin executors that bridge Twin GPT tool decisions into canonical owner
 * services. The catalog remains metadata-only; execution stays in
 * BizCity_Twin_Tool_Registry.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	$_bizcity_twinweb_tool_interface = defined( 'BIZCITY_TWIN_AI_DIR' )
		? BIZCITY_TWIN_AI_DIR . 'core/twin-core/includes/interface-twin-tool.php'
		: dirname( __DIR__, 3 ) . '/core/twin-core/includes/interface-twin-tool.php';
	if ( is_readable( $_bizcity_twinweb_tool_interface ) ) {
		require_once $_bizcity_twinweb_tool_interface;
	}
	unset( $_bizcity_twinweb_tool_interface );
}

if ( interface_exists( 'BizCity_Twin_Tool' ) && ! class_exists( 'BizCity_TwinWeb_Doc_Artifact_Tool' ) ) {
	class BizCity_TwinWeb_Doc_Artifact_Tool implements BizCity_Twin_Tool {

		/** @var string */
		private $slug;

		/** @var string */
		private $doc_type;

		/** @var string */
		private $artifact_type;

		/** @var string */
		private $label;

		public function __construct( $slug, $doc_type, $artifact_type, $label ) {
			$this->slug          = sanitize_key( (string) $slug );
			$this->doc_type      = sanitize_key( (string) $doc_type );
			$this->artifact_type = sanitize_key( (string) $artifact_type );
			$this->label         = sanitize_text_field( (string) $label );
		}

		public function name(): string {
			return $this->slug;
		}

		public function description(): string {
			return $this->label . ' trong BizCity Doc Studio rồi trả artifact_created để Twin GPT mở Canvas.';
		}

		public function tool_class(): string {
			return 'producer';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'prompt'         => array( 'type' => 'string', 'description' => 'Yêu cầu gốc của user.' ),
					'title'          => array( 'type' => 'string', 'description' => 'Tiêu đề artifact.' ),
					'attachment_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					'thread_id'      => array( 'type' => 'string' ),
					'project_id'     => array( 'type' => 'string' ),
					// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — structured handoff spec from runtime to owner artifact service.
					'tool_spec'      => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
				),
				'required'   => array( 'prompt' ),
			);
		}

		public function execute( array $args, array $context ): array {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — hand off document artifacts to bizcity-doc owner service.
			$prompt = trim( (string) ( $args['prompt'] ?? '' ) );
			$title  = trim( (string) ( $args['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = $this->derive_title( $prompt );
			}
			if ( '' === $prompt ) {
				$prompt = $title;
			}
			if ( '' === $prompt ) {
				return array(
					'ok'      => false,
					'error'   => 'invalid_param',
					'summary' => 'Thiếu nội dung để tạo artifact.',
					'result'  => null,
				);
			}

			if ( ! class_exists( 'BZDoc_Notebook_Bridge' ) || ! method_exists( 'BZDoc_Notebook_Bridge', 'generate_from_skeleton_public' ) ) {
				return array(
					'ok'      => false,
					'error'   => 'tool_unavailable',
					'summary' => 'BizCity Doc Studio chưa sẵn sàng để tạo artifact.',
					'result'  => null,
				);
			}

			$attachment_ids = array();
			foreach ( (array) ( $args['attachment_ids'] ?? array() ) as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id > 0 ) {
					$attachment_ids[] = $attachment_id;
				}
			}

			$notebook_id = (int) ( $context['notebook_id'] ?? 0 );
			$project_id  = (string) ( $args['project_id'] ?? $context['project_id'] ?? '' );
			$thread_id   = (string) ( $args['thread_id'] ?? $context['session_id'] ?? '' );
			$tool_spec   = isset( $args['tool_spec'] ) && is_array( $args['tool_spec'] ) ? $args['tool_spec'] : array();
			$spec_source = $this->build_spec_source_text( $prompt, $tool_spec );
			$outline     = $this->build_spec_outline_nodes( $prompt, $tool_spec );
			$key_points  = $this->build_spec_key_points( $prompt, $tool_spec );

			$skeleton = array(
				'nucleus'       => array(
					'title'  => $title,
					'thesis' => $this->derive_spec_thesis( $prompt, $tool_spec ),
					'domain' => 'twin_gpt',
				),
				'skeleton'      => $outline,
				'key_points'    => $key_points,
				'decisions'     => array(
					array( 'text' => 'Ưu tiên yêu cầu trực tiếp của user làm nguồn điều phối artifact.' ),
					array( 'text' => 'Dùng lịch sử hội thoại và file đính kèm làm ngữ cảnh bổ sung, không thay thế yêu cầu gốc.' ),
				),
				'project_id'    => $project_id !== '' ? $project_id : ( $thread_id !== '' ? ( 'tw_' . sanitize_key( $thread_id ) ) : '' ),
				'_raw_text'     => $spec_source !== '' ? $spec_source : $prompt,
				'_kickstart'    => true,
				'_source'       => 'twin_gpt_agent_tool',
				'_tool_slug'    => $this->slug,
				'_thread_id'    => $thread_id,
				'tool_spec'     => $tool_spec,
				'attachment_ids'=> $attachment_ids,
				'doc_opts'      => array(
					'template'    => 'blank',
					'theme'       => 'modern',
					// [2026-07-20 Johnny Chu] PHASE-1-BZDOC-DEEPSEEK — Twin GPT slide artifacts must open as detailed 20-slide BZDoc decks.
					'slide_count' => $this->doc_type === 'presentation' ? 20 : 0,
					'parallel_batches' => $this->doc_type === 'document' ? 3 : 0,
				),
			);

			$result = BZDoc_Notebook_Bridge::generate_from_skeleton_public( $skeleton, $this->doc_type );
			if ( is_wp_error( $result ) ) {
				return array(
					'ok'      => false,
					'error'   => $result->get_error_code(),
					'summary' => $result->get_error_message(),
					'result'  => null,
				);
			}
			if ( ! is_array( $result ) || empty( $result['data']['doc_id'] ) ) {
				return array(
					'ok'      => false,
					'error'   => 'artifact_write_failed',
					'summary' => 'Doc Studio không trả về artifact hợp lệ.',
					'result'  => $result,
				);
			}

			$doc_id   = (int) $result['data']['doc_id'];
			$edit_url = (string) ( $result['data']['url'] ?? home_url( '/tool-doc/?id=' . $doc_id . '&autogen=1&kickstart=1' ) );
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — open BZDoc in compact Artifact Canvas shell when launched from Twin GPT.
			$edit_url = add_query_arg( 'twin_canvas', '1', $edit_url );
			$title    = (string) ( $result['title'] ?? $title );

			if ( $notebook_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
				BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc', $doc_id, $notebook_id, $title, $edit_url );
			}

			$artifact_created = class_exists( 'BizCity_Artifact_Source_Federation' )
				? BizCity_Artifact_Source_Federation::make_artifact_created( 'bizcity-doc', $doc_id, $title, $edit_url, $notebook_id )
				: array(
					'plugin_name' => 'bizcity-doc',
					'studio_id'   => $doc_id,
					'title'       => $title,
					'edit_url'    => $edit_url,
				);
			$artifact_created['artifact_type'] = $this->artifact_type;
			$artifact_created['status']        = 'pending';
			$artifact_created['render_mode']   = 'trusted_iframe';
			$artifact_created['url']           = $edit_url;
			$artifact_created['status_url']    = add_query_arg( array(
				'plugin'        => 'bizcity-doc',
				'id'            => $doc_id,
				'artifact_type' => $this->artifact_type,
			), rest_url( 'bizcity-twinweb/v1/artifacts/status' ) );
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose transparent spec handoff for the Twin GPT Canvas `{}` inspector.
			$artifact_created['spec_trace']       = $this->build_spec_trace_payload( $tool_spec, $skeleton, $spec_source );
			$artifact_created['tool_spec_schema'] = (string) ( $tool_spec['schema'] ?? '' );

			return array(
				'ok'               => true,
				'status'           => 'pending',
				'summary'          => 'Đã tạo project ' . $this->label . ' trong Doc Studio; nội dung/export tiếp tục chạy trong Canvas.',
				'result'           => array(
					'doc_id'        => $doc_id,
					'doc_type'      => $this->doc_type,
					'artifact_type' => $this->artifact_type,
					'edit_url'      => $edit_url,
					'title'         => $title,
				),
				'artifact_created' => $artifact_created,
				'canvas_open'      => array(
					'type'          => $this->artifact_type,
					'url'           => $edit_url,
					'output_id'     => $doc_id,
					'plugin_name'   => 'bizcity-doc',
					'artifact_type' => $this->artifact_type,
					'status'        => 'pending',
					'render_mode'   => 'trusted_iframe',
					'status_url'    => $artifact_created['status_url'],
					'spec_trace'    => $artifact_created['spec_trace'],
				),
			);
		}

		private function derive_title( $prompt ) {
			$title = trim( wp_strip_all_tags( (string) $prompt ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 120 ) {
				$title = mb_substr( $title, 0, 120, 'UTF-8' );
			} elseif ( strlen( $title ) > 120 ) {
				$title = substr( $title, 0, 120 );
			}
			return $title !== '' ? $title : $this->label;
		}

		private function derive_spec_thesis( $prompt, array $tool_spec ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — carry user intent into BZDoc nucleus instead of leaving thesis empty.
			$thread_summary = isset( $tool_spec['thread']['summary'] ) ? trim( (string) $tool_spec['thread']['summary'] ) : '';
			if ( '' !== $thread_summary ) {
				return 'Tạo artifact theo yêu cầu user, có đối chiếu ngữ cảnh hội thoại gần đây.';
			}
			return 'Tạo artifact theo yêu cầu trực tiếp của user.';
		}

		private function build_spec_outline_nodes( $prompt, array $tool_spec ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — deterministic outline gives BZDoc a real skeleton even before LLM expansion.
			$nodes = array(
				array(
					'label'   => 'Yêu cầu tạo tài liệu',
					'summary' => $this->limit_text( $prompt, 700 ),
				),
				array(
					'label'   => 'Spec nội dung ưu tiên',
					'summary' => $this->limit_text( (string) ( $tool_spec['request']['user_prompt'] ?? $prompt ), 900 ),
				),
			);

			$thread_summary = isset( $tool_spec['thread']['summary'] ) ? trim( (string) $tool_spec['thread']['summary'] ) : '';
			if ( '' !== $thread_summary ) {
				$nodes[] = array(
					'label'   => 'Ngữ cảnh hội thoại trong thread',
					'summary' => $this->limit_text( $thread_summary, 1200 ),
				);
			}

			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — BZDoc outline must see vision/file/audio intake facts, not only filenames.
			$file_summary = $this->build_spec_file_context_text( $tool_spec, 1800 );
			if ( '' !== $file_summary ) {
				$nodes[] = array(
					'label'   => 'Tệp và media tham chiếu',
					'summary' => $file_summary,
				);
			}

			$nodes[] = array(
				'label'   => $this->doc_type === 'presentation' ? 'Cấu trúc slide đề xuất' : 'Cấu trúc nội dung đề xuất',
				'summary' => 'Triển khai thành các phần rõ ràng, có tiêu đề, luận điểm, ví dụ và bước hành động phù hợp với yêu cầu gốc.',
			);

			return $nodes;
		}

		private function build_spec_key_points( $prompt, array $tool_spec ) {
			$points = array(
				array( 'text' => 'Yêu cầu gốc: ' . $this->limit_text( $prompt, 800 ) ),
			);
			$enriched = isset( $tool_spec['request']['enriched_prompt'] ) ? trim( (string) $tool_spec['request']['enriched_prompt'] ) : '';
			if ( '' !== $enriched && $enriched !== $prompt ) {
				$points[] = array( 'text' => 'Ngữ cảnh suy luận đã chuẩn bị: ' . $this->limit_text( $enriched, 1200 ) );
			}
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — BZDoc should consume the completed AskBrain answer after stream final_done.
			$final_answer = isset( $tool_spec['request']['final_answer'] ) ? trim( (string) $tool_spec['request']['final_answer'] ) : '';
			if ( '' !== $final_answer ) {
				$points[] = array( 'text' => 'Câu trả lời AskBrain hoàn chỉnh: ' . $this->limit_text( $final_answer, 1800 ) );
			}
			$thread = isset( $tool_spec['thread']['summary'] ) ? trim( (string) $tool_spec['thread']['summary'] ) : '';
			if ( '' !== $thread ) {
				$points[] = array( 'text' => 'Tóm tắt thread: ' . $this->limit_text( $thread, 900 ) );
			}
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — include extracted text / transcript / vision summary in BZDoc key points.
			$files = $this->build_spec_file_context_text( $tool_spec, 1600 );
			if ( '' !== $files ) {
				$points[] = array( 'text' => 'Tệp tham chiếu: ' . $files );
			}
			return $points;
		}

		private function build_spec_source_text( $prompt, array $tool_spec ) {
			$lines = array(
				'=== Yêu cầu tạo tài liệu ===',
				$prompt,
			);
			$user_prompt = isset( $tool_spec['request']['user_prompt'] ) ? trim( (string) $tool_spec['request']['user_prompt'] ) : '';
			if ( '' !== $user_prompt && $user_prompt !== $prompt ) {
				$lines[] = "\n=== Tin nhắn user ưu tiên ===";
				$lines[] = $user_prompt;
			}
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — final streamed answer is the second-priority artifact source after the user prompt.
			$final_answer = isset( $tool_spec['request']['final_answer'] ) ? trim( (string) $tool_spec['request']['final_answer'] ) : '';
			if ( '' !== $final_answer ) {
				$lines[] = "\n=== Câu trả lời cuối cùng của AskBrain (ưu tiên #2) ===";
				$lines[] = $this->limit_text( $final_answer, 12000 );
			}
			$thread = isset( $tool_spec['thread']['summary'] ) ? trim( (string) $tool_spec['thread']['summary'] ) : '';
			if ( '' !== $thread ) {
				$lines[] = "\n=== Ngữ cảnh hội thoại trong thread ===";
				$lines[] = $thread;
			}
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — source text carries full multimodal context for autogen outline_block.
			$files = $this->build_spec_file_context_text( $tool_spec, 6000 );
			if ( '' !== $files ) {
				$lines[] = "\n=== Tệp/ảnh/media tham chiếu ===";
				$lines[] = $files;
			}
			$enriched = isset( $tool_spec['request']['enriched_prompt'] ) ? trim( (string) $tool_spec['request']['enriched_prompt'] ) : '';
			if ( '' !== $enriched && $enriched !== $prompt ) {
				$lines[] = "\n=== Context suy luận đã chuẩn bị bởi TwinBrain ===";
				$lines[] = $this->limit_text( $enriched, 8000 );
			}
			return $this->limit_text( implode( "\n", $lines ), 16000 );
		}

		private function build_spec_trace_payload( array $tool_spec, array $skeleton, $spec_source ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — compact audit payload: user command first, then thread and multimodal file context.
			$files = isset( $tool_spec['files'] ) && is_array( $tool_spec['files'] ) ? $tool_spec['files'] : array();
			$thread = isset( $tool_spec['thread'] ) && is_array( $tool_spec['thread'] ) ? $tool_spec['thread'] : array();
			$request = isset( $tool_spec['request'] ) && is_array( $tool_spec['request'] ) ? $tool_spec['request'] : array();

			$recent = array();
			foreach ( array_slice( (array) ( $thread['recent'] ?? array() ), -8 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$recent[] = array(
					'role'    => sanitize_key( (string) ( $item['role'] ?? 'message' ) ),
					'content' => $this->limit_text( (string) ( $item['content'] ?? '' ), 900 ),
				);
			}

			$file_items = array();
			foreach ( array_slice( (array) ( $files['items'] ?? array() ), 0, 8 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$intake = isset( $item['intake'] ) && is_array( $item['intake'] ) ? $item['intake'] : array();
				$file_items[] = array(
					'id'            => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
					'filename'      => sanitize_file_name( (string) ( $item['filename'] ?? '' ) ),
					'kind'          => sanitize_key( (string) ( $item['kind'] ?? 'file' ) ),
					'mime'          => sanitize_text_field( (string) ( $item['mime'] ?? '' ) ),
					'summary'       => $this->limit_text( (string) ( $item['summary'] ?? '' ), 900 ),
					'intake_status' => sanitize_key( (string) ( $intake['status'] ?? '' ) ),
					'reason_bucket' => sanitize_key( (string) ( $intake['reason_bucket'] ?? '' ) ),
					'text_excerpt'  => $this->limit_text( (string) ( $intake['text_excerpt'] ?? '' ), 1200 ),
					'transcript'    => $this->limit_text( (string) ( $intake['transcript'] ?? '' ), 1200 ),
					'vision'        => $this->limit_text( (string) ( $intake['summary'] ?? '' ), 1200 ),
					'ocr_text'      => array_slice( (array) ( $intake['ocr_text'] ?? array() ), 0, 8 ),
					'entities'      => array_slice( (array) ( $intake['entities'] ?? array() ), 0, 12 ),
				);
			}

			$outline = array();
			foreach ( array_slice( (array) ( $skeleton['skeleton'] ?? array() ), 0, 12 ) as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$outline[] = array(
					'label'   => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
					'summary' => $this->limit_text( (string) ( $node['summary'] ?? '' ), 900 ),
				);
			}

			$key_points = array();
			foreach ( array_slice( (array) ( $skeleton['key_points'] ?? array() ), 0, 12 ) as $point ) {
				if ( is_array( $point ) ) {
					$key_points[] = $this->limit_text( (string) ( $point['text'] ?? '' ), 900 );
				} else {
					$key_points[] = $this->limit_text( (string) $point, 900 );
				}
			}

			return array(
				'schema'       => 'bizcity.twinweb.spec_trace.v1',
				'tool_schema'  => (string) ( $tool_spec['schema'] ?? '' ),
				'priority'     => (array) ( $tool_spec['priority'] ?? array( 'user_prompt', 'thread.summary', 'files.summary', 'request.enriched_prompt' ) ),
				// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — trace must include the same acceptance gates BZDoc receives.
				'quality_gates'=> isset( $tool_spec['quality_gates'] ) && is_array( $tool_spec['quality_gates'] ) ? $tool_spec['quality_gates'] : array(),
				'doc_contract' => array(
					'owner'         => 'bizcity-doc',
					'doc_type'      => $this->doc_type,
					'artifact_type' => $this->artifact_type,
					'export_target' => $this->artifact_type === 'pptx' ? 'pptx/pdf' : ( $this->artifact_type === 'xlsx' ? 'xlsx/csv' : 'docx/pdf' ),
				),
				'request'      => array(
					'user_prompt'     => $this->limit_text( (string) ( $request['user_prompt'] ?? '' ), 3000 ),
					// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — expose final answer in Canvas `{}` and BZDoc handoff trace.
					'final_answer'    => $this->limit_text( (string) ( $request['final_answer'] ?? '' ), 5000 ),
					'enriched_prompt' => $this->limit_text( (string) ( $request['enriched_prompt'] ?? '' ), 5000 ),
					'title'           => sanitize_text_field( (string) ( $request['title'] ?? '' ) ),
				),
				'thread'       => array(
					'session_id' => sanitize_text_field( (string) ( $thread['session_id'] ?? '' ) ),
					'summary'    => $this->limit_text( (string) ( $thread['summary'] ?? '' ), 4000 ),
					'recent'     => $recent,
				),
				'files'        => array(
					'summary'    => $this->limit_text( (string) ( $files['summary'] ?? '' ), 2500 ),
					'ingest'     => isset( $files['ingest'] ) && is_array( $files['ingest'] ) ? $files['ingest'] : array(),
					'items'      => $file_items,
					'context_md' => $this->limit_text( (string) ( $files['context_md'] ?? '' ), 4000 ),
				),
				'skeleton'     => array(
					'title'      => (string) ( $skeleton['nucleus']['title'] ?? '' ),
					'thesis'     => (string) ( $skeleton['nucleus']['thesis'] ?? '' ),
					'outline'    => $outline,
					'key_points' => $key_points,
				),
				'source_excerpt' => $this->limit_text( (string) $spec_source, 5000 ),
			);
		}

		private function build_spec_file_context_text( array $tool_spec, $max ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — flatten tool_spec.files into prose BZDoc already understands.
			$files = isset( $tool_spec['files'] ) && is_array( $tool_spec['files'] ) ? $tool_spec['files'] : array();
			if ( empty( $files ) ) {
				return '';
			}
			$lines = array();
			$summary = isset( $files['summary'] ) ? trim( (string) $files['summary'] ) : '';
			if ( '' !== $summary ) {
				$lines[] = 'Tóm tắt attachment:';
				$lines[] = $summary;
			}

			$ingest = isset( $files['ingest'] ) && is_array( $files['ingest'] ) ? $files['ingest'] : array();
			if ( ! empty( $ingest ) ) {
				$status = ! empty( $ingest['degraded'] ) ? 'degraded' : 'ok';
				$lines[] = 'Multimodal intake: ' . $status . ( ! empty( $ingest['intent'] ) ? '; intent=' . (string) $ingest['intent'] : '' ) . ( ! empty( $ingest['confidence'] ) ? '; confidence=' . (string) $ingest['confidence'] : '' );
				if ( ! empty( $ingest['reason_bucket'] ) ) {
					$lines[] = 'Reason bucket: ' . (string) $ingest['reason_bucket'];
				}
				if ( ! empty( $ingest['vision_summary'] ) ) {
					$lines[] = 'Vision summary: ' . (string) $ingest['vision_summary'];
				}
				if ( ! empty( $ingest['ocr_text'] ) ) {
					$lines[] = 'OCR: ' . implode( '; ', array_map( 'strval', (array) $ingest['ocr_text'] ) );
				}
				if ( ! empty( $ingest['entities'] ) ) {
					$lines[] = 'Entities: ' . implode( ', ', array_map( 'strval', (array) $ingest['entities'] ) );
				}
			}

			foreach ( (array) ( $files['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$name = (string) ( $item['filename'] ?? '' );
				$kind = (string) ( $item['kind'] ?? 'file' );
				$lines[] = '- ' . $name . ' (' . $kind . ')';
				$intake = isset( $item['intake'] ) && is_array( $item['intake'] ) ? $item['intake'] : array();
				foreach ( array( 'summary', 'text_excerpt', 'transcript' ) as $field ) {
					$value = isset( $intake[ $field ] ) ? trim( (string) $intake[ $field ] ) : '';
					if ( '' !== $value ) {
						$lines[] = '  ' . $field . ': ' . $this->limit_text( $value, 1400 );
					}
				}
			}

			$context_md = isset( $files['context_md'] ) ? trim( (string) $files['context_md'] ) : '';
			if ( '' !== $context_md ) {
				$lines[] = 'Multimodal context markdown:';
				$lines[] = $this->limit_text( $context_md, 3500 );
			}

			return $this->limit_text( implode( "\n", array_filter( $lines ) ), $max );
		}

		private function limit_text( $text, $max ) {
			$text = trim( (string) $text );
			$max = max( 1, absint( $max ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
				return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . '...';
			}
			if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $max ) {
				return substr( $text, 0, $max - 1 ) . '...';
			}
			return $text;
		}
	}
}

if ( interface_exists( 'BizCity_Twin_Tool' ) && ! class_exists( 'BizCity_TwinWeb_Image_Artifact_Tool' ) ) {
	class BizCity_TwinWeb_Image_Artifact_Tool implements BizCity_Twin_Tool {

		public function name(): string {
			return 'generate_image';
		}

		public function description(): string {
			return 'Tạo image project trong Doc Studio, mở Canvas pending, rồi để Canvas khởi động gateway ảnh qua same-origin proxy.';
		}

		public function tool_class(): string {
			return 'producer';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'prompt'       => array( 'type' => 'string', 'description' => 'Mô tả ảnh cần tạo.' ),
					'title'        => array( 'type' => 'string' ),
					'aspect_ratio' => array( 'type' => 'string', 'enum' => array( '1:1', '4:3', '3:4', '16:9', '9:16' ) ),
					'input_images' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
					// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — preserve image prompt/thread/file handoff for BZDoc trace download.
					'tool_spec'    => array( 'type' => 'object', 'description' => 'Structured request/thread/file context packet.' ),
				),
				'required'   => array( 'prompt' ),
			);
		}

		public function execute( array $args, array $context ): array {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — image-only adapter opens Canvas first; generation starts from Canvas proxy, not the chat SSE request.
			$prompt = trim( (string) ( $args['prompt'] ?? '' ) );
			$title  = trim( (string) ( $args['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = $this->derive_title( $prompt );
			}
			if ( '' === $prompt ) {
				return array(
					'ok'      => false,
					'error'   => 'invalid_param',
					'summary' => 'Thiếu prompt để tạo ảnh.',
					'result'  => null,
				);
			}

			$tool_spec = isset( $args['tool_spec'] ) && is_array( $args['tool_spec'] ) ? $args['tool_spec'] : array();
			$doc_handoff = $this->create_image_doc_handoff( $prompt, $title, (string) ( $args['aspect_ratio'] ?? '1:1' ), (array) ( $args['input_images'] ?? array() ), $tool_spec );
			if ( is_array( $doc_handoff ) ) {
				return $doc_handoff;
			}

			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — fallback keeps MVP usable when Doc Studio image owner is not loaded.
			if ( ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
				return array(
					'ok'      => false,
					'error'   => 'gateway_degraded',
					'summary' => 'BizCity LLM client chưa sẵn sàng để tạo ảnh.',
					'result'  => null,
				);
			}

			$input_images = $this->resolve_input_image_urls( (array) ( $args['input_images'] ?? array() ), $context );
			if ( is_wp_error( $input_images ) ) {
				return array(
					'ok'      => false,
					'error'   => $input_images->get_error_code(),
					'summary' => $input_images->get_error_message(),
					'result'  => null,
				);
			}

			$client = BizCity_LLM_Client::instance();
			if ( method_exists( $client, 'is_ready' ) && ! $client->is_ready() ) {
				return array(
					'ok'      => false,
					'error'   => 'gateway_degraded',
					'summary' => 'BizCity API key chưa cấu hình nên chưa thể tạo ảnh.',
					'result'  => null,
				);
			}

			$options = array(
				'purpose'      => 'image',
				'timeout'      => 120,
				'size'         => $this->size_for_ratio( (string) ( $args['aspect_ratio'] ?? '1:1' ) ),
				'input_images' => $input_images,
			);

			$result = $client->generate_image( $prompt, $options );
			if ( empty( $result['success'] ) ) {
				return array(
					'ok'      => false,
					'error'   => 'generation_failed',
					'summary' => (string) ( $result['error'] ?? 'Tạo ảnh thất bại.' ),
					'result'  => $result,
				);
			}

			$media = $this->persist_image_result(
				(string) ( $result['image_url'] ?? '' ),
				(string) ( $result['b64_json'] ?? '' ),
				$title,
				(int) ( $context['user_id'] ?? get_current_user_id() )
			);
			if ( is_wp_error( $media ) ) {
				$media = array(
					'attachment_id' => 0,
					'url'           => (string) ( $result['image_url'] ?? '' ),
				);
			}

			$attachment_id = (int) ( $media['attachment_id'] ?? 0 );
			$image_url     = (string) ( $media['url'] ?? ( $result['image_url'] ?? '' ) );
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — direct fallback still exposes Canva post-production with generated image as layer 1.
			$editor_url    = $image_url !== '' ? $this->build_canva_editor_url( $image_url, 0 ) : '';
			$artifact      = array(
				'plugin_name'   => 'twin-gpt-image',
				'studio_id'     => $attachment_id,
				'artifact_id'   => $attachment_id,
				'artifact_type' => 'image',
				'status'        => 'ready',
				'render_mode'   => 'card',
				'title'         => $title,
				'edit_url'      => $editor_url,
				'canva_edit_url'=> $editor_url,
				'preview_url'   => $image_url,
				'url'           => $image_url,
				'download_url'  => $image_url,
				'mime_type'     => 'image/png',
			);

			return array(
				'ok'               => true,
				'status'           => 'ready',
				'summary'          => 'Đã tạo ảnh và mở artifact trong Canvas.',
				'result'           => array(
					'attachment_id' => $attachment_id,
					'image_url'     => $image_url,
					'title'         => $title,
				),
				'artifact_created' => $artifact,
				'artifact_ready'   => $artifact,
				'canvas_open'      => array(
					'type'          => 'image',
					'url'           => $image_url,
					'output_id'     => $attachment_id,
					'plugin_name'   => 'twin-gpt-image',
					'artifact_type' => 'image',
					'status'        => 'ready',
					'render_mode'   => 'card',
				),
			);
		}

		private function build_canva_editor_url( $image_url, $doc_id ) {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — generated image becomes layer 1 in Canva editor.
			$image_url = esc_url_raw( (string) $image_url );
			if ( '' === $image_url ) {
				return '';
			}
			$args = array(
				'imageUrl' => $image_url,
				'source'   => 'twinweb',
			);
			$doc_id = absint( $doc_id );
			if ( $doc_id > 0 ) {
				$args['doc_id'] = $doc_id;
			}
			return add_query_arg( $args, home_url( '/canva/' ) );
		}

		private function resolve_input_image_urls( array $attachment_ids, array $context ) {
			$urls    = array();
			$user_id = (int) ( $context['user_id'] ?? get_current_user_id() );
			foreach ( $attachment_ids as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id <= 0 ) {
					continue;
				}
				$post = get_post( $attachment_id );
				if ( ! $post || $post->post_type !== 'attachment' ) {
					return new WP_Error( 'attachment_not_found', 'Không tìm thấy ảnh tham chiếu.' );
				}
				if ( $user_id > 0 && (int) $post->post_author !== $user_id ) {
					return new WP_Error( 'artifact_ownership_refused', 'Ảnh tham chiếu không thuộc user hiện tại.' );
				}
				$mime = strtolower( (string) get_post_mime_type( $attachment_id ) );
				if ( 0 !== strpos( $mime, 'image/' ) ) {
					continue;
				}
				$url = wp_get_attachment_url( $attachment_id );
				if ( is_string( $url ) && $url !== '' ) {
					$urls[] = $url;
				}
			}
			return $urls;
		}

		private function create_image_doc_handoff( $prompt, $title, $aspect_ratio, array $input_images, array $tool_spec ) {
			if ( ! class_exists( 'BZDoc_Rest_API' ) ) {
				return null;
			}

			$create = new WP_REST_Request( 'POST', '/' . $this->bzdoc_rest_namespace() . '/project/create' );
			$create->set_param( 'doc_type', 'image' );
			$create->set_param( 'title', $title );
			$res = rest_do_request( $create );
			if ( is_wp_error( $res ) ) {
				return null;
			}
			$data = (array) rest_ensure_response( $res )->get_data();
			$doc_id = (int) ( $data['doc_id'] ?? 0 );
			if ( $doc_id <= 0 ) {
				return null;
			}

			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — persist image handoff trace into BZDoc schema_json immediately after project create.
			$spec_trace = $this->build_image_spec_trace_payload( $prompt, $title, $aspect_ratio, $input_images, $tool_spec );
			$this->persist_image_handoff_schema( $doc_id, $prompt, $title, $aspect_ratio, $input_images, $tool_spec, $spec_trace );

			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — image handoff uses the same compact Canvas shell as document artifacts.
			$edit_url = home_url( '/tool-doc/?id=' . $doc_id . '&type=image&autogen=1&kickstart=1&twin_canvas=1' );
			$status_url = add_query_arg( array(
				'plugin'        => 'bizcity-doc',
				'id'            => $doc_id,
				'artifact_type' => 'image',
			), rest_url( 'bizcity-twinweb/v1/artifacts/status' ) );
			$start_payload = array(
				'doc_id'       => $doc_id,
				'prompt'       => $prompt,
				'title'        => $title,
				'aspect_ratio' => $aspect_ratio !== '' ? $aspect_ratio : '1:1',
				'input_images' => array_values( array_map( 'absint', $input_images ) ),
				'tool_spec'    => $tool_spec,
				'spec_trace'   => $spec_trace,
			);
			$artifact = array(
				'plugin_name'   => 'bizcity-doc',
				'studio_id'     => $doc_id,
				'artifact_id'   => $doc_id,
				'artifact_type' => 'image',
				'status'        => 'pending',
				'render_mode'   => 'card',
				'title'         => $title,
				'edit_url'      => $edit_url,
				'url'           => $edit_url,
				'status_url'    => $status_url,
				'start_url'     => rest_url( 'bizcity-twinweb/v1/artifacts/image/start' ),
				'start_payload' => $start_payload,
				'spec_trace'    => $spec_trace,
			);

			return array(
				'ok'               => true,
				'status'           => 'pending',
				'summary'          => 'Đã mở Canvas tạo ảnh; tiến trình chạy qua Doc Studio owner service.',
				'result'           => array(
					'doc_id'        => $doc_id,
					'artifact_type' => 'image',
					'edit_url'      => $edit_url,
					'title'         => $title,
				),
				'artifact_created' => $artifact,
				'canvas_open'      => array(
					'type'          => 'image',
					'url'           => $edit_url,
					'output_id'     => $doc_id,
					'plugin_name'   => 'bizcity-doc',
					'artifact_type' => 'image',
					'status'        => 'pending',
					'render_mode'   => 'card',
					'status_url'    => $status_url,
					'start_url'     => $artifact['start_url'],
					'start_payload' => $start_payload,
					'spec_trace'    => $spec_trace,
				),
			);
		}

		private function build_image_spec_trace_payload( $prompt, $title, $aspect_ratio, array $input_images, array $tool_spec ) {
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — compact image trace mirrors doc spec trace shape for equality checks.
			$request = isset( $tool_spec['request'] ) && is_array( $tool_spec['request'] ) ? $tool_spec['request'] : array();
			$thread  = isset( $tool_spec['thread'] ) && is_array( $tool_spec['thread'] ) ? $tool_spec['thread'] : array();
			$files   = isset( $tool_spec['files'] ) && is_array( $tool_spec['files'] ) ? $tool_spec['files'] : array();
			return array(
				'schema'        => 'bizcity.twinweb.image_spec_trace.v1',
				'tool_schema'   => (string) ( $tool_spec['schema'] ?? '' ),
				'quality_gates' => isset( $tool_spec['quality_gates'] ) && is_array( $tool_spec['quality_gates'] ) ? $tool_spec['quality_gates'] : array(),
				'doc_contract'  => array(
					'owner'         => 'bizcity-doc',
					'doc_type'      => 'image',
					'artifact_type' => 'image',
					'export_target' => 'png/webp',
				),
				'request'       => array(
					'user_prompt'     => $this->limit_image_text( (string) ( $request['user_prompt'] ?? $prompt ), 3000 ),
					// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — image/poster trace preserves the same post-final AskBrain answer as doc handoff.
					'final_answer'    => $this->limit_image_text( (string) ( $request['final_answer'] ?? '' ), 5000 ),
					'enriched_prompt' => $this->limit_image_text( (string) ( $request['enriched_prompt'] ?? '' ), 5000 ),
					'title'           => sanitize_text_field( (string) ( $request['title'] ?? $title ) ),
					'aspect_ratio'    => sanitize_text_field( (string) $aspect_ratio ),
				),
				'thread'        => array(
					'session_id' => sanitize_text_field( (string) ( $thread['session_id'] ?? '' ) ),
					'summary'    => $this->limit_image_text( (string) ( $thread['summary'] ?? '' ), 4000 ),
					'recent'     => array_slice( (array) ( $thread['recent'] ?? array() ), -8 ),
				),
				'files'         => array(
					'attachment_ids' => array_values( array_map( 'absint', $input_images ) ),
					'summary'        => $this->limit_image_text( (string) ( $files['summary'] ?? '' ), 2500 ),
					'ingest'         => isset( $files['ingest'] ) && is_array( $files['ingest'] ) ? $files['ingest'] : array(),
					'items'          => array_slice( (array) ( $files['items'] ?? array() ), 0, 8 ),
					'context_md'     => $this->limit_image_text( (string) ( $files['context_md'] ?? '' ), 4000 ),
				),
			);
		}

		private function persist_image_handoff_schema( $doc_id, $prompt, $title, $aspect_ratio, array $input_images, array $tool_spec, array $spec_trace ) {
			// [2026-07-20 Johnny Chu] PHASE-1-TWIN-GPT-AGENT-TOOLS — image Canvas starts later, so store its handoff before gateway execution.
			global $wpdb;
			$doc_id = absint( $doc_id );
			if ( $doc_id <= 0 || ! isset( $wpdb ) ) {
				return;
			}
			$table = $wpdb->prefix . 'bzdoc_documents';
			$schema = array(
				'_handoff' => array(
					'schema'        => 'bzdoc.handoff.v1',
					'source'        => 'twin_gpt_image_agent_tool',
					'tool_slug'     => 'generate_image',
					'doc_id'        => $doc_id,
					'doc_type'      => 'image',
					'tool_spec'     => $tool_spec,
					'spec_trace'    => $spec_trace,
					'quality_gates' => isset( $tool_spec['quality_gates'] ) && is_array( $tool_spec['quality_gates'] ) ? $tool_spec['quality_gates'] : array(),
					'created_at'    => current_time( 'mysql' ),
				),
				'_autogen' => array(
					'topic'       => $prompt,
					'doc_type'    => 'image',
					'kickstart'   => true,
					'image_opts'  => array(
						'prompt'       => $prompt,
						'title'        => $title,
						'aspect_ratio' => $aspect_ratio !== '' ? $aspect_ratio : '1:1',
						'input_images' => array_values( array_map( 'absint', $input_images ) ),
					),
				),
			);
			$wpdb->update( $table, array(
				'schema_json' => wp_json_encode( $schema ),
				'updated_at'  => current_time( 'mysql' ),
			), array( 'id' => $doc_id ) );
		}

		private function limit_image_text( $text, $max ) {
			$text = trim( (string) $text );
			$max = max( 1, absint( $max ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $max ) {
				return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . '...';
			}
			if ( ! function_exists( 'mb_strlen' ) && strlen( $text ) > $max ) {
				return substr( $text, 0, $max - 1 ) . '...';
			}
			return $text;
		}

		private function bzdoc_rest_namespace() {
			return class_exists( 'BZDoc_Rest_API' ) && defined( 'BZDoc_Rest_API::NAMESPACE' )
				? trim( (string) BZDoc_Rest_API::NAMESPACE, '/' )
				: 'bzdoc/v1';
		}

		private function persist_image_result( $image_url, $b64_json, $title, $user_id ) {
			if ( ! function_exists( 'wp_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$file_path = '';
			$file_url  = '';
			$file_type = 'image/png';
			if ( '' !== $b64_json ) {
				$bytes = base64_decode( $b64_json, true );
				if ( false === $bytes ) {
					return new WP_Error( 'artifact_write_failed', 'Không đọc được dữ liệu ảnh trả về.' );
				}
				$upload = wp_upload_bits( sanitize_file_name( $title . '-' . wp_generate_uuid4() . '.png' ), null, $bytes );
				if ( ! empty( $upload['error'] ) ) {
					return new WP_Error( 'artifact_write_failed', (string) $upload['error'] );
				}
				$file_path = (string) $upload['file'];
				$file_url  = (string) $upload['url'];
			} elseif ( '' !== $image_url ) {
				$tmp = download_url( $image_url, 180 );
				if ( is_wp_error( $tmp ) ) {
					return $tmp;
				}
				$name = wp_basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) );
				if ( '' === $name || false === strpos( $name, '.' ) ) {
					$name = sanitize_file_name( $title . '-' . wp_generate_uuid4() . '.png' );
				}
				$file = array(
					'name'     => sanitize_file_name( $name ),
					'tmp_name' => $tmp,
				);
				$sideload = wp_handle_sideload( $file, array( 'test_form' => false ) );
				if ( ! empty( $sideload['error'] ) ) {
					@unlink( $tmp );
					return new WP_Error( 'artifact_write_failed', (string) $sideload['error'] );
				}
				$file_path = (string) $sideload['file'];
				$file_url  = (string) $sideload['url'];
				$file_type = (string) ( $sideload['type'] ?? 'image/png' );
			} else {
				return new WP_Error( 'artifact_write_failed', 'Gateway không trả về URL hoặc base64 ảnh.' );
			}

			$attachment_id = wp_insert_attachment( array(
				'post_mime_type' => $file_type,
				'post_title'     => sanitize_text_field( $title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => max( 0, (int) $user_id ),
			), $file_path );
			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				return new WP_Error( 'artifact_write_failed', 'Không tạo được media attachment cho ảnh.' );
			}

			$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $file_path );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( (int) $attachment_id, $metadata );
			}
			update_post_meta( (int) $attachment_id, '_bizcity_twinweb_artifact', 'image' );
			update_post_meta( (int) $attachment_id, '_bizcity_twinweb_tool_slug', 'generate_image' );

			return array(
				'attachment_id' => (int) $attachment_id,
				'url'           => $file_url,
			);
		}

		private function size_for_ratio( $ratio ) {
			$ratio = (string) $ratio;
			if ( '16:9' === $ratio ) {
				return '1536x864';
			}
			if ( '9:16' === $ratio ) {
				return '864x1536';
			}
			return '1024x1024';
		}

		private function derive_title( $prompt ) {
			$title = trim( wp_strip_all_tags( (string) $prompt ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 96 ) {
				$title = mb_substr( $title, 0, 96, 'UTF-8' );
			} elseif ( strlen( $title ) > 96 ) {
				$title = substr( $title, 0, 96 );
			}
			return $title !== '' ? $title : 'Ảnh Twin GPT';
		}
	}
}

if ( interface_exists( 'BizCity_Twin_Tool' ) && ! class_exists( 'BizCity_TwinWeb_HTML_Artifact_Tool' ) ) {
	class BizCity_TwinWeb_HTML_Artifact_Tool implements BizCity_Twin_Tool {

		public function name(): string {
			return 'render_html';
		}

		public function description(): string {
			return 'Render HTML/CSS preview artifact in Twin GPT sandbox Canvas.';
		}

		public function tool_class(): string {
			return 'producer';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'code'   => array( 'type' => 'string', 'description' => 'Complete HTML document or fragment.' ),
					'prompt' => array( 'type' => 'string', 'description' => 'Fallback UI request when code is not provided.' ),
					'title'  => array( 'type' => 'string' ),
				),
				'required'   => array(),
			);
		}

		public function execute( array $args, array $context ): array {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — execute HTML preview locally through sandbox artifact, not raw message DOM.
			unset( $context );
			$title = trim( (string) ( $args['title'] ?? '' ) );
			$code  = trim( (string) ( $args['code'] ?? '' ) );
			$prompt = trim( (string) ( $args['prompt'] ?? '' ) );
			if ( '' === $title ) {
				$title = $this->derive_title( $prompt !== '' ? $prompt : $code );
			}
			if ( '' === $code ) {
				$code = $this->fallback_html( $prompt !== '' ? $prompt : $title, $title );
			}

			$html = $this->sanitize_preview_html( $code );
			$artifact = array(
				'plugin_name'   => 'twin-gpt-canvas',
				'artifact_id'   => 'html_' . substr( md5( $html ), 0, 12 ),
				'artifact_type' => 'html',
				'status'        => 'ready',
				'render_mode'   => 'sandbox_html',
				'title'         => $title,
				'content'       => $html,
				'mime_type'     => 'text/html',
			);

			return array(
				'ok'             => true,
				'status'         => 'ready',
				'summary'        => 'Đã tạo HTML preview trong Canvas sandbox.',
				'result'         => array(
					'artifact_type' => 'html',
					'title'         => $title,
				),
				'artifact_ready' => $artifact,
				'canvas_open'    => array(
					'type'        => 'html',
					'content'     => $html,
					'title'       => $title,
					'status'      => 'ready',
					'render_mode' => 'sandbox_html',
				),
			);
		}

		private function sanitize_preview_html( $html ) {
			$html = (string) $html;
			$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
			$html = preg_replace( '#<(iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html );
			$html = preg_replace( '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );
			$html = preg_replace( '#javascript\s*:#i', '', $html );
			return $html !== '' ? $html : $this->fallback_html( 'HTML preview', 'HTML preview' );
		}

		private function fallback_html( $prompt, $title ) {
			$prompt = trim( wp_strip_all_tags( (string) $prompt ) );
			$title  = trim( wp_strip_all_tags( (string) $title ) );
			if ( '' === $title ) {
				$title = 'HTML preview';
			}
			if ( '' === $prompt ) {
				$prompt = 'Preview artifact created by Twin GPT.';
			}

			return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font-family:Georgia,serif;background:#f7f4ef;color:#1f2933}.wrap{min-height:100vh;display:grid;place-items:center;padding:32px}.panel{max-width:720px;border:1px solid #d7cfc2;background:#fffdf8;padding:28px;box-shadow:0 18px 50px rgba(31,41,51,.12)}h1{margin:0 0 12px;font-size:32px;line-height:1.15}p{font-size:16px;line-height:1.7}</style></head><body><main class="wrap"><section class="panel"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $prompt ) . '</p></section></main></body></html>';
		}

		private function derive_title( $input ) {
			$title = trim( wp_strip_all_tags( (string) $input ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 80 ) {
				$title = mb_substr( $title, 0, 80, 'UTF-8' );
			} elseif ( strlen( $title ) > 80 ) {
				$title = substr( $title, 0, 80 );
			}
			return $title !== '' ? $title : 'HTML preview';
		}
	}
}

if ( interface_exists( 'BizCity_Twin_Tool' ) && ! class_exists( 'BizCity_TwinWeb_Chart_Artifact_Tool' ) ) {
	class BizCity_TwinWeb_Chart_Artifact_Tool implements BizCity_Twin_Tool {

		public function name(): string {
			return 'generate_chart';
		}

		public function description(): string {
			return 'Return structured chart JSON artifact for Twin GPT Canvas preview.';
		}

		public function tool_class(): string {
			return 'producer';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'title'     => array( 'type' => 'string' ),
					'type'      => array( 'type' => 'string', 'enum' => array( 'bar', 'line', 'pie' ) ),
					'data'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'xKey'      => array( 'type' => 'string' ),
					'dataKeys'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'prompt'    => array( 'type' => 'string' ),
				),
				'required'   => array(),
			);
		}

		public function execute( array $args, array $context ): array {
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — make chart tool executable without provider calls or DB writes.
			unset( $context );
			$title = trim( (string) ( $args['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = $this->derive_title( (string) ( $args['prompt'] ?? '' ) );
			}
			$chart_type = sanitize_key( (string) ( $args['type'] ?? 'bar' ) );
			if ( ! in_array( $chart_type, array( 'bar', 'line', 'pie' ), true ) ) {
				$chart_type = 'bar';
			}
			$data = isset( $args['data'] ) && is_array( $args['data'] ) ? $this->normalize_rows( $args['data'] ) : array();
			if ( empty( $data ) ) {
				$data = $this->sample_rows( (string) ( $args['prompt'] ?? '' ) );
			}
			$x_key = sanitize_key( (string) ( $args['xKey'] ?? 'label' ) );
			$data_keys = isset( $args['dataKeys'] ) && is_array( $args['dataKeys'] ) ? $this->normalize_keys( $args['dataKeys'] ) : array( 'value' );

			$payload = array(
				'title'     => $title,
				'type'      => $chart_type,
				'data'      => $data,
				'xKey'      => $x_key !== '' ? $x_key : 'label',
				'dataKeys'  => ! empty( $data_keys ) ? $data_keys : array( 'value' ),
				'source'    => 'twin_gpt_agent_tool',
			);
			$content = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			$artifact = array(
				'plugin_name'   => 'twin-gpt-canvas',
				'artifact_id'   => 'chart_' . substr( md5( (string) $content ), 0, 12 ),
				'artifact_type' => 'chart',
				'status'        => 'ready',
				'render_mode'   => 'card',
				'title'         => $title,
				'content'       => (string) $content,
				'mime_type'     => 'application/json',
			);

			return array(
				'ok'             => true,
				'status'         => 'ready',
				'summary'        => 'Đã tạo chart artifact trong Canvas.',
				'result'         => $payload,
				'artifact_ready' => $artifact,
				'canvas_open'    => array(
					'type'        => 'chart',
					'content'     => (string) $content,
					'title'       => $title,
					'status'      => 'ready',
					'render_mode' => 'card',
				),
			);
		}

		private function normalize_rows( array $rows ) {
			$out = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$item = array();
				foreach ( $row as $key => $value ) {
					$clean_key = sanitize_key( (string) $key );
					if ( '' === $clean_key ) {
						continue;
					}
					$item[ $clean_key ] = is_numeric( $value ) ? (float) $value : sanitize_text_field( (string) $value );
				}
				if ( ! empty( $item ) ) {
					$out[] = $item;
				}
			}
			return $out;
		}

		private function normalize_keys( array $keys ) {
			$out = array();
			foreach ( $keys as $key ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key ) {
					$out[] = $key;
				}
			}
			return array_values( array_unique( $out ) );
		}

		private function sample_rows( $prompt ) {
			$numbers = array();
			if ( preg_match_all( '/-?\d+(?:[\.,]\d+)?/', (string) $prompt, $matches ) ) {
				foreach ( array_slice( $matches[0], 0, 6 ) as $number ) {
					$numbers[] = (float) str_replace( ',', '.', $number );
				}
			}
			if ( empty( $numbers ) ) {
				$numbers = array( 12, 18, 27 );
			}

			$rows = array();
			foreach ( $numbers as $index => $value ) {
				$rows[] = array(
					'label' => 'Mục ' . ( $index + 1 ),
					'value' => $value,
				);
			}
			return $rows;
		}

		private function derive_title( $prompt ) {
			$title = trim( wp_strip_all_tags( (string) $prompt ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $title, 'UTF-8' ) > 80 ) {
				$title = mb_substr( $title, 0, 80, 'UTF-8' );
			} elseif ( strlen( $title ) > 80 ) {
				$title = substr( $title, 0, 80 );
			}
			return $title !== '' ? $title : 'Biểu đồ Twin GPT';
		}
	}
}

if ( interface_exists( 'BizCity_Twin_Tool' ) ) {
	// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register MVP artifact tools through the canonical registry filter.
	add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
		if ( ! is_array( $registry ) ) {
			$registry = array();
		}
		$registry['create_doc']     = new BizCity_TwinWeb_Doc_Artifact_Tool( 'create_doc', 'document', 'document', 'tài liệu' );
		$registry['create_xlsx']    = new BizCity_TwinWeb_Doc_Artifact_Tool( 'create_xlsx', 'spreadsheet', 'xlsx', 'bảng tính' );
		$registry['create_pptx']    = new BizCity_TwinWeb_Doc_Artifact_Tool( 'create_pptx', 'presentation', 'pptx', 'slide' );
		$registry['create_mindmap'] = new BizCity_TwinWeb_Doc_Artifact_Tool( 'create_mindmap', 'mindmap', 'mindmap', 'mindmap' );
		$registry['generate_image'] = new BizCity_TwinWeb_Image_Artifact_Tool();
		$registry['render_html']    = new BizCity_TwinWeb_HTML_Artifact_Tool();
		$registry['generate_chart'] = new BizCity_TwinWeb_Chart_Artifact_Tool();
		return $registry;
	}, 20 );
}