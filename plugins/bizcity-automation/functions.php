<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Set first leter in a string as UPPERCASE
 *
 * @param string $str string to modify
 * @return string string with first Uppercase letter
 */
if (!function_exists('waicStrFirstUp')) {
	function waicStrFirstUp( $str ) {
		return strtoupper(substr($str, 0, 1)) . strtolower(substr($str, 1, strlen($str)));
	}
}
/**
 * Deprecated - class must be created
 */
if (!function_exists('waicDateToTimestamp')) {
	function waicDateToTimestamp( $date ) {
		if (empty($a)) {
			return false;
		}
		$a = explode(WAIC_DATE_DL, $date);
		return mktime(0, 0, 0, $a[1], $a[0], $a[2]);
	}
}
/**
 * Generate random string name
 *
 * @param int $lenFrom min len
 * @param int $lenTo max len
 * @return string random string with length from $lenFrom to $lenTo
 */
if (!function_exists('waicGetRandName')) {
	function waicGetRandName( $lenFrom = 6, $lenTo = 9 ) {
		$res = '';
		$len = mt_rand($lenFrom, $lenTo);
		if ($len) {
			for ($i = 0; $i < $len; $i++) {
				$res .= chr(mt_rand(97, 122)); /*rand symbol from a to z*/
			}
		}
		return $res;
	}
}
if (!function_exists('waicImport')) {
	function waicImport( $path ) {
		if (file_exists($path)) {
			require $path;
			return true;
		}
		return false;
	}
}
if (!function_exists('waicSetDefaultParams')) {
	function waicSetDefaultParams( $params, $default ) {
		foreach ($default as $k => $v) {
			$params[$k] = isset($params[$k]) ? $params[$k] : $default[$k];
		}
		return $params;
	}
}
if (!function_exists('waicImportClass')) {
	function waicImportClass( $class, $path = '' ) {
		if (!class_exists($class)) {
			if (!$path) {
				$classFile = lcfirst($class);
				if (strpos(strtolower($classFile), WAIC_CODE) !== false) {
					$classFile = preg_replace('/' . WAIC_CODE . '/i', '', $classFile);
				}
				$path = WAIC_CLASSES_DIR . lcfirst($classFile) . '.php';
			}
			return waicImport($path);
		}
		return false;
	}
}
/**
 * Check if class name exist with prefix or not
 *
 * @param strin $class preferred class name
 * @return string existing class name
 */
if (!function_exists('waicToeGetClassName')) {
	function waicToeGetClassName( $class ) {
		$className = '';
		if (class_exists(waicStrFirstUp(WAIC_CODE) . $class)) {
			$className = waicStrFirstUp(WAIC_CODE) . $class;
		} else if (class_exists(WAIC_CLASS_PREFIX . $class)) {
			$className = WAIC_CLASS_PREFIX . $class;
		} else {
			$className = $class;
		}
		return $className;
	}
}
/**
 * Create object of specified class
 *
 * @param string $class class that you want to create
 * @param array $params array of arguments for class __construct function
 * @return object new object of specified class
 */
if (!function_exists('waicToeCreateObj')) {
	function waicToeCreateObj( $class, $params ) {
		$className = waicToeGetClassName($class);
		$obj = null;
		if (class_exists('ReflectionClass')) {
			$reflection = new ReflectionClass($className);
			try {
				$obj = $reflection->newInstanceArgs($params);
			} catch (ReflectionException $e) { // If class have no constructor
				$obj = $reflection->newInstanceArgs();
			}
		} else {
			$obj = new $className();
			call_user_func_array(array($obj, '__construct'), $params);
		}
		return $obj;
	}
}

/**
 * BizCity: Network-wide cleanup for removed WAIC modules.
 * Deletes rows from each blog's `${prefix}waic_modules` table so removed modules
 * do not appear anywhere in UI.
 */
