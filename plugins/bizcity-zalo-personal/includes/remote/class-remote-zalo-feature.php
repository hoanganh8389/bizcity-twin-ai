<?php
/** Remote Zalo Hub feature gate — C0. */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Remote_Zalo_Feature', false ) ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C0 — centralize the default-off remote feature gate for every remote loader/route/cron.
final class BizCity_Remote_Zalo_Feature {
	public static $override = null;
	public static function enabled(): bool {
		return null !== self::$override ? true === self::$override : ( defined( 'BIZCITY_ZALO_REMOTE_HUB_ENABLED' ) && true === BIZCITY_ZALO_REMOTE_HUB_ENABLED );
	}
	public static function reset(): void { self::$override = null; }
}
