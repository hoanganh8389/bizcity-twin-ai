<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BizCity Tool — Sample: Tool Callbacks
 *
 * Mỗi public static method = 1 tool callback.
 * Input: $slots array (từ Engine).
 * Output: envelope { success, complete, message, data? }
 */
class BizCity_Tool_Sample {

    /**
     * PRIMARY TOOL — create_sample
     *
     * @param array $slots {
     *     topic:     string  (required) — Mô tả sample
     *     image_url: string  (optional) — URL ảnh
     *     _meta:     array   (auto)     — Engine context
     * }
     * @return array Output envelope
     */
    public static function create_sample( array $slots ) {
        $topic     = sanitize_text_field( $slots['topic'] ?? '' );
        $image_url = esc_url_raw( $slots['image_url'] ?? '' );
        $meta      = $slots['_meta'] ?? [];

        if ( empty( $topic ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Vui lòng mô tả sample cần tạo.',
            ];
        }

        // ── Business Logic ──
        // TODO: Replace with actual implementation
        // Ví dụ: gọi AI, tạo post, tạo resource, v.v.
        $result_id  = 0;    // ID resource đã tạo
        $result_url = '';    // URL kết quả

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã tạo sample thành công!\n📋 Chủ đề: {$topic}",
            'data'     => [
                'type'      => 'data',       // article | image | video | product | data
                'id'        => $result_id,
                'title'     => $topic,
                'url'       => $result_url,
                'image_url' => $image_url,
            ],
        ];
    }

    /**
     * SECONDARY TOOL — edit_sample
     *
     * @param array $slots {
     *     topic: string (required) — Yêu cầu chỉnh sửa
     *     _meta: array  (auto)     — Engine context
     * }
     * @return array Output envelope
     */
    public static function edit_sample( array $slots ) {
        $topic = sanitize_text_field( $slots['topic'] ?? '' );
        $meta  = $slots['_meta'] ?? [];

        if ( empty( $topic ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❌ Vui lòng mô tả sample cần sửa.',
            ];
        }

        // ── Business Logic ──
        // TODO: Replace with actual implementation

        return [
            'success'  => true,
            'complete' => true,
            'message'  => "✅ Đã cập nhật sample!\n📋 {$topic}",
            'data'     => [
                'type' => 'data',
                'id'   => 0,
            ],
        ];
    }
}
