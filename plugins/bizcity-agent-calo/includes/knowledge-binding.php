<?php
/**
 * Knowledge Binding — Liên kết plugin với bizcity-knowledge.
 *
 * @package BizCity_Calo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Lấy character_id liên kết với plugin này ─── */
function bzcalo_get_knowledge_character_id() {
    return (int) get_option( 'bzcalo_knowledge_character_id', 0 );
}

/* ─── Lấy knowledge context từ bizcity-knowledge ─── */
function bzcalo_get_knowledge_context( $query, $max_tokens = 2000 ) {
    $char_id = bzcalo_get_knowledge_character_id();
    if ( ! $char_id || ! class_exists( 'BizCity_Knowledge_Context_API' ) ) {
        return '';
    }

    $result = BizCity_Knowledge_Context_API::instance()->build_context(
        $char_id, $query, array( 'max_tokens' => $max_tokens )
    );

    return ! empty( $result['context'] ) ? $result['context'] : '';
}

/* ─── Admin Settings: Chọn character liên kết ─── */
function bzcalo_knowledge_settings_section() {
    $char_id    = bzcalo_get_knowledge_character_id();
    $characters = bzcalo_get_available_characters();
    ?>
    <h3>📚 Đào tạo kiến thức</h3>
    <table class="form-table">
        <tr>
            <th>Character liên kết</th>
            <td>
                <select name="bzcalo_knowledge_character_id">
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
                    Character chứa kiến thức dinh dưỡng chuyên môn (FAQ, files, web crawl) riêng cho Calo Tracker.
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

        $stats = bzcalo_get_knowledge_stats( $char_id );
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
function bzcalo_get_available_characters() {
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_characters';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        return array();
    }
    return $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name" );
}

/* ─── Helper: Lấy thống kê knowledge ─── */
function bzcalo_get_knowledge_stats( $character_id ) {
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
    if ( ! isset( $_POST['bzcalo_knowledge_character_id'] ) ) return;
    if ( ! current_user_can( 'manage_options' ) ) return;
    update_option( 'bzcalo_knowledge_character_id', (int) $_POST['bzcalo_knowledge_character_id'] );
} );

/* ─── Auto-sync: khi food data thay đổi → re-index knowledge chunks ─── */
add_action( 'bzcalo_food_saved', function( $food_id, $food_data ) {
    $char_id = bzcalo_get_knowledge_character_id();
    if ( ! $char_id || ! function_exists( 'bizcity_knowledge_sync_plugin_data' ) ) return;

    bizcity_knowledge_sync_plugin_data( $char_id, 'bzcalo', $food_id, array(
        'title'   => isset( $food_data['name_vi'] ) ? $food_data['name_vi'] : '',
        'content' => sprintf(
            '%s (%s) — %s kcal, P: %sg, C: %sg, F: %sg',
            $food_data['name_vi'] ?? '', $food_data['serving_size'] ?? '',
            $food_data['calories'] ?? 0, $food_data['protein_g'] ?? 0,
            $food_data['carbs_g'] ?? 0, $food_data['fat_g'] ?? 0
        ),
    ) );
}, 10, 2 );
