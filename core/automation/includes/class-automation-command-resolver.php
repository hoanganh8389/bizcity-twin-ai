<?php
/**
 * Canonical explicit command resolver for Automation Workflow commands.
 *
 * `#workflow_slug` is intentionally separate from legacy `/slash_command` and
 * keyword matching. This class only resolves and authorizes command metadata;
 * runners remain owned by BizCity_Automation_Runner/Repo_Runs.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Command_Resolver', false ) ) {
	return;
}

final class BizCity_Automation_Command_Resolver {

	const MAX_SLUG_LENGTH = 64;

	/**
	 * Extract an explicit `#workflow_slug` from the beginning of a message.
	 *
	 * @return array|null {slug:string,args:string} or null when absent/invalid.
	 */
	public static function extract( $text ) {
		// [2026-08-16 Johnny Chu] CCG-1 — parse one exact leading #workflow_slug command.
		$text = ltrim( (string) $text );
		if ( $text === '' || $text[0] !== '#' ) {
			return null;
		}
		if ( ! preg_match( '/^#([a-zA-Z0-9_-]+)(?:\s+(.*))?$/s', $text, $matches ) ) {
			return null;
		}
		$slug = strtolower( (string) $matches[1] );
		if ( strlen( $slug ) > self::MAX_SLUG_LENGTH ) {
			return null;
		}
		return array(
			'slug' => $slug,
			'args' => isset( $matches[2] ) ? trim( (string) $matches[2] ) : '',
		);
	}

	/**
	 * Resolve one command-invokable workflow for an actor and surface.
	 *
	 * @return array {matched:bool,reason:string,workflow?:array,slug?:string,args?:string}
	 */
	public static function resolve( $text, array $identity = array(), array $context = array() ) {
		// [2026-08-16 Johnny Chu] CCG-1 — authorize one explicit workflow command without executing it.
		$parsed = self::extract( $text );
		if ( ! $parsed ) {
			return array( 'matched' => false, 'reason' => 'no_workflow_command' );
		}
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return array( 'matched' => false, 'reason' => 'workflow_repository_unavailable', 'slug' => $parsed['slug'] );
		}

		$workflow = BizCity_Automation_Repo_Workflows::find_by_slug( $parsed['slug'] );
		if ( ! is_array( $workflow ) ) {
			return array( 'matched' => false, 'reason' => 'workflow_not_found', 'slug' => $parsed['slug'] );
		}
		if ( empty( $workflow['enabled'] ) ) {
			return array( 'matched' => false, 'reason' => 'workflow_disabled', 'slug' => $parsed['slug'] );
		}

		$config = is_array( $workflow['trigger_config'] ?? null ) ? $workflow['trigger_config'] : array();
		if ( empty( $config['command_invokable'] ) ) {
			return array( 'matched' => false, 'reason' => 'workflow_command_not_enabled', 'slug' => $parsed['slug'] );
		}

		$requested_zone = sanitize_key( (string) ( $context['zone'] ?? $identity['zone'] ?? 'admin' ) );
		$config_zone    = sanitize_key( (string) ( $config['zone'] ?? 'admin' ) );
		if ( $requested_zone !== '' && $config_zone !== '' && $requested_zone !== $config_zone ) {
			return array( 'matched' => false, 'reason' => 'workflow_zone_denied', 'slug' => $parsed['slug'] );
		}

		$user_id        = (int) ( $identity['user_id'] ?? $identity['wp_user_id'] ?? 0 );
		$created_by     = (int) ( $workflow['created_by'] ?? 0 );
		$is_admin       = ! empty( $identity['is_admin'] ) || ( $user_id > 0 && current_user_can( 'manage_options' ) );
		$visibility     = sanitize_key( (string) ( $config['visibility'] ?? 'private' ) );
		$selector_mode  = sanitize_key( (string) ( $config['selector_mode'] ?? '' ) );
		$is_global      = 'global' === $visibility || 'global' === $selector_mode;
		$owner_required = ! empty( $config['owner_required'] );

		if ( ! $is_global && ! $is_admin && ( $user_id <= 0 || $created_by <= 0 || $created_by !== $user_id ) ) {
			return array( 'matched' => false, 'reason' => 'workflow_owner_denied', 'slug' => $parsed['slug'] );
		}
		if ( $owner_required && $user_id <= 0 ) {
			return array( 'matched' => false, 'reason' => 'workflow_identity_required', 'slug' => $parsed['slug'] );
		}

		return array(
			'matched'  => true,
			'reason'   => 'workflow_command_resolved',
			'workflow' => $workflow,
			'slug'     => $parsed['slug'],
			'args'     => $parsed['args'],
		);
	}

	/**
	 * Return command-invokable workflows visible to the current actor.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function suggestions( array $identity = array(), array $context = array(), $search = '', $limit = 20 ) {
		// [2026-08-16 Johnny Chu] CCG-1 — return actor-scoped command suggestions only.
		if ( ! class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			return array();
		}
		$out = BizCity_Automation_Repo_Workflows::query( array(
			'enabled' => 1,
			'zone'    => sanitize_key( (string) ( $context['zone'] ?? $identity['zone'] ?? 'admin' ) ),
			'search'  => sanitize_text_field( (string) $search ),
			'limit'   => 200,
		) );
		$items = array();
		foreach ( (array) ( $out['rows'] ?? array() ) as $workflow ) {
			$resolved = self::resolve( '#' . (string) ( $workflow['slug'] ?? '' ), $identity, $context );
			if ( empty( $resolved['matched'] ) ) {
				continue;
			}
			$config = is_array( $workflow['trigger_config'] ?? null ) ? $workflow['trigger_config'] : array();
			$items[] = array(
				'id'              => (int) ( $workflow['id'] ?? 0 ),
				'slug'            => (string) ( $workflow['slug'] ?? '' ),
				'name'            => (string) ( $workflow['name'] ?? '' ),
				'node_count'      => self::node_count( $workflow ),
				'created_by'      => (int) ( $workflow['created_by'] ?? 0 ),
				'zone'            => sanitize_key( (string) ( $config['zone'] ?? 'admin' ) ),
				'command_invokable' => true,
			);
			if ( count( $items ) >= max( 1, min( 50, (int) $limit ) ) ) {
				break;
			}
		}
		return $items;
	}

	private static function node_count( array $workflow ) {
		$graph = is_array( $workflow['graph'] ?? null ) ? $workflow['graph'] : array();
		$nodes = is_array( $graph['nodes'] ?? null ) ? $graph['nodes'] : array();
		return count( $nodes );
	}
}
