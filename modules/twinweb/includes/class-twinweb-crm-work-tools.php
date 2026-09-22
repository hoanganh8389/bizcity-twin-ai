<?php
/**
 * Twin GPT — member "work.*" retriever tools (PHASE-0.55 §5.4/§5.10 A2).
 *
 * Read-only tools that let Brain Chat `/gpt/` answer as the member's own
 * "pocket team-lead": today's queue, assigned tasks, personal space/goal and
 * a one-customer brief — always scoped to `BizCity_TwinWeb_Identity::current()`
 * (R-LEADER-MEMBER R-LM-1/-3/-5). No colleague data, no B2 endpoints.
 *
 * Each tool implements `BizCity_Twin_Tool` and is dispatched by
 * `BizCity_TwinBrain_Runtime::dispatch_tool()` like `create_doc`/`generate_image`
 * (see class-twinweb-agent-tool-adapters.php). Unlike those producer tools, a
 * work_* tool never returns `artifact`/`canvas_open`, so no panel opens — its
 * `summary` string is the only thing that reaches the Synthesizer/Final
 * Composer (`dispatched_tool_results()` in class-twinbrain-runtime.php only
 * forwards `summary` + `sources`, not the raw `result`), so `summary` must be
 * a complete, human-readable Vietnamese answer, not a data dump.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since PHASE-0.55 2026-09-19
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	$_bizcity_twinweb_work_tool_interface = defined( 'BIZCITY_TWIN_AI_DIR' )
		? BIZCITY_TWIN_AI_DIR . 'core/twin-core/includes/interface-twin-tool.php'
		: dirname( __DIR__, 3 ) . '/core/twin-core/includes/interface-twin-tool.php';
	if ( is_readable( $_bizcity_twinweb_work_tool_interface ) ) {
		require_once $_bizcity_twinweb_work_tool_interface;
	}
	unset( $_bizcity_twinweb_work_tool_interface );
}

if ( ! interface_exists( 'BizCity_Twin_Tool' ) ) {
	return;
}

if ( ! trait_exists( 'BizCity_TwinWeb_Work_Tool_Trait' ) ) {
	trait BizCity_TwinWeb_Work_Tool_Trait {

		public function tool_class(): string {
			return 'retriever';
		}

		/**
		 * Canonical member principal, or 0. Never trusts an LLM/context-supplied
		 * user id — same rule as `class-twinweb-crm-tasks-rest.php::member_id()`.
		 */
		private function member_id(): int {
			$identity = class_exists( 'BizCity_TwinWeb_Identity' ) ? BizCity_TwinWeb_Identity::current() : array();
			$user_id  = (int) ( $identity['user_id'] ?? 0 );
			return ( empty( $identity['is_guest'] ) && $user_id > 0 ) ? $user_id : 0;
		}

		private function auth_required_result(): array {
			return array(
				'ok'      => false,
				'error'   => 'auth_required',
				'summary' => 'Người này chưa đăng nhập Twin GPT nên không có việc/khách riêng để tra cứu.',
				'result'  => null,
			);
		}

		private function module_not_loaded_result(): array {
			return array(
				'ok'      => false,
				'error'   => 'module_not_loaded',
				'summary' => 'Module CRM chưa sẵn sàng nên chưa đọc được dữ liệu việc/khách.',
				'result'  => null,
			);
		}

		private function due_phrase( $due_at, bool $overdue ): string {
			if ( ! $due_at ) {
				return '';
			}
			$today = current_time( 'Y-m-d' );
			if ( $overdue ) {
				$days = (int) floor( ( strtotime( $today ) - strtotime( (string) $due_at ) ) / DAY_IN_SECONDS );
				return $days > 0 ? "quá hạn {$days} ngày" : 'quá hạn';
			}
			if ( $due_at === $today ) {
				return 'hạn hôm nay';
			}
			return 'hạn ' . date_i18n( 'd/m', strtotime( (string) $due_at ) );
		}

		private function normalize_text( string $text ): string {
			$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		}
	}
}

