<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skills — Public Agent Page (v2: Tree View)
 *
 * Route: /skills/
 * Two-panel layout: Tree sidebar (left) + Content panel (right)
 * Admin users get full CRUD; regular users get read-only browse.
 *
 * @package  BizCity_Skills
 * @since    2026-04-02
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$user_id      = get_current_user_id();
$is_admin     = current_user_can( 'manage_options' );

$rest_url   = esc_url_raw( rest_url( 'bizcity/skill/v1' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$td = 'bizcity-twin-ai';

/* ── Workflows (feature cards) ── */
$workflows = [
    [ 'icon' => '📝', 'label' => __( 'Viết bài blog', $td ),       'desc' => __( 'Tạo bài blog chuẩn SEO với outline + nội dung', $td ),     'msg' => __( 'Viết bài blog', $td ),            'tags' => ['Content','Blog'] ],
    [ 'icon' => '💬', 'label' => __( 'Tóm tắt cuộc họp', $td ),    'desc' => __( 'Tóm tắt nhanh nội dung cuộc họp quan trọng', $td ),        'msg' => __( 'Tóm tắt cuộc họp', $td ),         'tags' => ['Note','Meeting'] ],
    [ 'icon' => '🔧', 'label' => __( 'Phân tích dữ liệu', $td ),   'desc' => __( 'Phân tích số liệu, báo cáo, thống kê nhanh', $td ),        'msg' => __( 'Phân tích dữ liệu', $td ),        'tags' => ['Tools','Data'] ],
    [ 'icon' => '📧', 'label' => __( 'Soạn email chuyên nghiệp', $td ),'desc' => __( 'Viết email chuẩn mực cho công việc', $td ),              'msg' => __( 'Soạn email chuyên nghiệp', $td ), 'tags' => ['Content','Email'] ],
    [ 'icon' => '📋', 'label' => __( 'Lên kế hoạch dự án', $td ),   'desc' => __( 'Tạo project plan với timeline và milestones', $td ),        'msg' => __( 'Lên kế hoạch dự án', $td ),       'tags' => ['Tools','Planning'] ],
    [ 'icon' => '📖', 'label' => __( 'Viết nhật ký hôm nay', $td ), 'desc' => __( 'Ghi lại ngày hôm nay: công việc, suy nghĩ, cảm xúc', $td ),'msg' => __( 'Viết nhật ký hôm nay', $td ),     'tags' => [__('Nhật ký',$td),'Daily'] ],
];

/* ── Templates (for New Skill modal) ── */
$templates = [
    [ 'id' => 'automation', 'icon' => '🔄', 'name' => 'Automation / Workflow',  'desc' => __( 'Quy trình tự động hoá nhiều bước, có trigger & tool call', $td ) ],
    [ 'id' => 'tool',       'icon' => '🛠️', 'name' => 'Tool Integration',       'desc' => __( 'Skill gọi 1 tool cụ thể: tạo sản phẩm, gửi email, tra cứu...', $td ) ],
    [ 'id' => 'content',    'icon' => '✍️',  'name' => __( 'Viết nội dung', $td ),          'desc' => __( 'Viết bài bán hàng, blog, email marketing, social post...', $td ) ],
    [ 'id' => 'analysis',   'icon' => '📊', 'name' => __( 'Phân tích / Báo cáo', $td ),   'desc' => __( 'Phân tích dữ liệu, tạo báo cáo, đánh giá hiệu suất...', $td ) ],
    [ 'id' => 'blank',      'icon' => '📝', 'name' => __( 'Skill trống', $td ),            'desc' => __( 'Bắt đầu từ đầu với cấu trúc cơ bản', $td ) ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__( 'Skills – Kỹ năng AI', 'bizcity-twin-ai' ); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sk-primary:#10b981;--sk-primary-light:#ecfdf5;--sk-primary-dark:#059669;
  --sk-bg:#f8fafc;--sk-card:#fff;--sk-border:#e5e7eb;
  --sk-text:#1f2937;--sk-text2:#6b7280;--sk-text3:#9ca3af;
  --sk-sidebar:240px;
  --sk-accent:#4d6bfe;
}
body{
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  background:var(--sk-bg);color:var(--sk-text);
  -webkit-font-smoothing:antialiased;overflow:hidden;height:100vh;
}

/* ══ Layout ══ */
.sk-layout{display:flex;height:100vh;width:100%}
.sk-sidebar{
  width:var(--sk-sidebar);min-width:var(--sk-sidebar);
  background:var(--sk-card);border-right:1px solid var(--sk-border);
  display:flex;flex-direction:column;overflow:hidden;
  flex-shrink:0;
}
.sk-main{flex:1;overflow-y:auto;min-width:0}

/* ══ Sidebar header ══ */
.sk-sb-hd{
  padding:12px 12px 8px;border-bottom:1px solid var(--sk-border);
  display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;
}
.sk-sb-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:6px}
.sk-sb-btns{display:flex;gap:4px}
.sk-sb-btn{
  width:28px;height:28px;border-radius:6px;border:1px solid var(--sk-border);
  background:var(--sk-card);cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  font-size:14px;transition:all .12s;color:var(--sk-text2);
}
.sk-sb-btn:hover{background:var(--sk-primary-light);color:var(--sk-primary);border-color:var(--sk-primary)}

/* ══ New Skill / Mẫu buttons ══ */
.sk-sb-actions{
  padding:8px 12px;display:flex;gap:6px;
  border-bottom:1px solid var(--sk-border);flex-shrink:0;
}
.sk-btn{
  flex:1;padding:7px 10px;border-radius:8px;font-size:11px;font-weight:600;
  border:1px solid var(--sk-border);cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:4px;
  transition:all .12s;background:var(--sk-card);color:var(--sk-text);
}
.sk-btn:hover{border-color:var(--sk-primary);color:var(--sk-primary)}
.sk-btn--primary{background:var(--sk-accent);color:#fff;border-color:var(--sk-accent)}
.sk-btn--primary:hover{background:#3b55d9}

/* ══ Tree ══ */
.sk-tree-wrap{flex:1;overflow-y:auto;padding:4px 0}
.sk-tree{list-style:none;margin:0;padding:0}
.sk-tree ul{list-style:none;padding:0;margin:0}
.sk-tree-item{
  display:flex;align-items:center;gap:6px;
  padding:5px 8px 5px 0;cursor:pointer;
  font-size:13px;color:var(--sk-text);
  border-radius:4px;transition:background .1s;
  user-select:none;white-space:nowrap;overflow:hidden;
}
.sk-tree-item:hover{background:var(--sk-primary-light)}
.sk-tree-item.selected{background:#dbeafe;color:var(--sk-accent);font-weight:600}
.sk-tree-chevron{
  width:16px;height:16px;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;color:var(--sk-text3);transition:transform .15s;
}
.sk-tree-chevron.open{transform:rotate(90deg)}
.sk-tree-chevron.spacer{visibility:hidden}
.sk-tree-icon{width:16px;height:16px;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.sk-tree-name{flex:1;overflow:hidden;text-overflow:ellipsis}

/* ── Inline inputs ── */
.sk-tree-input{
  margin:2px 8px 2px 12px;padding:4px 8px;
  border:1px solid var(--sk-primary);border-radius:4px;
  font-size:12px;outline:none;width:calc(100% - 24px);
}

/* ══ Editor ══ */
.sk-editor{display:flex;flex-direction:column;height:100%;overflow:hidden}
.sk-editor-hd{
  padding:12px 20px;border-bottom:1px solid var(--sk-border);
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  flex-shrink:0;background:var(--sk-card);
}
.sk-editor-path{font-size:13px;color:var(--sk-text2);display:flex;align-items:center;gap:6px}
.sk-editor-path b{color:var(--sk-text);font-weight:600}
.sk-editor-btns{display:flex;gap:6px}
.sk-editor-body{flex:1;padding:16px 20px;overflow-y:auto}
.sk-editor-textarea{
  width:100%;min-height:400px;height:calc(100vh - 180px);
  padding:14px;border:1px solid var(--sk-border);border-radius:8px;
  font-family:"JetBrains Mono","Fira Code",Monaco,monospace;
  font-size:13px;line-height:1.6;resize:vertical;
  outline:none;background:var(--sk-card);color:var(--sk-text);
}
.sk-editor-textarea:focus{border-color:var(--sk-primary);box-shadow:0 0 0 2px rgba(16,185,129,.15)}
.sk-save-btn{
  padding:8px 20px;background:var(--sk-primary);color:#fff;
  border:none;border-radius:8px;font-weight:600;font-size:13px;
  cursor:pointer;transition:all .15s;
}
.sk-save-btn:hover{background:var(--sk-primary-dark)}
.sk-save-btn:disabled{opacity:.5;cursor:not-allowed}
.sk-del-btn{
  padding:8px 14px;background:#fef2f2;color:#dc2626;
  border:1px solid #fecaca;border-radius:8px;font-size:12px;font-weight:600;
  cursor:pointer;transition:all .12s;
}
.sk-del-btn:hover{background:#fee2e2}
.sk-save-msg{font-size:12px;padding:6px 12px;border-radius:6px;margin-top:8px;display:none}
.sk-save-msg.ok{display:block;background:#dcfce7;color:#166534}
.sk-save-msg.err{display:block;background:#fef2f2;color:#b91c1c}
.sk-save-msg.warn{display:block;background:#fefce8;color:#a16207}

/* ══ Template Gallery (home) ══ */
.sk-home{padding:20px}
.sk-home-hero{
  background:linear-gradient(135deg,#10b981,#059669,#047857);
  border-radius:16px;padding:20px;color:#fff;
  box-shadow:0 6px 24px rgba(16,185,129,.2);margin-bottom:20px;
  position:relative;overflow:hidden;
}
.sk-home-hero::before{content:'';position:absolute;top:-40%;right:-20%;width:180px;height:180px;background:rgba(255,255,255,.06);border-radius:50%}
.sk-home-hero-row{display:flex;align-items:center;gap:14px}
.sk-home-hero-icon{font-size:36px}
.sk-home-hero h2{font-size:18px;margin:0 0 4px}
.sk-home-hero p{font-size:12px;opacity:.85;margin:0}

.sk-tpl-sec{font-size:15px;font-weight:700;margin:20px 0 10px;display:flex;align-items:center;gap:8px}
.sk-tpl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.sk-tpl-card{
  background:var(--sk-card);border:1px solid var(--sk-border);border-radius:12px;
  padding:16px;cursor:pointer;transition:all .15s;text-align:center;
}
.sk-tpl-card:hover{border-color:var(--sk-primary);box-shadow:0 4px 12px rgba(16,185,129,.1);transform:translateY(-2px)}
.sk-tpl-card-icon{font-size:28px;margin-bottom:6px}
.sk-tpl-card-name{font-size:13px;font-weight:700}
.sk-tpl-card-desc{font-size:10px;color:var(--sk-text3);margin-top:3px;line-height:1.3}

/* ══ Workflow cards ══ */
.sk-wf-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}
.sk-wf-card{
  display:flex;align-items:flex-start;gap:12px;
  background:var(--sk-card);border-radius:12px;padding:12px 14px;
  box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid var(--sk-border);
  cursor:pointer;transition:all .15s;
}
.sk-wf-card:hover{border-color:#6ee7b7;transform:translateY(-1px)}
.sk-wf-card-icon{
  width:36px;height:36px;border-radius:8px;
  background:var(--sk-primary-light);
  display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;
}
.sk-wf-card-body{flex:1;min-width:0}
.sk-wf-card-label{font-size:12px;font-weight:600}
.sk-wf-card-desc{font-size:10px;color:var(--sk-text2);line-height:1.3;margin-top:1px}
.sk-wf-card-tags{display:flex;gap:3px;margin-top:3px}
.sk-wf-card-tag{font-size:9px;font-weight:600;padding:1px 6px;border-radius:4px;background:var(--sk-primary-light);color:var(--sk-primary-dark)}

/* ══ Modal ══ */
.sk-modal-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;
  display:flex;align-items:center;justify-content:center;
}
.sk-modal{
  background:var(--sk-card);border-radius:16px;width:90%;max-width:520px;
  max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);
}
.sk-modal-hd{
  padding:16px 20px;border-bottom:1px solid var(--sk-border);
  display:flex;align-items:center;justify-content:space-between;
}
.sk-modal-hd h3{font-size:16px;margin:0;display:flex;align-items:center;gap:8px}
.sk-modal-close{
  width:30px;height:30px;border-radius:8px;border:none;
  background:var(--sk-bg);cursor:pointer;font-size:16px;
  display:flex;align-items:center;justify-content:center;
}
.sk-modal-body{padding:16px 20px}
.sk-modal-tpl-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.sk-modal-tpl{
  border:2px solid var(--sk-border);border-radius:10px;
  padding:12px;cursor:pointer;text-align:center;transition:all .12s;
}
.sk-modal-tpl:hover{border-color:var(--sk-primary-dark)}
.sk-modal-tpl.selected{border-color:var(--sk-primary);background:var(--sk-primary-light)}
.sk-modal-tpl-icon{font-size:24px}
.sk-modal-tpl-name{font-size:12px;font-weight:700;margin-top:4px}
.sk-modal-field{margin-bottom:14px}
.sk-modal-field label{display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:var(--sk-text2)}
.sk-modal-field input,.sk-modal-field select{
  width:100%;padding:8px 12px;border:1px solid var(--sk-border);border-radius:8px;
  font-size:13px;outline:none;
}
.sk-modal-field input:focus{border-color:var(--sk-primary)}
.sk-modal-submit{
  width:100%;padding:10px;background:var(--sk-accent);color:#fff;
  border:none;border-radius:10px;font-weight:600;font-size:14px;
  cursor:pointer;margin-top:4px;
}
.sk-modal-submit:hover{background:#3b55d9}
.sk-modal-submit:disabled{opacity:.5;cursor:not-allowed}

/* ══ Toast ══ */
.sk-toast{
  position:fixed;top:16px;right:16px;z-index:300;
  padding:10px 20px;border-radius:10px;font-size:13px;font-weight:500;
  background:#dcfce7;color:#166534;border:1px solid #bbf7d0;
  box-shadow:0 4px 12px rgba(0,0,0,.1);animation:sk-toast-in .3s;
}
.sk-toast.err{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
@keyframes sk-toast-in{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}

/* ══ Context menu ══ */
.sk-ctx{
  position:fixed;z-index:250;
  background:var(--sk-card);border:1px solid var(--sk-border);border-radius:8px;
  box-shadow:0 4px 20px rgba(0,0,0,.12);padding:4px;min-width:140px;
}
.sk-ctx-item{
  display:flex;align-items:center;gap:6px;padding:6px 10px;
  font-size:12px;cursor:pointer;border-radius:4px;
}
.sk-ctx-item:hover{background:var(--sk-primary-light)}
.sk-ctx-item.danger{color:#dc2626}
.sk-ctx-item.danger:hover{background:#fef2f2}

/* ══ Login ══ */
.sk-login{display:flex;align-items:center;justify-content:center;height:100vh;color:var(--sk-text2);font-size:14px}
.sk-login a{color:var(--sk-primary);text-decoration:underline}

/* ══ Readonly badge ══ */
.sk-badge-ro{
  font-size:10px;font-weight:600;padding:2px 8px;border-radius:4px;
  background:#fef3c7;color:#92400e;border:1px solid #fde68a;
}

/* ══ Mobile ══ */
.sk-mobile-toggle{
  display:none;position:fixed;top:10px;left:10px;z-index:150;
  width:36px;height:36px;border-radius:8px;background:var(--sk-card);
  border:1px solid var(--sk-border);cursor:pointer;
  align-items:center;justify-content:center;font-size:18px;
  box-shadow:0 2px 8px rgba(0,0,0,.1);
}
@media(max-width:768px){
  .sk-sidebar{position:fixed;left:0;top:0;bottom:0;z-index:140;transform:translateX(-100%);transition:transform .25s}
  .sk-sidebar.open{transform:translateX(0)}
  .sk-mobile-toggle{display:flex}
  .sk-mobile-overlay{position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:130;display:none}
  .sk-mobile-overlay.open{display:block}
  .sk-main{padding-top:50px}
  .sk-tpl-grid{grid-template-columns:1fr 1fr}
  .sk-wf-cards{grid-template-columns:1fr}
  .sk-modal-tpl-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
  .sk-tpl-grid{grid-template-columns:1fr}
  .sk-modal-tpl-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>

<?php if ( ! $is_logged_in ) : ?>
<div class="sk-login">
    Vui lòng <a href="<?php echo esc_url( wp_login_url( home_url( '/skills/' ) ) ); ?>">đăng nhập</a> để sử dụng Skills.
</div>
<?php else : ?>

<!-- Mobile toggle -->
<button class="sk-mobile-toggle" id="sk-mob-toggle">📁</button>
<div class="sk-mobile-overlay" id="sk-mob-overlay"></div>

<div class="sk-layout">
  <!-- ════ Sidebar: Tree View ════ -->
  <aside class="sk-sidebar" id="sk-sidebar">
    <div class="sk-sb-hd">
      <span class="sk-sb-title">⚡ Skill Library</span>
      <div class="sk-sb-btns">
        <?php if ( $is_admin ) : ?>
        <button class="sk-sb-btn" id="sk-btn-addfolder" title="Tạo folder mới">+</button>
        <?php endif; ?>
        <button class="sk-sb-btn" id="sk-btn-refresh" title="Làm mới">↻</button>
      </div>
    </div>
    <?php if ( $is_admin ) : ?>
    <div class="sk-sb-actions">
      <button class="sk-btn" id="sk-btn-templates">📋 Mẫu</button>
      <button class="sk-btn sk-btn--primary" id="sk-btn-newskill">+ New Skill</button>
    </div>
    <?php endif; ?>
    <div class="sk-tree-wrap" id="sk-tree-wrap">
      <ul class="sk-tree" id="sk-tree"></ul>
    </div>
    <!-- inline folder input (hidden by default) -->
    <?php if ( $is_admin ) : ?>
    <input class="sk-tree-input" id="sk-folder-input" style="display:none" placeholder="folder-name">
    <?php endif; ?>
  </aside>

  <!-- ════ Main content ════ -->
  <main class="sk-main" id="sk-main">
    <!-- Home (default view — templates + features) -->
    <div class="sk-home" id="sk-home">
      <div class="sk-home-hero">
        <div class="sk-home-hero-row">
          <span class="sk-home-hero-icon">⚡</span>
          <div>
            <h2>Skills — Kỹ năng AI</h2>
            <p>Thư viện kỹ năng plug & play — chọn file bên trái để xem / chỉnh sửa</p>
          </div>
        </div>
      </div>

      <!-- Template gallery (admin only) -->
      <?php if ( $is_admin ) : ?>
      <div class="sk-tpl-sec">📋 Mẫu kịch bản Skill <span style="font-size:11px;font-weight:400;color:var(--sk-text3)">Nhấn để tạo skill mới từ mẫu</span></div>
      <div class="sk-tpl-grid">
        <?php foreach ( $templates as $tpl ) : ?>
        <div class="sk-tpl-card" data-tpl="<?php echo esc_attr( $tpl['id'] ); ?>">
          <div class="sk-tpl-card-icon"><?php echo $tpl['icon']; ?></div>
          <div class="sk-tpl-card-name"><?php echo esc_html( $tpl['name'] ); ?></div>
          <div class="sk-tpl-card-desc"><?php echo esc_html( $tpl['desc'] ); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Workflows -->
      <div class="sk-tpl-sec">⚡ Tính năng <span style="font-size:11px;font-weight:400;color:var(--sk-text3)">Bấm để gửi lệnh vào chat</span></div>
      <div class="sk-wf-cards">
        <?php foreach ( $workflows as $w ) : ?>
        <div class="sk-wf-card" data-msg="<?php echo esc_attr( $w['msg'] ); ?>">
          <div class="sk-wf-card-icon"><?php echo $w['icon']; ?></div>
          <div class="sk-wf-card-body">
            <div class="sk-wf-card-label"><?php echo esc_html( $w['label'] ); ?></div>
            <div class="sk-wf-card-desc"><?php echo esc_html( $w['desc'] ); ?></div>
            <div class="sk-wf-card-tags">
              <?php foreach ( $w['tags'] as $tag ) : ?>
                <span class="sk-wf-card-tag"><?php echo esc_html( $tag ); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>

    <!-- Editor (shown when file selected) -->
    <div class="sk-editor" id="sk-editor" style="display:none">
      <div class="sk-editor-hd">
        <div class="sk-editor-path">
          <span style="cursor:pointer" id="sk-editor-home" title="Về trang chính">⚡</span>
          <span>›</span>
          <b id="sk-editor-filename"></b>
          <?php if ( ! $is_admin ) : ?>
            <span class="sk-badge-ro">Chỉ đọc</span>
          <?php endif; ?>
        </div>
        <div class="sk-editor-btns">
          <?php if ( $is_admin ) : ?>
          <button class="sk-del-btn" id="sk-editor-delete">🗑 Xóa</button>
          <button class="sk-save-btn" id="sk-editor-save">💾 Lưu</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="sk-editor-body">
        <textarea class="sk-editor-textarea" id="sk-editor-content" <?php echo $is_admin ? '' : 'readonly'; ?>></textarea>
        <div class="sk-save-msg" id="sk-editor-msg"></div>
      </div>
    </div>
  </main>
</div>

<!-- ════ Context Menu ════ -->
<div class="sk-ctx" id="sk-ctx" style="display:none"></div>

<!-- ════ New Skill Modal ════ -->
<?php if ( $is_admin ) : ?>
<div class="sk-modal-overlay" id="sk-modal" style="display:none">
  <div class="sk-modal">
    <div class="sk-modal-hd">
      <h3>✨ Tạo Skill mới</h3>
      <button class="sk-modal-close" id="sk-modal-close">✕</button>
    </div>
    <div class="sk-modal-body">
      <div class="sk-modal-field">
        <label>Chọn mẫu</label>
        <div class="sk-modal-tpl-grid" id="sk-modal-tpls">
          <?php foreach ( $templates as $tpl ) : ?>
          <div class="sk-modal-tpl" data-tpl="<?php echo esc_attr( $tpl['id'] ); ?>">
            <div class="sk-modal-tpl-icon"><?php echo $tpl['icon']; ?></div>
            <div class="sk-modal-tpl-name"><?php echo esc_html( $tpl['name'] ); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="sk-modal-field">
        <label>Thư mục</label>
        <select id="sk-modal-folder">
          <option value="/">(root)</option>
        </select>
      </div>
      <div class="sk-modal-field">
        <label>Tên file (không cần .md)</label>
        <input id="sk-modal-name" type="text" placeholder="my-skill">
      </div>
      <div class="sk-modal-field">
        <label>Tiêu đề hiển thị</label>
        <input id="sk-modal-label" type="text" placeholder="My Skill Name">
      </div>
      <button class="sk-modal-submit" id="sk-modal-submit">🚀 Tạo Skill</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
'use strict';
var REST  = <?php echo wp_json_encode( $rest_url ); ?>;
var NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;
var IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
var isIframe = window.parent && window.parent !== window;

var treeData = [];
var selectedFileId = null;
var currentFilePath = null;

function h() { return { 'Content-Type':'application/json', 'X-WP-Nonce': NONCE }; }

function toast(msg, err) {
  var d = document.createElement('div');
  d.className = 'sk-toast' + (err ? ' err' : '');
  d.textContent = msg;
  document.body.appendChild(d);
  setTimeout(function(){ d.remove(); }, 2500);
}

function esc(s) {
  var d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

function sendMsg(msg) {
  if (!msg) return;
  if (isIframe) {
    window.parent.postMessage({
      type: 'bizcity_agent_command',
      source: 'bizcity-skill',
      plugin_slug: '',
      tool_name: '',
      text: msg,
      auto_send: false
    }, '*');
  } else {
    window.location.href = <?php echo wp_json_encode( home_url( '/' ) ); ?> + '?bizcity_chat_msg=' + encodeURIComponent(msg);
  }
}

/* ── Mobile sidebar toggle ── */
var sidebar = document.getElementById('sk-sidebar');
var mobToggle = document.getElementById('sk-mob-toggle');
var mobOverlay = document.getElementById('sk-mob-overlay');
if (mobToggle) mobToggle.onclick = function(){ sidebar.classList.toggle('open'); mobOverlay.classList.toggle('open'); };
if (mobOverlay) mobOverlay.onclick = function(){ sidebar.classList.remove('open'); mobOverlay.classList.remove('open'); };

/* ════════════════════════════════════════════════════════
 *  Tree — resolve path, build, render
 * ════════════════════════════════════════════════════════ */
function resolvePath(id) {
  var f = treeData.find(function(x){ return x.id === id; });
  if (!f) return '';
  var parts = [f.name], cur = f;
  while (cur && cur.parentId && cur.parentId !== '0') {
    var p = treeData.find(function(x){ return x.id === cur.parentId; });
    if (!p) break;
    parts.unshift(p.name);
    cur = p;
  }
  return '/' + parts.join('/');
}

function buildTree(flat) {
  var map = {}, roots = [];
  flat.forEach(function(f){ map[f.id] = { file: f, children: [] }; });
  flat.forEach(function(f){
    var n = map[f.id];
    if (!f.parentId || f.parentId === '0') { if (f.id !== '0') roots.push(n); }
    else { var p = map[f.parentId]; if (p) p.children.push(n); }
  });
  function sortN(arr){
    arr.sort(function(a,b){
      if (a.file.isDir && !b.file.isDir) return -1;
      if (!a.file.isDir && b.file.isDir) return 1;
      return a.file.name.localeCompare(b.file.name);
    });
    arr.forEach(function(n){ sortN(n.children); });
  }
  sortN(roots);
  return roots;
}

var expandedSet = {};

function renderTree() {
  var tree = buildTree(treeData);
  // auto-expand root folders on first load
  treeData.forEach(function(f){
    if (f.isDir && (!f.parentId || f.parentId === '0') && f.id !== '0' && expandedSet[f.id] === undefined)
      expandedSet[f.id] = true;
  });
  document.getElementById('sk-tree').innerHTML = renderNodes(tree, 0);
  attachTreeEvents();
  updateFolderSelect();
}

var SVG_CHEVRON = '<svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><path d="M4.7 10c-.2 0-.4-.1-.5-.2-.3-.3-.3-.8 0-1.1L6.9 6 4.2 3.3c-.3-.3-.3-.8 0-1.1.3-.3.8-.3 1.1 0l3.3 3.2c.3.3.3.8 0 1.1L5.3 9.7c-.2.2-.4.3-.6.3Z"/></svg>';
var SVG_FOLDER = '<svg width="16" height="16" viewBox="0 0 16 16" fill="#e8a838"><path d="M1.75 1A1.75 1.75 0 0 0 0 2.75v10.5C0 14.216.784 15 1.75 15h12.5A1.75 1.75 0 0 0 16 13.25v-8.5A1.75 1.75 0 0 0 14.25 3H7.5a.25.25 0 0 1-.2-.1l-.9-1.2C6.07 1.26 5.55 1 5 1H1.75Z"/></svg>';
var SVG_FILE = '<svg width="16" height="16" viewBox="0 0 16 16" fill="#8b949e"><path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/></svg>';

function renderNodes(nodes, level) {
  var html = '';
  nodes.forEach(function(n){
    var f = n.file, isOpen = !!expandedSet[f.id], isSel = f.id === selectedFileId;
    var pad = 8 + level * 16;
    html += '<li data-id="' + esc(f.id) + '" data-dir="' + (f.isDir?'1':'0') + '">';
    html += '<div class="sk-tree-item' + (isSel?' selected':'') + '" style="padding-left:'+pad+'px">';
    html += f.isDir
      ? '<span class="sk-tree-chevron' + (isOpen?' open':'') + '">' + SVG_CHEVRON + '</span>'
      : '<span class="sk-tree-chevron spacer"></span>';
    html += '<span class="sk-tree-icon">' + (f.isDir ? SVG_FOLDER : SVG_FILE) + '</span>';
    html += '<span class="sk-tree-name">' + esc(f.name) + '</span></div>';
    if (f.isDir && isOpen && n.children.length > 0)
      html += '<ul>' + renderNodes(n.children, level+1) + '</ul>';
    html += '</li>';
  });
  return html;
}

function attachTreeEvents() {
  document.querySelectorAll('#sk-tree .sk-tree-item').forEach(function(el){
    var li = el.closest('li'), id = li.getAttribute('data-id'), isDir = li.getAttribute('data-dir') === '1';
    el.addEventListener('click', function(e){
      e.stopPropagation();
      if (isDir) { expandedSet[id] = !expandedSet[id]; renderTree(); }
      else openFile(id);
      sidebar.classList.remove('open');
      if (mobOverlay) mobOverlay.classList.remove('open');
    });
    if (IS_ADMIN) el.addEventListener('contextmenu', function(e){ e.preventDefault(); showContextMenu(e, id, isDir); });
  });
}

/* ════════════════════════════════════════════════════════
 *  Context Menu (admin only)
 * ════════════════════════════════════════════════════════ */
var ctxEl = document.getElementById('sk-ctx');

function showContextMenu(e, id, isDir) {
  var path = resolvePath(id), html = '';
  if (isDir) {
    html += '<div class="sk-ctx-item" data-action="new-file" data-path="'+esc(path)+'">📄 Tạo file mới</div>';
    html += '<div class="sk-ctx-item danger" data-action="delete-folder" data-path="'+esc(path)+'">🗑 Xóa folder</div>';
  } else {
    html += '<div class="sk-ctx-item" data-action="open" data-id="'+esc(id)+'">📖 Mở</div>';
    html += '<div class="sk-ctx-item danger" data-action="delete-file" data-path="'+esc(path)+'">🗑 Xóa file</div>';
  }
  ctxEl.innerHTML = html;
  ctxEl.style.display = '';
  ctxEl.style.left = Math.min(e.clientX, window.innerWidth-160)+'px';
  ctxEl.style.top = Math.min(e.clientY, window.innerHeight-100)+'px';
  ctxEl.querySelectorAll('.sk-ctx-item').forEach(function(item){
    item.onclick = function(){
      ctxEl.style.display = 'none';
      var a = this.getAttribute('data-action');
      if (a==='open') openFile(this.getAttribute('data-id'));
      else if (a==='delete-file') deleteItem(this.getAttribute('data-path'), false);
      else if (a==='delete-folder') deleteItem(this.getAttribute('data-path'), true);
      else if (a==='new-file') quickNewFile(this.getAttribute('data-path'));
    };
  });
}

document.addEventListener('click', function(){ ctxEl.style.display = 'none'; });

function deleteItem(path, isFolder) {
  if (!confirm('Xóa ' + (isFolder?'folder':'file') + ': ' + path + '?')) return;
  fetch(REST + (isFolder?'/folder':'/file') + '?path=' + encodeURIComponent(path), { method:'DELETE', headers:h() })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.error) { toast(d.error, true); return; }
    toast('Đã xóa ' + path);
    if (currentFilePath === path) goHome();
    loadTree();
  })
  .catch(function(){ toast('Lỗi kết nối', true); });
}

function quickNewFile(folderPath) {
  var name = prompt('Tên file (không cần .md):', 'new-skill');
  if (!name) return;
  name = name.trim().replace(/\.md$/i, '');
  var path = folderPath.replace(/\/$/,'') + '/' + name + '.md';
  var raw = generateTemplate('blank', name, name);
  fetch(REST+'/file', { method:'POST', headers:h(), body:JSON.stringify({path:path,raw:raw}) })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if (d.error) { toast(d.error,true); return; }
    toast('Đã tạo ' + path);
    loadTree(function(){ openFileByPath(path); });
  })
  .catch(function(){ toast('Lỗi kết nối', true); });
}

/* ════════════════════════════════════════════════════════
 *  Load tree
 * ════════════════════════════════════════════════════════ */
function loadTree(cb) {
  fetch(REST+'/tree', { headers:h() })
  .then(function(r){ return r.json(); })
  .then(function(data){ treeData = data||[]; renderTree(); if(cb)cb(); })
  .catch(function(){ toast('Không thể tải tree', true); });
}
loadTree();
document.getElementById('sk-btn-refresh').onclick = function(){ loadTree(); };

/* ════════════════════════════════════════════════════════
 *  Open / Read file
 * ════════════════════════════════════════════════════════ */
function openFile(id) {
  var path = resolvePath(id);
  if (!path) return;
  selectedFileId = id; currentFilePath = path;
  renderTree();
  fetch(REST+'/file?path='+encodeURIComponent(path), { headers:h() })
  .then(function(r){ return r.json(); })
  .then(function(data){
    if (data.error) { toast(data.error,true); return; }
    showEditor(path, data.raw||'');
  })
  .catch(function(){ toast('Không thể đọc file', true); });
}

function openFileByPath(path) {
  var f = treeData.find(function(x){ return resolvePath(x.id) === path; });
  if (f) openFile(f.id);
}

/* ════════════════════════════════════════════════════════
 *  Editor panel
 * ════════════════════════════════════════════════════════ */
function showEditor(path, raw) {
  document.getElementById('sk-home').style.display = 'none';
  document.getElementById('sk-editor').style.display = '';
  document.getElementById('sk-editor-filename').textContent = path;
  document.getElementById('sk-editor-content').value = raw;
  var m = document.getElementById('sk-editor-msg');
  if (m) { m.className='sk-save-msg'; m.style.display='none'; }
}

function goHome() {
  selectedFileId = null; currentFilePath = null;
  document.getElementById('sk-home').style.display = '';
  document.getElementById('sk-editor').style.display = 'none';
  renderTree();
}

document.getElementById('sk-editor-home').onclick = goHome;

/* Save */
var saveBtn = document.getElementById('sk-editor-save');
if (saveBtn) {
  saveBtn.onclick = function(){
    if (!currentFilePath) return;
    saveBtn.disabled = true; saveBtn.textContent = '⏳ Đang lưu...';
    var raw = document.getElementById('sk-editor-content').value;
    var m = document.getElementById('sk-editor-msg');
    fetch(REST+'/file', { method:'POST', headers:h(), body:JSON.stringify({path:currentFilePath,raw:raw}) })
    .then(function(r){ return r.json(); })
    .then(function(d){
      saveBtn.disabled=false; saveBtn.textContent='💾 Lưu';
      if (d.error) { m.className='sk-save-msg err'; m.textContent='❌ '+d.error; m.style.display=''; return; }
      if (d.db_synced === false) {
        m.className='sk-save-msg warn'; m.textContent='⚠️ File đã lưu nhưng chưa sync vào DB (skill_id=0)'; m.style.display='';
        toast('⚠️ Skill chưa sync DB — kiểm tra frontmatter', true);
      } else {
        m.className='sk-save-msg ok'; m.textContent='✅ Đã lưu! (skill #' + (d.skill_id||'?') + ')'; m.style.display='';
        setTimeout(function(){ m.style.display='none'; },2000);
        toast('Đã lưu ' + currentFilePath);
      }
    })
    .catch(function(){ saveBtn.disabled=false; saveBtn.textContent='💾 Lưu'; m.className='sk-save-msg err'; m.textContent='❌ Lỗi'; m.style.display=''; });
  };
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey||e.metaKey)&&e.key==='s'&&currentFilePath) { e.preventDefault(); saveBtn.click(); }
  });
}

/* Delete from editor */
var delBtn = document.getElementById('sk-editor-delete');
if (delBtn) delBtn.onclick = function(){ if (currentFilePath) deleteItem(currentFilePath, false); };

/* ════════════════════════════════════════════════════════
 *  Create Folder
 * ════════════════════════════════════════════════════════ */
var folderInput = document.getElementById('sk-folder-input');
var addFolderBtn = document.getElementById('sk-btn-addfolder');
if (addFolderBtn && folderInput) {
  addFolderBtn.onclick = function(){ folderInput.style.display=''; folderInput.value=''; folderInput.focus(); };
  folderInput.onkeydown = function(e){
    if (e.key==='Enter') {
      var name = folderInput.value.trim();
      if (name) {
        fetch(REST+'/folder', { method:'POST', headers:h(), body:JSON.stringify({name:name,parentPath:''}) })
        .then(function(r){ return r.json(); })
        .then(function(d){ if(d.error){toast(d.error,true);return;} toast('Đã tạo folder: '+name); loadTree(); })
        .catch(function(){ toast('Lỗi kết nối',true); });
      }
      folderInput.style.display='none';
    }
    if (e.key==='Escape') folderInput.style.display='none';
  };
  folderInput.onblur = function(){ folderInput.style.display='none'; };
}

/* ════════════════════════════════════════════════════════
 *  Update folder <select> for modal
 * ════════════════════════════════════════════════════════ */
function updateFolderSelect() {
  var sel = document.getElementById('sk-modal-folder');
  if (!sel) return;
  var html = '<option value="/">(root)</option>';
  treeData.forEach(function(f){
    if (f.isDir && f.id !== '0') {
      var path = resolvePath(f.id);
      html += '<option value="'+esc(path)+'">'+esc(path)+'</option>';
    }
  });
  sel.innerHTML = html;
}

/* ════════════════════════════════════════════════════════
 *  Templates
 * ════════════════════════════════════════════════════════ */
function generateTemplate(tplId, slug, label) {
  slug = slug||'new-skill'; label = label||slug;
  var L = label.toLowerCase();
  var tpls = {
    automation: "---\nname: "+slug+"\ntitle: \""+label+"\"\ndescription: \"\"\nversion: \"1.0\"\ntriggers:\n  - \""+L+"\"\n  - \"/"+slug+"\"\nslash_commands:\n  - /"+slug+"\nmodes:\n  - planning\n  - execution\nrelated_tools:\n  - create_workflow\nrequired_inputs:\n  - flow_name\n  - trigger\noutput_format: json_workflow\npriority: 90\nstatus: active\n---\n\n# 🎯 Mục tiêu\n\n(Mô tả mục tiêu chính)\n\n# ⚡ Khi nào dùng\n\n- Khi người dùng yêu cầu...\n\n# 📋 Quy trình\n\n1. Bước 1\n2. Bước 2\n3. Bước 3\n\n# 🛡 Guardrails\n\n- Luôn xác nhận trước khi thực thi\n\n# 💡 Ví dụ\n\nUser: \"...\"\nAI: \"...\"",
    tool: "---\nname: "+slug+"\ntitle: \""+label+"\"\ndescription: \"\"\nversion: \"1.0\"\ntriggers:\n  - \""+L+"\"\nslash_commands:\n  - /"+slug+"\nmodes:\n  - execution\nrelated_tools:\n  - tool_name_here\nrequired_inputs:\n  - input_1\noutput_format: text\npriority: 80\nstatus: active\n---\n\n# 🎯 Mục tiêu\n\n(Skill gọi 1 tool cụ thể)\n\n# ⚡ Khi nào dùng\n\n- Khi người dùng yêu cầu...\n\n# 📋 Quy trình\n\n1. Thu thập input\n2. Gọi tool\n3. Trả kết quả\n\n# 🛡 Guardrails\n\n- Validate input trước khi gọi tool\n\n# 💡 Ví dụ\n\nUser: \"...\"\nAI: \"...\"",
    content: "---\nname: "+slug+"\ntitle: \""+label+"\"\ndescription: \"\"\nversion: \"1.0\"\ntriggers:\n  - \"viết "+L+"\"\n  - \"tạo "+L+"\"\nslash_commands:\n  - /"+slug+"\nmodes:\n  - content\n  - creative\nrelated_tools: []\nrequired_inputs:\n  - topic\n  - tone\n  - length\noutput_format: markdown\npriority: 70\nstatus: active\n---\n\n# 🎯 Mục tiêu\n\n(Viết nội dung chất lượng)\n\n# ⚡ Khi nào dùng\n\n- Khi cần viết "+L+"\n\n# 📋 Quy trình\n\n1. Xác định chủ đề\n2. Tạo outline\n3. Viết nội dung\n4. Review\n\n# 💡 Ví dụ\n\nUser: \"Viết bài...\"\nAI: \"...\"",
    analysis: "---\nname: "+slug+"\ntitle: \""+label+"\"\ndescription: \"\"\nversion: \"1.0\"\ntriggers:\n  - \"phân tích "+L+"\"\n  - \"báo cáo "+L+"\"\nslash_commands:\n  - /"+slug+"\nmodes:\n  - analysis\nrelated_tools: []\nrequired_inputs:\n  - data_source\n  - metric\noutput_format: markdown_report\npriority: 75\nstatus: active\n---\n\n# 🎯 Mục tiêu\n\n(Phân tích dữ liệu)\n\n# ⚡ Khi nào dùng\n\n- Khi cần phân tích...\n\n# 📋 Quy trình\n\n1. Thu thập dữ liệu\n2. Phân tích\n3. Output báo cáo\n\n# 💡 Ví dụ\n\nUser: \"Phân tích...\"\nAI: \"...\"",
    blank: "---\nname: "+slug+"\ntitle: \""+label+"\"\ndescription: \"\"\nversion: \"1.0\"\ntriggers:\n  - \""+L+"\"\nslash_commands:\n  - /"+slug+"\nmodes:\n  - default\nrelated_tools: []\nrequired_inputs: []\noutput_format: text\npriority: 50\nstatus: active\n---\n\n# 🎯 Mục tiêu\n\n(Mô tả...)\n\n# ⚡ Khi nào dùng\n\n- ...\n\n# 📋 Quy trình\n\n1. ...\n\n# 💡 Ví dụ\n\nUser: \"...\"\nAI: \"...\""
  };
  return tpls[tplId] || tpls.blank;
}

/* ════════════════════════════════════════════════════════
 *  New Skill Modal (admin only)
 * ════════════════════════════════════════════════════════ */
var modal = document.getElementById('sk-modal');
var modalSelectedTpl = 'blank';

if (IS_ADMIN && modal) {
  var newSkillBtn = document.getElementById('sk-btn-newskill');
  if (newSkillBtn) newSkillBtn.onclick = function(){ openModal('blank'); };

  document.querySelectorAll('.sk-tpl-card[data-tpl]').forEach(function(c){
    c.onclick = function(){ openModal(this.getAttribute('data-tpl')); };
  });

  var tplBtn = document.getElementById('sk-btn-templates');
  if (tplBtn) tplBtn.onclick = function(){ goHome(); };

  function openModal(tplId) {
    modalSelectedTpl = tplId || 'blank';
    modal.style.display = '';
    document.getElementById('sk-modal-name').value = '';
    document.getElementById('sk-modal-label').value = '';
    updateFolderSelect();
    document.querySelectorAll('#sk-modal-tpls .sk-modal-tpl').forEach(function(t){
      t.classList.toggle('selected', t.getAttribute('data-tpl') === modalSelectedTpl);
    });
  }

  document.getElementById('sk-modal-close').onclick = function(){ modal.style.display='none'; };
  modal.addEventListener('click', function(e){ if(e.target===modal) modal.style.display='none'; });

  document.querySelectorAll('#sk-modal-tpls .sk-modal-tpl').forEach(function(t){
    t.onclick = function(){
      modalSelectedTpl = this.getAttribute('data-tpl');
      document.querySelectorAll('#sk-modal-tpls .sk-modal-tpl').forEach(function(x){ x.classList.remove('selected'); });
      this.classList.add('selected');
    };
  });

  document.getElementById('sk-modal-submit').onclick = function(){
    var name = document.getElementById('sk-modal-name').value.trim().replace(/\.md$/i, '');
    var label = document.getElementById('sk-modal-label').value.trim() || name;
    var folder = document.getElementById('sk-modal-folder').value;
    if (!name) { alert('Nhập tên file!'); return; }
    var slug = name.toLowerCase().replace(/[^a-z0-9\u00C0-\u024F\-_]/g, '-').replace(/-+/g, '-');
    var path = (folder === '/' ? '/' : folder + '/') + slug + '.md';
    var raw = generateTemplate(modalSelectedTpl, slug, label);
    var btn = this;
    btn.disabled = true; btn.textContent = '⏳...';
    fetch(REST+'/file', { method:'POST', headers:h(), body:JSON.stringify({path:path,raw:raw}) })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled=false; btn.textContent='🚀 Tạo Skill';
      if (d.error) { toast(d.error,true); return; }
      modal.style.display = 'none';
      toast('Đã tạo: ' + path);
      loadTree(function(){ openFileByPath(path); });
    })
    .catch(function(){ btn.disabled=false; btn.textContent='🚀 Tạo Skill'; toast('Lỗi kết nối',true); });
  };
}

/* ════════════════════════════════════════════════════════
 *  Workflow card clicks → send to chat
 * ════════════════════════════════════════════════════════ */
document.querySelectorAll('[data-msg]').forEach(function(el){
  el.addEventListener('click', function(e){ e.preventDefault(); sendMsg(this.getAttribute('data-msg')); });
});

})();
</script>
<?php endif; ?>
</body>
</html>
