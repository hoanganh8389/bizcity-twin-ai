<?php
/**
 * BizCity Tool Slide — Tool Callbacks + AJAX Handlers
 *
 * Kiến trúc "Developer-packaged Pipeline" (giống tool-mindmap):
 *   1. Intent Provider khai báo goal_patterns + required_slots
 *   2. Tool callback tạo Reveal.js HTML bằng AI → lưu thành CPT bz_slide
 *   3. AJAX endpoints phục vụ trang views SPA (generate, save, list, get, delete, update)
 *
 * @package BizCity_Tool_Slide
 * @since   1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Slide {

    /* ════════════════════════════════════════════════════════════
     *  Bootstrap
     * ════════════════════════════════════════════════════════════ */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );

        // AJAX handlers cho view page SPA
        $actions = [ 'generate', 'save', 'list', 'get', 'delete', 'update' ];
        foreach ( $actions as $a ) {
            add_action( "wp_ajax_bztool_sl_{$a}", [ __CLASS__, "ajax_{$a}" ] );
        }
    }

    /* ════════════════════════════════════════════════════════════
     *  Custom Post Type: bz_slide
     * ════════════════════════════════════════════════════════════ */
    public static function register_post_type() {
        register_post_type( 'bz_slide', [
            'labels' => [
                'name'          => 'Slides',
                'singular_name' => 'Slide',
            ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Intent Engine Tool Callback — create_slide
     *
     *  Flow:
     *    T1: AI phân tích prompt → tạo Reveal.js HTML slides
     *    T2: Lưu vào CPT bz_slide + post_meta
     *
     *  @param array $slots { topic, slide_theme, num_slides, session_id, chat_id, _meta }
     *  @return array Tool Output Envelope
     * ══════════════════════════════════════════════════════════════ */
    public static function create_slide( array $slots ): array {
        $meta        = $slots['_meta']       ?? [];
        $ai_context  = $meta['_context']     ?? '';
        $topic       = self::extract_text( $slots );
        $slide_theme = $slots['slide_theme'] ?? 'auto';
        $num_slides  = intval( $slots['num_slides'] ?? 0 );
        $session_id  = $slots['session_id']  ?? '';
        $chat_id     = $slots['chat_id']     ?? '';
        $user_id     = get_current_user_id();

        if ( empty( $topic ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Cần mô tả nội dung / kịch bản bài trình bày.',
                'data'    => [], 'missing_fields' => [ 'topic' ],
            ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Module AI (OpenRouter) chưa sẵn sàng.',
                'data'    => [],
            ];
        }

        // ── Job Trace ──
        $trace = null;
        if ( class_exists( 'BizCity_Job_Trace' ) ) {
            $trace = BizCity_Job_Trace::start(
                $session_id ?: $chat_id ?: 'cli',
                'create_slide',
                [
                    'T1' => 'AI phân tích & tạo slide HTML',
                    'T2' => 'Lưu bài trình bày',
                ]
            );
        }

        // ══════════════════════════════════════════════════════
        //  T1: AI generate Reveal.js slides
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T1', 'running' );

        $result = self::ai_generate_slides( $topic, $slide_theme, $num_slides, $ai_context );

        if ( is_wp_error( $result ) ) {
            if ( $trace ) $trace->fail( $result->get_error_message() );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ ' . $result->get_error_message(),
                'data'    => [],
            ];
        }

        $title  = $result['title']       ?? wp_trim_words( $topic, 8 );
        $theme  = $result['theme']       ?? 'white';
        $slides = $result['slides_html'] ?? '';
        $desc   = $result['description'] ?? $topic;
        $count  = $result['slide_count'] ?? 0;

        if ( $trace ) $trace->step( 'T1', 'done', [ 'theme' => $theme, 'title' => $title, 'slides' => $count ] );

        // ══════════════════════════════════════════════════════
        //  T2: Save to bz_slide CPT
        // ══════════════════════════════════════════════════════
        if ( $trace ) $trace->step( 'T2', 'running' );

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_slide',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => sanitize_textarea_field( $desc ),
            'post_status'  => 'publish',
            'post_author'  => $user_id ?: 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            if ( $trace ) $trace->step( 'T2', 'failed' );
            if ( $trace ) $trace->fail( 'Không thể lưu bài trình bày' );
            return [
                'success' => false, 'complete' => false,
                'message' => '❌ Lỗi khi lưu bài trình bày vào database.',
                'data'    => [],
            ];
        }

        update_post_meta( $post_id, '_bz_slides_html', $slides );
        update_post_meta( $post_id, '_bz_slide_theme', $theme );
        update_post_meta( $post_id, '_bz_slide_count', $count );
        update_post_meta( $post_id, '_bz_prompt', $topic );

        $view_url = home_url( '/tool-slide/?id=' . $post_id );

        if ( $trace ) $trace->step( 'T2', 'done', [ 'post_id' => $post_id ] );
        if ( $trace ) $trace->complete( [ 'post_id' => $post_id, 'view_url' => $view_url ] );

        // ── Notify Telegram nếu từ Telegram ──
        if ( $chat_id && function_exists( 'twf_telegram_send_message' ) ) {
            twf_telegram_send_message( $chat_id, "✅ Đã tạo slide: {$title}\n🎬 Theme: {$theme} · {$count} slides\n🔗 {$view_url}" );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo bài trình bày: **{$title}**\n"
                . "🎬 Theme: {$theme} · {$count} slides\n"
                . "🔗 [Xem & Trình chiếu]({$view_url})",
            'data' => [
                'id'          => $post_id,
                'type'        => 'slide',
                'title'       => $title,
                'theme'       => $theme,
                'slide_count' => $count,
                'description' => $desc,
                'url'         => $view_url,
                'trace_id'    => $trace ? $trace->get_trace_id() : '',
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  AI: Generate Reveal.js HTML slides
     * ══════════════════════════════════════════════════════════════ */
    private static function ai_generate_slides( string $topic, string $theme = 'auto', int $num_slides = 0, string $ai_context = '' ) {
        $theme_hint = ( $theme !== 'auto' && $theme !== '' )
            ? "Theme slide yêu cầu: {$theme}. "
            : 'Tự chọn theme phù hợp nội dung (white, black, moon, night, serif, simple, solarized, blood, beige, league). ';

        $num_hint = $num_slides > 0
            ? "Tạo ĐÚNG {$num_slides} slides. "
            : 'Tạo 8-14 slides (nhiều slide hơn = chi tiết, chuyên nghiệp hơn). ';

        $sys = <<<'SYSPROMPT'
Bạn là chuyên gia thiết kế slide CHUYÊN NGHIỆP theo phong cách Apple Keynote / Google Slides cao cấp. Tạo nội dung HTML cho Reveal.js — KHÔNG dùng ảnh ngoài, toàn bộ visual dùng CSS thuần.

══ QUY TẮC BẮT BUỘC ══
1. Mỗi slide = 1 thẻ <section>...</section>
2. KHÔNG dùng <style>, <script>, <link>, <img> — chỉ HTML + inline style
3. KHÔNG wrap trong <div class="reveal"> hay <div class="slides">
4. Dùng tiếng Việt (trừ khi user yêu cầu tiếng Anh)
5. KHÔNG dùng ảnh từ picsum, unsplash hay bất kỳ URL ảnh nào

══ FONT SIZE (quan trọng — Reveal.js tự scale nên set nhỏ) ══
- h2 tiêu đề slide: KHÔNG set font-size (dùng default theme)
- Stat lớn: tối đa font-size:1.5em; font-weight:800
- Icon/emoji badge: font-size:1.2em
- Body / bullet text: font-size:0.78em; line-height:1.75
- Label / caption nhỏ: font-size:0.65em
- Chip / badge text: font-size:0.62em

══ COLOR PALETTE ══
Primary:   #6366f1  (indigo)
Secondary: #8b5cf6  (violet)
Accent:    #06b6d4  (cyan) | #f59e0b (amber)
Text dark: #1e293b
Text muted:#64748b
Surface:   #f8fafc
Border:    rgba(99,102,241,.18)

══ VISUAL ELEMENTS (thay cho ảnh) ══

A) ICON BLOCK — thay cho hero image:
<div style="display:flex;gap:12px;justify-content:center;margin:16px 0">
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.2em;box-shadow:0 8px 24px rgba(99,102,241,.35)">🎯</div>
  <div style="width:64px;height:64px;background:linear-gradient(135deg,#06b6d4,#3b82f6);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:1.2em;box-shadow:0 8px 24px rgba(6,182,212,.30)">📊</div>
</div>

B) DECORATIVE BLOB SHAPES — tạo chiều sâu:
<div style="position:relative;padding:24px;overflow:hidden">
  <div style="position:absolute;top:-50px;right:-50px;width:180px;height:180px;border-radius:50%;background:rgba(99,102,241,.10);pointer-events:none"></div>
  <div style="position:absolute;bottom:-40px;left:-40px;width:140px;height:140px;border-radius:50%;background:rgba(139,92,246,.08);pointer-events:none"></div>
  <div style="position:relative"><!-- nội dung --></div>
</div>

C) STAT CARDS với màu accent:
<div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
  <div style="min-width:130px;padding:20px 16px;background:#fff;border-radius:16px;border-top:3px solid #6366f1;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.07)" class="fragment">
    <div style="font-size:1.5em;font-weight:800;color:#6366f1">85%</div>
    <div style="font-size:0.65em;color:#64748b;margin-top:4px">Hài lòng</div>
  </div>
</div>

D) PROGRESS BAR — so sánh / tiến độ:
<div style="margin:10px 0">
  <div style="display:flex;justify-content:space-between;font-size:0.72em;margin-bottom:5px">
    <span style="color:#1e293b">Mục tiêu</span>
    <span style="color:#6366f1;font-weight:600">78%</span>
  </div>
  <div style="height:7px;background:#e2e8f0;border-radius:99px">
    <div style="height:7px;width:78%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:99px"></div>
  </div>
</div>

E) TIMELINE — roadmap / giai đoạn:
<div style="border-left:3px solid #6366f1;padding-left:20px;text-align:left;margin:12px 0">
  <div style="margin-bottom:14px;position:relative">
    <div style="position:absolute;left:-26px;top:3px;width:14px;height:14px;border-radius:50%;background:#6366f1;border:2px solid #fff;box-shadow:0 0 0 2px #6366f1"></div>
    <div style="font-size:0.75em;font-weight:600;color:#1e293b">Giai đoạn 1 — Q1</div>
    <div style="font-size:0.68em;color:#64748b;margin-top:2px">Mô tả ngắn gọn</div>
  </div>
</div>

F) HIGHLIGHT BOX — call-out quan trọng:
<div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:18px 24px;border-radius:14px;margin:14px 0;font-size:0.8em;font-weight:500;line-height:1.6">
  💡 Insight / takeaway quan trọng
