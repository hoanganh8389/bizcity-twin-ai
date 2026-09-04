<?php
/**
 * Read-only preview for legacy CRM Zalo conversation reconciliation.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Conversation_Reconciliation_Preview', false ) ) {
	return;
}

final class BizCity_CRM_Conversation_Reconciliation_Preview {

	public static function preview( array $opts = array() ): array {
		// [2026-09-01 Johnny Chu] R-CRM-LEGACY-PREVIEW — inspect current routed tenant only; never mutate legacy conversation rows.
		global $wpdb;
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$requested_blog_id = (int) ( $opts['blog_id'] ?? $current_blog_id );
		$limit = max( 1, min( 100, (int) ( $opts['limit'] ?? 100 ) ) );
		$report = array(
			'dry_run' => true,
			'blog_id' => $current_blog_id,
			'database' => isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '',
			'routing' => array(),
			'scanned' => 0,
			'bot_candidates' => 0,
			'oa_candidates' => 0,
			'unknown' => 0,
			'conflicts' => 0,
			'items' => array(),
		);

		$routing = self::routing_evidence( $requested_blog_id, $current_blog_id, $wpdb );
		$report['routing'] = $routing;
		if ( ! $routing['ok'] ) {
			$report['blocked_reason'] = (string) $routing['reason'];
			return $report;
		}

		$inboxes = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$conversations = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$contact_inboxes = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$messages = BizCity_CRM_DB_Installer_V2::tbl_messages();
		if ( ! class_exists( 'BizCity_Table_Metadata' ) ) {
			$report['blocked_reason'] = 'metadata_helper_missing';
			return $report;
		}
		foreach ( array( $inboxes, $conversations, $contact_inboxes, $messages ) as $table ) {
			if ( ! BizCity_Table_Metadata::table_exists( $table ) ) {
				$report['blocked_reason'] = 'crm_schema_missing';
				return $report;
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id AS conversation_id, i.channel_type, i.channel_ref_id, ci.source_id,
					MAX(m.created_at) AS last_message_at
			   FROM {$conversations} c
			   JOIN {$inboxes} i ON i.id = c.inbox_id
			   JOIN {$contact_inboxes} ci ON ci.id = c.contact_inbox_id
			   LEFT JOIN {$messages} m ON m.conversation_id = c.id
			  WHERE i.channel_type = %s
			  GROUP BY c.id, i.channel_type, i.channel_ref_id, ci.source_id
			  ORDER BY c.id ASC
			  LIMIT %d",
			'zalo',
			$limit
		), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$classification = self::classify_row( $row );
			$detected = (string) $classification['detected_as'];
			if ( $detected === 'bot' ) { $report['bot_candidates']++; }
			elseif ( $detected === 'oa' ) { $report['oa_candidates']++; }
			elseif ( $detected === 'conflict' ) { $report['conflicts']++; }
			else { $report['unknown']++; }
			$report['scanned']++;
			$report['items'][] = array(
				'conversation_id' => (int) $row['conversation_id'],
				'current_channel_type' => (string) $row['channel_type'],
				'detected_as' => $detected,
				'channel_ref_id' => (string) $row['channel_ref_id'],
				'source_id' => (string) $row['source_id'],
				'source_id_normalized' => self::normalize_source_id( (string) $row['source_id'] ),
				'action' => (string) $classification['action'],
				'reason' => (string) $classification['reason'],
				'last_message_at' => $row['last_message_at'] ? (string) $row['last_message_at'] : null,
			);
		}
		return $report;
	}

	private static function routing_evidence( int $requested_blog_id, int $current_blog_id, $wpdb ): array {
		if ( $requested_blog_id <= 0 || $current_blog_id <= 0 || $requested_blog_id !== $current_blog_id ) {
			return array( 'ok' => false, 'reason' => 'blog_scope_mismatch' );
		}
		if ( defined( 'BIZCITY_DBINIT_FAIL_OPEN' ) && BIZCITY_DBINIT_FAIL_OPEN ) {
			return array( 'ok' => false, 'reason' => 'db_route_fail_open_forbidden' );
		}
		$database = isset( $wpdb->dbname ) ? trim( (string) $wpdb->dbname ) : '';
		if ( $database === '' || empty( $wpdb->dbh ) ) {
			return array( 'ok' => false, 'reason' => 'database_connection_unverified' );
		}
		$router = class_exists( 'BizCity_WPDB_Router', false );
		$bizname = $router && isset( $wpdb->current_bizname ) ? trim( (string) $wpdb->current_bizname ) : '';
		if ( $router && $bizname === '' ) {
			return array( 'ok' => false, 'reason' => 'shard_identity_missing' );
		}
		return array(
			'ok' => true,
			'blog_id' => $current_blog_id,
			'database' => $database,
			'router' => $router ? get_class( $wpdb ) : 'wpdb',
			'bizname' => $bizname !== '' ? $bizname : null,
		);
	}

	private static function classify_row( array $row ): array {
		$ref = trim( (string) ( $row['channel_ref_id'] ?? '' ) );
		$bot_match = false;
		if ( ctype_digit( $ref ) && class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
			try {
				$bot = BizCity_Zalo_Bot_Database::instance()->get_bot( (int) $ref );
				$bot_match = is_object( $bot ) && (string) ( $bot->status ?? 'active' ) === 'active';
			} catch ( \Throwable $e ) {
				$bot_match = false;
			}
		}
		$oa_match = false;
		foreach ( BizCity_CRM_Repository::list_inboxes() as $inbox ) {
			if ( strtolower( (string) ( $inbox['channel_type'] ?? '' ) ) === 'zalo_oa' && (string) ( $inbox['channel_ref_id'] ?? '' ) === $ref ) {
				$oa_match = true;
				break;
			}
		}
		if ( $bot_match && $oa_match ) {
			return array( 'detected_as' => 'conflict', 'action' => 'quarantine', 'reason' => 'ref_matches_bot_and_oa_evidence' );
		}
		if ( $bot_match ) {
			return array( 'detected_as' => 'bot', 'action' => 'migrate_to_zalo_bot', 'reason' => 'exact_active_bot_registry_match' );
		}
		if ( $oa_match ) {
			return array( 'detected_as' => 'oa', 'action' => 'migrate_to_zalo_oa', 'reason' => 'exact_existing_zalo_oa_inbox_match' );
		}
		return array( 'detected_as' => 'unknown', 'action' => 'quarantine', 'reason' => 'no_exact_bot_or_oa_evidence' );
	}

	private static function normalize_source_id( string $source_id ): string {
		if ( strpos( $source_id, 'private_' ) === 0 ) {
			return substr( $source_id, 8 );
		}
		if ( strpos( $source_id, 'group_' ) === 0 ) {
			return 'group:' . substr( $source_id, 6 );
		}
		return $source_id;
	}
}