<?php
/**
 * BizCity Zalo Personal — tell the Hub the owner packs of a number changed (PHASE-0.87 CL-2 / CL-D4, projection-pack@1.1 §2).
 *
 * `POST <HUB>/zalo-hub/packs/invalidate {account_ids, kinds, reason}` (Bearer site 1API key). The Hub relays to every cell that
 * hosts one of those numbers; the cell re-pulls the kinds (no data travels in the notice). Losing a notice is harmless: cells
 * reconcile every 10 minutes.
 *
 * Sources, all folded into ONE call DEBOUNCE seconds after the LAST event (trailing, same reasoning as the Guru invalidate):
 *  - a daily notebook of a number changed (ingest, source delete, stats dirty, notebook deleted) ⇒ that number
 *  - agent modes changed (role, per-user grant, CRM staff role), Bot Studio saved                 ⇒ every zalo-hub number
 *
 * // @axis twin-agent-axis@1 seam SEAM-5
 *
 * @package BizCity_Zalo_Personal
 * @since   PHASE-0.87 (2026-09-30)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Pack_Invalidate', false ) ) {
	return;
}

final class BizCity_Zalo_Pack_Invalidate {

	const PATH           = '/zalo-hub/packs/invalidate';
	const PENDING_OPTION = 'bizcity_zalo_pack_invalidate_pending';
	const STATE_OPTION   = 'bizcity_zalo_pack_invalidate_state';
	const CRON_RUN       = 'bizcity_zalo_pack_invalidate_run';
	const LOCK           = 'bizcity_zalo_pack_invalidate_lock';
	/** Trailing debounce; with the cell pull it keeps a change inside the 60 s pack freshness (D-TAA-3). */
	const DEBOUNCE       = 30;
	/** Kinds a notebook change touches. */
	const KINDS          = array( 'owner_knowledge', 'notebook_meta' );
	/** Every owner pack kind — an access change can flip the enabled state of any of them. */
	// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-6 — + catalog (product facts, moves with Woo product / stock changes).
	const ALL_KINDS      = array( 'owner_knowledge', 'notebook_meta', 'sales', 'orders', 'customers', 'stock', 'astro_self', 'catalog' );
	/** Reason priority when several kinds of change share one call. */
	const REASONS        = array( 'access_changed', 'source_changed', 'notebook_changed' );
	const MAX_ITEMS      = 200;

	/**
	 * Test seams: accounts(): string[] zalo-hub account ids of this site · account_of_notebook(nb): string ('' = not a daily
	 * notebook of a number) · sender(body): array · now(): int
	 *
	 * @var array<string,callable>
	 */
	public static $readers = array();

	public static function boot(): void {
		add_action( 'bizcity_kg_after_ingest_central', array( __CLASS__, 'on_ingest_central' ), 20, 2 );
		add_action( 'bizcity_kg_after_extension_ingest', array( __CLASS__, 'on_extension_ingest' ), 20, 3 );
		add_action( 'bizcity_kg_after_source_delete', array( __CLASS__, 'on_source_delete' ), 20, 2 );
		add_action( 'bizcity_kg_notebook_stats_dirty', array( __CLASS__, 'on_notebook' ), 20, 1 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'on_notebook' ), 20, 1 );
		add_action( 'bizcity_agent_modes_changed', array( __CLASS__, 'on_all' ), 20, 0 );
		add_action( 'bizcity_bot_config_changed', array( __CLASS__, 'on_all' ), 20, 0 );
		// [2026-10-01] PHASE-0.87 CL-2 — business sources (Woo orders/stock, CRM) say which kinds changed.
		add_action( 'bizcity_twin_agent_packs_changed', array( __CLASS__, 'on_packs_changed' ), 20, 1 );
		add_action( self::CRON_RUN, array( __CLASS__, 'run' ) );
	}

	/** Site-wide data changed (every number of the site sees the same business pack). @param string[] $kinds */
	public static function on_packs_changed( $kinds = array() ): void {
		$kinds = array_values( array_intersect( array_map( 'strval', (array) $kinds ), self::ALL_KINDS ) );
		if ( $kinds ) {
			self::queue( array( 'all' => true, 'kinds' => $kinds, 'reason' => 'source_changed' ) );
		}
	}

	public static function on_ingest_central( $source_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	public static function on_extension_ingest( $source_id = 0, $passage_id = 0, $scope = array() ): void {
		self::on_notebook( self::notebook_of_scope( $scope ) );
	}

	public static function on_source_delete( $source_id = 0, $scope_id = '' ): void {
		if ( is_numeric( $scope_id ) ) {
			self::on_notebook( (int) $scope_id );
		}
	}

	public static function on_notebook( $notebook_id = 0 ): void {
		if ( (int) $notebook_id > 0 ) {
			self::queue( array( 'notebooks' => array( (int) $notebook_id ), 'kinds' => self::KINDS, 'reason' => 'notebook_changed' ) );
		}
	}

	public static function on_all(): void {
		self::queue( array( 'all' => true, 'kinds' => self::ALL_KINDS, 'reason' => 'access_changed' ) );
	}

	public static function queue( array $item ): void {
		if ( ! self::accounts() ) {
			return;
		}
		$p = self::pending();
		$p['notebooks'] = array_slice( array_values( array_unique( array_merge( $p['notebooks'], array_map( 'intval', (array) ( $item['notebooks'] ?? array() ) ) ) ) ), 0, self::MAX_ITEMS );
		$p['all']       = $p['all'] || ! empty( $item['all'] );
		$p['kinds']     = array_values( array_unique( array_merge( $p['kinds'], array_map( 'strval', (array) ( $item['kinds'] ?? self::KINDS ) ) ) ) );
		$p['reasons']   = array_values( array_unique( array_merge( $p['reasons'], array( (string) ( $item['reason'] ?? 'notebook_changed' ) ) ) ) );
		update_option( self::PENDING_OPTION, $p, false );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_unschedule_event' ) ) {
			$existing = wp_next_scheduled( self::CRON_RUN );
			if ( false !== $existing ) {
				wp_unschedule_event( $existing, self::CRON_RUN );
			}
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( self::now() + self::DEBOUNCE, self::CRON_RUN );
		}
	}

	/** @return array{ok:bool,code:string,body?:array} */
	public static function run(): array {
		if ( function_exists( 'get_transient' ) && false !== get_transient( self::LOCK ) ) {
			return array( 'ok' => true, 'code' => 'busy' );
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK, 1, 30 );
		}
		try {
			$p = self::pending();
			delete_option( self::PENDING_OPTION );
			$known = self::accounts();
			$ids   = array();
			$nbs   = array(); // [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-4 — notebooks that belong to a number (for uris[])
			if ( $p['all'] ) {
				$ids = $known;
			} else {
				foreach ( $p['notebooks'] as $nb ) {
					$acc = self::account_of_notebook( (int) $nb );
					if ( '' !== $acc && in_array( $acc, $known, true ) ) {
						$ids[] = $acc;
						$nbs[] = (int) $nb;
					}
				}
			}
			$ids = array_values( array_unique( $ids ) );
			sort( $ids );
			if ( ! $ids ) {
				return array( 'ok' => true, 'code' => 'nothing_to_send' );
			}
			$reason = 'notebook_changed';
			foreach ( self::REASONS as $r ) {
				if ( in_array( $r, $p['reasons'], true ) ) {
					$reason = $r;
					break;
				}
			}
			$kinds = array_values( array_intersect( self::ALL_KINDS, $p['kinds'] ? $p['kinds'] : self::KINDS ) );
			$body  = array( 'account_ids' => array_slice( $ids, 0, self::MAX_ITEMS ), 'kinds' => $kinds, 'reason' => $reason, 'uris' => self::uris( $kinds, $nbs ) );
			$result = self::send( $body );
			$ok     = ! empty( $result['ok'] ) || ! empty( $result['success'] );
			$code   = $ok ? 'sent' : (string) ( $result['code'] ?? 'managed_bridge_unavailable' );
			update_option( self::STATE_OPTION, array( 'at' => self::now(), 'ok' => $ok, 'code' => $code, 'accounts' => count( $body['account_ids'] ), 'reason' => $body['reason'], 'cells' => (int) ( $result['cells'] ?? 0 ) ), false );
			return array( 'ok' => $ok, 'code' => $code, 'body' => $body );
		} finally {
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::LOCK );
			}
		}
	}

	/**
	 * [2026-10-01 Claude Opus 5.5] PHASE-0.88 L2-4 — additive `uris[]` (bizcity-resource-uri@1) of what changed: one
	 * `bizcity://pack/<kind>` per kind, `bizcity://notebook/<id>` per changed notebook, `bizcity://product/catalog` when the
	 * catalog moved. The Hub rebuilds its relay body from account_ids/kinds/reason, so older Hubs/cells ignore it.
	 *
	 * @return string[]
	 */
	public static function uris( array $kinds, array $notebooks = array() ): array {
		$out = array();
		foreach ( $notebooks as $nb ) {
			if ( (int) $nb > 0 ) {
				$out[] = 'bizcity://notebook/' . (int) $nb;
			}
		}
		foreach ( $kinds as $kind ) {
			$out[] = 'bizcity://pack/' . $kind;
		}
		if ( in_array( 'catalog', $kinds, true ) ) {
			$out[] = 'bizcity://product/catalog';
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_ITEMS );
	}

	private static function pending(): array {
		$p = function_exists( 'get_option' ) ? get_option( self::PENDING_OPTION, array() ) : array();
		$p = is_array( $p ) ? $p : array();
		return array(
			'notebooks' => array_map( 'intval', (array) ( $p['notebooks'] ?? array() ) ),
			'all'       => ! empty( $p['all'] ),
			'kinds'     => array_map( 'strval', (array) ( $p['kinds'] ?? array() ) ),
			'reasons'   => array_map( 'strval', (array) ( $p['reasons'] ?? array() ) ),
		);
	}

	private static function notebook_of_scope( $scope ): int {
		$scope = is_array( $scope ) ? $scope : ( is_object( $scope ) ? (array) $scope : array() );
		if ( isset( $scope['notebook_id'] ) ) {
			return (int) $scope['notebook_id'];
		}
		return 'notebook' === (string) ( $scope['scope_type'] ?? '' ) ? (int) ( $scope['scope_id'] ?? 0 ) : 0;
	}

	/** Per-request memo: a bulk ingest fires `bizcity_kg_notebook_stats_dirty` many times in one request. */
	private static $accounts_memo = null;

	public static function reset(): void {
		self::$accounts_memo = null;
	}

	/** @return string[] */
	private static function accounts(): array {
		if ( null !== self::$accounts_memo ) {
			return self::$accounts_memo;
		}
		if ( isset( self::$readers['accounts'] ) ) {
			return self::$accounts_memo = array_map( 'strval', (array) call_user_func( self::$readers['accounts'] ) );
		}
		if ( ! class_exists( 'BizCity_Zalo_Hub_Config_Sync' ) ) {
			return self::$accounts_memo = array();
		}
		return self::$accounts_memo = array_map( static function ( $a ) { return (string) $a['bridge_id']; }, BizCity_Zalo_Hub_Config_Sync::accounts() );
	}

	/** The number a daily notebook belongs to (`settings.scope_id = zalo_account:<id>`), '' otherwise. */
	private static function account_of_notebook( int $nb ): string {
		if ( isset( self::$readers['account_of_notebook'] ) ) {
			return (string) call_user_func( self::$readers['account_of_notebook'], $nb );
		}
		global $wpdb;
		if ( $nb <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return '';
		}
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM ' . BizCity_KG_Database::instance()->tbl_notebooks() . ' WHERE id = %d', $nb ) );
		$st  = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $st ) || 'zalo_personal' !== ( $st['channel'] ?? '' ) ) {
			return '';
		}
		$scope = (string) ( $st['scope_id'] ?? '' );
		return 0 === strpos( $scope, 'zalo_account:' ) ? substr( $scope, strlen( 'zalo_account:' ) ) : '';
	}

	private static function send( array $body ): array {
		if ( isset( self::$readers['sender'] ) ) {
			return (array) call_user_func( self::$readers['sender'], $body );
		}
		if ( ! class_exists( 'BizCity_Zalo_Personal_Hub_Client' ) || ! BizCity_Zalo_Personal_Hub_Client::instance()->is_ready_fast() ) {
			return array( 'success' => false, 'code' => 'api_key_missing' );
		}
		return BizCity_Zalo_Personal_Hub_Client::instance()->post_managed_path( self::PATH, $body );
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}
}
