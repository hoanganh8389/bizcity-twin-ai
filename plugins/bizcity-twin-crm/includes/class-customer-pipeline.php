<?php
/**
 * BizCity CRM — customer pipeline read model (PHASE-0.52, R-PIPE-1/2/8).
 *
 * The pipeline counts CUSTOMERS, not deals. Each contact's stage is resolved on read:
 *   - facts the system already knows push it forward (outgoing message ⇒ contacted, paid Woo order ⇒ won,
 *     ≥ 2 paid orders ⇒ repeat, silence after buying ⇒ dormant flag);
 *   - staff may move a customer by hand to any stage (incl. "won" without a Woo order); that choice lives on
 *     the contact's single pipeline opportunity (`custom_json.pipeline = true`), written only by
 *     {@see BizCity_CRM_Pipeline_Stage_Service};
 *   - a fact NEWER than the last manual move only pushes the customer UP, never down (R-PIPE-2).
 * Legacy (Chatwoot-port) opportunities without the flag are still read and mapped (R-PIPE-4).
 *
 * No table of its own: contacts, contact_inboxes, conversations, messages, opportunities, crm_tasks, Woo
 * orders (`_bizcity_crm_contact_id`), audit log, one tenant option and one user meta.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.52 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Customer_Pipeline', false ) ) {
	return;
}

final class BizCity_CRM_Customer_Pipeline {

	const OPTION        = 'bizcity_crm_pipeline_settings';
	const GOAL_META     = 'bizcity_crm_monthly_goal';
	const CONTACT_CAP   = 5000;
	const CACHE_TTL     = 60;
	const DORMANT_DAYS  = 30;

	/** Ordered forward stages (rank = index). `dormant` is a flag over won/repeat, `lost` sits aside. */
	const STAGES      = array( 'target', 'contacted', 'consult', 'quote', 'won', 'repeat' );
	const OPEN_STAGES = array( 'target', 'contacted', 'consult', 'quote' );
	const ALL_STAGES  = array( 'target', 'contacted', 'consult', 'quote', 'won', 'repeat', 'dormant', 'lost' );
	const LABELS      = array(
		'target' => 'Khách mục tiêu', 'contacted' => 'Đã liên hệ', 'consult' => 'Đang tư vấn', 'quote' => 'Đã báo giá',
		'won' => 'Đã mua', 'repeat' => 'Mua lại', 'dormant' => 'Nguội', 'lost' => 'Không mua',
	);

	/** opportunities.stage ⇄ pipeline vocabulary (R-PIPE §2). */
	const OPP_STAGE_OUT = array(
		'target' => 'prospecting', 'contacted' => 'prospecting', 'consult' => 'qualification', 'quote' => 'proposal',
		'won' => 'closed_won', 'repeat' => 'closed_won', 'lost' => 'closed_lost',
	);
	const OPP_STAGE_IN = array(
		'prospecting' => 'contacted', 'qualification' => 'consult', 'proposal' => 'quote', 'negotiation' => 'quote',
		'closed_won' => 'won', 'closed_lost' => 'lost',
	);

	// ── Settings (R-PIPE-8) ──────────────────────────────────────────────

	public static function default_settings(): array {
		return array(
			'stuck_days'       => array( 'target' => 3, 'contacted' => 3, 'consult' => 3, 'quote' => 3 ),
			'dormant_days'     => self::DORMANT_DAYS,
			'lost_return_days' => 90,
			'lost_reasons'     => array( 'Giá cao', 'Đã mua nơi khác', 'Chưa có nhu cầu', 'Sai đối tượng', 'Khác' ),
			'steps'            => array(
				'target'    => array( 'Chào khách, giới thiệu mình', 'Hỏi nhu cầu và thời điểm cần' ),
				'contacted' => array( 'Xác định nhu cầu chính', 'Hẹn thời điểm tư vấn / gọi' ),
				'consult'   => array( 'Gửi thông tin / catalog đúng nhu cầu', 'Trả lời thắc mắc', 'Hỏi ngân sách, người quyết định' ),
				'quote'     => array( 'Gửi báo giá', 'Gọi lại chốt trong 48h', 'Xử lý băn khoăn (giá, giao hàng)' ),
				'won'       => array( 'Xác nhận đơn / thanh toán', 'Theo dõi giao hàng', 'Hỏi trải nghiệm D+3, xin feedback' ),
				'repeat'    => array( 'Mời mua lại theo chu kỳ', 'Xin giới thiệu khách mới' ),
				'dormant'   => array( 'Gửi ưu đãi quay lại', 'Hỏi nhu cầu mới' ),
				'lost'      => array( 'Ghi lý do', 'Hẹn liên hệ lại sau 90 ngày' ),
			),
			// [PHASE-0.54 R-INBOX-PIPE-11, D54-5] gợi ý giai đoạn từ nội dung tin nhắn — chỉ HIỆN gợi ý
			// (💡 dismissible), không bao giờ tự đổi giai đoạn. Từ khoá thuần chuỗi con, không regex; MVP
			// trước khi có phân loại LLM Router. `to` phải nằm sau `from` trong STAGES (chỉ gợi ý tiến lên).
			'suggestions_enabled' => true,
			'suggestion_rules' => array(
				array( 'from' => 'outgoing', 'to' => 'quote', 'keywords' => array( 'báo giá', 'bao gia' ) ),
				array( 'from' => 'incoming', 'to' => 'won', 'keywords' => array( 'đã chuyển khoản', 'da chuyen khoan', 'chuyển khoản rồi', 'đã ck', 'da ck', 'e ck rồi', 'em ck rồi' ) ),
			),
		);
	}

	public static function settings(): array {
		$stored = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		return self::sanitize_settings( is_array( $stored ) ? $stored : array() );
	}

	/** Merge stored/posted values over defaults; every field bounded. */
	public static function sanitize_settings( array $in ): array {
		$out = self::default_settings();
		foreach ( self::OPEN_STAGES as $stage ) {
			if ( isset( $in['stuck_days'][ $stage ] ) ) { $out['stuck_days'][ $stage ] = max( 0, min( 365, (int) $in['stuck_days'][ $stage ] ) ); }
		}
		if ( isset( $in['dormant_days'] ) ) { $out['dormant_days'] = max( 7, min( 365, (int) $in['dormant_days'] ) ); }
		if ( isset( $in['lost_return_days'] ) ) { $out['lost_return_days'] = max( 0, min( 730, (int) $in['lost_return_days'] ) ); }
		if ( isset( $in['lost_reasons'] ) && is_array( $in['lost_reasons'] ) ) {
			$reasons = array();
			foreach ( $in['lost_reasons'] as $reason ) {
				$reason = trim( self::clean_text( (string) $reason, 80 ) );
				if ( '' !== $reason && ! in_array( $reason, $reasons, true ) ) { $reasons[] = $reason; }
			}
			if ( $reasons ) { $out['lost_reasons'] = array_slice( $reasons, 0, 12 ); }
		}
		if ( isset( $in['steps'] ) && is_array( $in['steps'] ) ) {
			foreach ( self::ALL_STAGES as $stage ) {
				if ( ! isset( $in['steps'][ $stage ] ) || ! is_array( $in['steps'][ $stage ] ) ) { continue; }
				$steps = array();
				foreach ( $in['steps'][ $stage ] as $step ) {
					$step = trim( self::clean_text( (string) $step, 120 ) );
					if ( '' !== $step ) { $steps[] = $step; }
				}
				$out['steps'][ $stage ] = array_slice( $steps, 0, 6 );
			}
		}
		if ( isset( $in['suggestions_enabled'] ) ) { $out['suggestions_enabled'] = (bool) $in['suggestions_enabled']; }
		if ( isset( $in['suggestion_rules'] ) && is_array( $in['suggestion_rules'] ) ) {
			$rules = array();
			foreach ( $in['suggestion_rules'] as $rule ) {
				if ( ! is_array( $rule ) ) { continue; }
				$from = in_array( (string) ( $rule['from'] ?? '' ), array( 'outgoing', 'incoming' ), true ) ? (string) $rule['from'] : '';
				$to   = in_array( (string) ( $rule['to'] ?? '' ), self::STAGES, true ) ? (string) $rule['to'] : '';
				if ( '' === $from || '' === $to ) { continue; }
				$keywords = array();
				foreach ( (array) ( $rule['keywords'] ?? array() ) as $kw ) {
					$kw = trim( mb_strtolower( self::clean_text( (string) $kw, 60 ) ) );
					if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) { $keywords[] = $kw; }
				}
				if ( empty( $keywords ) ) { continue; }
				$rules[] = array( 'from' => $from, 'to' => $to, 'keywords' => array_slice( $keywords, 0, 20 ) );
				if ( count( $rules ) >= 10 ) { break; }
			}
			if ( $rules ) { $out['suggestion_rules'] = $rules; }
		}
		return $out;
	}

	// [PHASE-0.54 R-INBOX-PIPE-11] The keyword match itself (`suggestion_rules` above → a stage hint) runs
	// client-side, once per conversation over messages already loaded — see `suggestStage.js`/`.ts`. This
	// class only owns the tenant-configured rules (settings + `detail()` below); no server-side matching
	// function here on purpose, to avoid a second implementation of the same rule that can drift.

	// ── Pure resolution (unit-tested) ────────────────────────────────────

	public static function rank( string $stage ): int {
		$i = array_search( $stage, self::STAGES, true );
		return false === $i ? -1 : (int) $i;
	}

	/**
	 * Resolve one customer's effective stage.
	 *
	 * @param array      $facts {created_ts, first_out_ts, last_out_ts, last_activity_ts, paid, first_paid_ts, second_paid_ts, ordered, first_order_ts, last_order_ts}
	 * @param array|null $pipe  {stage, at_ts, source} from the pipeline opportunity (or a mapped legacy one)
	 * @return array{stage:string, base_stage:string, since_ts:int, source:string, dormant:bool, manual_won_pending:bool, payment_pending:bool, days:int, stuck:bool}
	 */
	public static function resolve( array $facts, ?array $pipe, int $now, array $settings ): array {
		$paid = (int) ( $facts['paid'] ?? 0 );
		$ordered = (int) ( $facts['ordered'] ?? 0 );
		if ( $paid >= 2 ) {
			$fact = 'repeat'; $fact_ts = (int) ( $facts['second_paid_ts'] ?? 0 );
		} elseif ( $paid >= 1 ) {
			$fact = 'won'; $fact_ts = (int) ( $facts['first_paid_ts'] ?? 0 );
		} elseif ( $ordered >= 1 ) {
			// [PHASE-0.54 R-PIPE-2 v1.1, D54-2] a created, not-cancelled order pushes the customer up to "won"
			// even before it is paid — the bar shows "Đã mua · chờ thanh toán"; revenue/won KPIs still gate on `paid`.
			$fact = 'won'; $fact_ts = (int) ( $facts['first_order_ts'] ?? 0 );
		} elseif ( (int) ( $facts['first_out_ts'] ?? 0 ) > 0 ) {
			$fact = 'contacted'; $fact_ts = (int) $facts['first_out_ts'];
		} else {
			$fact = 'target'; $fact_ts = (int) ( $facts['created_ts'] ?? 0 );
		}

		$stage = $fact; $since = $fact_ts; $source = 'auto';
		if ( is_array( $pipe ) && in_array( (string) ( $pipe['stage'] ?? '' ), self::ALL_STAGES, true ) ) {
			$p_stage = (string) $pipe['stage'];
			$p_ts    = (int) ( $pipe['at_ts'] ?? 0 );
			$p_src   = (string) ( $pipe['source'] ?? 'manual' );
			if ( 'lost' === $p_stage ) {
				$return_days = (int) ( $settings['lost_return_days'] ?? 90 );
				if ( self::rank( $fact ) >= self::rank( 'won' ) && $fact_ts > $p_ts ) {
					// Bought after being marked lost: the order wins.
				} elseif ( $return_days > 0 && $p_ts > 0 && $now - $p_ts > $return_days * DAY_IN_SECONDS ) {
					$stage = 'target'; $since = $p_ts + $return_days * DAY_IN_SECONDS; $source = 'auto';
				} else {
					$stage = 'lost'; $since = $p_ts; $source = $p_src;
				}
			} elseif ( 'dormant' === $p_stage ) {
				// Not a manual stage — ignore, facts decide.
			} elseif ( self::rank( $fact ) > self::rank( $p_stage ) && $fact_ts > $p_ts ) {
				// A newer fact only pushes up (R-PIPE-2).
			} else {
				$stage = $p_stage; $since = $p_ts; $source = $p_src;
			}
		}

		$base = $stage;
		$dormant = false;
		if ( in_array( $stage, array( 'won', 'repeat' ), true ) ) {
			$last = max( (int) ( $facts['last_activity_ts'] ?? 0 ), (int) ( $facts['last_order_ts'] ?? 0 ), (int) ( $facts['last_out_ts'] ?? 0 ), $since );
			$dormant_days = (int) ( $settings['dormant_days'] ?? self::DORMANT_DAYS );
			if ( $last > 0 && $now - $last > $dormant_days * DAY_IN_SECONDS ) {
				$dormant = true; $stage = 'dormant'; $since = $last;
			}
		}
		$days = $since > 0 ? max( 0, (int) floor( ( $now - $since ) / DAY_IN_SECONDS ) ) : 0;
		$limit = (int) ( $settings['stuck_days'][ $stage ] ?? 0 );
		$won_like = in_array( $base, array( 'won', 'repeat' ), true );
		return array(
			'stage'              => $stage,
			'base_stage'         => $base,
			'since_ts'           => $since,
			'source'             => $source,
			'dormant'            => $dormant,
			// "chốt tay" — staff moved to won/repeat by hand, no order at all yet (§3.3 "chưa có đơn").
			'manual_won_pending' => $won_like && $paid === 0 && $ordered === 0,
			// [PHASE-0.54 D54-2] a real (not-cancelled) order exists but hasn't been paid — "chờ thanh toán".
			'payment_pending'    => $won_like && $paid === 0 && $ordered > 0,
			'days'               => $days,
			'stuck'              => in_array( $stage, self::OPEN_STAGES, true ) && $limit > 0 && $days >= $limit,
		);
	}

	/** Pipeline state stored on (or mapped from) an opportunity row. */
	public static function pipe_from_opportunity( ?array $opp ): ?array {
		if ( ! $opp ) { return null; }
		$custom = json_decode( (string) ( $opp['custom_json'] ?? '' ), true );
		$custom = is_array( $custom ) ? $custom : array();
		if ( ! empty( $custom['pipeline'] ) && in_array( (string) ( $custom['pipeline_stage'] ?? '' ), self::ALL_STAGES, true ) ) {
			return array(
				'stage'  => (string) $custom['pipeline_stage'],
				'at_ts'  => (int) ( $custom['stage_at'] ?? strtotime( (string) ( $opp['updated_at'] ?? '' ) ) ),
				'source' => in_array( (string) ( $custom['source'] ?? '' ), array( 'manual', 'outcome' ), true ) ? (string) $custom['source'] : 'manual',
				'steps'  => is_array( $custom['steps'] ?? null ) ? $custom['steps'] : array(),
				'opportunity_id' => (int) ( $opp['id'] ?? 0 ),
				'legacy' => false,
			);
		}
		// `BizCity_CRM_Pipeline_Sync` auto-creates prospecting/qualification opportunities from "has a phone"
		// (source + source_ref set). That is not a human judgement — facts decide those customers.
		$legacy_stage = (string) ( $opp['stage'] ?? '' );
		if ( '' !== (string) ( $opp['source'] ?? '' ) && in_array( $legacy_stage, array( 'prospecting', 'qualification' ), true ) && 'lost' !== (string) ( $opp['status'] ?? '' ) ) {
			return null;
		}
		$mapped = self::OPP_STAGE_IN[ $legacy_stage ] ?? '';
		if ( 'lost' !== $mapped && 'lost' === (string) ( $opp['status'] ?? '' ) ) { $mapped = 'lost'; }
		if ( '' === $mapped ) { return null; }
		return array(
			'stage' => $mapped, 'at_ts' => (int) strtotime( (string) ( $opp['updated_at'] ?? '' ) ), 'source' => 'manual',
			'steps' => array(), 'opportunity_id' => (int) ( $opp['id'] ?? 0 ), 'legacy' => true,
		);
	}

	// ── Scope ────────────────────────────────────────────────────────────

	/**
	 * Contact ids the actor may see, newest activity first (capped).
	 *
	 * @param int[]|null $inbox_ids null = tenant-wide (admin B2 only).
	 * @return int[]
	 */
	public static function contact_ids_for_inboxes( ?array $inbox_ids, int $cap = self::CONTACT_CAP ): array {
		global $wpdb;
		$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ct_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$cap = max( 1, min( self::CONTACT_CAP, $cap ) );
		if ( is_array( $inbox_ids ) ) {
			$inbox_ids = array_values( array_filter( array_map( 'intval', $inbox_ids ) ) );
			if ( empty( $inbox_ids ) ) { return array(); }
			$ph = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
			$sql = $wpdb->prepare(
				"SELECT ci.contact_id, MAX(c.last_activity_at) AS last_at FROM `{$ci_t}` ci
				 INNER JOIN `{$ct_t}` ct ON ct.id = ci.contact_id AND ct.deleted_at IS NULL
				 LEFT JOIN `{$conv_t}` c ON c.contact_inbox_id = ci.id
				 WHERE ci.inbox_id IN ({$ph}) GROUP BY ci.contact_id ORDER BY last_at DESC LIMIT {$cap}",
				$inbox_ids
			);
		} else {
			$sql = "SELECT ct.id AS contact_id FROM `{$ct_t}` ct WHERE ct.deleted_at IS NULL ORDER BY ct.updated_at DESC LIMIT {$cap}";
		}
		return array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ) );
	}

	/** B2 actor scope: inbox ids (null = admin tenant-wide). */
	public static function b2_inbox_ids( int $actor_id ): ?array {
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return array(); }
		$allowed = BizCity_CRM_Inbox_Access::allowed_inbox_ids( $actor_id );
		return null === $allowed ? null : array_values( array_map( 'intval', (array) $allowed ) );
	}

	/** C member scope: only the member's own inboxes (R-TWEB, R-PIPE-7). */
	public static function c_inbox_ids( int $member_id ): array {
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) ) { return array(); }
		$scope = BizCity_CRM_Inbox_Access::resolve_scope( $member_id, 'c' );
		return array_values( array_map( 'intval', (array) ( $scope['inbox_ids'] ?? array() ) ) );
	}

	public static function contact_in_scope( int $contact_id, ?array $inbox_ids ): bool {
		if ( $contact_id <= 0 ) { return false; }
		if ( null === $inbox_ids ) { return true; }
		if ( empty( $inbox_ids ) ) { return false; }
		global $wpdb;
		$ci_t = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ph = implode( ',', array_fill( 0, count( $inbox_ids ), '%d' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$ci_t}` WHERE contact_id = %d AND inbox_id IN ({$ph}) LIMIT 1", array_merge( array( $contact_id ), array_map( 'intval', $inbox_ids ) ) ) );
	}

	// ── Facts loader ─────────────────────────────────────────────────────

	/**
	 * @param int[] $ids
	 * @return array<int,array> contact_id => {name, source, created_ts, first_out_ts, last_out_ts, last_activity_ts, paid, first_paid_ts, second_paid_ts, last_order_ts, revenue, owner_id, open_tasks, next_due, overdue_tasks, pipe}
	 */
	public static function load( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		if ( empty( $ids ) ) { return $out; }
		$ct_t   = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci_t   = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$conv_t = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$msg_t  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$opp_t  = BizCity_CRM_DB_Installer_V2::tbl_crm_opportunities();
		$task_t = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$today  = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, first_name, last_name, acquisition_source, created_at FROM `{$ct_t}` WHERE id IN ({$ph})", $chunk ), ARRAY_A ) as $r ) {
				$name = trim( (string) ( $r['name'] ?? '' ) );
				if ( '' === $name ) { $name = trim( (string) $r['first_name'] . ' ' . (string) $r['last_name'] ); }
				$out[ (int) $r['id'] ] = array(
					'name' => '' !== $name ? $name : 'Khách #' . (int) $r['id'],
					'source' => strtolower( (string) strtok( (string) $r['acquisition_source'], ':' ) ?: '' ),
					'created_ts' => (int) strtotime( (string) $r['created_at'] ),
					'first_out_ts' => 0, 'last_out_ts' => 0, 'last_activity_ts' => 0,
					'paid' => 0, 'first_paid_ts' => 0, 'second_paid_ts' => 0,
					'ordered' => 0, 'first_order_ts' => 0, 'last_order_ts' => 0, 'revenue' => 0.0,
					'owner_id' => 0, 'open_tasks' => 0, 'next_due' => null, 'overdue_tasks' => 0, 'pipe' => null,
					'conversation_id' => 0, 'inbox_id' => 0,
				);
			}
			// Touches: outgoing messages (human or bot) per contact.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT ci.contact_id, MIN(m.created_at) AS first_out, MAX(m.created_at) AS last_out FROM `{$msg_t}` m
				 INNER JOIN `{$conv_t}` c ON c.id = m.conversation_id INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
				 WHERE m.message_type = 'outgoing' AND ci.contact_id IN ({$ph}) GROUP BY ci.contact_id", $chunk ), ARRAY_A ) as $r ) {
				$cid = (int) $r['contact_id'];
				if ( isset( $out[ $cid ] ) ) { $out[ $cid ]['first_out_ts'] = (int) strtotime( (string) $r['first_out'] ); $out[ $cid ]['last_out_ts'] = (int) strtotime( (string) $r['last_out'] ); }
			}
			// Latest conversation (owner = its assignee) + last activity.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT ci.contact_id, c.id, c.inbox_id, c.assignee_id, c.last_activity_at FROM `{$conv_t}` c INNER JOIN `{$ci_t}` ci ON ci.id = c.contact_inbox_id
				 WHERE ci.contact_id IN ({$ph}) ORDER BY c.last_activity_at DESC", $chunk ), ARRAY_A ) as $r ) {
				$cid = (int) $r['contact_id'];
				if ( ! isset( $out[ $cid ] ) ) { continue; }
				$ts = (int) strtotime( (string) $r['last_activity_at'] );
				if ( $ts > $out[ $cid ]['last_activity_ts'] ) { $out[ $cid ]['last_activity_ts'] = $ts; }
				if ( 0 === $out[ $cid ]['conversation_id'] ) { $out[ $cid ]['conversation_id'] = (int) $r['id']; $out[ $cid ]['inbox_id'] = (int) $r['inbox_id']; }
				if ( 0 === $out[ $cid ]['owner_id'] && (int) $r['assignee_id'] > 0 ) { $out[ $cid ]['owner_id'] = (int) $r['assignee_id']; }
			}
			// Pipeline opportunity (flagged first, else most recent legacy one).
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$opp_t}` WHERE deleted_at IS NULL AND contact_id IN ({$ph}) ORDER BY updated_at DESC, id DESC", $chunk ), ARRAY_A ) as $r ) {
				$cid = (int) $r['contact_id'];
				if ( ! isset( $out[ $cid ] ) ) { continue; }
				$pipe = self::pipe_from_opportunity( $r );
				if ( ! $pipe ) { continue; }
				if ( null === $out[ $cid ]['pipe'] || ( ! empty( $out[ $cid ]['pipe']['legacy'] ) && empty( $pipe['legacy'] ) ) ) { $out[ $cid ]['pipe'] = $pipe; }
			}
			// Next step = any open task/reminder on the contact.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT related_entity_id AS cid, COUNT(*) AS n, MIN(due_date) AS next_due, SUM(CASE WHEN due_date IS NOT NULL AND due_date < %s THEN 1 ELSE 0 END) AS overdue
				 FROM `{$task_t}` WHERE deleted_at IS NULL AND completed = 0 AND related_entity_type = 'contact' AND status NOT IN ('done','cancelled','returned')
				 AND related_entity_id IN ({$ph}) GROUP BY related_entity_id", array_merge( array( $today ), $chunk ) ), ARRAY_A ) as $r ) {
				$cid = (int) $r['cid'];
				if ( isset( $out[ $cid ] ) ) { $out[ $cid ]['open_tasks'] = (int) $r['n']; $out[ $cid ]['next_due'] = $r['next_due'] ?: null; $out[ $cid ]['overdue_tasks'] = (int) $r['overdue']; }
			}
		}

		// Paid Woo orders linked to the contact (auto-attach, R-PIPE-2 / Q52-6).
		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				$orders = wc_get_orders( array(
					'limit' => 2000, 'return' => 'objects', 'orderby' => 'date', 'order' => 'ASC',
					'meta_query' => array( array( 'key' => '_bizcity_crm_contact_id', 'value' => array_map( 'strval', $chunk ), 'compare' => 'IN' ) ),
				) );
				foreach ( (array) $orders as $order ) {
					if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { continue; }
					$cid = (int) $order->get_meta( '_bizcity_crm_contact_id' );
					if ( ! isset( $out[ $cid ] ) ) { continue; }
					// [PHASE-0.54 D54-2] "chưa huỷ" (R-PIPE-2 v1.1): a cancelled/failed/binned order is not a sale signal.
					$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
					if ( in_array( $status, array( 'cancelled', 'failed', 'trash' ), true ) ) { continue; }
					$created = $order->get_date_created();
					$ts = $created ? (int) $created->getTimestamp() : 0;
					$out[ $cid ]['ordered']++;
					if ( 1 === $out[ $cid ]['ordered'] || 0 === $out[ $cid ]['first_order_ts'] ) { $out[ $cid ]['first_order_ts'] = $ts; }
					$out[ $cid ]['last_order_ts'] = max( $out[ $cid ]['last_order_ts'], $ts );
					if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
						$out[ $cid ]['paid']++;
						$out[ $cid ]['revenue'] += (float) $order->get_total();
						if ( 1 === $out[ $cid ]['paid'] ) { $out[ $cid ]['first_paid_ts'] = $ts; }
						if ( 2 === $out[ $cid ]['paid'] ) { $out[ $cid ]['second_paid_ts'] = $ts; }
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Resolved rows for a set of contacts.
	 *
	 * @return array<int,array> contact_id => facts + resolution
	 */
	public static function rows( array $ids, ?array $settings = null, int $now = 0 ): array {
		$settings = $settings ?: self::settings();
		$now = $now > 0 ? $now : ( function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time() );
		$rows = array();
		foreach ( self::load( $ids ) as $cid => $facts ) {
			$res = self::resolve( $facts, $facts['pipe'], $now, $settings );
			$rows[ $cid ] = array_merge( $facts, $res, array( 'contact_id' => $cid, 'has_next' => $facts['open_tasks'] > 0 ) );
		}
		return $rows;
	}

	// ── Projections ──────────────────────────────────────────────────────

	/** Card DTO. `$with_owner` false on C (R-PIPE-7). */
	public static function card( array $row, bool $with_owner, array $names = array() ): array {
		$card = array(
			'contact_id'      => (int) $row['contact_id'],
			'display_name'    => self::clean_text( (string) $row['name'], 120 ),
			'stage'           => (string) $row['stage'],
			'days'            => (int) $row['days'],
			'stuck'           => (bool) $row['stuck'],
			'has_next'        => (bool) $row['has_next'],
			'next_due'        => $row['next_due'],
			'overdue_tasks'   => (int) $row['overdue_tasks'],
			'source'          => (string) $row['source'],
			'manual_won_pending' => (bool) $row['manual_won_pending'],
			'payment_pending' => (bool) $row['payment_pending'],
			'paid_orders'     => (int) $row['paid'],
			'ordered'         => (int) $row['ordered'],
			'conversation_id' => (int) $row['conversation_id'] ?: null,
			'inbox_id'        => (int) $row['inbox_id'] ?: null,
			'steps_done'      => self::steps_done_count( $row ),
		);
		if ( $with_owner ) {
			$oid = (int) $row['owner_id'];
			$card['owner'] = $oid > 0 ? array( 'user_id' => $oid, 'display_name' => $names[ $oid ] ?? ( '#' . $oid ) ) : null;
		}
		return $card;
	}

	public static function steps_done_count( array $row ): int {
		$stage = (string) ( $row['base_stage'] ?? $row['stage'] ?? '' );
		$steps = is_array( $row['pipe']['steps'][ $stage ] ?? null ) ? $row['pipe']['steps'][ $stage ] : array();
		return count( array_filter( $steps ) );
	}

	/**
	 * Board: columns + sample cards + per-owner matrix + KPIs.
	 *
	 * @param array $opts {owner_ids:int[]|null (only these owners), source:string, with_owner:bool, sample:int, range_days:int}
	 */
	public static function board( array $rows, array $opts = array() ): array {
		$settings = self::settings();
		$with_owner = ! empty( $opts['with_owner'] );
		$sample = max( 0, min( 60, (int) ( $opts['sample'] ?? 20 ) ) );
		$source = sanitize_key( (string) ( $opts['source'] ?? '' ) );
		$owner_filter = isset( $opts['owner_ids'] ) && is_array( $opts['owner_ids'] ) ? array_flip( array_map( 'intval', $opts['owner_ids'] ) ) : null;
		$now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		$range = max( 1, (int) ( $opts['range_days'] ?? 30 ) );

		$names = array();
		if ( $with_owner ) {
			foreach ( $rows as $r ) {
				$oid = (int) $r['owner_id'];
				if ( $oid > 0 && ! isset( $names[ $oid ] ) ) { $u = function_exists( 'get_userdata' ) ? get_userdata( $oid ) : null; $names[ $oid ] = $u ? (string) $u->display_name : '#' . $oid; }
			}
		}
		$columns = array();
		foreach ( self::ALL_STAGES as $stage ) {
			$columns[ $stage ] = array( 'stage' => $stage, 'label' => self::LABELS[ $stage ], 'count' => 0, 'stuck' => 0, 'no_next' => 0, 'stuck_days' => (int) ( $settings['stuck_days'][ $stage ] ?? 0 ), 'cards' => array() );
		}
		$matrix = array();
		$kpi = array( 'customers' => 0, 'no_next' => 0, 'stuck' => 0, 'overdue_tasks' => 0, 'open' => 0, 'touched7' => 0, 'touched30' => 0, 'ordered30' => 0 );
		$filtered = array();
		foreach ( $rows as $r ) {
			if ( '' !== $source && $r['source'] !== $source ) { continue; }
			if ( null !== $owner_filter && ! isset( $owner_filter[ (int) $r['owner_id'] ] ) ) { continue; }
			$filtered[] = $r;
		}
		usort( $filtered, static function ( $a, $b ) {
			return ( (int) $b['stuck'] <=> (int) $a['stuck'] ) ?: ( (int) ! $b['has_next'] <=> (int) ! $a['has_next'] ) ?: ( $b['days'] <=> $a['days'] );
		} );
		foreach ( $filtered as $r ) {
			$st = $r['stage'];
			$open = in_array( $st, self::OPEN_STAGES, true );
			$nonext = ! $r['has_next'] && 'lost' !== $st && ! in_array( $st, array( 'won', 'repeat' ), true );
			$columns[ $st ]['count']++;
			if ( $r['stuck'] ) { $columns[ $st ]['stuck']++; $kpi['stuck']++; }
			if ( $nonext ) { $columns[ $st ]['no_next']++; $kpi['no_next']++; }
			if ( count( $columns[ $st ]['cards'] ) < $sample ) { $columns[ $st ]['cards'][] = self::card( $r, $with_owner, $names ); }
			$kpi['customers']++;
			$kpi['overdue_tasks'] += (int) $r['overdue_tasks'];
			if ( $open ) {
				$kpi['open']++;
				if ( $r['last_out_ts'] > $now - 7 * DAY_IN_SECONDS ) { $kpi['touched7']++; }
			}
			$touched_in_range = $r['last_out_ts'] > $now - $range * DAY_IN_SECONDS;
			if ( $touched_in_range ) {
				$kpi['touched30']++;
				if ( in_array( $r['base_stage'], array( 'won', 'repeat' ), true ) && $r['since_ts'] > $now - $range * DAY_IN_SECONDS ) { $kpi['ordered30']++; }
			}
			if ( $with_owner ) {
				$oid = (int) $r['owner_id'];
				if ( ! isset( $matrix[ $oid ] ) ) {
					$matrix[ $oid ] = array( 'user_id' => $oid ?: null, 'display_name' => $oid > 0 ? ( $names[ $oid ] ?? '#' . $oid ) : 'Chưa phân công', 'team_name' => '', 'counts' => array_fill_keys( self::ALL_STAGES, 0 ), 'stuck' => 0, 'no_next' => 0, 'overdue_tasks' => 0, 'open' => 0, 'touched7' => 0, 'touched' => 0, 'ordered' => 0 );
				}
				$m =& $matrix[ $oid ];
				$m['counts'][ $st ]++;
				if ( $r['stuck'] ) { $m['stuck']++; }
				if ( $nonext ) { $m['no_next']++; }
				$m['overdue_tasks'] += (int) $r['overdue_tasks'];
				if ( $open ) { $m['open']++; if ( $r['last_out_ts'] > $now - 7 * DAY_IN_SECONDS ) { $m['touched7']++; } }
				if ( $touched_in_range ) { $m['touched']++; if ( in_array( $r['base_stage'], array( 'won', 'repeat' ), true ) && $r['since_ts'] > $now - $range * DAY_IN_SECONDS ) { $m['ordered']++; } }
				unset( $m );
			}
		}
		$min_sample = 10;
		$matrix_out = array();
		foreach ( $matrix as $m ) {
			$m['touch7_rate'] = $m['open'] > 0 ? round( $m['touched7'] / $m['open'] * 100, 1 ) : null;
			$m['conversion'] = $m['touched'] >= $min_sample ? array( 'num' => $m['ordered'], 'den' => $m['touched'], 'value' => round( $m['ordered'] / $m['touched'] * 100, 1 ) ) : null;
			$m['risk'] = $m['no_next'] * 3 + $m['overdue_tasks'] * 2 + $m['stuck'] + ( null !== $m['touch7_rate'] && $m['touch7_rate'] < 60 ? 5 : 0 );
			$matrix_out[] = $m;
		}
		usort( $matrix_out, static function ( $a, $b ) { return $b['risk'] <=> $a['risk']; } );
		return array(
			'columns' => array_values( $columns ),
			'kpis'    => array(
				'customers'     => $kpi['customers'],
				'no_next'       => $kpi['no_next'],
				'stuck'         => $kpi['stuck'],
				'overdue_tasks' => $kpi['overdue_tasks'],
				'touch7_rate'   => $kpi['open'] > 0 ? round( $kpi['touched7'] / $kpi['open'] * 100, 1 ) : null,
				'conversion'    => $kpi['touched30'] >= $min_sample ? array( 'num' => $kpi['ordered30'], 'den' => $kpi['touched30'], 'value' => round( $kpi['ordered30'] / $kpi['touched30'] * 100, 1 ) ) : null,
			),
			'matrix'  => $with_owner ? $matrix_out : null,
			'settings' => array( 'stuck_days' => $settings['stuck_days'] ),
			'capped'  => count( $rows ) >= self::CONTACT_CAP,
		);
	}

	/**
	 * One customer's stage detail for the Inbox toolbar / rail.
	 */
	public static function detail( int $contact_id, bool $with_actor_names ): ?array {
		$rows = self::rows( array( $contact_id ) );
		if ( ! isset( $rows[ $contact_id ] ) ) { return null; }
		$row = $rows[ $contact_id ];
		$settings = self::settings();
		$base = $row['base_stage'];
		$step_labels = $settings['steps'][ $row['stage'] ] ?? ( $settings['steps'][ $base ] ?? array() );
		$ticked = is_array( $row['pipe']['steps'][ $row['stage'] ] ?? null ) ? $row['pipe']['steps'][ $row['stage'] ] : array();
		$steps = array();
		foreach ( $step_labels as $i => $label ) { $steps[] = array( 'key' => (int) $i, 'label' => $label, 'done' => ! empty( $ticked[ $i ] ) ); }
		return array(
			'contact_id'  => $contact_id,
			'display_name' => self::clean_text( (string) $row['name'], 120 ),
			'stage'       => $row['stage'],
			'base_stage'  => $base,
			'label'       => self::LABELS[ $row['stage'] ] ?? $row['stage'],
			'days'        => $row['days'],
			'stuck'       => $row['stuck'],
			'source'      => $row['source'],
			'manual_won_pending' => $row['manual_won_pending'],
			'payment_pending' => $row['payment_pending'],
			'paid_orders' => $row['paid'],
			'ordered'     => $row['ordered'],
			'steps'       => $steps,
			'next'        => array( 'open_tasks' => $row['open_tasks'], 'next_due' => $row['next_due'], 'overdue' => $row['overdue_tasks'] ),
			'history'     => self::history( $contact_id, $with_actor_names ),
			'stages'      => array_map( static function ( $s ) { return array( 'stage' => $s, 'label' => self::LABELS[ $s ] ); }, array( 'target', 'contacted', 'consult', 'quote', 'won', 'repeat', 'lost' ) ),
			'lost_reasons' => $settings['lost_reasons'],
			'steps_by_stage' => $settings['steps'],
			// [PHASE-0.54 R-INBOX-PIPE-11] tenant-configured suggestion rules — the FE detects matches
			// client-side against the messages it already has loaded (no extra request per message).
			'suggestions_enabled' => (bool) $settings['suggestions_enabled'],
			'suggestion_rules' => $settings['suggestion_rules'],
			// [2026-09-21 08:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63A WP-5.3 — expose ordered server vocabulary for legacy sales rail consumers.
			'stage_definition' => array(
				'keys' => array_values( array_map( 'strval', self::STAGES ) ),
				'labels' => self::LABELS,
			),
		);
	}

	/** Stage history from the audit log (newest first). Member surface gets no colleague names. */
	public static function history( int $contact_id, bool $with_actor_names, int $limit = 20 ): array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) { return array(); }
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$me = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT action, before_json, after_json, user_id, user_label, created_at FROM `{$tbl}` WHERE entity_type = 'crm_contact' AND entity_id = %d AND action IN ('stage_changed','step_done','outcome_logged') ORDER BY id DESC LIMIT %d",
			$contact_id, $limit ), ARRAY_A ) as $r ) {
			$before = json_decode( (string) $r['before_json'], true ) ?: array();
			$after  = json_decode( (string) $r['after_json'], true ) ?: array();
			$uid = (int) $r['user_id'];
			$out[] = array(
				'kind'    => (string) $r['action'],
				'from'    => (string) ( $before['stage'] ?? '' ),
				'to'      => (string) ( $after['stage'] ?? '' ),
				'note'    => self::clean_text( (string) ( $after['note'] ?? '' ), 500 ),
				'step'    => self::clean_text( (string) ( $after['step_label'] ?? '' ), 120 ),
				'outcome' => sanitize_key( (string) ( $after['outcome'] ?? '' ) ),
				'channel' => sanitize_key( (string) ( $after['channel'] ?? '' ) ),
				'lost_reason' => self::clean_text( (string) ( $after['lost_reason'] ?? '' ), 80 ),
				'at'      => (string) $r['created_at'],
				'actor'   => $with_actor_names ? (string) $r['user_label'] : ( $uid === $me ? 'Bạn' : 'Trưởng nhóm' ),
			);
		}
		return $out;
	}

	// ── Personal space (M0) ──────────────────────────────────────────────

	public static function goal_for( int $user_id, string $month = '' ): ?array {
		$month = '' !== $month ? $month : ( function_exists( 'current_time' ) ? current_time( 'Y-m' ) : gmdate( 'Y-m' ) );
		$goals = function_exists( 'get_user_meta' ) ? get_user_meta( $user_id, self::GOAL_META, true ) : array();
		$goal = is_array( $goals ) ? ( $goals[ $month ] ?? null ) : null;
		return is_array( $goal ) ? array( 'month' => $month, 'won_target' => (int) ( $goal['won_target'] ?? 0 ), 'set_by' => (int) ( $goal['set_by'] ?? 0 ), 'set_at' => (string) ( $goal['set_at'] ?? '' ) ) : null;
	}

	public static function set_goal( int $user_id, int $won_target, int $actor_id, string $month = '' ): array {
		$month = '' !== $month ? $month : current_time( 'Y-m' );
		$goals = get_user_meta( $user_id, self::GOAL_META, true );
		$goals = is_array( $goals ) ? $goals : array();
		$goals[ $month ] = array( 'won_target' => max( 0, min( 100000, $won_target ) ), 'set_by' => $actor_id, 'set_at' => current_time( 'mysql' ) );
		// Keep the last 12 months only.
		krsort( $goals );
		$goals = array_slice( $goals, 0, 12, true );
		update_user_meta( $user_id, self::GOAL_META, $goals );
		if ( class_exists( 'BizCity_CRM_Audit_Log' ) ) {
			BizCity_CRM_Audit_Log::log( 'crm_staff', $user_id, 'goal_set', null, array( 'month' => $month, 'won_target' => $goals[ $month ]['won_target'] ), array( 'user_id' => $actor_id ) );
		}
		return self::goal_for( $user_id, $month );
	}

	/**
	 * Personal space for one user, over the user's OWN scope (never widened).
	 */
	public static function space( int $user_id ): array {
		global $wpdb;
		$now = (int) current_time( 'timestamp' );
		$ids = self::contact_ids_for_inboxes( self::c_inbox_ids( $user_id ) );
		$rows = self::rows( $ids );
		$counts = array_fill_keys( self::ALL_STAGES, 0 );
		$no_next = 0; $stuck = 0;
		$month_start = strtotime( current_time( 'Y-m-01 00:00:00' ) );
		$won_month = 0;
		$todo = array();
		foreach ( $rows as $r ) {
			$counts[ $r['stage'] ]++;
			if ( $r['stuck'] ) { $stuck++; }
			if ( ! $r['has_next'] && in_array( $r['stage'], self::OPEN_STAGES, true ) ) { $no_next++; }
			if ( in_array( $r['base_stage'], array( 'won', 'repeat' ), true ) && $r['since_ts'] >= $month_start ) { $won_month++; }
			if ( $r['overdue_tasks'] > 0 || $r['stuck'] ) { $todo[] = $r; }
		}
		usort( $todo, static function ( $a, $b ) { return ( $b['overdue_tasks'] <=> $a['overdue_tasks'] ) ?: ( $b['days'] <=> $a['days'] ); } );

		// Week: open tasks assigned to the user by due date (7 days from today).
		$task_t = BizCity_CRM_DB_Installer_V2::tbl_crm_tasks();
		$today = current_time( 'Y-m-d' );
		$end = gmdate( 'Y-m-d', strtotime( $today . ' +6 days' ) );
		$by_day = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT due_date, COUNT(*) AS n FROM `{$task_t}` WHERE deleted_at IS NULL AND completed = 0 AND assignee_id = %d AND status NOT IN ('done','cancelled','returned') AND due_date BETWEEN %s AND %s GROUP BY due_date",
			$user_id, $today, $end ), ARRAY_A ) as $r ) { $by_day[ (string) $r['due_date'] ] = (int) $r['n']; }
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$task_t}` WHERE deleted_at IS NULL AND completed = 0 AND assignee_id = %d AND status NOT IN ('done','cancelled','returned') AND due_date < %s", $user_id, $today ) );
		$week = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$d = gmdate( 'Y-m-d', strtotime( $today . " +{$i} days" ) );
			$week[] = array( 'date' => $d, 'tasks' => ( $by_day[ $d ] ?? 0 ) + ( 0 === $i ? $overdue : 0 ), 'today' => 0 === $i );
		}

		$assigned = array( 'open' => 0, 'overdue' => 0, 'unseen' => 0 );
		if ( class_exists( 'BizCity_CRM_Task_Handoff' ) ) {
			$open = BizCity_CRM_Task_Handoff::list_for_member( $user_id, 'open', 200 );
			$assigned['open'] = count( $open );
			foreach ( $open as $t ) { if ( ! empty( $t['overdue'] ) ) { $assigned['overdue']++; } }
			$assigned['unseen'] = BizCity_CRM_Task_Handoff::count_unseen_for_member( $user_id );
		}

		// Recent activity by this user (audit), no customer PII beyond the display name.
		$audit = BizCity_CRM_DB_Installer_V2::tbl_crm_audit_log();
		$recent = array();
		$recent_rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT entity_id, action, before_json, after_json, created_at FROM `{$audit}` WHERE entity_type = 'crm_contact' AND user_id = %d AND action IN ('stage_changed','outcome_logged') ORDER BY id DESC LIMIT 8", $user_id ), ARRAY_A );
		$names = array();
		foreach ( $recent_rows as $r ) { $names[] = (int) $r['entity_id']; }
		$name_map = array();
		if ( $names ) {
			$ct_t = BizCity_CRM_DB_Installer_V2::tbl_contacts();
			$ph = implode( ',', array_fill( 0, count( $names ), '%d' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, name, first_name, last_name FROM `{$ct_t}` WHERE id IN ({$ph})", $names ), ARRAY_A ) as $c ) {
				$n = trim( (string) $c['name'] ); if ( '' === $n ) { $n = trim( $c['first_name'] . ' ' . $c['last_name'] ); }
				$name_map[ (int) $c['id'] ] = $n ?: 'Khách #' . (int) $c['id'];
			}
		}
		foreach ( $recent_rows as $r ) {
			$before = json_decode( (string) $r['before_json'], true ) ?: array();
			$after = json_decode( (string) $r['after_json'], true ) ?: array();
			$recent[] = array(
				'at' => (string) $r['created_at'], 'kind' => (string) $r['action'], 'contact' => $name_map[ (int) $r['entity_id'] ] ?? '',
				'contact_id' => (int) $r['entity_id'], 'from' => (string) ( $before['stage'] ?? '' ), 'to' => (string) ( $after['stage'] ?? '' ),
				'note' => self::clean_text( (string) ( $after['note'] ?? '' ), 200 ), 'outcome' => sanitize_key( (string) ( $after['outcome'] ?? '' ) ),
			);
		}

		$phones = array( 'total' => 0, 'attention' => 0 );
		if ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) ) {
			foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) as $account ) {
				$phones['total']++;
				if ( 'connected' !== (string) ( $account['status'] ?? '' ) ) { $phones['attention']++; }
			}
		}

		$first = array();
		foreach ( array_slice( $todo, 0, 5 ) as $r ) { $first[] = self::card( $r, false ); }

		return array(
			'as_of'     => current_time( 'c' ),
			'customers' => count( $rows ),
			'counts'    => $counts,
			'no_next'   => $no_next,
			'stuck'     => $stuck,
			'assigned'  => $assigned,
			'week'      => $week,
			'goal'      => self::goal_for( $user_id ),
			'won_month' => $won_month,
			'first'     => $first,
			'recent'    => $recent,
			'phones'    => $phones,
			'capped'    => count( $ids ) >= self::CONTACT_CAP,
		);
	}

	// ── Utils ────────────────────────────────────────────────────────────

	public static function clean_text( string $value, int $max ): string {
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
