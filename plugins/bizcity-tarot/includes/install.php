<?php
/**
 * BizCity Tarot – DB Install / Tables
 *
 * Tables:
 *   {prefix}bct_cards       – 78 tarot cards data
 *   {prefix}bct_readings    – saved readings history
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Table name helpers
 * ------------------------------------------------------------- */
function bct_tables(): array {
    global $wpdb;
    return [
        'cards'    => $wpdb->prefix . 'bct_cards',
        'readings' => $wpdb->prefix . 'bct_readings',
    ];
}

/* ---------------------------------------------------------------
 * Install / create tables
 * ------------------------------------------------------------- */
function bct_install_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t       = bct_tables();

    $sqls   = [];

    // ---- 1. Cards ----
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['cards']} (
        id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        card_slug        VARCHAR(80)  NOT NULL DEFAULT '',
        card_name_en     VARCHAR(150) NOT NULL DEFAULT '',
        card_name_vi     VARCHAR(150) NOT NULL DEFAULT '',
        card_type        VARCHAR(30)  NOT NULL DEFAULT 'major',
        card_number      TINYINT      NOT NULL DEFAULT 0,
        suit             VARCHAR(30)  NOT NULL DEFAULT '',
        keywords_en      TEXT         NULL,
        keywords_vi      TEXT         NULL,
        description_en   LONGTEXT     NULL,
        description_vi   LONGTEXT     NULL,
        upright_vi       LONGTEXT     NULL,
        reversed_vi      LONGTEXT     NULL,
        image_url        VARCHAR(500) NOT NULL DEFAULT '',
        source_url       VARCHAR(500) NOT NULL DEFAULT '',
        sort_order       SMALLINT     NOT NULL DEFAULT 0,
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY card_slug (card_slug),
        KEY card_type (card_type)
    ) $charset;";

    // ---- 2. Readings ----
    $sqls[] = "CREATE TABLE IF NOT EXISTS {$t['readings']} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id     BIGINT UNSIGNED NULL,
        client_id   VARCHAR(100)    NOT NULL DEFAULT '',
        platform    VARCHAR(30)     NOT NULL DEFAULT '',
        session_id  VARCHAR(64)     NOT NULL DEFAULT '',
        topic       VARCHAR(255)    NOT NULL DEFAULT '',
        question    VARCHAR(500)    NOT NULL DEFAULT '',
        card_ids    VARCHAR(50)     NOT NULL DEFAULT '',
        cards_json  LONGTEXT        NULL,
        is_reversed VARCHAR(20)     NOT NULL DEFAULT '',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY client_id (client_id),
        KEY platform (platform),
        KEY created_at (created_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ( $sqls as $sql ) {
        dbDelta( $sql );
    }

    // Migration: add missing columns to existing installs
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t['readings']}" );
    if ( ! in_array( 'client_id', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD COLUMN client_id VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id" );
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD INDEX client_id (client_id)" );
    }
    if ( ! in_array( 'platform', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD COLUMN platform VARCHAR(30) NOT NULL DEFAULT '' AFTER client_id" );
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD INDEX platform (platform)" );
    }
    if ( ! in_array( 'ai_reply', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD COLUMN ai_reply LONGTEXT NULL AFTER is_reversed" );
    }

    // Seed default cards if table is empty
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['cards']}" );
    if ( $count === 0 ) {
        bct_seed_default_cards();
    }
}

/* ---------------------------------------------------------------
 * Seed all 78 Tarot cards with English names & slug
 * (Meanings/descriptions populated via crawl)
 * ------------------------------------------------------------- */
function bct_seed_default_cards(): void {
    global $wpdb;
    $t = bct_tables();

    $cards = bct_get_card_definitions();
    foreach ( $cards as $i => $c ) {
        $wpdb->replace( $t['cards'], [
            'card_slug'    => $c['slug'],
            'card_name_en' => $c['name_en'],
            'card_name_vi' => $c['name_vi'],
            'card_type'    => $c['type'],
            'card_number'  => $c['number'],
            'suit'         => $c['suit'] ?? '',
            'image_url'    => 'https://www.learntarot.com/bigjpgs/' . $c['slug'] . '.jpg',
            'source_url'   => 'https://www.learntarot.com/' . $c['source'] . '.htm',
            'sort_order'   => $i + 1,
        ] );
    }
}

/* ---------------------------------------------------------------
 * Card definitions – all 78 cards
 * ------------------------------------------------------------- */
function bct_get_card_definitions(): array {
    return [
        // === MAJOR ARCANA ===
        ['slug'=>'maj00','name_en'=>'The Fool',            'name_vi'=>'Kẻ Ngốc',             'type'=>'major','number'=>0,  'source'=>'maj00'],
        ['slug'=>'maj01','name_en'=>'The Magician',        'name_vi'=>'Pháp Sư',              'type'=>'major','number'=>1,  'source'=>'maj01'],
        ['slug'=>'maj02','name_en'=>'The High Priestess',  'name_vi'=>'Nữ Tư Tế',             'type'=>'major','number'=>2,  'source'=>'maj02'],
        ['slug'=>'maj03','name_en'=>'The Empress',         'name_vi'=>'Nữ Hoàng',             'type'=>'major','number'=>3,  'source'=>'maj03'],
        ['slug'=>'maj04','name_en'=>'The Emperor',         'name_vi'=>'Hoàng Đế',             'type'=>'major','number'=>4,  'source'=>'maj04'],
        ['slug'=>'maj05','name_en'=>'The Hierophant',      'name_vi'=>'Giáo Hoàng',           'type'=>'major','number'=>5,  'source'=>'maj05'],
        ['slug'=>'maj06','name_en'=>'The Lovers',          'name_vi'=>'Đôi Tình Nhân',        'type'=>'major','number'=>6,  'source'=>'maj06'],
        ['slug'=>'maj07','name_en'=>'The Chariot',         'name_vi'=>'Cỗ Xe Chiến',          'type'=>'major','number'=>7,  'source'=>'maj07'],
        ['slug'=>'maj08','name_en'=>'Strength',            'name_vi'=>'Sức Mạnh',             'type'=>'major','number'=>8,  'source'=>'maj08'],
        ['slug'=>'maj09','name_en'=>'The Hermit',          'name_vi'=>'Ẩn Sĩ',               'type'=>'major','number'=>9,  'source'=>'maj09'],
        ['slug'=>'maj10','name_en'=>'Wheel Of Fortune',    'name_vi'=>'Bánh Xe Số Phận',      'type'=>'major','number'=>10, 'source'=>'maj10'],
        ['slug'=>'maj11','name_en'=>'Justice',             'name_vi'=>'Công Lý',              'type'=>'major','number'=>11, 'source'=>'maj11'],
        ['slug'=>'maj12','name_en'=>'The Hanged Man',      'name_vi'=>'Người Treo Ngược',     'type'=>'major','number'=>12, 'source'=>'maj12'],
        ['slug'=>'maj13','name_en'=>'Death',               'name_vi'=>'Cái Chết',             'type'=>'major','number'=>13, 'source'=>'maj13'],
        ['slug'=>'maj14','name_en'=>'Temperance',          'name_vi'=>'Điều Độ',              'type'=>'major','number'=>14, 'source'=>'maj14'],
        ['slug'=>'maj15','name_en'=>'The Devil',           'name_vi'=>'Ác Quỷ',              'type'=>'major','number'=>15, 'source'=>'maj15'],
        ['slug'=>'maj16','name_en'=>'The Tower',           'name_vi'=>'Ngọn Tháp',            'type'=>'major','number'=>16, 'source'=>'maj16'],
        ['slug'=>'maj17','name_en'=>'The Star',            'name_vi'=>'Ngôi Sao',             'type'=>'major','number'=>17, 'source'=>'maj17'],
        ['slug'=>'maj18','name_en'=>'The Moon',            'name_vi'=>'Mặt Trăng',            'type'=>'major','number'=>18, 'source'=>'maj18'],
        ['slug'=>'maj19','name_en'=>'The Sun',             'name_vi'=>'Mặt Trời',             'type'=>'major','number'=>19, 'source'=>'maj19'],
        ['slug'=>'maj20','name_en'=>'Judgement',           'name_vi'=>'Phán Xét',             'type'=>'major','number'=>20, 'source'=>'maj20'],
        ['slug'=>'maj21','name_en'=>'The World',           'name_vi'=>'Thế Giới',             'type'=>'major','number'=>21, 'source'=>'maj21'],

        // === WANDS ===
        ['slug'=>'wa',  'name_en'=>'Ace Of Wands',    'name_vi'=>'Át Gậy',            'type'=>'minor','number'=>1, 'suit'=>'wands','source'=>'wa'],
        ['slug'=>'w2',  'name_en'=>'Two Of Wands',    'name_vi'=>'Hai Gậy',           'type'=>'minor','number'=>2, 'suit'=>'wands','source'=>'w2'],
        ['slug'=>'w3',  'name_en'=>'Three Of Wands',  'name_vi'=>'Ba Gậy',            'type'=>'minor','number'=>3, 'suit'=>'wands','source'=>'w3'],
        ['slug'=>'w4',  'name_en'=>'Four Of Wands',   'name_vi'=>'Bốn Gậy',          'type'=>'minor','number'=>4, 'suit'=>'wands','source'=>'w4'],
        ['slug'=>'w5',  'name_en'=>'Five Of Wands',   'name_vi'=>'Năm Gậy',          'type'=>'minor','number'=>5, 'suit'=>'wands','source'=>'w5'],
        ['slug'=>'w6',  'name_en'=>'Six Of Wands',    'name_vi'=>'Sáu Gậy',          'type'=>'minor','number'=>6, 'suit'=>'wands','source'=>'w6'],
        ['slug'=>'w7',  'name_en'=>'Seven Of Wands',  'name_vi'=>'Bảy Gậy',          'type'=>'minor','number'=>7, 'suit'=>'wands','source'=>'w7'],
        ['slug'=>'w8',  'name_en'=>'Eight Of Wands',  'name_vi'=>'Tám Gậy',          'type'=>'minor','number'=>8, 'suit'=>'wands','source'=>'w8'],
        ['slug'=>'w9',  'name_en'=>'Nine Of Wands',   'name_vi'=>'Chín Gậy',         'type'=>'minor','number'=>9, 'suit'=>'wands','source'=>'w9'],
        ['slug'=>'w10', 'name_en'=>'Ten Of Wands',    'name_vi'=>'Mười Gậy',         'type'=>'minor','number'=>10,'suit'=>'wands','source'=>'w10'],
        ['slug'=>'wpg', 'name_en'=>'Page Of Wands',   'name_vi'=>'Tiểu Đồng Gậy',   'type'=>'minor','number'=>11,'suit'=>'wands','source'=>'wpg'],
        ['slug'=>'wkn', 'name_en'=>'Knight Of Wands', 'name_vi'=>'Kỵ Sĩ Gậy',       'type'=>'minor','number'=>12,'suit'=>'wands','source'=>'wkn'],
        ['slug'=>'wqn', 'name_en'=>'Queen Of Wands',  'name_vi'=>'Nữ Hoàng Gậy',    'type'=>'minor','number'=>13,'suit'=>'wands','source'=>'wqn'],
        ['slug'=>'wkg', 'name_en'=>'King Of Wands',   'name_vi'=>'Vua Gậy',          'type'=>'minor','number'=>14,'suit'=>'wands','source'=>'wkg'],

        // === CUPS ===
        ['slug'=>'ca',  'name_en'=>'Ace Of Cups',    'name_vi'=>'Át Cốc',            'type'=>'minor','number'=>1, 'suit'=>'cups','source'=>'ca'],
        ['slug'=>'c2',  'name_en'=>'Two Of Cups',    'name_vi'=>'Hai Cốc',           'type'=>'minor','number'=>2, 'suit'=>'cups','source'=>'c2'],
        ['slug'=>'c3',  'name_en'=>'Three Of Cups',  'name_vi'=>'Ba Cốc',            'type'=>'minor','number'=>3, 'suit'=>'cups','source'=>'c3'],
        ['slug'=>'c4',  'name_en'=>'Four Of Cups',   'name_vi'=>'Bốn Cốc',          'type'=>'minor','number'=>4, 'suit'=>'cups','source'=>'c4'],
        ['slug'=>'c5',  'name_en'=>'Five Of Cups',   'name_vi'=>'Năm Cốc',          'type'=>'minor','number'=>5, 'suit'=>'cups','source'=>'c5'],
        ['slug'=>'c6',  'name_en'=>'Six Of Cups',    'name_vi'=>'Sáu Cốc',          'type'=>'minor','number'=>6, 'suit'=>'cups','source'=>'c6'],
        ['slug'=>'c7',  'name_en'=>'Seven Of Cups',  'name_vi'=>'Bảy Cốc',          'type'=>'minor','number'=>7, 'suit'=>'cups','source'=>'c7'],
        ['slug'=>'c8',  'name_en'=>'Eight Of Cups',  'name_vi'=>'Tám Cốc',          'type'=>'minor','number'=>8, 'suit'=>'cups','source'=>'c8'],
        ['slug'=>'c9',  'name_en'=>'Nine Of Cups',   'name_vi'=>'Chín Cốc',         'type'=>'minor','number'=>9, 'suit'=>'cups','source'=>'c9'],
        ['slug'=>'c10', 'name_en'=>'Ten Of Cups',    'name_vi'=>'Mười Cốc',         'type'=>'minor','number'=>10,'suit'=>'cups','source'=>'c10'],
        ['slug'=>'cpg', 'name_en'=>'Page Of Cups',   'name_vi'=>'Tiểu Đồng Cốc',   'type'=>'minor','number'=>11,'suit'=>'cups','source'=>'cpg'],
        ['slug'=>'ckn', 'name_en'=>'Knight Of Cups', 'name_vi'=>'Kỵ Sĩ Cốc',       'type'=>'minor','number'=>12,'suit'=>'cups','source'=>'ckn'],
        ['slug'=>'cqn', 'name_en'=>'Queen Of Cups',  'name_vi'=>'Nữ Hoàng Cốc',    'type'=>'minor','number'=>13,'suit'=>'cups','source'=>'cqn'],
        ['slug'=>'ckg', 'name_en'=>'King Of Cups',   'name_vi'=>'Vua Cốc',          'type'=>'minor','number'=>14,'suit'=>'cups','source'=>'ckg'],

        // === SWORDS ===
        ['slug'=>'sa',  'name_en'=>'Ace Of Swords',    'name_vi'=>'Át Kiếm',          'type'=>'minor','number'=>1, 'suit'=>'swords','source'=>'sa'],
        ['slug'=>'s2',  'name_en'=>'Two Of Swords',    'name_vi'=>'Hai Kiếm',         'type'=>'minor','number'=>2, 'suit'=>'swords','source'=>'s2'],
        ['slug'=>'s3',  'name_en'=>'Three Of Swords',  'name_vi'=>'Ba Kiếm',          'type'=>'minor','number'=>3, 'suit'=>'swords','source'=>'s3'],
        ['slug'=>'s4',  'name_en'=>'Four Of Swords',   'name_vi'=>'Bốn Kiếm',        'type'=>'minor','number'=>4, 'suit'=>'swords','source'=>'s4'],
        ['slug'=>'s5',  'name_en'=>'Five Of Swords',   'name_vi'=>'Năm Kiếm',        'type'=>'minor','number'=>5, 'suit'=>'swords','source'=>'s5'],
        ['slug'=>'s6',  'name_en'=>'Six Of Swords',    'name_vi'=>'Sáu Kiếm',        'type'=>'minor','number'=>6, 'suit'=>'swords','source'=>'s6'],
        ['slug'=>'s7',  'name_en'=>'Seven Of Swords',  'name_vi'=>'Bảy Kiếm',        'type'=>'minor','number'=>7, 'suit'=>'swords','source'=>'s7'],
        ['slug'=>'s8',  'name_en'=>'Eight Of Swords',  'name_vi'=>'Tám Kiếm',        'type'=>'minor','number'=>8, 'suit'=>'swords','source'=>'s8'],
        ['slug'=>'s9',  'name_en'=>'Nine Of Swords',   'name_vi'=>'Chín Kiếm',       'type'=>'minor','number'=>9, 'suit'=>'swords','source'=>'s9'],
        ['slug'=>'s10', 'name_en'=>'Ten Of Swords',    'name_vi'=>'Mười Kiếm',       'type'=>'minor','number'=>10,'suit'=>'swords','source'=>'s10'],
        ['slug'=>'spg', 'name_en'=>'Page Of Swords',   'name_vi'=>'Tiểu Đồng Kiếm', 'type'=>'minor','number'=>11,'suit'=>'swords','source'=>'spg'],
        ['slug'=>'skn', 'name_en'=>'Knight Of Swords', 'name_vi'=>'Kỵ Sĩ Kiếm',     'type'=>'minor','number'=>12,'suit'=>'swords','source'=>'skn'],
        ['slug'=>'sqn', 'name_en'=>'Queen Of Swords',  'name_vi'=>'Nữ Hoàng Kiếm',  'type'=>'minor','number'=>13,'suit'=>'swords','source'=>'sqn'],
        ['slug'=>'skg', 'name_en'=>'King Of Swords',   'name_vi'=>'Vua Kiếm',        'type'=>'minor','number'=>14,'suit'=>'swords','source'=>'skg'],

        // === PENTACLES ===
        ['slug'=>'pa',  'name_en'=>'Ace Of Pentacles',    'name_vi'=>'Át Tiền',          'type'=>'minor','number'=>1, 'suit'=>'pentacles','source'=>'pa'],
        ['slug'=>'p2',  'name_en'=>'Two Of Pentacles',    'name_vi'=>'Hai Tiền',         'type'=>'minor','number'=>2, 'suit'=>'pentacles','source'=>'p2'],
        ['slug'=>'p3',  'name_en'=>'Three Of Pentacles',  'name_vi'=>'Ba Tiền',          'type'=>'minor','number'=>3, 'suit'=>'pentacles','source'=>'p3'],
        ['slug'=>'p4',  'name_en'=>'Four Of Pentacles',   'name_vi'=>'Bốn Tiền',        'type'=>'minor','number'=>4, 'suit'=>'pentacles','source'=>'p4'],
        ['slug'=>'p5',  'name_en'=>'Five Of Pentacles',   'name_vi'=>'Năm Tiền',        'type'=>'minor','number'=>5, 'suit'=>'pentacles','source'=>'p5'],
        ['slug'=>'p6',  'name_en'=>'Six Of Pentacles',    'name_vi'=>'Sáu Tiền',        'type'=>'minor','number'=>6, 'suit'=>'pentacles','source'=>'p6'],
        ['slug'=>'p7',  'name_en'=>'Seven Of Pentacles',  'name_vi'=>'Bảy Tiền',        'type'=>'minor','number'=>7, 'suit'=>'pentacles','source'=>'p7'],
        ['slug'=>'p8',  'name_en'=>'Eight Of Pentacles',  'name_vi'=>'Tám Tiền',        'type'=>'minor','number'=>8, 'suit'=>'pentacles','source'=>'p8'],
        ['slug'=>'p9',  'name_en'=>'Nine Of Pentacles',   'name_vi'=>'Chín Tiền',       'type'=>'minor','number'=>9, 'suit'=>'pentacles','source'=>'p9'],
        ['slug'=>'p10', 'name_en'=>'Ten Of Pentacles',    'name_vi'=>'Mười Tiền',       'type'=>'minor','number'=>10,'suit'=>'pentacles','source'=>'p10'],
        ['slug'=>'ppg', 'name_en'=>'Page Of Pentacles',   'name_vi'=>'Tiểu Đồng Tiền', 'type'=>'minor','number'=>11,'suit'=>'pentacles','source'=>'ppg'],
        ['slug'=>'pkn', 'name_en'=>'Knight Of Pentacles', 'name_vi'=>'Kỵ Sĩ Tiền',     'type'=>'minor','number'=>12,'suit'=>'pentacles','source'=>'pkn'],
        ['slug'=>'pqn', 'name_en'=>'Queen Of Pentacles',  'name_vi'=>'Nữ Hoàng Tiền',  'type'=>'minor','number'=>13,'suit'=>'pentacles','source'=>'pqn'],
        ['slug'=>'pkg', 'name_en'=>'King Of Pentacles',   'name_vi'=>'Vua Tiền',        'type'=>'minor','number'=>14,'suit'=>'pentacles','source'=>'pkg'],
    ];
}
