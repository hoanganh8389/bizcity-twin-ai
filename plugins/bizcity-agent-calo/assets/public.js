/**
 * BizCity Calo — Public JS (Mobile-first)
 * Handles: tab switching, dashboard data, meal logging, photo upload, AI analysis, charts
 */
(function($) {
    'use strict';

    if (typeof BZCALO === 'undefined') return;

    var $app = $('#bzcalo-app');
    if (!$app.length) return;

    var mealTypeIcons = {
        breakfast: '🌅',
        lunch: '☀️',
        dinner: '🌙',
        snack: '🍪'
    };

    /* ═══════════════════════════════════════════════
       Tab Navigation (SPA-like)
       ═══════════════════════════════════════════════ */
    $app.on('click', '.bzcalo-nav-item', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.bzcalo-nav-item').removeClass('active');
        $(this).addClass('active');
        $('.bzcalo-tab-content').hide();
        $('#bzcalo-tab-' + tab).show();

        // Lazy load data
        if (tab === 'dashboard') loadToday();
        if (tab === 'history') { loadChart7d(); loadHistory(); }
        if (tab === 'weight') loadWeightData();
    });

    /* ═══════════════════════════════════════════════
       Dashboard — Load Today Data
       ═══════════════════════════════════════════════ */
    function loadToday() {
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_get_today',
            nonce: BZCALO.nonce
        }, function(res) {
            if (!res.success) return;
            var d = res.data;

            // Update ring
            var pct = BZCALO.target > 0 ? Math.min(100, Math.round(d.stats.total_calories / BZCALO.target * 100)) : 0;
            $('#bzcalo-ring-progress').attr('stroke-dasharray', pct + ', 100');
            $('#bzcalo-today-cal').text(Math.round(d.stats.total_calories));

            // Update macro progress bars
            var curP = Math.round(d.stats.total_protein);
            var curC = Math.round(d.stats.total_carbs);
            var curF = Math.round(d.stats.total_fat);
            $('#bzcalo-today-p').text(curP);
            $('#bzcalo-today-c').text(curC);
            $('#bzcalo-today-f').text(curF);
            if (BZCALO.target_carbs > 0) $('#bzcalo-bar-carbs').css('width', Math.min(100, curC / BZCALO.target_carbs * 100) + '%');
            if (BZCALO.target_protein > 0) $('#bzcalo-bar-protein').css('width', Math.min(100, curP / BZCALO.target_protein * 100) + '%');
            if (BZCALO.target_fat > 0) $('#bzcalo-bar-fat').css('width', Math.min(100, curF / BZCALO.target_fat * 100) + '%');

            // Update mascot message
            var msgs = [
                [0,   'Chào bạn! Hôm nay bạn ăn gì rồi? \ud83c\udf7d\ufe0f'],
                [30,  'Tốt lắm! Tiếp tục ghi nhận bữa ăn nhé! \ud83d\udcaa'],
                [60,  'Gần đạt mục tiêu rồi, cố lên! \ud83d\udd25'],
                [90,  'Tuyệt vời! Gần đủ calo rồi! \ud83c\udfaf'],
                [100, 'Đã đạt mục tiêu hôm nay! \ud83c\udf89'],
                [120, 'Hơi vượt mức rồi, cẩn thận nhé! \ud83d\ude05']
            ];
            var mascotMsg = msgs[0][1];
            for (var mi = msgs.length - 1; mi >= 0; mi--) {
                if (pct >= msgs[mi][0]) { mascotMsg = msgs[mi][1]; break; }
            }
            $('#bzcalo-mascot-msg').text(mascotMsg);

            // Week strip
            if (d.week_stats && d.week_stats.length) {
                buildWeekStrip(d.week_stats);
            }

            // Meal list
            var $list = $('#bzcalo-today-meals');
            if (!d.meals || !d.meals.length) {
                $list.html('<p class="bzcalo-empty">Chưa ghi bữa ăn nào 📝</p>');
                return;
            }

            var html = '';
            $.each(d.meals, function(i, m) {
                var icon = mealTypeIcons[m.meal_type] || '🍽️';
                var photoHtml = m.photo_url
                    ? '<img src="' + m.photo_url + '" class="bzcalo-meal-photo">'
                    : '<div class="bzcalo-meal-icon">' + icon + '</div>';
                html += '<div class="bzcalo-meal-item" data-id="' + m.id + '">'
                    + photoHtml
                    + '<div class="bzcalo-meal-info">'
                    + '<div class="bzcalo-meal-desc">' + escHtml(m.description || 'Bữa ăn') + '</div>'
                    + '<div class="bzcalo-meal-meta">' + icon + ' ' + (m.meal_time || '').substr(0,5) + '</div>'
                    + '</div>'
                    + '<div class="bzcalo-meal-cal">' + Math.round(m.total_calories) + '</div>'
                    + '<button class="bzcalo-meal-del" data-id="' + m.id + '">🗑️</button>'
                    + '</div>';
            });
            $list.html(html);
        });
    }

    // Delete meal
    $app.on('click', '.bzcalo-meal-del', function() {
        var id = $(this).data('id');
        if (!confirm('Xóa bữa ăn này?')) return;
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_delete_meal',
            nonce: BZCALO.nonce,
            meal_id: id
        }, function(res) {
            if (res.success) loadToday();
        });
    });

    /* ═══════════════════════════════════════════════
       Log Meal — Photo Upload
       ═══════════════════════════════════════════════ */
    var uploadedPhotoUrl = '';

    // Zone is now a <label for="..."> — native trigger works.
    // JS fallback kept for edge cases (e.g. dynamically replaced DOM).
    $('#bzcalo-pub-photo-zone').on('click', function(e) {
        // If the label's native "for" already triggered the input, skip.
        if (e.target.tagName === 'INPUT') return;
        var inp = document.getElementById('bzcalo-pub-photo-input');
        if (inp) inp.click();
    });

    $('#bzcalo-pub-photo-input').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        // Preview
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#bzcalo-pub-photo-img').attr('src', e.target.result);
            $('#bzcalo-pub-photo-preview').show();
            $('#bzcalo-pub-photo-placeholder').hide();
            $('#bzcalo-pub-photo-zone').addClass('has-photo');
        };
        reader.readAsDataURL(file);

        // Upload
        var fd = new FormData();
        fd.append('action', 'bzcalo_upload_photo');
        fd.append('nonce', BZCALO.nonce);
        fd.append('photo', file);

        $.ajax({
            url: BZCALO.ajax_url,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    uploadedPhotoUrl = res.data.url;
                    $('#bzcalo-pub-photo-url').val(res.data.url);
                }
            }
        });
    });

    /* ═══════════════════════════════════════════════
       Log Meal — Meal Type Pills
       ═══════════════════════════════════════════════ */
    $app.on('change', 'input[name="bzcalo_pub_meal_type"]', function() {
        $('.bzcalo-pill').removeClass('active');
        $(this).closest('.bzcalo-pill').addClass('active');
    });

    /* ═══════════════════════════════════════════════
       Log Meal — AI Analyze
       ═══════════════════════════════════════════════ */
    var lastAiData = null;

    $('#bzcalo-pub-btn-ai').on('click', function() {
        var $btn = $(this);
        var photoUrl = uploadedPhotoUrl;
        var desc = $('#bzcalo-pub-desc').val().trim();

        if (!photoUrl && !desc) {
            showStatus('Vui lòng chụp ảnh hoặc mô tả bữa ăn', 'error');
            return;
        }

        $btn.prop('disabled', true).html('<span class="bzcalo-loading"></span> Đang phân tích...');

        // Choose API endpoint
        var data = { nonce: BZCALO.nonce };
        if (photoUrl) {
            data.action = 'bzcalo_ai_analyze_photo';
            data.photo_url = photoUrl;
            if (desc) data.description = desc;
        } else {
            data.action = 'bzcalo_ai_analyze_text';
            data.description = desc;
        }

        $.post(BZCALO.ajax_url, data, function(res) {
            $btn.prop('disabled', false).html('🤖 Phân tích');
            if (!res.success) {
                showStatus(res.data.message || 'Lỗi phân tích', 'error');
                return;
            }

            lastAiData = res.data;
            renderAiResult(res.data);
        }).fail(function() {
            $btn.prop('disabled', false).html('🤖 Phân tích');
            showStatus('Lỗi kết nối', 'error');
        });
    });

    function renderAiResult(data) {
        var $result = $('#bzcalo-pub-ai-result');
        var $items = $('#bzcalo-pub-ai-items');
        $items.empty();

        if (data.items && data.items.length) {
            $.each(data.items, function(i, item) {
                $items.append(
                    '<div class="bzcalo-ai-item">'
                    + '<span>' + escHtml(item.name || item.food_name || '') + '</span>'
                    + '<span>' + Math.round(item.calories || 0) + ' kcal</span>'
                    + '</div>'
                );
            });
        }

        var total = data.total_calories || data.totals?.calories || 0;
        $('#bzcalo-pub-ai-total').html('Tổng: <strong>' + Math.round(total) + ' kcal</strong>');
        $result.show();
    }

    /* ═══════════════════════════════════════════════
       Log Meal — Save
       ═══════════════════════════════════════════════ */
    $('#bzcalo-pub-btn-save').on('click', function() {
        var $btn = $(this);
        var desc = $('#bzcalo-pub-desc').val().trim();
        var mealType = $('input[name="bzcalo_pub_meal_type"]:checked').val() || 'lunch';

        if (!desc && !uploadedPhotoUrl && !lastAiData) {
            showStatus('Vui lòng mô tả hoặc chụp ảnh bữa ăn', 'error');
            return;
        }

        $btn.prop('disabled', true).html('<span class="bzcalo-loading"></span> Đang lưu...');

        var postData = {
            action: 'bzcalo_log_meal',
            nonce: BZCALO.nonce,
            description: desc,
            meal_type: mealType,
            photo_url: uploadedPhotoUrl
        };

        // If AI analyzed, include nutritional data
        if (lastAiData) {
            postData.total_calories = lastAiData.total_calories || lastAiData.totals?.calories || 0;
            postData.total_protein = lastAiData.total_protein || lastAiData.totals?.protein || 0;
            postData.total_carbs = lastAiData.total_carbs || lastAiData.totals?.carbs || 0;
            postData.total_fat = lastAiData.total_fat || lastAiData.totals?.fat || 0;
            if (lastAiData.items) {
                postData.items_json = JSON.stringify(lastAiData.items);
            }
        }

        $.post(BZCALO.ajax_url, postData, function(res) {
            $btn.prop('disabled', false).html('💾 Lưu');
            if (res.success) {
                showStatus('✅ Đã lưu bữa ăn!', 'success');
                resetLogForm();
                // Auto-switch to dashboard
                setTimeout(function() {
                    $('[data-tab="dashboard"]').trigger('click');
                }, 1200);
            } else {
                showStatus(res.data.message || 'Lỗi lưu', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).html('💾 Lưu');
            showStatus('Lỗi kết nối', 'error');
        });
    });

    function resetLogForm() {
        $('#bzcalo-pub-desc').val('');
        uploadedPhotoUrl = '';
        $('#bzcalo-pub-photo-url').val('');
        $('#bzcalo-pub-photo-preview').hide();
        $('#bzcalo-pub-photo-placeholder').show();
        $('#bzcalo-pub-photo-zone').removeClass('has-photo');
        $('#bzcalo-pub-ai-result').hide();
        lastAiData = null;
    }

    /* ═══════════════════════════════════════════════
       History — 7-day chart
       ═══════════════════════════════════════════════ */
    function loadChart7d() {
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_get_chart',
            nonce: BZCALO.nonce,
            days: 7
        }, function(res) {
            if (!res.success || !res.data.length) {
                $('#bzcalo-chart-7d').html('<p class="bzcalo-empty">Chưa có dữ liệu</p>');
                return;
            }

            var maxCal = Math.max.apply(null, res.data.map(function(d) { return d.total_calories; }));
            if (maxCal < 100) maxCal = BZCALO.target || 2000;

            var html = '';
            $.each(res.data, function(i, d) {
                var h = Math.max(4, Math.round(d.total_calories / maxCal * 130));
                var pct = BZCALO.target > 0 ? d.total_calories / BZCALO.target * 100 : 0;
                var color = pct > 100 ? '#ef4444' : (pct > 80 ? '#f59e0b' : '#6366f1');
                html += '<div class="bzcalo-bar-col">'
                    + '<div class="bzcalo-bar" style="height:' + h + 'px;background:' + color + '"></div>'
                    + '<div class="bzcalo-bar-date">' + d.stat_date.substr(5) + '</div>'
                    + '<div class="bzcalo-bar-val">' + Math.round(d.total_calories) + '</div>'
                    + '</div>';
            });
            $('#bzcalo-chart-7d').html(html);
        });
    }

    /* ═══════════════════════════════════════════════
       History — Meal list (last 30)
       ═══════════════════════════════════════════════ */
    function loadHistory() {
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_get_chart',
            nonce: BZCALO.nonce,
            days: 30
        }, function() {
            // get_chart returns daily stats — we use get_today variant for full meals
        });

        // Simple: show last 7 days of daily summaries
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_get_chart',
            nonce: BZCALO.nonce,
            days: 30
        }, function(res) {
            if (!res.success || !res.data.length) {
                $('#bzcalo-history-list').html('<p class="bzcalo-empty">Chưa có dữ liệu</p>');
                return;
            }

            var html = '';
            $.each(res.data.reverse(), function(i, d) {
                html += '<div class="bzcalo-meal-item">'
                    + '<div class="bzcalo-meal-icon">📅</div>'
                    + '<div class="bzcalo-meal-info">'
                    + '<div class="bzcalo-meal-desc">' + d.stat_date + '</div>'
                    + '<div class="bzcalo-meal-meta">' + d.meals_count + ' bữa · P' + Math.round(d.total_protein) + ' C' + Math.round(d.total_carbs) + ' F' + Math.round(d.total_fat) + '</div>'
                    + '</div>'
                    + '<div class="bzcalo-meal-cal">' + Math.round(d.total_calories) + '</div>'
                    + '</div>';
            });
            $('#bzcalo-history-list').html(html);
        });
    }

    /* ═══════════════════════════════════════════════
       Week Strip Builder
       ═══════════════════════════════════════════════ */
    var dayLabels = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

    function buildWeekStrip(weekStats) {
        var today = new Date();
        var todayStr = today.toISOString().substr(0, 10);
        var html = '';
        // Build map date → calories
        var map = {};
        $.each(weekStats, function(i, s) { map[s.stat_date] = s; });

        for (var i = 6; i >= 0; i--) {
            var d = new Date(today);
            d.setDate(d.getDate() - i);
            var ds = d.toISOString().substr(0, 10);
            var dayIdx = d.getDay();
            var isToday = ds === todayStr;
            var hasData = map[ds] && map[ds].total_calories > 0;
            var cal = hasData ? Math.round(map[ds].total_calories) : '';
            var cls = 'bzcalo-week-day' + (isToday ? ' today' : '') + (hasData ? ' has-data' : '');
            html += '<div class="' + cls + '">'
                + '<span class="bzcalo-week-label">' + dayLabels[dayIdx] + '</span>'
                + '<span class="bzcalo-week-dot">' + d.getDate() + '</span>'
                + (cal ? '<span class="bzcalo-week-cal">' + cal + '</span>' : '<span class="bzcalo-week-cal">&nbsp;</span>')
                + '</div>';
        }
        $('#bzcalo-week-strip').html(html);
    }

    /* ═══════════════════════════════════════════════
       Weight Tab — BMI, Save, Chart, History
       ═══════════════════════════════════════════════ */
    function calcBMI(w, h) {
        if (!w || !h) return 0;
        return w / ((h / 100) * (h / 100));
    }

    function updateBMI(weight) {
        var h = BZCALO.height_cm;
        var w = weight || BZCALO.weight_kg;
        if (!w || !h) return;
        var bmi = calcBMI(w, h);
        $('#bzcalo-bmi-val').text(bmi.toFixed(1));
        $('#bzcalo-w-current').text(w);

        var status = '';
        var color = '';
        if (bmi < 18.5) { status = 'Thiếu cân'; color = '#3b82f6'; }
        else if (bmi < 25) { status = 'Bình thường ✅'; color = '#10b981'; }
        else if (bmi < 30) { status = 'Thừa cân'; color = '#f59e0b'; }
        else { status = 'Béo phì'; color = '#ef4444'; }
        $('#bzcalo-bmi-status').text(status);
        $('#bzcalo-bmi-circle').css('border', '3px solid ' + color);

        // Position pointer on BMI scale (range 15–40)
        var pctPos = Math.max(0, Math.min(100, (bmi - 15) / 25 * 100));
        $('#bzcalo-bmi-pointer').css('left', pctPos + '%');
    }

    function loadWeightData() {
        updateBMI();
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_get_weight_history',
            nonce: BZCALO.nonce,
            days: 30
        }, function(res) {
            if (!res.success) return;
            renderWeightChart(res.data);
            renderWeightList(res.data);
            if (res.data.length) {
                var latest = res.data[res.data.length - 1];
                updateBMI(parseFloat(latest.weight_kg));
            }
        });
    }

    function renderWeightChart(data) {
        if (!data.length) {
            $('#bzcalo-weight-chart').html('<p class="bzcalo-empty">Chưa có dữ liệu cân nặng</p>');
            return;
        }
        var weights = data.map(function(d) { return parseFloat(d.weight_kg); });
        var minW = Math.min.apply(null, weights) - 1;
        var maxW = Math.max.apply(null, weights) + 1;
        var range = maxW - minW || 1;
        var svgW = 300, svgH = 140, padY = 15;

        var points = [];
        var dots = '';
        var labels = '';
        var step = data.length > 1 ? (svgW - 20) / (data.length - 1) : 0;

        $.each(data, function(i, d) {
            var x = 10 + i * step;
            var y = padY + (svgH - 2 * padY) * (1 - (parseFloat(d.weight_kg) - minW) / range);
            points.push(x + ',' + y);
            dots += '<circle cx="' + x + '" cy="' + y + '" r="3.5" fill="#6366f1"/>';
            dots += '<text x="' + x + '" y="' + (y - 8) + '" text-anchor="middle" font-size="9" fill="#6b7280">' + parseFloat(d.weight_kg).toFixed(1) + '</text>';
        });

        var svg = '<svg viewBox="0 0 ' + svgW + ' ' + svgH + '" class="bzcalo-chart-svg">'
            + '<polyline points="' + points.join(' ') + '" fill="none" stroke="#6366f1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>'
            + dots + '</svg>';

        var labelHtml = '<div class="bzcalo-chart-labels">';
        $.each(data, function(i, d) {
            if (data.length <= 10 || i % Math.ceil(data.length / 7) === 0 || i === data.length - 1) {
                labelHtml += '<span>' + d.stat_date.substr(5) + '</span>';
            }
        });
        labelHtml += '</div>';

        $('#bzcalo-weight-chart').html(svg + labelHtml);
    }

    function renderWeightList(data) {
        if (!data.length) {
            $('#bzcalo-weight-list').html('<p class="bzcalo-empty">Chưa ghi cân nặng</p>');
            return;
        }
        var html = '';
        var reversed = data.slice().reverse();
        $.each(reversed, function(i, d) {
            var bmi = calcBMI(parseFloat(d.weight_kg), BZCALO.height_cm);
            html += '<div class="bzcalo-meal-item">'
                + '<div class="bzcalo-meal-icon">⚖️</div>'
                + '<div class="bzcalo-meal-info">'
                + '<div class="bzcalo-meal-desc">' + parseFloat(d.weight_kg).toFixed(1) + ' kg</div>'
                + '<div class="bzcalo-meal-meta">' + d.stat_date + (bmi ? ' · BMI ' + bmi.toFixed(1) : '') + (d.note ? ' · ' + escHtml(d.note) : '') + '</div>'
                + '</div>'
                + '<button class="bzcalo-w-del" data-date="' + d.stat_date + '">🗑️</button>'
                + '</div>';
        });
        $('#bzcalo-weight-list').html(html);
    }

    // Save weight
    $app.on('click', '#bzcalo-w-save', function() {
        var $btn = $(this);
        var weight = parseFloat($('#bzcalo-w-input').val());
        var date = $('#bzcalo-w-date').val();
        var note = $('#bzcalo-w-note').val();

        if (!weight || weight < 20 || weight > 300) {
            showWeightStatus('Vui lòng nhập cân nặng hợp lệ (20-300 kg)', 'error');
            return;
        }

        $btn.prop('disabled', true).html('<span class="bzcalo-loading"></span> Đang lưu...');
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_log_weight',
            nonce: BZCALO.nonce,
            weight_kg: weight,
            date: date,
            note: note
        }, function(res) {
            $btn.prop('disabled', false).html('💾 Lưu cân nặng');
            if (res.success) {
                showWeightStatus('✅ Đã lưu cân nặng!', 'success');
                BZCALO.weight_kg = weight;
                loadWeightData();
            } else {
                showWeightStatus(res.data.message || 'Lỗi lưu', 'error');
            }
        });
    });

    // Delete weight entry
    $app.on('click', '.bzcalo-w-del', function() {
        var date = $(this).data('date');
        if (!confirm('Xóa cân nặng ngày ' + date + '?')) return;
        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_delete_weight',
            nonce: BZCALO.nonce,
            date: date
        }, function(res) {
            if (res.success) loadWeightData();
        });
    });

    function showWeightStatus(msg, type) {
        $('#bzcalo-w-status').text(msg).removeClass('success error').addClass(type).show();
        setTimeout(function() { $('#bzcalo-w-status').fadeOut(); }, 4000);
    }

    /* ═══════════════════════════════════════════════
       Profile — Save
       ═══════════════════════════════════════════════ */
    $('#bzcalo-profile-save').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="bzcalo-loading"></span> Đang lưu...');

        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_save_profile',
            nonce: BZCALO.nonce,
            full_name: $('#bzcalo-p-name').val(),
            gender: $('#bzcalo-p-gender').val(),
            dob: $('#bzcalo-p-dob').val(),
            height_cm: $('#bzcalo-p-height').val(),
            weight_kg: $('#bzcalo-p-weight').val(),
            goal: $('#bzcalo-p-goal').val(),
            activity_level: $('#bzcalo-p-activity').val(),
            target_weight: $('#bzcalo-p-target-w').val(),
            allergies: $('#bzcalo-p-allergies').val()
        }, function(res) {
            $btn.prop('disabled', false).html('💾 Lưu hồ sơ');
            if (res.success) {
                BZCALO.target = res.data.daily_calo_target || BZCALO.target;
                $('#bzcalo-profile-status').text('Mục tiêu: ' + BZCALO.target + ' kcal/ngày');
                $('#bzcalo-dialog-target').html('🎯 Mục tiêu calo hàng ngày: <strong>' + BZCALO.target + ' kcal/ngày</strong>');
                $('#bzcalo-dialog-overlay').fadeIn(200);
            } else {
                $('#bzcalo-profile-status').text('❌ ' + (res.data.message || 'Lỗi'));
            }
        });
    });

    /* ═══════════════════════════════════════════════
       Dialog — Close
       ═══════════════════════════════════════════════ */
    $app.on('click', '#bzcalo-dialog-close', function() {
        $('#bzcalo-dialog-overlay').fadeOut(200);
    });
    $app.on('click', '#bzcalo-dialog-overlay', function(e) {
        if (e.target === this) $('#bzcalo-dialog-overlay').fadeOut(200);
    });

    /* ═══════════════════════════════════════════════
       Helpers
       ═══════════════════════════════════════════════ */
    function showStatus(msg, type) {
        $('#bzcalo-pub-status').text(msg).removeClass('success error').addClass(type).show();
        setTimeout(function() { $('#bzcalo-pub-status').fadeOut(); }, 4000);
    }

    function escHtml(str) {
        return $('<div/>').text(str).html();
    }

    /* ═══════════════════════════════════════════════
       Init — load dashboard data on page load
       ═══════════════════════════════════════════════ */
    var activeTab = $('.bzcalo-nav-item.active').data('tab');
    if (activeTab === 'dashboard' || !activeTab) loadToday();
    if (activeTab === 'history') { loadChart7d(); loadHistory(); }
    if (activeTab === 'weight') loadWeightData();

})(jQuery);
