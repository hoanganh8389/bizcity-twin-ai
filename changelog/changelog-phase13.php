<?php
/**
 * Changelog — Phase 1.3: Scheduler Core Backbone
 *
 * Validates: Scheduler Manager (CRUD, today context, reminders),
 *            Scheduler Tools (provider tools, agenda, free slots, Google sync),
 *            Scheduler Cron, Google Calendar, REST API.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase13 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.3';
	}

	public function get_phase_title(): string {
		return 'Scheduler Core Backbone';
	}

	public function get_description(): string {
		return 'Scheduler Manager CRUD + build_today_context + reminders. Scheduler Tools: provider tools, agenda, free-slots, Google sync. Cron, REST API.';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-15', 'updated' => '2026-03-25' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'Scheduler Manager',
				'icon'    => '📅',
				'entries' => [
					[ 'id' => 'SC-1', 'title' => 'BizCity_Scheduler_Manager class exists' ],
					[ 'id' => 'SC-2', 'title' => 'Singleton — get_instance()' ],
					[ 'id' => 'SC-3', 'title' => 'CRUD: create_event / update_event / delete_event' ],
					[ 'id' => 'SC-4', 'title' => 'build_today_context()' ],
					[ 'id' => 'SC-5', 'title' => 'get_pending_reminders()' ],
				],
			],
			[
				'group'   => 'Scheduler Tools',
				'icon'    => '🛠️',
				'entries' => [
					[ 'id' => 'ST-1', 'title' => 'BizCity_Scheduler_Tools class exists' ],
					[ 'id' => 'ST-2', 'title' => 'get_provider_tools() — tool schema' ],
					[ 'id' => 'ST-3', 'title' => 'get_today_agenda()' ],
					[ 'id' => 'ST-4', 'title' => 'find_free_slots()' ],
					[ 'id' => 'ST-5', 'title' => 'sync_google() or google-related method' ],
				],
			],
			[
				'group'   => 'Infrastructure',
				'icon'    => '⚙️',
				'entries' => [
					[ 'id' => 'IF-1', 'title' => 'BizCity_Scheduler_Cron class exists' ],
					[ 'id' => 'IF-2', 'title' => 'BizCity_Scheduler_Google class exists' ],
					[ 'id' => 'IF-3', 'title' => 'BizCity_Scheduler_Rest_Api class exists' ],
					[ 'id' => 'IF-4', 'title' => 'REST namespace registered' ],
				],
			],
		];
	}

	protected function run_verifications(): void {
		$this->verify_scheduler_manager();
		$this->verify_scheduler_tools();
		$this->verify_infrastructure();
	}

	/* ── Scheduler Manager ── */
	private function verify_scheduler_manager(): void {
		$exists = class_exists( 'BizCity_Scheduler_Manager' );
		$this->assert( 'SC-1', 'BizCity_Scheduler_Manager exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'SC-2', 'Manager not loaded' );
			$this->skip( 'SC-3', 'Manager not loaded' );
			$this->skip( 'SC-4', 'Manager not loaded' );
			$this->skip( 'SC-5', 'Manager not loaded' );
			return;
		}

		$this->assert( 'SC-2', 'Singleton get_instance()',
			method_exists( 'BizCity_Scheduler_Manager', 'get_instance' ) );

		$has_create = method_exists( 'BizCity_Scheduler_Manager', 'create_event' )
		           || method_exists( 'BizCity_Scheduler_Manager', 'create' )
		           || method_exists( 'BizCity_Scheduler_Manager', 'add_event' );
		$has_update = method_exists( 'BizCity_Scheduler_Manager', 'update_event' )
		           || method_exists( 'BizCity_Scheduler_Manager', 'update' );
		$has_delete = method_exists( 'BizCity_Scheduler_Manager', 'delete_event' )
		           || method_exists( 'BizCity_Scheduler_Manager', 'delete' );
		$this->assert( 'SC-3', 'CRUD methods', $has_create && $has_update && $has_delete,
			'create=' . ( $has_create ? '✅' : '❌' ) . ' update=' . ( $has_update ? '✅' : '❌' ) . ' delete=' . ( $has_delete ? '✅' : '❌' ) );

		$this->assert( 'SC-4', 'build_today_context()',
			method_exists( 'BizCity_Scheduler_Manager', 'build_today_context' ) );

		$this->assert( 'SC-5', 'get_pending_reminders()',
			method_exists( 'BizCity_Scheduler_Manager', 'get_pending_reminders' ) );
	}

	/* ── Scheduler Tools ── */
	private function verify_scheduler_tools(): void {
		$exists = class_exists( 'BizCity_Scheduler_Tools' );
		$this->assert( 'ST-1', 'BizCity_Scheduler_Tools exists', $exists );

		if ( ! $exists ) {
			$this->skip( 'ST-2', 'Tools not loaded' );
			$this->skip( 'ST-3', 'Tools not loaded' );
			$this->skip( 'ST-4', 'Tools not loaded' );
			$this->skip( 'ST-5', 'Tools not loaded' );
			return;
		}

		$this->assert( 'ST-2', 'get_provider_tools()',
			method_exists( 'BizCity_Scheduler_Tools', 'get_provider_tools' ) );

		$this->assert( 'ST-3', 'get_today_agenda()',
			method_exists( 'BizCity_Scheduler_Tools', 'get_today_agenda' ) );

		$this->assert( 'ST-4', 'find_free_slots()',
			method_exists( 'BizCity_Scheduler_Tools', 'find_free_slots' ) );

		$has_google = method_exists( 'BizCity_Scheduler_Tools', 'sync_google' )
		           || method_exists( 'BizCity_Scheduler_Tools', 'google_sync' )
		           || method_exists( 'BizCity_Scheduler_Tools', 'push_to_google' );
		$this->assert( 'ST-5', 'Google sync method', $has_google,
			$has_google ? 'Available' : 'No google sync method found at Tools level' );
	}

	/* ── Infrastructure ── */
	private function verify_infrastructure(): void {
		$classes = [
			'IF-1' => 'BizCity_Scheduler_Cron',
			'IF-2' => 'BizCity_Scheduler_Google',
			'IF-3' => 'BizCity_Scheduler_Rest_Api',
		];

		foreach ( $classes as $id => $class ) {
			$this->assert( $id, "{$class} exists", class_exists( $class ),
				class_exists( $class ) ? 'Loaded' : 'Not loaded' );
		}

		// Check if REST namespace is registered
		if ( class_exists( 'BizCity_Scheduler_Rest_Api' ) ) {
			$ref = new ReflectionClass( 'BizCity_Scheduler_Rest_Api' );
			$src = '';
			if ( $ref->getFileName() && is_readable( $ref->getFileName() ) ) {
				$src = file_get_contents( $ref->getFileName() );
			}
			$has_namespace = strpos( $src, 'register_rest_route' ) !== false
			              || strpos( $src, 'rest_api_init' ) !== false;
			$this->assert( 'IF-4', 'REST routes registered', $has_namespace,
				$has_namespace ? 'register_rest_route or rest_api_init found' : 'Checked via Reflection' );
		} else {
			$this->skip( 'IF-4', 'REST API class not loaded' );
		}
	}
}
