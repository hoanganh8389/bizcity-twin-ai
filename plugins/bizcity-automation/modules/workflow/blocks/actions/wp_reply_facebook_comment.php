<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Workflow Action: Reply to Facebook Comment
 * Sử dụng BizCity_Facebook_Bot_API để trả lời comment trên Facebook Page
 */
class WaicAction_wp_reply_facebook_comment extends WaicAction {
    protected $_code  = 'wp_reply_facebook_comment';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('Trả lời comment Facebook', 'ai-copilot-content-generator');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    /**
     * Get list of active Facebook bots for dropdown
     */
    private function getBotOptions() {
        $opts = array(
            '' => __('— Chọn Bot —', 'ai-copilot-content-generator'),
        );
        
        if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
            $db = BizCity_Facebook_Bot_Database::instance();
            $bots = $db->get_active_bots();
            
            foreach ( $bots as $bot ) {
                $opts[ $bot->id ] = sprintf( '%s (Page: %s)', $bot->bot_name, $bot->page_id );
            }
        }
        
        return $opts;
    }

    public function setSettings() {
        // Get newest bot ID as default
        $default_bot_id = '';
        if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
            $db = BizCity_Facebook_Bot_Database::instance();
            $bots = $db->get_active_bots();
            if ( ! empty( $bots ) ) {
                $newest_bot = end( $bots );
                $default_bot_id = $newest_bot->id;
            }
        }
        
        $this->_settings = array(
            'bot_id' => array(
                'type' => 'select',
                'label' => __('Chọn Facebook Bot *', 'ai-copilot-content-generator'),
                'default' => $default_bot_id,
                'options' => $this->getBotOptions(),
                'desc' => __('Chọn bot để trả lời comment', 'ai-copilot-content-generator'),
            ),

            'comment_id' => array(
                'type' => 'text',
                'label' => __('Comment ID *', 'ai-copilot-content-generator'),
                'default' => '{{node#1.comment_id}}',
                'variables' => true,
                'desc' => __('ID của comment cần trả lời. Lấy từ trigger khi có comment mới.', 'ai-copilot-content-generator'),
            ),

            'message' => array(
                'type' => 'textarea',
                'label' => __('Nội dung trả lời *', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 4,
                'html' => false,
                'variables' => true,
                'desc' => __('Nội dung comment trả lời', 'ai-copilot-content-generator'),
            ),
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $bot_id = (int) $this->getParam('bot_id');
        $comment_id = (string) $this->replaceVariables($this->getParam('comment_id'), $variables);
        $message = (string) $this->replaceVariables($this->getParam('message'), $variables);

        // Fallback to variables if comment_id is empty
        if ( empty( $comment_id ) && ! empty( $variables['comment_id'] ) ) {
            $comment_id = (string) $variables['comment_id'];
        }

        $error = '';
        $sent = 0;
        $result_data = array();

        // Validation
        if ( empty( $bot_id ) ) {
            $error = __('Chưa chọn bot', 'ai-copilot-content-generator');
        } elseif ( empty( $comment_id ) ) {
            $error = __('Comment ID đang trống', 'ai-copilot-content-generator');
        } elseif ( empty( $message ) ) {
            $error = __('Nội dung trả lời đang trống', 'ai-copilot-content-generator');
        }

        if ( empty( $error ) && class_exists( 'BizCity_Facebook_Bot_Database' ) && class_exists( 'BizCity_Facebook_Bot_API' ) ) {
            // Get bot configuration
            $db = BizCity_Facebook_Bot_Database::instance();
            $bot = $db->get_bot( $bot_id );

            if ( ! $bot ) {
                $error = __('Không tìm thấy bot', 'ai-copilot-content-generator');
            } elseif ( empty( $bot->page_access_token ) ) {
                $error = __('Bot chưa có page access token', 'ai-copilot-content-generator');
            } else {
                // Initialize API client
                $api = new BizCity_Facebook_Bot_API( $bot->page_access_token, $bot->page_id );
                
                // Reply to comment
                $response = $api->reply_comment( $comment_id, $message );

                if ( is_wp_error( $response ) ) {
                    $error = sprintf(
                        __('Lỗi trả lời comment: %s', 'ai-copilot-content-generator'),
                        $response->get_error_message()
                    );
                    $result_data = array(
                        'error_code' => $response->get_error_code(),
                        'error_data' => $response->get_error_data(),
                    );
                } else {
                    $sent = 1;
                    $result_data = $response;
                    
                    // Log to database
                    $db->insert_log( $bot_id, 'workflow_reply_comment', json_encode( array(
                        'comment_id' => $comment_id,
                        'message' => $message,
                        'response' => $response,
                    ) ) );
                }
            }
        } elseif ( empty( $error ) ) {
            $error = __('BizCity Facebook Bot plugin chưa được kích hoạt', 'ai-copilot-content-generator');
        }

        return array(
            'stop' => ! empty( $error ) ? 1 : 0,
            'result' => array(
                'sent' => $sent,
                'error' => $error,
                'comment_id' => $comment_id,
                'message' => $message,
                'response' => $result_data,
            ),
        );
    }
}
