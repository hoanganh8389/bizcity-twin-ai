<?php
/**
 * Context Bank runtime boundary.
 *
 * CB2.1 intentionally loads only the package contract boundary. Registries,
 * storage, REST handlers, workers and adapters are lazy slices owned by later
 * roadmap sprints.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BIZCITY_CONTEXT_BANK_LOADED' ) ) {
	return;
}

// [2026-09-01 Johnny Chu] CB2.1 — establish a side-effect-free Context Bank package boundary.
define( 'BIZCITY_CONTEXT_BANK_LOADED', true );
if ( ! defined( 'BIZCITY_CONTEXT_BANK_DIR' ) ) {
	define( 'BIZCITY_CONTEXT_BANK_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_CONTEXT_BANK_VERSION' ) ) {
	define( 'BIZCITY_CONTEXT_BANK_VERSION', '0.1.0' );
}

	// [2026-09-01 Johnny Chu] PHASE-CB-MVP — load the server-side access boundary before ledger follow/query consumers.
	$_context_bank_access = __DIR__ . '/includes/class-context-bank-access.php';
	if ( class_exists( 'BizCity_Safe_Loader', false )
		&& is_file( $_context_bank_access )
		&& is_readable( $_context_bank_access ) ) {
		BizCity_Safe_Loader::require_file( $_context_bank_access, 'context_bank.access' );
	}
	unset( $_context_bank_access );

// [2026-09-01 Johnny Chu] CB2.2.1 — load the contract registry only after a guarded artifact check.
$_context_bank_contract_registry = __DIR__ . '/contracts/class-context-bank-contract-registry.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_contract_registry )
	&& is_readable( $_context_bank_contract_registry ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_contract_registry, 'context_bank.contract_registry' );
}
if ( class_exists( 'BizCity_Context_Bank_Contract_Registry', false ) ) {
	BizCity_Context_Bank_Contract_Registry::register_builtins();
}
unset( $_context_bank_contract_registry );

// [2026-09-01 Johnny Chu] CB2.2.2 — load the identity dimension registry through the same guarded loader boundary.
$_context_bank_identity_registry = __DIR__ . '/contracts/class-context-bank-identity-registry.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_identity_registry )
	&& is_readable( $_context_bank_identity_registry ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_identity_registry, 'context_bank.identity_registry' );
}
if ( class_exists( 'BizCity_Context_Bank_Identity_Registry', false ) ) {
	BizCity_Context_Bank_Identity_Registry::register_builtins();
}
unset( $_context_bank_identity_registry );

// [2026-09-01 Johnny Chu] CB3.1 — load the pointer ledger only after the
// contract and identity registries exist; schema work remains Provisioner-only.
$_context_bank_ledger = __DIR__ . '/includes/class-context-bank-ledger.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_ledger )
	&& is_readable( $_context_bank_ledger ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_ledger, 'context_bank.ledger' );
}
if ( class_exists( 'BizCity_Context_Bank_Ledger', false ) ) {
	BizCity_Context_Bank_Ledger::instance();
}
unset( $_context_bank_ledger );

// [2026-09-01 Johnny Chu] PHASE-CB6.1 — load the bounded search owner after the ledger and before REST consumers.
$_context_bank_search = __DIR__ . '/includes/class-context-bank-search.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_search )
	&& is_readable( $_context_bank_search ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_search, 'context_bank.search' );
}
unset( $_context_bank_search );

// [2026-09-01 Johnny Chu] PHASE-CB-MVP — mount the metadata-only REST consumer only for the Context Bank namespace.
$_context_bank_rest_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
if ( false !== strpos( $_context_bank_rest_uri, '/wp-json/bizcity-context/' ) ) {
	$_context_bank_rest = __DIR__ . '/includes/class-context-bank-rest-controller.php';
	if ( class_exists( 'BizCity_Safe_Loader', false )
		&& is_file( $_context_bank_rest )
		&& is_readable( $_context_bank_rest ) ) {
		BizCity_Safe_Loader::require_file( $_context_bank_rest, 'context_bank.rest_controller' );
	}
	if ( class_exists( 'BizCity_Context_Bank_REST_Controller', false ) ) {
		BizCity_Context_Bank_REST_Controller::init();
	}
	unset( $_context_bank_rest );
}
unset( $_context_bank_rest_uri );

// [2026-09-01 Johnny Chu] PHASE-CB4.4 — load the shared memory admission/read adapter after the pointer ledger.
$_context_bank_memory_adapter = __DIR__ . '/includes/class-context-bank-memory-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_memory_adapter )
	&& is_readable( $_context_bank_memory_adapter ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_memory_adapter, 'context_bank.memory_adapter' );
}
unset( $_context_bank_memory_adapter );

// [2026-09-01 Johnny Chu] CB3.4 — load the bounded reconciler after the pointer ledger without starting a worker.
$_context_bank_reconciler = __DIR__ . '/includes/class-context-bank-reconciler.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_reconciler )
	&& is_readable( $_context_bank_reconciler ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_reconciler, 'context_bank.reconciler' );
}
unset( $_context_bank_reconciler );

// [2026-09-01 Johnny Chu] CB2.2.3 — load the typed correlation predicate registry through Safe Loader.
$_context_bank_predicate_registry = __DIR__ . '/contracts/class-context-bank-predicate-registry.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_predicate_registry )
	&& is_readable( $_context_bank_predicate_registry ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_predicate_registry, 'context_bank.predicate_registry' );
}
if ( class_exists( 'BizCity_Context_Bank_Predicate_Registry', false ) ) {
	BizCity_Context_Bank_Predicate_Registry::register_builtins();
}
unset( $_context_bank_predicate_registry );

// [2026-09-01 Johnny Chu] CB2.2.4 — load declarative rollup definitions without starting workers or writing state.
$_context_bank_rollup_registry = __DIR__ . '/contracts/class-context-bank-rollup-registry.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_rollup_registry )
	&& is_readable( $_context_bank_rollup_registry ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_rollup_registry, 'context_bank.rollup_registry' );
}
if ( class_exists( 'BizCity_Context_Bank_Rollup_Registry', false ) ) {
	BizCity_Context_Bank_Rollup_Registry::register_builtins();
}
unset( $_context_bank_rollup_registry );

// [2026-09-01 Johnny Chu] PHASE-CB5.1 — load the side-effect-free rollup reducer after declarative definitions; workers remain deferred.
$_context_bank_rollup_engine = __DIR__ . '/includes/class-context-bank-rollup-engine.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_rollup_engine )
	&& is_readable( $_context_bank_rollup_engine ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_rollup_engine, 'context_bank.rollup_engine' );
}
unset( $_context_bank_rollup_engine );

// [2026-09-02 Johnny Chu] PHASE-CB5.1 — load the resumable rollup worker after the reducer; no worker is scheduled or executed here.
$_context_bank_rollup_worker = __DIR__ . '/includes/class-context-bank-rollup-worker.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_rollup_worker )
	&& is_readable( $_context_bank_rollup_worker ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_rollup_worker, 'context_bank.rollup_worker' );
}
unset( $_context_bank_rollup_worker );

// [2026-09-01 Johnny Chu] PHASE-CB6.2 — load the side-effect-free KG candidate gate; KG-Hub remains the semantic owner.
$_context_bank_kg_policy = __DIR__ . '/includes/class-context-bank-kg-candidate-policy.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_kg_policy )
	&& is_readable( $_context_bank_kg_policy ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_kg_policy, 'context_bank.kg_candidate_policy' );
}
unset( $_context_bank_kg_policy );

// [2026-09-02 Johnny Chu] PHASE-CB6.3 — load the KG promotion bridge after the candidate gate; promotion remains feature-gated and does not start at bootstrap.
$_context_bank_kg_bridge = __DIR__ . '/includes/class-context-bank-kg-bridge.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_kg_bridge )
	&& is_readable( $_context_bank_kg_bridge ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_kg_bridge, 'context_bank.kg_bridge' );
}
unset( $_context_bank_kg_bridge );

// [2026-09-02 Johnny Chu] PHASE-CB4.3 — load the Woo projection adapter after the Context Bank pointer and filestore owners.
$_context_bank_commerce_adapter = __DIR__ . '/includes/class-context-bank-commerce-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_commerce_adapter )
	&& is_readable( $_context_bank_commerce_adapter ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_commerce_adapter, 'context_bank.commerce_adapter' );
}
if ( class_exists( 'BizCity_Context_Bank_Commerce_Adapter', false ) ) {
	BizCity_Context_Bank_Commerce_Adapter::boot();
}
unset( $_context_bank_commerce_adapter );

// [2026-09-01 Johnny Chu] PHASE-CB7.1 — load the server-owned scope resolver without starting MPR/TwinBrain retrieval.
$_context_bank_scope_resolver = __DIR__ . '/includes/class-context-bank-scope-resolver.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_scope_resolver )
	&& is_readable( $_context_bank_scope_resolver ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_scope_resolver, 'context_bank.scope_resolver' );
}
unset( $_context_bank_scope_resolver );

// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — load the Skill reference adapter through the Context Bank owner so canonical Skill lifecycle events reach one pointer-only projection path.
$_context_bank_rule_reference_adapter = __DIR__ . '/includes/class-context-bank-rule-reference-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_rule_reference_adapter )
	&& is_readable( $_context_bank_rule_reference_adapter ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_rule_reference_adapter, 'context_bank.rule_reference_adapter' );
}
if ( class_exists( 'BizCity_Context_Bank_Rule_Reference_Adapter', false ) ) {
	BizCity_Context_Bank_Rule_Reference_Adapter::boot();
}
unset( $_context_bank_rule_reference_adapter );

// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — mount the post-commit identity merge rollup consumer through the guarded Context Bank loader.
$_context_bank_identity_merge_adapter = __DIR__ . '/includes/class-context-bank-identity-merge-adapter.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_identity_merge_adapter )
	&& is_readable( $_context_bank_identity_merge_adapter ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_identity_merge_adapter, 'context_bank.identity_merge_adapter' );
}
if ( class_exists( 'BizCity_Context_Bank_Identity_Merge_Adapter', false ) ) {
	BizCity_Context_Bank_Identity_Merge_Adapter::boot();
}
unset( $_context_bank_identity_merge_adapter );

// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — load the bounded rollup scheduler through Safe Loader; the explicit tenant flag controls recurring work.
$_context_bank_rollup_cron = __DIR__ . '/includes/class-context-bank-rollup-cron.php';
if ( class_exists( 'BizCity_Safe_Loader', false )
	&& is_file( $_context_bank_rollup_cron )
	&& is_readable( $_context_bank_rollup_cron ) ) {
	BizCity_Safe_Loader::require_file( $_context_bank_rollup_cron, 'context_bank.rollup_cron' );
}
if ( class_exists( 'BizCity_Context_Bank_Rollup_Cron', false ) ) {
	BizCity_Context_Bank_Rollup_Cron::boot();
}
unset( $_context_bank_rollup_cron );
