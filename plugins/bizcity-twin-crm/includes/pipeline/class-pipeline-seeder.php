<?php
/**
 * BizCity CRM — built-in pipeline template seeder (PHASE-0.63A WP-1.7).
 *
 * Seeds only missing built-in definitions on the current multisite blog. Existing definitions are
 * never overwritten: team-lead edits are tenant data and must survive plugin upgrades. The seeder is
 * deliberately disabled for diagnostics so read-only probes cannot mutate a tenant.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-22 (PHASE-0.63A WP-1.7)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_Seeder', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_Seeder {

	/** @var bool */
	private static $registered = false;

	/** @var bool */
	private static $ran = false;

	/** Register the admin-only repair/seed hook. */
	public static function register(): void {
		if ( self::$registered || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ), 20 );
	}

	/**
	 * Seed missing built-ins for the current blog without overwriting tenant definitions.
	 *
	 * @return array{seeded:string[],skipped:string[],errors:array<string,string>,status:string}
	 */
	public static function maybe_seed(): array {
		if ( self::$ran ) {
			return array(
				'seeded'  => array(),
				'skipped' => array(),
				'errors'  => array(),
				'status'  => 'already_ran',
			);
		}
		self::$ran = true;

		// [2026-09-22 PHASE-0.63A WP-1.7] Diagnostics must remain read-only, including on admin_init.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return array(
				'seeded'  => array(),
				'skipped' => array(),
				'errors'  => array(),
				'status'  => 'diagnostics_skipped',
			);
		}

		if ( ! self::can_seed() ) {
			return array(
				'seeded'  => array(),
				'skipped' => array(),
				'errors'  => array(),
				'status'  => 'permission_skipped',
			);
		}

		return self::seed_missing_templates();
	}

	/**
	 * Import every built-in template whose kind is not present on this blog.
	 *
	 * This public method is also the deterministic seam for WP-CLI/admin tests. It does not perform
	 * capability checks; callers crossing a request boundary must use maybe_seed().
	 *
	 * @return array{seeded:string[],skipped:string[],errors:array<string,string>,status:string}
	 */
	public static function seed_missing_templates(): array {
		$result = array(
			'seeded'  => array(),
			'skipped' => array(),
			'errors'  => array(),
			'status'  => 'complete',
		);

		if ( ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			$result['status'] = 'registry_missing';
			$result['errors']['_registry'] = 'pipeline_registry_missing';
			return $result;
		}

		foreach ( (array) BizCity_CRM_Pipeline_Registry::templates() as $kind ) {
			$kind = self::clean_kind( (string) $kind );
			if ( '' === $kind ) {
				continue;
			}

			if ( is_array( BizCity_CRM_Pipeline_Registry::get( $kind ) ) ) {
				$result['skipped'][] = $kind;
				continue;
			}

			$definition = BizCity_CRM_Pipeline_Registry::template( $kind );
			if ( ! is_array( $definition ) ) {
				$result['errors'][ $kind ] = 'template_unreadable';
				continue;
			}

			$imported = BizCity_CRM_Pipeline_Registry::import_definition( $definition, false );
			if ( is_wp_error( $imported ) ) {
				$result['errors'][ $kind ] = (string) $imported->get_error_code();
				continue;
			}
			$result['seeded'][] = $kind;
		}

		if ( ! empty( $result['errors'] ) ) {
			$result['status'] = 'completed_with_errors';
		} elseif ( empty( $result['seeded'] ) ) {
			$result['status'] = 'nothing_to_seed';
		}
		return $result;
	}

	private static function can_seed(): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}
		return current_user_can( 'bizcity_crm_manage_rules' )
			|| current_user_can( 'manage_options' )
			|| current_user_can( 'manage_network' );
	}

	private static function clean_kind( string $kind ): string {
		return preg_match( '/^[a-z][a-z0-9_-]{1,31}$/', $kind ) ? $kind : '';
	}
}
