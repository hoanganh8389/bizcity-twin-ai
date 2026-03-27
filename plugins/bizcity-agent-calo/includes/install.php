<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Table name helper
 */
function bzcalo_tables() {
    global $wpdb;
    return array(
        'profiles'    => $wpdb->prefix . 'bzcalo_profiles',
        'meals'       => $wpdb->prefix . 'bzcalo_meals',
        'foods'       => $wpdb->prefix . 'bzcalo_foods',
        'daily_stats' => $wpdb->prefix . 'bzcalo_daily_stats',
    );
}

/**
 * Install / upgrade DB tables
 */
function bzcalo_install_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $t       = bzcalo_tables();

    $sql = "CREATE TABLE {$t['profiles']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        full_name       VARCHAR(255)    NOT NULL DEFAULT '',
        gender          ENUM('male','female','other') NOT NULL DEFAULT 'other',
        dob             DATE            NULL,
        height_cm       DECIMAL(5,1)    NULL,
        weight_kg       DECIMAL(5,1)    NULL,
        target_weight   DECIMAL(5,1)    NULL,
        activity_level  VARCHAR(30)     NOT NULL DEFAULT 'moderate',
        goal            VARCHAR(30)     NOT NULL DEFAULT 'maintain',
        daily_calo_target INT UNSIGNED  NOT NULL DEFAULT 2000,
        allergies       TEXT,
        medical_notes   TEXT,
        extra_json      LONGTEXT,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id)
    ) $charset;

    CREATE TABLE {$t['foods']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug            VARCHAR(120)    NOT NULL DEFAULT '',
        name_vi         VARCHAR(255)    NOT NULL DEFAULT '',
        name_en         VARCHAR(255)    NOT NULL DEFAULT '',
        category        VARCHAR(60)     NOT NULL DEFAULT '',
        serving_size    VARCHAR(60)     NOT NULL DEFAULT '100g',
        calories        DECIMAL(8,1)    NOT NULL DEFAULT 0,
        protein_g       DECIMAL(6,1)    NOT NULL DEFAULT 0,
        carbs_g         DECIMAL(6,1)    NOT NULL DEFAULT 0,
        fat_g           DECIMAL(6,1)    NOT NULL DEFAULT 0,
        fiber_g         DECIMAL(6,1)    NOT NULL DEFAULT 0,
        image_url       VARCHAR(500)    NOT NULL DEFAULT '',
        data_json       LONGTEXT,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY slug (slug),
        KEY category (category)
    ) $charset;

    CREATE TABLE {$t['meals']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        meal_type       ENUM('breakfast','lunch','dinner','snack') NOT NULL DEFAULT 'lunch',
        meal_date       DATE            NOT NULL,
        meal_time       TIME            NULL,
        description     TEXT,
        photo_url       VARCHAR(500)    NOT NULL DEFAULT '',
        ai_analysis     LONGTEXT,
        items_json      LONGTEXT,
        total_calories  DECIMAL(8,1)    NOT NULL DEFAULT 0,
        total_protein   DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_carbs     DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_fat       DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_fiber     DECIMAL(6,1)    NOT NULL DEFAULT 0,
        note            TEXT,
        source          VARCHAR(30)     NOT NULL DEFAULT 'manual',
        platform        VARCHAR(30)     NOT NULL DEFAULT 'WEBCHAT',
        session_id      VARCHAR(100)    NOT NULL DEFAULT '',
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id_date (user_id, meal_date),
        KEY meal_date (meal_date),
        KEY meal_type (meal_type)
    ) $charset;

    CREATE TABLE {$t['daily_stats']} (
        id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id         BIGINT UNSIGNED NOT NULL,
        stat_date       DATE            NOT NULL,
        meals_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
        total_calories  DECIMAL(8,1)    NOT NULL DEFAULT 0,
        total_protein   DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_carbs     DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_fat       DECIMAL(6,1)    NOT NULL DEFAULT 0,
        total_fiber     DECIMAL(6,1)    NOT NULL DEFAULT 0,
        water_ml        INT UNSIGNED    NOT NULL DEFAULT 0,
        weight_kg       DECIMAL(5,1)    NULL,
        note            TEXT,
        created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_date (user_id, stat_date),
        KEY stat_date (stat_date)
    ) $charset;";

    dbDelta( $sql );
    bzcalo_seed_foods();
}

/**
 * Seed common Vietnamese foods
 */
