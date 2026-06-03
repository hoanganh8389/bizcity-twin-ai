/* BizCity Zalo Bot Admin JavaScript */

(function($) {
	'use strict';
	
	$(document).ready(function() {
		
		// ===== Webhook Listener Page =====
		var listenerPageInterval = null;
		var listenerPageStartTime = null;
		var selectedBotId = null;
		
		// Bot selection for listener page
		$('#listener-bot-select').on('change', function() {
			selectedBotId = $(this).val();
			
			// Reset any previous listener first
			if (listenerPageInterval) {
				clearInterval(listenerPageInterval);
				listenerPageInterval = null;
			}
			listenerPageStartTime = null;
			$('#listener-status-container').hide();
			$('#listener-results-container').hide();
			$('#btn-stop-listening').hide();
			
			if (selectedBotId) {
				// Enable start button
				$('#btn-start-listening').prop('disabled', false).show();
				
				// Show bot info
				var webhookUrl = $(this).find('option:selected').data('webhook-url');
				$('#bot-info-content').html(
					'<p><strong>Webhook URL:</strong> <code>' + webhookUrl + '</code></p>' +
					'<p><strong>Status:</strong> <span style="color: #46b450;">✓ Ready to listen</span></p>'
				);
				$('#bot-info-row').show();
				
				// Clean up any previous listening state
				if (selectedBotId) {
					$.post(bizcityZaloBot.ajaxUrl, {
						action: 'bizcity_zalo_bot_stop_listener',
						nonce: bizcityZaloBot.nonce,
						bot_id: selectedBotId
					});
				}
			} else {
				$('#btn-start-listening').prop('disabled', true);
				$('#bot-info-row').hide();
			}
		});
		
		// Start listening on dedicated page
		$('#btn-start-listening').on('click', function(e) {
			e.preventDefault();
			
			if (!selectedBotId) {
				showMessage('error', 'Please select a bot first');
				return;
			}
			
			var $button = $(this);
			$button.prop('disabled', true);
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_start_listener',
				nonce: bizcityZaloBot.nonce,
				bot_id: selectedBotId
			}, function(response) {
				if (response.success) {
					$button.hide();
					$('#btn-stop-listening').show();
					
					listenerPageStartTime = Date.now();
					
					$('#listener-status-container').html(
						'<div style="background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">' +
						'<div style="text-align: center;">' +
						'<div class="dashicons dashicons-update bizcity-rotating" style="font-size: 48px; color: #00a0d2; width: 48px; height: 48px;"></div>' +
						'<h2 style="color: #00a0d2; margin: 15px 0 10px 0;">Listening for Webhooks...</h2>' +
						'<p style="font-size: 16px; color: #666; margin: 10px 0;">Send a message or image to your Zalo bot now</p>' +
						'<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 20px 0; border: 1px solid #e0e0e0;">' +
						'<p style="margin: 5px 0;"><strong>💬 Text Example:</strong> <code>"Hello bot"</code></p>' +
						'<p style="margin: 5px 0;"><strong>🖼️ Image Example:</strong> Send any photo</p>' +
						'</div>' +
						'<p id="listener-page-timer" style="font-size: 14px; color: #999; margin: 15px 0;">Elapsed: 0s</p>' +
						'</div>' +
						'</div>'
					).show();
					
					$('#listener-results-container').hide();
					
					// Start polling
					listenerPageInterval = setInterval(function() {
						checkListenerPage();
						updateListenerPageTimer();
					}, 2000);
					
					showMessage('success', response.data.message);
				} else {
					$button.prop('disabled', false);
					showMessage('error', response.data.message);
				}
			}).fail(function() {
				$button.prop('disabled', false);
				showMessage('error', 'Network error');
			});
		});
		
		// Stop listening on dedicated page
		$('#btn-stop-listening').on('click', function(e) {
			e.preventDefault();
			stopListenerPage();
			showMessage('info', 'Listening stopped');
		});
		
		function updateListenerPageTimer() {
			if (listenerPageStartTime) {
				var elapsed = Math.floor((Date.now() - listenerPageStartTime) / 1000);
				$('#listener-page-timer').text('Elapsed: ' + elapsed + 's');
			}
		}
		
		function checkListenerPage() {
			if (!selectedBotId) return;
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_check_listener',
				nonce: bizcityZaloBot.nonce,
				bot_id: selectedBotId
			}, function(response) {
				if (response.success && response.data.data) {
					// Webhook received!
					clearInterval(listenerPageInterval);
					listenerPageInterval = null;
					
					var webhookData = response.data.data;
					displayWebhookData(webhookData);
					
					$('#listener-status-container').hide();
					$('#btn-start-listening').show().prop('disabled', false);
					$('#btn-stop-listening').hide();
					
					showMessage('success', '✅ Webhook received successfully!');
				} else if (!response.success) {
					// Listener expired or error
					stopListenerPage();
					showMessage('info', response.data.message || 'Listener stopped');
				}
			}).fail(function() {
				stopListenerPage();
				showMessage('error', 'Network error while checking listener');
			});
		}
		
		function stopListenerPage() {
			if (listenerPageInterval) {
				clearInterval(listenerPageInterval);
				listenerPageInterval = null;
			}
			listenerPageStartTime = null;
			
			if (selectedBotId) {
				$.post(bizcityZaloBot.ajaxUrl, {
					action: 'bizcity_zalo_bot_stop_listener',
					nonce: bizcityZaloBot.nonce,
					bot_id: selectedBotId
				});
			}
			
			// Only enable if a bot is selected
			var hasBotSelected = selectedBotId && selectedBotId !== '';
			$('#btn-start-listening').show().prop('disabled', !hasBotSelected);
			$('#btn-stop-listening').hide();
			$('#listener-status-container').hide();
		}
		
		function displayWebhookData(webhookData) {
			var eventName = webhookData.event_name || '';
			var message = webhookData.message || {};
			var rawJson = webhookData.raw_json || '';
			var receivedAt = webhookData.received_at || '';
			
			var html = '<div style="margin-bottom: 20px;">';
			
			// Event info
			html += '<div style="background: #ecf7ed; padding: 15px; border-radius: 6px; border-left: 4px solid #46b450; margin-bottom: 20px;">';
			html += '<p style="margin: 0; font-size: 16px;"><strong>Event:</strong> <code style="background: #fff; padding: 4px 8px; border-radius: 4px;">' + escapeHtml(eventName) + '</code></p>';
			html += '<p style="margin: 10px 0 0 0;"><strong>Received At:</strong> ' + receivedAt + '</p>';
			html += '</div>';
			
			// Message details
			if (eventName === 'message.text.received' && message.text) {
				html += '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 20px;">';
				html += '<h3 style="margin-top: 0; color: #333;"><span class="dashicons dashicons-format-chat"></span> Text Message</h3>';
				html += '<div style="background: #fff; padding: 15px; border-radius: 6px; border: 1px solid #ddd;">';
				html += '<p style="font-size: 16px; margin: 0;">' + escapeHtml(message.text) + '</p>';
				html += '</div>';
				
				if (message.from) {
					html += '<div style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 4px; border: 1px solid #e0e0e0;">';
					html += '<p style="margin: 5px 0;"><strong>From:</strong> ' + escapeHtml(message.from.display_name || message.from.id) + '</p>';
					html += '<p style="margin: 5px 0;"><strong>User ID:</strong> <code>' + escapeHtml(message.from.id) + '</code></p>';
					html += '</div>';
				}
				html += '</div>';
				
			} else if (eventName === 'message.image.received' && message.attachments) {
				html += '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 20px;">';
				html += '<h3 style="margin-top: 0; color: #333;"><span class="dashicons dashicons-format-image"></span> Image Message</h3>';
				
				message.attachments.forEach(function(att) {
					if (att.type === 'image' && att.payload && att.payload.url) {
						html += '<div style="text-align: center; margin: 15px 0;">';
						html += '<a href="' + att.payload.url + '" target="_blank">';
						html += '<img src="' + att.payload.url + '" style="max-width: 100%; max-height: 400px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: transform 0.2s;" onmouseover="this.style.transform=\'scale(1.02)\'" onmouseout="this.style.transform=\'scale(1)\'" />';
						html += '</a>';
						html += '<p style="margin-top: 10px; color: #666;"><small>Click image to open in new tab</small></p>';
						html += '</div>';
					}
				});
				
				if (message.from) {
					html += '<div style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 4px; border: 1px solid #e0e0e0;">';
					html += '<p style="margin: 5px 0;"><strong>From:</strong> ' + escapeHtml(message.from.display_name || message.from.id) + '</p>';
					html += '<p style="margin: 5px 0;"><strong>User ID:</strong> <code>' + escapeHtml(message.from.id) + '</code></p>';
					html += '</div>';
				}
				html += '</div>';
			}
			
			// Raw JSON
			html += '<div style="margin-top: 20px;">';
			html += '<h3 style="color: #333;"><span class="dashicons dashicons-media-code"></span> Full JSON Payload</h3>';
			html += '<pre style="background: #282c34; color: #abb2bf; padding: 20px; border-radius: 8px; overflow: auto; max-height: 500px; font-size: 13px; line-height: 1.6; box-shadow: inset 0 2px 8px rgba(0,0,0,0.2);">' + escapeHtml(rawJson) + '</pre>';
			html += '<button type="button" class="button button-secondary" onclick="copyToClipboard(\'' + escapeHtml(rawJson).replace(/'/g, "\\'") + '\')" style="margin-top: 10px;">';
			html += '<span class="dashicons dashicons-clipboard"></span> Copy JSON';
			html += '</button>';
			html += '</div>';
			
			html += '</div>';
			
			$('#listener-results-content').html(html);
			$('#listener-results-container').show();
			
			// Scroll to results
			$('html, body').animate({
				scrollTop: $('#listener-results-container').offset().top - 100
			}, 500);
		}
		
		// Global copy function
		window.copyToClipboard = function(text) {
			var $temp = $('<textarea>');
			$('body').append($temp);
			$temp.val(text).select();
			try {
				document.execCommand('copy');
				showMessage('success', 'Copied to clipboard!');
			} catch(e) {
				showMessage('error', 'Failed to copy');
			}
			$temp.remove();
		};
		
		// ===== Bot Edit Form =====
		
		// Save bot form
		$('#bot-form').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var $button = $form.find('button[type="submit"]');
			var buttonText = $button.text();
			
			// Validate webhook secret
			var webhookSecret = $form.find('[name="webhook_secret"]').val();
			if (webhookSecret.length > 0 && webhookSecret.length < 8) {
				showMessage('error', 'Webhook secret must be at least 8 characters long');
				return;
			}
			if (webhookSecret.length > 64) {
				showMessage('error', 'Webhook secret must be less than 64 characters');
				return;
			}
			
			$button.prop('disabled', true).html(buttonText + ' <span class="bizcity-loading"></span>');
			
			var formData = {
				action: 'bizcity_zalo_bot_save',
				nonce: bizcityZaloBot.nonce,
				bot_id: $form.find('[name="bot_id"]').val(),
				bot_name: $form.find('[name="bot_name"]').val(),
				bot_token: $form.find('[name="bot_token"]').val(),
				app_id: $form.find('[name="app_id"]').val(),
				app_secret: $form.find('[name="app_secret"]').val(),
				oa_id: $form.find('[name="oa_id"]').val(),
				webhook_secret: webhookSecret,
				status: $form.find('[name="status"]').val()
			};
			
			$.post(bizcityZaloBot.ajaxUrl, formData, function(response) {
				$button.prop('disabled', false).text(buttonText);
				
				if (response.success) {
					showMessage('success', response.data.message);
					setTimeout(function() {
						window.location.href = 'admin.php?page=bizcity-zalo-bots';
					}, 1000);
				} else {
					showMessage('error', response.data.message || 'Error saving bot');
				}
			}).fail(function() {
				$button.prop('disabled', false).text(buttonText);
				showMessage('error', 'Network error');
			});
		});
		
		// Delete bot
		$('.delete-bot').on('click', function(e) {
			e.preventDefault();
			
			if (!confirm('Are you sure you want to delete this bot?')) {
				return;
			}
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_delete',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success) {
					$link.closest('tr').fadeOut(function() {
						$(this).remove();
					});
					showMessage('success', response.data.message);
				} else {
					showMessage('error', response.data.message || 'Error deleting bot');
				}
			});
		});
		
		// Set webhook button in table
		$('.set-webhook-btn').on('click', function(e) {
			e.preventDefault();
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			var originalText = $link.html();
			
			$link.html('⚙️ Setting... <span class="bizcity-loading"></span>');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_set_webhook',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}).done(function(response) {
				if (response.success) {
					showMessage('success', '✅ Webhook activated successfully!<br>URL: <code>' + response.data.webhook_url + '</code>');
					console.log('Webhook response:', response.data.data);
				} else {
					showMessage('error', '❌ Webhook setup failed: ' + (response.data ? response.data.message : 'Unknown error'));
					if (response.data && response.data.details) {
						console.error('Webhook error details:', response.data.details);
					}
				}
			}).fail(function(xhr, status, error) {
				showMessage('error', 'Network error: ' + error);
				console.error('Webhook AJAX error:', xhr.responseText);
			}).always(function() {
				// Đảm bảo luôn reset về trạng thái ban đầu
				$link.html(originalText);
			});
		});
		
		// Get Me button in table
		$('.get-me-btn').on('click', function(e) {
			e.preventDefault();
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			var originalText = $link.html();
			
			$link.html('🤖 Getting... <span class="bizcity-loading"></span>');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_get_me',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success) {
					var botData = response.data.data;
					var botInfo = '';
					
					if (botData && botData.result) {
						var result = botData.result;
						botInfo = '<br><strong>🆔 Bot ID:</strong> <code>' + (result.id || 'N/A') + '</code>' +
								  '<br><strong>🏷️ Display Name:</strong> <em>' + (result.display_name || 'N/A') + '</em>' +
								  '<br><strong>📛 Account Name:</strong> <code>' + (result.account_name || 'N/A') + '</code>' + 
								  '<br><strong>🏆 Account Type:</strong> <span style="padding: 2px 6px; background: #e3f2fd; border-radius: 3px; color: #1976d2;">' + (result.account_type || 'N/A') + '</span>' +
								  '<br><strong>👥 Can Join Groups:</strong> ' + (result.can_join_groups ? '✅ Yes' : '❌ No');
					}
					
					showMessage('success', '🚀 Bot is active and working!' + botInfo);
					console.log('Bot info:', botData);
				} else {
					showMessage('error', '⚠️ Bot check failed: ' + (response.data ? response.data.message : 'Unknown error'));
					if (response.data && response.data.details) {
						console.error('GetMe error details:', response.data.details);
					}
				}
			}).fail(function(xhr, status, error) {
				showMessage('error', 'Network error: ' + error);
				console.error('GetMe AJAX error:', xhr.responseText);
			}).always(function() {
				// Đảm bảo luôn reset về trạng thái ban đầu
				$link.html(originalText);
			});
		});
		
		// Delete Webhook button - Switch to getUpdates mode
		$('.delete-webhook-btn').on('click', function(e) {
			e.preventDefault();
			
			if (!confirm('⚠️ Bạn có chắc muốn xóa webhook?\n\nViệc này sẽ:\n• Tắt chế độ webhook hiện tại\n• Bật chế độ getUpdates (Long Polling)\n• Bot sẽ không nhận tin nhắn tự động nữa')) {
				return;
			}
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			var originalText = $link.html();
			
			$link.html('🔄 Deleting... <span class="bizcity-loading"></span>');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_delete_webhook',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success) {
					showMessage('success', '✅ ' + (response.data.message || 'Webhook deleted successfully'));
					console.log('Delete webhook response:', response.data.data);
				} else {
					showMessage('error', '⚠️ Delete webhook failed: ' + (response.data ? response.data.message : 'Unknown error'));
					if (response.data && response.data.details) {
						console.error('Delete webhook error details:', response.data.details);
					}
				}
			}).fail(function(xhr, status, error) {
				showMessage('error', 'Network error: ' + error);
				console.error('Delete webhook AJAX error:', xhr.responseText);
			}).always(function() {
				$link.html(originalText);
			});
		});
		
		// Get Updates button - Long polling mode
		$('.get-updates-btn').on('click', function(e) {
			e.preventDefault();
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			var originalText = $link.html();
			
			// Hiển thị form nhập offset
			var offset = prompt('📥 Get Updates - Long Polling\n\nNhập offset (ID tin nhắn cuối cùng):\nĐể trống để lấy tất cả tin nhắn mới', '');
			if (offset === null) return; // User cancelled
			
			$link.html('📥 Getting... <span class="bizcity-loading"></span>');
			
			var requestData = {
				action: 'bizcity_zalo_bot_get_updates',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId,
				limit: 10,
				timeout: 30
			};
			
			if (offset && offset.trim() !== '') {
				requestData.offset = parseInt(offset.trim());
			}
			
			$.post(bizcityZaloBot.ajaxUrl, requestData, function(response) {
				if (response.success) {
					var updates = response.data.data;
					var messageContent = '📥 Updates retrieved successfully!';
					
					if (updates && updates.result && updates.result.length > 0) {
						messageContent += '<br><br><strong>📊 Found ' + updates.result.length + ' update(s):</strong>';
						
						updates.result.forEach(function(update, index) {
							if (update.message) {
								var msg = update.message;
								var updateInfo = '<br>• Update #' + (index + 1) + 
									' (ID: <code>' + update.update_id + '</code>)';
								
								if (msg.text) {
									updateInfo += '<br>  💬 Text: "' + escapeHtml(msg.text).substring(0, 50) + (msg.text.length > 50 ? '...' : '') + '"';
								}
								
								if (msg.from) {
									updateInfo += '<br>  👤 From: ' + escapeHtml(msg.from.display_name || msg.from.id);
								}
								
								if (msg.timestamp) {
									updateInfo += '<br>  🕐 Time: ' + new Date(msg.timestamp).toLocaleString();
								}
								
								messageContent += updateInfo;
							}
						});
						
						// Gợi ý offset tiếp theo
						var lastUpdateId = updates.result[updates.result.length - 1].update_id;
						messageContent += '<br><br>💡 <strong>Next offset:</strong> <code>' + (lastUpdateId + 1) + '</code>';
						
					} else {
						messageContent += '<br><br>📭 No new updates found.';
					}
					
					showMessage('success', messageContent);
					console.log('Get updates response:', updates);
				} else {
					var errorMsg = '⚠️ Get updates failed: ' + (response.data ? response.data.message : 'Unknown error');
					
					// Kiểm tra lỗi webhook conflict
					if (response.data && response.data.message && response.data.message.toLowerCase().includes('webhook')) {
						errorMsg += '<br><br>💡 <strong>Tip:</strong> Sử dụng nút "🔄 Polling" để xóa webhook trước!';
					}
					
					showMessage('error', errorMsg);
					if (response.data && response.data.details) {
						console.error('Get updates error details:', response.data.details);
					}
				}
			}).fail(function(xhr, status, error) {
				showMessage('error', 'Network error: ' + error);
				console.error('Get updates AJAX error:', xhr.responseText);
			}).always(function() {
				$link.html(originalText);
			});
		});
		
		// Test bot
		$('.test-bot').on('click', function(e) {
			e.preventDefault();
			
			var $link = $(this);
			var botId = $link.data('bot-id');
			var originalText = $link.text();
			
			$link.html('Testing... <span class="bizcity-loading"></span>');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_test',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				$link.text(originalText);
				
				if (response.success) {
					showMessage('success', response.data.message);
					console.log('Bot info:', response.data.data);
				} else {
					showMessage('error', response.data.message || 'Connection failed');
				}
			}).fail(function() {
				$link.text(originalText);
				showMessage('error', 'Network error');
			});
		});
		
		// Set webhook on Zalo
		$('#btn-set-webhook').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var botId = $button.data('bot-id');
			var originalText = $button.text();
			
			$button.prop('disabled', true).html('Setting webhook... <span class="bizcity-loading"></span>');
			$('#webhook-result').html('');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_set_webhook',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				$button.prop('disabled', false).text(originalText);
				
				if (response.success) {
					$('#webhook-result').html(
						'<div class="notice notice-success inline"><p>' +
						'<strong>✅ Success!</strong><br>' +
						'Webhook URL: <code>' + response.data.webhook_url + '</code>' +
						'</p></div>'
					);
					showMessage('success', response.data.message);
				} else {
					$('#webhook-result').html(
						'<div class="notice notice-error inline"><p>' +
						'<strong>❌ Error:</strong> ' + response.data.message +
						'</p></div>'
					);
					showMessage('error', response.data.message);
				}
			}).fail(function() {
				$button.prop('disabled', false).text(originalText);
				$('#webhook-result').html(
					'<div class="notice notice-error inline"><p>' +
					'<strong>❌ Network error</strong> - Could not connect to server' +
					'</p></div>'
				);
				showMessage('error', 'Network error');
			});
		});
		
		// Real-time validation for webhook secret
		$('[name="webhook_secret"]').on('input', function() {
			var $input = $(this);
			var value = $input.val();
			var $description = $input.siblings('.description');
			
			if (value.length === 0) {
				$description.text('Secret key for webhook verification (8-64 characters required)').css('color', '#666');
			} else if (value.length < 8) {
				$description.text('⚠️ Too short - minimum 8 characters required').css('color', '#dc3232');
			} else if (value.length > 64) {
				$description.text('⚠️ Too long - maximum 64 characters allowed').css('color', '#dc3232');
			} else {
				$description.text('✅ Valid secret length (' + value.length + ' characters)').css('color', '#46b450');
			}
		});
		
		// Show message helper
		function showMessage(type, message) {
			var $message = $('<div class="bizcity-message ' + type + '">' + message + '</div>');
			$('.wrap').prepend($message);
			
			setTimeout(function() {
				$message.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		}
		
		// Auto-select webhook URL on click
		$('input[readonly]').on('click', function() {
			$(this).select();
			
			// Copy to clipboard
			try {
				document.execCommand('copy');
				showMessage('success', 'Copied to clipboard!');
			} catch(e) {
				// Silent fail
			}
		});
		
		// Webhook Listener
		var listenerInterval = null;
		var listenerStartTime = null;
		
		$('#btn-start-listener').on('click', function(e) {
			e.preventDefault();
			
			var $button = $(this);
			var $stopButton = $('#btn-stop-listener');
			var botId = $button.data('bot-id');
			
			$button.prop('disabled', true);
			$('#listener-status').html('').hide();
			$('#listener-result').html('').hide();
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_start_listener',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success) {
					$button.hide();
					$stopButton.show();
					
					listenerStartTime = Date.now();
					
					$('#listener-status').html(
						'<div class="notice notice-info inline">' +
						'<p><span class="dashicons dashicons-update bizcity-rotating"></span> ' +
						'<strong>Listening for webhooks...</strong><br>' +
						'<small>Send a text message or image to your Zalo bot now.</small><br>' +
						'<small>Examples:</small><br>' +
						'<code>• Text: "Hello bot"</code><br>' +
						'<code>• Image: Send any image</code><br>' +
						'<small id="listener-timer">Elapsed: 0s</small>' +
						'</p></div>'
					).show();
					
					// Start polling for webhook data
					listenerInterval = setInterval(function() {
						checkListener(botId);
						updateTimer();
					}, 2000);
					
					showMessage('success', response.data.message);
				} else {
					$button.prop('disabled', false);
					showMessage('error', response.data.message);
				}
			}).fail(function() {
				$button.prop('disabled', false);
				showMessage('error', 'Network error');
			});
		});
		
		$('#btn-stop-listener').on('click', function(e) {
			e.preventDefault();
			stopListener();
		});
		
		function updateTimer() {
			if (listenerStartTime) {
				var elapsed = Math.floor((Date.now() - listenerStartTime) / 1000);
				$('#listener-timer').text('Elapsed: ' + elapsed + 's');
			}
		}
		
		function checkListener(botId) {
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_check_listener',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success && response.data.data) {
					// Webhook received!
					clearInterval(listenerInterval);
					listenerInterval = null;
					
					var webhookData = response.data.data;
					var eventName = webhookData.event_name || '';
					var message = webhookData.message || {};
					
					var displayHtml = '<div class="notice notice-success inline">' +
						'<p><strong>✅ Webhook Received!</strong></p>' +
						'<p><strong>Event:</strong> <code>' + eventName + '</code></p>' +
						'<p><strong>Time:</strong> ' + webhookData.received_at + '</p>';
					
					// Display message details
					if (message.text) {
						displayHtml += '<p><strong>Text Message:</strong> <code>' + escapeHtml(message.text) + '</code></p>';
					}
					
					if (message.attachments && message.attachments.length > 0) {
						displayHtml += '<p><strong>Attachments:</strong></p><ul>';
						message.attachments.forEach(function(att) {
							if (att.type === 'image' && att.payload && att.payload.url) {
								displayHtml += '<li>Image: <a href="' + att.payload.url + '" target="_blank">' +
									'<img src="' + att.payload.url + '" style="max-width: 200px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;" />' +
									'</a></li>';
							}
						});
						displayHtml += '</ul>';
					}
					
					displayHtml += '<p><strong>Full JSON Payload:</strong></p>' +
						'<pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 400px; overflow: auto; font-size: 12px;">' +
						escapeHtml(webhookData.raw_json) +
						'</pre></div>';
					
					$('#listener-result').html(displayHtml).show();
					$('#listener-status').hide();
					$('#btn-start-listener').show().prop('disabled', false);
					$('#btn-stop-listener').hide();
					
					showMessage('success', 'Webhook data captured successfully!');
				} else if (!response.success) {
					// Listener expired or stopped
					stopListener();
					showMessage('info', response.data.message || 'Listener stopped');
				}
			}).fail(function() {
				stopListener();
				showMessage('error', 'Network error while checking listener');
			});
		}
		
		function stopListener() {
			if (listenerInterval) {
				clearInterval(listenerInterval);
				listenerInterval = null;
			}
			listenerStartTime = null;
			
			var botId = $('#btn-start-listener').data('bot-id');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_stop_listener',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			});
			
			$('#btn-start-listener').show().prop('disabled', false);
			$('#btn-stop-listener').hide();
			$('#listener-status').hide();
		}
		
		function escapeHtml(text) {
			if (typeof text !== 'string') return text;
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
		
		// ===== Test API Page Handlers =====
		var selectedTestBotId = null;
		var selectedBotToken = null;
		
		// Bot selection for Test API page
		$('#test-api-bot-select').on('change', function() {
			selectedTestBotId = $(this).val();
			selectedBotToken = $(this).find('option:selected').data('bot-token');
			
			if (selectedTestBotId) {
				showMessage('success', 'Đã chọn bot: ' + $(this).find('option:selected').text());
				
				// Load user IDs for this bot
				loadUserIds(selectedTestBotId);
			}
		});
		
		// Function to load user IDs
		function loadUserIds(botId) {
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_get_user_ids',
				nonce: bizcityZaloBot.nonce,
				bot_id: botId
			}, function(response) {
				if (response.success && response.data.user_ids) {
					var userIds = response.data.user_ids;
					
					// Update both dropdowns
					$('#send-message-user-select, #send-photo-user-select').html('<option value="">-- Hoặc chọn từ danh sách --</option>');
					
					if (userIds.length === 0) {
						$('#send-message-user-select, #send-photo-user-select').append('<option value="" disabled>Chưa có user nào nhắn tin với bot</option>');
					} else {
						userIds.forEach(function(item) {
							var lastSeen = new Date(item.last_seen).toLocaleString('vi-VN');
							$('#send-message-user-select, #send-photo-user-select').append(
								'<option value="' + item.user_id + '">' + item.user_id + ' (Lần cuối: ' + lastSeen + ')</option>'
							);
						});
					}
				}
			});
		}
		
		// When user selects from dropdown, populate input
		$('#send-message-user-select').on('change', function() {
			var userId = $(this).val();
			if (userId) {
				$('#send-message-chat-id').val(userId);
			}
		});
		
		$('#send-photo-user-select').on('change', function() {
			var userId = $(this).val();
			if (userId) {
				$('#send-photo-chat-id').val(userId);
			}
		});
		
		// Send Message button
		$('#btn-send-message').on('click', function() {
			if (!selectedTestBotId) {
				showMessage('error', 'Vui lòng chọn bot trước!');
				return;
			}
			
			var chatId = $('#send-message-chat-id').val().trim();
			var text = $('#send-message-text').val().trim();
			
			if (!chatId || !text) {
				showMessage('error', 'Vui lòng điền đầy đủ Chat ID và Text!');
				return;
			}
			
			var $button = $(this);
			var originalText = $button.html();
			
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Đang gửi...');
			$('#send-message-result').html('');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_send_message',
				nonce: bizcityZaloBot.nonce,
				bot_id: selectedTestBotId,
				chat_id: chatId,
				text: text
			}, function(response) {
				$button.prop('disabled', false).html(originalText);
				
				if (response.success) {
					$('#send-message-result').html(
						'<div class="notice notice-success inline" style="padding: 15px; border-radius: 4px;">' +
						'<p style="margin: 0;"><strong>✅ ' + response.data.message + '</strong></p>' +
						'<details style="margin-top: 10px;"><summary style="cursor: pointer;">📄 Xem phản hồi từ API</summary>' +
						'<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin-top: 10px; overflow-x: auto;">' +
						JSON.stringify(response.data.data, null, 2) +
						'</pre></details>' +
						'</div>'
					);
					showMessage('success', response.data.message);
				} else {
					$('#send-message-result').html(
						'<div class="notice notice-error inline" style="padding: 15px; border-radius: 4px;">' +
						'<p style="margin: 0;"><strong>❌ Lỗi:</strong> ' + response.data.message + '</p>' +
						(response.data.details ? '<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin-top: 10px; overflow-x: auto;">' + JSON.stringify(response.data.details, null, 2) + '</pre>' : '') +
						'</div>'
					);
					showMessage('error', response.data.message);
				}
			}).fail(function(xhr) {
				$button.prop('disabled', false).html(originalText);
				$('#send-message-result').html(
					'<div class="notice notice-error inline"><p><strong>❌ Lỗi kết nối</strong> - Không thể kết nối đến server</p></div>'
				);
				showMessage('error', 'Network error');
			});
		});
		
		// Send Photo button
		$('#btn-send-photo').on('click', function() {
			if (!selectedTestBotId) {
				showMessage('error', 'Vui lòng chọn bot trước!');
				return;
			}
			
			var chatId = $('#send-photo-chat-id').val().trim();
			var photo = $('#send-photo-url').val().trim();
			var caption = $('#send-photo-caption').val().trim();
			
			if (!chatId || !photo) {
				showMessage('error', 'Vui lòng điền đầy đủ Chat ID và Photo URL!');
				return;
			}
			
			var $button = $(this);
			var originalText = $button.html();
			
			$button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Đang gửi...');
			$('#send-photo-result').html('');
			
			$.post(bizcityZaloBot.ajaxUrl, {
				action: 'bizcity_zalo_bot_send_photo',
				nonce: bizcityZaloBot.nonce,
				bot_id: selectedTestBotId,
				chat_id: chatId,
				photo: photo,
				caption: caption
			}, function(response) {
				$button.prop('disabled', false).html(originalText);
				
				if (response.success) {
					$('#send-photo-result').html(
						'<div class="notice notice-success inline" style="padding: 15px; border-radius: 4px;">' +
						'<p style="margin: 0;"><strong>✅ ' + response.data.message + '</strong></p>' +
						'<div style="margin-top: 10px;"><img src="' + photo + '" style="max-width: 300px; border-radius: 4px; border: 1px solid #ddd;" /></div>' +
						'<details style="margin-top: 10px;"><summary style="cursor: pointer;">📄 Xem phản hồi từ API</summary>' +
						'<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin-top: 10px; overflow-x: auto;">' +
						JSON.stringify(response.data.data, null, 2) +
						'</pre></details>' +
						'</div>'
					);
					showMessage('success', response.data.message);
				} else {
					$('#send-photo-result').html(
						'<div class="notice notice-error inline" style="padding: 15px; border-radius: 4px;">' +
						'<p style="margin: 0;"><strong>❌ Lỗi:</strong> ' + response.data.message + '</p>' +
						(response.data.details ? '<pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin-top: 10px; overflow-x: auto;">' + JSON.stringify(response.data.details, null, 2) + '</pre>' : '') +
						'</div>'
					);
					showMessage('error', response.data.message);
				}
			}).fail(function(xhr) {
				$button.prop('disabled', false).html(originalText);
				$('#send-photo-result').html(
					'<div class="notice notice-error inline"><p><strong>❌ Lỗi kết nối</strong> - Không thể kết nối đến server</p></div>'
				);
				showMessage('error', 'Network error');
			});
		});
	});
	
})(jQuery);
