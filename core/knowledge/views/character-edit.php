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
$iframe_suffix = ! empty( $_GET['bizcity_iframe'] ) ? '&bizcity_iframe=1' : '';

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

    // Sprint 0.18.A.4 — newest first so user sees what they just added on top.
    $sort_desc = static function ( $a, $b ) {
        return strcmp( (string) ( $b->created_at ?? '' ), (string) ( $a->created_at ?? '' ) );
    };
    usort( $documents, $sort_desc );
    usort( $websites, $sort_desc );
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
                <h1 id="header-title"><?php echo $is_new ? 'New Bind Connector' : esc_html($character->name); ?></h1>
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
                <button type="button" class="button button-large bk-export-btn" id="export-knowledge-btn">
                    <span class="dashicons dashicons-download"></span> Export Knowledge
                </button>
                <button type="button" class="button button-large bk-import-btn" id="import-knowledge-btn">
                    <span class="dashicons dashicons-upload"></span> Import Knowledge
                </button>
            <?php endif; ?>
            <button type="button" class="button button-large bk-save-btn" id="save-character-btn">
                <span class="dashicons dashicons-saved"></span> Save Changes
            </button>
            <a href="<?php echo admin_url('admin.php?page=bizcity-knowledge-characters' . $iframe_suffix); ?>" class="button button-large">Back</a>
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
                Overview
            </button>
            <button type="button" class="bk-tab-btn" data-tab="quick-knowledge">
                <span class="dashicons dashicons-editor-table"></span>
                Quick Training
                <span class="bk-tab-count"><?php echo count($quick_knowledge); ?></span>
            </button>
            <?php if ( ! $is_new ): ?>
            <button type="button" class="bk-tab-btn" data-tab="notebooks">
                <span class="dashicons dashicons-book-alt"></span>
                Notebooks
                <span class="bk-tab-count" id="bk-notebooks-count">
                    <?php
                    if ( class_exists( 'BizCity_KG_Database' ) ) {
                        global $wpdb;
                        $tbl = BizCity_KG_Database::instance()->tbl_notebooks();
                        echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE character_id = %d", (int) $id ) );
                    } else { echo 0; }
                    ?>
                </span>
            </button>
            <?php endif; ?>
            <?php if ( ! $is_new ): ?>
            <!-- [2026-06-22 Johnny Chu] GURU-FINISH — removed standalone Messages + Cài đặt AI tabs; added Dashboard tab -->
            <button type="button" class="bk-tab-btn" data-tab="channels">
                <span class="dashicons dashicons-rss"></span>
                Channels
                <span class="bk-tab-count" id="bk-channels-count">0</span>
            </button>
            <button type="button" class="bk-tab-btn" data-tab="dashboard">
                <span class="dashicons dashicons-chart-bar"></span>
                Dashboard
            </button>
            <!-- [2026-06-24 Johnny Chu] GURU-KPI — Automations tab -->
            <button type="button" class="bk-tab-btn" data-tab="automations">
                <span class="dashicons dashicons-randomize"></span>
                Automations
            </button>
            <?php endif; ?>
        </div>
        
        <!-- Tab: General -->
        <div class="bk-tab-content active" id="tab-general">
            <div class="bk-tab-inner">
                <h2>Basic Information</h2>
                
                <table class="form-table">
                    <tr>
                        <th><label for="character-name">Name *</label></th>
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
                    <?php
                    /**
                     * Extension point: extra meta rows in character Overview.
                     * Used by Twin CRM to render Guru Role + Service Template selectors.
                     * Hook receives the $character object.
                     */
                    do_action( 'bizcity_knowledge_character_meta_rows', $character ?? null );
                    ?>
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
                    <?php
                    // ── Wave 0.18.2 — Twin Guru Persona Provider binding (settings.provider_id) ──
                    $current_provider_id = '';
                    $character_settings  = [];
                    if ( ! empty( $character->settings ) ) {
                        $character_settings = is_string( $character->settings )
                            ? (array) json_decode( $character->settings, true )
                            : (array) $character->settings;
                        $current_provider_id = isset( $character_settings['provider_id'] )
                            ? (string) $character_settings['provider_id']
                            : '';
                    }
                    $persona_providers = [];
                    if ( class_exists( 'BizCity_Persona_Registry' ) ) {
                        foreach ( BizCity_Persona_Registry::instance()->all() as $slug => $prov ) {
                            $persona_providers[ $slug ] = method_exists( $prov, 'label' ) ? (string) $prov->label() : (string) $slug;
                        }
                    }
                    ?>
                    <tr>
                        <th><label for="persona-provider-id">🧩 Bind Connector Provider</label></th>
                        <td>
                            <select name="persona_provider_id" id="persona-provider-id" class="regular-text">
                                <option value=""><?php esc_html_e( '— Không gắn provider (pure prompt) —', 'bizcity-twin-ai' ); ?></option>
                                <?php foreach ( $persona_providers as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_provider_id, $slug ); ?>>
                                        <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ( $current_provider_id !== '' && ! isset( $persona_providers[ $current_provider_id ] ) ) : ?>
                                    <option value="<?php echo esc_attr( $current_provider_id ); ?>" selected>
                                        <?php echo esc_html( $current_provider_id . ' — ⚠ provider chưa load' ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Gắn character này với Persona Provider (PHASE-0.18). Khi notebook chọn character → tự lấy smart-source chips, tools, và artifact dialog tương ứng.', 'bizcity-twin-ai' ); ?>
                                <?php if ( empty( $persona_providers ) ) : ?>
                                    <br><strong style="color:#b91c1c;">⚠ <?php esc_html_e( 'Chưa có persona provider nào được register. Cần activate plugin (vd: bizcoach-map).', 'bizcity-twin-ai' ); ?></strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <?php
                    // [2026-06-22 Johnny Chu] GURU-FINISH — moved from Cài đặt AI tab to Overview
                    $creativity_level = $character->creativity_level ?? 0.7;
                    $max_tokens_val   = $character->max_tokens ?? null;
                    ?>
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
                                    min="0" max="1" step="0.1" value="<?php echo esc_attr( $creativity_level ); ?>"
                                    class="bk-slider">
                                <div class="bk-slider-value">
                                    Temperature: <strong id="temperature-value"><?php echo esc_html( $creativity_level ); ?></strong>
                                </div>
                            </div>
                            <p class="description">Temperature điều chỉnh độ sáng tạo của AI. Thấp = chính xác, Cao = sáng tạo.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="max-tokens">Max Tokens (Output)</label></th>
                        <td>
                            <input type="number" name="max_tokens" id="max-tokens"
                                min="0" max="32000" step="1"
                                value="<?php echo esc_attr( $max_tokens_val !== null && $max_tokens_val !== '' ? (int) $max_tokens_val : '' ); ?>"
                                placeholder="Mặc định: 3000"
                                class="small-text">
                            <p class="description">Giới hạn token đầu ra mỗi câu trả lời. <strong>Để trống</strong> = dùng mặc định hệ thống (<code>3000</code>).</p>
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
        
        <!-- Tab: Notebooks (PHASE 0.34.2 — Guru ↔ Notebook attach) -->
        <?php if ( ! $is_new && class_exists( 'BizCity_KG_Database' ) ):
            global $wpdb;
            $kgdb        = BizCity_KG_Database::instance();
            $tbl_nb      = $kgdb->tbl_notebooks();
            // Schema 0.6+: chunks live in bizcity_kg_passages (with embed_status). Fallback for older method names.
            if ( method_exists( $kgdb, 'tbl_source_chunks' ) ) {
                $tbl_chunks = $kgdb->tbl_source_chunks();
            } elseif ( method_exists( $kgdb, 'tbl_passages' ) ) {
                $tbl_chunks = $kgdb->tbl_passages();
            } else {
                $tbl_chunks = $wpdb->prefix . 'bizcity_kg_passages';
            }

            // Per PHASE-0-RULE-VECTOR-FILE-STORE v2.0 (FILESTORE-ONLY): readiness signal đọc trực tiếp
            // từ header file `.bin` (single source of truth, standalone, no DB embedding column).
            // Cột `embedding LONGTEXT` + `embed_status` deprecated, sẽ DROP ở Wave 2 §C-6.
            $attached = $wpdb->get_results( $wpdb->prepare(
                "SELECT n.id, n.uuid, n.name, n.description, n.owner_id, n.updated_at,
                        (SELECT COUNT(*) FROM {$tbl_chunks} c WHERE c.notebook_id = n.id) AS chunks_total
                   FROM {$tbl_nb} n
                  WHERE n.character_id = %d
                  ORDER BY n.updated_at DESC",
                (int) $id
            ), ARRAY_A );

            // Resolve `chunks_ready` từ header `.bin` (truthful, standalone từ filestore).
            if ( ! empty( $attached ) && function_exists( 'bizcity_kg_vector_bin_path' )
                 && class_exists( 'BizCity_KG_Vector_File_Store' ) ) {
                $vfs = BizCity_KG_Vector_File_Store::instance();
                foreach ( $attached as $idx => $row ) {
                    $uuid = strtolower( (string) ( $row['uuid'] ?? '' ) );
                    if ( '' === $uuid ) {
                        $attached[ $idx ]['chunks_ready'] = 0;
                        continue;
                    }
                    $abs = bizcity_kg_vector_bin_path( 'notebooks', $uuid );
                    $hdr = $abs ? $vfs->header_validate( $abs ) : null;
                    $attached[ $idx ]['chunks_ready'] = ( $hdr && ! is_wp_error( $hdr ) )
                        ? (int) $hdr['count']
                        : 0;
                }
            } else {
                foreach ( $attached as $idx => $row ) {
                    $attached[ $idx ]['chunks_ready'] = 0;
                }
            }

            $available = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, owner_id, character_id, updated_at
                   FROM {$tbl_nb}
                  WHERE ( character_id IS NULL OR character_id = 0 OR character_id != %d )
                    AND ( owner_id = %d OR owner_id IS NULL OR %d = 1 )
                  ORDER BY updated_at DESC
                  LIMIT 200",
                (int) $id, (int) get_current_user_id(), (int) ( current_user_can( 'manage_options' ) ? 1 : 0 )
            ), ARRAY_A );

            $nb_nonce = wp_create_nonce( 'bk_char_nb_' . (int) $id );
        ?>
        <div class="bk-tab-content" id="tab-notebooks">
            <div class="bk-tab-inner">
                <h2>Notebooks (Knowledge Graph) <span class="bk-helper-tip">— gắn nhiều notebook làm nguồn kiến thức cho Connector</span></h2>
                <p class="description">
                    Mỗi notebook chứa documents/passages đã được embed vào KG. Khi Connector này được gọi (auto reply, Twin chat),
                    hệ thống sẽ pull ưu tiên từ các notebook đính kèm dưới đây trước khi mở rộng sang KG chung.
                </p>
                <div id="bk-nb-notice" style="display:none;padding:8px 12px;margin-bottom:12px;border-radius:3px;font-weight:500"></div>

                <h3 style="margin-top:24px">Đã gắn (<?php echo count( $attached ); ?>)</h3>
                <?php if ( empty( $attached ) ): ?>
                    <p style="padding:16px;background:#f8fafc;border:1px solid #f1f5f9;color:#64748b">Chưa có notebook nào gắn vào Connector này.</p>
                <?php else: ?>
                <table class="widefat striped" style="margin-bottom:16px">
                    <thead>
                        <tr><th>#</th><th>Tên notebook</th><th>Chunks</th><th>Updated</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $attached as $nb ): ?>
                        <tr id="bk-nb-row-<?php echo (int) $nb['id']; ?>">
                            <td><?php echo (int) $nb['id']; ?></td>
                            <td>
                                <strong><?php echo esc_html( $nb['name'] ?: 'Untitled' ); ?></strong>
                                <?php if ( ! empty( $nb['description'] ) ): ?>
                                    <div style="color:#64748b;font-size:12px"><?php echo esc_html( wp_trim_words( $nb['description'], 20 ) ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span title="ready/total"><?php echo (int) $nb['chunks_ready']; ?>/<?php echo (int) $nb['chunks_total']; ?></span></td>
                            <td><?php echo esc_html( $nb['updated_at'] ); ?></td>
                            <td>
                                <button type="button" class="button button-link-delete bk-nb-detach"
                                    data-nb="<?php echo (int) $nb['id']; ?>"
                                    data-cid="<?php echo (int) $id; ?>"
                                    data-nonce="<?php echo esc_attr( $nb_nonce ); ?>">Gỡ</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <h3 style="margin-top:24px">Gắn thêm notebook</h3>
                <?php if ( empty( $available ) ): ?>
                    <p style="color:#64748b">Không có notebook nào sẵn sàng để gắn.</p>
                <?php else: ?>
                <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap">
                    <select id="bk-nb-select" multiple size="8" style="min-width:360px">
                        <?php foreach ( $available as $nb ): ?>
                            <option value="<?php echo (int) $nb['id']; ?>">
                                #<?php echo (int) $nb['id']; ?> · <?php echo esc_html( $nb['name'] ?: 'Untitled' ); ?>
                                <?php if ( ! empty( $nb['character_id'] ) ) echo ' (đang gắn G' . (int) $nb['character_id'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex;flex-direction:column;gap:8px">
                        <button type="button" class="button button-primary" id="bk-nb-attach-btn"
                            data-cid="<?php echo (int) $id; ?>"
                            data-nonce="<?php echo esc_attr( $nb_nonce ); ?>">+ Attach selected</button>
                        <span class="description" style="color:#64748b;font-size:12px">Giữ Ctrl/Cmd để chọn nhiều.</span>
                    </div>
                </div>
                <?php endif; ?>

                <script>
                (function(){
                    var adminPost = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;

                    function nbNotice(msg, ok){
                        var el = document.getElementById('bk-nb-notice');
                        if(!el) return;
                        el.textContent = msg;
                        el.style.display = 'block';
                        el.style.background = ok ? '#d1fae5' : '#fee2e2';
                        el.style.color      = ok ? '#065f46' : '#991b1b';
                    }

                    function nbPost(body, onOk){
                        var fd = new FormData();
                        for(var k in body) fd.append(k, body[k]);
                        fetch(adminPost, { method:'POST', body:fd, credentials:'same-origin', redirect:'manual' })
                            .then(function(r){ if(r.ok || r.type==='opaqueredirect') onOk(); else nbNotice('Lỗi server: '+r.status, false); })
                            .catch(function(e){ nbNotice('Fetch error: '+e.message, false); });
                    }

                    // Attach
                    var attachBtn = document.getElementById('bk-nb-attach-btn');
                    if(attachBtn){
                        attachBtn.addEventListener('click', function(){
                            var sel = document.getElementById('bk-nb-select');
                            var ids = Array.from(sel.selectedOptions).map(function(o){ return o.value; });
                            if(!ids.length){ nbNotice('Chọn ít nhất 1 notebook.', false); return; }
                            attachBtn.disabled = true;
                            var body = {
                                action: 'bizcity_character_notebook_attach',
                                character_id: attachBtn.dataset.cid,
                                _wpnonce: attachBtn.dataset.nonce
                            };
                            ids.forEach(function(id){ body['notebook_ids[]'] = id; });
                            // For multiple values we need FormData manually
                            var fd = new FormData();
                            fd.append('action', 'bizcity_character_notebook_attach');
                            fd.append('character_id', attachBtn.dataset.cid);
                            fd.append('_wpnonce', attachBtn.dataset.nonce);
                            ids.forEach(function(id){ fd.append('notebook_ids[]', id); });
                            fetch(adminPost, { method:'POST', body:fd, credentials:'same-origin', redirect:'manual' })
                                .then(function(){ nbNotice('Đã gắn! Đang tải lại…', true); setTimeout(function(){ location.reload(); }, 800); })
                                .catch(function(e){ nbNotice('Fetch error: '+e.message, false); attachBtn.disabled = false; });
                        });
                    }

                    // Detach
                    document.querySelectorAll('.bk-nb-detach').forEach(function(btn){
                        btn.addEventListener('click', function(){
                            if(!confirm('Gỡ notebook này khỏi Connector?')) return;
                            btn.disabled = true;
                            var fd = new FormData();
                            fd.append('action', 'bizcity_character_notebook_detach');
                            fd.append('character_id', btn.dataset.cid);
                            fd.append('notebook_id',  btn.dataset.nb);
                            fd.append('_wpnonce',     btn.dataset.nonce);
                            fetch(adminPost, { method:'POST', body:fd, credentials:'same-origin', redirect:'manual' })
                                .then(function(){
                                    var row = document.getElementById('bk-nb-row-'+btn.dataset.nb);
                                    if(row) row.remove();
                                    nbNotice('Đã gỡ notebook.', true);
                                })
                                .catch(function(e){ nbNotice('Fetch error: '+e.message, false); btn.disabled = false; });
                        });
                    });
                })();
                </script>
            </div>
        </div>
        <?php endif; ?>

        <!-- [2026-06-22 Johnny Chu] GURU-FINISH — Messages tab removed; content moved into Channels tab below -->

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

                <!-- Sprint 0.18.A.4 — Upload progress dialog -->
                <div class="bk-progress-panel" id="upload-progress-panel" style="display:none;">
                    <div class="bk-progress-header">
                        <span class="dashicons dashicons-update bk-spin"></span>
                        <strong id="upload-progress-title">Đang upload &amp; embed…</strong>
                        <span class="bk-progress-counter" id="upload-progress-counter"></span>
                    </div>
                    <div class="bk-progress-bar"><div class="bk-progress-bar-fill" id="upload-progress-fill"></div></div>
                    <ul class="bk-progress-log" id="upload-progress-log"></ul>
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

                <!-- Sprint 0.18.A.4 — AJAX console for website crawl/embed -->
                <div class="bk-console" id="website-console">
                    <div class="bk-console-header">
                        <span class="dashicons dashicons-editor-code"></span>
                        <strong>Console — crawl &amp; embed</strong>
                        <button type="button" class="button-link bk-console-clear" id="website-console-clear">clear</button>
                    </div>
                    <div class="bk-console-body" id="website-console-body">
                        <div class="bk-console-line bk-console-info">[ready] Nhập URL ở trên rồi bấm <em>Add link</em>. Mọi request AJAX sẽ log tại đây.</div>
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
        
        <!-- Tab: FAQs (REMOVED Sprint 0.18.A.4) -->
        <?php if ( false ): ?>
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
        <?php endif; // end removed FAQs/Legacy tabs ?>
        
        <!-- [2026-06-22 Johnny Chu] GURU-FINISH — Tab Model (Cài đặt AI) removed; creativity_level + max_tokens moved to Overview tab -->

        <!-- [2026-06-22 Johnny Chu] GURU-FINISH — Tab Skills removed from nav and div -->

        <?php if ( ! $is_new ): ?>
        <!-- [2026-06-03 Johnny Chu] GURU-UI W0.2 — Channels tab (R-GCB SoT, reuse bizcity-channel/v1/inspector/*) -->
        <div class="bk-tab-content" id="tab-channels">
            <div class="bk-tab-inner">
                <h2>📡 Channels bound to this Connector</h2>
                <p class="description">
                    Mỗi binding map <strong>(platform, account_id)</strong> → Connector này. Khi message inbound đến từ
                    OA/Page/Bot/WebChat đó, Universal Channel Listener sẽ resolve về Connector này và pipe
                    <code>character_id</code> vào pipeline LLM. R-GCB SoT: bảng <code>bizcity_channel_bindings</code>.
                </p>

                <div class="bk-channels-toolbar" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button type="button" class="button button-secondary" id="bk-channels-refresh">
                        <span class="dashicons dashicons-update"></span> Refresh
                    </button>
                    <button type="button" class="button button-primary" id="bk-channels-bind-open">
                        <span class="dashicons dashicons-plus-alt2"></span> Bind kênh mới
                    </button>
                </div>

                <div id="bk-channels-list" class="bk-channels-list" data-character-id="<?php echo (int) $id; ?>">
                    <p class="description"><em>Đang tải bindings…</em></p>
                </div>

                <!-- Bind dialog (hidden) -->
                <div id="bk-channels-dialog" class="bk-channels-dialog" style="display:none">
                    <div class="bk-channels-dialog-bg"></div>
                    <div class="bk-channels-dialog-box">
                        <h3>Bind kênh cho Connector này</h3>
                        <p>
                            <label><strong>Platform</strong></label><br>
                            <select id="bk-bind-platform" style="min-width:240px"></select>
                        </p>
                        <!-- [2026-06-22 Johnny Chu] GURU-FINISH W1.2 — account picker: auto-load accounts for platform -->
                        <p id="bk-bind-account-wrap">
                            <label><strong>Kênh / Account</strong></label><br>
                            <!-- Dropdown auto-populated when platform is chosen (via /platform-accounts REST) -->
                            <select id="bk-bind-account-select" style="width:100%;display:none" aria-label="Chọn fanpage/OA/bot">
                                <option value="">— chọn tài khoản —</option>
                            </select>
                            <!-- Fallback manual input shown when no accounts found for chosen platform -->
                            <input type="text" id="bk-bind-account-id" style="width:100%" placeholder="vd: 123456789 hoặc * để wildcard">
                            <span id="bk-bind-account-loading" style="display:none;color:#646970;font-size:12px;margin-top:4px">⏳ Đang tải danh sách tài khoản…</span>
                            <span id="bk-bind-account-hint" style="display:none;color:#646970;font-size:12px;margin-top:4px">
                                💡 WEBCHAT: để trống = áp dụng cho <strong>tất cả khách guest</strong> (wildcard <code>*</code>). Nhập ID cụ thể nếu muốn giới hạn một session key nhất định.
                            </span>
                            <span id="bk-bind-account-manual-hint" style="display:none;color:#646970;font-size:12px;margin-top:4px">
                                ℹ Chưa tìm thấy tài khoản cấu hình sẵn cho platform này. Nhập Account ID thủ công.
                                <a href="<?php echo esc_url( admin_url('admin.php?page=bizcity-channel-gateway') ); ?>" target="_blank">Cấu hình kênh →</a>
                            </span>
                        </p>
                        <p>
                            <label><strong>Mode</strong></label><br>
                            <select id="bk-bind-mode">
                                <option value="auto" selected>auto (LLM trả tự động)</option>
                                <option value="manual">manual (chỉ log inbound, chờ human)</option>
                                <option value="hybrid">hybrid (LLM gợi ý → human duyệt)</option>
                                <option value="roundrobin">roundrobin (pool responder xoay vòng)</option>
                            </select>
                        </p>
                        <p>
                            <label>
                                <input type="checkbox" id="bk-bind-autoreply" checked>
                                <strong>Auto-reply</strong> (cho phép Listener trả lời tự động)
                            </label>
                        </p>
                        <p>
                            <label><strong>Fallback assignee (WP user ID, optional)</strong></label><br>
                            <input type="number" id="bk-bind-fallback" min="0" placeholder="0 = không có">
                        </p>
                        <p style="text-align:right;margin-top:18px">
                            <button type="button" class="button" id="bk-bind-cancel">Hủy</button>
                            <button type="button" class="button button-primary" id="bk-bind-save">Bind</button>
                        </p>
                    </div>
                </div>

                <!-- [2026-06-22 Johnny Chu] GURU-FINISH — Messages section embedded in Channels tab -->
                <div id="bk-channels-messages-section" style="margin-top:28px">
                    <h3 style="border-top:1px solid #dcdcde;padding-top:16px;margin-top:0">
                        💬 Tin nhắn đã xử lý
                    </h3>
                    <div style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <label for="bk-msg-platform-filter" style="font-size:13px">Lọc kênh:</label>
                        <select id="bk-msg-platform-filter" style="min-width:140px">
                            <option value="">Tất cả</option>
                        </select>
                        <button type="button" class="button button-small" id="bk-msg-reload">
                            <span class="dashicons dashicons-update" style="vertical-align:middle"></span>
                        </button>
                    </div>
                    <?php
                    // [2026-06-22 Johnny Chu] GURU-FINISH — messages embedded in channels tab
                    global $wpdb;
                    $tbl_msg  = $wpdb->prefix . 'bizcity_channel_messages';
                    $rows_msg = $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, platform, chat_id, body, status, responder_kind, created_at
                           FROM {$tbl_msg}
                          WHERE character_id = %d
                          ORDER BY id DESC
                          LIMIT 300",
                        (int) $id
                    ), ARRAY_A );
                    ?>
                    <?php if ( empty( $rows_msg ) ): ?>
                        <p style="padding:14px;background:#f8fafc;border:1px solid #f1f5f9;color:#64748b;border-radius:6px">
                            Connector này chưa có tin nhắn nào. Đảm bảo binding mode = AUTO và đã bind đúng character.
                        </p>
                    <?php else: ?>
                    <div id="bk-msg-table-wrap">
                        <table class="widefat striped" id="bk-msg-table">
                            <thead>
                                <tr><th>Time</th><th>Platform</th><th>Chat ID</th><th>Nội dung</th><th>Kind</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $rows_msg as $r ):
                                $kind = $r['responder_kind'] ? $r['responder_kind'] : 'auto';
                                $bg   = $kind === 'manual' ? '#fee2e2' : ( $kind === 'hybrid' ? '#fef3c7' : '#d1fae5' );
                                $fg   = $kind === 'manual' ? '#991b1b' : ( $kind === 'hybrid' ? '#92400e' : '#065f46' );
                            ?>
                                <tr data-platform="<?php echo esc_attr( $r['platform'] ); ?>">
                                    <td style="font-size:11px;font-family:monospace;white-space:nowrap"><?php echo esc_html( $r['created_at'] ); ?></td>
                                    <td><span style="font-family:monospace;font-size:11px"><?php echo esc_html( $r['platform'] ); ?></span></td>
                                    <td style="font-size:11px;font-family:monospace;max-width:150px;overflow:hidden;text-overflow:ellipsis"><?php echo esc_html( $r['chat_id'] ); ?></td>
                                    <td><?php echo esc_html( wp_trim_words( (string) $r['body'], 20 ) ); ?></td>
                                    <td><span style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;padding:2px 5px;font-size:10px;font-weight:600;text-transform:uppercase"><?php echo esc_html( $kind ); ?></span></td>
                                    <td><?php echo $r['status'] === 'sent' ? '<span style="color:#059669">✓</span>' : ( $r['status'] === 'failed' ? '<span style="color:#dc2626">✗</span>' : esc_html( $r['status'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <script>
                    jQuery(function($){
                        var platforms = {};
                        $('#bk-msg-table tbody tr').each(function(){
                            var p = $(this).data('platform');
                            if(p) platforms[p] = true;
                        });
                        var $filter = $('#bk-msg-platform-filter');
                        $.each(platforms, function(p){ $filter.append('<option value="'+p+'">'+p+'</option>'); });
                        $filter.on('change', function(){
                            var v = $(this).val();
                            $('#bk-msg-table tbody tr').each(function(){
                                $(this).toggle(!v || $(this).data('platform') === v);
                            });
                        });
                        $('#bk-msg-reload').on('click', function(){ location.reload(); });
                    });
                    </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- /Tab: Channels -->

        <!-- [2026-06-22 Johnny Chu] GURU-FINISH — New Dashboard stats tab -->
        <div class="bk-tab-content" id="tab-dashboard">
            <div class="bk-tab-inner">
                <h2>📊 Dashboard — Thống kê tin nhắn</h2>
                <p class="description">Tổng quan về tin nhắn mà Connector này đã xử lý.</p>
                <?php
                // [2026-06-22 Johnny Chu] GURU-FINISH — dashboard stats queries
                global $wpdb;
                $tbl_msg   = $wpdb->prefix . 'bizcity_channel_messages';
                $char_id   = (int) $id;

                $total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_msg} WHERE character_id = %d", $char_id ) );
                $total_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_msg} WHERE character_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $char_id ) );
                $total_30d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_msg} WHERE character_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", $char_id ) );
                $sent_count= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_msg} WHERE character_id = %d AND status='sent'", $char_id ) );
                $success_rate = $total > 0 ? round( $sent_count / $total * 100 ) : 0;
                $last_msg  = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$tbl_msg} WHERE character_id = %d ORDER BY id DESC LIMIT 1", $char_id ) );

                $by_platform = $wpdb->get_results( $wpdb->prepare(
                    "SELECT platform, COUNT(*) AS cnt FROM {$tbl_msg} WHERE character_id = %d GROUP BY platform ORDER BY cnt DESC",
                    $char_id
                ), ARRAY_A );

                $by_kind = $wpdb->get_results( $wpdb->prepare(
                    "SELECT IFNULL(responder_kind,'auto') AS kind, COUNT(*) AS cnt FROM {$tbl_msg} WHERE character_id = %d GROUP BY kind ORDER BY cnt DESC",
                    $char_id
                ), ARRAY_A );

                $daily_trend = $wpdb->get_results( $wpdb->prepare(
                    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM {$tbl_msg} WHERE character_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY day ORDER BY day ASC",
                    $char_id
                ), ARRAY_A );
                ?>

                <!-- Stat cards -->
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin:16px 0 24px">
                    <?php
                    $cards = array(
                        array( 'label' => 'Tổng tin nhắn', 'value' => number_format( $total ), 'color' => '#1d4ed8' ),
                        array( 'label' => '7 ngày gần đây', 'value' => number_format( $total_7d ), 'color' => '#0369a1' ),
                        array( 'label' => '30 ngày gần đây', 'value' => number_format( $total_30d ), 'color' => '#0284c7' ),
                        array( 'label' => 'Tỷ lệ gửi thành công', 'value' => $success_rate . '%', 'color' => $success_rate >= 90 ? '#059669' : ( $success_rate >= 70 ? '#d97706' : '#dc2626' ) ),
                    );
                    foreach ( $cards as $card ):
                    ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;min-width:150px;flex:1">
                        <div style="font-size:11px;color:#646970;text-transform:uppercase;letter-spacing:.5px"><?php echo esc_html( $card['label'] ); ?></div>
                        <div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $card['color'] ); ?>;margin-top:4px"><?php echo esc_html( $card['value'] ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $last_msg ): ?>
                <p style="color:#646970;font-size:12px;margin-bottom:20px">
                    Tin nhắn gần nhất: <strong><?php echo esc_html( $last_msg ); ?></strong>
                </p>
                <?php endif; ?>

                <!-- By platform -->
                <?php if ( ! empty( $by_platform ) ): ?>
                <h3>Theo kênh (platform)</h3>
                <table class="widefat" style="max-width:480px;margin-bottom:24px">
                    <thead><tr><th>Platform</th><th>Số tin nhắn</th><th>Tỷ lệ</th></tr></thead>
                    <tbody>
                    <?php foreach ( $by_platform as $row ): ?>
                        <tr>
                            <td><code><?php echo esc_html( $row['platform'] ); ?></code></td>
                            <td><?php echo number_format( (int) $row['cnt'] ); ?></td>
                            <td><?php echo $total > 0 ? round( $row['cnt'] / $total * 100 ) . '%' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- By responder kind -->
                <?php if ( ! empty( $by_kind ) ): ?>
                <h3>Theo loại phản hồi</h3>
                <table class="widefat" style="max-width:480px;margin-bottom:24px">
                    <thead><tr><th>Kind</th><th>Số tin nhắn</th><th>Tỷ lệ</th></tr></thead>
                    <tbody>
                    <?php foreach ( $by_kind as $row ):
                        $k  = $row['kind'];
                        $bg = $k === 'manual' ? '#fee2e2' : ( $k === 'hybrid' ? '#fef3c7' : '#d1fae5' );
                        $fg = $k === 'manual' ? '#991b1b' : ( $k === 'hybrid' ? '#92400e' : '#065f46' );
                    ?>
                        <tr>
                            <td><span style="background:<?php echo esc_attr($bg); ?>;color:<?php echo esc_attr($fg); ?>;padding:2px 8px;font-size:11px;font-weight:600;text-transform:uppercase;border-radius:3px"><?php echo esc_html( $k ); ?></span></td>
                            <td><?php echo number_format( (int) $row['cnt'] ); ?></td>
                            <td><?php echo $total > 0 ? round( $row['cnt'] / $total * 100 ) . '%' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- 14-day trend -->
                <?php if ( ! empty( $daily_trend ) ): ?>
                <h3>Xu hướng 14 ngày gần đây</h3>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;max-width:600px">
                    <?php
                    $max_day = max( array_column( $daily_trend, 'cnt' ) );
                    $max_day = max( $max_day, 1 );
                    foreach ( $daily_trend as $day ):
                        $pct = round( $day['cnt'] / $max_day * 100 );
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                        <span style="font-size:11px;font-family:monospace;width:80px;flex-shrink:0"><?php echo esc_html( $day['day'] ); ?></span>
                        <div style="flex:1;background:#f0f0f1;border-radius:4px;height:16px;overflow:hidden">
                            <div style="width:<?php echo $pct; ?>%;background:#2563eb;height:100%;border-radius:4px"></div>
                        </div>
                        <span style="font-size:11px;width:32px;text-align:right"><?php echo (int) $day['cnt']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ( $total === 0 ): ?>
                <p style="padding:20px;background:#f8fafc;border:1px solid #f1f5f9;color:#64748b;border-radius:8px;text-align:center">
                    Connector này chưa có dữ liệu tin nhắn. Bind kênh và bật mode AUTO để bắt đầu thu thập thống kê.
                </p>
                <?php endif; ?>
            </div>
        </div>
        <!-- /Tab: Dashboard -->

        <!-- Tab: Automations -->
        <!-- [2026-06-24 Johnny Chu] GURU-KPI — show automation workflows bound to this Guru via trigger_config.guru_id -->
        <div class="bk-tab-content" id="tab-automations">
            <div class="bk-tab-inner">
                <h2 style="display:flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-randomize" style="font-size:22px;color:#6366f1;"></span>
                    Automation Workflows gắn với Guru này
                </h2>
                <p style="color:#6b7280;margin-bottom:20px;">
                    Danh sách các Automation Workflow có trigger filter <code>Guru ID = <?php echo esc_html( $character->id ?? 0 ); ?></code>.
                    Để gắn workflow mới, vào <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-automation' ) ); ?>">Automation Builder</a> và đặt <em>Guru ID</em> trong trigger config.
                </p>
                <?php
                // [2026-06-24 Johnny Chu] GURU-KPI — query workflows bound to this guru
                $guru_id_val = (int) ( $character->id ?? 0 );
                $bound_workflows = array();
                if ( $guru_id_val > 0 ) {
                    global $wpdb;
                    $tbl_wf2  = $wpdb->prefix . 'bizcity_automation_workflows';
                    $tbl_run2 = $wpdb->prefix . 'bizcity_automation_runs';

                    $wf2_exists = (bool) $wpdb->get_var(
                        $wpdb->prepare(
                            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                            $tbl_wf2
                        )
                    );

                    if ( $wf2_exists ) {
                        $bound_workflows = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT id, name, enabled, trigger_type,
                                        (SELECT COUNT(*) FROM {$tbl_run2} r WHERE r.workflow_id = w.id) AS run_total,
                                        (SELECT COUNT(*) FROM {$tbl_run2} r WHERE r.workflow_id = w.id AND r.status = 2) AS run_ok,
                                        (SELECT COUNT(*) FROM {$tbl_run2} r WHERE r.workflow_id = w.id AND r.status = 3) AS run_fail
                                 FROM {$tbl_wf2} w
                                 WHERE CAST( JSON_UNQUOTE( JSON_EXTRACT(trigger_config, '$.guru_id') ) AS UNSIGNED ) = %d
                                 ORDER BY id DESC",
                                $guru_id_val
                            ),
                            ARRAY_A
                        );
                    }
                }
                ?>
                <?php if ( empty( $bound_workflows ) ) : ?>
                <div style="padding:32px 20px;text-align:center;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;color:#94a3b8;">
                    <div style="font-size:36px;margin-bottom:8px;">⚙️</div>
                    <p style="margin:0;">Chưa có workflow nào được gắn với Guru này.<br>
                    Mở <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-automation' ) ); ?>">Automation Builder</a>, tạo workflow và đặt <strong>Guru ID = <?php echo esc_html( $character->id ?? 0 ); ?></strong> trong trigger.</p>
                </div>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:40px;">ID</th>
                            <th>Tên Workflow</th>
                            <th style="width:120px;">Trigger</th>
                            <th style="width:80px;text-align:center;">Trạng thái</th>
                            <th style="width:80px;text-align:center;">Tổng runs</th>
                            <th style="width:120px;text-align:center;">OK / Fail</th>
                            <th style="width:80px;">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $bound_workflows as $wf ) :
                            $run_total_wf = (int) $wf['run_total'];
                            $run_ok_wf    = (int) $wf['run_ok'];
                            $run_fail_wf  = (int) $wf['run_fail'];
                            $enabled      = ! empty( $wf['enabled'] );
                        ?>
                        <tr>
                            <td><?php echo (int) $wf['id']; ?></td>
                            <td><strong><?php echo esc_html( $wf['name'] ); ?></strong></td>
                            <td><code style="font-size:11px;"><?php echo esc_html( $wf['trigger_type'] ); ?></code></td>
                            <td style="text-align:center;">
                                <?php if ( $enabled ) : ?>
                                <span style="color:#10b981;font-weight:600;">✓ ON</span>
                                <?php else : ?>
                                <span style="color:#9ca3af;">— OFF</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;"><?php echo esc_html( number_format( $run_total_wf ) ); ?></td>
                            <td style="text-align:center;">
                                <?php if ( $run_total_wf > 0 ) : ?>
                                <span style="color:#10b981;">✓<?php echo esc_html( $run_ok_wf ); ?></span>
                                &nbsp;/&nbsp;
                                <span style="color:#ef4444;">✗<?php echo esc_html( $run_fail_wf ); ?></span>
                                <?php else : ?>
                                <span style="color:#d1d5db;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-automation&workflow_id=' . (int) $wf['id'] ) ); ?>" class="button button-small" target="_blank">
                                    Mở Builder
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:12px;color:#9ca3af;font-size:12px;">
                    Để thêm workflow mới cho Guru này: mở Automation Builder → chọn trigger → đặt <strong>Guru ID = <?php echo esc_html( $character->id ?? 0 ); ?></strong>.
                </p>
                <?php endif; ?>
            </div>
        </div>
        <!-- /Tab: Automations -->
        <?php endif; ?>
    </form>
    
    <!-- Hidden file input for imports -->
    <input type="file" id="import-file-input" accept=".csv,.xlsx,.xls" style="display:none;">
</div>

<style>
.bk-skills-list{display:flex;flex-direction:column;gap:10px}
.bk-skill-card{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.bk-skill-card .bk-skill-meta{flex:1;min-width:0}
.bk-skill-card h4{margin:0 0 4px 0;font-size:14px}
.bk-skill-card .bk-skill-key{color:#646970;font-size:12px;font-family:Menlo,Monaco,monospace}
.bk-skill-card .bk-skill-tags{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}
.bk-skill-card .bk-skill-tag{background:#f0f0f1;border:1px solid #dcdcde;border-radius:999px;font-size:11px;padding:1px 8px;color:#1d2327}
.bk-skill-card .bk-skill-tag.slash{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;font-family:Menlo,Monaco,monospace}
.bk-skill-card .bk-skill-tag.tool{background:#ecfdf5;border-color:#a7f3d0;color:#065f46;font-family:Menlo,Monaco,monospace}
.bk-skill-card .bk-skill-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
.bk-skill-card.is-draft{opacity:.65;border-style:dashed}
.bk-skills-empty{padding:18px;border:1px dashed #c3c4c7;border-radius:8px;text-align:center;color:#646970}
</style>

<script>
jQuery(function($){
    var $list = $('#bk-skills-list');
    if(!$list.length) return;

    var characterId = parseInt($list.attr('data-character-id') || '0', 10);
    if(!characterId){ return; }

    var REST_BASE  = <?php echo wp_json_encode( esc_url_raw( rest_url( 'bizcity/skill/v1' ) ) ); ?>;
    var REST_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;

    function api(method, path, body){
        return $.ajax({
            url: REST_BASE + path,
            method: method,
            contentType: 'application/json',
            data: body ? JSON.stringify(body) : undefined,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', REST_NONCE); }
        });
    }

    function escapeHtml(s){
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function renderSkill(s){
        var slash = (s.slash_commands || []).map(function(c){
            return '<span class="bk-skill-tag slash">/' + escapeHtml(String(c).replace(/^\//,'')) + '</span>';
        }).join('');
        var tools = (s.tools || []).map(function(t){
            return '<span class="bk-skill-tag tool">@' + escapeHtml(t) + '</span>';
        }).join('');
        var modes = (s.modes || []).map(function(m){
            return '<span class="bk-skill-tag">' + escapeHtml(m) + '</span>';
        }).join('');
        var draft = (s.status && s.status !== 'active') ? ' is-draft' : '';
        return ''
          + '<div class="bk-skill-card' + draft + '" data-skill-id="' + (s.id|0) + '">'
          +   '<div class="bk-skill-meta">'
          +     '<h4>' + escapeHtml(s.title || s.skill_key) + '</h4>'
          +     '<div class="bk-skill-key">' + escapeHtml(s.skill_key) + ' · priority ' + (s.priority|0) + ' · ' + escapeHtml(s.status || 'active') + '</div>'
          +     '<div class="bk-skill-tags">' + slash + tools + modes + '</div>'
          +   '</div>'
          +   '<div class="bk-skill-actions">'
          +     '<a href="' + <?php echo wp_json_encode( esc_url_raw( admin_url( 'admin.php?page=bizcity-skills' ) ) ); ?> + '#skill-' + (s.id|0) + '" target="_blank" class="button button-small">Edit</a>'
          +     '<button type="button" class="button button-small bk-skill-detach" data-skill-id="' + (s.id|0) + '">Detach</button>'
          +   '</div>'
          + '</div>';
    }

    function load(){
        $list.html('<p class="description"><em>Đang tải skills…</em></p>');
        api('GET', '/character/' + characterId + '/skills').done(function(res){
            var skills = (res && res.skills) || [];
            $('#bk-skills-count').text(skills.length);
            if(!skills.length){
                $list.html('<div class="bk-skills-empty">Chưa có skill nào gắn với character này.<br>Nhấn <strong>Clone từ Skill Library</strong> để bắt đầu.</div>');
                return;
            }
            $list.html(skills.map(renderSkill).join(''));
        }).fail(function(xhr){
            $list.html('<div class="notice notice-error"><p>Không tải được skills: ' + escapeHtml(xhr.responseText || xhr.statusText) + '</p></div>');
        });
    }

    $('#bk-skills-refresh').on('click', load);

    $('#bk-skills-clone-open').on('click', function(){
        var raw = window.prompt('Nhập Skill ID nguồn (xem ở Skill Library) để clone vào character này:', '');
        if(!raw) return;
        var srcId = parseInt(raw, 10);
        if(!srcId){ alert('Skill ID không hợp lệ'); return; }
        api('POST', '/character/' + characterId + '/skills/clone', { source_skill_id: srcId }).done(function(){
            load();
        }).fail(function(xhr){
            alert('Clone thất bại: ' + (xhr.responseText || xhr.statusText));
        });
    });

    $list.on('click', '.bk-skill-detach', function(){
        var id = parseInt($(this).attr('data-skill-id'), 10);
        if(!id) return;
        if(!confirm('Detach (xoá) skill này khỏi character? Hành động không thể hoàn tác.')) return;
        api('DELETE', '/skill/' + id).done(load).fail(function(xhr){
            alert('Detach thất bại: ' + (xhr.responseText || xhr.statusText));
        });
    });

    // Lazy-load when the Skills tab is first activated
    var loaded = false;
    $(document).on('click', '.bk-tab-btn[data-tab="skills"]', function(){
        if(loaded) return;
        loaded = true;
        load();
    });
});
</script>

<?php if ( ! $is_new ): ?>
<style>
/* [2026-06-03 Johnny Chu] GURU-UI W0.2 — Channels tab styles */
.bk-channels-list{display:flex;flex-direction:column;gap:10px}
.bk-channel-card{border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;background:#fff;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.bk-channel-card.is-disabled{opacity:.55;border-style:dashed}
.bk-channel-card .bk-channel-meta{flex:1;min-width:0}
.bk-channel-card h4{margin:0 0 4px 0;font-size:14px}
.bk-channel-card .bk-channel-account{color:#646970;font-size:12px;font-family:Menlo,Monaco,monospace;word-break:break-all}
.bk-channel-card .bk-channel-tags{margin-top:6px;display:flex;flex-wrap:wrap;gap:4px}
.bk-channel-card .bk-channel-tag{background:#f0f0f1;border:1px solid #dcdcde;border-radius:999px;font-size:11px;padding:1px 8px;color:#1d2327}
.bk-channel-card .bk-channel-tag.mode-auto{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.bk-channel-card .bk-channel-tag.mode-manual{background:#fef3c7;border-color:#fde68a;color:#92400e}
.bk-channel-card .bk-channel-tag.mode-hybrid{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.bk-channel-card .bk-channel-tag.mode-roundrobin{background:#f3e8ff;border-color:#e9d5ff;color:#6b21a8}
.bk-channel-card .bk-channel-actions{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
.bk-channels-empty{padding:18px;border:1px dashed #c3c4c7;border-radius:8px;text-align:center;color:#646970}
.bk-channels-dialog{position:fixed;inset:0;z-index:100050}
.bk-channels-dialog .bk-channels-dialog-bg{position:absolute;inset:0;background:rgba(15,23,42,.55)}
.bk-channels-dialog .bk-channels-dialog-box{position:relative;max-width:480px;margin:80px auto;background:#fff;border-radius:10px;padding:20px 24px;box-shadow:0 20px 50px rgba(0,0,0,.2)}
.bk-channels-dialog h3{margin-top:0}
</style>

<script>
/* [2026-06-03 Johnny Chu] GURU-UI W0.2 — Channels tab JS (reuse bizcity-channel/v1/inspector/*) */
jQuery(function($){
    var $list = $('#bk-channels-list');
    if(!$list.length) return;

    var characterId = parseInt($list.attr('data-character-id') || '0', 10);
    if(!characterId){ return; }

    var REST_BASE  = <?php echo wp_json_encode( esc_url_raw( rest_url( 'bizcity-channel/v1' ) ) ); ?>;
    var REST_NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
    var channelsCatalog = []; // [{key,label,kind}]

    function api(method, path, body){
        return $.ajax({
            url: REST_BASE + path,
            method: method,
            contentType: 'application/json',
            data: body ? JSON.stringify(body) : undefined,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', REST_NONCE); }
        });
    }

    function esc(s){
        return String(s == null ? '' : s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function platformLabel(key){
        for(var i=0;i<channelsCatalog.length;i++){
            // [2026-06-09 Johnny Chu] PHASE-D D-WEBCHAT-WILDCARD — API dùng c.platform, không phải c.key
            var cat = channelsCatalog[i];
            if((cat.platform || cat.key) === key) return cat.label || key;
        }
        return key;
    }

    function renderBinding(b){
        var mode = (b.mode || 'auto').toLowerCase();
        var status = String(b.status) === '0' || b.status === 0 ? 'is-disabled' : '';
        return ''
          + '<div class="bk-channel-card ' + status + '" data-binding-id="' + (b.id|0) + '">'
          +   '<div class="bk-channel-meta">'
          +     '<h4>' + esc(platformLabel(b.platform)) + '</h4>'
          +     '<div class="bk-channel-account">' + esc(b.platform) + ' · account: ' + esc(b.account_id) + '</div>'
          +     '<div class="bk-channel-tags">'
          +       '<span class="bk-channel-tag mode-' + esc(mode) + '">mode: ' + esc(mode) + '</span>'
          +       (b.auto_reply ? '<span class="bk-channel-tag">auto-reply</span>' : '<span class="bk-channel-tag">no auto-reply</span>')
          +       (b.fallback_assignee ? '<span class="bk-channel-tag">fallback #' + (b.fallback_assignee|0) + '</span>' : '')
          +       ((String(b.status)==='0'||b.status===0) ? '<span class="bk-channel-tag">disabled</span>' : '')
          +     '</div>'
          +   '</div>'
          +   '<div class="bk-channel-actions">'
          +     (String(b.status)==='0'||b.status===0
                  ? ''
                  : '<button type="button" class="button button-small bk-channel-disable" data-binding-id="' + (b.id|0) + '">Disable</button>')
          +   '</div>'
          + '</div>';
    }

    function loadChannels(){
        return api('GET', '/inspector/channels').then(function(res){
            channelsCatalog = (res && (res.data || res.channels)) || [];
            // Populate dialog dropdown
            // [2026-06-09 Johnny Chu] PHASE-D D-WEBCHAT-WILDCARD — API trả về c.platform (không phải c.key)
            var $sel = $('#bk-bind-platform');
            if($sel.length){
                $sel.empty();
                channelsCatalog.forEach(function(c){
                    var val = c.platform || c.key || '';
                    $sel.append('<option value="' + esc(val) + '">' + esc(c.label || val) + '</option>');
                });
                updateAccountIdHint();
            }
        });
    }

    function loadBindings(){
        $list.html('<p class="description"><em>Đang tải bindings…</em></p>');
        return api('GET', '/inspector/bindings?character_id=' + characterId).done(function(res){
            var rows = (res && (res.data || res.bindings)) || [];
            $('#bk-channels-count').text(rows.length);
            if(!rows.length){
                $list.html('<div class="bk-channels-empty">Connector này chưa bind kênh nào.<br>Nhấn <strong>Bind kênh mới</strong> để gắn Zalo OA / FB Page / WebChat / Telegram.</div>');
                return;
            }
            $list.html(rows.map(renderBinding).join(''));
        }).fail(function(xhr){
            $list.html('<div class="notice notice-error"><p>Không tải được bindings: ' + esc(xhr.responseText || xhr.statusText) + '</p></div>');
        });
    }

    function refresh(){
        return loadChannels().then(loadBindings);
    }

    $('#bk-channels-refresh').on('click', refresh);

    // Bind dialog
    var $dlg = $('#bk-channels-dialog');

    // [2026-06-22 Johnny Chu] GURU-FINISH W1.2 — Account picker: fetch available accounts for platform.
    // Replaces static hint with dynamic dropdown when accounts are found.
    function loadPlatformAccounts(platform) {
        var $select   = $('#bk-bind-account-select');
        var $input    = $('#bk-bind-account-id');
        var $loading  = $('#bk-bind-account-loading');
        var $hint     = $('#bk-bind-account-hint');
        var $manualHint = $('#bk-bind-account-manual-hint');

        // Reset state
        $select.hide().empty().append('<option value="">— chọn tài khoản —</option>');
        $input.show().val('').attr('placeholder', 'vd: 123456789 hoặc * để wildcard');
        $hint.hide();
        $manualHint.hide();

        if (!platform) { return; }

        var platUpper = platform.toUpperCase();

        // WEBCHAT: show hint + keep text input
        if (platUpper === 'WEBCHAT') {
            $hint.show();
            $input.attr('placeholder', '(để trống = tất cả khách guest)');
            return;
        }

        $loading.show();
        $input.hide();

        $.ajax({
            url: wpApiSettings.root + 'bizcity-channel/v1/platform-accounts',
            method: 'GET',
            data: { platform: platform.toLowerCase() },
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
            success: function(resp) {
                $loading.hide();
                var accounts = (resp && resp.accounts) ? resp.accounts : [];
                if (accounts.length > 0) {
                    // Populate dropdown
                    accounts.forEach(function(acc) {
                        var opt = $('<option>').val(acc.account_id).text(acc.label);
                        $select.append(opt);
                    });
                    $select.show();
                    // Auto-select first (most common case = 1 account)
                    if (accounts.length === 1) {
                        $select.val(accounts[0].account_id);
                    }
                } else {
                    // No preconfigured accounts — fallback to manual
                    $input.show();
                    $manualHint.show();
                }
            },
            error: function() {
                $loading.hide();
                // On REST error, fall back to manual input gracefully
                $input.show();
            }
        });
    }

    // [2026-06-09 Johnny Chu] PHASE-D D-WEBCHAT-WILDCARD (superseded by GURU-FINISH W1.2 above)
    $('#bk-bind-platform').on('change', function(){
        loadPlatformAccounts($(this).val() || '');
    });

    $('#bk-channels-bind-open').on('click', function(){
        $('#bk-bind-account-select').hide().empty().append('<option value="">— chọn tài khoản —</option>');
        $('#bk-bind-account-id').show().val('');
        $('#bk-bind-account-hint').hide();
        $('#bk-bind-account-manual-hint').hide();
        $('#bk-bind-mode').val('auto');
        $('#bk-bind-autoreply').prop('checked', true);
        $('#bk-bind-fallback').val('');
        // Trigger account load for the currently selected platform
        var currentPlatform = $('#bk-bind-platform').val() || '';
        if (currentPlatform) { loadPlatformAccounts(currentPlatform); }
        $dlg.show();
    });
    $('#bk-bind-cancel, .bk-channels-dialog-bg').on('click', function(){ $dlg.hide(); });

    $('#bk-bind-save').on('click', function(){
        var platform = ($('#bk-bind-platform').val() || '').toString();
        // [2026-06-22 Johnny Chu] GURU-FINISH W1.2 — read from dropdown if visible, else text input.
        var $sel = $('#bk-bind-account-select');
        var accountId;
        if ($sel.is(':visible')) {
            accountId = ($sel.val() || '').toString().trim();
        } else {
            accountId = ($('#bk-bind-account-id').val() || '').toString().trim();
        }
        var mode = $('#bk-bind-mode').val();
        var autoReply = $('#bk-bind-autoreply').is(':checked') ? 1 : 0;
        var fallback = parseInt($('#bk-bind-fallback').val() || '0', 10) || 0;
        // [2026-06-09 Johnny Chu] PHASE-D D-WEBCHAT-WILDCARD — WEBCHAT không cần account_id.
        if(platform.toUpperCase() === 'WEBCHAT' && accountId === '') { accountId = '*'; }
        if(!platform || !accountId){
            alert('Cần chọn platform và nhập account_id'); return;
        }
        var $btn = $(this).prop('disabled', true).text('Đang lưu…');
        api('POST', '/inspector/bindings', {
            platform: platform,
            account_id: accountId,
            character_id: characterId,
            mode: mode,
            auto_reply: autoReply,
            fallback_assignee: fallback || null
        }).done(function(){
            $dlg.hide();
            loadBindings();
        }).fail(function(xhr){
            var msg = '';
            try { msg = (JSON.parse(xhr.responseText || '{}').message) || xhr.responseText; }
            catch(e){ msg = xhr.responseText || xhr.statusText; }
            alert('Bind thất bại: ' + msg);
        }).always(function(){
            $btn.prop('disabled', false).text('Bind');
        });
    });

    $list.on('click', '.bk-channel-disable', function(){
        var id = parseInt($(this).attr('data-binding-id'), 10);
        if(!id) return;
        if(!confirm('Disable binding #' + id + '? Listener sẽ không route message của kênh này tới Connector nữa.')) return;
        api('POST', '/inspector/bindings/' + id + '/disable').done(loadBindings).fail(function(xhr){
            alert('Disable thất bại: ' + (xhr.responseText || xhr.statusText));
        });
    });

    // Lazy-load when Channels tab activated
    var chLoaded = false;
    $(document).on('click', '.bk-tab-btn[data-tab="channels"]', function(){
        if(chLoaded) return;
        chLoaded = true;
        refresh();
    });
});
</script>
<?php endif; ?>

<?php
// [2026-06-24 Johnny Chu] GURU-KPI — auto-open tab from URL ?tab= parameter (e.g. from KPI page "Chi tiết" link)
$initial_tab = sanitize_key( $_GET['tab'] ?? '' );
if ( $initial_tab ) :
?>
<script>
jQuery(function($){
    var tab = <?php echo wp_json_encode( $initial_tab ); ?>;
    var allowed = ['general','quick-knowledge','notebooks','channels','dashboard','automations'];
    if ( allowed.indexOf(tab) !== -1 ) {
        // Wait for CharacterEdit to finish init then switch
        setTimeout(function(){
            $('.bk-tab-btn').removeClass('active');
            $('.bk-tab-btn[data-tab="' + tab + '"]').addClass('active');
            $('.bk-tab-content').removeClass('active');
            $('#tab-' + tab).addClass('active');
        }, 50);
    }
});
</script>
<?php endif; ?>
