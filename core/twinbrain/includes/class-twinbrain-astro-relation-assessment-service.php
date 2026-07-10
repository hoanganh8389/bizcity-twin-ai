<?php
/**
 * TwinBrain Astro Relation Assessment Service.
 *
 * Shared service for TwinBrain runtime and Automation relation workflows.
 * Responsibilities:
 * - Resolve subject + partner profiles for relation analysis.
 * - Enforce ownership guard for non-admin users.
 * - Ensure transit evidence exists for both profiles in the target window.
 * - Build a unified context payload for relation composer.
 * - Persist source marker evidence into bccm_astro.llm_report.
 *
 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — canonical relation assessment service.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Astro_Relation_Assessment_Service {

	const DEFAULT_SYNC_DAYS    = 7;
	const DEFAULT_START_OFFSET = 1;

	private static $instance = null;

	/**
	 * @return BizCity_TwinBrain_Astro_Relation_Assessment_Service
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve pair from query + options then assess relation context.
	 *
	 * @param string $query
	 * @param array  $opts
	 * @return array
	 */
	public function assess_by_query( $query, array $opts = array() ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — query entrypoint.
		$query = trim( (string) $query );
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );

		$subject_id = (int) ( $opts['subject_coachee_id'] ?? 0 );
		if ( $subject_id <= 0 ) {
			$subject_id = $this->resolve_self_coachee_id( $user_id );
		}

		$partner_id = (int) ( $opts['partner_coachee_id'] ?? 0 );
		$partner_name_hint = trim( (string) ( $opts['partner_name_hint'] ?? '' ) );
		if ( $partner_name_hint === '' ) {
			$partner_name_hint = trim( (string) ( $opts['partner_name'] ?? '' ) );
		}
		if ( $partner_name_hint === '' ) {
			$partner_name_hint = $this->extract_partner_name_hint( $query );
		}

		if ( $partner_id <= 0 && $partner_name_hint !== '' ) {
			$partner_id = $this->find_coachee_id_by_name( $partner_name_hint, $user_id );
		}

		$normalized_lenses = $this->normalize_lenses(
			$opts['relation_lenses'] ?? '',
			$query
		);

		$pair_opts = array_merge( $opts, array(
			'user_id'          => $user_id,
			'query'            => $query,
			'partner_name_hint'=> $partner_name_hint,
			'relation_lenses'  => $normalized_lenses,
		) );

		return $this->assess_by_pair( $subject_id, $partner_id, $pair_opts );
	}

	/**
	 * Assess relation context for a known pair.
	 *
	 * @param int   $subject_coachee_id
	 * @param int   $partner_coachee_id
	 * @param array $opts
	 * @return array
	 */
	public function assess_by_pair( $subject_coachee_id, $partner_coachee_id, array $opts = array() ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — pair entrypoint.
		$subject_coachee_id = (int) $subject_coachee_id;
		$partner_coachee_id = (int) $partner_coachee_id;
		$user_id            = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$query              = (string) ( $opts['query'] ?? '' );
		$source_marker      = $this->normalize_source_marker( (string) ( $opts['source_marker'] ?? '' ) );
		$trace_id           = (string) ( $opts['trace_id'] ?? '' );
		$chat_id            = (string) ( $opts['chat_id'] ?? '' );
		$lenses             = $this->normalize_lenses( $opts['relation_lenses'] ?? '', $query );
		$window             = $this->build_window(
			(int) ( $opts['start_offset'] ?? self::DEFAULT_START_OFFSET ),
			(int) ( $opts['sync_days'] ?? self::DEFAULT_SYNC_DAYS )
		);

		if ( $subject_coachee_id <= 0 ) {
			return $this->fail_result(
				'relation_subject_missing',
				'Khong xac dinh duoc chu the de danh gia moi quan he.',
				$source_marker
			);
		}
		if ( $partner_coachee_id <= 0 ) {
			return $this->fail_result(
				'relation_partner_missing',
				'Khong tim thay ho so doi tac. Vui long chon profile doi tac hoac nhap ten chinh xac.',
				$source_marker
			);
		}
		if ( $subject_coachee_id === $partner_coachee_id ) {
			return $this->fail_result(
				'relation_same_profile',
				'Chu the va doi tac dang trung cung mot profile. Vui long chon doi tac khac.',
				$source_marker
			);
		}

		if ( ! $this->is_admin_user() && $user_id > 0 ) {
			if ( ! $this->coachee_belongs_to_user( $subject_coachee_id, $user_id ) ) {
				return $this->fail_result(
					'relation_subject_forbidden',
					'Khong co quyen truy cap profile chu the nay.',
					$source_marker
				);
			}
			if ( ! $this->coachee_belongs_to_user( $partner_coachee_id, $user_id ) ) {
				return $this->fail_result(
					'relation_partner_forbidden',
					'Khong co quyen truy cap profile doi tac nay.',
					$source_marker
				);
			}
		}

		$subject = $this->resolve_profile_payload(
			$subject_coachee_id,
			$user_id,
			(string) ( $opts['subject_name_hint'] ?? '' ),
			$window
		);
		$partner = $this->resolve_profile_payload(
			$partner_coachee_id,
			$user_id,
			(string) ( $opts['partner_name_hint'] ?? '' ),
			$window
		);

		if ( empty( $subject['coachee_id'] ) || empty( $partner['coachee_id'] ) ) {
			return $this->fail_result(
				'relation_profile_unavailable',
				'Khong tai duoc day du du lieu profile de danh gia relation.',
				$source_marker
			);
		}

		$sync = $this->ensure_transit_for_pair(
			(int) $subject['coachee_id'],
			(int) $partner['coachee_id'],
			$window,
			$source_marker,
			$trace_id,
			$chat_id
		);

		$context_md = $this->build_relation_context_md(
			$subject,
			$partner,
			$sync['subject'] ?? array(),
			$sync['partner'] ?? array(),
			$lenses
		);
		$citations = $this->build_citations( $subject, $partner, $window );

		$marker_payload = array(
			'source_marker' => $source_marker,
			'trace_id' => $trace_id,
			'chat_id'  => $chat_id,
			'subject_coachee_id' => (int) $subject['coachee_id'],
			'partner_coachee_id' => (int) $partner['coachee_id'],
			'range' => array(
				'start' => (string) $window['start'],
				'end'   => (string) $window['end'],
				'days'  => (int) $window['days'],
			),
			'synced_at' => current_time( 'mysql' ),
			'sync_status' => (string) ( $sync['sync_status'] ?? 'failed' ),
		);
		$this->persist_relation_marker( (int) $subject['coachee_id'], $marker_payload );
		$this->persist_relation_marker( (int) $partner['coachee_id'], $marker_payload );

		$degraded = '';
		if ( (string) ( $sync['sync_status'] ?? 'failed' ) === 'partial' ) {
			$degraded = 'relation_transit_partial';
		} elseif ( (string) ( $sync['sync_status'] ?? 'failed' ) === 'failed' ) {
			$degraded = 'relation_transit_failed';
		}

		return array(
			'success' => true,
			'analysis_mode' => 'relation_profile',
			'subject' => $subject,
			'partner' => $partner,
			'window'  => $window,
			'query'   => $query,
			'relation_lenses' => $lenses,
			'relation_context_md' => $context_md,
			'citations' => $citations,
			'source_marker' => $source_marker,
			'sync_status' => (string) ( $sync['sync_status'] ?? 'failed' ),
			'sync' => $sync,
			'_degraded' => $degraded !== '' ? $degraded : null,
			'message' => '',
		);
	}

	/**
	 * @param mixed  $raw_lenses
	 * @param string $query
	 * @return array
	 */
	private function normalize_lenses( $raw_lenses, $query ) {
		$lenses = array();
		if ( is_array( $raw_lenses ) ) {
			$lenses = $raw_lenses;
		} elseif ( is_string( $raw_lenses ) ) {
			$lenses = preg_split( '/[,\s]+/', $raw_lenses );
		}
		$lenses = is_array( $lenses ) ? $lenses : array();
		$normalized = array();
		foreach ( $lenses as $lens ) {
			$l = sanitize_key( (string) $lens );
			if ( $l === 'career' ) { $l = 'work'; }
			if ( in_array( $l, array( 'work', 'love', 'business', 'hr' ), true ) ) {
				$normalized[] = $l;
			}
		}

		if ( empty( $normalized ) ) {
			$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $query ) : strtolower( (string) $query );
			if ( preg_match( '/\b(?:công việc|cong viec|sự nghiệp|su nghiep|career)\b/u', $q ) ) {
				$normalized[] = 'work';
			}
			if ( preg_match( '/\b(?:tình cảm|tinh cam|tình duyên|tinh duyen|yêu|yeu|love)\b/u', $q ) ) {
				$normalized[] = 'love';
			}
			if ( preg_match( '/\b(?:hợp tác làm ăn|hop tac lam an|đầu tư|dau tu|business)\b/u', $q ) ) {
				$normalized[] = 'business';
			}
			if ( preg_match( '/\b(?:nhân sự|nhan su|quản lý đội ngũ|quan ly doi ngu|tuyển dụng|tuyen dung|hr)\b/u', $q ) ) {
				$normalized[] = 'hr';
			}
		}

		if ( empty( $normalized ) ) {
			$normalized = array( 'work', 'love', 'business', 'hr' );
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * @param int $start_offset
	 * @param int $sync_days
	 * @return array
	 */
	private function build_window( $start_offset, $sync_days ) {
		$start_offset = max( 0, (int) $start_offset );
		$sync_days    = max( 1, min( 31, (int) $sync_days ) );

		$today = current_time( 'Y-m-d' );
		$start = date( 'Y-m-d', strtotime( $today . ' +' . $start_offset . ' days' ) );
		$end   = date( 'Y-m-d', strtotime( $start . ' +' . ( $sync_days - 1 ) . ' days' ) );

		return array(
			'start' => $start,
			'end' => $end,
			'days' => $sync_days,
			'start_offset' => $start_offset,
		);
	}

	/**
	 * @param int    $coachee_id
	 * @param int    $user_id
	 * @param string $name_hint
	 * @param array  $window
	 * @return array
	 */
	private function resolve_profile_payload( $coachee_id, $user_id, $name_hint, array $window ) {
		$coachee_id = (int) $coachee_id;
		$user_id    = (int) $user_id;
		$name_hint  = (string) $name_hint;

		if ( $coachee_id <= 0 ) {
			return array();
		}

		$subject_profile = array();
		if ( class_exists( 'BizCity_TwinBrain_Astro_Subject_Profile_Service' ) ) {
			$subject_profile = BizCity_TwinBrain_Astro_Subject_Profile_Service::instance()->resolve_by_coachee(
				$coachee_id,
				$user_id,
				$name_hint
			);
		}

		$coachee_name = '';
		$natal_profile_md = '';
		$transit_context_md = '';
		$natal_url = '';
		$birth = array();
		if ( is_array( $subject_profile ) && ! empty( $subject_profile['coachee_id'] ) ) {
			$coachee_name = (string) ( $subject_profile['coachee_name'] ?? '' );
			$natal_profile_md = (string) ( $subject_profile['natal_profile_md'] ?? '' );
			$transit_context_md = (string) ( $subject_profile['transit_context_md'] ?? '' );
			$natal_url = (string) ( $subject_profile['natal_chart_url'] ?? '' );
			$birth = isset( $subject_profile['birth'] ) && is_array( $subject_profile['birth'] ) ? $subject_profile['birth'] : array();
		}

		if ( $coachee_name === '' || $natal_url === '' ) {
			$row = $this->load_coachee_row( $coachee_id );
			if ( is_array( $row ) ) {
				if ( $coachee_name === '' ) {
					$coachee_name = (string) ( $row['full_name'] ?? $name_hint );
				}
				if ( empty( $birth ) ) {
					$birth = $this->extract_birth( $row );
				}
			}
			if ( $natal_url === '' ) {
				$natal_url = $this->build_natal_url( $coachee_id );
			}
		}

		$transit_url = $this->build_transit_url( $coachee_id, $window );

		return array(
			'coachee_id' => $coachee_id,
			'name' => $coachee_name !== '' ? $coachee_name : ( $name_hint !== '' ? $name_hint : ( 'Profile #' . $coachee_id ) ),
			'natal_url' => $natal_url,
			'transit_url' => $transit_url,
			'natal_profile_md' => $natal_profile_md,
			'transit_context_md' => $transit_context_md,
			'birth' => $birth,
		);
	}

	/**
	 * @param int    $subject_id
	 * @param int    $partner_id
	 * @param array  $window
	 * @param string $source_marker
	 * @param string $trace_id
	 * @param string $chat_id
	 * @return array
	 */
	private function ensure_transit_for_pair( $subject_id, $partner_id, array $window, $source_marker, $trace_id, $chat_id ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — pair transit evidence sync.
		$subject = $this->ensure_transit_for_coachee( (int) $subject_id, $window );
		$partner = $this->ensure_transit_for_coachee( (int) $partner_id, $window );

		$subject_ok = ! empty( $subject['ok'] );
		$partner_ok = ! empty( $partner['ok'] );

		$sync_status = 'failed';
		if ( $subject_ok && $partner_ok ) {
			$sync_status = 'ok';
		} elseif ( $subject_ok || $partner_ok ) {
			$sync_status = 'partial';
		}

		return array(
			'sync_status' => $sync_status,
			'subject' => $subject,
			'partner' => $partner,
			'source_marker' => $source_marker,
			'trace_id' => $trace_id,
			'chat_id' => $chat_id,
		);
	}

	/**
	 * @param int   $coachee_id
	 * @param array $window
	 * @return array
	 */
	private function ensure_transit_for_coachee( $coachee_id, array $window ) {
		$coachee_id = (int) $coachee_id;
		$stats = $this->load_transit_stats( $coachee_id, $window );
		$ok = (int) ( $stats['rows_count'] ?? 0 ) > 0;

		$queued = false;
		if ( ! $ok ) {
			$owner_user_id = $this->resolve_owner_user_id( $coachee_id );
			$queued = $this->queue_transit_rebuild( $coachee_id, $owner_user_id );
		}

		return array(
			'ok' => $ok,
			'rows_count' => (int) ( $stats['rows_count'] ?? 0 ),
			'fetched_at' => (string) ( $stats['fetched_at'] ?? '' ),
			'queued' => $queued,
			'window' => $window,
		);
	}

	/**
	 * @param int   $coachee_id
	 * @param array $window
	 * @return array
	 */
	private function load_transit_stats( $coachee_id, array $window ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return array( 'rows_count' => 0, 'fetched_at' => '' );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_transit_snapshots';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return array( 'rows_count' => 0, 'fetched_at' => '' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS rows_count, MAX(fetched_at) AS fetched_at
			 FROM {$tbl}
			 WHERE coachee_id = %d
			   AND target_date BETWEEN %s AND %s",
			$coachee_id,
			(string) $window['start'],
			(string) $window['end']
		), ARRAY_A );

		return array(
			'rows_count' => (int) ( $row['rows_count'] ?? 0 ),
			'fetched_at' => (string) ( $row['fetched_at'] ?? '' ),
		);
	}

	/**
	 * @param int $coachee_id
	 * @return int
	 */
	private function resolve_owner_user_id( $coachee_id ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$tbl} WHERE id = %d LIMIT 1",
			$coachee_id
		) );
	}

	/**
	 * @param int $coachee_id
	 * @param int $owner_user_id
	 * @return bool
	 */
	private function queue_transit_rebuild( $coachee_id, $owner_user_id ) {
		$coachee_id = (int) $coachee_id;
		$owner_user_id = (int) $owner_user_id;
		if ( $coachee_id <= 0 || $owner_user_id <= 0 ) {
			return false;
		}

		$args = array( $coachee_id, $owner_user_id );
		if ( ! wp_next_scheduled( 'bcpro_async_rebuild_transit', $args ) ) {
			wp_schedule_single_event( time() + 10, 'bcpro_async_rebuild_transit', $args );
		}

		return true;
	}

	/**
	 * @param array $subject
	 * @param array $partner
	 * @param array $subject_sync
	 * @param array $partner_sync
	 * @param array $lenses
	 * @return string
	 */
	private function build_relation_context_md( array $subject, array $partner, array $subject_sync, array $partner_sync, array $lenses ) {
		$lines = array();
		$lines[] = '## RELATION CONTEXT';
		$lines[] = '### Subject';
		$lines[] = '- id: ' . (int) ( $subject['coachee_id'] ?? 0 );
		$lines[] = '- name: ' . (string) ( $subject['name'] ?? '' );
		if ( ! empty( $subject['natal_url'] ) ) {
			$lines[] = '- natal_url: ' . (string) $subject['natal_url'];
		}
		if ( ! empty( $subject['transit_url'] ) ) {
			$lines[] = '- transit_url: ' . (string) $subject['transit_url'];
		}
		if ( ! empty( $subject['natal_profile_md'] ) ) {
			$lines[] = "\n" . trim( (string) $subject['natal_profile_md'] );
		}
		if ( ! empty( $subject['transit_context_md'] ) ) {
			$lines[] = "\n" . trim( (string) $subject['transit_context_md'] );
		}

		$lines[] = '';
		$lines[] = '### Partner';
		$lines[] = '- id: ' . (int) ( $partner['coachee_id'] ?? 0 );
		$lines[] = '- name: ' . (string) ( $partner['name'] ?? '' );
		if ( ! empty( $partner['natal_url'] ) ) {
			$lines[] = '- natal_url: ' . (string) $partner['natal_url'];
		}
		if ( ! empty( $partner['transit_url'] ) ) {
			$lines[] = '- transit_url: ' . (string) $partner['transit_url'];
		}
		if ( ! empty( $partner['natal_profile_md'] ) ) {
			$lines[] = "\n" . trim( (string) $partner['natal_profile_md'] );
		}
		if ( ! empty( $partner['transit_context_md'] ) ) {
			$lines[] = "\n" . trim( (string) $partner['transit_context_md'] );
		}

		$lines[] = '';
		$lines[] = '### Relation Lenses';
		$lines[] = '- ' . implode( ', ', $lenses );

		$lines[] = '';
		$lines[] = '### Transit Sync Evidence';
		$lines[] = '- subject_rows: ' . (int) ( $subject_sync['rows_count'] ?? 0 ) . ' | fetched_at: ' . (string) ( $subject_sync['fetched_at'] ?? '' );
		$lines[] = '- partner_rows: ' . (int) ( $partner_sync['rows_count'] ?? 0 ) . ' | fetched_at: ' . (string) ( $partner_sync['fetched_at'] ?? '' );
		$lines[] = '- queued_subject: ' . ( ! empty( $subject_sync['queued'] ) ? '1' : '0' );
		$lines[] = '- queued_partner: ' . ( ! empty( $partner_sync['queued'] ) ? '1' : '0' );

		return implode( "\n", $lines );
	}

	/**
	 * @param array $subject
	 * @param array $partner
	 * @param array $window
	 * @return array
	 */
	private function build_citations( array $subject, array $partner, array $window ) {
		$subject_id = (int) ( $subject['coachee_id'] ?? 0 );
		$partner_id = (int) ( $partner['coachee_id'] ?? 0 );
		$range      = (string) $window['start'] . '..' . (string) $window['end'];
		return array(
			'[astro:natal#' . (string) ( $subject['natal_url'] ?? '' ) . ']',
			'[astro:natal#' . (string) ( $partner['natal_url'] ?? '' ) . ']',
			'[astro:transit-range#' . $subject_id . '/' . $range . ']',
			'[astro:transit-range#' . $partner_id . '/' . $range . ']',
		);
	}

	/**
	 * @param int   $coachee_id
	 * @param array $marker_payload
	 * @return void
	 */
	private function persist_relation_marker( $coachee_id, array $marker_payload ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — persist source marker in llm_report JSON.
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return;
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_astro';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, llm_report FROM {$tbl} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return;
		}

		$decoded = array();
		if ( ! empty( $row['llm_report'] ) ) {
			$tmp = json_decode( (string) $row['llm_report'], true );
			if ( is_array( $tmp ) ) {
				$decoded = $tmp;
			}
		}
		$decoded['relation_sync'] = $marker_payload;

		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: json_encode( $decoded );
		if ( ! is_string( $json ) || $json === '' ) {
			return;
		}

		$wpdb->update(
			$tbl,
			array( 'llm_report' => $json ),
			array( 'id' => (int) $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @param string $raw
	 * @return string
	 */
	private function normalize_source_marker( $raw ) {
		$raw = sanitize_key( trim( (string) $raw ) );
		if ( in_array( $raw, array( 'twinbrain_chat', 'zalobot_chat', 'automation' ), true ) ) {
			return $raw;
		}
		return 'twinbrain_chat';
	}

	/**
	 * @param int $user_id
	 * @return int
	 */
	private function resolve_self_coachee_id( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}

		if ( function_exists( 'bccm_get_self_coachee' ) ) {
			$row = bccm_get_self_coachee( $user_id );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return (int) $row['id'];
			}
		}
		if ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			$row = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/**
	 * @param int $coachee_id
	 * @return array|null
	 */
	private function load_coachee_row( $coachee_id ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return null;
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE id = %d LIMIT 1",
			$coachee_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array $row
	 * @return array
	 */
	private function extract_birth( array $row ) {
		$birth = array();
		$map = array(
			'dob' => 'date',
			'birth_date' => 'date',
			'birth_time' => 'time',
			'birth_place' => 'place',
			'birth_tz' => 'tz',
		);
		foreach ( $map as $col => $key ) {
			if ( ! empty( $row[ $col ] ) && empty( $birth[ $key ] ) ) {
				$birth[ $key ] = (string) $row[ $col ];
			}
		}
		return $birth;
	}

	/**
	 * @param int $coachee_id
	 * @return string
	 */
	private function build_natal_url( $coachee_id ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'bcpro_get_astro_public_url' ) ) {
			return (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
		}
		if ( function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			return (string) bccm_get_natal_chart_public_url( $coachee_id );
		}
		return home_url( '/my-natal-chart/?id=' . $coachee_id );
	}

	/**
	 * @param int   $coachee_id
	 * @param array $window
	 * @return string
	 */
	private function build_transit_url( $coachee_id, array $window ) {
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
			$week = (string) bcpro_get_transit_public_url( $coachee_id, 'week' );
			if ( $week !== '' ) {
				return $week;
			}
		}
		return home_url(
			'/my-transit/?coachee_id=' . $coachee_id
			. '&start=' . rawurlencode( (string) $window['start'] )
			. '&end=' . rawurlencode( (string) $window['end'] )
		);
	}

	/**
	 * @param string $query
	 * @return string
	 */
	private function extract_partner_name_hint( $query ) {
		$query = trim( (string) $query );
		if ( $query === '' ) {
			return '';
		}
		if ( preg_match( '/(?:với|voi|của|cua|về|ve)\s+([\p{L}][\p{L}\s\.]{1,50})/u', $query, $m ) ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string) $m[1] ) );
			if ( function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $name ) >= 2 && mb_strlen( $name ) <= 50 ) {
					return $name;
				}
			} elseif ( strlen( $name ) >= 2 && strlen( $name ) <= 100 ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * @param string $name_hint
	 * @param int    $owner_user_id
	 * @return int
	 */
	private function find_coachee_id_by_name( $name_hint, $owner_user_id ) {
		$name_hint = trim( (string) $name_hint );
		if ( $name_hint === '' ) {
			return 0;
		}
		if ( ! function_exists( 'bccm_tables' ) ) {
			return 0;
		}

		global $wpdb;
		$t = bccm_tables();
		if ( empty( $t['profiles'] ) ) {
			return 0;
		}
		$like = '%' . $wpdb->esc_like( $name_hint ) . '%';

		if ( $this->is_admin_user() ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t['profiles']} WHERE full_name LIKE %s ORDER BY updated_at DESC LIMIT 1",
				$like
			) );
		}

		$owner_user_id = (int) $owner_user_id;
		if ( $owner_user_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t['profiles']} WHERE user_id = %d AND full_name LIKE %s ORDER BY updated_at DESC LIMIT 1",
			$owner_user_id,
			$like
		) );
	}

	/**
	 * @param int $coachee_id
	 * @param int $user_id
	 * @return bool
	 */
	private function coachee_belongs_to_user( $coachee_id, $user_id ) {
		$coachee_id = (int) $coachee_id;
		$user_id = (int) $user_id;
		if ( $coachee_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		if ( function_exists( '_bizcity_legacy_tbl_exists' ) && ! _bizcity_legacy_tbl_exists( $tbl ) ) {
			return true;
		}
		$ok = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$tbl} WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id,
			$user_id
		) );
		return $ok === 1;
	}

	/**
	 * @return bool
	 */
	private function is_admin_user() {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @param string $source_marker
	 * @return array
	 */
	private function fail_result( $code, $message, $source_marker ) {
		return array(
			'success' => false,
			'analysis_mode' => 'relation_profile',
			'subject' => array(),
			'partner' => array(),
			'window' => array(),
			'query' => '',
			'relation_lenses' => array( 'work', 'love', 'business', 'hr' ),
			'relation_context_md' => '',
			'citations' => array(),
			'source_marker' => (string) $source_marker,
			'sync_status' => 'failed',
			'sync' => array(),
			'_degraded' => (string) $code,
			'message' => (string) $message,
		);
	}
}
