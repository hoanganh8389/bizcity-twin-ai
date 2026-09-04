<?php
/**
 * Context Bank to KG-Hub promotion bridge.
 *
 * Context Bank decides eligibility and owns the pointer. KG-Hub owns semantic
 * extraction, passage storage and vector/provenance persistence.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_KG_Bridge', false ) ) {
	return;
}

final class BizCity_Context_Bank_KG_Bridge {

	const FEATURE_FLAG = 'bizcity_context_bank_kg_bridge_enabled';
	const VERSION = '1.0.0';
	const RECHECK_HOOK = 'bizcity_context_bank_kg_recheck';
	const RECHECK_MAX_ATTEMPTS = 3;

	/**
	 * Promote one verified stable rollup through the canonical KG-Hub owner.
	 *
	 * @param array<string,mixed> $pointer Context Bank pointer row.
	 * @param array<string,mixed> $context Server-owned promotion context.
	 * @return array<string,mixed>
	 */
	public static function promote( array $pointer, array $context = array() ) {
		// [2026-09-02 Johnny Chu] PHASE-CB6.3 — keep KG promotion explicitly gated and route all semantic writes through KG-Hub.
		if ( ! self::enabled() ) {
			return array( 'ok' => true, 'promoted' => false, 'reason' => 'kg_promotion_disabled' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_KG_Candidate_Policy' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_bridge_dependency_missing' );
		}
		$record_id = (string) ( $pointer['record_id'] ?? '' );
		if ( $record_id === '' || (string) ( $pointer['record_kind'] ?? '' ) !== 'rollup' ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_rollup_pointer_required' );
		}
		// [2026-09-02 11:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — never trust caller-supplied KG IDs as replay proof; authorization must start from the canonical ledger pointer.
		$ledger = BizCity_Context_Bank_Ledger::instance();
		$verified = $ledger->follow( $record_id, array(
			'blog_id' => (int) ( $pointer['blog_id'] ?? get_current_blog_id() ),
			'source_contract_id' => (string) ( $pointer['source_contract_id'] ?? '' ),
		) );
		if ( empty( $verified['ok'] ) || empty( $verified['verified'] ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_pointer_unverified' );
		}
		$pointer = is_array( $verified['pointer'] ?? null ) ? $verified['pointer'] : $pointer;
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — read canonical rollup metadata before policy evaluation so stable/confidence gates use owner evidence rather than caller-supplied pointer fields.
		$followed = self::read_pointer( $pointer );
		if ( ! is_array( $followed ) || empty( $followed['ok'] ) || ! is_array( $followed['record'] ?? null ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_rollup_body_unavailable' );
		}
		$record = $followed['record'];
		$policy_record = array_merge( $pointer, self::policy_metadata( $record ) );
		$policy = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( $policy_record, array_merge( $context, array( 'pointer_verified' => true ) ) );
		if ( empty( $policy['candidate'] ) ) {
			return array( 'ok' => true, 'promoted' => false, 'reason' => (string) ( $policy['reason'] ?? 'kg_candidate_rejected' ), 'policy_version' => self::VERSION );
		}
		$existing_source_id = (int) ( $pointer['kg_source_id'] ?? 0 );
		$existing_passage_id = (int) ( $pointer['kg_passage_id'] ?? 0 );
		if ( $existing_source_id > 0 || $existing_passage_id > 0 ) {
			$replay = self::verify_existing_promotion( $pointer, $record_id );
			if ( empty( $replay['ok'] ) ) {
				return $replay;
			}
			return array( 'ok' => true, 'promoted' => true, 'replayed' => true, 'kg_source_id' => $existing_source_id, 'kg_passage_id' => $existing_passage_id, 'provenance_ref' => (string) ( $pointer['provenance_ref'] ?? '' ), 'provenance_verified' => true );
		}
		if ( ! class_exists( 'BizCity_KG' ) || ! method_exists( 'BizCity_KG', 'ingest_extension_source' ) ) {
			return self::defer_candidate( $ledger, $pointer, 'kg_hub_owner_unavailable', (int) ( $context['recheck_attempt'] ?? 0 ) );
		}
		$content = self::summary_content( $record );
		$notebook_id = (int) ( $context['notebook_id'] ?? $pointer['notebook_id'] ?? 0 );
		if ( $content === '' || $notebook_id <= 0 ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_rollup_summary_or_notebook_missing' );
		}
		$promotion_user_id = (int) ( $pointer['wp_user_id'] ?? 0 );
		if ( $promotion_user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
			$promotion_user_id = (int) get_current_user_id();
		}
		if ( ! self::notebook_allowed_for_user( $notebook_id, $promotion_user_id ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_notebook_scope_denied' );
		}
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) && method_exists( 'BizCity_KG_Cost_Guard', 'instance' ) ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.2 — enforce the canonical KG cost guard before creating a source or passage.
			$cost_check = BizCity_KG_Cost_Guard::instance()->can_extract( (int) ( $pointer['wp_user_id'] ?? 0 ), 1 );
			if ( is_wp_error( $cost_check ) ) {
				return array( 'ok' => true, 'promoted' => false, 'reason' => 'kg_cost_guard_denied', 'cost_reason' => sanitize_key( (string) $cost_check->get_error_code() ) );
			}
		}
		$result = BizCity_KG::ingest_extension_source(
			array( 'plugin' => 'context-bank', 'notebook_id' => $notebook_id, 'user_id' => (int) ( $pointer['wp_user_id'] ?? 0 ) ),
			array(
				'type' => 'context_rollup',
				'title' => 'Context Bank rollup ' . $record_id,
				'content' => $content,
				'origin_id' => (int) ( $pointer['id'] ?? 0 ),
				'metadata' => array( 'context_bank_record_id' => $record_id, 'policy_version' => (string) ( $policy['policy_version'] ?? '' ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			$error_code = sanitize_key( (string) $result->get_error_code() );
			if ( in_array( $error_code, array( 'kg_extension_unavailable', 'kg_extension_vector_unavailable', 'kg_extension_embedding_failed' ), true ) ) {
				return self::defer_candidate( $ledger, $pointer, $error_code, (int) ( $context['recheck_attempt'] ?? 0 ) );
			}
		}
		if ( is_wp_error( $result ) || ! is_array( $result ) || (int) ( $result['source_id'] ?? 0 ) <= 0 || empty( $result['passage_ids'][0] ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_hub_promotion_failed' );
		}
		$kg_source_id = (int) $result['source_id'];
		$kg_passage_id = (int) $result['passage_ids'][0];
		$provenance_ref = 'context-bank:' . $record_id;
		if ( ! method_exists( 'BizCity_KG', 'xref' ) || (int) ( $pointer['id'] ?? 0 ) <= 0 ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_provenance_pointer_identity_missing' );
		}
		$existing_xref_id = 0;
		$existing_xrefs = BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
		foreach ( (array) $existing_xrefs as $existing_xref ) {
			if ( is_array( $existing_xref ) && (int) ( $existing_xref['cortex_ref_id'] ?? 0 ) === (int) $pointer['id'] ) {
				$existing_xref_id = (int) ( $existing_xref['id'] ?? 0 );
				break;
			}
		}
		$xref_id = $existing_xref_id > 0 ? $existing_xref_id : BizCity_KG::xref( array( 'cortex' => 'context-bank', 'cortex_table' => BizCity_Context_Bank_Ledger::table(), 'cortex_ref_id' => (int) $pointer['id'], 'kg_ref_type' => 'passage', 'kg_ref_id' => $kg_passage_id, 'relation' => 'promoted', 'meta' => array( 'record_id' => $record_id, 'source_contract_id' => (string) ( $pointer['source_contract_id'] ?? '' ), 'provenance_ref' => $provenance_ref ) ) );
		if ( $xref_id <= 0 || ! method_exists( 'BizCity_KG', 'lookup_xref' ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_provenance_write_failed' );
		}
		$reverse = BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
		$reverse_ok = false;
		foreach ( (array) $reverse as $edge ) {
			if ( is_array( $edge ) && (int) ( $edge['cortex_ref_id'] ?? 0 ) === (int) $pointer['id'] && (string) ( $edge['relation'] ?? '' ) === 'promoted' ) {
				$reverse_ok = true;
				break;
			}
		}
		if ( ! $reverse_ok ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_provenance_reverse_lookup_failed' );
		}
		$updated = $ledger->update_kg_reference( $pointer, $kg_source_id, $kg_passage_id, $provenance_ref );
		if ( empty( $updated['ok'] ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_pointer_provenance_update_failed' );
		}
		return array( 'ok' => true, 'promoted' => true, 'kg_source_id' => $kg_source_id, 'kg_passage_id' => $kg_passage_id, 'provenance_ref' => $provenance_ref, 'provenance_verified' => true );
	}

	/**
	 * Resolve a KG passage citation through the verified Context Bank owner.
	 *
	 * @param int                  $kg_passage_id Canonical KG passage ID.
	 * @param array<string,mixed>  $context Server-owned authorization context.
	 * @return array<string,mixed>
	 */
	public static function resolve_citation( $kg_passage_id, array $context = array() ) {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — traverse passage xref, current-tenant pointer authorization and canonical owner read in one bounded citation owner.
		$kg_passage_id = (int) $kg_passage_id;
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $kg_passage_id <= 0 || $current_blog_id <= 0 ) {
			return array( 'ok' => false, 'reason' => 'kg_citation_identity_invalid' );
		}
		if ( ! class_exists( 'BizCity_KG' ) || ! method_exists( 'BizCity_KG', 'lookup_xref' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'reason' => 'kg_citation_owner_unavailable' );
		}
		$edges = BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
		foreach ( (array) $edges as $edge ) {
			if ( ! is_array( $edge ) || (int) ( $edge['kg_ref_id'] ?? 0 ) !== $kg_passage_id || (string) ( $edge['relation'] ?? '' ) !== 'promoted' ) {
				continue;
			}
			$meta = json_decode( (string) ( $edge['meta'] ?? '' ), true );
			$meta = is_array( $meta ) ? $meta : array();
			$record_id = sanitize_text_field( (string) ( $meta['record_id'] ?? '' ) );
			$contract_id = sanitize_text_field( (string) ( $meta['source_contract_id'] ?? $context['source_contract_id'] ?? '' ) );
			if ( $record_id === '' || $contract_id === '' || (int) ( $edge['cortex_ref_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => $current_blog_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'limit' => 1 ) );
			if ( empty( $rows[0] ) || ! is_array( $rows[0] ) || (int) ( $rows[0]['id'] ?? 0 ) !== (int) $edge['cortex_ref_id'] ) {
				continue;
			}
			$pointer = $rows[0];
			$follow = BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'blog_id' => $current_blog_id, 'source_contract_id' => $contract_id ) );
			if ( empty( $follow['ok'] ) || empty( $follow['verified'] ) ) {
				continue;
			}
			$owner_read = self::read_pointer( $pointer );
			if ( ! is_array( $owner_read ) || empty( $owner_read['ok'] ) || ! is_array( $owner_read['record'] ?? null ) ) {
				continue;
			}
			return array( 'ok' => true, 'kg_passage_id' => $kg_passage_id, 'record_id' => $record_id, 'source_contract_id' => $contract_id, 'pointer_id' => (int) $pointer['id'], 'owner' => 'context-bank-canonical-owner', 'source_view' => self::citation_source_view( $owner_read['record'] ) );
		}
		return array( 'ok' => false, 'reason' => 'kg_citation_pointer_not_found' );
	}

	/**
	 * Reconcile existing KG provenance for one Context Bank pointer.
	 *
	 * @param array<string,mixed> $pointer Context Bank pointer row.
	 * @param array<string,mixed> $context Reconciliation context.
	 * @return array<string,mixed>
	 */
	public static function reconcile_provenance( array $pointer, array $context = array() ) {
		// [2026-09-02 05:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.4 — reconcile only through canonical ledger follow and KG facade lookup, then mark stale provenance in the pointer ledger.
		if ( ! self::enabled() ) {
			return array( 'ok' => true, 'action' => 'deferred', 'reason' => 'kg_promotion_disabled' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_KG' ) || ! method_exists( 'BizCity_KG', 'lookup_xref' ) ) {
			return array( 'ok' => false, 'action' => 'deferred', 'reason' => 'kg_reconcile_dependency_missing' );
		}
		$record_id = (string) ( $pointer['record_id'] ?? '' );
		$ledger = BizCity_Context_Bank_Ledger::instance();
		$follow = $ledger->follow( $record_id, array(
			'blog_id' => (int) ( $pointer['blog_id'] ?? get_current_blog_id() ),
			'source_contract_id' => (string) ( $pointer['source_contract_id'] ?? '' ),
		) );
		if ( empty( $follow['ok'] ) || empty( $follow['verified'] ) ) {
			return array( 'ok' => false, 'action' => 'deferred', 'reason' => 'kg_reconcile_pointer_unverified' );
		}
		$canonical = is_array( $follow['pointer'] ?? null ) ? $follow['pointer'] : $pointer;
		$passage_id = (int) ( $canonical['kg_passage_id'] ?? 0 );
		$pointer_id = (int) ( $canonical['id'] ?? 0 );
		if ( $passage_id <= 0 || $pointer_id <= 0 ) {
			return array( 'ok' => true, 'action' => 'not_linked', 'reason' => 'kg_provenance_not_linked' );
		}
		$edges = BizCity_KG::lookup_xref( 'passage', $passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
		$linked = false;
		foreach ( (array) $edges as $edge ) {
			if ( is_array( $edge ) && (int) ( $edge['cortex_ref_id'] ?? 0 ) === $pointer_id && (int) ( $edge['kg_ref_id'] ?? 0 ) === $passage_id && (string) ( $edge['relation'] ?? '' ) === 'promoted' ) {
				$linked = true;
				break;
			}
		}
		$deleted = (string) ( $canonical['operation'] ?? '' ) === 'delete' || (string) ( $canonical['lifecycle_status'] ?? '' ) === 'deleted';
		if ( $deleted || ! $linked ) {
			$reason = $deleted ? 'kg_source_pointer_deleted' : 'kg_reverse_xref_missing';
			$marked = $ledger->mark_kg_status( $canonical, 'stale', $reason );
			if ( empty( $marked['ok'] ) ) {
				return array( 'ok' => false, 'action' => 'mark_stale_failed', 'reason' => 'kg_provenance_stale_mark_failed' );
			}
			self::cron_note_event( 'kg_provenance_stale', array( 'reason' => $reason, 'record_id_hash' => substr( hash( 'sha256', $record_id ), 0, 12 ) ) );
			$recheck_scheduled = self::schedule_recheck( $canonical, $reason );
			return array( 'ok' => true, 'action' => 'stale', 'reason' => $reason, 'kg_passage_id' => $passage_id, 'recheck_scheduled' => $recheck_scheduled );
		}
		return array( 'ok' => true, 'action' => 'keep', 'reason' => 'kg_provenance_verified', 'kg_passage_id' => $passage_id );
	}

	private static function cron_note_event( $name, array $data = array() ) {
		// [2026-09-02 05:10 PM Johnny Chu - Chu Hoàng Anh] R-CRON-META — record bounded KG provenance reconcile outcomes on an active parent run.
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( (string) $name, $data );
		}
	}

	private static function defer_candidate( $ledger, array $pointer, $reason, $attempt = 0 ) {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — preserve the verified source pointer as pending when KG infrastructure is unavailable, then request one bounded retry.
		try {
			$marked = $ledger->mark_kg_status( $pointer, 'pending', $reason );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_pending_mark_failed' );
		}
		if ( empty( $marked['ok'] ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_pending_mark_failed' );
		}
		$scheduled = self::schedule_recheck( $pointer, $reason, max( 0, (int) $attempt ) );
		return array( 'ok' => true, 'promoted' => false, 'action' => 'deferred', 'kg_status' => 'pending', 'reason' => sanitize_key( (string) $reason ), 'recheck_scheduled' => $scheduled, 'recheck_attempt' => max( 0, (int) $attempt ) );
	}

	private static function schedule_recheck( array $pointer, $reason, $attempt = 0 ) {
		// [2026-09-04 Johnny Chu - Chu Hoàng Anh] PHASE-CB6.4 — cap tenant-bound KG retries so an unavailable KG cannot create an unbounded cron loop.
		if ( self::diagnostics_blocked() || ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}
		$attempt = max( 0, (int) $attempt );
		if ( $attempt >= self::RECHECK_MAX_ATTEMPTS ) {
			self::cron_note_event( 'kg_recheck_exhausted', array( 'reason' => sanitize_key( (string) $reason ), 'attempt' => $attempt ) );
			return false;
		}
		$job = array( 'blog_id' => (int) ( $pointer['blog_id'] ?? 0 ), 'record_id' => (string) ( $pointer['record_id'] ?? '' ), 'source_contract_id' => (string) ( $pointer['source_contract_id'] ?? '' ) );
		$job['attempt'] = $attempt + 1;
		if ( $job['blog_id'] <= 0 || $job['record_id'] === '' || $job['source_contract_id'] === '' ) {
			return false;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::RECHECK_HOOK, array( $job ) ) ) {
			return true;
		}
		try {
			$scheduled = wp_schedule_single_event( time() + 60, self::RECHECK_HOOK, array( $job ) );
		} catch ( \Throwable $e ) {
			self::cron_note_event( 'kg_recheck_schedule_failed', array( 'reason' => 'schedule_exception', 'attempt' => $job['attempt'] ) );
			return false;
		}
		$ok = false !== $scheduled;
		self::cron_note_event( $ok ? 'kg_recheck_scheduled' : 'kg_recheck_schedule_failed', array( 'reason' => sanitize_key( (string) $reason ), 'attempt' => $job['attempt'] ) );
		return $ok;
	}

	public static function run_recheck( $job = array() ) {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB6.4 — block scheduled and direct retry entry in Diagnostics CLI before any KG/provider side effect.
		if ( self::diagnostics_blocked() ) {
			return array( 'ok' => false, 'reason' => 'diagnostics_cli_isolated' );
		}
		$job = is_array( $job ) ? $job : array();
		$blog_id = (int) ( $job['blog_id'] ?? 0 );
		$record_id = sanitize_text_field( (string) ( $job['record_id'] ?? '' ) );
		$contract_id = sanitize_text_field( (string) ( $job['source_contract_id'] ?? '' ) );
		if ( $blog_id <= 0 || $blog_id !== (int) get_current_blog_id() || $record_id === '' || $contract_id === '' || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'reason' => 'kg_recheck_scope_invalid' );
		}
		$rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => $blog_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'limit' => 1 ) );
		if ( empty( $rows[0] ) || ! is_array( $rows[0] ) ) {
			return array( 'ok' => false, 'reason' => 'kg_recheck_pointer_missing' );
		}
		$result = self::promote( $rows[0], array( 'authorized' => true, 'recheck_attempt' => max( 1, (int) ( $job['attempt'] ?? 1 ) ) ) );
		if ( is_array( $result ) && empty( $result['promoted'] ) && (string) ( $result['action'] ?? '' ) === 'deferred' ) {
			$result['recheck_attempt'] = (int) ( $job['attempt'] ?? 1 );
		}
		self::cron_note_event( 'kg_recheck_completed', array( 'reason' => sanitize_key( (string) ( $result['reason'] ?? ( ! empty( $result['promoted'] ) ? 'promoted' : 'deferred' ) ) ) ) );
		return is_array( $result ) ? $result : array( 'ok' => false, 'reason' => 'kg_recheck_invalid_result' );
	}

	private static function citation_source_view( array $record ) {
		$view = array();
		foreach ( array( 'record_id', 'record_kind', 'rollup_id', 'dimension_key', 'summary_text', 'summary', 'state', 'evidence_refs', 'rollup_version', 'provenance_ref', 'occurred_at', 'entity_type', 'entity_key' ) as $field ) {
			if ( array_key_exists( $field, $record ) ) {
				$view[ $field ] = $record[ $field ];
			}
		}
		return $view;
	}

	private static function diagnostics_blocked() {
		return defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
	}

	private static function notebook_allowed_for_user( $notebook_id, $user_id ) {
		// [2026-09-02 03:25 PM Johnny Chu - Chu Hoàng Anh] PHASE-CB7.1 — authorize the target notebook through the canonical KG owner before Context Bank promotion.
		if ( (int) $notebook_id <= 0 || (int) $user_id <= 0 || ! class_exists( 'BizCity_KG_Notebook_Service' ) || ! method_exists( 'BizCity_KG_Notebook_Service', 'instance' ) ) {
			return false;
		}
		$notebooks = BizCity_KG_Notebook_Service::instance()->list_for_user( (int) $user_id, array( 'include_public' => true, 'limit' => 500 ) );
		foreach ( (array) $notebooks as $notebook ) {
			if ( is_array( $notebook ) && (int) ( $notebook['id'] ?? 0 ) === (int) $notebook_id ) {
				return true;
			}
		}
		return false;
	}

	private static function verify_existing_promotion( array $pointer, $record_id ) {
		// [2026-09-02 11:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — require the canonical ledger row and reverse KG xref to agree before acknowledging a replay.
		$kg_passage_id = (int) ( $pointer['kg_passage_id'] ?? 0 );
		$pointer_id = (int) ( $pointer['id'] ?? 0 );
		if ( $pointer_id <= 0 || $kg_passage_id <= 0 || (int) ( $pointer['kg_source_id'] ?? 0 ) <= 0 ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_replay_provenance_identity_missing', 'record_id' => (string) $record_id );
		}
		if ( ! class_exists( 'BizCity_KG' ) || ! method_exists( 'BizCity_KG', 'lookup_xref' ) ) {
			return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_replay_owner_unavailable', 'record_id' => (string) $record_id );
		}
		$reverse = BizCity_KG::lookup_xref( 'passage', $kg_passage_id, array( 'cortex' => 'context-bank', 'relation' => 'promoted', 'limit' => 20 ) );
		foreach ( (array) $reverse as $edge ) {
			if ( is_array( $edge ) && (int) ( $edge['cortex_ref_id'] ?? 0 ) === $pointer_id && (int) ( $edge['kg_ref_id'] ?? 0 ) === $kg_passage_id && (string) ( $edge['relation'] ?? '' ) === 'promoted' ) {
				return array( 'ok' => true, 'promoted' => true );
			}
		}
		return array( 'ok' => false, 'promoted' => false, 'reason' => 'kg_replay_provenance_unverified', 'record_id' => (string) $record_id );
	}

	private static function policy_metadata( array $record ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB6.3 — pass only bounded rollup eligibility metadata into the candidate policy; never copy payload fields into the ledger pointer.
		$metadata = array();
		if ( array_key_exists( 'stable', $record ) ) {
			$metadata['stable'] = (bool) $record['stable'];
		} elseif ( ! empty( $record['deterministic'] ) ) {
			$metadata['stable'] = true;
		}
		if ( array_key_exists( 'confidence', $record ) && is_numeric( $record['confidence'] ) ) {
			$metadata['confidence'] = max( 0.0, min( 1.0, (float) $record['confidence'] ) );
		}
		return $metadata;
	}

	private static function read_pointer( array $pointer ) {
		// [2026-09-02 Johnny Chu] PHASE-CB6.3 — follow only the canonical owner reader for the registered Context Bank contract.
		$contract_id = (string) ( $pointer['source_contract_id'] ?? '' );
		if ( $contract_id === 'core.channel_gateway.context_corpus' && class_exists( 'BizCity_Channel_Conversation_Archive' ) ) {
			return BizCity_Channel_Conversation_Archive::read_receipt( $pointer );
		}
		if ( class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return BizCity_Business_JSONL_File_Store::read_receipt( $contract_id, $pointer );
		}
		return array( 'ok' => false, 'reason' => 'kg_pointer_reader_missing' );
	}

	private static function summary_content( array $record ) {
		// [2026-09-02 Johnny Chu] PHASE-CB6.3 — extract only bounded rollup summary/state content for the KG owner.
		$summary = trim( (string) ( $record['summary_text'] ?? $record['summary'] ?? '' ) );
		if ( $summary !== '' ) {
			return substr( $summary, 0, 12000 );
		}
		$state = isset( $record['state'] ) && is_array( $record['state'] ) ? $record['state'] : array();
		return ! empty( $state ) ? (string) wp_json_encode( $state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	}

	private static function enabled() {
		// [2026-09-02 Johnny Chu] PHASE-CB6.3 — read the explicit KG bridge canary flag with a false default.
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}
}