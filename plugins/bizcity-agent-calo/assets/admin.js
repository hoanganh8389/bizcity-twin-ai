/**
 * BizCity Calo — Admin JS
 * Handles: admin meal logging, photo upload, AI analysis, delete meals
 */
(function($) {
    'use strict';

    if (typeof BZCALO === 'undefined') return;

    /* ═══════════════════════════════════════════════
       Admin — Photo Upload (Log Meal page)
       ═══════════════════════════════════════════════ */
    var adminPhotoUrl = '';

    $('#bzcalo-photo-zone').on('click', function() {
        $('#bzcalo-photo-input').trigger('click');
    });

    $('#bzcalo-photo-input').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(e) {
            $('#bzcalo-photo-img').attr('src', e.target.result);
            $('#bzcalo-photo-preview').show();
        };
        reader.readAsDataURL(file);

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
                    adminPhotoUrl = res.data.url;
                    $('#bzcalo-photo-url').val(res.data.url);
                }
            }
        });
    });

    /* ═══════════════════════════════════════════════
       Admin — Meal Type Radio
       ═══════════════════════════════════════════════ */
    $('.bzcalo-meal-type-btn input').on('change', function() {
        $('.bzcalo-meal-type-btn').css({ borderColor: '#e5e7eb', background: 'transparent' });
        $(this).closest('.bzcalo-meal-type-btn').css({ borderColor: '#6366f1', background: '#e0e7ff' });
    });

    /* ═══════════════════════════════════════════════
       Admin — AI Analyze
       ═══════════════════════════════════════════════ */
    var adminAiData = null;

    $('#bzcalo-btn-analyze').on('click', function() {
        var $btn = $(this);
        var desc = $('#bzcalo-desc').val().trim();
        var photoUrl = adminPhotoUrl;

        if (!desc && !photoUrl) {
            alert('Vui lòng mô tả hoặc chụp ảnh bữa ăn');
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang phân tích...');

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
            $btn.prop('disabled', false).text('🤖 AI Phân tích');
            if (!res.success) {
                alert(res.data.message || 'Lỗi phân tích');
                return;
            }

            adminAiData = res.data;
            renderAdminAiResult(res.data);
        }).fail(function() {
            $btn.prop('disabled', false).text('🤖 AI Phân tích');
            alert('Lỗi kết nối');
        });
    });

    function renderAdminAiResult(data) {
        var $result = $('#bzcalo-ai-result');
        var $items = $('#bzcalo-ai-items');
        $items.empty();

        if (data.items && data.items.length) {
            $.each(data.items, function(i, item) {
                $items.append(
                    '<div class="bzcalo-ai-item-row">'
                    + '<span>' + escHtml(item.name || item.food_name || '') + '</span>'
                    + '<span>' + Math.round(item.calories || 0) + ' kcal</span>'
                    + '</div>'
                );
            });
        }

        var total = data.total_calories || 0;
        $('#bzcalo-ai-total').html('Tổng: <strong>' + Math.round(total) + ' kcal</strong> · P' + Math.round(data.total_protein || 0) + ' C' + Math.round(data.total_carbs || 0) + ' F' + Math.round(data.total_fat || 0));
        $('#bzcalo-ai-note').text(data.note || '');
        $result.show();
    }

    /* ═══════════════════════════════════════════════
       Admin — Save Meal
       ═══════════════════════════════════════════════ */
    $('#bzcalo-btn-save').on('click', function() {
        var $btn = $(this);
        var desc = $('#bzcalo-desc').val().trim();
        var mealType = $('input[name="meal_type"]:checked').val() || 'lunch';

        if (!desc && !adminPhotoUrl && !adminAiData) {
            alert('Vui lòng mô tả hoặc chụp ảnh bữa ăn');
            return;
        }

        $btn.prop('disabled', true).text('⏳ Đang lưu...');

        var postData = {
            action: 'bzcalo_log_meal',
            nonce: BZCALO.nonce,
            description: desc,
            meal_type: mealType,
            photo_url: adminPhotoUrl
        };

        if (adminAiData) {
            postData.total_calories = adminAiData.total_calories || 0;
            postData.total_protein = adminAiData.total_protein || 0;
            postData.total_carbs = adminAiData.total_carbs || 0;
            postData.total_fat = adminAiData.total_fat || 0;
            if (adminAiData.items) postData.items_json = JSON.stringify(adminAiData.items);
        }

        $.post(BZCALO.ajax_url, postData, function(res) {
            $btn.prop('disabled', false).text('💾 Lưu bữa ăn');
            if (res.success) {
                alert('✅ Đã lưu bữa ăn!');
                location.reload();
            } else {
                alert(res.data.message || 'Lỗi lưu');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('💾 Lưu bữa ăn');
            alert('Lỗi kết nối');
        });
    });

    /* ═══════════════════════════════════════════════
       Admin — Delete Meal
       ═══════════════════════════════════════════════ */
    $('.bzcalo-del-meal').on('click', function() {
        var id = $(this).data('id');
        if (!confirm('Xóa bữa ăn #' + id + '?')) return;

        $.post(BZCALO.ajax_url, {
            action: 'bzcalo_delete_meal',
            nonce: BZCALO.nonce,
            meal_id: id
        }, function(res) {
            if (res.success) location.reload();
            else alert(res.data.message || 'Lỗi xóa');
        });
    });

    /* ═══════════════════════════════════════════════
       Helpers
       ═══════════════════════════════════════════════ */
    function escHtml(str) {
        return $('<div/>').text(str).html();
    }

})(jQuery);
