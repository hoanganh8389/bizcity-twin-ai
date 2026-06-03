<?php
/**
 * TwinBrain — Web Research Skills Seeder (TBR.W8-be-skills).
 *
 * Phase 0.36-UNIFIED §3.5 — Đăng ký 2 skill global vào `bizcity_skills`:
 *   • `web_search_quick`  — Quick Web (Stage 2.5, ~3-4s, 1 search + 1 LLM)
 *   • `web_research_deep` — Deep Web (Stage 2.5, ~8-12s, ReAct max 5 iters)
 *
 * Cả 2 skill scope GLOBAL (`user_id=0`, `character_id=0`) → tool intent
 * matcher có thể propose cho mọi turn khi user bật `web_mode != off`.
 *
 * Idempotent qua UNIQUE KEY `uk_skill_key_user (skill_key, user_id, character_id)`
 * của `bizcity_skills`. Re-run an toàn — `upsert()` sẽ UPDATE thay vì duplicate.
 *
 * Hook: `init` priority 30 (sau khi `BizCity_Skill_Database::maybe_install()`
 * tạo bảng ở priority < 20). Throttle 1 lần / day qua transient để tránh
 * INSERT trên mọi request.
 *
 * R-SKILL N1 — skills là Source of Truth. Tool dispatcher (TBR.W6/W7) đọc
 * skill_key + content (prompt template) từ bảng này thay vì hardcode trong PHP.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W8)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Skills_Seeder {

	const TRANSIENT_KEY = 'bizcity_twinbrain_web_skills_seeded_v1';
	const TTL_SECONDS   = DAY_IN_SECONDS;

	/**
	 * Skill definitions — single source of truth.
	 *
	 * `content` = Markdown prompt template loaded by W6/W7 engines.
	 * `triggers_json` = keywords gợi ý cho tool intent matcher (fallback khi
	 *                   embedding model offline; engine chính dùng cosine).
	 *
	 * @return array<int,array>
	 */
	public static function definitions(): array {
		return [
			[
				'skill_key'      => 'web_search_quick',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Search · Quick',
				'description'    => 'Trả lời nhanh bằng 1 lần search Tavily + 1 lần LLM synth (~3-4s). Dùng khi user bật chế độ Quick Web ở Ask Brain composer.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'tin mới nhất', 'mới nhất', 'hôm nay', 'giá', 'tỷ giá',
					'thời tiết', 'tin tức', 'cập nhật', 'breaking',
					'latest news', 'price', 'weather', 'today', 'current',
				],
				'slash_commands' => [ '/quick_web', '/web_quick' ],
				'modes'          => [ 'research', 'execution' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::quick_prompt_template(),
				'priority'       => 30,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_research_deep',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Deep',
				'description'    => 'Nghiên cứu sâu bằng ReAct agent (search + extract + reasoning, max 5 iters, ~8-12s). Dùng khi user bật chế độ Deep Web ở Ask Brain composer.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'so sánh', 'phân tích', 'nghiên cứu', 'tổng hợp',
					'review', 'đánh giá', 'điểm khác biệt', 'pros and cons',
					'compare', 'analyze', 'research', 'deep dive',
					'investigation', 'comprehensive',
				],
				'slash_commands' => [ '/deep_web', '/web_deep', '/deep_research_web' ],
				'modes'          => [ 'research' ],
				'tools_json'     => [ 'search_web', 'extract_web', 'crawl_web', 'llm_chat' ],
				'content'        => self::deep_prompt_template(),
				'priority'       => 35,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_med',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Medical (Y khoa)',
				'description'    => 'Nghiên cứu y khoa bám allowlist (pubmed, nih, who, cdc, mayoclinic, NEJM, Lancet, JAMA, BMJ, Cochrane, Bộ Y tế VN, suckhoedoisong.vn…) — 1 Tavily search advanced + 1 LLM synth (~5-7s). Buộc disclaimer y tế + cap stance=conditional. Dùng cho câu hỏi triệu chứng / chẩn đoán / thuốc / liệu dùng / tác dụng phụ / bệnh mạn / vaccine. KHÔNG thay thế bác sĩ.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'triệu chứng', 'bệnh', 'chẩn đoán', 'điều trị', 'liệu pháp',
					'phác đồ', 'liều dùng', 'thuốc', 'tác dụng phụ', 'vaccine',
					'vắc xin', 'đau ngực', 'sốt', 'tiểu đường', 'huyết áp',
					'ung thư', 'tim mạch', 'mang thai', 'trẻ em', 'sức khỏe',
					'symptom', 'disease', 'treatment', 'diagnosis', 'dosage',
					'medication', 'medical', 'pubmed', 'cochrane',
				],
				'slash_commands' => [ '/med', '/medical', '/web_med' ],
				'modes'          => [ 'research', 'execution' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::med_prompt_template(),
				'priority'       => 40,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_scholar',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Scholar (Học thuật)',
				'description'    => 'Nghiên cứu học thuật bám allowlist (arxiv, doi, nature, sciencedirect, pubmed, ieee, acm, semanticscholar, ssrn, MIT, Stanford…) — 1 Tavily search advanced + 1 LLM synth (~5-7s). Cite (Author, Year) + [sch:N#URL]. Phân biệt pre-print vs peer-reviewed.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'paper', 'nghiên cứu', 'arxiv', 'doi', 'meta-analysis',
					'systematic review', 'cohort', 'rct', 'thực nghiệm',
					'literature review', 'citation', 'academic',
					'scholar', 'pubmed', 'preprint', 'peer-review',
				],
				'slash_commands' => [ '/scholar', '/academic', '/web_scholar' ],
				'modes'          => [ 'research' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::scholar_prompt_template(),
				'priority'       => 41,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_nutri',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Nutrition (Dinh dưỡng)',
				'description'    => 'Nghiên cứu dinh dưỡng bám allowlist (Harvard NutritionSource, WHO, FAO, NIH, USDA, examine.com, Viện Dinh Dưỡng VN, Bộ Y tế…) — 1 Tavily search advanced + 1 LLM synth. Mọi claim sức khỏe yêu cầu ≥2 nguồn. Disclaimer 🥗.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'dinh dưỡng', 'khẩu phần', 'calo', 'protein', 'chất béo',
					'carb', 'vitamin', 'khoáng chất', 'thực phẩm', 'ăn kiêng',
					'giảm cân', 'tăng cân', 'chế độ ăn', 'thực đơn',
					'nutrition', 'diet', 'calorie', 'macro', 'micronutrient',
					'rda', 'eating', 'meal plan',
				],
				'slash_commands' => [ '/nutri', '/nutrition', '/web_nutri' ],
				'modes'          => [ 'research', 'execution' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::nutri_prompt_template(),
				'priority'       => 42,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_law',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Pháp luật VN',
				'description'    => 'Tra cứu văn bản pháp luật bám allowlist (congbao.chinhphu.vn, vbpl.vn, thuvienphapluat.vn, luatvietnam.vn, quochoi.vn, các Bộ .gov.vn) — 1 Tavily search advanced + 1 LLM synth. Cite loại VB + số hiệu + ngày + cơ quan. Phân biệt Luật/NĐ/TT. Cảnh báo VB hết hiệu lực. Disclaimer 📜.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'luật', 'nghị định', 'thông tư', 'quyết định', 'bộ luật',
					'điều luật', 'pháp luật', 'quy định', 'hợp đồng', 'tranh chấp',
					'kiện', 'tố tụng', 'hình sự', 'dân sự', 'hành chính',
					'lao động', 'đất đai', 'hôn nhân', 'thừa kế',
					'law', 'legal', 'statute', 'decree', 'regulation',
				],
				'slash_commands' => [ '/law', '/luat', '/web_law' ],
				'modes'          => [ 'research', 'execution' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::law_prompt_template(),
				'priority'       => 43,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_tax',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Thuế VN',
				'description'    => 'Tra cứu chính sách thuế bám allowlist (tct.gov.vn, gdt.gov.vn, mof.gov.vn, customs.gov.vn, thuvienphapluat.vn, vbpl.vn) — 1 Tavily search advanced + 1 LLM synth. Cite loại VB + số hiệu + năm + đối tượng (TNCN/TNDN/GTGT/TTĐB). Cảnh báo CV cá biệt. Disclaimer 💰.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'thuế', 'thuế suất', 'tncn', 'tndn', 'gtgt', 'ttđb',
					'hóa đơn', 'hoàn thuế', 'kê khai thuế', 'quyết toán',
					'mã số thuế', 'người nộp thuế', 'truy thu', 'phạt thuế',
					'công văn thuế', 'tổng cục thuế', 'chi cục thuế',
					'tax', 'vat', 'income tax', 'corporate tax',
				],
				'slash_commands' => [ '/tax', '/thue', '/web_tax' ],
				'modes'          => [ 'research', 'execution' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::tax_prompt_template(),
				'priority'       => 44,
				'status'         => 'active',
				'version'        => '1.0',
			],
			[
				'skill_key'      => 'web_search_gov',
				'user_id'        => 0,
				'character_id'   => 0,
				'title'          => 'Web Research · Chính sách / VBQPPL mới',
				'description'    => 'Tin chính sách / VBQPPL mới ban hành bám allowlist (chinhphu.vn, baochinhphu.vn, quochoi.vn, các Bộ .gov.vn, báo chính thống nhandan/vnexpress/tuoitre/thanhnien) — 1 Tavily news search advanced + 1 LLM synth. Default time_range=week. Cite số hiệu + ngày + cơ quan ban hành. Trung lập, factual.',
				'category'       => 'web-research',
				'triggers_json'  => [
					'chính sách', 'nghị quyết', 'thủ tướng', 'chính phủ',
					'quốc hội', 'bộ trưởng', 'công bố', 'ban hành',
					'hiệu lực', 'kế hoạch', 'chiến lược quốc gia',
					'tin chính phủ', 'thông cáo', 'họp báo chính phủ',
					'government policy', 'cabinet', 'decree announcement',
				],
				'slash_commands' => [ '/gov', '/chinhsach', '/web_gov' ],
				'modes'          => [ 'research' ],
				'tools_json'     => [ 'search_web', 'llm_chat' ],
				'content'        => self::gov_prompt_template(),
				'priority'       => 45,
				'status'         => 'active',
				'version'        => '1.0',
			],
		];
	}

	/**
	 * Boot hook — register on `init`.
	 */
	public static function register_hooks(): void {
		add_action( 'init', [ __CLASS__, 'maybe_seed' ], 30 );
	}

	/**
	 * Seed nếu chưa seed trong TTL. Throttle qua transient.
	 */
	public static function maybe_seed(): void {
		if ( get_transient( self::TRANSIENT_KEY ) ) {
			return;
		}
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			// Skills core chưa load — sẽ retry next request (KHÔNG set transient).
			return;
		}
		self::seed();
		set_transient( self::TRANSIENT_KEY, time(), self::TTL_SECONDS );
	}

	/**
	 * Force seed (CLI / activation hook). KHÔNG check transient.
	 *
	 * @return array{seeded:int, skipped:int, errors:array<int,string>}
	 */
	public static function seed(): array {
		$result = [ 'seeded' => 0, 'skipped' => 0, 'errors' => [] ];

		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			$result['errors'][] = 'BizCity_Skill_Database class missing';
			return $result;
		}

		$db = BizCity_Skill_Database::instance();
		if ( ! method_exists( $db, 'upsert' ) ) {
			$result['errors'][] = 'BizCity_Skill_Database::upsert() missing';
			return $result;
		}

		foreach ( self::definitions() as $def ) {
			$id = $db->upsert( $def );
			if ( $id ) {
				$result['seeded']++;
			} else {
				$result['errors'][] = sprintf( 'upsert FAIL: %s', $def['skill_key'] );
			}
		}

		return $result;
	}

	/**
	 * Quick mode prompt — 1 search → 1 LLM synth.
	 * Loaded by `BizCity_TwinBrain_Web_Quick::synthesize()` (TBR.W6).
	 */
	private static function quick_prompt_template(): string {
		return <<<MD
# Web Search · Quick

Bạn là trợ lý web search nhanh. Người dùng đã cung cấp câu hỏi + danh sách kết quả search từ web (top-K snippets). Nhiệm vụ của bạn:

1. **Tổng hợp ngắn gọn** câu trả lời dựa trên các snippets — KHÔNG bịa thông tin không có trong snippets.
2. **Trích dẫn** mọi mệnh đề bằng token `[web:N#URL]` trong đó `N` là index 1-based của result và `URL` là URL gốc (để FE render thành chip 🌐 host).
3. **Nếu snippets không đủ thông tin**, nói rõ "Snippets chưa đủ để trả lời chính xác, cần Deep Web".
4. **Giới hạn** ≤ 200 từ. Tiếng Việt nếu câu hỏi tiếng Việt.

## Format output

```
<câu trả lời súc tích với citation [web:1#https://...], [web:2#https://...]>
```

KHÔNG bullet list trừ khi câu hỏi yêu cầu liệt kê. KHÔNG markdown heading.
MD;
	}

	/**
	 * Deep mode prompt — ReAct agent (port from tavily-chat-main REASONING_PROMPT).
	 * Loaded by `BizCity_TwinBrain_Web_Deep::react_step()` (TBR.W7).
	 */
	private static function deep_prompt_template(): string {
		return <<<MD
# Web Research · Deep (ReAct)

Bạn là một agent nghiên cứu web sâu (ReAct pattern). Bạn có 3 tools:

- `search(query: string, max: int=5)` — Tavily search, trả top-K snippets.
- `extract(url: string)` — lấy full content + summary của 1 URL.
- `crawl(url: string, limit: int=10)` — crawl subpages của 1 site.

Quy trình mỗi iteration:

```
Thought: <suy nghĩ về step tiếp theo>
Action: search | extract | crawl | final
Action Input: <query hoặc URL>
Observation: <kết quả tool, tự động điền>
```

Khi đủ thông tin (≥ 3 sources chất lượng, hoặc đã reach max 5 iters), output:

```
Thought: Đã đủ thông tin để trả lời.
Action: final
Action Input: <câu trả lời chi tiết với citation [web:N#URL], [web:N#pP] nếu có page>
```

## Rules

1. **Tối đa 5 iterations**. Vượt → force `final` với những gì có.
2. **Trích dẫn bắt buộc** mọi claim bằng `[web:N#URL]`. KHÔNG citation = unverified.
3. **Tiếng Việt** nếu câu hỏi tiếng Việt; **English** nếu câu hỏi English.
4. **Nếu tools fail** (search/extract trả empty): nói rõ "Web sources không khả dụng cho query này" và `final` ngay.
5. **TENSIONS** với nguồn local (notebook perspectives): nếu phát hiện web nói khác local, ghi rõ "Web vs Local TENSION: ..." — Synthesizer cấp trên sẽ xử lý.
MD;
	}

	/**
	 * Med vertical prompt (TBR.W17 / Vertical Web Research Wave 1).
	 * Loaded by `BizCity_TwinBrain_Web_Med::load_prompt_template()`.
	 */
	private static function med_prompt_template(): string {
		return <<<MD
# Web Research · Medical (Y khoa)

Bạn là **medical evidence synthesizer**. User đã cung cấp câu hỏi y khoa + top-K snippets từ allowlist (pubmed.ncbi.nlm.nih.gov, nih.gov, who.int, cdc.gov, mayoclinic.org, NEJM, The Lancet, JAMA, BMJ, Cochrane, UpToDate, MedlinePlus, Bộ Y tế VN, suckhoedoisong.vn, ADA, Heart.org, Cancer.org, …).

## Quy tắc BẮT BUỘC

1. **Chỉ dùng snippets** — KHÔNG bịa, KHÔNG suy luận vượt khỏi dữ liệu cung cấp.
2. **Citation** mọi mệnh đề y học bằng `[med:N#URL]` (N = index 1-based).
3. **KHÔNG dùng** "chắc chắn", "khẳng định", "100%", "không thể sai". Dùng "theo nghiên cứu X", "bằng chứng hiện tại cho thấy", "guideline khuyến cáo".
4. **Sources không nhất quán** → nói rõ "Sources có ý kiến khác nhau" + cite cả hai bên (tier A ưu tiên).
5. **Disclaimer cuối bài** (BẮT BUỘC): `⚕️ Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn. Hãy gặp bác sĩ cho trường hợp cụ thể.`
6. **Cấp cứu** (nếu query về triệu chứng cấp tính: đột quỵ / đau tim / khó thở / co giật / ngộ độc): mở đầu phải có `🚨 Nếu là cấp cứu, hãy gọi 115 (VN) hoặc đến phòng cấp cứu gần nhất ngay.`
7. **Giới hạn** ≤ 260 từ. Tiếng Việt nếu câu hỏi tiếng Việt.

## Format output

```
<câu trả lời súc tích, có citation [med:1#https://...], [med:2#https://...]>

⚕️ *Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn.*
```

## Anti-patterns CẤM

- ❌ Khuyên liều thuốc cụ thể (mg, mL) cho 1 cá nhân cụ thể.
- ❌ Tự chẩn đoán bệnh dựa trên triệu chứng user mô tả ("bạn có thể bị X").
- ❌ Phủ định khuyến cáo của WHO/CDC/Bộ Y tế mà không cite source ngang tầm.
- ❌ Khẳng định 1 phương pháp "đã được khoa học chứng minh" khi chỉ có 1 source.
MD;
	}

	/** Scholar vertical prompt (TBR.W17). */
	private static function scholar_prompt_template(): string {
		return <<<MD
# Web Research · Scholar (Học thuật)

Bạn là **academic evidence synthesizer**. Tổng hợp paper từ allowlist (arxiv.org, doi.org, nature.com, sciencedirect.com, pubmed, ieee.org, acm.org, semanticscholar.org, jstor.org, ssrn.com, MIT/Stanford/Harvard, openreview, aclanthology…).

## Quy tắc BẮT BUỘC
1. **Chỉ dùng snippets** — KHÔNG bịa kết quả, số liệu, n=, p-value.
2. **Citation**: trong text ghi `(Tác giả/Tổ chức, Năm)` NẾU snippet có; sau đó kèm `[sch:N#URL]`.
3. **Phân biệt rõ**: pre-print (medrxiv/biorxiv/arxiv) vs peer-reviewed journal. Nêu rõ trong câu trả lời.
4. **Xung đột nghiên cứu** → nêu rõ + cite cả 2 bên.
5. **KHÔNG** "chắc chắn". Dùng "theo nghiên cứu X (Năm)", "meta-analysis Y cho thấy".
6. ≤ 260 từ, Tiếng Việt nếu câu hỏi tiếng Việt.

## Anti-patterns CẤM
- ❌ Trích kết quả pre-print như đã peer-reviewed.
- ❌ Bịa tên tác giả / năm xuất bản nếu snippet không có.
- ❌ Generalize từ 1 paper nhỏ → "đã được chứng minh".
MD;
	}

	/** Nutrition vertical prompt (TBR.W17). */
	private static function nutri_prompt_template(): string {
		return <<<MD
# Web Research · Nutrition (Dinh dưỡng)

Bạn là **nutrition evidence synthesizer**. Tổng hợp dinh dưỡng từ allowlist (Harvard NutritionSource, WHO, FAO, NIH ODS, USDA, examine.com, eatright.org, Viện Dinh Dưỡng VN, Bộ Y tế VN, Mayo Clinic…).

## Quy tắc BẮT BUỘC
1. **Chỉ dùng snippets** — KHÔNG bịa số liệu RDA / khẩu phần / hàm lượng.
2. **Citation** dạng `[nut:N#URL]`.
3. **MỌI claim sức khỏe** (giảm cân, phòng bệnh X, tăng cường Y) phải có ≥2 nguồn ủng hộ. Nếu <2 → ghi rõ "bằng chứng còn hạn chế".
4. **Sources xung đột** → nêu rõ + cite cả 2 bên.
5. **Disclaimer cuối bài** (BẮT BUỘC): `🥗 Thông tin tham khảo — tham vấn chuyên gia dinh dưỡng cho chế độ cá nhân hoá.`
6. ≤ 260 từ.

## Anti-patterns CẤM
- ❌ Khuyến cáo khẩu phần cụ thể (g/kg/ngày) cho 1 cá nhân.
- ❌ Trích blog cá nhân / influencer làm authority.
- ❌ Phủ định khuyến cáo của WHO/FAO không cite source ngang tầm.
- ❌ Khẳng định "thực phẩm X chữa bệnh Y".
MD;
	}

	/** Law vertical prompt (TBR.W17). */
	private static function law_prompt_template(): string {
		return <<<MD
# Web Research · Pháp luật VN

Bạn là **legal evidence synthesizer** cho hệ thống pháp luật Việt Nam. Tổng hợp văn bản từ allowlist (congbao.chinhphu.vn, vbpl.vn, thuvienphapluat.vn, luatvietnam.vn, quochoi.vn, các Bộ .gov.vn).

## Quy tắc BẮT BUỘC
1. **Chỉ dùng snippets** — KHÔNG bịa số hiệu / điều khoản / ngày ban hành.
2. **Citation BẮT BUỘC**: trong text ghi rõ `loại VB + số hiệu + ngày ban hành + cơ quan` (vd: "Nghị định 100/2019/NĐ-CP ngày 30/12/2019 của Chính phủ"), kèm `[law:N#URL]`.
3. **Thứ bậc pháp lý**: Luật (Quốc hội) > Nghị định (Chính phủ) > Thông tư (Bộ) > Quyết định/Công văn.
4. **Cảnh báo** nếu VB đã hết hiệu lực / được sửa đổi bởi VB sau.
5. **KHÔNG** kết luận pháp lý cá nhân ("bạn được/không được X"). Dùng "theo VB Y, quy định là...".
6. **Disclaimer cuối bài**: `📜 Thông tin tham khảo — cần luật sư/chuyên gia pháp lý tư vấn cho trường hợp cụ thể.`
7. ≤ 280 từ.

## Anti-patterns CẤM
- ❌ Đưa lời khuyên hành động pháp lý cụ thể.
- ❌ Khẳng định "chắc chắn thắng/thua kiện".
- ❌ Bịa điều khoản / số hiệu nếu snippet không có.
MD;
	}

	/** Tax vertical prompt (TBR.W17). */
	private static function tax_prompt_template(): string {
		return <<<MD
# Web Research · Thuế VN

Bạn là **tax evidence synthesizer** cho hệ thống thuế Việt Nam. Tổng hợp văn bản từ allowlist (tct.gov.vn, gdt.gov.vn, mof.gov.vn, customs.gov.vn, thuvienphapluat.vn, vbpl.vn, chinhphu.vn).

## Quy tắc BẮT BUỘC
1. **Chỉ dùng snippets** — KHÔNG bịa thuế suất / công thức tính.
2. **Citation BẮT BUỘC**: ghi rõ `loại VB + số hiệu + năm` (vd "Thông tư 78/2014/TT-BTC", "Công văn 1234/TCT-DNNCN"), kèm `[tax:N#URL]`.
3. **Nêu rõ ĐỐI TƯỢNG ÁP DỤNG**: TNCN / TNDN / GTGT / TTĐB / XNK + **KỲ HIỆU LỰC** (năm tính thuế).
4. **CẢNH BÁO** nếu nguồn là CÔNG VĂN CÁ BIỆT (CV xxx/TCT-...): "chỉ áp dụng cho NNT cụ thể trong CV, KHÔNG có giá trị pháp lý chung".
5. **Cảnh báo** nếu thuế suất / chính sách đã thay đổi → ghi rõ kỳ áp dụng.
6. **KHÔNG** kết luận nghĩa vụ thuế cá nhân. Dùng "theo TT/NĐ X, thuế suất là Y%".
7. **Disclaimer cuối bài**: `💰 Thông tin tham khảo — chính sách thuế thay đổi theo năm. Cần đại lý thuế / kế toán tư vấn cho trường hợp cụ thể.`
8. ≤ 280 từ.

## Anti-patterns CẤM
- ❌ Tính cụ thể số thuế phải nộp cho 1 doanh nghiệp / cá nhân.
- ❌ Trích công văn cá biệt mà không cảnh báo "không giá trị pháp lý chung".
- ❌ Bịa thuế suất nếu snippet không có.
MD;
	}

	/** Gov vertical prompt (TBR.W17). */
	private static function gov_prompt_template(): string {
		return <<<MD
# Web Research · Chính sách / VBQPPL mới

Bạn là **gov policy synthesizer** cho VN. Tổng hợp tin chính sách / VBQPPL mới ban hành từ allowlist tier A-D (chinhphu.vn, baochinhphu.vn, congbao, quochoi.vn, các Bộ .gov.vn; báo chính thống nhandan/vnexpress/tuoitre/thanhnien/vov/vtv).

## Quy tắc BẮT BUỘC
1. **Chỉ dùng snippets** — KHÔNG bịa số hiệu / ngày ban hành.
2. **Citation BẮT BUỘC**: ghi rõ `số hiệu + ngày ban hành + cơ quan` (vd "Quyết định 1234/QĐ-TTg ngày 15/05/2026 của Thủ tướng"), kèm `[gov:N#URL]`.
3. **Phân biệt NGUỒN**: sơ cấp (chinhphu.vn, congbao, bộ .gov.vn) vs thứ cấp (nhandan/vnexpress). Ưu tiên trích sơ cấp.
4. **Nêu rõ HIỆU LỰC**: từ ngày nào, áp dụng cho đối tượng nào (nếu snippet có).
5. **Trung lập, factual** — KHÔNG bình luận chính trị, KHÔNG đánh giá chủ quan.
6. ≤ 280 từ.

## Anti-patterns CẤM
- ❌ Bình luận chính trị / dự đoán ý đồ chính sách.
- ❌ Trích báo thứ cấp khi nguồn sơ cấp có sẵn trong snippets.
- ❌ Bịa nội dung Quyết định / Chỉ thị.
MD;
	}
}

