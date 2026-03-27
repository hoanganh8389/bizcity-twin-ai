<?php
/**
 * BizCity Tarot – AJAX Handlers
 *
 * Actions:
 *   bct_crawl_card      – crawl 1 card from learntarot.com
 *   bct_get_card_data   – return card data (frontend)
 *   bct_save_reading    – save reading to DB
 *
 * @package BizCity_Tarot
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------
 * Crawl single card from learntarot.com  (admin only)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_crawl_card', 'bct_ajax_crawl_card' );
function bct_ajax_crawl_card(): void {
    check_ajax_referer( 'bct_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    global $wpdb;
    $t       = bct_tables();
    $card_id = (int) ( $_POST['card_id'] ?? 0 );
    $slug    = sanitize_key( $_POST['slug'] ?? '' );

    if ( ! $card_id || ! $slug ) {
        wp_send_json_error( 'Missing params' );
    }

    $card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['cards']} WHERE id = %d", $card_id ) );
    if ( ! $card ) {
        wp_send_json_error( 'Card not found' );
    }

    $source_url = 'https://www.learntarot.com/' . $slug . '.htm';

    $response = wp_remote_get( $source_url, [
        'timeout'    => 20,
        'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( $response->get_error_message() );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( ! $body ) {
        wp_send_json_error( 'Empty response from ' . $source_url );
    }

    $parsed = bct_parse_learntarot_page( $body, $slug );

    // Build update array – always refresh EN fields
    $update = [
        'keywords_en'    => $parsed['keywords'],
        'description_en' => $parsed['description'],
        'image_url'      => $parsed['image_url'] ?: $card->image_url,
        'source_url'     => $source_url,
    ];

    // Populate VI fields as EN placeholder so they show up in the edit form.
    // force=1 (from "Crawl Again" button) will overwrite existing VI content.
    $force = ! empty( $_POST['force'] );
    if ( ( $force || empty( $card->keywords_vi ) ) && ! empty( $parsed['keywords'] ) ) {
        $update['keywords_vi'] = $parsed['keywords'];
    }
    if ( ( $force || empty( $card->description_vi ) ) && ! empty( $parsed['description'] ) ) {
        $update['description_vi'] = '<p>' . nl2br( esc_html( $parsed['description'] ) ) . '</p>';
    }
    if ( ( $force || empty( $card->upright_vi ) ) && ! empty( $parsed['upright'] ) ) {
        $update['upright_vi'] = '<p>' . nl2br( esc_html( $parsed['upright'] ) ) . '</p>';
    }
    if ( ( $force || empty( $card->reversed_vi ) ) && ! empty( $parsed['reversed'] ) ) {
        $update['reversed_vi'] = '<p>' . nl2br( esc_html( $parsed['reversed'] ) ) . '</p>';
    }

    $wpdb->update( $t['cards'], $update, [ 'id' => $card_id ] );

    wp_send_json_success( [
        'slug'        => $slug,
        'keywords'    => $parsed['keywords'],
        'description' => substr( $parsed['description'], 0, 200 ) . '...',
        'upright'     => substr( $parsed['upright'], 0, 100 ) . '...',
        'reversed'    => substr( $parsed['reversed'], 0, 100 ) . '...',
        'image_url'   => $parsed['image_url'],
    ] );
}

/* ---------------------------------------------------------------
 * Parse learntarot.com page HTML
 *
 * Page structure (learntarot.com):
 *   <h1>CARD NAME</h1>
 *   <ul><li><b>KEYWORD</b></li>...</ul>       ← keywords
 *   <a name="actions"> ... <dl>...</dl>        ← actions / upright meanings
 *   <a name="opposite"> ...                    ← opposing cards (skip)
 *   <a name="reinforce"> ...                   ← reinforcing cards (skip)
 *   <a name="description"> ... <p>...</p>      ← description
 *   <hr>
 * ------------------------------------------------------------- */