if (!function_exists('bizcity_waic_prune_modules_db_network')) {
	function bizcity_waic_prune_modules_db_network() {
		if (!is_admin() || !function_exists('is_multisite') || !is_multisite() || !function_exists('is_super_admin') || !is_super_admin()) {
			return;
		}
		// Only run from Network Admin to avoid slowing normal wp-admin pages.
		if (function_exists('is_network_admin') && !is_network_admin()) {
			return;
		}

		// Allow manual re-run: network admin -> add `?bizcity_waic_prune_modules=1`
		$force = isset($_GET['bizcity_waic_prune_modules']) && $_GET['bizcity_waic_prune_modules'] == '1';
		$flagKey = 'bizcity_waic_modules_pruned_v1';
		$progressKey = 'bizcity_waic_modules_prune_progress_v1';
		if ($force) {
			delete_site_option($flagKey);
			delete_site_option($progressKey);
		}
		if (get_site_option($flagKey)) {
			return;
		}

		if (!function_exists('get_sites')) {
			return;
		}

		$codesToRemove = array('promo', 'gopro', 'magictext', 'chatbots', 'forms', 'postscreate', 'postsfields');
		/**
		 * Optional override.
		 * add_filter('bizcity_waic_prune_modules_codes', fn($codes)=>$codes);
		 */
		if (function_exists('apply_filters')) {
			$codesToRemove = apply_filters('bizcity_waic_prune_modules_codes', $codesToRemove);
		}
		if (!is_array($codesToRemove) || empty($codesToRemove)) {
			update_site_option($flagKey, array('ts' => time(), 'deleted' => 0, 'sites' => 0));
			return;
		}

		global $wpdb;
		$batchSize = 25;
		if (function_exists('apply_filters')) {
			$batchSize = (int) apply_filters('bizcity_waic_prune_modules_batch_size', $batchSize);
		}
		if ($batchSize < 1) {
			$batchSize = 1;
		}

		$progress = get_site_option($progressKey);
		if (!is_array($progress)) {
			$progress = array(
				'offset' => 0,
				'processed' => 0,
				'deleted' => 0,
				'started_ts' => time(),
			);
		}

		$offset = (int) (isset($progress['offset']) ? $progress['offset'] : 0);
		$siteIds = get_sites(array('fields' => 'ids', 'number' => $batchSize, 'offset' => $offset));
		if (empty($siteIds)) {
			update_site_option($flagKey, array(
				'ts' => time(),
				'deleted' => (int) $progress['deleted'],
				'sites' => (int) $progress['processed'],
				'codes' => array_values($codesToRemove),
				'started_ts' => (int) $progress['started_ts'],
			));
			delete_site_option($progressKey);
			return;
		}

		$batchDeleted = 0;
		foreach ($siteIds as $blogId) {
			$blogId = (int) $blogId;
			$prefix = $wpdb->get_blog_prefix($blogId);
			$table = $prefix . WAIC_DB_PREF . 'modules';

			$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			if ($exists === $table) {
				$placeholders = implode(',', array_fill(0, count($codesToRemove), '%s'));
				$sql = "DELETE FROM `{$table}` WHERE `code` IN ({$placeholders})";
				$prepared = $wpdb->prepare($sql, $codesToRemove);
				$res = $wpdb->query($prepared);
				if (is_numeric($res) && $res > 0) {
					$batchDeleted += (int) $res;
				}
			}
		}

		$progress['offset'] = $offset + count($siteIds);
		$progress['processed'] = (int) $progress['processed'] + count($siteIds);
		$progress['deleted'] = (int) $progress['deleted'] + (int) $batchDeleted;
		update_site_option($progressKey, $progress);
	}
}

if (!function_exists('bizcity_waic_prune_modules_db_network_hook')) {
	function bizcity_waic_prune_modules_db_network_hook() {
		bizcity_waic_prune_modules_db_network();
	}
	add_action('admin_init', 'bizcity_waic_prune_modules_db_network_hook', 2);
}
/**
 * Redirect user to specified location. Be advised that it should redirect even if headers alredy sent.
 *
 * @param string $url where page must be redirected
 */
