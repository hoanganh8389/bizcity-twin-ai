<?php
if (!defined('ABSPATH')) exit;

class BizCity_Market_Shortcodes {

  public static function boot() {
    add_shortcode('bizcity_market_plugins', [__CLASS__, 'sc_market_plugins']);
  }

  public static function sc_market_plugins($atts) {
    $atts = shortcode_atts([
      'q' => '',
      'featured' => '',
      'limit' => 12,
    ], $atts);

    $q = sanitize_text_field($atts['q']);
    $limit = min(60, max(4, (int)$atts['limit']));
    $desc = $r->quickview ? $r->quickview : $r->description;

    $res = BizCity_Market_Catalog::list(['q'=>$q, 'page'=>1, 'per'=>$limit]);
    $rows = $res['rows'];

    ob_start();
    ?>
    <div class="bc-market-grid">
      <?php foreach ($rows as $r): ?>
        <div class="bc-market-card">
          <div class="bc-market-thumb">
            <?php if (!empty($r->image_url)): ?>
              <img src="<?php echo esc_url($r->image_url); ?>" alt="">
            <?php endif; ?>
          </div>

          <div class="bc-market-body">
            <div class="bc-market-title"><?php echo esc_html($r->title); ?></div>
            <div class="bc-market-meta">
              <span>by <?php echo esc_html($r->author_name ?: '—'); ?></span>
              <span>· 👁 <?php echo (int)$r->views; ?></span>
              <span>· ⭐ <?php echo esc_html($r->useful_score); ?></span>
            </div>

            <div class="bc-market-desc"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($desc), 16)); ?></div>

            <div class="bc-market-price">
              <b><?php echo (int)$r->credit_price; ?></b> credit
              <span class="bc-market-vnd"><?php echo number_format((int)$r->vnd_price); ?>đ</span>
            </div>

            <div class="bc-market-actions">
              <?php if (!empty($r->demo_url)): ?>
                <a class="bc-btn" href="<?php echo esc_url($r->demo_url); ?>" target="_blank">Demo</a>
              <?php endif; ?>
              <?php if (!empty($r->download_url)): ?>
                <a class="bc-btn" href="<?php echo esc_url($r->download_url); ?>" target="_blank">Tải về</a>
              <?php endif; ?>
              <a class="bc-btn bc-btn-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Xem trong Plugins</a>
            </div>

            <div class="bc-market-code">
              <code><?php echo esc_html($r->plugin_file); ?></code>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
  }
}
