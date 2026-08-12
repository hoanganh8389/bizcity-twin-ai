<?php
/**
 * Probe: TwinBrain Woo BizOps foundation and Contacts identity contract.
 *
 * Read-only probe. It never creates orders, writes ledger rows or mutates Contacts.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_TwinBrain_Woo_Bizops', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Woo_Bizops implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.twinbrain.woo_bizops'; }
	public function label(): string { return 'TwinBrain Woo BizOps + Contacts Identity (DDV)'; }
	public function description(): string { return 'Kiểm tra phone contract, Woo BizOps resolver/engine/action, event taxonomy và ledger contact_id projection.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 48; }
	public function icon(): string { return 'bar-chart-3'; }
	public function estimate_ms(): int { return 250; }
	public function precondition(): bool { return true; }

	public function run( $ctx ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — read-only Disk/Loader/Runtime foundation probe.
		$steps = array();
		$failures = array();
		$warnings = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$files = array(
			'normalizer' => $root . '/core/helper/includes/class-bizcity-phone-normalizer.php',
			'resolver'   => $root . '/core/twinbrain/includes/class-twinbrain-woo-bizops-resolver-service.php',
			'rest'       => $root . '/core/twinbrain/includes/class-twinbrain-rest.php',
			'engine'     => $root . '/core/twinbrain/includes/class-twinbrain-web-woo-bizops.php',
			'action'     => $root . '/core/automation/includes/blocks/actions/class-action-run-woo-bizops.php',
			'digest'     => $root . '/core/automation/includes/blocks/actions/class-action-run-woo-bizops-digest.php',
			'reports'    => $root . '/plugins/bizcity-twin-crm/includes/woo/class-woo-reports-bridge.php',
			'customer'   => $root . '/plugins/bizcity-twin-crm/includes/woo/class-woo-customer-bridge.php',
			'queue'      => $root . '/plugins/bizcity-twin-crm/includes/woo/class-identity-conflict-queue.php',
			'fixtures'   => $root . '/plugins/bizcity-twin-crm/includes/woo/class-identity-fixtures.php',
			'backfill'   => $root . '/plugins/bizcity-twin-crm/includes/woo/migrations/class-contacts-unify-backfill.php',
		);
		$disk_ok = true;
		foreach ( $files as $file ) {
			$exists = file_exists( $file );
			$disk_ok = $disk_ok && $exists;
			$steps[] = array( 'label' => 'Disk — ' . basename( $file ), 'status' => $exists ? 'pass' : 'fail', 'detail' => $exists ? 'File exists.' : 'File missing.' );
		}
		if ( ! $disk_ok ) { $failures[] = 'woo_bizops_disk_files_missing'; }

		$loader_ok = interface_exists( 'BizCity_Phone_Normalizer_Interface' )
			&& class_exists( 'BizCity_Phone_Normalizer' )
			&& class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service' )
			&& class_exists( 'BizCity_TwinBrain_Web_Woo_Bizops' )
			&& class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops' )
			&& class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops_Digest' )
			&& class_exists( 'BizCity_CRM_Woo_Reports_Bridge' )
			&& class_exists( 'BizCity_CRM_Woo_Customer_Bridge' )
			&& class_exists( 'BizCity_CRM_Identity_Conflict_Queue' )
			&& class_exists( 'BizCity_CRM_Identity_Fixtures' )
			&& class_exists( 'BizCity_CRM_Contacts_Unify_Backfill' );
		$steps[] = array( 'label' => 'Loader — helper, resolver, engine, action', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'All foundation classes/contracts loaded.' : 'One or more foundation classes/contracts missing.' );
		if ( ! $loader_ok ) { $failures[] = 'woo_bizops_loader_missing'; }

		$rest_source = file_exists( $files['rest'] ) ? (string) file_get_contents( $files['rest'] ) : '';
		$rest_parity_ok = strpos( $rest_source, 'keep synchronous REST mode parity with stream' ) !== false
			&& strpos( $rest_source, 'propagate synchronous vertical mode to runtime' ) !== false
			&& strpos( $rest_source, 'preserve vertical mode during synchronous completion' ) !== false;
		$steps[] = array( 'label' => 'Loader — synchronous REST web_mode parity', 'status' => $rest_parity_ok ? 'pass' : 'fail', 'detail' => $rest_parity_ok ? 'Non-stream /turn declares, propagates and completes with web_mode.' : 'Non-stream /turn web_mode parity contract is incomplete.' );
		if ( ! $rest_parity_ok ) { $failures[] = 'woo_bizops_rest_turn_parity_missing'; }

		$digest_source = file_exists( $files['digest'] ) ? (string) file_get_contents( $files['digest'] ) : '';
		$digest_contract_ok = strpos( $digest_source, "'action.run_woo_bizops_digest'" ) !== false
			&& strpos( $digest_source, 'woo_bizops_digest_query_ok' ) !== false
			&& strpos( $digest_source, 'woo_bizops_digest_done' ) !== false
			&& strpos( $digest_source, 'note_event' ) !== false;
		$steps[] = array( 'label' => 'Runtime — digest cron evidence contract', 'status' => $digest_contract_ok ? 'pass' : 'fail', 'detail' => $digest_contract_ok ? 'Digest action emits success/failure evidence through note_event().' : 'Digest action or cron evidence contract is missing.' );
		if ( ! $digest_contract_ok ) { $failures[] = 'woo_bizops_digest_contract_missing'; }

		$reports_source = file_exists( $files['reports'] ) ? (string) file_get_contents( $files['reports'] ) : '';
		$reports_cache_ok = strpos( $reports_source, 'Cache Contract (R-CACHE)' ) !== false
			&& strpos( $reports_source, "const CACHE_GROUP  = 'bzcrw'" ) !== false
			&& strpos( $reports_source, "BizCity_Cache_Registry::register( 'bzcrw'" ) !== false
			&& strpos( $reports_source, 'BizCity_Cache::flush_group( self::CACHE_GROUP )' ) !== false;
		$steps[] = array( 'label' => 'Loader — Woo Reports cache contract', 'status' => $reports_cache_ok ? 'pass' : 'fail', 'detail' => $reports_cache_ok ? 'Reports Bridge uses bzcrw registry and order invalidation.' : 'Reports Bridge cache contract is incomplete.' );
		if ( ! $reports_cache_ok ) { $failures[] = 'woo_reports_cache_contract_missing'; }

		$customer_source = file_exists( $files['customer'] ) ? (string) file_get_contents( $files['customer'] ) : '';
		$identity_conflict_ok = strpos( $customer_source, 'email_phone_contact_mismatch' ) !== false
			&& strpos( $customer_source, 'bizcity_crm_contact_identity_conflict' ) !== false
			&& strpos( $customer_source, "'source'           => 'woo_order'" ) !== false;
		$steps[] = array( 'label' => 'Runtime — Woo identity conflict fail-closed', 'status' => $identity_conflict_ok ? 'pass' : 'fail', 'detail' => $identity_conflict_ok ? 'Guest order mismatch emits internal evidence and refuses auto-merge.' : 'Woo order identity conflict guard is missing.' );
		if ( ! $identity_conflict_ok ) { $failures[] = 'woo_identity_conflict_guard_missing'; }

		$account_identity_ok = strpos( $customer_source, 'strtolower( trim( $billing_email ?: $user->user_email ) )' ) !== false
			&& strpos( $customer_source, '$candidate_ids = array_values( array_unique' ) !== false
			&& strpos( $customer_source, "'source'           => 'woo_user'" ) !== false
			&& strpos( $customer_source, "'reason'           => 'identity_candidate_mismatch'" ) !== false;
		$steps[] = array( 'label' => 'Loader — Woo account identity conflict contract', 'status' => $account_identity_ok ? 'pass' : 'fail', 'detail' => $account_identity_ok ? 'Account sync normalizes email, collects all candidates and refuses mismatch overwrite.' : 'Account identity conflict contract is missing.' );
		if ( ! $account_identity_ok ) { $failures[] = 'woo_account_identity_guard_missing'; }

		$queue_source = file_exists( $files['queue'] ) ? (string) file_get_contents( $files['queue'] ) : '';
		$queue_contract_ok = strpos( $queue_source, 'BizCity_CRM_Identity_Conflict_Queue' ) !== false
			&& strpos( $queue_source, 'function capture' ) !== false
			&& strpos( $queue_source, 'dedupe_key' ) !== false
			&& strpos( $queue_source, 'contact_ids_json' ) !== false
			&& strpos( $queue_source, 'claim_next' ) !== false
			&& strpos( $queue_source, 'public static function retry' ) !== false
			&& strpos( $queue_source, 'private static function table_ready' ) !== false
			&& strpos( $queue_source, 'BizCity_CRM_DB_Installer_V2::table_exists' ) !== false
			&& strpos( $queue_source, 'public static function audit_history' ) !== false
			&& strpos( $queue_source, "BizCity_Cache_Registry::register( 'bzcric'" ) !== false;
		$steps[] = array( 'label' => 'Loader — durable identity conflict queue', 'status' => $queue_contract_ok ? 'pass' : 'fail', 'detail' => $queue_contract_ok ? 'Queue class, dedupe hash, candidate projection and cache catalog are present.' : 'Durable identity conflict queue contract is incomplete.' );
		if ( ! $queue_contract_ok ) { $failures[] = 'woo_identity_conflict_queue_missing'; }

		$rest_queue_api_ok = strpos( $rest_source, "'/identity-conflicts'" ) !== false
			&& strpos( $rest_source, 'get_identity_conflicts' ) !== false
			&& strpos( $rest_source, 'resolve_identity_conflict' ) !== false
			&& strpos( $rest_source, 'reject_identity_conflict' ) !== false
			&& strpos( $rest_source, "'can_manage_rules'" ) !== false;
		$steps[] = array( 'label' => 'Loader — identity conflict admin REST API', 'status' => $rest_queue_api_ok ? 'pass' : 'fail', 'detail' => $rest_queue_api_ok ? 'List/resolve/reject routes are admin-gated and candidate-scoped.' : 'Identity conflict review API is incomplete or not admin-gated.' );
		if ( ! $rest_queue_api_ok ) { $failures[] = 'woo_identity_conflict_rest_api_missing'; }

		$fixture_source = file_exists( $files['fixtures'] ) ? (string) file_get_contents( $files['fixtures'] ) : '';
		$backfill_source = file_exists( $files['backfill'] ) ? (string) file_get_contents( $files['backfill'] ) : '';
		$maintenance_contract_ok = strpos( $fixture_source, "'V2' !== strtoupper" ) !== false
			&& strpos( $fixture_source, 'START TRANSACTION' ) !== false
			&& strpos( $fixture_source, 'ROLLBACK' ) !== false
			&& strpos( $backfill_source, 'CHECKPOINT_PREFIX' ) !== false
			&& strpos( $backfill_source, 'run_user_points' ) !== false
			&& strpos( $backfill_source, 'run_woo_orders' ) !== false
			&& strpos( $backfill_source, 'dry_run' ) !== false;
		$steps[] = array( 'label' => 'Loader — tenant fixtures and checkpoint backfill', 'status' => $maintenance_contract_ok ? 'pass' : 'fail', 'detail' => $maintenance_contract_ok ? 'Opt-in transaction fixtures and bounded dry-run/checkpoint backfill are loaded.' : 'Maintenance fixture/backfill contract is incomplete.' );
		if ( ! $maintenance_contract_ok ) { $failures[] = 'woo_identity_maintenance_contract_missing'; }

		$rest_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_REST', false ) ) {
			try {
				$rest = BizCity_TwinBrain_REST::instance();
				$reflection = new ReflectionMethod( $rest, 'sanitize_web_mode' );
				$reflection->setAccessible( true );
				$rest_ok = $reflection->invoke( $rest, 'woo_bizops' ) === 'woo_bizops';
			} catch ( Throwable $e ) {
				$rest_ok = false;
			}
		}
		$steps[] = array( 'label' => 'Loader — REST accepts woo_bizops', 'status' => $rest_ok ? 'pass' : 'fail', 'detail' => $rest_ok ? 'REST mode normalizer preserves woo_bizops.' : 'REST mode is not registered.' );
		if ( ! $rest_ok ) { $failures[] = 'woo_bizops_rest_mode_missing'; }

		$parity_ok = false;
		if ( class_exists( 'BizCity_Phone_Normalizer', false ) ) {
			$canonical = BizCity_Phone_Normalizer::normalize_vn( '098 765 4321' );
			$parity_ok = $canonical === BizCity_Phone_Normalizer::normalize_vn( '+84 987 654 321' )
				&& $canonical === BizCity_Phone_Normalizer::normalize_vn( '84987654321' )
				&& $canonical === '0987654321';
		}
		$steps[] = array( 'label' => 'Runtime — phone normalization parity', 'status' => $parity_ok ? 'pass' : 'fail', 'detail' => $parity_ok ? '+84/84/0 representations resolve to one canonical value.' : 'Phone representations diverge.' );
		if ( ! $parity_ok ) { $failures[] = 'phone_normalization_parity_failed'; }

		$taxonomy_ok = class_exists( 'BizCity_Twin_Event_Taxonomy', false )
			&& defined( 'BizCity_Twin_Event_Taxonomy::WOO_BIZOPS_DOMAIN_GATE' )
			&& defined( 'BizCity_Twin_Event_Taxonomy::WOO_BIZOPS_COMPOSED' );
		$steps[] = array( 'label' => 'Loader — Woo BizOps event taxonomy', 'status' => $taxonomy_ok ? 'pass' : 'fail', 'detail' => $taxonomy_ok ? 'Domain/query/composed event constants are loaded.' : 'Woo BizOps event taxonomy is missing.' );
		if ( ! $taxonomy_ok ) { $failures[] = 'woo_bizops_taxonomy_missing'; }

		$repeat_contract_ok = class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service', false );
		$repeat_source = $repeat_contract_ok ? (string) file_get_contents( $files['resolver'] ) : '';
		$repeat_contract_ok = $repeat_contract_ok
			&& strpos( $repeat_source, 'repeat_customer_cohort' ) !== false
			&& strpos( $repeat_source, 'repeat_scan_capped' ) !== false;
		$steps[] = array( 'label' => 'Loader — repeat customer bounded contract', 'status' => $repeat_contract_ok ? 'pass' : 'fail', 'detail' => $repeat_contract_ok ? 'Repeat cohort intent and capped degraded state are wired.' : 'Repeat cohort contract missing.' );
		if ( ! $repeat_contract_ok ) { $failures[] = 'repeat_customer_contract_missing'; }

		$schema_warnings = array();
		foreach ( array( 'user_points', 'user_points_exchange' ) as $suffix ) {
			$table = $this->table_name( $suffix );
			if ( $table === '' || ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( $table ) ) {
				$schema_warnings[] = $suffix . '_table_unavailable';
				continue;
			}
			$column_ok = function_exists( 'bizcity_column_exists' ) && bizcity_column_exists( $table, 'contact_id' );
			$steps[] = array( 'label' => 'Runtime — ' . $suffix . '.contact_id', 'status' => $column_ok ? 'pass' : 'fail', 'detail' => $column_ok ? 'Contact projection column exists.' : 'Table exists but contact_id is missing.' );
			if ( ! $column_ok ) { $failures[] = $suffix . '_contact_id_missing'; }
		}
		if ( ! empty( $schema_warnings ) ) {
			$warnings = array_merge( $warnings, $schema_warnings );
			$steps[] = array( 'label' => 'Runtime — user-points schema availability', 'status' => 'warn', 'detail' => implode( ', ', $schema_warnings ) . '. Install/upgrade user-points before live ledger DDV.' );
		}

		return array(
			'pass' => empty( $failures ),
			'status' => empty( $failures ) ? ( empty( $warnings ) ? 'PASS' : 'WARN' ) : 'FAIL',
			'failures' => $failures,
			'warnings' => $warnings,
			'steps' => $steps,
		);
	}

	private function table_name( string $suffix ): string {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . $suffix : '';
	}

	public static function register( array $probes ): array {
		$probes[] = new self();
		return $probes;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', array( 'BizCity_Probe_TwinBrain_Woo_Bizops', 'register' ), 10, 1 );