// ── work_my_today ───────────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_MyToday_Tool' ) ) {
	class BizCity_TwinWeb_Work_MyToday_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;

		public function name(): string {
			return 'work_my_today';
		}

		public function description(): string {
			return 'Đọc "Hôm nay" của chính nhân viên đang chat: khách quá hạn, khách đến hẹn hôm nay, việc trưởng nhóm vừa giao, khách mới chưa liên hệ. Dùng khi hỏi "hôm nay làm gì trước", "việc gì gấp", "còn khách nào chưa chăm".';
		}

		public function parameters_schema(): array {
			return array( 'type' => 'object', 'properties' => array(), 'required' => array() );
		}

		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			if ( $uid <= 0 ) {
				return $this->auth_required_result();
			}
			if ( ! class_exists( 'BizCity_TwinWeb_CRM_Pipeline_REST' ) || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
				return $this->module_not_loaded_result();
			}

			$groups = BizCity_TwinWeb_CRM_Pipeline_REST::today_groups( $uid );
			$n_overdue  = count( $groups['overdue'] );
			$n_today    = count( $groups['today'] );
			$n_assigned = count( $groups['assigned'] );
			$n_new      = count( $groups['new'] );

			if ( 0 === $n_overdue + $n_today + $n_assigned + $n_new ) {
				return array(
					'ok'      => true,
					'summary' => 'Hôm nay chưa có khách quá hạn, khách đến hẹn hay việc mới nào — có thể chủ động chăm khách đang ở giai đoạn xa hơn hoặc nghỉ ngơi.',
					'result'  => $groups,
					'sources' => array( array( 'type' => 'crm_pipeline_today', 'label' => 'Hôm nay của tôi' ) ),
				);
			}

			$lines   = array();
			$lines[] = sprintf(
				'Hôm nay: %d khách quá hạn, %d khách đến hẹn, %d việc trưởng nhóm vừa giao, %d khách mới chưa liên hệ.',
				$n_overdue,
				$n_today,
				$n_assigned,
				$n_new
			);

			$priority = array_merge(
				array_slice( $groups['overdue'], 0, 3 ),
				array_slice( $groups['today'], 0, 2 ),
				array_slice( $groups['assigned'], 0, 2 )
			);
			foreach ( array_slice( $priority, 0, 5 ) as $card ) {
				$stage_label = BizCity_CRM_Customer_Pipeline::LABELS[ (string) ( $card['stage'] ?? '' ) ] ?? (string) ( $card['stage'] ?? '' );
				$reason = array();
				if ( ! empty( $card['overdue_tasks'] ) ) {
					$reason[] = 'quá hạn ' . (int) $card['overdue_tasks'] . ' việc';
				}
				if ( ! empty( $card['task'] ) && is_array( $card['task'] ) ) {
					$reason[] = 'việc: ' . (string) ( $card['task']['title'] ?? '' );
				}
				if ( empty( $reason ) ) {
					$reason[] = 'đến hẹn hôm nay';
				}
				$lines[] = sprintf(
					'- %s (%s) — %s',
					(string) ( $card['display_name'] ?? 'Khách #' . ( $card['contact_id'] ?? 0 ) ),
					$stage_label,
					implode( '; ', $reason )
				);
			}
			$lines[] = 'Gợi ý: xử lý nhóm quá hạn trước, rồi đến khách hẹn hôm nay.';

			return array(
				'ok'      => true,
				'summary' => implode( "\n", $lines ),
				'result'  => $groups,
				'sources' => array( array( 'type' => 'crm_pipeline_today', 'label' => 'Hôm nay của tôi' ) ),
			);
		}
	}
}

// ── work_my_tasks ────────────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_MyTasks_Tool' ) ) {
	class BizCity_TwinWeb_Work_MyTasks_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;

		const STATUS_LABEL = array(
			'open'    => 'đang mở',
			'today'   => 'hạn hôm nay',
			'overdue' => 'quá hạn',
			'done'    => 'đã xong',
			'all'     => 'tất cả',
		);

		public function name(): string {
			return 'work_my_tasks';
		}

		public function description(): string {
			return 'Đọc việc trưởng nhóm đã giao cho chính nhân viên đang chat (mặc định: đang mở). Dùng khi hỏi "việc của tôi", "còn việc gì chưa xong", "việc nào quá hạn", "việc nào xong rồi".';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'status' => array( 'type' => 'string', 'enum' => array( 'open', 'today', 'overdue', 'done', 'all' ), 'default' => 'open' ),
				),
				'required'   => array(),
			);
		}

		private function infer_status( string $prompt ): string {
			$p = $this->normalize_text( $prompt );
			if ( false !== strpos( $p, 'qua han' ) || false !== strpos( $p, 'tre han' ) ) {
				return 'overdue';
			}
			if ( false !== strpos( $p, 'hom nay' ) ) {
				return 'today';
			}
			if ( false !== strpos( $p, 'xong' ) || false !== strpos( $p, 'hoan thanh' ) ) {
				return 'done';
			}
			if ( false !== strpos( $p, 'tat ca' ) || false !== strpos( $p, 'toan bo' ) ) {
				return 'all';
			}
			return 'open';
		}

		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			if ( $uid <= 0 ) {
				return $this->auth_required_result();
			}
			if ( ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
				return $this->module_not_loaded_result();
			}

			$status = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
			if ( ! isset( self::STATUS_LABEL[ $status ] ) ) {
				$status = $this->infer_status( (string) ( $args['prompt'] ?? '' ) );
			}

			$tasks = BizCity_CRM_Task_Handoff::list_for_member( $uid, $status, 20 );
			$label = self::STATUS_LABEL[ $status ];

			if ( empty( $tasks ) ) {
				return array(
					'ok'      => true,
					'summary' => "Không có việc nào ở trạng thái \"{$label}\".",
					'result'  => array( 'status' => $status, 'tasks' => array() ),
					'sources' => array( array( 'type' => 'crm_tasks', 'label' => 'Việc được giao (' . $label . ')' ) ),
				);
			}

			$lines   = array();
			$lines[] = sprintf( 'Việc %s (%d việc):', $label, count( $tasks ) );
			foreach ( array_slice( $tasks, 0, 8 ) as $task ) {
				$due = $this->due_phrase( $task['due_at'] ?? null, ! empty( $task['overdue'] ) );
				$subject = $task['subjects'][0]['display_name'] ?? '';
				$progress = $task['progress'] ?? array( 'touched' => 0, 'total' => 0 );
				$bits = array();
				if ( '' !== $due ) {
					$bits[] = $due;
				}
				if ( '' !== $subject ) {
					$bits[] = 'khách: ' . $subject;
				}
				if ( ( $progress['total'] ?? 0 ) > 0 ) {
					$bits[] = sprintf( 'đã chạm %d/%d', $progress['touched'], $progress['total'] );
				}
				$bits[] = 'giao bởi ' . (string) ( $task['assigned_by']['display_name'] ?? '' );
				$lines[] = sprintf( '- %s (%s)', (string) $task['title'], implode( '; ', array_filter( $bits ) ) );
			}

			return array(
				'ok'      => true,
				'summary' => implode( "\n", $lines ),
				'result'  => array( 'status' => $status, 'tasks' => $tasks ),
				'sources' => array( array( 'type' => 'crm_tasks', 'label' => 'Việc được giao (' . $label . ')' ) ),
			);
		}
	}
}

