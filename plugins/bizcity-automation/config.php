<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
global $wpdb;

// BizCity: dùng locale WP thay vì WPLANG (WPLANG đã lỗi thời)
$waicLocale = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'vi');
// Only force seed-templates if DB tables already exist (db_version set).
// On fresh installs, let WaicInstaller::update() run init() to create tables first.
if ( get_option( $wpdb->prefix . 'bizcity_db_version', false ) ) {
    define('WAIC_FORCE_SEED_TEMPLATES', true);
}
// Chuẩn hoá: nếu là tiếng Việt thì set 'vi', còn lại giữ nguyên locale
if (strpos($waicLocale, 'vi') === 0) {
    define('WAIC_WPLANG', 'vi');
} else {
    define('WAIC_WPLANG', $waicLocale);
}

define('WAIC_DS', DIRECTORY_SEPARATOR);
define('WAIC_PLUG_NAME', basename(dirname(__FILE__)));

define('BIZCITY_AUTOMATION_DIR', dirname(__FILE__) . '/');
define('BIZCITY_AUTOMATION_URL', plugins_url('/', __FILE__));
define('WAIC_DIR', BIZCITY_AUTOMATION_DIR);
define('WAIC_LOG_DIR', WAIC_DIR . 'logs' . WAIC_DS);
define('WAIC_CLASSES_DIR', WAIC_DIR . 'classes' . WAIC_DS);
define('WAIC_TABLES_DIR', WAIC_CLASSES_DIR . 'tables' . WAIC_DS);
define('WAIC_HELPERS_DIR', WAIC_CLASSES_DIR . 'helpers' . WAIC_DS);
define('WAIC_LANG_DIR', WAIC_DIR . 'languages' . WAIC_DS);
define('WAIC_ASSETS_DIR', WAIC_DIR . 'common' . WAIC_DS);
define('WAIC_IMG_DIR', WAIC_ASSETS_DIR . 'img' . WAIC_DS);
define('WAIC_JS_DIR', WAIC_ASSETS_DIR . 'js' . WAIC_DS);
define('WAIC_LIB_DIR', WAIC_ASSETS_DIR . 'lib' . WAIC_DS);
define('WAIC_MODULES_DIR', WAIC_DIR . 'modules' . WAIC_DS);
define('WAIC_ADMIN_DIR', ABSPATH . 'wp-admin' . WAIC_DS);

define('WAIC_PLUGINS_URL', plugins_url());
if (!defined('WAIC_SITE_URL')) {
	define('WAIC_SITE_URL', get_bloginfo('wpurl') . '/');
}
define('WAIC_LIB_PATH', BIZCITY_AUTOMATION_URL . 'common/lib/');
define('WAIC_JS_PATH', BIZCITY_AUTOMATION_URL . 'common/js/');
define('WAIC_CSS_PATH', BIZCITY_AUTOMATION_URL . 'common/css/');
define('WAIC_IMG_PATH',  BIZCITY_AUTOMATION_URL . 'common/img/');
define('WAIC_MODULES_PATH', BIZCITY_AUTOMATION_URL . 'modules/');

define('WAIC_URL', WAIC_SITE_URL);

define('WAIC_LOADER_IMG', WAIC_IMG_PATH . 'loading.gif');
define('WAIC_TIME_FORMAT', 'H:i:s');
define('WAIC_DATE_DL', '/');
define('WAIC_DATE_FORMAT', 'm/d/Y');
define('WAIC_DATE_FORMAT_HIS', 'm/d/Y (' . WAIC_TIME_FORMAT . ')');
define('WAIC_DB_PREF', 'bizcity_');
define('WAIC_MAIN_FILE', 'bootstrap.php');

define('WAIC_DEFAULT', 'default');

define('WAIC_VERSION', '1.3.7.3.26'); // Fix REST nonce for workflow history

define('WAIC_CLASS_PREFIX', 'waicc');
define('WAIC_TEST_MODE', true);

define('WAIC_ADMIN', 'admin');
define('WAIC_LOGGED', 'logged');
define('WAIC_GUEST', 'guest');

define('WAIC_METHODS', 'methods');
define('WAIC_USERLEVELS', 'userlevels');
/**
 * Framework instance code
 */
define('WAIC_CODE', 'waic');
/**
 * Plugin name
 */
define('WAIC_WP_PLUGIN_NAME', 'Biz Automation - Tự động hóa công việc');
/**
 * Custom defined for plugin
 */
define('WAIC_CHATBOT', 'aiwu-chatbot');
define('WAIC_FORM', 'aiwu-form');
