<?php
/**
 * Broken fixture: KG content written through a PARAMETER-PASSTHROUGH helper.
 *
 * Must report `R-KG.promotion_outside_owner` at the `$wpdb->insert()` line
 * inside the private helper, even though the helper itself never assigns or
 * names the KG table directly — it receives it as a parameter from a caller
 * that resolved it through a KG helper call.
 *
 * Mirrors the real shape in
 * plugins/bizcity-profile/includes/class-personal-kg-service.php:
 * `insert_passage( $tbl_passages, array $args )` writes `$wpdb->insert(
 * $tbl_passages, ... )` using its own parameter, which per-function variable
 * tracking (local `$var = ...` assignment only) cannot see. This is the
 * gate's WP6 stated false negative, resolved by the interprocedural pass.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_KG_Passthrough_Writer {

	public function promote( int $notebook_id, string $text ): int {
		$tbl_passages = BizCity_KG_Database::instance()->tbl_passages();
		return $this->insert_row( $tbl_passages, array(
			'notebook_id' => $notebook_id,
			'content'     => $text,
		) );
	}

	/** Never names the KG table itself — receives it as a parameter. */
	private function insert_row( $tbl_passages, array $row ) {
		global $wpdb;
		$wpdb->insert( $tbl_passages, $row );
		return (int) $wpdb->insert_id;
	}
}
