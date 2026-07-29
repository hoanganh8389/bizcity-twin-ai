<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoang Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_Tier_Admin_Page
 *
 * Legacy adapter for old cron-tier admin endpoint.
 * Canonical UX was unified into:
 *   admin.php?page=bizcity-twinchat-settings
 *
 * This class now keeps only:
 *   - admin-post handlers for save/sync actions (backward compatibility)
 *   - redirect from old page slug to canonical settings page
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_Tier_Admin_Page {

	const MENU_SLUG    = 'bizcity-cron-tiers';
	const NONCE_ACTION = 'bizcity_cron_tiers_save';
	const ACTION_NAME  = 'bizcity_cron_tiers_save';
	const SYNC_ACTION  = 'bizcity_cron_tiers_sync_now';
	const SYNC_NONCE   = 'bizcity_cron_tiers_sync_now';

	public static function register(): void {
		// [2026-07-15 Johnny Chu] R-CRON-TIER-UNIFY - keep handlers and redirect legacy page to TwinChat settings.
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_page' ), 1 );
		add_action( 'admin_post_' . self::ACTION_NAME, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::SYNC_ACTION, array( __CLASS__, 'handle_sync_now' ) );
	}

	public static function maybe_redirect_legacy_page(): void {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( empty( $_GET['page'] ) || sanitize_key( (string) $_GET['page'] ) !== self::MENU_SLUG ) {
			return;
		}
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	public static function enqueue_assets(): void {
		// [2026-07-15 Johnny Chu] R-CRON-TIER-UNIFY - deprecated: standalone page removed.
		return;
	}

	public static function add_menu(): void {
		// [2026-07-15 Johnny Chu] R-CRON-TIER-UNIFY - deprecated: standalone menu removed.
		return;
	}

	private static function cap(): string {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	private static function settings_url(): string {
		return admin_url( 'admin.php?page=bizcity-twinchat-settings' );
	}

	private static function redirect_url( array $args = array() ): string {
		$url = self::settings_url();
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	public static function handle_save(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		check_admin_referer( self::NONCE_ACTION );

		$minutes = array();
		foreach ( array( 'free', 'pro', 'premium', 'enterprise' ) as $tier ) {
			$field = 'min_' . $tier;
			if ( isset( $_POST[ $field ] ) ) {
				$minutes[ $tier ] = (int) $_POST[ $field ];
			}
		}
		BizCity_Cron_Tier_Settings::update_tier_minutes( $minutes );

		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH - remove legacy central dual-write from hard-cut defaults.
		$dual = false;
		$read = ! empty( $_POST['file_first_read'] );
		// [2026-07-15 Johnny Chu] PHASE-FILE-PRIMARY - persist file-primary write rollout flag.
		$primary = ! empty( $_POST['file_primary_write'] );
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH - persist graph embedding migration cron flag.
		$graph_embedding_migration = ! empty( $_POST['graph_embedding_migration'] );
		// [2026-07-23 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH - persist triplet raw payload migration cron flag.
		$triplet_raw_migration = isset( $_POST['triplet_raw_migration'] )
			? ! empty( $_POST['triplet_raw_migration'] )
			: ( method_exists( 'BizCity_Cron_Tier_Settings', 'is_triplet_raw_migration_enabled' )
				? BizCity_Cron_Tier_Settings::is_triplet_raw_migration_enabled()
				: (bool) get_option( 'bizcity_kg_v09_triplet_raw_migration_enabled', true ) );
		BizCity_Cron_Tier_Settings::set_file_first( $dual, $read );
		BizCity_Cron_Tier_Settings::set_file_primary_write( $primary );
		BizCity_Cron_Tier_Settings::set_graph_embedding_migration( $graph_embedding_migration );
		if ( method_exists( 'BizCity_Cron_Tier_Settings', 'set_triplet_raw_migration' ) ) {
			BizCity_Cron_Tier_Settings::set_triplet_raw_migration( $triplet_raw_migration );
		}

		if ( class_exists( 'BizCity_KG_Filestore_Backfill' ) ) {
			BizCity_KG_Filestore_Backfill::instance()->bind();
		}
		if ( class_exists( 'BizCity_KG_Graph_Embedding_Migration' ) ) {
			BizCity_KG_Graph_Embedding_Migration::instance()->bind();
		}
		if ( class_exists( 'BizCity_KG_Triplet_Raw_Migration' ) ) {
			BizCity_KG_Triplet_Raw_Migration::instance()->bind();
		}

		wp_safe_redirect( self::redirect_url( array( 'cron_saved' => '1' ) ) );
		exit;
	}

	public static function handle_sync_now(): void {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bizcity-twin-ai' ), 403 );
		}
		check_admin_referer( self::SYNC_NONCE );

		$ok = false;
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm->is_ready() ) {
				$cfg = $llm->get_plan_config(
					array(
						'force_refresh' => true,
						'timeout'       => 8,
					)
				);
				if ( is_array( $cfg ) && ! empty( $cfg['ok'] ) ) {
					update_option( 'bizcity_hub_master_sync_ts', time() );
					$ok = true;
				}
			}
		}

		wp_safe_redirect( self::redirect_url( array( 'cron_synced' => $ok ? '1' : '0' ) ) );
		exit;
	}

	public static function render(): void {
		// [2026-07-15 Johnny Chu] R-CRON-TIER-UNIFY - hard redirect if legacy callback is invoked.
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		wp_safe_redirect( self::settings_url() );
		exit;
	}
}
