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

// [2026-08-19 Johnny Chu] HOTFIX — provide the bundle entrypoint required by the Twin AI plugin loader.
require_once __DIR__ . '/bootstrap.php';
