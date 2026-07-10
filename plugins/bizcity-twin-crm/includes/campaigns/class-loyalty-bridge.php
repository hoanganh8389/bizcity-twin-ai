<?php
/**
 * BizCity CRM — Loyalty Bridge (PHASE 0.35 M6.W5).
 *
 * Bridges CRM contacts ↔ legacy `wp_user_points` ledger so:
 *   - M6.W4 conversion event can auto-award `loyalty_points_award`
 *   - M2 automation `award_points` action can fire from any rule
 *   - REST surface (`/loyalty/award`, `/loyalty/balance/{id}`) lets external
 *     plugins integrate
 *
 * The legacy schema is INSERT-only:
 *   wp_user_points       — credits  (cols: code, store_name, product, phone,
 *                          client_id, user_points, time, …)
 *   wp_user_points_exchange — debits (cols: phone, points, remaining_points, …)
 *
 * Dedupe key: we reuse the `code` column with prefix `evt_<event_uuid>` —
 * the legacy column is a VARCHAR(255) used for promo codes; we add a uniqueness
 * check before INSERT (the column has no UNIQUE constraint we control, so we
 * SELECT first; cheap because lookup is by phone+code).
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M6.W5
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Loyalty_Bridge {

	const STORE_PREFIX = 'campaign:'; // namespace for awards triggered by campaigns.
	const PRODUCT_NOOP = 'loyalty_award';

	public static function register(): void {
		// Auto-award on conversion (M6.W4 → M6.W5 wiring).
		// Event_Emitter::emit() fans out via `bizcity_crm_event_<type>` — raw
		// `crm_campaign_conversion_recorded` is never fired (BUG fixed M6.W12).
		add_action( 'bizcity_crm_event_crm_campaign_conversion_recorded', array( __CLASS__, 'on_conversion' ), 25, 1 );
	}

	/* ============================================================
	 * Event listener — auto-award when a campaign converts
	 * ============================================================ */

	public static function on_conversion( $payload ): void {
		if ( ! is_array( $payload ) ) { return; }
		$points = (int) ( $payload['loyalty_points_award'] ?? 0 );
		if ( $points <= 0 ) { return; }

		$contact_id = (int) ( $payload['contact_id'] ?? 0 );
		if ( $contact_id <= 0 ) { return; }

		$subject = array(
			'contact_id' => $contact_id,
			'client_id'  => (string) ( $payload['client_id'] ?? '' ),
			'event_uuid' => (string) ( $payload['event_uuid'] ?? ( 'conv-' . $payload['visit_id'] . '-' . $contact_id ) ),
		);
		$meta = array(
			'source'      => 'campaign_conversion',
			'campaign_id' => (int) ( $payload['campaign_id'] ?? 0 ),
			'code'        => (string) ( $payload['code'] ?? '' ),
			'visit_id'    => (int) ( $payload['visit_id'] ?? 0 ),
		);
		self::award( $subject, $points, $meta );
	}

	/* ============================================================
	 * award() — INSERT a credit row, dedupe by event_uuid
	 *
	 * @param array{contact_id?:int, phone?:string, client_id?:string, event_uuid?:string} $subject
	 * @param int                                                                          $points
	 * @param array<string,mixed>                                                          $meta
	 *
	 * @return array{ok:bool, status:string, ledger_id:int, balance_after:int, detail?:string}
	 * ============================================================ */
	public static function award( array $subject, int $points, array $meta = array() ): array {
		global $wpdb;
		if ( $points <= 0 ) {
			return array( 'ok' => false, 'status' => 'invalid_points', 'ledger_id' => 0, 'balance_after' => 0 );
		}

		// Resolve phone — the legacy ledger keys on `phone`, not contact_id.
		$contact_id = (int) ( $subject['contact_id'] ?? 0 );
		$phone      = self::sanitize_phone( (string) ( $subject['phone'] ?? '' ) );
		if ( $phone === '' && $contact_id > 0 ) {
			$phone = self::lookup_contact_phone( $contact_id );
		}
		if ( $phone === '' ) {
			return array( 'ok' => false, 'status' => 'no_phone', 'ledger_id' => 0, 'balance_after' => 0 );
		}

		$client_id  = (string) ( $subject['client_id'] ?? '' );
		$event_uuid = (string) ( $subject['event_uuid'] ?? '' );
		if ( $event_uuid === '' ) {
			$event_uuid = wp_generate_uuid4();
		}

		$tbl  = $wpdb->prefix . 'user_points';
		$code = 'evt_' . substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $event_uuid ), 0, 60 );

		// Ledger may not exist (user-points plugin disabled). Bail gracefully.
		$tbl_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		if ( $tbl_exists !== $tbl ) {
			return array( 'ok' => false, 'status' => 'ledger_table_missing', 'ledger_id' => 0, 'balance_after' => 0 );
		}

		// Dedupe — reject if a row already exists with the same (phone, code) tuple.
		$dup_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE phone = %s AND code = %s LIMIT 1",
			$phone, $code
		) );
		if ( $dup_id > 0 ) {
			return array(
				'ok'            => true,
				'status'        => 'duplicate_skipped',
				'ledger_id'     => $dup_id,
				'balance_after' => self::balance_by_phone( $phone ),
				'detail'        => 'event_uuid replay',
			);
		}

		$store_name = self::STORE_PREFIX . substr( (string) ( $meta['code'] ?? 'misc' ), 0, 80 );
		$row = array(
			'code'         => $code,
			'store_name'   => $store_name,
			'product'      => self::PRODUCT_NOOP,
			'phone'        => $phone,
			'client_id'    => $client_id,
			'user_points'  => (string) $points,
			'time'         => current_time( 'mysql' ),
		);
		// `user_name` may have been added by an ALTER (user_points plugin); insert
		// only if the column exists. WP's $wpdb->insert silently drops unknown
		// columns from the row but errors on missing NOT-NULL ones, so guard it.
		if ( self::column_exists( $tbl, 'user_name' ) ) {
			$row['user_name'] = self::lookup_contact_name( $contact_id );
		}

		$ok = $wpdb->insert( $tbl, $row );
		if ( ! $ok ) {
			return array(
				'ok' => false, 'status' => 'insert_failed',
				'ledger_id' => 0, 'balance_after' => self::balance_by_phone( $phone ),
				'detail' => $wpdb->last_error,
			);
		}
		$ledger_id = (int) $wpdb->insert_id;
		$balance   = self::balance_by_phone( $phone );

		// Denorm — refresh contacts.points_balance_cache (R-PAR-cache).
		if ( $contact_id > 0 ) {
			self::refresh_balance_cache( $contact_id, $balance );
		}

		BizCity_CRM_Event_Emitter::emit( 'crm_loyalty_points_awarded', array(
			'ledger_id'      => $ledger_id,
			'contact_id'     => $contact_id,
			'phone'          => $phone,
			'points'         => $points,
			'balance_after'  => $balance,
			'campaign_id'    => (int) ( $meta['campaign_id'] ?? 0 ),
			'campaign_code'  => (string) ( $meta['code'] ?? '' ),
			'event_uuid'     => $event_uuid,
		), $event_uuid );

		return array(
			'ok'            => true,
			'status'        => 'awarded',
			'ledger_id'     => $ledger_id,
			'balance_after' => $balance,
		);
	}

	/* ============================================================
	 * balance() — current balance for a subject (credits − debits)
	 *
	 * @param array{contact_id?:int, phone?:string} $subject
	 * ============================================================ */
	public static function balance( array $subject ): int {
		$phone = self::sanitize_phone( (string) ( $subject['phone'] ?? '' ) );
		if ( $phone === '' ) {
			$phone = self::lookup_contact_phone( (int) ( $subject['contact_id'] ?? 0 ) );
		}
		return $phone === '' ? 0 : self::balance_by_phone( $phone );
	}

	/* ============================================================
	 * history() — last N award + redemption events from the event stream
	 * ============================================================ */
	public static function history( array $subject, int $limit = 20 ): array {
		global $wpdb;
		$phone = self::sanitize_phone( (string) ( $subject['phone'] ?? '' ) );
		if ( $phone === '' ) {
			$phone = self::lookup_contact_phone( (int) ( $subject['contact_id'] ?? 0 ) );
		}
		if ( $phone === '' ) { return array(); }

		$limit = max( 1, min( 200, $limit ) );
		$tbl_c = $wpdb->prefix . 'user_points';
		$tbl_d = $wpdb->prefix . 'user_points_exchange';

		$credits = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, code, store_name, product, user_points AS points, time AS at
			   FROM {$tbl_c} WHERE phone = %s ORDER BY id DESC LIMIT %d",
			$phone, $limit
		), ARRAY_A );
		$debits  = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, product, points, remaining_points, time AS at
			   FROM {$tbl_d} WHERE phone = %s ORDER BY id DESC LIMIT %d",
			$phone, $limit
		), ARRAY_A );

		$out = array();
		foreach ( $credits as $r ) {
			$out[] = array(
				'kind'   => 'credit',
				'id'     => (int) $r['id'],
				'code'   => (string) $r['code'],
				'store'  => (string) $r['store_name'],
				'item'   => (string) $r['product'],
				'points' => (int) $r['points'],
				'at'     => (string) $r['at'],
			);
		}
		foreach ( $debits as $r ) {
			$out[] = array(
				'kind'      => 'debit',
				'id'        => (int) $r['id'],
				'item'      => (string) $r['product'],
				'points'    => -1 * (int) $r['points'],
				'remaining' => (int) $r['remaining_points'],
				'at'        => (string) $r['at'],
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) $b['at'], (string) $a['at'] ) );
		return array_slice( $out, 0, $limit );
	}

	/* ============================================================
	 * Internals
	 * ============================================================ */

	private static function balance_by_phone( string $phone ): int {
		global $wpdb;
		$tbl_c = $wpdb->prefix . 'user_points';
		$tbl_d = $wpdb->prefix . 'user_points_exchange';
		$credits = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(CAST(user_points AS UNSIGNED)),0) FROM {$tbl_c} WHERE phone = %s",
			$phone
		) );
		// Exchange table may not exist on installs that never used the redeem feature.
		$debits = 0;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_d ) ) === $tbl_d ) {
			$debits = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(points),0) FROM {$tbl_d} WHERE phone = %s",
				$phone
			) );
		}
		return max( 0, $credits - $debits );
	}

	private static function refresh_balance_cache( int $contact_id, int $balance ): void {
		global $wpdb;
		$ct = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		// Column was added in PHASE 0.35 M1.W1 migrate_phase_035; guarded for older DBs.
		if ( ! BizCity_CRM_DB_Installer_V2::column_exists( $ct, 'points_balance_cache' ) ) {
			return;
		}
		$wpdb->update(
			$ct,
			array( 'points_balance_cache' => $balance, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $contact_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	private static function lookup_contact_phone( int $contact_id ): string {
		if ( $contact_id <= 0 ) { return ''; }
		global $wpdb;
		$ct = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$phone = (string) $wpdb->get_var( $wpdb->prepare( "SELECT phone FROM {$ct} WHERE id = %d", $contact_id ) );
		return self::sanitize_phone( $phone );
	}

	private static function lookup_contact_name( int $contact_id ): string {
		if ( $contact_id <= 0 ) { return ''; }
		global $wpdb;
		$ct = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$ct} WHERE id = %d", $contact_id ) );
	}

	private static function sanitize_phone( string $phone ): string {
		$p = preg_replace( '/[^\d+]/', '', $phone );
		return (string) $p;
	}

	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
		return (bool) $row;
	}
}
