<?php
/**
 * Structural and deterministic DDV for the unified TwinBrain channel boundary.
 *
 * This probe does not call an LLM or write event/memory data. Real channel
 * fixtures are added only after each adapter has a production-safe harness.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinBrain_Channel_Unify', false ) ) {
	return;
}

final class BizCity_Probe_TwinBrain_Channel_Unify implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'twinbrain.channel_unify'; }
	public function label(): string { return 'TwinBrain Unified Channel Boundary'; }
	public function description(): string { return 'Checks the shared channel adapter, dual-owner Brain session resolver, Goal Loop continuity APIs, and deterministic guest/user-bound guards.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 66; }
	public function icon(): string { return 'git-merge'; }
	public function estimate_ms(): int { return 30; }

	public function precondition() {
		foreach ( array( 'BizCity_TwinBrain_Channel_Adapter', 'BizCity_TwinBrain_Adapter_Messenger', 'BizCity_TwinBrain_Adapter_ZaloOA', 'BizCity_TwinBrain_Adapter_WebChat', 'BizCity_TwinBrain_Adapter_ZaloBot', 'BizCity_TwinBrain_Adapter_Telegram', 'BizCity_TwinBrain_Brain_Session_Resolver', 'BizCity_TwinBrain_Sessions_Manager', 'BizCity_TwinBrain_Goal_Loop_Repository', 'BizCity_TwinBrain_Goal_Loop_State' ) as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'class_missing', $class . ' chưa load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — deterministic DDV for channel convergence and dual identity ownership.
		$steps = array();
		$ok = true;
		$root = defined( 'BIZCITY_TWINBRAIN_DIR' ) ? (string) BIZCITY_TWINBRAIN_DIR : '';
		$adapter_file = $root . 'includes/class-twinbrain-channel-adapter.php';
		$resolver_file = $root . 'includes/class-twinbrain-brain-session-resolver.php';
		$disk_ok = is_readable( $adapter_file ) && is_readable( $resolver_file );
		$ok = $this->step( $ctx, $steps, 'Disk: shared adapter and session resolver', $disk_ok, $adapter_file . ' | ' . $resolver_file ) && $ok;

		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — guard repository event metadata against being dropped by canonical state normalization.
		$repository_file = $root . 'includes/class-twinbrain-goal-loop-repository.php';
		$repository_source = is_readable( $repository_file ) ? (string) file_get_contents( $repository_file ) : '';
		$latest_normalize_pos = strpos( $repository_source, '? BizCity_TwinBrain_Goal_Loop_State::normalize( $state )' );
		$latest_marker_pos = strpos( $repository_source, "\$latest['_event_type']" );
		$active_normalize_pos = strpos( $repository_source, '$normalized = BizCity_TwinBrain_Goal_Loop_State::normalize( $state );' );
		$active_marker_pos = strpos( $repository_source, "\$normalized['_event_type']" );
		$metadata_order_ok = is_readable( $repository_file )
			&& false !== $latest_normalize_pos
			&& false !== $latest_marker_pos
			&& $latest_marker_pos > $latest_normalize_pos
			&& false !== $active_normalize_pos
			&& false !== $active_marker_pos
			&& $active_marker_pos > $active_normalize_pos;
		$ok = $this->step( $ctx, $steps, 'Disk: Goal repository preserves event markers after normalize', $metadata_order_ok, $metadata_order_ok ? 'latest() and latest_active_by_identity() attach read-only event metadata after canonical normalization.' : $repository_file . ' has an invalid metadata/normalize order.' ) && $ok;

		$runtime_file = $root . 'includes/class-twinbrain-goal-loop-runtime.php';
		$runtime_source = is_readable( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$persistence_contract_ok = is_readable( $runtime_file )
			&& strpos( $runtime_source, 'BizCity_TwinBrain_Goal_Loop_Repository::progress' ) !== false
			&& strpos( $runtime_source, "'persisted' => \$event_uuid !== ''" ) !== false
			&& strpos( $runtime_source, "'event_uuid' => \$event_uuid" ) !== false
			&& strpos( $runtime_source, "'persistence_error' => \$event_uuid === '' ? 'event_write_rejected' : ''" ) !== false;
		$ok = $this->step( $ctx, $steps, 'Disk: Goal post-turn exposes event persistence outcome', $persistence_contract_ok, $persistence_contract_ok ? 'post_turn() reports persisted/event_uuid and a stable rejection bucket.' : $runtime_file . ' does not expose the Goal event persistence contract.' ) && $ok;

		$api_ok = method_exists( 'BizCity_TwinBrain_Channel_Adapter', 'handle' )
			&& method_exists( 'BizCity_TwinBrain_Channel_Adapter', 'normalize_envelope' )
			&& is_subclass_of( 'BizCity_TwinBrain_Adapter_Messenger', 'BizCity_TwinBrain_Channel_Adapter' )
			&& is_subclass_of( 'BizCity_TwinBrain_Adapter_ZaloOA', 'BizCity_TwinBrain_Channel_Adapter' )
			&& is_subclass_of( 'BizCity_TwinBrain_Adapter_WebChat', 'BizCity_TwinBrain_Channel_Adapter' )
			&& is_subclass_of( 'BizCity_TwinBrain_Adapter_ZaloBot', 'BizCity_TwinBrain_Channel_Adapter' )
			&& is_subclass_of( 'BizCity_TwinBrain_Adapter_Telegram', 'BizCity_TwinBrain_Channel_Adapter' )
			&& method_exists( 'BizCity_TwinBrain_Brain_Session_Resolver', 'resolve' )
			&& method_exists( 'BizCity_TwinBrain_Brain_Session_Resolver', 'build_opts' )
			&& method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'latest_active_by_identity' );
		$ok = $this->step( $ctx, $steps, 'Loader: unified channel/session/goal APIs', $api_ok, 'Adapter, resolver, and cross-session Goal Loop API are available.' ) && $ok;

		$adapter = new BizCity_TwinBrain_Channel_Adapter();
		$guest = $adapter->normalize_envelope( array(
			'platform' => 'FB_MESS',
			'account_id' => 'page-test',
			'external_user_id' => 'psid-test',
			'chat_id' => 'fb_page-test_psid-test',
			'text' => 'Tôi muốn kiểm tra tích điểm',
			'wp_user_id' => 0,
			'channel_class' => 'guest_channel',
			'is_group' => false,
		) );
		$guest_session = BizCity_TwinBrain_Brain_Session_Resolver::resolve( array(
			'channel_class' => 'guest_channel',
			'identity_uuid' => 'identity-test',
			'chat_id' => $guest['chat_id'],
		) );
		$guest_ok = ( $guest['channel_class'] ?? '' ) === 'guest_channel'
			&& ! empty( $guest['identity_guest_bind'] )
			&& ! empty( $guest_session['ok'] )
			&& ( $guest_session['owner'] ?? '' ) === 'guest_identity';
		$ok = $this->step( $ctx, $steps, 'Runtime: guest fixture resolves by identity/chat', $guest_ok, $guest_ok ? 'guest_channel keeps wp_user_id=0 and uses a stable chat session.' : wp_json_encode( array( $guest, $guest_session ) ) ) && $ok;

		$messenger = ( new BizCity_TwinBrain_Adapter_Messenger() )->normalize_envelope( array( 'text' => 'Xin chào', 'chat_id' => 'fb_page_psid', 'is_group' => false ) );
		$zalo_oa = ( new BizCity_TwinBrain_Adapter_ZaloOA() )->normalize_envelope( array( 'text' => 'Xin chào', 'chat_id' => 'zalooa_oa_uid', 'is_group' => false ) );
		$webchat = ( new BizCity_TwinBrain_Adapter_WebChat() )->normalize_envelope( array( 'text' => 'Xin chào', 'chat_id' => 'webchat_session', 'is_group' => false ) );
		$guest_policy_ok = ( $messenger['channel_class'] ?? '' ) === 'guest_channel'
			&& ( $messenger['platform'] ?? '' ) === 'FB_MESS'
			&& ( $zalo_oa['channel_class'] ?? '' ) === 'guest_channel'
			&& ( $zalo_oa['platform'] ?? '' ) === 'ZALO_OA'
			&& ( $webchat['channel_class'] ?? '' ) === 'guest_channel'
			&& ( $webchat['platform'] ?? '' ) === 'WEBCHAT';
		$ok = $this->step( $ctx, $steps, 'Runtime: guest adapter policies', $guest_policy_ok, $guest_policy_ok ? 'Messenger, Zalo OA, and WebChat are guest_channel.' : wp_json_encode( array( $messenger, $zalo_oa, $webchat ) ) ) && $ok;

		$zalo_bot = ( new BizCity_TwinBrain_Adapter_ZaloBot() )->normalize_envelope( array( 'text' => 'Làm báo cáo', 'wp_user_id' => 42, 'chat_id' => 'zalobot_1_user-1' ) );
		$telegram = ( new BizCity_TwinBrain_Adapter_Telegram() )->normalize_envelope( array( 'text' => 'Làm báo cáo', 'wp_user_id' => 42, 'chat_id' => 'tg_1_user-1' ) );
		$user_policy_ok = ( $zalo_bot['channel_class'] ?? '' ) === 'user_bound'
			&& empty( $zalo_bot['identity_guest_bind'] )
			&& ( $telegram['channel_class'] ?? '' ) === 'user_bound'
			&& empty( $telegram['identity_guest_bind'] );
		$ok = $this->step( $ctx, $steps, 'Runtime: user-bound adapter policies', $user_policy_ok, $user_policy_ok ? 'Zalo Bot and Telegram require linked user identity.' : wp_json_encode( array( $zalo_bot, $telegram ) ) ) && $ok;

		$group_result = ( new BizCity_TwinBrain_Adapter_Messenger() )->handle( array( 'text' => 'Group message', 'chat_id' => 'group-1', 'is_group' => true ) );
		$group_guard_ok = empty( $group_result['ok'] ) && ( $group_result['error'] ?? '' ) === 'group_identity_not_supported';
		$ok = $this->step( $ctx, $steps, 'Runtime: group identity fails closed', $group_guard_ok, $group_guard_ok ? 'Group traffic never enters personal Brain subject resolution.' : wp_json_encode( $group_result ) ) && $ok;

		$user_unlinked = BizCity_TwinBrain_Brain_Session_Resolver::resolve( array(
			'channel_class' => 'user_bound',
			'identity_uuid' => 'identity-test-user',
			'chat_id' => 'zalobot_1_user-test',
			'wp_user_id' => 0,
		) );
		$user_guard_ok = empty( $user_unlinked['ok'] ) && ( $user_unlinked['error'] ?? '' ) === 'wp_user_id_required';
		$ok = $this->step( $ctx, $steps, 'Runtime: user-bound fixture fails closed without linker', $user_guard_ok, $user_guard_ok ? 'wp_user_id is required for user_bound.' : wp_json_encode( $user_unlinked ) ) && $ok;

		$stable_user_ok = false;
		$stable_method = new ReflectionMethod( 'BizCity_TwinBrain_Brain_Session_Resolver', 'stable_user_session_id' );
		$stable_method->setAccessible( true );
		$user_envelope = array( 'platform' => 'ZALO_BOT', 'account_id' => 'bot-test', 'external_user_id' => 'user-test', 'chat_id' => 'zalobot_bot-test_user-test' );
		$stable_a = (string) $stable_method->invoke( null, $user_envelope, 42 );
		$stable_b = (string) $stable_method->invoke( null, $user_envelope, 42 );
		$other_user = $user_envelope;
		$other_user['external_user_id'] = 'other-user-test';
		$stable_c = (string) $stable_method->invoke( null, $other_user, 42 );
		$stable_user_ok = $stable_a !== '' && $stable_a === $stable_b && $stable_a !== $stable_c && BizCity_TwinBrain_Sessions_Manager::is_valid_session_id( $stable_a );
		$ok = $this->step( $ctx, $steps, 'Runtime: user-bound channel session is stable', $stable_user_ok, $stable_user_ok ? 'Same linked channel identity reuses one valid Brain session id.' : wp_json_encode( array( $stable_a, $stable_b, $stable_c ) ) ) && $ok;

		$transition_guard_ok = ! BizCity_TwinBrain_Goal_Loop_State::can_transition( 'completed', 'executing', array() );
		$ok = $this->step( $ctx, $steps, 'Runtime: terminal Goal Loop transition is rejected', $transition_guard_ok, 'Completed goals cannot transition back to executing.' ) && $ok;

		return array(
			'ok' => $ok,
			'status' => $ok ? 'PASS' : 'FAIL',
			'steps' => $steps,
			'failures' => $ok ? array() : array( 'twinbrain_channel_unify_failed' ),
		);
	}

	private function step( $ctx, array &$steps, string $label, bool $pass, string $detail ): bool {
		$status = $pass ? 'PASS' : 'FAIL';
		$steps[] = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
		if ( is_object( $ctx ) && method_exists( $ctx, 'emit_step' ) ) {
			$ctx->emit_step( array( 'label' => $label, 'status' => $status, 'detail' => $detail ) );
		}
		return $pass;
	}

	public function cleanup(): void {}
}

// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — register the unified channel probe in the central Smoke Runner catalog.
add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinBrain_Channel_Unify';
	return $probes;
} );
