<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
 * Dashboard — Overview & Quick Ask
 * ================================================================ */
function bzck_page_dashboard() {
    $user_id = get_current_user_id();
    global $wpdb;
    $t = bzck_tables();

    $total_queries = 0;
    $today_queries = 0;
    $total_tokens  = 0;

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t['search_history']}'" ) === $t['search_history'] ) {
        $total_queries = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['search_history']} WHERE user_id = %d", $user_id
        ) );
        $today_queries = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['search_history']} WHERE user_id = %d AND DATE(created_at) = CURDATE()", $user_id
        ) );
        $total_tokens = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(tokens_prompt + tokens_reply) FROM {$t['search_history']} WHERE user_id = %d", $user_id
        ) );
    }

    $recent = [];
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t['search_history']}'" ) === $t['search_history'] ) {
        $recent = $wpdb->get_results( $wpdb->prepare(
            "SELECT query_text, model_used, tokens_reply, created_at
             FROM {$t['search_history']} WHERE user_id = %d ORDER BY created_at DESC LIMIT 10",
            $user_id
        ), ARRAY_A );
    }

    $bookmarks_count = 0;
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t['bookmarks']}'" ) === $t['bookmarks'] ) {
        $bookmarks_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['bookmarks']} WHERE user_id = %d", $user_id
        ) );
    }

    $topics = bzck_get_topics();

    wp_enqueue_style( 'bzck-admin' );
    wp_enqueue_script( 'bzck-admin' );
    ?>
    <div class="wrap bzck-wrap">
        <h1>🧠 ChatGPT Knowledge — Dashboard</h1>

        <div class="bzck-stats-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin:20px 0">
            <div class="bzck-stat-card" style="background:#10a37f;color:#fff;padding:20px;border-radius:12px;text-align:center">
                <h3 style="margin:0;font-size:28px">💬 <?php echo number_format( $total_queries ); ?></h3>
                <p style="margin:5px 0 0">Tổng câu hỏi</p>
            </div>
            <div class="bzck-stat-card" style="background:#fff;padding:20px;border-radius:12px;text-align:center;border:1px solid #e5e7eb">
                <h3 style="margin:0;font-size:28px">📅 <?php echo $today_queries; ?></h3>
                <p style="margin:5px 0 0">Hôm nay</p>
            </div>
            <div class="bzck-stat-card" style="background:#fff;padding:20px;border-radius:12px;text-align:center;border:1px solid #e5e7eb">
                <h3 style="margin:0;font-size:28px">🔖 <?php echo $bookmarks_count; ?></h3>
                <p style="margin:5px 0 0">Bookmarks</p>
            </div>
            <div class="bzck-stat-card" style="background:#fff;padding:20px;border-radius:12px;text-align:center;border:1px solid #e5e7eb">
                <h3 style="margin:0;font-size:28px">⚡ <?php echo number_format( $total_tokens ); ?></h3>
                <p style="margin:5px 0 0">Tokens đã dùng</p>
            </div>
        </div>

        <!-- Quick Topics -->
        <div class="postbox" style="margin-top:20px">
            <h2 class="hndle" style="padding:12px 16px">💡 Chủ đề gợi ý — Hỏi ChatGPT</h2>
            <div class="inside">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px">
                    <?php foreach ( $topics as $topic ) : ?>
                        <div style="background:#f9fafb;padding:16px;border-radius:10px;border:1px solid #e5e7eb">
                            <span style="font-size:24px"><?php echo $topic['icon']; ?></span>
                            <strong style="display:block;margin:8px 0"><?php echo esc_html( $topic['label'] ); ?></strong>
                            <ul style="margin:0;padding-left:16px;font-size:13px;color:#6b7280">
                                <?php foreach ( $topic['questions'] as $q ) : ?>
                                    <li><a href="#" class="bzck-ask-link" data-question="<?php echo esc_attr( $q ); ?>" style="text-decoration:none;color:#10a37f"><?php echo esc_html( $q ); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Recent Queries -->
        <div class="postbox" style="margin-top:20px">
            <h2 class="hndle" style="padding:12px 16px">📜 Câu hỏi gần đây</h2>
            <div class="inside">
                <?php if ( empty( $recent ) ) : ?>
                    <p style="color:#9ca3af;text-align:center;padding:20px">
                        Chưa có câu hỏi nào. Hãy thử hỏi ChatGPT bất cứ điều gì!
                    </p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead><tr><th>Câu hỏi</th><th>Model</th><th>Tokens</th><th>Thời gian</th></tr></thead>
                        <tbody>
                        <?php foreach ( $recent as $r ) : ?>
                            <tr>
                                <td><a href="#" class="bzck-ask-link" data-question="<?php echo esc_attr( $r['query_text'] ); ?>"><?php echo esc_html( mb_substr( $r['query_text'], 0, 80 ) ); ?></a></td>
                                <td><code><?php echo esc_html( $r['model_used'] ); ?></code></td>
                                <td><?php echo number_format( $r['tokens_reply'] ); ?></td>
                                <td><?php echo esc_html( $r['created_at'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/* ================================================================
 * Ask — Direct ChatGPT Query Page
 * ================================================================ */
function bzck_page_ask() {
    wp_enqueue_style( 'bzck-admin' );
    wp_enqueue_script( 'bzck-admin' );
    ?>
    <div class="wrap bzck-wrap">
        <h1>💡 Hỏi ChatGPT Knowledge</h1>

        <div class="bzck-ask-container">
            <div class="bzck-ask-input-area">
                <textarea id="bzck-question" rows="3" placeholder="Nhập câu hỏi của bạn... (ChatGPT sẽ trả lời chi tiết)" style="width:100%;padding:12px;font-size:15px;border-radius:8px;border:1px solid #d1d5db"></textarea>
                <div style="margin-top:10px;display:flex;gap:10px;align-items:center">
                    <button id="bzck-ask-btn" class="button button-primary button-hero" style="flex:0 0 auto;background:#10a37f;border-color:#10a37f">
                        🧠 Hỏi ChatGPT
                    </button>
                    <span id="bzck-ask-status" style="color:#6b7280;font-size:13px"></span>
                </div>
            </div>

            <div id="bzck-answer-area" style="margin-top:24px;display:none">
                <div class="postbox">
                    <h2 class="hndle" style="padding:12px 16px">
                        <span id="bzck-answer-model" style="font-size:12px;color:#6b7280"></span>
                    </h2>
                    <div class="inside">
                        <div id="bzck-answer-content" style="font-size:14px;line-height:1.7"></div>
                        <div style="margin-top:16px;display:flex;gap:8px">
                            <button class="button bzck-bookmark-btn" title="Bookmark">🔖 Lưu</button>
                            <button class="button bzck-copy-btn" title="Copy">📋 Copy</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/* ================================================================
 * History — Search History
 * ================================================================ */
function bzck_page_history() {
    $user_id = get_current_user_id();
    global $wpdb;
    $t = bzck_tables();

    $page     = max( 1, intval( $_GET['paged'] ?? 1 ) );
    $per_page = 20;
    $offset   = ( $page - 1 ) * $per_page;

    $total = 0;
    $rows  = [];
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t['search_history']}'" ) === $t['search_history'] ) {
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t['search_history']} WHERE user_id = %d", $user_id
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t['search_history']} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );
    }

    wp_enqueue_style( 'bzck-admin' );
    ?>
    <div class="wrap bzck-wrap">
        <h1>📜 Lịch sử tìm kiếm (<?php echo $total; ?> câu hỏi)</h1>
        <?php if ( empty( $rows ) ) : ?>
            <p>Chưa có lịch sử.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Câu hỏi</th><th>Model</th><th>Tokens (in/out)</th><th>Thời gian</th></tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( mb_substr( $r['query_text'], 0, 120 ) ); ?></td>
                        <td><code><?php echo esc_html( $r['model_used'] ); ?></code></td>
                        <td><?php echo number_format( $r['tokens_prompt'] ); ?> / <?php echo number_format( $r['tokens_reply'] ); ?></td>
                        <td><?php echo esc_html( $r['created_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ) {
                echo '<div style="margin-top:16px">';
                echo paginate_links( [
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ] );
                echo '</div>';
            }
            ?>
        <?php endif; ?>
    </div>
    <?php
}

