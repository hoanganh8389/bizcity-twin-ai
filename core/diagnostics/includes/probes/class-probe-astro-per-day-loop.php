<?php
/**
 * BizCity Diagnostics — astro.per_day_message_loop probe (PHASE-FAA2-TWINBRAIN).
 *
 * Verifies day-by-day loop wiring for automation Astro template:
 * - Block files exist (run_astro_transit + reply_zalo_each_day).
 * - Classes are loaded and registered in block registry source.
 * - Template includes days_json binding into action.reply_zalo_each_day.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Astro_Per_Day_Loop', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Per_Day_Loop implements BizCity_Diagnostics_Probe {

	public function id(): string       { return 'astro.per_day_message_loop'; }
	public function label(): string    { return 'Astro Per-day Message Loop (Automation)'; }
	public function description(): string {
		return 'Kiểm tra action.reply_zalo_each_day và binding days_json từ action.run_astro_transit trong template astro-zalobot.';
	}
	public function severity(): string { return 'info'; }
	public function order(): int       { return 45; }
	public function icon(): string     { return 'message-square'; }
	public function estimate_ms(): int { return 180; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_AUTOMATION_DIR' ) ) {
			return 'BIZCITY_AUTOMATION_DIR chưa định nghĩa — automation chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — DDV probe for per-day message loop.
		$steps    = array();
		$failures = array();

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — normalize directory join.
		// BIZCITY_AUTOMATION_DIR is defined as __DIR__ (no trailing slash).
		$automation_dir = rtrim( (string) BIZCITY_AUTOMATION_DIR, '/\\' );
		$reply_file     = $automation_dir . '/includes/blocks/actions/class-action-reply-zalo-each-day.php';
		$transit_file   = $automation_dir . '/includes/blocks/actions/class-action-run-astro-transit.php';
		$registry_file  = $automation_dir . '/includes/blocks/class-block-registry.php';
		$template_file  = $automation_dir . '/templates/astro-zalobot.json';

		$disk_ok = file_exists( $reply_file ) && file_exists( $transit_file )
			&& file_exists( $registry_file ) && file_exists( $template_file );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'Required files exist (actions + registry + template).'
				: 'Missing one of required files for per-day loop wiring.',
		);
		if ( ! $disk_ok ) {
			$failures[] = 'required_files_missing';
		}

		$reply_class_ok   = class_exists( 'BizCity_Automation_Action_Reply_Zalo_Each_Day' );
		$transit_class_ok = class_exists( 'BizCity_Automation_Action_Run_Astro_Transit' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => ( $reply_class_ok && $transit_class_ok ),
			'msg'   => ( $reply_class_ok && $transit_class_ok )
				? 'Automation classes loaded (run_astro_transit + reply_zalo_each_day).'
				: 'One/both automation classes not loaded.',
		);
		if ( ! $reply_class_ok || ! $transit_class_ok ) {
			$failures[] = 'automation_classes_missing';
		}

		$registry_src = file_exists( $registry_file ) ? (string) file_get_contents( $registry_file ) : '';
		$registry_ok  = ( strpos( $registry_src, 'BizCity_Automation_Action_Reply_Zalo_Each_Day' ) !== false )
			&& ( strpos( $registry_src, 'BizCity_Automation_Action_Run_Astro_Transit' ) !== false );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $registry_ok,
			'msg'   => $registry_ok
				? 'Block registry source includes both Astro per-day blocks.'
				: 'Block registry source missing one of Astro per-day blocks.',
		);
		if ( ! $registry_ok ) {
			$failures[] = 'registry_missing_blocks';
		}

		$template_src = file_exists( $template_file ) ? (string) file_get_contents( $template_file ) : '';
		$has_transit  = strpos( $template_src, '"blockId": "action.run_astro_transit"' ) !== false;
		$has_reply    = strpos( $template_src, '"blockId": "action.reply_zalo_each_day"' ) !== false;
		$has_binding  = strpos( $template_src, '"days_json": "{{n4.days_json}}"' ) !== false;
		$template_ok  = $has_transit && $has_reply && $has_binding;

		$steps[] = array(
			'layer' => 'runtime',
			'ok'    => $template_ok,
			'msg'   => $template_ok
				? 'Template wiring OK: run_astro_transit -> days_json -> reply_zalo_each_day.'
				: 'Template wiring missing blockId or days_json binding.',
		);
		if ( ! $template_ok ) {
			$failures[] = 'template_wiring_missing';
		}

		if ( ! empty( $failures ) ) {
			$fatal_keys = array( 'required_files_missing', 'automation_classes_missing', 'registry_missing_blocks' );
			$has_fatal  = (bool) array_intersect( $fatal_keys, $failures );
			return array(
				'status'   => $has_fatal ? 'fail' : 'warn',
				'steps'    => $steps,
				'summary'  => 'Astro per-day loop issue(s): ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Kiểm tra class-block-registry.php và templates/astro-zalobot.json.',
			);
		}

		return array(
			'status'  => 'pass',
			'steps'   => $steps,
			'summary' => 'Astro per-day message loop OK — classes, registry, template binding all present.',
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Per_Day_Loop';
	return $list;
} );
