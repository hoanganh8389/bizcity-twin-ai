<?php
/**
 * Bot Studio — Vertical Brain as builtin tools (PHASE-0.60D §3, S2.*).
 *
 * The bot never re-implements a specialty. It exposes each vertical the Guru
 * is ALLOWED to use (`bizcity_characters.allowed_verticals`, empty = none) as
 * ONE tool row, resolved from the canonical registry
 * `BizCity_TwinBrain_Vertical_Bridge_Registry::all()` (Contract 13 — no fourth catalog).
 *
 * Hard rules on a customer channel:
 *   - `sensitive` / `requires_grant` rows (woo_bizops) are NEVER offered (S2.5).
 *   - `guest_allowed=false` rows are NEVER offered — a Zalo customer is not a logged-in user (0.60D Q4: hard block in 0.60A).
 *   - `min_plan` above the site tier → excluded WITH a hint (S2.6, not silent).
 *   - Disclaimers for med / nutri / law / tax travel with the reply and survive Zalo trimming (S2.4).
 *
 * Execution depth (MVP, stated honestly): a vertical tool returns a *frame* —
 * scope, output shape, disclaimer obligation — that narrows the model, and for
 * research verticals adds a web search through the same 1API search client.
 * It does not run the full MPR pipeline (that path needs a logged-in identity;
 * see class-twinbrain-runtime.php::vertical_policy_allows()).
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60D (2026-09-23)
 */

