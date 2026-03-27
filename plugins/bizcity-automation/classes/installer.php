<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WaicInstaller {
	public static $update_to_version_method = '';
	private static $_firstTimeActivated = false;
	private static function getSeedTemplatesOptionName() {
		global $wpdb;
		// Keep option per-site in multisite via prefix.
		return $wpdb->prefix . WAIC_DB_PREF . 'seed_templates_done';
	}
	public static function init( $isUpdate = false ) {
		global $wpdb;
		$wpPrefix = $wpdb->prefix; /* add to 0.0.3 Versiom */
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$current_version = get_option($wpPrefix . WAIC_DB_PREF . 'db_version', 0);
		if (!$current_version || version_compare(WAIC_VERSION, $current_version, '>')	) {
			self::$_firstTimeActivated = true;
		}
		/**
		 * Table modules 
		 */
		if (!WaicDb::exist('@__modules')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__modules` (
				`id` smallint(3) NOT NULL AUTO_INCREMENT,
				`code` varchar(32) NOT NULL,
				`active` tinyint(1) NOT NULL DEFAULT '0',
				`type_id` tinyint(1) NOT NULL DEFAULT '0',
				`label` varchar(64) DEFAULT NULL,
				`ex_plug_dir` varchar(255) DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE INDEX `code` (`code`)
			) DEFAULT CHARSET=utf8;"));
			WaicDb::query("INSERT INTO `@__modules` (id, code, active, type_id, label) VALUES
				(NULL, 'adminmenu',1,1,'Admin Menu'),
				(NULL, 'options',1,1,'Options'),
				(NULL, 'workspace',1,1,'Workspace'),
				(NULL, 'workflow',1,1,'Workflow');");
		}
		
		/**
		 * Table workspace
		 */
		if (!WaicDb::exist('@__workspace')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__workspace` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(10) NOT NULL,
				`value` VARCHAR(128) NOT NULL,
				`flag` INT NOT NULL DEFAULT 0,
				`timeout` INT NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
			  UNIQUE INDEX `code` (`name`)
			) DEFAULT CHARSET=utf8;"));
			WaicDb::query("INSERT INTO `@__workspace` (id, name, value, flag, timeout) VALUES
				(1, 'task', 0, 0, 0),
				(2, 'flag', 0, 0, 0),
				(3, 'publish', 0, 0, 0),
				(11, 'flow', 0, 0, 0);");
		}
		/**
		 * Table tasks
		 */
		if (!WaicDb::exist('@__tasks')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__tasks` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`feature` VARCHAR(24) NOT NULL,
				`author` INT NOT NULL DEFAULT 0,
				`title` VARCHAR(250) DEFAULT '',
				`params` MEDIUMTEXT NOT NULL,
				`cnt` INT NOT NULL DEFAULT 0,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated` TIMESTAMP NULL,
				`start` TIMESTAMP NULL,
				`end` TIMESTAMP NULL,
				`step` INT NOT NULL DEFAULT 0,
				`steps` INT NOT NULL DEFAULT 0,
				`cycle` INT NOT NULL DEFAULT 0,
				`message` VARCHAR(250) DEFAULT '',
				`tokens` BIGINT NOT NULL DEFAULT 0,
				`mode` VARCHAR(24) DEFAULT '',
				`obj_id` BIGINT NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`)
			) DEFAULT CHARSET=utf8mb4;"));
		}
		
		/**
		 * Table workflows
		 */
		if (!WaicDb::exist('@__workflows')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__workflows` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`task_id` INT NOT NULL DEFAULT 0,
				`version` INT NOT NULL DEFAULT 0,
				`params` MEDIUMTEXT NOT NULL,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`tr_id` INT NOT NULL DEFAULT 0,
				`tr_code` VARCHAR(30) DEFAULT '',
				`tr_type` TINYINT(1) NOT NULL DEFAULT 0,
				`sch_start` TIMESTAMP NULL,
				`sch_period` INT NOT NULL DEFAULT 0,
				`tr_hook` VARCHAR(500) DEFAULT '',
				`timeout` INT NOT NULL,
				`flags` CHAR(10) DEFAULT '',
				`created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated` TIMESTAMP NULL,
				PRIMARY KEY (`id`),
				UNIQUE INDEX `task_id` (`task_id`, `version`, `tr_id`),
				INDEX `status` (`status`, `tr_type`)
			) DEFAULT CHARSET=utf8mb4;"));
		}
		
		/**
		 * Table flowruns
		 */
		if (!WaicDb::exist('@__flowruns')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__flowruns` (
				`id` BIGINT NOT NULL AUTO_INCREMENT,
				`task_id` INT NOT NULL DEFAULT 0,
				`fl_id` INT NOT NULL DEFAULT 0,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`params` MEDIUMTEXT NOT NULL,
				`obj_id` BIGINT NOT NULL DEFAULT 0,
				`tokens` INT NOT NULL DEFAULT 0,
				`added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`started` TIMESTAMP NULL,
				`ended` TIMESTAMP NULL,
				`log_id` INT NOT NULL DEFAULT 0,
				`waiting` INT NOT NULL DEFAULT 0,
				`error` VARCHAR(500) DEFAULT '',
				PRIMARY KEY (`id`),
				INDEX `fl_id` (`fl_id`),
				INDEX `status` (`status`, `task_id`)
			) DEFAULT CHARSET=utf8mb4;"));
		}
		
		/**
		 * Table flowlogs
		 */
		if (!WaicDb::exist('@__flowlogs')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__flowlogs` (
				`id` BIGINT NOT NULL AUTO_INCREMENT,
				`run_id` BIGINT NOT NULL DEFAULT 0,
				`bl_type` TINYINT(1) NOT NULL DEFAULT 0,
				`bl_id` INT NOT NULL DEFAULT 0,
				`bl_code` VARCHAR(30) DEFAULT '',
				`parent` INT NOT NULL DEFAULT 0,
				`step` INT NOT NULL DEFAULT 0,
				`cnt` INT NOT NULL DEFAULT 0,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`result` MEDIUMTEXT NULL,
				`started` TIMESTAMP NULL,
				`ended` TIMESTAMP NULL,
				`error` VARCHAR(500) DEFAULT '',
				PRIMARY KEY (`id`),
				INDEX `run_id` (`run_id`, `bl_id`, `step`)
			) DEFAULT CHARSET=utf8mb4;"));
		}

		// ✅ MIGRATION: nếu bảng đã tồn tại từ trước (result NOT NULL) thì sửa lại thành NULL
		try {
			if (WaicDb::exist('@__flowlogs')) {
				WaicDb::query(WaicDb::prepareQuery(
					"ALTER TABLE `@__flowlogs` MODIFY `result` MEDIUMTEXT NULL"
				));
			}
		} catch (\Throwable $e) {
			// không block activation/update nếu ALTER fail
			error_log('[AIWU][installer] flowlogs.result migrate error: ' . $e->getMessage());
		}

		/**
		 * Table posts_create
		 */
		if (!WaicDb::exist('@__posts_create')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__posts_create` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`task_id` INT NOT NULL DEFAULT 0,
				`num` INT NOT NULL DEFAULT 0,
				`params` MEDIUMTEXT NOT NULL,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`updated` TIMESTAMP NULL,
				`start` TIMESTAMP NULL,
				`end` TIMESTAMP NULL,
				`flag` TINYINT NOT NULL DEFAULT 0,
				`step` MEDIUMINT NOT NULL DEFAULT 0,
				`steps` MEDIUMINT NOT NULL DEFAULT 0,
				`results` MEDIUMTEXT NOT NULL,
				`pub_mode` TINYINT(1) NOT NULL DEFAULT 0,
				`publish` TIMESTAMP NULL,
				`post_id` INT NOT NULL DEFAULT 0,
				`added` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`uniq` VARCHAR(32) NULL,
				PRIMARY KEY (`id`),
				INDEX `task_id` (`task_id`),
				INDEX `task_uniq` (`uniq`)
			) DEFAULT CHARSET=utf8mb4;"));
		} 
		/**
		 * Table history
		 */
		if (!WaicDb::exist('@__history')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__history` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`task_id` INT NOT NULL DEFAULT 0,
				`feature` VARCHAR(24) NOT NULL,
				`user_id` INT NOT NULL DEFAULT 0,
				`ip` VARCHAR(20) DEFAULT '',
				`engine` VARCHAR(20) DEFAULT '',
				`model` VARCHAR(30) DEFAULT '',
				`mode` TINYINT(1) NOT NULL DEFAULT 0,
				`created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`status` TINYINT(1) NOT NULL DEFAULT 0,
				`tokens` BIGINT NOT NULL DEFAULT 0,
				`cost` decimal(19,4),
				PRIMARY KEY (`id`),
				INDEX `task_id` (`task_id`)
			) DEFAULT CHARSET=utf8mb4;"));
		}
		/**
		 * Table chatlogs
		 */
		if (!WaicDb::exist('@__chatlogs')) {
			dbDelta(WaicDb::prepareQuery("CREATE TABLE IF NOT EXISTS `@__chatlogs` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`his_id` INT NOT NULL DEFAULT 0,
				`question` MEDIUMTEXT NOT NULL,
				`answer` MEDIUMTEXT NOT NULL,
				`file` MEDIUMTEXT NOT NULL,
				PRIMARY KEY (`id`),
				INDEX `task_id` (`his_id`)
			) DEFAULT CHARSET=utf8mb4;"));
		}
		
		WaicInstallerDbUpdater::runUpdate($current_version);
		if ($current_version && !self::$_firstTimeActivated) {
			self::setUsed();
		}
		update_option($wpPrefix . WAIC_DB_PREF . 'db_version', WAIC_VERSION);
		add_option($wpPrefix . WAIC_DB_PREF . 'db_installed', 1);
		self::setFirstActivation();
	}
	public static function setFirstActivation() {
		if (get_option(WAIC_DB_PREF . 'first_activation', false) === false) {
			update_option(WAIC_DB_PREF . 'first_activation', 1);
		}
	}
	public static function setUsed() {
		update_option(WAIC_DB_PREF . 'plug_was_used', 1);
	}
	public static function isUsed() {
		return (int) get_option(WAIC_DB_PREF . 'plug_was_used');
	}
	public static function delete() {
		global $wpdb;
		$wpPrefix = $wpdb->prefix;
		$wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . esc_sql(WAIC_DB_PREF) . 'modules`');
		delete_option($wpPrefix . WAIC_DB_PREF . 'db_version');
		delete_option($wpPrefix . WAIC_DB_PREF . 'db_installed');
	}
	public static function deactivate() {
		wp_clear_scheduled_hook('waic_run_generation_task');
		wp_clear_scheduled_hook('waic_run_delayed_actions');
		wp_clear_scheduled_hook('waic_create_scheduled_flow');
		wp_clear_scheduled_hook('waic_run_workflow');
		WaicFrame::_()->getModule('workspace')->getModel()->setStoppingTaskGeneration();
	}
	public static function update() {
		global $wpdb;
		$wpPrefix = $wpdb->prefix;
		$currentVersion = get_option($wpPrefix . WAIC_DB_PREF . 'db_version', 0);
		// Allow re-running selected DB updates (e.g. template seeding) without bumping WAIC_VERSION.
		if (defined('WAIC_FORCE_SEED_TEMPLATES') && WAIC_FORCE_SEED_TEMPLATES) {
			$markerVersion = defined('WAIC_FORCE_SEED_TEMPLATES_VERSION') ? (string) WAIC_FORCE_SEED_TEMPLATES_VERSION : '1';
			$optName = self::getSeedTemplatesOptionName();
			$already = (string) get_option($optName, '');
			if ($already !== $markerVersion) {
				WaicInstallerDbUpdater::runUpdate($currentVersion);
				update_option($optName, $markerVersion);
			}
			return;
		}
		if (!$currentVersion || version_compare(WAIC_VERSION, $currentVersion, '>')) {
			self::init( true );
			update_option($wpPrefix . WAIC_DB_PREF . 'db_version', WAIC_VERSION);
		}
	}
}
