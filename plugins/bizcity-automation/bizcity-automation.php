<?php
/**
 * Plugin Name:       Bizcity - Agent planner Automation
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-automation
 *
 * Description:       Tạo & quản lý kịch bản workflow tự động hóa bằng AI — viết bài, đăng Facebook, gửi Zalo, schedule… Chat mô tả quy trình, AI tạo workflow đa bước tự động.
 * Short Description: Thiết kế workflow automation từ chat — AI tạo pipeline đa bước tự động.
 * Quick View:        ⚡ Chat → Mô tả quy trình → AI → Workflow tự động
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Icon Path:         /assets/multi-agent-system.png
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Plan:              pro
 * Template Page:     automation
 * Admin Slug:        bizcity-workspace
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/maxresdefault1(1).jpg
 * Category:          automation, workflow, productivity
 * Tags:              workflow, automation, AI, tự động hóa, pipeline, schedule, multi-step, kịch bản
 * Author:            Chu Hoàng Anh
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-automation
 *
 * === Giới thiệu ===
 * BizCity Automation cho phép tạo & quản lý kịch bản workflow tự động hóa.
 * Mô tả quy trình bằng ngôn ngữ tự nhiên, AI tạo pipeline đa bước: viết bài,
 * tạo ảnh, đăng Facebook, gửi Zalo, lên lịch…
 *
 * === Tính năng chính ===
 * • Chat mô tả quy trình → AI tạo workflow tự động
 * • Pipeline đa bước: kết hợp nhiều tool (content, image, facebook, video…)
 * • Lên lịch chạy workflow theo giờ / ngày / tuần
 * • Theo dõi trạng thái từng bước trong workflow
 * • Template kịch bản mẫu (blog + facebook, sản phẩm + landing page…)
 * • Giao diện Automation Studio tại /automation/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • Các tool plugin tương ứng với các bước trong workflow
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Vào BizCity > Workspace > Workflow để bắt đầu.
 * Truy cập /automation/ để mở Automation Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Bizcity Automation</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ── Framework bootstrap ── */
require_once __DIR__ . '/bootstrap.php';

/* ══════════════════════════════════════════════════════════════
 *  PILLAR 2 — Intent Provider Registration
 *  Lets users generate workflow scenarios via chat.
 *  Class-based: heavy LLM logic cannot fit array config.
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {
    require_once __DIR__ . '/includes/class-intent-provider.php';
    $registry->register( new BizCity_Automation_Intent_Provider() );
} );