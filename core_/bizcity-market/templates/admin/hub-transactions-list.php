<?php
if (!defined('ABSPATH')) exit;
if (!current_user_can('manage_options')) wp_die('No permission');

$transactions = isset($transactions) ? $transactions : [];
?>
<div class="bc-card" style="background:#fff; border-radius:16px; box-shadow:0 6px 16px rgba(0,0,0,.06); padding:24px; margin-top:24px;">
  <h1 style="font-size:22px; font-weight:800; margin-bottom:18px;">Danh sách giao dịch trong Hub của bạn</h1>
  <?php if (empty($transactions)): ?>
    <p style="opacity:.7;">Không có giao dịch nào.</p>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="widefat striped" style="border-radius:12px; overflow:hidden; margin:0;">
        <thead>
          <tr>
            <?php foreach ((array)($transactions[0]) as $col => $val): ?>
              <th style="background:#f8fafc; font-weight:700; padding:10px 8px;"><?php echo esc_html($col); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $row): ?>
            <tr>
              <?php foreach ((array)$row as $val): ?>
                <td style="padding:8px 8px; background:#fff; border-bottom:1px solid #e5e7eb;"><?php echo esc_html($val); ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
