<?php
/**
 * BizCity CRM reporting facts and daily rollups.
 *
 * Reporting is a content-free projection of CRM events. It is not a message
 * archive and must never become a second conversation store.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39F 2026-08-24
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Reporting_Rollup', false ) ) {
	return;
}

final class BizCity_CRM_Reporting_Rollup {

	const EVENT_RETENTION_DAYS = 90;
	const VERSION = '1.0.0';
	const CACHE_GROUP = 'crm_reporting';

	public static function register(): void {
		add_action( 'bizcity_crm_event', array( __CLASS__, 'on_event' ), 80, 2 );
	}

	public static function on_event( $event_type, $payload ): void {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-F3 — project allowlisted CRM lifecycle events without reading message content.
		if ( ! is_string( $event_type ) || ! is_array( $payload ) ) {
			return;
		}
		$metric = self::metric_for_event( $event_type );
		if ( $metric === '' || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return;
		}
		$event_uuid = sanitize_text_field( (string) ( $payload['event_uuid'] ?? '' ) );
		if ( $event_uuid === '' ) {
			return;
		}
		$inbox_id = max( 0, (int) ( $payload['inbox_id'] ?? 0 ) );
		$team_id  = max( 0, (int) ( $payload['team_id'] ?? 0 ) );
		$user_id  = max( 0, (int) ( $payload['user_id'] ?? $payload['assignee_id'] ?? $payload['responder_user_id'] ?? 0 ) );
		$conversation_id = max( 0, (int) ( $payload['conversation_id'] ?? 0 ) );
		$channel_type = self::channel_type( $inbox_id );
		$occurred_at = self::occurred_at( $payload );
		$dedupe_key = hash( 'sha256', $event_uuid . '|' . $metric );
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_events();
		$inserted = $wpdb->insert( $table, array(
			'event_uuid'           => $event_uuid,
			'metric'               => $metric,
			'occurred_at'          => $occurred_at,
			'event_date'           => substr( $occurred_at, 0, 10 ),
			'inbox_id'             => $inbox_id > 0 ? $inbox_id : null,
			'team_id'              => $team_id > 0 ? $team_id : null,
			'user_id'              => $user_id > 0 ? $user_id : null,
			'conversation_id'      => $conversation_id > 0 ? $conversation_id : null,
			'channel_type'         => $channel_type,
			'value'                => 1,
			'business_hours_value' => null,
			'dedupe_key'           => $dedupe_key,
			'created_at'           => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%f', '%f', '%s', '%s' ) );
		if ( false === $inserted ) {
			return;
		}
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		self::upsert_rollup( $occurred_at, 'tenant', 0, $channel_type, $metric, 1 );
		if ( $channel_type !== '' ) {
			self::upsert_rollup( $occurred_at, 'channel', 0, $channel_type, $metric, 1 );
		}
		if ( $inbox_id > 0 ) {
			self::upsert_rollup( $occurred_at, 'inbox', $inbox_id, $channel_type, $metric, 1 );
		}
		if ( $team_id > 0 ) {
			self::upsert_rollup( $occurred_at, 'team', $team_id, $channel_type, $metric, 1 );
		}
		if ( $user_id > 0 ) {
			self::upsert_rollup( $occurred_at, 'user', $user_id, $channel_type, $metric, 1 );
		}
	}

	public static function get_rollups( array $args = array() ): array {
		$cache_key = 'rollups_' . md5( serialize( $args ) );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached && is_array( $cached ) ) { return $cached; }
		}
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_rollups();
		$where = array( '1=1' );
		$params = array();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['from'] ?? '' ) ) ? (string) $args['from'] : gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
		$to = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['to'] ?? '' ) ) ? (string) $args['to'] : gmdate( 'Y-m-d' );
		$where[] = 'bucket_date BETWEEN %s AND %s';
		$params[] = $from;
		$params[] = $to;
		$dimension = sanitize_key( (string) ( $args['dimension_type'] ?? '' ) );
		if ( $dimension !== '' && in_array( $dimension, array( 'tenant', 'channel', 'inbox', 'team', 'user' ), true ) ) {
			$where[] = 'dimension_type = %s';
			$params[] = $dimension;
		}
		$metric = sanitize_key( (string) ( $args['metric'] ?? '' ) );
		if ( $metric !== '' ) {
			$where[] = 'metric = %s';
			$params[] = $metric;
		}
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 200 ) ) );
		$params[] = $limit;
		$sql = "SELECT bucket_date, dimension_type, dimension_id, channel_type, metric, count, sum_value, sum_business_hours FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY bucket_date DESC, id DESC LIMIT %d';
		$result = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $result ); }
		return $result;
	}

	public static function purge_events( int $days = self::EVENT_RETENTION_DAYS, int $limit = 1000 ): int {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_events();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, min( 365, $days ) ) * DAY_IN_SECONDS ) );
		$limit = max( 1, min( 10000, $limit ) );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s LIMIT %d", $cutoff, $limit ) );
		if ( $deleted > 0 && class_exists( 'BizCity_Cache' ) ) { BizCity_Cache::flush_group( self::CACHE_GROUP ); }
		return $deleted;
	}

	private static function metric_for_event( string $event_type ): string {
		$map = array(
			'crm_message_received'      => 'messages_inbound',
			'crm_message_sent'           => 'messages_outbound',
			'crm_conversation_opened'    => 'conversations_opened',
			'crm_conversation_resolved'  => 'conversations_resolved',
			'crm_conversation_assigned'  => 'assignment_count',
			'crm_message_delivery_updated' => 'delivery_updated',
		);
		return $map[ $event_type ] ?? '';
	}

	private static function channel_type( int $inbox_id ): string {
		if ( $inbox_id <= 0 || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return '';
		}
		$inbox = BizCity_CRM_Repository::get_inbox( $inbox_id );
		return is_array( $inbox ) ? sanitize_key( (string) ( $inbox['channel_type'] ?? '' ) ) : '';
	}

	private static function occurred_at( array $payload ): string {
		$ms = (int) ( $payload['created_epoch_ms'] ?? 0 );
		if ( $ms > 0 ) {
			return gmdate( 'Y-m-d H:i:s', (int) ( $ms / 1000 ) );
		}
		return current_time( 'mysql' );
	}

	private static function upsert_rollup( string $occurred_at, string $dimension_type, int $dimension_id, string $channel_type, string $metric, float $value ): void {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_reporting_rollups();
		$date = substr( $occurred_at, 0, 10 );
		$now = current_time( 'mysql' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$table}` (bucket_date, dimension_type, dimension_id, channel_type, metric, count, sum_value, sum_business_hours, created_at, updated_at) VALUES (%s, %s, %d, %s, %s, 1, %f, 0, %s, %s) ON DUPLICATE KEY UPDATE count = count + 1, sum_value = sum_value + VALUES(sum_value), updated_at = VALUES(updated_at)",
			$date, $dimension_type, $dimension_id, $channel_type, $metric, $value, $now, $now
		) );
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'crm_reporting', 'modules.twin-crm', array(
		'rollups_{args_hash}' => array( 'ttl' => 300, 'desc' => 'Content-free CRM reporting rollups' ),
	) );
}