// ── work_my_space ────────────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_MySpace_Tool' ) ) {
	class BizCity_TwinWeb_Work_MySpace_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;

		public function name(): string {
			return 'work_my_space';
		}

		public function description(): string {
			return 'Đọc "Không gian của tôi": mục tiêu tháng do trưởng nhóm đặt, số khách đang chăm, khách kẹt/chưa có việc kế tiếp, việc quá hạn. Dùng khi hỏi "còn cách mục tiêu bao xa", "tháng này chốt được bao nhiêu", "khích lệ tôi đi".';
		}

		public function parameters_schema(): array {
			return array( 'type' => 'object', 'properties' => array(), 'required' => array() );
		}

		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			if ( $uid <= 0 ) {
				return $this->auth_required_result();
			}
			if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
				return $this->module_not_loaded_result();
			}

			$space = BizCity_CRM_Customer_Pipeline::space( $uid );
			$goal  = $space['goal'] ?? null;

			$lines = array();
			if ( is_array( $goal ) && ( $goal['won_target'] ?? 0 ) > 0 ) {
				$remain = max( 0, (int) $goal['won_target'] - (int) $space['won_month'] );
				$lines[] = sprintf(
					'Mục tiêu tháng %s (trưởng nhóm đặt): đã chốt %d/%d khách — còn %d khách.',
					(string) $goal['month'],
					(int) $space['won_month'],
					(int) $goal['won_target'],
					$remain
				);
			} else {
				$lines[] = sprintf( 'Chưa có mục tiêu tháng do trưởng nhóm đặt. Tháng này đã chốt %d khách.', (int) $space['won_month'] );
			}
			$lines[] = sprintf(
				'Đang chăm %d khách; %d khách bị kẹt giai đoạn quá lâu, %d khách chưa có việc kế tiếp.',
				(int) $space['customers'],
				(int) $space['stuck'],
				(int) $space['no_next']
			);
			$assigned = $space['assigned'] ?? array( 'open' => 0, 'overdue' => 0 );
			if ( ( $assigned['open'] ?? 0 ) > 0 ) {
				$lines[] = sprintf( 'Việc được giao: %d việc đang mở, %d việc quá hạn.', (int) $assigned['open'], (int) $assigned['overdue'] );
			}
			foreach ( array_slice( (array) ( $space['first'] ?? array() ), 0, 3 ) as $card ) {
				$stage_label = BizCity_CRM_Customer_Pipeline::LABELS[ (string) ( $card['stage'] ?? '' ) ] ?? (string) ( $card['stage'] ?? '' );
				$lines[] = sprintf(
					'- Ưu tiên: %s (%s), quá hạn %d việc.',
					(string) ( $card['display_name'] ?? '' ),
					$stage_label,
					(int) ( $card['overdue_tasks'] ?? 0 )
				);
			}

			return array(
				'ok'      => true,
				'summary' => implode( "\n", $lines ),
				'result'  => $space,
				'sources' => array( array( 'type' => 'crm_me_space', 'label' => 'Không gian của tôi' ) ),
			);
		}
	}
}

