<?php
/**
 * BizCity Scheduler — Automation Lab (Admin QA Harness)
 *
 * Self-contained admin page for testing `ai_context.automation.on_fire[]`
 * chains without waiting for the 5-min cron tick.
 *
 * Route: wp-admin/admin.php?page=bizcity-scheduler-automation-lab
 * Capability: manage_options (admin only)
 *
 * Backed by 4 REST endpoints in bizcity-scheduler/v1:
 *   POST /automation/fire-now   — bypass cron
 *   GET  /automation/recent     — last N chain runs
 *   POST /automation/validate   — lint chain JSON
 *   GET  /automation/tools      — discover tool registry
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-04 (Phase 0.37 — Layer 2 test surface)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Automation_Lab {

	const MENU_SLUG = 'bizcity-scheduler-automation-lab';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 30 );
	}

	public function register_menu(): void {
		add_submenu_page(
			'bizcity-webchat-dashboard',
			__( 'Automation Lab', 'bizcity-twin-ai' ),
			'🧪 ' . __( 'Automation Lab', 'bizcity-twin-ai' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$rest_base = esc_url_raw( rest_url( 'bizcity-scheduler/v1' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );

		include __DIR__ . '/../views/page-automation-lab.php';
	}
}

BizCity_Scheduler_Automation_Lab::instance();
