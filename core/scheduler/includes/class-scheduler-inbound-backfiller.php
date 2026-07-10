<?php
/**
 * BizCity Scheduler — Inbound Provenance Backfiller (SCH-NC W10).
 *
 * Replaces the original one-shot CLI migration (deleted 2026-06-03) with
 * a probe-friendly, case-based service. Two surfaces consume it:
 *
 *   1. `BizCity_Probe_Scheduler_Inbound_Backfill` — reports per-case row
 *      counts as smoke-test steps (read-only).
 *   2. `BizCity_Site_Provisioner` — registers one installer per case via
 *      `bizcity_register_installers` so the "🔧 Fix" buttons in the
 *      Diagnostics admin page can apply each case idempotently.
 *
 * Cases (each maps 1:1 to a probe step + 1 installer id):
 *   - `ai_plan`        — source IN ('ai_plan','ai_reminder') (TwinBrain pre-W5)
 *   - `workflow`       — source = 'workflow' (automation runner pre-W5)
 *   - `twinbrain`      — source = 'twinbrain' (TwinBrain tools pre-W5)
 *   - `crm_calendar`   — source = 'crm_calendar' (legacy v2 bridge)
 *   - `orphan_type`    — event_type IN canonical-list AND source NOT IN known
 *   - `corrupt_meta`   — metadata column non-NULL but JSON_EXTRACT fails
 *
 * Selection filter (shared by all cases):
 *   metadata IS NULL OR metadata = '' OR JSON_EXTRACT(metadata, '$.inbound') IS NULL
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      SCH-NC W10 (2026-06-03)
 */

// [2026-06-03 Johnny Chu] SCH-NC W10 — case-based backfiller.

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Inbound_Backfiller' ) ) {
	return;
}

final class BizCity_Scheduler_Inbound_Backfiller {

	const VERSION_OPT = 'bizcity_scheduler_inbound_backfill_v';

	/** @var int Default lookback in days when scanning. */
	const DEFAULT_DAYS = 30;

	// [2026-06-04 Johnny Chu] SCH-BC W2 — wp_cache group cho scan() counters.
	const CACHE_GROUP = 'bizcity_sched_backfill';
	const CACHE_TTL   = 300; // 5 minutes

	// [2026-06-04 Johnny Chu] SCH-BC W3 — option prefix gate per-case (expected_ver='1').
	const DONE_OPT_PREFIX = 'bizcity_scheduler_backfill_done_';

	/** @var array<string,array> In-request memo for scan()/apply() (L0). */
	private static $memo_scan  = array();
	private static $memo_apply = array();

	/** @var array<string,string> Canonical event_type adapter list. */
	const CANONICAL_EVENT_TYPES = array(
		'fb_post', 'web_post', 'reminder_zalo', 'telegram_send',
		'reminder_personal', 'automation_workflow',
	);

	/** @var array<string,bool> Sources that historically came from inbound channels. */
	const KNOWN_INBOUND_SOURCES = array(
		'ai_plan'      => true,
		'ai_reminder'  => true,
		'workflow'     => true,
		'twinbrain'    => true,
		'crm_calendar' => true,
	);

