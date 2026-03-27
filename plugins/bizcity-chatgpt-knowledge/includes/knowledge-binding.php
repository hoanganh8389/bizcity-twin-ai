<?php
/**
 * Knowledge Binding — Liên kết plugin với bizcity-knowledge.
 *
 * @package BizCity_ChatGPT_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Lấy character_id liên kết với plugin này ─── */
function bzck_get_knowledge_character_id() {
    return (int) get_option( 'bzck_knowledge_character_id', 0 );
}

/* ─── Lấy knowledge context từ bizcity-knowledge ─── */
function bzck_get_knowledge_context( $query, $max_tokens = 4000 ) {
    $char_id = bzck_get_knowledge_character_id();
    if ( ! $char_id || ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
        return '';
    }

    $result = BizCity_Knowledge_Context_API::instance()->build_context(
        $char_id, $query, [ 'max_tokens' => $max_tokens ]
    );

    return ! empty( $result['context'] ) ? $result['context'] : '';
}

/* ─── Admin Settings: Chọn character liên kết ─── */
function bzck_knowledge_settings_section() {
    $char_id    = bzck_get_knowledge_character_id();
    $characters = bzck_get_available_characters();
    ?>
    <h3>📚 Đào tạo kiến thức cho ChatGPT Knowledge</h3>
    <table class="form-table">
        <tr>
            <th>Character liên kết</th>
            <td>
                <select name="bzck_knowledge_character_id">
                    <option value="0">-- Chưa liên kết --</option>
                    <?php foreach ( $characters as $c ) : ?>
                        <option value="<?php echo esc_attr( $c->id ); ?>"
                            <?php selected( $char_id, $c->id ); ?>>
                            <?php echo esc_html( $c->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-knowledge-new' ) ); ?>" target="_blank">
                    🔗 Tạo Character mới
                </a>
                <p class="description">
                    Character chứa kiến thức RAG (FAQ, files, web crawl) cho ChatGPT Knowledge.
                </p>
            </td>
        </tr>
    </table>
    <?php
    if ( $char_id ) {
        $knowledge_url = admin_url( 'admin.php?page=bizcity-knowledge-edit&character_id=' . $char_id );
        echo '<p>';
        echo '<a href="' . esc_url( $knowledge_url ) . '" class="button" target="_blank">';
        echo '📝 Quản lý kiến thức (FAQ, Upload file, Crawl web)';
        echo '</a>';

        $stats = bzck_get_knowledge_stats( $char_id );
        if ( $stats ) {
            echo '<span style="margin-left:12px;color:#666;">';
            echo '📊 ' . esc_html( $stats['sources'] ) . ' nguồn • ';
            echo esc_html( $stats['chunks'] ) . ' chunks';
            echo '</span>';
        }
        echo '</p>';
    }
}

/* ─── Helper: Lấy danh sách characters ─── */
function bzck_get_available_characters() {
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_characters';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return [];
    }
    return $wpdb->get_results(
        "SELECT id, name FROM {$table} WHERE status = 'active' ORDER BY name ASC"
    );
}

/* ─── Helper: Lấy stats của character ─── */
function bzck_get_knowledge_stats( $character_id ) {
    global $wpdb;
    $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
    $chunks_table  = $wpdb->prefix . 'bizcity_knowledge_chunks';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sources_table}'" ) !== $sources_table ) {
        return null;
    }

    $sources = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$sources_table} WHERE character_id = %d", $character_id
    ) );
    $chunks = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$chunks_table} WHERE character_id = %d", $character_id
    ) );

    return [ 'sources' => $sources, 'chunks' => $chunks ];
}
