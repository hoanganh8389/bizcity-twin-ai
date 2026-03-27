/**
 * ChatGPT Knowledge — Public JS
 * Handles shortcode widget interactions.
 */
jQuery(function($) {
    'use strict';

    var $app = $('#bzck-app');
    if (!$app.length) return;

    var $input   = $('#bzck-input');
    var $sendBtn = $('#bzck-send-btn');
    var $answer  = $('#bzck-answer-area');
    var $content = $('#bzck-answer-content');
    var $meta    = $('#bzck-answer-meta');
    var $loading = $('#bzck-loading');
    var $topics  = $('#bzck-topics');

    function sendQuestion(question) {
        if (!question) return;

        $loading.show();
        $answer.hide();
        $topics.hide();

        $.post(BZCK_PUB.ajax_url, {
            action: 'bzck_public_ask',
            nonce: BZCK_PUB.nonce,
            question: question
        }, function(res) {
            $loading.hide();

            if (res.success) {
                $content.html(res.data.answer);
                $meta.text('Model: ' + (res.data.model || 'ChatGPT') + ' | Powered by OpenAI');
                $answer.show();
            } else {
                $content.text('❌ ' + (res.data?.message || 'Lỗi'));
                $answer.show();
            }
        }).fail(function() {
            $loading.hide();
            $content.text('❌ Lỗi kết nối');
            $answer.show();
        });
    }

    /* Send button */
    $sendBtn.on('click', function() {
        sendQuestion($input.val().trim());
        $input.val('');
    });

    /* Enter to send */
    $input.on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $sendBtn.click();
        }
    });

    /* Topic chips */
    $(document).on('click', '.bzck-topic-chip', function() {
        var questions = $(this).data('questions');
        if (questions && questions.length) {
            var q = questions[Math.floor(Math.random() * questions.length)];
            $input.val(q);
            sendQuestion(q);
        }
    });
});
