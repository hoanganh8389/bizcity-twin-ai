/**
 * Gemini Knowledge — Public JS
 */
(function($) {
    'use strict';

    var BZGK = window.BZGK_PUB || {};

    $(document).ready(function() {
        var $input     = $('#bzgk-input');
        var $sendBtn   = $('#bzgk-send-btn');
        var $answerArea = $('#bzgk-answer-area');
        var $answerContent = $('#bzgk-answer-content');
        var $answerMeta = $('#bzgk-answer-meta');
        var $loading   = $('#bzgk-loading');
        var $topics    = $('#bzgk-topics');

        // Send question
        $sendBtn.on('click', function() {
            var question = $input.val().trim();
            if (!question) return;
            sendQuestion(question);
        });

        // Enter to send (Shift+Enter for newline)
        $input.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $sendBtn.trigger('click');
            }
        });

        // Topic chip click → show suggested questions
        $('.bzgk-topic-chip').on('click', function() {
            var questions = $(this).data('questions');
            if (questions && questions.length) {
                $input.val(questions[0]).focus();
            }
        });

        function sendQuestion(question) {
            $loading.show();
            $answerArea.hide();
            $sendBtn.prop('disabled', true);
            $topics.hide();

            $.post(BZGK.ajax_url, {
                action: 'bzgk_public_ask',
                nonce: BZGK.nonce,
                question: question
            }, function(resp) {
                $loading.hide();
                $sendBtn.prop('disabled', false);

                if (resp.success) {
                    // Simple markdown-to-html conversion
                    var html = markdownToHtml(resp.data.answer);
                    $answerContent.html(html);
                    $answerMeta.text('Model: ' + (resp.data.model || 'Gemini'));
                    $answerArea.show();
                } else {
                    $answerContent.html('<p style="color:#ef4444">' + (resp.data.message || 'Lỗi') + '</p>');
                    $answerArea.show();
                }
            }).fail(function() {
                $loading.hide();
                $sendBtn.prop('disabled', false);
                $answerContent.html('<p style="color:#ef4444">Lỗi kết nối. Vui lòng thử lại.</p>');
                $answerArea.show();
            });
        }

        /**
         * Simple markdown to HTML converter.
         */
        function markdownToHtml(text) {
            if (!text) return '';
            var html = text;
            // Code blocks
            html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>');
            // Inline code
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            // Headers
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            // Bold/italic
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            // Lists
            html = html.replace(/^\- (.+)$/gm, '<li>$1</li>');
            html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
            html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
            // Links
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
            // Paragraphs
            html = html.replace(/\n\n/g, '</p><p>');
            html = '<p>' + html + '</p>';
            // Clean up
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