</div>

G) FEATURE GRID — 3 cột:
<div style="display:flex;gap:14px;justify-content:center;margin-top:16px">
  <div style="flex:1;background:rgba(99,102,241,.05);padding:18px 14px;border-radius:14px;text-align:center;border:1px solid rgba(99,102,241,.15)" class="fragment">
    <div style="font-size:1.2em;margin-bottom:6px">🚀</div>
    <div style="font-size:0.72em;font-weight:600;color:#1e293b">Tiêu đề</div>
    <div style="font-size:0.65em;color:#64748b;margin-top:4px;line-height:1.5">Mô tả ngắn</div>
  </div>
</div>

H) QUOTE CARD:
<div style="background:#f8fafc;border-left:4px solid #6366f1;padding:16px 22px;border-radius:0 14px 14px 0;text-align:left;margin:14px 0">
  <div style="font-size:0.82em;font-style:italic;color:#334155;line-height:1.65">"Trích dẫn quan trọng…"</div>
  <div style="font-size:0.66em;color:#6366f1;font-weight:600;margin-top:8px">— Tên / chức vụ</div>
</div>

I) GLASSMORPHISM CARD (dùng trên gradient bg):
<div style="background:rgba(255,255,255,.15);backdrop-filter:blur(12px);padding:22px 20px;border-radius:18px;border:1px solid rgba(255,255,255,.25);text-align:center" class="fragment">
  <div style="font-size:1.5em;font-weight:800;color:#fff">+150%</div>
  <div style="font-size:0.68em;color:rgba(255,255,255,.82);margin-top:5px">Tăng trưởng</div>
