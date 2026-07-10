<?php
/**
 * BizCoach Pro — Astro Relation Manager
 *
 * CRUD for bccm_astro_relations (Ashtakoot compatibility scores between
 * chính chủ (is_self=1) and other subjects).
 *
 * Cache Contract:
 *   group: bcpro
 *   key:   'rel_owner_{owner_coachee}'  → list all relations of an owner
 *   key:   'rel_{id}'                   → single relation row
 *   TTL:   BizCity_Cache::TTL_MEDIUM (30 min)
 *   Flush: flush_group('bcpro') on insert/update/delete
 *
 * @package BizCoach_Pro
 * @since   0.5.0 (PHASE-FAA2-NEXT 2026-07-04)
 */
// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-4 — Relation Manager

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Relation_Manager' ) ) { return; }

class BizCoach_Pro_Relation_Manager {

	const GROUP = 'bcpro';

	// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE.7: canonical relation_type enum
	// Spec: docs/rules/PHASE-0-RULE-COACHEE-IDENTITY.md §7
	const RELATION_TYPES = array(
		'general'          => 'Chung',
		'spouse'           => 'Vợ/Chồng',
		'partner'          => 'Người yêu',
		'family'           => 'Gia đình',
		'colleague'        => 'Đồng nghiệp',
		'employee'         => 'Nhân viên',
		'friend'           => 'Bạn bè',
		'customer'         => 'Khách hàng',
		'business_partner' => 'Đối tác làm ăn',
	);

	// Ashtakoot focus kootams per relation_type (for LLM prompt context)
	const RELATION_ASHTAKOOT_FOCUS = array(
		'general'          => 'Phân tích đều các kootam. Ngưỡng chuẩn Vedic: <18 cần chú ý, 18-27 ổn, ≥28 tốt.',
		'spouse'           => 'Tập trung: Nadi (sức khỏe di truyền), Gana (tính cách), Bhakoot (tài lộc hôn nhân). Ngưỡng tối thiểu kết hôn: 18/36.',
		'partner'          => 'Tập trung: Nadi, Gana, Yoni (tương hợp thể chất), Bhakoot. Ngưỡng tốt: 21/36.',
		'family'           => 'Tập trung: Nadi (máu mủ di truyền), Gana (bản chất), Tara (trường thọ). Lưu ý: Nadi=0 trong gia đình → nguy cơ bệnh di truyền cao.',
		'colleague'        => 'Tập trung: Graha Maitri (tình bạn hành tinh), Vashya (ảnh hưởng qua lại). Ngưỡng ổn: 18/36.',
		'employee'         => 'Tập trung: Graha Maitri (tin tưởng/trung thành), Varna (phân cấp dharmic), Vashya. Thấp <12/36 → rủi ro quản lý.',
		'friend'           => 'Tập trung: Graha Maitri, Gana, Tara. Điểm thấp vẫn có bạn tốt — không cần ngưỡng cao.',
		'customer'         => 'Tập trung: Vashya (khách chịu ảnh hưởng bởi ta), Bhakoot (tài lộc giao dịch), Graha Maitri.',
		'business_partner' => 'Tập trung: Bhakoot (tài lộc chung), Graha Maitri (tin tưởng), Vashya (ai kiểm soát ai). Ngưỡng partnership tốt: 21/36.',
	);

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function tbl() {
		global $wpdb;
		return $wpdb->prefix . 'bccm_astro_relations';
	}

	/* ------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------ */

