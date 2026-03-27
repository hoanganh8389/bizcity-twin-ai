<?php
/**
 * Topics & data registry — static data for frontend, intent matching, AI prompt.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get all topics with categories and suggested questions.
 *
 * @return array
 */
function bz{prefix}_get_topics() {
    return array(
        // ── Category: {category_1} ──
        array(
            'value'     => 'Tình cảm',
            'label'     => 'Tình cảm',
            'icon'      => '💕',
            'category'  => 'tinh_yeu',
            'questions' => array(
                'Tình cảm hiện tại của tôi thế nào?',
                'Mối quan hệ sẽ phát triển ra sao?',
            ),
        ),
        // ── Category: {category_2} ──
        array(
            'value'     => 'Tài chính',
            'label'     => 'Tài chính',
            'icon'      => '💰',
            'category'  => 'tai_chinh',
            'questions' => array(
                'Tình hình tài chính tháng này?',
                'Nên đầu tư không?',
            ),
        ),
        // ... thêm topics ...
    );
}

/**
 * Get topic categories.
 *
 * @return array
 */
function bz{prefix}_get_topic_categories() {
    return array(
        'tinh_yeu'  => array( 'label' => 'Tình yêu',   'icon' => '❤️' ),
        'tai_chinh' => array( 'label' => 'Tài chính',   'icon' => '💰' ),
        'cong_viec' => array( 'label' => 'Công việc',   'icon' => '💼' ),
        'suc_khoe'  => array( 'label' => 'Sức khỏe',    'icon' => '🌿' ),
        'ban_than'  => array( 'label' => 'Bản thân',    'icon' => '🌟' ),
    );
}

/**
 * Match user message to a topic.
 *
 * @param string $message User message text
 * @return array|null Matched topic or null
 */
function bz{prefix}_match_topic( $message ) {
    $topics = bz{prefix}_get_topics();
    $msg    = mb_strtolower( $message );

    foreach ( $topics as $topic ) {
        $val = mb_strtolower( $topic['value'] );
        if ( mb_strpos( $msg, $val ) !== false ) {
            return $topic;
        }
    }
    return null;
}