function bzcalo_seed_foods() {
    global $wpdb;
    $t = bzcalo_tables();
    if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['foods']}" ) > 0 ) return;

    $foods = array(
        array( 'slug' => 'com-trang',       'name_vi' => 'Cơm trắng',          'name_en' => 'White Rice',         'category' => 'tinh_bot',  'serving_size' => '1 chén (200g)',  'calories' => 260, 'protein_g' => 5.4,  'carbs_g' => 56, 'fat_g' => 0.6,  'fiber_g' => 0.6 ),
        array( 'slug' => 'pho-bo',          'name_vi' => 'Phở bò',             'name_en' => 'Beef Pho',           'category' => 'mon_chinh', 'serving_size' => '1 tô (500g)',    'calories' => 450, 'protein_g' => 25,   'carbs_g' => 55, 'fat_g' => 12,   'fiber_g' => 1.5 ),
        array( 'slug' => 'banh-mi-thit',    'name_vi' => 'Bánh mì thịt',       'name_en' => 'Banh Mi',            'category' => 'mon_chinh', 'serving_size' => '1 ổ',            'calories' => 350, 'protein_g' => 15,   'carbs_g' => 45, 'fat_g' => 12,   'fiber_g' => 2 ),
        array( 'slug' => 'bun-cha',         'name_vi' => 'Bún chả',            'name_en' => 'Bun Cha',            'category' => 'mon_chinh', 'serving_size' => '1 phần',         'calories' => 500, 'protein_g' => 28,   'carbs_g' => 50, 'fat_g' => 18,   'fiber_g' => 2 ),
        array( 'slug' => 'com-tam',         'name_vi' => 'Cơm tấm sườn',      'name_en' => 'Broken Rice',        'category' => 'mon_chinh', 'serving_size' => '1 dĩa',          'calories' => 600, 'protein_g' => 30,   'carbs_g' => 65, 'fat_g' => 22,   'fiber_g' => 1 ),
        array( 'slug' => 'ga-nuong',        'name_vi' => 'Gà nướng',           'name_en' => 'Grilled Chicken',    'category' => 'protein',   'serving_size' => '1 đùi (150g)',   'calories' => 250, 'protein_g' => 30,   'carbs_g' => 0,  'fat_g' => 14,   'fiber_g' => 0 ),
        array( 'slug' => 'ca-hoi-nuong',    'name_vi' => 'Cá hồi nướng',      'name_en' => 'Grilled Salmon',     'category' => 'protein',   'serving_size' => '150g',           'calories' => 280, 'protein_g' => 32,   'carbs_g' => 0,  'fat_g' => 16,   'fiber_g' => 0 ),
        array( 'slug' => 'trung-luoc',      'name_vi' => 'Trứng luộc',         'name_en' => 'Boiled Egg',         'category' => 'protein',   'serving_size' => '1 quả',          'calories' => 78,  'protein_g' => 6.3,  'carbs_g' => 0.6,'fat_g' => 5.3,  'fiber_g' => 0 ),
        array( 'slug' => 'rau-cai-luoc',    'name_vi' => 'Rau cải luộc',       'name_en' => 'Boiled Vegetables',  'category' => 'rau_cu',    'serving_size' => '1 đĩa (150g)',   'calories' => 35,  'protein_g' => 2.5,  'carbs_g' => 5,  'fat_g' => 0.5,  'fiber_g' => 3 ),
        array( 'slug' => 'salad-tron',      'name_vi' => 'Salad trộn',         'name_en' => 'Mixed Salad',        'category' => 'rau_cu',    'serving_size' => '1 đĩa (200g)',   'calories' => 120, 'protein_g' => 3,    'carbs_g' => 10, 'fat_g' => 8,    'fiber_g' => 4 ),
        array( 'slug' => 'sua-tuoi',        'name_vi' => 'Sữa tươi',          'name_en' => 'Fresh Milk',         'category' => 'do_uong',   'serving_size' => '200ml',          'calories' => 120, 'protein_g' => 6.4,  'carbs_g' => 10, 'fat_g' => 6,    'fiber_g' => 0 ),
        array( 'slug' => 'tra-da',          'name_vi' => 'Trà đá',             'name_en' => 'Iced Tea',           'category' => 'do_uong',   'serving_size' => '1 ly (300ml)',   'calories' => 30,  'protein_g' => 0,    'carbs_g' => 8,  'fat_g' => 0,    'fiber_g' => 0 ),
        array( 'slug' => 'ca-phe-sua-da',   'name_vi' => 'Cà phê sữa đá',     'name_en' => 'Vietnamese Coffee',  'category' => 'do_uong',   'serving_size' => '1 ly (250ml)',   'calories' => 120, 'protein_g' => 2,    'carbs_g' => 20, 'fat_g' => 3,    'fiber_g' => 0 ),
        array( 'slug' => 'chuoi',           'name_vi' => 'Chuối',              'name_en' => 'Banana',             'category' => 'trai_cay',  'serving_size' => '1 quả',          'calories' => 89,  'protein_g' => 1.1,  'carbs_g' => 23, 'fat_g' => 0.3,  'fiber_g' => 2.6 ),
        array( 'slug' => 'tao',             'name_vi' => 'Táo',                'name_en' => 'Apple',              'category' => 'trai_cay',  'serving_size' => '1 quả',          'calories' => 95,  'protein_g' => 0.5,  'carbs_g' => 25, 'fat_g' => 0.3,  'fiber_g' => 4.4 ),
        array( 'slug' => 'mi-goi',          'name_vi' => 'Mì gói',             'name_en' => 'Instant Noodles',    'category' => 'tinh_bot',  'serving_size' => '1 gói (75g)',    'calories' => 350, 'protein_g' => 7,    'carbs_g' => 45, 'fat_g' => 15,   'fiber_g' => 1 ),
        array( 'slug' => 'xoi',             'name_vi' => 'Xôi',               'name_en' => 'Sticky Rice',        'category' => 'tinh_bot',  'serving_size' => '1 gói (200g)',   'calories' => 340, 'protein_g' => 6,    'carbs_g' => 70, 'fat_g' => 3,    'fiber_g' => 1 ),
        array( 'slug' => 'dau-hu',          'name_vi' => 'Đậu hũ',            'name_en' => 'Tofu',               'category' => 'protein',   'serving_size' => '150g',           'calories' => 120, 'protein_g' => 13,   'carbs_g' => 3,  'fat_g' => 7,    'fiber_g' => 1 ),
        array( 'slug' => 'goi-cuon',        'name_vi' => 'Gỏi cuốn',          'name_en' => 'Spring Rolls',       'category' => 'mon_chinh', 'serving_size' => '2 cuốn',         'calories' => 200, 'protein_g' => 10,   'carbs_g' => 28, 'fat_g' => 5,    'fiber_g' => 2 ),
        array( 'slug' => 'hu-tieu',         'name_vi' => 'Hủ tiếu',           'name_en' => 'Hu Tieu',            'category' => 'mon_chinh', 'serving_size' => '1 tô (450g)',    'calories' => 400, 'protein_g' => 22,   'carbs_g' => 50, 'fat_g' => 12,   'fiber_g' => 1 ),
    );

    foreach ( $foods as $food ) {
        $wpdb->insert( $t['foods'], $food );
    }
}

