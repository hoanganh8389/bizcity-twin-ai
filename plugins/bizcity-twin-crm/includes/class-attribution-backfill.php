<?php
/**
 * PHASE-0.50 C-04 / 0.48F F-UID-04b — one-off, idempotent D4 attribution backfill
 * for CRM orders created before `create_order()` started stamping attribution.
 *
 * Rules (0.48F §3.4):
 *   - Only orders created from a CRM conversation (`_bizcity_crm_conversation_id`)
 *     count toward an employee; web checkout orders are never touched (rule 5).
 *   - An order that already has `_bizcity_crm_attribution` is never overwritten
 *     (rule 4: a later reassignment does not move revenue).
 *   - Latest `crm_conversation_assigned` fact at or before the order's creation
 *     ⇒ `backfill_event`. No fact at all ⇒ the conversation's current assignee
 *     ⇒ `backfill_current` (dashboards label it "ước tính").
 *   - A fact saying the conversation was unassigned at that moment, or no
 *     assignee anywhere, leaves the order unattributed — creator is unknown for
 *     legacy orders, so we do not guess (Guardrail 4).
 *
 * Assignment facts are read from `bizcity_crm_reporting_events`
 * (metric `assignment_count`, `user_id` = assignee), which the reporting
 * projector already writes for every assignment. Every examined order gets the
 * marker `_bizcity_crm_attribution_backfill` so a batch always advances and a
 * re-run is a no-op. Dry-run is the default.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Attribution_Backfill', false ) ) {
	return;
}

final class BizCity_CRM_Attribution_Backfill {

	const VERSION     = '1.0.0';
	const MARKER_META   = '_bizcity_crm_attribution_backfill';
	const CURSOR_OPTION = 'bizcity_crm_attribution_backfill_page';
	const MAX_BATCH     = 200;

	const OUTCOME_EVENT          = 'backfill_event';
	const OUTCOME_CURRENT        = 'backfill_current';
	const OUTCOME_UNATTRIBUTABLE = 'unattributable';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'bizcity crm-attribution-backfill', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * Pure decision (unit-tested): who an old order counts toward.
	 *
	 * @param bool     $event_found      A `crm_conversation_assigned` fact exists at/before the order time.
	 * @param int|null $event_assignee   Assignee in that fact (null/0 = unassigned at that moment).
	 * @param int      $current_assignee Conversation's assignee today (0 = none).
	 * @return array{outcome:string,assignee_id:int}
	 */
	public static function decide( bool $event_found, $event_assignee, int $current_assignee ): array {
		if ( $event_found ) {
			$event_assignee = (int) $event_assignee;
			return $event_assignee > 0
				? array( 'outcome' => self::OUTCOME_EVENT, 'assignee_id' => $event_assignee )
				: array( 'outcome' => self::OUTCOME_UNATTRIBUTABLE, 'assignee_id' => 0 );
		}
		return $current_assignee > 0
			? array( 'outcome' => self::OUTCOME_CURRENT, 'assignee_id' => $current_assignee )
			: array( 'outcome' => self::OUTCOME_UNATTRIBUTABLE, 'assignee_id' => 0 );
	}

	/**
	 * Process one page of orders.
	 *
	 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 C-04 — first site run showed `wc_get_orders()` ignores
	 * `meta_query` on the legacy (post) order store: the "CRM orders without attribution" filter returned every
	 * order, all were skipped without a marker, and the batch never advanced ("examined 0 … more orders remain").
	 * The scan is now store-agnostic: page through all orders by ID with a saved page cursor and filter in PHP.
	 *
	 * @param array{apply?:bool,limit?:int,page?:int} $args `page` overrides the saved cursor (dry-run paging).
	 * @return array{ok:bool,version:string,apply:bool,page:int,scanned:int,examined:int,skipped:array<string,int>,counts:array<string,int>,remaining:bool,next_page:int,code?:string}
	 */
	public static function run_batch( array $args = array() ): array {
		$apply = ! empty( $args['apply'] );
		$limit = max( 1, min( self::MAX_BATCH, (int) ( $args['limit'] ?? 100 ) ) );
		$page  = isset( $args['page'] ) && (int) $args['page'] > 0 ? (int) $args['page'] : max( 1, (int) get_option( self::CURSOR_OPTION, 1 ) );
		$counts = array( self::OUTCOME_EVENT => 0, self::OUTCOME_CURRENT => 0, self::OUTCOME_UNATTRIBUTABLE => 0 );
		$skipped = array( 'not_crm' => 0, 'already_attributed' => 0 );
		$base = array( 'ok' => true, 'version' => self::VERSION, 'apply' => $apply, 'page' => $page, 'scanned' => 0, 'examined' => 0, 'skipped' => $skipped, 'counts' => $counts, 'remaining' => false, 'next_page' => $page );
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return array_merge( $base, array( 'ok' => false, 'code' => 'orders_unavailable' ) );
		}

		$orders = wc_get_orders( array(
			'limit'   => $limit,
			'paged'   => $page,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
			'type'    => 'shop_order',
		) );
		$orders = is_array( $orders ) ? $orders : array();
		$base['scanned']   = count( $orders );
		$base['remaining'] = count( $orders ) >= $limit;
		$base['next_page'] = $base['remaining'] ? $page + 1 : $page;

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { continue; }
			$conversation_id = (int) $order->get_meta( '_bizcity_crm_conversation_id' );
			if ( $conversation_id <= 0 ) { $skipped['not_crm']++; continue; } // Web checkout — never an employee's (rule 5).
			// A create_order() stamp or an earlier backfill wins (rule 4, idempotent re-runs).
			if ( '' !== (string) $order->get_meta( '_bizcity_crm_attribution' ) || '' !== (string) $order->get_meta( self::MARKER_META ) ) { $skipped['already_attributed']++; continue; }
			$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$created_gmt = $created ? gmdate( 'Y-m-d H:i:s', (int) $created->getTimestamp() ) : '';

			$fact = '' !== $created_gmt ? self::assignment_fact_before( $conversation_id, $created_gmt ) : null;
			$current = 0;
			if ( null === $fact && class_exists( 'BizCity_CRM_Repository' ) ) {
				$conversation = BizCity_CRM_Repository::get_conversation( $conversation_id );
				$current = (int) ( is_array( $conversation ) ? ( $conversation['assignee_id'] ?? 0 ) : 0 );
			}
			$decision = self::decide( null !== $fact, null !== $fact ? $fact['user_id'] : null, $current );
			$counts[ $decision['outcome'] ]++;
			$base['examined']++;

			if ( ! $apply ) { continue; }
			if ( $decision['assignee_id'] > 0 ) {
				$order->update_meta_data( '_bizcity_crm_assignee_id', $decision['assignee_id'] );
				$order->update_meta_data( '_bizcity_crm_attribution', $decision['outcome'] );
			}
			$order->update_meta_data( self::MARKER_META, self::VERSION . '|' . $decision['outcome'] . '|' . gmdate( 'Y-m-d' ) );
			$order->save();
		}

		$base['counts']  = $counts;
		$base['skipped'] = $skipped;
		// Only a real run moves the saved cursor; dry-runs page with an explicit `page`.
		if ( $apply && ! isset( $args['page'] ) ) {
			update_option( self::CURSOR_OPTION, $base['next_page'], false );
		}
		if ( $apply && $base['examined'] > 0 && class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			// Counts only — no order, customer or amount in the audit row.
			BizCity_CRM_Audit_Log::log( 'crm_attribution_backfill', 0, 'updated', null, array( 'version' => self::VERSION, 'counts' => $counts ), array( 'user_id' => get_current_user_id() ) );
		}
		return $base;
	}

	/**
	 * Latest assignment fact for the conversation at or before `$at_gmt`, or null.
	 *
	 * @return array{user_id:int|null}|null
	 */
	private static function assignment_fact_before( int $conversation_id, string $at_gmt ): ?array {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_events();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT user_id FROM `{$table}` WHERE metric = 'assignment_count' AND conversation_id = %d AND occurred_at <= %s ORDER BY occurred_at DESC, id DESC LIMIT 1",
			$conversation_id, $at_gmt
		), ARRAY_A );
		if ( ! is_array( $row ) ) { return null; }
		return array( 'user_id' => null === $row['user_id'] ? null : (int) $row['user_id'] );
	}

	// ── REST (admin only) ────────────────────────────────────────────────

	public static function register_routes(): void {
		$ns = defined( 'BIZCITY_CRM_REST_NS' ) ? BIZCITY_CRM_REST_NS : 'bizcity-crm/v1';
		register_rest_route( $ns, '/reports/attribution-backfill', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_run' ),
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'args'                => array(
				'apply' => array( 'type' => 'boolean', 'default' => false ),
				'limit' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => self::MAX_BATCH ),
				// Dry-run paging: pass `next_page` back. Apply without `page` resumes the saved cursor.
				'page'  => array( 'type' => 'integer', 'minimum' => 1 ),
			),
		) );
	}

	public static function rest_run( WP_REST_Request $request ) {
		$args = array(
			'apply' => rest_sanitize_boolean( $request->get_param( 'apply' ) ),
			'limit' => (int) $request->get_param( 'limit' ),
		);
		if ( null !== $request->get_param( 'page' ) ) {
			$args['page'] = (int) $request->get_param( 'page' );
		} elseif ( empty( $args['apply'] ) ) {
			$args['page'] = 1; // A dry-run never depends on (or moves) the apply cursor.
		}
		$result = self::run_batch( $args );
		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response( array_merge( $result, array( 'message' => 'WooCommerce chưa sẵn sàng.', 'hint' => 'Bật WooCommerce rồi chạy lại.', 'help_code' => 'module_not_loaded' ) ), 503 );
		}
		return new WP_REST_Response( $result, 200 );
	}

	// ── WP-CLI ───────────────────────────────────────────────────────────

	/**
	 * Backfill D4 attribution on legacy CRM orders.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write meta. Without it the command only reports what it would do.
	 *
	 * [--batch=<n>]
	 * : Orders per batch (1–200). Default 100.
	 *
	 * [--max-batches=<n>]
	 * : Stop after this many batches. Default 500.
	 *
	 * [--restart]
	 * : Reset the apply cursor to the first page (already-attributed orders are skipped anyway).
	 */
	public static function cli( $args, $assoc ): void {
		$apply = isset( $assoc['apply'] );
		if ( isset( $assoc['restart'] ) ) { delete_option( self::CURSOR_OPTION ); }
		$batch = max( 1, min( self::MAX_BATCH, (int) ( $assoc['batch'] ?? 100 ) ) );
		$max_batches = max( 1, (int) ( $assoc['max-batches'] ?? 500 ) );
		$totals = array( self::OUTCOME_EVENT => 0, self::OUTCOME_CURRENT => 0, self::OUTCOME_UNATTRIBUTABLE => 0 );
		$skipped = array( 'not_crm' => 0, 'already_attributed' => 0 );
		$scanned = 0;
		$examined = 0;
		$remaining = false;
		$page = 1; // Dry-run pages in memory from the start and never touches the saved cursor.
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$run_args = array( 'apply' => $apply, 'limit' => $batch );
			if ( ! $apply ) { $run_args['page'] = $page; }
			$result = self::run_batch( $run_args );
			if ( empty( $result['ok'] ) ) { \WP_CLI::error( 'Orders unavailable: ' . ( $result['code'] ?? 'unknown' ) ); }
			foreach ( $result['counts'] as $key => $count ) { $totals[ $key ] += (int) $count; }
			foreach ( $result['skipped'] as $key => $count ) { $skipped[ $key ] += (int) $count; }
			$scanned  += (int) $result['scanned'];
			$examined += (int) $result['examined'];
			$remaining = ! empty( $result['remaining'] );
			$page = (int) $result['next_page'];
			if ( ! $remaining ) { break; }
		}
		\WP_CLI::log( sprintf( '%s — scanned %d orders: %d CRM orders to attribute (backfill_event=%d, backfill_current=%d, unattributable=%d); skipped web/non-CRM=%d, already attributed=%d%s',
			$apply ? 'APPLIED' : 'DRY-RUN (nothing written; add --apply)',
			$scanned, $examined, $totals[ self::OUTCOME_EVENT ], $totals[ self::OUTCOME_CURRENT ], $totals[ self::OUTCOME_UNATTRIBUTABLE ],
			$skipped['not_crm'], $skipped['already_attributed'],
			$remaining ? ' — stopped at --max-batches, run again to continue' : ''
		) );
	}
}