// ── work_contact_brief ───────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_ContactBrief_Tool' ) ) {
	class BizCity_TwinWeb_Work_ContactBrief_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;

		public function name(): string {
			return 'work_contact_brief';
		}

		public function description(): string {
			return 'Tra cứu tình trạng MỘT khách trong danh sách khách của chính nhân viên đang chat: giai đoạn, bước còn thiếu, việc quá hạn, lịch sử gần đây. Cần tên khách xuất hiện trong câu hỏi. Dùng khi hỏi "khách Nguyễn A đang ở đâu", "khách này cần làm gì tiếp".';
		}

		public function parameters_schema(): array {
			return array(
				'type'       => 'object',
				'properties' => array(
					'prompt' => array( 'type' => 'string', 'description' => 'Câu hỏi gốc, chứa tên khách cần tra.' ),
				),
				'required'   => array( 'prompt' ),
			);
		}

		/** Best-effort name match against the member's OWN scoped contacts — never an LLM-trusted id (R-LM-1). */
		private function resolve_contact_id( int $uid, string $prompt ): int {
			$needle = $this->normalize_text( $prompt );
			if ( '' === trim( $needle ) ) {
				return 0;
			}
			$ids  = BizCity_CRM_Customer_Pipeline::contact_ids_for_inboxes( BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) );
			$rows = BizCity_CRM_Customer_Pipeline::rows( $ids );
			$best_id    = 0;
			$best_score = 0;
			foreach ( $rows as $cid => $row ) {
				$name = trim( (string) ( $row['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}
				$name_norm = $this->normalize_text( $name );
				if ( '' === $name_norm || false === strpos( $needle, $name_norm ) ) {
					continue;
				}
				$score = mb_strlen( $name_norm );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best_id    = (int) $cid;
				}
			}
			return $best_id;
		}

		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			if ( $uid <= 0 ) {
				return $this->auth_required_result();
			}
			if ( ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
				return $this->module_not_loaded_result();
			}

			$prompt = (string) ( $args['prompt'] ?? '' );
			$contact_id = $this->resolve_contact_id( $uid, $prompt );
			if ( $contact_id <= 0 ) {
				return array(
					'ok'      => false,
					'error'   => 'contact_not_found',
					'summary' => 'Không khớp được tên khách nào trong danh sách khách của bạn — hỏi lại đúng tên khách hiện trong CRM.',
					'result'  => null,
				);
			}
			if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) ) ) {
				return array(
					'ok'      => false,
					'error'   => 'contact_not_in_scope',
					'summary' => 'Khách này không thuộc kênh của bạn.',
					'result'  => null,
				);
			}

			$detail = BizCity_CRM_Customer_Pipeline::detail( $contact_id, false );
			if ( ! $detail ) {
				return array(
					'ok'      => false,
					'error'   => 'contact_not_found',
					'summary' => 'Không tìm thấy khách này.',
					'result'  => null,
				);
			}

			$lines   = array();
			$lines[] = sprintf( '%s — giai đoạn %s (%d ngày).', (string) $detail['display_name'], (string) $detail['label'], (int) $detail['days'] );
			$todo = array_values( array_filter( (array) $detail['steps'], static function ( $s ) { return empty( $s['done'] ); } ) );
			if ( ! empty( $todo ) ) {
				$lines[] = 'Bước còn thiếu: ' . implode( '; ', array_map( static function ( $s ) { return (string) $s['label']; }, array_slice( $todo, 0, 3 ) ) ) . '.';
			}
			if ( ( $detail['next']['overdue'] ?? 0 ) > 0 ) {
				$lines[] = sprintf( 'Đang có %d việc quá hạn với khách này.', (int) $detail['next']['overdue'] );
			}
			if ( ! empty( $detail['stuck'] ) ) {
				$lines[] = 'Khách này đang bị kẹt ở giai đoạn quá lâu so với ngưỡng thông thường.';
			}
			foreach ( array_slice( (array) $detail['history'], 0, 2 ) as $h ) {
				$note = trim( (string) ( $h['note'] ?? '' ) );
				$lines[] = sprintf( '- %s: %s%s', (string) $h['at'], (string) $h['kind'], $note !== '' ? ' — ' . $note : '' );
			}

			return array(
				'ok'      => true,
				'summary' => implode( "\n", $lines ),
				'result'  => $detail,
				'sources' => array( array( 'type' => 'crm_contact_detail', 'label' => 'Chi tiết khách: ' . (string) $detail['display_name'] ) ),
			);
		}
	}
}

// [2026-09-19 Johnny Chu] PHASE-0.55-A3 — CRM action previews never write before member confirmation.
// ── A3 action previews ────────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_Action_Tool_Trait' ) ) {
	trait BizCity_TwinWeb_Work_Action_Tool_Trait {
		use BizCity_TwinWeb_Work_Tool_Trait;

		private function action_result( string $kind, array $card, string $summary ): array {
			return array(
				'ok' => true,
				'summary' => $summary,
				'result' => array( 'needs_confirm' => true, 'action_card' => $card ),
				'action_card' => $card,
				'sources' => array( array( 'type' => 'crm_action_preview', 'label' => 'Thao tác CRM cần xác nhận' ) ),
			);
		}
	}
}

