<?php
/**
 * BizCity LLM — Model Registry
 *
 * Curated catalog of popular models grouped by purpose.
 * Identical to the old BizCity_OpenRouter_Models but with new class name.
 *
 * @package BizCity_LLM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_LLM_Models {

    /* ── Curated model catalog ── */
    const CATALOG = [

        /* ─ Chat / General ─ */
        'chat' => [
            [ 'id' => 'anthropic/claude-sonnet-4',             'name' => 'Claude Sonnet 4',                'ctx' => 200000 ],
            [ 'id' => 'anthropic/claude-3.5-sonnet',           'name' => 'Claude 3.5 Sonnet',              'ctx' => 200000 ],
            [ 'id' => 'anthropic/claude-3.5-haiku',            'name' => 'Claude 3.5 Haiku',               'ctx' => 200000 ],
            [ 'id' => 'openai/gpt-4o',                         'name' => 'GPT-4o',                         'ctx' => 128000 ],
            [ 'id' => 'openai/gpt-4o-mini',                    'name' => 'GPT-4o mini',                    'ctx' => 128000 ],
            [ 'id' => 'google/gemini-2.5-flash',               'name' => 'Gemini 2.5 Flash',               'ctx' => 1048576 ],
            [ 'id' => 'google/gemini-2.5-pro',                 'name' => 'Gemini 2.5 Pro',                 'ctx' => 1048576 ],
            [ 'id' => 'google/gemini-2.0-flash-001',           'name' => 'Gemini 2.0 Flash',               'ctx' => 1048576 ],
            [ 'id' => 'deepseek/deepseek-chat',                'name' => 'DeepSeek Chat',                  'ctx' => 163840  ],
            [ 'id' => 'deepseek/deepseek-r1',                  'name' => 'DeepSeek R1',                    'ctx' => 163840  ],
            [ 'id' => 'qwen/qwen-2.5-72b-instruct',           'name' => 'Qwen 2.5 72B Instruct',          'ctx' => 131072  ],
            [ 'id' => 'mistralai/mistral-large',               'name' => 'Mistral Large',                  'ctx' => 131072  ],
            [ 'id' => 'meta-llama/llama-3.3-70b-instruct',    'name' => 'Llama 3.3 70B Instruct',         'ctx' => 131072  ],
        ],

        /* ─ Vision ─ */
        'vision' => [
            [ 'id' => 'anthropic/claude-sonnet-4',             'name' => 'Claude Sonnet 4 (Vision)',       'ctx' => 200000 ],
            [ 'id' => 'anthropic/claude-3.5-sonnet',           'name' => 'Claude 3.5 Sonnet (Vision)',     'ctx' => 200000 ],
            [ 'id' => 'openai/gpt-4o',                         'name' => 'GPT-4o (Vision)',                'ctx' => 128000 ],
            [ 'id' => 'google/gemini-2.5-flash',               'name' => 'Gemini 2.5 Flash (Vision)',      'ctx' => 1048576 ],
            [ 'id' => 'google/gemini-2.0-flash-001',           'name' => 'Gemini 2.0 Flash (Vision)',      'ctx' => 1048576 ],
            [ 'id' => 'meta-llama/llama-3.2-90b-vision-instruct', 'name' => 'Llama 3.2 90B Vision',       'ctx' => 131072 ],
        ],

        /* ─ Code ─ */
        'code' => [
            [ 'id' => 'anthropic/claude-sonnet-4',             'name' => 'Claude Sonnet 4',                'ctx' => 200000 ],
            [ 'id' => 'anthropic/claude-3.5-sonnet',           'name' => 'Claude 3.5 Sonnet',              'ctx' => 200000 ],
            [ 'id' => 'openai/gpt-4o',                         'name' => 'GPT-4o',                         'ctx' => 128000 ],
            [ 'id' => 'deepseek/deepseek-coder',               'name' => 'DeepSeek Coder',                 'ctx' => 16000  ],
            [ 'id' => 'qwen/qwen-2.5-coder-32b-instruct',     'name' => 'Qwen 2.5 Coder 32B',             'ctx' => 131072 ],
        ],

        /* ─ Fast / Low-latency ─ */
        'fast' => [
            [ 'id' => 'anthropic/claude-3.5-haiku',            'name' => 'Claude 3.5 Haiku',               'ctx' => 200000 ],
            [ 'id' => 'openai/gpt-4o-mini',                    'name' => 'GPT-4o mini',                    'ctx' => 128000 ],
            [ 'id' => 'google/gemini-2.5-flash',               'name' => 'Gemini 2.5 Flash',               'ctx' => 1048576 ],
            [ 'id' => 'google/gemini-2.0-flash-001',           'name' => 'Gemini 2.0 Flash',               'ctx' => 1048576 ],
            [ 'id' => 'deepseek/deepseek-chat',                'name' => 'DeepSeek Chat',                  'ctx' => 163840  ],
        ],

        /* ─ Router / Intent Classification ─ */
        'router' => [
            [ 'id' => 'google/gemini-2.5-flash',               'name' => 'Gemini 2.5 Flash (Router)',      'ctx' => 1048576 ],
            [ 'id' => 'openai/gpt-4o-mini',                    'name' => 'GPT-4o mini (Router)',            'ctx' => 128000 ],
            [ 'id' => 'anthropic/claude-3.5-haiku',            'name' => 'Claude 3.5 Haiku (Router)',       'ctx' => 200000 ],
        ],

        /* ─ Planner / Slot Filling ─ */
        'planner' => [
            [ 'id' => 'google/gemini-2.5-pro',                 'name' => 'Gemini 2.5 Pro (Planner)',        'ctx' => 1048576 ],
            [ 'id' => 'anthropic/claude-sonnet-4',             'name' => 'Claude Sonnet 4 (Planner)',       'ctx' => 200000 ],
            [ 'id' => 'openai/gpt-4o',                         'name' => 'GPT-4o (Planner)',                'ctx' => 128000 ],
        ],

        /* ─ Executor / Compose ─ */
        'executor' => [
            [ 'id' => 'google/gemini-2.5-flash',               'name' => 'Gemini 2.5 Flash (Executor)',    'ctx' => 1048576 ],
            [ 'id' => 'google/gemini-2.5-pro',                 'name' => 'Gemini 2.5 Pro (Executor)',      'ctx' => 1048576 ],
            [ 'id' => 'anthropic/claude-sonnet-4',             'name' => 'Claude Sonnet 4 (Executor)',     'ctx' => 200000 ],
        ],

        /* ─ Free-tier ─ */
        'free' => [
            [ 'id' => 'google/gemini-2.0-flash-exp:free',      'name' => 'Gemini 2.0 Flash (Free)',        'ctx' => 1048576 ],
            [ 'id' => 'meta-llama/llama-3.3-70b-instruct:free','name' => 'Llama 3.3 70B (Free)',           'ctx' => 131072  ],
            [ 'id' => 'deepseek/deepseek-chat:free',           'name' => 'DeepSeek Chat (Free)',           'ctx' => 163840  ],
        ],

        /* ─ Embedding ─ */
        'embedding' => [
            [ 'id' => 'openai/text-embedding-3-small',         'name' => 'Text Embedding 3 Small',         'ctx' => 8191,  'dim' => 1536 ],
            [ 'id' => 'openai/text-embedding-3-large',         'name' => 'Text Embedding 3 Large',         'ctx' => 8191,  'dim' => 3072 ],
            [ 'id' => 'openai/text-embedding-ada-002',         'name' => 'Text Embedding Ada 002',         'ctx' => 8191,  'dim' => 1536 ],
        ],
    ];

    /* ── Default PRIMARY model IDs per purpose ── */
    const DEFAULTS = [
        'chat'         => 'google/gemini-2.5-flash',
        'vision'       => 'google/gemini-2.0-flash-001',
        'code'         => 'google/gemini-2.5-flash',
        'fast'         => 'google/gemini-2.5-flash',
        'router'       => 'google/gemini-2.5-flash',
        'planner'      => 'google/gemini-2.5-pro',
        'executor'     => 'google/gemini-2.5-flash',
        'slot_extract' => 'google/gemini-2.5-flash',
        'slide'        => 'google/gemini-2.5-flash',
        'embedding'    => 'openai/text-embedding-3-small',
    ];

    /* ── Default FALLBACK model IDs per purpose ── */
    const FALLBACK_DEFAULTS = [
        'chat'         => 'anthropic/claude-sonnet-4',
        'vision'       => 'anthropic/claude-sonnet-4',
        'code'         => 'anthropic/claude-sonnet-4',
        'fast'         => 'anthropic/claude-sonnet-4',
        'router'       => 'anthropic/claude-sonnet-4',
        'planner'      => 'anthropic/claude-sonnet-4',
        'executor'     => 'anthropic/claude-sonnet-4',
        'slot_extract' => 'anthropic/claude-sonnet-4',
        'slide'        => 'anthropic/claude-sonnet-4',
        'embedding'    => 'openai/text-embedding-ada-002',
    ];

    /**
     * Return all models for a given purpose category.
     */
    public static function get( ?string $category = null ): array {
        if ( $category && isset( self::CATALOG[ $category ] ) ) {
            return self::CATALOG[ $category ];
        }
        $all = [];
        foreach ( self::CATALOG as $models ) {
            foreach ( $models as $m ) {
                $all[ $m['id'] ] = $m;
            }
        }
        return array_values( $all );
    }

    /**
     * Return all purpose keys.
     */
    public static function purposes(): array {
        return array_keys( self::CATALOG );
    }

    /**
     * Check whether a model supports vision input.
     */
    public static function supports_vision( string $model_id ): bool {
        foreach ( self::CATALOG['vision'] as $m ) {
            if ( $m['id'] === $model_id ) return true;
        }
        $patterns = [ 'vision', 'claude-3', 'claude-sonnet-4', 'gpt-4o', 'gemini', 'llava', 'pixtral' ];
        foreach ( $patterns as $p ) {
            if ( strpos( $model_id, $p ) !== false ) return true;
        }
        return false;
    }

    /**
     * Find a model's display name from catalog.
     */
    public static function get_label( string $model_id ): string {
        foreach ( self::CATALOG as $models ) {
            foreach ( $models as $m ) {
                if ( $m['id'] === $model_id ) return $m['name'];
            }
        }
        return $model_id;
    }
}

/* ── Backward-compat class alias ── */
if ( ! class_exists( 'BizCity_OpenRouter_Models' ) ) {
    class_alias( 'BizCity_LLM_Models', 'BizCity_OpenRouter_Models' );
}
