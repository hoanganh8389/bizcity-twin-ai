<?php
/**
 * BizCity Zalo Personal — tell the Hub a Guru or one of its notebooks changed (PHASE-0.81 C1.4/C2.2, contract C-10).
 *
 * `POST <HUB>/zalo-hub/guru/invalidate {contract, refs, notebooks, reason}` (Bearer site 1API key, via the Zalo Personal Hub
 * client). The Hub drops its 5-minute profile cache and forwards to every cell of this key; a cell drops its own cache and pulls
 * the notebook pack (C-4) at once. Losing a message is harmless: cells reconcile every 10 minutes.
 *
 * Sources (all debounced into ONE call, DEBOUNCE seconds after the LAST event of a burst — trailing, not leading: a long
 * ingestion job keeps pushing the run out instead of firing once per DEBOUNCE window, S-3 peer review):
 *  - kg-hub notebook content: ingest (central / extension), source delete, stats dirty, notebook deleted  ⇒ notebook_changed
 *  - kg-hub attach / detach of a Guru                                                                     ⇒ binding_changed
 *  - Guru saved / deleted (Knowledge core), Bot Studio config saved                                       ⇒ guru_saved / binding_changed
 * Only Gurus that ANSWER a zalo-hub number of this site are sent, and only notebooks those Gurus use; anything else ⇒ no call.
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.81 (2026-09-27)
 */

// [2026-09-27 Claude Opus 5.5] PHASE-0.81 C1.4/C2.2.
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Hub_Guru_Invalidate', false ) ) {
	return;
}

final class BizCity_Zalo_Hub_Guru_Invalidate {

	const CONTRACT       = 'bizcity-guru-context/1.2';
	const PATH           = '/zalo-hub/guru/invalidate';
	const PENDING_OPTION = 'bizcity_zalo_hub_guru_invalidate_pending';
	const STATE_OPTION   = 'bizcity_zalo_hub_guru_invalidate_state';
	const CRON_RUN       = 'bizcity_zalo_hub_guru_invalidate_run';
	const DEBOUNCE       = 5;
	const MAX_ITEMS      = 200;

	/** Priority when several kinds of change share one call (C-10 carries one `reason`). */
	const REASONS = array( 'guru_saved', 'binding_changed', 'notebook_changed' );

	/** S-3 (peer review) — guards run() against a double WP-Cron fire reading/clearing the pending option at the same time. */
	const LOCK = 'bizcity_zalo_hub_guru_invalidate_lock';

	/**
	 * Test seams: has_accounts(): bool · answering(): array<int,string> character id ⇒ wire ref · notebook_ids(cid): int[] (used ones) ·
	 * character_of_uuid(uuid): int · sender(body): array · now(): int
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	/** Per-request memo of has_accounts() (S-3) — `bizcity_kg_notebook_stats_dirty` alone has 13 call sites and can fire many times
	 * in one bulk-ingestion request; this class re-checks it on the same request's every queue() call otherwise. */
	private static $has_accounts_memo = null;

	public static function reset(): void { self::$has_accounts_memo = null; }