function bct_parse_learntarot_page( string $html, string $slug ): array {
    $result = [
        'keywords'    => '',
        'description' => '',
        'upright'     => '',
        'reversed'    => '',
        'image_url'   => '',
    ];

    // ── Step 1: Image URL ──────────────────────────────────────────────────
    // Prefer big JPG (bigjpgs/slug.jpg), fallback to small GIF (slugs.gif)
    if ( preg_match( '/<a[^>]+href=["\']bigjpgs\/([^"\']+)["\'][^>]*>/i', $html, $big_m ) ) {
        $result['image_url'] = 'https://www.learntarot.com/bigjpgs/' . $big_m[1];
    } elseif ( preg_match( '/<img[^>]+src=["\']([^"\']*' . preg_quote( $slug, '/' ) . '[^"\']*)["\'][^>]*>/i', $html, $img_m ) ) {
        $src = $img_m[1];
        $result['image_url'] = ( strpos( $src, 'http' ) === 0 )
            ? $src
            : 'https://www.learntarot.com/' . ltrim( $src, '/' );
    } else {
        $result['image_url'] = 'https://www.learntarot.com/' . $slug . 's.gif';
    }

    // ── Step 2: Keywords ──────────────────────────────────────────────────
    // learntarot.com: <ul><li><b>BEGINNING</b></li>...</ul> (first UL block)
    // Extract only the first <ul> block (the keywords one, before navigation)
    if ( preg_match( '/<ul>([\s\S]*?)<\/ul>/i', $html, $ul_m ) ) {
        if ( preg_match_all( '/<b>([^<]+)<\/b>/i', $ul_m[1], $kw_m ) ) {
            $keywords = array_map( 'trim', $kw_m[1] );
            $keywords = array_filter( $keywords, fn( $k ) => strlen( $k ) > 1 && strlen( $k ) < 60 );
            $result['keywords'] = implode( ', ', array_values( $keywords ) );
        }
    }

    // ── Step 3: Actions section (maps to upright / card meanings) ─────────
    // Between <a name="actions"> and <a name="opposite">
    if ( preg_match( '/<a\s+name=["\']actions["\'][^>]*>[\s\S]*?<\/a>([\s\S]*?)(?=<a\s+name=["\']opposite["\']|<img[^>]+rbowline)/i', $html, $act_m ) ) {
        $actions_html = $act_m[1];
        // Extract dt/dd pairs: verb → sub-bullets
        $lines = [];
        // dt = main action verb (bold)
        if ( preg_match_all( '/<dt[^>]*>([\s\S]*?)<\/dt>/i', $actions_html, $dt_m ) ) {
            foreach ( $dt_m[1] as $dt ) {
                $verb = trim( strip_tags( $dt ) );
                if ( $verb ) $lines[] = ucfirst( $verb );
            }
        }
        // dd = sub-bullets
        if ( preg_match_all( '/<dd[^>]*>([\s\S]*?)<\/dd>/i', $actions_html, $dd_m ) ) {
            foreach ( $dd_m[1] as $dd ) {
                $clean_dd = strip_tags( str_replace( '<br>', "\n", $dd ) );
                $subs = array_filter( array_map( 'trim', explode( "\n", $clean_dd ) ) );
                foreach ( $subs as $s ) {
                    if ( $s ) $lines[] = '  • ' . $s;
                }
            }
        }
        if ( $lines ) {
            $result['upright'] = implode( "\n", $lines );
        }
    }

    // ── Step 4: Description section ───────────────────────────────────────
    // Between <a name="description"> and <hr>
    if ( preg_match( '/<a\s+name=["\']description["\'][^>]*>([\s\S]*?)(?=<hr|<div\s+align)/i', $html, $desc_m ) ) {
        $desc_html = $desc_m[1];
        // Collect all <p> paragraphs
        $paragraphs = [];
        if ( preg_match_all( '/<p[^>]*>([\s\S]*?)<\/p>/i', $desc_html, $p_m ) ) {
            foreach ( $p_m[1] as $p ) {
                $text = trim( strip_tags( $p ) );
                $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
                $text = preg_replace( '/\s+/', ' ', $text );
                if ( strlen( $text ) > 30 ) {
                    $paragraphs[] = $text;
                }
            }
        }
        if ( $paragraphs ) {
            $result['description'] = implode( "\n\n", $paragraphs );
        }
    }

    // Fallback description: grab long prose lines if anchor-based parse failed
    if ( empty( $result['description'] ) ) {
        $clean = strip_tags( preg_replace( '/<script[\s\S]*?<\/script>/i', '', $html ) );
        $clean = html_entity_decode( $clean, ENT_QUOTES, 'UTF-8' );
        $clean = preg_replace( '/[ \t]+/', ' ', $clean );
        $lines = explode( "\n", $clean );
        $prose = array_filter( $lines, fn( $l ) => strlen( trim( $l ) ) > 80 && ! preg_match( '/^[A-Z\s]{5,40}$/', trim( $l ) ) );
        $result['description'] = implode( ' ', array_slice( array_values( $prose ), 0, 6 ) );
    }

    return $result;
}

