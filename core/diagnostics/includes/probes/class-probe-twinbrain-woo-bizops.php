<?php
/**
 * Probe: TwinBrain Woo BizOps foundation and Contacts identity contract.
 *
 * Safe probe. It never creates orders or writes ledger rows; it creates only a
 * disposable Guru policy fixture and removes it during cleanup().
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_TwinBrain_Woo_Bizops', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Woo_Bizops implements BizCity_Diagnostics_Probe {

	private $policy_fixture_guru_id = 0;

	public function id(): string { return 'core.twinbrain.woo_bizops'; }
	public function label(): string { return 'TwinBrain Woo BizOps + Contacts Identity (DDV)'; }
	public function description(): string { return 'Kiểm tra phone contract, Woo BizOps resolver/engine/action, event taxonomy và ledger contact_id projection.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 48; }
	public function icon(): string { return 'bar-chart-3'; }
	public function estimate_ms(): int { return 250; }
	public function precondition() {
		// [2026-08-21 Johnny Chu] R-DDV-OPTIONAL-WOO — BizOps cannot be runtime-validated when WooCommerce is inactive.
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 'WooCommerce chưa active; bỏ qua Woo BizOps contract probe.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-11 Johnny Chu] PHASE-TWB-WOO-BIZOPS — Disk/Loader/Runtime foundation probe.
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
			&& class_exists( 'BizCity_TwinBrain_Guru_Policy' )
			&& class_exists( 'BizCity_TwinBrain_Woo_Bizops_Resolver_Service' )
			&& class_exists( 'BizCity_TwinBrain_Web_Woo_Bizops' )
			&& class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops' )
			&& class_exists( 'BizCity_Automation_Action_Run_Woo_Bizops_Digest' )
			&& class_exists( 'BizCity_CRM_Woo_Reports_Bridge' )
			&& class_exists( 'BizCity_CRM_Woo_Customer_Bridge' )
			&& class_exists( 'BizCity_CRM_Identity_Conflict_Queue' )
			&& class_exists( 'BizCity_CRM_Identity_Fixtures' )
			&& class_exists( 'BizCity_CRM_Contacts_Unify_Backfill' );
		$steps[] = array( 'label' => 'Loader — helper, Guru policy, resolver, engine, action', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'All foundation classes/contracts loaded.' : 'One or more foundation classes/contracts missing.' );
		if ( ! $loader_ok ) { $failures[] = 'woo_bizops_loader_missing'; }

		$policy_source = file_exists( $root . '/core/twinbrain/includes/class-twinbrain-guru-policy.php' ) ? (string) file_get_contents( $root . '/core/twinbrain/includes/class-twinbrain-guru-policy.php' ) : '';
		$web_source = file_exists( $files['engine'] ) ? (string) file_get_contents( $files['engine'] ) : '';
		$action_source = file_exists( $files['action'] ) ? (string) file_get_contents( $files['action'] ) : '';
		$runtime_source = file_exists( $root . '/core/twinbrain/includes/class-twinbrain-runtime.php' ) ? (string) file_get_contents( $root . '/core/twinbrain/includes/class-twinbrain-runtime.php' ) : '';
		$policy_wiring_ok = strpos( $policy_source, 'class BizCity_TwinBrain_Guru_Policy' ) !== false
			&& strpos( $policy_source, "CAP_WOO_BIZOPS = 'woo_bizops'" ) !== false
			&& strpos( $policy_source, "'allowed_verticals'" ) !== false
			&& strpos( $policy_source, 'verify_channel_binding' ) !== false
			&& strpos( $web_source, 'BizCity_TwinBrain_Guru_Policy::decide' ) !== false
			&& strpos( $action_source, 'BizCity_TwinBrain_Guru_Policy::decide' ) !== false
			&& strpos( $runtime_source, "'guru_id' => (int) ( \$opts['guru_id'] ?? 0 )" ) !== false
			&& strpos( $runtime_source, "'account_id' => (string) ( \$opts['account_id'] ?? '' )" ) !== false
			&& strpos( $policy_source, 'verify_audience' ) !== false
			&& strpos( $policy_source, 'verify_resource' ) !== false
			&& strpos( $policy_source, 'min_role' ) !== false
			&& strpos( $policy_source, 'min_plan' ) !== false
			&& strpos( $root . '/modules/twinweb/includes/class-twinweb-rest.php', "'account_id'     => (string) get_current_blog_id()" ) !== false;
		$steps[] = array( 'label' => 'Loader — shared Guru policy wiring', 'status' => $policy_wiring_ok ? 'pass' : 'fail', 'detail' => $policy_wiring_ok ? 'Web mode and direct action share one Guru capability decision boundary.' : 'Guru policy gateway is not wired across all Woo entrypoints.' );
		if ( ! $policy_wiring_ok ) { $failures[] = 'woo_bizops_guru_policy_wiring_missing'; }

		$deny_without_guru_ok = false;
		if ( class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
			$decision = BizCity_TwinBrain_Guru_Policy::decide( array(
				'user_id'    => 0,
				'guru_id'    => 0,
				'surface'    => 'twinchat',
				'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
			) );
			$deny_without_guru_ok = empty( $decision['allowed'] ) && (string) ( $decision['reason'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::REASON_GURU_NOT_ASSIGNED;
		}
		$steps[] = array( 'label' => 'Runtime — Woo denies when Guru is unresolved', 'status' => $deny_without_guru_ok ? 'pass' : 'fail', 'detail' => $deny_without_guru_ok ? 'Woo BizOps stops before resolver execution when no effective Guru exists.' : 'Missing Guru does not produce the expected fail-closed policy decision.' );
		if ( ! $deny_without_guru_ok ) { $failures[] = 'woo_bizops_missing_guru_not_denied'; }

		$stale_guru = false;
		if ( class_exists( 'BizCity_TwinBrain_Guru_Policy' ) ) {
			$stale_decision = BizCity_TwinBrain_Guru_Policy::decide( array(
				'user_id'    => 0,
				'guru_id'    => PHP_INT_MAX,
				'surface'    => 'twinweb',
				'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
			) );
			$stale_guru = empty( $stale_decision['allowed'] ) && (string) ( $stale_decision['reason'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::REASON_GURU_NOT_FOUND;
		}
		$steps[] = array( 'label' => 'Runtime — stale Guru ID is denied as not found', 'status' => $stale_guru ? 'pass' : 'fail', 'detail' => $stale_guru ? 'A Guru ID absent from the current tenant cannot become an empty-policy decision.' : 'Stale/cross-tenant Guru ID was not rejected as guru_not_found.' );
		if ( ! $stale_guru ) { $failures[] = 'woo_bizops_stale_guru_not_denied'; }

		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — prove persisted empty/positive vertical policy with a disposable Guru fixture.
		$policy_table = isset( $wpdb->prefix ) ? $wpdb->prefix . 'bizcity_characters' : '';
		$policy_storage_ready = $policy_table !== ''
			&& function_exists( 'bizcity_tbl_exists' )
			&& bizcity_tbl_exists( $policy_table )
			&& function_exists( 'bizcity_column_exists' )
			&& bizcity_column_exists( $policy_table, 'allowed_verticals' )
			&& bizcity_column_exists( $policy_table, 'min_role' )
			&& bizcity_column_exists( $policy_table, 'min_plan' );
		$policy_user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$policy_capable = $policy_user_id > 0 && function_exists( 'user_can' )
			&& ( user_can( $policy_user_id, 'manage_woocommerce' ) || user_can( $policy_user_id, 'manage_options' ) );
		if ( ! $policy_storage_ready ) {
			$warnings[] = 'woo_guru_policy_storage_not_provisioned';
			$step = array( 'label' => 'Runtime — persisted Guru vertical deny/allow fixture', 'status' => 'warn', 'detail' => 'SKIP: bizcity_characters.allowed_verticals is not physically provisioned on this tenant.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
		} elseif ( ! $policy_capable ) {
			$warnings[] = 'woo_guru_policy_fixture_requires_capability';
			$step = array( 'label' => 'Runtime — persisted Guru vertical deny/allow fixture', 'status' => 'warn', 'detail' => 'SKIP: current diagnostics user lacks manage_woocommerce/manage_options.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$fixture_name = '__healthtest_woo_guru_policy_' . substr( md5( uniqid( 'woo_policy', true ) ), 0, 8 );
			$fixture = BizCity_Knowledge_Database::instance()->create_character( array(
				'name'              => $fixture_name,
				'slug'              => sanitize_title( $fixture_name ),
				'description'       => 'DDV fixture — safe to delete',
				'status'            => 'draft',
				'allowed_verticals'  => array(),
				'min_role'          => '',
				'min_plan'          => '',
			) );
			$this->policy_fixture_guru_id = is_wp_error( $fixture ) ? 0 : (int) $fixture;
			$created_ok = $this->policy_fixture_guru_id > 0;
			$step = array( 'label' => 'Runtime — create disposable Guru policy fixture', 'status' => $created_ok ? 'pass' : 'fail', 'detail' => $created_ok ? 'Disposable Guru fixture created.' : 'Could not create disposable Guru fixture.' );
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $created_ok ) {
				$failures[] = 'woo_guru_policy_fixture_create_failed';
			} else {
				$deny = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id'    => $policy_user_id,
					'guru_id'    => $this->policy_fixture_guru_id,
					'surface'    => 'twinweb',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
				) );
				$deny_ok = empty( $deny['allowed'] ) && (string) ( $deny['reason'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::REASON_VERTICAL_NOT_ALLOWED;
				$step = array( 'label' => 'Runtime — persisted empty allowlist denies Woo BizOps', 'status' => $deny_ok ? 'pass' : 'fail', 'detail' => $deny_ok ? 'allowed_verticals=[] produced vertical_not_allowed.' : 'Empty allowlist did not deny with vertical_not_allowed.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $deny_ok ) { $failures[] = 'woo_guru_policy_empty_allowlist_not_denied'; }

				$updated = BizCity_Knowledge_Database::instance()->update_character( $this->policy_fixture_guru_id, array( 'allowed_verticals' => array( 'woo_bizops' ) ) );
				$allow = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id'    => $policy_user_id,
					'guru_id'    => $this->policy_fixture_guru_id,
					'surface'    => 'twinweb',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
				) );
				$allow_ok = ! is_wp_error( $updated ) && ! empty( $allow['allowed'] ) && (string) ( $allow['status'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::STATUS_ENFORCED;
				$step = array( 'label' => 'Runtime — persisted Woo allowlist permits capable actor', 'status' => $allow_ok ? 'pass' : 'fail', 'detail' => $allow_ok ? 'allowed_verticals=[woo_bizops] produced enforced allow.' : 'Positive Guru Woo policy did not permit the capable actor.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $allow_ok ) { $failures[] = 'woo_guru_policy_positive_allow_failed'; }

				$user_obj = function_exists( 'get_userdata' ) ? get_userdata( $policy_user_id ) : false;
				$user_roles = $user_obj && isset( $user_obj->roles ) ? array_map( 'sanitize_key', (array) $user_obj->roles ) : array();
				$role_rank = array( 'subscriber' => 0, 'contributor' => 1, 'author' => 2, 'editor' => 3, 'administrator' => 4 );
				$persisted_role = '';
				$persisted_rank = -1;
				foreach ( $user_roles as $user_role ) {
					if ( isset( $role_rank[ $user_role ] ) && $role_rank[ $user_role ] > $persisted_rank ) {
						$persisted_role = $user_role;
						$persisted_rank = $role_rank[ $user_role ];
					}
				}
				$persisted_plan = function_exists( 'apply_filters' ) ? sanitize_key( (string) apply_filters( 'bizcity_twinweb_user_tier', 'free', $policy_user_id ) ) : 'free';
				if ( ! in_array( $persisted_plan, array( 'free', 'plus', 'pro' ), true ) ) { $persisted_plan = 'free'; }
				$audience_saved = BizCity_Knowledge_Database::instance()->update_character( $this->policy_fixture_guru_id, array( 'min_role' => $persisted_role, 'min_plan' => $persisted_plan ) );
				$audience_allow = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id' => $policy_user_id,
					'guru_id' => $this->policy_fixture_guru_id,
					'surface' => 'twinweb',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
				) );
				$audience_ok = ! is_wp_error( $audience_saved ) && ! empty( $audience_allow['allowed'] );
				$step = array( 'label' => 'Runtime — persisted Guru role/plan policy is enforced', 'status' => $audience_ok ? 'pass' : 'fail', 'detail' => $audience_ok ? 'Stored min_role/min_plan were read by the shared policy gateway.' : 'Stored Guru audience policy did not produce the expected decision.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $audience_ok ) { $failures[] = 'woo_guru_persisted_audience_not_enforced'; }

				$binding_mismatch = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id'    => $policy_user_id,
					'guru_id'    => $this->policy_fixture_guru_id,
					'surface'    => 'twinweb',
					'platform'   => 'TWINWEB',
					'account_id' => '__healthtest_missing_twinweb_binding__',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
				) );
				$binding_mismatch_ok = empty( $binding_mismatch['allowed'] ) && in_array( (string) ( $binding_mismatch['reason'] ?? '' ), array( BizCity_TwinBrain_Guru_Policy::REASON_BINDING_MISMATCH, BizCity_TwinBrain_Guru_Policy::REASON_BINDING_PENDING ), true );
				$step = array( 'label' => 'Runtime — Guru not matching channel binding is denied', 'status' => $binding_mismatch_ok ? 'pass' : 'fail', 'detail' => $binding_mismatch_ok ? 'A mismatched TWINWEB binding cannot execute the Guru Woo policy.' : 'Guru allowlist bypassed channel binding verification.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $binding_mismatch_ok ) { $failures[] = 'woo_guru_binding_mismatch_not_denied'; }

				$resource_denied = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id'    => $policy_user_id,
					'guru_id'    => $this->policy_fixture_guru_id,
					'surface'    => 'twinweb',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
					'target_resource' => array( 'scope' => 'woo', 'blog_id' => PHP_INT_MAX ),
				) );
				$resource_ok = empty( $resource_denied['allowed'] ) && (string) ( $resource_denied['reason'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::REASON_RESOURCE_NOT_OWNED;
				$step = array( 'label' => 'Runtime — cross-tenant Woo resource is denied', 'status' => $resource_ok ? 'pass' : 'fail', 'detail' => $resource_ok ? 'A target resource from another blog is rejected.' : 'Target resource tenant mismatch was not rejected.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $resource_ok ) { $failures[] = 'woo_cross_tenant_resource_not_denied'; }

				$role_denied = BizCity_TwinBrain_Guru_Policy::decide( array(
					'user_id' => $policy_user_id,
					'guru_id' => $this->policy_fixture_guru_id,
					'surface' => 'twinweb',
					'capability' => BizCity_TwinBrain_Guru_Policy::CAP_WOO_BIZOPS,
					'required_role' => '__healthtest_role__',
				) );
				$role_ok = empty( $role_denied['allowed'] ) && (string) ( $role_denied['reason'] ?? '' ) === BizCity_TwinBrain_Guru_Policy::REASON_ROLE_NOT_ALLOWED;
				$step = array( 'label' => 'Runtime — unmet Guru role requirement is denied', 'status' => $role_ok ? 'pass' : 'fail', 'detail' => $role_ok ? 'An unmet required role returns role_not_allowed.' : 'Required role was not enforced.' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $role_ok ) { $failures[] = 'woo_guru_role_requirement_not_denied'; }
			}
		}

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

	public function cleanup(): void {
		if ( $this->policy_fixture_guru_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
			// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — remove the disposable policy fixture after pass or failure.
			BizCity_Knowledge_Database::instance()->delete_character( $this->policy_fixture_guru_id );
			$this->policy_fixture_guru_id = 0;
		}
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — cleanup is best-effort and limited to the disposable Guru fixture above.
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
