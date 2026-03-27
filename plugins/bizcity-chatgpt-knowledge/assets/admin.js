/**
 * ChatGPT Knowledge — Admin JS
 * Handles Ask page interactions, bookmarks, and copy.
 */
jQuery(function($) {
    'use strict';

    /* ── Ask Button ── */
    $(document).on('click', '#bzck-ask-btn', function() {
        var question = $('#bzck-question').val().trim();
        if (!question) return;

        var $btn = $(this);
        var $status = $('#bzck-ask-status');
        var $area = $('#bzck-answer-area');

        $btn.prop('disabled', true).text('⏳ Đang hỏi...');
        $status.text('ChatGPT đang suy nghĩ...');
        $area.hide();

        $.post(BZCK.ajax_url, {
            action: 'bzck_ask',
            nonce: BZCK.nonce,
            question: question
        }, function(res) {
            $btn.prop('disabled', false).text('🧠 Hỏi ChatGPT');

            if (res.success) {
                $status.text('');
                $('#bzck-answer-content').html(res.data.answer);
                $('#bzck-answer-model').text('Model: ' + (res.data.model || 'unknown'));
                $area.show();
                // Store for bookmark
                $area.data('question', question);
                $area.data('model', res.data.model || '');
            } else {
                $status.text('❌ ' + (res.data?.message || 'Lỗi không xác định'));
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('🧠 Hỏi ChatGPT');
            $status.text('❌ Lỗi kết nối');
        });
    });

    /* ── Ask Link (from topics / history) ── */
    $(document).on('click', '.bzck-ask-link', function(e) {
        e.preventDefault();
        var question = $(this).data('question');
        // Navigate to Ask page if not on it
        if ($('#bzck-question').length) {
            $('#bzck-question').val(question);
            $('#bzck-ask-btn').click();
        } else {
            var askUrl = window.location.href.replace(/page=[^&]+/, 'page=' + BZCK.slug + '-ask');
            window.location.href = askUrl + '&q=' + encodeURIComponent(question);
        }
    });

    /* ── Bookmark ── */
    $(document).on('click', '.bzck-bookmark-btn', function() {
        var $area = $('#bzck-answer-area');
        $.post(BZCK.ajax_url, {
            action: 'bzck_save_bookmark',
            nonce: BZCK.nonce,
            query: $area.data('question'),
            answer: $('#bzck-answer-content').html(),
            model: $area.data('model')
        }, function(res) {
            if (res.success) {
                alert('✅ Đã bookmark!');
            }
        });
    });

    /* ── Copy ── */
    $(document).on('click', '.bzck-copy-btn', function() {
        var text = $('#bzck-answer-content').text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                alert('📋 Đã copy!');
            });
        }
    });

    /* ── Delete Bookmark ── */
    $(document).on('click', '.bzck-del-bookmark', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        if (!confirm('Xóa bookmark này?')) return;

        $.post(BZCK.ajax_url, {
            action: 'bzck_delete_bookmark',
            nonce: BZCK.nonce,
            id: id
        }, function(res) {
            if (res.success) {
                $btn.closest('.postbox').fadeOut(300, function() { $(this).remove(); });
            }
        });
    });

    /* ── Auto-fill from URL param ── */
    var urlParams = new URLSearchParams(window.location.search);
    var q = urlParams.get('q');
    if (q && $('#bzck-question').length) {
        $('#bzck-question').val(q);
        setTimeout(function() { $('#bzck-ask-btn').click(); }, 300);
    }
});
