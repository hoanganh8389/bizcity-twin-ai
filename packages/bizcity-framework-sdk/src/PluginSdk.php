<?php
/**
 * Optional runtime forwarding facade for the deployment-neutral SDK.
 *
 * @package BizCity\FrameworkSdk
 * @since 1.2.0
 */

namespace BizCity\Twin\Sdk;

final class PluginSdk {

	public static function register_plugin( $module ) {
		return self::forward( 'register_plugin', array( $module ) );
	}

	public static function register_tool( $tool ) {
		return self::forward( 'register_tool', array( $tool ) );
	}

	public static function register_skill( $skill ) {
		return self::forward( 'register_skill', array( $skill ) );
	}

	public static function register_source( $source ) {
		return self::forward( 'register_source', array( $source ) );
	}

	public static function register_event( $event_type, array $definition = array() ) {
		return self::forward( 'register_event', array( $event_type, $definition ) );
	}

	public static function register_diagnostic( $probe ) {
		return self::forward( 'register_diagnostic', array( $probe ) );
	}

	public static function register_ui( array $definition ) {
		return self::forward( 'register_ui', array( $definition ) );
	}

	private static function forward( $method, array $args ) {
		if ( ! class_exists( 'BizCity_Twin_Plugin_SDK' ) || ! method_exists( 'BizCity_Twin_Plugin_SDK', $method ) ) {
			return false;
		}
		return call_user_func_array( array( 'BizCity_Twin_Plugin_SDK', $method ), $args );
	}
}