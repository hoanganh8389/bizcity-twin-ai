<?php
/**
 * Canonical observe-only loader ownership registry for PHASE-1.23.
 *
 * This registry records feature claims and monotonic boot states. It never blocks
 * a loader in observe-only mode and never performs DB, cache or provider work.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @since 2026-08-10
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Loader_Ownership_Registry', false ) ) {
	return;
}

final class BizCity_Loader_Ownership_Registry {

	const STATE_ABSENT = 'ABSENT';
	const STATE_DECLARED = 'DECLARED';
	const STATE_CONTRACT_READY = 'CONTRACT_READY';
	const STATE_BOOTSTRAPPED = 'BOOTSTRAPPED';
	const STATE_RUNTIME_READY = 'RUNTIME_READY';
	const STATE_FAILED = 'FAILED';
	const MAX_FEATURES = 100;
	const MAX_EVENTS = 500;
	const MAX_SOURCES = 8;

	private static $records = array();
	private static $events = array();

	public static function claim( string $feature_id, string $source, string $canonical_path, string $version = '', string $surface = '', string $phase = 'unknown_phase' ): string {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W2 - observe owner claims without blocking secondary loaders.
		$feature_id = self::normalize_feature( $feature_id );
		if ( $feature_id === '' ) {
			return self::STATE_ABSENT;
		}
		$path = self::normalize_path( $canonical_path );
		if ( ! isset( self::$records[ $feature_id ] ) ) {
			if ( count( self::$records ) >= self::MAX_FEATURES ) {
				self::event( 'registry_capacity', $feature_id, $source, $path, 'feature_capacity_reached' );
				return self::STATE_ABSENT;
			}
			self::$records[ $feature_id ] = array(
				'feature_id'        => $feature_id,
				'state'             => self::STATE_DECLARED,
				'canonical_path'    => $path,
				'owner_source'      => $source,
				'owner_version'     => $version,
				'surface'           => $surface,
				'claim_count'       => 1,
				'duplicate_attempts' => 0,
				'secondary_sources' => array(),
				'first_claim_phase' => $phase,
				'last_transition_phase' => $phase,
				'failure_code'      => '',
			);
			self::event( 'owner_claimed', $feature_id, $source, $path, $phase );
			return self::STATE_DECLARED;
		}

		$record =& self::$records[ $feature_id ];
		$record['claim_count']++;
		if ( $path !== '' && $record['canonical_path'] !== '' && $path !== $record['canonical_path'] ) {
			$record['duplicate_attempts']++;
			self::event( 'duplicate_owner', $feature_id, $source, $path, 'canonical_path_mismatch' );
		}
		if ( $source !== '' && $source !== $record['owner_source'] && count( $record['secondary_sources'] ) < self::MAX_SOURCES ) {
			if ( ! in_array( $source, $record['secondary_sources'], true ) ) {
				$record['secondary_sources'][] = $source;
			}
			self::event( 'secondary_claim', $feature_id, $source, $path, 'same_feature_claimed_again' );
		}
		if ( $version !== '' && $record['owner_version'] !== '' && $version !== $record['owner_version'] ) {
			self::event( 'version_conflict', $feature_id, $source, $path, $record['owner_version'] . '!=' . $version );
		}
		return (string) $record['state'];
	}

	public static function transition( string $feature_id, string $next_state, string $source = '', string $phase = 'unknown_phase', string $failure_code = '' ): string {
		// [2026-08-10 Johnny Chu] PHASE-1.23-CANONICAL-W2 - enforce monotonic state recording only; no runtime rejection.
		$feature_id = self::normalize_feature( $feature_id );
		if ( $feature_id === '' || ! isset( self::$records[ $feature_id ] ) ) {
			return self::STATE_ABSENT;
		}
		$record =& self::$records[ $feature_id ];
		$current = (string) $record['state'];
		$allowed = array(
			self::STATE_ABSENT,
			self::STATE_DECLARED,
			self::STATE_CONTRACT_READY,
			self::STATE_BOOTSTRAPPED,
			self::STATE_RUNTIME_READY,
			self::STATE_FAILED,
		);
		if ( ! in_array( $next_state, $allowed, true ) ) {
			self::event( 'invalid_state', $feature_id, $source, $record['canonical_path'], $next_state );
			return $current;
		}
		if ( $current === self::STATE_FAILED && $next_state !== self::STATE_FAILED ) {
			self::event( 'state_recovery_attempt', $feature_id, $source, $record['canonical_path'], $current . '->' . $next_state );
			return $current;
		}
		$rank = array_flip( array(
			self::STATE_ABSENT,
			self::STATE_DECLARED,
			self::STATE_CONTRACT_READY,
			self::STATE_BOOTSTRAPPED,
			self::STATE_RUNTIME_READY,
		) );
		if ( $next_state !== self::STATE_FAILED && isset( $rank[ $current ], $rank[ $next_state ] ) && $rank[ $next_state ] < $rank[ $current ] ) {
			self::event( 'state_downgrade_attempt', $feature_id, $source, $record['canonical_path'], $current . '->' . $next_state );
			return $current;
		}
		$record['state'] = $next_state;
		$record['last_transition_phase'] = $phase;
		$record['failure_code'] = $failure_code;
		self::event( 'state_transition', $feature_id, $source, $record['canonical_path'], $current . '->' . $next_state );
		return $next_state;
	}

	public static function snapshot(): array {
		return array(
			'records' => self::$records,
			'events'  => self::$events,
			'mode'    => 'observe_only',
		);
	}

	public static function reset_request(): void {
		self::$records = array();
		self::$events = array();
	}

	private static function normalize_feature( string $feature_id ): string {
		return sanitize_key( $feature_id );
	}

	private static function normalize_path( string $path ): string {
		$path = str_replace( '\\', '/', $path );
		$lower = strtolower( $path );
		$marker = strpos( $lower, '/wp-content/' );
		if ( false !== $marker ) {
			return 'wp-content/' . ltrim( substr( $path, $marker + 12 ), '/' );
		}
		return $path !== '' ? 'external/' . substr( sha1( $path ), 0, 12 ) : '';
	}

	private static function event( string $event, string $feature_id, string $source, string $path, string $reason ): void {
		if ( count( self::$events ) >= self::MAX_EVENTS ) {
			return;
		}
		self::$events[] = array(
			'event'     => $event,
			'feature_id' => $feature_id,
			'source'    => $source,
			'path'      => $path,
			'reason'    => $reason,
		);
	}
}
