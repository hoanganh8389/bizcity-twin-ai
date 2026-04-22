<?php
/**
 * CRUD for bizcity_code_variants table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Variant_Manager {

	/* ── Read ── */

	public static function get_by_page( int $page_id ): array {
		global $wpdb;
		$t = BZCode_Installer::table_variants();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE page_id = %d ORDER BY variant_index ASC",
			$page_id
		) );
	}

	public static function get_selected( int $page_id ): ?object {
		global $wpdb;
		$t = BZCode_Installer::table_variants();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE page_id = %d AND is_selected = 1 LIMIT 1",
			$page_id
		) );
	}

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCode_Installer::table_variants();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCode_Installer::table_variants();

		$defaults = [
			'page_id'         => 0,
			'variant_index'   => 0,
			'code'            => '',
			'history_json'    => '[]',
			'model_used'      => '',
			'generation_type' => 'create',
			'is_selected'     => 0,
			'status'          => 'generating',
			'error_message'   => '',
			'token_input'     => 0,
			'token_output'    => 0,
			'created_at'      => current_time( 'mysql', true ),
			'updated_at'      => current_time( 'mysql', true ),
		];

		$data = array_merge( $defaults, $data );
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCode_Installer::table_variants();

		$data['updated_at'] = current_time( 'mysql', true );
		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function select_variant( int $page_id, int $variant_id ): void {
		global $wpdb;
		$t = BZCode_Installer::table_variants();

		// Deselect all variants on this page
		$wpdb->update( $t, [ 'is_selected' => 0 ], [ 'page_id' => $page_id ] );

		// Select chosen (variant_id=0 means deselect all only)
		if ( $variant_id > 0 ) {
			$wpdb->update( $t, [ 'is_selected' => 1 ], [ 'id' => $variant_id ] );
		}
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( BZCode_Installer::table_variants(), [ 'id' => $id ] );
	}
}
