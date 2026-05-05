<?php
/**
 * Twinsource — bootstrap.
 *
 * Standard source-management panel used across all bizcity-twin-ai plugins.
 * See PHASE-6.1-TWINSOURCE-STANDARD.md for the full contract.
 *
 * @package Bizcity_Twin_AI\Twinsource
 * @since   0.1.0 (Wave 0 — scaffold only)
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_TWINSOURCE_LOADED' ) ) {
	return;
}
define( 'BIZCITY_TWINSOURCE_LOADED', true );
define( 'BIZCITY_TWINSOURCE_DIR',     __DIR__ );
define( 'BIZCITY_TWINSOURCE_URL',     plugins_url( '', __FILE__ ) );
define( 'BIZCITY_TWINSOURCE_VERSION', '0.2.0-wave1' );

require_once __DIR__ . '/includes/class-twinsource-registry.php';
require_once __DIR__ . '/includes/class-twinsource.php';
require_once __DIR__ . '/includes/class-twinsource-rest.php';

add_action( 'rest_api_init', [ 'BizCity_Twinsource_REST', 'register_routes' ] );

/**
 * Convenience renderer — host page calls this where the panel should appear.
 *
 * @param array $args See BizCity_Twinsource::render() for shape.
 */
function twinsource_render( array $args ): void {
	BizCity_Twinsource::render( $args );
}
