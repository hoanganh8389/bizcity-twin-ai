<?php
/**
 * BizCity Diagnostics — core.twinbrain.products probe (PHASE-TWB-PRODUCTS).
 *
 * 3-layer DDV evidence:
 * - Disk   : products vertical files/classes exist.
 * - Loader : REST accepts web_mode=products, products skill definition wired.
 * - Runtime: resolver call succeeds and emits product_synthesize_done event.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Products', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Products implements BizCity_Diagnostics_Probe {

	/** @var array<int,string> */
	private static $probe_trace_ids = array();

	public function id(): string          { return 'core.twinbrain.products'; }
	public function label(): string       { return 'TwinBrain Super-MRO Vertical (DDV)'; }
	public function description(): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — expose canonical Super-MRO brand while retaining probe ID.
		return 'Kiểm tra 3 lớp Super-MRO: Disk classes, Loader products compatibility wiring, Runtime resolver + product timeline event.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 47; }
	public function icon(): string        { return 'shopping-bag'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - dedicated DDV probe for products vertical rollout.
		$steps    = array();
		$failures = array();
		$warnings = array();

		$tb_dir = defined( 'BIZCITY_TWINBRAIN_DIR' ) ? (string) BIZCITY_TWINBRAIN_DIR : '';
		$provider_file = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-product-provider.php' : '';
		$resolver_file = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-product-resolver-service.php' : '';
		$engine_file   = $tb_dir !== '' ? $tb_dir . 'includes/class-twinbrain-web-products.php' : '';

		$disk_ok = $provider_file !== ''
			&& file_exists( $provider_file )
			&& file_exists( $resolver_file )
			&& file_exists( $engine_file );
		$steps[] = array(
			'label'  => 'Disk — products files exist',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok
				? 'Provider + Resolver + Web Products files are present.'
				: 'Missing one of class-twinbrain-product-provider.php / class-twinbrain-product-resolver-service.php / class-twinbrain-web-products.php.',
		);
		if ( ! $disk_ok ) {
			$failures[] = 'disk_files_missing';
		}

		$classes_ok = class_exists( 'BizCity_TwinBrain_Product_Provider', false )
			&& class_exists( 'BizCity_TwinBrain_Product_Resolver_Service', false )
			&& class_exists( 'BizCity_TwinBrain_Web_Products', false );
		$steps[] = array(
			'label'  => 'Disk — classes loaded',
			'status' => $classes_ok ? 'pass' : 'fail',
			'detail' => $classes_ok
				? 'Products classes are loaded by twinbrain bootstrap.'
				: 'Products classes are not loaded. Check core/twinbrain/bootstrap.php require_once wiring.',
		);
		if ( ! $classes_ok ) {
			$failures[] = 'classes_not_loaded';
		}

		$rest_mode_ok = false;
		$rest_note    = '';
		if ( class_exists( 'BizCity_TwinBrain_REST', false ) ) {
			try {
				$rest  = BizCity_TwinBrain_REST::instance();
				$refl  = new \ReflectionMethod( $rest, 'sanitize_web_mode' );
				$refl->setAccessible( true );
				$mode  = (string) $refl->invoke( $rest, 'products' );
				$rest_mode_ok = ( $mode === 'products' );
				$rest_note    = $rest_mode_ok ? 'sanitize_web_mode("products") => products.' : 'sanitize_web_mode("products") did not return products.';
			} catch ( \Throwable $e ) {
				$rest_note = 'Reflection failed: ' . $e->getMessage();
			}
		} else {
			$rest_note = 'BizCity_TwinBrain_REST class not loaded.';
		}
		$steps[] = array(
			'label'  => 'Loader — REST accepts products mode',
			'status' => $rest_mode_ok ? 'pass' : 'fail',
			'detail' => $rest_note,
		);
		if ( ! $rest_mode_ok ) {
			$failures[] = 'rest_products_mode_missing';
		}

		$skill_def_ok = false;
		$skill_db_ok  = false;
		if ( class_exists( 'BizCity_TwinBrain_Web_Skills_Seeder', false ) && method_exists( 'BizCity_TwinBrain_Web_Skills_Seeder', 'definitions' ) ) {
			$defs = (array) BizCity_TwinBrain_Web_Skills_Seeder::definitions();
			foreach ( $defs as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( (string) ( $row['skill_key'] ?? '' ) === 'web_search_products' ) {
					$skill_def_ok = true;
					break;
				}
			}
		}
		$steps[] = array(
			'label'  => 'Loader — products skill definition wired',
			'status' => $skill_def_ok ? 'pass' : 'fail',
			'detail' => $skill_def_ok
				? 'BizCity_TwinBrain_Web_Skills_Seeder::definitions() contains web_search_products.'
				: 'web_search_products not found in web skills definitions.',
		);
		if ( ! $skill_def_ok ) {
			$failures[] = 'skill_definition_missing';
		}

		if ( class_exists( 'BizCity_Skill_Database', false ) ) {
			try {
				$db  = BizCity_Skill_Database::instance();
				$row = method_exists( $db, 'get_by_key' ) ? $db->get_by_key( 'web_search_products', 0, 0 ) : null;
				$skill_db_ok = is_array( $row ) && ! empty( $row['id'] );
			} catch ( \Throwable $e ) {
				$skill_db_ok = false;
			}
		}
		$steps[] = array(
			'label'  => 'Loader — products skill row in bizcity_skills',
			'status' => $skill_db_ok ? 'pass' : 'warn',
			'detail' => $skill_db_ok
				? 'web_search_products found in SQL skill store.'
				: 'Skill row not found yet. Run web skills seed/reseed if needed.',
		);
		if ( ! $skill_db_ok ) {
			$warnings[] = 'skill_row_not_seeded';
		}

		$runtime_ok = false;
		$runtime_note = '';
		$saw_product_synthesize = false;
		$probe_trace_id = 'probe-products-' . wp_generate_uuid4();
		self::$probe_trace_ids[] = $probe_trace_id;

		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - runtime preflight: assert physical schema files for product timeline events.
		$schema_events_dir = '';
		if ( $tb_dir !== '' ) {
			$core_dir = dirname( rtrim( $tb_dir, '/\\' ) );
			$schema_events_dir = $core_dir . '/twin-core/event-stream/schemas/events/';
		}
		$product_schema_files = array(
			'product_research_started.json',
			'product_intent_detected.json',
			'product_needs_decomposed.json',
			'product_react_step.json',
			'product_synthesize_done.json',
		);
		$missing_schema_files = array();
		foreach ( $product_schema_files as $schema_file ) {
			$full_path = $schema_events_dir . $schema_file;
			if ( $schema_events_dir === '' || ! file_exists( $full_path ) ) {
				$missing_schema_files[] = $schema_file;
			}
		}
		$schema_files_ok = empty( $missing_schema_files );
		$steps[] = array(
			'label'  => 'Runtime — product schema files exist physically',
			'status' => $schema_files_ok ? 'pass' : 'fail',
			'detail' => $schema_files_ok
				? 'All 5 product_* schema JSON files exist in core/twin-core/event-stream/schemas/events.'
				: 'Missing schema files: ' . implode( ', ', $missing_schema_files ) . '.',
		);
		if ( ! $schema_files_ok ) {
			$failures[] = 'runtime_schema_files_missing';
		}

		if ( class_exists( 'BizCity_TwinBrain_Product_Resolver_Service', false ) ) {
			$product_events = array();
			$listener = static function ( $event_key, $payload ) use ( $probe_trace_id, &$product_events ) {
				if ( ! is_string( $event_key ) || $event_key !== 'product_synthesize_done' ) {
					return;
				}
				if ( ! is_array( $payload ) || (string) ( $payload['trace_id'] ?? '' ) !== $probe_trace_id ) {
					return;
				}
				$product_events[] = $payload;
			};
			add_action( 'bizcity_twin_event', $listener, 10, 2 );
			try {
				$service = BizCity_TwinBrain_Product_Resolver_Service::instance();
				$res     = $service->resolve_by_query( 'gia xi mang bao nhieu', array(
					'trace_id'        => $probe_trace_id,
					'intent_hint'     => 'stock_price',
					'want_enrichment' => false,
					// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — keep DDV runtime probe read-safe (no write side effects).
					'auto_sheet_handoff' => false,
					'max_results'     => 3,
					'max_items'       => 5,
					'source_marker'   => 'diagnostics_probe',
					'sse'             => static function () {
						// probe only needs runtime contract verification.
					},
				) );
				$runtime_ok = is_array( $res ) && ! empty( $res['success'] );
				$saw_product_synthesize = ! empty( $product_events );

				$provider_ready = class_exists( 'BizCity_TwinBrain_Product_Provider', false )
					? BizCity_TwinBrain_Product_Provider::instance()->is_ready()
					: false;
				$degraded = is_array( $res ) ? (string) ( $res['_degraded'] ?? '' ) : '';

				if ( ! $provider_ready ) {
					$warnings[] = 'woo_inactive';
					$runtime_note = 'Resolver executed in Woo-inactive mode (expected degraded=woo_inactive).';
				} elseif ( $degraded !== '' ) {
					$warnings[] = 'runtime_degraded:' . $degraded;
					$runtime_note = 'Resolver returned degraded=' . $degraded . '.';
				} else {
					$runtime_note = 'Resolver returned success with matched=' . (int) ( $res['matched_count'] ?? 0 ) . ', gaps=' . (int) ( $res['gap_count'] ?? 0 ) . '.';
				}
			} catch ( \Throwable $e ) {
				$runtime_ok = false;
				$runtime_note = 'Runtime threw: ' . $e->getMessage();
			} finally {
				remove_action( 'bizcity_twin_event', $listener, 10 );
			}
		} else {
			$runtime_note = 'BizCity_TwinBrain_Product_Resolver_Service class not loaded.';
		}

		$steps[] = array(
			'label'  => 'Runtime — resolver contract returns success',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_note,
		);
		if ( ! $runtime_ok ) {
			$failures[] = 'runtime_resolve_failed';
		}

		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — regression for accented "lắp đèn trần" domain contamination incident.
		$ceiling_light_ok   = false;
		$ceiling_light_note = 'Super-MRO resolver class not loaded.';
		if ( class_exists( 'BizCity_TwinBrain_Product_Resolver_Service', false ) ) {
			try {
				$service          = BizCity_TwinBrain_Product_Resolver_Service::instance();
				$classify_method  = new \ReflectionMethod( $service, 'classify_intent' );
				$decompose_method = new \ReflectionMethod( $service, 'decompose_needs' );
				$classify_method->setAccessible( true );
				$decompose_method->setAccessible( true );
				$incident_query = 'mình muốn lắp đèn trần';
				$incident_intent = (string) $classify_method->invoke( $service, $incident_query, '' );
				$incident_items  = (array) $decompose_method->invoke( $service, $incident_query, 15 );
				$incident_text   = function_exists( 'remove_accents' )
					? mb_strtolower( remove_accents( implode( ' ', array_map( 'strval', $incident_items ) ) ) )
					: mb_strtolower( implode( ' ', array_map( 'strval', $incident_items ) ) );
				$has_lighting = mb_strpos( $incident_text, 'den' ) !== false;
				$has_cosmetic = preg_match( '/mascara|phan mat|son moi|nuoc hoa|skincare/u', $incident_text ) === 1;
				$ceiling_light_ok = $incident_intent === 'need_solution' && $has_lighting && ! $has_cosmetic;
				$ceiling_light_note = 'intent=' . $incident_intent . '; items=' . implode( ', ', array_slice( $incident_items, 0, 8 ) ) . '.';
			} catch ( \Throwable $e ) {
				$ceiling_light_note = 'Regression reflection failed: ' . $e->getMessage();
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — lắp đèn trần stays in Super-MRO scope',
			'status' => $ceiling_light_ok ? 'pass' : 'fail',
			'detail' => $ceiling_light_note,
		);
		if ( ! $ceiling_light_ok ) {
			$failures[] = 'ceiling_light_scope_regression';
		}

		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — assert BOQ/sheet handoff contract is exposed by resolver runtime.
		$sheet_contract_ok   = false;
		$sheet_contract_note = 'Super-MRO resolver class not loaded.';
		if ( class_exists( 'BizCity_TwinBrain_Product_Resolver_Service', false ) ) {
			try {
				$service = BizCity_TwinBrain_Product_Resolver_Service::instance();
				$sheet_res = $service->resolve_by_query( 'lap bang BOQ den tran van phong', array(
					'trace_id'        => $probe_trace_id . '-sheet',
					'intent_hint'     => 'need_solution',
					'want_enrichment' => false,
					// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — assert handoff contract shape without auto creating sheet during probe.
					'auto_sheet_handoff' => false,
					'max_results'     => 3,
					'max_items'       => 8,
					'source_marker'   => 'diagnostics_probe',
					'sse'             => null,
				) );

				$sheet_seed = ( is_array( $sheet_res ) && isset( $sheet_res['sheet_seed'] ) && is_array( $sheet_res['sheet_seed'] ) )
					? $sheet_res['sheet_seed']
					: array();
				$headers = isset( $sheet_seed['headers'] ) && is_array( $sheet_seed['headers'] ) ? $sheet_seed['headers'] : array();
				$rows    = isset( $sheet_seed['rows'] ) && is_array( $sheet_seed['rows'] ) ? $sheet_seed['rows'] : array();
				$sheet_contract_ok = is_array( $sheet_res )
					&& ! empty( $sheet_res['success'] )
					&& ! empty( $sheet_res['sheet_recommended'] )
					&& ! empty( $headers )
					&& ! empty( $rows );
				$sheet_contract_note = 'sheet_recommended=' . ( ! empty( $sheet_res['sheet_recommended'] ) ? '1' : '0' )
					. '; headers=' . count( $headers )
					. '; rows=' . count( $rows ) . '.';
			} catch ( \Throwable $e ) {
				$sheet_contract_note = 'Sheet contract runtime failed: ' . $e->getMessage();
			}
		}
		$steps[] = array(
			'label'  => 'Runtime — sheet handoff contract returned',
			'status' => $sheet_contract_ok ? 'pass' : 'fail',
			'detail' => $sheet_contract_note,
		);
		if ( ! $sheet_contract_ok ) {
			$failures[] = 'sheet_handoff_contract_missing';
		}

		$steps[] = array(
			'label'  => 'Runtime — product_synthesize_done emitted',
			'status' => $saw_product_synthesize ? 'pass' : 'fail',
			'detail' => $saw_product_synthesize
				? 'Detected product_synthesize_done on bizcity_twin_event hook.'
				: 'No product_synthesize_done event seen for probe trace.',
		);
		if ( ! $saw_product_synthesize ) {
			$failures[] = 'timeline_event_missing';
		}

		$status = empty( $failures ) ? 'pass' : 'fail';
		$summary = empty( $failures )
			? 'Super-MRO vertical DDV PASS.'
			: 'Super-MRO vertical DDV FAIL: ' . implode( ', ', $failures ) . '.';
		if ( empty( $failures ) && ! empty( $warnings ) ) {
			$summary .= ' Warnings: ' . implode( ', ', $warnings ) . '.';
		}

		return array(
			'status'   => $status,
			'summary'  => $summary,
			'steps'    => $steps,
			'error'    => empty( $failures ) ? '' : implode( '; ', $failures ),
			'fix_hint' => empty( $failures )
				? ''
				: 'Check TwinBrain products bootstrap wiring, REST sanitize enum, and resolver event emission path.',
		);
	}

	public function cleanup(): void {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - remove probe event rows by trace_id.
		if ( empty( self::$probe_trace_ids ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twin_event_stream';
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			self::$probe_trace_ids = array();
			return;
		}
		foreach ( self::$probe_trace_ids as $trace_id ) {
			$trace_id = trim( (string) $trace_id );
			if ( $trace_id === '' ) {
				continue;
			}
			$wpdb->delete( $table, array( 'trace_id' => $trace_id ), array( '%s' ) );
		}
		self::$probe_trace_ids = array();
	}
}

// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - register products DDV probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinBrain_Products';
	return $list;
} );