	public static function boot(): void {
		add_action( 'bizcity_kg_after_ingest_central', array( __CLASS__, 'on_ingest_central' ), 20, 2 );
		add_action( 'bizcity_kg_after_extension_ingest', array( __CLASS__, 'on_extension_ingest' ), 20, 3 );
		add_action( 'bizcity_kg_after_source_delete', array( __CLASS__, 'on_source_delete' ), 20, 2 );
		add_action( 'bizcity_kg_notebook_stats_dirty', array( __CLASS__, 'on_notebook' ), 20, 1 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'on_notebook' ), 20, 1 );
		add_action( 'bizcity_kg_guru_attached', array( __CLASS__, 'on_binding' ), 20, 2 );
		add_action( 'bizcity_kg_guru_detached', array( __CLASS__, 'on_binding' ), 20, 2 );
		add_action( 'bizcity_knowledge_character_saved', array( __CLASS__, 'on_guru_saved' ), 20, 1 );
		add_action( 'bizcity_knowledge_character_deleted', array( __CLASS__, 'on_guru_deleted' ), 20, 1 );
		add_action( 'bizcity_bot_config_changed', array( __CLASS__, 'on_bot_config' ), 20, 2 );
		// [2026-09-28 Claude Sonnet 5] PHASE-0.81 S-4 (peer review, confirmed by code) — the quick-edit sheet is the MAIN Bot Studio edit
		// path (prompt/tone/quick FAQ/runtime) and fires no `bizcity_knowledge_character_saved` (only class-guru-service.php does), so
		// without this a Guru content edit through that sheet never reaches C-10 at all: same seam BizCity_Zalo_Hub_Config_Sync::after_rest()
		// already uses for the same route, so the two listeners agree on what counts as "saved".
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_rest' ), 10, 3 );
		add_action( self::CRON_RUN, array( __CLASS__, 'run' ) );
	}

	/** A successful non-GET call to `bizcity-knowledge/v1/characters/{id}/quick-edit` queues a `guru_saved` invalidate for that Guru. */
	public static function after_rest( $response, $handler = null, $request = null ) {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) && method_exists( $request, 'get_method' )
			&& 'GET' !== strtoupper( (string) $request->get_method() )
			&& preg_match( '#^/bizcity-knowledge/v1/characters/(\d+)/quick-edit#', (string) $request->get_route(), $m )
			&& ! ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) )
			&& ! ( is_object( $response ) && method_exists( $response, 'get_status' ) && (int) $response->get_status() >= 300 ) ) {
			self::on_guru_saved( (int) $m[1] );
		}
		return $response;
	}

	/* ================================================================
	 *  Listeners → queue
	 * ================================================================ */

	/** `$scope` = { plugin, scope_type, scope_id } (BizCity_KG_Facade). */
	public static function on_ingest_central( $source_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	public static function on_extension_ingest( $source_id = 0, $passage_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	/** `$scope_id` is the notebook id for notebook sources; any other id is dropped by run() (no Guru uses it). */
	public static function on_source_delete( $source_id = 0, $scope_id = '' ): void {
		if ( is_numeric( $scope_id ) ) { self::on_notebook( (int) $scope_id ); }
	}

	public static function on_notebook( $notebook_id = 0 ): void {
		if ( (int) $notebook_id > 0 ) { self::queue( array( 'notebooks' => array( (int) $notebook_id ), 'reason' => 'notebook_changed' ) ); }
	}

	public static function on_binding( $notebook_id = 0, $guru_uuid = '' ): void {
		self::queue( array( 'notebooks' => array( (int) $notebook_id ), 'uuids' => array( strtolower( trim( (string) $guru_uuid ) ) ), 'reason' => 'binding_changed' ) );
	}

	public static function on_guru_saved( $character_id = 0 ): void {
		if ( (int) $character_id > 0 ) { self::queue( array( 'gurus' => array( (int) $character_id ), 'reason' => 'guru_saved' ) ); }
	}

	/** A deleted Guru no longer answers anything, so its ref is sent without the "answers a number" filter. */
	public static function on_guru_deleted( $character_id = 0 ): void {
		if ( (int) $character_id > 0 ) { self::queue( array( 'deleted' => array( (int) $character_id ), 'reason' => 'guru_saved' ) ); }
	}

	/** `bizcity_bot_config_changed( scope, id )`: a Guru's bot settings (scope, tools…) or a binding / default Guru changed. */
	public static function on_bot_config( $scope = '', $id = 0 ): void {
		if ( 'character' === $scope && (int) $id > 0 ) {
			self::queue( array( 'gurus' => array( (int) $id ), 'reason' => 'guru_saved' ) );
			return;
		}
		self::queue( array( 'all' => true, 'reason' => 'binding_changed' ) ); // which number → which Guru may have moved
	}

	/** Merge into the pending set; the first event of a burst schedules one run DEBOUNCE seconds later. */
	public static function queue( array $item ): void {
		if ( ! self::has_accounts() ) { return; }
		$p = self::pending();
		foreach ( array( 'notebooks', 'gurus', 'deleted' ) as $k ) {
			$p[ $k ] = array_slice( array_values( array_unique( array_filter( array_merge( $p[ $k ], array_map( 'intval', (array) ( $item[ $k ] ?? array() ) ) ) ) ) ), 0, self::MAX_ITEMS );
		}
		$p['uuids']   = array_slice( array_values( array_unique( array_filter( array_merge( $p['uuids'], array_map( 'strval', (array) ( $item['uuids'] ?? array() ) ) ) ) ) ), 0, self::MAX_ITEMS );
		$p['all']     = $p['all'] || ! empty( $item['all'] );
		$p['reasons'] = array_values( array_unique( array_merge( $p['reasons'], array( (string) ( $item['reason'] ?? 'notebook_changed' ) ) ) ) );
		update_option( self::PENDING_OPTION, $p, false );
		// [2026-09-28 Claude Sonnet 5] PHASE-0.81 S-3 (peer review, confirmed) — TRAILING debounce: every event pushes the run out to
		// now()+DEBOUNCE, instead of only the first event of a burst scheduling it (leading-edge). A single edit still invalidates after
		// DEBOUNCE seconds exactly as before; a long ingestion job (many `bizcity_kg_notebook_stats_dirty` fires close together) no longer
		// sends an invalidate to the Hub roughly every DEBOUNCE seconds for its whole duration — it fires once, DEBOUNCE seconds after the
		// job's last dirty event.
		if ( function_exists( 'wp_unschedule_event' ) && function_exists( 'wp_next_scheduled' ) ) {
			$existing = wp_next_scheduled( self::CRON_RUN );
			if ( false !== $existing ) { wp_unschedule_event( $existing, self::CRON_RUN ); }
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) { wp_schedule_single_event( self::now() + self::DEBOUNCE, self::CRON_RUN ); }
	}

	/* ================================================================
	 *  Run → one Hub call
	 * ================================================================ */

	/**
	 * @return array{ok:bool,code:string,body?:array}
	 *
	 * [2026-09-28 Claude Sonnet 5] PHASE-0.81 S-3 (peer review) — a short lock (same pattern as
	 * BizCity_Zalo_Hub_Config_Sync::run()) around the read-then-clear of the pending option, so two overlapping WP-Cron fires of
	 * CRON_RUN cannot both read the same pending set and send it twice. This does not close every race on `queue()` writing the
	 * option from a second, concurrent request while `run()` holds it — that would need an atomic DB primitive WP options don't
	 * give; a lost item there is a known, accepted gap (the same "cells reconcile every 10 minutes" fallback the class doc already
	 * relies on for a dropped invalidate covers it).
	 */
	public static function run(): array {
		if ( function_exists( 'get_transient' ) && false !== get_transient( self::LOCK ) ) {
			return array( 'ok' => true, 'code' => 'busy' );
		}
		if ( function_exists( 'set_transient' ) ) { set_transient( self::LOCK, 1, 30 ); }
		try {
			return self::run_locked();
		} finally {
			if ( function_exists( 'delete_transient' ) ) { delete_transient( self::LOCK ); }
		}
	}

	private static function run_locked(): array {
		$p = self::pending();
		delete_option( self::PENDING_OPTION );
		foreach ( $p['notebooks'] as $nb ) {
			if ( class_exists( 'BizCity_Zalo_Guru_Knowledge_Version' ) ) { BizCity_Zalo_Guru_Knowledge_Version::flush( (int) $nb ); }
		}
		$answering = self::answering();
		$refs = array();
		$notebooks = array();
		foreach ( $answering as $cid => $ref ) {
			$used = self::used_notebooks( (int) $cid );
			if ( $p['all'] || in_array( (int) $cid, $p['gurus'], true ) ) { $refs[ $ref ] = true; }
			foreach ( $p['notebooks'] as $nb ) {
				if ( in_array( (int) $nb, $used, true ) ) { $refs[ $ref ] = true; $notebooks[ (int) $nb ] = true; }
			}
		}
		foreach ( $p['uuids'] as $uuid ) {
			$cid = self::character_of_uuid( $uuid );
			if ( $cid > 0 && isset( $answering[ $cid ] ) ) {
				$refs[ $answering[ $cid ] ] = true;
				foreach ( $p['notebooks'] as $nb ) { $notebooks[ (int) $nb ] = true; } // attach or detach: the cell must re-list either way
			}
		}
		foreach ( $p['deleted'] as $cid ) { $refs[ 'guru:' . (int) $cid ] = true; }
		if ( ! $refs && ! $notebooks ) {
			return array( 'ok' => true, 'code' => 'nothing_to_send' );
		}
		$reason = 'notebook_changed';
		foreach ( self::REASONS as $r ) {
			if ( in_array( $r, $p['reasons'], true ) ) { $reason = $r; break; }
		}
		$refs = array_keys( $refs );
		sort( $refs );
		$nbs = array_map( 'intval', array_keys( $notebooks ) );
		sort( $nbs );
		$body = array( 'contract' => self::CONTRACT, 'refs' => array_slice( $refs, 0, self::MAX_ITEMS ), 'notebooks' => array_slice( $nbs, 0, self::MAX_ITEMS ), 'reason' => $reason );
		$result = self::send( $body );
		$ok = ! empty( $result['ok'] ) || ! empty( $result['success'] );
		$code = $ok ? 'sent' : (string) ( $result['code'] ?? 'managed_bridge_unavailable' );
		update_option( self::STATE_OPTION, array( 'at' => self::now(), 'ok' => $ok, 'code' => $code, 'refs' => count( $body['refs'] ), 'notebooks' => count( $body['notebooks'] ), 'reason' => $reason, 'cells' => (int) ( $result['cells'] ?? 0 ) ), false );
		return array( 'ok' => $ok, 'code' => $code, 'body' => $body );
	}

	/* ================================================================
	 *  Readers
	 * ================================================================ */

	private static function pending(): array {
		$p = function_exists( 'get_option' ) ? get_option( self::PENDING_OPTION, array() ) : array();
		$p = is_array( $p ) ? $p : array();
		return array(
			'notebooks' => array_map( 'intval', (array) ( $p['notebooks'] ?? array() ) ),
			'gurus'     => array_map( 'intval', (array) ( $p['gurus'] ?? array() ) ),
			'deleted'   => array_map( 'intval', (array) ( $p['deleted'] ?? array() ) ),
			'uuids'     => array_map( 'strval', (array) ( $p['uuids'] ?? array() ) ),
			'all'       => ! empty( $p['all'] ),
			'reasons'   => array_map( 'strval', (array) ( $p['reasons'] ?? array() ) ),
		);
	}

	private static function notebook_of_scope( $scope ): int {
		$scope = is_array( $scope ) ? $scope : ( is_object( $scope ) ? (array) $scope : array() );
		if ( isset( $scope['notebook_id'] ) ) { return (int) $scope['notebook_id']; }
		return 'notebook' === (string) ( $scope['scope_type'] ?? '' ) ? (int) ( $scope['scope_id'] ?? 0 ) : 0;
	}

	private static function has_accounts(): bool {
		if ( null !== self::$has_accounts_memo ) { return self::$has_accounts_memo; }
		if ( isset( self::$readers['has_accounts'] ) ) { return self::$has_accounts_memo = (bool) call_user_func( self::$readers['has_accounts'] ); }
		return self::$has_accounts_memo = ( class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) && BizCity_Zalo_Hub_Config_Sync::has_accounts() );
	}

	/**
	 * Gurus answering a zalo-hub number of this site ⇒ wire ref (same gate-0 rule as the config sync: AI on + no Guru = default Guru).
	 *
	 * @return array<int,string>
	 */
	public static function answering(): array {
		if ( isset( self::$readers['answering'] ) ) { return (array) call_user_func( self::$readers['answering'] ); }
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) || ! class_exists( 'BizCity_Guru_Context_Resolver' ) || ! class_exists( 'BizCity_Channel_Binding' ) ) { return array(); }
		$default = BizCity_Guru_Context_Resolver::default_character_id( false );
		$out = array();
		foreach ( BizCity_Zalo_Hub_Config_Sync::accounts() as $acc ) {
			$b    = BizCity_Channel_Binding::resolve( BizCity_Zalo_Hub_Config_Sync::PLATFORM, (string) $acc['bridge_id'] );
			$cid  = is_array( $b ) ? (int) ( $b['character_id'] ?? 0 ) : 0;
			$mode = is_array( $b ) ? (string) ( $b['mode'] ?? '' ) : '';
			if ( $cid <= 0 && in_array( $mode, array( 'auto', 'hybrid' ), true ) ) { $cid = $default; }
			if ( $cid > 0 ) { $out[ $cid ] = $cid === $default ? BizCity_Guru_Context_Resolver::DEFAULT_REF : 'guru:' . $cid; }
		}
		return $out;
	}

	/** Notebooks a Guru actually uses (attached AND scope base+notebooks). @return int[] */
	private static function used_notebooks( int $cid ): array {
		if ( isset( self::$readers['notebook_ids'] ) ) { return array_map( 'intval', (array) call_user_func( self::$readers['notebook_ids'], $cid ) ); }
		if ( ! class_exists( 'BizCity_Guru_Context_Resolver' ) ) { return array(); }
		$s = BizCity_Guru_Context_Resolver::scope( $cid );
		return 'base+notebooks' === $s['knowledge'] ? array_map( 'intval', $s['notebook_ids'] ) : array();
	}

	private static function character_of_uuid( string $uuid ): int {
		if ( isset( self::$readers['character_of_uuid'] ) ) { return (int) call_user_func( self::$readers['character_of_uuid'], $uuid ); }
		global $wpdb;
		if ( '' === $uuid || ! isset( $wpdb ) ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bizcity_characters WHERE guru_uuid = %s LIMIT 1", $uuid ) );
	}

	private static function send( array $body ): array {
		if ( isset( self::$readers['sender'] ) ) { return (array) call_user_func( self::$readers['sender'], $body ); }
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return array( 'success' => false, 'code' => 'api_key_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::PATH, $body );
	}

	private static function now(): int { return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time(); }
}
