<?php
/**
 * Bizcity TwinChat — Learning Notifier
 *
 * Phase 4.9 — sends a chat system message to the user when learning is done.
 *
 * Multi-file batching: when several jobs finish within a short window
 * (default 6 s) we coalesce them into a single message
 *   "💡 Đã học xong 3 nguồn — 24 entities mới"
 * instead of spamming the chat.
 *
 *   Job done → notify( $job )
 *     ├─ append job to transient `tc_learn_pending_$nb` (TTL 30 s)
 *     └─ if no flush already scheduled → as_schedule_single_action(+6 s)
 *
 *   On flush → reads transient, inserts ONE system message via
 *   BizCity_TwinChat_Database::insert_message(role=system, meta.kind=learning_done).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Notifier {

	const HOOK_FLUSH    = 'bizcity_twinchat_learning_flush';
	const BATCH_DELAY_S = 6;
	const TRANSIENT_TTL = 30;

	private static $bound = false;
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function bind() {
		if ( self::$bound ) {
			return;
		}
		self::$bound = true;
		add_action( self::HOOK_FLUSH, [ __CLASS__, 'flush' ], 10, 1 );
	}

	/** Called by the pipeline at end-of-job. */
	public function notify( array $job ) {
		$nb     = (int) $job['notebook_id'];
		$key    = $this->transient_key( $nb );
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = [ 'jobs' => [], 'flush_scheduled' => 0 ];
		}

		$bucket['jobs'][] = [
			'job_id'             => (int) $job['id'],
			'source_id'          => (int) $job['source_id'],
			'source_title'       => (string) $job['source_title'],
			'entities_approved'  => (int) $job['entities_approved'],
			'triplets_extracted' => (int) $job['triplets_extracted'],
			'passages_processed' => (int) $job['passages_processed'],
			'entity_ids'         => isset( $job['entity_ids'] ) && is_array( $job['entity_ids'] ) ? array_values( array_map( 'intval', $job['entity_ids'] ) ) : [],
			'user_id'            => (int) $job['user_id'],
			'finished_at'        => (string) $job['finished_at'],
		];

		if ( empty( $bucket['flush_scheduled'] ) ) {
			$bucket['flush_scheduled'] = 1;
			$this->schedule_flush( $nb );
		}
		set_transient( $key, $bucket, self::TRANSIENT_TTL );
	}

	protected function schedule_flush( $nb ) {
		$args = [ (int) $nb ];
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::BATCH_DELAY_S, self::HOOK_FLUSH, $args, 'bizcity_twinchat_learning_' . (int) $nb );
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_FLUSH, $args ) ) {
			wp_schedule_single_event( time() + self::BATCH_DELAY_S, self::HOOK_FLUSH, $args );
		}
	}

	/** Flush handler — coalesces all pending job notifications into one chat row. */
	public static function flush( $notebook_id ) {
		$nb     = (int) $notebook_id;
		$self   = self::instance();
		$key    = $self->transient_key( $nb );
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || empty( $bucket['jobs'] ) ) {
			return;
		}
		delete_transient( $key );

		$jobs = $bucket['jobs'];
		// Filter out jobs with zero entities AND zero triplets (nothing to celebrate).
		$jobs = array_values( array_filter( $jobs, static function ( $j ) {
			return ( (int) $j['entities_approved'] > 0 ) || ( (int) $j['triplets_extracted'] > 0 );
		} ) );
		if ( empty( $jobs ) ) {
			return;
		}

		$total_entities = array_sum( array_map( static function ( $j ) { return (int) $j['entities_approved']; }, $jobs ) );
		$total_triplets = array_sum( array_map( static function ( $j ) { return (int) $j['triplets_extracted']; }, $jobs ) );
		$entity_ids     = [];
		foreach ( $jobs as $j ) {
			foreach ( (array) $j['entity_ids'] as $eid ) { $entity_ids[ (int) $eid ] = true; }
		}
		$entity_ids = array_keys( $entity_ids );

		$user_id = (int) $jobs[0]['user_id'];

		// Build user-facing content (Markdown) — single vs batch.
		if ( count( $jobs ) === 1 ) {
			$j       = $jobs[0];
			$title   = $j['source_title'] !== '' ? $j['source_title'] : 'Nguồn vừa tải';
			$content = sprintf(
				'💡 **Twin đã học xong** «%s» — %d khái niệm mới, %d quan hệ vào Second Brain.',
				$title,
				$total_entities,
				$total_triplets
			);
		} else {
			$titles = array_slice( array_filter( array_map( static function ( $j ) { return (string) $j['source_title']; }, $jobs ) ), 0, 4 );
			$preview = '';
			if ( ! empty( $titles ) ) {
				$preview = ' (' . implode( ', ', array_map( static function ( $t ) {
					return strlen( $t ) > 30 ? substr( $t, 0, 30 ) . '…' : $t;
				}, $titles ) );
				if ( count( $jobs ) > count( $titles ) ) {
					$preview .= ', +' . ( count( $jobs ) - count( $titles ) ) . ' nữa';
				}
				$preview .= ')';
			}
			$content = sprintf(
				'💡 **Twin đã học xong %d nguồn**%s — tổng %d khái niệm mới, %d quan hệ vào Second Brain.',
				count( $jobs ),
				$preview,
				$total_entities,
				$total_triplets
			);
		}

		$meta = [
			'kind'        => 'learning_done',
			'job_ids'     => array_map( static function ( $j ) { return (int) $j['job_id']; }, $jobs ),
			'source_ids'  => array_values( array_filter( array_map( static function ( $j ) { return (int) $j['source_id']; }, $jobs ) ) ),
			'entities'    => $total_entities,
			'triplets'    => $total_triplets,
			'jobs'        => count( $jobs ),
			'entity_ids'  => array_slice( $entity_ids, 0, 200 ),
		];

		// Resolve a session to attach the system message to. Without a session_id
		// the row is invisible to get_session_messages(). Strategy:
		//   1) latest webchat_sessions row for (notebook, user)
		//   2) latest distinct session_id from this notebook's messages
		//   3) synth a fresh sess_* id and let the user discover it on next open
		$session_id = self::resolve_session_id( $nb, $user_id );

		$msg_id = 0;
		if ( class_exists( 'BizCity_TwinChat_Database' ) ) {
			$msg_id = (int) BizCity_TwinChat_Database::instance()->insert_message( [
				'notebook_id' => $nb,
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'role'        => 'system',
				'content'     => $content,
				'kg_entities' => $meta, // stored under meta.kg_entities — see DB layer
			] );
		}

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'chat', [
			'message_id' => $msg_id,
			'session_id' => $session_id,
			'role'       => 'system',
			'content'    => $content,
			'meta'       => $meta,
		] );
	}

	/**
	 * Find the most-recent session id for this (notebook, user). Falls back to
	 * any session in the notebook, then to a synthetic id so the row is at
	 * least addressable when the user opens the notebook later.
	 */
	protected static function resolve_session_id( $notebook_id, $user_id ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_TwinChat_Database' ) ) {
			return 'sys_' . wp_generate_password( 8, false, false );
		}
		$db      = BizCity_TwinChat_Database::instance();
		$ses_tbl = $db->table_sessions();
		$msg_tbl = $db->table_messages();

		// Try sessions table first (filtered by user when possible).
		$ses_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ses_tbl ) );
		if ( $ses_exists === $ses_tbl ) {
			$cols = $wpdb->get_col( "DESCRIBE {$ses_tbl}", 0 ) ?: [];
			$has_user = in_array( 'user_id', $cols, true );
			if ( $has_user && $user_id > 0 ) {
				$sid = $wpdb->get_var( $wpdb->prepare(
					"SELECT session_id FROM {$ses_tbl} WHERE project_id=%s AND platform_type='twinchat' AND user_id=%d ORDER BY last_message_at DESC LIMIT 1",
					(string) $notebook_id,
					(int) $user_id
				) );
				if ( $sid ) { return (string) $sid; }
			}
			$sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT session_id FROM {$ses_tbl} WHERE project_id=%s AND platform_type='twinchat' ORDER BY last_message_at DESC LIMIT 1",
				(string) $notebook_id
			) );
			if ( $sid ) { return (string) $sid; }
		}

		// Fallback: latest session_id from messages table.
		$msg_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $msg_tbl ) );
		if ( $msg_exists === $msg_tbl ) {
			$sid = $wpdb->get_var( $wpdb->prepare(
				"SELECT session_id FROM {$msg_tbl} WHERE project_id=%s AND platform_type='twinchat' AND session_id<>'' ORDER BY id DESC LIMIT 1",
				(string) $notebook_id
			) );
			if ( $sid ) { return (string) $sid; }
		}

		return 'sess_' . wp_generate_password( 12, false, false );
	}

	protected function transient_key( $nb ) {
		return 'tc_learn_pending_' . (int) $nb;
	}
}
