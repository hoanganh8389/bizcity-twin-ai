<?php
/**
 * BizCity Tarot – Crawl / Import page
 * Crawl thông tin từ https://www.learntarot.com
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function bct_page_crawl(): void {
    global $wpdb;
    $t     = bct_tables();
    $cards = $wpdb->get_results( "SELECT id, card_slug, card_name_en, description_en, image_url, source_url FROM {$t['cards']} ORDER BY sort_order ASC" );

    $total     = count( $cards );
    $crawled   = count( array_filter( $cards, fn( $c ) => ! empty( $c->description_en ) ) );
    $remaining = $total - $crawled;
    ?>
    <div class="wrap bct-admin">
        <h1>🔄 Crawl dữ liệu Tarot từ learntarot.com</h1>

        <div class="bct-stats">
            <div class="bct-stat-box">
                <span class="bct-stat-num"><?php echo esc_html( $total ); ?></span>
                <span class="bct-stat-label">Tổng lá</span>
            </div>
            <div class="bct-stat-box bct-stat-ok">
                <span class="bct-stat-num"><?php echo esc_html( $crawled ); ?></span>
                <span class="bct-stat-label">Đã crawl</span>
            </div>
            <div class="bct-stat-box bct-stat-warn">
                <span class="bct-stat-num"><?php echo esc_html( $remaining ); ?></span>
                <span class="bct-stat-label">Còn lại</span>
            </div>
        </div>

        <div class="bct-crawl-info">
            <p>⚠️ <strong>Lưu ý:</strong> Nội dung mô tả từ learntarot.com thuộc bản quyền của Joan Bunning (1995-2021).
            Chức năng crawl này nhằm mục đích <em>tham khảo / dịch thuật nội bộ</em>. Vui lòng sử dụng có trách nhiệm.</p>
        </div>

        <!-- Crawl All Button -->
        <div class="bct-crawl-controls">
            <button id="bct-crawl-all" class="button button-primary button-large">
                🔄 Crawl TẤT CẢ lá chưa có dữ liệu (<?php echo esc_html( $remaining ); ?> lá)
            </button>
            &nbsp;
            <button id="bct-crawl-again" class="button button-secondary button-large">
                🔁 Crawl lại TẤT CẢ (<?php echo esc_html( $total ); ?> lá)
            </button>
            &nbsp;
            <button id="bct-stop-crawl" class="button button-secondary" style="display:none">
                ⏹ Dừng crawl
            </button>
            <span id="bct-crawl-status" style="margin-left:12px;font-style:italic"></span>
        </div>

        <div id="bct-crawl-progress" style="margin-top:12px;display:none">
            <div class="bct-progress-bar-wrap">
                <div id="bct-progress-bar" class="bct-progress-bar" style="width:0%"></div>
            </div>
            <p id="bct-progress-text">0 / <?php echo esc_html( $total ); ?></p>
        </div>

        <!-- Cards Table -->
        <table class="widefat bct-cards-table" style="margin-top:20px">
            <thead>
                <tr>
                    <th style="width:50px">Hình</th>
                    <th>Slug</th>
                    <th>Tên (EN)</th>
                    <th>Nguồn</th>
                    <th>Trạng thái</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody id="bct-card-rows">
                <?php foreach ( $cards as $card ) : ?>
                    <tr id="row-<?php echo esc_attr( $card->card_slug ); ?>">
                        <td>
                            <?php if ( $card->image_url ) : ?>
                                <img src="<?php echo esc_url( $card->image_url ); ?>"
                                     style="width:36px;height:auto;border-radius:3px">
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $card->card_slug ); ?></code></td>
                        <td><?php echo esc_html( $card->card_name_en ); ?></td>
                        <td>
                            <?php
                            $src_url = $card->source_url ?: ( 'https://www.learntarot.com/' . $card->card_slug . '.htm' );
                            ?>
                            <a href="<?php echo esc_url( $src_url ); ?>" target="_blank" style="font-size:11px">
                                <?php echo esc_html( $card->card_slug . '.htm' ); ?>
                            </a>
                        </td>
                        <td class="bct-card-status-<?php echo esc_attr( $card->card_slug ); ?>">
                            <?php if ( $card->description_en ) : ?>
                                <span class="bct-badge bct-badge-ok">✅ Đã crawl</span>
                            <?php else : ?>
                                <span class="bct-badge bct-badge-warn">⏳ Chưa crawl</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="button button-small bct-crawl-one"
                                    data-slug="<?php echo esc_attr( $card->card_slug ); ?>"
                                    data-id="<?php echo (int) $card->id; ?>"
                                    data-url="<?php echo esc_url( $card->source_url ); ?>">
                                🔄 Crawl
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    (function($){
        var nonce     = '<?php echo esc_js( wp_create_nonce( 'bct_nonce' ) ); ?>';
        var ajaxUrl   = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var stopFlag  = false;

        // Crawl single card
        function crawlCard(slug, id, force, callback) {
            $.post(ajaxUrl, {
                action: 'bct_crawl_card',
                nonce:  nonce,
                card_id: id,
                slug:   slug,
                force:  force ? 1 : 0
            }, function(res) {
                if (res.success) {
                    $('.bct-card-status-' + slug).html('<span class="bct-badge bct-badge-ok">✅ Đã crawl</span>');
                } else {
                    $('.bct-card-status-' + slug).html('<span class="bct-badge bct-badge-err">❌ Lỗi</span>');
                }
                if (callback) callback(res.success);
            }).fail(function(){
                $('.bct-card-status-' + slug).html('<span class="bct-badge bct-badge-err">❌ Timeout</span>');
                if (callback) callback(false);
            });
        }

        // Single card button
        $(document).on('click', '.bct-crawl-one', function(){
            var $btn = $(this);
            var slug = $btn.data('slug');
            var id   = $btn.data('id');
            $btn.prop('disabled', true).text('...');
            crawlCard(slug, id, false, function(){
                $btn.prop('disabled', false).text('🔄 Crawl');
            });
        });

        // Generic batch crawl function
        function startBatchCrawl(rows, $triggerBtn, force) {
            force = force || false;
            stopFlag = false;
            $triggerBtn.prop('disabled', true);
            $('#bct-stop-crawl').show();
            $('#bct-crawl-progress').show();

            var total = rows.length;
            var done  = 0;

            function next() {
                if (stopFlag || rows.length === 0) {
                    $triggerBtn.prop('disabled', false);
                    $('#bct-stop-crawl').hide();
                    $('#bct-crawl-status').text(stopFlag ? '⏹ Đã dừng.' : '✅ Hoàn thành!');
                    return;
                }
                var item = rows.shift();
                $('#bct-crawl-status').text('Đang crawl: ' + item.slug);
                crawlCard(item.slug, item.id, force, function(){
                    done++;
                    var pct = Math.round(done / total * 100);
                    $('#bct-progress-bar').css('width', pct + '%');
                    $('#bct-progress-text').text(done + ' / ' + total);
                    setTimeout(next, 800); // 800ms delay giữa các request
                });
            }
            next();
        }

        // Crawl all uncrawled
        $('#bct-crawl-all').on('click', function(){
            var rows = [];
            $('.bct-crawl-one').each(function(){
                var slug = $(this).data('slug');
                if ($('.bct-card-status-' + slug + ' .bct-badge-warn').length) {
                    rows.push({ slug: slug, id: $(this).data('id') });
                }
            });
            startBatchCrawl(rows, $(this), false);
        });

        // Force re-crawl all (overwrite existing)
        $('#bct-crawl-again').on('click', function(){
            if (!confirm('Bạn chắc chắn muốn crawl lại TẤT CẢ <?php echo esc_js( $total ); ?> lá? Dữ liệu cũ sẽ bị ghi đè.')) return;
            var rows = [];
            $('.bct-crawl-one').each(function(){
                rows.push({ slug: $(this).data('slug'), id: $(this).data('id') });
            });
            startBatchCrawl(rows, $(this), true);
        });

        $('#bct-stop-crawl').on('click', function(){
            stopFlag = true;
        });
    })(jQuery);
    </script>
    <?php
}
