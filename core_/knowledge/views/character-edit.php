<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Character Edit Page Template — Trang chỉnh sửa trợ lý AI
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * @var object|null $character
 * @var bool $is_new
 * @var int $id
 */

defined('ABSPATH') or die('OOPS...');

$db = BizCity_Knowledge_Database::instance();

// Get knowledge sources if editing
$quick_knowledge = [];
$documents = [];
$websites = [];
$all_chunks = [];
if ($id) {
    $sources = $db->get_knowledge_sources($id);
    foreach ($sources as $source) {
        if ($source->source_type === 'quick_faq') {
            // Try JSON decode first, fallback to source_name/content columns
            $content_data = json_decode($source->content, true);
            if (is_array($content_data) && (isset($content_data['title']) || isset($content_data['content']))) {
                $title = $content_data['title'] ?? '';
                $content = $content_data['content'] ?? '';
            } else {
                $title = $source->source_name ?? '';
                $content = $source->content ?? '';
            }
            $quick_knowledge[] = [
                'id' => $source->id,
                'title' => $title,
                'content' => $content
            ];
        } elseif ($source->source_type === 'file') {
            $documents[] = $source;
        } elseif ($source->source_type === 'url') {
            $websites[] = $source;
        }
    }
    
    // Get all chunks for this character
    $all_chunks = $db->get_all_chunks_with_source($id);
}
?>

