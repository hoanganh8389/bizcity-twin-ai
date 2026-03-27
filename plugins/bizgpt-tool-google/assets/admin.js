/* global jQuery, bzgoogle */
(function ($) {
    'use strict';

    $(function () {
        var $stats = $('#bzgoogle-hub-stats');
        if (!$stats.length) return;

        $.ajax({
            url: bzgoogle.rest_url + '/hub/stats',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', bzgoogle.nonce);
            },
            success: function (res) {
                var html = '<div class="bzgoogle-stats-grid">';
                html += statBox(res.total_accounts, 'Tài khoản Google');
                html += statBox(res.total_sites, 'Site kết nối');
                html += statBox(res.total_users, 'Người dùng');
                html += statBox(res.today_api_calls, 'API calls hôm nay');
                html += '</div>';
                $stats.html(html);
            },
            error: function () {
                $stats.html('<p style="color:#c62828;">Không thể tải thống kê.</p>');
            }
        });

        function statBox(num, label) {
            return '<div class="bzgoogle-stat-box">'
                + '<span class="num">' + (num || 0) + '</span>'
                + '<span class="label">' + label + '</span>'
                + '</div>';
        }
    });
})(jQuery);