/* ---------------------------------------------------------------
 * Get card data (frontend AJAX – no login required)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_get_cards',        'bct_ajax_get_cards' );
add_action( 'wp_ajax_nopriv_bct_get_cards', 'bct_ajax_get_cards' );
function bct_ajax_get_cards(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    global $wpdb;
    $t     = bct_tables();
    $cards = $wpdb->get_results( "SELECT id, card_slug, card_name_en, card_name_vi, card_type, suit, image_url FROM {$t['cards']} ORDER BY sort_order ASC" );

    wp_send_json_success( $cards );
}

/* ---------------------------------------------------------------
 * Get single card meaning (frontend AJAX)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_get_card_meaning',        'bct_ajax_get_card_meaning' );
add_action( 'wp_ajax_nopriv_bct_get_card_meaning', 'bct_ajax_get_card_meaning' );
function bct_ajax_get_card_meaning(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    global $wpdb;
    $t       = bct_tables();
    $card_id = (int) ( $_POST['card_id'] ?? 0 );
    $is_rev  = (int) ( $_POST['is_reversed'] ?? 0 );

    if ( ! $card_id ) {
        wp_send_json_error( 'No card ID' );
    }

    $card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['cards']} WHERE id = %d", $card_id ) );
    if ( ! $card ) {
        wp_send_json_error( 'Card not found' );
    }

    // Prepare meaning
    $meaning = '';
    if ( $is_rev ) {
        $meaning = $card->reversed_vi ?: $card->upright_vi;
        if ( ! $meaning ) {
            $meaning = '<p><em>Lá bài ' . esc_html( $card->card_name_vi ?: $card->card_name_en ) . ' ngược – chưa có bản dịch. Hãy vào Admin để bổ sung.</em></p>';
        }
    } else {
        $meaning = $card->upright_vi;
        if ( ! $meaning ) {
            $meaning = $card->description_vi ?: '<p><em>' . esc_html( $card->card_name_vi ?: $card->card_name_en ) . ' – ' . esc_html( $card->keywords_vi ?: $card->keywords_en ?: 'Chưa có dữ liệu. Hãy crawl về từ learntarot.com.' ) . '</em></p>';
        }
    }

    wp_send_json_success( [
        'id'          => (int) $card->id,
        'slug'        => $card->card_slug,
        'name_en'     => $card->card_name_en,
        'name_vi'     => $card->card_name_vi,
        'image_url'   => $card->image_url,
        'keywords_vi' => $card->keywords_vi ?: $card->keywords_en,
        'meaning'     => $meaning,
        'is_reversed' => $is_rev,
    ] );
}

/* ---------------------------------------------------------------
 * Save reading to DB (frontend AJAX)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_save_reading',        'bct_ajax_save_reading' );
add_action( 'wp_ajax_nopriv_bct_save_reading', 'bct_ajax_save_reading' );
function bct_ajax_save_reading(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    if ( ! get_option( 'bct_save_readings', 1 ) ) {
        wp_send_json_success( [ 'saved' => false ] );
    }

    global $wpdb;
    $t          = bct_tables();
    $topic      = sanitize_text_field( wp_unslash( $_POST['topic']       ?? '' ) );
    $question   = sanitize_text_field( wp_unslash( $_POST['question']    ?? '' ) );
    $card_ids   = sanitize_text_field( wp_unslash( $_POST['card_ids']    ?? '' ) );
    $cards_json = wp_unslash( $_POST['cards_json'] ?? '' );
    $is_rev     = sanitize_text_field( wp_unslash( $_POST['is_reversed'] ?? '' ) );
    $session    = sanitize_text_field( wp_unslash( $_POST['session_id']  ?? '' ) );
    $client_id  = sanitize_text_field( wp_unslash( $_POST['client_id']   ?? '' ) );
    $bct_token  = sanitize_text_field( wp_unslash( $_POST['bct_token']   ?? '' ) );

    // Resolve user_id / client_id / platform từ token khi user là guest qua link bot
    $user_id  = get_current_user_id();
    $platform = '';
    if ( $bct_token && function_exists( 'bct_validate_chat_token' ) ) {
        $tok = bct_validate_chat_token( $bct_token );
        if ( $tok ) {
            if ( ! $user_id )   $user_id  = (int) ( $tok['wp_user_id'] ?? 0 );
            if ( ! $client_id ) $client_id = (string) ( $tok['client_id'] ?? '' );
            $platform = (string) ( $tok['platform'] ?? '' );
        }
    }

    $wpdb->insert( $t['readings'], [
        'user_id'     => $user_id ?: null,
        'client_id'   => $client_id,
        'platform'    => $platform,
        'session_id'  => $session ?: wp_generate_uuid4(),
        'topic'       => $topic,
        'question'    => $question,
        'card_ids'    => $card_ids,
        'cards_json'  => $cards_json,
        'is_reversed' => $is_rev,
    ] );

    wp_send_json_success( [ 'reading_id' => $wpdb->insert_id ] );
}

/* ---------------------------------------------------------------
 * Update reading with AI reply (called by JS after AI interpret)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_update_reading_ai',        'bct_ajax_update_reading_ai' );
add_action( 'wp_ajax_nopriv_bct_update_reading_ai', 'bct_ajax_update_reading_ai' );
function bct_ajax_update_reading_ai(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    $reading_id = intval( $_POST['reading_id'] ?? 0 );
    $ai_reply   = wp_unslash( $_POST['ai_reply'] ?? '' );

    if ( ! $reading_id || empty( $ai_reply ) ) {
        wp_send_json_error( 'Missing reading_id or ai_reply' );
    }

    global $wpdb;
    $t = bct_tables();

    // Verify ownership
    $user_id = get_current_user_id();
    $bct_token = sanitize_text_field( wp_unslash( $_POST['bct_token'] ?? '' ) );
    if ( ! $user_id && $bct_token && function_exists( 'bct_validate_chat_token' ) ) {
        $tok = bct_validate_chat_token( $bct_token );
        if ( $tok ) $user_id = (int) ( $tok['wp_user_id'] ?? 0 );
    }

    $reading = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, user_id FROM {$t['readings']} WHERE id = %d LIMIT 1",
        $reading_id
    ) );

    if ( ! $reading ) {
        wp_send_json_error( 'Reading not found' );
    }

    // Allow update if user owns the reading or reading has null user_id
    if ( $reading->user_id && (int) $reading->user_id !== $user_id ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $wpdb->update(
        $t['readings'],
        [ 'ai_reply' => $ai_reply ],
        [ 'id' => $reading_id ],
        [ '%s' ],
        [ '%d' ]
    );

    wp_send_json_success( [ 'updated' => true ] );
}

/* ---------------------------------------------------------------
 * Get a single reading detail (for "Xem luận giải" button)
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_get_reading', 'bct_ajax_get_reading' );
function bct_ajax_get_reading(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    $reading_id = intval( $_REQUEST['reading_id'] ?? 0 );
    if ( ! $reading_id ) {
        wp_send_json_error( 'Missing reading_id' );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( 'Login required' );
    }

    global $wpdb;
    $t = bct_tables();

    $reading = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$t['readings']} WHERE id = %d AND user_id = %d LIMIT 1",
        $reading_id,
        $user_id
    ), ARRAY_A );

    if ( ! $reading ) {
        wp_send_json_error( 'Reading not found' );
    }

    $cards_data = json_decode( $reading['cards_json'] ?? '[]', true );
    $reversed   = explode( ',', $reading['is_reversed'] ?? '' );

    $cards = [];
    if ( is_array( $cards_data ) ) {
        foreach ( $cards_data as $i => $card ) {
            $cards[] = [
                'name_vi'     => $card['name_vi'] ?? '',
                'name_en'     => $card['name_en'] ?? '',
                'is_reversed' => isset( $reversed[ $i ] ) && $reversed[ $i ] === '1',
            ];
        }
    }

    wp_send_json_success( [
        'id'         => (int) $reading['id'],
        'topic'      => $reading['topic'] ?? '',
        'question'   => $reading['question'] ?? '',
        'cards'      => $cards,
        'ai_reply'   => $reading['ai_reply'] ?? '',
        'created_at' => $reading['created_at'] ?? '',
    ] );
}

/* ---------------------------------------------------------------
 * AI Tarot Interpretation  (frontend AJAX)
 * Logged-in / bot-token → uses BizCity Chat Gateway với hồ sơ chiêm tinh + transit
 * Guest (no token)       → direct API call với prompt ngắn gọn
 * ------------------------------------------------------------- */
