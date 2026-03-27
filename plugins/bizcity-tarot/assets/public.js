/**
 * BizCity Tarot – Public JS
 * Xáo bài, trải bài, lật bài, hiển thị ý nghĩa
 */
(function ($) {
    'use strict';

    /* ============================================================
       STATE
       ============================================================ */
    var state = {
        phase: 'idle',      // idle | topic | question | ready | shuffle | spread | selecting | done
        topic: '',
        question: '',
        cardsToPick: BCT_PUB.cards_to_pick || 3,
        showReversed: BCT_PUB.show_reversed || 1,
        selectedIds: [],
        selectedData: [],
        shuffled: [],
        lastReadingId: 0,
        stepNum: 1,
        stepTexts: [
            'Hãy chọn chủ đề Tarot của bạn',
            'Hãy chọn câu hỏi bạn muốn bói',
            'Nhấn "Xào bài" để xáo bộ bài',
            'Tập trung tâm trí, chọn ' + (BCT_PUB.cards_to_pick || 3) + ' lá bài',
            'Nhấn "Tiết lộ ý nghĩa" để xem kết quả',
            'Hoàn thành! Bạn đã nhận được thông điệp.'
        ]
    };

    /* ============================================================
       SET CARDS COUNT
       ============================================================ */
    window.bctSetCardsCount = function (n) {
        n = parseInt(n);
        if (!n || n < 1 || n > 10) return;
        if (state.phase !== 'idle') return; // locked once shuffle starts

        state.cardsToPick      = n;
        state.stepTexts[3]     = 'Tập trung tâm trí, chọn ' + n + ' lá bài';

        // Update big header number
        $('.bct-header-count').text(n);

        // Update active pill
        $('.bct-count-btn').removeClass('is-active');
        $('.bct-count-btn[data-count="' + n + '"]').addClass('is-active');
    };

    /* ============================================================
       TOPIC → QUESTION
       ============================================================ */
    window.bctUpdateQuestions = function () {
        var topicVal  = $('#bct-topic').val();
        var $question = $('#bct-question');
        var topics    = JSON.parse($('#bct-topics-data').text() || '[]');

        state.topic = topicVal;
        $question.html('<option value="">💬 Chọn câu hỏi</option>');
        $('#bct-btn-shuffle').prop('disabled', true);

        if (!topicVal) {
            bctSetStep(1, state.stepTexts[0]);
            return;
        }

        bctSetStep(2, state.stepTexts[1]);

        // Find matching topic
        for (var i = 0; i < topics.length; i++) {
            if (topics[i].value === topicVal) {
                var qs = topics[i].questions || [];
                for (var j = 0; j < qs.length; j++) {
                    var opt = $('<option>').val(qs[j]).text(qs[j]);
                    $question.append(opt);
                }
                break;
            }
        }

        // Enable shuffle when question selected
        $question.off('change.bct').on('change.bct', function () {
            state.question = $(this).val();
            if (state.question) {
                $('#bct-btn-shuffle').prop('disabled', false);
                bctSetStep(3, state.stepTexts[2]);
            } else {
                $('#bct-btn-shuffle').prop('disabled', true);
                bctSetStep(2, state.stepTexts[1]);
            }
        });
    };

    /* ============================================================
       SHUFFLE & DEAL (FAN SPREAD)
       ============================================================ */
    window.bctShuffleAndDeal = function () {
        state.phase = 'shuffle';
        bctSetStep(4, state.stepTexts[3]);

        // Lock card count picker once shuffle starts
        $('.bct-count-btn').prop('disabled', true).addClass('is-disabled');

        var $pack  = $('#bct-pack');
        var $cards = $pack.find('.bct-card');
        var total  = $cards.length;
        var arr    = [];

        $cards.each(function () { arr.push(this); });
        bctFisherYates(arr);

        // Quick stack animation
        $pack.css({ height: '', transition: 'none' });
        $cards.addClass('no-pointer');

        // Step 1: stack all to center (ripple)
        arr.forEach(function (el, i) {
            setTimeout(function () {
                $(el).css({
                    transition: 'transform .25s ease',
                    transform: 'translateX(-50%) translateY(0px)',
                    zIndex: i
                });
            }, i * 8);
        });

        // Step 2: fan them out after delay
        setTimeout(function () {
            bctFanSpread(arr);
        }, Math.min(arr.length * 8 + 400, 900));
    };

    function bctFanSpread(arr) {
        var total   = arr.length;
        var packW   = $('#bct-pack').width() || 400;
        var spread  = Math.min(packW - 60, 360);
        var step    = spread / (total - 1);
        var startX  = -spread / 2;
        var maxAngle = 30; // degrees total arc
        var angleStep = maxAngle / (total - 1);
        var startAngle = -maxAngle / 2;
        var cardH   = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--bct-card-h')) || 200;

        arr.forEach(function (el, i) {
            var x        = startX + step * i;
            var angle    = startAngle + angleStep * i;
            var rad      = angle * Math.PI / 180;
            var yOffset  = Math.abs(Math.sin(rad)) * 30;

            $(el).css({
                '--fan-x':   'calc(-50% + ' + x + 'px)',
                '--fan-y':   yOffset + 'px',
                '--fan-r':   angle + 'deg',
                transition:  'transform .6s cubic-bezier(.4,2,.6,1)',
                transform:   'translateX(calc(-50% + ' + x + 'px)) translateY(' + yOffset + 'px) rotate(' + angle + 'deg)',
                zIndex:      i
            }).removeClass('no-pointer');
        });

        setTimeout(function () {
            state.phase     = 'spread';
            state.shuffled  = arr;
            state.selectedIds  = [];
            state.selectedData = [];
            $('#bct-pack').addClass('is-spread');

            // Set pack height for fan
            var newH = cardH + 80;
            $('#bct-pack').css('height', newH + 'px');

            // Bind click to select
            $('#bct-pack').off('click.bct').on('click.bct', '.bct-card:not(.is-selected):not(.no-pointer)', function () {
                bctSelectCard($(this));
            });
        }, 700);
    }

    /* ============================================================
       SELECT CARD
       ============================================================ */
    function bctSelectCard($card) {
        if (state.selectedIds.length >= state.cardsToPick) return;

        var id    = $card.data('id');
        var nameEn = $card.data('name');
        var nameVi = $card.data('name-vi');
        var imgUrl = $card.data('img');
        var isRev  = state.showReversed && Math.random() < 0.3 ? 1 : 0;

        state.selectedIds.push(id);
        state.selectedData.push({ id: id, name_en: nameEn, name_vi: nameVi, image_url: imgUrl, is_reversed: isRev });

        $card.addClass('is-selected');

        // Add to selected zone
        bctAddToSelectedZone(id, nameEn, nameVi, imgUrl, isRev, state.selectedIds.length);

        if (state.selectedIds.length >= state.cardsToPick) {
            state.phase = 'selecting';
            // Disable remaining
            $('#bct-pack .bct-card:not(.is-selected)').addClass('no-pointer');

            bctSetStep(5, state.stepTexts[4]);
            setTimeout(function () {
                $('#bct-reveal-wrap').show();
            }, 400);
        } else {
            var remaining = state.cardsToPick - state.selectedIds.length;
            bctSetStep(4, 'Chọn thêm <strong>' + remaining + '</strong> lá nữa…');
        }
    }

    function bctAddToSelectedZone(id, nameEn, nameVi, imgUrl, isRev, pos) {
        var labels = getCardLabels();
        var posLabel = labels[pos] || ('Lá ' + pos);

        var $zone = $('#bct-selected-zone').show();
        var $cards = $('#bct-selected-cards');
        var revClass = isRev ? 'is-reversed' : '';
        var img = imgUrl
            ? '<img src="' + imgUrl + '" alt="' + bctEsc(nameEn) + '">'
            : '<div class="bct-no-img-ph">✦</div>';

        $cards.append(
            '<div class="bct-sel-card ' + revClass + '">' +
                img +
                '<div class="bct-sel-card-pos">' + posLabel + '</div>' +
                '<div class="bct-sel-card-name">' + bctEsc(nameVi || nameEn) + '</div>' +
            '</div>'
        );
    }

    /* ============================================================
       REVEAL MEANING
       ============================================================ */
    window.bctRevealMeaning = function () {
        state.phase = 'done';
        bctSetStep(6, state.stepTexts[5]);
        $('#bct-reveal-wrap').hide();

        var $panel = $('#bct-meaning-panel').show();
        $('#bct-meaning-topic').text(state.topic);
        $('#bct-meaning-question').text(state.question);

        var $cardsWrap = $('#bct-meaning-cards').html(
            '<div style="text-align:center;padding:40px"><span class="bct-spinner"></span> Đang giải mã bài Tarot…</div>'
        );

        var promises = state.selectedData.map(function (card, idx) {
            return $.post(BCT_PUB.ajax_url, {
                action:      'bct_get_card_meaning',
                nonce:       BCT_PUB.nonce,
                card_id:     card.id,
                is_reversed: card.is_reversed
            });
        });

        $.when.apply($, promises).done(function () {
            var results = promises.length === 1 ? [arguments] : Array.from(arguments);
            $cardsWrap.html('');

            var labels = getCardLabels();

            results.forEach(function (res, idx) {
                var data = res[0];   // first argument = response JSON
                if (!data || !data.success) return;
                var d       = data.data;
                var pos     = idx + 1;
                var posLbl  = labels[pos] || ('Lá ' + pos);
                var revBadge = d.is_reversed ? '<span class="bct-reversed-badge">↕ Ngược</span>' : '';
                var revClass = d.is_reversed ? 'is-reversed' : '';

                var kws = '';
                if (d.keywords_vi) {
                    kws = d.keywords_vi.split(/[,،]+/).map(function (k) {
                        return '<span class="bct-kw-tag">' + bctEsc(k.trim()) + '</span>';
                    }).join('');
                }

                var img = d.image_url
                    ? '<img src="' + bctEsc(d.image_url) + '" alt="' + bctEsc(d.name_en) + '" loading="lazy">'
                    : '<div style="width:80px;height:133px;background:rgba(201,162,39,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:32px">✦</div>';

                $cardsWrap.append(
                    '<div class="bct-meaning-card" style="animation-delay:' + (idx * 0.15) + 's">' +
                        '<div class="bct-meaning-img ' + revClass + '">' + img +
                            '<div class="bct-meaning-img-label">' + bctEsc(posLbl) + '</div>' +
                        '</div>' +
                        '<div class="bct-meaning-text">' +
                            '<div class="bct-meaning-pos">' + bctEsc(posLbl) + '</div>' +
                            '<div class="bct-meaning-card-name">' +
                                bctEsc(d.name_vi || d.name_en) + revBadge +
                            '</div>' +
                            (kws ? '<div class="bct-meaning-keywords">' + kws + '</div>' : '') +
                            '<div class="bct-meaning-body">' + (d.meaning || '<em>Chưa có dữ liệu. Vào admin crawl dữ liệu từ learntarot.com.</em>') + '</div>' +
                        '</div>' +
                    '</div>'
                );
            });

            // Save reading
            bctSaveReading();

            // AI interpretation
            setTimeout(function () {
                bctAiInterpret();
            }, 600);

            // Scroll to panel
            $([document.documentElement, document.body]).animate({
                scrollTop: $panel.offset().top - 40
            }, 600);
        });
    };

    /* ============================================================
       AI INTERPRETATION
       ============================================================ */
    function bctAiInterpret() {
        var $panel   = $('#bct-ai-panel').show();
        var $content = $('#bct-ai-content');
        var $cta     = $('#bct-ai-cta');

        $content.html(
            '<div class="bct-ai-loading"><span class="bct-spinner"></span> AI đang luận giải bài Tarot của bạn…</div>'
        );
        $cta.hide();

        // Build cards data with position labels
        var labels   = getCardLabels();
        var cardsPayload = state.selectedData.map(function (c, idx) {
            var pos = idx + 1;
            return {
                id:             c.id,
                name_en:        c.name_en,
                name_vi:        c.name_vi,
                is_reversed:    c.is_reversed ? 1 : 0,
                keywords:       c.keywords_vi || c.keywords_en || '',
                position_label: labels[pos] || ('Lá ' + pos)
            };
        });

        $.post(BCT_PUB.ajax_url, {
            action:      'bct_ai_interpret',
            nonce:       BCT_PUB.nonce,
            topic:       state.topic,
            question:    state.question,
            cards_json:  JSON.stringify(cardsPayload),
            session_id:  bctSessionId(),
            bct_token:   BCT_PUB.bct_token || ''   // gửi token để server resolve user_id (kể cả guest)
        }, function (res) {
            if (res && res.success) {
                // Convert markdown bold **text** → <strong>text</strong> and newlines → <br>
                var html = bctEsc(res.data.reply)
                    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                    .replace(/\n/g, '<br>');
                $content.html('<div class="bct-ai-reply">' + html + '</div>');

                // ── Save AI reply back to the reading record ──
                if (state.lastReadingId) {
                    $.post(BCT_PUB.ajax_url, {
                        action:     'bct_update_reading_ai',
                        nonce:      BCT_PUB.nonce,
                        reading_id: state.lastReadingId,
                        ai_reply:   res.data.reply,
                        bct_token:  BCT_PUB.bct_token || ''
                    });
                }

                // ── Push result via chat message if page opened from bot link ──
                // Chỉ cần token có mặt; server sẽ validate token và dispatch đúng luồng
                if (BCT_PUB.bct_token) {
                    bctPushReadingResult(res.data.reply);
                }

                // If guest user, show upgrade CTA below the reply
                if (!BCT_PUB.is_logged) {
                    $cta.html(
                        '<div class="bct-ai-upgrade">' +
                        '<p class="bct-ai-upgrade-note">🌟 <strong>Tôi là AI theo từng cá nhân.</strong> Để luận giải chính xác hơn, tôi cần hồ sơ chiêm tinh của bạn để đo vị trí transit chòm sao vào thời điểm bạn bốc — cùng với thông tin về bản thân để ra kết quả thật sự dành riêng cho bạn.</p>' +
                        '<a href="' + bctEsc(BCT_PUB.create_agent_url) + '" class="bct-ai-upgrade-btn" target="_blank">🔮 Tạo AI Agent chiêm tinh cá nhân — Miễn phí</a>' +
                        '</div>'
                    ).show();
                }
            } else {
                var errMsg = (res && res.data) ? res.data : 'Không thể kết nối AI lúc này.';
                $content.html('<div class="bct-ai-error">⚠️ ' + bctEsc(errMsg) + '</div>');

                // Always show CTA on error for guest
                if (!BCT_PUB.is_logged) {
                    $cta.html(
                        '<div class="bct-ai-upgrade">' +
                        '<p class="bct-ai-upgrade-note">🌟 <strong>Tôi là AI theo từng cá nhân.</strong> Để có luận giải chính xác dựa trên bản đồ sao cá nhân và vị trí transit hành tinh, hãy tạo tài khoản miễn phí.</p>' +
                        '<a href="' + bctEsc(BCT_PUB.create_agent_url) + '" class="bct-ai-upgrade-btn" target="_blank">🔮 Tạo AI Agent chiêm tinh — Miễn phí</a>' +
                        '</div>'
                    ).show();
                }
            }

            // Scroll AI panel into view
            $([document.documentElement, document.body]).animate({
                scrollTop: $panel.offset().top - 60
            }, 500);
        }).fail(function () {
            $content.html('<div class="bct-ai-error">⚠️ Không thể kết nối tới máy chủ AI.</div>');
        });
    }

    /* ============================================================
       PUSH READING RESULT → gửi luận giải qua tin nhắn bot
       Chỉ chạy khi trang được mở từ link bot (có bct_token)
       ============================================================ */
    function bctPushReadingResult(aiReply) {
        if (!BCT_PUB.bct_token) return;

        // Build danh sách lá bài dạng text
        var cardsText = state.selectedData.map(function (c, idx) {
            var pos = (idx + 1);
            var name = c.name_vi || c.name_en || '';
            var rev  = c.is_reversed ? ' (Ngược)' : ' (Thuận)';
            return pos + '. ' + name + rev;
        }).join('\n');

        $.post(BCT_PUB.ajax_url, {
            action:     'bct_push_reading',
            nonce:      BCT_PUB.nonce,
            bct_token:  BCT_PUB.bct_token,
            ai_reply:   aiReply,
            topic:      state.topic,
            question:   state.question,
            cards_text: cardsText
        }); // fire and forget – không cần chờ response
    }

    /* ============================================================
       SAVE READING
       ============================================================ */
    function bctSaveReading() {
        $.post(BCT_PUB.ajax_url, {
            action:      'bct_save_reading',
            nonce:       BCT_PUB.nonce,
            topic:       state.topic,
            question:    state.question,
            card_ids:    state.selectedIds.join(','),
            cards_json:  JSON.stringify(state.selectedData),
            is_reversed: state.selectedData.map(function (c) { return c.is_reversed; }).join(','),
            session_id:  bctSessionId(),
            bct_token:   BCT_PUB.bct_token || ''  // giúp server lưu đúng client_id + platform
        }, function (res) {
            if (res && res.success && res.data && res.data.reading_id) {
                state.lastReadingId = res.data.reading_id;
            }
        });
    }

    /* ============================================================
       RESET
       ============================================================ */
    window.bctReset = function () {
        state.phase        = 'idle';
        state.topic        = '';
        state.question     = '';
        state.selectedIds  = [];
        state.selectedData = [];
        state.shuffled     = [];
        state.lastReadingId = 0;

        // Re-enable card count picker
        $('.bct-count-btn').prop('disabled', false).removeClass('is-disabled');

        $('#bct-topic').val('');
        $('#bct-question').html('<option value="">💬 Chọn câu hỏi</option>');
        $('#bct-btn-shuffle').prop('disabled', true);
        $('#bct-selected-zone').hide();
        $('#bct-selected-cards').html('');
        $('#bct-reveal-wrap').hide();
        $('#bct-meaning-panel').hide();
        $('#bct-ai-panel').hide();
        $('#bct-ai-content').html('');
        $('#bct-ai-cta').hide().html('');

        // Reset cards
        var $pack  = $('#bct-pack');
        $pack.removeClass('is-spread').off('click.bct');
        var $cards = $pack.find('.bct-card');
        $cards.removeClass('is-selected no-pointer is-reversed is-flipped');
        $cards.each(function (i) {
            $(this).css({ transform: 'translateX(-50%) translateY(' + (i * 0.2) + 'px)', zIndex: i, transition: 'transform .3s' });
        });
        $pack.css('height', '');

        bctSetStep(1, state.stepTexts[0]);
    };

    /* ============================================================
       HELPERS
       ============================================================ */
    function bctSetStep(num, text) {
        $('#bct-step-num').text(num);
        $('#bct-tutorial-text').html(text);
    }

    function bctFisherYates(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
        }
    }

    function bctEsc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getCardLabels() {
        try {
            var raw = JSON.parse($('#bct-labels-data').text() || '{}');
            var n   = state.cardsToPick;
            if (raw.pos_labels && raw.pos_labels[n] && Array.isArray(raw.pos_labels[n])) {
                var lblArr = raw.pos_labels[n];
                var out = {};
                lblArr.forEach(function (l, i) { out[i + 1] = l; });
                return out;
            }
        } catch (e) { /* ignore */ }
        var def = {};
        for (var i = 1; i <= 10; i++) def[i] = 'Lá ' + i;
        return def;
    }

    var _sid = null;
    function bctSessionId() {
        if (!_sid) {
            _sid = 'bct_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
        }
        return _sid;
    }

    /* ============================================================
       AUTO-PRESELECT TOPIC + QUESTION (khi mở từ link bot)
       ============================================================ */
    $(function () {
        var preTopic = BCT_PUB.preselect_topic    || '';
        var preQ     = BCT_PUB.preselect_question || '';
        if (!preTopic) return;

        // Chọn topic
        var $topicSel = $('#bct-topic');
        $topicSel.find('option').each(function () {
            if ($(this).val() === preTopic) {
                $topicSel.val(preTopic);
                return false;
            }
        });

        // Ngọn lửa: load danh sách câu hỏi
        bctUpdateQuestions();

        // Sau khi questions populate, chọn câu hỏi khớp
        if (preQ) {
            setTimeout(function () {
                $('#bct-question').val(preQ).trigger('change.bct');
            }, 60);
        }
    });

})(jQuery);
