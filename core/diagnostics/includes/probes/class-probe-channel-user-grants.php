<?php
/**
 * DDV probe for PHASE-1.33A exact channel-account user grants.
 *
 * The probe uses disposable users and one synthetic HMAC account key. It does
 * not connect a provider, write Context Bank rows or read a filestore payload.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Channel_User_Grants', false ) ) {
	return;
}

final class BizCity_Probe_Channel_User_Grants implements BizCity_Diagnostics_Probe {

	private $meta_key = '';
	private $fixture_users = array();
	private $primary_user_id = 0;

	public function id(): string {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — expose the exact channel grant ownership probe.
		return 'core.channel.channel_user_grants';
	}

	public function label(): string {
		return 'Channel account primary/delegate grants';
	}

	public function description(): string {
		return 'Checks current-user primary binding, bounded delegate authorization, revoke behavior, HMAC account keys and primary conflict refusal.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 67; }
	public function icon(): string { return 'users'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — require the canonical grant service and an authenticated diagnostic operator.
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) ) {
			return new WP_Error( 'channel_user_grant_missing', 'Channel user grant service is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Access' ) ) {
			return new WP_Error( 'context_bank_access_missing', 'Context Bank access boundary is not loaded.' );
		}
		if ( ! function_exists( 'get_current_user_id' ) || (int) get_current_user_id() <= 0 ) {
			return new WP_Error( 'channel_user_grant_operator_missing', 'An authenticated diagnostic operator is required.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'channel_user_grant_admin_operator_missing', 'An authenticated tenant administrator is required for the ownership matrix.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — exercise a disposable primary/delegate/revoke/conflict matrix without provider or Context Bank side effects.
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — verify grant mutations route cache invalidation through the canonical ledger owner.
		$base = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$grant_source = is_readable( $base . '/core/channel-gateway/includes/class-channel-user-grant.php' ) ? (string) file_get_contents( $base . '/core/channel-gateway/includes/class-channel-user-grant.php' ) : '';
		$ledger_source = is_readable( $base . '/core/context-bank/includes/class-context-bank-ledger.php' ) ? (string) file_get_contents( $base . '/core/context-bank/includes/class-context-bank-ledger.php' ) : '';
		$cache_invalidation_ok = strpos( $grant_source, 'invalidate_authorization_cache' ) !== false && strpos( $ledger_source, 'public static function invalidate_authorization_cache' ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk - grant cache invalidation owner', 'status' => $cache_invalidation_ok ? 'pass' : 'fail', 'detail' => $cache_invalidation_ok ? 'Grant mutations call the canonical Context Bank ledger invalidator.' : 'Grant/cache invalidation owner marker is missing.' ) );
		if ( ! $cache_invalidation_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Grant cache invalidation owner is incomplete.', 'error' => 'grant_cache_invalidation_missing', 'fix_hint' => 'Route grant mutation invalidation through the Context Bank ledger cache owner.', 'steps' => array() );
		}
		$this->primary_user_id = (int) get_current_user_id();
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$account_id = 'grant_probe_' . $suffix;
		$primary = BizCity_Channel_User_Grant::bind_primary_from_current( 'zalo_personal', $account_id, array( 'connection_verified' => true, 'connection_owner_user_id' => $this->primary_user_id, 'source' => 'diagnostics' ) );
		$this->meta_key = BizCity_Channel_User_Grant::meta_key( 'zalo_personal', $account_id );
		$primary_ok = ! empty( $primary['ok'] ) && (string) ( $primary['relation'] ?? '' ) === 'primary' && strpos( $this->meta_key, '_bizcity_chgrant_v1_' ) === 0;

		$delegate_id = 0;
		$outsider_id = 0;
		if ( $primary_ok && function_exists( 'wp_insert_user' ) ) {
			$delegate_id = $this->create_user( 'delegate' );
			$outsider_id = $this->create_user( 'outsider' );
		}
		$grant = $delegate_id > 0
			? BizCity_Channel_User_Grant::grant( 'zalo_personal', $account_id, $delegate_id, array( 'view_context', 'reply' ), $this->primary_user_id, array( 'source' => 'diagnostics' ) )
			: array( 'ok' => false );
		$delegate_authorized = $delegate_id > 0 ? BizCity_Channel_User_Grant::authorize( 'zalo_personal', $account_id, $delegate_id, 'view_context' ) : array( 'ok' => false, 'reason' => 'fixture_delegate_missing' );
		$delegate_ok = $delegate_id > 0 && ! empty( $grant['ok'] ) && ! empty( $delegate_authorized['ok'] );
		$delegate_pointer_ok = $delegate_id > 0 && ! empty( BizCity_Channel_User_Grant::authorize_account_key( 'zalo_personal', BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id ), $delegate_id, 'view_context' )['ok'] );
		$wrong_account_denied = $delegate_id > 0 && empty( BizCity_Channel_User_Grant::authorize_account_key( 'zalo_personal', BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id . '_other' ), $delegate_id, 'view_context' )['ok'] );
		$outsider_denied = $outsider_id > 0 && empty( BizCity_Channel_User_Grant::authorize( 'zalo_personal', $account_id, $outsider_id, 'view_context' )['ok'] );
		$malformed_denied = false;
		if ( $outsider_id > 0 ) {
			$malformed = array(
				'contract' => 'wrong.contract',
				'version' => BizCity_Channel_User_Grant::VERSION,
				'blog_id' => (int) get_current_blog_id(),
				'channel' => 'zalo_personal',
				'account_key' => BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id . '_other' ),
				'permissions' => array( 'view_context' ),
				'relation' => 'agent',
				'status' => 'active',
			);
			if ( class_exists( 'BizCity_User_Meta_Cache' ) && method_exists( 'BizCity_User_Meta_Cache', 'set' ) ) {
				BizCity_User_Meta_Cache::set( $outsider_id, $this->meta_key, $malformed );
			} else {
				update_user_meta( $outsider_id, $this->meta_key, $malformed );
			}
			$malformed_denied = empty( BizCity_Channel_User_Grant::authorize_account_key( 'zalo_personal', BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id ), $outsider_id, 'view_context' )['ok'] );
		}
		$account_key = BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id );
		$same_label_account_id = $account_id . '_same_label';
		$same_label_key = BizCity_Channel_User_Grant::account_key( 'zalo_personal', $same_label_account_id );
		// [2026-09-06 11:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — prove display labels never collapse exact account HMAC identity and one user cannot bind a second Personal primary.
		$second_primary = BizCity_Channel_User_Grant::bind_primary_from_current( 'zalo_personal', $same_label_account_id, array( 'connection_verified' => true, 'connection_owner_user_id' => $this->primary_user_id, 'source' => 'diagnostics' ) );
		$second_primary_denied = empty( $second_primary['ok'] ) && 'personal_primary_limit_reached' === (string) ( $second_primary['reason'] ?? '' );
		$same_label_isolated = $delegate_id > 0 && $same_label_key !== $account_key && empty( BizCity_Channel_User_Grant::authorize_account_key( 'zalo_personal', $same_label_key, $delegate_id, 'view_context' )['ok'] );
		$channel_pointer = array(
			'blog_id' => (int) get_current_blog_id(),
			'wp_user_id' => $this->primary_user_id,
			'entity_type' => 'channel_account',
			'entity_key' => 'zalo_personal:' . $account_key,
		);
		$personal_pointer = array(
			'blog_id' => (int) get_current_blog_id(),
			'wp_user_id' => $this->primary_user_id,
			'entity_type' => 'twin_user',
			'entity_key' => 'user:' . $this->primary_user_id,
		);
		$delegate_channel_access = array( 'ok' => false );
		$delegate_personal_denied = false;
		$outsider_channel_denied = false;
		$admin_channel_access = array( 'ok' => false );
		if ( $delegate_id > 0 && $outsider_id > 0 ) {
			// [2026-09-06 11:31 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — prove channel grants do not widen personal pointer ownership before any filestore follow.
			try {
				wp_set_current_user( $delegate_id );
				$delegate_channel_access = BizCity_Context_Bank_Access::authorize_pointer( $channel_pointer );
				$delegate_personal_denied = empty( BizCity_Context_Bank_Access::authorize_pointer( $personal_pointer )['ok'] );
				wp_set_current_user( $outsider_id );
				$outsider_channel_denied = empty( BizCity_Context_Bank_Access::authorize_pointer( $channel_pointer )['ok'] );
				wp_set_current_user( $this->primary_user_id );
				$admin_channel_access = BizCity_Context_Bank_Access::authorize_pointer( $channel_pointer );
			} finally {
				wp_set_current_user( $this->primary_user_id );
			}
		}
		$delegate_channel_matrix_ok = $delegate_id > 0 && ! empty( $delegate_channel_access['ok'] ) && 'channel_grant' === (string) ( $delegate_channel_access['scope'] ?? '' );
		$admin_channel_matrix_ok = ! empty( $admin_channel_access['ok'] ) && 'tenant_admin' === (string) ( $admin_channel_access['scope'] ?? '' );
		$revoke = $delegate_id > 0 ? BizCity_Channel_User_Grant::revoke( 'zalo_personal', $account_id, $delegate_id, $this->primary_user_id, array( 'source' => 'diagnostics' ) ) : array( 'ok' => false );
		$revoke_ok = $delegate_id > 0 && ! empty( $revoke['ok'] ) && empty( BizCity_Channel_User_Grant::authorize( 'zalo_personal', $account_id, $delegate_id, 'view_context' )['ok'] );

		$conflict_ok = false;
		if ( $outsider_id > 0 ) {
			$conflict_grant = array(
				'contract' => BizCity_Channel_User_Grant::CONTRACT,
				'version' => BizCity_Channel_User_Grant::VERSION,
				'blog_id' => (int) get_current_blog_id(),
				'channel' => 'zalo_personal',
				'account_key' => BizCity_Channel_User_Grant::account_key( 'zalo_personal', $account_id ),
				'relation' => 'primary',
				'permissions' => array( 'view_context' ),
				'status' => 'active',
			);
			if ( class_exists( 'BizCity_User_Meta_Cache' ) && method_exists( 'BizCity_User_Meta_Cache', 'set' ) ) {
				BizCity_User_Meta_Cache::set( $outsider_id, $this->meta_key, $conflict_grant );
			} else {
				update_user_meta( $outsider_id, $this->meta_key, $conflict_grant );
			}
			$primary_state = BizCity_Channel_User_Grant::primary_for_account( 'zalo_personal', $account_id );
			$conflict_ok = ! empty( $primary_state['conflict'] );
		}

		$checks = array(
			array( 'label' => 'Current user becomes the sole primary', 'ok' => $primary_ok, 'detail' => $primary_ok ? 'The server-resolved current user received one exact primary grant.' : 'Primary binding did not produce the expected exact grant.' ),
			array( 'label' => 'Delegate receives exact account permission', 'ok' => $delegate_ok, 'detail' => $delegate_ok ? 'A delegated user can authorize view_context for only the synthetic account.' : 'Delegate grant or permission resolution failed: grant=' . sanitize_key( (string) ( $grant['reason'] ?? ( ! empty( $grant['ok'] ) ? 'ok' : 'unknown' ) ) ) . ', authorize=' . sanitize_key( (string) ( $delegate_authorized['reason'] ?? ( ! empty( $delegate_authorized['ok'] ) ? 'ok' : 'unknown' ) ) ) ),
			array( 'label' => 'Pointer account key authorizes exact grant', 'ok' => $delegate_pointer_ok, 'detail' => $delegate_pointer_ok ? 'The HMAC account key resolves the delegated Context Bank permission.' : 'Pointer account-key authorization failed.' ),
			array( 'label' => 'Wrong account key is denied', 'ok' => $wrong_account_denied, 'detail' => $wrong_account_denied ? 'A different HMAC account key is denied.' : 'A delegate crossed into another account scope.' ),
			array( 'label' => 'Same-label account keeps a distinct HMAC scope', 'ok' => $same_label_isolated, 'detail' => $same_label_isolated ? 'A delegate for account A cannot authorize the distinct account B key even when the display label is the same.' : 'Same-label account identity was not isolated by its exact HMAC key.' ),
			array( 'label' => 'One user cannot bind a second Personal primary', 'ok' => $second_primary_denied, 'detail' => $second_primary_denied ? 'The one-active-Personal-primary policy returned personal_primary_limit_reached.' : 'A second Zalo Personal primary was not refused with the canonical limit reason.' ),
			array( 'label' => 'Malformed grant payload is denied', 'ok' => $malformed_denied, 'detail' => $malformed_denied ? 'An active but malformed usermeta payload is denied.' : 'Malformed grant metadata was accepted.' ),
			array( 'label' => 'Unlisted user is denied', 'ok' => $outsider_denied, 'detail' => $outsider_denied ? 'A user without the exact meta-key grant is denied.' : 'An unlisted user received channel access.' ),
			array( 'label' => 'Delegate can follow only the exact channel pointer scope', 'ok' => $delegate_channel_matrix_ok, 'detail' => $delegate_channel_matrix_ok ? 'The delegated user is authorized for the exact channel account before any filestore follow.' : 'The exact channel pointer was not authorized through the Context Bank access boundary.' ),
			array( 'label' => 'Delegate cannot follow primary personal pointer', 'ok' => $delegate_personal_denied, 'detail' => $delegate_personal_denied ? 'Channel access did not widen the primary user personal scope.' : 'A channel delegate crossed into the primary personal pointer scope.' ),
			array( 'label' => 'Outsider is denied before channel pointer follow', 'ok' => $outsider_channel_denied, 'detail' => $outsider_channel_denied ? 'An unlisted user was denied at pointer authorization.' : 'An outsider reached the channel pointer authorization boundary.' ),
			array( 'label' => 'Tenant admin can inspect the current channel pointer', 'ok' => $admin_channel_matrix_ok, 'detail' => $admin_channel_matrix_ok ? 'The explicit tenant-admin branch authorizes the current tenant pointer.' : 'The tenant-admin Context Bank branch did not authorize the current tenant pointer.' ),
			array( 'label' => 'Revoke removes access immediately', 'ok' => $revoke_ok, 'detail' => $revoke_ok ? 'Revocation makes the delegated permission fail closed.' : 'Revocation did not remove the effective permission.' ),
			array( 'label' => 'Multiple primary state fails closed', 'ok' => $conflict_ok, 'detail' => $conflict_ok ? 'Two active primary projections return channel_primary_conflict.' : 'The service selected an implicit primary.' ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$ctx->emit_step( array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] ) );
			$pass = $pass && $check['ok'];
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Channel primary/delegate grants passed exact-key, revoke and conflict checks.' : 'Channel primary/delegate grant ownership failed.', 'fix_hint' => $pass ? '' : 'Keep channel grants tenant-bound, exact-key and fail closed on primary conflicts.', 'steps' => array() );
	}

	public function cleanup(): void {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — remove disposable grant projections and fixture users without touching provider or Context Bank data.
		if ( $this->meta_key !== '' ) {
			if ( $this->primary_user_id > 0 ) {
				delete_user_meta( $this->primary_user_id, $this->meta_key );
			}
			foreach ( $this->fixture_users as $user_id ) {
				delete_user_meta( (int) $user_id, $this->meta_key );
			}
		}
		foreach ( $this->fixture_users as $user_id ) {
			if ( function_exists( 'wp_delete_user' ) ) {
				wp_delete_user( (int) $user_id );
			}
		}
		$this->fixture_users = array();
	}

	private function create_user( $label ) {
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — create one disposable subscriber fixture for the ownership matrix.
		$suffix = strtolower( substr( md5( (string) microtime( true ) . '|' . wp_generate_uuid4() ), 0, 12 ) );
		$user_id = wp_insert_user( array(
			'user_login' => 'cb_grant_' . sanitize_key( $label ) . '_' . $suffix,
			'user_pass' => wp_generate_password( 32, true, true ),
			'user_email' => 'cb-grant-' . $label . '-' . $suffix . '@invalid.test',
			'role' => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) {
			return 0;
		}
		// [2026-09-06 Johnny Chu - Chu Hoàng Anh] PHASE-1.33A-DDV — add disposable users to the current multisite blog before testing delegate authorization.
		if ( function_exists( 'add_user_to_blog' ) && function_exists( 'get_current_blog_id' ) ) {
			add_user_to_blog( (int) get_current_blog_id(), (int) $user_id, 'subscriber' );
		}
		$this->fixture_users[] = (int) $user_id;
		return (int) $user_id;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_User_Grants';
	return $list;
} );
