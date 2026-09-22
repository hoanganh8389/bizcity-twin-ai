<?php
/**
 * Canonical Context Retrieval Pack builder (L4 evidence pack).
 *
 * PHASE-0.41D §3 (D3.1). One owner composes the already-existing pieces into a
 * schema-valid `context-retrieval-pack@1.x`:
 *
 *   BizCity_Context_Bank_Scope_Resolver::resolve()   -> server-owned scope policy
 *   BizCity_Context_Bank_Search::search()            -> bounded ledger metadata + verified pointers
 *   BizCity_Context_Bank_Access::authorize_pointer() -> per-pointer tenant/owner recheck
 *   BizCity_Context_Bank_Rollup_Registry::all()      -> declarative rollup metadata
 *   BizCity_Context_Bank_KG_Candidate_Policy        -> stable-rollup-only KG gate
 *
 * Ownership rules honoured here:
 *   - This class owns no storage, no ACL and no semantic extraction. It never
 *     reads JSONL, ledger tables or file paths directly; every read goes through
 *     the canonical owners above.
 *   - `authorization.identity_scope` comes from the resolver, never from the request.
 *   - Fail-closed means a *valid empty pack with degraded=true*, never an exception
 *     and never a 500.
 *   - `evidence_refs[]` are references, not filesystem paths. No ciphertext, no
 *     raw body, no physical pointer leaves this boundary.
 *   - `query_id` is deterministic over the already-authorized input so D4 can
 *     prove Twin GPT/MCP parity by comparing two pack builds.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Context_Bank
 * @since      2026-09-16 (PHASE-0.41D-CLOSURE / D3)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Retrieval_Pack', false ) ) {
	return;
}

final class BizCity_Context_Bank_Retrieval_Pack {

	const CONTRACT = 'context-retrieval-pack';
	const VERSION  = '1.0.0';

	/** Canonical feature flag owner is BizCity_Context_Bank_Mode_Policy. */
	const FEATURE_FLAG = 'bizcity_context_bank_mpr_enabled';

	const DEFAULT_MAX_RECORDS = 50;
	const DEFAULT_MAX_BYTES   = 262144;
	const DEFAULT_MAX_MS      = 250;
	const DEFAULT_MAX_TOKENS  = 3000;

	/** Server-side ceilings. A request may narrow these but never widen them. */
	const HARD_MAX_RECORDS = 200;
	const HARD_MAX_BYTES   = 1048576;
	const HARD_MAX_MS      = 2000;
	const HARD_MAX_TOKENS  = 12000;

	/**
	 * Build one schema-valid retrieval pack. Never throws.
	 *
	 * @param array $request_context {
	 *   mode: 'context_bank'|'vertical'|'notebook'|'hybrid'|'recent_identity',
	 *   filters[], query?, cursor?, budget[],
	 *   channel?, platform?, account_key?, chat_kind?, case_id?, vertical_id?, notebook_id?
	 * }
	 * @return array schema-valid context-retrieval-pack
	 */
	public static function build( array $request_context ): array {
		try {
			return self::compose( $request_context );
		} catch ( \Throwable $e ) {
			// [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-0.41D-D3 — a builder fault must degrade, never surface as a 5xx.
			return self::degraded( 'builder_exception', array(), self::budgets( array() ) );
		}
	}

	/* ── Compose ───────────────────────────────────────────────────────────── */

	private static function compose( array $request_context ): array {
		$requested_budgets = is_array( $request_context['budget'] ?? null ) ? $request_context['budget'] : array();
		$budgets = self::budgets( $requested_budgets );
		$mode_raw = sanitize_key( (string) ( $request_context['mode'] ?? $request_context['scope'] ?? 'context_bank' ) );

		// Step 1 — server-owned scope policy.
		if ( ! class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) ) {
			return self::degraded( 'scope_owner_unavailable', array(), $budgets );
		}
		$scope = BizCity_Context_Bank_Scope_Resolver::resolve( $request_context );
		if ( ! is_array( $scope ) ) {
			return self::degraded( 'scope_resolution_invalid', array(), $budgets );
		}
		// Step 2 — after the scope resolves, adopt its canonical consumer budgets.
		$budgets = self::budgets( $requested_budgets, $scope );
		if ( empty( $scope['ok'] ) || 'skip' === (string) ( $scope['effective_mode'] ?? '' ) ) {
			// Fail-closed: an empty, schema-valid pack with an explicit reason bucket.
			return self::degraded(
				(string) ( $scope['reason_bucket'] ?? 'context_bank_scope_denied' ) ?: 'context_bank_scope_denied',
				$scope,
				$budgets
			);
		}

		// Step 2 — canonical feature flag owner (default-ON, stored option wins).
		if ( class_exists( 'BizCity_Context_Bank_Mode_Policy' ) && method_exists( 'BizCity_Context_Bank_Mode_Policy', 'is_enabled' ) ) {
			if ( ! BizCity_Context_Bank_Mode_Policy::is_enabled() ) {
				return self::degraded( 'context_bank_disabled', $scope, $budgets );
			}
		}

		// Step 3 — bounded authorized metadata search + verified pointer sample.
		if ( ! class_exists( 'BizCity_Context_Bank_Search' ) ) {
			return self::degraded( 'search_owner_unavailable', $scope, $budgets );
		}
		$filters = self::normalize_filters( $request_context, $scope );
		$pointer_limit = min( (int) $budgets['max_records'], (int) ( $scope['budgets']['max_pointer_follows'] ?? $budgets['max_records'] ) );
		$search = BizCity_Context_Bank_Search::search(
			$filters,
			(string) ( $request_context['cursor'] ?? '' ),
			max( 1, $pointer_limit ),
			(int) $budgets['max_ms']
		);

		if ( empty( $search['ok'] ) ) {
			return self::degraded(
				(string) ( $search['reason_bucket'] ?? 'context_bank_search_denied' ) ?: 'context_bank_search_denied',
				$scope,
				$budgets
			);
		}

		$rows          = is_array( $search['rows'] ?? null ) ? $search['rows'] : array();
		$owner_records = is_array( $search['owner_records'] ?? null ) ? $search['owner_records'] : array();

		// Step 4 — per-pointer authorization recheck. Denials are counted, never surfaced.
		$denied_count = 0;
		$authorized_owner_records = array();
		$approved_by_id = array();
		foreach ( $owner_records as $owner_record ) {
			if ( ! is_array( $owner_record ) ) {
				continue;
			}
			$record_id = (string) ( $owner_record['record_id'] ?? '' );
			$pointer = self::pointer_for( $rows, $record_id );
			$decision = self::authorize_pointer( $pointer );
			if ( empty( $decision['ok'] ) ) {
				$denied_count++;
				continue;
			}
			$approved_by_id[ $record_id ] = true;
			$authorized_owner_records[] = $owner_record;
		}
		// Metadata rows that failed pointer verification count as denials too.
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && empty( $row['verified'] ) ) {
				$denied_count++;
			}
		}

		// Step 5 — bounded record projection + budget truncation.
		$records      = array();
		$evidence     = array();
		$rollups      = array();
		$kg_candidates = array();
		$truncated    = ! empty( $search['truncated'] );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( count( $records ) >= (int) $budgets['max_records'] ) {
				$truncated = true;
				break;
			}
			$records[] = self::project_record( $row );
			$record_id = (string) ( $row['record_id'] ?? '' );
			if ( $record_id !== '' ) {
				$evidence[ $record_id ] = true;
			}
		}
		$truncated = $truncated || (int) ( $search['returned_count'] ?? 0 ) > count( $records );

		// Step 6 — rollups + KG candidates from verified owner records only.
		foreach ( $authorized_owner_records as $owner_record ) {
			$record = is_array( $owner_record['record'] ?? null ) ? $owner_record['record'] : array();
			$record_id = (string) ( $owner_record['record_id'] ?? '' );
			if ( $record_id !== '' ) {
				$evidence[ $record_id ] = true;
			}
			$provenance = (string) ( $record['provenance_ref'] ?? '' );
			if ( $provenance !== '' ) {
				$evidence[ $provenance ] = true;
			}

			if ( 'rollup' !== sanitize_key( (string) ( $record['record_kind'] ?? '' ) ) ) {
				continue;
			}
			if ( count( $rollups ) >= 100 ) {
				$truncated = true;
				break;
			}
			$rollups[] = self::project_rollup( $record_id, $owner_record, $record );

			if ( class_exists( 'BizCity_Context_Bank_KG_Candidate_Policy' ) && count( $kg_candidates ) < 100 ) {
				$candidate_context = array(
					'authorized'      => true,
					'pointer_verified' => true,
				);
				$record_for_policy = $record;
				$record_for_policy['record_id'] = $record_id;
				$decision = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( $record_for_policy, $candidate_context );
				if ( ! empty( $decision['ok'] ) ) {
					$kg_candidates[] = array(
						'record_id' => $record_id,
						'policy'    => (string) ( $decision['policy'] ?? 'summary_only' ),
					);
				}
			}
		}

		$evidence_refs = self::bounded_evidence_refs( $evidence, min( 200, (int) $budgets['max_records'] ) );

		$pack = array(
			'contract'       => self::CONTRACT,
			'version'        => self::VERSION,
			'query_id'       => '', // filled after scope shaping so it hashes authorized input only.
			'scope'          => self::project_scope( $scope, $mode_raw, $request_context ),
			'authorization'  => array(
				'allowed'        => true,
				'identity_scope' => self::identity_scope( $scope, $request_context ),
				'denied_count'   => $denied_count,
			),
			'budget'         => $budgets,
			'matched_count'  => (int) ( $search['matched_count'] ?? count( $records ) ),
			'returned_count' => count( $records ),
			'truncated'      => $truncated,
			'records'        => $records,
			'rollups'        => $rollups,
			'references'     => array(),
			'relations'      => array(),
			'evidence_refs'  => $evidence_refs,
			'kg_candidates'  => $kg_candidates,
			'degraded'       => ! empty( $search['degraded'] ),
			'incomplete'     => ! empty( $search['incomplete'] ),
			'reason_bucket'  => ! empty( $search['degraded'] )
				? ( (string) ( $search['reason_bucket'] ?? '' ) ?: 'context_bank_search_degraded' )
				: ( $truncated ? 'budget_truncated' : 'ok' ),
		);
		$pack['query_id'] = self::query_id( $request_context, $scope, $pack, $filters );

		return $pack;
	}

	/* ── Projections ───────────────────────────────────────────────────────── */

	private static function project_scope( array $scope, string $mode_raw, array $request_context ): array {
		$mode = (string) ( $scope['effective_mode'] ?? $mode_raw );
		$out  = array(
			'mode'    => in_array( $mode, array( 'context_bank', 'vertical', 'notebook', 'hybrid', 'recent_identity', 'skip' ), true ) ? $mode : 'context_bank',
			'blog_id' => max( 1, (int) ( $scope['blog_id'] ?? ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) ) ),
		);
		$vertical_id = sanitize_key( (string) ( $scope['vertical_id'] ?? $request_context['vertical_id'] ?? '' ) );
		if ( strlen( $vertical_id ) >= 2 ) {
			$out['vertical_id'] = $vertical_id;
		}
		$notebook_id = (int) ( $scope['notebook_id'] ?? 0 );
		if ( $notebook_id > 0 ) {
			$out['notebook_id'] = $notebook_id;
		}
		$case_id = sanitize_text_field( (string) ( $request_context['case_id'] ?? '' ) );
		if ( strlen( $case_id ) >= 2 ) {
			$out['case_id'] = substr( $case_id, 0, 191 );
		}
		return $out;
	}

	private static function identity_scope( array $scope, array $request_context ): string {
		$case_id = (string) ( $request_context['case_id'] ?? '' );
		if ( strlen( $case_id ) >= 2 ) {
			return 'case';
		}
		if ( (int) ( $scope['notebook_id'] ?? 0 ) > 0 ) {
			return 'notebook';
		}
		$resolved = (string) ( $scope['scope'] ?? '' );
		if ( 'tenant_admin' === $resolved ) {
			return 'tenant';
		}
		if ( 'channel_grant' === $resolved ) {
			return 'contact';
		}
		return 'identity';
	}

	/** Bounded record projection. Never contains a path, hash, ciphertext or body. */
	private static function project_record( array $row ): array {
		$allowed = array(
			'record_id', 'record_kind', 'source_contract_id', 'contract_version', 'schema_version',
			'identity_uuid', 'wp_user_id', 'contact_id', 'conversation_id',
			'entity_type', 'entity_key', 'secondary_type', 'secondary_key', 'scope_key',
			'case_id', 'goal_id', 'notebook_id', 'trace_id', 'event_uuid',
			'occurred_at', 'ingested_at', 'valid_from', 'valid_to',
			'rollup_window', 'rollup_version', 'operation', 'lifecycle_status',
			'kg_status', 'provenance_ref', 'verified',
		);
		$out = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$out[ $field ] = $row[ $field ];
			}
		}
		return $out;
	}

	private static function project_rollup( string $record_id, array $owner_record, array $record ): array {
		$out = array(
			'record_id'        => $record_id,
			'owner'            => sanitize_key( (string) ( $owner_record['owner'] ?? '' ) ),
			'source_contract_id' => sanitize_text_field( (string) ( $owner_record['source_contract_id'] ?? '' ) ),
			'rollup_window'    => sanitize_key( (string) ( $record['rollup_window'] ?? '' ) ),
			'rollup_version'   => sanitize_text_field( (string) ( $record['rollup_version'] ?? '' ) ),
			'provenance_ref'   => sanitize_text_field( (string) ( $record['provenance_ref'] ?? '' ) ),
		);
		$refs = array();
		foreach ( (array) ( $record['evidence_refs'] ?? array() ) as $ref ) {
			$ref = sanitize_text_field( (string) $ref );
			if ( $ref !== '' && strlen( $ref ) <= 191 ) {
				$refs[] = $ref;
			}
			if ( count( $refs ) >= 50 ) {
				break;
			}
		}
		$out['evidence_refs'] = $refs;
		if ( isset( $record['confidence'] ) && is_numeric( $record['confidence'] ) ) {
			$out['confidence'] = (float) $record['confidence'];
		}
		return array_filter(
			$out,
			static function ( $value ) {
				return $value !== '' && $value !== array() && $value !== null;
			}
		);
	}

	/* ── Budgets ───────────────────────────────────────────────────────────── */

	/**
	 * Resolve budgets. A request may NARROW a server default but never widen it.
	 *
	 * @param array $requested      Request-provided budget hints (untrusted).
	 * @param array $resolved_scope Resolved scope envelope carrying canonical budgets.
	 * @return array{max_records:int,max_bytes:int,max_ms:int,max_tokens:int}
	 */
	private static function budgets( array $requested = array(), array $resolved_scope = array() ): array {
		$server = array(
			'max_records' => self::DEFAULT_MAX_RECORDS,
			'max_bytes'   => self::DEFAULT_MAX_BYTES,
			'max_ms'      => self::DEFAULT_MAX_MS,
			'max_tokens'  => self::DEFAULT_MAX_TOKENS,
		);
		// The resolver owns the canonical consumer budgets; read them from its
		// resolved envelope. `budgets()` itself is private, so this boundary must
		// never call it directly.
		$resolver_budgets = (array) ( $resolved_scope['budgets'] ?? array() );
		if ( ! empty( $resolver_budgets ) ) {
			if ( isset( $resolver_budgets['max_rows'] ) ) {
				$server['max_records'] = (int) $resolver_budgets['max_rows'];
			}
			if ( isset( $resolver_budgets['max_decrypted_bytes'] ) ) {
				$server['max_bytes'] = (int) $resolver_budgets['max_decrypted_bytes'];
			}
			if ( isset( $resolver_budgets['max_time_ms'] ) ) {
				$server['max_ms'] = (int) $resolver_budgets['max_time_ms'];
			}
		}
		$ceiling = array(
			'max_records' => min( max( 1, $server['max_records'] ), self::HARD_MAX_RECORDS ),
			'max_bytes'   => min( max( 1, $server['max_bytes'] ), self::HARD_MAX_BYTES ),
			'max_ms'      => min( max( 1, $server['max_ms'] ), self::HARD_MAX_MS ),
			'max_tokens'  => min( max( 1, $server['max_tokens'] ), self::HARD_MAX_TOKENS ),
		);
		foreach ( array( 'max_records', 'max_bytes', 'max_ms', 'max_tokens' ) as $key ) {
			$requested_value = absint( $requested[ $key ] ?? 0 );
			if ( $requested_value > 0 ) {
				$ceiling[ $key ] = min( $requested_value, $ceiling[ $key ] );
			}
		}
		return $ceiling;
	}

	/* ── Helpers ───────────────────────────────────────────────────────────── */

	private static function normalize_filters( array $request_context, array $scope ): array {
		$filters = is_array( $request_context['filters'] ?? null ) ? $request_context['filters'] : array();
		$allowed_contracts = (array) ( $scope['allowed_contracts'] ?? array() );
		$requested_contracts = array();
		foreach ( (array) ( $filters['source_contract_ids'] ?? array() ) as $contract_id ) {
			$contract_id = sanitize_text_field( (string) $contract_id );
			if ( $contract_id !== '' && in_array( $contract_id, $allowed_contracts, true ) ) {
				$requested_contracts[] = $contract_id;
			}
		}
		// A request may only narrow the authorized contract set, never extend it.
		$filters['source_contract_ids'] = ! empty( $requested_contracts ) ? array_values( array_unique( $requested_contracts ) ) : $allowed_contracts;

		$allowed_record_kinds = (array) ( $scope['allowed_record_kinds'] ?? array() );
		if ( ! empty( $filters['record_kind'] ) ) {
			$kind = sanitize_key( (string) $filters['record_kind'] );
			if ( ! in_array( $kind, $allowed_record_kinds, true ) ) {
				unset( $filters['record_kind'] );
			}
		}
		$blog_id = (int) ( $scope['blog_id'] ?? 0 );
		if ( $blog_id > 0 ) {
			$filters['blog_id'] = $blog_id;
		}
		return $filters;
	}

	private static function pointer_for( array $rows, string $record_id ): array {
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && (string) ( $row['record_id'] ?? '' ) === $record_id ) {
				return $row;
			}
		}
		return array( 'record_id' => $record_id );
	}

	private static function authorize_pointer( array $pointer ): array {
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) || ! method_exists( 'BizCity_Context_Bank_Access', 'authorize_pointer' ) ) {
			return array( 'ok' => false, 'reason' => 'access_owner_unavailable' );
		}
		$decision = BizCity_Context_Bank_Access::authorize_pointer( $pointer );
		return is_array( $decision ) ? $decision : array( 'ok' => false, 'reason' => 'access_decision_invalid' );
	}

	private static function bounded_evidence_refs( array $evidence, int $limit ): array {
		$limit = max( 1, min( 200, $limit ) );
		$out = array();
		foreach ( array_keys( $evidence ) as $ref ) {
			$ref = (string) $ref;
			if ( $ref === '' || strlen( $ref ) < 3 || strlen( $ref ) > 191 ) {
				continue;
			}
			$out[] = $ref;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Deterministic identity of the authorized query.
	 *
	 * D4 proves Twin GPT/MCP parity by comparing this value across surfaces, so
	 * every input here must already be server-authorized: never a raw request
	 * filter set or a client-supplied owner.
	 */
	private static function query_id( array $request_context, array $scope, array $pack, array $filters ): string {
		$canonical_filters = $filters;
		ksort( $canonical_filters );
		$body = array(
			'contract' => self::CONTRACT,
			'version'  => self::VERSION,
			'blog_id'  => (int) ( $scope['blog_id'] ?? 0 ),
			'owner'    => (int) ( $scope['owner_user_id'] ?? 0 ),
			'mode'     => (string) ( $pack['scope']['mode'] ?? '' ),
			'vertical' => (string) ( $pack['scope']['vertical_id'] ?? '' ),
			'notebook' => (int) ( $pack['scope']['notebook_id'] ?? 0 ),
			'mode_policy_version' => (string) ( $scope['mode_policy_version'] ?? '' ),
			'filters'  => $canonical_filters,
			'cursor'   => (string) ( $request_context['cursor'] ?? '' ),
			'budget'   => $pack['budget'],
		);
		return 'q_' . substr( hash( 'sha256', (string) wp_json_encode( $body ) ), 0, 40 );
	}

	/**
	 * A valid, empty, fail-closed pack. Always schema-valid.
	 */
	private static function degraded( string $reason_bucket, array $scope, array $budgets ): array {
		$reason_bucket = sanitize_key( $reason_bucket );
		if ( strlen( $reason_bucket ) < 2 ) {
			$reason_bucket = 'context_bank_unavailable';
		}
		$blog_id = (int) ( $scope['blog_id'] ?? 0 );
		if ( $blog_id <= 0 ) {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		}
		return array(
			'contract'       => self::CONTRACT,
			'version'        => self::VERSION,
			'query_id'       => 'q_degraded_' . substr( hash( 'sha256', $reason_bucket . '|' . $blog_id ), 0, 24 ),
			'scope'          => array(
				'mode'    => 'skip',
				'blog_id' => max( 1, $blog_id ),
			),
			'authorization'  => array(
				'allowed'        => false,
				'identity_scope' => 'identity',
				'denied_count'   => 0,
			),
			'budget'         => $budgets,
			'matched_count'  => 0,
			'returned_count' => 0,
			'truncated'      => false,
			'records'        => array(),
			'rollups'        => array(),
			'references'     => array(),
			'relations'      => array(),
			'evidence_refs'  => array(),
			'kg_candidates'  => array(),
			'degraded'       => true,
			'incomplete'     => true,
			'reason_bucket'  => substr( $reason_bucket, 0, 80 ),
		);
	}
}
