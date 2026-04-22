<?php
/**
 * CRUD for bizcity_code_projects table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Project_Manager {

	/* ── Read ── */

	public static function get_all( int $user_id = 0, string $status = '' ): array {
		global $wpdb;
		$t = BZCode_Installer::table_projects();

		$where = [];
		$args  = [];

		if ( $user_id ) {
			$where[] = 'user_id = %d';
			$args[]  = $user_id;
		}
		if ( $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$sql = "SELECT * FROM {$t}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY updated_at DESC';

		if ( $args ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
		}
		return $wpdb->get_results( $sql );
	}

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCode_Installer::table_projects();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function get_by_slug( string $slug ): ?object {
		global $wpdb;
		$t = BZCode_Installer::table_projects();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s", $slug ) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCode_Installer::table_projects();

		$defaults = [
			'user_id'       => get_current_user_id(),
			'title'         => '',
			'slug'          => '',
			'stack'         => 'html_tailwind',
			'description'   => '',
			'settings_json' => '{}',
			'status'        => 'draft',
			'publish_url'   => '',
			'created_at'    => current_time( 'mysql', true ),
			'updated_at'    => current_time( 'mysql', true ),
		];

		$data = array_merge( $defaults, $data );

		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] ) . '-' . wp_rand( 1000, 9999 );
		}

		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCode_Installer::table_projects();

		$data['updated_at'] = current_time( 'mysql', true );
		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;

		// Cascade: delete pages → variants → assets
		$pages = BZCode_Page_Manager::get_by_project( $id );
		foreach ( $pages as $page ) {
			BZCode_Page_Manager::delete( (int) $page->id );
		}

		// Delete assets
		$wpdb->delete( BZCode_Installer::table_assets(), [ 'project_id' => $id ] );

		// Delete project
		return (bool) $wpdb->delete( BZCode_Installer::table_projects(), [ 'id' => $id ] );
	}
}