if ( ! class_exists( 'BizCity_TwinWeb_Work_TaskGuide_Tool' ) ) {
	class BizCity_TwinWeb_Work_TaskGuide_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;
		public function name(): string { return 'work_task_guide'; }
		public function description(): string { return 'Đọc hướng dẫn hoàn thành một việc của chính nhân viên: instructions, playbook và bước còn thiếu của khách.'; }
		public function parameters_schema(): array { return array( 'type' => 'object', 'properties' => array( 'task_id' => array( 'type' => 'integer' ) ), 'required' => array( 'task_id' ) ); }
		public function execute( array $args, array $context ): array {
			$uid = $this->member_id(); $task_id = absint( $args['task_id'] ?? 0 );
			if ( $uid <= 0 ) return $this->auth_required_result();
			if ( ! $task_id || ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) return $this->module_not_loaded_result();
			$task = BizCity_CRM_Task_Handoff::shape_c( BizCity_CRM_Task_Handoff::get_row( $task_id ), $uid );
			if ( ! $task ) return array( 'ok' => false, 'error' => 'task_not_found', 'summary' => 'Không tìm thấy việc trong danh sách của bạn.' );
			$playbook = is_array( $task['playbook'] ?? null ) ? $task['playbook'] : null;
			$lines = array( 'Việc: ' . (string) $task['title'] . '.', 'Hướng dẫn: ' . (string) ( $task['instructions'] ?: 'Chưa có hướng dẫn riêng.' ) );
			if ( $playbook ) $lines[] = 'Playbook ' . (string) $playbook['id'] . ' — ' . (string) $playbook['label'] . ': ' . (string) $playbook['instructions'];
			$subject = $task['subjects'][0] ?? null;
			if ( $subject && ! empty( $subject['contact_id'] ) && class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
				$detail = BizCity_CRM_Customer_Pipeline::detail( (int) $subject['contact_id'], false );
				if ( $detail ) {
					$todo = array_values( array_filter( (array) $detail['steps'], static function ( $step ) { return empty( $step['done'] ); } ) );
					$lines[] = 'Khách ' . (string) $detail['display_name'] . ' đang ở ' . (string) $detail['label'] . '.';
					if ( $todo ) $lines[] = 'Bước còn thiếu: ' . implode( '; ', array_map( static function ( $step ) { return (string) $step['label']; }, array_slice( $todo, 0, 5 ) ) ) . '.';
				}
			}
			return array( 'ok' => true, 'summary' => implode( "\n", $lines ), 'result' => array( 'task' => $task, 'playbook' => $playbook ), 'sources' => array( array( 'type' => 'crm_task_guide', 'label' => 'Hướng dẫn việc #' . $task_id ) ) );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinWeb_Work_TaskTransition_Tool' ) ) {
	class BizCity_TwinWeb_Work_TaskTransition_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Action_Tool_Trait;

		public function name(): string { return 'work_task_transition'; }
		public function description(): string { return 'Chuẩn bị thẻ xác nhận hoàn thành hoặc trả lại một việc được giao. Không tự ghi.'; }
		public function parameters_schema(): array {
			return array( 'type' => 'object', 'properties' => array(
				'task_id' => array( 'type' => 'integer' ),
				'action' => array( 'type' => 'string', 'enum' => array( 'complete', 'return' ) ),
				'note' => array( 'type' => 'string' ),
			), 'required' => array( 'task_id', 'action' ) );
		}
		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			$task_id = absint( $args['task_id'] ?? 0 );
			$action = sanitize_key( (string) ( $args['action'] ?? '' ) );
			if ( $uid <= 0 ) return $this->auth_required_result();
			if ( ! $task_id || ! in_array( $action, array( 'complete', 'return' ), true ) || ! class_exists( 'BizCity_CRM_Task_Handoff' ) ) return $this->module_not_loaded_result();
			$task = BizCity_CRM_Task_Handoff::shape_c( BizCity_CRM_Task_Handoff::get_row( $task_id ), $uid );
			if ( ! $task ) return array( 'ok' => false, 'error' => 'task_not_found', 'summary' => 'Việc này không còn thuộc danh sách của bạn.' );
			$label = 'complete' === $action ? 'Xác nhận hoàn thành việc' : 'Trả lại việc';
			return $this->action_result( 'task_transition', array(
				'kind' => 'task_transition', 'action' => $action, 'task_id' => $task_id,
				'title' => $label, 'detail' => $task['title'], 'note' => sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ),
			), 'Đã chuẩn bị thao tác “' . $label . '”. Bạn cần bấm xác nhận trong sheet để ghi.' );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinWeb_Work_StageChange_Tool' ) ) {
	class BizCity_TwinWeb_Work_StageChange_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Action_Tool_Trait;
		public function name(): string { return 'work_stage_change'; }
		public function description(): string { return 'Chuẩn bị thẻ xác nhận đổi giai đoạn cho khách của nhân viên. Không tự ghi.'; }
		public function parameters_schema(): array {
			return array( 'type' => 'object', 'properties' => array(
				'contact_id' => array( 'type' => 'integer' ), 'to' => array( 'type' => 'string' ),
				'note' => array( 'type' => 'string' ), 'remind' => array( 'type' => 'boolean' ),
			), 'required' => array( 'contact_id', 'to' ) );
		}
		public function execute( array $args, array $context ): array {
			$uid = $this->member_id(); $contact_id = absint( $args['contact_id'] ?? 0 );
			$to = sanitize_key( (string) ( $args['to'] ?? '' ) );
			if ( $uid <= 0 ) return $this->auth_required_result();
			if ( ! $contact_id || ! $to || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) return $this->module_not_loaded_result();
			$inboxes = BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid );
			if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, $inboxes ) ) return array( 'ok' => false, 'error' => 'contact_not_in_scope', 'summary' => 'Khách này không thuộc kênh của bạn.' );
			$detail = BizCity_CRM_Customer_Pipeline::detail( $contact_id, false );
			if ( ! $detail ) return array( 'ok' => false, 'error' => 'contact_not_found', 'summary' => 'Không tìm thấy khách này.' );
			return $this->action_result( 'stage_change', array(
				'kind' => 'stage_change', 'contact_id' => $contact_id, 'to' => $to,
				'title' => 'Xem & xác nhận đổi giai đoạn', 'detail' => $detail['display_name'],
				'note' => sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ), 'remind' => ! empty( $args['remind'] ),
			), 'Đã chuẩn bị đổi giai đoạn cho ' . (string) $detail['display_name'] . '. Bạn cần bấm xác nhận trong sheet để ghi.' );
		}
	}
}