	/**
	 * Get cases catalog.
	 *
	 * @return array<string,array{label:string,description:string,where:string,platform:string,intent:string,severity:string}>
	 */
	public static function cases(): array {
		return array(
			'ai_plan' => array(
				'label'       => 'TwinBrain reminders (ai_plan / ai_reminder)',
				'description' => 'Events tạo bởi TwinBrain trước W5 — không có metadata.inbound.',
				'where'       => "source IN ('ai_plan','ai_reminder')",
				'platform'    => 'TWINBRAIN',
				'intent'      => 'reminder',
				'severity'    => 'warning',
			),
			'workflow' => array(
				'label'       => 'Automation workflow events',
				'description' => 'Events từ automation runner trước W5 — emit_crm_bridge chưa pass inbound.',
				'where'       => "source = 'workflow'",
				'platform'    => 'TWINBRAIN',
				'intent'      => 'workflow',
				'severity'    => 'warning',
			),
			'twinbrain' => array(
				'label'       => 'TwinBrain tool create_event',
				'description' => 'Events từ tools/scheduler/* — pre-W5 ctx chưa thread qua build_metadata_from_slots.',
				'where'       => "source = 'twinbrain'",
				'platform'    => 'TWINBRAIN',
				'intent'      => 'tool',
				'severity'    => 'warning',
			),
			'crm_calendar' => array(
				'label'       => 'Legacy CRM calendar bridge',
				'description' => 'Events ngược dòng từ bridge v2 — source=crm_calendar.',
				'where'       => "source = 'crm_calendar'",
				'platform'    => 'TWINBRAIN',
				'intent'      => 'legacy_bridge',
				'severity'    => 'info',
			),
			'orphan_type' => array(
				'label'       => 'Canonical event_type / unknown source',
				'description' => 'Adapter event_type hợp lệ nhưng source ngoài whitelist — workflow row thất lạc.',
				'where'       => sprintf(
					"event_type IN ('%s') AND source NOT IN ('user','%s')",
					implode( "','", self::CANONICAL_EVENT_TYPES ),
					implode( "','", array_keys( self::KNOWN_INBOUND_SOURCES ) )
				),
				'platform'    => 'TWINBRAIN',
				'intent'      => 'orphan',
				'severity'    => 'info',
			),
			'corrupt_meta' => array(
				'label'       => 'Corrupted metadata JSON',
				'description' => 'Cột metadata không NULL nhưng JSON_EXTRACT lỗi → ghi đè block trống + inbound synth.',
				'where'       => "metadata IS NOT NULL AND metadata != '' AND JSON_VALID(metadata) = 0",
				'platform'    => 'TWINBRAIN',
				'intent'      => 'corrupt_meta',
				'severity'    => 'critical',
			),
		);
	}

