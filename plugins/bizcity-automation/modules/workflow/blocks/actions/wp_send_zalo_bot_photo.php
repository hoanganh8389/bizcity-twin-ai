<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Workflow Action: Send Zalo Bot Photo Message
 * Sử dụng BizCity_Zalo_Bot_API để gửi hình ảnh
 */
class WaicAction_wp_send_zalo_bot_photo extends WaicAction {
    protected $_code  = 'wp_send_zalo_bot_photo';
    protected $_order = 1;

    public function __construct( $block = null ) {
        $this->_name = __('Gửi hình ảnh Zalo Bot (Photo)', 'ai-copilot-content-generator');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    /**
     * Get list of active Zalo bots for dropdown
     */
    private function getBotOptions() {
        $opts = array(
            '' => __('— Chọn Bot —', 'ai-copilot-content-generator'),
        );
        
        if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
            $db = BizCity_Zalo_Bot_Database::instance();
            $bots = $db->get_active_bots();
            
            foreach ( $bots as $bot ) {
                $opts[ $bot->id ] = sprintf( '%s (ID: %d)', $bot->bot_name, $bot->id );
            }
        }
        
        return $opts;
    }

    public function setSettings() {
        // Get newest bot ID as default
        $default_bot_id = '';
        if ( class_exists( 'BizCity_Zalo_Bot_Database' ) ) {
            $db = BizCity_Zalo_Bot_Database::instance();
            $bots = $db->get_active_bots();
            if ( ! empty( $bots ) ) {
                // Get the bot with highest ID (newest)
                $newest_bot = end( $bots );
                $default_bot_id = $newest_bot->id;
            }
        }
        
        $this->_settings = array(
            'bot_id' => array(
                'type' => 'select',
                'label' => __('Chọn Zalo Bot *', 'ai-copilot-content-generator'),
                'default' => $default_bot_id,
                'options' => $this->getBotOptions(),
                'desc' => __('Chọn bot để gửi tin nhắn', 'ai-copilot-content-generator'),
            ),
            'chat_id' => array(
                'type' => 'text',
                'label' => __('Chat ID người nhận *', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_chat_id}}',
                'variables' => true,
                'desc' => __('ID người nhận (user_id từ Zalo). Ví dụ: 8872247607', 'ai-copilot-content-generator'),
            ),

            'file_url' => array(
                'type' => 'text',
                'label' => __('URL hình ảnh *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('URL đầy đủ của hình ảnh (https://...). Ví dụ: https://example.com/image.jpg', 'ai-copilot-content-generator'),
            ),

            'caption' => array(
                'type' => 'textarea',
                'label' => __('Chú thích (Caption)', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 3,
                'html' => false,
                'variables' => true,
                'desc' => __('Mô tả kèm theo hình ảnh (không bắt buộc)', 'ai-copilot-content-generator'),
            ),
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $bot_id = (int) $this->getParam('bot_id');
        $chat_id = (string) $this->replaceVariables($this->getParam('chat_id'), $variables);
        $file_url = (string) $this->replaceVariables($this->getParam('file_url'), $variables);
        $caption = (string) $this->replaceVariables($this->getParam('caption'), $variables);

        // Fallback to variables if chat_id is empty
        if ( empty( $chat_id ) ) {
            if ( ! empty( $variables['twf_chat_id'] ) ) {
                $chat_id = (string) $variables['twf_chat_id'];
            } elseif ( ! empty( $variables['chat_id'] ) ) {
                $chat_id = (string) $variables['chat_id'];
            } elseif ( ! empty( $variables['user_id'] ) ) {
                $chat_id = (string) $variables['user_id'];
            }
        }

        $error = '';
        $sent = 0;
        $result_data = array();

        // Validation
        if ( empty( $bot_id ) ) {
            $error = __('Chưa chọn bot', 'ai-copilot-content-generator');
        } elseif ( empty( $chat_id ) ) {
            $error = __('Chat ID đang trống', 'ai-copilot-content-generator');
        } elseif ( empty( $file_url ) ) {
            $error = __('URL hình ảnh đang trống', 'ai-copilot-content-generator');
        } elseif ( ! filter_var( $file_url, FILTER_VALIDATE_URL ) ) {
            $error = __('URL hình ảnh không hợp lệ', 'ai-copilot-content-generator');
        }

        if ( empty( $error ) && class_exists( 'BizCity_Zalo_Bot_Database' ) && class_exists( 'BizCity_Zalo_Bot_API' ) ) {
            // Get bot configuration
            $db = BizCity_Zalo_Bot_Database::instance();
            $bot = $db->get_bot( $bot_id );

            if ( ! $bot ) {
                $error = __('Không tìm thấy bot', 'ai-copilot-content-generator');
            } elseif ( empty( $bot->bot_token ) ) {
                $error = __('Bot chưa có access token', 'ai-copilot-content-generator');
            } else {
                // Initialize API client
                $api = new BizCity_Zalo_Bot_API( $bot->bot_token );
                
                // Send photo
                $response = $api->send_photo( $chat_id, $file_url, $caption );

                if ( is_wp_error( $response ) ) {
                    $error = sprintf(
                        __('Lỗi gửi hình: %s', 'ai-copilot-content-generator'),
                        $response->get_error_message()
                    );
                    $result_data = array(
                        'error_code' => $response->get_error_code(),
                        'error_data' => $response->get_error_data(),
                    );
                } else {
                    $sent = 1;
                    $result_data = $response;
                }
            }
        } elseif ( empty( $error ) ) {
            $error = __('BizCity Zalo Bot plugin chưa được kích hoạt', 'ai-copilot-content-generator');
        }

        $this->_results = array(
            'result' => array(
                'bot_id' => $bot_id,
                'chat_id' => $chat_id,
                'file_url' => $file_url,
                'caption' => $caption,
                'sent' => $sent,
                'api_response' => $result_data,
            ),
            'error' => $error,
            'status' => empty($error) ? 3 : 7,
        );

        return $this->_results;
    }
}
