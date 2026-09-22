<?php
/**
 * Broken fixture: KG content written from outside KG-Hub.
 *
 * Must report `R-KG.promotion_outside_owner`.
 *
 * A passage inserted this way never passes the KG promotion path, so it carries
 * no provenance row — which breaks the citation chain PHASE-0-RULE-CONTEXT-BANK
 * requires (kg relation/entity -> kg passage/source -> context record_id ->
 * ledger pointer -> verified JSONL line). The citation can no longer be
 * resolved back to a canonical owner.
 *
 * Both real shapes are present: a helper call used directly as the write target
 * and a helper result held in a local variable.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_KG_Bypass_Writer {

	public static function promote( int $notebook_id, string $text ): int {
		global $wpdb;

		$kg = BizCity_KG_Database::instance();

		$wpdb->insert( $kg->tbl_passages(), array(
			'notebook_id' => $notebook_id,
			'content'     => $text,
		) );

		$tbl_sources = $kg->tbl_sources();
		$wpdb->update( $tbl_sources, array( 'title' => 'renamed' ), array( 'id' => 1 ) );

		return (int) $wpdb->insert_id;
	}
}