// [2026-09-23 03:30 PM Claude Fable 5.1] PHASE-0.60D S2.1–S2.8 — registry-driven, narrow-only.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Vertical_Tools {

	const TOOL_PREFIX = 'vertical_';

	/** Verticals whose answers must carry a disclaimer (0.60D §3.3 rule 3). */
	public static function disclaimers(): array {
		return array(
			'med'   => 'Lưu ý: thông tin y khoa chỉ để tham khảo, không thay thế chẩn đoán của bác sĩ.',
			'nutri' => 'Lưu ý: gợi ý dinh dưỡng mang tính tham khảo; người có bệnh nền nên hỏi chuyên gia.',
			'law'   => 'Lưu ý: nội dung pháp lý chỉ để tham khảo, không phải tư vấn pháp luật chính thức.',
			'tax'   => 'Lưu ý: thông tin thuế chỉ để tham khảo; quyết định cuối cùng theo cơ quan thuế và kế toán của bạn.',
		);
	}

	/** Verticals that benefit from a web search inside the frame. */
	const RESEARCH_VERTICALS = array( 'quick', 'deep', 'scholar', 'gov', 'social', 'company', 'products' );

	/**
	 * Registry rows the Guru may use on a customer channel, as tool rows.
	 *
	 * @param object $character Character row (allowed_verticals JSON/array).
	 * @param string $site_tier 'free'|'plus'|'pro' (defaults to the same filter TwinBrain uses, user 0).
	 */
	public static function rows_for_character( $character, string $site_tier = '' ): array {
		if ( ! class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ) {
			return array();
		}
		$allowed = self::allowed_verticals( $character );
		if ( empty( $allowed ) ) {
			return array(); // S2.1 — empty list = nothing, never "everything".
		}
		if ( $site_tier === '' ) {
			$site_tier = function_exists( 'apply_filters' ) ? sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', 0 ) ) : 'free';
		}
		$rank = array( 'free' => 0, 'plus' => 1, 'pro' => 2 );
		$out  = array();
		foreach ( BizCity_TwinBrain_Vertical_Bridge_Registry::all() as $v ) {
			$id = (string) ( $v['id'] ?? '' );
			if ( $id === '' || ! in_array( $id, $allowed, true ) ) {
				continue;
			}
			$status = BizCity_Bot_Tool_Registry::STATUS_AVAILABLE;
			$hint   = '';
			if ( ! empty( $v['sensitive'] ) || ! empty( $v['context_bank']['requires_grant'] ) ) {
				$status = BizCity_Bot_Tool_Registry::STATUS_UNCONFIGURED;
				$hint   = 'Vertical nhạy cảm (dữ liệu nội bộ) — mặc định tắt trên kênh khách; không có cấp quyền theo số Zalo ở 0.60A.';
			} elseif ( empty( $v['guest_allowed'] ) ) {
				$status = BizCity_Bot_Tool_Registry::STATUS_UNCONFIGURED;
				$hint   = 'Vertical chỉ cho người dùng đã đăng nhập; khách Zalo là khách lạ nên bị chặn cứng (0.60D Q4).';
			} elseif ( ( $rank[ sanitize_key( (string) ( $v['min_plan'] ?? 'free' ) ) ] ?? 0 ) > ( $rank[ $site_tier ] ?? 0 ) ) {
				$status = BizCity_Bot_Tool_Registry::STATUS_UNCONFIGURED;
				$hint   = sprintf( 'Cần gói %s; site đang ở gói %s.', (string) $v['min_plan'], $site_tier );
			}
			if ( 'astro' === $id ) {
				// astro is offered through the dedicated astro_profile tool (identity-safe path, 0.60D §2).
				continue;
			}
			$out[] = array(
				'id'          => self::TOOL_PREFIX . $id,
				'label'       => 'Vertical · ' . (string) ( $v['label'] ?? $id ),
				'group'       => 'read',
				'description' => (string) ( $v['role'] ?? '' ) . ' (phạm vi chuyên môn "' . $id . '"; chỉ THU HẸP, không nới rộng)',
				'infra'       => 'TwinBrain vertical ' . $id,
				'status'      => $status,
				'hint'        => $hint,
				'kind'        => 'vertical',
				'vertical_id' => $id,
				'output_shape'=> (string) ( $v['output_shape'] ?? 'narrative' ),
			);
		}
		return $out;
	}

	/** allowed_verticals column → normalised slug list. */
	public static function allowed_verticals( $character ): array {
		$raw = is_object( $character ) ? ( $character->allowed_verticals ?? array() ) : array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		$out = array();
		foreach ( (array) $raw as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug !== '' ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Run one vertical tool: returns a system frame (and optional research findings).
	 *
	 * @return array{ok:bool,content:string,disclaimer:string,error:string}
	 */
	public static function run( string $vertical_id, array $args, array $claim ): array {
		$vertical_id = sanitize_key( $vertical_id );
		$row = class_exists( 'BizCity_TwinBrain_Vertical_Bridge_Registry' ) ? BizCity_TwinBrain_Vertical_Bridge_Registry::get( $vertical_id ) : null;
		if ( ! is_array( $row ) ) {
			return array( 'ok' => false, 'content' => '', 'disclaimer' => '', 'error' => 'vertical_not_registered' );
		}
		// S2.5 — belt and braces: even if a caller bypassed rows_for_character(), sensitive rows never run on a customer channel.
		if ( ! empty( $row['sensitive'] ) || ! empty( $row['context_bank']['requires_grant'] ) || empty( $row['guest_allowed'] ) ) {
			return array( 'ok' => false, 'content' => '', 'disclaimer' => '', 'error' => 'vertical_not_allowed' );
		}
		$disclaimer = self::disclaimers()[ $vertical_id ] ?? '';
		$lines = array(
			'=== VERTICAL: ' . strtoupper( $vertical_id ) . ' ===',
			'Vai trò: ' . (string) ( $row['role'] ?? '' ),
			'Hình thức trả lời: ' . (string) ( $row['output_shape'] ?? 'narrative' ) . ' (rút gọn cho Zalo, không markdown).',
			'Phạm vi: chỉ trả lời trong chuyên môn này; ngoài phạm vi thì nói rõ và quay về vai trò chung.',
		);
		if ( $disclaimer !== '' ) {
			$lines[] = 'BẮT BUỘC kết thúc câu trả lời bằng đúng câu: "' . $disclaimer . '"';
		}
		$query = trim( (string) ( $args['query'] ?? '' ) );
		if ( $query !== '' && in_array( $vertical_id, self::RESEARCH_VERTICALS, true ) && class_exists( 'BizCity_Bot_Tools' ) ) {
			$search = BizCity_Bot_Tools::run( 'web_search', array( 'query' => $query ), $claim );
			if ( ! empty( $search['ok'] ) && $search['content'] !== '' ) {
				$lines[] = $search['content'];
			}
		}
		return array( 'ok' => true, 'content' => implode( "\n", $lines ), 'disclaimer' => $disclaimer, 'error' => '' );
	}

	/**
	 * Trim a reply for Zalo without ever cutting the disclaimer (S2.4 / E-D6).
	 */
	public static function trim_for_zalo( string $text, int $limit, string $disclaimer = '' ): string {
		$text       = trim( $text );
		$disclaimer = trim( $disclaimer );
		if ( $disclaimer !== '' && mb_substr( $text, -mb_strlen( $disclaimer ) ) === $disclaimer ) {
			$text = trim( mb_substr( $text, 0, mb_strlen( $text ) - mb_strlen( $disclaimer ) ) );
		}
		$reserve = $disclaimer !== '' ? mb_strlen( $disclaimer ) + 2 : 0;
		$room    = max( 40, $limit - $reserve );
		if ( mb_strlen( $text ) > $room ) {
			$text = rtrim( mb_substr( $text, 0, $room - 1 ) ) . '…';
		}
		return $disclaimer !== '' ? $text . "\n\n" . $disclaimer : $text;
	}
}
