<?php
/**
 * Event-sourced repository for HIL Instance snapshots.
 *
 * Reuses the canonical Twin Event Bus/Event Stream as the only source of
 * truth (R-EVT). No new table is created; scoping is blog_id + identity_uuid
 * + session_id + hil_id (R-CH-IDMEM, R-MSDB).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 *
 * Cache Contract: group `twin_hil`; keys include physical database,
 * `blog_id`, identity/session/HIL hashes; latest TTL 60 seconds, history TTL
 * 5 seconds; invalidate after every opened/progressed/closed event.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_HIL_Repository {

	const CACHE_GROUP = 'twin_hil';
	const CACHE_TTL   = 60;
	const HISTORY_CACHE_TTL = 5;

	/**
	 * Read the newest HIL Instance snapshot for one tenant/identity/session/hil scope.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest( int $blog_id, string $identity_uuid, string $session_id, string $hil_id ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — resolve newest snapshot by tenant, identity, session, and instance id.
		$blog_id = max( 0, $blog_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		$session_id = trim( $session_id );
		$hil_id = trim( $hil_id );
		if ( $blog_id <= 0 || $identity_uuid === '' || $session_id === '' || $hil_id === '' ) {
			return array();
		}
		$cache_key = self::cache_key( 'latest', $blog_id, $identity_uuid, $session_id, $hil_id );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array();
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$event_types = array( 'twin_hil_opened', 'twin_hil_progressed', 'twin_hil_closed' );
		$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
		$sql = "SELECT id, event_uuid, event_type, payload_json, created_at
			FROM {$table}
			WHERE blog_id = %d AND session_id = %s
			AND event_type IN ({$placeholders})
			AND payload_json LIKE %s
			ORDER BY id DESC LIMIT 100";
		$params = array_merge(
			array( $blog_id, $session_id ),
			$event_types,
			array( '%"hil_id":"' . $wpdb->esc_like( $hil_id ) . '"%' )
		);
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$latest = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				if ( ! is_array( $payload ) || (string) ( $payload['hil_id'] ?? '' ) !== $hil_id ) {
					continue;
				}
				if ( $identity_uuid !== '' && strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) ) !== $identity_uuid ) {
					continue;
				}
				$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
				$state = self::restore_state( $state );
				if ( ! is_array( $state ) ) {
					continue;
				}
				$state['hil_id'] = $hil_id;
				$state['blog_id'] = $blog_id;
				$state['session_id'] = $session_id;
				$latest = BizCity_TwinBrain_HIL_State::normalize( $state );
				$latest['_event_type'] = (string) $row['event_type'];
				$latest['_event_uuid'] = sanitize_text_field( (string) ( $row['event_uuid'] ?? '' ) );
				$latest['_created_at'] = (string) ( $row['created_at'] ?? '' );
				break;
			}
		}
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $latest, self::CACHE_TTL );
		}
		return $latest;
	}

	/**
	 * Read the ordered HIL Instance snapshots for one scoped instance.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( int $blog_id, string $identity_uuid, string $session_id, string $hil_id, int $limit = 50 ): array {
		// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — read-only ordered projection for Builder step trace; no new event or table.
		$blog_id = max( 0, $blog_id );
		$identity_uuid = strtolower( trim( $identity_uuid ) );
		$session_id = trim( $session_id );
		$hil_id = trim( $hil_id );
		$limit = max( 1, min( 200, $limit ) );
		if ( $blog_id <= 0 || $identity_uuid === '' || $session_id === '' || $hil_id === '' ) {
			return array();
		}

		$cache_key = self::cache_key( 'history', $blog_id, $identity_uuid, $session_id, $hil_id, $limit );
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::CACHE_GROUP, $cache_key );
			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Stream_Schema' ) ) {
			return array();
		}

		global $wpdb;
		$table = BizCity_Twin_Event_Stream_Schema::table();
		$event_types = array( 'twin_hil_opened', 'twin_hil_progressed', 'twin_hil_closed' );
		$placeholders = implode( ', ', array_fill( 0, count( $event_types ), '%s' ) );
		$sql = "SELECT id, event_uuid, event_type, payload_json, created_at
			FROM {$table}
			WHERE blog_id = %d AND session_id = %s
			AND event_type IN ({$placeholders})
			AND payload_json LIKE %s
			ORDER BY id DESC LIMIT {$limit}";
		$params = array_merge(
			array( $blog_id, $session_id ),
			$event_types,
			array( '%"hil_id":"' . $wpdb->esc_like( $hil_id ) . '"%' )
		);
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$history = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$payload = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
				if ( ! is_array( $payload ) || (string) ( $payload['hil_id'] ?? '' ) !== $hil_id ) {
					continue;
				}
				if ( $identity_uuid !== '' && strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) ) !== $identity_uuid ) {
					continue;
				}
				$state = isset( $payload['state'] ) && is_array( $payload['state'] ) ? $payload['state'] : $payload;
				$state = self::restore_state( $state );
				if ( ! is_array( $state ) ) {
					continue;
				}
				$state['hil_id'] = $hil_id;
				$state['blog_id'] = $blog_id;
				$state['session_id'] = $session_id;
				$normalized = BizCity_TwinBrain_HIL_State::normalize( $state );
				$normalized['_event_type'] = (string) ( $row['event_type'] ?? '' );
				$normalized['_event_uuid'] = sanitize_text_field( (string) ( $row['event_uuid'] ?? '' ) );
				$normalized['_created_at'] = (string) ( $row['created_at'] ?? '' );
				$history[] = $normalized;
			}
		}
		// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — keep the newest bounded window, then restore chronological ASC order for footnotes.
		$history = array_reverse( $history );
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $cache_key, $history, self::HISTORY_CACHE_TTL );
		}
		return $history;
	}

	public static function open( array $state, array $opts = array() ): string {
		return self::write_snapshot( 'twin_hil_opened', $state, $opts );
	}

	public static function progress( array $state, array $opts = array() ): string {
		return self::write_snapshot( 'twin_hil_progressed', $state, $opts );
	}

	public static function close( array $state, array $opts = array() ): string {
		return self::write_snapshot( 'twin_hil_closed', $state, $opts );
	}

	private static function write_snapshot( string $event_type, array $state, array $opts ): string {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — persist only normalized snapshots through the canonical Event Bus; no parallel truth store.
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			return '';
		}
		$normalized = BizCity_TwinBrain_HIL_State::normalize( $state );
		$identity_uuid = strtolower( trim( (string) ( $opts['identity_uuid'] ?? $normalized['identity_uuid'] ?? '' ) ) );
		$blog_id = (int) ( $opts['blog_id'] ?? get_current_blog_id() );
		if ( $blog_id <= 0 || $identity_uuid === '' || $normalized['hil_id'] === '' || $normalized['session_id'] === '' ) {
			return '';
		}
		if ( $event_type !== 'twin_hil_opened' ) {
			// [2026-08-16 Johnny Chu] MPR-V5-HIL-RUNTIME — reject progress/close writes without an existing opened instance.
			$current = self::latest( $blog_id, $identity_uuid, $normalized['session_id'], $normalized['hil_id'] );
			if ( empty( $current ) || ! BizCity_TwinBrain_HIL_State::can_transition( (string) ( $current['status'] ?? '' ), $normalized['status'] ) ) {
				error_log( '[TwinBrain][hil] rejected invalid or orphaned HIL Instance transition' );
				return '';
			}
		}
		if ( $event_type === 'twin_hil_closed' && $normalized['closure_reason'] === '' ) {
			return '';
		}
		try {
			$stored_state = self::protect_state( $normalized );
		} catch ( \Throwable $e ) {
			$stored_state = null;
		}
		if ( ! is_array( $stored_state ) ) {
			return '';
		}
		$payload = array_merge(
			array(
				'hil_id'        => $normalized['hil_id'],
				'session_id'    => $normalized['session_id'],
				'status'        => $normalized['status'],
				'state'         => $stored_state,
				'identity_uuid' => $identity_uuid,
			),
			$event_type === 'twin_hil_opened' ? array( 'spec_id' => $normalized['spec_id'], 'trigger_id' => $normalized['trigger_id'] ) : array(),
			$event_type === 'twin_hil_closed' ? array( 'closure_reason' => $normalized['closure_reason'] ) : array()
		);
		try {
			$uuid = BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, array(
				'event_source' => (string) ( $opts['event_source'] ?? 'twinbrain' ),
				'trace_id'     => (string) ( $opts['trace_id'] ?? '' ),
				'session_id'   => $normalized['session_id'],
				'user_id'      => (int) ( $opts['user_id'] ?? 0 ),
				'blog_id'      => $blog_id,
			) );
			if ( class_exists( 'BizCity_Cache' ) ) {
				BizCity_Cache::delete( self::CACHE_GROUP, self::cache_key( 'latest', $blog_id, $identity_uuid, $normalized['session_id'], $normalized['hil_id'] ) );
				// [2026-08-16 Johnny Chu] PHASE-3-HIL-TRACE — flush ordered history projections after every HIL progress/close/open event.
				BizCity_Cache::flush_group( self::CACHE_GROUP );
			}
			return (string) $uuid;
		} catch ( \Throwable $e ) {
			error_log( '[TwinBrain][hil] write_snapshot exception: ' . $e->getMessage() );
			return '';
		}
	}

	private static function cache_key( string $kind, int $blog_id, string $identity_uuid, string $session_id, string $hil_id, int $limit = 0 ): string {
		global $wpdb;
		// [2026-08-16 Johnny Chu] R-MSDB/R-CACHE — include the physical database in HIL cache identity when the router exposes it.
		$physical_db = isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '';
		$payload = implode( '|', array( $kind, $blog_id, $physical_db, $identity_uuid, $session_id, $hil_id, $limit ) );
		return $kind . '_' . md5( $payload );
	}

	private static function protect_state( array $state ) {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-PRIVACY — encrypt resumable slot values before Event Stream persistence.
		if ( empty( $state['slot_values'] ) ) {
			return $state;
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'wp_salt' ) || ! function_exists( 'random_bytes' ) ) {
			return null;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|' . (int) ( $state['blog_id'] ?? get_current_blog_id() ) . '|bizcity_hil_slot', true );
		foreach ( (array) $state['slot_values'] as $slot_id => $value ) {
			$iv = random_bytes( 16 );
			$cipher = openssl_encrypt( (string) $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false === $cipher ) {
				return null;
			}
			$state['slot_values'][ $slot_id ] = 'enc:v1:' . base64_encode( $iv . $cipher );
		}
		return $state;
	}

	private static function restore_state( array $state ) {
		// [2026-08-16 Johnny Chu] MPR-V5-HIL-PRIVACY — decrypt slot values only inside the scoped HIL repository.
		if ( empty( $state['slot_values'] ) ) {
			return $state;
		}
		if ( ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'wp_salt' ) ) {
			return null;
		}
		$key = hash( 'sha256', wp_salt( 'auth' ) . '|' . (int) ( $state['blog_id'] ?? get_current_blog_id() ) . '|bizcity_hil_slot', true );
		foreach ( (array) $state['slot_values'] as $slot_id => $value ) {
			$value = (string) $value;
			if ( strpos( $value, 'enc:v1:' ) !== 0 ) {
				return null;
			}
			$raw = base64_decode( substr( $value, 7 ), true );
			if ( ! is_string( $raw ) || strlen( $raw ) <= 16 ) {
				return null;
			}
			$plain = openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
			if ( false === $plain ) {
				return null;
			}
			$state['slot_values'][ $slot_id ] = $plain;
		}
		return $state;
	}
}

if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register(
		BizCity_TwinBrain_HIL_Repository::CACHE_GROUP,
		'core.twinbrain.hil-runtime',
		array(
			'latest_{blog_id}_{physical_db_hash}_{identity_hash}_{session_hash}_{hil_hash}' => array(
				'ttl'  => BizCity_TwinBrain_HIL_Repository::CACHE_TTL,
				'desc' => 'Latest HIL Instance snapshot by tenant, identity, session, and HIL id',
			),
			'history_{blog_id}_{physical_db_hash}_{identity_hash}_{session_hash}_{hil_hash}_{limit}' => array(
				'ttl'  => BizCity_TwinBrain_HIL_Repository::HISTORY_CACHE_TTL,
				'desc' => 'Ordered HIL Instance snapshots for Builder step trace',
			),
		)
	);
}
