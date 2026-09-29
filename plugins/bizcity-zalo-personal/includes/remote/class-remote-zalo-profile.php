<?php
/**
 * Remote Zalo Hub consumption-profile validator — LC-7/B4.
 *
 * @package BizCity_Zalo_Personal
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Profile', false ) ) {
	return;
}

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-B4 — validate only the fields this client consumes and tolerate additive fields.
final class BizCity_Remote_Zalo_Profile {

	const PROFILE_VERSION = 'client-v1@2026-09-28';

	public static function check( string $shape, array $data ): array {
		$required = self::required( $shape );
		$missing = array();
		$wrong = array();
		foreach ( $required as $field => $type ) {
			if ( ! array_key_exists( $field, $data ) ) {
				$missing[] = $field;
				continue;
			}
			if ( ! self::matches( $data[ $field ], $type ) ) { $wrong[] = $field; }
		}
		return array( 'ok' => ! $missing && ! $wrong, 'missing' => $missing, 'wrong_type' => $wrong, 'profile_version' => self::PROFILE_VERSION );
	}

	private static function required( string $shape ): array {
		$map = array(
			'accounts_item' => array( 'id' => 'string', 'status' => 'account_status' ),
			'threads_item' => array( 'threadId' => 'string', 'threadType' => 'string' ),
			'messages_item' => array( 'id' => 'int', 'role' => 'message_role', 'zaloMsgId' => 'string', 'createdAt' => 'string' ),
			'events_page' => array( 'events' => 'array', 'nextAfter' => 'cursor' ),
			'event' => array( 'event_id' => 'string', 'event_type' => 'string', 'cursor' => 'cursor', 'account_id' => 'string', 'timestamp' => 'string', 'payload' => 'array' ),
			'payload.message.received' => array( 'threadId' => 'string', 'senderId' => 'string', 'zaloMsgId' => 'string' ),
			'payload.message.sent' => array( 'threadId' => 'string', 'origin' => 'origin' ),
			'payload.account.status' => array( 'status' => 'string' ),
			'payload.thread.bot_state' => array( 'threadId' => 'string', 'botEnabled' => 'bool' ),
			'send_201' => array( 'zaloMsgIds' => 'array' ),
			'error' => array( 'error' => 'array' ),
		);
		return $map[ $shape ] ?? array();
	}

	private static function matches( $value, string $type ): bool {
		switch ( $type ) {
			case 'string': return is_string( $value );
			case 'int': return is_int( $value );
			case 'bool': return is_bool( $value );
			case 'array': return is_array( $value );
			case 'cursor': return is_int( $value ) || ( is_string( $value ) && ( '' === $value || ctype_digit( $value ) ) );
			case 'account_status': return is_string( $value ) && in_array( $value, array( 'running', 'stopped' ), true );
			case 'message_role': return is_string( $value ) && in_array( $value, array( 'user', 'assistant' ), true );
			case 'origin': return is_string( $value ) && in_array( $value, array( 'bot', 'api', 'dashboard' ), true );
		}
		return false;
	}
}
