<?php
/**
 * Read-only route/loader/runtime probe for the six B2B2C product pages.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_B2B2C_Product_Pages', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Product_Pages implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-02 Johnny Chu] B2C-F3 — identify the six canonical public product route probe.
		return 'b2b2c.product_pages';
	}

	public function label(): string {
		// [2026-09-02 Johnny Chu] B2C-F3 — expose the product route probe label.
		return 'B2B2C product pages';
	}

	public function description(): string {
		// [2026-09-02 Johnny Chu] B2C-F3 — describe read-only route, loader and payload evidence.
		return 'Checks the six canonical product page artifacts, loader registration and runtime catalog payload without database or provider side effects.';
	}

	public function severity(): string {
		// [2026-09-02 Johnny Chu] B2C-F3 — product route availability is a critical public release contract.
		return 'critical';
	}

	public function order(): int {
		// [2026-09-02 Johnny Chu] B2C-F3 — run after Hub account gates and before heavier product probes.
		return 22;
	}

	public function icon(): string {
		// [2026-09-02 Johnny Chu] B2C-F3 — use a route icon in diagnostics.
		return 'layout-template';
	}

	public function estimate_ms(): int {
		// [2026-09-02 Johnny Chu] B2C-F3 — this probe is read-only and bounded to six catalog entries.
		return 180;
	}

	public function precondition() {
		// [2026-09-02 Johnny Chu] B2C-F3 — product pages are owned by the B1 public Hub surface.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: canonical product pages are owned by bizcity.vn.';
		}

		$router_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-llm-router/includes/' : '';
		$file = $router_dir . 'class-router-product-pages.php';
		if ( ! class_exists( 'BizCity_Router_Product_Pages' ) && $router_dir !== '' && is_file( $file ) && is_readable( $file ) && class_exists( 'BizCity_Safe_Loader' ) ) {
			BizCity_Safe_Loader::require_file( $file, 'diagnostics.b2b2c_product_pages.product_owner' );
		}
		if ( ! class_exists( 'BizCity_Router_Product_Pages' ) ) {
			return new WP_Error( 'product_pages_class_missing', 'Canonical product page owner is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();
		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' )
			? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' )
			: rtrim( WP_PLUGIN_DIR, '/\\' ) . '/bizcity-llm-router';
		$owner_file = $router_dir . '/includes/class-router-product-pages.php';
		$template_file = $router_dir . '/templates/products/page-product.php';
		$owner_source = is_readable( $owner_file ) ? (string) file_get_contents( $owner_file ) : '';
		$template_source = is_readable( $template_file ) ? (string) file_get_contents( $template_file ) : '';
		$disk_ok = $owner_source !== ''
			&& $template_source !== ''
			&& strpos( $owner_source, 'function catalog' ) !== false
			&& strpos( $owner_source, 'function add_rewrites' ) !== false
			&& strpos( $owner_source, 'function handle_template' ) !== false
			// [2026-09-02 Johnny Chu] B2C-F3 — assert the renderer's source marker, not its filename.
			&& strpos( $template_source, 'Shared renderer for BizCity Twin B2B2C product pages.' ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Disk · product owner and renderer',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Canonical product owner and shared renderer are readable.' : 'Product owner or shared renderer is missing/incomplete.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_product_artifacts_missing';
		}

		$loader_ok = class_exists( 'BizCity_Router_Product_Pages' )
			&& method_exists( 'BizCity_Router_Product_Pages', 'catalog' )
			&& method_exists( 'BizCity_Router_Product_Pages', 'add_rewrites' )
			&& method_exists( 'BizCity_Router_Product_Pages', 'register_query_vars' )
			&& method_exists( 'BizCity_Router_Product_Pages', 'current_slug' );
		$ctx->emit_step( array(
			'label'  => 'Loader · product route owner',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Product catalog, rewrite and query-var methods are loaded.' : 'Product route owner methods are missing.',
		) );
		if ( ! $loader_ok ) {
			$failures[] = 'loader_product_owner_missing';
		}
		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Product page Disk/Loader contract failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Load the canonical Router product page owner and shared renderer, then rerun the focused product probe.',
			);
		}

		$expected_slugs = array( 'twinbrain-ai', 'shared-inbox', 'crm-workspace', 'omni-channel', 'automation', 'integrations' );
		$catalog = BizCity_Router_Product_Pages::catalog();
		$catalog_slugs = is_array( $catalog ) ? array_keys( $catalog ) : array();
		$catalog_ok = is_array( $catalog ) && $expected_slugs === array_values( $catalog_slugs );
		$ctx->emit_step( array(
			'label'  => 'Runtime · six-slug catalog',
			'status' => $catalog_ok ? 'pass' : 'fail',
			'detail' => $catalog_ok ? 'Catalog contains exactly the six canonical product slugs in stable order.' : 'Catalog slug set/order differs from the canonical six-product contract.',
		) );
		if ( ! $catalog_ok ) {
			$failures[] = 'catalog_slugs_mismatch';
		}

		$payload_ok = true;
		foreach ( $expected_slugs as $slug ) {
			$page = isset( $catalog[ $slug ] ) && is_array( $catalog[ $slug ] ) ? $catalog[ $slug ] : array();
			$required = array( 'label', 'title', 'description', 'headline', 'lead', 'proof_image', 'proof_alt', 'primary_cta', 'secondary_cta', 'sections', 'workflow', 'status' );
			foreach ( $required as $field ) {
				if ( ! array_key_exists( $field, $page ) || ( is_string( $page[ $field ] ) && '' === trim( $page[ $field ] ) ) ) {
					$payload_ok = false;
				}
			}
			if ( empty( $page['primary_cta']['url'] ) || empty( $page['secondary_cta']['url'] ) || count( (array) $page['sections'] ) < 4 || count( (array) $page['workflow'] ) < 5 ) {
				$payload_ok = false;
			}
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · product payloads',
			'status' => $payload_ok ? 'pass' : 'fail',
			'detail' => $payload_ok ? 'All six pages contain the fields required by the shared renderer and CTA contract.' : 'At least one product payload is incomplete for the shared renderer.',
		) );
		if ( ! $payload_ok ) {
			$failures[] = 'product_payload_incomplete';
		}

		$query_vars = BizCity_Router_Product_Pages::register_query_vars( array() );
		$query_var_ok = in_array( BizCity_Router_Product_Pages::QV, $query_vars, true );
		$ctx->emit_step( array(
			'label'  => 'Runtime · product query variable',
			'status' => $query_var_ok ? 'pass' : 'fail',
			'detail' => $query_var_ok ? 'Canonical product query variable is registered.' : 'Product query variable is not registered.',
		) );
		if ( ! $query_var_ok ) {
			$failures[] = 'query_var_missing';
		}

		$previous_slug = get_query_var( BizCity_Router_Product_Pages::QV, '' );
		$runtime_slug_ok = true;
		foreach ( $expected_slugs as $slug ) {
			set_query_var( BizCity_Router_Product_Pages::QV, $slug );
			if ( BizCity_Router_Product_Pages::current_slug() !== $slug ) {
				$runtime_slug_ok = false;
			}
		}
		set_query_var( BizCity_Router_Product_Pages::QV, $previous_slug );
		$ctx->emit_step( array(
			'label'  => 'Runtime · current product resolver',
			'status' => $runtime_slug_ok ? 'pass' : 'fail',
			'detail' => $runtime_slug_ok ? 'Each canonical slug resolves to its own product payload.' : 'One or more canonical slugs did not resolve through current_slug().',
		) );
		if ( ! $runtime_slug_ok ) {
			$failures[] = 'current_slug_resolution_failed';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Product page runtime contract failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check the six-slug catalog, shared renderer fields and query-var resolver.',
			);
		}
		return array(
			'status'  => 'pass',
			'summary' => 'Six canonical product page routes passed Disk/Loader/Runtime checks without database or provider side effects.',
		);
	}

	public function cleanup(): void {
		// [2026-09-02 Johnny Chu] B2C-F3 — probe changes only in-memory query vars and needs no persistent cleanup.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Product_Pages';
	return $list;
} );
