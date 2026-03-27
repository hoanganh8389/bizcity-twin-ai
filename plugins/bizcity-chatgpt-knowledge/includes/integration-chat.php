<?php
/**
 * Integration Chat — Hook into webchat pipeline for ChatGPT knowledge.
 *
 * @package BizCity_ChatGPT_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   KNOWLEDGE DETECTION — For analytics & logging
   ═══════════════════════════════════════════════ */

function bzck_is_knowledge_query( $message ) {
    return (bool) preg_match(
        '/t[ìi]m\s*hi[ểe]u|nghi[êe]n\s*c[ứu]u|ph[âa]n\s*t[íi]ch|so\s*s[áa]nh'
        . '|gi[ảa]i\s*th[íi]ch|l[àa]\s*g[ìi]|t[ạa]i\s*sao|nh[ưu]\s*th[ếe]\s*n[àa]o'
        . '|c[áa]ch\s*n[àa]o|b[ằa]ng\s*c[áa]ch|[đd][ịi]nh\s*ngh[ĩi]a|kh[áa]i\s*ni[ệe]m'
        . '|l[ịi]ch\s*s[ửu]|ngu[ồo]n\s*g[ốo]c|th[ốo]ng\s*tin|t[ìi]m\s*ki[ếe]m'
        . '|h[ưu][ớo]ng\s*d[ẫa]n|chi\s*ti[ếe]t|c[ụu]\s*th[ểe]/ui',
        $message
    );
}

/* ═══════════════════════════════════════════════
   POST-RESPONSE HOOKS
   ═══════════════════════════════════════════════ */

add_filter( 'bizcity_chat_post_response', function( $response, $ctx ) {
    $pipeline = $ctx['meta']['pipeline'] ?? '';
    if ( $pipeline !== 'chatgpt-knowledge' ) {
        return $response;
    }

    if ( ! isset( $response['meta'] ) ) {
        $response['meta'] = [];
    }
    $response['meta']['powered_by']       = 'OpenAI ChatGPT';
    $response['meta']['knowledge_plugin']  = true;

    return $response;
}, 10, 2 );

/* ═══════════════════════════════════════════════
   SECURE TOKEN SYSTEM
   ═══════════════════════════════════════════════ */

function bzck_create_token( $user_id, $query ) {
    $payload = [
        'user_id'    => $user_id,
        'query'      => mb_substr( $query, 0, 200 ),
        'created_at' => time(),
    ];
    $token = substr( hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) ), 0, 20 );
    set_site_transient( 'bzck_token_' . $token, $payload, 2 * HOUR_IN_SECONDS );
    return $token;
}

function bzck_verify_token( $token ) {
    if ( empty( $token ) ) return false;
    $payload = get_site_transient( 'bzck_token_' . $token );
    if ( ! $payload ) return false;
    delete_site_transient( 'bzck_token_' . $token );
    return $payload;
}