if (!function_exists('waicRedirect')) {
	function waicRedirect( $url ) {
		if (headers_sent()) {
			if ( class_exists('WaicFrame') ) {
				WaicFrame::_()->printInlineJs('document.location.href="' . esc_url($url) . '";');
			}
		} else {
			header('Location: ' . $url);
		}
		exit();
	}
}
if (!function_exists('waicJsonEncodeUTFnormal')) {
	function waicJsonEncodeUTFnormal( $value, $ent = false ) {
		if (is_int($value)) {
			return (string) $value;   
		} elseif (is_string($value)) {
			if ($ent) {
				$value = stripslashes($value);
			}
			
			$value = str_replace(array('\\', '/', '"', "\r", "\n", "\b", "\f", "\t"), 
				$ent ? array('\\\\', '\/', '\"', '\\\\r', '\\\\n', '\\\\b', '\\\\f', '\\\\t') : array('\\\\', '\/', '\"', '\r', '\n', '\b', '\f', '\t'), $value);
			$convmap = array(0x80, 0xFFFF, 0, 0xFFFF);
			$result = '';
			for ($i = strlen($value) - 1; $i >= 0; $i--) {
				$mb_char = substr($value, $i, 1);
				$result = $mb_char . $result;
			}
			return '"' . ( $ent ? htmlspecialchars($result, ENT_QUOTES) : $result ) . '"';                
		} elseif (is_float($value)) {
			return str_replace(',', '.', $value);         
		} elseif (is_null($value)) {
			return 'null';
		} elseif (is_bool($value)) {
			return $value ? 'true' : 'false';
		} elseif (is_array($value)) {
			$with_keys = false;
			$n = count($value);
			for ($i = 0, reset($value); $i < $n; $i++, next($value)) {
				if (key($value) !== $i) {
					$with_keys = true;
					break;
				}
			}
		} elseif (is_object($value)) {
			$with_keys = true;
		} else {
			return '';
		}
		$result = array();
		if ($with_keys) {
			foreach ($value as $key => $v) {
				$result[] = waicJsonEncodeUTFnormal((string) $key, $ent) . ':' . waicJsonEncodeUTFnormal($v, $ent);    
			}
			return '{' . implode(',', $result) . '}';                
		} else {
			foreach ($value as $key => $v) {
				$result[] = waicJsonEncodeUTFnormal($v, $ent);    
			}
			return '[' . implode(',', $result) . ']';
		}
	} 
}
/**
 * Prepares the params values to store into db
 * 
 * @param array $d $_POST array
 * @return array
 */
if (!function_exists('waicPrepareParams')) {
	function waicPrepareParams( &$d = array(), &$options = array() ) {
		if (!empty($d['params'])) {
			if (isset($d['params']['options'])) {
				$options = $d['params']['options'];
			}
			if (is_array($d['params'])) {
				$params = WaicUtils::jsonEncode($d['params']);
				$params = str_replace(array('\n\r', "\n\r", '\n', "\r", '\r', "\r"), '<br />', $params);
				$params = str_replace(array('<br /><br />', '<br /><br /><br />'), '<br />', $params);
				$d['params'] = $params;
			}
		} elseif (isset($d['params'])) {
			$d['params']['attr']['class'] = '';
			$d['params']['attr']['id'] = '';
			$params = WaicUtils::jsonEncode($d['params']);
			$d['params'] = $params;
		}
		if (empty($options)) {
			$options = array('value' => array('EMPTY'), 'data' => array());
		}
		if (isset($d['code'])) {
			if ('' == $d['code']) {
				$d['code'] = waicPrepareFieldCode($d['label']) . '_' . rand(0, 9999999);
			}
		}
		return $d;
	}
}
if (!function_exists('waicPrepareFieldCode')) {
	function waicPrepareFieldCode( $string ) {   
		$string = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $string);
		$string = preg_replace('/\s+/', ' ', $string);
		$string = preg_replace('/ /', '', $string);

		$code = substr($string, 0, 8);
		$code = strtolower($code);
		if ('' == $code) {
			$code = 'field_' . gmdate('dhis');
		}
		return $code;
	}
}
/**
 * Recursive implode of array
 *
 * @param string $glue imploder
 * @param array $array array to implode
 * @return string imploded array in string
 */
