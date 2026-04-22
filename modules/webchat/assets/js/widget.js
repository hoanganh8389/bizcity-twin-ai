/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * WebChat Widget — JavaScript (Float interaction)
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat\Assets
 * @license    GPL-2.0-or-later | https://bizcity.vn
 *
 * Features: Polling, Speech Recognition, File Upload, History, Typing Effect
 */

(function($) {
    'use strict';

    // WebChat Widget Class
    class BizChatWidget {
        constructor(options) {
            this.options = Object.assign({
                ajaxurl: bizcity_webchat_vars.ajaxurl || '/wp-admin/admin-ajax.php',
                restUrl: bizcity_webchat_vars.rest_url || '/wp-json/bizcity-webchat/v1',
                nonce: bizcity_webchat_vars.nonce || '',
                sessionId: bizcity_webchat_vars.session_id || this.generateSessionId(),
                userId: bizcity_webchat_vars.user_id || 0,
                characterId: bizcity_webchat_vars.character_id || 0, // Add character_id support
                siteName: bizcity_webchat_vars.site_name || 'BizChat',
                avatarBot: bizcity_webchat_vars.avatar_bot || '',
                avatarUser: bizcity_webchat_vars.avatar_user || '',
                welcomeMessage: bizcity_webchat_vars.welcome_message || 'Xin chào! Tôi có thể giúp gì cho bạn?',
                alertSoundUrl: bizcity_webchat_vars.alert_sound_url || '/wp-content/uploads/alert.mp3',
                enablePolling: bizcity_webchat_vars.enable_polling || true,
                pollInterval: bizcity_webchat_vars.poll_interval || 4000,
                typingSpeed: 18,
                autoScrollDelay: 100,
            }, options);

            this.isOpen = false;
            this.isLoading = false;
            this.waitingReply = false;
            this.messageQueue = [];
            this.displayedMsgIds = new Set();
            this.pollTimer = null;
            this.recognition = null;
            this.isRecording = false;
            this.lastMessageId = 0;
            
            this.init();
        }

        init() {
            this.bindEvents();
            this.initSpeechRecognition();
            this.initKeyboardFix();
            this.restoreState();
            this.updateSendButton(); // Initialize send button state
        }

        bindEvents() {
            const self = this;

            // Toggle chat window
            $(document).on('click', '#bizchat-float-btn', function(e) {
                e.preventDefault();
                self.toggleChat();
            });

            // Close button
            $(document).on('click', '#bizchat-close-btn', function(e) {
                e.preventDefault();
                self.closeChat();
            });

            // Minimize button
            $(document).on('click', '#bizchat-minimize-btn', function(e) {
                e.preventDefault();
                self.closeChat();
            });

            // Expand/Maximize button
            $(document).on('click', '#bizchat-expand-btn', function(e) {
                e.preventDefault();
                self.toggleExpand();
            });

            // Send message
            $(document).on('click', '#bizchat-send-btn', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            // Enter to send
            $(document).on('keydown', '#bizchat-input', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Quick replies
            $(document).on('click', '.bizchat-quick-btn', function(e) {
                e.preventDefault();
                const message = $(this).data('value');
                $('#bizchat-input').val(message);
                self.sendMessage();
            });

            // Auto-resize textarea
            $(document).on('input', '#bizchat-input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                self.updateSendButton();
            });

            // File upload
            $(document).on('change', '#bizchat-file-input', function(e) {
                self.handleFileUpload(e.target.files);
                $(this).val(''); // Reset to allow re-select same file
            });

            // Upload button click
            $(document).on('click', '#bizchat-upload-btn', function(e) {
                e.preventDefault();
                $('#bizchat-file-input').click();
            });

            // Voice input
            $(document).on('click', '#bizchat-voice-btn', function(e) {
                e.preventDefault();
                self.toggleVoiceInput();
            });

            // Clear history
            $(document).on('click', '#bizchat-clear-btn', function(e) {
                e.preventDefault();
                self.clearHistory();
            });

            // Focus input select all
            $(document).on('focus', '#bizchat-input', function() {
                try { this.select(); } catch(e) {}
            });
        }

        // ========== State Management ==========
        restoreState() {
            // Always start hidden — user must click the float button to open
        }

        toggleExpand() {
            $('#bizchat-window').toggleClass('bizchat-maximized');
        }

        toggleChat() {
            if (this.isOpen) {
                this.closeChat();
            } else {
                this.openChat();
            }
        }

        openChat() {
            this.loadHistory();
            $('#bizchat-window').removeClass('bizchat-hidden').addClass('active');
            $('body').addClass('bizchat-open');
            this.isOpen = true;
            $('#bizchat-input').focus();
            this.scrollToBottom();
            localStorage.setItem('bizchat_is_closed', 'false');
            
            // Start polling - TẠM ẨN: chưa làm phần quản lý chatinbox tương tác từ admin ra ngoài
            // this.startPolling();
            
            // Show welcome message if no messages
            if ($('.bizchat-message').length === 0) {
                this.showWelcomeMessage();
            }
        }

        closeChat() {
            $('#bizchat-window').removeClass('active').addClass('bizchat-hidden');
            $('body').removeClass('bizchat-open');
            this.isOpen = false;
            localStorage.setItem('bizchat_is_closed', 'true');
            
            // Stop polling
            this.stopPolling();
        }

        showWelcomeMessage() {
            if (this.options.welcomeMessage) {
                this.appendMessage(this.options.welcomeMessage, 'bot', true);
            }
        }

        /* ========== Polling - TẠM ẨN: chưa làm phần quản lý chatinbox tương tác từ admin ra ngoài ==========
        startPolling() {
            if (!this.options.enablePolling) return;
            
            this.stopPolling();
            const self = this;
            this.pollTimer = setInterval(function() {
                self.pullMessages();
            }, this.options.pollInterval);
        }

        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        }

        pullMessages() {
            if (this.isLoading) return; // Only skip if actively loading
            
            const self = this;
            
            $.ajax({
                url: this.options.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_webchat_pull',
                    last_id: this.lastMessageId,
                    session_id: this.options.sessionId,
                    user_id: this.options.userId,
                    _wpnonce: this.options.nonce
                },
                success: function(res) {
                    if (res && res.success && Array.isArray(res.data)) {
                        let hasNewMsg = false;
                        
                        res.data.forEach(function(msg) {
                            const mid = msg.id ? String(msg.id) : self.generateId();
                            
                            // Skip if already displayed or empty message
                            if (!msg.msg || self.displayedMsgIds.has(mid)) return;
                            
                            self.displayedMsgIds.add(mid);
                            
                            // Update lastMessageId
                            if (msg.id && parseInt(msg.id, 10) > self.lastMessageId) {
                                self.lastMessageId = parseInt(msg.id, 10);
                            }
                            
                            if (msg.from === 'bot') {
                                // Hide typing indicator for new bot messages
                                self.hideTyping();
                                // Use typing effect for bot messages from polling
                                self.appendMessageTyping(msg.msg, msg.from);
                                hasNewMsg = true;
                            }
                            // Skip user messages from polling as they are already displayed when sent
                        });
                        
                        // Notification for new messages when chat is closed
                        if (hasNewMsg && !self.isOpen) {
                            self.notifyNewMessage();
                        }
                    }
                }
            });
        }
        ========== End Polling ========== */

        // Stub method - polling is disabled
        stopPolling() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        }

        notifyNewMessage() {
            // Only show notification badge on float button, don't auto-open
            $('#bizchat-float-btn').addClass('bizchat-notify');
            
            // Play sound
            try {
                const audio = new Audio(this.options.alertSoundUrl);
                audio.play();
            } catch(e) {}
            
            setTimeout(function() {
                $('#bizchat-float-btn').removeClass('bizchat-notify');
            }, 2000);
        }

        // ========== History ==========

        loadHistory() {
            const self = this;
            this.displayedMsgIds.clear();
            this.lastMessageId = 0;
            
            // Clear existing messages
            $('#bizchat-messages').html('');
            
            $.ajax({
                url: this.options.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_chat_history',
                    platform_type: 'WEBCHAT',
                    session_id: this.options.sessionId,
                    _wpnonce: this.options.nonce
                },
                success: function(response) {
                    if (response.success && Array.isArray(response.data)) {
                        // Process direct array of messages from backend
                        response.data.forEach(function(msg, idx) {
                            const mid = msg.id ? String(msg.id) : self.generateId();
                            const messageText = msg.msg || msg.message_text || '';
                            const messageFrom = msg.from || msg.message_from || 'user';
                            
                            if (!messageText || self.displayedMsgIds.has(mid)) return;
                            
                            self.displayedMsgIds.add(mid);
                            
                            // Update lastMessageId
                            if (msg.id && parseInt(msg.id, 10) > self.lastMessageId) {
                                self.lastMessageId = parseInt(msg.id, 10);
                            }
                            
                            // Append message (skip animation for history)
                            self.appendMessage(messageText, messageFrom, true);
                        });
                        
                        self.scrollToBottom();
                    }
                }
            });
        }

        clearHistory() {
            if (!confirm("Bạn có chắc chắn muốn xóa toàn bộ hội thoại không?")) return;
            
            const self = this;
            
            $.ajax({
                url: this.options.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_chat_clear',
                    platform_type: 'WEBCHAT',
                    session_id: this.options.sessionId,
                    user_id: this.options.userId,
                    _wpnonce: this.options.nonce
                },
                success: function(res) {
                    if (res && res.success) {
                        $('#bizchat-messages').html('');
                        self.displayedMsgIds.clear();
                        self.lastMessageId = 0;
                        self.showWelcomeMessage();
                    } else {
                        alert('Có lỗi khi xóa hội thoại!');
                    }
                },
                error: function() {
                    alert('Không thể kết nối server!');
                }
            });
        }

        // ========== Send Message ==========

        sendMessage() {
            const input = $('#bizchat-input');
            const message = input.val().trim();
            
            // Check if there are selected images or message text
            const hasImages = this.selectedImages && this.selectedImages.length > 0;
            if (!message && !hasImages) return;
            if (this.isLoading) return;

            // Clear input
            input.val('');
            input.css('height', 'auto');
            this.updateSendButton();

            // Prepare message content with images
            let messageContent = '';
            
            // Add images to message if any
            if (hasImages) {
                const imagesHtml = this.selectedImages.map(img => 
                    `<img src="${img.src}" class="bizchat-photo-msg" alt="${img.name}" style="max-width:200px;border-radius:8px;margin:2px;">`
                ).join('');
                messageContent += imagesHtml;
                
                // Add a line break if there's both images and text
                if (message) {
                    messageContent += '<br>';
                }
            }
            
            // Add text message if any
            if (message) {
                messageContent += this.escapeHtml(message);
            }

            // Append user message with images and text
            this.appendMessage(messageContent, 'user');

            // Show typing indicator
            this.showTyping();
            
            // Prepare data for sending
            const sendData = {
                action: 'bizcity_chat_send',
                platform_type: 'WEBCHAT',
                message: message,
                session_id: this.options.sessionId,
                character_id: this.options.characterId || 0,
                _wpnonce: this.options.nonce
            };
            
            // Add first image as image_data if present (backend expects single base64)
            if (hasImages && this.selectedImages.length > 0) {
                sendData.image_data = this.selectedImages[0].src; // First image only
            }
            
            // Clear image previews AFTER preparing sendData
            if (hasImages) {
                $('#bizchat-image-preview').hide();
                $('.bizchat-preview-images').empty();
                this.selectedImages = [];
            }
            
            // Send to server and get immediate reply
            this.isLoading = true;
            const self = this;

            $.ajax({
                url: this.options.ajaxurl,
                type: 'POST',
                data: sendData,
                dataType: 'text', // Get as text to clean JSON
                success: function(response) {
                    try {
                        self.hideTyping();
                        self.isLoading = false;
                        
                        // CRITICAL: Clean BOM and whitespace before JSON parsing
                        if (typeof response === 'string') {
                            // Remove BOM
                            response = response.replace(/^\uFEFF/, '');
                            
                            // Find JSON start
                            const jsonStart = response.indexOf('{');
                            if (jsonStart > 0) {
                                console.warn('Garbage before JSON:', response.substring(0, jsonStart));
                                response = response.substring(jsonStart);
                            }
                            
                            // Find JSON end
                            const jsonEnd = response.lastIndexOf('}');
                            if (jsonEnd > 0 && jsonEnd < response.length - 1) {
                                console.warn('Garbage after JSON:', response.substring(jsonEnd + 1));
                                response = response.substring(0, jsonEnd + 1);
                            }
                            
                            try {
                                response = JSON.parse(response);
                            } catch (e) {
                                console.error('JSON parse error:', e);
                                console.error('Response text:', response);
                                self.appendMessage('❌ Lỗi xử lý dữ liệu từ server.', 'bot');
                                return;
                            }
                        }
                        
                        // Handle response directly (no polling needed)
                        if (response.success && response.data && response.data.reply) {
                            const reply = response.data.reply;
                            self.appendMessageTyping(reply, 'bot');
                        } else {
                            const errorMsg = response.data?.message || 'Có lỗi xảy ra. Vui lòng thử lại.';
                            self.appendMessage('❌ ' + errorMsg, 'bot');
                        }
                    } catch (e) {
                        console.error('Error processing response:', e);
                        self.appendMessage('❌ Lỗi xử lý phản hồi: ' + e.message, 'bot');
                    }
                },
                error: function(xhr, status, error) {
                    self.hideTyping();
                    self.isLoading = false;
                    console.error('AJAX Error:', status, error, xhr.responseText);
                    self.appendMessage('❌ Không thể kết nối đến server. Vui lòng thử lại.', 'bot');
                }
            });
        }

        appendMessage(message, from, skipAnimation = false) {
            const avatar = this.getAvatar(from);
            const time = this.formatTime(new Date());
            const messageId = 'msg-' + this.generateId();
            const rendered = (from === 'bot') ? this.formatMessage(message) : message;
            
            const html = `
                <div class="bizchat-message ${from}" ${skipAnimation ? '' : 'style="animation: bizchat-fade-in 0.3s ease"'} data-message-id="${messageId}">
                    <div class="bizchat-message-avatar">${avatar}</div>
                    <div class="bizchat-message-content">
                        <div class="bizchat-message-bubble bizchat-md">${rendered}</div>
                        <div class="bizchat-message-time">${time}</div>
                    </div>
                </div>
            `;

            $('#bizchat-messages').append(html);
            this.scrollToBottom();
        }

        appendMessageTyping(message, from) {
            const self = this;
            const avatar = this.getAvatar(from);
            const time = this.formatTime(new Date());
            const bubbleId = 'bizchat-bubble-' + this.generateId();
            const messageId = 'typing-' + bubbleId; // Generate unique ID for tracking

            const html = `
                <div class="bizchat-message ${from}" data-message-id="${messageId}">
                    <div class="bizchat-message-avatar">${avatar}</div>
                    <div class="bizchat-message-content">
                        <div class="bizchat-message-bubble" id="${bubbleId}"></div>
                        <div class="bizchat-message-time">${time}</div>
                    </div>
                </div>
            `;

            $('#bizchat-messages').append(html);
            this.scrollToBottom();
            
            // Track this message to prevent duplicates
            this.displayedMsgIds.add(messageId);

            // Typing effect
            this.typeText(bubbleId, message, this.options.typingSpeed);
        }

        typeText(elementId, text, speed) {
            const element = $('#' + elementId);
            element.addClass('bizchat-md');
            const formatted = this.formatMessage(text);
            const isHtml = /<\/?[a-z][\s\S]*>/i.test(formatted);
            const self = this;
            let i = 0;

            if (isHtml) {
                // For HTML content, type plain text first then replace with formatted HTML
                const plainText = $('<div>').html(formatted).text();
                const timer = setInterval(function() {
                    if (i <= plainText.length) {
                        element.text(plainText.substring(0, i));
                        i++;
                        self.scrollToBottom();
                    } else {
                        element.html(formatted);
                        self.scrollToBottom();
                        clearInterval(timer);
                    }
                }, speed);
            } else {
                const timer = setInterval(function() {
                    if (i <= text.length) {
                        element.text(text.substring(0, i));
                        i++;
                        self.scrollToBottom();
                    } else {
                        element.html(formatted);
                        clearInterval(timer);
                    }
                }, speed);
            }
        }

        showTyping() {
            const html = `
                <div class="bizchat-message bot" id="bizchat-typing">
                    <div class="bizchat-message-avatar">${this.getAvatar('bot')}</div>
                    <div class="bizchat-message-content">
                        <div class="bizchat-typing">
                            <div class="bizchat-typing-dot"></div>
                            <div class="bizchat-typing-dot"></div>
                            <div class="bizchat-typing-dot"></div>
                        </div>
                    </div>
                </div>
            `;
            $('#bizchat-messages').append(html);
            this.scrollToBottom();
        }

        hideTyping() {
            $('#bizchat-typing').remove();
        }

        getAvatar(from) {
            if (from === 'bot') {
                if (this.options.avatarBot) {
                    return `<img src="${this.options.avatarBot}" alt="Bot">`;
                }
                return '🤖';
            } else {
                if (this.options.avatarUser) {
                    return `<img src="${this.options.avatarUser}" alt="User">`;
                }
                return '👤';
            }
        }

        scrollToBottom() {
            const container = $('#bizchat-messages');
            if (!container.length) return;
            setTimeout(function() {
                container.scrollTop(container[0].scrollHeight);
            }, this.options.autoScrollDelay);
        }

        updateSendButton() {
            const input = $('#bizchat-input');
            const sendBtn = $('#bizchat-send-btn');
            if (!input.length || !sendBtn.length) return; // Element not ready
            const inputVal = input.val() || '';
            const hasText = inputVal.trim().length > 0;
            const hasImages = this.selectedImages && this.selectedImages.length > 0;
            
            if (hasText || hasImages) {
                sendBtn.prop('disabled', false).css('opacity', '1');
            } else {
                sendBtn.prop('disabled', true).css('opacity', '0.5');
            }
        }

        // ========== File Upload ==========
        handleFileUpload(files) {
            if (!files || !files.length) return;
            
            const file = files[0];
            const self = this;
            
            // Check file size (10MB limit)
            if (file.size > 10 * 1024 * 1024) {
                alert('File quá lớn. Vui lòng chọn file dưới 10MB.');
                return;
            }
            
            // Handle different file types
            if (file.type && file.type.indexOf('image/') === 0) {
                // For images: show preview and allow user to add text
                const reader = new FileReader();
                reader.onload = function(ev) {
                    self.addImagePreview(ev.target.result, file.name);
                    // Focus on input to encourage user to add text
                    $('#bizchat-input').focus();
                };
                reader.readAsDataURL(file);
            } else if (file.type && file.type.indexOf('audio/') === 0) {
                // For audio: process immediately (upload and transcribe)
                self.processAudioFile(file);
            } else {
                alert('Chỉ hỗ trợ file ảnh và âm thanh.');
                return;
            }
        }

        addImagePreview(imageSrc, fileName) {
            // Create image preview area if not exists
            if (!$('#bizchat-image-preview').length) {
                const previewHtml = `
                    <div id="bizchat-image-preview" class="bizchat-image-preview">
                        <div class="bizchat-preview-label">Ảnh đã chọn:</div>
                        <div class="bizchat-preview-images"></div>
                        <button class="bizchat-remove-all-images" type="button">Xóa tất cả ảnh</button>
                    </div>
                `;
                $('.bizchat-input-area').prepend(previewHtml);
            }
            
            // Add image to preview
            const imageId = 'img_' + Date.now();
            const imageHtml = `
                <div class="bizchat-preview-item" data-image-id="${imageId}">
                    <img src="${imageSrc}" alt="${fileName}" />
                    <button class="bizchat-remove-image" data-image-id="${imageId}" type="button">&times;</button>
                </div>
            `;
            $('#bizchat-image-preview .bizchat-preview-images').append(imageHtml);
            
            // Store image data for sending
            if (!this.selectedImages) this.selectedImages = [];
            this.selectedImages.push({
                id: imageId,
                src: imageSrc,
                name: fileName
            });
            
            // Show preview area
            $('#bizchat-image-preview').show();
            
            // Update send button state
            this.updateSendButton();
            
            // Bind remove events
            this.bindImagePreviewEvents();
        }

        bindImagePreviewEvents() {
            const self = this;
            
            // Remove single image
            $(document).off('click', '.bizchat-remove-image').on('click', '.bizchat-remove-image', function(e) {
                e.preventDefault();
                const imageId = $(this).data('image-id');
                $(this).parent().remove();
                
                // Remove from selectedImages
                if (self.selectedImages) {
                    self.selectedImages = self.selectedImages.filter(img => img.id !== imageId);
                }
                
                // Hide preview if no images left
                if (!$('.bizchat-preview-item').length) {
                    $('#bizchat-image-preview').hide();
                }
                
                // Update send button state
                self.updateSendButton();
            });
            
            // Remove all images
            $(document).off('click', '.bizchat-remove-all-images').on('click', '.bizchat-remove-all-images', function(e) {
                e.preventDefault();
                $('#bizchat-image-preview').hide();
                $('.bizchat-preview-images').empty();
                self.selectedImages = [];
                
                // Update send button state
                self.updateSendButton();
            });
        }

        processAudioFile(file) {
            const self = this;
            self.showTyping();
            
            const fd = new FormData();
            fd.append('action', 'bizcity_webchat_upload');
            fd.append('file', file);
            fd.append('session_id', this.options.sessionId);
            fd.append('_wpnonce', this.options.nonce);
            
            $.ajax({
                url: this.options.ajaxurl,
                method: 'POST',
                processData: false,
                contentType: false,
                data: fd,
                success: function(res) {
                    self.hideTyping();
                    if (res && res.success && res.data) {
                        if (res.data.transcript) {
                            // Set transcript as input value for user to edit/send
                            $('#bizchat-input').val(res.data.transcript);
                            $('#bizchat-input').focus();
                        } else if (res.data.reply) {
                            self.appendMessage(res.data.reply, 'bot');
                        } else {
                            self.appendMessage('Đã nhận file audio.', 'bot');
                        }
                    } else {
                        self.appendMessage('Có lỗi khi xử lý file audio.', 'bot');
                    }
                },
                error: function() {
                    self.hideTyping();
                    self.appendMessage('Có lỗi khi upload file!', 'bot');
                }
            });
        }

        // ========== Voice Input (Web Speech API) ==========
        initSpeechRecognition() {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            const self = this;
            
            if (!SpeechRecognition) {
                $('#bizchat-voice-btn').prop('disabled', true).attr('title', 'Trình duyệt không hỗ trợ nhận diện giọng nói');
                return;
            }
            
            this.recognition = new SpeechRecognition();
            this.recognition.lang = 'vi-VN';
            this.recognition.continuous = false;
            this.recognition.interimResults = false;
            
            this.recognition.onstart = function() {
                self.isRecording = true;
                $('#bizchat-voice-btn').addClass('recording');
            };
            
            this.recognition.onend = function() {
                self.isRecording = false;
                $('#bizchat-voice-btn').removeClass('recording');
            };
            
            this.recognition.onerror = function(event) {
                self.isRecording = false;
                $('#bizchat-voice-btn').removeClass('recording');
                if (event.error !== 'aborted') {
                    console.log('Speech recognition error:', event.error);
                }
            };
            
            this.recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                $('#bizchat-input').val(transcript).focus();
            };
        }

        toggleVoiceInput() {
            if (!this.recognition) {
                alert('Trình duyệt này không hỗ trợ nhận diện giọng nói.');
                return;
            }
            
            if (this.isRecording) {
                this.recognition.stop();
            } else {
                this.recognition.start();
            }
        }

        // ========== Mobile Keyboard Fix ==========
        initKeyboardFix() {
            const self = this;
            
            function updateKeyboardVar() {
                const vv = window.visualViewport;
                if (!vv) {
                    document.documentElement.style.setProperty('--bizchat-kb', '0px');
                    return;
                }
                const kb = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
                document.documentElement.style.setProperty('--bizchat-kb', kb + 'px');
            }
            
            updateKeyboardVar();
            window.addEventListener('resize', updateKeyboardVar);
            
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', updateKeyboardVar);
                window.visualViewport.addEventListener('scroll', updateKeyboardVar);
            }
            
            $(document).on('focus', '#bizchat-input', function() {
                setTimeout(function() {
                    updateKeyboardVar();
                    try { self.scrollToBottom(); } catch(e) {}
                }, 120);
            });
            
            $(document).on('blur', '#bizchat-input', function() {
                setTimeout(function() {
                    document.documentElement.style.setProperty('--bizchat-kb', '0px');
                }, 80);
            });
        }

        // ========== Utilities ==========
        generateMessageHtml(message, from, skipAnimation) {
            const avatar = this.getAvatar(from);
            const time = this.formatTime(new Date());
            
            return `
                <div class="bizchat-message ${from}" ${skipAnimation ? '' : 'style="animation: bizchat-fade-in 0.3s ease"'}>
                    <div class="bizchat-message-avatar">${avatar}</div>
                    <div class="bizchat-message-content">
                        <div class="bizchat-message-bubble">${message}</div>
                        <div class="bizchat-message-time">${time}</div>
                    </div>
                </div>
            `;
        }

        formatTime(date) {
            return date.toLocaleTimeString('vi-VN', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Convert markdown-like text to HTML for bot messages.
         * Handles: headings, bold, italic, inline code, code blocks, lists, line breaks.
         */
        formatMessage(text) {
            if (!text) return '';
            // If already contains HTML tags, return as-is
            if (/<\/?(?:div|p|br|h[1-6]|ul|ol|li|strong|em|table|tr|td|th|blockquote|pre|code|span|a|img)[\s>]/i.test(text)) {
                return text;
            }
            let t = this.escapeHtml(text);
            // URL auto-link (after escape, before markdown)
            t = t.replace(/(https?:\/\/[^\s<\]]+)/g, function(m, url) {
                // Balance parentheses — trim unmatched trailing ')'
                var open = (url.match(/\(/g) || []).length;
                var close = (url.match(/\)/g) || []).length;
                var after = '';
                while (close > open && url.endsWith(')')) { after = ')' + after; url = url.slice(0, -1); close--; }
                return '<a href="' + url + '" target="_blank" rel="noopener" style="color:#7c3aed;text-decoration:underline;">' + url + '</a>' + after;
            });
            // Code blocks: ```...```
            t = t.replace(/```([\s\S]*?)```/g, '<pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px;margin:8px 0"><code>$1</code></pre>');
            // Headings: ### / ## / #
            t = t.replace(/^### (.+)$/gm, '<h4 style="margin:8px 0 4px;font-size:14px;font-weight:700">$1</h4>');
            t = t.replace(/^## (.+)$/gm, '<h3 style="margin:8px 0 4px;font-size:15px;font-weight:700">$1</h3>');
            t = t.replace(/^# (.+)$/gm, '<h2 style="margin:8px 0 4px;font-size:16px;font-weight:700">$1</h2>');
            // Bold + Italic: ***text***
            t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
            // Bold: **text**
            t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            // Italic: *text*
            t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
            // Inline code: `text`
            t = t.replace(/`([^`]+)`/g, '<code style="background:rgba(0,0,0,0.06);padding:1px 5px;border-radius:4px;font-size:12px">$1</code>');
            // Unordered list: - item
            t = t.replace(/((?:^|\n)- .+(?:\n- .+)*)/g, function(block) {
                var items = block.trim().split('\n').map(function(line) {
                    return '<li>' + line.replace(/^- /, '') + '</li>';
                }).join('');
                return '<ul style="margin:6px 0;padding-left:20px">' + items + '</ul>';
            });
            // Ordered list: 1. item
            t = t.replace(/((?:^|\n)\d+\. .+(?:\n\d+\. .+)*)/g, function(block) {
                var items = block.trim().split('\n').map(function(line) {
                    return '<li>' + line.replace(/^\d+\.\s*/, '') + '</li>';
                }).join('');
                return '<ol style="margin:6px 0;padding-left:20px">' + items + '</ol>';
            });
            // Line breaks (but not inside block elements)
            t = t.replace(/\n/g, '<br>');
            // Clean up double <br> after block elements
            t = t.replace(/(<\/(?:h[2-4]|ul|ol|pre|li)>)<br>/g, '$1');
            t = t.replace(/<br>(<(?:h[2-4]|ul|ol|pre))/g, '$1');
            return t;
        }

        generateId() {
            return Math.random().toString(36).substring(2, 9);
        }

        generateSessionId() {
            let sessionId = localStorage.getItem('bizchat_session_id');
            if (!sessionId) {
                sessionId = 'sess_' + this.generateId() + '_' + Date.now();
                localStorage.setItem('bizchat_session_id', sessionId);
            }
            return sessionId;
        }
    }

    // Initialize when document ready
    $(document).ready(function() {
        if (typeof bizcity_webchat_vars !== 'undefined') {
            window.BizChatWidget = new BizChatWidget();
        }
    });

})(jQuery);