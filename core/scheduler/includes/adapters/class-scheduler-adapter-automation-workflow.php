<?php
/**
 * Adapter: automation_workflow (Automation BE-4 — workflow trigger fire).
 *
 * @package Bizcity_Twin_AI
 * @since   2026-06-03 (SCH-NC W2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once __DIR__ . '/class-scheduler-adapter-base.php';

if ( class_exists( 'BizCity_Scheduler_Adapter_Automation_Workflow' ) ) {
	return;
}

final class BizCity_Scheduler_Adapter_Automation_Workflow extends BizCity_Scheduler_Adapter_Base {

	public function event_type() {
		return 'automation_workflow';
	}

	public function label() {
		return 'Chạy automation';
	}

	public function metadata_schema() {
		return [
			'workflow_id' => [ 'type' => 'int',   'required' => true ],
			'payload'     => [ 'type' => 'array', 'required' => false ],
			'defer'       => [ 'type' => 'bool',  'required' => false ],
		];
	}
}
