<?php
/**
 * [2026-06-07 Johnny Chu] PHASE-0.40 G7.4 — DDV probe: CRM G7 Integration (Discord action + integration UI)
 *
 * Probe ID  : core.crm.g7_integration
 * Order     : 45
 * 3 layers:
 *   Disk    : Discord action file exists + block registry file exists
 *   Loader  : BizCity_Automation_Action_Notify_Discord class loaded
 *   Runtime : block registry returns notify_discord in registered block list
 *
 * @package BizCity_Twin_CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

// [2026-07-02 Johnny Chu] HOTFIX — add missing interface methods (description/severity/icon/estimate_ms) + fix run() return type + double-load guard
if ( class_exists( 'BizCity_Probe_CRM_G7_Integration', false ) ) {
	return;
}

final class BizCity_Probe_CRM_G7_Integration implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.crm.g7_integration'; }
	public function label(): string       { return 'CRM G7 Integration (Discord action block)'; }
	public function description(): string { return 'Kiểm tra Discord action block tồn tại, class loaded, và block registry đăng ký notify_discord.'; }
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 45; }
	public function icon(): string        { return 'plug'; }
	public function estimate_ms(): int    { return 2000; }
	public function tags(): array         { return array( 'crm', 'automation', 'discord', 'integration', 'g7' ); }

	/**
	 * @return bool
	 */
	public function precondition() {
		return true;
	}

	/**
	 * @param object $ctx
	 * @return array
	 */
	public function run( $ctx ): array {
		$results = array();

		// [2026-07-08 Johnny Chu] HOTFIX — resolve plugin root safely even when BIZCITY_TWIN_AI_PATH is unavailable.
		$plugin_root = defined( 'BIZCITY_TWIN_AI_PATH' ) ? (string) BIZCITY_TWIN_AI_PATH : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		if ( substr( $plugin_root, -1 ) !== '/' ) {
			$plugin_root .= '/';
		}

		// ── Layer 1: Disk ──
		$discord_file  = $plugin_root . 'core/automation/includes/blocks/actions/class-action-notify-discord.php';
		$registry_file = $plugin_root . 'core/automation/includes/blocks/class-block-registry.php';

		$results[] = array(
			'id'     => 'g7_disk_discord_file',
			'label'  => 'Disk: class-action-notify-discord.php exists',
			'status' => file_exists( $discord_file ) ? 'pass' : 'fail',
			'detail' => $discord_file,
		);
		$results[] = array(
			'id'     => 'g7_disk_registry_file',
			'label'  => 'Disk: class-block-registry.php exists',
			'status' => file_exists( $registry_file ) ? 'pass' : 'fail',
			'detail' => $registry_file,
		);
		if ( file_exists( $discord_file ) ) {
			$src = file_get_contents( $discord_file );
			$has_id  = strpos( $src, 'notify_discord' ) !== false;
			$has_execute = strpos( $src, 'function execute' ) !== false;
			$results[] = array(
				'id'     => 'g7_disk_discord_source',
				'label'  => 'Disk: Discord file has notify_discord id + execute()',
				'status' => ( $has_id && $has_execute ) ? 'pass' : 'fail',
				'detail' => 'has_id=' . ( $has_id ? 'yes' : 'NO' ) . ' has_execute=' . ( $has_execute ? 'yes' : 'NO' ),
			);
		}
		if ( file_exists( $registry_file ) ) {
			$reg_src = file_get_contents( $registry_file );
			$registered = strpos( $reg_src, 'Notify_Discord' ) !== false;
			$results[] = array(
				'id'     => 'g7_disk_registry_registers',
				'label'  => 'Disk: block-registry registers Notify_Discord',
				'status' => $registered ? 'pass' : 'fail',
				'detail' => $registered ? 'found' : 'NOT found in registry file',
			);
		}

		// ── Layer 2: Loader ──
		$class_loaded = class_exists( 'BizCity_Automation_Action_Notify_Discord' );
		$results[] = array(
			'id'     => 'g7_loader_discord_class',
			'label'  => 'Loader: BizCity_Automation_Action_Notify_Discord class loaded',
			'status' => $class_loaded ? 'pass' : 'fail',
			'detail' => $class_loaded ? 'class exists' : 'class NOT found in runtime — check bootstrap',
		);

		// ── Layer 3: Runtime ──
		if ( $class_loaded && class_exists( 'BizCity_Automation_Block_Registry' ) ) {
			$registry = BizCity_Automation_Block_Registry::instance();
			$block    = $registry->get( 'action.notify_discord' );
			$results[] = array(
				'id'     => 'g7_runtime_block_registered',
				'label'  => 'Runtime: action.notify_discord registered in block registry',
				'status' => $block ? 'pass' : 'fail',
				'detail' => $block ? 'block found, id=' . $block->id() : 'block NOT registered — check class-block-registry.php',
			);
			if ( $block ) {
				$meta = $block->meta();
				$results[] = array(
					'id'     => 'g7_runtime_block_meta',
					'label'  => 'Runtime: Discord block meta() returns valid array',
					'status' => ( ! empty( $meta['label'] ) && ! empty( $meta['fields'] ) ) ? 'pass' : 'warn',
					'detail' => 'label=' . ( $meta['label'] ?? '?' ) . ' fields=' . count( $meta['fields'] ?? array() ),
				);
			}
		} else {
			$results[] = array(
				'id'     => 'g7_runtime_block_registered',
				'label'  => 'Runtime: action.notify_discord registered',
				'status' => 'skip',
				'detail' => $class_loaded ? 'BizCity_Automation_Block_Registry not available' : 'Discord class not loaded',
			);
		}

		return $results;
	}

	public function cleanup(): void {}
}
// Self-register
add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes[] = new BizCity_Probe_CRM_G7_Integration();
	return $probes;
} );