</div>

J) TWO-COLUMN layout (text + visual):
<div style="display:flex;gap:28px;align-items:center;margin-top:16px">
  <div style="flex:3;text-align:left"><!-- text / bullets --></div>
  <div style="flex:2"><!-- cards / stats / icon grid --></div>
</div>

K) CHIP TAGS — context / category:
<div style="display:inline-flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:10px 0">
  <span style="background:rgba(99,102,241,.1);color:#6366f1;border-radius:99px;padding:4px 14px;font-size:0.62em;font-weight:600;border:1px solid rgba(99,102,241,.2)">🎯 Tag</span>
</div>

══ GRADIENT BACKGROUNDS (2–3 key slides) ══
Dark navy:    data-background-gradient="linear-gradient(135deg,#0f172a,#1e3a5f)"
Purple:       data-background-gradient="linear-gradient(135deg,#7c3aed,#4f46e5)"
Indigo-soft:  data-background-gradient="linear-gradient(135deg,#667eea,#764ba2)"
Teal:         data-background-gradient="linear-gradient(135deg,#0891b2,#0e7490)"
Forest:       data-background-gradient="linear-gradient(135deg,#064e3b,#065f46)"
Sunset:       data-background-gradient="linear-gradient(135deg,#b45309,#92400e)"

