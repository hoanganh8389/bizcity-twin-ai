<?php
/**
 * Probe: Channel Identity · Memory Ownership (PHASE-0.52 W6).
 *
 * Verifies the three identity/memory boundaries required by W6:
 *   (a) FB identity link issuance and canonical resolution;
 *   (b) subject resolution stays actor-scoped unless explicitly authorized;
 *   (c) unlinked Zalo Bot memory writes fail closed with a no-owner signal.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      PHASE-0.52 W6
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Probe_Channel_Identity_Memory' ) ) { return; }

// [2026-07-27 Johnny Chu] PHASE-0.52 W6 — DDV probe for channel identity and memory ownership.
final class BizCity_Probe_Channel_Identity_Memory implements BizCity_Diagnostics_Probe {

	private $fb_external = '';
	private $fb_account  = '';
	private $memory_keys = array();
	private $identity_bindings = array();
	private $identity_probe_uuids = array();
	private $identity_merge_event = array();

	public function id(): string          { return 'core.channel.identity_memory'; }
	public function label(): string       { return 'Channel · Identity and Memory Ownership'; }
	public function description(): string { return 'PHASE-0.52 W6: linked Facebook identity, authorized subject resolution, and fail-closed unlinked Zalo memory.'; }
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 44; }
	public function icon(): string        { return 'fingerprint'; }
	public function estimate_ms(): int    { return 500; }

	public function precondition() {
		$required = array(
			'BizCity_Channel_User_Linker',
			'BizCity_CRM_Magic_Link',
			'BizCity_TwinBrain_Runtime',
			'BizCity_TwinBrain_Memory_Writer',
			'BizCity_TwinBrain_Memory_Recall',
			'BizCity_User_Memory',
				'BizCity_Identity_Hub',
		);
		$missing = array();
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'identity_memory_probe_missing', 'Missing runtime classes: ' . implode( ', ', $missing ) );
		}
		// [2026-07-28 Johnny Chu] PHASE-0.52 W6 — linking must bind to the authenticated probe actor; never use an admin or anonymous fallback.
		if ( (int) get_current_user_id() <= 0 ) {
			return new WP_Error( 'identity_memory_probe_auth_required', 'Run this runtime probe while authenticated as a WordPress user.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$rows = array();
		$pass = true;

		$plugin_root = dirname( __DIR__, 3 );
		$listener_file = $plugin_root . '/channel-gateway/includes/class-universal-channel-listener.php';
		$linker_file   = $plugin_root . '/channel-gateway/includes/class-channel-user-linker.php';
		$writer_file   = $plugin_root . '/twinbrain/includes/class-twinbrain-memory-writer.php';
		$runtime_file  = $plugin_root . '/twinbrain/includes/class-twinbrain-runtime.php';
		$identity_file = $plugin_root . '/channel-gateway/includes/class-identity-hub.php';
		$resolver_file = $plugin_root . '/channel-gateway/includes/class-user-resolver.php';
		$gateway_bootstrap_file = $plugin_root . '/channel-gateway/bootstrap.php';
		$installer_file = $plugin_root . '/diagnostics/includes/installer-registry.php';

		// LAYER 1: DISK.
		$disk_files = array( $listener_file, $linker_file, $writer_file, $runtime_file, $identity_file, $resolver_file, $gateway_bootstrap_file, $installer_file );
		$missing_files = array();
		foreach ( $disk_files as $file ) {
			if ( ! file_exists( $file ) ) {
				$missing_files[] = basename( $file );
			}
		}
		$disk_ok = empty( $missing_files );
		if ( ! $disk_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Disk: identity hub, linker, listener, runtime, and writer exist',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'All four W6 ownership surfaces are present.' : implode( ', ', $missing_files ) . ' missing.',
		);

		// LAYER 2: LOADER/static contract.
		$listener_src = file_exists( $listener_file ) ? (string) file_get_contents( $listener_file ) : '';
		$linker_src   = file_exists( $linker_file ) ? (string) file_get_contents( $linker_file ) : '';
		$writer_src   = file_exists( $writer_file ) ? (string) file_get_contents( $writer_file ) : '';
		$runtime_src  = file_exists( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';
		$identity_src = file_exists( $identity_file ) ? (string) file_get_contents( $identity_file ) : '';
		$resolver_src = file_exists( $resolver_file ) ? (string) file_get_contents( $resolver_file ) : '';
		$gateway_bootstrap_src = file_exists( $gateway_bootstrap_file ) ? (string) file_get_contents( $gateway_bootstrap_file ) : '';
		$installer_src = file_exists( $installer_file ) ? (string) file_get_contents( $installer_file ) : '';
		$loader_checks = array(
			'Durable identity hub resolves UUIDs' => strpos( $identity_src, 'resolve_from_opts' ) !== false && strpos( $identity_src, 'identity_uuid' ) !== false,
			'Compatibility resolver exposes identity context' => strpos( $resolver_src, 'resolve_identity' ) !== false && strpos( $resolver_src, 'identity_state' ) !== false,
			'Site Provisioner registers tenant identity installers' => strpos( $installer_src, "'channel_identity_hub'" ) !== false && strpos( $installer_src, "'channel_user_linker'" ) !== false,
			'Identity tables use Schema Registry' => strpos( $gateway_bootstrap_src, 'bizcity_identity_contacts' ) !== false && strpos( $gateway_bootstrap_src, 'bizcity_identity_bindings' ) !== false && strpos( $gateway_bootstrap_src, 'BizCity_Schema_Registry::register' ) !== false,
			'Identity merge has operator guard and Context Bank consumer' => strpos( $identity_src, 'identity_merge_forbidden' ) !== false && strpos( $identity_src, 'bizcity_identity_merged' ) !== false && class_exists( 'BizCity_Context_Bank_Identity_Merge_Adapter' ) && false !== has_action( 'bizcity_identity_merged', array( 'BizCity_Context_Bank_Identity_Merge_Adapter', 'on_merged' ) ),
			'Identity installers use tenant prefix and options' => strpos( $identity_src, '$wpdb->prefix' ) !== false && strpos( $identity_src, 'get_option( self::OPTION_VERSION' ) !== false && strpos( $linker_src, '$wpdb->prefix' ) !== false && strpos( $linker_src, 'get_option( self::OPTION_VERSION' ) !== false,
			'Facebook command uses canonical linker' => strpos( $listener_src, 'BizCity_Channel_User_Linker::resolve_wp_user' ) !== false,
			'Linker exposes FB_MESS resolution and issue path' => strpos( $linker_src, 'resolve_wp_user' ) !== false && strpos( $linker_src, 'issue_link' ) !== false,
			'Writer has no-owner fail-closed guard' => strpos( $writer_src, 'bizcity_twinbrain_memory_no_owner' ) !== false && strpos( $writer_src, "'no_owner'" ) !== false,
			'Runtime has explicit subject authorization filter' => strpos( $runtime_src, 'bizcity_twinbrain_can_access_subject' ) !== false,
		);
		$loader_ok = ! in_array( false, $loader_checks, true );
		if ( ! $loader_ok ) { $pass = false; }
		$failed_loader = array();
		foreach ( $loader_checks as $label => $ok ) {
			if ( ! $ok ) { $failed_loader[] = $label; }
		}
		$rows[] = array(
			'label'  => 'Loader: canonical identity and memory ownership guards present',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Canonical linker, no-owner event, and subject authorization contract confirmed.' : implode( '; ', $failed_loader ),
		);

		// LAYER 3(a): issue/consume a sentinel FB link, then resolve it.
		$blog_id       = (int) get_current_blog_id();
		$actor_user_id = (int) get_current_user_id();
		$this->fb_external = '__healthtest_fb_identity_' . $blog_id . '_' . wp_rand( 1000, 9999 );
		$this->fb_account  = '__healthtest_fb_page_' . $blog_id;
		// [2026-07-28 Johnny Chu] PHASE-0.52 W6 — keep the shared magic-link consumer active when Diagnostics loaded the class without its normal bootstrap path.
		if ( has_action( 'bizcity_crm_magic_link_consumed', array( 'BizCity_Channel_User_Linker', 'on_magic_link_consumed' ) ) === false ) {
			BizCity_Channel_User_Linker::init();
		}
		$unlinked = BizCity_Channel_User_Linker::resolve_wp_user( 'FB_MESS', $this->fb_external, $this->fb_account, $blog_id );
		$issued = BizCity_Channel_User_Linker::issue_link( 'FB_MESS', $this->fb_external, $this->fb_account, $blog_id, array( 'source' => 'diagnostic_probe' ) );
		$link_reason = '';
		// [2026-07-28 Johnny Chu] R-CH-IDMEM — the linker intentionally returns only a URL; recover the one-time token from its query string for this in-process verification.
		$link_token = '';
		if ( is_array( $issued ) && ! empty( $issued['url'] ) ) {
			$link_parts = wp_parse_url( (string) $issued['url'] );
			$link_query = array();
			if ( is_array( $link_parts ) && ! empty( $link_parts['query'] ) ) {
				parse_str( (string) $link_parts['query'], $link_query );
			}
			$link_token = trim( (string) ( $link_query['bzzalolink'] ?? '' ) );
		}
		$link_ok = is_array( $issued ) && $link_token !== '' && ! empty( $issued['url'] ) && $unlinked === 0;
		if ( ! $link_ok ) {
			$link_reason = is_wp_error( $issued ) ? (string) $issued->get_error_code() : 'issue_or_initial_resolve_failed';
		}
		$linked = 0;
		if ( $link_ok ) {
			$row = BizCity_CRM_Magic_Link::verify( $link_token );
			if ( ! is_array( $row ) ) {
				$link_reason = is_wp_error( $row ) ? (string) $row->get_error_code() : 'verify_failed';
			}
			$consumed = is_array( $row ) && BizCity_CRM_Magic_Link::consume( (int) $row['id'], $actor_user_id );
			if ( ! $consumed && $link_reason === '' ) {
				$link_reason = 'consume_failed';
			}
			$linked = BizCity_Channel_User_Linker::resolve_wp_user( 'FB_MESS', $this->fb_external, $this->fb_account, $blog_id );
			$link_ok = $consumed && $actor_user_id > 0 && $linked === $actor_user_id;
			if ( ! $link_ok && $link_reason === '' ) {
				$link_reason = 'linked_owner_mismatch';
			}
		}
		if ( ! $link_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (a): FB unlinked → link → canonical linked owner',
			'status' => $link_ok ? 'pass' : 'fail',
			'detail' => $link_ok ? 'Unlinked identity resolved to 0, then to the authenticated probe user after token consumption.' : 'FB identity did not complete the expected fail-closed link transition (' . $link_reason . ').',
		);

		// LAYER 3(b): private subject resolver honors authorization and defaults to actor.
		$subject_ok = false;
		$subject_detail = 'TwinBrain runtime subject resolver unavailable.';
		try {
			$runtime = BizCity_TwinBrain_Runtime::instance();
			$method = new ReflectionMethod( $runtime, 'resolve_subject_id' );
			$method->setAccessible( true );
			$target_user_id = 2147483000;
			$denied = $method->invoke( $runtime, $actor_user_id, array( 'subject_id' => $target_user_id ) );
			$allow = static function ( $allowed, $requested, $actor ) use ( $target_user_id, $actor_user_id ) {
				return ( (int) $requested === $target_user_id && (int) $actor === $actor_user_id ) ? true : $allowed;
			};
			add_filter( 'bizcity_twinbrain_can_access_subject', $allow, 10, 3 );
			$allowed = $method->invoke( $runtime, $actor_user_id, array( 'subject_id' => $target_user_id ) );
			remove_filter( 'bizcity_twinbrain_can_access_subject', $allow, 10 );
			$actor_key  = '__healthtest_subject_actor_' . wp_rand( 1000, 9999 );
			$target_key = '__healthtest_subject_target_' . wp_rand( 1000, 9999 );
			$actor_text  = '__healthtest_actor_memory_' . wp_rand( 1000, 9999 );
			$target_text = '__healthtest_subject_memory_' . wp_rand( 1000, 9999 );
			// [2026-07-28 Johnny Chu] R-CH-IDMEM — plant diagnostic rows with verified UUID owners.
			$actor_scope = class_exists( 'BizCity_Memory_Identity_Scope' )
				? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $actor_user_id ) )
				: null;
			$target_scope = class_exists( 'BizCity_Memory_Identity_Scope' )
				? BizCity_Memory_Identity_Scope::for_write( array( 'user_id' => $target_user_id ) )
				: null;
			$this->memory_keys = array( $actor_key, $target_key );
			$memory = BizCity_User_Memory::instance();
			$memory->upsert_public( array( 'user_id' => $actor_user_id, 'identity_uuid' => (string) ( $actor_scope['identity_uuid'] ?? '' ), 'session_id' => '', 'memory_tier' => 'explicit', 'memory_type' => 'fact', 'memory_key' => $actor_key, 'memory_text' => $actor_text, 'score' => 99 ) );
			$memory->upsert_public( array( 'user_id' => $target_user_id, 'identity_uuid' => (string) ( $target_scope['identity_uuid'] ?? '' ), 'session_id' => '', 'memory_tier' => 'explicit', 'memory_type' => 'fact', 'memory_key' => $target_key, 'memory_text' => $target_text, 'score' => 99 ) );
			$recall = BizCity_TwinBrain_Memory_Recall::instance()->collect( $target_user_id, $target_text, array( 'session_id' => '', 'identity_uuid' => (string) ( $target_scope['identity_uuid'] ?? '' ) ) );
			$block = (string) ( $recall['block'] ?? '' );
			$subject_ok = (int) $denied === $actor_user_id
				&& (int) $allowed === $target_user_id
				&& strpos( $block, $target_text ) !== false
				&& strpos( $block, $actor_text ) === false;
			$subject_detail = $subject_ok ? 'Unauthorized target fell back to actor; authorized target was selected and Memory_Recall returned only the target sentinel.' : 'Subject resolver or Memory_Recall returned an unexpected actor/target result.';
		} catch ( Throwable $e ) {
			if ( isset( $allow ) ) {
				remove_filter( 'bizcity_twinbrain_can_access_subject', $allow, 10 );
			}
			$subject_detail = 'Subject resolver check threw: ' . $e->getMessage();
		}
		if ( ! $subject_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (b): subject ID differs only with explicit authorization',
			'status' => $subject_ok ? 'pass' : 'fail',
			'detail' => $subject_detail,
		);

		// LAYER 3(c): unlinked Zalo Bot writer refuses ownerless memory.
		$no_owner_event = false;
		$no_owner_trace = '__healthtest_zalo_memory_' . wp_rand( 1000, 9999 );
		$capture_no_owner = static function ( $trace_id, $meta ) use ( &$no_owner_event, $no_owner_trace ) {
			if ( (string) $trace_id === $no_owner_trace && is_array( $meta ) && (int) ( $meta['user_id'] ?? 0 ) === 0 && (string) ( $meta['channel'] ?? '' ) === 'zalo_bot' ) {
				$no_owner_event = true;
			}
		};
		add_action( 'bizcity_twinbrain_memory_no_owner', $capture_no_owner, 10, 2 );
		$writer_result = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
			$no_owner_trace,
			'hãy nhớ probe này không được lưu',
			'',
			array( 'user_id' => 0, 'session_id' => '', 'channel' => 'zalo_bot', 'platform' => 'ZALO_BOT', 'chat_id' => '__healthtest_zalo_chat' )
		);
		remove_action( 'bizcity_twinbrain_memory_no_owner', $capture_no_owner, 10 );
		$no_owner_ok = is_array( $writer_result ) && (int) ( $writer_result['persisted'] ?? -1 ) === 0 && (string) ( $writer_result['mode'] ?? '' ) === 'no_owner' && $no_owner_event;
		if ( ! $no_owner_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (c): Zalo Bot memory without a durable UUID is refused',
			'status' => $no_owner_ok ? 'pass' : 'fail',
			'detail' => $no_owner_ok ? 'Writer returned no_owner with persisted=0 and emitted bizcity_twinbrain_memory_no_owner; stable UUID identities are tested separately below.' : 'Ownerless writer path did not prove fail-closed behavior.',
		);

		// [2026-07-28 Johnny Chu] R-CH-IDMEM — prove one durable UUID spans channels, stable external memory survives without WP user, and unsafe identity claims fail closed.
		$identity_ok     = false;
		$identity_detail = 'Durable identity hub runtime check unavailable.';
		try {
			$identity_prefix  = '__healthtest_identity_' . $blog_id . '_' . wp_rand( 1000, 9999 );
			$fb_account       = $identity_prefix . '_fb';
			$fb_external      = $identity_prefix . '_fb_user';
			$tg_account       = $identity_prefix . '_tg';
			$tg_external      = $identity_prefix . '_tg_user';
			$external_account = $identity_prefix . '_zalo';
			$external_ref     = $identity_prefix . '_zalo_user';
			$this->identity_bindings = array(
				array( 'platform' => 'FB_MESS',  'account_id' => $fb_account,       'external_ref' => $fb_external ),
				array( 'platform' => 'TELEGRAM', 'account_id' => $tg_account,       'external_ref' => $tg_external ),
				array( 'platform' => 'ZALO_BOT', 'account_id' => $external_account, 'external_ref' => $external_ref ),
			);
			$fb_identity       = BizCity_Identity_Hub::bind( 'FB_MESS', $fb_account, $fb_external, $actor_user_id, $blog_id, true, array( 'display_label' => 'Identity DDV' ) );
			$tg_identity       = BizCity_Identity_Hub::bind( 'TELEGRAM', $tg_account, $tg_external, $actor_user_id, $blog_id, true );
			$external_identity = BizCity_Identity_Hub::bind( 'ZALO_BOT', $external_account, $external_ref, 0, $blog_id, true );
			$fb_uuid       = is_array( $fb_identity ) ? (string) ( $fb_identity['identity_uuid'] ?? '' ) : '';
			$tg_uuid       = is_array( $tg_identity ) ? (string) ( $tg_identity['identity_uuid'] ?? '' ) : '';
			$external_uuid = is_array( $external_identity ) ? (string) ( $external_identity['identity_uuid'] ?? '' ) : '';
			$this->identity_probe_uuids = array_values( array_unique( array_filter( array( $fb_uuid, $tg_uuid, $external_uuid ) ) ) );
			$group_result = BizCity_Identity_Hub::bind( 'ZALO_BOT', $identity_prefix . '_group', $identity_prefix . '_member', 0, $blog_id, true, array( 'chat_kind' => 'group' ) );
			$conflict_result = BizCity_Identity_Hub::bind( 'FB_MESS', $fb_account, $fb_external, 2147483000, $blog_id, true );
			$stable_text = 'identity probe stable external memory ' . $identity_prefix;
			$stable_writer = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
				$identity_prefix . '_memory',
				'hãy nhớ ' . $stable_text,
				'',
				array(
					'user_id'            => 0,
					'session_id'         => '',
					'identity_uuid'      => $external_uuid,
					'identity_is_stable' => true,
					'enable_llm'         => false,
					'channel'            => 'zalo_bot',
					'platform'           => 'ZALO_BOT',
					'chat_id'            => 'zalobot_' . $external_account . '_' . $external_ref,
				)
			);
			$stable_recall = BizCity_TwinBrain_Memory_Recall::instance()->collect( 0, $stable_text, array( 'identity_uuid' => $external_uuid, 'session_id' => '' ) );
			$stable_block = (string) ( $stable_recall['block'] ?? '' );
			$stable_key = 'explicit:' . md5( mb_strtolower( trim( $stable_text ) ) );
			$this->memory_keys[] = $stable_key;
			$identity_ok = $fb_uuid !== ''
				&& $fb_uuid === $tg_uuid
				&& $external_uuid !== ''
				&& is_wp_error( $group_result )
				&& 'identity_group_forbidden' === $group_result->get_error_code()
				&& is_wp_error( $conflict_result )
				&& 'identity_conflict' === $conflict_result->get_error_code()
				&& (int) ( $stable_writer['persisted'] ?? 0 ) > 0
				&& strpos( $stable_block, $stable_text ) !== false;
			$identity_detail = $identity_ok
				? 'FB and Telegram share one UUID; stable external identity owns recallable memory without WP user; group and conflicting ownership were rejected.'
				: 'Durable UUID, stable external memory, or fail-closed group/conflict guard did not match the contract.';
		} catch ( Throwable $e ) {
			$identity_detail = 'Durable identity check threw: ' . $e->getMessage();
		}
		if ( ! $identity_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (d): durable UUID continuity and fail-closed identity boundaries',
			'status' => $identity_ok ? 'pass' : 'fail',
			'detail' => $identity_detail,
		);

		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — exercise the canonical Identity Hub merge mutation and verify its post-commit rebuild event.
		$merge_ok = false;
		$merge_detail = 'Identity merge fixture was not executed.';
		$merge_source_uuid = '';
		$merge_target_uuid = '';
		$merge_source_account = '__healthtest_merge_source_' . $blog_id . '_' . wp_rand( 1000, 9999 );
		$merge_target_account = '__healthtest_merge_target_' . $blog_id . '_' . wp_rand( 1000, 9999 );
		$merge_source_external = $merge_source_account . '_user';
		$merge_target_external = $merge_target_account . '_user';
		$this->identity_bindings[] = array( 'platform' => 'WEBCHAT', 'account_id' => $merge_source_account, 'external_ref' => $merge_source_external );
		$this->identity_bindings[] = array( 'platform' => 'WEBCHAT', 'account_id' => $merge_target_account, 'external_ref' => $merge_target_external );
		try {
			$merge_source = BizCity_Identity_Hub::bind( 'WEBCHAT', $merge_source_account, $merge_source_external, 0, $blog_id, true );
			$merge_target = BizCity_Identity_Hub::bind( 'WEBCHAT', $merge_target_account, $merge_target_external, 0, $blog_id, true );
			$merge_source_uuid = is_array( $merge_source ) ? (string) ( $merge_source['identity_uuid'] ?? '' ) : '';
			$merge_target_uuid = is_array( $merge_target ) ? (string) ( $merge_target['identity_uuid'] ?? '' ) : '';
			$this->identity_probe_uuids = array_values( array_unique( array_filter( array_merge( $this->identity_probe_uuids, array( $merge_source_uuid, $merge_target_uuid ) ) ) ) );
			$merge_events = 0;
			$merge_listener = static function ( $source_uuid, $target_uuid, $event ) use ( &$merge_events, &$merge_source_uuid, &$merge_target_uuid ) {
				if ( (string) $source_uuid === $merge_source_uuid && (string) $target_uuid === $merge_target_uuid && is_array( $event ) && (string) ( $event['event_uuid'] ?? '' ) !== '' ) {
					$merge_events++;
				}
			};
			add_action( 'bizcity_identity_merged', $merge_listener, 99, 3 );
			$merge_result = ( $merge_source_uuid !== '' && $merge_target_uuid !== '' ) ? BizCity_Identity_Hub::merge( $merge_source_uuid, $merge_target_uuid, 'diagnostic_probe' ) : new WP_Error( 'identity_merge_fixture_create_failed', 'Identity fixture creation failed.' );
			remove_action( 'bizcity_identity_merged', $merge_listener, 99 );
			$resolved_source = $merge_source_uuid !== '' ? BizCity_Identity_Hub::resolve( $merge_source_uuid ) : null;
			$resolved_binding = BizCity_Identity_Hub::resolve_binding( 'WEBCHAT', $merge_source_account, $merge_source_external, $blog_id );
			$merge_ok = is_array( $merge_result ) && ! empty( $merge_result['ok'] ) && ! empty( $merge_result['merged'] ) && $merge_events === 1 && is_array( $resolved_source ) && (string) ( $resolved_source['identity_uuid'] ?? '' ) === $merge_target_uuid && is_array( $resolved_binding ) && (string) ( $resolved_binding['identity_uuid'] ?? '' ) === $merge_target_uuid;
			$merge_detail = $merge_ok ? 'Identity Hub atomically merged the source into the target, moved the source binding, emitted one post-commit event and resolved both identities to the target.' : 'Identity merge did not prove atomic post-commit event, binding transfer or canonical resolution.';
		} catch ( Throwable $e ) {
			$merge_detail = 'Identity merge fixture threw: ' . sanitize_key( (string) $e->getMessage() );
		}
		if ( ! $merge_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (e): canonical identity merge and rebuild event',
			'status' => $merge_ok ? 'pass' : 'fail',
			'detail' => $merge_detail,
		);
		$merge_event_guard_ok = false;
		if ( class_exists( 'BizCity_Context_Bank_Identity_Merge_Adapter' ) ) {
			// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — reject cross-tenant and UUID-less merge events before any rollup dirty mutation.
			$invalid_tenant = BizCity_Context_Bank_Identity_Merge_Adapter::on_merged( $merge_source_uuid, $merge_target_uuid, array( 'blog_id' => (int) get_current_blog_id() + 1, 'event_uuid' => 'probe-invalid-tenant' ) );
			$missing_event = BizCity_Context_Bank_Identity_Merge_Adapter::on_merged( $merge_source_uuid, $merge_target_uuid, array( 'blog_id' => (int) get_current_blog_id() ) );
			$merge_event_guard_ok = is_array( $invalid_tenant ) && (string) ( $invalid_tenant['reason'] ?? '' ) === 'identity_merge_tenant_mismatch'
				&& is_array( $missing_event ) && (string) ( $missing_event['reason'] ?? '' ) === 'identity_merge_event_uuid_missing';
		}
		if ( ! $merge_event_guard_ok ) { $pass = false; }
		$rows[] = array(
			'label'  => 'Runtime (f): identity merge event tenant and UUID guards',
			'status' => $merge_event_guard_ok ? 'pass' : 'fail',
			'detail' => $merge_event_guard_ok ? 'Cross-tenant and UUID-less merge events were refused before rollup dirty handling.' : 'Identity merge event validation did not fail closed.',
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Channel identity and memory ownership contracts passed all W6 DDV layers.' : 'Channel identity and memory ownership probe failed; inspect the failed W6 step.',
			'steps'   => $rows,
		);
	}

	public function cleanup(): void {
		global $wpdb;
		$blog_id = (int) get_current_blog_id();
		if ( $this->fb_external !== '' && $this->fb_account !== '' && class_exists( 'BizCity_Channel_User_Linker' ) ) {
			$wpdb->delete(
				BizCity_Channel_User_Linker::table(),
				array( 'blog_id' => $blog_id, 'platform' => 'FB_MESS', 'external_user_id' => $this->fb_external, 'account_id' => $this->fb_account ),
				array( '%d', '%s', '%s', '%s' )
			);
		}
		// [2026-07-28 Johnny Chu] PHASE-0.52 W6 — keep magic-link and sentinel cleanup inside the probe method.
		if ( $this->fb_external !== '' && $this->fb_account !== '' && class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			$wpdb->delete( BizCity_CRM_Magic_Link::table(), array( 'platform' => 'FB_MESS', 'chat_id' => 'fb_' . $this->fb_account . '_' . $this->fb_external ), array( '%s', '%s' ) );
		}
		if ( ! empty( $this->memory_keys ) ) {
			$legacy = BizCity_User_Memory::table();
			foreach ( $this->memory_keys as $memory_key ) {
				$wpdb->delete( $legacy, array( 'memory_key' => $memory_key ), array( '%s' ) );
			}
			if ( class_exists( 'BizCity_Memory_Unified_Installer' ) && function_exists( 'bizcity_tbl_exists' ) ) {
				$unified = BizCity_Memory_Unified_Installer::table();
				if ( bizcity_tbl_exists( $unified ) ) {
					foreach ( $this->memory_keys as $memory_key ) {
						$wpdb->delete( $unified, array( 'memory_key' => $memory_key ), array( '%s' ) );
					}
				}
			}
		}
		if ( ! empty( $this->identity_bindings ) && class_exists( 'BizCity_Identity_Hub' ) ) {
			foreach ( $this->identity_bindings as $binding ) {
				$wpdb->delete( BizCity_Identity_Hub::table_bindings(), array(
					'blog_id'      => $blog_id,
					'platform'     => (string) $binding['platform'],
					'account_id'   => (string) $binding['account_id'],
					'external_ref' => (string) $binding['external_ref'],
				), array( '%d', '%s', '%s', '%s' ) );
			}
		}
		if ( ! empty( $this->identity_probe_uuids ) && class_exists( 'BizCity_Identity_Hub' ) ) {
			foreach ( $this->identity_probe_uuids as $identity_uuid ) {
				$wpdb->query( $wpdb->prepare(
					'DELETE FROM ' . BizCity_Identity_Hub::table_contacts() . ' WHERE identity_uuid=%s AND primary_wp_user_id=0',
					$identity_uuid
				) );
			}
		}
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Identity_Memory';
	return $list;
} );