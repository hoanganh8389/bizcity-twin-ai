<?php
/**
 * BizCity REST Error trait — compatibility shim.
 *
 * [2026-09-25 Claude Sonnet 5] CORE-REDUCTION — the trait moved to core/helper/includes/trait-rest-error.php
 * (a shipped core). This shim keeps the old path loading for the private Diagnostics package.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! trait_exists( 'BizCity_REST_Error', false ) && class_exists( 'BizCity_Safe_Loader', false ) ) {
	BizCity_Safe_Loader::require_file( dirname( __DIR__, 2 ) . '/helper/includes/trait-rest-error.php', 'diagnostics.rest_error_trait_shim' );
}
