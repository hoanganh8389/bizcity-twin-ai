<?php
/**
 * BizCity Tarot – Admin Cards Page (Dashboard + Edit)
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Dashboard / Card List
 * ------------------------------------------------------------- */
function bct_page_dashboard(): void {
    global $wpdb;
    $t = bct_tables();

    // Handle single card save
    if ( isset( $_POST['bct_save_card'], $_POST['bct_card_nonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bct_card_nonce'] ) ), 'bct_edit_card' )
    ) {
        $id = (int) $_POST['card_id'];
        $wpdb->update( $t['cards'], [
            'card_name_vi'   => sanitize_text_field( wp_unslash( $_POST['card_name_vi'] ?? '' ) ),
            'keywords_vi'    => sanitize_textarea_field( wp_unslash( $_POST['keywords_vi'] ?? '' ) ),
            'description_vi' => wp_kses_post( wp_unslash( $_POST['description_vi'] ?? '' ) ),
            'upright_vi'     => wp_kses_post( wp_unslash( $_POST['upright_vi'] ?? '' ) ),
            'reversed_vi'    => wp_kses_post( wp_unslash( $_POST['reversed_vi'] ?? '' ) ),
            'image_url'      => esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) ),
        ], [ 'id' => $id ] );
        echo '<div class="notice notice-success"><p>✅ Đã lưu thông tin lá bài!</p></div>';
    }

    // Edit mode?
    $edit_id = isset( $_GET['edit_card'] ) ? (int) $_GET['edit_card'] : 0;
    if ( $edit_id > 0 ) {
        $card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['cards']} WHERE id = %d", $edit_id ) );
        if ( $card ) {
            bct_render_edit_card( $card );
            return;
        }
    }

    // Counts
    $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['cards']}" );
    $crawled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['cards']} WHERE description_en IS NOT NULL AND description_en != ''" );
    $types   = $wpdb->get_results( "SELECT card_type, COUNT(*) as cnt FROM {$t['cards']} GROUP BY card_type" );

    // Filter
    $filter_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
    $filter_suit = isset( $_GET['suit'] ) ? sanitize_text_field( $_GET['suit'] ) : '';
    $search      = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

    $where = '1=1';
    $args  = [];
    if ( $filter_type ) {
        $where .= ' AND card_type = %s';
        $args[] = $filter_type;
    }
    if ( $filter_suit ) {
        $where .= ' AND suit = %s';
        $args[] = $filter_suit;
    }
    if ( $search ) {
        $where .= ' AND (card_name_en LIKE %s OR card_name_vi LIKE %s)';
        $args[] = '%' . $wpdb->esc_like( $search ) . '%';
        $args[] = '%' . $wpdb->esc_like( $search ) . '%';
    }

    $sql   = $args
        ? $wpdb->prepare( "SELECT * FROM {$t['cards']} WHERE $where ORDER BY sort_order ASC", ...$args )
        : "SELECT * FROM {$t['cards']} WHERE $where ORDER BY sort_order ASC";
    $cards = $wpdb->get_results( $sql );
    ?>
    <div class="wrap bct-admin">
        <h1>🃏 Quản lý bài Tarot</h1>

        <!-- Stats -->
        <div class="bct-stats">
            <div class="bct-stat-box">
                <span class="bct-stat-num"><?php echo esc_html( $total ); ?></span>
                <span class="bct-stat-label">Tổng số lá</span>
            </div>
            <div class="bct-stat-box">
                <span class="bct-stat-num"><?php echo esc_html( $crawled ); ?></span>
                <span class="bct-stat-label">Đã crawl xong</span>
            </div>
            <div class="bct-stat-box">
                <span class="bct-stat-num"><?php echo esc_html( $total - $crawled ); ?></span>
                <span class="bct-stat-label">Chưa có nội dung</span>
            </div>
        </div>

        <p>Shortcode để nhúng: <code>[bizcity_tarot]</code> &nbsp;|&nbsp;
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BCT_SLUG . '-crawl' ) ); ?>" class="button button-primary">
                🔄 Crawl dữ liệu từ learntarot.com
            </a>
        </p>

        <!-- Filters -->
        <form method="get" style="margin-bottom:12px">
            <input type="hidden" name="page" value="<?php echo esc_attr( BCT_SLUG ); ?>">
            <select name="type" onchange="this.form.submit()">
                <option value="">-- Loại bài --</option>
                <option value="major" <?php selected( $filter_type, 'major' ); ?>>Major Arcana</option>
                <option value="minor" <?php selected( $filter_type, 'minor' ); ?>>Minor Arcana</option>
            </select>
            <select name="suit" onchange="this.form.submit()">
                <option value="">-- Bộ bài --</option>
                <?php foreach ( [ 'wands', 'cups', 'swords', 'pentacles' ] as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_suit, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Tìm lá bài...">
            <button type="submit" class="button">Lọc</button>
            <?php if ( $filter_type || $filter_suit || $search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BCT_SLUG ) ); ?>" class="button">Xóa lọc</a>
            <?php endif; ?>
        </form>

        <!-- Cards Table -->
        <table class="widefat bct-cards-table">
            <thead>
                <tr>
                    <th>Hình ảnh</th>
                    <th>Tên (EN)</th>
                    <th>Tên (VI)</th>
                    <th>Loại</th>
                    <th>Bộ</th>
                    <th>Từ khóa VI</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $cards as $card ) : ?>
                    <tr>
                        <td>
                            <?php if ( $card->image_url ) : ?>
                                <img src="<?php echo esc_url( $card->image_url ); ?>"
                                     alt="<?php echo esc_attr( $card->card_name_en ); ?>"
                                     style="width:40px;height:auto;border-radius:4px;">
                            <?php else : ?>
                                <span class="bct-no-img">🃏</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo esc_html( $card->card_name_en ); ?></strong></td>
                        <td><?php echo esc_html( $card->card_name_vi ?: '—' ); ?></td>
                        <td>
                            <span class="bct-badge bct-badge-<?php echo esc_attr( $card->card_type ); ?>">
                                <?php echo $card->card_type === 'major' ? 'Major' : 'Minor'; ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $card->suit ? ucfirst( $card->suit ) : '—' ); ?></td>
                        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?php echo esc_html( $card->keywords_vi ?: '—' ); ?>
                        </td>
                        <td>
                            <?php if ( $card->description_en ) : ?>
                                <span class="bct-badge bct-badge-ok">✅ Đã crawl</span>
                            <?php else : ?>
                                <span class="bct-badge bct-badge-warn">⚠️ Chưa có</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( [ 'page' => BCT_SLUG, 'edit_card' => $card->id ] , admin_url( 'admin.php' ) ) ); ?>"
                               class="button button-small">Sửa</a>
                            <a href="<?php echo esc_url( $card->source_url ); ?>" target="_blank" class="button button-small">Xem gốc</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * Edit Single Card
 * ------------------------------------------------------------- */
