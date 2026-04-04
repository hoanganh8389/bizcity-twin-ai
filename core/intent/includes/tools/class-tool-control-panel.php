<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Tool Control Panel
 *
 * Admin page for managing the Tool Registry — the source of truth for
 * AI Team Leader's tool awareness and LLM routing.
 *
 * Features:
 *   1. View all registered tools with priority, description, hints
 *   2. Edit custom_description — override what LLM sees
 *   3. Edit custom_hints — keywords/concepts that trigger this tool
 *   4. Drag-to-reorder priority (lower = higher priority in LLM prompt)
 *   5. Toggle active/inactive per tool
 *   6. Preview effective LLM goal list (what the Router actually sends)
 *   7. Mermaid flow diagram of all tools
 *   8. Force re-sync from providers
 *
 * @package BizCity_Intent
 * @since   3.7.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Control_Panel {

    /** @var self|null */
    private static $instance = null;

    /** Admin page slug */
    const PAGE_SLUG = 'bizcity-tool-control-panel';

    /** Nonce action */
    const NONCE_ACTION = 'bizcity_tcp_action';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'wp_ajax_bizcity_tcp_save_tool',     [ $this, 'ajax_save_tool' ] );
        add_action( 'wp_ajax_bizcity_tcp_reorder',       [ $this, 'ajax_reorder' ] );
        add_action( 'wp_ajax_bizcity_tcp_toggle_active', [ $this, 'ajax_toggle_active' ] );
        add_action( 'wp_ajax_bizcity_tcp_force_sync',    [ $this, 'ajax_force_sync' ] );
        add_action( 'wp_ajax_bizcity_tcp_preview_prompt',[ $this, 'ajax_preview_prompt' ] );
        add_action( 'wp_ajax_bizcity_tcp_get_mermaid',   [ $this, 'ajax_get_mermaid' ] );
        add_action( 'wp_ajax_bizcity_tcp_get_tools',     [ $this, 'ajax_get_tools' ] );
        add_action( 'wp_ajax_bizcity_tcp_save_settings', [ $this, 'ajax_save_settings' ] );
    }

    /**
     * Register admin menu — under Intent Monitor.
     */
    public function register_menu() {
        add_submenu_page(
            'bizcity-intent-monitor',
            '🎛️ Tool Control Panel',
            '🎛️ Control Panel',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    /**
     * Render the full admin page.
     */
    public function render_page() {
        $tool_index = BizCity_Intent_Tool_Index::instance();
        $tools      = $tool_index->get_all_for_control_panel();
        $counts     = $tool_index->get_counts_by_plugin();

        // Build effective preview
        $router = BizCity_Intent_Router::instance();
        ?>
        <div class="wrap bizcity-tcp-wrap">
            <h1>🎛️ Tool Control Panel <small style="font-weight:normal; color:#888;">— Quản lý công cụ AI</small></h1>
            <p class="description">
                Cấu hình prompt, ưu tiên, từ khóa cho từng tool. AI Router sẽ sử dụng thông tin này để phân loại intent chính xác hơn.
            </p>

            <!-- ── Tab Navigation ── -->
            <nav class="nav-tab-wrapper bizcity-tcp-tabs">
                <a href="#" class="nav-tab nav-tab-active" data-tab="tools">🔧 Công cụ (<?php echo count($tools); ?>)</a>
                <a href="#" class="nav-tab" data-tab="preview">👁️ Preview Prompt</a>
                <a href="#" class="nav-tab" data-tab="flow">📊 Flow Diagram</a>
                <a href="#" class="nav-tab" data-tab="stats">📈 Thống kê</a>
            </nav>

            <!-- ══════════════════════════════════════════════
                 TAB 1: Tools Registry Table
            ══════════════════════════════════════════════ -->
            <div class="bizcity-tcp-tab-content" id="tcp-tab-tools" style="display:block;">
                <div class="bizcity-tcp-toolbar">
                    <button type="button" class="button button-primary" id="tcp-force-sync">
                        🔄 Đồng bộ lại từ Plugins
                    </button>
                    <button type="button" class="button" id="tcp-save-order">
                        💾 Lưu thứ tự ưu tiên
                    </button>
                    <span class="bizcity-tcp-status" id="tcp-status"></span>
                </div>

                <table class="wp-list-table widefat striped bizcity-tcp-table" id="tcp-tools-table">
                    <thead>
                        <tr>
                            <th style="width:30px;">⇅</th>
                            <th style="width:50px;">P</th>
                            <th style="width:40px;">ON</th>
                            <th style="width:140px;">Tool</th>
                            <th style="width:100px;">Plugin</th>
                            <th>Mô tả cho AI <small>(custom_description)</small></th>
                            <th>Từ khóa / Hints <small>(custom_hints)</small></th>
                            <th style="width:120px;">Slots</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody id="tcp-tools-body">
                        <?php foreach ( $tools as $row ) : ?>
                        <?php $this->render_tool_row( $row ); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ══════════════════════════════════════════════
                 TAB 2: Preview LLM Prompt
            ══════════════════════════════════════════════ -->
            <div class="bizcity-tcp-tab-content" id="tcp-tab-preview" style="display:none;">
                <h3>🧠 LLM Prompt Preview — Đây là prompt Router gửi cho LLM để phân loại intent</h3>
                <p class="description">
                    Khi user gửi tin nhắn, AI Router sẽ gửi danh sách goals bên dưới cho LLM.
                    LLM dựa vào mô tả + hints để chọn đúng goal. Bạn có thể thay đổi mô tả và hints ở tab "Công cụ" để điều chỉnh.
                </p>
                <div class="bizcity-tcp-prompt-box">
                    <h4>Tier 1 — Goal List Compact (dùng cho fast classification)</h4>
                    <pre id="tcp-preview-compact" class="bizcity-tcp-pre">Đang tải...</pre>
                </div>
                <div class="bizcity-tcp-prompt-box">
                    <h4>Full Manifest — Tool Registry Context (inject vào system prompt)</h4>
                    <pre id="tcp-preview-full" class="bizcity-tcp-pre">Đang tải...</pre>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════
                 TAB 3: Flow Diagram (Mermaid)
            ══════════════════════════════════════════════ -->
            <div class="bizcity-tcp-tab-content" id="tcp-tab-flow" style="display:none;">
                <h3>📊 Tool Flow Diagram — Sơ đồ phân luồng từ User → Router → Tools</h3>
                <p class="description">Sơ đồ Mermaid hiển thị cách AI Router phân luồng tin nhắn đến từng tool.</p>
                <div class="bizcity-tcp-mermaid-container">
                    <div id="tcp-mermaid-render"></div>
                </div>
                <details>
                    <summary>📋 Xem Mermaid source code</summary>
                    <pre id="tcp-mermaid-source" class="bizcity-tcp-pre"></pre>
                </details>
            </div>

            <!-- ══════════════════════════════════════════════
                 TAB 4: Statistics + Settings
            ══════════════════════════════════════════════ -->
            <div class="bizcity-tcp-tab-content" id="tcp-tab-stats" style="display:none;">
                <h3>📈 Thống kê Tool Registry</h3>
                <div class="bizcity-tcp-stats-grid">
                    <div class="bizcity-tcp-stat-card">
                        <div class="tcp-stat-number"><?php echo count($tools); ?></div>
                        <div class="tcp-stat-label">Tổng công cụ</div>
                    </div>
                    <div class="bizcity-tcp-stat-card">
                        <div class="tcp-stat-number"><?php echo count(array_filter($tools, function($t) { return $t['active']; })); ?></div>
                        <div class="tcp-stat-label">Đang hoạt động</div>
                    </div>
                    <div class="bizcity-tcp-stat-card">
                        <div class="tcp-stat-number"><?php echo count($counts); ?></div>
                        <div class="tcp-stat-label">Plugins cung cấp</div>
                    </div>
                    <div class="bizcity-tcp-stat-card">
                        <div class="tcp-stat-number"><?php echo count(array_filter($tools, function($t) { return !empty($t['custom_hints']); })); ?></div>
                        <div class="tcp-stat-label">Có custom hints</div>
                    </div>
                </div>

                <h4>Phân bổ theo Plugin</h4>
                <table class="wp-list-table widefat striped">
                    <thead><tr><th>Plugin</th><th>Số tools</th></tr></thead>
                    <tbody>
                    <?php foreach ( $counts as $plugin => $cnt ) : ?>
                        <tr>
                            <td><code><?php echo esc_html($plugin); ?></code></td>
                            <td><strong><?php echo (int)$cnt; ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- ── Settings: Routing Priority ── -->
                <h3 style="margin-top:30px;">🎯 Ưu tiên Routing</h3>
                <p class="description">Cài đặt hướng ưu tiên cho Assistant: thiên về trò chuyện/cảm xúc hay thiên về thực thi công cụ.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Routing Priority</label>
                        </th>
                        <td>
                            <?php $routing_priority = get_option( 'bizcity_tcp_routing_priority', 'balanced' ); ?>
                            <fieldset>
                                <label style="display:block; margin-bottom:8px;">
                                    <input type="radio" name="tcp_routing_priority" value="conversation" <?php checked( $routing_priority, 'conversation' ); ?>>
                                    <strong>💬 Trò chuyện</strong> — Ưu tiên cảm xúc, reflection, knowledge. Tool chỉ chạy khi @mention rõ ràng.
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input type="radio" name="tcp_routing_priority" value="balanced" <?php checked( $routing_priority, 'balanced' ); ?>>
                                    <strong>⚖️ Cân bằng</strong> — Mặc định. AI tự phân loại, gợi ý tool khi phù hợp.
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input type="radio" name="tcp_routing_priority" value="tools" <?php checked( $routing_priority, 'tools' ); ?>>
                                    <strong>🔧 Công cụ</strong> — Ưu tiên phát hiện & thực thi tool. Phù hợp workspace productivity.
                                </label>
                            </fieldset>
                            <p class="description">
                                🔹 <strong>Trò chuyện:</strong> 60%+ tin nhắn sẽ vào emotion/knowledge/reflection. Tool suggestion bị tắt.<br>
                                🔹 <strong>Cân bằng:</strong> AI tự quyết định. Nếu chưa chắc tool nào → hỏi user xác nhận.<br>
                                🔹 <strong>Công cụ:</strong> Ngưỡng phát hiện tool thấp hơn, auto-suggest @tool khi có khả năng khớp.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── Settings: Image Default Goal ── -->
                <h3 style="margin-top:30px;">🖼️ Mặc định khi gửi ảnh</h3>
                <p class="description">Khi user gửi ảnh mà không nói rõ mục đích, Router sẽ xử lý theo cấu hình này.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tcp-image-default-goal">Image Default Goal</label>
                        </th>
                        <td>
                            <?php $image_default = get_option( 'bizcity_tcp_image_default_goal', 'tarot_interpret' ); ?>
                            <select id="tcp-image-default-goal">
                                <option value="tarot_interpret" <?php selected( $image_default, 'tarot_interpret' ); ?>>🃏 Giải bài Tarot</option>
                                <option value="image_describe" <?php selected( $image_default, 'image_describe' ); ?>>📝 Mô tả hình ảnh</option>
                                <option value="image_analyze" <?php selected( $image_default, 'image_analyze' ); ?>>🔍 Phân tích hình ảnh</option>
                                <option value="passthrough" <?php selected( $image_default, 'passthrough' ); ?>>🔄 Để AI tự quyết định (passthrough)</option>
                            </select>
                            <p class="description">
                                🔹 <strong>Giải bài Tarot:</strong> Mặc định hiện tại — ảnh gửi vào sẽ tự động chạy tool tarot_interpret.<br>
                                🔹 <strong>Passthrough:</strong> Không gán goal, để Intent Engine quyết định dựa trên context.
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- ── Settings: LLM Prompt Configuration ── -->
                <h3 style="margin-top:30px;">⚙️ Cấu hình LLM Prompt</h3>
                <p class="description">Điều chỉnh cách AI Router inject tool schema vào prompt phân loại.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tcp-top-n-tools">Top N Tools trong Prompt</label>
                        </th>
                        <td>
                            <input type="number" id="tcp-top-n-tools" value="<?php echo esc_attr( BizCity_Intent_Router::get_top_n_tools() ); ?>" min="3" max="50" step="1" class="small-text" style="width:80px;">
                            <p class="description">
                                Số lượng tool tối đa inject vào LLM prompt khi phân loại intent. (min 3, max 50, mặc định 10)<br>
                                🔹 Regex pre-match sẽ ưu tiên tool khớp lên đầu danh sách (★ marker).<br>
                                🔹 Giá trị nhỏ hơn → prompt ngắn hơn → nhanh hơn + rẻ hơn, nhưng có thể bỏ sót tool.<br>
                                🔹 Giá trị lớn hơn → chính xác hơn nhưng tốn token hơn.
                            </p>
                        </td>
                    </tr>
                </table>
                <button type="button" class="button button-primary" id="tcp-save-settings">💾 Lưu cấu hình</button>
                <span class="bizcity-tcp-status" id="tcp-settings-status"></span>
            </div>

        </div>

        <?php $this->render_inline_css(); ?>
        <?php $this->render_inline_js(); ?>
        <?php
    }

    /**
     * Render a single tool row in the table.
     */
    private function render_tool_row( array $row ) {
        $id = (int) $row['id'];
        $active = (int) $row['active'];
        $priority = (int) ( $row['priority'] ?? 50 );
        $goal = $row['goal'] ?: $row['tool_name'];
        $label = $row['goal_label'] ?: $row['title'] ?: $row['tool_name'];
        $plugin = $row['plugin'] ?: 'builtin';
        $desc_provider = $row['goal_description'] ?: $row['description'] ?: '';
        $desc_custom = $row['custom_description'] ?? '';
        $hints = $row['custom_hints'] ?? '';

        // Slots summary
        $req = $this->slot_summary( $row['required_slots'] ?? '', '🔴' );
        $opt = $this->slot_summary( $row['optional_slots'] ?? '', '⚪' );
        $slots_html = $req . ( $req && $opt ? '<br>' : '' ) . $opt;

        $row_class = $active ? '' : 'tcp-row-inactive';
        ?>
        <tr class="tcp-tool-row <?php echo $row_class; ?>" data-tool-id="<?php echo $id; ?>" data-priority="<?php echo $priority; ?>">
            <td class="tcp-drag-handle" title="Kéo để sắp xếp">☰</td>
            <td>
                <input type="number" class="tcp-priority-input" value="<?php echo $priority; ?>"
                       min="1" max="999" style="width:45px;" data-tool-id="<?php echo $id; ?>">
            </td>
            <td>
                <label class="tcp-toggle">
                    <input type="checkbox" class="tcp-active-toggle" data-tool-id="<?php echo $id; ?>"
                           <?php checked($active, 1); ?>>
                    <span class="tcp-toggle-slider"></span>
                </label>
            </td>
            <td>
                <strong><?php echo esc_html($goal); ?></strong>
                <br><small class="tcp-label"><?php echo esc_html($label); ?></small>
            </td>
            <td><code class="tcp-plugin-badge"><?php echo esc_html($plugin); ?></code></td>
            <td>
                <?php if ( $desc_provider ) : ?>
                <div class="tcp-desc-provider" title="Mô tả gốc từ plugin">
                    <small>📦 <?php echo esc_html( mb_substr($desc_provider, 0, 80, 'UTF-8') ); ?></small>
                </div>
                <?php endif; ?>
                <textarea class="tcp-desc-input" data-tool-id="<?php echo $id; ?>" data-field="custom_description"
                          placeholder="✏️ Nhập mô tả tùy chỉnh cho AI Router (override mô tả gốc)..."
                          rows="2"><?php echo esc_textarea($desc_custom); ?></textarea>
                <?php
                $suggestions = $this->generate_suggestions( $row );
                if ( empty( $desc_custom ) && ! empty( $suggestions['desc'] ) ) {
                    echo $this->render_suggestion_chips( $suggestions['desc'], 'tcp-desc-input' );
                }
                ?>
            </td>
            <td>
                <textarea class="tcp-hints-input" data-tool-id="<?php echo $id; ?>" data-field="custom_hints"
                          placeholder="🔑 Từ khóa kích hoạt tool này (VD: viết bài, tạo sản phẩm...)"
                          rows="2"><?php echo esc_textarea($hints); ?></textarea>
                <?php
                if ( empty( $hints ) && ! empty( $suggestions['hints'] ) ) {
                    echo $this->render_suggestion_chips( $suggestions['hints'], 'tcp-hints-input' );
                }
                ?>
            </td>
            <td class="tcp-slots"><?php echo $slots_html; ?></td>
            <td>
                <button type="button" class="button button-small tcp-save-btn" data-tool-id="<?php echo $id; ?>">💾</button>
            </td>
        </tr>
        <?php
    }

    /**
     * Generate contextual suggestions for a tool's description and hints.
     *
     * @param array $row Tool registry row.
     * @return array { desc_suggestions: string[], hint_suggestions: string[] }
     */
    private function generate_suggestions( array $row ): array {
        $tool_name = $row['tool_name'] ?? '';
        $goal      = $row['goal'] ?? $tool_name;
        $plugin    = $row['plugin'] ?? '';
        $label     = $row['goal_label'] ?? $row['title'] ?? '';
        $desc      = $row['goal_description'] ?? $row['description'] ?? '';

        // Searchable text
        $haystack = mb_strtolower( $tool_name . ' ' . $goal . ' ' . $label . ' ' . $desc . ' ' . $plugin, 'UTF-8' );

        // ── Keyword → hint suggestions map ──
        $kw_map = [
            'write|viết|soạn|tạo bài|content'    => [ 'viết bài', 'tạo nội dung', 'soạn content' ],
            'article|bài viết|blog'               => [ 'bài viết', 'blog post', 'đăng bài' ],
            'seo'                                  => [ 'SEO', 'tối ưu SEO', 'từ khóa SEO' ],
            'rewrite|viết lại|chỉnh sửa'          => [ 'viết lại', 'sửa bài', 'chỉnh sửa' ],
            'translate|dịch|chuyển ngữ'            => [ 'dịch bài', 'dịch tiếng', 'chuyển ngữ' ],
            'product|sản phẩm'                     => [ 'sản phẩm', 'tạo sản phẩm', 'đăng bán' ],
            'order|đơn hàng'                       => [ 'đơn hàng', 'tạo đơn', 'đặt hàng' ],
            'tarot'                                => [ 'tarot', 'bói bài', 'rút bài tarot' ],
            'astro|tử vi|horoscope'                => [ 'tử vi', 'chiêm tinh', 'horoscope' ],
            'natal|lá số|birth chart'              => [ 'lá số', 'bản đồ sao', 'natal chart' ],
            'gemini'                               => [ 'hỏi gemini', 'dùng gemini', 'google AI' ],
            'chatgpt|gpt|openai'                   => [ 'hỏi chatgpt', 'dùng GPT', 'hỏi AI openai' ],
            'knowledge|kiến thức|tra cứu'          => [ 'kiến thức', 'tra cứu', 'tìm hiểu' ],
            'train|học|huấn luyện'                 => [ 'học file này', 'huấn luyện AI', 'thêm kiến thức' ],
            'search|tìm kiếm'                      => [ 'tìm kiếm', 'search', 'tra cứu' ],
            'schedule|lịch|hẹn giờ'                => [ 'lên lịch đăng', 'hẹn giờ', 'schedule' ],
            'video|kịch bản'                       => [ 'tạo video', 'kịch bản video', 'script video' ],
            'image|ảnh|hình'                       => [ 'tạo ảnh', 'hình minh họa', 'upload ảnh' ],
            'warehouse|kho|nhập kho'               => [ 'nhập kho', 'xuất kho', 'tồn kho' ],
            'calo|nutrition|dinh dưỡng'            => [ 'tính calo', 'dinh dưỡng', 'calories' ],
            'report|báo cáo|thống kê'              => [ 'báo cáo', 'thống kê', 'xem report' ],
            'map|bản đồ|vị trí'                    => [ 'bản đồ', 'vị trí', 'tìm địa điểm' ],
            'forecast|dự báo'                      => [ 'dự báo', 'dự đoán', 'forecast' ],
            'help|hướng dẫn|guide'                 => [ 'hướng dẫn', 'trợ giúp', 'cách dùng' ],
            'email|thư'                            => [ 'gửi email', 'viết thư', 'soạn mail' ],
            'summary|tóm tắt'                      => [ 'tóm tắt', 'summary', 'rút gọn' ],
        ];

        $hint_suggestions = [];
        foreach ( $kw_map as $pattern => $suggestions ) {
            foreach ( explode( '|', $pattern ) as $kw ) {
                if ( mb_strpos( $haystack, $kw ) !== false ) {
                    $hint_suggestions = array_merge( $hint_suggestions, $suggestions );
                    break;
                }
            }
        }
        $hint_suggestions = array_unique( $hint_suggestions );

        // ── Description suggestions ──
        $desc_suggestions = [];

        // Suggest based on goal_label (if it's natural language)
        if ( $label && ! preg_match( '/^[a-z0-9_]+$/i', $label ) ) {
            $desc_suggestions[] = 'Khi user muốn ' . mb_strtolower( $label, 'UTF-8' );
        }

        // Suggest an action-based description
        $action = str_replace( '_', ' ', $tool_name );
        $desc_suggestions[] = 'Dùng khi user yêu cầu ' . $action;

        // If goal_description exists, suggest a refined version
        if ( $desc ) {
            $desc_suggestions[] = mb_substr( $desc, 0, 80, 'UTF-8' );
        }

        $desc_suggestions = array_values( array_unique( $desc_suggestions ) );

        return [
            'hints' => array_slice( $hint_suggestions, 0, 6 ),
            'desc'  => array_slice( $desc_suggestions, 0, 3 ),
        ];
    }

    /**
     * Render suggestion chips HTML.
     *
     * @param array  $suggestions Array of suggestion strings.
     * @param string $target_class CSS class of the target textarea.
     * @return string HTML.
     */
    private function render_suggestion_chips( array $suggestions, string $target_class ): string {
        if ( empty( $suggestions ) ) return '';
        $chips = '';
        foreach ( $suggestions as $s ) {
            $chips .= '<span class="tcp-suggest-chip" data-target="' . esc_attr( $target_class ) . '" '
                    . 'data-value="' . esc_attr( $s ) . '" title="Click để điền">'
                    . esc_html( $s ) . '</span>';
        }
        return '<div class="tcp-suggest-row"><small class="tcp-suggest-label">💡 Gợi ý:</small>' . $chips . '</div>';
    }

    /**
     * Build slot summary HTML.
     */
    private function slot_summary( string $json, string $icon ): string {
        if ( empty( $json ) ) return '';
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data ) ) return '';
        $names = array_keys( $data );
        return $icon . ' ' . implode( ', ', array_map( function($n) { return '<code>' . esc_html($n) . '</code>'; }, $names ) );
    }

    /* ================================================================
     *  AJAX Handlers
     * ================================================================ */

    /**
     * Save a single tool's admin fields.
     */
    public function ajax_save_tool() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $tool_id = (int) ( $_POST['tool_id'] ?? 0 );
        if ( ! $tool_id ) {
            wp_send_json_error( 'Missing tool_id' );
        }

        $data = [];
        if ( isset( $_POST['custom_description'] ) ) {
            $data['custom_description'] = sanitize_textarea_field( $_POST['custom_description'] );
        }
        if ( isset( $_POST['custom_hints'] ) ) {
            $data['custom_hints'] = sanitize_textarea_field( $_POST['custom_hints'] );
        }
        if ( isset( $_POST['priority'] ) ) {
            $data['priority'] = max( 1, min( 999, (int) $_POST['priority'] ) );
        }

        $index = BizCity_Intent_Tool_Index::instance();
        $ok = $index->update_tool_admin_fields( $tool_id, $data );

        wp_send_json_success( [ 'updated' => $ok, 'tool_id' => $tool_id ] );
    }

    /**
     * Batch reorder priorities.
     */
    public function ajax_reorder() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $order = json_decode( stripslashes( $_POST['order'] ?? '{}' ), true );
        if ( ! is_array( $order ) || empty( $order ) ) {
            wp_send_json_error( 'Invalid order data' );
        }

        $index   = BizCity_Intent_Tool_Index::instance();
        $updated = $index->batch_update_priority( $order );

        wp_send_json_success( [ 'updated' => $updated ] );
    }

    /**
     * Toggle a tool active/inactive.
     */
    public function ajax_toggle_active() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $tool_id = (int) ( $_POST['tool_id'] ?? 0 );
        $active  = (int) ( $_POST['active'] ?? 1 );

        $index = BizCity_Intent_Tool_Index::instance();
        $ok    = $index->update_tool_admin_fields( $tool_id, [ 'active' => $active ] );

        wp_send_json_success( [ 'updated' => $ok, 'tool_id' => $tool_id, 'active' => $active ] );
    }

    /**
     * Force re-sync all tools from providers.
     */
    public function ajax_force_sync() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        if ( ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            wp_send_json_error( 'Provider Registry not available' );
        }

        $registry  = BizCity_Intent_Provider_Registry::instance();
        $providers = $registry->get_all();
        $index     = BizCity_Intent_Tool_Index::instance();
        $index->force_sync_all( $providers );

        // Reload tools
        $tools = $index->get_all_for_control_panel();

        wp_send_json_success( [
            'message' => 'Đã đồng bộ lại ' . count($tools) . ' tools từ ' . count($providers) . ' providers.',
            'count'   => count($tools),
        ] );
    }

    /**
     * Preview the effective LLM prompts.
     */
    public function ajax_preview_prompt() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $router  = BizCity_Intent_Router::instance();
        $index   = BizCity_Intent_Tool_Index::instance();

        // Goal list compact (Tier 1)
        $compact = $router->build_goal_list_compact_for_preview( 2000 );

        // Full manifest (Tool Registry context)
        $full = $index->build_tools_context( 3000 );

        wp_send_json_success( [
            'compact' => $compact,
            'full'    => $full,
        ] );
    }

    /**
     * Get Mermaid flow diagram.
     */
    public function ajax_get_mermaid() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $index   = BizCity_Intent_Tool_Index::instance();
        $mermaid = $index->build_mermaid_flow();

        wp_send_json_success( [ 'mermaid' => $mermaid ] );
    }

    /**
     * Get full tools list (for dynamic refresh after sync).
     */
    public function ajax_get_tools() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $index = BizCity_Intent_Tool_Index::instance();
        $tools = $index->get_all_for_control_panel();

        wp_send_json_success( [ 'tools' => $tools ] );
    }

    /**
     * AJAX: Save Control Panel settings (top_n_tools, etc.)
     * @since 3.8.0
     */
    public function ajax_save_settings() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
        }

        $top_n = isset( $_POST['top_n_tools'] ) ? absint( $_POST['top_n_tools'] ) : 10;
        $top_n = max( 3, min( 50, $top_n ) );

        // Routing priority: conversation | balanced | tools
        $valid_priorities = array( 'conversation', 'balanced', 'tools' );
        $routing_priority = isset( $_POST['routing_priority'] ) ? sanitize_text_field( $_POST['routing_priority'] ) : 'balanced';
        if ( ! in_array( $routing_priority, $valid_priorities, true ) ) {
            $routing_priority = 'balanced';
        }

        // Image default goal
        $valid_image_goals = array( 'tarot_interpret', 'image_describe', 'image_analyze', 'passthrough' );
        $image_default = isset( $_POST['image_default_goal'] ) ? sanitize_text_field( $_POST['image_default_goal'] ) : 'tarot_interpret';
        if ( ! in_array( $image_default, $valid_image_goals, true ) ) {
            $image_default = 'tarot_interpret';
        }

        update_option( 'bizcity_tcp_top_n_tools', $top_n );
        update_option( 'bizcity_tcp_routing_priority', $routing_priority );
        update_option( 'bizcity_tcp_image_default_goal', $image_default );

        // Clear any cached context that depends on these settings.
        delete_transient( 'bizcity_intent_context' );

        $priority_labels = array(
            'conversation' => '💬 Trò chuyện',
            'balanced'     => '⚖️ Cân bằng',
            'tools'        => '🔧 Công cụ',
        );

        wp_send_json_success( array(
            'message'          => sprintf(
                'Đã lưu: Top N = %d | Routing = %s | Image = %s',
                $top_n,
                isset( $priority_labels[ $routing_priority ] ) ? $priority_labels[ $routing_priority ] : $routing_priority,
                $image_default
            ),
            'top_n_tools'      => $top_n,
            'routing_priority' => $routing_priority,
            'image_default'    => $image_default,
        ) );
    }

    /* ================================================================
     *  Inline CSS
     * ================================================================ */

    private function render_inline_css() {
        ?>
        <style>
        .bizcity-tcp-wrap { max-width: 1400px; }
        .bizcity-tcp-tabs { margin-bottom: 0; }
        .bizcity-tcp-tab-content { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-top: 0; }
        .bizcity-tcp-toolbar { margin-bottom: 15px; display: flex; gap: 10px; align-items: center; }
        .bizcity-tcp-status { color: #46b450; font-weight: 600; }

        /* Table */
        .bizcity-tcp-table { border-collapse: collapse; }
        .bizcity-tcp-table th { font-size: 12px; background: #f1f1f1; }
        .bizcity-tcp-table td { vertical-align: top; padding: 8px 6px; }
        .tcp-drag-handle { cursor: grab; font-size: 16px; text-align: center; color: #999; }
        .tcp-drag-handle:active { cursor: grabbing; }
        .tcp-tool-row.tcp-dragging { opacity: 0.5; background: #e8f0fe; }
        .tcp-tool-row.tcp-row-inactive { opacity: 0.5; background: #fafafa; }
        .tcp-priority-input { text-align: center; font-size: 13px; }
        .tcp-label { color: #888; }
        .tcp-plugin-badge { background: #e8f0fe; padding: 2px 6px; border-radius: 3px; font-size: 11px; }
        .tcp-desc-provider { background: #f9f9f9; padding: 4px 6px; border-radius: 3px; margin-bottom: 4px; font-size: 11px; color: #666; }
        .tcp-desc-input, .tcp-hints-input { width: 100%; font-size: 12px; resize: vertical; min-height: 40px; }
        .tcp-desc-input:focus, .tcp-hints-input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
        .tcp-slots code { font-size: 10px; background: #f0f0f0; padding: 1px 3px; border-radius: 2px; }
        .tcp-save-btn { font-size: 14px !important; }

        /* Suggestion chips */
        .tcp-suggest-row { margin-top: 4px; display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
        .tcp-suggest-label { color: #b0b0b0; font-size: 11px; margin-right: 2px; }
        .tcp-suggest-chip {
            display: inline-block; font-size: 10.5px; padding: 2px 8px;
            background: #f0f5ff; color: #3b82f6; border: 1px solid #bfdbfe;
            border-radius: 10px; cursor: pointer; transition: all .15s;
            white-space: nowrap; user-select: none;
        }
        .tcp-suggest-chip:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .tcp-suggest-chip:active { transform: scale(.95); }

        /* Toggle switch */
        .tcp-toggle { position: relative; display: inline-block; width: 34px; height: 18px; }
        .tcp-toggle input { opacity: 0; width: 0; height: 0; }
        .tcp-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc; transition: .3s; border-radius: 18px; }
        .tcp-toggle-slider:before { position: absolute; content: ""; height: 14px; width: 14px;
            left: 2px; bottom: 2px; background: #fff; transition: .3s; border-radius: 50%; }
        .tcp-toggle input:checked + .tcp-toggle-slider { background-color: #46b450; }
        .tcp-toggle input:checked + .tcp-toggle-slider:before { transform: translateX(16px); }

        /* Preview */
        .bizcity-tcp-prompt-box { margin-bottom: 20px; }
        .bizcity-tcp-pre { background: #1e1e1e; color: #d4d4d4; padding: 16px; border-radius: 6px;
            font-size: 12px; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-word;
            max-height: 500px; overflow-y: auto; }

        /* Mermaid */
        .bizcity-tcp-mermaid-container { background: #fff; padding: 20px; border: 1px solid #ddd;
            border-radius: 6px; min-height: 300px; overflow: auto; }
        .bizcity-tcp-mermaid-container svg { max-width: 100%; }

        /* Stats */
        .bizcity-tcp-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px; margin-bottom: 20px; }
        .bizcity-tcp-stat-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px;
            padding: 20px; text-align: center; }
        .tcp-stat-number { font-size: 32px; font-weight: 700; color: #2271b1; }
        .tcp-stat-label { font-size: 13px; color: #666; margin-top: 5px; }

        /* Row highlight on save */
        .tcp-tool-row.tcp-saved { animation: tcpSaveFlash 0.6s ease; }
        @keyframes tcpSaveFlash { 0% { background: #d4edda; } 100% { background: transparent; } }
        </style>
        <?php
    }

    /* ================================================================
     *  Inline JS
     * ================================================================ */

    private function render_inline_js() {
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script src="https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js"></script>
        <script>
        (function(){
            'use strict';
            const NONCE = '<?php echo esc_js($nonce); ?>';
            const AJAX  = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            /* ── Tab switching ── */
            document.querySelectorAll('.bizcity-tcp-tabs .nav-tab').forEach(tab => {
                tab.addEventListener('click', e => {
                    e.preventDefault();
                    document.querySelectorAll('.bizcity-tcp-tabs .nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
                    tab.classList.add('nav-tab-active');
                    document.querySelectorAll('.bizcity-tcp-tab-content').forEach(c => c.style.display = 'none');
                    const target = tab.dataset.tab;
                    document.getElementById('tcp-tab-' + target).style.display = 'block';

                    // Lazy-load content
                    if (target === 'preview') loadPreview();
                    if (target === 'flow') loadMermaid();
                });
            });

            /* ── Status flash ── */
            function showStatus(msg, isError) {
                const el = document.getElementById('tcp-status');
                el.textContent = msg;
                el.style.color = isError ? '#dc3545' : '#46b450';
                setTimeout(() => el.textContent = '', 3000);
            }

            /* ── Save single tool ── */
            document.querySelectorAll('.tcp-save-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const toolId = this.dataset.toolId;
                    const row = document.querySelector(`.tcp-tool-row[data-tool-id="${toolId}"]`);
                    const desc = row.querySelector('.tcp-desc-input').value;
                    const hints = row.querySelector('.tcp-hints-input').value;
                    const priority = row.querySelector('.tcp-priority-input').value;

                    const fd = new FormData();
                    fd.append('action', 'bizcity_tcp_save_tool');
                    fd.append('nonce', NONCE);
                    fd.append('tool_id', toolId);
                    fd.append('custom_description', desc);
                    fd.append('custom_hints', hints);
                    fd.append('priority', priority);

                    fetch(AJAX, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                showStatus('✅ Đã lưu tool #' + toolId);
                                row.classList.add('tcp-saved');
                                setTimeout(() => row.classList.remove('tcp-saved'), 700);
                            } else {
                                showStatus('❌ Lỗi: ' + (res.data || 'Unknown'), true);
                            }
                        });
                });
            });

            /* ── Toggle active ── */
            document.querySelectorAll('.tcp-active-toggle').forEach(cb => {
                cb.addEventListener('change', function() {
                    const toolId = this.dataset.toolId;
                    const active = this.checked ? 1 : 0;
                    const row = document.querySelector(`.tcp-tool-row[data-tool-id="${toolId}"]`);

                    const fd = new FormData();
                    fd.append('action', 'bizcity_tcp_toggle_active');
                    fd.append('nonce', NONCE);
                    fd.append('tool_id', toolId);
                    fd.append('active', active);

                    fetch(AJAX, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                row.classList.toggle('tcp-row-inactive', !active);
                                showStatus(active ? '✅ Đã bật tool' : '⏸️ Đã tắt tool');
                            }
                        });
                });
            });

            /* ── Save priority order ── */
            document.getElementById('tcp-save-order').addEventListener('click', function() {
                const order = {};
                document.querySelectorAll('.tcp-priority-input').forEach(input => {
                    order[input.dataset.toolId] = parseInt(input.value) || 50;
                });

                const fd = new FormData();
                fd.append('action', 'bizcity_tcp_reorder');
                fd.append('nonce', NONCE);
                fd.append('order', JSON.stringify(order));

                fetch(AJAX, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            showStatus('✅ Đã lưu thứ tự (' + res.data.updated + ' tools)');
                        }
                    });
            });

            /* ── Force sync ── */
            document.getElementById('tcp-force-sync').addEventListener('click', function() {
                this.disabled = true;
                this.textContent = '⏳ Đang đồng bộ...';

                const fd = new FormData();
                fd.append('action', 'bizcity_tcp_force_sync');
                fd.append('nonce', NONCE);

                fetch(AJAX, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        this.disabled = false;
                        this.textContent = '🔄 Đồng bộ lại từ Plugins';
                        if (res.success) {
                            showStatus('✅ ' + res.data.message);
                            // Reload the page to refresh the table
                            setTimeout(() => location.reload(), 500);
                        } else {
                            showStatus('❌ ' + (res.data || 'Sync failed'), true);
                        }
                    });
            });

            /* ── Load Preview ── */
            function loadPreview() {
                const fd = new FormData();
                fd.append('action', 'bizcity_tcp_preview_prompt');
                fd.append('nonce', NONCE);

                fetch(AJAX, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            document.getElementById('tcp-preview-compact').textContent = res.data.compact || '(trống)';
                            document.getElementById('tcp-preview-full').textContent = res.data.full || '(trống)';
                        }
                    });
            }

            /* ── Load Mermaid ── */
            function loadMermaid() {
                const fd = new FormData();
                fd.append('action', 'bizcity_tcp_get_mermaid');
                fd.append('nonce', NONCE);

                fetch(AJAX, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success && res.data.mermaid) {
                            const src = res.data.mermaid;
                            document.getElementById('tcp-mermaid-source').textContent = src;

                            // Render Mermaid diagram
                            const container = document.getElementById('tcp-mermaid-render');
                            container.innerHTML = '';
                            const div = document.createElement('div');
                            div.className = 'mermaid';
                            div.textContent = src;
                            container.appendChild(div);

                            if (window.mermaid) {
                                mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });
                                mermaid.run({ nodes: [div] });
                            }
                        }
                    });
            }

            /* ── Drag-and-drop reorder (simple) ── */
            let dragRow = null;
            const tbody = document.getElementById('tcp-tools-body');

            tbody.addEventListener('dragstart', e => {
                dragRow = e.target.closest('.tcp-tool-row');
                if (dragRow) {
                    dragRow.classList.add('tcp-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                }
            });

            tbody.addEventListener('dragover', e => {
                e.preventDefault();
                const target = e.target.closest('.tcp-tool-row');
                if (target && target !== dragRow) {
                    const rect = target.getBoundingClientRect();
                    const mid = rect.top + rect.height / 2;
                    if (e.clientY < mid) {
                        tbody.insertBefore(dragRow, target);
                    } else {
                        tbody.insertBefore(dragRow, target.nextSibling);
                    }
                }
            });

            tbody.addEventListener('dragend', () => {
                if (dragRow) {
                    dragRow.classList.remove('tcp-dragging');
                    // Update priority inputs based on new order
                    let p = 10;
                    tbody.querySelectorAll('.tcp-priority-input').forEach(input => {
                        input.value = p;
                        p += 10;
                    });
                    dragRow = null;
                }
            });

            // Make rows draggable via handle
            document.querySelectorAll('.tcp-drag-handle').forEach(handle => {
                handle.closest('tr').setAttribute('draggable', 'true');
            });

            /* ── Save settings (top_n_tools + routing_priority + image_default) ── */
            document.getElementById('tcp-save-settings').addEventListener('click', function() {
                const topN = document.getElementById('tcp-top-n-tools').value;
                const routingPriority = document.querySelector('input[name="tcp_routing_priority"]:checked');
                const imageDefault = document.getElementById('tcp-image-default-goal');
                const fd = new FormData();
                fd.append('action', 'bizcity_tcp_save_settings');
                fd.append('nonce', NONCE);
                fd.append('top_n_tools', topN);
                if (routingPriority) fd.append('routing_priority', routingPriority.value);
                if (imageDefault) fd.append('image_default_goal', imageDefault.value);

                fetch(AJAX, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        const el = document.getElementById('tcp-settings-status');
                        if (res.success) {
                            el.textContent = '✅ ' + res.data.message;
                            el.style.color = '#46b450';
                        } else {
                            el.textContent = '❌ ' + (res.data || 'Error');
                            el.style.color = '#dc3545';
                        }
                        setTimeout(() => el.textContent = '', 3000);
                    });
            });

            /* ── Suggestion chips: click to fill ── */
            document.querySelectorAll('.tcp-suggest-chip').forEach(chip => {
                chip.addEventListener('click', function() {
                    const targetClass = this.dataset.target;
                    const value = this.dataset.value;
                    const row = this.closest('tr') || this.closest('.tcp-tool-card');
                    if (!row) return;
                    const textarea = row.querySelector('.' + targetClass);
                    if (!textarea) return;

                    // Append (with comma separator if already has content)
                    if (textarea.value.trim()) {
                        textarea.value = textarea.value.trim() + ', ' + value;
                    } else {
                        textarea.value = value;
                    }
                    textarea.focus();

                    // Visual feedback: fade out the clicked chip
                    this.style.opacity = '0.4';
                    this.style.pointerEvents = 'none';
                });
            });

        })();
        </script>
        <?php
    }
}
