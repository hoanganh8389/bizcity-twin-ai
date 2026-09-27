<?php
/**
 * Research Summarizer — port of create_output_summarizer() in agent.py.
 *
 * Applied to Extract + Crawl results (NOT Search) — nano LLM compresses
 * raw_content so the agent context doesn't overflow. Returns the canonical
 * shape { summary, urls, favicons, items[] }.
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Summarizer {

    /**
     * @param array  $tool_output  Raw response from BizCity_Tavily_API::extract|crawl
     * @param string $user_query   The user's research question (for relevance)
     * @return array { summary, urls[], favicons[], items[] }
     */
    public static function summarize( array $tool_output, string $user_query ): array {
        $items    = $tool_output['results'] ?? [];
        $urls     = [];
        $favicons = [];
        $shaped   = [];
        $combined = '';

        foreach ( $items as $item ) {
            $url     = (string) ( $item['url']     ?? '' );
            $favicon = (string) ( $item['favicon'] ?? '' );
            $title   = (string) ( $item['title']   ?? '' );
            $raw     = (string) ( $item['raw_content'] ?? $item['content'] ?? '' );
            if ( $url === '' ) continue;

            $urls[]     = $url;
            $favicons[] = $favicon;
            $shaped[]   = [
                'url'     => $url,
                'title'   => $title !== '' ? $title : $url,
                'favicon' => $favicon,
            ];

            if ( $raw !== '' ) {
                $combined .= "\n\n---\n\n" . $title . "\n" . mb_substr( $raw, 0, 6000 );
            }
        }

        $summary = '';
        if ( $combined !== '' && class_exists( 'BizCity_LLM_Client' ) ) {
            $client = BizCity_LLM_Client::instance();
            $resp = $client->chat( [
                [ 'role' => 'system', 'content' => 'Bạn là trợ lý tóm tắt nội dung web. Trả về tóm tắt ngắn gọn, trung thực, có cấu trúc, bằng tiếng Việt.' ],
                [ 'role' => 'user',   'content' => "Tóm tắt nội dung dưới đây liên quan đến câu hỏi:\n\nCÂU HỎI: {$user_query}\n\nNỘI DUNG:\n{$combined}\n\nTrả về tóm tắt có cấu trúc (tiếng Việt, markdown):" ],
            ], [
                'purpose'     => 'summarizer',
                'temperature' => 0.3,
                'max_tokens'  => 1200,
            ] );
            if ( ! empty( $resp['success'] ) ) {
                $summary = (string) $resp['message'];
            }
        }

        return [
            'summary'  => $summary,
            'urls'     => $urls,
            'favicons' => $favicons,
            'items'    => $shaped,
        ];
    }
}
