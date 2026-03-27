<?php
/**
 * Knowledge Binding — Liên kết plugin với bizcity-knowledge.
 *
 * Mỗi Plugin Agent có lớp tri thức riêng, liên kết với bizcity-knowledge
 * qua Character. File này cung cấp:
 *   - Helper lấy character_id liên kết
 *   - Helper lấy knowledge context (RAG)
 *   - Admin Settings section "Đào tạo kiến thức"
 *   - Auto-sync khi plugin data thay đổi
 *
 * @package BizCity_{Name}
 * @see     ARCHITECTURE.md § 7 — Knowledge ↔ Plugin Agent
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Lấy character_id liên kết với plugin này ─── */
function bz{prefix}_get_knowledge_character_id() {
    return (int) get_option( 'bz{prefix}_knowledge_character_id', 0 );
}

/* ─── Lấy knowledge context từ bizcity-knowledge ─── */
function bz{prefix}_get_knowledge_context( $query, $max_tokens = 2000 ) {
    $char_id = bz{prefix}_get_knowledge_character_id();
    if ( ! $char_id || ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
        return '';
    }

    $result = BizCity_Knowledge_Context_API::instance()->build_context(
        $char_id, $query, array( 'max_tokens' => $max_tokens )
    );

    return ! empty( $result['context'] ) ? $result['context'] : '';
}

/* ─── Admin Settings: Chọn character liên kết ─── */
function bz{prefix}_knowledge_settings_section() {
    $char_id    = bz{prefix}_get_knowledge_character_id();
    $characters = bz{prefix}_get_available_characters();
    ?>
    <h3>📚 Đào tạo kiến thức</h3>
    <table class="form-table">
        <tr>
            <th>Character liên kết</th>
            <td>
                <select name="bz{prefix}_knowledge_character_id">
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
                    Character chứa kiến thức chuyên môn (FAQ, files, web crawl) riêng cho plugin này.
                    Kiến thức sẽ tự động được inject vào AI khi Intent Engine route đến plugin.
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

        // Show stats
        $stats = bz{prefix}_get_knowledge_stats( $char_id );
        if ( $stats ) {
            echo '<span style="margin-left:12px;color:#666;">';
            echo '📊 ' . esc_html( $stats['sources'] ) . ' nguồn • ';
            echo esc_html( $stats['chunks'] ) . ' chunks';
            echo '</span>';
        }
        echo '</p>';
    }
}

/* ─── Helper: Lấy danh sách characters từ bizcity-knowledge ─── */
function bz{prefix}_get_available_characters() {
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_characters';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return array();
    }
    return $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name" );
}

/* ─── Helper: Lấy thống kê knowledge ─── */
function bz{prefix}_get_knowledge_stats( $character_id ) {
    global $wpdb;
    $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
    $chunks_table  = $wpdb->prefix . 'bizcity_knowledge_chunks';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sources_table}'" ) !== $sources_table ) {
        return null;
    }

    $sources = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$sources_table} WHERE character_id = %d",
        $character_id
    ) );

    $chunks = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$chunks_table} WHERE character_id = %d",
        $character_id
    ) );

    return array( 'sources' => $sources, 'chunks' => $chunks );
}

/* ─── Save settings ─── */
add_action( 'admin_init', function() {
    if ( ! isset( $_POST['bz{prefix}_knowledge_character_id'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // Nonce verified in the main settings save handler
    update_option(
        'bz{prefix}_knowledge_character_id',
        (int) $_POST['bz{prefix}_knowledge_character_id']
    );
} );

/* ─── Auto-sync: khi plugin data thay đổi → re-index knowledge chunks ─── */
add_action( 'bz{prefix}_item_saved', function( $item_id, $item_data ) {
    $char_id = bz{prefix}_get_knowledge_character_id();
    if ( ! $char_id ) {
        return;
    }
    if ( ! function_exists( 'bizcity_knowledge_sync_plugin_data' ) ) {
        return;
    }

    bizcity_knowledge_sync_plugin_data( $char_id, 'bz{prefix}', $item_id, array(
        'title'   => isset( $item_data['name_vi'] ) ? $item_data['name_vi'] : '',
        'content' => ( isset( $item_data['description'] ) ? $item_data['description'] : '' )
                   . "\n"
                   . ( isset( $item_data['data_json'] ) ? $item_data['data_json'] : '' ),
    ) );
}, 10, 2 );
