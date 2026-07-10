<?php
/**
 * BizCity CRM — Legacy `biz_contacts` → canonical `crm_contacts` migration
 * (PHASE 0.35 M-CRM.M8.W2.2).
 *
 * Idempotent. Each legacy row is matched against canonical contacts by
 * email → phone → fallback insert. The mapping is recorded in
 * `bizcity_crm_contact_id_map` (UNIQUE on `old_biz_id`) so reruns are
 * safe and FK redirects elsewhere can resolve via a single JOIN.
 *
 * Usage:
 *   $report = BizCity_CRM_Migrate_Biz_Contacts::dry_run();      // preview only
 *   $report = BizCity_CRM_Migrate_Biz_Contacts::run();          // execute
 *   $report = BizCity_CRM_Migrate_Biz_Contacts::run( ['batch'=>500] );
 *
 * Returns:
 *   {
 *     scanned, inserted, merged_by_email, merged_by_phone,
 *     skipped_already_mapped, conflicts:[{old_biz_id, reason}],
 *     duration_ms, dry_run:bool
 *   }
 *
 * Safe to invoke from admin button or WP-CLI.
 *
 * @package BizCity_Twin_CRM\Woo\Migrations
 * @since   1.11.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Migrate_Biz_Contacts' ) ) { return; }

final class BizCity_CRM_Migrate_Biz_Contacts {

	const DEFAULT_BATCH = 500;

	public static function dry_run( array $opts = array() ): array {
		$opts['dry_run'] = true;
		return self::run( $opts );
	}

	/**
	 * @param array{batch?:int,dry_run?:bool,max_rows?:int} $opts
	 */
	public static function run( array $opts = array() ): array {
		global $wpdb;

		$dry_run  = ! empty( $opts['dry_run'] );
		$batch    = max( 50, (int) ( $opts['batch']    ?? self::DEFAULT_BATCH ) );
		$max_rows = (int) ( $opts['max_rows'] ?? 0 ); // 0 = unlimited

		$report = array(
			'scanned'                => 0,
			'inserted'               => 0,
			'merged_by_email'        => 0,
			'merged_by_phone'        => 0,
			'skipped_already_mapped' => 0,
			'conflicts'              => array(),
			'duration_ms'            => 0,
			'dry_run'                => $dry_run,
		);

		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			$report['conflicts'][] = array( 'old_biz_id' => 0, 'reason' => 'db_installer_missing' );
			return $report;
		}

		$tbl_legacy = BizCity_CRM_DB_Installer_V2::tbl_biz_contacts();
		$tbl_canon  = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$tbl_map    = BizCity_CRM_DB_Installer_V2::tbl_contact_id_map();

		// Sanity: ensure schema present.
		if ( ! self::table_exists( $tbl_legacy ) ) {
			// Nothing to migrate.
			return $report;
		}
		if ( ! self::table_exists( $tbl_canon ) || ! self::table_exists( $tbl_map ) ) {
			$report['conflicts'][] = array( 'old_biz_id' => 0, 'reason' => 'canonical_or_map_table_missing' );
			return $report;
		}

		$started = microtime( true );
		$offset  = 0;
		$now     = current_time( 'mysql', true );

		while ( true ) {
			$rows = $wpdb->get_results(
				"SELECT * FROM `{$tbl_legacy}` WHERE deleted_at IS NULL ORDER BY id ASC LIMIT {$batch} OFFSET {$offset}",
				ARRAY_A
			);
			if ( empty( $rows ) ) { break; }

			foreach ( $rows as $row ) {
				$report['scanned']++;
				if ( $max_rows > 0 && $report['scanned'] > $max_rows ) { break 2; }

				$old_id = (int) $row['id'];

				// Already mapped?
				$existing = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT new_contact_id FROM `{$tbl_map}` WHERE old_biz_id=%d LIMIT 1",
					$old_id
				) );
				if ( $existing > 0 ) {
					$report['skipped_already_mapped']++;
					continue;
				}

				$email = self::norm_email( $row['email'] ?? '' );
				$phone = self::norm_phone( $row['phone'] ?? '' );

				$match_id     = 0;
				$match_method = 'inserted';

				if ( $email !== '' ) {
					$match_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM `{$tbl_canon}` WHERE email=%s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
						$email
					) );
					if ( $match_id > 0 ) { $match_method = 'email'; }
				}
				if ( $match_id === 0 && $phone !== '' ) {
					$match_id = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM `{$tbl_canon}` WHERE phone=%s AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
						$phone
					) );
					if ( $match_id > 0 ) { $match_method = 'phone'; }
				}

				if ( $dry_run ) {
					if ( $match_method === 'email' ) {
						$report['merged_by_email']++;
					} elseif ( $match_method === 'phone' ) {
						$report['merged_by_phone']++;
					} else {
						$report['inserted']++;
					}
					continue;
				}

				if ( $match_id === 0 ) {
					// INSERT new canonical row.
					$first = (string) ( $row['first_name'] ?? '' );
					$last  = (string) ( $row['last_name']  ?? '' );
					$full  = trim( $first . ' ' . $last );
					if ( $full === '' ) { $full = $email !== '' ? $email : ( 'contact-' . $old_id ); }

					$ok = $wpdb->insert(
						$tbl_canon,
						array(
							'name'                  => $full,
							'first_name'            => $first !== '' ? $first : null,
							'last_name'             => $last  !== '' ? $last  : null,
							'title'                 => $row['title']      ?? null,
							'account_id'            => isset( $row['account_id'] ) ? (int) $row['account_id'] : null,
							'email'                 => $email !== '' ? $email : null,
							'phone'                 => $phone !== '' ? $phone : null,
							'additional_attributes' => $row['additional_attributes'] ?? null,
							'owner_id'              => isset( $row['owner_id'] ) ? (int) $row['owner_id'] : null,
							'tags_json'             => $row['tags_json'] ?? null,
							'acquisition_source'    => 'legacy_biz_contacts',
							'created_at'            => $row['created_at'] ?? $now,
							'updated_at'            => $now,
						),
						array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
					);
					if ( $ok === false ) {
						$report['conflicts'][] = array( 'old_biz_id' => $old_id, 'reason' => 'insert_failed:' . $wpdb->last_error );
						continue;
					}
					$match_id = (int) $wpdb->insert_id;
					$report['inserted']++;
				} else {
					// Backfill nulls without overwriting non-null canonical fields.
					self::backfill_contact( $tbl_canon, $match_id, $row );
					if ( $match_method === 'email' ) {
						$report['merged_by_email']++;
					} else {
						$report['merged_by_phone']++;
					}
				}

				// Record mapping (idempotent via UNIQUE old_biz_id).
				$wpdb->insert(
					$tbl_map,
					array(
						'old_biz_id'     => $old_id,
						'new_contact_id' => $match_id,
						'match_method'   => $match_method,
						'migrated_at'    => $now,
					),
					array( '%d', '%d', '%s', '%s' )
				);
			}

			$offset += $batch;
			if ( count( $rows ) < $batch ) { break; }
		}

		// Optional: redirect FKs in dependent tables. Cheap UPDATE…JOIN
		// only when not in dry-run and only for rows that still point at
		// a legacy id present in the map.
		if ( ! $dry_run ) {
			self::redirect_fks( $tbl_map );
		}

		$report['duration_ms'] = (int) ( ( microtime( true ) - $started ) * 1000 );

		do_action( 'bizcity_crm_biz_contacts_migrated', $report );

		return $report;
	}

	/* ------------------------------------------------------------------ */

	private static function backfill_contact( string $tbl, int $id, array $row ): void {
		global $wpdb;
		$updates = array();
		$fmt     = array();

		// Only fill canonical fields that are NULL/empty.
		$cur = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name, title, account_id, owner_id, tags_json FROM `{$tbl}` WHERE id=%d", $id ), ARRAY_A );
		if ( ! $cur ) { return; }

		$pairs = array(
			'first_name' => array( $row['first_name'] ?? '', '%s' ),
			'last_name'  => array( $row['last_name']  ?? '', '%s' ),
			'title'      => array( $row['title']      ?? '', '%s' ),
			'tags_json'  => array( $row['tags_json']  ?? '', '%s' ),
		);
		foreach ( $pairs as $col => $pair ) {
			if ( ( $cur[ $col ] === null || $cur[ $col ] === '' ) && $pair[0] !== '' ) {
				$updates[ $col ] = $pair[0];
				$fmt[]           = $pair[1];
			}
		}
		if ( empty( $cur['account_id'] ) && ! empty( $row['account_id'] ) ) {
			$updates['account_id'] = (int) $row['account_id']; $fmt[] = '%d';
		}
		if ( empty( $cur['owner_id'] ) && ! empty( $row['owner_id'] ) ) {
			$updates['owner_id'] = (int) $row['owner_id']; $fmt[] = '%d';
		}
		if ( $updates ) {
			$updates['updated_at'] = current_time( 'mysql', true );
			$fmt[] = '%s';
			$wpdb->update( $tbl, $updates, array( 'id' => $id ), $fmt, array( '%d' ) );
		}
	}

	/**
	 * Update FK columns in dependent tables to point at the canonical
	 * contact id when their current value matches a legacy biz_contact id
	 * in the mapping table. Guarded by column existence so it works on
	 * older installs.
	 */
	private static function redirect_fks( string $tbl_map ): void {
		global $wpdb;
		if ( ! method_exists( 'BizCity_CRM_DB_Installer_V2', 'column_exists' ) ) { return; }

		$candidates = array(
			array( 'tbl_leads',         'contact_id' ),
			array( 'tbl_opportunities', 'contact_id' ),
			array( 'tbl_invoices',      'contact_id' ),
			array( 'tbl_crm_tasks',     'related_entity_id' ), // only when related_entity_type='biz_contact'
		);

		foreach ( $candidates as [ $tbl_method, $col ] ) {
			if ( ! method_exists( 'BizCity_CRM_DB_Installer_V2', $tbl_method ) ) { continue; }
			$tbl = call_user_func( array( 'BizCity_CRM_DB_Installer_V2', $tbl_method ) );
			if ( ! self::table_exists( $tbl ) ) { continue; }
			if ( ! BizCity_CRM_DB_Installer_V2::column_exists( $tbl, $col ) ) { continue; }

			$where_extra = '';
			if ( $tbl_method === 'tbl_crm_tasks' && BizCity_CRM_DB_Installer_V2::column_exists( $tbl, 'related_entity_type' ) ) {
				$where_extra = " AND t.related_entity_type = 'biz_contact'";
			}

			// UPDATE … JOIN — MySQL syntax.
			$wpdb->query(
				"UPDATE `{$tbl}` t
				 INNER JOIN `{$tbl_map}` m ON m.old_biz_id = t.{$col}
				 SET t.{$col} = m.new_contact_id
				 WHERE t.{$col} IS NOT NULL{$where_extra}"
			);
		}
	}

	private static function table_exists( string $tbl ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
		return ( $found === $tbl );
	}

	private static function norm_email( $v ): string {
		$v = strtolower( trim( (string) $v ) );
		return ( $v && is_email( $v ) ) ? $v : '';
	}

	private static function norm_phone( $v ): string {
		$v = preg_replace( '/[^0-9+]/', '', (string) $v );
		return $v ?: '';
	}
}
