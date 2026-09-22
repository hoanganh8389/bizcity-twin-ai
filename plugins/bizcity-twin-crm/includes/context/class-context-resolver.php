<?php
/**
 * CRM Context App resolver — PHASE-0.63B WP-C06.
 *
 * Server-owned deterministic filtering for the right rail. The resolver never
 * trusts a client-provided app list; the client receives the selected apps and
 * diagnostic reasons for every rejected registration.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Context_Resolver', false ) ) {
	return;
}

final class BizCity_CRM_Context_Resolver {
	const DEFAULT_LIMIT = 6;

	public static function for_conversation( array $context = array() ): array {
		$surface = sanitize_key( (string) ( $context['surface'] ?? 'b2' ) );
		$roles = self::values( $context['subject_roles'] ?? ( $context['roles'] ?? array() ) );
		$kind = sanitize_key( (string) ( $context['pipeline_kind'] ?? '' ) );
		$channel = sanitize_key( (string) ( $context['channel'] ?? '' ) );
		$limit = self::limit( $context );
		$definition_tools = self::values( $context['definition_tools'] ?? array() );
		$apps = BizCity_CRM_Context_App_Registry::all();
		$selected = array();
		$rejected = array();

		foreach ( $apps as $key => $app ) {
			$reason = self::reject_reason( $app, $surface, $roles, $kind, $channel, $context );
			if ( '' !== $reason ) {
				$rejected[ $key ] = $reason;
				continue;
			}
			$selected[ $key ] = $app;
		}

		uksort( $selected, static function ( $left, $right ) use ( $selected, $definition_tools ) {
			$left_tool = array_search( $left, $definition_tools, true );
			$right_tool = array_search( $right, $definition_tools, true );
			if ( false !== $left_tool || false !== $right_tool ) {
				$left_rank = false === $left_tool ? PHP_INT_MAX : $left_tool;
				$right_rank = false === $right_tool ? PHP_INT_MAX : $right_tool;
				if ( $left_rank !== $right_rank ) {
					return $left_rank <=> $right_rank;
				}
			}
			$left_position = (int) $selected[ $left ]['position'];
			$right_position = (int) $selected[ $right ]['position'];
			return $left_position === $right_position ? strcmp( $left, $right ) : $left_position <=> $right_position;
		} );

		$all_keys = array_keys( $selected );
		$visible_keys = array_slice( $all_keys, 0, $limit );
		$more_keys = array_slice( $all_keys, $limit );
		$visible = array();
		foreach ( $visible_keys as $key ) {
			$visible[] = $selected[ $key ];
		}
		foreach ( $more_keys as $key ) {
			$rejected[ $key ] = 'limit_reached';
		}
		return array(
			'apps' => $visible,
			'visible' => $visible,
			'more' => array_values( array_map( static function ( $key ) use ( $selected ) { return $selected[ $key ]; }, $more_keys ) ),
			'rejected' => $rejected,
			'limit' => $limit,
			'total_matching' => count( $all_keys ),
		);
	}

	private static function reject_reason( array $app, string $surface, array $roles, string $kind, string $channel, array $context ): string {
		if ( ! in_array( $surface, $app['surfaces'], true ) ) return 'surface_mismatch';
		$applies = $app['applies_to'];
		if ( ! self::matches( $applies['subject_roles'], $roles, true ) ) return 'subject_role_mismatch';
		if ( ! empty( $applies['pipeline_kinds'] ) && ! in_array( $kind, $applies['pipeline_kinds'], true ) ) return 'pipeline_kind_mismatch';
		if ( ! self::matches( $applies['channels'], array( $channel ), false ) ) return 'channel_mismatch';
		if ( ! self::can( $app['applies_to']['capability'], $context ) ) return 'capability_denied';
		return '';
	}

	private static function matches( array $allowed, array $actual, bool $empty_allowed_matches_all ): bool {
		if ( in_array( '*', $allowed, true ) ) return true;
		if ( $empty_allowed_matches_all && empty( $allowed ) ) return true;
		return ! empty( array_intersect( $allowed, $actual ) );
	}

	private static function can( string $capability, array $context ): bool {
		if ( ! empty( $context['capabilities'] ) && is_array( $context['capabilities'] ) ) {
			return in_array( $capability, $context['capabilities'], true );
		}
		// Context manifests declare CRM actions (not raw WordPress caps) when
		// they start with `crm.`. Keep authority mapping in the canonical facade.
		if ( 0 === strpos( $capability, 'crm.' ) && class_exists( 'BizCity_CRM_Authority' ) ) {
			$decision = BizCity_CRM_Authority::can( $capability );
			return ! empty( $decision['ok'] );
		}
		return ! function_exists( 'current_user_can' ) || current_user_can( $capability );
	}

	private static function values( $value ): array {
		return is_array( $value ) ? array_values( array_unique( array_filter( array_map( array( __CLASS__, 'token' ), $value ) ) ) ) : array();
	}

	private static function token( $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9._*:-]+/i', '', (string) $value ) );
	}

	private static function limit( array $context ): int {
		$limit = isset( $context['limit'] ) ? (int) $context['limit'] : self::DEFAULT_LIMIT;
		if ( ! isset( $context['limit'] ) && function_exists( 'get_option' ) ) {
			$limit = (int) get_option( 'bizcity_crm_context_app_limit', self::DEFAULT_LIMIT );
		}
		return max( 1, min( 50, $limit ) );
	}
}