/* ================================================================
 * Bookmarks
 * ================================================================ */
function bzck_page_bookmarks() {
    $user_id = get_current_user_id();
    global $wpdb;
    $t = bzck_tables();

    $rows = [];
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t['bookmarks']}'" ) === $t['bookmarks'] ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t['bookmarks']} WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ), ARRAY_A );
    }

    wp_enqueue_style( 'bzck-admin' );
    ?>
    <div class="wrap bzck-wrap">
        <h1>🔖 Bookmarks (<?php echo count( $rows ); ?>)</h1>
        <?php if ( empty( $rows ) ) : ?>
            <p>Chưa có bookmark nào. Hãy bookmark câu trả lời hay!</p>
        <?php else : ?>
            <?php foreach ( $rows as $bm ) : ?>
                <div class="postbox" style="margin-bottom:16px">
                    <h2 class="hndle" style="padding:10px 16px;font-size:14px">
                        💬 <?php echo esc_html( mb_substr( $bm['query_text'], 0, 100 ) ); ?>
                        <span style="float:right;font-size:12px;color:#9ca3af"><?php echo esc_html( $bm['created_at'] ); ?></span>
                    </h2>
                    <div class="inside" style="font-size:14px;line-height:1.7">
                        <?php echo wp_kses_post( $bm['answer_text'] ); ?>
                        <div style="margin-top:8px">
                            <button class="button button-small bzck-del-bookmark" data-id="<?php echo $bm['id']; ?>">🗑️ Xóa</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

