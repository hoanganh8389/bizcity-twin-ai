/**
 * BizCity Content Creator — Admin JS
 */
(function ($) {
    'use strict';

    var bzccConfig = window.bzcc || {};

    $(document).ready(function () {
        // Auto-generate slug from title
        $('#title').on('blur', function () {
            var $slug = $('#slug');
            if ($slug.length && !$slug.val()) {
                $slug.val(
                    $(this).val()
                        .toLowerCase()
                        .replace(/[^a-z0-9\s-]/g, '')
                        .replace(/\s+/g, '-')
                        .replace(/-+/g, '-')
                        .substring(0, 100)
                );
            }
        });

        // Validate JSON fields before submit
        $('.bzcc-template-form').on('submit', function (e) {
            var jsonFields = ['form_fields', 'wizard_steps', 'output_platforms'];
            for (var i = 0; i < jsonFields.length; i++) {
                var $field = $('textarea[name="' + jsonFields[i] + '"]');
                if ($field.length && $field.val().trim()) {
                    try {
                        JSON.parse($field.val());
                    } catch (err) {
                        e.preventDefault();
                        alert('JSON không hợp lệ trong field: ' + jsonFields[i] + '\n' + err.message);
                        $field.focus();
                        return false;
                    }
                }
            }
        });

        /* ══════════════════════════════════════════════
         *  Prompt Pipeline UX
         * ══════════════════════════════════════════════ */

        // Track last focused prompt textarea
        var $lastPromptTextarea = null;
        $('.bzcc-prompt-textarea').on('focus', function () {
            $lastPromptTextarea = $(this);
        });

        // Toggle optional prompt cards
        $(document).on('click', '.bzcc-prompt-card__toggle', function () {
            var targetId = $(this).data('target');
            var $body = $('#' + targetId);
            $body.toggleClass('bzcc-prompt-card__body--collapsed');
            $(this).toggleClass('bzcc-prompt-card__toggle--collapsed');
        });

        // Temperature slider ↔ output sync
        var $tempRange = $('#bzcc_temp_range');
        var $tempOutput = $('#bzcc_temp_value');
        if ($tempRange.length) {
            $tempRange.on('input', function () {
                $tempOutput.text(parseFloat(this.value).toFixed(2));
            });
        }

        // Build variable tags from form_fields JSON
        function refreshVarTags() {
            var $tagsContainer = $('#bzccVarTags');
            if (!$tagsContainer.length) return;

            var fields = [];
            try {
                var raw = $('textarea[name="form_fields"]').val();
                fields = raw ? JSON.parse(raw) : [];
            } catch (e) {
                fields = [];
            }

            if (!fields.length) {
                $tagsContainer.html('<span class="bzcc-prompt-vars__empty">Thêm trường ở Form Builder bên trên để có biến dùng.</span>');
                return;
            }

            var html = '';
            for (var i = 0; i < fields.length; i++) {
                var slug = fields[i].slug || '';
                var label = fields[i].label || slug;
                if (slug) {
                    html += '<span class="bzcc-var-tag" data-slug="' + slug + '" title="' + label + '">{{' + slug + '}}</span>';
                }
            }
            // Add special vars
            html += '<span class="bzcc-var-tag bzcc-var-tag--special" data-slug="outline" title="Dàn ý (auto)">{{outline}}</span>';
            html += '<span class="bzcc-var-tag bzcc-var-tag--special" data-slug="current_section" title="Section đang viết (auto)">{{current_section}}</span>';
            $tagsContainer.html(html);
        }

        // Click variable tag → insert into last focused textarea
        $(document).on('click', '.bzcc-var-tag', function () {
            var slug = '{{' + $(this).data('slug') + '}}';
            var $tag = $(this);

            if (!$lastPromptTextarea || !$lastPromptTextarea.length) {
                // Default to system_prompt
                $lastPromptTextarea = $('#system_prompt');
            }

            var textarea = $lastPromptTextarea[0];
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var text = textarea.value;
            textarea.value = text.substring(0, start) + slug + text.substring(end);
            textarea.selectionStart = textarea.selectionEnd = start + slug.length;
            textarea.focus();

            // Flash green feedback
            $tag.addClass('bzcc-var-tag--inserted');
            setTimeout(function () {
                $tag.removeClass('bzcc-var-tag--inserted');
            }, 600);
        });

        /* ══════════════════════════════════════════════
         *  Auto-gen System Prompt — Dialog
         * ══════════════════════════════════════════════ */

        // Build & inject dialog markup once
        function ensureDialog() {
            if ($('#bzccPromptDialog').length) return;
            var dialogHTML =
                '<div id="bzccPromptDialog" class="bzcc-dialog-overlay">' +
                  '<div class="bzcc-dialog">' +
                    '<div class="bzcc-dialog__header">' +
                      '<h3>✨ Tạo Prompt bằng AI</h3>' +
                      '<button type="button" class="bzcc-dialog__close" id="bzccDialogClose">&times;</button>' +
                    '</div>' +
                    '<div class="bzcc-dialog__body">' +

                      '<div class="bzcc-dialog__input-group">' +
                        '<label for="bzccDialogGoal">🎯 Mục tiêu của template này là gì?</label>' +
                        '<textarea id="bzccDialogGoal" rows="4" placeholder="Ví dụ:\n• Viết bài review sản phẩm, giọng văn thân thiện, dài 800 từ\n• Tạo email marketing cho chiến dịch sale, có CTA mạnh\n• Viết script video TikTok 60s, hài hước, trending"></textarea>' +
                      '</div>' +

                      '<div class="bzcc-dialog__input-group">' +
                        '<label for="bzccDialogTone">🎨 Giọng văn / phong cách</label>' +
                        '<div class="bzcc-dialog__tone-chips" id="bzccToneChips">' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="thân thiện">😊 Thân thiện</span>' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="chuyên nghiệp">💼 Chuyên nghiệp</span>' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="hài hước">😄 Hài hước</span>' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="thuyết phục">🎯 Thuyết phục</span>' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="học thuật">📚 Học thuật</span>' +
                          '<span class="bzcc-dialog__tone-chip" data-tone="sáng tạo">🎨 Sáng tạo</span>' +
                        '</div>' +
                        '<input type="text" id="bzccDialogTone" placeholder="Hoặc gõ giọng văn tùy chỉnh...">' +
                      '</div>' +

                      '<div class="bzcc-dialog__fields-summary" id="bzccDialogFields">' +
                        '<p class="bzcc-dialog__fields-title">📋 Các trường từ Form Builder <small>(sẽ tự chèn vào prompt)</small>:</p>' +
                        '<div class="bzcc-dialog__fields-list"></div>' +
                      '</div>' +

                    '</div>' +
                    '<div class="bzcc-dialog__footer">' +
                      '<span class="bzcc-dialog__shortcut">Ctrl+Enter để tạo nhanh</span>' +
                      '<button type="button" class="button" id="bzccDialogCancel">Hủy</button>' +
                      '<button type="button" class="button button-primary" id="bzccDialogGenerate">🚀 Tạo prompt</button>' +
                    '</div>' +
                  '</div>' +
                '</div>';
            $('body').append(dialogHTML);

            // Tone chip click → toggle active + fill input
            $(document).on('click', '.bzcc-dialog__tone-chip', function () {
                $(this).toggleClass('bzcc-dialog__tone-chip--active');
                var selected = [];
                $('.bzcc-dialog__tone-chip--active').each(function () {
                    selected.push($(this).data('tone'));
                });
                $('#bzccDialogTone').val(selected.join(', '));
            });
        }

        function getFormFields() {
            var fields = [];
            try {
                var raw = $('textarea[name="form_fields"]').val();
                fields = raw ? JSON.parse(raw) : [];
            } catch (e) { fields = []; }
            return Array.isArray(fields) ? fields : [];
        }

        function openPromptDialog() {
            ensureDialog();
            var fields = getFormFields();

            // Populate fields summary
            var $list = $('#bzccPromptDialog .bzcc-dialog__fields-list');
            if (!fields.length) {
                $list.html('<span class="bzcc-dialog__no-fields">⚠️ Chưa có trường nào. Hãy thêm trường trong Form Builder trước.</span>');
                $('#bzccDialogGenerate').prop('disabled', true);
            } else {
                var chips = '';
                for (var i = 0; i < fields.length; i++) {
                    var f = fields[i];
                    var typeIcons = { text:'✏️', textarea:'📝', number:'🔢', select:'📋', radio:'🔘', checkbox:'☑️', rating:'⭐', scale:'📊', range:'🎚️', toggle:'🔀', image:'🖼️' };
                    var icon = typeIcons[f.type] || '📄';
                    chips += '<span class="bzcc-dialog__field-chip">' +
                        '<span class="bzcc-dialog__field-chip-icon">' + icon + '</span>' +
                        '<span class="bzcc-dialog__field-chip-label">' + (f.label || f.slug) + '</span>' +
                        '<code>{{' + f.slug + '}}</code>' +
                    '</span>';
                }
                $list.html(chips);
                $('#bzccDialogGenerate').prop('disabled', false);
            }

            // Clear inputs, show dialog
            $('#bzccDialogGoal').val('');
            $('#bzccDialogTone').val('');
            $('.bzcc-dialog__tone-chip').removeClass('bzcc-dialog__tone-chip--active');
            $('#bzccPromptDialog').addClass('bzcc-dialog-overlay--open');
            setTimeout(function () { $('#bzccDialogGoal').trigger('focus'); }, 100);
        }

        function closePromptDialog() {
            $('#bzccPromptDialog').removeClass('bzcc-dialog-overlay--open');
        }

        function generatePromptFromDialog() {
            var fields = getFormFields();
            var goal = ($('#bzccDialogGoal').val() || '').trim();
            var tone = ($('#bzccDialogTone').val() || '').trim();

            if (!fields.length && !goal) return;

            /* ═══ 1. System Prompt ═══ */
            var sys = [];

            // Role + expertise
            if (goal) {
                sys.push('Bạn là chuyên gia hàng đầu về: ' + goal.split('\n')[0] + '.');
                sys.push('Bạn có 15+ năm kinh nghiệm thực chiến trong lĩnh vực này.');
            } else {
                sys.push('Bạn là chuyên gia sáng tạo nội dung hàng đầu với 15+ năm kinh nghiệm.');
            }
            sys.push('');

            // Tone
            if (tone) {
                sys.push('GIỌNG VĂN: ' + tone + '.');
            }

            // Core rules
            sys.push('');
            sys.push('QUY TẮC BẮT BUỘC:');
            sys.push('1. CHỈ viết NỘI DUNG THỰC TẾ, cụ thể, sẵn sàng sử dụng ngay.');
            sys.push('2. KHÔNG viết phân tích chiến lược, nghiên cứu thị trường, hay báo cáo tổng quan.');
            sys.push('3. KHÔNG lặp lại nhiệm vụ hoặc mô tả lại đề bài.');
            sys.push('4. Mỗi phần phải khác biệt rõ ràng — KHÔNG viết nội dung na ná giữa các phần.');
            sys.push('5. Ưu tiên ngôn ngữ đời thường, dễ hiểu, không thuật ngữ chuyên môn trừ khi cần thiết.');
            sys.push('6. Viết dạng danh sách có đánh số khi phù hợp — mỗi mục 2-3 câu giải thích cụ thể.');
            sys.push('');

            // Form fields
            if (fields.length) {
                sys.push('Người dùng sẽ cung cấp:');
                for (var i = 0; i < fields.length; i++) {
                    var f = fields[i];
                    var label = f.label || f.slug;
                    var hint  = '';
                    if (f.type === 'select' || f.type === 'radio') {
                        var opts = (f.options || []).map(function(o) { return o.label || o.value; });
                        if (opts.length) hint = ' (lựa chọn: ' + opts.join(', ') + ')';
                    } else if (f.type === 'rating') {
                        hint = ' (thang ' + (f.min || 1) + '-' + (f.max || 5) + ')';
                    } else if (f.type === 'scale' || f.type === 'range') {
                        hint = ' (' + (f.min || 1) + '-' + (f.max || 10) + ')';
                    } else if (f.type === 'toggle') {
                        hint = ' (bật/tắt)';
                    }
                    sys.push('- ' + label + ': {{' + f.slug + '}}' + hint);
                }
                sys.push('');
            }

            if (goal) {
                sys.push('MỤC TIÊU CUỐI: ' + goal.split('\n')[0]);
            }
            sys.push('Output phải rõ ràng, hấp dẫn, mang lại giá trị thực cho người đọc.');

            $('#system_prompt').val(sys.join('\n')).trigger('focus');

            /* ═══ 2. Outline Prompt ═══ */
            var outline = [];
            outline.push('Dựa trên yêu cầu trên, hãy tạo dàn ý chi tiết gồm 5-8 phần RIÊNG BIỆT.');
            outline.push('');
            outline.push('YÊU CẦU DÀN Ý:');
            outline.push('- Mỗi phần phải có nội dung ĐỘC LẬP, không trùng lặp.');
            outline.push('- Tiêu đề ngắn gọn, rõ ràng, gợi mở nội dung cụ thể sẽ viết.');
            outline.push('- KHÔNG đặt tiêu đề chung chung kiểu "Phân tích", "Tổng quan", "Kết luận".');
            outline.push('- Mỗi tiêu đề phải cho biết chính xác nội dung gì sẽ được viết.');
            outline.push('');
            outline.push('FORMAT (JSON array):');
            outline.push('[');
            outline.push('  {"title": "Tiêu đề phần 1", "description": "Mô tả ngắn nội dung"},');
            outline.push('  {"title": "Tiêu đề phần 2", "description": "Mô tả ngắn nội dung"}');
            outline.push(']');

            var $outWrap = $('#outline_prompt_wrap');
            $('#outline_prompt').val(outline.join('\n'));
            if ($outWrap.hasClass('bzcc-prompt-card__body--collapsed')) {
                $outWrap.removeClass('bzcc-prompt-card__body--collapsed');
            }

            /* ═══ 3. Chunk Prompt ═══ */
            var chunk = [];
            chunk.push('Đây là dàn ý tổng thể:');
            chunk.push('{{outline}}');
            chunk.push('');
            chunk.push('Hãy viết chi tiết cho phần: {{chunk_title}}');
            chunk.push('Nền tảng xuất bản: {{platform}}');
            chunk.push('');
            chunk.push('YÊU CẦU:');
            chunk.push('- Viết NỘI DUNG THỰC TẾ sẵn sàng đăng/gửi, KHÔNG phải phân tích hay chiến lược.');
            chunk.push('- Nội dung phải CỤ THỂ cho phần "{{chunk_title}}" — không lặp lại các phần khác.');
            chunk.push('- Khi liệt kê: mỗi mục có số thứ tự, 2-3 câu giải thích chi tiết.');
            chunk.push('- Viết ít nhất 300 từ cho phần này.');
            if (tone) {
                chunk.push('- Giữ giọng văn: ' + tone + '.');
            }

            var $chunkWrap = $('#chunk_prompt_wrap');
            $('#chunk_prompt').val(chunk.join('\n'));
            if ($chunkWrap.hasClass('bzcc-prompt-card__body--collapsed')) {
                $chunkWrap.removeClass('bzcc-prompt-card__body--collapsed');
            }

            closePromptDialog();

            // Flash feedback
            var $btn = $('#bzccAutoGenPrompt');
            $btn.text('✅ Đã tạo 3 prompt!').prop('disabled', true);
            setTimeout(function () { $btn.text('✨ Gợi ý prompt').prop('disabled', false); }, 2000);
        }

        // Open dialog on button click
        $(document).on('click', '#bzccAutoGenPrompt', function () {
            openPromptDialog();
        });

        // Close dialog
        $(document).on('click', '#bzccDialogClose, #bzccDialogCancel', function () {
            closePromptDialog();
        });
        $(document).on('click', '#bzccPromptDialog', function (e) {
            if ($(e.target).is('#bzccPromptDialog')) closePromptDialog();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#bzccPromptDialog').hasClass('bzcc-dialog-overlay--open')) {
                closePromptDialog();
            }
        });

        // Generate
        $(document).on('click', '#bzccDialogGenerate', function () {
            generatePromptFromDialog();
        });

        // Ctrl+Enter shortcut in dialog textarea
        $(document).on('keydown', '#bzccDialogGoal, #bzccDialogTone', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                generatePromptFromDialog();
            }
        });

        // Refresh tags on load and when form_fields changes
        refreshVarTags();

        // Watch for form_fields textarea changes (updated by form builder JS)
        var formFieldsTextarea = $('textarea[name="form_fields"]')[0];
        if (formFieldsTextarea) {
            var observer = new MutationObserver(function () { refreshVarTags(); });
            observer.observe(formFieldsTextarea, { childList: true, characterData: true, subtree: true });
            // Also poll since value changes don't trigger mutations
            setInterval(function () {
                var current = formFieldsTextarea.value;
                if (current !== formFieldsTextarea._lastVal) {
                    formFieldsTextarea._lastVal = current;
                    refreshVarTags();
                }
            }, 1500);
        }

        /* ══════════════════════════════════════════════
         *  Template Import / Export / Duplicate
         * ══════════════════════════════════════════════ */

        function downloadJSON(data, filename) {
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(a.href);
        }

        /* ── Export single template ── */
        $(document).on('click', '.bzcc-tpl-export', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!id) return;

            var $btn = $(this);
            var origText = $btn.text();
            $btn.text('⏳...').css('pointer-events', 'none');

            $.ajax({
                url: bzccConfig.rest_url + '/template/' + id + '/export',
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bzccConfig.nonce);
                },
                success: function (res) {
                    var slug = (res.templates && res.templates[0] && res.templates[0].slug) || 'template';
                    downloadJSON(res, 'bzcc-template-' + slug + '.json');
                    $btn.text('✅ Done!');
                    setTimeout(function () { $btn.text(origText).css('pointer-events', ''); }, 1500);
                },
                error: function () {
                    alert('Export thất bại.');
                    $btn.text(origText).css('pointer-events', '');
                }
            });
        });

        /* ── Export all templates ── */
        $(document).on('click', '#bzcc-export-all', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('⏳ Đang xuất...');

            $.ajax({
                url: bzccConfig.rest_url + '/templates/export',
                method: 'GET',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bzccConfig.nonce);
                },
                success: function (res) {
                    var count = res.templates ? res.templates.length : 0;
                    downloadJSON(res, 'bzcc-all-templates-' + count + '.json');
                    $btn.prop('disabled', false).text('📤 Export tất cả');
                },
                error: function () {
                    alert('Export thất bại.');
                    $btn.prop('disabled', false).text('📤 Export tất cả');
                }
            });
        });

        /* ── Import trigger ── */
        $(document).on('click', '#bzcc-import-btn', function () {
            $('#bzcc-import-file').val('').trigger('click');
        });

        $(document).on('change', '#bzcc-import-file', function () {
            var file = this.files[0];
            if (!file) return;

            var $status = $('#bzcc-import-status');
            $status.text('⏳ Đang đọc file...');

            var reader = new FileReader();
            reader.onload = function (e) {
                var data;
                try {
                    data = JSON.parse(e.target.result);
                } catch (err) {
                    $status.text('❌ JSON không hợp lệ: ' + err.message);
                    return;
                }

                if (!data.templates || !Array.isArray(data.templates)) {
                    $status.text('❌ File thiếu mảng "templates".');
                    return;
                }

                var count = data.templates.length;
                if (!confirm('Import ' + count + ' template(s)?\nTemplate trùng slug sẽ được tạo mới với tên (1), (2)...')) {
                    $status.text('');
                    return;
                }

                $status.text('⏳ Đang import ' + count + ' templates...');

                $.ajax({
                    url: bzccConfig.rest_url + '/templates/import',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(data),
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', bzccConfig.nonce);
                    },
                    success: function (res) {
                        var msg = '✅ Import xong: ' + res.imported + ' template(s)';
                        if (res.renamed) msg += ' (' + res.renamed + ' đổi tên)';
                        if (res.skipped) msg += ', bỏ qua ' + res.skipped;
                        $status.text(msg);
                        setTimeout(function () { location.reload(); }, 1500);
                    },
                    error: function (xhr) {
                        var errMsg = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'Lỗi server';
                        $status.text('❌ ' + errMsg);
                    }
                });
            };
            reader.readAsText(file);
        });

        /* ── Duplicate via REST ── */
        $(document).on('click', '.bzcc-tpl-duplicate', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!id) return;

            var $btn = $(this);
            $btn.css('pointer-events', 'none');

            $.ajax({
                url: bzccConfig.rest_url + '/template/' + id + '/duplicate',
                method: 'POST',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bzccConfig.nonce);
                },
                success: function (res) {
                    if (res.id) {
                        alert('✅ Đã nhân bản → ID: ' + res.id + ' (' + res.title + ')');
                        location.reload();
                    }
                },
                error: function () {
                    alert('Nhân bản thất bại.');
                    $btn.css('pointer-events', '');
                }
            });
        });

        /* ══════════════════════════════════════════════
         *  Emoji Picker for icon_emoji fields
         * ══════════════════════════════════════════════ */
        (function initEmojiPickers() {
            var EMOJI_DATA = {
                'Nội dung':   ['📝','✍️','📄','📰','📖','📑','💬','🗣️','📣','📢','💡','🎯','🔥','⚡','✨','🌟','⭐','💎','🏆','🎖️'],
                'Mạng xã hội': ['📘','📸','🎵','▶️','💬','📧','🐦','🔗','📱','💻','🖥️','📲','🌐','📡','📺','🎬','🎥','🤳','👥','🫂'],
                'Marketing':  ['🚀','💰','📈','📊','🎯','🛒','🛍️','💳','🏷️','🎁','🎪','📮','🎊','🎉','💸','🤑','📋','🔖','🏅','🎫'],
                'Công cụ':    ['🔧','⚙️','🛠️','🔨','🧰','📐','📏','🔬','🔭','🧪','🧮','💾','📁','📂','🗂️','📦','🧩','🔑','🔒','🔓'],
                'Cảm xúc':   ['😊','😄','😉','🥰','😎','🤩','🤔','😂','👍','👏','❤️','💜','💙','💚','🧡','💛','🙏','💪','🎶','🌈'],
                'Ngành nghề': ['🏠','🍔','💊','📚','✈️','🚗','👗','💄','🏋️','🐾','👶','🌿','🎮','🎨','🎹','📷','🏫','🏥','🏢','🏗️']
            };

            $('.bzcc-emoji-picker').each(function () {
                var $picker   = $(this);
                var $trigger  = $picker.find('.bzcc-emoji-picker__trigger');
                var $dropdown = $picker.find('.bzcc-emoji-picker__dropdown');
                var $input    = $picker.find('input[type="hidden"]');
                var $preview  = $picker.find('.bzcc-emoji-picker__preview');
                var $placeholder = $picker.find('.bzcc-emoji-picker__placeholder');

                // Set initial state
                if ($input.val()) {
                    $preview.text($input.val()).show();
                    $placeholder.hide();
                } else {
                    $preview.hide();
                    $placeholder.show();
                }

                // Build dropdown HTML
                var html = '<div class="bzcc-emoji-picker__search"><input type="text" placeholder="Tìm emoji..." class="bzcc-emoji-picker__search-input"></div>';
                html += '<div class="bzcc-emoji-picker__groups">';
                $.each(EMOJI_DATA, function (group, emojis) {
                    html += '<div class="bzcc-emoji-picker__group">';
                    html += '<div class="bzcc-emoji-picker__group-title">' + group + '</div>';
                    html += '<div class="bzcc-emoji-picker__grid">';
                    for (var i = 0; i < emojis.length; i++) {
                        html += '<button type="button" class="bzcc-emoji-picker__item" data-emoji="' + emojis[i] + '">' + emojis[i] + '</button>';
                    }
                    html += '</div></div>';
                });
                html += '</div>';
                html += '<div class="bzcc-emoji-picker__clear"><button type="button" class="bzcc-emoji-picker__clear-btn">✕ Xóa icon</button></div>';
                $dropdown.html(html);

                // Toggle dropdown
                $trigger.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var wasOpen = $dropdown.hasClass('bzcc-emoji-picker__dropdown--open');
                    // Close all others
                    $('.bzcc-emoji-picker__dropdown').removeClass('bzcc-emoji-picker__dropdown--open');
                    if (!wasOpen) {
                        $dropdown.addClass('bzcc-emoji-picker__dropdown--open');
                        $dropdown.find('.bzcc-emoji-picker__search-input').focus();
                    }
                });

                // Pick emoji
                $dropdown.on('click', '.bzcc-emoji-picker__item', function (e) {
                    e.preventDefault();
                    var emoji = $(this).data('emoji');
                    $input.val(emoji);
                    $preview.text(emoji).show();
                    $placeholder.hide();
                    $dropdown.removeClass('bzcc-emoji-picker__dropdown--open');
                });

                // Clear
                $dropdown.on('click', '.bzcc-emoji-picker__clear-btn', function (e) {
                    e.preventDefault();
                    $input.val('');
                    $preview.text('').hide();
                    $placeholder.show();
                    $dropdown.removeClass('bzcc-emoji-picker__dropdown--open');
                });

                // Search filter
                $dropdown.on('input', '.bzcc-emoji-picker__search-input', function () {
                    var q = $(this).val().toLowerCase();
                    // Simple: if search is an emoji char, match directly; otherwise hide non-matching
                    $dropdown.find('.bzcc-emoji-picker__item').each(function () {
                        var emoji = $(this).data('emoji');
                        $(this).toggle(!q || emoji.indexOf(q) !== -1);
                    });
                });
            });

            // Close on outside click
            $(document).on('click', function () {
                $('.bzcc-emoji-picker__dropdown').removeClass('bzcc-emoji-picker__dropdown--open');
            });
            // Prevent dropdown clicks from bubbling
            $(document).on('click', '.bzcc-emoji-picker__dropdown', function (e) {
                e.stopPropagation();
            });
        })();

        /* ══════════════════════════════════════════════
         *  Badge Preset Picker
         * ══════════════════════════════════════════════ */
        (function initBadgePicker() {
            var $presets    = $('#bzcc-badge-presets');
            var $textInput  = $('input[name="badge_text"]');
            var $colorInput = $('input[name="badge_color"]');
            var $livePreview = $('#bzcc-badge-live-preview');

            if (!$presets.length) return;

            // Mark active preset on load
            function syncActiveChip() {
                var curText  = $textInput.val();
                var curColor = $colorInput.val();
                $presets.find('.bzcc-badge-picker__chip').each(function () {
                    var match = $(this).data('badge') === curText && ($(this).data('color') === curColor || !$(this).data('badge'));
                    $(this).toggleClass('bzcc-badge-picker__chip--active', match);
                });
            }

            function updateLivePreview() {
                var text  = $textInput.val();
                var color = $colorInput.val() || '#6366f1';
                if (text) {
                    $livePreview.html('<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff;background:' + color + ';">' + $('<span>').text(text).html() + '</span>').show();
                } else {
                    $livePreview.hide();
                }
            }

            // Click preset chip
            $presets.on('click', '.bzcc-badge-picker__chip', function (e) {
                e.preventDefault();
                var badge = $(this).data('badge');
                var color = $(this).data('color');
                $textInput.val(badge);
                if (color) $colorInput.val(color);
                syncActiveChip();
                updateLivePreview();
            });

            // Typing custom badge → deselect presets
            $textInput.on('input', function () {
                syncActiveChip();
                updateLivePreview();
            });
            $colorInput.on('input change', function () {
                syncActiveChip();
                updateLivePreview();
            });

            // Initial state
            syncActiveChip();
            updateLivePreview();
        })();
    });

})(jQuery);
