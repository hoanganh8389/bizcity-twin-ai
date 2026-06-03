<?php
/**
 * Sprint 5.5 — T-S5b.2: Test-Run single block (sync, no flowruns).
 *
 * AJAX endpoint: `wp_ajax_waic_test_run_block`
 *
 * Differences vs `waic_workflow_execute_node` (in execute-api.php):
 *   - KHÔNG ghi gì vào bảng `flowruns` / `flowlogs` (test runner đó làm).
 *   - Sync: nhận setting + variables giả → trả full result trong 1 request.
 *   - Honors `_test_run_safe` flag trên block. Block có side-effect "publish"
 *     thật (vd send FB, capture lead) phải opt-in `confirm=yes` mới chạy.
 *   - Lưu kết quả test cuối cùng vào transient `waic_test_run_<task>_<node>`
 *     TTL 1h để FE có thể restore khi reload.
 *
 * Caller: trong sidebar setting node, nhấn nút "▶ Test" (xem test-run-button.js).
 *
 * Security:
 *   - require capability: `manage_options` (admin only — đây là dev tool).
 *   - require nonce `waic_test_run_block`.
 *   - Variables là user-supplied → KHÔNG eval, chỉ pass nguyên vào getResults().
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Test_Run
 * @since 1.4.0  Sprint 5.5
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Test_Run_Block_API {

	const NONCE_ACTION   = 'waic_test_run_block';
	const TRANSIENT_PFX  = 'waic_test_run_';
	const TRANSIENT_TTL  = HOUR_IN_SECONDS;

	/**
	 * Block codes có side-effect không thể rollback — buộc confirm=yes.
	 * Có thể mở rộng. Kết hợp với property `$_test_run_safe = false` trên block.
	 */
	const RISKY_BLOCKS = array(
		'wp_send_facebook_bot_text',
		'wp_create_facebook_page_post',
		'wp_send_telegram_message',
		'te_send_message',
		'te_send_telegram_message',
		'di_send_message',
		'sl_send_message',
		'em_send_email',
		'ca_send_message',
		'crm_capture_lead',
		'loyalty_award_points',
		'wp_create_post',
		'wc_create_order',
		'kling_text2video',
		'kling_image2video',
	);

	public static function init() {
		add_action( 'wp_ajax_waic_test_run_block', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function handle_ajax() {
		// 1. Capability + nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden — admin only.' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// 2. Inputs.
		$task_id    = isset( $_POST['task_id'] )    ? (int) $_POST['task_id']    : 0;
		$node_id    = isset( $_POST['node_id'] )    ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';
		$node_type  = isset( $_POST['node_type'] )  ? sanitize_text_field( wp_unslash( $_POST['node_type'] ) ) : '';
		$node_code  = isset( $_POST['node_code'] )  ? sanitize_text_field( wp_unslash( $_POST['node_code'] ) ) : '';
		$confirm    = isset( $_POST['confirm'] )    && 'yes' === $_POST['confirm'];

		// settings + variables come as JSON string from FE.
		$settings_raw  = isset( $_POST['settings'] )  ? wp_unslash( $_POST['settings'] )  : '{}';
		$variables_raw = isset( $_POST['variables'] ) ? wp_unslash( $_POST['variables'] ) : '{}';

		$settings  = json_decode( $settings_raw,  true );
		$variables = json_decode( $variables_raw, true );
		if ( ! is_array( $settings ) )  { $settings  = array(); }
		if ( ! is_array( $variables ) ) { $variables = array(); }

		if ( '' === $node_code || ! in_array( $node_type, array( 'action', 'logic' ), true ) ) {
			wp_send_json_error( array(
				'message' => 'Invalid node_type or node_code (must be action|logic).',
				'inputs'  => compact( 'node_type', 'node_code' ),
			), 400 );
		}

		// 3. Resolve block class file.
		$loaded = self::load_block_classes( $node_type, $node_code );
		if ( true !== $loaded ) {
			wp_send_json_error( array( 'message' => 'Block class load failed: ' . $loaded ), 500 );
		}

		$class_name = ( 'logic' === $node_type ? 'WaicLogic_' : 'WaicAction_' ) . $node_code;
		if ( ! class_exists( $class_name ) ) {
			wp_send_json_error( array( 'message' => "Class not found after load: {$class_name}" ), 500 );
		}

		// 4. Build node skeleton (mimic workflow.php nodeData).
		$node = array(
			'id'   => $node_id ?: 'test-' . wp_generate_password( 6, false ),
			'type' => $node_type,
			'data' => array(
				'type'     => $node_type,
				'code'     => $node_code,
				'settings' => $settings,
			),
		);

		// 5. Side-effect safety check.
		try {
			$probe = new $class_name( $node );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => 'Block constructor threw: ' . $e->getMessage() ), 500 );
		}

		$is_safe = self::is_block_safe( $probe, $node_code );
		if ( ! $is_safe && ! $confirm ) {
			wp_send_json_error( array(
				'message'   => 'Block này có side-effect thật (gửi tin nhắn / ghi DB ngoài). Test sẽ chạy live API.',
				'risky'     => true,
				'need_confirm' => true,
			), 409 );
		}

		// 6. Run getResults sync.
		$task_id_safe = $task_id > 0 ? $task_id : 0;
		$started_at   = microtime( true );
		try {
			if ( method_exists( $probe, 'setRunId' ) ) {
				$probe->setRunId( 0 ); // 0 = test run, no flowruns row
			}
			$result = $probe->getResults( $task_id_safe, $variables, 0 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array(
				'message' => 'Block threw: ' . $e->getMessage(),
				'trace'   => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getTraceAsString() : null,
			), 500 );
		}
		$elapsed_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );

		// 7. Persist last_test_result (transient).
		if ( $task_id > 0 && '' !== $node_id ) {
			$tk = self::TRANSIENT_PFX . $task_id . '_' . md5( $node_id );
			set_transient( $tk, array(
				'at'       => time(),
				'elapsed'  => $elapsed_ms,
				'result'   => $result,
				'risky'    => ! $is_safe,
				'confirmed'=> $confirm,
			), self::TRANSIENT_TTL );
		}

		wp_send_json_success( array(
			'node_code' => $node_code,
			'node_type' => $node_type,
			'risky'     => ! $is_safe,
			'confirmed' => $confirm,
			'elapsed_ms'=> $elapsed_ms,
			'result'    => $result,
		) );
	}

	/**
	 * Whether a block is considered safe to test-run without confirmation.
	 *
	 * Tier 1: explicit property `$_test_run_safe`.
	 *   - true  → safe.
	 *   - false → risky.
	 * Tier 2: hard-coded RISKY_BLOCKS list.
	 * Tier 3: heuristic on prefix:
	 *   - tf_*, un_*, lp_* (utility) → safe.
	 *   - others (incl. ai_*, wp_create_*, wc_*, te_*, di_*, sl_*, em_*, ca_*, kling_*) → risky.
	 */
	protected static function is_block_safe( $instance, $code ) {
		// Tier 1: explicit property via reflection (property is protected).
		try {
			$ref = new \ReflectionClass( $instance );
			if ( $ref->hasProperty( '_test_run_safe' ) ) {
				$p = $ref->getProperty( '_test_run_safe' );
				$p->setAccessible( true );
				$v = $p->getValue( $instance );
				if ( is_bool( $v ) ) {
					return $v;
				}
			}
		} catch ( \Throwable $e ) { /* ignore */ }

		// Tier 2: blacklist.
		if ( in_array( $code, self::RISKY_BLOCKS, true ) ) {
			return false;
		}

		// Tier 3: prefix heuristic.
		$pos = strpos( $code, '_' );
		$prefix = $pos ? substr( $code, 0, $pos ) : $code;
		$safe_prefixes = array( 'tf', 'un', 'lp', 'it' );
		if ( in_array( $prefix, $safe_prefixes, true ) ) {
			return true;
		}

		// Default: risky (require confirm).
		return false;
	}

	/**
	 * Load WaicAction/WaicLogic base + the specific block file.
	 *
	 * NOTE (2026-06-01 archival): The legacy plugins/bizcity-automation/ folder
	 * was moved to plugins/_archived/ as part of the Phase 0.99 bundle slim-down.
	 * The native xyflow runtime in core/automation/ (BE-1..BE-5) replaces the
	 * old WAIC test-run pipeline. This loader now short-circuits with a clear
	 * error so callers fall back to the new runner. Keep the path string for
	 * git history / un-archive scenarios.
	 *
	 * @return true|string  true on success, error string on failure.
	 */
	protected static function load_block_classes( $node_type, $node_code ) {
		// ARCHIVED 2026-06-01 — plugins/bizcity-automation/ moved to plugins/_archived/.
		// Use core/automation/ (native xyflow) instead.
		return 'legacy WAIC blocks archived 2026-06-01 — use core/automation/ runner';

		// phpcs:disable -- legacy code retained for reference.
		/*
		$blocks_root = WP_PLUGIN_DIR . '/bizcity-twin-ai/plugins/bizcity-automation/';

		$base_files = array(
			$blocks_root . 'classes/baseObject.php'      => 'WaicBaseObject',
			$blocks_root . 'classes/builderBlock.php'    => 'WaicBuilderBlock',
			$blocks_root . 'modules/workflow/blocks/'    . $node_type . '.php' => '',
		);
		foreach ( $base_files as $path => $cls ) {
			if ( ! is_readable( $path ) ) {
				return "missing base file: {$path}";
			}
			require_once $path;
		}

		$block_file = $blocks_root . 'modules/workflow/blocks/' . $node_type . 's/' . $node_code . '.php';
		if ( ! is_readable( $block_file ) ) {
			return "block file not found: {$block_file}";
		}
		require_once $block_file;

		return true;
		*/
		// phpcs:enable
	}
}

BizCity_Test_Run_Block_API::init();
