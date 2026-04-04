<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Content Tools — Bootstrap
 *
 * Loads Content Engine + 36 atomic content tools across 9 categories.
 * All tools: tool_type=atomic, accepts_skill=true, content_tier=1.
 *
 * Categories: Blog/Website, Social (8), Email (7), Product, Business Docs (6),
 *             Marketing (3), Video/Media (4), Support (3)
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_CONTENT_DIR' ) ) {
	define( 'BIZCITY_CONTENT_DIR', __DIR__ . '/' );
}

/* ── Load engine + all tool files ─────────────────────────────────── */
require_once BIZCITY_CONTENT_DIR . 'class-content-engine.php';
require_once BIZCITY_CONTENT_DIR . 'tools/blog.php';
require_once BIZCITY_CONTENT_DIR . 'tools/website.php';
require_once BIZCITY_CONTENT_DIR . 'tools/social.php';
require_once BIZCITY_CONTENT_DIR . 'tools/social_ext.php';
require_once BIZCITY_CONTENT_DIR . 'tools/email.php';
require_once BIZCITY_CONTENT_DIR . 'tools/email_ext.php';
require_once BIZCITY_CONTENT_DIR . 'tools/product.php';
require_once BIZCITY_CONTENT_DIR . 'tools/business_docs.php';
require_once BIZCITY_CONTENT_DIR . 'tools/marketing.php';
require_once BIZCITY_CONTENT_DIR . 'tools/video_media.php';
require_once BIZCITY_CONTENT_DIR . 'tools/support.php';

