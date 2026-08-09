<?php
/**
 * Shared chat correlation contract for channel JSONL and Twin Event Stream.
 *
 * event_uuid        = one unique event identity.
 * trace_id          = one turn/request correlation.
 * parent_event_uuid = causal parent event identity.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'BizCity_Chat_Correlation', false ) ) {
	return;
}

final class BizCity_Chat_Correlation {

	const VERSION = '1';
	private static $pending_roots = array();

	/**
	 * Ensure the minimum correlation contract on a log/event context.
	 *
	 * @param array  $context
	 * @param string $event_type
	 * @return array
	 */
	public static function ensure( array $context = array(), $event_type = 'chat_event' ) {
		$event_uuid = trim( (string) ( $context['event_uuid'] ?? '' ) );
		if ( $event_uuid === '' ) {
			$event_uuid = self::new_uuid();
		}

		$trace_id = trim( (string) ( $context['trace_id'] ?? '' ) );
		if ( $trace_id === '' ) {
			$trace_id = 'trace-' . substr( str_replace( '-', '', $event_uuid ), 0, 16 );
		}

		$parent = trim( (string) ( $context['parent_event_uuid'] ?? '' ) );
		$context['correlation_version'] = self::VERSION;
		$context['event_uuid']          = $event_uuid;
		$context['trace_id']            = $trace_id;
		$context['parent_event_uuid']   = $parent;
		$context['event_type']          = sanitize_key( (string) $event_type );
		return $context;
	}

	/**
	 * Generate a UUID without requiring the Twin Event Bus to be loaded.
	 */
	public static function new_uuid() {
		if ( class_exists( 'Bizcity_Uuid' ) && method_exists( 'Bizcity_Uuid', 'v7' ) ) {
			return (string) Bizcity_Uuid::v7( (int) ( microtime( true ) * 1000 ) );
		}
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		return 'evt-' . sha1( uniqid( '', true ) . '|' . mt_rand() );
	}

	/**
	 * Resolve a platform/channel hint to the canonical channel folder.
	 */
	public static function channel( $hint ) {
		$value = strtolower( (string) $hint );
		if ( strpos( $value, 'messenger' ) !== false ) { return 'messenger'; }
		if ( strpos( $value, 'zalo_oa' ) !== false || strpos( $value, 'bizhook' ) !== false ) { return 'zalo_oa'; }
		if ( strpos( $value, 'zalo' ) !== false ) { return 'zalo_bot'; }
		if ( strpos( $value, 'telegram' ) !== false ) { return 'telegram'; }
		if ( strpos( $value, 'webchat' ) !== false || strpos( $value, 'twinchat' ) !== false || strpos( $value, 'twinweb' ) !== false ) { return 'webchat'; }
		if ( strpos( $value, 'facebook' ) !== false || strpos( $value, 'fb' ) !== false ) { return 'facebook'; }
		if ( strpos( $value, 'email' ) !== false ) { return 'email'; }
		return 'channel_gateway';
	}

	/** Bind an inbound channel root for the next Event Stream event in this trace. */
	public static function bind_pending_root( array $context ) {
		$context = self::ensure( $context, $context['event_type'] ?? 'channel_inbound' );
		$trace_id = (string) ( $context['trace_id'] ?? '' );
		if ( $trace_id !== '' && ! isset( self::$pending_roots[ $trace_id ] ) ) {
			self::$pending_roots[ $trace_id ] = $context;
		}
		return $context;
	}

	/** Consume one inbound root; child Event Stream events keep their own UUID. */
	public static function consume_pending_root( $trace_id ) {
		$trace_id = (string) $trace_id;
		if ( $trace_id === '' || empty( self::$pending_roots[ $trace_id ] ) ) {
			return array();
		}
		$root = self::$pending_roots[ $trace_id ];
		unset( self::$pending_roots[ $trace_id ] );
		return $root;
	}

	/** Release an imported root when a worker exits before creating an event. */
	public static function release_pending_root( $trace_id ): void {
		// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — prevent pending roots
		// from leaking between multiple jobs handled by one long-lived worker.
		$trace_id = (string) $trace_id;
		if ( $trace_id !== '' ) {
			unset( self::$pending_roots[ $trace_id ] );
		}
	}

	/** Return the first pending inbound trace for the current request. */
	public static function pending_trace_id() {
		foreach ( self::$pending_roots as $root ) {
			$trace_id = trim( (string) ( $root['trace_id'] ?? '' ) );
			if ( $trace_id !== '' ) {
				return $trace_id;
			}
		}
		return '';
	}

	/** Identify raw inbound events that can become a Twin turn root. */
	public static function is_inbound_event( $event ) {
		$value = strtolower( (string) $event );
		return strpos( $value, 'webhook' ) !== false
			|| strpos( $value, 'message_in' ) !== false
			|| strpos( $value, 'message_received' ) !== false
			|| strpos( $value, 'intake' ) !== false;
	}

	/** Export only correlation metadata into an async job payload. */
	public static function export_async( array $context = array(), $event_type = 'automation_job' ) {
		if ( isset( $context['correlation'] ) && is_array( $context['correlation'] ) ) {
			$context = array_merge( $context['correlation'], $context );
		}
		if ( (string) ( $context['event_uuid'] ?? '' ) === '' && self::pending_trace_id() !== '' ) {
			foreach ( self::$pending_roots as $root ) {
				if ( (string) ( $root['trace_id'] ?? '' ) === self::pending_trace_id() ) {
					$context = array_merge( $root, $context );
					break;
				}
			}
		}
		$normalized = self::ensure( $context, $event_type );
		return array(
			'correlation_version' => self::VERSION,
			'event_uuid'          => (string) $normalized['event_uuid'],
			'trace_id'            => (string) $normalized['trace_id'],
			'parent_event_uuid'   => (string) $normalized['parent_event_uuid'],
		);
	}

	/** Import correlation metadata at an async worker boundary. */
	public static function import_async( array $payload = array(), $event_type = 'automation_job' ) {
		$context = isset( $payload['correlation'] ) && is_array( $payload['correlation'] )
			? $payload['correlation']
			: $payload;
		return self::ensure( $context, $event_type );
	}
}
