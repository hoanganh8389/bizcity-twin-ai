<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Confirm Analyzer — LLM-based confirmation response analysis.
 *
 * Replaces the binary regex "ok / not-ok" confirm check with a 3-tier
 * analysis that understands user intent + slot enrichment.
 *
 * Tier 1: Fast regex path for simple confirmations (no LLM cost).
 * Tier 2: Fast regex path for rejections (no LLM cost).
 * Tier 3: LLM analysis for ambiguous responses — determines intent AND
 *         extracts slot updates (corrections, enrichments, new fills).
 *
 * 5 Intent Types:
 *   - accept        → Execute as-is (e.g. "ok", "được", "tiếp tục")
 *   - accept_modify  → Apply changes AND execute (e.g. enrichment, "ok nhưng thêm X")
 *   - modify         → Apply changes, re-show confirm (e.g. "sửa X thành Y")
 *   - reject         → Cancel/abandon (e.g. "hủy", "thôi", "không")
 *   - new_goal       → User switched topic entirely
 *
 * @package BizCity_Intent
 * @since   4.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Confirm_Analyzer {

    const INTENT_ACCEPT        = 'accept';
    const INTENT_ACCEPT_MODIFY = 'accept_modify';
    const INTENT_MODIFY        = 'modify';
    const INTENT_REJECT        = 'reject';
    const INTENT_NEW_GOAL      = 'new_goal';

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Analyze user's response to a confirmation prompt.
     *
     * @param string $message      User's raw message.
     * @param array  $conversation Conversation data with 'goal', 'slots', etc.
     * @param object $planner      BizCity_Intent_Planner instance.
     * @return array {
     *     @type string $intent       One of the INTENT_* constants.
     *     @type array  $slot_updates Key-value pairs of slot changes to apply.
     *     @type string $method       Detection method for logging.
     * }
     */
    public function analyze( string $message, array $conversation, $planner ): array {
        $default = [
            'intent'       => self::INTENT_ACCEPT,
            'slot_updates' => [],
            'method'       => '',
        ];

        $trimmed = mb_strtolower( trim( $message ), 'UTF-8' );

        // ── Tier 1: Fast accept (regex, 0 cost) ──
        if ( $this->is_fast_accept( $trimmed ) ) {
            $default['method'] = 'fast_accept';
            return $default;
        }

        // ── Tier 2: Fast reject (regex, 0 cost) ──
        if ( $this->is_fast_reject( $trimmed ) ) {
            return [
                'intent'       => self::INTENT_REJECT,
                'slot_updates' => [],
                'method'       => 'fast_reject',
            ];
        }

        // ── Tier 3: LLM analysis ──
        return $this->llm_analyze( $message, $conversation, $planner );
    }

    /**
     * Fast-path confirmation detection. No LLM call needed.
     */
    private function is_fast_accept( string $lower ): bool {
        $phrases = [
            'ok', 'oke', 'okie', 'okay', 'yes', 'có', 'đồng ý', 'thực hiện',
            'bắt đầu', 'làm đi', 'được', 'chạy', 'go', 'xác nhận', 'confirm',
            'tiếp tục', 'tiếp', 'đúng rồi', 'đúng', 'rồi', 'ừ', 'ờ', 'uh',
            'vâng', 'dạ', 'yeah', 'yep', 'yup', 'sure', 'làm luôn', 'chạy luôn',
            'tạo luôn', 'viết luôn', 'đăng luôn', 'thực hiện luôn', 'làm thôi',
            'chạy thôi', 'lẹ đi', 'nhanh đi', 'gửi đi', 'đi',
        ];
        foreach ( $phrases as $phrase ) {
            if ( $lower === $phrase
                 || ( str_starts_with( $lower, $phrase )
                      && mb_strlen( $lower, 'UTF-8' ) <= mb_strlen( $phrase, 'UTF-8' ) + 5 )
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fast-path rejection detection. No LLM call needed.
     */
    private function is_fast_reject( string $lower ): bool {
        $phrases = [
            'hủy', 'huỷ', 'cancel', 'thôi', 'bỏ', 'dừng', 'dung',
            'không', 'ko', 'no', 'nope', 'stop', 'hủy bỏ', 'bỏ đi',
            'thôi đi', 'dừng lại', 'không làm', 'ko làm', 'hủy luôn',
        ];
        foreach ( $phrases as $phrase ) {
            if ( $lower === $phrase
                 || ( str_starts_with( $lower, $phrase )
                      && mb_strlen( $lower, 'UTF-8' ) <= mb_strlen( $phrase, 'UTF-8' ) + 5 )
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * LLM-based analysis for ambiguous confirm responses.
     *
     * The LLM determines the user's intent AND extracts any slot changes,
     * including enrichments (appending to existing text values).
     */
    private function llm_analyze( string $message, array $conversation, $planner ): array {
        $fallback = [
            'intent'       => self::INTENT_MODIFY,
            'slot_updates' => [],
            'method'       => 'llm_fallback',
        ];

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return $fallback;
        }

        $goal = $conversation['goal'] ?? '';
        $plan = $goal ? $planner->get_plan( $goal ) : null;
        if ( ! $plan ) {
            return $fallback;
        }

        $all_slots     = array_merge( $plan['required_slots'] ?? [], $plan['optional_slots'] ?? [] );
        $current_slots = $conversation['slots'] ?? [];

        // Build schema description for LLM
        $schema_lines = [];
        foreach ( $all_slots as $field => $config ) {
            if ( str_starts_with( $field, '_' ) ) continue;
            if ( in_array( $field, [ 'session_id', 'user_id', 'platform', 'client_name', 'plugin_slug', 'tool_name' ], true ) ) continue;

            $type    = $config['type'] ?? 'text';
            $value   = $current_slots[ $field ] ?? '';
            $display = is_array( $value )
                ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE )
                : mb_substr( (string) $value, 0, 120, 'UTF-8' );

            $line = "- {$field} (type={$type}): \"{$display}\"";

            if ( ! empty( $config['choices'] ) && is_array( $config['choices'] ) ) {
                $choices_str = [];
                foreach ( $config['choices'] as $key => $label ) {
                    $display_key   = is_int( $key ) ? $label : $key;
                    $choices_str[] = "{$display_key}";
                }
                $line .= '  [choices: ' . implode( ', ', $choices_str ) . ']';
            }

            $schema_lines[] = $line;
        }
        $schema_text = implode( "\n", $schema_lines );
        $goal_label  = $conversation['goal_label'] ?? $goal;

        $system = <<<PROMPT
You are a confirmation response analyzer for the tool "{$goal_label}".

The user was shown a summary of collected slot values and asked: "Gõ OK để thực hiện, hoặc bổ sung/chỉnh sửa thêm."

CURRENT SLOT VALUES:
{$schema_text}

ANALYZE the user's response and classify into ONE of these intents:

1. "accept" — User agrees to execute with current values (e.g. "ok", "được", "chạy đi")
2. "accept_modify" — User AGREES to proceed BUT provides additional info that should ENRICH/UPDATE existing slots.
   KEY: If the response contains descriptive content that adds detail to a text-type slot (especially "prompt"), classify as accept_modify and provide the ENRICHED value (merge old + new).
   Example: Current prompt="Tạo ảnh giúp tôi" → User says "xịn xò, studio chuyên nghiệp" → Enriched prompt="Tạo ảnh sản phẩm xịn xò, studio chuyên nghiệp"
3. "modify" — User explicitly wants to CHANGE specific values and review again before executing (e.g. "sửa lại X thành Y", "đổi kích thước")
4. "reject" — User wants to cancel entirely (e.g. "hủy", "thôi", "không làm nữa")
5. "new_goal" — User is talking about something completely unrelated to {$goal_label}

RULES:
- For "accept_modify": The slot_updates MUST contain the FULL new value (merged), not just the addition.
- For text/prompt slots: MERGE user's new description with existing value naturally.
- For choice slots: MAP user's text to the closest valid choice KEY.
- Only update slots that the user explicitly references or whose content is clearly relevant.
- If unsure between accept_modify and modify, prefer accept_modify when the response sounds affirming.
- Response MUST be valid JSON only, no explanation.

RETURN FORMAT:
{"intent": "accept_modify", "slot_updates": {"prompt": "merged enriched value"}}
PROMPT;

        $ai = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $message ],
            ],
            [
                'purpose'     => 'confirm_analyze',
                'temperature' => 0.1,
                'max_tokens'  => 300,
                'no_fallback' => false,
            ]
        );

        if ( empty( $ai['success'] ) || empty( $ai['message'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[CONFIRM-ANALYZER] LLM call failed: ' . wp_json_encode( $ai['error'] ?? 'unknown' ) );
            }
            return $fallback;
        }

        // Parse JSON from LLM response
        $raw = $ai['message'];
        $parsed = null;

        // Try extracting JSON block (handles ```json ... ``` wrapping)
        if ( preg_match( '/\{[^{}]*"intent"\s*:.*\}/us', $raw, $m ) ) {
            $parsed = json_decode( $m[0], true );
        }
        if ( ! is_array( $parsed ) ) {
            $parsed = json_decode( $raw, true );
        }

        if ( ! is_array( $parsed ) || empty( $parsed['intent'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[CONFIRM-ANALYZER] Failed to parse LLM response: ' . mb_substr( $raw, 0, 200, 'UTF-8' ) );
            }
            return $fallback;
        }

        // Validate intent
        $valid_intents = [
            self::INTENT_ACCEPT,
            self::INTENT_ACCEPT_MODIFY,
            self::INTENT_MODIFY,
            self::INTENT_REJECT,
            self::INTENT_NEW_GOAL,
        ];
        $intent = in_array( $parsed['intent'], $valid_intents, true )
            ? $parsed['intent']
            : self::INTENT_MODIFY;

        // Validate slot_updates — only allow slots defined in the plan
        $slot_updates = [];
        if ( ! empty( $parsed['slot_updates'] ) && is_array( $parsed['slot_updates'] ) ) {
            foreach ( $parsed['slot_updates'] as $field => $value ) {
                if ( isset( $all_slots[ $field ] ) && $value !== '' && $value !== null ) {
                    $slot_updates[ $field ] = $value;
                }
            }
        }

        // accept_modify without actual updates → downgrade to accept
        if ( $intent === self::INTENT_ACCEPT_MODIFY && empty( $slot_updates ) ) {
            $intent = self::INTENT_ACCEPT;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[CONFIRM-ANALYZER] intent=' . $intent
                     . ' | updates=' . wp_json_encode( $slot_updates, JSON_UNESCAPED_UNICODE )
                     . ' | method=llm' );
        }

        return [
            'intent'       => $intent,
            'slot_updates' => $slot_updates,
            'method'       => 'llm_confirm_analyze',
        ];
    }
}
