<?php
/**
 * BizCity Tarot – Agent Profile Page: /tarot/
 *
 * Frontend profile form cho người dùng khai báo sở thích Tarot.
 * Được load trong Touch Bar iframe của AI Agent dashboard.
 * Mục đích: Thu thập profile context (chủ đề quan tâm, câu hỏi thường gặp)
 * và hiển thị lịch sử bốc bài — để Intent Router có dữ liệu khi điều phối.
 *
 * @package BizCity_Tarot
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

$user_id     = get_current_user_id();
$is_logged_in = is_user_logged_in();
?>

<div id="bct-profile-wrap" style="max-width:680px;margin:30px auto;padding:20px;font-family:Inter,system-ui,-apple-system,sans-serif;">

<?php if ( ! $is_logged_in ): ?>
    <div style="text-align:center;padding:60px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">🔐</div>
        <h2 style="color:#1a1a2e;font-size:22px;">Đăng nhập để tiếp tục</h2>
        <p style="color:#6b7280;margin-bottom:20px;">Bạn cần đăng nhập để sử dụng AI Agent Tarot và xem lịch sử bốc bài.</p>
        <a href="<?php echo wp_login_url( get_permalink() ); ?>" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#8b5cf6,#a855f7);color:#fff;border-radius:12px;text-decoration:none;font-weight:600;">
            Đăng nhập
        </a>
    </div>
<?php else:

    // Load user preferences from user_meta
    $prefs = get_user_meta( $user_id, 'bct_tarot_preferences', true );
    if ( ! is_array( $prefs ) ) $prefs = [];

    $fav_topic    = $prefs['favorite_topic'] ?? '';
    $fav_spread   = $prefs['favorite_spread'] ?? '3';
    $question_ctx = $prefs['default_question'] ?? '';

    // Handle form save
    $saved_msg = '';
    if ( ! empty( $_POST['bct_profile_save'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'bct_frontend_profile' ) ) {
        $prefs = [
            'favorite_topic'   => sanitize_text_field( $_POST['favorite_topic'] ?? '' ),
            'favorite_spread'  => sanitize_text_field( $_POST['favorite_spread'] ?? '3' ),
            'default_question' => sanitize_text_field( $_POST['default_question'] ?? '' ),
        ];
        update_user_meta( $user_id, 'bct_tarot_preferences', $prefs );
        $fav_topic    = $prefs['favorite_topic'];
        $fav_spread   = $prefs['favorite_spread'];
        $question_ctx = $prefs['default_question'];
        $saved_msg    = '✅ Đã lưu sở thích Tarot!';
    }

    // Reading history
    global $wpdb;
    $t = bct_tables();
    $readings = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, topic, question, card_ids, cards_json, is_reversed, ai_reply, created_at
         FROM {$t['readings']}
         WHERE user_id = %d
         ORDER BY created_at DESC
         LIMIT 10",
        $user_id
    ), ARRAY_A );

    $has_prefs    = ! empty( $fav_topic );
    $reading_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['readings']} WHERE user_id=%d", $user_id
    ) );

    // Topics list (from bct_topics if exists)
    $topics = [];
    if ( function_exists( 'bct_get_topics_list' ) ) {
        $topics = bct_get_topics_list();
    } else {
        $topics = [
            'love'    => 'Tình yêu',
            'career'  => 'Sự nghiệp',
            'finance' => 'Tài chính',
            'health'  => 'Sức khỏe',
            'general' => 'Tổng quan',
            'family'  => 'Gia đình',
        ];
    }
?>

    <style>
        #bct-profile-wrap h2 { font-size:20px; font-weight:700; color:#1a1a2e; margin:0 0 6px; }
        #bct-profile-wrap .bct-pf-section { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:20px; margin-bottom:16px; }
        #bct-profile-wrap .bct-pf-section h3 { margin:0 0 12px; font-size:16px; font-weight:700; }
        #bct-profile-wrap label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:4px; }
        #bct-profile-wrap input[type="text"],
        #bct-profile-wrap select,
        #bct-profile-wrap textarea { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:10px; font-size:14px; box-sizing:border-box; }
        #bct-profile-wrap textarea { resize:vertical; min-height:60px; }
        #bct-profile-wrap .bct-pf-row { margin-bottom:12px; }
        #bct-profile-wrap .bct-pf-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        #bct-profile-wrap .bct-pf-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 20px; border-radius:10px; border:none; font-size:14px; font-weight:600; cursor:pointer; }
        #bct-profile-wrap .bct-pf-btn-primary { background:linear-gradient(135deg,#8b5cf6,#a855f7); color:#fff; }
        #bct-profile-wrap .bct-pf-status { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
        #bct-profile-wrap .bct-pf-ok { background:#dcfce7; color:#166534; }
        #bct-profile-wrap .bct-pf-warn { background:#fef3c7; color:#92400e; }
        #bct-profile-wrap .bct-pf-notice { padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:16px; }
        #bct-profile-wrap .bct-reading-item { padding:12px; border:1px solid #f3f4f6; border-radius:12px; margin-bottom:8px; }
        #bct-profile-wrap .bct-reading-date { font-size:11px; color:#9ca3af; }
        #bct-profile-wrap .bct-reading-cards { display:flex; gap:4px; margin-top:6px; flex-wrap:wrap; }
        #bct-profile-wrap .bct-reading-card { padding:3px 8px; background:#f5f3ff; border:1px solid #c4b5fd; border-radius:8px; font-size:11px; }

        /* ── Bottom Nav Bar ── */
        .bct-nav { position:sticky; top:0; z-index:100; display:flex; background:#fff; border-bottom:1px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.06); margin:-20px -20px 20px; }
        .bct-nav-item { flex:1; display:flex; flex-direction:column; align-items:center; padding:10px 4px 8px; text-decoration:none; color:#9ca3af; font-size:11px; font-weight:600; cursor:pointer; transition:color .2s; border:none; background:none; border-bottom:3px solid transparent; }
        .bct-nav-item:hover { color:#8b5cf6; }
        .bct-nav-item.active { color:#8b5cf6; border-bottom-color:#8b5cf6; }
        .bct-nav-icon { font-size:20px; line-height:1; margin-bottom:2px; }
        .bct-tab-panel { display:none; }
        .bct-tab-panel.active { display:block; }

        /* ── Interpret Tab ── */
        .bct-interp-card-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(100px,1fr)); gap:8px; max-height:320px; overflow-y:auto; padding:4px; }
        .bct-interp-card-item { border:2px solid #e5e7eb; border-radius:10px; padding:6px; text-align:center; cursor:pointer; transition:all .15s; background:#fff; }
        .bct-interp-card-item:hover { border-color:#c4b5fd; background:#f5f3ff; }
        .bct-interp-card-item.selected { border-color:#8b5cf6; background:#ede9fe; box-shadow:0 0 0 2px rgba(139,92,246,.3); }
        .bct-interp-card-item img { width:100%; border-radius:6px; aspect-ratio:2/3; object-fit:cover; }
        .bct-interp-card-item .card-label { font-size:10px; color:#374151; margin-top:4px; line-height:1.2; word-break:break-word; }
        .bct-interp-reverse-toggle { display:flex; align-items:center; gap:8px; margin-top:12px; }
        .bct-interp-reverse-toggle input[type="checkbox"] { width:18px; height:18px; accent-color:#8b5cf6; }
        .bct-interp-photo-area { border:2px dashed #d1d5db; border-radius:12px; padding:24px; text-align:center; cursor:pointer; transition:border-color .2s; margin-bottom:12px; }
        .bct-interp-photo-area:hover { border-color:#8b5cf6; }
        .bct-interp-photo-area.has-file { border-color:#8b5cf6; background:#f5f3ff; }
        .bct-interp-result { background:#faf5ff; border:1px solid #c4b5fd; border-radius:16px; padding:20px; margin-top:16px; font-size:14px; line-height:1.7; color:#374151; }
        .bct-interp-result h3, #bct-modal-body h3 { font-size:17px; margin:18px 0 8px; color:#5b21b6; }
        .bct-interp-result h4, #bct-modal-body h4 { font-size:15px; margin:14px 0 6px; color:#6d28d9; }
        .bct-interp-result ul, #bct-modal-body ul { margin:6px 0 6px 18px; padding:0; }
        .bct-interp-result li, #bct-modal-body li { margin-bottom:3px; }
        .bct-interp-search { position:relative; }
        .bct-interp-search input { padding-left:32px !important; }
        .bct-interp-search::before { content:'🔍'; position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:14px; z-index:1; }
        .bct-interp-method-btns { display:flex; gap:8px; margin-bottom:16px; }
        .bct-interp-method-btn { flex:1; padding:14px; border:2px solid #e5e7eb; border-radius:12px; background:#fff; cursor:pointer; text-align:center; transition:all .15s; }
        .bct-interp-method-btn:hover { border-color:#c4b5fd; }
        .bct-interp-method-btn.active { border-color:#8b5cf6; background:#ede9fe; }
        .bct-interp-method-btn .method-icon { font-size:28px; display:block; margin-bottom:6px; }
        .bct-interp-method-btn .method-label { font-size:13px; font-weight:600; color:#374151; }
        .bct-interp-method-btn .method-desc { font-size:11px; color:#9ca3af; margin-top:2px; }
    </style>

    <!-- ═══════════════════ Navigation Bar ═══════════════════ -->
    <nav class="bct-nav">
        <button class="bct-nav-item active" data-tab="profile">
            <span class="bct-nav-icon">🔮</span><span>Hồ sơ</span>
        </button>
        <button class="bct-nav-item" data-tab="interpret">
            <span class="bct-nav-icon">🃏</span><span>Giải nghĩa</span>
        </button>
    </nav>

    <!-- ═══════════════════ TAB 1: Hồ sơ Tarot ═══════════════════ -->
    <div class="bct-tab-panel active" id="bct-tab-profile">

    <!-- Status -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <h2>🔮 Hồ sơ Tarot</h2>
        <span class="bct-pf-status <?php echo $has_prefs ? 'bct-pf-ok' : 'bct-pf-warn'; ?>">
            <?php echo $has_prefs ? '✅ Đã thiết lập' : '⚠️ Chưa thiết lập'; ?>
        </span>
        <span class="bct-pf-status bct-pf-ok">🃏 <?php echo $reading_count; ?> lượt bốc bài</span>
    </div>

    <?php if ( $saved_msg ): ?>
        <div class="bct-pf-notice" style="background:#dcfce7;color:#166534;"><?php echo esc_html( $saved_msg ); ?></div>
    <?php endif; ?>

    <?php if ( ! $has_prefs ): ?>
        <div class="bct-pf-notice" style="background:#fef3c7;color:#92400e;">
            ⚠️ <strong>Sở thích chưa được thiết lập.</strong> Hãy cho AI Agent biết chủ đề bạn quan tâm để nhận được gợi ý bốc bài phù hợp hơn.
        </div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field( 'bct_frontend_profile' ); ?>

        <div class="bct-pf-section">
            <h3>🎯 Sở thích Tarot</h3>

            <div class="bct-pf-grid">
                <div class="bct-pf-row">
                    <label>Chủ đề yêu thích</label>
                    <select name="favorite_topic">
                        <option value="">-- Chọn chủ đề --</option>
                        <?php foreach ( $topics as $tk => $tl ): ?>
                            <option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $fav_topic, $tk ); ?>>
                                <?php echo esc_html( $tl ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bct-pf-row">
                    <label>Kiểu trải bài mặc định</label>
                    <select name="favorite_spread">
                        <option value="1" <?php selected( $fav_spread, '1' ); ?>>1 lá</option>
                        <option value="3" <?php selected( $fav_spread, '3' ); ?>>3 lá (Quá khứ - Hiện tại - Tương lai)</option>
                    </select>
                </div>
            </div>

            <div class="bct-pf-row">
                <label>Câu hỏi / Bối cảnh mặc định</label>
                <textarea name="default_question" placeholder="VD: Tôi đang phân vân giữa 2 lựa chọn công việc..."><?php echo esc_textarea( $question_ctx ); ?></textarea>
                <p style="font-size:11px;color:#9ca3af;margin-top:4px;">AI Agent sẽ dùng bối cảnh này khi bạn bốc bài mà không nêu câu hỏi cụ thể.</p>
            </div>
        </div>

        <button type="submit" name="bct_profile_save" value="1" class="bct-pf-btn bct-pf-btn-primary">💾 Lưu sở thích</button>
    </form>

    <!-- Reading History -->
    <?php if ( ! empty( $readings ) ): ?>
    <div class="bct-pf-section" style="margin-top:20px;">
        <h3>📖 Lịch sử bốc bài gần đây</h3>

        <?php foreach ( $readings as $r ):
            $cards_data = json_decode( $r['cards_json'] ?? '[]', true );
            $reversed   = explode( ',', $r['is_reversed'] ?? '' );
        ?>
        <div class="bct-reading-item">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong style="font-size:14px;"><?php echo esc_html( $r['topic'] ?: 'Tổng quan' ); ?></strong>
                <span class="bct-reading-date"><?php echo esc_html( $r['created_at'] ); ?></span>
            </div>
            <?php if ( $r['question'] ): ?>
                <p style="font-size:13px;color:#6b7280;margin:4px 0;"><?php echo esc_html( $r['question'] ); ?></p>
            <?php endif; ?>
            <?php if ( ! empty( $cards_data ) ): ?>
                <div class="bct-reading-cards">
                    <?php foreach ( $cards_data as $i => $card ):
                        $is_rev = isset( $reversed[ $i ] ) && $reversed[ $i ] === '1';
                        $name   = $card['name_vi'] ?? $card['name_en'] ?? ( 'Lá ' . ( $i + 1 ) );
                    ?>
                        <span class="bct-reading-card"><?php echo esc_html( $name ); ?><?php echo $is_rev ? ' ↕' : ''; ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php
                $has_ai_reply = ! empty( $r['ai_reply'] );
            ?>
            <div style="margin-top:8px;">
                <?php if ( $has_ai_reply ): ?>
                    <button type="button" class="bct-pf-btn-view-reading" data-reading-id="<?php echo (int) $r['id']; ?>" style="padding:4px 12px;font-size:12px;background:linear-gradient(135deg,#8b5cf6,#a855f7);color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                        🔮 Xem luận giải
                    </button>
                <?php else: ?>
                    <span style="font-size:11px;color:#9ca3af;font-style:italic;">Chưa có luận giải</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ( $reading_count > 10 ): ?>
            <p style="font-size:12px;color:#9ca3af;text-align:center;margin-top:8px;">
                Hiển thị 10 / <?php echo $reading_count; ?> lượt bốc bài
            </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    </div><!-- end #bct-tab-profile -->

    <!-- ═══════════════════ TAB 2: Giải nghĩa lá bài ═══════════════════ -->
    <div class="bct-tab-panel" id="bct-tab-interpret">

        <h2 style="margin-bottom:16px;">🃏 Giải nghĩa lá bài</h2>

        <!-- Step 1: Choose input method -->
        <div class="bct-pf-section">
            <h3>Chọn cách giải nghĩa</h3>
            <div class="bct-interp-method-btns">
                <div class="bct-interp-method-btn" data-method="prompt">
                    <span class="method-icon">✍️</span>
                    <span class="method-label">Nhập tên lá bài</span>
                    <span class="method-desc">Gõ tên + nhu cầu giải nghĩa</span>
                </div>
                <div class="bct-interp-method-btn active" data-method="select">
                    <span class="method-icon">🃏</span>
                    <span class="method-label">Chọn lá bài</span>
                    <span class="method-desc">Tìm trong bộ bài 78 lá</span>
                </div>
                <div class="bct-interp-method-btn" data-method="photo">
                    <span class="method-icon">📸</span>
                    <span class="method-label">Chụp ảnh</span>
                    <span class="method-desc">Gửi ảnh lá bài để nhận diện</span>
                </div>
            </div>
        </div>

        <!-- Method: Prompt input (type card name + question) -->
        <div id="bct-interp-prompt" class="bct-pf-section" style="display:none;">
            <h3>✍️ Nhập tên lá bài & nhu cầu giải nghĩa</h3>
            <div class="bct-pf-row">
                <label>Tên lá bài bạn bốc được</label>
                <input type="text" id="bct-prompt-cards" placeholder="VD: The Fool, Queen of Cups, 10 of Wands…">
                <p style="font-size:11px;color:#9ca3af;margin-top:4px;">Nhập 1 hoặc nhiều lá, ngăn cách bằng dấu phẩy. Ghi thêm "ngược" nếu lá bị reversed.</p>
            </div>
            <div class="bct-pf-row">
                <label>Bạn muốn giải nghĩa về điều gì?</label>
                <textarea id="bct-prompt-question" rows="3" placeholder="VD: Mình bốc được lá The Tower về tình cảm, muốn biết ý nghĩa sâu…"></textarea>
            </div>
        </div>

        <!-- Method: Select from card DB -->
        <div id="bct-interp-select" class="bct-pf-section">
            <h3>🔍 Tìm & chọn lá bài</h3>
            <div class="bct-pf-row bct-interp-search">
                <input type="text" id="bct-card-search" placeholder="Gõ tên lá bài... (The Fool, Queen of Cups, 10 Gậy...)">
            </div>
            <div class="bct-interp-card-grid" id="bct-card-grid">
                <div style="grid-column:1/-1;text-align:center;padding:20px;color:#9ca3af;">
                    <span style="font-size:24px;">⏳</span><br>Đang tải bộ bài...
                </div>
            </div>
            <div id="bct-selected-cards" style="margin-top:12px;display:none;">
                <label>Lá bài đã chọn:</label>
                <div id="bct-selected-list" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;"></div>
            </div>
            <div class="bct-interp-reverse-toggle">
                <input type="checkbox" id="bct-card-reversed">
                <label for="bct-card-reversed" style="margin:0;cursor:pointer;">Lá bài ngược ↕ (Reversed)</label>
            </div>
        </div>

        <!-- Method: Photo upload -->
        <div id="bct-interp-photo" class="bct-pf-section" style="display:none;">
            <h3>📸 Gửi ảnh lá bài</h3>
            <div class="bct-interp-photo-area" id="bct-photo-area">
                <div id="bct-photo-placeholder">
                    <span style="font-size:36px;">📷</span>
                    <p style="color:#6b7280;margin:8px 0 0;font-size:13px;">Nhấp để chọn ảnh hoặc kéo thả vào đây</p>
                    <p style="color:#9ca3af;font-size:11px;margin-top:4px;">Hỗ trợ: JPG, PNG, WebP (tối đa 5MB)</p>
                </div>
                <div id="bct-photo-preview" style="display:none;">
                    <img id="bct-photo-img" style="max-width:200px;border-radius:10px;" alt="">
                    <p style="color:#8b5cf6;font-size:12px;margin-top:6px;">✅ Đã chọn ảnh · <a href="#" id="bct-photo-remove" style="color:#dc2626;">Xoá</a></p>
                </div>
            </div>
            <input type="file" id="bct-photo-input" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </div>

        <!-- Question focus (shared — hidden in prompt mode) -->
        <div class="bct-pf-section" id="bct-interp-shared-focus">
            <h3>💭 Khía cạnh muốn giải (tuỳ chọn)</h3>
            <div class="bct-pf-row">
                <select id="bct-interp-focus">
                    <option value="">-- Tổng quát --</option>
                    <?php foreach ( $topics as $tk => $tl ): ?>
                        <option value="<?php echo esc_attr( $tl ); ?>"><?php echo esc_html( $tl ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bct-pf-row">
                <textarea id="bct-interp-question" placeholder="Câu hỏi cụ thể (tuỳ chọn): VD: Mối quan hệ hiện tại sẽ đi về đâu?"></textarea>
            </div>
        </div>

        <!-- Submit -->
        <button type="button" id="bct-interp-submit" class="bct-pf-btn bct-pf-btn-primary" style="width:100%;justify-content:center;padding:14px;">
            🔮 Giải nghĩa lá bài
        </button>

        <!-- Result -->
        <div id="bct-interp-result-wrap" style="display:none;">
            <div class="bct-interp-result" id="bct-interp-result"></div>
        </div>

    </div><!-- end #bct-tab-interpret -->

<?php endif; // is_logged_in ?>

</div>

<!-- Reading Detail Modal -->
<div id="bct-reading-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;z-index:99999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;max-width:640px;width:92%;max-height:85vh;overflow-y:auto;padding:28px;position:relative;box-shadow:0 25px 50px rgba(0,0,0,.25);">
        <button id="bct-modal-close" type="button" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;line-height:1;">✕</button>
        <div id="bct-modal-header" style="margin-bottom:16px;">
            <h3 style="margin:0 0 4px;font-size:18px;color:#1a1a2e;">🔮 Luận giải Tarot</h3>
            <div id="bct-modal-meta" style="font-size:12px;color:#9ca3af;"></div>
        </div>
        <div id="bct-modal-cards" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;"></div>
        <div id="bct-modal-body" style="font-size:14px;line-height:1.7;color:#374151;"></div>
    </div>
</div>

<?php if ( $is_logged_in ): ?>
<script>
(function(){
    var ajaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
    var nonce    = '<?php echo wp_create_nonce( 'bct_pub_nonce' ); ?>';

    /* ══════════════════════════════════════════════════════
     *  Tab Navigation
     * ══════════════════════════════════════════════════════ */
    document.querySelectorAll('.bct-nav-item').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.bct-nav-item').forEach(function(b){ b.classList.remove('active'); });
            document.querySelectorAll('.bct-tab-panel').forEach(function(p){ p.classList.remove('active'); });
            btn.classList.add('active');
            document.getElementById('bct-tab-' + btn.getAttribute('data-tab')).classList.add('active');
        });
    });

    /* ══════════════════════════════════════════════════════
     *  Reading Modal (existing)
     * ══════════════════════════════════════════════════════ */
    var modal    = document.getElementById('bct-reading-modal');
    var body     = document.getElementById('bct-modal-body');
    var cards    = document.getElementById('bct-modal-cards');
    var meta     = document.getElementById('bct-modal-meta');
    var header   = document.querySelector('#bct-modal-header h3');

    document.getElementById('bct-modal-close').addEventListener('click', function(){ modal.style.display = 'none'; });
    modal.addEventListener('click', function(e){ if(e.target === modal) modal.style.display = 'none'; });

    /**
     * Simple markdown→HTML converter for Tarot AI responses
     */
    function bctFormatMd(text) {
        if (!text) return '';
        // Escape HTML first
        text = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        // Split into lines for block-level processing
        var lines = text.split('\n');
        var html = [];
        var inList = false;
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            // Headers: ### → h4, ## → h3
            if (/^###\s+(.+)/.test(line)) {
                if (inList) { html.push('</ul>'); inList = false; }
                html.push('<h4>' + line.replace(/^###\s+/, '') + '</h4>');
                continue;
            }
            if (/^##\s+(.+)/.test(line)) {
                if (inList) { html.push('</ul>'); inList = false; }
                html.push('<h3>' + line.replace(/^##\s+/, '') + '</h3>');
                continue;
            }
            // Bullet list: - item or * item
            if (/^\s*[-*]\s+(.+)/.test(line)) {
                if (!inList) { html.push('<ul>'); inList = true; }
                html.push('<li>' + line.replace(/^\s*[-*]\s+/, '') + '</li>');
                continue;
            }
            // Numbered list: 1. item
            if (/^\s*\d+\.\s+(.+)/.test(line)) {
                if (!inList) { html.push('<ul>'); inList = true; }
                html.push('<li>' + line.replace(/^\s*\d+\.\s+/, '') + '</li>');
                continue;
            }
            // Close list if we're no longer in one
            if (inList) { html.push('</ul>'); inList = false; }
            // Empty line → paragraph break
            if (line.trim() === '') {
                html.push('<br>');
                continue;
            }
            // Normal line
            html.push(line + '<br>');
        }
        if (inList) html.push('</ul>');
        var result = html.join('\n');
        // Inline formatting: bold **text**, italic *text*
        result = result.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        result = result.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        // Clean up excessive <br>
        result = result.replace(/(<br>\s*){3,}/g, '<br><br>');
        return result;
    }

    document.querySelectorAll('.bct-pf-btn-view-reading').forEach(function(btn){
        btn.addEventListener('click', function(){
            var rid = this.getAttribute('data-reading-id');
            modal.style.display = 'flex';
            body.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;"><span style="font-size:24px;">⏳</span><br>Đang tải luận giải…</div>';
            cards.innerHTML = '';
            meta.textContent = '';
            header.textContent = '🔮 Luận giải Tarot';

            var fd = new FormData();
            fd.append('action', 'bct_get_reading');
            fd.append('nonce', nonce);
            fd.append('reading_id', rid);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if(!res.success){
                        body.innerHTML = '<div style="color:#dc2626;">❌ ' + (res.data || 'Lỗi') + '</div>';
                        return;
                    }
                    var d = res.data;
                    header.textContent = '🔮 ' + (d.topic || 'Tổng quan');
                    meta.textContent = (d.question ? '❓ ' + d.question + '  •  ' : '') + '📅 ' + d.created_at;

                    cards.innerHTML = '';
                    (d.cards || []).forEach(function(c){
                        var span = document.createElement('span');
                        span.style.cssText = 'padding:3px 10px;background:#f5f3ff;border:1px solid #c4b5fd;border-radius:8px;font-size:12px;';
                        span.textContent = (c.name_vi || c.name_en || '?') + (c.is_reversed ? ' ↕' : '');
                        cards.appendChild(span);
                    });

                    if(d.ai_reply){
                        body.innerHTML = bctFormatMd(d.ai_reply);
                    } else {
                        body.innerHTML = '<div style="color:#9ca3af;text-align:center;padding:20px;">Chưa có nội dung luận giải cho lần bốc bài này.</div>';
                    }
                })
                .catch(function(){
                    body.innerHTML = '<div style="color:#dc2626;">❌ Không thể tải dữ liệu.</div>';
                });
        });
    });

    /* ══════════════════════════════════════════════════════
     *  Interpret Tab — Method Switching
     * ══════════════════════════════════════════════════════ */
    var interpMethod = 'select';
    var interpPanels = { select:'bct-interp-select', photo:'bct-interp-photo', prompt:'bct-interp-prompt' };
    document.querySelectorAll('.bct-interp-method-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.bct-interp-method-btn').forEach(function(b){ b.classList.remove('active'); });
            btn.classList.add('active');
            interpMethod = btn.getAttribute('data-method');
            Object.keys(interpPanels).forEach(function(k){
                document.getElementById(interpPanels[k]).style.display = k === interpMethod ? '' : 'none';
            });
            // Hide shared question/focus section when prompt mode (it has its own)
            var sharedQ = document.getElementById('bct-interp-shared-focus');
            if(sharedQ) sharedQ.style.display = interpMethod === 'prompt' ? 'none' : '';
        });
    });

    /* ══════════════════════════════════════════════════════
     *  Interpret Tab — Card Grid Loading & Selection
     * ══════════════════════════════════════════════════════ */
    var allCards = [];
    var selectedCards = [];

    function loadCardGrid() {
        var fd = new FormData();
        fd.append('action', 'bct_get_cards');
        fd.append('nonce', nonce);

        fetch(ajaxUrl, { method:'POST', body:fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if(!res.success) return;
                allCards = res.data || [];
                renderCardGrid(allCards);
            });
    }

    function renderCardGrid(list) {
        var grid = document.getElementById('bct-card-grid');
        if(!list.length){
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:#9ca3af;">Không tìm thấy lá bài nào.</div>';
            return;
        }
        grid.innerHTML = '';
        list.forEach(function(c){
            var div = document.createElement('div');
            div.className = 'bct-interp-card-item';
            if(selectedCards.some(function(s){ return s.id === c.id; })) div.classList.add('selected');
            div.setAttribute('data-id', c.id);
            var img = c.image_url ? '<img src="' + c.image_url + '" alt="' + (c.card_name_vi || c.card_name_en || '') + '" loading="lazy">' : '<div style="width:100%;aspect-ratio:2/3;background:#f3f4f6;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:28px;">🃏</div>';
            div.innerHTML = img + '<div class="card-label">' + (c.card_name_vi || c.card_name_en || 'Lá ' + c.id) + '</div>';
            div.addEventListener('click', function(){ toggleCard(c); });
            grid.appendChild(div);
        });
    }

    function toggleCard(c) {
        var idx = selectedCards.findIndex(function(s){ return s.id === c.id; });
        if(idx>=0) {
            selectedCards.splice(idx,1);
        } else {
            if(selectedCards.length >= 5) { alert('Tối đa 5 lá bài.'); return; }
            selectedCards.push(c);
        }
        updateSelectedUI();
        // Update grid highlights
        document.querySelectorAll('.bct-interp-card-item').forEach(function(el){
            var sid = parseInt(el.getAttribute('data-id'));
            el.classList.toggle('selected', selectedCards.some(function(s){ return s.id === sid; }));
        });
    }

    function updateSelectedUI(){
        var wrap = document.getElementById('bct-selected-cards');
        var list = document.getElementById('bct-selected-list');
        if(selectedCards.length === 0) { wrap.style.display = 'none'; return; }
        wrap.style.display = '';
        list.innerHTML = '';
        selectedCards.forEach(function(c,i){
            var span = document.createElement('span');
            span.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#ede9fe;border:1px solid #8b5cf6;border-radius:8px;font-size:12px;font-weight:600;color:#5b21b6;';
            span.innerHTML = (c.card_name_vi || c.card_name_en) + ' <button type="button" style="background:none;border:none;cursor:pointer;color:#dc2626;font-weight:700;padding:0 2px;" data-idx="'+i+'">✕</button>';
            span.querySelector('button').addEventListener('click', function(e){
                e.stopPropagation();
                selectedCards.splice(i,1);
                updateSelectedUI();
                renderCardGrid(filteredCards());
            });
            list.appendChild(span);
        });
    }

    function filteredCards(){
        var q = (document.getElementById('bct-card-search').value || '').toLowerCase().trim();
        if(!q) return allCards;
        return allCards.filter(function(c){
            return (c.card_name_vi||'').toLowerCase().indexOf(q) >= 0
                || (c.card_name_en||'').toLowerCase().indexOf(q) >= 0
                || (c.card_slug||'').toLowerCase().indexOf(q) >= 0;
        });
    }

    // Search filter
    var searchTimer;
    document.getElementById('bct-card-search').addEventListener('input', function(){
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function(){ renderCardGrid(filteredCards()); }, 200);
    });

    // Auto-load on tab switch
    var cardsLoaded = false;
    document.querySelector('[data-tab="interpret"]').addEventListener('click', function(){
        if(!cardsLoaded){ loadCardGrid(); cardsLoaded = true; }
    });

    /* ══════════════════════════════════════════════════════
     *  Interpret Tab — Photo Upload
     * ══════════════════════════════════════════════════════ */
    var photoFile = null;
    var photoArea = document.getElementById('bct-photo-area');
    var photoInput = document.getElementById('bct-photo-input');

    photoArea.addEventListener('click', function(){ photoInput.click(); });
    photoArea.addEventListener('dragover', function(e){ e.preventDefault(); photoArea.style.borderColor = '#8b5cf6'; });
    photoArea.addEventListener('dragleave', function(){ photoArea.style.borderColor = ''; });
    photoArea.addEventListener('drop', function(e){
        e.preventDefault();
        photoArea.style.borderColor = '';
        if(e.dataTransfer.files.length) handlePhotoFile(e.dataTransfer.files[0]);
    });
    photoInput.addEventListener('change', function(){
        if(this.files.length) handlePhotoFile(this.files[0]);
    });

    function handlePhotoFile(file) {
        if(file.size > 5*1024*1024) { alert('Ảnh quá lớn! Tối đa 5MB.'); return; }
        if(!file.type.match(/^image\/(jpeg|png|webp)$/)) { alert('Chỉ hỗ trợ JPG, PNG, WebP.'); return; }
        photoFile = file;
        photoArea.classList.add('has-file');
        document.getElementById('bct-photo-placeholder').style.display = 'none';
        var preview = document.getElementById('bct-photo-preview');
        preview.style.display = '';
        var reader = new FileReader();
        reader.onload = function(e){ document.getElementById('bct-photo-img').src = e.target.result; };
        reader.readAsDataURL(file);
    }

    document.getElementById('bct-photo-remove').addEventListener('click', function(e){
        e.preventDefault(); e.stopPropagation();
        photoFile = null;
        photoArea.classList.remove('has-file');
        document.getElementById('bct-photo-placeholder').style.display = '';
        document.getElementById('bct-photo-preview').style.display = 'none';
        photoInput.value = '';
    });

    /* ══════════════════════════════════════════════════════
     *  Interpret Tab — Submit Interpretation
     * ══════════════════════════════════════════════════════ */
    document.getElementById('bct-interp-submit').addEventListener('click', function(){
        var btn = this;
        var focus    = document.getElementById('bct-interp-focus').value;
        var question = document.getElementById('bct-interp-question').value.trim();
        var isRev    = document.getElementById('bct-card-reversed').checked;

        // Validate
        if(interpMethod === 'select' && selectedCards.length === 0) {
            alert('Hãy chọn ít nhất 1 lá bài!'); return;
        }
        if(interpMethod === 'photo' && !photoFile) {
            alert('Hãy gửi ảnh lá bài!'); return;
        }
        if(interpMethod === 'prompt') {
            var promptCards = document.getElementById('bct-prompt-cards').value.trim();
            if(!promptCards) { alert('Hãy nhập tên lá bài bạn bốc được!'); return; }
        }

        // Build cards_json for select method
        var cardsPayload = [];
        if(interpMethod === 'select') {
            selectedCards.forEach(function(c, i){
                cardsPayload.push({
                    position_label: selectedCards.length > 1 ? ('Lá số ' + (i+1)) : 'Lá bài',
                    name_en: c.card_name_en || '',
                    name_vi: c.card_name_vi || '',
                    keywords: c.keywords_vi || c.keywords_en || '',
                    is_reversed: isRev
                });
            });
        }

        btn.disabled = true;
        btn.innerHTML = '⏳ Đang giải nghĩa...';

        var resultWrap = document.getElementById('bct-interp-result-wrap');
        var resultDiv  = document.getElementById('bct-interp-result');
        resultWrap.style.display = '';
        resultDiv.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;"><span style="font-size:32px;">🔮</span><br>AI đang chiêm nghiệm lá bài của bạn…<br><span style="font-size:11px;">Có thể mất 10-30 giây</span></div>';

        var fd = new FormData();
        fd.append('action', 'bct_ai_interpret');
        fd.append('nonce', nonce);
        fd.append('topic', focus || 'general');
        fd.append('question', question || (focus || 'Hướng đi trong cuộc sống'));

        if(interpMethod === 'select') {
            fd.append('cards_json', JSON.stringify(cardsPayload));
        } else if(interpMethod === 'photo') {
            // Photo mode — send 1 card with photo data
            fd.append('cards_json', JSON.stringify([{
                position_label: 'Lá bài (từ ảnh)',
                name_en: '',
                name_vi: '',
                keywords: '',
                is_reversed: false,
                has_photo: true
            }]));
            fd.append('card_photo', photoFile);
        } else if(interpMethod === 'prompt') {
            // Prompt mode — parse card names from text input
            var promptCards = document.getElementById('bct-prompt-cards').value.trim();
            var promptQ     = document.getElementById('bct-prompt-question').value.trim();
            var cardNames   = promptCards.split(/[,;，、]+/).map(function(s){ return s.trim(); }).filter(Boolean);
            var promptPayload = cardNames.map(function(name, i){
                var isRev = /ngược|reversed|rev/i.test(name);
                var cleanName = name.replace(/\s*(ngược|reversed|rev)\s*/gi, '').trim();
                return {
                    position_label: cardNames.length > 1 ? ('Lá số ' + (i+1)) : 'Lá bài',
                    name_en: cleanName,
                    name_vi: '',
                    keywords: '',
                    is_reversed: isRev
                };
            });
            fd.append('cards_json', JSON.stringify(promptPayload));
            // Override question with prompt's own question field
            if(promptQ) {
                fd.set('question', promptQ);
            }
        }

        // ── Step 1: Save reading to history FIRST ──
        var saveFd = new FormData();
        saveFd.append('action', 'bct_save_reading');
        saveFd.append('nonce', nonce);
        saveFd.append('topic', fd.get('topic'));
        saveFd.append('question', fd.get('question'));
        saveFd.append('cards_json', fd.get('cards_json'));
        saveFd.append('card_ids', (interpMethod === 'select' ? selectedCards.map(function(c){ return c.id; }).join(',') : ''));
        saveFd.append('is_reversed', (interpMethod === 'select' && document.getElementById('bct-card-reversed').checked) ? '1' : '0');
        saveFd.append('session_id', '');

        fetch(ajaxUrl, { method:'POST', body:saveFd })
            .then(function(r){ return r.json(); })
            .then(function(saveRes){
                var readingId = (saveRes.success && saveRes.data) ? saveRes.data.reading_id : 0;

                // ── Step 2: Call AI interpret ──
                return fetch(ajaxUrl, { method:'POST', body:fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        btn.disabled = false;
                        btn.innerHTML = '🔮 Giải nghĩa lá bài';

                        if(!res.success) {
                            resultDiv.innerHTML = '<div style="color:#dc2626;">❌ ' + (res.data || 'Lỗi khi giải nghĩa') + '</div>';
                            return;
                        }

                        var ai = res.data.reply || res.data.ai_reply || '';
                        if(typeof ai === 'object') ai = ai.reply || ai.ai_reply || JSON.stringify(ai);

                        resultDiv.innerHTML = '<h3 style="margin:0 0 12px;font-size:18px;">🔮 Luận giải Tarot</h3>' + bctFormatMd(ai);
                        resultWrap.scrollIntoView({ behavior:'smooth', block:'start' });

                        // ── Step 3: Save AI reply back to reading history ──
                        if(readingId && ai) {
                            var updateFd = new FormData();
                            updateFd.append('action', 'bct_update_reading_ai');
                            updateFd.append('nonce', nonce);
                            updateFd.append('reading_id', readingId);
                            updateFd.append('ai_reply', ai);
                            fetch(ajaxUrl, { method:'POST', body:updateFd });
                        }
                    });
            })
            .catch(function(err){
                btn.disabled = false;
                btn.innerHTML = '🔮 Giải nghĩa lá bài';
                resultDiv.innerHTML = '<div style="color:#dc2626;">❌ Lỗi kết nối: ' + err.message + '</div>';
            });
    });
})();
</script>
<?php endif; ?>

<?php get_footer(); ?>
