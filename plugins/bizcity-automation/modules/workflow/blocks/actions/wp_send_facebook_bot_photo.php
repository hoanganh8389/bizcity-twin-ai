<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Workflow Action: Send Facebook Bot Photo Message
 * Sử dụng BizCity_Facebook_Bot_API để gửi hình ảnh qua Messenger
 */
class WaicAction_wp_send_facebook_bot_photo extends WaicAction {
    protected $_code  = 'wp_send_facebook_bot_photo';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('Gửi hình ảnh Facebook Bot (Photo)', 'ai-copilot-content-generator');
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
                'desc' => __('Chọn bot để gửi hình ảnh', 'ai-copilot-content-generator'),
            ),

            'user_id' => array(
                'type' => 'text',
                'label' => __('User ID (PSID) người nhận *', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_chat_id}}',
                'variables' => true,
                'desc' => __('Page-scoped ID của người nhận', 'ai-copilot-content-generator'),
            ),

            'photo_url' => array(
                'type' => 'text',
                'label' => __('URL hình ảnh *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('URL công khai của hình ảnh (HTTPS)', 'ai-copilot-content-generator'),
            ),

            'caption' => array(
                'type' => 'text',
                'label' => __('Caption (tùy chọn)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('Chú thích cho hình ảnh (gửi như tin nhắn riêng)', 'ai-copilot-content-generator'),
            ),
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $bot_id = (int) $this->getParam('bot_id');
        $user_id = (string) $this->replaceVariables($this->getParam('user_id'), $variables);
        $photo_url = (string) $this->replaceVariables($this->getParam('photo_url'), $variables);
        $caption = (string) $this->replaceVariables($this->getParam('caption'), $variables);

        // Fallback to variables if user_id is empty
        if ( empty( $user_id ) ) {
            if ( ! empty( $variables['twf_chat_id'] ) ) {
                $user_id = (string) $variables['twf_chat_id'];
            } elseif ( ! empty( $variables['chat_id'] ) ) {
                $user_id = (string) $variables['chat_id'];
            } elseif ( ! empty( $variables['user_id'] ) ) {
                $user_id = (string) $variables['user_id'];
            }
        }

        $error = '';
        $sent = 0;
        $result_data = array();

        // Validation
        if ( empty( $bot_id ) ) {
            $error = __('Chưa chọn bot', 'ai-copilot-content-generator');
        } elseif ( empty( $user_id ) ) {
            $error = __('User ID đang trống', 'ai-copilot-content-generator');
        } elseif ( empty( $photo_url ) ) {
            $error = __('URL hình ảnh đang trống', 'ai-copilot-content-generator');
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
                
                // Send photo
                $response = $api->send_photo( $user_id, $photo_url, $caption );

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
                    
                    // Log to database
                    $db->insert_log( $bot_id, 'workflow_send_photo', json_encode( array(
                        'user_id' => $user_id,
                        'photo_url' => $photo_url,
                        'caption' => $caption,
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
                'user_id' => $user_id,
                'photo_url' => $photo_url,
                'caption' => $caption,
                'response' => $result_data,
            ),
        );
    }
}
