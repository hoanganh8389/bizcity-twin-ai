<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core Bridge — Optional API bridge to bizcity-knowledge/intent.
 */
class BCN_Core_Bridge {

    public function get_user_memories( $user_id ) {
        if ( ! $this->is_knowledge_active() ) return [];
        if ( ! class_exists( 'BizCity_User_Memory' ) ) return [];
        return BizCity_User_Memory::get_for_user( $user_id );
    }

    public function get_active_goals( $user_id ) {
        if ( ! $this->is_intent_active() ) return [];
        if ( ! class_exists( 'BizCity_Rolling_Memory' ) ) return [];
        return BizCity_Rolling_Memory::get_active_goals( $user_id );
    }

    public function is_knowledge_active() {
        return bcn_has_knowledge();
    }

    public function is_intent_active() {
        return bcn_has_intent();
    }
}