/* ================================================================
 * Settings
 * ================================================================ */
function bzck_page_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }

    // Handle save
    if ( isset( $_POST['bzck_save_settings'] ) && check_admin_referer( 'bzck_settings_nonce' ) ) {
        $settings = [
            'model'       => sanitize_text_field( $_POST['bzck_model'] ?? '' ),
            'temperature' => floatval( $_POST['bzck_temperature'] ?? 0.55 ),
            'max_tokens'  => intval( $_POST['bzck_max_tokens'] ?? 8000 ),
        ];
        update_option( 'bzck_settings', $settings );
        update_option( 'bzck_db_version', '' ); // Force DB migration

        // Knowledge character binding
        $char_id = intval( $_POST['bzck_knowledge_character_id'] ?? 0 );
        update_option( 'bzck_knowledge_character_id', $char_id );

        echo '<div class="notice notice-success"><p>✅ Đã lưu cài đặt!</p></div>';
    }

    $chatgpt  = BizCity_ChatGPT_Knowledge::instance();
    $settings = $chatgpt->get_settings();
    $models   = $chatgpt->get_available_models();

    wp_enqueue_style( 'bzck-admin' );
    ?>
    <div class="wrap bzck-wrap">
        <h1>⚙️ ChatGPT Knowledge — Cài đặt</h1>

        <form method="post">
            <?php wp_nonce_field( 'bzck_settings_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th>ChatGPT Model</th>
                    <td>
                        <select name="bzck_model">
                            <?php foreach ( $models as $id => $info ) : ?>
                                <option value="<?php echo esc_attr( $id ); ?>"
                                    <?php selected( $settings['model'], $id ); ?>>
                                    <?php echo esc_html( $info['name'] . ' — ' . $info['context'] ); ?>
                                    <?php echo ! empty( $info['default'] ) ? ' ⭐' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Model ChatGPT sử dụng cho knowledge responses.</p>
                    </td>
                </tr>
                <tr>
                    <th>Temperature</th>
                    <td>
                        <input type="number" name="bzck_temperature" value="<?php echo esc_attr( $settings['temperature'] ); ?>"
                               step="0.05" min="0" max="1.5" style="width:100px">
                        <p class="description">0 = chính xác, 1.5 = sáng tạo. Mặc định: 0.55</p>
                    </td>
                </tr>
                <tr>
                    <th>Max Tokens</th>
                    <td>
                        <input type="number" name="bzck_max_tokens" value="<?php echo esc_attr( $settings['max_tokens'] ); ?>"
                               step="500" min="1000" max="32000" style="width:120px">
                        <p class="description">Giới hạn output tokens. Mặc định: 8000</p>
                    </td>
                </tr>
            </table>

            <hr>

            <?php bzck_knowledge_settings_section(); ?>

            <p class="submit">
                <button type="submit" name="bzck_save_settings" class="button button-primary" style="background:#10a37f;border-color:#10a37f">💾 Lưu cài đặt</button>
            </p>
        </form>
    </div>
    <?php
}
