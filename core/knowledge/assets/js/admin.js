/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Core\Knowledge — Admin JavaScript
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @license GPL-2.0-or-later | https://bizcity.vn
 */

(function($) {
    'use strict';

    // Global state
    const BizCityKnowledge = {
        currentCharacterId: null,
        
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initIntentAccordion();
        },
        
        bindEvents: function() {
            // Tab switching
            $(document).on('click', '.bizcity-tab', this.handleTabClick.bind(this));
            
            // Modal
            $(document).on('click', '[data-modal]', this.openModal.bind(this));
            $(document).on('click', '.bizcity-modal-close, .bizcity-modal-overlay', this.closeModal.bind(this));
            $(document).on('click', '.bizcity-modal', function(e) { e.stopPropagation(); });
            
            // Add knowledge source
            $(document).on('click', '.add-option', this.handleAddKnowledge.bind(this));
            
            // Import URL
            $(document).on('click', '#btn-import-url', this.handleImportUrl.bind(this));
            
            // Upload file
            $(document).on('click', '#btn-upload-file', this.handleUploadFile.bind(this));
            
            // Sync fanpage
            $(document).on('click', '#btn-sync-fanpage', this.handleSyncFanpage.bind(this));
            
            // Delete source
            $(document).on('click', '.delete-source', this.handleDeleteSource.bind(this));
            
            // Promote source scope (Knowledge Fabric v3.0)
            $(document).on('click', '.promote-source', this.handlePromoteSource.bind(this));
            
            // Test character
            $(document).on('click', '#btn-test-character', this.handleTestCharacter.bind(this));
            $(document).on('keypress', '#test-question', function(e) {
                if (e.which === 13) {
                    $('#btn-test-character').click();
                }
            });
            
            // Intent accordion
            $(document).on('click', '.bizcity-intent-header', this.toggleIntent.bind(this));
            
            // Add intent
            $(document).on('click', '#btn-add-intent', this.handleAddIntent.bind(this));
            
            // Save character
            $(document).on('click', '#btn-save-character', this.handleSaveCharacter.bind(this));
            
            // Publish to market
            $(document).on('click', '#btn-publish-market', this.handlePublishToMarket.bind(this));
        },
        
        initTabs: function() {
            const hash = window.location.hash;
            if (hash && $(hash + '-tab').length) {
                this.switchTab(hash.replace('#', ''));
            }
        },
        
        initIntentAccordion: function() {
            // Open first intent by default
            $('.bizcity-intent-content:first').addClass('active');
        },
        
        handleTabClick: function(e) {
            const $tab = $(e.currentTarget);
            const tabId = $tab.data('tab');
            this.switchTab(tabId);
        },
        
        switchTab: function(tabId) {
            $('.bizcity-tab').removeClass('active');
            $(`.bizcity-tab[data-tab="${tabId}"]`).addClass('active');
            
            $('.bizcity-tab-content').removeClass('active');
            $(`#${tabId}-content`).addClass('active');
            
            window.location.hash = tabId;
        },
        
        openModal: function(e) {
            e.preventDefault();
            const modalId = $(e.currentTarget).data('modal');
            $(`#${modalId}`).addClass('active');
        },
        
        closeModal: function(e) {
            if ($(e.target).hasClass('bizcity-modal-overlay') || 
                $(e.target).hasClass('bizcity-modal-close')) {
                $('.bizcity-modal-overlay').removeClass('active');
            }
        },
        
        handleAddKnowledge: function(e) {
            const type = $(e.currentTarget).data('type');
            
            switch (type) {
                case 'quick_faq':
                    this.openModal({ currentTarget: { dataset: { modal: 'modal-quick-faq' }}});
                    break;
                case 'file':
                    this.openModal({ currentTarget: { dataset: { modal: 'modal-upload-file' }}});
                    break;
                case 'url':
                    this.openModal({ currentTarget: { dataset: { modal: 'modal-import-url' }}});
                    break;
                case 'fanpage':
                    this.openModal({ currentTarget: { dataset: { modal: 'modal-sync-fanpage' }}});
                    break;
            }
        },
        
        handleImportUrl: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const url = $('#import-url').val();
            const characterId = $('#character-id').val();
            
            if (!url) {
                this.showNotice('error', 'Vui lòng nhập URL');
                return;
            }
            
            $btn.prop('disabled', true).text('Đang import...');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bizcity_import_url',
                    nonce: bizcityKnowledge.nonce,
                    url: url,
                    character_id: characterId
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Import thành công!');
                        this.closeModal({ target: $('.bizcity-modal-overlay') });
                        this.refreshKnowledgeSources();
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra khi import');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Import');
                }
            });
        },
        
        handleUploadFile: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const fileInput = document.getElementById('upload-file');
            const characterId = $('#character-id').val();
            
            if (!fileInput.files.length) {
                this.showNotice('error', 'Vui lòng chọn file');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'bizcity_process_file');
            formData.append('nonce', bizcityKnowledge.nonce);
            formData.append('file', fileInput.files[0]);
            formData.append('character_id', characterId);
            
            $btn.prop('disabled', true).text('Đang xử lý...');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Upload và xử lý file thành công!');
                        this.closeModal({ target: $('.bizcity-modal-overlay') });
                        this.refreshKnowledgeSources();
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra khi upload file');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Upload');
                }
            });
        },
        
        handleSyncFanpage: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const fanpageId = $('#fanpage-id').val();
            const characterId = $('#character-id').val();
            
            if (!fanpageId) {
                this.showNotice('error', 'Vui lòng nhập Fanpage ID');
                return;
            }
            
            $btn.prop('disabled', true).text('Đang đồng bộ...');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bizcity_sync_fanpage',
                    nonce: bizcityKnowledge.nonce,
                    fanpage_id: fanpageId,
                    character_id: characterId
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', `Đồng bộ thành công ${response.data.posts_count} bài viết!`);
                        this.closeModal({ target: $('.bizcity-modal-overlay') });
                        this.refreshKnowledgeSources();
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra khi đồng bộ');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Đồng bộ');
                }
            });
        },
        
        handleDeleteSource: function(e) {
            e.preventDefault();
            if (!confirm('Bạn có chắc muốn xóa nguồn kiến thức này?')) {
                return;
            }
            
            const $btn = $(e.currentTarget);
            const sourceId = $btn.data('id');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bizcity_delete_source',
                    nonce: bizcityKnowledge.nonce,
                    source_id: sourceId
                },
                success: (response) => {
                    if (response.success) {
                        $btn.closest('.bizcity-source-item').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                }
            });
        },
        
        /**
         * Knowledge Fabric v3.0 — Promote source to a different scope
         */
        handlePromoteSource: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const sourceId = $btn.data('id');
            const newScope = $btn.data('scope') || 'user';

            if (!confirm('Promote source #' + sourceId + ' → scope "' + newScope + '"?')) {
                return;
            }

            $btn.text('⏳...').css('pointer-events', 'none');

            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bizcity_knowledge_promote_source',
                    nonce: bizcityKnowledge.nonce,
                    source_id: sourceId,
                    new_scope: newScope
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message || 'Đã promote thành công');
                        // Update badge in the same row
                        const $row = $btn.closest('tr');
                        const scopeIcons = { agent: '🤖', user: '👤', project: '📁', session: '💬' };
                        const scopeLabels = { agent: 'Agent', user: 'Cá nhân', project: 'Dự án', session: 'Session' };
                        $row.find('.bk-scope-badge')
                            .attr('class', 'bk-scope-badge bk-scope-' + newScope)
                            .html(scopeIcons[newScope] + ' ' + scopeLabels[newScope]);
                        // Remove promote button (already promoted)
                        $btn.remove();
                    } else {
                        this.showNotice('error', response.data.message || 'Không thể promote');
                        $btn.text('⬆ Promote').css('pointer-events', '');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra');
                    $btn.text('⬆ Promote').css('pointer-events', '');
                }
            });
        },

        handleTestCharacter: function(e) {
            e.preventDefault();
            const $btn = $(e.currentTarget);
            const question = $('#test-question').val();
            const characterId = $('#character-id').val();
            
            if (!question) {
                return;
            }
            
            // Add user message
            this.addChatMessage(question, 'user');
            $('#test-question').val('');
            
            $btn.prop('disabled', true);
            
            $.ajax({
                url: bizcityKnowledge.restUrl + 'characters/' + characterId + '/query',
                type: 'POST',
                headers: {
                    'X-WP-Nonce': bizcityKnowledge.restNonce
                },
                data: JSON.stringify({ question: question }),
                contentType: 'application/json',
                success: (response) => {
                    this.addChatMessage(response.answer, 'bot');
                },
                error: () => {
                    this.addChatMessage('Có lỗi xảy ra, vui lòng thử lại.', 'bot');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                }
            });
        },
        
        addChatMessage: function(content, type) {
            const $messages = $('.bizcity-chat-messages');
            const html = `
                <div class="bizcity-chat-message ${type}">
                    <div class="message-content">${this.escapeHtml(content)}</div>
                </div>
            `;
            $messages.append(html);
            $messages.scrollTop($messages[0].scrollHeight);
        },
        
        toggleIntent: function(e) {
            const $header = $(e.currentTarget);
            const $content = $header.next('.bizcity-intent-content');
            
            $content.toggleClass('active');
        },
        
        handleAddIntent: function(e) {
            e.preventDefault();
            const intentName = prompt('Nhập tên intent mới:');
            
            if (!intentName) return;
            
            const template = `
                <div class="bizcity-intent-item">
                    <div class="bizcity-intent-header">
                        <span class="intent-name">${this.escapeHtml(intentName)}</span>
                        <span class="intent-action">Chưa cấu hình</span>
                    </div>
                    <div class="bizcity-intent-content active">
                        <div class="bizcity-form-row">
                            <label>Từ khóa kích hoạt</label>
                            <input type="text" name="intent_keywords[]" placeholder="Nhập các từ khóa, cách nhau bằng dấu phẩy">
                        </div>
                        <div class="bizcity-form-row">
                            <label>Hành động</label>
                            <select name="intent_action[]">
                                <option value="reply">Trả lời cố định</option>
                                <option value="workflow">Chạy Workflow</option>
                                <option value="webhook">Gọi Webhook</option>
                            </select>
                        </div>
                        <div class="bizcity-form-row">
                            <label>Biến cần trích xuất</label>
                            <input type="text" name="intent_variables[]" placeholder="quantity, phone, email...">
                        </div>
                        <input type="hidden" name="intent_name[]" value="${this.escapeHtml(intentName)}">
                    </div>
                </div>
            `;
            
            $('.bizcity-intents-list').append(template);
        },
        
        handleSaveCharacter: function(e) {
            e.preventDefault();
            const $form = $('#character-form');
            const $btn = $(e.currentTarget);
            
            $btn.prop('disabled', true).text('Đang lưu...');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: $form.serialize() + '&action=bizcity_save_character&nonce=' + bizcityKnowledge.nonce,
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Đã lưu thành công!');
                        if (response.data.id && !$('#character-id').val()) {
                            $('#character-id').val(response.data.id);
                            // Update URL
                            history.pushState({}, '', `?page=bizcity-knowledge-characters&action=edit&id=${response.data.id}`);
                        }
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra khi lưu');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Lưu Character');
                }
            });
        },
        
        handlePublishToMarket: function(e) {
            e.preventDefault();
            const characterId = $('#character-id').val();
            
            if (!characterId) {
                this.showNotice('error', 'Vui lòng lưu character trước');
                return;
            }
            
            if (!confirm('Bạn có chắc muốn đăng character này lên BizCity Market?')) {
                return;
            }
            
            const $btn = $(e.currentTarget);
            $btn.prop('disabled', true).text('Đang đăng...');
            
            $.ajax({
                url: bizcityKnowledge.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bizcity_publish_to_market',
                    nonce: bizcityKnowledge.nonce,
                    character_id: characterId
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', 'Đã đăng lên BizCity Market thành công!');
                    } else {
                        this.showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Có lỗi xảy ra khi đăng');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Đăng lên BizCity Market');
                }
            });
        },
        
        refreshKnowledgeSources: function() {
            const characterId = $('#character-id').val();
            if (!characterId) return;
            
            $.ajax({
                url: bizcityKnowledge.restUrl + 'characters/' + characterId + '/knowledge',
                type: 'GET',
                headers: {
                    'X-WP-Nonce': bizcityKnowledge.restNonce
                },
                success: (sources) => {
                    const $list = $('.bizcity-knowledge-sources');
                    $list.find('.bizcity-source-item').remove();
                    
                    sources.forEach(source => {
                        const icon = this.getSourceIcon(source.source_type);
                        const html = `
                            <div class="bizcity-source-item">
                                <div class="source-icon">${icon}</div>
                                <div class="source-info">
                                    <div class="source-name">${this.escapeHtml(source.source_name)}</div>
                                    <div class="source-meta">${source.chunks_count} chunks • ${source.status}</div>
                                </div>
                                <div class="source-actions">
                                    <button class="button button-small delete-source" data-id="${source.id}">Xóa</button>
                                </div>
                            </div>
                        `;
                        $list.find('.bizcity-add-knowledge').before(html);
                    });
                }
            });
        },
        
        getSourceIcon: function(type) {
            const icons = {
                'quick_faq': '📝',
                'file': '📄',
                'url': '🌐',
                'fanpage': '📘'
            };
            return icons[type] || '📚';
        },
        
        showNotice: function(type, message) {
            const $notice = $(`<div class="bizcity-notice ${type}">${this.escapeHtml(message)}</div>`);
            $('.bizcity-knowledge-dashboard, .bizcity-character-form').prepend($notice);
            
            setTimeout(() => {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        },
        
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        BizCityKnowledge.init();
        
        // Handle duplicate character
        $(document).on('click', '.duplicate-character', function(e) {
            e.preventDefault();
            
            const $link = $(this);
            const characterId = $link.data('id');
            const characterName = $link.data('name');
            
            if (!confirm(`Bạn có chắc muốn nhân bản character "${characterName}"?\n\nCharacter mới sẽ bao gồm toàn bộ knowledge sources và chunks.`)) {
                return;
            }
            
            $link.html('⏳ Đang nhân bản...');
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_duplicate_character',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        if (response.data.redirect_url) {
                            window.location.href = response.data.redirect_url;
                        }
                    } else {
                        alert('Lỗi: ' + (response.data.message || 'Không thể nhân bản character'));
                        $link.html('Nhân bản');
                    }
                },
                error: function() {
                    alert('Lỗi kết nối! Vui lòng thử lại.');
                    $link.html('Nhân bản');
                }
            });
        });
        
        // Handle delete character
        $(document).on('click', '.delete-character', function(e) {
            e.preventDefault();
            
            const $link = $(this);
            const characterId = $link.data('id');
            const $row = $link.closest('tr');
            
            // Try to find character name from the row (different positions in different tables)
            let characterName = $row.find('strong').first().text().replace('#', '').trim();
            if (!characterName || /^\d+$/.test(characterName)) {
                characterName = 'character này';
            }
            
            if (!confirm(`Bạn có chắc muốn xóa "${characterName}"?\n\nThao tác này không thể hoàn tác!`)) {
                return;
            }
            
            $link.html('⏳ Đang xóa...');
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_delete_character',
                    nonce: bizcity_knowledge_vars.nonce,
                    id: characterId
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row with animation
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Lỗi: ' + (response.data.message || 'Không thể xóa character'));
                        $link.html('Xóa');
                    }
                },
                error: function() {
                    alert('Lỗi kết nối! Vui lòng thử lại.');
                    $link.html('Xóa');
                }
            });
        });
        
        // Handle import character JSON (both from dashboard and characters list)
        $(document).on('click', '#import-character-json-btn, #dashboard-import-json-btn', function(e) {
            e.preventDefault();
            
            // Create file input
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json';
            
            input.onchange = function(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    try {
                        const importData = JSON.parse(event.target.result);
                        
                        // Validate data structure
                        if (!importData.character || !importData.knowledge_sources) {
                            alert('File JSON không hợp lệ! Vui lòng chọn file export từ character.');
                            return;
                        }
                        
                        // Confirm import
                        const characterName = importData.character.name || 'Unknown';
                        const sourceCount = importData.knowledge_sources.length || 0;
                        
                        if (!confirm(
                            `Bạn muốn import character "${characterName}"?\n\n` +
                            `- ${sourceCount} nguồn kiến thức\n` +
                            `- Character mới sẽ được tạo với trạng thái "draft"`
                        )) {
                            return;
                        }
                        
                        performImportCharacter(importData);
                        
                    } catch (error) {
                        alert('File JSON không hợp lệ! Error: ' + error.message);
                    }
                };
                reader.readAsText(file);
            };
            
            input.click();
        });
        
        // Function to perform character import from JSON
        function performImportCharacter(importData) {
            // Show loading
            const $loadingMsg = $('<div class="notice notice-info" style="margin:20px 0;"><p>⏳ Đang kiểm tra và import character...</p></div>');
            $('.wrap').prepend($loadingMsg);
            
            // Check if slug/name exists and find unique name
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_check_slug',
                    nonce: bizcity_knowledge_vars.nonce,
                    name: importData.character.name,
                    slug: importData.character.slug
                },
                success: function(checkResponse) {
                    let finalName = importData.character.name;
                    let finalSlug = importData.character.slug;
                    
                    if (checkResponse.success && checkResponse.data.exists) {
                        // Slug exists, generate unique name/slug
                        finalName = checkResponse.data.suggested_name;
                        finalSlug = checkResponse.data.suggested_slug;
                        
                        $loadingMsg.find('p').text('⏳ Slug đã tồn tại. Import với tên mới: "' + finalName + '"...');
                    } else {
                        $loadingMsg.find('p').text('⏳ Đang tạo character và import knowledge...');
                    }
                    
                    // Create character and import knowledge
                    createCharacterAndImport(finalName, finalSlug, importData, $loadingMsg);
                },
                error: function() {
                    // If check fails, proceed with original name + (Imported)
                    const finalName = importData.character.name + ' (Imported)';
                    
                    $loadingMsg.find('p').text('⏳ Đang tạo character và import knowledge...');
                    
                    // Create character with (Imported) suffix
                    createCharacterAndImport(finalName, '', importData, $loadingMsg);
                }
            });
        }
        
        // Helper function to create character and import knowledge
        function createCharacterAndImport(characterName, slug, importData, $loadingMsg) {
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_save_character',
                    nonce: bizcity_knowledge_vars.nonce,
                    id: 0, // New character
                    name: characterName,
                    slug: slug || '', // Will be auto-generated if empty
                    avatar: importData.character.avatar || '',
                    description: importData.character.description || '',
                    system_prompt: importData.character.system_prompt || '',
                    model_id: importData.character.model_id || '',
                    creativity_level: importData.character.creativity_level || 0.7,
                    greeting_messages: importData.character.greeting_messages || '',
                    skills: importData.character.capabilities || [],
                    status: 'draft'
                },
                success: function(response) {
                    if (response.success) {
                        const newCharacterId = response.data.id;
                        
                        // Now import knowledge
                        $.ajax({
                            url: bizcity_knowledge_vars.ajaxurl,
                            method: 'POST',
                            data: {
                                action: 'bizcity_knowledge_import_knowledge',
                                nonce: bizcity_knowledge_vars.nonce,
                                character_id: newCharacterId,
                                import_data: JSON.stringify(importData),
                                overwrite: false
                            },
                            success: function(knowledgeResponse) {
                                $loadingMsg.remove();
                                
                                if (knowledgeResponse.success) {
                                    alert('✓ Import thành công!\n\nCharacter: ' + characterName + '\n' + knowledgeResponse.data.message);
                                    window.location.href = bizcity_knowledge_vars.ajaxurl.replace('/wp-admin/admin-ajax.php', '/wp-admin/admin.php?page=bizcity-knowledge-character-edit&id=' + newCharacterId);
                                } else {
                                    alert('Character đã tạo nhưng lỗi import knowledge:\n' + (knowledgeResponse.data.message || 'Unknown error'));
                                    window.location.href = bizcity_knowledge_vars.ajaxurl.replace('/wp-admin/admin-ajax.php', '/wp-admin/admin.php?page=bizcity-knowledge-characters');
                                }
                            },
                            error: function() {
                                $loadingMsg.remove();
                                alert('Character đã tạo nhưng lỗi import knowledge. Vui lòng import lại từ character edit page.');
                                window.location.href = bizcity_knowledge_vars.ajaxurl.replace('/wp-admin/admin-ajax.php', '/wp-admin/admin.php?page=bizcity-knowledge-characters');
                            }
                        });
                        
                    } else {
                        $loadingMsg.remove();
                        alert('Lỗi tạo character:\n' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function() {
                    $loadingMsg.remove();
                    alert('Lỗi kết nối! Vui lòng thử lại.');
                }
            });
        }
    });

})(jQuery);