add_action( 'wp_ajax_bct_ai_interpret',        'bct_ajax_ai_interpret' );
add_action( 'wp_ajax_nopriv_bct_ai_interpret', 'bct_ajax_ai_interpret' );
function bct_ajax_ai_interpret(): void {
    check_ajax_referer( 'bct_pub_nonce', 'nonce' );

    $topic      = sanitize_text_field( wp_unslash( $_POST['topic']      ?? '' ) );
    $question   = sanitize_text_field( wp_unslash( $_POST['question']   ?? '' ) );
    $cards_json = wp_unslash( $_POST['cards_json'] ?? '[]' );
    $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ?? '' ) );
    $bct_token  = sanitize_text_field( wp_unslash( $_POST['bct_token']  ?? '' ) );

    $cards = json_decode( $cards_json, true );
    if ( ! is_array( $cards ) || empty( $cards ) ) {
        wp_send_json_error( 'No cards data' );
    }

    // ── Resolve WP user_id: logged-in → từ session; guest với token → từ token payload ──
    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id && $bct_token && function_exists( 'bct_validate_chat_token' ) ) {
        $tok_payload = bct_validate_chat_token( $bct_token );
        if ( $tok_payload ) {
            $wp_user_id = (int) ( $tok_payload['wp_user_id'] ?? 0 );
            // Switch to the correct blog so profile/transit lookups hit the right DB tables
            if ( is_multisite() ) {
                $tok_blog = (int) ( $tok_payload['blog_id'] ?? 0 );
                if ( $tok_blog && $tok_blog !== get_current_blog_id() ) {
                    switch_to_blog( $tok_blog );
                }
            }
        }
    }

    // ── Build card description text ──────────────────────────────
    $card_lines = [];
    foreach ( $cards as $i => $c ) {
        $pos  = $c['position_label'] ?? ( 'Lá số ' . ( $i + 1 ) );
        $name = $c['name_vi'] ?: ( $c['name_en'] ?? '' );
        $rev  = ! empty( $c['is_reversed'] ) ? '(Ngược)' : '(Thuận)';
        $kw   = $c['keywords'] ?? '';
        $card_lines[] = "- {$pos}: **{$name}** {$rev}" . ( $kw ? " – Từ khóa: {$kw}" : '' );
    }
    $cards_text     = implode( "\n", $card_lines );
    $topic_label    = $topic    ?: 'Tổng quát';
    $question_label = $question ?: 'Hướng đi trong cuộc sống';
    $today          = wp_date( 'd/m/Y' );
    $time_now       = wp_date( 'H:i' );

    // ── Fetch astro profile + transit (nếu user đã có hồ sơ) ──
    $profile_section = '';
    $transit_section = '';
    $has_astro       = false;

    if ( $wp_user_id && class_exists( 'BizCity_Profile_Context' ) ) {
        try {
            $pctx = BizCity_Profile_Context::instance();
            // Dùng synthetic message có chứa từ khoá trigger transit
            $synthetic_msg   = "bói bài tarot chiêm tinh chuyển dịch hôm nay {$today}";
            $profile_section = method_exists( $pctx, 'build_user_context' )
                ? $pctx->build_user_context( $wp_user_id, $session_id, 'WEBCHAT', [] )
                : '';
            $transit_section = method_exists( $pctx, 'build_transit_context' )
                ? $pctx->build_transit_context( $synthetic_msg, $wp_user_id, $session_id, 'WEBCHAT' )
                : '';
            $has_astro = ! empty( $profile_section );
        } catch ( \Throwable $e ) {
            error_log( '[BizCity Tarot] Profile context error: ' . $e->getMessage() );
        }
    }

    // Fallback: dùng bccm_get_user_astro_display_data nếu BizCity_Profile_Context không có
    if ( ! $has_astro && $wp_user_id && function_exists( 'bccm_get_user_astro_display_data' ) ) {
        $astro = bccm_get_user_astro_display_data( $wp_user_id );
        if ( $astro && ! empty( $astro['planets'] ) ) {
            $lines = [];
            $lines[] = "**Hồ sơ chiêm tinh:**";
            if ( $astro['full_name'] ) $lines[] = "- Họ tên: {$astro['full_name']}";
            if ( $astro['dob'] )       $lines[] = "- Ngày sinh: {$astro['dob']}, giờ: " . ( $astro['birth_time'] ?: '?' );
            if ( $astro['birth_place'] ) $lines[] = "- Nơi sinh: {$astro['birth_place']}";
            if ( $astro['sun_sign'] )  $lines[] = "- Mặt Trời (Sun): " . ( $astro['zodiac_vi'] ?: $astro['sun_sign'] );
            if ( $astro['moon_sign'] ) $lines[] = "- Mặt Trăng (Moon): {$astro['moon_sign']}";
            if ( $astro['asc_sign'] )  $lines[] = "- Ascendant: {$astro['asc_sign']}";
            $planet_lines = [];
            foreach ( $astro['planets'] as $p ) {
                if ( $p['name'] && $p['sign_vi'] ) {
                    $retro = $p['is_retro'] ? ' (Nghịch hành)' : '';
                    $planet_lines[] = "  • {$p['name_vi']} ({$p['name']}): {$p['sign_vi']}" . ( $p['degree'] ? " {$p['degree']}" : '' ) . $retro;
                }
            }
            if ( $planet_lines ) {
                $lines[] = "- Các hành tinh natal:";
                $lines = array_merge( $lines, $planet_lines );
            }
            $profile_section = implode( "\n", $lines );
            $has_astro = true;
        }
    }

    // ── Build 4-layer framework (shared) ────────────────────────
    $four_layer  = "## KHUNG GIẢI BÀI 4 TẦNG (BẮT BUỘC)\n";
    $four_layer .= "Mỗi trải bài phải được đọc qua 4 tầng — từ bề mặt đến chiều sâu tâm linh:\n\n";

    $four_layer .= "### 🃏 Tầng 1 — Ý nghĩa gốc của từng lá\n";
    $four_layer .= "Dựa trên hệ biểu tượng Rider–Waite và archetype Tarot. ";
    $four_layer .= "Mô tả hình ảnh trên lá bài: nhân vật đang làm gì, cầm gì, nhìn về đâu, bầu trời/nền phía sau ra sao. ";
    $four_layer .= "Giải thích ý nghĩa cốt lõi (xuôi vs ngược) một cách sống động, không khô khan.\n\n";

    $four_layer .= "### 🌙 Tầng 2 — Tâm lý ẩn của nhân vật trong trải bài\n";
    $four_layer .= "Lá bài này đang nói về trạng thái cảm xúc THẬT SỰ phía sau hành động bề ngoài là gì? ";
    $four_layer .= "Nhân vật trong lá bài đang sợ hãi, khao khát, chờ đợi, hay đang che giấu điều gì? ";
    $four_layer .= "Liên hệ trực tiếp cảm xúc này với ngữ cảnh/câu hỏi của user — khiến họ cảm thấy \"lá bài đang nói đúng về mình\".\n\n";

    $four_layer .= "### 🔥 Tầng 3 — Dòng chảy năng lượng giữa các lá (nếu ≥ 2 lá)\n";
    $four_layer .= "Đây là tầng \"phản ứng hóa học\" khi các lá bài ghép lại với nhau:\n";
    $four_layer .= "- Lá nào đang DẪN DẮT câu chuyện? (năng lượng chủ đạo)\n";
    $four_layer .= "- Lá nào đang CHẶN hoặc tạo xung đột nội tâm?\n";
    $four_layer .= "- Lá nào là KẾT QUẢ hoặc lối thoát?\n";
    $four_layer .= "- Mối quan hệ đan xen, mâu thuẫn cảm xúc giữa các lá: ví dụ \"The Lovers ngồi cạnh The Tower — trái tim đang mở ra nhưng nền móng cũ sắp sụp đổ, và chính sự sụp đổ đó lại là điều giải phóng bạn\".\n";
    $four_layer .= "- Nhấn mạnh: sự kết hợp này tạo ra một thông điệp ĐỘC NHẤT, chỉ xuất hiện trong trải bài này, dành riêng cho user.\n";
    $four_layer .= "- Nếu chỉ 1 lá: tập trung vào ĐỐI THOẠI giữa phần sáng và phần tối của cùng một lá bài.\n\n";

    $four_layer .= "### 💎 Tầng 4 — Thông điệp sâu / Bài học nghiệp\n";
    $four_layer .= "Đây là tầng mà reader 20 năm kinh nghiệm thường nhìn — tầng tâm linh:\n";
    $four_layer .= "- Vũ trụ đang muốn nhắn nhủ điều gì qua trải bài này?\n";
    $four_layer .= "- Có bài học nghiệp (karmic lesson) nào đang lặp lại không?\n";
    $four_layer .= "- Cánh cửa nào đang mở ra nếu user dám bước qua?\n";
    $four_layer .= "- Viết như một lời tiên tri nhẹ nhàng nhưng đầy sức nặng.\n\n";

    $four_layer .= "## NGUYÊN TẮC CỐT LÕI\n";
    $four_layer .= "- **Nhắm vào điều user muốn nghe nhất**: Dựa trên câu hỏi và ngữ cảnh, xác định nỗi lo hoặc khao khát lớn nhất của user. ";
    $four_layer .= "Hướng kết luận về phía khẳng định rằng user có một vị trí đặc biệt, một ý nghĩa quan trọng trong tình huống này — ";
    $four_layer .= "bài Tarot đang xác nhận điều đó.\n";
    $four_layer .= "- **Mỗi trải bài là một câu chuyện**: Không liệt kê khô khan — hãy DỆT các lá bài thành một narrative liền mạch. ";
    $four_layer .= "Bắt đầu bằng một câu mở huyền bí, kết thúc bằng lời nhắn gửi lay động.\n";
    $four_layer .= "- **Lời khuyên xây dựng**: Kết luận luôn tích cực, trao quyền — cho user cảm giác rằng họ nắm trong tay chìa khóa thay đổi. ";
    $four_layer .= "Dù bài xấu, hãy chỉ ra ánh sáng cuối đường hầm.\n";
    $four_layer .= "- **Giọng văn**: Thì thầm, bí ẩn, thi vị — xen kẽ câu ngắn đầy sức nặng với đoạn diễn giải giàu hình ảnh. ";
    $four_layer .= "Tránh giọng sách giáo khoa.\n";

    // ── Build prompt ─────────────────────────────────────────────
    if ( $has_astro ) {
        // Prompt chi tiết kết hợp chiêm tinh + 4-layer framework
        $prompt  = "## VAI TRÒ\n";
        $prompt .= "Bạn là một Tarot Reader với 20 năm kinh nghiệm kết hợp chiêm tinh học, ";
        $prompt .= "người đọc bài bằng trực giác sâu thẳm và sự thấu cảm phi thường. ";
        $prompt .= "Giọng văn của bạn: thần bí, thi vị, giàu hình ảnh ẩn dụ — như đang thì thầm bí mật của vũ trụ vào tai người nghe. ";
        $prompt .= "Bạn không chỉ đọc bài — bạn kể một câu chuyện mà người hỏi là nhân vật chính. ";
        $prompt .= "Luận giải CÁ NHÂN HOÁ theo natal chart và transit thực tế.\n\n";

        $prompt .= "**Chủ đề trải bài:** {$topic_label}\n";
        $prompt .= "**Câu hỏi:** {$question_label}\n";
        $prompt .= "**Thời điểm bốc bài:** {$today} lúc {$time_now}\n\n";

        $prompt .= "---\n{$profile_section}\n---\n\n";

        if ( $transit_section ) {
            $prompt .= "**TRANSIT CHIÊM TINH HIỆN TẠI ({$today}):**\n{$transit_section}\n\n---\n\n";
        }

        $prompt .= "**LÁ BÀI ĐÃ RÚT:**\n{$cards_text}\n\n";

        $prompt .= "---\n";
        $prompt .= $four_layer . "\n";

        $prompt .= "## YÊU CẦU BỔ SUNG CHIÊM TINH – CHO MỖI LÁ BÀI:\n\n";

        foreach ( $cards as $i => $c ) {
            $pos  = $c['position_label'] ?? ( 'Lá số ' . ( $i + 1 ) );
            $name = $c['name_vi'] ?: ( $c['name_en'] ?? '' );
            $rev  = ! empty( $c['is_reversed'] ) ? '(Ngược)' : '(Thuận)';
            $prompt .= "**{$pos}: {$name} {$rev}**\n";
            $prompt .= "⭐ *Dưới góc nhìn natal chart:* [nêu TÊN SAO + CUNG cụ thể trong natal cộng hưởng với lá bài này]\n";
            if ( $transit_section ) {
                $prompt .= "🌍 *Transit hôm nay:* [sao transit nào đang ở cung nào, tạo góc chiếu gì với natal, tăng cường hay thách thức thông điệp lá bài]\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "⚠️ BẮT BUỘC: Luận giải PHẢI đi qua đủ 4 tầng; ";
        $prompt .= "nêu TÊN SAO + CUNG cụ thể từ natal; ";
        $prompt .= "dùng dữ liệu transit thực tế đã cung cấp; ";
        $prompt .= "DỆT thành câu chuyện liền mạch, KHÔNG liệt kê khô khan.";

        $max_tokens_gateway = 2400;
    } else {
        // Prompt 4-layer cho user chưa có hồ sơ chiêm tinh
        $prompt  = "## VAI TRÒ\n";
        $prompt .= "Bạn là một Tarot Reader với 20 năm kinh nghiệm, người đọc bài bằng trực giác sâu thẳm và sự thấu cảm phi thường. ";
        $prompt .= "Giọng văn của bạn: thần bí, thi vị, giàu hình ảnh ẩn dụ — như đang thì thầm bí mật của vũ trụ vào tai người nghe. ";
        $prompt .= "Bạn không chỉ đọc bài — bạn kể một câu chuyện mà người hỏi là nhân vật chính.\n\n";

        $prompt .= "**Chủ đề trải bài:** {$topic_label}\n";
        $prompt .= "**Câu hỏi:** {$question_label}\n";
        $prompt .= "**Thời điểm bốc bài:** {$today} lúc {$time_now}\n\n";

        $prompt .= "**LÁ BÀI ĐÃ RÚT:**\n{$cards_text}\n\n";

        $prompt .= "---\n";
        $prompt .= $four_layer . "\n";

        $prompt .= "⚠️ BẮT BUỘC: Luận giải PHẢI đi qua đủ 4 tầng; ";
        $prompt .= "DỆT thành câu chuyện liền mạch, KHÔNG liệt kê khô khan; ";
        $prompt .= "bắt đầu bằng câu mở huyền bí, kết thúc bằng lời nhắn gửi lay động.";

        $max_tokens_gateway = 1800;
    }

    // ── Gọi Chat Gateway (ưu tiên – có profile/transit trong system prompt) ──
    if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
        try {
            $default_character_id = (int) get_option( 'bizcity_default_character_id', 0 );
            if ( ! $default_character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
                $db  = BizCity_Knowledge_Database::instance();
                $all = method_exists( $db, 'get_all_characters' ) ? $db->get_all_characters() : [];
                if ( ! empty( $all ) ) $default_character_id = (int) $all[0]->id;
            }

            if ( $default_character_id ) {
                $gw = BizCity_Chat_Gateway::instance();
                if ( ! method_exists( $gw, 'get_ai_response' ) ) {
                    throw new \RuntimeException( 'get_ai_response method not found' );
                }
                // Dùng session prefix 'tarot_' để gateway KHÔNG mang lịch sử chat cũ vào context
                $tarot_session = 'tarot_' . ( $session_id ?: wp_generate_uuid4() );
                $res = $gw->get_ai_response(
                    $default_character_id,
                    $prompt,
                    [],                 // images: rỗng (prompt đã chứa đủ context)
                    $tarot_session,
                    '[]',               // history: không dùng lịch sử chat
                    $wp_user_id,        // user_id đã resolve (kể cả từ token)
                    'WEBCHAT'
                );
                wp_send_json_success( [
                    'reply'     => $res['message'] ?? '',
                    'via'       => 'gateway',
                    'has_astro' => $has_astro,
                ] );
            }
        } catch ( \Throwable $e ) {
            error_log( '[BizCity Tarot] Chat Gateway fallback: ' . $e->getMessage() );
        }
    }

    // ── Fallback: Use BizCity OpenRouter mu-plugin ──
    if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
        wp_send_json_error( 'BizCity OpenRouter chưa được cài đặt.' );
    }

    $system_msg = $has_astro
        ? 'Bạn là Tarot Reader 20 năm kinh nghiệm kết hợp chiêm tinh học. Giọng văn: thần bí, thi vị, giàu hình ảnh ẩn dụ. Luận giải CÁ NHÂN HOÁ theo natal chart + transit, nêu TÊN SAO + CUNG cụ thể. DỆT thành câu chuyện qua 4 tầng: ý nghĩa gốc → tâm lý ẩn → dòng chảy năng lượng → thông điệp tâm linh. Tiếng Việt.'
        : 'Bạn là Tarot Reader 20 năm kinh nghiệm. Giọng văn: thần bí, thi vị, giàu hình ảnh ẩn dụ — thì thầm bí mật của vũ trụ. DỆT lá bài thành câu chuyện qua 4 tầng: ý nghĩa gốc → tâm lý ẩn → dòng chảy năng lượng → thông điệp tâm linh. Kết luận tích cực, trao quyền. Tiếng Việt.';

    $or_result = bizcity_openrouter_chat(
        [
            [ 'role' => 'system', 'content' => $system_msg ],
            [ 'role' => 'user',   'content' => $prompt ],
        ],
        [
            'purpose'    => 'chat',
            'max_tokens' => $has_astro ? 2400 : 1800,
        ]
    );

    if ( empty( $or_result['success'] ) || empty( $or_result['message'] ) ) {
        wp_send_json_error( $or_result['error'] ?? 'No reply from AI' );
    }

    wp_send_json_success( [
        'reply'     => $or_result['message'],
        'via'       => 'openrouter',
        'model'     => $or_result['model'] ?? '',
        'has_astro' => $has_astro,
    ] );
}
