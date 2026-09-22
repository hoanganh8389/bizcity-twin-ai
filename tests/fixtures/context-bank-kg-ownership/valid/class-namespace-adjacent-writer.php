<?php
/**
 * Clean fixture: promotes through the facade, and writes only tables that share
 * the `bizcity_kg_*` NAMESPACE without being KG content.
 *
 * Must PASS with ZERO findings.
 *
 * This is the regression guard for the scope of `R-KG.promotion_outside_owner`.
 * A first version matched the `bizcity_kg_*` prefix and flagged
 * `modules/twinchat/includes/learning/class-twinchat-learning-database.php`,
 * which owns `bizcity_kg_learning_jobs`, `bizcity_kg_learning_events` and
 * `bizcity_kg_learning_batches`. Those were deliberately renamed into the
 * namespace "for unified naming" but hold queue, progress and ring-buffer
 * state — not entities, relations, passages or citations. The rule is about
 * promotion ownership, so flagging them was a scope error.
 *
 * If someone widens the pattern back to the prefix, THIS fixture fails.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Namespace_Adjacent_Writer {

	/** Promotion goes through the canonical facade, never direct SQL. */
	public static function promote( int $notebook_id, string $text ): void {
		BizCity_KG::ingest( array(
			'notebook_id' => $notebook_id,
			'content'     => $text,
		) );
	}

	/** Operational learning state — namespace-adjacent, not KG content. */
	public static function record_job( int $notebook_id, string $status ): void {
		global $wpdb;

		$jobs = $wpdb->prefix . 'bizcity_kg_learning_jobs';
		$wpdb->insert( $jobs, array(
			'notebook_id' => $notebook_id,
			'status'      => $status,
		) );

		$events = $wpdb->prefix . 'bizcity_kg_learning_events';
		$wpdb->delete( $events, array( 'notebook_id' => $notebook_id ) );
	}
}
