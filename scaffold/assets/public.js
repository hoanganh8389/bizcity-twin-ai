/**
 * BizCity {Name} — Public JS
 * Handles topic selection, item picking, AI request, push result.
 */
(function($) {
    'use strict';

    var PUB = window.BZ_PREFIX_PUB || {}; // Replace BZ_PREFIX_PUB with actual var
    var selected = [];
    var maxItems = parseInt(PUB.items_count) || 3;
    var currentTopic = '';

    /* ── Init ── */
    $(document).ready(function() {
        initTopics();
        initItems();
        initAI();
    });

    /* ── Topics ── */
    function initTopics() {
        var $container = $('#bz_prefix_-topics'); // Replace with actual prefix
        var dataEl = document.getElementById('bz_prefix_-topics-data');
        if (!dataEl) return;

        try {
            var topics = JSON.parse(dataEl.textContent);
        } catch(e) { return; }

        topics.forEach(function(t) {
            var $btn = $('<button class="bz_prefix_-topic-btn" data-value="' + t.value + '">')
                .html(t.icon + ' ' + t.label);
            $container.append($btn);
        });

        $container.on('click', '.bz_prefix_-topic-btn', function() {
            $(this).addClass('active').siblings().removeClass('active');
            currentTopic = $(this).data('value');
            showQuestions(topics, currentTopic);
        });
    }

    function showQuestions(topics, value) {
        var $q = $('#bz_prefix_-questions').empty().show();
        var topic = topics.find(function(t) { return t.value === value; });
        if (!topic || !topic.questions) return $q.hide();

        topic.questions.forEach(function(q) {
            $q.append('<button class="bz_prefix_-question-btn">' + q + '</button>');
        });
    }

    /* ── Item Selection ── */
    function initItems() {
        $(document).on('click', '.bz_prefix_-item', function() {
            var slug = $(this).data('slug');
            var idx = selected.indexOf(slug);

            if (idx > -1) {
                selected.splice(idx, 1);
                $(this).removeClass('selected');
            } else if (selected.length < maxItems) {
                selected.push(slug);
                $(this).addClass('selected');
            }

            if (selected.length === maxItems) {
                onSelectionComplete();
            }
        });
    }

    function onSelectionComplete() {
        // Show result panel
        $('#bz_prefix_-result').show();
        $('#bz_prefix_-ai').show();

        // Get detail for each selected item
        var promises = selected.map(function(slug) {
            return $.post(PUB.ajax_url, {
                action: 'bz_prefix__get_detail', // Replace with actual AJAX action
                nonce: PUB.nonce,
                slug: slug
            });
        });

        $.when.apply($, promises).then(function() {
            var results = promises.length === 1 ? [arguments] : Array.from(arguments);
            displayResults(results);
        });
    }

    function displayResults(results) {
        var $content = $('#bz_prefix_-result-content').empty();
        results.forEach(function(r) {
            var data = r[0] || r;
            if (data.success && data.data) {
                var item = data.data;
                $content.append(
                    '<div class="bz_prefix_-result-item">' +
                    '<h4>' + (item.name_vi || item.slug) + '</h4>' +
                    (item.image_url ? '<img src="' + item.image_url + '" />' : '') +
                    '</div>'
                );
            }
        });

        // Auto-save result
        saveResult();
    }

    /* ── Save Result ── */
    function saveResult() {
        $.post(PUB.ajax_url, {
            action: 'bz_prefix__save_result',
            nonce: PUB.nonce,
            topic: currentTopic,
            result: JSON.stringify({ items: selected }),
            token: PUB.token || ''
        });
    }

    /* ── AI Interpretation ── */
    function initAI() {
        $(document).on('click', '#bz_prefix_-ai-btn', function() {
            var $btn = $(this).prop('disabled', true).text('Đang phân tích...');
            var $content = $('#bz_prefix_-ai-content').html('<div class="bz_prefix_-loading">Đang chờ AI</div>');

            $.post(PUB.ajax_url, {
                action: 'bz_prefix__ai_interpret',
                nonce: PUB.nonce,
                topic: currentTopic,
                data: JSON.stringify({ items: selected }),
                prompt: 'Hãy phân tích chi tiết kết quả.'
            }).done(function(res) {
                if (res.success && res.data.ai_reply) {
                    $content.html('<div class="bz_prefix_-ai-text">' + res.data.ai_reply + '</div>');

                    // Push to chat if opened from chat context
                    if (PUB.has_chat_ctx == 1 && PUB.token) {
                        pushToChat(res.data.ai_reply);
                    }
                } else {
                    $content.html('<p>❌ ' + (res.data ? res.data.message : 'Lỗi') + '</p>');
                }
            }).fail(function() {
                $content.html('<p>❌ Lỗi kết nối.</p>');
            }).always(function() {
                $btn.prop('disabled', false).text('Yêu cầu phân tích AI');
            });
        });
    }

    /* ── Push to Chat ── */
    function pushToChat(aiReply) {
        $.post(PUB.ajax_url, {
            action: 'bz_prefix__push_result',
            nonce: PUB.nonce,
            token: PUB.token,
            ai_reply: aiReply
        });
    }

})(jQuery);