BizCity_TwinBrain_Web_Skills_Seeder::register_hooks();

/* ────────────────────────────────────────────────────────────────────────
 * WP-CLI:  wp bizcity diag web-skills-seed
 *
 * Force seed bypass transient — dùng khi update prompt template hoặc reset
 * sau khi xoá row bằng tay từ DB.
 * ──────────────────────────────────────────────────────────────────────── */
if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'BizCity_CLI_Web_Skills_Seed', false ) ) {

	final class BizCity_CLI_Web_Skills_Seed {

		/**
		 * Force seed TBR.W8 web research skills vào `bizcity_skills`.
		 *
		 * ## EXAMPLES
		 *
		 *     wp bizcity diag web-skills-seed
		 *
		 * @when after_wp_load
		 */
		public function __invoke( $args, $assoc ) {
			delete_transient( BizCity_TwinBrain_Web_Skills_Seeder::TRANSIENT_KEY );
			$res = BizCity_TwinBrain_Web_Skills_Seeder::seed();

			WP_CLI::log( '── TBR.W8 · web-skills-seed ──' );
			WP_CLI::log( sprintf( 'Seeded  : %d', $res['seeded'] ) );
			WP_CLI::log( sprintf( 'Skipped : %d', $res['skipped'] ) );

			if ( ! empty( $res['errors'] ) ) {
				foreach ( $res['errors'] as $err ) {
					WP_CLI::warning( $err );
				}
				WP_CLI::error( 'Seed completed với errors.' );
			}

			WP_CLI::success( sprintf( 'TBR.W8 PASS — %d skill(s) registered.', $res['seeded'] ) );
		}
	}

	WP_CLI::add_command( 'bizcity diag web-skills-seed', 'BizCity_CLI_Web_Skills_Seed' );
}
