<?php
/**
 * BizCity Diagnostics — core.automation.runtime_errors probe
 *
 * [2026-06-08 Johnny Chu] PHASE-0.43 R-ERROR-UX + R-DDV — Runtime Error Report
 *
 * Đọc các lỗi đã xảy ra khi automation vận hành thực tế (last 24h), map sang
 * canonical ERROR-UX catalog, và surface trong health dashboard.
 *
 * KHÔNG tạo data / không side-effect — read-only probe.
 *
 * Spec đầy đủ: core/automation/docs/AUTOMATION-RUNTIME-ERRORS.md
 *
 * 6 assertions:
 *
 *   recent.fails_24h     — COUNT(status=FAIL) trong 24h gần nhất (INFO nếu > 0)
 *   recent.by_reason.*   — Một step WARN per reason bucket có fail (grouped)
 *   recent.stuck_queued  — Runs stuck ở status=QUEUED > 10 phút (WARN nếu có)
 *   cron.dispatch_meta   — Cron dispatcher đã ghi meta run trong 24h (INFO)
 *   cron.event_failures  — automation_run_failed events trong cron meta (WARN)
 *   crm.bridge_orphan    — Runs STATUS_OK không có crm_event_id (INFO)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-06-08
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-06-08 Johnny Chu] HOTFIX — guard double-load để tránh fatal redeclare trên production.
if ( class_exists( 'BizCity_Probe_Automation_Runtime_Errors', false ) ) {
	return;
}

final class BizCity_Probe_Automation_Runtime_Errors implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.automation.runtime_errors'; }
	public function label(): string       { return 'Automation · Runtime Error Report (24h)'; }
	public function description(): string {
		return 'Đọc bizcity_automation_runs + bizcity_cron_runs trong 24h gần nhất, '
		       . 'phân loại lỗi theo reason bucket, map sang ERROR-UX catalog, '
		       . 'surface run FAIL / stuck / cron event failures trong health dashboard.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 40; }
	public function icon(): string        { return 'warning'; }
	public function estimate_ms(): int    { return 600; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Automation_Repo_Runs chưa load. Chạy probe core.automation trước.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;

		$results = array();

		$tbl_runs = BizCity_Automation_Repo_Runs::table_runs();
		$since_24h = gmdate( 'Y-m-d H:i:s', time() - 86400 );

		// Guard: bảng tồn tại không?
		if ( ! bizcity_tbl_exists( $tbl_runs ) ) { // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$results[] = array(
				'id'     => 'recent.fails_24h',
				'label'  => 'Bảng bizcity_automation_runs chưa tồn tại',
				'status' => 'warn',
				'detail' => 'Bảng chưa được tạo. Chạy probe core.automation để auto-create.',
			);
			return $results;
		}

		// ── Step 1: recent.fails_24h ─────────────────────────────────────────
		$fail_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_runs}
			 WHERE status = %d AND created_at >= %s",
			BizCity_Automation_Repo_Runs::STATUS_FAIL,
			$since_24h
		) );

		$results[] = array(
			'id'     => 'recent.fails_24h',
			'label'  => 'Fails 24h · tổng số run STATUS_FAIL',
			'status' => $fail_count === 0 ? 'pass' : 'info',
			'detail' => $fail_count === 0
				? 'Không có run nào thất bại trong 24h — tốt.'
				: sprintf( '%d run thất bại trong 24h gần nhất.', $fail_count ),
		);

		// ── Step 2: recent.by_reason.* (grouped per reason bucket) ───────────
		$fail_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT error, result_json, created_at, workflow_id
			 FROM {$tbl_runs}
			 WHERE status = %d AND created_at >= %s
			 ORDER BY created_at DESC
			 LIMIT 200",
			BizCity_Automation_Repo_Runs::STATUS_FAIL,
			$since_24h
		), ARRAY_A );

		$buckets = array(); // reason_bucket => [ count, last_at, last_error, workflow_ids[] ]
		foreach ( (array) $fail_rows as $row ) {
			$reason = 'block_error'; // default
			if ( ! empty( $row['result_json'] ) ) {
				$result = json_decode( $row['result_json'], true );
				if ( is_array( $result ) && ! empty( $result['reason'] ) ) {
					$reason = (string) $result['reason'];
				}
			}
			if ( ! isset( $buckets[ $reason ] ) ) {
				$buckets[ $reason ] = array( 'count' => 0, 'last_at' => '', 'last_error' => '', 'workflow_ids' => array() );
			}
			$buckets[ $reason ]['count']++;
			if ( $row['created_at'] > $buckets[ $reason ]['last_at'] ) {
				$buckets[ $reason ]['last_at']    = $row['created_at'];
				$buckets[ $reason ]['last_error'] = substr( (string) $row['error'], 0, 150 );
			}
			$wf_id = (int) $row['workflow_id'];
			if ( $wf_id > 0 && ! in_array( $wf_id, $buckets[ $reason ]['workflow_ids'], true ) ) {
				$buckets[ $reason ]['workflow_ids'][] = $wf_id;
			}
		}

		$catalog = self::error_catalog();

		if ( empty( $buckets ) ) {
			$results[] = array(
				'id'     => 'recent.by_reason',
				'label'  => 'Fails 24h · không có reason bucket nào',
				'status' => 'pass',
				'detail' => 'Tất cả runs đều thành công trong 24h.',
			);
		} else {
			foreach ( $buckets as $reason => $info ) {
				$entry   = isset( $catalog[ $reason ] ) ? $catalog[ $reason ] : $catalog['block_error'];
				$wf_list = implode( ', ', array_slice( $info['workflow_ids'], 0, 5 ) );
				$results[] = array(
					'id'       => 'recent.by_reason.' . $reason,
					'label'    => sprintf( 'Run Error · %s (%dx)', $reason, $info['count'] ),
					'status'   => 'warn',
					'detail'   => sprintf(
						'[%s] code=%s — %s | hint: %s | last_at=%s | workflows=[%s]',
						$entry['code'],
						$reason,
						$entry['message'],
						$entry['hint'],
						$info['last_at'],
						$wf_list
					),
					// Structured fields for UI consumption.
					'error_payload' => array(
						'code'      => $entry['code'],
						'message'   => $entry['message'],
						'hint'      => $entry['hint'],
						'help_code' => $entry['help_code'],
						'context'   => array(
							'reason'      => $reason,
							'count_24h'   => $info['count'],
							'last_at'     => $info['last_at'],
							'last_error'  => $info['last_error'],
							'workflow_ids' => $info['workflow_ids'],
						),
					),
				);
			}
		}

		// ── Step 3: recent.stuck_queued ──────────────────────────────────────
		// Runs queued > 10 minutes are likely stuck (cron not running or dispatcher bug).
		$stuck_since = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$stuck_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_runs}
			 WHERE status = %d AND created_at <= %s",
			BizCity_Automation_Repo_Runs::STATUS_QUEUED,
			$stuck_since
		) );

		$results[] = array(
			'id'     => 'recent.stuck_queued',
			'label'  => 'Runs · không có run bị kẹt STATUS_QUEUED > 10 phút',
			'status' => $stuck_count === 0 ? 'pass' : 'warn',
			'detail' => $stuck_count === 0
				? 'Không có run stuck — cron dispatcher đang hoạt động bình thường.'
				: sprintf(
					'%d run bị kẹt ở STATUS_QUEUED hơn 10 phút. '
					. 'Kiểm tra cron `bizcity_automation_cron_dispatch` có đang chạy không '
					. '(Tools → Scheduled Events hoặc BizCity Cron).',
					$stuck_count
				),
		);

		// ── Step 4: cron.dispatch_meta ────────────────────────────────────────
		// Check if bizcity_automation_cron_dispatch left a cron_runs row in last 24h.
		$cron_dispatch_found = false;
		$cron_meta_detail    = 'bizcity_cron_runs table không load được — BizCity_Cron_Manager không sẵn sàng.';

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$tbl_cron = $wpdb->prefix . 'bizcity_cron_runs';
			$tbl_exists = bizcity_tbl_exists( $tbl_cron ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			if ( $tbl_exists ) {
				$cron_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, started_at, meta FROM {$tbl_cron}
					 WHERE job_id = %s AND started_at >= %s
					 ORDER BY id DESC LIMIT 1",
					BizCity_Automation_Runner::CRON_HOOK,
					$since_24h
				), ARRAY_A );
				$cron_dispatch_found = ! empty( $cron_row );
				$cron_meta_detail = $cron_dispatch_found
					? sprintf( 'Cron run #%d tìm thấy (started_at=%s).', $cron_row['id'], $cron_row['started_at'] )
					: 'Không tìm thấy cron run cho bizcity_automation_cron_dispatch trong 24h. Kiểm tra WP-Cron.';
			} else {
				$cron_meta_detail = 'bizcity_cron_runs table chưa tạo.';
			}
		}

		$results[] = array(
			'id'     => 'cron.dispatch_meta',
			'label'  => 'Cron · bizcity_automation_cron_dispatch có meta run trong 24h',
			'status' => $cron_dispatch_found ? 'pass' : 'info',
			'detail' => $cron_meta_detail,
		);

		// ── Step 5: cron.event_failures ───────────────────────────────────────
		// Parse cron meta events[] for automation_run_failed entries.
		$cron_event_fails = 0;
		$cron_fail_detail = 'Không thể đọc cron meta.';

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$tbl_cron = $wpdb->prefix . 'bizcity_cron_runs';
			$tbl_exists2 = bizcity_tbl_exists( $tbl_cron ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			if ( $tbl_exists2 ) {
				// Grab last 10 cron runs for the automation dispatch hook.
				$cron_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT meta FROM {$tbl_cron}
					 WHERE job_id = %s AND started_at >= %s
					 ORDER BY id DESC LIMIT 10",
					BizCity_Automation_Runner::CRON_HOOK,
					$since_24h
				), ARRAY_A );

				foreach ( (array) $cron_rows as $cr ) {
					$meta = json_decode( (string) $cr['meta'], true );
					if ( ! is_array( $meta ) || empty( $meta['events'] ) ) { continue; }
					foreach ( (array) $meta['events'] as $ev ) {
						if ( isset( $ev['name'] ) && $ev['name'] === 'automation_run_failed' ) {
							$cron_event_fails++;
						}
					}
				}
				$cron_fail_detail = $cron_event_fails === 0
					? 'Không có automation_run_failed event trong cron meta 24h gần nhất.'
					: sprintf( '%d automation_run_failed event trong cron meta 24h. Xem Tools → BizCity Cron để phân tích.', $cron_event_fails );
			}
		}

		$results[] = array(
			'id'     => 'cron.event_failures',
			'label'  => 'Cron Meta · automation_run_failed events trong 24h',
			'status' => $cron_event_fails === 0 ? 'pass' : 'warn',
			'detail' => $cron_fail_detail,
		);

		// ── Step 6: crm.bridge_orphan ─────────────────────────────────────────
		// Count STATUS_OK runs in last 24h missing crm_event_id (bridge didn't fire).
		$orphan_count = 0;
		$orphan_detail = 'Không thể đọc — cột crm_event_id chưa có.';

		$has_crm_col = $wpdb->get_var(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = '{$tbl_runs}'
			   AND COLUMN_NAME  = 'crm_event_id'"
		);
		if ( (int) $has_crm_col > 0 ) {
			$orphan_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl_runs}
				 WHERE status = %d
				   AND created_at >= %s
				   AND (crm_event_id IS NULL OR crm_event_id = 0)",
				BizCity_Automation_Repo_Runs::STATUS_OK,
				$since_24h
			) );
			$orphan_detail = $orphan_count === 0
				? 'Tất cả runs OK có crm_event_id — CRM Bridge hoạt động bình thường.'
				: sprintf(
					'%d run STATUS_OK thiếu crm_event_id trong 24h. '
					. 'CRM Bridge không tạo được event → Scheduler Notifier không reply kênh.',
					$orphan_count
				);
		}

		$results[] = array(
			'id'     => 'crm.bridge_orphan',
			'label'  => 'CRM Bridge · runs OK có crm_event_id (inbound forwarded)',
			'status' => $orphan_count === 0 ? 'pass' : 'info',
			'detail' => $orphan_detail,
		);

		return $results;
	}

	// [2026-06-08 Johnny Chu] HOTFIX — interface BizCity_Diagnostics_Probe yêu cầu cleanup().
	// Probe này read-only, không tạo artifact nên cleanup là no-op.
	public function cleanup(): void {
		// no-op
	}

	// ─── Error Code Catalog ───────────────────────────────────────────────────
	//
	// Map reason_bucket → canonical ERROR-UX payload.
	// Spec nguồn: core/automation/docs/AUTOMATION-RUNTIME-ERRORS.md §6.2
	// Code catalog: core/helper/docs/ERROR-UX-SPEC.md §4.6
	//
	// Khi thêm block/surface mới emit WP_Error với code mới:
	//   1. Thêm row vào AUTOMATION-RUNTIME-ERRORS.md §3.
	//   2. Thêm entry ở đây.
	//   3. Thêm help_code entry vào HELP_CATALOG (FE).
	//   4. Thêm vào ERROR-UX-SPEC.md §4.6.
	//
	private static function error_catalog(): array {
		return array(
			'block_error'           => array(
				'code'      => 'automation_block_error',
				'message'   => 'Automation block ném exception khi thực thi.',
				'hint'      => 'Mở Automation → Lịch sử → chọn run → xem step lỗi màu đỏ.',
				'help_code' => 'automation_block_error',
			),
			'block_timeout'         => array(
				'code'      => 'automation_block_timeout',
				'message'   => 'Automation block chạy quá thời gian cho phép.',
				'hint'      => 'Rút ngắn timeout trong block config, hoặc tối ưu API downstream.',
				'help_code' => 'automation_block_error',
			),
			'validation_failed'     => array(
				'code'      => 'automation_graph_invalid',
				'message'   => 'Workflow graph không hợp lệ (có cycle hoặc block lạc).',
				'hint'      => 'Mở editor workflow → kiểm tra không có cạnh tạo vòng tròn.',
				'help_code' => 'automation_graph_invalid',
			),
			'unknown_block'         => array(
				'code'      => 'module_not_loaded',
				'message'   => 'Block chưa đăng ký trong registry khi chạy workflow.',
				'hint'      => 'Kiểm tra plugin chứa block đã activate; xem PHP error log.',
				'help_code' => 'module_not_loaded',
			),
			'workflow_missing'      => array(
				'code'      => 'workflow_not_found',
				'message'   => 'Workflow bị xóa trong khi run đang chờ thực thi.',
				'hint'      => 'Xóa các run queued cũ hoặc khôi phục workflow từ backup.',
				'help_code' => 'workflow_not_found',
			),
			'http_error'            => array(
				'code'      => 'automation_http_error',
				'message'   => 'Action HTTP Request nhận phản hồi lỗi từ server đích.',
				'hint'      => 'Kiểm tra URL và credentials trong cấu hình block. Xem detail step trong Lịch sử.',
				'help_code' => 'automation_block_error',
			),
			'invalid_param'         => array(
				'code'      => 'invalid_param',
				'message'   => 'Tham số không hợp lệ trong cấu hình block khi thực thi.',
				'hint'      => 'Mở workflow editor → kiểm tra cấu hình block bị lỗi → lưu lại.',
				'help_code' => 'invalid_param_generic',
			),
			'provider_unavailable'  => array(
				'code'      => 'llm_error',
				'message'   => 'LLM Gateway không phản hồi khi automation chạy.',
				'hint'      => 'Kiểm tra API key BizCity và trạng thái gateway. Thử lại sau vài phút.',
				'help_code' => 'gateway_degraded',
			),
			'enqueue_failed'        => array(
				'code'      => 'automation_enqueue_failed',
				'message'   => 'Không thể tạo run mới vào hàng đợi (DB insert lỗi).',
				'hint'      => 'Kiểm tra bảng bizcity_automation_runs; xem PHP error log.',
				'help_code' => 'automation_run_failed',
			),
			'tables_missing'        => array(
				'code'      => 'module_not_loaded',
				'message'   => 'Bảng DB automation không tồn tại trên site này.',
				'hint'      => 'Vào Diagnostics → Schema Inventory → tạo bảng thiếu.',
				'help_code' => 'module_not_loaded',
			),
			'cron_schedule_missing' => array(
				'code'      => 'cron_failed',
				'message'   => 'Interval every_minute chưa đăng ký — cron broadcast không chạy được.',
				'hint'      => 'Cập nhật plugin lên phiên bản mới nhất (BUG-1 đã vá 2026-06-08).',
				'help_code' => 'cron_meta_viewer',
			),
		);
	}
}
