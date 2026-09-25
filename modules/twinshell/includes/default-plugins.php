<?php
/**
 * Twin Shell — Default plugin registrations.
 *
 * Maps the bundled BizCity plugin pages (already routed at their own
 * pretty-URLs by their respective modules) into the Twin Shell ActivityBar.
 * The shell does NOT create new pages — it only wraps the existing slugs in
 * an iframe and provides a unified left-side nav.
 *
 * URL mapping (must match the existing rewrite rules):
 *   twinchat   → /twinchat/             (modules/twinchat)
 *   creator    → /creator/              (plugins/bizcity-content-creator)
 *   doc        → /tool-doc/             (plugins/bizcity-doc)
 *   image      → /tool-image/           (plugins/bizcity-tool-image)
 *   profile    → /profile-studio/       (plugins/bizcity-tool-image)
 *   canva      → /canva/                (plugins/bizcity-tool-image)
 *   video      → /kling-video/          (plugins/bizcity-video-kling)
 *   web        → /tool-pagebuilder/     (plugins/bizcity-pagebuilder)
 *   mindmap    → /mindmap/              (plugins/bizcity-mindmap)
 *   note       → /note/                 (plugins/bizcity-notebook)
 *   tasks      → /tasks/                (core/intent)
 *   sessions   → /chat-sessions/        (core/intent)
 *   scheduler  → /scheduler/            (core/scheduler)
 *   workflow   → admin: bizcity-automation               (mode=link)
 *   tools      → /tools-map/            (intent tool map)
 *   skills     → /skills/               (skills page)
 *   gateway    → admin: bizchat-gateway                  (mode=link)
 *   marketplace → admin: index.php?page=bizcity-marketplace (mode=link)
 *
 * ── Activation gating (2026-06-02) ─────────────────────────────────────
 * Each entry MAY declare `requires`:
 *   [ 'const'    => 'CONST_NAME'  ]   — defined(CONST_NAME)
 *   [ 'class'    => 'Class_Name'  ]   — class_exists(...)
 *   [ 'function' => 'fn_name'     ]   — function_exists(...)
 *   [ 'plugin'   => 'slug/file.php' ] — is_plugin_active(...)
 * Entries WITHOUT `requires` are considered **core** and ALWAYS show:
 *   twinchat · gateway · crm · scheduler · workflow · skills · settings · account.
 * Non-core entries whose requirement fails are hidden from the ActivityBar.
 * Bookmarked URLs (`/twin/?plugin=xxx`) hitting a locked entry render the
 * “Plugin chưa được kích hoạt / gói Pro” notice (see class-twin-shell-page).
 *
 * Language convention (PHASE-0-RULE-LANGUAGE):
 *   - Source labels are written in English.
 *   - Vietnamese (and other locales) ship via .po/.mo under
 *     bizcity-twin-ai/languages/, text-domain `bizcity-twin-ai`.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

add_filter( 'bizcity_twin_register_plugins', static function ( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		$plugins = [];
	}

	$td = 'bizcity-twin-ai';

	$defaults = [
		// ── Operating center ─────────────────────────────────────────
		[
			'id'          => 'twinchat',
			'label'       => __( 'Twin Chat',                     $td ),
			// [2026-08-29 Johnny Chu] PHASE-TWINSHELL-NAV — identify Twin Chat with the brain icon in the shared ActivityBar.
			'icon'        => 'brain',
			'emoji'       => '💬',
			'mode'        => 'embed',
			'public_slug' => '/twinchat/',
			// [2026-09-19 Johnny Chu] PHASE-0.60 C2 — hide CRM from users who are not CRM staff; REST/route still rechecks scope.
			'capability'  => 'bizcity_crm_handle_inbox',
			'section'     => 'top',
			// [2026-06-04 Johnny Chu] BS-12 — thêm `session_id` vào allowlist để
			// twin-shell forward param này khi rebuild iframe URL + sync address
			// bar (admin.php?page=bizcity-twinchat&plugin=twinchat&session_id=…),
			// y như cách `notebook_id` đang hoạt động cho notebook deep-link.
			'params'      => [ 'session', 'session_id', 'notebook', 'notebook_id', 'thread' ],
			'desc'        => __( 'Central business brain for chat, knowledge and work.', $td ),
		],
		// [2026-08-26 Johnny Chu] PHASE-TWINSHELL-CORE-NAV — channels are the
		// second operating layer: bring every external conversation into the brain.
		[
			'id'          => 'gateway',
			'label'       => __( 'Channels',                      $td ),
			'icon'        => 'gateway',
			'emoji'       => '🔌',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizchat-gateway-spa' ),
			'capability'  => 'read',
			'section'     => 'top',
		],
		// [2026-08-26 Johnny Chu] PHASE-TWINSHELL-CORE-NAV — CRM is the third
		// operating layer for channel data, inboxes and customer context.
		// [2026-08-27 Johnny Chu] PHASE-TWINSHELL-CORE-NAV — CRM is a default
		// capability of BizCity Twin AI, available on the Free plan.
		[
			'id'          => 'crm',
			'label'       => __( 'Twin CRM',                       $td ),
			'icon'        => 'funnel',
			'emoji'       => '📥',
			'mode'        => 'embed',
			'public_slug' => '/crm/',
			// [2026-09-20 Johnny Chu] PHASE-0.60 C60-M06 — the ActivityBar entry asks the CRM
			// action resolver for the WP capability string instead of hardcoding 'read'; keeps
			// this file a consumer of CRM's boundary, not a second source of truth for it.
			'capability'  => class_exists( 'BizCity_CRM_Authority' ) ? BizCity_CRM_Authority::menu_cap( 'crm.inbox.open' ) : 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'inbox', 'thread', 'contact_id' ],
			'desc'        => __( 'Unified multi-channel inbox (Facebook / Zalo / WebChat) with Twin Brain trace.', $td ),
			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — CRM is the
			// first plugin migrated to the canonical `r` route contract (it is the plugin the
			// original F5/deep-link bug was reported against). `/crm/` itself stays the entry —
			// it is still the wrapper that avoids cross-host redirect loops on multisite +
			// domain-mapped installs; the P0 hotfix already made it mirror its child's hash onto
			// its own URL, so the shell can now treat that URL's hash as this plugin's route.
			'route_mode'  => 'hash',
			// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P2 — a link saved
			// before this migration (e.g. `…&plugin=crm&thread=88&id=13`) still opens the right
			// conversation/contact instead of CRM's default screen. `thread` (a conversation) is
			// checked before `contact_id` so a link that somehow carries both still opens the
			// conversation, matching what a human reading such a link would expect. `id` was
			// Channel Gateway's own historical field for the inbox id; `inbox` is the other name
			// the same value has carried elsewhere in this codebase — both are tried before the
			// `0` (unassigned rail) fallback CRM's own router already treats as valid.
			'legacy_params' => [
				'thread'     => '/inbox/{id|inbox|0}/conv/{thread}',
				'contact_id' => '/contacts/{contact_id}/360',
			],
		],
		// [2026-08-26 Johnny Chu] PHASE-TWINSHELL-CORE-NAV — PageBuilder is
		// fourth: turn the brain and CRM context into landing pages and campaigns.
		[
			'id'          => 'web',
			'label'       => __( 'Page Builder',                  $td ),
			'icon'        => 'web',
			'emoji'       => '🌐',
			'mode'        => 'embed',
			'public_slug' => '/tool-pagebuilder/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'page' ],
			'requires'    => [ 'class' => 'BZPB_Rest_API' ],
		],
		// ── Extensions (Pro) — bottom group, after the QR boundary ─────
		// [2026-09-16 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-NAV-GROUP — Pro entries are not in `$_bizcity_bundled_must_load`, so they leave the primary top group and sit below QR Studio with the other non-must-load entries.
		[
			'id'          => 'astro',
			'label'       => __( 'Astrology',                     $td ),
			'icon'        => 'astro',
			'emoji'       => '🌙',
			'mode'        => 'embed',
			'public_slug' => '/astro/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id', 'tab', 'hash' ],
			'desc'        => __( 'Western & Vedic natal charts, transit calendar.', $td ),
			'requires'    => [ 'class' => 'BizCoach_Pro_Self_Service_Page' ],
			'plan'        => 'pro',
			'pro_package' => 'BizCoach Pro',
		],
		[
			'id'          => 'doc',
			'label'       => __( 'Documents & Slides',            $td ),
			'icon'        => 'doc',
			'emoji'       => '📄',
			'mode'        => 'embed',
			'public_slug' => '/tool-doc/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'doc', 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZDOC_VERSION' ],
			'plan'        => 'pro',
			'pro_package' => 'BizCity Doc',
		],
		[
			'id'          => 'creator',
			'label'       => __( 'Brain Factory',                $td ),
			'icon'        => 'creator',
			'emoji'       => '🧠',
			'mode'        => 'embed',
			'public_slug' => '/creator/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'id', 'tab', 'session_id' ],
			'requires'    => [ 'const' => 'BZCC_VERSION' ],
		],
		[
			'id'          => 'image',
			'label'       => __( 'Product Images',                $td ),
			'icon'        => 'image',
			'emoji'       => '🎨',
			'mode'        => 'embed',
			'public_slug' => '/tool-image/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZTIMG_VERSION' ],
			'plan'        => 'pro',
			'pro_package' => 'BizCity Tool Image',
		],
		[
			'id'          => 'video',
			'label'       => __( 'Video Studio',                  $td ),
			'icon'        => 'video',
			'emoji'       => '🎬',
			'mode'        => 'embed',
			'public_slug' => '/kling-video/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id', 'tab', 'mode' ],
			'requires'    => [ 'const' => 'BIZCITY_VIDEO_KLING_VERSION' ],
			'plan'        => 'pro',
			'pro_package' => 'BizCity Video Kling',
		],
		[
			'id'          => 'profile',
			'label'       => __( 'Portrait Studio',               $td ),
			'icon'        => 'profile',
			'emoji'       => '🧑‍🎨',
			'mode'        => 'embed',
			'public_slug' => '/profile-studio/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id', 'tab' ],
			'requires'    => [ 'const' => 'BZTIMG_VERSION' ],
			'plan'        => 'pro',
			'pro_package' => 'BizCity Tool Image',
		],
		[
			'id'          => 'personal',
			'label'       => __( 'Personal Assistant',            $td ),
			// [2026-08-26 Johnny Chu] PHASE-TWINSHELL-CORE-NAV — use a profile
			// silhouette for the personal assistant entry instead of the home icon.
			'icon'        => 'profile',
			'emoji'       => '🏠',
			'mode'        => 'embed',
			// [2026-08-29 Johnny Chu] PHASE-TWINSHELL-NAV — Personal Assistant is served by the canonical /profile/ route.
			'public_slug' => '/profile/',
			'capability'  => 'read',
			'section'     => 'top',
			'params'      => [ 'ref' ],
			'desc'        => __( 'Personal calendar, tasks, budget & journal.', $td ),
			'requires'    => [ 'const' => 'BIZCITY_PERSONAL_VERSION' ],
		],
		[
			'id'          => 'profile-public',
			'label'       => __( 'Public Profile QR',              $td ),
			'icon'        => 'qr',
			'emoji'       => '🔳',
			'mode'        => 'embed',
			'public_slug' => '/profile-public/',
			'capability'  => 'read',
			'section'     => 'top',
			'requires'    => [ 'const' => 'BIZCITY_PERSONAL_VERSION' ],
		],
		// [2026-09-16 04:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-TWINSHELL-NAV-GROUP — QR Studio is the LAST entry of the top group and therefore the visual boundary: everything that is not in `$_bizcity_bundled_must_load` (Pro/non-must-load) sits below it.
		[
			'id'          => 'qr',
			'label'       => __( 'QR Studio',                    $td ),
			'icon'        => 'qr',
			'emoji'       => '🔳',
			'mode'        => 'embed',
			'public_slug' => '/qr-studio/',
			'capability'  => 'read',
			'section'     => 'top',
			'requires'    => [ 'const' => 'BZTIMG_VERSION' ],
		],
		// ── Operations (bottom — utilities, system links, non-must-load) ──
		// [2026-08-26 Johnny Chu] PHASE-TWINSHELL-MARKET — keep Marketplace
		// inside the Twin workspace, immediately before Reminders.
		[
			'id'          => 'marketplace',
			'label'       => __( 'Marketplace',                    $td ),
			'icon'        => 'marketplace',
			'emoji'       => '🏪',
			'mode'        => 'link',
			'target_url'  => admin_url( 'index.php?page=bizcity-marketplace' ),
			'capability'  => 'read',
			'section'     => 'bottom',
			'desc'        => __( 'Browse and manage BizCity extensions.', $td ),
		],
		// [2026-06-17 Johnny Chu] UX — removed Account & Billing button from ActivityBar
		[
			'id'          => 'scheduler',
			'label'       => __( 'Reminders',                     $td ),
			'icon'        => 'scheduler',
			'emoji'       => '📅',
			'mode'        => 'embed',
			'public_slug' => '/scheduler/',
			'capability'  => 'read',
			// [2026-09-24 Johnny Chu] PHASE-TWINSHELL-NAV — Reminders sits in the top group right after Twin Chat (see ACTIVITY_PRIORITY in twin-shell.js).
			'section'     => 'top',
			'params'      => [ 'id' ],
		],
		[
			'id'          => 'workflow',
			'label'       => __( 'Automation',                    $td ),
			'icon'        => 'automation',
			'emoji'       => '🔄',
			'mode'        => 'link',
			'target_url'  => admin_url( 'admin.php?page=bizcity-automation' ),
			'capability'  => 'read',
			// [2026-09-24 Johnny Chu] PHASE-TWINSHELL-NAV — Automation sits in the top group right after Twin Chat (see ACTIVITY_PRIORITY in twin-shell.js).
			'section'     => 'top',
		],
		// 2026-05-13 — `tools` removed from ActivityBar (still reachable at /tools-map/).
		[
			'id'          => 'skills',
			'label'       => __( 'Skills',                        $td ),
			'icon'        => 'skills',
			'emoji'       => '⚡',
			'mode'        => 'embed',
			'public_slug' => '/skills/',
			'capability'  => 'read',
			'section'     => 'bottom',
			'params'      => [ 'id' ],
		],
		[
			'id'          => 'settings',
			'label'       => __( 'Settings',                      $td ),
			'icon'        => 'settings',
			'emoji'       => '⚙️',
			// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0-SETTING-PANEL-G5 — Settings is the Control Panel,
			// embedded in TwinShell so the ActivityBar stays visible. The panel's own hash route (the open
			// destination/item) is carried in `_iurl` by the shell's iframe deep-link sync, so F5 reopens it.
			'mode'        => 'embed',
			'public_slug' => '/twin/panel/',
			// [2026-09-21 04:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.63B C-08 — use the
			// canonical Settings owner gate. This keeps the button visible for the
			// same administrator persona that can open the Setting Panel, while the
			// registry still filters it before it reaches the browser.
			'capability'  => class_exists( 'BizCity_CRM_Authority' )
				? BizCity_CRM_Authority::menu_cap( 'crm.settings.manage' )
				: 'manage_options',
			'section'     => 'bottom',
			'desc'        => __( 'Control Panel: API gateway, account, appearance, channels and modules.', $td ),
		],
		// 2026-05-13 — `channels` and `explore` removed from bottom ActivityBar
		// (channels merged into `gateway`; marketplace reachable via direct URL).
		// Learning Hub entry intentionally hidden from ActivityBar (still
		// reachable via direct /learning-hub/ URL when needed).
	];

	return array_merge( $defaults, $plugins );
}, 5 );