══ NỘI DUNG BẮT BUỘC ══
- SỐ LIỆU CỤ THỂ: %, tiền, thời gian, so sánh trước/sau — KHÔNG nói chung chung
- Ít nhất 2 slide có ví dụ thực tế / case study cụ thể
- Mỗi bullet: emoji + <strong> keyword + mô tả
- Dùng class="fragment" để animate từng item
- Data → card/stat/visual grid — KHÔNG text thuần

══ CẤU TRÚC DECK ══
1. Title slide (gradient bg tối, tiêu đề lớn, chip tags)
2. Agenda (feature grid 3-4 cột)
3–N. Nội dung (2-col hoặc grid, xen kẽ layouts)
   • 1–2 Data slides (glassmorphism stats trên gradient bg)
   • 1 Case Study (quote + progress bars + stat cards)
   • 1–2 Diagram/Process (timeline hoặc flow)
N+1. CTA / Cảm ơn / Next Steps (gradient bg + icon blocks)

══ VÍ DỤ TITLE SLIDE ══
<section data-background-gradient="linear-gradient(135deg,#0f172a,#1e3a5f)">
  <div style="text-align:center;padding:16px">
    <div style="display:inline-flex;gap:8px;background:rgba(255,255,255,.1);border-radius:99px;padding:5px 16px;margin-bottom:18px">
      <span style="font-size:0.62em;color:rgba(255,255,255,.75)">📅 2025</span>
      <span style="font-size:0.62em;color:rgba(255,255,255,.4)">•</span>
      <span style="font-size:0.62em;color:rgba(255,255,255,.75)">Chủ đề báo cáo</span>
    </div>
    <h2 style="color:#fff;margin:0 0 14px;line-height:1.25">Tiêu Đề Bài Trình Bày</h2>
    <p style="color:rgba(255,255,255,.68);font-size:0.78em;max-width:580px;margin:0 auto;line-height:1.65">Phụ đề mô tả ngắn gọn nội dung và mục tiêu chính</p>
    <div style="display:flex;gap:10px;justify-content:center;margin-top:22px;flex-wrap:wrap">
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:7px 16px;font-size:0.65em;color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.15)">🎯 Mục tiêu</div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:7px 16px;font-size:0.65em;color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.15)">📊 Dữ liệu</div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:7px 16px;font-size:0.65em;color:rgba(255,255,255,.85);border:1px solid rgba(255,255,255,.15)">🚀 Giải pháp</div>
    </div>
  </div>
</section>

══ VÍ DỤ STATS SLIDE (glassmorphism) ══
<section data-background-gradient="linear-gradient(135deg,#667eea,#764ba2)">
  <h2 style="color:#fff">📈 Kết quả nổi bật</h2>
  <div style="display:flex;gap:18px;justify-content:center;margin-top:22px;flex-wrap:wrap">
    <div style="background:rgba(255,255,255,.15);backdrop-filter:blur(12px);padding:22px 18px;border-radius:18px;border:1px solid rgba(255,255,255,.22);text-align:center;min-width:120px" class="fragment">
      <div style="font-size:1.5em;font-weight:800;color:#fff">+150%</div>
      <div style="font-size:0.66em;color:rgba(255,255,255,.8);margin-top:5px">Doanh thu</div>
    </div>
    <div style="background:rgba(255,255,255,.15);backdrop-filter:blur(12px);padding:22px 18px;border-radius:18px;border:1px solid rgba(255,255,255,.22);text-align:center;min-width:120px" class="fragment">
      <div style="font-size:1.5em;font-weight:800;color:#fff">50K</div>
      <div style="font-size:0.66em;color:rgba(255,255,255,.8);margin-top:5px">Khách hàng</div>
    </div>
    <div style="background:rgba(255,255,255,.15);backdrop-filter:blur(12px);padding:22px 18px;border-radius:18px;border:1px solid rgba(255,255,255,.22);text-align:center;min-width:120px" class="fragment">
      <div style="font-size:1.5em;font-weight:800;color:#fff">98%</div>
      <div style="font-size:0.66em;color:rgba(255,255,255,.8);margin-top:5px">Hài lòng</div>
    </div>
  </div>
