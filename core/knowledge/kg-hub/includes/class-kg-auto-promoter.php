<?php
/**
 * Bizcity Twin AI — KG Auto-Promoter
 *
 * Listens for `bizcity_kg_auto_promote_message` action and writes
 * eligible chat messages into `bizcity_kg_passages` as scope=session
 * passages — making them retrievable by the next KG query.
 *
 * Governed by PHASE-0-RULE-KG-HUB-CONTRACT.md §4 (auto-promote).
 *
 * Eligibility:
 *  - Content length ≥ MIN_CHARS (30 by default)
 *  - User has not exceeded throttle (10 per minute via transient)
 *  - Hash dedup against existing kg_passages within the same scope
 *
 * Triplet extraction is NOT triggered inline — passage is left
 * `extraction_status='pending'` so the existing extractor cron picks it up.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Auto_Promoter {

	const MIN_CHARS         = 30;
	const THROTTLE_LIMIT    = 10;
	const THROTTLE_WINDOW_S = 60;
	const HOOK              = 'bizcity_kg_auto_promote_message';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		add_action( self::HOOK, [ $this, 'handle' ], 10, 2 );
	}

	/**
	 * @param array $message { role, notebook_id, user_id, session_id, content, id? }
	 * @param array $context { surface }
	 */
	public function handle( $message, $context = [] ) {
		if ( ! is_array( $message ) ) return;

		$content = isset( $message['content'] ) ? trim( (string) $message['content'] ) : '';
		if ( $content === '' || mb_strlen( $content ) < self::MIN_CHARS ) {
			return;
		}

		$user_id     = (int) ( $message['user_id'] ?? 0 );
		$notebook_id = (int) ( $message['notebook_id'] ?? 0 );
		$session_id  = (string) ( $message['session_id'] ?? '' );
		$role        = (string) ( $message['role'] ?? 'user' );
		$surface     = isset( $context['surface'] ) ? (string) $context['surface'] : 'unknown';

		if ( $session_id === '' ) {
			return;
		}

		// Throttle: per-user counter window.
		if ( $user_id > 0 && ! $this->within_throttle( $user_id ) ) {
			return;
		}

		if ( ! class_exists( 'BizCity_KG_Database' ) ) return;
		$db   = BizCity_KG_Database::instance();
		$hash = md5( $content );

		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()} WHERE scope_type='session' AND scope_id=%s AND content_hash=%s LIMIT 1",
			$session_id,
			$hash
		) );
		if ( $exists ) {
			return;
		}

		$origin = 'chat:' . $role;

		// Filestore-only (Rule v2.0): generate embedding for .bin write only;
		// embedding column on kg_passages stays NULL.
		$vec = null;
		if ( class_exists( 'BizCity_KG_Vector_Index' ) ) {
			$tmp = BizCity_KG_Vector_Index::instance()->embed( $content );
			if ( is_array( $tmp ) ) { $vec = $tmp; }
		}

		$wpdb->insert( $db->tbl_passages(), [
			'notebook_id'       => $notebook_id,
			'scope_type'        => 'session',
			'scope_id'          => $session_id,
			'source_table'      => '',
			'source_id'         => null,
			'chunk_id'          => null,
			'origin'            => substr( $origin, 0, 100 ),
			'content'           => $content,
			'content_hash'      => $hash,
			'embedding'         => null,
			'token_count'       => (int) ceil( mb_strlen( $content ) / 4 ),
			'extraction_status' => 'pending',
			'metadata'          => wp_json_encode( [
				'plugin'     => $surface,
				'session_id' => $session_id,
				'role'       => $role,
				'message_id' => isset( $message['id'] ) ? (int) $message['id'] : 0,
			] ),
		] );

		// PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 — .bin is single source of truth.
		$pid = (int) $wpdb->insert_id;
		if ( $pid && $notebook_id > 0 && is_array( $vec ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			BizCity_KG_Embedding_Writer::instance()->register_chunk(
				(int) $notebook_id, $pid, $vec, null, null
			);
		}
	}

	/**
	 * Sliding window throttle via WP transient.
	 */
	private function within_throttle( $user_id ) {
		$key = 'bizcity_kg_promote_' . (int) $user_id;
		$cnt = (int) get_transient( $key );
		if ( $cnt >= self::THROTTLE_LIMIT ) {
			return false;
		}
		set_transient( $key, $cnt + 1, self::THROTTLE_WINDOW_S );
		return true;
	}
}
