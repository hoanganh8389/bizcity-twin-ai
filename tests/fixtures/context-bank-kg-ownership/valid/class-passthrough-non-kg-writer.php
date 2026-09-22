<?php
/**
 * Clean fixture: a parameter-passthrough helper shaped exactly like the
 * interprocedural sink pattern, but the table it receives is never
 * KG/ledger-tagged at any call site.
 *
 * Must PASS with ZERO findings.
 *
 * This is the regression guard for the interprocedural pass added to close
 * the WP6 stated false negative: an ordinary "table name via parameter"
 * helper (a common, legitimate PHP shape) must not be flagged just because
 * its shape matches — only when a call site actually passes a KG/ledger
 * table into that parameter position.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Passthrough_Non_Kg_Writer {

	public function record_job( int $notebook_id, string $status ): int {
		$tbl_jobs = $this->tbl_learning_jobs();
		return $this->insert_row( $tbl_jobs, array(
			'notebook_id' => $notebook_id,
			'status'      => $status,
		) );
	}

	/** Operational learning state — namespace-adjacent, not KG content. */
	private function tbl_learning_jobs() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_jobs';
	}

	/** Same passthrough shape as the KG sink — must stay clean. */
	private function insert_row( $tbl_jobs, array $row ) {
		global $wpdb;
		$wpdb->insert( $tbl_jobs, $row );
		return (int) $wpdb->insert_id;
	}
}
