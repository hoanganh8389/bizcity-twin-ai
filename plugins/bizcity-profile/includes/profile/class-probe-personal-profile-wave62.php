<?php
/**
 * BizCity Diagnostics — Profile Wave 6.2 quick-edit/Page Builder probe.
 *
 * @package Bizcity_Twin_AI
 */
defined( 'ABSPATH' ) || exit;

$probe_iface = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR . 'core/diagnostics/includes/interface-diagnostics-probe.php'
	: dirname( __DIR__, 4 ) . '/core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $probe_iface ) ) {
	require_once $probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) || class_exists( 'BizCity_Probe_Personal_Profile_Wave62', false ) ) {
	return;
}

final class BizCity_Probe_Personal_Profile_Wave62 implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.personal.profile.wave62'; }
	public function label(): string { return 'Profile · Wave 6.2 Quick Edit Unify'; }
	// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — include public Chat/Contact tabs in the Profile DDV surface.
	public function description(): string { return 'Disk / Loader / Runtime: Profile quick-edit, public TwinBrain/CF7 tabs, shared SiteConfig routes, provider fixtures, and compiled Page Builder artifact.'; }
	public function severity(): string { return 'warning'; }
	public function order(): int { return 86; }
	public function icon(): string { return 'id-card'; }
	public function estimate_ms(): int { return 30; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2 DDV, read-only fixture checks.
		$steps = array();
		$pass  = true;
		$plugin_root = defined( 'BIZCITY_PERSONAL_DIR' ) ? BIZCITY_PERSONAL_DIR : dirname( __DIR__, 2 ) . '/';
		$pagebuilder_root = defined( 'BZPB_DIR' ) ? BZPB_DIR : dirname( $plugin_root ) . '/bizcity-pagebuilder/';
		$rest_file = $plugin_root . 'includes/profile/class-personal-profile-rest.php';
		$twinweb_rest_file = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'modules/twinweb/includes/class-twinweb-rest.php' : '';
		$compact_template_file = $plugin_root . 'includes/profile/templates/business-card-compact.json';
		$full_template_file = $plugin_root . 'includes/profile/templates/business-card-full.json';
		$portfolio_template_file = $plugin_root . 'includes/profile/templates/business-card-portfolio.json';
		$wheel_provider_file = $plugin_root . 'includes/profile/class-personal-profile-wheel-provider.php';
		$wheel_bridge_file = $plugin_root . 'includes/profile/class-personal-profile-wheel-bridge.php';
		$export_file = $pagebuilder_root . 'includes/class-export.php';
		$dist_file = $pagebuilder_root . 'assets/dist/pagebuilder-app.js';
		$profile_ui_dist_file = $plugin_root . 'ui/dist/assets/profile.js';
		$profile_public_dist_file = $plugin_root . 'ui/dist/assets/profile-public.js';
		$disk_ok = is_readable( $rest_file ) && is_readable( $wheel_provider_file ) && is_readable( $wheel_bridge_file ) && is_readable( $export_file ) && is_readable( $dist_file ) && is_readable( $profile_ui_dist_file ) && is_readable( $profile_public_dist_file ) && is_readable( $compact_template_file ) && is_readable( $full_template_file ) && is_readable( $portfolio_template_file );
		$steps[] = array(
			'label'  => 'Disk · Wave 6.2 runtime files and compiled Page Builder bundle',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Profile REST, wheel provider/bridge, BZPB renderer, pagebuilder-app.js, and deployed profile.js are readable.' : 'One or more Wave 6.2 runtime files or a deployed frontend bundle is missing.',
		);
		if ( ! $disk_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — verify the ported source template keeps the shared Profile card, portfolio sections, and canonical lead form.
		$portfolio_template_ok = false;
		$portfolio_template_detail = 'Portfolio template JSON is missing or invalid.';
		if ( is_readable( $portfolio_template_file ) ) {
			$portfolio_config = json_decode( (string) file_get_contents( $portfolio_template_file ), true );
			$portfolio_types = array();
			foreach ( is_array( $portfolio_config['blocks'] ?? null ) ? $portfolio_config['blocks'] : array() as $block ) {
				$portfolio_types[] = (string) ( $block['type'] ?? '' );
			}
			$profile_block = array();
			foreach ( is_array( $portfolio_config['blocks'] ?? null ) ? $portfolio_config['blocks'] : array() as $block ) {
				if ( 'profile-card' === (string) ( $block['type'] ?? '' ) ) { $profile_block = is_array( $block['props'] ?? null ) ? $block['props'] : array(); break; }
			}
			$portfolio_template_ok = is_array( $portfolio_config )
				&& 'vcard_portfolio' === (string) ( $profile_block['profileStyle'] ?? '' )
				&& in_array( 'navbar', $portfolio_types, true )
				&& in_array( 'profile-card', $portfolio_types, true )
				&& in_array( 'content', $portfolio_types, true )
				&& in_array( 'features', $portfolio_types, true )
				&& in_array( 'testimonials', $portfolio_types, true )
				&& in_array( 'gallery', $portfolio_types, true )
				&& in_array( 'lead-form', $portfolio_types, true )
				&& (string) ( (array) ( $portfolio_config['blocks'][5] ?? array() )['variant'] ?? '' ) === 'timeline'
				&& (string) ( (array) ( $portfolio_config['blocks'][6] ?? array() )['variant'] ?? '' ) === 'progress'
				&& ! empty( ( (array) ( $portfolio_config['blocks'][7] ?? array() )['props'] ?? array() )['filterable'] )
				&& ! empty( $profile_block['chatEntrypoints'] )
				&& is_readable( $export_file )
				&& false !== strpos( (string) file_get_contents( $export_file ), 'vcard_portfolio' );
			$portfolio_template_detail = $portfolio_template_ok ? 'Ported portfolio template has the Profile/WebChat anchor, portfolio sections, navigation, and canonical lead form.' : 'Ported portfolio template is missing a required block or Profile contract marker.';
		}
		$steps[] = array(
			'label'  => 'Disk · ported Profile portfolio template contract',
			'status' => $portfolio_template_ok ? 'pass' : 'fail',
			'detail' => $portfolio_template_detail,
		);
		if ( ! $portfolio_template_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — verify template switching is server-allowlisted and exposed by the owner-scoped REST handler.
		$template_switch_contract_ok = $disk_ok && false !== strpos( (string) file_get_contents( $rest_file ), "'/profile/cards/(?P<id>\\d+)/template'" ) && false !== strpos( (string) file_get_contents( $rest_file ), 'public function apply_template' ) && false !== strpos( (string) file_get_contents( $rest_file ), "'business-card-compact', 'business-card-full', 'business-card-portfolio'" );
		$steps[] = array(
			'label'  => 'Disk · owner-scoped Profile template switch contract',
			'status' => $template_switch_contract_ok ? 'pass' : 'fail',
			'detail' => $template_switch_contract_ok ? 'Profile Edit can select only the three server-owned template keys through the template route.' : 'Template switch route or server allowlist is missing.',
		);
		if ( ! $template_switch_contract_ok ) { $pass = false; }

		// [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — verify Profile can use the canonical TwinWeb stream without exposing notebook selection to the public client.
		$profile_sse_contract_ok = is_readable( $twinweb_rest_file )
			&& false !== strpos( (string) file_get_contents( $twinweb_rest_file ), "'profile_context_token'" )
			&& false !== strpos( (string) file_get_contents( $twinweb_rest_file ), '$mode      = $is_profile_public ? \'chat\'' )
			&& false !== strpos( (string) file_get_contents( $export_file ), 'bizcity-twinweb/v1/chat/stream' );
		$steps[] = array(
			'label'  => 'Disk · Profile public shared SSE/no-notebook contract',
			'status' => $profile_sse_contract_ok ? 'pass' : 'fail',
			'detail' => $profile_sse_contract_ok ? 'Profile public stream uses signed context, shared TwinWeb SSE and chat mode without a notebook selector.' : 'Profile public shared SSE contract is missing or stale.',
		);
		if ( ! $profile_sse_contract_ok ) { $pass = false; }

		$loader_ok = class_exists( 'BizCity_Personal_Profile_REST' )
			&& class_exists( 'BizCity_Personal_Page' )
			&& method_exists( 'BizCity_Personal_Page', 'maybe_render' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'list_shortcodes' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'add_shortcode' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'remove_shortcode' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'insert_shortcode_after_lead_form' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'filter_zalo_bots_for_user' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'chat_config' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'update_chat_config' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'apply_template' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'create_contact_follow_up' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'follow_ups' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'complete_follow_up' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'is_profile_follow_up_event' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'follow_up_payload' )
			&& method_exists( 'BizCity_Personal_Profile_REST', 'lead_priorities' )
			&& class_exists( 'BizCity_Scheduler_Manager' )
			&& method_exists( 'BizCity_Scheduler_Manager', 'get_events' )
			&& method_exists( 'BizCity_Scheduler_Manager', 'update_event' )
			&& class_exists( 'BizCity_Profile_Wheel_Provider_Registry' )
			&& interface_exists( 'BizCity_Profile_Wheel_Provider' )
			&& class_exists( 'BizCity_Profile_Mabel_Wheel_Provider' )
			&& class_exists( 'BizCity_Personal_Profile_Wheel_Bridge' )
			&& class_exists( 'BZPB_Export' )
			&& method_exists( 'BZPB_Export', 'render_canvas_page' );
		$steps[] = array(
			'label'  => 'Loader · shared Profile/BZPB classes and quick-edit methods',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'REST handlers, insertion helper, and BZPB canvas renderer are loaded.' : 'One or more shared Profile/BZPB methods are not loaded.',
		);
		if ( ! $loader_ok ) { $pass = false; }

		// [2026-08-22 Johnny Chu] PHASE-PROFILE-ROLE-SPLIT — prove the three public role aliases resolve to the intended shared surface.
		$surface_runtime_ok = false;
		if ( class_exists( 'BizCity_Personal_Page' ) ) {
			$surface_reflection = new ReflectionClass( 'BizCity_Personal_Page' );
			if ( $surface_reflection->hasMethod( 'surface_for_request' ) ) {
				$surface_method = $surface_reflection->getMethod( 'surface_for_request' );
				$surface_method->setAccessible( true );
				$original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
				$surface_runtime_ok = true;
				foreach ( array( 'profile' => 'profile-care', 'profile-care' => 'profile-care', 'profile-public' => 'profile-public' ) as $slug => $expected_surface ) {
					$_SERVER['REQUEST_URI'] = (string) parse_url( home_url( '/' . $slug . '/' ), PHP_URL_PATH );
					$surface_runtime_ok = $surface_runtime_ok && $expected_surface === $surface_method->invoke( null );
				}
				if ( null === $original_request_uri ) {
					unset( $_SERVER['REQUEST_URI'] );
				} else {
					$_SERVER['REQUEST_URI'] = $original_request_uri;
				}
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · Profile Care/Public role route aliases',
			'status' => $surface_runtime_ok ? 'pass' : 'fail',
			'detail' => $surface_runtime_ok ? '/profile/ and /profile-care/ resolve to Profile Care; /profile-public/ resolves to Profile Public.' : 'Profile role route aliases did not resolve through the shared page controller.',
		);
		if ( ! $surface_runtime_ok ) { $pass = false; }

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: deterministic consent fixture must not invoke CRM or provider side effects.
		$consent_runtime_ok = false;
		if ( class_exists( 'BizCity_Personal_Profile_Wheel_Bridge' ) ) {
			$wheel_reflection = new ReflectionClass( 'BizCity_Personal_Profile_Wheel_Bridge' );
			if ( $wheel_reflection->hasMethod( 'has_explicit_consent' ) ) {
				$consent_method = $wheel_reflection->getMethod( 'has_explicit_consent' );
				$consent_method->setAccessible( true );
				$crm_method = $wheel_reflection->getMethod( 'should_upsert_crm' );
				$crm_method->setAccessible( true );
				$consent_runtime_ok = true === $consent_method->invoke( null, array(), array( 'marketing-consent' => '1' ) )
					&& false === $consent_method->invoke( null, array(), array( 'marketing-consent' => '0' ) )
						&& true === $consent_method->invoke( null, array( 'consent' => true ), array() )
						&& true === $crm_method->invoke( null, 'lead@example.test', true )
						&& false === $crm_method->invoke( null, 'lead@example.test', false )
						&& false === $crm_method->invoke( null, '', true );
			}
		}
		$steps[] = array(
			'label'  => 'Fixture · wheel CRM consent gate',
			'status' => $consent_runtime_ok ? 'pass' : 'fail',
			'detail' => $consent_runtime_ok ? 'Explicit consent plus email/phone permits CRM attribution; missing consent or identity does not.' : 'Consent gate did not distinguish opted-in and opted-out payloads.',
		);
		if ( ! $consent_runtime_ok ) { $pass = false; }

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: verify every provider boundary before runtime play attribution.
		$provider_contract_ok = false;
		$provider_contract_missing = array();
		if ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ) {
			$provider = BizCity_Profile_Wheel_Provider_Registry::get( 'mabel' );
			$provider_methods = array( 'key', 'is_available', 'register_hooks', 'list_for_user', 'can_use', 'member_can_use', 'assigned_user_ids', 'set_assigned_user_ids', 'render', 'stats' );
			if ( ! $provider ) { $provider_contract_missing[] = 'mabel provider not registered'; }
			foreach ( $provider_methods as $provider_method ) {
				if ( $provider && ! method_exists( $provider, $provider_method ) ) { $provider_contract_missing[] = $provider_method; }
			}
			$provider_contract_ok = empty( $provider_contract_missing ) && 'mabel' === $provider->key();
		}
		$steps[] = array(
			'label'  => 'Fixture · wheel provider registry contract',
			'status' => $provider_contract_ok ? 'pass' : 'fail',
			'detail' => $provider_contract_ok ? 'Mabel is registered behind the provider interface with list, permission, assignment, render, stats, and hook contracts.' : 'Missing provider contract parts: ' . implode( ', ', $provider_contract_missing ?: array( 'registry class not loaded' ) ),
		);
		if ( ! $provider_contract_ok ) { $pass = false; }

		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: verify owner, assigned member, and unrelated member isolation in memory.
		$member_wheel_runtime_ok = false;
		if ( $provider_contract_ok && method_exists( $provider, 'member_can_use' ) ) {
			$member_wheel_runtime_ok = true === $provider->member_can_use( 101, 101, array( 202 ) )
				&& true === $provider->member_can_use( 202, 101, array( 202 ) )
				&& false === $provider->member_can_use( 303, 101, array( 202 ) );
		}
		$steps[] = array(
			'label'  => 'Fixture · member-owned wheel isolation',
			'status' => $member_wheel_runtime_ok ? 'pass' : 'fail',
			'detail' => $member_wheel_runtime_ok ? 'Wheel owner and assigned member are allowed; an unrelated member is refused.' : 'Member wheel ownership did not isolate the fixture users.',
		);
		if ( ! $member_wheel_runtime_ok ) { $pass = false; }

		$zalo_runtime_ok = false;
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$profile_reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
			if ( $profile_reflection->hasMethod( 'filter_zalo_bots_for_user' ) ) {
				$zalo_filter = $profile_reflection->getMethod( 'filter_zalo_bots_for_user' );
				$zalo_filter->setAccessible( true );
				$zalo_fixture = array(
					array( 'id' => 11, 'oa_id' => 'oa-11' ),
					array( 'id' => 22, 'oa_id' => 'oa-22' ),
				);
				$user_one = $zalo_filter->invoke( BizCity_Personal_Profile_REST::instance(), $zalo_fixture, 101, false, array( 11 ) );
				$user_two = $zalo_filter->invoke( BizCity_Personal_Profile_REST::instance(), $zalo_fixture, 202, false, array( 22 ) );
				$zalo_runtime_ok = count( $user_one ) === 1 && (int) $user_one[0]['id'] === 11 && count( $user_two ) === 1 && (int) $user_two[0]['id'] === 22;
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · Zalo bot assignment isolation fixture',
			'status' => $zalo_runtime_ok ? 'pass' : 'fail',
			'detail' => $zalo_runtime_ok ? 'Two member assignment sets return only their own Zalo bots.' : 'Zalo assignment filtering did not isolate the fixture users.',
		);
		if ( ! $zalo_runtime_ok ) { $pass = false; }

		$zalo_assignment_ok = class_exists( 'BizCity_Zalo_Bot_Dashboard' )
			&& method_exists( 'BizCity_Zalo_Bot_Dashboard', 'get_user_bot_ids' );
		$steps[] = array(
			'label'  => 'Loader · Zalo OA account ownership resolver',
			'status' => $zalo_assignment_ok ? 'pass' : 'warn',
			'detail' => $zalo_assignment_ok ? 'Profile quick-pick can use the existing per-user bot assignment usermeta.' : 'Zalo Bot assignment resolver is not loaded; non-admin quick-pick remains fail-closed with an empty list.',
		);

		// [2026-08-22 Johnny Chu] PHASE-TBP-6.3 — prove Zalo Personal picker uses the canonical owner mapping repository.
		$zalo_personal_picker_ok = class_exists( 'BizCity_Zalo_Mapping_Repo' )
			&& method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' )
			&& $disk_ok
			&& false !== strpos( (string) file_get_contents( $rest_file ), "'zalo_personal' === \$platform" )
			&& false !== strpos( (string) file_get_contents( $rest_file ), 'list_personal_accounts_for_owner' );
		$steps[] = array(
			'label'  => 'Loader · Zalo Personal owner-scoped Profile picker',
			'status' => $zalo_personal_picker_ok ? 'pass' : 'fail',
			'detail' => $zalo_personal_picker_ok ? 'Profile picker uses BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner().' : 'Zalo Personal picker or canonical owner mapping method is missing.',
		);
		if ( ! $zalo_personal_picker_ok ) { $pass = false; }

		$route_ok = false;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$route_ok = isset( $routes['/bizcity-profile/v1/profile/cards/(?P<id>\d+)/shortcodes'] )
				&& isset( $routes['/bizcity-profile/v1/profile/cards/(?P<id>\d+)/shortcodes/(?P<block_id>[a-zA-Z0-9_-]+)'] )
				&& isset( $routes['/bizcity-profile/v1/profile/wheels/(?P<provider>[a-z0-9_-]+)/(?P<id>\d+)/members'] )
				&& isset( $routes['/bizcity-profile/v1/profile/chat-config'] )
				&& isset( $routes['/bizcity-profile/v1/profile/contacts/(?P<id>\d+)/follow-up'] )
				&& isset( $routes['/bizcity-profile/v1/profile/follow-ups'] )
				&& isset( $routes['/bizcity-profile/v1/profile/follow-ups/(?P<id>\d+)/done'] )
				&& isset( $routes['/bizcity-profile/v1/profile/lead-priorities'] );
		}
		$steps[] = array(
			'label'  => 'Loader · Profile/TBP-5 REST routes registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'Profile edit, provider assignment, Guru config, and Scheduler follow-up routes are registered.' : 'One or more Profile/TBP-5 routes are missing from the REST server.',
		);
		if ( ! $route_ok ) { $pass = false; }

		$follow_up_contract_ok = false;
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$rest_reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
			if ( $rest_reflection->hasMethod( 'follow_up_bucket' ) ) {
				$bucket_method = $rest_reflection->getMethod( 'follow_up_bucket' );
				$bucket_method->setAccessible( true );
				$rest = BizCity_Personal_Profile_REST::instance();
				$follow_up_contract_ok = 'overdue' === $bucket_method->invoke( $rest, '2026-08-20 09:00:00', '2026-08-21' )
					&& 'today' === $bucket_method->invoke( $rest, '2026-08-21 09:00:00', '2026-08-21' )
					&& 'upcoming' === $bucket_method->invoke( $rest, '2026-08-22 09:00:00', '2026-08-21' );
			}
			if ( $rest_reflection->hasMethod( 'is_profile_follow_up_event' ) ) {
				$profile_event_method = $rest_reflection->getMethod( 'is_profile_follow_up_event' );
				$profile_event_method->setAccessible( true );
				$profile_event_ok = true === $profile_event_method->invoke( $rest, array( 'event_type' => 'crm_conversation_task', 'metadata' => wp_json_encode( array( 'source' => 'profile', 'profile_card_id' => 1, 'contact_id' => 2 ) ) ) )
					&& false === $profile_event_method->invoke( $rest, array( 'event_type' => 'crm_conversation_task', 'metadata' => wp_json_encode( array( 'source' => 'crm_inbox', 'profile_card_id' => 1, 'contact_id' => 2 ) ) ) );
			} else {
				$profile_event_ok = false;
			}
		}
		$follow_up_contract_ok = ! empty( $follow_up_contract_ok ) && ! empty( $profile_event_ok );
		$steps[] = array(
			'label'  => 'Fixture · follow-up queue date buckets',
			'status' => $follow_up_contract_ok ? 'pass' : 'fail',
			'detail' => $follow_up_contract_ok ? 'Scheduler follow-ups classify deterministically into overdue, today, and upcoming.' : 'Follow-up date classification did not match the queue contract.',
		);
		if ( ! $follow_up_contract_ok ) { $pass = false; }

		$public_tabs_ok = false;
		if ( $disk_ok ) {
			// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — verify the public surface keeps chat and lead capture as separate accessible panels.
			$export_source = (string) file_get_contents( $export_file );
			$public_tabs_ok = false !== strpos( $export_source, 'data-profile-public-tabs="1"' )
				&& false !== strpos( $export_source, 'data-profile-tab="chat"' )
				&& false !== strpos( $export_source, 'data-profile-tab="contact"' )
				&& false !== strpos( $export_source, 'data-profile-tab-panel="chat"' )
				&& false !== strpos( $export_source, 'data-profile-tab-panel="contact"' );
		}
		$steps[] = array(
			'label'  => 'Disk · public Profile Chat/Contact tab contract',
			'status' => $public_tabs_ok ? 'pass' : 'fail',
			'detail' => $public_tabs_ok ? 'Public export contains accessible Chat and Contact panels.' : 'Public Profile tab markers are missing from the served export source.',
		);
		if ( ! $public_tabs_ok ) { $pass = false; }

		$funnel_contract_ok = class_exists( 'BizCity_Personal_Profile_Analytics' )
			&& defined( 'BizCity_Personal_Profile_Analytics::EVENT_TYPES' )
			&& in_array( 'share', BizCity_Personal_Profile_Analytics::EVENT_TYPES, true )
			&& in_array( 'chat_open', BizCity_Personal_Profile_Analytics::EVENT_TYPES, true )
			&& in_array( 'chat_message', BizCity_Personal_Profile_Analytics::EVENT_TYPES, true )
			&& in_array( 'contact_submitted', BizCity_Personal_Profile_Analytics::EVENT_TYPES, true )
			&& method_exists( 'BizCity_Personal_Profile_Analytics', 'funnel_for_card' );
		$steps[] = array(
			'label'  => 'Loader · share funnel analytics contract',
			'status' => $funnel_contract_ok ? 'pass' : 'fail',
			'detail' => $funnel_contract_ok ? 'Share, chat, and contact funnel events plus aggregation method are loaded.' : 'One or more share funnel analytics events or aggregation methods are missing.',
		);
		if ( ! $funnel_contract_ok ) { $pass = false; }

		$profile_ui_artifact_ok = false;
		if ( is_readable( $profile_ui_dist_file ) ) {
			$profile_ui_artifact = (string) file_get_contents( $profile_ui_dist_file );
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-UX — fail the deployed-artifact gate when the Profile Edit Template tab is absent.
			$profile_ui_artifact_ok = false !== strpos( $profile_ui_artifact, 'profile/follow-ups' ) && false !== strpos( $profile_ui_artifact, 'Hàng đợi follow-up' ) && false !== strpos( $profile_ui_artifact, 'Áp dụng template' );
		}
		$steps[] = array(
			'label'  => 'Runtime · deployed Profile follow-up queue artifact',
			'status' => $profile_ui_artifact_ok ? 'pass' : 'fail',
			'detail' => $profile_ui_artifact_ok ? 'The served Profile bundle contains the Scheduler follow-up queue.' : 'The deployed Profile bundle is stale or missing the follow-up queue marker.',
		);
		if ( ! $profile_ui_artifact_ok ) { $pass = false; }
		$profile_public_artifact_ok = false;
		if ( is_readable( $profile_public_dist_file ) ) {
			$profile_public_artifact = (string) file_get_contents( $profile_public_dist_file );
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-REACT — verify the public page is served by the dedicated React chat bundle, not the dashboard bundle.
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-REACT — require the Hero graph and visible prompt composer in the deployed public artifact.
			// [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — deployed artifact must carry the canonical Nexus KG palette (KGGraphViewNexus.tsx), not a decorative rainbow.
			$profile_public_artifact_ok = false !== strpos( $profile_public_artifact, 'bzp-react-chat-panel' ) && false !== strpos( $profile_public_artifact, 'bzp-react-chat-launch' ) && false !== strpos( $profile_public_artifact, 'bzp-public-hero-graph' ) && false !== strpos( $profile_public_artifact, '#4ade80' ) && false !== strpos( $profile_public_artifact, 'bizcity-profile-graph-highlight' ) && false !== strpos( $profile_public_artifact, 'data-bizcity-public-chat-style' ) && false !== strpos( $profile_public_artifact, 'profile_webchat_' );
		}
		$steps[] = array(
			'label'  => 'Runtime · dedicated public React chat artifact',
			'status' => $profile_public_artifact_ok ? 'pass' : 'fail',
			'detail' => $profile_public_artifact_ok ? 'Public Profile uses the lightweight React chat bundle with scoped styles and the canonical Profile session prefix.' : 'The dedicated public React chat bundle is stale, missing, or not self-contained.',
		);
		if ( ! $profile_public_artifact_ok ) { $pass = false; }

		$runtime_ok = false;
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
			if ( $reflection->hasMethod( 'insert_shortcode_after_lead_form' ) ) {
				$method = $reflection->getMethod( 'insert_shortcode_after_lead_form' );
				$method->setAccessible( true );
				$rest = BizCity_Personal_Profile_REST::instance();
				$new_block = array( 'id' => 'probe-shortcode', 'type' => 'shortcode', 'variant' => 'default', 'props' => array( 'shortcode' => '[gallery]', 'label' => '' ) );
				$legacy = $method->invoke( $rest, array( 'blocks' => array( array( 'id' => 'lead', 'type' => 'lead-form' ), array( 'id' => 'cta', 'type' => 'cta' ) ) ), $new_block );
				$legacy_types = array_map( function ( $block ) { return (string) ( $block['type'] ?? '' ); }, $legacy['blocks'] ?? array() );
				$paged = $method->invoke( $rest, array( 'blocks' => array(), 'pages' => array( array( 'id' => 'page-home', 'blocks' => array( array( 'id' => 'lead', 'type' => 'lead-form' ), array( 'id' => 'cta', 'type' => 'cta' ) ) ) ) ), $new_block );
				$paged_types = array_map( function ( $block ) { return (string) ( $block['type'] ?? '' ); }, $paged['pages'][0]['blocks'] ?? array() );
				$runtime_ok = $legacy_types === array( 'lead-form', 'shortcode', 'cta' )
					&& $paged_types === array( 'lead-form', 'shortcode', 'cta' )
					&& $paged_types === array_map( function ( $block ) { return (string) ( $block['type'] ?? '' ); }, $paged['blocks'] ?? array() );
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · quick-add inserts shortcode immediately after lead-form',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'Legacy and paged SiteConfig fixtures preserve lead-form → shortcode → next-block order.' : 'Insertion helper did not preserve the expected block order.',
		);
		if ( ! $runtime_ok ) { $pass = false; }

		$artifact_ok = false;
		if ( is_readable( $dist_file ) ) {
			$artifact = (string) file_get_contents( $dist_file );
			$artifact_ok = false !== strpos( $artifact, 'profile-card' ) && false !== strpos( $artifact, 'shortcode' );
		}
		$steps[] = array(
			'label'  => 'Runtime · compiled Page Builder artifact contains profile-card and shortcode UI',
			'status' => $artifact_ok ? 'pass' : 'fail',
			'detail' => $artifact_ok ? 'The served bundle contains both Wave 6.2 block markers.' : 'The served bundle is stale or does not contain Wave 6.2 markers.',
		);
		if ( ! $artifact_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — verify the deployed Page Builder canvas bundle contains the ported portfolio renderer variants and filter interaction.
		$canvas_portfolio_ok = false;
		if ( is_readable( $dist_file ) ) {
			$canvas_portfolio = (string) file_get_contents( $dist_file );
			$canvas_portfolio_ok = false !== strpos( $canvas_portfolio, 'timeline' )
				&& false !== strpos( $canvas_portfolio, 'progress' )
				&& false !== strpos( $canvas_portfolio, 'preview-gallery-filter' )
				&& false !== strpos( $canvas_portfolio, 'bzpb-preview-portfolio' );
		}
		$steps[] = array(
			'label'  => 'Runtime · Page Builder canvas portfolio parity artifact',
			'status' => $canvas_portfolio_ok ? 'pass' : 'fail',
			'detail' => $canvas_portfolio_ok ? 'Deployed Page Builder canvas contains timeline, progress, responsive portfolio layout, and gallery filter renderers.' : 'Page Builder canvas bundle is missing one or more Profile portfolio parity markers.',
		);
		if ( ! $canvas_portfolio_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-TBP-3 — validate the public capability snapshot in memory without DB, KG, LLM, or provider side effects.
		$snapshot_runtime_ok = false;
		$snapshot_detail = 'Profile snapshot helper is not loaded.';
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$snapshot_reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
			if ( $snapshot_reflection->hasMethod( 'with_public_graph_snapshot' ) ) {
				$snapshot_method = $snapshot_reflection->getMethod( 'with_public_graph_snapshot' );
				$snapshot_method->setAccessible( true );
				$snapshot_fixture = $snapshot_method->invoke( BizCity_Personal_Profile_REST::instance(), array(
					'blocks' => array(
						array(
							'type'  => 'profile-card',
							'props' => array(
								'publicCapabilities' => array(
									array( 'id' => 'consulting', 'label' => 'Tư vấn', 'category' => 'expertise', 'weight' => 8, 'private_note' => 'must-drop' ),
								),
							),
						),
					),
				) );
				$snapshot = $snapshot_fixture['blocks'][0]['props']['publicGraphSnapshot'] ?? array();
				$capability = $snapshot['capabilities'][0] ?? array();
				$snapshot_runtime_ok = (int) ( $snapshot['version'] ?? 0 ) === 1
					&& 'profile_public_capabilities' === (string) ( $snapshot['source'] ?? '' )
					&& ! empty( $snapshot['content_hash'] )
					&& ! empty( $snapshot['graph_hash'] )
					&& 'Tư vấn' === (string) ( $capability['label'] ?? '' )
					&& ! array_key_exists( 'private_note', $capability )
					&& hash( 'sha256', wp_json_encode( $snapshot['capabilities'] ) ) === (string) $snapshot['content_hash']
					&& hash( 'sha256', wp_json_encode( $snapshot['graph'] ?? array() ) ) === (string) $snapshot['graph_hash'];
				$snapshot_detail = $snapshot_runtime_ok ? 'Publish snapshot keeps only approved public capability fields and a verifiable content hash.' : 'Publish snapshot fixture did not produce the expected public-safe shape.';
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · public capability snapshot privacy fixture',
			'status' => $snapshot_runtime_ok ? 'pass' : 'fail',
			'detail' => $snapshot_detail,
		);
		if ( ! $snapshot_runtime_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-TBP-3 — validate portfolio projection allowlists public block content and excludes executable/private block types.
		$portfolio_runtime_ok = false;
		$portfolio_detail = 'Portfolio snapshot helper is not loaded.';
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$portfolio_reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
			if ( $portfolio_reflection->hasMethod( 'with_public_portfolio_snapshot' ) ) {
				$portfolio_method = $portfolio_reflection->getMethod( 'with_public_portfolio_snapshot' );
				$portfolio_method->setAccessible( true );
				$portfolio_fixture = $portfolio_method->invoke( BizCity_Personal_Profile_REST::instance(), array(
					'blocks' => array(
						array( 'type' => 'content', 'props' => array( 'title' => 'Portfolio', 'body' => '<script>alert(1)</script> Nội dung công khai.' ) ),
						array( 'type' => 'shortcode', 'props' => array( 'shortcode' => '[secret]' ) ),
						array( 'type' => 'profile-card', 'props' => array() ),
					),
				) );
				$portfolio = $portfolio_fixture['blocks'][2]['props']['publicPortfolioSnapshot'] ?? array();
				$portfolio_section = $portfolio['sections'][0] ?? array();
				$portfolio_runtime_ok = (int) ( $portfolio['version'] ?? 0 ) === 1
					&& 'profile_public_site_config' === (string) ( $portfolio['source'] ?? '' )
					&& count( (array) ( $portfolio['sections'] ?? array() ) ) === 1
					&& false === strpos( (string) ( $portfolio_section['body'] ?? '' ), '<script' )
					&& 'content' === (string) ( $portfolio_section['type'] ?? '' )
					&& hash( 'sha256', wp_json_encode( $portfolio['sections'] ) ) === (string) ( $portfolio['content_hash'] ?? '' );
				$portfolio_detail = $portfolio_runtime_ok ? 'Portfolio snapshot keeps bounded public content and excludes shortcode/private block payloads.' : 'Portfolio snapshot did not match the public block allowlist contract.';
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · public portfolio snapshot privacy fixture',
			'status' => $portfolio_runtime_ok ? 'pass' : 'fail',
			'detail' => $portfolio_detail,
		);
		if ( ! $portfolio_runtime_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-TBP-3 — prove server-authorized KG graph redaction keeps only public node/edge fields and valid references.
		$graph_redaction_ok = false;
		$graph_redaction_detail = 'Graph redaction helper is not loaded.';
		if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
			$graph_filter = static function ( $fallback, $card_id ) {
				return array(
					'nodes' => array(
						array( 'id' => 'service_a', 'label' => 'Service A', 'category' => 'expertise', 'weight' => 9, 'passage' => 'must-drop' ),
					),
					'edges' => array(
						array( 'source' => 'service_a', 'target' => 'missing', 'relation_public' => 'related' ),
					),
				);
			};
			add_filter( 'bizcity_profile_public_graph_snapshot', $graph_filter, 99, 2 );
			try {
				$graph_reflection = new ReflectionClass( 'BizCity_Personal_Profile_REST' );
				$graph_method = $graph_reflection->getMethod( 'with_public_graph_snapshot' );
				$graph_method->setAccessible( true );
				$graph_fixture = $graph_method->invoke( BizCity_Personal_Profile_REST::instance(), array( 'blocks' => array( array( 'type' => 'profile-card', 'props' => array( 'publicCapabilities' => array() ) ) ) ), 42 );
				$graph = $graph_fixture['blocks'][0]['props']['publicGraphSnapshot']['graph'] ?? array();
				$node = $graph['nodes'][0] ?? array();
				$graph_redaction_ok = count( (array) ( $graph['nodes'] ?? array() ) ) === 1
					&& 'service_a' === (string) ( $node['id'] ?? '' )
					&& ! array_key_exists( 'passage', $node )
					&& empty( $graph['edges'] )
					&& hash( 'sha256', wp_json_encode( $graph ) ) === (string) ( $graph_fixture['blocks'][0]['props']['publicGraphSnapshot']['graph_hash'] ?? '' );
				$graph_redaction_detail = $graph_redaction_ok ? 'Server-authorized graph fixture keeps allowlisted node fields and drops invalid/private edges.' : 'Graph redaction did not enforce the public node/edge contract.';
			} finally {
				remove_filter( 'bizcity_profile_public_graph_snapshot', $graph_filter, 99 );
			}
		}
		$steps[] = array(
			'label'  => 'Runtime · public KG graph redaction fixture',
			'status' => $graph_redaction_ok ? 'pass' : 'fail',
			'detail' => $graph_redaction_detail,
		);
		if ( ! $graph_redaction_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-TBP-6.1 — verify the deployed Profile bundle carries both role routes and the care surface excludes the public profile navigation item.
		$surface_navigation_ok = false;
		if ( is_readable( $profile_ui_dist_file ) ) {
			$surface_bundle = (string) file_get_contents( $profile_ui_dist_file );
			$surface_navigation_ok = false !== strpos( $surface_bundle, 'profile-public' )
				&& false !== strpos( $surface_bundle, 'profile-care' )
				&& false !== strpos( $surface_bundle, 'Profile Public' );
		}
		$steps[] = array(
			'label'  => 'Runtime · Profile Care/Public navigation artifact',
			'status' => $surface_navigation_ok ? 'pass' : 'fail',
			'detail' => $surface_navigation_ok ? 'Deployed Profile bundle contains both role surfaces and the public workspace route.' : 'Deployed Profile bundle is missing the Profile Care/Public surface markers.',
		);
		if ( ! $surface_navigation_ok ) { $pass = false; }

		// [2026-08-24 Johnny Chu] PHASE-TBP-6.2 — verify the canonical WebChat CRM bridge is available without inserting a fixture or firing an inbound event.
		$crm_bridge_ok = class_exists( 'BizCity_CRM_Adapter_WebChat' )
			&& class_exists( 'BizCity_CRM_Facebook_Ingestor' )
			&& class_exists( 'BizCity_CRM_Channel_Registry' )
			&& method_exists( 'BizCity_CRM_Adapter_WebChat', 'normalize_inbound' )
			&& method_exists( 'BizCity_CRM_Facebook_Ingestor', 'ingest' );
		$steps[] = array(
			'label'  => 'Loader · Profile WebChat canonical CRM bridge',
			'status' => $crm_bridge_ok ? 'pass' : 'warn',
			'detail' => $crm_bridge_ok ? 'Profile can reuse the WebChat adapter and CRM ingestor; diagnostics does not invoke the side-effecting ingest path.' : 'CRM WebChat bridge is not loaded; Profile chat remains fail-open but CRM projection requires the CRM module.',
		);

		// [2026-08-24 Johnny Chu] PHASE-TBP-6.2 — prove card/source attribution survives Profile → WebChat normalization without CRM writes.
		$crm_attribution_ok = false;
		$crm_attribution_detail = 'Profile CRM attribution fixture is not loaded.';
		if ( $crm_bridge_ok && class_exists( 'BizCity_Personal_Profile_Chat_Handler' ) ) {
			$chat_reflection = new ReflectionClass( 'BizCity_Personal_Profile_Chat_Handler' );
			$payload_ok = false;
			$crm_payload = array();
			if ( $chat_reflection->hasMethod( 'build_crm_inbound_payload' ) ) {
				$payload_method = $chat_reflection->getMethod( 'build_crm_inbound_payload' );
				$payload_method->setAccessible( true );
				$crm_payload = $payload_method->invoke( null, 42, 'profile_webchat_42_probe', 'hello', 'profile_user_probe' );
				$payload_ok = (int) ( $crm_payload['profile_card_id'] ?? 0 ) === 42
					&& 'profile_public' === (string) ( $crm_payload['profile_source'] ?? '' );
			}
			$webchat_adapter = BizCity_CRM_Channel_Registry::get( 'webchat' );
			$normalized = $webchat_adapter ? $webchat_adapter->normalize_inbound( $crm_payload ) : null;
			$crm_attribution_ok = $payload_ok && is_array( $normalized )
				&& (int) ( $normalized['profile_card_id'] ?? 0 ) === 42
				&& 'profile_public' === (string) ( $normalized['profile_source'] ?? '' )
				&& 'profile_user_probe' === (string) ( $normalized['external_source_id'] ?? '' );
			$crm_attribution_detail = $crm_attribution_ok ? 'Profile card ID, source, session and stable external message ID survive the canonical WebChat normalizer.' : 'Profile card/source attribution was lost before the canonical CRM ingest boundary.';
		}
		$steps[] = array(
			'label'  => 'Runtime · Profile WebChat CRM card attribution fixture',
			'status' => $crm_attribution_ok ? 'pass' : 'warn',
			'detail' => $crm_attribution_detail,
		);

		if ( method_exists( $ctx, 'emit_step' ) ) {
			foreach ( $steps as $step ) { $ctx->emit_step( $step ); }
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Wave 6.2 quick edit and Page Builder artifact contracts are wired.' : 'One or more Wave 6.2 quick edit or Page Builder artifact contracts are incomplete.',
			'error'    => $pass ? '' : 'personal_profile_wave62_incomplete',
			'fix_hint' => $pass ? '' : 'Kiểm tra Profile REST routes, insertion helper, BZPB loader và build artifact pagebuilder-app.js.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void { /* Read-only. */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Personal_Profile_Wave62';
	return $list;
} );
