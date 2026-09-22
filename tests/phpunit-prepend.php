<?php
/**
 * PHPUnit preload: define ABSPATH before Composer's eager `files` autoload runs.
 *
 * Why this exists
 * ---------------
 * composer.json lists `core/bizcity-llm/includes/helpers-deprecation.php` under
 * `autoload.files`. PHPUnit's own launcher requires the Composer autoloader
 * *before* it loads `tests/bootstrap.php`, so ABSPATH is still undefined at that
 * point and that helper runs `defined( 'ABSPATH' ) || exit;`. The result is a
 * PHPUnit process that terminates with exit code 0 and **no output at all**,
 * which reads as a green build in CI while zero tests actually ran.
 *
 * Wiring
 * ------
 * - `composer test` passes this file via `php -d auto_prepend_file=...`.
 * - CI calls `composer test`, so it inherits the same preload.
 *
 * The value only needs to exist; `tests/bootstrap.php` keeps the same guarded
 * definition and WordPress never loads this file.
 *
 * [2026-09-15 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G7 — unblock real unit evidence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
}