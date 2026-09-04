<?php
/**
 * Bizcity Twin AI — TwinChat Database (Unified Bridge)
 *
 * Sprint 4.5 — Unified messages/sessions into a core-owned message contract and
 * compatibility projections:
 *   bizcity_webchat_messages  (platform_type = 'TWINCHAT', project_id = notebook_id) — Core owner
 *   bizcity_webchat_sessions  (platform_type = 'TWINCHAT', project_id = notebook_id) — legacy projection
 *
 * The old bizcity_twinchat_messages table is no longer created.
 * TwinChat-specific fields (sources, thinking, agent_steps, kg_entities)
 * are serialised into the `meta` JSON column of webchat_messages.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Database {

	/** Platform tag stored in every row written by TwinChat. */
	const PLATFORM = 'TWINCHAT';
	/** Core-owned shared message projection; physical table name stays compatible. */
	const MESSAGE_TABLE = 'bizcity_webchat_messages';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Core message contract backed by the shared WebChat-compatible table. */
	public function table_messages() {
		global $wpdb;
		return $wpdb->prefix . self::MESSAGE_TABLE;
	}

	/** Shared sessions table (managed by WebChat module). */
	public function table_sessions() {
		global $wpdb;
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — TwinChat session metadata has no SQL table name.
		return '';
	}

	/**
	 * No local TwinChat DDL — the core message contract delegates its single-table
	 * schema to the WebChat extension without provisioning quarantined projections.
	 * Triggers the scoped message installer if the retained table does not exist.
	 *
	 * Caches the "installed" flag in an option so we skip the metadata probe
	 * on every request (Query Monitor flagged this as a slow recurring query).
	 */
	public function maybe_install() {
		$opt_key = 'bizcity_twinchat_db_installed';
		if ( get_option( $opt_key ) === '1' ) {
			return;
		}
		global $wpdb;
		$tbl    = $this->table_messages();
		$exists = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $tbl );
		if ( $exists ) {
			update_option( $opt_key, '1', true );
			return;
		}
		if ( class_exists( 'BizCity_WebChat_Database' ) ) {
			self::install_message_table();
			// Re-probe once to confirm before caching.
			$exists = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $tbl );
			if ( $exists ) {
				update_option( $opt_key, '1', true );
			}
		}
	}

	/**
	 * Install only the core-owned message projection through the shared DDL owner.
	 */
	public static function install_message_table() {
		// [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-CORE-MESSAGE — core owns the message contract without duplicating extension DDL.
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return false;
		}
		$database = BizCity_WebChat_Database::instance();
		$database->create_messages_table();
		$ready = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( self::instance()->table_messages() );
		if ( $ready ) {
			update_option( 'bizcity_twinchat_message_db_ver', '1.0.0', true );
		}
		return $ready;
	}

	/**
	 * Insert a message into bizcity_webchat_messages.
	 *
	 * Accepted keys:
	 *   notebook_id, user_id, session_id, role (user|assistant|system),
	 *   content, sources, thinking, agent_steps, kg_entities,
	 *   citations (string[]), citations_meta (array<int, array>), token_count
	 *
	 * TwinChat-specific fields are encoded into the `meta` JSON column.
	 *
	 * @param array $args
	 * @return int  inserted row ID, or 0 on failure
	 */
	public function insert_message( array $args ) {
		global $wpdb;

		$notebook_id = (int) ( $args['notebook_id'] ?? 0 );
		$user_id     = (int) ( $args['user_id']     ?? 0 );
		$session_id  = (string) ( $args['session_id'] ?? '' );
		$role        = in_array( $args['role'] ?? 'user', [ 'user', 'assistant', 'system' ], true )
		               ? $args['role'] : 'user';
		$content     = (string) ( $args['content'] ?? '' );
		$token_count = (int) ( $args['token_count'] ?? 0 );

		// Sprint 0.6.16 — split prompt/completion + finish_reason for billing.
		// `prompt_tokens` / `completion_tokens` take precedence when provided; otherwise
		// fall back to legacy `token_count` (mapped by role as before).
		$prompt_tokens     = (int) ( $args['prompt_tokens']     ?? 0 );
		$completion_tokens = (int) ( $args['completion_tokens'] ?? 0 );
		$finish_reason     = (string) ( $args['finish_reason']  ?? '' );

		// role → webchat message_from mapping.
		$message_from = ( $role === 'assistant' ) ? 'bot' : $role;

		// Build meta JSON for TwinChat-specific fields.
		$meta_fields = [
			'sources'        => $args['sources']        ?? null,
			'thinking'       => $args['thinking']       ?? null,
			'agent_steps'    => $args['agent_steps']    ?? null,
			'kg_entities'    => $args['kg_entities']    ?? null,
			'citations'      => $args['citations']      ?? null,
			'citations_meta' => $args['citations_meta'] ?? null,
			// 2026-05-05 — persist <suggestions> chips so they survive F5.
			'suggestions'    => $args['suggestions']    ?? null,
		];
		$meta = [];
		foreach ( $meta_fields as $k => $v ) {
			if ( $v !== null && $v !== '' && $v !== [] ) {
				$meta[ $k ] = $v;
			}
		}

		$row = [
			'session_id'    => $session_id,
			'user_id'       => $user_id,
			'message_id'    => uniqid( 'tc_', true ),
			'message_text'  => $content,
			'message_from'  => $message_from,
			'plugin_slug'   => 'twinchat',
			'platform_type' => self::PLATFORM,
			'project_id'    => (string) $notebook_id,
			'status'        => 'visible',
		];

		if ( $role === 'user' ) {
			$row['input_tokens']  = $prompt_tokens > 0 ? $prompt_tokens : $token_count;
			$row['output_tokens'] = 0;
		} else {
			// Assistant / system: prefer split counts when stream-handler supplied them.
			$row['input_tokens']  = $prompt_tokens;
			$row['output_tokens'] = $completion_tokens > 0 ? $completion_tokens : $token_count;
		}

		if ( ! empty( $meta ) ) {
			$row['meta'] = wp_json_encode( $meta );
		}

		// Only include created_at / token columns if they exist in the live table.
		// The migration in webchat's maybe_upgrade_conversations() will add them on next init.
		$msg_cols = array();
		foreach ( array( 'created_at', 'input_tokens', 'output_tokens', 'finish_reason' ) as $column ) {
			if ( function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $this->table_messages(), $column ) ) {
				$msg_cols[] = $column;
			}
		}
		if ( in_array( 'created_at', $msg_cols, true ) ) {
			$row['created_at'] = current_time( 'mysql', true );
		}
		if ( ! in_array( 'input_tokens', $msg_cols, true ) ) {
			unset( $row['input_tokens'], $row['output_tokens'] );
		}
		// Sprint 0.6.16 — only write finish_reason if migration 14 has run.
		if ( $finish_reason !== '' && in_array( 'finish_reason', $msg_cols, true ) ) {
			$row['finish_reason'] = mb_substr( $finish_reason, 0, 32 );
		}

		$ok = $wpdb->insert( $this->table_messages(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch recent messages for a session, mapped back to TwinChat shape.
	 *
	 * Returns rows with keys: id, role, content, sources, thinking,
	 * agent_steps, kg_entities, token_count, created_at
	 *
	 * @param string $session_id
	 * @param int    $limit
	 * @return array
	 */
	public function get_session_messages( $session_id, $limit = 100 ) {
		global $wpdb;
		$session_id = (string) $session_id;
		$limit      = max( 1, min( 500, (int) $limit ) );
		if ( $session_id === '' ) {
			return [];
		}

		$tbl = $this->table_messages();

		// Detect whether input_tokens/output_tokens exist (they may be absent on older tables
		// before the migration in maybe_upgrade_conversations() adds them).
		$cols         = array();
		foreach ( array( 'input_tokens', 'output_tokens' ) as $column ) {
			if ( function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $tbl, $column ) ) {
				$cols[] = $column;
			}
		}
		$has_tokens   = in_array( 'input_tokens', $cols, true );
		$token_select = $has_tokens ? ', input_tokens, output_tokens' : '';

		$rows = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, message_from, message_text{$token_select}, meta, created_at
			   FROM {$tbl}
			  WHERE session_id = %s AND platform_type = %s
			  ORDER BY id ASC
			  LIMIT %d",
			$session_id,
			self::PLATFORM,
			$limit
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $r ) {
			$meta  = ! empty( $r['meta'] ) ? json_decode( $r['meta'], true ) : [];
			$meta  = is_array( $meta ) ? $meta : [];
			$role  = ( $r['message_from'] === 'bot' ) ? 'assistant' : (string) $r['message_from'];
			$tok   = ( $role === 'assistant' )
			         ? (int) ( $r['output_tokens'] ?? 0 )
			         : (int) ( $r['input_tokens']  ?? 0 );
			$out[] = [
				'id'             => (int) $r['id'],
				'role'           => $role,
				'content'        => (string) $r['message_text'],
				'sources'        => is_array( $meta['sources']        ?? null ) ? $meta['sources']        : [],
				'thinking'       => $meta['thinking']       ?? null,
				'agent_steps'    => is_array( $meta['agent_steps']    ?? null ) ? $meta['agent_steps']    : [],
				'kg_entities'    => is_array( $meta['kg_entities']    ?? null ) ? $meta['kg_entities']    : [],
				'citations'      => is_array( $meta['citations']      ?? null ) ? $meta['citations']      : [],
				'citations_meta' => is_array( $meta['citations_meta'] ?? null ) ? $meta['citations_meta'] : [],
				// 2026-05-05 — return persisted suggestion chips for F5 hydration.
				'suggestions'    => is_array( $meta['suggestions']    ?? null ) ? $meta['suggestions']    : [],
				'token_count'    => $tok,
				'created_at'     => (string) $r['created_at'],
			];
		}
		return $out;
	}

	/**
	 * List sessions for a notebook (latest first).
	 * Reads from bizcity_webchat_sessions where available, falls back to messages aggregate.
	 *
	 * @param int $notebook_id
	 * @param int $limit
	 * @return array
	 */
	public function list_sessions( $notebook_id, $limit = 50 ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		$limit       = max( 1, min( 200, (int) $limit ) );
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — read notebook sessions from the encrypted state owner.
		if ( class_exists( 'BizCity_WebChat_Session_State' ) ) {
			$states = BizCity_WebChat_Session_State::instance()->list_for_user( null, self::PLATFORM, $limit, (string) $notebook_id, 'all' );
			$rows = array();
			foreach ( $states as $state ) {
				$rows[] = array(
					'session_id' => $state->session_id,
					'last_at' => $state->last_message_at,
					'message_count' => (int) $state->message_count,
					'first_message' => $state->last_message_preview,
					'title' => $state->title,
				);
			}
			return $rows;
		}

		return array();
	}

	/**
	 * Create or update a session row in bizcity_webchat_sessions.
	 * Called by the stream handler after the first message is inserted.
	 *
	 * @param array $args  notebook_id, session_id, user_id, title?, preview?
	 * @return bool
	 */
	public function upsert_session( array $args ) {
		global $wpdb;
		// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — TwinChat upsert uses the encrypted session-state owner.

		$session_id  = (string) ( $args['session_id']  ?? '' );
		$notebook_id = (int)    ( $args['notebook_id'] ?? 0 );
		$user_id     = (int)    ( $args['user_id']     ?? 0 );
		if ( $session_id === '' || $notebook_id <= 0 ) {
			return false;
		}

		$title   = isset( $args['title'] )   ? mb_substr( sanitize_text_field( $args['title'] ), 0, 255 )   : '';
		$preview = isset( $args['preview'] ) ? mb_substr( sanitize_text_field( $args['preview'] ), 0, 255 ) : $title;
		if ( ! class_exists( 'BizCity_WebChat_Session_State' ) ) {
			return false;
		}
		$store = BizCity_WebChat_Session_State::instance();
		$state = $store->get_by_session( $session_id, self::PLATFORM );
		if ( ! $state ) {
			$created = $store->create_for_session( $session_id, $user_id, '', self::PLATFORM, $title, array( 'project_id' => (string) $notebook_id ) );
			$state = $store->get_by_id( $created['id'] );
		}
		if ( ! $state ) {
			return false;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_messages()} WHERE session_id = %s AND platform_type = %s AND message_type != 'conversation_meta'",
			$session_id, self::PLATFORM
		) );
		return $store->update( $state->id, array(
			'title' => $title !== '' ? $title : $state->title,
			'last_message_at' => current_time( 'mysql', true ),
			'last_message_preview' => $preview,
			'message_count' => max( 1, $count ),
		) );
	}
}

// [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-CORE-MESSAGE — central schema owner for the retained shared message projection.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_webchat_messages',
		'core.twin-core',
		'1.0.0',
		'bizcity_twinchat_message_db_ver',
		array( 'BizCity_TwinChat_Database', 'install_message_table' )
	);
}
