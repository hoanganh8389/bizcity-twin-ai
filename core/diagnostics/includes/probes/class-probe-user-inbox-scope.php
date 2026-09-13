<?php
/**
 * User-centric Inbox scope contract probe.
 *
 * Verifies the canonical CRM B2/C envelope and records the current
 * CRM-to-Context-Bank handoff boundary without creating a second resolver.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-08
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_probe_interface = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_probe_interface ) ) {
		require_once $_probe_interface;
	}
	unset( $_probe_interface );
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_User_Inbox_Scope', false ) ) {
	return;
}

final class BizCity_Probe_User_Inbox_Scope implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.user_inbox_scope'; }
	public function label(): string { return 'CRM user-centric Inbox scope'; }
	public function description(): string { return 'Checks one CRM-owned user-inbox-scope envelope for B2/C forwarding, Personal owner isolation markers and Context Bank handoff status.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 57; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 120; }

	public function precondition() {
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$owner_file = $root . 'plugins/bizcity-twin-crm/includes/class-inbox-access.php';
		$source = is_readable( $owner_file ) ? (string) file_get_contents( $owner_file ) : '';
		$disk_ok = strpos( $source, 'resolve_user_inbox_scope' ) !== false
			&& strpos( $source, 'resolve_scope( $user_id, $scope_key, \'b2\' === $scope_key )' ) !== false
			&& strpos( $source, "'user-inbox-scope'" ) !== false;
		$steps[] = array(
			'label' => 'Disk - canonical CRM user-inbox scope owner',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'CRM owner exposes the versioned envelope and forwards normalized B2/C policy.' : 'Canonical CRM scope owner or B2/C forwarding markers are missing.',
		);

		$loaded = class_exists( 'BizCity_CRM_Inbox_Access', false ) && method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_user_inbox_scope' );
		$steps[] = array(
			'label' => 'Loader - CRM scope resolver',
			'status' => $loaded ? 'pass' : 'skip',
			'detail' => $loaded ? 'BizCity_CRM_Inbox_Access::resolve_user_inbox_scope() is loaded.' : 'CRM Inbox scope owner is not loaded in this diagnostics context.',
		);

		$runtime_status = 'skip';
		$runtime_detail = 'Authenticated CRM resolver runtime is deferred because the owner is not loaded or no current user fixture exists.';
		$runtime_ok = false;
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$c_scope = array();
		$b2_scope = array();
		if ( $loaded && $user_id > 0 ) {
			$c_scope = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $user_id, 'c' );
			$b2_scope = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $user_id, 'b2' );
			$runtime_ok = is_array( $c_scope ) && is_array( $b2_scope )
				&& (string) ( $c_scope['contract'] ?? '' ) === 'user-inbox-scope'
				&& (string) ( $b2_scope['contract'] ?? '' ) === 'user-inbox-scope'
				&& (string) ( $c_scope['version'] ?? '' ) === '1.0.0'
				&& (string) ( $b2_scope['version'] ?? '' ) === '1.0.0'
				&& (string) ( $c_scope['surface'] ?? '' ) === 'C_PUBLIC_TWINGPT'
				&& (string) ( $b2_scope['surface'] ?? '' ) === 'B2_ADMIN_CRM'
				&& (int) ( $c_scope['principal']['user_id'] ?? 0 ) === $user_id
				&& (int) ( $b2_scope['principal']['user_id'] ?? 0 ) === $user_id;
			$runtime_status = $runtime_ok ? 'pass' : 'fail';
			$runtime_detail = $runtime_ok
				? 'C and B2 envelopes preserve one principal and distinct surface policy keys.'
				: 'C/B2 envelope contract, surface or principal invariants failed.';
		}
		$steps[] = array(
			'label' => 'Runtime - B2/C envelope forwarding',
			'status' => $runtime_status,
			'detail' => $runtime_detail,
		);

		$selected_status = 'skip';
		$selected_detail = 'B2 selected-user runtime is deferred because no distinct operator/selected-user fixture is available.';
		if ( $loaded && $user_id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) && function_exists( 'get_users' ) ) {
			$selected_users = get_users( array( 'exclude' => array( $user_id ), 'number' => 1, 'fields' => array( 'ID' ) ) );
			$selected_user_id = ! empty( $selected_users[0]->ID ) ? (int) $selected_users[0]->ID : 0;
			if ( $selected_user_id > 0 ) {
				$selected_scope = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $selected_user_id, 'b2' );
				$selected_ok = is_array( $selected_scope )
					&& (string) ( $selected_scope['surface'] ?? '' ) === 'B2_ADMIN_CRM'
					&& (int) ( $selected_scope['principal']['user_id'] ?? 0 ) === $selected_user_id;
				$selected_status = $selected_ok ? 'pass' : 'fail';
				$selected_detail = $selected_ok ? 'Admin operator resolved a distinct selected principal through the B2 envelope without collapsing to operator user scope.' : 'B2 selected-user envelope did not preserve the selected principal.';
			}
		}
		$steps[] = array( 'label' => 'Runtime - B2 selected user differs from admin operator', 'status' => $selected_status, 'detail' => $selected_detail );

		$business_items = array();
		foreach ( (array) ( $b2_scope['branches']['customer'] ?? array() ) as $scope_item ) {
			if ( is_array( $scope_item ) && in_array( sanitize_key( (string) ( $scope_item['channel'] ?? '' ) ), array( 'facebook', 'messenger', 'zalo_oa' ), true ) ) {
				$business_items[] = $scope_item;
			}
		}
		$business_keys = array_values( array_unique( array_map( 'strval', array_column( $business_items, 'account_key' ) ) ) );
		$page_oa_status = count( $business_keys ) >= 2 ? 'pass' : 'skip';
		$page_oa_detail = count( $business_keys ) >= 2
			? 'One user scope contains at least two distinct business Page/OA account keys.'
			: 'Multi-Page/OA union is deferred because the current tenant has fewer than two distinct business account fixtures.';
		$steps[] = array( 'label' => 'Runtime - one user multi-Page/OA scope union', 'status' => $page_oa_status, 'detail' => $page_oa_detail );

		$context_loaded = class_exists( 'BizCity_Context_Bank_Scope_Resolver', false ) && class_exists( 'BizCity_Context_Bank_Access', false );
		$handoff_status = 'skip';
		$handoff_detail = $context_loaded
			? ( class_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter', false )
				? 'Context Bank scope/access owners and the guarded CX2 CRM scope adapter are loaded; Runtime admission and two-user canary evidence remain pending.'
				: 'Context Bank scope/access owners are loaded, but the guarded CX2 CRM scope adapter is not loaded in this diagnostics context.' )
			: 'Context Bank scope/access owners are not loaded in this diagnostics context; CRM-to-Context-Bank handoff evidence is deferred.';
		$steps[] = array(
			'label' => 'Runtime - CRM to Context Bank scope handoff',
			'status' => $handoff_status,
			'detail' => $handoff_detail,
		);
		$cx2_file = $root . 'core/context-bank/includes/class-context-bank-crm-scope-adapter.php';
		$cx2_source = is_readable( $cx2_file ) ? (string) file_get_contents( $cx2_file ) : '';
		$cx2_disk_ok = strpos( $cx2_source, 'class BizCity_Context_Bank_CRM_Scope_Adapter' ) !== false
			&& strpos( $cx2_source, 'user-inbox-scope' ) !== false
			&& strpos( $cx2_source, 'grant_account_key' ) !== false;
		$steps[] = array(
			'label' => 'Disk - CX2 CRM to Context Bank bridge',
			'status' => $cx2_disk_ok ? 'pass' : 'fail',
			'detail' => $cx2_disk_ok ? 'The bridge consumes user-inbox-scope and preserves the canonical grant account key.' : 'The CX2 bridge artifact or scope/grant markers are missing.',
		);
		$cx2_loaded = class_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter', false )
			&& method_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter', 'authorize' );
		$steps[] = array(
			'label' => 'Loader - CX2 CRM scope bridge',
			'status' => $cx2_loaded ? 'pass' : 'skip',
			'detail' => $cx2_loaded ? 'The CRM scope bridge is loaded through the Context Bank bootstrap.' : 'The Context Bank CRM scope bridge is not loaded in this diagnostics context.',
		);
		if ( ! $cx2_disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'CX2 CRM to Context Bank bridge is incomplete.', 'fix_hint' => 'Restore the guarded bridge and preserve exact CRM grant/account scope before admission.', 'steps' => $steps );
		}

		$contact_projection_status = 'skip';
		$contact_projection_detail = 'CRM Contacts projection runtime is deferred because the CRM scope owner is not loaded or no current user fixture exists.';
		$contact_projection_ok = false;
		if ( $loaded && $user_id > 0 && method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_user_contact_projection' ) ) {
			$c_projection = BizCity_CRM_Inbox_Access::resolve_user_contact_projection( $user_id, 'c', 10 );
			$b2_projection = BizCity_CRM_Inbox_Access::resolve_user_contact_projection( $user_id, 'b2', 10 );
			$contact_projection_ok = is_array( $c_projection ) && is_array( $b2_projection )
				&& (string) ( $c_projection['surface'] ?? '' ) === 'C_PUBLIC_TWINGPT'
				&& (string) ( $b2_projection['surface'] ?? '' ) === 'B2_ADMIN_CRM'
				&& is_array( $c_projection['contacts'] ?? null )
				&& is_array( $b2_projection['contacts'] ?? null );
			$redacted = true;
			foreach ( array( $c_projection, $b2_projection ) as $projection ) {
				foreach ( (array) ( $projection['contacts'] ?? array() ) as $contact ) {
					foreach ( (array) ( $contact['source_memberships'] ?? array() ) as $membership ) {
						foreach ( array( 'source_id', 'channel_ref_id', 'phone', 'provider_uid', 'provider_user_id' ) as $forbidden ) {
							if ( array_key_exists( $forbidden, $membership ) ) { $redacted = false; }
						}
						if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $membership['membership_key'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{32}$/', (string) ( $membership['provider_contact_key'] ?? '' ) ) ) {
							$redacted = false;
						}
					}
				}
			}
			$contact_projection_ok = $contact_projection_ok && $redacted;
			$contact_projection_status = $contact_projection_ok ? 'pass' : 'fail';
			$contact_projection_detail = $contact_projection_ok
				? 'B2/C share the CRM-owned Contacts DTO; source memberships are bounded and provider identifiers are redacted.'
				: 'B2/C Contacts projection or source-membership redaction invariant failed.';
		}
		$steps[] = array(
			'label' => 'Runtime - CX1 unified Contacts source memberships',
			'status' => $contact_projection_status,
			'detail' => $contact_projection_detail,
		);

		$canary_status = 'skip';
		$canary_detail = 'Two-user/foreign-Personal canary is deferred because the diagnostics tenant has no current and foreign Personal fixtures.';
		$canary_ok = false;
		if ( $loaded && $user_id > 0 && $cx2_loaded ) {
			$foreign_user_id = 0;
			if ( function_exists( 'get_users' ) ) {
				$foreign_users = get_users( array( 'exclude' => array( $user_id ), 'role__not_in' => array( 'subscriber' ), 'number' => 1, 'fields' => array( 'ID' ) ) );
				$foreign_user_id = ! empty( $foreign_users[0]->ID ) ? (int) $foreign_users[0]->ID : 0;
			}
			$current_scope = BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $user_id, 'c' );
			$foreign_scope = $foreign_user_id > 0 ? BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $foreign_user_id, 'c' ) : array();
			$foreign_b2_scope = $foreign_user_id > 0 ? BizCity_CRM_Inbox_Access::resolve_user_inbox_scope( $foreign_user_id, 'b2' ) : array();
			$current_personal = self::first_scope_item( $current_scope, 'zalo_personal' );
			$foreign_personal = self::first_scope_item( $foreign_scope, 'zalo_personal' );
			if ( ! empty( $current_personal ) && ! empty( $foreign_personal ) && ! empty( $foreign_b2_scope ) ) {
				$current_result = BizCity_Context_Bank_CRM_Scope_Adapter::authorize( $current_scope, array( 'channel' => 'zalo_personal', 'inbox_id' => self::inbox_id_from_scope_item( $current_personal ) ) );
				$foreign_result = BizCity_Context_Bank_CRM_Scope_Adapter::authorize( $current_scope, array( 'channel' => 'zalo_personal', 'inbox_id' => self::inbox_id_from_scope_item( $foreign_personal ) ) );
				$selected_result = BizCity_Context_Bank_CRM_Scope_Adapter::authorize( $foreign_b2_scope, array( 'channel' => 'zalo_personal', 'inbox_id' => self::inbox_id_from_scope_item( $foreign_personal ) ) );
				$current_ok = ! empty( $current_result['ok'] );
				$foreign_denied = empty( $foreign_result['ok'] ) && in_array( (string) ( $foreign_result['reason'] ?? '' ), array( 'crm_scope_account_denied', 'crm_scope_resolver_denied', 'crm_scope_account_key_mismatch' ), true );
				$selected_ok = ! empty( $selected_result['ok'] );
				$canary_ok = $current_ok && $foreign_denied && $selected_ok;
				$canary_status = $canary_ok ? 'pass' : 'fail';
				$canary_detail = $canary_ok
					? 'User A can authorize A Personal, A is denied B Personal, and an admin operator can authorize selected User B through the B2 boundary.'
					: 'User A authorization, foreign Personal denial or selected User B B2 authorization failed.';
			}
		}
		$steps[] = array(
			'label' => 'Runtime - two-user foreign Personal Context Bank canary',
			'status' => $canary_status,
			'detail' => $canary_detail,
		);

		$archive_canary = self::run_archive_scope_canary( $c_scope, $user_id );
		$steps[] = array( 'label' => 'Runtime - CRM scope to archive receipt/pointer admission', 'status' => $archive_canary['status'], 'detail' => $archive_canary['detail'] );

		foreach ( $steps as $step ) {
			$ctx->emit_step( $step );
		}

		if ( ! $disk_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Canonical CRM user-inbox-scope owner is incomplete.', 'fix_hint' => 'Restore the CRM-owned user-inbox-scope envelope and normalized B2/C forwarding.', 'steps' => $steps );
		}
		if ( $runtime_status === 'fail' ) {
			return array( 'status' => 'fail', 'summary' => 'CRM B2/C user-inbox-scope runtime invariants failed.', 'fix_hint' => 'Verify one principal, contract version and distinct B2/C surface policy keys.', 'steps' => $steps );
		}
		if ( $contact_projection_status === 'fail' ) {
			return array( 'status' => 'fail', 'summary' => 'CRM CX1 Contacts source-membership projection failed.', 'fix_hint' => 'Keep B2/C Contacts on the CRM-owned Inbox scope and redact provider identifiers before returning source_memberships.', 'steps' => $steps );
		}
		if ( $canary_status === 'fail' ) {
			return array( 'status' => 'fail', 'summary' => 'Two-user foreign Personal Context Bank canary failed.', 'fix_hint' => 'Verify exact Zalo Personal ownership before Context Bank account admission; never widen access from a contact membership row.', 'steps' => $steps );
		}
		if ( $runtime_status === 'skip' ) {
			return array( 'status' => 'skip', 'summary' => 'CRM user-inbox-scope catalog passed; authenticated B2/C runtime evidence is deferred.', 'error' => 'crm_scope_runtime_deferred', 'fix_hint' => 'Run this probe with CRM loaded and an authenticated user fixture.', 'steps' => $steps );
		}
		return array( 'status' => 'pass', 'summary' => 'CRM B2/C scope and CX1 projection runtime passed; CX2 Context Bank admission/canary remains explicitly pending.', 'steps' => $steps );
	}

	private static function first_scope_item( array $scope, string $channel ): array {
		foreach ( (array) ( $scope['branches']['customer'] ?? array() ) as $item ) {
			if ( is_array( $item ) && sanitize_key( (string) ( $item['channel'] ?? '' ) ) === $channel ) {
				return $item;
			}
		}
		return array();
	}

	private static function inbox_id_from_scope_item( array $item ): int {
		$scope_id = (string) ( $item['scope_id'] ?? '' );
		return preg_match( '/^inbox_(\d+)$/', $scope_id, $matches ) ? (int) $matches[1] : 0;
	}

	private static function run_archive_scope_canary( array $scope, int $user_id ): array {
		// [2026-09-08 04:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX2 — exercise one real CRM scope through the canonical archive receipt and Context Bank pointer owners when a supported Inbox fixture exists.
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter' ) || ! class_exists( 'BizCity_CRM_Repository' ) || ! class_exists( 'BizCity_Channel_Conversation_Archive' ) ) {
			return array( 'status' => 'skip', 'detail' => 'CRM scope/archive owners are not loaded for the admission canary.' );
		}
		$target = array();
		foreach ( (array) ( $scope['branches']['customer'] ?? array() ) as $item ) {
			if ( is_array( $item ) && in_array( sanitize_key( (string) ( $item['channel'] ?? '' ) ), array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' ), true ) ) {
				$target = $item;
				break;
			}
		}
		$inbox_id = self::inbox_id_from_scope_item( $target );
		$channel = sanitize_key( (string) ( $target['channel'] ?? '' ) );
		$inbox = $inbox_id > 0 ? BizCity_CRM_Repository::get_inbox( $inbox_id ) : null;
		if ( ! is_array( $target ) || $inbox_id <= 0 || ! is_array( $inbox ) || $channel === '' ) {
			return array( 'status' => 'skip', 'detail' => 'No supported CRM customer Inbox fixture is available for a disposable archive receipt.' );
		}
		$archive_class = 'BizCity_Channel_Conversation_Archive';
		$reflection = new ReflectionClass( $archive_class );
		foreach ( array( 'archive_key', 'hash_identifier', 'append_with_receipt' ) as $method_name ) {
			if ( ! $reflection->hasMethod( $method_name ) ) { return array( 'status' => 'skip', 'detail' => 'Canonical archive receipt API is unavailable for the disposable scope canary.' ); }
		}
		$key_method = $reflection->getMethod( 'archive_key' );
		$hash_method = $reflection->getMethod( 'hash_identifier' );
		$append_method = $reflection->getMethod( 'append_with_receipt' );
		$key_method->setAccessible( true );
		$hash_method->setAccessible( true );
		$append_method->setAccessible( true );
		$archive_key = (string) $key_method->invoke( null );
		if ( $archive_key === '' ) { return array( 'status' => 'skip', 'detail' => 'Canonical archive key is unavailable; no disposable receipt was written.' ); }
		$account_ref = trim( (string) ( $inbox['channel_ref_id'] ?? '' ) );
		if ( $account_ref === '' ) { return array( 'status' => 'skip', 'detail' => 'CRM Inbox has no account reference for the disposable receipt canary.' ); }
		$peer_uid = 'cx2_scope_peer_' . wp_rand( 1000, 9999 );
		$record_id = 'cx2_scope_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$event_uuid = wp_generate_uuid4();
		$conversation_id = 920000000 + wp_rand( 1000, 9999 );
		$entry = array(
			'schema_version' => 1,
			'event_type' => 'message',
			'event_uuid' => $event_uuid,
			'trace_id' => 'trace_' . $record_id,
			'blog_id' => (int) get_current_blog_id(),
			'channel' => $channel,
			'platform' => strtoupper( $channel ),
			'account_key' => 'a_' . $hash_method->invoke( null, $account_ref, $archive_key ),
			'grant_account_key' => class_exists( 'BizCity_Channel_User_Grant' ) ? BizCity_Channel_User_Grant::account_key( $channel, $account_ref, (int) get_current_blog_id() ) : '',
			'peer_key' => 'p_' . $hash_method->invoke( null, $peer_uid, $archive_key ),
			'conversation_id' => $conversation_id,
			'inbox_id' => $inbox_id,
			'crm_message_id' => 920000001,
			'provider_message_id_hash' => $hash_method->invoke( null, 'cx2-scope-message', $archive_key ),
			'direction' => 'inbound',
			'actor_type' => 'customer',
			'actor_user_id' => 0,
			'content_ciphertext' => 'cx2-scope-ciphertext-only',
			'attachment_refs' => array(),
			'delivery_status' => 'received',
			'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			'record_id' => $record_id,
		);
		$previous_flag = get_option( 'bizcity_context_bank_channel_capture_enabled', '__cx2_flag_missing__' );
		$receipt = array();
		try {
			update_option( 'bizcity_context_bank_channel_capture_enabled', true, false );
			$receipt = $append_method->invoke( null, $entry, $channel, $account_ref, $peer_uid );
			$admission = is_array( $receipt ) ? BizCity_Context_Bank_CRM_Scope_Adapter::admit_archive_receipt( $scope, $entry, $receipt ) : array();
			$admission_ok = is_array( $admission ) && ! empty( $admission['ok'] ) && ! empty( $admission['projected'] );
			$cleanup_ok = false;
			if ( $admission_ok && class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
				$tombstone_entry = $entry;
				$tombstone_entry['event_type'] = 'delete';
				$tombstone_entry['event_uuid'] = wp_generate_uuid4();
				$tombstone_entry['operation'] = 'delete';
				$tombstone_entry['content_ciphertext'] = '';
				$tombstone_receipt = $append_method->invoke( null, $tombstone_entry, $channel, $account_ref, $peer_uid );
				$tombstone = is_array( $tombstone_receipt ) ? BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => $tombstone_entry, 'receipt' => $tombstone_receipt ) ) : array();
				if ( is_array( $tombstone ) && ! empty( $tombstone['ok'] ) && ! empty( $tombstone['tombstone'] ) ) {
					$cleanup = BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer(
						array_merge( $tombstone_entry, $tombstone_receipt, array(
							'source_contract_id' => BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID,
							'record_id' => (string) ( $receipt['record_id'] ?? '' ),
							'operation' => 'delete',
							'lifecycle_status' => 'deleted',
						) ),
						'cx2_scope_canary_cleanup'
					);
					$cleanup_ok = is_array( $cleanup ) && ! empty( $cleanup['ok'] );
				}
			}
			$ok = $admission_ok && $cleanup_ok;
			$detail = $ok
				? 'CRM scope produced a receipt, admitted a pointer-only Context Bank record, then tombstoned and removed the derived pointer.'
				: ( $admission_ok ? 'CRM scope admission passed but disposable tombstone/pointer cleanup did not complete.' : 'CRM scope receipt admission failed: ' . (string) ( $admission['reason'] ?? 'unknown' ) );
			return array( 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		} catch ( Throwable $e ) {
			return array( 'status' => 'fail', 'detail' => 'Disposable CRM scope archive canary threw: ' . sanitize_key( $e->getMessage() ) );
		} finally {
			if ( $previous_flag === '__cx2_flag_missing__' ) { delete_option( 'bizcity_context_bank_channel_capture_enabled' ); } else { update_option( 'bizcity_context_bank_channel_capture_enabled', $previous_flag, false ); }
			if ( method_exists( $archive_class, 'erase_conversation' ) ) { $archive_class::erase_conversation( $channel, $account_ref, $peer_uid, $conversation_id, array( $archive_class, 'rest_authorize_tenant_admin' ) ); }
		}
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_User_Inbox_Scope';
	return $probes;
} );
