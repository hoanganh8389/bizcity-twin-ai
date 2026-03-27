<?php
/**
 * Plugin Name: BizCity Knowledge
 * Plugin URI: https://bizcity.vn
 * Description: AI Assistants & Knowledge Management / Các trợ lý AI & Quản lý Kiến thức
 * Version: 2.0.0
 * Author: Johnny Chu (Chu Hoàng Anh)
 * Author URI: https://bizcity.vn
 * Text Domain: bizcity-knowledge
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */
 * 
 * @package BizCity_Knowledge
 */

defined('ABSPATH') or die('OOPS...');

// Define plugin constants
define('BIZCITY_KNOWLEDGE_FILE', __FILE__);
define('BIZCITY_KNOWLEDGE_DIR', plugin_dir_path(__FILE__));
define('BIZCITY_KNOWLEDGE_URL', plugin_dir_url(__FILE__));
define('BIZCITY_KNOWLEDGE_BASENAME', plugin_basename(__FILE__));

// Activation hook
register_activation_hook(__FILE__, 'bizcity_knowledge_activate');
function bizcity_knowledge_activate() {
    require_once BIZCITY_KNOWLEDGE_DIR . 'includes/class-database.php';
    
    // Force create/update tables
    $db = new BizCity_Knowledge_Database();
    $db->create_tables();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'bizcity_knowledge_deactivate');
function bizcity_knowledge_deactivate() {
    // Clean up if needed
}

// Load bootstrap
require_once BIZCITY_KNOWLEDGE_DIR . 'bootstrap.php';
