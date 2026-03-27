<?php

/**
 * Base config constants and functions
 */
 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register WAIC Workflow cron intervals EARLY
 * Must be done before any code calls wp_get_schedules() to avoid caching issues
 * @since 1.4.5
 */
add_filter( 'cron_schedules', 'waic_register_cron_intervals_early', 1 );
function waic_register_cron_intervals_early( $schedules ) {
	if ( ! isset( $schedules['waic_interval1'] ) ) {
		$schedules['waic_interval1'] = array(
			'interval' => 60, // 1 minute
			'display'  => 'Every Minute (WAIC)',
		);
	}
	if ( ! isset( $schedules['waic_interval5'] ) ) {
		$schedules['waic_interval5'] = array(
			'interval' => 300, // 5 minutes
			'display'  => 'Every 5 Minutes (WAIC)',
		);
	}
	return $schedules;
}

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'functions.php';
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
/**
 * Connect all required core classes
 */
waicImportClass('WaicAIProviderInterface');
waicImportClass('WaicDb');
waicImportClass('WaicInstaller');
waicImportClass('WaicBaseObject');
waicImportClass('WaicModule');
waicImportClass('WaicModel');
waicImportClass('WaicView');
waicImportClass('WaicController');
waicImportClass('WaicHelper');
waicImportClass('WaicDispatcher');
waicImportClass('WaicField');
waicImportClass('WaicTable');
waicImportClass('WaicFrame');

waicImportClass('WaicReq');
waicImportClass('WaicUri');
waicImportClass('WaicHtml');
waicImportClass('WaicResponse');
waicImportClass('WaicFieldAdapter');
waicImportClass('WaicValidator');
waicImportClass('WaicErrors');
waicImportClass('WaicUtils');
waicImportClass('WaicModInstaller');
waicImportClass('WaicInstallerDbUpdater');
waicImportClass('WaicDate');
waicImportClass('WaicAssets');
waicImportClass('WaicCache');
waicImportClass('WaicUser');
waicImportClass('WaicBuilderBlock');
waicImportClass('WaicIntegration');
/**
 * Check plugin version - maybe we need to update database, and check global errors in request
 */
WaicInstaller::update();
WaicErrors::init();

/**
 * Register Action Scheduler hooks for workflow parallel execution
 */
#add_action('waic_run_workflow_branch', array(WaicFrame::_()->getModule('workflow')->getModel(), 'runWorkflowBranch'));

/**
 * Start application
 */
WaicFrame::_()->parseRoute();
WaicFrame::_()->init();


WaicFrame::_()->exec();
