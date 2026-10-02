<?php
/**
 * BizCity_Zalo_Personal_Knowledge_Capture — what the OWNER of a zalo-hub Zalo Cá nhân number sends the bot in their own
 * 1-1 chat with it goes into a per-number, per-day KG-Hub notebook (PHASE-0.87 CL-D1, contract `owner-capture@1`).
 *
 * // [2026-09-30 Claude Opus 5.5] PHASE-0.87 CL-D1 — replaces the PHASE-0.86 S86-1 "Cloud của tôi + groups" source model
 * // (superseded, D-TAA-2/D-TAA-8): the single entry point is now BizCity_Zalo_Owner_Capture_REST, which the cell reaches
 * // through the Hub only when the sender is the account's UID chủ, in a 1-1 chat with the number. No group/self-thread
 * // heuristic runs on the site any more — the REST gate already proved the origin before this class ever schedules a job.
 *
 * Owner decision (2026-09-30, D-TAA-8): a file the owner sends is auto-captured; "ghi nhớ …" ⇒ the owner tool
 * `notebook_remember` sends a text item instead. Anyone else asking to save something never reaches this class — the
 * agent declines in its own reply (R-TAA-15), so the site never sees a non-owner capture request at all.
 *
 * `from_owner_capture()` only schedules a single cron event on the job registered with BizCity_Cron_Manager (R-CRON-META,
 * adopt-only). The download (Zalo CDN URLs expire) and the ingest run in that job, never inside the REST request.
 * Diagnostics CLI never schedules nor runs it (R-CLI-ASYNC-ISOLATION).
 *
 * Silent by construction (D-H7, Bot Studio owns the turn): the `inbound{}` handed to the bridge has no `chat_id` and
 * `notify => false`, so BizCity_KG_Channel_Progress_Notifier never replies into Zalo — the agent's own turn already
 * confirmed the save.
 *
 * This class never attaches a notebook to a Guru and never touches `scope.knowledge` (R-GP-6 / R-GS-3: notebooks are
 * opt-in, off by default) — binding is CL-D3.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Zalo_Personal_Knowledge_Capture {

	const CRON_HOOK = 'bizcity_zalo_personal_knowledge_capture';
	/** BizCity_Cron_Manager job id (R-CRON-META). */
	const JOB_ID    = 'zalo_personal.knowledge_capture';
	const CHANNEL   = 'zalo_personal';
	/** Scope prefix: one notebook per number per day, never per customer. */
	const SCOPE_PREFIX = 'zalo_account:';
	/** Filter: return false to turn capture off for a number (or everywhere). */
	const FILTER_ENABLED = 'bizcity_zalo_personal_knowledge_capture_enabled';

	/** Owner-capture item kinds that carry a downloadable attachment (contract owner-capture@1). */
	const ATTACHMENT_KINDS = array( 'image', 'file', 'audio' );

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
		add_action( 'init', array( __CLASS__, 'register_job' ), 20 );
	}

	/**
	 * Register the single-event job with the central cron registry so every run leaves meta (R-CRON-META).
	 * Adopt-only: no recurring loop, the hook only fires for events scheduled by from_owner_capture().
	 */
	public static function register_job(): void {
		if ( self::in_diagnostics_cli() || ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::CRON_HOOK,
			'interval'    => 'hourly',
			'owner'       => 'plugins/bizcity-zalo-personal',
			'description' => 'PHASE-0.87 CL-D1: owner-capture@1 file/remember into the per-number daily KG notebook.',
			'retention'   => 7,
			'adopt_only'  => true,
		) );
	}

	/**
	 * The one rule. Pure, so it is unit-testable. '' = capture; anything else is the reason to skip (logged).
	 * Group/cloud never enter this gate any more: BizCity_Zalo_Owner_Capture_REST only calls from_owner_capture()
	 * once it already proved the message came from the number's UID chủ in a 1-1 chat (R-TAA-15).
	 *
	 * @param array $f { enabled:bool, provider:string, owner_user_id:int, has_items:bool }
	 */
	public static function owner_capture_skip_reason( array $f ): string {
		if ( empty( $f['enabled'] ) ) {
			return 'disabled';
		}
		if ( (string) ( $f['provider'] ?? '' ) !== 'zalo_hub' ) {
			return 'provider_not_zalo_hub';
		}
		if ( (int) ( $f['owner_user_id'] ?? 0 ) <= 0 ) {
			return 'no_owner';
		}
		if ( empty( $f['has_items'] ) ) {
			return 'no_items';
		}
		return '';
	}

	/**
	 * Owner-capture@1 items → this class's internal item shape ({kind, attachment} or {kind:text, content}).
	 * `kind=file` items with an http(s) url; `kind=remember` sends `kind:text, text:` items instead.
	 *
	 * @param array $raw_items contract `items[]` ({kind:image|file|audio, url, file_name?} or {kind:text, text, title_hint?})
	 * @return array<int,array<string,mixed>>
	 */
	public static function items_from_owner_capture( array $raw_items ): array {
		$out = array();
		foreach ( $raw_items as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $it['kind'] ?? '' ) );
			if ( 'text' === $kind ) {
				$text = trim( (string) ( $it['text'] ?? '' ) );
				if ( '' === $text ) {
					continue;
				}
				$out[] = array( 'kind' => 'text', 'content' => $text );
				continue;
			}
			$url = trim( (string) ( $it['url'] ?? '' ) );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$att_kind = in_array( $kind, self::ATTACHMENT_KINDS, true ) ? $kind : 'file';
			$att      = array( 'kind' => $att_kind, 'url' => $url, 'source_url' => $url );
			$name     = (string) ( $it['file_name'] ?? '' );
			if ( '' !== $name ) {
				$att['file_name'] = $name;
			}
			$out[] = array( 'kind' => $att_kind, 'attachment' => $att );
		}
		return $out;
	}

	/**
	 * Called by BizCity_Zalo_Owner_Capture_REST after the callback Bearer + `owner-capture@1` payload verified.
	 * Schedules the ingest job; never touches Zalo or the KG bridge in the request itself.
	 *
	 * @param string $bridge_id       zalo-hub account id (contract `account_id`)
	 * @param array  $raw_items       contract `items[]`
	 * @param string $idempotency_key contract `idempotency_key` — becomes the per-item dedupe key downstream
	 * @param int    $ts              contract `sent_at` (unix time), 0 = now
	 * @param array  $principal       owner-capture@1.1 {user_hash, role, kind}; empty / no role = the owner (wave 1)
	 * @return array { ok:bool, code:string, duplicate?:bool }
	 */
	public static function from_owner_capture( string $bridge_id, array $raw_items, string $idempotency_key, int $ts = 0, array $principal = array() ): array {
		// [2026-09-30 Claude Opus 5.5] PHASE-0.87 — R-CLI-ASYNC-ISOLATION §3.2: check before any schedule.
		if ( self::in_diagnostics_cli() ) {
			return array( 'ok' => false, 'code' => 'cli_isolated' );
		}
		$bridge_id       = sanitize_text_field( $bridge_id );
		$idempotency_key = sanitize_text_field( $idempotency_key );
		if ( '' === $bridge_id || '' === $idempotency_key ) {
			return array( 'ok' => false, 'code' => 'invalid_param' );
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			return array( 'ok' => false, 'code' => 'mapping_unavailable' );
		}
		$db_account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_id );
		if ( ! is_array( $db_account ) ) {
			return array( 'ok' => false, 'code' => 'account_not_found' );
		}
		$items = self::items_from_owner_capture( $raw_items );
		$owner = (int) ( $db_account['owner_user_id'] ?? 0 );
		// [2026-10-01 Claude Opus 5.5] PHASE-0.87 W2-4 — the file lands in the notebook of the person who sent it (doc 50 §5.4).
		$role = 'owner';
		if ( '' !== (string) ( $principal['role'] ?? '' ) ) {
			$who = self::capture_recipient( $bridge_id, $owner, $principal );
			if ( null === $who ) {
				self::log( 'owner_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'capture_principal_unknown' ) );
				return array( 'ok' => false, 'code' => 'capture_principal_unknown' );
			}
			$owner = $who['user_id'];
			$role  = $who['role'];
		}
		$reason = self::owner_capture_skip_reason( array(
			'enabled'       => (bool) apply_filters( self::FILTER_ENABLED, true, $bridge_id ),
			'provider'      => class_exists( 'BizCity_Zalo_Account_Flags' ) ? BizCity_Zalo_Account_Flags::provider( $bridge_id ) : '',
			'owner_user_id' => $owner,
			'has_items'     => ! empty( $items ),
		) );
		if ( '' !== $reason ) {
			self::log( 'owner_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => $reason ) );
			return array( 'ok' => false, 'code' => $reason );
		}
		$ts  = $ts > 0 ? $ts : time();
		$job = array(
			'blog_id'        => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'bridge_id'      => $bridge_id,
			'owner_user_id'  => $owner,
			'label'          => (string) ( $db_account['label'] ?? '' ),
			'day_key'        => wp_date( 'Ymd', $ts ),
			// Own key namespace: never collides with a plain message_id even if some future source reuses one.
			'dedupe_key'     => 'oc:' . $idempotency_key,
			'origin'         => 'owner_capture',
			'thread_kind'    => 'owner_1to1',
			'crm_message_id' => 0,
			'actor_user_id'  => $owner,
			'role'           => $role,
			'items'          => $items,
		);
		// WP ignores the same hook+args within 10 minutes: a Hub retry of the same idempotency_key schedules nothing new.
		// The real dedupe (survives past that window, and past an already-run job) is the per-item message_id lookup
		// BizCity_KG_Channel_Notebook_Bridge does inside capture() below — this flag is only the fast, best-effort signal.
		$already   = (bool) wp_next_scheduled( self::CRON_HOOK, array( $job ) );
		$scheduled = $already ? true : wp_schedule_single_event( time(), self::CRON_HOOK, array( $job ) );
		if ( false === $scheduled ) {
			self::log( 'knowledge_capture_schedule_failed', array( 'account_id' => $bridge_id, 'origin' => 'owner_capture', 'items' => count( $items ) ), 'error' );
			return array( 'ok' => false, 'code' => 'schedule_failed' );
		}
		if ( ! $already ) {
			self::log( 'knowledge_capture_queued', array( 'account_id' => $bridge_id, 'origin' => 'owner_capture', 'items' => count( $items ), 'thread' => 'owner_1to1' ) );
			// Zalo CDN links expire: wake WP-cron now instead of waiting for the next page view (non-blocking).
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
		return array( 'ok' => true, 'code' => '', 'duplicate' => $already );
	}

	/**
	 * Wave 2 (W2-4): who receives an owner-capture@1.1 item. The owner by hash (or `role: owner` with no staff match), or an
	 * active staff member of THIS number whose `notebook` mode + matching capture switch are on. null = refuse (403).
	 *
	 * @param array $principal {user_hash, role, kind}
	 * @return array{user_id:int,role:string}|null
	 */
	public static function capture_recipient( string $bridge_id, int $owner_user_id, array $principal ): ?array {
		$hash = strtolower( trim( (string) ( $principal['user_hash'] ?? '' ) ) );
		$role = sanitize_key( (string) ( $principal['role'] ?? '' ) );
		$kind = 'remember' === (string) ( $principal['kind'] ?? '' ) ? 'remember' : 'files';
		if ( 'owner' === $role ) {
			$owner_hash = $owner_user_id > 0 && class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::user_hash( $owner_user_id ) : '';
			return ( '' === $hash || ( '' !== $owner_hash && hash_equals( $owner_hash, $hash ) ) ) && $owner_user_id > 0 ? array( 'user_id' => $owner_user_id, 'role' => 'owner' ) : null;
		}
		if ( 'staff' !== $role || '' === $hash || ! class_exists( 'BizCity_Zalo_Agent_Principals' ) ) {
			return null;
		}
		$p = BizCity_Zalo_Agent_Principals::by_hash( $bridge_id, $hash );
		if ( null === $p || 'staff' !== $p['role'] || empty( $p['capture'][ $kind ] ) ) {
			return null;
		}
		return array( 'user_id' => (int) $p['user_id'], 'role' => 'staff' );
	}

	/**
	 * Cron job: resolve the per-number daily notebook and ingest the files. Never replies anywhere.
	 *
	 * @param array $job { blog_id, bridge_id, owner_user_id, label, day_key, dedupe_key, origin, thread_kind, actor_user_id, items[] }
	 */
	public static function run_job( $job ): void {
		// [2026-09-30 Claude Opus 5.5] PHASE-0.87 — R-CLI-ASYNC-ISOLATION §3.3: first line of the worker.
		if ( self::in_diagnostics_cli() || ! is_array( $job ) ) {
			return;
		}
		$bridge_id = (string) ( $job['bridge_id'] ?? '' );
		$blog_id   = (int) ( $job['blog_id'] ?? 0 );
		$switched  = false;
		if ( $blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && (int) get_current_blog_id() !== $blog_id ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}
		try {
			self::capture( $job, $bridge_id );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * "Zalo <number label> dd/mm" — stable per number and day, so the bridge finds the same notebook all day.
	 * A label that is a phone number is masked to its last 3 digits (the title travels to the cell bundle).
	 * The title is the per-day lookup key: it is built in the site locale, so a locale change starts a new notebook.
	 */
	public static function notebook_title( string $label, string $bridge_id, string $day_key ): string {
		$name = self::mask_label( trim( $label ) !== '' ? trim( $label ) : $bridge_id );
		$date = preg_match( '/^\d{8}$/', $day_key ) ? substr( $day_key, 6, 2 ) . '/' . substr( $day_key, 4, 2 ) : '';
		// [2026-10-01] Fixed ASCII-safe format, no gettext: the title is the per-day lookup key and travels to the cell, and a translated/decorated
		// format showed tofu boxes around the number and date in the notebook list and in the answer. Invisible/bidi characters are stripped.
		$title = trim( 'Zalo ' . $name . ' ' . $date );
		$title = (string) preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $title );
		return str_replace( '…', '...', $title );
	}

	/** A label made mostly of digits (a phone number) shows only "…" + its last 3 digits. */
	public static function mask_label( string $label ): string {
		$digits = preg_replace( '/\D+/', '', $label );
		if ( strlen( (string) $digits ) >= 8 && strlen( (string) $digits ) >= (int) ( strlen( $label ) * 0.6 ) ) {
			return '…' . substr( (string) $digits, -3 );
		}
		return $label;
	}

	// ── internals ────────────────────────────────────────────────────────

	private static function capture( array $job, string $bridge_id ): void {
		if ( ! class_exists( 'BizCity_KG_Channel_Notebook_Bridge' ) ) {
			self::done( 'knowledge_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'kg_hub_not_loaded' ), 'invalid_metadata' );
			return;
		}
		$owner   = (int) ( $job['owner_user_id'] ?? 0 );
		$day_key = (string) ( $job['day_key'] ?? '' );
		if ( $owner <= 0 || '' === $bridge_id || ! get_userdata( $owner ) ) {
			self::done( 'knowledge_capture_skipped', array( 'account_id' => $bridge_id, 'reason' => 'no_owner' ), 'invalid_metadata' );
			return;
		}
		$title = self::notebook_title( (string) ( $job['label'] ?? '' ), $bridge_id, $day_key );
		$items = array();
		foreach ( (array) ( $job['items'] ?? array() ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			// One message id per item: the bridge dedupes on it, so a retried job never adds the same item twice.
			$mid = (string) ( $job['dedupe_key'] ?? '' ) . ':' . $i;
			$row = array(
				'kind'       => (string) ( $item['kind'] ?? 'file' ),
				'title_hint' => $title,
				'message_id' => $mid,
			);
			if ( isset( $item['attachment'] ) && is_array( $item['attachment'] ) ) {
				$row['attachment'] = array_merge( $item['attachment'], array( 'message_id' => $mid ) );
			} elseif ( isset( $item['content'] ) ) {
				$row['content'] = (string) $item['content'];
			} else {
				continue;
			}
			$items[] = $row;
		}
		if ( empty( $items ) ) {
			return;
		}

		// A fixed "Zalo <number> dd/mm" title: no LLM naming call per file.
		add_filter( 'bizcity_kg_notebook_bridge_use_llm_title', '__return_false', 99 );
		try {
			$res = BizCity_KG_Channel_Notebook_Bridge::instance()->capture_batch( array(
				'user_id'    => $owner,
				'channel'    => self::CHANNEL,
				'chat_id'    => '',
				'chat_kind'  => 'private',
				'scope_type' => 'private',
				'scope_id'   => self::SCOPE_PREFIX . $bridge_id,
				'title_hint' => $title,
				'day_key'    => $day_key,
				// No chat_id + notify:false ⇒ the progress notifier stays silent (D-H7). The rest is provenance only.
				'inbound'    => array(
					'platform'       => 'ZALO_PERSONAL',
					'account_id'     => $bridge_id,
					'thread_kind'    => (string) ( $job['thread_kind'] ?? '' ),
					'crm_message_id' => (int) ( $job['crm_message_id'] ?? 0 ),
					'origin'         => (string) ( $job['origin'] ?? '' ),
					'actor_user_id'  => (int) ( $job['actor_user_id'] ?? 0 ),
					'notify'         => false,
				),
			), $items );
		} finally {
			remove_filter( 'bizcity_kg_notebook_bridge_use_llm_title', '__return_false', 99 );
		}

		if ( is_wp_error( $res ) ) {
			self::done( 'knowledge_capture_failed', array( 'account_id' => $bridge_id, 'reason' => $res->get_error_code(), 'items' => count( $items ) ), 'invalid_param', 'error' );
			return;
		}
		// [2026-10-01] capture_batch() returns a normal array even when an item failed (download/sideload before any placeholder
		// source exists) — it used to be reported as "done" with succeeded=0, so a lost file left no trace here. Surface it.
		$failed = self::failed_items_summary( $res );
		if ( ! empty( $failed ) ) {
			self::done( 'knowledge_capture_failed', array(
				'account_id' => $bridge_id,
				'reason'     => 'items_failed',
				'items'      => count( $items ),
				'failed'     => count( $failed ),
				'succeeded'  => (int) ( $res['succeeded'] ?? 0 ),
				'queued'     => (int) ( $res['queued'] ?? 0 ),
				'errors'     => $failed,
			), 'invalid_param', 'error' );
			return;
		}
		self::done( 'knowledge_capture_done', array(
			'account_id'       => $bridge_id,
			'origin'           => (string) ( $job['origin'] ?? '' ),
			'notebook_id'      => (int) ( $res['notebook_id'] ?? 0 ),
			'notebook_created' => ! empty( $res['notebook_created'] ),
			'total'            => (int) ( $res['total'] ?? count( $items ) ),
			'succeeded'        => (int) ( $res['succeeded'] ?? 0 ),
			'queued'           => (int) ( $res['queued'] ?? 0 ),
		) );
	}

	/**
	 * Failed items of a capture_batch() result as [{kind, error}] (error text capped). Pure.
	 *
	 * @param mixed $res capture_batch() return
	 * @return array<int,array{kind:string,error:string}>
	 */
	public static function failed_items_summary( $res ): array {
		$out = array();
		foreach ( (array) ( is_array( $res ) ? ( $res['failed'] ?? array() ) : array() ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$out[] = array( 'kind' => (string) ( $f['kind'] ?? '' ), 'error' => substr( (string) ( $f['error'] ?? '' ), 0, 200 ) );
		}
		return $out;
	}

	/** Job result: channel JSONL record + cron run meta (R-CRON-META §4 reason buckets). */
	private static function done( string $event, array $context, string $bucket = '', string $level = 'info' ): void {
		self::log( $event, $context, $level );
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		$cron = BizCity_Cron_Manager::instance();
		$cron->note( array_filter( array(
			'account_id'    => (string) ( $context['account_id'] ?? '' ),
			'reason_bucket' => $bucket,
			'counters'      => array_intersect_key( $context, array_flip( array( 'total', 'succeeded', 'queued', 'items' ) ) ),
		) ), self::JOB_ID );
		$cron->note_event( $event, $context, self::JOB_ID );
	}

	private static function log( string $event, array $context, string $level = 'info' ): void {
		// Structured writer (CONTEXT-BANK §1.2 rule 1). Every record carries the exact account: a record without it is
		// dropped (R-CH-10 `account_scope_required`).
		$account_id = (string) ( $context['account_id'] ?? '' );
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) || '' === $account_id ) {
			return;
		}
		unset( $context['account_id'] );
		BizCity_Channel_File_Logger::write_record( array(
			'channel'   => BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			'level'     => 'error' === $level ? BizCity_Channel_File_Logger::LEVEL_ERROR : BizCity_Channel_File_Logger::LEVEL_INFO,
			'event'     => $event,
			'stage'     => 'kg',
			'direction' => 'internal',
			'message'   => 'Zalo Personal knowledge capture.',
			'producer'  => array( 'module' => 'plugins/bizcity-zalo-personal', 'version' => '1.0.0' ),
			'account'   => array( 'account_id' => $account_id, 'scope' => 'exact' ),
			'context'   => $context,
		) );
	}

	private static function in_diagnostics_cli(): bool {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}
}
