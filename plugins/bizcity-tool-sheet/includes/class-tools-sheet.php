<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Sheet {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );

        $actions = [ 'create', 'list', 'get', 'save', 'delete' ];
        foreach ( $actions as $action ) {
            add_action( "wp_ajax_bztool_sheet_{$action}", [ __CLASS__, "ajax_{$action}" ] );
        }
    }

    public static function register_post_type(): void {
        register_post_type( 'bz_sheet', [
            'labels' => [
                'name'          => 'Sheets',
                'singular_name' => 'Sheet',
            ],
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title', 'editor', 'author' ],
            'capability_type' => 'post',
        ] );
    }

    public static function create_sheet_from_prompt( array $slots ): array {
        $topic         = trim( (string) ( $slots['topic'] ?? self::extract_text( $slots ) ) );
        $sheet_purpose = sanitize_key( (string) ( $slots['sheet_purpose'] ?? 'auto' ) );
        $rows_estimate = max( 3, min( 60, intval( $slots['rows_estimate'] ?? 12 ) ) );
        $meta          = is_array( $slots['_meta'] ?? null ) ? $slots['_meta'] : [];
        $session_id    = sanitize_text_field( (string) ( $slots['session_id'] ?? '' ) );
        $chat_id       = sanitize_text_field( (string) ( $slots['chat_id'] ?? '' ) );
        $user_id       = get_current_user_id() ?: 1;

        if ( $topic === '' ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Can mo ta muc dich workbook truoc khi tao.',
                'data'           => [],
                'missing_fields' => [ 'topic' ],
            ];
        }

        $blueprint = self::infer_blueprint( $topic, $sheet_purpose, $rows_estimate );
        $workbook  = self::build_workbook( $blueprint );
        $title     = $blueprint['title'];

        $post_id = wp_insert_post( [
            'post_type'    => 'bz_sheet',
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => sanitize_textarea_field( $topic ),
            'post_status'  => 'publish',
            'post_author'  => $user_id,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => 'Khong the luu workbook vao database.',
                'data'     => [],
            ];
        }

        $json = wp_json_encode( $workbook, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        update_post_meta( $post_id, '_bz_sheet_workbook', $json );
        update_post_meta( $post_id, '_bz_sheet_blueprint', $blueprint );
        update_post_meta( $post_id, '_bz_sheet_purpose', $blueprint['purpose'] );
        update_post_meta( $post_id, '_bz_sheet_rows_estimate', $blueprint['rows_estimate'] );
        update_post_meta( $post_id, '_bz_sheet_session_id', $session_id );
        update_post_meta( $post_id, '_bz_sheet_chat_id', $chat_id );
        update_post_meta( $post_id, '_bz_sheet_context', sanitize_textarea_field( (string) ( $meta['_context'] ?? '' ) ) );

        $sheet_url = home_url( '/tool-sheet/?id=' . $post_id );

        return [
            'success'  => true,
            'complete' => true,
            'message'  => 'Da tao workbook ' . $title . '. Mo Sheet Studio de chinh sua va export.',
            'data'     => [
                'workbook_id'   => $post_id,
                'id'            => $post_id,
                'type'          => 'sheet',
                'title'         => $title,
                'workbook_json' => $json,
                'sheet_url'     => $sheet_url,
                'url'           => $sheet_url,
                'sheet_purpose' => $blueprint['purpose'],
                'headers'       => $blueprint['headers'],
                'sheet_name'    => $workbook['sheets'][0]['name'] ?? 'Sheet1',
            ],
        ];
    }

    public static function analyze_sheet_data( array $slots ): array {
        $payload       = (string) ( $slots['sheet_data'] ?? '' );
        $analysis_goal = sanitize_key( (string) ( $slots['analysis_goal'] ?? 'auto' ) );

        if ( $payload === '' && ! empty( $slots['workbook_id'] ) ) {
            $payload = self::load_workbook_json( intval( $slots['workbook_id'] ) );
        }

        if ( $payload === '' ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Can du lieu bang tinh, CSV, JSON hoac workbook_id de phan tich.',
                'data'           => [],
                'missing_fields' => [ 'sheet_data' ],
            ];
        }

        $table = self::parse_table_payload( $payload );
        if ( empty( $table['rows'] ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => 'Khong doc duoc du lieu bang tinh tu payload da gui.',
                'data'     => [],
            ];
        }

        $rows           = $table['rows'];
        $headers        = $rows[0] ?? [];
        $data_rows      = array_slice( $rows, 1 );
        $column_count   = count( $headers );
        $numeric_cols   = [];
        $fill_rate      = 0;
        $filled_cells   = 0;
        $total_cells    = max( 1, max( count( $data_rows ), 1 ) * max( $column_count, 1 ) );
        $column_metrics = [];

        for ( $column = 0; $column < $column_count; $column++ ) {
            $numeric_hits = 0;
            $non_empty    = 0;
            foreach ( $data_rows as $row ) {
                $value = isset( $row[ $column ] ) ? trim( (string) $row[ $column ] ) : '';
                if ( $value === '' ) {
                    continue;
                }
                $non_empty++;
                $filled_cells++;
                if ( is_numeric( str_replace( ',', '', $value ) ) ) {
                    $numeric_hits++;
                }
            }

            $column_metrics[] = [
                'header'      => $headers[ $column ] ?? 'Column ' . ( $column + 1 ),
                'non_empty'   => $non_empty,
                'numeric_hits'=> $numeric_hits,
            ];

            if ( $non_empty > 0 && $numeric_hits >= max( 1, floor( $non_empty * 0.6 ) ) ) {
                $numeric_cols[] = $headers[ $column ] ?? 'Column ' . ( $column + 1 );
            }
        }

        $fill_rate = round( ( $filled_cells / $total_cells ) * 100, 1 );

        $insights = [
            'Bang co ' . count( $data_rows ) . ' dong du lieu va ' . $column_count . ' cot.',
            'Ti le o co du lieu xap xi ' . $fill_rate . '%.',
        ];

        if ( ! empty( $numeric_cols ) ) {
            $insights[] = 'Cot nghi la chi so so lieu: ' . implode( ', ', $numeric_cols ) . '.';
        } else {
            $insights[] = 'Chua thay cot so lieu ro rang, co the du lieu dang text hoac can lam sach them.';
        }

        if ( $analysis_goal === 'dashboard' ) {
            $insights[] = 'Phu hop de tao dashboard neu chon 1 cot nhom, 1 cot thoi gian va 1-2 cot so lieu.';
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => 'Da phan tich workbook payload.',
            'data'     => [
                'row_count'       => count( $data_rows ),
                'column_count'    => $column_count,
                'headers'         => $headers,
                'numeric_columns' => $numeric_cols,
                'column_metrics'  => $column_metrics,
                'insights'        => $insights,
                'analysis_goal'   => $analysis_goal,
            ],
        ];
    }

    public static function fill_formula_range( array $slots ): array {
        $formula_goal = trim( (string) ( $slots['formula_goal'] ?? '' ) );
        $target_range = strtoupper( trim( (string) ( $slots['target_range'] ?? '' ) ) );
        $sheet_name   = trim( (string) ( $slots['sheet_name'] ?? 'Sheet1' ) );
        $workbook_id  = intval( $slots['workbook_id'] ?? 0 );

        if ( $formula_goal === '' || $target_range === '' ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Can mo ta cong thuc va vung dich de tao formula patch.',
                'data'           => [],
                'missing_fields' => [ 'formula_goal', 'target_range' ],
            ];
        }

        $formula = self::guess_formula( $formula_goal, $target_range );
        $patch_id = 'patch_' . wp_generate_password( 8, false, false );

        if ( $workbook_id > 0 ) {
            update_post_meta( $workbook_id, '_bz_sheet_last_patch', [
                'patch_id'     => $patch_id,
                'formula_goal' => $formula_goal,
                'formula'      => $formula,
                'target_range' => $target_range,
                'sheet_name'   => $sheet_name,
                'created_at'   => current_time( 'mysql' ),
            ] );
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => 'Da tao formula patch cho ' . $target_range . '.',
            'data'     => [
                'formula'      => $formula,
                'target_range' => $target_range,
                'sheet_name'   => $sheet_name,
                'patch_id'     => $patch_id,
                'workbook_id'  => $workbook_id,
            ],
        ];
    }

    public static function export_sheet_file( array $slots ): array {
        $workbook_id   = intval( $slots['workbook_id'] ?? 0 );
        $export_format = sanitize_key( (string) ( $slots['export_format'] ?? 'json' ) );
        $workbook_json = '';

        if ( $workbook_id > 0 ) {
            $workbook_json = self::load_workbook_json( $workbook_id );
        } elseif ( ! empty( $slots['workbook_json'] ) ) {
            $workbook_json = (string) $slots['workbook_json'];
        }

        if ( $workbook_json === '' ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Can workbook_id hoac workbook_json de export.',
                'data'           => [],
                'missing_fields' => [ 'workbook_id' ],
            ];
        }

        $file_name = 'workbook-' . ( $workbook_id ?: gmdate( 'YmdHis' ) ) . '.' . $export_format;
        $payload   = '';

        if ( $export_format === 'json' ) {
            $payload = $workbook_json;
        } elseif ( $export_format === 'csv' ) {
            $payload = self::workbook_to_csv( $workbook_json );
        } else {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => 'MVP hien chi export JSON va CSV. XLSX/PDF duoc giu cho phase 2 frontend engine.',
                'data'     => [
                    'workbook_id'   => $workbook_id,
                    'export_format' => $export_format,
                ],
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => 'Da tao payload export dang ' . strtoupper( $export_format ) . '.',
            'data'     => [
                'workbook_id'   => $workbook_id,
                'export_format' => $export_format,
                'file_name'     => $file_name,
                'payload'       => $payload,
            ],
        ];
    }

    public static function ajax_create(): void {
        self::ensure_logged_in();
        check_ajax_referer( 'bztool_sheet', 'nonce' );

        $result = self::create_sheet_from_prompt( [
            'topic'         => sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) ),
            'sheet_purpose' => sanitize_key( wp_unslash( $_POST['sheet_purpose'] ?? 'auto' ) ),
            'rows_estimate' => intval( $_POST['rows_estimate'] ?? 12 ),
            '_meta'         => [ 'channel' => 'profile_direct' ],
        ] );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result['data'] + [ 'message' => $result['message'] ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ?? 'Khong tao duoc workbook.' ] );
    }

    public static function ajax_list(): void {
        self::ensure_logged_in();
        check_ajax_referer( 'bztool_sheet', 'nonce' );

        $query = new WP_Query( [
            'post_type'      => 'bz_sheet',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'author'         => get_current_user_id(),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $items = [];
        foreach ( $query->posts as $post ) {
            $blueprint = get_post_meta( $post->ID, '_bz_sheet_blueprint', true );
            $items[] = [
                'id'            => $post->ID,
                'title'         => $post->post_title,
                'prompt'        => $post->post_content,
                'sheet_purpose' => is_array( $blueprint ) ? ( $blueprint['purpose'] ?? 'custom' ) : 'custom',
                'headers'       => is_array( $blueprint ) ? ( $blueprint['headers'] ?? [] ) : [],
                'updated_at'    => get_the_modified_date( 'Y-m-d H:i', $post ),
            ];
        }

        wp_send_json_success( [ 'items' => $items ] );
    }

    public static function ajax_get(): void {
        self::ensure_logged_in();
        check_ajax_referer( 'bztool_sheet', 'nonce' );

        $post_id = intval( $_GET['id'] ?? 0 );
        $post    = self::get_accessible_workbook( $post_id );

        wp_send_json_success( [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'prompt'        => $post->post_content,
            'workbook_json' => self::load_workbook_json( $post->ID ),
            'blueprint'     => get_post_meta( $post->ID, '_bz_sheet_blueprint', true ),
            'last_patch'    => get_post_meta( $post->ID, '_bz_sheet_last_patch', true ),
        ] );
    }

    public static function ajax_save(): void {
        self::ensure_logged_in();
        check_ajax_referer( 'bztool_sheet', 'nonce' );

        $post_id       = intval( $_POST['id'] ?? 0 );
        $title         = sanitize_text_field( wp_unslash( $_POST['title'] ?? 'Workbook' ) );
        $workbook_json = wp_unslash( $_POST['workbook_json'] ?? '' );

        if ( $post_id <= 0 || $workbook_json === '' ) {
            wp_send_json_error( [ 'message' => 'Can id va workbook_json de luu.' ] );
        }

        self::get_accessible_workbook( $post_id );

        $normalized = self::normalize_workbook_json( $workbook_json );
        if ( $normalized === '' ) {
            wp_send_json_error( [ 'message' => 'Workbook JSON khong hop le.' ] );
        }

        wp_update_post( [
            'ID'         => $post_id,
            'post_title' => $title,
        ] );

        update_post_meta( $post_id, '_bz_sheet_workbook', wp_slash( $normalized ) );

        wp_send_json_success( [ 'message' => 'Da luu workbook.' ] );
    }

    public static function ajax_delete(): void {
        self::ensure_logged_in();
        check_ajax_referer( 'bztool_sheet', 'nonce' );

        $post_id = intval( $_POST['id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'ID workbook khong hop le.' ] );
        }

        self::get_accessible_workbook( $post_id );

        wp_delete_post( $post_id, true );
        wp_send_json_success( [ 'message' => 'Da xoa workbook.' ] );
    }

    private static function ensure_logged_in(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Can dang nhap de thao tac voi Sheet Studio.' ], 403 );
        }
    }

    private static function get_accessible_workbook( int $post_id ): WP_Post {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'bz_sheet' ) {
            wp_send_json_error( [ 'message' => 'Khong tim thay workbook.' ], 404 );
        }

        $user_id = get_current_user_id();
        if ( ! current_user_can( 'manage_options' ) && intval( $post->post_author ) !== $user_id ) {
            wp_send_json_error( [ 'message' => 'Ban khong co quyen truy cap workbook nay.' ], 403 );
        }

        return $post;
    }

    private static function normalize_workbook_json( string $workbook_json ): string {
        $decoded = json_decode( $workbook_json, true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }

        if ( empty( $decoded['sheets'] ) || ! is_array( $decoded['sheets'] ) ) {
            return '';
        }

        return (string) wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    private static function infer_blueprint( string $topic, string $purpose, int $rows_estimate ): array {
        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $topic ) : strtolower( $topic );

        if ( $purpose === 'auto' || $purpose === '' ) {
            if ( strpos( $lower, 'ngan sach' ) !== false || strpos( $lower, 'budget' ) !== false ) {
                $purpose = 'budget';
            } elseif ( strpos( $lower, 'kpi' ) !== false || strpos( $lower, 'dashboard' ) !== false ) {
                $purpose = 'dashboard';
            } elseif ( strpos( $lower, 'cham cong' ) !== false || strpos( $lower, 'roster' ) !== false || strpos( $lower, 'ca lam' ) !== false ) {
                $purpose = 'roster';
            } elseif ( strpos( $lower, 'ton kho' ) !== false || strpos( $lower, 'inventory' ) !== false ) {
                $purpose = 'inventory';
            } elseif ( strpos( $lower, 'tai chinh' ) !== false || strpos( $lower, 'doanh thu' ) !== false || strpos( $lower, 'finance' ) !== false ) {
                $purpose = 'finance';
            } else {
                $purpose = 'tracker';
            }
        }

        $map = [
            'budget' => [
                'title'   => 'Bang ngan sach',
                'headers' => [ 'Hang muc', 'Thang', 'Ngan sach', 'Thuc chi', 'Chenhlech', 'Ghi chu' ],
            ],
            'dashboard' => [
                'title'   => 'KPI dashboard',
                'headers' => [ 'Chi so', 'Muc tieu', 'Thuc te', 'Ty le dat', 'Chu so huu', 'Ky bao cao' ],
            ],
            'tracker' => [
                'title'   => 'Bang theo doi cong viec',
                'headers' => [ 'Cong viec', 'Nguoi phu trach', 'Trang thai', 'Bat dau', 'Deadline', 'Ghi chu' ],
            ],
            'roster' => [
                'title'   => 'Bang cham cong',
                'headers' => [ 'Nhan vien', 'Ngay', 'Ca', 'Gio vao', 'Gio ra', 'Tong gio' ],
            ],
            'inventory' => [
                'title'   => 'Bang ton kho',
                'headers' => [ 'Ma hang', 'Ten hang', 'Nhap', 'Xuat', 'Ton', 'Gia tri ton' ],
            ],
            'finance' => [
                'title'   => 'Bao cao tai chinh',
                'headers' => [ 'Khoan muc', 'Ky truoc', 'Ky nay', 'Chenhlech', 'Ty le', 'Ghi chu' ],
            ],
            'custom' => [
                'title'   => 'Workbook tuy chinh',
                'headers' => [ 'Cot 1', 'Cot 2', 'Cot 3', 'Cot 4', 'Cot 5' ],
            ],
        ];

        $chosen = $map[ $purpose ] ?? $map['custom'];

        return [
            'title'         => $chosen['title'] . ' - ' . wp_trim_words( $topic, 6, '' ),
            'purpose'       => $purpose,
            'headers'       => $chosen['headers'],
            'rows_estimate' => $rows_estimate,
            'prompt'        => $topic,
        ];
    }

    private static function build_workbook( array $blueprint ): array {
        $headers = $blueprint['headers'] ?? [ 'Cot 1', 'Cot 2' ];
        $rows    = [];
        $rows[]  = $headers;

        for ( $index = 1; $index <= intval( $blueprint['rows_estimate'] ?? 12 ); $index++ ) {
            $row = [];
            foreach ( $headers as $column => $header ) {
                if ( $column === 0 ) {
                    $row[] = $header . ' ' . $index;
                } elseif ( in_array( $header, [ 'Thang', 'Ky bao cao', 'Ngay' ], true ) ) {
                    $row[] = 'M' . $index;
                } elseif ( in_array( $header, [ 'Ngan sach', 'Thuc chi', 'Muc tieu', 'Thuc te', 'Nhap', 'Xuat', 'Ton', 'Gia tri ton', 'Ky truoc', 'Ky nay', 'Chenhlech' ], true ) ) {
                    $row[] = (string) ( $index * 1000 );
                } else {
                    $row[] = '';
                }
            }
            $rows[] = $row;
        }

        return [
            'version' => 1,
            'engine'  => 'sheet-studio-mvp',
            'sheets'  => [
                [
                    'name'   => 'Sheet1',
                    'rows'   => $rows,
                    'freeze' => [ 'row' => 1, 'col' => 1 ],
                ],
            ],
            'meta'    => [
                'purpose' => $blueprint['purpose'] ?? 'custom',
                'title'   => $blueprint['title'] ?? 'Workbook',
            ],
        ];
    }

    private static function parse_table_payload( string $payload ): array {
        $decoded = json_decode( $payload, true );
        if ( is_array( $decoded ) ) {
            if ( isset( $decoded['sheets'][0]['rows'] ) && is_array( $decoded['sheets'][0]['rows'] ) ) {
                return [ 'rows' => $decoded['sheets'][0]['rows'] ];
            }
            if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
                return [ 'rows' => $decoded ];
            }
        }

        $lines = preg_split( '/\r\n|\r|\n/', trim( $payload ) );
        $rows  = [];
        foreach ( $lines as $line ) {
            if ( trim( $line ) === '' ) continue;
            $rows[] = str_getcsv( $line );
        }

        return [ 'rows' => $rows ];
    }

    private static function workbook_to_csv( string $workbook_json ): string {
        $decoded = json_decode( $workbook_json, true );
        $rows    = $decoded['sheets'][0]['rows'] ?? [];
        $handle  = fopen( 'php://temp', 'r+' );
        foreach ( $rows as $row ) {
            fputcsv( $handle, $row );
        }
        rewind( $handle );
        $csv = stream_get_contents( $handle );
        fclose( $handle );
        return (string) $csv;
    }

    private static function load_workbook_json( int $post_id ): string {
        return (string) get_post_meta( $post_id, '_bz_sheet_workbook', true );
    }

    private static function guess_formula( string $goal, string $target_range ): string {
        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $goal ) : strtolower( $goal );
        $source = self::infer_source_column_from_range( $target_range );

        if ( strpos( $lower, 'tong' ) !== false || strpos( $lower, 'sum' ) !== false ) {
            return '=SUM(' . $source . ')';
        }
        if ( strpos( $lower, 'average' ) !== false || strpos( $lower, 'trung binh' ) !== false ) {
            return '=AVERAGE(' . $source . ')';
        }
        if ( strpos( $lower, 'margin' ) !== false || strpos( $lower, '%' ) !== false || strpos( $lower, 'ty le' ) !== false ) {
            return '=IFERROR((C2-B2)/C2,0)';
        }
        if ( strpos( $lower, 'count' ) !== false || strpos( $lower, 'dem' ) !== false ) {
            return '=COUNTA(' . $source . ')';
        }
        return '=SUM(' . $source . ')';
    }

    private static function infer_source_column_from_range( string $target_range ): string {
        if ( preg_match( '/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $target_range, $matches ) ) {
            $first = $matches[1];
            $start = $matches[2];
            $end   = $matches[4];
            $prev  = self::column_prev( $first );
            return $prev . $start . ':' . $prev . $end;
        }
        return 'A2:A100';
    }

    private static function column_prev( string $column ): string {
        if ( $column === 'A' ) return 'A';
        $index = self::column_to_index( $column );
        return self::index_to_column( max( 1, $index - 1 ) );
    }

    private static function column_to_index( string $column ): int {
        $column = strtoupper( $column );
        $index  = 0;
        for ( $i = 0; $i < strlen( $column ); $i++ ) {
            $index = $index * 26 + ( ord( $column[ $i ] ) - 64 );
        }
        return $index;
    }

    private static function index_to_column( int $index ): string {
        $column = '';
        while ( $index > 0 ) {
            $index--;
            $column = chr( 65 + ( $index % 26 ) ) . $column;
            $index  = intdiv( $index, 26 );
        }
        return $column ?: 'A';
    }

    private static function extract_text( array $slots ): string {
        foreach ( [ 'message', 'prompt', 'description', 'topic' ] as $key ) {
            if ( ! empty( $slots[ $key ] ) ) return trim( (string) $slots[ $key ] );
        }
        return '';
    }
}