/**
 * Get or create user profile
 */
function bzcalo_get_or_create_profile( $user_id ) {
    global $wpdb;
    $t = bzcalo_tables();
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE user_id = %d", (int) $user_id
    ), ARRAY_A );

    if ( $row ) return $row;

    $user = get_userdata( $user_id );
    $wpdb->insert( $t['profiles'], array(
        'user_id'   => (int) $user_id,
        'full_name' => $user ? $user->display_name : '',
    ) );

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['profiles']} WHERE user_id = %d", (int) $user_id
    ), ARRAY_A );
}

/**
 * Recalculate daily stats for a user + date
 */
function bzcalo_recalc_daily_stats( $user_id, $date ) {
    global $wpdb;
    $t = bzcalo_tables();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT COUNT(*) as cnt,
                COALESCE(SUM(total_calories),0) as cal,
                COALESCE(SUM(total_protein),0) as pro,
                COALESCE(SUM(total_carbs),0) as carb,
                COALESCE(SUM(total_fat),0) as fat,
                COALESCE(SUM(total_fiber),0) as fib
         FROM {$t['meals']}
         WHERE user_id = %d AND meal_date = %s",
        (int) $user_id, $date
    ) );

    $wpdb->query( $wpdb->prepare(
        "INSERT INTO {$t['daily_stats']} (user_id, stat_date, meals_count, total_calories, total_protein, total_carbs, total_fat, total_fiber)
         VALUES (%d, %s, %d, %f, %f, %f, %f, %f)
         ON DUPLICATE KEY UPDATE
            meals_count    = VALUES(meals_count),
            total_calories = VALUES(total_calories),
            total_protein  = VALUES(total_protein),
            total_carbs    = VALUES(total_carbs),
            total_fat      = VALUES(total_fat),
            total_fiber    = VALUES(total_fiber),
            updated_at     = NOW()",
        (int) $user_id, $date,
        (int) $row->cnt, $row->cal, $row->pro, $row->carb, $row->fat, $row->fib
    ) );
}

/**
 * Calculate BMR (Mifflin-St Jeor)
 */
function bzcalo_calc_bmr( $profile ) {
    $w = (float) ( $profile['weight_kg'] ?? 0 );
    $h = (float) ( $profile['height_cm'] ?? 0 );
    $age = 30;
    if ( ! empty( $profile['dob'] ) ) {
        $age = max( 1, (int) date_diff( date_create( $profile['dob'] ), date_create( 'today' ) )->y );
    }
    $gender = $profile['gender'] ?? 'male';
    if ( $w <= 0 || $h <= 0 ) return 2000;

    if ( $gender === 'female' ) {
        $bmr = 10 * $w + 6.25 * $h - 5 * $age - 161;
    } else {
        $bmr = 10 * $w + 6.25 * $h - 5 * $age + 5;
    }

    $multipliers = array(
        'sedentary' => 1.2, 'light' => 1.375, 'moderate' => 1.55,
        'active' => 1.725, 'very_active' => 1.9,
    );
    $mult = $multipliers[ $profile['activity_level'] ?? 'moderate' ] ?? 1.55;

    return round( $bmr * $mult );
}
