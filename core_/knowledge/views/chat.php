<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat Interface — Expanded dark-theme view (full page)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

$db = BizCity_Knowledge_Database::instance();
$character_id = isset($_GET['character_id']) ? intval($_GET['character_id']) : 0;
$character = $character_id ? $db->get_character($character_id) : null;
$characters = $db->get_characters(['status' => 'active', 'limit' => 100]);

if (!$character && !empty($characters)) {
    $character = $characters[0];
    $character_id = $character->id;
}

$greeting_messages = [];
if ($character && !empty($character->greeting_messages)) {
    $greeting_messages = json_decode($character->greeting_messages, true) ?: [];
}
$random_greeting = !empty($greeting_messages) ? $greeting_messages[array_rand($greeting_messages)] : 'Xin chào! Tôi có thể giúp gì cho bạn?';

// Use character name if available, overriding widget config
$char_name = $character ? $character->name : 'AI Assistant';
$char_model = ($character && !empty($character->model_id)) ? $character->model_id : 'GPT-4o-mini (OpenAI)';
$char_avatar = ($character && !empty($character->avatar)) ? $character->avatar : '';
?>

<style>
/* ===== FULL-PAGE DARK CHAT ===== */
.bkc-wrap {
    display: flex;
    height: calc(100vh - 32px); /* minus WP admin bar */
    background: #0f1019;
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    margin: 0px -20px 0;
    overflow: hidden;
}