if ( ! class_exists( 'BizCity_TwinWeb_Work_DraftMessage_Tool' ) ) {
	class BizCity_TwinWeb_Work_DraftMessage_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Action_Tool_Trait;
		public function name(): string { return 'work_draft_message'; }
		public function description(): string { return 'Soạn nháp tin nhắn cho khách của nhân viên; không gửi tin.'; }
		public function parameters_schema(): array { return array( 'type' => 'object', 'properties' => array( 'contact_id' => array( 'type' => 'integer' ), 'draft' => array( 'type' => 'string' ), 'conversation_id' => array( 'type' => 'integer' ) ), 'required' => array( 'contact_id', 'draft' ) ); }
		public function execute( array $args, array $context ): array {
			$uid = $this->member_id(); $contact_id = absint( $args['contact_id'] ?? 0 ); $draft = trim( sanitize_textarea_field( (string) ( $args['draft'] ?? '' ) ) );
			if ( $uid <= 0 ) return $this->auth_required_result();
			if ( ! $contact_id || '' === $draft || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) return $this->module_not_loaded_result();
			if ( ! BizCity_CRM_Customer_Pipeline::contact_in_scope( $contact_id, BizCity_CRM_Customer_Pipeline::c_inbox_ids( $uid ) ) ) return array( 'ok' => false, 'error' => 'contact_not_in_scope', 'summary' => 'Khách này không thuộc kênh của bạn.' );
			return $this->action_result( 'draft_message', array( 'kind' => 'draft_message', 'contact_id' => $contact_id, 'conversation_id' => absint( $args['conversation_id'] ?? 0 ), 'title' => 'Tin nhắn nháp', 'detail' => $draft ), 'Đã soạn nháp. Bạn hãy kiểm tra, sao chép và tự gửi trong hội thoại; trợ lý không tự gửi tin.' );
		}
	}
}

// ── work_task_guide (A4) ─────────────────────────────────────────────────────

