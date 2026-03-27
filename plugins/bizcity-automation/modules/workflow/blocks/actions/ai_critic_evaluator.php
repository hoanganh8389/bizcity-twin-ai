<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Critic & Evaluator
 * Đánh giá chất lượng kết quả execution và đưa ra feedback
 */
class WaicAction_ai_critic_evaluator extends WaicAction {
    protected $_code  = 'ai_critic_evaluator';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('AI Critic Evaluator - Đánh giá kết quả', 'ai-copilot-content-generator');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'execution_log' => array(
                'type' => 'textarea',
                'label' => __('Execution Log *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 6,
                'desc' => __('Log từ ai_execute_checklist', 'ai-copilot-content-generator'),
            ),

            'step_results' => array(
                'type' => 'textarea',
                'label' => __('Step Results JSON', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 6,
                'desc' => __('Kết quả chi tiết từng step (JSON)', 'ai-copilot-content-generator'),
            ),

            'original_request' => array(
                'type' => 'textarea',
                'label' => __('Yêu cầu gốc', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 3,
                'desc' => __('Message gốc của user để so sánh', 'ai-copilot-content-generator'),
            ),

            'use_ai_evaluation' => array(
                'type' => 'select',
                'label' => __('Dùng AI đánh giá', 'ai-copilot-content-generator'),
                'default' => '1',
                'options' => array(
                    '0' => __('Không - Chỉ rule-based', 'ai-copilot-content-generator'),
                    '1' => __('Có - Gọi GPT để đánh giá', 'ai-copilot-content-generator'),
                ),
            ),
        );
    }
    
    public function getVariables() {
        if (empty($this->_variables)) {
            $this->setVariables();
        }
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'quality_score' => __('Điểm chất lượng (0-100)', 'ai-copilot-content-generator'),
            'success_rate' => __('Tỷ lệ thành công (0-100)', 'ai-copilot-content-generator'),
            'total_steps' => __('Tổng số steps', 'ai-copilot-content-generator'),
            'success_steps' => __('Số steps thành công', 'ai-copilot-content-generator'),
            'failed_steps' => __('Số steps thất bại', 'ai-copilot-content-generator'),
            'evaluation_result' => __('Kết quả đánh giá: excellent, good, fair, poor', 'ai-copilot-content-generator'),
            'feedback' => __('Feedback chi tiết (text)', 'ai-copilot-content-generator'),
            'suggestions' => __('Gợi ý cải thiện (array)', 'ai-copilot-content-generator'),
            'needs_retry' => __('Có nên retry không (0/1)', 'ai-copilot-content-generator'),
            'ai_evaluation' => __('Đánh giá từ AI (nếu bật)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // Replace variables
        $execution_log = $this->replaceVariables($this->getParam('execution_log'), $variables);
        $step_results_json = $this->replaceVariables($this->getParam('step_results'), $variables);
        $original_request = $this->replaceVariables($this->getParam('original_request'), $variables);
        $use_ai_evaluation = (int) $this->getParam('use_ai_evaluation');

        // Initialize result variables
        $error = '';
        $quality_score = 0;
        $success_rate = 0;
        $total_steps = 0;
        $success_steps = 0;
        $failed_steps = 0;
        $evaluation_result = 'unknown';
        $feedback = '';
        $suggestions = array();
        $needs_retry = 0;
        $ai_evaluation = '';

        // Handle execution_log (could be array or string)
        if (is_array($execution_log)) {
            $execution_log = implode("\n", $execution_log);
        }

        // Debug log
        error_log('[ai_critic_evaluator] Execution log length: ' . strlen($execution_log));
        error_log('[ai_critic_evaluator] Execution log preview: ' . substr($execution_log, 0, 200));

        // Validation
        if ( empty( $execution_log ) ) {
            $error = __('Execution log đang trống', 'ai-copilot-content-generator');
        }

        // Parse step results
        if ( empty( $error ) ) {
            $step_results = array();
            if (!empty($step_results_json)) {
                $step_results = json_decode($step_results_json, true);
                if (!is_array($step_results)) {
                    $step_results = array();
                }
            }

            // Analyze execution log
            $log_lines = explode("\n", $execution_log);
            $total_steps = 0;
            $success_steps = 0;
            $failed_steps = 0;

            foreach ($log_lines as $line) {
                if (strpos($line, '[Step') === 0) {
                    $total_steps++;
                }
                if (strpos($line, '✓ Success') !== false) {
                    $success_steps++;
                }
                if (strpos($line, '❌ Error') !== false) {
                    $failed_steps++;
                }
            }

            // Calculate success rate
            if ($total_steps > 0) {
                $success_rate = round(($success_steps / $total_steps) * 100);
            }

            // Rule-based evaluation
            if ($success_rate >= 90) {
                $evaluation_result = 'excellent';
                $quality_score = 95;
                $feedback = 'Tất cả các bước thực thi thành công. Kết quả xuất sắc!';
            } elseif ($success_rate >= 70) {
                $evaluation_result = 'good';
                $quality_score = 80;
                $feedback = 'Đa số các bước thành công. Kết quả tốt.';
                if ($failed_steps > 0) {
                    $suggestions[] = 'Kiểm tra lại ' . $failed_steps . ' bước thất bại';
                }
            } elseif ($success_rate >= 50) {
                $evaluation_result = 'fair';
                $quality_score = 60;
                $feedback = 'Một số bước thất bại. Kết quả trung bình.';
                $needs_retry = 1;
                $suggestions[] = 'Nên retry các bước thất bại';
                $suggestions[] = 'Kiểm tra logs để tìm nguyên nhân';
            } else {
                $evaluation_result = 'poor';
                $quality_score = 30;
                $feedback = 'Nhiều bước thất bại. Cần xem xét lại workflow.';
                $needs_retry = 1;
                $suggestions[] = 'Kiểm tra lại toàn bộ workflow';
                $suggestions[] = 'Có thể cần thay đổi approach';
            }

            // Check specific issues
            if (strpos($execution_log, 'timeout') !== false) {
                $suggestions[] = 'Phát hiện timeout - Cân nhắc tăng thời gian chờ';
            }
            if (strpos($execution_log, 'not found') !== false) {
                $suggestions[] = 'Phát hiện "not found" - Kiểm tra dữ liệu đầu vào';
            }
            if (strpos($execution_log, 'permission') !== false) {
                $suggestions[] = 'Phát hiện lỗi permission - Kiểm tra quyền truy cập';
            }

            // AI Evaluation (if enabled)
            if ($use_ai_evaluation && !empty($original_request)) {
                $ai_evaluation = $this->getAIEvaluation(
                    $original_request,
                    $execution_log,
                    $step_results
                );
                
                if (!empty($ai_evaluation)) {
                    // Adjust quality score based on AI feedback
                    if (strpos(strtolower($ai_evaluation), 'excellent') !== false) {
                        $quality_score = min(100, $quality_score + 10);
                    } elseif (strpos(strtolower($ai_evaluation), 'poor') !== false) {
                        $quality_score = max(0, $quality_score - 10);
                    }
                }
            }

            // Final quality adjustments based on step results
            if (!empty($step_results)) {
                // Check if final outputs exist
                $has_page_id = false;
                $has_form_id = false;
                
                foreach ($step_results as $result) {
                    if (isset($result['page_id']) && $result['page_id'] > 0) {
                        $has_page_id = true;
                    }
                    if (isset($result['form_id']) && $result['form_id'] > 0) {
                        $has_form_id = true;
                    }
                }
                
                if ($has_page_id) {
                    $quality_score += 5;
                }
                if ($has_form_id) {
                    $quality_score += 5;
                }
            }

            $quality_score = min(100, max(0, $quality_score));
        }

        // Build results
        $this->_results = array(
            'result' => array(
                'quality_score' => $quality_score,
                'success_rate' => $success_rate,
                'total_steps' => $total_steps,
                'success_steps' => $success_steps,
                'failed_steps' => $failed_steps,
                'evaluation_result' => $evaluation_result,
                'feedback' => $this->controlText($feedback),
                'suggestions' => $suggestions,
                'needs_retry' => $needs_retry,
                'ai_evaluation' => $this->controlText($ai_evaluation),
            ),
            'error' => $error,
            'status' => empty($error) ? 3 : 7,
        );

        // Send Zalo notification
        if (empty($error) && function_exists('biz_get_zalo_admin_id') && function_exists('biz_send_message')) {
            $emoji = $evaluation_result === 'excellent' ? '🌟' : 
                     ($evaluation_result === 'good' ? '👍' : 
                     ($evaluation_result === 'fair' ? '⚠️' : '❌'));
            
            $notification = "{$emoji} *Đánh giá kết quả*\n\n";
            $notification .= "Quality: {$quality_score}/100\n";
            $notification .= "Success rate: {$success_rate}%\n";
            $notification .= "Result: {$evaluation_result}\n";
            $notification .= "\n{$feedback}";
            
            if (!empty($suggestions)) {
                $notification .= "\n\n💡 Suggestions:\n";
                foreach (array_slice($suggestions, 0, 3) as $suggestion) {
                    $notification .= "- {$suggestion}\n";
                }
            }
            
            $chat_ids = biz_get_zalo_admin_id(get_current_blog_id(), true);
            if (is_array($chat_ids)) {
                foreach ($chat_ids as $chat_id) {
                    biz_send_message($chat_id, $notification);
                }
            }
        }

        return $this->_results;
    }

    /**
     * Get AI evaluation using GPT
     */
    private function getAIEvaluation($original_request, $execution_log, $step_results) {
        $api_key = get_option('twf_openai_api_key');
        if (empty($api_key)) {
            return '';
        }

        $system_prompt = "Bạn là AI chuyên đánh giá chất lượng automation workflow.

Nhiệm vụ:
1. So sánh yêu cầu gốc với kết quả thực thi
2. Đánh giá mức độ hoàn thành
3. Tìm các vấn đề và đề xuất cải thiện

Output format (ngắn gọn, 3-5 câu):
- Đánh giá tổng thể: Excellent/Good/Fair/Poor
- Điểm mạnh (nếu có)
- Điểm yếu (nếu có)
- Đề xuất cụ thể (nếu có)";

        $user_prompt = "Yêu cầu gốc:\n{$original_request}\n\n";
        $user_prompt .= "Execution Log:\n{$execution_log}\n\n";
        $user_prompt .= "Hãy đánh giá kết quả và đưa ra feedback.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_prompt),
                ),
                'temperature' => 0.5,
                'max_tokens' => 300,
            )),
        ));

        if (is_wp_error($response)) {
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['choices'][0]['message']['content']) 
            ? trim($body['choices'][0]['message']['content']) 
            : '';
    }
}