</section>

══ VÍ DỤ NỘI DUNG + VISUAL PANEL ══
<section>
  <h2>💡 Chiến lược tăng trưởng</h2>
  <div style="display:flex;gap:24px;align-items:flex-start;margin-top:18px">
    <div style="flex:3;text-align:left">
      <ul style="font-size:0.78em;line-height:1.75;list-style:none;padding:0;margin:0">
        <li class="fragment" style="margin-bottom:10px">🚀 <strong>Tập trung MVP:</strong> Xây product-market fit trước khi scale</li>
        <li class="fragment" style="margin-bottom:10px">📣 <strong>Kênh phân phối:</strong> Organic 60% + Paid 40% theo giai đoạn</li>
        <li class="fragment" style="margin-bottom:10px">🤝 <strong>Đối tác chiến lược:</strong> Leverage network sẵn có để giảm CAC</li>
      </ul>
    </div>
    <div style="flex:2;display:flex;flex-direction:column;gap:10px">
      <div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:16px;border-radius:14px;text-align:center;color:#fff">
        <div style="font-size:1.2em;margin-bottom:4px">🎯</div>
        <div style="font-size:0.7em;font-weight:600">Mục tiêu Q4</div>
        <div style="font-size:0.65em;opacity:.82;margin-top:3px">$2M ARR</div>
      </div>
      <div style="background:rgba(99,102,241,.06);padding:16px;border-radius:14px;text-align:center;border:1px solid rgba(99,102,241,.18)">
        <div style="font-size:0.72em;color:#6366f1;font-weight:600">⏱ Timeline</div>
        <div style="font-size:0.66em;color:#64748b;margin-top:3px">6 tháng triển khai</div>
      </div>
    </div>
  </div>
</section>

══ VÍ DỤ CASE STUDY ══
<section>
  <h2>🏆 Case Study: Công ty ABC</h2>
  <div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px">
    <div style="flex:1">
      <div style="background:#f8fafc;border-left:4px solid #6366f1;padding:14px 20px;border-radius:0 14px 14px 0;margin-bottom:14px">
        <div style="font-size:0.8em;font-style:italic;color:#334155;line-height:1.6">"Sau 6 tháng, doanh thu tăng 200%, chi phí giảm 40% nhờ tự động hóa."</div>
        <div style="font-size:0.65em;color:#6366f1;font-weight:600;margin-top:7px">— Giám đốc vận hành, ABC Corp</div>
      </div>
      <div style="display:flex;gap:10px">
        <div style="flex:1;background:#f0fdf4;padding:12px;border-radius:12px;text-align:center;border:1px solid #bbf7d0">
          <div style="font-size:1.2em;font-weight:800;color:#16a34a">+200%</div>
          <div style="font-size:0.62em;color:#15803d;margin-top:3px">Doanh thu</div>
        </div>
        <div style="flex:1;background:#fefce8;padding:12px;border-radius:12px;text-align:center;border:1px solid #fde68a">
          <div style="font-size:1.2em;font-weight:800;color:#d97706">−40%</div>
          <div style="font-size:0.62em;color:#b45309;margin-top:3px">Chi phí</div>
        </div>
      </div>
    </div>
    <div style="flex:1;display:flex;flex-direction:column;gap:10px">
      <div style="font-size:0.72em;font-weight:600;color:#64748b;margin-bottom:4px;text-align:left">Tiến độ triển khai</div>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:0.68em;margin-bottom:4px"><span>Tự động hóa quy trình</span><span style="color:#6366f1;font-weight:600">90%</span></div>
        <div style="height:6px;background:#e2e8f0;border-radius:99px"><div style="height:6px;width:90%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:99px"></div></div>
      </div>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:0.68em;margin-bottom:4px"><span>Giảm chi phí nhân sự</span><span style="color:#f59e0b;font-weight:600">75%</span></div>
        <div style="height:6px;background:#e2e8f0;border-radius:99px"><div style="height:6px;width:75%;background:linear-gradient(90deg,#f59e0b,#f97316);border-radius:99px"></div></div>
      </div>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:0.68em;margin-bottom:4px"><span>Tăng trưởng khách hàng</span><span style="color:#06b6d4;font-weight:600">60%</span></div>
        <div style="height:6px;background:#e2e8f0;border-radius:99px"><div style="height:6px;width:60%;background:linear-gradient(90deg,#06b6d4,#0891b2);border-radius:99px"></div></div>
      </div>
    </div>
  </div>
