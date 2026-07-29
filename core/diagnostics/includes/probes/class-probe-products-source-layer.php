<?php
/**
 * BizCity Diagnostics — products source-of-truth layer probe.
 *
 * 3-layer DDV evidence for Layer 4.2 source contract:
 * - Disk: source layer class file + required methods exist.
 * - Loader: resolver + source layer runtime classes are available.
 * - Runtime: resolver emits source_of_truth_links/source_block_md + counters.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-16
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Products_Source_Layer', false ) ) {
	return;
}

final class BizCity_Probe_Products_Source_Layer implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.twinbrain.products_source_layer'; }
	public function label(): string { return 'TwinBrain Products Source-of-Truth Layer'; }
	public function description(): string {
		return 'Verifies Layer 4.2 contract: source_of_truth_links, source_block_md, internal/public counters, and citation validation parity.';
	}
	public function severity(): string { return 'info'; }
	public function order(): int { return 48; }
	public function icon(): string { return 'Link'; }
	public function estimate_ms(): int { return 320; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinBrain_Product_Resolver_Service' ) ) {
			return new WP_Error( 'products_resolver_missing', 'BizCity_TwinBrain_Product_Resolver_Service is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - DDV runtime probe for source_of_truth_links contract.
		$steps    = array();
		$failures = array();
		$warnings = array();

		$tb_dir = defined( 'BIZCITY_TWINBRAIN_DIR' ) ? (string) BIZCITY_TWINBRAIN_DIR : '';
		$source_file = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-product-source-layer.php' : '';

		$disk_file_ok = $source_file !== '' && file_exists( $source_file );
		$step = array(
			'label'  => 'Disk - source layer file exists',
			'status' => $disk_file_ok ? 'pass' : 'fail',
			'detail' => $disk_file_ok
				? 'class-twinbrain-product-source-layer.php is present.'
				: 'Missing class-twinbrain-product-source-layer.php under core/twinbrain/includes.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_file_ok ) {
			$failures[] = 'source_layer_file_missing';
		}

		$methods_ok = class_exists( 'BizCity_TwinBrain_Product_Source_Layer' )
			&& method_exists( 'BizCity_TwinBrain_Product_Source_Layer', 'build_source_of_truth_links' )
			&& method_exists( 'BizCity_TwinBrain_Product_Source_Layer', 'build_source_block_md' )
			&& method_exists( 'BizCity_TwinBrain_Product_Source_Layer', 'validate_citation' );
		$step = array(
			'label'  => 'Disk - source layer methods available',
			'status' => $methods_ok ? 'pass' : 'fail',
			'detail' => $methods_ok
				? 'build_source_of_truth_links/build_source_block_md/validate_citation available.'
				: 'One or more source layer methods are missing.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $methods_ok ) {
			$failures[] = 'source_layer_methods_missing';
		}

		$loader_ok = class_exists( 'BizCity_TwinBrain_Product_Resolver_Service' )
			&& class_exists( 'BizCity_TwinBrain_Product_Source_Layer' );
		$step = array(
			'label'  => 'Loader - resolver + source layer classes loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok
				? 'Resolver and source layer classes are loaded from TwinBrain bootstrap.'
				: 'TwinBrain bootstrap did not load resolver/source layer stack.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) {
			$failures[] = 'loader_classes_missing';
		}

		$search_client_ready = class_exists( 'BizCity_Search_Client' )
			&& method_exists( 'BizCity_Search_Client', 'instance' )
			&& BizCity_Search_Client::instance()->is_ready();
		$step = array(
			'label'  => 'Loader - Tavily search client readiness',
			'status' => $search_client_ready ? 'pass' : 'warn',
			'detail' => $search_client_ready
				? 'BizCity_Search_Client is ready; public source links can be generated.'
				: 'Search client not ready; runtime may produce internal-only source links.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $search_client_ready ) {
			$warnings[] = 'search_client_not_ready';
		}

		$runtime_ok = false;
		$runtime_detail = 'Resolver runtime check not executed.';
		$scope_counts_ok = false;
		$citation_valid_ok = true;

		if ( $loader_ok ) {
			try {
				$resolver = BizCity_TwinBrain_Product_Resolver_Service::instance();
				$result   = $resolver->resolve_by_query( 'minh muon lap den tran', array(
					'intent_hint'     => 'need_solution',
					'want_enrichment' => true,
					'max_results'     => 5,
					'max_items'       => 8,
					'source_marker'   => 'diagnostics_probe',
					'sse'             => null,
				) );

				$runtime_ok = is_array( $result ) && ! empty( $result['success'] );
				if ( ! $runtime_ok ) {
					$runtime_detail = 'Resolver returned non-success payload for runtime contract probe.';
				} else {
					$links = isset( $result['source_of_truth_links'] ) && is_array( $result['source_of_truth_links'] )
						? $result['source_of_truth_links']
						: array();
					$source_block_md = isset( $result['source_block_md'] ) ? (string) $result['source_block_md'] : '';
					$internal_count  = (int) ( $result['internal_link_count'] ?? 0 );
					$public_count    = (int) ( $result['public_link_count'] ?? 0 );

					$actual_internal = 0;
					$actual_public   = 0;
					foreach ( $links as $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}
						$scope = (string) ( $row['scope'] ?? '' );
						if ( $scope === 'internal' ) {
							$actual_internal++;
						} elseif ( $scope === 'public' ) {
							$actual_public++;
						}
					}

					$scope_counts_ok = ( $internal_count === $actual_internal ) && ( $public_count === $actual_public );
					if ( ! $scope_counts_ok ) {
						$failures[] = 'scope_count_mismatch';
					}

					if ( ! is_string( $source_block_md ) ) {
						$failures[] = 'source_block_invalid_type';
					}

					$citations = isset( $result['citations'] ) && is_array( $result['citations'] ) ? $result['citations'] : array();
					if ( class_exists( 'BizCity_TwinBrain_Product_Source_Layer' ) ) {
						$source_layer = BizCity_TwinBrain_Product_Source_Layer::instance();
						foreach ( $citations as $token ) {
							$token = (string) $token;
							if ( $token === '' ) {
								continue;
							}
							if ( ! $source_layer->validate_citation( $token, $links ) ) {
								$citation_valid_ok = false;
								break;
							}
						}
					}
					if ( ! $citation_valid_ok ) {
						$failures[] = 'citation_not_in_source_truth';
					}

					if ( empty( $links ) && (string) ( $result['_degraded'] ?? '' ) !== '' ) {
						$warnings[] = 'empty_links_degraded';
					}

					$runtime_detail = 'internal=' . $internal_count
						. '; public=' . $public_count
						. '; links=' . count( $links )
						. '; degraded=' . (string) ( $result['_degraded'] ?? '' ) . '.';
				}
			} catch ( \Throwable $e ) {
				$runtime_ok = false;
				$runtime_detail = 'Runtime call failed: ' . $e->getMessage();
			}
		}

		$step = array(
			'label'  => 'Runtime - source-of-truth output contract',
			'status' => ( $runtime_ok && $scope_counts_ok && $citation_valid_ok ) ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! ( $runtime_ok && $scope_counts_ok && $citation_valid_ok ) ) {
			$failures[] = 'runtime_contract_failed';
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		$summary = empty( $failures )
			? 'Source-of-Truth Link Layer operational.'
			: 'Source-of-Truth Link Layer failed: ' . implode( ', ', array_unique( $failures ) ) . '.';
		if ( empty( $failures ) && ! empty( $warnings ) ) {
			$summary .= ' Warnings: ' . implode( ', ', array_unique( $warnings ) ) . '.';
		}

		return array(
			'status'   => $status,
			'summary'  => $summary,
			'error'    => empty( $failures ) ? '' : implode( '; ', array_unique( $failures ) ),
			'fix_hint' => empty( $failures ) ? '' : 'Check source layer wiring in resolver and ensure source_of_truth_links counters are in sync.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - read-only probe; no cleanup needed.
	}
}

// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - register DDV probe for source-of-truth contract.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Products_Source_Layer';
	return $list;
} );
