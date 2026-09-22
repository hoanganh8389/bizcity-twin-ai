<?php
/**
 * Broken fixture: client code reading the server-only provider key option.
 *
 * Must report `R-GW8.provider_key_option_referenced`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Bad_Key_Read {

	public function debug_key(): string {
		return (string) get_option( 'bizcity_openrouter_api_key' );
	}
}