function bct_render_edit_card( object $card ): void {
    $back_url = admin_url( 'admin.php?page=' . BCT_SLUG );
    ?>
    <div class="wrap bct-admin">
        <h1>
            <a href="<?php echo esc_url( $back_url ); ?>">← Danh sách</a>
            &nbsp;/&nbsp; Sửa lá bài: <?php echo esc_html( $card->card_name_en ); ?>
        </h1>
        <div style="display:flex;gap:24px;align-items:flex-start">
            <?php if ( $card->image_url ) : ?>
                <img src="<?php echo esc_url( $card->image_url ); ?>" style="width:120px;height:auto;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.3)">
            <?php endif; ?>
            <form method="post" style="flex:1">
                <?php wp_nonce_field( 'bct_edit_card', 'bct_card_nonce' ); ?>
                <input type="hidden" name="card_id" value="<?php echo (int) $card->id; ?>">
                <input type="hidden" name="bct_save_card" value="1">
                <table class="form-table">
                    <tr>
                        <th>Tên tiếng Việt</th>
                        <td><input type="text" name="card_name_vi" value="<?php echo esc_attr( $card->card_name_vi ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>URL hình ảnh</th>
                        <td><input type="url" name="image_url" value="<?php echo esc_attr( $card->image_url ); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th>Từ khóa (VI)</th>
                        <td><textarea name="keywords_vi" class="large-text" rows="2"><?php echo esc_textarea( $card->keywords_vi ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Mô tả tổng quát (VI)</th>
                        <td><?php wp_editor( $card->description_vi ?? '', 'description_vi', [ 'textarea_rows' => 5, 'media_buttons' => false ] ); ?></td>
                    </tr>
                    <tr>
                        <th>Ý nghĩa thuận (Upright)</th>
                        <td><?php wp_editor( $card->upright_vi ?? '', 'upright_vi', [ 'textarea_rows' => 4, 'media_buttons' => false ] ); ?></td>
                    </tr>
                    <tr>
                        <th>Ý nghĩa ngược (Reversed)</th>
                        <td><?php wp_editor( $card->reversed_vi ?? '', 'reversed_vi', [ 'textarea_rows' => 4, 'media_buttons' => false ] ); ?></td>
                    </tr>
                    <tr>
                        <th>Nguồn (EN) – Description</th>
                        <td><p><?php echo nl2br( esc_html( substr( $card->description_en ?? '(Chưa crawl)', 0, 600 ) ) ); ?></p></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">💾 Lưu lá bài</button></p>
            </form>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * History Page
 * ------------------------------------------------------------- */
function bct_page_history(): void {
    global $wpdb;
    $t        = bct_tables();
    $readings = $wpdb->get_results( "SELECT * FROM {$t['readings']} ORDER BY created_at DESC LIMIT 100" );
    ?>
    <div class="wrap bct-admin">
        <h1>📜 Lịch sử trải bài Tarot</h1>
        <?php if ( ! $readings ) : ?>
            <p>Chưa có trải bài nào được lưu.</p>
        <?php else : ?>
            <table class="widefat">
                <thead>
                    <tr><th>#</th><th>Thời gian</th><th>User ID</th><th>Platform</th><th>Client ID</th><th>Chủ đề</th><th>Câu hỏi</th><th>Các lá bài</th></tr>
                </thead>
                <tbody>
                    <?php
                    $platform_labels = [
                        'ZALO_PERSONAL' => '<span style="background:#0068ff;color:#fff;padding:1px 6px;border-radius:4px;font-size:11px">Zalo</span>',
                        'ZALO_BOT'      => '<span style="background:#0068ff;color:#fff;padding:1px 6px;border-radius:4px;font-size:11px">Zalo Bot</span>',
                        'ADMINCHAT'     => '<span style="background:#7c3aed;color:#fff;padding:1px 6px;border-radius:4px;font-size:11px">AdminChat</span>',
                        'WEBCHAT'       => '<span style="background:#059669;color:#fff;padding:1px 6px;border-radius:4px;font-size:11px">Webchat</span>',
                    ];
                    ?>
                    <?php foreach ( $readings as $r ) : ?>
                        <tr>
                            <td><?php echo (int) $r->id; ?></td>
                            <td><?php echo esc_html( $r->created_at ); ?></td>
                            <td><?php echo $r->user_id ? (int) $r->user_id : '<em style="color:#999">—</em>'; ?></td>
                            <td><?php
                                $plat = $r->platform ?? '';
                                echo ! empty( $plat )
                                    ? ( $platform_labels[ $plat ] ?? '<code style="font-size:11px">' . esc_html( $plat ) . '</code>' )
                                    : '<em style="color:#999">—</em>';
                            ?></td>
                            <td>
                                <?php if ( ! empty( $r->client_id ) ) : ?>
                                    <code style="font-size:11px"><?php echo esc_html( $r->client_id ); ?></code>
                                <?php else : ?>
                                    <em style="color:#999">—</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $r->topic ); ?></td>
                            <td><?php echo esc_html( $r->question ); ?></td>
                            <td><?php
                                if ( $r->cards_json ) {
                                    $cards = json_decode( $r->cards_json, true );
                                    if ( is_array( $cards ) ) {
                                        echo esc_html( implode( ', ', array_column( $cards, 'name_en' ) ) );
                                    }
                                } else {
                                    echo esc_html( $r->card_ids );
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ---------------------------------------------------------------
 * Settings Page
 * ------------------------------------------------------------- */
function bct_page_settings(): void {
    if ( isset( $_POST['bct_save_settings'] ) && check_admin_referer( 'bct_settings' ) ) {
        update_option( 'bct_cards_to_pick', (int) ( $_POST['cards_to_pick'] ?? 3 ) );
        update_option( 'bct_save_readings', isset( $_POST['save_readings'] ) ? 1 : 0 );
        update_option( 'bct_show_reversed', isset( $_POST['show_reversed'] ) ? 1 : 0 );
        if ( isset( $_POST['bct_tarot_page_url'] ) ) {
            update_option( 'bct_tarot_page_url', esc_url_raw( wp_unslash( $_POST['bct_tarot_page_url'] ) ) );
        }
        echo '<div class="notice notice-success"><p>✅ Đã lưu cài đặt!</p></div>';
    }
    $cards_to_pick  = (int) get_option( 'bct_cards_to_pick', 3 );
    $save_readings  = get_option( 'bct_save_readings', 1 );
    $show_reversed  = get_option( 'bct_show_reversed', 1 );
    ?>
    <div class="wrap bct-admin">
        <h1>⚙️ Cài đặt BizCity Tarot</h1>
        <form method="post">
            <?php wp_nonce_field( 'bct_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>Số lá bài rút</th>
                    <td>
                        <select name="cards_to_pick">
                            <?php foreach ( [ 1, 3, 5, 7, 10 ] as $n ) : ?>
                                <option value="<?php echo $n; ?>" <?php selected( $cards_to_pick, $n ); ?>><?php echo $n; ?> lá</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Mặc định 3 lá (Quá khứ · Hiện tại · Tương lai)</p>
                    </td>
                </tr>
                <tr>
                    <th>Lưu lịch sử trải bài</th>
                    <td><label><input type="checkbox" name="save_readings" value="1" <?php checked( $save_readings, 1 ); ?>> Lưu vào database</label></td>
                </tr>
                <tr>
                    <th>Lá bài ngược</th>
                    <td><label><input type="checkbox" name="show_reversed" value="1" <?php checked( $show_reversed, 1 ); ?>> Cho phép lá bài xuất hiện ngược</label></td>
                </tr>
                <?php do_action( 'bct_settings_fields' ); ?>
            </table>
            <p>
                <strong>Shortcode:</strong> <code>[bizcity_tarot]</code><br>
                <strong>Tùy chỉnh:</strong> <code>[bizcity_tarot cards="3" show_topics="1" show_questions="1"]</code>
            </p>
            <button type="submit" name="bct_save_settings" class="button button-primary">💾 Lưu cài đặt</button>
        </form>
    </div>
    <?php
}