/* ── Register atomic tools into Intent Tools registry ─────────────── */
add_action( 'init', function () {
	if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
		return;
	}

	$tools = BizCity_Intent_Tools::instance();

	// ── Blog & Website ──
	$tools->register( 'generate_blog_content', [
		'description'   => 'Tạo nội dung blog bằng AI (chỉ text, không tạo WP post)',
		'input_fields'  => [
			'topic'  => [ 'required' => true,  'type' => 'text' ],
			'tone'   => [ 'required' => false, 'type' => 'choice', 'options' => 'professional,casual,engaging,academic' ],
			'length' => [ 'required' => false, 'type' => 'choice', 'options' => '300-500,700-1000,1000-1500' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_blog_content' );

	$tools->register( 'generate_seo_content', [
		'description'   => 'Tạo bài SEO tối ưu với meta description và heading structure',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'keywords' => [ 'required' => false, 'type' => 'text' ],
			'length'   => [ 'required' => false, 'type' => 'choice', 'options' => '1000-1500,1500-2000' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_seo_content' );

	$tools->register( 'rewrite_content', [
		'description'   => 'Viết lại / cải thiện nội dung có sẵn theo yêu cầu',
		'input_fields'  => [
			'source_content' => [ 'required' => true,  'type' => 'text' ],
			'instruction'    => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_rewrite_content' );

	// ── Social Media ──
	$tools->register( 'generate_fb_post', [
		'description'   => 'Tạo nội dung bài Facebook post',
		'input_fields'  => [
			'topic' => [ 'required' => true,  'type' => 'text' ],
			'style' => [ 'required' => false, 'type' => 'choice', 'options' => 'engaging,professional,casual,storytelling' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_fb_post' );

	$tools->register( 'generate_ad_copy', [
		'description'   => 'Tạo copy quảng cáo (Facebook Ads, Google Ads)',
		'input_fields'  => [
			'product'  => [ 'required' => true,  'type' => 'text' ],
			'audience' => [ 'required' => false, 'type' => 'text' ],
			'platform' => [ 'required' => false, 'type' => 'choice', 'options' => 'facebook,google,tiktok' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_ad_copy' );

	// ── Email ──
	$tools->register( 'generate_email_sales', [
		'description'   => 'Tạo email bán hàng / chào hàng',
		'input_fields'  => [
			'product'  => [ 'required' => true,  'type' => 'text' ],
			'audience' => [ 'required' => false, 'type' => 'text' ],
			'offer'    => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_sales' );

	$tools->register( 'generate_email_reply', [
		'description'   => 'Tạo email phản hồi khách hàng / đối tác',
		'input_fields'  => [
			'original_email' => [ 'required' => true,  'type' => 'text' ],
			'intent'         => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_reply' );

	// ── Product ──
	$tools->register( 'generate_product_desc', [
		'description'   => 'Tạo mô tả sản phẩm cho WooCommerce',
		'input_fields'  => [
			'product_name' => [ 'required' => true,  'type' => 'text' ],
			'features'     => [ 'required' => false, 'type' => 'text' ],
			'audience'     => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_product_desc' );

	/* ════════════════════════════════════════════════════════════════
	 * WEBSITE & LANDING PAGES
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_landing_page', [
		'description'   => 'Tạo nội dung landing page (hero, benefits, FAQ, CTA)',
		'input_fields'  => [
			'topic'   => [ 'required' => true,  'type' => 'text' ],
			'purpose' => [ 'required' => false, 'type' => 'choice', 'options' => 'sales,lead_gen,event,product_launch' ],
			'cta_text' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_landing_page' );

	$tools->register( 'generate_faq', [
		'description'   => 'Tạo FAQ (câu hỏi thường gặp) cho sản phẩm/dịch vụ',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'count'    => [ 'required' => false, 'type' => 'number', 'default' => 10 ],
			'audience' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_faq' );

	/* ════════════════════════════════════════════════════════════════
	 * SOCIAL MEDIA — Extended platforms
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_threads_post', [
		'description'   => 'Tạo bài Threads (Meta) — ngắn, hook mạnh',
		'input_fields'  => [
			'topic' => [ 'required' => true,  'type' => 'text' ],
			'style' => [ 'required' => false, 'type' => 'choice', 'options' => 'casual,provocative,educational,storytelling' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_threads_post' );

	$tools->register( 'generate_ig_caption', [
		'description'   => 'Tạo caption Instagram với hashtags',
		'input_fields'  => [
			'topic' => [ 'required' => true,  'type' => 'text' ],
			'style' => [ 'required' => false, 'type' => 'choice', 'options' => 'engaging,minimal,storytelling,educational' ],
			'cta'   => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_ig_caption' );

	$tools->register( 'generate_youtube_desc', [
		'description'   => 'Tạo mô tả video YouTube (SEO-optimized, timestamps)',
		'input_fields'  => [
			'topic' => [ 'required' => true, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_youtube_desc' );

	$tools->register( 'generate_tiktok_script', [
		'description'   => 'Tạo kịch bản TikTok (hook 3s + body + CTA)',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'duration' => [ 'required' => false, 'type' => 'choice', 'options' => '15s,30s,60s' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_tiktok_script' );

	$tools->register( 'generate_linkedin_post', [
		'description'   => 'Tạo bài LinkedIn (thought leadership, insights)',
		'input_fields'  => [
			'topic' => [ 'required' => true,  'type' => 'text' ],
			'style' => [ 'required' => false, 'type' => 'choice', 'options' => 'thought_leadership,case_study,career,industry_news' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_linkedin_post' );

	$tools->register( 'generate_zalo_message', [
		'description'   => 'Tạo tin nhắn Zalo OA (CSKH / quảng cáo)',
		'input_fields'  => [
			'topic'   => [ 'required' => true,  'type' => 'text' ],
			'purpose' => [ 'required' => false, 'type' => 'choice', 'options' => 'customer_care,promotion,notification,followup' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_zalo_message' );

	/* ════════════════════════════════════════════════════════════════
	 * EMAIL — Extended variants
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_email_quote', [
		'description'   => 'Tạo email báo giá sản phẩm/dịch vụ',
		'input_fields'  => [
			'product'  => [ 'required' => true,  'type' => 'text' ],
			'customer' => [ 'required' => false, 'type' => 'text' ],
			'price'    => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_quote' );

	$tools->register( 'generate_email_contract', [
		'description'   => 'Tạo email gửi hợp đồng / thoả thuận',
		'input_fields'  => [
			'topic'   => [ 'required' => true,  'type' => 'text' ],
			'parties' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_contract' );

	$tools->register( 'generate_email_announce', [
		'description'   => 'Tạo email thông báo nội bộ / bên ngoài',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'audience' => [ 'required' => false, 'type' => 'choice', 'options' => 'all_staff,department,customers,partners' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_announce' );

	$tools->register( 'generate_email_newsletter', [
		'description'   => 'Tạo email newsletter nhiều section',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'sections' => [ 'required' => false, 'type' => 'number', 'default' => 3 ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_newsletter' );

	$tools->register( 'generate_email_followup', [
		'description'   => 'Tạo email follow-up nhẹ nhàng, không áp lực',
		'input_fields'  => [
			'topic'   => [ 'required' => true,  'type' => 'text' ],
			'purpose' => [ 'required' => false, 'type' => 'choice', 'options' => 'general,sales,meeting,document,payment' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_email_followup' );

	/* ════════════════════════════════════════════════════════════════
	 * BUSINESS DOCUMENTS
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_proposal', [
		'description'   => 'Tạo proposal / đề xuất dự án',
		'input_fields'  => [
			'topic'  => [ 'required' => true,  'type' => 'text' ],
			'client' => [ 'required' => false, 'type' => 'text' ],
			'scope'  => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_proposal' );

	$tools->register( 'generate_report_content', [
		'description'   => 'Tạo nội dung báo cáo (summary, analysis, recommendations)',
		'input_fields'  => [
			'topic'       => [ 'required' => true,  'type' => 'text' ],
			'report_type' => [ 'required' => false, 'type' => 'choice', 'options' => 'summary,analysis,monthly,quarterly,annual' ],
			'data'        => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_report_content' );

	$tools->register( 'generate_policy', [
		'description'   => 'Tạo chính sách / quy định nội bộ',
		'input_fields'  => [
			'topic'        => [ 'required' => true,  'type' => 'text' ],
			'organization' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_policy' );

	$tools->register( 'generate_sop', [
		'description'   => 'Tạo SOP (quy trình chuẩn) cho nghiệp vụ',
		'input_fields'  => [
			'topic'      => [ 'required' => true,  'type' => 'text' ],
			'department' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_sop' );

	$tools->register( 'generate_job_description', [
		'description'   => 'Tạo JD tuyển dụng',
		'input_fields'  => [
			'position'   => [ 'required' => true,  'type' => 'text' ],
			'department' => [ 'required' => false, 'type' => 'text' ],
			'level'      => [ 'required' => false, 'type' => 'choice', 'options' => 'intern,junior,mid,senior,lead,manager' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_job_description' );

	$tools->register( 'generate_meeting_notes', [
		'description'   => 'Tóm tắt biên bản họp + action items',
		'input_fields'  => [
			'topic'        => [ 'required' => true,  'type' => 'text' ],
			'participants' => [ 'required' => false, 'type' => 'text' ],
			'transcript'   => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_meeting_notes' );

	/* ════════════════════════════════════════════════════════════════
	 * MARKETING & ADVERTISING
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_comparison', [
		'description'   => 'Tạo bài so sánh sản phẩm / dịch vụ',
		'input_fields'  => [
			'product_a' => [ 'required' => true,  'type' => 'text' ],
			'product_b' => [ 'required' => true,  'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_comparison' );

	$tools->register( 'generate_testimonial_request', [
		'description'   => 'Tạo tin nhắn xin đánh giá / review từ khách hàng',
		'input_fields'  => [
			'product'  => [ 'required' => true,  'type' => 'text' ],
			'customer' => [ 'required' => false, 'type' => 'text' ],
			'channel'  => [ 'required' => false, 'type' => 'choice', 'options' => 'email,zalo,sms,messenger' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_testimonial_request' );

	$tools->register( 'generate_campaign_brief', [
		'description'   => 'Tạo campaign brief cho chiến dịch marketing',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'budget'   => [ 'required' => false, 'type' => 'text' ],
			'duration' => [ 'required' => false, 'type' => 'text' ],
			'channels' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_campaign_brief' );

	/* ════════════════════════════════════════════════════════════════
	 * VIDEO & MEDIA SCRIPTS
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_video_script', [
		'description'   => 'Tạo kịch bản video (narration + hướng dẫn visual)',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'duration' => [ 'required' => false, 'type' => 'text', 'default' => '5-7 phút' ],
			'style'    => [ 'required' => false, 'type' => 'choice', 'options' => 'educational,promotional,documentary,tutorial' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_video_script' );

	$tools->register( 'generate_shorts_script', [
		'description'   => 'Tạo kịch bản video ngắn (Shorts/Reels/TikTok)',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'platform' => [ 'required' => false, 'type' => 'choice', 'options' => 'youtube_shorts,instagram_reels,tiktok' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_shorts_script' );

	$tools->register( 'generate_podcast_outline', [
		'description'   => 'Tạo outline podcast (segments, câu hỏi, transitions)',
		'input_fields'  => [
			'topic'    => [ 'required' => true,  'type' => 'text' ],
			'duration' => [ 'required' => false, 'type' => 'text', 'default' => '20-30 phút' ],
			'guests'   => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_podcast_outline' );

	$tools->register( 'generate_presentation', [
		'description'   => 'Tạo nội dung bài thuyết trình (slide deck)',
		'input_fields'  => [
			'topic'  => [ 'required' => true,  'type' => 'text' ],
			'slides' => [ 'required' => false, 'type' => 'number', 'default' => 10 ],
			'style'  => [ 'required' => false, 'type' => 'choice', 'options' => 'professional,creative,minimal,data_driven' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_presentation' );

	/* ════════════════════════════════════════════════════════════════
	 * SUPPORT & COMMUNICATION
	 * ════════════════════════════════════════════════════════════════ */

	$tools->register( 'generate_support_reply', [
		'description'   => 'Tạo phản hồi support / CSKH',
		'input_fields'  => [
			'ticket'   => [ 'required' => true,  'type' => 'text' ],
			'customer' => [ 'required' => false, 'type' => 'text' ],
			'tone'     => [ 'required' => false, 'type' => 'choice', 'options' => 'empathetic,formal,friendly' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_support_reply' );

	$tools->register( 'generate_chatbot_response', [
		'description'   => 'Tạo câu trả lời chatbot (ngắn, action-oriented)',
		'input_fields'  => [
			'message' => [ 'required' => true,  'type' => 'text' ],
			'context' => [ 'required' => false, 'type' => 'text' ],
			'persona' => [ 'required' => false, 'type' => 'text' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_chatbot_response' );

	$tools->register( 'generate_announcement', [
		'description'   => 'Tạo thông báo (What, When, Impact, Action needed)',
		'input_fields'  => [
			'topic'   => [ 'required' => true,  'type' => 'text' ],
			'channel' => [ 'required' => false, 'type' => 'choice', 'options' => 'all,email,slack,zalo,internal' ],
			'urgency' => [ 'required' => false, 'type' => 'choice', 'options' => 'normal,urgent,critical' ],
		],
		'tool_type'     => 'atomic',
		'accepts_skill' => true,
		'content_tier'  => 1,
	], 'bizcity_atomic_generate_announcement' );

}, 25 ); // priority 25: after init_builtin (priority 20)
