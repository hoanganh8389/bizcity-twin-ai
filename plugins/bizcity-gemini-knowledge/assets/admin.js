/**
 * Gemini Knowledge — Admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // ── Ask page: Send question to Gemini ──
        var $askBtn = $('#bzgk-ask-btn');
        var $question = $('#bzgk-question');
        var $status = $('#bzgk-ask-status');
        var $answerArea = $('#bzgk-answer-area');
        var $answerContent = $('#bzgk-answer-content');
        var $answerModel = $('#bzgk-answer-model');
        var lastAnswer = '';
        var lastModel = '';
        var lastQuery = '';

        $askBtn.on('click', function() {
            var q = $question.val().trim();
            if (!q) return;
            askGemini(q);
        });

        $question.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $askBtn.trigger('click');
            }
        });

        // Click-to-ask links
        $(document).on('click', '.bzgk-ask-link', function(e) {
            e.preventDefault();
            var q = $(this).data('question');
            if ($question.length) {
                $question.val(q);
                // Navigate to ask page if not on it
                var askPage = window.location.href.indexOf('-ask') !== -1;
                if (askPage) {
                    askGemini(q);
                } else {
                    window.location.href = 'admin.php?page=' + BZGK.slug + '-ask&q=' + encodeURIComponent(q);
                }
            }
        });

        // Pre-fill from URL param
        var urlQ = new URLSearchParams(window.location.search).get('q');
        if (urlQ && $question.length) {
            $question.val(urlQ);
            askGemini(urlQ);
        }

        function askGemini(question) {
            $askBtn.prop('disabled', true);
            $status.text('🧠 Đang hỏi Gemini...');
            $answerArea.hide();
            lastQuery = question;

            $.post(BZGK.ajax_url, {
                action: 'bzgk_ask',
                nonce: BZGK.nonce,
                question: question
            }, function(resp) {
                $askBtn.prop('disabled', false);
                $status.text('');

                if (resp.success) {
                    lastAnswer = resp.data.answer;
                    lastModel = resp.data.model || '';
                    $answerContent.html(markdownToHtml(resp.data.answer));
                    $answerModel.text('Model: ' + lastModel);
                    $answerArea.show();
                } else {
                    $status.text('❌ ' + (resp.data.message || 'Lỗi'));
                }
            }).fail(function() {
                $askBtn.prop('disabled', false);
                $status.text('❌ Lỗi kết nối');
            });
        }

        // ── Bookmark ──
        $(document).on('click', '.bzgk-bookmark-btn', function() {
            if (!lastQuery || !lastAnswer) return;
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(BZGK.ajax_url, {
                action: 'bzgk_save_bookmark',
                nonce: BZGK.nonce,
                query: lastQuery,
                answer: lastAnswer,
                model: lastModel
            }, function(resp) {
                $btn.prop('disabled', false);
                if (resp.success) {
                    $btn.text('✅ Đã lưu!');
                    setTimeout(function() { $btn.text('🔖 Lưu'); }, 2000);
                }
            });
        });

        // ── Copy ──
        $(document).on('click', '.bzgk-copy-btn', function() {
            if (!lastAnswer) return;
            navigator.clipboard.writeText(lastAnswer).then(function() {
                var $btn = $('.bzgk-copy-btn');
                $btn.text('✅ Đã copy!');
                setTimeout(function() { $btn.text('📋 Copy'); }, 2000);
            });
        });

        // ── Delete bookmark ──
        $(document).on('click', '.bzgk-del-bookmark', function() {
            if (!confirm('Xóa bookmark này?')) return;
            var $btn = $(this);
            var id = $btn.data('id');
            $.post(BZGK.ajax_url, {
                action: 'bzgk_delete_bookmark',
                nonce: BZGK.nonce,
                id: id
            }, function(resp) {
                if (resp.success) {
                    $btn.closest('.postbox').slideUp(200, function() { $(this).remove(); });
                }
            });
        });

        /**
         * Simple markdown to HTML (same as public.js)
         */
        function markdownToHtml(text) {
            if (!text) return '';
            var html = text;
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>');
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            html = html.replace(/^\- (.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
            html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
            html = html.replace(/\n\n/g, '</p><p>');
            html = '<p>' + html + '</p>';
            html = html.replace(/<p><\/p>/g, '');
            html = html.replace(/<p>(<h[1-3]>)/g, '$1');
            html = html.replace(/(<\/h[1-3]>)<\/p>/g, '$1');
            html = html.replace(/<p>(<ul>)/g, '$1');
            html = html.replace(/(<\/ul>)<\/p>/g, '$1');
            html = html.replace(/<p>(<pre>)/g, '$1');
            html = html.replace(/(<\/pre>)<\/p>/g, '$1');
            return html;
        }
    });
})(jQuery);
