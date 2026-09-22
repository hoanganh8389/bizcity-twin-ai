<?php
/**
 * DDV probe for PHASE-0.48F U5 — CRM conversation label write/read round-trip.
 *
 * Reproduces the 2026-09-18 field report: `POST /conversations/{id}/labels` answered
 * `{"ok":true,"data":{"labels":[]}}` on both `/crm/` and `/gpt/crm/` because the join-table
 * write failed silently. The probe writes one label onto a real conversation, reads it back,
 * then restores the conversation's original label set — no new label and no new conversation.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Conversation_Labels_Write', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Conversation_Labels_Write implements BizCity_Diagnostics_Probe {

	/** @var int conversation touched by the last run(), for cleanup. */
	private $conversation_id = 0;

	/** @var array<int,int>|null label ids the conversation had before the probe. */
	private $original_label_ids = null;

	public function id(): string { return 'core.crm.conversation_labels_write'; }
	public function label(): string { return 'CRM conversation labels — write/read'; }
	public function description(): string { return 'Gán một nhãn có sẵn vào một hội thoại thật, đọc lại, rồi trả về trạng thái cũ. Phát hiện trường hợp API trả ok nhưng nhãn không được lưu.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 71; }
	public function icon(): string { return 'tag'; }
	public function estimate_ms(): int { return 220; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) || ! method_exists( 'BizCity_CRM_Repository', 'set_conversation_labels' ) ) {
			return new WP_Error( 'crm_repository_missing', 'CRM repository (label owner) chưa được nạp.' );
		}
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return new WP_Error( 'crm_installer_missing', 'CRM schema owner chưa được nạp.' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 ) {
			return new WP_Error( 'operator_missing', 'Cần một phiên đăng nhập để chạy probe.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		global $wpdb;
		$labels_tbl = BizCity_CRM_DB_Installer_V2::tbl_labels();
		$join_tbl   = BizCity_CRM_DB_Installer_V2::tbl_conversation_labels();
		$conv_tbl   = BizCity_CRM_DB_Installer_V2::tbl_conversations();

		// 1. Schema — the silent failure in the field was a missing/unwritable join table.
		$join_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $join_tbl ) ) === $join_tbl;
		$ctx->emit_step( array(
			'label'  => 'Schema — bảng nhãn hội thoại',
			'status' => $join_exists ? 'pass' : 'fail',
			'detail' => $join_exists ? $join_tbl . ' có trên shard này.' : $join_tbl . ' không tồn tại trên shard của site này.',
		) );
		if ( ! $join_exists ) {
			return array(
				'status'    => 'fail',
				'summary'   => 'Bảng nhãn hội thoại chưa có trên site này nên mọi thao tác gán nhãn đều mất im lặng.',
				'error'     => 'conversation_labels_table_missing',
				'fix_hint'  => 'Chạy Site Provisioner / Schema Registry cho CRM trên site này (R-DCL), không tạo bảng thủ công.',
			);
		}

		// 2. Fixtures — reuse an existing label and conversation; the probe never creates business rows.
		$label_id = (int) $wpdb->get_var( "SELECT id FROM `{$labels_tbl}` ORDER BY id ASC LIMIT 1" );
		$this->conversation_id = (int) $wpdb->get_var( "SELECT id FROM `{$conv_tbl}` ORDER BY id DESC LIMIT 1" );
		if ( $label_id <= 0 || $this->conversation_id <= 0 ) {
			$this->conversation_id = 0;
			return array(
				'status'   => 'precheck-fail',
				'summary'  => 'Site chưa có nhãn hoặc chưa có hội thoại nào để thử.',
				'error'    => 'label_or_conversation_missing',
				'fix_hint' => 'Tạo ít nhất một nhãn trong Settings → Labels và mở một hội thoại rồi chạy lại.',
			);
		}
		$this->original_label_ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT label_id FROM `{$join_tbl}` WHERE conversation_id = %d", $this->conversation_id ) ) );
		$ctx->emit_step( array(
			'label'  => 'Fixture — hội thoại và nhãn có sẵn',
			'status' => 'pass',
			'detail' => 'Hội thoại #' . $this->conversation_id . ', nhãn #' . $label_id . '. Nhãn hiện có: ' . ( $this->original_label_ids ? implode( ',', $this->original_label_ids ) : 'không có' ) . '.',
		) );

		// 3. Write + read back through the canonical owner (same path B2 and C both use).
		$desired = array_values( array_unique( array_merge( $this->original_label_ids, array( $label_id ) ) ) );
		$diff    = BizCity_CRM_Repository::set_conversation_labels( $this->conversation_id, $desired, (int) get_current_user_id() );
		if ( ! empty( $diff['failed'] ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Owner báo ghi nhãn thất bại (đã có lỗi trong PHP error log).',
				'error'    => 'label_write_failed',
				'fix_hint' => 'Xem PHP error log dòng "set_conversation_labels write failed" để biết lỗi MySQL cụ thể.',
			);
		}
		$read_back = array_map( static function ( $row ) { return (int) $row['id']; }, BizCity_CRM_Repository::get_conversation_labels( $this->conversation_id ) );
		$written   = in_array( $label_id, $read_back, true );
		$ctx->emit_step( array(
			'label'  => 'Ghi rồi đọc lại nhãn',
			'status' => $written ? 'pass' : 'fail',
			'detail' => $written ? 'Nhãn #' . $label_id . ' đã lưu và đọc lại được.' : 'API báo thành công nhưng đọc lại không thấy nhãn #' . $label_id . '.',
		) );
		if ( ! $written ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Gán nhãn báo thành công nhưng dữ liệu không được lưu — đúng triệu chứng 0.48F §5.6 U5.',
				'error'    => 'label_write_not_persisted',
				'fix_hint' => 'Kiểm quyền ghi của DB user lên ' . $join_tbl . ' và PHP error log; nếu bảng thuộc shard khác, xử lý theo R-MSDB/R-DCL.',
			);
		}

		// 4. Cached label list on the conversation must agree with the join table (the rail reads the cache).
		$cached_raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT cached_label_list FROM `{$conv_tbl}` WHERE id = %d", $this->conversation_id ) );
		$label_title = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM `{$labels_tbl}` WHERE id = %d", $label_id ) );
		$cache_ok = '' !== $label_title && false !== strpos( strtolower( $cached_raw ), strtolower( $label_title ) );
		$ctx->emit_step( array(
			'label'  => 'Cache nhãn trên hội thoại',
			'status' => $cache_ok ? 'pass' : 'fail',
			'detail' => $cache_ok ? 'cached_label_list khớp nhãn vừa gán.' : 'cached_label_list ("' . $cached_raw . '") chưa chứa "' . $label_title . '".',
		) );

		return array(
			'status'  => $cache_ok ? 'pass' : 'fail',
			'summary' => $cache_ok
				? 'Gán nhãn ghi và đọc lại đúng; cache nhãn trên hội thoại khớp.'
				: 'Nhãn đã lưu nhưng cached_label_list chưa đồng bộ — rail/queue sẽ hiện thiếu nhãn.',
			'error'    => $cache_ok ? '' : 'label_cache_out_of_sync',
			'fix_hint' => $cache_ok ? '' : 'Kiểm tra BizCity_CRM_Repository::resync_conversation_label_cache().',
		);
	}

	public function cleanup(): void {
		// Restore exactly the label set the conversation had before the probe ran.
		if ( $this->conversation_id > 0 && is_array( $this->original_label_ids ) && class_exists( 'BizCity_CRM_Repository' ) ) {
			BizCity_CRM_Repository::set_conversation_labels( $this->conversation_id, $this->original_label_ids, (int) get_current_user_id() );
		}
		$this->conversation_id = 0;
		$this->original_label_ids = null;
	}
}
