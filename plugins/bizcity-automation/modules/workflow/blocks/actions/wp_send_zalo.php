<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Workflow Action: Send Zalo (via chat_id routing)
 */
class WaicAction_wp_send_zalo extends WaicAction {
    protected $_code  = 'wp_send_zalo';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('Trả lời Zalo BizCity', 'ai-copilot-content-generator');
        $this->_desc = __('Trả lời bằng số Zalo BizCity 0562608899', 'ai-copilot-content-generator');
		$this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    private function bizcityGetAllChatIdsInSystem() {
        $ids = array();

        if (function_exists('twf_get_all_telegram_chat_ids')) {
            $ids = array_merge($ids, (array) twf_get_all_telegram_chat_ids());
        }
        if (function_exists('twf_get_all_zalo_chat_ids')) {
            $ids = array_merge($ids, (array) twf_get_all_zalo_chat_ids());
        }

        $ids = array_merge($ids, (array) apply_filters('waic_get_all_chat_ids', array()));

        $out = array();
        foreach ($ids as $cid) {
            $cid = trim((string) $cid);
            if ($cid !== '') $out[] = $cid;
        }
        return array_values(array_unique($out));
    }

    private function bizcityChatIdOptions() {
        $opts = array(
            '' => __('— Chọn Chat ID —', 'ai-copilot-content-generator'),
        );
        $all = $this->bizcityGetAllChatIdsInSystem();
        foreach ($all as $cid) {
            // label = value để dễ nhận biết
            $opts[$cid] = $cid;
        }
        return $opts;
    }

    public function setSettings() {
        $yesNo = array(
            0 => __('Không', 'ai-copilot-content-generator'),
            1 => __('Có', 'ai-copilot-content-generator'),
        );

        $this->_settings = array(
            'chat_id' => array(
                'type' => 'textarea',
                'label' => __('Chat ID người nhận *', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 3,
                'variables' => true,
                'desc' => __('Nhập mỗi dòng 1 ID hoặc phân tách bằng dấu phẩy. Ví dụ: zalo_8872247607', 'ai-copilot-content-generator'),
            ),
            /*
            // NEW: dropdown chọn 1 chat id có sẵn trong hệ thống
            'chat_id_select' => array(
                'type' => 'select',
                'label' => __('Chọn nhanh Chat ID (từ hệ thống)', 'ai-copilot-content-generator'),
                'default' => '',
                'options' => $this->bizcityChatIdOptions(),
                'desc' => __('Nếu chọn, ID này sẽ được tự động thêm vào danh sách người nhận.', 'ai-copilot-content-generator'),
            ),

            // NOTE: UI không hỗ trợ checkbox => dùng select yes/no
            'send_to_blog_admins' => array(
                'type' => 'select',
                'label' => __('Gửi thêm cho admin của blog', 'ai-copilot-content-generator'),
                'default' => 0,
                'options' => $yesNo,
            ),

            'send_to_all_chat_ids' => array(
                'type' => 'select',
                'label' => __('Gửi tới toàn bộ Chat ID trong hệ thống', 'ai-copilot-content-generator'),
                'default' => 0,
                'options' => $yesNo,
                'desc' => __('Nếu bật: tự động gộp toàn bộ chat_id (zalo_telegram_*) tìm thấy trong hệ thống vào danh sách người nhận.', 'ai-copilot-content-generator'),
            ),*/

            'message' => array(
                'type' => 'textarea',
                'label' => __('Nội dung tin nhắn *', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 6,
                'html' => true,
                'variables' => true,
            ),
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {

        $chatIdRaw = (string) $this->replaceVariables($this->getParam('chat_id'), $variables);
        #$chatIdPick = (string) $this->getParam('chat_id_select');
        $message   = (string) $this->replaceVariables($this->getParam('message'), $variables);

        if ($chatIdRaw === '') {
            if (!empty($variables['twf_chat_id'])) {
                $chatIdRaw = (string) $variables['twf_chat_id'];
            } else if (!empty($variables['chat_id'])) {
                $chatIdRaw = (string) $variables['chat_id'];
            }
        }

        #$sendToBlogAdmins   = (int) $this->getParam('send_to_blog_admins') === 1;
        #$sendToAllChatIds   = (int) $this->getParam('send_to_all_chat_ids') === 1;

        $error  = '';
        $sent   = 0;
        $errors = array();

        if (empty($message)) {
            $error = __('Nội dung tin nhắn đang trống.', 'ai-copilot-content-generator');
        }

        #$chat_ids  = biz_get_zalo_admin_id(get_current_blog_id(), true); // Được định nghĩa trong zalo/functions.php global_user_admin 
							
        $chat_ids  = biz_get_zalo_admin_id(get_current_blog_id(), true); 
        foreach ($chat_ids as $chat_id) {
            biz_send_message($chat_id, $message);
        }	

        $this->_results = array(
            'result' => array(
                'chat_ids' => $chatIds,
                'message'  => $message,
                'sent'     => $sent,
                'errors'   => $errors
                
            ),
            'error' => $error,
            'status' => empty($error) ? 3 : 7,
        );

        return $this->_results;
    }
}
