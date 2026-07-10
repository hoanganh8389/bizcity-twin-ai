<?php
/**
 * BizCity Diagnostics — cg.admin-router probe (TASK-UNIFY Phase 2).
 *
 * Validates the CG Admin Router + CMD Classifier pipeline:
 *   Layer 1 (Disk)    — class-cg-admin-router.php + class-cmd-classifier.php exist (no BOM).
 *   Layer 2 (Loader)  — BizCity_CG_Admin_Router + BizCity_CMD_Classifier loaded;
 *                        bizcity_zalo_message_received hook attached.
 *   Layer 3 (Runtime) — BizCity_CMD_Classifier::classify() spot-tests for each intent pattern.
 *   Layer 3 (Runtime) — REST route bizcity-channel/v1/tasks/{id}/confirm registered.
 *
 * No real Zalo message is sent. The classifier unit-tests run in-process.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-29 (TASK-UNIFY Phase 2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_CG_Admin_Router', false ) ) {
	return;
}

final class BizCity_Probe_CG_Admin_Router implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'cg.admin-router'; }
	public function label(): string       { return 'Channel GW · Admin Router + CMD Classifier (TASK-UNIFY Phase 2)'; }
	public function description(): string {
		return 'Kiểm tra BizCity_CG_Admin_Router + BizCity_CMD_Classifier: disk + loader + hook + classifier unit tests + REST route.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 39; }
	public function icon(): string        { return 'route'; }
	public function estimate_ms(): int    { return 200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CG_Admin_Router' ) ) {
			return 'BizCity_CG_Admin_Router chưa load — core/channel-gateway/bootstrap.php chưa require class-cg-admin-router.php.';
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = [];

		/* ----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ---------------------------------------------------------------- */

		$files = [
			'class-cg-admin-router.php'  => WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-cg-admin-router.php',
			'class-cmd-classifier.php'   => WP_PLUGIN_DIR . '/bizcity-twin-ai/core/channel-gateway/includes/class-cmd-classifier.php',
		];

		foreach ( $files as $short => $path ) {
			if ( ! file_exists( $path ) ) {
				$steps[] = [
					'label'  => 'Disk · ' . $short . ' exists',
					'status' => 'FAIL',
					'detail' => 'File not found: ' . $path,
				];
				return $steps;
			}
			$first3 = file_get_contents( $path, false, null, 0, 3 );
			if ( $first3 === "\xEF\xBB\xBF" ) {
				$steps[] = [
					'label'  => 'Disk · ' . $short . ' (no BOM)',
					'status' => 'FAIL',
					'detail' => 'BOM detected — PHP output before <?php.',
				];
			} else {
				$steps[] = [
					'label'  => 'Disk · ' . $short . ' exists + no BOM',
					'status' => 'PASS',
					'detail' => number_format( filesize( $path ) ) . ' bytes.',
				];
			}
		}

		/* ----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ---------------------------------------------------------------- */

		foreach (
			[
				'BizCity_CG_Admin_Router' => 'class-cg-admin-router.php',
				'BizCity_CMD_Classifier'  => 'class-cmd-classifier.php',
			] as $class => $file
		) {
			$loaded = class_exists( $class );
			$steps[] = [
				'label'  => 'Loader · ' . $class . ' loaded',
				'status' => $loaded ? 'PASS' : 'FAIL',
				'detail' => $loaded ? 'class_exists() = true' : 'class_exists() = false — check require_once in bootstrap.php.',
			];
		}

		// Hook check.
		$hook_priority = has_action(
			'bizcity_zalo_message_received',
			[ BizCity_CG_Admin_Router::instance(), 'on_message' ]
		);
		$steps[] = [
			'label'  => 'Loader · hook bizcity_zalo_message_received @5',
			'status' => $hook_priority === 5 ? 'PASS' : 'FAIL',
			'detail' => 'has_action() = ' . var_export( $hook_priority, true ),
		];

		/* ----------------------------------------------------------------
		 * Layer 3 — Classifier unit tests
		 * ---------------------------------------------------------------- */

		if ( class_exists( 'BizCity_CMD_Classifier' ) ) {
			$cases = [
				[ 'đăng bài tử vi tháng 6',      'web_post',      'tử vi tháng 6' ],
				[ 'viết post giới thiệu sản phẩm', 'web_post',      'giới thiệu sản phẩm' ],
				[ 'post facebook khai trương',     'fb_post',       'khai trương' ],
				[ 'đăng fb caption mới',           'fb_post',       'caption mới' ],
				[ 'nhắc chị Lan lúc 8 giờ sáng',  'reminder_zalo', '' ], // topic blank OK
				[ 'danh sách task',                'list_tasks',    '' ],
				[ 'hủy task 42',                   'cancel_task',   '' ],
				[ 'xin chào',                      'CHAT',          '' ],
			];

			$all_pass = true;
			$details  = [];

			foreach ( $cases as [ $input, $expected_type, $expected_topic ] ) {
				$r = BizCity_CMD_Classifier::classify( $input );
				$type_ok  = $r['type'] === $expected_type;
				$topic_ok = $expected_topic === '' || $r['topic'] === $expected_topic;
				if ( ! $type_ok || ! $topic_ok ) {
					$all_pass = false;
					$details[] = "FAIL: input='{$input}' → type={$r['type']} (expected {$expected_type}), topic={$r['topic']} (expected '{$expected_topic}')";
				}
			}

			$steps[] = [
				'label'  => 'Classifier · ' . count( $cases ) . ' intent pattern tests',
				'status' => $all_pass ? 'PASS' : 'FAIL',
				'detail' => $all_pass
					? 'All ' . count( $cases ) . ' patterns matched correctly.'
					: implode( '; ', $details ),
			];
		} else {
			$steps[] = [
				'label'  => 'Classifier · unit tests',
				'status' => 'SKIP',
				'detail' => 'BizCity_CMD_Classifier not loaded.',
			];
		}

		/* ----------------------------------------------------------------
		 * Layer 3 — REST route registered
		 * ---------------------------------------------------------------- */

		$server  = rest_get_server();
		$routes  = $server->get_routes();
		$route_key = '/bizcity-channel/v1/tasks/(?P<id>\d+)/confirm';
		$registered = isset( $routes[ $route_key ] );

		$steps[] = [
			'label'  => 'REST · ' . $route_key . ' registered',
			'status' => $registered ? 'PASS' : 'FAIL',
			'detail' => $registered
				? 'Route found in rest_get_server()->get_routes().'
				: 'Route not found — BizCity_CG_Admin_Router::init() + register_rest() may not have run.',
		];

		return $steps;
	}

	public function cleanup(): void {} // cleanup done inline inside run()
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_CG_Admin_Router();
	return $list;
} );