if (!function_exists('waicRecImplode')) {
	function waicRecImplode( $glue, $array ) {
		$res = '';
		$i = 0;
		$count = count($array);
		foreach ($array as $el) {
			$str = '';
			if (is_array($el)) {
				$str = waicRecImplode('', $el);
			} else {
				$str = $el;
			}
			$res .= $str;
			if ($i < ( $count-1 )) {
				$res .= $glue;
			}
			$i++;
		}
		return $res;
	}
}
if (!function_exists('aiwuSimpleTextQuery')) {
	function aiwuSimpleTextQuery( $prompt, $options = false ) {
		if (empty($prompt) || !class_exists('WaicFrame')) {
			return false;
		}
		$frame = WaicFrame::_();
		$apiOptions = $frame->getModule('options')->getModel()->getDefaults('api');
		if (!empty($options) && is_array($options)) {
			$apiOptions = array_merge($apiOptions, $options);
		}
		$aiProvider = $frame->getModule('workspace')->getModel('aiprovider')->getInstance($apiOptions);
		if (!$aiProvider) {
			return false;
		}
		$aiProvider->init();
		$aiProvider->setSaveError(false);

		if ($aiProvider->setApiOptions($apiOptions)) {
			$opts = array('prompt' => $prompt);
			$result = $aiProvider->getText($opts);
		} else {
			$result = array(
				'error' => 1,
				'msg' => $frame->getLastError(),
			);
		}
		return $result;
	}
}
if (!function_exists('aiwuAskChatbot')) {
	function aiwuAskChatbot( $botId, $message, $options = false ) {
		$botId = (int) $botId;
		if (empty($botId) || empty($message) || !class_exists('WaicFrame')) {
			return false;
		}
		if (isset($options['user_id'])) {
			$options['user_id'] = (int) $options['user_id'];
		}
		unset($options['ip']);
		if (isset($options['uniq'])) {
			$options['ip'] = substr(str_replace(array("'", '"'), array('', ''), $options['uniq']), 0, 20);
			$options['user_id'] = 0;
		}
		
		$options['use_log'] = isset($options['user_id']) || isset($options['ip']);
		$mode = 0;
		if (!$options['use_log']) {
			$mode = 2;
			$options['user_id'] = 0;
			$options['ip'] = '';
		}
		$result = WaicFrame::_()->getModule('chatbots')->getModel()->sendMessage($message, $botId, (int) $mode, '', false, $options);
		
		return $result;
	}
}

add_action('rest_api_init', function() {
	register_rest_route( 'aiwu/v1', '/simple-text-query/', array(
		'methods'             => 'POST',
		'callback'            => 'aiwuSimpleTextQueryRest',
		'permission_callback' => function( $request ) {
			return aiwuAllowPublicApi('simple-text-query', $request );
		},
	));
});
if (!function_exists('aiwuSimpleTextQueryRest')) {
	function aiwuSimpleTextQueryRest( $request ) {
		try {
			$params = $request->get_params();
			$prompt = isset( $params['prompt'] ) ? $params['prompt'] : '';
			if (empty($prompt)) {
				throw new Exception( 'The promp is required.' );
			}
			$options = isset( $params['options'] ) ? $params['options'] : [];
			$result = aiwuSimpleTextQuery($prompt, $options);
			return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
		}
		catch (Exception $e) {
			return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500 );
		}
	}
}
add_action('rest_api_init', function() {
	register_rest_route( 'aiwu/v1', '/ask-chatbot/', array(
		'methods'             => 'POST',
		'callback'            => 'aiwuAskChatbotRest',
		'permission_callback' => function( $request ) {
			return aiwuAllowPublicApi('ask-chatbot', $request );
		},
	));
});
if (!function_exists('aiwuAskChatbotRest')) {
	function aiwuAskChatbotRest( $request ) {
		try {
			$params = $request->get_params();
			$message = isset( $params['message'] ) ? $params['message'] : '';
			if (empty($message)) {
				throw new Exception( 'The message is required.' );
			}
			$botId = isset( $params['bot_id'] ) ? $params['bot_id'] : ''; 
			if (empty($botId)) {
				throw new Exception( 'The bot_id is required.' );
			}
			$options = isset( $params['options'] ) ? $params['options'] : [];
			$result = aiwuAskChatbot($botId, $message, $options);
			return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
		}
		catch (Exception $e) {
			return new WP_REST_Response(array('success' => false, 'message' => $e->getMessage()), 500 );
		}
	}
}
if (!function_exists('aiwuAllowPublicApi')) {
	function aiwuAllowPublicApi($feature, $extra) {
		$isAdmin = current_user_can('manage_options');
		return apply_filters('aiwu_allow_public_api', $isAdmin, $feature, $extra);
	}
}
// Resume workflow run theo run_id (dùng cho block Delay tự động)
if (!function_exists('waic_resume_workflow')) {
    function waic_resume_workflow($run_id) {
        if (empty($run_id)) return;
        // Lấy model workflow
        if (!class_exists('WaicWorkflowModel')) return;
        $workflowModel = WaicFrame::_()->getModule('workflow')->getModel();
        if (method_exists($workflowModel, 'runModel') && method_exists($workflowModel->runModel, 'startRun')) {
            // Đánh dấu run là Processing
            $workflowModel->runModel->startRun($run_id);
        }
        // Tiếp tục thực thi workflow
        if (method_exists($workflowModel, 'doFlowRun')) {
            $run = $workflowModel->runModel->getById($run_id);
            if ($run) {
                $workflowModel->doFlowRun($run);
            }
        }
    }
}
