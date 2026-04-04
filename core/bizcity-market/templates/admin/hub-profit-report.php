<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_Market
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
$table = BizCity_Market_DB::t_hub_rollups();
$hub_blog_id = get_current_blog_id();
$credit_to_vnd = 1000; // Quy đổi 1 credit = 1000 VND

// Lấy dữ liệu tổng hợp
$rollup_data = $wpdb->get_results($wpdb->prepare("
    SELECT type, SUM(amount_credit) AS total_credit, year, month, day
    FROM $table
    WHERE hub_blog_id = %d
    GROUP BY type, year, month, day
    ORDER BY year DESC, month DESC, day DESC
", $hub_blog_id));

// Lấy % hoa hồng
$commission_percent = Bizcity_Hub_Checker::get_commission_percent($hub_blog_id);

// Chuẩn bị dữ liệu cho biểu đồ
$chart_data = [];
$type_totals = [];
foreach ($rollup_data as $row) {
    $date = sprintf('%02d/%02d/%d', $row->day, $row->month, $row->year);
    $total_vnd = $row->total_credit * $credit_to_vnd;
    $chart_data[$date] = $total_vnd;
    $type_totals[$row->type] = ($type_totals[$row->type] ?? 0) + $total_vnd;
}
?>

<div class="wrap bc-market-wrap">
    <h1 class="bc-market-head">Thống kê doanh thu/ lợi nhuận của Hub</h1>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th>Ngày</th>
                <th>Loại</th>
                <th>Doanh thu (VND)</th>
                <th>Lợi nhuận (VND)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rollup_data as $row): ?>
                <?php
                $total_vnd = $row->total_credit * $credit_to_vnd; // Quy đổi doanh thu từ credit sang VND
                $profit_vnd = $total_vnd * $commission_percent / 100; // Tính lợi nhuận
                ?>
                <tr>
                    <td><?php echo sprintf('%02d/%02d/%d', $row->day, $row->month, $row->year); ?></td>
                    <td><?php echo esc_html($row->type); ?></td>
                    <td><?php echo number_format($total_vnd); ?> đ</td>
                    <td><?php echo number_format($profit_vnd); ?> đ</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="bc-market-grid">
        <div class="bc-card">
            <h2>Biểu đồ doanh thu theo ngày</h2>
            <canvas id="revenueChart"></canvas>
        </div>
        <div class="bc-card">
            <h2>Tỉ lệ doanh thu theo loại</h2>
            <canvas id="typeChart"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Dữ liệu biểu đồ doanh thu theo ngày
    const revenueData = {
        labels: <?php echo json_encode(array_keys($chart_data)); ?>,
        datasets: [{
            label: 'Doanh thu (VND)',
            data: <?php echo json_encode(array_values($chart_data)); ?>,
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 1
        }]
    };

    // Dữ liệu biểu đồ tỉ lệ doanh thu theo loại
    const typeData = {
        labels: <?php echo json_encode(array_keys($type_totals)); ?>,
        datasets: [{
            label: 'Tỉ lệ doanh thu',
            data: <?php echo json_encode(array_values($type_totals)); ?>,
            backgroundColor: [
                'rgba(255, 99, 132, 0.2)',
                'rgba(54, 162, 235, 0.2)',
                'rgba(255, 206, 86, 0.2)'
            ],
            borderColor: [
                'rgba(255, 99, 132, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(255, 206, 86, 1)'
            ],
            borderWidth: 1
        }]
    };

    // Cấu hình biểu đồ doanh thu theo ngày
    const revenueConfig = {
        type: 'line',
        data: revenueData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Doanh thu theo ngày'
                }
            }
        }
    };

    // Cấu hình biểu đồ tỉ lệ doanh thu theo loại
    const typeConfig = {
        type: 'pie',
        data: typeData,
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Tỉ lệ doanh thu theo loại'
                }
            }
        }
    };

    // Render biểu đồ
    const revenueChart = new Chart(
        document.getElementById('revenueChart'),
        revenueConfig
    );

    const typeChart = new Chart(
        document.getElementById('typeChart'),
        typeConfig
    );
</script>

<style>
    .bc-market-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    .bc-market-wrap {
        padding: 00px;
        background: #f9fafb;
        max-width: 1280px;
    }
    .bc-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        padding: 16px;
        width: 80%;
        margin-bottom: 20px;
    }
    table.striped {
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,.1);   
        margin-bottom: 20px;
        }
</style>