if ( ! class_exists( 'BizCity_TwinWeb_Work_TaskGuide_Tool' ) ) {
	class BizCity_TwinWeb_Work_TaskGuide_Tool implements BizCity_Twin_Tool {
		use BizCity_TwinWeb_Work_Tool_Trait;

		public function name(): string { return 'work_task_guide'; }
		public function description(): string { return 'Giải thích chi tiết một việc được giao: hướng dẫn, playbook, bước giai đoạn khách, gợi ý cách làm. Dùng khi hỏi "làm sao để...", "việc này cần gì", "hướng dẫn việc gọi lại khách".'; }
		public function parameters_schema(): array {
			return array(
				'type' => 'object',
				'properties' => array(
					'task_id' => array( 'type' => 'integer' ),
				),
				'required' => array( 'task_id' ),
			);
		}

		public function execute( array $args, array $context ): array {
			$uid = $this->member_id();
			$task_id = absint( $args['task_id'] ?? 0 );
			if ( $uid <= 0 ) return $this->auth_required_result();
			if ( ! $task_id || ! class_exists( 'BizCity_CRM_Task_Handoff' ) || ! class_exists( 'BizCity_CRM_Customer_Pipeline' ) ) {
				return $this->module_not_loaded_result();
			}
			$task = BizCity_CRM_Task_Handoff::shape_c( BizCity_CRM_Task_Handoff::get_row( $task_id ), $uid );
			if ( ! $task ) {
				return array( 'ok' => false, 'error' => 'task_not_found', 'summary' => 'Việc này không còn thuộc danh sách của bạn.' );
			}
			$lines = array();
			$lines[] = sprintf( '**Việc: %s** (%s)', (string) $task['title'], (string) $task['status'] );
			$lines[] = '📋 ' . (string) $task['instructions'];

			// Playbook details
			if ( ! empty( $task['playbook'] ) && is_array( $task['playbook'] ) ) {
				$pb = $task['playbook'];
				$lines[] = sprintf( '\n📋 **Kịch bản: %s** (%s)', (string) ( $pb['title'] ?? '' ), (string) ( $pb['label'] ?? '' ) );
				if ( ! empty( $pb['instructions'] ) ) {
					$lines[] = (string) $pb['instructions'];
				}
			}

			// Contact/subject details & stage steps
			$subjects = $task['subjects'] ?? array();
			if ( ! empty( $subjects[0] ) && is_array( $subjects[0] ) ) {
				$subject = $subjects[0];
				if ( ! empty( $subject['contact_id'] ) && is_array( $subject ) ) {
					$lines[] = sprintf( '\n👤 **Khách: %s**', (string) ( $subject['display_name'] ?? '' ) );
					$detail = BizCity_CRM_Customer_Pipeline::detail( (int) $subject['contact_id'], false );
					if ( $detail && ! empty( $detail['steps'] ) ) {
						$lines[] = sprintf( 'Giai đoạn: %s', (string) ( $detail['label'] ?? '' ) );
						$todo = array_values( array_filter( (array) $detail['steps'], static function ( $s ) { return empty( $s['done'] ); } ) );
						if ( ! empty( $todo ) ) {
							$lines[] = '📝 Bước còn thiếu:';
							foreach ( array_slice( $todo, 0, 5 ) as $step ) {
								$lines[] = sprintf( '  - %s', (string) $step['label'] );
							}
						}
					}
				}
			}

			$lines[] = '\n💡 **Gợi ý**: thực hiện đúng các bước trên, ghi chú kết quả khi xong, rồi hoàn thành việc để trưởng nhóm xem xét.';

			return array(
				'ok' => true,
				'summary' => implode( "\n", $lines ),
				'result' => $task,
				'sources' => array( array( 'type' => 'crm_task_guide', 'label' => 'Hướng dẫn việc: ' . (string) $task['title'] ) ),
			);
		}
	}
}

// ── Registration ─────────────────────────────────────────────────────────────

add_filter( 'bizcity_twin_register_tool', static function ( $registry ) {
	if ( ! is_array( $registry ) ) {
		$registry = array();
	}
	$registry['work_my_today']       = new BizCity_TwinWeb_Work_MyToday_Tool();
	$registry['work_my_tasks']       = new BizCity_TwinWeb_Work_MyTasks_Tool();
	$registry['work_my_space']       = new BizCity_TwinWeb_Work_MySpace_Tool();
	$registry['work_contact_brief']  = new BizCity_TwinWeb_Work_ContactBrief_Tool();
	$registry['work_task_transition'] = new BizCity_TwinWeb_Work_TaskTransition_Tool();
	$registry['work_stage_change'] = new BizCity_TwinWeb_Work_StageChange_Tool();
	$registry['work_draft_message'] = new BizCity_TwinWeb_Work_DraftMessage_Tool();
	$registry['work_task_guide'] = new BizCity_TwinWeb_Work_TaskGuide_Tool();
	return $registry;
} );

/**
 * Catalog metadata drives the keyword intent matcher
 * (`BizCity_TwinWeb_Agent_Tool_Catalog::match_prompt()`) that picks the tool
 * for a turn — see `class-twinweb-agent-tool-catalog.php:48`.
 */
