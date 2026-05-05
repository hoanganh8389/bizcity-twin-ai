/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Core\Knowledge — Character Edit JavaScript
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @license GPL-2.0-or-later | https://bizcity.vn
 */

/**
 * Character Edit Page JavaScript
 */

(function($) {
    'use strict';
    
    const CharacterEdit = {
        
        init() {
            this.bindEvents();
            this.initTabs();
            this.initAvatarSelector();
            this.initEditableTables();
            this.initPromptTemplates();
            this.initUploadAreas();
            this.initModelTab();
            this.initGreetingMessages();
            this.initChunksViewer();
            this.initLegacyFAQ();
            this.updatePreview();
        },
        
        bindEvents() {
            // Save character
            $('#save-character-btn').on('click', (e) => {
                e.preventDefault();
                this.saveCharacter();
            });
            
            // Export knowledge
            $('#export-knowledge-btn').on('click', (e) => {
                e.preventDefault();
                this.exportKnowledge();
            });
            
            // Import knowledge
            $('#import-knowledge-btn').on('click', (e) => {
                e.preventDefault();
                this.importKnowledge();
            });
            
            // Character name change - update header
            $('#character-name').on('input', (e) => {
                $('#header-title').text(e.target.value || 'Tạo AI Character Mới');
            });
            
            // Avatar upload
            $('#select-avatar').on('click', () => this.selectAvatar());
            $('#avatar-url').on('change', () => this.updateAvatarPreview());
        },
        
        // Tabs Management
        initTabs() {
            $('.bk-tab-btn').on('click', function() {
                const tab = $(this).data('tab');
                CharacterEdit.switchTab(tab);
            });
        },
        
        switchTab(tabName) {
            $('.bk-tab-btn').removeClass('active');
            $('.bk-tab-btn[data-tab="' + tabName + '"]').addClass('active');
            
            $('.bk-tab-content').removeClass('active');
            $('#tab-' + tabName).addClass('active');
        },
        
        // Avatar Selector
        initAvatarSelector() {
            $('#avatar-url').on('input', () => this.updateAvatarPreview());
        },
        
        selectAvatar() {
            const mediaUploader = wp.media({
                title: 'Chọn Avatar',
                button: { text: 'Chọn ảnh này' },
                multiple: false,
                library: { type: 'image' }
            });
            
            mediaUploader.on('select', () => {
                const attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#avatar-url').val(attachment.url);
                this.updateAvatarPreview();
            });
            
            mediaUploader.open();
        },
        
        updateAvatarPreview() {
            const avatarUrl = $('#avatar-url').val();
            const $preview = $('#avatar-preview');
            const $headerPreview = $('#header-avatar-preview');
            
            if (avatarUrl) {
                $preview.html('<img src="' + avatarUrl + '" alt="">');
                $headerPreview.html('<img src="' + avatarUrl + '" alt="">');
            } else {
                $preview.html('<span class="bk-avatar-placeholder-large">📷</span>');
                $headerPreview.html('<span class="bk-avatar-placeholder">👤</span>');
            }
        },
        
        // Prompt Templates
        initPromptTemplates() {
            const self = this;
            // Use event delegation for better reliability
            $(document).on('click', '.bk-insert-template', function(e) {
                e.preventDefault();
                const template = $(this).data('template');
                console.log('Template clicked:', template);
                self.insertPromptTemplate(template);
            });
        },
        
        insertPromptTemplate(template) {
            const templates = {
                'customer-support': `Role: Customer Support Expert

Objective:
• Provide information about products and services
• Resolve customer issues quickly and effectively
• Collect feedback and improve customer satisfaction

Tone: Friendly & Professional

Guidelines:
• Always greet customers warmly and professionally
• Listen actively to understand their concerns
• Provide clear, accurate information
• Show empathy and patience
• Follow up to ensure satisfaction
• Maintain a positive attitude even in difficult situations`,

                'marketing': `Role: Marketing Expert

Objective:
• Develop effective marketing strategies
• Analyze market trends and consumer behavior
• Create compelling campaigns that drive engagement
• Optimize marketing ROI

Tone: Creative & Strategic

Guidelines:
• Think data-driven and creative simultaneously
• Understand target audience deeply
• Focus on value proposition and unique selling points
• Leverage multiple channels for maximum reach
• Measure and optimize campaign performance
• Stay updated with latest marketing trends`,

                'sales': `Role: Sales Expert

Objective:
• Build strong relationships with customers
• Understand customer needs and provide solutions
• Close deals effectively
• Achieve sales targets consistently

Tone: Persuasive & Consultative

Guidelines:
• Ask questions to understand customer pain points
• Present solutions that match customer needs
• Highlight product benefits, not just features
• Handle objections professionally
• Create urgency without being pushy
• Follow up persistently but respectfully`,

                'seo': `Role: SEO Expert

Objective:
• Improve website ranking on search engines
• Analyze website content and provide optimization suggestions
• Stay updated with SEO best practices and algorithm changes

Tone: Academic & Scholarly

Guidelines:
• Use precise terminology and well-structured arguments
• Reference credible sources and evidence-based information
• Encourage critical thinking and intellectual curiosity
• Present information in an educational, research-oriented manner
• Provide actionable recommendations based on data
• Explain technical concepts clearly`,

                'content': `Role: Content Writer

Objective:
• Create engaging, valuable content for target audience
• Write SEO-friendly articles that rank well
• Maintain consistent brand voice
• Drive traffic and engagement

Tone: Engaging & Informative

Guidelines:
• Research topics thoroughly before writing
• Use storytelling to make content memorable
• Write clear headlines that grab attention
• Structure content for easy scanning
• Include relevant keywords naturally
• Edit ruthlessly for clarity and impact`,

                'social-media': `Role: Social Media Manager

Objective:
• Create engaging social media content
• Build and grow online community
• Monitor trends and conversations
• Drive brand awareness and engagement

Tone: Trendy & Conversational

Guidelines:
• Stay on top of current trends and viral content
• Engage with followers authentically
• Use visuals and multimedia effectively
• Post consistently and at optimal times
• Monitor analytics and adjust strategy
• Handle comments and messages promptly`,

                'tam-ly': `Role: Chuyên gia Tâm lý học

Mục tiêu:
• Lắng nghe và thấu hiểu cảm xúc, tâm trạng của người cần tư vấn
• Cung cấp góc nhìn khách quan và chuyên môn về các vấn đề tâm lý
• Hỗ trợ phát triển kỹ năng quản lý cảm xúc và cải thiện sức khỏe tinh thần
• Đưa ra lời khuyên dựa trên các nguyên lý tâm lý học

Phong cách: Đồng cảm & Chuyên nghiệp

Hướng dẫn:
• Tạo không gian an toàn để người dùng chia sẻ
• Lắng nghe tích cực không phán xét
• Đặt câu hỏi mở để hiểu sâu hơn
• Cung cấp góc nhìn dựa trên các lý thuyết tâm lý
• Khuyến khích tự nhận thức và phát triển bản thân
• Giữ bí mật và tôn trọng quyền riêng tư`,

                'dinh-duong': `Role: Chuyên gia Dinh dưỡng

Mục tiêu:
• Tư vấn chế độ dinh dưỡng phù hợp với từng đối tượng
• Cung cấp kiến thức về thực phẩm, calories và chất dinh dưỡng
• Hỗ trợ xây dựng thực đơn khoa học và lành mạnh
• Giúp cải thiện sức khỏe thông qua ăn uống

Phong cách: Khoa học & Thực tiễn

Hướng dẫn:
• Đưa ra lời khuyên dựa trên nghiên cứu khoa học
• Tính toán nhu cầu dinh dưỡng cá nhân hóa
• Đề xuất thực đơn cân bằng và đa dạng
• Giải thích rõ ràng về vai trò của từng nhóm thực phẩm
• Tôn trọng sở thích ăn uống và văn hóa
• Khuyến khích thói quen ăn uống lành mạnh bền vững`,

                'kinh-dich': `Role: Chuyên gia Kinh Dịch

Mục tiêu:
• Giải đáp thắc mắc về vận mệnh, tương lai dựa trên Kinh Dịch
• Phân tích quẻ và đưa ra lời khuyên về quyết định cuộc sống
• Giúp người hỏi hiểu rõ bản thân và định hướng phát triển
• Kết nối trí tuệ cổ đại với cuộc sống hiện đại

Phong cách: Triết lý & Sâu sắc

Hướng dẫn:
• Sử dụng ngôn ngữ dễ hiểu nhưng mang tính triết lý
• Giải thích ý nghĩa các quẻ một cách rõ ràng
• Liên hệ với tình huống thực tế của người hỏi
• Khuyến khích suy ngẫm và tự nhận thức
• Đưa ra lời khuyên mang tính định hướng, không áp đặt
• Tôn trọng niềm tin và quan điểm cá nhân`,

                'tarot': `Role: Chuyên gia Tarot

Mục tiêu:
• Đọc và giải nghĩa các lá bài Tarot một cách chính xác
• Cung cấp guidance về các vấn đề tình cảm, sự nghiệp, tài chính
• Giúp người hỏi nhìn nhận tình huống từ nhiều góc độ
• Khơi gợi trực giác và sự tự nhận thức

Phong cách: Trực giác & Huyền bí

Hướng dẫn:
• Giải thích ý nghĩa của từng lá bài trong ngữ cảnh cụ thể
• Kết hợp các lá bài để tạo câu chuyện liền mạch
• Đặt câu hỏi để hiểu rõ vấn đề của người hỏi
• Sử dụng ngôn ngữ mang tính gợi mở, không tuyệt đối
• Khuyến khích người hỏi tin vào trực giác của bản thân
• Nhấn mạnh rằng tương lai có thể thay đổi bởi hành động`,

                'bai-tay': `Role: Chuyên gia Bói Bài Tây

Mục tiêu:
• Đọc và giải nghĩa bộ bài 52 lá (bài Tây) theo truyền thống
• Dự đoán vận mệnh về tình cảm, công việc, tài chính, sức khỏe
• Phân tích tính cách và đặc điểm của người hỏi qua lá bài đại diện
• Tư vấn hướng đi phù hợp dựa trên kết quả bói

Phong cách: Bí ẩn & Truyền thống

Hướng dẫn:
• Giải thích ý nghĩa từng chất (Cơ, Rô, Chuồn, Bích) và số/hình
• Cơ (♥): Tình cảm, gia đình, hạnh phúc
• Rô (♦): Tiền bạc, tài chính, vật chất
• Chuồn (♣): Công việc, sự nghiệp, phát triển
• Bích (♠): Thử thách, biến động, cảnh báo
• Kết hợp các lá bài để đưa ra dự đoán tổng quan
• Sử dụng các trải bài phổ biến (3 lá, 5 lá, 7 lá)
• Giữ thái độ tích cực ngay cả khi có lá bài không thuận lợi`,

                'chiem-tinh': `Role: Chuyên gia Chiêm Tinh học

Mục tiêu:
• Phân tích cung hoàng đạo và bản đồ sao của người hỏi
• Dự báo vận trình theo các giai đoạn hành tinh
• Tư vấn về tình cảm, sự nghiệp, sức khỏe dựa trên vị trí các hành tinh
• Giải thích mối quan hệ tương hợp giữa các cung

Phong cách: Khoa học Huyền bí & Học thuật

Hướng dẫn:
• Phân tích 12 cung hoàng đạo và đặc điểm tính cách
• Giải thích ảnh hưởng của Mặt Trời, Mặt Trăng, các hành tinh
• Rising Sign (cung mọc), Moon Sign và ý nghĩa
• Phân tích các nhà (houses) trong bản đồ sao
• Giải thích các góc chiếu (aspects) giữa các hành tinh
• Dự báo theo chu kỳ Thủy Nghịch, Trăng Tròn, Trăng Non
• Tư vấn ngày/giờ tốt cho các hoạt động quan trọng
• Phân tích độ tương hợp giữa hai người (synastry)`,

                'data-analyst': `Role: Data Analyst

Objective:
• Analyze data to uncover insights and trends
• Create clear visualizations and reports
• Provide data-driven recommendations
• Support decision-making with statistical evidence

Tone: Analytical & Precise

Guidelines:
• Always base conclusions on data evidence
• Present findings clearly with charts and numbers
• Identify patterns, trends, and anomalies
• Use appropriate statistical methods
• Communicate complex data in simple terms
• Consider context and business implications`,

                'business': `Role: Business Consultant

Objective:
• Analyze business challenges and opportunities
• Develop strategic solutions and action plans
• Improve business processes and efficiency
• Drive growth and profitability

Tone: Strategic & Professional

Guidelines:
• Understand the business context thoroughly
• Ask probing questions to identify root causes
• Provide practical, actionable recommendations
• Consider both short-term and long-term implications
• Use frameworks and best practices
• Focus on measurable outcomes`,

                'personal-coach': `Role: Personal Coach

Objective:
• Help individuals achieve their personal goals
• Build confidence and self-awareness
• Develop action plans for personal growth
• Provide motivation and accountability

Tone: Motivational & Supportive

Guidelines:
• Ask powerful questions that promote reflection
• Listen actively and without judgment
• Help clients identify their values and priorities
• Break down big goals into actionable steps
• Celebrate progress and learn from setbacks
• Hold clients accountable with compassion`,

                'recruiter': `Role: Recruiter

Objective:
• Identify and attract top talent
• Assess candidates' skills and cultural fit
• Provide excellent candidate experience
• Build strong talent pipeline

Tone: Professional & Engaging

Guidelines:
• Understand job requirements thoroughly
• Ask behavioral and situational questions
• Evaluate both technical and soft skills
• Communicate clearly about process and timeline
• Sell the company and opportunity effectively
• Maintain candidate relationships`,

                'finance': `Role: Finance Expert

Objective:
• Provide financial analysis and advice
• Help with investment decisions
• Explain complex financial concepts
• Support financial planning and management

Tone: Professional & Trustworthy

Guidelines:
• Base advice on solid financial principles
• Consider risk tolerance and financial goals
• Explain concepts clearly with examples
• Stay updated on market trends and regulations
• Provide balanced perspective on opportunities and risks
• Emphasize importance of diversification and planning`
            };
            
            const promptText = templates[template] || '';
            console.log('Template found:', template, 'Text length:', promptText.length);
            if (promptText) {
                const $textarea = $('#system-prompt');
                console.log('Textarea found:', $textarea.length);
                $textarea.val(promptText).trigger('change');
            } else {
                console.warn('Template not found:', template);
            }
        },
        
        // Editable Tables
        initEditableTables() {
            // Quick Knowledge table
            this.initQuickKnowledgeTable();
            
            // FAQs table
            this.initFaqsTable();
        },
        
        initQuickKnowledgeTable() {
            const $tbody = $('#quick-knowledge-tbody');

            // Add new row
            $('#add-quick-knowledge-row').on('click', () => {
                this.addQuickKnowledgeRow();
            });

            // Delete row — Sprint 0.18.A.3: AJAX-delete row from DB instead of just DOM.
            $tbody.on('click', '.bk-row-delete', function() {
                if (!confirm('Delete this row?')) return;
                const $row      = $(this).closest('tr');
                const sourceId  = parseInt($row.attr('data-id'), 10) || 0;
                const charId    = parseInt($('#character-id').val(), 10) || 0;

                // Unsaved row (id=0) → just remove from DOM, no AJAX needed.
                if (sourceId <= 0 || charId <= 0) {
                    $row.remove();
                    CharacterEdit.updateRowNumbers('#quick-knowledge-tbody');
                    CharacterEdit.updateQuickKnowledgeData();
                    CharacterEdit.refreshFaqTabCount();
                    return;
                }

                $row.css('opacity', .5);
                $.post(bizcity_knowledge_vars.ajaxurl, {
                    action:       'bizcity_knowledge_quick_faq_delete',
                    nonce:        bizcity_knowledge_vars.nonce,
                    character_id: charId,
                    source_id:    sourceId
                }).done((response) => {
                    if (response && response.success) {
                        $row.fadeOut(200, function() {
                            $(this).remove();
                            CharacterEdit.updateRowNumbers('#quick-knowledge-tbody');
                            CharacterEdit.updateQuickKnowledgeData();
                            CharacterEdit.refreshFaqTabCount();
                        });
                    } else {
                        $row.css('opacity', 1);
                        CharacterEdit.showMessage(
                            (response && response.data && response.data.message) || 'Delete failed!',
                            'error'
                        );
                    }
                }).fail(() => {
                    $row.css('opacity', 1);
                    CharacterEdit.showMessage('Connection error during delete!', 'error');
                });
            });

            // Auto-save on blur — Sprint 0.18.A.3: per-row AJAX upsert.
            $tbody.on('blur', '.bk-editable', function() {
                CharacterEdit.updateQuickKnowledgeData();
                CharacterEdit.autoSaveQuickKnowledgeRow($(this).closest('tr'));
            });

            // Import/Export
            $('#import-quick-knowledge').on('click', () => this.importQuickKnowledge());
            $('#export-quick-knowledge').on('click', () => this.exportQuickKnowledge());

            // Remove empty row if exists
            if ($tbody.find('.bk-editable-row').length > 0) {
                $tbody.find('.bk-empty-row').remove();
            }
        },

        /**
         * Sprint 0.18.A.3 — Persist a single Quick Knowledge row through wp_ajax.
         * Idempotent: skips empty rows; switches between INSERT/UPDATE based on data-id.
         * Updates the inline status indicator (saving / saved / error).
         */
        autoSaveQuickKnowledgeRow($row) {
            if (!$row || !$row.length) return;
            const charId = parseInt($('#character-id').val(), 10) || 0;
            if (charId <= 0) {
                this.showMessage('Please save the Twin Guru first (Save Changes) to generate an ID.', 'warning');
                return;
            }

            const title    = $row.find('[data-field="title"]').text().trim();
            const content  = $row.find('[data-field="content"]').text().trim();
            const sourceId = parseInt($row.attr('data-id'), 10) || 0;

            // Empty row → don't waste a request.
            if (!title && !content) {
                this.setRowStatus($row, '');
                return;
            }

            // Skip if nothing changed since last save.
            const sig = title + '\u0001' + content;
            if ($row.data('lastSig') === sig) return;

            this.setRowStatus($row, 'saving');

            $.post(bizcity_knowledge_vars.ajaxurl, {
                action:       'bizcity_knowledge_quick_faq_upsert',
                nonce:        bizcity_knowledge_vars.nonce,
                character_id: charId,
                source_id:    sourceId,
                title:        title,
                content:      content
            }).done((response) => {
                if (response && response.success && response.data && response.data.id) {
                    $row.attr('data-id', response.data.id).data('id', response.data.id);
                    $row.data('lastSig', sig);
                    this.setRowStatus($row, 'saved');
                    this.refreshFaqTabCount();
                } else {
                    const msg = (response && response.data && response.data.message) || 'unknown';
                    this.setRowStatus($row, 'error', msg);
                }
            }).fail(() => {
                this.setRowStatus($row, 'error', 'network');
            });
        },

        setRowStatus($row, state, detail) {
            // Indicator lives in the row-number cell so we don't reshape the table.
            let $cell = $row.find('.bk-row-status');
            if (!$cell.length) {
                $cell = $('<span class="bk-row-status" style="margin-left:6px;font-size:11px;"></span>');
                $row.find('.bk-row-number').append($cell);
            }
            const map = {
                saving: { html: '⏳', color: '#9ca3af', title: 'Saving…' },
                saved:  { html: '✓',  color: '#10b981', title: 'Saved' },
                error:  { html: '✗',  color: '#d02828', title: 'Error: ' + (detail || '') },
                '':     { html: '',   color: '',         title: '' }
            };
            const m = map[state] || map[''];
            $cell.html(m.html).css('color', m.color).attr('title', m.title);
        },

        refreshFaqTabCount() {
            const n = $('#quick-knowledge-tbody .bk-editable-row').filter(function () {
                return $(this).find('[data-field="title"]').text().trim()
                    || $(this).find('[data-field="content"]').text().trim();
            }).length;
            $('.bk-tab-btn[data-tab="quick-knowledge"] .bk-tab-count').text(n);
            $('#qk-row-count').text(n);
        },
        
        addQuickKnowledgeRow() {
            const $tbody = $('#quick-knowledge-tbody');
            $tbody.find('.bk-empty-row').remove();
            
            const rowCount = $tbody.find('.bk-editable-row').length + 1;
            const newRow = `
                <tr class="bk-editable-row" data-id="0">
                    <td class="bk-row-number">${rowCount}</td>
                    <td class="bk-editable" contenteditable="true" data-field="title" data-placeholder="Enter title..."></td>
                    <td class="bk-editable" contenteditable="true" data-field="content" data-placeholder="Enter content..."></td>
                    <td>
                        <button type="button" class="bk-row-delete" title="Delete">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            `;
            
            $tbody.append(newRow);
            $tbody.find('tr:last .bk-editable').first().focus();
            this.updateQuickKnowledgeData();
        },
        
        updateQuickKnowledgeData() {
            const data = [];
            $('#quick-knowledge-tbody .bk-editable-row').each(function() {
                const $row = $(this);
                const title = $row.find('[data-field="title"]').text().trim();
                const content = $row.find('[data-field="content"]').text().trim();
                
                if (title || content) {
                    data.push({
                        id: $row.data('id') || 0,
                        title: title,
                        content: content
                    });
                }
            });
            
            $('#quick-knowledge-data').val(JSON.stringify(data));
            $('#qk-row-count').text(data.length);
        },
        
        importQuickKnowledge() {
            $('#import-file-input').off('change').on('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;
                
                this.parseImportFile(file, (rows) => {
                    const $tbody = $('#quick-knowledge-tbody');
                    $tbody.find('.bk-empty-row').remove();
                    
                    rows.forEach((row, index) => {
                        if (row.length >= 2) {
                            const newRow = `
                                <tr class="bk-editable-row" data-id="0">
                                    <td class="bk-row-number">${index + 1}</td>
                                    <td class="bk-editable" contenteditable="true" data-field="title">${this.escapeHtml(row[0])}</td>
                                    <td class="bk-editable" contenteditable="true" data-field="content">${this.escapeHtml(row[1])}</td>
                                    <td>
                                        <button type="button" class="bk-row-delete" title="Xóa">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            $tbody.append(newRow);
                        }
                    });
                    
                    this.updateQuickKnowledgeData();
                    this.showMessage('Import thành công ' + rows.length + ' dòng!', 'success');
                });
            });
            
            $('#import-file-input').click();
        },
        
        exportQuickKnowledge() {
            const data = [];
            $('#quick-knowledge-tbody .bk-editable-row').each(function() {
                const $row = $(this);
                const title = $row.find('[data-field="title"]').text().trim();
                const content = $row.find('[data-field="content"]').text().trim();
                
                if (title || content) {
                    data.push([title, content]);
                }
            });
            
            if (data.length === 0) {
                alert('Không có dữ liệu để export!');
                return;
            }
            
            // Add header
            data.unshift(['Title', 'Content']);
            
            // Create CSV
            const csv = data.map(row => row.map(cell => '"' + (cell || '').replace(/"/g, '""') + '"').join(',')).join('\n');
            
            // Download
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'quick-knowledge-' + Date.now() + '.csv';
            link.click();
        },
        
        // FAQs Table
        initFaqsTable() {
            const $tbody = $('#faqs-tbody');
            
            // Add new row
            $('#add-faq-row').on('click', () => {
                this.addFaqRow();
            });
            
            // Delete row
            $tbody.on('click', '.bk-row-delete', function() {
                if (confirm('Xóa FAQ này?')) {
                    $(this).closest('tr').remove();
                    CharacterEdit.updateRowNumbers('#faqs-tbody');
                    CharacterEdit.updateFaqsData();
                }
            });
            
            // Select all
            $('#faqs-select-all').on('change', function() {
                $tbody.find('input[type="checkbox"]').prop('checked', this.checked);
                CharacterEdit.updateDeleteButton();
            });
            
            $tbody.on('change', 'input[type="checkbox"]', () => {
                this.updateDeleteButton();
            });
            
            // Delete selected
            $('#delete-selected-faqs').on('click', () => {
                if (confirm('Xóa các FAQ đã chọn?')) {
                    $tbody.find('input[type="checkbox"]:checked').closest('tr').remove();
                    this.updateRowNumbers('#faqs-tbody');
                    this.updateFaqsData();
                    this.updateDeleteButton();
                }
            });
            
            // Auto-save on blur
            $tbody.on('blur', '.bk-editable', () => {
                this.updateFaqsData();
            });
            
            // Import/Export
            $('#import-faqs').on('click', () => this.importFaqs());
            $('#export-faqs').on('click', () => this.exportFaqs());
        },
        
        addFaqRow() {
            const $tbody = $('#faqs-tbody');
            $tbody.find('.bk-empty-row').remove();
            
            const rowCount = $tbody.find('.bk-editable-row').length + 1;
            const newRow = `
                <tr class="bk-editable-row" data-id="0">
                    <td class="bk-row-number">${rowCount}</td>
                    <td><input type="checkbox"></td>
                    <td class="bk-editable" contenteditable="true" data-field="question" data-placeholder="Nhập câu hỏi..."></td>
                    <td class="bk-editable" contenteditable="true" data-field="answer" data-placeholder="Nhập câu trả lời..."></td>
                    <td>
                        <button type="button" class="bk-row-delete" title="Xóa">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            `;
            
            $tbody.append(newRow);
            $tbody.find('tr:last .bk-editable').first().focus();
            this.updateFaqsData();
        },
        
        updateFaqsData() {
            const data = [];
            $('#faqs-tbody .bk-editable-row').each(function() {
                const $row = $(this);
                const question = $row.find('[data-field="question"]').text().trim();
                const answer = $row.find('[data-field="answer"]').text().trim();
                
                if (question || answer) {
                    data.push({
                        id: $row.data('id') || 0,
                        question: question,
                        answer: answer
                    });
                }
            });
            
            $('#faqs-data').val(JSON.stringify(data));
            $('#faq-row-count').text(data.length);
        },
        
        updateDeleteButton() {
            const checkedCount = $('#faqs-tbody input[type="checkbox"]:checked').length;
            $('#delete-selected-faqs').prop('disabled', checkedCount === 0);
        },
        
        importFaqs() {
            $('#import-file-input').off('change').on('change', (e) => {
                const file = e.target.files[0];
                if (!file) return;
                
                this.parseImportFile(file, (rows) => {
                    const $tbody = $('#faqs-tbody');
                    $tbody.find('.bk-empty-row').remove();
                    
                    rows.forEach((row, index) => {
                        if (row.length >= 2) {
                            const newRow = `
                                <tr class="bk-editable-row" data-id="0">
                                    <td class="bk-row-number">${index + 1}</td>
                                    <td><input type="checkbox"></td>
                                    <td class="bk-editable" contenteditable="true" data-field="question">${this.escapeHtml(row[0])}</td>
                                    <td class="bk-editable" contenteditable="true" data-field="answer">${this.escapeHtml(row[1])}</td>
                                    <td>
                                        <button type="button" class="bk-row-delete" title="Xóa">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                            `;
                            $tbody.append(newRow);
                        }
                    });
                    
                    this.updateFaqsData();
                    this.showMessage('Import thành công ' + rows.length + ' FAQs!', 'success');
                });
            });
            
            $('#import-file-input').click();
        },
        
        exportFaqs() {
            const data = [];
            $('#faqs-tbody .bk-editable-row').each(function() {
                const $row = $(this);
                const question = $row.find('[data-field="question"]').text().trim();
                const answer = $row.find('[data-field="answer"]').text().trim();
                
                if (question || answer) {
                    data.push([question, answer]);
                }
            });
            
            if (data.length === 0) {
                alert('Không có dữ liệu để export!');
                return;
            }
            
            // Add header
            data.unshift(['Question', 'Answer']);
            
            // Create CSV
            const csv = data.map(row => row.map(cell => '"' + (cell || '').replace(/"/g, '""') + '"').join(',')).join('\n');
            
            // Download
            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'faqs-' + Date.now() + '.csv';
            link.click();
        },
        
        // Upload Areas
        initUploadAreas() {
            const $uploadArea = $('#document-upload-area');
            
            $uploadArea.on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('dragging');
            });
            
            $uploadArea.on('dragleave', function() {
                $(this).removeClass('dragging');
            });
            
            $uploadArea.on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('dragging');
                
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    CharacterEdit.uploadDocuments(files);
                }
            });
            
            $('#browse-documents').on('click', () => this.browseDocuments());
            
            // Website input
            $('#add-website').on('click', () => this.addWebsite());
            
            // Input mode tabs
            $('.bk-input-tab-btn').on('click', function() {
                $('.bk-input-tab-btn').removeClass('active');
                $(this).addClass('active');
            });
            
            // Delete handlers
            $('.bk-documents-list').on('click', '.bk-doc-delete', function() {
                if (confirm('Delete this document?')) {
                    const id = $(this).data('id');
                    CharacterEdit.deleteKnowledgeSource(id, $(this).closest('.bk-document-item'));
                }
            });
            
            $('.bk-websites-list').on('click', '.bk-web-delete', function() {
                if (confirm('Delete this website?')) {
                    const id = $(this).data('id');
                    CharacterEdit.deleteWebsite(id, $(this).closest('.bk-website-item'));
                }
            });
            
            // Process website button
            $('.bk-websites-list').on('click', '.bk-web-process', function() {
                const id = $(this).data('id');
                const $item = $(this).closest('.bk-website-item');
                CharacterEdit.wsConsole('info', 'Manual Process clicked source_id=' + id);
                CharacterEdit.processWebsite(id, $item);
            });

            // Sprint 0.18.A.4 — console clear
            $(document).on('click', '#website-console-clear', function (e) {
                e.preventDefault();
                $('#website-console-body').empty();
                CharacterEdit.wsConsole('info', '[cleared]');
            });
        },
        
        browseDocuments() {
            const mediaUploader = wp.media({
                title: 'Select Documents',
                button: { text: 'Select File' },
                multiple: true
            });
            
            mediaUploader.on('select', () => {
                const attachments = mediaUploader.state().get('selection').toJSON();
                this.uploadDocuments(attachments);
            });
            
            mediaUploader.open();
        },
        
        uploadDocuments(files) {
            console.log('Upload documents:', files);

            const characterId = $('#character-id').val();
            console.log('Character ID:', characterId);

            if (!characterId || characterId === '0') {
                this.showMessage('Please save the Twin Guru before uploading files!', 'error');
                return;
            }

            // Sprint 0.18.A.4 — progress dialog so user thinks process is alive.
            const total = Array.isArray(files) ? files.length : 0;
            this.uploadProgressOpen('Uploading & embedding ' + total + ' file(s)…');
            this.uploadProgressLog('upload', 'POST bizcity_knowledge_upload_document (' + total + ' file)');
            this.uploadProgressSet(10);

            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_upload_document',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId,
                    files: JSON.stringify(files)
                },
                xhr: () => {
                    const x = $.ajaxSettings.xhr();
                    if (x.upload) {
                        x.upload.addEventListener('progress', (ev) => {
                            if (ev.lengthComputable) {
                                const pct = 10 + (ev.loaded / ev.total) * 40;
                                this.uploadProgressSet(pct);
                            }
                        });
                    }
                    return x;
                },
                success: (response) => {
                    if (typeof response !== 'object') {
                        this.uploadProgressLog('error', 'Server returned an invalid response');
                        this.uploadProgressDone(false);
                        this.showMessage('Server error: Invalid response', 'error');
                        return;
                    }

                    if (response.success) {
                        this.uploadProgressSet(80);
                        const docs = (response.data && response.data.documents) || [];
                        this.uploadProgressLog('ok', 'Upload complete: ' + docs.length + ' file(s). Embedding…');

                        // Prepend so newest sits at top.
                        docs.forEach((doc) => {
                            this.uploadProgressLog('ok', '• ' + (doc.name || doc.id) + ' (status=' + (doc.status || '?') + ')');
                            this.addDocumentToList(doc);
                        });

                        this.uploadProgressSet(100);
                        this.uploadProgressDone(true);
                        this.showMessage((response.data && response.data.message) || 'Upload successful!', 'success');
                    } else {
                        const msg = (response.data && response.data.message) || 'Upload failed!';
                        this.uploadProgressLog('error', msg);
                        this.uploadProgressDone(false);
                        this.showMessage(msg, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.uploadProgressLog('error', 'Connection error: ' + error);
                    this.uploadProgressDone(false);
                    this.showMessage('Connection error: ' + error, 'error');
                }
            });
        },

        uploadProgressOpen(title) {
            $('#upload-progress-title').text(title || 'Processing…');
            $('#upload-progress-counter').text('');
            $('#upload-progress-fill').css('width', '0%');
            $('#upload-progress-log').empty();
            $('#upload-progress-panel').slideDown(150);
        },
        uploadProgressSet(pct) {
            const v = Math.max(0, Math.min(100, Math.round(pct)));
            $('#upload-progress-fill').css('width', v + '%');
            $('#upload-progress-counter').text(v + '%');
        },
        uploadProgressLog(type, msg) {
            const stamp = new Date().toLocaleTimeString();
            const $li = $('<li class="bk-progress-line bk-progress-' + (type || 'info') + '"></li>')
                .text('[' + stamp + '] ' + msg);
            $('#upload-progress-log').append($li);
            const $body = $('#upload-progress-log');
            $body.scrollTop($body.prop('scrollHeight'));
        },
        uploadProgressDone(ok) {
            $('#upload-progress-title').text(ok ? 'Done ✓' : 'Error ✗');
            $('#upload-progress-panel').find('.dashicons-update').removeClass('bk-spin');
            setTimeout(() => {
                $('#upload-progress-panel').slideUp(250, function () {
                    $(this).find('.dashicons-update').addClass('bk-spin');
                });
            }, ok ? 4000 : 8000);
        },

        addDocumentToList(doc) {
            const html = `
                <div class="bk-document-item" data-id="${doc.id}">
                    <div class="bk-doc-icon">📄</div>
                    <div class="bk-doc-info">
                        <div class="bk-doc-name">${doc.name}</div>
                        <div class="bk-doc-meta">
                            <span class="bk-doc-status bk-status-${doc.status}">${doc.status}</span>
                            <span class="bk-doc-date">${doc.date}</span>
                        </div>
                    </div>
                    <button type="button" class="bk-doc-delete" data-id="${doc.id}">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
            `;

            // Sprint 0.18.A.4 — prepend so newest sits on top.
            $('#documents-list').prepend(html);
        },
        
        addWebsite() {
            const url = $('#website-url').val().trim();
            const mode = $('.bk-input-tab-btn.active').data('mode') || 'single';
            const characterId = $('#character-id').val();

            if (!url) {
                this.wsConsole('error', 'URL is empty — enter a link and click Add link.');
                return;
            }

            if (!characterId || characterId === '0') {
                this.wsConsole('error', 'No character_id — save the Twin Guru first.');
                return;
            }

            try { new URL(url); }
            catch (e) {
                this.wsConsole('error', 'Invalid URL: ' + url);
                return;
            }

            const $btn = $('#add-website');
            $btn.prop('disabled', true).text('Processing…');

            this.wsConsole('info', 'POST bizcity_knowledge_add_website mode=' + mode + ' url=' + url);
            const t0 = performance.now();

            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_add_website',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId,
                    url: url,
                    mode: mode
                },
                success: (response) => {
                    const dt = (performance.now() - t0).toFixed(0);
                    if (response.success) {
                        this.wsConsole('ok', 'Added (' + dt + 'ms): ' + (response.data.message || ''));
                        $('#website-url').val('');

                        // Sprint 0.18.A.4 — don't reload; auto-trigger Process for each new source.
                        const ids = (response.data && response.data.source_ids) || [];
                        if (ids.length) {
                            this.wsConsole('info', '→ auto Process ' + ids.length + ' source_id(s)…');
                            let pending = ids.length;
                            const onDone = (result) => {
                                if (result && result.success) {
                                    this.addWebsiteToList(result.sourceId, result.url, result.title, result.chunks_count);
                                }
                                if (--pending <= 0) {
                                    this.wsConsole('ok', 'All sources processed — list updated.');
                                    this.refreshWebsiteTabCount();
                                }
                            };
                            ids.forEach((sid, i) => {
                                setTimeout(() => this.processWebsiteHeadless(parseInt(sid, 10), url, onDone), 200 + i * 250);
                            });
                        } else {
                            this.wsConsole('warn', 'Server returned no source_ids. Please refresh the page manually to see the new row.');
                        }
                    } else {
                        const msg = (response.data && response.data.message) || 'Lỗi thêm website!';
                        this.wsConsole('error', 'Failed: ' + msg);
                        this.showMessage(msg, 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.wsConsole('error', 'Lỗi AJAX: ' + error + ' (status=' + status + ')');
                    this.showMessage('Lỗi kết nối: ' + error, 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Add link');
                }
            });
        },

        /**
         * Sprint 0.18.A.4 — process newly-added website without needing the row in DOM yet.
         * Logs every step to the console. Calls onDone({success, sourceId, url, title, chunks_count})
         * so the caller can inject the row into the DOM without a page reload.
         */
        processWebsiteHeadless(sourceId, urlHint, onDone) {
            this.wsConsole('info', 'POST bizcity_knowledge_process_website source_id=' + sourceId);
            const t0 = performance.now();
            let crawlResult = null;

            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_process_website',
                    nonce: bizcity_knowledge_vars.nonce,
                    source_id: sourceId
                },
                success: (response) => {
                    const dt = (performance.now() - t0).toFixed(0);
                    if (response.success) {
                        const d = response.data || {};
                        this.wsConsole('ok', 'Crawled (' + dt + 'ms) source_id=' + sourceId + ' title="' + (d.title || '?') + '" chunks=' + (d.chunks_count || 0));
                        crawlResult = { success: true, sourceId: sourceId, url: urlHint, title: d.title || '', chunks_count: d.chunks_count || 0 };
                    } else {
                        this.wsConsole('error', 'Process failed source_id=' + sourceId + ': ' + ((response.data && response.data.message) || 'unknown'));
                        crawlResult = { success: false, sourceId: sourceId, url: urlHint };
                    }
                },
                error: (xhr, status, error) => {
                    this.wsConsole('error', 'Process AJAX error source_id=' + sourceId + ': ' + error);
                    crawlResult = { success: false, sourceId: sourceId, url: urlHint };
                },
                complete: () => { if (typeof onDone === 'function') onDone(crawlResult); }
            });
        },

        /**
         * Inject a newly-crawled website row at the top of #websites-list without a page reload.
         */
        addWebsiteToList(sourceId, url, title, chunksCount) {
            const escAttr = (str) => $('<span>').text(str || '').html().replace(/"/g, '&quot;');
            const escHtml = (str) => $('<span>').text(str || '').html();
            let displayTitle = title || '';
            if (!displayTitle && url) {
                try { displayTitle = new URL(url).hostname; } catch(e) { displayTitle = url; }
            }
            let displayUrl = url || '';
            if (displayUrl.length > 60) displayUrl = displayUrl.substring(0, 60) + '…';
            const today = new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
            const chunksHtml = chunksCount > 0
                ? '<span class="bk-web-chunks"><span class="dashicons dashicons-database"></span> ' + escHtml(String(chunksCount)) + ' chunks</span>'
                : '';
            const html =
                '<div class="bk-website-item bk-website-status-ready" data-id="' + escAttr(String(sourceId)) + '">' +
                    '<div class="bk-web-icon">🌐</div>' +
                    '<div class="bk-web-info">' +
                        '<div class="bk-web-title">' + escHtml(displayTitle) + '</div>' +
                        '<div class="bk-web-url-small">' +
                            '<a href="' + escAttr(url || '') + '" target="_blank" title="' + escAttr(url || '') + '">' + escHtml(displayUrl) + '</a>' +
                        '</div>' +
                        '<div class="bk-web-meta">' +
                            '<span class="bk-web-status bk-status-ready"><span class="dashicons dashicons-yes-alt"></span> Ready</span>' +
                            chunksHtml +
                            '<span class="bk-web-date"><span class="dashicons dashicons-calendar-alt"></span> ' + escHtml(today) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="bk-web-actions">' +
                        '<button type="button" class="bk-web-delete" data-id="' + escAttr(String(sourceId)) + '" title="Delete this website">' +
                            '<span class="dashicons dashicons-trash"></span>' +
                        '</button>' +
                    '</div>' +
                '</div>';
            $('#websites-list').prepend(html);
        },

        /**
         * Update the Websites tab badge counter.
         */
        refreshWebsiteTabCount() {
            const count = $('#websites-list .bk-website-item').length;
            $('.bk-tab-btn[data-tab="websites"] .bk-tab-count').text(count);
        },

        wsConsole(type, msg) {
            const $body = $('#website-console-body');
            if (!$body.length) return;
            const stamp = new Date().toLocaleTimeString();
            const cls = 'bk-console-' + (type || 'info');
            const $line = $('<div class="bk-console-line ' + cls + '"></div>')
                .text('[' + stamp + '] ' + msg);
            $body.append($line);
            $body.scrollTop($body.prop('scrollHeight'));
        },
        
        processWebsite(sourceId, $element) {
            const $statusBadge = $element.find('.bk-web-status');
            const $processBtn = $element.find('.bk-web-process');
            
            // Update to processing state
            $element.removeClass('bk-website-status-pending bk-website-status-ready bk-website-status-error')
                    .addClass('bk-website-status-processing');
            
            $statusBadge.removeClass('bk-status-pending bk-status-ready bk-status-error')
                         .addClass('bk-status-processing')
                         .html('<span class="dashicons dashicons-update"></span> Processing');
            
            $processBtn.prop('disabled', true);
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_process_website',
                    nonce: bizcity_knowledge_vars.nonce,
                    source_id: sourceId
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        
                        // Update title if we got one
                        if (response.data.title) {
                            const $title = $element.find('.bk-web-title');
                            if ($title.length) {
                                $title.text(response.data.title);
                            }
                        }
                        
                        // Update status to ready
                        $element.removeClass('bk-website-status-processing')
                               .addClass('bk-website-status-ready');
                        
                        $statusBadge.removeClass('bk-status-processing')
                                   .addClass('bk-status-ready')
                                   .html('<span class="dashicons dashicons-yes-alt"></span> Ready');
                        
                        // Update or add chunks count
                        const $meta = $element.find('.bk-web-meta');
                        const $chunksSpan = $meta.find('.bk-web-chunks');
                        if ($chunksSpan.length) {
                            $chunksSpan.html('<span class="dashicons dashicons-database"></span> ' + response.data.chunks_count + ' chunks');
                        } else {
                            $statusBadge.after('<span class="bk-web-chunks"><span class="dashicons dashicons-database"></span> ' + response.data.chunks_count + ' chunks</span>');
                        }
                        
                        // Hide process button
                        $processBtn.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        this.wsConsole('error', 'Process failed source_id=' + sourceId + ': ' + ((response.data && response.data.message) || 'unknown'));
                        $element.removeClass('bk-website-status-processing')
                               .addClass('bk-website-status-error');
                        
                        $statusBadge.removeClass('bk-status-processing')
                                   .addClass('bk-status-error')
                                   .html('<span class="dashicons dashicons-warning"></span> Error');
                        
                        this.showMessage(response.data.message || 'Lỗi xử lý website!', 'error');
                        $processBtn.prop('disabled', false);
                    }
                },
                error: () => {
                    $element.removeClass('bk-website-status-processing')
                           .addClass('bk-website-status-error');
                    
                    $statusBadge.removeClass('bk-status-processing')
                               .addClass('bk-status-error')
                               .html('<span class="dashicons dashicons-warning"></span> Error');
                    
                    this.showMessage('Lỗi kết nối!', 'error');
                    $processBtn.prop('disabled', false);
                }
            });
        },
        
        deleteKnowledgeSource(id, $element) {
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_delete_document',
                    nonce: bizcity_knowledge_vars.nonce,
                    source_id: id
                },
                success: (response) => {
                    if (response.success) {
                        $element.fadeOut(300, function() {
                            $(this).remove();
                        });
                        CharacterEdit.showMessage('Đã xóa document', 'success');
                    } else {
                        CharacterEdit.showMessage(response.data.message || 'Lỗi xóa!', 'error');
                    }
                },
                error: () => {
                    CharacterEdit.showMessage('Lỗi kết nối!', 'error');
                }
            });
        },
        
        // Save Character
        saveCharacter() {
            const $btn = $('#save-character-btn');
            $btn.prop('disabled', true).addClass('bk-loading');
            
            // Collect form data
            this.updateQuickKnowledgeData();
            this.updateFaqsData();
            
            const formData = $('#character-form').serialize();
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: formData + '&nonce=' + bizcity_knowledge_vars.nonce,
                success: (response) => {
                    if (response.success) {
                        // Sprint 0.18.A.1: surface Quick Knowledge save counts + sync new IDs back to DOM.
                        const qk = response.data && response.data.quick_knowledge;
                        let msg = 'Lưu thành công!';
                        if (qk) {
                            const c = (qk.created || []).length;
                            const u = (qk.updated || []).length;
                            const d = (qk.deleted || []).length;
                            const e = (qk.errors  || []).length;
                            if (c || u || d || e) {
                                msg += ' Quick Knowledge: ' + c + ' tạo, ' + u + ' cập nhật, ' + d + ' xóa' + (e ? ', ' + e + ' lỗi' : '') + '.';
                            }
                            if (e) {
                                console.warn('[BizCity] Quick Knowledge errors:', qk.errors);
                            }
                            // Re-bind data-id for newly created rows so re-saving updates instead of duplicating.
                            if (Array.isArray(qk.rows)) {
                                const $rows = $('#quick-knowledge-tbody .bk-editable-row');
                                qk.rows.forEach((row) => {
                                    if (row && typeof row.client_index === 'number' && row.id) {
                                        const $r = $rows.eq(row.client_index);
                                        if ($r.length) {
                                            $r.attr('data-id', row.id).data('id', row.id);
                                        }
                                    }
                                });
                            }
                        }
                        this.showMessage(msg, 'success');

                        // Update character ID if new
                        if (response.data.id && $('#character-id').val() == '0') {
                            $('#character-id').val(response.data.id);
                            // Update URL without reload
                            const newUrl = window.location.origin + window.location.pathname + '?page=bizcity-knowledge-character-edit&id=' + response.data.id;
                            window.history.pushState({}, '', newUrl);
                        }
                    } else {
                        this.showMessage((response.data && response.data.message) || 'Có lỗi xảy ra!', 'error');
                    }
                },
                error: () => {
                    this.showMessage('Lỗi kết nối!', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).removeClass('bk-loading');
                }
            });
        },
        
        // Utilities
        updateRowNumbers(tbodySelector) {
            $(tbodySelector + ' .bk-editable-row').each(function(index) {
                $(this).find('.bk-row-number').text(index + 1);
            });
        },
        
        parseImportFile(file, callback) {
            const reader = new FileReader();
            
            reader.onload = (e) => {
                const content = e.target.result;
                const rows = [];
                
                if (file.name.endsWith('.csv')) {
                    // Parse CSV
                    const lines = content.split('\n');
                    lines.forEach((line, index) => {
                        if (index === 0 || !line.trim()) return; // Skip header and empty lines
                        
                        const cells = line.match(/(".*?"|[^",]+)(?=\s*,|\s*$)/g) || [];
                        const cleanCells = cells.map(cell => cell.replace(/^"|"$/g, '').replace(/""/g, '"').trim());
                        
                        if (cleanCells.length > 0) {
                            rows.push(cleanCells);
                        }
                    });
                    
                    callback(rows);
                } else {
                    alert('Chỉ hỗ trợ file CSV hiện tại. XLSX đang được phát triển.');
                }
            };
            
            reader.readAsText(file);
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        showMessage(message, type = 'info') {
            const $message = $('<div class="bk-message bk-message-' + type + '">' + message + '</div>');
            $('.bk-character-edit-wrap').prepend($message);
            
            setTimeout(() => {
                $message.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },
        
        // Model Tab
        initModelTab() {
            // Load models on tab switch
            $('.bk-tab-btn[data-tab="model"]').one('click', () => {
                this.loadModels();
            });
            
            // Refresh models button
            $('#refresh-models').on('click', () => this.loadModels(true));
            
            // Model selection
            $('#model-select').on('change', function() {
                const selectedOption = $(this).find('option:selected');
                $('#model-info').text(selectedOption.data('description') || '');
                $('#model-cost').text(selectedOption.data('cost') || '');
            });
            
            // Creativity slider
            $('#creativity-level').on('input', function() {
                const value = $(this).val();
                $('#temperature-value').text(value);
            });
        },
        
        loadModels(force = false) {
            const $select = $('#model-select');
            const $btn = $('#refresh-models');
            
            // Check if already loaded and not forcing
            if (!force && $select.find('option').length > 2) {
                return;
            }
            
            $btn.prop('disabled', true);
            $select.prop('disabled', true);
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_fetch_models',
                    nonce: bizcity_knowledge_vars.nonce
                },
                success: (response) => {
                    if (response.success && response.data.models) {
                        const selectedModel = $select.val();
                        $select.empty();
                        $select.append('<option value="">-- Chọn AI Model --</option>');
                        
                        response.data.models.forEach(model => {
                            const cost = (model.pricing.prompt * 1000000).toFixed(2);
                            const option = $('<option>')
                                .val(model.id)
                                .text(model.name)
                                .data('description', model.description)
                                .data('cost', 'Cost: $' + cost + '/1M tokens')
                                .attr('data-context', model.context_length);
                            
                            if (model.id === selectedModel) {
                                option.prop('selected', true);
                            }
                            
                            $select.append(option);
                        });
                        
                        this.showMessage('Đã tải ' + response.data.models.length + ' models!', 'success');
                    } else {
                        this.showMessage(response.data.message || 'Không thể tải models', 'error');
                    }
                },
                error: () => {
                    this.showMessage('Lỗi kết nối!', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false);
                    $select.prop('disabled', false);
                }
            });
        },
        
        // Greeting Messages
        initGreetingMessages() {
            // Add greeting
            $('#add-greeting').on('click', () => this.addGreeting());
            
            // Remove greeting
            $(document).on('click', '.bk-remove-greeting', function() {
                $(this).closest('.bk-greeting-item').remove();
                CharacterEdit.updateGreetingsData();
            });
            
            // Update greeting data on input
            $(document).on('input', '.bk-greeting-input', () => {
                this.updateGreetingsData();
            });
            
            // Initial update
            this.updateGreetingsData();
        },
        
        addGreeting() {
            const newItem = `
                <div class="bk-greeting-item">
                    <input type="text" class="regular-text bk-greeting-input" 
                        placeholder="Hi! How can I help you today?"
                        value="">
                    <button type="button" class="button bk-remove-greeting">×</button>
                </div>
            `;
            
            $('#greeting-messages-list').append(newItem);
            $('#greeting-messages-list .bk-greeting-input').last().focus();
            this.updateGreetingsData();
        },
        
        updateGreetingsData() {
            const greetings = [];
            let totalChars = 0;
            
            $('.bk-greeting-input').each(function() {
                const text = $(this).val().trim();
                if (text) {
                    greetings.push(text);
                    totalChars += text.length;
                }
            });
            
            $('#greeting-messages-data').val(JSON.stringify(greetings));
            $('#greeting-count').text(totalChars + '/300');
        },
        
        // Chunks Viewer
        initChunksViewer() {
            // Expand/collapse chunk content
            $(document).on('click', '.bk-expand-chunk', function(e) {
                e.preventDefault();
                const $row = $(this).closest('.bk-chunk-row');
                const $preview = $row.find('.bk-chunk-preview');
                const $full = $row.find('.bk-chunk-full');
                const $icon = $(this).find('.dashicons');
                
                if ($full.is(':visible')) {
                    $full.slideUp(200);
                    $preview.show();
                    $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
                } else {
                    $preview.hide();
                    $full.slideDown(200);
                    $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
                }
            });
            
            // Click on row to expand (except on buttons)
            $(document).on('click', '.bk-chunk-row', function(e) {
                if (!$(e.target).closest('button').length) {
                    $(this).find('.bk-expand-chunk').trigger('click');
                }
            });
            
            // Collapse all when clicking header
            $(document).on('click', '.bk-chunks-section h3', function() {
                const $section = $(this).closest('.bk-chunks-section');
                const $table = $section.find('.bk-chunks-table-wrap');
                
                if ($table.is(':visible')) {
                    $table.slideUp(200);
                    $(this).find('.dashicons').css('transform', 'rotate(-90deg)');
                } else {
                    $table.slideDown(200);
                    $(this).find('.dashicons').css('transform', 'rotate(0)');
                }
            });
        },
        
        deleteWebsite(id, $element) {
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_delete_website',
                    nonce: bizcity_knowledge_vars.nonce,
                    source_id: id
                },
                success: (response) => {
                    if (response.success) {
                        $element.fadeOut(300, function() {
                            $(this).remove();
                        });
                        CharacterEdit.showMessage('Đã xóa website', 'success');
                    } else {
                        CharacterEdit.showMessage(response.data.message || 'Lỗi xóa!', 'error');
                    }
                },
                error: () => {
                    CharacterEdit.showMessage('Lỗi kết nối!', 'error');
                }
            });
        },
        
        // Legacy FAQ Import
        initLegacyFAQ() {
            const self = this;
            
            // Select all checkbox
            $('#legacy-faq-select-all').on('change', function() {
                const checked = $(this).is(':checked');
                $('.legacy-faq-checkbox:not(:disabled)').prop('checked', checked);
                self.updateLegacyFAQCount();
            });
            
            // Individual checkboxes
            $(document).on('change', '.legacy-faq-checkbox', function() {
                self.updateLegacyFAQCount();
            });
            
            // Import selected button
            $('#import-selected-faq').on('click', function() {
                self.importSelectedFAQ();
            });
            
            // Refresh button
            $('#refresh-legacy-faq').on('click', function() {
                location.reload();
            });
            
            // Initial count
            this.updateLegacyFAQCount();
        },
        
        updateLegacyFAQCount() {
            const selectedCount = $('.legacy-faq-checkbox:checked:not(:disabled)').length;
            $('#legacy-faq-selected-count').text(selectedCount);
            $('#import-selected-faq').prop('disabled', selectedCount === 0);
        },
        
        importSelectedFAQ() {
            const characterId = $('#character-id').val();
            
            if (!characterId || characterId === '0') {
                alert('Vui lòng lưu character trước khi import FAQ!');
                return;
            }
            
            const postIds = [];
            $('.legacy-faq-checkbox:checked:not(:disabled)').each(function() {
                postIds.push($(this).data('post-id'));
            });
            
            if (postIds.length === 0) {
                alert('Vui lòng chọn ít nhất 1 FAQ post để import!');
                return;
            }
            
            const $btn = $('#import-selected-faq');
            $btn.prop('disabled', true).text('Đang import...');
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_import_legacy_faq',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId,
                    post_ids: postIds
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        
                        // Mark imported rows
                        postIds.forEach(postId => {
                            const $row = $('.bk-legacy-faq-row[data-post-id="' + postId + '"]');
                            const $checkbox = $row.find('.legacy-faq-checkbox');
                            $checkbox.prop('disabled', true).prop('checked', true);
                            $row.find('td:last-child').html('<span style="color: #10b981;">✓ Imported</span>');
                        });
                        
                        this.updateLegacyFAQCount();
                    } else {
                        this.showMessage(response.data.message || 'Lỗi import!', 'error');
                    }
                },
                error: () => {
                    this.showMessage('Lỗi kết nối!', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).text('Import Selected');
                }
            });
        },
        
        // Export Knowledge
        exportKnowledge() {
            const characterId = $('#character-id').val();
            
            if (!characterId || characterId === '0') {
                this.showMessage('Vui lòng lưu character trước khi export!', 'error');
                return;
            }
            
            const $btn = $('#export-knowledge-btn');
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Đang export...');
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_export_knowledge',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId
                },
                success: (response) => {
                    if (response.success) {
                        // Create download link
                        const jsonData = JSON.stringify(response.data.data, null, 2);
                        const blob = new Blob([jsonData], { type: 'application/json' });
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                        
                        this.showMessage('Knowledge đã được export thành công!', 'success');
                    } else {
                        this.showMessage(response.data.message || 'Lỗi export!', 'error');
                    }
                },
                error: () => {
                    this.showMessage('Lỗi kết nối!', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Export Knowledge');
                }
            });
        },
        
        // Import Knowledge
        importKnowledge() {
            const characterId = $('#character-id').val();
            
            if (!characterId || characterId === '0') {
                this.showMessage('Vui lòng lưu character trước khi import!', 'error');
                return;
            }
            
            // Create file input
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = '.json';
            
            input.onchange = (e) => {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = (event) => {
                    try {
                        const importData = JSON.parse(event.target.result);
                        
                        // Confirm import
                        const overwrite = confirm(
                            'Bạn có muốn ghi đè toàn bộ knowledge hiện tại?\n\n' +
                            '✓ YES = Xóa tất cả knowledge cũ và import mới\n' +
                            '✗ NO = Giữ knowledge cũ và thêm mới vào'
                        );
                        
                        this.performImport(characterId, importData, overwrite);
                    } catch (error) {
                        this.showMessage('File JSON không hợp lệ!', 'error');
                    }
                };
                reader.readAsText(file);
            };
            
            input.click();
        },
        
        performImport(characterId, importData, overwrite) {
            const $btn = $('#import-knowledge-btn');
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Đang import...');
            
            $.ajax({
                url: bizcity_knowledge_vars.ajaxurl,
                method: 'POST',
                data: {
                    action: 'bizcity_knowledge_import_knowledge',
                    nonce: bizcity_knowledge_vars.nonce,
                    character_id: characterId,
                    import_data: JSON.stringify(importData),
                    overwrite: overwrite
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        
                        // Reload page to show new knowledge
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        this.showMessage(response.data.message || 'Lỗi import!', 'error');
                    }
                },
                error: () => {
                    this.showMessage('Lỗi kết nối!', 'error');
                },
                complete: () => {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Import Knowledge');
                }
            });
        },
        
        updatePreview() {
            // Any preview updates
        }
    };
    
    // Initialize on document ready
    $(document).ready(() => {
        if ($('.bk-character-edit-wrap').length > 0) {
            CharacterEdit.init();
        }
    });
    
})(jQuery);