/* Sidebar */
.bkc-sidebar {
    width: 260px;
    min-width: 260px;
    background: #131320;
    border-right: 1px solid rgba(255,255,255,0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.bkc-sidebar-hdr {
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.bkc-sidebar-hdr h3 { margin: 0; font-size: 14px; color: #fff; }
.bkc-sidebar-hdr button {
    padding: 4px 10px; border-radius: 8px;
    background: rgba(99,102,241,0.2); border: none; color: #a78bfa;
    font-size: 11px; cursor: pointer; transition: 0.2s;
}
.bkc-sidebar-hdr button:hover { background: rgba(99,102,241,0.4); }

.bkc-char-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}
.bkc-char-list::-webkit-scrollbar { width: 4px; }
.bkc-char-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.bkc-char {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    cursor: pointer;
    transition: 0.2s;
    text-decoration: none;
    color: #fff;
    border: 1px solid transparent;
    margin-bottom: 4px;
}
.bkc-char:hover { background: rgba(255,255,255,0.05); }
.bkc-char.active {
    background: rgba(99,102,241,0.15);
    border-color: rgba(99,102,241,0.3);
}
.bkc-char-av {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(139,92,246,0.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0; overflow: hidden;
}
.bkc-char-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bkc-char-info { flex: 1; min-width: 0; }
.bkc-char-name {
    font-size: 13px; font-weight: 500;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    color: rgba(255,255,255,0.85);
}
.bkc-char-model {
    font-size: 10px; color: rgba(255,255,255,0.35);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bkc-char .bkc-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #22c55e; flex-shrink: 0;
}

/* Main chat area */
.bkc-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Header */
.bkc-header {
    background: linear-gradient(135deg, #1e1b4b 0%, #312e81 100%);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.bkc-hdr-left { display: flex; align-items: center; gap: 12px; }
.bkc-hdr-av {
    width: 40px; height: 40px; border-radius: 50%;
    border: 2px solid rgba(139,92,246,0.4);
    display: flex; align-items: center; justify-content: center;
    background: rgba(139,92,246,0.2); font-size: 18px; overflow: hidden;
}
.bkc-hdr-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.bkc-hdr-info h2 { margin: 0; font-size: 16px; color: #fff; font-weight: 600; }
.bkc-hdr-info span {
    font-size: 11px; color: rgba(255,255,255,0.5);
    display: flex; align-items: center; gap: 6px;
}
.bkc-model-badge {
    background: rgba(99,102,241,0.2); color: #a78bfa;
    padding: 2px 8px; border-radius: 6px; font-size: 10px;
}
.bkc-hdr-right { display: flex; gap: 6px; }
.bkc-hdr-right button, .bkc-hdr-right a {
    padding: 6px 12px; border-radius: 8px;
    background: rgba(255,255,255,0.08); border: none;
    color: rgba(255,255,255,0.7); font-size: 12px;
    cursor: pointer; transition: 0.2s; display: flex;
    align-items: center; gap: 4px; text-decoration: none;
}
.bkc-hdr-right button:hover, .bkc-hdr-right a:hover {
    background: rgba(255,255,255,0.15); color: #fff;
}

/* Messages */
.bkc-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: #0f1019;
}
.bkc-messages::-webkit-scrollbar { width: 4px; }
.bkc-messages::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 2px; }

.bkc-msg {
    display: flex; margin-bottom: 14px; gap: 10px;
    animation: bkc-msg-in 0.3s ease;
}
.bkc-msg.user { flex-direction: row-reverse; }
@keyframes bkc-msg-in { from { opacity:0; transform: translateY(6px); } }

.bkc-msg-av {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0; overflow: hidden;
}
.bkc-msg.bot .bkc-msg-av { background: rgba(139,92,246,0.2); color: #a78bfa; }
.bkc-msg.user .bkc-msg-av { background: rgba(99,102,241,0.2); color: #818cf8; }
.bkc-msg-av img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

.bkc-msg-bubble {
    max-width: 65%;
    padding: 12px 16px;
    border-radius: 16px;
    font-size: 14px;
    line-height: 1.65;
    word-break: break-word;
}
.bkc-msg.bot .bkc-msg-bubble {
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.88);
    border-bottom-left-radius: 4px;
}
.bkc-msg.user .bkc-msg-bubble {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #fff;
    border-bottom-right-radius: 4px;
}
.bkc-msg-time {
    font-size: 10px; color: rgba(255,255,255,0.25);
    margin-top: 4px;
}
.bkc-msg.user .bkc-msg-time { text-align: right; }

/* Images in messages */
.bkc-msg-images { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.bkc-msg-images img {
    max-width: 180px; max-height: 140px;
    border-radius: 8px; cursor: pointer;
    border: 1px solid rgba(255,255,255,0.08);
}

/* Typing indicator */
.bkc-typing-wrap { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px; }
.bkc-typing-dots { display: flex; gap: 4px; padding: 14px 18px; background: rgba(255,255,255,0.06); border-radius: 16px; }
.bkc-typing-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: rgba(139,92,246,0.6);
    animation: bkc-dot-pulse 1.4s infinite ease-in-out;
}
.bkc-typing-dot:nth-child(2) { animation-delay: 0.2s; }
.bkc-typing-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes bkc-dot-pulse { 0%,80%,100%{opacity:0.3;transform:scale(0.8)} 40%{opacity:1;transform:scale(1.2)} }

/* Image preview area */
.bkc-img-preview {
    padding: 8px 20px;
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex; gap: 8px; flex-wrap: wrap;
}
.bkc-img-thumb {
    position: relative; width: 60px; height: 60px;
    border-radius: 8px; overflow: hidden;
    border: 1px solid rgba(255,255,255,0.1);
}
.bkc-img-thumb img { width: 100%; height: 100%; object-fit: cover; }
.bkc-img-thumb .bkc-img-rm {
    position: absolute; top: -4px; right: -4px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #ef4444; color: #fff; border: none;
    font-size: 10px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}

/* Input area */
.bkc-input-area {
    padding: 14px 20px;
    background: rgba(255,255,255,0.03);
    border-top: 1px solid rgba(255,255,255,0.06);
    display: flex;
    gap: 10px;
    align-items: flex-end;
}
.bkc-attach-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: rgba(255,255,255,0.06); border: none;
    color: rgba(255,255,255,0.4); cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: 0.2s; flex-shrink: 0;
}
.bkc-attach-btn:hover { background: rgba(255,255,255,0.12); color: #a78bfa; }
.bkc-input {
    flex: 1;
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 20px;
    padding: 10px 18px;
    font-size: 14px;
    color: #fff;
    outline: none;
    resize: none;
    min-height: 20px;
    max-height: 150px;
    transition: border-color 0.2s;
    line-height: 1.5;
}
.bkc-input::placeholder { color: rgba(255,255,255,0.3); }
.bkc-input:focus { border-color: rgba(139,92,246,0.5); }
.bkc-send-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: #6366f1; color: #fff; border: none;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; transition: all 0.2s;
}
.bkc-send-btn:hover { background: #8b5cf6; }
.bkc-send-btn:disabled { background: rgba(255,255,255,0.1); cursor: not-allowed; }
.bkc-vision-hint {
    font-size: 11px; color: rgba(139,92,246,0.7);
    padding: 4px 20px 0;
}

/* Empty state */
.bkc-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,0.3);
    font-size: 14px;
    gap: 12px;
}
.bkc-empty span { font-size: 48px; }
.bkc-empty a {
    padding: 8px 20px; border-radius: 10px;
    background: #6366f1; color: #fff; text-decoration: none;
    font-size: 13px; transition: 0.2s;
}
.bkc-empty a:hover { background: #8b5cf6; }

/* Responsive */
@media (max-width: 900px) {
    .bkc-sidebar { width: 200px; min-width: 200px; }
}
@media (max-width: 600px) {
    .bkc-sidebar { display: none; }
}
</style>

<div class="bkc-wrap">
    <!-- Sidebar -->
    <div class="bkc-sidebar">
        <div class="bkc-sidebar-hdr">
            <h3>💬 Characters</h3>
            <button type="button" id="bkc-clear-chat">🗑 Clear</button>
        </div>
        <div class="bkc-char-list">
            <?php foreach ($characters as $ch): ?>
            <a class="bkc-char <?php echo $ch->id == $character_id ? 'active' : ''; ?>"
               href="<?php echo esc_url(admin_url('admin.php?page=bizcity-knowledge-chat&character_id=' . $ch->id)); ?>">
                <div class="bkc-char-av">
                    <?php if (!empty($ch->avatar)): ?>
                        <img src="<?php echo esc_url($ch->avatar); ?>" alt="">
                    <?php else: ?>👤<?php endif; ?>
                </div>
                <div class="bkc-char-info">
                    <div class="bkc-char-name"><?php echo esc_html($ch->name); ?></div>
                    <div class="bkc-char-model"><?php echo esc_html($ch->model_id ?: 'GPT-4o-mini'); ?></div>
                </div>
                <?php if ($ch->id == $character_id): ?><span class="bkc-dot"></span><?php endif; ?>
            </a>
            <?php endforeach; ?>

            <?php if (empty($characters)): ?>
            <div style="padding:30px 12px;text-align:center;color:rgba(255,255,255,0.3);font-size:13px;">
                Chưa có character nào.<br>
                <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>"
                   style="color:#a78bfa;">Tạo Character →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main -->
    <div class="bkc-main">
        <?php if ($character): ?>
        <!-- Header -->
        <div class="bkc-header">
            <div class="bkc-hdr-left">
                <div class="bkc-hdr-av">
                    <?php if ($char_avatar): ?>
                        <img src="<?php echo esc_url($char_avatar); ?>" alt="">
                    <?php else: ?>🤖<?php endif; ?>
                </div>
                <div class="bkc-hdr-info">
                    <h2><?php echo esc_html($char_name); ?></h2>
                    <span>
                        Online · <span class="bkc-model-badge"><?php echo esc_html($char_model); ?></span>
                        · Temp <?php echo esc_html($character->creativity_level ?? 0.7); ?>
                    </span>
                </div>
            </div>
            <div class="bkc-hdr-right">
                <a href="<?php echo esc_url(admin_url('admin.php?page=bizcity-knowledge-character-edit&id=' . $character_id)); ?>">
                    ✏️ Edit
                </a>
            </div>
        </div>

        <!-- Messages -->
        <div class="bkc-messages" id="bkc-messages">
            <!-- Greeting (shown if no history) -->
            <div class="bkc-msg bot bkc-greeting">
                <div class="bkc-msg-av">
                    <?php if ($char_avatar): ?>
                        <img src="<?php echo esc_url($char_avatar); ?>" alt="">
                    <?php else: ?>🤖<?php endif; ?>
                </div>
                <div>
                    <div class="bkc-msg-bubble"><?php echo esc_html($random_greeting); ?></div>
                    <div class="bkc-msg-time"><?php echo current_time('H:i'); ?></div>
                </div>
            </div>
        </div>

        <!-- Image Preview -->
        <div class="bkc-img-preview" id="bkc-img-preview" style="display:none;"></div>

        <!-- Input -->
        <div class="bkc-input-area">
            <button type="button" class="bkc-attach-btn" id="bkc-attach" title="Đính kèm hình ảnh">📎</button>
            <input type="file" id="bkc-file-input" accept="image/*" multiple style="display:none;">
            <textarea id="bkc-input" class="bkc-input" placeholder="Nhập tin nhắn..." rows="1"></textarea>
            <button id="bkc-send" class="bkc-send-btn" disabled>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
            </button>
        </div>
        <div class="bkc-vision-hint" id="bkc-vision-hint" style="display:none;">
            👁 Vision model sẽ phân tích hình ảnh
        </div>

        <?php else: ?>
        <div class="bkc-empty">
            <span>💬</span>
            Chọn một character để bắt đầu chat
            <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-character-edit'); ?>">Tạo Character</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($character): ?>
<script>
jQuery(function($) {

    /* ── Config from unified admin chat vars ── */
    var V = typeof bizcity_admin_chat_vars !== 'undefined' ? bizcity_admin_chat_vars : {
        ajaxurl: '<?php echo admin_url("admin-ajax.php"); ?>',
        nonce: '<?php echo wp_create_nonce("bizcity_admin_chat"); ?>',
        session_id: 'adminchat_<?php echo get_current_blog_id() . "_" . get_current_user_id(); ?>',
        character_id: <?php echo $character_id; ?>
    };

    var charId    = <?php echo $character_id; ?>,
        $msgs     = $('#bkc-messages'),
        $input    = $('#bkc-input'),
        $send     = $('#bkc-send'),
        pendingImages = [],
        botAvatar = <?php echo wp_json_encode($char_avatar ?: ''); ?>;

    /* ── Init ── */
    loadHistory();

    /* ── Events ── */
    $('#bkc-attach').on('click', function() { $('#bkc-file-input').click(); });
    $('#bkc-file-input').on('change', function(e) { handleImages(e.target.files); $(this).val(''); });
    $send.on('click', sendMsg);
    $input.on('keydown', function(e) { if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();sendMsg();} });
    $input.on('input', function() {
        this.style.height='auto';
        this.style.height=Math.min(this.scrollHeight,150)+'px';
        updateBtn();
    });
    $('#bkc-clear-chat').on('click', clearChat);

    /* ── Load history from DB ── */
    function loadHistory() {
        $.post(V.ajaxurl, {
            action: 'bizcity_chat_history',
            platform_type: 'ADMINCHAT',
            session_id: V.session_id,
            character_id: charId,
            nonce: V.nonce
        }, function(res) {
            if (res.success && Array.isArray(res.data) && res.data.length) {
                // Remove greeting if we have history
                $msgs.find('.bkc-greeting').remove();
                res.data.forEach(function(m) {
                    var imgs = m.images || [];
                    appendMsg(m.msg, m.from === 'user' ? 'user' : 'bot', m.time, false, imgs);
                });
                scrollBottom();
            }
        });
    }

    /* ── Send message ── */
    function sendMsg() {
        var text = $input.val().trim();
        if (!text && !pendingImages.length) return;

        $input.val('').css('height','auto');
        $send.prop('disabled',true);

        appendMsg(esc(text || '📷 Hình ảnh'), 'user', null, true, pendingImages.map(function(i){return i.data;}));

        // Show typing
        var typId = 'typ-'+Math.random().toString(36).substr(2,6);
        $msgs.append(
            '<div class="bkc-typing-wrap" id="'+typId+'">'+
            '  <div class="bkc-msg-av" style="background:rgba(139,92,246,0.2);color:#a78bfa;">'+avHtml('bot')+'</div>'+
            '  <div class="bkc-typing-dots"><span class="bkc-typing-dot"></span><span class="bkc-typing-dot"></span><span class="bkc-typing-dot"></span></div>'+
            '</div>'
        );
        scrollBottom();

        var postData = {
            action: 'bizcity_chat_send',
            platform_type: 'ADMINCHAT',
            message: text,
            character_id: charId,
            nonce: V.nonce
        };
        if (pendingImages.length) {
            postData.images = JSON.stringify(pendingImages.map(function(i){return i.data;}));
        }
        clearImages();

        $.ajax({
            url: V.ajaxurl,
            type: 'POST',
            data: postData,
            success: function(res) {
                $('#'+typId).remove();
                if (res.success && res.data && res.data.message) {
                    appendMsg(res.data.message, 'bot', null, true);
                    console.log('🤖', res.data.provider, res.data.model, res.data.usage);
                } else {
                    appendMsg('❌ '+(res.data && res.data.message ? res.data.message : 'Lỗi'), 'bot', null, true);
                }
            },
            error: function() {
                $('#'+typId).remove();
                appendMsg('❌ Không thể kết nối server.', 'bot', null, true);
            }
        });
    }

    /* ── Append message ── */
    function appendMsg(text, from, time, scroll, imgs) {
        var t = time ? new Date(time).toLocaleTimeString('vi-VN',{hour:'2-digit',minute:'2-digit'})
                     : new Date().toLocaleTimeString('vi-VN',{hour:'2-digit',minute:'2-digit'});

        var imgHtml = '';
        if (imgs && imgs.length) {
            imgHtml = '<div class="bkc-msg-images">';
            imgs.forEach(function(u){ imgHtml += '<img src="'+u+'" alt="">'; });
            imgHtml += '</div>';
        }

        var formatted = from === 'bot' ? formatMsg(text) : esc(text);

        $msgs.append(
            '<div class="bkc-msg '+from+'">'+
            '  <div class="bkc-msg-av">'+avHtml(from)+'</div>'+
            '  <div>'+imgHtml+'<div class="bkc-msg-bubble">'+formatted+'</div><div class="bkc-msg-time">'+t+'</div></div>'+
            '</div>'
        );
        if (scroll) scrollBottom();
    }

    function avHtml(from) {
        if (from==='user') return '👤';
        if (botAvatar) return '<img src="'+esc(botAvatar)+'" alt="">';
        return '🤖';
    }

    /* ── Images ── */
    function handleImages(files) {
        if (!files) return;
        Array.from(files).forEach(function(f) {
            if (!f.type.startsWith('image/') || pendingImages.length>=5) return;
            var reader = new FileReader();
            reader.onload = function(e) {
                pendingImages.push({data: e.target.result, name: f.name});
                renderPreviews();
                updateBtn();
            };
            reader.readAsDataURL(f);
        });
    }
    function renderPreviews() {
        var $p = $('#bkc-img-preview').empty();
        if (!pendingImages.length) { $p.hide(); $('#bkc-vision-hint').hide(); return; }
        $p.show(); $('#bkc-vision-hint').show();
        pendingImages.forEach(function(img,i) {
            $p.append(
                '<div class="bkc-img-thumb">'+
                '  <img src="'+img.data+'" alt="">'+
                '  <button class="bkc-img-rm" data-idx="'+i+'">✕</button>'+
                '</div>'
            );
        });
        $p.find('.bkc-img-rm').on('click', function() {
            pendingImages.splice($(this).data('idx'),1);
            renderPreviews(); updateBtn();
        });
    }
    function clearImages() { pendingImages=[]; renderPreviews(); }

    /* ── Clear chat ── */
    function clearChat() {
        if (!confirm('Xóa toàn bộ cuộc hội thoại?')) return;
        $.post(V.ajaxurl, {
            action: 'bizcity_chat_clear',
            platform_type: 'ADMINCHAT',
            session_id: V.session_id,
            nonce: V.nonce
        }, function() {
            $msgs.html(
                '<div class="bkc-msg bot bkc-greeting">'+
                '  <div class="bkc-msg-av">'+avHtml('bot')+'</div>'+
                '  <div><div class="bkc-msg-bubble"><?php echo esc_js($random_greeting); ?></div></div>'+
                '</div>'
            );
        });
    }

    /* ── Helpers ── */
    function updateBtn() { $send.prop('disabled', !$input.val().trim() && !pendingImages.length); }
    function scrollBottom() { $msgs.scrollTop($msgs[0].scrollHeight); }
    function esc(t) { return $('<div>').text(t).html(); }
    function formatMsg(t) {
        t = esc(t);
        t = t.replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
        t = t.replace(/\*(.*?)\*/g,'<em>$1</em>');
        t = t.replace(/`([^`]+)`/g,'<code style="background:rgba(255,255,255,0.08);padding:1px 5px;border-radius:4px;font-size:12px;">$1</code>');
        t = t.replace(/\n/g,'<br>');
        return t;
    }
});
</script>
<?php endif; ?>
