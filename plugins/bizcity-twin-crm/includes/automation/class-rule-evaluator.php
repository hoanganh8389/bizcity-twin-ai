<?php
/**
 * BizCity CRM — Rule Evaluator (PHASE 0.35 M2.W2).
 *
 * Stateless condition matcher. Conditions JSON shape:
 *
 *   {
 *     "operator": "all"|"any",        // default: all
 *     "rules": [
 *       { "field": "status",            "op": "equals",   "value": "open" },
 *       { "field": "priority",          "op": "gte",      "value": 2 },
 *       { "field": "labels",            "op": "contains", "value": "vip" },
 *       { "field": "content",           "op": "regex",    "value": "(price|báo giá)" },
 *       { "field": "inbox_id",          "op": "in",       "value": [1,2] },
 *       { "field": "custom_attr.region","op": "equals",   "value": "VN-HN" }
 *     ]
 *   }
 *
 * Supported ops: equals · not_equals · gt · gte · lt · lte · in · not_in
 *               · contains · not_contains · regex · is_empty · is_not_empty
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M2.W2
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Rule_Evaluator {

	/**
	 * Evaluate a rule's conditions against a context snapshot.
	 *
	 * @param array $conditions Decoded conditions JSON.
	 * @param array $context    { conversation, message?, contact?, payload }
	 * @return array { matched: bool, trace: array<string,mixed> }
	 */
	public static function evaluate( array $conditions, array $context ): array {
		$rules = $conditions['rules'] ?? array();
		if ( ! is_array( $rules ) || empty( $rules ) ) {
			return array( 'matched' => true, 'trace' => array( 'reason' => 'no_conditions' ) );
		}
		$op    = strtolower( (string) ( $conditions['operator'] ?? 'all' ) );
		$trace = array();
		$pass_count = 0;

		foreach ( $rules as $i => $rule ) {
			$field = (string) ( $rule['field'] ?? '' );
			$rop   = (string) ( $rule['op']    ?? 'equals' );
			$want  = $rule['value'] ?? null;
			$have  = self::resolve_field( $field, $context );
			$ok    = self::compare( $have, $rop, $want );
			$trace[] = array(
				'field' => $field,
				'op'    => $rop,
				'want'  => $want,
				'have'  => $have,
				'ok'    => $ok,
			);
			if ( $ok ) { $pass_count++; }
			if ( $op === 'any' && $ok ) {
				return array( 'matched' => true, 'trace' => $trace );
			}
			if ( $op === 'all' && ! $ok ) {
				return array( 'matched' => false, 'trace' => $trace );
			}
		}
		$matched = ( $op === 'any' ) ? ( $pass_count > 0 ) : ( $pass_count === count( $rules ) );
		return array( 'matched' => $matched, 'trace' => $trace );
	}

	/* ================================================================
	 * Field resolver
	 * ================================================================ */

	private static function resolve_field( string $field, array $context ) {
		// Dot-notation: custom_attr.region · contact.email · conversation.priority
		if ( strpos( $field, '.' ) !== false ) {
			list( $head, $rest ) = explode( '.', $field, 2 );
			if ( $head === 'custom_attr' ) {
				$attrs = $context['contact']['additional_attributes'] ?? array();
				if ( is_string( $attrs ) ) { $attrs = json_decode( $attrs, true ) ?: array(); }
				return $attrs[ $rest ] ?? null;
			}
			$bag = $context[ $head ] ?? array();
			return is_array( $bag ) ? ( $bag[ $rest ] ?? null ) : null;
		}
		// Top-level shortcuts (most common): pull from conversation/message/contact.
		$conv = $context['conversation'] ?? array();
		$msg  = $context['message']      ?? array();
		$ct   = $context['contact']      ?? array();
		switch ( $field ) {
			case 'status':         return $conv['status']         ?? null;
			case 'priority':       return isset( $conv['priority'] ) ? (int) $conv['priority'] : null;
			case 'inbox_id':       return isset( $conv['inbox_id'] ) ? (int) $conv['inbox_id'] : null;
			case 'assignee_id':    return isset( $conv['assignee_id'] ) ? (int) $conv['assignee_id'] : null;
			case 'labels':
				$raw = (string) ( $conv['cached_label_list'] ?? '' );
				return $raw === '' ? array() : array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
			case 'content':        return (string) ( $msg['content'] ?? '' );
			case 'message_type':   return $msg['message_type']  ?? null;
			case 'sender_type':    return $msg['sender_type']   ?? null;
			case 'content_type':   return $msg['content_type']  ?? null;
			case 'contact_email':  return $ct['email']          ?? null;
			case 'contact_phone':  return $ct['phone']          ?? null;
			case 'contact_name':   return $ct['name']           ?? null;
			case 'is_business_hours':
				if ( class_exists( 'BizCity_CRM_Working_Hours' ) ) {
					$inbox_id = (int) ( $conv['inbox_id'] ?? 0 );
					return BizCity_CRM_Working_Hours::is_open( $inbox_id, time() );
				}
				return null;
			default:
				return $context[ $field ] ?? null;
		}
	}

	/* ================================================================
	 * Operator implementations
	 * ================================================================ */

	private static function compare( $have, string $op, $want ): bool {
		switch ( $op ) {
			case 'equals':       return self::scalar_eq( $have, $want );
			case 'not_equals':   return ! self::scalar_eq( $have, $want );
			case 'gt':           return is_numeric( $have ) && is_numeric( $want ) && (float) $have >  (float) $want;
			case 'gte':          return is_numeric( $have ) && is_numeric( $want ) && (float) $have >= (float) $want;
			case 'lt':           return is_numeric( $have ) && is_numeric( $want ) && (float) $have <  (float) $want;
			case 'lte':          return is_numeric( $have ) && is_numeric( $want ) && (float) $have <= (float) $want;
			case 'in':
				return is_array( $want ) && in_array( $have, $want, false );
			case 'not_in':
				return is_array( $want ) && ! in_array( $have, $want, false );
			case 'contains':
				if ( is_array( $have ) ) { return in_array( $want, $have, true ); }
				if ( is_string( $have ) && is_string( $want ) ) {
					return $want !== '' && stripos( $have, $want ) !== false;
				}
				return false;
			case 'not_contains':
				return ! self::compare( $have, 'contains', $want );
			case 'regex':
				if ( ! is_string( $have ) || ! is_string( $want ) || $want === '' ) { return false; }
				$pattern = '/' . str_replace( '/', '\/', $want ) . '/iu';
				return @preg_match( $pattern, $have ) === 1;
			case 'is_empty':
				return $have === null || $have === '' || ( is_array( $have ) && empty( $have ) );
			case 'is_not_empty':
				return ! ( $have === null || $have === '' || ( is_array( $have ) && empty( $have ) ) );
		}
		return false;
	}

	private static function scalar_eq( $a, $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return (float) $a === (float) $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return strcasecmp( $a, $b ) === 0;
		}
		return $a == $b; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- intentional cross-type equality.
	}
}
