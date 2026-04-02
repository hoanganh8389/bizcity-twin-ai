<?php
/**
 * BizCity Automation Intent Provider
 *
 * Registers with Intent Engine so users can generate workflow scenarios
 * via chat. AI generates workflow JSON (nodes/edges) → saves as draft
 * task → returns builder link for review/publish.
 *
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Automation_Intent_Provider extends BizCity_Intent_Provider {

	/**
	 * Curated list of chainable blocks for LLM workflow generation.
	 * Each entry: code => [ label, category, settings summary, output variables ]
	 *
	 * @var array
	 */
	private $chainable_blocks = array();

	/**
	 * Tools used to manage workflows themselves.
	 * These must never appear as action steps inside generated workflows.
	 *
	 * @var array
	 */
	private $orchestration_tools = array( 'build_workflow', 'publish_workflow', 'list_workflows' );

	/* ================================================================
	 *  Identity
	 * ================================================================ */

	public function get_id() {
		return 'automation';
	}

	public function get_name() {
		return 'BizCity Workflow Automation — Tạo kịch bản quy trình tự động';
	}

	/* ================================================================
	 *  Goal Patterns (Router) — high trust
	 * ================================================================ */

	public function get_goal_patterns() {
		return array(
			/* --- Explicit workflow creation --- */
			'/(?:lập|tạo|xây\s*dựng|thiết\s*kế|build|create|design)\s*(?:kịch\s*bản|workflow|quy\s*trình|pipeline|scenario|automation)/ui' => array(
				'goal'        => 'build_workflow',
				'label'       => 'Tạo kịch bản workflow',
				'description' => 'Tạo kịch bản workflow tự động hóa từ mô tả',
				'extract'     => array( 'description' ),
			),

			/* --- Chain keywords: "A rồi B", "A sau đó B" --- */
			'/(?:viết|tạo|gen|soạn|làm)\s*.{5,60}?\s*(?:rồi|sau\s*đó|tiếp\s*theo|xong\s*thì|rồi\s*thì)\s*(?:đăng|post|gửi|tạo|viết|gen|upload|publish)/ui' => array(
				'goal'        => 'build_workflow',
				'label'       => 'Tạo workflow chuỗi tự động',
				'description' => 'Nhận diện multi-step chain từ mô tả ngôn ngữ tự nhiên',
				'extract'     => array( 'description' ),
			),

			/* --- English chain keywords --- */
			'/(?:create|write|generate)\s*.{5,60}?\s*(?:then|and\s*then|after\s*that|next)\s*(?:post|send|publish|create|upload)/ui' => array(
				'goal'        => 'build_workflow',
				'label'       => 'Build workflow chain',
				'description' => 'Multi-step chain from natural language',
				'extract'     => array( 'description' ),
			),

			/* --- List / manage workflows --- */
			'/(?:xem|danh\s*sách|list)\s*(?:workflow|kịch\s*bản|quy\s*trình|automation)/ui' => array(
				'goal'        => 'list_workflows',
				'label'       => 'Xem danh sách workflows',
				'description' => 'Liệt kê workflows đã tạo',
				'extract'     => array(),
			),
		);
	}

	/* ================================================================
	 *  Plans (Planner) — slot schemas
	 * ================================================================ */

	public function get_plans() {
		return array(
			'build_workflow' => array(
				'required_slots' => array(
					'description' => array(
						'type'        => 'text',
						'prompt'      => '📝 Mô tả quy trình bạn muốn tự động hóa? (VD: "viết bài rồi đăng Facebook")',
						'no_auto_map' => true,
					),
				),
				'optional_slots' => array(
					'trigger_type' => array(
						'type'    => 'choice',
						'choices' => array(
							'adminchat' => '💬 Chat Admin — nhắn tin trên giao diện chat này',
							'zalo'      => '📱 Zalo Bot — nhắn tin qua Zalo OA',
							'schedule'  => '⏰ Lên lịch — tự chạy theo thời gian đặt trước',
						),
						'prompt'  => '🤔 Bạn muốn kích hoạt workflow bằng cách nào?',
						'default' => 'adminchat',
					),
					'trigger_filter' => array(
						'type'    => 'text',
						'prompt'  => '🔑 Từ khóa / ký hiệu để kích hoạt workflow là gì? (VD: gõ "mkt" hoặc "tạo video" để khởi động)',
						'default' => '',
					),
				),
				'tool'       => 'build_workflow',
				'ai_compose' => true,
				'slot_order' => array( 'description', 'trigger_type', 'trigger_filter' ),
			),

			'list_workflows' => array(
				'required_slots' => array(),
				'optional_slots' => array(
					'limit' => array(
						'type'    => 'choice',
						'choices' => array( '5' => '5 gần nhất', '10' => '10 gần nhất', '20' => '20 gần nhất' ),
						'default' => '10',
					),
				),
				'tool'       => 'list_workflows',
				'ai_compose' => false,
			),
		);
	}

	/* ================================================================
	 *  Tools — callbacks
	 * ================================================================ */

	public function get_tools() {
		return array(
			'build_workflow' => array(
				'schema' => array(
					'description'  => 'Tạo kịch bản workflow từ mô tả. AI gen JSON → lưu draft → trả link builder.',
					'input_fields' => array(
						'description'    => array( 'required' => true,  'type' => 'text', 'description' => 'Mô tả quy trình muốn tự động hóa' ),
						'trigger_type'   => array( 'required' => false, 'type' => 'choice', 'description' => 'Loại trigger: adminchat, schedule, webhook, manual' ),
						'trigger_filter' => array( 'required' => false, 'type' => 'text', 'description' => 'Bộ lọc trigger (keyword, regex)' ),
					),
				),
				'callback'     => array( $this, 'tool_build_workflow' ),
				'auto_execute' => false,
			),

			'publish_workflow' => array(
				'schema' => array(
					'description'  => 'Publish một workflow draft đã tạo trước đó.',
					'input_fields' => array(
						'task_id' => array( 'required' => true, 'type' => 'number', 'description' => 'ID của task cần publish' ),
					),
				),
				'callback'     => array( $this, 'tool_publish_workflow' ),
				'auto_execute' => false,
			),

			'list_workflows' => array(
				'schema' => array(
					'description'  => 'Xem danh sách workflows đã tạo.',
					'input_fields' => array(
						'limit' => array( 'required' => false, 'type' => 'number', 'description' => 'Số lượng (mặc định 10)' ),
					),
				),
				'callback'     => array( $this, 'tool_list_workflows' ),
				'auto_execute' => true,
			),
		);
	}

	/* ================================================================
	 *  Examples (Tools Map hints)
	 * ================================================================ */

	public function get_examples() {
		return array(
			'build_workflow' => array(
				'Lập kịch bản viết bài rồi đăng Facebook',
				'Tạo workflow tạo video từ ảnh',
				'Thiết kế quy trình: viết script → gen ảnh → tạo video → đăng FB',
				'Build automation: khi nhận tin nhắn tạo video thì gen script rồi tạo video kling',
			),
			'list_workflows' => array(
				'Xem danh sách workflow',
				'List automation đã tạo',
			),
		);
	}

	/* ================================================================
	 *  System Instructions
	 * ================================================================ */

	public function get_system_instructions( $goal ) {
		if ( $goal === 'build_workflow' ) {
			return "Bạn là chuyên gia tự động hóa workflow BizCity.\n"
				. "Khi user mô tả quy trình multi-step, phân tích từng bước → chọn block phù hợp → gen kịch bản.\n"
				. "Trình bày rõ từng bước trước khi tạo. Giải thích tác dụng ở mỗi bước.\n"
				. "Sau khi tạo xong draft, hướng dẫn user mở builder để review và publish.\n"
				. "Nếu user muốn publish ngay, hỏi xác nhận trước.";
		}
		return '';
	}

	/* ================================================================
	 *  Context building
	 * ================================================================ */

	public function build_context( $goal, array $slots, $user_id, array $conversation ) {
		$ctx = "Plugin: BizCity Workflow Automation\n";

		// Count existing workflows
		if ( class_exists( 'WaicFrame' ) ) {
			$count = WaicDb::get( "SELECT COUNT(*) FROM `@__tasks` WHERE feature='workflow'", 'one' );
			$ctx  .= "Workflows hiện có: {$count}\n";
		}

		if ( $goal === 'build_workflow' ) {
			$ctx .= "\nKiến trúc workflow: Pipeline tuyến tính, tất cả action dùng it_call_tool (Agent).\n";
			$tools = $this->get_available_intent_tools();
			if ( $tools ) {
				$ctx .= "\nCông cụ AI có sẵn:\n{$tools}\n";
			}
		}

		return $ctx;
	}

	/* ================================================================
	 *  Tool: build_workflow — Generate workflow JSON + save draft
	 * ================================================================ */

	public function tool_build_workflow( $slots ) {
		$description    = isset( $slots['description'] ) ? $slots['description'] : '';
		$trigger_type   = isset( $slots['trigger_type'] ) ? $slots['trigger_type'] : 'adminchat';
		$trigger_filter = isset( $slots['trigger_filter'] ) ? $slots['trigger_filter'] : '';

		if ( empty( $description ) ) {
			return array(
				'success'        => false,
				'complete'       => false,
				'message'        => 'Vui lòng mô tả quy trình bạn muốn tự động hóa.',
				'missing_fields' => array( 'description' ),
			);
		}

		// 1. Fast deterministic path for common chained prompts (write -> image -> post)
		$scenario_json = $this->build_rule_based_scenario( $description, $trigger_type, $trigger_filter );

		// 2. Fallback to LLM for complex scenarios
		if ( ! $scenario_json ) {
			$system_prompt = $this->build_gen_system_prompt( $trigger_type, $trigger_filter );
			$scenario_json = $this->call_llm_generate( $system_prompt, $description );
		}

		if ( ! $scenario_json || empty( $scenario_json['nodes'] ) ) {
			return array(
				'success' => false,
				'message' => '❌ Không thể tạo kịch bản từ mô tả. Vui lòng mô tả cụ thể hơn các bước cần thực hiện.',
			);
		}

		// 3. Validate scenario
		$validation = $this->validate_scenario( $scenario_json, $description );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'message' => '❌ Kịch bản có block không hợp lệ: ' . $validation['error'],
			);
		}
		// Use validated/fixed scenario (linear edges enforced)
		if ( isset( $validation['scenario'] ) ) {
			$scenario_json = $validation['scenario'];
		}

		// 4. Save as draft task
		$task_id = $this->save_as_draft_task( $scenario_json, $description, $trigger_filter );
		if ( ! $task_id ) {
			return array(
				'success' => false,
				'message' => '❌ Không thể lưu kịch bản. Vui lòng thử lại.',
			);
		}

		// 5. Build edit URL + step summary
		$edit_url = admin_url( "admin.php?page=bizcity-workspace&tab=builder&task_id={$task_id}" );
		$summary  = $this->build_step_summary( $scenario_json, $task_id, $edit_url );

		return array(
			'success'  => true,
			'complete' => true,
			'message'  => $summary,
			'data'     => array(
				'type'  => 'workflow',
				'id'    => $task_id,
				'url'   => $edit_url,
				'title' => isset( $scenario_json['task_title'] ) ? $scenario_json['task_title'] : $description,
				'steps' => count( $scenario_json['nodes'] ) - 1, // minus trigger
			),
		);
	}

	/**
	 * Build a deterministic scenario for common linear chains.
	 *
	 * This bypasses LLM for high-confidence prompts like:
	 * "viết bài ... rồi tạo ảnh ... rồi đăng Facebook".
	 *
	 * @return array|null Scenario JSON or null if not matched.
	 */
	private function build_rule_based_scenario( $description, $trigger_type = 'adminchat', $trigger_filter = '' ) {
		$text = mb_strtolower( (string) $description, 'UTF-8' );

		$want_article  = (bool) preg_match( '/(viết\s*bài|write\s*article|blog|content)/u', $text );
		$want_image    = (bool) preg_match( '/(tạo\s*ảnh|ảnh\s*minh\s*họa|generate\s*image|image)/u', $text );
		$want_facebook = (bool) preg_match( '/(đăng\s*facebook|post\s*facebook|facebook)/u', $text );

		$steps = array();
		if ( $want_article ) {
			$steps[] = 'write_article';
		}
		if ( $want_image ) {
			$steps[] = 'generate_image';
		}
		if ( $want_facebook ) {
			$steps[] = 'post_facebook';
		}

		if ( count( $steps ) < 2 ) {
			return null;
		}

		$trigger = $this->build_trigger_node( $trigger_type, $trigger_filter );
		$nodes   = array( $trigger );

		$x = 560;
		$node_idx = 2;
		foreach ( $steps as $tool_id ) {
			$nodes[] = array(
				'id'       => (string) $node_idx,
				'type'     => 'action',
				'position' => array( 'x' => $x, 'y' => 200 ),
				'data'     => array(
					'type'     => 'action',
					'category' => 'it',
					'code'     => 'it_call_tool',
					'label'    => '🤖 Agent — ' . $tool_id,
					'settings' => array(
						'tool_id'        => $tool_id,
						'input_json'     => $this->default_input_json_for_tool( $tool_id, $node_idx ),
						'user_id_source' => 'trigger',
					),
				),
			);
			$x += 210;
			$node_idx++;
		}

		return array(
			'task_title' => 'Workflow: ' . mb_substr( $description, 0, 100, 'UTF-8' ),
			'nodes'      => $nodes,
			'edges'      => $this->build_linear_edges( $nodes ),
			'settings'   => array( 'timeout' => 300, 'multiple' => 0, 'skip' => 0, 'cooldown' => 0, 'stop' => 'yes' ),
			'version'    => '1.0.0',
		);
	}

	/**
	 * Build trigger node based on trigger type and filter.
	 */
	private function build_trigger_node( $trigger_type, $trigger_filter = '' ) {
		$trigger_map = array(
			'adminchat' => array( 'code' => 'bc_adminchat_message',    'category' => 'bc', 'label' => '💬 Admin Chat — Nhận tin nhắn' ),
			'zalo'      => array( 'code' => 'wu_zalobot_text_received', 'category' => 'wu', 'label' => '📱 Zalo Bot — Nhận tin nhắn Zalo' ),
			'schedule'  => array( 'code' => 'sy_schedule',              'category' => 'sy', 'label' => '⏰ Schedule — Lên lịch tự động' ),
			'webhook'   => array( 'code' => 'sy_webhook',               'category' => 'sy', 'label' => '🔗 Webhook (URL)' ),
			'manual'    => array( 'code' => 'sy_manual',                'category' => 'sy', 'label' => '▶️ Manual Trigger' ),
		);

		$trigger_info = isset( $trigger_map[ $trigger_type ] ) ? $trigger_map[ $trigger_type ] : $trigger_map['adminchat'];
		$settings = array();
		if ( in_array( $trigger_type, array( 'adminchat', 'zalo' ), true ) && ! empty( $trigger_filter ) ) {
			$settings['text_contains'] = $trigger_filter;
			$settings['text_regex']    = '';
		}

		return array(
			'id'       => '1',
			'type'     => 'trigger',
			'position' => array( 'x' => 350, 'y' => 200 ),
			'data'     => array(
				'type'     => 'trigger',
				'category' => $trigger_info['category'],
				'code'     => $trigger_info['code'],
				'label'    => $trigger_info['label'],
				'settings' => $settings,
			),
		);
	}

	/**
	 * Build linear edges for node list.
	 */
	private function build_linear_edges( $nodes ) {
		$edges = array();
		for ( $i = 0; $i < count( $nodes ) - 1; $i++ ) {
			$src = $nodes[ $i ]['id'];
			$tgt = $nodes[ $i + 1 ]['id'];
			$edges[] = array(
				'id'           => "e{$src}-{$tgt}",
				'source'       => $src,
				'target'       => $tgt,
				'sourceHandle' => 'output-right',
				'targetHandle' => 'input-left',
				'type'         => 'default',
			);
		}
		return $edges;
	}

	/**
	 * Default input_json template per tool to keep chaining predictable.
	 */
	private function default_input_json_for_tool( $tool_id, $node_idx ) {
		$prev = max( 1, (int) $node_idx - 1 );

		switch ( $tool_id ) {
			case 'write_article':
				return '{"topic": "{{node#1.text}}"}';

			case 'generate_image':
				if ( $prev <= 1 ) {
					return '{"message": "{{node#1.text}}"}';
				}
				return '{"message": "{{node#' . $prev . '.message}}"}';

			case 'post_facebook':
				if ( $prev <= 1 ) {
					return '{"content": "{{node#1.text}}", "image_url": "{{node#1.image_url}}"}';
				}
				return '{"content": "{{node#' . $prev . '.message}}", "image_url": "{{node#' . $prev . '.image_url}}"}';

			default:
				if ( $prev <= 1 ) {
					return '{"message": "{{node#1.text}}"}';
				}
				return '{"message": "{{node#' . $prev . '.message}}"}';
		}
	}

	/* ================================================================
	 *  Tool: publish_workflow
	 * ================================================================ */

	public function tool_publish_workflow( $slots ) {
		$task_id = isset( $slots['task_id'] ) ? (int) $slots['task_id'] : 0;

		if ( ! $task_id ) {
			return array(
				'success'        => false,
				'complete'       => false,
				'message'        => 'Vui lòng cung cấp ID của workflow cần publish.',
				'missing_fields' => array( 'task_id' ),
			);
		}

		if ( ! class_exists( 'WaicFrame' ) ) {
			return array(
				'success' => false,
				'message' => '❌ Automation engine chưa sẵn sàng.',
			);
		}

		$task_model = WaicFrame::_()->getModule( 'workspace' )->getModel( 'tasks' );
		$task       = $task_model->getTask( $task_id );

		if ( ! $task ) {
			return array(
				'success' => false,
				'message' => "❌ Không tìm thấy workflow #{$task_id}.",
			);
		}

		// Only allow publishing non-published tasks
		if ( $task_model->isPublished( $task['status'] ) ) {
			return array(
				'success' => true,
				'message' => "✅ Workflow #{$task_id} đã được publish trước đó.",
				'data'    => array( 'type' => 'workflow', 'id' => $task_id ),
			);
		}

		// Publish
		$workflow_model = WaicFrame::_()->getModule( 'workflow' )->getModel();
		$workflow_model->publishResults( $task_id );

		$edit_url = admin_url( "admin.php?page=bizcity-workspace&tab=builder&task_id={$task_id}" );

		return array(
			'success'  => true,
			'complete' => true,
			'message'  => "✅ Workflow **#{$task_id}** đã được publish thành công!\n\n"
				. "🔗 [Mở trong Builder]({$edit_url})\n\n"
				. "Workflow sẽ bắt đầu hoạt động theo trigger đã cấu hình.",
			'data' => array(
				'type' => 'workflow',
				'id'   => $task_id,
				'url'  => $edit_url,
			),
		);
	}

	/* ================================================================
	 *  Tool: list_workflows
	 * ================================================================ */

	public function tool_list_workflows( $slots ) {
		$limit = isset( $slots['limit'] ) ? (int) $slots['limit'] : 10;
		$limit = max( 1, min( 50, $limit ) );

		if ( ! class_exists( 'WaicFrame' ) ) {
			return array(
				'success' => false,
				'message' => '❌ Automation engine chưa sẵn sàng.',
			);
		}

		$rows = WaicDb::get(
			"SELECT id, title, status, created, updated FROM `@__tasks` "
			. "WHERE feature='workflow' ORDER BY id DESC LIMIT " . $limit,
			'all'
		);

		if ( empty( $rows ) ) {
			return array(
				'success'  => true,
				'complete' => true,
				'message'  => '📭 Chưa có workflow nào.',
			);
		}

		$task_model = WaicFrame::_()->getModule( 'workspace' )->getModel( 'tasks' );
		$lines      = array();
		foreach ( $rows as $row ) {
			$status_text = $task_model->getStatuses( (int) $row['status'] );
			$lines[]     = sprintf(
				"#%d **%s** — %s (tạo: %s)",
				$row['id'],
				$row['title'],
				$status_text,
				wp_date( 'd/m/Y', strtotime( $row['created'] ) )
			);
		}

		return array(
			'success'  => true,
			'complete' => true,
			'message'  => sprintf(
				"📋 **Danh sách Workflows** (%d):\n\n%s",
				count( $rows ),
				implode( "\n", $lines )
			),
			'data' => array(
				'type'  => 'workflow_list',
				'count' => count( $rows ),
			),
		);
	}

	/* ================================================================
	 *  Chainable Tools Catalog
	 * ================================================================ */

	/**
	 * Get curated list of blocks that can be used in AI-generated workflows.
	 * Combines intent tools + key WaicAction blocks.
	 *
	 * @return array code => [ 'label', 'category', 'settings', 'output' ]
	 */
	public function get_chainable_tools() {
		if ( ! empty( $this->chainable_blocks ) ) {
			return $this->chainable_blocks;
		}

		$this->chainable_blocks = array(
			/* --- Triggers --- */
			'bc_adminchat_message' => array(
				'type'     => 'trigger',
				'label'    => 'Admin Chat Message',
				'category' => 'bc',
				'settings' => array(
					'text_contains' => 'Keyword filter (optional)',
					'text_regex'    => 'Regex pattern (optional)',
				),
				'output'   => array( 'session_id', 'user_id', 'display_name', 'text', 'message_id', 'image_url', 'platform', 'reply_to' ),
			),
			'sy_schedule' => array(
				'type'     => 'trigger',
				'label'    => 'Schedule (Cron)',
				'category' => 'sy',
				'settings' => array(
					'mode'      => 'one (one-time) or period (recurring)',
					'date'      => 'YYYY-MM-DD (if mode=one)',
					'time'      => 'HH:MM (if mode=one)',
					'frequency' => 'Number (if mode=period)',
					'units'     => 'd/h/m (days/hours/minutes, if mode=period)',
				),
				'output'   => array( 'date', 'time' ),
			),
			'sy_manual' => array(
				'type'     => 'trigger',
				'label'    => 'Manual Trigger',
				'category' => 'sy',
				'settings' => array(),
				'output'   => array( 'date', 'time' ),
			),
			'wu_zalobot_text_received' => array(
				'type'     => 'trigger',
				'label'    => 'Zalo Bot — Nhận tin nhắn',
				'category' => 'wu',
				'settings' => array(
					'text_contains' => 'Keyword filter (optional)',
					'text_regex'    => 'Regex pattern (optional)',
				),
				'output'   => array( 'bot_name', 'user_id', 'display_name', 'text', 'image_url', 'platform' ),
			),
			'sy_webhook' => array(
				'type'     => 'trigger',
				'label'    => 'Webhook (URL)',
				'category' => 'sy',
				'settings' => array(),
				'output'   => array( 'data', 'headers' ),
			),

			/* --- Agent Caller (ALL actions use this) --- */
			'it_call_tool' => array(
				'type'     => 'action',
				'label'    => 'Agent — Gọi công cụ AI',
				'category' => 'it',
				'settings' => array(
					'tool_id'        => 'Tool name from Intent Tools registry',
					'input_json'     => 'JSON string with {{node#X.field}} variables as input',
					'user_id_source' => 'trigger (from trigger user) / admin / none',
				),
				'output'   => array( 'success', 'tool_name', 'title', 'message', 'image_url', 'resource_id', 'resource_url' ),
			),
		);

		return $this->chainable_blocks;
	}

	/* ================================================================
	 *  LLM Workflow Generator Internals
	 * ================================================================ */

	/**
	 * Get available intent tools from DB registry for LLM prompt.
	 *
	 * @return string Compact list of tool_name => description
	 */
	private function get_available_intent_tools() {
		if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
			return '';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_tool_registry';
		$rows  = $wpdb->get_results(
			"SELECT tool_name, title, description, required_slots, optional_slots, trust_tier, tool_type, priority
			 FROM {$table}
			 WHERE active = 1
			 ORDER BY trust_tier ASC, priority ASC, tool_name ASC",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return '';
		}

		$lines = array();
		foreach ( $rows as $row ) {
			$tool_name = (string) ( $row['tool_name'] ?? '' );
			if ( ! $tool_name || in_array( $tool_name, $this->orchestration_tools, true ) ) {
				continue;
			}

			$required = json_decode( $row['required_slots'] ?? '{}', true );
			$optional = json_decode( $row['optional_slots'] ?? '{}', true );

			$required_names = is_array( $required ) ? array_keys( $required ) : array();
			$optional_names = is_array( $optional ) ? array_keys( $optional ) : array();

			$required_text = empty( $required_names ) ? 'none' : implode( ', ', $required_names );
			$optional_text = empty( $optional_names ) ? 'none' : implode( ', ', array_slice( $optional_names, 0, 6 ) );

			$lines[] = sprintf(
				'- %s [tier:%d|type:%s|p:%d] — %s | required: %s | optional: %s',
				$tool_name,
				(int) ( $row['trust_tier'] ?? 4 ),
				(string) ( $row['tool_type'] ?? 'atomic' ),
				(int) ( $row['priority'] ?? 50 ),
				(string) ( $row['title'] ?? $row['description'] ?? $tool_name ),
				$required_text,
				$optional_text
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Build system prompt for LLM to generate workflow JSON.
	 */
	private function build_gen_system_prompt( $trigger_type, $trigger_filter = '' ) {
		$trigger_map = array(
			'adminchat' => array( 'code' => 'bc_adminchat_message',    'category' => 'bc', 'label' => '💬 Admin Chat — Nhận tin nhắn' ),
			'zalo'      => array( 'code' => 'wu_zalobot_text_received', 'category' => 'wu', 'label' => '📱 Zalo Bot — Nhận tin nhắn Zalo' ),
			'schedule'  => array( 'code' => 'sy_schedule',              'category' => 'sy', 'label' => '⏰ Schedule — Lên lịch tự động' ),
			'webhook'   => array( 'code' => 'sy_webhook',               'category' => 'sy', 'label' => '🔗 Webhook (URL)' ),
			'manual'    => array( 'code' => 'sy_manual',                'category' => 'sy', 'label' => '▶️ Manual Trigger' ),
		);
		$trigger_info = isset( $trigger_map[ $trigger_type ] ) ? $trigger_map[ $trigger_type ] : $trigger_map['adminchat'];

		// Build trigger settings JSON
		$trigger_settings = array();
		if ( in_array( $trigger_type, array( 'adminchat', 'zalo' ), true ) && ! empty( $trigger_filter ) ) {
			$trigger_settings['text_contains'] = $trigger_filter;
			$trigger_settings['text_regex']    = '';
		}
		$trigger_settings_json = ! empty( $trigger_settings ) ? wp_json_encode( $trigger_settings, JSON_UNESCAPED_UNICODE ) : '{}';

		// Get available intent tools from DB
		$tools_list = $this->get_available_intent_tools();

		return <<<PROMPT
Bạn là workflow generator cho BizCity Automation.

NHIỆM VỤ: Phân tích mô tả user → tạo workflow JSON theo pipeline TUYẾN TÍNH.

QUY TẮC BẮT BUỘC:

1. Pipeline TUYẾN TÍNH: node 1 → 2 → 3 → 4... KHÔNG ĐƯỢC tạo nhánh song song.

2. Node đầu tiên PHẢI là trigger (id="1", type="trigger").
   Trigger đã chọn: code="{$trigger_info['code']}", category="{$trigger_info['category']}", label="{$trigger_info['label']}"
   Trigger settings: {$trigger_settings_json}

3. ⭐ QUAN TRỌNG NHẤT — Phân tích {{node#1.text}}:
   {{node#1.text}} chứa TOÀN BỘ nội dung user gửi lên, có thể bao gồm:
   - Chủ đề / topic (VD: "viết bài về marketing")
   - Chủ đề + URL ảnh (VD: "tạo bài về AI, ảnh: https://example.com/photo.jpg")
   - Chủ đề + nhiều thông tin phụ (VD: "viết bài mkt cho shop thời trang, đăng FB và tạo sản phẩm")
   
   → Node action ĐẦU TIÊN (node#2) LUÔN phải nhận {{node#1.text}} làm input chính.
   → Mỗi tool đều có AI Agent tự động parse nội dung này, nên CHỈ CẦN truyền nguyên {{node#1.text}}.
   → KHÔNG cần tách/parse text thủ công — Agent sẽ xử lý.
   
   Cách viết input_json cho node#2:
   - Tool write_article: {"topic": "{{node#1.text}}"}
   - Tool create_product: {"message": "{{node#1.text}}"}
   - Tool post_facebook: {"content": "{{node#1.text}}"}
   - Tool create_video: {"message": "{{node#1.text}}"}
   - Bất kỳ tool nào: truyền vào field chính (topic/message/content/query) = "{{node#1.text}}"

4. TẤT CẢ action nodes đều PHẢI dùng block code="it_call_tool", category="it".
   Mỗi action node gọi 1 công cụ AI qua settings:
   - tool_id: tên công cụ từ danh sách bên dưới
   - input_json: JSON string, dùng biến output từ node trước
   - user_id_source: "trigger"

5. Mỗi edge nối tuyến tính source → target: sourceHandle="output-right", targetHandle="input-left".

6. OUTPUT VARIABLES — Dùng chính xác các biến sau:
   Từ trigger (node#1):
     {{node#1.text}} — toàn bộ nội dung user gửi (quan trọng nhất!)
     {{node#1.image_url}} — URL ảnh đính kèm (nếu có)
     {{node#1.user_id}}, {{node#1.display_name}}, {{node#1.session_id}}
   Từ action it_call_tool (node#2, node#3...):
     {{node#X.title}} — tiêu đề resource (bài viết, sản phẩm...)
     {{node#X.message}} — nội dung kết quả dạng text
     {{node#X.image_url}} — URL ảnh output (featured image...)
     {{node#X.resource_url}} — URL resource đã tạo
     {{node#X.resource_id}} — ID resource
     {{node#X.success}} — "true" hoặc "false"
   ⚠️ KHÔNG dùng {{node#X.result_json.field}} — chỉ dùng các biến ở trên.
   ⚠️ Nếu thiếu data → hệ thống tự động bổ sung từ context, không cần lo.

7. INPUT_JSON cho các node SAU node#2:
   - Dùng key name trùng input schema của tool đích
   - Gán value từ biến output node trước: {{node#2.title}}, {{node#2.message}}, {{node#2.image_url}}...
   - Nếu không chắc field nào khớp, để trống "" — Agent sẽ tự map
   - VD node#3 post_facebook sau write_article:
     {"content": "{{node#2.message}}", "image_url": "{{node#2.image_url}}"}

8. Position: x bắt đầu 350, tăng 210 mỗi node, y=200.

9. task_title ngắn gọn mô tả workflow.

DANH SÁCH CÔNG CỤ AI CÓ SẴN (dùng cho tool_id):
{$tools_list}

10. TUYỆT ĐỐI KHÔNG dùng các tool điều phối workflow làm action step:
	- build_workflow
	- publish_workflow
	- list_workflows
	Các tool này chỉ dùng ở lớp điều phối chat, không phải bước thực thi trong pipeline.

11. Với mô tả kiểu "viết bài ... rồi tạo ảnh ... rồi đăng Facebook", ưu tiên chain chuẩn:
	- node#2: write_article (topic={{node#1.text}})
	- node#3: generate_image (message={{node#2.message}})
	- node#4: post_facebook (content={{node#2.message}}, image_url={{node#3.image_url}})

OUTPUT FORMAT — Trả về DUY NHẤT JSON, KHÔNG markdown:
{
  "task_title": "Tên ngắn gọn",
  "nodes": [
    {"id": "1", "type": "trigger", "position": {"x": 350, "y": 200}, "data": {"type": "trigger", "category": "{$trigger_info['category']}", "code": "{$trigger_info['code']}", "label": "{$trigger_info['label']}", "settings": {$trigger_settings_json}}},
    {"id": "2", "type": "action", "position": {"x": 560, "y": 200}, "data": {"type": "action", "category": "it", "code": "it_call_tool", "label": "🤖 Agent — <tên_công_cụ>", "settings": {"tool_id": "<tool_name>", "input_json": "{\"topic\": \"{{node#1.text}}\"}", "user_id_source": "trigger"}}}
  ],
  "edges": [
    {"id": "e1-2", "source": "1", "target": "2", "sourceHandle": "output-right", "targetHandle": "input-left", "type": "default"}
  ],
  "settings": {"timeout": 300, "multiple": 0, "skip": 0, "cooldown": 0, "stop": "yes"},
  "version": "1.0.0"
}
PROMPT;
	}

	/**
	 * Call LLM to generate workflow scenario JSON.
	 *
	 * @return array|null Parsed JSON or null on failure.
	 */
	private function call_llm_generate( $system_prompt, $user_description ) {
		$messages = array(
			array( 'role' => 'system', 'content' => $system_prompt ),
			array( 'role' => 'user',   'content' => "Tạo workflow cho: {$user_description}" ),
		);

		$result = null;

		// Strategy 1: Use bizcity_openrouter_chat helper
		if ( function_exists( 'bizcity_openrouter_chat' ) ) {
			$response = bizcity_openrouter_chat(
				$messages,
				array( 'temperature' => 0.3, 'max_tokens' => 4000 )
			);

			$raw = isset( $response['message'] ) ? $response['message'] : '';
			$result = $this->parse_json_from_llm( $raw );
		}

		// Strategy 2: Use WaicOpenrouterModel directly
		if ( ! $result && class_exists( 'WaicFrame' ) ) {
			$ai_model = WaicFrame::_()->getModule( 'workspace' )->getModel( 'openrouter' );
			if ( $ai_model && method_exists( $ai_model, 'init' ) ) {
				$ai_model->init();
				$body = array(
					'model'       => 'google/gemini-2.0-flash-001',
					'messages'    => $messages,
					'temperature' => 0.3,
					'max_tokens'  => 4000,
				);

				$response = wp_remote_post(
					$ai_model->getApiChatCompletionsUrl(),
					array(
						'timeout' => 120,
						'headers' => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $this->get_openrouter_api_key(),
						),
						'body' => wp_json_encode( $body ),
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
					$raw       = $resp_body['choices'][0]['message']['content'] ?? '';
					$result    = $this->parse_json_from_llm( $raw );
				}
			}
		}

		return $result;
	}

	/**
	 * Get OpenRouter API key from automation settings.
	 */
	private function get_openrouter_api_key() {
		if ( class_exists( 'WaicFrame' ) ) {
			return WaicFrame::_()->getModule( 'options' )->get( 'api', 'openrouter_api_key' );
		}
		return '';
	}

	/**
	 * Parse JSON from LLM response (handle markdown fences, extra text).
	 */
	private function parse_json_from_llm( $raw ) {
		if ( empty( $raw ) ) {
			return null;
		}

		// Strip markdown code fences
		$raw = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
		$raw = preg_replace( '/```\s*$/m', '', $raw );
		$raw = trim( $raw );

		// Try to extract JSON object from response
		if ( preg_match( '/\{[\s\S]*\}/u', $raw, $matches ) ) {
			$parsed = json_decode( $matches[0], true );
			if ( is_array( $parsed ) && ! empty( $parsed['nodes'] ) ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * Validate scenario structure: linear pipeline, all actions use it_call_tool.
	 */
	private function validate_scenario( $scenario, $description = '' ) {
		$nodes   = isset( $scenario['nodes'] ) ? $scenario['nodes'] : array();
		$invalid = array();

		if ( empty( $nodes ) ) {
			return array( 'valid' => false, 'error' => 'No nodes found' );
		}

		// Check first node is trigger
		$first = $nodes[0];
		if ( ! isset( $first['data']['type'] ) || $first['data']['type'] !== 'trigger' ) {
			$invalid[] = 'Node #1 must be a trigger';
		}

		// Check all action nodes use it_call_tool
		for ( $i = 1; $i < count( $nodes ); $i++ ) {
			$node = $nodes[ $i ];
			$code = isset( $node['data']['code'] ) ? $node['data']['code'] : '';
			if ( empty( $code ) ) {
				$invalid[] = "Node #{$node['id']}: missing code";
			} elseif ( $code !== 'it_call_tool' ) {
				$invalid[] = "Node #{$node['id']}: must use it_call_tool, got '{$code}'";
			}
			// Check tool_id is set
			$tool_id = isset( $node['data']['settings']['tool_id'] ) ? $node['data']['settings']['tool_id'] : '';
			if ( empty( $tool_id ) ) {
				$invalid[] = "Node #{$node['id']}: missing tool_id in settings";
			} elseif ( in_array( $tool_id, $this->orchestration_tools, true ) ) {
				$invalid[] = "Node #{$node['id']}: orchestration tool '{$tool_id}' is not allowed in action chain";
			}
		}

		if ( ! empty( $invalid ) ) {
			return array(
				'valid' => false,
				'error' => implode( '; ', $invalid ),
			);
		}

		// Ensure linear edges (fix if LLM made branching)
		$scenario = $this->enforce_linear_edges( $scenario );

		// Enforce deterministic ordering for common VN marketing chain.
		$scenario = $this->enforce_common_chain_pattern( $scenario, $description );

		return array( 'valid' => true, 'scenario' => $scenario );
	}

	/**
	 * Enforce expected order for common prompt pattern:
	 * write article -> generate image -> post Facebook.
	 */
	private function enforce_common_chain_pattern( $scenario, $description ) {
		$text = mb_strtolower( (string) $description, 'UTF-8' );
		$match = preg_match( '/viết\s*bài/u', $text )
			&& preg_match( '/(tạo\s*ảnh|minh\s*họa|image)/u', $text )
			&& preg_match( '/facebook/u', $text );

		if ( ! $match || empty( $scenario['nodes'] ) ) {
			return $scenario;
		}

		$trigger = $scenario['nodes'][0];
		$new_nodes = array( $trigger );

		$wanted = array( 'write_article', 'generate_image', 'post_facebook' );
		$existing_by_tool = array();
		for ( $i = 1; $i < count( $scenario['nodes'] ); $i++ ) {
			$n = $scenario['nodes'][ $i ];
			$t = $n['data']['settings']['tool_id'] ?? '';
			if ( $t ) {
				$existing_by_tool[ $t ] = $n;
			}
		}

		$x = 560;
		$idx = 2;
		foreach ( $wanted as $tool_id ) {
			$node = $existing_by_tool[ $tool_id ] ?? array(
				'id'       => (string) $idx,
				'type'     => 'action',
				'position' => array( 'x' => $x, 'y' => 200 ),
				'data'     => array(
					'type'     => 'action',
					'category' => 'it',
					'code'     => 'it_call_tool',
					'label'    => '🤖 Agent — ' . $tool_id,
					'settings' => array(
						'tool_id'        => $tool_id,
						'input_json'     => $this->default_input_json_for_tool( $tool_id, $idx ),
						'user_id_source' => 'trigger',
					),
				),
			);

			$node['id'] = (string) $idx;
			$node['position'] = array( 'x' => $x, 'y' => 200 );
			$node['type'] = 'action';
			$node['data']['type'] = 'action';
			$node['data']['category'] = 'it';
			$node['data']['code'] = 'it_call_tool';
			$node['data']['settings']['tool_id'] = $tool_id;
			$node['data']['settings']['input_json'] = $this->default_input_json_for_tool( $tool_id, $idx );
			$node['data']['settings']['user_id_source'] = 'trigger';

			$new_nodes[] = $node;
			$x += 210;
			$idx++;
		}

		$scenario['nodes'] = $new_nodes;
		$scenario['edges'] = $this->build_linear_edges( $new_nodes );

		if ( empty( $scenario['task_title'] ) ) {
			$scenario['task_title'] = 'Workflow: viết bài -> tạo ảnh -> đăng Facebook';
		}

		return $scenario;
	}

	/**
	 * Force edges into a linear chain: 1→2→3→4...
	 */
	private function enforce_linear_edges( $scenario ) {
		$nodes = $scenario['nodes'];
		$scenario['edges'] = $this->build_linear_edges( $nodes );
		return $scenario;
	}

	/**
	 * Save scenario as draft task in bizcity-automation.
	 *
	 * @return int|false Task ID or false on failure.
	 */
	private function save_as_draft_task( $scenario, $description, $trigger_filter ) {
		if ( ! class_exists( 'WaicFrame' ) ) {
			return false;
		}

		$task_title = isset( $scenario['task_title'] ) ? $scenario['task_title'] : $description;

		$params = array(
			'task_title' => $task_title,
			'nodes'      => isset( $scenario['nodes'] ) ? $scenario['nodes'] : array(),
			'edges'      => isset( $scenario['edges'] ) ? $scenario['edges'] : array(),
			'settings'   => isset( $scenario['settings'] ) ? $scenario['settings'] : array(
				'timeout'  => 300,
				'multiple' => 0,
				'skip'     => 0,
				'cooldown' => 0,
				'stop'     => 'yes',
			),
			'version'    => '1.0.0',
		);

		// Add metadata for filtering
		$params['_meta'] = array(
			'source'      => 'intent_ai',
			'description' => $description,
			'created_by'  => get_current_user_id(),
		);

		$task_model = WaicFrame::_()->getModule( 'workspace' )->getModel( 'tasks' );
		$task_id    = $task_model->saveTask( 'workflow', 0, $params );

		return $task_id ? (int) $task_id : false;
	}

	/**
	 * Build human-readable step summary from scenario JSON.
	 */
	private function build_step_summary( $scenario, $task_id, $edit_url ) {
		$nodes = isset( $scenario['nodes'] ) ? $scenario['nodes'] : array();
		$title = isset( $scenario['task_title'] ) ? $scenario['task_title'] : 'Workflow';

		$lines = array();
		$lines[] = "✅ **Kịch bản \"{$title}\"** đã được tạo (Draft #{$task_id})!\n";

		$step = 0;
		foreach ( $nodes as $node ) {
			$label = isset( $node['data']['label'] ) ? $node['data']['label'] : ( isset( $node['data']['code'] ) ? $node['data']['code'] : '?' );
			$type  = isset( $node['data']['type'] ) ? $node['data']['type'] : '';
			$code  = isset( $node['data']['code'] ) ? $node['data']['code'] : '';

			if ( $type === 'trigger' ) {
				$filter = isset( $node['data']['settings']['text_contains'] ) ? $node['data']['settings']['text_contains'] : '';
				$filter_note = $filter ? " — filter: \"{$filter}\"" : '';
				$lines[] = "⚡ **Trigger**: {$label}{$filter_note}";
			} else {
				$step++;
				$tool_id = isset( $node['data']['settings']['tool_id'] ) ? $node['data']['settings']['tool_id'] : '';
				$tool_note = $tool_id ? " → `{$tool_id}`" : '';
				$lines[] = "  {$step}. **{$label}**{$tool_note}";
			}
		}

		$lines[] = '';
		$lines[] = "📊 Tổng: " . count( $nodes ) . " nodes (" . ( count( $nodes ) - 1 ) . " bước)";
		$lines[] = "📝 Status: **Draft** — chưa publish";
		$lines[] = '';
		$lines[] = "👉 **Tiếp theo**:";
		$lines[] = "- Mở [Builder]({$edit_url}) để review và chỉnh sửa";
		$lines[] = "- Hoặc nói \"publish workflow #{$task_id}\" để publish ngay";

		return implode( "\n", $lines );
	}
}
