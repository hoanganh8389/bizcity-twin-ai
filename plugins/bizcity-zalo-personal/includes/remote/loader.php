<?php
/** Remote Zalo Hub optional loader — C0. */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader' ) || ! class_exists( 'BizCity_Remote_Zalo_Feature' ) ) { return; }

if ( ! BizCity_Remote_Zalo_Feature::enabled() ) { return; }

// [2026-09-29 12:00 PM GitHub Copilot] PHASE-0.82-C0 — load only remote artifacts behind the feature gate; legacy Zalo remains independent.
$files = array(
	'class-remote-zalo-credentials.php', 'class-remote-zalo-http.php', 'class-remote-zalo-host-policy.php',
	'class-remote-zalo-hub-client.php', 'class-remote-zalo-profile.php', 'class-remote-zalo-cursor-store.php',
	'class-remote-zalo-normalizer.php', 'class-remote-zalo-poller.php',
	// [2026-09-29 Claude Sonnet 5] PHASE-0.82 E3 — last "Lưu và kiểm tra" outcome, read by settings REST.
	'class-remote-zalo-last-check.php',
	// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS1 — one link owner shared by Bot Studio + CRM REST.
	'class-remote-zalo-link-service.php',
	// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS5/XS6 — Guru -> remote agent projection + sync owner.
	'class-remote-zalo-agent-projector.php', 'class-remote-zalo-agent-sync.php',
	// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B9 — throttled-send retry queue (must load before the transport enqueues into it).
	'class-remote-zalo-send-queue.php',
);
// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B8/E13 — the LC-2 transport port + capability descriptor live in core/,
// loaded independently of this list; only wire them up (filters) when the interface is actually present.
if ( interface_exists( 'BizCity_Zalo_Transport' ) ) {
	$files[] = 'class-remote-zalo-transport.php';
}
foreach ( $files as $file ) {
	$path = __DIR__ . '/' . $file;
	if ( ! BizCity_Safe_Loader::require_file( $path, 'zalo_personal.remote.' . basename( $file, '.php' ) ) ) {
		error_log( '[BizCity_Zalo_Remote] loader_failed ' . basename( $file ) );
		return;
	}
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	BizCity_Safe_Loader::require_file( __DIR__ . '/class-remote-zalo-cli.php', 'zalo_personal.remote.cli' );
}
// [2026-09-29 Claude Opus 5.5] PHASE-0.82 XS2 — WP-Cron poll (degraded path; system cron + WP-CLI tick recommended).
if ( class_exists( 'BizCity_Remote_Zalo_Poller' ) ) { BizCity_Remote_Zalo_Poller::boot(); }
// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B9 — piggyback the send-queue retry on the SAME 60s schedule (no second
// cron event); it is cheap when the queue is empty and independent of the poller's own lock/cursor.
if ( class_exists( 'BizCity_Remote_Zalo_Send_Queue' ) && class_exists( 'BizCity_Remote_Zalo_Poller' ) && function_exists( 'add_action' ) ) {
	add_action( BizCity_Remote_Zalo_Poller::CRON_HOOK, array( 'BizCity_Remote_Zalo_Send_Queue', 'tick' ) );
}
// [2026-09-30 Claude Sonnet 5] PHASE-0.82 XS6 follow-up — auto-push the Guru projection on link / binding / edit.
if ( class_exists( 'BizCity_Remote_Zalo_Agent_Sync' ) ) { BizCity_Remote_Zalo_Agent_Sync::boot(); }
BizCity_Safe_Loader::require_file( __DIR__ . '/rest/class-remote-zalo-settings-rest.php', 'zalo_personal.remote.settings_rest' );
if ( class_exists( 'BizCity_Remote_Zalo_Settings_REST' ) ) { BizCity_Remote_Zalo_Settings_REST::init(); }
BizCity_Safe_Loader::require_file( __DIR__ . '/rest/class-remote-zalo-accounts-rest.php', 'zalo_personal.remote.accounts_rest' );
if ( class_exists( 'BizCity_Remote_Zalo_Accounts_REST' ) ) { BizCity_Remote_Zalo_Accounts_REST::init(); }
// [2026-09-30 Claude Sonnet 5] PHASE-0.82 B8/E13 — register the transport + its capability descriptor. This is the
// ONLY hook-up needed for CRM sends to reach Remote Zalo Hub: BizCity_Zalo_Transport_Registry::for_account()
// already resolves by BizCity_Zalo_Account_Flags provider, and A5 already routes any non-legacy transport generically.
if ( class_exists( 'BizCity_Remote_Zalo_Transport' ) && function_exists( 'add_filter' ) ) {
	add_filter( 'bizcity_zalo_transports', static function ( $transports ) {
		$transports = is_array( $transports ) ? $transports : array();
		$transports['remote_zalo_hub'] = new BizCity_Remote_Zalo_Transport();
		return $transports;
	} );
	add_filter( 'bizcity_zalo_transport_capabilities', static function ( $descriptors ) {
		$descriptors = is_array( $descriptors ) ? $descriptors : array();
		// 04 §6.5 — every row is a ruled owner decision (2026-09-29), not a guess: no new thread (row 1), no
		// image/file send (row 5), no quote-reply (row 6), owner's phone-app sends never arrive (row 7).
		$descriptors['remote_zalo_hub'] = array(
			'label'        => 'Remote Zalo Hub API',
			'capabilities' => array(
				'can_initiate_thread'         => false,
				'can_send_text'               => true,
				'can_send_image'              => false,
				'can_send_file'               => false,
				'can_quote_reply'             => false,
				'delivers_owner_app_messages' => false,
				'guru_projection'             => true,
				'config_sync_check'           => false,
				'group_actions'               => false,
			),
			'hints'        => array(
				'can_initiate_thread'         => 'Remote Zalo Hub chỉ trả lời được khách đã nhắn trước — API bên thứ ba không mở được hội thoại mới.',
				'can_send_image'               => 'Remote Zalo Hub chưa gửi được ảnh trong phiên bản API hiện tại.',
				'can_send_file'                => 'Remote Zalo Hub chưa gửi được tệp trong phiên bản API hiện tại.',
				'can_quote_reply'              => 'Remote Zalo Hub chưa hỗ trợ trả lời trích dẫn.',
				'delivers_owner_app_messages'  => 'Tin chủ nick tự nhắn trên app Zalo điện thoại không về được CRM qua Remote Zalo Hub.',
			),
			'limits'       => array( 'messages_per_minute' => 20 ),
		);
		return $descriptors;
	} );
}
unset( $files, $file, $path );
