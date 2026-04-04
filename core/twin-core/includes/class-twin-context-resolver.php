<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Twin Context Resolver (local runtime implementation).
 *
 * Single entry point used by Chat Gateway and Intent Stream in Twin AI.
 * It keeps compatibility with existing local flow while reducing scattered
 * prompt assembly logic.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Context_Resolver', false ) ) {
    return;
}

class BizCity_Twin_Context_Resolver {

    /**
     * Build full system prompt text for a mode.
     */
    public static function build_system_prompt( string $mode, array $ctx = [] ): string {
        $bundle = self::build_prompt_bundle( $mode, $ctx );
        return (string) ( $bundle['system_content'] ?? '' );
    }

    /**
     * Compatibility helper for chat consumers that need context slices.
     */
    public static function for_chat( array $ctx = [] ): array {
        return self::build_prompt_bundle( 'chat', $ctx );
    }

    /**
     * Build unified prompt bundle (prompt + contextual slices).
     */
    public static function build_prompt_bundle( string $mode, array $ctx = [] ): array {
        $mode = $mode ?: 'chat';

        $user_id        = (int) ( $ctx['user_id'] ?? 0 );
        $session_id     = (string) ( $ctx['session_id'] ?? '' );
        $message        = (string) ( $ctx['message'] ?? '' );
        $character_id   = (int) ( $ctx['character_id'] ?? 0 );
        $platform_type  = (string) ( $ctx['platform_type'] ?? '' );
        $images         = is_array( $ctx['images'] ?? null ) ? $ctx['images'] : [];
        $engine_result  = is_array( $ctx['engine_result'] ?? null ) ? $ctx['engine_result'] : [];
        $effective_platform = $platform_type !== '' ? $platform_type : 'WEBCHAT';
        $channel_role = $ctx['channel_role'] ?? [];

        if ( class_exists( 'BizCity_Focus_Gate' ) ) {
            BizCity_Focus_Gate::ensure_resolved( $message, [
                'mode'           => $engine_result['meta']['mode'] ?? $mode,
                'platform_type'  => $effective_platform,
                'routing_branch' => $engine_result['action'] ?? '',
                'active_goal'    => $engine_result['goal'] ?? '',
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'images'         => $images,
                'channel_role'   => $channel_role,
                'context'        => $ctx,
            ] );
            BizCity_Focus_Gate::amend_for_goal( $engine_result['goal'] ?? '' );
        }

        $character = null;
        if ( $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
        }

        $system_content = ( $character && ! empty( $character->system_prompt ) )
            ? (string) $character->system_prompt
            : 'Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời bằng tiếng Việt.';

        $profile_context = '';
        $transit_context = '';
        if ( class_exists( 'BizCity_Profile_Context' ) ) {
            $can_inject_profile = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'profile' );
            if ( $can_inject_profile ) {
                $profile_ctx_inst = BizCity_Profile_Context::instance();
                $profile_context  = (string) $profile_ctx_inst->build_user_context(
                    $user_id ?: get_current_user_id(),
                    $session_id,
                    $effective_platform,
                    [ 'coach_type' => '' ]
                );

                $can_inject_transit = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'transit' );
                if ( $can_inject_transit ) {
                    $transit_context = (string) $profile_ctx_inst->build_transit_context(
                        $message,
                        $user_id ?: get_current_user_id(),
                        $session_id,
                        $effective_platform,
                        (string) ( $engine_result['goal'] ?? '' )
                    );
                }
            }
        }

        if ( $profile_context !== '' ) {
            $system_content .= "\n\n" . $profile_context;
        }
        if ( $transit_context !== '' ) {
            $system_content .= "\n\n" . $transit_context;
        }

        // ── Context Layers Capture: start recording (Phase 1.6) ──
        $capture_active = class_exists( 'BizCity_Context_Layers_Capture' )
            && class_exists( 'BizCity_Session_Memory_Spec' )
            && BizCity_Session_Memory_Spec::is_enabled();
        if ( $capture_active ) {
            BizCity_Context_Layers_Capture::start();
            if ( $profile_context !== '' ) {
                BizCity_Context_Layers_Capture::record( 'profile', $profile_context, array( 'priority' => 0, 'source' => 'twin_resolver' ) );
            }
            if ( $transit_context !== '' ) {
                BizCity_Context_Layers_Capture::record( 'transit', $transit_context, array( 'priority' => 0, 'source' => 'twin_resolver', 'gated_by' => 'focus_gate' ) );
            }
        }

        if ( ! empty( $engine_result['meta']['system_instructions'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['system_instructions'];
        }
        if ( ! empty( $engine_result['meta']['provider_context'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['provider_context'];
        }

        // ── Knowledge RAG Context (v4.9.4) ──
        // Registered as one-shot filter at priority 95 (AFTER Skill Context at 93)
        // so skills define HOW to do things, knowledge supplies business data.
        $knowledge_context = '';
        $can_inject_knowledge = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'knowledge' );
        if ( $can_inject_knowledge && class_exists( 'BizCity_Knowledge_Context_API' ) && $character_id > 0 ) {
            $k_char_id = $character_id;
            $k_message = $message;
            $k_images  = $images;
            add_filter( 'bizcity_chat_system_prompt', function ( $prompt, $args ) use ( $k_char_id, $k_message, $k_images, &$knowledge_context ) {
                $knowledge_result  = BizCity_Knowledge_Context_API::instance()->build_context(
                    $k_char_id,
                    $k_message,
                    [ 'images' => $k_images ]
                );
                $knowledge_context = $knowledge_result['context'] ?? '';
                if ( $knowledge_context !== '' ) {
                    $prompt .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
                }
                return $prompt;
            }, 95, 2 );
        }

        $filter_args = [
            'mode'             => $engine_result['meta']['mode'] ?? $mode,
            'character_id'     => $character_id,
            'message'          => $message,
            'user_id'          => $user_id,
            'session_id'       => $session_id,
            'platform_type'    => $effective_platform,
            'images'           => $images,
            'engine_result'    => $engine_result,
            'kci_ratio'        => (int) ( $ctx['kci_ratio'] ?? 80 ),
            'mention_override' => ! empty( $ctx['mention_override'] ),
            'channel_role'     => $channel_role,
            'via'              => $ctx['via'] ?? 'twin_resolver',
        ];

        $system_content = (string) apply_filters( 'bizcity_chat_system_prompt', $system_content, $filter_args );

        // ── Phase 1.6: Fire system_prompt_built action for observability ──
        $bundle = [
            'system_content'     => $system_content,
            'character'          => $character,
            'profile_context'    => $profile_context,
            'transit_context'    => $transit_context,
            'knowledge_context'  => $knowledge_context,
            'memory_context'     => '',
            'effective_platform' => $effective_platform,
        ];

        do_action( 'bizcity_system_prompt_built', $system_content, $filter_args, $bundle );

        // §20 C2 fix: Removed inline on_prompt_built() call.
        // Bootstrap hook (bizcity_system_prompt_built @10) already handles it.
        // Duplicate call was no-op (safe) but confusing.

        return $bundle;
    }
}
