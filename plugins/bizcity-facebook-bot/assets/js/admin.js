/**
 * BizCity Facebook Bot - Admin Scripts
 */

(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {
        initBotManagement();
        initListener();
        initTestAPI();
        initInbox();
    });

    /**
     * Bot Management Functions
     */
    function initBotManagement() {
        // Save bot form
        $('#bot-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $submitBtn = $form.find('button[type="submit"]');
            var originalText = $submitBtn.text();
            
            $submitBtn.text('Đang lưu...').prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_save',
                    nonce: bizcityFBBot.nonce,
                    bot_id: $form.find('[name="bot_id"]').val(),
                    bot_name: $form.find('[name="bot_name"]').val(),
                    page_id: $form.find('[name="page_id"]').val(),
                    page_token: $form.find('[name="page_token"]').val(),
                    app_id: $form.find('[name="app_id"]').val(),
                    app_secret: $form.find('[name="app_secret"]').val(),
                    status: $form.find('[name="status"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'Đã lưu bot thành công!');
                        setTimeout(function() {
                            window.location.href = response.data.redirect || window.location.href;
                        }, 1000);
                    } else {
                        showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                },
                error: function() {
                    showNotice('error', 'Lỗi kết nối server');
                },
                complete: function() {
                    $submitBtn.text(originalText).prop('disabled', false);
                }
            });
        });

        // Delete bot
        $(document).on('click', '.delete-bot', function(e) {
            e.preventDefault();
            
            if (!confirm('Bạn có chắc muốn xóa bot này?')) {
                return;
            }
            
            var botId = $(this).data('bot-id');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_delete',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'Đã xóa bot');
                        location.reload();
                    } else {
                        showNotice('error', response.data.message || 'Có lỗi xảy ra');
                    }
                }
            });
        });

        // Test bot connection
        $(document).on('click', '.test-bot', function(e) {
            e.preventDefault();
            
            var botId = $(this).data('bot-id');
            var $btn = $(this);
            
            $btn.text('Testing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_test',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', 'Bot hoạt động bình thường! Page: ' + response.data.page_name);
                    } else {
                        showNotice('error', response.data.message || 'Không thể kết nối bot');
                    }
                },
                complete: function() {
                    $btn.text('Test');
                }
            });
        });

        // Verify webhook
        $(document).on('click', '.verify-webhook-btn', function() {
            var botId = $(this).data('bot-id');
            var $result = $('#webhook-result');
            
            $result.html('<span style="color:#666;">Đang xác minh...</span>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_verify_webhook',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">✅ ' + response.data.message + '</span>');
                    } else {
                        $result.html('<span style="color:red;">❌ ' + response.data.message + '</span>');
                    }
                }
            });
        });
    }

    /**
     * Listener Functions
     */
    function initListener() {
        var listenerInterval = null;
        var $output = $('#listener-output');
        
        if (!$output.length) return;

        // Start listener
        $('#btn-start-listener').on('click', function() {
            var botId = $('#listener-bot-select').val();
            
            if (!botId) {
                showNotice('error', 'Vui lòng chọn bot trước');
                return;
            }
            
            var $btn = $(this);
            $btn.text('Đang kết nối...').prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_start_listener',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success) {
                        $btn.text('⏹ Dừng').prop('disabled', false);
                        $output.html('<div class="log-entry"><span class="log-time">[' + new Date().toLocaleTimeString() + ']</span> Đang lắng nghe webhook...</div>');
                        
                        // Start polling
                        listenerInterval = setInterval(function() {
                            checkListener(botId);
                        }, 2000);
                    } else {
                        showNotice('error', response.data.message);
                        $btn.text('▶ Bắt đầu').prop('disabled', false);
                    }
                }
            });
        });

        // Stop listener
        $('#btn-stop-listener').on('click', function() {
            var botId = $('#listener-bot-select').val();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_stop_listener',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (listenerInterval) {
                        clearInterval(listenerInterval);
                        listenerInterval = null;
                    }
                    $('#btn-start-listener').text('▶ Bắt đầu').prop('disabled', false);
                    appendLog('Đã dừng lắng nghe');
                }
            });
        });

        function checkListener(botId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_check_listener',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success && response.data.events) {
                        response.data.events.forEach(function(event) {
                            appendLogEvent(event);
                        });
                    }
                }
            });
        }

        function appendLog(message) {
            var time = new Date().toLocaleTimeString();
            $output.append('<div class="log-entry"><span class="log-time">[' + time + ']</span> ' + message + '</div>');
            $output.scrollTop($output[0].scrollHeight);
        }

        function appendLogEvent(event) {
            var time = new Date().toLocaleTimeString();
            var html = '<div class="log-entry">';
            html += '<span class="log-time">[' + time + ']</span> ';
            html += '<span class="log-event">' + event.event_name + '</span> ';
            html += 'từ <span class="log-user">' + (event.display_name || event.sender_id) + '</span>';
            if (event.text) {
                html += '<br><small style="color:#aaa;margin-left:80px;">' + event.text + '</small>';
            }
            html += '</div>';
            $output.append(html);
            $output.scrollTop($output[0].scrollHeight);
        }
    }

    /**
     * Test API Functions
     */
    function initTestAPI() {
        var $container = $('.test-api-container');
        if (!$container.length) return;

        // Send test message
        $('#btn-send-test-message').on('click', function() {
            var botId = $('#test-bot-select').val();
            var userId = $('#test-user-id').val();
            var message = $('#test-message').val();
            
            if (!botId || !userId || !message) {
                showNotice('error', 'Vui lòng điền đầy đủ thông tin');
                return;
            }
            
            var $btn = $(this);
            var $result = $('#send-message-result');
            
            $btn.prop('disabled', true).text('Đang gửi...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_send_message',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId,
                    user_id: userId,
                    message: message
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">✅ Đã gửi thành công!</span><pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                    } else {
                        $result.html('<span style="color:red;">❌ ' + response.data.message + '</span>');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('📤 Gửi tin nhắn');
                }
            });
        });

        // Send test photo
        $('#btn-send-test-photo').on('click', function() {
            var botId = $('#test-bot-select').val();
            var userId = $('#test-user-id').val();
            var photoUrl = $('#test-photo-url').val();
            
            if (!botId || !userId || !photoUrl) {
                showNotice('error', 'Vui lòng điền đầy đủ thông tin');
                return;
            }
            
            var $btn = $(this);
            var $result = $('#send-photo-result');
            
            $btn.prop('disabled', true).text('Đang gửi...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_send_photo',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId,
                    user_id: userId,
                    photo_url: photoUrl
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span style="color:green;">✅ Đã gửi ảnh thành công!</span><pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                    } else {
                        $result.html('<span style="color:red;">❌ ' + response.data.message + '</span>');
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).text('🖼 Gửi ảnh');
                }
            });
        });

        // Load user IDs for selected bot
        $('#test-bot-select').on('change', function() {
            var botId = $(this).val();
            if (!botId) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_get_user_ids',
                    nonce: bizcityFBBot.nonce,
                    bot_id: botId
                },
                success: function(response) {
                    if (response.success && response.data.users) {
                        var html = '<option value="">-- Chọn hoặc nhập --</option>';
                        response.data.users.forEach(function(user) {
                            html += '<option value="' + user.user_id + '">' + (user.display_name || user.user_id) + '</option>';
                        });
                        $('#test-user-id-select').html(html);
                    }
                }
            });
        });

        // Copy selected user ID to input
        $('#test-user-id-select').on('change', function() {
            var userId = $(this).val();
            if (userId) {
                $('#test-user-id').val(userId);
            }
        });
    }

    /**
     * Inbox Functions
     */
    function initInbox() {
        var $inbox = $('.inbox-container');
        if (!$inbox.length) return;

        var currentUserId = '';
        var currentBotId = '';
        var pollInterval = null;
        var lastMessageId = 0;

        // Load contacts
        function loadContacts() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_get_contacts',
                    nonce: bizcityFBBot.nonce,
                    bot_id: currentBotId
                },
                success: function(response) {
                    if (response.success) {
                        renderContacts(response.data.contacts);
                    }
                }
            });
        }

        function renderContacts(contacts) {
            var html = '';
            contacts.forEach(function(contact) {
                html += '<div class="user-item" data-user-id="' + contact.user_id + '">';
                html += '<div class="user-avatar">' + (contact.display_name ? contact.display_name.charAt(0) : '👤') + '</div>';
                html += '<div class="user-info">';
                html += '<div class="user-name">' + (contact.display_name || contact.user_id) + '</div>';
                html += '<div class="user-preview">' + (contact.last_message || '') + '</div>';
                html += '</div></div>';
            });
            $('.inbox-sidebar').html(html);
        }

        // Select user
        $(document).on('click', '.user-item', function() {
            var userId = $(this).data('user-id');
            if (userId === currentUserId) return;
            
            currentUserId = userId;
            $('.user-item').removeClass('active');
            $(this).addClass('active');
            
            loadMessages(userId);
        });

        function loadMessages(userId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_get_messages',
                    nonce: bizcityFBBot.nonce,
                    bot_id: currentBotId,
                    user_id: userId
                },
                success: function(response) {
                    if (response.success) {
                        renderMessages(response.data.messages);
                        if (response.data.messages.length) {
                            lastMessageId = response.data.messages[response.data.messages.length - 1].id;
                        }
                    }
                }
            });
        }

        function renderMessages(messages) {
            var html = '';
            messages.forEach(function(msg) {
                var bubbleClass = msg.message_type === 'user' ? 'user' : 'bot';
                html += '<div class="message-bubble ' + bubbleClass + '">';
                html += msg.message_text;
                html += '</div>';
            });
            $('.inbox-messages').html(html);
            $('.inbox-messages').scrollTop($('.inbox-messages')[0].scrollHeight);
        }

        // Send message
        $('#inbox-send-btn').on('click', function() {
            var message = $('#inbox-input').val().trim();
            if (!message || !currentUserId) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_facebook_bot_send_message',
                    nonce: bizcityFBBot.nonce,
                    bot_id: currentBotId,
                    user_id: currentUserId,
                    message: message
                },
                success: function(response) {
                    if (response.success) {
                        $('#inbox-input').val('');
                        loadMessages(currentUserId);
                    }
                }
            });
        });

        $('#inbox-input').on('keypress', function(e) {
            if (e.which === 13) {
                $('#inbox-send-btn').click();
            }
        });
    }

    /**
     * Helper Functions
     */
    function showNotice(type, message) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.bizcity-fb-bot-wrap h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

})(jQuery);
