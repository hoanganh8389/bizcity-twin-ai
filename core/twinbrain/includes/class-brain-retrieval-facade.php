<?php
/**
 * One Brain retrieval facade shared by Twin GPT and MCP.
 *
 * PHASE-0.41D-D4. This is the only public read entry point for the bounded
 * Context Bank retrieval pack. Consumers may shape the response for their
 * surface, but may not create a second retrieval path.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-09-16 (PHASE-0.41D-CLOSURE / D4)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Brain_Retrieval_Facade', false ) ) {
	return;
}

final class BizCity_Brain_Retrieval_Facade {

	const VERSION = '1.0.0';

	/**
	 * Build one server-authorized pack for a surface.
	 *
	 * Browser/client fields that could become authority are ignored. The
	 * Context Bank scope resolver remains the tenant/account/identity authority.
	 *
	 * @param string $surface twin_gpt|twinchat|mcp|automation
	 * @param array  $request Untrusted request hints and bounded filters.
	 * @return array
	 */
	public static function pack( string $surface, array $request ): array {
		try {
			if ( ! self::load_context_bank() ) {
				return self::degraded( 'context_bank_runtime_unavailable' );
			}
			$surface = sanitize_key( $surface );
			if ( ! in_array( $surface, array( 'twin_gpt', 'twinchat', 'mcp', 'automation' ), true ) ) {
				return self::degraded( 'surface_invalid' );
			}
			$context = self::server_context( $request );
			$pack = BizCity_Context_Bank_Retrieval_Pack::build( $context );
			return self::shape_for_surface( $surface, $pack );
		} catch ( \Throwable $e ) {
			return self::degraded( 'facade_exception' );
		}
	}

	/**
	 * Return one bounded metadata summary for an authorized source reference.
	 * This is deliberately not a raw archive/file excerpt.
	 *
	 * @param string $source_ref
	 * @param array  $request
	 * @return array
	 */
	public static function evidence( string $source_ref, array $request ): array {
		$source_ref = sanitize_text_field( $source_ref );
		if ( $source_ref === '' || strlen( $source_ref ) > 191 ) {
			return array( 'ok' => false, 'code' => 'source_ref_invalid', 'reason_bucket' => 'source_ref_invalid' );
		}
		$request['filters'] = is_array( $request['filters'] ?? null ) ? $request['filters'] : array();
		$request['filters']['record_id'] = $source_ref;
		$pack = self::pack( 'mcp', $request );
		foreach ( (array) ( $pack['records'] ?? array() ) as $record ) {
			if ( is_array( $record ) && (string) ( $record['record_id'] ?? '' ) === $source_ref ) {
				return array(
					'ok' => true,
					'source_ref' => $source_ref,
					'record' => $record,
					'citation' => array( 'source_ref' => $source_ref, 'kind' => 'bounded_metadata' ),
					'degraded' => ! empty( $pack['degraded'] ),
					'incomplete' => ! empty( $pack['incomplete'] ),
				);
			}
		}
		return array(
			'ok' => false,
			'code' => ! empty( $pack['degraded'] ) ? 'context_not_admitted' : 'source_not_found',
			'reason_bucket' => (string) ( $pack['reason_bucket'] ?? 'source_not_found' ),
			'degraded' => ! empty( $pack['degraded'] ),
		);
	}

	/**
	 * Build a bounded order lifecycle summary through the same pack owner.
	 * No Woo/CRM repository or ledger is called here.
	 *
	 * @param string $order_ref
	 * @param array  $request
	 * @return array
	 */
	public static function order_summary( string $order_ref, array $request ): array {
		$order_ref = sanitize_text_field( $order_ref );
		if ( $order_ref === '' || strlen( $order_ref ) > 191 ) {
			return array( 'ok' => false, 'code' => 'order_ref_invalid', 'reason_bucket' => 'order_ref_invalid' );
		}
		$request['mode'] = 'context_bank';
		$request['filters'] = is_array( $request['filters'] ?? null ) ? $request['filters'] : array();
		$request['filters']['entity_type'] = 'order';
		$request['filters']['entity_key'] = $order_ref;
		$pack = self::pack( 'mcp', $request );
		return array(
			'ok' => ! empty( $pack['authorization']['allowed'] ) && ! empty( $pack['records'] ),
			'order_ref' => $order_ref,
			'summary' => array(
				'records' => (array) ( $pack['records'] ?? array() ),
				'rollups' => (array) ( $pack['rollups'] ?? array() ),
				'evidence_refs' => (array) ( $pack['evidence_refs'] ?? array() ),
			),
			'degraded' => ! empty( $pack['degraded'] ),
			'incomplete' => ! empty( $pack['incomplete'] ),
			'reason_bucket' => (string) ( $pack['reason_bucket'] ?? 'ok' ),
		);
	}

	private static function server_context( array $request ): array {
		$context = $request;
		// Never accept browser/client authority fields.
		foreach ( array( 'blog_id', 'user_id', 'owner_id', 'owner_user_id', 'tenant_id', 'physical_shard', 'notebook_authority', 'identity_scope' ) as $field ) {
			unset( $context[ $field ] );
		}
		$context['blog_id'] = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$context['user_id'] = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$context['mode'] = sanitize_key( (string) ( $request['mode'] ?? 'context_bank' ) );
		$context['filters'] = is_array( $request['filters'] ?? null ) ? $request['filters'] : array();
		return $context;
	}

	private static function shape_for_surface( string $surface, array $pack ): array {
		if ( 'mcp' !== $surface ) {
			$pack['retrieval_surface'] = $surface;
			return $pack;
		}
		// MCP gets the same evidence set, but no internal owner/path fields.
		$pack['retrieval_surface'] = 'mcp';
		return $pack;
	}

	private static function load_context_bank(): bool {
		if ( class_exists( 'BizCity_Context_Bank_Retrieval_Pack', false ) ) {
			return true;
		}
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
			$loader = dirname( __DIR__, 2 ) . '/helper/class-bizcity-safe-loader.php';
			if ( is_readable( $loader ) ) {
				require_once $loader;
			}
		}
		$bootstrap = dirname( __DIR__, 2 ) . '/context-bank/bootstrap.php';
		if ( class_exists( 'BizCity_Safe_Loader', false ) && is_readable( $bootstrap ) ) {
			BizCity_Safe_Loader::require_file( $bootstrap, 'brain.retrieval_facade.context_bank' );
		}
		return class_exists( 'BizCity_Context_Bank_Retrieval_Pack', false );
	}

	private static function degraded( string $reason ): array {
		return array(
			'contract' => 'context-retrieval-pack',
			'version' => '1.0.0',
			'query_id' => 'q_facade_' . substr( hash( 'sha256', $reason ), 0, 24 ),
			'scope' => array( 'mode' => 'skip', 'blog_id' => max( 1, (int) get_current_blog_id() ) ),
			'authorization' => array( 'allowed' => false, 'identity_scope' => 'identity', 'denied_count' => 0 ),
			'budget' => array( 'max_records' => 50, 'max_bytes' => 262144, 'max_ms' => 250, 'max_tokens' => 3000 ),
			'matched_count' => 0,
			'returned_count' => 0,
			'truncated' => false,
			'records' => array(), 'rollups' => array(), 'references' => array(), 'relations' => array(),
			'evidence_refs' => array(), 'kg_candidates' => array(),
			'degraded' => true, 'incomplete' => true, 'reason_bucket' => sanitize_key( $reason ),
		);
	}
}