	/**
	 * List all relations where owner_coachee = $owner.
	 */
	public function get_for_owner( $owner_coachee ) {
		global $wpdb;
		$owner_coachee = (int) $owner_coachee;
		if ( $owner_coachee <= 0 ) { return array(); }

		$cache_key = 'rel_owner_' . $owner_coachee;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::GROUP, $cache_key );
			if ( false !== $cached ) { return $cached; }
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->tbl()} WHERE owner_coachee = %d ORDER BY id ASC",
			$owner_coachee
		), ARRAY_A );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::GROUP, $cache_key, $rows, BizCity_Cache::TTL_MEDIUM );
		}
		return $rows;
	}

	/**
	 * Get single relation by id.
	 */
	public function get( $relation_id ) {
		global $wpdb;
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) { return null; }

		$cache_key = 'rel_' . $relation_id;
		if ( class_exists( 'BizCity_Cache' ) ) {
			$cached = BizCity_Cache::get( self::GROUP, $cache_key );
			if ( false !== $cached ) { return $cached ?: null; }
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->tbl()} WHERE id = %d LIMIT 1",
			$relation_id
		), ARRAY_A );

		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::GROUP, $cache_key, $row ?: 0, BizCity_Cache::TTL_MEDIUM );
		}
		return $row ?: null;
	}

	/* ------------------------------------------------------------------
	 * Write
	 * ------------------------------------------------------------------ */

	/**
	 * Upsert a relation row (insert or update on UNIQUE KEY).
	 * Returns the relation id (existing or new).
	 */
	public function upsert( $owner_coachee, $subject_coachee, $system = 'ashtakoot', array $data = array() ) {
		global $wpdb;
		$owner_coachee   = (int) $owner_coachee;
		$subject_coachee = (int) $subject_coachee;
		if ( $owner_coachee <= 0 || $subject_coachee <= 0 ) { return 0; }
		$system = sanitize_key( $system ) ?: 'ashtakoot';

		$now   = current_time( 'mysql' );
		$uid   = ! empty( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		$rtype = ! empty( $data['relation_type'] ) ? sanitize_key( $data['relation_type'] ) : 'general';
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE.7: whitelist enum validation
		if ( ! array_key_exists( $rtype, self::RELATION_TYPES ) ) {
			$rtype = 'general';
		}

		// Try existing first
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->tbl()} WHERE owner_coachee=%d AND subject_coachee=%d AND system=%s LIMIT 1",
			$owner_coachee, $subject_coachee, $system
		) );

		if ( $existing_id ) {
			$wpdb->update(
				$this->tbl(),
				array( 'relation_type' => $rtype, 'updated_at' => $now ),
				array( 'id' => $existing_id )
			);
			$this->flush_cache( $owner_coachee, $existing_id );
			return $existing_id;
		}

		$wpdb->insert( $this->tbl(), array(
			'user_id'         => $uid,
			'owner_coachee'   => $owner_coachee,
			'subject_coachee' => $subject_coachee,
			'system'          => $system,
			'relation_type'   => $rtype,
			'status'          => 'active',
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( $id ) { $this->flush_cache( $owner_coachee, $id ); }
		return $id;
	}

	/**
	 * Save computed Ashtakoot score into a relation row.
	 */
	public function save_score( $relation_id, array $envelope ) {
		global $wpdb;
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) { return; }

		$total = isset( $envelope['total_score'] ) ? (float) $envelope['total_score'] : null;
		$out   = isset( $envelope['out_of'] ) ? (float) $envelope['out_of'] : 36.0;

		$wpdb->update( $this->tbl(), array(
			'total_score' => $total,
			'out_of'      => $out,
			'score_json'  => wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE ),
			'computed_at' => current_time( 'mysql' ),
			'status'      => 'active',
			'updated_at'  => current_time( 'mysql' ),
		), array( 'id' => $relation_id ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT owner_coachee FROM {$this->tbl()} WHERE id=%d LIMIT 1", $relation_id
		), ARRAY_A );
		$this->flush_cache( $row ? (int) $row['owner_coachee'] : 0, $relation_id );
	}

	/**
	 * Save LLM-generated interpretation into a relation row.
	 */
	public function save_interpretation( $relation_id, array $sections ) {
		global $wpdb;
		$relation_id = (int) $relation_id;
		if ( $relation_id <= 0 ) { return; }

		$payload = array( 'sections' => $sections, 'generated' => gmdate( 'c' ) );
		$wpdb->update( $this->tbl(), array(
			'interpretation' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'updated_at'     => current_time( 'mysql' ),
		), array( 'id' => $relation_id ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT owner_coachee FROM {$this->tbl()} WHERE id=%d LIMIT 1", $relation_id
		), ARRAY_A );
		$this->flush_cache( $row ? (int) $row['owner_coachee'] : 0, $relation_id );
	}

	/* ------------------------------------------------------------------
	 * Cache helpers
	 * ------------------------------------------------------------------ */

	private function flush_cache( $owner_coachee, $relation_id ) {
		if ( ! class_exists( 'BizCity_Cache' ) ) { return; }
		if ( $owner_coachee > 0 ) {
			BizCity_Cache::delete( self::GROUP, 'rel_owner_' . $owner_coachee );
		}
		if ( $relation_id > 0 ) {
			BizCity_Cache::delete( self::GROUP, 'rel_' . $relation_id );
		}
	}
}

// R-CACHE: register cache group
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — extend bcpro catalog with
	// unified TwinBrain subject profile/context cache keys.
	BizCity_Cache_Registry::register( 'bcpro', 'bizcoach-pro.astro-relations', array(
		'rel_owner_{owner_coachee}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'All relations for owner coachee' ),
		'rel_{id}'                  => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Single relation row by id' ),
		'astro_subject_profile_v1_{coachee_id}_{fingerprint}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Unified natal profile markdown for subject' ),
		'astro_subject_context_v1_{coachee_id}_{fingerprint}' => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Unified transit context markdown for subject' ),
	) );
}
