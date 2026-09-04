<?php
/**
 * Project versioned Skill references into Context Bank.
 *
 * Skill content remains owned by core/skills. Context Bank stores only an
 * encrypted metadata record and a verified pointer; it never stores content_md.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Rule_Reference_Adapter {

	const CONTRACT_ID = 'core.skills.rule_reference';
	const FEATURE_FLAG = 'bizcity_context_bank_capture_enabled';
	const RECORD_KIND = 'rule';

	private static $booted = false;

	public static function boot() {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — subscribe once to canonical Skill lifecycle events without creating a second Skill registry.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_skill_saved', array( __CLASS__, 'on_saved' ), 20, 2 );
		add_action( 'bizcity_skill_deleted', array( __CLASS__, 'on_deleted' ), 20, 1 );
	}

	public static function on_saved( $skill_id, $payload = '', $title = '' ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — resolve Skill metadata through the canonical owner before reference admission.
		unset( $title );
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'skill_owner_unavailable' );
		}
		$db = BizCity_Skill_Database::instance();
		$skill = method_exists( $db, 'get_by_id' ) ? $db->get_by_id( (int) $skill_id ) : ( method_exists( $db, 'get' ) ? $db->get( (int) $skill_id ) : null );
		$operation = in_array( (string) $payload, array( 'insert', 'update', 'upsert' ), true ) ? (string) $payload : 'upsert';
		return self::project( $skill, 'upsert', $operation );
	}

	public static function on_deleted( $skill_id, $snapshot = array() ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — emit one deterministic tombstone reference for a deleted Skill version.
		if ( is_array( $skill_id ) ) {
			$snapshot = $skill_id;
			$skill_id = (int) ( $snapshot['id'] ?? 0 );
		}
		return self::project( is_array( $snapshot ) && ! empty( $snapshot ) ? $snapshot : array( 'id' => (int) $skill_id ), 'delete', 'delete' );
	}

	/**
	 * Project one Skill version as metadata only.
	 *
	 * @param object|array|null $skill Canonical Skill row or deleted ID shape.
	 * @param string            $operation upsert or delete.
	 * @param string            $lifecycle_event Source lifecycle operation.
	 * @return array<string,mixed>
	 */
	public static function project( $skill, $operation = 'upsert', $lifecycle_event = '' ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — keep reference capture disabled by default and exclude Skill content from the Context Bank record.
		if ( ! self::capture_enabled() ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'capture_disabled' );
		}
		$skill = is_object( $skill ) ? get_object_vars( $skill ) : ( is_array( $skill ) ? $skill : array() );
		$skill_id = (int) ( $skill['id'] ?? 0 );
		$blog_id = (int) get_current_blog_id();
		if ( $skill_id <= 0 || $blog_id <= 0 || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'skill_reference_dependency_missing' );
		}
		$operation = $operation === 'delete' ? 'delete' : 'upsert';
		$version = sanitize_text_field( (string) ( $skill['version'] ?? '1.0' ) );
		$skill_key = sanitize_text_field( (string) ( $skill['skill_key'] ?? 'skill_' . $skill_id ) );
		$slug = sanitize_title( (string) ( $skill['slug'] ?? '' ) );
		$content_hash = (string) ( $skill['content_hash'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{32,64}$/i', $content_hash ) ) {
			$content_hash = hash( 'sha256', (string) ( $skill['content_md'] ?? $skill['content'] ?? '' ) );
		}
		$version_hash = hash( 'sha256', $skill_id . '|' . $skill_key . '|' . $version . '|' . $content_hash );
		$record_id = $operation === 'delete' ? 'skill_' . $skill_id . '_deleted' : 'skill_' . $skill_id . '_' . substr( $version_hash, 0, 32 );
		$existing = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => $blog_id, 'source_contract_id' => self::CONTRACT_ID, 'record_id' => $record_id, 'limit' => 1 ) );
		if ( ! empty( $existing[0] ) ) {
			return array( 'ok' => true, 'projected' => true, 'replayed' => true, 'record_id' => $record_id );
		}
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — retain Skill character scope as bounded pointer metadata for canonical owner navigation.
		$record = array(
			'record_id' => $record_id,
			'skill_id' => $skill_id,
			'skill_key' => $skill_key,
			'slug' => $slug,
			'version' => $version,
			'version_hash' => $version_hash,
			'content_hash' => $content_hash,
			'status' => sanitize_key( (string) ( $skill['status'] ?? 'deleted' ) ),
			'author_id' => (int) ( $skill['author_id'] ?? 0 ),
			'user_id' => (int) ( $skill['user_id'] ?? 0 ),
			'character_id' => (int) ( $skill['character_id'] ?? 0 ),
			'lifecycle_event' => sanitize_key( (string) $lifecycle_event ),
			'occurred_at' => gmdate( 'c' ),
		);
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, $record, $operation );
		if ( ! is_array( $receipt ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'skill_reference_filestore_write_failed' );
		}
		$admission = BizCity_Context_Bank_Ledger::instance()->record( array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => self::RECORD_KIND,
			'event_uuid' => (string) $receipt['event_uuid'],
			'source_record_id' => $version_hash,
			'user_id' => (int) ( $skill['user_id'] ?? $skill['author_id'] ?? 0 ),
			'entity_type' => 'skill',
			'entity_key' => $skill_key,
			'secondary_type' => 'character',
			'secondary_key' => (string) (int) ( $skill['character_id'] ?? 0 ),
			'scope_key' => 'skill:' . $skill_id . ':u_' . (int) ( $skill['user_id'] ?? 0 ) . ':c_' . (int) ( $skill['character_id'] ?? 0 ),
			'operation' => $operation,
			'lifecycle_status' => $operation === 'delete' ? 'deleted' : 'active',
			'provenance_ref' => 'skill:' . $skill_id . ':' . $version_hash,
			'kg_status' => 'not_candidate',
			'receipt' => $receipt,
		) );
		return ! empty( $admission['ok'] ) ? array( 'ok' => true, 'projected' => true, 'record_id' => $record_id, 'replayed' => ! empty( $admission['replayed'] ) ) : array( 'ok' => false, 'projected' => false, 'reason' => (string) ( $admission['reason'] ?? 'skill_reference_ledger_admission_failed' ) );
	}

	/**
	 * Follow one Skill reference through the authorized canonical Skill owner.
	 *
	 * @param array<string,mixed> $pointer Context Bank pointer metadata.
	 * @return array<string,mixed>
	 */
	public static function follow_skill( array $pointer ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — resolve Skill bodies only through the authorized core/skills owner, never from Context Bank payload.
		if ( (string) ( $pointer['source_contract_id'] ?? '' ) !== self::CONTRACT_ID || (string) ( $pointer['record_kind'] ?? '' ) !== self::RECORD_KIND ) {
			return array( 'ok' => false, 'reason' => 'skill_reference_contract_mismatch' );
		}
		if ( (string) ( $pointer['lifecycle_status'] ?? 'active' ) === 'deleted' || (string) ( $pointer['operation'] ?? 'upsert' ) === 'delete' ) {
			return array( 'ok' => false, 'reason' => 'skill_reference_deleted' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) || ! class_exists( 'BizCity_Context_Bank_Access' ) || ! class_exists( 'BizCity_Skill_Database' ) ) {
			return array( 'ok' => false, 'reason' => 'skill_owner_follow_unavailable' );
		}
		$record_id = sanitize_text_field( (string) ( $pointer['record_id'] ?? '' ) );
		if ( $record_id === '' ) {
			return array( 'ok' => false, 'reason' => 'skill_reference_record_id_missing' );
		}
		$stored_rows = BizCity_Context_Bank_Ledger::instance()->find( array(
			'blog_id' => (int) get_current_blog_id(),
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'limit' => 1,
		) );
		$stored_pointer = isset( $stored_rows[0] ) && is_array( $stored_rows[0] ) ? $stored_rows[0] : array();
		if ( empty( $stored_pointer ) ) {
			return array( 'ok' => false, 'reason' => 'skill_reference_not_found' );
		}
		if ( isset( $pointer['event_uuid'] ) && (string) $pointer['event_uuid'] !== '' && (string) $pointer['event_uuid'] !== (string) ( $stored_pointer['event_uuid'] ?? '' ) ) {
			return array( 'ok' => false, 'reason' => 'skill_reference_pointer_conflict' );
		}
		$authorized = BizCity_Context_Bank_Access::authorize_pointer( $stored_pointer );
		if ( empty( $authorized['ok'] ) ) {
			return $authorized;
		}
		$db = BizCity_Skill_Database::instance();
		$skill_key = sanitize_text_field( (string) ( $stored_pointer['entity_key'] ?? '' ) );
		$skill = null;
		$character_id = (int) ( $stored_pointer['secondary_key'] ?? 0 );
		if ( $skill_key !== '' && method_exists( $db, 'get_by_key' ) ) {
			$skill = $db->get_by_key( $skill_key, (int) ( $stored_pointer['wp_user_id'] ?? 0 ), $character_id );
		}
		$skill_id = (int) ( $stored_pointer['skill_id'] ?? 0 );
		if ( ! $skill && $skill_id > 0 ) {
			$skill = method_exists( $db, 'get' ) ? $db->get( $skill_id ) : ( method_exists( $db, 'get_by_id' ) ? $db->get_by_id( $skill_id ) : null );
		}
		if ( ! $skill ) {
			return array( 'ok' => false, 'reason' => 'skill_owner_record_not_found' );
		}
		$skill = is_object( $skill ) ? get_object_vars( $skill ) : $skill;
		return array( 'ok' => true, 'owner' => 'core/skills', 'record' => $skill, 'pointer' => $stored_pointer, 'authorized_scope' => (string) ( $authorized['scope'] ?? '' ) );
	}

	private static function capture_enabled() {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — use the existing tenant capture flag with a false default.
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}
}