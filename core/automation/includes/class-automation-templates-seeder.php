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

	const SEED_VERSION    = '1.6.0';
	const VERSION_OPTION  = 'bizcity_automation_templates_seed_version';

	public static function maybe_seed(): void {
		if ( ! is_admin() ) { return; }
		$stamped = (string) get_option( self::VERSION_OPTION, '' );
		if ( $stamped === self::SEED_VERSION ) { return; }
		self::seed_all();
		update_option( self::VERSION_OPTION, self::SEED_VERSION, false );
	}

	public static function force_reseed(): array {
		$rows = self::seed_all();
		update_option( self::VERSION_OPTION, self::SEED_VERSION, false );
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

	// ─── Blueprints ──────────────────────────────────────────────────────

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function blueprints(): array {
		return array(
			self::bp_zalo_cskh(),
			self::bp_fb_lead_capture(),
			self::bp_mpr_auto_reply(),
			self::bp_cron_daily_report(),
			self::bp_webhook_to_crm(),
			self::bp_tb_web_search_alert(),
			self::bp_zalo_pilot_keyword(),       // BE-7.B pilot 1
			self::bp_zalo_pilot_fallback_brain(),// BE-7.B pilot 2 — fallback to TwinBrain
			self::bp_image_capture(),            // BE-7.C — turn 1 of multi-turn
			self::bp_post_web_with_image(),      // BE-7.C — đăng web kèm ảnh
			self::bp_post_web_image_first(),     // PG-S9-fix — Logic 1: ảnh trước → keyword sau
			self::bp_post_fb_image_first(),      // PG-S9-fix v6 — Logic 2: ảnh trước → "đăng fb" sau (linear, ngắn)
			self::bp_reminder_calendar(),        // PG-S9-fix v6 — Logic 3: nhắc lịch → CRM event (Google Calendar future)
			self::bp_post_fb_with_image(),       // BE-7.C — đăng FB kèm ảnh
			self::bp_schedule_event(),           // BE-7.D — “lên lịch” generic CRM event
			self::bp_test_smoke_manual(),        // BE-7.E — smoke test ngắn nhất
			self::bp_test_smoke_branch(),        // BE-7.E — smoke test có condition
		);
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
				'system' => 'Bạn là bộ trích xuất event. Đọc câu của user và trả về 1 dòng JSON DUY NHẤT (không markdown) form: {"title":"...","when":"+1 hour|tomorrow 9am|2026-06-05 14:00","kind":"task|meeting|reminder|reminder_zalo"}. Nếu không xác định được thời gian thì đặt "+1 hour".',
				'prompt' => '{{trigger.text}}',
			) ),
			self::n( 'sched', 'action', 'action.schedule_event', 640, 80, array(
				'label'        => 'Ghi vào Scheduler',
				'event_type'   => 'task',
				'title'        => '{{trigger.text}}',
				'description'  => 'Tạo từ Zalo: {{extract.output}}',
				'start_at'     => '+1 hour',
				'reminder_min' => 15,
			) ),
			self::n( 'r1', 'action', 'action.reply_zalo', 960, 80, array(
				'label' => 'Báo lại',
				'text'  => '✅ Đã lên lịch (event #{{sched.event_id}}, loại: {{sched.event_type}}, bắt đầu {{sched.start_at}}). Sếp xem tại Scheduler.',
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
}
