<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_Templates_Seeder — idempotent seeder for 5 built-in
 * workflow templates (BE-7.C).
 *
 * Triggered by `BizCity_Automation_Templates_Seeder::maybe_seed()` on
 * `admin_init` priority 6 (AFTER `BizCity_Automation_Installer::ensure()`
 * priority 5). Seed version stamped in option
 * `bizcity_automation_templates_seed_version` — bump constant SEED_VERSION
 * whenever any blueprint JSON changes.
 *
 * Each blueprint uses NATIVE block ids that already exist in the FE registry
 * (`core/automation/frontend/src/blocks/registry.js`) so instantiated
 * workflows render correctly in the xyflow builder out of the box.
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Templates_Seeder {

	// [2026-06-02 Johnny Chu] SEED W1 — bump bao gồm tpl_knowledge_router_v1
	// + tpl_remember_this_v1 (xem PHASE-SEED-TEMPLATES-AND-GURU-TRIGGER.md).
	// [2026-06-03 Johnny Chu] WF-AUTO W4 — Wave D: thêm tpl_slash_kg_query_v1
	// + tpl_skill_intent_invoke_v1 (slash_command + skill_intent triggers GURU W2).
	// [2026-06-07 Johnny Chu] CRM-PATH-2 — 3 care templates zone=crm:
	// tpl_zalo_oa_auto_reply_v1, tpl_zalo_classify_route_v1, tpl_zalo_tag_assign_v1.
	// [2026-06-07 Johnny Chu] SEED-DEPLAO — 5 Deplao CRM-style templates (static keyword-based,
	// no AI): keyword chain / lead collect / FB comment / out-of-hours / payment confirm.
	// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — +25 templates (W1-W25, reach 55 total).
	// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — bump for 2 Veo 3 video templates.
	// [2026-06-15 Johnny Chu] R-UNIFY — bump for tpl_reminder_personal_zalo_bot_v1.
	// [2026-06-15 Johnny Chu] PHASE-0 — bump for tpl_schedule_event_v1 fix (extract.output.* + user_id).
	// [2026-06-16 Johnny Chu] AUTOMATION-CAL — +3 daily FB cron templates (8h/9h/10h) R-DCL compliant.
	// [2026-07-03 Johnny Chu] PHASE-ASTRO-WORKFLOW — +3 astro templates (van_han_zalo + quick_zalo + daily_cron)
	// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — astro-zalobot template adds deterministic best-day selector.
	// [2026-07-05 Johnny Chu] PHASE-ASTRO-WORKFLOW — daily astro cron template moved to 09:00 + focused brief.
	const SEED_VERSION    = '1.44.0'; // [2026-07-08 Johnny Chu] PHASE-ATH — astro relation profile zalo template.
	const VERSION_OPTION  = 'bizcity_automation_templates_seed_version';
	const HASH_OPTION     = 'bizcity_automation_templates_seed_hash';

	public static function maybe_seed(): void {
		// [2026-07-10 Johnny Chu] PHASE-ATH — allow seed checks in admin/REST/WP-CLI so Template Gallery REST sees latest blueprints.
		$in_seed_ctx = is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $in_seed_ctx ) { return; }

		$stamped      = (string) get_option( self::VERSION_OPTION, '' );
		$stamped_hash = (string) get_option( self::HASH_OPTION, '' );
		$current_hash = self::current_blueprints_hash();

		// [2026-07-10 Johnny Chu] PHASE-ATH — reseed when either SEED_VERSION or blueprint fingerprint changes.
		if ( $stamped === self::SEED_VERSION && $stamped_hash !== '' && hash_equals( $stamped_hash, $current_hash ) ) { return; }

		self::seed_all();
		update_option( self::VERSION_OPTION, self::SEED_VERSION, false );
		update_option( self::HASH_OPTION, $current_hash, false );
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — After seeding locally on main site,
		// push all builtin templates to Hub so sub-sites can browse via HubTemplateTab API
		// instead of relying on per-blog seeded copies (which caused "table is full" errors).
		// Runs on a deferred admin_init action to not block current request.
		// [2026-07-10 Johnny Chu] PHASE-ATH — sync-to-hub remains main-site-only; local seeding now runs per blog.
		if ( ! function_exists( 'is_main_site' ) || is_main_site() ) {
			add_action( 'admin_init', array( __CLASS__, 'sync_to_hub' ), 99 );
		}
	}

	public static function force_reseed(): array {
		$rows = self::seed_all();
		update_option( self::VERSION_OPTION, self::SEED_VERSION, false );
		// [2026-07-10 Johnny Chu] PHASE-ATH — keep fingerprint in sync for manual reseed path.
		update_option( self::HASH_OPTION, self::current_blueprints_hash(), false );
		return $rows;
	}

	/**
	 * Run all blueprints through repo upsert (idempotent).
	 *
	 * @return array<int,array{slug:string,result:string}>
	 */
	public static function seed_all(): array {
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) ) { return array(); }
		$out = array();
		foreach ( self::blueprints() as $blueprint ) {
			$res = BizCity_Automation_Repo_Templates::upsert( $blueprint );
			$out[] = array(
				'slug'   => (string) $blueprint['slug'],
				'result' => is_wp_error( $res ) ? 'error:' . $res->get_error_message() : 'ok',
			);
		}
		return $out;
	}

	/**
	 * Deterministic hash for all blueprint definitions (unique by slug).
	 * Used to auto-reseed when JSON/PHP blueprints change but SEED_VERSION wasn't bumped yet.
	 *
	 * @return string
	 */
	public static function current_blueprints_hash(): string {
		$slug_map = array();
		foreach ( self::blueprints() as $blueprint ) {
			if ( ! is_array( $blueprint ) || empty( $blueprint['slug'] ) ) {
				continue;
			}
			$slug_map[ (string) $blueprint['slug'] ] = md5( (string) wp_json_encode( $blueprint ) );
		}
		if ( empty( $slug_map ) ) {
			return '';
		}
		ksort( $slug_map );
		return md5( (string) wp_json_encode( $slug_map ) );
	}

	/**
	 * Push all builtin templates to Hub (idempotent, safe to re-run).
	 *
	 * [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — Converts local seeder blueprints to Hub
	 * format and calls BizCity_Automation_Hub_Client::sync_bulk(). Hub stores them with
	 * status='published'. Sub-sites then read from Hub via HubTemplateTab (R-AUTO-HUB).
	 *
	 * Called from maybe_seed() on deferred admin_init:99 (main site only).
	 * Also exposed for manual WP-CLI trigger: wp eval 'BizCity_Automation_Templates_Seeder::sync_to_hub();'
	 */
	public static function sync_to_hub(): void {
		if ( ! class_exists( 'BizCity_Automation_Hub_Client' ) ) { return; }
		if ( ! class_exists( 'BizCity_Automation_Repo_Templates' ) ) { return; }

		// Only run on main site
		if ( function_exists( 'is_main_site' ) && ! is_main_site() ) { return; }

		$hub     = BizCity_Automation_Hub_Client::instance();
		$result_q = BizCity_Automation_Repo_Templates::query( array( 'source' => 'builtin', 'limit' => 200 ) );
		$all_tpl  = isset( $result_q['rows'] ) ? $result_q['rows'] : array();

		if ( empty( $all_tpl ) || ! is_array( $all_tpl ) ) { return; }

		// Normalize to Hub schema
		$hub_rows = array();
		foreach ( $all_tpl as $tpl ) {
			if ( empty( $tpl['slug'] ) ) { continue; }

			$trigger_type = '';
			if ( ! empty( $tpl['graph_json'] ) ) {
				$graph = json_decode( $tpl['graph_json'], true );
				if ( is_array( $graph ) && ! empty( $graph['nodes'] ) ) {
					foreach ( $graph['nodes'] as $node ) {
						if ( isset( $node['type'] ) && strpos( (string) $node['type'], 'trigger.' ) === 0 ) {
							$trigger_type = str_replace( 'trigger.', '', (string) $node['type'] );
							break;
						}
					}
				}
			}

			$tags = array();
			if ( ! empty( $tpl['category'] ) ) { $tags[] = (string) $tpl['category']; }
			if ( $trigger_type ) { $tags[] = $trigger_type; }

			$hub_rows[] = array(
				'slug'         => (string) $tpl['slug'],
				'name'         => (string) ( $tpl['name']        ?? $tpl['slug'] ),
				'description'  => (string) ( $tpl['description'] ?? '' ),
				'category'     => (string) ( $tpl['category']    ?? 'general' ),
				'tags'         => $tags,
				'plan'         => (string) ( $tpl['plan']        ?? 'free' ),
				'trigger_type' => $trigger_type,
				'author'       => 'Johnny Chu',
				'graph_json'   => (string) ( $tpl['graph_json']  ?? '' ),
			);
		}

		if ( empty( $hub_rows ) ) { return; }

		$result = $hub->sync_bulk( $hub_rows );
		if ( ! empty( $result['_degraded'] ) ) {
			error_log( '[bizcity-automation] sync_to_hub degraded: ' . ( $result['_reason'] ?? 'unknown' ) );
		} else {
			error_log( sprintf(
				'[bizcity-automation] sync_to_hub OK — inserted=%d updated=%d skipped=%d',
				(int) ( $result['inserted'] ?? 0 ),
				(int) ( $result['updated']  ?? 0 ),
				(int) ( $result['skipped']  ?? 0 )
			) );
		}
	}

	// ─── Blueprints ──────────────────────────────────────────────────────

	/**
	 * @return array<int,array<string,mixed>>
	 */
	/**
	 * Toàn bộ blueprints = GROUP 1 (builtin mặc định) + GROUP 2 (automation tương lai).
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH — tách thành 2 nhóm rõ ràng.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function blueprints(): array {
		return array_merge(
			self::builtin_blueprints(),
			self::future_blueprints()
		);
	}

	/**
	 * GROUP 1 — Built-in mặc định (default templates đi theo code).
	 *
	 * Đây là các template gốc, luôn có mặt sau khi cài plugin.
	 * Bao gồm: CSKH Zalo/FB, Lead, MPR, Cron report, Webhook,
	 * TwinBrain, Image, WooCommerce, CRM care, Skill,
	 * Slash triggers, Video Veo3, Personal reminder, v.v.
	 *
	 * [2026-06-16 Johnny Chu] PHASE-ATH — extracted from blueprints().
	 * [2026-06-28 Johnny Chu] PHASE-ATH — load từ JSON files (builtin-*.json) trước;
	 * PHP bp_* functions là fallback khi JSON chưa tồn tại.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function builtin_blueprints(): array {
		// [2026-06-28 Johnny Chu] PHASE-ATH — load builtin JSON files trước, PHP là fallback
		$json_blueprints = self::load_builtin_json_blueprints();
		if ( ! empty( $json_blueprints ) ) {
			return $json_blueprints;
		}

		// Fallback: PHP inline definitions (giữ nguyên khi JSON chưa có)
		return array(
			// ── Core CSKH & Lead ──────────────────────────────────────────────
			self::bp_zalo_cskh(),
			self::bp_fb_lead_capture(),
			self::bp_mpr_auto_reply(),
			self::bp_cron_daily_report(),
			self::bp_webhook_to_crm(),
			self::bp_tb_web_search_alert(),
			// ── Zalo Bot pilots ──────────────────────────────────────────────
			self::bp_zalo_pilot_keyword(),       // BE-7.B pilot 1
			self::bp_zalo_pilot_fallback_brain(),// BE-7.B pilot 2 — fallback to TwinBrain
			// ── Image / Web / FB publishing ─────────────────────────────────
			self::bp_image_capture(),            // BE-7.C — turn 1 of multi-turn
			self::bp_post_web_with_image(),      // BE-7.C — đăng web kèm ảnh
			self::bp_post_web_image_first(),     // PG-S9-fix — Logic 1: ảnh trước → keyword sau
			self::bp_post_fb_image_first(),      // PG-S9-fix v6 — Logic 2: ảnh trước → đăng fb sau
			self::bp_reminder_calendar(),        // PG-S9-fix v6 — Logic 3: nhắc lịch → CRM event
			self::bp_post_fb_with_image(),       // BE-7.C — đăng FB kèm ảnh
			self::bp_schedule_event(),           // BE-7.D — "lên lịch" generic CRM event
			// ── Smoke tests ──────────────────────────────────────────────────
			self::bp_test_smoke_manual(),        // BE-7.E — smoke test ngắn nhất
			self::bp_test_smoke_branch(),        // BE-7.E — smoke test có condition
			// ── SEED W1 — Knowledge & Memory ─────────────────────────────────
			self::bp_knowledge_router(),         // SEED W1 — @hỏi → TwinBrain MPR thinking
			self::bp_remember_this(),            // SEED W1 — @nhớ → CRM event (memory_note kind)
			// ── WF-AUTO W4 — GURU triggers ───────────────────────────────────
			self::bp_slash_kg_query(),           // WF-AUTO W4 — /kg slash → KG search → LLM → reply
			self::bp_skill_intent_invoke(),      // WF-AUTO W4 — skill_intent → invoke_skill → log
			// ── CRM-PATH-2 — Care templates Zone 1 (zone=crm) ──────────────
			self::bp_care_zalo_oa_auto_reply(),  // CRM-PATH-2 — Zalo OA auto-reply CSKH
			self::bp_care_zalo_classify_route(), // CRM-PATH-2 — classify ý định → route phòng ban
			self::bp_care_zalo_tag_assign(),     // CRM-PATH-2 — tag + assign nhân viên chăm sóc
			// ── CRM-PATH-5 — Facebook Messenger ─────────────────────────────
			self::bp_care_fb_messenger_reply(),  // CRM-PATH-5 — FB Messenger CSKH auto-reply
			// ── PHASE-CG-CF7 — CF7 lead capture + ebook autoresponder ────────
			self::bp_cf7_ebook_autoresponder(),  // PHASE-CG-CF7 — CF7 submit → email ebook SMTP + CRM lead
			// ── SEED-DEPLAO — Static keyword templates ────────────────────────
			self::bp_deplao_keyword_chain(),     // SEED-DEPLAO 1 — trả lời theo từ khoá
			self::bp_deplao_lead_collect(),      // SEED-DEPLAO 2 — thu thập lead / tư vấn
			self::bp_deplao_fb_comment_lead(),   // SEED-DEPLAO 3 — FB comment → CRM lead
			self::bp_deplao_out_of_hours(),      // SEED-DEPLAO 4 — ngoài giờ tự trả lời
			self::bp_deplao_order_confirm(),     // SEED-DEPLAO 5 — webhook thanh toán → log + CRM
			// ── PHASE-0.41 CRM-PATH-3 — 25 templates (W1-W25) ──────────────
			self::bp_woo_order_created(),        // W1 — Woo đơn mới → CRM + Zalo notify
			self::bp_woo_order_shipped(),        // W2 — Woo shipped → Zalo theo dõi đơn
			self::bp_woo_abandoned_cart(),       // W3 — Giỏ bỏ dở → nhắc Zalo (webhook)
			self::bp_woo_refund_notify(),        // W4 — Hoàn tiền → log + Zalo thông báo
			self::bp_woo_low_stock_alert(),      // W5 — Sắp hết hàng → email admin
			self::bp_crm_new_contact_welcome(),  // W6 — Liên hệ mới → welcome Zalo
			self::bp_crm_label_assigned_notify(), // W7 — Label gán → assign + Zalo thông báo NV
			self::bp_crm_sla_breach_escalate(),  // W8 — SLA quá hạn → gán lead + email manager
			self::bp_crm_conv_resolved_csat(),   // W9 — Đóng hội thoại → gửi CSAT Zalo
			self::bp_crm_stale_lead_remind(),    // W10 — Lead nguội (cron) → nhắc NV
			self::bp_internal_daily_standup(),   // W11 — Cron 9h mỗi ngày → Zalo standup
			self::bp_internal_weekly_report(),   // W12 — Cron Thứ 2 → Zalo tổng hợp tuần
			self::bp_internal_task_overdue(),    // W13 — Cron check task quá hạn → Zalo
			self::bp_zalo_image_classify(),      // W14 — Ảnh gửi Zalo → LLM classify + ghi CRM
			self::bp_zalo_pdf_extract(),         // W15 — PDF/file gửi Zalo → trích nội dung vào KG
			self::bp_zalo_voice_transcribe(),    // W16 — Voice → transcribe → LLM tóm tắt → ghi CRM
			self::bp_loyalty_points_earned(),    // W17 — CRM event tích điểm → Zalo báo số dư
			self::bp_loyalty_tier_upgrade(),     // W18 — Lên hạng thành viên → Zalo chúc mừng
			self::bp_campaign_started_notify(),  // W19 — Campaign bắt đầu → Zalo thông báo team
			self::bp_appointment_reminder(),     // W20 — Lịch hẹn 24h trước → Zalo nhắc khách
			self::bp_invoice_overdue_remind(),   // W21 — Hóa đơn quá hạn → email + CRM note
			self::bp_ai_summarise_thread(),      // W22 — Keyword "tóm tắt" → LLM tóm hội thoại
			self::bp_http_to_crm_contact(),      // W23 — HTTP webhook form → CRM contact upsert
			self::bp_zalo_menu_bot(),            // W24 — Menu bot: số → trả câu trả lời tĩnh
			self::bp_broadcast_cron_segment(),   // W25 — Cron phân đoạn → broadcast campaign nhóm
			// ── VIDEO-VEO3 — Zalo Bot + Veo 3 video ─────────────────────────
			self::bp_zalobot_img_capture_v1(),   // VIDEO-VEO3 A — Zalo Bot nhận ảnh → capture
			self::bp_zalobot_video_veo3_v1(),    // VIDEO-VEO3 B — keyword "tạo video" → Veo 3 → poll → reply
			// ── R-UNIFY — Personal reminder ──────────────────────────────────
			self::bp_reminder_personal_zalo_bot(), // R-UNIFY 1.16.0 — nhắc tôi → reminder_personal → Zalo Bot
			// ── PHASE-ZALOBOT — Zalo Bot 3-step reply patterns ───────────────
			self::bp_zalobot_web_research_steps(), // PHASE-ZALOBOT — "nghiên cứu X" → 3-step: ack / nguồn / kết luận
			self::bp_zalobot_astro_steps(),        // PHASE-ZALOBOT — "chiêm tinh" → 3-step: tra cứu / link chart / luận giải
		); // end fallback PHP array
	}

	/**
	 * GROUP 2 — Automation templates mới (load từ core/automation/templates/*.json).
	 *
	 * [2026-06-28 Johnny Chu] PHASE-ATH — Tách toàn bộ future blueprints ra JSON files
	 * dưới core/automation/templates/. PHP bp_* functions giữ lại nhưng chỉ còn
	 * là fallback khi file JSON tương ứng chưa tồn tại.
	 * Thêm template mới: tạo JSON trong templates/, không cần sửa PHP.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function future_blueprints(): array {
		// [2026-06-28 Johnny Chu] PHASE-ATH — load từ JSON files trước, PHP là fallback
		$json_blueprints = self::load_json_blueprints();
		if ( ! empty( $json_blueprints ) ) {
			return $json_blueprints;
		}

		// Fallback: PHP inline definitions (giữ nguyên khi JSON chưa có)
		return array(
			// ── AUTOMATION-CAL — Daily cron FB post (8h / 9h / 10h) ─────────
			self::bp_daily_fb_post_8h(),         // AUTOMATION-CAL C1 — Cron 8h → KG topic → LLM caption → FB post
			self::bp_daily_fb_post_9h(),         // AUTOMATION-CAL C2 — Cron 9h → KG product → LLM CTA → FB post
			self::bp_daily_fb_post_10h(),        // AUTOMATION-CAL C3 — Cron 10h → KG tips → LLM knowledge → FB post
			// ── PHASE-ATH W10 — Web research + Notebook content generation ──
			self::bp_daily_research_zalo(),      // W10 B1 — Cron 8h → web_research → generate_content(script) → reply_zalo
			self::bp_daily_notebook_fb_post(),   // W10 B2 — Cron 8h → generate_content(fb_post,notebook) → publish_fb_post
			self::bp_daily_notebook_wp_post(),   // W10 B3 — Cron 9h → generate_content(web_post,notebook) → publish_wp_post(draft)
			// ── PHASE-HOME-ARCH — Personal Assistant action templates ────────
			self::bp_personal_task_zalo_v1(),    // ARCH P1 — Zalo "việc:" → create_task → reply_zalo
			self::bp_personal_finance_zalo_v1(), // ARCH P2 — Zalo "chi:"/"thu:" → save_finance → reply_zalo
			self::bp_personal_journal_evening_v1(), // ARCH P3 — Cron 21h → reply_zalo (nhật ký prompt)
			self::bp_personal_daily_summary_v1(), // ARCH P4 — Cron 7h → reply_zalo morning summary
			// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — save_note template
			self::bp_personal_note_zalo_v1(),    // NOTEBOOKS N1 — Zalo "note:" → save_note(.md + KG) → reply_zalo
			// ── PHASE-TRENDING W1 — Multi-source trending research ──────────
			self::bp_daily_trending_digest_v1(),   // TRENDING T1 — Cron 9h → trending_research → reply_zalo
			self::bp_ondemand_trending_zalo_v1(),  // TRENDING T2 — Zalo "@trending topic" → research 7d → reply_zalo
			self::bp_zalo_trending_vn_v1(),        // TRENDING T3 — Zalo từ khóa tiếng Việt (xu hướng/trend hôm nay) → research 1d → reply_zalo (quick test)
		);
	}

	/**
	 * Đọc toàn bộ *.json files trong core/automation/templates/ (ngoại trừ
	 * builtin-catalog.json và thư mục con web-skills/).
	 * Mỗi file là một JSON array các blueprint objects.
	 *
	 * [2026-06-28 Johnny Chu] PHASE-ATH — canonical JSON loader cho future_blueprints.
	 *
	 * @return array<int,array<string,mixed>>  Mảng blueprints đã merge từ tất cả JSON files.
	 */
	public static function load_json_blueprints(): array {
		$templates_dir = dirname( __DIR__ ) . '/templates/';
		if ( ! is_dir( $templates_dir ) ) {
			return array();
		}

		// Files cần bỏ qua (catalog metadata-only, không phải blueprint array)
		$skip = array( 'builtin-catalog.json' );

		$blueprints = array();
		$files      = glob( $templates_dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $skip, true ) ) {
				continue;
			}
			// [2026-07-10 Johnny Chu] PHASE-ATH — avoid duplicate load: builtin-*.json is handled by load_builtin_json_blueprints().
			if ( strpos( $basename, 'builtin-' ) === 0 ) {
				continue;
			}

			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $raw ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			// Mỗi JSON file là một array các blueprint objects (slugs).
			// Mỗi object phải có ít nhất 'slug' và 'graph'.
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
					continue;
				}
				// Chuyển đổi graph sang graph_json string nếu cần
				if ( isset( $item['graph'] ) && is_array( $item['graph'] ) && ! isset( $item['graph_json'] ) ) {
					$item['graph_json'] = wp_json_encode( $item['graph'] );
					unset( $item['graph'] );
				}
				$blueprints[] = $item;
			}
		}

		return $blueprints;
	}

	/**
	 * Đọc các file builtin-*.json trong core/automation/templates/.
	 * Pattern: bất kỳ file nào bắt đầu bằng "builtin-" (ngoại trừ builtin-catalog.json).
	 *
	 * [2026-06-28 Johnny Chu] PHASE-ATH — canonical JSON loader cho builtin_blueprints.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_builtin_json_blueprints(): array {
		$templates_dir = dirname( __DIR__ ) . '/templates/';
		if ( ! is_dir( $templates_dir ) ) {
			return array();
		}

		$skip       = array( 'builtin-catalog.json' );
		$blueprints = array();
		$files      = glob( $templates_dir . 'builtin-*.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		foreach ( $files as $file ) {
			$basename = basename( $file );
			if ( in_array( $basename, $skip, true ) ) {
				continue;
			}

			$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $raw ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) || empty( $item['slug'] ) ) {
					continue;
				}
				if ( isset( $item['graph'] ) && is_array( $item['graph'] ) && ! isset( $item['graph_json'] ) ) {
					$item['graph_json'] = wp_json_encode( $item['graph'] );
					unset( $item['graph'] );
				}
				$blueprints[] = $item;
			}
		}

		return $blueprints;
	}

	/** Template 1 — Zalo CSKH (KG lookup → LLM reply → send back). */
	private static function bp_zalo_cskh(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80,  array( 'label' => 'Zalo · tin nhắn', 'instance_id' => '', 'filter' => '' ) ),
			self::n( 'a1', 'action',  'action.search_kg',     320, 80,  array( 'label' => 'Tra cứu KG', 'query' => '{{trigger.text}}', 'top_k' => 5 ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',    640, 80,  array( 'label' => 'Soạn câu trả lời', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là CSKH thân thiện.', 'prompt' => 'Câu hỏi: {{trigger.text}}\nThông tin nội bộ: {{kg.snippet}}\nTrả lời ngắn gọn, lịch sự.' ) ),
			self::n( 'o1', 'action',  'action.reply_zalo',    960, 80,  array( 'label' => 'Gửi Zalo', 'text' => '{{llm.output}}' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 960, 240, array( 'label' => 'Ghi CRM', 'event_type' => 'reminder_zalo', 'title' => 'Đã trả lời Zalo CSKH' ) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'l1' ),
			self::e( 'l1', 'o1' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'         => 'tpl_zalo_cskh_v1',
			'name'         => 'Zalo CSKH — Hỏi & đáp tự động',
			'description'  => 'Khách nhắn Zalo → tra Knowledge Graph → LLM soạn câu trả lời → gửi lại + ghi CRM.',
			'category'     => 'cskh',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'MessageCircle',
			'tags'         => 'zalo,cskh,kg,llm',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_cskh_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '' ),
		);
	}

	/** Template 2 — FB comment → lead capture (CRM + email notify). */
	private static function bp_fb_lead_capture(): array {
		$nodes = array(
			self::n( 't1', 'trigger',   'trigger.fb_comment',       0,   80,  array( 'label' => 'FB · comment mới', 'instance_id' => '', 'filter' => '' ) ),
			self::n( 'g1', 'condition', 'logic.condition',          320, 80,  array( 'label' => 'Là lead?', 'expression' => "trigger.comment != ''" ) ),
			self::n( 'c1', 'action',    'action.create_crm_event',  640, 0,   array( 'label' => 'Tạo CRM lead', 'event_type' => 'lead_report', 'title' => 'Lead FB: {{trigger.comment}}' ) ),
			self::n( 'm1', 'action',    'action.send_email',        640, 160, array( 'label' => 'Email sales', 'to' => get_option( 'admin_email', '' ), 'subject' => 'Lead mới từ Facebook', 'body' => 'Comment: {{trigger.comment}}' ) ),
			self::n( 'r1', 'action',    'action.reply_zalo',        940, 80,  array( 'label' => 'Phản hồi page (placeholder)', 'text' => 'Cảm ơn anh/chị! Sales sẽ liên hệ sớm.' ) ),
		);
		$edges = array(
			self::e( 't1', 'g1' ),
			self::e( 'g1', 'c1', 'true' ),
			self::e( 'g1', 'm1', 'true' ),
			self::e( 'c1', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_fb_lead_capture_v1',
			'name'         => 'Facebook Lead — Capture & notify',
			'description'  => 'Comment FB mới → ghi nhận lead vào CRM + email sales + phản hồi tự động.',
			'category'     => 'lead',
			'source'       => 'builtin',
			'trigger_type' => 'fb_comment',
			'icon'         => 'Zap',
			'tags'         => 'facebook,lead,crm,email',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_fb_lead_capture_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '' ),
		);
	}

	/** Template 3 — MPR Thinking auto-reply on TwinBrain intent. */
	private static function bp_mpr_auto_reply(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.twinbrain_intent', 0,   80,  array( 'label' => 'TB intent · tạo bảng tính', 'intent_id' => 'create_spreadsheet' ) ),
			self::n( 'l1', 'llm',     'llm.mpr_think',            320, 80,  array( 'label' => 'MPR Thinking', 'prompt' => '{{trigger.prompt}}', 'guru_id' => 0, 'k' => 8 ) ),
			self::n( 'log','action',  'action.log',               640, 80,  array( 'label' => 'Log MPR result', 'message' => 'Layers: {{mpr.layers_count}} — Answer: {{mpr.answer_md}}' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event',  640, 240, array( 'label' => 'CRM event', 'event_type' => 'reminder_zalo', 'title' => 'MPR đã chạy (intent={{trigger.intent_id}})' ) ),
		);
		$edges = array(
			self::e( 't1', 'l1' ),
			self::e( 'l1', 'log' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'         => 'tpl_mpr_auto_reply_v1',
			'name'         => 'TwinBrain Intent → MPR Thinking',
			'description'  => 'Khi TwinBrain phát hiện intent (vd tạo bảng tính) → chạy MPR Thinking 9 lớp → log + ghi CRM.',
			'category'     => 'mpr',
			'source'       => 'builtin',
			'trigger_type' => 'twinbrain_intent',
			'icon'         => 'Brain',
			'tags'         => 'twinbrain,mpr,llm,intent',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_mpr_auto_reply_v1' ) ),
			'trigger_config' => array( 'intent_id' => 'create_spreadsheet' ),
		);
	}

	/** Template 4 — Cron daily report (HTTP → log → CRM). */
	private static function bp_cron_daily_report(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',            0,   80,  array( 'label' => 'Cron · 08:00', 'schedule' => '0 8 * * *' ) ),
			self::n( 'h1', 'action',  'action.http_request',     320, 80,  array( 'label' => 'Fetch dashboard API', 'method' => 'GET', 'url' => home_url( '/wp-json/wp/v2/posts?per_page=1' ), 'body' => '' ) ),
			self::n( 'log','action',  'action.log',              640, 80,  array( 'label' => 'Log raw', 'message' => 'Status: {{http.status}}' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 940, 80,  array( 'label' => 'CRM daily report', 'event_type' => 'lead_report', 'title' => 'Daily report 08:00' ) ),
		);
		$edges = array(
			self::e( 't1', 'h1' ),
			self::e( 'h1', 'log' ),
			self::e( 'log','c1' ),
		);
		return array(
			'slug'         => 'tpl_cron_daily_report_v1',
			'name'         => 'Cron · Báo cáo mỗi sáng 08:00',
			'description'  => 'Mỗi sáng 8h → gọi HTTP fetch dashboard → log → tạo CRM event nhắc nhân viên xem.',
			'category'     => 'report',
			'source'       => 'builtin',
			'trigger_type' => 'cron',
			'icon'         => 'Clock',
			'tags'         => 'cron,report,daily,http',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_cron_daily_report_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * *' ),
		);
	}

	/** Template 5 — Webhook inbound → CRM event. */
	private static function bp_webhook_to_crm(): array {
		$nodes = array(
			self::n( 't1', 'trigger',   'trigger.webhook',          0,   80,  array( 'label' => 'Webhook inbound', 'slug' => 'lead_form', 'secret' => '' ) ),
			self::n( 'g1', 'condition', 'logic.condition',          320, 80,  array( 'label' => 'Có email?', 'expression' => "trigger.payload.email != ''" ) ),
			self::n( 'db', 'action',    'action.db_write',          640, 0,   array( 'label' => 'Ghi DB lead', 'table' => 'bizcity_crm_events', 'payload' => '{"event_type":"lead_report","title":"Webhook lead: {{trigger.payload.email}}"}' ) ),
			self::n( 'c1', 'action',    'action.create_crm_event',  640, 160, array( 'label' => 'CRM event', 'event_type' => 'lead_report', 'title' => 'Webhook lead: {{trigger.payload.email}}', 'due_at' => '' ) ),
			self::n( 'log','action',    'action.log',               940, 80,  array( 'label' => 'Log skipped', 'message' => 'Skipped lead — no email' ) ),
		);
		$edges = array(
			self::e( 't1', 'g1' ),
			self::e( 'g1', 'db', 'true' ),
			self::e( 'g1', 'c1', 'true' ),
			self::e( 'g1', 'log','false' ),
		);
		return array(
			'slug'         => 'tpl_webhook_crm_v1',
			'name'         => 'Webhook · Nhận lead từ form ngoài',
			'description'  => 'Form ngoài POST → /wp-json/bizcity-automation/v1/webhook/lead_form → validate email → ghi DB + CRM event.',
			'category'     => 'webhook',
			'source'       => 'builtin',
			'trigger_type' => 'webhook',
			'icon'         => 'Globe',
			'tags'         => 'webhook,lead,crm,external',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_webhook_crm_v1' ) ),
			'trigger_config' => array( 'slug' => 'lead_form', 'secret' => '' ),
		);
	}

	/**
	 * Template 6 — BE-7.A · Alert admin khi TwinBrain gợi ý web.search.
	 *
	 * Stage 3 tool router quyết định gọi `web.search` = câu hỏi bên ngoài KG
	 * (đắt tiền: tốn token + gọi web). Workflow này gửi 1 cảnh báo cho
	 * admin qua Zalo + email + log CRM để sales / content team bít lỗ hổng KG.
	 *
	 * User cần cấu hình sau khi instantiate:
	 *   - `action.reply_zalo.chat_id` = chat_id Zalo của admin (vd zalobot_<oa>_<uid>).
	 *   - `trigger_config.skill_slug` đã set sẵn = 'web.search'.
	 */
	private static function bp_tb_web_search_alert(): array {
		$admin_email = (string) get_option( 'admin_email', '' );
		$nodes = array(
			self::n( 't1',  'trigger', 'trigger.twinbrain_tool_decided', 0,   80,  array(
				'label'      => 'TB tool · web.search',
				'skill_slug' => 'web.search',
			) ),
			self::n( 'log', 'action',  'action.log',                     320, 80,  array(
				'label'   => 'Log trace',
				'message' => 'TB đề xuất web.search — trace={{trigger.trace_id}} skill={{trigger.skill_slug}}',
			) ),
			self::n( 'z1',  'action',  'action.reply_zalo',              640, 0,   array(
				'label'   => 'Zalo báo admin',
				// chat_id để trống — user phải fill vào sau khi instantiate.
				'chat_id' => '',
				'text'    => '⚠ TwinBrain gợi ý web.search cho 1 câu hỏi — trace {{trigger.trace_id}}. Có thể cần bổ sung Knowledge Graph.',
			) ),
			self::n( 'm1',  'action',  'action.send_email',              640, 160, array(
				'label'   => 'Email admin',
				'to'      => $admin_email,
				'subject' => '[BizCity] TwinBrain cần web.search — bổ sung KG?',
				'body'    => "Trace ID: {{trigger.trace_id}}\nSkill: {{trigger.skill_slug}}\nArgs: {{trigger.args}}\n\nMở biểu đồ thinking timeline để xem câu hỏi ban đầu.",
			) ),
			self::n( 'c1',  'action',  'action.create_crm_event',        940, 80,  array(
				'label'      => 'CRM · KG gap',
				'event_type' => 'lead_report',
				'title'      => 'KG gap — TwinBrain cần web.search (trace {{trigger.trace_id}})',
			) ),
		);
		$edges = array(
			self::e( 't1',  'log' ),
			self::e( 'log', 'z1' ),
			self::e( 'log', 'm1' ),
			self::e( 'z1',  'c1' ),
		);
		return array(
			'slug'         => 'tpl_tb_web_search_alert_v1',
			'name'         => 'TwinBrain cần web.search → cảnh báo admin',
			'description'  => 'Khi Stage 3 của TwinBrain quyết định gọi web.search = câu hỏi vượt KG. Gửi Zalo admin + email + CRM event để bổ sung Guru Knowledge.',
			'category'     => 'mpr',
			'source'       => 'builtin',
			'trigger_type' => 'twinbrain_tool_decided',
			'icon'         => 'Wand2',
			'tags'         => 'twinbrain,tool,web-search,alert,kg-gap',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_tb_web_search_alert_v1' ) ),
			'trigger_config' => array( 'skill_slug' => 'web.search' ),
		);
	}

	/**
	 * Pilot 1 — BE-7.B · Zalo keyword pilot.
	 *
	 * Trả lời tự động khi user nhắn chứa từ "bảng giá" qua Zalo OA.
	 * Đây là workflow "keyword-based" — PASS qua filter → chạy KG lối ngắn.
	 * Non-fallback (ưu tiên hơn mọi fallback workflow).
	 */
	private static function bp_zalo_pilot_keyword(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo · keyword "bảng giá"',
				'instance_id' => '',
				'filter'      => 'bảng giá',
			) ),
			self::n( 'a1', 'action',  'action.search_kg',     320, 80, array(
				'label' => 'Tra KG — bảng giá',
				'query' => '{{trigger.text}}',
				'top_k' => 3,
			) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',    640, 80, array(
				'label'  => 'Soạn trả lời',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là CSKH gửi bảng giá ngắn gọn, lịch sự.',
				'prompt' => "Câu hỏi: {{trigger.text}}\nThông tin nội bộ: {{kg.snippet}}\nTrả lời ngắn 3-5 dòng.",
			) ),
			self::n( 'o1', 'action',  'action.reply_zalo',    960, 80, array(
				'label' => 'Gửi Zalo',
				'text'  => '{{llm.output}}',
			) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 960, 240, array(
				'label'      => 'Ghi CRM',
				'event_type' => 'reminder_zalo',
				'title'      => 'Pilot keyword · đã gửi bảng giá',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'l1' ),
			self::e( 'l1', 'o1' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'         => 'tpl_zalo_pilot_keyword_v1',
			'name'         => 'Pilot · Zalo keyword "bảng giá"',
			'description'  => 'Khi khách nhắn chứa "bảng giá" → tra KG → LLM soạn trả lời → gửi Zalo. Mẫu pilot keyword-based, không phải fallback.',
			'category'     => 'cskh',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'MessageCircle',
			'tags'         => 'pilot,zalo,keyword,kg',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_pilot_keyword_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'bảng giá',
				'is_fallback' => false,
				'priority'    => 10,
			),
		);
	}

	/**
	 * Pilot 2 — BE-7.B · Zalo fallback to TwinBrain.
	 *
	 * R-FB-NOMATCH (TWINBRAIN-DEFAULT): mọi tin Zalo KHÔNG match keyword nào
	 * → fan vào `llm.mpr_think` với `{{trigger.text}}`. TwinBrain runtime
	 * tự chọn notebook mà user binding (qua Channel_Binding → character_id
	 * → Stage 1 selector resolve notebook).
	 *
	 * Matcher (`on_channel_message`) chỉ fire fallback khi `$matched` empty,
	 * sort theo `priority` desc. Đặt `priority=0` cho mẫu pilot — staff có
	 * thể tạo fallback riêng priority=10 để override.
	 */
	private static function bp_zalo_pilot_fallback_brain(): array {
		$nodes = array(
			self::n( 't1',  'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo · fallback (mọi tin không match keyword)',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'mpr', 'llm',     'llm.mpr_think',        320, 80, array(
				'label'      => 'TwinBrain · notebook user',
				'prompt'     => '{{trigger.text}}',
				'guru_id'    => 0,   // 0 = mặc định, Stage 1 tự chọn notebook.
				'tool_force' => '',
				'k'          => 6,
			) ),
			self::n( 'r1',  'action',  'action.reply_zalo',    640, 80, array(
				'label' => 'Gửi trả lời Zalo',
				'text'  => '{{mpr.answer_md}}',
			) ),
			self::n( 'c1',  'action',  'action.create_crm_event', 640, 240, array(
				'label'      => 'CRM · Fallback brain',
				'event_type' => 'reminder_zalo',
				'title'      => 'Fallback TwinBrain — tin Zalo: {{trigger.text}}',
			) ),
		);
		$edges = array(
			self::e( 't1',  'mpr' ),
			self::e( 'mpr', 'r1' ),
			self::e( 'mpr', 'c1' ),
		);
		return array(
			'slug'         => 'tpl_zalo_pilot_fallback_brain_v1',
			'name'         => 'Pilot · Zalo fallback → TwinBrain notebook',
			'description'  => 'Khi tin Zalo KHÔNG match keyword workflow nào → chạy MPR Thinking với notebook user binding → gửi trả lời + log CRM. R-FB-NOMATCH guarantee mọi tin luôn có response.',
			'category'     => 'mpr',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Brain',
			'tags'         => 'pilot,zalo,fallback,twinbrain,notebook',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_pilot_fallback_brain_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '',
				'is_fallback' => true,
				'priority'    => 0,
			),
		);
	}

	/**
	 * Pilot 3 — BE-7.C · Image capture (multi-turn turn 1).
	 *
	 * User Zalo gửi 1 ảnh (không kèm text) → workflow lưu URL ảnh vào pending
	 * slot + set `intent=awaiting_image_purpose`, workflow_id=self → hỏi lại
	 * "sếp muốn em làm gì?". Lượt sau user nhập text → matcher resume vào
	 * chính workflow này. Trong nhánh resume (có `_resume.attachment_url`),
	 * hỏi → llm.compose_reply → reply Zalo (đại loại "đã hiểu, em sẽ ...").
	 *
	 * Đây là pattern thay thế trực tiếp cho `twf_handle_image_attachment`
	 * legacy. Để workflow đăng web/FB chạy được, staff thường KHÔNG dùng pilot
	 * này một mình — họ chain qua `tpl_post_web_with_image_v1` /
	 * `tpl_post_fb_with_image_v1` (entry keyword "đăng bài" / "đăng fb").
	 *
	 * priority=12 (giữa keyword pilot=10 và fallback=0).
	 */
	private static function bp_image_capture(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo · nhận ảnh',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'cap',     'action', 'action.capture_attachment',  320, 80, array(
				'label'   => 'Lưu ảnh vào slot',
				'url'     => '{{trigger.media_url}}',
				'ttl_min' => 15,
			) ),
			self::n( 'pending', 'action', 'action.set_pending_intent',  640, 80, array(
				'label'         => 'Đặt slot chờ',
				'intent'        => 'awaiting_image_purpose',
				'workflow_id'   => 0,    // 0 = self.
				'workflow_slug' => '',
				'ttl_min'       => 15,
				'slots_json'    => '{}',
			) ),
			self::n( 'reply',   'action', 'action.reply_zalo',          960, 80, array(
				'label' => 'Hỏi mục đích',
				'text'  => '✅ Em đã nhận được ảnh. Sếp muốn em làm gì với ảnh này?',
			) ),
		);
		$edges = array(
			self::e( 't1',      'cap' ),
			self::e( 'cap',     'pending' ),
			self::e( 'pending', 'reply' ),
		);
		return array(
			'slug'         => 'tpl_image_capture_v1',
			'name'         => 'Pilot · Lưu ảnh + hỏi mục đích',
			'description'  => 'Khi user gửi ảnh qua Zalo (không text) → lưu URL vào pending slot 15 phút → hỏi "sếp muốn em làm gì?". Lượt tiếp theo user trả lời sẽ resume đúng workflow này. Pattern multi-turn slot — thay legacy `twf_handle_image_attachment` transient.',
			'category'     => 'cskh',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Image',
			'tags'         => 'pilot,zalo,multi-turn,attachment,pending-state',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_image_capture_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '',
				'is_fallback' => false,
				'priority'    => 12,
			),
		);
	}

	/**
	 * Pilot 4 — BE-7.C · Đăng bài WordPress kèm ảnh (multi-turn keyword).
	 *
	 * Trigger: keyword "đăng bài" / "viết bài". Gồm 2 entry path:
	 *   - Lượt 1 (chưa có ảnh): set pending slot + hỏi gửi ảnh.
	 *   - Resume (đã có ảnh trong slot): consume → LLM gen title/content →
	 *     publish_wp_post (status=draft → staff review).
	 *
	 * Logic phân nhánh dùng `logic.condition` đọc {{trigger._resume.attachment_url}}
	 * — nếu không rỗng thì đi nhánh "publish", ngược lại nhánh "ask_image".
	 *
	 * Status mặc định = `draft` (giám sát manual). Staff bật `enabled=1` sau
	 * khi instantiate.
	 */
	private static function bp_post_web_with_image(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "đăng bài"',
				'instance_id' => '',
				'filter'      => 'đăng bài',
			) ),
			self::n( 'cond', 'condition', 'logic.condition', 320, 80, array(
				'label'      => 'Đã có ảnh từ turn trước?',
				'expression' => "trigger._resume.attachment_url != ''",
			) ),
			// Branch FALSE — chưa có ảnh, set slot + hỏi.
			self::n( 'pending', 'action', 'action.set_pending_intent', 640, 240, array(
				'label'         => 'Đặt slot chờ ảnh',
				'intent'        => 'awaiting_post_image',
				'workflow_id'   => 0,
				'workflow_slug' => '',
				'ttl_min'       => 15,
				'slots_json'    => '{"title_hint":"{{trigger.text}}"}',
			) ),
			self::n( 'ask',     'action', 'action.reply_zalo', 960, 240, array(
				'label' => 'Hỏi gửi ảnh',
				'text'  => '📸 Sếp gửi 1 ảnh kèm để em đăng bài nhé. Em sẽ đợi 15 phút.',
			) ),
			// Branch TRUE — có ảnh trong slot → consume + compose + publish.
			self::n( 'consume', 'action', 'action.consume_attachment', 640, 80, array(
				'label'      => 'Lấy ảnh từ slot',
				'clear_slot' => 1,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 960, 80, array(
				'label'  => 'Gen title + content',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter. Trả về JSON {"title": "...", "content": "<p>...</p>"} từ yêu cầu user.',
				'prompt' => "Yêu cầu: {{trigger._resume.slots.title_hint}}\nMessage hiện tại: {{trigger.text}}\nẢnh: {{consume.attachment_url}}\nTrả về JSON đúng schema.",
			) ),
			self::n( 'publish', 'action', 'action.publish_wp_post', 1280, 80, array(
				'label'     => 'Đăng web (draft)',
				'title'     => '{{gen.title}}',
				'content'   => '{{gen.output}}',
				'image_url' => '{{consume.attachment_url}}',
				'status'    => 'draft',
				'category'  => '',
				'tags'      => 'automation',
				'author_id' => 0,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 1600, 80, array(
				'label' => 'Báo lại Zalo',
				'text'  => '✅ Đã tạo draft post #{{publish.post_id}}. Sếp duyệt: {{publish.edit_url}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'cond' ),
			self::e( 'cond', 'pending', 'false' ),
			self::e( 'pending', 'ask' ),
			self::e( 'cond', 'consume', 'true' ),
			self::e( 'consume', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_post_web_with_image_v1',
			'name'         => 'Pilot · Đăng bài WP kèm ảnh (multi-turn)',
			'description'  => 'Keyword "đăng bài" → nếu chưa có ảnh thì hỏi → nếu có rồi (turn resume) thì LLM gen title/content + đăng draft. Status=draft cho staff review trước khi publish.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'FileText',
			'tags'         => 'pilot,zalo,multi-turn,wordpress,publish',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_post_web_with_image_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'đăng bài',
				'is_fallback' => false,
				'priority'    => 15,
			),
		);
	}

	/**
	 * PG-S9-fix — Pilot · Linear "đăng bài" workflow (simple, no condition).
	 *
	 * Logic theo legacy bizcity-admin-hook-zalo:
	 *   Turn 1 (user gửi ẢNH): MATCHER tự stash attachment_url vào pending_state
	 *           (intent=awaiting_media_purpose) + reply hardcode "Đã nhận ảnh,
	 *           muốn em làm gì?". Workflow KHÔNG chạy ở turn này.
	 *   Turn 2 (user gõ "đăng bài <chủ đề>"): matcher inject pending vào _resume,
	 *           workflow này fire LINEAR:
	 *             1. consume_attachment  → đọc ảnh từ pending (rỗng cũng OK).
	 *             2. llm.compose_reply   → JSON {title, content_html} từ
	 *                user text + ảnh URL (nếu có).
	 *             3. publish_wp_post     → đăng draft, image_url = ảnh đã consume.
	 *             4. reply_zalo          → báo lại link edit cho user.
	 *
	 * KHÔNG có condition branch — nếu user nhắn "đăng bài" mà chưa từng gửi ảnh,
	 * workflow vẫn đăng bài (không thumbnail). User có thể gửi ảnh sau và update.
	 * Đơn giản, không dead-loop.
	 *
	 * @since PG-S9-fix-v2 (2026-05-31)
	 */
	private static function bp_post_web_image_first(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "đăng bài"',
				'instance_id' => '',
				'filter'      => 'đăng bài',
			) ),
			self::n( 'consume', 'action', 'action.consume_attachment', 320, 80, array(
				'label'      => 'Đọc ảnh từ pending (do Matcher stash ở turn ảnh)',
				'clear_slot' => 1,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Gen nội dung bài viết',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter chuyên nghiệp cho website Việt Nam. Trả về DUY NHẤT phần nội dung HTML của bài blog (200-400 từ, có <h2>, <p>, <ul> nếu phù hợp). KHÔNG kèm tiêu đề (sẽ tự sinh), KHÔNG markdown, KHÔNG ```html wrapper. Mở đầu bằng <h2> chủ đề chính.',
				'prompt' => "Yêu cầu đăng bài từ user: {{trigger.text}}\nẢnh minh hoạ (nếu cần chèn): {{consume.attachment_url}}\nViết HTML bài blog dài 200-400 từ, có 2-3 đoạn, lồng ghép tự nhiên giá trị / lợi ích.",
			) ),
			self::n( 'publish', 'action', 'action.publish_wp_post', 960, 80, array(
				'label'     => 'Đăng web (draft)',
				'title'     => '',
				'content'   => '{{gen.output}}',
				'image_url' => '{{consume.attachment_url}}',
				'status'    => 'draft',
				'category'  => '',
				'tags'      => 'automation,zalo-bot',
				'author_id' => 0,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 1280, 80, array(
				'label' => 'Báo lại Zalo',
				'text'  => "✅ Đã tạo draft #{{publish.post_id}}\n📝 {{publish.title}}\n🔗 Edit: {{publish.edit_url}}",
			) ),
		);
		$edges = array(
			self::e( 't1', 'consume' ),
			self::e( 'consume', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_post_web_image_first_v1',
			'name'         => 'Pilot · Đăng bài WP (linear, ảnh-trước)',
			'description'  => 'LINEAR workflow theo logic legacy: user gửi ảnh → MATCHER tự lưu + reply "muốn làm gì". User gõ "đăng bài <chủ đề>" → workflow consume ảnh đã stash + LLM gen title/content + publish draft + reply link. KHÔNG có nhánh điều kiện — không ảnh thì vẫn đăng được.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Image',
			'tags'         => 'pilot,zalo,multi-turn,linear,wordpress,publish',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_post_web_image_first_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'đăng bài',
				'is_fallback' => false,
				'priority'    => 12,
			),
		);
	}

	/**
	 * PG-S9-fix v6 · Logic 2 — Đăng FB Page linear (ảnh-trước, caption ngắn).
	 *
	 * Cùng pattern với `bp_post_web_image_first` (đã verify chạy ngon):
	 *   user gửi ảnh → MATCHER stash + reply "muốn làm gì" →
	 *   user gõ "đăng fb <chủ đề>" → consume ảnh → LLM gen caption ngắn
	 *   (4-6 dòng + emoji + 3-5 hashtag) → publish_fb_post (scheduled +3m)
	 *   → reply Zalo báo lại event_id.
	 *
	 * Khác `bp_post_fb_with_image`: KHÔNG có cond branching, không dùng
	 * pending_intent (vì matcher đã stash) → an toàn hơn, ít fail point hơn.
	 */
	private static function bp_post_fb_image_first(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "đăng fb"',
				'instance_id' => '',
				'filter'      => 'đăng fb',
			) ),
			self::n( 'consume', 'action', 'action.consume_attachment', 320, 80, array(
				'label'      => 'Đọc ảnh từ pending (matcher stash ở turn ảnh)',
				'clear_slot' => 1,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Gen FB caption (ngắn)',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter Facebook chuyên nghiệp. Viết caption NGẮN GỌN cho post FB Việt Nam: 4-6 dòng, có 2-4 emoji phù hợp, kết thúc bằng 3-5 hashtag liên quan. KHÔNG có tiêu đề riêng, KHÔNG markdown, KHÔNG ```. Tone tự nhiên, gần gũi, kêu gọi tương tác (like/comment/share) ở cuối nếu phù hợp.',
				'prompt' => "Yêu cầu đăng FB từ user: {{trigger.text}}\nẢnh đính kèm: {{consume.attachment_url}}\nViết caption FB ngắn 4-6 dòng đúng spec.",
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 960, 80, array(
				'label'        => 'Đặt lịch đăng FB (+3 phút)',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.output}}',
				'image_url'    => '{{consume.attachment_url}}',
				'mode'         => 'scheduled',
				'delay_min'    => 3,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 1280, 80, array(
				'label' => 'Báo lại Zalo',
				'text'  => "✅ Đã đặt lịch đăng FB sau 3 phút (event #{{publish.event_id}})\n📝 {{gen.output}}\n⏱️ Sếp muốn huỷ → vào Scheduler.",
			) ),
		);
		$edges = array(
			self::e( 't1', 'consume' ),
			self::e( 'consume', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_post_fb_image_first_v1',
			'name'         => 'Pilot · Đăng FB Page (linear, ảnh-trước, caption ngắn)',
			'description'  => 'LINEAR theo logic Logic-1 đã verify: user gửi ảnh → matcher stash → user gõ "đăng fb <chủ đề>" → consume ảnh + LLM gen caption NGẮN (4-6 dòng + emoji + hashtag) → đặt lịch publish_fb_post +3 phút → báo lại Zalo. Cần điền fb_page_id sau khi instantiate.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Facebook',
			'tags'         => 'pilot,zalo,linear,facebook,publish,caption-ngan',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_post_fb_image_first_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'đăng fb',
				'is_fallback' => false,
				'priority'    => 14,
			),
		);
	}

	/**
	 * PG-S9-fix v6 · Logic 3 — Nhắc lịch (Calendar reminder qua Zalo).
	 *
	 * Keyword "nhắc" (cover "nhắc lịch", "nhắc tôi", "tạo lịch nhắc")
	 * → LLM extract {title, when_iso} → action.schedule_event với
	 * event_type=reminder_zalo + reminder_min=0 → cron tự bắn tin nhắc
	 * lại Zalo đúng giờ.
	 *
	 * NOTE: Google Calendar sync chính thức (OAuth + Calendar API) chưa
	 * ship — template này dùng CRM events table làm "calendar nội bộ".
	 * Khi GCal integration ready, đổi action.schedule_event sang
	 * action.gcal_create_event là xong (cùng schema input).
	 */
	private static function bp_reminder_calendar(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "nhắc"',
				'instance_id' => '',
				'filter'      => 'nhắc',
			) ),
			self::n( 'extract', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Extract title + thời gian',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là bộ trích xuất reminder. Đọc câu của user và trả về DUY NHẤT 1 dòng JSON (không markdown, không giải thích) format chính xác: {"title":"<tiêu đề ngắn 5-10 từ>","when":"<thời gian PHP strtotime hợp lệ>"}. Quy tắc when: nếu user nói "5h chiều mai" → "tomorrow 17:00"; "thứ 2 tuần sau 9h" → "next monday 09:00"; "trong 30 phút" → "+30 minutes"; "ngày 5/6 lúc 14h" → "2026-06-05 14:00". Nếu KHÔNG xác định được thời gian → "+1 hour".',
				'prompt' => 'Câu của user: {{trigger.text}}',
			) ),
			self::n( 'sched', 'action', 'action.schedule_event', 640, 80, array(
				'label'        => 'Tạo reminder (CRM event)',
				'event_type'   => 'reminder_zalo',
				'title'        => '{{trigger.text}}',
				'description'  => "Tạo từ Zalo bot.\nExtract: {{extract.output}}\nOriginal: {{trigger.text}}",
				'start_at'     => '+1 hour',
				'reminder_min' => 0,
				'zalo_bot_id'  => '',
				'zalo_user_id' => '{{trigger.chat_id}}',
				'zalo_text'    => '⏰ Nhắc Sếp: {{trigger.text}}',
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Báo lại Zalo',
				'text'  => "✅ Đã tạo nhắc lịch (event #{{sched.event_id}})\n🕘 {{sched.start_at}}\n📋 Extract: {{extract.output}}\n💡 Em sẽ tự nhắn nhắc đúng giờ.",
			) ),
		);
		$edges = array(
			self::e( 't1', 'extract' ),
			self::e( 'extract', 'sched' ),
			self::e( 'sched', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_reminder_calendar_v1',
			'name'         => 'Pilot · Nhắc lịch (Calendar reminder qua Zalo)',
			'description'  => 'Keyword "nhắc" → LLM extract title + when (strtotime) → tạo CRM event type reminder_zalo → cron tự gửi nhắc lại Zalo đúng giờ. Cần điền zalo_bot_id của bot Zalo sau khi instantiate (hoặc để rỗng để cron resolve từ context). Google Calendar OAuth chưa ship — tạm dùng CRM events table.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'CalendarClock',
			'tags'         => 'pilot,zalo,linear,reminder,calendar,scheduler',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_reminder_calendar_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'nhắc',
				'is_fallback' => false,
				'priority'    => 11,
			),
		);
	}

	/**
	 * Pilot 5 — BE-7.C · Đăng Facebook Page kèm ảnh (multi-turn keyword).
	 *
	 * Tương tự `bp_post_web_with_image()` nhưng publish qua scheduler event
	 * `event_type=fb_post` → `BizCity_FB_Publisher` xử lý publish + ghi
	 * `fb_post_id` / `fb_permalink` ngược lại metadata.
	 *
	 * Mode mặc định = `scheduled` (trễ 5 phút) → staff giám sát có cửa sổ
	 * huỷ trước khi reminder fire. `fb_page_id` để rỗng — staff điền lúc
	 * instantiate template (mỗi tenant có page khác nhau).
	 */
	private static function bp_post_fb_with_image(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "đăng fb"',
				'instance_id' => '',
				'filter'      => 'đăng fb',
			) ),
			self::n( 'cond', 'condition', 'logic.condition', 320, 80, array(
				'label'      => 'Đã có ảnh?',
				'expression' => "trigger._resume.attachment_url != ''",
			) ),
			self::n( 'pending', 'action', 'action.set_pending_intent', 640, 240, array(
				'label'         => 'Đặt slot chờ ảnh',
				'intent'        => 'awaiting_fb_image',
				'workflow_id'   => 0,
				'workflow_slug' => '',
				'ttl_min'       => 15,
				'slots_json'    => '{"title_hint":"{{trigger.text}}"}',
			) ),
			self::n( 'ask',     'action', 'action.reply_zalo', 960, 240, array(
				'label' => 'Hỏi gửi ảnh',
				'text'  => '📸 Sếp gửi ảnh kèm để em đăng FB nhé. Em đợi 15 phút.',
			) ),
			self::n( 'consume', 'action', 'action.consume_attachment', 640, 80, array(
				'label'      => 'Lấy ảnh từ slot',
				'clear_slot' => 1,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 960, 80, array(
				'label'  => 'Gen FB caption',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter Facebook. Viết caption ngắn 4-6 dòng + emoji + 3-5 hashtag, KHÔNG có tiêu đề.',
				'prompt' => "Yêu cầu: {{trigger._resume.slots.title_hint}}\nMessage hiện tại: {{trigger.text}}\nẢnh: {{consume.attachment_url}}",
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 1280, 80, array(
				'label'        => 'Đăng FB (scheduled +5m)',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.output}}',
				'image_url'    => '{{consume.attachment_url}}',
				'mode'         => 'scheduled',
				'delay_min'    => 5,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 1600, 80, array(
				'label' => 'Báo lại Zalo',
				'text'  => '✅ Đã đặt lịch đăng FB sau 5 phút (event #{{publish.event_id}}). Sếp huỷ ở Scheduler nếu cần.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'cond' ),
			self::e( 'cond', 'pending', 'false' ),
			self::e( 'pending', 'ask' ),
			self::e( 'cond', 'consume', 'true' ),
			self::e( 'consume', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_post_fb_with_image_v1',
			'name'         => 'Pilot · Đăng FB Page kèm ảnh (multi-turn)',
			'description'  => 'Keyword "đăng fb" → nếu chưa có ảnh hỏi → có rồi (resume) thì LLM gen caption + đặt scheduler event fb_post (delay 5 phút cho staff huỷ). Page ID staff điền lúc instantiate.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Facebook',
			'tags'         => 'pilot,zalo,multi-turn,facebook,publish',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_post_fb_with_image_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'đăng fb',
				'is_fallback' => false,
				'priority'    => 15,
			),
		);
	}

	/**
	 * Pilot 6 — BE-7.D · Lên lịch generic (CRM event).
	 *
	 * Keyword "lên lịch" → LLM extract slots {title,when,kind} → ghi vào
	 * `bizcity_crm_events` qua `action.schedule_event`. Mặc định
	 * `event_type=task`. Đổi sang `reminder_zalo` + điền `zalo_bot_id` để
	 * cron tự bắn nhắc.
	 */
	/**
	 * [2026-06-15 Johnny Chu] PHASE-0 — fixed: dùng {{extract.output.title}}, {{extract.output.when}},
	 * {{extract.output.kind}} thay vì hardcode. LLM prompt rõ ràng hơn về format "today HH:MM:SS".
	 */
	private static function bp_schedule_event(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "lên lịch"',
				'instance_id' => '',
				'filter'      => 'lên lịch',
			) ),
			self::n( 'extract', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Extract slots',
				'model'  => 'gpt-4o-mini',
				// [2026-06-15 Johnny Chu] PHASE-0 — prompt rõ format datetime; "10h hôm nay" → "today 10:00:00"
				// không dùng "HH:MM" đơn thuần (PHP strtotime không parse được).
				'system' => 'Bạn là bộ trích xuất lịch. Trả về 1 dòng JSON DUY NHẤT (không markdown, không giải thích): {"title":"<tên việc ngắn>","when":"<datetime string hợp lệ cho PHP strtotime>","kind":"task|meeting|reminder"}. Quy tắc "when": cùng ngày dùng "today HH:MM:SS" (VD "10h đi họp" → "today 10:00:00"), ngày mai dùng "tomorrow HH:MM:SS", ngày cụ thể dùng "YYYY-MM-DD HH:MM:SS", không rõ thời gian → "+1 hour". Ví dụ: "lên lịch 10h đi họp" → {"title":"đi họp","when":"today 10:00:00","kind":"meeting"}. "9h sáng mai gặp khách" → {"title":"gặp khách","when":"tomorrow 09:00:00","kind":"meeting"}.',
				'prompt' => '{{trigger.text}}',
			) ),
			self::n( 'sched', 'action', 'action.schedule_event', 640, 80, array(
				'label'        => 'Ghi vào Scheduler',
				// [2026-06-15 Johnny Chu] PHASE-0 — dùng {{extract.output.*}} thay vì hardcode.
				'event_type'   => '{{extract.output.kind}}',
				'title'        => '{{extract.output.title}}',
				'description'  => 'Tạo từ Zalo: {{trigger.text}}',
				'start_at'     => '{{extract.output.when}}',
				'reminder_min' => 15,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Báo lại',
				'text'  => '✅ Đã lên lịch: {{extract.output.title}} vào {{extract.output.when}} (event #{{sched.event_id}}). Xem tại Scheduler.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'extract' ),
			self::e( 'extract', 'sched' ),
			self::e( 'sched', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_schedule_event_v1',
			'name'         => 'Pilot · Lên lịch từ Zalo (CRM event)',
			'description'  => 'Keyword "lên lịch" → LLM extract title/when/kind → tạo row bizcity_crm_events (status=active) → Scheduler page hiển thị. Đổi event_type=reminder_zalo + điền zalo_bot_id để cron tự gửi nhắc.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'CalendarClock',
			'tags'         => 'pilot,zalo,scheduler,crm,reminder',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_schedule_event_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'lên lịch',
				'is_fallback' => false,
				'priority'    => 13,
			),
		);
	}

	// ─── Node / edge helpers ─────────────────────────────────────────────
	// BE-7.E — Smoke-test templates dành cho "Chạy thử" (FE realtime SSE).
	// Thiết kế: trigger.manual (không cần channel) + 1 LLM compose + 1 CRM
	// event ghi mirror để verify toàn bộ pipeline runner + log streaming +
	// node status badges + DB write. Khi user mở Library → instantiate →
	// nhấn "▶ Chạy thử" sẽ thấy luồng chạy realtime ngay lập tức.

	private static function bp_test_smoke_manual(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.manual',          0,   80, array( 'label' => 'Manual · trigger' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',       320, 80, array(
				'label'  => 'LLM · echo',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là smoke-test bot. Trả lời ngắn 1 câu.',
				'prompt' => 'Hãy chào bằng tiếng Việt và in ra timestamp hiện tại.',
			) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 640, 80, array(
				'label'      => 'CRM · log run',
				'event_type' => 'task',
				'title'      => '[Smoke] Test runner OK',
				'description' => 'Reply: {{llm.output}}',
				'start_at'   => '+1 minute',
				'status'     => 'done',
			) ),
		);
		$edges = array(
			self::e( 't1', 'l1' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'         => 'tpl_test_smoke_manual_v1',
			'name'         => '[TEST] Smoke · Manual → LLM → CRM',
			'description'  => 'Template ngắn nhất để verify runner: trigger.manual → llm.compose_reply → action.create_crm_event. Dùng để debug realtime per-node status + SSE logs nhanh.',
			'category'     => 'test',
			'source'       => 'builtin',
			'trigger_type' => 'manual',
			'icon'         => 'Play',
			'tags'         => 'test,smoke,debug,manual',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_test_smoke_manual_v1' ) ),
			'trigger_config' => array(),
		);
	}

	private static function bp_test_smoke_branch(): array {
		$nodes = array(
			self::n( 't1', 'trigger',   'trigger.manual',          0,   140, array( 'label' => 'Manual · trigger' ) ),
			self::n( 'x1', 'condition', 'logic.condition',         320, 140, array(
				'label'      => 'IF · text chứa "ok"',
				'expression' => 'contains({{trigger.text}}, "ok")',
			) ),
			self::n( 'a_ok',  'action', 'action.create_crm_event', 640, 40,  array(
				'label'      => 'CRM · branch TRUE',
				'event_type' => 'task',
				'title'      => '[Smoke] Branch TRUE hit',
				'start_at'   => '+1 minute',
				'status'     => 'done',
			) ),
			self::n( 'a_fail', 'action', 'action.create_crm_event', 640, 240, array(
				'label'      => 'CRM · branch FALSE',
				'event_type' => 'task',
				'title'      => '[Smoke] Branch FALSE hit',
				'start_at'   => '+1 minute',
				'status'     => 'done',
			) ),
		);
		$edges = array(
			self::e( 't1', 'x1' ),
			self::e( 'x1', 'a_ok',   'true' ),
			self::e( 'x1', 'a_fail', 'false' ),
		);
		return array(
			'slug'         => 'tpl_test_smoke_branch_v1',
			'name'         => '[TEST] Smoke · Manual → IF → 2 CRM branches',
			'description'  => 'Verify condition branch routing + per-node skip status (3). Gửi {"text":"ok"} → branch TRUE chạy, FALSE skip. Gửi {"text":"no"} → ngược lại.',
			'category'     => 'test',
			'source'       => 'builtin',
			'trigger_type' => 'manual',
			'icon'         => 'GitBranch',
			'tags'         => 'test,smoke,branch,condition',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_test_smoke_branch_v1' ) ),
			'trigger_config' => array(),
		);
	}

	// ─── Node / edge helpers ─────────────────────────────────────────────

	/** @param array<string,mixed> $data */
	private static function n( string $id, string $type, string $block_id, int $x, int $y, array $data = array() ): array {
		$data['blockId'] = $block_id;
		return array(
			'id'       => $id,
			'type'     => $type,
			'position' => array( 'x' => $x, 'y' => $y ),
			'data'     => $data,
		);
	}

	private static function e( string $source, string $target, string $source_handle = '' ): array {
		$edge = array(
			'id'     => 'e_' . $source . '_' . $target . ( $source_handle ? '_' . $source_handle : '' ),
			'source' => $source,
			'target' => $target,
		);
		if ( $source_handle !== '' ) {
			$edge['sourceHandle'] = $source_handle;
		}
		return $edge;
	}

	// ─── SEED W1 (2026-06-02) ─────────────────────────────────────────────
	// Companion seeds cho PHASE-R-FINAL-ACTION + PHASE-SEED-TEMPLATES.
	// Note: seed `tpl_reminder_calendar_v1` đã có sẵn từ PG-S9-fix v6 → ko
	// duplicate ở đây. SEED W1 ship 2 seed mới: knowledge-router + remember.

	/**
	 * SEED W1.2 — Knowledge router (@hỏi → TwinBrain MPR thinking timeline).
	 *
	 * Trigger Zalo keyword "@hỏi" / "@tra" / "@kb" → llm.mpr_think
	 * (TwinBrain bridge) → reply Zalo kèm citations → ghi CRM audit row
	 * (event_type=task, metadata.kind=knowledge_query) để có evidence trên
	 * Scheduler UI theo R-FINAL-ACTION.
	 *
	 * Đây là 1 final-action SYNCHRONOUS → quad-commit lite (skip ④ ack
	 * callback vì đã reply ngay tại commit ②).
	 */
	private static function bp_knowledge_router(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "@hỏi"',
				'instance_id' => '',
				'filter'      => '@hỏi',
			) ),
			self::n( 'think', 'llm', 'llm.mpr_think', 320, 80, array(
				'label'      => 'TwinBrain MPR · domain router',
				'prompt'     => '{{trigger.text}}',
				'guru_id'    => 0,
				'tool_force' => '',
				'k'          => 8,
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 640, 80, array(
				'label' => 'Trả lời + citations',
				'text'  => "{{think.answer_md}}\n\n— TwinBrain (trace {{think.trace_id}})",
			) ),
			self::n( 'audit', 'action', 'action.create_crm_event', 640, 240, array(
				'label'       => 'Audit · knowledge_query',
				'event_type'  => 'task',
				'title'       => '[knowledge] {{trigger.text}}',
				'description' => "kind=knowledge_query\nQ: {{trigger.text}}\nTrace: {{think.trace_id}}\nLayers: {{think.layers_count}}",
				'start_at'    => '+0 minutes',
			) ),
		);
		$edges = array(
			self::e( 't1',    'think' ),
			self::e( 'think', 'reply' ),
			self::e( 'think', 'audit' ),
		);
		return array(
			'slug'         => 'tpl_knowledge_router_v1',
			'name'         => 'SEED W1 · Knowledge Router (@hỏi → TwinBrain)',
			'description'  => 'Keyword "@hỏi" qua bất kỳ channel (Zalo demo) → llm.mpr_think route sang TwinBrain MPR (đi qua web_search / web_med / web_gov... tùy domain classifier nội bộ) → reply kèm citations + ghi audit row vào CRM (event_type=task, metadata.kind=knowledge_query) cho R-FINAL-ACTION evidence. Synchronous final → skip ack callback.',
			'category'     => 'mpr',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'BookOpen',
			'tags'         => 'seed-w1,r-final-action,twinbrain,mpr,knowledge,router',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_knowledge_router_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '@hỏi',
				'is_fallback' => false,
				'priority'    => 13,
			),
		);
	}

	/**
	 * SEED W1.3 — Remember this (@nhớ → CRM event memory_note).
	 *
	 * Keyword "@nhớ" → ghi 1 row vào CRM events với event_type=task,
	 * metadata.kind=memory_note, source=ai_memory → reply Zalo confirm +
	 * link xem trên Scheduler. R-FINAL-ACTION quad-commit lite (① ② ③, ④
	 * skipped vì synchronous).
	 *
	 * TODO SEED W3: thay action.create_crm_event bằng action.tb_memory_remember
	 * khi block đó được port từ core/twinbrain/tools/memory/remember.
	 */
	private static function bp_remember_this(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · keyword "@nhớ"',
				'instance_id' => '',
				'filter'      => '@nhớ',
			) ),
			self::n( 'extract', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Extract memory title + tags',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là bộ trích xuất memory note. Đọc câu của user và trả về DUY NHẤT 1 dòng JSON (không markdown): {"title":"<tiêu đề 5-10 từ>","tags":"<csv tag>","content":"<nội dung gọn rõ ràng>"}. Nếu user nói "nhớ giúp tôi X" thì title bám sát X.',
				'prompt' => 'Câu của user: {{trigger.text}}',
			) ),
			self::n( 'remember', 'action', 'action.create_crm_event', 640, 80, array(
				'label'       => 'Ghi memory (CRM event)',
				'event_type'  => 'task',
				'title'       => '[memory] {{trigger.text}}',
				'description' => "kind=memory_note\nExtract: {{extract.output}}\nOriginal: {{trigger.text}}\nChat: {{trigger.chat_id}}",
				'start_at'    => '+3 minutes',
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Confirm Zalo',
				'text'  => "🧠 Đã nhớ (event #{{remember.event_id}})\n📝 {{extract.output}}\n🔍 Recall sau bằng @recall hoặc xem trên Scheduler.",
			) ),
		);
		$edges = array(
			self::e( 't1',       'extract' ),
			self::e( 'extract',  'remember' ),
			self::e( 'remember', 'reply' ),
		);
		return array(
			'slug'         => 'tpl_remember_this_v1',
			'name'         => 'SEED W1 · Remember this (@nhớ → memory note)',
			'description'  => 'Keyword "@nhớ" → LLM extract title/tags/content → tạo CRM event (event_type=task, metadata.kind=memory_note) làm bằng chứng theo R-FINAL-ACTION → reply Zalo confirm với event_id để recall sau. SEED W3 sẽ swap sang action.tb_memory_remember thực thụ khi port memory tool từ core/twinbrain.',
			'category'     => 'general',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'BrainCircuit',
			'tags'         => 'seed-w1,r-final-action,memory,twinbrain,recall',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_remember_this_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '@nhớ',
				'is_fallback' => false,
				'priority'    => 13,
			),
		);
	}

	/**
	 * Wave D W4.1 — /kg slash_command trigger → KG search → LLM → reply Zalo.
	 *
	 * Mẫu chuẩn dùng trigger_type='slash_command' (GURU W2). User gõ
	 * "/kg <câu hỏi>" trên Zalo/FB/TwinChat → workflow bắt → tra KG →
	 * LLM soạn → reply. Cần instantiate + fill instance_id của Zalo OA.
	 */
	private static function bp_slash_kg_query(): array {
		// [2026-06-03 Johnny Chu] WF-AUTO W4 — blueprint slash_command trigger (GURU W2).
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.slash_command', 0,   80, array(
				'label'         => '/kg — hỏi Knowledge Graph',
				'slash_command' => '/kg',
				'description'   => 'User gõ /kg <câu hỏi> để tra nội bộ.',
			) ),
			self::n( 'a1', 'action', 'action.search_kg', 320, 80, array(
				'label' => 'Tra cứu KG',
				'query' => '{{trigger.slash_args}}',
				'top_k' => 5,
			) ),
			self::n( 'l1', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'LLM soạn câu trả lời',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là trợ lý thân thiện của doanh nghiệp.',
				'prompt' => "Câu hỏi: {{trigger.slash_args}}\nKiến thức nội bộ: {{kg.snippet}}\nTrả lời ngắn gọn, lịch sự.",
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Gửi Zalo',
				'text'  => '{{llm.output}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'l1' ),
			self::e( 'l1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_slash_kg_query_v1',
			'name'           => '/kg — Hỏi Knowledge Graph qua slash',
			'description'    => 'User gõ /kg <câu hỏi> → tra Knowledge Graph → LLM soạn câu trả lời → reply Zalo. Mẫu chuẩn cho GURU W2 slash_command trigger.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'slash_command',
			'icon'           => 'Search',
			'tags'           => 'slash,kg,llm,zalo,guru-w2',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_slash_kg_query_v1' ) ),
			'trigger_config' => array( 'slash_command' => '/kg' ),
		);
	}

	/**
	 * Wave D W4.2 — skill_intent trigger → invoke_skill → log (Bridge W2 demo).
	 *
	 * Khi skill pipeline fire hook `bizcity_skill_trigger_pipeline` (archetype
	 * C/D hoặc slash invoke) → Bridge W2 subscriber catch → enqueue workflow
	 * này → `action.invoke_skill` run lại skill trong workflow context →
	 * `action.log` ghi evidence.
	 *
	 * Mẫu demo; user cần fill `skill_slug` trong trigger block sau khi
	 * instantiate để filter đúng skill mình muốn chain vào.
	 */
	private static function bp_skill_intent_invoke(): array {
		// [2026-06-03 Johnny Chu] WF-AUTO W4 — blueprint skill_intent trigger (Bridge W2).
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.skill_intent', 0, 80, array(
				'label'      => 'Skill Intent fired (any)',
				'skill_slug' => '',
				'archetype'  => 'any',
			) ),
			self::n( 'a1', 'action', 'action.invoke_skill', 320, 80, array(
				'label'           => 'Invoke skill',
				'skill_slug'      => '{{trigger.skill_key}}',
				'prompt_template' => '{{trigger.prompt}}',
				'character_id'    => 0,
				'timeout_seconds' => 30,
			) ),
			self::n( 'log', 'action', 'action.log', 640, 80, array(
				'label'   => 'Log kết quả',
				'message' => 'Skill {{trigger.skill_key}} done — output: {{a1.skill_output}}',
			) ),
		);
		$edges = array(
			self::e( 't1',  'a1' ),
			self::e( 'a1', 'log' ),
		);
		return array(
			'slug'           => 'tpl_skill_intent_invoke_v1',
			'name'           => 'Skill Intent → Invoke & Log (Bridge W2)',
			'description'    => 'Khi skill pipeline fire (archetype C/D) → workflow bắt skill_intent → invoke_skill trong context → log output. Mẫu demo cho trigger.skill_intent (GURU Bridge W2).',
			'category'       => 'mpr',
			'source'         => 'builtin',
			'trigger_type'   => 'skill_intent',
			'icon'           => 'Zap',
			'tags'           => 'skill,intent,bridge,invoke,guru-w2',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_skill_intent_invoke_v1' ) ),
			'trigger_config' => array( 'skill_slug' => '', 'archetype' => 'any' ),
		);
	}

	// ─── CRM-PATH-2 (2026-06-07) ─────────────────────────────────────────────
	// 3 care templates dành cho Path B (zone=crm). Trigger: zalo_oa / zalo_personal
	// (Zone 1). Admin instantiate ở Path A; CSKH activate ở tab CRM-care.

	/**
	 * Care Template 1 — Zalo OA auto-reply chăm sóc CSKH.
	 *
	 * Khách nhắn Zalo OA → KG tra kiến thức → LLM soạn → reply ngay.
	 * zone=crm; Path B chỉ cần fill instance_id (OA ID) sau khi instantiate.
	 */
	private static function bp_care_zalo_oa_auto_reply(): array {
		// [2026-06-07 Johnny Chu] CRM-PATH-2 — care template 1: Zalo OA auto-reply.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo OA · tin nhắn khách',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'a1', 'action',  'action.search_kg', 320, 80, array(
				'label' => 'Tra cứu sản phẩm / FAQ',
				'query' => '{{trigger.text}}',
				'top_k' => 5,
			) ),
			self::n( 'l1', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Soạn câu trả lời CSKH',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là nhân viên chăm sóc khách hàng thân thiện, chuyên nghiệp. Trả lời ngắn gọn, đúng vào vấn đề, xưng "shop" với khách.',
				'prompt' => "Câu hỏi khách: {{trigger.text}}\nThông tin nội bộ: {{kg.snippet}}\nTrả lời:",
			) ),
			self::n( 'o1', 'action',  'action.reply_zalo', 960, 80, array(
				'label' => 'Trả lời Zalo OA',
				'text'  => '{{llm.output}}',
			) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 960, 240, array(
				'label'       => 'Ghi CRM · auto_reply',
				'event_type'  => 'reminder_zalo',
				'title'       => '[care] Tự động trả lời: {{trigger.text}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'l1' ),
			self::e( 'l1', 'o1' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'           => 'tpl_zalo_oa_auto_reply_v1',
			'name'           => '[Care] Zalo OA · Tự động trả lời CSKH',
			'description'    => 'Khách nhắn Zalo OA → tra Knowledge Graph → LLM soạn câu trả lời chăm sóc → reply ngay + ghi CRM. Dùng cho Path B (zone=crm): nhân viên CSKH chỉ cần bật/tắt và gán OA ID.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'MessageCircle',
			'tags'           => 'care,crm-path,zalo-oa,cskh,kg,llm,auto-reply',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_oa_auto_reply_v1', 'zone' => 'crm' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '', 'zone' => 'crm' ),
		);
	}

	/**
	 * Care Template 2 — Phân loại ý định khách → route phòng ban.
	 *
	 * Khách nhắn → LLM phân loại ý định (mua hàng / khiếu nại / kỹ thuật / khác)
	 * → route tới đúng channel/phòng ban → ghi CRM với tag tương ứng.
	 */
	private static function bp_care_zalo_classify_route(): array {
		// [2026-06-07 Johnny Chu] CRM-PATH-2 — care template 2: classify + route.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo OA · tin nhắn khách',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'clf', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Phân loại ý định',
				'model'  => 'gpt-4o-mini',
				'system' => 'Phân loại ý định khách hàng thành 1 trong 4 nhãn duy nhất: MUA_HANG, KHIEU_NAI, KY_THUAT, KHAC. Chỉ trả về nhãn, không giải thích.',
				'prompt' => 'Tin nhắn: {{trigger.text}}',
			) ),
			self::n( 'gate', 'condition', 'logic.condition', 640, 80, array(
				'label'      => 'Route theo ý định',
				'expression' => "clf.output == 'MUA_HANG'",
			) ),
			self::n( 'c_buy', 'action', 'action.create_crm_event', 960, 0, array(
				'label'       => 'CRM · Mua hàng',
				'event_type'  => 'task',
				'title'       => '[care/mua_hang] {{trigger.text}}',
			) ),
			self::n( 'c_oth', 'action', 'action.create_crm_event', 960, 200, array(
				'label'       => 'CRM · Khác / chuyển tiếp',
				'event_type'  => 'task',
				'title'       => '[care/{{clf.output}}] {{trigger.text}}',
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 1280, 80, array(
				'label' => 'Xác nhận tiếp nhận',
				'text'  => 'Cảm ơn anh/chị đã liên hệ! Chúng tôi đã ghi nhận và sẽ phản hồi sớm nhất.',
			) ),
		);
		$edges = array(
			self::e( 't1',   'clf' ),
			self::e( 'clf',  'gate' ),
			self::e( 'gate', 'c_buy', 'true' ),
			self::e( 'gate', 'c_oth', 'false' ),
			self::e( 'c_buy', 'reply' ),
			self::e( 'c_oth', 'reply' ),
		);
		return array(
			'slug'           => 'tpl_zalo_classify_route_v1',
			'name'           => '[Care] Zalo OA · Phân loại ý định → Route phòng ban',
			'description'    => 'Khách nhắn Zalo OA → LLM phân loại ý định (mua hàng / khiếu nại / kỹ thuật / khác) → tạo CRM event với tag tương ứng + reply xác nhận tiếp nhận. Dùng cho Path B (zone=crm).',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'GitBranch',
			'tags'           => 'care,crm-path,zalo-oa,classify,route,cskh',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_classify_route_v1', 'zone' => 'crm' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '', 'zone' => 'crm' ),
		);
	}

	/**
	 * Care Template 3 — Tag khách + assign nhân viên chăm sóc.
	 *
	 * Khách nhắn → LLM gợi ý tag (VIP / new / churn_risk / ...) và
	 * assignee → ghi CRM event + reply xác nhận.
	 */
	private static function bp_care_zalo_tag_assign(): array {
		// [2026-06-07 Johnny Chu] CRM-PATH-2 — care template 3: tag + assign.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo OA · tin nhắn khách',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'tag', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Gợi ý tag khách',
				'model'  => 'gpt-4o-mini',
				'system' => 'Phân tích tin nhắn khách và trả về JSON (không markdown): {"tag":"vip|new|churn_risk|regular","priority":"high|medium|low","note":"<lý do ngắn>"}.',
				'prompt' => 'Tin nhắn khách: {{trigger.text}}',
			) ),
			self::n( 'c1', 'action', 'action.create_crm_event', 640, 80, array(
				'label'       => 'Ghi CRM · tag + assign',
				'event_type'  => 'task',
				'title'       => '[care/tag] {{trigger.text}}',
				'description' => "Tag analysis: {{tag.output}}\nChat: {{trigger.chat_id}}\nChannel: {{trigger.platform}}",
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Xác nhận + ưu tiên chăm sóc',
				'text'  => 'Cảm ơn anh/chị! Yêu cầu đã được ghi nhận và chuyển tới bộ phận phụ trách.',
			) ),
		);
		$edges = array(
			self::e( 't1',   'tag' ),
			self::e( 'tag',  'c1' ),
			self::e( 'c1',   'reply' ),
		);
		return array(
			'slug'           => 'tpl_zalo_tag_assign_v1',
			'name'           => '[Care] Zalo OA · Tag khách + Assign nhân viên',
			'description'    => 'Khách nhắn Zalo OA → LLM phân tích tag (VIP/new/churn_risk) và độ ưu tiên → ghi CRM event với JSON tag analysis + reply xác nhận. Nhân viên CSKH thấy trong CRM Scheduler để follow up. Dùng cho Path B (zone=crm).',
			'category'       => 'care',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Tags',
			'tags'           => 'care,crm-path,zalo-oa,tag,assign,cskh',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_tag_assign_v1', 'zone' => 'crm' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '', 'zone' => 'crm' ),
		);
	}

	// ─── SEED-DEPLAO (2026-06-07) ────────────────────────────────────────────────
	// 5 Deplao CRM-style templates — keyword-based static replies, no AI/LLM cost.
	// Designed for Vietnamese SMBs needing fast, free, maintainable automations.
	// Phong cách: condition chain, static text, ghi CRM lead — giống Deplao CRM
	// "Trả lời theo từ khoá" (xem screenshot trong roadmap PHASE-0.41).

	/**
	 * SEED-DEPLAO 1 — "Trả lời theo từ khoá" (keyword chain, no AI).
	 *
	 * Exact Deplao CRM pattern from screenshot:
	 *   Khi nhận tin nhắn → IF "giá" → Gửi bảng giá
	 *                     → ELSE IF "địa chỉ" → Gửi địa chỉ
	 *                     → ELSE IF "giờ" / "mở cửa" → Gửi giờ làm việc
	 *                     → ELSE → Trả lời mặc định
	 *
	 * Không cần AI — phù hợp SMB Việt Nam. Staff chỉ cần sửa nội dung text
	 * trong các node reply sau khi instantiate.
	 *
	 * priority=5 (thấp) — mọi keyword-specific workflow (priority=10+) fire trước.
	 * is_fallback=false — đây là keyword workflow, không phải fallback AI.
	 *
	 * @since SEED-DEPLAO (2026-06-07)
	 */
	private static function bp_deplao_keyword_chain(): array {
		// [2026-06-07 Johnny Chu] SEED-DEPLAO 1 — Deplao CRM keyword chain, no AI.
		// [2026-06-17 Johnny Chu] SEED-DEPLAO fix-font — thay \uXXXX bằng ký tự UTF-8 thực.
		$nodes = array(
			self::n( 't1',       'trigger',   'trigger.zalo_inbound', 0,    200, array(
				'label'       => 'Khi nhận tin nhắn',
				'instance_id' => '',
				'filter'      => '',
			) ),
			// Level 1 — check "giá"
			self::n( 'c_price',  'condition', 'logic.condition',      320,  200, array(
				'label'      => 'Có chứa "giá"?',
				'expression' => "trigger.text contains 'gia' || trigger.text contains 'giá' || trigger.text contains 'bảng giá'",
			) ),
			self::n( 'r_price',  'action',    'action.reply_zalo',    640,  60,  array(
				'label' => 'Gửi bảng giá',
				'text'  => "Bảng giá sản phẩm:\n• Gói A: 500.000đ/tháng\n• Gói B: 1.200.000đ/tháng\n• Gói C: 2.500.000đ/tháng\n\nLiên hệ để được tư vấn thêm! 😊",
			) ),
			// Level 2 — check "địa chỉ"
			self::n( 'c_addr',   'condition', 'logic.condition',      640,  340, array(
				'label'      => 'Có chứa "địa chỉ"?',
				'expression' => "trigger.text contains 'địa chỉ' || trigger.text contains 'điện thoại' || trigger.text contains 'cửa hàng'",
			) ),
			self::n( 'r_addr',   'action',    'action.reply_zalo',    960,  200, array(
				'label' => 'Gửi địa chỉ',
				'text'  => "📍 Địa chỉ cửa hàng:\n123 Nguyễn Huệ, Q.1, TP.HCM\n\n⏰ Giờ làm việc: 8:00 – 22:00 (T2–CN)\n📞 Hotline: 0901 234 567\n\nRất vui được phục vụ anh/chị! 🙏",
			) ),
			// Level 3 — check "giờ" / "mở cửa"
			self::n( 'c_hours',  'condition', 'logic.condition',      960,  480, array(
				'label'      => 'Có chứa "giờ"?',
				'expression' => "trigger.text contains 'giờ' || trigger.text contains 'mở cửa' || trigger.text contains 'làm việc'",
			) ),
			self::n( 'r_hours',  'action',    'action.reply_zalo',    1280, 340, array(
				'label' => 'Gửi giờ làm việc',
				'text'  => "⏰ Giờ làm việc của shop:\nThứ 2 – Chủ nhật: 8:00 – 22:00\n\nNgoài giờ này, bạn có thể để lại tin nhắn, shop sẽ phản hồi ngay khi mở cửa. 😊",
			) ),
			// Default (all false paths end here)
			self::n( 'r_default','action',    'action.reply_zalo',    1280, 560, array(
				'label' => 'Trả lời mặc định',
				'text'  => "Cảm ơn anh/chị đã liên hệ! Nhân viên tư vấn sẽ phản hồi trong thời gian sớm nhất. Anh/chị có thể gọi hotline 0901 234 567 để được hỗ trợ ngay! 🙏",
			) ),
		);
		$edges = array(
			self::e( 't1',      'c_price' ),
			self::e( 'c_price', 'r_price',  'true' ),
			self::e( 'c_price', 'c_addr',   'false' ),
			self::e( 'c_addr',  'r_addr',   'true' ),
			self::e( 'c_addr',  'c_hours',  'false' ),
			self::e( 'c_hours', 'r_hours',  'true' ),
			self::e( 'c_hours', 'r_default','false' ),
		);
		return array(
			'slug'           => 'tpl_deplao_keyword_chain_v1',
			'name'           => '[Deplao] Trả lời theo từ khoá (giá / địa chỉ / giờ / mặc định)',
			'description'    => 'Exact Deplao CRM pattern: mọi tin nhắn Zalo → IF chứa "giá" → gửi bảng giá; ELSE IF "địa chỉ" → gửi địa chỉ; ELSE IF "giờ" → gửi giờ làm việc; ELSE → trả lời mặc định. Không tốn AI, phù hợp SMB. Staff sửa text trong từng node sau khi instantiate.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'MessageCircle',
			'tags'           => 'deplao,keyword,chain,static,no-ai,zalo,cskh,smb',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_deplao_keyword_chain_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '',
				'is_fallback' => false,
				'priority'    => 5,
			),
		);
	}

	/**
	 * SEED-DEPLAO 2 — Thu th\u1eadp lead / t\u01b0 v\u1ea5n (no AI, pure static reply + CRM).
	 *
	 * Khi khách nhắn từ "tư vấn", "order", "mua", "đặt hàng" → reply ngay
	 * xin SĐT + ghi CRM lead event. Không cần LLM.
	 *
	 * @since SEED-DEPLAO (2026-06-07)
	 */
	private static function bp_deplao_lead_collect(): array {
		// [2026-06-07 Johnny Chu] SEED-DEPLAO 2 — lead collect, no AI.
		// [2026-06-17 Johnny Chu] SEED-DEPLAO fix-font — thay \uXXXX bằng ký tự UTF-8 thực.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo · khách muốn tư vấn',
				'instance_id' => '',
				'filter'      => 'tư vấn',
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 320, 80, array(
				'label' => 'Yêu cầu SĐT / thông tin',
				'text'  => "Cảm ơn anh/chị đã quan tâm! 😊\n\nĐể được tư vấn chính xác nhất, anh/chị vui lòng cho shop biết:\n1️⃣ Tên anh/chị?\n2️⃣ Số điện thoại liên hệ?\n3️⃣ Sản phẩm / dịch vụ quan tâm?\n\nNhân viên sẽ liên hệ trong vòng 15 phút! ⚡",
			) ),
			self::n( 'lead', 'action', 'action.create_crm_event', 640, 80, array(
				'label'       => 'Ghi CRM lead',
				'event_type'  => 'lead_report',
				'title'       => '[lead/zalo] Tư vấn: {{trigger.text}}',
				'description' => "Nguồn: Zalo tư vấn\nTin nhắn: {{trigger.text}}\nChat ID: {{trigger.chat_id}}\nTên: {{trigger.sender_name}}",
			) ),
		);
		$edges = array(
			self::e( 't1',    'reply' ),
			self::e( 'reply', 'lead' ),
		);
		return array(
			'slug'           => 'tpl_deplao_lead_collect_v1',
			'name'           => '[Deplao] Thu thập lead CSKH (keyword "tư vấn")',
			'description'    => 'Khách nhắn chứa "tư vấn" → reply xin thông tin liên hệ (tên / SĐT / sản phẩm) + ghi CRM lead_report để sales follow up. Không cần AI. Deplao CRM pattern cho SMB Việt.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'UserPlus',
			'tags'           => 'deplao,lead,zalo,static,no-ai,smb,tu-van',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_deplao_lead_collect_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'tư vấn',
				'is_fallback' => false,
				'priority'    => 8,
			),
		);
	}

	/**
	 * SEED-DEPLAO 3 — Facebook comment → ghi lead + log (no AI).
	 *
	 * Khi có comment mới dưới post FB → ghi CRM lead + log để sales follow up.
	 * Không cần AI. Pattern phổ biến nhất trong Deplao CRM cho FB pages.
	 *
	 * @since SEED-DEPLAO (2026-06-07)
	 */
	private static function bp_deplao_fb_comment_lead(): array {
		// [2026-06-07 Johnny Chu] SEED-DEPLAO 3 — FB comment → CRM lead, no AI.
		// [2026-06-17 Johnny Chu] SEED-DEPLAO fix-font — thay \uXXXX bằng ký tự UTF-8 thực.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.fb_comment', 0, 80, array(
				'label'       => 'Facebook · comment mới',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'g1', 'condition', 'logic.condition', 320, 80, array(
				'label'      => 'Comment có nội dung?',
				'expression' => "trigger.comment != ''",
			) ),
			self::n( 'lead', 'action', 'action.create_crm_event', 640, 0, array(
				'label'       => 'Ghi CRM lead FB',
				'event_type'  => 'lead_report',
				'title'       => '[lead/fb] {{trigger.comment}}',
				'description' => "Nguồn: Facebook comment\nPost ID: {{trigger.post_id}}\nNgười bình luận: {{trigger.sender_id}}\nNội dung: {{trigger.comment}}",
			) ),
			self::n( 'log', 'action', 'action.log', 640, 160, array(
				'label'   => 'Log lead FB',
				'message' => '[Deplao FB Lead] Comment: {{trigger.comment}} | From: {{trigger.sender_id}} | Post: {{trigger.post_id}}',
			) ),
			self::n( 'skip', 'action', 'action.log', 640, 320, array(
				'label'   => 'Bỏ qua (comment rỗng)',
				'message' => 'Bỏ qua comment rỗng từ {{trigger.sender_id}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'g1' ),
			self::e( 'g1', 'lead', 'true' ),
			self::e( 'g1', 'log',  'true' ),
			self::e( 'g1', 'skip', 'false' ),
		);
		return array(
			'slug'           => 'tpl_deplao_fb_comment_lead_v1',
			'name'           => '[Deplao] Facebook comment → CRM lead (no AI)',
			'description'    => 'Bình luận mới dưới post FB → condition (comment không rỗng) → ghi CRM lead_report + log để sales follow up thủ công. Không cần AI. Pattern Deplao CRM cho Facebook Page.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'fb_comment',
			'icon'           => 'Facebook',
			'tags'           => 'deplao,facebook,comment,lead,static,no-ai,smb',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_deplao_fb_comment_lead_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '' ),
		);
	}

	/**
	 * SEED-DEPLAO 4 — Ngoài giờ làm việc → tự động trả lời (no AI).
	 *
	 * Workflow đơn giản nhất: mọi tin nhắn đều nhận reply ngoài giờ.
	 * Staff BẬT workflow này khi đóng cửa, TẮT khi mở cửa.
	 * Hoặc chain với một cron workflow "bật/tắt theo lịch" (future feature).
	 *
	 * priority=1 — thấp nhất, chỉ fire khi không có workflow nào khác chạy.
	 * is_fallback=true — đây là fallback cuối, sau mọi keyword workflow.
	 *
	 * @since SEED-DEPLAO (2026-06-07)
	 */
	private static function bp_deplao_out_of_hours(): array {
		// [2026-06-07 Johnny Chu] SEED-DEPLAO 4 — out-of-hours static reply, no AI.
		// [2026-06-17 Johnny Chu] SEED-DEPLAO fix-font — thay \uXXXX bằng ký tự UTF-8 thực.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Khi nhận tin nhắn (mọi giờ)',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 320, 80, array(
				'label' => 'Trả lời ngoài giờ',
				'text'  => "🔔 Shop hiện đang ngoài giờ làm việc.\n\n⏰ Giờ làm việc: 8:00 – 22:00 (T2–CN)\n\nBạn có thể để lại tin nhắn, shop sẽ phản hồi ngay khi mở cửa sáng mai! 🙏\n\nTrường hợp khẩn cấp: gọi hotline 0901 234 567.",
			) ),
		);
		$edges = array(
			self::e( 't1', 'reply' ),
		);
		return array(
			'slug'           => 'tpl_deplao_out_of_hours_v1',
			'name'           => '[Deplao] Ngoài giờ → Tự trả lời (no AI)',
			'description'    => 'Mọi tin nhắn Zalo → reply ngoài giờ + thông báo giờ làm việc. Staff bật khi đóng cửa, tắt khi mở cửa. priority=1 (lần lượt sau mọi keyword workflow). Simple nhất có thể, không tốn AI.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Clock',
			'tags'           => 'deplao,out-of-hours,static,no-ai,zalo,smb,fallback',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_deplao_out_of_hours_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '',
				'is_fallback' => true,
				'priority'    => 1,
			),
		);
	}

	/**
	 * SEED-DEPLAO 5 — Webhook thanh toán → log + ghi CRM (no AI).
	 *
	 * Trigger: webhook slug 'payment_success' (từ Casso / SePay / VietQR).
	 * Condition: payload.amount > 0 → ghi CRM event order_confirm + log.
	 * Staff cần set webhook URL trong cổng thanh toán sau khi instantiate:
	 *   POST /wp-json/bizcity-automation/v1/webhook/payment_success
	 *
	 * @since SEED-DEPLAO (2026-06-07)
	 */
	private static function bp_deplao_order_confirm(): array {
		// [2026-06-07 Johnny Chu] SEED-DEPLAO 5 — payment webhook → CRM, no AI.
		// [2026-06-17 Johnny Chu] SEED-DEPLAO fix-font — thay \uXXXX bằng ký tự UTF-8 thực.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook', 0, 80, array(
				'label'  => 'Webhook · thanh toán thành công',
				'slug'   => 'payment_success',
				'secret' => '',
			) ),
			self::n( 'g1', 'condition', 'logic.condition', 320, 80, array(
				'label'      => 'Số tiền hợp lệ?',
				'expression' => 'trigger.payload.amount > 0',
			) ),
			self::n( 'crm', 'action', 'action.create_crm_event', 640, 0, array(
				'label'       => 'Ghi CRM · đơn hàng',
				'event_type'  => 'lead_report',
				'title'       => 'Đơn hàng #{{trigger.payload.order_id}} — {{trigger.payload.amount}}đ',
				'description' => "Khách hàng: {{trigger.payload.name}}\nSố điện thoại: {{trigger.payload.phone}}\nSố tiền: {{trigger.payload.amount}}\nMã giao dịch: {{trigger.payload.transaction_id}}\nThời gian: {{trigger.received_at}}",
			) ),
			self::n( 'log_ok', 'action', 'action.log', 640, 160, array(
				'label'   => 'Log thanh toán OK',
				'message' => '[Deplao Payment] OK — order={{trigger.payload.order_id}} amount={{trigger.payload.amount}} tx={{trigger.payload.transaction_id}}',
			) ),
			self::n( 'log_skip', 'action', 'action.log', 640, 280, array(
				'label'   => 'Log bỏ qua (amount=0)',
				'message' => '[Deplao Payment] Bỏ qua — amount=0 payload={{trigger.payload}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'g1' ),
			self::e( 'g1', 'crm',      'true' ),
			self::e( 'g1', 'log_ok',   'true' ),
			self::e( 'g1', 'log_skip', 'false' ),
		);
		return array(
			'slug'           => 'tpl_deplao_order_confirm_v1',
			'name'           => '[Deplao] Webhook thanh toán → CRM đơn hàng (no AI)',
			'description'    => 'Casso / SePay / VietQR gửi webhook POST payment_success → condition amount>0 → ghi CRM lead_report (order confirm) + log. Staff cài webhook URL vào cổng thanh toán. Không cần AI, không cần token.',
			'category'       => 'webhook',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'CreditCard',
			'tags'           => 'deplao,webhook,payment,casso,sepay,vietqr,order,crm,no-ai,smb',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_deplao_order_confirm_v1' ) ),
			'trigger_config' => array( 'slug' => 'payment_success', 'secret' => '' ),
		);
	}

	// [2026-06-07 Johnny Chu] CRM-PATH-5 — Facebook Messenger CSKH auto-reply (Zone 1).
	private static function bp_care_fb_messenger_reply(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.fb_message', 0,   80, array(
				'label'       => 'Facebook Messenger · tin nhắn khách',
				'instance_id' => '',
				'filter'      => '',
			) ),
			self::n( 'a1', 'action', 'action.search_kg', 320, 80, array(
				'label' => 'Tra cứu KG',
				'query' => '{{trigger.text}}',
				'top_k' => 5,
			) ),
			self::n( 'l1', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Soạn câu trả lời CSKH',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là nhân viên CSKH thân thiện. Trả lời ngắn gọn, lịch sự, tiếng Việt.',
				'prompt' => "Câu hỏi khách: {{trigger.text}}\nThông tin sản phẩm/dịch vụ: {{kg.snippet}}\nTrả lời phù hợp cho Facebook Messenger.",
			) ),
			self::n( 'r1', 'action', 'action.reply_fb_message', 960, 80, array(
				'label' => 'Trả lời FB Messenger',
				'text'  => '{{llm.output}}',
			) ),
			self::n( 'c1', 'action', 'action.create_crm_event', 960, 240, array(
				'label'       => 'Ghi CRM · hội thoại FB',
				'event_type'  => 'task',
				'title'       => '[care/fb] {{trigger.text}}',
				'description' => "Trả lời: {{llm.output}}\nPage: {{trigger.account_id}}\nUser: {{trigger.sender_id}}",
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'l1' ),
			self::e( 'l1', 'r1' ),
			self::e( 'l1', 'c1' ),
		);
		return array(
			'slug'           => 'tpl_fb_messenger_auto_reply_v1',
			'name'           => '[Care] Facebook Messenger · CSKH Tự động',
			'description'    => 'Khách nhắn Facebook Messenger → tra KG nội bộ → LLM soạn câu trả lời thân thiện → gửi Messenger + ghi CRM. Dùng cho Path B (zone=crm, Zone 1 CRM-care).',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'fb_message',
			'icon'           => 'MessageCircle',
			'tags'           => 'care,crm-path,facebook,messenger,cskh,auto-reply',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_fb_messenger_auto_reply_v1', 'zone' => 'crm' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '', 'zone' => 'crm' ),
		);
	}

	// ─── WAVE W1-W25 (2026-06-14) — Deplao parity batch (+25 templates) ──

	/** W1 — WooCommerce đơn mới → CRM event + Zalo notify. */
	private static function bp_woo_order_created(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W1 Woo order created
		$nodes = array(
			self::n( 't1',  'trigger', 'trigger.webhook',          0,   80, array( 'label' => 'Webhook · Woo đơn mới', 'slug' => 'woo_order_created', 'secret' => '' ) ),
			self::n( 'c1',  'action',  'action.create_crm_event',  320, 80, array( 'label' => 'CRM: ghi đơn', 'event_type' => 'order_new', 'title' => 'Đơn #{{trigger.payload.order_id}} — {{trigger.payload.customer_name}}' ) ),
			self::n( 'r1',  'action',  'action.reply_zalo',        640, 80, array( 'label' => 'Zalo notify team', 'text' => '🛒 Đơn mới #{{trigger.payload.order_id}} — {{trigger.payload.total}} VND. Xem: {{trigger.payload.admin_url}}' ) ),
		);
		$edges = array( self::e( 't1', 'c1' ), self::e( 'c1', 'r1' ) );
		return array(
			'slug'           => 'tpl_woo_order_created_v1',
			'name'           => '[Woo] Đơn mới → CRM + Zalo',
			'description'    => 'WooCommerce gửi webhook khi có đơn mới → ghi CRM event + Zalo thông báo team sales.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'ShoppingCart',
			'tags'           => 'woo,order,crm,notify',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_woo_order_created_v1' ) ),
			'trigger_config' => array( 'slug' => 'woo_order_created' ),
		);
	}

	/** W2 — Woo shipped → Zalo theo dõi đơn. */
	private static function bp_woo_order_shipped(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W2 Woo shipped
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook',          0,   80, array( 'label' => 'Webhook · Woo shipped', 'slug' => 'woo_order_shipped', 'secret' => '' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',        320, 80, array( 'label' => 'Zalo: thông báo giao', 'text' => '📦 Đơn #{{trigger.payload.order_id}} đã giao GHN mã {{trigger.payload.tracking_code}}. Dự kiến: {{trigger.payload.estimate_date}}.' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event',  640, 80, array( 'label' => 'CRM: ghi giao', 'event_type' => 'order_shipped', 'title' => 'Giao hàng đơn #{{trigger.payload.order_id}}' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ), self::e( 'r1', 'c1' ) );
		return array(
			'slug'           => 'tpl_woo_order_shipped_v1',
			'name'           => '[Woo] Shipped → Zalo khách theo dõi',
			'description'    => 'Đơn WooCommerce chuyển sang trạng thái giao → Zalo thông báo khách mã vận đơn + ngày dự kiến.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'Truck',
			'tags'           => 'woo,shipping,zalo,notify',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_woo_order_shipped_v1' ) ),
			'trigger_config' => array( 'slug' => 'woo_order_shipped' ),
		);
	}

	/** W3 — Giỏ bỏ dở → nhắc Zalo. */
	private static function bp_woo_abandoned_cart(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W3 abandoned cart
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook',       0,   80, array( 'label' => 'Webhook · giỏ bỏ dở', 'slug' => 'woo_cart_abandoned', 'secret' => '' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',    320, 80, array( 'label' => 'LLM soạn nhắc', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là CSKH nhẹ nhàng, thân thiện.', 'prompt' => 'Khách {{trigger.payload.name}} đã thêm {{trigger.payload.product}} vào giỏ nhưng chưa thanh toán. Viết tin nhắn Zalo nhắc nhẹ nhàng (≤80 ký tự, có emoji).' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',    640, 80, array( 'label' => 'Gửi Zalo nhắc', 'text' => '{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_woo_abandoned_cart_v1',
			'name'           => '[Woo] Giỏ bỏ dở → Nhắc Zalo',
			'description'    => 'Giỏ hàng bỏ dở (webhook) → LLM soạn tin nhắn nhắc khách nhẹ nhàng → Zalo.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'ShoppingCart',
			'tags'           => 'woo,abandoned,cart,zalo,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_woo_abandoned_cart_v1' ) ),
			'trigger_config' => array( 'slug' => 'woo_cart_abandoned' ),
		);
	}

	/** W4 — Woo hoàn tiền → log + Zalo. */
	private static function bp_woo_refund_notify(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W4 refund notify
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook',          0,   80, array( 'label' => 'Webhook · hoàn tiền', 'slug' => 'woo_order_refunded', 'secret' => '' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event',  320, 80, array( 'label' => 'CRM: hoàn tiền', 'event_type' => 'order_refunded', 'title' => 'Hoàn đơn #{{trigger.payload.order_id}} — {{trigger.payload.amount}} VND' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',        640, 80, array( 'label' => 'Zalo notify manager', 'text' => '💸 Hoàn đơn #{{trigger.payload.order_id}} ({{trigger.payload.amount}} VND). Xem CRM để xử lý.' ) ),
		);
		$edges = array( self::e( 't1', 'c1' ), self::e( 'c1', 'r1' ) );
		return array(
			'slug'           => 'tpl_woo_refund_notify_v1',
			'name'           => '[Woo] Hoàn tiền → Log CRM + Zalo',
			'description'    => 'Webhook hoàn tiền WooCommerce → ghi CRM event order_refunded + Zalo thông báo manager.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'RefreshCw',
			'tags'           => 'woo,refund,crm,notify',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_woo_refund_notify_v1' ) ),
			'trigger_config' => array( 'slug' => 'woo_order_refunded' ),
		);
	}

	/** W5 — Sắp hết hàng → email admin. */
	private static function bp_woo_low_stock_alert(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W5 low stock
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook',       0,   80, array( 'label' => 'Webhook · sắp hết hàng', 'slug' => 'woo_low_stock', 'secret' => '' ) ),
			self::n( 'm1', 'action',  'action.send_email',    320, 80, array( 'label' => 'Email admin', 'to' => '{{site_admin_email}}', 'subject' => '[Cảnh báo] Sắp hết hàng: {{trigger.payload.product}}', 'body' => 'Sản phẩm "{{trigger.payload.product}}" còn {{trigger.payload.stock}} đơn vị. Vui lòng nhập thêm.' ) ),
		);
		$edges = array( self::e( 't1', 'm1' ) );
		return array(
			'slug'           => 'tpl_woo_low_stock_v1',
			'name'           => '[Woo] Sắp hết hàng → Email',
			'description'    => 'Webhook WooCommerce low-stock → email admin cảnh báo ngay.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'AlertTriangle',
			'tags'           => 'woo,stock,alert,email',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_woo_low_stock_v1' ) ),
			'trigger_config' => array( 'slug' => 'woo_low_stock' ),
		);
	}

	/** W6 — Liên hệ mới → welcome Zalo. */
	private static function bp_crm_new_contact_welcome(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W6 new contact welcome
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · liên hệ mới', 'event_type' => 'contact_created' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM soạn welcome', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là nhân viên chào đón khách hàng mới.', 'prompt' => 'Viết tin nhắn chào mừng khách hàng mới {{trigger.contact_name}} (≤100 ký tự, thân thiện, có emoji).' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 80, array( 'label' => 'Zalo chào mừng', 'text' => '{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_crm_new_contact_welcome_v1',
			'name'           => '[CRM] Liên hệ mới → Welcome Zalo',
			'description'    => 'Liên hệ mới tạo trong CRM → LLM soạn tin chào mừng cá nhân hoá → gửi Zalo.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'UserPlus',
			'tags'           => 'crm,contact,welcome,zalo,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_crm_new_contact_welcome_v1' ) ),
			'trigger_config' => array( 'event_type' => 'contact_created' ),
		);
	}

	/** W7 — Label gán → assign + Zalo thông báo NV. */
	private static function bp_crm_label_assigned_notify(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W7 label assigned
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · label gán', 'event_type' => 'label_assigned' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo thông báo NV', 'text' => '🏷 Hội thoại #{{trigger.conversation_id}} vừa được gán nhãn "{{trigger.label}}". Phụ trách: @{{trigger.assignee_name}}.' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_crm_label_notify_v1',
			'name'           => '[CRM] Label gán → Thông báo NV',
			'description'    => 'Khi gán label cho hội thoại → Zalo thông báo nhân viên phụ trách ngay.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Tag',
			'tags'           => 'crm,label,assign,notify,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_crm_label_notify_v1' ) ),
			'trigger_config' => array( 'event_type' => 'label_assigned' ),
		);
	}

	/** W8 — SLA quá hạn → gán manager + email. */
	private static function bp_crm_sla_breach_escalate(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W8 SLA breach
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · SLA breach', 'event_type' => 'crm_sla_breached' ) ),
			self::n( 'm1', 'action',  'action.send_email',   320, 80, array( 'label' => 'Email manager', 'to' => '{{site_admin_email}}', 'subject' => '[SLA] Hội thoại #{{trigger.conversation_id}} quá hạn', 'body' => 'Hội thoại #{{trigger.conversation_id}} chưa được xử lý. NV: {{trigger.assignee_name}}. Vui lòng xem xét.' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 80, array( 'label' => 'Zalo escalate', 'text' => '⚠️ SLA quá hạn: hội thoại #{{trigger.conversation_id}}. Manager đã được thông báo.' ) ),
		);
		$edges = array( self::e( 't1', 'm1' ), self::e( 'm1', 'r1' ) );
		return array(
			'slug'           => 'tpl_crm_sla_escalate_v1',
			'name'           => '[CRM] SLA quá hạn → Escalate',
			'description'    => 'SLA breach → email manager + Zalo cảnh báo để leo thang xử lý.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'AlertCircle',
			'tags'           => 'crm,sla,escalate,email,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_crm_sla_escalate_v1' ) ),
			'trigger_config' => array( 'event_type' => 'crm_sla_breached' ),
		);
	}

	/** W9 — Đóng hội thoại → gửi CSAT Zalo. */
	private static function bp_crm_conv_resolved_csat(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W9 CSAT
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · đóng hội thoại', 'event_type' => 'crm_conversation_resolved' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo CSAT', 'text' => '😊 Cảm ơn bạn đã liên hệ! Vui lòng đánh giá dịch vụ:\n1 ⭐ — 5 ⭐\n(Trả lời số 1-5)' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_crm_csat_v1',
			'name'           => '[CRM] Đóng hội thoại → CSAT Zalo',
			'description'    => 'Sau khi đóng/resolve hội thoại → tự gửi Zalo hỏi điểm hài lòng (CSAT).',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Star',
			'tags'           => 'crm,csat,resolved,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_crm_csat_v1' ) ),
			'trigger_config' => array( 'event_type' => 'crm_conversation_resolved' ),
		);
	}

	/** W10 — Lead nguội (cron hàng ngày) → nhắc NV. */
	private static function bp_crm_stale_lead_remind(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W10 stale lead
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',         0,   80, array( 'label' => 'Cron · 8h hàng ngày', 'schedule' => '0 8 * * *' ) ),
			self::n( 'q1', 'action',  'action.search_kg',    320, 80, array( 'label' => 'KG: lead không hoạt động 3 ngày', 'query' => 'lead status:open last_activity:<3d', 'top_k' => 10 ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 80, array( 'label' => 'Zalo nhắc NV', 'text' => '📋 Bạn có {{kg.count}} lead chưa chăm sóc > 3 ngày. Vào CRM để xem chi tiết.' ) ),
		);
		$edges = array( self::e( 't1', 'q1' ), self::e( 'q1', 'r1' ) );
		return array(
			'slug'           => 'tpl_crm_stale_lead_v1',
			'name'           => '[CRM] Lead nguội → Nhắc NV hàng ngày',
			'description'    => 'Cron 8h sáng → tìm lead không hoạt động > 3 ngày → Zalo nhắc nhân viên phụ trách.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Clock',
			'tags'           => 'cron,crm,lead,stale,remind',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_crm_stale_lead_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * *' ),
		);
	}

	/** W11 — Cron 9h → Zalo daily standup. */
	private static function bp_internal_daily_standup(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W11 daily standup
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',         0,   80, array( 'label' => 'Cron · 9h hàng ngày', 'schedule' => '0 9 * * 1-5' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM tổng hợp ngày', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là trợ lý quản lý nội bộ. Hôm nay là {{date.today}}.', 'prompt' => 'Viết tin nhắn standup buổi sáng cho team (≤5 dòng, có emoji, nhắc họ tập trung vào mục tiêu ngày, bao gồm ngày tháng và lời chúc ngắn).' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 80, array( 'label' => 'Zalo standup', 'text' => '{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_internal_standup_v1',
			'name'           => '[Nội bộ] Standup sáng — Cron 9h T2-T6',
			'description'    => 'Cron 9h sáng T2–T6 → LLM soạn tin standup ngắn → Zalo team.',
			'category'       => 'report',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Sun',
			'tags'           => 'cron,internal,standup,zalo,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_internal_standup_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 9 * * 1-5' ),
		);
	}

	/** W12 — Cron Thứ 2 → Zalo tổng hợp tuần. */
	private static function bp_internal_weekly_report(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W12 weekly report
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',         0,   80, array( 'label' => 'Cron · 8h Thứ 2', 'schedule' => '0 8 * * 1' ) ),
			self::n( 'q1', 'action',  'action.search_kg',    320, 80, array( 'label' => 'KG: báo cáo tuần', 'query' => 'crm weekly summary leads orders resolved', 'top_k' => 5 ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   640, 80, array( 'label' => 'LLM tổng hợp tuần', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là trợ lý báo cáo. Dữ liệu: {{kg.snippet}}.', 'prompt' => 'Viết báo cáo tuần ngắn cho team (≤8 dòng, có số liệu chính, emoji, tích cực).' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   960, 80, array( 'label' => 'Zalo báo cáo', 'text' => '{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'q1' ), self::e( 'q1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_internal_weekly_v1',
			'name'           => '[Nội bộ] Báo cáo tuần — Cron Thứ 2',
			'description'    => 'Cron 8h Thứ 2 → KG lấy số liệu tuần → LLM tổng hợp → Zalo team.',
			'category'       => 'report',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'BarChart3',
			'tags'           => 'cron,report,weekly,zalo,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_internal_weekly_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * 1' ),
		);
	}

	/** W13 — Cron check task quá hạn → Zalo. */
	private static function bp_internal_task_overdue(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W13 task overdue
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',         0,   80, array( 'label' => 'Cron · 8h30 hàng ngày', 'schedule' => '30 8 * * 1-5' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo task quá hạn', 'text' => '⏰ Nhắc: kiểm tra task quá hạn trên CRM Board hôm nay.' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_internal_task_overdue_v1',
			'name'           => '[Nội bộ] Task quá hạn → Nhắc Zalo',
			'description'    => 'Cron 8h30 T2–T6 → Zalo nhắc team kiểm tra task board quá hạn.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'CheckSquare',
			'tags'           => 'cron,task,overdue,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_internal_task_overdue_v1' ) ),
			'trigger_config' => array( 'schedule' => '30 8 * * 1-5' ),
		);
	}

	/** W14 — Ảnh gửi Zalo → LLM classify + ghi CRM. */
	private static function bp_zalo_image_classify(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W14 image classify
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array( 'label' => 'Zalo · ảnh gửi', 'instance_id' => '', 'filter' => '__attachment:image' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM phân loại ảnh', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là CSKH phân tích ảnh từ khách hàng.', 'prompt' => 'Ảnh: {{trigger.attachment_url}}. Xác định đây là loại yêu cầu gì (sản phẩm lỗi, xác nhận hình thức, hóa đơn, khác). Trả lời JSON: {"type":"...","summary":"..."}' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 640, 80, array( 'label' => 'CRM: ghi phân loại', 'event_type' => 'image_received', 'title' => 'Ảnh: {{llm.output}}' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   960, 80, array( 'label' => 'Zalo xác nhận', 'text' => '📷 Đã nhận và phân loại ảnh. CSKH sẽ xử lý sớm nhất.' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'c1' ), self::e( 'c1', 'r1' ) );
		return array(
			'slug'           => 'tpl_zalo_image_classify_v1',
			'name'           => '[Zalo] Ảnh → Phân loại + CRM',
			'description'    => 'Khách gửi ảnh Zalo → LLM phân loại (lỗi sản phẩm / hóa đơn / khác) → ghi CRM + xác nhận.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Image',
			'tags'           => 'zalo,image,classify,llm,crm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_image_classify_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '__attachment:image' ),
		);
	}

	/** W15 — PDF/file gửi Zalo → trích nội dung vào KG. */
	private static function bp_zalo_pdf_extract(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W15 pdf extract
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array( 'label' => 'Zalo · file PDF', 'instance_id' => '', 'filter' => '__attachment:file' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM tóm tắt tài liệu', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là trợ lý tóm tắt tài liệu.', 'prompt' => 'URL tài liệu: {{trigger.attachment_url}}. Tóm tắt nội dung chính (≤200 ký tự).' ) ),
			self::n( 'k1', 'action',  'action.search_kg',    640, 80, array( 'label' => 'Lưu vào KG', 'query' => '{{llm.output}}', 'top_k' => 0 ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   960, 80, array( 'label' => 'Zalo xác nhận', 'text' => '📄 Tài liệu đã được lưu vào Knowledge Base. Tóm tắt: {{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'k1' ), self::e( 'k1', 'r1' ) );
		return array(
			'slug'           => 'tpl_zalo_pdf_extract_v1',
			'name'           => '[Zalo] File/PDF → Lưu Knowledge Base',
			'description'    => 'Khách hoặc staff gửi file Zalo → LLM tóm tắt → lưu vào KG → xác nhận.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'FileText',
			'tags'           => 'zalo,pdf,file,kg,extract',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_pdf_extract_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '__attachment:file' ),
		);
	}

	/** W16 — Voice Zalo → transcribe → LLM tóm tắt → ghi CRM. */
	private static function bp_zalo_voice_transcribe(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W16 voice transcribe
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array( 'label' => 'Zalo · voice/audio', 'instance_id' => '', 'filter' => '__attachment:audio' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM transcribe + tóm tắt', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là trợ lý chuyển audio thành văn bản.', 'prompt' => 'File audio: {{trigger.attachment_url}}. Transcribe và tóm tắt yêu cầu chính (JSON: {"transcript":"...","request":"..."}).' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 640, 80, array( 'label' => 'CRM: ghi yêu cầu', 'event_type' => 'voice_request', 'title' => 'Voice: {{llm.output}}' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   960, 80, array( 'label' => 'Xác nhận Zalo', 'text' => '🎤 Đã nhận voice. CSKH sẽ xử lý yêu cầu sớm nhất.' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'c1' ), self::e( 'c1', 'r1' ) );
		return array(
			'slug'           => 'tpl_zalo_voice_v1',
			'name'           => '[Zalo] Voice → Transcribe + CRM',
			'description'    => 'Khách gửi voice Zalo → LLM transcribe + tóm tắt yêu cầu → ghi CRM + xác nhận.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Mic',
			'tags'           => 'zalo,voice,transcribe,crm,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_voice_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '__attachment:audio' ),
		);
	}

	/** W17 — CRM event tích điểm → Zalo báo số dư. */
	private static function bp_loyalty_points_earned(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W17 loyalty points
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · tích điểm', 'event_type' => 'loyalty_earned' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo báo điểm', 'text' => '🌟 Bạn vừa tích +{{trigger.points}} điểm từ {{trigger.reason}}! Tổng: {{trigger.balance}} điểm.' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_loyalty_earned_v1',
			'name'           => '[Loyalty] Tích điểm → Zalo thông báo',
			'description'    => 'Khi CRM ghi nhận điểm thưởng → Zalo thông báo số điểm tích lũy cho khách.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Star',
			'tags'           => 'loyalty,points,zalo,crm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_loyalty_earned_v1' ) ),
			'trigger_config' => array( 'event_type' => 'loyalty_earned' ),
		);
	}

	/** W18 — Lên hạng thành viên → Zalo chúc mừng. */
	private static function bp_loyalty_tier_upgrade(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W18 tier upgrade
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · lên hạng', 'event_type' => 'loyalty_tier_up' ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   320, 80, array( 'label' => 'LLM chúc mừng cá nhân hoá', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là nhân viên chăm sóc khách hàng trân trọng, nhiệt tình.', 'prompt' => 'Khách {{trigger.contact_name}} vừa lên hạng {{trigger.new_tier}} từ {{trigger.old_tier}}. Viết tin chúc mừng ngắn (<120 ký tự, nhiệt tình, emoji).' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 80, array( 'label' => 'Zalo chúc mừng', 'text' => '{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_loyalty_tier_up_v1',
			'name'           => '[Loyalty] Lên hạng → Zalo chúc mừng',
			'description'    => 'Khách lên hạng thành viên → LLM soạn tin chúc mừng cá nhân hoá → Zalo.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Award',
			'tags'           => 'loyalty,tier,upgrade,zalo,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_loyalty_tier_up_v1' ) ),
			'trigger_config' => array( 'event_type' => 'loyalty_tier_up' ),
		);
	}

	/** W19 — Campaign bắt đầu → Zalo thông báo team. */
	private static function bp_campaign_started_notify(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W19 campaign notify
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · campaign bắt đầu', 'event_type' => 'campaign_started' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo team', 'text' => '🚀 Campaign "{{trigger.campaign_name}}" vừa bắt đầu. Target: {{trigger.recipient_count}} khách. Theo dõi tại CRM → Campaigns.' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_campaign_started_v1',
			'name'           => '[Campaign] Bắt đầu → Zalo team',
			'description'    => 'Campaign CRM bắt đầu gửi → Zalo thông báo team số lượng target.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Megaphone',
			'tags'           => 'campaign,started,notify,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_campaign_started_v1' ) ),
			'trigger_config' => array( 'event_type' => 'campaign_started' ),
		);
	}

	/** W20 — Lịch hẹn 24h trước → Zalo nhắc khách. */
	private static function bp_appointment_reminder(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W20 appointment reminder
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · lịch hẹn sắp đến', 'event_type' => 'appointment_reminder' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   320, 80, array( 'label' => 'Zalo nhắc khách', 'text' => '📅 Nhắc lịch hẹn: {{trigger.title}}\nThời gian: {{trigger.scheduled_at}}\nĐịa điểm: {{trigger.location}}.\nVui lòng xác nhận tham dự bằng cách trả lời "OK".' ) ),
		);
		$edges = array( self::e( 't1', 'r1' ) );
		return array(
			'slug'           => 'tpl_appointment_reminder_v1',
			'name'           => '[CRM] Nhắc lịch hẹn → Zalo khách',
			'description'    => 'CRM event nhắc lịch (24h trước) → Zalo thông báo khách hàng đính kèm địa điểm.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Calendar',
			'tags'           => 'appointment,reminder,zalo,crm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_appointment_reminder_v1' ) ),
			'trigger_config' => array( 'event_type' => 'appointment_reminder' ),
		);
	}

	/** W21 — Hóa đơn quá hạn → email + CRM note. */
	private static function bp_invoice_overdue_remind(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W21 invoice overdue
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.crm_event',    0,   80, array( 'label' => 'CRM · hóa đơn quá hạn', 'event_type' => 'invoice_overdue' ) ),
			self::n( 'm1', 'action',  'action.send_email',   320, 80, array( 'label' => 'Email nhắc thanh toán', 'to' => '{{trigger.contact_email}}', 'subject' => '[Nhắc thanh toán] Hóa đơn #{{trigger.invoice_id}} đã quá hạn', 'body' => 'Kính gửi {{trigger.contact_name}},\n\nHóa đơn #{{trigger.invoice_id}} ({{trigger.amount}} VND) đã quá hạn vào {{trigger.due_date}}. Vui lòng thanh toán sớm để tránh gián đoạn dịch vụ.' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 640, 80, array( 'label' => 'CRM: ghi nhắc', 'event_type' => 'invoice_reminder_sent', 'title' => 'Nhắc hóa đơn #{{trigger.invoice_id}}' ) ),
		);
		$edges = array( self::e( 't1', 'm1' ), self::e( 'm1', 'c1' ) );
		return array(
			'slug'           => 'tpl_invoice_overdue_v1',
			'name'           => '[Finance] Hóa đơn quá hạn → Email',
			'description'    => 'Hóa đơn CRM quá hạn → email nhắc khách thanh toán + ghi log CRM.',
			'category'       => 'general',
			'source'         => 'builtin',
			'trigger_type'   => 'crm_event',
			'icon'           => 'Receipt',
			'tags'           => 'invoice,overdue,email,crm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_invoice_overdue_v1' ) ),
			'trigger_config' => array( 'event_type' => 'invoice_overdue' ),
		);
	}

	/** W22 — Keyword "tóm tắt" → LLM tóm hội thoại. */
	private static function bp_ai_summarise_thread(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W22 summarise thread
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array( 'label' => 'Zalo · keyword "tóm tắt"', 'instance_id' => '', 'filter' => 'tóm tắt' ) ),
			self::n( 'q1', 'action',  'action.search_kg',    320, 80, array( 'label' => 'KG: lịch sử hội thoại', 'query' => 'conversation history contact:{{trigger.sender_id}}', 'top_k' => 10 ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',   640, 80, array( 'label' => 'LLM tóm tắt', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là trợ lý tóm tắt. Dữ liệu hội thoại: {{kg.snippet}}.', 'prompt' => 'Tóm tắt hội thoại với khách hàng này trong 3-5 dòng, nêu vấn đề chính và kết quả.' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   960, 80, array( 'label' => 'Zalo trả kết quả', 'text' => '📋 Tóm tắt hội thoại:\n{{llm.output}}' ) ),
		);
		$edges = array( self::e( 't1', 'q1' ), self::e( 'q1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_ai_summarise_thread_v1',
			'name'           => '[AI] Tóm tắt hội thoại → Zalo',
			'description'    => 'Keyword "tóm tắt" → KG lấy lịch sử → LLM tóm tắt hội thoại → Zalo.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Brain',
			'tags'           => 'ai,summarise,zalo,kg,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_ai_summarise_thread_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => 'tóm tắt' ),
		);
	}

	/** W23 — HTTP webhook form landing page → CRM contact upsert. */
	private static function bp_http_to_crm_contact(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W23 form to CRM
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.webhook',       0,   80, array( 'label' => 'Webhook · form đăng ký', 'slug' => 'form_submitted', 'secret' => '' ) ),
			self::n( 'c1', 'action',  'action.create_crm_event', 320, 80, array( 'label' => 'CRM: upsert lead', 'event_type' => 'lead_form', 'title' => 'Form đăng ký: {{trigger.payload.name}} — {{trigger.payload.phone}}' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',    640, 80, array( 'label' => 'Zalo thông báo sales', 'text' => '📝 Lead mới từ form: {{trigger.payload.name}} ({{trigger.payload.phone}}). Nguồn: {{trigger.payload.source}}.' ) ),
		);
		$edges = array( self::e( 't1', 'c1' ), self::e( 'c1', 'r1' ) );
		return array(
			'slug'           => 'tpl_http_form_to_crm_v1',
			'name'           => '[HTTP] Form đăng ký → CRM + Zalo',
			'description'    => 'Webhook từ landing page / form → tạo CRM lead + Zalo thông báo sales.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'webhook',
			'icon'           => 'Globe',
			'tags'           => 'http,form,lead,crm,zalo',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_http_form_to_crm_v1' ) ),
			'trigger_config' => array( 'slug' => 'form_submitted' ),
		);
	}

	/** W24 — Menu bot số: 1→sản phẩm, 2→giá, 3→liên hệ. */
	private static function bp_zalo_menu_bot(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W24 menu bot
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array( 'label' => 'Zalo · keyword 1/2/3', 'instance_id' => '', 'filter' => '' ) ),
			self::n( 'g1', 'condition', 'logic.condition', 320, 80, array( 'label' => 'Số 1 = sản phẩm?', 'expression' => "trigger.text == '1'" ) ),
			self::n( 'g2', 'condition', 'logic.condition', 320, 240, array( 'label' => 'Số 2 = giá?', 'expression' => "trigger.text == '2'" ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',   640, 0,   array( 'label' => 'Trả lời sản phẩm', 'text' => '📦 Danh mục sản phẩm:\n— Sản phẩm A: ...\n— Sản phẩm B: ...\nNhập "2" để xem giá, "3" để liên hệ.' ) ),
			self::n( 'r2', 'action',  'action.reply_zalo',   640, 160, array( 'label' => 'Trả lời giá', 'text' => '💰 Bảng giá:\n— Sản phẩm A: ...đ\n— Sản phẩm B: ...đ\nNhập "3" để liên hệ tư vấn.' ) ),
			self::n( 'r3', 'action',  'action.reply_zalo',   640, 320, array( 'label' => 'Trả lời liên hệ', 'text' => '📞 Liên hệ tư vấn:\nHotline: 0123-456-789\nZalo: @yourbrand\nGiờ làm việc: T2-T6, 8h-17h.' ) ),
		);
		$edges = array(
			self::e( 't1', 'g1' ),
			self::e( 'g1', 'r1', 'true' ),
			self::e( 'g1', 'g2', 'false' ),
			self::e( 'g2', 'r2', 'true' ),
			self::e( 'g2', 'r3', 'false' ),
		);
		return array(
			'slug'           => 'tpl_zalo_menu_bot_v1',
			'name'           => '[Zalo] Menu bot 1-2-3 (tĩnh)',
			'description'    => 'Khách nhắn 1/2/3 → trả lời menu tĩnh (sản phẩm / giá / liên hệ). Không dùng AI — cực nhanh, không chi phí.',
			'category'       => 'cskh',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'MessageCircle',
			'tags'           => 'zalo,menu,bot,static,no-ai',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_menu_bot_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => '', 'is_fallback' => false, 'priority' => 5 ),
		);
	}

	/** W25 — Cron phân đoạn → broadcast campaign theo nhóm. */
	private static function bp_broadcast_cron_segment(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 CRM-PATH-3 — W25 broadcast segment
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron',          0,   80, array( 'label' => 'Cron · broadcast 10h', 'schedule' => '0 10 * * 1' ) ),
			self::n( 'q1', 'action',  'action.search_kg',     320, 80, array( 'label' => 'KG: nhóm khách VIP', 'query' => 'contact segment:vip active_last_30d', 'top_k' => 100 ) ),
			self::n( 'l1', 'llm',     'llm.compose_reply',    640, 80, array( 'label' => 'LLM soạn nội dung', 'model' => 'gpt-4o-mini', 'system' => 'Bạn là copywriter marketing.', 'prompt' => 'Viết tin nhắn broadcast hàng tuần cho khách VIP (≤100 ký tự, thân thiện, có emoji). Không cần personalize - cùng 1 nội dung.' ) ),
			self::n( 'r1', 'action',  'action.reply_zalo',    960, 80, array( 'label' => 'Zalo confirm team', 'text' => '📢 Draft broadcast VIP tuần này: {{llm.output}}\n\nDuyệt và kích hoạt campaign trong CRM → Campaigns.' ) ),
		);
		$edges = array( self::e( 't1', 'q1' ), self::e( 'q1', 'l1' ), self::e( 'l1', 'r1' ) );
		return array(
			'slug'           => 'tpl_broadcast_segment_v1',
			'name'           => '[Broadcast] Cron → Draft VIP campaign',
			'description'    => 'Cron 10h Thứ 2 → KG lấy nhóm VIP → LLM soạn nội dung broadcast → Zalo gửi draft để team duyệt.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Send',
			'tags'           => 'cron,broadcast,segment,vip,llm',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_broadcast_segment_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 10 * * 1' ),
		);
	}

	// ─── VIDEO-VEO3 batch (2026-06-14) ────────────────────────────────────────

	/**
	 * VIDEO-VEO3 A — Zalo Bot nhận ảnh → capture slot + hỏi mục đích.
	 *
	 * User gửi ảnh qua Zalo Bot (Zone 2) → capture_attachment lưu URL
	 * → set_pending_intent (resume = tpl_zalobot_video_veo3_v1) → reply hỏi.
	 * Lượt tiếp theo gõ “tạo video” → workflow B resume + đọc attachment_url từ slot.
	 */
	private static function bp_zalobot_img_capture_v1(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — A: capture image + ask intent
		$nodes = array(
			self::n( 't1',      'trigger', 'trigger.zalo_inbound',           0,   80, array(
				'label'       => 'Zalo Bot · nhận ảnh',
				'instance_id' => '',
				'filter'      => '__attachment:image',
			) ),
			self::n( 'cap',     'action',  'action.capture_attachment',      320, 80, array(
				'label'   => 'Lưu ảnh vào slot',
				'url'     => '{{trigger.media_url}}',
				'ttl_min' => 60,
			) ),
			self::n( 'pin',     'action',  'action.set_pending_intent',      640, 80, array(
				'label'         => 'Đặt slot chờ lệnh video',
				'intent'        => 'awaiting_video_command',
				'workflow_id'   => 0,
				'workflow_slug' => 'tpl_zalobot_video_veo3_v1',
				'ttl_min'       => 60,
				'slots_json'    => '{}',
			) ),
			self::n( 'reply',   'action',  'action.reply_zalo',              960, 80, array(
				'label' => 'Hỏi mục đích',
				'text'  => '✅ Đã nhận ảnh! Sếp muốn em làm gì với ảnh này? Để tạo video, gõ: “tạo video [yêu cầu]” ví dụ: tạo video cảnh biển lúc bình minh 🌅',
			) ),
		);
		$edges = array(
			self::e( 't1',    'cap' ),
			self::e( 'cap',   'pin' ),
			self::e( 'pin',   'reply' ),
		);
		return array(
			'slug'           => 'tpl_zalobot_img_capture_v1',
			'name'           => '[Zalo Bot] Được ảnh → Hỏi mục đích (Video Veo 3)',
			'description'    => 'Admin gửi ảnh qua Zalo Bot → capture URL vào pending slot (60 phút) → hỏi “sếp muốn em làm gì?”. Lượt sau gõ “tạo video” sẽ resume tpl_zalobot_video_veo3_v1 và dùng ảnh đã lưu.',
			'category'       => 'ai',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Image',
			'tags'           => 'zalo-bot,video,veo3,image,multi-turn,capture',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalobot_img_capture_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => '__attachment:image',
				'is_fallback' => false,
				'priority'    => 10,
			),
		);
	}

	/**
	 * VIDEO-VEO3 B — Keyword “tạo video” / resume từ ảnh → Veo 3 async poll → reply.
	 *
	 * 2-path via logic.condition:
	 *   - Có ảnh ({{trigger._resume.attachment_url}} khác rỗng):
	 *       LLM expand prompt → action.video_submit → rồi dừng (poller tự gửi kết quả qua Zalo).
	 *   - Không có ảnh:
	 *       Reply hỏi gửi ảnh trước, set_pending_intent quay lại workflow này.
	 *
	 * Model mặc định: kling/v1-5/i2v-pro (PiAPI). Độ dài: 5 giây. Tỷ lệ: 16:9.
	 */
	private static function bp_zalobot_video_veo3_v1(): array {
		// [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — B: create video Veo 3
		$nodes = array(
			// Trigger: keyword “tạo video” (Zone 2 Zalo Bot)
			self::n( 't1',    'trigger',   'trigger.zalo_inbound',      0,    80, array(
				'label'       => 'Zalo Bot · tạo video',
				'instance_id' => '',
				'filter'      => 'tạo video',
			) ),
			// Condition: đã có ảnh trong slot?
			self::n( 'g1',    'condition', 'logic.condition',            320,  80, array(
				'label'      => 'Đã có ảnh?',
				'expression' => "trigger._resume.attachment_url != ''",
			) ),
			// TRUE branch: có ảnh → LLM expand prompt
			self::n( 'l1',    'llm',       'llm.compose_reply',          640,  0, array(
				'label'  => 'LLM: mở rộng prompt video',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là chuyên gia viết prompt video AI. Viết prompt tiếng Anh ngắn gọn, sinh động, có chi tiết chuyển động, ánh sáng, góc máy. Tối đa 100 từ.',
				'prompt' => 'Yêu cầu của người dùng: {{trigger.text}}\nHãy viết prompt video AI chi tiết với ảnh này làm nền. Chỉ trả về prompt thuần, không giải thích.',
			) ),
			// TRUE branch: submit video job
			self::n( 'vs1',   'action',    'action.video_submit',        960,  0, array(
				'label'          => 'Tạo video Kling i2v',
				'prompt'         => '{{llm.output}}',
				'image_url'      => '{{trigger._resume.attachment_url}}',
				'model'          => 'kling/v1-5/i2v-pro', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3 — PiAPI model ID
				'duration'       => 5,
				'aspect_ratio'   => '16:9',
				'reply_template' => '⏳ Em đang tạo video Kling từ ảnh của sếp! Khoảng 2-5 phút sẽ xong. Em sẽ gửi link ngay khi hoàn thành 🎬',
				'reply_on_fail'  => '❌ Rất tiếc, tạo video Kling thất bại: {{video_submit.error}}. Sếp thử lại hoặc đổi model sang kling/v1-5/i2v-standard.',
			) ),
			// FALSE branch: chưa có ảnh → hỏi gửi ảnh
			self::n( 'r_ask', 'action',    'action.reply_zalo',          640,  200, array(
				'label' => 'Hỏi gửi ảnh',
				'text'  => '🖼️ Để tạo video, sếp cần gửi ảnh trước nhé! Gửi ảnh người, phong cảnh, sản phẩm... em sẽ tạo video từ ảnh đó.',
			) ),
			self::n( 'pin2',  'action',    'action.set_pending_intent',  960,  200, array(
				'label'         => 'Đặt slot chờ ảnh',
				'intent'        => 'awaiting_image_for_video',
				'workflow_id'   => 0,
				'workflow_slug' => 'tpl_zalobot_video_veo3_v1',
				'ttl_min'       => 30,
				'slots_json'    => '{"original_text":"{{trigger.text}}"}',
			) ),
		);
		$edges = array(
			self::e( 't1',    'g1' ),
			// TRUE: có ảnh
			self::e( 'g1',    'l1',    'true' ),
			self::e( 'l1',    'vs1' ),
			// FALSE: chưa có ảnh
			self::e( 'g1',    'r_ask', 'false' ),
			self::e( 'r_ask', 'pin2' ),
		);
		return array(
			'slug'           => 'tpl_zalobot_video_veo3_v1',
			'name'           => '[Zalo Bot] Tạo video Kling i2v từ ảnh', // [2026-06-14 Johnny Chu] PHASE-0.41 VIDEO-VEO3
			'description'    => 'Admin gõ “tạo video [yêu cầu]” qua Zalo Bot → (có ảnh slot) LLM expand prompt → Kling i2v (PiAPI) submit async → cron poll 60s → sideload WP Media → reply link. Chưa có ảnh: hỏi gửi ảnh trước.',
			'category'       => 'ai',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Video',
			'tags'           => 'zalo-bot,video,kling,piapi,image-to-video,async,cron,multi-turn',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalobot_video_veo3_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'tạo video',
				'is_fallback' => false,
				'priority'    => 8,
			),
		);
	}

	/**
	 * [2026-06-15 Johnny Chu] R-UNIFY — Admin nhắc lịch cá nhân qua Zalo Bot.
	 *
	 * Scenario: Admin nhắn "nhắc tôi 10h dự tiệc"
	 * → LLM extract title + time
	 * → action.schedule_event (event_type=reminder_personal)
	 * → Cron fires at 10h → BizCity_Reminder_Personal_Handler → Zalo Bot gửi "⏰ Nhắc lịch: Dự tiệc"
	 *
	 * Yêu cầu: Admin đã bind Zalo Bot chat_id qua PUT /bizcity-scheduler/v1/me/notify-channel.
	 */
	private static function bp_reminder_personal_zalo_bot(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo Bot · "nhắc tôi"',
				'instance_id' => '',
				'filter'      => 'nhắc tôi',
			) ),
			self::n( 'extract', 'llm', 'llm.compose_reply', 320, 80, array(
				'label'  => 'Extract: title + khi nào',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là bộ trích xuất nhắc nhở. Đọc câu của user và trả về 1 dòng JSON DUY NHẤT (không markdown), dạng: {"title":"<tên việc>","when":"<ISO datetime hoặc strtotime: +1 hour | 2026-06-15 10:00:00>"}. Ví dụ input: "nhắc tôi 10h dự tiệc" → {"title":"Dự tiệc","when":"today 10:00:00"}. Nếu không xác định được thời gian thì dùng "+1 hour".',
				'prompt' => '{{trigger.text}}',
			) ),
			self::n( 'sched', 'action', 'action.schedule_event', 640, 80, array(
				'label'         => 'Tạo reminder_personal',
				'event_type'    => 'reminder_personal',
				'title'         => '{{trigger.text}}',
				'description'   => 'Nhắc lịch từ Zalo Bot',
				'start_at'      => '+1 hour',
				'reminder_min'  => 0,
				'reminder_text' => '{{trigger.text}}',
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Xác nhận đã đặt nhắc',
				'text'  => '⏰ Đã đặt nhắc: {{sched.event_id}} — sẽ gửi Zalo Bot khi đến giờ.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'extract' ),
			self::e( 'extract', 'sched' ),
			self::e( 'sched', 'r1' ),
		);
		return array(
			'slug'         => 'tpl_reminder_personal_zalo_bot_v1',
			'name'         => 'Nhắc lịch cá nhân · Zalo Bot (R-UNIFY)',
			'description'  => 'Admin nhắn "nhắc tôi [thời gian] [việc gì]" qua Zalo Bot → LLM extract title+time → tạo reminder_personal → cron fires → Zalo Bot gửi "⏰ Nhắc lịch: ...". Cần bind Zalo Bot chat_id qua PUT /bizcity-scheduler/v1/me/notify-channel.',
			'category'     => 'personal',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'BellRing',
			'tags'         => 'zalo-bot,reminder,personal,calendar,admin,r-unify',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_reminder_personal_zalo_bot_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'nhắc tôi',
				'is_fallback' => false,
				'priority'    => 12,
			),
		);
	}

	// ─── PHASE-ZALOBOT — Zalo Bot 3-step research / astro ────────────────

	/**
	 * Zalo Bot · Nghiên cứu web 3-bước.
	 *
	 * Scenario: Admin nhắn "nghiên cứu thuế TNCN"
	 *   → Ack ngay "🔍 Đang nghiên cứu..."
	 *   → action.web_research (vertical=auto, mode=quick)
	 *   → Condition: ok?
	 *     TRUE  → reply nguồn → reply tổng hợp
	 *     FALSE → reply báo lỗi
	 *
	 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT — bp_zalobot_web_research_steps.
	 */
	private static function bp_zalobot_web_research_steps(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo Bot · nhận lệnh nghiên cứu',
				'instance_id' => '',
				'filter'      => 'nghiên cứu',
			) ),
			self::n( 'ack', 'action', 'action.reply_zalo', 320, 80, array(
				'label'       => 'Ack: đang nghiên cứu',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => '🔍 Đang nghiên cứu "{{trigger.text}}"... Đợi tôi giây lát nhé!',
			) ),
			self::n( 'research', 'action', 'action.web_research', 640, 80, array(
				'label'       => 'Web Research',
				'query'       => '{{trigger.text}}',
				'vertical'    => 'auto',
				'mode'        => 'quick',
				'max_results' => 7,
			) ),
			self::n( 'g1', 'logic', 'logic.condition', 960, 80, array(
				'label'      => 'Tìm thấy kết quả?',
				'expression' => 'research.ok == 1',
			) ),
			self::n( 'step1', 'action', 'action.reply_zalo', 1280, 20, array(
				'label'       => 'Reply: danh sách nguồn',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => '{{research.sources_text}}',
			) ),
			self::n( 'step2', 'action', 'action.reply_zalo', 1600, 20, array(
				'label'       => 'Reply: tổng hợp kết quả',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => "💡 Tổng hợp nhận định:\n\n{{research.answer_md}}",
			) ),
			self::n( 'err', 'action', 'action.reply_zalo', 1280, 200, array(
				'label'       => 'Reply: không tìm được',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => "😕 Không tìm được thông tin về \"{{trigger.text}}\". Thử lại với từ khoá khác nhé.",
			) ),
		);

		$edges = array(
			self::e( 'e1', 't1',      'ack'      ),
			self::e( 'e2', 'ack',     'research' ),
			self::e( 'e3', 'research','g1'       ),
			self::e( 'e4', 'g1',      'step1',   array( 'condition' => 'true' ) ),
			self::e( 'e5', 'step1',   'step2'    ),
			self::e( 'e6', 'g1',      'err',     array( 'condition' => 'false' ) ),
		);

		return array(
			'slug'         => 'tpl_zalobot_web_research_steps_v1',
			'name'         => 'Zalo Bot · Nghiên cứu web 3 bước',
			'description'  => 'Nhắn "nghiên cứu <chủ đề>" qua Zalo Bot → AI trả lời 3 tin: (1) ack xác nhận; (2) danh sách nguồn tham khảo; (3) tổng hợp kết luận. Không cần KG, tra cứu trực tiếp từ web.',
			'category'     => 'ai',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Globe',
			'tags'         => 'zalobot,web-research,3-step,tax,law,no-kg',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalobot_web_research_steps_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'nghiên cứu',
				'is_fallback' => false,
				'priority'    => 20,
			),
			'plan' => 'free',
		);
	}

	/**
	 * Zalo Bot · Chiêm tinh 3-bước.
	 *
	 * Scenario: Admin nhắn "chiêm tinh hôm nay"
	 *   → Ack ngay "🔭 Đang tra cứu bản đồ sao..."
	 *   → action.run_astro (engine TwinBrain Astro + LLM compose)
	 *   → Condition: có bản đồ sao?
	 *     TRUE  → reply links chart → reply nhận định LLM
	 *     FALSE → reply fallback + link tạo bản đồ mới
	 *
	 * [2026-06-18 Johnny Chu] PHASE-ZALOBOT — bp_zalobot_astro_steps.
	 */
	private static function bp_zalobot_astro_steps(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'       => 'Zalo Bot · nhận lệnh chiêm tinh',
				'instance_id' => '',
				'filter'      => 'chiêm tinh',
			) ),
			self::n( 'ack', 'action', 'action.reply_zalo', 320, 80, array(
				'label'       => 'Ack: đang tra bản đồ sao',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => '🔭 Đang tra cứu bản đồ sao của bạn... Đợi tôi chút!',
			) ),
			self::n( 'astro', 'action', 'action.run_astro', 640, 80, array(
				'label'       => 'TwinBrain Astro',
				'chat_id'     => '{{trigger.chat_id}}',
				'instance_id' => '{{trigger.instance_id}}',
				'query'       => '{{trigger.text}}',
				'compose'     => true,
			) ),
			self::n( 'g1', 'logic', 'logic.condition', 960, 80, array(
				'label'      => 'Có bản đồ sao?',
				'expression' => 'astro.passages_count > 0',
			) ),
			self::n( 'links', 'action', 'action.reply_zalo', 1280, 20, array(
				'label'       => 'Reply: links chart',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => "✨ Đã tìm thấy bản đồ sao của {{astro.coachee_name}}!\n\n📊 Bản đồ sao: {{astro.natal_url}}\n\n🌀 Transit {{astro.period_label}}: {{astro.transit_url}}",
			) ),
			self::n( 'reading', 'action', 'action.reply_zalo', 1600, 20, array(
				'label'       => 'Reply: nhận định chiêm tinh',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => "🔮 Nhận định {{astro.period_label}}:\n\n{{astro.analysis}}",
			) ),
			self::n( 'fallback', 'action', 'action.reply_zalo', 1280, 260, array(
				'label'       => 'Reply: chưa có bản đồ sao',
				'instance_id' => '{{trigger.instance_id}}',
				'chat_id'     => '{{trigger.chat_id}}',
				'text'        => "🌟 Bạn chưa có bản đồ sao trong hệ thống.\n\n👉 Tạo bản đồ sao tại:\n{{astro.create_chart_url}}\n\nSau khi tạo xong, nhắn \"chiêm tinh\" để nhận phân tích ngay qua Zalo! ✨",
			) ),
		);

		$edges = array(
			self::e( 'e1', 't1',       'ack'      ),
			self::e( 'e2', 'ack',      'astro'    ),
			self::e( 'e3', 'astro',    'g1'       ),
			self::e( 'e4', 'g1',       'links',   array( 'condition' => 'true' ) ),
			self::e( 'e5', 'links',    'reading'  ),
			self::e( 'e6', 'g1',       'fallback', array( 'condition' => 'false' ) ),
		);

		return array(
			'slug'         => 'tpl_zalobot_astro_steps_v1',
			'name'         => 'Zalo Bot · Chiêm tinh 3 bước',
			'description'  => 'Nhắn "chiêm tinh" qua Zalo Bot → AI tra cứu bản đồ sao và trả lời 3 tin: (1) ack; (2) link bản đồ sao + transit; (3) nhận định AI. Nếu chưa có bản đồ → gửi link tạo mới. Yêu cầu module bizcoach-pro đã kích hoạt.',
			'category'     => 'ai',
			'source'       => 'builtin',
			'trigger_type' => 'zalo_inbound',
			'icon'         => 'Star',
			'tags'         => 'zalobot,astro,chiemtinh,3-step,bizcoach',
			'graph'        => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalobot_astro_steps_v1' ) ),
			'trigger_config' => array(
				'instance_id' => '',
				'filter'      => 'chiêm tinh',
				'is_fallback' => false,
				'priority'    => 21,
			),
			'plan' => 'pro',
		);
	}

	// ─── AUTOMATION-CAL — Daily Facebook post templates (cron-based) ─────

	/**
	 * Đăng FB hàng ngày 08:00 — Cron → KG topic → LLM caption → publish.
	 *
	 * Flow:
	 *   trigger.cron 0 8 * * *
	 *     → action.search_kg (tìm chủ đề hôm nay từ KG)
	 *     → llm.compose_reply (soạn caption FB 4-6 dòng + emoji + hashtag)
	 *     → action.publish_fb_post (mode=now → BizCity_FB_Publisher fire trong 5 phút)
	 *     → action.log (audit)
	 *
	 * Cần cấu hình sau khi instantiate:
	 *  - Điền fb_page_id vào node `publish`.
	 *  - Bật enabled=1 sau khi kiểm thử thủ công.
	 *  - (Tùy chọn) Điều chỉnh query KG cho phù hợp nội dung trang.
	 *
	 * @since AUTOMATION-CAL (2026-06-16)
	 */
	private static function bp_daily_fb_post_8h(): array {
		// [2026-06-16 Johnny Chu] AUTOMATION-CAL — template đăng FB hàng ngày 8h
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 08:00 mỗi ngày',
				'schedule' => '0 8 * * *',
			) ),
			self::n( 'kg', 'action', 'action.search_kg', 320, 80, array(
				'label' => 'Tìm chủ đề hôm nay (KG)',
				'query' => 'chủ đề đăng Facebook hôm nay',
				'top_k' => 3,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Soạn caption FB buổi sáng',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter Facebook chuyên nghiệp. Viết caption FB tiếng Việt: 4-6 dòng, 2-4 emoji, kết thúc bằng 3-5 hashtag liên quan. Tone tự nhiên, gần gũi, thân thiện. KHÔNG markdown, KHÔNG ```. Nếu KG không có chủ đề cụ thể, hãy tạo post truyền cảm hứng buổi sáng phù hợp với lĩnh vực kinh doanh.',
				'prompt' => "Thời điểm đăng: 08:00 hôm nay\nChủ đề từ KG: {{kg.snippet}}\nViết caption Facebook ngắn gọn, hấp dẫn, kêu gọi tương tác.",
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 960, 80, array(
				'label'        => 'Đăng Facebook 8h',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.output}}',
				'image_url'    => '',
				'mode'         => 'now',
				'delay_min'    => 0,
			) ),
			self::n( 'log', 'action', 'action.log', 1280, 80, array(
				'label'   => 'Ghi audit log',
				'message' => 'Daily FB 8h — event_id={{publish.event_id}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'kg' ),
			self::e( 'kg', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_fb_post_8h_v1',
			'name'           => 'Đăng FB hàng ngày 08:00 (Cron + LLM)',
			'description'    => 'Cron 8h mỗi ngày → tra KG tìm chủ đề → LLM soạn caption FB (4-6 dòng + emoji + hashtag) → đăng ngay qua BizCity_FB_Publisher. Cần điền fb_page_id sau khi instantiate.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Facebook',
			'tags'           => 'cron,daily,facebook,publish,llm,kg,8h',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_fb_post_8h_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * *' ),
		);
	}

	/**
	 * Đăng FB hàng ngày 09:00 — pattern giống 8h, schedule khác.
	 *
	 * @since AUTOMATION-CAL (2026-06-16)
	 */
	private static function bp_daily_fb_post_9h(): array {
		// [2026-06-16 Johnny Chu] AUTOMATION-CAL — template đăng FB hàng ngày 9h
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 09:00 mỗi ngày',
				'schedule' => '0 9 * * *',
			) ),
			self::n( 'kg', 'action', 'action.search_kg', 320, 80, array(
				'label' => 'Tìm chủ đề 9h (KG)',
				'query' => 'sản phẩm dịch vụ nổi bật hôm nay',
				'top_k' => 3,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Soạn caption FB giờ vàng 9h',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter Facebook chuyên nghiệp. Viết caption FB tiếng Việt về sản phẩm/dịch vụ: 5-7 dòng, 2-4 emoji, nêu rõ giá trị/lợi ích, kết thúc bằng CTA (comment hoặc inbox) và 3-5 hashtag. Tone tự nhiên, thuyết phục nhẹ. KHÔNG markdown. Nếu KG không có thông tin, tạo post về dịch vụ tư vấn/hỗ trợ khách hàng.',
				'prompt' => "Thời điểm đăng: 09:00 hôm nay (giờ vàng)\nThông tin sản phẩm/dịch vụ từ KG: {{kg.snippet}}\nViết caption Facebook hấp dẫn, kêu gọi tương tác mạnh.",
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 960, 80, array(
				'label'        => 'Đăng Facebook 9h',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.output}}',
				'image_url'    => '',
				'mode'         => 'now',
				'delay_min'    => 0,
			) ),
			self::n( 'log', 'action', 'action.log', 1280, 80, array(
				'label'   => 'Ghi audit log',
				'message' => 'Daily FB 9h — event_id={{publish.event_id}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'kg' ),
			self::e( 'kg', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_fb_post_9h_v1',
			'name'           => 'Đăng FB hàng ngày 09:00 (Cron + LLM)',
			'description'    => 'Cron 9h mỗi ngày (giờ vàng) → tra KG sản phẩm/dịch vụ → LLM soạn caption FB với CTA mạnh → đăng ngay. Cần điền fb_page_id sau khi instantiate.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Facebook',
			'tags'           => 'cron,daily,facebook,publish,llm,kg,9h',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_fb_post_9h_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 9 * * *' ),
		);
	}

	/**
	 * Đăng FB hàng ngày 10:00 — post chia sẻ kiến thức/tips buổi sáng.
	 *
	 * @since AUTOMATION-CAL (2026-06-16)
	 */
	/** [2026-06-16 Johnny Chu] PHASE-ATH W10 — Tìm kiếm web hàng ngày → tóm tắt script → gửi Zalo Bot. */
	private static function bp_daily_research_zalo(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 08:00 mỗi ngày',
				'schedule' => '0 8 * * *',
			) ),
			self::n( 'web', 'action', 'action.web_research', 320, 80, array(
				'label'       => 'Tìm kiếm xu hướng hôm nay',
				'query'       => 'xu hướng nổi bật hôm nay {{trigger.fired_at}}',
				'mode'        => 'quick',
				'max_results' => 7,
			) ),
			self::n( 'gen', 'action', 'action.generate_content', 640, 80, array(
				'label'           => 'Tóm tắt thành script Zalo',
				'content_type'    => 'script',
				'notebook_id'     => 0,
				'prompt_template' => "Tóm tắt các thông tin sau thành một đoạn tin nhắn Zalo ngắn gọn, hấp dẫn (tối đa 300 từ), phù hợp gửi cho nhóm nội bộ:\n\n{{web.answer_md}}",
				'tone'            => 'professional',
				'max_words'       => 300,
				'character_id'    => 0,
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Gửi Zalo Bot',
				'text'  => '{{gen.content}}',
			) ),
			self::n( 'log', 'action', 'action.log', 1280, 80, array(
				'label'   => 'Ghi log',
				'message' => 'daily_research_zalo — citations={{web.citation_count}} tokens={{gen.tokens}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'web' ),
			self::e( 'web', 'gen' ),
			self::e( 'gen', 'reply' ),
			self::e( 'reply', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_research_zalo_v1',
			'name'           => 'Web Research → Script Zalo (Cron 8h)',
			'description'    => 'Cron 8h → tìm kiếm web nhanh (TwinBrain Web Quick) → LLM tóm tắt thành script 300 từ → gửi Zalo Bot. Cần chọn channel_instance sau khi import.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Globe',
			'tags'           => 'cron,daily,web_research,zalo,script,ai',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_research_zalo_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * *' ),
		);
	}

	/** [2026-06-16 Johnny Chu] PHASE-ATH W10 — Notebook → tạo bài FB → đăng lên fanpage (có delay). */
	private static function bp_daily_notebook_fb_post(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 08:00 mỗi ngày',
				'schedule' => '0 8 * * *',
			) ),
			self::n( 'gen', 'action', 'action.generate_content', 320, 80, array(
				'label'           => 'Tạo bài FB từ Notebook',
				'content_type'    => 'fb_post',
				'notebook_id'     => 0,
				'prompt_template' => 'Viết một bài đăng Facebook hấp dẫn, tiếng Việt, phong cách thương hiệu, dài 5-8 dòng, có 2-3 emoji, kết thúc bằng câu hỏi và 3-5 hashtag liên quan. Thời điểm đăng: sáng sớm {{trigger.fired_at}}.',
				'tone'            => '',
				'max_words'       => 400,
				'character_id'    => 0,
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 640, 80, array(
				'label'        => 'Đăng Facebook (delay 60p)',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.content}}',
				'image_url'    => '',
				'mode'         => 'scheduled',
				'delay_min'    => 60,
			) ),
			self::n( 'log', 'action', 'action.log', 960, 80, array(
				'label'   => 'Ghi log',
				'message' => 'daily_notebook_fb_post — event_id={{publish.event_id}} tokens={{gen.tokens}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_notebook_fb_post_v1',
			'name'           => 'Notebook → Bài FB hàng ngày (Cron 8h)',
			'description'    => 'Cron 8h → action.generate_content dùng Notebook skeleton (R-SK) → soạn bài FB phong cách thương hiệu → đăng fanpage với delay 60 phút. Điền notebook_id và fb_page_id sau khi import.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Sparkles',
			'tags'           => 'cron,daily,facebook,publish,ai,notebook,skeleton',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_notebook_fb_post_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 8 * * *' ),
		);
	}

	/** [2026-06-16 Johnny Chu] PHASE-ATH W10 — Notebook → tạo bài web → đăng WP (draft). */
	private static function bp_daily_notebook_wp_post(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 09:00 mỗi ngày',
				'schedule' => '0 9 * * *',
			) ),
			self::n( 'gen', 'action', 'action.generate_content', 320, 80, array(
				'label'           => 'Tạo bài web từ Notebook',
				'content_type'    => 'web_post',
				'notebook_id'     => 0,
				'prompt_template' => 'Viết một bài blog tiếng Việt SEO-friendly, phong cách thương hiệu, khoảng 400-600 từ, có tiêu đề H1, 2-3 đoạn chính và phần kết luận ngắn. Ngày: {{trigger.fired_at}}.',
				'tone'            => '',
				'max_words'       => 600,
				'character_id'    => 0,
			) ),
			self::n( 'publish', 'action', 'action.publish_wp_post', 640, 80, array(
				'label'     => 'Đăng WP (draft)',
				'title'     => 'Bài viết AI {{trigger.fired_at}}',
				'content'   => '{{gen.content}}',
				'image_url' => '',
				'status'    => 'draft',
				'category'  => '',
				'tags'      => 'ai-content',
				'author_id' => 0,
			) ),
			self::n( 'log', 'action', 'action.log', 960, 80, array(
				'label'   => 'Ghi log',
				'message' => 'daily_notebook_wp_post — post_id={{publish.post_id}} tokens={{gen.tokens}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_notebook_wp_post_v1',
			'name'           => 'Notebook → Bài WP hàng ngày (Cron 9h, Draft)',
			'description'    => 'Cron 9h → action.generate_content dùng Notebook skeleton (R-SK) → soạn bài blog 400-600 từ → tạo WP post (draft để review). Điền notebook_id sau khi import. Sửa title template nếu cần.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Newspaper',
			'tags'           => 'cron,daily,wordpress,publish,ai,notebook,skeleton,blog',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_notebook_wp_post_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 9 * * *' ),
		);
	}

	private static function bp_daily_fb_post_10h(): array {
		// [2026-06-16 Johnny Chu] AUTOMATION-CAL — template đăng FB hàng ngày 10h
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 10:00 mỗi ngày',
				'schedule' => '0 10 * * *',
			) ),
			self::n( 'kg', 'action', 'action.search_kg', 320, 80, array(
				'label' => 'Tìm kiến thức/tips (KG)',
				'query' => 'mẹo hay kiến thức chia sẻ khách hàng',
				'top_k' => 3,
			) ),
			self::n( 'gen', 'llm', 'llm.compose_reply', 640, 80, array(
				'label'  => 'Soạn caption FB chia sẻ kiến thức',
				'model'  => 'gpt-4o-mini',
				'system' => 'Bạn là copywriter Facebook chuyên nghiệp viết nội dung giáo dục / chia sẻ. Viết caption FB tiếng Việt: 5-8 dòng (có thể dùng danh sách ngắn với số thứ tự), 2-3 emoji, kết thúc bằng câu hỏi kêu gọi bình luận và 3-5 hashtag. Tone chuyên nghiệp nhưng gần gũi. KHÔNG markdown. Nếu KG không có nội dung, chia sẻ 3 mẹo nhỏ hữu ích về chủ đề kinh doanh/cuộc sống.',
				'prompt' => "Thời điểm đăng: 10:00 hôm nay\nNội dung kiến thức từ KG: {{kg.snippet}}\nViết bài chia sẻ kiến thức/tips hữu ích, kêu gọi độc giả bình luận kinh nghiệm.",
			) ),
			self::n( 'publish', 'action', 'action.publish_fb_post', 960, 80, array(
				'label'        => 'Đăng Facebook 10h',
				'fb_page_id'   => '',
				'fb_page_name' => '',
				'content'      => '{{gen.output}}',
				'image_url'    => '',
				'mode'         => 'now',
				'delay_min'    => 0,
			) ),
			self::n( 'log', 'action', 'action.log', 1280, 80, array(
				'label'   => 'Ghi audit log',
				'message' => 'Daily FB 10h — event_id={{publish.event_id}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'kg' ),
			self::e( 'kg', 'gen' ),
			self::e( 'gen', 'publish' ),
			self::e( 'publish', 'log' ),
		);
		return array(
			'slug'           => 'tpl_daily_fb_post_10h_v1',
			'name'           => 'Đăng FB hàng ngày 10:00 (Cron + LLM)',
			'description'    => 'Cron 10h mỗi ngày → tra KG mẹo/kiến thức → LLM soạn bài chia sẻ FB (5-8 dòng + câu hỏi CTA + hashtag) → đăng ngay. Cần điền fb_page_id sau khi instantiate.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Facebook',
			'tags'           => 'cron,daily,facebook,publish,llm,kg,10h',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_fb_post_10h_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 10 * * *' ),
		);
	}

	private static function bp_cf7_ebook_autoresponder(): array {
		// [2026-06-17 Johnny Chu] PHASE-CG-CF7 — CF7 form submit → gửi ebook SMTP + CRM lead_report.
		// Visitor điền CF7 form → trigger cf7_submit → condition email ok → wp_mail SMTP đính kèm
		// ebook từ WP Media → ghi CRM lead_report. attachment_url do staff điền sau khi import.
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cf7_submit', 0, 80, array(
				'label'        => 'CF7 · form submit',
				'form_id'      => 0,
				'filter_email' => '',
			) ),
			self::n( 'g1', 'condition', 'logic.condition', 320, 80, array(
				'label'      => 'Email có giá trị?',
				'expression' => "trigger.email != ''",
			) ),
			self::n( 'send_email', 'action', 'action.send_email', 640, 0, array(
				'label'          => 'Gửi ebook qua email (SMTP)',
				'to'             => '{{trigger.email}}',
				'subject'        => 'Ebook tặng bạn từ {{site.name}}',
				'body'           => "Xin chào {{trigger.name}},\n\nCảm ơn bạn đã quan tâm! Đây là ebook chúng tôi muốn tặng bạn theo yêu cầu.\n\nChúc bạn đọc vui! 🎁\n\n— {{site.name}}",
				'attachment_url' => '',
			) ),
			self::n( 'crm', 'action', 'action.create_crm_event', 960, 0, array(
				'label'       => 'Ghi CRM lead (CF7)',
				'event_type'  => 'lead_report',
				'title'       => '[CF7/ebook] {{trigger.name}} — {{trigger.email}}',
				'description' => "Nguồn: CF7 form\nTên: {{trigger.name}}\nEmail: {{trigger.email}}\nSĐT: {{trigger.phone}}\nURL: {{trigger.source_url}}\nForm: {{trigger.form_title}}",
			) ),
			self::n( 'log_ok', 'action', 'action.log', 960, 160, array(
				'label'   => 'Log ebook gửi OK',
				'message' => '[CF7 Ebook] OK — email={{trigger.email}} name={{trigger.name}} form={{trigger.form_title}}',
			) ),
			self::n( 'log_skip', 'action', 'action.log', 640, 240, array(
				'label'   => 'Bỏ qua (không có email)',
				'message' => '[CF7 Ebook] Bỏ qua — không có email. Form: {{trigger.form_title}}',
			) ),
		);
		$edges = array(
			self::e( 't1',        'g1' ),
			self::e( 'g1',        'send_email', 'true' ),
			self::e( 'send_email','crm' ),
			self::e( 'crm',       'log_ok' ),
			self::e( 'g1',        'log_skip',   'false' ),
		);
		return array(
			'slug'           => 'tpl_cf7_ebook_autoresponder_v1',
			'name'           => '[CF7] Tự gửi ebook khi khách điền form (SMTP + CRM)',
			'description'    => 'Khách điền Contact Form 7 → kiểm tra có email → gửi ebook đính kèm qua SMTP (cấu hình trong Channel Gateway) + ghi CRM lead_report. Staff điền attachment_url = URL file ebook từ WP Media sau khi import.',
			'category'       => 'lead',
			'source'         => 'builtin',
			'trigger_type'   => 'cf7_submit',
			'icon'           => 'FileText',
			'tags'           => 'cf7,form,ebook,email,smtp,autoresponder,lead,no-ai',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_cf7_ebook_autoresponder_v1' ) ),
			'trigger_config' => array( 'form_id' => 0, 'filter_email' => '' ),
		);
	}

	// ── PHASE-HOME-ARCH — Personal Assistant templates ────────────────────

	/**
	 * P1: Zalo keyword "việc:" → tạo task → reply xác nhận.
	 * [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — personal task from Zalo Bot
	 */
	private static function bp_personal_task_zalo_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo Bot · "việc:"',
				'instance_id' => '',
				'filter'      => 'việc:',
			) ),
			self::n( 'a1', 'action', 'action.personal_create_task', 320, 80, array(
				'label'       => 'Tạo task cá nhân',
				'title'       => '{{trigger.text}}',
				'description' => '',
				'due_at'      => '+1 day',
				'priority'    => 'medium',
				'user_id'     => 0,
				'source'      => 'zalo_bot',
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 640, 80, array(
				'label' => 'Xác nhận tạo task',
				'text'  => '✅ Đã tạo task: {{a1.title}} (hạn {{a1.due_at}}). Xem lịch tại trang Personal của bạn.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_personal_task_zalo_v1',
			'name'           => 'Personal — Tạo Task từ Zalo Bot',
			'description'    => 'Zalo Bot nhận tin nhắn bắt đầu bằng "việc:" → tạo task trong lịch cá nhân → reply xác nhận. Yêu cầu plugin bizcity-personal đã kích hoạt.',
			'category'       => 'personal',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'CheckSquare',
			'tags'           => 'zalo,personal,task,calendar,quick-add',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_personal_task_zalo_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => 'việc:' ),
		);
	}

	/**
	 * P2: Zalo keyword "chi:" / "thu:" → ghi tài chính → reply xác nhận.
	 * [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — personal finance from Zalo Bot
	 */
	private static function bp_personal_finance_zalo_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0,   80, array(
				'label'       => 'Zalo Bot · "chi:" / "thu:"',
				'instance_id' => '',
				'filter'      => 'chi:|thu:',
			) ),
			self::n( 'a1', 'action', 'action.personal_save_finance', 320, 80, array(
				'label'       => 'Ghi thu/chi',
				'kind'        => 'expense',
				'amount'      => '{{trigger.text}}',
				'title'       => '{{trigger.text}}',
				'note'        => '',
				'category_id' => 0,
				'occurred_at' => '',
				'source'      => 'zalo_bot',
				'user_id'     => 0,
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 640, 80, array(
				'label' => 'Xác nhận ghi chi/thu',
				'text'  => '💰 Đã ghi {{a1.kind}}: {{a1.amount}} — {{a1.title}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_personal_finance_zalo_v1',
			'name'           => 'Personal — Ghi Thu/Chi từ Zalo Bot',
			'description'    => 'Zalo Bot nhận "chi: [số tiền] [mô tả]" hoặc "thu: ..." → ghi vào sổ tài chính cá nhân → reply xác nhận. Yêu cầu plugin bizcity-personal đã kích hoạt.',
			'category'       => 'personal',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Wallet',
			'tags'           => 'zalo,personal,finance,expense,income,quick-add',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_personal_finance_zalo_v1' ) ),
			'trigger_config' => array( 'instance_id' => '', 'filter' => 'chi:|thu:' ),
		);
	}

	/**
	 * P3: Cron 21h → nhắc nhở ghi nhật ký qua Zalo Bot.
	 * [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — evening journal reminder
	 */
	private static function bp_personal_journal_evening_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0,   80, array(
				'label'    => 'Cron · 21:00 mỗi ngày',
				'schedule' => '0 21 * * *',
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 320, 80, array(
				'label' => 'Nhắc ghi nhật ký',
				'text'  => '📔 Hôm nay thế nào? Hãy ghi lại cảm xúc và khoảnh khắc đáng nhớ.' . "\n" . 'Nhắn: nhật ký: [nội dung] để lưu vào nhật ký cá nhân.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_personal_journal_evening_v1',
			'name'           => 'Personal — Nhắc Nhật Ký Buổi Tối (21h)',
			'description'    => 'Cron 21h mỗi ngày → gửi tin nhắn Zalo Bot nhắc nhở user ghi nhật ký. Cần điền instance_id Zalo Bot + user chat_id sau khi import. Yêu cầu plugin bizcity-personal đã kích hoạt.',
			'category'       => 'personal',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'BookOpen',
			'tags'           => 'cron,daily,personal,journal,evening,reminder',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_personal_journal_evening_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 21 * * *' ),
		);
	}

	/**
	 * P4: Cron 7h → morning summary qua Zalo Bot.
	 * [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — morning summary reminder
	 */
	private static function bp_personal_daily_summary_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0,   80, array(
				'label'    => 'Cron · 07:00 mỗi ngày',
				'schedule' => '0 7 * * *',
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 320, 80, array(
				'label' => 'Tóm tắt buổi sáng',
				'text'  => '☀️ Chào buổi sáng! Hôm nay là ' . date( 'l, d/m/Y' ) . '.' . "\n" . 'Gợi ý: gõ "việc: [tên task]" để thêm nhanh công việc hôm nay.' . "\n" . 'Gõ "chi: [số tiền] [mô tả]" để ghi chi tiêu.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_personal_daily_summary_v1',
			'name'           => 'Personal — Morning Summary (7h)',
			'description'    => 'Cron 7h mỗi sáng → gửi tin nhắn Zalo chào buổi sáng + gợi ý lệnh nhanh (việc:/chi:). Cần điền instance_id Zalo Bot + user chat_id sau khi import.',
			'category'       => 'personal',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'Sun',
			'tags'           => 'cron,daily,personal,morning,summary,reminder',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_personal_daily_summary_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 7 * * *' ),
		);
	}

	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS N1 — Zalo "note:" → save_note → reply_zalo
	private static function bp_personal_note_zalo_v1() {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'    => 'Zalo Bot — "note:"',
				'keywords' => array( 'note:', 'ghi:', 'note :', 'ghi :' ),
				'mode'     => 'keyword_start',
			) ),
			self::n( 'a1', 'action', 'action.personal_save_note', 320, 80, array(
				'label'       => 'Lưu ghi chú',
				'content'     => '{{trigger.text_stripped}}',
				'title'       => '',
				'notebook_id' => 0,
				'tags'        => 'zalo,auto',
				'mood'        => '',
				'ingest_kg'   => 1,
				'user_id'     => 0,
			) ),
			self::n( 'o1', 'action', 'action.reply_zalo', 640, 80, array(
				'label' => 'Xác nhận ghi chú',
				'text'  => '📓 Đã lưu ghi chú vào notebook của bạn.' . "\n" . 'Ghi chú cũng được cập nhật vào KG Hub để AI có thể tham chiếu.',
			) ),
		);
		$edges = array(
			self::e( 't1', 'a1' ),
			self::e( 'a1', 'o1' ),
		);
		return array(
			'slug'           => 'tpl_personal_note_zalo_v1',
			'name'           => 'Personal — Ghi Chú qua Zalo (note:)',
			'description'    => 'Zalo Bot nhận "note: [nội dung]" → lưu vào notebook mặc định dưới dạng .md → ingest vào KG Hub → reply xác nhận. Cần điền instance_id Zalo Bot sau khi import.',
			'category'       => 'personal',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'NotebookPen',
			'tags'           => 'zalo,note,notebook,kg,personal,auto',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_personal_note_zalo_v1' ) ),
			'trigger_config' => array( 'keywords' => array( 'note:', 'ghi:', 'note :', 'ghi :' ) ),
		);
	}

	/** [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — Cron 9h → multi-source trending (web+Reddit+TikTok) → reply Zalo. */
	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — upgraded to 4-msg format (msg_1..4). Cron time configurable via cron_time_picker UI.
	private static function bp_daily_trending_digest_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.cron', 0, 80, array(
				'label'    => 'Cron · 09:00 mỗi ngày',
				'schedule' => '0 9 * * *',
			) ),
			self::n( 'tr', 'action', 'action.trending_research', 320, 80, array(
				'label'     => 'Tìm xu hướng hôm nay',
				'topic'     => 'xu hướng mạng xã hội Việt Nam hôm nay',
				'scope'     => '1d',
				'platforms' => 'web,reddit,tiktok',
				'language'  => 'vi',
				'output'    => 'full',
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 640, 80, array(
				'label'            => 'Gửi nghiên cứu',
				'instance_id'      => '',
				'override_chat_id' => '',
				'text'             => '{{tr.msg_1}}',
			) ),
			self::n( 'r2', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Gửi nguồn',
				'text'  => '{{tr.msg_2}}',
			) ),
			self::n( 'r3', 'action', 'action.reply_zalo', 1280, 80, array(
				'label' => 'Gửi phân tích',
				'text'  => '{{tr.msg_3}}',
			) ),
			self::n( 'r4', 'action', 'action.reply_zalo', 1600, 80, array(
				'label' => 'Gửi kết luận',
				'text'  => '{{tr.msg_4}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'tr' ),
			self::e( 'tr', 'r1' ),
			self::e( 'r1', 'r2' ),
			self::e( 'r2', 'r3' ),
			self::e( 'r3', 'r4' ),
		);
		return array(
			'slug'           => 'tpl_daily_trending_digest_v1',
			'name'           => 'Xu Hướng Hàng Ngày → Zalo (4 tin)',
			'description'    => 'Cron hàng ngày (mặc định 9h, đổi giờ bằng time picker) → multi-source trending research (web + Reddit + TikTok) → 4 tin Zalo: nghiên cứu + nguồn + phân tích + kết luận. Sau import: chọn Zalo Bot + điền override_chat_id ở node r1.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'cron',
			'icon'           => 'TrendingUp',
			'tags'           => 'cron,daily,trending,zalo,ai,social,reddit,tiktok',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_daily_trending_digest_v1' ) ),
			'trigger_config' => array( 'schedule' => '0 9 * * *' ),
		);
	}

	/** [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — Zalo "@trending [topic]" → research 7d (web+Reddit+TikTok+YouTube) → reply compact. */
	private static function bp_ondemand_trending_zalo_v1(): array {
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'    => 'Zalo Bot — "@trending"',
				'keywords' => array( '@trending', '@trend', 'trending:', 'trend:' ),
				'mode'     => 'keyword_start',
			) ),
			self::n( 'tr', 'action', 'action.trending_research', 320, 80, array(
				'label'     => 'Nghiên cứu xu hướng',
				// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — trigger only has 'text', not 'text_stripped'.
				'topic'     => '{{trigger.text}}',
				'scope'     => '7d',
				'platforms' => 'web,reddit,tiktok,youtube',
				'language'  => 'vi',
				'output'    => 'compact',
			) ),
			self::n( 'reply', 'action', 'action.reply_zalo', 640, 80, array(
				'label' => 'Gửi kết quả',
				'text'  => "🔍 KẾT QUẢ ({{tr.source_count}} nguồn — {{tr.scope}})\n────────────────────\n{{tr.answer_md}}\n\n📎 {{tr.sources_text}}",
			) ),
		);
		$edges = array(
			self::e( 't1', 'tr' ),
			self::e( 'tr', 'reply' ),
		);
		return array(
			'slug'           => 'tpl_ondemand_trending_zalo_v1',
			'name'           => 'Trending On-demand qua Zalo (@trending)',
			'description'    => 'Zalo Bot nhận "@trending [topic]" → multi-source research (7d, web+Reddit+TikTok+YouTube) → báo cáo compact + sources → reply ngay. Cần chọn channel_instance Zalo Bot sau khi import.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'Search',
			'tags'           => 'zalo,trending,ondemand,reddit,tiktok,youtube,ai',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_ondemand_trending_zalo_v1' ) ),
			'trigger_config' => array( 'keywords' => array( '@trending', '@trend' ) ),
		);
	}

	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — TRENDING T3: Vietnamese keyword trigger for quick test.
	// Supports: "xu hướng", "xem trend", "trend hôm nay", "hot nhất", "hot hôm nay" + English @trending.
	// Uses scope=1d (fast) + web+reddit+tiktok; reply_zalo with instance_id+override_chat_id pre-wired.
	private static function bp_zalo_trending_vn_v1(): array {
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — 4 reply_zalo nodes (msg_1..msg_4).
		$nodes = array(
			self::n( 't1', 'trigger', 'trigger.zalo_inbound', 0, 80, array(
				'label'    => 'Zalo Bot — Từ khoá xu hướng (VN)',
				'keywords' => array(
					'xu hướng',
					'xu huong',
					'xem trend',
					'trend hôm nay',
					'trend hom nay',
					'hot nhất',
					'hot nhat',
					'hot hôm nay',
					'hot hom nay',
					'đang hot',
					'dang hot',
					'@trending',
					'@trend',
				),
				'mode'     => 'keyword_contains',
			) ),
			self::n( 'tr', 'action', 'action.trending_research', 320, 80, array(
				'label'     => 'Tìm xu hướng (1 ngày)',
				// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — trigger only has 'text', not 'text_stripped'.
				'topic'     => '{{trigger.text}}',
				'scope'     => '1d',
				'platforms' => 'web,reddit,tiktok',
				'language'  => 'vi',
				'output'    => 'full',
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 640, 80, array(
				'label'            => 'Gửi nghiên cứu',
				'instance_id'      => '',
				'override_chat_id' => '',
				'text'             => '{{tr.msg_1}}',
			) ),
			self::n( 'r2', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Gửi nguồn',
				'text'  => '{{tr.msg_2}}',
			) ),
			self::n( 'r3', 'action', 'action.reply_zalo', 1280, 80, array(
				'label' => 'Gửi phân tích',
				'text'  => '{{tr.msg_3}}',
			) ),
			self::n( 'r4', 'action', 'action.reply_zalo', 1600, 80, array(
				'label' => 'Gửi kết luận',
				'text'  => '{{tr.msg_4}}',
			) ),
		);
		$edges = array(
			self::e( 't1', 'tr' ),
			self::e( 'tr', 'r1' ),
			self::e( 'r1', 'r2' ),
			self::e( 'r2', 'r3' ),
			self::e( 'r3', 'r4' ),
		);
		return array(
			'slug'           => 'tpl_zalo_trending_vn_v1',
			'name'           => 'Xu hướng hôm nay qua Zalo (từ khoá VN)',
			'description'    => 'Zalo Bot nhận tin có từ khoá "xu hướng", "trend hôm nay", "hot nhất"... → tìm xu hướng 1 ngày (web+Reddit+TikTok) → 4 tin: nghiên cứu + nguồn + phân tích + kết luận. Sau import: chọn Zalo Bot + người dùng nhận trong node r1.',
			'category'       => 'automation',
			'source'         => 'builtin',
			'trigger_type'   => 'zalo_inbound',
			'icon'           => 'TrendingUp',
			'tags'           => 'zalo,trending,xu-huong,test,tiktok,reddit,ai,vn',
			'graph'          => array( 'nodes' => $nodes, 'edges' => $edges, 'meta' => array( 'template' => 'tpl_zalo_trending_vn_v1' ) ),
			'trigger_config' => array( 'keywords' => array( 'xu hướng', 'trend hôm nay', 'hot nhất', '@trending' ) ),
		);
	}
}
