<?php
/**
 * Integration Chat — Hook into webchat pipeline for Gemini knowledge.
 *
 * Unlike calo (execution-mode agent), this plugin's primary integration
 * is through the Knowledge Pipeline override (class-gemini-knowledge-pipeline.php).
 *
 * This file provides supplementary hooks:
 *  - Knowledge keyword detection (for logging/analytics)
 *  - Post-response hooks (bookmark suggestions, follow-up prompts)
 *
 * @package BizCity_Gemini_Knowledge
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   KNOWLEDGE DETECTION — For analytics & logging
   ═══════════════════════════════════════════════ */

/**
 * Check if a message is a knowledge query.
 */
function bzgk_is_knowledge_query( $message ) {
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

/**
 * After knowledge pipeline response, add follow-up suggestions.
 *
 * Hooks into the chat gateway to append follow-up prompts
 * when the response comes from gemini-knowledge pipeline.
 */
add_filter( 'bizcity_chat_post_response', function( $response, $ctx ) {
    // Only modify gemini-knowledge pipeline responses
    $pipeline = $ctx['meta']['pipeline'] ?? '';
    if ( $pipeline !== 'gemini-knowledge' ) {
        return $response;
    }

    // Add metadata for UI to show "powered by Gemini"
    if ( ! isset( $response['meta'] ) ) {
        $response['meta'] = [];
    }
    $response['meta']['powered_by']       = 'Google Gemini';
    $response['meta']['knowledge_plugin']  = true;

    return $response;
}, 10, 2 );

/* ═══════════════════════════════════════════════
   SECURE TOKEN SYSTEM
   (For external API calls if needed)
   ═══════════════════════════════════════════════ */

function bzgk_create_token( $user_id, $query ) {
    $payload = [
        'user_id'    => $user_id,
        'query'      => mb_substr( $query, 0, 200 ),
        'created_at' => time(),
    ];
    $token = substr( hash_hmac( 'sha256', wp_json_encode( $payload ), wp_salt( 'auth' ) ), 0, 20 );
    set_site_transient( 'bzgk_token_' . $token, $payload, 2 * HOUR_IN_SECONDS );
    return $token;
}

function bzgk_verify_token( $token ) {
    if ( empty( $token ) ) return false;
    $payload = get_site_transient( 'bzgk_token_' . $token );
    if ( ! $payload ) return false;
    // Token is single-use
    delete_site_transient( 'bzgk_token_' . $token );
    return $payload;
}