<div class="wrap bk-character-edit-wrap">
    <!-- Header -->
    <div class="bk-character-header">
        <div class="bk-header-left">
            <div class="bk-character-avatar-mini" id="header-avatar-preview">
                <?php if (!empty($character->avatar)): ?>
                    <img src="<?php echo esc_url($character->avatar); ?>" alt="">
                <?php else: ?>
                    <span class="bk-avatar-placeholder">👤</span>
                <?php endif; ?>
            </div>
            <div class="bk-header-info">
                <h1 id="header-title"><?php echo $is_new ? 'Tạo AI Character Mới' : esc_html($character->name); ?></h1>
                <?php if (!$is_new): ?>
                    <div class="bk-character-meta">
                        <span class="bk-status bk-status-<?php echo esc_attr($character->status ?? 'draft'); ?>">
                            <?php echo esc_html(ucfirst($character->status ?? 'draft')); ?>
                        </span>
                        <span class="bk-created">Created: <?php echo date('M d, Y', strtotime($character->created_at ?? 'now')); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="bk-header-right">
            <?php if (!$is_new): ?>
                <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-chat&character_id=' . $id); ?>" 
                   class="button button-large bk-chat-btn" target="_blank">
                    <span class="dashicons dashicons-format-chat"></span> Chat thử
                </a>
                <button type="button" class="button button-large bk-export-btn" id="export-knowledge-btn">
                    <span class="dashicons dashicons-download"></span> Export Knowledge
                </button>
                <button type="button" class="button button-large bk-import-btn" id="import-knowledge-btn">
                    <span class="dashicons dashicons-upload"></span> Import Knowledge
                </button>
            <?php endif; ?>
            <button type="button" class="button button-large bk-save-btn" id="save-character-btn">
                <span class="dashicons dashicons-saved"></span> Lưu thay đổi
            </button>
            <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters'); ?>" class="button button-large">Quay lại</a>
        </div>
    </div>
    
    <form id="character-form">
        <?php wp_nonce_field('bizcity_knowledge', 'bizcity_knowledge_nonce'); ?>
        <input type="hidden" name="id" value="<?php echo $id; ?>" id="character-id">
        <input type="hidden" name="action" value="bizcity_knowledge_save_character">
        
        <!-- Tabs Navigation -->
        <div class="bk-tabs-nav">
            <button type="button" class="bk-tab-btn active" data-tab="general">
                <span class="dashicons dashicons-admin-generic"></span>
                Tổng quan
            </button>
            <button type="button" class="bk-tab-btn" data-tab="quick-knowledge">
                <span class="dashicons dashicons-editor-table"></span>
                Đào tạo nhanh
                <span class="bk-tab-count"><?php echo count($quick_knowledge); ?></span>
            </button>
            <button type="button" class="bk-tab-btn" data-tab="documents">
                <span class="dashicons dashicons-media-document"></span>
                Tài liệu
                <span class="bk-tab-count"><?php echo count($documents); ?></span>
            </button>
            <button type="button" class="bk-tab-btn" data-tab="websites">
                <span class="dashicons dashicons-admin-site"></span>
                Websites
                <span class="bk-tab-count"><?php echo count($websites); ?></span>
            </button>
            <button type="button" class="bk-tab-btn" data-tab="faqs">
                <span class="dashicons dashicons-editor-help"></span>
                FAQs
            </button>
            <button type="button" class="bk-tab-btn" data-tab="legacy-faq">
                <span class="dashicons dashicons-database-import"></span>
                Legacy FAQ Posts
            </button>
            <button type="button" class="bk-tab-btn" data-tab="model">
                <span class="dashicons dashicons-admin-settings"></span>
                Choose Model
            </button>
        </div>
        
        <!-- Tab: General -->
        <div class="bk-tab-content active" id="tab-general">
            <div class="bk-tab-inner">
                <h2>Thông tin cơ bản</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="character-name">Tên Character *</label></th>
                        <td>
                            <input type="text" name="name" id="character-name" class="regular-text" required
                                value="<?php echo esc_attr($character->name ?? ''); ?>"
                                placeholder="VD: Trợ lý Bán hàng">
                        </td>
                    </tr>
                    <tr style="display:none">
                        <th><label for="character-slug">Slug</label></th>
                        <td>
                            <input type="text" name="slug" id="character-slug" class="regular-text"
                                value="<?php echo esc_attr($character->slug ?? ''); ?>"
                                placeholder="Tự động tạo từ tên">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="intent-tags-input">Intent Tags</label></th>
                        <td>
                            <?php
                            $intent_tags = [];
                            if (!empty($character->intent_tags)) {
                                $decoded = is_string($character->intent_tags) ? json_decode($character->intent_tags, true) : $character->intent_tags;
                                if (is_array($decoded)) $intent_tags = $decoded;
                            }
                            ?>
                            <div class="bk-intent-tags-wrap" id="intent-tags-wrap">
                                <div class="bk-intent-tags-list" id="intent-tags-list">
                                    <?php foreach ($intent_tags as $tag): ?>
                                        <span class="bk-itag">
                                            <?php echo esc_html($tag); ?>
                                            <button type="button" class="bk-itag-remove" data-tag="<?php echo esc_attr($tag); ?>">&times;</button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <input type="text" id="intent-tags-input" class="regular-text"
                                    placeholder="Nhập tag rồi Enter (VD: dinh dưỡng, sức khỏe...)"
                                    autocomplete="off">
                                <input type="hidden" name="intent_tags" id="intent-tags-hidden"
                                    value="<?php echo esc_attr(json_encode($intent_tags, JSON_UNESCAPED_UNICODE)); ?>">
                            </div>
                            <p class="description">Gắn tag intent để hệ thống tự lấy kiến thức character này khi user hỏi liên quan. Nhấn Enter hoặc dấu phẩy để thêm.</p>
                            <style>
                            .bk-intent-tags-wrap{border:1px solid #dcdcde;border-radius:8px;padding:6px 8px;background:#fff;display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-height:38px;cursor:text}
                            .bk-intent-tags-wrap:focus-within{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb}
                            .bk-intent-tags-list{display:flex;flex-wrap:wrap;gap:4px}
                            .bk-itag{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:999px;font-size:13px;font-weight:600}
                            .bk-itag-remove{background:none;border:none;color:#93c5fd;font-size:16px;cursor:pointer;padding:0 2px;line-height:1}
                            .bk-itag-remove:hover{color:#dc2626}
                            #intent-tags-input{border:none!important;box-shadow:none!important;padding:4px!important;flex:1;min-width:120px;font-size:13px}
                            #intent-tags-input:focus{outline:none!important}
                            </style>
                            <script>
                            jQuery(function($){
                                var $wrap=$('#intent-tags-wrap'),$input=$('#intent-tags-input'),$hidden=$('#intent-tags-hidden'),$list=$('#intent-tags-list');
                                function getTags(){return JSON.parse($hidden.val()||'[]');}
                                function setTags(tags){$hidden.val(JSON.stringify(tags));}
                                function addTag(t){
                                    t=t.trim().toLowerCase();
                                    if(!t)return;
                                    var tags=getTags();
                                    if(tags.indexOf(t)!==-1)return;
                                    tags.push(t);
                                    setTags(tags);
                                    $list.append('<span class="bk-itag">'+$('<span>').text(t).html()+' <button type="button" class="bk-itag-remove" data-tag="'+t+'">&times;</button></span>');
                                }
                                function removeTag(t){
                                    var tags=getTags().filter(function(x){return x!==t;});
                                    setTags(tags);
                                    $list.find('.bk-itag-remove[data-tag="'+t+'"]').closest('.bk-itag').remove();
                                }
                                $input.on('keydown',function(e){
                                    if(e.key==='Enter'||e.key===','){e.preventDefault();addTag($input.val());$input.val('');}
                                    if(e.key==='Backspace'&&!$input.val()){var tags=getTags();if(tags.length){removeTag(tags[tags.length-1]);}}
                                });
                                $input.on('blur',function(){if($input.val().trim()){addTag($input.val());$input.val('');}});
                                $list.on('click','.bk-itag-remove',function(){removeTag($(this).data('tag'));});
                                $wrap.on('click',function(){$input.focus();});
                            });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="avatar-url">Avatar</label></th>
                        <td>
                            <div class="bk-avatar-selector">
                                <div class="bk-avatar-preview" id="avatar-preview">
                                    <?php if (!empty($character->avatar)): ?>
                                        <img src="<?php echo esc_url($character->avatar); ?>" alt="">
                                    <?php else: ?>
                                        <span class="bk-avatar-placeholder-large">📷</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bk-avatar-fields">
                                    <input type="text" name="avatar" id="avatar-url" class="regular-text"
                                        value="<?php echo esc_url($character->avatar ?? ''); ?>"
                                        placeholder="URL ảnh đại diện">
                                    <button type="button" class="button" id="select-avatar">Chọn ảnh</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description">Mô tả</label></th>
                        <td>
                            <textarea name="description" id="description" rows="3" class="large-text"
                                placeholder="Mô tả ngắn về character và khả năng"><?php echo esc_textarea($character->description ?? ''); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="system-prompt">System Prompt</label></th>
                        <td>
                            <textarea name="system_prompt" id="system-prompt" rows="8" class="large-text code"
                                placeholder="Prompt hệ thống định nghĩa character. VD: Bạn là trợ lý bán hàng chuyên nghiệp..."><?php echo esc_textarea($character->system_prompt ?? ''); ?></textarea>
                            <p class="description">Prompt này sẽ được gửi cho AI mỗi khi character xử lý tin nhắn.</p>
                            
                            <div class="bk-prompt-templates">
                                <label>📝 Templates chuyên gia:</label>
                                <button type="button" class="button button-small bk-insert-template" data-template="customer-support">Customer Support</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="marketing">Marketing</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="sales">Sales Expert</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="seo">SEO Expert</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="content">Content Writer</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="social-media">Social Media</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="tam-ly">Tâm lý học</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="dinh-duong">Dinh dưỡng</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="kinh-dich">Kinh Dịch</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="tarot">Tarot</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="bai-tay">Bói Bài Tây</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="chiem-tinh">Chiêm Tinh</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="data-analyst">Data Analyst</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="business">Business Consultant</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="personal-coach">Personal Coach</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="recruiter">Recruiter</button>
                                <button type="button" class="button button-small bk-insert-template" data-template="finance">Finance Expert</button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Agent Skills</label></th>
                        <td>
                            <div class="bk-skills-grid">
                                <label class="bk-skill-item">
                                    <input type="checkbox" name="skills[]" value="web_tool" checked>
                                    <span class="bk-skill-icon">🌐</span>
                                    <span class="bk-skill-name">Web Tool</span>
                                </label>
                                <label class="bk-skill-item">
                                    <input type="checkbox" name="skills[]" value="extract_text">
                                    <span class="bk-skill-icon">📋</span>
                                    <span class="bk-skill-name">Extract Text</span>
                                </label>
                                <label class="bk-skill-item">
                                    <input type="checkbox" name="skills[]" value="send_emails">
                                    <span class="bk-skill-icon">📧</span>
                                    <span class="bk-skill-name">Send Emails</span>
                                </label>
                                <label class="bk-skill-item">
                                    <input type="checkbox" name="skills[]" value="image_understanding">
                                    <span class="bk-skill-icon">🖼️</span>
                                    <span class="bk-skill-name">Deep Image Understanding</span>
                                </label>
                                <label class="bk-skill-item">
                                    <input type="checkbox" name="skills[]" value="conversation_starters">
                                    <span class="bk-skill-icon">💬</span>
                                    <span class="bk-skill-name">Conversation Starters</span>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status">Trạng thái</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="draft" <?php selected(($character->status ?? 'draft'), 'draft'); ?>>Draft</option>
                                <option value="active" <?php selected(($character->status ?? ''), 'active'); ?>>Active</option>
                                <option value="published" <?php selected(($character->status ?? ''), 'published'); ?>>Published (on Market)</option>
                                <option value="archived" <?php selected(($character->status ?? ''), 'archived'); ?>>Archived</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Tab: Quick Knowledge -->
        <div class="bk-tab-content" id="tab-quick-knowledge">
            <div class="bk-tab-inner">
                <div class="bk-section-header">
                    <div>
                        <h2>Đào tạo nhanh (Quick Knowledge)</h2>
                        <p class="description">Thêm các cặp câu hỏi-trả lời hoặc kiến thức nhanh cho character. Hỗ trợ import từ CSV/XLSX.</p>
                    </div>
                    <div class="bk-section-actions">
                        <button type="button" class="button" id="import-quick-knowledge">
                            <span class="dashicons dashicons-upload"></span> Import CSV/XLSX
                        </button>
                        <button type="button" class="button" id="export-quick-knowledge">
                            <span class="dashicons dashicons-download"></span> Export
                        </button>
                    </div>
                </div>
                
                <div class="bk-quick-knowledge-table-wrap">
                    <table class="bk-editable-table" id="quick-knowledge-table">
                        <thead>
                            <tr>
                                <th style="width: 5%">#</th>
                                <th style="width: 35%">Title (Tiêu đề/Câu hỏi)</th>
                                <th style="width: 55%">Content (Nội dung/Trả lời)</th>
                                <th style="width: 5%"></th>
                            </tr>
                        </thead>
                        <tbody id="quick-knowledge-tbody">
                            <?php if (empty($quick_knowledge)): ?>
                                <tr class="bk-empty-row">
                                    <td colspan="4" style="text-align:center;padding:40px;color:#666;">
                                        Chưa có dữ liệu. Click "+ New" để thêm mới hoặc Import từ file.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($quick_knowledge as $index => $item): ?>
                                <tr class="bk-editable-row" data-id="<?php echo esc_attr($item['id']); ?>">
                                    <td class="bk-row-number"><?php echo $index + 1; ?></td>
                                    <td class="bk-editable" contenteditable="true" data-field="title">
                                        <?php echo esc_html($item['title']); ?>
                                    </td>
                                    <td class="bk-editable" contenteditable="true" data-field="content">
                                        <?php echo esc_html($item['content']); ?>
                                    </td>
                                    <td>
                                        <button type="button" class="bk-row-delete" title="Xóa">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="bk-table-footer">
                    <button type="button" class="button button-primary" id="add-quick-knowledge-row">
                        <span class="dashicons dashicons-plus-alt2"></span> New
                    </button>
                    <span class="bk-row-count">Total: <strong id="qk-row-count"><?php echo count($quick_knowledge); ?></strong> rows</span>
                </div>
                
                <input type="hidden" name="quick_knowledge_data" id="quick-knowledge-data" value="">
            </div>
        </div>
        
        <!-- Tab: Documents -->
        <div class="bk-tab-content" id="tab-documents">
            <div class="bk-tab-inner">
                <h2>Tài liệu (Documents)</h2>
                <p class="description">Upload file PDF, Word, Excel, CSV để làm nguồn kiến thức cho character.</p>
                
                <div class="bk-upload-area" id="document-upload-area">
                    <div class="bk-upload-icon">📁</div>
                    <p class="bk-upload-text">Kéo thả file vào đây hoặc</p>
                    <button type="button" class="button button-primary" id="browse-documents">Chọn file</button>
                    <p class="bk-upload-formats">Hỗ trợ: PDF, Image, TXT, Word, Excel, CSV</p>
                </div>
                
                <div class="bk-documents-list" id="documents-list">
                    <?php foreach ($documents as $doc): ?>
                    <div class="bk-document-item" data-id="<?php echo esc_attr($doc->id); ?>">
                        <div class="bk-doc-icon">📄</div>
                        <div class="bk-doc-info">
                            <div class="bk-doc-name"><?php echo esc_html($doc->source_name); ?></div>
                            <div class="bk-doc-meta">
                                <span class="bk-doc-status bk-status-<?php echo esc_attr($doc->status); ?>">
                                    <?php echo esc_html(ucfirst($doc->status)); ?>
                                </span>
                                <span class="bk-doc-date"><?php echo date('M d, Y', strtotime($doc->created_at)); ?></span>
                            </div>
                        </div>
                        <button type="button" class="bk-doc-delete" data-id="<?php echo esc_attr($doc->id); ?>">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Chunks List Section -->
                <?php 
                $doc_chunks = array_filter($all_chunks, function($c) { return $c->source_type === 'file'; });
                if (!empty($doc_chunks)): 
                ?>
                <div class="bk-chunks-section">
                    <h3>
                        <span class="dashicons dashicons-database"></span>
                        Chunks đã tạo 
                        <span class="bk-chunks-count">(<?php echo count($doc_chunks); ?> chunks)</span>
                    </h3>
                    <p class="description">Các đoạn văn bản đã được chia nhỏ để embedding và tìm kiếm ngữ nghĩa.</p>
                    
                    <div class="bk-chunks-table-wrap">
                        <table class="bk-chunks-table widefat">
                            <thead>
                                <tr>
                                    <th style="width:50px;">#</th>
                                    <th style="width:200px;">Nguồn</th>
                                    <th>Nội dung</th>
                                    <th style="width:80px;">Tokens</th>
                                    <th style="width:80px;">Vector</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doc_chunks as $chunk): ?>
                                <tr class="bk-chunk-row" data-id="<?php echo esc_attr($chunk->id); ?>">
                                    <td class="bk-chunk-index"><?php echo esc_html($chunk->chunk_index + 1); ?></td>
                                    <td class="bk-chunk-source">
                                        <span class="bk-source-badge" title="<?php echo esc_attr($chunk->source_name); ?>">
                                            <?php echo esc_html(wp_trim_words($chunk->source_name, 4)); ?>
                                        </span>
                                    </td>
                                    <td class="bk-chunk-content" style="text-align:center;">
                                        <span style="display:inline-block; line-height:1;" title="Chunk đại diện">
                                            <span class="dashicons dashicons-database" style="font-size:18px;color:#b36b00;"></span>
                                            <span class="dashicons dashicons-database" style="font-size:18px;color:#b36b00"></span>
                                            <span class="dashicons dashicons-database" style="font-size:18px;color:#b36b00;"></span>
                                        </span>
                                    </td>
                                    <td class="bk-chunk-tokens"><?php echo esc_html($chunk->token_count); ?></td>
                                    <td class="bk-chunk-vector">
                                        <?php if ($chunk->has_embedding): ?>
                                            <span class="bk-vector-yes" title="Đã có embedding">✓</span>
                                        <?php else: ?>
                                            <span class="bk-vector-no" title="Chưa có embedding">✗</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tab: Websites -->
        <div class="bk-tab-content" id="tab-websites">
            <div class="bk-tab-inner">
                <h2>Websites</h2>
                <p class="description">Nạp kiến thức từ website, sitemap hoặc crawl nhiều trang.</p>
                
                <div class="bk-website-input-section">
                    <div class="bk-input-tabs">
                        <button type="button" class="bk-input-tab-btn active" data-mode="single">Single Webpage</button>
                        <button type="button" class="bk-input-tab-btn" data-mode="sublinks">Find Sublinks</button>
                        <button type="button" class="bk-input-tab-btn" data-mode="sitemap">Sitemap</button>
                    </div>
                    
                    <div class="bk-website-input-form">
                        <input type="text" id="website-url" class="large-text" placeholder="https://www.example.com">
                        <button type="button" class="button button-primary" id="add-website">Add link</button>
                    </div>
                </div>
                
                <div class="bk-websites-list" id="websites-list">
                    <?php foreach ($websites as $web): 
                        // Parse metadata to get actual title
                        $metadata = !empty($web->metadata) ? json_decode($web->metadata, true) : [];
                        $display_title = !empty($metadata['title']) ? $metadata['title'] : $web->source_name;
                        if ($display_title === $web->source_url) {
                            $display_title = parse_url($web->source_url, PHP_URL_HOST);
                        }
                    ?>
                    <div class="bk-website-item bk-website-status-<?php echo esc_attr($web->status); ?>" data-id="<?php echo esc_attr($web->id); ?>">
                        <div class="bk-web-icon">🌐</div>
                        <div class="bk-web-info">
                            <div class="bk-web-title">
                                <?php echo esc_html($display_title); ?>
                            </div>
                            <div class="bk-web-url-small">
                                <a href="<?php echo esc_url($web->source_url); ?>" target="_blank" title="<?php echo esc_attr($web->source_url); ?>">
                                    <?php echo esc_html(strlen($web->source_url) > 60 ? substr($web->source_url, 0, 60) . '...' : $web->source_url); ?>
                                </a>
                            </div>
                            <div class="bk-web-meta">
                                <span class="bk-web-status bk-status-<?php echo esc_attr($web->status); ?>">
                                    <?php 
                                    switch($web->status) {
                                        case 'ready':
                                            echo '<span class="dashicons dashicons-yes-alt"></span> Ready';
                                            break;
                                        case 'pending':
                                            echo '<span class="dashicons dashicons-clock"></span> Pending';
                                            break;
                                        case 'processing':
                                            echo '<span class="dashicons dashicons-update"></span> Processing';
                                            break;
                                        case 'error':
                                            echo '<span class="dashicons dashicons-warning"></span> Error';
                                            break;
                                        default:
                                            echo esc_html(ucfirst($web->status));
                                    }
                                    ?>
                                </span>
                                <?php if ($web->chunks_count > 0): ?>
                                    <span class="bk-web-chunks"><span class="dashicons dashicons-database"></span> <?php echo esc_html($web->chunks_count); ?> chunks</span>
                                <?php endif; ?>
                                <span class="bk-web-date"><span class="dashicons dashicons-calendar-alt"></span> <?php echo date('M d, Y', strtotime($web->created_at)); ?></span>
                            </div>
                        </div>
                        <div class="bk-web-actions">
                            <?php if ($web->status === 'pending'): ?>
                                <button type="button" class="button button-small bk-web-process" data-id="<?php echo esc_attr($web->id); ?>" title="Crawl content từ trang này">
                                    <span class="dashicons dashicons-download"></span> Process
                                </button>
                            <?php endif; ?>
                            <button type="button" class="bk-web-delete" data-id="<?php echo esc_attr($web->id); ?>" title="Xóa website này">
                                <span class="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Chunks List Section for Websites -->
                <?php 
                $web_chunks = array_filter($all_chunks, function($c) { return $c->source_type === 'url'; });
                if (!empty($web_chunks)): 
                ?>
                <div class="bk-chunks-section" style="margin-top: 30px;">
                    <h3>
                        <span class="dashicons dashicons-database"></span>
                        Website Content Chunks
                        <span class="bk-chunks-count">(<?php echo count($web_chunks); ?> chunks)</span>
                    </h3>
                    <p class="description">Các đoạn văn bản đã được crawl và chia nhỏ từ websites.</p>
                    
                    <div class="bk-chunks-table-wrap">
                        <table class="bk-chunks-table widefat">
                            <thead>
                                <tr>
                                    <th style="width: 5%">ID</th>
                                    <th style="width: 30%">Source URL</th>
                                    <th style="width: 45%">Content Preview</th>
                                    <th style="width: 10%">Index</th>
                                    <th style="width: 10%">Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($web_chunks as $chunk): ?>
                                <tr class="bk-chunk-row" data-id="<?php echo esc_attr($chunk->id); ?>">
                                    <td><?php echo esc_html($chunk->id); ?></td>
                                    <td>
                                        <?php 
                                        $metadata = json_decode($chunk->metadata, true);
                                        $url = $metadata['url'] ?? $chunk->source_url;
                                        $title = $metadata['title'] ?? 'Untitled';
                                        ?>
                                        <a href="<?php echo esc_url($url); ?>" target="_blank" title="<?php echo esc_attr($url); ?>">
                                            <?php echo esc_html($title); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="bk-chunk-preview">
                                            <?php echo esc_html(mb_substr($chunk->chunk_text, 0, 100)); ?>...
                                        </div>
                                        <div class="bk-chunk-full" style="display:none;">
                                            <?php echo nl2br(esc_html($chunk->chunk_text)); ?>
                                        </div>
                                        <button type="button" class="button button-small bk-expand-chunk">
                                            <span class="dashicons dashicons-visibility"></span> Xem full
                                        </button>
                                    </td>
                                    <td>
                                        <?php 
                                        $chunk_num = ($metadata['chunk_number'] ?? $chunk->chunk_index + 1);
                                        $total = ($metadata['total_chunks'] ?? '?');
                                        echo esc_html($chunk_num . '/' . $total);
                                        ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($chunk->created_at)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tab: FAQs -->
        <div class="bk-tab-content" id="tab-faqs">
            <div class="bk-tab-inner">
                <div class="bk-section-header">
                    <div>
                        <h2>FAQs (Frequently Asked Questions)</h2>
                        <p class="description">Quản lý câu hỏi thường gặp với công cụ editable table. Hỗ trợ import/export CSV.</p>
                    </div>
                    <div class="bk-section-actions">
                        <button type="button" class="button" id="import-faqs">
                            <span class="dashicons dashicons-upload"></span> Import CSV
                        </button>
                        <button type="button" class="button" id="export-faqs">
                            <span class="dashicons dashicons-download"></span> Export
                        </button>
                    </div>
                </div>
                
                <div class="bk-faqs-table-wrap">
                    <table class="bk-editable-table" id="faqs-table">
                        <thead>
                            <tr>
                                <th style="width: 5%">#</th>
                                <th style="width: 5%">
                                    <input type="checkbox" id="faqs-select-all">
                                </th>
                                <th style="width: 35%">Question (Câu hỏi)</th>
                                <th style="width: 50%">Answer (Trả lời)</th>
                                <th style="width: 5%"></th>
                            </tr>
                        </thead>
                        <tbody id="faqs-tbody">
                            <tr class="bk-empty-row">
                                <td colspan="5" style="text-align:center;padding:40px;color:#666;">
                                    Chưa có FAQ. Click "+ New" để thêm mới hoặc Import từ CSV.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="bk-table-footer">
                    <button type="button" class="button button-primary" id="add-faq-row">
                        <span class="dashicons dashicons-plus-alt2"></span> New
                    </button>
                    <button type="button" class="button" id="delete-selected-faqs" disabled>
                        <span class="dashicons dashicons-trash"></span> Delete Selected
                    </button>
                    <span class="bk-row-count">Total: <strong id="faq-row-count">0</strong> rows</span>
                </div>
                
                <input type="hidden" name="faqs_data" id="faqs-data" value="">
            </div>
        </div>
        
        <!-- Tab: Legacy FAQ Posts -->
        <div class="bk-tab-content" id="tab-legacy-faq">
            <div class="bk-tab-inner">
                <h2>📚 Legacy FAQ Posts (Import từ Post Type cũ)</h2>
                <p class="description">
                    Import kiến thức từ post_type <code>quick_faq</code> đã tồn tại trong hệ thống. 
                    Đây là dữ liệu đã được nhập từ trước trong các site multisite.
                </p>
                
                <div class="bk-section-header">
                    <div>
                        <h3>Danh sách Quick FAQ Posts</h3>
                        <p class="description">Chọn các posts để import vào knowledge base của character này.</p>
                    </div>
                    <div class="bk-section-actions">
                        <button type="button" class="button" id="refresh-legacy-faq">
                            <span class="dashicons dashicons-update"></span> Refresh
                        </button>
                        <button type="button" class="button button-primary" id="import-selected-faq" disabled>
                            <span class="dashicons dashicons-download"></span> Import Selected
                        </button>
                    </div>
                </div>
                
                <div class="bk-legacy-faq-list" id="legacy-faq-list">
                    <?php
                    // Query quick_faq posts
                    $faq_args = array(
                        'post_type' => 'quick_faq',
                        'post_status' => 'publish',
                        'posts_per_page' => 50,
                        'orderby' => 'date',
                        'order' => 'DESC'
                    );
                    $faq_posts = get_posts($faq_args);
                    
                    if (!empty($faq_posts)): 
                    ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 40px"><input type="checkbox" id="legacy-faq-select-all"></th>
                                    <th style="width: 50%">Title / Question</th>
                                    <th style="width: 25%">Tags</th>
                                    <th style="width: 15%">Date</th>
                                    <th style="width: 10%">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($faq_posts as $faq_post): 
                                    $tags = get_the_terms($faq_post->ID, 'quick_faq_tag');
                                    $tag_names = $tags ? implode(', ', wp_list_pluck($tags, 'name')) : '';
                                    $action_faq = get_post_meta($faq_post->ID, '_action_faq', true);
                                    $link_faq = get_post_meta($faq_post->ID, '_link_faq', true);
                                    
                                    // Check if already imported
                                    $already_imported = false;
                                    if ($id) {
                                        global $wpdb;
                                        $exists = $wpdb->get_var($wpdb->prepare(
                                            "SELECT COUNT(*) FROM {$wpdb->prefix}bizcity_knowledge_sources 
                                            WHERE character_id = %d AND source_type = 'legacy_faq' AND post_id = %d",
                                            $id,
                                            $faq_post->ID
                                        ));
                                        $already_imported = ($exists > 0);
                                    }
                                ?>
                                <tr class="bk-legacy-faq-row" data-post-id="<?php echo esc_attr($faq_post->ID); ?>">
                                    <td>
                                        <input type="checkbox" class="legacy-faq-checkbox" 
                                               data-post-id="<?php echo esc_attr($faq_post->ID); ?>"
                                               <?php echo $already_imported ? 'disabled checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($faq_post->post_title); ?></strong>
                                        <?php if ($action_faq): ?>
                                            <br><small style="color: #666;">Action: <?php echo esc_html($action_faq); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html($tag_names); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($faq_post->post_date)); ?></td>
                                    <td>
                                        <?php if ($already_imported): ?>
                                            <span style="color: #10b981;">✓ Imported</span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">Not imported</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="bk-empty-state">
                            <p style="text-align: center; padding: 40px; color: #999;">
                                Không tìm thấy quick_faq posts nào. 
                                <a href="<?php echo admin_url('edit.php?post_type=quick_faq'); ?>">Tạo mới</a>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="bk-legacy-faq-stats" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 8px;">
                    <strong>Statistics:</strong>
                    <span id="legacy-faq-selected-count">0</span> selected | 
                    <span id="legacy-faq-total-count"><?php echo count($faq_posts); ?></span> total posts
                </div>
            </div>
        </div>
        
        <!-- Tab: Model -->
        <div class="bk-tab-content" id="tab-model">
            <div class="bk-tab-inner">
                <h2>🤖 AI Model Configuration (Tùy chọn)</h2>
                <p class="description">
                    <strong>Mặc định:</strong> Character sẽ sử dụng <strong>GPT-4o-mini</strong> (OpenAI) nếu không chọn model cụ thể.<br>
                    <strong>Tùy chọn:</strong> Chọn model khác từ OpenRouter (Claude, Gemini, Llama, v.v.) nếu muốn sử dụng AI khác.
                </p>
                
                <?php
                $selected_model = $character->model_id ?? '';
                $creativity_level = $character->creativity_level ?? 0.7;
                $greeting_messages = $character->greeting_messages ?? '[]';
                if (is_string($greeting_messages)) {
                    $greeting_messages = json_decode($greeting_messages, true) ?: [];
                }
                $openrouter_api_key = get_option('bizcity_knowledge_openrouter_api_key', '');
                $openai_key = get_option('twf_openai_api_key', '');
                ?>
                
                <div class="bk-api-status" style="background: #f0f0f1; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;">📊 Trạng thái API</h3>
                    <p style="margin: 5px 0;">
                        <strong>OpenAI API (Default):</strong> 
                        <?php if (!empty($openai_key)): ?>
                            <span style="color: green;">✓ Đã cấu hình</span> - Sử dụng model <code>gpt-4o-mini</code>
                        <?php else: ?>
                            <span style="color: red;">✗ Chưa cấu hình</span>
                        <?php endif; ?>
                    </p>
                    <p style="margin: 5px 0;">
                        <strong>OpenRouter API (Optional):</strong> 
                        <?php if (!empty($openrouter_api_key)): ?>
                            <span style="color: green;">✓ Đã cấu hình</span> - Có thể chọn models khác
                        <?php else: ?>
                            <span style="color: gray;">ℹ Chưa cấu hình</span> - Vào <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-settings'); ?>">Settings</a> để cấu hình
                        <?php endif; ?>
                    </p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th><label for="model-select">Choose Model (Optional)</label></th>
                        <td>
                            <div class="bk-model-selector">
                                <select name="model_id" id="model-select" class="large-text" <?php echo empty($openrouter_api_key) ? 'disabled' : ''; ?>>
                                    <option value="">-- Sử dụng OpenAI GPT-4o-mini (Mặc định) --</option>
                                    <?php if (!empty($selected_model)): ?>
                                        <option value="<?php echo esc_attr($selected_model); ?>" selected><?php echo esc_html($selected_model); ?></option>
                                    <?php endif; ?>
                                </select>
                                <button type="button" class="button" id="refresh-models" <?php echo empty($openrouter_api_key) ? 'disabled' : ''; ?>>
                                    <span class="dashicons dashicons-update"></span> Refresh Models
                                </button>
                            </div>
                            <p class="description">
                                <strong>Để trống</strong> = Sử dụng GPT-4o-mini (OpenAI mặc định)<br>
                                <strong>Chọn model</strong> = Sử dụng OpenRouter với model đã chọn<br>
                                <span id="model-info"></span>
                                <span id="model-cost"></span>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="creativity-level">Creativity Level</label></th>
                        <td>
                            <div class="bk-creativity-slider">
                                <div class="bk-slider-labels">
                                    <span class="bk-label-left">Precise</span>
                                    <span class="bk-label-center">Balanced</span>
                                    <span class="bk-label-right">Creative</span>
                                </div>
                                <input type="range" name="creativity_level" id="creativity-level" 
                                    min="0" max="1" step="0.1" value="<?php echo esc_attr($creativity_level); ?>"
                                    class="bk-slider">
                                <div class="bk-slider-value">
                                    Temperature: <strong id="temperature-value"><?php echo esc_html($creativity_level); ?></strong>
                                </div>
                            </div>
                            <p class="description">Temperature điều chỉnh độ sáng tạo của AI. Thấp = chính xác, Cao = sáng tạo.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="greeting-messages">Greeting Messages</label></th>
                        <td>
                            <div class="bk-greeting-messages">
                                <div id="greeting-messages-list">
                                    <?php if (empty($greeting_messages)): ?>
                                        <div class="bk-greeting-item">
                                            <input type="text" class="regular-text bk-greeting-input" 
                                                placeholder="Hi there! 👋 I'm Ada, your virtual guide!"
                                                value="">
                                            <button type="button" class="button bk-remove-greeting">×</button>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($greeting_messages as $msg): ?>
                                            <div class="bk-greeting-item">
                                                <input type="text" class="regular-text bk-greeting-input" 
                                                    value="<?php echo esc_attr($msg); ?>">
                                                <button type="button" class="button bk-remove-greeting">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="button" id="add-greeting">
                                    <span class="dashicons dashicons-plus-alt2"></span> Add greeting
                                </button>
                                <p class="description">
                                    Character sẽ chọn ngẫu nhiên 1 trong các greeting messages khi bắt đầu hội thoại.
                                    <span id="greeting-count">0/300</span> ký tự
                                </p>
                                <input type="hidden" name="greeting_messages" id="greeting-messages-data" value="">
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </form>
    
    <!-- Hidden file input for imports -->
    <input type="file" id="import-file-input" accept=".csv,.xlsx,.xls" style="display:none;">
</div>
