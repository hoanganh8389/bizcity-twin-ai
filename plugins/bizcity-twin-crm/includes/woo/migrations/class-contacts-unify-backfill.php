<?php
/**
 * Contacts Unify V2 maintenance backfill.
 *
 * Batch-only service. It never runs from frontend hooks and never changes
 * points arithmetic or Woo order data. Execution updates identity projections
 * only and stores a per-blog checkpoint.
 *
 * @package BizCity_Twin_CRM\Woo\Migrations
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Contacts_Unify_Backfill' ) ) { return; }

final class BizCity_CRM_Contacts_Unify_Backfill {

	const CHECKPOINT_PREFIX = 'bizcity_crm_v2_backfill_';
	const DEFAULT_BATCH = 100;

	/**
	 * Process one user-points ledger batch.
	 *
	 * @param array{source?:string,batch?:int,dry_run?:bool,reset?:bool} $opts
	 * @return array<string,mixed>
	 */
	public static function run_user_points( array $opts = array() ): array {
		$source = sanitize_key( (string) ( $opts['source'] ?? 'user_points' ) );
		if ( ! in_array( $source, array( 'user_points', 'user_points_exchange' ), true ) ) { $source = 'user_points'; }
		$batch = max( 10, min( 500, (int) ( $opts['batch'] ?? self::DEFAULT_BATCH ) ) );
		$dry_run = ! empty( $opts['dry_run'] );
		if ( ! empty( $opts['reset'] ) && ! $dry_run ) { self::reset( $source ); }
		$checkpoint = $dry_run ? 0 : self::checkpoint( $source );
		$report = self::base_report( $source, $dry_run, $checkpoint );
		global $wpdb;
		$table = $wpdb->prefix . $source;
		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( ! self::table_ready( $table ) || ! self::table_ready( $contacts ) ) {
			$report['status'] = 'SKIP';
			$report['reason'] = 'source_or_contacts_table_missing';
			return $report;
		}
		if ( ! function_exists( 'bizcity_column_exists' ) || ! bizcity_column_exists( $table, 'contact_id' ) ) {
			$report['status'] = 'SKIP';
			$report['reason'] = 'contact_id_column_missing';
			return $report;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, phone, client_id, contact_id, %s AS direction FROM `{$table}` WHERE id>%d ORDER BY id ASC LIMIT %d", 'user_points' === $source ? 'credit' : 'debit', $checkpoint, $batch ), ARRAY_A );
		if ( empty( $rows ) ) {
			$report['done'] = true;
			$report['status'] = 'PASS';
			return $report;
		}
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$report['scanned']++;
			$report['last_id'] = $id;
			$phone = class_exists( 'BizCity_Phone_Normalizer' ) ? BizCity_Phone_Normalizer::normalize_vn( (string) $row['phone'] ) : trim( (string) $row['phone'] );
			if ( '' === $phone ) { $report['invalid']++; continue; }
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$contacts}` WHERE phone=%s AND deleted_at IS NULL ORDER BY id ASC", $phone ) ) );
			$ids = array_values( array_unique( array_filter( $ids ) ) );
			if ( count( $ids ) > 1 ) {
				$report['conflicts']++;
				if ( class_exists( 'BizCity_CRM_Identity_Conflict_Queue' ) && ! $dry_run ) {
					BizCity_CRM_Identity_Conflict_Queue::capture( array( 'source' => 'user_points', 'source_id' => $id, 'contact_ids' => $ids, 'reason' => 'duplicate_phone' ) );
				}
				continue;
			}
			if ( 0 === count( $ids ) && ! $dry_run && class_exists( 'BizCity_CRM_UserPoints_Contact_Bridge' ) ) {
				BizCity_CRM_UserPoints_Contact_Bridge::on_ledger_written( $source, $id, $phone, (string) ( $row['client_id'] ?? '' ), 'user_points' === $source ? 'credit' : 'debit' );
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$contacts}` WHERE phone=%s AND deleted_at IS NULL ORDER BY id ASC", $phone ) ) );
			}
			if ( 1 !== count( $ids ) ) { $report['unmatched']++; continue; }
			if ( (int) ( $row['contact_id'] ?? 0 ) === $ids[0] ) { $report['already_linked']++; continue; }
			if ( $dry_run ) { $report['would_link']++; continue; }
			$updated = $wpdb->update( $table, array( 'contact_id' => $ids[0] ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
			if ( false === $updated ) { $report['errors']++; continue; }
			$report['linked']++;
		}
		if ( ! $dry_run ) { self::set_checkpoint( $source, (int) $report['last_id'] ); }
		$report['done'] = count( $rows ) < $batch;
		$report['status'] = $report['errors'] > 0 ? 'WARN' : 'PASS';
		return $report;
	}

	/**
	 * Process one paged Woo order batch. This is maintenance-only.
	 *
	 * @param array{batch?:int,dry_run?:bool,reset?:bool} $opts
	 * @return array<string,mixed>
	 */
	public static function run_woo_orders( array $opts = array() ): array {
		$batch = max( 10, min( 100, (int) ( $opts['batch'] ?? self::DEFAULT_BATCH ) ) );
		$dry_run = ! empty( $opts['dry_run'] );
		if ( ! $dry_run && ! empty( $opts['reset'] ) ) { self::reset( 'woo_orders' ); }
		$page = $dry_run ? 1 : self::checkpoint( 'woo_orders' );
		$report = self::base_report( 'woo_orders', $dry_run, $page );
		if ( ! function_exists( 'wc_get_orders' ) || ! class_exists( 'WC_Order' ) ) {
			$report['status'] = 'SKIP';
			$report['reason'] = 'woocommerce_not_loaded';
			return $report;
		}
		$result = wc_get_orders( array( 'limit' => $batch, 'paged' => max( 1, $page ), 'paginate' => true, 'status' => array( 'processing', 'completed', 'on-hold' ), 'orderby' => 'date', 'order' => 'ASC', 'return' => 'objects' ) );
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
		$max_pages = is_object( $result ) ? max( 1, (int) ( $result->max_num_pages ?? 1 ) ) : $page;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) { continue; }
			$report['scanned']++;
			if ( $dry_run ) { $report['would_sync']++; continue; }
			$id = class_exists( 'BizCity_CRM_Woo_Customer_Bridge' ) ? BizCity_CRM_Woo_Customer_Bridge::sync_from_order( $order ) : 0;
			if ( $id > 0 ) { $report['linked']++; } else { $report['unmatched']++; }
		}
		$report['last_id'] = $page;
		$report['done'] = $page >= $max_pages || count( $orders ) < $batch;
		if ( ! $dry_run && ! $report['done'] ) { self::set_checkpoint( 'woo_orders', $page + 1 ); }
		if ( ! $dry_run && $report['done'] ) { self::set_checkpoint( 'woo_orders', 1 ); }
		$report['status'] = 'PASS';
		return $report;
	}

	public static function checkpoint( string $source ): int {
		return max( 0, (int) get_option( self::CHECKPOINT_PREFIX . (int) get_current_blog_id() . '_' . sanitize_key( $source ), 0 ) );
	}

	public static function reset( string $source ): void {
		delete_option( self::CHECKPOINT_PREFIX . (int) get_current_blog_id() . '_' . sanitize_key( $source ) );
	}

	private static function set_checkpoint( string $source, int $value ): void {
		update_option( self::CHECKPOINT_PREFIX . (int) get_current_blog_id() . '_' . sanitize_key( $source ), max( 0, $value ), false );
	}

	private static function base_report( string $source, bool $dry_run, int $checkpoint ): array {
		return array( 'source' => $source, 'dry_run' => $dry_run, 'checkpoint' => $checkpoint, 'last_id' => $checkpoint, 'scanned' => 0, 'linked' => 0, 'would_link' => 0, 'would_sync' => 0, 'already_linked' => 0, 'unmatched' => 0, 'invalid' => 0, 'conflicts' => 0, 'errors' => 0, 'done' => false, 'status' => 'PASS' );
	}

	private static function table_ready( string $table ): bool {
		return class_exists( 'BizCity_CRM_DB_Installer_V2' ) && BizCity_CRM_DB_Installer_V2::table_exists( $table );
	}
}
