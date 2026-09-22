<?php
/**
 * BizCity CRM — pipeline platform loader (PHASE-0.63A, lane S / master §4 S-4).
 *
 * The one and only entry point of the 0.6x pipeline platform. `bootstrap.php` requires this file and
 * nothing else, which is the whole point: five lanes are working in parallel, and if each of them had
 * to add its own require line to `bootstrap.php` every merge would collide in the same twenty lines.
 *
 * A lane adds a file to `includes/pipeline/`, `includes/context/` (framework only) or `apps/<key>/`
 * (a Context App's own implementation — see `apps/README.md`) and it loads. No bootstrap edit, no merge
 * conflict. Loading goes through `BizCity_Safe_Loader` because `bootstrap.php` has already
 * crashed production once on a hard require of a file a partial deploy had not shipped yet
 * (0.60 M17-02); a half-deployed pipeline must degrade, not white-screen the CRM.
 *
 * Load order matters only for the few files that others read at include time, so those are listed
 * first; everything else is globbed alphabetically.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-0/WP-1)
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_CRM_PIPELINE_LOADED' ) ) {
	return;
}
define( 'BIZCITY_CRM_PIPELINE_LOADED', true );

if ( ! function_exists( 'bizcity_crm_pipeline_require' ) ) {
	/**
	 * Guarded include for one pipeline artifact.
	 *
	 * @param string $path  Absolute path.
	 * @param string $label Redacted diagnostics label.
	 */
	function bizcity_crm_pipeline_require( string $path, string $label ): bool {
		if ( class_exists( 'BizCity_Safe_Loader', false ) ) {
			return BizCity_Safe_Loader::require_file( $path, $label );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		require_once $path;
		return true;
	}
}

if ( ! function_exists( 'bizcity_crm_pipeline_load_dir' ) ) {
	/**
	 * Load `class-*.php` from one directory: the priority list first, then everything else.
	 *
	 * @param string   $dir      Absolute directory path (with trailing slash).
	 * @param string[] $priority Basenames that must load before their siblings.
	 * @param string   $scope    Label prefix.
	 */
	function bizcity_crm_pipeline_load_dir( string $dir, array $priority, string $scope ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$loaded = array();
		foreach ( $priority as $basename ) {
			$path = $dir . $basename;
			if ( is_file( $path ) ) {
				bizcity_crm_pipeline_require( $path, $scope . '.' . basename( $basename, '.php' ) );
				$loaded[ $basename ] = true;
			}
		}

		$rest = glob( $dir . 'class-*.php' );
		if ( ! is_array( $rest ) ) {
			return;
		}
		sort( $rest );
		foreach ( $rest as $path ) {
			$basename = basename( $path );
			if ( isset( $loaded[ $basename ] ) ) {
				continue;
			}
			bizcity_crm_pipeline_require( $path, $scope . '.' . basename( $basename, '.php' ) );
		}
	}
}

$bizcity_crm_pipeline_dir = __DIR__ . '/';
$bizcity_crm_context_dir  = dirname( __DIR__ ) . '/context/';

bizcity_crm_pipeline_load_dir(
	$bizcity_crm_pipeline_dir,
	array(
		// The registry is what every other pipeline class reads a definition through.
		'class-pipeline-registry.php',
		'class-pipeline-cpt.php',
		'class-pipeline-seeder.php',
		'class-pipeline-roles.php',
		'class-pipeline-sla-clock.php',
		'class-pipeline-sla-service.php',
		'class-pipeline-run-service.php',
		'class-pipeline-sla-runner.php',
		'class-pipeline-notify.php',
		'class-pipeline-retention.php',
	),
	'crm.pipeline'
);

// Lane C0 (PHASE-0.63B) lands the core-entity contracts and the Context App resolver here. The
// directory may not exist yet — that is expected, not an error.
bizcity_crm_pipeline_load_dir(
	$bizcity_crm_context_dir,
	array(
		'class-context-app-registry.php',
		'class-context-resolver.php',
	),
	'crm.context'
);

unset( $bizcity_crm_pipeline_dir, $bizcity_crm_context_dir );

// [2026-09-21] Context App IMPLEMENTATIONS live in `apps/<key>/`, not `includes/context/` — that directory is
// framework only (registry + resolver, lane C0, frozen after). See `apps/README.md` for the three-way split.
// Each `manifest.php` self-registers into `bizcity_crm_register_context_apps`, so requiring it is the whole job;
// a lane adds a subdirectory and this loop picks it up without anyone touching this file again.
$bizcity_crm_apps_dir = dirname( __DIR__, 2 ) . '/apps/';
if ( is_dir( $bizcity_crm_apps_dir ) ) {
	$bizcity_crm_app_manifests = glob( $bizcity_crm_apps_dir . '*/manifest.php' );
	if ( is_array( $bizcity_crm_app_manifests ) ) {
		sort( $bizcity_crm_app_manifests );
		foreach ( $bizcity_crm_app_manifests as $bizcity_crm_app_manifest ) {
			bizcity_crm_pipeline_require(
				$bizcity_crm_app_manifest,
				'crm.app.' . basename( dirname( $bizcity_crm_app_manifest ) )
			);
		}
		unset( $bizcity_crm_app_manifest );
	}
	unset( $bizcity_crm_app_manifests );
}
unset( $bizcity_crm_apps_dir );

add_action( 'init', static function () {
	if ( class_exists( 'BizCity_CRM_Pipeline_CPT' ) ) {
		BizCity_CRM_Pipeline_CPT::register();
	}
	// [2026-09-22 PHASE-0.63A WP-1.7] Seed only missing built-ins on an authorized admin request.
	if ( class_exists( 'BizCity_CRM_Pipeline_Seeder' ) ) {
		BizCity_CRM_Pipeline_Seeder::register();
	}
	if ( class_exists( 'BizCity_CRM_Pipeline_Retention' ) ) {
		BizCity_CRM_Pipeline_Retention::register();
	}
	if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Runner' ) ) {
		BizCity_CRM_Pipeline_SLA_Runner::register();
	}
}, 5 );
