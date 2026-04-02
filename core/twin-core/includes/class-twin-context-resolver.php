<?php
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

        if ( $profile_context !== '' ) {
            $system_content .= "\n\n" . $profile_context;
        }
        if ( $transit_context !== '' ) {
            $system_content .= "\n\n" . $transit_context;
        }

        if ( ! empty( $engine_result['meta']['system_instructions'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['system_instructions'];
        }
        if ( ! empty( $engine_result['meta']['provider_context'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['provider_context'];
        }

        $system_content = (string) apply_filters( 'bizcity_chat_system_prompt', $system_content, [
            'mode'             => $mode,
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
        ] );

        return [
            'system_content'     => $system_content,
            'character'          => $character,
            'profile_context'    => $profile_context,
            'transit_context'    => $transit_context,
            'knowledge_context'  => '',
            'memory_context'     => '',
            'effective_platform' => $effective_platform,
        ];
    }
}
