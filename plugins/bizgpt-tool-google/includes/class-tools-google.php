<?php
/**
 * Tool callbacks for Intent Engine integration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Tools {

    /**
     * Helper: ensure user has Google connected.
     */
    private static function require_connection( $user_id ) {
        $blog_id = get_current_blog_id();
        if ( ! BZGoogle_Token_Store::has_valid_token( $blog_id, $user_id ) ) {
            $connect_url = BZGoogle_Google_OAuth::get_connect_url( [
                'blog_id'    => $blog_id,
                'return_url' => home_url(),
            ] );
            return [
                'success'  => false,
                'complete' => false,
                'message'  => "Bạn chưa kết nối Google. Vui lòng kết nối tại đây:\n👉 {$connect_url}",
            ];
        }
        return true;
    }

    /**
     * Helper: ensure user has the required service scope.
     * If not, return a message with upgrade URL (incremental authorization).
     *
     * @param int    $user_id
     * @param string $service  gmail, calendar, drive, contacts
     * @return true|array  true if OK, array error response if scope missing
     */
    private static function require_scope( $user_id, $service ) {
        $blog_id = get_current_blog_id();

        // First check basic connection
        $check = self::require_connection( $user_id );
        if ( $check !== true ) return $check;

        // Then check specific service scope
        if ( ! BZGoogle_Google_OAuth::has_scope( $blog_id, $user_id, $service ) ) {
            $labels = [
                'gmail'    => 'Gmail (đọc & gửi email)',
                'calendar' => 'Google Calendar (đọc & tạo sự kiện)',
                'drive'    => 'Google Drive (xem & quản lý file)',
                'contacts' => 'Google Contacts (xem danh bạ)',
                'docs'     => 'Google Docs (tạo & chỉnh sửa tài liệu)',
                'sheets'   => 'Google Sheets (tạo & chỉnh sửa bảng tính)',
                'slides'   => 'Google Slides (tạo & chỉnh sửa bài thuyết trình)',
            ];
            $label       = $labels[ $service ] ?? $service;
            $upgrade_url = BZGoogle_Google_OAuth::get_scope_upgrade_url( $service, home_url() );

            return [
                'success'  => false,
                'complete' => false,
                'message'  => "Bạn cần cấp thêm quyền **{$label}** để sử dụng tính năng này.\n"
                            . "👉 Bấm vào đây để cấp quyền: {$upgrade_url}",
            ];
        }

        return true;
    }

    /* ══════════════════════════════════════════════════════════
     *  GMAIL TOOLS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Tool: gmail_list_messages
     */
    public static function gmail_list_messages( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'gmail' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();
        $result  = BZGoogle_Google_Service::gmail_list( $blog_id, $user_id, [
            'max_results' => $input['max_results'] ?? 10,
            'query'       => $input['query'] ?? '',
        ] );

        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'gmail', 'list', $input['query'] ?? '', is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi đọc email: ' . $result->get_error_message(),
            ];
        }

        if ( empty( $result['messages'] ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => 'Không tìm thấy email nào.',
                'data'    => $result,
            ];
        }

        $lines = [ "📧 **{$result['total_estimate']} email** (hiển thị " . count( $result['messages'] ) . "):\n" ];
        foreach ( $result['messages'] as $i => $msg ) {
            $num = $i + 1;
            $lines[] = "**{$num}.** {$msg['subject']}\n   Từ: {$msg['from']} — {$msg['date']}\n   {$msg['snippet']}\n";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => implode( "\n", $lines ),
            'data'     => $result,
        ];
    }

    /**
     * Tool: gmail_send_message
     */
    public static function gmail_send_message( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'gmail' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();

        if ( empty( $input['to'] ) || empty( $input['subject'] ) || empty( $input['body'] ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Vui lòng cung cấp đầy đủ: người nhận, tiêu đề và nội dung email.',
                'missing_fields' => array_filter( [
                    empty( $input['to'] )      ? 'to'      : null,
                    empty( $input['subject'] ) ? 'subject' : null,
                    empty( $input['body'] )    ? 'body'    : null,
                ] ),
            ];
        }

        $result = BZGoogle_Google_Service::gmail_send( $blog_id, $user_id, $input );
        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'gmail', 'send', $input['to'], is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi gửi email: ' . $result->get_error_message(),
            ];
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã gửi email thành công đến **{$input['to']}**\nTiêu đề: {$input['subject']}",
            'data'     => $result,
        ];
    }

    /**
     * Tool: gmail_summarize_inbox
     */
    public static function gmail_summarize_inbox( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'gmail' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();
        $result  = BZGoogle_Google_Service::gmail_list( $blog_id, $user_id, [
            'max_results' => $input['max_results'] ?? 20,
        ] );

        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'gmail', 'summarize', '', is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi đọc email: ' . $result->get_error_message(),
            ];
        }

        // Build summary for AI compose
        $summary_parts = [];
        foreach ( $result['messages'] ?? [] as $msg ) {
            $summary_parts[] = "- [{$msg['subject']}] từ {$msg['from']}: {$msg['snippet']}";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "Đây là tóm tắt **{$result['total_estimate']}** email mới nhất trong inbox:\n\n"
                        . implode( "\n", $summary_parts ),
            'data'     => $result,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  CALENDAR TOOLS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Tool: calendar_list_events
     */
    public static function calendar_list_events( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'calendar' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();
        $result  = BZGoogle_Google_Service::calendar_list( $blog_id, $user_id, $input );

        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'calendar', 'list', '', is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi đọc lịch: ' . $result->get_error_message(),
            ];
        }

        if ( empty( $result['events'] ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => 'Không có sự kiện nào trong khoảng thời gian này.',
                'data'    => $result,
            ];
        }

        $lines = [ "📅 **Lịch sự kiện** (" . count( $result['events'] ) . "):\n" ];
        foreach ( $result['events'] as $i => $evt ) {
            $num = $i + 1;
            $lines[] = "**{$num}.** {$evt['title']}\n   🕐 {$evt['start']} → {$evt['end']}"
                     . ( $evt['location'] ? "\n   📍 {$evt['location']}" : '' )
                     . "\n";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => implode( "\n", $lines ),
            'data'     => $result,
        ];
    }

    /**
     * Tool: calendar_create_event
     */
    public static function calendar_create_event( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'calendar' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();

        if ( empty( $input['title'] ) || empty( $input['start_time'] ) || empty( $input['end_time'] ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => 'Vui lòng cung cấp: tên sự kiện, thời gian bắt đầu và kết thúc.',
                'missing_fields' => array_filter( [
                    empty( $input['title'] )      ? 'title'      : null,
                    empty( $input['start_time'] ) ? 'start_time' : null,
                    empty( $input['end_time'] )   ? 'end_time'   : null,
                ] ),
            ];
        }

        $result = BZGoogle_Google_Service::calendar_create( $blog_id, $user_id, $input );
        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'calendar', 'create', $input['title'], is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi tạo sự kiện: ' . $result->get_error_message(),
            ];
        }

        $link = $result['htmlLink'] ?? '';
        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo sự kiện **{$input['title']}**\n🕐 {$input['start_time']} → {$input['end_time']}"
                        . ( $link ? "\n🔗 {$link}" : '' ),
            'data'     => $result,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  DRIVE TOOLS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Tool: drive_list_files
     */
    public static function drive_list_files( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'drive' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();
        $result  = BZGoogle_Google_Service::drive_list( $blog_id, $user_id, $input );

        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'drive', 'list', $input['query'] ?? '', is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi đọc Drive: ' . $result->get_error_message(),
            ];
        }

        if ( empty( $result['files'] ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => 'Không tìm thấy file nào trong Google Drive.',
                'data'    => $result,
            ];
        }

        $lines = [ "📁 **Google Drive** (" . count( $result['files'] ) . " file):\n" ];
        foreach ( $result['files'] as $i => $f ) {
            $num  = $i + 1;
            $size = $f['size'] ? self::human_size( $f['size'] ) : '';
            $lines[] = "**{$num}.** {$f['name']}"
                     . ( $size ? " ({$size})" : '' )
                     . "\n   📎 {$f['mime_type']}"
                     . ( $f['web_link'] ? " — [Xem]({$f['web_link']})" : '' )
                     . "\n";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => implode( "\n", $lines ),
            'data'     => $result,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     *  CONTACTS TOOLS
     * ══════════════════════════════════════════════════════════ */

    /**
     * Tool: contacts_list
     */
    public static function contacts_list( $slots ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $input   = $slots;
        $check   = self::require_scope( $user_id, 'contacts' );
        if ( $check !== true ) return $check;

        $blog_id = get_current_blog_id();
        $result  = BZGoogle_Google_Service::contacts_list( $blog_id, $user_id, $input );

        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'contacts', 'list', $input['query'] ?? '', is_wp_error( $result ) ? 'error' : 'success' );

        if ( is_wp_error( $result ) ) {
            return [
                'success' => false, 'complete' => true,
                'message' => 'Lỗi đọc danh bạ: ' . $result->get_error_message(),
            ];
        }

        if ( empty( $result['contacts'] ) ) {
            return [
                'success' => true, 'complete' => true,
                'message' => 'Không tìm thấy liên hệ nào.',
                'data'    => $result,
            ];
        }

        $lines = [ "👥 **Danh bạ Google** (" . count( $result['contacts'] ) . "):\n" ];
        foreach ( $result['contacts'] as $i => $c ) {
            $num = $i + 1;
            $parts = [ "**{$num}.** {$c['name']}" ];
            if ( $c['email'] )   $parts[] = "   📧 {$c['email']}";
            if ( $c['phone'] )   $parts[] = "   📱 {$c['phone']}";
            if ( $c['company'] ) $parts[] = "   🏢 {$c['company']}";
            $lines[] = implode( "\n", $parts ) . "\n";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => implode( "\n", $lines ),
            'data'     => $result,
        ];
    }

    /* ── Helpers ───────────────────────────────────────────── */

    private static function human_size( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes < 1024 ) return $bytes . ' B';
        if ( $bytes < 1048576 ) return round( $bytes / 1024, 1 ) . ' KB';
        if ( $bytes < 1073741824 ) return round( $bytes / 1048576, 1 ) . ' MB';
        return round( $bytes / 1073741824, 1 ) . ' GB';
    }
}
