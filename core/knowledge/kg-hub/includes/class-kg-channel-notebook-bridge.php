<?php
/**
 * BizCity_KG_Channel_Notebook_Bridge — canonical channel → notebook capture bridge.
 *
 * Single unify entry point for every channel/surface (Zalo Bot, Telegram,
 * Messenger, WebChat, TwinWeb, TwinChat) that wants to turn an inbound
 * text / image / audio / document message into a KG notebook source,
 * auto-creating a per-user per-day notebook when one does not exist yet.
 *
 * Naming rule (superseded 2026-07-26, PHASE-0.46 Wave 5 — see §2.1-R below):
 * human-facing `name` is now JUST the resolved title (e.g. "Hop Sale");
 * `{channel}/{user_id}/{day_key}` identity moved entirely into `settings{}`,
 * and every notebook now carries a stable `settings.slug` (e.g.
 * `20260724-hop-sale`) used consistently by share links, the public
 * learning-log link and doc-export filenames. New notebooks also auto-join
 * a per-user "{Channel} hằng ngày" workspace (`settings.workspace_id`)
 * instead of the shared generic "Notebook" workspace.
 *
 * See docs/roadmaps/PHASE-0.46-CHANNEL-NOTEBOOK-BRIDGE.md §2.3 (canonical
 * architecture spec this class implements — Wave 1 MVP) and §Wave 5 (naming/
 * workspace/slug + trigger-unification rules).
 *
 * [2026-07-24 Johnny Chu] PHASE-0.46 W1 — initial implementation.
 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 — workspace auto-provision, slug,
 * friendly naming, @ghichu/@notebook trigger unification.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KGHub
 * @since      PHASE-0.46 Wave 1
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_KG_Channel_Notebook_Bridge', false ) ) {
	return;
}

class BizCity_KG_Channel_Notebook_Bridge {
	const HOOK_ASYNC_CAPTURE_INGEST = 'bizcity_kg_notebook_capture_ingest_dispatch';
	const HOOK_ASYNC_CAPTURE_WATCHDOG = 'bizcity_kg_notebook_capture_watchdog'; // [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — bounded bridge recovery hook.
	const JOB_ASYNC_CAPTURE_INGEST  = 'kg.notebook_capture_ingest_dispatch';
	const JOB_ASYNC_CAPTURE_WATCHDOG = 'kg.notebook_capture_watchdog'; // [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — central cron identity for bridge watchdog.
	const ASYNC_CAPTURE_INTERVAL    = 'hourly';
	const ASYNC_CAPTURE_WATCHDOG_INTERVAL = 'bizcity_kg_bridge_5min'; // [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — five-minute recovery cadence.
	const ASYNC_CAPTURE_AUTO_RESUME_MAX = 1; // [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — prevent infinite automatic retries.

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — pending inbox/session TTL was
	 * a hard 600s (10 min), too short for a staff member busy uploading a
	 * voice memo + several photos/documents before typing the trigger. Bumped
	 * to 25 minutes (mid of the requested 15–30 min range), still filterable.
	 */
	const PENDING_TTL_DEFAULT = 1500;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — bind async ingest dispatch
	 * hook so media/file notebook captures can hand off learning to cron.
	 */
	public static function bind_async_dispatch(): void {
		add_action( self::HOOK_ASYNC_CAPTURE_INGEST, array( __CLASS__, 'run_async_capture_ingest' ), 10, 1 );
		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — recover bridge placeholders that failed after staging but before successful ingest.
		add_action( self::HOOK_ASYNC_CAPTURE_WATCHDOG, array( __CLASS__, 'watchdog' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'register_async_watchdog_schedule' ) );
		add_action( 'init', array( __CLASS__, 'ensure_async_dispatch_registration' ), 20 );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.4 — register bridge async job
	 * in core cron registry (adopt-only: single-event queue, no recurring loop).
	 */
	public static function ensure_async_dispatch_registration(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			if ( ! wp_next_scheduled( self::HOOK_ASYNC_CAPTURE_WATCHDOG ) ) {
				wp_schedule_event( time() + 60, self::ASYNC_CAPTURE_WATCHDOG_INTERVAL, self::HOOK_ASYNC_CAPTURE_WATCHDOG );
			}
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ASYNC_CAPTURE_INGEST,
			'hook'        => self::HOOK_ASYNC_CAPTURE_INGEST,
			'interval'    => self::ASYNC_CAPTURE_INTERVAL,
			'owner'       => 'core/knowledge/kg-hub',
			'description' => 'Dispatch notebook-bridge media/file ingest via cron single events.',
			'retention'   => 7,
			'adopt_only'  => true,
		) );
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ASYNC_CAPTURE_WATCHDOG,
			'hook'        => self::HOOK_ASYNC_CAPTURE_WATCHDOG,
			'interval'    => self::ASYNC_CAPTURE_WATCHDOG_INTERVAL,
			'owner'       => 'core/knowledge/kg-hub',
			'description' => 'Resume failed notebook-bridge captures when their staged evidence file is still available.',
			'retention'   => 7,
		) );
	}

	public static function register_async_watchdog_schedule( $schedules ) {
		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — expose the bridge recovery cadence to WP-Cron fallback.
		if ( ! isset( $schedules[ self::ASYNC_CAPTURE_WATCHDOG_INTERVAL ] ) ) {
			$schedules[ self::ASYNC_CAPTURE_WATCHDOG_INTERVAL ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'BizCity notebook bridge watchdog (5 min)',
			);
		}
		return $schedules;
	}

	public static function watchdog(): void {
		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — resume failed bridge placeholders only when durable staging remains.
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Database' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return;
		}
		global $wpdb;
		$db = BizCity_TwinChat_Sources_Database::instance();
		$rows = $wpdb->get_results( "SELECT id, project_id, user_id, title, attachment_id, embedding_status, metadata FROM {$db->table_sources()} WHERE embedding_status IN ('processing','error') AND metadata LIKE '%bridge_async%' ORDER BY id ASC LIMIT 25", ARRAY_A ) ?: array();
		$bridge = self::instance();

		foreach ( $rows as $row ) {
			$meta = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
			if ( ! is_array( $meta ) || empty( $meta['async_file'] ) ) {
				continue;
			}
			$state = sanitize_key( (string) ( $meta['async_state'] ?? 'queued' ) );
			if ( in_array( $state, array( 'done', 'duplicate' ), true ) ) {
				continue;
			}
			$heartbeat = (int) ( $meta['async_heartbeat_at'] ?? 0 );
			$is_error = $state === 'error' || (string) ( $row['embedding_status'] ?? '' ) === 'error';
			$is_stale = ! $is_error && $heartbeat > 0 && time() - $heartbeat >= 10 * MINUTE_IN_SECONDS;
			if ( ! $is_error && ! $is_stale ) {
				continue;
			}

			$path = $bridge->bridge_async_stage_path( (string) $meta['async_file'] );
			if ( ! is_readable( $path ) ) {
				$bridge->bridge_log( 'watchdog_file_missing', array(
					'source_id' => (int) $row['id'],
					'job_id'    => (string) ( $meta['job_id'] ?? '' ),
					'error_code'=> 'notebook_bridge_async_file_missing',
				), 'warn' );
				continue;
			}

			$auto_resume_count = (int) ( $meta['async_auto_resume_count'] ?? 0 );
			if ( $auto_resume_count >= self::ASYNC_CAPTURE_AUTO_RESUME_MAX ) {
				continue;
			}

			$job_id = (string) ( $meta['job_id'] ?? $meta['async_job_id'] ?? wp_generate_uuid4() );
			$attachment_id = (int) ( $meta['evidence_attachment_id'] ?? $row['attachment_id'] ?? 0 );
			$payload = array(
				'type'          => 'file',
				'title'         => (string) $row['title'],
				'attachment_id' => $attachment_id,
				'metadata'      => $meta,
				'file'          => array(
					'name'     => (string) ( $meta['async_original_name'] ?? $meta['async_file'] ),
					'type'     => (string) ( $meta['async_file_type'] ?? '' ),
					'tmp_name' => $path,
					'error'    => 0,
					'size'     => (int) ( $meta['async_file_size'] ?? filesize( $path ) ),
				),
			);
			$job = array(
				'job_id'         => $job_id,
				'blog_id'        => (int) get_current_blog_id(),
				'notebook_id'    => (int) $row['project_id'],
				'user_id'        => (int) $row['user_id'],
				'ingest_payload' => $payload,
				'item_ctx'       => array( 'source_id' => (int) $row['id'], 'auto_resume' => 1 ),
				'inbound'        => isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ? $meta['inbound'] : array(),
				'queued_at'      => time(),
			);

			$scheduled = wp_schedule_single_event( time() + 5, self::HOOK_ASYNC_CAPTURE_INGEST, array( $job ) );
			if ( false === $scheduled && ! wp_next_scheduled( self::HOOK_ASYNC_CAPTURE_INGEST, array( $job ) ) ) {
				$bridge->mark_bridge_async_placeholder_failed( $payload, 'Khong the xep lai cron de resume nguon.', 'notebook_bridge_async_resume_schedule_failed' );
				$bridge->bridge_log( 'watchdog_resume_schedule_failed', array( 'source_id' => (int) $row['id'], 'job_id' => $job_id, 'error_code' => 'notebook_bridge_async_resume_schedule_failed' ), 'error' );
				continue;
			}

			$meta['async_state'] = 'queued';
			$meta['async_attempt'] = 0;
			$meta['async_auto_resume_count'] = $auto_resume_count + 1;
			$meta['async_auto_resume_at'] = time();
			$meta['async_heartbeat_at'] = time();
			unset( $meta['async_error'], $meta['async_error_code'] );
			$db->update_source( (int) $row['id'], array( 'metadata' => $meta, 'error_message' => null, 'embedding_status' => 'processing' ) );
			$kg_source_id = (int) ( $meta['async_placeholder_kg_source_id'] ?? 0 );
			if ( $kg_source_id > 0 ) {
				$wpdb->update( BizCity_KG_Database::instance()->tbl_sources(), array( 'status' => 'processing', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $kg_source_id ) );
			}
			$bridge->bridge_log( 'watchdog_auto_resume', array( 'source_id' => (int) $row['id'], 'job_id' => $job_id, 'attempt' => 0, 'error_code' => 'notebook_bridge_async_auto_resume' ) );
			self::spawn_cron_wakeup();
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — cron worker for queued
	 * notebook-bridge ingest items.
	 */
	public static function run_async_capture_ingest( $job ): void {
		if ( ! is_array( $job ) ) {
			return;
		}

		$blog_id        = isset( $job['blog_id'] ) ? (int) $job['blog_id'] : 0;
		$job_id         = isset( $job['job_id'] ) ? (string) $job['job_id'] : '';
		$notebook_id    = isset( $job['notebook_id'] ) ? (int) $job['notebook_id'] : 0;
		$user_id        = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;
		$ingest_payload = isset( $job['ingest_payload'] ) && is_array( $job['ingest_payload'] ) ? $job['ingest_payload'] : array();
		$item_ctx       = isset( $job['item_ctx'] ) && is_array( $job['item_ctx'] ) ? $job['item_ctx'] : array();
		$inbound        = isset( $job['inbound'] ) && is_array( $job['inbound'] ) ? $job['inbound'] : array();
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — async worker must
		// settle the Journal learning state after cron/retry completes.
		$journal_entry_id = isset( $ingest_payload['metadata']['journal_entry_id'] ) ? (int) $ingest_payload['metadata']['journal_entry_id'] : 0;

		$switched = false;
		$started  = microtime( true );
		$cleanup_staged_file = false;

		try {
			if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() !== $blog_id ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
			if ( $user_id > 0 && function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $user_id );
			}

			$bridge = self::instance();
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — prefer staged async
			// file payload when available; fallback attachment_id path remains intact.
			$bridge->hydrate_bridge_async_file_payload( $ingest_payload );
			$bridge->bridge_log( 'ingest_item_started', array_merge( $item_ctx, array(
				'dispatch_job_id' => $job_id,
				'dispatch_mode'   => 'async_cron',
			) ) );

			if ( $notebook_id <= 0 || $user_id <= 0 || empty( $ingest_payload ) || ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
				$bridge->mark_bridge_async_placeholder_failed(
					$ingest_payload,
					'Notebook bridge async payload invalid.',
					'notebook_bridge_async_payload_invalid'
				);
				$bridge->sync_journal_learning_status( $journal_entry_id, $user_id, 'failed', 'notebook_bridge_async_payload_invalid', $ingest_payload );
				$bridge->bridge_log( 'ingest_item_failed', array_merge( $item_ctx, array(
					'dispatch_job_id' => $job_id,
					'dispatch_mode'   => 'async_cron',
					'status'          => 'failed',
					'reason'          => 'notebook_bridge_async_payload_invalid',
					'duration_ms'     => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
				) ), 'error' );
				return;
			}

			$res = BizCity_TwinChat_Sources_Service::instance()->ingest( $notebook_id, $user_id, $ingest_payload );
			if ( is_wp_error( $res ) ) {
				$bridge->mark_bridge_async_placeholder_failed(
					$ingest_payload,
					(string) $res->get_error_message(),
					$bridge->extract_error_code( $res )
				);
				$bridge->sync_journal_learning_status( $journal_entry_id, $user_id, 'failed', $bridge->extract_error_code( $res ), $ingest_payload );
				$bridge->bridge_log( 'ingest_item_failed', array_merge( $item_ctx, array(
					'dispatch_job_id' => $job_id,
					'dispatch_mode'   => 'async_cron',
					'status'          => 'failed',
					'reason'          => $bridge->extract_error_code( $res ),
					'duration_ms'     => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
				) ), 'warn' );
				return;
			}

			$source_id = (int) ( $res['source_id'] ?? 0 );
			if ( ! empty( $res['duplicate'] ) ) {
				$bridge->mark_bridge_async_placeholder_duplicate( $ingest_payload, $res );
			} else {
				$bridge->mark_bridge_async_placeholder_state( $ingest_payload, 'done' );
			}
			$cleanup_staged_file = true;
			if ( $source_id > 0 && ! empty( $inbound ) && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
				$capture_meta = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
				$capture_meta['kg_source_id'] = (int) ( $res['kg_source_id'] ?? 0 );
				$bridge->stamp_inbound_provenance( $source_id, $inbound, $capture_meta );
			}
			$bridge->sync_journal_learning_status( $journal_entry_id, $user_id, 'learned', '', array_merge( $ingest_payload, array(
				'metadata' => array_merge(
					isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array(),
					array( 'kg_source_id' => (int) ( $res['kg_source_id'] ?? 0 ) )
				),
			) ) );

			$bridge->bridge_log( 'ingest_item_done', array_merge( $item_ctx, array(
				'dispatch_job_id' => $job_id,
				'dispatch_mode'   => 'async_cron',
				'source_id'       => $source_id,
				'kg_source_id'    => (int) ( $res['kg_source_id'] ?? 0 ),
				'status'          => ! empty( $res['duplicate'] ) ? 'duplicate' : 'ok',
				'duration_ms'     => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
			) ) );
		} catch ( \Throwable $e ) {
			self::instance()->mark_bridge_async_placeholder_failed(
				$ingest_payload,
				(string) $e->getMessage(),
				'notebook_bridge_async_exception'
			);
			self::instance()->bridge_log( 'ingest_item_failed', array_merge( $item_ctx, array(
				'dispatch_job_id' => $job_id,
				'dispatch_mode'   => 'async_cron',
				'status'          => 'failed',
				'reason'          => 'notebook_bridge_async_exception',
				'error_class'     => get_class( $e ),
				'duration_ms'     => (int) max( 0, round( ( microtime( true ) - $started ) * 1000 ) ),
			) ), 'error' );
			self::instance()->sync_journal_learning_status( $journal_entry_id, $user_id, 'failed', 'notebook_bridge_async_exception', $ingest_payload );
		} finally {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — free staged copy only
			// after success/duplicate so failed sources remain retryable.
			if ( $cleanup_staged_file ) {
				self::instance()->maybe_cleanup_bridge_async_file( $ingest_payload );
			}
			if ( $switched && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Mirror async KG worker state to the canonical Journal Entry.
	 */
	private function sync_journal_learning_status( int $journal_entry_id, int $user_id, string $status, string $error_code = '', array $payload = array() ): void {
		if ( $journal_entry_id <= 0 || $user_id <= 0 || ! class_exists( 'BizCity_Journal_Database' ) ) {
			return;
		}
		BizCity_Journal_Database::maybe_install();
		$metadata = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : array();
		BizCity_Journal_Database::instance()->mark_learning_projection(
			$journal_entry_id,
			$user_id,
			array(
				'learning_status' => $status,
				'learning_error'  => $error_code,
				'notebook_id'     => (int) ( $payload['notebook_id'] ?? 0 ),
				'kg_source_id'    => (int) ( $metadata['kg_source_id'] ?? 0 ),
				'metadata'        => $metadata,
			)
		);
	}

	/**
	 * Detect the "@notebook ..." capture command inside free text.
	 *
	 * Accepted shapes:
	 *   "@notebook hop sale: nội dung cuộc họp..."  → title + content split by ':'
	 *   "@notebook hop sale"                        → title == content (image/file caption)
	 *   "@notebook"                                  → empty title/content (caller derives)
	 *
	 * @return array{title:string,content:string}|null null when no command found.
	 */
	public static function parse_capture_command( string $text ): ?array {
		$text = trim( (string) $text );
		if ( $text === '' ) {
			return null;
		}
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R5 — "@ghichu" used to be a
		// SEPARATE automation-workflow keyword (tpl_zalo_capture_to_notebook_v1)
		// that bypassed this listener's session/confirm-more-files flow
		// entirely, losing any attachment queued in THIS bridge's own inbox
		// (automation only reads BizCity_Automation_Pending_State). "@ghichu"
		// is now a first-class alias of "@notebook" so every capture — no
		// matter which marker the user types — goes through the SAME
		// inbox/session/confirm state machine. Filterable for future aliases.
		$markers = (array) apply_filters( 'bizcity_kg_notebook_bridge_trigger_markers', array( 'notebook', 'ghichu', 'ghi chú', 'ghi-chu' ) );
		$markers = array_values( array_filter( array_map( 'strval', $markers ) ) );
		if ( empty( $markers ) ) {
			$markers = array( 'notebook' );
		}
		$alt = implode( '|', array_map( static function ( $m ) {
			return preg_quote( $m, '/' );
		}, $markers ) );
		if ( ! preg_match( '/@(?:' . $alt . ')\b\s*(.*)$/isu', $text, $m ) ) {
			return null;
		}
		$rest = trim( (string) $m[1] );
		if ( $rest === '' ) {
			return array( 'title' => '', 'content' => '' );
		}
		if ( strpos( $rest, ':' ) !== false ) {
			$parts   = explode( ':', $rest, 2 );
			$title   = trim( $parts[0] );
			$content = trim( $parts[1] );
			if ( $title === '' ) {
				$title = $content;
			}
		} else {
			$title   = $rest;
			$content = $rest;
		}
		return array( 'title' => $title, 'content' => $content );
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — MULTI-FILE CAPTURE.
	 * Two small state stores back the "send files first, or trigger first,
	 * either way confirm 'thêm files không?' before saving" conversation:
	 *
	 * 1) INBOX (list) — attachments arriving BEFORE any "@notebook" marker is
	 *    seen (Zalo voice never carries one; images/files may or may not).
	 *    Unlike the old single-slot buffer this replaces, MULTIPLE files can
	 *    queue up here (e.g. 3 photos sent back-to-back) until a later
	 *    "@notebook <title>" claims the whole inbox at once via
	 *    `start_capture_session()`.
	 *
	 * 2) SESSION — opened the moment a "@notebook" trigger is seen. Holds the
	 *    title/content plus every attachment collected so far (draining the
	 *    inbox on start, then accepting more via `append_session_attachment()`
	 *    while `awaiting_more=true`). A channel listener flips
	 *    `awaiting_more` off (via `end_capture_session()`) once the user
	 *    answers "không"/"xong", then calls `capture()`'s batch sibling to
	 *    ingest everything collected into ONE notebook in one shot.
	 *
	 * Both stores are dedicated, own-prefixed transients — deliberately NOT
	 * `BizCity_Automation_Pending_State` (a different feature's slot store;
	 * reusing it risks corrupting an unrelated in-flight automation flow).
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — callers MAY pass
	 * `$automation_chat_id` (the fully-formatted id that store expects, e.g.
	 * `zalobot_{bot_id}_{group_|private_}{chat_id}`) to ALSO best-effort
	 * mirror the attachment there, so other automation workflows never lose a
	 * file that landed in THIS inbox first. Building that id is the CALLER's
	 * responsibility (this class is channel-agnostic and has no bot_id).
	 *
	 * @param string $channel  e.g. 'zalobot'.
	 * @param string $chat_key Provider-level conversation id.
	 */
	public static function queue_pending_attachment( string $channel, string $chat_key, array $attachment, int $ttl = self::PENDING_TTL_DEFAULT, string $automation_chat_id = '' ): void {
		if ( $channel === '' || $chat_key === '' || empty( $attachment['url'] ) ) {
			return;
		}
		$key  = self::inbox_key( $channel, $chat_key );
		$list = get_transient( $key );
		$list = is_array( $list ) ? $list : array();
		$attachment['received_at'] = time();
		$list[] = $attachment;
		if ( count( $list ) > 20 ) {
			$list = array_slice( $list, -20 ); // hard ceiling — avoid unbounded transient growth from a runaway sender.
		}
		set_transient( $key, $list, max( 30, $ttl ) );
		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — mirror into the generic
		// automation slot store too (best-effort, only when the caller supplied
		// a properly-formatted id) so ANY automation workflow that pulls
		// `BizCity_Automation_Pending_State` (e.g. action.capture_to_notebook
		// triggered by a keyword workflow) can still see files that arrived
		// BEFORE the trigger, instead of silently losing them.
		self::mirror_attachment_to_automation_pending_state( $automation_chat_id, $attachment );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R4 — best-effort dual-write so the
	 * bridge's own inbox/session and the generic automation slot store never
	 * diverge. Never throws; a failure here must not block the native capture
	 * flow, which does not depend on this mirror to function. No-op when
	 * `$automation_chat_id` is blank (caller could not resolve one).
	 */
	public static function mirror_attachment_to_automation_pending_state( string $automation_chat_id, array $attachment ): void {
		if ( $automation_chat_id === '' || ! class_exists( 'BizCity_Automation_Pending_State' ) ) {
			return;
		}
		try {
			BizCity_Automation_Pending_State::append_attachment( $automation_chat_id, $attachment );
		} catch ( \Throwable $e ) {
			// swallow — mirror is best-effort, never fatal to the capture UX.
		}
	}

	/**
	 * Read + clear every attachment queued in the pre-trigger inbox. Returns
	 * `[]` (never null) so callers can `array_merge()` unconditionally.
	 */
	public static function drain_pending_attachments( string $channel, string $chat_key ): array {
		if ( $channel === '' || $chat_key === '' ) {
			return array();
		}
		$key  = self::inbox_key( $channel, $chat_key );
		$list = get_transient( $key );
		delete_transient( $key );
		return is_array( $list ) ? $list : array();
	}

	/** How many attachments are currently queued (0 if none) — used to only prompt once per burst. */
	public static function peek_pending_attachment_count( string $channel, string $chat_key ): int {
		$list = get_transient( self::inbox_key( $channel, $chat_key ) );
		return is_array( $list ) ? count( $list ) : 0;
	}

	/**
	 * Start (or restart) a capture session for a conversation. Automatically
	 * folds in anything already sitting in the pre-trigger inbox.
	 *
	 * @param array $session { user_id, chat_kind, title_hint, content, inbound_base, text_message_id, attachments?:array }
	 * @return array the persisted session (with `attachments` normalized).
	 */
	public static function start_capture_session( string $channel, string $chat_key, array $session, int $ttl = self::PENDING_TTL_DEFAULT ): array {
		$session['attachments'] = isset( $session['attachments'] ) && is_array( $session['attachments'] ) ? $session['attachments'] : array();
		$queued = self::drain_pending_attachments( $channel, $chat_key );
		if ( ! empty( $queued ) ) {
			$session['attachments'] = array_merge( $session['attachments'], $queued );
		}
		$session['started_at']    = time();
		$session['awaiting_more'] = ! empty( $session['awaiting_more'] );
		$_key    = self::session_key( $channel, $chat_key );
		$_ok     = set_transient( $_key, $session, max( 60, $ttl ) );
		// [2026-07-26 Johnny Chu] HOTFIX-DIAG — trace session persistence so a
		// silent "không"/"xong" reply that never finalizes can be told apart
		// from "session never found" vs "session found but write failed".
		error_log( sprintf(
			'[NotebookBridge][session] start_capture_session blog_id=%d key=%s awaiting_more=%s set_transient_ok=%s',
			(int) get_current_blog_id(),
			$_key,
			$session['awaiting_more'] ? 'yes' : 'no',
			$_ok ? 'yes' : 'no'
		) );
		return $session;
	}

	/** @return array|null the active session, or null if none/expired. */
	public static function get_capture_session( string $channel, string $chat_key ): ?array {
		$_key    = self::session_key( $channel, $chat_key );
		$session = get_transient( $_key );
		// [2026-07-26 Johnny Chu] HOTFIX-DIAG — see start_capture_session() trace;
		// confirms whether the transient written on the PRIOR request round-trips
		// back on THIS request (multisite/multishard cache/DB routing suspect).
		error_log( sprintf(
			'[NotebookBridge][session] get_capture_session blog_id=%d key=%s found=%s awaiting_more=%s',
			(int) get_current_blog_id(),
			$_key,
			is_array( $session ) ? 'yes' : 'no',
			( is_array( $session ) && ! empty( $session['awaiting_more'] ) ) ? 'yes' : 'no'
		) );
		return is_array( $session ) ? $session : null;
	}

	/** Append one more attachment to an ALREADY-open session; no-op (returns null) if none is open. */
	public static function append_session_attachment( string $channel, string $chat_key, array $attachment, int $ttl = self::PENDING_TTL_DEFAULT, string $automation_chat_id = '' ): ?array {
		$session = self::get_capture_session( $channel, $chat_key );
		if ( $session === null ) {
			return null;
		}
		$attachment['received_at'] = time();
		$session['attachments'][]  = $attachment;
		set_transient( self::session_key( $channel, $chat_key ), $session, max( 60, $ttl ) );
		self::mirror_attachment_to_automation_pending_state( $automation_chat_id, $attachment );
		return $session;
	}

	/** Flip the "waiting for more files?" flag and refresh the session TTL. */
	public static function set_session_awaiting_more( string $channel, string $chat_key, bool $awaiting, int $ttl = self::PENDING_TTL_DEFAULT ): ?array {
		$session = self::get_capture_session( $channel, $chat_key );
		if ( $session === null ) {
			// [2026-07-26 Johnny Chu] HOTFIX-DIAG — this bail is the exact
			// point that would explain a session created moments earlier
			// (start_capture_session) failing to be found here already,
			// inside the SAME request that just created it.
			error_log( sprintf(
				'[NotebookBridge][session] set_session_awaiting_more bail: get_capture_session returned null key=%s',
				self::session_key( $channel, $chat_key )
			) );
			return null;
		}
		$session['awaiting_more'] = $awaiting;
		$_ok = set_transient( self::session_key( $channel, $chat_key ), $session, max( 60, $ttl ) );
		error_log( sprintf(
			'[NotebookBridge][session] set_session_awaiting_more blog_id=%d key=%s awaiting=%s set_transient_ok=%s',
			(int) get_current_blog_id(),
			self::session_key( $channel, $chat_key ),
			$awaiting ? 'yes' : 'no',
			$_ok ? 'yes' : 'no'
		) );
		return $session;
	}

	/** Close out a session (call BEFORE finalizing the batch, so a duplicate reply never re-finalizes it). */
	public static function end_capture_session( string $channel, string $chat_key ): void {
		delete_transient( self::session_key( $channel, $chat_key ) );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — "Dạng 2" instant upload-link
	 * flow: user issues "@ghichu" FIRST (opening a session), then opens an
	 * upload link that should mount the eventual file directly against a
	 * KNOWN notebook_id (so the user gets "đã gắn vào notebook X" feedback
	 * immediately, and so uploaded attachments can be postmeta-tagged with
	 * `_bizcity_kg_notebook_id` right away instead of waiting for finalize).
	 *
	 * Idempotent: once a session has a `notebook_id`, subsequent calls just
	 * return it. `find_or_create_daily_notebook()`'s own settings-based
	 * matching (channel+day_key+capture_title+scope) guarantees the LATER
	 * `capture_batch()` call at finalize time reuses this SAME row (never a
	 * duplicate) as long as the session's `title_hint` does not change in
	 * between — which it never does today (finalize reads the same
	 * `session['title_hint']` this method does).
	 *
	 * @return int notebook_id, or 0 if no session is open / creation failed.
	 */
	public function ensure_session_notebook( string $channel, string $chat_key, array $base_envelope ): int {
		$session = self::get_capture_session( $channel, $chat_key );
		if ( $session === null ) {
			return 0;
		}
		if ( ! empty( $session['notebook_id'] ) ) {
			return (int) $session['notebook_id'];
		}
		$user_id = (int) ( $base_envelope['user_id'] ?? $session['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$day_key       = ! empty( $base_envelope['day_key'] ) ? sanitize_key( (string) $base_envelope['day_key'] ) : current_time( 'Ymd' );
		$title_hint_in = trim( (string) ( $session['title_hint'] ?? $base_envelope['title_hint'] ?? '' ) );
		$title_meta    = $this->resolve_capture_title( $title_hint_in, array() );
		$title_hint    = (string) $title_meta['title_hint'];
		$title_slug    = (string) $title_meta['title_slug'];
		$scope         = $this->resolve_capture_scope( $base_envelope, $user_id );

		$nb = $this->find_or_create_daily_notebook( $user_id, $channel, $day_key, $title_slug, $title_hint, $scope );
		if ( is_wp_error( $nb ) ) {
			return 0;
		}
		$notebook_id             = (int) $nb['id'];
		$session['notebook_id'] = $notebook_id;
		set_transient( self::session_key( $channel, $chat_key ), $session, self::PENDING_TTL_DEFAULT );
		return $notebook_id;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — list every WP Media attachment
	 * tagged with `_bizcity_kg_notebook_id` for a given notebook (files
	 * uploaded via the instant upload-link flow, or any other capture path
	 * that goes through `build_ingest_payload()`'s file branch below). Backend
	 * capability only — surfacing this in UI can reuse the existing Learning
	 * Log / SmartSourcesPanel per-notebook source list, which already lists
	 * every ingested file via `webchat_sources`; this is an auxiliary index
	 * for direct WP Media queries (e.g. admin/debug tooling), not a second
	 * source of truth for what got captured.
	 *
	 * @return int[] attachment ids, newest first.
	 */
	public static function list_notebook_attachments( int $notebook_id ): array {
		if ( $notebook_id <= 0 ) {
			return array();
		}
		$ids = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_bizcity_kg_notebook_id',
					'value' => $notebook_id,
				),
			),
		) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	private static function inbox_key( string $channel, string $chat_key ): string {
		return 'bizcity_kg_nb_inbox_' . md5( $channel . '|' . $chat_key );
	}

	private static function session_key( string $channel, string $chat_key ): string {
		return 'bizcity_kg_nb_session_' . md5( $channel . '|' . $chat_key );
	}

	/**
	 * Main entry point — every channel/surface calls this SAME method.
	 *
	 * @param array $envelope {
	 *   user_id:int         REQUIRED. Resolved WP user id (never guest/0).
	 *   channel:string      REQUIRED. 'zalobot'|'telegram'|'messenger'|'webchat'|'twinweb'|'twinchat'.
	 *   chat_id:string      REQUIRED. Canonical/provider chat id for progress replies.
	 *   chat_kind:?string   Optional. 'group'|'private' used for notebook scope isolation.
	 *   provider_chat_id:?string Optional provider-level conversation id.
	 *   scope_type:?string Optional explicit scope override ('group'|'private').
	 *   scope_id:?string    Optional explicit scope id (group id or private conversation id).
	 *   title_hint:string   Free text after the capture command → becomes title_slug.
	 *   day_key:?string     Ymd, defaults to current site-local date.
	 *   kind:string         'text'|'image'|'file'.
	 *   content:?string     Raw text when kind=text.
	 *   attachment:?array   { kind, url, source_url, attachment_id? }.
	 *   inbound:array       R-SCH-REPLY inbound{} block (platform, chat_id, user_id, account_id, message_id).
	 * }
	 * @return array|WP_Error { notebook_id, notebook_name, notebook_created, source_id, duplicate }
	 */
	public function capture( array $envelope ) {
		$user_id = (int) ( $envelope['user_id'] ?? 0 );
		$channel = sanitize_key( (string) ( $envelope['channel'] ?? '' ) );
		if ( $user_id <= 0 || $channel === '' ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — structured fail evidence for invalid identity/channel before any DB write.
			$this->bridge_log( 'capture_received', array(
				'channel' => $channel,
				'user_id' => $user_id,
				'status'  => 'failed',
				'reason'  => 'notebook_bridge_invalid_identity',
			), 'error' );
			return new WP_Error( 'notebook_bridge_invalid_identity', 'Thiếu định danh user_id hoặc channel — không thể lưu vào não.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) || ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — fail-closed dependency evidence for automation parsing.
			$this->bridge_log( 'capture_received', array(
				'channel' => $channel,
				'user_id' => $user_id,
				'status'  => 'failed',
				'reason'  => 'notebook_bridge_deps_missing',
			), 'error' );
			return new WP_Error( 'notebook_bridge_deps_missing', 'Dịch vụ Notebook/KG chưa sẵn sàng trên site này.', array( 'status' => 500 ) );
		}

		$day_key       = ! empty( $envelope['day_key'] ) ? sanitize_key( (string) $envelope['day_key'] ) : current_time( 'Ymd' );
		$title_hint_in = trim( (string) ( $envelope['title_hint'] ?? '' ) );
		$kind          = sanitize_key( (string) ( $envelope['kind'] ?? 'text' ) );
		$title_item    = array(
			'kind'       => $kind,
			'content'    => (string) ( $envelope['content'] ?? '' ),
			'attachment' => is_array( $envelope['attachment'] ?? null ) ? $envelope['attachment'] : array(),
		);
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — avoid using trigger words
		// (@ghichu/@notebook) as notebook title; summarize with Gemini Flash.
		$title_meta = $this->resolve_capture_title( $title_hint_in, array( $title_item ) );
		$title_hint = (string) $title_meta['title_hint'];
		$title_slug = (string) $title_meta['title_slug'];
		$scope      = $this->resolve_capture_scope( $envelope, $user_id );
		$message_id = (string) ( $envelope['inbound']['message_id'] ?? '' );
		$dedup_url  = '';
		if ( $kind !== 'text' ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.5 — same Zalo message_id can
			// carry caption+media; include provider_url to avoid deduping image/file
			// against the text item from the same inbound event.
			$dedup_attachment = is_array( $envelope['attachment'] ?? null ) ? $envelope['attachment'] : array();
			$dedup_url        = (string) ( $dedup_attachment['source_url'] ?? $dedup_attachment['url'] ?? '' );
		}

		$capture_ctx = array(
			'channel'       => $channel,
			'user_id'       => $user_id,
			'day_key'       => $day_key,
			'scope_type'    => (string) ( $scope['scope_type'] ?? 'private' ),
			'scope_id'      => (string) ( $scope['scope_id'] ?? '' ),
			'capture_title' => $title_slug,
			'message_id'    => $message_id,
			'item_kind'     => $kind,
			'batch_total'   => 1,
			'item_index'    => 0,
		);
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — first lifecycle checkpoint (file-log first) for capture().
		$this->bridge_log( 'capture_received', $capture_ctx );

		$dup = $this->find_existing_source_by_message_id( $user_id, $channel, $message_id, $scope, $kind, $dedup_url );
		if ( is_array( $dup ) && ! empty( $dup['source_id'] ) ) {
			$notebook_id   = (int) ( $dup['notebook_id'] ?? 0 );
			$notebook_name = (string) ( $dup['notebook_name'] ?? '' );
			$this->bridge_log( 'ingest_item_done', array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => $notebook_name,
				'source_id'     => (int) $dup['source_id'],
				'status'        => 'duplicate',
			) ) );
			$this->bridge_log( 'capture_batch_done', array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => $notebook_name,
				'succeeded'     => 1,
				'failed'        => 0,
				'status'        => 'ok',
			) ) );
			return array(
				'notebook_id'      => $notebook_id,
				'notebook_name'    => $notebook_name,
				'notebook_created' => false,
				'source_id'        => (int) $dup['source_id'],
				'duplicate'        => true,
			);
		}

		$source_meta = array(
			'channel'       => $channel,
			'day_key'       => $day_key,
			'capture_title' => $title_slug,
			'scope_type'    => (string) ( $scope['scope_type'] ?? 'private' ),
			'scope_id'      => (string) ( $scope['scope_id'] ?? '' ),
			'chat_id'       => (string) ( $envelope['chat_id'] ?? '' ),
			'message_id'    => $message_id,
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — surface `kind` on the
			// source's own metadata so the Progress Notifier can render
			// modality-aware step 2/3 copy ("ghi âm"/"ảnh"/"tài liệu") instead
			// of always saying generic "nội dung".
			'kind'          => $kind,
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 BUGFIX — stamp inbound{}
			// into metadata BEFORE ingest() runs, not only afterward via
			// stamp_inbound_provenance(). ingest() is confirmed synchronous
			// (@set_time_limit(0), no cron hop) and fires
			// bizcity_kg_source_embedded from INSIDE the call — the progress
			// notifier's step-2 hook reads the row at THAT moment, so it can
			// only see `inbound` if it was already in the very first INSERT.
			'inbound'       => (array) ( $envelope['inbound'] ?? array() ),
		);
		$svc  = BizCity_TwinChat_Sources_Service::instance();
		$ingest_payload = $this->build_ingest_payload( $kind, $title_hint, $envelope, $source_meta );
		if ( is_wp_error( $ingest_payload ) ) {
			$this->bridge_log( 'ingest_item_failed', array_merge( $capture_ctx, array(
				'status' => 'failed',
				'reason' => $this->extract_error_code( $ingest_payload ),
			) ), 'warn' );
			return $ingest_payload;
		}

		$nb = $this->find_or_create_daily_notebook( $user_id, $channel, $day_key, $title_slug, $title_hint, $scope );
		if ( is_wp_error( $nb ) ) {
			$this->bridge_log( 'notebook_resolved', array_merge( $capture_ctx, array(
				'status' => 'failed',
				'reason' => $this->extract_error_code( $nb ),
			) ), 'error' );
			return $nb;
		}
		$notebook_id = (int) $nb['id'];
		$this->bridge_log( 'notebook_resolved', array_merge( $capture_ctx, array(
			'notebook_id'      => $notebook_id,
			'notebook_name'    => (string) ( $nb['name'] ?? '' ),
			'notebook_created' => ! empty( $nb['_created'] ),
			'status'           => 'ok',
		) ) );
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — index every attached WP Media
		// file by notebook_id so files can be listed per-notebook directly from
		// the Media Library (see list_notebook_attachments()).
		$this->maybe_tag_attachment_notebook_meta( $ingest_payload, $notebook_id );

		$ingest_started_at = microtime( true );
		$this->bridge_log( 'ingest_item_started', array_merge( $capture_ctx, array(
			'notebook_id'   => $notebook_id,
			'notebook_name' => (string) ( $nb['name'] ?? '' ),
		) ) );

		if ( $this->should_dispatch_async_ingest( $kind, $envelope, $ingest_payload ) ) {
			$item_ctx = array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
			) );
			$queue = $this->enqueue_async_capture_ingest(
				$notebook_id,
				$user_id,
				$ingest_payload,
				$item_ctx,
				(array) ( $envelope['inbound'] ?? array() )
			);
			if ( is_wp_error( $queue ) ) {
				$this->bridge_log( 'ingest_item_failed', array_merge( $capture_ctx, array(
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
					'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
					'status'        => 'failed',
					'reason'        => $this->extract_error_code( $queue ),
				) ), 'warn' );
				$this->bridge_log( 'capture_batch_done', array_merge( $capture_ctx, array(
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
					'succeeded'     => 0,
					'queued'        => 0,
					'failed'        => 1,
					'status'        => 'failed',
					'reason'        => $this->extract_error_code( $queue ),
				) ), 'warn' );
				return $queue;
			}
			$this->bridge_log( 'ingest_item_queued', array_merge( $capture_ctx, array(
				'notebook_id'      => $notebook_id,
				'notebook_name'    => (string) ( $nb['name'] ?? '' ),
				'dispatch_job_id'  => (string) ( $queue['job_id'] ?? '' ),
				'source_id'        => (int) ( $queue['source_id'] ?? 0 ),
				'kg_source_id'     => (int) ( $queue['kg_source_id'] ?? 0 ),
				'dispatch_mode'    => 'async_cron',
				'status'           => 'queued',
			) ) );
			$this->bridge_log( 'capture_batch_done', array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
				'succeeded'     => 0,
				'queued'        => 1,
				'failed'        => 0,
				'status'        => 'queued',
			) ) );
			return array(
				'notebook_id'      => $notebook_id,
				'notebook_name'    => (string) $nb['name'],
				'notebook_created' => (bool) $nb['_created'],
				'source_id'        => (int) ( $queue['source_id'] ?? 0 ),
				'kg_source_id'     => (int) ( $queue['kg_source_id'] ?? 0 ),
				'duplicate'        => false,
				'queued'           => 1,
				'queued_jobs'      => array( (string) ( $queue['job_id'] ?? '' ) ),
			);
		}

		$res = $svc->ingest( $notebook_id, $user_id, $ingest_payload );

		if ( is_wp_error( $res ) ) {
			$this->bridge_log( 'ingest_item_failed', array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
				'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
				'status'        => 'failed',
				'reason'        => $this->extract_error_code( $res ),
			) ), 'warn' );
			$this->bridge_log( 'capture_batch_done', array_merge( $capture_ctx, array(
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
				'succeeded'     => 0,
				'failed'        => 1,
				'status'        => 'failed',
				'reason'        => $this->extract_error_code( $res ),
			) ), 'warn' );
			return $res;
		}

		$source_id = (int) ( $res['source_id'] ?? 0 );
		$this->bridge_log( 'ingest_item_done', array_merge( $capture_ctx, array(
			'notebook_id'   => $notebook_id,
			'notebook_name' => (string) ( $nb['name'] ?? '' ),
			'source_id'     => $source_id,
			'kg_source_id'  => (int) ( $res['kg_source_id'] ?? 0 ),
			'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
			'status'        => ! empty( $res['duplicate'] ) ? 'duplicate' : 'ok',
		) ) );
		if ( $source_id > 0 && ! empty( $envelope['inbound'] ) && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			// [2026-07-24 Johnny Chu] PHASE-0.46 W1 PROGRESS — stash kg_source_id so
			// BizCity_KG_Channel_Progress_Notifier can look up passage extraction_status
			// for this exact source without re-deriving the legacy↔kg id mapping.
			$capture_meta = $source_meta;
			$capture_meta['kg_source_id'] = (int) ( $res['kg_source_id'] ?? 0 );
			$this->stamp_inbound_provenance( $source_id, (array) $envelope['inbound'], $capture_meta );
		}
		$this->bridge_log( 'capture_batch_done', array_merge( $capture_ctx, array(
			'notebook_id'   => $notebook_id,
			'notebook_name' => (string) ( $nb['name'] ?? '' ),
			'source_id'     => $source_id,
			'kg_source_id'  => (int) ( $res['kg_source_id'] ?? 0 ),
			'succeeded'     => 1,
			'queued'        => 0,
			'failed'        => 0,
			'status'        => 'ok',
		) ) );

		return array(
			'notebook_id'      => $notebook_id,
			'notebook_name'    => (string) $nb['name'],
			'notebook_created' => (bool) $nb['_created'],
			'source_id'        => $source_id,
			'duplicate'        => (bool) ( $res['duplicate'] ?? false ),
		);
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 — MULTI-FILE BATCH CAPTURE.
	 * Creates (or reuses) ONE notebook, then ingests N items (each item is a
	 * text note OR an attachment) into it in one shot — the "capture session"
	 * finalize path (files sent before/after the "@notebook" trigger, all
	 * confirmed via "gửi thêm files không?" first).
	 *
	 * Every successfully-ingested item's metadata carries `batch_id` +
	 * `batch_total` so `BizCity_KG_Channel_Progress_Notifier` can send exactly
	 * ONE aggregated "đã học xong" message for the whole batch instead of one
	 * per file (step 2 is NOT hook-driven for batches at all — ingest() is
	 * synchronous, so the caller sends the combined "đã tải + đã embedding"
	 * reply itself right after this method returns).
	 *
	 * @param array $base_envelope { user_id, channel, chat_id, chat_kind?, provider_chat_id?, scope_type?, scope_id?, title_hint?, day_key?, inbound }
	 * @param array $items list of { kind:string, content?:string, attachment?:array, title_hint?:string, message_id?:string }
	 * @return array|WP_Error { notebook_id, notebook_name, notebook_created, batch_id, total, succeeded, items:array, failed:array }
	 */
	public function capture_batch( array $base_envelope, array $items ) {
		$user_id = (int) ( $base_envelope['user_id'] ?? 0 );
		$channel = sanitize_key( (string) ( $base_envelope['channel'] ?? '' ) );
		if ( $user_id <= 0 || $channel === '' ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — structured fail evidence before returning fail-closed.
			$this->bridge_log( 'capture_received', array(
				'channel' => $channel,
				'user_id' => $user_id,
				'status'  => 'failed',
				'reason'  => 'notebook_bridge_invalid_identity',
			), 'error' );
			return new WP_Error( 'notebook_bridge_invalid_identity', 'Thiếu định danh user_id hoặc channel — không thể lưu vào não.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) || ! class_exists( 'BizCity_TwinChat_Sources_Service' ) ) {
			$this->bridge_log( 'capture_received', array(
				'channel' => $channel,
				'user_id' => $user_id,
				'status'  => 'failed',
				'reason'  => 'notebook_bridge_deps_missing',
			), 'error' );
			return new WP_Error( 'notebook_bridge_deps_missing', 'Dịch vụ Notebook/KG chưa sẵn sàng trên site này.', array( 'status' => 500 ) );
		}
		$items = array_values( array_filter( $items, 'is_array' ) );
		if ( empty( $items ) ) {
			$this->bridge_log( 'capture_received', array(
				'channel' => $channel,
				'user_id' => $user_id,
				'status'  => 'failed',
				'reason'  => 'notebook_bridge_empty_batch',
			), 'warn' );
			return new WP_Error( 'notebook_bridge_empty_batch', 'Không có nội dung/tệp nào để lưu.', array( 'status' => 400 ) );
		}

		$day_key       = ! empty( $base_envelope['day_key'] ) ? sanitize_key( (string) $base_envelope['day_key'] ) : current_time( 'Ymd' );
		$title_hint_in = trim( (string) ( $base_envelope['title_hint'] ?? '' ) );
		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — summarize batch title using
		// compact LLM naming so captions/trigger words become concise notebook names.
		$title_meta = $this->resolve_capture_title( $title_hint_in, $items );
		$title_hint = (string) $title_meta['title_hint'];
		$title_slug = (string) $title_meta['title_slug'];
		$scope      = $this->resolve_capture_scope( $base_envelope, $user_id );
		$inbound_base = (array) ( $base_envelope['inbound'] ?? array() );
		$batch_id    = wp_generate_uuid4();
		$batch_total = count( $items );

		$batch_ctx = array(
			'channel'       => $channel,
			'user_id'       => $user_id,
			// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — preserve the
			// canonical Journal authoring row through KG projection logs.
			'journal_entry_id' => (int) ( $base_envelope['journal_entry_id'] ?? 0 ),
			'day_key'       => $day_key,
			'scope_type'    => (string) ( $scope['scope_type'] ?? 'private' ),
			'scope_id'      => (string) ( $scope['scope_id'] ?? '' ),
			'capture_title' => $title_slug,
			'batch_id'      => $batch_id,
			'batch_total'   => $batch_total,
		);
		$this->bridge_log( 'capture_received', $batch_ctx );

		$nb = $this->find_or_create_daily_notebook( $user_id, $channel, $day_key, $title_slug, $title_hint, $scope );
		if ( is_wp_error( $nb ) ) {
			$this->bridge_log( 'notebook_resolved', array_merge( $batch_ctx, array(
				'status' => 'failed',
				'reason' => $this->extract_error_code( $nb ),
			) ), 'error' );
			return $nb;
		}
		$notebook_id = (int) $nb['id'];
		$this->bridge_log( 'notebook_resolved', array_merge( $batch_ctx, array(
			'notebook_id'      => $notebook_id,
			'notebook_name'    => (string) ( $nb['name'] ?? '' ),
			'notebook_created' => ! empty( $nb['_created'] ),
			'status'           => 'ok',
		) ) );
		$svc         = BizCity_TwinChat_Sources_Service::instance();
		$succeeded   = array();
		$queued      = array();
		$failed      = array();

		foreach ( $items as $idx => $item ) {
			$item_kind        = sanitize_key( (string) ( $item['kind'] ?? 'text' ) );
			$item_title_hint  = trim( (string) ( $item['title_hint'] ?? $title_hint ) );
			$item_message_id  = (string) ( $item['message_id'] ?? '' );
			$item_attachment  = is_array( $item['attachment'] ?? null ) ? $item['attachment'] : array();
			$item_file_name   = trim( (string) ( $item_attachment['file_name'] ?? '' ) );
			$item_dedup_url   = '';
			if ( $item_kind !== 'text' ) {
				// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.5 — avoid false duplicate
				// when one inbound carries both caption text and media.
				$item_dedup_url  = (string) ( $item_attachment['source_url'] ?? $item_attachment['url'] ?? '' );
			}

			// Per-item dedup — same as capture(), so a queued/retried
			// attachment already saved in an earlier finalize never doubles up.
			$dup = $this->find_existing_source_by_message_id( $user_id, $channel, $item_message_id, $scope, $item_kind, $item_dedup_url );
			if ( is_array( $dup ) && ! empty( $dup['source_id'] ) ) {
				$this->bridge_log( 'ingest_item_done', array_merge( $batch_ctx, array(
					'item_index'    => $idx,
					'item_kind'     => $item_kind,
					'message_id'    => $item_message_id,
					'file_name'     => $item_file_name,
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
					'source_id'     => (int) $dup['source_id'],
					'status'        => 'duplicate',
				) ) );
				$succeeded[] = array(
					'index'      => $idx,
					'kind'       => $item_kind,
					'message_id' => $item_message_id,
					'file_name'  => $item_file_name,
					'source_id'  => (int) $dup['source_id'],
					'duplicate'  => true,
				);
				continue;
			}

			$item_inbound = $inbound_base;
			if ( $item_message_id !== '' ) {
				$item_inbound['message_id'] = $item_message_id;
			}
			$source_meta = array(
				'channel'       => $channel,
				'day_key'       => $day_key,
				'capture_title' => $title_slug,
				'scope_type'    => (string) ( $scope['scope_type'] ?? 'private' ),
				'scope_id'      => (string) ( $scope['scope_id'] ?? '' ),
				'chat_id'       => (string) ( $base_envelope['chat_id'] ?? '' ),
				'message_id'    => $item_message_id,
				'journal_entry_id' => (int) ( $base_envelope['journal_entry_id'] ?? 0 ),
				// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.5 — see capture() note.
				'kind'          => $item_kind,
				'inbound'       => $item_inbound,
				'batch_id'      => $batch_id,
				'batch_total'   => $batch_total,
			);

			$ingest_started_at = microtime( true );
			$this->bridge_log( 'ingest_item_started', array_merge( $batch_ctx, array(
				'item_index'    => $idx,
				'item_kind'     => $item_kind,
				'message_id'    => $item_message_id,
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
			) ) );

			$ingest_payload = $this->build_ingest_payload( $item_kind, $item_title_hint, $item, $source_meta );
			if ( is_wp_error( $ingest_payload ) ) {
				$this->bridge_log( 'ingest_item_failed', array_merge( $batch_ctx, array(
					'item_index'    => $idx,
					'item_kind'     => $item_kind,
					'message_id'    => $item_message_id,
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
					'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
					'status'        => 'failed',
					'reason'        => $this->extract_error_code( $ingest_payload ),
				) ), 'warn' );
				$failed[] = array( 'index' => $idx, 'kind' => $item_kind, 'error' => $ingest_payload->get_error_message() );
				continue;
			}
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 — index every attached WP Media
			// file by notebook_id (see list_notebook_attachments()).
			$this->maybe_tag_attachment_notebook_meta( $ingest_payload, $notebook_id );

			if ( $this->should_dispatch_async_ingest( $item_kind, array_merge( $base_envelope, $item ), $ingest_payload ) ) {
				$item_ctx = array_merge( $batch_ctx, array(
					'item_index'    => $idx,
					'item_kind'     => $item_kind,
					'message_id'    => $item_message_id,
					'file_name'     => $item_file_name,
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
				) );
				$queue = $this->enqueue_async_capture_ingest(
					$notebook_id,
					$user_id,
					$ingest_payload,
					$item_ctx,
					$item_inbound
				);
				if ( is_wp_error( $queue ) ) {
					$queue_reason = $this->extract_error_code( $queue );
					$allow_sync_fallback = in_array( $queue_reason, array(
						'notebook_bridge_async_attachment_missing',
						'notebook_bridge_async_stage_unwritable',
						'notebook_bridge_async_file_stage_failed',
					), true );
					if ( $allow_sync_fallback ) {
						// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-6 — fail open for
						// upload-link file ingest when async staging infra fails; fallback to
						// direct ingest so the file still enters notebook instead of being
						// counted as lost in capture_batch summary.
						$sync_res = $svc->ingest( $notebook_id, $user_id, $ingest_payload );
						if ( ! is_wp_error( $sync_res ) ) {
							$source_id = (int) ( $sync_res['source_id'] ?? 0 );
							if ( $source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
								$capture_meta = $source_meta;
								$capture_meta['kg_source_id'] = (int) ( $sync_res['kg_source_id'] ?? 0 );
								$this->stamp_inbound_provenance( $source_id, $item_inbound, $capture_meta );
							}
							$this->bridge_log( 'ingest_item_done', array_merge( $batch_ctx, array(
								'item_index'    => $idx,
								'item_kind'     => $item_kind,
								'message_id'    => $item_message_id,
								'file_name'     => $item_file_name,
								'notebook_id'   => $notebook_id,
								'notebook_name' => (string) ( $nb['name'] ?? '' ),
								'source_id'     => $source_id,
								'kg_source_id'  => (int) ( $sync_res['kg_source_id'] ?? 0 ),
								'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
								'dispatch_mode' => 'sync_fallback',
								'status'        => ! empty( $sync_res['duplicate'] ) ? 'duplicate' : 'ok',
								'reason'        => $queue_reason,
							) ) );
							$succeeded[] = array(
								'index'        => $idx,
								'kind'         => $item_kind,
								'message_id'   => $item_message_id,
								'file_name'    => $item_file_name,
								'source_id'    => $source_id,
								'kg_source_id' => (int) ( $sync_res['kg_source_id'] ?? 0 ),
								'duplicate'    => (bool) ( $sync_res['duplicate'] ?? false ),
							);
							continue;
						}
					}
					$this->bridge_log( 'ingest_item_failed', array_merge( $batch_ctx, array(
						'item_index'    => $idx,
						'item_kind'     => $item_kind,
						'message_id'    => $item_message_id,
						'notebook_id'   => $notebook_id,
						'notebook_name' => (string) ( $nb['name'] ?? '' ),
						'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
						'status'        => 'failed',
						'reason'        => $queue_reason,
					) ), 'warn' );
					$failed[] = array( 'index' => $idx, 'kind' => $item_kind, 'error' => $queue->get_error_message() );
					continue;
				}
				$this->bridge_log( 'ingest_item_queued', array_merge( $batch_ctx, array(
					'item_index'      => $idx,
					'item_kind'       => $item_kind,
					'message_id'      => $item_message_id,
					'file_name'       => $item_file_name,
					'notebook_id'     => $notebook_id,
					'notebook_name'   => (string) ( $nb['name'] ?? '' ),
					'dispatch_job_id' => (string) ( $queue['job_id'] ?? '' ),
					'source_id'       => (int) ( $queue['source_id'] ?? 0 ),
					'kg_source_id'    => (int) ( $queue['kg_source_id'] ?? 0 ),
					'dispatch_mode'   => 'async_cron',
					'status'          => 'queued',
				) ) );
				$queued[] = array(
					'index'        => $idx,
					'kind'         => $item_kind,
					'message_id'   => $item_message_id,
					'file_name'    => $item_file_name,
					'job_id'       => (string) ( $queue['job_id'] ?? '' ),
					'source_id'    => (int) ( $queue['source_id'] ?? 0 ),
					'kg_source_id' => (int) ( $queue['kg_source_id'] ?? 0 ),
				);
				continue;
			}

			$res = $svc->ingest( $notebook_id, $user_id, $ingest_payload );
			if ( is_wp_error( $res ) ) {
				$this->bridge_log( 'ingest_item_failed', array_merge( $batch_ctx, array(
					'item_index'    => $idx,
					'item_kind'     => $item_kind,
					'message_id'    => $item_message_id,
					'notebook_id'   => $notebook_id,
					'notebook_name' => (string) ( $nb['name'] ?? '' ),
					'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
					'status'        => 'failed',
					'reason'        => $this->extract_error_code( $res ),
				) ), 'warn' );
				$failed[] = array( 'index' => $idx, 'kind' => $item_kind, 'error' => $res->get_error_message() );
				continue;
			}

			$source_id = (int) ( $res['source_id'] ?? 0 );
			if ( $source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
				$capture_meta = $source_meta;
				$capture_meta['kg_source_id'] = (int) ( $res['kg_source_id'] ?? 0 );
				$this->stamp_inbound_provenance( $source_id, $item_inbound, $capture_meta );
			}
			$this->bridge_log( 'ingest_item_done', array_merge( $batch_ctx, array(
				'item_index'    => $idx,
				'item_kind'     => $item_kind,
				'message_id'    => $item_message_id,
				'file_name'     => $item_file_name,
				'notebook_id'   => $notebook_id,
				'notebook_name' => (string) ( $nb['name'] ?? '' ),
				'source_id'     => $source_id,
				'kg_source_id'  => (int) ( $res['kg_source_id'] ?? 0 ),
				'duration_ms'   => (int) max( 0, round( ( microtime( true ) - $ingest_started_at ) * 1000 ) ),
				'status'        => ! empty( $res['duplicate'] ) ? 'duplicate' : 'ok',
			) ) );
			$succeeded[] = array(
				'index'        => $idx,
				'kind'         => $item_kind,
				'message_id'   => $item_message_id,
				'file_name'    => $item_file_name,
				'source_id'    => $source_id,
				'kg_source_id' => (int) ( $res['kg_source_id'] ?? 0 ),
				'duplicate'    => (bool) ( $res['duplicate'] ?? false ),
			);
		}

		$this->bridge_log( 'capture_batch_done', array_merge( $batch_ctx, array(
			'notebook_id'   => $notebook_id,
			'notebook_name' => (string) ( $nb['name'] ?? '' ),
			'succeeded'     => count( $succeeded ),
			'queued'        => count( $queued ),
			'failed'        => count( $failed ),
			'status'        => count( $failed ) > 0
				? ( ( count( $succeeded ) + count( $queued ) ) > 0 ? 'partial' : 'failed' )
				: ( count( $queued ) > 0 ? ( count( $succeeded ) > 0 ? 'partial' : 'queued' ) : 'ok' ),
		) ), count( $failed ) > 0 ? 'warn' : 'info' );

		return array(
			'notebook_id'      => $notebook_id,
			'notebook_name'    => (string) $nb['name'],
			'notebook_created' => (bool) $nb['_created'],
			'batch_id'         => $batch_id,
			'total'            => $batch_total,
			'succeeded'        => count( $succeeded ),
			'queued'           => count( $queued ),
			'queued_jobs'      => $queued,
			'items'            => $succeeded,
			'failed'           => $failed,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — media/file items default to
	 * cron dispatch; callers may force sync via ingest_mode=sync/dispatch_cron=0.
	 */
	private function should_dispatch_async_ingest( string $kind, array $ctx, array $ingest_payload ): bool {
		$mode = sanitize_key( (string) ( $ctx['ingest_mode'] ?? $ctx['dispatch_mode'] ?? '' ) );
		if ( $mode === 'sync' ) {
			return false;
		}
		if ( $mode === 'cron' || $mode === 'async' ) {
			return true;
		}
		if ( array_key_exists( 'dispatch_cron', $ctx ) ) {
			return ! empty( $ctx['dispatch_cron'] );
		}
		if ( (string) ( $ingest_payload['type'] ?? '' ) !== 'file' ) {
			return false;
		}
		return in_array( $kind, array( 'image', 'audio', 'file' ), true );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — queue one notebook ingest
	 * item as a single cron event.
	 */
	private function enqueue_async_capture_ingest( int $notebook_id, int $user_id, array $ingest_payload, array $item_ctx, array $inbound ) {
		$job_id = wp_generate_uuid4();
		$queue_payload = $ingest_payload;

		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — stage a durable
		// async file copy for bridge-dispatched file items so worker materialize
		// does not depend only on get_attached_file() at cron runtime.
		$staged = $this->stage_bridge_async_attachment( $queue_payload, $job_id );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — create visible source placeholder
		// BEFORE cron dispatch so non-text capture appears immediately in sources list.
		$placeholder = $this->create_bridge_async_placeholder( $notebook_id, $user_id, $queue_payload, $job_id );
		if ( is_wp_error( $placeholder ) ) {
			return $placeholder;
		}
		if ( is_array( $placeholder ) && ! empty( $placeholder['source_id'] ) ) {
			$queue_payload['metadata'] = isset( $queue_payload['metadata'] ) && is_array( $queue_payload['metadata'] ) ? $queue_payload['metadata'] : array();
			$queue_payload['metadata']['async_placeholder_source_id']    = (int) $placeholder['source_id'];
			$queue_payload['metadata']['async_placeholder_kg_source_id'] = (int) ( $placeholder['kg_source_id'] ?? 0 );
			$queue_payload['metadata']['async_ingest']                   = true;
			$queue_payload['metadata']['async_state']                    = 'queued';
			$queue_payload['metadata']['async_job_id']                   = $job_id;
			$queue_payload['metadata']['job_id']                         = $job_id;
		}
		$job    = array(
			'job_id'         => $job_id,
			'blog_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'notebook_id'    => $notebook_id,
			'user_id'        => $user_id,
			'ingest_payload' => $queue_payload,
			'item_ctx'       => $item_ctx,
			'inbound'        => $inbound,
			'queued_at'      => time(),
		);

		// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — leave time for the staged payload and placeholder metadata to commit before cron reads the job.
		$scheduled = wp_schedule_single_event( time() + 5, self::HOOK_ASYNC_CAPTURE_INGEST, array( $job ) );
		if ( false === $scheduled && ! wp_next_scheduled( self::HOOK_ASYNC_CAPTURE_INGEST, array( $job ) ) ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — fail closed for async placeholders
			// so a scheduling outage does not leave source rows stuck in processing.
			$this->mark_bridge_async_placeholder_failed(
				$queue_payload,
				'Khong the xep lich cron de hoc nguon vua nhan.',
				'notebook_bridge_async_schedule_failed'
			);
			return new WP_Error( 'notebook_bridge_async_schedule_failed', 'Không thể xếp lịch cron để học nguồn vừa nhận.', array( 'status' => 503 ) );
		}

		self::spawn_cron_wakeup();
		return array(
			'job_id'      => $job_id,
			'source_id'   => (int) ( $placeholder['source_id'] ?? 0 ),
			'kg_source_id'=> (int) ( $placeholder['kg_source_id'] ?? 0 ),
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — insert a TwinChat/KG source
	 * placeholder for bridge async capture so "add source" is visible before cron run.
	 */
	private function create_bridge_async_placeholder( int $notebook_id, int $user_id, array &$ingest_payload, string $job_id ) {
		if ( ! class_exists( 'BizCity_TwinChat_Sources_Database' ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'notebook_bridge_async_placeholder_unavailable', 'Source database chưa sẵn sàng để tạo placeholder async.', array( 'status' => 503 ) );
		}

		$title = sanitize_text_field( (string) ( $ingest_payload['title'] ?? '' ) );
		if ( $title === '' ) {
			$title = 'Tai lieu dang hoc nen';
		}
		$meta = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$meta['async_ingest'] = true;
		$meta['async_state']  = 'queued';
		$meta['job_id']       = $job_id;
		$meta['async_job_id'] = $job_id;
		$meta['bridge_async'] = true;

		$attach_id  = (int) ( $ingest_payload['attachment_id'] ?? 0 );
		$source_url = (string) ( $meta['provider_url'] ?? '' );
		if ( $source_url === '' && $attach_id > 0 ) {
			$source_url = (string) wp_get_attachment_url( $attach_id );
		}

		$db = BizCity_TwinChat_Sources_Database::instance();
		$source_id = $db->insert_source( array(
			'project_id'       => (string) $notebook_id,
			'notebook_id'      => $notebook_id,
			'user_id'          => $user_id,
			'title'            => $title,
			'source_type'      => 'file',
			'source_url'       => $source_url,
			'attachment_id'    => $attach_id,
			'content_text'     => '',
			'content_hash'     => '',
			'embedding_model'  => '',
			'embedding_status' => 'processing',
			'metadata'         => $meta,
		) );
		if ( $source_id <= 0 ) {
			return new WP_Error( 'notebook_bridge_async_placeholder_insert_failed', 'Không tạo được source placeholder async.', array( 'status' => 500 ) );
		}

		global $wpdb;
		$wpdb->insert( BizCity_KG_Database::instance()->tbl_sources(), array(
			'uuid'          => wp_generate_uuid4(),
			'blog_id'       => (int) get_current_blog_id(),
			'origin_plugin' => 'twinchat',
			'origin_kind'   => 'file',
			'origin_id'     => $source_id,
			'title'         => $title,
			'origin_url'    => $source_url !== '' ? $source_url : null,
			'status'        => 'processing',
			'scope_type'    => 'notebook',
			'scope_id'      => (string) $notebook_id,
			'user_id'       => $user_id,
			'passage_count' => 0,
		) );
		$kg_source_id = (int) $wpdb->insert_id;
		if ( $kg_source_id <= 0 ) {
			$db->update_source( $source_id, array(
				'embedding_status' => 'error',
				'error_message'    => 'Khong tao duoc KG placeholder cho async dispatch.',
			) );
			return new WP_Error( 'notebook_bridge_async_kg_placeholder_failed', 'Không tạo được KG source placeholder async.', array( 'status' => 500 ) );
		}

		return array(
			'source_id'    => (int) $source_id,
			'kg_source_id' => (int) $kg_source_id,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — keep async placeholder rows
	 * from hanging in processing when scheduling fails before worker starts.
	 */
	private function mark_bridge_async_placeholder_failed( array $ingest_payload, string $message, string $error_code = '' ): void {
		$metadata     = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$source_id    = isset( $metadata['async_placeholder_source_id'] ) ? (int) $metadata['async_placeholder_source_id'] : 0;
		$kg_source_id = isset( $metadata['async_placeholder_kg_source_id'] ) ? (int) $metadata['async_placeholder_kg_source_id'] : 0;
		$error        = function_exists( 'mb_substr' ) ? mb_substr( wp_strip_all_tags( $message ), 0, 500 ) : substr( wp_strip_all_tags( $message ), 0, 500 );

		if ( $source_id > 0 && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			$db  = BizCity_TwinChat_Sources_Database::instance();
			$row = $db->get_source( $source_id );
			$src_meta = $row && ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
			$src_meta = is_array( $src_meta ) ? $src_meta : array();
			$src_meta['async_state']        = 'error';
			$src_meta['async_error_at']     = time();
			$src_meta['async_heartbeat_at'] = time();
			if ( $error_code !== '' ) {
				$src_meta['async_error_code'] = sanitize_key( $error_code );
			}
			$db->update_source( $source_id, array(
				'embedding_status' => 'error',
				'error_message'    => $error,
				'metadata'         => $src_meta,
			) );
		}

		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$wpdb->update(
				BizCity_KG_Database::instance()->tbl_sources(),
				array( 'status' => 'error', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $kg_source_id )
			);
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — update async state metadata
	 * for the placeholder source row after worker terminal states.
	 */
	private function mark_bridge_async_placeholder_state( array $ingest_payload, string $state ): void {
		$metadata  = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$source_id = isset( $metadata['async_placeholder_source_id'] ) ? (int) $metadata['async_placeholder_source_id'] : 0;
		if ( $source_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return;
		}

		$db  = BizCity_TwinChat_Sources_Database::instance();
		$row = $db->get_source( $source_id );
		if ( ! $row ) {
			return;
		}
		$src_meta = ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
		$src_meta = is_array( $src_meta ) ? $src_meta : array();
		$src_meta['async_state']        = sanitize_key( $state );
		$src_meta['async_heartbeat_at'] = time();
		if ( in_array( $state, array( 'done', 'duplicate', 'error' ), true ) ) {
			$src_meta['async_finished_at'] = time();
		}
		$db->update_source( $source_id, array( 'metadata' => $src_meta ) );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — if async ingest dedups to an
	 * existing source, hide placeholder as duplicate and close its KG mirror row.
	 */
	private function mark_bridge_async_placeholder_duplicate( array $ingest_payload, array $result ): void {
		$metadata        = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$placeholder_id  = isset( $metadata['async_placeholder_source_id'] ) ? (int) $metadata['async_placeholder_source_id'] : 0;
		$kg_source_id    = isset( $metadata['async_placeholder_kg_source_id'] ) ? (int) $metadata['async_placeholder_kg_source_id'] : 0;
		$dedup_source_id = isset( $result['source_id'] ) ? (int) $result['source_id'] : 0;
		if ( $placeholder_id <= 0 || $dedup_source_id <= 0 || $placeholder_id === $dedup_source_id ) {
			$this->mark_bridge_async_placeholder_state( $ingest_payload, 'duplicate' );
			return;
		}

		if ( class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			$db  = BizCity_TwinChat_Sources_Database::instance();
			$row = $db->get_source( $placeholder_id );
			$src_meta = $row && ! empty( $row['metadata'] ) ? json_decode( (string) $row['metadata'], true ) : array();
			$src_meta = is_array( $src_meta ) ? $src_meta : array();
			$src_meta['async_state']        = 'duplicate';
			$src_meta['async_finished_at']  = time();
			$src_meta['async_heartbeat_at'] = time();
			$src_meta['dedup_source_id']    = $dedup_source_id;
			$db->update_source( $placeholder_id, array(
				'embedding_status' => 'duplicate',
				'error_message'    => null,
				'metadata'         => $src_meta,
			) );
		}

		if ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$wpdb->update(
				BizCity_KG_Database::instance()->tbl_sources(),
				array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $kg_source_id )
			);
		}
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.3 — nudge WP-Cron immediately
	 * after queuing async capture ingest.
	 */
	private static function spawn_cron_wakeup(): void {
		$url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
		wp_remote_post( $url, array(
			// [2026-07-28 Johnny Chu] AUTOMATION BE-ASYNC — allow the cron wakeup request to reach PHP-FPM; non-blocking still returns immediately to the caller.
			'timeout'   => 1,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		) );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — stage directory parity
	 * with scoped async ingest (`uploads/.../bizcity-async-ingest`).
	 */
	private function bridge_async_stage_dir(): string {
		$uploads = wp_upload_dir( null, true, false );
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : wp_normalize_path( WP_CONTENT_DIR . '/uploads' );
		return trailingslashit( $base ) . 'bizcity-async-ingest';
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — canonical bridge async
	 * staged file path builder.
	 */
	private function bridge_async_stage_path( string $basename ): string {
		return trailingslashit( $this->bridge_async_stage_dir() ) . sanitize_file_name( $basename );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — copy attachment file to
	 * durable async staging and hydrate payload+metadata for worker/retry.
	 *
	 * @return true|WP_Error
	 */
	private function stage_bridge_async_attachment( array &$ingest_payload, string $job_id ) {
		if ( (string) ( $ingest_payload['type'] ?? '' ) !== 'file' ) {
			return true;
		}
		$attach_id = (int) ( $ingest_payload['attachment_id'] ?? 0 );
		if ( $attach_id <= 0 ) {
			return true;
		}

		if ( function_exists( 'clean_attachment_cache' ) ) {
			clean_attachment_cache( $attach_id );
		}
		wp_cache_delete( $attach_id, 'post_meta' );

		$meta          = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$force_remote  = ! empty( $meta['force_remote_evidence'] );
		$remote_url    = (string) ( $meta['evidence_url'] ?? $meta['provider_url'] ?? '' );
		if ( $remote_url === '' ) {
			$remote_url = (string) wp_get_attachment_url( $attach_id );
		}
		$source_path   = get_attached_file( $attach_id );
		$source_local  = is_string( $source_path ) && $source_path !== '' && is_readable( $source_path );
		$remote_tmp    = '';

		if ( $force_remote ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-9 — strict mode for
			// notebook bridge: always stage from the evidence URL (R2/CDN), never
			// trust local attachment path as source-of-truth.
			error_log( sprintf( '[NotebookBridge][stage] force_remote_evidence=1 attachment_id=%d job_id=%s url=%s', $attach_id, $job_id, $remote_url ) );
			if ( $remote_url === '' ) {
				return new WP_Error( 'notebook_bridge_async_attachment_missing', 'Thiếu link R2/CDN để xếp hàng xử lý nền cho notebook bridge.', array( 'status' => 410 ) );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$remote_tmp = download_url( $remote_url, 30 );
			if ( is_wp_error( $remote_tmp ) ) {
				error_log( sprintf( '[NotebookBridge][stage] force-remote download failed attachment_id=%d job_id=%s url=%s error=%s', $attach_id, $job_id, $remote_url, $remote_tmp->get_error_message() ) );
				return new WP_Error( 'notebook_bridge_async_attachment_missing', 'Không tải được file từ link R2/CDN để xếp hàng xử lý nền.', array( 'status' => 410 ) );
			}
			error_log( sprintf( '[NotebookBridge][stage] force-remote download OK attachment_id=%d job_id=%s tmp=%s', $attach_id, $job_id, $remote_tmp ) );
			$source_path = $remote_tmp;
			$source_local = true;
		}

		if ( ! $source_local ) {
			// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-7 — offloaded media
			// (R2/S3, e.g. media.bizcity.vn CDN) deletes the local copy right
			// after upload, so get_attached_file() resolves a path that no
			// longer exists on this server. Fall back to downloading the
			// public attachment URL so staging still succeeds instead of
			// failing "no file" before the item ever reaches the adapter.
			error_log( sprintf( '[NotebookBridge][stage] local file missing for attachment_id=%d job_id=%s — trying remote URL fallback url=%s', $attach_id, $job_id, $remote_url ) );
			if ( $remote_url === '' ) {
				return new WP_Error( 'notebook_bridge_async_attachment_missing', 'Không tìm thấy file vật lý của attachment để xếp hàng xử lý nền.', array( 'status' => 410 ) );
			}
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			$remote_tmp = download_url( $remote_url, 30 );
			if ( is_wp_error( $remote_tmp ) ) {
				error_log( sprintf( '[NotebookBridge][stage] remote download failed attachment_id=%d job_id=%s url=%s error=%s', $attach_id, $job_id, $remote_url, $remote_tmp->get_error_message() ) );
				return new WP_Error( 'notebook_bridge_async_attachment_missing', 'Không tải được file gốc từ CDN để xếp hàng xử lý nền.', array( 'status' => 410 ) );
			}
			error_log( sprintf( '[NotebookBridge][stage] remote download OK attachment_id=%d job_id=%s tmp=%s', $attach_id, $job_id, $remote_tmp ) );
			$source_path = $remote_tmp;
		}

		$dir = $this->bridge_async_stage_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			if ( $remote_tmp !== '' && file_exists( $remote_tmp ) ) {
				@unlink( $remote_tmp );
			}
			return new WP_Error( 'notebook_bridge_async_stage_unwritable', 'Không thể tạo thư mục hàng đợi xử lý nền cho notebook bridge.', array( 'status' => 500 ) );
		}

		$original_name = sanitize_file_name( (string) ( $meta['file_name'] ?? basename( $source_path ) ) );
		if ( $original_name === '' ) {
			$original_name = 'notebook-capture.bin';
		}
		$stage_basename = sanitize_file_name( $job_id . '-' . $original_name );
		$stage_path     = $this->bridge_async_stage_path( $stage_basename );
		$copy_ok        = @copy( $source_path, $stage_path ) && is_readable( $stage_path );
		if ( $remote_tmp !== '' && file_exists( $remote_tmp ) ) {
			@unlink( $remote_tmp );
		}
		if ( ! $copy_ok ) {
			error_log( sprintf( '[NotebookBridge][stage] copy to stage failed attachment_id=%d job_id=%s source=%s stage=%s', $attach_id, $job_id, $source_path, $stage_path ) );
			return new WP_Error( 'notebook_bridge_async_file_stage_failed', 'Không thể sao chép file vào hàng đợi xử lý nền.', array( 'status' => 500 ) );
		}

		$mime_type = (string) ( $meta['mime'] ?? get_post_mime_type( $attach_id ) ?? '' );
		$file_size = (int) @filesize( $stage_path );
		if ( $file_size < 0 ) {
			$file_size = 0;
		}

		$meta['async_file']          = basename( $stage_path );
		$meta['async_original_name'] = $original_name;
		$meta['async_file_type']     = $mime_type;
		$meta['async_file_size']     = $file_size;
		$ingest_payload['metadata']  = $meta;
		$ingest_payload['file']      = array(
			'name'     => $original_name,
			'type'     => $mime_type,
			'tmp_name' => $stage_path,
			'error'    => 0,
			'size'     => $file_size,
		);

		return true;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — before ingest, rehydrate
	 * staged file from metadata so cron worker reads durable copy first.
	 */
	private function hydrate_bridge_async_file_payload( array &$ingest_payload ): bool {
		$meta = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$basename = sanitize_file_name( (string) ( $meta['async_file'] ?? '' ) );
		if ( $basename === '' ) {
			return false;
		}

		$path = $this->bridge_async_stage_path( $basename );
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$file_size = (int) ( $meta['async_file_size'] ?? @filesize( $path ) );
		if ( $file_size < 0 ) {
			$file_size = 0;
		}
		$ingest_payload['file'] = array(
			'name'     => (string) ( $meta['async_original_name'] ?? $basename ),
			'type'     => (string) ( $meta['async_file_type'] ?? '' ),
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => $file_size,
		);

		return true;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — clean up staged file
	 * after successful ingest; keep file on failure so retry can rehydrate.
	 */
	private function maybe_cleanup_bridge_async_file( array $ingest_payload ): void {
		$meta = isset( $ingest_payload['metadata'] ) && is_array( $ingest_payload['metadata'] ) ? $ingest_payload['metadata'] : array();
		$basename = sanitize_file_name( (string) ( $meta['async_file'] ?? '' ) );
		if ( $basename === '' ) {
			return;
		}
		$path = $this->bridge_async_stage_path( $basename );
		if ( file_exists( $path ) ) {
			@unlink( $path );
		}
	}

	/**
	 * Build the `ingest()` payload for ONE item (text or attachment) — shared
	 * by `capture()` (single item) and `capture_batch()` (N items). Behavior
	 * is IDENTICAL to what `capture()` used to build inline (see git history);
	 * this is a pure extraction, not a logic change.
	 *
	 * @param string $kind        'text'|'image'|'file'|'audio'.
	 * @param string $title_hint  Free text title (may be '').
	 * @param array  $item        { content?:string, attachment?:array } — envelope shape.
	 * @param array  $source_meta Base metadata (channel/day_key/scope/chat_id/message_id/inbound/…)
	 *                            to merge into `ingest_payload['metadata']`.
	 * @return array|WP_Error { type, title, content|attachment_id, metadata }
	 */
	private function build_ingest_payload( string $kind, string $title_hint, array $item, array $source_meta ) {
		if ( $kind === 'text' ) {
			$content = trim( (string) ( $item['content'] ?? '' ) );
			if ( $content === '' ) {
				return new WP_Error( 'notebook_bridge_empty_text', 'Không có nội dung để lưu vào ghi chú.', array( 'status' => 400 ) );
			}
			$title = $title_hint !== '' ? $title_hint : wp_trim_words( $content, 8, '…' );
			return array(
				'type'     => 'text',
				'title'    => $title,
				'content'  => $content,
				'metadata' => $source_meta,
			);
		}

		$attachment = is_array( $item['attachment'] ?? null ) ? $item['attachment'] : array();
		$url        = (string) ( $attachment['url'] ?? $attachment['source_url'] ?? '' );
		$attach_id  = (int) ( $attachment['attachment_id'] ?? 0 );
		$mime_hint  = (string) ( $attachment['mime'] ?? $attachment['mime_type'] ?? '' );
		if ( $attach_id <= 0 && $url !== '' ) {
			$file_label = (string) ( $attachment['file_name'] ?? $attachment['name'] ?? '' );
			if ( $file_label === '' ) {
				$file_label = $title_hint !== '' ? $title_hint : 'notebook-capture';
			}
			$sideloaded = $this->sideload_remote_file( $url, $file_label, $mime_hint );
			if ( is_wp_error( $sideloaded ) ) {
				return $sideloaded;
			}
			$attach_id = $sideloaded;
		}
		if ( $attach_id <= 0 ) {
			return new WP_Error( 'notebook_bridge_no_attachment', 'Không tìm thấy file/ảnh để lưu vào ghi chú.', array( 'status' => 400 ) );
		}
		$file_title = trim( (string) ( $attachment['file_name'] ?? $attachment['name'] ?? '' ) );
		if ( $file_title === '' && $url !== '' ) {
			$file_title = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-4 — for document/file
		// sources, prefer original filename as persisted source title so users can
		// recognize uploaded entries in source list and learning log.
		if ( $kind === 'file' && $file_title !== '' ) {
			$title = $file_title;
		} else {
			$title = $title_hint !== '' ? $title_hint : ( $kind === 'image' ? 'Hình ảnh' : ( $kind === 'audio' ? 'Ghi âm' : 'Tài liệu' ) );
		}
		// [2026-07-24 Johnny Chu] PHASE-0.46 W1 — type='file' + attachment_id already
		// routes through BizCity_KG_Adapter_Registry (pdf/office/av) and, for anything
		// unmatched (incl. images), the R-KG-FILE-TYPES unified media-url fallback —
		// no separate image adapter needed for this bridge to work end-to-end.
		// [2026-07-24 Johnny Chu] PHASE-0.46 §2.6 item 3 — surface the ORIGINAL
		// file (evidence) as its own metadata key, distinct from `attachment_id`
		// (which is the ingest-time param, not guaranteed to stay named the same
		// downstream). This is a one-line symmetry addition, not a content
		// change — the original photo/voice/document is already retained
		// permanently in the WP Media Library (`sideload_remote_file()` never
		// deletes on success); this just makes that evidence pointer easy to
		// find directly from a source's `metadata` without joining anywhere.
		return array(
			'type'          => 'file',
			'title'         => $title,
			'attachment_id' => $attach_id,
			'metadata'      => array_merge( $source_meta, array(
				'provider_url'          => $url,
				'file_name'             => (string) ( $attachment['file_name'] ?? $attachment['name'] ?? '' ),
				'mime'                  => $mime_hint,
				// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-9 — enforce
				// remote-evidence learning path (R2/CDN URL as source-of-truth)
				// for notebook bridge file captures.
				'force_remote_evidence' => 1,
				'evidence_attachment_id' => $attach_id,
				'evidence_url'          => (string) wp_get_attachment_url( $attach_id ),
			) ),
		);
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 — Requirement #4 of the instant
	 * upload-link feature: "Post meta của files cần lưu notebook id lại để
	 * sau còn list ra danh sách các files đã up lên của notebook id này."
	 * Centralized here (single call site each in `capture()`/`capture_batch()`)
	 * so EVERY attachment-based capture — not just instant-upload-link ones —
	 * gets indexed the same way, whether it arrived via remote-URL sideload
	 * or a direct WP Media upload (`attachment_id` already known). No-op for
	 * text items or failed ingest payloads.
	 */
	private function maybe_tag_attachment_notebook_meta( $ingest_payload, int $notebook_id ): void {
		if ( $notebook_id <= 0 || ! is_array( $ingest_payload ) ) {
			return;
		}
		if ( (string) ( $ingest_payload['type'] ?? '' ) !== 'file' ) {
			return;
		}
		$attach_id = (int) ( $ingest_payload['attachment_id'] ?? 0 );
		if ( $attach_id <= 0 ) {
			return;
		}
		update_post_meta( $attach_id, '_bizcity_kg_notebook_id', $notebook_id );
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.2 — "trong ghi chú hôm nay" /
	 * "@notebook hôm nay" scoped querying. Returns the notebook ids
	 * auto-created by THIS bridge for the given user/day (optionally
	 * narrowed to one `channel`), most-recently-updated first, so a caller
	 * (e.g. TwinBrain runtime) can force-scope retrieval to exactly what was
	 * captured that day instead of letting the generic cosine/recency
	 * selector guess. Same PHP-side JSON filter as `find_or_create_daily_notebook()`
	 * — no schema change (§2.2).
	 *
	 * @return int[] notebook ids (may be empty — caller falls back to normal selection).
	 */
	public static function find_day_notebooks( int $user_id, string $day_key = '', string $channel = '' ): array {
		if ( $user_id <= 0 || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return array();
		}
		$day_key = $day_key !== '' ? sanitize_key( $day_key ) : current_time( 'Ymd' );
		$channel = sanitize_key( $channel );

		$svc  = BizCity_KG_Notebook_Service::instance();
		$rows = $svc->list_for_user( $user_id, array( 'limit' => 200 ) );
		$ids  = array();
		foreach ( (array) $rows as $row ) {
			$settings = (array) ( $row['settings'] ?? array() );
			if ( empty( $settings['auto_created'] ) ) {
				continue;
			}
			if ( ( $settings['day_key'] ?? '' ) !== $day_key ) {
				continue;
			}
			if ( $channel !== '' && ( $settings['channel'] ?? '' ) !== $channel ) {
				continue;
			}
			$ids[] = (int) ( $row['id'] ?? 0 );
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W5 S5.4 — "xuất file word tóm tắt"
	 * document-export combo. Reuses the SAME "Plan A handoff" pattern already
	 * shipped for the TwinShell mindmap/doc agents
	 * (`BZDoc_Notebook_Bridge::generate_from_skeleton_public()`, see
	 * `plugins/bizcity-doc/includes/agents/register-doc-agent.php`) — this is
	 * NOT a new document generator. bzdoc's real architecture builds the
	 * JSON schema server-side but converts to DOCX/PDF/PPTX/XLSX client-side
	 * in the browser (see Open Question #3 in the roadmap doc), so this
	 * method returns a clickable draft URL, not a ready binary file — the
	 * user opens the link (logged in) to actually generate + download.
	 *
	 * @param int    $user_id  Resolved WP user id (never guest/0).
	 * @param string $day_key  Ymd, defaults to current site-local date.
	 * @param string $doc_type 'document'|'presentation'|'spreadsheet'.
	 * @return array|WP_Error { doc_id, url, title, notebook_id, notebook_name }
	 */
	public static function export_day_notebook_as_document( int $user_id, string $day_key = '', string $doc_type = 'document' ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'notebook_bridge_invalid_identity', 'Thiếu định danh user_id — không thể xuất tài liệu.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BZDoc_Notebook_Bridge' ) || ! method_exists( 'BZDoc_Notebook_Bridge', 'generate_from_skeleton_public' ) ) {
			return new WP_Error( 'bzdoc_bridge_unavailable', 'BizCity Doc Studio chưa sẵn sàng trên site này.', array( 'status' => 500 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'notebook_bridge_deps_missing', 'Dịch vụ Notebook chưa sẵn sàng trên site này.', array( 'status' => 500 ) );
		}

		$notebook_ids = self::find_day_notebooks( $user_id, $day_key );
		if ( empty( $notebook_ids ) ) {
			return new WP_Error( 'notebook_bridge_no_notebook_today', 'Chưa có ghi chú nào được lưu hôm nay để xuất tài liệu.', array( 'status' => 404 ) );
		}
		// MVP: export the MOST RECENTLY UPDATED notebook only (`find_day_notebooks()`
		// iterates `list_for_user()`, already ordered by `updated_at DESC`) —
		// multi-notebook merge is a future enhancement, not needed for the
		// common single-daily-notebook-per-title case.
		$notebook_id = (int) $notebook_ids[0];

		$svc      = BizCity_KG_Notebook_Service::instance();
		$notebook = $svc->get( $notebook_id );
		if ( ! is_array( $notebook ) ) {
			return new WP_Error( 'notebook_bridge_notebook_not_found', 'Không tìm thấy ghi chú để xuất tài liệu.', array( 'status' => 404 ) );
		}
		$notebook_name = (string) ( $notebook['name'] ?? '' );
		// "Báo cáo" nudges bzdoc's own guess_template() heuristic toward the
		// 'report' template (see class-notebook-bridge.php::guess_template())
		// instead of always falling back to 'blank'.
		$title = sprintf( 'Báo cáo tóm tắt %s', $notebook_name !== '' ? $notebook_name : 'ghi chú hôm nay' );

		$skeleton = array(
			'nucleus'    => array(
				'title'  => $title,
				'thesis' => '',
				'domain' => '',
			),
			'project_id' => 'tc_' . $notebook_id,
			'_raw_text'  => sprintf(
				'Tóm tắt nội dung đã ghi chú/học được trong notebook "%s" thành một tài liệu Word mạch lạc: nêu các điểm chính, quyết định và việc cần làm (nếu có).',
				$notebook_name
			),
			'_kickstart' => true,
			'doc_opts'   => array(
				'template'         => 'report',
				'theme'            => 'modern',
				'slide_count'      => 0,
				'split_two'        => false,
				'parallel_batches' => 0,
			),
		);

		$result = BZDoc_Notebook_Bridge::generate_from_skeleton_public( $skeleton, $doc_type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_array( $result ) || empty( $result['data']['doc_id'] ) ) {
			return new WP_Error( 'bzdoc_bridge_failed', 'Không tạo được bản nháp tài liệu.', array( 'status' => 500 ) );
		}

		$doc_id   = (int) $result['data']['doc_id'];
		$edit_url = (string) ( $result['data']['url'] ?? home_url( '/tool-doc/?id=' . $doc_id . '&autogen=1&kickstart=1' ) );

		if ( class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			// Same federation the doc-agent tool uses — lets bzdoc's FE read
			// this notebook's captured KG sources via /document/{id}/kg-sources
			// instead of relying only on the short `_raw_text` instruction.
			BizCity_Artifact_Source_Federation::stamp( 'bizcity-doc', $doc_id, $notebook_id, $title, $edit_url );
		}

		return array(
			'doc_id'        => $doc_id,
			'url'           => $edit_url,
			'title'         => $title,
			'notebook_id'   => $notebook_id,
			'notebook_name' => $notebook_name,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — normalize + summarize title
	 * for notebook capture so command triggers are not used as final notebook name.
	 *
	 * @return array{title_hint:string,title_slug:string}
	 */
	private function resolve_capture_title( string $raw_title_hint, array $items ): array {
		$seed = $this->sanitize_capture_title_hint( $raw_title_hint );
		if ( $seed === '' ) {
			$seed = $this->build_capture_title_seed( $items );
		}
		if ( $seed === '' ) {
			$seed = 'Ghi chú nhanh';
		}

		$title_hint = $seed;
		$should_use_llm = (bool) apply_filters( 'bizcity_kg_notebook_bridge_use_llm_title', true, $seed, $items );
		if ( $should_use_llm ) {
			$title_hint = $this->summarize_capture_title_with_llm( $seed, $items );
		}

		$title_hint = $this->sanitize_capture_title_hint( $title_hint );
		if ( $title_hint === '' ) {
			$title_hint = $seed;
		}
		$title_hint = $this->shorten_capture_title( $title_hint );
		$title_slug = sanitize_title( $title_hint );
		if ( $title_slug === '' ) {
			$title_slug = 'ghi-chu';
		}

		return array(
			'title_hint' => $title_hint,
			'title_slug' => $title_slug,
		);
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — strip command prefixes and
	 * normalize whitespace/punctuation for notebook title text.
	 */
	private function sanitize_capture_title_hint( string $title ): string {
		$title = wp_strip_all_tags( (string) $title );
		$title = preg_replace( '/@(?:notebook|ghichu)\b/iu', '', $title );
		$title = preg_replace( '/^[\s:\-_,.;]+/u', '', (string) $title );
		$title = preg_replace( '/\s+/u', ' ', (string) $title );
		$title = trim( (string) $title, " \t\n\r\0\x0B.,:;!?-_" );
		return $title;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — build concise title seed from
	 * capture items when caller did not provide a usable title.
	 */
	private function build_capture_title_seed( array $items ): string {
		$bits = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
			if ( $kind === 'text' ) {
				$text = trim( wp_strip_all_tags( (string) ( $item['content'] ?? '' ) ) );
				if ( $text !== '' ) {
					$bits[] = $text;
				}
				continue;
			}
			$att = isset( $item['attachment'] ) && is_array( $item['attachment'] ) ? $item['attachment'] : array();
			$file_name = trim( (string) ( $att['file_name'] ?? $att['name'] ?? '' ) );
			if ( $file_name === '' ) {
				$url = trim( (string) ( $att['source_url'] ?? $att['url'] ?? '' ) );
				if ( $url !== '' ) {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					$file_name = basename( $path );
				}
			}
			if ( $file_name !== '' ) {
				$bits[] = $file_name;
				continue;
			}
			if ( $kind === 'image' ) {
				$bits[] = 'hinh anh';
			} elseif ( $kind === 'audio' ) {
				$bits[] = 'ghi am';
			} elseif ( $kind !== '' ) {
				$bits[] = $kind;
			}
		}

		$seed = trim( implode( ' | ', array_slice( $bits, 0, 4 ) ) );
		return $this->shorten_capture_title( $seed );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — fast Gemini Flash title
	 * summary for notebook capture batches.
	 */
	private function summarize_capture_title_with_llm( string $seed, array $items ): string {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->shorten_capture_title( $seed );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! method_exists( $llm, 'is_ready' ) || ! $llm->is_ready() ) {
			return $this->shorten_capture_title( $seed );
		}

		$evidence_lines = array();
		foreach ( array_slice( $items, 0, 4 ) as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $item['kind'] ?? '' ) );
			if ( $kind === 'text' ) {
				$text = trim( wp_strip_all_tags( (string) ( $item['content'] ?? '' ) ) );
				if ( $text !== '' ) {
					$evidence_lines[] = sprintf( '- item %d text: %s', $idx + 1, $this->shorten_capture_title( $text, 120 ) );
				}
				continue;
			}
			$att = isset( $item['attachment'] ) && is_array( $item['attachment'] ) ? $item['attachment'] : array();
			$file_name = trim( (string) ( $att['file_name'] ?? $att['name'] ?? '' ) );
			if ( $file_name === '' ) {
				$url = trim( (string) ( $att['source_url'] ?? $att['url'] ?? '' ) );
				if ( $url !== '' ) {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					$file_name = basename( $path );
				}
			}
			$evidence_lines[] = sprintf( '- item %d %s: %s', $idx + 1, $kind !== '' ? $kind : 'file', $file_name !== '' ? $file_name : 'tep dinh kem' );
		}

		$prompt = "Seed title: " . $seed . "\n";
		if ( ! empty( $evidence_lines ) ) {
			$prompt .= "Evidence:\n" . implode( "\n", $evidence_lines ) . "\n";
		}
		$prompt .= "Yeu cau: Tao 1 tieu de tieng Viet rat ngan gon (toi da 6 tu), khong dung @notebook hay @ghichu, chi tra ve duy nhat tieu de.";

		$res = $llm->chat( array(
			array(
				'role'    => 'system',
				'content' => 'Ban dat tieu de ghi chu cuc ngan gon, ro y chinh. Khong them markdown hay giai thich.',
			),
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		), array(
			'purpose'     => 'chat',
			'model'       => 'google/gemini-2.5-flash',
			'temperature' => 0.2,
			'max_tokens'  => 28,
			'timeout'     => 15,
			'no_fallback' => true,
		) );

		if ( empty( $res['success'] ) ) {
			return $this->shorten_capture_title( $seed );
		}

		$title = trim( (string) ( $res['message'] ?? '' ) );
		$title = preg_replace( '/[\r\n].*$/s', '', $title );
		$title = trim( (string) $title, " \t\n\r\0\x0B\"'`“”.,:;!?-_" );
		$title = $this->sanitize_capture_title_hint( $title );
		if ( $title === '' ) {
			return $this->shorten_capture_title( $seed );
		}
		return $this->shorten_capture_title( $title );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.7 — keep title compact for slug/name.
	 */
	private function shorten_capture_title( string $title, int $max_chars = 64 ): string {
		$title = trim( preg_replace( '/\s+/u', ' ', (string) $title ) );
		if ( $title === '' ) {
			return '';
		}
		$words = preg_split( '/\s+/u', $title );
		if ( is_array( $words ) && count( $words ) > 8 ) {
			$title = implode( ' ', array_slice( $words, 0, 8 ) );
		}
		if ( function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $title ) > $max_chars ) {
				$title = mb_substr( $title, 0, $max_chars );
			}
		} elseif ( strlen( $title ) > $max_chars ) {
			$title = substr( $title, 0, $max_chars );
		}
		return trim( $title );
	}

	/**
	 * Find today's channel-scoped notebook for this user, or create it.
	 *
	 * MVP lookup: no schema change (per PHASE-0.46 §2.2) — filters the
	 * existing `settings` JSON bag in PHP. Promote to an indexed column only
	 * if/when volume proves this too slow (must go through R-DCL + R-CR).
	 *
	 * @return array|WP_Error hydrated notebook row + `_created` bool.
	 */
	private function find_or_create_daily_notebook( int $user_id, string $channel, string $day_key, string $title_slug, string $title_hint, array $scope = array() ) {
		// [2026-07-27 Johnny Chu] PHASE-0.51 W4 — unlinked channel identities must link before capture can create a notebook.
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'kg_notebook_identity_required',
				'Vui lòng liên kết tài khoản trước khi ghi chú.',
				array( 'status' => 403 )
			);
		}
		$svc  = BizCity_KG_Notebook_Service::instance();
		$scope_type = (string) ( $scope['scope_type'] ?? 'private' );
		$scope_id   = (string) ( $scope['scope_id'] ?? '' );
		$workspace_id = $this->resolve_daily_workspace_id( $user_id, $channel );

		$existing = $svc->list_for_user( $user_id, array( 'limit' => 200 ) );
		foreach ( (array) $existing as $row ) {
			$settings = (array) ( $row['settings'] ?? array() );
			if ( ( $settings['channel'] ?? '' ) === $channel
				&& ( $settings['day_key'] ?? '' ) === $day_key
				&& ( $settings['capture_title'] ?? '' ) === $title_slug
			) {
				$existing_scope_type = (string) ( $settings['scope_type'] ?? '' );
				$existing_scope_id   = (string) ( $settings['scope_id'] ?? '' );

				if ( $scope_type === 'group' ) {
					if ( $existing_scope_type !== 'group' ) {
						continue;
					}
					if ( $scope_id !== '' && $existing_scope_id !== $scope_id ) {
						continue;
					}
				} else {
					if ( $existing_scope_type === 'group' ) {
						continue;
					}
					if ( $existing_scope_type === 'private' && $scope_id !== '' && $existing_scope_id !== '' && $existing_scope_id !== $scope_id ) {
						continue;
					}
				}
				// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — backfill `settings.slug`
				// + `settings.workspace_id` lazily for pre-W5 notebooks so every
				// share/public link surface can rely on stable slug/workspace keys
				// even when reusing an old row created before this rule existed.
				$changed = false;
				if ( empty( $settings['workspace_id'] ) || (string) $settings['workspace_id'] !== $workspace_id ) {
					$settings['workspace_id'] = $workspace_id;
					$changed = true;
				}
				if ( empty( $settings['slug'] ) ) {
					$settings['slug'] = $this->build_capture_slug( $day_key, $title_slug, $user_id );
					$changed = true;
				}
				if ( $changed ) {
					$updated = $svc->update( (int) ( $row['id'] ?? 0 ), array( 'settings' => $settings ) );
					if ( is_array( $updated ) ) {
						$row = $updated;
					}
				}
				$row['_created'] = false;
				return $row;
			}
		}

		// [2026-07-26 Johnny Chu] PHASE-0.46 W5 R1-R3 — new notebooks auto-join
		// the per-user "{Channel} hằng ngày" workspace (instead of the shared
		// generic "Notebook" workspace) and carry a stable `slug` used by
		// every share/download/public-log surface. The human-facing `name`
		// no longer embeds `{channel}_{user_id}_{day_key}` — that identity
		// now lives ONLY in `settings{}`.
		$slug         = $this->build_capture_slug( $day_key, $title_slug, $user_id );
		$name         = $this->build_capture_display_name( $title_hint, $title_slug );

		$description = $title_hint !== '' ? sprintf( '[%s] %s (%s)', $channel, $title_hint, $day_key ) : $name;
		$created     = $svc->create( array(
			'name'        => $name,
			'description' => $description,
			'settings'    => array(
				'channel'       => $channel,
				'day_key'       => $day_key,
				'capture_title' => $title_slug,
				'scope_type'    => $scope_type,
				'scope_id'      => $scope_id,
				'auto_created'  => true,
				'workspace_id'  => $workspace_id,
				'slug'          => $slug,
			),
		), $user_id );
		if ( ! $created ) {
			return new WP_Error( 'notebook_bridge_create_failed', 'Không thể tạo notebook mới.', array( 'status' => 500 ) );
		}
		$created['_created'] = true;
		return $created;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R1 — find-or-create a per-user,
	 * per-channel "daily capture" workspace (e.g. "Zalo hằng ngày") so
	 * channel captures stop mixing into the shared default "Notebook"
	 * workspace. Storage stays the EXISTING `bizcity_kg_workspaces` user_meta
	 * list — no schema migration — see
	 * class-kg-rest-controller.php::USER_META_WORKSPACES.
	 */
	private function resolve_daily_workspace_id( int $user_id, string $channel ): string {
		$channel = sanitize_key( $channel );
		$ws_id   = 'ws_' . ( $channel !== '' ? $channel : 'channel' ) . '_daily';
		$labels  = array(
			'zalobot'   => 'Zalo hằng ngày',
			'telegram'  => 'Telegram hằng ngày',
			'messenger' => 'Messenger hằng ngày',
			'webchat'   => 'WebChat hằng ngày',
		);
		$label = isset( $labels[ $channel ] ) ? $labels[ $channel ] : ( ucfirst( $channel ) . ' hằng ngày' );

		$blog_id  = is_multisite() ? (int) get_current_blog_id() : 0;
		$meta_key = $blog_id > 1 ? 'bizcity_kg_workspaces_' . $blog_id : 'bizcity_kg_workspaces';

		// [2026-07-27 Johnny Chu] PHASE-0.51 W1 — read workspace JSON once via the unified meta cache.
		$raw  = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $user_id, $meta_key, '' )
			: get_user_meta( $user_id, $meta_key, true );
		$list = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : $raw;
		$list = is_array( $list ) ? $list : array();

		foreach ( $list as $w ) {
			if ( is_array( $w ) && ( (string) ( $w['id'] ?? '' ) ) === $ws_id ) {
				return $ws_id; // already provisioned.
			}
		}

		$list[] = array(
			'id'        => $ws_id,
			'name'      => $label,
			'color'     => '#16a34a',
			'createdAt' => current_time( 'mysql' ),
		);
		// [2026-07-27 Johnny Chu] PHASE-0.51 W1 — one cached write; avoid stale reread and invalid cache API.
		$encoded = wp_json_encode( $list, JSON_UNESCAPED_UNICODE );
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::set( $user_id, $meta_key, $encoded );
		} else {
			update_user_meta( $user_id, $meta_key, $encoded );
		}
		return $ws_id;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — human-facing notebook name
	 * drops the `{channel}_{user_id}_{day_key}` prefix entirely; that
	 * identity now lives ONLY in `settings{}` + `settings.slug`. Share
	 * links, doc export titles and the public learning log header all read
	 * this friendly name (or the source title), never the raw internal key.
	 */
	private function build_capture_display_name( string $title_hint, string $title_slug ): string {
		$name = trim( (string) $title_hint );
		if ( $name === '' ) {
			$name = str_replace( '-', ' ', (string) $title_slug );
		}
		if ( function_exists( 'mb_convert_case' ) && $name !== '' ) {
			$name = mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
		}
		return $name !== '' ? $name : 'Ghi Chú Nhanh';
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W5 R3 — stable, URL-safe slug used
	 * consistently by share links, public learning-log links and doc-export
	 * filenames. Collision-safe: appends a numeric suffix when the same user
	 * already has a notebook with the same day+title slug.
	 */
	private function build_capture_slug( string $day_key, string $title_slug, int $user_id ): string {
		$base = trim( $day_key . '-' . $title_slug, '-' );
		$base = $base !== '' ? $base : ( 'capture-' . $day_key );

		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return $base;
		}
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_notebooks();

		$slug   = $base;
		$suffix = 1;
		while ( true ) {
			$found = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl} WHERE owner_id = %d AND settings LIKE %s LIMIT 1",
				$user_id,
				'%"slug":"' . $wpdb->esc_like( $slug ) . '"%'
			) );
			if ( ! $found ) {
				break;
			}
			$suffix++;
			$slug = $base . '-' . $suffix;
			if ( $suffix > 50 ) { // safety valve — never loop forever.
				$slug = $base . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
				break;
			}
		}
		return $slug;
	}

	/**
	 * Generic remote-URL → WP Media sideload. Works for image/audio/pdf/
	 * office/etc — extension is irrelevant here, `read_file_content()` in
	 * BizCity_TwinChat_Sources_Service decides how to extract text from it.
	 *
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W3 S3.2 — accepts an optional MIME
	 * hint from the channel payload. Many Zalo CDN URLs have no file
	 * extension in the path; without a real extension, `media_handle_sideload()`
	 * silently fails `wp_check_filetype_and_ext()`. Resolving the extension
	 * from the provider-declared MIME type when the URL lacks one fixes that
	 * class of failure without guessing.
	 *
	 * @return int|WP_Error attachment_id
	 */
	private function sideload_remote_file( string $url, string $label, string $mime_hint = '' ) {
		if ( $url === '' ) {
			return new WP_Error( 'notebook_bridge_empty_url', 'URL tệp trống.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'notebook_bridge_download_failed', 'Không tải được file từ kênh gửi lên.', array( 'reason' => $tmp->get_error_message() ) );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( $ext === '' && $mime_hint !== '' ) {
			$ext = $this->resolve_ext_from_mime( $mime_hint );
		}
		$base_name = sanitize_file_name( $label );
		$file_name = $base_name !== '' ? $base_name . ( $ext !== '' ? '.' . $ext : '' ) : basename( $path );

		$file_array = array(
			'name'     => $file_name !== '' ? $file_name : 'notebook-capture',
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return new WP_Error( 'notebook_bridge_sideload_failed', 'Không lưu được file vào thư viện media.', array( 'reason' => $attach_id->get_error_message() ) );
		}
		return (int) $attach_id;
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 W3 S3.2 — reverse-lookup a file
	 * extension from a MIME type using WordPress's own allowed-mimes map, so
	 * we never hard-code a private ext↔mime table that could drift from core.
	 */
	private function resolve_ext_from_mime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		if ( $mime === '' ) {
			return '';
		}
		foreach ( wp_get_mime_types() as $ext_pattern => $mapped_mime ) {
			if ( strtolower( $mapped_mime ) === $mime ) {
				$first_ext = explode( '|', $ext_pattern )[0] ?? '';
				return (string) $first_ext;
			}
		}
		return '';
	}

	/**
	 * Persist R-SCH-REPLY style inbound{} provenance on the source's existing
	 * `metadata` JSON column (no schema change) so a future progress notifier
	 * (PHASE-0.46 Wave 5) can reply on the exact originating channel/chat.
	 */
	private function stamp_inbound_provenance( int $source_id, array $inbound, array $capture_meta = array() ): void {
		$db = BizCity_TwinChat_Sources_Database::instance();
		if ( ! method_exists( $db, 'get_source' ) || ! method_exists( $db, 'update_source' ) ) {
			return;
		}
		$row = $db->get_source( $source_id );
		if ( ! $row ) {
			return;
		}
		$meta = array();
		if ( ! empty( $row['metadata'] ) ) {
			$decoded = json_decode( (string) $row['metadata'], true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$meta['inbound'] = $inbound;
		if ( ! empty( $capture_meta ) ) {
			$meta['capture'] = $capture_meta;
			// [2026-07-24 Johnny Chu] PHASE-0.46 W4 S4.7 BUGFIX — also surface
			// kg_source_id/batch_id/batch_total at the TOP level of metadata.
			// BizCity_KG_Channel_Progress_Notifier::on_batch_done() reads
			// `$meta['kg_source_id']` directly (not `$meta['capture']['kg_source_id']`)
			// — nesting-only would leave Step 3 permanently unable to resolve
			// kg_source_id and skip every source forever.
			if ( isset( $capture_meta['kg_source_id'] ) ) {
				$meta['kg_source_id'] = (int) $capture_meta['kg_source_id'];
			}
			if ( isset( $capture_meta['batch_id'] ) ) {
				$meta['batch_id'] = (string) $capture_meta['batch_id'];
			}
			if ( isset( $capture_meta['batch_total'] ) ) {
				$meta['batch_total'] = (int) $capture_meta['batch_total'];
			}
		}
		$db->update_source( $source_id, array( 'metadata' => wp_json_encode( $meta ) ) );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — write notebook-bridge lifecycle events
	 * to dedicated JSONL evidence stream; never throw.
	 */
	private function bridge_log( string $event, array $data = array(), string $level = 'info' ): void {
		// [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-8 — mirror every bridge
		// lifecycle event to the main PHP error log too. The dedicated JSONL
		// stream (BizCity_KG_Notebook_Bridge_File_Logger) can silently fail to
		// write (wrong blog dir, permissions, disk) with no surfaced error;
		// error_log() is the one sink we've confirmed reliably reaches
		// bps_php_error.log, so step-by-step tracing must not depend solely
		// on the JSONL file existing.
		error_log( sprintf(
			'[NotebookBridge][%s] event=%s data=%s',
			strtoupper( $level ),
			$event,
			wp_json_encode( $this->bridge_log_error_log_context( $data ) )
		) );
		// [2026-07-28 Johnny Chu] R-DDV — mirror bridge lifecycle evidence into the same per-blog learning log used by scoped async ingest.
		if ( function_exists( 'bizcity_tc_learning_debug_log' ) ) {
			bizcity_tc_learning_debug_log( '[kg-bridge] ' . $event . ': ' . wp_json_encode( $this->bridge_log_error_log_context( $data ) ) );
		}

		if ( ! class_exists( 'BizCity_KG_Notebook_Bridge_File_Logger' ) ) {
			return;
		}
		BizCity_KG_Notebook_Bridge_File_Logger::log( $event, $data, $level );
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.46 W6 HOTFIX-8 — keep the error_log
	 * mirror compact (drop large/raw content fields already stripped by the
	 * JSONL logger's own sanitizer, plus anything not scalar/short array).
	 */
	private function bridge_log_error_log_context( array $data ): array {
		$out = array();
		foreach ( $data as $k => $v ) {
			if ( is_scalar( $v ) || $v === null ) {
				$out[ $k ] = $v;
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = ( count( $v ) <= 10 ) ? $v : array( '_truncated_count' => count( $v ) );
			}
		}
		return $out;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — stable reason bucket extraction
	 * for JSONL logs; keeps automation parsers independent of localized messages.
	 */
	private function extract_error_code( $err ): string {
		if ( is_wp_error( $err ) ) {
			$code = (string) $err->get_error_code();
			if ( $code !== '' ) {
				return sanitize_key( $code );
			}
		}
		return 'unknown_error';
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 HARDEN-1 — scope isolation for group/private capture.
	 */
	private function resolve_capture_scope( array $envelope, int $user_id ): array {
		$scope_type = sanitize_key( (string) ( $envelope['scope_type'] ?? $envelope['chat_kind'] ?? '' ) );
		$scope_type = $scope_type === 'group' ? 'group' : 'private';
		$scope_id   = (string) ( $envelope['scope_id'] ?? '' );

		if ( $scope_id === '' ) {
			if ( $scope_type === 'group' ) {
				$scope_id = (string) ( $envelope['provider_chat_id'] ?? $envelope['chat_id'] ?? '' );
			} else {
				$scope_id = (string) ( $envelope['provider_chat_id'] ?? $envelope['chat_id'] ?? $user_id );
			}
		}

		return array(
			'scope_type' => $scope_type,
			'scope_id'   => sanitize_text_field( $scope_id ),
		);
	}

	/**
	 * [2026-07-24 Johnny Chu] PHASE-0.46 HARDEN-2 — best-effort message-level dedup for webhook retries.
	 * [2026-07-25 Johnny Chu] PHASE-0.46 W4.5.5 — include optional kind/url
	 * dimensions to avoid collisions on caption+media sharing one message_id.
	 */
	private function find_existing_source_by_message_id( int $user_id, string $channel, string $message_id, array $scope, string $kind = '', string $provider_url = '' ): ?array {
		if ( $message_id === '' || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
			return null;
		}
		$db = BizCity_TwinChat_Sources_Database::instance();
		if ( ! method_exists( $db, 'table_sources' ) ) {
			return null;
		}

		global $wpdb;
		$tbl          = $db->table_sources();
		$like_message = '%' . $wpdb->esc_like( '"message_id":"' . $message_id . '"' ) . '%';
		$like_channel = '%' . $wpdb->esc_like( '"channel":"' . $channel . '"' ) . '%';
		$sql          = "SELECT id, project_id FROM {$tbl} WHERE user_id = %d AND metadata LIKE %s AND metadata LIKE %s";
		$params       = array( $user_id, $like_message, $like_channel );

		$kind = sanitize_key( $kind );
		if ( $kind !== '' ) {
			$like_kind = '%' . $wpdb->esc_like( '"kind":"' . $kind . '"' ) . '%';
			$sql      .= ' AND metadata LIKE %s';
			$params[]  = $like_kind;
		}

		$provider_url = trim( (string) $provider_url );
		if ( $provider_url !== '' ) {
			$like_url = '%' . $wpdb->esc_like( '"provider_url":"' . $provider_url . '"' ) . '%';
			$sql     .= ' AND metadata LIKE %s';
			$params[] = $like_url;
		}

		$scope_type = (string) ( $scope['scope_type'] ?? 'private' );
		$scope_id   = (string) ( $scope['scope_id'] ?? '' );
		if ( $scope_type === 'group' ) {
			$like_scope_type = '%' . $wpdb->esc_like( '"scope_type":"group"' ) . '%';
			$sql .= ' AND metadata LIKE %s';
			$params[] = $like_scope_type;
			if ( $scope_id !== '' ) {
				$like_scope_id = '%' . $wpdb->esc_like( '"scope_id":"' . $scope_id . '"' ) . '%';
				$sql .= ' AND metadata LIKE %s';
				$params[] = $like_scope_id;
			}
		}

		$sql .= ' ORDER BY id DESC LIMIT 1';
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return null;
		}

		$notebook_id   = (int) ( $row['project_id'] ?? 0 );
		$notebook_name = '';
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
			if ( is_array( $nb ) ) {
				$notebook_name = (string) ( $nb['name'] ?? '' );
			}
		}

		return array(
			'source_id'     => (int) $row['id'],
			'notebook_id'   => $notebook_id,
			'notebook_name' => $notebook_name,
		);
	}
}
