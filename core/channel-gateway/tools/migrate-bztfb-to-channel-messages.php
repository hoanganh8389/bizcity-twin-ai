<?php
/**
 * Migrate `wp_bztfb_*` (legacy bizcity-tool-facebook tables) → unified
 * `wp_bizcity_channel_messages`. PHASE 0.31 T-S6.4.
 *
 * SAFE BY DEFAULT — Provides:
 *   - inspect()   : counts source rows + identifies columns (read-only)
 *   - plan_row()  : translates one source row → unified row (pure)
 *   - execute()   : actually writes; requires explicit ['confirm' => true]
 *                   AND opt-in `BIZCITY_ALLOW_T_S6_4_MIGRATE` constant.
 *
 * This script DOES NOT delete the source tables or files. After successful
 * migration + manual verification, the operator may:
 *   1. Drop legacy tables: `wp_bztfb_messages`, `wp_bztfb_users`, etc.
 *   2. `rm -rf plugins/bizcity-twin-ai/plugins/bizcity-tool-facebook/`
 *   3. Remove `bizcity-tool-facebook/bizcity-tool-facebook.php` from
 *      active plugins list.
 *
 * Recommended call (WP-CLI):
 *   wp eval-file plugins/bizcity-twin-ai/core/channel-gateway/tools/migrate-bztfb-to-channel-messages.php
 *
 * Or programmatically (admin-only):
 *   if ( current_user_can( 'manage_options' ) ) {
 *     $report = BizCity_Migrate_Bztfb::execute( [ 'confirm' => true, 'limit' => 1000 ] );
 *   }
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Tools
 * @since 1.4.0 (Sprint 6 T-S6.4)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Migrate_Bztfb' ) ) {
	return;
}

class BizCity_Migrate_Bztfb {

	/**
	 * Discover candidate source tables. Returns table names that exist.
	 *
	 * @return string[] Like ['wp_bztfb_messages']
	 */
	public static function find_source_tables(): array {
		global $wpdb;
		$prefix    = $wpdb->prefix;
		$candidates = array(
			$prefix . 'bztfb_messages',
			$prefix . 'bztfb_outgoing',
			$prefix . 'bztfb_inbox',
		);
		$found = array();
		foreach ( $candidates as $t ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
			if ( $exists !== '' ) {
				$found[] = $t;
			}
		}
		return $found;
	}

	/**
	 * Read-only inspection of source data.
	 */
	public static function inspect(): array {
		global $wpdb;
		$out = array(
			'source_tables'      => array(),
			'destination_table'  => '',
			'destination_exists' => false,
			'destination_rows'   => 0,
		);
		foreach ( self::find_source_tables() as $t ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			$cols  = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
			$out['source_tables'][] = array(
				'name'    => $t,
				'rows'    => $count,
				'columns' => is_array( $cols ) ? $cols : array(),
			);
		}
		if ( class_exists( 'BizCity_Channel_Messages' ) ) {
			$dest = BizCity_Channel_Messages::table();
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dest ) );
			$out['destination_table']  = $dest;
			$out['destination_exists'] = $exists !== '';
			if ( $exists !== '' ) {
				$out['destination_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dest}" );
			}
		}
		return $out;
	}

	/**
	 * Pure translator: one bztfb_messages row → log_outbound() args.
	 *
	 * Best-effort schema mapping — bztfb_messages columns vary across versions.
	 * Adjust here if your prod table uses different column names.
	 */
	public static function plan_row( array $row ): array {
		$direction = isset( $row['direction'] ) ? (int) $row['direction'] : 0;
		if ( $direction === 0 ) {
			// Heuristic: if `recipient_id` set and `from_us` truthy → outbound.
			$direction = ! empty( $row['from_us'] ) ? BizCity_Channel_Messages::DIR_OUTBOUND : BizCity_Channel_Messages::DIR_INBOUND;
		}
		return array(
			'platform'   => 'FACEBOOK',
			'direction'  => $direction,
			'chat_id'    => 'fb_' . (string) ( $row['psid'] ?? $row['recipient_id'] ?? $row['user_id'] ?? '' ),
			'user_psid'  => (string) ( $row['psid'] ?? $row['user_id'] ?? '' ),
			'message_id' => (string) ( $row['mid'] ?? $row['message_id'] ?? '' ),
			'thread_id'  => (string) ( $row['thread_id'] ?? '' ),
			'event_type' => (string) ( $row['event_type'] ?? 'message' ),
			'body'       => (string) ( $row['message'] ?? $row['text'] ?? $row['body'] ?? '' ),
			'payload'    => $row,
			'status'     => $direction === BizCity_Channel_Messages::DIR_OUTBOUND ? 'sent' : 'received',
		);
	}

	/**
	 * Actually migrate. SAFE: returns dry-run report unless confirm=true.
	 */
	public static function execute( array $args = array() ): array {
		global $wpdb;
		$confirm   = ! empty( $args['confirm'] );
		$limit     = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 500;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		if ( $confirm && ! defined( 'BIZCITY_ALLOW_T_S6_4_MIGRATE' ) ) {
			return array(
				'ok'    => false,
				'error' => 'Refused: define BIZCITY_ALLOW_T_S6_4_MIGRATE = true in wp-config.php to enable destructive write.',
			);
		}
		if ( ! class_exists( 'BizCity_Channel_Messages' ) ) {
			return array( 'ok' => false, 'error' => 'BizCity_Channel_Messages class not loaded.' );
		}
		BizCity_Channel_Messages::maybe_install();

		$src_tables = self::find_source_tables();
		if ( ! $src_tables ) {
			return array( 'ok' => true, 'note' => 'No source tables — nothing to migrate.', 'inserted' => 0 );
		}
		$inserted   = 0;
		$skipped    = 0;
		$dry_run    = ! $confirm;
		$samples    = array();

		foreach ( $src_tables as $t ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) { continue; }
			foreach ( $rows as $row ) {
				$translated = self::plan_row( $row );
				if ( count( $samples ) < 5 ) { $samples[] = $translated; }
				if ( $dry_run ) {
					$skipped++;
					continue;
				}
				$id = $translated['direction'] === BizCity_Channel_Messages::DIR_OUTBOUND
					? BizCity_Channel_Messages::log_outbound( $translated )
					: BizCity_Channel_Messages::log_inbound( $translated );
				if ( $id > 0 ) { $inserted++; } else { $skipped++; }
			}
		}
		return array(
			'ok'        => true,
			'dry_run'   => $dry_run,
			'inserted'  => $inserted,
			'skipped_or_dup' => $skipped,
			'samples'   => $samples,
			'next_offset' => $offset + $limit,
		);
	}
}

/* WP-CLI command: `wp bizcity migrate-bztfb [--confirm] [--limit=500]` */
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'bizcity migrate-bztfb', function ( $args, $assoc ) {
		$confirm = ! empty( $assoc['confirm'] );
		$limit   = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 500;
		$offset  = isset( $assoc['offset'] ) ? (int) $assoc['offset'] : 0;
		$res = BizCity_Migrate_Bztfb::execute( array( 'confirm' => $confirm, 'limit' => $limit, 'offset' => $offset ) );
		\WP_CLI::log( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	} );
}