</section>

Chỉ trả JSON, không giải thích.
SYSPROMPT;

        if ( $ai_context ) {
            $sys .= "\n\n" . $ai_context;
        }

        $prompt = "{$theme_hint}{$num_hint}Yêu cầu: {$topic}\n\n"
            . "Trả về ĐÚNG JSON:\n"
            . "{\n"
            . "  \"title\": \"Tiêu đề bài trình bày (ngắn gọn, dưới 60 ký tự)\",\n"
            . "  \"theme\": \"white|black|moon|night|serif|simple|solarized|blood|beige|league\",\n"
            . "  \"slides_html\": \"<section>...</section><section>...</section>...\",\n"
            . "  \"slide_count\": 8,\n"
            . "  \"description\": \"Mô tả ngắn về bài trình bày (1-2 câu)\"\n"
            . "}";

        $model_id = apply_filters( 'bizcity_tool_slide_model', 'anthropic/claude-sonnet-4-5' );

        $ai_result = bizcity_openrouter_chat( [
            [ 'role' => 'system', 'content' => $sys ],
            [ 'role' => 'user',   'content' => $prompt ],
        ], [
            'model'       => is_string( $model_id ) ? trim( $model_id ) : 'anthropic/claude-sonnet-4-5',
            'purpose'     => 'slide',
            'temperature' => 0.75,
            'max_tokens'  => 16000,
            'timeout'     => 180,
        ] );

        $parsed = self::parse_json_response( $ai_result['message'] ?? '' );

        if ( empty( $parsed['slides_html'] ) ) {
            return new WP_Error( 'ai_failed', 'AI không tạo được nội dung slide. Thử mô tả rõ hơn nhé.' );
        }

        return $parsed;
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Generate Slides from prompt (View page SPA)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_generate() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $prompt     = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $theme      = sanitize_text_field( $_POST['theme'] ?? 'auto' );
        $num_slides = intval( $_POST['num_slides'] ?? 0 );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Cần nhập mô tả / kịch bản bài trình bày.' ] );
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            wp_send_json_error( [ 'message' => 'AI chưa sẵn sàng (OpenRouter).' ] );
        }

        $result = self::ai_generate_slides( $prompt, $theme, $num_slides );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Save slide (create or update)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_save() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $title       = sanitize_text_field( $_POST['title'] ?? '' );
        $slides_html = wp_unslash( $_POST['slides_html'] ?? '' );
        $theme       = sanitize_text_field( $_POST['theme'] ?? 'white' );
        $prompt      = sanitize_textarea_field( $_POST['prompt'] ?? '' );
        $desc        = sanitize_textarea_field( $_POST['description'] ?? '' );
        $post_id     = intval( $_POST['post_id'] ?? 0 );

        if ( empty( $slides_html ) ) {
            wp_send_json_error( [ 'message' => 'Nội dung slide trống.' ] );
        }

        if ( $post_id > 0 ) {
            $existing = get_post( $post_id );
            if ( ! $existing || $existing->post_type !== 'bz_slide'
                 || ( (int) $existing->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
                wp_send_json_error( [ 'message' => 'Không tìm thấy hoặc không có quyền.' ] );
            }
            wp_update_post( [
                'ID'           => $post_id,
                'post_title'   => $title ?: $existing->post_title,
                'post_content' => $desc  ?: $existing->post_content,
            ] );
        } else {
            $post_id = wp_insert_post( [
                'post_type'    => 'bz_slide',
                'post_title'   => $title ?: ( 'Slide ' . wp_date( 'd/m/Y H:i' ) ),
                'post_content' => $desc ?: $prompt,
                'post_status'  => 'publish',
                'post_author'  => get_current_user_id(),
            ] );

            if ( ! $post_id || is_wp_error( $post_id ) ) {
                wp_send_json_error( [ 'message' => 'Lỗi lưu vào database.' ] );
            }
        }

        update_post_meta( $post_id, '_bz_slides_html', $slides_html );
        update_post_meta( $post_id, '_bz_slide_theme', $theme );

        // Count sections
        $count = preg_match_all( '/<section[\s>]/i', $slides_html );
        update_post_meta( $post_id, '_bz_slide_count', $count ?: 0 );

        if ( $prompt ) {
            update_post_meta( $post_id, '_bz_prompt', $prompt );
        }

        wp_send_json_success( [
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: List slides (paginated)
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_list() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per_page = 20;

        $q = new WP_Query( [
            'post_type'      => 'bz_slide',
            'post_status'    => 'publish',
            'author'         => get_current_user_id(),
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $q->posts as $p ) {
            $items[] = [
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'description' => wp_trim_words( $p->post_content, 20 ),
                'theme'       => get_post_meta( $p->ID, '_bz_slide_theme', true ) ?: 'white',
                'slide_count' => intval( get_post_meta( $p->ID, '_bz_slide_count', true ) ),
                'date'        => get_the_date( 'd/m/Y H:i', $p ),
                'prompt'      => get_post_meta( $p->ID, '_bz_prompt', true ),
            ];
        }

        wp_send_json_success( [
            'items' => $items,
            'total' => $q->found_posts,
            'pages' => $q->max_num_pages,
            'page'  => $page,
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Get single slide
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_get() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_slide' ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy bài trình bày.' ] );
        }

        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền truy cập.' ] );
        }

        wp_send_json_success( [
            'id'          => $post->ID,
            'title'       => $post->post_title,
            'description' => $post->post_content,
            'slides_html' => get_post_meta( $post->ID, '_bz_slides_html', true ),
            'theme'       => get_post_meta( $post->ID, '_bz_slide_theme', true ) ?: 'white',
            'slide_count' => intval( get_post_meta( $post->ID, '_bz_slide_count', true ) ),
            'prompt'      => get_post_meta( $post->ID, '_bz_prompt', true ),
            'date'        => get_the_date( 'd/m/Y H:i', $post ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Delete slide
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_delete() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );

        if ( ! $post || $post->post_type !== 'bz_slide'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền xóa.' ] );
        }

        wp_delete_post( $post_id, true );
        wp_send_json_success( [ 'deleted' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Update slide HTML / title / theme
     * ══════════════════════════════════════════════════════════════ */
    public static function ajax_update() {
        check_ajax_referer( 'bztool_slide', 'nonce' );

        $post_id     = intval( $_POST['post_id'] ?? 0 );
        $slides_html = wp_unslash( $_POST['slides_html'] ?? '' );
        $title       = sanitize_text_field( $_POST['title'] ?? '' );
        $theme       = sanitize_text_field( $_POST['theme'] ?? '' );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bz_slide'
             || ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền chỉnh sửa.' ] );
        }

        if ( $title ) {
            wp_update_post( [ 'ID' => $post_id, 'post_title' => $title ] );
        }
        if ( $slides_html ) {
            update_post_meta( $post_id, '_bz_slides_html', $slides_html );
            $count = preg_match_all( '/<section[\s>]/i', $slides_html );
            update_post_meta( $post_id, '_bz_slide_count', $count ?: 0 );
        }
        if ( $theme ) {
            update_post_meta( $post_id, '_bz_slide_theme', $theme );
        }

        wp_send_json_success( [ 'post_id' => $post_id ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  Helpers
     * ══════════════════════════════════════════════════════════════ */
    private static function extract_text( array $slots ): string {
        foreach ( [ 'topic', 'message', 'content' ] as $key ) {
            $val = $slots[ $key ] ?? '';
            if ( is_string( $val ) && $val !== '' ) return trim( $val );
            if ( is_array( $val ) ) {
                $text = $val['text'] ?? $val['caption'] ?? '';
                if ( $text ) return trim( $text );
            }
        }
        return '';
    }

    private static function parse_json_response( string $raw ): array {
        if ( empty( $raw ) ) return [];

        $clean = trim( $raw );
        $clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
        $clean = preg_replace( '/```\s*$/', '', $clean );

        $parsed = json_decode( $clean, true );
        if ( is_array( $parsed ) ) return $parsed;

        if ( preg_match( '/\{[\s\S]*\}/', $clean, $m ) ) {
            $parsed = json_decode( $m[0], true );
            if ( is_array( $parsed ) ) return $parsed;
        }

        return [];
    }
}

BizCity_Tool_Slide::init();
