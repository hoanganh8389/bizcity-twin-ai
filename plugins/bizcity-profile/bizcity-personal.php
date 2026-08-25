<?php
/**
 * Plugin Name: BizCity Personal
 * Plugin URI:  https://bizcity.vn
 * Description: Trợ lý cá nhân: lịch, việc, ngân sách, tài liệu, nhật ký và chat.
 * Version:     1.5.0
 * Author:      BizCity
 * Text Domain: bizcity-twin-ai
 * Requires PHP: 7.4
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// [2026-08-25 Johnny Chu] PHASE-1.24 — bootstrap the shared guarded loader before loading the Profile module entrypoint.
$_profile_safe_loader = dirname( dirname( __DIR__ ) ) . '/core/helper/class-bizcity-safe-loader.php';
if ( is_file( $_profile_safe_loader ) && is_readable( $_profile_safe_loader ) ) {
	require_once $_profile_safe_loader;
}
unset( $_profile_safe_loader );
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
BizCity_Safe_Loader::require_file( __DIR__ . '/bootstrap.php', 'profile.bootstrap' );
