<?php
/**
 * Changelog — Phase 1.4: Content Tool Core v2
 *
 * Validates: Skill SQL storage, accepts_skill flag, it_call_content WaicAction,
 *            Skill-Tool mapping, atomic content tool catalog.
 *
 * @package BizCity_Twin_AI
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die();

class BizCity_Changelog_Phase14 extends BizCity_Changelog_Base {

	public function get_phase_id(): string {
		return '1.4';
	}

	public function get_phase_title(): string {
		return 'Content Tool Core v2';
	}

	public function get_description(): string {
		return 'Skill SQL + Atomic Content + it_call_content — SQL-based skill storage, accepts_skill flag, content tool catalog, distribution tools';
	}

	public function get_dates(): array {
		return [ 'started' => '2026-03-20', 'updated' => '2026-04-04' ];
	}

	public function get_changelog(): array {
		return [
			[
				'group'   => 'R1 — Skill SQL Storage',
				'icon'    => '🗄️',
				'entries' => [
					[ 'id' => 'R1-1', 'title' => 'BizCity_Skill_Database class exists (singleton)' ],
					[ 'id' => 'R1-2', 'title' => 'bizcity_skills table created via maybe_create_table()' ],
					[ 'id' => 'R1-3', 'title' => 'CRUD: upsert() + get() + delete()' ],
					[ 'id' => 'R1-4', 'title' => 'Query: get_by_key() + get_by_slash_command()' ],
					[ 'id' => 'R1-5', 'title' => 'Scoring: find_matching() with SQL pre-filter + in-memory score' ],
					[ 'id' => 'R1-6', 'title' => 'Migration: migrate_files_to_sql() from .md → SQL' ],
				],
			],
			[
				'group'   => 'R2 — accepts_skill Flag & Tool Classification',
				'icon'    => '🏷️',
				'entries' => [
					[ 'id' => 'R2-1', 'title' => 'Skill Manager class exists' ],
					[ 'id' => 'R2-2', 'title' => 'Skill Context filter integrated' ],
					[ 'id' => 'R2-3', 'title' => 'Skill Pipeline Bridge wiring' ],
				],
			],
			[
				'group'   => 'R4 — it_call_content WaicAction',
				'icon'    => '📝',
				'entries' => [
					[ 'id' => 'R4-1', 'title' => 'WaicAction_it_call_content class exists' ],
					[ 'id' => 'R4-2', 'title' => 'Action code = it_call_content' ],
					[ 'id' => 'R4-3', 'title' => 'Has getResults() execution method' ],
					[ 'id' => 'R4-4', 'title' => 'Has HIL state management (get/set/clear)' ],
					[ 'id' => 'R4-5', 'title' => 'Has getContentToolOptions() — filters by accepts_skill' ],
					[ 'id' => 'R4-6', 'title' => 'Has inherit_from_previous_nodes() — auto-inherit topic' ],
				],
			],
			[
				'group'   => 'R7 — Skill ↔ Tool Mapping',
				'icon'    => '🔗',
				'entries' => [
					[ 'id' => 'R7-1', 'title' => 'BizCity_Skill_Tool_Map class exists' ],
					[ 'id' => 'R7-2', 'title' => 'Skill REST API with resolve_skill_db_id()' ],
				],
			],
			[
				'group'   => 'Admin & Infrastructure',
				'icon'    => '⚙️',
				'entries' => [
					[ 'id' => 'ADM-1', 'title' => 'Skill Admin Page class exists' ],
					[ 'id' => 'ADM-2', 'title' => 'list_skills() supports filters (category, status, user_id)' ],
				],
			],
		];
	}

	/* ════════════════════════════════════════════════════════════════════
	 * VERIFICATIONS
	 * ════════════════════════════════════════════════════════════════════ */

	protected function run_verifications(): void {
		$this->verify_skill_database();
		$this->verify_skill_ecosystem();
		$this->verify_it_call_content();
		$this->verify_tool_map();
		$this->verify_admin();
	}

	/* ── R1: Skill SQL Storage ── */
	private function verify_skill_database(): void {
		$exists = class_exists( 'BizCity_Skill_Database' );
		$this->assert( 'R1-1', 'BizCity_Skill_Database class exists', $exists,
			$exists ? 'Singleton loaded' : 'Not loaded — skills bootstrap missing?' );

		if ( ! $exists ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "R1-{$i}", 'Skill Database not loaded' );
			}
			return;
		}

		$db = BizCity_Skill_Database::instance();

		// R1-2: Table exists
		global $wpdb;
		$table = $db->get_table();
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		$this->assert( 'R1-2', 'bizcity_skills table exists', $table_exists, $table );

		// R1-3: CRUD methods
		$has_crud = method_exists( $db, 'upsert' )
		         && method_exists( $db, 'get' )
		         && method_exists( $db, 'delete' );
		$this->assert( 'R1-3', 'CRUD: upsert + get + delete', $has_crud );

		// R1-4: Query methods
		$has_query = method_exists( $db, 'get_by_key' )
		          && method_exists( $db, 'get_by_slash_command' );
		$this->assert( 'R1-4', 'Query: get_by_key + get_by_slash_command', $has_query );

		// R1-5: find_matching with scoring
		$has_find = method_exists( $db, 'find_matching' );
		if ( $has_find ) {
			$ref = new ReflectionMethod( $db, 'find_matching' );
			$src = $this->read_method_source( $ref->getFileName(), $ref->getStartLine(), $ref->getEndLine() );
			$has_score = strpos( $src, 'score' ) !== false;
			$this->assert( 'R1-5', 'find_matching() with scoring', $has_score,
				$has_score ? 'SQL pre-filter + in-memory score' : 'No scoring logic' );
		} else {
			$this->assert( 'R1-5', 'find_matching() exists', false );
		}

		// R1-6: Migration
		$has_migrate = method_exists( $db, 'migrate_files_to_sql' );
		$this->assert( 'R1-6', 'migrate_files_to_sql() exists', $has_migrate,
			$has_migrate ? '.md → SQL migration available' : 'Migration method missing' );
	}

	/* ── R2: Skill Ecosystem ── */
	private function verify_skill_ecosystem(): void {
		$this->assert( 'R2-1', 'Skill Manager class exists',
			class_exists( 'BizCity_Skill_Manager' ),
			class_exists( 'BizCity_Skill_Manager' ) ? 'Loaded' : 'Not loaded' );

		$this->assert( 'R2-2', 'Skill Context class exists',
			class_exists( 'BizCity_Skill_Context' ),
			class_exists( 'BizCity_Skill_Context' ) ? 'Loaded' : 'Not loaded' );

		$this->assert( 'R2-3', 'Skill Pipeline Bridge exists',
			class_exists( 'BizCity_Skill_Pipeline_Bridge' ),
			class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ? 'Loaded' : 'Not loaded' );
	}

	/* ── R4: it_call_content ── */
	private function verify_it_call_content(): void {
		$exists = class_exists( 'WaicAction_it_call_content' );
		$this->assert( 'R4-1', 'WaicAction_it_call_content exists', $exists,
			$exists ? 'WaicAction block loaded' : 'Not loaded — actions bootstrap missing?' );

		if ( ! $exists ) {
			for ( $i = 2; $i <= 6; $i++ ) {
				$this->skip( "R4-{$i}", 'WaicAction_it_call_content not loaded' );
			}
			return;
		}

		// R4-2: Action code
		$ref = new ReflectionClass( 'WaicAction_it_call_content' );
		$code_prop = $ref->hasProperty( '_code' );
		if ( $code_prop ) {
			$prop = $ref->getProperty( '_code' );
			$prop->setAccessible( true );
			$instance = $ref->newInstanceWithoutConstructor();
			$code = $prop->getValue( $instance );
			$this->assert( 'R4-2', 'Action code = it_call_content', $code === 'it_call_content',
				'code=' . $code );
		} else {
			$this->assert( 'R4-2', '$_code property exists', false );
		}

		// R4-3: getResults
		$this->assert( 'R4-3', 'getResults() exists', $ref->hasMethod( 'getResults' ) );

		// R4-4: HIL state
		$has_hil = $ref->hasMethod( 'get_hil_state' )
		        && $ref->hasMethod( 'set_hil_state' )
		        && $ref->hasMethod( 'clear_hil_state' );
		$this->assert( 'R4-4', 'HIL state management (get/set/clear)', $has_hil );

		// R4-5: getContentToolOptions
		$has_options = $ref->hasMethod( 'getContentToolOptions' );
		if ( $has_options ) {
			$src = $this->read_method_source(
				$ref->getMethod( 'getContentToolOptions' )->getFileName(),
				$ref->getMethod( 'getContentToolOptions' )->getStartLine(),
				$ref->getMethod( 'getContentToolOptions' )->getEndLine()
			);
			$filters_skill = strpos( $src, 'accepts_skill' ) !== false;
			$this->assert( 'R4-5', 'getContentToolOptions filters accepts_skill', $filters_skill,
				$filters_skill ? 'Filters by accepts_skill=true' : 'No accepts_skill filter' );
		} else {
			$this->assert( 'R4-5', 'getContentToolOptions() exists', false );
		}

		// R4-6: inherit_from_previous_nodes
		$has_inherit = $ref->hasMethod( 'inherit_from_previous_nodes' );
		$this->assert( 'R4-6', 'inherit_from_previous_nodes() exists', $has_inherit,
			$has_inherit ? 'Auto-inherit topic from prior nodes' : 'Method missing' );
	}

	/* ── R7: Skill-Tool Map ── */
	private function verify_tool_map(): void {
		$this->assert( 'R7-1', 'Skill Tool Map class exists',
			class_exists( 'BizCity_Skill_Tool_Map' ),
			class_exists( 'BizCity_Skill_Tool_Map' ) ? 'Loaded' : 'Not loaded' );

		$rest_exists = class_exists( 'BizCity_Skill_REST_API' );
		if ( $rest_exists ) {
			$has_resolve = method_exists( 'BizCity_Skill_REST_API', 'resolve_skill_db_id' );
			$this->assert( 'R7-2', 'REST API resolve_skill_db_id()', $has_resolve );
		} else {
			$this->skip( 'R7-2', 'BizCity_Skill_REST_API not loaded' );
		}
	}

	/* ── Admin ── */
	private function verify_admin(): void {
		// ADM-1: Admin Page
		// Note: multiple classes may be named similarly; check the skills-specific one
		$admin_exists = class_exists( 'BizCity_Skill_Admin_Page' );
		$this->assert( 'ADM-1', 'Skill Admin Page exists', $admin_exists,
			$admin_exists ? 'Loaded' : 'Not loaded (non-admin context OK)' );

		// ADM-2: list_skills with filters
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			$db  = BizCity_Skill_Database::instance();
			$ref = new ReflectionMethod( $db, 'list_skills' );
			$params = array_map( fn( $p ) => $p->getName(), $ref->getParameters() );
			$has_filters = in_array( 'filters', $params, true );
			$this->assert( 'ADM-2', 'list_skills() accepts $filters', $has_filters,
				'params=[' . implode( ', ', $params ) . ']' );
		} else {
			$this->skip( 'ADM-2', 'Skill Database not loaded' );
		}
	}
}
