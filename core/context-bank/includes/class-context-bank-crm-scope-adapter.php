<?php
/**
 * Bridge the CRM user-inbox-scope envelope into Context Bank admission.
 *
 * CRM remains the owner of contacts/conversations and Context Bank remains the
 * owner of pointer admission. This class only validates the boundary between
 * those two contracts.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_CRM_Scope_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_CRM_Scope_Adapter {

	const CONTRACT_ID = 'core.context_bank.crm_scope_bridge';
	const VERSION = '1.0.0';

	/**
	 * Validate one CRM scope envelope against one exact customer Inbox.
	 *
	 * @param array $user_scope CRM-owned user-inbox-scope@1.0.0 envelope.
	 * @param array $request_context Exact channel/inbox request context.
	 * @return array
	 */
	public static function authorize( array $user_scope, array $request_context = array() ) {
		// [2026-09-08 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX2 — consume the CRM scope envelope before Context Bank access or pointer admission.
		$contract = (string) ( $user_scope['contract'] ?? '' );
		$version = (string) ( $user_scope['version'] ?? '' );
		$surface = (string) ( $user_scope['surface'] ?? '' );
		$principal = is_array( $user_scope['principal'] ?? null ) ? $user_scope['principal'] : array();
		$blog_id = (int) ( $principal['blog_id'] ?? 0 );
		$user_id = (int) ( $principal['user_id'] ?? 0 );
		if ( 'user-inbox-scope' !== $contract || self::VERSION !== $version || ! in_array( $surface, array( 'B2_ADMIN_CRM', 'C_PUBLIC_TWINGPT' ), true ) || $blog_id <= 0 || $user_id <= 0 ) {
			return self::deny( 'crm_scope_contract_invalid' );
		}
		if ( $blog_id !== (int) get_current_blog_id() ) {
			return self::deny( 'crm_scope_tenant_mismatch' );
		}
		if ( 'C_PUBLIC_TWINGPT' === $surface && $user_id !== (int) get_current_user_id() ) {
			return self::deny( 'crm_scope_principal_mismatch' );
		}
		if ( 'B2_ADMIN_CRM' === $surface && $user_id !== (int) get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return self::deny( 'crm_scope_operator_mismatch' );
		}

		$channel = sanitize_key( (string) ( $request_context['channel'] ?? $request_context['platform'] ?? '' ) );
		$inbox_id = absint( $request_context['inbox_id'] ?? 0 );
		if ( ! in_array( $channel, array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'email', 'instagram', 'whatsapp' ), true ) || $inbox_id <= 0 ) {
			return self::deny( 'crm_scope_channel_context_invalid' );
		}
		$scope_item = self::find_customer_scope_item( $user_scope, $inbox_id, $channel );
		if ( empty( $scope_item ) ) {
			return self::deny( 'crm_scope_account_denied' );
		}
		if ( 'zalo_personal' === $channel && 'owner_only' !== (string) ( $scope_item['access_mode'] ?? '' ) ) {
			return self::deny( 'crm_scope_personal_owner_required' );
		}
		if ( ! class_exists( 'BizCity_CRM_Inbox_Access' ) || ! method_exists( 'BizCity_CRM_Inbox_Access', 'resolve_scope' ) ) {
			return self::deny( 'crm_scope_owner_unavailable' );
		}
		$scope_key = 'B2_ADMIN_CRM' === $surface ? 'b2' : 'c';
		$resolved = BizCity_CRM_Inbox_Access::resolve_scope( $user_id, $scope_key, 'b2' === $scope_key );
		$resolved_ids = $resolved['inbox_ids'] ?? array();
		if ( is_array( $resolved_ids ) && ! in_array( $inbox_id, array_map( 'intval', $resolved_ids ), true ) ) {
			return self::deny( 'crm_scope_resolver_denied' );
		}

		$inbox = class_exists( 'BizCity_CRM_Repository' ) && method_exists( 'BizCity_CRM_Repository', 'get_inbox' )
			? BizCity_CRM_Repository::get_inbox( $inbox_id )
			: null;
		if ( ! is_array( $inbox ) || sanitize_key( (string) ( $inbox['channel_type'] ?? '' ) ) !== $channel || (int) get_current_blog_id() <= 0 ) {
			return self::deny( 'crm_scope_inbox_not_found' );
		}
		$account_ref = trim( (string) ( $inbox['channel_ref_id'] ?? '' ) );
		$public_account_key = substr( hash_hmac( 'sha256', $channel . '|' . $account_ref, wp_salt( 'auth' ) ), 0, 32 );
		if ( $account_ref === '' || ! hash_equals( strtolower( $public_account_key ), strtolower( (string) ( $scope_item['account_key'] ?? '' ) ) ) ) {
			return self::deny( 'crm_scope_account_key_mismatch' );
		}
		if ( ! class_exists( 'BizCity_Channel_User_Grant' ) || ! method_exists( 'BizCity_Channel_User_Grant', 'account_key' ) ) {
			return self::deny( 'crm_scope_grant_owner_unavailable' );
		}
		$grant_account_key = BizCity_Channel_User_Grant::account_key( $channel, $account_ref, (int) get_current_blog_id() );
		if ( ! preg_match( '/^a_[a-f0-9]{64}$/i', (string) $grant_account_key ) ) {
			return self::deny( 'crm_scope_grant_key_invalid' );
		}
		return array(
			'ok' => true,
			'contract' => self::CONTRACT_ID,
			'version' => self::VERSION,
			'surface' => $surface,
			'principal' => array( 'blog_id' => $blog_id, 'user_id' => $user_id ),
			'inbox_id' => $inbox_id,
			'channel' => $channel,
			'account_key' => (string) ( $scope_item['account_key'] ?? '' ),
			'grant_account_key' => $grant_account_key,
			'context_request' => array(
				'channel' => $channel,
				'mode' => sanitize_key( (string) ( $request_context['mode'] ?? 'recent_identity' ) ),
				'chat_kind' => sanitize_key( (string) ( $request_context['chat_kind'] ?? 'direct' ) ),
			),
		);
	}

	/**
	 * Admit an already-written CRM archive receipt through the existing CB4.2 owner.
	 *
	 * @param array $user_scope CRM-owned scope envelope.
	 * @param array $entry Verified archive entry metadata.
	 * @param array $receipt Verified archive receipt.
	 * @return array
	 */
	public static function admit_archive_receipt( array $user_scope, array $entry, array $receipt ) {
		// [2026-09-08 03:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-CX2 — preserve CRM principal/account scope before delegating pointer admission to Context Bank.
		$authorized = self::authorize( $user_scope, array(
			'channel' => $entry['channel'] ?? '',
			'inbox_id' => $entry['inbox_id'] ?? 0,
			'mode' => 'recent_identity',
			'chat_kind' => $entry['chat_kind'] ?? 'direct',
		) );
		if ( empty( $authorized['ok'] ) ) { return $authorized; }
		if ( (string) ( $entry['grant_account_key'] ?? $receipt['grant_account_key'] ?? '' ) !== (string) $authorized['grant_account_key'] ) {
			return self::deny( 'crm_scope_grant_key_mismatch' );
		}
		if ( ! self::load_context_bank_runtime() ) {
			return self::deny( 'context_bank_runtime_unavailable' );
		}
		$context = class_exists( 'BizCity_Context_Bank_Scope_Resolver' )
			? BizCity_Context_Bank_Scope_Resolver::resolve( $authorized['context_request'] )
			: array();
		if ( empty( $context['ok'] ) || 'skip' === (string) ( $context['effective_mode'] ?? '' ) || ! in_array( BizCity_Context_Bank_Channel_Archive_Adapter::CONTRACT_ID, (array) ( $context['allowed_contracts'] ?? array() ), true ) ) {
			return self::deny( 'context_bank_scope_denied' );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' ) ) {
			return self::deny( 'context_bank_archive_adapter_unavailable' );
		}
		$result = BizCity_Context_Bank_Channel_Archive_Adapter::project( array( 'entry' => $entry, 'receipt' => $receipt ) );
		if ( ! is_array( $result ) ) { return self::deny( 'context_bank_admission_invalid' ); }
		$result['crm_scope'] = array( 'contract' => self::CONTRACT_ID, 'surface' => $authorized['surface'], 'user_id' => (int) $authorized['principal']['user_id'], 'inbox_id' => (int) $authorized['inbox_id'], 'channel' => $authorized['channel'] );
		return $result;
	}

	private static function find_customer_scope_item( array $user_scope, int $inbox_id, string $channel ) {
		$scope_id = 'inbox_' . $inbox_id;
		foreach ( (array) ( $user_scope['branches']['customer'] ?? array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['scope_id'] ?? '' ) === $scope_id && sanitize_key( (string) ( $item['channel'] ?? '' ) ) === $channel ) {
				return $item;
			}
		}
		return array();
	}

	private static function load_context_bank_runtime(): bool {
		if ( class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) && class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' ) ) { return true; }
		$bootstrap = dirname( __DIR__ ) . '/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) { return false; }
		try {
			BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.crm_scope_adapter' );
		} catch ( \Throwable $e ) {
			return false;
		}
		return class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) && class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' );
	}

	private static function deny( $reason ) {
		return array( 'ok' => false, 'projected' => false, 'contract' => self::CONTRACT_ID, 'reason' => sanitize_key( (string) $reason ) );
	}
}
