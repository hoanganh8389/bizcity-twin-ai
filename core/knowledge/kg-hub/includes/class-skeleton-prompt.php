<?php
/**
 * Bizcity Twin AI — Notebook Skeleton Reflection Prompts
 *
 * Centralised system prompts and validator for the LLM-driven reflection
 * pass that turns raw notebook chunks into the canonical "skeleton" JSON
 * consumed by every artifact-generating tool (RULE-1).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-11
 * @see        PHASE-0-RULE-SKELETON.md   RULE-2 / Reflection pipeline
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Skeleton_Prompt {

	const SCHEMA_VERSION = 1;

	/** Hard caps applied by validate() — F-12. */
	const LABEL_MAX_WORDS   = 8;
	const SUMMARY_MAX_WORDS = 30;
	const KEY_POINT_MAX_WORDS = 40;

	const MAX_TOP_NODES   = 7;
	const MAX_CHILDREN    = 5;
	const MAX_KEY_POINTS  = 10;
	const MAX_ENTITIES    = 12;

	/** Primary system prompt (single-pass + map step). */
	public static function system( bool $has_pinned_notes = false ): string {
		$base = <<<'TXT'
Bạn là **Notebook Skeleton Architect** — một AI chuyên trích xuất “bộ khung ý đồ” từ kho tài liệu mà người dùng đã nạp vào một notebook.

NHIỆM VỤ
Đọc các đoạn nội dung được cung cấp và trả về một JSON DUY NHẤT mô tả khung sườn chuẩn của notebook, theo schema dưới đây. KHÔNG được giải thích, KHÔNG được bọc trong markdown. Chỉ trả về JSON hợp lệ.

SCHEMA (bắt buộc):
{
  "schema_version": 1,
  "nucleus": {
    "title":   "tên ngắn gọn (≤ 8 từ) cho cả notebook",
    "thesis":  "1 câu lõi (≤ 30 từ) — ý đồ chính người dùng đang theo đuổi"
  },
  "skeleton": [
    {
      "label":    "tiêu đề mục (≤ 8 từ)",
      "summary":  "1 câu mô tả ngắn (≤ 30 từ)",
      "children": [ { "label": "tiểu mục (≤ 8 từ)" }, ... up to 5 ]
    },
    ... up to 7 mục cấp 1
  ],
  "key_points": [ "dữ kiện hoặc luận điểm quan trọng (≤ 40 từ)", ... up to 10 ],
  "entities":   [ "tên riêng / khái niệm cốt lõi", ... up to 12 ]
}

QUY TẮC BẮT BUỘC:
1. JSON hợp lệ; không trailing comma; không comment.
2. Tiếng Việt cho mọi label/summary/key_point trừ khi nguồn hoàn toàn tiếng Anh.
3. Bám đúng nội dung được cung cấp — KHÔNG bịa thêm dữ kiện ngoài ngữ liệu.
4. Nucleus phải là “tinh chất” — gộp được tinh thần xuyên suốt toàn bộ chunks.
5. Skeleton tối đa 7 mục cấp 1, mỗi mục tối đa 5 children. Sắp xếp theo logic, không theo thứ tự xuất hiện ngẫu nhiên.
6. Key_points là dữ kiện đáng cite (số liệu, định nghĩa, ngày, tên), KHÔNG phải tóm tắt chung.
7. Entities là tên riêng / khái niệm độc lập có thể tra cứu.
TXT;

		if ( ! $has_pinned_notes ) {
			return $base;
		}

		// v1.1 — prepend a priority directive when the trigger reason is
		// `notes_pinned`. The pinned notes block is materialised in the USER
		// message; here we only tell the model how to weight it.
		$priority = <<<'TXT'

⚡ ƯU TIÊN TỪ NGƯỜI DÙNG (NOTES PINNED)
Người dùng đã ghim một số ghi chú cho notebook này (khối “NOTES PINNED BY USER” ở cuối nội dung). Hãy coi những ghi chú đó là tín hiệu ý đồ có trọng số cao nhất:
 - Nucleus phải phản ánh đúng những ý được ghim (không bỏ qua).
 - Các mục cha trong "skeleton" ưu tiên bao trùm các chủ đề mà user pin.
 - Key_points nên kéo thêm dữ kiện từ pinned notes khi chúng là con số / mốc / quyết định.
 - KHÔNG bịa thêm: nếu một ghi chú không khớp với bất kỳ chunk nào, vẫn coi nó như tuyên bố ý đồ của user và cân nhắc đưa vào nucleus/skeleton/key_points.
TXT;

		return $base . $priority;
	}

	/** REDUCE prompt — merge mini-skeletons in map-reduce pipeline. */
	public static function reduce_system(): string {
		return <<<'TXT'
Bạn là **Notebook Skeleton Architect (REDUCE pass)**.

Bạn nhận N skeleton con (mỗi cái đại diện cho một nhóm chunks). Hãy gộp chúng thành một skeleton DUY NHẤT theo cùng schema:

{ "schema_version":1, "nucleus":{...}, "skeleton":[...], "key_points":[...], "entities":[...] }

QUY TẮC GỘP:
- Loại trùng lặp về ý (không chỉ trùng từ).
- Giữ tối đa 7 mục cấp 1, 5 children mỗi mục, 10 key_points, 12 entities.
- Nucleus phải phản ánh được cả N skeleton con.
- Chỉ trả JSON hợp lệ, KHÔNG bọc markdown.
TXT;
	}

	/**
	 * Parse + normalise + cap the LLM output.
	 *
	 * @return array|null  Cleaned skeleton or null if parse failed.
	 */
	public static function validate( string $raw ): ?array {
		$raw = trim( $raw );
		// Strip ``` fences if model wrapped despite instructions.
		if ( strpos( $raw, '```' ) === 0 ) {
			$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $raw );
			$raw = trim( $raw );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			// Try to extract first JSON object block.
			if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
				$data = json_decode( $m[0], true );
			}
		}
		if ( ! is_array( $data ) ) {
			return null;
		}

		$out = [
			'schema_version' => self::SCHEMA_VERSION,
			'nucleus'        => [
				'title'  => self::cap_words(
					(string) ( $data['nucleus']['title']  ?? '' ),
					self::LABEL_MAX_WORDS
				),
				'thesis' => self::cap_words(
					(string) ( $data['nucleus']['thesis'] ?? '' ),
					self::SUMMARY_MAX_WORDS
				),
			],
			'skeleton'       => [],
			'key_points'     => [],
			'entities'       => [],
		];

		if ( isset( $data['skeleton'] ) && is_array( $data['skeleton'] ) ) {
			$count = 0;
			foreach ( $data['skeleton'] as $node ) {
				if ( ! is_array( $node ) ) { continue; }
				if ( ++$count > self::MAX_TOP_NODES ) { break; }
				$kids = [];
				if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
					$ck = 0;
					foreach ( $node['children'] as $child ) {
						if ( ! is_array( $child ) ) { continue; }
						if ( ++$ck > self::MAX_CHILDREN ) { break; }
						$kids[] = [
							'label' => self::cap_words(
								(string) ( $child['label'] ?? '' ),
								self::LABEL_MAX_WORDS
							),
						];
					}
				}
				$out['skeleton'][] = [
					'label'    => self::cap_words(
						(string) ( $node['label']   ?? '' ),
						self::LABEL_MAX_WORDS
					),
					'summary'  => self::cap_words(
						(string) ( $node['summary'] ?? '' ),
						self::SUMMARY_MAX_WORDS
					),
					'children' => $kids,
				];
			}
		}

		if ( isset( $data['key_points'] ) && is_array( $data['key_points'] ) ) {
			$kps = [];
			foreach ( $data['key_points'] as $kp ) {
				$txt = trim( (string) $kp );
				if ( $txt === '' ) { continue; }
				$kps[] = self::cap_words( $txt, self::KEY_POINT_MAX_WORDS );
				if ( count( $kps ) >= self::MAX_KEY_POINTS ) { break; }
			}
			$out['key_points'] = $kps;
		}

		if ( isset( $data['entities'] ) && is_array( $data['entities'] ) ) {
			$ents = [];
			foreach ( $data['entities'] as $ent ) {
				$txt = trim( (string) $ent );
				if ( $txt === '' ) { continue; }
				$ents[] = $txt;
				if ( count( $ents ) >= self::MAX_ENTITIES ) { break; }
			}
			$out['entities'] = $ents;
		}

		// Refuse if nucleus is empty — empty skeleton is useless.
		if ( $out['nucleus']['title'] === '' && $out['nucleus']['thesis'] === '' ) {
			return null;
		}

		return $out;
	}

	/** Hard-trim to first N whitespace-separated words. F-12. */
	private static function cap_words( string $text, int $max_words ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( $text === '' || $max_words <= 0 ) {
			return $text;
		}
		$parts = preg_split( '/\s+/u', $text );
		if ( ! is_array( $parts ) || count( $parts ) <= $max_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $parts, 0, $max_words ) );
	}
}

// Back-compat alias — PHASE-0-RULE-NAMESPACE §2.2.
if ( ! class_exists( 'BZKG_Skeleton_Prompt' ) ) {
	class_alias( 'BizCity_KG_Skeleton_Prompt', 'BZKG_Skeleton_Prompt' );
}
