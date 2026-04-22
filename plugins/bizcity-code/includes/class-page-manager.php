<?php
/**
 * CRUD for bizcity_code_pages table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Page_Manager {

	/* ── Read ── */

	public static function get_by_project( int $project_id ): array {
		global $wpdb;
		$t = BZCode_Installer::table_pages();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE project_id = %d ORDER BY sort_order ASC, id ASC",
			$project_id
		) );
	}

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCode_Installer::table_pages();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCode_Installer::table_pages();

		$defaults = [
			'project_id' => 0,
			'title'      => 'index',
			'slug'       => 'index',
			'sort_order' => 0,
			'status'     => 'active',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		];

		$data = array_merge( $defaults, $data );
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCode_Installer::table_pages();

		$data['updated_at'] = current_time( 'mysql', true );
		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		// Cascade: delete all variants of this page
		$wpdb->delete( BZCode_Installer::table_variants(), [ 'page_id' => $id ] );

		return (bool) $wpdb->delete( BZCode_Installer::table_pages(), [ 'id' => $id ] );
	}
}