add_filter( 'bizcity_twinweb_agent_tool_catalog', static function ( $tools, $ctx ) {
	if ( ! is_array( $tools ) ) {
		$tools = array();
	}
	$common = array(
		'tool_class'     => 'retriever',
		'execution'      => 'sync_preview',
		'plan_min'       => 'free',
		'capability'     => 'crm_work_read',
		'needs_approval' => false,
	);
	$tools['work_my_today'] = array_merge( $common, array(
		'label'             => 'Hôm nay của tôi',
		'description'       => 'Khách quá hạn, đến hẹn hôm nay, việc vừa được giao, khách mới.',
		'artifact_type'     => '',
		'intent_keywords'   => array( 'hôm nay làm gì', 'hôm nay có gì', 'việc gì trước', 'việc gì gấp', 'khách nào cần chăm', 'quá hạn' ),
		'parameters_schema' => array( 'type' => 'object', 'properties' => array(), 'required' => array() ),
	) );
	$tools['work_my_tasks'] = array_merge( $common, array(
		'label'             => 'Việc của tôi',
		'description'       => 'Việc trưởng nhóm đã giao cho chính người đang chat.',
		'artifact_type'     => '',
		'intent_keywords'   => array( 'việc của tôi', 'việc được giao', 'còn việc gì', 'việc nào xong', 'kiểm tra tiến độ việc', 'nhiệm vụ của tôi' ),
		'parameters_schema' => array(
			'type'       => 'object',
			'properties' => array( 'status' => array( 'type' => 'string', 'enum' => array( 'open', 'today', 'overdue', 'done', 'all' ) ) ),
			'required'   => array(),
		),
	) );
	$tools['work_my_space'] = array_merge( $common, array(
		'label'             => 'Không gian của tôi',
		'description'       => 'Mục tiêu tháng, tiến độ, khách kẹt/chưa có việc kế tiếp.',
		'artifact_type'     => '',
		'intent_keywords'   => array( 'mục tiêu tháng', 'còn cách mục tiêu', 'chốt được bao nhiêu', 'khích lệ tôi', 'không gian của tôi', 'tôi làm được bao nhiêu' ),
		'parameters_schema' => array( 'type' => 'object', 'properties' => array(), 'required' => array() ),
	) );
	$tools['work_contact_brief'] = array_merge( $common, array(
		'label'             => 'Chi tiết khách',
		'description'       => 'Giai đoạn, bước còn thiếu, lịch sử gần đây của một khách trong danh sách của tôi.',
		'artifact_type'     => '',
		'intent_keywords'   => array( 'khách này đang', 'khách tên', 'cần làm gì tiếp', 'đang ở giai đoạn nào', 'khách này cần' ),
		'parameters_schema' => array(
			'type'       => 'object',
			'properties' => array( 'prompt' => array( 'type' => 'string' ) ),
			'required'   => array( 'prompt' ),
		),
	) );
	foreach ( array(
		'work_task_transition' => array( 'label' => 'Xác nhận việc', 'description' => 'Mở sheet xác nhận hoàn thành hoặc trả lại việc.', 'intent_keywords' => array( 'hoàn thành việc', 'trả lại việc', 'xác nhận việc' ) ),
		'work_stage_change' => array( 'label' => 'Đổi giai đoạn', 'description' => 'Mở sheet xác nhận đổi giai đoạn khách.', 'intent_keywords' => array( 'đổi giai đoạn', 'chuyển giai đoạn' ) ),
		'work_draft_message' => array( 'label' => 'Soạn tin nháp', 'description' => 'Soạn tin nhắn nhưng không tự gửi.', 'intent_keywords' => array( 'soạn tin', 'viết tin nhắn', 'nhắn cho khách' ) ),
		'work_task_guide' => array( 'label' => 'Hướng dẫn việc', 'description' => 'Hướng dẫn theo instructions, playbook và bước còn thiếu của khách.', 'intent_keywords' => array( 'giải thích việc', 'cách làm việc', 'hướng dẫn hoàn thành', 'làm việc này thế nào' ) ),
	) as $slug => $meta ) {
		$schemas = array(
			'work_task_transition' => array( 'type' => 'object', 'properties' => array( 'task_id' => array( 'type' => 'integer' ), 'action' => array( 'type' => 'string', 'enum' => array( 'complete', 'return' ) ), 'note' => array( 'type' => 'string' ) ), 'required' => array( 'task_id', 'action' ) ),
			'work_stage_change' => array( 'type' => 'object', 'properties' => array( 'contact_id' => array( 'type' => 'integer' ), 'to' => array( 'type' => 'string' ), 'note' => array( 'type' => 'string' ), 'remind' => array( 'type' => 'boolean' ) ), 'required' => array( 'contact_id', 'to' ) ),
			'work_draft_message' => array( 'type' => 'object', 'properties' => array( 'contact_id' => array( 'type' => 'integer' ), 'conversation_id' => array( 'type' => 'integer' ), 'draft' => array( 'type' => 'string' ) ), 'required' => array( 'contact_id', 'draft' ) ),
			'work_task_guide' => array( 'type' => 'object', 'properties' => array( 'task_id' => array( 'type' => 'integer' ) ), 'required' => array( 'task_id' ) ),
		);
		$tools[ $slug ] = array_merge( $common, $meta, array( 'artifact_type' => '', 'parameters_schema' => $schemas[ $slug ] ) );
	}
	return $tools;
}, 10, 2 );