	/**
	 * Count rows per case (read-only).
	 *
	 * @param int $days Lookback window. Default DEFAULT_DAYS.
	 * @return array<string,int> Map case_id → row count.
	 */
	public static function scan( int $days = self::DEFAULT_DAYS ): array {
		// [2026-06-04 Johnny Chu] SCH-BC W1 — L0 in-request memo.
		$memo_key = (string) $days;
		if ( isset( self::$memo_scan[ $memo_key ] ) ) {
			return self::$memo_scan[ $memo_key ];
		}

		// [2026-06-04 Johnny Chu] SCH-BC W2 — L1 wp_cache cross-request (TTL 5').
		$cache_key = 'scan:' . $days;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return self::$memo_scan[ $memo_key ] = $cached;
		}

		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return array();
		}
		global $wpdb;
		$tbl = BizCity_Scheduler_Manager::instance()->get_table();
		if ( ! $tbl ) {
			return array();
		}

		$cutoff = self::cutoff_sql( $days );

		// [2026-06-04 Johnny Chu] SCH-BC W5 — consolidate 6 COUNT queries → 1
		// aggregated query to eliminate duplicate-query warnings in Query Monitor.
		$no_inbound = "(metadata IS NULL OR metadata = '' OR JSON_EXTRACT(metadata, '\$.inbound') IS NULL)";
		$et_list    = implode( "','", self::CANONICAL_EVENT_TYPES );
		$src_known  = implode( "','", array_keys( self::KNOWN_INBOUND_SOURCES ) );

		$sql = $wpdb->prepare(
			"SELECT
			  SUM(CASE WHEN source IN ('ai_plan','ai_reminder')
			           AND {$no_inbound} THEN 1 ELSE 0 END)           AS cnt_ai_plan,
			  SUM(CASE WHEN source = 'workflow'
			           AND {$no_inbound} THEN 1 ELSE 0 END)           AS cnt_workflow,
			  SUM(CASE WHEN source = 'twinbrain'
			           AND {$no_inbound} THEN 1 ELSE 0 END)           AS cnt_twinbrain,
			  SUM(CASE WHEN source = 'crm_calendar'
			           AND {$no_inbound} THEN 1 ELSE 0 END)           AS cnt_crm_calendar,
			  SUM(CASE WHEN event_type IN ('{$et_list}')
			           AND source NOT IN ('user','{$src_known}')
			           AND {$no_inbound} THEN 1 ELSE 0 END)           AS cnt_orphan_type,
			  SUM(CASE WHEN metadata IS NOT NULL
			           AND metadata != ''
			           AND JSON_VALID(metadata) = 0 THEN 1 ELSE 0 END) AS cnt_corrupt_meta
			FROM {$tbl}
			WHERE created_at >= %s",
			$cutoff
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );
		$out = array(
			'ai_plan'      => (int) ( $row['cnt_ai_plan']      ?? 0 ),
			'workflow'     => (int) ( $row['cnt_workflow']      ?? 0 ),
			'twinbrain'    => (int) ( $row['cnt_twinbrain']     ?? 0 ),
			'crm_calendar' => (int) ( $row['cnt_crm_calendar']  ?? 0 ),
			'orphan_type'  => (int) ( $row['cnt_orphan_type']   ?? 0 ),
			'corrupt_meta' => (int) ( $row['cnt_corrupt_meta']  ?? 0 ),
		);

		wp_cache_set( $cache_key, $out, self::CACHE_GROUP, self::CACHE_TTL );
		return self::$memo_scan[ $memo_key ] = $out;
	}

	/**
	 * Apply backfill for one case (idempotent).
	 *
	 * @param string $case_id
	 * @param int    $days
	 * @param int    $limit  0 = no limit.
	 * @return array{ok:int,skipped:int,failed:int,case:string}
	 */
	public static function apply( string $case_id, int $days = self::DEFAULT_DAYS, int $limit = 0 ): array {
		// [2026-06-04 Johnny Chu] SCH-BC W1 — L0 memoize identical calls within same request.
		$memo_key = $case_id . ':' . $days . ':' . $limit;
		if ( isset( self::$memo_apply[ $memo_key ] ) ) {
			return self::$memo_apply[ $memo_key ];
		}

		$result = array( 'ok' => 0, 'skipped' => 0, 'failed' => 0, 'case' => $case_id );
		$cases  = self::cases();
		if ( ! isset( $cases[ $case_id ] ) ) {
			return self::$memo_apply[ $memo_key ] = $result;
		}
		if ( ! class_exists( 'BizCity_Scheduler_Manager' )
			|| ! class_exists( 'BizCity_Scheduler_Inbound_Provenance' ) ) {
			return self::$memo_apply[ $memo_key ] = $result;
		}
		global $wpdb;
		$tbl = BizCity_Scheduler_Manager::instance()->get_table();
		if ( ! $tbl ) {
			return self::$memo_apply[ $memo_key ] = $result;
		}

		$c           = $cases[ $case_id ];
		$cutoff      = self::cutoff_sql( $days );
		$where_extra = $case_id === 'corrupt_meta'
			? ''
			: " AND (metadata IS NULL OR metadata = '' OR JSON_EXTRACT(metadata, '\$.inbound') IS NULL)";

		$sql = "SELECT id, user_id, ai_context, source, event_type, metadata, created_at
		        FROM {$tbl}
		        WHERE created_at >= %s
		          AND ({$c['where']})
		          {$where_extra}
		        ORDER BY id ASC";

		$args = array( $cutoff );
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( empty( $rows ) ) {
			// [2026-06-04 Johnny Chu] SCH-BC W3 — L2 gate: 0 row → mark done so
			// Site_Provisioner version-gate sau skip hẳn. Invalidate bởi event_*
			// hooks khi có row mới.
			update_option( self::DONE_OPT_PREFIX . $case_id, '1', false );
			return self::$memo_apply[ $memo_key ] = $result;
		}

		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$user_id = (int) $row['user_id'];
			$source  = (string) $row['source'];
			$evtype  = (string) $row['event_type'];
			$ctx     = (string) ( $row['ai_context'] ?? '' );

			// Decode existing metadata (preserve other reserved blocks); reset on
			// corrupt_meta case where JSON_VALID() said no.
			$meta = array();
			if ( $case_id !== 'corrupt_meta' && ! empty( $row['metadata'] ) ) {
				$decoded = json_decode( (string) $row['metadata'], true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			if ( isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ) {
				$result['skipped']++;
				continue; // race-safe.
			}

			$intent_tag = ( $evtype !== '' && $evtype !== 'meeting' )
				? $evtype
				: ( $c['intent'] !== '' ? $c['intent'] : 'backfill' );

			$inbound = BizCity_Scheduler_Inbound_Provenance::build(
				$c['platform'],
				(string) $user_id,
				array(
					'user_id'     => (string) $user_id,
					'captured_at' => (string) $row['created_at'],
					'raw_text'    => $ctx,
					'intent_tag'  => $intent_tag,
				)
			);
			$inbound['_backfilled'] = true;
			$inbound['_case']       = $case_id;
			$inbound['_source']     = $source !== '' ? $source : 'unknown';

			$meta['inbound'] = $inbound;

			$updated = $wpdb->update(
				$tbl,
				array( 'metadata' => wp_json_encode( $meta ) ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( $updated === false ) {
				$result['failed']++;
			} else {
				$result['ok']++;
			}
		}

		// Bump version option so Site_Provisioner can short-circuit re-runs of
		// the aggregate "Auto-Fix-All" sweep when nothing else changes.
		update_option(
			self::VERSION_OPT,
			gmdate( 'Y-m-d\TH:i:s\Z' ) . ':' . $case_id . ':' . $result['ok'],
			false
		);

		// [2026-06-04 Johnny Chu] SCH-BC W3 — set done-gate khi vừa sửa hết
		// (không còn failed pending). Invalidate lại bởi event_* hooks khi có row mới.
		if ( $result['failed'] === 0 ) {
			update_option( self::DONE_OPT_PREFIX . $case_id, '1', false );
		}

		return self::$memo_apply[ $memo_key ] = $result;
	}

	/**
	 * Apply all cases sequentially (used by aggregate installer).
	 *
	 * @param int $days
	 * @return array<string,array{ok:int,skipped:int,failed:int,case:string}>
	 */
	public static function apply_all( int $days = self::DEFAULT_DAYS ): array {
		$out = array();
		foreach ( array_keys( self::cases() ) as $cid ) {
			$out[ $cid ] = self::apply( $cid, $days );
		}
		return $out;
	}

	/* ── Helpers ─────────────────────────────────────────────────── */

	private static function cutoff_sql( int $days ): string {
		$days = max( 1, $days );
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}
	/**
	 * [2026-06-04 Johnny Chu] SCH-BC W4 — invalidate L0/L1/L2 cho tất cả case.
	 *
	 * Fired by listeners on event create/update/delete + sau khi installer
	 * `scheduler_backfill_inbound__*` chạy từ fix-button.
	 */
	public static function invalidate_all(): void {
		self::$memo_scan  = array();
		self::$memo_apply = array();
		wp_cache_delete( 'scan:' . self::DEFAULT_DAYS, self::CACHE_GROUP );
		foreach ( array_keys( self::cases() ) as $cid ) {
			delete_option( self::DONE_OPT_PREFIX . $cid );
		}
	}

	/**
	 * [2026-06-04 Johnny Chu] SCH-BC W4 — register invalidation listeners.
	 * Called once from core/scheduler/bootstrap.php.
	 */
	public static function init(): void {
		add_action( 'bizcity_scheduler_event_created', array( __CLASS__, 'invalidate_all' ) );
		add_action( 'bizcity_scheduler_event_updated', array( __CLASS__, 'invalidate_all' ) );
		add_action( 'bizcity_scheduler_event_deleted', array( __CLASS__, 'invalidate_all' ) );
		add_action( 'bizcity_run_installer_done', static function ( $installer_id ) {
			if ( is_string( $installer_id )
				&& strpos( $installer_id, 'scheduler_backfill_inbound__' ) === 0 ) {
				self::invalidate_all();
			}
		} );
	}}
