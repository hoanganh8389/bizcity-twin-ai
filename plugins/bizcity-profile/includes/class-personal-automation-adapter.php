<?php
/**
 * BizCity Personal — Automation Adapter
 *
 * Đăng ký 3 action blocks của plugin bizcity-personal vào
 * core/automation block registry qua hook `bizcity_automation_register_blocks`.
 *
 * Đây là ĐIỂM KẾT NỐI DUY NHẤT giữa bizcity-personal và core/automation.
 * bizcity-personal KHÔNG được hook channel trực tiếp (W2 listener đã bị
 * loại bỏ theo ARCHITECTURE.md §6).
 *
 * Blocks được đăng ký:
 *   - action.personal_create_task    → tạo task vào bizcity_crm_events
 *   - action.personal_save_finance   → ghi thu/chi vào bizcity_personal_finance_entries
 *   - action.personal_save_journal   → upsert nhật ký vào bizcity_personal_journal
 *   - action.personal_save_note      → lưu ghi chú vào bizcity_personal_notebook_pages + .md file
 *
 * Load order:
 *   File này được require_once trong bootstrap.php.
 *   Block classes được require_once BÊN TRONG callback hook (lazy) — chỉ
 *   khi BizCity_Automation_Block_Registry::bootstrap() thực sự fire
 *   (tức là khi core/automation đã load). Điều này tuân thủ R-PERF.
 *
 * Phương thức: chỉ có 1 hàm static init() → 1 add_action() call.
 *
 * Architecture: PHASE-HOME-ARCH §3 + §8
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since      2026-06-24 (PHASE-HOME-ARCH v1.0)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Personal_Automation_Adapter {

	/**
	 * Register action blocks via automation hook.
	 *
	 * Call once from bootstrap.php at file-load time (outside any hook).
	 *
	 * @return void
	 */
	public static function init() {
		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — register personal blocks into automation registry.
		// Priority 20: after core blocks (priority default 10) so personal blocks appear last in UI.
		add_action( 'bizcity_automation_register_blocks', array( __CLASS__, 'register_blocks' ), 20 );
	}

	/**
	 * @param BizCity_Automation_Block_Registry $registry
	 * @return void
	 */
	public static function register_blocks( $registry ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — lazy-require block files on first call.
		$blocks_dir = BIZCITY_PERSONAL_DIR . 'includes/blocks/';

		require_once $blocks_dir . 'class-action-personal-create-task.php';
		require_once $blocks_dir . 'class-action-personal-save-finance.php';
		require_once $blocks_dir . 'class-action-personal-save-journal.php';
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — save_note block
		require_once $blocks_dir . 'class-action-personal-save-note.php';

		if ( class_exists( 'BizCity_Personal_Action_Create_Task' ) ) {
			$registry->register( new BizCity_Personal_Action_Create_Task() );
		}
		if ( class_exists( 'BizCity_Personal_Action_Save_Finance' ) ) {
			$registry->register( new BizCity_Personal_Action_Save_Finance() );
		}
		if ( class_exists( 'BizCity_Personal_Action_Save_Journal' ) ) {
			$registry->register( new BizCity_Personal_Action_Save_Journal() );
		}
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — register save_note block
		if ( class_exists( 'BizCity_Personal_Action_Save_Note' ) ) {
			$registry->register( new BizCity_Personal_Action_Save_Note() );
		}
	}
}
