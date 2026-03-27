<?php
/**
 * Topics & data registry — meal categories, suggested questions.
 *
 * @package BizCity_Calo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Get all topics with categories and suggested questions.
 */
function bzcalo_get_topics() {
    return array(
        array(
            'value'     => 'Ghi bữa ăn',
            'label'     => 'Ghi bữa ăn',
            'icon'      => '📸',
            'category'  => 'ghi_bua',
            'questions' => array(
                'Tôi vừa ăn 1 tô phở bò',
                'Ghi nhận bữa trưa: cơm tấm sườn, trà đá',
                'Chụp ảnh bữa ăn để tính calo',
            ),
        ),
        array(
            'value'     => 'Bữa sáng',
            'label'     => 'Bữa sáng',
            'icon'      => '🌅',
            'category'  => 'bua_sang',
            'questions' => array(
                'Ăn sáng nên ăn gì giảm cân?',
                'Bữa sáng hôm nay tôi ăn bánh mì',
            ),
        ),
        array(
            'value'     => 'Bữa trưa',
            'label'     => 'Bữa trưa',
            'icon'      => '☀️',
            'category'  => 'bua_trua',
            'questions' => array(
                'Bữa trưa healthy nên ăn gì?',
                'Trưa nay ăn cơm gà và canh rau',
            ),
        ),
        array(
            'value'     => 'Bữa tối',
            'label'     => 'Bữa tối',
            'icon'      => '🌙',
            'category'  => 'bua_toi',
            'questions' => array(
                'Bữa tối ít calo nên ăn gì?',
                'Tối nay ăn salad cá hồi',
            ),
        ),
        array(
            'value'     => 'Ăn vặt',
            'label'     => 'Ăn vặt',
            'icon'      => '🍪',
            'category'  => 'an_vat',
            'questions' => array(
                'Ăn vặt healthy có gì?',
                'Tôi vừa ăn 1 quả chuối',
            ),
        ),
        array(
            'value'     => 'Dinh dưỡng',
            'label'     => 'Dinh dưỡng',
            'icon'      => '🥗',
            'category'  => 'dinh_duong',
            'questions' => array(
                'Nhu cầu protein mỗi ngày?',
                'Cách tính BMR?',
                'Macro hàng ngày của tôi thế nào?',
            ),
        ),
        array(
            'value'     => 'Giảm cân',
            'label'     => 'Giảm cân',
            'icon'      => '🔽',
            'category'  => 'giam_can',
            'questions' => array(
                'Lộ trình giảm cân cho tôi?',
                'Thực đơn giảm cân 1 tuần?',
                'Cần ăn bao nhiêu calo để giảm cân?',
            ),
        ),
        array(
            'value'     => 'Tăng cân',
            'label'     => 'Tăng cân',
            'icon'      => '🔼',
            'category'  => 'tang_can',
            'questions' => array(
                'Thực đơn tăng cân cho người gầy?',
                'Nên ăn bao nhiêu calo để tăng cân?',
            ),
        ),
        array(
            'value'     => 'Thống kê',
            'label'     => 'Thống kê',
            'icon'      => '📊',
            'category'  => 'thong_ke',
            'questions' => array(
                'Hôm nay tôi ăn bao nhiêu calo?',
                'Thống kê tuần này?',
                'Biểu đồ dinh dưỡng 7 ngày?',
            ),
        ),
    );
}

/**
 * Get topic categories.
 */
function bzcalo_get_topic_categories() {
    return array(
        'ghi_bua'   => array( 'label' => 'Ghi bữa ăn',  'icon' => '📸' ),
        'bua_sang'  => array( 'label' => 'Bữa sáng',     'icon' => '🌅' ),
        'bua_trua'  => array( 'label' => 'Bữa trưa',     'icon' => '☀️' ),
        'bua_toi'   => array( 'label' => 'Bữa tối',      'icon' => '🌙' ),
        'an_vat'    => array( 'label' => 'Ăn vặt',        'icon' => '🍪' ),
        'dinh_duong'=> array( 'label' => 'Dinh dưỡng',    'icon' => '🥗' ),
        'giam_can'  => array( 'label' => 'Giảm cân',      'icon' => '🔽' ),
        'tang_can'  => array( 'label' => 'Tăng cân',      'icon' => '🔼' ),
        'thong_ke'  => array( 'label' => 'Thống kê',      'icon' => '📊' ),
    );
}

/**
 * Match user message to a topic.
 */
function bzcalo_match_topic( $message ) {
    $topics = bzcalo_get_topics();
    $msg    = mb_strtolower( $message );

    foreach ( $topics as $topic ) {
        $val = mb_strtolower( $topic['value'] );
        if ( mb_strpos( $msg, $val ) !== false ) {
            return $topic;
        }
    }
    return null;
}
