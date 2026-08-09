<?php
/**
 * BizCity Diagnostics — skills.journal_isolation probe.
 *
 * Read-only Wave 0 evidence for the boundary between the machine skill
 * registry and the user-facing Journal tree.
 *
 * @package Bizcity_Twin_AI
 * @since   2026-08-02
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Skills_Journal_Isolation', false ) ) {
	return;
}

final class BizCity_Probe_Skills_Journal_Isolation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'skills.journal_isolation'; }
	public function label(): string { return 'TwinNote · Runtime Isolation'; }
	public function description(): string {
		return 'Verify runtime rows remain active and resolvable while system-owned rows stay out of the TwinNote tree.';
	}
	public function severity(): string { return 'critical'; }
	public function order(): int { return 44; }
	public function icon(): string { return 'book'; }
	public function estimate_ms(): int { return 250; }

	public function precondition() {
		foreach ( array( 'BizCity_Skill_Database', 'BizCity_Skill_REST_API' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$base  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$rest_file = $base . '/core/skills/includes/class-skill-rest-api.php';
		$db_file   = $base . '/core/skills/includes/class-skill-database.php';
		$rest_src  = file_exists( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$db_src    = file_exists( $db_file ) ? (string) file_get_contents( $db_file ) : '';

		$disk_ok = $rest_src !== ''
			&& strpos( $rest_src, 'is_system_owned_skill' ) !== false
			&& strpos( $rest_src, 'get_tree' ) !== false
			&& strpos( $db_src, 'visibility' ) !== false
			&& strpos( $db_src, 'source_module' ) !== false;
		$steps[] = array(
			'label'  => 'Disk · provenance contract',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'REST isolation and DB provenance markers are present.' : 'Wave 0 provenance implementation is incomplete.',
		);
		if ( ! $disk_ok ) {
			return self::fail( $steps, 'Wave 0 disk contract missing.' );
		}

		$db = BizCity_Skill_Database::instance();
		$provenance_ready = method_exists( $db, 'get_table' )
			&& function_exists( 'bizcity_columns_exist' )
			&& bizcity_columns_exist( $db->get_table(), array( 'visibility', 'source_module' ) );
		$steps[] = array(
			'label'  => 'Runtime · routed provenance columns',
			'status' => $provenance_ready ? 'pass' : 'fail',
			'detail' => $provenance_ready ? 'visibility/source_module exist on the active routed shard.' : 'Required TwinNote runtime provenance columns are missing on the active routed shard.',
		);
		if ( ! $provenance_ready ) {
			return self::fail( $steps, 'TwinNote runtime provenance migration is incomplete on the active shard.' );
		}
		$rows = array_merge(
			$db->list_skills( array( 'limit' => 200, 'status' => 'active' ) ),
			$db->list_skills( array( 'limit' => 200, 'status' => 'draft' ) )
		);
		$system_rows = array();
		foreach ( $rows as $row ) {
			$visibility = sanitize_key( (string) ( $row['visibility'] ?? '' ) );
			$category   = sanitize_key( (string) ( $row['category'] ?? '' ) );
			if ( $visibility === 'runtime' || $category === 'tool-image' || $category === 'content-creator' || $category === 'web-research' ) {
				$system_rows[] = $row;
			}
		}
		$steps[] = array(
			'label'  => 'Loader · runtime registry',
			'status' => 'pass',
			'detail' => count( $system_rows ) . ' system/runtime row(s) loaded; no status mutation performed.',
		);

		$tree = BizCity_Skill_REST_API::instance()->get_tree()->get_data();
		$system_ids = array();
		foreach ( $system_rows as $row ) {
			$system_ids[ (int) ( $row['id'] ?? 0 ) ] = true;
		}
		$leaked = array();
		foreach ( is_array( $tree ) ? $tree : array() as $entry ) {
			if ( ! empty( $entry['virtual'] ) && isset( $system_ids[ (int) ( $entry['sk_id'] ?? 0 ) ] ) ) {
				$leaked[] = (int) $entry['sk_id'];
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · Journal tree boundary',
			'status' => empty( $leaked ) ? 'pass' : 'fail',
			'detail' => empty( $leaked ) ? 'No system-owned virtual nodes returned by get_tree().' : 'Leaked system skill IDs: ' . implode( ',', $leaked ),
		);
		if ( ! empty( $leaked ) ) {
			return self::fail( $steps, 'System-owned skill rows leaked into the Journal tree.' );
		}

		$slash_checked = false;
		foreach ( $system_rows as $row ) {
			$commands = array_filter( array_map( 'trim', explode( ',', (string) ( $row['slash_commands'] ?? '' ) ) ) );
			if ( empty( $commands ) || (string) ( $row['status'] ?? '' ) !== 'active' ) {
				continue;
			}
			$resolved = $db->get_by_slash_command( $commands[0] );
			$slash_checked = true;
			if ( ! is_array( $resolved ) || (int) ( $resolved['id'] ?? 0 ) !== (int) $row['id'] ) {
				return self::fail( $steps, 'Active runtime slash command no longer resolves.' );
			}
			break;
		}
		$steps[] = array(
			'label'  => 'Runtime · slash routing continuity',
			'status' => $slash_checked ? 'pass' : 'skip',
			'detail' => $slash_checked ? 'An active runtime slash command resolved to its owning row.' : 'No active runtime row with a slash command was available to exercise.',
		);

		return array(
			'status'  => 'pass',
			'summary' => 'Runtime skill registry remains active and isolated from the Journal tree.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}

	private static function fail( array $steps, string $message ): array {
		return array(
			'status'  => 'fail',
			'summary' => $message,
			'error'   => $message,
			'steps'   => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Skills_Journal_Isolation';
	return $probes;
} );
